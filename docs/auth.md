# Autentizace — lokální login, OIDC relying party, break-glass

## Přehled

Autentizace má tři cesty vzniku `shpd_st_` session tokenu:

1. **Lokální login** — `POST /_auth/login` (login + bcrypt heslo).
2. **OIDC** — standardní relying party (authorization code + PKCE) vůči
   libovolnému OIDC providerovi (Microsoft Entra, Zitadel, Keycloak, později
   centrální Shipard ID).
3. **Break-glass CLI** — `shpd-ds auth-emergency-login` založí session přímo
   v DB, obchází HTTP vrstvu i auth politiku.

Session mechanika (`shpd_st_`, 24 h TTL, refresh s rotací, logout,
`AuthMiddleware`) je pro všechny cesty stejná — OIDC je jen další způsob, jak
session vznikne. Mintování a invalidaci session drží `SessionService`
(`src/Api/SessionService.php`), který nově plní i `ip_address`.

## Auth politika per DS

`config/main.json`, volitelný klíč `auth`. Bez něj platí dnešní chování
(jen lokální login):

```jsonc
"auth": {
    "local": true,                       // default true
    "providers": [                       // default []
        {
            "id": "entra",               // stabilní id, [a-z0-9-]+
            "label": "Přihlásit přes Firma a.s.",
            "issuer": "https://login.microsoftonline.com/{tenant}/v2.0",
            "clientId": "...",
            "clientSecret": "...",
            "scopes": ["openid", "profile", "email"],   // default tyto tři
            "autoLinkEmail": true,       // default false
            "jitProvision": false        // default false
        }
    ]
}
```

- Parsování + validace: `AuthPolicy::fromArray()` / `OidcProviderConfig`
  (`src/Core/Auth/`). Fail-fast: unikátní `id`, `issuer` https URL, neprázdný
  `clientId`/`clientSecret`, `local: false` bez providerů = chyba.
- Validuje se **lazy** při prvním použití (`DataSourceConfig::getAuthPolicy()`),
  ne při načtení configu — rozbitá auth sekce nezablokuje CLI (break-glass).
- `local: false` → `POST /_auth/login` vrací **403 `AUTH_METHOD_DISABLED`**
  i při správném heslu; login obrazovka formulář skryje.
- `GET /_app/info` vrací `auth: {local, providers: [{id, label}]}` — nikdy
  clientId/secret/issuer.

## OIDC flow

```
Prohlížeč                      nov_shipard                       IdP
    │  GET /_auth/oidc/start?provider=x                           │
    │──────────────────────────────►│  INSERT transakce           │
    │                               │  (state, nonce, PKCE,       │
    │            302                │   TTL 10 min)               │
    │◄──────────────────────────────│                             │
    │  authorize URL (state, nonce, code_challenge S256)          │
    │─────────────────────────────────────────────────────────────►
    │                 … přihlášení u IdP …                         │
    │  GET /_auth/oidc/callback?code=…&state=…                    │
    │──────────────────────────────►│  lookup state (single-use)  │
    │                               │  POST token endpoint ───────►
    │                               │  (code + PKCE verifier +    │
    │                               │   client_secret_post)       │
    │                               │◄──────────── id_token ──────│
    │                               │  validace JWT (JWKS)        │
    │                               │  IdentityMapper.resolve()   │
    │            302                │  createSession()            │
    │◄──────────────────────────────│  UPDATE transakce (handoff, │
    │  /app/?login=oidc&code={handoff}       TTL 60 s)            │
    │  POST /_auth/oidc/exchange {code}                           │
    │──────────────────────────────►│  DELETE transakce           │
    │◄──────────────────────────────│                             │
    │  {token, expires_at, user} — envelope shodný s loginem      │
```

Klíčové vlastnosti:

- **Token nikdy v URL** (D11) — do SPA jde jen jednorázový handoff kód
  s TTL 60 s; `exchange` ho smaže při prvním použití (i expirovaný).
- **State je single-use** — po úspěšném callbacku (naplněný `handoff_code`)
  se replay odmítne jako `oidc_invalid_state`.
- **PKCE S256** vždy; token endpoint `client_secret_post`.
- Transakce flow žijí v `core_system_auth_transactions` (tableId 422);
  expirované řádky uklízí oportunistický DELETE při každém `start`.
