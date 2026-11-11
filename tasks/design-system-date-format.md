# Design system — jeden formát data na obrazovce (D73)

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady, D73)

## Kontext / Cíl

Bod checklistu #133 „Formát data“, zjištěný při ověření F1. Datum se na
obrazovce ukazuje ve čtyřech podobách podle toho, kdo ho formátuje:

- **viewery** (backend) — zhruba 30 privátních kopií `formatDate()` /
  `formatDateTime()`: ISO `2026-01-01` (Fiskální roky včetně tabu Měsíce,
  číselníky v Nastavení, Účty, Číselné řady, saldokonto, Podání a Období
  DPH), `j. n. Y` (doklady, banka, pošta, Spisovna, položky, deník, úkoly,
  AI), `d.m.Y` (Osoby, majetek, zakázky) — v angličtině stejně jako česky;
- **další backend pro obrazovku** — `SubtableCellFormatter::date()` /
  `dateTime()` (sub-tabulky formulářů a detailů, ~20 volání),
  `RecordPattern` (hlavičky detailu), `AnalysisController`, informace ve
  formulářích (`DocsHeadsFormBase`, `IncomingMessagesForm`, `ItemsForm`,
  `AssetsForm`, `PersonsForm`, `TasksForm`) a další;
- **frontend** — sedm komponent: mřížka `TableBrowser` (`01.01.2026`),
  review `reviewParts.js` a `RegistryExtractedPreview` (`Intl` cs-CZ),
  `ReportView` (`toLocaleDateString` cs-CZ / **en-US**), `CompanyOverview`
  a `DsAbout` (`dateStyle: 'medium'` — „1. led 2026“), `DsSetup`.

V detailu faktury data bez nul „skáčou“, v angličtině vychází americký
nebo český zápis.

**Cíl:** jeden formát podle jazyka (D73), tvořený na jednom místě
v backendu a na jednom ve frontendu; kontrola, aby kopie nevznikaly znovu.

**Mimo rozsah:**

- pole formuláře pro zadání data (`DateInput`) a jeho zobrazení;
- tisk (Twig), export (XLSX / CSV), výměnný formát, ISDOC, XML podání;
- **texty uložené v datech** (popis účetního zápisu, text upozornění
  uložený při běhu kontroly, poznámky) — vznikly jednou v jednom jazyce;
  jen je vypiš do odchylek, neměň;
- relativní čas („před 3 d“, `sharedText`) — beze změny;
- vývojářský dashboard (`frontend/src/dev/format.js`) — vlastní slovník
  a jazyk; pokud je převod triviální (stejné pravidlo podle jazyka
  dashboardu), smí se udělat, jinak do odchylek.

## Návaznost

- **#133** — D73; checklist „Další nesoulady“ (bod Formát data)
- `tasks/design-system-viewer-texts.md` (F1) — `TableViewer` sdílené
  helpery (`sharedText()`), Ruční ověření (zjištění)
- `tasks/design-system-small-fixes.md` — Spisovna formátuje ISO data
  (krok 3) — sjednotit
- Pořadí: po `design-system-report-account-label.md` (D68)
  a `design-system-tone-colors.md` (D69–D72) — všechny tři sahají do
  `ReportView.svelte`; F2 (dvojjazyčný zápis) až po tomto tasku nebo před
  ním, ne souběžně (stejné viewery)

## Před implementací přečti

- `src/Core/Viewer/TableViewer.php` (`$language`, `sharedText()`)
- `src/Core/Form/SubtableCellFormatter.php`
- `src/Core/Database/RecordPattern.php`
- `src/Core/I18n/` (umístění nové třídy)
- `frontend/src/utils/formatNumber.js` (vzor sdílené funkce),
  `frontend/src/stores/language.svelte.js`
- `CLAUDE.md` → Frontend — Vícejazyčnost, Backend — Vícejazyčnost

## Rozhodnutí (potvrzené v #133)

- ✓ **D73** Datum na obrazovce má jeden formát podle jazyka: čeština
  `01.01.2026` (s časem `01.01.2026 14:05`), angličtina ISO `2026-01-01`
  (s časem `2026-01-01 14:05`) — pevná délka, jednoznačné. Platí pro
  detail, seznam, mřížku, review i report na obrazovce; formát tvoří jedno
  místo v backendu a jedno ve frontendu. Pole formuláře pro zadání data,
  tisk, export a texty uložené v datech beze změny.

