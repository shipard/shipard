# Modul `mail` — Deterministický import ISDOC příloh místo AI analýzy

**Stav:** hotovo

**Cíl:** Když došlá zpráva obsahuje v příloze ISDOC (český standard
e-fakturace, XML), extrahovat doklad **deterministicky parserem** místo
AI analýzy. ISDOC nese autoritativní strukturovaná data — LLM extrakce
je u něj zbytečná (cena, latence, riziko chyb). Zpráva s úspěšně
naparsovaným ISDOCem do AI fronty vůbec nejde.

**Motivace z reálných dat (alpha, 14. 7. 2026):** 822 příloh `.isdoc`
napříč 4 DS (firma 485, DS A 170, DS B 107, DS C 60).
Typický vzor: e-mail nese ISDOC + PDF vizualizaci téže faktury.

**Návaznost:**

- Navazuje na `tasks/mail-phase3a.md` (AI analýza, pull-based protokol)
  a `tasks/mail-states-and-classification.md` (`analysis_state`,
  `primary_type_source`).
- Canonical formát s ISDOCem už počítá: `source.kind = 'isdoc'` je
  vyjmenován v `docs/exchange-format.md` §5 i v enum
  `docs_core_heads.source_kind` (cfgItem `docs.core.sourceKinds`).
- Externí analyzer daemon (`ai_analyzer`) **nevyžaduje změny** — zpráva
  s ISDOCem se ve frontě prostě neobjeví (`analysis_state` přeskočí 10).
- Nezávislé na opravách AI schema erroru (enum `kind`, nullable `vat`,
  `courtRegistration` v promptu) — ty řeší samostatný budoucí task.
- Dokumentaci aktualizovat: `docs/exchange-format.md`,
  `modules/core/mail/docs/ai-analysis.md`,
  `modules/core/mail/docs/documentation.md`.

---

## Klíčová rozhodnutí (potvrzena Annou 14. 7. 2026)

1. Po úspěšném ISDOC importu `analysis_state = 30` (Analyzováno) —
   žádná nová hodnota stavu. Provenance je vidět v tabu Analýzy
   (`model_name = 'isdoc'`). Reanalyze (30 → 10) zůstává jako úniková
   cesta k AI, kdyby ISDOC výsledek nestačil.
2. Do cfgItem `core.mail.primaryTypeSources` přibude hodnota `isdoc`;
   import nastaví `primary_type = 'invoiceReceived'` +
   `primary_type_source = 'isdoc'` — **jen pokud** aktuální source
   není `user` (ruční volba má vždy přednost, stejné pravidlo jako AI).
3. Mix příloh (MVP): parsuje se **každá** ISDOC příloha zprávy; když
   aspoň jedna projde, AI analýza se přeskočí úplně. Teoretický případ
   „ISDOC faktura + jiná faktura jen v PDF" řeší uživatel ručně přes
   „Znova analyzovat".
4. Typy dokladů (MVP): `DocumentType` 1 (faktura) → `invoiceReceived`,
   2 (dobropis) → `creditNote`. Jakýkoli jiný typ (zálohová, doklad
   k přijaté platbě, …) = ISDOC větev se pro celou zprávu vzdá a zpráva
   jde normálně do AI fronty. (Číselník ověřit proti spec ISDOC 6.0.1
   na isdoc.cz při implementaci.)
5. Podpora `.isdoc` **i** `.isdocx` (ZIP obal) hned v MVP.
6. Import běží **server-side v intake** (`POST /_mail/incoming`),
   po commitu intake transakce, ve vlastní transakci s guardem —
   viz Datový tok. Deterministika nepotřebuje AI backend ani profil,
   funguje i v DS bez nakonfigurované AI.
7. Backfill existujících zpráv se neřeší (konzistentně s rozhodnutím 5
   z mail-states-and-classification).

## Mimo scope

- ISDOC vložený uvnitř PDF (isdocpdf) — future work.
- Ostatní `DocumentType` (zálohové faktury, doklady k platbě) — future.
- Deduplikace podle ISDOC `UUID` — `UUID` se ukládá do `source.raw`
  pro budoucí použití, ale nekontroluje se.
