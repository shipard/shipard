# Task: Reference ve formátu dokladu — pokyn bez hledání podle jména a bez zakládání

**Stav:** hotovo — implementováno 2026-11-22 (#166 etapa 3, část C1; D9–D14, D30–D39)

**Issue:** #166 (etapa 3, část C)

**Cíl:** Každý odkaz na záznam ve formátu dokladu je **jedno pole
s referencí** podle D9 (`42`, `"@klíč"`, `"@sloupec:hodnota"`) a hledá ho
obecný `ReferenceResolver`. Pokyn nehledá podle jména, nic nezakládá
a nenalezená reference je chyba. Export, generátory dokladů a datové sady
přejdou na reference. Návrh (pošta, ISDOC) dál hledá po svém.

Dvě místa, která dnes pole ve formátu nemají (partner na hlavičce
účetního a pokladního dokladu, partner na řádku), řeší navazující
`tasks/doc-format-partner-fields.md`; do té doby je pokyn jako jediné čte
z `_resolve`.

## Před implementací přečti

- issue #166 — D8–D17, D18–D29, komentář s rozhodnutími části C
- `tasks/exchange-key-references.md`, `tasks/doc-apply-instruction-mode.md`,
  `tasks/number-series-vat-registration-codes.md` včetně poznámek
- `docs/exchange-format.md` — § 5–§ 10 (zvlášť § 6 Party, § 7 Row, § 8
  Resolve včetně § 8.6, § 9 `_resolve`, § 10 „Apply a doc state“,
  `autoCreateMode`), tabulka issue kódů
- `docs/datasets.md` — § 1, § 4 (limity: partner účetního dokladu, vlastní
  účet bez kódu, opravný doklad)
- `modules/core/exchange/src/Resolve/` — `ReferenceValue`, `ReferenceResolver`,
  `ReferenceException`, `PartyResolver`, `ItemResolver`, `UnitResolver`,
  `BankAccountResolver`, `DimensionResolver`, resolver účtů
- `modules/core/exchange/src/Document/DocumentApplier.php` — `resolveAll()`,
  `reconcile()`, `resolvePin()`, `resolveOne()`, `autoCreateAllowed()`,
  `runSideCreates()`, `prepareItemCreatePayload()`,
  `writeSupplierCodeMappings()`, `resolveSaveTargets()` (řada, pokladna,
  vlastní účet), `resolveNumberSeriesFor()` / `resolveNumberSeriesById()`,
  `resolveBoundNumberSeries()`, `resolveCashDeskIdByCode()`,
  `resolveOwnBankAccountByCode()`, `instructionVatRegistration()`,
  `correctionDecision()`, `resolveDimensions()`, `transform()`,
  `transformRows()`, `buildImportPartnerSnapshot()`
- `modules/core/exchange/src/Document/DocumentValidator.php`
- `modules/core/exchange/src/Export/DocumentExporter.php`, `CanonicalParty.php`
- `modules/core/exchange/src/Enrich/RowHistoryEnricher.php`,
  `RowEnrichmentPipeline.php`
- `modules/economy/workOrders/src/Invoicing/InvoiceBuilder.php`,
  `modules/docs/core/src/Corrective/CorrectiveDocBuilder.php`
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` + `.json`
  + kopie v `modules/core/mail/profiles/czech_general.jsonc`
- generátor sad: `tools/invoice-generator/invoicegen/` (`dumpshape.py`,
  `story.py`, `demo.py`, `model.py`, `expand.py`), `demo/datasets/build.py`
- testy: `DocumentRoundTripMatrixTest` + `RoundTrip/`,
  `DocumentInstructionPayloadTest`, `DocumentApplier*Test`,
  `tests/Integration/Exchange/*ImportTest.php`

## Kontext

- Reference dokladu mají dnes vlastní pole a pravidla podle typu: strana
  se hledá podle IČO → DIČ → daňového ID → jména, položka podle
  `ourCode` → dodavatelského kódu → EAN → SKU → jména, řada kódem
  z čísla dokladu nebo id, vlastní účet id nebo kódem, pokladna, dimenze
  a účet holou hodnotou. Interní id jde předat jen volbou
  `_resolve.*.userAction = "useExisting:<id>"` — tak to dělají generátory
  (`InvoiceBuilder`, `CorrectiveDocBuilder`) i import.
- Nenalezený účet nebo jednotka je dnes jen varování („řádek bude bez
  účtu“, „doplněna default“); nenalezenou stranu nebo položku applier
  podle `autoCreateMode` založí.
- Po etapě 2 má každá cílová tabulka `exchangeKey`, řady a registrace DPH
  kód (D16, D17) a existuje `ReferenceResolver`. Po části A existuje režim
  pokyn × návrh.

## Rozhodnutí k designu

Potvrzená (#166):

- ✓ **D9, D10, D15** — gramatika reference, přesná shoda, dvojtečka.
- ✓ **D11–D14** — jedno pole na referenci, pokyn nezakládá, interpretace
  dohledává po svém, řez bez přechodného období.
- ✓ **D30 — Místa referencí** (tabulka v U1).
- ✓ **D31 — Pokyn nezakládá** osoby, položky ani bankovní účty.
  Nenalezená nebo nejednoznačná reference je chyba 422 — i u účtu řádku
  a jednotky, kde je dnes varování.
- ✓ **D32 — Pokyn nečte `_resolve`** (dokončí
  `tasks/doc-format-partner-fields.md`).
- ✓ **D33 — Návrh hledá jako dnes;** uvedený `ref` má přednost.
  Obohacení z historie řádků zapisuje `ref`.
- ✓ **D34 — Export píše stranu jako objekt s `ref` a daty** (data nese
  dobový snapshot v importním módu).
- ✓ **D35 — Osobu jde odkázat i přes IČO nebo DIČ**
  (`"@company_id:…"`, `"@vat_id:…"`); dvě osoby se stejnou hodnotou jsou
  chyba.
- ✓ **D36 — Registrace DPH je reference** `vat.registration`
  (`"@cz"`), nepovinná: bez ní výchozí registrace zdroje dat, u dokladu
  Bez DPH žádná.
- ✓ **D37 — Jednotka zůstává holý kód;** v pokynu jen přesný systémový
  kód, neznámá jednotka je chyba. Zkratky a aliasy jen v návrhu.
- ✓ **D38 — Autor zůstává id** v `applyOptions.author`.

- ✓ **D39 — Signály návrhu zatím zůstávají.** Uložené návrhy z pošty
  a obohacení nesou `rows[].item.ourCode`, účet řádku holým číslem
  a `vat.registrationCountry`. Tato tři pole zůstávají ve schématu jako
  **signály platné jen v návrhu**; pokyn je odmítá (chyba, ne tiché
  ignorování). Je to dočasný stav — časem je lze odstranit (převodem
  uložených návrhů); v dokumentaci je proto označ jako dočasná. Všechna
  ostatní stará pole mizí bez náhrady (D14).

## Upřesnění pro implementaci

- **U1 — Místa referencí (D30).**

  | pole | cílová tabulka | nahrazuje |
  |---|---|---|
  | `supplier`, `customer`, `balanceParty` — reference, nebo objekt s `ref` | osoby | hledání podle identifikátorů a jména |
  | `rows[].item` — reference, nebo objekt s `ref` | položky | `item.ourCode` a další kódy (v pokynu) |
  | `numberSeries` (kořen dokladu) | řady dokladů | `applyOptions.numberSeriesCode`, `numberSeriesId` |
  | `payment.ownBankAccount` | vlastní bankovní účty | `applyOptions.importOwnBankAccount` |
  | `cashDesk` | pokladny | holý kód |
  | `dimensions.<id>`, `rows[].dimensions.<id>` | cílová tabulka dimenze | holá hodnota klíče |
  | `rows[].account` | účty osnovy | holé číslo (v pokynu) |
  | `vat.registration` | registrace DPH | `vat.registrationCountry` (v pokynu) |
  | `correction.originalDoc` | doklady | dohledání podle `originalDocNumber` |

  `correction.originalDocNumber` zůstává jako text (souhrnný opravný
  doklad, číslo na tisku). Strana vlastní firmy (`selfParty`) referenci
  nenese. `supplier.bankAccount` zůstává daty: jdou do sloupců hlavičky,
  vazba na účet partnera vznikne jen přesnou shodou s jeho existujícím
  účtem — pokyn účet nezakládá.
- **U2 — Schéma.** Typ „reference“ jako společná definice (kladné celé
  číslo, nebo řetězec začínající `@`). Strana a položka: `oneOf` reference
  / objekt; objekt má `ref` vedle dnešních dat. Odstraněná pole podle
  tabulky (kromě signálů z D39). Všechny tři kopie schématu a strážní
  testy; prompt profilu pošty se nemění.
- **U3 — Pokyn (D31).**
  - Reference jde přes `ReferenceResolver::resolve()`; výjimky resolveru
    → nález s `severity: error`, kódem resolveru (`reference_not_found`,
    `reference_ambiguous`, `reference_invalid`, …) a cestou pole;
    odpověď 422 `validation_failed`.
  - Strana nebo položka jako objekt **bez `ref`** s identifikujícími
    údaji (název, IČO, kód) = chyba `reference_missing` — ne tiché
    „bez partnera“ / „bez položky“.
  - Signál z D39 v pokynu = chyba `signal_not_allowed` s radou, které
    pole použít.
  - `rows[].unit`: přesná shoda na systémový kód jednotky; jinak chyba
    `unit_not_found`.
  - Žádné side-creates, žádné `autoCreateMode` / `createMissingEntities`
    (v pokynu se nečtou); `writeSupplierCodeMappings()` už v pokynu
    neběží (část A).
  - `numberSeries` chybí → výchozí řada jako dnes; u typu vázaného na
    pokladnu určuje řadu pokladna a uvedená jiná řada je chyba
    `number_series_conflict`.
  - `vat.registration` podle D36; v importním módu bez ohledu na platnost
    k datu (D22).
  - `_resolve` se v pokynu nečte, **s dočasnou výjimkou**
    `_resolve.partner` a `_resolve.rows[].partner` (do
    `tasks/doc-format-partner-fields.md`).
- **U4 — Návrh (D33).** Hledání stran a položek, volby uživatele,
  zakládání podle `autoCreateMode` beze změny. Navíc: je-li u strany nebo
  položky `ref`, použije se a hledání se přeskočí (nenalezený `ref` je
  i tady chyba). Ostatní místa z U1 berou v návrhu referenci stejně jako
  pokyn; signály z D39 jen v návrhu. `RowHistoryEnricher` a
  `RowEnrichmentPipeline` zapisují `item.ref` a účet jako referenci;
  uložené návrhy se starým tvarem (`ourCode`, holé číslo účtu) návrh čte
  dál.
- **U5 — Export (D34).** `DocumentExporter`: strana `{ref: "@<person_id>",
  …data}`, položka `{ref: "@<kód>", name}`, ostatní místa z U1 referencí
  přirozeným klíčem; `correction.originalDoc` jen když odkaz existuje.
  Záznam bez hodnoty klíče (osoba bez `person_id` nenastane; položka
  nebo vlastní účet bez kódu ano) = varování dumpu a pole se vynechá,
  jako dnes u vlastního účtu.
- **U6 — Generátory dokladů.** `InvoiceBuilder` a `CorrectiveDocBuilder`
  posílají reference číslem (id) místo voleb v `_resolve` a místo
  `numberSeriesId` / `importOwnBankAccount`; `CorrectiveDocBuilder`
  `correction.originalDoc`. Výsledné doklady stejné jako před taskem.
- **U7 — Datové sady a generátor sad.** Sekce `docs/` demo sady
  a `tools/invoice-generator` píšou reference ve tvaru, jaký dá dump
  (osoby sady proto potřebují stálé `personId`). Kritérium: seed → dump →
  seed → dump stabilní a sekce `docs/` dumpu byte-shodná se sadou v repu.
  Sekce `mail/` (uložené návrhy) se nemění — čte je návrh (D39).
- **U8 — Testy invariantu.** Matice round-tripu beze změny výsledku
  (20 / 20). Případy payloadů navíc: reference nenalezená; nejednoznačné
  IČO; strana jen názvem v pokynu → `reference_missing`; `"42"` jako
  řetězec → `reference_invalid`; položka kódem; osoba přes
  `"@company_id:…"`; účet nenalezený (pokyn chyba, návrh varování);
  neznámá jednotka; `item.ourCode` v pokynu → `signal_not_allowed`;
  týž payload v návrhu projde; návrh s `ref` hledání přeskočí; řada
  `"@invno"`; cizí řada u pokladního dokladu → `number_series_conflict`;
  registrace `"@cz"` a bez pole.

## Co je potřeba udělat

### Commit 1 — schéma a reference v pokynu (U1–U3)

1. Schéma (tři kopie), validátor.
2. Applier: místa z U1 přes `ReferenceResolver`, chyby podle U3.
3. Unit testy pokynu pro každé místo a každou chybu.

### Commit 2 — návrh a obohacení (U4)

1. `ref` má přednost; obohacení píše reference; starý tvar čitelný.
2. Stávající testy návrhu beze změny očekávání, kromě tvaru obohacení.

### Commit 3 — export, generátory dokladů (U5, U6)

1. `DocumentExporter`, `CanonicalParty`.
2. `InvoiceBuilder`, `CorrectiveDocBuilder` + jejich testy.

### Commit 4 — datové sady a generátor sad (U7)

1. Generátor a přegenerovaná demo sada; fixtury integračních testů.

### Commit 5 — testy invariantu (U8)

### Commit 6 — dokumentace a stav

1. `docs/exchange-format.md`: § 5–§ 8 přepsat podle U1 (tabulka míst
   referencí na jednom místě, § 6 a § 7 strana a položka jako reference,
   § 8 hledání podle údajů jen pro návrh), § 9 (`_resolve` jen návrh),
   § 10, § 11, tabulka issue kódů.
2. `docs/datasets.md` (§ 1 „žádná interní id“ → reference; § 4 limity),
   `docs/document-system.md` § 12b, `docs/work-orders.md` (generátor),
   `CLAUDE.md`.
3. Návod pro klienta API: krátká kapitola „Doklad skriptem“
   v `docs/exchange-format.md` § 11 s minimálním payloadem vydané faktury
   (odběratel a položka referencí, bez `totals`).
4. `**Stav:**`, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. Matice a případy payloadů, `DatasetRoundTripTest`, importní testy.
2. `dataset-verify` tří veřejných sad beze změny (cesta návrhu).
3. Demo sada podle U7; periodická fakturace a opravný doklad nad ní —
   doklady stejné jako před taskem.
4. Minimální payload z návodu přes REST `/preview` a `/apply` na
   ukázkovém zdroji.

## Dopad na import ze starého Shipardu

Runner musí místo voleb `useExisting` posílat reference (id z vlastní
mapy, nebo přirozený klíč), řadu jako `numberSeries`, vlastní účet jako
`payment.ownBankAccount`, a osoby a položky nahrávat předem — pokyn je
nezaloží. Etapa 6.

## Mimo rozsah

- Pole `partner` na hlavičce účetního a pokladního dokladu
  a `rows[].partner` (`tasks/doc-format-partner-fields.md`).
- Ostatní výměnné formáty (osoby, položky, zakázky, majetek, výpisy)
  a jejich reference.
- Výchozí hodnoty sdílené s formulářem (etapa 4) — výchozí vlastní účet,
  povinná pole.
- Fyzické oddělení interpretace (etapa 5).
- Runner starého Shipardu.

## Pasti

- `doc_number` nemá unikátní index a čísla různých typů se mohou shodovat
  — `correction.originalDoc` přirozeným klíčem může být nejednoznačný
  (chyba D10); generátory posílají id.
- Uložené návrhy v databázi i v sekci `mail/` sad mají starý tvar — návrh
  je musí číst beze změny výsledku (`dataset-verify` je strážce).
- `additionalProperties: false`: pole odstraněné ze schématu shodí
  validaci každého payloadu, který ho ještě nese — sady, fixtury
  a generátory proto patří do téhož běhu jako schéma.
- Strana jako objekt s `ref` a daty: mimo importní mód se snapshot staví
  z adresáře, data z payloadu se nepoužijí; v importním módu naopak.
- Pokladní doklad: řadu určuje pokladna (`resolveBoundNumberSeries()`
  zakládá řadu líně) — zachovej.
- PHPUnit s úzkým `--filter`, grep s `--include`; integrační testy jen
  proti zdroji s třídou dat `free`.

## Hotovo když

- [x] každé místo z U1 je jedno pole s referencí; stará pole kromě D39
      ve schématu nejsou
- [x] pokyn hledá jen přes `ReferenceResolver`, nic nezakládá, chyby
      podle U3
- [x] návrh beze změny výsledku; `ref` má přednost; obohacení píše
      reference
- [x] export, generátory dokladů, generátor sad a demo sada na referencích
- [x] matice 20 / 20, nové případy payloadů, `dataset-verify` beze změny
- [x] cílené testy, pak celá unit sada
- [x] dokumentace včetně návodu „Doklad skriptem“, stav tasku

## Poznámky k implementaci

Implementováno 2026-11-22 ve čtyřech commitech kódu (`6bdee605`,
`8ea40789`, `45cff21a`, `a452dfc4`) a commitu dokumentace.

### Upřesnění přijatá při plánování

- **Skalární reference se uvnitř applieru rozbalí.** `"supplier": 42`
  a `"item": "@KOD"` se po validaci schématu převedou na objekt s `ref`
  (`CanonicalReferences::expand()`), takže applier, validátor
  a normalizace hodnot čtou jeden tvar. Odpověď vrací rozbalený tvar.
- **Tvar referencí na jednom místě** — `Document\CanonicalReferences`:
  rozbalení, identifikující údaje strany a položky, signál účtu, volby
  `_resolve`, které pokyn nečte, a čtení / zápis položky a účtu řádku
  v návrhu (obohacení, pásmo karty, zvláštní místo plnění).
- **Původní doklad opravného dokladu:** podle `originalDocNumber` se už
  nic nedohledává ani v návrhu (U1 nemá u `correction.originalDoc`
  poznámku „v pokynu“). Volba `_resolve.correction` v návrhu zůstává;
  reference má přednost. Nález `correction_original_not_found` /
  `…_ambiguous` zanikl; reference na doklad jiného typu je chyba
  `correction_original_not_supported`.
- **Stálé číslo osoby v demo sadě:** `personId` je zapsané v příběhu
  (`partners.jsonc`, `story.jsonc`) s dosavadními hodnotami, generátor ho
  už neodvozuje z pořadí. Soubory `persons/` se nezměnily.
- **`numberSeries` v exportu vždy** u typu nevázaného na pokladnu
  (i výchozí řada); u pokladních typů se nevyváží — řadu určuje pokladna.
- **Registrace DPH, která k datu dokladu neplatí** (reference, mimo
  import), je chyba `vat_registration_not_valid` — výslovně určená
  registrace nesmí tiše vypadnout. `CorrectiveDocService` proto registraci
  původního dokladu předává jen, když k datu vystavení platí.
- **Reference dimenze:** chyby mají kód resolveru (`reference_not_found`
  …) s názvem dimenze ve zprávě; `dimension_not_found` zanikl,
  `dimension_unknown` zůstává. `DimensionResolver::resolve()` bere
  referenci a hází `ReferenceException`.
- **Chyby dohledání cílů uložení:** pokladna, řada a vlastní účet, které
  se podle reference nenajdou, jsou nálezy pod `validation_failed`
  (všechny tři najednou), ne apply-level kódy `cash_desk_not_found` /
  `own_bank_account_not_found`. `number_series_not_found` zůstává pro
  řadu jiného typu nebo neaktivní, `cash_desk_not_found` pro pokladnu
  bez řady.
- **`enrichment.suggested` nese dál holé hodnoty** (`ourCode`, `account`);
  na řádek jdou reference. Frontend náhledu potřeboval jen číst
  `matchedBy: "ref"` jako napárování naším kódem (`rowMatch.js`).

### Odchylky od zadání

- **Rozdělení commitů.** Schéma se starými poli končí (D14), takže
  `DocumentExporter`, `CanonicalParty` a generátory dokladů (U5, U6) jsou
  v commitu 1 se schématem a pokynem — jejich výstup jinak schématem
  neprojde a unit sada by mezi commity nebyla zelená. Commit 2 obohacení,
  commit 3 sady a generátor sad, commit 4 případy payloadů, commit 5
  dokumentace.
- **Definice reference ve schématu hlídá jen typ** (číslo nebo text),
  ne tvar `@…`: gramatiku drží jediné místo (`ReferenceValue`) a U8 chce
  u `"42"` nález `reference_invalid`, ne `schema_invalid`. Strana
  a položka jsou místo `oneOf` sjednocením typů téže definice
  (`["object", "integer", "string", "null"]`) — stejný význam, čitelnější
  chyby schématu.
- **Jednotka v exportu a generátorech jen systémovým kódem** (D37) —
  dřív export padal zpět na zkratku. Jednotka bez systémového kódu se
  s varováním vynechá.
- **Strana jen referencí v importním módu** dostane snapshot z adresáře
  (`PersonSnapshotBuilder::buildPartner()`); `DocDocument` by jinak
  v importním módu nechal snapshot prázdný.

### Stávající testy

- **Testy pokynu nad harnessem** (`OutOfScopeHarness`): payloady nesou
  papírovou podobu dokladu; pokyn je dostává převedené
  (`ReferenceFixture::asInstruction()` — `ref` u strany a položky, bez
  signálu země registrace). Očekávání beze změny. `ReferenceFixture` staví
  skutečný `ReferenceResolver` nad vyjmenovanými záznamy.
- **Testy obohacení:** tvrzení o výstupu obohacení přešla na `item.ref`
  a účet `"@číslo"`; nový test drží čtení uloženého návrhu starým tvarem.
- **`DocumentImportSeriesStatesTest`** si zakládá vlastního partnera
  a položku bere existující; neposílá papírové součty fixtury. Na volných
  zdrojích test přeskakuje (chce dvě řady přijatých faktur s kódy 1 a 5)
  a od zavedení kontrolního součtu (část B) by neprošel — ověřen na
  demo zdroji s dočasně založenými řadami.
- **`CashDocumentImportTest`:** řádek komentáře s názvem reálného zdroje
  dat anonymizován prefixem ID.

### Ověření (volné zdroje dat)

- Unit sada 12 077 testů; integrační skupina dokladů, sad a pošty nad
  zdrojem se sadou Plátce DPH 516 testů bez selhání; matice round-tripu
  20 / 20, případy payloadů 33 (12 nových).
- Demo sada: seed → dump je v `docs/` bajtově shodný se sadou v repu,
  druhé kolo seed → dump stabilní (liší se jen manifest).
- Srovnání s kódem před taskem (worktree): 305 dokladů po seedu demo
  sady a běhu periodické fakturace (10 nových konceptů) shodných ve všech
  porovnávaných sloupcích hlavičky a řádků; tři opravné doklady (Kč se
  zakázkou, Kč bez ní, EUR s přenesením) shodné; `dataset-verify --json`
  tří veřejných sad shodný.
- Minimální payload z návodu „Doklad skriptem“ přes REST `/preview`
  a `/apply` na demo zdroji: koncept vydané faktury 5 000 + 21 %, náhled
  pro stav V pořádku hlásí chybějící vlastní účet na `payment.ownBankAccount`.

### Otevřené body

- **Výstupní schéma profilu pošty** (`output_schema` v
  `czech_general.jsonc`) nese kopii schématu, takže model na čerstvě
  založeném zdroji uvidí `ref`, `numberSeries` a referenci u účtu (popis
  definice říká, že doklad z papíru referenci nenese). Verze promptu se
  nemění, existující zdroje dat si proto drží uložené starší schéma.
  Dopad na extrakci ověří evaluace (pouští člověk).
- Import ze starého Shipardu (etapa 6) musí posílat reference a osoby
  i položky nahrávat předem — viz „Dopad na import“.
- Navazuje `tasks/doc-format-partner-fields.md` (partner účetního
  a pokladního dokladu jako pole, konec `_resolve` v pokynu).
