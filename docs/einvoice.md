# Strukturovaná e-faktura — ISDOC (#119)

Jak je udělaný generátor ISDOC z canonicalu, co čtečka čte zpět a co
spojuje korpus testovacích dokladů s e-fakturou. Fáze 1 (D1–D10,
`tasks/einvoice-phase1.md`); fáze 2 (vložení do PDF, příloha e-mailu,
výběr formátu podle stran) je v § 9.

## 1. Účel a rozsah (D1, D4, D5, D10)

ISDOC je český standard elektronické fakturace (XML, verze 6.x). Shipard
ho **čte** z došlé pošty (`IsdocReader`, deterministický import místo AI
analýzy — `docs/exchange-format.md` § 4, `modules/core/mail/docs/ai-analysis.md`)
a od fáze 1 #119 ho i **píše** (`IsdocWriter`) — pro korpus testovacích
dokladů (`docs/test-corpus.md`) a ve fázi 2 pro tisky vydaných dokladů.

ISDOC má smysl **jen mezi dvěma českými subjekty s IČO** (D10): zahraničnímu
partnerovi ani soukromé osobě k ničemu není. Generátor proto vyžaduje IČO
a úplnou adresu obou stran; prázdné smějí zůstat jen prvky bankovního
spojení, které nemáme (název banky, BIC). V korpusu mají podobu PDF + ISDOC
jen druhé doklady variant 1–4, 7 a 10.

V1 generuje jen **doklady v Kč** (D4 — ISDOC chce všechny částky v tuzemské
měně a jednotkovou cenu v cizí měně nemá) a jen typy **faktura** (`DocumentType`
1 — `invoiceIssued` / `invoiceReceived`) a **zálohová faktura** (4 —
`proformaIssued` / `proformaReceived`) (D5). Opravné doklady (2, 3) přijdou
s #92; pokladní doklad, prodejka ani zjednodušený daňový doklad ISDOC
nedostanou.

## 2. Třídy (`modules/core/exchange/`)

| Třída | Role |
|---|---|
| `EInvoice\EInvoiceWriter` | rozhraní formátu: `format(): string`, `write(array $canonical, EInvoiceOptions): string` |
| `EInvoice\EInvoiceFormats` | registr `isdoc` → `IsdocWriter`; místo pro další formáty (EN 16931 — UBL / CII, Peppol) |
| `EInvoice\EInvoiceOptions` | readonly volby: `uuid`, `countryNames`, `issuingSystem` (výchozí `Shipard`, bez verze a commitu) |
| `EInvoice\CountryNames` | názvy zemí nad cfgItem `world.base.countries` (`name:cs`); CLI je staví z modulového souboru (`fromModuleFile`), tisk z konfigurace zdroje dat |
| `EInvoice\EInvoiceInputException` | seznam `{path, message}` — všechny nálezy najednou, cesta jako v canonicalu (`supplier.companyId`, `rows[2].computed.vatAmount`) |
| `EInvoice\EInvoiceUuid` | UUIDv5 (RFC 9562, SHA-1) nad pevným jmenným prostorem Shipardu (§ 6) |
| `Isdoc\IsdocWriter` | canonical → ISDOC 6.0.1 (`DOMDocument`, namespace `http://isdoc.cz/namespace/2013`) |
| `Isdoc\IsdocSchemaValidator` | validace proti lokálním XSD 6.0.1 a 6.0.2 (`validate`, `validateAll`), `LIBXML_NONET` |
| `Isdoc\IsdocReader` | ISDOC → canonical (import pošty; rozšířen o to, co generátor píše — § 5) |

Generátor je **čistá funkce** (D1): bez databáze a konfigurace zdroje dat.
Stejný vstup dává stejné bajty — žádné časové razítko, UUID a vystavující
systém dodává volající ve volbách.

## 3. Vstupní kontrakt (D2)

