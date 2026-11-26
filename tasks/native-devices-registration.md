# Nativní aplikace — task 3: modul `core.devices`, registrace zařízení a podepsaná volání (#177)

**Stav:** naplánováno — rozhodnutí #177 D5, D13, D23, D24, D27, D32, U1, U4, U6, U7 a upřesnění U16–U23 (schválená 2026-11-26), připraveno k implementaci po tasku 1

> Task pro Claude Code (6 commitů). Design: issue #177. **Navazuje na
> task 1** (kontrakt `docs/native-bridge.md`); s taskem 2 jde souběžně —
> simulace mostu z tasku 2 je první klient těchto endpointů. Tiskové
> úlohy a katalog profilů tiskáren jsou task 4, zařazení uživatelů, PIN
> a zamčení task 6.

## Před implementací přečti

- `docs/native-bridge.md` — §1, §6, §7 (zařízení ↔ server), §9
- `docs/modules.md` — struktura modulu, `settingsItems` (i `panel`),
  `commands`, `cronJobs`, `keepOnReset`, `extensions`
- `docs/table-definitions.md` — formát tabulek, §8 Extensions; nová
  `tableId` přes `shpd-server next-table-id`
- `docs/document-system.md`, `docs/doc-states.md` — Document třídy, stavy
- `docs/edit-forms.md` — JSONC formuláře, `"optional": true` (extension
  sloupce), podtabulky
- `docs/rest-api.md` §3–4, `docs/auth.md` (auth politika v `main.json`,
  jednorázové tokeny — vzor atomické spotřeby), `docs/ds-state.md`
  (`ReadOnlyPolicy` — fail-closed per routa)
- vzor ověření mimo session: `HostingApiKeyAuthenticator`,
  `HostingServerController`, výjimky v `AuthMiddleware::isExempt()`

## Kontext

Zařízení (nativní aplikace, později i hub bez okna) se v DS registruje:
administrátor v aplikaci zvolí „Registrovat toto zařízení“, aplikace
podepíše výzvu serveru klíčem zařízení a server uloží veřejný klíč (D5,
D27). Od té chvíle nativní část volá vybrané endpointy **bez session**,
jen s podpisem výzvy (U4). Tenhle task postaví modul, registraci,
podepsaná volání, hlášení periferií a správu zařízení v Nastavení.

## Rozhodnutí a upřesnění (potvrzená 2026-11-26)

- **D32** (mění D23) — Periferie hlásí serveru **nativní část
  sama**: `device.sync` po načtení stavu pošle podepsané
  `PUT /_devices/self/peripherals` (účel výzvy `peripherals`) a aplikace
  sync spustí i při změně periferií. Nahrazuje v D23 „přes webovou
  aplikaci“ a v kontraktu endpoint `PUT /_devices/{deviceId}/peripherals`
  se session. Důvod: endpoint jen se session by dovolil kterémukoli
  přihlášenému uživateli přepsat periferie cizího zařízení a hub bez okna
  (D11) webovou aplikaci nemá.
- **U16** — `GET /_devices/self` nevrací pokladnu. Číselník pokladen
  (`economy_codebooks_cash_desks`) patří modulu `economy.codebooks`
  a jádro na něm záviset nesmí. Pokladnu na zařízení přidá
  `economy.codebooks` jako extension sloupec `cash_desk`; formulář
  zařízení ho má jako prvek s `"optional": true`. Kasa ji později přečte
  ze záznamu zařízení; nativní aplikace ji nepotřebuje.
- **U17** — Modul `core.devices` je v `install.base`. Tabulky
  `core_devices_devices`, `core_devices_peripherals`,
  `core_devices_challenges`. Zařízení a periferie přežijí `ds-reset`
  (`keepOnReset`), výzvy ne.
- **U18** — Záznam zařízení vzniká **jen registrací** (viewer bez
  Přidat). Stavy aktivní a zneplatněné; zneplatnění je konečné — nová
  registrace = nový záznam s novým `deviceId`; záznam zůstává kvůli
  auditu. Editovat jde název, režim, automatické zamčení, pokladnu (U16)
  a role periferií.
- **U19** — Periferie se při hlášení párují podle `peripheral_id`.
  Chybějící se označí jako nepřítomné, nemažou se — přiřazená role
  přežije, když tiskárna chvíli chybí. Jedno zařízení má nejvýš jednu
  periferii s rolí `receipt`.