- Chybové větve callbacku = 302 na `/app/?login_error={kod}`; kódy viz níže.
- Redirect URI se odvozuje z requestu: dev mód
  `http://{host}/{ds-id}/api/v1/_auth/oidc/callback`, produkce
  `https://{host}/api/v1/_auth/oidc/callback`.

### Návrat po loginu (`return_to`)

`GET /_auth/oidc/start` přijímá volitelný parametr `return` — query-suffix
(tvar `?klic=hodnota&…`), který callback po úspěšném loginu připojí
k handoff redirectu: `/app/?login=oidc&code={handoff}&klic=hodnota`.

- Hodnota se ukládá do transakce (sloupec `return_to`, varchar 200,
  nullable) a validuje **na startu i v callbacku** (defense in depth):
  začíná `?`, délka ≤ 200, jen páry
  `[A-Za-z0-9_-]+=[A-Za-z0-9_-]+` oddělené `&` — žádné cesty, žádná plná
  URL, žádné procentové kódování. Open redirect nemá kudy; nevalidní
  hodnota se ignoruje (start projde, log warn).
- Použití: **OP flow kontinuita** — LoginScreen hostingu při pending
  `opAuth.txn` staví start URL s `return=?op_auth={txn}`, takže rozjednaná
  OP transakce přežije plnou navigaci na externího IdP (in-memory store by
  ji ztratil). `main.js` na kombinovaném příchodu
  `?login=oidc&code=…&op_auth=…` po úspěšném exchange naplní `opAuth`
  store → App zobrazí OpAuthScreen → approve → návrat do klientského DS.
- Budoucí použití ať validační pravidlo rozšiřuje vědomě
  (`OidcController::isValidReturnTo`).

### GitHub jako provider (`kind: "github"`)

Provider může mít `kind` (default `"oidc"`, chování beze změny). GitHub
nemluví OIDC — bez discovery, id_tokenu a JWKS — identitu skládá
`GithubOauthClient` (`src/Core/Auth/`) z REST API: token exchange →
`GET /user` → `GET /user/emails`.

- **Identita:** `issuer = "https://github.com"` (syntetická konstanta,
  vynucená i při odlišné hodnotě v configu — identity klíč nesmí být věc
  konfigurace), `subject` = numerické `user.id` (login se dá přejmenovat),
  e-mail jen `primary && verified` (jinak `email_verified: false` →
  autoLink nenastane, flow skončí `oidc_no_account`).
- **Bez PKCE a nonce** — GitHub OAuth apps je nepodporují; CSRF drží
  single-use `state` transakce. Default scopes `["read:user", "user:email"]`.
- Všechna API volání s `User-Agent: shipard` (GitHub bez něj vrací 403).
- `IdentityMapper` i navazující flow (session, handoff, return_to) beze
  změny — `fetchIdentity` vrací standardní `OidcIdentity`.

```json
{
    "id": "github",
    "label": "GitHub",
    "kind": "github",
    "clientId": "<z GitHub OAuth App>",
    "clientSecret": "<z GitHub OAuth App>",
    "autoLinkEmail": true,
    "jitProvision": false
}
```

### Validace id_tokenu (`OidcClient::validateIdToken`)

- Podpis proti JWKS providera přes `firebase/php-jwt`.
- Allowlist algoritmů **RS256/ES256** — `none` a HS* se odmítají ještě před
  dekódováním.
- `iss` === issuer z discovery, `aud` obsahuje `clientId`, `exp` povinný,
  `exp`/`iat` s tolerancí ±60 s, `nonce` musí sedět s transakcí.
- Discovery + JWKS cache: `{ds}/cache/oidc/{sha256(issuer)}.{discovery,jwks}.json`,
  discovery TTL 24 h; neznámý `kid` → vynucený refresh JWKS, max 1×/5 min
  (anti-DoS). Jen https, timeout 10 s (`OidcDiscovery`).

### Mapování identit (`IdentityMapper`, D10)

Tabulka `core_system_user_identities` (tableId 421), unikátní klíč
`(issuer, subject)` — id providera v konfiguraci se může přejmenovat, issuer
je stabilní. E-mail u IdP se může měnit, `(issuer, sub)` lookup funguje dál.

1. `(issuer, subject)` nalezeno → kontrola `is_active` (neaktivní →
   `oidc_account_inactive`) → update `last_login` → hotovo.