Vstupem je **canonical pro e-fakturu**: papírová projekce dokladu
(`docs/test-corpus.md` § 8) + `rows[].computed` (`vatBase`, `vatAmount`,
`vatTotal`). Generátor **nepočítá nic, co jde do DPH nebo součtů** — částky
z `computed`, `vatRecap` a `totals` jen formátuje. Dopočítává jen
informativní povinné prvky: druhou jednotkovou cenu (s daní; u cen s DPH
naopak bez daně), `TaxInclusiveAmount` součtů (= částka celkem bez
zaokrouhlení) a částku řádku před slevou.

Povinné, jinak `EInvoiceInputException` se všemi cestami:

- `docType` z D5 (jiný typ, i `creditNote`, = „typ není podporován");
- `docNumber` (nejvýš 36 znaků), `dates.issueDate`; u faktury s DPH
  (`vat.mode` ≠ `none`) i `dates.taxPointDate`;
- `vat.mode` ∈ `fromBase` / `fromTotal` / `none`;
- `currency` `CZK`, `exchangeRate` null nebo 1;
- u obou stran `name`, `companyId`, `address.{street, houseNumber, city,
  zip, country}` (kód země musí být v číselníku);
- aspoň jeden položkový řádek; u každého `quantity`, `unitPrice`,
  `vat.pct` (u dokladu bez DPH smí být null → 0 %),
  `computed.{vatBase, vatAmount, vatTotal}`;
- `totals.totalAmount`; u dokladu s DPH i `totals.totalBase`,
  `totals.totalVat` a neprázdný `vatRecap`;
- u převodu `dates.dueDate` a `supplier.bankAccount.accountNumber` ve tvaru
  `[předčíslí-]číslo/kód`;
- `vat.reverseCharge: true` chce aspoň jeden řádek s `reverseChargeCode`,
  a naopak kód na řádku chce příznak na hlavičce.

**Doklad bez DPH** (`vat.mode: none`, dodavatel neplátce — v korpusu
varianta 7): bez DUZP, `VATApplicable` `false`, řádky s 0 %, jeden
`TaxSubTotal` 0 % se základem = částka celkem a daní 0, `TaxExclusiveAmount`
= `PayableAmount`. Zadání #119 chtělo DUZP a sazbu povinně vždy; korpus
neplátce obojí nemá a D10 ho výslovně zahrnuje, proto tato odchylka.

## 4. Mapování canonical → ISDOC (generátor)

Pořadí elementů = `xs:sequence` schématu, booleany `true` / `false`,
`version="6.0.1"` (D6). `*` = v XSD povinné.

| ISDOC | Zdroj v canonicalu |
|---|---|
| `DocumentType`* | 1 (`invoice*`), 4 (`proforma*`) |
| `ID`* | `docNumber` (číslo vystavující strany) |
| `UUID`* | `options.uuid` |
| `IssuingSystem` | `options.issuingSystem` |
| `IssueDate`* | `dates.issueDate` |
| `TaxPointDate` | `dates.taxPointDate` — jen typ 1 s DPH |
| `VATApplicable`* | `vat.mode !== 'none'` |
| `ElectronicPossibilityAgreementReference`* | prázdný |
| `Note` | `notes.onDocument` |
| `LocalCurrencyCode`*, `CurrRate`*, `RefCurrRate`* | `CZK`, `1`, `1` |
| `AccountingSupplierParty/Party`*, `AccountingCustomerParty/Party`* | `supplier`, `customer` — viz Strana |
| `InvoiceLines/InvoiceLine`* | položkové řádky (`rowKind: item`; text a sekce do ISDOC nejdou) — viz Řádek |
| `TaxTotal/TaxSubTotal`* | `vatRecap[]` v pořadí canonicalu: `TaxableAmount` = base, `TaxAmount` = tax, `TaxInclusiveAmount` = total, `AlreadyClaimed*` 0, `Difference*` = totéž, `TaxCategory/Percent` = vatPct, `LocalReverseChargeFlag` `true` u sazby, v níž je řádek s kódem přenesení; doklad bez DPH jeden subtotal 0 % s `VATApplicable false` |
| `TaxTotal/TaxAmount`* | `totals.totalVat` (0 u dokladu bez DPH) |
| `LegalMonetaryTotal`* | `TaxExclusiveAmount` = totalBase, `TaxInclusiveAmount` = totalAmount − totalRounding, `AlreadyClaimed*` 0, `Difference*` = totéž, `PayableRoundingAmount` = totalRounding, `PaidDepositsAmount` 0, `PayableAmount` = totalAmount |
| `PaymentMeans/Payment` | `PaidAmount` = totalAmount, `PaymentMeansCode` podle `payment.method` (převod a platební brána 42, hotově 10, kartou 48, dobírka 50, zápočet 97); bez `payment.method` se `PaymentMeans` nepíše |
| `Payment/Details` | jen u převodu: `PaymentDueDate` = dueDate, `ID` + `BankCode` z `accountNumber`, `Name` prázdný, `IBAN` = `bankAccount.iban` nebo dopočet `CzIbanCalculator` (docs.core), `BIC` = `bankAccount.bic` nebo prázdný, `VariableSymbol` = paymentReference, `ConstantSymbol`, `SpecificSymbol` |

**Strana** (`Party`): `PartyIdentification/ID` = `companyId`,
`PartyName/Name` = `name`, `PostalAddress` (`StreetName`, `BuildingNumber`,
`CityName`, `PostalZone`, `Country/IdentificationCode` = kód velkými,
`Country/Name` z `CountryNames`), `PartyTaxScheme` (`CompanyID` = `vatId`,
`TaxScheme` `VAT`) jen s DIČ, `RegisterIdentification/Preformatted` =
`courtRegistration`, `Contact` (`Name`, `Telephone`, `ElectronicMail`) jen
má-li co.

**Řádek** (`InvoiceLine`): `ID` = pořadí 1…n (6.0.2 hlídá unikátnost),
`InvoicedQuantity@unitCode` = `unit`, `LineExtensionAmount` = `computed.vatBase`,
`LineExtensionAmountBeforeDiscount` (jen se slevou: `vatBase + discountAmount`,
resp. `vatBase / (1 − discountPct / 100)`), `LineExtensionAmountTaxInclusive`
= `computed.vatTotal` (u cen s DPH i `…TaxInclusiveBeforeDiscount`),
`LineExtensionTaxAmount` = `computed.vatAmount`, `UnitPrice` /
`UnitPriceTaxInclusive` (jedna z canonicalu podle `vat.mode`, druhá
dopočtená), `ClassifiedTaxCategory` (`Percent` = `vat.pct`,
`VATCalculationMethod` 0 u `fromBase`, 1 u `fromTotal`, `VATApplicable
false` u dokladu bez DPH, `LocalReverseCharge/LocalReverseChargeCode` =
`vat.reverseChargeCode`), `Note` = `item.description`, `Item/Description` =
`item.name`, `CatalogueItemIdentification/ID` = `item.ean`,
`SellersItemIdentification/ID` = `item.supplierCode`.

**Formát čísel:** částky na 2 desetinná místa (`-0.00` → `0.00`), množství
a sazby bez koncových nul, desetinná tečka.

## 5. Mapování ISDOC → canonical (čtečka)

`IsdocReader` (`fromFile` — i `.isdocx` ZIP obal, `fromXmlString`);
`source.kind = 'isdoc'`, confidence 1.0, `selfParty = 'customer'`.
Mapuje se jen to, co v ISDOC opravdu je, chybějící pole se vynechávají.

| Canonical | ISDOC | Poznámka |
|---|---|---|
| `source.raw` | `@version`, `DocumentType`, `UUID`, `ID` | audit, budoucí dedup |
| `docType` | `DocumentType` | 1 → `invoiceReceived`, 2 → `creditNote`; jinak `IsdocParseException` (typ 4 čtečka zatím nepřijímá) |
| `docNumber` | `ID` | |
| `docText` **a** `notes.onDocument` | `Note` | text dodavatele na dokladu: `doc_notice` i dosavadní `docText` (řádek seznamu, podtitulek detailu, náhled review) |
| `dates.issueDate` / `taxPointDate` | `IssueDate` / `TaxPointDate` | |
| `dates.dueDate` | `PaymentMeans/Payment/Details/PaymentDueDate` | jen u převodu |
| `currency` | `ForeignCurrencyCode` ?? `LocalCurrencyCode` | u cizí měny částky z `*Curr` |
| `exchangeRate` | `CurrRate` / `RefCurrRate` | jen u cizí měny |
| `vat.mode` | `VATApplicable` `false` → `none`; jinak `VATCalculationMethod` shodná na všech řádcích (0 → `fromBase`, 1 → `fromTotal`), rozdílná → nic | u `fromTotal` jsou ceny řádku s daní |
| `vat.reverseCharge` | `LocalReverseChargeCode` na řádku nebo `LocalReverseChargeFlag` `true` v rekapitulaci → `true`; jinak při `VATApplicable true` `false`; bez `VATApplicable` nic | místo plnění ISDOC nemá, applier ho odvodí z DIČ |
| `payment.method` | `PaymentMeansCode` i bez `Details` | 10 cash, 42 bankTransfer, 48 card, 50 cashOnDelivery, 97 setOff; jiné null |
| `payment.paymentReference` / `constantSymbol` / `specificSymbol` | `Details/VariableSymbol` / `ConstantSymbol` / `SpecificSymbol` | |
| `supplier`, `customer` | `AccountingSupplierParty/Party`, `AccountingCustomerParty/Party` | `name`, `companyId`, `taxId` + `vatId` (`PartyTaxScheme` s `TaxScheme` VAT), `courtRegistration` (`Preformatted`), `country` + `address.*`, `contact.{name, email, phone}` |
| `supplier.bankAccount` | první `Payment/Details` | `accountNumber` = `ID/BankCode`, `iban`, `bic` |
| `rows[]` | `InvoiceLines/InvoiceLine` | `rowKind item`, `orderPos`; `quantity` + `unit`; `unitPrice` = `UnitPrice` (u cen s DPH `UnitPriceTaxInclusive`), `totalPrice` = `LineExtensionAmount` (`…TaxInclusive`), `priceCalcMode fromUnitPrice` (cizí měna: jen `totalPrice`, `fromTotal`); `vat.pct` (u dokladu bez DPH null), `vat.reverseChargeCode`; `discountAmount` = rozdíl `…BeforeDiscount` − částka, jen kladný; `computed.{vatBase, vatAmount, vatTotal}`; `item.name` (`Description`), `item.description` (`Note` řádku), `item.supplierCode`, `item.ean` (`CatalogueItemIdentification`) |
| `vatRecap[]` | `TaxTotal/TaxSubTotal` | `vatPct`, `base`, `tax`, `total`; u dokladu bez DPH se nevrací |
| `totals` | `LegalMonetaryTotal` + `TaxTotal/TaxAmount` | `totalBase`, `totalVat` (u dokladu bez DPH null), `totalAmount`, `totalRounding` |

Doklad bez DPH (`VATApplicable false`) tak dostane tvar dokladu neplátce
podle `docs/exchange-format.md` § 8.4 — applier mu dá kód bez daně místo
hledání sazby 0 %. Signál tuzemského přenesení dojde přes náhled
k `cz-115` (`DocumentApplierTest::testReceivedIsdocWithLocalReverseChargeDerivesPdpCode`).

## 6. UUID (D7)

`EInvoiceUuid::fromName()` = UUIDv5 nad jmenným prostorem
`082dfa0f-d3a6-4b11-9c1b-3a6737a1a957` (konstanta, neměnit). Stejné jméno
→ stejné UUID: tisk (fáze 2) ho tvoří ze zdroje dat a dokladu (bez sloupce
v databázi — stejný doklad = stejný soubor při každém odeslání), korpus
z cesty buňky `corpus/<země>/<profil>/<NNNN-slug>`, aby se commitované
soubory mezi buildy neměnily.

## 7. Schémata a validace (D6)

`modules/core/exchange/schemas/isdoc/` — XSD 6.0.1 a 6.0.2 beze změny
s licenční hlavičkou, zdroje a MD5 v `README.md` tamtéž. Liší se jen
hlavičkou, atributem verze a `xs:key` na ID řádků. Generátor píše
`version="6.0.1"` (kompatibilita příjemců), výstup musí projít oběma
(`IsdocSchemaValidator::validateAll`): testy generátoru, strážce korpusu
(`CorpusExpectedTest::testIsdocFilePassesBothSchemas`) a CLI před zápisem.
Za běhu ve fázi 2 (neplatný výstup = bez ISDOC + hlášení, PDF jde ven).
Schematron ne.

Schéma hlídá strukturu, ne obsah — prázdný řetězec je platná hodnota,
proto povinná pole hlídá generátor (§ 3).

## 8. CLI, korpus a round-trip (D8, D9)

`shpd-server einvoice-render --format=isdoc --input=<canonical.json>
--output=<soubor> --uuid-name=<text> [--issuing-system=<text>]`
(`docs/cli.md`): bez zdroje dat a `server.json`, exit 0 zapsáno,
1 vstup (cesty na stderr), 2 výstup neprošel XSD; při chybě se nic
nezapíše, zápis je atomický.

Build korpusu (`tests/corpus/cz/build.py`, `docs/test-corpus.md` § 12)
u buňky `pdfIsdoc` předá očekávaný výsledek + `rows[].computed`
(řádkové DPH z `compute.py`) a vyrobí `out/<profil>/NNNN-<slug>.isdoc`;
18 souborů (varianty 1–4, 7, 10 × 3 profily). V datových sadách ISDOC
zatím není (O1 — co má evaluace u zpráv s ISDOC měřit, je otázka #103).

**Round-trip** `IsdocCorpusRoundTripTest`: každý `.isdoc` korpusu →
`IsdocReader` → `CanonicalComparator` proti očekávanému výsledku bez
rozdílu mimo `IGNORE`; totéž nad fixturami generátoru
(`tests/Fixtures/Exchange/einvoice/`, vydané doklady s prohozenou rolí).
Výjimky (pole, která ISDOC nenese — ověřeno proti XSD):

| Cesta | Důvod |
|---|---|
| `vat.place` | ISDOC nemá místo plnění; applier ho odvodí z prefixu DIČ |
| `rows[].vat.supplyKind` | nemá druh plnění (zboží / služba) |
| `rows[].priceCalcMode` | neříká, zda je autoritativní jednotková, nebo celková cena |
| `supplier.contact.web`, `customer.contact.web` | `Contact` má jen jméno, telefon a e-mail |
| `dates.periodFrom`, `dates.periodTo` | hlavička nemá období plnění |
| `rows[].discountPct`, `rows[].discountAmount` | ISDOC nese jen částku před slevou; korpus má procento — částku z čtečky test ověřuje zvlášť |
| `_resolve` | štítek obsahu je výsledek obohacení, ne pole dokladu |

Známé ztráty mimo korpus: textové řádky a sekce (do ISDOC nejdou), splatnost
u jiné úhrady než převodem (`PaymentDueDate` je jen v bankovních
detailech), `paymentGateway` → 42 → zpět `bankTransfer`, procento slevy.

## 9. Výhled — fáze 2

- adaptér `PrintData → canonical` (D3): tisk dodá canonical z dat tisku
  (snapshoty stran), ne přes `DocumentExporter`;
- vložení ISDOC do PDF faktury vydané (`EmbedIsdocStep`, `docs/render.md`)
  a příloha e-mailu zvlášť;
- výběr formátu podle stran (D10): ISDOC jen odběratel se sídlem v ČR
  s IČO od vlastní firmy v ČR;
- validace za běhu s degradací; přechod na `version="6.0.2"` s ISDOC.PDF;
- mimo: cizí měna (D4), opravné doklady (#92), `.isdocx`, podpis,
  Schematron, EN 16931 / Peppol.