- **U20** — Výzvy: 32 náhodných bajtů (base64url), platnost 60 s,
  jednorázové (spotřeba atomickým `UPDATE … WHERE used_at IS NULL`
  rozhodnutým přes počet ovlivněných řádků — vzor `AuthTokenService::consume`),
  nejvýš 5 nevyužitých platných na zařízení. Účel `register` jen se
  session administrátora; ostatní účely jen pro registrované aktivní
  zařízení — jinak `DEVICE_UNKNOWN` / `DEVICE_REVOKED` a žádný nový řádek.
  Prošlé a použité výzvy uklízí úloha cronu modulu.
- **U21** — Politika PINu `auth.pin` (U7) se do auth politiky DS načítá
  už tady, protože ji vrací `GET /_devices/self`; použije ji task 6.
- **U22** — CLI `shpd-ds device-list` a `shpd-ds device-revoke <deviceId>`
  — zneplatnění bez UI (ztracené zařízení, nedostupná aplikace).
- **U23** — Záznam zařízení plní roli **pracoviště** ze starého Shipardu:
  jedno místo pro název, pokladnu (U16), role tiskáren, čtečky, zařazené
  uživatele (task 6) a automatické zamčení. Samostatný číselník pracovišť
  se nezavádí. Role periferií jsou rozšiřitelné — ve v1 `receipt`, později
  `a4` (s `print.pdf`) a štítky; kód role proto ber z cfgItem, ne
  z natvrdo psané podmínky. Výměna hardwaru přes akci „Registrovat jako
  náhradu za…“ (převezme nastavení a zařazené uživatele ze zneplatněného
  zařízení) je mimo v1 — model ji nesmí znemožnit. V UI zůstává název
  „Zařízení“ (sedí i na osobní Mac); v nápovědě a README modulu zmínit, že
  jde o obdobu pracoviště ze starého Shipardu.

## Rozsah

**V rozsahu:** modul `core.devices` (tabulky, Document třídy, viewer,
formulář, sekce Nastavení → Zařízení, panel Toto zařízení), extension
`cash_desk` z `economy.codebooks`, endpointy `POST /_devices/challenge`,
`POST /_devices/register`, `GET /_devices/self`,
`PUT /_devices/self/peripherals`, ověření podpisem, `auth.pin` v auth
politice, CLI, úloha cronu, testy, dokumentace.

**Mimo rozsah:** `GET /_devices/printer-profiles` a role při tisku
(task 4), `enroll-ticket`, `enroll`, `pin-login`, `lock`, zařazení
uživatelé a `device_id` na session (task 6), kasa.

## Datový model

**`core_devices_devices`**

| Sloupec | Typ | Poznámka |
|---|---|---|
| `device_id` | varchar(36) | UUID z aplikace, unikátní index |
| `name` | varchar | např. „Pokladna 2“ |
| `mode` | enum cfgItem | `personal` / `shared` (D13) |
| `platform` | varchar | `macos`, `ipados`, `mock` |
| `app_version` | varchar | z registrace, aktualizuje `self` |
| `public_key` | text | SPKI DER v base64 |
| `key_algorithm` | varchar | `ES256` |
| `key_storage` | varchar | `secureEnclave` / `keychain` / `mock` |
| `auto_lock_minutes` | smallint, null | null = bez automatického zamčení (D17) |
| `registered_by` | FK uživatel | administrátor, který registroval |
| `registered_at`, `last_seen_at` | datetime | `last_seen_at` při každém podepsaném volání |
| stav | docState | aktivní / zneplatněné (U18) |

Extension z `economy.codebooks`: `cash_desk` → `economy_codebooks_cash_desks`
(nullable, U16).

**`core_devices_peripherals`**

| Sloupec | Typ | Poznámka |
|---|---|---|
| `device` | FK zařízení | |
| `peripheral_id` | varchar | ID z aplikace, unikátní spolu s `device` |
| `kind` | varchar | `printer` / `scanner` |
| `name`, `transport`, `profile_id` | varchar | podle kontraktu §3 `peripherals.list` |
| `reads` | json, null | u čtečky |
| `status` | varchar | poslední hlášený stav |
| `present` | bool | false = v posledním hlášení chyběla (U19) |
| `role` | varchar, null | `receipt`; nejvýš jedna na zařízení (U19) |
| `reported_at` | datetime | |

**`core_devices_challenges`** — `challenge` (unikátní), `device_id`
(řetězec — u `register` zařízení ještě neexistuje), `purpose`,
`created_at`, `expires_at`, `used_at`. Bez Document třídy, jen služba.

## Server

