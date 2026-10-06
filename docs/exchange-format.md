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
2 → `creditNote`. Řádky nesou i `computed` (základ, daň a cenu s daní, jak
je spočítal dodavatel) — cenu s daní z nich bere applier u přijatého
dokladu neplátce DPH (§ 8.4). Kompletní mapovací tabulka ISDOC → canonical:
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
    "registrationCountry": "CZ",  // ISO země naší registrace DPH. U přijatých
                                   //   dokladů (selfParty customer) ji applier
                                   //   IGNORUJE a bere první aktivní registraci
                                   //   zdroje (D2, info issue
                                   //   `vat_registration_country_derived` při
                                   //   rozporu); u ostatních dohledá
                                   //   economy_codebooks_vat_registrations.
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
      "vatCode": "cz-110", "vatPct": 21,
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

**Samovyměření (D3):** má-li kterýkoli položkový řádek — po odvození kódu
(§ 8.4) — kód s `reverseVatCode`, je rekapitulace přijatého dokladu vždy
`computed` + info issue s důvodem „přenesení daňové povinnosti“.
Rekapitulace dodavatele je z jeho pohledu (0 %, daň 0); naše nese nárok na
odpočet a oddaňovací pár. Explicitní `recapSource: "declared"` (import ze
starého Shipardu, s páry) zůstává beze změny.

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

`totals` zůstávají informativní vždy. Důvod, proč jsou obě pole v canonical:

- **UI náhled** — chce je zobrazit (AI extrahovala součty z PDF, uživatel
  je kontroluje).
