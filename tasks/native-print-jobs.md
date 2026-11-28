# Nativní aplikace — task 4b: tiskové úlohy, směrování podle role, tisk z UI (#177)

**Stav:** naplánováno — rozhodnutí #177 D4, D11, D25, D33, U8, U19 a upřesnění U36–U43 (schválená 2026-11-28), připraveno k implementaci

> Task pro Claude Code (6 commitů). Design: issue #177. **Navazuje na
> task 4a** (`tasks/native-escpos-render.md` — `EscPosRenderer`, profily,
> `receiptPos`, zkušební účtenka) a na tasky 2 a 3 (most a simulace,
> `core.devices`). Tady vzniká lokální cesta tisku z kontraktu §5:
> SPA → tisková úloha na serveru → most → tiskárna → výsledek zpět.

## Před implementací přečti

- `docs/native-bridge.md` §3.6 (`print.submit`), §5 (tisková úloha), §6, §9a
- `docs/prints.md` — kapitola o tisku na účtenkovou tiskárnu (task 4a),
  §6 REST, §7 UI (akce tisku v detailu)
- `modules/core/devices/README.md` — zařízení, periferie, role, D33
- `frontend/src/native/` (obálka mostu, simulace), `components/devices/`
  (panel Toto zařízení), `components/viewer/Viewer.svelte`
  (`handleDetailAction`), `stores/notice.svelte.js` (toast)
- `docs/ds-state.md` — `ReadOnlyPolicy`

## Kontext

Tisková úloha je záznam na serveru pro obě cesty doručení (D4, D25); ve
v1 ji doručuje webová aplikace přes most (D11). Server najde podle role
periferii zařízení, složí data podle jejího profilu (task 4a) a úlohu
uloží; webová aplikace data předá mostu a výsledek nahlásí. Ochrana proti
dvojímu tisku je na obou stranách: aplikace odmítne stejné `jobId` bez
`reprint`, server drží stav. Úloha bez výsledku je „neověřeno“.

## Upřesnění (schválená 2026-11-28)

- **U36** — Druhy úloh: `print` (tisk záznamu, `printId` + `recordId`),
  `test` (zkušební účtenka profilu, task 4a) a `drawer` (otevření šuplíku).
  `POST /_print-jobs` dostane navíc pole `kind` (výchozí `print`, přidání
  pole verzi kontraktu nemění); `role` je nepovinná, výchozí `receipt`.
  `GET /_print-jobs/{jobId}` vrátí data úlohy znovu pro Zkusit znovu.
- **U37** — Směrování: zařízení podle `deviceId` **v tomto zdroji dat**
  (D33), aktivní — jinak `DEVICE_UNKNOWN` / `DEVICE_REVOKED`. Periferie
  zařízení s rolí, druhu tiskárna a přítomná: žádná s rolí =
  `NO_PERIPHERAL_FOR_ROLE`, jen nepřítomná = `PERIPHERAL_NOT_PRESENT`,
  víc přítomných = `ROLE_AMBIGUOUS` (generický CRUD jedinečnost role
  obejde, task 3). Šuplík u profilu bez funkce `drawer` =
  `PROFILE_NO_DRAWER`. Profil podle `profile_id` periferie; neznámý →
  záložní s varováním v `warnings` (task 4a).
- **U38** — Práva: úlohu `print` vytvoří, kdo smí číst tabulku tisku
  (`TableAccessGuard` jako `/_prints`); `test` a `drawer` kterýkoli
  přihlášený uživatel (kdo na zařízení vystavuje prodejky, musí umět
  otevřít šuplík; zvláštní právo se nezavádí). Znovu načíst úlohu a zapsat výsledek smí jen její
  autor nebo administrátor. Režim jen pro čtení: `GET` povolen, oba `POST`
  zakázané.
- **U39** — Data úlohy se ukládají spolu s úlohou (bajty, ne base64);
  úlohy starší než 30 dní maže úloha cronu jednou denně.
- **U40** — Tisky s `output: escpos` mají v detailu záznamu vlastní akci
  `printPos` („Tisk na účtenkovou tiskárnu“; jeden tisk = tlačítko, víc =
  nabídka jako u `print`). Server ji posílá vždy; frontend ji **ukáže jen**,
  když most nabízí `print.escpos` a zařízení v tomto zdroji dat není
  `unregistered`. Úspěch = toast. Neúspěch = dialog s důvodem; u chyb mostu
  a tiskárny (`PERIPHERAL_UNAVAILABLE`, `PRINTER_ERROR`, `TIMEOUT`,
  `NETWORK_ERROR`) s tlačítkem **Zkusit znovu** (`reprint`), u chyb
  nastavení (`NO_PERIPHERAL_FOR_ROLE`, `ROLE_AMBIGUOUS`,
  `PERIPHERAL_NOT_PRESENT`, …) s textem, co nastavit, bez opakování.
- **U41** — Panel Toto zařízení dostane **Zkušební tisk** a **Otevřít
  šuplík** (jen registrované zařízení s capability `print.escpos`), se
  stejným zpracováním výsledku jako U40.
