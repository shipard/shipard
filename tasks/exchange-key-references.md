# Task: Přirozený klíč tabulky a obecný resolver referencí

**Stav:** hotovo — 2026-11-22 (#166 etapa 2, D8–D10, D15); zadání 2026-11-21

**Issue:** #166 (etapa 2)

**Cíl:** Přirozený klíč záznamu se deklaruje **v definici tabulky**
(`exchangeKey`) a hledá ho jeden obecný resolver. Přejdou na něj datové
sady (`SetupReferences`) a dimenze dokladů (`DimensionResolver`); mapa
klíčů v `SetupExporter::TABLES` a `exchangeKey` v konfiguraci dimenzí
zaniknou. Vznikne i parser gramatiky hodnoty reference (číslo, `"@klíč"`,
`"@sloupec:hodnota"`), který od etapy 3 použije formát dokladu.

**Formát dokladu ani soubory datových sad se v tomhle tasku nemění** —
dump veřejných sad musí zůstat byte-shodný.

## Před implementací přečti

- issue #166 (D8–D14, otevřené body)
- `docs/table-definitions.md` — § 4 (pole na úrovni tabulky,
  `displayPattern`), § 5 („Reference na jinou tabulku“), § 8 (extensions)
- `docs/datasets.md` — § 1 („Sekce `setup/`“, „Cizí klíče přes přirozený
  klíč“)
- `docs/accounting.md` — § Dimenze deníku (`journalDimensions`,
  `exchangeKey`)
- `docs/exchange-format.md` — § 5 (`dimensions`), § 8, tabulka issue kódů
  (`dimension_unknown`, `dimension_not_found`)
- `src/Core/Database/TableDefinition.php`, `ColumnDefinition.php`,
  `TableMerger.php`; `tests/Unit/Core/Database/TableDefinitionTest.php`
- `modules/core/exchange/src/Dataset/SetupReferences.php` — celý
- `modules/core/exchange/src/Export/SetupExporter.php` — `TABLES`,
  `exportTable()`
- `modules/core/exchange/src/Dataset/Seed/SetupSeeder.php`
- `modules/core/exchange/src/Resolve/DimensionResolver.php`
- `src/Core/Accounting/JournalDimension.php`, `JournalDimensionSet.php`,
  `src/Core/Module/ModuleDefinition.php` (~ř. 356–410, parsování
  `journalDimensions`)
- `modules/economy/accounting/module.jsonc` — `journalDimensions`
- uživatelé `SetupReferences`: `modules/economy/assets/src/Dataset/`,
  `modules/economy/workOrders/src/Dataset/`
- testy: `SetupReferencesTest`, `SetupExporterTest`, `DatasetDumperTest`,
  testy dimenzí (`JournalDimensionSetTest`, `StandardDimensionsTest`,
  `DimensionFormFieldsTest`), `DatasetRoundTripTest`

## Kontext

Přirozený klíč záznamu má dnes tři nesourodé podoby:

- PHP konstanta `SetupExporter::TABLES` (`'key' => ['code']`), ze které
  `SetupReferences::keyColumn()` bere jednosloupcový klíč pro cizí klíče
  v datových sadách; účet osnovy má v `SetupReferences` vlastní větev;
- `exchangeKey` u dimenze v `journalDimensions` (středisko `code`, zakázka
  `number`, majetek `asset_number`);
- vlastní pole ve formátu dokladu (`ourCode`, `numberSeriesCode`, …) —
  ta řeší až etapa 3.

Sloupec tabulky přitom už nese `reference` na cílovou tabulku a tabulka
`displayPattern`. Chybí jen říct, **který sloupec cílové tabulky je její
přirozený klíč**.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D8** — přirozený klíč v definici tabulky (`exchangeKey`); nahradí
  mapu v `SetupExporter::TABLES` i `exchangeKey` dimenzí; hledá jeden
  obecný resolver.
- ✓ **D9** — gramatika hodnoty reference: číslo = primární klíč;
  `"@K00042"` = přirozený klíč cílové tabulky; `"@company_id:12345678"`
  = jmenovaný sloupec; jiný řetězec = chyba.
- ✓ **D10** — přesná shoda; žádný nebo víc než jeden živý záznam je
  chyba; koš se nepočítá. Jmenovaný sloupec jen ten, který tabulka pro
  hledání deklaruje.
- ✓ **Dvojtečka** (2026-11-21) — část před první dvojtečkou je sloupec
  jen tehdy, když jde o deklarovaný sloupec cílové tabulky; jinak je celý
  text za `@` hodnota přirozeného klíče.

## Upřesnění pro implementaci

- **U1 — Definice tabulky.** Dvě nepovinná pole na úrovni tabulky:
  - `exchangeKey`: id sloupce — přirozený klíč;
  - `exchangeLookupColumns`: seznam id sloupců, přes které smí hledat
    jmenovaná reference (`"@sloupec:hodnota"`). `exchangeKey` je povolený
    vždy, do seznamu se neuvádí.

  `TableDefinition` dostane `?string $exchangeKey` a
  `array $exchangeLookupColumns`. `fromArray()` odmítne (výjimka
  s názvem tabulky): neexistující sloupec, primární klíč, sloupec
  `sensitive` nebo šifrovaný, jiný typ než řetězcový nebo celočíselný,
  duplicitu v seznamu. Extensions pole neumí (jen sloupce a indexy) —
  beze změny.
- **U2 — Unikátní index se nevyžaduje.** Klíče podle názvu (druh zakázky,
  typ majetku, pořadač) index nemají a nejednoznačnost řeší D10 až při
  hledání. Strážní test nad skutečnými definicemi drží **seznam tabulek
  s `exchangeKey` bez unikátního indexu** — nová tabulka na něm je vědomé
  rozhodnutí, ne opomenutí.
- **U3 — Které tabulky klíč dostanou.** Sloupec vždy ověř v definici:
  - všechny tabulky z `SetupExporter::TABLES` s jednosloupcovým klíčem —
    týž sloupec, jaký mapa uvádí dnes;
  - `economy_accounting_accounts` → `number`;
  - cílové tabulky dimenzí — sloupce z dnešního `exchangeKey` dimenzí;
  - `base_persons_persons` → `person_id`, lookup `company_id`, `vat_id`;
  - `economy_items` → `code`, lookup `ean`, `sku`;
  - `docs_core_heads` → `doc_number`.

  **Bez klíče zůstávají** `docs_core_number_series`
  a `economy_codebooks_vat_registrations` (složený klíč — #166 otevřený
  bod) a `core_system_settings`.
- **U4 — Parser gramatiky** `Shipard\Module\Core\Exchange\Resolve\ReferenceValue`
  — čistá třída bez databáze. Vstup: hodnota z JSON a definice cílové
  tabulky. Výstup: druh (`id` | `key` | `column`), sloupec a hodnota.
  - JSON celé číslo > 0 → `id`. Číslo ≤ 0, desetinné, `true` / `false`,
    `null`, pole → chyba.
  - Řetězec začínající `@`: text za `@` nesmí být prázdný. Obsahuje-li
    dvojtečku a část před **první** dvojtečkou je `exchangeKey` nebo
    sloupec z `exchangeLookupColumns` cílové tabulky → `column` (hodnota
    = zbytek, smí obsahovat další dvojtečky, nesmí být prázdná). Jinak
    `key` s celým textem za `@`.
  - Jakýkoli jiný řetězec (i číselný, `"42"`) → chyba.
  - Hodnota se neořezává ani nemění velikost písmen.
- **U5 — Resolver** `Shipard\Module\Core\Exchange\Resolve\ReferenceResolver`
  (`Connection`, definice aktivních tabulek):
  - `resolve(string $table, mixed $value): int` — podle U4;
  - `resolveByKey(string $table, string $value): int` — přímo přirozeným
    klíčem (pro volající, jejichž soubory nesou holou hodnotu klíče:
    datové sady, dimenze);
  - `keyOf(string $table, int $id): ?string` — hodnota přirozeného klíče
    záznamu (export); `null` = záznam není nebo má klíč prázdný;
  - `hasKey(string $table): bool`.

  Chyby jako `ReferenceException` s kódem a texty česky, tykáním:
  `reference_invalid` (tvar hodnoty), `reference_table_unknown`,
  `reference_no_key` (tabulka nemá `exchangeKey`),
  `reference_column_not_allowed`, `reference_not_found`,
  `reference_ambiguous` (zpráva nese počet a id záznamů).
  Záznam v koši (`docState` 90, jen u tabulky s `docStates`) se nepočítá
  — ani u hledání primárním klíčem; archivovaný ano. Sloupec i tabulka
  jdou do SQL jen z definice, hodnota vždy parametrem. Cache per instance.
- **U6 — `SetupReferences`** zůstává fasádou datových sad se stejným
  rozhraním a stejnými texty chyb (`DatasetException`), uvnitř deleguje:
  - `keyColumn()` / `isPortable()` čtou `exchangeKey` z definice tabulky;
    větev pro účet osnovy mizí (účet má klíč v definici);
  - `export()` / `resolve()` jdou přes `ReferenceResolver`; výjimky
    resolveru překládá na dnešní zprávy;
  - **řada dokladů** (`{docType, code}`) zůstává zvláštní větví beze změny.
- **U7 — `SetupExporter::TABLES`.** `key` je klíč **upsertu souboru sady**.
  U tabulky s `exchangeKey` se z mapy vypustí a bere se z definice;
  výslovný zůstává jen tam, kde tabulka `exchangeKey` nemá
  (`core_system_settings`, `economy_codebooks_vat_registrations`).
  Test: každá položka mapy má právě jedno z toho.
- **U8 — Dimenze.** `DimensionResolver` bere sloupec z `exchangeKey`
  cílové tabulky a hledá přes `ReferenceResolver::resolveByKey()`; jeho
  chování (nenalezeno → `null`, applier hlásí `dimension_not_found`) se
  nemění. `exchangeKey` zmizí z `journalDimensions`, z `JournalDimension`
  a z parsování v `ModuleDefinition`; „dimenze má klíč pro výměnný
  formát“ = cílová tabulka má `exchangeKey`. Kdo dnes čte
  `JournalDimension::$exchangeKey` (validátor, exporter, formuláře,
  testy), přejde na definici tabulky — najdi cíleným grepem.
- **U9 — Nic se nemění navenek.** Stejné soubory sad, stejné issue kódy
  a zprávy applieru, stejné chování exportu. Jediný nový veřejný povrch
  jsou dvě pole v definici tabulky.

## Co je potřeba udělat

### Commit 1 — definice tabulky (U1–U3)

1. `TableDefinition` + validace + testy (`TableDefinitionTest`: platné,
   každý důvod odmítnutí).
2. `exchangeKey` / `exchangeLookupColumns` do definic tabulek podle U3.
3. Strážní test nad skutečnými definicemi (U2) + test, že každá cílová
   tabulka dimenze a každá jednosloupcová tabulka z `SetupExporter::TABLES`
   klíč má a je to týž sloupec jako dosud.

### Commit 2 — parser a resolver (U4, U5)

1. `ReferenceValue` + unit testy: všechny tvary, dvojtečka (deklarovaný
   sloupec, nedeklarovaný text před dvojtečkou, víc dvojteček, prázdná
   hodnota), číselný řetězec.
2. `ReferenceResolver` + unit testy: nalezeno, nenalezeno, nejednoznačné,
   koš, archiv, primární klíč, tabulka bez klíče, nepovolený sloupec.

### Commit 3 — datové sady (U6, U7)

1. `SetupReferences` a `SetupExporter` podle U6, U7; uživatelé v majetku
   a zakázkách beze změny rozhraní.
2. Úprava `SetupReferencesTest`, `SetupExporterTest`.

### Commit 4 — dimenze (U8)

1. `DimensionResolver`, `JournalDimension`, `ModuleDefinition`,
   `module.jsonc`, ostatní čtenáři.
2. Úprava testů dimenzí.

### Commit 5 — dokumentace a stav

1. `docs/table-definitions.md` § 4 — obě pole, pravidla U1, U2.
2. `docs/exchange-format.md` — nový oddíl „Reference“ v § 8: gramatika
   D9, D10, pravidlo dvojtečky, kódy chyb; výslovně, že formát dokladu ji
   začne používat až v etapě 3 (#166).
3. `docs/datasets.md` (odstavec „Cizí klíče přes přirozený klíč“),
   `docs/accounting.md` (dimenze bez `exchangeKey`), řádky v `CLAUDE.md`
   (`docs/table-definitions.md`, konvence přirozeného klíče).
4. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`)

- `ds-upgrade` (kompilace konfigurace po změně `module.jsonc`).
- `dataset-dump` → porovnání s veřejnou sadou: byte-shodné.
- `dataset-verify` veřejných sad: výsledky jako před taskem.
- `DatasetRoundTripTest` a `DocumentRoundTripMatrixTest` (je-li už
  v repu) beze změny.

## Mimo rozsah

- Formát dokladu a ostatní výměnné formáty: `ref` na straně a položce,
  sjednocení `numberSeriesCode` / `numberSeriesId`,
  `importOwnBankAccount`, `ourCode` (D11–D14, etapa 3).
- `PartyResolver`, `ItemResolver`, `UnitResolver` — zůstávají.
- Přirozený klíč číselných řad a registrací DPH.
- Unikátní indexy pro klíče podle názvu.
- Sloupec s primárním klíčem zdrojového systému (klíč importu).
- Import ze starého Shipardu.

## Pasti

- `SetupExporter::TABLES['key']` má dvě role (klíč upsertu, klíč cizího
  klíče) — neslučuj je; tabulka se složeným klíčem upsertu
  (`vat_registrations`) `exchangeKey` nedostane.
- Definice tabulek se kompilují do konfigurace zdroje dat — po změně je
  nutný `ds-upgrade`, jinak běžící zdroj nová pole nevidí.
- `DimensionResolver` vrací `null`, `SetupReferences` hází
  `DatasetException` — rozdílné chování volajících zachovej, sjednocuje
  se jen hledání.
- Hodnota klíče `"0123"` a `"123"` jsou různé klíče; nic nenormalizuj.
- Grep jen s `--include` a cíleně; PHPUnit s úzkým `--filter`.

## Hotovo když

- [x] `exchangeKey` a `exchangeLookupColumns` jsou v `TableDefinition`
      s validací a v definicích tabulek podle U3
- [x] `ReferenceValue` a `ReferenceResolver` s unit testy všech tvarů
      a chyb
- [x] `SetupReferences` a `DimensionResolver` hledají přes resolver;
      v kódu není mapa přirozených klíčů mimo definice tabulek ani
      `exchangeKey` v `journalDimensions`
- [x] dump veřejných sad byte-shodný, `dataset-verify` beze změny
- [x] cílené testy zelené, pak celá unit sada
- [x] dokumentace podle commitu 5, stav tasku

## Odchylky od zadání (2026-11-22)

- **Přenosnost v sadách není „tabulka má `exchangeKey`“.** Osoby, položky
  a doklady klíč dostaly, sekce setup je ale nenese — FK na ně by se začal
  zapisovat do souborů sad (`vat_registrations.tax_office_person`).
  `SetupReferences::isPortable()` = řada dokladů, nebo tabulka ze
  `SetupExporter::TABLES` s `exchangeKey`.
- **`isPortable()` a `keyColumn()` jsou metody instance** — bez definic
  tabulek klíč nepřečtou. Volá je jen `SetupExporter` a `SetupSeeder`.
- **`resolveByColumn()` navíc proti U5.** Kód `reference_column_not_allowed`
  je přes `resolve()` nedosažitelný (nedeklarovaný text před dvojtečkou je
  podle D15 hodnota klíče); hází ho přímé hledání jmenovaným sloupcem.
- **Nejednoznačná hodnota dimenze** už není první záznam podle id, ale
  nenalezeno (`dimension_not_found`) — D10. Všechny tři cílové tabulky mají
  nad klíčem unikátní index.
- **Unikátní index chybí šesti klíčům**, ne jen klíčům podle názvu: navíc
  `economy_work_orders_number_series.number_code` a `docs_core_heads.doc_number`
  (seznam v `ExchangeKeyDefinitionsTest`).
- **Ořezávání zůstalo ve fasádách** (`ValueNormalizer::str` při exportu,
  `trim` v `DimensionResolver`), resolver sám hodnotu nemění. `keyOf()` koš
  nefiltruje, jako dosavadní export.
- **Čtenáři dimenzí dostávají definice tabulek:** `DocumentExporter` (bez
  definice cílové tabulky dimenze končí výjimkou), `DocumentValidator`,
  `CorrectiveDocService`. Pole `exchangeKey` v `journalDimensions` je chyba
  načtení modulu.
- **Definice tabulek se nekompilují** — `TableLoader` je čte z modulů živě;
  `ds-upgrade` je potřeba jen kvůli `journalDimensions` (a i bez něj se
  zbylé pole ve zkompilované konfiguraci ignoruje).

## Ověření (2026-11-22)

- Dump čtyř ukázkových zdrojů dat (`jfpl-7`, `im3v-h`, `gsbg-b`, `8yft-3`
  — poslední se zakázkami, majetkem a 140 doklady s dimenzemi) před změnou
  a po ní včetně `ds-upgrade`: byte-shodný až na datum v manifestu.
- `DatasetRoundTripTest` a `DocumentRoundTripMatrixTest` nad `blkm-i` zelené.
- Vrstva sad testovacího kola (`test-round --only datasets`): čerstvý seed,
  `dataset-verify` a `dataset-e2e` všech tří veřejných sad `ok`.
- Celá unit sada zelená (11 850 testů).
