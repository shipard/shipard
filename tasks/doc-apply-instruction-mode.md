# Task: Pokyn × návrh — applier, který u pokynu neodvozuje

**Stav:** hotovo — 2026-11-22 (#166 etapa 3, část A; D18–D24; 6 commitů); zadání 2026-11-22. Zbývá mimo kód: komentář do #166, úprava runneru starého Shipardu (etapa 6), alfa

**Issue:** #166 (etapa 3; D1–D7, D18–D24)

**Cíl:** `DocumentApplier` dostane dva výslovné režimy. **Pokyn** uloží
doklad tak, jak ho payload říká: dohledá reference, doplní výchozí
hodnoty, nechá `DocDocument` validovat a počítat — a nic neodvozuje ze
signálů. **Návrh** se chová jako dnešní applier (pošta, ISDOC). REST,
datové sady a generátory dokladů přejdou na pokyn. Importní mód zůstane
jen nositelem historie.

Po tasku platí pro pokyn invariant z etapy 1: co uloží formulář, uloží
i výměnný formát. Zavřou se mezery matice round-tripu kromě módu
zaokrouhlení (tu zavře navazující `tasks/doc-totals-rounding-explicit.md`).

## Před implementací přečti

- issue #166 — cílový model, D1–D17, komentář se známými mezerami
- `tasks/doc-saving-safety-net.md` včetně poznámek (matice, mezery,
  „co matice neukázala“)
- `docs/exchange-format.md` — § 3, § 5 („`vatRecap` a `totals`“, „Datum
  sazby DPH“), § 8.4 celé, § 9, § 10 (pipeline, „Apply a doc state“,
  „Normalizace a validace hodnot“), § 11, tabulka issue kódů
- `docs/vat-calculation.md` — § 1.1, § 5, § 6
- `docs/document-system.md` — § 12b, § 13, § 16 (zámek, `isLockExempt`)
- `modules/core/exchange/src/Document/DocumentApplier.php`:
  `validate()` / `preview()` / `apply()` (~ř. 348–885),
  `resolveAll()`, `reconcile()`, `transform()` (~ř. 1612),
  `buildImportPartnerSnapshot()` (~ř. 1837), `vatContext()` (~ř. 2019),
  `outOfScopeKind()`, `decideRowVatCode()` (~ř. 2744),
  `resolveRowVatCode()` (~ř. 2931), `resolveRecapSource()` (~ř. 3517),
  `exchangeRateDecision()` (~ř. 3782), `documentVatMode()` /
  `effectiveVatMode()` (~ř. 4318), `supplierVatGrossTotals()`,
  `defaultRowOperationsForApply()` (~ř. 4483),
  `resolveTotalRoundingAuto()` / `deriveTotalRoundingMode()` (~ř. 4593),
  `resolveVatRegistrationFor()` (~ř. 5244), `writeSupplierCodeMappings()`
- `modules/core/exchange/src/Document/DocumentValidator.php`,
  `VatModeDerivation.php`, `VatPlaceDerivation.php`, `VatCodeDerivation.php`,
  `SupplierVatPrices.php`, `Values/CanonicalValueNormalizer.php`
- `modules/docs/core/src/DocDocument.php` — `validate()` (~ř. 124),
  `validateVatCodesFilled()` (~ř. 603), `validateCorrective()` (~ř. 757),
  `isLockExempt()`, `beforeSave()` (~ř. 909), `buildSnapshots()` /
  `buildImportSnapshots()` (~ř. 2839); `PersonSnapshotBuilder.php`
- volající: `src/Api/Controller/ExchangeController.php`,
  `src/Api/Controller/AnalysisController.php` (~ř. 640),
  `modules/core/mail/src/MessageProposalApplier.php` (~ř. 248),
  `modules/core/exchange/src/Dataset/Seed/DocumentSeeder.php`,
  `modules/economy/workOrders/src/Invoicing/InvoicingRunService.php`
  (~ř. 439) + `InvoiceBuilder.php`,
  `modules/docs/core/src/Corrective/CorrectiveDocService.php` (~ř. 114)
  + `CorrectiveDocBuilder.php`
- testy: `tests/Integration/Exchange/DocumentRoundTripMatrixTest.php`
  a `RoundTrip/`, `tests/Unit/Module/Core/Exchange/Document/` (všechny
  `DocumentApplier*Test`), `tests/Integration/Exchange/*ImportTest.php`

## Kontext

- Applier dnes odvozuje vždy; importní mód (`applyOptions.importNumber`)
  vypíná jen část. Důsledky drží matice round-tripu jako známé mezery:
  místo plnění přepsané podle DIČ dodavatele, jiný tvar snapshotu
  partnera, odhad módu zaokrouhlení.
- Payload, jehož řádky nesou objekt `vat` bez kódu (import ze starého
  Shipardu), končí `vat_code_unknown`, nebo dostane odvozený kód
  (`cz-114`) a řádek rekapitulace navíc.
- Chyba validace `DocDocument` při uložení dnes vyjde jako `internal_error`
  (500) s textem validace.
- Volající: REST `/_exchange/docs/document/*` (externí klienti, import),
  `DocumentSeeder`, periodická fakturace a opravný doklad vědí, co chtějí
  uložit. Pošta (`AnalysisController`, `MessageProposalApplier`) a ISDOC
  přes ni potřebují interpretaci. Aplikace sama REST výměnného formátu
  nevolá — náhled návrhu jde přes `/_mail/messages/…`.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D1–D7** — tři pojmy, REST = pokyn, jedno schéma, `totals`,
  importní mód jen historie, učení jen v interpretaci, bez update.

- ✓ **D18 — Režim je parametr volání.** `DocumentApplier::validate()`,
  `preview()` a `apply()` berou režim (`pokyn` | `návrh`); výchozí je
  pokyn. Obsah payloadu režim neurčuje, s jednou výjimkou pro REST:
  `applyOptions.interpret: true` vyžádá návrh (D2 „na vyžádání“).
  Pošta volá návrh, ostatní volající pokyn.
- ✓ **D19 — Pokyn neodvozuje.** V pokynu neběží: místo plnění z DIČ;
  odvození ani náhrada kódu DPH; doklad neplátce a doklad mimo české DPH
  (ceny řádků s daní dodavatele, zahozená rekapitulace); korekce režimu
  DPH ze součtů a kvůli samovyměření; druh plnění ze štítků; odvození
  `recapSource` a dohledání kódu rekapitulace z řádků; odhad módu
  zaokrouhlení; vynechání řádků; heuristická varování validátoru
  (`totals_mismatch`, `rows_recap_mismatch`, `vat_mode_suspect`,
  `vat_recap_inconsistent`); oprava IBAN ↔ číslo účtu (normalizace tvaru
  hodnot zůstává); učení dodavatelských kódů (D6). Volby DPH v `_resolve`
  se v pokynu ignorují s info `vat_pin_ignored`.
- ✓ **D20 — Hodnoty DPH v pokynu jsou záměr:**
  - `vat.place` z payloadu, chybí = tuzemsko;
  - `vat.mode` z payloadu, chybí = `fromBase`;
  - `rows[].vat.code` z payloadu, ověřený proti číselníku země registrace
    k datu sazby řádku; neznámý kód = chyba `vat_code_unknown`; řádek bez
    kódu je „nevyplněno“ a rozhodne `DocDocument` (#164 D2); `vat.pct`
    z payloadu se nepoužije — procento je vždy z kódu a data;
  - registrace DPH: `vat.registrationCountry` → registrace země; bez
    údaje výchozí registrace zdroje dat jako ve formuláři; v importním
    módu bez ohledu na platnost k datu (D5);
  - `vat.recapSource` z payloadu, chybí = `computed`; `declared` vyžaduje
    `vatRecap` s kódem DPH na každém řádku — chybějící nebo neznámý kód
    je chyba, ne tichý přepočet z řádků.
- ✓ **D21 — Chyba `DocDocument` je chyba apply.** Selhání validace při
  uložení vrací 422 `validation_failed` s nálezy (`path` = cesta
  v canonicalu, kde ji jde určit, jinak sloupec), ne `internal_error`.
  Platí v obou režimech. `/preview` v pokynu spustí tutéž validaci pro
  cílový stav a výpočet bez zápisu — nález je vidět před uložením.
- ✓ **D22 — Importní mód v pokynu:** nese vnucené číslo, snapshot partnera
  z payloadu, otevírací a uzávěrkové období, archivní řady, zápis do
  zamčených období, registraci bez ohledu na platnost k datu, stav
  V opravě jako cíl; kurz z lístku nedohledává. **Výjimky z validace
  mizí** — obě, které `DocDocument` dnes má:
  - chybějící kód DPH řádku je při potvrzení chyba i v importu
    (`validateVatCodesFilled`);
  - opravný doklad musí mít při potvrzení důvod opravy i v importu
    (`validateCorrective`).

  `importNumber` v režimu návrh je chyba validace.
- ✓ **D23 — Snapshot partnera v importním módu má tvar snapshotu
  z formuláře.** Autorita tvaru je `PersonSnapshotBuilder`: stejné klíče
  (bez `bank_account`, pokud ho formulářový snapshot nemá), prázdné
  identifikátory stejnou hodnotou.
- ✓ **D24 — Případy payloadů.** Vedle matice round-tripu vznikne integrační
  test „payload → uložený doklad“ pro tvary, které export nevyrobí
  (U8).

**Mimo tenhle task (jiné části etapy 3 a etapa 4):** `totals` jako
kontrolní součet a výslovný mód zaokrouhlení (D4); gramatika referencí
ve formátu dokladu, `ref` na straně a položce, pokyn bez zakládání osob
a položek (D11–D14); povinná pole a společné výchozí hodnoty s formulářem
(D1 — pravidla `required` validátoru se tady nemění).

## Upřesnění pro implementaci

- **U1 — Režim v kódu.** Enum `ApplyMode` (`Instruction`, `Proposal`)
  v modulu výměnného formátu; `validate()`, `preview()`, `apply()` mají
  parametr s výchozí hodnotou `Instruction`. Režim se uvnitř předává
  výslovně (ne přes vlastnost instance — `vatContext()` cachuje podle
  obsahu canonicalu, režim musí být v klíči cache).
- **U2 — Jedno místo větvení.** Odvozování se vypíná v `vatContext()`
  (`derive` = režim návrh **a** dnešní podmínky; `nonPayer` a `outOfScope`
  jen v návrhu) a v hrstce funkcí, které čtou signály mimo něj:
  `documentVatMode()` (`VatModeDerivation`), `effectiveVatMode()`
  (samovyměření přepíná Bez DPH), `resolveRecapSource()`,
  `deriveTotalRoundingMode()`, `supplierVatGrossTotals()`,
  `skippedRowIndices()`, `writeSupplierCodeMappings()`, validátor,
  normalizátor. Nerozsévej `if ($mode …)` po celém souboru — kde to jde,
  rozhodni jednou a dál předávej výsledek. Podmínky na `importMode`
  uvnitř odvozování po tasku ztratí smysl (import = pokyn); nech jen ty
  z D22.
- **U3 — Pokyn a hodnoty DPH** podle D20. Řádek s objektem `vat` bez kódu
  nesmí dostat odvozený kód ani chybu applieru; na doklad jde bez kódu.
  U dokladu s `vat.mode: "none"` se kódy řádků na doklad nepropisují
  (jako dnes u dokladu Bez DPH) — ověř, že formulář dělá totéž, a drž se
  formuláře.
- **U4 — Pokyn a zaokrouhlení (přechodně).** Mód zaokrouhlení se
  v pokynu neodhaduje: `totals.totalRoundingAuto` z payloadu, chybí =
  zapnuto tam, kde automatika platí (`TotalRoundingAuto::applies()`),
  jinak vypnuto s módem „na haléře“. Výslovný mód přinese navazující
  task; mezera `issued-rounding-manual` do té doby zůstává, jen s jiným
  rozdílem — uprav ji v matici podle skutečnosti.
- **U5 — Normalizace.** `CanonicalValueNormalizer::normalizeShapes()`
  v obou režimech, oprava bankovního spojení (`normalize()`) jen
  v návrhu. Nálezy tvaru (`iban_invalid`, …) zůstávají varováním
  v obou režimech.
- **U6 — Chyby dokladu (D21).** `apply()`: neúspěšný
  `saveDocument()` s `ValidationResult` → rollback a
  `ApplyResult::error('validation_failed', …, 422)`; nálezy do
  `_resolve.issues` se `severity: error`, kódem z `ValidationError`
  a cestou: `rows.N.<sloupec>` → `rows.N.…` podle mapy sloupec → pole
  canonicalu tam, kde je jednoznačná (kód DPH, cena, množství, položka,
  data hlavičky, partner), jinak sloupec. Jiná výjimka než validace
  zůstává `internal_error`. `preview()` v pokynu: po výpočtu
  (`computePreviewAmounts()`) spusť `DocDocument::validate()` nad týmiž
  daty pro cílový stav z `applyOptions.targetDocState` a nálezy přidej
  (chyby i varování); nic se neukládá.
- **U7 — Importní mód (D22).** V `DocDocument` zmizí `isLockExempt()`
  z podmínek `validateVatCodesFilled()` a z důvodu opravy ve
  `validateCorrective()`; ostatní čtení `isLockExempt()` / `importMode`
  (zámky, čísla, snapshoty, saldo partnera, varování zaokrouhlení,
  přísná sazba) zůstávají. Validátor: `importNumber` + režim návrh =
  chyba `import_requires_instruction`.
- **U8 — Případy payloadů (D24)**
  `tests/Integration/Exchange/DocumentInstructionPayloadTest.php`,
  pomocné třídy sdílej s maticí. Minimálně:

  | payload | očekávání |
  |---|---|
  | přijatý, `vat.mode: none`, dodavatel s DIČ jiného státu, řádky s `vat: {pct: 0}`, importní mód | uloží se Bez DPH, místo plnění tuzemsko, řádky bez kódu, bez rekapitulace |
  | přijatý, `vat.mode: none`, tuzemský dodavatel, řádky s `vat: {pct: 0}` | uloží se Bez DPH; žádný `cz-114`, žádný řádek rekapitulace |
  | přijatý, dodavatel s DIČ jiného státu, `vat.place: domestic`, řádky s tuzemským kódem | uloží se, jak je psáno (místo i kódy) |
  | přijatý s DPH, řádek bez kódu, cílový stav V pořádku | 422 `validation_failed`, nález `vat_code_missing` na řádku; týž nález v `/preview` |
  | týž, cílový stav Koncept | uloží se, nález jako varování |
  | týž, importní mód, cílový stav V pořádku | 422 (D22) |
  | opravný doklad bez důvodu, importní mód, V pořádku | 422 `correction_reason_required` |
  | `recapSource: declared`, řádek rekapitulace bez kódu | chyba, nic se neuloží |
  | `recapSource: declared` s kódy, řádky se od rekapitulace liší o haléř | uloží se s převzatou rekapitulací |
  | neznámý kód DPH | 422 `vat_code_unknown` |
  | řádky v cenách s daní při `fromBase` (součet řádků = `totalAmount`) | režim zůstane `fromBase`, žádná korekce |
  | týž payload v režimu návrh | chová se jako dnes (`vat_mode_derived`) |
  | `importNumber` v režimu návrh | chyba `import_requires_instruction` |
- **U9 — Matice round-tripu.** Mezery místa plnění a snapshotu partnera
  (D23) přepni na `holds`; mezeru zaokrouhlení uprav podle U4. Nová
  mezera je nález k nahlášení, ne k zapsání.
- **U10 — Volající.** `ExchangeController`: pokyn, s
  `applyOptions.interpret: true` návrh (pole přidej do schématu — všechny
  tři kopie a strážní testy). `AnalysisController::preview`
  a `MessageProposalApplier`: návrh. `DocumentSeeder`,
  `InvoicingRunService`, `CorrectiveDocService`: pokyn (výchozí).
- **U11 — Datové sady v repu.** Sekce `docs/` jde nově pokynem. Sada,
  jejíž doklad spoléhal na odvození (kód z DIČ, režim ze součtů), se
  musí převést na výslovné hodnoty: **před** změnou applieru sadu
  naseeduj a dumpni (dump nese výsledné hodnoty), po změně ověř dump →
  seed → dump byte-shodně. Šablony a `build.py`, které doklady generují,
  uprav stejně. Sekce `mail/` (návrhy) se nemění.

  Dvě věci z ověření `tasks/number-series-vat-registration-codes.md`:
  - Dump demo sady **není** byte-shodný se sestavenou sadou v repu už
    dnes: doklady v dumpu nesou `totals.totalRoundingAuto`, které
    generátor sady nepíše, a `setup/settings.jsonc` nese provozní stav
    stahování kurzů. Kritérium je proto dump → seed → dump, ne dump
    proti repu.
  - Doklad sady s `totals.totalAmount` a bez `totalRoundingAuto` má dnes
    automatiku vypnutou a mód odhadnutý z částek; po U4 má automatiku
    zapnutou tam, kde platí, jinak mód „na haléře“. Generátory sad
    (`demo/datasets/build.py`, `tests/corpus/cz/build.py`, šablony) proto
    musí `totalRoundingAuto` psát výslovně. Doklad, který stál na
    odhadnutém módu jiném než „na haléře“ (ruční zaokrouhlení mimo
    automatiku), po tomhle tasku vyjde s jinou částkou — **neopravuj to
    obcházením**, sepiš takové doklady do poznámek; zavře je
    `tasks/doc-totals-rounding-explicit.md`.
- **U12 — Stávající testy applieru** volají `apply()` / `preview()` bez
  režimu a testují odvozování — dostanou výslovně režim návrh. Neměň
  jejich očekávání; test, který po přepnutí na návrh selže, je nález.

## Co je potřeba udělat

### Commit 1 — režim a pokyn bez odvozování (D18–D20; U1–U5, U12)

1. `ApplyMode`, parametr veřejných metod, klíč cache.
2. Větvení podle U2–U5.
3. Stávající unit testy applieru na režim návrh (U12); nové unit testy
   pokynu pro každou položku D19 a D20.

### Commit 2 — chyby dokladu a náhled pokynu (D21; U6)

1. `validation_failed` místo `internal_error`, mapa cest.
2. Validace v `preview()` pokynu.
3. Testy: apply do V pořádku s řádkem bez kódu, náhled téhož.

### Commit 3 — importní mód a snapshot (D22, D23; U7)

1. `DocDocument`: konec dvou výjimek; validátor `import_requires_instruction`.
2. `buildImportPartnerSnapshot()` na tvar `PersonSnapshotBuilder`.
3. Testy.

### Commit 4 — volající, schéma, datové sady (U10, U11)

1. Volající podle U10; `applyOptions.interpret` ve schématu.
2. Převod sad v repu podle U11.

### Commit 5 — testy invariantu (D24; U8, U9)

1. `DocumentInstructionPayloadTest`.
2. Matice: zavřené mezery na `holds`.

### Commit 6 — dokumentace a stav

1. `docs/exchange-format.md`: § 3 a § 4 (dva režimy, kdo který volá),
   § 8.4 (odvozování platí pro návrh), § 10 (pipeline pokynu vedle
   návrhu, „Apply a doc state“ — importní mód podle D22 na jednom
   místě), § 11 (`applyOptions.interpret`, chyby), tabulka issue kódů
   (které kódy vznikají jen v návrhu; `import_requires_instruction`),
   „Invariant ukládání“.
2. `docs/document-system.md` § 12b (generátory = pokyn), `docs/datasets.md`
   (sekce `docs/` = pokyn), `docs/vat-calculation.md` § 1.1 (kontrola
   kódu bez výjimky pro import), `CLAUDE.md` (odstavce Generované doklady
   a Kód DPH řádku).
3. `help/` jen pokud některá stránka popisuje chování, které se mění
   (import dokladů do V pořádku bez kódu) — `docs/help-authoring.md`.
4. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. `DocumentRoundTripMatrixTest`, `DocumentInstructionPayloadTest`,
   `DatasetRoundTripTest`, integrační testy importu dokladů.
2. `dataset-verify` tří veřejných sad: výsledky jako ve výchozím stavu
   v poznámkách `tasks/doc-saving-safety-net.md` (cesta návrhu se nesmí
   pohnout).
3. Demo sada: `ds-reset` + `dataset-seed` + `dataset-dump`, pak znovu
   seed a dump — druhý dump byte-shodný s prvním (až na manifest
   a kurzovní lístky); rozdíly částek proti stavu před taskem jen
   u dokladů sepsaných podle U11.
4. Periodická fakturace a opravný doklad nad demo sadou: vystavený
   doklad stejný jako před taskem (porovnej export).

## Dopad na import ze starého Shipardu

Runner posílá přes REST, tedy pokynem. Po tasku musí posílat hotový
doklad: kód DPH na každém řádku dokladu s DPH (`cz-000` u řádku, který
není plněním), `recapSource: "declared"` s kódy v rekapitulaci, důvod
opravy u opravných dokladů, výslovné `totalRoundingAuto`. Úprava runneru
je samostatné zadání v `old_shipard` (#166 etapa 6); do té doby import
dokladů, které to nesplňují, selže čitelnou chybou.

## Mimo rozsah

- `totals` jako kontrolní součet a výslovný mód zaokrouhlení
  (`tasks/doc-totals-rounding-explicit.md`).
- Reference: `PartyResolver`, `ItemResolver`, `_resolve` piny
  `useExisting`, side-creates, `autoCreateMode` — beze změny (D11–D14).
- Fyzické oddělení interpretace do vlastní třídy (etapa 5) — tady jen
  větvení.
- Povinná pole a výchozí hodnoty sdílené s formulářem (etapa 4).
- Export konceptu s `importNumber` a zástupným číslem.
- Runner starého Shipardu.

## Pasti

- `vatContext()` je cachovaný podle obsahu canonicalu — bez režimu
  v klíči vrátí náhled návrhu kontext pokynu a naopak.
- `preview()` a `apply()` sdílejí `transform()`; náhled návrhu nesmí
  změnou dostat validaci cílového stavu, kterou dnes nemá, pokud by tím
  přibyly nálezy v review — U6 platí pro pokyn; pro návrh jen přemapování
  chyby uložení (D21).
- `InvoiceBuilder` nepředává `totals` → automatika zaokrouhlení zapnutá;
  U4 to nesmí změnit.
- Datové sady v sekci `docs/` nesou `totals.totalAmount`: dnes z něj
  applier odhaduje mód zaokrouhlení. Po U4 se neodhaduje — sada, která
  na odhadu stála, vyjde s jinou částkou; U11 (dump před změnou) to
  podchytí jen tam, kde export `totalRoundingAuto` nese.
- `MessageProposalApplier` si `applyOptions` skládá sám — `interpret`
  z payloadu pošty nečti, režim tam určuje kód.
- Neopravuj testy odvozování tím, že změníš očekávání (U12).
- PHPUnit s úzkým `--filter`, grep s `--include`; integrační testy jen
  proti zdroji s třídou dat `free`.

## Hotovo když

- [x] `ApplyMode` na všech třech vstupech; volající podle U10
- [x] pokyn neprovádí žádné odvození z D19 a hodnoty DPH bere podle D20
- [x] chyba validace dokladu je 422 `validation_failed` s nálezy; náhled
      pokynu ji ukáže předem
- [x] `DocDocument` nemá výjimku z validace pro import (kód DPH, důvod
      opravy); `importNumber` v návrhu je chyba
- [x] snapshot partnera z importu má tvar snapshotu z formuláře
- [x] `DocumentInstructionPayloadTest` zelený; v matici zbývá jen mezera
      zaokrouhlení
- [x] `dataset-verify` veřejných sad beze změny; sady v repu dump → seed
      → dump byte-shodně
- [x] cílené testy, pak celá unit sada
- [x] dokumentace podle commitu 6, stav tasku

## Poznámky k implementaci

Implementováno 2026-11-22 v šesti commitech (`c561f9c3`, `31fd4bcc`,
`2b03a7ef`, `346dff88`, `3192b956` a commit dokumentace).

### Upřesnění přijatá při plánování

- **Náhled pokynu skládá data jako apply** (U6): dnešní data náhledu
  (`previewPlan()`) nemají řadu, partnera ani výchozí pohyby řádků, takže
  validace cílového stavu nad nimi hlásila chyby, které apply nemá. Náhled
  pokynu proto jde cestou `reconcile()` → `resolveSaveTargets()` (pokladna,
  řada, vlastní účet — společný krok s apply, v náhledu bez zakládání řady)
  → výchozí pohyby → `transform()` → `validate()` nad kopií dat. Nálezy na
  referenci, kterou apply teprve založí nebo která čeká na rozhodnutí
  (partner, položka řádku a její pohyb), a zámky záznamu náhled nehlásí.
- **Režim u každého volání v testech** (U12): `ApplyMode::Proposal`
  doplněný skriptem do volání ve 13 souborech, očekávání beze změny.

### Odchylky od zadání

- **Pošta volá návrh už od commitu 1** (`AnalysisController::previewMessage`,
  `MessageProposalApplier`), ne až v commitu 4 — jinak by mezi commity
  běžela pošta jako pokyn. `ExchangeController` (`interpret`) a schéma jsou
  v commitu 4 podle zadání.
- **Matice round-tripu se upravovala průběžně** (mezera místa plnění
  a nový tvar mezery zaokrouhlení v commitu 1, snapshoty v commitu 3), aby
  byly testy po každém commitu zelené; commit 5 nese jen
  `DocumentInstructionPayloadTest` a sdílené prostředí
  `RoundTrip/RoundTripEnvironment`.
- **Nové kódy nálezů:** `vat_recap_missing` a `vat_recap_code_missing`
  (převzatá rekapitulace bez řádků / bez kódu — D20 říká „chyba“, kód
  neurčuje), `document_validation_unavailable` (náhled pokynu, když validace
  dokladu spadne výjimkou).
- **Varování dokladu z úspěšného uložení** jdou do `_resolve.issues` jen
  v pokynu — výstup apply návrhu se nemění (`dataset-verify`).
- **Doklad Bez DPH v pokynu** dostane registraci DPH jen s výslovným
  `vat.registrationCountry`; bez něj žádnou (jako formulář).
- **Info `vat_pin_ignored` v pokynu** dostane i volba předmětu plnění
  a neplatná volba (pokyn volby nevaliduje).

### Stávající testy

- Testy applieru, které importují (`applyOptions.importNumber`), běží
  v **pokynu** — import je pokyn (D22). Čtrnáct jich po zrušení importních
  větví v odvozování selhalo v návrhu; dvanáct prošlo v pokynu **beze změny
  očekávání**. Dva jsou upravené podle D20:
  `testRecapCodeResolvesWithSupplierCountryWhenNoPrefix` (rekapitulace se
  v pokynu přebírá jen s výslovným `declared`, procento z payloadu se
  resolveru nepředává) a `testImportModeIsNotTreatedAsNonPayer` (jen režim).
- `DocDocumentCorrectiveTest` a `DocDocumentOutOfScopeRowsTest` hlídaly
  rušené výjimky importu — přepsané na pravidlo D22 (chyba při potvrzení
  i v importu, cíl V opravě dál jen varuje).
- Dva testy tvaru importního snapshotu (`DocumentApplierTest`) hlídaly
  bankovní spojení a `null` u prázdného DIČ — přepsané na D23.
- `DocumentExchangeEndpointTest` (integrační) čeká naučený kód dodavatele
  → režim návrh. `DatasetRoundTripTest`: prodejka ve fixtuře nese výslovné
  `totalRoundingAuto: false` (dřív plynulo z přítomnosti částky).

### Datové sady (U11)

Sekci `docs/` má v repu jen demo sada `it-servis` (295 dokladů); veřejné
sady `tests/datasets/cz/*` nesou jen `mail/`. Žádný doklad demo sady
nestál na odvození místa plnění, režimu ani kódu DPH. Generátor
(`tools/invoice-generator`, `dumpshape.total_rounding_auto`) nově píše
`totals.totalRoundingAuto` výslovně: zapnuto u prodejních typů tam, kde
s automatikou vyjde stejná částka (177 dokladů), jinak vypnuto (118).
Sada je přegenerovaná — v každém dokladu přibylo jen to jedno pole.

**Doklady, které po tasku vycházejí s jinou částkou** (mód zaokrouhlení
mimo automatiku formát nenese; zavře `tasks/doc-totals-rounding-explicit.md`)
— sedm přijatých faktur zaokrouhlených na koruny:

| doklad | před taskem | po tasku |
|---|---|---|
| 2260012 | 4 788,00 (zaokrouhlení −0,26) | 4 788,26 |
| 2260017 | 4 744,00 (−0,18) | 4 744,18 |
| 2260024 | 4 700,00 (−0,09) | 4 700,09 |
| 2260035 | 4 612,00 (+0,08) | 4 611,92 |
| 2260043 | 4 568,00 (+0,17) | 4 567,83 |
| 2260049 | 4 524,00 (+0,26) | 4 523,74 |
| 2260054 | 4 480,00 (+0,34) | 4 479,66 |

Platby těchto faktur ve výpisech sady zůstávají na zaokrouhlených
částkách, takže do části B po nich v saldokontu zbývají haléřové rozdíly
(neověřováno — důsledek, ne měření).

Dvě vydané faktury sady hrazené hotově (1260037, 1260067) příběh na
koruny nezaokrouhlil — generátor u hotovostních faktur automatiku #73
nemodeluje. Mají `totalRoundingAuto: false`, částka zůstává; se zapnutou
automatikou by vyšly na koruny.

### Ověření (2026-11-22)

- Unit sada: 11 990 testů zelených.
- Integrační nad `blkm-i` (`free`): matice round-tripu 18 / 18 (17 `holds`,
  1 `gap` — `issued-rounding-manual`), `DocumentInstructionPayloadTest`
  13 / 13, `DatasetRoundTripTest`, importní testy, `Docs`, `WorkOrders`.
  Širší běh (`Docs|Vat|Accounting|Accbal|Exchange|Reports|Prints|Assets|`
  `WorkOrders|Dataset|Mail`, 506 testů) má dvě selhání, obě **i na výchozím
  commitu** `d3d81730`: `VatReturnAccountingServiceTest::`
  `testAccountsFiledReturnAsDraftDocument` a `DocJournalPrintTest::`
  `testInvoiceJournalMatchesContractSnapshot` (snímek kontraktu bez `web`
  a `gov_e_box_id` v kontaktu).
- `dataset-verify` tří veřejných sad (`jfpl-7`, `im3v-h`, `gsbg-b`):
  JSON výsledku shodný s výchozím stavem po commitech 1 i 4 — cesta návrhu
  se nepohnula.
- Demo sada na `8yft-3`: seed → dump → seed → dump shodné až na manifest;
  proti dumpu před taskem se liší `totalRoundingAuto` (177×) a částky sedmi
  faktur výše, ostatní sekce beze změny (`setup/settings.jsonc` nese
  provozní stav stahování kurzů jako dřív).
- Periodická fakturace (10 konceptů) a opravný doklad nad demo sadou:
  řádky a částky shodné s výchozím stavem; **koncepty periodické fakturace
  nově dostávají výchozí registraci DPH** (dřív `vat_registration` prázdná)
  — důsledek D20, `InvoiceBuilder` posílá jen `vat.mode`.
- Živý náhled pokynu přes REST na `blkm-i`: řádek s `vat: {pct: 21}`
  a cílem V pořádku → `vat_code_missing` (error) na `rows.0.vat.code`.

### Nálezy k dalším etapám

- **Prázdné hodnoty ve snapshotu závisí na tom, jak je drží adresář.**
  D23 sjednotil identifikátory (IČO, DIČ, DIČ pro DPH: prázdný řetězec).
  Kontakt, ID datové schránky a adresa jdou z importu jako `null`, kdežto
  `PersonSnapshotBuilder` předává hodnotu sloupce — u osob uložených
  s prázdným řetězcem (na demo sadě `web` u 8 z 26 osob, `gov_e_box_id`
  u všech) dá formulář `""` a import `null`. Matice to neukáže, její
  fixtury mají tyto sloupce `NULL`. Zavřít jde jen jedním pravidlem
  prázdné hodnoty pro obě cesty (změna i formulářového snapshotu).
- `computed_total_mismatch` zůstává v náhledu pokynu varováním; část B ho
  nahradí kontrolním součtem `totals_check_failed`.
- Export konceptu dál nese `importNumber` se zástupným číslem — koncept ze
  sady vzniká v importním módu (mimo rozsah, beze změny).
- `docs/datasets.md` § 4 tvrdil, že doklady s `importNumber` snapshot
  partnera nestaví — opraveno podle skutečnosti.
