# invoice-generator — sdílený generátor fiktivních dokladů

Balík `invoicegen` (jen standardní knihovna Pythonu 3.12+) sdílený dvěma
vstupními body:

- `demo/invoices/build.py` — demo faktury pro video (`tasks/demo-invoices.md`),
- `tests/corpus/cz/build.py` — korpus testovacích dokladů s očekávaným
  výsledkem (`tasks/testing-phase1.md`, `docs/test-corpus.md`).

| Modul | Obsah |
|---|---|
| `invoicegen/jsonc.py` | `load_jsonc` — komentáře `//`, trailing čárky, čísla jako `Decimal` |
| `invoicegen/checks.py` | kontrolní součty IČO, mod 11 čísel účtů, IBAN; validace identit |
| `invoicegen/compute.py` | výpočet řádků, rekapitulace DPH a součtů v `Decimal` (ROUND_HALF_UP) |
| `invoicegen/render.py` | formátování pro tisk, HTML ze šablony `{{key}}`, PDF přes Gotenberg |
| `invoicegen/expected.py` | očekávaný výsledek = papírová projekce canonicalu (`tests/corpus/projection.jsonc`), odvozená pole podle promptu, zapisovač JSON bez float |
| `invoicegen/registries.py` | ověření identit v ARES a VIES (síť, jen `build.py --verify-identities`), přepis `verifiedAt` |
| `invoicegen/corpus.py` | datový soubor korpusu: validace, přepisy per profil (`overrides`, alias), vynechané buňky, matice doklad × profil, jazyk šablon (`TEMPLATE_LOCALE`) |
| `invoicegen/rates.py` | kurzovní lístky korpusu (`tasks/testing-rates-isdoc.md` D72, D73): účetní datum dokladu (DUZP, bez něj vystavení), zrcadlo `ExchangeRateResolver` (poslední lístek ≤ D, nejvýš 7 dní, měna v něm, kurz za jednotku na 6 míst), výběr použitých a rezervních lístků, stažení ze služby kurzů jen pro `build.py --fetch-rates` (síť) |
| `invoicegen/dataset.py` | datové sady z korpusu (`tasks/testing-phase2.md`): základ profilu, sekce `exchangeRates/` ve tvaru exporteru, vlastní firma, zpráva s ideální analýzou per buňka (u podoby `pdfIsdoc` s ISDOC jako druhou přílohou), zapisovač JSON jako PHP `DatasetWriter`, slug jako `Slug::make`, deterministické sestavení `tests/datasets/cz/<profil>/` vč. `expected/` pro `dataset-verify` |
| `invoicegen/reporting.py` | očekávání pro ověření zaúčtování (`tasks/testing-phase3.md`): mapování variant na řádky přiznání DPH a oddíly KH (`tests/corpus/cz/vat-reporting.jsonc`), záznamy `expected/vat.json`, `expected/costs.json` (v Kč kurzem k účetnímu datu) a `expected/exchange-rates.json` v `Decimal` nezávisle na Shipardu |

Vstupní body si přidávají `tools/invoice-generator` na `sys.path` a balík
importují; žádná instalace. Gotenberg: env `GOTENBERG_URL`
(default `http://10.199.6.210:3000`, `docs/render.md`).

## Testy

```bash
cd tools/invoice-generator && python3 -m unittest
```

`tests/test_demo_regression.py` hlídá, že demo kolekce dává stále stejné
HTML (sha256) a součty — demo se nemění, generátor se jen rozšiřuje.