2. Nenalezeno + `autoLinkEmail: true` + `email_verified` + neprázdný e-mail →
   `SELECT … WHERE email = ? AND is_active = 1`:
   - právě 1 → propojit (INSERT identity);
   - 0 → `jitProvision: true` ? založit uživatele (`login` = e-mail,
     `full_name` = name claim ?: e-mail, `password_hash` **NULL**; kolize
     loginu → `oidc_login_conflict`) : `oidc_no_account`;
   - \>1 → `oidc_email_ambiguous` (e-mail není unique — řeší admin).
3. Jinak → `oidc_no_account`.

JIT uživatelé mají `password_hash NULL` — lokální login vrací 401 se stejnou
hláškou jako špatné heslo (žádný leak existence účtu).

### Chybové kódy

Redirect `/app/?login_error={kod}`, frontend překládá přes
`login.error.{kod}`:

| Kód | Význam |
|-----|--------|
| `oidc_denied` | Uživatel odmítl na straně IdP (`?error=` v callbacku) |
| `oidc_invalid_state` | Neznámý / expirovaný / už použitý state |
| `oidc_provider_error` | Discovery, code exchange nebo validace id_tokenu selhaly |
| `oidc_no_account` | Identita nemá účet a auto-link/JIT nejsou povolené |
| `oidc_email_ambiguous` | E-mail odpovídá více účtům |
| `oidc_account_inactive` | Propojený uživatel je deaktivovaný |
| `oidc_login_conflict` | JIT: login (= e-mail) už existuje |

### Shipard ID jako provider (hosting OP)

Hosting DS umí vystupovat jako OIDC Provider (`docs/hosting.md` §5.4,
D2/D10/D12) — centrální „Shipard ID" pro ostatní DS. RP strana se nemění:
hosting OP je běžná položka `auth.providers` klientského DS:

```json
{
    "id": "shipard-id",
    "label": "Shipard ID",
    "issuer": "https://portal.example.com/api/v1/_hosting/oidc",
    "clientId": "<ds_id klientského DS>",
    "clientSecret": "<z shpd-ds hosting-oidc-client --generate>",
    "autoLinkEmail": true
}
```

Dev tvar issueru: `http://127.0.0.1/{hosting-ds-id}/api/v1/_hosting/oidc`
(http je povolené jen pro localhost/127.0.0.1). Redirect URI klienta
registruje na hostingu `shpd-ds hosting-oidc-client` a musí přesně
odpovídat odvození RP: dev
`http://{host}/{ds-id}/api/v1/_auth/oidc/callback`, prod
`https://{host}/api/v1/_auth/oidc/callback`. OP vydává id_token s claimy
`sub`/`email`/`email_verified: true`/`name`/`nonce`, takže `autoLinkEmail`
i `jitProvision` fungují dle politiky DS.

## Frontend

- `main.js` při bootu (před mountem) detekuje `?login=oidc&code=` →
  `exchangeOidc()` → `authStore.setAuth()` → `history.replaceState` (kód
  nesmí přežít reload). `?login_error=` → `loginNotice` store → LoginScreen.
  Kombinovaný příchod `…&op_auth=…` (return_to kontinuita) po úspěšném
  exchange navíc naplní `opAuth` store; při neúspěchu se opAuth nenastavuje
  (txn na hostingu doexpiruje sama).
- `LoginScreen.svelte`: z `appInfoStore.auth` — `local: false` skryje
  formulář; tlačítka providerů (label z konfigurace) navigují na
  `oidcStartUrl(id, returnTo)` (plná navigace, ne fetch); `returnTo`
  = `?op_auth={txn}` při pending OP transakci. Oddělovač
  (`login.providerHint`) jen když je vidět obojí.
- Pure helpery `parseOidcRedirect` / `buildOidcStartUrl` v `api/oidc.js`
  (bez závislosti na window — testovatelné v node:test).

## Admin model — is_admin a ochrana systémových tabulek (Fáze 0a)

Minimální model oprávnění (D16): boolean `is_admin` na `core_system_users`
+ plošný guard — CRUD/viewer/form/lookup akce nad tabulkami `core_system_*`
vyžadují admina, jinak `403 FORBIDDEN_SYSTEM_TABLE`. Plné RBAC mimo scope,
model je s ním dopředně kompatibilní.

- `AuthMiddleware` joinuje session na users: propíše `is_admin` do
  `AuthContext->isAdmin` a session **neaktivního** účtu odmítne (401) —
  deaktivace platí okamžitě, ne až od dalšího loginu.
- **API klíče mají `isAdmin: false` vždy** — integrace do systémových
  tabulek nemají co dělat, provisioning jde přes CLI.
