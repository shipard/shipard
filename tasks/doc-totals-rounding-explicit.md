# Task: `totals` jako kontrolní součet a výslovný mód zaokrouhlení

**Stav:** částečně — commity 1–2 z 5 hotové 2026-11-22 (pole formátu, pokyn, export, demo sada, kontrolní součet `totals`); zbývají případy payloadů, snapshot osoby a dokumentace (#166 etapa 3, část B; D4, D25–D29)

**Issue:** #166 (etapa 3; D4, D25–D29)

**Cíl:** Dvě věci, které pokynu po části A chybí, aby šel doklad uložit
přesně tak, jak vznikl ve formuláři:

1. Mód zaokrouhlení celkové částky a DPH jde ve výměnném formátu říct
   **výslovně** — pokyn ho bere z payloadu, neodhaduje z částek.
2. `totals` v pokynu je **kontrolní součet**: když ho payload nese
   a výpočet dokladu vyjde jinak, doklad se neuloží.

Tím se zavře poslední mezera matice round-tripu
(`issued-rounding-manual`).

Navíc drobnost z ověření části A: prázdná hodnota ve snapshotu osoby je
vždy `null`, ať snapshot vznikl z formuláře, nebo z importu (D29).

## Před implementací přečti

- issue #166 — D1, D4, D18–D22
- `tasks/doc-apply-instruction-mode.md` včetně poznámek k implementaci
  (`ApplyMode`, U4 „přechodně“, U6 chyby dokladu)
- `docs/vat-calculation.md` — § 6 (zaokrouhlení celkové částky,
  `total_rounding_auto`), § 3, § 7
- `docs/exchange-format.md` — § 5 („Pole `vatRecap` a `totals` — vstup
  vs. autorita“), § 10, tabulka issue kódů (`totals_mismatch`,
  `computed_total_mismatch`)
- `tasks/doc-total-rounding-auto.md` (#73) — kdy automatika platí
- `modules/docs/core/src/RoundingModes.php`, `TotalRoundingAuto.php`,
  `DocDocument.php` — `validateTotalRounding()` (~ř. 228), `sumTotals()`
  (~ř. 2065), `applyTotalRounding()` / `applyTotalRoundingAuto()`
  (~ř. 2090–2140), `vat_rounding_mode` (~ř. 1530, ~ř. 1870)
- `modules/docs/core/src/DocsHeadsFormBase.php` — výchozí hodnoty
  a pole zaokrouhlení (~ř. 296, ~ř. 630, ~ř. 781, ~ř. 1802)
- `modules/core/exchange/src/Document/DocumentApplier.php` —
  `resolveTotalRoundingAuto()` / `deriveTotalRoundingMode()` (~ř. 4593),
  `transform()`, `computePreviewAmounts()` (~ř. 468)
- `modules/core/exchange/src/Document/DocumentValidator.php` —
  `checkTotalsCoherence()` (~ř. 274)
- `modules/core/exchange/src/Export/DocumentExporter.php` — blok `totals`
  (~ř. 401), blok `vat`
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`, ručně
  udržovaný `.json` (`SchemaDriftTest`), kopie v
  `modules/core/mail/profiles/czech_general.jsonc` (`ProfileSchemaDriftTest`)

## Kontext

- Doklad má na hlavičce `total_rounding_auto`, `total_rounding_mode`
  a `vat_rounding_mode`; formulář je nabízí všechny tři.
- Formát nese jen `totals.totalRoundingAuto` a `totals.totalRounding`
  (částku rozdílu). Mód applier odhaduje z rozdílu mezi deklarovanou
  a spočtenou částkou (`deriveTotalRoundingMode()`), `vat_rounding_mode`
  formát nenese vůbec.
- Po části A pokyn mód neodhaduje a bez dalšího pole ho nemá jak dostat.
- `totals` dnes slouží návrhu jako signál a validátoru k heuristickému
  varování `totals_mismatch`. Na doklad se neukládá — částky vždy počítá
  `DocDocument`.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D4** — `totals` jsou nepovinné a nikdy autorita. Když je payload
  pokynu nese, rozdíl proti výpočtu nad 0,01 je chyba a doklad se
  neuloží. Import je posílá. Mód zaokrouhlení jde zadat výslovně,
  výchozí je automatika podle způsobu úhrady (#73).
  `vat.recapSource: "declared"` zůstává záměrem.
- ✓ **D25–D28** — upřesnění U1–U4 níže (pole formátu, automatika
  nezávislá na `totals`, kontrola každého uvedeného pole, návrh beze
  změny), potvrzená 2026-11-22.
- ✓ **D29** — prázdná hodnota ve snapshotu osoby je vždy `null`
  (formulář i import); jedno pravidlo na jednom místě. Nahrazuje část
  D23, která u identifikátorů nechávala prázdný řetězec.

## Upřesnění pro implementaci

- **U1 (D25) — Pole formátu.**
  - `totals.totalRoundingMode`: `cent` | `unit` | `unitUp` | `unitDown`
    | `fiveCent` (↔ `RoundingModes` 0, 1, 3, 4, 5);
  - `vat.roundingMode`: `cent` | `unit` (↔ `RoundingModes::VAT_MODES`).

  Obě nepovinná, `null` = výchozí. Ve všech třech kopiích schématu;
  prompt profilu pošty se nemění (model pole neemituje).
- **U2 (D26) — Pokyn a zaokrouhlení celkové částky.**
  - `totalRoundingAuto` uvedené → platí; chybí → zapnuto tam, kde
    automatika platí (`TotalRoundingAuto::applies()`), jinak vypnuto.
    Přítomnost `totals.totalAmount` na to už nemá vliv (dnes automatiku
    vypíná).
  - Automatika vypnutá: `totalRoundingMode` z payloadu, chybí = `cent`.
  - Automatika zapnutá a mód uvedený: platí automatika (mód dopočítá
    `DocDocument`); liší-li se uvedený mód od dopočítaného, varování
    `total_rounding_mode_ignored`.
  - `vat.roundingMode` z payloadu, chybí = `cent`.
- **U3 (D27) — Kontrolní součet v pokynu.** Každé uvedené číselné pole
  z `totals.totalBase`, `totalVat`, `totalAmount`, `totalRounding` se
  porovná s hodnotou, kterou spočítal `DocDocument` (sloupce hlavičky
  v měně dokladu). Rozdíl větší než 0,01 = chyba `totals_check_failed`
  (422) s cestou pole a hodnotami `declared` / `computed`; nic se
  neuloží (rollback). `/preview` pokynu hlásí totéž jako chybu nad
  `_resolve.computed`. Platí i v importním módu. Heuristické
  `totals_mismatch` validátoru v pokynu neběží (část A, D19).
- **U4 (D28) — Návrh se nemění**, s jedinou výjimkou: uvedený
  `totalRoundingMode` / `vat.roundingMode` má přednost před odhadem.
  `totals` zůstávají signálem a zdrojem varování (`computed_total_mismatch`),
  kontrolní součet se v návrhu nevyhodnocuje.

- **U5 — Export.** `DocumentExporter` vyváží `totalRoundingMode`
  a `vat.roundingMode` **jen s nevýchozí hodnotou** (mód jiný než
  `cent`; u celkové částky jen při vypnuté automatice) — soubory
  existujících sad se tak změní jen u dokladů, kterých se to týká.
- **U6 — Kde se kontrola dělá.** V transakci po `saveDocument()` nad
  vrácenými daty hlavičky; neshoda = `ApplyAbortedException` (rollback
  vlastní práce, vnější transakce volajícího zůstává). Jedna funkce
  porovnání pro apply i náhled.
- **U7 — Matice a payloady.**
  - matice: `issued-rounding-manual` na `holds`; nový případ
    `issued-vat-rounding-unit` (zaokrouhlení DPH na koruny);
  - payloady (`DocumentInstructionPayloadTest`): `totals.totalAmount`
    o korunu vedle → 422 `totals_check_failed`, nic uloženo; rozdíl
    0,01 → projde; správné `totals` s `unitUp` → uloží se s módem
    „nahoru na 1“; automatika zapnutá + jiný uvedený mód → varování;
    `totals` v cizí měně; týž chybný payload v režimu návrh → uloží se
    s varováním jako dnes.
- **U9 (D29) — Snapshot osoby.** `PersonSnapshotBuilder::shape()` převádí
  prázdný řetězec (i samé mezery) u všech textových hodnot na `null`:
  identifikátory, zápis v rejstříku, kontakt včetně webu a ID datové
  schránky, adresa. `name` zůstává řetězec. Platí pro snapshot partnera
  i vlastní firmy, z formuláře i z importního módu (obě cesty už `shape()`
  volají). Uložené snapshoty se nepřepisují. Čtenáři snapshotu (tisky,
  ISDOC, kontrolní a souhrnné hlášení, detail dokladu) musí brát `null`
  a `""` stejně — projdi je cíleným grepem a kde porovnávají s `''`,
  oprav. Matice: fixtura partnera s prázdnými řetězci ve sloupcích
  adresáře (`web`, `gov_e_box_id`, `tax_id`) — případ musí držet.
- **U8 — Datové sady v repu.** Sekce `docs/` nese `totals` z exportu,
  takže seed je nově kontroluje. Doklad sady, který neprojde, je nález:
  buď chyba v ručně psané šabloně (oprav data), nebo rozdíl výpočtu
  (nahlas, neobcházej). Po úpravě dump → seed → dump byte-shodně.
  Generátory sad (`demo/datasets/build.py`, `tests/corpus/cz/build.py`,
  šablony) píšou `totalRoundingAuto` výslovně (od části A) a u dokladu
  s ručním zaokrouhlením nově i `totalRoundingMode` — doklady sepsané
  v poznámkách části A se tím musí vrátit na částky před částí A.

## Co je potřeba udělat

### Commit 1 — pole formátu a pokyn (U1, U2, U4, U5)

1. Schéma (tři kopie, strážní testy), mapy názvů ↔ `RoundingModes`.
2. `transform()`: pokyn podle U2, návrh podle U4.
3. `DocumentExporter` podle U5.
4. Unit testy: každá větev U2, přednost v návrhu, export nevýchozích
   hodnot, round-trip názvů módů proti `RoundingModes::TABLE`.

### Commit 2 — kontrolní součet (U3, U6)

1. Porovnání, `totals_check_failed`, náhled.
2. Unit testy: každé pole zvlášť, tolerance, cizí měna, importní mód,
   režim návrh beze změny.

### Commit 3 — testy invariantu a sady (U7, U8)

1. Matice a payloady.
2. Sady v repu.

### Commit 4 — snapshot osoby (U9)

1. `PersonSnapshotBuilder::shape()` + unit testy (prázdný řetězec,
   mezery, `null`, vyplněná hodnota; partner i vlastní firma).
2. Čtenáři snapshotu; snímky kontraktu tisků se smí změnit jen v těchto
   polích (`""` → `null`).
3. Matice podle U9.

### Commit 5 — dokumentace a stav

0. `docs/document-system.md` / `docs/docs-mvp.md` (snapshoty stran):
   pravidlo prázdné hodnoty (D29).
1. `docs/exchange-format.md` § 5 („`vatRecap` a `totals`“ přepsat:
   `totals` = kontrolní součet v pokynu, signál v návrhu; nová pole),
   § 10, tabulka issue kódů (`totals_check_failed`,
   `total_rounding_mode_ignored`).
2. `docs/vat-calculation.md` § 6 (mód ve výměnném formátu),
   `docs/datasets.md` § 4.
3. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. `DocumentRoundTripMatrixTest` — žádná mezera.
2. `DocumentInstructionPayloadTest`, `DatasetRoundTripTest`.
3. `dataset-verify` veřejných sad beze změny; demo sada `ds-reset`
   + seed + dump byte-shodně.

## Dopad na import ze starého Shipardu

Runner má posílat `totals` (kontrola, že nový Shipard počítá stejně)
a u dokladu se zaokrouhlením `totalRoundingAuto: false`
+ `totalRoundingMode` podle nastavení starého dokladu. Úprava runneru je
#166 etapa 6.

## Mimo rozsah

- Další sloupce, které export nenese (autor, doprava a platební
  terminál, adresa partnera, partner na řádku účetního dokladu).
- Reference ve formátu dokladu (D11–D14).
- Fyzické oddělení interpretace (etapa 5); `totals` do hotového dokladu
  interpretace nepředává až tam.
- Runner starého Shipardu.

## Pasti

- `total_rounding` na hlavičce je rozdíl ze zaokrouhlení; u dokladu
  s automatikou ho určuje způsob úhrady — kontrola `totalRounding` proto
  odhalí i špatně uvedený způsob úhrady. To je záměr, ne falešný poplach.
- U převzaté rekapitulace jdou součty hlavičky z rekapitulace
  a z řádků „Mimo DPH“ (`docs/vat-calculation.md` § 5.1) — porovnávej
  s tím, co `DocDocument` skutečně uložil, nic nepočítej znovu.
- Periodická fakturace `totals` neposílá — automatika zapnutá, kontrola
  neběží; nesmí se změnit.
- Opravný doklad přes `CorrectiveDocBuilder` — ověř, co o zaokrouhlení
  posílá, a že výsledek zůstává stejný.
- Porovnávej zaokrouhlené na 2 místa; float šum nesmí dát chybu.
- PHPUnit s úzkým `--filter`, grep s `--include`.

## Hotovo když

- [ ] `totals.totalRoundingMode` a `vat.roundingMode` ve schématu,
      exportu a applieru
- [ ] pokyn mód neodhaduje; návrh odhaduje jen bez uvedeného módu
- [ ] `totals` v pokynu je kontrolní součet (`totals_check_failed`),
      v náhledu i při uložení
- [ ] matice round-tripu bez mezer; případy payloadů z U7
- [ ] sady v repu projdou seedem a dump → seed → dump byte-shodně;
      `dataset-verify` beze změny
- [ ] cílené testy, pak celá unit sada
- [ ] snapshot osoby nese prázdnou hodnotu jako `null` z obou cest;
      čtenáři berou `null` a `""` stejně
- [ ] dokumentace podle commitu 5, stav tasku
