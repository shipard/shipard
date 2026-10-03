# Tisky — PDF nad jedním záznamem

Doména `print` ([reports.md](reports.md) §1): výstup nad **jedním záznamem**
— faktura vydaná, zálohová faktura, pokladní doklad, prodejka, Kontace,
později karta majetku. Rozhodnutí D1–D28 jsou v issue #90, zadání
v `tasks/prints-phase1.md` a `tasks/prints-phase2.md`.

Hotovo: kontrakt `PrintData`, infrastruktura (deklarace, registr, runner,
Twig, PDF), REST, CLI včetně nástrojů pro vývoj šablon, akce Tisk
v detailu, vodoznak storna a tisky:

| Tisk | Modul | Co |
|---|---|---|
| `docs.invoicesOut.invoice` | `docs.invoicesOut` | faktura vydaná |
| `docs.proformasOut.proforma` | `docs.proformasOut` | zálohová faktura vydaná |
| `docs.cashDocs.cash` | `docs.cashDocs` | pokladní doklad, příjmový i výdajový |
| `docs.cashRegister.receipt` | `docs.cashRegister` | prodejka (A4) |
| `economy.accounting.docJournal` | `economy.accounting` | Kontace — interní tisk účetních zápisů dokladu (§4.2) |

Nehotovo: nastavení vzhledu a texty na tiscích (fáze 3), e-mail
a zmrazená odeslaná kopie (fáze 4), katalogy tisku `sk`, `de` (#94 D10),
QR pro další země (#91), opravný daňový doklad (#92), „Vystavil“
a jména u podpisů (#93), účtenka na POS tiskárnu, EET.

## 1. Princip

```
deklarace (module.jsonc → prints)
        │
PrintRunner ── záznam, dostupnost, jazyk
        │
PrintBuilder ──► PrintData (JSON) ──► PrintRenderer ──► RenderClient ──► PDF
                     │                 (Twig, sandbox)   (profil Report)
                     │                        └─► format=html (CLI, vývoj šablon)
                     └─► format=json (ladění, testy bez renderu)
```

- **Builder neví nic o šabloně ani o PDF.** Skládá data a rozhoduje
  (titulek, popisky číselníků, QR payload, součty).
- **Šablona jen formátuje a skládá.** Dostává pole z `PrintData`, žádné
  objekty; konfiguraci ani databázi nečte.
- **Tisk vzniká vždy na požádání**, nic se neukládá. Zmrazená kopie
  odeslaného dokladu je věc fáze 4 (D10).
- Se `report` sdílí doména jen `RenderClient` ([render.md](render.md)) —
  žádná společná hierarchie tříd (D1).

Třídy jádra žijí v `src/Core/Prints/`, tisky dokladů
v `modules/docs/core/src/Prints/`, Kontace
v `modules/economy/accounting/src/Prints/`.

## 2. Deklarace

`module.jsonc`:

```jsonc
"prints": [{ "file": "config/prints.jsonc" }]
```

`config/prints.jsonc` je pole deklarací:

```jsonc
{
    "id": "docs.invoicesOut.invoice",
    "name": "Invoice",
    "name:cs": "Faktura",
    "name:en": "Invoice",
    "table": "docs_core_heads",
    "filter": { "doc_type": ["invno"] },
    "docStates": [40, 30],
    "watermarks": { "30": "watermark.cancelled" },
    "audience": "external",
    "builder": "Shipard\\Module\\Docs\\Core\\Prints\\DocPrintBuilder",
    "template": "@docs.invoicesOut/invoice",
    "catalogs": ["@docs.core/_layout"],
    "paper": {
        "format": "A4",
        "orientation": "portrait",
        "margins": { "top": "3.2cm", "bottom": "2cm" }
    },
    "order": 10
}
```

| Klíč | Povinný | Význam |
|---|---|---|
| `id` | ano | Textové, globálně unikátní. Duplicita napříč moduly = tvrdá chyba při načtení. Na id se později navážou texty a nastavení příjemců |
| `name` | ano | Název v nabídce tisků (lokalizovaný `name:cs`) |
| `table` | ano | Tabulka záznamu |
| `filter` | ne | Sloupec → povolené hodnoty; typ záznamu, pro který tisk platí |
| `docStates` | ano | Stavy, ve kterých je tisk dostupný. Koncept se netiskne (D5) |
| `watermarks` | ne | Stav z `docStates` → klíč katalogu s textem vodoznaku (D23). Tisky dokladů ven mají `{"30": "watermark.cancelled"}` — stornovaný doklad se tiskne se „STORNO“ přes každou stranu. **Layout tisku s `watermarks` musí `meta.watermark` vykreslit** (§5) |
| `audience` | ne | `external` (default; jde ven z firmy) / `internal` |
| `builder` | ano | Třída implementující `PrintBuilder` (`build()` + `version()`) |
| `template` | ano | Adresář šablony `@<modul>/<adresář>` → `<modul>/prints/<adresář>/` |
| `catalogs` | ne | **Sdílené adresáře tisku** (typicky layout): jejich katalog překladů, assety a výchozí záhlaví a zápatí (§5) |
| `paper` | ne | `format` (A3/A4/A5/Letter/Legal), `orientation`, `margins` (`top`/`right`/`bottom`/`left` jako CSS délky; chybějící strana = okraj profilu Report, 1.6 cm) |
| `order` | ne | Pořadí v nabídce (default 1000) |

`PrintDefinitionLoader` (`src/Api/`) staví `PrintRegistry` z modulů zdroje
dat. `PrintRegistry::forRecord($table, $record)` vrací tisky dostupné pro
záznam — sedí tabulka, `filter` i `docStates` — seřazené podle `order`.
Tisk bez `filter` platí pro všechny záznamy tabulky (Kontace nad
`docs_core_heads`, `order: 900` — v nabídce za tiskem dokladu).

Příznak `enablePrint` v `docStates.jsonc` se nepoužívá; autoritou je
`docStates` deklarace.

## 3. Běh

`PrintRunner::run($printId, $recordId, PrintFormat, ?$language): PrintOutput`
je jediný vstupní bod — REST i CLI ho staví přes `PrintRunnerFactory`.

1. Definice z registru → `PrintNotFoundException`.
2. Záznam `SELECT *` z tabulky deklarace → `PrintRecordNotFoundException`;
   nesplní `filter` / `docStates` → `PrintNotAvailableException`.
3. Jazyk tisku (`PrintLanguageResolver`, viz níže): výslovný parametr,
   jinak jazyk dokumentu podle partnera. Builder se vytvoří už tady —
   umí-li to (`PrintPartyProvider`), runner se ho před buildem zeptá na
   stranu tisku.
4. Builder dostane `PrintRequest`: definici, záznam, jazyk, spojení,
   **`ConfigRuntime` v jazyce tisku** (ne v jazyce requestu — popisky
   číselníků jdou na doklad) a `PrintTranslator` nad katalogy tisku.
5. Obálka `PrintData`: verze kontraktu z `PrintBuilder::version()`,
   `meta.watermark` = přeložený text klíče z `watermarks` pro stav
   záznamu, jinak `null`. `json` tím končí; `pdf` a `html` pokračují
   rendererem (`html` = `PrintDocument` bez render služby, jen CLI).
6. Render služba PDF nevyrobí → `PrintRenderException` s `errorKind`
   (provozní stav, ne programátorská chyba).

`PrintRunner::renderData($printId, $envelope, PrintFormat, ?$language)`
vstupuje až do kroku renderu s hotovým `PrintData` (pole z JSON) — bez
záznamu, kontroly dostupnosti a builderu, runner k tomu nepotřebuje ani
spojení do databáze (D28). Odmítne (`InvalidArgumentException`) obálku
s neplatným tvarem (`PrintData::fromArray()`), data jiného tisku, verzi
vyšší než `version()` builderu a formát `json`. Jazyk je z obálky,
parametr ho přebije: mění překlady šablony, popisky v `data`
i `meta.watermark` zůstávají, jak jsou.

### Jazyk tisku (#94 D2–D4)

Rozlišují se **jazyky dokumentů** (cfgItem `world.base.documentLanguages`:
`cs`, `en`, `sk`, `de` — to, co lze nastavit na osobě) a **jazyky tisku**
(`PrintLanguageResolver::LANGUAGES`: `cs`, `en` — jazyky, pro které
existují katalogy šablon a kompilovaná konfigurace).

1. **Výslovný parametr** (REST `language`, CLI `--language`) — validuje se
   proti jazykům tisku, jiná hodnota je chyba (400). Stranu tisku nehledá.
2. Jinak **jazyk dokumentu** z `Shipard\Core\I18n\DocumentLanguageResolver`
   (čistá služba, použije ji i odeslání e-mailem ve fázi 4):
   1. jazyk osoby partnera (`base_persons_persons.language`), je-li mezi
      jazyky dokumentů;
   2. **hlavní** jazyk země strany — první položka `languages` v
      `world.base.countries`; není-li mezi jazyky dokumentů → `en`
      (CH → `de`, BE / LU / IE / FR → `en`);
   3. země chybí nebo je neznámá (i doklad bez partnera) → hlavní jazyk
      **vlastní země** zdroje dat (`DataSourceConfig::getCountry()`),
      stejným pravidlem.
3. Jazyk dokumentu bez katalogu tisku (`sk`, `de`) se tiskne **anglicky**
   a tisk nese měkké hlášení `language.unavailable` (bez názvu partnera).

Stranu tisku dodává builder přes volitelné rozhraní `PrintPartyProvider`
→ `PrintParty {personLanguage, country}`. `DocPrintBuilder` (a s ním
pokladní doklad a prodejka): partner = `head.partner`; jazyk osoby se čte
**živě** z osoby — partner si řekne o jiný jazyk, na osobě se změní
a doklad se vytiskne znovu bez zásahu do dokladu (vědomá výjimka z D5);
země je `address.country` z **partnerského snapshotu** dokladu podle směru
obchodu (`DocPrintContext::partnerSnapshot()`, stejné pravidlo jako
`partner()`). Doklad bez partnera nebo bez jeho snapshotu stranu nemá.
Builder bez rozhraní a tisk s `audience: internal` (Kontace) stranu
nehledají — tisknou v hlavním jazyce vlastní země.

`defaultLanguage` zdroje dat se pro jazyk tisku **nepoužívá**: je to jazyk
rozhraní a jeho fallback `en` by tuzemcům tiskl anglicky. Oproti fázi 1 je
to změna chování — zdroj dat s `defaultLanguage: en` a českou zemí tiskne
Kontaci i doklady tuzemským partnerům česky.

Konfigurace pro dotaz na stranu (`printParty()` z ní čte jen klíče
cfgItemů, např. směr typu dokladu) se bere v záložním jazyce `en`;
je-li to zároveň jazyk tisku, podruhé se nenačítá.

### Volby osoby pro odeslání (kontrakt pro fázi 4, #94 D5 / D6 / D11)

Samotné odeslání dokladu e-mailem je fáze 4; data, ze kterých bude číst,
už existují:

- **Které přílohy se posílají:** přílohy záznamu s
  `core_attachments_files.send_with_record = 1` a `is_deleted = 0`, v pořadí
  `att_order` (přepínač „Odeslat s dokladem“ v tabu Přílohy, viz
  [attachments.md](attachments.md) §4). Žádný výběr při odeslání —
  automatické odesílání (D11) čte jen příznak.
- **Jak se posílají:** `base_persons_persons.send_attachments_merged` osoby
  partnera, čtené **živě** jako jazyk. `1` = PDF přílohy se připojí za PDF
  dokladu (`RenderClient` `appendPdfs`), ostatní soubory jdou do e-mailu
  samostatně, bez konverze. `0` = všechny přílohy samostatně.
- **Jazyk e-mailu** = jazyk dokumentu z `DocumentLanguageResolver` (stejná
  strana jako tisk; texty e-mailu per jazyk dokumentů, D9) — na rozdíl od
  tisku tedy i `sk` / `de`, jakmile pro ně texty budou.

Builder hlásí dvojí druh problému: **tvrdý** výjimkou `PrintBuildException`
(doklad bez snapshotu vlastní strany — tisk ven nesmí číst z dnešního
adresáře) a **měkký** jako `PrintMessage` v `messages` (QR platba
nevznikla, doklad nemá účetní zápisy).

## 4. Kontrakt `PrintData`

Obálka je stejná pro všechny tisky, `data` patří tisku:

```jsonc
{
    "printId": "docs.invoicesOut.invoice",
    "version": 1,                 // verze kontraktu `data`, zvyšuje builder
    "language": "cs",
    "record": { "table": "docs_core_heads", "id": 123, "docState": 40 },
    "generatedAt": "2026-10-02T10:30:00+02:00",
    "meta": { "title": "Faktura – daňový doklad 2026000123",
              "fileName": "faktura-2026000123.pdf",
              "watermark": null },          // text přes každou stranu („STORNO“)
    "branding": { "logo": "logo.png" },     // null bez loga
    "texts": {},                            // sloty textů na tiscích — fáze 3
    "messages": [ { "severity": "warning", "code": "payment.qrNoAccount", "text": "…" } ],
    "data": { … }
}
```

**Hodnoty (D13):** částky a množství jsou čísla v plné přesnosti, data ISO
`YYYY-MM-DD`. Nic se v builderu nezaokrouhluje ani neformátuje. Popisky
číselníků řeší builder v jazyce tisku.

`PrintData::fromArray()` staví obálku zpět z JSON: povinné jsou `printId`,
`version`, `language`, `record`, `meta` a `data`, zbytek má výchozí
hodnoty.

### 4.1 `data` tisků dokladů nad `docs_core_heads`

`DocPrintBuilder` skládá bloky z `modules/docs/core/src/Prints/Blocks/`.
SQL je jen v `DocPrintContext::load()`; bloky jsou čisté funkce nad
kontextem. Další tisk dokladu dědí builder a v `blocks()` blok přidá,
nahradí nebo vynechá:

| Builder | Tisk | Proti `DocPrintBuilder` |
|---|---|---|
| `DocPrintBuilder` | faktura, zálohová faktura | — |
| `CashDocPrintBuilder` | pokladní doklad | `dates` s `paymentReceived`, navíc `cashDesk` |
| `CashRegisterPrintBuilder` | prodejka | navíc `cashDesk` |

| Klíč | Blok | Obsah |
|---|---|---|
| `document` | `DocDocumentBlock` | `type`, `tradeDir` (1 výstup, 2 vstup, null bez směru — `DocDocument::resolveTradeDir()`), `titleVariant`, `title`, `number`, `text`, `notice`, `isTaxDocument`, `vatPayer`, `vatMode` (0 bez DPH / 1 ze základu / 2 z ceny celkem), `currency`, `homeCurrency`, `exchangeRate` (null v domácí měně), `foreignCurrency` |
| `dates` | `DocDatesBlock` | `issue`, `due`, `duzp` (null u nedaňového dokladu), `periodFrom`, `periodTo`; pokladní doklad (`DocCashDatesBlock`) navíc `paymentReceived` — den přijetí platby, jen na příjmu s DPH |
| `supplier`, `customer` | `DocPartiesBlock` | snapshoty hlavičky **beze změny tvaru** (`PersonSnapshotBuilder`); **`null`**, když doklad stranu nemá (D24) |
| `payment` | `DocPaymentBlock` | `method {id, label}`, `bankTransfer` (platí se převodem), `reference`, `specificSymbol`, `constantSymbol`, `bankAccount` (ze snapshotu dodavatele, **jen u převodu**, jinak null), `amountToPay`, `currency`, `qr {standard, payload}` nebo null |
| `rows` | `DocRowsBlock` | `kind: item` — `description`, `quantity`, `unit {id, label}`, `unitPrice`, `unitPriceIncludesVat`, `discountPct`, `vat {code, pct, label, noteMark}`, `base`, `vatAmount`, `total`, `advanceDeduction`; `kind: text` — jen `description` |
| `vatRecap` | `DocVatRecapBlock` | `label`, `pct`, `base`, `tax`, `total`, `baseDom`, `taxDom`, `totalDom`, `noteMark` |
| `vatNotes` | `DocVatRecapBlock` | `{mark, text}` — každá poznámka jednou |
| `advances` | `DocAdvancesBlock` | `{base, vat, total}` kladně, nebo null |
| `totals` | `DocTotalsBlock` | `base`, `vat`, `rounding`, `total`, `totalBeforeAdvances`, `baseDom`, `vatDom`, `totalDom` |
| `cashDesk` | `DocCashDeskBlock` | jen pokladní doklad a prodejka: `{id, code, name}` z číselníku pokladen (aktuální, D14) nebo null |

Pravidla:

- **Plátce** = hlavička má `vat_registration`. DPH se tiskne, když je
  doklad plátce a `vatMode ≠ 0`; jinak `rows[].vat` je null a `vatRecap`
  prázdný.
- **Titulek (D16, D25, D26):** `TitleVariantResolver` nad
  `DocTitleContext` (typ, plátce, směr, zda má doklad tištěnou
  rekapitulaci DPH, celková částka); text z katalogu pod
  `title.<varianta>`, název souboru pod `fileName.<varianta>`:

  | Typ | Podmínka | Varianta | Titulek `cs` |
  |---|---|---|---|
  | `invno` | plátce | `invoiceVatPayer` | Faktura – daňový doklad |
  | `invno` | neplátce | `invoiceNonVatPayer` | Faktura |
  | `invpo` | — | `proforma` | Zálohová faktura |
  | `cash` | příjem, plátce, rekapitulace DPH neprázdná | `cashInTaxDocument` | Příjmový pokladní doklad – daňový doklad |
  | `cash` | příjem, jinak | `cashIn` | Příjmový pokladní doklad |
  | `cash` | výdej | `cashOut` | Výdajový pokladní doklad |
  | `cashreg` | celková částka < 0 | `cashRegisterRefund` | Prodejka – vratka |
  | `cashreg` | plátce | `cashRegisterVatPayer` | Prodejka – daňový doklad |
  | `cashreg` | neplátce | `cashRegisterNonVatPayer` | Prodejka |

  Vratka má přednost před plátcovstvím (správný titulek vratky plátce
  doladí #92). Neznámý typ dokladu nebo pokladní doklad bez směru =
  `PrintBuildException`. `correctiveVatPayer` / `correctiveNonVatPayer`
  jsou rezervované (#92).
- **Strany a náš účet ze snapshotů (D5, D14)**; jednotky, tiskové popisky
  DPH, pokladna a logo jsou aktuální v okamžiku tisku.
- **Povinné strany (D24):** snapshot **vlastní strany** (výstup →
  `supplier`, vstup → `customer`) je povinný, **partnerský** jen když
  hlavička má `partner` — pokladní doklad a prodejka ho mít nemusí. Chybí-li
  povinný, tisk skončí chybou (`PRINT_DATA_MISSING`). Strana, kterou doklad
  nemá, je `null` a šablona ji vynechá: místo zůstane prázdné, bez
  zástupného textu. Zápatí a země DPH se berou z vlastní strany podle
  `tradeDir`.
- **Platba převodem:** `payment.bankTransfer` řídí v šablonách splatnost,
  účet, QR a popisek „K úhradě“ — doklad zaplacený hotově nebo kartou má
  jen způsob úhrady a částku „Celkem“.
- **Rekapitulace:** řádky `is_reverse_pair` se netisknou. Popisek =
  `vatCodes[].print` z `world.vat.<země>` (země z registrace ve snapshotu).
  Značky poznámek přiděluje `DocVatCodes` v pořadí prvního použití; stejný
  text má jednu značku, sdílenou řádky i rekapitulací.
- **Zálohy (D18):** řádky `sale.advanceDeduction` zůstávají v `rows`
  s `advanceDeduction: true`. `totals.total` je částka po odpočtu,
  `totalBeforeAdvances = total + advances.total`.
- **`*Dom` hodnoty** jen u dokladu v cizí měně, jinak null.
- **QR (D17):** jen platba převodem a kladná částka. `PaymentQrResolver`
  volí standard podle země odběratele (v1 vždy SPAYD); `SpaydGenerator`
  skládá `ACC`, `AM`, `CC`, `X-VS`, `X-SS`, `X-KS`, `DT`, `MSG`. IBAN ze
  snapshotu, jinak dopočet z českého čísla účtu (`CzIbanCalculator`).
  Bez účtu `qr = null` + varování; symbol mimo 1–10 číslic se z kódu
  vynechá + varování.
- **Název souboru:** slug `fileName.<varianta>` z katalogu + číslo dokladu.

Kontrakt hlídají integrační testy v `tests/Integration/Prints/` proti
fixture v `tests/Fixtures/Prints/` (D15) — každá je celá obálka
`PrintData`, takže jde rovnou do `print-run --data`. Nekompatibilní změna
`data` = zvýšit `VERSION` builderu a upravit fixture; přidané pole verzi
nemění.

### 4.2 `data` tisku Kontace

`DocJournalPrintBuilder` (`economy.accounting`) — účetní zápisy jednoho
dokladu, interní tisk (`audience: internal`) pro všechny typy dokladů ve
stavu V pořádku. Stejné řádky, sloupce a dimenze jako tab Zaúčtování
v detailu dokladu; popisky dimenzí oběma dává
`Core\Accounting\JournalDimensionLabels`.

| Klíč | Obsah |
|---|---|
| `document` | jako `DocDocumentBlock` (`DocDocumentBlock::describe()`), ale bez `titleVariant`; navíc `typeName` (název typu dokladu), `title` = „Kontace“ |
| `dates` | `issue`, `accounting` (účetní datum), `duzp` (null u nedaňového dokladu) |
| `accountingUnit` | vlastní firma: snapshot vlastní strany, a když ho doklad nemá (účetní doklad bez směru), **aktuální data** vlastní firmy — interní tisk to smí (D2); null jen na DS bez vlastní firmy |
| `partner` | partnerský snapshot, nebo null |
| `accounting` | `state` (`accounting_state`), `stateLabel` |
| `dimensions` | `[{id, label}]` — dimenze deníku, které některý zápis nese |
| `journal` | řádky deníku v pořadí `id`: `accountNumber`, `accountName` (aktuální název z rozvrhu, D14; null u neznámého účtu), `text`, `debit`, `credit` (nulová strana = null), `debitCur`, `creditCur` (jen u dokladu v cizí měně), `dimensions {id: popisek \| null}`, `isError` |
| `totals` | `debit`, `credit`, `debitCur`, `creditCur` (null v domácí měně) |

`meta.title` = „Kontace <název typu> <číslo>“, soubor `kontace-<číslo>.pdf`.
Tisk nikdy neselže kvůli stranám (`DocPrintContext::forHead()` snapshoty
nevyžaduje). Doklad bez zápisů → `journal: []` a varování `noJournal`;
stav účtování „chyba“ → varování `accountingError`. `TitleVariantResolver`
se nepoužívá.

## 5. Šablony

Umístění `modules/<modul>/prints/<adresář>/`, Twig namespace = id modulu:

```
modules/docs/core/prints/
    _layout/
        doc-base.html.twig    # kostra stránky dokladu s bloky
        header.html.twig      # záhlaví: logo, titulek, číslo dokladu
        footer.html.twig      # zápatí: vlastní firma, stránkování
        doc-base.css
        messages.jsonc        # společný katalog
    _partials/
        parties.html.twig, party.html.twig, payment.html.twig,
        rows.html.twig, vat-recap.html.twig, totals.html.twig,
        watermark.html.twig

modules/docs/invoicesOut/prints/invoice/
    page.html.twig            # extends doc-base, beze změn
    messages.jsonc            # titulky, název souboru

modules/docs/proformasOut/prints/proforma/
    page.html.twig            # + věta „Nejedná se o daňový doklad.“
    messages.jsonc

modules/docs/cashDocs/prints/cash/
    page.html.twig            # data s pokladnou, podpisy podle směru
    messages.jsonc            # titulky, popisky stran „… / přijal“, „… / vydal“

modules/docs/cashRegister/prints/receipt/
    page.html.twig            # data s pokladnou, splatnost jen u převodu
    messages.jsonc

modules/economy/accounting/prints/docJournal/
    page.html.twig            # tabulka zápisů místo řádků dokladu
    footer.html.twig          # zápatí s účetní jednotkou
    doc-journal.css
    messages.jsonc
```

`PrintRenderer` skládá tisk ze tří dokumentů a assetů:

- **`page.html.twig`** z adresáře šablony — povinná. Stránka typu dokladu
  je tenká: dědí `doc-base` a přepisuje jen bloky, kterými se liší
  (`styles`, `title`, `parties`, `dates`, `payment`, `rows`, `vatRecap`,
  `totals`, `notes`, `signatures`). `styles` (v `<head>`) a `signatures`
  (na konci) jsou v layoutu prázdné: první je pro `<link>` na vlastní CSS
  šablony, druhý pro podpisová pole (`.doc-signatures` / `.doc-signature`
  — prázdné linky, bez jmen, #93).
- **`header.html.twig` / `footer.html.twig`** — hledají se v adresáři
  šablony, pak ve sdílených adresářích (`catalogs`). Jsou to **samostatné
  HTML dokumenty** pro render službu, tisknou se na každé straně: styly
  inline, logo jako data URI (`branding.logoDataUri`), vodorovné odsazení
  shodné s okraji stránky. Čísla stran doplní render služba do prvků
  `class="pageNumber"` / `class="totalPages"`. Výšku záhlaví musí pokrýt
  `paper.margins.top`. **Pozor na názvy tříd:** prvky s třídami `title`,
  `date`, `url`, `pageNumber` a `totalPages` Chromium v záhlaví a zápatí
  přepisuje vlastním obsahem (`title` = `<title>` stránky) — vlastní prvky
  proto pojmenuj jinak (`head-title`).
- **Assety** — soubory `css`, obrázky a fonty ze sdílených adresářů
  a z adresáře šablony (šablona má přednost); pushují se s HTML
  a referencují relativně (`<link href="doc-base.css">`). Logo z brandingu
  (slot `companyLogo`) jde jako asset pod názvem z `branding.logo`.

Šablona dostává `PrintData::toArray()`: proměnné `printId`, `language`,
`record`, `meta`, `branding`, `texts`, `messages`, `data`.

### Vodoznak (D23)

`doc-base` zahrnuje `_partials/watermark.html.twig`: je-li `meta.watermark`
vyplněný, vykreslí ho jako `.doc-watermark` — šikmý světle šedý text pod
obsahem, `position: fixed`, takže ho tisk opakuje **na každé straně**.

Pravidlo: **layout tisku, jehož deklarace má `watermarks`, musí
`meta.watermark` vykreslit** — jinak by stornovaný doklad vyšel jako
platný. Šablona dědící `doc-base` ho plní sama; vlastní layout musí partial
zahrnout. Hlídá to `PrintWatermarkRuleTest`: pro každou deklaraci
s `watermarks` vyrenderuje fixture z `tests/Fixtures/Prints/` (hledá ji
podle `printId`) a ověří text vodoznaku v HTML — nový tisk s vodoznakem
proto potřebuje fixture.

### Sandbox (D6)

`PrintTwigFactory` staví prostředí per běh: `autoescape: html`,
`strict_variables: true` (překlep je chyba, ne prázdné místo na faktuře —
nepovinné klíče snapshotu proto čti přes `|default`), cache
v `<ds>/cache/twig` s `auto_reload`. `SandboxExtension` je zapnutý
globálně se striktní politikou `PrintSecurityPolicy::templates()`:

| | Povoleno |
|---|---|
| Tagy | `if`, `for`, `set`, `block`, `extends`, `include`, `apply` |
| Filtry | `escape`, `e`, `default`, `length`, `join`, `upper`, `lower`, `nl2br`, `first`, `last`, `keys`, `merge`, `money`, `qty`, `pct`, `date` |
| Funkce | `t`, `qr_svg`, `block`, `parent`, `include` |
| Testy | `defined`, `null`, `none`, `empty`, `same as`, `even`, `odd`, `iterable` |
| Metody a vlastnosti objektů | žádné |

Úzkou politiku pro uživatelské texty (D9) zavede fáze 3.

### Filtry a funkce (`PrintTwigExtension`)

Formát podle jazyka tisku; mezery uvnitř hodnot jsou nezlomitelné.

| | `cs` | `en` |
|---|---|---|
| `1210.5\|money` | `1 210,50` | `1,210.50` |
| `1210.5\|money('EUR')` | `1 210,50 EUR` | `1,210.50 EUR` |
| `1.5\|qty` (bez zbytečných nul, nejvýš 4 místa) | `1,5` | `1.5` |
| `21\|pct` | `21 %` | `21%` |
| `'2026-10-02'\|date` | `2. 10. 2026` | `10/2/2026` |

Filtr `date` **přepisuje vestavěný Twig filtr** stejného jména — bere jen
ISO datum, nic jiného. `t('klíč', {param: hodnota})` čte katalog tisku,
`qr_svg(data.payment.qr)` vrací inline SVG (pro null prázdný řetězec).

### Překlady (D8)

`messages.jsonc` = `{ "klíč": { "cs": "…", "en": "…" } }`.
`PrintCatalogLoader` slévá katalogy z `catalogs` a nakonec katalog šablony
(pozdější klíč vyhrává). `PrintTranslator`: chybějící jazyk → `cs`,
chybějící klíč → vrátí klíč a zaloguje warning. Úplnost katalogů ve všech
jazycích tisku hlídá `PrintDeclarationsTest`.

## 6. REST

`GET /_prints/{printId}/{recordId}?format=pdf|json[&language=cs|en]`

Tabulku určuje deklarace tisku. Práva (D21): tisk = čtení záznamu —
`TableAccessGuard::guardTable()` na tabulku deklarace. `format=json` jen
pro administrátora. Na read-only zdroji dat povoleno. `format=html` REST
nenabízí (400) — je to nástroj CLI.

- `pdf` (default): `application/pdf`, `Content-Disposition: inline;
  filename="…"` (z `meta.fileName`). Měkká hlášení builderu i runneru
  (jazyk bez katalogu, §3) nese hlavička **`X-Print-Messages`** —
  procentově kódované JSON pole `messages`.
- `json`: `{success, data}`, `data` = `PrintData`.

| Kód | HTTP | Kdy |
|---|---|---|
| `PRINT_NOT_FOUND` | 404 | neznámé id tisku |
| `RECORD_NOT_FOUND` | 404 | záznam neexistuje |
| `PRINT_NOT_AVAILABLE` | 409 | stav nebo typ záznamu tisk nedovoluje |
| `PRINT_DATA_MISSING` | 409 | záznamu chybí data pro tisk (snapshot vlastní strany, nebo partnera u dokladu s partnerem) |
| `BAD_REQUEST` | 400 | neplatný `format` / `language` |
| `FORBIDDEN_ADMIN_ONLY` | 403 | `format=json` bez práv administrátora |
| `RENDER_UNAVAILABLE` | 503 | render služba `unconfigured` / `unreachable` / `timeout` |
| `RENDER_FAILED` | 500 | `engineError` / `invalidInput` |

U obou render chyb je `errorKind` v `details[0].code`.

## 7. UI

`ViewerController::detail()` po `renderDetail()` připojí na konec
`detail.actions` akci tisku, když `PrintRegistry::forRecord()` něco vrátí.
Je to generický háček — viewer o tisku neví, takže tisk další tabulky
nevyžaduje zásah do jejího vieweru.

- jeden tisk: `{id: "print", kind: "button", target: {printId}}`
- víc tisků: `{id: "print", kind: "dropdown", items: [{label, value}]}`,
  `value` = id tisku — doklad s vlastním tiskem ve stavu V pořádku nabízí
  tisk dokladu a Kontaci; stornovaný jen tisk dokladu, ostatní doklady jen
  Kontaci

Popisek je v `core.system.viewerDefaults.detailActions.print`.

Frontend: `Viewer.svelte::handleDetailAction` otevře
`PrintPreviewDialog.svelte` — PDF stáhne jako Blob (`api/prints.js`, Bearer
auth), ukáže v `<iframe>` z object URL, nad náhledem vypíše `messages`,
**Stáhnout** uloží soubor pod názvem ze serveru. Prohlížeč bez vestavěného
prohlížeče PDF (`navigator.pdfViewerEnabled === false`) dostane jen
Stáhnout. Čtecí `ViewerDetailModal` akce detailu nezobrazuje. Ve formuláři
tisk není (D19).

## 8. CLI

```bash
shpd-ds print-run <printId> [<recordId>] [--format=json|pdf|html]
                  [--language=cs|en] [--output=<cíl>] [--data=<PrintData.json>]
```

`json` (default) vypíše `PrintData` na stdout, `pdf` vyžaduje
`--output=<soubor>`. Nástroje pro vývoj šablon (D28):

- **`--format=html --output=<adresář>`** zapíše přesně to, co jde do render
  služby: `index.html`, `header.html`, `footer.html` a assety pod svými
  jmény. `index.html` jde otevřít v prohlížeči; záhlaví a zápatí jsou
  samostatné dokumenty, do okrajů je skládá až render služba.
- **`--data=<soubor>`** renderuje hotový `PrintData` (`html` nebo `pdf`)
  bez databáze, kontroly dostupnosti a builderu — `PrintRunner::renderData()`
  (§3). `recordId` se nezadává.

Viz [cli.md](cli.md).

## 9. Jak přidat tisk

Příklad: karta majetku (`economy.assets`).

1. **Builder** `modules/economy/assets/src/Prints/AssetCardPrintBuilder.php`
   implementuje `PrintBuilder`: z `PrintRequest` načte kartu a události
   a vrátí `PrintBuildResult` (`data`, titulek, název souboru, hlášení);
   `version()` vrací verzi kontraktu `data`. Popisky číselníků čte
   z `$request->config` — je v jazyce tisku. Kontrakt `data` popiš
   v dokumentaci modulu. Vzor tisku mimo doklady ven:
   `DocJournalPrintBuilder` (Kontace). Tisk určený partnerovi
   (`audience: external`) nad jinou tabulkou než doklady implementuje
   i `PrintPartyProvider` — jinak se tiskne v jazyce vlastní země (§3).
2. **Šablona** `modules/economy/assets/prints/card/page.html.twig`
   a `messages.jsonc` (`cs` i `en`). Vlastní `header.html.twig` /
   `footer.html.twig` a CSS polož vedle, nebo do sdíleného adresáře
   a uveď ho v `catalogs`.
3. **Deklarace** v `config/prints.jsonc` + `"prints": [{"file": …}]`
   v `module.jsonc`: `table`, `docStates`, `audience: "internal"`,
   `builder`, `template`, okraje podle výšky záhlaví.
4. **Testy:** fixture záznam → porovnání `data` s JSON
   (`tests/Fixtures/Prints/`, celá obálka `PrintData`), render šablony do
   HTML (`PrintRenderer::renderDocument()` — bez render služby).
   `PrintDeclarationsTest` nový tisk zkontroluje sám (builder, šablona,
   úplnost katalogů), `PrintWatermarkRuleTest` vodoznak.
5. `ds-upgrade` není potřeba — deklarace se čtou z modulů při requestu.
   Akce Tisk se v detailu objeví sama.

Vývoj šablony bez opakovaného sahání do databáze:

```bash
shpd-ds print-run <id> <záznam> > data.json           # jednou: data builderu
shpd-ds print-run <id> --data=data.json \
    --format=html --output=/tmp/tisk                   # po každé úpravě šablony
shpd-ds print-run <id> --data=data.json \
    --format=pdf --output=/tmp/tisk.pdf                # kontrola stránkování v PDF
```

Místo vlastního `data.json` poslouží i fixture z `tests/Fixtures/Prints/`;
úpravou JSON (víc řádků, `meta.watermark`, `customer: null`) vyzkoušíš
stavy, pro které v databázi doklad není.

## 10. Testy

- Unit `tests/Unit/Core/I18n/DocumentLanguageResolverTest.php` — tabulka
  odvození jazyka nad skutečnými číselníky `world.base`;
  `tests/Unit/Core/Prints/` — deklarace, registr, runner (včetně
  `renderData()` a volby jazyka), obálka `PrintData`, katalogy, pravidlo vodoznaku, Twig
  filtry a sandbox; `tests/Unit/Module/Docs/Core/Prints/` — bloky, titulky,
  strana tisku (`DocPrintPartyTest`), QR platba, šablony dokladů a Kontace do HTML;
  `tests/Unit/Command/DataSource/PrintRunCommandTest.php` — `--format=html`
  a `--data` bez databáze; `tests/Unit/Api/Controller/PrintsApiTest.php`
  — routa, controller, akce v detailu.
- Integrační `tests/Integration/Prints/` — kontrakt nad fixture doklady
  v dev DS (`PrintFixtureDocuments`; pokladní doklady a prodejky potřebují
  pokladnu a její řady), CLI. `PrintPdfTest` jde přes celou cestu do PDF
  (včetně vodoznaku na každé straně vícestránkového storna) a vedle
  `SHIPARD_INTEGRATION_DS_PATH` potřebuje
  `SHIPARD_INTEGRATION_GOTENBERG_URL`.
