# Shipard — Hosting (modul `hosting.core`)

**Designový dokument.** Centrální správa zdrojů dat: portál pro uživatele
s více DS, centrální identita (vlastní minimální OIDC Provider), automatické
zakládání zdrojů dat, automatické napojení na mail-router a AI gateway.
Vychází z myšlenek modulu `hosting` ze starého Shipardu, ale nekopíruje ho —
přebírá pull-based provisioning a princip „hosting je sám Shipard DS",
vynechává fakturaci, helpdesk a HW evidenci.

> **Stav:** **Design D1–D12 kompletně implementován (Fáze 0–5 hotové).**
> **Fáze 0 hotová** (2026-08-05):
> `adminOnly` mechanismus (task 0a), modul `hosting.core` + `install.hosting`,
> tabulky servers / data_sources / ds_users, admin viewery, portálový endpoint
> `/_hosting/portal/my-datasources` + `PortalScreen` (task 0b).
> **Fáze 1 hotová** (2026-08-05): OIDC OP — `OpKeyStore` + CLI
> `hosting-oidc-init`/`hosting-oidc-client`, endpointy discovery/jwks/
> authorize/approve/token, `hosting_core_oidc_codes`, issuer setting
> `hosting.oidc.issuer`, SPA `op_auth` flow (task `hosting-02-oidc-op`).
> **Fáze 2 hotová** (2026-08-05): provisioning agent — endpointy
> `/_hosting/server/reconcile|queue|confirm` + klíče serverů `shpd_hk_`
> (CLI `hosting-server-key`), požadavek na nový DS z admin formuláře
> (beforeSave generuje ds_id/secret/URL, setting `hosting.baseDomain`),
> `shpd-server hosting-sync` + cron slot `two-minutes`, `ds-create
> --ds-id`, `user-create --if-not-exists` + předpropojení identity
> (task `hosting-03-provisioning-agent`).
> **Fáze 3 hotová** (2026-08-05): mail-router — tabulka mail_routers +
> `hosting-router-key`, sdílený `HostingApiKeyAuthenticator`, endpoint
> `/_hosting/mail/lookup` (ETag/304), krok `mail-router-setup --json`
> v agentu + `mail_token` v confirmu (šifrovaně), `lookup-sync` v repu
> `mail_router` (task `hosting-04-mail-router`).
> **Fáze 4 hotová** (2026-08-06): AI gateway — passthrough endpoint
> `/_hosting/ai-gw/v1/messages` s metering, tabulky ai_tokens/ai_usage,
> CLI `hosting-ai-gw-init`/`hosting-ai-token`, ai sekce queue payloadu +
> krok `ai-analyzer-set-key --base-url` (dnes `ai-backend-set-key`) v agentu (task `hosting-05-ai-gateway`).
> **Fáze 5 hotová** (2026-08-06): přehled napříč DS — `shpd-ds
> hosting-stats`, stats krok agenta řízený `stats_wanted` z reconcile,
> tabulka ds_stats (snapshot upsert), badge „k řešení" na portálu
> (task `hosting-06-stats`).
> **Portál v app shellu** (2026-08-13): revize D10 — jednotný AppShell pro
> všechny přihlášené, portál = panel `hostingPortal` v hlavní navigaci,
> ne-admin dostává serverem ořezanou navigaci (odvozené `adminOnly`),
> landing = první root leaf; `PortalScreen` a `hasPortal` zanikly
> (task `hosting-07-portal-in-shell`).
> **Dashboard module guards** (2026-08-13): feed zdroje se registrují dle
> přítomnosti modulu + per-source izolace (dashboard nepadá na hosting DS
> bez core.mail), response nese `capabilities {mailUpload, chat}`, Chat
> leaf vyžaduje aktivní core.chat, viewer Upozornění `adminOnly` na
> deklaraci (task `hosting-07b-dashboard-module-guards`).
> **Self-service zakládání DS** (2026-08-13): wizard na portálu —
> `provision_default` na serverech (feature switch), `selfService`
> install moduly, web_id validace + blocklist, portálové endpointy
> create-meta/check-web-id/create-datasource, pending karty s pollingem
> (task `hosting-08-self-service-ds`, sekce 6.1).

---

