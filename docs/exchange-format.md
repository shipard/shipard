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
Místo `partner: 42` má `partner: { country: "CZ", companyId: "12345678" }`.
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
6. **Mezikrok pro elektronickou fakturaci** — ISDOC, Peppol UBL, e-Faktura
   se rozparsují na canonical, ten se uloží. Stejný flow pro AI extrakci
   i strukturovaná data. Příchozí ISDOC je implementován — viz `IsdocReader`
   v sekci Adaptéry (kapitola 4).
7. **Export pro elektronickou výměnu** — opačný směr: applier (resp. exporter)
   vyrobí canonical z DB záznamu, ten lze serializovat do ISDOC, e-mailem
   přeposlat partnerovi, který má taky Shipard, atd. Spolehlivější než AI
   extrakce z PDF na obou stranách.
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

## 4. Životní cyklus

```
Vstup (PDF, ISDOC, ruční zadání, ...)
  │
  ▼
[Adaptér / AI analyzer / parser]
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
v `_resolve.*.userAction`.

### Adaptéry

První implementovaný vstupní adaptér je **ISDOC** (český standard
e-fakturace): `Shipard\Module\Core\Exchange\Isdoc\IsdocReader` konvertuje
ISDOC 6.x XML (i `.isdocx` ZIP obal) na canonical se `source.kind='isdoc'`
a confidence 1.0. Mapuje se jen to, co v ISDOC opravdu je (chybějící pole
se vynechávají); podporované `DocumentType`: 1 → `invoiceReceived`,
2 → `creditNote`. Kompletní mapovací tabulka ISDOC → canonical:
[tasks/mail-isdoc-import.md](../tasks/mail-isdoc-import.md). Použití
v příjmu pošty (deterministický import místo AI analýzy):
`modules/core/mail/docs/ai-analysis.md`, sekce „Deterministický ISDOC
import". Ostatní adaptéry (Peppol UBL, Pohoda, Flexibee, …) zůstávají
future work.

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

  // ── Parties ──────────────────────────────────────────────────────────────
  "supplier":  { /* Party — viz sekce 6 */ },
  "customer":  { /* Party — viz sekce 6 */ },
  "balanceParty": null,            // Party — ruční plátce (osoba pro
                                  //   saldokonto, #72); null = odvodí se

  // ── Dates ────────────────────────────────────────────────────────────────
  "dates": {
    "issueDate":         "2026-04-15",
    "dueDate":           "2026-04-29",
    "accountingDate":    "2026-04-15",
    "taxPointDate":      "2026-04-15",  // DUZP
    "vatObligationDate": "2026-04-15",  // DPPD
    "periodFrom":        null,
    "periodTo":          null
  },

  // ── Currency & VAT ───────────────────────────────────────────────────────
  "currency":     "CZK",          // ISO 4217 uppercase v canonical;
                                   //   applier lowercases pro cfgItem
  "exchangeRate": null,           // required if currency != home

  "vat": {                        // celý objekt nullable — nelze-li určit,
                                   //   vynechat nebo null (ne prázdný objekt)
    "mode":  "fromBase",          // fromBase | fromTotal | none
                                   //   (key z docs.core.vatModes)
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
    "place": "domestic",          // klíč z docs.core.vatPlaces
    "registrationCountry": "CZ",  // ISO země — resolver dohledá
                                   //   economy_codebooks_vat_registrations
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
  },

  // ── Payment ──────────────────────────────────────────────────────────────
  "payment": {
    "method":          "bankTransfer",  // cash | bankTransfer | card | cashOnDelivery
                                        //   | setOff | paymentGateway (docs.core.paymentMethods)
    "paymentReference": "2026000123",
    "specificSymbol":  null,
    "constantSymbol":  null
  },

  // ── Notes ────────────────────────────────────────────────────────────────
  "notes": {
    "internal":   null,           // → docs_core_heads.notice
    "onDocument": "Děkujeme."     // → docs_core_heads.doc_notice
  },

  // ── Rows ─────────────────────────────────────────────────────────────────
  "rows": [
    { /* DocumentRow — viz sekce 7 */ }
  ],

  // ── Computed (informative; applier recomputes) ───────────────────────────
  "vatRecap": [
    {
      "vatCode": "highEU", "vatPct": 21,
      "base": 10330.58, "tax": 2169.42, "total": 12500.00,
      "isReversePair": false
    }
  ],
  "totals": {
    "totalBase":     10330.58,
    "totalVat":      2169.42,
    "totalAmount":   12500.00,
    "totalRounding": 0.00
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

### Pole `vatRecap` a `totals` — vstup vs. autorita

**`vatRecap` je autorita, když doklad říká `vat.recapSource: "declared"`.**
Applier ho pak uloží tak, jak přišel, a `DocDocument::beforeSave()` ho
nepřepočítá — dopočte z něj jen domácí měnu, součty hlavičky a flagy
sčítání z definice kódu (`docs/vat-calculation.md` § 5). To je cesta pro
import ze starého Shipardu a pro doklad dodavatele: nárok na odpočet je
částka **z faktury**, i když je haléřově „špatně".

U `"computed"` (a u vystavených dokladů vždy) je `vatRecap` **informativní**
a v DB je autoritativní hodnota vypočtená z řádků.

**Odvození, když `recapSource` chybí (`null`):** u dokladu, který přijímáme
(`selfParty: "customer"` — typicky AI extrakce faktury dodavatele), je
`declared` tehdy, když je rekapitulace neprázdná, každý řádek projde
aritmetickou kontrolou níže a u každého jde dohledat DPH kód. Jinak
`computed` + info issue **`recap_source_computed_fallback`** s důvodem —
bez něj by uživatel nepoznal, proč je na dokladu jiná rekapitulace než na
předloze. U vystavených dokladů je odvození vždy `computed`.

**DPH kód rekapitulace:** ISDOC ho v rekapitulaci nenese (`TaxSubTotal` má
jen sazbu a částky), takže ho applier dohledá z položkových řádků — mapa
sazba → kód, použije se jen pro sazbu s jediným kódem. Bez kódu se
rekapitulace převzít nedá (`vat_code` je NOT NULL a bez kódu nejdou určit
flagy sčítání) → `computed` + info issue.

`totals` zůstávají informativní vždy. Důvod, proč jsou obě pole v canonical:

- **UI náhled** — chce je zobrazit (AI extrahovala součty z PDF, uživatel
  je kontroluje).
- **Validace** — applier porovná deklarované totals s vypočtenými, pokud
  se liší, vyrobí warning `totals_mismatch` v `_resolve.issues`. Silný
  signál chybné extrakce řádků. Výjimka: deklarovaná **celá** částka
  v pásmu < 1,00 od vypočtené varianty projde bez warningu — jde
  o zaokrouhlení celkové částky faktury.
- **Integrita řádků vs. rekapitulace** — `totals_mismatch` nechytí
  neúplné řádky, když AI rekapitulaci opsala z dokladu (recap si na
  deklarovanou částku vždy sedne). Proto validátor navíc porovná součet
  položkových řádků proti rekapitulaci podle efektivního režimu DPH
  (fromBase → Σ `base`, fromTotal → Σ `total`; fallback `totals`,
  tolerance per-řádkového zaokrouhlení) → warning **`rows_recap_mismatch`**
  na `rows`. Rekapitulace z dokladu je autoritativní — mismatch znamená
  neúplné či chybně extrahované řádky. Stejnou kontrolu dělá při uložení
  i `DocDocument` nad převzatou rekapitulací.
- **Vnitřní aritmetika rekapitulace** — pro každý řádek recapu musí
  platit `base + tax = total` (±0,02) a `tax = base × pct/100`
  (±max(0,05; |base| × 0,001) — kryje haléře i výpočet koeficientem);
  reverse-charge páry a 0% řádky se přeskakují. Porušení → warning
  **`vat_recap_inconsistent`** na `vatRecap[i]`. Chytá rekapitulaci,
  kterou model dopočítal pozpátku (typicky po chybně určeném režimu DPH)
  místo opsání z dokladu. **Pozor:** u explicitního `declared` je to jen
  warning, ne důvod k přepočtu — u přenesení daňové povinnosti `base + tax
  ≠ total` platí a je správně.

`totals.totalRounding` nese zaokrouhlení celkové částky se znaménkem
(zaokrouhleno dolů = záporné, např. `-0.05`). I ono je informativní —
applier z něj **nečte**; `total_rounding_mode` dokladu (matematicky /
nahoru / dolů na celé jednotky, nebo matematicky na 0,05 — hotovost SK)
si odvozuje nezávisle porovnáním vypočtené a deklarované částky
(`DocumentApplier::deriveTotalRoundingMode`, konzervativně jen pro rozdíl
>= 0,01 a < 1,00, který některý mod přesně reprodukuje — rozdíl o jediný
haléř je platné zaokrouhlení, 69,99 → 70,00; celé jednotky mají přednost
před 0,05, protože celá částka je násobek 0,05 taky). Validátor
(`checkTotals`) mlčí přesně tam, kde applier mód přidělí.
Výslednou částku a `total_rounding` pak dopočte `DocDocument` sám.
**Důsledek pro zdroje dat:** `totalAmount` musí být částka **po** zaokrouhlení
(placená) — starý Shipard drží `sumTotal` bez zaokrouhlení a runner musí
poslat `sumTotal + rounding`, jinak vyjde mód 0 a doklad zaokrouhlení ztratí
(reimport 2026-09-07: 211/311/321 o haléře jinak, saldo nespáruje 70,00
proti 69,99). Platí pro AI extrakci i ISDOC
(`PayableRoundingAmount`) — obě cesty jdou přes týž applier.

### Polymorfismus podle `docType`

`docType` určuje, která pole jsou relevantní:

- **`invoiceReceived`** — strana, kterou _my_ pozici je `customer`,
  `supplier.bankAccount` se má vyplnit (kam platíme),
  `payment.paymentReference` typicky odvozeno od `docNumber`.
- **`invoiceIssued`** — strana, kterou my pozici je `supplier`,
  `supplier.bankAccount` (= náš účet) se vyplní z dokladu, customer
  z partnera.
- **`accountingDocument`** (`cmnbkp`) — bez stran, kontační řádky
  (`accSide`, `account`), partner per řádek přes pin.
- **`cashDocument`** (`cash`, pokladní doklad, #59 D12) — povinný **`cashDesk`**
  (kód pokladny `economy_codebooks_cash_desks.code`) a **`cashDirection`**
  (1 příjem / 2 výdej). Řadu applier dohledá podle (typ, pokladna) — řady
  jsou vázané na pokladnu, `applyOptions.numberSeriesCode` se ignoruje.
  Chybí-li řada, applier ji založí sám (`BoundNumberSeriesProvisioner`,
  idempotentní — pokladna založená generickým CRUD importu nespustí
  `afterSave` handler); neznámá pokladna nebo pokladna mimo stav 40 =
  apply-level chyba `cash_desk_not_found` (422). Archivovanou pokladnu (70)
  přijme jen import mód (`applyOptions.importNumber`) — řady vzniknou ve
  stavu 70 (#59 Task E). `cash_desk` hlavičky se denormalizuje z řady.
  Strany `supplier`/`customer` nepovinné (anonymní doklad); je-li strana
  uvedená, je partnerem hlavičky podle směru (příjem → odběratel, výdej →
  dodavatel; `selfParty` má přednost) a v import módu z ní vzniká dobový
  snapshot. `payment.method` default `cash` (jinak jen `card`). Úhrady
  faktur hotově/kartou = řádky `operation: payment.receivable` /
  `payment.payable` s `totalPrice`, `paymentReference` (VS) a partnerem
  přes pin `_resolve.rows[i].partner` (viz §7). Převody peněz (#59 Task D)
  = řádky `operation: transfer.in` (jen `cashDirection: 1`) /
  `transfer.out` (jen 2) s `totalPrice` a volitelným `paymentReference`
  (staré `symbol1`/`symbol2`), bez partnera; `operation` je passthrough,
  směr vůči `cashDirection` hlídá validace dokladu. Zálohy (#59 Task E):
  `advance.received` (jen příjem) / `advance.given` (jen výdej) s
  `totalPrice`, povinným partnerem přes pin `_resolve.rows[i].partner` a
  volitelným `paymentReference`, bez DPH; záporná částka = vrácení. Odpočet
  `sale.advanceDeduction` / `purchase.advanceDeduction` je na `cashDocument`
  položkový řádek s DPH jako na faktuře (záporný základ). Stejné klíče platí i pro
  `operation` bankovní transakce (`shpd.bank.statement.v1`).
- **`cashRegisterDocument`** (`cashreg`, prodejka) — povinný `cashDesk`,
  bez `cashDirection` (pevně výstup); vratka = záporné řádky.
- **`invoiceIssued` / `invoiceReceived` + `cashDesk`** — volitelný kód
  pokladny platí jen s `payment.method: "cash"` (faktura placená hotově →
  `cash_desk` hlavičky, účtuje se na pokladnu místo 311/321); s jinou
  platbou se ignoruje s warningem `cash_desk_ignored`.
- **`creditNote*`** — bude rozšířeno o `relatedDocNumber` (originál).
- **`order*`, `deliveryNote*`, `bankStatement*`** — budoucí rozšíření,
  držíme stejnou top-level kostru.

Pole, která nedávají smysl pro daný `docType`, mají být `null` nebo
vynechána. Validace polymorfismu je v PHP
(`DocumentValidator::checkPerDocType()`), JSON Schema definuje jen společnou
strukturu.

## 6. Party object

```jsonc
{
  "name":     "Dodavatel s.r.o.",
  "country":  "CZ",                // ISO 3166-1 alpha-2, lowercase v canonical
                                    //   ("cz", "sk", "de", ...)

  // Identifiers — alespoň jeden silně doporučený, resolver je zkouší v pořadí
  "companyId": "12345678",         // IČO (CZ), Reg.č. (SK), USt-IdNr base (DE), …
  "taxId":     "CZ12345678",       // DIČ
  "vatId":     "CZ12345678",       // VAT ID pro EU (v CZ obvykle = taxId)

  "courtRegistration":
    "Obchodní rejstřík vedený MS v Praze, oddíl C, vložka 123456",

  "address": {
    "street":       "Hlavní",
    "houseNumber":  "1",
    "city":         "Praha",
    "cityPart":     "Nové Město",
    "zip":          "11000",
    "country":      "CZ",
    "registryCode": null,          // RÚIAN address code, CZ-specific
    "displayLine":  "Hlavní 1, 110 00 Praha 1"
  },

  "contact": {
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
řádek dokladu (`docs_core_heads.partner_balance`). Applier ji resolvuje
stejně jako `supplier`/`customer` (`_resolve.balanceParty`, `userAction`,
auto-create) a zapíše ji s `partner_balance_manual = 1` — odvození při
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

  // Item identification — resolver zkouší více cest, viz sekce 8
  "item": {
    "ourCode":      "K-001",       // economy_items.code
    "supplierCode": "KONZ-001",    // dodavatelský kód
                                    //   (per-partner mapování přes
                                    //    economy_items_supplier_codes)
    "sku":          "K-001-EN",    // optional
    "ean":          "8590123456789",
    "name":         "Konzultace",
    "description":  "Hodinová sazba senior konzultanta"
  },

  // Quantity & price
  "unit":          "h",            // ISO unit code nebo náš unit id;
                                    //   resolver mapuje na core_units.id
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
    "code": "highEU",              // klíč z per-country VAT codes
    "pct":  21                     // optional; resolver doplní z code+date
  },

  // Computed (informative)
  "computed": {
    "vatBase":   10330.58,
    "vatAmount": 2169.42,
    "vatTotal":  12500.00
  },

  // Kontace / saldo identita (účetní doklad, úhrady payment.* na pokladním
  // dokladu): částka přímo v totalPrice, strana a účet u kontace, VS / SS /
  // KS / splatnost úhrady. Partner řádku se NEPOSÍLÁ jako Party — pinuje
  // se přes `_resolve.rows[i].partner = "useExisting:<id>"` (exportér zná
  // id z LocalIdMap; PartyResolver je pro hlavičkové strany).
  "accSide":          null,          // "debit" | "credit" (jen kontace)
  "account":          null,          // číslo účtu (acc.record)
  "paymentReference": "2026000042",  // VS hrazeného dokladu
  "specificSymbol":   null,
  "constantSymbol":   null,
  "dueDate":          null
}
```

## 8. Resolve

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

1. **`(country, companyId)` exact match** → je-li 1 výsledek, `matched`
   (`matchedBy: "companyId"`).
2. **`(country, vatId)` exact match** → analogicky (`matchedBy: "vatId"`).
3. **`(country, taxId)` exact match** → analogicky (`matchedBy: "taxId"`).
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

Postup:

1. **`ourCode` exact match** v `economy_items.code` → `matched`,
   `matchedBy: "ourCode"`.
2. **`(supplier.personId, supplierCode)` lookup** v nové tabulce
   `economy_items_supplier_codes` → `matched`, `matchedBy: "supplierCode"`.
3. **`ean` exact match** v `economy_items.ean` (nový sloupec) → `matched`,
   `matchedBy: "ean"`.
4. **`sku` exact match** v `economy_items.sku` (nový sloupec) → `matched`,
   `matchedBy: "sku"`.
5. **`name` fuzzy** v `economy_items.name` + `description` → kandidáti.
6. Žádný kandidát → `canCreate` s payloadem připraveným pro vytvoření
   `economy_items` row (potřebuje uživatelské doplnění `item_kind`).

Per-partner mapování v `economy_items_supplier_codes` se buduje jednak ručně,
jednak applierem: když uživatel rozhodne "tato extrahovaná položka odpovídá naší
`K-001`" pro `supplierCode: "KONZ-001"` od `personId: 42`, applier zaznamená
mapping. Příště se napaří automaticky.

### 8.3 UnitResolver

Vstup: `unit` string.

Postup:

1. **ISO kód** (e.g. `"h"`, `"kg"`, `"pcs"`, `"l"`) — mapování v
   `core.units.unitsSeed` na `core_units.code`. → `matched`.
2. **Lokalizovaná zkratka** (`"ks"` → pcs, `"hod"` → h, `"l"` → l) přes
   alias tabulku v PHP resolveru.
3. Žádný match → `notFound`. Applier použije default unit (`pcs`) a vyrobí
   warning.

### 8.4 VatCodeResolver

Vstup: `vat.code` string + `vat.registrationCountry` + `dates.taxPointDate`.

Postup:

1. Volá existující `VatRateResolver::getVatCodes($country, ...)` (modul
   `world.vat`).
2. Lookup podle `vat.code` v vrácené mapě → `matched` s definicí kódu
   včetně `vat_pct`, `reverseVatCode`, `noPayTax` atd.
3. Pokud `vat.pct` v payloadu chybí, doplní z resolved code + date přes
   `VatRateResolver::resolveVatPct($country, $code, $date)`.
4. Žádný match → `notFound` + warning.

### 8.5 BankAccountResolver

Vstup: `Party.bankAccount` object + resolved `personId` (pokud existuje).

Postup pro **partner's bank** (resolved person je partner, ne my):

1. **`iban` exact match** v `base_persons_bank_accounts` filtrované na
   `person_id` → `matched`.
2. **`accountNumber` exact match** → analogicky.
3. Žádný match, ale partner existuje → `canCreate` (přidat účet partnerovi).
4. Partner ještě neexistuje (sám `canCreate`) → odložit do Apply fáze.

Postup pro **vlastní účet** (`selfParty == "supplier"` na FVB):

1. Lookup v `economy_codebooks_bank_accounts` podle `iban` /
   `accountNumber` / `currency` filteru.
2. Žádný match → `notFound` (vlastní účet musí být v codebooks, applier
   ho nevytváří automaticky).

## 9. `_resolve` state

Vyrábí ho `/preview`, čte `/apply`. Žije v stejném JSON dokumentu jako data —
klient drží jeden payload mezi step preview a apply.

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
      "item": {
        "status": "matched", "itemId": 18, "matchedBy": "ourCode"
      },
      "unit":     { "status": "matched", "unitId": 3, "matchedBy": "iso" },
      "vatCode":  { "status": "matched", "code": "highEU" }
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

  // Validation & sanity findings — chyby i warningy
  "issues": [
    {
      "severity": "warning",            // "error" | "warning" | "info"
      "path":     "totals.totalAmount",
      "code":     "totals_mismatch",
      "message":  "Deklarovaná částka 12500.00 neodpovídá vypočtené 12499.50.",
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

- `_resolve.rows[i].enrichment` — obohacení řádku z historie partnera
  nebo obsahové eskalace (viz `modules/core/mail/docs/ai-analysis.md`,
  sekce „Obohacení řádků z historie" a „Obsahová eskalace").
- `_resolve.contentTag` — dokument-level obsahový štítek
  (`{tag, tagSource: "rule"|"llm", ruleId? | tagConfidence?,
  promptVersion?, rowExceptions?}`), persistuje se při `/result`,
  fresh re-check pravidla IČO ho může přepsat
  (`tasks/content-tag-enrichment.md`).

### `userAction` slovník

| Hodnota | Význam |
|---------|--------|
| `null` | Default — applier použije resolved match. Pokud `status == "matched"`, OK; jinak chyba (`unresolved_required`). |
| `"useExisting:<id>"` | Použít konkrétního kandidáta z `candidates`. |
| `"create"` | Vytvořit novou entitu z payloadu (jen pro `canCreate`). |
| `"skip"` | Skipnout položku (jen pro řádky; pro hlavičkové reference je default `null`). |

Klient vyplňuje `userAction` mezi `/preview` a `/apply`. `/apply` aktion
zvalidnuje a buď uloží, nebo vrátí chybu se seznamem nerozhodnutých referencí.

### Issue codes — dokumenty

Kódy v `_resolve.issues[]` (`DocumentValidator` + `DocumentApplier`).
Errors blokují `/apply`, warningy jen informují v UI.

| `code` | Severity | Význam |
|--------|----------|--------|
| `required` | error | Chybí povinné pole per `docType` (issueDate, rows, supplier/customer). |
| `totals_mismatch` | warning | Deklarovaná `totals.totalAmount` neodpovídá žádné vypočtené variantě (Σ řádků, Σ řádků s DPH, Σ recap). |
| `rows_recap_mismatch` | warning | Součet položkových řádků neodpovídá rekapitulaci/totals dle efektivního režimu DPH — řádky nejspíš neúplné. |
| `vat_recap_inconsistent` | warning | Řádek rekapitulace vnitřně nesedí (`base + tax ≠ total` nebo `tax ≠ base × pct`) — recap dopočtený místo opsaného. |
| `vat_mode_derived` | warning | `DocumentApplier` koriguje `vat_mode` podle `VatModeDerivation` (Σ řádků sedí na total, ne na base — nebo zrcadlově). |
| `recap_source_computed_fallback` | info | Rekapitulaci nešlo převzít (prázdná, nekonzistentní, nebo bez dohledatelného DPH kódu) — spočítá se z řádků. Zpráva nese důvod. |
| `vat_mode_suspect` | warning | Řádky vypadají jako ceny s DPH při deklarovaném `fromBase`, ale derivace nemá dost dat na korekci. |
| `partner_doc_number_missing` | warning | Přijatá faktura cílí na stav ≥ 20 bez čísla dokladu dodavatele. |
| `row_operation_config_invalid` | warning | Pohyb řádku nejde doplnit — chybná konfigurace rowOperations. |
| `invalid_value` | error | `cashDirection` pokladního dokladu není 1 ani 2. |
| `cash_desk_ignored` | warning | `cashDesk` na faktuře bez `payment.method: "cash"` — pokladna se nepropíše. |

Apply-level kódy (`ApplyResult.errorCode`, 422): `number_series_not_found`,
`own_bank_account_not_found`, **`cash_desk_not_found`** (neznámý kód pokladny,
nebo pokladna mimo stav V pořádku (40), takže jí nelze založit řadu typu
`cash`/`cashreg`; pokladně ve stavu 40 applier chybějící řadu založí sám).

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
  ├─ 5. BEGIN TRANSACTION
  │
  ├─ 6. Side-creates (per execution plan)
  │      - canCreate Party → PersonDocument::saveDocument(...)
  │      - canCreate Item  → ItemDocument::saveDocument(...)
  │      - canCreate Bank  → BankAccountDocument::saveDocument(...)
  │      - per-partner item mapping → INSERT economy_items_supplier_codes
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
  │      → DocDocument::validate (+ subclass per docType)
  │      → DocDocument::beforeSave (snapshoty, recap, totals, čísla)
  │      → insert/update docs_core_heads + rows + vat_recap
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
  ├─ 11. COMMIT
  │
  └─ 12. Vrátí enriched canonical JSON
        - _resolve aktualizován: status="matched" pro všechny canCreate
          s novými id
        - docNumber doplněn z přidělené series (jen pokud confirm — viz níže)
        - savedDocId v top-level (FK na docs_core_heads.id)
```

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
    "rejectOnIssues": ["error"]      // ["error"] | ["error","warning"] | []
  }
}
```

Když `targetDocState` je 40, projde state transition v `DocDocument::processStateTransition`
(přidělí číslo z series, vyrobí snapshoty). Pokud kterákoliv povinná reference
chybí, applier selže (validace v `DocDocument::validate`).

`targetDocState: 80` (V opravě) je **parkovací cíl migrace** — validátor ho
povoluje jen v kombinaci s `applyOptions.importNumber` (jinak error
`target_state_80_requires_import`). Mimo migraci přes exchange dosažitelný
není; číslo v tom případě nese `importNumber`.

Snapshoty stran se v import módu (`importNumber` přítomné) **nestaví
z dnešního adresáře** — pro historické doklady by to bylo věcně špatně.
Strana partnera (`supplier`/`customer` dle `selfParty`) se místo toho
**mrazí do snapshotu dokladu** tak, jak přišla v payloadu (přeložená do
tvaru `PersonSnapshotBuilder`); za dobové hodnoty — především `vatId`,
ze kterého kontrolní hlášení čte DIČ partnera — odpovídá exportér.
Vlastní strana se staví standardně (dnešní adresářová data vlastní firmy
+ `bank_account` a `vat_registration` z hlavičky dokladu). Prázdná strana
partnera v payloadu = partnerský snapshot zůstává NULL (kanonické zdroje
bez stran, např. účetní doklady).

`applyOptions.importOwnBankAccount` (vlastní bankovní účet u vydaných
faktur ve stavu 40+) přijímá buď interní id, nebo **string = `code`
z číselníku `economy_codebooks_bank_accounts`** — přenosná varianta pro
datové sady (#40). Kód resolvuje `DocumentApplier` před transakcí; neznámý
kód = `own_bank_account_not_found` (422).

Opačný směr (DB → canonical) dělají exportery v
`modules/core/exchange/src/Export/` (`DocumentExporter`, `PersonExporter`,
`ItemExporter`) a `RegistryExporter` v `modules/base/registry/src/` —
zrcadlo `transform()` s referencemi externě (partner identifikátory,
položky `ourCode`, účty číslem, řada `numberSeriesCode`, vlastní účet
kódem). Konzument: datové sady, `shpd-ds dataset-dump`.

### `autoCreateMode`

Řídí, co se stane s `canCreate` referencí, na které klient nedoplnil
`userAction`:

| Mode | Chování |
|---|---|
| `strict` (default) | Bez explicit `userAction` → `422 unresolved_required`. Vhodné pro UI flow, kde uživatel rozhoduje. |
| `safe` | Autocreate, pokud `createPayload` splňuje per-tabulka guard (Party: `company_id`; Item: `name`; BankAccount: `iban` nebo `account_number`). Jinak `unresolved_required`. Vhodné pro AI flow (apply návrhu zprávy bez klientských userActions jede `safe`). |
| `liberal` | Autocreate vždy. Žádný safety guard. Pro budoucí B2B import / testovací cesty. |

`userAction` přebíjí mode — explicit `useExisting:<id>` nebo `create`
funguje stejně ve všech režimech.

## 11. REST API endpointy

Všechny pod `/api/v1/_exchange/docs/document/`. Auth: standardní (API key
nebo session token). Rate limit: standardní.

### POST `/validate`

Statická + dynamická validace bez resolve a bez DB writes.

**Request body:** canonical JSON.

**Response:** `{success, issues: [...]}`. Nepoužívá `_resolve` strukturu —
jen validační findings.

### POST `/preview`

Validate + resolve. Bez DB writes.

**Request body:** canonical JSON (libovolné `_resolve` od klienta se zahodí).

**Response:** enriched canonical s vyplněným `_resolve` na top-level a
issues uvnitř `_resolve.issues`.

### POST `/apply`

Validate + resolve + reconcile s klientským `_resolve.*.userAction` + uložit.

**Request body:** canonical JSON s vyplněným `_resolve.*.userAction` (pokud
preview indikoval `canCreate` / `ambiguous`).

**Response:** enriched canonical (jako z `/preview`), navíc:

- `savedDocId` (top-level) — id nového záznamu v `docs_core_heads`
- `_resolve.summary.status = "applied"`
- Nově vytvořené entity mají `_resolve.*.personId` / `itemId` / atd. vyplněné
- Pokud `targetDocState: 40`: `docNumber` doplněn z přidělené series

**Chybové stavy:**

- `400` — schema validation failure (chyba struktury)
- `422 unresolved` — applier běžel, ale narazil na nerozhodnuté reference
  bez `userAction`. Tělo obsahuje `_resolve` se seznamem.
- `422 validation` — `_resolve.issues` obsahuje severity=error mimo
  resolve scope (např. chybějící datum).
- `409 conflict` — během reconcile se zjistilo, že entita mezitím
  zmizela (`useExisting:42` ale person 42 už neexistuje).

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
| `source_kind` | `enumString(40) nullable` | `aiExtraction` / `isdoc` / `manual` / `import.flexibee` / … (řízeno cfgItem `docs.core.sourceKinds`) |
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

### `shpd.docs.bankStatement.v1`

Použití: import bankovních výpisů (XML/CSV od banky → kanonický → pokladní
doklad nebo párování plateb).

---

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
