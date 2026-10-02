# Tisky — PDF nad jedním záznamem

Doména `print` ([reports.md](reports.md) §1): výstup nad **jedním záznamem**
— faktura vydaná, zálohová faktura, později pokladní doklad nebo karta
majetku. Rozhodnutí D1–D21 jsou v issue #90, zadání první fáze
v `tasks/prints-phase1.md`.

Hotovo: kontrakt `PrintData`, infrastruktura (deklarace, registr, runner,
Twig, PDF), tisk faktury vydané a zálohové faktury, REST, CLI, akce Tisk
v detailu. Nehotovo: nastavení vzhledu a texty na tiscích (fáze 3), e-mail
a zmrazená odeslaná kopie (fáze 4), jazyk partnera (#94), QR pro další země
(#91), opravný daňový doklad (#92), „Vystavil“ (#93).

## 1. Princip

```
deklarace (module.jsonc → prints)
        │
PrintRunner ── záznam, dostupnost, jazyk
        │
PrintBuilder ──► PrintData (JSON) ──► PrintRenderer ──► RenderClient ──► PDF
                     │                 (Twig, sandbox)   (profil Report)
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
v `modules/docs/core/src/Prints/`.

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
    "docStates": [40],
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
| `audience` | ne | `external` (default; jde ven z firmy) / `internal` |
| `builder` | ano | Třída implementující `PrintBuilder` |
| `template` | ano | Adresář šablony `@<modul>/<adresář>` → `<modul>/prints/<adresář>/` |
| `catalogs` | ne | **Sdílené adresáře tisku** (typicky layout): jejich katalog překladů, assety a výchozí záhlaví a zápatí (§5) |
| `paper` | ne | `format` (A3/A4/A5/Letter/Legal), `orientation`, `margins` (`top`/`right`/`bottom`/`left` jako CSS délky; chybějící strana = okraj profilu Report, 1.6 cm) |
| `order` | ne | Pořadí v nabídce (default 1000) |

`PrintDefinitionLoader` (`src/Api/`) staví `PrintRegistry` z modulů zdroje
dat. `PrintRegistry::forRecord($table, $record)` vrací tisky dostupné pro
záznam — sedí tabulka, `filter` i `docStates` — seřazené podle `order`.

Příznak `enablePrint` v `docStates.jsonc` se nepoužívá; autoritou je
`docStates` deklarace.

## 3. Běh

`PrintRunner::run($printId, $recordId, PrintFormat, ?$language): PrintOutput`
je jediný vstupní bod — REST i CLI ho staví přes `PrintRunnerFactory`.

1. Definice z registru → `PrintNotFoundException`.
2. Záznam `SELECT *` z tabulky deklarace → `PrintRecordNotFoundException`;
   nesplní `filter` / `docStates` → `PrintNotAvailableException`.
3. Jazyk tisku: výslovný parametr, jinak výchozí jazyk zdroje dat
   (`PrintLanguageResolver` — jediné místo, kam přibude jazyk partnera).
   Podporované jazyky: `cs`, `en`.
4. Builder dostane `PrintRequest`: definici, záznam, jazyk, spojení,
   **`ConfigRuntime` v jazyce tisku** (ne v jazyce requestu — popisky
   číselníků jdou na doklad) a `PrintTranslator` nad katalogy tisku.
5. Obálka `PrintData`. `json` tím končí; `pdf` pokračuje rendererem.
6. Render služba PDF nevyrobí → `PrintRenderException` s `errorKind`
   (provozní stav, ne programátorská chyba).

Builder hlásí dvojí druh problému: **tvrdý** výjimkou `PrintBuildException`
(doklad bez snapshotu stran — tisk nesmí číst z dnešního adresáře)
a **měkký** jako `PrintMessage` v `messages` (QR platba nevznikla).

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
              "fileName": "faktura-2026000123.pdf" },
    "branding": { "logo": "logo.png" },     // null bez loga
    "texts": {},                            // sloty textů na tiscích — fáze 3
    "messages": [ { "severity": "warning", "code": "payment.qrNoAccount", "text": "…" } ],
    "data": { … }
}
```

**Hodnoty (D13):** částky a množství jsou čísla v plné přesnosti, data ISO
`YYYY-MM-DD`. Nic se v builderu nezaokrouhluje ani neformátuje. Popisky
číselníků řeší builder v jazyce tisku.

### `data` tisků nad `docs_core_heads`

`DocPrintBuilder` skládá bloky z `modules/docs/core/src/Prints/Blocks/`.
SQL je jen v `DocPrintContext::load()`; bloky jsou čisté funkce nad
kontextem. Další tisk dokladu dědí builder a v `blocks()` blok přidá nebo
vynechá.

| Klíč | Blok | Obsah |
|---|---|---|
| `document` | `DocDocumentBlock` | `type`, `titleVariant`, `title`, `number`, `text`, `notice`, `isTaxDocument`, `vatPayer`, `vatMode` (0 bez DPH / 1 ze základu / 2 z ceny celkem), `currency`, `homeCurrency`, `exchangeRate` (null v domácí měně), `foreignCurrency` |
| `dates` | `DocDatesBlock` | `issue`, `due`, `duzp` (null u nedaňového dokladu), `periodFrom`, `periodTo` |
| `supplier`, `customer` | `DocPartiesBlock` | snapshoty hlavičky **beze změny tvaru** (`PersonSnapshotBuilder`) |
| `payment` | `DocPaymentBlock` | `method {id, label}`, `reference`, `specificSymbol`, `constantSymbol`, `bankAccount` (ze snapshotu dodavatele), `amountToPay`, `currency`, `qr {standard, payload}` nebo null |
| `rows` | `DocRowsBlock` | `kind: item` — `description`, `quantity`, `unit {id, label}`, `unitPrice`, `unitPriceIncludesVat`, `discountPct`, `vat {code, pct, label, noteMark}`, `base`, `vatAmount`, `total`, `advanceDeduction`; `kind: text` — jen `description` |
| `vatRecap` | `DocVatRecapBlock` | `label`, `pct`, `base`, `tax`, `total`, `baseDom`, `taxDom`, `totalDom`, `noteMark` |
| `vatNotes` | `DocVatRecapBlock` | `{mark, text}` — každá poznámka jednou |
| `advances` | `DocAdvancesBlock` | `{base, vat, total}` kladně, nebo null |
| `totals` | `DocTotalsBlock` | `base`, `vat`, `rounding`, `total`, `totalBeforeAdvances`, `baseDom`, `vatDom`, `totalDom` |

Pravidla:

- **Plátce** = hlavička má `vat_registration`. DPH se tiskne, když je
  doklad plátce a `vatMode ≠ 0`; jinak `rows[].vat` je null a `vatRecap`
  prázdný.
- **Titulek (D16):** `TitleVariantResolver` — `invoiceVatPayer`,
  `invoiceNonVatPayer`, `proforma`; text z katalogu pod `title.<varianta>`.
  Neznámý typ dokladu = `PrintBuildException`. `correctiveVatPayer` /
  `correctiveNonVatPayer` jsou rezervované (#92).
- **Strany a náš účet ze snapshotů (D5, D14)**; jednotky, tiskové popisky
  DPH a logo jsou aktuální v okamžiku tisku. Chybí-li snapshot, tisk
  skončí chybou.
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

Kontrakt hlídá `tests/Integration/Prints/DocPrintBuilderTest.php` proti
JSON v `tests/Fixtures/Prints/` (D15). Nekompatibilní změna `data` =
zvýšit `DocPrintBuilder::VERSION` a upravit fixture.

## 5. Šablony

Umístění `modules/<modul>/prints/<adresář>/`, Twig namespace = id modulu:

```
modules/docs/core/prints/
    _layout/
        doc-base.html.twig    # kostra stránky dokladu s bloky
        header.html.twig      # záhlaví: logo, titulek, číslo dokladu
        footer.html.twig      # zápatí: dodavatel, stránkování
        doc-base.css
        messages.jsonc        # společný katalog
    _partials/
        parties.html.twig, party.html.twig, payment.html.twig,
        rows.html.twig, vat-recap.html.twig, totals.html.twig

modules/docs/invoicesOut/prints/invoice/
    page.html.twig            # extends doc-base, beze změn
    messages.jsonc            # titulky, název souboru

modules/docs/proformasOut/prints/proforma/
    page.html.twig            # + věta „Nejedná se o daňový doklad.“
    messages.jsonc
```

`PrintRenderer` skládá tisk ze tří dokumentů a assetů:

- **`page.html.twig`** z adresáře šablony — povinná. Stránka typu dokladu
  je tenká: dědí `doc-base` a přepisuje jen bloky, kterými se liší
  (`title`, `parties`, `dates`, `payment`, `rows`, `vatRecap`, `totals`,
  `notes`).
- **`header.html.twig` / `footer.html.twig`** — hledají se v adresáři
  šablony, pak ve sdílených adresářích (`catalogs`). Jsou to **samostatné
  HTML dokumenty** pro render službu, tisknou se na každé straně: styly
  inline, logo jako data URI (`branding.logoDataUri`), vodorovné odsazení
  shodné s okraji stránky. Čísla stran doplní render služba do prvků
  `class="pageNumber"` / `class="totalPages"`. Výšku záhlaví musí pokrýt
  `paper.margins.top`.
- **Assety** — soubory `css`, obrázky a fonty ze sdílených adresářů
  a z adresáře šablony (šablona má přednost); pushují se s HTML
  a referencují relativně (`<link href="doc-base.css">`). Logo z brandingu
  (slot `companyLogo`) jde jako asset pod názvem z `branding.logo`.

Šablona dostává `PrintData::toArray()`: proměnné `printId`, `language`,
`record`, `meta`, `branding`, `texts`, `messages`, `data`.

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
pro administrátora. Na read-only zdroji dat povoleno.

- `pdf` (default): `application/pdf`, `Content-Disposition: inline;
  filename="…"` (z `meta.fileName`). Měkká hlášení builderu nese hlavička
  **`X-Print-Messages`** — procentově kódované JSON pole `messages`.
- `json`: `{success, data}`, `data` = `PrintData`.

| Kód | HTTP | Kdy |
|---|---|---|
| `PRINT_NOT_FOUND` | 404 | neznámé id tisku |
| `RECORD_NOT_FOUND` | 404 | záznam neexistuje |
| `PRINT_NOT_AVAILABLE` | 409 | stav nebo typ záznamu tisk nedovoluje |
| `PRINT_DATA_MISSING` | 409 | záznamu chybí data pro tisk (snapshot) |
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
  `value` = id tisku

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
shpd-ds print-run <printId> <recordId> [--format=json|pdf] [--language=cs|en] [--output=<soubor>]
```

`json` (default) vypíše `PrintData` na stdout, `pdf` vyžaduje `--output`.
Viz [cli.md](cli.md).

## 9. Jak přidat tisk

Příklad: karta majetku (`economy.assets`).

1. **Builder** `modules/economy/assets/src/Prints/AssetCardPrintBuilder.php`
   implementuje `PrintBuilder`: z `PrintRequest` načte kartu a události
   a vrátí `PrintBuildResult` (`data`, titulek, název souboru, hlášení,
   verze kontraktu). Popisky číselníků čte z `$request->config` — je
   v jazyce tisku. Kontrakt `data` popiš v dokumentaci modulu.
2. **Šablona** `modules/economy/assets/prints/card/page.html.twig`
   a `messages.jsonc` (`cs` i `en`). Vlastní `header.html.twig` /
   `footer.html.twig` a CSS polož vedle, nebo do sdíleného adresáře
   a uveď ho v `catalogs`.
3. **Deklarace** v `config/prints.jsonc` + `"prints": [{"file": …}]`
   v `module.jsonc`: `table`, `docStates`, `audience: "internal"`,
   `builder`, `template`, okraje podle výšky záhlaví.
4. **Testy:** fixture záznam → porovnání `data` s JSON
   (`tests/Fixtures/Prints/`), render šablony do HTML
   (`PrintRenderer::renderDocument()` — bez render služby).
   `PrintDeclarationsTest` nový tisk zkontroluje sám (builder, šablona,
   úplnost katalogů).
5. `ds-upgrade` není potřeba — deklarace se čtou z modulů při requestu.
   Akce Tisk se v detailu objeví sama.

Ladění: `shpd-ds print-run <id> <záznam>` pro data,
`--format=pdf --output=x.pdf` pro výsledek.

## 10. Testy

- Unit `tests/Unit/Core/Prints/` — deklarace, registr, runner, katalogy,
  Twig filtry a sandbox; `tests/Unit/Module/Docs/Core/Prints/` — bloky,
  QR platba, šablony dokladů do HTML; `tests/Unit/Api/Controller/PrintsApiTest.php`
  — routa, controller, akce v detailu.
- Integrační `tests/Integration/Prints/` — kontrakt nad fixture doklady
  v dev DS (`PrintFixtureDocuments`), CLI. `PrintPdfTest` jde přes celou
  cestu do PDF a vedle `SHIPARD_INTEGRATION_DS_PATH` potřebuje
  `SHIPARD_INTEGRATION_GOTENBERG_URL`.
