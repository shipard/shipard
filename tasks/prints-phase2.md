# Tisky — Fáze 2: nástroje pro šablony, pokladní doklad, prodejka, Kontace, storno

**Stav:** hotovo — 2026-10-02 (6 commitů + oprava záhlaví; unit a integrační testy na `4l3j-` včetně PDF přes render službu, tisky ověřené nad skutečnými doklady `4l3j-`); zbývá ruční proklik nabídky Tisk v prohlížeči; odchylky od zadání na konci

> PRD pro Claude Code (6 commitů). Design: issue #90, komentář
> „Rozhodnutí: fáze 2 (D22–D28)“; základ D1–D21 a fáze 0 + 1 v
> `tasks/prints-phase1.md`. Související: #92 (opravný daňový doklad —
> titulek vratky), #93 („Vystavil“ — jména u podpisů), #94 (jazyk osoby —
> jazyky `sk`/`de` jsou samostatný krok po něm).

## Kontext

Fáze 1 postavila doménu `print` a dva tisky ven nad `docs_core_heads`
(faktura vydaná, zálohová faktura). Fáze 2 ověřuje obecnost (D2) na dalších
typech dokladů a na prvním interním tisku, přidává vodoznak storna a zrychluje
vývoj šablon:

- **Nástroje (D28):** HTML výstup do adresáře a render z hotového
  `PrintData` JSON bez databáze a builderu.
- **Vodoznak (D23):** stornované doklady jdou vytisknout s „STORNO“ přes
  každou stranu.
- **Strany bez partnera (D24):** pokladní doklady a prodejky mají partnera
  nepovinného.
- **Pokladní doklad (D25), prodejka (D26):** tisky ven.
- **Kontace (D27):** interní tisk účetních zápisů nad libovolným dokladem.

## Před implementací přečti

- Issue #90 — D1–D28 (tělo a komentáře)
- `docs/prints.md` celé — kontrakt, deklarace, šablony, sandbox, CLI
- `tasks/prints-phase1.md` — sekce „Odchylky od zadání“ na konci
- `src/Core/Prints/` — `PrintRunner`, `PrintDefinition`, `PrintData`,
  `PrintRenderer`, `PrintDocument`, `PrintTemplatePaths`
- `src/Command/DataSource/PrintRunCommand.php`
- `modules/docs/core/src/Prints/` — `DocPrintBuilder`, `DocPrintContext`
  (kontrola snapshotů), `TitleVariantResolver`, `Blocks/*`
- `modules/docs/core/prints/` — `_layout`, `_partials`
- `modules/docs/cashDocs/README.md`, `modules/docs/cashRegister/README.md`,
  `modules/docs/core/src/CashDeskDocumentBase.php`,
  `DocDocument::resolveTradeDir()` a `assignSnapshots()`
- `modules/docs/core/config/docStates.jsonc` (30 = Storno, jen z 40 a 80)
- `modules/docs/core/src/DocsHeadsViewer.php::buildAccountingTab()` a
  `accountingJournalTable()` — **vzor sloupců Kontace** (včetně dimenzí
  `JournalDimensionSet`)
- `modules/economy/accounting/tables/economy_accounting_journal.jsonc`,
  `economy_accounting_accounts.jsonc`, config `accountingStates`
- `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc`
- `help/faktury-vydane/tisk-faktury.md`, `help/pokladna/*.md`

## Scope

**Uvnitř:**

- CLI `print-run --format=html` a `--data=<soubor>` (D28).
- Deklarace `watermarks`, `meta.watermark` v obálce, vykreslení v layoutu
  dokladů (D23); faktura a zálohová faktura `docStates: [40, 30]`.
- Volitelný partnerský snapshot (D24), `document.tradeDir`.
- Tisk `docs.cashDocs.cash` (D25) a `docs.cashRegister.receipt` (D26).
- Tisk `economy.accounting.docJournal` „Kontace“ (D27).
- Testy, `docs/prints.md`, `docs/cli.md`, nápověda.

**Mimo:**

