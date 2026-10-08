# Standardní dimenze v jádru — majetek a středisko

**Stav:** naplánováno — #110 D20, D21, D23; T1–T3 potvrzené 2026-10-08

> PRD pro jednu Claude Code session (4 commity). Design:
> `docs/work-orders.md` §4 (D20, D21, D23), §5.6; issue #110.
> Navazuje na `tasks/assets-phase3.md` a `tasks/assets-phase4.md`
> (mechanismus dimenzí deníku a pole dimenzí na formulářích dokladů).

## Kontext

Dimenze deníku dnes deklaruje modul, kterému patří cílová tabulka, a ten
taky zakládá sloupce přes extensions — jediná dimenze je majetek
(`economy.assets`). Rozbor ke zakázkám (#110) ukázal, že sada dimenzí je
v praxi pevná (starý deník měl za 20 let stále tytéž čtyři: středisko,
projekt, zakázka, majetek), ekonomické moduly jsou přes `install.base`
v každém zdroji a databáze nemá cizí klíče. Volitelnost sloupců tedy nic
nepřináší a jen rozhazuje schéma dokladů a deníku po modulech.

Tenhle task proto udělá ze středisek, zakázek a majetku **standardní
dimenze jádra**: sloupce přímo v tabulkách hlavičky, řádků a deníku,
deklarace v `economy.accounting`, viditelnost na dokladech z jednoho
bloku nastavení. Majetek se přestěhuje, středisko přibude; zakázka
přijde stejnou cestou s modulem zakázek (`tasks/work-orders-phase1.md`).
Jednotný kód nad seznamem dimenzí (engine, deník, formuláře, tisk
Kontace) zůstává beze změny chování.

## Cíl

1. Majetek jako standardní dimenze jádra — přesun sloupců a deklarace
   bez změny schématu a chování (D20).
2. Nastavení *Dimenze na dokladech* v Účetnictví, které nahradí
   „Sledovat náklady na majetek“ (D21).
3. Středisko jako dimenze deníku na hlavičce a řádcích dokladů (D20,
   D23).
4. Dimenze ve výměnném formátu dokladu a středisko v datových sadách —
   aby je šlo importovat ze starého Shipardu a přenášet `dataset-dump`.

## Před implementací přečti

- `docs/work-orders.md` §3.3, §4 (D20, D21, D23), §5.6
- `docs/accounting.md` § Dimenze deníku a §6 (deník)
- `docs/assets.md` D47, D59–D61, §5.5 (dimenze `asset`, `rowFlag`)
- `docs/table-definitions.md` §10 (co dělá `ds-upgrade` se sloupci —
  nic nemaže)
- `docs/app-settings.md` (`settingsPages`, `settingsItems`)
- `docs/exchange-format.md` §5, §7, §10, `docs/datasets.md`
- `modules/economy/assets/module.jsonc` (`extensions`, `journalDimensions`,
  `settingsPages.assetsDocuments`) a `modules/economy/assets/extensions/`
- `modules/economy/accounting/module.jsonc`,
  `modules/economy/codebooks/module.jsonc` (`settingsItems`)
- `src/Core/Accounting/JournalDimension.php`, `JournalDimensionSet.php`,
  `src/Core/Module/ModuleDefinition.php` (parsování `journalDimensions`),
  `src/Core/Config/ConfigCompiler.php` (složení dimenzí)
- `modules/core/exchange/src/Document/DocumentValidator.php`,
  `DocumentApplier.php`, `src/Export/DocumentExporter.php`,
  `SetupExporter.php`, schéma `schemas/shpd.docs.document.v1.jsonc`
- `docs/help-authoring.md` před psaním do `help/`

## Scope

**Uvnitř:** vše v Cíli.

**Mimo:** dimenze zakázka (sloupce `work_order`, deklarace, pole
nastavení — `tasks/work-orders-phase1.md`); bankovní výpis (D23); runner
ve starém Shipardu — plnění střediska na dokladech a zapnutí nastavení
podle zdroje (oblast importu); přehledy po střediscích nad rámec filtru
deníku; převzetí hodnoty starého nastavení `economy.assets.trackExpenses`
(D21 — bez kompatibility).

## 1. Majetek v jádru (D20)

Čistý přesun, žádná změna schématu ani chování:

- Sloupec `asset` (int, nullable, `reference: economy_assets_assets`)
  a index `idx_asset` se přesunou **beze změny id** z extensions modulu
  `economy.assets` do definic tabulek jádra: `docs_core_heads`,
  `docs_core_rows` (`modules/docs/core/tables/`) a
  `economy_accounting_journal` (`modules/economy/accounting/tables/`).
  Komentáře u sloupců převezmi z extensions a doplň odkaz na #110 D20.
- Smaž `modules/economy/assets/extensions/` (tři soubory) a klíč
  `extensions` v `modules/economy/assets/module.jsonc`.
- Deklarace `journalDimensions[asset]` se přesune do
  `modules/economy/accounting/module.jsonc` beze změny (`headColumn`,
  `rowFlag: rowAsset`, `forms.docTypes`), jen
  `forms.enabledBySetting` = `economy.accounting.dimension.asset`
  (§2). Komentář v `module.jsonc` majetku nahraď odkazem na deklaraci
  v účetnictví.
- `ConfigCompiler` se nemění: kontrola „dimenze míří na neznámou tabulku“
  zůstává (každý zdroj s `economy.accounting` má přes `install.base`
  i `economy.assets`). Kdyby některý test skládal `economy.accounting`
  bez majetku, uprav fixture — kontrolu neoslabuj.
- `ds-upgrade` na zdroji, který sloupce už má, nesmí hlásit žádnou změnu
  u `asset` (stejný sloupec i index; `ds-upgrade` nic nemaže).

## 2. Nastavení „Dimenze na dokladech“ (D21)

- `economy.accounting` dostane `settingsPages` → stránku
  `accountingDimensions` („Dimenze na dokladech“ / „Dimensions on
  documents“) a `settingsItems` `{ "page": "accountingDimensions",
  "section": "accounting" }`.
- Jedno pole `select` ano / ne na dimenzi, klíč
  `economy.accounting.dimension.<id>`, prázdné = ne. Pořadí polí =
  pořadí dimenzí: středisko, majetek (zakázka přibude mezi ně ve
  `work-orders-phase1`). Hint u majetku převezmi z dnešního pole
  (řádek pořízení má pole vždy).
- Z `economy.assets` zmizí stránka `assetsDocuments` i její
  `settingsItems`; klíč `economy.assets.trackExpenses` se nikde nečte.
  Hodnotu nepřevádíme (D21).
- `economy.codebooks.costCenters` v `settingsItems` modulu
  `economy.codebooks` přesuň ze sekce `warehouses` do `accounting` —
  středisko je teď účetní dimenze.
- Nastavení řídí jen zobrazení pole; uložená hodnota se do deníku
  propisuje dál (beze změny, `docs/accounting.md`).

## 3. Středisko jako dimenze (D20, D23)

- Sloupec `cost_center` (int, nullable,
  `reference: economy_codebooks_cost_centers`) a index `idx_cost_center`
  v `docs_core_heads`, `docs_core_rows` a `economy_accounting_journal`.
- Deklarace v `economy.accounting` **před** majetkem:

  ```jsonc
  {
      "id": "costCenter",
      "rowColumn": "cost_center", "headColumn": "cost_center",
      "journalColumn": "cost_center",
      "table": "economy_codebooks_cost_centers",
      "exchangeKey": "code",                       // §4
      "name": "Cost center", "name:cs": "Středisko", "name:en": "Cost center",
      "name:sk": "Stredisko", "name:de": "Kostenstelle",
      "forms": {
          "docTypes": ["invno", "invpo", "invni", "cash", "cmnbkp"],
          "head": true, "rows": true,
          "enabledBySetting": "economy.accounting.dimension.costCenter"
      }
  }
  ```

  Bez `rowFlag`. Zálohová faktura vydaná má pole na rozdíl od majetku —
  periodická fakturace z ní vystavuje zálohy a nese na nich středisko.
- Zbytek dává mechanismus: pole na formulářích (`addDimensionElements`,
  `DocRowsForm`), dědění z hlavičky v enginu, filtr `dim_costCenter`
  a sloupec v deníku, tab Zaúčtování, tisk Kontace. Ověř, že lookup
  střediska nabízí jen platné záznamy číselníku, jako ostatní lookupy
  archivních číselníků.

## 4. Výměnný formát a datové sady

- Deklarace dimenze dostane volitelný klíč **`exchangeKey`** = sloupec
  cílové tabulky, který slouží jako přirozený klíč ve výměnném formátu
  (středisko `code`, majetek `asset_number`). Dimenze bez `exchangeKey`
  ve formátu není. Validace v `ModuleDefinition` (identifikátor),
  vlastnost v `JournalDimension`.
- `shpd.docs.document.v1`: volitelný objekt **`dimensions`** na hlavičce
  a na řádku — klíč = id dimenze, hodnota = řetězec přirozeného klíče
  (`"dimensions": { "costCenter": "S01" }`). Ve schématu
  `additionalProperties: { "type": "string" }`; klíče hlídá PHP.
  Kompilace `.jsonc` → `.json` je ruční (`modules/core/exchange/README.md`),
  drift hlídá `SchemaDriftTest`.
- `DocumentValidator`: neznámé id dimenze nebo dimenze bez `exchangeKey`
  → issue `dimension_unknown`; hodnota, ke které není záznam →
  `dimension_not_found` (cesta `rows.3.dimensions.costCenter`).
- `DocumentApplier`: přeloží hodnotu na id záznamu a zapíše sloupec
  hlavičky / řádku; platí i v importním módu. Prázdný objekt nebo
  chybějící klíč = sloupec se nemění (merge) / NULL (nový doklad).
- `DocumentExporter`: vyplněné dimenze s `exchangeKey` exportuje na
  hlavičce i řádcích.
- `SetupExporter`: tabulka `cost_centers`
  (`economy_codebooks_cost_centers`, klíč `code`), aby datová sada nesla
  číselník středisek před doklady. Nastavení `economy.accounting.*` sada
  nese už dnes (prefix `economy.`).
- Majetek si ponechává vlastní doplnění karty na doklady
  (`docs/assets.md` §5.7); `dimensions.asset` je navíc, pro export
  a datové sady.
- `docs/exchange-format.md` §5 a §7 (objekt `dimensions`, issue kódy),
  `docs/datasets.md` (tabulka středisek v setup).

## 5. Dokumentace

- `docs/accounting.md` § Dimenze deníku: zásada „účetnictví o konkrétní
  dimenzi neví“ se nahradí standardními dimenzemi jádra (#110 D20) —
  tabulka středisko / zakázka (plánovaná) / majetek, deklarace
  v `economy.accounting`, sloupce v tabulkách jádra, mechanismus dál
  otevřený pro další dimenze; `exchangeKey`; nastavení
  `economy.accounting.dimension.<id>`. §6 doplní `asset` a `cost_center`
  do tabulky sloupců deníku.
- `docs/assets.md`: D59 označ jako nahrazené #110 D21, v §5.5 nová cesta
  k nastavení a odkaz na deklaraci v účetnictví.
- `docs/work-orders.md` §7: stav řádku tasku.
- `CLAUDE.md`: řádky `docs/accounting.md` a `docs/assets.md` (zmínka
  `economy.assets.trackExpenses` → *Dimenze na dokladech*).

## Uživatelská dokumentace

- `help/majetek/naklady-na-majetek.md`, `help/majetek/nastaveni-majetku.md`
  — nová cesta: **Nastavení aplikace → Účetnictví → Dimenze na
  dokladech → Majetek na dokladech**.
- `help/uctarna/strediska.md` (nová) — co je středisko, číselník,
  zapnutí v nastavení, hlavička vs. řádek (dědění), filtr v deníku,
  sloupec v Kontaci; vypnutím se nic neztratí.
- `python3 scripts/help-index.py`.

## Testy

- Nový `tests/Unit/Module/Economy/Accounting/StandardDimensionsTest.php`
  nad skutečnou konfigurací: deklarace v `economy.accounting` ↔ sloupce
  a indexy v definicích tří tabulek jádra; pořadí dimenzí; invarianty
  majetku z dnešního `AssetDimensionParityTest` (výchozí z hlavičky, pole
  na formulářích, `invpo` bez pole, `rowAsset` z hlavičky nedědí);
  středisko na `invpo` má pole. `AssetDimensionParityTest` se zruší.
- `ModuleDefinitionTest`, `JournalDimensionSetTest`,
  `DimensionFormFieldsTest` — klíče nastavení, `exchangeKey`.
- `AccountingEngineDimensionsTest` — dvě dimenze současně (středisko
  z hlavičky, majetek z řádku), klíč seskupení řádků.
- Výměnný formát: validator (`dimension_unknown`, `dimension_not_found`),
  applier (hlavička, řádek, importní mód), exporter, `SetupExporter`.
- `vendor/bin/phpunit --filter 'Dimension|JournalDimension|AccountingEngine|ModuleDefinition|ConfigCompiler|Document(Validator|Applier|Exporter)|SetupExporter|Assets'`,
  pak celá sada.

## Task breakdown

1. **Majetek v jádru** — přesun sloupců a deklarace, smazání extensions,
   stránka *Dimenze na dokladech* s polem Majetek, zrušení
   `assetsDocuments`, sekce číselníku středisek + `StandardDimensionsTest`
   + úpravy testů + `docs/accounting.md`, `docs/assets.md`.
   `ds-upgrade` ukázkového zdroje bez změn schématu.
2. **Středisko** — sloupce, deklarace, pole nastavení + testy (engine,
   formuláře).
3. **Výměnný formát a datové sady** — `exchangeKey`, schéma, validator,
   applier, exporter, `SetupExporter` + testy + `docs/exchange-format.md`,
   `docs/datasets.md`.
4. **Dokumentace a stav** — help, `CLAUDE.md`, `docs/work-orders.md` §7,
   Stav tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- `ds-upgrade` přidá jen sloupce a indexy `cost_center`; u `asset` nic.
- **Nastavení → Účetnictví → Dimenze na dokladech** má Středisko
  a Majetek; Nastavení → Majetek stránku Majetek na dokladech nemá;
  Střediska jsou v sekci Účetnictví.
- Se zapnutým Majetkem se doklady chovají jako dřív (pole na hlavičce
  a řádcích, řádek pořízení má pole vždy).
- Se zapnutým Střediskem má faktura přijatá, vydaná, zálohová vydaná,
  pokladní a účetní doklad pole Středisko na hlavičce i řádcích; po
  zaúčtování nesou řádky deníku středisko (řádek bez hodnoty z hlavičky),
  filtr Středisko v deníku funguje a Kontace má sloupec Středisko.
- Vypnutím nastavení pole zmizí a hodnoty v deníku zůstanou.
- `dataset-dump` nese číselník středisek a `dimensions` na dokladech;
  `dataset-seed` do zresetovaného zdroje je obnoví.
- Apply dokladu s neznámým kódem střediska skončí chybou
  `dimension_not_found` s cestou k řádku.
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **D20, D21, D23** (#110) — standardní dimenze jádra, nastavení
  *Dimenze na dokladech* bez převzetí `trackExpenses`, středisko na
  hlavičce a řádcích dokladů.
- ✓ **T1 — Klíč nastavení** `economy.accounting.dimension.<id>` — jeden
  vzor pro všechny dimenze.
- ✓ **T2 — `exchangeKey` a objekt `dimensions`** ve výměnném formátu —
  přirozený klíč (středisko kód, majetek inventární číslo), ne id
  záznamu: datové sady jdou přenést mezi zdroji a runner importu kódy
  středisek zná.
- ✓ **T3 — Pole Středisko i na zálohové faktuře vydané** — periodická
  fakturace vystavuje zálohy a nese na nich středisko (majetek tam pole
  nemá).
