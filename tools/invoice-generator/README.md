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
| `invoicegen/dataset.py` | datové sady z korpusu (`tasks/testing-phase2.md`): základ profilu, vlastní firma, zpráva s ideální analýzou per buňka, zapisovač JSON jako PHP `DatasetWriter`, slug jako `Slug::make`, deterministické sestavení `tests/datasets/cz/<profil>/` |

Vstupní body si přidávají `tools/invoice-generator` na `sys.path` a balík
importují; žádná instalace. Gotenberg: env `GOTENBERG_URL`
(default `http://10.199.6.210:3000`, `docs/render.md`).

## Testy

```bash
cd tools/invoice-generator && python3 -m unittest
```

`tests/test_demo_regression.py` hlídá, že demo kolekce dává stále stejné
HTML (sha256) a součty — demo se nemění, generátor se jen rozšiřuje.
