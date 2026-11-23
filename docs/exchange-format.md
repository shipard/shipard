# Shipard — Výměnný formát dokumentů

## 1. Účel a kontext

**Výměnný formát** (exchange format) je kanonická JSON reprezentace doménové
entity (doklad, osoba, položka, …) navržená pro **přenos mezi systémy**.
Stojí jako střední vrstva mezi:

- **externí reprezentací** dokumentu (PDF zpracované AI, ISDOC, XML z účetního
  systému, výstup z partnerského API), kterou produkují různé zdroje v různých
  formátech,
- **vnitřní reprezentací** v Shipard DB (`docs_core_heads` row s FK na
  `base_persons_persons.id`, `economy_items.id`, …), která je optimalizovaná
  pro běh aplikace a referenční integritu.

Hlavní rozlišovací znak: **externí reprezentace nepoužívá interní ID**.
Místo `partner: 42` má `partner: { country: "cz", companyId: "12345678" }`.
Tím je formát samonosný — lze ho přenášet mezi DS, mezi firmami, do/z
e-fakturace, exportovat a archivovat. Mapování na lokální entity (proces
zvaný **resolve**) probíhá až při importu.

### K čemu nám to slouží

Mít jeden dobře navržený kanonický formát umožňuje stavět na něm několik věcí
najednou:

1. **Vizualizace** výsledku AI extrakce — uživatel vidí lidsky čitelný náhled
   dokladu (před uložením) se zvýrazněním nerozhodnutých referencí.
2. **Strojová validace** — kontrola chybějících údajů, nesouhlasných součtů,
   neexistujících referencí.
3. **Ukládání do DB** — applier transformuje canonical na interní `$data`
   pole a prožene přes existující `TableGateway::saveDocument()`. Veškerá
   business logika (`DocDocument::beforeSave`: snapshoty, recap, totals,
   přidělení čísla) zůstává ve stávajícím aparátu.
4. **API pro celé doklady** — `/api/v1/_exchange/docs/document/apply` jako
   atomický endpoint pro pořízení dokladu jedním requestem. Umožňuje Shipard
   používat jako automatizovaný fakturační systém.
5. **Importy z účetních / ERP systémů** — adaptér přečte jejich formát,
   transformuje na canonical, předá applieru. Stejná cesta pro Pohodu, Money,
   Flexibee, atd. — odlišuje se jen vstupní adaptér.
