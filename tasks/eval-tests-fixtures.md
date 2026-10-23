# Testy katalogu evaluací nezávislé na `tests/eval/`

**Stav:** naplánováno — zadání 2026-10-23 (#103 D106)

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

- [ ] `vendor/bin/phpunit --filter 'EvalCatalogTest|DevTestingControllerTest|EvalRunComparisonTest|EvalCostEstimatorTest|EvalRunStoreTest|DevJobsControllerTest|DatasetE2eCommandTest|E2ePreflightTest'` projde.
- [ ] **Zkouška nezávislosti:** dočasně zkopírovat libovolný běh do
      `tests/eval/cz-vat-payer/2099-01-01-probe.json`
      a `tests/eval/cz-association/2099-01-01-probe.json`, pustit stejný
      filtr → projde; kopie smazat (necommitovat). Výsledek v závěrečné
      zprávě.
- [ ] Tabulka převedených testů v závěrečné zprávě.
- [ ] `**Stav:**`, index tasků.

## Pasti

- **Kopie z kroku „Zkouška nezávislosti“ nesmí zůstat v pracovním stromu.**
- **Nepřepisovat historické běhy** v `tests/eval/` kvůli testům.
