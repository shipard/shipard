# Akce záznamu s obsluhou v modulu (#172 D12–D15, D19)

**Stav:** naplánováno — zadání 2026-11-25 (#172 D12–D15, D19)

> Task pro Claude Code (5 commitů). Design: issue #172 (komentář
> „Body rozšíření v UI“). Sesterský task C1 `tasks/module-ui-extensions.md`
> (taby, záložka formuláře, štítky řádku) na tomto nezávisí. První
> odběratel je doplněk Evidence tržeb (#171): nahrání certifikátu
> (soubor + heslo), Ověřit spojení, Kontaktní platba na dokladu V pořádku
> (čas platby).

## Kontext

- **Akce detailu dnes:** `TableViewer::detailActions()` vrací popisy
  akcí, `Viewer.svelte::handleDetailAction` je obsluhuje podle asi 25
  pevných id (`snooze`, `reaccount`, `setPassword`, `workOrderIssueDue`,
  `createCorrectiveDoc`, …) a pak podle `kind` (`open_form`,
  `open_detail`, `open_viewer`, `open_report`). Každá akce s dialogem má
  vlastní komponentu (`SetPasswordPrompt`, `SendDialog`,
  `AssetsDepreciationRunDialog`) a vlastní endpoint v `src/Api/Controller`.
  Komentář u obsluhy sám počítá s přesunem dispatche na backend.
- **Tajemství:** generické API citlivé sloupce nepřijme ani nevrátí;
  vzor zápisu hesla je dedikovaný endpoint (`POST /_mail/senders/{id}/password`).
- **Upload:** `Request` parsuje jen JSON tělo; multipart čtou controllery
  přímo z `$_FILES` (`AppController` branding, `MultipartFiles`).
- **Read-only zdroj dat:** `ReadOnlyPolicy` je fail-closed per
  (controller, akce) — nový controller bez záznamu v tabulce je ve stavu
  `read_only` automaticky 403.
- **Texty:** katalogy textů modulu přes `CatalogText`
  (`*.viewerDetailLabels`, `*.formTexts`).
- **Frontend:** `Modal`, `Input`, `TextArea`, `NumberInput`, `DateInput`,
  `Checkbox`, `Select`, `Notice`, `Button` v `components/ui/`; toast
  `stores/notice.svelte.js` (`GlobalToast`); `datetime` se ve formulářích
  kreslí jako `Input type="datetime-local"` (`FormElement.svelte`);
  multipart vzor `api/app.js` (`uploadBranding`). Testy frontendu: `node
  --test tests/Unit/**/*.test.mjs` (vzor `detailActionResult.test.mjs`).

## Rozhodnutí (#172)

- **D12** — `recordActions: [{id, table, class}]`; třída: dostupnost pro
  záznam, popisek, varianta, potvrzení, nepovinný dialog, provedení.
  Builder detailu je přidá k akcím vieweru (`kind: "record_action"`).
  Jeden endpoint `POST /_ui/record-action/{table}/{id}/{akce}` (JSON nebo
  multipart); ve frontendu jedna obecná obsluha a jeden obecný dialog.
  Platí pro vlastní i cizí tabulky.
- **D13** — Pole dialogu: text, textarea, password, number, date,
  datetime, checkbox, select (pevná nabídka), file (jeden soubor, limit
  velikosti v deklaraci), notice. Heslo a soubor jdou jen do handleru —
  nelogují se, nevracejí; soubor jako dočasná cesta, uložení řeší handler.
- **D14** — Výsledek `{message, level}` a vždy obnovení detailu i seznamu;
  chyba validace → 422 s chybami polí v dialogu.
- **D15** — Přístup k tabulce jako u vieweru, `admin: true` jen pro
  správce; akce je mutace → ve zdroji dat jen pro čtení 403; každé
  provedení do logu (kdo, akce, záznam).
- **D19** — Jen aktivní moduly; výjimka v poskytovateli → log
  a přeskočení (detail se vykreslí bez akce); pořadí `order`, pak id
  modulu. Stávající akce ve `Viewer.svelte` se nepřevádějí, **nové akce
  už jen přes D12**.

## Před implementací přečti

- `docs/frontend.md` § Akce detailu, § Formát detail panelu
- `docs/modules.md` kap. 5 (vzor pole `{table, class}`), kap. 9 Doplňky
- `docs/rest-api.md` (konvence chyb, autentizace), `docs/ds-state.md`
  (read-only, `ReadOnlyPolicy`)
- `src/Core/Viewer/TableViewer.php` (`renderDetail`, `detailActions`),
  `src/Core/Viewer/ViewerRegistry.php`, `src/Api/ViewerLoader.php`,
  `src/Api/Controller/ViewerController.php` (`detail`)
- `src/Api/Router.php`, `src/Api/Route.php`, `src/Api/ReadOnlyPolicy.php`,
  `src/Api/TableAccessGuard.php`, `src/Api/AuthContext.php`,
  `src/Api/Request.php`, `src/Api/MultipartFiles.php`,
  `src/Api/Controller/AppController.php` (upload z `$_FILES`),
  `src/Api/Controller/MailController.php` (heslo senderu)
- `src/Core/I18n/CatalogText.php`
- `frontend/src/components/viewer/Viewer.svelte` (`handleDetailAction`,
  `refreshAfterAction`, `busyActionId`), `ViewerDetail.svelte`,
  `ViewerDetailDrawer.svelte`, `ViewerDetailModal.svelte` (read-only —
  akce nemá), `SetPasswordPrompt.svelte`, `detailActionResult.js`,
  `components/form/FormElement.svelte`, `stores/notice.svelte.js`,
  `api/app.js`, `i18n/cs.js`, `i18n/en.js`, `i18n/errors.js`
- **C1 je hotový** (`tasks/module-ui-extensions.md`, implementační poznámky):
  registr `Shipard\Core\Module\ModuleUiExtensions` (staví ho `ViewerLoader`
  a `FormLoader` z resolvovaných modulů, izolace v jednom místě `call()`),
  kontext `ModuleExtensionContext` (včetně tabulky a její definice),
  pravidla `ModuleUiExtensionRules` (třída bez argumentů konstruktoru,
  duplicity), prefix id `<id modulu>.<id>` doplňuje sběr, fixtury
  `tests/Fixtures/modules/test/uiHost` + `uiExtensions` a
  `tests/Unit/Core/Module/UiExtensionFixtures.php`. Akce záznamu na tom
  stavějí — registr, kontext, izolaci a pravidla rozšiř, nekopíruj.

## 1. Deklarace, rozhraní a pravidla

```jsonc
// module.jsonc
"recordActions": [
    {
        "id": "uploadCertificate",
        "table": "economy_salesRegistration_registrations",
        "class": "Shipard\\Module\\Economy\\SalesRegistration\\Actions\\UploadCertificateAction",
        "admin": true,      // volitelné, výchozí false
        "order": 500        // volitelné, výchozí 500
    }
]
```

- `id` `[a-z][a-zA-Z0-9]*`, unikátní v modulu; **globální id akce** =
  `<id modulu>.<id akce>` (`economy.salesRegistration.uploadCertificate`) —
  to jde do detailu i do URL.
- Rozhraní (návrh) `Shipard\Core\RecordAction\RecordAction`, instance bez
  argumentů konstruktoru, kontext v parametru:
  - `available(string $table, array $record, RecordActionContext $ctx): bool`
    — volá se při každém vykreslení detailu, má být levná;
  - `describe(string $table, array $record, RecordActionContext $ctx): RecordActionDescriptor`
    — popisek, varianta (`primary` / `secondary` / `danger`), volitelně
    ikona, text potvrzení a dialog (titulek, úvodní text, popisek
    tlačítka, pole); texty z katalogu modulu přes `CatalogText`;
  - `execute(string $table, array $record, RecordActionInput $input, RecordActionContext $ctx): RecordActionResult`
    — `message` + `level` (`success` / `info` / `warning`); chyba vstupu
    → `RecordActionValidationException` s chybami polí.
- `RecordActionContext`: to, co nese `ModuleExtensionContext` z C1
  (DB, `ConfigRuntime`, `DataSourceConfig`, jazyk, tabulka a její
  definice), navíc přihlášený uživatel (id, správce) a `ServerConfig`
  (sekce modulů přes `ServerConfigSection`, #172 D7) — rozšiř nebo obal
  kontext z C1, nezakládej druhý nezávislý.
- **Pole dialogu (D13)** `{id, type, label, required?, default?, hint?}`
  + podle typu: `select` → `options` `[{value, label}]`; `number` →
  `min`, `max`, `step`; `file` → `accept`, `maxBytes` (povinné, strop
  serveru 10 MB); `notice` → jen text, bez hodnoty. `default: "now"`
  u `date` / `datetime` doplní frontend při otevření dialogu.
- **Test stromu** (rozšíření `ModuleUiExtensionRules` z C1): třída existuje,
  implementuje rozhraní, leží v namespace modulu; tabulka existuje
  (vlastní, nebo modulu, na kterém modul tranzitivně závisí); globální id
  unikátní. Validace tvaru deklarace při načtení modulu.

Commit 1 (`feat(actions): deklarace recordActions, rozhraní a pravidla (#172 D12, D19)`).

## 2. Endpoint (D12–D15)

`POST /api/v1/_ui/record-action/{table}/{recordId}/{globalActionId}`
(nový controller, např. `RecordActionController`, route `recordAction` /
`run`):

1. akce z aktivních modulů zdroje dat; neznámé id nebo jiná tabulka → 404
   `RECORD_ACTION_NOT_FOUND`;
2. `TableAccessGuard::guardTable()` nad tabulkou (stejně jako detail
   vieweru); `admin: true` a uživatel není správce → 403;
3. záznam podle id → 404; `available()` je `false` (záznam se od
   vykreslení změnil) → 409 `RECORD_ACTION_NOT_AVAILABLE`;
4. **vstup:** JSON `{"values": {...}}`, nebo multipart — část `values`
   (JSON řetězec) a soubory `files[<id pole>]`; obecná validace podle polí
   z `describe()`: povinnost, typ (číslo, datum, datum a čas v časovém
   pásmu zdroje dat, checkbox → bool, `select` jen z nabídky), soubor
   přítomný a do `maxBytes`; **pole, které dialog nedeklaruje → 400**;
   chyby → 422 `{"fields": {<id>: <text>}}` (tvar chyb polí jako
   u formulářů, `i18n/errors.js`);
5. `execute()`; `RecordActionValidationException` → 422 se stejným
   tvarem; jiná výjimka → 500 s obecnou hláškou, detail jen do logu;
6. odpověď `{"message": "...", "level": "success"}`.

- **Read-only (D15):** controller do `ReadOnlyPolicy` nepřidávej —
  fail-closed dá ve stavu `read_only` 403. Doplň do tabulky jen komentář,
  že je to záměr, a test.
- **Log (D15):** každé provedení `ErrorLogger::info` (id uživatele, globální
  id akce, tabulka, id záznamu, výsledek / kód chyby). **Nikdy hodnoty
  polí** — ani heslo, ani jméno souboru; výjimky z `execute()`
  `ErrorLogger::error` bez vstupu.
- **Soubor (D13):** handler dostane dočasnou cestu, původní jméno,
  velikost a MIME z obsahu; po requestu PHP soubor smaže — co chce
  handler zachovat, musí si uložit sám.
- **Zámky:** endpoint `documentLockProviders` neuplatňuje — zda akce smí
  nad zamčeným nebo potvrzeným záznamem běžet, rozhoduje `available()`
  a `execute()` akce (Kontaktní platba nad dokladem V pořádku je záměr).
  Zapiš do dokumentace.

Commit 2 (`feat(api): endpoint akce záznamu — oprávnění, vstup, validace, log (#172 D12–D15)`).

## 3. Akce v detailu (D12, D19)

- Akce jsou součástí registru `ModuleUiExtensions` z C1 (stejné načtení,
  stejná izolace `call()`) a `ViewerRegistry` je předá vieweru; `renderDetail()` připojí za akce
  vieweru popisy dostupných akcí:

  ```json
  {"id": "economy.salesRegistration.uploadCertificate", "kind": "record_action",
   "label": "Nahrát certifikát", "variant": "secondary",
   "confirm": null, "dialog": {...},
   "target": {"table": "…", "recordId": 42}}
  ```

- Akce s `admin: true` se uživateli, který není správce, nevypíše — viewer
  potřebuje vědět, zda je uživatel správce; `ViewerController::detail()`
  to předá (zvol cestu, která nerozbije ostatní volání `renderDetail()` —
  MCP, testy).
- Izolace D19: výjimka z `available()` / `describe()` → log, akce se
  vynechá, detail se vykreslí.
- Pořadí: akce vieweru, pak akce modulů podle `order`, id modulu, pořadí
  deklarace.
- `DetailContractTest` zelený.

Commit 3 (`feat(viewer): akce záznamu z modulů v detailu (#172 D12, D19)`).

## 4. Frontend (D12–D14)

- `Viewer.svelte::handleDetailAction` — nová větev pro `action.kind ===
  'record_action'` před závěrečným `console.warn`; stejná obsluha všude,
  kde se dnes obsluhují `detail.actions` (hlavní detail, drawer na
  telefonu); read-only modal akce nemá.
  - bez dialogu: `confirm` → potvrzení (`ConfirmDialog`, ne `window.confirm`),
    pak volání;
  - s dialogem: otevře `RecordActionDialog`;
  - po úspěchu toast (`noticeStore`) se zprávou, `refreshAfterAction()`;
  - během volání akce zablokovaná (`busyActionId`, vzor
    `createCorrectiveDoc`) — dvojklik nesmí akci spustit dvakrát;
  - chyba mimo 422 → `alert(translateError(...))` jako ostatní akce.
- `RecordActionDialog.svelte` nad `Modal` a existujícími vstupy:
  `password` s `autocomplete="new-password"`, `file` jako nativní input
  s `accept` (kontrola velikosti už v prohlížeči, server kontroluje
  znovu), `datetime` jako `Input type="datetime-local"`, `notice` přes
  `Notice`; chyby polí z 422 u polí; tlačítko odeslání zablokované po
  dobu volání; zavření Esc / zrušením jen mimo běh. Hodnoty hesla
  a souboru se po zavření zahodí.
- `api/recordActions.js` — JSON bez souboru, `FormData` se souborem
  (vzor `uploadBranding`).
- Čistý helper (např. `recordActionRequest.js`): výchozí hodnoty včetně
  `"now"`, sestavení těla (JSON × multipart), převod chyb 422 na chyby
  polí — testy v `frontend/tests/Unit/`.
- Obecné texty dialogu (Zrušit, Provést, Probíhá…) v `i18n/cs.js`
  a `i18n/en.js`; kódy chyb endpointu v `i18n/errors.js`.

Commit 4 (`feat(frontend): obecná obsluha a dialog akce záznamu (#172 D12–D14)`).

## 5. Dokumentace a ověření

- `docs/frontend.md` § Akce detailu — `kind: "record_action"`, dialog,
  výsledek, pravidlo D19 (nové akce už jen přes `recordActions`; seznam
  pevných id ve `Viewer.svelte` je uzavřený).
- `docs/modules.md` kap. 5 (pole `recordActions`), kap. 9 Doplňky (bod
  rozšíření).
- `docs/rest-api.md` — endpoint, tvar vstupu a chyb.
- `docs/ds-state.md` — akce záznamu ve stavu `read_only` → 403.
- **Ověření v prohlížeči** na volném zdroji dat s testovacím modulem
  (fixtura nebo dočasná akce, která se necommitne): akce bez dialogu,
  s potvrzením, s dialogem (text, password, datetime s `"now"`, file),
  422 u pole, úspěch s toastem a obnovením detailu i seznamu, telefonní
  šířka (drawer).

Commit 5 (`docs: akce záznamu z modulů (#172 D12–D15, D19)`) včetně
`**Stav:**` a `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] `module.jsonc` umí `recordActions`; test stromu hlídá třídu,
      rozhraní, namespace, tabulku a unikátní globální id
- [ ] endpoint: 404 neznámá akce / záznam, 403 bez přístupu k tabulce
      i u `admin` akce pro běžného uživatele, 403 ve stavu `read_only`,
      409 nedostupná akce, 400 nedeklarované pole, 422 validace (povinné,
      typ, select, soubor nad limit, výjimka handleru), 200 se zprávou —
      vše testy
- [ ] log obsahuje uživatele, akci, tabulku, záznam a výsledek, nikdy
      hodnoty polí (test na heslo)
- [ ] detail ukazuje dostupné akce modulů za akcemi vieweru; `admin` akce
      jen správci; výjimka poskytovatele akci vynechá
- [ ] frontend: akce bez dialogu, s potvrzením a s dialogem fungují
      v hlavním detailu i v draweru; chyby polí u polí; toast a obnovení
      po úspěchu; dvojklik akci nespustí dvakrát; testy helperu zelené
- [ ] fixtura s akcí nad tabulkou `test_uihost_records` (moduly
      `test/uiHost` a `test/uiExtensions` z C1) pokrývá dialog se všemi
      typy polí; žádný skutečný modul
      zatím mechanismus nepoužívá
- [ ] dokumentace podle kroku 5, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný
- [ ] celá sada PHPUnit a `npm test` lokálně zelené, `npm run build` projde

## Implementační poznámky

(doplní Claude Code)