- Generování ISDOC z canonical (opačný směr, e-fakturace odchozí).
- Opravy AI extrakce (schema enum `kind`, nullable `vat`, prompt) —
  samostatný task.

---

## Datový tok

```
POST /_mail/incoming (MailController::receiveIncoming)
  ├─ [beze změny] intake tx: insert message (analysis_state dle
  │  resolveInitialAnalysisState, typicky 10) + upload příloh + commit
  │
  └─ [NOVÉ, po commitu] IsdocImportService::tryImport($messageId, $uploadedFiles)
       ├─ detekce kandidátů: přípona .isdoc/.isdocx (case-insensitive)
       │  NEBO mime XML se sniffem root elementu
       │  {http://isdoc.cz/*}Invoice; .isdocx → ZipArchive → *.isdoc entry
       ├─ žádný kandidát → return (nic se neděje, AI fronta beze změny)
       ├─ IsdocReader::fromFile() → canonical array (source.kind='isdoc')
       │    parse/mapping chyba, neznámý DocumentType → celá větev
       │    končí: log + return (zpráva zůstává ve frontě pro AI)
       ├─ SchemaValidator::validate(canonical) → invalid = konec větve
       ├─ RowHistoryEnricher::enrich(canonical) (persist, jako /result;
       │    selhání enrichmentu ne-fatální — log + neobohacený canonical)
       └─ vlastní tx:
            ├─ SELECT analysis_state FROM messages WHERE id=? FOR UPDATE
            │    guard: pokud NOT IN (0, 10) → rollback, konec (mezitím
            │    si zprávu stihl claimnout analyzer — nechat mu ji)
            ├─ INSERT core_mail_message_analyses
            │    (status=2, model_name='isdoc', model_version=@version
            │     z XML, prompt_version='isdoc', cost/tokens NULL,
            │     duration_ms měřeno, confidence=1.0,
            │     extracted_document_count=N)
            ├─ INSERT core_mail_extracted_documents (per ISDOC)
            │    (analysis=↑, doc_type dle DocumentType, confidence=1.0,
            │     source_attachments=[att id ISDOC souboru],
            │     status: mapConfidenceToStatus + strop D7 → typicky
            │     ready_to_apply, bez ourCode pending_review)
            ├─ UPDATE message: analysis_state=30, needs_reanalysis=0,
            │    primary_type='invoiceReceived' + primary_type_source='isdoc'
            │    (jen pokud primary_type_source != 'user'),
            │    docState 10→20 jen pokud je stále 10
            └─ commit
```

Invarianty:

- **ISDOC větev nikdy nesmí shodit příjem pošty** — všechno za commitem
  intake tx, `tryImport` polyká všechny výjimky
  (`ErrorLogger::logException` + fallback do AI fronty).
- Parse + validace + enrichment běží **před** otevřením zápisové tx —
  v tx jsou jen rychlé zápisy, žádné parsování.
- Guard `FOR UPDATE` řeší závod s analyzerem (okno mezi commitem intake
  a začátkem importu): stav 20 = claim vyhrál, import se vzdá.
- Endpointu `POST /_mail/import` (JSON bez příloh) se task netýká.

## Mapování ISDOC 6.x → `shpd.docs.document.v1`

Ověřeno proti reálnému souboru (ISDOC 6.0.1, namespace
`http://isdoc.cz/namespace/2013`). Zásada: mapovat jen to, co v ISDOC
opravdu je; chybějící pole vynechat (canonical je má nullable).

