# Task: Interpretace dokladu jako samostatná část — hotový doklad vedle dnešní cesty návrhu

**Stav:** naplánováno — zadání 2026-11-23 (#166 etapa 5, část 1; D51, D52, D54, D57)

**Issue:** #166 (etapa 5)

**Cíl:** Kód, který „přemýšlí“ nad papírovou podobou dokladu (hledání
podle údajů, odvozování DPH, volby uživatele), odejde z `DocumentApplier`
do vlastní části a naučí se vrátit **hotový doklad** — payload platný jako
pokyn. Dnešní cesta návrhu zatím běží dál; test dokáže, že „pokyn nad
hotovým dokladem“ dává totéž. Přepnutí je navazující
`tasks/doc-interpretation-switch.md`.

## Před implementací přečti

- issue #166 — celé tělo a komentáře s rozhodnutími etap 3–5
- `tasks/doc-apply-instruction-mode.md`, `tasks/doc-format-references.md`,
  `tasks/doc-defaults-shared.md` včetně poznámek
- `docs/exchange-format.md` — § 3, § 8 (zvlášť § 8.4), § 9, § 10
  („Pipeline pokynu vedle návrhu“), § 12
- `modules/core/exchange/src/Document/DocumentApplier.php` — `preview()`,
  `apply()`, `resolveAll()`, `reconcile()`, `resolveOne()`, `resolvePin()`,
  `runSideCreates()`, `writeSupplierCodeMappings()`, `vatContext()`,
  `vatPins()`, `decideRowVatCode()`, `resolveRowVatCode()`,
  `resolveRowReverseChargeCode()`, `effectiveVatMode()`,
  `documentVatMode()`, `outOfScopeKind()`, `isSupplierNonPayer()`,
  `supplierVatGrossTotals()`, `resolveRecapSource()`,
  `deriveTotalRoundingMode()`, `skippedRowIndices()`,
  `defaultRowOperationsForApply()`, `correctionDecision()`,
  `annotateRowDisplay()`, `carryOverEnrichment()`, `withVatBlocks()`,
  `computePreviewAmounts()`, `transform()`, `transformRows()`, všechna
  `append…Issue(s)()`
- `Document/` — `VatCodeDerivation`, `VatModeDerivation`,
  `VatPlaceDerivation`, `SupplierVatPrices`, `SpecialPlaceSupply`,
  `CanonicalReferences`, `InstructionDefaults`, `DocumentValidator`
  (heuristiky návrhu), `ApplyMode`
- `Resolve/` — `PartyResolver`, `ItemResolver`, `UnitResolver`,
  `BankAccountResolver`, `AccountResolver`, `VatCodeResolver`
- volající návrhu: `modules/core/mail/src/MessageProposalApplier.php`,
  `src/Api/Controller/AnalysisController.php`,
  `src/Api/Controller/ExchangeController.php`,
  `modules/core/exchange/src/Dataset/Verify/ProposalApplyStep.php`
- testy návrhu: `tests/Unit/Module/Core/Exchange/Document/` (zvlášť
  `OutOfScopeHarness`, `ReferenceFixture`), `AiProposalApplyTest`

## Kontext

`DocumentApplier` má necelých 7 000 řádků a 157 metod; režim návrhu se
v něm větví na 32 místech ve 22 metodách. Návrh a pokyn sdílejí jednu
rouru a liší se podmínkami uvnitř. Po etapách 3 a 4 jde vše, co návrh
rozhodne, zapsat jako pokyn: reference, kódy DPH, režim, místo plnění,
registrace, rekapitulace, mód zaokrouhlení, výslovné `null`.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D51 — Interpretace je samostatná část** v `core.exchange`: papírová
  podoba dokladu + volby uživatele (`_resolve`) → **hotový doklad**
  (payload platný jako pokyn) + **zpráva** (co našla, co odvodila,
  kandidáti, nálezy). Doklady neukládá.
- ✓ **D52 — Hotový doklad je úplný:** každý odkaz referencí, hodnoty DPH
  a zaokrouhlení uvedené, vše s výchozí hodnotou uvedené výslovně včetně
  `null` — výchozí hodnoty pokynu se na návrh neuplatní. Částky z papíru
  se jako kontrolní součet nepředávají. Signály z D39 interpretace
  spotřebuje.
- ✓ **D54 — Chování návrhu se nemění.**
- ✓ **D57** — tři zadání; tohle je první.

## Upřesnění pro implementaci

- **U1 — Umístění.** Jmenný prostor
  `Shipard\Module\Core\Exchange\Document\Interpret\`. Vstupní třída
  (návrh názvu `DocumentInterpreter`) s jednou veřejnou operací
  `interpret(array $canonical): InterpretedDocument`; čte databázi,
  nezapisuje.
- **U2 — Přesun, ne kopie.** Metody návrhu z applieru (seznam v „Před
  implementací přečti“) se **přesunou** do tříd interpretace a dnešní
  cesta návrhu v applieru je volá (deleguje). Logika odvozování nesmí
  existovat dvakrát. Tok `preview()` / `apply()` se v tomto tasku nemění.
- **U3 — `InterpretedDocument`.**
  - `document` — hotový doklad (U4);
  - `report` — bloky, které dnes tvoří `_resolve` návrhu (strany, řádky,
    DPH, oprava, `valueRepairs`, souhrn) a nálezy; cesty nálezů míří do
    **původního** payloadu;
  - `pending` — co je před uložením potřeba založit (osoby, položky,
    bankovní účty partnerů) i s daty k založení a s místem v hotovém
    dokladu, kam přijde reference;
  - `rowMap` — index řádku hotového dokladu → index původního (vynechané
    řádky v hotovém dokladu nejsou);
  - `learn` — co se má po uložení naučit (dodavatelské kódy položek).
- **U4 — Hotový doklad (D52).**
  - reference číslem (id) na všech místech z D30; strana nebo položka,
    která se teprve založí, zůstává objektem s daty bez `ref` a je
    v `pending`;
  - `vat.mode`, `vat.place`, `vat.registration` (nebo `null`),
    `vat.recapSource`, `vatRecap` s kódy, `vat.roundingMode`;
  - řádky: `vat.code` (nebo `null`), `operation`, text, cena, jednotka
    systémovým kódem, množství — hodnota, nebo výslovné `null`; ceny
    řádků už převedené (doklad neplátce, doklad mimo české DPH);
  - `totals.totalRoundingAuto` a `totals.totalRoundingMode` výslovně;
    **žádné částky** v `totals`;
  - `payment.method`, `payment.ownBankAccount` (nebo `null`), `currency`,
    data dokladu tak, jak je dnes návrh předává;
  - bez signálů D39 (`item.ourCode`, holé číslo účtu,
    `vat.registrationCountry`), bez `_resolve`, bez
    `applyOptions.interpret` a `autoCreateMode`; ostatní `applyOptions`
    a `source` beze změny.
- **U5 — Stínový test.** Pro každý vstup spustí dnešní cestu návrhu
  a novou cestu „pokyn nad hotovým dokladem“ a porovná **data předaná
  k uložení** (hlavička, řádky, rekapitulace — výstup `transform()`)
  a u náhledu seznam nálezů (kód, cesta, závažnost) a blok `computed`.
  Vstupy:
  - všechny návrhy sekcí `mail/` tří veřejných sad (integrační, nad
    zdrojem s nahranou sadou);
  - fixtury unit testů návrhu (doklad mimo DPH, neplátce, přenesení,
    zvláštní místo plnění, převzatá rekapitulace, zaokrouhlení, vynechané
    řádky, volby uživatele, opravný doklad, cizí měna).
  Kde nová cesta potřebuje založit osobu nebo položku, test je založí
  předem stejně jako dnešní cesta (porovnávají se data dokladu).
  Rozdíl = chyba testu; **žádné výjimky v testu bez zápisu do poznámek**.
- **U6 — Co se nemění.** Pokyn, výměnný formát, REST, odpovědi návrhu,
  frontend. `ApplyMode` zůstává (zmizí v navazujícím tasku).

## Co je potřeba udělat

### Commit 1 — přesun kódu návrhu (U1, U2)

Třídy interpretace; applier deleguje. Unit sada beze změny očekávání.

### Commit 2 — hotový doklad (U3, U4)

`interpret()` + unit testy tvaru hotového dokladu (úplnost podle U4,
`pending`, `rowMap`).

### Commit 3 — stínový test (U5)

### Commit 4 — dokumentace a stav

Krátký oddíl v `docs/exchange-format.md` § 3 (interpretace jako část,
hotový doklad) — plný přepis dokumentace je
`tasks/doc-layers-documentation.md`. `**Stav:**`,
`python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. Stínový test nad třemi veřejnými sadami — bez rozdílu.
2. `dataset-verify --json` tří veřejných sad shodný s kódem před taskem.
3. Matice round-tripu, případy payloadů, invariant formulář × pokyn —
   beze změny.

## Mimo rozsah

- Přepnutí návrhu na novou cestu, odstranění režimu z applieru, endpoint
  `/interpret` (`tasks/doc-interpretation-switch.md`).
- Dokumentace po vrstvách (`tasks/doc-layers-documentation.md`).
- Výchozí hodnoty formuláře pro doklady z návrhu (odloženo, po evaluaci).
- Obohacení řádků z historie a štítky obsahu (`Enrich/`) — běží před
  interpretací a zůstávají, kde jsou.

## Pasti

- `vatContext()` čtou i kroky, které zůstanou v applieru (kurz, datum
  sazby, registrace) — při přesunu rozděl, co je odvození (návrh) a co
  kontext z payloadu (pokyn).
- Vynechané řádky posouvají indexy: nálezy a bloky zprávy míří do
  původního payloadu, varování dokladu po uložení do hotového — proto
  `rowMap`.
- Hotový doklad nesmí nést částky `totals`: pokyn by je bral jako
  kontrolní součet a doklad z papíru s haléřovým rozdílem by odmítl.
- Výslovné `null` je nutné všude, kde má pokyn výchozí hodnotu — jinak
  řádek bez rozhodnutého kódu DPH dostane základní sazbu.
- PHPUnit s úzkým `--filter`, grep s `--include`; integrační testy jen
  proti zdroji s třídou dat `free`.

## Hotovo když

- [ ] kód návrhu je ve třídách interpretace, applier ho jen volá; žádná
      logika odvozování není dvakrát
- [ ] `interpret()` vrací úplný hotový doklad, zprávu, `pending`,
      `rowMap`, `learn`
- [ ] stínový test bez rozdílu nad veřejnými sadami i unit fixturami
- [ ] `dataset-verify` a testy invariantu beze změny
- [ ] cílené testy, pak celá unit sada; stav tasku