**Třídy** v `modules/core/devices/src/` (`Shipard\Module\Core\Devices\`):

- `DeviceKey` — převod SPKI DER base64 → PEM, kontrola, že jde o EC P-256
  (`openssl_pkey_get_details`), ověření podpisu
  (`openssl_verify(..., OPENSSL_ALGO_SHA256) === 1`) nad řetězcem
  `shipard-device-v1|<účel>|<deviceId>|<výzva>` (U4). Jediné místo, kde se
  skládá podepisovaný řetězec.
- `ChallengeService` — `issue(deviceId, purpose)`, `consume(challenge,
  deviceId, purpose)` (U20), úklid.
- `DeviceRequestAuthenticator` — z hlaviček `X-Shipard-Device`,
  `X-Shipard-Challenge`, `X-Shipard-Signature` a účelu routy: zařízení
  existuje a je aktivní, výzva platí, podpis sedí → kontext zařízení;
  jinak chyby z kontraktu §7.2 (`DEVICE_UNKNOWN`, `DEVICE_REVOKED`,
  `CHALLENGE_INVALID`, `SIGNATURE_INVALID`), vždy 401 / 403 bez
  podrobností, které by pomohly hádat.
- `DeviceRegistrationService` — registrace (výzva `register` vydaná
  pro session administrátora, podpis nad dodaným klíčem, `DEVICE_EXISTS`
  pro aktivní `device_id`), zneplatnění.
- `PeripheralSync` — upsert podle `peripheral_id`, `present`, kontrola
  tvaru podle kontraktu (U19).
- `DeviceDocument`, `PeripheralDocument` — pravidla U18, U19 (role
  `receipt` nejvýš jednou, zneplatněné zařízení jen ke čtení).

**Endpointy** (`src/Api/Controller/DevicesController.php`, routy
v `Router`, dispatch v `public/index.php`):

| Endpoint | Ověření | Poznámka |
|---|---|---|
| `POST /_devices/challenge` `{deviceId, purpose}` | bez session (výjimka v `AuthMiddleware`), u `register` session administrátora | U20 |
| `POST /_devices/register` | session administrátora (U6) | kontrakt §7.2, odpověď 201 se záznamem zařízení |
| `GET /_devices/self` | podpis, účel `self` | `{mode, name, state, users: [], pinPolicy, autoLockMinutes}` — `users` naplní task 6 |
| `PUT /_devices/self/peripherals` | podpis, účel `peripherals` (D32) | `{peripherals: [...]}` |

`ReadOnlyPolicy`: `challenge` a `self` povolit (bez nich se zařízení
v režimu jen pro čtení neověří), `register` a `self/peripherals`
zakázat. Obálka odpovědí a chybové kódy podle `docs/rest-api.md` §4.
Pokud repo vyžaduje popis rout v OpenAPI (test pokrytí), doplnit.

**Auth politika** — `auth.pin` (`enabled`, `minLength`, `maxLength`,
`maxAttempts`, výchozí `false / 4 / 8 / 5`) do načítání auth politiky
z `main.json`: validace rozsahů (min ≥ 4, max ≤ 8, min ≤ max, pokusy ≥ 1),
neplatná hodnota = výchozí + nález v `shpd-server doctor` (vzor ostatních
klíčů politiky).

**CLI** (`commands` modulu, U22): `device-list [--json]` (název, režim,
platforma, stav, poslední kontakt), `device-revoke <deviceId>` (potvrzení,
`--yes` pro skripty). **Cron** (`cronJobs`): úklid výzev ve slotu
`five-minutes`.

## Frontend

- **Nastavení → Zařízení** (nová sekce v `settingsSections` z
  `install.base`): panel **Toto zařízení** (`settingsItems` s `panel`)
  a viewer Zařízení. Ikonu doplnit do `icons.js`.
- **Panel Toto zařízení** (`components/devices/ThisDevicePanel.svelte`,
  most přes `native/bridge.js` z tasku 2):
  - bez mostu: vysvětlení, že registrovat jde jen z nativní aplikace
    (nebo ze simulace na vývojovém serveru);
  - most, režim `unregistered`, uživatel administrátor: formulář
    (název, režim) a **Registrovat** → `POST /_devices/challenge`
    (účel `register`) → `device.register` → `POST /_devices/register` →
    `device.sync` → obnovit panel; chyby z mostu i serveru srozumitelně;
  - most, registrované zařízení: název, režim, platforma, periferie
    s rolemi (odkaz na záznam zařízení), **Synchronizovat**
    (`device.sync`);
  - most, ale zařízení na serveru zneplatněné: hlášení a návod
    (aplikace se po `DEVICE_REVOKED` vrátí do `unregistered`).
- **Viewer a formulář zařízení**: řádek s názvem, režimem, platformou,
  posledním kontaktem a štítkem „toto zařízení“ (porovnání s `deviceId`
  z mostu); formulář — název, režim, automatické zamčení, pokladna
  (`optional`), podtabulka periferií jen ke čtení kromě sloupce Role;
  akce záznamu **Zneplatnit** s potvrzením (body rozšíření akcí záznamu,
  #172). Bez tlačítka Přidat.

## Testy

- `DeviceKeyTest` — klíč a podpis vygenerované OpenSSL v testu; jiný
  účel / zařízení / výzva → neplatné; klíč jiné křivky odmítnut.
- **Interoperabilita**: fixture `tests/fixtures/devices/webcrypto-es256.json`
  (veřejný klíč SPKI, řetězec, podpis DER) vyrobená jednou WebCrypto
  v Node stejnou funkcí jako simulace z tasku 2 (skript vedle fixture);
  PHP ji ověří. Až bude aplikace, přibude fixture z CryptoKitu (task 5).
- `ChallengeServiceTest` — jednorázovost (souběžná spotřeba vyhraje
  jedna), expirace, nesoulad účelu a zařízení, limit 5, úklid.
- `DeviceRequestAuthenticatorTest` — chybějící hlavičky, neznámé
  a zneplatněné zařízení, `last_seen_at`.
- Registrace a sync periferií — integrační testy proti dev DS
  (`SHIPARD_INTEGRATION_DS_PATH`): registrace → self → sync → druhý sync
  bez tiskárny (`present = false`, role zůstává) → zneplatnění → self
  `DEVICE_REVOKED`.
- Auth politika `auth.pin` — výchozí, platné a neplatné hodnoty.
- Frontend: čisté funkce panelu (stav podle mostu a odpovědí) v
  `frontend/tests/Unit/devices/`.

## Dokumentace

- `docs/native-bridge.md` — §6 a §7 podle D32 a U16 (pokud je task 1
  napsal ještě po staru), Stav implementace: task 3; Historie změn.
- nový `modules/core/devices/README.md` podle `docs/documentation.md` §2
  (tabulky, třídy, CLI, cron) a řádek v rozcestníku modulů.
- `docs/auth.md` — klíč `auth.pin` v auth politice (význam pro PIN
  doplní task 6).
- `docs/cli.md` — `device-list`, `device-revoke`.
- `tasks/README.md` — řádek tasku 3.

## Commity

1. `feat(devices): modul core.devices — tabulky, dokumenty, auth.pin v politice (#177 D24, U17–U19, U21)`
2. `feat(devices): výzvy a ověření podpisem zařízení (#177 D27, U4, U20)` — `DeviceKey`, `ChallengeService`, `DeviceRequestAuthenticator`, fixture interoperability
3. `feat(devices): registrace, self a hlášení periferií (#177 D5, D32)` — endpointy, `ReadOnlyPolicy`, integrační testy
4. `feat(devices): Nastavení → Zařízení, panel Toto zařízení (#177 D13, U18)` — viewer, formulář, extension `cash_desk`, akce Zneplatnit, panel
5. `feat(devices): CLI device-list, device-revoke, úklid výzev (#177 U20, U22)`
6. `docs(devices): zařízení a registrace, task 3 hotový (#177)` — dokumentace, **Stav**, `python3 scripts/tasks-index.py`

## Hotovo když

- [ ] `shpd-ds ds-upgrade` na dev DS založí tabulky; `cash_desk` jen tam,
      kde je `economy.codebooks`
- [ ] cílené PHPUnit (`--filter 'Device|Challenge|AuthPolicy'`) a
      integrační testy registrace projdou; fixture z WebCrypto ověří PHP
- [ ] na dev serveru se simulací z tasku 2: Nastavení → Zařízení → Toto
      zařízení → Registrovat → záznam zařízení existuje, panel ukazuje
      registrované zařízení, Synchronizovat nahlásí periferie simulace,
      role `receipt` jde přiřadit jen jedné tiskárně
- [ ] po Zneplatnit (UI i `device-revoke`) skončí `device.sync`
      chybou `DEVICE_REVOKED`
- [ ] podepsané volání se starou, cizí nebo použitou výzvou je odmítnuté
- [ ] v režimu jen pro čtení `self` funguje, `register` a hlášení
      periferií vrací `DS_READ_ONLY`
- [ ] dokumentace a `tasks/README.md` aktualizované
