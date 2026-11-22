# Task: Kód číselné řady a registrace DPH jako přirozený klíč

**Stav:** částečně — commity 0–1 hotové 2026-11-22 (export bez tabulky dimenze; sloupec `code`, generátory, Document); zbývají commity 2–5 (#166 D16, D17)

**Issue:** #166 (D16, D17; navazuje na etapu 2)

**Cíl:** Číselná řada dokladů a registrace DPH dostanou vlastní unikátní
**kód** — jednosloupcový přirozený klíč (`exchangeKey`), stejný jako
u ostatních číselníků. Zanikne zvláštní tvar odkazu na řadu
`{docType, code}` v datových sadách i složený klíč upsertu registrací
(země, název). Kód dostanou i existující záznamy.

Formát dokladu (`applyOptions.numberSeriesCode`, `numberSeriesId`,
`vat.registrationCountry`) se **nemění** — sjednocení referencí dokladu je
etapa 3 (#166 D11).

## Před implementací přečti

- issue #166 — D8–D10, D14, D16, D17
- `tasks/exchange-key-references.md` včetně poznámek k implementaci
  (`exchangeKey`, `ReferenceResolver`, `SetupReferences` jako fasáda)
- `docs/table-definitions.md` — § 4 (`exchangeKey`), § 7 (indexy), § 10
  (bezpečné změny při `ds-upgrade`)
- `docs/datasets.md` — § 1 („Sekce `setup/`“, „Cizí klíče přes přirozený
  klíč“, obálka zakázky), § 4
- `docs/docs-mvp.md` § 5 (číselné řady), `docs/work-orders.md` (řada
  dokladů na druhu a zakázce)
- `modules/docs/core/tables/docs_core_number_series.jsonc`,
  `src/NumberSeriesDocument.php`, `NumberSeriesForm.php`,
  `NumberSeriesViewer.php`, `NumberSeriesProvisioner.php`,
  `BoundNumberSeriesProvisioner.php`
- `modules/economy/assets/src/Posting/AssetPostingSeriesProvisioner.php`
- `modules/economy/codebooks/tables/economy_codebooks_vat_registrations.jsonc`,
  `src/VatRegistrationDocument.php`, `VatRegistrationsForm.php`,
  `VatRegistrationsViewer.php`; `src/Api/Controller/SetupController.php`
  (~ř. 158, průvodce založí registraci)
- `src/Command/DataSource/DsUpgradeCommand.php` — pořadí provisionerů
  (~ř. 360–440), co běží i pod `skipProvisioning`
- `modules/core/exchange/src/Dataset/SetupReferences.php`,
  `Export/SetupExporter.php`, `Dataset/Seed/SetupSeeder.php`
- `modules/economy/workOrders/src/Dataset/WorkOrderExporter.php` (~ř. 230),
  `WorkOrderSeeder.php` (~ř. 121);
  `modules/core/exchange/schemas/shpd.dataset.workOrder.v1.jsonc`
- `docs/edit-forms-cookbook.md` před úpravou formulářů,
  `docs/help-authoring.md` před úpravou `help/`

## Kontext

- Řada dokladů má `doc_number_code` — kód, který se propisuje do čísla
  dokladu (`%C`). Je nepovinný a neunikátní; výchozí řady z provisioneru
  ho nemají. Datové sady proto na řadu odkazují objektem
  `{docType, code}` se zvláštní větví v `SetupReferences` (#131 D37).
- Registrace DPH má v sadě klíč upsertu (země, název) a jako cíl
  reference se přenést neumí.
- #166 D16: obě tabulky dostanou vlastní jednosloupcový klíč; složené
  klíče se nezavádějí.
- Řady vznikají na čtyřech místech: formulář (`NumberSeriesDocument`),
  `NumberSeriesProvisioner`, `BoundNumberSeriesProvisioner` (i líně
  z `DocumentApplier`) a `AssetPostingSeriesProvisioner`. Provisionery
  vkládají řádek přímo, mimo `Document`. Import ze starého Shipardu
  zakládá řady i registrace generickým CRUD, také mimo `Document` (#113).

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D16** — číselné řady a registrace DPH dostanou vlastní
  jednosloupcový přirozený klíč.
- ✓ **D17** — podoba kódu:
  - sloupec `code` v obou tabulkách, unikátní, nezávislý na
    `doc_number_code` (`%C` zůstává, jak je);
  - výchozí řada typu má kód = typ dokladu (`invno`); další řada téhož
    typu `invno-<kód z čísla dokladu>`, bez něj `invno-2`;
  - řada vázaná na pokladnu `cash-<kód pokladny>`, u prodejek
    `cashreg-<kód pokladny>`;
  - registrace DPH má kód = země (`cz`), druhá registrace téže země
    `cz-2`;
  - stávajícím záznamům doplní kód stejným pravidlem `ds-upgrade`; ve
    formuláři je pole předvyplněné a jde přepsat;
  - datové sady odkazují řadu kódem; demo sady se přegenerují, bez
    přechodného období (D14).

## Upřesnění pro implementaci

- **U1 — Sloupec.** `code` `varchar`, v definici `nullable: true`
  + unikátní index (`unq_code`). Nullable jen kvůli pořadí `ds-upgrade`
  (sloupec a index vzniknou dřív, než doplnění proběhne; unikátní index
  víc `NULL` snese). Povinnost hlídá `Document` (U4). Délka: řada 40,
  registrace 20. Skupina sloupců identita. Popisky: řada „Kód řady“,
  registrace „Kód registrace“. Dnešní popisek `doc_number_code`
  „Kód řady (%C)“ přejmenuj na „Kód v čísle dokladu (%C)“ — dva „kódy
  řady“ vedle sebe by se pletly.
- **U2 — Tvar kódu.** `^[A-Za-z0-9][A-Za-z0-9._-]*$` — bez mezer,
  bez `@` a **bez dvojtečky** (D15, gramatika reference). Jedno místo
  pravidla (konstanta + metoda), čte ho validace i generátor.
- **U3 — Generátor** — jedna třída na tabulku v modulu tabulky, čistá
  logika + dotaz na obsazené kódy:
  - **řada vázaná** (`cash_desk` / `warehouse`):
    `<doc_type>-<kód entity>`;
  - **řada nevázaná:** řada typu s nejnižším `id` mezi nesmazanými
    dostane `<doc_type>`; ostatní `<doc_type>-<doc_number_code>`,
    je-li `doc_number_code` neprázdný, jinak `<doc_type>-<n>`, n od 2;
  - **registrace:** registrace země s nejnižším `id` mezi nesmazanými
    dostane zemi malými písmeny (`cz`); ostatní `<země>-<n>`, n od 2;
  - znak mimo U2 v kódu entity nebo v `doc_number_code` se nahradí
    pomlčkou; obsazený kód (i záznamem v koši — unikátní index platí pro
    všechny řádky) dostane příponu `-2`, `-3`, …
- **U4 — Document.** `NumberSeriesDocument` a `VatRegistrationDocument`:
  - `beforeSave`: prázdný `code` u nového záznamu doplní generátor;
  - `validate`: tvar podle U2 (`invalid_value`), unikátnost proti všem
    řádkům tabulky kromě sebe (`duplicate`, zpráva jmenuje, kdo kód má);
    u existujícího záznamu nejde kód vymazat (`required`).
    `VatRegistrationDocument` validuje částečné uložení přes
    `$effective` — drž se toho.

  Kód jde měnit i u záznamu ve stavu V pořádku (odkazy v databázi jsou
  přes id); zařaď ho mezi sloupce editovatelné v read-only stavu, pokud
  to formulář tak rozlišuje.
- **U5 — Doplnění existujících záznamů.** Jedna idempotentní služba na
  tabulku: doplní `code` všem řádkům s `NULL` / prázdným kódem v pořadí
  `id` (včetně řádků v koši — až po živých, aby živé dostaly „hezké“
  kódy). Volá ji:
  - `ds-upgrade` — vždy, **i pod `skipProvisioning`** (kód je
    infrastruktura jako vázané řady); po provisionerech řad;
  - každý provisioner řad hned po vložení řádku (včetně
    `AssetPostingSeriesProvisioner` a líného založení z applieru) —
    řada má kód nejpozději na konci operace, která ji založila.

  Výpis `ds-upgrade`: počet doplněných kódů jen když je nenulový.
- **U6 — Formuláře a viewery.** Pole `code` ve formuláři řady
  i registrace v první sekci u názvu; u nového záznamu se předvyplní
  generátorem (řada po volbě typu / pokladny přes recalculate, jako se
  dnes předvyplňuje název a `doc_number_code`). Viewer: kód v řádku
  seznamu a v detailu. Texty z katalogů podle konvencí formulářů
  a viewerů (žádné literály v PHP).
- **U7 — `exchangeKey`.** Obě tabulky `exchangeKey: "code"`. Strážní
  test z etapy 2 (klíč bez unikátního indexu) se jich netýká — index
  mají.
- **U8 — Datové sady.**
  - `SetupReferences`: zvláštní větev řady dokladů (`DOC_SERIES_TABLE`,
    `docSeriesIds()`, `docSeriesLabel()`) zmizí; řada jde obecnou cestou
    a v souboru je **řetězec** s kódem (`"invno"`).
  - `SetupExporter::TABLES`: `vat_registrations` bez výslovného `key`
    (upsert podle `exchangeKey`); řádek souboru nese `code`.
  - Obálka zakázky `shpd.dataset.workOrder.v1`: `invoicing.numberSeries`
    je řetězec; uprav `.jsonc`, ručně udržovaný `.json` a strážní test
    schématu. `WorkOrderExporter` / `WorkOrderSeeder` beze změny rozhraní.
  - Sloupce číselníků s odkazem na řadu (`work_order_kinds` aj.) nesou
    v souboru řetězec.
  - **Sady v repu** (`demo/datasets/`, `demo/datasets/stories/`,
    `tests/datasets/`, šablony a `build.py`, pokud tvar generují): převeď
    objekt řady na kód a doplň `code` registracím. Ověř dump → seed →
    dump byte-shodně.
- **U9 — Co se nemění.** `DocumentApplier::resolveNumberSeriesFor()`
  (hledá podle `doc_number_code`), `DocumentExporter`
  (`numberSeriesCode` = `doc_number_code`), výchozí řada bez kódu,
  určení registrace dokladu zemí a datem. Číslování dokladů (`%C`,
  čítače) se nedotkne.

## Co je potřeba udělat

### Commit 0 — oprava z ověření etapy 2: export dokladu bez tabulky dimenze

Nález z ověření `tasks/exchange-key-references.md` (#166, komentář
2026-11-22). `DocumentExporter::__construct()` hodí `LogicException`, když
v předaných definicích chybí cílová tabulka některé dimenze deníku.
Dimenze deklaruje `economy.accounting`, který na modulech zakázek
a majetku nezávisí — zdroj dat s účetnictvím bez některého z nich by
doklady nevyexportoval (`dataset-dump`, `dataset-verify`). Před etapou 2
se taková dimenze jen nepřenesla.

1. Chybějící definice cílové tabulky = dimenze **není přenosná**: stejná
   větev jako tabulka bez `exchangeKey` (hodnota se nepřenese, varování
   jen když ji některý doklad opravdu nese). Výjimka zmizí.
2. Rozliš to od volajícího, který definice nepředal vůbec: `DocumentExporter`
   bez konfigurace dimenzí funguje dál jako dnes (unit testy bez
   `$tables`); s konfigurací dimenzí a prázdným `$tables` nejsou přenosné
   žádné — neházej výjimku, test v `DocumentExporterTest`, který ji dnes
   čeká, přepiš na „dimenze se nepřenáší + varování“.
3. Test: konfigurace se třemi dimenzemi, definice jen pro středisko →
   export projde, středisko se přenese, hodnota zakázky a majetku dá
   varování.

### Commit 1 — sloupce, generátory, Document (U1–U4, U7)

1. Definice tabulek: `code`, unikátní index, `exchangeKey`, popisky.
2. Generátory + unit testy pravidel U3 (vázaná, první nevázaná, s kódem
   z čísla, bez něj, kolize, nepovolený znak; registrace první, druhá).
3. `NumberSeriesDocument`, `VatRegistrationDocument` + testy validace
   a doplnění při uložení.

### Commit 2 — doplnění existujících a provisionery (U5)

1. Služby doplnění + volání z `ds-upgrade` a ze všech provisionerů řad.
2. Testy: idempotence, pořadí (živé před košem), běh pod
   `skipProvisioning`, řada založená provisionerem má kód.

### Commit 3 — formuláře a viewery (U6)

1. Formuláře, předvyplnění, viewery, katalogy textů.
2. `cd frontend && npm run build` jen pokud se měnil frontend
   (nemělo by být potřeba).

### Commit 4 — datové sady (U8)

1. `SetupReferences`, `SetupExporter`, schéma obálky zakázky.
2. Převod sad v repu; úprava `SetupReferencesTest`, `SetupExporterTest`,
   `DatasetRoundTripTest`, testů sad zakázek.

### Commit 5 — dokumentace, nápověda, stav

1. `docs/datasets.md` (odstavec o cizích klíčích bez výjimky pro řady,
   tabulka sekce `setup/`, obálka zakázky), `docs/exchange-format.md`
   § 8 „Reference“ (řada a registrace mají klíč), `docs/docs-mvp.md` § 5
   nebo `tables/docs_core_number_series.md` (dva kódy řady a k čemu
   který je), tabulková dokumentace registrací, `CLAUDE.md` (jedna věta
   v konvencích).
2. `help/` — stránky o číselných řadách a o registraci DPH: co je kód
   a kdy ho měnit; `python3 scripts/help-index.py`.
3. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`)

1. `ds-upgrade` → všechny řady a registrace mají kód; druhý běh nic
   nedoplní.
2. Kódy odpovídají D17: výchozí řady = typ dokladu, vázané
   `<typ>-<kód pokladny>`, registrace = země.
3. Nová řada a nová registrace formulářem: kód předvyplněný, jde
   přepsat, duplicita a dvojtečka jsou odmítnuté.
4. `ds-reset` + `dataset-seed` demo sady + `dataset-dump`: byte-shodné
   s repem; `dataset-verify` veřejných sad beze změny.

## Mimo rozsah

- Formát dokladu: `numberSeriesCode` / `numberSeriesId` → jedna
  reference (#166 D11, etapa 3).
- Přenos uživatelsky založených řad dokladů datovou sadou (řady nejsou
  v `SetupExporter::TABLES`; sada dál počítá s řadami z provisioneru).
- Import ze starého Shipardu: runner řady a registrace zakládá
  generickým CRUD bez kódu — kód jim doplní až `ds-upgrade`; aby ho
  posílal sám, je věc etapy importu (`old_shipard`).
- #113 (generický CRUD mimo `Document`).
- Unikátní indexy klíčů ostatních číselníků.

## Pasti

- Unikátní index se vytváří v témže `ds-upgrade` jako sloupec, **před**
  doplněním — sloupec proto musí být nullable; prázdný řetězec místo
  `NULL` by na druhém řádku index porušil.
- Kolace `utf8mb4_czech_ci` nerozlišuje velikost písmen: `FV` a `fv`
  jsou pro unikátní index týž kód — generátor i validace to musí vidět
  stejně jako databáze.
- Archivovaná pokladna má řady v archivu (import historických dokladů) —
  i ty kód dostanou.
- `BoundNumberSeriesProvisioner` běží i z `DocumentApplier` uprostřed
  apply; doplnění kódu nesmí otevřít vlastní transakci ani spadnout na
  souběh dvou apply (unikátní index → zkus další volný kód).
- Přejmenování pokladny se do kódu řady nepropisuje (stejně jako do
  `doc_number_code`, #59 D3).
- Sady v repu se po změně tvaru nedají naseedovat starým kódem ani
  naopak — převod sad a změna `SetupReferences` patří do jednoho commitu.
- PHPUnit jen s úzkým `--filter`, grep s `--include`.

## Hotovo když

- [x] `DocumentExporter` bez definice cílové tabulky dimenze nepadá —
      dimenze se nepřenese (commit 0)
- [ ] obě tabulky mají `code` s unikátním indexem a `exchangeKey`
- [ ] nový záznam (formulář, provisionery, líné založení) má kód podle
      D17; existující ho dostanou při `ds-upgrade`, idempotentně
- [ ] validace tvaru a unikátnosti, pole ve formulářích a viewerech
- [ ] v `SetupReferences` není zvláštní větev řady dokladů; sady nesou
      řadu kódem; `vat_registrations` se upsertuje podle kódu
- [ ] sady v repu převedené, dump → seed → dump byte-shodný,
      `dataset-verify` beze změny
- [ ] cílené testy zelené, pak celá unit sada
- [ ] dokumentace a nápověda podle commitu 5, stav tasku

## Poznámky k implementaci (průběžně)

- **Commit 0:** tabulka dimenze bez `exchangeKey` se dřív přeskakovala
  tiše, nově dává stejné varování jako chybějící definice (jen když
  hodnotu některý doklad nese). Varování nese důvod.
- **Pravidlo tvaru kódu je v jádře** (`Shipard\Core\Database\RecordCode`)
  — čtou ho generátory i validace v `docs.core` a `economy.codebooks`.
- **`exchangeKey` registrace DPH přijde až s commitem 4.** Strážní test
  `SetupExporterTest` chce u každé tabulky sekce setup právě jedno z
  „výslovný klíč upsertu“ a „`exchangeKey`“; výslovný klíč (země, název)
  může zmizet až s převodem sad v repu. Sloupec a unikátní index registrace
  má od commitu 1, řada dokladů má i `exchangeKey`.
- **`NumberSeriesDocument` validuje sloučený řádek** (uložený + payload)
  jako `VatRegistrationDocument` — bez toho by částečné uložení kódu u řady
  ve stavu V pořádku padalo na povinných polích.
- **Existující záznam bez kódu** (založený mimo `Document`) ho dostane
  i při běžném uložení; `required` je jen pokus vymazat uložený kód.
