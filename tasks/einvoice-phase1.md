# Strukturovaná e-faktura — generátor ISDOC, fáze 1 (#119)

**Stav:** hotovo — implementace 2026-10-20 (6 commitů + dovětek k testům
evaluací); odchylky od zadání (doklad bez DPH bez DUZP a sazby, `Note` →
`notes.onDocument` i `docText`, sleva v round-tripu, explicitní
`vat.reverseCharge: false`, úhrada / EAN / poznámka řádku / ceny s DPH
v čtečce, konzistence přenesení jako chyba vstupu) v `docs/einvoice.md`
§ 3, 5 a 8

> Task pro Claude Code (6 commitů). První fáze #119: generátor ISDOC
> z canonicalu, schémata a validace, rozšíření čtečky, CLI a soubory ISDOC
> v korpusu testovacích dokladů (#103). Vložení do odchozích PDF, příloha
> e-mailu a výběr formátu jsou fáze 2 (samostatný task).

## Kontext

- `IsdocReader` (`core.exchange`) převádí ISDOC na canonical
  `shpd.docs.document.v1`; generátor chybí. Potřebuje ho korpus (#103 F1 —
  podoba „PDF + ISDOC“ má zatím jen PDF, #103 D18) a tisky (#90, fáze 2).
- `EmbedIsdocStep` v `RenderClient` umí soubor vložit do PDF jako běžnou
  přílohu (`docs/render.md`) — použije ho fáze 2.
- ISDOC (XSD) vyžaduje víc, než nese papírová projekce canonicalu: všechny
  složky adresy včetně názvu země, IČ obou stran, na každém řádku částku
  bez daně, daň, částku s daní a jednotkovou cenu bez daně i s daní,
  bankovní spojení se všemi prvky (číslo, kód banky, název, IBAN, BIC),
  bloky záloh v rekapitulaci a součtech.
- Starý Shipard psal ISDOC šablonou ve verzi 6.0.1, vkládal ho do PDF
  faktury vydané a v e-mailu ho přikládal i zvlášť.

## Rozhodnutí (potvrzená 2026-10-20, #119)

- ✓ **D1 — Generátor je čistá funkce v `core.exchange`.**
  `Isdoc\IsdocWriter` vedle `IsdocReader`: canonical → XML, bez databáze
  a konfigurace zdroje dat. Nad ním rozhraní formátu (`isdoc` je první),
  aby další formáty měly kam přibýt. Výběr formátu podle stran není věc
  generátoru (fáze 2).
- ✓ **D2 — Vstupem je canonical pro e-fakturu**: papírová projekce +
  `rows[].computed`. Generátor nepočítá nic, co jde do DPH nebo součtů
  (#103 D5 zůstává čisté). Dopočítá jen informativní povinné hodnoty
  (jednotková cena s daní, nulové bloky záloh). Chybějící povinná hodnota
  je chyba generátoru s cestou k poli, ne prázdný prvek.
- ✓ **D3 — Tisk dodá canonical z dat tisku** (adaptér
  `PrintData → canonical`, fáze 2), ne přes `DocumentExporter`. Exportér
  datových sad bere partnera živě z osoby, `docNumber` plní číslem
  partnera a vlastní stranu nenese; ISDOC musí sedět s PDF, které se
  tiskne ze snapshotů. Odchylka od textu issue.
- ✓ **D4 — V1 jen doklady v Kč.** ISDOC chce všechny částky v tuzemské
  měně a jednotkovou cenu v cizí měně nemá. Tuzemské faktury v cizí měně
  jsou výjimka; cizoměnová větev přijde samostatně.
- ✓ **D5 — Typy dokladů:** `invoiceIssued` / `invoiceReceived` → 1,
  `proformaIssued` / `proformaReceived` → 4. Opravné doklady (2, 3)
  s #92. Pokladní doklad, prodejka a zjednodušený daňový doklad ISDOC
  nedostanou.
- ✓ **D6 — XSD 6.0.1 i 6.0.2 v repozitáři**, výstup musí projít oběma.
  Validace v testech i za běhu (za běhu ve fázi 2: neplatný výstup = bez
  ISDOC + hlášení, PDF jde ven). Atribut `version="6.0.1"` kvůli
  kompatibilitě; na 6.0.2 se přejde s ISDOC.PDF ve fázi 2. Schematron ne.
- ✓ **D7 — UUID deterministicky (UUIDv5).** Tisk: ze zdroje dat a dokladu
  (stejný doklad = stejné UUID při každém odeslání, bez sloupce). Korpus:
  z cesty buňky, aby se commitované soubory mezi buildy neměnily.
  `IssuingSystem` bez commitu.
- ✓ **D8 — Round-trip a čtečka.** Round-trip generátor → čtečka →
  `CanonicalComparator` s vyjmenovanými výjimkami (pole, která ISDOC
  nenese). Čtečka se ve fázi 1 rozšíří o to, co generátor píše — mění to
  produkční import, proto samostatný commit.
- ✓ **D9 — Vstupní bod pro korpus** `shpd-server einvoice-render` (bez
  zdroje dat, #103 D9). Build korpusu předá očekávaný canonical doplněný
  o částky řádků spočítané v Pythonu; výstup
  `tests/corpus/<země>/out/<profil>/NNNN-<slug>.isdoc`. Zapojení souborů
  do datových sad je otevřené (O1).
- ✓ **D10 — ISDOC jen mezi dvěma českými subjekty s IČO.** Zahraničnímu
  partnerovi ani soukromé osobě k ničemu není. Generátor proto vyžaduje
  IČO a úplnou adresu obou stran; prázdné smějí zůstat jen prvky
  bankovního spojení, které nemáme (název banky, BIC). Ve fázi 2 dostane
  ISDOC jen odběratel se sídlem v ČR s IČO od vlastní firmy v ČR. V korpusu
  má podobu PDF + ISDOC jen varianty 1–4, 7 a 10; druhé doklady variant 5,
  6, 8 a 9 zůstávají jen v PDF. Upřesňuje #103 D4 a D18.

## Před implementací přečti

- `CLAUDE.md`
- `docs/exchange-format.md` — §4 Adaptéry, §5 (struktura, polymorfismus
  podle `docType`), §6 Party, §7 Row, §8.4 (odvození kódu DPH u přijatých
  dokladů — co čte `VatCodeDerivation`)
- `tasks/mail-isdoc-import.md` — mapování ISDOC → canonical (dnešní stav
  čtečky)
- `docs/test-corpus.md` — §2 Struktura, §4 Datový soubor, §6 Matice,
  §8 Papírová projekce, §12 Build, §13 Strážce, §17 (porovnávač)
- `docs/cli.md` — konvence příkazů `shpd-server`
- Schéma ISDOC: <https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd>
  a 6.0.1 z archivu MV ČR (zdroje v `schemas/isdoc/README.md`, krok 2)

## Rozsah

**V rozsahu:** generátor a rozhraní formátu, schémata a validace, UUID,
rozšíření čtečky, CLI `einvoice-render`, soubory ISDOC v korpusu
a round-trip test, úprava korpusu podle D10, dokumentace `docs/einvoice.md`.

**Mimo rozsah:**

- fáze 2: adaptér `PrintData → canonical` (D3), vložení do PDF, příloha
  e-mailu, výběr formátu podle stran (D10), validace za běhu s degradací
- cizí měna (D4), opravné doklady (#92), zjednodušený daňový doklad,
  `.isdocx`, digitální podpis, Schematron
- ISDOC jako příloha zpráv v datových sadách (O1)
- další formáty (EN 16931 — UBL / CII, Peppol)

## Kroky

### 1. Korpus: číslo dokladu nezávislé na podobě, pravidlo D10

Dnes název souboru určuje podobu (`0V01` = `pdf`, `0V02` = `pdfIsdoc` —
`expected_stem()` v `tools/invoice-generator/invoicegen/corpus.py`
a kontrola v `tests/Unit/Corpus/CorpusExpectedTest.php`). Nově:

- `0V01` / `0V02` = první / druhý doklad varianty; podoba je jen pole
  `form` v datovém souboru. Validátor kontroluje číslo varianty v názvu,
  pořadí `01` / `02` a unikátnost, ne podobu.
- Pravidlo D10 ve validátoru datového souboru: `form: "pdfIsdoc"` jen když
  je dodavatel český (`country` `cz`) s IČO a doklad má odběratele (ne
  účtenka). Porušení = chyba buildu s důvodem.
- `tests/corpus/cz/data/0502-…`, `0602-…`, `0802-…`, `0902-…`: `"form": "pdf"`.
  Očekávané výsledky a PDF se nemění; datové sady se přegenerují (záznam
  zprávy nese `form`).
- Strážce v PHP (`CorpusExpectedTest`) stejné pravidlo: podoba z datového
  souboru, D10 pro `pdfIsdoc`.
- `docs/test-corpus.md`: konvence názvu, matice (§6 — které varianty mají
  PDF + ISDOC), pasti (§15 — účtenka v podobě `pdfIsdoc` už neplatí).

Ověření: `python3 tests/corpus/cz/build.py` — `expected/` a `out/` beze
změny (`git status`), v `tests/datasets/` jen pole `form` u čtyř dokladů;
`python3 -m unittest` v `tools/invoice-generator`;
`vendor/bin/phpunit --filter 'CorpusExpectedTest|EvalMessagesTest|EvalMetricsTest'`.

### 2. Generátor ISDOC a schémata

V `modules/core/exchange/`:

- `src/EInvoice/EInvoiceWriter.php` — rozhraní: `format(): string`,
  `write(array $canonical, EInvoiceOptions $options): string`.
- `src/EInvoice/EInvoiceOptions.php` (readonly) — `uuid`, `issuingSystem`,
  názvy zemí (callable nebo malá třída nad
  `modules/world/base/config/countries.jsonc`, `name:cs`; generátor sám
  konfiguraci nečte).
- `src/EInvoice/EInvoiceInputException.php` — seznam `{path, message}`
  (cesta jako v canonicalu, `supplier.companyId`, `rows[2].computed.vatAmount`).
- `src/EInvoice/EInvoiceUuid.php` — `fromName(string): string`, UUIDv5
  (RFC 9562) s pevným jmenným prostorem Shipardu v konstantě.
- `src/Isdoc/IsdocWriter.php` — formát `isdoc`, `DOMDocument`, namespace
  `http://isdoc.cz/namespace/2013`, `version="6.0.1"`.
- `src/Isdoc/IsdocSchemaValidator.php` — `validate(string $xml, string $version): list<string>`
  nad lokálními XSD (`LIBXML_NONET`, chyby libxml jako řádky).
- `schemas/isdoc/isdoc-invoice-6.0.1.xsd`, `isdoc-invoice-6.0.2.xsd`
  beze změny (licenční hlavička musí zůstat) + `README.md` se zdrojovými
  adresami a poznámkou o licenci. Varianty s podpisem (`-dsig`) netřeba.

**Vstup (D2, D10) — povinné, jinak `EInvoiceInputException`:**

- `docType` z D5; jiný typ (i `creditNote`) = chyba „typ není podporován“.
- `docNumber` (→ `ID`; číslo vystavující strany, viz §5 výměnného formátu),
  `dates.issueDate`; u typu 1 `dates.taxPointDate`.
- `currency` `CZK` a `exchangeRate` null nebo 1 (D4).
- U obou stran `name`, `companyId`, adresa `street`, `houseNumber`,
  `city`, `zip`, `country` (název země z číselníku; neznámý kód = chyba).
- U každého položkového řádku `quantity`, `unitPrice`, `vat.pct`,
  `computed.vatBase`, `computed.vatAmount`, `computed.vatTotal`.
- `totals.totalAmount`; u převodu `dates.dueDate` a
  `supplier.bankAccount.accountNumber`.

**Mapování — pravidla, úplná tabulka je výstup kroku 6:**

- Hlavička: `DocumentType`, `ID`, `UUID`, `IssuingSystem`, `IssueDate`,
  `TaxPointDate` (ne u typu 4), `VATApplicable` (`vat.mode` ≠ `none`),
  `ElectronicPossibilityAgreementReference` prázdný,
  `LocalCurrencyCode` `CZK`, `CurrRate` / `RefCurrRate` `1`. Žádné časové
  razítko ani jiná proměnná hodnota — stejný vstup dává stejné bajty.
- Strany: `PartyIdentification/ID` = `companyId`, `PartyTaxScheme`
  (`VAT`) jen s `vatId`, `RegisterIdentification/Preformatted` =
  `courtRegistration`, `Contact` z `contact`.
- Řádky: jen `rowKind: item` (text a sekce do ISDOC nejdou — výjimka
  round-tripu); `ID` = pořadí 1…n (6.0.2 hlídá unikátnost);
  `InvoicedQuantity@unitCode` = `unit`; částky z `computed`, jednotková
  cena s daní dopočtená; `VATCalculationMethod` 0 u `fromBase`, 1 u
  `fromTotal`; sleva → `LineExtensionAmountBeforeDiscount`;
  `reverseChargeCode` → `LocalReverseCharge/LocalReverseChargeCode`;
  `Item/Description` = `item.name`, `SellersItemIdentification` =
  `item.supplierCode`, `CatalogueItemIdentification` = `item.ean`.
- Rekapitulace: `TaxTotal` z `vatRecap` (aspoň jeden `TaxSubTotal` — doklad
  bez DPH dostane jeden s 0 % a `VATApplicable` `false`),
  `AlreadyClaimed*` 0, `Difference*` = předpis, `LocalReverseChargeFlag`
  u sazby s přenesením.
- Součty: `LegalMonetaryTotal` z `totals`, zálohy 0,
  `PayableRoundingAmount` = `totalRounding`, `PayableAmount` =
  `totalAmount`. Doklad bez DPH: základ = částka celkem, daň 0.
- Platba: `PaymentMeansCode` podle `payment.method` (převod 42, hotově 10,
  kartou 48, dobírka 50, zápočet 97, platební brána 42). `Details` jen
  u převodu: splatnost, účet `ID` + `BankCode` z `accountNumber`
  (`číslo/kód`), `IBAN` z `bankAccount.iban`, jinak dopočet
  `CzIbanCalculator` (`docs.core`), `BIC` z `bankAccount.bic`, `Name`
  prázdný (D10 — jediné povolené prázdné prvky), VS / KS / SS.
- Hlavičková poznámka: ISDOC má jednu `Note`. Navrhované mapování
  `notes.onDocument` ↔ `Note`; čtečka dnes plní `docText`. Změnu cílového
  pole čtečky udělej v kroku 3 jen tehdy, když se tím nezhorší titulky
  došlých dokladů (ověř, kde se `docText` z ISDOC používá); jinak výjimka
  round-tripu.
- Formát čísel: částky na 2 desetinná místa (`number_format` po `round`),
  množství a sazby bez koncových nul, desetinná tečka.

**Testy** (`tests/Unit/Module/Core/Exchange/Isdoc/IsdocWriterTest.php`,
fixtury canonicalu v `tests/Fixtures/Exchange/einvoice/`):

- faktura vydaná, dvě sazby, převod (IBAN dopočtený); tuzemské přenesení
  s kódem 4; dodavatel neplátce (`vat.mode: none`); zálohová faktura (typ 4,
  bez DUZP); platba kartou (bez `Details`); řádek se slevou; textový řádek
- každý výstup projde XSD 6.0.1 i 6.0.2; dvojí zápis = shodné bajty
- chybějící IČO, cizí měna, `creditNote`, chybějící `computed` →
  `EInvoiceInputException` se všemi cestami najednou
- `EInvoiceUuid`: známý vektor UUIDv5, verze a varianta v řetězci

### 3. Čtečka: co generátor píše

`IsdocReader` nově mapuje:

- `LocalReverseCharge/LocalReverseChargeCode` → `rows[].vat.reverseChargeCode`
  a `vat.reverseCharge: true`; `TaxCategory/LocalReverseChargeFlag` `true`
  → `vat.reverseCharge: true`
- hlavička `VATApplicable` `false` → `vat.mode: none`; jinak
  `VATCalculationMethod` shodný na všech řádcích → `vat.mode`
  (`fromBase` / `fromTotal`), rozdílný → nic (odvodí applier)
- `LineExtensionAmountBeforeDiscount` → `discountAmount` (rozdíl, jen
  kladný)
- případně `Note` podle kroku 2

Typy dokladů beze změny (1, 2). Dopady na produkční import:

- Testy čtečky nad stávajícími fixturami (`tests/Fixtures/Exchange/isdoc/`)
  — každou změnu výsledku vědomě upravit a vyjmenovat v commitu.
- Test, že přijatý ISDOC s tuzemským přenesením (kód 4) dojde přes
  `IsdocReader` a applier (`/preview`) ke kódu DPH s přenesením u plátce —
  dřív signál přenesení z ISDOC chyběl (`docs/exchange-format.md` §8.4).
- `docs/exchange-format.md` §4 Adaptéry a mapování čtečky (krok 6).

### 4. CLI `shpd-server einvoice-render`

```
shpd-server einvoice-render --format=isdoc --input=<canonical.json>
    --output=<soubor> --uuid-name=<text> [--issuing-system=<text>]
```

- `src/Command/Server/EInvoiceRenderCommand.php`, registrace
  v `src/Cli/ServerApplicationFactory.php`. Nepotřebuje zdroj dat ani
  `server.json`.
- Přečte JSON, zapíše přes writer formátu, ověří proti oběma XSD, uloží
  atomicky (dočasný soubor + `rename`). `--issuing-system` výchozí
  `Shipard`.
- Exit 0 = zapsáno; 1 = vstup (cesty z `EInvoiceInputException` na
  stderr, nic se nezapíše); 2 = výstup neprošel XSD (chyba generátoru);
  neznámý `--format` = 1.
- Testy přes `CommandTester`; `docs/cli.md`.

### 5. Korpus: soubory ISDOC a round-trip

- `tools/invoice-generator/invoicegen/compute.py`: částky řádku v `Decimal`
  nezávisle na Shipardu (#103 D5) — u `fromBase` daň = základ × sazba / 100,
  u `fromTotal` daň = celkem × sazba / (100 + sazba), `ROUND_HALF_UP` na
  haléře, druhá částka rozdílem; bez DPH daň 0. Součet řádkových daní se od
  rekapitulace smí lišit o haléře (rekapitulace se počítá ze součtu
  v sazbě) — tak to na dokladech je. Unit testy.
- `tests/corpus/cz/build.py` u buňky `pdfIsdoc`: vstup = očekávaný
  výsledek po projekci + `rows[i].computed`; volá
  `php bin/shpd-server einvoice-render` s
  `--uuid-name=corpus/<země>/<profil>/<stem>`; výstup
  `out/<profil>/<stem>.isdoc`. ISDOC se generuje **vždy** (je
  deterministický, na rozdíl od PDF) a výpis řekne „ISDOC zapsán / beze
  změny“ podle obsahu; `build-state.json` se ho netýká. Plný běh smaže
  `.isdoc` buněk, které podobu `pdfIsdoc` nemají. Selhání CLI = build
  končí chybou s výstupem příkazu (chybí `vendor/` → návod
  `composer install`).
- `CorpusExpectedTest`: buňka `pdfIsdoc` má `.isdoc`, buňka `pdf` nemá;
  každý `.isdoc` projde XSD 6.0.1 i 6.0.2.
- `tests/Unit/Module/Core/Exchange/Isdoc/IsdocCorpusRoundTripTest.php`:
  každý `.isdoc` korpusu → `IsdocReader::fromFile()` →
  `CanonicalComparator::compare($expected, $actual, IGNORE)` = žádný
  rozdíl. `IGNORE` je konstanta s důvodem u každé cesty (pole, která ISDOC
  nenese — ověř v XSD; kandidáti `vat.place`, `rows[].vat.supplyKind`,
  web kontaktu, období plnění, textové řádky); stejný seznam v
  `docs/einvoice.md`. Pole, které ISDOC nese a čtečka ho nevrací, do
  `IGNORE` nepatří — patří do kroku 3.
- Round-trip i nad fixturami z kroku 2 (doklad vydaný: čtečka vrátí
  `invoiceReceived` se `selfParty: customer` — test role prohodí).

Soubory: 6 dokladů (varianty 1–4, 7, 10) × 3 profily = 18 `.isdoc`.
Datové sady se v tomto kroku nemění (O1).

### 6. Dokumentace a stav

- `docs/einvoice.md` (nový): účel a D10, vstupní kontrakt (povinná pole),
  mapovací tabulka canonical ↔ ISDOC v obou směrech (převzít tabulku
  čtečky z `tasks/mail-isdoc-import.md`), výjimky round-tripu, schémata
  a verze, UUID, CLI, výhled fáze 2 (D3, vložení do PDF, příloha e-mailu,
  výběr formátu).
- `CLAUDE.md` — řádek `docs/einvoice.md` v tabulce dokumentů.
- `docs/exchange-format.md` §4 Adaptéry — výstupní adaptér, odkaz na
  mapování v `docs/einvoice.md` místo `tasks/mail-isdoc-import.md`;
  bod 6 v §1 (mezikrok pro e-fakturaci) aktualizovat.
- `docs/test-corpus.md` — §1, §12 (ISDOC v buildu), §13 (strážce),
  §15 (mimo rozsah).
- `docs/cli.md` — `einvoice-render`.
- `modules/core/mail/docs/ai-analysis.md` — jen pokud vyjmenovává
  mapovaná pole čtečky.
- `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Pasti

- XSD má striktní pořadí prvků (`xs:sequence`) a vlastní boolean jen
  `true` / `false` — validace to odhalí, ale generátor piš podle pořadí
  ve schématu.
- `TaxTotal` musí mít aspoň jeden `TaxSubTotal` i u dokladu bez DPH.
- Prázdný řetězec je pro XSD platná hodnota — proto D2 / D10 hlídá
  povinná pole v generátoru, ne schéma.
- Žádná aritmetika s floaty kromě odvozených informativních hodnot;
  částky z `computed`, `vatRecap` a `totals` se jen formátují.
- Rozšíření čtečky mění produkční import ISDOC (přenesení daňové
  povinnosti, režim DPH) — samostatný commit s testy dopadu.
- Čtyři doklady korpusu mění podobu na `pdf` — výsledky evaluací
  rozdělené podle podoby (`byForm`) nejdou 1:1 srovnat s běhy před tímto
  taskem.
- Build korpusu nově volá PHP (`bin/shpd-server`) — potřebuje
  `composer install`.

## Hotovo když

- [x] Korpus: podoba nezávislá na čísle dokladu, pravidlo D10 v Pythonu
      i v PHP strážci, čtyři datové soubory `pdf`, `expected/` a PDF beze
      změny (hash datových sad se změnil — běhy evaluací z 16. 10. nejsou
      nad aktuální sadou, testy to říkají)
- [x] `IsdocWriter` + rozhraní, volby, výjimka, UUIDv5, validátor a XSD
      6.0.1 / 6.0.2 v repozitáři s licenční hlavičkou
- [x] Testy generátoru: platnost proti oběma XSD, determinismus, chyby
      vstupu se všemi cestami
- [x] Čtečka mapuje přenesení daňové povinnosti, režim DPH a slevu; test
      dopadu na kód DPH přes applier; změny výsledků stávajících fixtur
      vyjmenované v commitu
- [x] `shpd-server einvoice-render` s exit kódy 0 / 1 / 2 a testy
- [x] Build korpusu vyrábí 18 `.isdoc`, strážce je hlídá, round-trip test
      bez rozdílů s vyjmenovanými výjimkami
- [x] `docs/einvoice.md`, `CLAUDE.md`, `docs/exchange-format.md`,
      `docs/test-corpus.md`, `docs/cli.md` aktualizované
- [x] `php -l`, cílené PHPUnit, celá sada, `python3 -m unittest`
      v `tools/invoice-generator`
- [x] `**Stav:**` + `python3 scripts/tasks-index.py`

## Otevřené otázky

- **O1 — ISDOC v datových sadách.** `testing-phase2.md` počítá s ISDOC
  jako druhou přílohou zpráv podoby `pdfIsdoc`. `AttachmentPreparer`
  ale posílá přílohu typu `text/*` modelu jako text — evaluace AI (#103 F4)
  by u těchto zpráv dostala strukturovaná data navíc a měřila něco jiného
  než dnes. Produkce přitom zprávu s ISDOC zpracuje deterministicky
  (`IsdocImportService`), bez AI extrakce. Co má evaluace u zpráv s ISDOC
  měřit, je otázka #103; do té doby zůstávají soubory jen v `out/`.
  **Vyřešeno 2026-10-20** (#103 D75–D76, `tasks/testing-rates-isdoc.md`):
  ISDOC je druhou přílohou zpráv v sadách a evaluace ho zpracuje produkční
  cestou bez AI extrakce; `AttachmentPreparer` ISDOC modelu neposílá.