- **U42** — Viewer **Tiskové úlohy** v Nastavení → Zařízení, jen ke čtení,
  pro administrátora: čas, zařízení, druh, tisk a záznam, stav (neověřeno /
  odesláno / chyba), kód chyby, pokusy, uživatel. Zkusit znovu z vieweru
  ve v1 není — funguje jen na zařízení, které úlohu tisklo.
- **U43** — Simulace: druhá tiskárna `mock-receipt-58` (`escpos-58-cp852`),
  takže jde prokliknout i jedinečnost role; stav periferie `error` vrací
  `PRINTER_ERROR` s `details.paperOut: true`. Po registraci si simulace
  stáhne katalog profilů podepsaným `GET /_devices/printer-profiles`
  a náhled bere šířky a kódovou stránku z něj (vestavěný profil jen do
  registrace).

## Rozsah

**V rozsahu:** tabulka tiskových úloh a služba, tři endpointy, cron,
viewer Tiskové úlohy, akce `printPos` v detailu, orchestrace ve frontendu,
dialog chyby tisku, tlačítka v panelu Toto zařízení, rozšíření simulace,
testy, dokumentace.

**Mimo rozsah:** vyzvedávání úloh hubem a kanál server → zařízení (D11,
D12), formát `pdf` a role `a4`, kasa (prodejní obrazovka), tisk po
dokončení prodeje automaticky.

## Datový model

**`core_prints_jobs`** (modul `core.prints`, nová závislost na
`core.devices`; tableId přes `next-table-id`)

| Sloupec | Typ | Poznámka |
|---|---|---|
| `job_id` | varchar(36), `utf8mb4_bin` | UUID, unikátní |
| `kind` | varchar | `print` / `test` / `drawer` |
| `print_id` | varchar, null | jen `print` |
| `record_table`, `record_id` | null | jen `print` |
| `device` | FK `core_devices_devices` | |
| `peripheral` | FK `core_devices_peripherals` | periferie, na kterou úloha jde |
| `role` | varchar | `receipt` |
| `format` | varchar | `escpos` |
| `profile_id` | varchar | profil, podle kterého se skládalo (i záložní) |
| `data` | mediumblob | bajty úlohy (U39) |
| `data_size` | int | |
| `warnings` | json, null | varování renderu a profilu |
| `state` | varchar | `created` / `sent` / `failed` |
| `error_code` | varchar(64), null | kód z `print.submit` |
| `attempts` | smallint | počet nahlášených výsledků |
| `created_by` | FK uživatel | |
| `created_at`, `result_at` | datetime | |

Jako `core_devices_challenges`: `hideFromNavigation`, `adminOnly`,
`systemManaged`, bez Document třídy — záznamy píše jen `PrintJobService`.
Tabulka není v `keepOnReset` (úlohy odkazují na záznamy, `ds-reset` je
zahodí).

## Server