## 0. Schválená rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Hosting = modul `hosting.core` běžící na běžném DS. Portál = frontend tohoto DS. Kdokoli (např. účetní kancelář) si může rozjet vlastní hosting aktivací modulu. |
| D2 | Centrální identita = **vlastní minimální OIDC Provider (OP)** na hosting DS. Jen authorization code + PKCE, RS256, discovery + JWKS. Žádné externí IdP v deploymentu — jsou náročné na údržbu a jejich funkce nejsou potřeba. |
| D3 | Provisioning DS = **pull agent** `shpd-server hosting-sync` (cron na DS serveru). Polluje hosting, lokálně provede založení, potvrdí zpět. Součástí je rekonciliace (inventura existujících DS + verzí). |
| D4 | Mail-router lookup: `lookup.json` generuje **pull proces na mail-router stroji** z hosting API. Per-DS `shpd_ak_` tokeny mintuje DS server při provisioningu (`mail-router-setup`) a **hlásí je hostingu**; hosting je broker (uložené v `encrypted_text`). |
| D5 | AI gateway na hostingu: PHP endpoint, Anthropic Messages **passthrough** (včetně SSE). V1 = auth + metering (per-DS log tokenů); limity a model allowlist v2, schéma logu na ně připravené. Jen Anthropic. |
| D6 | Vlastní AI klíč zůstává **rovnocennou cestou** — gateway je jen jiná data v `core_ai_backends` (`base_url` = gateway, `api_key` = gateway token). Nulová změna kódu na straně DS — `base_url` respektuje PHP `AnthropicLlmClient`. |
| D7 | Přehled napříč DS = **push agregátů** z DS do hostingu (agent, cron). On-demand fetch lze doplnit kdykoli později. |
| D8 | Portálové účty = `core_system_users` hosting DS. Žádná nová tabulka uživatelů — OP autentizuje proti běžné user bázi hostingu (lokální login, pozvánky/reset z Fáze 0b fungují beze změny). |
| D9 | **Admin-only tabulky**: definice tabulky může deklarovat `"adminOnly": true`; `TableAccessGuard` to vynucuje plošně (CRUD/viewer/form/lookup → 403 pro ne-admina) stejně jako dnes prefix `core_system_`. Všechny `hosting_core_*` tabulky flag nesou. Malé rozšíření jádra s hodnotou i mimo hosting; nejhrubší stupeň budoucího RBAC. |
| D10 | **Jedno přihlášení** = session na hosting DS. ~~Ne-admin po přihlášení vidí pouze portál~~ *(revize 2026-08-13, task `hosting-07-portal-in-shell`)*: ne-admin vidí **app shell s navigací ořezanou na to, co mu server dovolí** — portálová data jdou dál výhradně přes dedikované endpointy `/_hosting/portal/*` scopované na session uživatele (žádné generické viewery nad hosting tabulkami); admin navíc standardní aplikaci s hosting viewery (= administrace hostingu). OIDC `authorize` používá tutéž session — SSO: uživatel se session proletí na DS bez zastávky. |
| D11 | Hosting DS je **dedikovaný** — install modul `install.hosting`; vlastní agenda provozovatele (účetnictví, pošta…) žije v samostatném běžném DS. Doporučení, ne tvrdý zámek — D9+D10 chrání i smíšený případ. |
| D12 | OIDC **issuer je explicitně uložený v nastavení hostingu**, ne odvozovaný z requestu. `(issuer, sub)` je klíč identit na všech DS — změna domény portálu ho nesmí tiše zneplatnit. Doménu portálu volit s rozmyslem hned na začátku. |
| D13 | **Zrušeno** (#85 D22, 2026-10-10, `tasks/ai-analyzer-removal.md`) — externí AI analyzer se na DS už nenapojuje; analýzu pošty dělá runner v procesu na DS serveru a přes hosting jde jen volání modelu (AI gateway, D5/D6). Původní řešení (task `hosting-10-ai-analyzer`): agent mintoval `analyzer_token` (`ai-analyzer-setup --json`, krok h.), hosting ho brokeroval přes `GET /_hosting/ai-analyzer/lookup` (klíč `shpd_hk_` analyzeru v `hosting_core_ai_analyzers`), analyzer stroj si ho stahoval procesem `sources-sync`. Tabulka a sloupec zůstávají v databázích osiřelé (servisní výmaz = roadmapa M5). |

Vědomě mimo scope (lze přidat později jako samostatné moduly / fáze):
fakturace (partners, invoicingGroups), helpdesk, monitoring (updown.io,
netdata), evidence HW/LXC v plné šíři, automatizace TLS certifikátů
a web-proxy konfigurace, pozastavení/mazání DS lifecycle, RBAC jemnější
než `is_admin`.

**Podoblast:** nastavení čerstvě založeného DS (vlastní Osoba, registrace
DPH, bankovní spojení, účtová osnova) řeší [`ds-setup.md`](ds-setup.md).
Hosting do něj přispívá výhradně přenosem jazyka a země z admin
formuláře do `ds-create` (tam D7); vlastní nastavení probíhá až
v cílovém DS.

---

## 1. Motivace

1. **Noví uživatelé** — ruční zakládání DS je pracné (ds-create, ds-upgrade,
   domain-add, user-create, mail-router-setup, ai-backend-set-key — šest
   ručních kroků na různých strojích).
2. **Napojení na mail-router a AI** je ruční a chybové (editace `lookup.json`
   mail-routeru, distribuce API klíčů).
3. **Portál** — uživatelé s více DS potřebují jeden vstupní bod: seznam svých
   DS, tlačítka pro vstup, přehled „co je kde potřeba řešit".
4. **Centrální autorita pro přihlašování** — RP infrastruktura (authorization
   code + PKCE, `IdentityMapper`, handoff) existuje (`docs/auth.md`), chybí OP.
   `docs/auth.md` s „centrálním Shipard ID" explicitně počítá.

## 2. Co se přebírá ze starého hostingu a co ne

**Přebírá se:**

- *Hosting je sám Shipard DS s modulem* — tabulky, docStates, viewery,
  server-driven UI. Žádná zvláštní aplikace.
- *Pull-based provisioning* — starý `DSCreator.php`: server polluje
  `get-new-data-source-request?serverGID=…` s API klíčem, lokálně provede
  založení, potvrdí `confirm-new-data-source-request`. Firewall-friendly,
  hosting nikam nepotřebuje SSH, výpadek agenta nic nerozbije.
- *Server ví „kam jít"* přes `/etc/shipard/server.json` (staré `useHosting`,
  `hostingDomain`, `hostingApiKey`, `serverGID` → nová sekce `hosting`, §5.1).

