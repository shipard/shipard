# invoice-generator — sdílený generátor fiktivních dokladů

Balík `invoicegen` (jen standardní knihovna Pythonu 3.12+) sdílený třemi
vstupními body:

- `demo/invoices/build.py` — demo faktury pro video (`tasks/demo-invoices.md`),
- `tests/corpus/cz/build.py` — korpus testovacích dokladů s očekávaným
  výsledkem (`tasks/testing-phase1.md`, `docs/test-corpus.md`),
- `demo/datasets/build.py` — demo datové sady z příběhu firmy
  (`tasks/demo-dataset-phase1.md`, `demo/datasets/README.md`).

| Modul | Obsah |
|---|---|
| `invoicegen/jsonc.py` | `load_jsonc` — komentáře `//`, trailing čárky, čísla jako `Decimal` |
| `invoicegen/checks.py` | kontrolní součty IČO, mod 11 čísel účtů, IBAN; validace identit |
| `invoicegen/compute.py` | výpočet řádků, rekapitulace DPH a součtů v `Decimal` (ROUND_HALF_UP) |
| `invoicegen/render.py` | formátování pro tisk, HTML ze šablony `{{key}}`, PDF přes Gotenberg |
| `invoicegen/expected.py` | očekávaný výsledek = papírová projekce canonicalu (`tests/corpus/projection.jsonc`), odvozená pole podle promptu, zapisovač JSON bez float |
| `invoicegen/registries.py` | ověření identit v ARES a VIES (síť, jen `build.py --verify-identities`), přepis `verifiedAt` |
| `invoicegen/corpus.py` | datový soubor korpusu: validace, přepisy per profil (`overrides`, alias), vynechané buňky, matice doklad × profil, jazyk šablon (`TEMPLATE_LOCALE`) |
| `invoicegen/datasetio.py` | zápis sady jako PHP: `dumps_dataset` (JSON jako `DatasetWriter`), `slug` jako `Slug::make`, `file_name`, `prune`, `php_json_numbers` (bloby analýz), `manifest` (pořadí klíčů `DatasetManifest`), `attachment_entry`, `sync_tree` (zápis jen změněných bajtů, úklid spravovaných sekcí) |
| `invoicegen/snapshot.py` | snímek ideální analýzy a obálka zprávy ve tvaru `MailExporter` bez vazby na korpus: `dataset_canonical`, `analysis_json`, `classification_json` (zpráva bez dokladu s pozorností), `analysis_record`, `message_record` |
| `invoicegen/dataset.py` | datové sady z korpusu (`tasks/testing-phase2.md`): základ profilu, vlastní firma, zpráva s ideální analýzou per buňka (adaptér nad `snapshot.py`), deterministické sestavení `tests/datasets/cz/<profil>/` vč. `expected/` pro `dataset-verify`; přesunutá jména re-exportuje |
| `invoicegen/demoprint.py` | hodnoty pro demo šablony faktur (`invoice_values`, dřív v `demo/invoices/build.py`), převod kanonické identity na plochý tvar dema (`flat_party`), hodnoty pro šablony dokumentů spisovny (`registry_values`) |
| `invoicegen/catalogs.py` | číselníky Shipardu z repozitáře pro validaci příběhu: osnova, nabídka účetních položek, jednotky a zkratky, druhy položek, taxonomie štítků, druhy dokumentů spisovny, účely odeslání, šablony |
| `invoicegen/timeline.py` | kotva a relativní data (`period_for`, `month_date`, `in_period`, `quarter_offsets`, fiskální rok), deterministická rozmanitost bez RNG (`spread`, `spread_decimal`), razítka a popisky období |
| `invoicegen/collation.py` | řazení jako MariaDB `utf8mb4_czech_ci` (`czech_key`: č, ch, ř, š, ž jako samostatná písmena, diakritika a velikost písmen až na dalších úrovních) a `robust_order` — jména, o jejichž pořadí rozhoduje jen diakritika nebo interpunkce |
| `invoicegen/story.py` | příběh firmy demo sady: `load_story` (soubory `story`, `partners`, `items`, `registry`, `mail`, `setup/`), validace (identity přes `checks.py`, odkazy, období, vyvážené mzdy, řazení jmen, překryv s identitami dema a korpusu), `identities_for_checks` pro ARES / VIES |
| `invoicegen/model.py` | mezimodel rozvinutého příběhu (`DocRec`, `RowRec`, `MailRec`, `RegistryRec`, `PersonRec`, `Records`) |
| `invoicegen/expand.py` | rozvinutí příběhu: vydané doklady z pravidel, přijaté faktury se zprávami a PDF, prodejky a výdajové pokladní doklady, mzdy, zprávy k řešení, spisovna; číslování podle `docTypes.jsonc` (`format_number`, `assign_numbers`), model plateb (`payment_events`, D8) |
| `invoicegen/doccalc.py` | výpočet dokladu jako `DocDocument::computeAmounts`: řádkové `computed`, rekapitulace metodou z hlavičky, dorovnání řádků na rekapitulaci, součty, zaokrouhlení na koruny, obrat MD u účetního dokladu |
| `invoicegen/dumpshape.py` | záznamy ve tvaru `dataset-dump` — jediné místo s konstantami z PHP exportérů (pořadí klíčů, float vs. int, prune, řazení souborů a slugy per sekce) |
| `invoicegen/pdfcache.py` | PDF přes Gotenberg jen při změně HTML (hash v `build-state.json` mimo sadu), jinak bajty z cílové složky |
| `invoicegen/demo.py` | sestavení demo sady: `build_demo_dataset` (příběh → záznamy → soubory → `sync_tree`), manifest s `country`, `provision` a `counts` všech sekcí |
| `invoicegen/reporting.py` | očekávání pro ověření zaúčtování (`tasks/testing-phase3.md`): mapování variant na řádky přiznání DPH a oddíly KH (`tests/corpus/cz/vat-reporting.jsonc`), záznamy `expected/vat.json` a `expected/costs.json` v `Decimal` nezávisle na Shipardu |

Vstupní body si přidávají `tools/invoice-generator` na `sys.path` a balík
importují; žádná instalace. Gotenberg: env `GOTENBERG_URL`
(default `http://10.199.6.210:3000`, `docs/render.md`).

## Testy

```bash
cd tools/invoice-generator && python3 -m unittest
```

`tests/test_demo_regression.py` hlídá, že demo kolekce dává stále stejné
HTML (sha256) a součty — demo se nemění, generátor se jen rozšiřuje.
Testy demo sady (`test_story`, `test_expand`, `test_demo`, …) používají
jako fixture skutečný příběh `demo/datasets/stories/it-servis/` a PDF
renderují falešným rendererem; sestavení s Gotenbergem dělá jen
`demo/datasets/build.py`.