| Canonical | ISDOC zdroj | Poznámka |
|---|---|---|
| `source.kind` | — | konstanta `'isdoc'` |
| `source.extractedAt` | — | now, ISO 8601 |
| `source.confidence` | — | `1.0` |
| `source.mailMessage` | — | ndx zprávy |
| `source.raw` | `@version`, `DocumentType`, `UUID`, `ID` | audit + budoucí dedup |
| `docType` | `DocumentType` | 1→`invoiceReceived`, 2→`creditNote`, jinak abort |
| `docNumber` | `ID` | |
| `docText` | `Note` | |
| `selfParty` | — | konstanta `'customer'` |
| `supplier` | `AccountingSupplierParty/Party` | viz Party níže |
| `customer` | `AccountingCustomerParty/Party` | informativně |
| `dates.issueDate` | `IssueDate` | |
| `dates.taxPointDate` | `TaxPointDate` | |
| `dates.dueDate` | `PaymentMeans/Payment/Details/PaymentDueDate` | |
| `currency` | `ForeignCurrencyCode` ?? `LocalCurrencyCode` | viz Cizí měna |
| `exchangeRate` | `CurrRate` / `RefCurrRate` | jen u cizí měny |
| `vat.registrationCountry` | země dodavatele | lowercase |
| `payment.method` | `PaymentMeansCode` | 42→`bankTransfer`, jinak null |
| `payment.paymentReference` | `Details/VariableSymbol` | |
| `payment.constantSymbol` | `Details/ConstantSymbol` | |
| `payment.specificSymbol` | `Details/SpecificSymbol` | |
| `rows[]` | `InvoiceLines/InvoiceLine` | viz Řádky |
| `vatRecap[]` | `TaxTotal/TaxSubTotal` | pct=`TaxCategory/Percent`, base=`TaxableAmount`, tax=`TaxAmount`, total=`TaxInclusiveAmount` |
| `totals.totalBase` | `LegalMonetaryTotal/TaxExclusiveAmount` | |
| `totals.totalVat` | `TaxTotal/TaxAmount` | |
| `totals.totalAmount` | `LegalMonetaryTotal/PayableAmount` | |
| `totals.totalRounding` | `LegalMonetaryTotal/PayableRoundingAmount` | |
| `attachments[]` | — | jeden záznam: ISDOC soubor, `kind:'structured'` (do 2026-07 `'original'`; enum rozšířen ve spec mail-analysis-schema-fixes), `ref:'att:<id>'`, `filename`, `mimeType` |

**Party** (`supplier` / `customer`):

| Canonical | ISDOC |
|---|---|
| `name` | `PartyName/Name` |
| `companyId` (IČO) | `PartyIdentification/ID` |
| `taxId` + `vatId` (DIČ) | `PartyTaxScheme/CompanyID` kde `TaxScheme` = VAT |
| `courtRegistration` | `RegisterIdentification/Preformatted` |
| `country` | `PostalAddress/Country/IdentificationCode` lowercase |
| `address.*` | `PostalAddress` (`StreetName`, `BuildingNumber`, `CityName`, `PostalZone`) |
| `contact.*` | `Contact` (`ElectronicMail`, `Telephone`) |
| `bankAccount` | `PaymentMeans/Payment/Details`: `accountNumber` = `ID`/`BankCode`, `iban` = `IBAN`, `bic` = `BIC` |

**Řádky** (`InvoiceLine` → row):

- `rowKind = 'item'`, `orderPos` = pořadí (1-based)
- `quantity` = `InvoicedQuantity` (+ atribut `unitCode` → `unit`)
- `unitPrice` = `UnitPrice`, `totalPrice` = `LineExtensionAmount`
  (u cizí měny `*Curr` varianty), `priceCalcMode = 'fromUnitPrice'`
- `vat.pct` = `ClassifiedTaxCategory/Percent`; `vat.code` **nemapovat**
  (doplní RowHistoryEnricher z historie, případně uživatel při review)