## 1. Backend — sdílený formát

- Nová třída `Shipard\Core\I18n\DisplayDate` (název smí být jiný, zapiš):
  `date(mixed $value, string $language): ?string` a
  `dateTime(mixed $value, string $language): ?string`.
  - vstup: `DateTimeInterface`, řetězec `Y-m-d` / `Y-m-d H:i:s` / ISO 8601,
    `null` / `''` → `null`; neparsovatelný řetězec vrátí beze změny;
  - `cs` → `d.m.Y` / `d.m.Y H:i`; `en` a cokoli jiného → `Y-m-d` /
    `Y-m-d H:i` (jazyky dokumentů `sk`, `de` na obrazovku nechodí; neznámý
    jazyk = anglicky jako fallback konfigurace);
  - časové pásmo: jako dnes (hodnoty z DB se nepřevádějí) — pokud některá
    kopie převádí, zachovej chování a zapiš.
- `TableViewer`: `protected function formatDate(mixed $value): ?string`
  a `formatDateTime()` nad `DisplayDate` s `$this->language`. Privátní
  kopie ve viewerech smaž (viewer, který dnes vrací `string` místo
  `?string`, ať si prázdnou hodnotu ošetří u volání).
- `SubtableCellFormatter::date()` / `dateTime()` — přidej parametr jazyka
  (nebo instanci s jazykem) a předej ho ze všech volání; volající bez
  jazyka (testy) dostanou výchozí `en` jen tehdy, když jinak nejde — zapiš.
- `RecordPattern` (hlavičky detailu) — jazyk z kontextu vieweru.
- Ostatní místa, která formátují datum **pro obrazovku v jazyce
  požadavku** (výčet z grepu `format('j. n. Y'`, `'d.m.Y'`, `'d. m. Y'`
  a `date('…')` mimo tisk a export): formuláře (informační řádky, popisky),
  `AnalysisController`, Feed (`MailSuggestionsSource`), validační hlášky
  s datem → `DisplayDate`. Výsledek je text v jazyce požadavku, takže
  změna je bezpečná.
- Místa, kde text s datem **vzniká jednou a ukládá se** (kontroly
  upozornění — `*Check.php`, účtování majetku — `AssetPosting*`,
  `SystemDepreciationWriter`, `DocumentApplier`, `PlanMessageTexts` podle
  toho, kam text jde) → neměnit, vypsat do odchylek s tím, kam text jde.

## 2. Frontend — sdílený formát

- `frontend/src/utils/formatDate.js`: `formatDate(value)` a
  `formatDateTime(value)` podle `language.current` — stejné výstupy jako
  backend (`01.01.2026` / `2026-01-01`, s časem `HH:mm`). Vstup ISO řetězec
  nebo `Date`; prázdný → `''` (nebo pomlčka podle volajícího, beze změny
  dnešního chování); neparsovatelný → vrátit beze změny. Bez `Intl`
  (výstup nesmí záviset na verzi prohlížeče) a bez převodu časového pásma
  u čistého data (`2026-01-01` nesmí přes `new Date()` spadnout na
  31. 12. v pásmu západně od UTC — parsuj řetězec ručně).
- Nahraď formátování data v `TableBrowser`, `reviewParts.js`,
  `RegistryExtractedPreview`, `ReportView` (`fmtText`, typ `date`),
  `CompanyOverview`, `DsAbout`, `DsSetup`; vlastní průchod
  (`toLocaleDateString`, `toLocaleString`, `Intl.DateTimeFormat`,
  `getFullYear()` mimo `utils/`) najde případné další — zapiš.
- `npm test`: jednotkové testy `formatDate.js` (cs / en, datum / čas,
  prázdné, neparsovatelné, `2026-01-01` bez posunu dne).

## 3. Kontrola

- PHP: test (vzor `ViewerSourceTextsTest`) nad `src/` a `modules/*/*/src/`
  — žádný formátovací vzor data pro zobrazení (`'j. n. Y'`, `'d.m.Y'`,
  `'d. m. Y'`, s časem i bez) mimo `DisplayDate`; povolené cesty pro tisk,
  export a uložené texty (krok 1) jako konstanta s důvodem.