- Jazyky `sk`, `de` (po #94).
- Opravný daňový doklad a jeho titulky (#92); vratka má zatím jednu
  variantu titulku.
- Jména u podpisů, „Vystavil“, „Zaúčtoval“ (#93).
- EET údaje na pokladních dokladech a prodejkách.
- Účtenka na POS tiskárnu.
- Karta majetku (řeší issue modulu majetku).
- REST `format=html`.
- E-mail (fáze 4).

## 1. Nástroje pro šablony (D28)

```
shpd-ds print-run <printId> [<recordId>] [--format=json|pdf|html]
                  [--language=cs] [--output=<cíl>] [--data=<PrintData.json>]
```

- **`--format=html --output=<adresář>`** zapíše přesně to, co jde do render
  služby: `index.html` (stránka), `header.html`, `footer.html` a všechny
  assety (CSS, logo, …) pod jejich publikovanými jmény. Adresář se vytvoří;
  existující soubory se stejným jménem se přepíší, jiné soubory se nemažou.
  Bez `--output` chyba. Výsledek se otevírá přímo v prohlížeči
  (`index.html` odkazuje assety relativně). Záhlaví a zápatí jsou samostatné
  soubory — v prohlížeči se nezobrazí v okrajích, to je v pořádku.
- **`--data=<soubor>`** načte `PrintData` JSON (výstup `--format=json` nebo
  fixture z `tests/Fixtures/Prints/`) a přeskočí databázi, kontrolu
  dostupnosti i builder — rovnou renderuje (`html` nebo `pdf`; `json`
  s `--data` = chyba, nedává smysl). `recordId` se nezadává. `printId`
  argumentu musí souhlasit s `printId` v JSON (jinak chyba — chrání před
  renderem dat jiného tisku cizí šablonou). Jazyk z JSON, `--language`
  ho přebije (překlady šablony; popisky v `data` zůstávají, jak jsou).
- `PrintData::fromArray()` — validace povinných klíčů obálky
  (`printId`, `version`, `language`, `record`, `meta`, `data`);
  `version` vyšší než podporovaná builderem → chyba.
- `PrintRunner`: nová metoda pro render z hotového `PrintData`
  (sdílí cestu s `run()` od kroku renderu). REST se nemění.

## 2. Vodoznak (D23)

Deklarace:

```jsonc
"docStates": [40, 30],
"watermarks": { "30": "watermark.cancelled" }
```

- `PrintDefinition::fromArray()` validuje: klíče = stavy z `docStates`,
  hodnoty neprázdné řetězce.
- `PrintRunner` po builderu: je-li pro `docState` záznamu klíč, vyplní
  `meta.watermark` přeloženým textem (katalogy tisku), jinak `null`.
  Obálka: nové pole `meta.watermark` (doplnění D12) — `PrintData`,
  `toArray()`, `fromArray()`, `docs/prints.md`. `VERSION` builderů se
  nemění (přidané pole).
- Vykreslení: partial `@docs.core/_partials/watermark.html.twig`
  zahrnutý v `doc-base` — prvek s `position: fixed` přes střed stránky,
  šikmo, světle šedý, `pointer-events: none`, pod obsahem. Ověřit, že ho
  Chromium vytiskne **na každé straně** vícestránkového dokladu.
  **Každý layout tisku, jehož deklarace má `watermarks`, musí
  `meta.watermark` vykreslit** — pravidlo do `docs/prints.md`, hlídá test
  (viz Testy).
- Katalog `@docs.core/_layout/messages.jsonc`: `watermark.cancelled`
  = „STORNO“ / „CANCELLED“.
- Faktura vydaná, zálohová faktura, pokladní doklad, prodejka:
  `docStates: [40, 30]`, `watermarks: {"30": "watermark.cancelled"}`.
  Storno vzniká jen ze stavů se snapshoty (40, 80), builder nic nového
  nepotřebuje.
- Název souboru stornovaného dokladu se nemění.

## 3. Strany a směr (D24)

- `DocPrintContext::load()`: snapshot **vlastní strany** (podle
  `resolveTradeDir`: výstup → `supplier_snapshot`, vstup →
  `customer_snapshot`) je povinný; **partnerský** jen když hlavička má
  `partner`. Chybí-li povinný → `PrintBuildException` (REST
  `PRINT_DATA_MISSING`, beze změny). Typ dokladu bez směru (`cmnbkp`)
  → oba nepovinné.
- `DocPartiesBlock`: chybějící strana → `null` (`data.supplier` nebo
  `data.customer`). Partial `parties.html.twig` vynechá prázdnou stranu,
  místo zůstane prázdné (žádný zástupný text).
- `DocDocumentBlock`: nové `document.tradeDir` (1 výstup, 2 vstup, `null`
  bez směru) přes `DocDocument::resolveTradeDir()`.
- `DocPaymentBlock`: QR a bankovní účet beze změny (jen převodem);
  u ostatních způsobů jen `payment.method`.
- Kontrakt `data` v `docs/prints.md`: `supplier` / `customer` mohou být
  `null`, `document.tradeDir`.

## 4. Titulky

`TitleVariantResolver::resolve()` dostane kontext místo dvojice
`(docType, vatPayer)`: typ, plátce, směr, zda je neprázdná rekapitulace
DPH, znaménko celkové částky. Varianty (klíč katalogu `title.<varianta>`
a `fileName.<varianta>`):

| Typ | Podmínka | Varianta | Titulek `cs` |
|---|---|---|---|
| `invno` | plátce | `invoiceVatPayer` | (beze změny) |
| `invno` | neplátce | `invoiceNonVatPayer` | (beze změny) |
| `invpo` | — | `proforma` | (beze změny) |
| `cash` | příjem, plátce, rekapitulace DPH neprázdná | `cashInTaxDocument` | Příjmový pokladní doklad – daňový doklad |
| `cash` | příjem, jinak | `cashIn` | Příjmový pokladní doklad |
| `cash` | výdej | `cashOut` | Výdajový pokladní doklad |
| `cashreg` | celková částka < 0 | `cashRegisterRefund` | Prodejka – vratka |
| `cashreg` | plátce | `cashRegisterVatPayer` | Prodejka – daňový doklad |
| `cashreg` | neplátce | `cashRegisterNonVatPayer` | Prodejka |

Plátce = hlavička má `vat_registration` (D16). Vratka má přednost před
plátcovstvím; správný titulek vratky plátce doladí #92. Neznámý typ dál
`PrintBuildException`. Rezervované `corrective*` beze změny.

## 5. Pokladní doklad (D25)

- Deklarace `docs.cashDocs.cash` v `modules/docs/cashDocs/config/prints.jsonc`:
  `table: docs_core_heads`, `filter: {doc_type: ["cash"]}`,
  `docStates: [40, 30]`, `watermarks`, `audience: external`, builder
  `CashDocPrintBuilder` (v `docs.core`, dědí `DocPrintBuilder`),
  `catalogs: ["@docs.core/_layout"]`, šablona
  `@docs.cashDocs/cash`, `order: 10`.
- Data navíc: blok `cashDesk` = `{id, code, name}` z
  `economy_codebooks_cash_desks` (aktuální, D14). `payment` bez účtu a QR
  (hotově / kartou). Data dokladu podle formuláře (vystavení, DUZP,
  den přijetí platby na příjmu, je-li na hlavičce).
- Šablona `modules/docs/cashDocs/prints/cash/page.html.twig` (dědí
  `doc-base`):
  - strany s popisky „Dodavatel / přijal“ (supplier) a „Odběratel /
    vydal“ (customer) — v obou směrech platí totéž, mění se jen, kdo je
    kdo (to už řeší snapshoty); popisky přes katalog šablony
    (`label.supplier`, `label.customer` přebijí layout),
  - pokladna v bloku dat,
  - podpisová pole na konci: příjem „Podpis“ + „Podpis pokladníka“,
    výdej „Podpis příjemce“ + „Podpis pokladníka“ — prázdné linky,
    bez jmen (#93),
  - bez QR a bankovního účtu.

## 6. Prodejka (D26)

- Deklarace `docs.cashRegister.receipt` v
  `modules/docs/cashRegister/config/prints.jsonc`: `filter:
  {doc_type: ["cashreg"]}`, `docStates: [40, 30]`, `watermarks`,
  `audience: external`, builder `CashRegisterPrintBuilder` (docs.core,
  dědí `DocPrintBuilder`), šablona `@docs.cashRegister/receipt`,
  formát A4.
- Data: jako faktura + `cashDesk`. Úhrada převodem (`payment_method = 1`)
  → platební blok s účtem a QR jako u faktury; hotově / kartou → jen
  způsob úhrady.
- Bez partnera → `customer: null`, místo odběratele prázdné (D24).
- Šablona `modules/docs/cashRegister/prints/receipt/page.html.twig`
  (dědí `doc-base`): pokladna, žádná splatnost u hotovosti a karty,
  vratka bez zvláštního vzhledu kromě titulku (záporné částky jsou
  v datech).
- Účtenka na POS mimo (D3 drží cestu otevřenou — jiný renderer nad
  stejným `PrintData`).

## 7. Kontace (D27)

- Deklarace `economy.accounting.docJournal` v
  `modules/economy/accounting/config/prints.jsonc`: `name:cs` „Kontace“,
  `name:en` „Accounting entries“, `table: docs_core_heads`, **bez
  `filter`** (všechny typy), `docStates: [40]`, `audience: internal`,
  builder `Shipard\Module\Economy\Accounting\Prints\DocJournalPrintBuilder`,
  šablona `@economy.accounting/docJournal`, `catalogs:
  ["@docs.core/_layout"]`, `order: 900` (za tisky ven — v detailu faktury
  vznikne první dropdown se dvěma tisky).
- Práva podle D21 (čtení tabulky deklarace).
- **Data:**
  ```jsonc
  "data": {
      "document": { /* DocDocumentBlock bez titleVariant */
                    "type": "invno", "typeName": "Faktura vydaná",
                    "title": "Kontace", "number": "…", "currency": "…", … },
      "dates": { "issue": "…", "accounting": "…", "duzp": "…" },
      "accountingUnit": { /* vlastní firma — snapshot, je-li, jinak
                             aktuální data (interní tisk, D2) */ },
      "partner": { /* partnerský snapshot, je-li; jinak null */ },
      "accounting": { "state": 1, "stateLabel": "Zaúčtováno" },
      "dimensions": [ { "id": "centre", "label": "Středisko" } ],
      "journal": [
          { "accountNumber": "311000", "accountName": "Odběratelé",
            "text": "…", "debit": 1210, "credit": null,
            "debitCur": null, "creditCur": null,
            "dimensions": { "centre": "…" }, "isError": false }
      ],
      "totals": { "debit": 1210, "credit": 1210,
                  "debitCur": null, "creditCur": null }
  }
  ```
  - Řádky deníku v pořadí `id` (jako tab Účtování), **stejné sloupce
    jako tab Účtování v detailu** včetně dimenzí z `JournalDimensionSet`.
  - Název účtu z `economy_accounting_accounts` (aktuální, D14).
  - `*Cur` jen u dokladu v cizí měně, jinak `null`.
  - Titulek `title.docJournal` („Kontace“); `meta.title` = „Kontace
    <název typu> <číslo>“, název souboru `kontace-<číslo>.pdf`.
  - Bez řádků deníku → `journal: []` a varování v `messages`
    (`code: noJournal`). Stav účtování „chyba“ → varování
    (`code: accountingError`) s textem stavu; tisk se vyrobí.
- Builder nevyužívá `TitleVariantResolver` (Kontace je pro všechny
  typy); sdílené bloky dokladu skládá sám.
- **Šablona** `modules/economy/accounting/prints/docJournal/page.html.twig`
  (dědí `@docs.core/_layout/doc-base`, bloky `parties`, `payment`, `rows`,
  `vatRecap`, `totals` přepíše):
  - hlavička: titulek, typ a číslo dokladu, data (vystavení, účetní
    datum, DUZP), účetní jednotka a partner,
  - tabulka zápisů: účet, název účtu, text, dimenze, MD, Dal (+ v cizí
    měně, je-li), chybové řádky zvýrazněné,
  - součty MD / Dal,
  - pole „Zaúčtoval“ prázdné (#93).

## Testy

- **Unit:**
  - `PrintDefinition` — `watermarks` (platné, stav mimo `docStates`,
    prázdná hodnota).
  - `PrintData::fromArray()` — roundtrip s `toArray()`, chybějící klíč,
    vyšší `version`.
  - `PrintRunner` — `meta.watermark` pro stav s klíčem a bez něj; render
    z hotového `PrintData`.
  - `TitleVariantResolver` — všechny řádky tabulky z §4.
  - `PrintRunCommand` — `--format=html` zapíše `index.html`, `header.html`,
    `footer.html` a assety; `--data` bez DB; nesouhlasící `printId`;
    `--data` + `json` = chyba.
  - Render HTML nad fixture: vodoznak u storna, žádný vodoznak u stavu 40;
    prázdná strana vynechaná; podpisy pokladního dokladu podle směru;
    tabulka Kontace (chybový řádek, dimenze).
  - Test pravidla vodoznaku: pro každou deklaraci s `watermarks`
    vyrenderovat fixture se storno stavem a ověřit text vodoznaku v HTML.
  - `PrintDeclarationsTest` — úplnost katalogů nových šablon (`cs`, `en`).
- **Integrační (dev DS, vzor `DocPrintBuilderTest`), snapshot JSON
  `data` v `tests/Fixtures/Prints/`:**
  - pokladní doklad příjem s prodejem (plátce) → `cashInTaxDocument`,
  - pokladní doklad příjem jen úhrada faktury → `cashIn`,
  - pokladní doklad výdej bez partnera → `cashOut`, `supplier: null`,
  - prodejka plátce bez partnera, prodejka převodem s QR, vratka,
  - stornovaná faktura → `meta.watermark`,
  - Kontace nad fakturou (s dimenzemi, je-li DS má) a nad účetním
    dokladem `cmnbkp` (bez snapshotů), doklad bez zápisů → varování,
  - doklad ve výstupu bez vlastního snapshotu → `PrintBuildException`.
- Gated Gotenberg: vícestránková stornovaná faktura — vodoznak na každé
  straně (`pdftotext` každé strany obsahuje „STORNO“).

## Task breakdown

### Commit 1 — Nástroje pro šablony (D28)

`PrintData::fromArray()`, render z hotového `PrintData` v `PrintRunner`,
`print-run --format=html` a `--data`, testy, `docs/cli.md`.

**Hotovo když:** `print-run docs.invoicesOut.invoice --data=tests/Fixtures/Prints/<fixture>.json --format=html --output=/tmp/x`
vyrobí adresář otevřitelný v prohlížeči bez DB záznamu.

### Commit 2 — Vodoznak, strany, směr, titulky

D23 (deklarace, runner, obálka, partial, katalog, faktura a zálohová
faktura na `[40, 30]`), D24 (`DocPrintContext`, `DocPartiesBlock`,
partial stran), `document.tradeDir`, refaktor `TitleVariantResolver`
(zatím jen stávající varianty), aktualizace fixture snapshotů (nové pole
`tradeDir`, `meta.watermark`), testy.

**Hotovo když:** stornovaná faktura na dev DS má Tisk a PDF s „STORNO“
na všech stranách; existující testy tisků zelené.

### Commit 3 — Pokladní doklad (D25)

Deklarace, `CashDocPrintBuilder`, blok `cashDesk`, varianty `cashIn*` /
`cashOut`, šablona a katalog, testy.

**Hotovo když:** příjmový i výdajový pokladní doklad na dev DS se vytiskne
se správným titulkem, pokladnou a podpisy.

### Commit 4 — Prodejka (D26)

Deklarace, `CashRegisterPrintBuilder`, varianty `cashRegister*`, šablona
a katalog, testy.

**Hotovo když:** prodejka hotově bez partnera, prodejka převodem s QR
a vratka se vytisknou se správným titulkem.

### Commit 5 — Kontace (D27)

Deklarace v `economy.accounting`, `DocJournalPrintBuilder`, šablona
a katalog, testy.

**Hotovo když:** v detailu faktury je dropdown Tisk (Faktura, Kontace);
Kontace faktury, pokladního dokladu i účetního dokladu se vytiskne se
zápisy shodnými s tabem Účtování.

### Commit 6 — Dokumentace a nápověda

- `docs/prints.md`: `meta.watermark` a pravidlo vykreslení, `watermarks`
  v deklaraci, nullable strany, `document.tradeDir`, tabulka titulků,
  nové tisky, Kontace (kontrakt `data`), CLI nástroje v kapitole
  „Jak přidat tisk“ (workflow: `--format=json` → upravit šablonu →
  `--data … --format=html`).
- Nápověda: `help/faktury-vydane/tisk-faktury.md` (storno, Kontace),
  tisk v `help/pokladna/pokladni-doklad.md` a `help/pokladna/prodejka.md`,
  Kontace v nápovědě účtárny (nový článek nebo doplnění existujícího —
  podle pravidel nápovědy).
- Roadmapa M4 — řádek tisků.
- Hlavička tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu

Zamčeno v #90: D22–D28 (komentář „Rozhodnutí: fáze 2“). Upřesnění
z PRD:

- Vodoznak vykresluje layout (partial v `docs.core`), ne renderer —
  pravidlo „layout s `watermarks` musí vykreslit `meta.watermark`“
  hlídá test.
- Popisky stran pokladního dokladu se nemění podle směru — „Dodavatel /
  přijal“ a „Odběratel / vydal“ platí v obou směrech, kdo je kdo určují
  snapshoty.
- Kontace u dokladu bez směru (`cmnbkp`) bere účetní jednotku z aktuálních
  dat (interní tisk, D2).

## Odchylky od zadání (implementace 2026-10-02)

- **Fixture jsou celé obálky `PrintData`** (`tests/Fixtures/Prints/<název>.json`
  místo `*.data.json` jen se sekcí `data`) — jinak by nešly použít
  v `print-run --data`, jak zadání předpokládá. Testy kontraktu porovnávají
  `data` a `meta`; test pravidla vodoznaku si fixture najde podle `printId`.
- **Verze kontraktu je metoda `PrintBuilder::version()`**, ne pole
  `PrintBuildResult::version` — render z JSON ji potřebuje znát bez běhu
  builderu.
- **Kontrolu `printId` a verze dělá `PrintRunner::renderData()`**, ne příkaz
  — platí pro každého volajícího. `--data` s `recordId` je chyba.
- **`PrintRunner` a `PrintRunnerFactory` mají spojení do databáze
  nepovinné**; renderer vzniká vždy (HTML render službu nepotřebuje), PDF bez
  služby končí jako `unconfigured` stejně jako dřív.
- **Bankovní účet jen u platby převodem** (`payment.bankAccount` jinak
  `null`, schváleno před implementací) — mění i fakturu placenou hotově.
  Nový příznak **`payment.bankTransfer`** řídí v šablonách splatnost, účet,
  QR a popisek „K úhradě“ / „Celkem“.
- **Zápatí a země DPH z vlastní strany podle `tradeDir`** — na výdajovém
  dokladu je dodavatel partner (nebo chybí), zadání to neřešilo.
- **Den přijetí platby** je `dates.paymentReceived` (jen pokladní doklad,
  příjem s DPH — tam ho nabízí i formulář).
- **Pokladnu načítá `DocPrintContext::load()`** (jediné místo se SQL
  tisků dokladů), bloky `DocCashDeskBlock` / `DocCashDatesBlock`.
- **Layout dokladů má dva nové prázdné bloky:** `signatures` (podpisy
  pokladního dokladu, „Zaúčtoval“ na Kontaci) a `styles` (vlastní CSS
  šablony — Kontace).
- **Kontace:** šablona přepisuje i bloky `dates` a `notes` (layout je čte
  pod `strict_variables`) a má vlastní zápatí s účetní jednotkou. Strany
  nejsou nikdy povinné (`DocPrintContext::forHead()`) — interní tisk nesmí
  spadnout na dokladu z importu bez partnerského snapshotu. Součet MD / Dal
  je poslední řádek tabulky, ne zvláštní blok. Popisky dimenzí jsou
  vytažené z `DocsHeadsViewer` do `Core\Accounting\JournalDimensionLabels`
  a sdílené s tabem Zaúčtování.
- **Oprava z fáze 1 (samostatný commit):** prvek s třídou `title` v záhlaví
  Chromium přepisuje `<title>` stránky — záhlaví netisklo obsah šablony
  (číslo dokladu vycházelo tučně). Třídy přejmenovány na `head-title` /
  `head-number`.
- **Vodoznak v PDF testu** se hledá přes `pdftotext -raw` — režim `-layout`
  otočený text vynechává.
- **Nápověda šla s každým tiskem** (commity 2–5), ne až v commitu 6 —
  pravidlo z `CLAUDE.md`. Kontace má vlastní stránku
  `help/uctarna/tisk-kontace.md`.
