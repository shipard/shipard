# Reporty — export do XLSX a CSV

**Stav:** naplánováno — rozhodnutí 2026-10-01 (#83 D72); implementace nezačala

> PRD pro jednu Claude Code session (4 commity). Design: `docs/reports.md`
> (§2 „jeden výpočet, N prezentací“, §3 `ReportResult`, §7, §14);
> rozhodnutí `docs/assets.md` D72. Musí předcházet `tasks/assets-phase5.md`.

## Kontext

Reporty (hlavní kniha, výsledovka, rozvaha, výstupy DPH, nově majetek)
se dají jen zobrazit v aplikaci, volat přes REST / MCP a porovnat CLI
`report-diff`. Účetní je potřebují dál zpracovat v tabulkovém
procesoru — export chybí. `ReportResult` je plochý seznam řádků
s typovanými sloupci, takže export je další renderer nad týmž
výsledkem (§2), obecný pro všechny reporty.

## Cíl

1. Renderer `ReportResult` → **XLSX** (knihovna OpenSpout) a **CSV**.
2. REST: `GET /_reports/{reportId}?…&format=xlsx|csv` vrátí soubor.
3. UI: tlačítko **Export** na stránce reportu (XLSX / CSV).
4. CLI: `report-run … --format=json|xlsx|csv [--output=<soubor>]`.

## Před implementací přečti

- `docs/reports.md` celé (hlavně §3, §7, §10–§14)
- `src/Core/Reports/ReportResult.php`, `ReportColumn.php`, `ReportRow.php`,
  `ReportRowKind.php`, `ReportRunner.php`
- `src/Api/Controller/ReportsController.php`, routy `/_reports` v
  `src/Api/Router.php`, `src/Api/ReadOnlyPolicy.php`
- `src/Command/DataSource/ReportRunCommand.php` (CLI `report-run`)
- `frontend/src/components/reports/ReportsPage.svelte`, `ReportView.svelte`
  (formát čísel, „v tisících“, `display: sides`, text/date sloupce)
- vzor stahování souboru ve frontendu (přílohy — `api/` wrapper s blobem)

## Scope

**Uvnitř:** závislost `openspout/openspout`, `ReportXlsxWriter`,
`ReportCsvWriter`, REST `format`, tlačítko Export, CLI `--format`,
testy, dokumentace.
**Mimo:** PDF a tisk reportů (samostatná tisková doména); grafy; export
mimo reporty (viewery).

## Pravidla převodu

Společná pro oba formáty (`ReportTabularizer` — `ReportResult` →
hlavičky + řádky buněk, ať se pravidla neliší mezi XLSX a CSV):

- **Úvod** (jen XLSX, list „Report“): název reportu, období (lidsky,
  stejně jako v UI), parametry, datum vygenerování, zdroj dat (název
  firmy, ne ID). Prázdný řádek, pak tabulka.
- **Sloupce:** první sloupec popisek řádku (`label`, u účetních reportů
  i účet — sloupec Účet zvlášť, je-li `account`). Sloupec `money`
  s `display: balance` = jedna číselná buňka (zůstatek dle strany
  účtu); `display: sides` = tři sloupce MD / D / Zůstatek s hlavičkou
  „{label} — MD“ atd. `text` = text, `date` = datum.
- **Řádky:** všechny řádky výsledku v pořadí; odsazení podle `level`
  (XLSX: indent stylu buňky; CSV: bez odsazení). `subtotal` / `total` /
  `computed` tučně (XLSX).
- **Čísla:** vždy přesné hodnoty (přepínač „v tisících“ se do exportu
  nepromítá — tabulkový procesor si dělí sám; v úvodu poznámka).
  XLSX číselný formát `# ##0.00`, datum jako datum (ne text).
- **Stav a zprávy:** `status != ok` → v úvodu XLSX řádek „Report obsahuje
  chyby / varování“ a druhý list „Zprávy“ (závažnost, kód, text, řádek).
  CSV zprávy nenese — REST hlavička `X-Report-Status`.
- **CSV:** UTF-8 s BOM (Excel), oddělovač `;`, desetinná čárka, datum
  `YYYY-MM-DD`, první řádek hlavička; jen tabulka.
- **Název souboru:** `{slug názvu reportu}-{období}.xlsx|csv`
  (např. `hlavni-kniha-2026-05.xlsx`), `Content-Disposition: attachment`.

## REST

`ReportsController`: parametr `format` (`json` default, `xlsx`, `csv`);
neplatný → 400. Pro `xlsx` / `csv` binární odpověď mimo JSON obálku,
správný `Content-Type`; chyby validace parametrů dál JSON 400.
`ReadOnlyPolicy` beze změny (čtení). MCP `report_run` export nenabízí.

## UI

`ReportsPage` / `ReportView`: tlačítko **Export** (dropdown XLSX / CSV)
v liště parametrů, aktivní po načtení výsledku; stáhne soubor se
stejnými parametry jako zobrazený report. i18n klíče cs / en
(`npm run check:i18n`).

## CLI

`report-run`: `--format=json|xlsx|csv` (default json — beze změny),
`--output=<soubor>` (u xlsx povinné; csv bez `--output` na stdout).

## Testy

- `ReportTabularizerTest` — balance i sides sloupce, text/date, úrovně,
  druhy řádků, zprávy.
- `ReportCsvWriterTest` — BOM, `;`, desetinná čárka, escapování.
- `ReportXlsxWriterTest` — soubor se otevře (OpenSpout reader), typy
  buněk (číslo / datum / text), tučné součty, list Zprávy.
- `ReportsControllerTest` — `format`, hlavičky, 400.
- CLI `--format`.
- `vendor/bin/phpunit --filter 'Report'`; `cd frontend && npm run build
  && npm run check:i18n`.

## Task breakdown

1. **Závislost a tabularizace** — `composer require openspout/openspout`,
   `ReportTabularizer`, `ReportCsvWriter`, `ReportXlsxWriter` + testy.
2. **REST a CLI** — `format`, hlavičky, název souboru, `--format`,
   `--output` + testy.
3. **UI** — tlačítko Export, stahování, i18n.
4. **Dokumentace** — `docs/reports.md` nová sekce Export (§15),
   `docs/cli.md`, `help/` (stránka reportů, je-li — jinak zmínka
   v `help/uctarna/`), Stav tasku + `python3 scripts/tasks-index.py`.

*Hotovo když* hlavní kniha, výsledovka, rozvaha a kontrolní hlášení
na ukázkovém DS jdou stáhnout jako XLSX i CSV, XLSX se otevře
v LibreOffice / Excelu s čísly jako čísly a tučnými součty, CSV se
správně načte v Excelu s českým nastavením.

## Rozhodnutí k designu (potvrzená)

- ✓ D72 Obecný export všech reportů do XLSX (OpenSpout) a CSV; PDF
  patří do tiskové domény.
