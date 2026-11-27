# Nativní aplikace — task 1: kontrakt mostu a dokumentace (#177)

**Stav:** hotovo — dokumentace hotová 2026-11-27 ve čtyřech commitech (#177 D21–D32, U1–U10, U16); odchylky od osnovy odsouhlasené při plánování jsou na konci zadání

> Task pro Claude Code (4 commity). **Jen dokumentace, žádný kód.**
> Design: issue #177 — D1–D20 v těle issue, D21–D31 v komentáři.
> Podle `docs/services.md` §12 se kontrakt vůči samostatné komponentě
> dokumentuje **před** implementací klienta. Navazující tasky #177
> (frontend, `core.devices`, tiskové úlohy a ESC/POS, kostra aplikace,
> profily a PIN, přihlášení přes systémový prohlížeč, vydávání) na
> `docs/native-bridge.md` odkazují a doplňují do něj stav implementace.

## Před implementací přečti

- `docs/services.md` — celý, mění se (D30)
- `docs/mail/api-contract.md` — vzor dokumentu kontraktu (status, verze, historie změn)
- `docs/rest-api.md` §2 (URL v produkci a ve vývoji), §4 (obálka odpovědi, chybové kódy)
- `docs/auth.md` — session `shpd_st_`, OIDC handoff, jednorázové tokeny, auth politika v `main.json`
- `docs/prints.md` §1, §4, §6 — `PrintData`, REST `/_prints`
- `docs/roadmap.md` (oddíly Doplňky, Vědomě odložené, Historie revizí), `docs/features.md` (Prostředí, Pokladna, Vědomě mimo rozsah)
- `docs/documentation.md` §4 — pravidla psaní dokumentace

## Kontext

Nativní aplikace Shipardu (macOS a iPadOS, repo `shipard/shipard-app`) je
okno s WKWebView, ve kterém běží běžný Shipard, a k tomu device hub pro
periferie (D1, D2). Webová aplikace s ní mluví přes most
`window.shipardNative` (D3, D21). Nativní část navíc sama volá několik
endpointů serveru, podepsaných klíčem zařízení (D27). Logika — skládání
ESC/POS, směrování, tiskové úlohy — zůstává na serveru; aplikace funguje
jen online.

`docs/native-bridge.md` je **zdroj pravdy pro obě strany**: webová strana
a server žijí v tomto repu, nativní strana v `shipard-app`.

V1 je jen lokální cesta (D11): tiskárna a čtečka jsou dosažitelné ze
zařízení, na kterém běží kasa. Hub bez okna, Mac jako hub pro jiná
zařízení, realtime brána (D12), Windows a Android do v1 nepatří — kontrakt
s nimi ale nesmí být v rozporu.

## Upřesnění (potvrzená 2026-11-26)

- **U1** — Metoda `device.sync` navíc k seznamu D22: aplikace si podepsaným
  `GET /_devices/self` obnoví stav registrace (režim, název, zařazení
  uživatelé, politika PINu, automatické zamčení). Webová aplikace ji volá
  po registraci a po změně zařízení v Shipardu, aplikace sama při startu.
- **U2** — Metoda `session.take` navíc k D22; událost `shipard:session` je
  jen „zvonek“ bez tokenu. Webová aplikace volá `session.take` po události
  **i při startu** — po uspání profilu nebo pádu procesu WebView se stránka
  načítá znovu a událost by propadla.
- **U3** — Registr capabilities: `device`, `peripherals`, `print.escpos`,
  `print.pdf`, `scanner`, `profiles`, `auth.external`. Metoda `info` je
  dostupná vždy.
- **U4** — Klíč a podpis: ECDSA P-256 se SHA-256 (`ES256`), veřejný klíč
  jako SPKI DER v base64, podpis DER v base64. Podepisuje se řetězec
  `shipard-device-v1|<účel>|<deviceId>|<výzva>` (UTF-8). Výzva je
  jednorázová, platí 60 s a je vázaná na účel a zařízení. Podepsaná volání
  nesou hlavičky `X-Shipard-Device`, `X-Shipard-Challenge`,
  `X-Shipard-Signature`. Kde Secure Enclave není (starší Mac s Intelem),
  je klíč v Keychainu a `info.keyStorage` to říká.
- **U5** — Katalog profilů tiskáren je na serveru (cfgItem v `core.prints`);
  aplikace ho čte podepsaným voláním a u periferie si ukládá jen ID profilu.
  Server tak skládá ESC/POS podle vlastních dat, ne podle parametrů
  z aplikace.
- **U6** — Registrovat zařízení smí ve v1 jen administrátor zdroje dat.
- **U7** — Politika PINu je v auth politice DS: `auth.pin` =
  `{enabled, minLength, maxLength, maxAttempts}`, výchozí
  `{false, 4, 8, 5}` (D31). PIN se musí ve zdroji dat výslovně zapnout.
- **U8** — `print.submit`: data nejvýš 5 MB po dekódování, `copies` 1–10.
- **U9** — Nativní aplikace odchází z „Vědomě odložených“ v roadmapě do
  vlastního oddílu vedle Doplňků se stejným pravidlem (mimo milníky, blokuje
  ji jen kategorie 1) a ve `features.md` z „Vědomě mimo rozsah“ do oddílu
  Prostředí.
> **Úprava 2026-11-26 (při návrhu tasku 3):** periferie hlásí serveru
> nativní část sama podepsaným voláním (D32, mění D23) a
> `GET /_devices/self` nevrací pokladnu (U16). Osnova níže je už upravená.

- **U10** — „Maloobchod / kasa“ ve `features.md` se přesouvá z „Vědomě mimo
  rozsah“ do oddílu Pokladna jako plánovaná položka bez návrhu (vlastní
  design issue později); v řádku mimo rozsah zůstává „Jídelna, rezervace“.

## Rozsah

**V rozsahu**

- nový `docs/native-bridge.md` (kontrakt verze 1) podle osnovy níže,
- `docs/services.md` — kategorie Klientská aplikace (D30),
- `docs/roadmap.md` a `docs/features.md` (U9, U10),
- rozcestníky: `docs/README.md`, tabulka dokumentace v `CLAUDE.md`,
  nová oblast v `tasks/README.md`.

**Mimo rozsah**

- jakýkoli kód (tasky 2–8 z #177),
- `docs/frontend.md` — cílové prohlížeče a atributy pro WebKit (D10) patří
  do tasku 2 spolu s implementací,
- `docs/auth.md` — nová cesta vzniku session (PIN) se dopíše s implementací
  (task 6); `docs/prints.md` — tiskové úlohy a ESC/POS s taskem 4,
- mapa projektů v `tasks/README.md` — řádek `shipard_app` přibude, až repo
  vznikne (task 5).

## Osnova `docs/native-bridge.md`

Hlavička po vzoru `docs/mail/api-contract.md`:

```
# Nativní aplikace — kontrakt mostu a zařízení

**Status:** návrh — verze 1, neimplementováno (#177)
**Verze:** 1 (<datum>) — viz Historie změn
**Implementace:** web `frontend/src/native/` (task 2); server `core.devices`,
`core.prints` (tasky 3, 4, 6); aplikace `shipard-app:apple/` (task 5)
```

### 1. Přehled

- Vrstvy a odpovědnosti (tabulka): webová aplikace (UI, kasa, REST),
  most `window.shipardNative`, nativní aplikace (shell, profily, zámek,
  PIN, periferie), server (`core.devices`, `core.prints`, auth).
- Princip: logika na serveru, aplikace přenáší; jen online (D1).
- Režimy zařízení (D13): `unregistered` → registrace → `personal` nebo
  `shared`; zneplatnění adminem vrací zařízení do `unregistered`.
- Co je ve v1 a co ne (D11, D12) — jedna krátká tabulka.

### 2. Most

- **Vložení** (D3): skript vložený na začátku dokumentu, jen v hlavním
  rámci a jen pro povolené originy — domény hostingu (`hosting.baseDomain`,
  D9). Vývojová sestavení aplikace dovolí přidat origin ručně, včetně `http`
  na privátní adrese (vývojový server). Mimo povolené originy
  `window.shipardNative` **neexistuje**; webová aplikace most zjišťuje
  přítomností objektu.
- **Volání**: `shipardNative.call(method, params?)` → `Promise`. Parametry
  i výsledek jsou JSON objekty. Původ se kontroluje u každého volání.
- **Chyby** (D21): reject s `{code, message, details?}`, `code` velkými
  písmeny jako v REST. Obecné kódy (tabulka s významem): `UNKNOWN_METHOD`,
  `INVALID_PARAMS`, `NOT_SUPPORTED`, `NOT_REGISTERED`, `DEVICE_REVOKED`,
  `NETWORK_ERROR`, `SERVER_ERROR`, `CANCELLED`, `INTERNAL_ERROR`; kódy
  jednotlivých metod u metod.
- **Verze** (D21): `apiVersion` = 1. Přidání metody, pole nebo capability
  verzi nemění; neznámá pole se ignorují. Odebrání nebo změna významu =
  nová verze. Webová aplikace se řídí `capabilities`, ne platformou ani
  verzí aplikace — aplikace z App Store může být starší než web.
- **Capabilities** (U3) — tabulka capability → metody a události:

  | Capability | Metody a události |
  |---|---|
  | — (vždy) | `info` |
  | `device` | `device.register`, `device.sync`, `device.enroll` |
  | `peripherals` | `peripherals.list`, událost `shipard:peripheral` |
  | `print.escpos` | `print.submit` s formátem `escpos` |
  | `print.pdf` | `print.submit` s formátem `pdf` |
  | `scanner` | událost `shipard:scan` |
  | `profiles` | `session.lock`, `session.take`, událost `shipard:session` |
  | `auth.external` | `auth.external` |

- **Události**: `CustomEvent` na `window`, název `shipard:<událost>`,
  `detail` JSON. Doručují se jen do aktivního okna aktivního profilu;
  nativní část je nefrontuje — kdo neposlouchá, nedostane. Kterou obrazovku
  událost čtečky dostane, rozhoduje frontend (D6).

### 3. Metody (v1)

U každé metody: capability, parametry, výsledek, chybové kódy, kdo ji volá
a kdy. Tvary:

**`info`** → výsledek:

```json
{
  "apiVersion": 1,
  "app": { "version": "1.0.0", "build": "17" },
  "platform": "ipados",
  "osVersion": "18.2",
  "deviceId": "<uuid>",
  "keyStorage": "secureEnclave",
  "mode": "shared",
  "profile": { "id": "<uuid>", "userId": 12 },
  "capabilities": ["device", "peripherals", "print.escpos", "scanner", "profiles"]
}
```

`platform`: `macos` | `ipados`. `keyStorage`: `secureEnclave` | `keychain`
(U4). `mode`: `unregistered` | `personal` | `shared`. `profile` je `null`
mimo režim `shared`; `userId` je `null`, dokud uživatel profilu není
zařazený. `deviceId` vzniká při prvním spuštění; po zneplatnění zařízení
aplikace zahodí `deviceId` i klíč a začíná znovu.

**`device.register`** — parametry `{ "challenge": "<výzva>" }`, výsledek:

```json
{ "deviceId": "<uuid>", "algorithm": "ES256", "publicKey": "<base64 SPKI DER>", "signature": "<base64 DER>" }
```

Podpis s účelem `register` (U4). Volá webová aplikace v průběhu registrace
(§7.3).

**`device.sync`** (U1, D32) — bez parametrů; aplikace zavolá
`GET /_devices/self`, pošle aktuální periferie podepsaným
`PUT /_devices/self/peripherals` a vrátí aktualizované `info`. Sync spouští
aplikace sama i při startu a při změně periferií. Chyby `NOT_REGISTERED`,
`DEVICE_REVOKED`, `NETWORK_ERROR`.

**`device.enroll`** — parametry
`{ "ticket": "<lístek>", "user": { "id": 12, "name": "…" }, "pinSet": true }`.
Aplikace otevře nativní dialog: `pinSet: false` → nastavení PINu (dvakrát),
`true` → ověření stávajícího PINu (D19). Výsledek `{ "status": "enrolled" }`,
chyby `CANCELLED`, `PIN_POLICY`, `PIN_INVALID`, `TICKET_INVALID`. PIN se
zadává jen v nativním UI a do JS se nikdy nevrací (D27).

**`peripherals.list`** → `{ "peripherals": [ … ] }`, periferie:

```json
{ "id": "p-1a2b", "kind": "printer", "name": "Účtenky – pult", "transport": "tcp", "status": "ready", "profileId": "escpos-80-cp852" }
```

`kind`: `printer` | `scanner`; čtečka má místo `profileId` pole
`reads: ["barcode"]` nebo `["rfid"]`. `transport`: `tcp` | `ble` | `mfi` |
`usb` | `serial`. `status`: `ready` | `offline` | `error`. Periferie se
nastavují v aplikaci (D23); ID periferie je stabilní v rámci zařízení.

**`print.submit`** — parametry:

```json
{ "jobId": "<uuid>", "peripheralId": "p-1a2b", "format": "escpos", "data": "<base64>", "copies": 1, "reprint": false }
```

Výsledek `{ "status": "sent" }` = data předaná tiskárně, **ne** vytištěno
(D25). Chyby: `PERIPHERAL_NOT_FOUND`, `PERIPHERAL_UNAVAILABLE`,
`UNSUPPORTED_FORMAT`, `DUPLICATE_JOB` (stejné `jobId` bez `reprint: true`),
`PAYLOAD_TOO_LARGE`, `PRINTER_ERROR` (`details` může nést `paperOut`,
`coverOpen`, když je tiskárna hlásí), `TIMEOUT`. Limity U8.

**`session.lock`** — bez parametrů; zamkne aktivní profil (D17). Mimo režim
`shared` `NOT_SUPPORTED`.

**`session.take`** (U2) — bez parametrů; vrátí session, kterou aplikace
získala odemčením PINem a webová aplikace si ji ještě nevzala, jinak `null`:

```json
{ "session": { "token": "shpd_st_…", "expires_at": "…", "user": { … } } }
```

Tvar `session` je stejný jako `data` odpovědi `POST /_auth/login`. Session
se vydá jednou.

**`auth.external`** — parametry `{ "url": "<https URL na povoleném originu>", "callbackScheme": "shipard" }`,
výsledek `{ "callbackUrl": "shipard://…" }`, chyby `CANCELLED`,
`INVALID_PARAMS`. Otevře přihlášení v systémovém prohlížeči
(`ASWebAuthenticationSession`). Tvar volání je pevný; průběh na serveru
(návratové schéma, `return_to`, předání handoff kódu) doplní task 7.

### 4. Události (v1)

- **`shipard:scan`** — `{ "peripheralId", "kind": "barcode" | "rfid", "data": "…", "symbology": "ean13" | null, "at": "<ISO 8601>" }`
- **`shipard:peripheral`** — `{ "peripheralId", "status" }`
- **`shipard:session`** — `{}`; zvonek, token si webová aplikace vezme
  přes `session.take` (U2)

### 5. Tisková úloha

- Průběh lokální cesty (D25) jako číslovaný diagram:
  1. SPA `POST /_print-jobs {printId, recordId, deviceId, role}` — server
     najde periferii podle role na záznamu zařízení, složí data podle
     profilu, úlohu uloží → `{jobId, peripheralId, format, data}`;
  2. SPA `print.submit`;
  3. SPA `POST /_print-jobs/{jobId}/result {status, errorCode}`.
- Stavy na serveru: `created` → `sent` | `failed`. Úloha bez výsledku je
  pro uživatele „neověřeno“; znovu ji pošle jen vědomou akcí (`reprint`).
- Ochrana proti dvojímu tisku na obou stranách: aplikace si pamatuje
  posledních 100 `jobId`, server stav.
- Otevření šuplíku je také úloha (bez papíru).
- Role ve v1: `receipt`. Formáty: `escpos`, `pdf` (U3).
- Podrobný popis endpointů, úklidu a skládání ESC/POS doplní task 4 do
  `docs/prints.md`; tady jen tvar, který prochází mostem.

### 6. Periferie a profily tiskáren

- Popis periferie viz `peripherals.list`; serveru ho hlásí nativní část
  sama podepsaným `PUT /_devices/self/peripherals` v rámci `device.sync`
  (D32 — webová aplikace periferie nehlásí; hub bez okna žádnou nemá).
- Role periferie (`receipt`) se přiřazuje v Shipardu na záznamu zařízení.
- Katalog profilů je na serveru (U5): `id`, `name`, `columns` (znaků na
  řádek), `dotsPerLine`, `codePage`, `features` (`cut`, `drawer`, `qr`),
  `models` (ověřené modely). Aplikace ho čte
  `GET /_devices/printer-profiles` a nabízí v nastavení periferie; ukládá
  jen `profileId`. Neznámé `profileId` → server použije obecný profil
  a úlohu označí varováním.

### 7. Zařízení ↔ server

**7.1 Klíč a podpis** (D27, U4) — algoritmus, formáty, podepisovaný
řetězec, platnost výzvy, hlavičky podepsaných volání, kde je klíč uložený.

**7.2 Endpointy** — tabulka (cesta, ověření, tělo, odpověď, chyby):

| Endpoint | Ověření | Účel |
|---|---|---|
| `POST /_devices/challenge` `{deviceId, purpose}` → `{challenge, expiresIn}` | veřejné, omezení četnosti | výzva pro podpis; účely `register`, `self`, `peripherals`, `printer-profiles`, `enroll`, `pin-login`, `lock` |
| `POST /_devices/register` | session, admin (U6) | registrace: `{deviceId, algorithm, publicKey, challenge, signature, name, mode, platform, appVersion}` |
| `GET /_devices/self` | podpis | `{mode, name, state, users: [{id, name}], pinPolicy, autoLockMinutes}` — bez pokladny (U16: patří `economy.codebooks`, kasa ji čte ze záznamu zařízení) |
| `GET /_devices/printer-profiles` | podpis | katalog profilů (U5) |
| `PUT /_devices/self/peripherals` `{peripherals: [...]}` | podpis | hlášení periferií (D32) |
| `POST /_devices/enroll-ticket` `{deviceId}` → `{ticket, expiresIn, pinSet}` | session, zařízení `shared` | jednorázový lístek zařazení (D27), platí 5 min |
| `POST /_devices/enroll` `{ticket, pin}` | podpis | zařazení a nastavení / ověření PINu |
| `POST /_devices/pin-login` `{userId, pin}` → `{token, expires_at, user}` | podpis | odemčení (D16) |
| `POST /_devices/lock` `{userId}` → `{invalidated}` | podpis | zamčení (D17, D28) |

Chybové kódy: `DEVICE_UNKNOWN`, `DEVICE_REVOKED`, `DEVICE_EXISTS`,
`CHALLENGE_INVALID`, `SIGNATURE_INVALID`, `TICKET_INVALID`, `PIN_DISABLED`,
`PIN_POLICY`, `PIN_INVALID` (`details.attemptsLeft`), `PIN_LOCKED`,
`USER_NOT_ENROLLED`. Obálka odpovědí podle `docs/rest-api.md` §4.

**7.3 Registrace** — průběh: admin v Shipardu zvolí „Registrovat toto
zařízení“ (název, režim) → SPA `POST /_devices/challenge` (účel
`register`) → `device.register` → SPA `POST /_devices/register` →
`device.sync`.

### 8. Profily, zařazení, PIN, zamčení

- Profil = uživatel na zařízení, vlastní úložiště WebView (D14). Aktivní
  je jeden profil, uspané se při návratu načtou znovu → `session.take`.
- **Zařazení** (D15, D19, D20, D27): plné přihlášení v novém profilu → SPA
  zjistí režim `shared` a nezařazeného uživatele → `enroll-ticket` →
  `device.enroll` → nativní dialog PINu → `POST /_devices/enroll`.
- **Odemčení** (D16, D28): zamčená obrazovka (uživatelé ze
  `GET /_devices/self`) → PIN → výzva → `pin-login` → aplikace session
  podrží pro profil → `shipard:session` → SPA `session.take`.
- **Zamčení** (D17): `session.lock` z webové aplikace, tlačítko
  v aplikaci, nebo nečinnost (`autoLockMinutes` ze záznamu zařízení) →
  `POST /_devices/lock` → server zneplatní session uživatele s daným
  `device_id`. WebView profilu zůstane pod nativním zámkem.
- **Session** (D18, D28) nese `auth_method` (`local`, `oidc`, `pin`,
  `emergency`) a `device_id`; vynucení plného ověření u citlivých akcí
  později.
- **Politika PINu** (D31, U7): klíč `auth.pin` v `main.json`, výchozí
  hodnoty, po `maxAttempts` chybách na zařízení `PIN_LOCKED` — dál jen
  plné přihlášení.

### 9. Bezpečnost

Krátká tabulka hrozba → ochrana: XSS v Shipardu (úzký most — žádné
soubory, síť jen k periferiím nastaveným v aplikaci; PIN nikdy v JS),
vyzrazený PIN (platí jen na registrovaném zařízení a pro zařazené
uživatele), zařízení bez PINu (zamčení zneplatnilo session), odcizené
zařízení (zneplatnění adminem), přehrání požadavku (jednorázová výzva
vázaná na účel), cizí stránka v aplikaci (most jen pro povolené originy),
doplňky prohlížeče (v aplikaci nejsou).

### 10. Stav implementace

Tabulka část → task → stav (vše „neimplementováno“): mock mostu a
`/_dev/` testovací stránka (task 2), `core.devices` a registrace (3),
tiskové úlohy a ESC/POS (4), aplikace (5), profily a PIN (6),
`auth.external` (7), vydávání (8).

### 11. Historie změn

- **1** (<datum>) — první verze: #177 D21–D32, upřesnění U1–U10, U16.

## `docs/services.md` (D30)

- Úvod a kap. 1: „tři kategorie“ → „čtyři kategorie“; do tabulky
  kategorií řádek **Klientská aplikace** — aplikace pro koncové uživatele,
  instaluje se z obchodu nebo přímým stažením, nemá CLI ani systemd; dnes
  `shipard-app` (#177).
- **Nová kapitola „11a. Klientská aplikace“** hned za kap. 11.
  **Nepřečíslovávat** stávající kapitoly — na §12 odkazuje #177 i jiné
  dokumenty. Obsah:
  - co platí a co ne (tabulka z #177 D30): `CLAUDE.md` se sekcí o vztahu
    k `shpd` — platí; `docs/README.md` + architektura + troubleshooting —
    platí, místo `operations.md` je `release.md` (podepisování, notarizace,
    Sparkle, App Store); kontrakt v `shpd` s verzí a historií (kap. 12) —
    platí; testy kontraktu proti falešnému serveru (kap. 9) — platí, navíc
    falešná tiskárna; tasky a čeština — platí; `bin/shpd-<name>`, povinné
    verby, systemd, `/opt`, `/etc` — neplatí; verze a git hash ve výpisu
    stavu — místo toho verze a build v okně O aplikaci a v `info` mostu,
  - struktura repa (D29): adresář pro každou platformu (`apple/`, později
    `android/`, `windows/`) s vlastním `CLAUDE.md`, společné `testing/`
    (falešná tiskárna, vzorové úlohy a bajty), `docs/`, `tasks/`,
    `scripts/tasks-index.py`, kořenový `CLAUDE.md` + `AGENTS.md`,
    `README.md` s tabulkou podpory metod mostu podle platforem, `LICENSE`,
  - verzování a vydávání: verze pro každou platformu zvlášť, tagy
    `<platforma>-v<verze>` (`apple-v1.2.0`), CI spouštěné podle cest,
    podpisové klíče pro každou platformu v GitHub environments, nikdy
    v repu,
  - krátký checklist souladu pro klientskou aplikaci.
- Kap. 14: řádek `shipard-app` | klientská aplikace | nevzniklo — vznikne
  podle standardu (#177, task 5).

## `docs/roadmap.md` a `docs/features.md` (U9, U10)

- Roadmap: řádek „Mobilní nativní aplikace“ z tabulky Vědomě odložené pryč.
  Za oddíl Doplňky nový oddíl **„Nativní aplikace“** se stejným pravidlem
  jako doplňky (mimo milníky, blokuje ji jen kategorie 1) a tabulkou:
  macOS a iPadOS — kontrakt mostu (task 1), frontend, zařízení, tiskové
  úlohy a ESC/POS, aplikace, profily a PIN (#177); Windows, Android — bez
  zadání. Do Historie revizí záznam s datem: nativní aplikace z Vědomě
  odložených do vlastního oddílu (#177).
- Features: z tabulky Vědomě mimo rozsah pryč řádek „Mobilní nativní
  aplikace“; řádek „Maloobchod / kasa, jídelna, rezervace“ →
  „Jídelna, rezervace“. Do oddílu Prostředí `- [ ] Nativní aplikace pro
  macOS a iPadOS — Shipard v okně aplikace, tisk na POS tiskárny, čtečky,
  sdílená pracoviště s PINem (#177)`. Do oddílu Pokladna `- [ ] Maloobchodní
  kasa — prodejní obrazovka, účtenky na POS tiskárnu, čtečky (bez návrhu)`.

## Rozcestníky

- `docs/README.md` — v oddílu Specifikace řádek pro `native-bridge.md`;
  u `services.md` popis „tři kategorie“ → čtyři včetně klientské aplikace.
- `CLAUDE.md` — v tabulce dokumentace řádek `docs/native-bridge.md`
  (kontrakt mostu nativní aplikace a zařízení: metody, události,
  capabilities, tisková úloha, podepsaná volání zařízení, profily a PIN;
  verze 1, #177); u `docs/services.md` doplnit klientskou aplikaci.
- `tasks/README.md` — nový oddíl **„Nativní aplikace (#177)“** za oddílem
  Tisky, s krátkým úvodem (WebView shell + device hub, repo `shipard-app`,
  kontrakt `docs/native-bridge.md`) a tabulkou tasků; zatím jediný řádek
  `native-bridge-contract.md` | 1 | Kontrakt mostu a zařízení verze 1,
  kategorie Klientská aplikace ve standardu komponent, roadmapa a přehled
  funkcí (#177 D21–D31, U1–U10).

## Commity

1. `docs(native): kontrakt mostu nativní aplikace verze 1 (#177 D21–D31)` —
   `docs/native-bridge.md`, `docs/README.md`, řádek v `CLAUDE.md`
2. `docs(services): kategorie Klientská aplikace (#177 D30)` —
   `docs/services.md`, popisy v `docs/README.md` a `CLAUDE.md`
3. `docs(roadmap): nativní aplikace a kasa do rozsahu (#177)` —
   `docs/roadmap.md`, `docs/features.md`
4. `tasks(native): oblast Nativní aplikace, task 1 hotový (#177)` —
   `tasks/README.md`, **Stav** tohoto tasku, `python3 scripts/tasks-index.py`

## Hotovo když

- [x] `docs/native-bridge.md` existuje se statusem, verzí 1 a historií
      změn; pokrývá všechny metody a události D22 + U1, U2, registr
      capabilities, chybové kódy, tiskovou úlohu, profily tiskáren,
      podepsaná volání a endpointy zařízení, profily / zařazení / PIN /
      zamčení, bezpečnost a stav implementace
- [x] tvary JSON v dokumentu odpovídají tomuto tasku; žádné pole bez popisu
- [x] `docs/services.md` má kategorii Klientská aplikace jako kap. 11a,
      stávající kapitoly nepřečíslované, řádek `shipard-app` v kap. 14
- [x] roadmapa a přehled funkcí podle U9 a U10, záznam v Historii revizí
- [x] `docs/README.md`, `CLAUDE.md` a `tasks/README.md` odkazují na nový
      dokument a novou oblast
- [x] `python3 scripts/tasks-index.py --check` projde
- [x] žádné názvy reálných zdrojů dat, firem ani osob (`scripts/check-sensitive.py`)

## Odchylky od osnovy

Odsouhlasené při plánování 2026-11-27:

- **`POST /_devices/challenge` podle U20**, ne „veřejné, omezení
  četnosti“: účel `register` jen se session administrátora, ostatní účely
  jen registrovanému aktivnímu zařízení, nejvýš 5 nevyužitých výzev
  (`RATE_LIMITED`), výzva 32 bajtů v base64url. U20 je schválené s taskem 3
  a kontrakt by se jinak hned přepisoval.
- **HTTP stavy chybových kódů zařízení** (§7.2) — osnova je neuváděla.
  Jsou to návrhy kontraktu, ne rozhodnutí z #177; task 3 a 6 je buď
  dodrží, nebo kontrakt změní s novým záznamem v Historii změn.
- **Doplněné hodnoty a tvary**, které osnova neurčila a „žádné pole bez
  popisu“ je žádá: `state` v `GET /_devices/self` (ve verzi 1 jen
  `active`), `symbology` (malými písmeny, otevřený seznam), odpovědi
  `POST /_devices/register` (`{deviceId, name, mode, state}`),
  `PUT /_devices/self/peripherals` (`{count}`) a `POST /_devices/enroll`
  (`{status: "enrolled"}`), `errorCode` výsledku tiskové úlohy, pravidlo
  pro chyby serveru na mostu (jmenovaný kód projde, ostatní
  `SERVER_ERROR` s `details`).
- **Označení rozhodnutí** v commitu 1 a v řádku `tasks/README.md` je
  D21–D32 a U16 (zadání uvádělo D21–D31), shodně s Historií změn.
- Hodnoty `mock` pro `platform` a `keyStorage` kontrakt neuvádí — doplní
  je task 2 v oddílu Simulace pro vývoj; kontrakt jen říká, že se obě
  strany vyrovnají s neznámou hodnotou výčtu (§2.4).

**Otevřené pro další tasky:** kontrakt neříká, jak se registrace chová
u zařízení v režimu `personal`, které pracuje s víc zdroji dat (D13 mluví
o výběru zdroje dat přes portál, `info.mode` je jedna hodnota). Patří do
návrhu tasku 5.
