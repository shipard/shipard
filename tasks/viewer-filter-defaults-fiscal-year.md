# Viewer — výchozí hodnoty filtrů; období v saldokontu s výchozím aktuálním rokem

**Stav:** hotovo — 2026-09-14 (3 commity; odchylky viz „Poznámky k implementaci"); zbývá ruční proklik UI + alfa

## Kontext

Viewer saldokonta po případech (`economy.accbal.cases`, T2) i pohyby
(`economy.accbal.ledger`) zobrazují všechna účetní období naráz. Případ je od
D11 vázaný na období, takže ruční kontrola očima je bez filtru období
nemožná — uživatel vidí tentýž klíč v deseti letech pod sebou. Požadavek:
**filtr období (fiskální rok) s výchozí hodnotou = aktuální fiskální rok**.

Framework filtrů (`meta.filters` → `Viewer.svelte` → `ViewerFilters.svelte`)
výchozí hodnoty neumí; T2 to obcházel obráceným checkboxem „Včetně
uzavřených". `JournalViewer` má filtr období jako `select`, také bez výchozí
hodnoty. Řešení je obecné: **`default` v definici filtru**, ne další obezlička.

## Před implementací přečti

- `docs/frontend.md` §„Filtry vieweru" (`meta.filters`, `filter[<id>]`,
  `ViewerFilters.svelte`) a §`open_viewer` / `pendingFilters` (z T2:
  `docs/frontend.md`, `docs/dashboard.md`).
- `frontend/src/components/viewer/Viewer.svelte` — `activeFilters`,
  `SUPPORTED_FILTER_TYPES`, blok `consumePendingFilters()` (~ř. 876) a první
  fetch.
- `modules/economy/accbal/src/CasesViewer.php::getFilters()`,
  `LedgerViewer.php`, `AccbalViewerBase.php`; akce „Pohyby případu"
  (`open_viewer` s `filters`).
- `modules/economy/accounting/src/JournalViewer.php::getFilters()` — vzor
  selectu období z `economy_codebooks_fiscal_years`.
- `src/Core/Reports/FiscalPeriodProvider.php` + `DbFiscalPeriodProvider.php`
  (`regularYears()`).
- Tabulka `economy_codebooks_fiscal_years` (`date_begin`, `date_end`,
  `docState`, regulární vs. otevírací/uzavírací období).

## 1. Framework: `default` v definici filtru

- Definice filtru může nést `'default' => <hodnota>` (string pro `text` /
  `select`, `'1'` pro `checkbox`). Backend nic dalšího nedělá — hodnota jde
  klientovi v `meta.filters`.
- `Viewer.svelte`: při načtení meta sestaví `activeFilters` = výchozí hodnoty
  všech podporovaných filtrů, které `default` mají; **`pendingFilters`
  z `open_viewer` se do toho slévají a vítězí** (`{...defaults,
  ...pending}`), takže volající, který chce jiné období, ho musí poslat
  explicitně. První fetch jde už s výsledným objektem.
- Uživatel může výchozí hodnotu smazat/změnit jako každou jinou; „reset
  filtrů" (pokud existuje) vrací na výchozí, ne na prázdno.
- Zápis do `docs/frontend.md` (§ Filtry vieweru: klíč `default`, precedence
  pending > default) a `docs/viewer-grid.md`, kde odkazuje na filtry.

## 2. Aktuální fiskální rok