**Nepřebírá se:**

- Spojování účtů přes e-mail (starý `dsUsers` + e-mail jako klíč) → nahrazeno
  stabilním `(issuer, sub)` z OIDC.
- Push statistik vlastním protokolem (`HostingUserSummaryUpload`,
  `DSHostingInfoReceiver`) → jednodušší agregát v rámci `hosting-sync`.
- Vše ze sekce „mimo scope" výše.

## 3. Architektura — komponenty

```
                       ┌──────────────────────────────────────────┐
                       │  HOSTING DS  (modul hosting.core)        │
                       │                                          │
   prohlížeč ─────────►│  Portál (frontend hosting DS)            │
                       │  OIDC OP   /_hosting/oidc/*  (D2)        │
                       │  Portál API /_hosting/portal/* (D10)     │
                       │  Server API /_hosting/server/* (D3, D7)  │
                       │  Mail API  /_hosting/mail/lookup (D4)    │
                       │  AI gateway /_hosting/ai-gw/v1/messages  │
                       └───▲──────────▲──────────▲────────────────┘
                           │          │          │
        pull (cron)        │          │          │        pull (cron)
  ┌────────────────────────┴──┐   ┌───┴──────────┴───────────────┐
  │ DS SERVER                 │   │ MAIL-ROUTER stroj            │
  │ shpd-server hosting-sync  │   │ lookup-sync → lookup.json    │
  │  · založení DS            │   │  (mtime-watch reload beze    │
  │  · rekonciliace, stats    │   │   změny mail_routeru)        │
  │  · mail token → hosting   │   └──────────────────────────────┘
  └───────────────────────────┘
        │ per-DS
        ▼
  DS: auth.providers → OP hostingu (RP strana existuje)
      core_ai_backends → base_url = AI gateway (D5/D6)
```

Všechny integrační směry jsou **pull od klienta k hostingu** — hosting nikdy
aktivně nevolá DS servery ani mail-router. Jediná výjimka: AI gateway volá ven na `api.anthropic.com`
(passthrough).

### 3.1 Endpointy a jejich zařazení

Routy podle precedentu `core.mail`: controllery v `src/Api/Controller/`
(příp. dispatch do `modules/hosting/core/src/`), větve v `src/Api/Router.php`,
funkční jen když je na DS aktivní `hosting.core`. Auth režimy:

| Prefix | Auth |
|---|---|
| `/_hosting/oidc/*` (discovery, jwks, authorize, token) | exempt v `AuthMiddleware` (vzor `/_auth/oidc/*`); authorize vyžaduje session uživatele na hostingu (redirect na login portálu) |
| `/_hosting/portal/*` (my-datasources, my-summary) | session uživatele hostingu; server vrací **jen řádky daného uživatele** (D10) |
| `/_hosting/server/*` | `shpd_hk_` klíč serveru (vlastní prefix; prefix + SHA-256 hash na `hosting_core_servers`, validuje `HostingServerController` sám — `core_system_api_keys` jsou vázané na uživatele a `AuthContext` identitu klíče nenese) |
| `/_hosting/mail/lookup` | `shpd_hk_` klíč routeru (stejné schéma jako klíče serverů; prefix + SHA-256 hash na `hosting_core_mail_routers`, sdílená validace `HostingApiKeyAuthenticator`) |
| `/_hosting/ai-gw/*` | gateway token (vlastní tabulka, ne `core_system_api_keys` — jiná audience) |

## 4. Datový model (náčrt — tableId přidělí `next-table-id` v PRD)

Skupina `modules/hosting/core/`, tabulky `hosting_core_*`. DocStates dle
konvence (výjimky: append-only log `ai_usage` a snapshot `ds_stats` je
nemají) a **všechny s `"adminOnly": true`** (D9) —
generické CRUD/viewer/form cesty jsou pro ne-adminy uzavřené, portál jde
výhradně přes `/_hosting/portal/*`.

| Tabulka | Obsah |
|---|---|
| `hosting_core_servers` | DS servery: název, FQDN, stav, příznaky „smí zakládat DS", hash API klíče serveru, last_seen, verze (shpd, OS) z rekonciliace |
| `hosting_core_data_sources` | Evidence DS: ds_id (`xxxx-xxxx-…`), název, web-id slug, server (FK), doména/URL aplikace, install modul, lifecycle stav (požadavek → zakládá se → aktivní → …), mail token (`encrypted_text`, D4), časy |
| `hosting_core_ds_users` | Vazba uživatel (FK `core_system_users` hostingu) ↔ DS + role (admin/člen). Zdroj pro portálový seznam „moje DS" |
| `hosting_core_mail_routers` | Mail-routery: název, obsluhované domény, hash API klíče, last_seen |
| `hosting_core_ds_stats` | Push agregáty per DS (D7): `alerts_count` + `mail_count` (NULL = modul na DS neaktivní), `collected_at`. Snapshot — jeden řádek per DS (unique `data_source`, upsert, bez historie). Malé, bez osobních dat |
| `hosting_core_ai_tokens` | Gateway tokeny: DS (FK), hash tokenu, aktivní, expirace |
| `hosting_core_ai_usage` | Metering: DS, model, input/output tokeny, timestamp, (rezerva: cache-read tokeny). Schéma připravené na limity v2 |