- Odpověď `login` i OIDC `exchange` nese `user.is_admin`; frontend přes
  `authStore.isAdmin` jen nezobrazuje mrtvé odkazy, zdroj pravdy je server
  (`TableAccessGuard` + server-side filtr settings navigace — položky nad
  `core_system_*` se ne-adminovi do stromu vůbec nepošlou).
- CLI: `user-create --admin`, `user-set-admin --login xy [--revoke]`
  s pojistkou proti odebrání posledního aktivního admina (viz `docs/cli.md`).
- Citlivé sloupce (`password_hash`, `key_hash`) mají `"sensitive": true` —
  nikdy neopustí server ani pro admina (viz `docs/table-definitions.md`).
- **Rollout na existující DS**: po `ds-upgrade` spustit `user-set-admin`
  pro adminské účty, jinak systémové tabulky v UI zmizí všem.

## Samoobsluha účtů — pozvánky, reset a změna hesla, relace (Fáze 0b)

Kompletní hygiena lokálních účtů nad odchozí poštou (`docs/mail/outbound.md`)
a admin modelem (0a). Rozhodnutí D19–D21.

### Jednorázové tokeny (D19)

Tabulka `core_system_auth_tokens` (tableId 426, `keepOnReset`): `purpose`
(`invite` | `password_reset`), `user_id`, `token_hash`, `created`, `expires`,
`used_at`. V DB je **jen SHA-256 hash** plaintextu (vzor API klíče) —
plaintext `shpd_pt_` + 32 B base64url jde jednorázově do mailu.

`Shipard\Core\Auth\AuthTokenService`:

- `issue(userId, purpose, ttl)` — smaže nepoužité tokeny stejného
  purpose+user (poslední odeslaný mail platí) a vloží nový. TTL: reset
  **1 h** (`RESET_TTL_SECONDS`), pozvánka **7 dní** (`INVITE_TTL_SECONDS`).
- `validate(token, purposes)` — neburning kontrola (hash + purpose +
  nepoužitý + neexpirovaný) → user_id. Umožňuje zkontrolovat politiku
  hesla, aniž by chyba spálila token.
- `consume(token, purposes)` — atomický `UPDATE … SET used_at` rozhodnutý
  přes affected rows: single-use i při souběhu. Miss větev uklízí tokeny
  expirované >30 dní — žádný cron není potřeba.

Pozvánka je technicky reset s delším TTL a jinou šablonou — obě purposes
konzumuje stejná landing page (`/_auth/password/reset` bere obě).

### Forgot / reset flow (D20 — anti-enumerace)

```
POST /_auth/password/forgot {identifier}     public, vždy 200
POST /_auth/password/reset  {token, password} public
```

- Forgot: přesná shoda loginu, jinak všechny účty s daným e-mailem (v mailu
  je uveden login — e-mail sdílený více účty dostane mail per účet).
  Neaktivní, `is_system` a účty bez e-mailu se **tiše přeskočí**; odpověď je
  vždy `{"status":"ok"}` a mail jde přes outbox
  (`MailOutboxService::enqueueAndSend()`), takže se existence účtu nedá
  odvodit ani časově. Selhání enqueue se jen zaloguje.
- Reset: pořadí validace → politika → consume. Chyba politiky (`400
  PASSWORD_POLICY`) token nespálí — uživatel heslo opraví a odešle znovu.
  Neplatný/expirovaný/použitý token → jednotně `400 INVALID_TOKEN`.
  Úspěch nastaví bcrypt hash a **zneplatní všechny sessions** uživatele
  (`SessionService::invalidateAllForUser`).
- Oba endpointy jsou exempt v `AuthMiddleware` a sdílí login rate-limit
  bucket (10/min/IP) v `RateLimitMiddleware`.

Mailové šablony: `modules/core/system/mail/{cs,en}/{reset,invite}.{txt,html}`,
renderer `Shipard\Core\Mail\MailTemplate` (soubor + `strtr`, subject =
první řádek `Subject:` v `.txt`, fallback jazyka na `en`). Placeholders
`{full_name} {login} {ds_name} {link} {ttl}`; jazyk dle
`DataSourceConfig::getDefaultLanguage()`, `ds_name` = setting `app.name`
?? install name. Link: `{scheme}://{host}{devPrefix}/app/?auth_action=
set-password&token=…` (dev prefix jako u OIDC redirectů).

### Pozvánka (invite)