`FiscalPeriodProvider::yearForDate(string $date): ?array` (+ implementace
v `DbFiscalPeriodProvider`): regulární rok (ne otevírací/uzavírací,
`docState != 90`) s `date_begin <= date <= date_end`. Fallback ve viewerech:
není-li rok pro dnešek, nejnovější regulární rok (`regularYears()` první).
Viewery provider dostanou stejnou cestou, jakou ho dostávají reporty
(ověř konstrukci vieweru / DI; když provider není dostupný, čti tabulku
přímo jako `JournalViewer`, ale helper pro „aktuální rok" ať je jeden).

## 3. Viewery

- **`CasesViewer`**: filtr `fiscal_year` (`select`, roky sestupně jako
  `JournalViewer`, `default` = aktuální rok) jako **první** filtr. `selectRows`
  i `renderGridFooter` ho promítnou do `WHERE c.fiscal_year = %i`
  (footer dostává stejné filtry — stačí sdílená podmínka). Sloupec „Období"
  zůstává (užitečný, když uživatel filtr uvolní).
- **`LedgerViewer`**: stejný filtr, stejný default. Akce **„Pohyby případu"**
  posílá v `filters` období případu (`fiscal_year` z řádku), aby se
  z případu 2024 neotevřely pohyby filtrované na 2026 — mění rozhodnutí
  z T2 („pohyby klíče přes roky pohromadě"): pohyby přes roky jsou dostupné
  uvolněním filtru, výchozí je období případu.
- **`JournalViewer`**: doplnit `default` na existující filtr období (stejný
  helper). Nic jiného se v deníku nemění.
- Položky saldokont z navigačního provideru (chipy) a `open_viewer`
  z dashboardu procházejí beze změny — default doplní klient.

## 4. Testy

PHPUnit jen s úzkým `--filter`.

- `DbFiscalPeriodProviderTest`: `yearForDate` uvnitř roku, na hranicích,
  mimo všechny roky → null, otevírací/uzavírací období se nevrací.
- `CasesViewerTest` / `LedgerViewerTest`: meta obsahuje `fiscal_year`
  s `default`; `selectRows` a footer respektují `filter[fiscal_year]`;
  bez filtru (uvolněno) vrací všechna období jako dnes.
- Frontend: pokud existuje test runner pro Svelte (ověř `frontend/package.json`),
  test sloučení `defaults` + `pendingFilters`; jinak HTTP/UI smoke
  s popisem v poznámkách k implementaci.

## Commit strategie

1. Framework `default` (`Viewer.svelte`) + `yearForDate` + testy provideru + docs.
2. `CasesViewer` + `LedgerViewer` (filtr, footer, akce „Pohyby případu") + testy.
3. `JournalViewer` default + hlavička tasku `hotovo` + index.

## Hotovo když

- [x] viewer saldokonta se otevře s obdobím = aktuální fiskální rok, footer
      odpovídá jen tomuto období; uvolnění filtru ukáže všechna období
      (unit + integrační `CasesViewerTest`; ruční proklik zbývá)
- [x] „Pohyby případu" z případu jiného roku otevře pohyby toho roku
      (integrační test akce; ruční proklik zbývá)
- [x] deník se otevře s aktuálním rokem (`JournalViewerTest`)
- [x] `default` je popsaný v `docs/frontend.md`, precedence pending > default
      má test (`frontend/tests/Unit/viewerFilters.test.mjs`)
- [x] docs a index tasků ve stejném commitu jako kód

## Poznámky k implementaci (2026-09-14)

- **Fallback „nejnovější rok" nejde přes `regularYears()`** — vrací jen
  `name` + počet měsíců bez `id` a řadí vzestupně. Provider dostal
  `years()` (id + name, nesmazané, `date_begin DESC`), které zároveň
  nahradilo inline dotaz na roky v `JournalViewer::getFilters()`.
  `FiscalPeriodProvider` má tedy dvě nové metody (`yearForDate`,
  `years`); tři anonymní fake třídy v testech reportů dostaly stuby,
  sdílený fake je `tests/Fixtures/Reports/FakeFiscalPeriodProvider.php`.
- **Podmínka roku v `CasesViewer` je na řádkové úrovni** (`l.fiscal_year`,
  před `GROUP BY`), ne `c.fiscal_year` — rok je součást klíče, výsledek je
  totožný, ale využije se `idx_case` a agregát je menší.
- **Otevírací / uzavírací období jsou měsíce**, tabulka roků typ nemá;
  `yearForDate` se ptá jen na roky. Integrační test ověřuje, že 1. 1.
  (datum otevíracího měsíce) vrací rok.
- **DI vieweru neexistuje** (`ViewerRegistry` dělá `new $class($db,
  $table)`): trait `Core\Viewer\UsesFiscalPeriods` provider líně vytváří
  nad `$this->db` (vzor `ReportRunner`) a testy ho podstrčí přes
  `setFiscalPeriodProvider()`.
- **Nad rámec zadání:** akce „Otevřít řádek deníku" v `LedgerViewer` posílá
  `filters: {fiscal_year}` — deník má výchozí rok a cílový řádek ze
  staršího roku by jinak ze seznamu zmizel (detail by se otevřel, řádek ne).
- Frontend: sloučení defaultů s pending filtry běží až po `fetchMeta()`
  (defaulty zná jen meta), čistá funkce `initialFilterValues()` v
  `utils/viewerFilters.js`. „Reset filtrů" v UI neexistuje; volba „— vše —"
  default ruší, přepnutí vieweru ho obnoví.

## Rozhodnutí k designu (potvrzená)

- ✓ Výchozí hodnoty filtrů řeší framework (`default`), ne obrácené checkboxy
  — „Včetně uzavřených" z T2 zůstává, ale nové filtry ho nekopírují.
- ✓ Precedence: `pendingFilters` z `open_viewer` > `default`; volající si
  období určuje sám.
- ✓ Období v saldokontu = fiskální rok (ne měsíc); aktuální = rok obsahující
  dnešek, fallback nejnovější regulární.