Poznámky:

- Privátní RS256 klíč OP žije v `secrets/` hosting DS (vedle `secrets.key`),
  ne v DB. Rotace klíčů = v2 (JWKS umí víc klíčů od začátku).
- OIDC OP autorizační kódy / consent transakce: malá tabulka
  `hosting_core_oidc_codes` (obdoba `core_system_auth_transactions` na RP
  straně) — single-use, krátké TTL, oportunistický úklid.
- `hosting_core_data_sources.lifecycle` řídí provisioning frontu (stav
  „požadavek" = ekvivalent starého docState 1100 + `inProgress`).

## 5. Kontrakty

### 5.1 Klientská konfigurace DS serveru

`/etc/shipard/server.json`, volitelná sekce (implementace:
`ServerConfig::getHosting()` → readonly `HostingConfig`):

```jsonc
"hosting": {
    "url": "https://portal.example.com",   // base URL hosting DS (https; http jen localhost dev)
    "serverId": 3,                          // ndx řádku hosting_core_servers (informativní/log)
    "apiKey": "shpd_hk_…"                   // klíč serveru z `shpd-ds hosting-server-key --generate`
}
```

Bez sekce se nic nemění — hosting je plně opt-in. Klíče serverů mají
vlastní prefix `shpd_hk_` (na hostingu jen prefix + SHA-256 hash);
postup připojení serveru: `docs/cli.md` → Workflow scénář 8.

### 5.2 `shpd-server hosting-sync` (D3)

Jeden běh (cron slot `two-minutes`; `--dry-run` = náhled fronty přes
`queue?peek=1` — nepřeklápí stavy a neposílá client_secret):

1. **Rekonciliace** — `POST /_hosting/server/reconcile`, body
   `{version, dataSources: [{ds_id, name, modules}]}`. Hosting
   aktualizuje `last_seen` + `last_version`, rozdíly evidence ↔ realita
   jen loguje (F2).
2. **Provisioning** — `GET /_hosting/server/queue` → požadavky serveru
   (`lifecycle` request/creating; servírování = překlopení na `creating`
   + `claimed_at`; `can_provision = false` → prázdná fronta). Payload
   per item: `{request_id, ds_id, name, install_module, web_id,
   language, country, host, owner: {email, name, sub},
   oidc: {issuer, client_id, client_secret, label}}` — `language`
   a `country` = vrstva A z admin formuláře (ds-setup D1/D7; chybějící
   hodnota = chyba požadavku, agent nedoplňuje default; požadavek může
   vzniknout i self-service z portálu — sekce 6.1, payload beze změny),
   `sub` =
   (string) id vlastníka (přesně co OP dává do id_tokenu), issuer ze
   settingu (D12), secret dešifrovaný (jediné místo, kde opouští
   hosting — https, jednorázově). Pro každý požadavek (chyba jednoho
   nezastaví další): `ds-create --ds-id --language --country`
   (existující adresář = skip) → `ds-upgrade` → `domain-add` → merge položky
   `{id: "shipard-id", label, issuer, clientId, clientSecret,
   autoLinkEmail: false}` do `auth.providers` (atomicky, 0600; U2 —
   identita se předpropojuje) → `user-create --admin --if-not-exists
   --identity-provider shipard-id --identity-issuer … --identity-subject
   {sub}` → `POST /_hosting/server/confirm` `{request_id, ds_id,
   status: "ok"|"failed", error?}`. Confirm `ok` → `lifecycle = active`
   + vazba vlastníka v `hosting_core_ds_users` (role `admin`, U1);
   `failed` → `lifecycle = failed` + `provision_error`, retry = admin
   přepne zpět na `request`. Za user-create následují: krok f.
   `mail-router-setup --json` (D4, jen s aktivním `core.mail`; token →
   confirm `mail_token`) a krok g. `ai-backend-set-key` (D5, jen s `ai`
   sekcí payloadu a aktivním `core.ai`). Krok h. (token externího AI
   analyzeru, D13) zanikl (#85 D22); `analyzer_token` od staršího agenta
   hosting tiše ignoruje.
3. **Stats push** (D7) — jen když reconcile response nese
   `stats_wanted: true` (hosting ho vrací, když je nejstarší snapshot
   jeho aktivních DS starší než ~10 min nebo žádný nemá; kadenci tedy
   řídí hosting, agent je stateless) nebo běh dostal `--stats`. Pro
   každý lokální DS z inventury `shpd-ds hosting-stats --json` →
   `{"alerts": N|null, "mail": N|null}` (COUNTy se sémantikou feed
   karet, NULL = modul neaktivní; selhání jednoho DS = skip + log).
   Nasbírané jedním `POST /_hosting/server/stats`
   `{stats: [{ds_id, alerts, mail}]}` → upsert do
   `hosting_core_ds_stats` (`collected_at = now`; cizí/neznámé ds_id
   skip + warning), response `{ok, accepted}`. Prázdný sběr se
   neposílá. Bez osobních dat — jen čísla.

### 5.3 Mail lookup (D4) — hotovo (Fáze 3)

`GET /_hosting/mail/lookup` (klíč routeru `shpd_hk_`, CLI
`hosting-router-key`) → **přesně** formát `lookup.json` (žádný success
envelope): `hosts` = domény z řádku routeru, `data_sources` = aktivní DS
(`lifecycle = active`, živý docState) s vyplněným `mail_token`
(dešifrovaný do `api_token`; ds_id i web-id slug → `api_url` z `url_app`).
ETag = sha256 kanonizovaného obsahu, `If-None-Match` shoda → 304 bez body.

Token mintuje agent v kroku f. provisioningu (`mail-router-setup --json`,
jen s aktivním `core.mail`; retry po pádu rotuje s `--force`) a hlásí ho
v confirm body (`mail_token`) — hosting ho ukládá šifrovaně
(`HostingDataSourceDocument`) a přepisuje nepodmíněně. Ruční backfill
existujícího DS: admin form (opt-in sensitive pole, viz
`docs/operations/mail-router.md`).

Na mail-router stroji oneshot **`lookup-sync`** (repo `mail_router`,
systemd timer à 2 min): validace před zápisem → atomický zápis (temp +
rename) → existující mtime-watch reload. Hosting down ⇒ jede se na stale
lookup, pošta se neztrácí.

### 5.3b AI analyzer lookup — zrušeno (#85 D22)

Endpoint `GET /_hosting/ai-analyzer/lookup`, CLI `hosting-analyzer-key`,
tabulka `hosting_core_ai_analyzers` a sloupec `analyzer_token` zanikly
spolu s externím analyzerem (D13 zrušeno, `tasks/ai-analyzer-removal.md`).
Analýzu pošty dělá runner v procesu na DS serveru; přes hosting jde jen
volání modelu AI gateway (§5.5). Osiřelá tabulka a sloupec v databázích
hostingů zůstávají, `ds-upgrade` je nemaže — servisní výmaz je položka
roadmapy M5. Potvrzení provisioningu od staršího agenta s polem
`analyzer_token` hosting přijme a pole ignoruje.

### 5.4 OIDC OP (D2, D12)

**Implementováno ve Fázi 1** (`HostingOidcController`,
`tasks/hosting-02-oidc-op.md`).

- `GET /_hosting/oidc/.well-known/openid-configuration`,
  `GET /_hosting/oidc/jwks`, `GET /_hosting/oidc/authorize`,
  `POST /_hosting/oidc/approve`, `POST /_hosting/oidc/token`. Gating:
  neaktivní modul nebo nevyplněný issuer setting → 404.
- **Issuer je explicitní setting `hosting.oidc.issuer`** (D12, settings
  stránka „OIDC provider", `adminOnly`) — trvalá forma
  `https://{host}/api/v1/_hosting/oidc`; discovery i id_tokeny ho používají
  doslovně, odvození z requestu se nepoužívá. Nesoulad hostname requestu
  vs. issuer = warning do logu. RP porovnává `iss` claim byte-exact.
- Jen authorization code + PKCE S256, `client_secret_post`, RS256 id_token
  (kid v hlavičce — povinný pro RP keyset lookup) s claimy `sub` (= user id
  hostingu, stabilní), `email`, `email_verified: true`, `name`, `nonce`.
  Přesně to, co náš RP validuje — nic navíc.
- Podpisový klíč: `secrets/oidc-op.key` (PEM RSA 3072, 0600, `OpKeyStore`),
  zakládá `shpd-ds hosting-oidc-init`. Nikdy v DB ani logu.
- Klienti (jednotlivé DS) = řádky v `hosting_core_data_sources`
  (client_id = ds_id, `oidc_client_secret` encrypted_text + sensitive,
  `oidc_redirect_uri` exact match). Fáze 1 plní `shpd-ds
  hosting-oidc-client --ds … --redirect-uri … --generate`; Fáze 2 agent.
- **Session bridge (D10):** SPA drží session token v localStorage, browser
  GET na `authorize` tedy hlavičku nenese. `authorize` (exempt) validuje
  požadavek, založí transakci v `hosting_core_oidc_codes` (TTL 10 min)
  a přesměruje na `/app/?op_auth={txn}`; SPA se session zavolá
  `POST /_hosting/oidc/approve` (Bearer, NENÍ exempt) → server naváže
  uživatele, vydá kód (TTL 60 s) a vrátí RP redirect URL; SPA provede
  `window.location`. Bez session SPA nejdřív ukáže LoginScreen (`op_auth`
  přežije login). SSO = tiché schválení bez interakce.
- Kód je single-use (token endpoint řádek smaže před validací), transakce
  single-use (`user IS NULL` guard v approve). Selhání token endpointu →
  400 `{"error":"invalid_grant"}` (OAuth tvar, bez rozlišení důvodu).
- Rate limit: authorize/approve/token v login bucketu (10/min/IP);
  discovery/jwks v defaultu (RP je cachuje).
- Vědomá volba: OP je čistě identitní autorita — kód vydá kterémukoli
  přihlášenému uživateli hostingu bez kontroly `hosting_core_ds_users`.
  O vpuštění do DS rozhoduje RP (`IdentityMapper`: autoLink/JIT/
  `oidc_no_account`). Centrální gating přes ds_users = případný v2 flag.

Výsledný login flow na DS: LoginScreen → „Přihlásit přes {hosting}" →
portál (session tam) → id_token → RP `IdentityMapper` `(issuer, sub)`,
`autoLinkEmail`/`jitProvision` dle politiky DS.

#### Federované přihlašování hostingu (Google, GitHub) — runbook

Hosting DS je sám relying party vůči externím IdP (task
`hosting-09-federated-login`): uživatel se na hosting — a skrze Shipard Id
do všech svých DS — přihlásí bez hesla. Klientské DS se nemění a o federaci
nevědí. Návrat do rozjednané OP transakce po plné navigaci na IdP zajišťuje
`return_to` (`docs/auth.md` → Návrat po loginu).

**Google** (config-only, běžný OIDC provider):

1. Google Cloud Console → OAuth client (typ **Web application**),
   Authorized redirect URI: `https://{hosting-host}/api/v1/_auth/oidc/callback`.
2. Položka `auth.providers` v `config/main.json` hosting DS:

```json
{
    "id": "google",
    "label": "Google",
    "issuer": "https://accounts.google.com",
    "clientId": "<z Google Cloud Console>",
    "clientSecret": "<z Google Cloud Console>",
    "autoLinkEmail": true,
    "jitProvision": false
}
```

**GitHub** (`kind: "github"`, viz `docs/auth.md` → GitHub jako provider):

1. GitHub → Settings → Developer settings → **OAuth App**, Authorization
   callback URL: táž jako u Google.
2. Položka `auth.providers`:

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

Provozní poznámky:

- `autoLinkEmail: true, jitProvision: false` (D4 tasku) — externí IdP je
  jen způsob přihlášení **existujících** účtů (pozvánka/self-service),
  nikoli otevřená registrace. Cizí účet skončí `oidc_no_account`.
- Editace `config/main.json` hosting DS = mutace na alfě (per-action
  schválení, provádí David). Restart není třeba — config se čte
  per-request. Před zapnutím: `ds-upgrade` (sloupec `return_to`
  v `core_system_auth_transactions`).
- Lokální login heslem na hostingu zůstává zapnutý; případné
  `local: false` je provozní rozhodnutí, ne součást rolloutu (D5 tasku).

### 5.5 AI gateway (D5/D6) — implementováno

- `POST /_hosting/ai-gw/v1/messages` — klienti si na `base_url` sami
  připojují `/v1/messages`, gateway tedy servíruje přesně tuto cestu
  (`HostingAiGatewayController`); jiné cesty pod `/_hosting/ai-gw/` → 404.
- Ověří gateway token (`x-api-key`, prefix `shpd_gw_` — prefix lookup +
  `hash_equals` nad sha256 celého tokenu v `hosting_core_ai_tokens`,
  `active` + lifecycle DS `active`; selhání → 401 v **Anthropic error
  formátu**) → nahradí ho klíčem organizace ze
  **`secrets/ai-gw-anthropic.key`** (0600, `AiGwKeyStore` — stejné
  zacházení jako privátní klíč OP, nikdy v DB; plní CLI
  `hosting-ai-gw-init --set-key`) → passthrough na `api.anthropic.com`
  včetně SSE streamu → z odpovědi paralelně vytěží `usage`
  (`GwUsageExtractor`: SSE `message_start` + `message_delta`, non-SSE JSON)
  → zápis do `hosting_core_ai_usage` (vždy, i chybové odpovědi
  s `http_status`; selhání meteringu odpověď neshodí).
- Forward headers jsou allowlist (`content-type`, `anthropic-version`,
  `anthropic-beta`) — `authorization`/cookies se nikdy nepropustí; body
  limit 32 MiB; vlastní rate-limit bucket `ai_gw` per token (300/min).
- Provisioning: queue payload nese sekci `ai` (`base_url` odvozená
  z issueru — `HostingUrls::aiGwBaseUrl`, `api_key` = gateway token) jen
  když org klíč existuje. Token se mintuje **lazy při stavbě queue
  payloadu** (existující aktivní token se dešifruje z `token_encrypted`
  — retry-stabilní; jinak nový řádek). Agent (krok g.) na DS s aktivním
  `core.ai` spustí `ai-backend-set-key --backend default --api-key …
  --base-url …`. Ruční backfill: `hosting-ai-token --ds <ndx> --generate`.
- Vlastní klíč = uživatel si backend přepne/založí jiný (D6) — gateway je
  jen jiná data v `core_ai_backends`, na straně DS se nemění žádný kód.
- Vědomý limit v1: streamované proxy spojení drží PHP-FPM worker. Pro
  desítky klientů OK; při růstu se gateway vydělí do samostatného daemonu
  (rozhraní se nemění — je to jen `base_url`).

## 6. Portál a přístupový model (D8–D11)

Frontend hosting DS, žádná zvláštní aplikace. **Jedno přihlášení = session
na hosting DS**; všichni přihlášení dostávají **jednotný app shell**
(sidebar + obsah) — revize D10, task `hosting-07-portal-in-shell`.
Přehled „Moje zdroje dat" je **panel hlavní navigace** (`hostingPortal`
v `panels[]` modulu, `navSection: "_top"`, `navOrder: 10`) — první
root-level leaf na hosting DS, vidí ho i admin. Zobrazuje **jen DS
uživatele** z `hosting_core_ds_users` (název, vstupní tlačítko, agregát
z `hosting_core_ds_stats`); data výhradně z `/_hosting/portal/*`.

- **Ne-admin (portálový uživatel)**: navigace je serverem **ořezaná na
  to, co mu server dovolí** — model odvozeného `adminOnly`:
  `NavigationController` ne-adminovi nevrací položky nad tabulkami
  s prefixem `core_system_` nebo s `adminOnly` na `TableDefinition`
  (tentýž zdroj pravdy jako `TableAccessGuard`, D9) ani položky
  s explicitním `adminOnly: true` na deklaraci vieweru/panelu. Na
  dedikovaném hosting DS tak zbývá portál + Dashboard (budoucí hosting
  alerty přes existující feed); root leaf **Chat se ne-adminovi na DS
  s aktivním `hosting.core` nevrací**. Zadarmo tím získává Nastavení
  účtu (heslo, avatar, vzhled, jazyk); „Nastavení aplikace" v user menu
  vidí jen admin. Landing po přihlášení = **první root-level leaf**
  stromu navigace (obecné pravidlo — na hosting DS portál, jinde
  Dashboard). Typický portálový uživatel jinak hosting DS vůbec
  „nepoužívá" — přichází přes OIDC redirect ze svého DS a se session
  proletí bez zastávky (SSO).
- **Admin hostingu**: standardní aplikace s hosting viewery (servery,
  zdroje dat — včetně založení nového: formulář vytvoří řádek ve stavu
  „požadavek", zbytek udělá agent —, mail-routery, AI spotřeba);
  portálový přehled má jako první položku navigace.

Bariéry byly vždy na serveru: `adminOnly` tabulky (D9) + portálové
endpointy scopované na session uživatele (D10) — navigace je nyní jen
zrcadlí. Důsledek pro smíšený DS (D11): ne-admin nově vidí svoji
legitimní agendu (dřív viděl jen portál), ale přichází o Chat —
akceptováno, dedikovaný hosting DS je doporučený stav. Vědomý kompromis
v1: `is_admin` je jediný stupeň — admin hostingu má i `core_system_*`
(uživatelé, API klíče); jemnější RBAC mimo scope.

Branding portálu = existující app-settings (název, logo) — starý
`hostings` číselník není potřeba, jeden hosting DS = jeden hosting.

### 6.1 Self-service zakládání DS (task `hosting-08-self-service-ds`)

Každý přihlášený uživatel hostingu (admin i ne-admin) si může z portálu
založit nový DS. Modal wizard (název, web_id se živou kontrolou, země/jazyk
s defaults cz/cs) vytvoří řádek `hosting_core_data_sources` s `lifecycle =
request` a `owner` = session uživatel — o zbytek se stará existující
provisioning pipeline Fáze 2 (payload i confirm beze změny).

- **Feature switch = default server.** Boolean `provision_default` na
  `hosting_core_servers` („Výchozí pro nové DS"); smí ho mít jen server
  s `can_provision`, nejvýše jeden — uložení řádku s příznakem ostatním
  příznak shodí (`HostingServerDocument::afterPersist`, poslední vyhrává).
  Bez default serveru se tlačítko na portálu nekreslí a create vrací
  `no_server`.
- **Install moduly** pro self-service označuje top-level
  `"selfService": true` v jejich `module.jsonc` (`install.base` ano,
  `install.hosting` ne). Roletka ve wizardu se kreslí jen při >1 nabízeném;
  při jediném se použije implicitně.
  `InstallModuleRegistry::list($language, $selfServiceOnly)` filtruje
  a lokalizuje name/description.
- **web_id**: 3–50 znaků `a-z0-9-`, bez krajních pomlček (subdoména —
  DNS/certifikáty), normalizace trim+lowercase, blocklist rezervovaných
  hodnot (`www`, `mail`, `api`, …) — vše v `HostingDataSourceDocument`
  (platí i pro adminský formulář; kontroly běží jen při novém řádku nebo
  změně hodnoty, editace se zachovaným web_id projde). Sdílená pravidla
  `checkWebIdRules()` používá i živá kontrola.
- **Portálové endpointy** (guard = modul aktivní + session): `GET
  /_hosting/portal/create-meta` (canCreate + reason
  `no_server|open_request|max_owned`, nabídka modulů/jazyků/zemí), `GET
  /_hosting/portal/check-web-id?value=…` (informativní; autorita je
  validace při create), `POST /_hosting/portal/create-datasource` (limity
  znovu, zápis přes `TableGateway::saveDocument`, validace 422
  s per-field detaily).
- **Limity**: max 1 otevřený požadavek (request/creating) na uživatele;
  strop vlastněných DS = setting `hosting.selfService.maxOwned`
  (text field na settings page Hosting; prázdné = 5, 0 = bez limitu).
- **Pending karty**: `my-datasources` vrací i vlastní řádky
  request/creating/failed (`state: creating|failed`, bez stats, bez
  vstupu); frontend při existenci creating karty polluje po ~15 s,
  failed se ukazuje bez detailu chyby (detail vidí admin v evidenci,
  retry = admin).

Release kroky: `ds-upgrade` na hosting DS (nový sloupec serverů);
zapnutí = admin označí server příznakem „Výchozí pro nové DS".

### 6.2 Doména portálu

Hosting DS je obyčejný DS — doména se přidá standardně:

```
shpd-server domain-add --host shpd.dev --ds <hosting-ds-id>
```

`domains.json` mapuje hostname (apex funguje), jednotlivé DS zůstávají na
`*.shpd.dev`. DNS + TLS na úrovni nginx/proxy jako dnes. **Doména = issuer**
(D12) — volit s rozmyslem, pozdější změna zneplatňuje `(issuer, sub)`
identity na všech DS.

## 7. Bezpečnostní poznámky

- Hosting DB drží citlivé hodnoty (mail tokeny DS, gateway tokeny,
  client_secrets) — vše `encrypted_text` (per-DS šifrování
  hostingu).
- API klíče serverů/routerů: jen SHA-256 hash (vzor
  `core_system_api_keys`).
  Gateway tokeny (`shpd_gw_`): prefix + SHA-256 hash pro runtime validaci,
  navíc šifrovaný plaintext (`token_encrypted`) pro opakované servírování
  v queue payloadu.
- OP privátní klíč i klíč organizace pro AI (`ai-gw-anthropic.key`) mimo
  DB, v `secrets/` (0600).
- `adminOnly` tabulky (D9): skutečná bariéra je na serveru
  (`TableAccessGuard` na všech datových cestách — CRUD/viewer/form/lookup),
  UI jen nezobrazuje mrtvé odkazy — stejný princip jako Fáze 0a.
- `hosting_core_ds_stats` záměrně jen čísla — žádné názvy partnerů, částky,
  předměty mailů.
- Rate-limit na OP endpointech (login-class bucket jako u `/_auth/*`).

## 8. Fázování

| Fáze | Obsah | Hotovo když |
|---|---|---|
| **0 — Modul, evidence, přístupový model** | `adminOnly` mechanismus v jádru (`TableAccessGuard` + table-definitions, D9); skeleton `hosting.core` + `install.hosting`, tabulky servers / data_sources / ds_users, admin viewery; `/_hosting/portal/*` + portálová obrazovka pro ne-adminy (D10) | Portál běží na vlastním DS; ne-admin vidí jen svůj seznam DS a proklikne se; admin spravuje evidenci; generické CRUD nad `hosting_core_*` vrací ne-adminovi 403 |
| **1 — OIDC OP** | discovery + JWKS + authorize + token, `hosting_core_oidc_codes`, klíč v secrets, explicitní issuer setting (D12); ruční napojení jednoho DS přes `auth.providers` | Login na DS přes „Přihlásit přes {hosting}" funguje end-to-end proti RP z `docs/auth.md`, včetně SSO průletu se session |
| **2 — Provisioning agent** | `server.json` sekce `hosting`, `shpd-server hosting-sync` (rekonciliace + fronta požadavků + confirm), formulář „nový DS" na portálu | Nový DS vznikne z portálu bez ručního zásahu na serveru, včetně `auth.providers` |
| **3 — Mail-router** | `/_hosting/mail/lookup`, mail token flow v agentovi (mint + report), `lookup-sync` v repu `mail_router` | Nový DS přijímá poštu bez ruční editace `lookup.json` |
| **4 — AI gateway** | ai-gw passthrough + tokeny + metering, krok v provisioningu (backend řádek) | Nový DS má funkční AI (chat, analýza pošty) od založení; hosting vidí spotřebu per DS |
| **5 — Přehled** | Stats push v agentovi, `hosting_core_ds_stats`, agregáty na portálu | Uživatel na portálu vidí, kolik čeho v jednotlivých DS čeká |

**Všechny fáze 0–5 jsou hotové** — data dokončení a rozsah viz stavový
blok v hlavičce dokumentu. Follow-up D13 (napojení nových DS na externí
AI analyzer, task `hosting-10-ai-analyzer`) byl realizován a následně
zrušen spolu s analyzerem (#85 D22) — §5.3b.

Pořadí 1↔2 lze prohodit; OP dřív znamená, že provisioning zapisuje
`auth.providers` od první verze a DS se rodí rovnou s centrálním loginem.

## 9. Dotčené projekty

| Projekt | Dopad |
|---|---|
| `nov_shipard` | Rozšíření jádra: `adminOnly` v table-definitions + `TableAccessGuard` (D9). Nová skupina `modules/hosting/` + `install.hosting`, controllery + routy, `hosting-sync` v `shpd-server`, rozšíření `server.json`, portálový režim frontend |
| `mail_router` | Nový proces/cron `lookup-sync` (fáze 3); běhové jádro beze změny |
| `ai_analyzer` | Oneshot `sources-sync` + systemd timer a path-unit reload (D13) — komponenta i s tímto napojením zrušena (#85 D22–D24), repozitář archivován |
| `shipard_node` | Beze změny ve fázích 0–5; odchozí pošta per DS je samostatné budoucí téma |