- `computed.vatBase` = `LineExtensionAmount`, `computed.vatAmount` =
  `LineExtensionTaxAmount`, `computed.vatTotal` =
  `LineExtensionAmountTaxInclusive` (u cizí měny `*Curr` varianty);
  chybějící element se vynechá. Cenu s daní z nich bere applier u zdroje
  dat, který není plátcem DPH (#97)
- `item.name` = `Item/Description`,
  `item.supplierCode` = `Item/SellersItemIdentification/ID`

**Cizí měna:** pokud existuje `ForeignCurrencyCode`, doklad je v cizí
měně → `currency` = foreign, `exchangeRate` = `CurrRate`/`RefCurrRate`,
částky (řádky, recap, totals) brát z `*Curr` elementů. Jinak
`currency` = `LocalCurrencyCode` a základní elementy. Pokrýt testem.

---

## Implementační kroky

### 1. `IsdocReader` (exchange)

`modules/core/exchange/src/Isdoc/IsdocReader.php` — čistá konverze
bez DB závislostí:

- `fromFile(string $path, string $filename): array` — dle přípony
  rozbalí `.isdocx` (`ZipArchive`, první `*.isdoc` entry; prázdný nebo
  vadný ZIP → výjimka) a deleguje na `fromXmlString`.
- `fromXmlString(string $xml): array` — `DOMDocument` s vypnutými
  external entities (`LIBXML_NONET`, žádný `loadXML` s DTD — XXE!),
  namespace-aware čtení (namespace matchovat prefixem
  `http://isdoc.cz/` — verze namespace se může lišit), mapování dle
  tabulky výše, návrat canonical array.
- `IsdocParseException` (`modules/core/exchange/src/Isdoc/`) pro
  všechny chyby: nevalidní XML, cizí root element, neznámý
  `DocumentType` (message obsahuje nalezenou hodnotu), chybějící
  povinné elementy (`ID`, `DocumentType`).
- Číselné hodnoty: `(float)`, částky zaokrouhlovat nechat na
  DocDocument (applier nesestupuje pod Document — exchange-format §3).

**Unit testy** `modules/core/exchange/tests/Isdoc/IsdocReaderTest.php`
s fixtures v `tests/Isdoc/fixtures/`:

- minimální faktura (DocumentType 1, CZK, 1 řádek)
- dobropis (DocumentType 2)
- cizí měna (EUR, `*Curr` částky, `CurrRate`)
- plná faktura (adresy, rejstřík, bankovní účet, VS/KS/SS, více sazeb)
- `.isdocx` ZIP
- chybové: vadné XML, root mimo isdoc.cz, DocumentType 4, prázdný ZIP

**Fixtures vytvořit synteticky** (smyšlená firma, smyšlené IČO/DIČ/IBAN)
— NEKOPÍROVAT reálné soubory z alfy, obsahují skutečná osobní data.

### 2. Sdílený resolver statusu extracted dokumentu

Z `AnalysisController` extrahovat logiku `mapConfidenceToStatus` +
strop D7 (`ready_to_apply` → `pending_review` když existuje item řádek
bez `item.ourCode`; helper `RowHistoryEnricher::rowExpectsItem`) do
`modules/core/mail/src/ExtractedDocumentStatusResolver.php`.
`AnalysisController` i nový import ji sdílí — žádná duplikace pravidel.
Thresholds: import použije stejný zdroj jako result
(`resolveThresholds` default profilu; když profil neexistuje,
fallback konstanty `{ready: 0.9, review: 0.6}` — import běží
i bez AI profilu).

### 3. `IsdocImportService` (mail)

`modules/core/mail/src/IsdocImportService.php`:

- `tryImport(int $messageNdx, array $uploadedFiles): bool` — orchestrace
  přesně dle Datového toku; `$uploadedFiles` = návraty
  `AttachmentService::upload` z intake (id, file_name, mime_type,
  cesta na disku).
- Detekce kandidáta: přípona `.isdoc`/`.isdocx` (case-insensitive),
  nebo mime `application/xml`/`text/xml` + sniff root elementu.
- Zápisová tx s `FOR UPDATE` guardem (`analysis_state IN (0, 10)`).
- Vše ve vnějším try/catch — návrat `false` = zpráva zůstává v AI
  frontě; nikdy nepropaguje výjimku.
- Konstruktor: `DataSourceConnection`, `SchemaValidator`,
  `?RowHistoryEnricher`, `ExtractedDocumentStatusResolver`, `$dsPath`.

**Testy** `modules/core/mail/tests/IsdocImportServiceTest.php`:
detekce (pozitivní/negativní), úspěšný import (analysis row +
extracted + stavy + primary_type), guard (state 20 → skip),
`primary_type_source='user'` se nepřepíše, parse fail → fronta
beze změny, docState != 10 se nemění, funguje bez AI profilu.

### 4. Zapojení do intake

`src/Api/Controller/MailController.php` (`receiveIncoming`): po
`$dibi->commit()` zavolat `IsdocImportService::tryImport($messageId,
$uploadedFiles)`. Service instancovat lazy až při prvním kandidátu
(intake bez ISDOC nesmí platit režii wiringu). Wiring závislostí
v `public/index.php` po vzoru `AnalysisController` (SchemaValidator +
volitelný RowHistoryEnricher s degradací bez ConfigRuntime).
Response intake se nemění (`ndx`, `message_id`) — výsledek importu
se do response nepropaguje, klient (mail-router) ho nepotřebuje.

### 5. Config

`modules/core/mail/config/primaryTypeSources.jsonc` — přidat:

```jsonc
"isdoc": { "name": "ISDOC import", "name:cs": "ISDOC import",
           "name:en": "ISDOC import", "order": 25 }
```

Vyžaduje `ds-upgrade` (spouští Anna ručně). Sloupec
`primary_type_source` je `enumString(10)` — hodnota `isdoc` se vejde.

### 6. UI kontrola (očekávaně beze změn)

Tab Analýzy zobrazuje `model_name`/`prompt_version` generic — záznam
`isdoc` se ukáže sám. Badge `analysis_state=30` funguje. Karta
extracted dokumentu, Použít/Zamítnout, auto-transition 20→40,
dashboard karty — vše přes existující cesty. Ověřit ručně v prohlížeči,
kód frontendu neměnit (pokud se neobjeví konkrétní problém).

### 7. Dokumentace

- `docs/exchange-format.md` — sekce o adaptérech: ISDOC už není
  „future", odkázat na `IsdocReader` + mapovací tabulku.
- `modules/core/mail/docs/ai-analysis.md` — nová sekce „Deterministický
  ISDOC import" (datový tok, vztah k frontě, reanalyze jako fallback).
- `modules/core/mail/docs/documentation.md` — zmínka v přehledu
  zpracování došlé zprávy.
- `tables/core_mail_message_analyses.md` (pokud existuje) —
  `model_name` může být `isdoc`.

## Doporučené pořadí commitů

1. `feat(exchange): IsdocReader — konverze ISDOC 6.x na canonical` (krok 1 + testy)
2. `refactor(mail): sdilene mapovani confidence na status extracted dokumentu` (krok 2)
3. `feat(mail): deterministicky import ISDOC priloh misto AI analyzy` (kroky 3–5 + testy)
4. `docs(mail): ISDOC import` (krok 7)

## Akceptace

1. Zpráva s `.isdoc` přílohou (faktura): žádný AI claim nevznikne,
   `analysis_state=30`, v tabu Analýzy záznam `isdoc` s cost NULL,
   extracted dokument `invoiceReceived` s confidence 1.0
   (`ready_to_apply`, resp. `pending_review` bez ourCode), docState
   Nová → K řešení, `primary_type='invoiceReceived'` se source `isdoc`.
2. Totéž pro `.isdocx`.
3. „Použít" na extracted dokumentu vytvoří doklad
   s `source_kind='isdoc'` a správnými částkami/DPH/VS.
4. Dobropis (DocumentType 2) → extracted `creditNote`.
5. Faktura v EUR → `currency='EUR'`, `exchangeRate` vyplněn, částky
   z `*Curr` elementů.
6. Vadný ISDOC / DocumentType 4: zpráva skončí s `analysis_state=10`
   (AI fronta), v logu warning, příjem pošty neselže.
7. Zpráva bez ISDOC příloh: chování beze změny (regrese intake testů).
8. `primary_type_source='user'` import nepřepíše.
9. Import funguje v DS bez aktivního AI backendu/profilu.
10. „Znova analyzovat" na ISDOC-importované zprávě ji pošle do AI
    fronty (existující mechanismus, jen ověřit).
11. `php -l`, PHPUnit (exchange + mail), frontend build zelené.