- **Validace** — applier porovná deklarované totals s vypočtenými, pokud
  se liší, vyrobí warning `totals_mismatch` v `_resolve.issues`. Silný
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
- **`proformaIssued`** (`invpo`, zálohová faktura vydaná, #79 D1) — strany
  jako `invoiceIssued` (my `supplier`, partner `customer`, povinný).
  **Není daňový doklad** (`docTypes[].tax_document: false`):
  `dates.taxPointDate` / `dates.vatObligationDate` applier ignoruje
  a doklad nenese období DPH (`vat_period` / `cs_period` / `rs_period`
  zůstávají NULL, do tvrzení nevstupuje). Rekapitulace DPH a sazby řádků
  se zpracují jako u faktury. Řádky jen `sale.services` / `sale.goods`.
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
- **`invoiceIssued` / `proformaIssued` / `invoiceReceived` + `cashDesk`** —
  volitelný kód pokladny platí jen s `payment.method: "cash"` (faktura placená hotově →
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
    "code": "cz-110",              // klíč z per-country VAT codes; u přijatého
                                   //   dokladu smí být null — kód odvodí
                                   //   applier ze signálů (§ 8.4)
    "pct":  21,                    // optional; resolver doplní z code+date
    "supplyKind": null,            // goods | services | null — druh plnění,
                                   //   rozhoduje jen mimo tuzemsko; u přijatého
                                   //   dokladu bez hodnoty ho applier doplní
                                   //   ze štítku řádku (§ 8.4, warning
                                   //   `supply_kind_derived`)
    "reverseChargeCode": null      // kód předmětu plnění u tuzemského
                                   //   přenesení daňové povinnosti ("4"
                                   //   stavební práce, "5" příloha 5)
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

### Text řádku

Text, který skončí v `docs_core_rows.description`, skládá **jediný**
helper `Shipard\Module\Core\Exchange\Document\CanonicalRowText::compose($row)`
([tasks/exchange-row-text.md](../tasks/exchange-row-text.md) D1/D2, #84):

1. neprázdný top-level `description` má přednost (účetní doklad, export,
   dataset round-trip);
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

Vstup: `vat.code` string + země registrace + `dates.taxPointDate`.

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
(`cz-110` → `cz`) → země dodavatele. Import (`applyOptions.importNumber`)
platnost registrace nezkoumá: s registrací jde naší zemí, bez ní kaskádou.

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
`vat.place` (efektivní místo, viz výše), `vat.reverseCharge`, řádek `vat.pct`, `vat.supplyKind`,
`vat.reverseChargeCode` (`tasks/exchange-received-reverse-charge.md`
D1/D4). Kandidáti = vstupní kódy číselníku naší registrace pro místo
plnění, bez `hidden` a bez `reducedDeduction` (krácený odpočet se nikdy
neodvozuje):

- **samovyměření** (`reverseCharge: true`, nebo místo ≠ tuzemsko): kódy
  s `reverseVatCode` kategorie `standard`; mimo tuzemsko rozhoduje
  `supplyKind` (EU zboží `cz-215`, služby `cz-217`; třetí země služby
  `cz-417` — dovoz zboží se neodvozuje, viz níže), v tuzemsku
  `reverseChargeCode` (`4` → `cz-115`, `5` → `cz-117`);
- **tuzemsko bez samovyměření:** kód bez `reverseVatCode`, jehož sazba
  k DUZP = `pct` řádku (`cz-110`, `cz-111`, `cz-112`; historicky `cz-301`).

Kód vznikne jen z **právě jednoho** kandidáta. Odvozený kód dostane sazbu
z číselníku k DUZP — u samovyměření tedy 21, ne 0 z dokladu dodavatele.

| vstupní `vat.code` | derivace | výsledek v `_resolve.rows[].vatCode` |
|---|---|---|
| prázdný | kód | `matched`, `matchedBy: "derived"`, bez issue |
| platný a v souladu se signály | cokoli | beze změny (`matchedBy: "cfgItem"`) |
| neznámý, nebo v rozporu se signály | kód | odvozený kód + warning `vat_code_derived` (původní hodnota ve zprávě) |
| neznámý / prázdný | `null` | `notFound` + error `vat_code_unknown` s důvodem a „doklad založ ručně“ |
| prázdný, bez signálů | — | bez bloku `vatCode` (jako dřív) |
| libovolný, **volba uživatele** `userAction: "useCode:<kód>"` (#87 task B) | cokoli | zvolený kód, `matchedBy: "user"`, sazba z číselníku k DUZP; kód mimo `_resolve.vatCodeOptions` → `notFound` + error `vat_code_pin_invalid`; v rozporu se signály warning `vat_code_pin_conflict` (volba platí, zpráva nese odvozený kód) |

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
k DUZP, **včetně** kráceného odpočtu a dovozu zboží
(`VatCodeDerivation::options()`, táž množina validuje volbu). Volba kódu
(`rows[i].vatCode.userAction = "useCode:<kód>"`), místa plnění
(`_resolve.vat.place.userAction = "useValue:domestic|intracom|thirdCountry"`)
a režimu (`_resolve.vat.mode.userAction = "useValue:fromBase|fromTotal|none"`)
se uplatní ve stejném kontextu jako derivace: místo z volby přebije DIČ
i AI (`placeSource: "user"`, derivace místa se nespouští), kód z volby
přebije derivaci, canonical i historii, režim z volby přebije
`VatModeDerivation` (ochrana „Bez DPH“ + samovyměření → `fromBase` platí
i proti volbě, tiše). Rekapitulace (D3) i `_resolve.computed` volbu
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
  z AI i před `reverseCharge: true` — chyba je lepší než samovyměření tam,
  kde se daň v ČR nepřiznává. Kódu z historie řádků (`conflict()`) se
  veto netýká.

Mimo rozsah (derivace vrací `null` → `vat_code_unknown`): snížená sazba
u samovyměření, zahraniční DPH naúčtovaná dodavatelem z EU či třetí země
(hotel, PHM v cizině — `pct` > 0 bez `reverseCharge: true` není
samovyměření), PDP kódy mimo číselník, smíšené doklady, druh plnění
z textu řádku bez štítku. Vystavené a účetní doklady derivaci
nepoužívají.

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
      "rowText": "Konzultace — Hodinová sazba senior konzultanta",
                                          // text řádku, jak ho zapíše applier
                                          //   (CanonicalRowText, §7); u každého
                                          //   řádku, bez textu null
      "item": {
        "status": "matched", "itemId": 18, "matchedBy": "ourCode"
      },
      "unit":     { "status": "matched", "unitId": 3, "matchedBy": "iso" },
      "vatCode":  { "status": "matched", "code": "cz-110",
                    "userAction": null }  // "useCode:<kód>" = volba uživatele
                                          //   (#87 B) → matchedBy "user";
                                          //   matchedBy "derived", když kód
                                          //   odvodil applier (§ 8.4);
                                          //   supplyKindSource "tag", když
                                          //   druh plnění doplnil ze štítku
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
  // doklad neplátce DPH, vždy "none" — § 8.4). auto = hodnota bez volby
  // uživatele (select „Automaticky (…)“ ji ukazuje i po volbě). Volba:
  // userAction "useValue:<hodnota>" na vat.place / vat.mode (vstup; v
  // odpovědi není).
  "vat": {
    "place": { "value": "domestic", "source": "user",    "auto": "intracom" },
    "mode":  { "value": "fromBase", "source": "derived", "auto": "fromBase" }
  },

  // Nabídka kódů DPH pro ruční volbu řádků (#87 B, D13) — jen přijatý
  // doklad na zdroji s registrací DPH; kandidáti k efektivnímu místu
  // (po volbě) a DUZP. Mimo tuto větev blok chybí.
  "vatCodeOptions": [
    { "code": "cz-217", "label": "EU/Vstup/Služby/Základní", "pct": 21,
      "reverseCharge": true, "reducedDeduction": false, "supplyKind": "services" },
    { "code": "cz-218", "label": "EU/Vstup/Služby/Snížená",  "pct": 12,
      "reverseCharge": true, "reducedDeduction": false, "supplyKind": "services" }
  ],

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
  cen z canonicalu. Jen `/preview`: `transform()` s náhledovým plánem (kódy
  DPH a jednotky z čerstvého resolve, bez založených entit a řady) →
  `TableGateway::createDocument()` → `DocDocument::computeAmounts()`
  — stejný kód jako `beforeSave()` při apply, včetně přetížení podtříd
  (účetní doklad sčítá z řádků, `vatRecap` prázdné). Při výjimce `null`
  + info `computed_unavailable`. Náhled z něj zobrazuje rekapitulaci,
  součty a sazbu řádků (`_resolve.rows[i].vatCode.createPayload`);
  canonical `vatRecap` / `totals` jen jako fallback a při
  `computed_total_mismatch` jako „na dokladu dodavatele“
  (`tasks/exchange-preview-vat-recompute.md`).

### `userAction` slovník

| Hodnota | Význam |
|---------|--------|
| `null` | Default — applier použije resolved match. Pokud `status == "matched"`, OK; jinak chyba (`unresolved_required`). |
| `"useExisting:<id>"` | Použít konkrétního kandidáta z `candidates`. |
| `"create"` | Vytvořit novou entitu z payloadu (jen pro `canCreate`). |
| `"skip"` | Skipnout položku (jen pro řádky; pro hlavičkové reference je default `null`). |
| `"useCode:<kód>"` | Jen `rows[i].vatCode` (#87 task B): zvolený kód DPH řádku z `_resolve.vatCodeOptions`. |
| `"useValue:<hodnota>"` | Jen `_resolve.vat.place` (`domestic` / `intracom` / `thirdCountry`) a `_resolve.vat.mode` (`fromBase` / `fromTotal` / `none`) (#87 task B). |

Klient vyplňuje `userAction` mezi `/preview` a `/apply`. `/apply` aktion
zvalidnuje a buď uloží, nebo vrátí chybu se seznamem nerozhodnutých referencí.

### Issue codes — dokumenty

Kódy v `_resolve.issues[]` (`DocumentValidator` + `DocumentApplier`).
Errors blokují `/apply`, warningy jen informují v UI.

| `code` | Severity | Význam |
|--------|----------|--------|
| `required` | error | Chybí povinné pole per `docType` (issueDate, rows, supplier/customer). |
| `author_not_found` | error | `applyOptions.author` není id existujícího uživatele (#93 D9). `null` a chybějící klíč se nekontrolují. |
| `totals_mismatch` | warning | Deklarovaná `totals.totalAmount` neodpovídá žádné vypočtené variantě (Σ řádků, Σ řádků s DPH, Σ recap). Heuristika validátoru; v `/preview` ji při dostupném `_resolve.computed` nahrazuje `computed_total_mismatch`. |
| `computed_total_mismatch` | warning | Jen `/preview`: částka k úhradě podle skutečného výpočtu dokladu (`_resolve.computed.totals.totalAmount`) se od `totals.totalAmount` liší o víc než 0,01. Nese `declared` a `computed`; zpráva příčinu nehádá, jen vyzve ke kontrole řádků a režimu DPH. U samovyměření se liší daň, k úhradě sedí — warning nepadne. |
| `computed_unavailable` | info | Jen `/preview`: výpočet `_resolve.computed` selhal výjimkou (zalogováno) — blok je `null`, náhled ukazuje údaje z canonicalu. |
| `rows_recap_mismatch` | warning | Součet položkových řádků neodpovídá rekapitulaci/totals dle efektivního režimu DPH — řádky nejspíš neúplné. |
| `vat_recap_inconsistent` | warning | Řádek rekapitulace vnitřně nesedí (`base + tax ≠ total` nebo `tax ≠ base × pct`) — recap dopočtený místo opsaného. |
| `vat_mode_derived` | warning | `DocumentApplier` koriguje `vat_mode` podle `VatModeDerivation` (Σ řádků sedí na total, ne na base — nebo zrcadlově); nebo deklarované `none` u dokladu, jehož řádky mají kód se samovyměřením → `fromBase` (Bez DPH by rekapitulaci nestavěl). |
| `recap_source_computed_fallback` | info | Rekapitulaci nešlo převzít (prázdná, nekonzistentní, bez dohledatelného DPH kódu, nebo samovyměření — D3) — spočítá se z řádků. Zpráva nese důvod. |
| `vat_code_unknown` | error | Kód DPH řádku není v číselníku země registrace a nejde odvodit ze signálů (§ 8.4). Zpráva nese důvod; blokuje apply. |
| `vat_code_derived` | warning | Kód DPH řádku byl neznámý nebo v rozporu se signály dokladu — nahrazen odvozeným (`VatCodeDerivation`). Zpráva nese původní hodnotu. |
| `vat_code_pin_invalid` | error | Zvolený kód DPH řádku (`useCode:`) není v `_resolve.vatCodeOptions` k efektivnímu místu a DUZP — blokuje apply; vyber jiný (#87 B). |
| `vat_code_pin_conflict` | warning | Zvolený kód DPH řádku odporuje signálům dokladu (`VatCodeDerivation::conflict()`); volba platí, zpráva nese důvod a odvozený kód (#87 B). |
| `vat_pin_invalid` | error | Neplatná hodnota nebo akce volby DPH (`useValue:` mimo enum, `useCode:` bez kódu, jiná akce); path dle volby (#87 B). |
| `vat_pin_ignored` | info | Volba DPH mimo větev derivace (vystavený doklad, přijatý doklad neplátce DPH) — ignorována; path dle volby (#87 B). |
| `supply_kind_derived` | warning | Řádek přijatého dokladu mimo tuzemsko bez `vat.supplyKind` — druh plnění doplněn ze štítku řádku (`crossBorderSupply` taxonomie, § 8.4). Zpráva nese název štítku a druh; path `rows.N.vat.supplyKind`. Hlásí se jen, když na doplněném druhu výsledek stojí (odvozený kód nebo `vat_code_unknown`). |
| `vat_registration_country_derived` | info | `vat.registrationCountry` přijatého dokladu neodpovídá naší registraci — nepoužije se (D2). |
| `vat_place_derived` | warning | Místo plnění přijatého dokladu odvozené z prefixu DIČ dodavatele (`VatPlaceDerivation`) se liší od neprázdné hodnoty `vat.place` z AI — použije se odvozené. Zpráva nese obě hodnoty a prefix. |
| `vat_place_unknown` / `vat_mode_unknown` | warning | Neznámá hodnota `vat.place` / `vat.mode` — fallback tuzemsko / fromBase. Schéma má obě pole jako enum, takže jen mimo schema validaci. |
| `vat_mode_suspect` | warning | Řádky vypadají jako ceny s DPH při deklarovaném `fromBase`, ale derivace nemá dost dat na korekci. |
| `vat_non_payer` | info | Přijatý doklad, ke kterému neplatí žádná registrace DPH, a doklad nese daň dodavatele: vznikne Bez DPH s daní v cenách řádků (§ 8.4, #97). Path `vat.mode`. |
| `non_payer_reverse_charge` | warning | Přijatý doklad neplátce nese přenesení daňové povinnosti — daň se nevyměří; identifikovaná osoba ji musí přiznat mimo doklad (#97 D9). Path `vat.reverseCharge`. |
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
nebo session token). Rate limit: standardní. Sesterské flow sdílejí
dispatcher: `/_exchange/persons/person/`, `/_exchange/items/item/`,
`/_exchange/bank/statement/`, import uživatelů `/_exchange/users/user/`
(jen `validate` + `apply`, admin nebo API klíč — §14 → Uživatelé) a import
majetku `/_exchange/assets/asset/` + `/_exchange/assets/doc-links/apply`
(stejná oprávnění — §14 → Majetek).

### POST `/validate`

Statická + dynamická validace bez resolve a bez DB writes.

**Request body:** canonical JSON.

**Response:** `{success, issues: [...]}`. Nepoužívá `_resolve` strukturu —
jen validační findings.

### POST `/preview`

Validate + resolve. Bez DB writes.

**Request body:** canonical JSON. Z klientského `_resolve` se čtou jen
`contentTag` a volby DPH (`rows[i].vatCode.userAction`, `vat.place` /
`vat.mode.userAction`, #87 task B); odpověď nese čerstvý `_resolve`,
takže `userAction` se v ní neobjeví — mapu rozhodnutí drží klient.

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
              "asset": 15, "orderHint": 3, "sourceRef": "row:812" } ] }
```

Odpověď `{ "status": "linked" | "unchanged" | "ambiguous" | "notFound" |
"conflict" | "turnover_changed" | "accounting_failed", "docId", "rows":
[{index, sourceRef, rowId, status}], "head"? }` — stav párování je obsah
odpovědi (HTTP 200), chybný tvar 400 s `details.issues`. Doklad se mění
celý, nebo vůbec; ve stavu 40 se přegeneruje deník s pojistkou shodných
obratů. Pravidla párování a uvolněné validace importu: `docs/assets.md`
§5.7.

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
