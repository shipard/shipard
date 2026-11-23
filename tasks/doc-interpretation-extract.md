# Task: Interpretace dokladu jako samostatná část — hotový doklad vedle dnešní cesty návrhu

**Stav:** hotovo — 2026-11-23 (#166 etapa 5, část 1; D51, D52, D54, D57); navazuje `tasks/doc-interpretation-switch.md`

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

- [x] kód návrhu je ve třídách interpretace, applier ho jen volá; žádná
      logika odvozování není dvakrát
- [x] `interpret()` vrací úplný hotový doklad, zprávu, `pending`,
      `rowMap`, `learn`
- [x] stínový test bez rozdílu nad veřejnými sadami i unit fixturami
      (jeden zapsaný známý rozdíl — poznámky)
- [x] `dataset-verify` a testy invariantu beze změny
- [x] cílené testy, pak celá unit sada; stav tasku

## Poznámky k implementaci

### Commit 1 — přesun kódu návrhu (2026-11-23)

Rozdělený na čtyři commity, každý čistý přesun se zelenou unit sadou:
`aa9caefd` (kontext z payloadu), `b47cd485` (DPH, rekapitulace,
zaokrouhlení), `d8601026` (hledání záznamů, volby, zakládání), `8318170c`
(zpráva návrhu, kontroly papíru).

**Sdílená vrstva** (`Document\`, čte ji pokyn i interpretace) — vznikla
proto, že metody, které návrh i pokyn volají obě, byly v applieru privátní
a třídy interpretace by je nezavolaly (past „kontext z payloadu ×
odvození“):

- `PayloadContext` — typ dokladu, importní mód, registrace DPH z reference,
  hlavička a datum sazby, původní doklad opravy, kurz, země číselníku kódů,
  jejich nálezy a **kontext DPH pokynu** (`vatContext()`); cache per
  canonical, které dřív držel applier.
- `PayloadResolve` — kroky resolve nad tím, co payload říká: blok reference,
  partner jen přes `ref`, účet řádku referencí, dimenze, kód DPH řádku
  ověřený proti číselníku, předmět plnění, řádek převzaté rekapitulace.

**Třídy interpretace** (`Document\Interpret\`):

| Třída | Obsah |
|---|---|
| `VatInterpretation` | kontext DPH návrhu (`context()` — dřívější větev odvozování ve `vatContext()`), volby DPH z `_resolve`, druh plnění ze štítků, rozhodnutí o kódu řádku, doklad neplátce a mimo české DPH, režim ověřený proti číslům, ceny řádků s daní dodavatele, registrace podle signálu země, nálezy režimu a hlavičky |
| `RecapInterpretation` | převzít × přepočítat rekapitulaci, kód rekapitulace z řádků, aritmetika, důvod přepočtu |
| `RoundingInterpretation` | odhad módu zaokrouhlení z čísel, automatika podle `totals.totalAmount` |
| `RecordMatching` | `resolveAll()` návrhu (strany a položky podle údajů, účet partnera, účet holým číslem, jednotka aliasem), vynechané řádky, `reconcile()` s volbami uživatele a `autoCreateMode` |
| `PendingRecords` | zakládání osob, účtu dodavatele a položek (zapisuje — mimo samotnou interpretaci) |
| `SupplierCodeLearning` | učení dodavatelských kódů (zápis přes `executeSql()` applieru, aby ho testovací podtřídy dál zachytávaly) |
| `ProposalDefaults` | pohyb řádku podle typu položky, výchozí způsob úhrady |
| `ProposalReport` | `item.display` / `effectiveAccount` řádků, přenos auditu obohacení, `computed_total_mismatch` |
| `PaperChecks` | z `DocumentValidator`: povinná pole návrhu, čtyři heuristiky nad čísly, `import_requires_instruction` |

**Applier:** 6 987 → 3 260 řádků. Režim se v něm čte na 24 místech
v 18 metodách (dřív 32 ve 22) a všechna jsou buď přepínač „návrh se ptá
interpretace, pokyn payloadu“ (`vatContext()`, `effectiveVatMode()`,
`resolveAll()`, `resolveRecapSource()`, …), nebo krok pořadí v `preview()`
/ `apply()` / `transform()`. `resolveAll()` má dvě varianty: návrh
v `RecordMatching`, pokyn (jen reference) v applieru; společné kroky drží
`PayloadResolve`.

**Co pokyn dál volá z interpretace** (k rozhodnutí v navazujícím tasku —
tady se tok neměnil):

- `RecordMatching::reconcile()` — pokyn `_resolve` nenese, takže je to jen
  projekce nalezených referencí do plánu;
- `ProposalReport::annotateRowDisplay()` a `carryOverEnrichment()` — náhled
  pokynu dnes nese `item.display` a `effectiveAccount` stejně jako návrh.

**Testy:** očekávání beze změny. Dva testy `DocumentApplierTest`, které
reflexí volaly `resolveOne()` a `resolvePin()` na applieru, míří na
`RecordMatching`. Ostatní metody, na které testy sahají reflexí
(`vatContext`, `resolveAll`, `reconcile`, `runSideCreates`, `transform`,
`appendVatModeIssue`, …), v applieru zůstaly jako přepínače se stejnou
signaturou.

**Ověření** (výchozí stav = commit `b462e89f`, zachycený před změnou):

- celá unit sada 12 175 testů zelená; cílená sada výměnného formátu, pošty
  a `docs.core` 4 600 testů se stejným počtem asercí (21 482) před i po;
- integrační testy dokladů, DPH, účtování, pošty a sad nad volným zdrojem
  `blkm-i8`: 554 testů, 16 přeskočených, bez selhání;
- tři veřejné sady nad zdroji testovacího kola (čerstvý seed →
  `dataset-verify --json`): výstup bajtově shodný s výchozím stavem;
  uložené hlavičky, řádky, rekapitulace, deník, osoby, položky, dodavatelské
  kódy a účty partnerů shodné (bez časových sloupců).

**Strážní test vázaný na cestu souboru:** `DisplayDateUsageTest` drží
výjimku pro formát data ve zprávě o kurzu podle cesty souboru — kód se
přestěhoval do `PayloadContext.php`, výjimka s ním. Cílený běh testů
výměnného formátu to neukáže; po přesunu mezi soubory pouštět celou sadu.

### Commit 2 — hotový doklad (2026-11-23, `4dd3bba6`)

- **`DocumentInterpreter::interpret()`** projde kroky návrhu až po
  rozhodnutí nad volbami uživatele (`RecordMatching::reconcile()`) přes
  tytéž třídy jako applier; hotový doklad z nich skládá čistá funkce
  `FinishedDocument::build()`. Schéma ověřuje volající.
- **`InterpretedDocument`** nese nad rámec U3:
  - `decisionError` (`unresolved_required` / `conflict`) a `decisionIssues`
    — nálezy, které dnes vznikají až při uložení, ne v náhledu;
  - `limitations` — co hotový doklad nevyjádří (níž);
  - `paper` — papírová podoba po normalizaci (payload, který návrh vrací
    v odpovědi) a `recapFallback` (důvod přepočtu rekapitulace pro
    `computed`);
  - `withCreated()` — doplní reference založených záznamů ve tvaru, který
    vrací `PendingRecords::create()`; `PendingRecords::createFrom()` zakládá
    přímo ze seznamu `pending`.
- **Pravidla sjednocená na jedno místo**, aby je `transform()` i interpret
  nebraly každý po svém: `RoundingInterpretation::resolveAuto()` /
  `resolveMode()`, `ProposalDefaults::paymentMethodFor()`,
  `PayloadResolve::vatBlocks()` / `summary()` / `keptRowIndices()`.
- **Pohyb řádku se zakládanou položkou:** typ položky plyne z druhu, který
  položka při založení dostane — `PendingRecords::plannedItemType()` ho
  přečte předem, takže pohyb je v hotovém dokladu už před založením.
- **Řada a pokladna** se nedoplňují (jen se reference převede na id):
  `resolveSaveTargets()` se chová v obou režimech stejně a vázanou řadu
  může založit až apply.
- **Strana s volbou „bez osoby“** (`skip`) ztrácí v hotovém dokladu název,
  IČO a DIČ — jinak by ji pokyn odmítl `reference_missing`; adresa
  a bankovní spojení zůstávají.
- **`ProposalReport::withComputed()`** — zpráva návrhu s blokem `computed`
  z náhledu pokynu (indexy řádků přes `rowMap`, `recapFallback`,
  `computed_total_mismatch`). Použije ji vstup návrhu v navazujícím tasku.

**Meze formátu** (`limitations`, k rozhodnutí v navazujícím tasku):

- Bankovní účet partnera nemá referenci. Pokyn ho váže přesnou shodou
  s účtem osoby; po založení z `pending` se najde. Účet zvolený uživatelem
  (`useExisting`) jiný než shoda vyjádřit nejde (`partner_bank_pin`).
- Jednotka nalezená zkratkou nebo názvem, která nemá systémový kód
  (`unit_without_system_code`) — řádek zůstane bez jednotky.

### Commit 3 — stínový test (2026-11-23, `46c65181`)

- **Co se porovnává** (`ShadowRunner`): u náhledu **celý blok `_resolve`**
  — bloky stran a řádků, DPH, nálezy včetně textů a pořadí, souhrn,
  `computed` — a vrácený payload; u uložení data předaná
  `saveDocument()` před `beforeSave()` (výstup `transform()`) a výsledek
  (uloženo × kód chyby). Navíc úplnost: pokyn nad hotovým dokladem nesmí
  doplnit žádnou výchozí hodnotu. Je to přísnější než U5 (kód, cesta,
  závažnost).
- **Unit fixtury** — 33 případů: `ProposalShadowTest` (25),
  `DocumentApplierCorrectionTest` (4, opravný doklad) a
  `DocumentApplierExchangeRateTest` (4, kurz z lístku).
- **Veřejné sady** — `DatasetProposalShadowTest`: 64 návrhů tří sad, každý
  dvakrát (volby `dataset-verify`: zakládat vše, cíl V pořádku; „jednoklik“:
  zakládat bezpečně, cíl Koncept). Bez rozdílu.
- **Test nad sadou běží naostro a jen na vyžádání**
  (`SHIPARD_SHADOW_DATASET=1`). Zápis verdiktu v `MessageProposalApplier`
  si otevírá vlastní transakci (`START TRANSACTION` vnější transakci
  potichu potvrdí), takže běh nejde zabalit a vrátit — první pokus
  o vnější rollback návrhy sady použil natrvalo. Nová cesta a varianta
  „jednoklik“ běží v savepointu, který se vrací; po testu jsou návrhy
  použité a další běh chce nový `dataset-seed`.
- **Pořadí cest:** nová cesta běží první (izolovaně), dnešní potom —
  jinak by druhé uložení narazilo na idempotenci podle zprávy a na doklad
  se stejným číslem dodavatele. Záznamy, které obě cesty zakládají, mají
  různá id; porovnání je převádí přes klíč v `pending`.

**Výjimky stínového testu:**

1. **Normalizace `total_rounding_mode`** (`ShadowRunner::NORMALIZED`): návrh
   bez odhadnutého módu klíč nepošle (platí výchozí hodnota sloupce 0),
   pokyn pošle výslovně 0 („na haléře“). Uložený doklad je stejný. Bez
   normalizace se liší většina návrhů sad (negativní kontrola nad sadou
   plátce: 40 řádků rozdílů).
2. **Známý rozdíl — režim Bez DPH s kódy řádků** (případ
   `KNOWN_GAPS` v `ProposalShadowTest`, přesné znění rozdílu v testu):
   předloha říká `vat.mode: none`, ale řádek nese daň dodavatele, takže to
   není doklad od neplátce ani doklad mimo české DPH. Dnešní cesta uloží
   `vat_mode` 0 **a** řádek s kódem DPH (v tuzemsku `cz-114`), doklad pak
   má i řádek rekapitulace. Pokyn doklad Bez DPH ukládá bez kódů (D20),
   takže nová cesta kód ani rekapitulaci nemá. **Je to rozdíl uloženého
   dokladu**, ne jen tvaru dat — k rozhodnutí před přepnutím: buď ho
   přijmout (doklad Bez DPH kódy nést nemá, #164), nebo návrhu takový
   doklad přepnout na režim s DPH. Ve veřejných sadách se nevyskytuje.

**Závěrečné ověření** (proti výchozímu commitu `b462e89f`):

1. Stínový test nad třemi veřejnými sadami (čerstvý seed) — bez rozdílu;
   nad unit fixturami bez rozdílu kromě zapsaného známého rozdílu.
2. `dataset-verify --json` tří veřejných sad bajtově shodný s kódem před
   taskem; uložené hlavičky, řádky, rekapitulace, deník, osoby, položky,
   dodavatelské kódy a účty partnerů shodné (bez časových sloupců).
3. Integrační testy nad volným zdrojem `blkm-i8` — matice round-tripu,
   případy payloadů, invariant formulář × pokyn, idempotence, pošta, sady:
   555 testů, 17 přeskočených (z toho stínový test nad sadou, který běží
   jen na vyžádání), bez selhání, stejný počet asercí jako po commitu 1.
4. Celá unit sada: 12 213 testů zelených.

`DocumentApplier`: 6 987 řádků před taskem → 3 166 po něm.