```
POST /_users/{id}/invite    admin only (session)
```

Cíl musí být aktivní, ne-systémový a mít e-mail (`400 NO_EMAIL`). Vydá
token purpose `invite` (7 dní) + šablonu pozvánky. Opakované volání
přepošle — starý token zaniká v `issue()`. Chybějící `mail.defaultFrom`
→ `500 MAIL_NOT_CONFIGURED` (na rozdíl od forgotu se chyba adminovi
přizná). V UI: detail uživatele v Nastavení (UsersViewer, akce „Poslat
pozvánku“). Doporučený onboarding: `user-create` **bez** `--password`
(NULL hash = bez lokálního loginu) → pozvánka.

### Uživatel ↔ Osoba a importovaní uživatelé (#93 D6–D8)

Uživatel (`core_system_users`) a Osoba (`base_persons_persons`) jsou
oddělené entity. Vazbu nese nepovinný sloupec **`person`** u uživatele —
přidává ho rozšíření z `base.persons` (`core.system` na Osobách záviset
nemůže), takže zdroj dat bez Osob (portál hostingu) ho nemá a kód jeho
přítomnost ověřuje přes `TableDefinition`. Ve formuláři uživatele je to
lookup **Osoba** (JSONC `core.system/forms/core_system_users.jsonc`,
element `optional`), detail uživatele ukazuje jméno Osoby. Na přihlašování
vazba vliv nemá.

Migrace ze starého systému zakládá uživatele přes
`POST /_exchange/users/user/apply` (`docs/exchange-format.md` → Uživatelé)
— pro Osoby, které jsou autorem dokladu nebo záznamu spisovny. Takový
uživatel je **neaktivní, bez hesla a bez práv admina** a přihlásit se
nemůže žádnou cestou:

- lokální login: `is_active = 0` a `password_hash NULL`;
- OIDC auto-link hledá jen aktivní účty, takže ho nepropojí; s `jitProvision`
  skončí přihlášení stejným e-mailem na `oidc_login_conflict` (login = e-mail
  už existuje) — do aktivace je to žádoucí stav, ne chyba;
- pozvánku neaktivnímu účtu poslat nejde.

**Aktivace je ruční akce admina** (D8): Nastavení → Ostatní → Systém →
Uživatelé, zaškrtnout **Aktivní**. Potom funguje pozvánka (`Poslat
pozvánku` v detailu) i auto-link OIDC podle e-mailu. Neaktivní uživatel
dál smí být autorem dokladu (`docs_core_heads.author`) — v nabídce pole
Vystavil se ukáže jen jako aktuální hodnota s poznámkou „neaktivní“.

### Politika hesel (D21)

`Shipard\Core\Auth\PasswordPolicy`: min. **12 znaků** a heslo ≠ login
(case-insensitive). Nic dalšího — komplexitní pravidla jsou security
theater. Reset zneplatní všechny sessions; změna hesla všechny kromě
aktuální.

### Změna hesla a relace

```
POST   /_auth/password/change          session — {currentPassword, newPassword}
GET    /_auth/sessions                 session — vlastní relace
DELETE /_auth/sessions/{id}            session — jen vlastní; cizí id → 404
POST   /_auth/sessions/revoke-others   session — odhlásí ostatní zařízení
```

- Change: `password_hash NULL` (OIDC/JIT účet) → `400 NO_LOCAL_PASSWORD`;
  špatné současné heslo → 401; pak politika; úspěch →
  `invalidateOthers` (aktuální session žije).
- Sessions list vrací `{id, created, expires, ip_address, current}` —
  `current` podle tokenu z `AuthContext`, **token se nikdy nevrací**.
  Delete cizí session → `404 NOT_FOUND` bez leaku existence.
- Login envelope (login i OIDC exchange) nese `user.has_password` —
  frontend podle něj skrývá panel změny hesla.

### Frontend (0b)

- `main.js` boot: `?auth_action=set-password&token=` (parser
  `api/authActions.js`, pure) → in-memory `authAction` store +
  `history.replaceState` → `SetPasswordScreen` (App.svelte větev pro
  nepřihlášené). Úspěch → flash `loginNotice` (typ `success`) na
  LoginScreen.
- LoginScreen: odkaz „Zapomenuté heslo?“ (jen při `auth.local`) → inline
  forgot form; odpověď je vždy „Pokud účet existuje…“.
