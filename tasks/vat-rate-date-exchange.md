# Task: Datum sazby DPH — task B: výměnný formát, applier, export

**Stav:** naplánováno — zadání 2026-11-17 (#159 D11)

**Issue:** #159

**Cíl:** Výměnný formát dokladu nese přepínač „Sazby DPH podle“ a vlastní
data sazby (hlavička, řádky, řádky rekapitulace). Applier určuje kód DPH
a sazbu **k datu sazby řádku** podle stejného pravidla jako dokument
(`VatRateDates`, task A) — ne k DUZP. Export dokladu (datové sady) nová
pole nese, takže dump → seed doklad s vlastním datem sazby zachová.

Navazuje na task A (`tasks/vat-rate-date-core.md`, hotovo) a končí jeho
mezistav U12. Po tasku B jde napsat import ze starého systému (runner mimo
toto repo).

## Před implementací přečti

- `tasks/vat-rate-date-core.md` — D1–D16, U1–U13 a „Poznámky
  k implementaci“ (co task A udělal jinak než zadání)
- `docs/vat-calculation.md` § 5.2 — datum sazby DPH
- `docs/exchange-format.md` — § 5 (`dates`, blok `vat`, `vatRecap`,
  opravný doklad → „Datum sazby DPH“ ~ř. 642), § 7 (`rows[].vat`),
  § 8.4 (`VatCodeResolver`, odvození kódu, volba kódu), § 9 (`_resolve`,
  tabulka issues)
- `docs/datasets.md` — co musí dump → seed zachovat
- `modules/docs/core/src/VatRateDates.php` — jediné pravidlo data sazby
- `modules/core/exchange/src/Document/DocumentApplier.php`:
  `vatContext()` (~ř. 1992; `taxPointDate` vs. `rateDate`, `codeOptions`
  ~ř. 2137, volání `decideRowVatCode()` ~ř. 2155), `decideRowVatCode()`
  (~ř. 2613), `resolveRowVatCode()` (~ř. 2800), předmět plnění
  (~ř. 2987, ~ř. 5159), převzatá rekapitulace (~ř. 3323), `transform()`
  hlavička (~ř. 1720) a `transformRows()` (~ř. 4480), `DOC_TYPE_MAP`,
  `correctionDecision()`
- `modules/core/exchange/src/Document/VatCodeDerivation.php` — `derive()`,
  `conflict()`, `options()`, `reverseChargeSubjectOptions()`
- `modules/core/exchange/src/Resolve/VatCodeResolver.php`
- `modules/core/exchange/src/Export/DocumentExporter.php` — `dates`,
  blok `vat` (~ř. 324), `rows()` (~ř. 469, vzor klíče jen s hodnotou
  u `reverseChargeCode`), `vatRecap()` (~ř. 538)
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` + ručně
  udržovaný `.json` (`SchemaDriftTest`) + kopie v
  `modules/core/mail/profiles/czech_general.jsonc` (`ProfileSchemaDriftTest`)
- `tests/Integration/Dataset/DatasetRoundTripTest.php`,
  `tests/Unit/Module/Core/Exchange/Document/DocumentApplierCorrectionTest.php`
  (vzor testů applieru s datem sazby)

## Kontext

- Task A: procento řádku i řádku rekapitulace se odvozuje z kódu a data
  sazby; datum určuje přepínač `vat_rate_source` (0 automaticky —
  opravný doklad k datu původního plnění, jinak DPPD, DUZP, datum
  vystavení; 1 vlastní datum dokladu; 2 vlastní datum na řádcích)
  a sloupce `vat_rate_date` na hlavičce, řádku a řádku rekapitulace.
- Applier dnes hledá kód a sazbu k DUZP (u opravného dokladu k datu
  původního plnění). Kód zvolený k DUZP nemusí mít sazbu k datu, ke
  kterému ji pak počítá dokument (task A U12). U přijatého dokladu se
  sazbou jinou než k DUZP odvození selže (`vat_code_unknown`), u
  ostatních dokladů `VatCodeResolver` deklarované procento nahradí sazbou
  k DUZP.
- Výměnný formát datum sazby nezná, takže ho neumí předat import ani
  datové sady.

## Rozhodnutí k designu (potvrzená, #159)

- ✓ **D11** — Výměnný formát: `vat.rateSource` (`auto` | `document` |
  `rows`), `dates.vatRateDate`, `rows[].vat.rateDate`,
  `vatRecap[].rateDate`. Applier odvozuje kód, kontroluje ho a dosazuje
  procento k datu sazby řádku. Výjimka „procento z dokladu“ v importním
  módu se nezavádí. Export dokladu (datové sady) nese nová pole.
- ✓ **D12** — Pošta (AI) je mimo rozsah: doklad dodavatele s odpočtem
  zálohy ve staré sazbě dál končí „doklad založ ručně“; prompt ani náhled
  se nemění.
- Platí D1, D3–D6, D9, D15 z tasku A — applier je jen nesmí obejít.

**Upřesnění při psaní tasku** (implementační, v duchu D11):

- **U1 — Schéma.** Ve všech třech kopiích:
  - `vat.rateSource`: `{ "enum": ["auto", "document", "rows", null] }`
    (null = `auto`);
  - `dates.vatRateDate`: `["string", "null"]`, `format: date`;
  - `RowVat.rateDate` a `VatRecapEntry.rateDate`: totéž.

  Komentáře v `.jsonc` odkazují na #159 a `VatRateDates`. Prompt profilu
  pošty se nemění (model pole neemituje), jen inline kopie schématu.
- **U2 — Jedno pravidlo, žádná kopie.** Applier datum sazby nepočítá
  vlastním kódem. Složí z canonicalu hlavičku ve tvaru, který čte
  `VatRateDates` (`doc_type` přes `DOC_TYPE_MAP`, `vat_mode`,
  `vat_rate_source`, `vat_rate_date`, `vat_dppd` ← `vatObligationDate`,
  `vat_duzp` ← `taxPointDate`, `issue_date`, `corrective`
  a `corrected_doc_date` z `correctionDecision()`), a volá
  `VatRateDates::headDate()` / `lineDate()`. Klíč `rateDate` ve
  `vatContext()` = datum sazby dokladu; datum řádku se počítá per řádek.
  Komentář u `rateDate` (dnes zmiňuje mezistav U12) aktualizovat.
- **U3 — Co se řídí datem sazby řádku:**
  - odvození kódu a kontrola souladu (`decideRowVatCode()` — dnes dostává
    `taxPointDate`);
  - nabídka kódů pro volbu uživatele (pin) — validace pinu řádku proti
    nabídce **k datu sazby řádku** (nabídky cachovat per datum);
    `_resolve.vatCodeOptions` pro klienta zůstává k datu sazby dokladu;
  - `resolveRowVatCode()` (dnes `rateDate` dokladu) a procento, které jde
    do `transformRows()` — kvůli souladu náhledu s uložením, dokument ho
    stejně odvodí znovu (D1);
  - kód řádku převzaté rekapitulace (dnes `rateDate` dokladu) — k datu
    řádku rekapitulace;
  - platnost a nabídka předmětu plnění řádku.

  **Datem sazby se neřídí** (zůstávají u `taxPointDate`): místo plnění
  (`VatPlaceDerivation`), registrace DPH k datu a neplátce (#97),
  `decisionsCarryReverseCharge()`, zařazení do období.
- **U4 — `transform()`:** hlavička `vat_rate_source` (`auto`/null → 0,
  `document` → 1, `rows` → 2) a `vat_rate_date` ← `dates.vatRateDate`;
  řádek `vat_rate_date` ← `rows[].vat.rateDate`; řádek rekapitulace
  `vat_rate_date` ← `vatRecap[].rateDate`. Mazání podle režimu (D6)
  a vynucení 0 u nedaňového dokladu dělá dokument — applier hodnoty jen
  předá.
- **U5 — Data mimo režim.** `rows[].vat.rateDate` nebo
  `vatRecap[].rateDate` při `rateSource` ≠ `rows`, a `dates.vatRateDate`
  při `rateSource` ≠ `document` → info issue `vat_rate_date_ignored`
  (jedno na doklad, cesta k prvnímu výskytu). Applier je ignoruje (nepočítá
  s nimi datum sazby), dokument je při uložení smaže.
- **U6 — Procento z canonicalu** (`rows[].vat.pct`, `vatRecap[].vatPct`)
  zůstává signálem pro odvození kódu a kontrolu souladu (přijatý doklad),
  teď k datu sazby řádku. Na doklad se ukládá sazba z číselníku k datu
  sazby (D1). Importní mód nemá výjimku: procento, které k datu sazby
  řádku nesedí, vede jako dnes ke `vat_code_derived` /
  `vat_code_unknown`.
- **U7 — Export.** `vat.rateSource` jen při hodnotě ≠ 0 (`document` /
  `rows`), `dates.vatRateDate` jen vyplněné, `rows[].vat.rateDate`
  a `vatRecap[].rateDate` jen vyplněné — vzor `reverseChargeCode`.
  Doklad v automatice se exportuje beze změny, datové sady v repu se
  nemění.
- **U8 — Opravný doklad** (`correction`) beze změny: automatika vrací
  datum původního plnění. `CorrectiveDocBuilder` přepínač ani data
  z původního dokladu nepřebírá (mimo rozsah).

## Co je potřeba udělat

### Commit 1 — schéma a applier (D11, U1–U6, U8)

1. Schéma podle U1, `SchemaDriftTest` a `ProfileSchemaDriftTest` zelené.
2. Datum sazby v applieru podle U2 a U3, `transform()` podle U4, issue
   podle U5 (tabulka issues v `docs/exchange-format.md`).
3. Testy applieru (nový `DocumentApplierVatRateDateTest`, vzor
   `DocumentApplierCorrectionTest`):
   - přijatá faktura, DUZP 4. 1. 2013, `vatObligationDate` 31. 12. 2012,
     řádek `cz-110` 20 % → kód ponechán (`matchedBy: input`), bez
     `vat_code_unknown`, řádek i rekapitulace 20 %;
   - totéž bez `vatObligationDate`, s `rateSource: document`
     a `vatRateDate` 31. 12. 2012 → 20 %; bez data sazby → dnešní
     `vat_code_unknown`;
   - import vydané faktury, `rateSource: rows`: běžný řádek bez data
     (21 %), řádek odpočtu zálohy s `rateDate` v roce 2012 (20 %),
     převzatá rekapitulace se dvěma skupinami `cz-120` a `rateDate` u
     skupiny 20 % → apply projde, řádky i rekapitulace nesou data;
   - doklad 2024, řádek `cz-311` s `rateDate` v prosinci 2023 → 10 %;
     tentýž řádek bez `rateDate` → apply v importním módu skončí chybou
     `vat_rate_missing` z validace dokumentu;
   - přijatý doklad bez kódu (odvození), `rateSource: rows`, řádek 15 %
     s `rateDate` 2023 → odvozený kód první snížené sazby;
   - volba kódu (pin) u řádku s vlastním datem: kód z nabídky k datu
     řádku projde, kód platný jen k datu dokladu → `vat_code_pin_invalid`;
   - `rows[].vat.rateDate` při `rateSource: auto` → info
     `vat_rate_date_ignored`, doklad bez dat;
   - opravný doklad: sazba k datu původního plnění beze změny (stávající
     testy `DocumentApplierCorrectionTest` zelené).

### Commit 2 — export, datové sady, dokumentace (D11, U7)

1. `DocumentExporter` podle U7; `DocumentExporterTest`: doklad
   v automatice beze změny výstupu, doklad se zdrojem 1 a 2 nese pole.
2. `DatasetRoundTripTest`: doklad se zdrojem 1 a doklad se zdrojem 2
   (řádky i převzatá rekapitulace s daty) projdou dump → seed beze změny.
3. Dokumentace: `docs/exchange-format.md` (§ 5 `dates`, blok `vat`,
   `vatRecap`; § 7 `rows[].vat`; § 8.4 — kód a sazba k datu sazby řádku,
   co zůstává u DUZP; odstavec „Datum sazby DPH“ u opravného dokladu
   zobecnit; issue `vat_rate_date_ignored`), `docs/datasets.md` (nová
   pole), `docs/vat-calculation.md` § 5.2 (applier a výměnný formát, konec
   mezistavu), v tasku A poznámka, že U12 skončil.
4. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj s třídou dat `free`)

- `dataset-seed` + `dataset-verify` veřejných sad `ok` (export doklady
  v automatice nemění).
- `/apply` v importním módu: přijatá faktura z ledna 2013 s DPPD
  31. 12. 2012 a sazbou 20 % projde; faktura vydaná se zdrojem `rows`
  a řádkem odpočtu zálohy ve staré sazbě projde a v detailu ukazuje data
  sazby.
- Dump zdroje s takovými doklady a seed do čistého zdroje: přepínač, data
  i procenta sedí.

## Návaznost — import ze starého systému

Po tomto tasku napsat task pro runner (repo starého systému): přepínač
1:1 (DUZP → `auto`, datum vystavení → `document` + `dates.vatRateDate`
= datum vystavení u všech dokladů s touto hodnotou, DPPD → `auto`,
vlastní datum na řádcích → `rows` + `rows[].vat.rateDate` = datum řádku,
bez něj DUZP — vždy výslovně), data řádků převzaté rekapitulace z řádků
téže skupiny; pak reimport.

## Mimo rozsah

- Pošta (AI) a náhled návrhu (D12): prompt, extrakce ani UI náhledu se
  nemění.
- ISDOC (příjem i generování) — datum sazby nenese.
- `CorrectiveDocBuilder` a fakturace zakázek (`InvoiceBuilder`) —
  generují doklady v automatice.
- Import ze starého systému — samostatný task v jeho repu.

## Pasti

- **Dvě data v applieru.** `taxPointDate` dál řídí místo plnění,
  registraci k datu, neplátce a období; datum sazby jen kód, sazbu
  a předmět. Nezaměnit — vzor rozdělení je dnešní `rateDate`
  u opravného dokladu.
- **Cache `vatContext()`.** Klíč cache už nese celý blok `vat`, `dates`
  i `rows[].vat`, takže přepínač a data řádků v něm jsou. Kdyby kontext
  četl datum odjinud (např. `vatRecap[].rateDate`), patří to do klíče
  taky — jinak náhled vrátí kontext jiného dokladu.
- **Nabídka pro pin per datum.** Jedna nabídka k datu dokladu by pin
  řádku s vlastním datem odmítla nebo pustila špatný kód.
- **Tři kopie schématu** — `SchemaDriftTest`, `ProfileSchemaDriftTest`;
  prompt profilu neměnit, `ai-eval` nespouštět.
- **Datové sady v repu se měnit nesmí** — export doklad v automatice nemá
  měnit ani o klíč (U7). Rozdíl v `dataset-verify` znamená chybu exportu.
- **Import mód je přísný** (D15): kód bez sazby k datu sazby řádku
  validace dokumentu odmítne — applier ho nesmí tiše „opravit“ jiným
  kódem, pokud ho neodvodil podle dnešních pravidel.

## Hotovo když

- [ ] Výměnný formát nese přepínač a data sazby ve všech třech kopiích
      schématu.
- [ ] Applier určuje kód, sazbu a předmět k datu sazby řádku; místo,
      registrace a období zůstávají u DUZP.
- [ ] Přijatá faktura z přelomu sazeb s DPPD dodavatele projde bez
      `vat_code_unknown`.
- [ ] Export a dump → seed zachovají přepínač i data; doklady
      v automatice se exportují beze změny.
- [ ] Dokumentace aktuální, mezistav U12 tasku A ukončený.
- [ ] Testy zelené (cíleně, pak celá sada).
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
