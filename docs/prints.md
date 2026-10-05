# Tisky — PDF nad jedním záznamem

Doména `print` ([reports.md](reports.md) §1): výstup nad **jedním záznamem**
— faktura vydaná, zálohová faktura, pokladní doklad, prodejka, Kontace,
později karta majetku. Rozhodnutí D1–D52 jsou v issue #90, zadání
v `tasks/prints-phase1.md`, `tasks/prints-phase2.md`,
`tasks/prints-languages.md`, `tasks/prints-phase4.md`
a `tasks/prints-phase3.md`.

Hotovo: kontrakt `PrintData`, infrastruktura (deklarace, registr, runner,
Twig, PDF), REST, CLI včetně nástrojů pro vývoj šablon, akce Tisk
v detailu, vodoznak storna, jazyky tisku `cs` / `en` / `sk` / `de`
s přepínačem v náhledu (§3), odesílání e-mailem (§9), vzhled a vlastní
texty na tiscích včetně textů e-mailu (§12) a tisky:

| Tisk | Modul | Co |
|---|---|---|
| `docs.invoicesOut.invoice` | `docs.invoicesOut` | faktura vydaná |
| `docs.proformasOut.proforma` | `docs.proformasOut` | zálohová faktura vydaná |
| `docs.cashDocs.cash` | `docs.cashDocs` | pokladní doklad, příjmový i výdajový |
| `docs.cashRegister.receipt` | `docs.cashRegister` | prodejka (A4) |
| `economy.accounting.docJournal` | `economy.accounting` | Kontace — interní tisk účetních zápisů dokladu (§4.2) |