- Nastavení účtu → **panel Zabezpečení** (`accountSecurity`): změna hesla
  (skrytá při `has_password === false`) + moje relace. Panel je nový druh
  nav položky `panel` — registrace `panels[]` + `accountItems[]` v
  `module.jsonc`, vykreslení mapou panelId → komponenta v
  `ContentArea.svelte` (viz `docs/app-settings.md`).

## Break-glass (D9/D15)

```bash
cd /opt/shipard/data-sources/{id}
vendor/bin/shpd-ds auth-emergency-login --login jan.novak
```

Najde aktivního uživatele, `SessionService::createSession()` přímo do DB,
vytiskne token + návod (localStorage / curl). Funguje bez ohledu na auth
politiku — `local: false`, nedostupný IdP i rozbitá `auth` sekce configu.
Každé použití zaloguje warning (`ErrorLogger`).

## Vývoj a test s Keycloakem v dockeru

```bash
docker run -d --name keycloak -p 8080:8080 \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin \
  quay.io/keycloak/keycloak:latest start-dev
```

1. Admin konzole `http://localhost:8080` → nový realm `shipard-dev`.
2. Clients → Create: `Client ID` = `shipard`, Client authentication **On**,
   Standard flow **On**. Valid redirect URI:
   `http://127.0.0.1/{ds-id}/api/v1/_auth/oidc/callback`.
3. Credentials tab → zkopírovat Client secret.
4. Users → založit uživatele s e-mailem (Email verified **On**), nastavit heslo.
5. Do `config/main.json` DS:

```jsonc
"auth": {
    "providers": [{
        "id": "keycloak-dev",
        "label": "Keycloak (dev)",
        "issuer": "http://localhost:8080/realms/shipard-dev",
        "clientId": "shipard",
        "clientSecret": "…",
        "autoLinkEmail": true,
        "jitProvision": true
    }]
}
```

**Pozn.:** issuer musí být https; jedinou výjimkou je `http://localhost` /
`http://127.0.0.1` (dev Keycloak bez TLS proxy) — viz
`OidcProviderConfig::isAllowedIssuerUrl()`. Stejné pravidlo vynucují i
skutečná HTTP volání v `OidcDiscovery`/`OidcClient`.

Smoke bez prohlížeče:

```bash
curl -s http://127.0.0.1/{ds-id}/api/v1/_app/info | jq .data.auth
curl -si "http://127.0.0.1/{ds-id}/api/v1/_auth/oidc/start?provider=keycloak-dev" | grep -i location
```

## Soubory

| Soubor | Role |
|--------|------|
| `src/Api/SessionService.php` | Mintování/invalidace `shpd_st_` sessions (login, OIDC, break-glass) + hromadné invalidace (reset/change) |
| `src/Core/Auth/AuthTokenService.php` | Jednorázové tokeny pozvánka/reset (hash v DB, single-use) |
| `src/Core/Auth/PasswordPolicy.php` | Politika hesel (≥12 znaků, ≠ login) |
| `src/Api/Controller/PasswordController.php` | Forgot/reset/change, invite, sessions endpointy |
| `src/Core/Mail/MailTemplate.php` | Minimální renderer mailových šablon (strtr) |
| `modules/core/system/src/UsersViewer.php` | Settings viewer uživatelů + akce invite |
| `src/Core/Auth/AuthPolicy.php`, `OidcProviderConfig.php` | Politika + validace konfigurace |
| `src/Core/Auth/OidcDiscovery.php` | Discovery + JWKS file cache, throttlovaný refresh |
| `src/Core/Auth/OidcClient.php` | Authorize URL, code exchange, validace id_tokenu |
| `src/Core/Auth/IdentityMapper.php` | `(issuer, sub)` → user_id, auto-link, JIT |
| `src/Core/Auth/OidcException.php`, `OidcIdentity.php` | Doménová chyba s kódem, DTO identity |
| `src/Api/Controller/OidcController.php` | Endpointy start/callback/exchange |
| `src/Command/DataSource/AuthEmergencyLoginCommand.php` | Break-glass CLI |
| `modules/core/system/tables/core_system_user_identities.jsonc` | Mapovací tabulka (421) |
| `modules/core/system/tables/core_system_auth_transactions.jsonc` | OIDC transakce (422) |
| `frontend/src/api/oidc.js`, `api/auth.js` | Pure helpery, `exchangeOidc`, `oidcStartUrl` |
| `frontend/src/stores/loginNotice.svelte.js` | Chybový kód pro login obrazovku |