6. **Doklady, které Shipard generuje sám** — periodická fakturace zakázek
   (#110 Q1), později faktura ze zakázky, faktura ze zálohy apod. Vznikají
   **i se přegenerovávají výhradně přes výměnný formát a `DocumentApplier`**
   (`applyOptions.replaceConcept`, Q2) — generátor skládá kanonický payload
   z interních dat, žádné přímé skládání hlavičky a řádků mimo applier;
   odvození DPH, řada, zaokrouhlení i dimenze tak mají jedinou implementaci.
   Známé výjimky z doby před touto zásadou (#112): zaúčtování podaného
   přiznání DPH (`VatReturnAccountingService`) a účetní doklady majetku
   (`AssetPostingDocuments`) jdou přes `TableGateway`; sjednotí je
   samostatný task.
6. **Mezikrok pro elektronickou fakturaci** — ISDOC, Peppol UBL, e-Faktura
   se rozparsují na canonical, ten se uloží. Stejný flow pro AI extrakci
   i strukturovaná data. Příchozí ISDOC je implementován — viz `IsdocReader`
   v sekci Adaptéry (kapitola 4).
7. **Export pro elektronickou výměnu** — opačný směr: canonical dokladu
   (papírová projekce + `rows[].computed`) se serializuje do ISDOC
   (`IsdocWriter`, #119 fáze 1, `docs/einvoice.md`); ve fázi 2 ho tisk
   vloží do PDF faktury vydané a přiloží k e-mailu partnerovi, který má
   taky Shipard. Spolehlivější než AI extrakce z PDF na obou stranách.
8. **Datové sady** — přenosný obraz celého DS (`shpd-ds dataset-dump` /
   `dataset-seed`): exportery vyrobí canonical z DB, seed ho vrátí přes
   appliery. Demo sady, testovací fixtury, školicí DS. Viz
   `docs/datasets.md` (#40).

### Generalizace

Stejný pattern je vhodný i pro další entity. Tento dokument popisuje
**`shpd.docs.document.v1`** (doklady) jako první konkrétní formát. Další
plánované formáty (samostatné dokumenty / iterace) popisuje sekce 13.

## 2. Pojmosloví

| Termín | Význam |
|--------|--------|
| **Canonical / exchange format** | Kanonická JSON reprezentace doménové entity. Samonosná, bez interních ID. |
| **Schema** | Definice struktury konkrétního formátu (`shpd.docs.document.v1`). JSON Schema draft-2020-12 + PHP `DocumentValidator` pro logiku, kterou schema neumí (např. polymorfismus podle `docType`). |
| **Resolve** | Proces propojení referencí v canonical (Party, Item, Unit, VAT code, BankAccount) s entitami v lokální DB. |
| **Apply** | Proces uložení canonical dokumentu do DB — orchestruje resolve, transformuje na interní `$data`, deleguje na `TableGateway::saveDocument()`. |
| **Lineage** | Stopy, odkud doklad vznikl — `source.kind` + `source_message` v `docs_core_heads`, zpětně `target_*` na zdrojové zprávě. |

## 3. Architektura — tři vrstvy

```
┌─────────────────────────────────────────────────────────────┐
│  REST API     /api/v1/_exchange/docs/document/{validate,    │
│                                                preview,     │
│                                                apply}       │
├─────────────────────────────────────────────────────────────┤
│  Applier      DocumentApplier                               │
│    - orchestruje validate → resolve → transform → save     │
├─────────────────────────────────────────────────────────────┤
│  Resolvers    PartyResolver, ItemResolver, UnitResolver,    │
│               VatCodeResolver, BankAccountResolver          │
│    - per-typ reference mapuje canonical → DB id            │
├─────────────────────────────────────────────────────────────┤
│  Schema       ExchangeFormat (PHP) + .json schema soubor    │
│    - definice struktury, statická validace                  │
├─────────────────────────────────────────────────────────────┤
│  Existing infra (nedotčeno)                                 │
│  Document, TableGateway, DocDocument::beforeSave,           │
│  VatRateResolver, ConfigRuntime, …                          │
└─────────────────────────────────────────────────────────────┘
```

**Klíčový design point:** Applier **nesestupuje** pod úroveň Document.
Veškerá business logika (přidělení čísla, snapshoty, recap, totals, rounding,
state transitions) zůstává v `DocDocument`. Exchange formát je pouze "lepší
vstup" — transformační vrstva nad existujícím dokumentovým systémem.

### Dva režimy volání: pokyn × návrh (#166 D18–D20)

`DocumentApplier::validate()`, `preview()` a `apply()` berou **režim**
(`ApplyMode`). Režim je parametr volání, ne obsah payloadu — schéma je
jedno a totéž.

- **Pokyn** (`ApplyMode::Instruction`, výchozí) — volající ví, co chce
  uložit. Applier dohledá reference, doplní výchozí hodnoty, nechá
  `DocDocument` validovat a počítat — a **nic neodvozuje ze signálů**. Co
  uloží formulář, uloží i pokyn („Invariant ukládání“, § 10).
- **Návrh** (`ApplyMode::Proposal`) — interpretace papírové podoby dokladu:
  odvozování z § 8.4, volby uživatele v `_resolve`, heuristická varování.

| Volající | Režim |
|---|---|
| REST `/_exchange/docs/document/*` (API klient, import ze starého Shipardu) | pokyn; návrh na vyžádání `applyOptions.interpret: true` (§ 11) |
| datové sady — sekce `docs/` (`DocumentSeeder`) | pokyn |
| generátory dokladů — periodická fakturace, opravný doklad | pokyn |
| pošta — náhled návrhu (`AnalysisController`) a jeho použití (`MessageProposalApplier`), tedy i ISDOC z přílohy a `dataset-verify` | návrh (určuje kód; `interpret` z payloadu se nečte) |

**V pokynu neběží** (D19): místo plnění z DIČ; odvození ani náhrada kódu
DPH; doklad neplátce a doklad mimo české DPH (ceny řádků s daní dodavatele,
zahozená rekapitulace); korekce režimu DPH ze součtů a kvůli samovyměření;
druh plnění ze štítků; odvození `recapSource` a dohledání kódu rekapitulace
z řádků; odhad módu zaokrouhlení (mód říká payload); vynechání řádků; heuristická varování
validátoru (`totals_mismatch`, `rows_recap_mismatch`, `vat_mode_suspect`,
`vat_recap_inconsistent`); oprava IBAN ↔ číslo účtu (normalizace tvaru
hodnot zůstává); učení dodavatelských kódů. Blok `_resolve` pokyn nečte
vůbec (#166 D32): payload, který ho nese, projde s jedním info nálezem
`resolve_ignored` — volby DPH, položek ani partnerů se neuplatní.

**Hodnoty DPH jsou v pokynu záměr** (D20):

| Pole | Hodnota v pokynu |
|---|---|
| `vat.place` | z payloadu; chybí = tuzemsko |
| `vat.mode` | z payloadu; chybí = `fromBase`. Doklad s `none` kódy řádků nenese (jako ve formuláři) a neověřují se |
| `rows[].vat.code` | z payloadu, ověřený proti číselníku země registrace k datu sazby řádku; neznámý = chyba `vat_code_unknown`. Řádek bez kódu (i s objektem `vat` bez `code`) je „nevyplněno“ a rozhodne `DocDocument` (`vat_code_missing`, `docs/vat-calculation.md` § 1.1) |
| `rows[].vat.pct` | nepoužije se — procento je vždy z kódu a data sazby |
| registrace DPH | reference `vat.registration` (`"@cz"`, #166 D36); bez ní výchozí registrace zdroje dat jako ve formuláři (první podle země a id), jen u dokladu s DPH. Signál `vat.registrationCountry` pokyn odmítá (`signal_not_allowed`). Mimo importní mód musí platit k datu dokladu |
| `vat.recapSource` | z payloadu; chybí = `computed`. `declared` vyžaduje `vatRecap` s kódem DPH na každém řádku — chybějící rekapitulace (`vat_recap_missing`), chybějící kód (`vat_recap_code_missing`) i neznámý kód (`vat_code_unknown`) je chyba, ne tichý přepočet z řádků |
| `totals.totalRoundingAuto` | z payloadu; chybí = zapnuto tam, kde automatika platí (prodejní typy, #73), jinak vypnuto. Na přítomnosti `totals.totalAmount` nezáleží (D26) |
| `totals.totalRoundingMode` | při vypnuté automatice z payloadu, chybí = `cent`; mód se neodhaduje. Při zapnuté automatice ho určí způsob úhrady — liší-li se uvedený, varování `total_rounding_mode_ignored` (§ 5) |
| `vat.roundingMode` | z payloadu; chybí = `cent` |
| `totals` — částky | **kontrolní součet** (D4, D27): každé uvedené pole se porovná s výpočtem dokladu, rozdíl nad 0,01 = `totals_check_failed` a doklad se neuloží (§ 5) |

Importní mód (`applyOptions.importNumber`) je vždy pokyn a nese jen
historii — § 10 „Importní mód“.

## 4. Životní cyklus

```
Vstup (PDF, ISDOC, ruční zadání, ...)
  │
  ▼
[Adaptér / AI analýza pošty / parser]
  │
  ▼
Canonical JSON  ──────►  /validate     → vrátí jen issues (no DB writes)
  │                       /preview     → vrátí canonical + _resolve (no DB writes)
  │                       /apply       → resolve → save → vrátí enriched canonical
  ▼
DB záznam v docs_core_heads + rows + vatRecap + případně nové persons/items
```

`validate` a `preview` jsou idempotentní a bez vedlejších efektů. `apply`
volitelně může vytvořit nové entity (osoby, položky) dle uživatelského pokynu
v `_resolve.*.userAction`. Všechny tři vstupy běží v jednom ze dvou režimů
(§ 3): canonical z adaptéru nebo AI analýzy jde **návrhem**, hotový doklad
z importu, datové sady nebo generátoru **pokynem**.

### Adaptéry

První implementovaný vstupní adaptér je **ISDOC** (český standard
e-fakturace): `Shipard\Module\Core\Exchange\Isdoc\IsdocReader` konvertuje
ISDOC 6.x XML (i `.isdocx` ZIP obal) na canonical se `source.kind='isdoc'`
a confidence 1.0. Mapuje se jen to, co v ISDOC opravdu je (chybějící pole
se vynechávají); podporované `DocumentType`: 1 → `invoiceReceived`,
2 → `creditNote`. Řádky nesou i `computed` (základ, daň a cenu s daní, jak
je spočítal dodavatel) — cenu s daní z nich bere applier u přijatého
dokladu neplátce DPH (§ 8.4). Čtečka čte i tuzemské přenesení daňové
povinnosti (`vat.reverseCharge`, `rows[].vat.reverseChargeCode`), režim
DPH (`vat.mode`, doklad bez DPH jako doklad neplátce), slevu, poznámku
a způsob úhrady (#119 krok 3). Kompletní mapovací tabulka v obou směrech:
[docs/einvoice.md](einvoice.md) § 4–5. Použití
v příjmu pošty (deterministický import místo AI analýzy):
`modules/core/mail/docs/ai-analysis.md`, sekce „Deterministický ISDOC
import".

Výstupní adaptér je **`IsdocWriter`** (#119 fáze 1): canonical pro
e-fakturu (papírová projekce + `rows[].computed`) → ISDOC 6.0.1, čistá
funkce bez databáze, nad rozhraním formátu `EInvoice\EInvoiceWriter`
(registr `EInvoiceFormats`). Chybějící povinná hodnota je
`EInvoiceInputException` s cestami, ne prázdný element; výstup musí projít
XSD 6.0.1 i 6.0.2. Round-trip generátor → čtečka hlídá
`IsdocCorpusRoundTripTest` nad korpusem. Vše v [docs/einvoice.md](einvoice.md).
Ostatní adaptéry (Peppol UBL, Pohoda, Flexibee, …) zůstávají future work.

## 5. Specifikace `shpd.docs.document.v1`

Top-level struktura:

```jsonc
{
  // ── Format meta ──────────────────────────────────────────────────────────
  "format": "shpd.docs.document",
  "formatVersion": "1.0",

  // ── Source (audit / lineage) ─────────────────────────────────────────────
  "source": {
    "kind": "aiExtraction",      // aiExtraction | isdoc | xml.peppol | manual
                                  //   | import.flexibee | import.pohoda | …
    "extractedAt": "2026-05-14T10:30:00Z",
    "confidence": 0.92,           // jen pro aiExtraction (overall_confidence)
    "message": 12345,             // int|null — FK na core_mail_incoming_messages.
                                  //   Injektuje ho SERVER při apply návrhu
                                  //   z pošty (nikdy se nevěří klientovi);
                                  //   applier ho propíše do
                                  //   docs_core_heads.source_message
    "promptVersion": "v1.1.0",    // pro AI lineage
    "raw": { /* opaque source-specific payload, optional */ }
  },

  // ── Document identity ────────────────────────────────────────────────────
  "docType": "invoiceReceived",  // key z docs.core.docTypes
  "docNumber": "2026000123",     // číslo dokladu vystavované strany (na
                                  //   přijaté faktuře = supplier's invoice #)
                                  //   Naše interní číslo přiděluje series
                                  //   až při Confirm — toto pole se ukládá
                                  //   do partner_doc_number (viz Apply).
  "docText": "Konzultace 04/2026",
  "selfParty": "customer",       // "supplier" | "customer" | null
                                  //   která strana jsme my
  "fiscalPeriodType": null,      // "opening" | "closing" | null — doklad
                                  //   otevíracího / uzávěrkového období
                                  //   (docs_core_heads.fiscal_period_type,
                                  //   #69 D20): zařadí se do jednodenního
                                  //   měsíce Otevření / Uzavření roku podle
                                  //   účetního data, ne do běžného měsíce;
                                  //   saldokonto uzávěrkové řádky nebere.
                                  //   Jen import mód (applyOptions
                                  //   .importNumber) — jinak applier pole
                                  //   ignoruje. Exportér ho vypisuje vždy.

  // ── Řada a pokladna — reference (viz „Reference na záznamy“ níže) ────────
  "numberSeries": "@invni",      // číselná řada dokladu; chybí = výchozí
                                  //   řada typu, u pokladních typů ji určuje
                                  //   pokladna
  "cashDesk": null,              // pokladna ("@POK") — povinná u pokladního
                                  //   dokladu a prodejky (§ „Polymorfismus“)

  // ── Parties ──────────────────────────────────────────────────────────────
  "supplier":  { /* Party — viz sekce 6 */ },   // reference na osobu, nebo
  "customer":  { /* Party — viz sekce 6 */ },   //   objekt s `ref` a daty
  "balanceParty": null,            // Party — ruční plátce (osoba pro
                                  //   saldokonto, #72); null = odvodí se
  "partner": null,                 // Party — partner hlavičky účetního
                                  //   dokladu a dokladu vázaného na pokladnu
                                  //   (#166 D30); u faktur je partnerem
                                  //   strana a pole je chyba

  // ── Dates ────────────────────────────────────────────────────────────────
  "dates": {
    "issueDate":         "2026-04-15",
    "dueDate":           "2026-04-29",
    "accountingDate":    "2026-04-15",
    "taxPointDate":      "2026-04-15",  // DUZP
    "vatObligationDate": "2026-04-15",  // DPPD
    "vatRateDate":       null,          // vlastní datum sazby DPH dokladu —
                                        //   jen při vat.rateSource "document"
                                        //   (#159, viz „Datum sazby DPH“ níže)
    "periodFrom":        null,
    "periodTo":          null
  },

  // ── Currency & VAT ───────────────────────────────────────────────────────
  "currency":     "CZK",          // ISO 4217 uppercase v canonical;
                                   //   applier lowercases pro cfgItem
  "exchangeRate": null,           // kurz za jednotku; null u cizí měny → applier
                                   //   ho mimo import mód doplní z kurzovního
                                   //   lístku k účetnímu datu: accountingDate, jinak
                                   //   u invoiceReceived taxPointDate, jinak issueDate
                                   //   (AccountingDateRule, #103 D78),
                                   //   kurz na dokladu vždy vyhrává; nenalezeno =
                                   //   warning exchange_rate_missing a V pořádku
                                   //   zastaví validace (#121 D6, U5,
                                   //   docs/exchange-rates.md §5.2)

  "vat": {                        // celý objekt nullable — nelze-li určit,
                                   //   vynechat nebo null (ne prázdný objekt)
    "mode":  "fromBase",          // fromBase | fromTotal | none | null
                                   //   (key z docs.core.vatModes; enum ve
                                   //   schématu — neznámá hodnota mimo schema
                                   //   validaci → warning `vat_mode_unknown`).
                                   //   `none` + řádky se samovyměřením →
                                   //   applier vynutí fromBase (warning
                                   //   `vat_mode_derived`).
                                   //   Applier mode deterministicky ověřuje
                                   //   proti číslům (VatModeDerivation): sedí-li
                                   //   Σ rows[].totalPrice právě na Σ vatRecap
                                   //   total (fallback totals.totalAmount −
                                   //   totalRounding), a ne na base, jsou řádky
                                   //   v cenách s DPH → vat_mode dokladu se
                                   //   nastaví na 2 (fromTotal) bez ohledu na
                                   //   deklarovaný mode; zrcadlově pro opačný
                                   //   směr. AI extraktory mode u koncových cen
                                   //   (účtenky, PHM) vracejí špatně a daň by se
                                   //   počítala dvakrát. Canonical zůstává
                                   //   nedotčený, korekce je v _resolve.issues
                                   //   jako warning `vat_mode_derived`.
                                   //   U přijatého dokladu neplátce DPH (žádná
                                   //   registrace platná k datu dokladu) mode
                                   //   přebíjí applier na `none` a říká jen,
                                   //   v jakých cenách jsou řádky — § 8.4
                                   //   „Přijatý doklad neplátce DPH“.
    "place": "domestic",          // domestic | intracom | thirdCountry | null
                                   //   Canonical názvy — číselník world.vat má
                                   //   pro thirdCountry `foreign`. Enum ve
                                   //   schématu; neznámá hodnota mimo schema
                                   //   validaci → warning `vat_place_unknown`.
                                   //   U přijatého dokladu (selfParty customer,
                                   //   naše registrace DPH) hodnotu PŘEBÍJÍ
                                   //   prefix DIČ dodavatele (VatPlaceDerivation,
                                   //   § 8.4): IE… → intracom i u sídla v USA;
                                   //   rozpor s neprázdnou hodnotou → warning
                                   //   `vat_place_derived`. Bez DIČ (nebo
                                   //   s prefixem, který unie nezná) platí
                                   //   hodnota odtud.
    "registration": "@cz",        // naše registrace DPH — reference (#166
                                   //   D36), nepovinná: bez ní pokyn doplní
                                   //   výchozí registraci zdroje dat, dokladu
                                   //   Bez DPH žádnou. Mimo importní mód musí
                                   //   k datu dokladu platit, jinak chyba
                                   //   `vat_registration_not_valid`.
    "registrationCountry": null,  // DOČASNÉ (D39) — signál jen pro návrh:
                                   //   ISO země naší registrace, jak ji
                                   //   přečetla AI. U přijatých dokladů
                                   //   (selfParty customer) ji návrh IGNORUJE
                                   //   a bere první aktivní registraci zdroje
                                   //   (D2, info `vat_registration_country_derived`
                                   //   při rozporu); u ostatních podle ní
                                   //   dohledá registraci. Pokyn pole odmítá
                                   //   (`signal_not_allowed`).
    "reverseCharge": false,       // bool | null — daň přiznává příjemce
                                   //   („reverse charge“, přenesení daňové
                                   //   povinnosti, čl. 196 směrnice, § 92a);
                                   //   signál pro odvození kódu DPH řádků
                                   //   (§ 8.4), i když je na dokladu DPH 0.
    "recapSource": "declared",    // computed | declared | null
                                   //   Autorita rekapitulace (viz níže).
                                   //   declared = vatRecap je fakt z dokladu
                                   //   a DocDocument ho nepřepočítá.
                                   //   null → applier odvodí.
    "calcSource": "header",       // header | rows | null
                                   //   Metoda výpočtu PŘEPOČÍTANÉ
                                   //   rekapitulace (docs.core.vatCalcSources):
                                   //   header = daň jednou ze součtu cen
                                   //   ve sazbě (norma), rows = součet
                                   //   řádkových daní. null → header.
    "controlStatementMode": "auto" // auto | detail | aggregate | exclude | null
                                   //   Ruční zařazení do kontrolního hlášení
                                   //   (docs_core_heads.cs_mode, cfgItem
                                   //   economy.vat.controlStatementModes, #77):
                                   //   auto = podle limitu 10 000 Kč, detail =
                                   //   vždy A4/B2, aggregate = vždy A5/B3,
                                   //   exclude = mimo hlášení (v přiznání
                                   //   zůstává). 1:1 se starým vatCS 0–3;
                                   //   null → auto. Applier auto do payloadu
                                   //   nedává (default sloupce), exporter
                                   //   hodnotu vypisuje vždy (round-trip).
    "rateSource": "auto"          // auto | document | rows | null
                                   //   Přepínač „Sazby DPH podle“
                                   //   (docs_core_heads.vat_rate_source, cfgItem
                                   //   docs.core.vatRateSources, #159 D11):
                                   //   k jakému datu se hledá sazba kódů DPH.
                                   //   auto = DPPD → DUZP → datum vystavení
                                   //   (opravný doklad: původní plnění),
                                   //   document = dates.vatRateDate,
                                   //   rows = rows[].vat.rateDate
                                   //   a vatRecap[].rateDate. null → auto.
                                   //   Exporter vypisuje jen document / rows.
                                   //   Viz „Datum sazby DPH“ níže.
  },

  // ── Payment ──────────────────────────────────────────────────────────────
  "payment": {
    "method":          "bankTransfer",  // cash | bankTransfer | card | cashOnDelivery
                                        //   | setOff | paymentGateway (docs.core.paymentMethods)
    "ownBankAccount":  "@BU1",          // náš bankovní účet dokladu — reference
                                        //   (#166 D30); vydaná faktura ho ve
                                        //   stavu V pořádku vyžaduje
    "paymentReference": "2026000123",
    "specificSymbol":  null,
    "constantSymbol":  null
  },

  // ── Notes ────────────────────────────────────────────────────────────────
  "notes": {
    "internal":   null,           // → docs_core_heads.notice
    "onDocument": "Děkujeme."     // → docs_core_heads.doc_notice
  },

  // ── Dimenze deníku (#110 D20, T2) ────────────────────────────────────────
  "dimensions": {                 // klíč = id dimenze (journalDimensions
    "costCenter": "@S01"          //   v economy.accounting), hodnota =
  },                              //   reference na záznam cílové tabulky
                                  //   (#166 D30; přirozený klíč = `exchangeKey`
                                  //   tabulky: středisko kód, zakázka číslo,
                                  //   majetek inventární číslo).
                                  //   Hlavička = výchozí hodnota pro
                                  //   řádky bez vlastní (→ docs_core_heads
                                  //   .cost_center). Neznámé id / dimenze,
                                  //   jejíž tabulka klíč nemá → dimension_unknown,
                                  //   hodnota bez záznamu → reference_not_found
                                  //   (obě blokují apply). Chybějící objekt
                                  //   = sloupce zůstanou prázdné.

  // ── Opravný daňový doklad (#92 D10, task B) ──────────────────────────────
  "correction": {                 // objekt = opravný doklad (docs_core_heads
    "originalDoc":          "@FV26-00123",  //   .corrective = 1), null / chybí
    "originalDocNumber":    "FV26-00123",   //   = běžný doklad. Jen u typu
    "originalTaxPointDate": "2026-03-15",   //   s příznakem docTypes[]
    "reason":               "Sleva za vadu" //   .corrective (faktura vydaná);
  },                              //   jinde warning correction_not_supported.
                                  //   Původní doklad = reference `originalDoc`
                                  //   (#166 D30); `originalDocNumber` je jen
                                  //   text, nic se podle něj nedohledává
                                  //   (§ 5 „Opravný daňový doklad“). Objekt
                                  //   se samými null = jen příznak (import ze
                                  //   starého Shipardu).

  // ── Rows ─────────────────────────────────────────────────────────────────
  "rows": [
    { /* DocumentRow — viz sekce 7 */ }
  ],

  // ── Computed (applier recomputes; `totals` v pokynu = kontrolní součet) ──
  "vatRecap": [
    {
      "vatCode": "cz-110", "vatPct": 21,
      "base": 10330.58, "tax": 2169.42, "total": 12500.00,
      "isReversePair": false
    }
  ],
  "totals": {
    "totalBase":     10330.58,
    "totalVat":      2169.42,
    "totalAmount":   12500.00,
    "totalRounding": 0.00,
    "totalRoundingAuto": false,    // nepovinné; automatika podle způsobu úhrady
    "totalRoundingMode": null      // nepovinné; cent | unit | unitUp | unitDown | fiveCent
  },

  // ── Attachments ──────────────────────────────────────────────────────────
  "attachments": [
    {
      "filename":  "faktura-001.pdf",
      "mimeType":  "application/pdf",
      "size":      123456,
      "sha256":    "…",           // optional, pro dedup
      "kind":      "original",    // original | scan | supplement | preview
                                   //   | structured (strojově čitelný formát
                                   //   — ISDOC, UBL, XML export)
      "ref":       "att:42",      // existující core_attachments_files.id, NEBO
      "inline":    null           // "data:application/pdf;base64,…"
                                   //   (mutually exclusive s ref)
    }
  ],

  // ── Resolve state (populated by /preview, used by /apply) ────────────────
  "_resolve": { /* viz sekce 9 */ }
}
```

### Reference na záznamy — jedno pole na odkaz (#166 D30–D39)

Každý odkaz na záznam je **jedno pole s referencí** podle gramatiky z § 8.6:
`42` (id záznamu), `"@klíč"` (přirozený klíč cílové tabulky) nebo
`"@sloupec:hodnota"` (jmenovaný sloupec, který tabulka pro hledání
deklaruje). Hledá jediný `ReferenceResolver`: přesně jeden živý záznam,
nenalezeno i nejednoznačné je chyba — nikdy první nalezený.

| pole | cílová tabulka | přirozený klíč | příklad |
|---|---|---|---|
| `supplier`, `customer`, `balanceParty` — reference, nebo objekt s `ref` (§ 6) | osoby | číslo osoby; jmenovitě i IČO a DIČ (D35) | `"@F00042"`, `"@company_id:12345678"`, `"@vat_id:CZ12345678"` |
| `rows[].item` — reference, nebo objekt s `ref` (§ 7) | položky | kód; jmenovitě i EAN a SKU | `"@KONZ"`, `"@ean:8590000000017"` |
| `numberSeries` | řady dokladů | kód řady (D17) | `"@invno"` |
| `payment.ownBankAccount` | vlastní bankovní účty | kód | `"@BU1"` |
| `cashDesk` | pokladny | kód | `"@POK"` |
| `dimensions.<id>`, `rows[].dimensions.<id>` | cílová tabulka dimenze | podle tabulky | `"@S01"` |
| `rows[].account` | účty osnovy | číslo účtu | `"@518100"` |
| `vat.registration` | registrace DPH | kód registrace (D17) | `"@cz"` |
| `correction.originalDoc` | doklady | číslo dokladu | `"@FV26-00123"` |
| `partner` — hlavička účetního dokladu a dokladu vázaného na pokladnu; `rows[].partner` — reference, nebo objekt s `ref` | osoby | jako strana | `"@F00042"`, `42` |

- **Pokyn** hledá jen přes referenci a **nic nezakládá** (D31) — osoby,
  položky ani účty partnerů. Reference, která se nenajde, je nález
  `severity: error` s kódem resolveru (`reference_not_found`,
  `reference_ambiguous`, `reference_invalid`, …) a cestou pole; odpověď
  422 `validation_failed`, nic se neuloží. Strana nebo položka jako objekt
  **bez `ref`** s identifikujícími údaji (název, IČO, kód) je chyba
  `reference_missing` — ne tiché „bez partnera“ / „bez položky“.
- **Návrh** (pošta, ISDOC) hledá strany a položky podle údajů jako dřív
  (§ 8); uvedený `ref` má přednost a hledání se přeskočí (nenalezený `ref`
  je chyba i tady). Ostatní místa z tabulky bere návrh stejně jako pokyn.
- **Jednotka** (`rows[].unit`) referencí není — je to holý systémový kód
  jednotky (D37). Pokyn bere jen přesnou shodu, neznámá jednotka je chyba
  `unit_not_found`; zkratky a aliasy zná jen návrh (§ 8.3).
- **Autor** zůstává id v `applyOptions.author` (D38). Strana vlastní firmy
  (`selfParty`) referenci nenese. `supplier.bankAccount` jsou data: jdou do
  sloupců hlavičky, vazba na účet partnera vznikne v pokynu jen přesnou
  shodou s jeho existujícím účtem.
- **Partner účetního a pokladního dokladu** (`partner`, `rows[].partner`,
  `tasks/doc-format-partner-fields.md`) má tvar strany, ale hledá se **jen
  přes `ref`**, v obou režimech — pole je nové, žádný uložený návrh ho
  nenese. Objekt s názvem / IČO / DIČ bez `ref` je v pokynu chyba
  `reference_missing`, v návrhu varování a pole se nepoužije.
  - `partner` na hlavičce nese jen `accountingDocument`, `cashDocument`
    a `cashRegisterDocument`. U faktur je partnerem strana: pole je
    v pokynu chyba `partner_not_supported`, návrh ho s varováním ignoruje.
  - U pokladního dokladu a prodejky nese partnera hlavičky i strana podle
    směru (tak ho píše export). Pole `partner` má přednost; určí-li pole
    a strana **různé osoby**, pokyn doklad odmítne (`partner_conflict`).
    V importním módu bere dobový snapshot data z pole.
  - Účetní doklad snapshot partnera nemá (nemá směr obchodu) — data vedle
    `ref` se u něj nepoužijí.
- `_resolve` pokyn **nečte vůbec** (D32): blok se zahodí před zpracováním
  a payload dostane jeden info nález `resolve_ignored` (path `_resolve`).
  V návrhu volby `_resolve.partner` a `_resolve.rows[].partner` fungují
  dál; uvedené pole má přednost.
- Exportér (`DocumentExporter`, datové sady) píše každý odkaz přirozeným
  klíčem; generátory dokladů (periodická fakturace, opravný doklad) id.
  Číslo dokladu nemá unikátní index a čísla různých typů se mohou shodovat —
  `correction.originalDoc` klíčem pak může být nejednoznačný (chyba).

**Dočasné signály návrhu (D39).** Uložené návrhy z pošty nesou tři pole
starým tvarem: `rows[].item.ourCode`, účet řádku holým číslem
(`"518100"`) a `vat.registrationCountry`. Ve schématu zůstávají jako
**signály platné jen v návrhu**; pokyn je odmítá chybou `signal_not_allowed`
s radou, které pole použít (tiché ignorování by uložilo jiný doklad, než
jaký odesílatel zamýšlel). Je to přechodný stav — po převodu uložených
návrhů pole zmizí. Obohacení řádků z historie už píše reference (`item.ref`,
účet `"@číslo"`), starý tvar čte dál.

Tvar referencí formátu drží `Document\CanonicalReferences` (strana a položka
jen referencí se uvnitř applieru rozbalí na objekt s `ref`; odpověď vrací
rozbalený tvar).

### Pole `vatRecap` a `totals` — vstup vs. autorita

**`vatRecap` je autorita, když doklad říká `vat.recapSource: "declared"`.**
Applier ho pak uloží tak, jak přišel, a `DocDocument::beforeSave()` ho
nepřepočítá — dopočte z něj jen domácí měnu, součty hlavičky a flagy
sčítání z definice kódu (`docs/vat-calculation.md` § 5). To je cesta pro
import ze starého Shipardu a pro doklad dodavatele: nárok na odpočet je
částka **z faktury**, i když je haléřově „špatně".

U `"computed"` (a u vystavených dokladů vždy) je `vatRecap` **informativní**
a v DB je autoritativní hodnota vypočtená z řádků.

**`vatRecap[].reverseChargeCode`** (nepovinné, #89 D10) — předmět plnění
skupiny u tuzemského přenesení daňové povinnosti; klíč skupiny rekapitulace
je (kód DPH, sazba, předmět). U převzaté rekapitulace ho řádek s kódem
přenesení **musí** nést (jinak error `reverse_charge_code_missing` /
`reverse_charge_code_unknown`, path `vatRecap.N.reverseChargeCode`),
oddaňovací pár nese předmět své skupiny, u jiného kódu se ignoruje.
Exporter ho vyváží; AI extrakce ho nevyplňuje (rekapitulace dodavatele se
u přenesení nepřebírá).

**`vatRecap[].rateDate`** (nepovinné, #159 D11) — vlastní datum sazby DPH
řádku rekapitulace při `vat.rateSource: "rows"`; prázdné = automatické datum
dokladu. Kód a předmět plnění řádku převzaté rekapitulace applier ověřuje
k tomuto datu, takže doklad smí nést dvě skupiny téhož kódu s různou sazbou
(vyúčtování záloh zdaněných starou sazbou). Procento skupiny na dokladu je
vždy sazba z číselníku k datu sazby (`docs/vat-calculation.md` § 5.2);
`vatPct` ze vstupu slouží dohledání kódu a kontrole konzistence.

**Odvození, když `recapSource` chybí (`null`):** u dokladu, který přijímáme
(`selfParty: "customer"` — typicky AI extrakce faktury dodavatele), je
`declared` tehdy, když je rekapitulace neprázdná, každý řádek projde
aritmetickou kontrolou níže a u každého jde dohledat DPH kód. Jinak
`computed` + info issue **`recap_source_computed_fallback`** s důvodem —
bez něj by uživatel nepoznal, proč je na dokladu jiná rekapitulace než na
předloze. U vystavených dokladů je odvození vždy `computed`.

**Samovyměření (D3):** má-li kterýkoli položkový řádek — po odvození kódu
(§ 8.4) — kód s `reverseVatCode`, je rekapitulace přijatého dokladu vždy
`computed` + info issue, které místo šablony „nešlo převzít“ vysvětlí
situaci: dodavatel uvádí 0 % DPH, daň přiznává příjemce, rekapitulace se
sestaví z řádků (daň na výstupu a nárok na odpočet).
Rekapitulace dodavatele je z jeho pohledu (0 %, daň 0); naše nese nárok na
odpočet a oddaňovací pár. Explicitní `recapSource: "declared"` (import ze
starého Shipardu, s páry) zůstává beze změny.

**Vynechaný řádek (#111 D12):** když uživatel v náhledu řádek vynechá
(`_resolve.rows[i].item.userAction: "skip"`, nebo řádková `userAction`),
je rekapitulace vždy `computed` + info issue s důvodem „vynechaný řádek“
— i při explicitním `recapSource: "declared"`. Rekapitulace dodavatele je
za všechny řádky, doklad jich má míň; převzetím by součty dokladu zůstaly
za celou fakturu a náhled by vynechání neukázal. Platí pro `/preview`
i `/apply` stejně.

**DPH kód rekapitulace:** ISDOC ho v rekapitulaci nenese (`TaxSubTotal` má
jen sazbu a částky) a AI od promptu v4.6.0 také ne, takže ho applier
dohledá z položkových řádků — mapa sazba → kód (kód, který na řádku
skončí: odvozený má přednost před canonicalem), použije se jen pro sazbu
s jediným kódem. Bez kódu se rekapitulace převzít nedá (`vat_code` je NOT
NULL a bez kódu nejdou určit flagy sčítání) → `computed` + info issue.

**Neplátce DPH (#97 D8):** přijatý doklad na zdroji, který k datu dokladu
není plátcem, rekapitulaci na doklad nedostane nikdy — ani při explicitním
`declared`. Daň dodavatele je součástí cen řádků (§ 8.4), `vatRecap`
slouží jen k dorovnání řádků a zůstává v canonicalu analýzy. Import
(`applyOptions.importNumber`) se větve neplátce netýká.

**`totals` nejsou autorita nikdy** — součty hlavičky vždy počítá
`DocDocument`. Co s nimi applier udělá, říká režim (§ 3; #166 D4, D27, D28).

**V pokynu jsou `totals` kontrolní součet.** Payload je nést nemusí; když
je nese, každé uvedené číselné pole se porovná se sloupcem hlavičky, který
`DocDocument` spočítal a uložil (v měně dokladu):

| pole | sloupec hlavičky |
|---|---|
| `totals.totalBase` | `total_base` |
| `totals.totalVat` | `total_vat` |
| `totals.totalAmount` | `total_amount` |
| `totals.totalRounding` | `total_rounding` |

Rozdíl větší než 0,01 (porovnává se v celých haléřích) je chyba
**`totals_check_failed`**: `/apply` vrátí 422 a nic se neuloží — kontrola
běží v transakci hned po uložení dokladu, neshoda ji vrátí i se založenými
entitami. `_resolve.issues` nese nález za každé pole, které nesedí:

```json
{
  "severity": "error",
  "path":     "totals.totalAmount",
  "code":     "totals_check_failed",
  "message":  "Kontrolní součet nesedí: totals.totalAmount uvádí 1211.00, výpočet dokladu dává 1210.00.",
  "declared": 1211.0,
  "computed": 1210.0
}
```

`/preview` pokynu hlásí týž nález předem nad `_resolve.computed.totals`
(místo varování `computed_total_mismatch`, které patří návrhu). Jedno
porovnání pro obě cesty: `TotalsCheck` (core.exchange). Platí i v importním
módu a pro každý cílový stav včetně Konceptu. Co kontrola znamená v praxi:

- Pole, které payload neuvádí (nebo je `null`), se nekontroluje. Kdo chce
  jen pojistku částky k úhradě, pošle samotné `totalAmount`.
- Nic se nepočítá znovu: u převzaté rekapitulace jdou součty z rekapitulace
  a z řádků „Mimo DPH“ (`docs/vat-calculation.md` § 5.1) — porovnává se
  s tím, co doklad opravdu uložil.
- `totalVat` je daň, kterou hlavička sčítá; daň samovyměření do ní nepatří
  (`sum_tax` kódu). Export ji píše stejně, zdroj mimo Shipard se tomu musí
  přizpůsobit.
- `totalRounding` u dokladu s automatickým zaokrouhlením plyne ze způsobu
  úhrady — neshoda tak odhalí i špatně uvedený způsob úhrady. To je záměr.
- Generátory, které částky nepočítají (periodická fakturace, opravný
  doklad), `totals` neposílají a kontrola se jich netýká.

**V návrhu jsou `totals` signál** — kontrolní součet se nevyhodnocuje
(D28), doklad se uloží s tím, co spočítal `DocDocument`, a rozdíl proti
předloze ukážou varování. K čemu tam `totals` a `vatRecap` slouží:

- **UI náhled** — chce je zobrazit (AI extrahovala součty z PDF, uživatel
  je kontroluje).
- **Validace** — validátor porovná deklarované totals s odhadem
  z canonicalu, pokud se liší, vyrobí warning `totals_mismatch`
  v `_resolve.issues`. Silný
  signál chybné extrakce řádků. Výjimka: deklarovaná **celá** částka
  v pásmu < 1,00 od vypočtené varianty projde bez warningu — jde
  o zaokrouhlení celkové částky faktury. Tohle je **heuristika** validátoru
  (odhad z canonicalu, bez DB). `/preview` navíc počítá částky **skutečným
  výpočtem dokladu** (`DocDocument::computeAmounts()`, blok
  `_resolve.computed`, §9) a porovná částku k úhradě → warning
  `computed_total_mismatch` (tolerance 0,01; `computed` už nese
  zaokrouhlení podle `total_rounding_mode`). Když je výpočet k dispozici,
  náhled heuristický `totals_mismatch` z issues **vyřadí** — dvě hlášky
  o tomtéž by mátly. `/validate` a `/apply` heuristiku nechávají.
- **Integrita řádků vs. rekapitulace** — `totals_mismatch` nechytí
  neúplné řádky, když AI rekapitulaci opsala z dokladu (recap si na
  deklarovanou částku vždy sedne). Proto validátor navíc porovná součet
  položkových řádků proti rekapitulaci podle efektivního režimu DPH
  (fromBase → Σ `base`, fromTotal → Σ `total`; fallback `totals`,
  tolerance per-řádkového zaokrouhlení) → warning **`rows_recap_mismatch`**
  na `rows`. Rekapitulace z dokladu je autoritativní — mismatch znamená
  neúplné či chybně extrahované řádky. Párové řádky samovyměření
  (`isReversePair: true`, dump dokladu se samovyměřením) nesou tentýž
  základ podruhé a do součtu nepatří. Stejnou kontrolu dělá při uložení
  i `DocDocument` nad převzatou rekapitulací.
- **Vnitřní aritmetika rekapitulace** — pro každý řádek recapu musí
  platit `base + tax = total` (±0,02) a `tax = base × pct/100`
  (±max(0,05; |base| × 0,001) — kryje haléře i výpočet koeficientem);
  reverse-charge páry a 0% řádky se přeskakují. Primární řádek
  samovyměření (hned za ním jde jeho pár `isReversePair` v téže sazbě —
  tak ho skládá `DocDocument::buildVatRecapitulation` a vypisuje dump) má
  celkem = základ, daň se dodavateli neplatí; kontrola sazby platí dál.
  Porušení → warning **`vat_recap_inconsistent`** na `vatRecap[i]`. Chytá
  rekapitulaci, kterou model dopočítal pozpátku (typicky po chybně určeném
  režimu DPH) místo opsání z dokladu. **Pozor:** u explicitního `declared`
  je to jen warning, ne důvod k přepočtu.

**Mód zaokrouhlení — `totals.totalRoundingMode` a `vat.roundingMode`**
(#166 D25, D26). Obě pole jsou nepovinná a jsou **záměr**; názvy ↔ kódy
`RoundingModes` drží `RoundingModeNames` (core.exchange) pro applier
i exporter:

| `totals.totalRoundingMode` | `total_rounding_mode` | význam |
|---|---|---|
| `cent` | 0 | na haléře (výchozí) |
| `unit` | 1 | matematicky na celé jednotky |
| `unitUp` | 3 | na celé jednotky nahoru |
| `unitDown` | 4 | na celé jednotky dolů |
| `fiveCent` | 5 | matematicky na 0,05 (hotovost SK a část eurozóny) |

`vat.roundingMode` (`vat_rounding_mode`, zaokrouhlení daně ve skupině
rekapitulace): `cent` (0, výchozí) nebo `unit` (1). Platí z payloadu v obou
režimech, odhad neexistuje.

Mód celkové částky se uplatní **při vypnuté automatice**
(`totals.totalRoundingAuto`, níže):

- **pokyn** — mód z payloadu, chybí = `cent`. Nic se neodhaduje: doklad
  zaokrouhlený na koruny bez `totalRoundingMode` vyjde na haléře a s `totals`
  neprojde kontrolním součtem;
- **návrh** — uvedený mód má přednost; bez něj ho applier odvodí porovnáním
  vypočtené a deklarované částky (`DocumentApplier::deriveTotalRoundingMode`,
  konzervativně jen pro rozdíl >= 0,01 a < 1,00, který některý mód přesně
  reprodukuje — rozdíl o jediný haléř je platné zaokrouhlení, 69,99 → 70,00;
  celé jednotky mají přednost před 0,05, protože celá částka je násobek 0,05
  taky). Validátor (`checkTotals`) mlčí přesně tam, kde applier mód přidělí.

Při **zapnuté automatice** mód určuje způsob úhrady a applier
`total_rounding_mode` neposílá. Uvádí-li pokyn jiný mód, než jaký
automatika dopočítala, platí automatika a `_resolve.issues` dostane varování
**`total_rounding_mode_ignored`** (v `/preview` i po `/apply`); pro vlastní
mód je potřeba `totalRoundingAuto: false`.

`totals.totalRounding` nese rozdíl ze zaokrouhlení se znaménkem
(zaokrouhleno dolů = záporné, např. `-0.05`). Applier z něj mód **nečte**:
v pokynu je to pole kontrolního součtu, v návrhu signál. Výslednou částku
a `total_rounding` dopočte `DocDocument` sám.

**Důsledek pro zdroje dat:** `totalAmount` musí být částka **po** zaokrouhlení
(placená) — starý Shipard drží `sumTotal` bez zaokrouhlení a runner musí
poslat `sumTotal + rounding` (reimport 2026-09-07: 211/311/321 o haléře
jinak, saldo nespáruje 70,00 proti 69,99). V pokynu k tomu patří
`totalRoundingAuto: false` a `totalRoundingMode` podle nastavení zdrojového
dokladu — jinak doklad vyjde na haléře a kontrolní součet ho odmítne. AI
extrakce a ISDOC (`PayableRoundingAmount`) jdou návrhem a mód neuvádějí.

**Automatické zaokrouhlení podle způsobu úhrady** (#73 D8, sloupec
`total_rounding_auto`): u prodejního dokladu (`invoiceIssued`,
`proformaIssued`, `cashRegisterDocument`, příjmový `cashDocument`) applier
nastaví příznak podle `totals.totalRoundingAuto`. Explicitní `true` /
`false` vyhrává. Bez něj (nebo `null`):

- **pokyn** — zapnuto všude, kde automatika platí; na `totals` nezáleží
  (#166 D26);
- **návrh** — zapnuto právě tehdy, když canonical nemá `totals.totalAmount`.
  Doklad s deklarovanou částkou (pošta, ISDOC) má automatiku vypnutou a mód
  uvedený, jinak odvozený z čísel.

Při zapnuté automatice mód určí `DocDocument::computeAmounts()` ze způsobu
úhrady, měny dokladu a země zdroje dat (`TotalRoundingAuto`,
`docs/vat-calculation.md` § 6) a náhled ho ukáže
v `_resolve.computed.totals.totalRoundingMode`. Přijatý doklad automatiku
nemá nikdy. `DocumentExporter` příznak vyváží vždy, mód celkové částky jen
při vypnuté automatice a jen mimo `cent`, `vat.roundingMode` jen mimo `cent`
— dump dokladů, kterých se zaokrouhlení netýká, se nemění a dump → seed
zachová stav dokladu.

### Polymorfismus podle `docType`

`docType` určuje, která pole jsou relevantní:

- **`invoiceReceived`** — strana, kterou _my_ pozici je `customer`,
  `supplier.bankAccount` se má vyplnit (kam platíme),
  `payment.paymentReference` typicky odvozeno od `docNumber`.
- **`invoiceIssued`** — strana, kterou my pozici je `supplier`,
  `supplier.bankAccount` (= náš účet) se vyplní z dokladu, customer
  z partnera.
- **`proformaIssued`** (`invpo`, zálohová faktura vydaná, #79 D1) — strany
  jako `invoiceIssued` (my `supplier`, partner `customer`, povinný).
  **Není daňový doklad** (`docTypes[].tax_document: false`):
  `dates.taxPointDate` / `dates.vatObligationDate` applier ignoruje
  a doklad nenese období DPH (`vat_period` / `cs_period` / `rs_period`
  zůstávají NULL, do tvrzení nevstupuje). Rekapitulace DPH a sazby řádků
  se zpracují jako u faktury. Řádky jen `sale.services` / `sale.goods`.
- **`proformaReceived`** (`invpi`, zálohová faktura přijatá, #106 D1) —
  strany jako `invoiceReceived` (my `customer`, partner `supplier`,
  povinný; `supplier.bankAccount` = kam platit; `docNumber` = číslo výzvy
  od dodavatele, chybějící hlásí warning `partner_doc_number_missing`
  jako u přijaté faktury). **Není daňový doklad**: `dates.taxPointDate` /
  `dates.vatObligationDate` applier ignoruje a doklad nenese období DPH.
  Řádky jen `purchase.goods` / `purchase.services` / `purchase.other`.
  Applier z AI analýzy (`applyRowOperations`) zatím typ nezná — přijde
  s poštou (#106 D4).
- **`accountingDocument`** (`cmnbkp`) — bez stran, kontační řádky
  (`accSide`, `account`); nepovinný partner hlavičky polem `partner`,
  partner řádku polem `rows[].partner` (reference na osobu, #166 D30).
- **`cashDocument`** (`cash`, pokladní doklad, #59 D12) — povinný **`cashDesk`**
  (reference na pokladnu, `"@POK"` = `economy_codebooks_cash_desks.code`)
  a **`cashDirection`** (1 příjem / 2 výdej). Řadu applier dohledá podle
  (typ, pokladna) — řady jsou vázané na pokladnu; uvedená jiná řada
  (`numberSeries`) je chyba `number_series_conflict` (422, #166 U3).
  Chybí-li řada, applier ji založí sám (`BoundNumberSeriesProvisioner`,
  idempotentní — pokladna založená generickým CRUD importu nespustí
  `afterSave` handler); pokladna, která se nenajde, je nález
  `reference_not_found` (422 `validation_failed`), pokladna mimo stav 40 =
  apply-level chyba `cash_desk_not_found` (422). Archivovanou pokladnu (70)
  přijme jen import mód (`applyOptions.importNumber`) — řady vzniknou ve
  stavu 70 (#59 Task E). `cash_desk` hlavičky se denormalizuje z řady.
  Strany `supplier`/`customer` nepovinné (anonymní doklad); je-li strana
  uvedená, je partnerem hlavičky podle směru (příjem → odběratel, výdej →
  dodavatel; `selfParty` má přednost) a v import módu z ní vzniká dobový
  snapshot. Partnera hlavičky jde určit i polem `partner` — má přednost
  před stranou, jiná osoba než strana je v pokynu `partner_conflict`
  (§ 5 „Reference na záznamy“). `payment.method` default `cash` (jinak jen
  `card`). Úhrady faktur hotově/kartou = řádky `operation:
  payment.receivable` / `payment.payable` s `totalPrice`,
  `paymentReference` (VS) a partnerem v poli `rows[].partner` (viz §7).
  Převody peněz (#59 Task D)
  = řádky `operation: transfer.in` (jen `cashDirection: 1`) /
  `transfer.out` (jen 2) s `totalPrice` a volitelným `paymentReference`
  (staré `symbol1`/`symbol2`), bez partnera; `operation` je passthrough,
  směr vůči `cashDirection` hlídá validace dokladu. Zálohy (#59 Task E):
  `advance.received` (jen příjem) / `advance.given` (jen výdej) s
  `totalPrice`, povinným partnerem v poli `rows[].partner` a
  volitelným `paymentReference`, bez DPH; záporná částka = vrácení. Odpočet
  `sale.advanceDeduction` / `purchase.advanceDeduction` je na `cashDocument`
  položkový řádek s DPH jako na faktuře (záporný základ). Stejné klíče platí i pro
  `operation` bankovní transakce (`shpd.bank.statement.v1`).
- **`cashRegisterDocument`** (`cashreg`, prodejka) — povinný `cashDesk`,
  bez `cashDirection` (pevně výstup); vratka = záporné řádky. `dataset-dump`
  oba pokladní typy vypisuje včetně `cashDesk` / `cashDirection`
  (`DocumentExporter`, #131 krok 0a); `numberSeries` u nich nevyváží —
  řadu určuje pokladna.
- **`invoiceIssued` / `proformaIssued` / `invoiceReceived` / `proformaReceived` + `cashDesk`** —
  volitelná reference na pokladnu platí jen s `payment.method: "cash"` (faktura placená hotově →
  `cash_desk` hlavičky, účtuje se na pokladnu místo 311/321); s jinou
  platbou se ignoruje s warningem `cash_desk_ignored`. Platba hotově **bez**
  `cashDesk` (návrh z AI pokladnu nezná) dostane výchozí pokladnu zdroje
  dat (`economy_codebooks_cash_desks.is_default`, info `cash_desk_defaulted`,
  #103 F3); bez výchozí pokladny zůstává prázdná a účtování ohlásí
  `cash_desk_account_missing` (deník s maskou `211???`).
- **`creditNote*`** — bude rozšířeno o `relatedDocNumber` (originál).
- **`order*`, `deliveryNote*`, `bankStatement*`** — budoucí rozšíření,
  držíme stejnou top-level kostru.

Pole, která nedávají smysl pro daný `docType`, mají být `null` nebo
vynechána. Validace polymorfismu je v PHP
(`DocumentValidator::checkPerDocType()`), JSON Schema definuje jen společnou
strukturu.

### Datum sazby DPH — `vat.rateSource` a vlastní data (#159 D11)

Procento DPH se na dokladu nezadává: řádek i řádek rekapitulace dostane
sazbu z číselníku podle kódu a **data sazby** (`docs/vat-calculation.md`
§ 5.2). Výměnný formát nese přepínač a vlastní data 1:1 se sloupci dokladu:

| canonical | sloupec | význam |
|---|---|---|
| `vat.rateSource` | `docs_core_heads.vat_rate_source` | `auto` (0, i `null`) — opravný doklad k datu původního plnění, jinak DPPD (`dates.vatObligationDate`), bez něj DUZP, bez něj datum vystavení; `document` (1) — vlastní datum dokladu; `rows` (2) — vlastní datum na řádcích |
| `dates.vatRateDate` | `docs_core_heads.vat_rate_date` | datum sazby všech řádků i řádků rekapitulace při `document` |
| `rows[].vat.rateDate` | `docs_core_rows.vat_rate_date` | datum sazby řádku při `rows`; prázdné = automatika |
| `vatRecap[].rateDate` | `docs_core_vat_recap.vat_rate_date` | datum sazby řádku převzaté rekapitulace při `rows`; prázdné = automatika |

Applier datum sazby nepočítá vlastním kódem: z canonicalu složí hlavičku
(`DocumentApplier::vatRateHead()`) a volá `VatRateDates::headDate()` /
`lineDate()` — totéž pravidlo jako dokument. **K datu sazby řádku** hledá
kód DPH (odvození, kontrola souladu, volba uživatele), sazbu a platnost
předmětu plnění, u řádku převzaté rekapitulace k datu řádku rekapitulace
(§ 8.4). Místo plnění, registrace DPH k datu, neplátce a zařazení do období
dál čtou `dates.taxPointDate`.

Hodnoty se na doklad jen předají; mazání dat mimo režim a zdroj `auto`
u nedaňového dokladu a dokladu bez DPH dělá dokument při uložení. Vlastní
datum mimo svůj režim (`dates.vatRateDate` při `rateSource` ≠ `document`,
`rows[].vat.rateDate` / `vatRecap[].rateDate` při `rateSource` ≠ `rows`)
applier ignoruje a hlásí jednou na doklad info **`vat_rate_date_ignored`**.

`vat.pct` řádku zůstává **signálem** pro odvození kódu a kontrolu souladu —
teď k datu sazby řádku. Přijatá faktura z ledna 2013 s plněním dodavatele
31. 12. 2012 (`vatObligationDate`) a řádkem `cz-110` 20 % tak kód ponechá;
dřív končila `vat_code_unknown`. Importní mód nemá výjimku: kód, který
k datu sazby řádku sazbu nemá, applier nenahrazuje (pokud ho neodvodil
podle pravidel § 8.4) a doklad odmítne validace dokumentu
(`vat_rate_missing`).

Exporter (datové sady) vypisuje `vat.rateSource` jen pro `document` / `rows`
a data jen vyplněná — doklad v automatice se exportuje beze změny. AI
extrakce pole nevyplňuje (#159 D12), ISDOC je nenese.

### Opravný daňový doklad — blok `correction`

Opravný doklad (#92, `tasks/corrective-doc-core.md` D1–D5,
`tasks/corrective-doc-exchange.md` D10, U1–U5) je faktura vydaná s příznakem
na hlavičce; ve výměnném formátu ho nese volitelný blok `correction`
(`originalDoc` reference | null; `originalDocNumber` string | null, max 40;
`originalTaxPointDate` date | null; `reason` string | null). Objekt = opravný doklad, `null` nebo chybějící
klíč = běžný doklad; objekt se samými `null` je platný (starý Shipard posílá
jen příznak).

**Typ dokladu.** Applier blok bere jen u typu s příznakem
`docTypes[].corrective` (`DocTypes::isCorrective()`, dnes `invoiceIssued`);
u jiného typu ho ignoruje a přidá warning `correction_not_supported` —
doklad vznikne jako běžný. Mapování: `corrective` = 1, `corrected_doc`
(odkaz), `corrected_doc_number`, `corrected_doc_date`, `correction_reason`.
Bez bloku se do hlavičky nic nezapisuje (příznak zůstává 0);
`applyOptions.replaceConcept` bez bloku sloupce opravy vynuluje.

**Odkaz na původní doklad** (`DocumentApplier::correctionDecision()`,
jednou per canonical — čtou ho datum sazby, kurz, `transform()` i náhled;
#166 D30):

1. Reference `correction.originalDoc` — id (posílá služba Vystavit opravný
   doklad), nebo `"@číslo dokladu"` (datové sady). Doklad musí existovat
   a mít typ s příznakem; nenalezená nebo nejednoznačná reference je chyba
   s kódem resolveru, doklad jiného typu chyba
   `correction_original_not_supported` (path `correction.originalDoc`,
   422 `validation_failed`). Číslo dokladu nemá unikátní index — shodné
   číslo u dvou dokladů je `reference_ambiguous`. Stav původního dokladu
   applier nehlídá — při potvrzení ho zkontroluje
   `DocDocument::validateCorrective` (V pořádku, stejný partner).
2. Jen v **návrhu** a bez reference: volba
   `_resolve.correction.userAction = "useExisting:<id>"`; neplatná = warning
   `pin_target_missing` (path `_resolve.correction`) a doklad vznikne bez
   odkazu. Pokyn volby `_resolve` nečte.

Podle `originalDocNumber` se **nic nedohledává** — je to jen text
(souhrnný opravný doklad, číslo na tisku); bez odkazu zůstane číslo i datum
plnění z bloku.

S odkazem applier přepíše `corrected_doc_number` a `corrected_doc_date`
(DUZP původního dokladu, bez něj datum vystavení) už v `transform()` —
dokument je z odkazu dosazuje znovu v `beforeSave()`, ale `validate()`
i odvození sazeb DPH běží dřív a čtou je z dat
(`VatRateDates`). Náhled vrací výsledek dohledání
v `_resolve.correction` (`status` matched | notFound | none,
`matchedId`, `matchedBy` ref | pin, `docNumber`, `taxPointDate`).

**Datum sazby DPH** (D4): automatické datum sazby opravného dokladu je
datum plnění původního dokladu (z odkazu, jinak `originalTaxPointDate`; bez
obojího běžná automatika DPPD → DUZP → datum vystavení) — jedna větev
pravidla `VatRateDates::automaticDate()`, viz „Datum sazby DPH“ výše.
Vlastní datum (`vat.rateSource` `document` / `rows`) má přednost i tady.
Místo plnění, registrace k datu, období DPH a KH dál čtou `taxPointDate` /
DUZP opravného dokladu.

**Kurz** (D4): u opravného dokladu v cizí měně applier kurz z lístku
nedoplňuje. S odkazem a shodnou měnou převezme kurz původního dokladu (info
`exchange_rate_from_original`); bez odkazu zůstane prázdný (info
`exchange_rate_corrective_manual`) a potvrzení zastaví validace dokladu.
`exchangeRate` z canonicalu vždy vyhrává; import mód nedohledává nic
(`docs/exchange-rates.md` § 5.2).

**Import mód** (`applyOptions.importNumber`): blok platí stejně — příznak,
odkaz referencí i datum sazby — a doklad se uloží v cílovém stavu. Datové
sady na tom stojí: dump vyváží blok ze sloupců s odkazem
`originalDoc: "@<číslo původního dokladu>"` (jen když odkaz existuje)
a seed ho podle něj obnoví — proto exportér řadí opravné doklady až za
běžné doklady téhož typu (`DocumentExporter::orderSql()`,
`docs/datasets.md` § 4).

## 6. Party object

Strana je **reference na osobu** — sama (`"supplier": 42`,
`"customer": "@F00042"`, `"@company_id:12345678"`), nebo objekt s `ref`
a daty (#166 D30, D34, D35):

```jsonc
{
  "ref":      "@F00042",           // reference na osobu: id, "@číslo osoby",
                                    //   "@company_id:IČO", "@vat_id:DIČ".
                                    //   Pokyn hledá jen podle ní; návrh ji
                                    //   bere přednostně, jinak hledá podle
                                    //   údajů níž (§ 8.1). AI ji nevyplňuje.
  "name":     "Dodavatel s.r.o.",
  "country":  "cz",                // ISO 3166-1 alpha-2, lowercase v canonical
                                    //   ("cz", "sk", "de", ...)

  // Identifiers — alespoň jeden silně doporučený, resolver je zkouší v pořadí
  "companyId": "12345678",         // IČO (CZ), Reg.č. (SK), USt-IdNr base (DE), …
  "taxId":     "CZ12345678",       // národní daňové číslo: v CZ DIČ = vatId; u zahraniční
                                   // strany jen číslo z dokladu (Steuernummer), jinak null (#103 D115)
  "vatId":     "CZ12345678",       // VAT ID (DIČ k DPH)


  "courtRegistration":
    "Obchodní rejstřík vedený MS v Praze, oddíl C, vložka 123456",

  "address": {
    "street":       "Hlavní",
    "houseNumber":  "1",
    "city":         "Praha",
    "cityPart":     "Nové Město",
    "zip":          "11000",
    "country":      "cz",
    "registryCode": null,          // RÚIAN address code, CZ-specific
    "displayLine":  "Hlavní 1, 110 00 Praha 1"
  },

  "contact": {                     // kontakt k dokladu: osoba + její kanály
    "name":  "Jan Novák",          // kontaktní osoba strany („Vyřizuje“, Attn,
                                    //   ISDOC Contact/Name) — jen náhled návrhu
    "email": "fakturace@example.cz",
    "phone": "+420 123 456 789",
    "web":   "https://example.cz"
  },

  "bankAccount": {                 // pro invoiceReceived: supplier's account
                                    //   (kam my platíme)
                                    // pro invoiceIssued: náš účet
                                    //   (kam customer platí)
    "accountNumber": "1234567890/0100",  // CZ/SK domestic form s bank kódem
    "iban":          "CZ6508000000001234567890",
    "bic":           "GIBACZPX",
    "currency":      "CZK"
  },

  "paymentTermDays": 14            // default splatnost pro due date
}
```

**Reference a data vedle ní.** V pokynu určuje osobu jen `ref`; objekt bez
`ref` s názvem, IČO nebo DIČ je chyba `reference_missing`, prázdný objekt
(anonymní pokladní doklad) strana bez osoby. Data vedle `ref` jsou
**dobový snapshot**: v importním módu (`applyOptions.importNumber`) z nich
vzniká snapshot partnera na dokladu (dobové DIČ pro kontrolní hlášení); mimo
něj se snapshot staví z adresáře a data z payloadu se nepoužijí. Strana jen
referencí v importním módu dostane snapshot z adresáře. Dvě osoby se stejným
IČO nebo DIČ jsou u `"@company_id:…"` / `"@vat_id:…"` chyba
`reference_ambiguous`. `DocumentExporter` píše stranu jako objekt s
`ref: "@<číslo osoby>"` a daty z adresáře.

**`contact` u dokladu vs. u Osoby.** V dokladu je `contact` *kontakt
k dokladu*: kontaktní osoba strany (`name` — „Vyřizuje“, „Kontaktní osoba“,
„Attn“, jméno uvedené nad názvem firmy; ISDOC `Contact/Name`) a její kanály.
Formát Osob (`shpd.persons.person.v1`) má naproti tomu `contact` jako
hlavičkový kanál firmy a jména osob vede v `contacts[]`. Hodnota
`contact.name` zůstává v návrhu (`canonical_json`) a zobrazuje ji náhled
návrhu (`DocumentExchangePreview`); **nepropisuje se** do Osoby
(`PartyResolver`, `PersonApplier`) ani do snapshotů dokladu
(`DocDocument::buildSnapshots()`, import-mód
`DocumentApplier::buildImportPartnerSnapshot()`) a `DocumentExporter` ji
nevydává (stranu skládá z Osoby). Jméno fyzické osoby bez názvu firmy
(účtenka, OSVČ) patří do `name` strany, ne do `contact.name`. Zadání:
`tasks/exchange-contact-name.md`.

### Self-party flow

Pokud `selfParty == "customer"`, applier ví, že druhá strana (`customer`) je
naše vlastní firma. Resolve pro customer:

1. Pokud `customer` je v payloadu vyplněn a obsahuje identifikátory →
   normální resolve (užitečné pro audit / kontrolu, že to skutečně jsme my).
2. Pokud `customer` je `null` / vynechán → applier ho doplní přes
   `OwnCompanyResolver::getOwnPersonId()`.

Symetricky pro `selfParty == "supplier"`.

**`balanceParty` — ruční plátce (#72 D2).** Volitelná třetí strana bez
vazby na `selfParty`: osoba pro saldokonto, za kterou vzniká saldokontní
řádek dokladu (`docs_core_heads.partner_balance`). Applier ji hledá
stejně jako `supplier`/`customer` (pokyn referencí, návrh i podle údajů —
`_resolve.balanceParty`, `userAction`, auto-create) a zapíše ji s `partner_balance_manual = 1` — odvození při
uložení (terminál / dopravce / partner) ji pak nepřepíše, v import módu
ani terminál. Vynechaná / `null` = plátce se odvodí při uložení. Exportér
ji vydává jen u dokladu s ručním plátcem; odvozeného si cílový DS odvodí
sám. Terminál a způsob dopravy kanonický formát zatím nenese (navazující
importní task).

`selfParty == null` znamená "nevíme / nezáleží" — typicky externí export
nebo import mezi dvěma cizími subjekty.

## 7. Row object

```jsonc
{
  "rowKind":  "item",              // key z docs.core.rowKinds
                                    //   item | text | section | discount | …
  "operation": null,               // key z docs.core.rowOperations (pohyb
                                    //   řádku). AI extraktory nevyplňují —
                                    //   interní účetní koncept, na předloze
                                    //   není; null applier při apply doplní
                                    //   podle typu položky / docTypu, viz
                                    //   §10 „Doplnění pohybu řádků".
  "orderPos": 1,

  // Položka: reference sama ("item": "@K-001", "item": 18), nebo objekt
  // s `ref` a texty (#166 D30). Pokyn hledá jen přes referenci; návrh hledá
  // i podle kódů a názvu, viz sekce 8.
  "item": {
    "ref":          "@K-001",      // reference na položku: id, "@kód",
                                    //   "@ean:…", "@sku:…". AI ji nevyplňuje;
                                    //   obohacení z historie ano.
    "ourCode":      null,          // DOČASNÉ (D39) — economy_items.code jako
                                    //   signál jen pro návrh (uložené návrhy
                                    //   z pošty); pokyn ho odmítá
                                    //   (signal_not_allowed) — patří tam `ref`
    "supplierCode": "KONZ-001",    // dodavatelský kód
                                    //   (per-partner mapování přes
                                    //    economy_items_supplier_codes)
    "sku":          "K-001-EN",    // optional
    "ean":          "8590123456789",
    "name":         "Konzultace",
    "description":  "Hodinová sazba senior konzultanta"
  },

  // Quantity & price
  "unit":          "hr",           // systémový kód jednotky (core_units
                                    //   .system_code) — holý kód, ne reference
                                    //   (D37). Pokyn bere jen přesnou shodu
                                    //   (jinak chyba unit_not_found); návrh
                                    //   zná i zkratky a aliasy ("h", "ks")
  "quantity":      10,
  "unitPrice":     1033.06,
  "totalPrice":    10330.58,       // informative; applier recomputes z
                                    //   quantity * unitPrice (s discountem)
  "priceCalcMode": "fromUnitPrice", // key z docs.core.priceCalcModes

  // Discount (pct OR amount, ne obojí)
  "discountPct":    null,
  "discountAmount": null,

  // VAT
  "vat": {
    "code": "cz-110",              // klíč z per-country VAT codes; u přijatého
                                   //   dokladu smí být null — kód odvodí
                                   //   applier ze signálů (§ 8.4)
    "pct":  21,                    // optional; signál pro odvození kódu
                                   //   a kontrolu souladu — na doklad jde
                                   //   vždy sazba z číselníku k datu sazby
                                   //   řádku (#159 D1)
    "supplyKind": null,            // goods | services | null — druh plnění,
                                   //   rozhoduje jen mimo tuzemsko; u přijatého
                                   //   dokladu bez hodnoty ho applier doplní
                                   //   ze štítku řádku (§ 8.4, warning
                                   //   `supply_kind_derived`)
    "reverseChargeCode": null,     // kód předmětu plnění u tuzemského
                                   //   přenesení daňové povinnosti ("4"
                                   //   stavební práce, "4a", "5" příloha 5,
                                   //   "14" mobilní telefony … — číselník
                                   //   world.vat reverseChargeSubjects);
                                   //   ukládá se na řádek dokladu (§ 8.4)
    "rateDate": null               // vlastní datum sazby DPH řádku — jen při
                                   //   vat.rateSource "rows" (řádek odpočtu
                                   //   zálohy ve staré sazbě); prázdné =
                                   //   automatické datum dokladu (#159, § 5
                                   //   „Datum sazby DPH“)
  },

  // Computed (informative)
  "computed": {
    "vatBase":   10330.58,
    "vatAmount": 2169.42,
    "vatTotal":  12500.00
  },

  // Text řádku na řádkové úrovni (docs_core_rows.description): účetní
  // doklad bez item fragmentu, export. AI extrakce ho nevyplňuje — text
  // se skládá z item.name + item.description, viz „Text řádku" níže.
  "description":      null,

  // Kontace / saldo identita (účetní doklad, úhrady payment.* na pokladním
  // dokladu): částka přímo v totalPrice, strana a účet u kontace, VS / SS /
  // KS / splatnost úhrady. Partner řádku je pole `partner` — reference na
  // osobu (42, "@F00042", "@company_id:…"), nebo objekt s `ref` (#166 D30).
  "partner":          null,
  "accSide":          null,          // "debit" | "credit" (jen kontace)
  "account":          null,          // účet řádku — reference na účet osnovy
                                     //   ("@518100", id). DOČASNÉ (D39): holé
                                     //   číslo ("518100") je signál jen pro
                                     //   návrh, pokyn ho odmítá
  "paymentReference": "2026000042",  // VS hrazeného dokladu
  "specificSymbol":   null,
  "constantSymbol":   null,
  "dueDate":          null,

  // Dimenze deníku řádku (#110 T2): stejný tvar jako na hlavičce (sekce 5),
  // → docs_core_rows.cost_center. Řádek bez hodnoty dědí hlavičku (engine).
  "dimensions":       { "costCenter": "@V01" }
}
```

### Text řádku

Text, který skončí v `docs_core_rows.description`, skládá **jediný**
helper `Shipard\Module\Core\Exchange\Document\CanonicalRowText::compose($row)`
([tasks/exchange-row-text.md](../tasks/exchange-row-text.md) D1/D2, #84):

1. neprázdný top-level `description` má přednost (účetní doklad, export,
   dataset round-trip, položka zadaná jen referencí — ta žádný text nenese
   a řádek bez `description` zůstane bez textu);
2. jinak `item.name`; za oddělovač ` — ` se připojí `item.description`,
   pokud je po trimu neprázdný a není v názvu obsažený (case-insensitive) —
   AI extrakce do popisu dává někdy užitečný detail (fakturované období,
   číslo služby), někdy šum (jednotka, záhlaví sloupců);
3. chybí-li název, samotný popis; nic → `null`.

Výsledek je oříznutý na 500 znaků. Tentýž helper používá applier
(`transformRows`), náhled (`_resolve.rows[i].rowText`, §9), poziční guard
`SupplierCodeCaptureHandler`, `ContentTagClassifier` i
`RowHistoryEnricher` (první kandidát matchování) — skládat text řádku
jinde znamená, že si vrstvy přestanou odpovídat (guard tiše přeskakuje,
historie nenapáruje exact matchem). Frontend skladbu nezrcadlí, jen
zobrazuje `rowText`.

## 8. Resolve

> **Hledání podle údajů v této kapitole (§ 8.1–§ 8.3, § 8.5) platí pro režim
> návrh** (#166 D31, D33). **Pokyn** hledá záznamy jen přes reference
> (§ 5 „Reference na záznamy“, § 8.6) a nic nezakládá: stav `canCreate`
> ani `ambiguous` v něm nevzniká, nenalezená reference je chyba. I v návrhu
> má uvedený `ref` strany nebo položky přednost — hledání se přeskočí
> a blok `_resolve` nese `matchedBy: "ref"`.

Resolver je sada nezávislých "lookuperů", které pro každou referenci v canonical
vrací jeden ze čtyř stavů:

| Status | Význam |
|--------|--------|
| `matched` | Jednoznačně napárováno, vrací konkrétní `id` |
| `ambiguous` | Víc kandidátů, vrací `candidates: [{id, name, …}]`, UI rozhoduje |
| `notFound` | Žádný match, není kandidát na vytvoření (např. neznámá `vatCode`) |
| `canCreate` | Žádný match, ale lze vytvořit z payloadu (Party, Item) |

Resolvery jsou idempotentní (čisté čtení DB). Mutace probíhá až v Applieru
podle `_resolve.*.userAction`.

### 8.1 PartyResolver

Vstup: `Party` object + `country` hint.

Postup:

1. **`companyId` exact match** → je-li 1 výsledek, `matched`
   (`matchedBy: "companyId"`).
2. **`vatId` exact match** → analogicky (`matchedBy: "vatId"`).
3. **`taxId` exact match** → analogicky (`matchedBy: "taxId"`); `null`
   sondu přeskočí — zahraniční strana s `vatId` a bez `taxId` (#103 D115)
   se páruje přes `vatId`. Země se při párování identifikátorů nepoužívá.

   Identifikátory se před párováním **normalizují** pravidly
   `world.validations` podle země strany (`country`, jinak
   `address.country`; bez země jen obecná pravidla — ostatní pošta
   a registr tak párují beze změny) a IČO se hledá i **bez vedoucích
   nul** — data z doby před normalizací (#118 D16). V applieru je
   canonical už normalizovaný (§ 10 → Normalizace a validace hodnot);
   resolver normalizuje znovu jako pojistku pro ostatní volající.

4. **`name` fuzzy s `country` filterem** — full-text + Levenshtein,
   `score >= threshold` → kandidáti seřazení podle score.
   - 1 kandidát se score `>= 0.95` → `matched`, `matchedBy: "name"`.
   - 1+ kandidátů 0.6 ≤ score < 0.95 → `ambiguous` se seznamem.
5. Žádný kandidát → `canCreate` s payloadem připraveným pro
   `PersonDocument::saveDocument()`.

**Self-party kratce:** `customer` (resp. `supplier`) při `selfParty == "customer"`
(`"supplier"`) → resolver vrátí `matched` s `personId` z `OwnCompanyResolver`,
`matchedBy: "selfParty"`. Pokud canonical přesto obsahuje identifikátory pro
self-party stranu, resolver porovná IČO/DIČ s vlastní firmou a vrátí warning,
když se liší — silný signál chybně extrahovaného dokladu.

### 8.2 ItemResolver

Vstup: `Row.item` object + kontext (resolved `supplier.personId` pokud existuje,
pro per-partner mapování).

Postup (jen návrh; řádek s `item.ref` se nehledá — § 8):

1. **`ourCode` exact match** v `economy_items.code` → `matched`,
   `matchedBy: "ourCode"` (signál uložených návrhů, D39).
2. **`(supplier.personId, supplierCode)` lookup** v nové tabulce
   `economy_items_supplier_codes` → `matched`, `matchedBy: "supplierCode"`.
3. **`ean` exact match** v `economy_items.ean` (nový sloupec) → `matched`,
   `matchedBy: "ean"`.
4. **`sku` exact match** v `economy_items.sku` (nový sloupec) → `matched`,
   `matchedBy: "sku"`.
5. **`name` fuzzy** v `economy_items.name` + `description` → kandidáti.
6. Žádný kandidát → `canCreate` s payloadem připraveným pro vytvoření
   `economy_items` row (potřebuje uživatelské doplnění `item_kind`).

I `matched` výsledek nese `createPayload` — jen `name` a `description`
(#111 D5, U3): review modal z něj předvyplní „Vytvořit novou položku“
i u automaticky napárovaného řádku. Bez `code`, `sku` a `ean`, přes které
se řádek napároval (`ourCode` z historie je kód existující položky) — nová
položka by s nimi kolidovala. Applier ho u `matched` nečte.

Per-partner mapování v `economy_items_supplier_codes` se buduje jednak ručně,
jednak applierem: když uživatel rozhodne "tato extrahovaná položka odpovídá naší
`K-001`" pro `supplierCode: "KONZ-001"` od `personId: 42`, applier zaznamená
mapping. Příště se napaří automaticky. Výslovná volba položky v review
(`userAction: useExisting:<id>` u řádku se `supplierCode`) naučené
mapování **přepíše** (`ON DUPLICATE KEY UPDATE`, #111 D8) — oprava
špatného napárování se projeví u příští faktury; automatické napárování
a potvrzení z Konceptu (`SupplierCodeCaptureHandler`) zapisují jen
`INSERT IGNORE`.

### 8.3 UnitResolver

Vstup: `unit` string.

Postup v **návrhu**:

1. **ISO kód** (e.g. `"h"`, `"kg"`, `"pcs"`, `"l"`) — mapování v
   `core.units.unitsSeed` na `core_units.code`. → `matched`.
2. **Lokalizovaná zkratka** (`"ks"` → pcs, `"hod"` → h, `"l"` → l) přes
   alias tabulku v PHP resolveru.
3. Žádný match → `notFound`. Applier použije default unit (`pcs`) a vyrobí
   warning `unit_not_found`.

V **pokynu** (#166 D37) platí jen přesná shoda na systémový kód jednotky
(`core_units.system_code`, např. `"hr"`, `"pcs"`, `"mnth"`) — bez aliasů,
zkratek a úprav hodnoty; neznámá jednotka je **chyba** `unit_not_found`.
Jednotka bez systémového kódu se ve formátu vyjádřit nedá (exportér ji
s varováním vynechá).

### 8.4 VatCodeResolver

> **Odvozování v této kapitole platí pro režim návrh** (§ 3, #166 D19):
> doklad neplátce, doklad mimo české DPH, místo plnění z DIČ, odvození
> a náhrada kódu, volby uživatele. V **pokynu** se kód jen ověří (kroky 1,
> 2 a 4 níže) v číselníku země registrace dokladu — `vat.pct` z payloadu
> se nepoužije, chybějící kód zůstává „nevyplněno“ a nic se neodvodí.

Vstup: `vat.code` string + země registrace + **datum sazby řádku**
(`VatRateDates::lineDate()` — § 5 „Datum sazby DPH“, #159; ne DUZP).

Postup:

1. Volá existující `VatRateResolver::getVatCodes($country, ...)` (modul
   `world.vat`).
2. Lookup podle `vat.code` v vrácené mapě → `matched` s definicí kódu
   včetně `vat_pct`, `reverseVatCode`, `noPayTax` atd.
3. Pokud `vat.pct` v payloadu chybí, doplní z resolved code + date přes
   `VatRateResolver::resolveVatPct($country, $code, $date)`.
4. Žádný match → `notFound` + error `vat_code_unknown`.

**Země registrace:** u přijatého dokladu (`selfParty: "customer"`) na zdroji
s registrací DPH **platnou k datu dokladu** (`valid_from` / `valid_to`
proti DUZP, bez něj datu vystavení, bez obou dnešku) **vždy naše
registrace** — první aktivní podle země a id, stejné pořadí jako výchozí
hodnota formuláře dokladu (D2). `vat.registrationCountry` z AI i ISDOC se
ignoruje; při rozporu info issue `vat_registration_country_derived`.
Přijatý doklad, ke kterému žádná registrace neplatí, je doklad neplátce —
kódy DPH se u něj neresolvují vůbec (podsekce níže). U ostatních dokladů
(vystavené, účetní) kaskáda `vat.registrationCountry` → prefix kódu
(`cz-110` → `cz`) → země dodavatele. **Pokyn** (§ 3) hledá kódy v zemi
registrace dokladu (`vat.registrationCountry`, jinak výchozí registrace
zdroje dat); bez registrace zůstává kaskáda. Import
(`applyOptions.importNumber`) je pokyn, který platnost registrace k datu
nezkoumá.

#### Přijatý doklad neplátce DPH

`tasks/exchange-received-non-vat-payer.md` (#97). Zdroj dat, který k datu
dokladu není plátcem DPH, si daň dodavatele odečíst nemůže — je pro něj
**součástí ceny pořízení** a musí skončit v nákladech i v závazku vůči
dodavateli. Doklad proto vznikne **Bez DPH** (`vat_mode` 0) a jeho řádky
nesou ceny **včetně daně dodavatele**.

**Kdo je neplátce (D2):** přijatý doklad (`selfParty: "customer"`), mimo
import, a žádná aktivní registrace DPH platná k datu dokladu — DUZP, bez
něj datum vystavení, bez obou dnešek. Rozhoduje registrace k datu, ne
příznak `economy.vatAgenda` (`docs/ds-setup.md` D5): bývalý plátce má
doklady po `valid_to` jako neplátce, starší jako plátce.

**Cena řádku s daní (D4)** — pro každý položkový řádek (ne kontační)
první dostupné:

1. `rows[].computed.vatTotal` od dodavatele (ISDOC, případně AI),
2. řádky už v cenách s daní — režim dokladu deklarovaný nebo odvozený
   `VatModeDerivation` je `fromTotal` → cena řádku beze změny,
3. cena řádku × (1 + `vat.pct` / 100), zaokrouhleno na 2 místa; řádek bez
   sazby nebo s 0 % beze změny.

Cenou řádku se rozumí `totalPrice` (chybí-li, množství × jednotková cena)
**po slevě**; u řádku se slevou z ceny za jednotku se sleva odečítá od
množství × jednotkové ceny, protože `totalPrice` z dokladu dodavatele ji
zpravidla už obsahuje.

**Dorovnání na rekapitulaci dodavatele (D5):** je-li `vatRecap` úplný
(každý řádek má `base` i `total` — stejná podmínka jako u
`VatModeDerivation`), srovnají se řádky každé sazby na `vatRecap[].total`
té sazby. Rozdíl do tolerance zaokrouhlení (`max(0,02; 0,01 × počet řádků
sazby)`) jde na řádek s největší absolutní částkou; větší rozdíl se
nedorovnává a náhled ho ukáže jako `computed_total_mismatch`. Doklad tak
sedí na částku k úhradě i v haléřích.

**Co skončí na dokladu:** hlavička `vat_mode` 0, bez `vat_registration`
a bez rekapitulace (D8); řádky `total_price` = cena s daní,
`price_calc_mode` 1 (z celkové ceny, jednotkovou cenu dopočítá
`DocRowCalculator`), bez slev (cena je obsahuje) a bez `vat_code` /
`vat_pct` — kód z historie řádků ani sazba dodavatele se nepropisují.
Jednotková cena se dopočítá i u záporného množství (přijatý dobropis:
−10 ks za −12 500 = 1 250 za kus); jen množství 0 nebo prázdné dává 0 (#98).
`total_rounding_mode` se odvodí ze součtu převedených řádků. Účtování
žádnou změnu nepotřebuje: doklad Bez DPH zaúčtuje plnou částku řádku.

| Situace | `vat_mode` | Řádky | Issues |
|---|---|---|---|
| neplátce, dodavatel plátce, ceny bez daně (ISDOC, AI fromBase) | 0 | ceny s daní dle D4/D5 | info `vat_non_payer` |
| neplátce, ceny už s daní (účtenka, fromTotal) | 0 | ceny beze změny (+ D5) | info `vat_non_payer` |
| neplátce, dodavatel neplátce (doklad daň nenese) | 0 | jak přišly | — |
| neplátce, doklad nese přenesení daňové povinnosti (`vat.reverseCharge` nebo `rows[].vat.reverseChargeCode`) | 0 | dle D4 | + warning `non_payer_reverse_charge` |
| plátce (registrace platná k datu) | beze změny | beze změny | beze změny |
| bývalý plátce, doklad po `valid_to` | 0 | dle D4/D5 | info `vat_non_payer` |
| vystavený doklad, import | beze změny | beze změny | beze změny |

„Doklad nese daň dodavatele“ = některý položkový řádek má kladnou sazbu
nebo nenulovou daň, případně ji nese rekapitulace. Jen takový doklad se
převádí a hlásí `vat_non_payer`; doklad od neplátce zůstává, jak přišel.

U neplátce se **nehlásí** `vat_mode_derived`, `vat_mode_suspect` ani
`recap_source_computed_fallback` — režim i rekapitulace jsou dané a hlášky
by mátly. Volby DPH (`useValue:` / `useCode:`) se u něj ignorují s info
`vat_pin_ignored`; nabídka `_resolve.vatCodeOptions` chybí.

Mimo rozsah: samovyměření u neplátce, který je identifikovanou osobou
(jen warning `non_payer_reverse_charge`, daň se nevyměří).

#### Přijatý doklad mimo české DPH

`tasks/exchange-received-foreign-vat.md` (#89 task A). Dodavatel mimo
tuzemsko, který účtuje **vlastní (zahraniční) DPH**, nebo poskytuje službu
se **zvláštním místem plnění** (hotel, tankování, letenka, mýto v cizině):
daň se v ČR nepřiznává a zahraniční daň si plátce v přiznání neodečte.
Číselník na takové plnění kód nemá a nedostane ho (D1) — doklad vznikne
celý **Bez DPH** (`vat_mode` 0), řádky bez kódu DPH. Dřív návrh končil
chybou `vat_code_unknown`.

Rozhoduje se jednou na doklad ve `vatContext()` (`outOfScope`), jen ve
větvi derivace — přijatý doklad (`selfParty: "customer"`) na zdroji
s registrací DPH platnou k datu, mimo import — a až po konečném místě
plnění, tedy po volbě místa:

| Situace (efektivní místo ≠ tuzemsko) | `outOfScope` |
|---|---|
| volba režimu **Bez DPH** (`vat.mode.userAction = "useValue:none"`) | `user` (D4) |
| volba režimu s DPH (`fromBase` / `fromTotal`), nebo volba kódu (`useCode:`) na kterémkoli řádku | — (jako dřív) |
| některý řádek má ponechaný kód z canonicalu / historie řádků (platný a v souladu se signály) | — (jako dřív) |
| doklad nese daň dodavatele a `vat.reverseCharge` není `true` | `foreignVat` (D2) |
| doklad nese daň dodavatele a `vat.reverseCharge: true` (smíšený doklad) | — (jako dřív) |
| doklad daň nenese a **každý** položkový řádek je `special` — štítkem z bloku, nebo štítky své položky (viz *Zvláštní místo plnění ze štítků položky*) | `specialPlace` (D3, D20) |
| cokoli jiného (část řádků `special`, služba bez štítku, nejednoznačná položka, dovoz zboží, …) | — (jako dřív) |

„Doklad nese daň dodavatele“ je tentýž predikát jako u neplátce
(`SupplierVatPrices::carriesSupplierVat()`). Ponechaný kód potvrdil člověk
(historie řádků) — takový doklad dřív prošel se samovyměřením a projde
dál; režim mimo DPH nahrazuje jen dřívější chybu. Neplátce, vystavený
doklad a import se nemění; v tuzemsku je jediným druhem doklad od neplátce
(níže).

**Doklad od neplátce DPH v tuzemsku** (#164 D5a,
`tasks/vat-codebook-revision.md`). S efektivním místem plnění **v tuzemsku**
je doklad mimo české DPH jen tehdy, když ho vystavil neplátce — druh
`supplierNonPayer`. Dřív dostával kód „Bez daně“ a nulovou rekapitulaci;
kód zanikl a doklad vznikne celý Bez DPH s řádky bez kódu:

| Situace (efektivní místo = tuzemsko) | `outOfScope` |
|---|---|
| volba režimu s DPH (`fromBase` / `fromTotal`) | — |
| režim není Bez DPH — ani `vat.mode: "none"` na dokladu, ani volba `useValue:none` | — |
| doklad nese daň dodavatele, nebo některý řádek nenulovou sazbu | — (deklarované `none` u dokladu s daní se řeší jako dřív) |
| přenesení daňové povinnosti — `vat.reverseCharge: true`, předmět plnění na řádku, nebo kód řádku se samovyměřením | — (Bez DPH se dál tiše přepíná na `fromBase`, D11 z #87) |
| bez volby režimu: volba kódu (`useCode:`) na kterémkoli řádku, nebo řádek s ponechaným kódem | — (volbou kódu se uživatel vrací k dokladu s DPH) |
| cokoli jiného | `supplierNonPayer` |

Volba režimu Bez DPH má i tady přednost před volbou kódu řádku
(`vat_pin_ignored`). Ceny řádků se **nemění** — neplátce daň neúčtuje,
není co převádět.

**Ruční Bez DPH (D4):** volba režimu `none` v náhledu u dokladu
s efektivním místem mimo tuzemsko platí — doklad je mimo české DPH i tam,
kde by ho derivace vyřešila samovyměřením (služba z EU, kterou účetní
vědomě vede bez daně; smíšený doklad). Má přednost před vším, i před
volbou kódu řádku (ta se ohlásí info `vat_pin_ignored`). V tuzemsku
a u `none`, které jen deklarovala AI (bez volby), zůstává tiché přepnutí
na `fromBase` u řádků se samovyměřením (D11 z #87) — jinak by se ztratil
nárok i oddanění. `auto` v `_resolve.vat.mode` dál říká, co by vyšlo bez
volby (např. `fromBase`), takže volba *Automaticky* samovyměření vrátí.

**Co skončí na dokladu** — totéž jako u ručně založeného dokladu Bez DPH:
hlavička `vat_mode` 0, bez rekapitulace, `vat_place` = efektivní místo
plnění, `vat_registration` naše (formulář ji při přepnutí na Bez DPH také
ponechává); řádky bez `vat_code` / `vat_pct`. U `foreignVat` jsou ceny
řádků **včetně daně dodavatele** — tentýž převod jako u neplátce (D4 / D5
výše, `SupplierVatPrices`), v měně dokladu; u `specialPlace`
a `supplierNonPayer` ceny beze změny; u `user` podle toho, zda doklad daň
dodavatele nese. Účtování žádnou změnu nepotřebuje a doklad se nedostane do
přiznání ani do kontrolního hlášení.

**`_resolve`:** `vat.mode` = `{value: "none", source: "outOfScope", reason:
"foreignVat" | "specialPlace" | "user" | "supplierNonPayer", auto: …}`
(u `supplierNonPayer` z volby režimu říká `auto`, co by vyšlo bez ní);
řádky nesou blok `vatCode`
`{status: "matched", matchedBy: "outOfScope"}` bez kódu — náhled na něm
drží volbu kódu; `vatCodeOptions` zůstává. U `specialPlace` blok říká,
odkud `special` řádku je: `specialSource: "tag"` (štítek z bloku
`_resolve.contentTag`) nebo `"item"` (štítky položky pokrytého řádku, D19). Volbou kódu u řádku (nebo
režimu s DPH) se uživatel vrátí k dřívějšímu chování: řádky se rozhodují
derivací a nerozhodnuté hlásí `vat_code_unknown`. `_resolve.computed`
počítá výsledek stejnou cestou jako u neplátce.

**Issues:** info `vat_foreign_tax`, warning `vat_special_place`
(rozhodnutí stojí na štítku, proto varování s cestou zpět; když `special`
plyne ze štítků položky, zpráva položku jmenuje), warning
`vat_out_of_scope_user` (zvolené Bez DPH mimo tuzemsko), nebo info
`vat_supplier_non_payer` (doklad od neplátce v tuzemsku); apply neblokuje
žádná. Režim
`safe` doklad vystaví, ale k `vat_special_place` se jednoklikem z karty
nedojde: karta takového návrhu má strop pásma na *review*
(`docs/dashboard.md` § 5.1, #89 D17) a MCP `mail_draft_document` varování
vrací v odpovědi (`warnings`, D18). U dokladu mimo české DPH se **nehlásí** `vat_code_unknown`,
`vat_code_derived`, `supply_kind_derived`, `vat_mode_derived`,
`vat_mode_suspect` ani `recap_source_computed_fallback`.

Mimo rozsah (D5): smíšené doklady, vrácení daně z jiného členského státu.

#### Zvláštní místo plnění ze štítků položky

`tasks/exchange-special-place-covered-rows.md` (#89 D19–D21). Štítek
z bloku `_resolve.contentTag` vzniká jen u návrhu s nepokrytým položkovým
řádkem. U opakovaného dodavatele jsou řádky pokryté položkami z historie
(`rows[].item.ourCode`), blok chybí — a hotel v cizině by se beze slova
odvodil jako běžná služba se samovyměřením. Řádek **bez štítku z bloku**
(výjimka řádku ani štítek dokladu), který nese `item.ourCode`, se proto
klasifikuje podle `economy_items.content_tags` své položky. Počítají se
jen štítky, které mají v taxonomii `crossBorderSupply`:

| Štítky položky | Stav řádku |
|---|---|
| aspoň jeden s `crossBorderSupply` a všechny takové jsou `special` | `special` |
| mezi nimi je `special` i jiná hodnota (`goods` / `services`) | nejednoznačný |
| žádný `special`; položka bez štítků, neaktivní nebo neexistující | nic |

Štítek bez `crossBorderSupply` není „ne“ — položka *mýto + správní
poplatky* je `special`. Štítek z bloku má přednost vždy, i když sám místo
plnění neurčuje. Druh plnění (`goods` / `services`) se z položky
neodvozuje. Řádek, u kterého uživatel v náhledu zvolil položku
(`_resolve.rows[i].item.userAction`), se z položky neklasifikuje — na
dokladu skončí jiná položka, než nese canonical.

- **`special` z položky = štítek řádku (D20).** Platí pro něj totéž co
  pro `special` z bloku: veto v derivaci kódu, podmínka „každý položkový
  řádek je `special`“ pro doklad mimo české DPH (`vat_special_place`)
  i strop pásma karty návrhu. `_resolve.rows[].vatCode.specialSource` je
  `"item"`; u veta smíšeného dokladu zpráva `vat_code_unknown` položku
  jmenuje.
- **Nejednoznačná položka (D21).** Chování se nemění — řádek se odvodí
  jako dřív. Když je kód řádku **odvozený** (mimo tuzemsko je to vždy
  samovyměření), doklad nenese daň dodavatele a uživatel nezvolil režim,
  dostane řádek warning `vat_special_place_ambiguous`. Řádek s kódem
  ponechaným z canonicalu / historie (`matchedBy` vstupu) ani s volbou
  kódu varování nedostane. Karta návrhu má strop pásma *review*, když
  takový řádek v canonicalu nemá kód DPH (`docs/dashboard.md` § 5.1).

Pravidlo drží `SpecialPlaceSupply::classifyRow()` — čistá funkce nad
canonicalem, taxonomií a mapou kód položky → štítky; applier i strop pásma
ji sdílejí. Mapu čte `ItemContentTags::forCodes()` (jen živé položky,
klíč bez rozlišení velikosti písmen): applier jedním dotazem na doklad
a jen mimo tuzemsko — tuzemský doklad se na štítky položek neptá —, feed
jedním dotazem na sběr karet, detail zprávy jedním na zprávu. Ponechaný
kód z historie dál režim mimo DPH ruší (tabulka výše).

Mimo rozsah: štítek uložený na řádku dokladu a vracený z historie (přesné
i u sběrných položek, ale změna schématu), druh plnění z položky.

#### Místo plnění přijatého dokladu (`VatPlaceDerivation`)

Model čte pravidlo „intracom = dodavatel z jiného státu EU“ podle adresy;
dodavatel se sídlem mimo EU, který fakturuje pod DIČ jiného členského
státu (americký SaaS s irskou registrací), pak dostal `thirdCountry`
a kód `cz-417` (ř. 12 přiznání) místo `cz-217` (ř. 5). Pro ř. 5 rozhoduje
registrace k dani v jiném členském státě, tedy **prefix DIČ dodavatele**,
ne sídlo (`tasks/exchange-received-vat-place.md` D1/D2). Proto u přijatého
dokladu na zdroji s registrací DPH určuje místo plnění systém a teprve
z něj se odvozuje kód (níže).

Data: cfgItem `world.trade.unions` (`modules/world/trade`) — členství zemí
v uniích k datu, `taxPrefixes` s `country`, platností (`GB` do 2020-12-31)
a volitelným `supplyKinds` (`XI` jen zboží). Čtení `TradeUnionResolver`,
rozhodování `VatPlaceDerivation`:

1. DIČ dodavatele normalizovat (uppercase, jen `[A-Z0-9]`), prefix = první
   dva znaky, jen když jsou písmena;
2. DIČ dodavatele = DIČ odběratele → bez derivace (model dal naše DIČ
   k dodavateli);
3. unie, jejichž členem je země naší registrace k DUZP (fallback datum
   vystavení); z nich ta, jejíž `taxPrefixes` prefix zná — žádná / víc →
   bez derivace;
4. prefix k datu mimo platnost → `thirdCountry`;
5. `supplyKinds` na prefixu: kterýkoli položkový řádek mimo seznam →
   `thirdCountry`, kterýkoli bez `supplyKind` → bez derivace;
6. země prefixu = naše země → `domestic`, jinak `intracom`.

Efektivní místo drží `DocumentApplier::vatContext()` (`place`,
`placeSource: "vatId" | "ai" | null`, `placePrefix`) a čtou ho tři místa:
derivace a kontrola souladu kódu řádků, transform `vat_place` hlavičky
a hlavičkové issues. Canonical se nemění (vzor `VatModeDerivation`);
`supplier.country` zůstává, jak přišlo.

| `vat.place` z AI / ISDOC | derivace | výsledek |
|---|---|---|
| null (ISDOC) | místo | odvozené, bez issue |
| stejné | místo | beze změny |
| jiné (platné) | místo | odvozené + warning `vat_place_derived` |
| neznámá hodnota (`eu`) | místo | odvozené + `vat_place_derived` (bez `vat_place_unknown`) |
| cokoli | `null` | dnešní chování (`vat_place_unknown` u neznámé hodnoty) |

Bez derivace (`null`): doklad bez DIČ dodavatele, prefix, který unie nezná
(`US`, `CHE`, `EU…` z režimu OSS mimo Unii), prohozené strany, zdroj mimo
unii, chybějící datum. Mimo rozsah: ověření DIČ ve VIES (derivace věří
prefixu), stálá provozovna v ČR vedle zahraničního DIČ (bere se DIČ, které
přišlo), vystavené doklady.

#### Odvození kódu DPH u přijatých dokladů (`VatCodeDerivation`)

AI ani ISDOC neznají klíče číselníku (model vracel `reverse-charge`,
`eu-reverse`); kód proto určuje systém ze sémantických signálů — hlavička
`vat.place` (efektivní místo, viz výše), `vat.reverseCharge`, řádek `vat.pct`, `vat.supplyKind`
(`tasks/exchange-received-reverse-charge.md` D1/D4). Kandidáti = vstupní kódy číselníku naší registrace pro místo
plnění, bez `hidden`, bez `reducedDeduction` (krácený odpočet se nikdy
neodvozuje) a bez kódu „Mimo DPH“ (`cz-000`, kategorie `outOfScope` — #164
D5c: ze signálů se neodvozuje, volí ho uživatel v náhledu nebo přijde jako
`vat.code` v payloadu):

- **samovyměření** (`reverseCharge: true`, nebo místo ≠ tuzemsko): kódy
  s `reverseVatCode` kategorie `standard`; mimo tuzemsko rozhoduje
  `supplyKind` (EU zboží `cz-215`, služby `cz-217`; třetí země služby
  `cz-417` — dovoz zboží se neodvozuje, viz níže), v tuzemsku obecný
  vstupní kód přenesení základní sazby (`cz-115`, příznak `reverseCharge`
  kódu) — **předmět plnění kód nevybírá**, je to samostatný údaj řádku
  (viz „Předmět plnění“ níže); sníženou sazbu volí uživatel výběrem kódu
  v náhledu;
- **tuzemsko bez samovyměření:** kód bez `reverseVatCode`, jehož sazba
  k datu sazby řádku = `pct` řádku (`cz-110`, `cz-111`; 0 % = osvobozené
  plnění `cz-114`, #164 D5b; historicky `cz-301`). Doklad od neplátce
  (režim Bez DPH, řádky bez sazby) se k řádkům nedostane — vznikne celý
  Bez DPH, viz *Přijatý doklad mimo české DPH*.

Mimo tuzemsko se odvozují jen kódy samovyměření: vstupní „Osvobozeno“
(`cz-214`, `cz-414`) a třístranný obchod (`cz-220`) volí uživatel.

Kód vznikne jen z **právě jednoho** kandidáta. Odvozený kód dostane sazbu
z číselníku k datu sazby řádku — u samovyměření tedy 21, ne 0 z dokladu
dodavatele.

**Co se řídí datem sazby a co DUZP (#159 U3).** K datu sazby řádku:
odvození kódu a kontrola souladu, nabídka kódů pro validaci volby
uživatele, sazba v `createPayload.pct` (shodná s tím, co odvodí dokument),
platnost předmětu plnění; u řádku převzaté rekapitulace k datu řádku
rekapitulace. K DUZP (`dates.taxPointDate`, fallback datum vystavení):
místo plnění (`VatPlaceDerivation`), registrace DPH k datu a neplátce
(#97), rozhodnutí o samovyměření pro rekapitulaci a zařazení do období.

| vstupní `vat.code` | derivace | výsledek v `_resolve.rows[].vatCode` |
|---|---|---|
| prázdný | kód | `matched`, `matchedBy: "derived"`, bez issue |
| platný a v souladu se signály | cokoli | beze změny (`matchedBy: "cfgItem"`) |
| neznámý, nebo v rozporu se signály | kód | odvozený kód + warning `vat_code_derived` (původní hodnota ve zprávě) |
| neznámý / prázdný | `null` | `notFound` + error `vat_code_unknown` s důvodem a „doklad založ ručně“ (doklad mimo české DPH se k řádkům nedostane — viz výše) |
| prázdný, bez signálů | — | bez bloku `vatCode` (jako dřív) |
| `cz-000` „Mimo DPH“ (z payloadu nebo z historie řádků) | cokoli | beze změny — nemá místo, směr ani sazbu k porovnání, v rozporu se signály není nikdy (`VatCodeDerivation::conflict()`); řádek je mimo rekapitulaci a v částce dokladu |
| libovolný, **volba uživatele** `userAction: "useCode:<kód>"` (#87 task B) | cokoli | zvolený kód, `matchedBy: "user"`, sazba z číselníku k datu sazby řádku; kód mimo nabídku k datu sazby řádku (`_resolve.vatCodeOptions` je nabídka k datu sazby dokladu) → `notFound` + error `vat_code_pin_invalid`; v rozporu se signály warning `vat_code_pin_conflict` (volba platí, zpráva nese odvozený kód) |

**Předmět plnění u tuzemského přenesení** (#89 D10, D11,
`tasks/vat-reverse-charge-subjects.md`). U každého položkového řádku, na
kterém **skončí** kód DPH s příznakem `reverseCharge` (odvozený, z canonicalu,
z historie řádků i zvolený; přijaté i vystavené doklady, i importní mód),
applier vezme `rows[].vat.reverseChargeCode`, ověří ho proti číselníku
`reverseChargeSubjects` k datu sazby (`VatCodeDerivation::reverseChargeSubject()`)
a uloží na řádek dokladu (`reverse_charge_code`); rekapitulace se pak skládá
per (kód, sazba, předmět). **Nic se tiše nedoplňuje** — ani v importním módu:

| efektivní kód řádku | `rows[].vat.reverseChargeCode` | výsledek |
|---|---|---|
| kód přenesení | platný k datu sazby | `_resolve.rows[].reverseChargeCode {value, source: "document", status: "matched"}`, bez issue |
| kód přenesení | chybí | `{value: null, source: null, status: "notFound"}` + error `reverse_charge_code_missing` |
| kód přenesení | mimo číselník / neplatný k datu | `{value: "<hodnota>", source: "document", status: "notFound"}` + error `reverse_charge_code_unknown` s hodnotou ve zprávě |
| jiný kód, bez kódu, doklad Bez DPH | cokoli | bez bloku, předmět se ignoruje (i z volby) |
| kód přenesení, **volba uživatele** `userAction: "useValue:<kód>"` (D12) | cokoli | zvolený předmět, `{value, source: "user", status: "matched"}`; mimo `_resolve.reverseChargeOptions` → `status: "notFound"` + error `reverse_charge_code_pin_invalid` (předmět z dokladu neplatnou volbu nenahradí) |

Volba předmětu jde stejnou mapou rozhodnutí jako kód DPH (#76, #87):
cesta `rows[i].reverseChargeCode`, akce `useValue:<kód>` — u řádku
i hromadně. `_resolve.reverseChargeOptions [{code, label}]` = předměty
platné k datu sazby dokladu, jednou na doklad a jen když má některý řádek
kód přenesení; volbu i předmět z dokladu validuje týž číselník k datu sazby
řádku (#159). Řádek se stavem `notFound` blokuje
jednoklikové použití (`reviewGate.js`).

Na doklad se propíše jen ověřený předmět. Blok `reverseChargeCode` se počítá
do `_resolve.summary` jako ostatní reference řádku.

„V souladu“ = místo kódu odpovídá `vat.place`; má `reverseVatCode` ⇔
`reverseCharge`; `supplyKind` sedí, když je na obou stranách; u tuzemska
bez samovyměření sedí sazba k datu. Null signál se nekontroluje. Kontrola
chrání i před kódem doplněným z historie řádků (`RowHistoryEnricher`),
který by jinak derivaci přebil. Kontrola dostává jen `supplyKind`
z canonicalu, ne fallback ze štítku — kód potvrzený člověkem štítek
nezpochybňuje.

**Volba uživatele** (`tasks/exchange-preview-vat-choices.md`, #87 task B):
náhled nabízí kódy z `_resolve.vatCodeOptions` — země naší registrace,
směr `input`, efektivní místo plnění, bez `hidden`, jen se sazbou platnou
k datu sazby dokladu, **včetně** kráceného odpočtu a dovozu zboží a vždy
s kódem „Mimo DPH“ jako posledním, pro každé místo plnění (položka má
`outOfScope: true` — náhled u ní nevypisuje procento, #164 U11)
(`VatCodeDerivation::options()`; táž funkce validuje volbu — u řádku
s vlastním datem sazby proti nabídce k datu řádku, #159). Volba kódu
(`rows[i].vatCode.userAction = "useCode:<kód>"`), místa plnění
(`_resolve.vat.place.userAction = "useValue:domestic|intracom|thirdCountry"`)
a režimu (`_resolve.vat.mode.userAction = "useValue:fromBase|fromTotal|none"`)
se uplatní ve stejném kontextu jako derivace: místo z volby přebije DIČ
i AI (`placeSource: "user"`, derivace místa se nespouští), kód z volby
přebije derivaci, canonical i historii, režim z volby přebije
`VatModeDerivation` (ochrana „Bez DPH“ + samovyměření → `fromBase` platí
v tuzemsku i proti volbě, tiše; mimo tuzemsko volba Bez DPH platí —
*Přijatý doklad mimo české DPH*, #89 D4). Rekapitulace (D3) i `_resolve.computed` volbu
následují. Volby platí jen ve větvi derivace (přijatý doklad na zdroji
s registrací DPH) — jinde se ignorují s info `vat_pin_ignored`; neplatná
hodnota nebo akce je error `vat_pin_invalid`. U zvolené hodnoty se nehlásí
`vat_place_derived`, `vat_mode_derived`, `vat_mode_suspect` ani
`vat_code_derived`.

**Druh plnění ze štítku řádku** (`tasks/exchange-received-supply-kind.md`
D2): přijatá faktura ze zahraničí, která DPH nezmiňuje (americký SaaS bez
DIČ, `vat.mode: none`), přijde z AI bez `supplyKind`. Když efektivní místo
≠ tuzemsko a řádek druh nemá, applier ho vezme ze štítku řádku
(`_resolve.contentTag`: výjimka `rowExceptions[]` pro index řádku, jinak
štítek dokumentu) podle atributu `crossBorderSupply` taxonomie
`core.exchange.contentTags` (`services` / `goods`; komentář v
`contentTags.jsonc`) a přidá warning `supply_kind_derived` na
`rows.N.vat.supplyKind`. Canonical se nemění — efektivní druh je jen
v kontextu a v `_resolve.rows[].vatCode.supplyKindSource: "tag"`. Bez
bloku `contentTag` (pokrytý doklad bez LLM běhu) nebo u štítku bez
atributu fallback není. Pořadí ve `vatContext()`: místo z DIČ s druhy
z canonicalu → fallback druhů → jediný doplňkový průchod derivace místa,
když fallback něco doplnil a první průchod selhal na prefixu (`XI` jen
zboží). Funguje i nad starou analýzou — `enrichFresh` blok persistuje.

Dvě pojistky proti tichému chybnému samovyměření, obě jen mimo tuzemsko:

- **dovoz zboží** (D3): třetí země + `goods` → `null` s důvodem „dovoz
  zboží — DPH se vyměřuje z celního dokladu, ne z faktury dodavatele“.
  Samovyměření dovozce (`cz-415` / `cz-405`, § 23 odst. 3) běžná firma
  nedělá, DPH platí celnímu úřadu a odpočet uplatní z JSD; `cz-415` jen
  ručně. Platí i pro `goods` ze štítku (issue `supply_kind_derived`
  i `vat_code_unknown`).
- **zvláštní místo plnění** (D4): štítek řádku s `crossBorderSupply:
  "special"` (ubytování, jízdné, stravování, nájem a služby k nemovitosti,
  mýto, parkování) → `null` s důvodem „místo plnění se řídí zvláštním
  pravidlem … — samovyměření se neodvozuje“. Veto jde před `supplyKind`
  z AI i před `reverseCharge: true` — samovyměření se neodvozuje tam, kde
  se daň v ČR nepřiznává. Kódu z historie řádků (`conflict()`) se veto
  netýká. Doklad, kde štítek nesou všechny řádky a daň na něm není,
  vznikne Bez DPH (*Přijatý doklad mimo české DPH*); chybou řádku zůstává
  veto jen u smíšeného dokladu. Stejné veto dává pokrytý řádek, jehož
  položka nese jen `special` štítky (*Zvláštní místo plnění ze štítků
  položky*, #89 D20).

Zahraniční DPH naúčtovaná dodavatelem z EU či třetí země (hotel, PHM
v cizině — `pct` > 0 bez `reverseCharge: true` není samovyměření) a veto
`special` vracejí `null` s druhem důvodu (`kind`: `foreignVat` /
`specialPlace`) — derivace o dokladu nerozhoduje, to dělá applier nad
celým dokladem (*Přijatý doklad mimo české DPH*).

Mimo rozsah (derivace vrací `null` → `vat_code_unknown`): snížená sazba
u samovyměření, PDP kódy mimo číselník, smíšené doklady, druh plnění
z textu řádku bez štítku. Vystavené a účetní doklady derivaci
nepoužívají.

### 8.5 BankAccountResolver

Vstup: `Party.bankAccount` object + resolved `personId` (pokud existuje).

Postup pro **partner's bank** (resolved person je partner, ne my),
#118 D16:

1. Načíst aktivní účty osoby (`base_persons_bank_accounts` podle
   `person`) a porovnat v PHP přes **klíč účtu**
   (`World\Validations\BankAccountKey::of()`, od fáze 2 v knihovně) z **obou** sloupců — u starých dat
   nese `account_number` často IBAN. Klíč: IBAN státu s tuzemským tvarem
   účtu (cz, sk) i tuzemské číslo `[předčíslí-]číslo/kód` → `bban:<20
   číslic>` (BBAN je u cz i sk stejný, země není potřeba); jiný IBAN →
   `iban:<IBAN>`; jinak `raw:<hodnota>`. Nezávisí na platnosti
   kontrolních součtů. IBAN z dokladu má přednost (`matchedBy: iban`),
   pak číslo účtu (`accountNumber`).
2. Žádný match, ale partner existuje → `canCreate` (přidat účet
   partnerovi; payload nese normalizované a případně opravené hodnoty
   z canonicalu).
3. Partner ještě neexistuje (sám `canCreate`) → odložit do Apply fáze.

Postup pro **vlastní účet** (`resolveOwnBank`, dnes bez produkčního
volajícího — applier bere vlastní účet referencí `payment.ownBankAccount`):

1. Načíst aktivní účty z `economy_codebooks_bank_accounts` a porovnat
   stejným klíčem.
2. Žádný match → `notFound` (vlastní účet musí být v codebooks, applier
   ho nevytváří automaticky).

### 8.6 Reference přirozeným klíčem (`ReferenceResolver`)

Obecné hledání záznamu podle přirozeného klíče (#166 D8–D10, D15). Který
sloupec je klíč, říká **definice cílové tabulky** (`exchangeKey`,
`exchangeLookupColumns` — `docs/table-definitions.md` § 4); resolver ho jen
najde. Třídy `ReferenceValue` (rozbor hodnoty, bez databáze),
`ReferenceResolver` a `ReferenceException` v `modules/core/exchange/src/Resolve/`.

> **Formát dokladu tuto gramatiku používá od etapy 3** (#166 D11–D14,
> D30–D39, `tasks/doc-format-references.md`): každý odkaz na záznam
> v `shpd.docs.document.v1` je jedno pole s referencí — seznam míst
> a pravidla pokynu × návrhu jsou v § 5 „Reference na záznamy“. Datové
> sady (`SetupReferences`) v sekci setup dál píšou holou hodnotu klíče bez
> `@`; dimenze dokladů (`DimensionResolver`) berou referenci.

**Číselná řada dokladů a registrace DPH mají vlastní kód** (#166 D16, D17;
`tasks/number-series-vat-registration-codes.md`): sloupec `code`
s unikátním indexem je jejich `exchangeKey`, takže se odkazují stejnou
gramatikou jako ostatní číselníky — řada `"@invno"`, `"@cash-POK"`,
registrace `"@cz"`. Kód je nezávislý na `doc_number_code` řady (ten se
propisuje do čísla dokladu jako `%C`). Tvar kódu hlídá
`Shipard\Core\Database\RecordCode`: písmena bez diakritiky, číslice, tečka,
podtržítko a pomlčka, první znak písmeno nebo číslice — **bez mezer, `@`
a dvojtečky**, aby kód šel zapsat do reference a nekolidoval s pravidlem
dvojtečky. Ve formátu dokladu je nesou pole `numberSeries`
a `vat.registration` (§ 5).

**Gramatika hodnoty reference** (D9):

| Hodnota v JSON | Význam |
|---|---|
| `42` (celé číslo > 0) | primární klíč záznamu |
| `"@K00042"` | přirozený klíč cílové tabulky (`exchangeKey`) |
| `"@company_id:12345678"` | jmenovaný sloupec — jen `exchangeKey` nebo sloupec z `exchangeLookupColumns` |
| cokoli jiného | chyba `reference_invalid`: `"42"`, `"K00042"`, `0`, `1.5`, `true`, `null`, pole, `"@"` |

**Pravidlo dvojtečky** (D15): část před **první** dvojtečkou je sloupec jen
tehdy, když jde o `exchangeKey` nebo deklarovaný sloupec pro hledání; jinak
je celý text za `@` hodnota přirozeného klíče. `"@A:1"` tedy hledá klíč
`A:1`, `"@vat_id:CZ:1"` hledá ve sloupci `vat_id` hodnotu `CZ:1`. Prázdná
hodnota za deklarovaným sloupcem (`"@company_id:"`) je chyba.

**Hledání** (D10):

- přesná shoda v collation sloupce; hodnota se **neořezává** a nemění se
  velikost písmen — `"0123"` a `"123"` jsou různé klíče;
- právě jeden živý záznam. Žádný = `reference_not_found`, víc než jeden =
  `reference_ambiguous` (zpráva nese počet a id záznamů) — nikdy první
  nalezený;
- záznam v koši (`docState` 90, jen u tabulky s `docStates`) se nepočítá,
  ani při hledání primárním klíčem; archivovaný ano;
- tabulka i sloupec jdou do SQL jen z definice tabulky, hodnota parametrem.

**Rozhraní** `ReferenceResolver(Connection, definice aktivních tabulek)`:

| Metoda | Použití |
|---|---|
| `resolve(tabulka, hodnota)` | hodnota podle gramatiky výše |
| `resolveByKey(tabulka, hodnota)` | holá hodnota přirozeného klíče (datové sady, dimenze) |
| `resolveByColumn(tabulka, sloupec, hodnota)` | deklarovaný sloupec pro hledání |
| `keyOf(tabulka, id)` | přirozený klíč záznamu pro export; `null` = záznam není nebo má klíč prázdný (koš se nefiltruje) |
| `hasKey(tabulka)` / `keyColumn(tabulka)` | má tabulka přirozený klíč a který sloupec to je |

**Chyby** — `ReferenceException` s kódem (`errorCode`), tabulkou, sloupcem,
hodnotou a u nejednoznačnosti seznamem id; volající si z nich skládá vlastní
znění (datové sady `DatasetException`). Formát dokladu kód výjimky přebírá
jako kód nálezu s cestou pole (`DocumentApplier::referenceIssue()`):

| Kód | Kdy |
|---|---|
| `reference_invalid` | hodnota nemá tvar reference, nebo je prázdná |
| `reference_table_unknown` | tabulka na zdroji dat není aktivní |
| `reference_no_key` | tabulka nemá `exchangeKey` — záznam jde odkázat jen `id` |
| `reference_column_not_allowed` | sloupec tabulka pro hledání nedeklaruje |
| `reference_not_found` | žádný živý záznam |
| `reference_ambiguous` | víc živých záznamů se stejnou hodnotou |

## 9. `_resolve` state

Vyrábí ho `/preview`, čte `/apply`. Žije v stejném JSON dokumentu jako data —
klient drží jeden payload mezi step preview a apply.

**Blok `_resolve` z payloadu čte jen návrh** (#166 D32) — pokyn ho zahodí
celý a ohlásí info `resolve_ignored`. Výstupní bloky jsou v obou režimech stejné: záznam určený
referencí z payloadu má `status: "matched"`, `matchedBy: "ref"`; reference,
která se nenašla, `status: "notFound"` s příznakem `referenceError: true`
(chybu nese nález v `issues`). Řádek obohacený z historie nese položku jako
`item.ref`, takže i její blok má `matchedBy: "ref"` — zdroj napárování
říká `enrichment.matchedBy`.

```jsonc
{
  "summary": {
    "status":          "needsAttention",  // ok | needsAttention | hasErrors
    "matchedCount":    8,
    "unresolvedCount": 1,
    "ambiguousCount":  0,
    "errorCount":      0
  },

  // Per-reference resolve výsledky
  "supplier": {
    "status":     "matched",              // matched | ambiguous | notFound | canCreate
    "personId":   42,
    "matchedBy":  "companyId",
    "candidates": [],                     // pouze pro "ambiguous"
    "userAction": null                    // null | "useExisting:<id>"
                                          //   | "create" | "skip"
  },
  "customer": {
    "status":    "matched",
    "personId":  1,
    "matchedBy": "selfParty"
  },

  "supplierBank": {
    "status":     "canCreate",
    "candidates": [],
    "userAction": null                    // "create" attaches to resolved
                                          //   supplier.personId
  },

  "rows": [
    {
      "index": 0,
      "rowText": "Konzultace — Hodinová sazba senior konzultanta",
                                          // text řádku, jak ho zapíše applier
                                          //   (CanonicalRowText, §7); u každého
                                          //   řádku, bez textu null
      "item": {
        "status": "matched", "matchedId": 18, "matchedBy": "ourCode",
        "createPayload": { "name": "Toner černý", "description": "" },
                                          // i u matched — předvyplnění
                                          //   „Vytvořit novou položku“ (#111)
        "display": { "id": 18, "code": "SPOTR-TON", "name": "Tonery a náplně",
                     "pinned": false },   // efektivní položka (jen /preview,
                                          //   #111 D3): volba useExisting
                                          //   > napárování; pinned = z volby
        "matchedDisplay": { "id": 18, "code": "SPOTR-TON",
                            "name": "Tonery a náplně" },
                                          // automatické napárování nezávisle
                                          //   na volbě (jen /preview, #111 D10)
        "userAction": null                // "useExisting:<id>" platí i nad
                                          //   matched — přebije napárování
      },
      // Účet, podle kterého se řádek zaúčtuje (jen /preview, #111 D7b);
      // null = sloupec Účet ukáže „—“. source: item (účetní položka) |
      // row (kontační řádek, nebo návrh účtu řádku bez efektivní
      // položky).
      "effectiveAccount": { "id": 412, "number": "501300",
                            "name": "Spotřeba materiálu", "source": "item" },
      "unit":     { "status": "matched", "unitId": 3, "matchedBy": "iso" },
      "vatCode":  { "status": "matched", "code": "cz-110",
                    "userAction": null }  // "useCode:<kód>" = volba uživatele
                                          //   (#87 B) → matchedBy "user";
                                          //   matchedBy "derived", když kód
                                          //   odvodil applier (§ 8.4);
                                          //   supplyKindSource "tag", když
                                          //   druh plnění doplnil ze štítku
                                          //   specialSource "tag" | "item" =
                                          //   odkud je zvláštní místo plnění
                                          //   řádku (§ 8.4, #89 D19)
                                          //   matchedBy "outOfScope" bez kódu
                                          //   = doklad mimo české DPH (§ 8.4)
    },
    {
      "index": 1,
      "item": {
        "status": "canCreate",
        "candidates": [],
        "userAction": null
      }
    }
  ],

  // Efektivní hlavička DPH (#87 B, D14) — náhled zobrazuje tohle, ne
  // canonical. source: ai | vatId | user | derived | default (canonical
  // hodnotu nenese, platí výchozí applieru) | nonPayer (jen mode: přijatý
  // doklad neplátce DPH, vždy "none" — § 8.4) | outOfScope (jen mode:
  // doklad mimo české DPH, vždy "none", navíc reason "foreignVat" |
  // "specialPlace" | "user" | "supplierNonPayer" — § 8.4). auto = hodnota bez volby
  // uživatele (select „Automaticky (…)“ ji ukazuje i po volbě). Volba:
  // userAction "useValue:<hodnota>" na vat.place / vat.mode (vstup; v
  // odpovědi není).
  "vat": {
    "place": { "value": "domestic", "source": "user",    "auto": "intracom" },
    "mode":  { "value": "fromBase", "source": "derived", "auto": "fromBase" }
  },

  // Nabídka kódů DPH pro ruční volbu řádků (#87 B, D13) — jen přijatý
  // doklad na zdroji s registrací DPH; kandidáti k efektivnímu místu
  // (po volbě) a DUZP. Mimo tuto větev blok chybí. Poslední je vždy
  // „Mimo DPH“ (outOfScope: true — bez procenta v náhledu, #164).
  "vatCodeOptions": [
    { "code": "cz-217", "label": "EU/Vstup/Služby/Základní", "pct": 21,
      "reverseCharge": true, "reducedDeduction": false, "supplyKind": "services", "outOfScope": false },
    { "code": "cz-218", "label": "EU/Vstup/Služby/Snížená",  "pct": 12,
      "reverseCharge": true, "reducedDeduction": false, "supplyKind": "services", "outOfScope": false },
    { "code": "cz-000", "label": "Mimo DPH", "pct": 0,
      "reverseCharge": false, "reducedDeduction": false, "supplyKind": null, "outOfScope": true }
  ],

  // Opravný doklad (#92 task B): výsledek dohledání původního dokladu
  // (§ 5 „Opravný daňový doklad“) — jen při bloku `correction` na typu
  // s příznakem. Vstup: userAction "useExisting:<id>" = odkaz přímo.
  "correction": {
    "status":       "matched",            // matched | notFound | ambiguous | none
    "matchedId":    77,
    "matchedBy":    "number",             // pin | number
    "docNumber":    "FV26-00123",
    "taxPointDate": "2026-03-15"
  },

  // Rekapitulace a součty, které skončí na dokladu — jen z /preview,
  // spočítané DocDocument::computeAmounts() nad transform() canonicalu
  // (stejný kód jako uložení; tasks/exchange-preview-vat-recompute.md).
  // V měně dokladu, domácí měna se nevrací. null = výpočet selhal
  // (info issue computed_unavailable), klient ukáže canonical.
  "computed": {
    "recapSource":   "declared",        // declared | computed — co doklad skutečně použil
    "recapFallback": null,              // důvod přepočtu (resolveRecapSource), jinak null
    "vatRecap": [
      { "vatCode": "cz-110", "vatPct": 21, "base": 10330.58, "tax": 2169.42,
        "total": 12500.00, "isReversePair": false }
    ],
    "totals": { "totalBase": 10330.58, "totalVat": 2169.42,
                "totalAmount": 12500.00, "totalRounding": 0.00 },
    // Ceny položkových řádků spočítané dokladem; index = index řádku
    // v canonicalu (ne pořadí na dokladu). U neplátce DPH včetně daně
    // dodavatele.
    "rows": [
      { "index": 0, "unitPrice": 1033.06, "totalPrice": 10330.60 }
    ]
  },

  // Validation & sanity findings — chyby i warningy
  "issues": [
    {
      "severity": "warning",            // "error" | "warning" | "info"
      "path":     "totals.totalAmount",
      "code":     "computed_total_mismatch",
      "message":  "Částka k úhradě na dokladu dodavatele 12500 se liší od částky, která skončí na dokladu (12499.5) — …",
      "declared": 12500.00,
      "computed": 12499.50
    },
    {
      "severity": "error",
      "path":     "dates.issueDate",
      "code":     "required",
      "message":  "Datum vystavení je povinné."
    }
  ]
}
```

### Audit bloky navíc (additionalProperties)

`_resolve` má ve schématu `additionalProperties: true` — audit vrstvy si
do něj přidávají vlastní bloky bez změny schématu:

- `_resolve.rows[i].rowText` — text řádku složený serverem
  (`CanonicalRowText`, §7 „Text řádku"); informativní, počítá ho `/preview`
  i `/apply` znovu, klient ho jen zobrazuje (review modal).
- `_resolve.rows[i].enrichment` — obohacení řádku z historie partnera
  nebo obsahové eskalace (viz `modules/core/mail/docs/ai-analysis.md`,
  sekce „Obohacení řádků z historie" a „Obsahová eskalace").
- `_resolve.rows[i].item.display` — efektivní položka řádku `{id, code,
  name, pinned}` (#111 D3), jen `/preview`: uložená volba `useExisting:<id>`
  (`pinned: true`) má přednost před automatickým napárováním (`status:
  matched`, `pinned: false`). U `skip`, nerozhodnutého nenapárovaného
  řádku a volby na neexistující položku klíč chybí.
  Review modal z něj kreslí druhý řádek buňky Položka.
- `_resolve.rows[i].item.matchedDisplay` — automaticky napárovaná položka
  `{id, code, name}` u každého bloku `status: matched`, nezávisle na volbě
  uživatele (#111 D10), jen `/preview`. Po ruční volbě z ní panel kreslí
  „Napárováno automaticky: …“ s názvem a zdrojem; `display` dál nese
  efektivní položku. Smazaná položka klíč nemá (klient ukáže `#id`).
- `_resolve.rows[i].effectiveAccount` — účet, podle kterého se řádek
  zaúčtuje, nebo návrh účtu řádku, `{id, number, name, source}` | `null`
  (#111 D7b), jen `/preview`, klíč na každém řádku. `skip` → `null`;
  kontační řádek (`accSide`) → účet řádku (`source: "row"`);
  efektivní položka → její `accounting_account`, jen u účetní položky
  (`item_type` 2; `source: "item"`), jinak `null` — služba a zásoba se
  účtují maskou kategorie a účet doplněný historií nebo štítkem se na
  řádek s ručně zvolenou položkou nezapíše; bez efektivní položky → účet
  řádku jako návrh (`source: "row"`) — účet z extrakce nebo kontace,
  obsahový štítek bez položky účet nenavrhuje (#36 D3). Bez extension
  `economy.accounting` je účet z položky vždy `null`.
- `_resolve.contentTag` — dokument-level obsahový štítek
  (`{tag, tagSource: "rule"|"llm", ruleId? | tagConfidence?,
  promptVersion?, rowExceptions?}`), persistuje se při `/result`,
  fresh re-check pravidla IČO ho může přepsat
  (`tasks/content-tag-enrichment.md`).
- `_resolve.vat` — efektivní místo plnění a režim DPH `{value, source,
  auto}` (#87 task B, D14; `/preview` i `/apply`). Náhled zobrazuje tyto
  hodnoty a jejich zdroj (z AI, z DIČ, odvozeno, zvoleno, výchozí);
  `auto` je hodnota bez volby uživatele pro položku „Automaticky (…)“;
  canonical `vat.*` zůstává vstupem.
- `_resolve.reverseChargeOptions` — nabídka předmětů plnění u tuzemského
  přenesení daňové povinnosti (#89 D12): `[{code, label}]`, předměty
  číselníku platné k datu sazby dokladu; jen když má některý řádek blok
  `reverseChargeCode`. `useValue:` u `rows[i].reverseChargeCode` validuje
  týž číselník k datu sazby řádku (`reverse_charge_code_pin_invalid`).
- `_resolve.vatCodeOptions` — nabídka kódů DPH pro ruční volbu řádků
  (#87 task B, D13), jen přijatý doklad na zdroji s registrací DPH.
  Kandidáti k efektivnímu místu a DUZP; stejná množina, proti které
  applier validuje `useCode:` (`vat_code_pin_invalid`).
- `_resolve.computed` — rekapitulace DPH, součty a ceny řádků, **které
  skončí na dokladu** (`{recapSource, recapFallback, vatRecap[], totals,
  rows[]}`, tvar v příkladu výše). `rows[]` = `{index, unitPrice,
  totalPrice}` položkových řádků podle **indexu canonicalu** (#97) — cena
  za jednotku a cena řádku po slevě, jak je spočítal doklad; u přijatého
  dokladu neplátce DPH včetně daně dodavatele. Náhled je zobrazuje místo
  cen z canonicalu. Řádky vynechané volbou `skip` (řádková i `rows[i].item`)
  v `rows[]`, rekapitulaci ani součtech nejsou — plán náhledu nese
  `rowSkips` stejně jako apply (#111 D9) a rekapitulace se přepočítá
  z řádků s důvodem „vynechaný řádek“ (D12, § 8). Jen `/preview`:
  `transform()` s náhledovým plánem (kódy DPH a jednotky z čerstvého
  resolve, vynechané řádky, bez založených entit a řady) →
  `TableGateway::createDocument()` → `DocDocument::computeAmounts()`
  — stejný kód jako `beforeSave()` při apply, včetně přetížení podtříd
  (účetní doklad sčítá z řádků, `vatRecap` prázdné). Při výjimce `null`
  + info `computed_unavailable`. Náhled z něj zobrazuje rekapitulaci,
  součty a sazbu řádků (`_resolve.rows[i].vatCode.createPayload`);
  canonical `vatRecap` / `totals` jen jako fallback a při
  `computed_total_mismatch` jako „na dokladu dodavatele“
  (`tasks/exchange-preview-vat-recompute.md`).
- `_resolve.valueRepairs` — auditní blok oprav hodnot z redundance
  (#118 D4, D14): `[{path, from, to, basis}]`, `basis` `accountNumber`
  (IBAN dopočtený z čísla účtu) | `iban` (číslo účtu dopočtené z IBAN) —
  zdrojem je jen hodnota, která projde vším včetně mod 11; z podezřelého
  čísla (`account_number_checksum`, D30) se nic nedopočítává. Zapisuje
  ho normalizátor canonicalu
  (`Values\CanonicalValueNormalizer`) při `/result`, sběru ISDOC
  i v každém volání applieru — idempotentně: existující záznamy
  zachová, stejný nepřidá; `withResolve()` ho přenáší do čerstvého
  `_resolve`, takže přežije `/preview` i `/apply`. Validátor z něj dělá
  warning `bank_account_repaired` a strop pásma návrhu ho vidí jako
  nález (§ 10 → Normalizace a validace hodnot).

### `userAction` slovník

| Hodnota | Význam |
|---------|--------|
| `null` | Default — applier použije resolved match. Pokud `status == "matched"`, OK; jinak chyba (`unresolved_required`). |
| `"useExisting:<id>"` | Použít konkrétní existující záznam — kandidáta z `candidates`, výsledek hledání, nebo jinou položku než napárovanou: u `rows[i].item` platí i nad `status == "matched"` a automatické napárování přebije (#111 D4). |
| `"create"` | Vytvořit novou entitu z payloadu (jen pro `canCreate`). |
| `"skip"` | Vynechat řádek — `rows[i].item` („Vynechat řádek“ v review) nebo řádková `rows[i].userAction`; řádek se na doklad nezapíše a v `/preview` chybí v `_resolve.computed` (#111 D9). Pro hlavičkové reference je default `null`. |
| `"useCode:<kód>"` | Jen `rows[i].vatCode` (#87 task B): zvolený kód DPH řádku z `_resolve.vatCodeOptions`. |
| `"useValue:<hodnota>"` | `_resolve.vat.place` (`domestic` / `intracom` / `thirdCountry`) a `_resolve.vat.mode` (`fromBase` / `fromTotal` / `none`) (#87 task B); `rows[i].reverseChargeCode` — předmět plnění řádku u tuzemského přenesení z `_resolve.reverseChargeOptions` (#89 D12). |

Klient vyplňuje `userAction` mezi `/preview` a `/apply`. `/apply` aktion
zvalidnuje a buď uloží, nebo vrátí chybu se seznamem nerozhodnutých referencí.

**Volby platí jen v návrhu** (#166 D32). Pokyn `_resolve` nečte vůbec —
reference určuje payload (§ 5 „Reference na záznamy“); payload s blokem
projde a dostane jeden info nález `resolve_ignored`. V návrhu
volba `useExisting` přebíjí i položku, kterou řádku zapsalo obohacení
(`item.ref`); nenalezený `ref` z payloadu volbou přebít nejde. Volby
`_resolve.partner` a `_resolve.rows[i].partner` (`useExisting:<id>`) určují
v návrhu partnera účetního a pokladního dokladu, když payload pole
`partner` / `rows[].partner` nenese; uvedené pole má přednost. Volba na
neexistující nebo smazanou osobu je warning `pin_target_missing`.

### Issue codes — dokumenty

Kódy v `_resolve.issues[]` (`DocumentValidator` + `DocumentApplier`).
Errors blokují `/apply`, warningy jen informují v UI.

**Jen v režimu návrh** (§ 3, #166 D19) vznikají: `totals_mismatch`,
`rows_recap_mismatch`, `vat_recap_inconsistent`, `vat_mode_suspect`,
`vat_mode_derived`, `recap_source_computed_fallback`, `vat_code_derived`,
`vat_code_pin_invalid`, `vat_code_pin_conflict`, `vat_pin_invalid`,
`reverse_charge_code_pin_invalid`, `supply_kind_derived`,
`vat_registration_country_derived`, `vat_place_derived`, `vat_non_payer`,
`vat_foreign_tax`, `vat_special_place`, `vat_special_place_ambiguous`,
`vat_out_of_scope_user`, `vat_supplier_non_payer`,
`non_payer_reverse_charge`, `bank_account_repaired`
a `computed_total_mismatch`. **Jen v pokynu:** `vat_recap_missing`,
`vat_recap_code_missing`, `totals_check_failed`,
`total_rounding_mode_ignored`, `document_validation_unavailable` a nálezy
dokladu v `/preview`.

**Nálezy dokladu** (#166 D21): chyby a varování `DocDocument::validate()`
nesou kód dokladu (`vat_code_missing`, `vat_rate_missing`,
`correction_reason_required`, `required`, `no_rows`, `no_own_company`, …
— `docs/vat-calculation.md`, `docs/document-system.md`) a `path` přeložený
ze sloupce na pole canonicalu tam, kde je vazba jednoznačná
(`DocumentIssuePaths`: `rows.2.vat_code` → `rows.2.vat.code`, `issue_date`
→ `dates.issueDate`, `partner` → `supplier` / `customer`,
`correction_reason` → `correction.reason`, …); jinak zůstává sloupec
(`number_series`, `_form`). Index řádku je index canonicalu. Do issues se
dostanou z odmítnutého `/apply` (oba režimy), z úspěšného `/apply` pokynu
(varování) a z `/preview` pokynu.

| `code` | Severity | Význam |
|--------|----------|--------|
| `required` | error | Chybí povinné pole per `docType` (issueDate, rows, supplier/customer). |
| `author_not_found` | error | `applyOptions.author` není id existujícího uživatele (#93 D9). `null` a chybějící klíč se nekontrolují. |
| `totals_mismatch` | warning | Deklarovaná `totals.totalAmount` neodpovídá žádné vypočtené variantě (Σ řádků, Σ řádků s DPH, Σ recap). Heuristika validátoru; v `/preview` ji při dostupném `_resolve.computed` nahrazuje `computed_total_mismatch`. |
| `computed_total_mismatch` | warning | Jen `/preview` návrhu: částka k úhradě podle skutečného výpočtu dokladu (`_resolve.computed.totals.totalAmount`) se od `totals.totalAmount` liší o víc než 0,01. Nese `declared` a `computed`; zpráva příčinu nehádá, jen vyzve ke kontrole řádků a režimu DPH. U samovyměření se liší daň, k úhradě sedí — warning nepadne. |
| `totals_check_failed` | error | Jen pokyn (#166 D27): uvedené pole `totals` (`totalBase`, `totalVat`, `totalAmount`, `totalRounding`) se od hodnoty spočtené dokladem liší o víc než 0,01. Jeden nález za pole, `path` = `totals.<pole>`, nese `declared` a `computed`. `/apply` končí 422 `totals_check_failed` a nic se neuloží; `/preview` hlásí totéž nad `_resolve.computed`. § 5 „Pole `vatRecap` a `totals`“. |
| `total_rounding_mode_ignored` | warning | Jen pokyn (#166 D26): doklad má zapnuté automatické zaokrouhlení a `totals.totalRoundingMode` uvádí jiný mód, než jaký dal způsob úhrady. Platí automatika; pro vlastní mód `totalRoundingAuto: false`. `path` = `totals.totalRoundingMode`. |
| `computed_unavailable` | info | Jen `/preview`: výpočet `_resolve.computed` selhal výjimkou (zalogováno) — blok je `null`, náhled ukazuje údaje z canonicalu. |
| `rows_recap_mismatch` | warning | Součet položkových řádků neodpovídá rekapitulaci/totals dle efektivního režimu DPH — řádky nejspíš neúplné. |
| `vat_recap_inconsistent` | warning | Řádek rekapitulace vnitřně nesedí (`base + tax ≠ total` nebo `tax ≠ base × pct`) — recap dopočtený místo opsaného. |
| `vat_mode_derived` | warning | `DocumentApplier` koriguje `vat_mode` podle `VatModeDerivation` (Σ řádků sedí na total, ne na base — nebo zrcadlově); nebo deklarované `none` u dokladu, jehož řádky mají kód se samovyměřením → `fromBase` (Bez DPH by rekapitulaci nestavěl). |
| `recap_source_computed_fallback` | info | Rekapitulaci nešlo převzít (prázdná, nekonzistentní, bez dohledatelného DPH kódu, vynechaný řádek) — spočítá se z řádků; zpráva nese důvod. U samovyměření (D3) vlastní text: dodavatel uvádí 0 % DPH, daň přiznáváte vy, rekapitulace se sestaví z řádků (daň na výstupu a nárok na odpočet). |
| `vat_code_unknown` | error | Kód DPH řádku není v číselníku země registrace a nejde odvodit ze signálů (§ 8.4). V pokynu se neodvozuje: neznámý kód řádku (`rows.N.vat.code`) nebo řádku převzaté rekapitulace (`vatRecap.N.vatCode`) je chyba vždy. Zpráva nese důvod; blokuje apply. U dokladu mimo české DPH (`vat_foreign_tax` / `vat_special_place` / `vat_out_of_scope_user`) se nehlásí. U zahraniční DPH a zvláštního místa plnění, které k dokladu mimo DPH nevedly (smíšený doklad, zvolený režim s DPH), zpráva radí kód u řádku nebo režim Bez DPH. |
| `vat_code_derived` | warning | Kód DPH řádku byl neznámý nebo v rozporu se signály dokladu — nahrazen odvozeným (`VatCodeDerivation`). Zpráva nese původní hodnotu. |
| `reverse_charge_code_missing` | error | Řádek (path `rows.N.vat.reverseChargeCode`) nebo řádek převzaté rekapitulace (`vatRecap.N.reverseChargeCode`) s kódem tuzemského přenesení daňové povinnosti nemá předmět plnění. Blokuje apply i v importním módu — výchozí hodnota neexistuje (#89 D10). |
| `reverse_charge_code_unknown` | error | Předmět plnění není v číselníku `reverseChargeSubjects` nebo k datu sazby dokladu neplatí; zpráva nese hodnotu. Blokuje apply i v importním módu (#89 D10). |
| `reverse_charge_code_pin_invalid` | error | Zvolený předmět plnění řádku (`useValue:`) není v `_resolve.reverseChargeOptions` k datu sazby — blokuje apply; vyber jiný (#89 D12). |
| `vat_code_pin_invalid` | error | Zvolený kód DPH řádku (`useCode:`) není v nabídce kódů k efektivnímu místu a **datu sazby řádku** (`_resolve.vatCodeOptions` je nabídka k datu sazby dokladu; u řádku s vlastním datem se ověřuje nabídka k jeho datu, #159) — blokuje apply; vyber jiný (#87 B). |
| `vat_code_pin_conflict` | warning | Zvolený kód DPH řádku odporuje signálům dokladu (`VatCodeDerivation::conflict()`); volba platí, zpráva nese důvod a odvozený kód (#87 B). |
| `vat_pin_invalid` | error | Neplatná hodnota nebo akce volby DPH (`useValue:` mimo enum, `useCode:` bez kódu, jiná akce — i u předmětu plnění řádku, kde se čeká `useValue:<kód>`); path dle volby (#87 B, #89 D12). |
| `vat_pin_ignored` | info | Volba DPH mimo větev derivace (vystavený doklad, přijatý doklad neplátce DPH) — ignorována; path dle volby (#87 B). Také volba kódu řádku u dokladu se zvoleným režimem Bez DPH mimo tuzemsko (#89 D4). Jen návrh — pokyn `_resolve` nečte a hlásí `resolve_ignored`. |
| `resolve_ignored` | info | Jen pokyn: payload nese blok `_resolve` — pokyn ho celý ignoruje (volby DPH, položek, partnerů i vynechání řádků; #166 D32). Jeden nález na payload, path `_resolve`. |
| `vat_recap_missing` | error | Jen pokyn: `vat.recapSource: "declared"` bez řádků `vatRecap` (#166 D20). Path `vatRecap`. |
| `vat_recap_code_missing` | error | Jen pokyn: řádek převzaté rekapitulace nemá kód DPH — kód se z řádků nedohledává (#166 D20). Path `vatRecap.N.vatCode`. |
| `import_requires_instruction` | error | `applyOptions.importNumber` v režimu návrh (REST s `applyOptions.interpret: true`): import přenáší hotový doklad, jde jen pokynem (#166 D22). Path `applyOptions.importNumber`. |
| `document_validation_unavailable` | info | Jen `/preview` pokynu: validaci dokladu pro cílový stav nešlo spustit (výjimka, zalogováno) — nálezy dokladu ukáže až uložení. |
| `vat_rate_date_ignored` | info | Vlastní datum sazby DPH mimo svůj režim (#159 D11): `dates.vatRateDate` při `vat.rateSource` ≠ `document`, `rows[].vat.rateDate` nebo `vatRecap[].rateDate` při `rateSource` ≠ `rows`. Jedno na doklad, `path` = první výskyt. Applier s datem nepočítá, dokument ho při uložení smaže. |
| `supply_kind_derived` | warning | Řádek přijatého dokladu mimo tuzemsko bez `vat.supplyKind` — druh plnění doplněn ze štítku řádku (`crossBorderSupply` taxonomie, § 8.4). Zpráva nese název štítku a druh; path `rows.N.vat.supplyKind`. Hlásí se jen, když na doplněném druhu výsledek stojí (odvozený kód nebo `vat_code_unknown`). |
| `vat_registration_country_derived` | info | `vat.registrationCountry` přijatého dokladu neodpovídá naší registraci — nepoužije se (D2). |
| `vat_place_derived` | warning | Místo plnění přijatého dokladu odvozené z prefixu DIČ dodavatele (`VatPlaceDerivation`) se liší od neprázdné hodnoty `vat.place` z AI — použije se odvozené. Zpráva nese obě hodnoty a prefix. |
| `vat_place_unknown` / `vat_mode_unknown` | warning | Neznámá hodnota `vat.place` / `vat.mode` — fallback tuzemsko / fromBase. Schéma má obě pole jako enum, takže jen mimo schema validaci. |
| `vat_mode_suspect` | warning | Řádky vypadají jako ceny s DPH při deklarovaném `fromBase`, ale derivace nemá dost dat na korekci. |
| `vat_non_payer` | info | Přijatý doklad, ke kterému neplatí žádná registrace DPH, a doklad nese daň dodavatele: vznikne Bez DPH s daní v cenách řádků (§ 8.4, #97). Path `vat.mode`. |
| `vat_foreign_tax` | info | Přijatý doklad od dodavatele mimo tuzemsko, který účtuje vlastní DPH: vznikne Bez DPH s daní dodavatele v cenách řádků (§ 8.4, #89 D2). Path `vat.mode`. |
| `vat_special_place` | warning | Přijatý doklad od dodavatele mimo tuzemsko bez daně, všechny řádky se zvláštním místem plnění — štítkem z bloku, nebo štítky položky pokrytého řádku (D19, D20; zpráva pak položku jmenuje): vznikne Bez DPH; volbou kódu DPH u řádku se vrátí samovyměření (§ 8.4, #89 D3). Path `vat.mode`. Apply neblokuje; karta návrhu ve feedu má kvůli němu strop pásma na *review* (`docs/dashboard.md` § 5.1, D17) a `mail_draft_document` ho vrací ve `warnings` (D18). |
| `vat_special_place_ambiguous` | warning | Řádek přijatého dokladu od dodavatele mimo tuzemsko bez daně dodavatele, pokrytý položkou, jejíž štítky místo plnění neurčují jednoznačně (`special` i jiná hodnota), s **odvozeným** kódem samovyměření (§ 8.4, #89 D21). Zpráva jmenuje položku a radí režim Bez DPH, pokud se daň v ČR nepřiznává. Path `rows.N.vat.code`. Apply neblokuje; řádek s ponechaným kódem ani s volbou uživatele (kód řádku, režim) ho nedostane. Karta návrhu má strop pásma *review*, když takový řádek nemá kód v canonicalu (`docs/dashboard.md` § 5.1). |
| `vat_out_of_scope_user` | warning | Uživatel zvolil režim Bez DPH u přijatého dokladu od dodavatele mimo tuzemsko: doklad vznikne bez DPH, daň se v ČR nepřizná (§ 8.4, #89 D4). Path `vat.mode`. Apply neblokuje. |
| `vat_supplier_non_payer` | info | Přijatý doklad od neplátce DPH v tuzemsku (režim Bez DPH z dokladu nebo z volby, bez daně a bez přenesení): vznikne Bez DPH, řádky bez kódu DPH, bez rekapitulace, ceny řádků beze změny (§ 8.4, #164 D5a). Path `vat.mode`. |
| `non_payer_reverse_charge` | warning | Přijatý doklad neplátce nese přenesení daňové povinnosti — daň se nevyměří; identifikovaná osoba ji musí přiznat mimo doklad (#97 D9). Path `vat.reverseCharge`. |
| `partner_doc_number_missing` | warning | Přijatá faktura cílí na stav ≥ 20 bez čísla dokladu dodavatele. |
| `row_operation_config_invalid` | warning | Pohyb řádku nejde doplnit — chybná konfigurace rowOperations. |
| `invalid_value` | error | `cashDirection` pokladního dokladu není 1 ani 2. |
| `dimension_unknown` | error | Klíč objektu `dimensions` není id deklarované dimenze deníku, nebo cílová tabulka dimenze nemá `exchangeKey` (#110 T2, #166 D8). Path `dimensions.<id>` / `rows.N.dimensions.<id>`. Kontroluje `DocumentValidator` se znalostí sady dimenzí (apply, /validate); preflight sady bez DS ho nehlásí. |
| `reference_not_found` / `reference_ambiguous` / `reference_invalid` / `reference_no_key` / `reference_column_not_allowed` / `reference_table_unknown` | error | Reference formátu dokladu (#166 D30, § 5 „Reference na záznamy“) se nenašla, není jednoznačná, nemá tvar reference, nebo cílová tabulka hledání neumí — kód resolveru (§ 8.6), path = pole s referencí (`supplier`, `rows.N.item`, `numberSeries`, `payment.ownBankAccount`, `cashDesk`, `dimensions.<id>`, `rows.N.account`, `vat.registration`, `correction.originalDoc`). Oba režimy; odpověď 422 `validation_failed`, nic se neuloží. U dimenze nese zpráva její název. |
| `reference_missing` | error / warning | Jen pokyn: strana nebo položka jako objekt bez `ref`, ale s názvem / IČO / DIČ / kódem — pokyn podle údajů nehledá a nic nezakládá (#166 D31). Path `supplier` / `customer` / `balanceParty` / `rows.N.item`. Partner (`partner`, `rows.N.partner`) jen údaji: v pokynu chyba, v návrhu warning a pole se nepoužije — podle údajů se nehledá v žádném režimu. |
| `partner_not_supported` | error / warning | Pole `partner` na hlavičce typu, který ho nenese (faktury — partnerem je strana; #166 D30). V pokynu chyba, v návrhu varování a pole se ignoruje. Path `partner`. |
| `partner_conflict` | error | Jen pokyn: u pokladního dokladu / prodejky určují pole `partner` a strana podle směru různé osoby. Path `partner`. |
| `signal_not_allowed` | error | Jen pokyn: dočasný signál návrhu (#166 D39) — `rows.N.item.ourCode`, účet řádku holým číslem (`rows.N.account`), `vat.registrationCountry`. Zpráva radí, které pole použít. |
| `unit_not_found` | warning / error | Jednotka řádku: návrh ji nerozpoznal (warning, doplní se výchozí); v pokynu není přesným systémovým kódem jednotky (error, #166 D37). Path `rows.N.unit`. |
| `account_not_found` | warning | Jen návrh: účet řádku holým číslem (signál) není v rozvrhu — řádek bude bez účtu. V pokynu je nenalezený účet `reference_not_found`. |
| `vat_registration_not_valid` | error | Reference `vat.registration` míří na registraci, která k datu dokladu neplatí (mimo importní mód). Path `vat.registration`. |
| `correction_original_not_supported` | error | `correction.originalDoc` míří na doklad typu, ke kterému nejde vystavit opravný doklad. |
| `cash_desk_ignored` | warning | `cashDesk` na faktuře bez `payment.method: "cash"` — pokladna se nepropíše. |
| `cash_desk_defaulted` | info | Platba hotově bez `cashDesk` — doklad dostal výchozí pokladnu zdroje dat (`is_default`, #103 F3). |
| `replace_concept_requires_draft` | error | `applyOptions.replaceConcept` s `targetDocState` jiným než 10 — přegenerování ukládá vždy Koncept (#110 Q2). |
| `replace_concept_with_import` | error | `applyOptions.replaceConcept` spolu s `importNumber` — koncept číslo nemá. |
| `replace_author_ignored` | warning | `applyOptions.author` při přegenerování — autor původního konceptu zůstává, klíč se ignoruje. |
| `company_id_invalid` | warning | IČO strany (cz, sk) nemá osm číslic nebo platný kontrolní součet (#118 D13, `Values\CanonicalValueValidator`). Path `supplier.companyId` apod. |
| `vat_id_invalid` | warning | DIČ / VAT ID nemá tvar prefix státu + 2–13 znaků, nebo neodpovídá pravidlu státu podle prefixu (cz 8–10 číslic, sk 10, de kontrolní číslice). |
| `vat_id_company_id_mismatch` | warning | Osmimístné české DIČ není `CZ` + IČO strany (9–10místné DIČ fyzické osoby vazbu nemá). Path `*.vatId`. |
| `zip_invalid` | warning | PSČ cz / sk nemá pět číslic (podle země adresy, jinak strany). |
| `account_number_invalid` | warning | Jistá chyba tvaru tuzemského čísla účtu `[předčíslí-]číslo/kód`, nebo strana cz / sk má účet jiného tvaru — důvod podle příčiny (#118 D27): nepovolené znaky, chybějící kód banky, jiný tvar, číslo ze samých nul. Mod 11 sem nepatří (D30). |
| `account_number_checksum` | warning | **Podezření** (#118 D30): tuzemské číslo účtu má správný tvar, ale předčíslí nebo číslo neprojde mod 11 — pravidlo pro vytvoření čísla, zveřejněné účty ho někdy nesplňují. Jednou na účet: path `*.bankAccount.accountNumber`; jen s IBAN (nebo IBAN jiného účtu) `*.bankAccount.iban` s textem „číslo účtu uvnitř … neprojde kontrolou mod 11 — ověřte ho“. Nikdy nenese návrh opravy; z podezřelého čísla se nic nedopočítává. V adresáři varování i u vlastní firmy a vlastních účtů. |
| `iban_invalid` | warning | IBAN nemá tvar, platnou kontrolní číslici (ISO 7064 MOD 97-10), nebo chybnou délku státu — důvod podle příčiny (#118 D27). Číslo účtu uvnitř (cz / sk) IBAN neznehodnotí (D30, `account_number_checksum`). |
| `bic_invalid` | warning | BIC nemá tvar 8 nebo 11 znaků. |
| `iban_account_mismatch` | warning | IBAN je platný a číslo účtu má tuzemský tvar, ale nenesou týž účet (BBAN se liší) — mod 11 nerozhoduje (D30), dva různé účty jsou jistá chyba. Path `*.bankAccount.iban`. |
| `bank_account_repaired` | warning | Normalizátor dopočetl neplatný IBAN z čísla účtu nebo naopak (D4) — zdrojem je jen hodnota, která projde vším včetně mod 11 (D30, `Iban::isValidWithDomesticAccount()`); z `_resolve.valueRepairs`, zpráva nese původní i novou hodnotu. Návrh s tímto nálezem nikdy není `ready`. |
| `exchange_rate_from_list` | info | Doklad v cizí měně bez `exchangeRate` mimo import mód: kurz doplněn z kurzovního lístku k účetnímu datu (#121 D6, `docs/exchange-rates.md` § 5.2). Path `exchangeRate`. |
| `exchange_rate_missing` | warning | Kurz k účetnímu datu není v lístku — doklad se uloží bez kurzu, potvrzení zastaví validace dokladu. |
| `correction_not_supported` | warning | Blok `correction` na typu dokladu bez příznaku `docTypes[].corrective` (přijatá, zálohová, pokladní) — ignorován, doklad vznikne jako běžný (#92 task B U2). Path `correction`. |
| `exchange_rate_from_original` | info | Opravný doklad v cizí měně bez `exchangeRate`: kurz převzat z původního dokladu (odkaz, shodná měna) místo lístku (#92 D4). Path `exchangeRate`. |
| `exchange_rate_corrective_manual` | info | Opravný doklad v cizí měně bez odkazu a bez `exchangeRate`: lístek se nečte, kurz zůstane prázdný a potvrzení zastaví validace dokladu. |

Apply-level kódy (`ApplyResult.errorCode`, 422): **`validation_failed`**
(error v issues před uložením, nebo doklad neprošel validací `DocDocument`
při uložení — #166 D21; nálezy jsou v `_resolve.issues`, nic se neuložilo),
`number_series_not_found` (řada z reference `numberSeries` není aktivní
nebo není typu dokladu),
`number_series_conflict` (u typu vázaného na pokladnu uvádí `numberSeries`
jinou řadu, než jakou určuje pokladna),
`replace_target_not_found` / `replace_target_type_mismatch` (422)
a `replace_target_not_concept` (409) u `replaceConcept`,
**`cash_desk_not_found`** (pokladna mimo stav V pořádku (40), takže jí nelze
založit řadu typu `cash`/`cashreg`; pokladně ve stavu 40 applier chybějící
řadu založí sám). Pokladna, řada ani vlastní účet, které se podle reference
**nenajdou**, apply-level kód nemají — jsou nálezem `reference_not_found`
pod `validation_failed`.

## 10. Apply pipeline

```
POST /api/v1/_exchange/docs/document/apply
  │
  ├─ 1. Schema validation (statická struktura)
  │
  ├─ 2. Resolve (znovu — i když /preview ho udělal, mohly se mezitím
  │      změnit DB data; idempotentní)
  │
  ├─ 3. Reconcile s klientským _resolve
  │      - validate userAction proti aktuálnímu resolve
  │      - sestaví execution plan: které entity vytvořit, které linkovat
  │
  ├─ 4. Validation gate
  │      - blok `issues` s severity="error" → 422 s payloadem
  │
  ├─ 5. NestedTransaction — vlastní transakce, nebo SAVEPOINT uvnitř
  │      transakce volajícího (generátory dokladů, které na výsledek
  │      atomicky navazují — periodická fakturace, #110)
  │
  ├─ 5b. replaceConcept: zámek cílového konceptu FOR UPDATE + kontrola
  │      stavu 10 a typu (replace_target_* → rollback vlastní práce)
  │
  ├─ 6. Side-creates (per execution plan)
  │      - canCreate Party → PersonDocument::saveDocument(...)
  │      - canCreate Item  → ItemDocument::saveDocument(...)
  │      - canCreate Bank  → BankAccountDocument::saveDocument(...)
  │      - per-partner item mapping → INSERT economy_items_supplier_codes
  │        (učení — jen režim návrh, #166 D6)
  │
  ├─ 7. Transform canonical → interní $data
  │      - item řádky bez operation → pohyb dle
  │        docs.core.applyRowOperations (viz níže)
  │      - reference → resolved id (partner, item, unit, vat_code)
  │      - canonical field names → DB column names (camelCase → snake_case)
  │      - currency uppercase → lowercase (cfgItem expects "czk")
  │      - vatRecap, totals vypustit (přepočte beforeSave)
  │      - source.* → docs_core_heads.source_kind / source_message /
  │        source_extracted_at
  │
  ├─ 8. TableGateway.saveDocument('docs_core_heads', $data)
  │      → DocDocument::validate (+ subclass per docType); chyba =
  │        rollback a 422 validation_failed s nálezy dokladu (#166 D21)
  │      → DocDocument::beforeSave (snapshoty, recap, totals, čísla)
  │      → insert docs_core_heads + rows + vat_recap; s replaceConcept
  │        update hlavičky (id cíle, sloupce mimo payload explicitně
  │        resetované) a náhrada řádků (bez id = staré smazat, nové vložit)
  │
  ├─ 9. Attachments — u dokladu z pošty se přílohy NEkopírují: zůstávají
  │      na zdrojové zprávě a detail dokladu je zobrazuje jako skupinu
  │      „mail" přes lineage (DocsHeadsViewer::sourceAttachmentGroups,
  │      heads.source_message + reverzní message.target_*)
  │
  ├─ 10. Lineage update (writeLineageTargets — jen pokud source.message
  │      je vyplněn; D6 z mail-message-centric)
  │      - core_mail_incoming_messages.target_table_id = 'docs_core_heads'
  │      - core_mail_incoming_messages.target_row      = $newDocId
  │      (druhá strana vazby k heads.source_message; zapisuje se atomicky
  │       v téže transakci. Verdikt analýzy — resolution — a docState
  │       zprávy sem záměrně NEpatří, ty píše MessageProposalApplier)
  │
  ├─ 11. COMMIT / RELEASE SAVEPOINT
  │
  └─ 12. Vrátí enriched canonical JSON
        - _resolve aktualizován: status="matched" pro všechny canCreate
          s novými id
        - docNumber doplněn z přidělené series (jen pokud confirm — viz níže)
        - savedDocId v top-level (FK na docs_core_heads.id)
```

### Pipeline pokynu vedle návrhu (#166)

Kroky jsou pro oba režimy (§ 3) stejné; liší se tím, co dělají:

| Krok | Návrh | Pokyn |
|---|---|---|
| normalizace hodnot (před validací) | tvary + oprava IBAN ↔ číslo účtu | jen tvary |
| `DocumentValidator` | včetně heuristik nad čísly předlohy | bez heuristik; `importNumber` povolené |
| resolve — strany, položky | `ref` má přednost, jinak hledání podle údajů (§ 8.1, § 8.2) | jen reference (`ReferenceResolver`); objekt bez `ref` s údaji = `reference_missing` (#166 D31) |
| resolve — účet řádku, jednotka | reference, nebo signál holým číslem (nenalezený = warning); jednotka i zkratkou a aliasem | jen reference (nenalezená = chyba); jednotka jen přesným systémovým kódem (`unit_not_found` = chyba) |
| resolve — řada, pokladna, vlastní účet, dimenze, registrace DPH, původní doklad | reference | reference |
| resolve — kódy DPH | odvození, soulad se signály, volby uživatele (§ 8.4) | kód z payloadu se jen ověří |
| resolve — partner účetního a pokladního dokladu (`partner`, `rows[].partner`) | jen reference; bez pole volba `_resolve…partner` | jen reference |
| reconcile | čte `userAction` vč. vynechání řádků | `_resolve` se nečte vůbec (D32) — blok se zahodí před validací, info `resolve_ignored`; řádky se nevynechávají |
| side-creates | podle `autoCreateMode` / `userAction` + učení dodavatelských kódů | **žádné** — pokyn osoby, položky ani účty partnerů nezakládá; `autoCreateMode` nečte |
| transform | místo, režim, registrace, rekapitulace a zaokrouhlení podle § 8.4 a § 5 | hodnoty z payloadu a výchozí hodnoty (§ 3, tabulka D20) |
| po uložení (v transakci) | — | kontrolní součet `totals` proti uložené hlavičce; neshoda = rollback a `totals_check_failed` (§ 5) |
| po uložení | — | varování dokladu do `_resolve.issues`, `total_rounding_mode_ignored` |

**`/preview` v pokynu** navíc spustí `DocDocument::validate()` pro cílový
stav z `applyOptions.targetDocState` — nález, který by apply odmítl (řádek
bez kódu DPH při cíli V pořádku, opravný doklad bez důvodu), je vidět před
uložením; stejně tak neshodu kontrolního součtu `totals`
(`totals_check_failed` nad `_resolve.computed`). Data skládá stejnou cestou
jako apply (reconcile, dohledání
pokladny a řady, výchozí pohyby řádků, transform) a nic nezapisuje; chybu
dohledání pokladny, řady nebo vlastního účtu hlásí týmž kódem, jaký vrátí
apply. Nehlásí, co posoudit nemůže: řadu vázanou na pokladnu, kterou apply
teprve zkusí založit, zámky záznamu a nález dokladu na poli, jehož
reference už sama skončila chybou (partner, položka řádku a její pohyb). Náhled návrhu validaci cílového stavu nemá — review
pracuje s konceptem a chybu uložení vrátí až apply.

### Doplnění pohybu řádků (`operation`)

Item řádek bez pohybu nesmí na docState 40 („Pohyb je povinný",
`DocRowOperationRules::validateRow`) — a AI pohyb správně nevrací
(interní účetní koncept, na předloze není). Applier proto item řádkům
s `operation = null` pohyb doplní dvoustupňově podle cfgItem
**`docs.core.applyRowOperations`** (`modules/docs/core/config/`,
klíč = docType):

1. **`byItemType`** — mapa `economy.items.itemTypes` → kód operace;
   `item_type` se čte z DB přes finální ID položky řádku (matched
   i side-created jednotně, proto běží až po side-creates),
2. **`default`** — fallback docTypu, když řádek položku nemá nebo typ
   není v mapě (invni → `acc.entry`, invno → `sale.services`).

Explicitní canonical `operation` má přednost (passthrough); kontační
(`accSide`) a textové řádky se nedoplňují; docType bez záznamu v cfg →
dnešní chování (null). `cashreg` záznam má (`sale.goods` default);
`cash` záměrně ne — pohyby závisejí na směru a mapa osu směru nemá, import
(old_shipard runner) posílá `operation` explicitně a AI apply pokladní
doklady netvoří. Řádek s operací, která má v `rowOperations` vlajku
`rowSide` (kontační — FX, `payment.*`), applier přepne na `price_calc_mode`
fromTotal, aby `totalPrice` přežil přepočet. Doplnění je **tiché** — AI pohyb nikdy nevrací,
doplňuje se tedy rutinně na každém item řádku a hláška, která svítí
vždy, by učila uživatele Upozornění přeskakovat; transparentnost dává
sám výsledek ve sloupci Pohyb konceptu (na rozdíl od `vat_mode_derived`,
kde výsledek odchylku od výstupu AI sám nevysvětlí). Kód, který
v `docs.core.rowOperations` neexistuje nebo není pro docType povolený,
se nedoplní a přidá warning `row_operation_config_invalid`; paritu
konfigurace hlídá `ApplyRowOperationsParityTest`.

### Apply a doc state

Default je uložení **v Konceptu** (`docState=10`). Klient může v requestu
specifikovat:

```jsonc
{
  "applyOptions": {
    "targetDocState": 40,      // 10 (Koncept) | 40 (V pořádku) | 30 (Storno) | 80 (jen migrace, viz níže)
    "autoCreateMode": "safe",  // strict (default) | safe | liberal — viz níže
    "createMissingEntities": true,   // explicit consent k side-creates
    "rejectOnIssues": ["error"],     // ["error"] | ["error","warning"] | []
    "interpret": false               // jen REST: true = režim návrh (§ 3, § 11); výchozí pokyn
  }
}
```

Když `targetDocState` je 40, projde state transition v `DocDocument::processStateTransition`
(přidělí číslo z series, vyrobí snapshoty). Doklad, který validací
`DocDocument::validate` pro cílový stav neprojde (chybějící povinná
reference, řádek bez kódu DPH, opravný doklad bez důvodu, …), se neuloží:
apply vrátí **422 `validation_failed`** s nálezy dokladu v `_resolve.issues`
(#166 D21, tabulka issue kódů) — ne `internal_error`. V pokynu je ukáže
už `/preview`.

#### Importní mód (`applyOptions.importNumber`)

Importní mód je **pokyn** (v režimu návrh chyba
`import_requires_instruction`) a nese **jen historii** dokladu (#166 D5,
D22) — tohle je úplný seznam toho, co mění:

- **vnucené číslo** dokladu a pořadové číslo (`importNumber.docNumber`,
  `sequenceNumber`; `null` = číslo mimo vzorec řady);
- **dobový snapshot partnera** z payloadu místo dnešního adresáře (níže);
- **otevírací a uzávěrkové období** (`fiscalPeriodType`);
- **archivní řady** — řada archivované pokladny;
- **zápis do zamčených období** (zámky záznamu se nevyhodnocují,
  `docs/document-system.md` § 16);
- **registrace DPH bez ohledu na platnost k datu** dokladu;
- **cílový stav V opravě** (`targetDocState: 80`) — parkovací cíl migrace;
  validátor ho povoluje jen s `importNumber` (jinak error
  `target_state_80_requires_import`), mimo migraci přes exchange
  dosažitelný není;
- **kurz z lístku se nedohledává** — chybějící kurz zůstane chybějící.

**Výjimky z validace nemá.** Chybějící kód DPH řádku (`vat_code_missing`)
a chybějící důvod opravy opravného dokladu (`correction_reason_required`)
jsou při potvrzení chybou i v importu; zdroj posílá validní doklad (kód
„Mimo DPH“ u řádku, který není plněním). Dál platí, co dokument pro import
dělá sám: přísná kontrola sazby k datu (`vat_rate_missing`), ruční osoba
pro saldokonto se nepřepisuje, varování zaokrouhlení se nehlásí.

Snapshoty stran se v import módu **nestaví z dnešního adresáře** — pro
historické doklady by to bylo věcně špatně. Strana partnera
(`supplier`/`customer` dle `selfParty`) se místo toho **mrazí do snapshotu
dokladu** tak, jak přišla v payloadu, **ve tvaru snapshotu z formuláře**
(#166 D23): klíče dává `PersonSnapshotBuilder::shape()`, bankovní spojení
partnera ve snapshotu není (formulářový snapshot partnera ho také nemá —
účet dodavatele nese hlavička), prázdná hodnota je u všech polí `null`
(D29 — jedno pravidlo se snapshotem z formuláře, `docs/docs-mvp.md` § 6.4).
Z `contact` jdou `email`, `phone` a `web`; ID
datové schránky strana dokladu nenese a ve snapshotu je `null` (#162
D133). Za dobové hodnoty — především `vatId`, ze kterého kontrolní hlášení
čte DIČ partnera — odpovídá exportér. Vlastní strana se staví standardně
(dnešní adresářová data vlastní firmy + `bank_account`
a `vat_registration` z hlavičky dokladu). Prázdná strana partnera
v payloadu = partnerský snapshot zůstává NULL (kanonické zdroje bez stran,
např. účetní doklady).

**Řada a vlastní účet jsou pole dokladu, ne volby apply** (#166 D30):
`numberSeries` (reference na `docs_core_number_series` — generátory dokladů
posílají id, datové sady kód `"@invno"`) a `payment.ownBankAccount`
(reference na `economy_codebooks_bank_accounts`, vlastní účet vydané faktury
ve stavu 40+). Řada musí být aktivní a typu `docType`, jinak
`number_series_not_found` (422); bez pole platí výchozí řada typu (první
aktivní); u typů vázaných na pokladnu řadu určuje pokladna a uvedená jiná
řada je `number_series_conflict`. Reference, která se nenajde, je nález
`reference_not_found`. Dřívější volby `applyOptions.numberSeriesCode`,
`numberSeriesId` a `importOwnBankAccount` ve schématu nejsou (D14 — řez bez
přechodného období).

`applyOptions.author` (#93 D9) určuje **autora dokladu** — `docs_core_heads.author`,
na tisku „Vystavil“. Rozhoduje **přítomnost klíče**, ne hodnota:

| V payloadu | Autor dokladu |
|---|---|
| `"author": 7` | uživatel 7 — musí existovat (aktivní i neaktivní), jinak error `author_not_found` na `applyOptions.author` |
| `"author": null` | doklad **bez autora** — applier klíč propíše do hlavičky mimo filtr nullů |
| klíč chybí | výchozí podle `DocAuthorResolver`: přihlášený člověk, jinak autor automaticky vystavených dokladů (řada → nastavení), jinak nikdo — `docs/document-system.md` → Autor dokladu |

Platí i mimo import mód (API klient smí autora určit); AI extrakce klíč
neposílá — `MessageProposalApplier` si `applyOptions` skládá sám, autorem je
uživatel, který návrh potvrdil. **Pozor u API klíče:** bez klíče `author`
rozhoduje uživatel klíče — člověk se stane autorem, systémový uživatel
(`is_system`) se bere jako strojový kontext. Migrace proto posílá klíč vždy
(i `null` u dokladů, které autora ve starém systému neměly) a uživatele
zakládá předem přes `/_exchange/users/user/apply` (§14 → Uživatelé).
Exportér (`DocumentExporter`) autora nevydává: datová sada uživatele
nepřenáší.

### Přegenerovat v místě — `applyOptions.replaceConcept`

`applyOptions.replaceConcept: <id dokladu>` (#110 D24, Q2) nahradí
**existující Koncept** obsahem payloadu místo založení nového dokladu —
obecný mechanismus pro generátory dokladů (periodická fakturace: akce
*Přegenerovat* a automatické doplnění konceptu po příchodu podkladů), ne
specialita zakázek. Validace, resolve a odvození jsou stejné jako u nového
dokladu; liší se jen uložení:

| Zachováno | Nahrazeno / resetováno |
|---|---|
| `id` (odkazy, přílohy — vážou se na `table_id` + `record_id`), `author` (klíč `author` v payloadu se ignoruje, warning `replace_author_ignored`), `docState` = 10, `doc_state_changed_at`, `doc_number` / `sequence_number` (koncept je nemá), `fiscal_year` / `fiscal_month` (přepočte `DocDocument`), součty a rekapitulace (přepočet) | všechny hlavičkové sloupce, které applier plní: text, partner, data, období, DPH, měna, platba, symboly, poznámky, `source_*`, dimenze, vlastní účet, řada — **sloupec mimo payload se vrátí na hodnotu čerstvého dokladu** (NULL / default), update přes `TableGateway` by jinak starou hodnotu nechal; řádky se nahradí celé (`rows` bez `id` → staré smazat, nové vložit) |

Podmínky: cíl existuje, je v Konceptu (10 — koš 90 se nepočítá) a má
stejný `doc_type` jako `docType` payloadu; `targetDocState` chybí nebo 10
(`replace_concept_requires_draft`), bez `importNumber`
(`replace_concept_with_import`). Kontrola cíle běží **uvnitř transakce se
zámkem řádku** (`FOR UPDATE`), takže koncept potvrzený mezi rozhodnutím
a apply skončí `replace_target_not_concept` (409), chybějící
`replace_target_not_found` (422), jiný typ `replace_target_type_mismatch`
(422). Idempotence podle `source.message` se při přegenerování přeskočí
(zpráva už na doklad ukazuje); `writeLineageTargets` propíše stejné id.
Provenience (`source_*`) sleduje payload — volající je autorita.

### Apply uvnitř cizí transakce

`apply()` ukládá v `NestedTransaction` (`src/Core/Database/`): mimo
transakci otevře vlastní, uvnitř transakce volajícího použije SAVEPOINT.
Volající, který musí na výsledek atomicky navázat (periodická fakturace:
zámek řádku období → apply → zápis `doc` do období), zabalí `apply()` do
`NestedTransaction::run` a při `success === false` svou práci vrátí
výjimkou — neúspěšný apply vrátil jen vlastní savepoint a vnější transakce
zůstává volajícímu. `internal_error` po pádu celé transakce na serveru
(deadlock) = volající dávku ukončí. Top-level volající (REST, pošta,
datové sady) se nemění.

Opačný směr (DB → canonical) dělají exportery v
`modules/core/exchange/src/Export/` (`DocumentExporter`, `PersonExporter`,
`ItemExporter`) a `RegistryExporter` v `modules/base/registry/src/` —
zrcadlo `transform()` s referencemi přirozeným klíčem (#166 D34, § 5
„Reference na záznamy“: strana `{ref: "@<číslo osoby>", …data}`, položka
`{ref: "@<kód>", name}`, účet `"@<číslo>"`, řada, vlastní účet, registrace
DPH, dimenze, původní doklad). Záznam bez hodnoty klíče (položka nebo
vlastní účet bez kódu, jednotka bez systémového kódu) = varování dumpu
a pole se vynechá. Konzument: datové sady, `shpd-ds dataset-dump`.

### `autoCreateMode`

**Jen návrh.** Pokyn nic nezakládá a volbu nečte (#166 D31). V návrhu
řídí, co se stane s `canCreate` referencí, na které klient nedoplnil
`userAction`:

| Mode | Chování |
|---|---|
| `strict` (default) | Bez explicit `userAction` → `422 unresolved_required`. Vhodné pro UI flow, kde uživatel rozhoduje. |
| `safe` | Autocreate, pokud `createPayload` splňuje per-tabulka guard (Party: `company_id`; Item: `name`; BankAccount: `iban` nebo `account_number`). Jinak `unresolved_required`. Vhodné pro AI flow (apply návrhu zprávy bez klientských userActions jede `safe`). |
| `liberal` | Autocreate vždy. Žádný safety guard. Pro budoucí B2B import / testovací cesty. |

`userAction` přebíjí mode — explicit `useExisting:<id>` nebo `create`
funguje stejně ve všech režimech.

### Normalizace a validace hodnot (#118)

Tvar údajů stran dokladu určoval model nebo dodavatel ISDOC (PSČ
s mezerou, IBAN po čtveřicích, telefon bez předvolby); platnost se
nekontrolovala. Dva kroky nad canonicalem `shpd.docs.document`
(`modules/core/exchange/src/Values/`, pravidla per stát v modulu
`world.validations` — `modules/world/validations/README.md`):

- **Normalizace** (`CanonicalValueNormalizer::normalize()`; v režimu
  pokyn jen `normalizeShapes()` — tvary bez opravy bankovního spojení,
  #166 U5) — čistá,
  idempotentní, mlčky sjednocuje tvar a obsah nemění (D1): země malými;
  `companyId` (cz / sk: bez mezer, samé číslice do osmi míst dostanou
  vedoucí nuly), `taxId`, `vatId` (velkými, bez mezer / teček / pomlček;
  u cz jen číslice dostanou prefix `CZ`), `address.zip` (podle země
  adresy, jinak strany), `contact.email` / `phone` (D12: `00` → `+`,
  `(0)` pryč, národní číslo bez národního předčíslí s předvolkou země
  strany; bez třídy země jen bez mezer a interpunkce; jen hodnota, která
  vypadá jako jedno číslo — víc čísel v poli, linka nebo nečekaná délka
  zůstávají beze změny, D26) / `web`,
  `bankAccount.iban` / `bic` / `accountNumber` (bez mezer a vedoucích
  nul), `payment.paymentReference` / `specificSymbol` /
  `constantSymbol` i na řádcích. Strany `supplier`, `customer`,
  `balanceParty`; `null`, chybějící klíče a ne-řetězce zůstávají, nic
  se nedoplňuje — jedinou výjimkou je **oprava z redundance (D4)**: IBAN
  a tuzemské číslo účtu (cz, sk — země z prefixu IBAN, jinak strany)
  nesou tentýž údaj; je-li jeden platný a druhý ne, neplatný se dopočte
  z platného (dopočtená hodnota musí sama projít validací), záznam jde
  do `_resolve.valueRepairs` (§ 9). Oba platné a nesedí → jen nález;
  oba neplatné → beze změny.
- **Validace** (`CanonicalValueValidator::validate()`) — kódy D13
  v tabulce Issue codes výš, vše `warning` na cestě pole, hodnota
  zůstává, apply neblokuje (D3). Kontroluje tvary po normalizaci
  (`normalizeShapes()`), takže persistované návrhy z doby před nasazením
  nedostanou nález za mezery. Telefon, e-mail, web ani `taxId` nálezy
  nevyrábí (u cz je `taxId` totéž co DIČ — nález by se zdvojil). Volá ji
  `DocumentValidator::validate()`, tedy `/validate`, `/preview` i
  `/apply`, a strop pásma návrhu
  (`AnalysisConfidenceResolver::capBand()`, D15: nález nebo oprava →
  `ready` klesá na `review`, v detailu zprávy i ve feedu).

**Kde a v jakém pořadí** (D2) — vždy po kontrole schématu (canonical má
zaručenou strukturu), před obohacením, dedupem a `DocumentValidator`:

1. AI analýza: `AnalysisResultWriter::validateCanonical()` (docs větev)
   před `enrichAtResult()` — pravidlo štítku hledá normalizované IČO;
   do `canonical_json` jde normalizovaný canonical, syrový výstup modelu
   zůstává v `analysis_json`.
2. ISDOC: `IsdocDocumentCollector::collect()` po `passesSchema()`
   v obou větvích (samostatná příloha, embedded) před dedupem identitou
   (páruje podle DIČ / IČ).
3. `DocumentApplier::validate()`, `preview()`, `apply()` po schématu
   před `DocumentValidator` — idempotentně pro každého producenta
   canonicalu včetně importního módu (`applyOptions.importNumber`): data
   ze starého Shipardu se při importu normalizují. Náhled vrací
   normalizovaný canonical; na hlavičku (`partner_bank_*`, symboly)
   i do založených účtů jdou normalizované / opravené hodnoty.

Porovnávač evaluace (`Compare\ValueShapes`) deleguje sdílené tvary na
tutéž knihovnu (D6) a výsledek volání nese `valueFindings`
(`docs/test-corpus.md` § 18).

**Adresář (fáze 2, #118 D18–D21, D23).** Všechny zápisy do osob, adres,
kontaktů, účtů osob a vlastních účtů ukládají kanonický tvar — jedna
funkce na tabulku (`base.persons` `Values\PersonValues`, země osoby
`Values\PersonContext`: sídlo → první adresa se zemí → země zdroje dat;
applier ji dává hlavičce jako virtuální `_country`), volaná z tříd
dokumentů (formuláře), z `PersonApplier` (podřízené záznamy přímým SQL)
i z `bankCreate` tady v applieru dokladu. IBAN zapsaný do čísla účtu
se přesouvá do `iban` (D20, `BankAccountShape`). Formuláře hlásí
nevalidní hodnoty týmiž kódy a texty jako nálezy canonicalu
(`ValueChecks` → `ValueFinding`; partner varování, vlastní firma
a vlastní účty chyba — D19). Tisky a detail osoby formátují
`ValueRules::format()` (D21), snapshoty stran na dokladech se nemění.
Párování účtů všude přes `BankAccountKey` (účty v canonicalu, protiúčet
a vlastní účet z výpisu, průvodce nastavením); QR platba a ISDOC berou
IBAN z `ValueRules::ibanFromAccountNumber()`. Adresa bez země dostane
pro PSČ zemi osoby, `country` se nedoplňuje (D24). Podrobně
`docs/exchange-format-persons.md` § 5–7. Existující data převede
`shpd-ds values-normalize` (D22, `docs/cli.md` → `shpd-ds`): tytéž
funkce, jen změněné sloupce, nevalidní hodnoty vypíše. Ověření
v registrech (ARES, VIES) je #142.

### Invariant ukládání (#166)

**Co uloží `DocDocument`, to výměnný formát přenese beze změny:** doklad
uložený formulářem (`TableGateway` → `DocDocument`), vyvezený
`DocumentExporter` a znovu uložený přes `DocumentApplier::apply` se musí
shodovat ve všech sloupcích `docs_core_heads`, `docs_core_rows`
a `docs_core_vat_recap`, které nejsou identitou, auditem ani původem
dokladu. Platí i opačně — co projde formátem, jde pořídit formulářem.
Platí pro **režim pokyn** (§ 3): pokyn neodvozuje, proto doklad nemění;
návrh je interpretace a invariant pro něj z definice neplatí.

Invariant hlídá integrační test
`tests/Integration/Exchange/DocumentRoundTripMatrixTest.php` nad maticí
dokladů (vydané a přijaté faktury v režimech DPH, převzatá rekapitulace,
řádek „Mimo DPH“, data sazeb, opravný doklad, cizí měna, dimenze, účetní
a pokladní doklad). Sloupce k porovnání bere z definic tabulek, takže nový
sloupec se hlídá sám. Kde invariant dnes neplatí, vede test **známou
mezeru** s přesným rozdílem; seznam mezer žije v testu
(`RoundTrip/RoundTripCases`), ne tady. Změna applieru nebo exportu, která
mezeru zavře nebo otevře novou, test zastaví — viz
`tests/Integration/README.md`. Po zavedení pokynu a výslovného módu
zaokrouhlení (#166 etapa 3) matice žádnou mezeru nemá; přechod formátu na
reference (část C) ji nezměnil — 20 případů z 20 drží.

Matice hlídá tvar, jaký vyrobí export. Tvary, které export nevyrobí —
řádek s objektem `vat` bez kódu, místo plnění v rozporu s DIČ dodavatele,
převzatá rekapitulace bez kódů, ceny řádků s daní při `fromBase`, `totals`
v rozporu s výpočtem, mód zaokrouhlení v rozporu s automatikou, reference
(nenalezená, nejednoznačná, špatně zapsaná, strana jen údaji, signály
návrhu, řada a registrace DPH referencí) — drží
`tests/Integration/Exchange/DocumentInstructionPayloadTest.php`
(„payload → uložený doklad“, #166 D24, D27, D30–D39).

## 11. REST API endpointy

Všechny pod `/api/v1/_exchange/docs/document/`. Auth: standardní (API key
nebo session token). Rate limit: standardní. Sesterské flow sdílejí
dispatcher: `/_exchange/persons/person/`, `/_exchange/items/item/`,
`/_exchange/bank/statement/`, import uživatelů `/_exchange/users/user/`
(jen `validate` + `apply`, admin nebo API klíč — §14 → Uživatelé) a import
majetku `/_exchange/assets/asset/` + `/_exchange/assets/doc-links/apply`
(stejná oprávnění — §14 → Majetek).

**Režim** (§ 3, #166 D2, D18): dokladové endpointy běží jako **pokyn** —
doklad se uloží, jak ho payload říká. Interpretaci (odvozování jako
u pošty) si klient vyžádá výslovně:

```jsonc
{ "applyOptions": { "interpret": true } }   // režim návrh; s importNumber nejde
```

### Doklad skriptem — minimální pokyn

Nejmenší payload, kterým skript (nebo jiný systém) založí vydanou fakturu:
odběratel a položka **referencí**, bez `totals` — částky, daň, splatnost,
účetní datum, DUZP, řadu, registraci DPH i výchozí pohyb řádku doplní
doklad stejně jako formulář.

```jsonc
POST /api/v1/_exchange/docs/document/apply
{
  "format": "shpd.docs.document",
  "formatVersion": "1.0",
  "docType": "invoiceIssued",
  "selfParty": "supplier",
  "customer": "@F00001",                    // nebo id: 42, nebo "@company_id:12345678"
  "dates": { "issueDate": "2026-11-20" },
  "rows": [
    {
      "item": "@SPRAVA-IT",                 // kód položky
      "description": "Správa IT — listopad 2026",
      "unit": "mnth",                       // systémový kód jednotky
      "quantity": 1,
      "unitPrice": 5000,
      "vat": { "code": "cz-120" }           // procento se neposílá
    }
  ]
}
```

- Vznikne **koncept** (výchozí `applyOptions.targetDocState` je 10);
  odpověď nese `savedDocId`. Pro stav V pořádku přidej
  `"applyOptions": {"targetDocState": 40}` a vlastní účet
  `"payment": {"ownBankAccount": "@BU1"}` — bez něj vydaná faktura
  potvrdit nejde.
- **Nejdřív `/preview`**: nic nezapíše a vrátí v `_resolve.issues` vše, co by
  apply odmítl (nenalezená reference, chybějící účet pro cílový stav),
  a v `_resolve.computed` částky, které doklad spočítá.
- Osoby a položky musí existovat předem (`/_exchange/persons/person/`,
  `/_exchange/items/item/`) — pokyn je nezakládá ani nehledá podle jména.
  Reference, která se nenajde nebo není jednoznačná, je 422
  `validation_failed` s nálezem na cestě pole.
- `totals` neposílej, pokud částky sám nepočítáš — uvedené jsou kontrolní
  součet (§ 5) a neshoda doklad odmítne.
- Text řádku jde z `description`; položka zadaná jen referencí text nenese.
- Jednotka je systémový kód (`pcs`, `hr`, `mnth`, …), ne zkratka z tisku.

### POST `/validate`

Statická + dynamická validace bez resolve a bez DB writes.

**Request body:** canonical JSON.

**Response:** `{success, issues: [...]}`. Nepoužívá `_resolve` strukturu —
jen validační findings.

### POST `/preview`

Validate + resolve. Bez DB writes.

**Request body:** canonical JSON. Z klientského `_resolve` se čtou jen
`contentTag` a volby DPH (`rows[i].vatCode.userAction`, `vat.place` /
`vat.mode.userAction`, #87 task B — jen v režimu návrh; pokyn blok
`_resolve` ignoruje celý s `resolve_ignored`); odpověď nese čerstvý `_resolve`,
takže `userAction` se v ní neobjeví — mapu rozhodnutí drží klient.

**Response:** enriched canonical s vyplněným `_resolve` na top-level a
issues uvnitř `_resolve.issues`. V pokynu nese i nálezy validace dokladu
pro cílový stav (§ 10 „Pipeline pokynu vedle návrhu“) — náhled vždy vrací
200, o tom, zda apply projde, vypovídají issues se `severity: error`.

### POST `/apply`

Validate + resolve + reconcile s klientským `_resolve.*.userAction` + uložit.

**Request body:** canonical JSON; v režimu **návrh** s vyplněným
`_resolve.*.userAction` (pokud preview indikoval `canCreate` /
`ambiguous`). Pokyn `_resolve` nečte — reference nese payload.

**Response:** enriched canonical (jako z `/preview`), navíc:

- `savedDocId` (top-level) — id nového záznamu v `docs_core_heads`
- `_resolve.summary.status = "applied"`
- Nově vytvořené entity mají `_resolve.*.personId` / `itemId` / atd. vyplněné
- Pokud `targetDocState: 40`: `docNumber` doplněn z přidělené series

**Chybové stavy:**

- `400` — schema validation failure (chyba struktury)
- `422 unresolved` — jen návrh: applier běžel, ale narazil na nerozhodnuté
  reference bez `userAction`. Tělo obsahuje `_resolve` se seznamem.
- `422 validation_failed` — `_resolve.issues` obsahuje severity=error
  (např. chybějící datum, neznámý kód DPH v pokynu, **reference, která se
  nenašla** — `reference_not_found` / `reference_ambiguous` /
  `reference_invalid`, `reference_missing`, `signal_not_allowed`,
  `unit_not_found`; #166 D31), nebo doklad neprošel validací `DocDocument`
  při uložení (#166 D21); nic se neuložilo.
- `422 totals_check_failed` — jen pokyn: `totals` payloadu se liší od
  výpočtu dokladu (#166 D27); nálezy s `declared` / `computed` jsou
  v `_resolve.issues`, nic se neuložilo.
- `409 conflict` — jen návrh: během reconcile se zjistilo, že entita
  mezitím zmizela (`useExisting:42` ale person 42 už neexistuje).

## 12. Source lineage

Vazba doklad ↔ zdrojová zpráva je **obousměrná** a obě strany zapisuje
apply atomicky (D6 z `tasks/mail-message-centric.md`):

**1. `core_mail_incoming_messages.target_table_id` / `target_row`** —
forward lookup ze zprávy → výsledná entita (docs i registry). Zapisuje
`DocumentApplier::writeLineageTargets` (resp. `RegistryApplier`) uvnitř
save transakce. Zároveň slouží jako klíč **idempotence** apply — obsazený
target = opakovaný apply vrátí existující entitu, nevzniká duplicita.

**2. Sloupce v `docs_core_heads`:**

| Sloupec | Typ | Význam |
|---------|-----|--------|
| `source_kind` | `enumString(40) nullable` | `aiExtraction` / `isdoc` / `manual` / `workOrder` (periodická fakturace) / `correctiveDoc` (opravný doklad z akce Vystavit opravný doklad, #92 D6) / `import.flexibee` / … (řízeno cfgItem `docs.core.sourceKinds`) |
| `source_message` | `int nullable` ref `core_mail_incoming_messages`, index `idx_source_message` | Zdrojová zpráva došlé pošty; plní server-side injection `source.message` při apply návrhu. |
| `source_extracted_at` | `datetime nullable` | Časový bod extrakce / importu. |

Reverse lookup z dokladu → původ. Pro doklady ručně pořízené přes UI je
`source_kind = NULL` (nebo `'manual'`, podle preference; default NULL).

## 13. Verzování

Klíč `formatVersion` v top-level (`"1.0"`). Změnové strategie:

- **Drobná rozšíření** (nová optional pole, nový enum value) — zachovává
  major verzi, applier je tolerantní (`additionalProperties` allowed).
- **Breaking changes** (přejmenování pole, změna typu) — bump na novou
  major verzi (`"2.0"`). Schema soubor + applier per-verzi (`v1Applier`,
  `v2Applier`). Server podporuje obě verze simultánně.
- **Polymorfismus podle `docType`** — přidání nového typu (např. `creditNoteReceived`)
  není breaking pokud rozšiřuje, ne nahrazuje existující sémantiku.

## 14. Budoucí formáty

Tato kapitola je plán, ne specifikace. Detail bude v samostatných
dokumentech.

### `shpd.persons.person.v1`

Použití: import z ARES, slovenský rejstřík (RPO), DE Handelsregister, …

```jsonc
{
  "format": "shpd.persons.person",
  "formatVersion": "1.0",
  "source": { "kind": "import.ares", "fetchedAt": "..." },
  "personType": "company",          // company | person
  "country": "CZ",
  "companyId": "12345678",
  // … rest like Party object, plus addresses array, bankAccounts array
}
```

Resolver / Applier obdobně — propojí na `base_persons_persons` + child
`base_persons_addresses`, `base_persons_bank_accounts`, `base_persons_contacts`.

### `shpd.items.item.v1`

Použití: import katalogů od partnerů, B2B item mapping.

### `shpd.exchangeRates.list.v1`

Kurzovní lístek — jeden soubor = jeden lístek (`listType`, `homeCurrency`,
`validFrom`, `validTo`, `listNumber`, `rates[] {currency, amount, rate}`).
Čte ho CLI `exchange-rates-import`, sekce `exchangeRates/` datové sady
a v tasku B služba; zapisuje jen `ExchangeRateListImporter`. Schéma
`modules/core/exchange/schemas/shpd.exchangeRates.list.v1.jsonc`, popis
`docs/exchange-rates.md` §2 (#121 D2).

### `shpd.docs.bankStatement.v1`

Použití: import bankovních výpisů (XML/CSV od banky → kanonický → pokladní
doklad nebo párování plateb).

### Uživatelé — `shpd.system.user.v1`

Hotový malý formát pro migraci (#93 D7, D13), `UserApplier`
(`modules/core/exchange/src/User/`). Starý systém měl uživatele jako Osobu
s loginem; nový je má oddělené. Importér proto pro každou Osobu, která ve
starém systému „něco udělala“ (autor dokladu, autor záznamu spisovny),
založí uživatele a jeho id pak posílá jako `applyOptions.author` u dokladů
a `createdBy` u spisovny (`POST /_registry/import`). Login ve starém systému
kritérium není — autorů je jednotky, osob s loginem řádově víc.

```
POST /api/v1/_exchange/users/user/validate
POST /api/v1/_exchange/users/user/apply
```

```jsonc
{
  "format": "shpd.system.user.v1",   // verze je součást `format`, bez `formatVersion`
  "login": "jana@example.test",      // povinné — určuje importér
  "email": "jana@example.test",      // nullable
  "fullName": "Jana Příkladová",     // povinné
  "person": 123                      // nullable — id Osoby v tomto zdroji dat
}
```

Odpověď: `{ "userId": 7, "created": true }` — `apply` 201 při založení, 200
při nalezení existujícího; `validate` vrací uživatele, kterého by `apply`
vrátil (`userId: null` = založil by nového), a nic nezapisuje. Chyby mají
společný tvar exchange (`schema_invalid` 400, `validation_failed` 422
s `details.canonical._resolve.issues`; neexistující Osoba = `person_not_found`).
`preview` flow nemá — není co rozhodovat.

**Párování** (idempotentní — `ds-reset` uživatele nemaže, opakovaný import
je běžný stav):

1. uživatel se stejným `login` → ten (i neaktivní);
2. jinak **právě jeden** aktivní ne-systémový uživatel se stejným `email`
   bez ohledu na velikost písmen → ten (skutečný účet, který mezitím založil
   admin); víc shod = nejednoznačné, nepáruje se;
3. jinak se založí nový.

Login je e-mail; při chybějícím nebo duplicitním e-mailu syntetický
(`import-<ref>`). Rozhoduje **importér**, který zná všechny Osoby — applier
jen páruje a unikátnost loginu (`unq_login`) řeší tím, že kolizi vrátí jako
nalezeného uživatele.

**Hranice** (D13 — endpoint smí volat admin **nebo API klíč**):

- nový uživatel je vždy `is_active = 0`, bez hesla, `is_admin = 0`,
  `is_system = 0`; formát žádné takové pole nemá (`additionalProperties: false`);
- u nalezeného uživatele se **nemění nic kromě `person`** — a ta jen když je
  prázdná nebo ukazuje na neexistující Osobu (po `ds-reset` mají Osoby nová
  id, uživatelé zůstali);
- sloupec `person` přidává rozšíření z `base.persons`; na zdroji dat bez Osob
  se `person` v payloadu ignoruje.

API klíč tedy voláním nezíská přihlášení. Aktivace importovaného uživatele
je ruční akce admina (`docs/auth.md`). V read-only stavu zdroje dat projde
`validate`, `apply` končí 403 (`ReadOnlyPolicy`, přípona akce).

---

### Majetek — `shpd.assets.asset.v1` a doplnění karty na doklady

Hotové formáty migrace majetku (#83 fáze 6, `docs/assets.md` §5.7);
applier a služby jsou v modulu majetku
(`modules/economy/assets/src/Import/`), schéma zde u ostatních. Oprávnění
jako import uživatelů (admin nebo API klíč), vždy POST.

```
POST /api/v1/_exchange/assets/asset/validate     # celý průběh s rollbackem
POST /api/v1/_exchange/assets/asset/apply
POST /api/v1/_exchange/assets/doc-links/apply
```

```jsonc
{
  "format": "shpd.assets.asset.v1",   // verze je součást `format`
  "asset": {
    "assetNumber": "MA0012",          // klíč párování, převezme se beze změny
    "name": "Soustruh", "shortName": null, "note": null,
    "type": 12, "category": "tangible", "tracking": "single",
    "accountingGroup": 3, "foreign": false, "owner": null,
    "acquiredDate": null, "disposedDate": null, "price": null,   // jen drobný majetek
    "taxMethod": "straight", "taxRule": "cz-2", "accMethod": "as_tax", "accMonths": null,
    "state": "confirmed"              // confirmed | archived
  },
  "events": [
    { "kind": "activation", "scope": "both", "date": "2019-05-01", "amount": 480000, "sourceRef": "row:1" },
    { "kind": "depreciation", "scope": "tax", "date": "2019-12-31",
      "periodBegin": "2019-01-01", "periodEnd": "2019-12-31", "amount": 52800,
      "claimUnrecorded": false, "halfYear": false, "sourceRef": "deps:4711" }
  ]
}
```

Odpověď `{ "status": "created" | "updated" | "skipped", "assetId": 31,
"warnings": [{code, message, path?}] }` — 201 při založení. Existující
karta dostane přepsanou hlavičku a nahrazené události původu `import`;
karta s ručními nebo systémovými událostmi se přeskočí
(`asset_has_local_events`). Chyby ve společném tvaru (`schema_invalid` 400,
`validation_failed` 422) s `details.issues[]` — cesta do payloadu
(`events.3.amount`) a `sourceRef` události. `preview` flow nemá.

```jsonc
{ "docId": 4711, "headAsset": null,
  "rows": [ { "account": "551022", "side": "dr", "amount": 13074.00,
              "asset": 15, "orderHint": 3, "sourceRef": "row:812" },
            // řádek faktury bez účtu (účet dává položka / kategorie): jen částka
            { "amount": 48000.00, "asset": 15, "sourceRef": "row:813" } ] }
```

`account` je nepovinný: chybí-li (nebo je `null`), páruje se řádek jen
s řádky dokladu bez účtu podle částky (a strany, je-li uvedená); prázdný
text je chyba tvaru. Odpověď `{ "status": "linked" | "unchanged" |
"ambiguous" | "notFound" | "conflict" | "turnover_changed" |
"accounting_failed", "docId", "rows": [{index, sourceRef, rowId, status}],
"head"? }` — stav párování je obsah odpovědi (HTTP 200), chybný tvar 400
s `details.issues`. Doklad se mění celý, nebo vůbec; ve stavu 40 se
přegeneruje deník s pojistkou shodných obratů. Pravidla párování
a uvolněné validace importu: `docs/assets.md` §5.7.

**Datová sada** (#131 fáze 2c, D38, D40) nese karty v sekci `assets/`
v obálce `shpd.dataset.asset.v1` — stejná struktura, místo id přirozené
klíče (typ názvem, účetní skupina kódem, vlastník identifikátory) a původ
události; seed jde přes `AssetDocument` / `AssetEventDocument` bez
importního módu, applier importu se nepoužívá. Viz `docs/datasets.md` §1.

---

### Zakázky — `shpd.workOrders.workOrder.v1`

Import zakázky ze starého Shipardu (#110 D9, D25, D26; `docs/work-orders.md`
§6, `tasks/work-orders-import.md`). Verifier a applier jsou v modulu zakázek
(`modules/economy/workOrders/src/Import/`), schéma zde u ostatních. Oprávnění
jako import majetku (admin nebo API klíč), vždy POST; `preview` flow nemá.

```
POST /api/v1/_exchange/workOrders/workOrder/validate   # celý průběh s rollbackem
POST /api/v1/_exchange/workOrders/workOrder/apply
```

```jsonc
{
  "format": "shpd.workOrders.workOrder.v1",
  "workOrder": {
    "number": "S260001",            // klíč párování; chybí → číslo přidělí řada při potvrzení
    "sequenceNumber": 1,            // nepovinné; s number srovná čítač řady
    "numberSeries": 3,              // řada zakázek (id) — určuje druh a typ
    "title": "Nájem kanceláře",
    "state": "confirmed",           // draft | confirmed | finished | cancelled
    "dateStart": "2026-01-01", "dateEnd": null,
    "costCenter": 2, "internalNote": null,
    "customer": 41, "currency": "czk", "paymentReference": null,   // jen externí typy
    "parent": null,                 // ČÍSLO nadřazené zakázky (jen jednorázové typy)
    "invoicing": {                  // jen periodický typ
      "periodicity": "month", "invoiceFrom": "2026-11-01", "docText": null,
      // přepisy z druhu — chybějící / null = z druhu
      "docType": null, "numberSeries": null, "dueDays": null, "timing": null,
      "vatMode": null, "paymentMethod": null, "bankAccount": null
    }
  },
  "rows": [                         // jen periodický typ; mimo Koncept aspoň jeden
    { "item": 15, "description": "Nájem", "quantity": 1, "unit": "pcs",
      "unitPrice": 4500, "vatCode": "cz-110", "operation": null,
      "validFrom": null, "validTo": null }
  ]
}
```

**Reference a kódy (I1):** `numberSeries`, `costCenter`, `customer`, `item`,
`invoicing.numberSeries` a `invoicing.bankAccount` jsou **id záznamů nového
Shipardu** — mapu starých na nová drží runner (jako `type` / `owner` u karty
majetku). Kódy jen tam, kde jsou přirozené: měna (`world.base.currencies`),
`vatCode`, `operation` (`docs.core.rowOperations`), jednotka (`system_code`,
zkratka nebo název přes `UnitResolver`), `periodicity` (`month` / `quarter` /
`halfyear` / `year`), `timing` (`start` / `end`), `vatMode` a `paymentMethod`
ve stejných kódech jako `shpd.docs.document.v1` (`none` / `fromBase` /
`fromTotal`, `cash` / `bankTransfer` / `card` / …).

**Kontrola před zápisem** (`WorkOrderImportVerifier`): existence referencí,
kódy, pravidla typu z `WorkOrderTypes` podle řady (strana jen u externích,
`parent` jen u jednorázových, `invoicing` a `rows` jen u periodického typu;
periodická mimo Koncept potřebuje periodicitu a aspoň jeden řádek),
`invoicing.numberSeries` typu efektivního dokladu (zakázka → druh), nadřazená
podle čísla (`parent_not_found` — runner posílá nadřazené dřív). Kódy DPH
se ověřují proti kódům země výchozí registrace pro směr cílového dokladu
bez ohledu na místo plnění (předpis zahraničního odběratele nese kód EU
služeb, jako formulář řádků podle zákazníka). Co verifier
nehlídá, hlídá `WorkOrderDocument` při zápisu (zákazník povinný při
potvrzení, nadřazená ne periodická, cyklus, duplicitní číslo…) a applier
odmítnutí překládá na cestu payloadu (`inv_periodicity` →
`workOrder.invoicing.periodicity`, `_form` → `workOrder`). Bez registrace
k DPH se kódy DPH neověřují.

**Apply (I2):** v jedné transakci hlavička v Konceptu → řádky předpisu
(zámek řádků potvrzené zakázky ještě neplatí) → cílový stav uložením
hlavičky znovu; `finished` / `cancelled` projdou přes V pořádku, takže
číslo i datum ukončení (chybí-li, dnešek) vznikají jako ve formuláři.
Zakázka se stejným číslem v libovolném stavu → `skipped` s varováním
`work_order_exists`, nic se nemění (reimport jde do resetovaného zdroje;
přepis se nedělá). Koncept bez čísla klíč nemá a opakovaný import ho
založí znovu.

**Číslo (I4):** `number` se převezme beze změny; `sequenceNumber` doplní
pořadí a fiskální rok data zahájení (u řady s ročním restartem — bez
založeného roku chyba `fiscal_year_missing`) a srovná čítač řady přes
`SequenceCounter::syncImported` (GREATEST, idempotentní), takže další
zakázka ve formuláři pokračuje za importovaným číslem. `number` bez
`sequenceNumber` = varování `counter_not_synced`; `sequenceNumber` bez
`number` = chyba `number_required`. Chybí-li `number` a stav není `draft`,
přidělí číslo řada při potvrzení.

Odpověď `{ "status": "created" | "skipped", "workOrderId": 31,
"number": "S260001", "warnings": [{code, message, path?}] }` — 201 při
založení; runner si `number` uloží pro `dimensions.workOrder` dokladů.
Chyby ve společném tvaru (`schema_invalid` 400, `validation_failed` 422,
`work_orders_unavailable` 500 bez modulu) s `details.issues[]` a cestou do
payloadu (`rows.2.vatCode`). `validate` projde celým průběhem s rollbackem
(pravidla potvrzení závisejí na uložené hlavičce a řádcích) — výsledek je
totožný s `apply`, nic se nezapíše. V read-only stavu zdroje projde
`validate`, `apply` končí 403 (`ReadOnlyPolicy`).

**Pořadí importu (D26):** druhy a číselné řady zakázek (formát je nenese —
runner je založí předem, I3) → osoby, položky, střediska → **zakázky**
(nadřazené před podřízenými) → doklady s `dimensions.workOrder` = číslo
zakázky. Datová sada (`dataset-dump` / `dataset-seed`) nese zakázky
v obálce `shpd.dataset.workOrder.v1` se stejnou strukturou a přirozenými
klíči místo id, druhy a řady v `setup/` — viz `docs/datasets.md`
§ Obálka zakázky (#131 fáze 2b). **Fakturovat od:** evidence vyfakturovaných období po importu
začíná prázdná, takže `invoicing.invoiceFrom` musí být první dosud
nevyfakturované období (pravidlo runneru v `docs/work-orders.md` §6) —
jinak by periodická fakturace vystavila období, která starý Shipard už
fakturoval.

## 15. Reference

- [docs/document-system.md](document-system.md) — Document/TableGateway
  systém, nad kterým exchange formát staví.
- [docs/table-definitions.md](table-definitions.md) — JSONC formát definic
  tabulek.
- [docs/modules.md](modules.md) — modulový systém.
- [modules/core/mail/docs/ai-analysis.md](../modules/core/mail/docs/ai-analysis.md)
  — AI pipeline, která je primárním konzumentem exchange formátu.
- [modules/world/vat/](../modules/world/vat/) — `VatRateResolver`,
  na který volá VatCodeResolver.
