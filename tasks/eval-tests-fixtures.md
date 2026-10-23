# Testy katalogu evaluací nezávislé na `tests/eval/`

**Stav:** hotovo — 2026-10-23 (#103 D106)

## Kontext / Cíl

`DevTestingControllerTest::testDatasetsListIncludesRunCounts`
a `EvalCatalogTest::testRunsAreListedNewestFirstWithBasename` čtou skutečný
adresář `tests/eval/` a předpokládají jeho obsah: nejnovější běh plátce je
z 2026-10-20, spolek nemá žádný běh, plátce má aspoň 13 běhů. Commit
výsledků z 2026-10-23 (`f5a3b022`) je rozbil — a rozbil by je každý další
commit výsledků evaluací, které repozitář drží záměrně (D8).

**Cíl:** testy katalogu a prohlížeče evaluací nezávisí na tom, kolik
a jakých běhů v `tests/eval/` je. Přidání nového běhu žádný test nerozbije.

## Návaznost

- **#103** D106 (komentář 2026-10-23), D8 (výsledky v repu), D52
  (katalog sad v checkoutu)
- `docs/test-corpus.md` § 18 (evaluace, prohlížeč výsledků)

## Před implementací přečti

- `modules/core/mail/src/Eval/Browse/EvalCatalog.php` (kořen repa
  v konstruktoru)
- `tests/Unit/Module/Core/Mail/Eval/Browse/EvalCatalogTest.php` — vzor
  dočasného kořene je v `testIgnoresDemoDatasetsOfTheSeedCatalog`
- `tests/Unit/Api/Controller/DevTestingControllerTest.php`
  (`DevDashboardController` dostává `$repoRoot`)
- ostatní testy, které čtou `tests/eval/` (`grep -rln "tests/eval" tests/Unit --include=*.php`):
  `EvalRunComparisonTest`, `EvalCostEstimatorTest`, `EvalRunStoreTest`,
  `DevJobsControllerTest`, `DatasetE2eCommandTest`, `E2ePreflightTest`

## Rozhodnutí

- **D106 — Testy nad katalogem evaluací si staví vlastní kořen.** Test,
  který tvrdí něco o adresáři `tests/eval/` jako celku (počet běhů,
  nejnovější běh, pořadí, sada bez běhů, počty v seznamu sad), pracuje
  nad dočasným kořenem s minimálními manifesty a běhy, které si sám
  vytvoří. Test, který potřebuje realistický běh (např.
  `EvalRunComparisonTest`), smí číst **konkrétní pojmenovaný** historický
  soubor jen pro čtení — o zbytku adresáře nic nepředpokládá.

## 1. Fixtura

- Pomocná funkce / trait pro testy (např. `EvalFixtureRoot`): dočasný
  kořen s `tests/datasets/cz/<sada>/manifest.jsonc` a
  `tests/eval/<sada>/<datum>-<varianta>.json` v minimálním platném tvaru
  (co `EvalCatalog` a controller čtou), úklid v `tearDown`. Žádná druhá
  kopie tvaru manifestu — vyjít z toho, co už dělá
  `testIgnoresDemoDatasetsOfTheSeedCatalog`.
- `currentHash` sady v `DevTestingControllerTest` (čte se z manifestu /
  souborů sady) musí jít spočítat i nad fixturou; pokud ne, tento jeden
  test smí dál číst skutečnou sadu `cz-vat-payer` — ale ne `tests/eval/`.

## 2. Úprava testů

- `EvalCatalogTest::testRunsAreListedNewestFirstWithBasename` a
  `DevTestingControllerTest::testDatasetsListIncludesRunCounts` nad
  fixturou: přesné počty, přesný nejnovější běh, sada bez běhů = 0.
- Projít ostatní testy ze seznamu výše: kde předpokládají vlastnosti
  adresáře jako celku, převést na fixturu; kde čtou pojmenovaný soubor,
  nechat. Do závěrečné zprávy tabulka test → co předpokládal → co se
  změnilo.

## Kroky

1. Fixtura a převedené testy → commit
   `test(eval): testy katalogu a prohlížeče evaluací nad vlastní fixturou (#103 D106)`
2. `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs(tasks): eval-tests-fixtures hotovo (#103 D106)`

## Hotovo když

- [x] `vendor/bin/phpunit --filter 'EvalCatalogTest|DevTestingControllerTest|EvalRunComparisonTest|EvalCostEstimatorTest|EvalRunStoreTest|DevJobsControllerTest|DatasetE2eCommandTest|E2ePreflightTest'` projde.
- [x] **Zkouška nezávislosti:** dočasně zkopírovat libovolný běh do
      `tests/eval/cz-vat-payer/2099-01-01-probe.json`
      a `tests/eval/cz-association/2099-01-01-probe.json`, pustit stejný
      filtr → projde; kopie smazat (necommitovat). Výsledek v závěrečné
      zprávě.
- [x] Tabulka převedených testů v závěrečné zprávě.
- [x] `**Stav:**`, index tasků.

## Výsledek (2026-10-23)

- **Fixtura** `tests/Unit/Module/Core/Mail/Eval/Browse/EvalFixtureRoot.php`:
  dočasný kořen s `tests/datasets` + `tests/eval`, `manifest(relDir, name,
  title, extra)` (jediné místo, které tvar manifestu píše — slouží i demo
  sadě), `run(set, basename, startedAt, variant, hash, extra)` v minimálním
  tvaru `shpd.eval.run.v1`, `currentHash(dir)` (sada jen s manifestem má
  deterministický hash — `DatasetReader::open` chce jen `manifest.jsonc`,
  `datasetHash` chybějící složky přeskočí), sestava
  `seedPayerAndAssociation()` (dvě sady, tři běhy plátce — `alpha` začal
  dřív než `baseline` téhož dne, takže se hlídá řazení podle `startedAt`,
  ne podle názvu souboru; dva běhy nad aktuálním hashem, jeden nad starším),
  úklid `remove()` přes `DatasetReader::removeTree`.
- **Zkouška nezávislosti:** s kopiemi `2099-01-01-probe.json` v obou
  složkách prošel filtr (rozšířený o `EvalRunSummaryTest`
  a `EvalOutputReconstructionTest`) stejně jako bez nich — 79 testů,
  830 tvrzení; kopie smazané, `git status tests/eval` prázdný, žádný
  dočasný kořen nezůstal.
- **Převedené testy:**

| Test | Co předpokládal o `tests/eval/` | Změna |
|------|--------------------------------|-------|
| `EvalCatalogTest::testRunsAreListedNewestFirstWithBasename` | ≥ 13 běhů plátce, nejnovější `2026-10-20-*`, pojmenovaný `2026-10-16-baseline.json` není nad aktuální sadou | nad fixturou: přesně 3 běhy v pořadí podle `startedAt`, `_basename`, `format`, `run()` jen přesná shoda, `currentHash()` = hash fixtury a shoda s `set.hash`, sada bez složky výsledků `[]` |
| `EvalCatalogTest::testUnknownOrTraversalNamesResolveToNothing` | sada `cz-vat-payer` v checkoutu | nad fixturou (provider beze změny, běh `2026-10-16-baseline.json` ve fixtuře) |
| `EvalCatalogTest::testIgnoresDemoDatasetsOfTheSeedCatalog` | vlastní kořen s inline manifestem a ručním `rmdir` | manifesty i úklid přes fixturu, tvrzení beze změny |
| `DevTestingControllerTest::testDatasetsListIncludesRunCounts` | ≥ 13 běhů, `lastRunAt` od `2026-10-20`, spolek 0 běhů | nad fixturou: přesně 3 / `2026-10-16T10:00:00+00:00` / 0 a `lastRunAt null`, pořadí sad, navíc `datasets/{sada}/runs` s `currentHash` a `currentSet` true / true / false |
| `DevTestingControllerTest::testMessageAcrossRunsAndCompare` | ≥ 13 řádků dokladu napříč běhy | oba pojmenované běhy jsou v seznamu; zbytek (pojmenované soubory) beze změny |
| `EvalRunSummaryTest::testMessageAcrossRuns` | ≥ 13 řádků napříč běhy | oba pojmenované běhy jsou v seznamu; zbytek beze změny |
| `EvalCatalogTest::testListsDatasetsByManifestName` | nic o `tests/eval` (čte `tests/datasets`) | beze změny |
| `EvalRunComparisonTest`, `EvalOutputReconstructionTest` | jen pojmenované běhy `2026-10-15-baseline`, `2026-10-15-sonnet-5-5`, `2026-10-16-baseline` | beze změny (D106 to dovoluje) |
| `EvalCostEstimatorTest`, `DevJobsControllerTest` | řetězec cesty v syntetických datech, nic nečtou | beze změny |
| `EvalRunStoreTest`, `DatasetE2eCommandTest`, `E2ePreflightTest`, `DevDashboardControllerTest`, `DevEvalControllerTest`, `DatasetCatalogTest` | vlastní dočasné kořeny (`tests/eval` prázdný nebo kopie `variants/baseline.jsonc`) | beze změny |
| `AiEvalCommandTest`, `EvalVariantTest` | pojmenovaná varianta `variants/baseline.jsonc` | beze změny |

- `docs/test-corpus.md` § 18 „Prohlížeč výsledků“: věta o testech nad
  vlastním kořenem a pojmenovaných bězích.

## Pasti

- **Kopie z kroku „Zkouška nezávislosti“ nesmí zůstat v pracovním stromu.**
- **Nepřepisovat historické běhy** v `tests/eval/` kvůli testům.
