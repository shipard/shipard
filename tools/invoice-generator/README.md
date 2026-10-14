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

Vstupní body si přidávají `tools/invoice-generator` na `sys.path` a balík
importují; žádná instalace. Gotenberg: env `GOTENBERG_URL`
(default `http://10.199.6.210:3000`, `docs/render.md`).

## Testy

```bash
cd tools/invoice-generator && python3 -m unittest
```

`tests/test_demo_regression.py` hlídá, že demo kolekce dává stále stejné
HTML (sha256) a součty — demo se nemění, generátor se jen rozšiřuje.
