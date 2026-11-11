# Report — popisek sloupce s číslem podle definice reportu (D68)

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady, D68)

## Kontext / Cíl

Bod checklistu #133 „Přiznání k DPH v reportu“, zjištěný v tasku
`design-system-report-table.md` (D63). Pole `account` v `ReportRow` nese
u účtových reportů číslo účtu, ale DPH reporty do něj dávají jiné
identifikátory:

- **Přiznání k DPH — živě** (`economy.vat.returnLive`) — číslo řádku
  přiznání (1, 5, 40, …);
- **Kontrolní hlášení — živě** (`economy.vat.controlStatementLive`) — kód
  oddílu (A1, B2, …) u součtových řádků oddílů.

Obrazovka (`ReportView`, D63) i export (`ReportTabularizer`, #83 D72) nad
sloupcem pak píšou „Účet“.

**Cíl:** popisek sloupce určuje definice reportu; bez něj „Účet“ jako dnes.

**Mimo rozsah:**

- přejmenování pole `account` v `ReportRow` / `ReportResult` (kontrakt
  API, MCP a exportu zůstává);
- vzhled sloupce (D63) a pravidlo „sloupec jen když některý řádek má
  `account`“ — beze změny;
- dvojjazyčné texty uvnitř DPH builderů (`$cs ? … : …`) — task F2.

## Návaznost

- **#133** — D63 (sloupec Účet), D68; checklist „Další nesoulady“
- **#83** D72 — export (`ReportTabularizer`, `ReportExportLabels`)
- `tasks/design-system-report-table.md` — Odchylky (Přiznání k DPH)

## Před implementací přečti

- `docs/reports.md` §3.1 (tvar `ReportResult`), §4 (deklarativní definice),
  §7.3 (MCP), §15.2 (pravidla převodu exportu)
- `src/Core/Reports/ReportDefinition.php` (`fromArray()`, validace polí),
  `ReportRunner.php`, `ReportResult.php`
- `src/Api/ReportDefinitionLoader.php` — definice se lokalizují
  `ConfigLocalizer` před `fromArray()`
- `src/Core/Reports/Export/ReportTabularizer.php` (`hasAccount`,
  hlavička), `ReportExportLabels.php`
- `frontend/src/components/reports/ReportView.svelte` (hlavička sloupce
  `reports.column.account`)
- `modules/economy/vat/config/reports.jsonc`

## Rozhodnutí (potvrzené v #133)

- ✓ **D68** Popisek sloupce s číslem v reportu (pole `account`) určuje
  definice reportu (`reports.jsonc`, s `name:cs` / `name:en`), výchozí
  „Účet“ — obrazovka i export. Přiznání k DPH — živě „Řádek“ / „Line“,
  Kontrolní hlášení — živě (kódy oddílů) „Oddíl“ / „Section“.

## 1. Inventura

Projdi všechny buildery (`modules/*/*/src/Reports/*Builder.php` a další
třídy, které vyrábějí `ReportRow`) a vypiš, které plní `account` něčím
jiným než číslem účtu. Odhad: jen dva DPH reporty výše; Souhrnné hlášení
`account` neplní. Najdeš-li další, doplň jim popisek stejně a zapiš do
odchylek.

## 2. Definice

- Nové volitelné pole definice `accountColumn`:
  `{"name": "Line", "name:cs": "Řádek", "name:en": "Line"}` — stejný tvar
  lokalizovaného textu jako `name` reportu. Po `ConfigLocalizer` je v něm
  `name` v jazyce požadavku.
- `ReportDefinition`: `public readonly ?string $accountLabel` z
  `accountColumn.name`; validace — objekt s neprázdným řetězcem `name`,
  jinak `InvalidArgumentException` se jménem reportu (vzor ostatních polí).
- `reports.jsonc` modulu `economy.vat`: `returnLive` „Line“ / „Řádek“,
  `controlStatementLive` „Section“ / „Oddíl“; komentář u pole (proč).

## 3. Výsledek, UI a export

- `ReportResult`: volitelné `accountLabel` (`?string`, výchozí `null`);
  `toArray()` ho uvádí jen když není `null` (tvar pro účtové reporty se
  nemění). `ReportRunner` ho doplní z definice — builder o něm neví.
  Pokud ho do immutabilního výsledku nejde doplnit bez zásahu do všech
  builderů, zvol nejmenší cestu (např. `withAccountLabel()`) a zapiš do
  odchylek.
- `ReportView.svelte`: hlavička `result.accountLabel ?? t('reports.column.account')`.
- `ReportTabularizer`: hlavička `$result->accountLabel ?? $labels->get('account')`.
- MCP a REST dostanou pole automaticky přes `toArray()` — ověř, že žádný
  test tvaru výsledku nepadá na novém klíči jen u DPH.

## 4. Dokumentace

- `docs/reports.md` §3.1 (`accountLabel` ve výsledku, kdy chybí), §4
  (pole `accountColumn` definice, D68), §15.2 (hlavička exportu).
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `feat(reports): popisek sloupce s číslem podle definice reportu (#133, D68)` — kroky 1–3
2. `docs: popisek sloupce s číslem v reportu (#133, D68)` — krok 4 +
   `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `php -l`; PHPUnit s úzkým filtrem (`ReportCoreTest`, testy
  `ReportDefinition`, `ReportTabularizerTest`, testy DPH builderů podle
  názvu); `cd frontend && npm run build`, `npm test`.
- Unit testy: definice s `accountColumn` i bez něj, neplatný tvar →
  výjimka; `toArray()` bez klíče u `null`; tabularizer s vlastním
  popiskem i bez něj.
- Zdroj dat `free` s DPH daty (podle `shpd-server ds-list --json`):
  Přiznání k DPH — živě a Kontrolní hlášení — živě v `cs` i `en` —
  hlavička „Řádek“ / „Line“, „Oddíl“ / „Section“; Hlavní kniha dál „Účet“ /
  „Account“; export XLSX / CSV přiznání s novou hlavičkou, export Hlavní
  knihy před a po shodný (kromě času vytvoření).

## Hotovo když

- [ ] Definice reportu umí vlastní popisek sloupce s číslem, výchozí
      „Účet“ beze změny
- [ ] Přiznání k DPH a Kontrolní hlášení mají správnou hlavičku na
      obrazovce i v exportu, v obou jazycích
- [ ] Účtové reporty beze změny (obrazovka, export, tvar výsledku)
- [ ] Dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- Definice se lokalizují před `fromArray()` — `ReportDefinition` dostává
  už jen `name`, žádné `name:cs`; nečti jazykové varianty sám.
- Kompilace konfigurace: změna `reports.jsonc` se na zdroji projeví po
  `ds-upgrade` — spouštěj ho jen na zdrojích s třídou `free`.
- `toArray()` čtou i MCP a kontrolní diff (`ReportDiff`) — nový klíč jen
  když je hodnota, aby se nezměnil tvar ostatních reportů.
