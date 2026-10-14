# Fiktivní přijaté faktury pro demo videa

Generátor PDF faktur pro hero video (scénář S1, `shpd-web/docs/demo-scenare.md`)
a demo datové sady. Zadání a rozhodnutí: [`tasks/demo-invoices.md`](../../tasks/demo-invoices.md).

## Přegenerování

```bash
python3 demo/invoices/build.py
```

`build.py` je tenký vstupní bod nad sdíleným generátorem
[`tools/invoice-generator/`](../../tools/invoice-generator/README.md)
(balík `invoicegen`, jen stdlib): JSONC, kontrolní součty IČO, mod-11
čísel účtů a IBAN check digits, per-řádek totály, rekapitulace DPH po
sazbách a celkové součty (Decimal, ROUND_HALF_UP) i render přes Gotenberg
(Chromium) — env `GOTENBERG_URL`, default `http://10.199.6.210:3000`
(dev render služba, `docs/render.md`). Výstup je `out/NNNN-<slug>.pdf`.
Tentýž generátor používá korpus testovacích dokladů `tests/corpus/cz/`
(`docs/test-corpus.md`); výstup dema se jeho rozšiřováním nemění
(regresní test `tools/invoice-generator/tests/test_demo_regression.py`).

## Struktura

| Cesta | Obsah |
|---|---|
| `suppliers.jsonc` | Identity — vlastní firma (odběratel) + 4 dodavatelé |
| `data/NNNN-<slug>.jsonc` | Data faktur — struktura blízko canonical `shpd.docs.document.v1`, aby soubor mohl později sloužit jako expected result pro AI eval (#40) |
| `templates/{a,b,c}.html` | HTML šablony, placeholdery `{{key}}`; **a** moderní, **b** strohý tabulkový, **c** „starší ekonomický systém" |
| `../../tools/invoice-generator/` | Sdílený generátor (`invoicegen`): JSONC, kontrolní součty, výpočty, render — `build.py` ho jen importuje |
| `out/*.pdf` | Vyrenderovaná PDF — **commitují se** (artefakt pro video, binárky v gitu vědomě, viz D13 v `shpd-web/docs/demo-vyroba.md`) |

Bloky řádků a rekapitulace skládá `build.py` a vkládá přes `{{rows}}` /
`{{vatRecap}}` — markup je jednotný, vizuální odlišení dělá čistě CSS
šablon. V šablonách nesmí být JS (Gotenberg ho má globálně vypnutý).

## Identity

Všechny subjekty jsou **fiktivní** — IČO mají validní kontrolní součet
a k 2026-09-01 byla ověřena jako neexistující v ARES, názvy měly 0 zásahů,
domény dodavatelů byly bez A záznamu. Detaily a rezervní IČO pro
rozšiřování sady: komentáře v `suppliers.jsonc`.

Tahle „volnost" je snapshot — při budoucí kolizi (IČO přiděleno, doména
registrována) identitu vyměnit a PDF přegenerovat. Identity korpusu
testovacích dokladů (`tests/corpus/cz/identities.jsonc`) jsou záměrně jiné
subjekty — demo a korpus se nesmí překrývat, build korpusu to kontroluje.

**POZOR:** doména `hoblinka.cz` (vlastní firma) patří cizímu subjektu —
e-mail ani web odběratele se na PDF nesmí objevit; šablony pro ně ani
nemají placeholder.

## Vazba na demo sady

PDF později převezme sada z #40 (`demo/datasets/`) jako přílohy
`mail/NNNN-*.files/`; identity ze `suppliers.jsonc` se propíšou do
`setup/` demo sady (vlastní firma) a datové soubory poslouží jako
expected results pro AI eval.

## Fixní datumy

Datumy faktur jsou fixní (srpen/září 2026) — `dateMode: relative` zatím
neexistuje. Před natáčením zkontrolovat, že splatnosti dávají na
dashboardu smysl; případně datumy posunout a přegenerovat.