Nehotovo: hromadné a automatické odesílání (D11), revize slovenských
a německých formulací (D32, `tasks/prints-languages.md`), QR pro další
země (#91), opravný daňový doklad (#92), „Vystavil“ a jména u podpisů
(#93), vlastní šablony per zdroj dat (D7), účtenka na POS tiskárnu, EET.

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

Třídy jádra žijí v `src/Core/Prints/` (uživatelské texty
v `src/Core/Prints/Texts/`), tisky dokladů v `modules/docs/core/src/Prints/`,
Kontace v `modules/economy/accounting/src/Prints/`. Nastavení vzhledu
a agendu textů drží modul `core.prints` (§12).

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
| `sendPurpose` | ne | Účel odesílání — id z cfgItemu `base.persons.sendPurposes` (D34). Tisk s ním jde **odeslat e-mailem** (§9); jen u `audience: external`, jinak chyba loaderu |
| `recipientPerson` | se `sendPurpose` | Sloupec záznamu s osobou příjemce (doklady `partner`) — podle ní se hledají adresy |
| `textSlots` | ne | Sloty uživatelských textů, které šablona tisku vykreslí (§12.2) — hodnoty `PrintTextSlot`. E-mailové sloty jen se `sendPurpose`, jinak chyba loaderu. Chybí = tisk texty nenese (Kontace) |
| `textVariables` | ne | Proměnné, které formulář textu nabídne (§12.5): položka s `@` je sdílená sada (`@docs.core/_layout`), jinak proměnná — cesta, nebo `{path, filter?}`. Jen u tisku s `textSlots` |

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
   Zdroj dat bez kompilované konfigurace v jazyce tisku →
   `PrintLanguageNotCompiledException` (viz Jazyky tisku a kompilace).
5. Obálka `PrintData`: verze kontraktu z `PrintBuilder::version()`,
   `meta.watermark` = přeložený text klíče z `watermarks` pro stav
   záznamu, jinak `null`; `branding` = logo + vzhled z nastavení zdroje
   dat (§12.1).
6. Uživatelské texty (§12): má-li tisk `textSlots` a zdroj dat modul
   `core.prints`, runner vybere texty platné **ke dni tisku** a vykreslí
   je do `texts`. Chybný text se vynechá a přidá hlášení `textError` —
   tisk vznikne. `json` tím končí; `pdf` a `html` pokračují rendererem
   (`html` = `PrintDocument` bez render služby, jen CLI).
7. Render služba PDF nevyrobí → `PrintRenderException` s `errorKind`
   (provozní stav, ne programátorská chyba).

`PrintRunner::renderData($printId, $envelope, PrintFormat, ?$language)`
vstupuje až do kroku renderu s hotovým `PrintData` (pole z JSON) — bez
záznamu, kontroly dostupnosti a builderu, runner k tomu nepotřebuje ani
spojení do databáze (D28). Odmítne (`InvalidArgumentException`) obálku
s neplatným tvarem (`PrintData::fromArray()`), data jiného tisku, verzi
vyšší než `version()` builderu a formát `json`. Jazyk je z obálky,
parametr ho přebije: mění překlady šablony, popisky v `data`
i `meta.watermark` zůstávají, jak jsou. `branding` a `texts` se berou
z obálky — texty se znovu nevybírají ani nevykreslují.

### Jazyk tisku (#94 D2–D4)

Rozlišují se **jazyky dokumentů** (cfgItem `world.base.documentLanguages`
— to, co lze nastavit na osobě) a **jazyky tisku**
(`PrintLanguageResolver::LANGUAGES` — jazyky, pro které má tisk překlady
a formáty). Dnes jsou oba seznamy stejné: `cs`, `en`, `sk`, `de`.

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
3. Jazyk dokumentu, který mezi jazyky tisku není (jazyk přidaný do
   `world.base.documentLanguages` dřív než překlady), se tiskne
   **anglicky** a tisk nese měkké hlášení `language.unavailable` (bez
   názvu partnera).

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

### Jazyky tisku a kompilace konfigurace (D29)

Jazyk tisku potřebuje čtyři věci; úplnost hlídají testy (§11):

| Co | Kde | Hlídá |
|---|---|---|
| kompilovaná konfigurace `compiled.<jazyk>.json` | `ds-upgrade` | `PrintConfigLanguagesTest` |
| popisky konfigurace, kterou tisk čte | varianty `:<jazyk>` v JSONC modulů | `PrintConfigLanguagesTest` |
| katalogy všech šablon | `messages.jsonc` | `PrintDeclarationsTest` |
| formát čísel a dat | `PrintTwigExtension::FORMATS` | `PrintTwigExtensionTest` |

**Kompilace.** `ds-upgrade` kompiluje konfiguraci pro jazyky rozhraní
(`cs`, `en`) a pro každý jazyk dokumentů — `ConfigCompiler::languages()`
je čte ze surového `world.base.documentLanguages`, kompilát ještě
neexistuje. Zdroj dat, který po přidání jazyka neprošel `ds-upgrade`,
kompilát nemá: tisk v tom jazyce skončí
`PrintLanguageNotCompiledException` (409 `PRINT_LANGUAGE_NOT_COMPILED`),
ne tiskem bez popisků. Platí i pro jazyk odvozený z partnera — po nasazení
nového jazyka proto `ds-upgrade` na všech zdrojích dat.

**Popisky konfigurace.** Kompilát chybějící variantu tiše nahradí
angličtinou (`LocalizedFieldResolver`: `:<jazyk>` → `:en` → holé pole),
proto test čte surová JSONC a vyžaduje **vlastní** variantu v každém
jazyce tisku (čeština smí být holé pole). Tisk čte:

| cfgItem | Pole | Čte |
|---|---|---|
| `world.vat.<země>` | `vatCodes[].print` (bez něj `name`), `vatNotes[].text` | `DocVatCodes` |
| `docs.core.paymentMethods` | `name` | `DocPaymentBlock` |
| `docs.core.docTypes` | `name` | `DocJournalPrintBuilder` |
| `economy.accounting.accountingStates` | `name` | `DocJournalPrintBuilder` |
| `core.units.printShortcuts` | `shortcut` | `DocRowsBlock` |
| `journalDimensions[]` v `module.jsonc` | `name` | Kontace (záhlaví sloupců dimenzí) |

Data zdroje se nepřekládají: texty řádků, poznámky, názvy pokladen, účtů
a hodnot dimenzí se tisknou, jak jsou.

**Zkratky jednotek (D31).** `core_units.shortcut` je český („ks“, „hod“).
`DocRowsBlock` proto v jiném jazyce než českém bere zkratku systémové
jednotky z `core.units.printShortcuts` (klíč = `system_code`; `pcs` je
anglicky „pcs“, slovensky „ks“, německy „Stk“); česky zkratku z dat, aby
platila úprava zkratky ve zdroji dat. Jednotka bez `system_code` (založená ve zdroji dat) se tiskne
vždy tak, jak je. `PrintShortcutsTest` hlídá, že každá jednotka seedu má
zkratku ve všech jazycích tisku a česká odpovídá seedu.

### Volby osoby pro odeslání (#94 D5 / D6 / D11)

Odeslání dokladu e-mailem (§9) čte:

- **Které přílohy se posílají:** přílohy záznamu s
  `core_attachments_files.send_with_record = 1` a `is_deleted = 0`, v pořadí
  `att_order` (přepínač „Odeslat s dokladem“ v tabu Přílohy, viz
  [attachments.md](attachments.md) §4). Dialog odeslání je podle příznaku
  předvybere a uživatel může výběr pro jednu zprávu změnit; automatické
  odesílání (D11) bude číst jen příznak.
- **Jak se posílají:** `base_persons_persons.send_attachments_merged` osoby
  partnera, čtené **živě** jako jazyk. `1` = PDF přílohy se připojí za PDF
  dokladu (`RenderClient` `appendPdfs`), ostatní soubory jdou do e-mailu
  samostatně, bez konverze. `0` = všechny přílohy samostatně.
- **Jazyk e-mailu** = jazyk tisku: předmět, tělo i PDF v příloze jsou
  v jednom jazyce (D37), podle pravidla výše nebo podle volby v dialogu.

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
    "branding": { "logo": "logo.png",       // null bez loga
                  "logoPlacement": "left",  // left | right (§12.1)
                  "accentColor": "#c8c8c8" },  // vždy `#rrggbb`
    "texts": { "afterRows": "<div class=\"print-text\">…</div>",
               "emailSubject": "…" },       // slot → HTML / prostý text (§12)
    "messages": [ { "severity": "warning", "code": "payment.qrNoAccount", "text": "…" } ],
    "data": { … }
}
```

**Hodnoty (D13):** částky a množství jsou čísla v plné přesnosti, data ISO
`YYYY-MM-DD`. Nic se v builderu nezaokrouhluje ani neformátuje. Popisky
číselníků řeší builder v jazyce tisku.

`PrintData::fromArray()` staví obálku zpět z JSON: povinné jsou `printId`,
`version`, `language`, `record`, `meta` a `data`, zbytek má výchozí
hodnoty — JSON z doby před nastavením vzhledu dostane logo vlevo
a neutrální akcent. `branding.accentColor` jiného tvaru než `#rrggbb`,
neznámé `logoPlacement` a `texts` s neznámým slotem obálka odmítne: barva
jde do stylů záhlaví a texty do stránky bez escapování.

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
  DPH, pokladna a logo jsou aktuální v okamžiku tisku. `rows[].unit.label`
  je zkratka v jazyce tisku (§3, Zkratky jednotek).
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
        text-variables.jsonc  # proměnné nabízené pro texty na tiscích (§12.5)
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

### Šablony e-mailu (D37)

Tisk se `sendPurpose` má vedle stránky dvě textové šablony — předmět
a tělo e-mailu, kterým se odesílá:

| Soubor | Co |
|---|---|
| `email-subject.txt.twig` | předmět — jeden řádek |
| `email-body.txt.twig` | tělo — prostý text |

Renderuje je `PrintEmailRenderer` ve **stejném sandboxu** a nad **stejným
`PrintData`** jako stránku, texty přes `t()` z katalogů tisku (klíče
`email.subject.*`, `email.body.*`, všechny jazyky tisku — hlídá
`PrintDeclarationsTest`). Hledají se jako záhlaví a zápatí: v adresáři
šablony tisku, jinak ve sdílených adresářích (`catalogs`). Doklady mají
společné šablony v `@docs.core/_layout/`; tisk je přebije vlastním souborem
ve svém adresáři.

Autoescape Twigu se řídí typem šablony (`autoescape: name`): `*.html.twig`
escapuje HTML, `*.txt.twig` ne — e-mail je prostý text a `&` nebo `<`
v názvu firmy mají dojít tak, jak jsou. **Předmět** jde do hlavičky zprávy,
proto z něj renderer odstraní konce řádků (název ze snapshotu s `\r\n` by
jinak podstrčil další hlavičku); tělo má sjednocené konce řádků a nejvýš
jeden prázdný řádek za sebou.

Obsah v1 (doklady): předmět „<titulek> <číslo> — <vlastní firma>“; tělo
oslovení, co je v příloze, částka k úhradě a splatnost (jen u platby
převodem), pozdrav a název vlastní firmy.

**Uživatelský text přepisuje šablonu (D49):** je-li v obálce neprázdné
`texts.emailSubject`, použije se místo `email-subject.txt.twig`; totéž
`texts.emailBody` pro tělo. Každá část zvlášť — vlastní předmět nechá
výchozí tělo. Text projde stejnou úpravou jako šablona (předmět bez konců
řádků). Platí pro návrh v dialogu Odeslat i pro odeslání, obojí jde přes
`PrintEmailRenderer` (§12.4).

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
| Filtry | `escape`, `e`, `raw`, `default`, `length`, `join`, `upper`, `lower`, `nl2br`, `first`, `last`, `keys`, `merge`, `money`, `qty`, `pct`, `date` |
| Funkce | `t`, `qr_svg`, `block`, `parent`, `include` |
| Testy | `defined`, `null`, `none`, `empty`, `same as`, `even`, `odd`, `iterable` |
| Metody a vlastnosti objektů | žádné |

**`raw` jen pro sloty uživatelských textů:** jediný povolený zápis je
`{{ texts.<slot>|default('')|raw }}`. HTML v `texts.*` vyrobil Markdown
z escapovaného vstupu (§12.4); cokoli jiného vypsané přes `raw` by do
tisku pustilo neescapovaná data dokladu. Hlídá `PrintTemplateRawRuleTest`.

Texty, které píše uživatel, tuhle politiku nedostanou — mají vlastní
prostředí s úzkou politikou `PrintSecurityPolicy::userTexts()` (§12.4).

### Filtry a funkce (`PrintTwigExtension`)

Formát podle jazyka tisku (D30); mezery uvnitř hodnot jsou nezlomitelné.

| | `cs`, `sk` | `de` | `en` |
|---|---|---|---|
| `1210.5\|money` | `1 210,50` | `1.210,50` | `1,210.50` |
| `1210.5\|money('EUR')` | `1 210,50 EUR` | `1.210,50 EUR` | `1,210.50 EUR` |
| `1.5\|qty` (bez zbytečných nul, nejvýš 4 místa) | `1,5` | `1,5` | `1.5` |
| `21\|pct` (nejvýš 2 místa) | `21 %` | `21 %` | `21%` |
| `'2026-10-02'\|date` | `2. 10. 2026` | `02.10.2026` | `2 Oct 2026` |

- Formátuje `ext-intl` (`NumberFormatter`, `IntlDateFormatter`), ale
  **vzory i symboly jsou zapsané v `PrintTwigExtension::FORMATS`** —
  výchozí data ICU se mezi verzemi mění a doklad se s nimi měnit nesmí.
- `money` tiskne **kód měny** za číslem ve všech jazycích, ne symbol.
- Zaokrouhluje PHP (`round()`), ne ICU: to má výchozí bankéřské
  zaokrouhlení (`10.005` → `10,00`) a zápornou nulu tiskne jako `-0,00`.
- Anglický měsíc je třípísmenná zkratka z vlastního seznamu — `MMM`
  v `en_GB` dává „Sept“.
- Datum mimo kalendář (`2026-02-31`) a cokoli jiného než ISO datum vrací
  prázdný řetězec. Filtr `date` **přepisuje vestavěný Twig filtr** stejného
  jména.
- Jazyk bez záznamu ve `FORMATS` je programátorská chyba
  (`LogicException`).

`t('klíč', {param: hodnota})` čte katalog tisku, `qr_svg(data.payment.qr)`
vrací inline SVG (pro null prázdný řetězec).

### Překlady (D8)

`messages.jsonc` = `{ "klíč": { "cs": "…", "en": "…", "sk": "…", "de": "…" } }`.
`PrintCatalogLoader` slévá katalogy z `catalogs` a nakonec katalog šablony
(pozdější klíč vyhrává). `PrintTranslator`: chybějící jazyk → `cs`,
chybějící klíč → vrátí klíč a zaloguje warning. Úplnost katalogů ve všech
jazycích tisku hlídá `PrintDeclarationsTest`.

## 6. REST

`GET /_prints/{printId}/{recordId}?format=pdf|json[&language=cs|en|sk|de]`

Tabulku určuje deklarace tisku. Práva (D21): tisk = čtení záznamu —
`TableAccessGuard::guardTable()` na tabulku deklarace. `format=json` jen
pro administrátora. Na read-only zdroji dat povoleno. `format=html` REST
nenabízí (400) — je to nástroj CLI.

- `pdf` (default): `application/pdf`, `Content-Disposition: inline;
  filename="…"` (z `meta.fileName`). **`Content-Language`** nese jazyk,
  ve kterém tisk vznikl — i když ho klient nevyžádal a zvolil ho partner
  dokladu (D33). Měkká hlášení builderu i runneru (jazyk bez katalogu, §3)
  nese hlavička **`X-Print-Messages`** — procentově kódované JSON pole
  `messages`. Obě hlavičky jsou vystavené v CORS.
- `json`: `{success, data}`, `data` = `PrintData`.

| Kód | HTTP | Kdy |
|---|---|---|
| `PRINT_NOT_FOUND` | 404 | neznámé id tisku |
| `RECORD_NOT_FOUND` | 404 | záznam neexistuje |
| `PRINT_NOT_AVAILABLE` | 409 | stav nebo typ záznamu tisk nedovoluje |
| `PRINT_DATA_MISSING` | 409 | záznamu chybí data pro tisk (snapshot vlastní strany, nebo partnera u dokladu s partnerem) |
| `PRINT_LANGUAGE_NOT_COMPILED` | 409 | zdroj dat nemá kompilovanou konfiguraci v jazyce tisku — čeká na `ds-upgrade` (§3) |
| `BAD_REQUEST` | 400 | neplatný `format` / `language` |
| `FORBIDDEN_ADMIN_ONLY` | 403 | `format=json` bez práv administrátora |
| `RENDER_UNAVAILABLE` | 503 | render služba `unconfigured` / `unreachable` / `timeout` |
| `RENDER_FAILED` | 500 | `engineError` / `invalidInput` |

U obou render chyb je `errorKind` v `details[0].code`.

### Proměnné pro texty (D51)

`GET /_prints/text-variables[?prints=<id,…>][&slot=<slot>]` →
`[{path, label, example}]` — nabídka pro formulář textu na tiscích (§12.5).
S `prints` průnik proměnných těchto tisků, bez nich průnik přes všechny
tisky, které slot podporují (bez `slot` aspoň jeden slot). Neznámý tisk se
přeskočí. `label` je v jazyce requestu, `example` je zápis s doporučeným
filtrem. Práva: `TableAccessGuard::guardTable()` na `core_prints_texts`;
na read-only zdroji dat povoleno. Neplatný `slot` nebo `prints` → 400.

### Odeslání e-mailem (D38)

`GET /_prints/{printId}/{recordId}/send-draft[?language=…]` — návrh
odeslání z `RecordSendService::prepare()` (§9): `to[]` (příjemci s důvodem
`{email, name, source, label, contactId?}`), `cc[]`, `from {email, name,
source}` nebo `null`, `allowedSenders[]`, `subject`, `body`, `language`,
`languages[]` (jazyky tisku s popiskem), `attachments[]` (`kind: print |
record`, `selected`, `merged`), `mergeAttachments`, `recipientPerson`,
`targetLabel`, `canSend` a `messages[]`. Nic nevytváří. Chybějící příjemce
nebo odesílatel **není chybová odpověď** — je to chyba v `messages`
a `canSend: false`; dialog ji ukáže a uživatel adresu doplní.

`POST /_prints/{printId}/{recordId}/send` — tělo `{from, to[], cc[],
subject, body, language, attachmentIds[]}`; co chybí, platí z návrhu.
Odpověď `{sentMessageId, transportState, messages}`; `transportState:
queued` znamená, že okamžitý pokus neprošel a zprávu převzala fronta.

`POST /_sent-messages/{id}/resend` — Odeslat znovu, viz
[mail/sent.md](mail/sent.md).

**Práva (D38):** model oprávnění zná jen administrátora (`docs/auth.md`
D16), takže „smí záznam upravovat“ = projde `guardTable()` na tabulku
deklarace **a** zdroj dat není jen pro čtení — `ReadOnlyPolicy` má pro
`prints` výčet akcí: `run` a `sendDraft` povolené, `send` 403
`DS_READ_ONLY`. S jemnějšími právy se tohle zpřísní na právo záznam
upravovat. Zdroj dat bez modulu `core.mail` odesílat neumí (409
`PRINT_NOT_SENDABLE`).

| Kód | HTTP | Kdy |
|---|---|---|
| `PRINT_NOT_SENDABLE` | 409 | tisk nemá `sendPurpose`, nebo zdroj dat nemá Odeslanou poštu |
| `PRINT_NOT_AVAILABLE` | 409 | stav nebo typ záznamu tisk nedovoluje |
| `NO_RECIPIENT` | 422 | prázdné „Komu“ |
| `NO_SENDER` | 422 | není adresa odesílatele (ani `mail.defaultFrom`) |
| `SENDER_NOT_ALLOWED` | 422 | zvolená nebo na řadě uložená adresa není mezi povolenými |
| `INVALID_EMAIL` | 422 | syntakticky neplatná adresa v „Komu“ / „Kopie“ |
| `INVALID_ATTACHMENT` | 422 | příloha nepatří k odesílanému záznamu |
| `EMPTY_MESSAGE` | 422 | prázdný předmět nebo text |
| `BAD_REQUEST` | 400 | špatný tvar těla, neplatný jazyk |
| `PRINT_NOT_FOUND`, `RECORD_NOT_FOUND`, `PRINT_DATA_MISSING`, `PRINT_LANGUAGE_NOT_COMPILED`, `RENDER_UNAVAILABLE`, `RENDER_FAILED` | jako u tisku | PDF se vyrábí při každém odeslání |

## 7. UI

`ViewerController::detail()` po `renderDetail()` připojí na konec
`detail.actions` akci tisku, když `PrintRegistry::forRecord()` něco vrátí.
Je to generický háček — viewer o tisku neví, takže tisk další tabulky
nevyžaduje zásah do jejího vieweru.

- jeden tisk: `{id: "print", kind: "button", target: {printId, languages}}`
- víc tisků: `{id: "print", kind: "dropdown", items: [{label, value}],
  target: {languages}}`, `value` = id tisku — doklad s vlastním tiskem ve
  stavu V pořádku nabízí tisk dokladu a Kontaci; stornovaný jen tisk
  dokladu, ostatní doklady jen Kontaci

Popisek je v `core.system.viewerDefaults.detailActions.print`.
`target.languages` = `[{id, label}]` jsou jazyky tisku pro přepínač
v náhledu — jeden seznam pro všechny tisky, popisek z
`world.base.documentLanguages` v jazyce rozhraní (bez cfgItemu kód jazyka).

Frontend: `Viewer.svelte::handleDetailAction` otevře
`PrintPreviewDialog.svelte` — PDF stáhne jako Blob (`api/prints.js`, Bearer
auth), ukáže v `<iframe>` z object URL, nad náhledem vypíše `messages`,
**Stáhnout** uloží soubor pod názvem ze serveru. Prohlížeč bez vestavěného
prohlížeče PDF (`navigator.pdfViewerEnabled === false`) dostane jen
Stáhnout. Čtecí `ViewerDetailModal` akce detailu nezobrazuje. Ve formuláři
tisk není (D19).

**Přepínač jazyka (D33).** V patičce dialogu je výběr **Jazyk**. První
načtení jde bez parametru `language` — jazyk volí server (§3) a výběr se
nastaví podle `Content-Language`; do té doby ukazuje „Automaticky“. Změna
načte PDF znovu s `language`; předchozí PDF se zahodí hned (object URL se
uvolní), aby Stáhnout nenabízelo jiný jazyk, než je vybraný. Po chybě
výběr zůstane na jazyce, který selhal, a jde zvolit jiný. Volba se nikam
neukládá — jazyk partnera se mění na osobě (#94 D1). Na úzké obrazovce je
výběr na vlastním řádku nad tlačítky, dostupný i v režimu jen Stáhnout.

**Akce Odeslat (D38).** Hned za akci tisku háček přidá akci `send` — stejný
tvar (`button` s `target.printId`, nebo `dropdown`), jen z tisků se
`sendPurpose`, a jen když zdroj dat má tabulku Odeslané pošty. Popisek
v `core.system.viewerDefaults.detailActions.send`. `Viewer.svelte` otevře
`SendDialog.svelte`:

- načte návrh (`fetchSendDraft`) a nechá ho upravit: **Od** (výběr
  z povolených adres), **Komu** a **Kopie** (štítky s důvodem, odebrat,
  přidat s kontrolou syntaxe), **Jazyk**, **Předmět**, **Text**, **Přílohy**
  (PDF tisku nejde odebrat a má Náhled; přílohy záznamu zaškrtnuté podle
  `send_with_record`, se štítkem „připojí se do PDF“);
- změna jazyka načte návrh znovu — přepíše předmět, text a název PDF
  (ručně upravený text až po potvrzení), příjemci, odesílatel a výběr
  příloh zůstávají;
- hlášení návrhu jsou nahoře; chyba, kterou uživatel v dialogu vyřešil
  (doplněný příjemce, zvolený odesílatel), zmizí;
- po odeslání ukáže výsledek (Odesláno / Ve frontě) a hostitel obnoví
  detail. Na úzké obrazovce je dialog přes celou plochu (`Modal`);
- na serveru se zapnutou pojistkou odchozí pošty má nahoře upozornění
  (`MailSafetyNotice`) a výsledek místo „Odesláno“ ukáže štítek pojistky
  (`safety` v odpovědi `send`) — viz
  [mail/outbound.md](mail/outbound.md) § Pojistka.

**Sekce Odeslaná pošta (D45).** Tentýž háček přidá `detail.sentMessages` —
zprávy ve stavu Odeslaná, které na záznam ukazují (`target_table_id` +
`target_row`), nejnovější první: `{id, createdAt, to[], subject, transport
{state, stateLabel, stateStyle}, attachments[]}`. `ViewerDetail.svelte` je
vykreslí pod obsahem každého tabu (uvnitř rolovací plochy) s náhledy příloh
(`AttachmentGrid`); klik na hlavičku zprávy otevře její formulář přes
generickou akci `open_form` — tam je Odeslat znovu, Archivovat a Smazat.
Funguje pro libovolnou tabulku; archivovaná a smazaná zpráva se u záznamu
neukazuje.

## 8. CLI

```bash
shpd-ds print-run <printId> [<recordId>] [--format=json|pdf|html]
                  [--language=cs|en|sk|de] [--output=<cíl>] [--data=<PrintData.json>]
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

```bash
shpd-ds print-send <printId> <recordId> [--to=<adresa>]… [--cc=<adresa>]…
                   [--from=<adresa>] [--language=cs|en|sk|de] [--dry-run]
```

Odešle záznam e-mailem (§9): vytvoří zprávu v Odeslané poště a zařadí ji do
fronty (odešle ji worker `mail-outbox-run`). `--dry-run` vypíše návrh jako
JSON a nic nevytvoří. Bez `--to` jde zpráva na adresy z kontaktů partnera;
kopii ostrých dat chrání pojistka odchozí pošty na úrovni serveru
(`mail.safety`, [mail/outbound.md](mail/outbound.md) § Pojistka). `--to` je
povinné jen na neprodukčním serveru, kde je pojistka vypnutá.

Viz [cli.md](cli.md).

## 9. Odesílání e-mailem

Odeslat jde **cokoliv, co je deklarované jako tisk ven s účelem** — ne jen
doklady. Rozhodnutí D34–D45; evidence toho, co odešlo, je v
[mail/sent.md](mail/sent.md), fronta a transporty
v [mail/outbound.md](mail/outbound.md).

```
REST / CLI (později dávka)
        │ SendRequest
        ▼
RecordSendService ── prepare() ──▶ SendDraft          (nic nevzniká)
        │ send()
        ├─ RecipientResolver      komu   — kontakty s účelem → e-mail osoby
        ├─ SenderResolver         odkud  — volba → číselná řada → výchozí
        ├─ PrintRunner (pdf)      co     — PDF v jazyce dokumentu
        ├─ PrintEmailRenderer     text   — předmět a tělo ze šablon tisku
        ▼
core_mail_sent_messages + přílohy zprávy ──▶ SentMessageTransport ──▶ fronta
```

**Účely (D34).** cfgItem `base.persons.sendPurposes` — *Faktury a daňové
doklady* (`invoices`), *Upomínky*, *Nabídky a objednávky*, *Přehledy
a výpisy*. Kontakt osoby nese sadu účelů (`send_purposes`), tisk deklaruje
jeden (`sendPurpose`). **Modul přidá vlastní účel** klíčem `sendPurposes`
ve svém `module.jsonc` — cfgItemy se mezi moduly neslučují, účely skládá
`ConfigCompiler` (vzor `journalDimensions`, viz
[modules.md](modules.md) → Pole `sendPurposes`).

**Příjemci (D35).** `RecipientResolver::resolve($personId, $purpose)`:
(1) platné kontakty osoby (stav 10 / 40 / 80, v platnosti) s e-mailem
a s účelem — všechny do „Komu“ v pořadí `order_pos`, stejná adresa jednou;
(2) jinak `base_persons_persons.email`; (3) jinak `NO_RECIPIENT`. Kontakt
bez účelu se nepoužije nikdy. Každá adresa nese důvod (`label`: „Kontakt
Účtárna — Faktury a daňové doklady“, „E-mail osoby“) v jazyce rozhraní;
syntakticky neplatná adresa se přeskočí s varováním. Osobu záznamu určuje
`recipientPerson` deklarace. Adresy se čtou **živě** z osoby a kontaktů, ne
ze snapshotu dokladu (D44) — oprava adresy platí pro další odeslání.

**Odesílatel (D39).** `SenderResolver` — adresa zvolená při odeslání →
odesílatel podle záznamu (doklady: volba „Odesílat z“ na číselné řadě) →
`mail.defaultFrom` → `NO_SENDER`; jen z povolených adres. Podrobně
[mail/outbound.md](mail/outbound.md) → Odesílatel záznamu.

**Služba (D42, D44).** `RecordSendService` (`modules/core/mail/src/Sent/`,
wiring `RecordSendServiceFactory`):

- `prepare(SendRequest): SendDraft` — návrh bez vedlejších účinků: tisk
  běží do `json` (bez render služby), texty ze šablon (§5), přílohy záznamu
  s příznakem `selected` (podle `send_with_record`, nebo podle
  `attachmentIds`) a `merged` (PDF + volba osoby, §3).
- `send(SendRequest): SendResult` — **vždy nová zpráva**: PDF vyrobí znovu
  (`pdf`, zvolený jazyk; připojované PDF přes `appendPdfs`), založí zprávu
  v Odeslané poště, uloží její přílohy (PDF tisku pod `meta.fileName`
  a kopie příloh posílaných zvlášť) a zařadí ji do fronty. **Na záznamu nic
  nevzniká** — zmrazená kopie není (D42); záznam ví, co odešlo, přes
  zprávy, které na něj ukazují.
- Zpráva, její přílohy a řádek fronty jsou **jedna transakce**
  (`NestedTransaction`); při chybě se vrátí a služba uklidí i soubory
  příloh na disku. Okamžitý pokus o odeslání (`trigger: manual`) běží **až
  po commitu** — rollback nesmí přijít po odeslání. `cli` a `batch` jen
  řadí do fronty.
- Prázdné „Komu“, chybějící odesílatel, neplatná adresa nebo cizí příloha
  = `RecordSendException` a nic nevznikne.

Služba nečte HTTP ani UI. Hromadné a automatické odesílání (D11) ji zavolá
beze změny — s `trigger: batch`.

**Jak udělat tisk odesílatelný:** §10, krok 6.

## 10. Jak přidat tisk

Příklad: karta majetku (`economy.assets`).

1. **Builder** `modules/economy/assets/src/Prints/AssetCardPrintBuilder.php`
   implementuje `PrintBuilder`: z `PrintRequest` načte kartu a události
   a vrátí `PrintBuildResult` (`data`, titulek, název souboru, hlášení);
   `version()` vrací verzi kontraktu `data`. Popisky číselníků čte
   z `$request->config` — je v jazyce tisku; nový cfgItem, který builder
   čte, doplň do `PrintConfigLanguagesTest` a do tabulky v §3, jinak jeho
   překlady nikdo nehlídá. Kontrakt `data` popiš
   v dokumentaci modulu. Vzor tisku mimo doklady ven:
   `DocJournalPrintBuilder` (Kontace). Tisk určený partnerovi
   (`audience: external`) nad jinou tabulkou než doklady implementuje
   i `PrintPartyProvider` — jinak se tiskne v jazyce vlastní země (§3).
2. **Šablona** `modules/economy/assets/prints/card/page.html.twig`
   a `messages.jsonc` (všechny jazyky tisku). Vlastní `header.html.twig` /
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
6. **Odesílatelný tisk** (jen `audience: external`): do deklarace
   `sendPurpose` (existující účel, nebo vlastní přes `sendPurposes`
   v `module.jsonc` + `ds-upgrade`) a `recipientPerson` (sloupec s osobou).
   E-mailové šablony `email-subject.txt.twig` a `email-body.txt.twig`
   s klíči `email.*` v katalogu — vlastní, nebo sdílené přes `catalogs`
   (§5). Tabulka mimo doklady, která má odesílat z jiné adresy než
   výchozí, registruje `RecordSenderProvider` (`recordSenderProviders`
   v `module.jsonc`). Akce Odeslat i sekce Odeslaná pošta se v detailu
   objeví samy; `PrintDeclarationsTest` ohlídá účel i šablony.
7. **Uživatelské texty** (§12.6): `textSlots` v deklaraci a sloty
   v šabloně; `textVariables` s popisky `var.<cesta>` v katalogu.

Vývoj šablony bez opakovaného sahání do databáze:

```bash
shpd-ds print-run <id> <záznam> > data.json           # jednou: data builderu
shpd-ds print-run <id> --data=data.json \
    --format=html --output=/tmp/tisk                   # po každé úpravě šablony
shpd-ds print-run <id> --data=data.json \
    --format=pdf --output=/tmp/tisk.pdf                # kontrola stránkování v PDF
```

Místo vlastního `data.json` poslouží i fixture z `tests/Fixtures/Prints/`;
úpravou JSON (víc řádků, `meta.watermark`, `customer: null`,
`branding.accentColor`, hotové HTML v `texts.footer`) vyzkoušíš stavy,
pro které v databázi doklad ani text není.

### Jak přidat jazyk tisku

Příklad: polština (`pl`).

1. **Jazyk dokumentů:** záznam v
   `modules/world/base/config/documentLanguages.jsonc` — tím se dá nastavit
   na osobě a `ds-upgrade` pro něj začne kompilovat konfiguraci. Do té
   doby, než bude i jazykem tisku, se tiskne anglicky s hlášením
   `language.unavailable`.
2. **Formát:** řádek v `PrintTwigExtension::FORMATS` (locale, oddělovače,
   procenta, vzor data) a případ v `PrintTwigExtensionTest`.
3. **Katalogy:** `pl` ke každému klíči všech `messages.jsonc`
   (`modules/*/*/prints/`).
4. **Konfigurace:** varianty `:pl` u všeho z tabulky v §3 (Popisky
   konfigurace), včetně `core.units.printShortcuts`.
5. **`PrintLanguageResolver::LANGUAGES`** — až teď; testy úplnosti
   (`PrintDeclarationsTest`, `PrintConfigLanguagesTest`,
   `PrintShortcutsTest`) vyjmenují, co chybí.
6. **Formulace s právní vahou** (titulky, věta o nedaňovém dokladu, datum
   plnění, poznámky DPH) vypiš k revizi — vzor je sekce „Formulace
   k revizi“ v `tasks/prints-languages.md` (D32).
7. Popisek jazyka pro CLI (`PrintRunCommand`, `HelpCommand`), nápověda
   v `help/`, a po nasazení **`ds-upgrade` na všech zdrojích dat**.

## 11. Testy

- Unit `tests/Unit/Core/I18n/DocumentLanguageResolverTest.php` — tabulka
  odvození jazyka nad skutečnými číselníky `world.base`;
  `tests/Unit/Core/Prints/` — deklarace, registr, runner (včetně
  `renderData()` a volby jazyka), továrna runneru (jazyk bez kompilátu),
  obálka `PrintData`, katalogy, pravidlo vodoznaku, Twig filtry (formáty
  všech jazyků tisku) a sandbox; `PrintConfigLanguagesTest` — popisky
  konfigurace čtené tiskem ve všech jazycích tisku, jazyky tisku ⊆ jazyky
  dokumentů a jejich kompilace; `tests/Unit/Module/Core/Units/PrintShortcutsTest.php`
  — tiskové zkratky jednotek proti seedu;
  `tests/Unit/Module/Docs/Core/Prints/` — bloky, titulky,
  strana tisku (`DocPrintPartyTest`), QR platba, šablony dokladů a Kontace do HTML;
  `tests/Unit/Command/DataSource/PrintRunCommandTest.php` — `--format=html`
  a `--data` bez databáze; `tests/Unit/Api/Controller/PrintsApiTest.php`
  — routa, controller (`Content-Language`, chybové kódy), akce v detailu
  včetně `target.languages`, `GET /_prints/text-variables`.
- Vzhled a texty (§12): `PrintDataTest` (`branding`, `texts`, starší JSON),
  `PrintRunnerTest` (nastavení → `branding`, zdroj textů, `textError`),
  `tests/Unit/Core/Prints/Texts/` — sloty proti cfgItemu, sandbox
  uživatelských textů (povolené / zakázané prvky, kontrola už při
  kompilaci), `MarkdownEscaperTest` (hodnoty s `*`, `_`, `#`, čísla a data
  beze změny), `PrintTextMarkdownTest` (HTML, obrázek, nebezpečný odkaz),
  `PrintTextRendererTest`, `PrintTextVariablesTest`;
  `PrintTemplateRawRuleTest` (`|raw` jen na `texts.*`);
  `DocPrintTemplatesTest` (akcent a logo v záhlaví, sloty na svých
  místech); `PrintDeclarationsTest` (sloty tisků, popisky proměnných,
  každá nabízená ukázka projde nad fixture doklady);
  `tests/Unit/Module/Core/Prints/` — validace textu, nabídky formuláře,
  agenda, výběr textů; `HexColorTest` a `SettingsControllerTest` (typ pole
  `color`).
- Odesílání: `tests/Unit/Module/Base/Persons/Send/` (účely, resolver
  příjemců), `tests/Unit/Core/Mail/` (`SenderResolverTest`,
  `AllowedSendersTest`), `PrintEmailRendererTest` (všechny jazyky, předmět
  bez konců řádků, žádné HTML escapování),
  `tests/Unit/Module/Core/Mail/Sent/` (`RecordSendServiceTest` s falešnými
  závislostmi — návrh bez vedlejších účinků, transakce, úklid po chybě;
  transport, posluchač fronty, pevný obsah zprávy),
  `PrintSendApiTest` (routy, read-only, návrh, odeslání, háček detailu),
  `PrintSendCommandTest`.
- Integrační `tests/Integration/Prints/` — kontrakt nad fixture doklady
  v dev DS (`PrintFixtureDocuments`; pokladní doklady a prodejky potřebují
  pokladnu a její řady), tisky v `en`, `sk` a `de` (titulky, popisky
  číselníků, zkratka jednotky), odběratel ze Slovenska bez parametru
  jazyka, CLI. Zdroj dat musí mít po `ds-upgrade` kompilát pro všechny
  jazyky tisku. `RecordSendTest` odesílá fixture fakturu — příjemci
  z kontaktů a osoby, přílohy zvlášť / spojené, zpráva a řádek fronty;
  běží celý v transakci s rollbackem a s `trigger: cli`, takže nic
  neodchází; vlastní předmět a tělo z textů na tiscích přepíšou návrh.
  `PrintTextsTest` — výběr textů nad skutečnou databází (tisk, typ, řada,
  jazyk, stav, platnost ke dni tisku) a text ve slotu stránky; běží
  v transakci a texty, které na zdroji dat jsou, v ní na dobu testu
  vypne. Ostatní testy tisků transakci nemají: rozbitý text ve stavu
  V pořádku na testovacím zdroji jim do `messages` přidá `textError`.
  `PrintPdfTest` jde přes celou cestu do PDF
  (včetně vodoznaku na každé straně vícestránkového storna) a vedle
  `SHIPARD_INTEGRATION_DS_PATH` potřebuje
  `SHIPARD_INTEGRATION_GOTENBERG_URL`.

## 12. Vzhled a texty na tiscích

Fáze 3 (#90 D46–D52, `tasks/prints-phase3.md`). Nastavení vzhledu i agendu
textů drží modul **`core.prints`** (`modules/core/prints/`, v `install.base`)
— obecný, bez závislosti na dokladech. Výběr a vykreslení textů při tisku
je v jádru (`src/Core/Prints/Texts/`).

### 12.1 Vzhled (D46)

Stránka nastavení **Tisky** (`printsAppearance`, sekce Aplikace):

| Klíč | Pole | Význam | Bez hodnoty |
|---|---|---|---|
| `prints.accentColor` | `color` | akcentová barva hlavičky `#rrggbb` | `#c8c8c8` (`PrintData::DEFAULT_ACCENT_COLOR`) |
| `prints.logoPlacement` | `select` | strana hlavičky s logem `left` / `right` | `left` |

`PrintRunner` hodnoty čte ze `SettingsStore` a plní jimi `branding` obálky;
platí pro všechny tisky. **Ověřuje je i při čtení** — `ds-setting set`
hodnotu nekontroluje, neplatná = výchozí vzhled.

Vykresluje je **jen záhlaví** (`_layout/header.html.twig`): akcent barví
svislý pruh u titulku, linku pod záhlavím a podklad loga (je vidět pod
průhledným logem); text zůstává černý. Logo vpravo prohodí strany — titulek
s číslem je vždy na opačné straně než logo. Tělo dokladu se nebarví
a `--accent` do stránky nejde. Barva se do stylů vkládá jen jako ověřené
`#rrggbb` z obálky. Kontace sdílí záhlaví dokladů, takže vzhled platí
i pro ni.

Mimo: styly standardní / moderní, kulaté rohy, podpis (#93), kódy položek,
identifikátory osob.

### 12.2 Texty: data, sloty, deklarace (D47, D48)

Tabulka `core_prints_texts`
([popis](../modules/core/prints/tables/core_prints_texts.md)): text, slot,
cílení (tisky, typy dokladů, číselné řady, jazyk), platnost od–do, pořadí,
stav. Agenda **Texty na tiscích** je v Nastavení → Aplikace; tabulka je
v `keepOnReset`.

Sloty jsou pevná sada — výčet `PrintTextSlot`, názvy a popisy v cfgItemu
`core.prints.textSlots`:

| Slot | Kde | Druh |
|---|---|---|
| `header` | začátek těla dokumentu — první prvek stránky, před titulkem a stranami | HTML |
| `beforeRows` | před tabulkou řádků | HTML |
| `afterRows` | za tabulkou řádků, před rekapitulací a součty | HTML |
| `footer` | konec dokumentu — za poznámkami, před podpisy | HTML |
| `emailSubject` | předmět e-mailu — **přepisuje** výchozí šablonu (D49) | text |
| `emailBody` | tělo e-mailu — **přepisuje** výchozí šablonu (D49) | text |

Tisk v deklaraci (`textSlots`) uvede, které sloty jeho šablona vykreslí.
Tisky dokladů mají všech šest, Kontace žádný.

Formulář a Document textu nemají cesty modulů, registr tisků si
nepostaví. `ConfigCompiler` proto z deklarací `prints` aktivních modulů
skládá cfgItem **`core.prints.declarations`** (název, tabulka, filtr,
`textSlots`) — odtud je nabídka tisků ve formuláři a validace při uložení.
Nový tisk se slotem se v nabídce objeví po `ds-upgrade`.

`PrintTextDocument` při uložení ověří: povinná pole, `valid_from ≤
valid_to`, tisky existují a slot podporují, typ dokladu a řada jsou
u vybraných tisků možné — a **text zkompiluje v sandboxu** (§12.4).
Zakázaný prvek nebo syntaktická chyba = 422 s hláškou u pole textu.

### 12.3 Výběr textů (D47, D52)

`PrintTextResolver` (modul) implementuje `PrintTextProvider` (jádro) —
jádro zná jen rozhraní a `PrintRunnerFactory` resolver zapojí jménem
třídy; zdroj dat bez tabulky textů tiskne bez nich. Text platí, když:

1. je ve stavu **V pořádku** a jeho slot je v `textSlots` tisku;
2. `prints` je prázdné, nebo obsahuje id tisku;
3. `doc_types` / `number_series` — jsou-li vyplněné — odpovídají záznamu.
   Co je u záznamu typ a řada, ví jen mapa `PrintTextTargeting` (tabulka
   tisku → sloupce; dnes `docs_core_heads`). U tisku nad jinou tabulkou
   text s tímto omezením **neplatí** (ne „platí vždy“);
4. `language` je prázdný, nebo jazyk tisku;
5. den tisku je uvnitř platnosti, oba kraje včetně. **Rozhoduje den tisku
   nebo odeslání, ne datum dokladu** — „příští týden máme dovolenou“ se
   tiskne ten týden na všechny doklady.

Do slotu jdou **všechny** platné texty v pořadí `order_pos`, `id`.

### 12.4 Zpracování textu (D49, D50)

`PrintTextRenderer` vykreslí každý text zvlášť a výsledek uloží do `texts`
obálky:

```
slot stránky:  text ─► Twig (sandbox userTexts, hodnoty escapované pro Markdown)
                    ─► Markdown ─► HTML ─► <div class="print-text">…</div>
e-mailový slot: text ─► Twig (stejný sandbox, bez escapování) ─► prostý text
```

**Sandbox** — `PrintTextCompiler` má vlastní Twig prostředí: žádný loader
souborů (k šablonám modulů se text nedostane), bez cache, striktní
proměnné a politika `PrintSecurityPolicy::userTexts()`:

| | Povoleno |
|---|---|
| Tagy | `if` |
| Filtry | `money`, `qty`, `pct`, `date`, `default`, `upper`, `lower` (+ `escape`, který vkládá autoescape) |
| Funkce | žádné — ani `t()`, `include()`; operátor rozsahu `..` je funkce `range`, tedy také ne |
| Testy | `defined`, `empty`, `null`, `none` |
| Metody a vlastnosti objektů | žádné |

Text vidí `data`, `meta` a `language` z `PrintData` — ne `branding`,
`record` ani `messages`. Twig kontroluje politiku až při vykreslení;
`compile()` si kontrolu vynucuje, aby zakázaný prvek odmítla už validace
formuláře. Název šablony nese režim (Markdown / prostý text): Twig sdílí
třídu šablony v rámci procesu podle názvu.

**Escapování pro Markdown** (`MarkdownEscaper`): text píše uživatel
a Markdown v něm je záměr; hodnota z dokladu Markdown být nesmí. Escapuje
se zpětným lomítkem veškerá ASCII interpunkce — CommonMark to dovoluje
u každého znaku a vypíše ho beze změny, takže `1.210,50` i `2. 10. 2026`
vyjdou stejně a nemůžou začít seznam. Konce řádků hodnoty jsou tvrdé
zalomení.

**Markdown** (`PrintTextMarkdown`, `league/commonmark`): CommonMark,
přeškrtnutí a automatické odkazy; tabulky ani seznamy úkolů ne.

- HTML ve vstupu se escapuje (`html_input: escape`).
- Odkaz se tiskne jako text s adresou v závorce, bez `<a>`; nebezpečná
  adresa (`javascript:`, `data:`) se nevypíše.
- Obrázek se nahradí popiskem — render služba nesmí na síť a `data:`
  adresu by volba `allow_unsafe_links` propustila.

Proto smí layout vypsat `texts.<slot>` přes `|raw` (§5 Sandbox) — a jen ten.

**Chyba v textu tisk nerozbije.** Neznámá proměnná nebo prvek mimo
politiku: text se vynechá, do `messages` jde varování `textError`
(text z katalogu, klíč `message.textError`) a událost do logu. Náhled
tisku varování ukáže nad dokladem.

**E-mail:** `emailSubject` a `emailBody` jsou prostý text, Markdownem
neprocházejí a nic se v nich neescapuje. Víc textů ve slotu se spojí —
předmět mezerou, tělo prázdným řádkem. `PrintEmailRenderer` je použije
místo výchozích šablon (§5 Šablony e-mailu).

Layout dokladů (`doc-base.html.twig`) kreslí čtyři sloty stránky **mimo
bloky** — stránková šablona, která blok přepíše, o ně nepřijde. Styl
`.print-text`: běžná velikost textu, odstavce, seznamy, tučné a kurzíva;
nadpisy Markdownu jsou jen tučný řádek; žádná barva.

### 12.5 Proměnné (D51)

Kurátorský seznam, který formulář textu nabídne — text jinak vidí celé
`data` a `meta`. `PrintTextVariables` ho skládá z deklarace:

```jsonc
"textVariables": [
    "@docs.core/_layout",                                  // sdílená sada
    { "path": "data.cashDesk.name" },                      // vlastní proměnná
    { "path": "data.dates.due", "filter": "date" }
]
```

Sdílená sada je soubor `text-variables.jsonc` v adresáři tisku. `filter`
je doporučený zápis do ukázky: formát podle jazyka (`date`,
`money(data.payment.currency)`), nebo `default('')` u údaje, jehož rodič
na dokladu být nemusí (`data.customer.name` na pokladním dokladu bez
partnera — bez něj by text skončil chybou). Popisky jsou v katalozích
tisku pod klíčem `var.<cesta>` ve všech jazycích tisku.

`GET /_prints/text-variables` (§6) vrací průnik proměnných dotčených
tisků. Formulář má panel **Proměnné** (`PrintTextVariables.svelte`) —
klik vloží ukázku na místo kurzoru.

`PrintDeclarationsTest` hlídá, že každá proměnná má popisek a že **každá
ukázka projde sandboxem nad každým fixture dokladem svého tisku**.

### 12.6 Jak přidat slot nebo podporu textů do tisku

**Nový tisk s texty:**

1. V deklaraci `textSlots` — jen sloty, které šablona opravdu vykreslí;
   e-mailové jen u tisku se `sendPurpose`.
2. Šablona dědící `doc-base` nedělá nic. Vlastní layout vypíše
   `{{ texts.<slot>|default('')|raw }}` na místě slotu (mimo přepisované
   bloky) a přidá styl `.print-text`.
3. `textVariables`: odkaz na sdílenou sadu, nebo vlastní proměnné
   s popisky `var.<cesta>` v `messages.jsonc`. Fixture tisku
   v `tests/Fixtures/Prints/` — test nad ní ověří ukázky.
4. Tisk nad tabulkou s typem a řadou, na které má jít text cílit: řádek
   v `PrintTextTargeting::TABLES`.
5. `ds-upgrade` — nabídka tisků ve formuláři textu je z kompilované
   konfigurace.

**Nový slot:** případ ve výčtu `PrintTextSlot` (+ `isEmail()`), záznam
v `core.prints.textSlots` (názvy, popisy, `kind`), vykreslení v layoutu
a zápis do `textSlots` tisků, které ho podporují. E-mailový slot navíc
potřebuje místo v `PrintEmailRenderer`. `PrintTextSlotTest` ohlídá shodu
výčtu s cfgItemem, `PrintTemplateRawRuleTest` počet slotů v layoutu.