`modules/core/prints/src/Jobs/` (`Shipard\Module\Core\Prints\Jobs\`):

- `PrintJobRouter` — zařízení + role → periferie a profil (U37).
- `PrintJobService` — `create(kind, ?printId, ?recordId, deviceId, role,
  AuthContext)`: router, podle druhu `PrintRunner::run(..., Escpos, null,
  profile)`, `PrinterTestReceipt` nebo příkaz šuplíku ovladače, limit 5 MB
  (U8, `PAYLOAD_TOO_LARGE`), uložení; `get(jobId, auth)`; `report(jobId,
  status, errorCode, auth)` — `status` jen `sent` / `failed`, `errorCode`
  u `failed` povinný (velká písmena, ≤ 64 znaků), `attempts++`, stav podle
  posledního výsledku; `prune()`.
- Chyby tisku mapovat stejně jako `PrintsController` (`PRINT_NOT_FOUND`,
  `PRINT_NOT_AVAILABLE`, `PRINT_DATA_MISSING`, …), tisk bez `output:
  escpos` = `PRINT_FORMAT_UNSUPPORTED`.

**Endpointy** (`src/Api/Controller/PrintJobsController.php`, routy,
dispatch, `ReadOnlyPolicy` podle U38):

| Endpoint | Tělo | Odpověď |
|---|---|---|
| `POST /_print-jobs` | `{kind?, printId?, recordId?, deviceId, role?}` | 201 `{jobId, peripheralId, format, data, warnings}` — `peripheralId` je ID periferie v aplikaci, `data` base64 |
| `GET /_print-jobs/{jobId}` | — | `{jobId, kind, state, peripheralId, format, data, attempts, warnings}` |
| `POST /_print-jobs/{jobId}/result` | `{status, errorCode?}` | `{state, attempts}` |

Chybové kódy navíc: `NO_PERIPHERAL_FOR_ROLE`, `PERIPHERAL_NOT_PRESENT`,
`ROLE_AMBIGUOUS`, `PROFILE_NO_DRAWER`, `PRINT_FORMAT_UNSUPPORTED`,
`JOB_NOT_FOUND`, `PAYLOAD_TOO_LARGE`; HTTP stavy podle `docs/rest-api.md` §4
(nastavení = 409 nebo 422, cizí úloha = 403).

**Detail záznamu**: `ViewerController::detail()` přidá akci `printPos`
z tisků s `output: escpos` (tvar jako `print`, popisek v
`core.system.viewerDefaults.detailActions.printPos`).

**Cron** (`cronJobs` modulu `core.prints`): `print-jobs-prune`, slot
`daily`. **Viewer** `core.prints.jobs` v sekci `devices`, jen pro
administrátora (U42).

## Frontend

- **`native/printJobs.js`** — `submitPrintJob({kind, printId, recordId,
  role})`: `nativeInfo()` → `POST /_print-jobs` s `deviceId` → most
  `print.submit` → `POST …/result`; `retryPrintJob(jobId)`: `GET` → most
  s `reprint: true` → výsledek. Výsledek `{ok, jobId, stage: 'server' |
  'bridge' | 'report', code, message, retryable}` (`retryable` podle U40).
  Když selže až zápis výsledku, úloha zůstane neověřená a hláška to řekne
  — tisk se kvůli tomu neopakuje.
- **`Viewer.svelte`** — akce `printPos`: ukázat jen podle U40, po kliku
  `submitPrintJob`; úspěch `noticeStore.show(…, {level: 'success'})`,
  neúspěch `components/native/PrintJobErrorDialog.svelte` (důvod podle
  kódu, Zkusit znovu jen u `retryable`).
- **Panel Toto zařízení** — tlačítka podle U41.
- **Simulace** podle U43 (`mock/state.js`, `install.js`, panel — výběr
  stavu `error`).
- Texty `native.print.*` (cs + en): úspěch, každý kód chyby srozumitelně
  (např. `NO_PERIPHERAL_FOR_ROLE` → „Zařízení nemá tiskárnu účtenek.
  Přiřaďte roli Účtenky v Nastavení → Zařízení.“).

## Testy

- `PrintJobRouterTest` — všechny větve U37 (žádná, nepřítomná, dvě,
  čtečka s rolí, zneplatněné zařízení, zařízení z jiného zdroje dat
  neexistuje, záložní profil).
- `PrintJobServiceTest` — tři druhy, limit, `report` (autor, admin, cizí
  403, neplatný stav a kód), `attempts`, `prune`.
- Integrační test proti dev DS (`SHIPARD_INTEGRATION_DS_PATH`, volný zdroj
  dat): zařízení + periferie s rolí → `test` a `drawer` → výsledek → `GET`;
  `print` nad prodejkou, pokud ji zdroj dat má (jinak přeskočit s důvodem).
- `ViewerController` — `printPos` jen z tisků `escpos`, `print` bez nich.
- Frontend: `printJobs.test.mjs` (falešný most a fetch: úspěch, chyba
  serveru, chyba mostu s `retryable`, selhání zápisu výsledku, Zkusit
  znovu s `reprint`), simulace (druhá tiskárna, `PRINTER_ERROR`, katalog
  ze serveru), viditelnost akce `printPos` (čistá funkce).

## Dokumentace

- `docs/prints.md` — kapitola **Tiskové úlohy**: model, směrování, druhy,
  REST, práva, úklid, akce `printPos`, viewer.
- `docs/native-bridge.md` — §5: pole `kind`, výchozí `role`, odkaz na
  `prints.md`; Stav implementace; Historie změn.
- `docs/frontend.md` — `printJobs.js`, dialog chyby tisku, akce `printPos`.
- `modules/core/devices/README.md` — vazba na tiskové úlohy.
- `tasks/README.md` — řádek tasku 4b.

## Commity

1. `feat(prints): tiskové úlohy — tabulka, směrování podle role, služba (#177 D25, U36, U37, U39)`
2. `feat(prints): REST /_print-jobs a úklid úloh (#177 U38, U39)`
3. `feat(prints): akce Tisk na účtenkovou tiskárnu v detailu, viewer Tiskové úlohy (#177 U40, U42)`
4. `feat(native): tisk přes most — úloha, výsledek, Zkusit znovu (#177 U40, U41)`
5. `feat(native): simulace — druhá tiskárna, chyba tiskárny, katalog profilů (#177 U43)`
6. `docs(prints): tiskové úlohy, task 4b hotový (#177)` — dokumentace, **Stav**, `python3 scripts/tasks-index.py`

## Hotovo když

- [ ] cílené PHPUnit (`--filter 'PrintJob|ViewerController'`), integrační
      test úloh na volném zdroji dat a `npm test` projdou
- [ ] na dev serveru přes https se simulací: prodejka → Tisk na účtenkovou
      tiskárnu → náhled účtenky v simulaci, úloha ve vieweru „odesláno“;
      bez role → dialog s návodem; tiskárna `error` → dialog a Zkusit
      znovu po přepnutí na `ready` vytiskne (stejné `jobId`, `attempts` 2)
- [ ] druhou tiskárnu simulace s rolí Účtenky formulář odmítne
- [ ] Zkušební tisk a Otevřít šuplík z panelu Toto zařízení
- [ ] v režimu jen pro čtení vrací `POST /_print-jobs` `DS_READ_ONLY`
- [ ] akce `printPos` v obyčejném prohlížeči bez mostu není vidět
- [ ] dokumentace aktualizovaná