- Frontend: test v `npm test`, který projde `frontend/src/components`
  a `frontend/src/utils` — `toLocaleDateString`, `toLocaleString` s datem
  a `Intl.DateTimeFormat` jen v `utils/formatDate.js` (výjimky s důvodem,
  `frontend/src/dev/` mimo).

## 4. Dokumentace

- `docs/design-system.md` — nové krátké pravidlo „Datum a čas“ (D73):
  formát podle jazyka, sdílené funkce, co je mimo; odkaz z §6 Pravidla.
- `CLAUDE.md` — Backend / Frontend Vícejazyčnost: jedna věta o
  `DisplayDate` a `utils/formatDate.js` a o kontrole.
- `docs/frontend.md` / `docs/reports.md`, kde se dnes popisuje formát data
  na obrazovce (najdi grepem), podle nového stavu.
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `feat: sdílený formát data na obrazovce (#133, D73)` — `DisplayDate`
   a `utils/formatDate.js` s testy
2. `refactor(viewer): datum ve viewerech přes sdílený formát (#133, D73)` —
   `TableViewer`, viewery, `SubtableCellFormatter`, `RecordPattern`
3. `refactor: datum ve formulářích a dalších textech pro obrazovku (#133, D73)`
4. `refactor(frontend): datum přes sdílený formát (#133, D73)`
5. `test: kontrola formátu data (#133, D73)` — krok 3
6. `docs: formát data (#133, D73)` — krok 4 + `**Stav:**` tohoto tasku +
   `python3 scripts/tasks-index.py`

## Ověření

- `php -l`; PHPUnit s úzkým filtrem (`DisplayDate`, testy dotčených viewerů
  a formulářů po třídách, `ViewerSourceTextsTest`, nová kontrola); `cd
  frontend && npm run build`, `npm test`, `npm run check:i18n`.
- Zdroj dat `free` (podle `shpd-server ds-list --json`) — snímek výstupu
  API všech viewerů (seznam, mřížka, detail) v `cs` a `en` před a po,
  stejně jako v F1: liší se jen data (a nic jiného); v `cs` všude
  `dd.mm.yyyy`, v `en` všude `yyyy-mm-dd`.
- Prohlížeč (Playwright, `cs` i `en`): detail faktury přijaté a vydané,
  Fiskální roky (detail a tab Měsíce), Osoby, banka, Došlá pošta, review
  dokladu z pošty, report s datovým sloupcem, Přehled firmy, O zdroji dat,
  mřížka seznamu.
- Časové pásmo: frontend test s `TZ=America/New_York` (nebo ekvivalent)
  — čisté datum se neposune.

## Hotovo když

- [ ] Backend formátuje datum pro obrazovku jen přes `DisplayDate`;
      privátní kopie ve viewerech zmizely
- [ ] Frontend formátuje datum jen přes `utils/formatDate.js`
- [ ] Čeština `01.01.2026`, angličtina `2026-01-01` (i s časem) všude
      v rozsahu D73
- [ ] Texty uložené v datech a mimo rozsah vypsané v odchylkách
- [ ] Obě kontroly procházejí
- [ ] Dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- `'Y-m-d'` se v PHP používá i pro SQL a pro výměnný formát — kontrola
  ho hledat nesmí a převod se týká jen hodnot, které jdou na obrazovku.
- `new Date('2026-01-01')` je půlnoc UTC — v pásmu západně od UTC dává
  31. 12.; čisté datum parsuj ručně.
- Některé kopie berou `DateTimeInterface`, jiné řetězec, jedna vrací `''`
  místo `null` — sjednocení nesmí změnit, zda se prázdná vlastnost
  v detailu ukáže („—“) nebo vynechá (`addItem` prázdné vynechává).
- `SubtableCellFormatter` je statický a volá se z formulářů i viewerů —
  ověř jazyk u obou cest, ne jen u viewerů.
- `ds-upgrade` není potřeba (žádný nový cfgItem); pokud ano, jen na
  zdrojích s třídou `free`.
