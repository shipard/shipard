# Tisky — Fáze 0 + 1: kontrakt `PrintData`, infrastruktura, faktura vydaná a zálohová

**Stav:** hotovo — 2026-10-02 (5 commitů; ověřeno na `4l3j-` a čtením na `btpg-p`, náhled a stažení PDF prokliknuté v prohlížeči); odchylky od zadání na konci

> PRD pro Claude Code (5 commitů). Design: issue #90 (D1–D11 v těle,
> D12–D21 v komentáři „Rozhodnutí: kontrakt `PrintData`, UI, endpoint,
> práva“). Vedlejší issues: #91 (QR pro další země), #92 (opravný daňový
> doklad), #93 („Vystavil“), #94 (jazyk a spojování příloh na osobě).

## Kontext

Tisk = PDF nad **jedním záznamem** (doména `print`, `docs/reports.md` §1).
Roadmapa M4 má „Tisk / PDF vydaného dokladu“ jako blokátor migrace. Dnes
existuje jen render (`RenderClient`, profil `Report`, `docs/render.md`);
tisky samotné chybí úplně.

Tato fáze staví kostru obecnou pro libovolnou tabulku (D2) a dotahuje ji
do konce pro **fakturu vydanou** (`invno`) a **zálohovou fakturu**
(`invpo`): deklarace → builder → `PrintData` (JSON) → Twig šablona → PDF →
tlačítko Tisk v detailu dokladu.

**Vztah k fázím #90:** fáze 0 (kontrakt) a 1 (infrastruktura + první
tisk) jsou tady celé. Twig zavádíme v nutném minimu (sandbox, filtry,
překlady, jedna sdílená šablona dokladu); fáze 2 #90 rozvede layout
s bloky, další jazyky, fonty a záhlaví. Kosmetika (nastavení vzhledu,
texty na tiscích) je fáze 3, e-mail fáze 4.

## Před implementací přečti

- Issue #90 celé včetně komentářů (D1–D21)
- `docs/reports.md` §1, §2, §4, §10, §15 — vzor deklarace, registru,
  REST a exportu souboru
- `docs/render.md` celé — `RenderClient`, `PdfOptions`, profil `Report`,
  push-only assety, `index.html`, header/footer jako samostatné HTML
- `src/Core/Reports/ReportRegistry.php`, `ReportDefinition.php`,
  `src/Api/ReportDefinitionLoader.php`, `ModuleDefinition.php` (klíč
  `reports`) — **vzor pro registr tisků**
- `src/Api/Controller/ReportsController.php` + routy `/_reports`
  v `src/Api/Router.php`, `Response::binary`
- `src/Command/DataSource/ReportRunCommand.php` — vzor pro `print-run`
- `src/Api/Controller/ViewerController.php::detail()` — místo pro
  generické připojení akce Tisk (vzor: zámek záznamu)
- `modules/docs/core/src/DocsHeadsViewer.php::buildDetailActions()`
  a `frontend/src/components/viewer/ViewerDetail.svelte` (akce
  `kind: dropdown`), `Viewer.svelte::handleDetailAction`
- `modules/docs/core/src/PersonSnapshotBuilder.php` — tvar snapshotů
- `modules/docs/core/src/DocTypes.php` (`isTaxDocument`),
  `DocHeadVatContext.php` (plátce = `vat_registration`)
- `modules/docs/core/tables/docs_core_heads.jsonc`, `docs_core_rows.jsonc`,
  `docs_core_vat_recap.jsonc`, config `paymentMethods`, `vatModes`,
  `rowOperations` (`sale.advanceDeduction`)
- `modules/world/vat/config/vat-cz.jsonc` — `vatCodes[].print`,
  `vatCodes[].note`, `vatNotes`
- `src/Core/Settings/BrandingStorage.php` — slot `companyLogo`
- `frontend/src/api/client.js::getBlob()`, `utils/download.js`,
  náhled PDF v `components/viewer/AttachmentGrid.svelte`

## Scope

**Uvnitř:**

- Composer: `twig/twig` (^3), `chillerlan/php-qrcode` (SVG výstup).
- Core `src/Core/Prints/`: deklarace, registr, loader, runner, obálka
  `PrintData`, Twig prostředí se sandboxem, filtry a překlady, renderer
  do PDF.
- Klíč `prints` v `module.jsonc` (D4).
- Builder tisků nad `docs_core_heads` se sdílenými bloky (D12) a SPAYD
  generátor (D17).
- Deklarace `docs.invoicesOut.invoice` (`invno`) a
  `docs.proformasOut.proforma` (`invpo`); sdílený layout a dílčí bloky
  šablon v `docs.core`, stránkové šablony v typových modulech, katalogy
  `cs` a `en`.
- REST `GET /_prints/{printId}/{recordId}?format=pdf|json`.
- Akce **Tisk** v detailu (generická přes registr), náhled PDF v dialogu,
  Stáhnout.
- CLI `print-run`.
- Testy, `docs/prints.md`.

**Mimo:**

- Nastavení vzhledu, akcentová barva, umístění loga, texty na tiscích
  (fáze 3). Logo se ve v1 vytiskne, pokud je v brandingu nahrané — nic víc.
- E-mail, zmrazená odeslaná kopie (fáze 4, D10, D11).
- Jazyk partnera — osoby dnes jazyk nemají; ve v1 = výchozí jazyk DS
  (viz Rozhodnutí k designu; doplní se v #94).
- „Vystavil“ (#93), stav úhrady, kódy položek, opravný daňový doklad (#92).
- QR pro jiné země než CZ (#91).
- ISDOC v PDF (samostatný task), POS, síťové tiskárny.
- MCP nástroj pro tisky.
- Další tisky (pokladní doklad, karta majetku) — infrastruktura je musí
  unést, ale deklarují se v dalších taskech.

## Deklarace (D2, D4)

`module.jsonc`:

```jsonc
"prints": [{ "file": "config/prints.jsonc" }]
```

`config/prints.jsonc` = pole deklarací:

```jsonc
[
    {
        "id": "docs.invoicesOut.invoice",
        "name": "Invoice",
        "name:cs": "Faktura",
        "name:en": "Invoice",
        "table": "docs_core_heads",
        "filter": { "doc_type": ["invno"] },     // volitelné; sloupec → povolené hodnoty
        "docStates": [40],                       // povinné, D2
        "audience": "external",                  // external | internal (D2)
        "builder": "Shipard\\Module\\Docs\\Core\\Prints\\DocPrintBuilder",
        "template": "@docs.invoicesOut/invoice", // adresář šablony (namespace modulu)
        "catalogs": ["@docs.core/_layout"],      // volitelné: další katalogy překladů
        "paper": { "format": "A4", "orientation": "portrait" },  // volitelné → PdfOptions
        "order": 10
    }
]
```

- `id` je textový, globálně unikátní; duplicita napříč moduly = tvrdá
  chyba loaderu (vzor `ReportRegistry`).
- `PrintRegistry::forRecord(string $table, array $record): PrintDefinition[]`
  vrátí tisky dostupné pro záznam: tabulka sedí, `filter` sedí,
  `docState` je v `docStates`. Seřazené podle `order`.
- `PrintDefinition::fromArray()` validuje: povinné `id`, `name`, `table`,
  `docStates` (neprázdné pole intů), `builder`, `template`; `audience`
  jen `external|internal`; volitelné `catalogs` = pole cest ve tvaru
  `@<modul>/<adresář>` s `messages.jsonc`.
- Příznak `enablePrint` v `docStates.jsonc` se nepoužívá (je mrtvý)
  a nenavazujeme na něj — autoritou je `docStates` deklarace tisku.

**Faktura i zálohová faktura sdílí builder, layout a dílčí bloky
šablon** z `docs.core`. Deklarace i stránková šablona žijí v typovém
modulu: `invno` v `docs.invoicesOut` (`@docs.invoicesOut/invoice`),
`invpo` v `docs.proformasOut` (`@docs.proformasOut/proforma`). Když se
v budoucnu typy rozejdou, změna zůstane v jejich modulu.

## Kontrakt `PrintData` (D12–D18)

Builder vrací pole; obálku doplní `PrintRunner`:

```jsonc
{
    "printId": "docs.invoicesOut.invoice",
    "version": 1,
    "language": "cs",
    "record": { "table": "docs_core_heads", "id": 123, "docState": 40 },
    "generatedAt": "2026-10-02T10:30:00+02:00",
    "meta": { "title": "Faktura – daňový doklad 2026000123",
              "fileName": "faktura-2026000123.pdf" },
    "branding": { "logo": "logo.png" },   // null, když logo není; fáze 3 rozšíří
    "texts": {},                          // fáze 3 (sloty D9) — v1 vždy prázdný objekt
    "messages": [],                       // měkká varování builderu (doplnění D12)
    "data": { … }                         // specifické pro tisk
}
```

### `data` pro tisky nad `docs_core_heads`

Sdílené bloky, každý jako malá třída v
`modules/docs/core/src/Prints/Blocks/` (`DocDocumentBlock`,
`DocDatesBlock`, `DocPartiesBlock`, `DocPaymentBlock`, `DocRowsBlock`,
`DocVatRecapBlock`, `DocAdvancesBlock`, `DocTotalsBlock`).
`DocPrintBuilder` je skládá; další tisky dokladů (pokladní doklad,
dobropis) přidají nebo vynechají bloky bez zásahu do ostatních.

```jsonc
"data": {
    "document": {
        "type": "invno",
        "titleVariant": "invoiceVatPayer",     // D16
        "title": "Faktura – daňový doklad",    // z katalogu v jazyce tisku
        "number": "2026000123",
        "text": "…",                           // doc_text
        "notice": "…",                         // doc_notice, null když prázdná
        "isTaxDocument": true,
        "vatPayer": true,                      // D16: hlavička má vat_registration
        "vatMode": 1,                          // 0 bez DPH, 1 ze základu, 2 z ceny celkem
        "currency": "EUR",
        "homeCurrency": "CZK",
        "exchangeRate": 24.31,                 // null v domácí měně
        "foreignCurrency": true
    },
    "dates": {
        "issue": "2026-09-30", "due": "2026-10-14",
        "duzp": "2026-09-30",                  // null u nedaňového dokladu
        "periodFrom": null, "periodTo": null
    },
    "supplier": { /* supplier_snapshot beze změny tvaru */ },
    "customer": { /* customer_snapshot beze změny tvaru */ },
    "payment": {
        "method": { "id": 1, "label": "Převodem" },
        "reference": "2026000123",             // VS
        "specificSymbol": null,
        "constantSymbol": null,
        "bankAccount": { /* supplier_snapshot.bank_account, null když není */ },
        "amountToPay": 1210.00,
        "currency": "EUR",
        "qr": { "standard": "spayd", "payload": "SPD*1.0*ACC:…*AM:1210.00*CC:EUR*X-VS:2026000123" }
    },
    "rows": [
        {
            "kind": "item",                    // item | text (row_kind)
            "description": "…",
            "quantity": 1,
            "unit": { "id": 3, "label": "ks" },
            "unitPrice": 1000,
            "unitPriceIncludesVat": false,     // vatMode 2
            "discountPct": null,
            "vat": { "code": "cz-210", "pct": 21, "label": "Základní", "noteMark": null },
            "base": 1000, "vatAmount": 210, "total": 1210,
            "advanceDeduction": false
        },
        { "kind": "text", "description": "…" }
    ],
    "vatRecap": [
        { "label": "Základní", "pct": 21, "base": 1000, "tax": 210, "total": 1210,
          "baseDom": 24310, "taxDom": 5105.10, "totalDom": 29415.10, "noteMark": null }
    ],
    "vatNotes": [ { "mark": "1", "text": "Daň odvede zákazník" } ],
    "advances": null,                          // nebo { "base": …, "vat": …, "total": … }
    "totals": {
        "base": 1000, "vat": 210, "rounding": 0, "total": 1210,
        "totalBeforeAdvances": 1210,
        "baseDom": 24310, "vatDom": 5105.10, "totalDom": 29415.10
    }
}
```

Pravidla:

- **Hodnoty (D13):** částky čísla v plné přesnosti (žádné zaokrouhlování
  ani formátování v builderu), data ISO `YYYY-MM-DD`. Popisky číselníků
  (`payment.method.label`, `unit.label`, `vat.label`, `document.title`)
  řeší builder v jazyce tisku. Šablona konfiguraci nečte.
- **Zdroj (D5, D14):** `supplier` a `customer` výhradně ze snapshotů
  hlavičky; `payment.bankAccount` ze `supplier_snapshot.bank_account`.
  Chybí-li snapshot u dokladu ve stavu 40, builder skončí chybou (nemá
  tisknout z adresáře). Jednotky, tisk sazeb DPH a logo aktuální.
- **Titulek (D16):** `invno` + plátce → `invoiceVatPayer`, `invno`
  + neplátce → `invoiceNonVatPayer`, `invpo` → `proforma`. Varianty
  `correctiveVatPayer` / `correctiveNonVatPayer` jsou rezervované (#92),
  builder je zatím nevrací. Neznámý typ dokladu = chyba builderu.
- **Rekapitulace DPH:** řádky `is_reverse_pair = 1` se netisknou.
  Popisek = `vatCodes[code].print` v jazyce tisku. `noteMark` přiřadí
  builder (pořadové číslo poznámky) pro kódy s `note`; `vatNotes` nese
  každou poznámku jednou. Neplátce / `vatMode` 0 → `vatRecap` prázdný.
  `*Dom` hodnoty jen při `foreignCurrency`, jinak `null`.
- **Zálohy (D18):** řádky s operací `sale.advanceDeduction` zůstávají
  v `rows` s `advanceDeduction: true`. `advances` = součet těchto řádků
  jako kladná čísla (`base`, `vat`, `total`), `null` když žádné nejsou.
  `totals.total` = `total_amount` hlavičky (už po odpočtu),
  `totals.totalBeforeAdvances` = `total + advances.total`.
- **QR (D17):** jen `payment_method = 1` (převodem) a kladná částka
  k úhradě. Generátor `Shipard\Module\Docs\Core\Prints\PaymentQr\SpaydGenerator`
  za rozhraním `PaymentQrGenerator`; výběr přes `PaymentQrResolver`
  (země odběratele → standard; v1 vždy SPAYD). Účet: IBAN ze snapshotu;
  chybí-li a účet je český ve tvaru `předčíslí-číslo/kód banky`, IBAN
  se dopočítá. Pole SPAYD: `ACC`, `AM` (2 desetinná místa), `CC`,
  `X-VS`, `X-SS`, `X-KS`, `DT` (splatnost `YYYYMMDD`), `MSG` (číslo
  dokladu). Hodnoty se escapují podle specifikace (`*` → `%2A`). Když
  QR nevznikne (chybí účet), `payment.qr = null` a do `messages` přijde
  varování.
- **Název souboru:** `meta.fileName` = slug z katalogového klíče
  `fileName.<titleVariant>` + číslo dokladu + `.pdf`, ASCII.

`DocPrintBuilder` dostane `PrintRequest` (definice, id záznamu, jazyk,
`ConfigRuntime`, DB spojení) a vrátí `data`, `meta` a `messages`.
Builder neví nic o Twigu ani o PDF.

## Šablony a Twig (D6, D8)

Umístění: `modules/<modul>/prints/<šablona>/`, Twig namespace = id
modulu (`@docs.invoicesOut/invoice/page.html.twig`). Společné části
dokladů jsou v `docs.core`, stránkové šablony v typových modulech:

```
modules/docs/core/prints/
    _layout/
        doc-base.html.twig    # kostra stránky dokladu, bloky (title, parties,
                              #   dates, payment, rows, vatRecap, advances,
                              #   totals, notes)
        doc-header.html.twig  # samostatný HTML dokument (Gotenberg header)
        doc-footer.html.twig  # samostatný HTML dokument (stránkování)
        doc-base.css
        messages.jsonc        # společné klíče { "klíč": { "cs": "…", "en": "…" } }
    _partials/
        parties.html.twig     # dodavatel + odběratel
        payment.html.twig     # platební údaje + QR
        rows.html.twig
        vat-recap.html.twig
        totals.html.twig      # součty, zálohy, domácí měna

modules/docs/invoicesOut/prints/invoice/
    page.html.twig            # extends @docs.core/_layout/doc-base, přepíše jen to, čím se liší
    messages.jsonc            # titulky, název souboru

modules/docs/proformasOut/prints/proforma/
    page.html.twig            # + věta „Nejedná se o daňový doklad“, bez DUZP
    messages.jsonc
```

Stránková šablona typu dokladu je tenká: dědí `doc-base` a přepisuje
bloky jen tam, kde se typ liší. Header a footer se berou z layoutu,
pokud adresář šablony nemá vlastní `header.html.twig` /
`footer.html.twig` (ty mají přednost).

- `PrintTwigFactory` staví `Twig\Environment` s `FilesystemLoader`
  (namespace per modul přes `ModulePathResolver`), cache v
  `<ds>/cache/twig`, `autoescape: html`, `strict_variables: true`.
- **Sandbox (D6):** `SandboxExtension` zapnutý globálně, politika
  `PrintSecurityPolicy::templates()` — tagy `if`, `for`, `set`,
  `block`, `extends`, `include`, `apply`; filtry `escape`, `e`, `default`,
  `length`, `join`, `upper`, `lower`, `nl2br`, `first`, `last`,
  `keys`, `merge` + naše; funkce `t`, `qr_svg`, `block`, `include`;
  **žádné metody ani vlastnosti objektů** (šablona dostává jen pole).
  Druhou, úzkou politiku pro uživatelské texty (D9) zavede fáze 3 —
  `PrintSecurityPolicy` ji jen pojmenuje jako budoucí `userTexts()`.
- **Filtry** (`PrintTwigExtension`, locale = jazyk tisku):
  `money` (2 desetinná místa, oddělovač tisíců nezlomitelnou mezerou
  v `cs`; volitelně kód měny), `qty` (bez zbytečných nul),
  `pct`, `date` (ISO → `2. 10. 2026` v `cs`, `10/2/2026` v `en`).
- **Překlady (D8):** funkce `t('klíč')` čte sloučený katalog —
  katalogy z `catalogs` deklarace a pak `<šablona>/messages.jsonc`
  (pozdější klíč má přednost) — v jazyce tisku;
  chybějící jazyk → `cs`; chybějící klíč → vrátí klíč a zaloguje warning.
- **QR:** funkce `qr_svg(payment.qr)` vrátí inline SVG
  (`chillerlan/php-qrcode`), `null` → prázdný řetězec. Výstup je
  bezpečný (`is_safe: html`).
- **Assety:** CSS a logo jdou do `RenderClient::renderHtml()` jako
  `$assets` (push-only, relativní odkazy). Header a footer jsou
  samostatné HTML dokumenty pro `PdfOptions::headerTemplate` /
  `footerTemplate` — styly inline, logo jako data URI, čísla stránek
  přes `<span class="pageNumber">` / `totalPages`.

**Obsah šablony faktury v1:** titulek a číslo, logo (je-li), dodavatel
a odběratel (adresa z `display_block`, IČ, DIČ / VAT ID, zápis v
rejstříku dodavatele), data (vystavení, splatnost, DUZP u daňového
dokladu), platební údaje + QR, řádky (sloupce DPH jen u plátce;
textové řádky přes celou šířku; odpočty záloh vizuálně odlišené),
rekapitulace DPH, zálohy, součty a částka k úhradě, při cizí měně
kurz a DPH v domácí měně, poznámky DPH, poznámka na doklad.
U nedaňového dokladu (šablona `proforma`) věta „Nejedná se o daňový
doklad“.
Patička: stránkování. Vizuálně střídmé, černobílé s jednou šedou —
akcentová barva a varianty vzhledu jsou fáze 3.

## Běh a renderer

```
PrintRunner::run(string $printId, int $recordId, PrintFormat $format, ?string $language): PrintOutput
```

1. Definice z registru (neznámé id → `PrintNotFoundException`).
2. Záznam z tabulky definice; neexistuje → `RecordNotFound`;
   nesplní `filter` / `docStates` → `PrintNotAvailableException`
   (koncept se netiskne, D5).
3. Jazyk: parametr, jinak výchozí jazyk DS (`PrintLanguageResolver` —
   jediné místo, kam později přibude jazyk partnera).
4. Builder → obálka (`PrintData`).
5. `json` → konec. `pdf` → `PrintRenderer` (Twig → HTML, header,
   footer, assety, `PdfOptions` z `paper` deklarace) →
   `RenderClient::renderHtml(…, RenderProfile::Report)`.
6. `RenderResult` bez `ok` → `PrintRenderException` s `errorKind`
   (provozní stav, ne programátorská chyba).

## REST (D20, D21)

`GET /_prints/{printId}/{recordId}?format=pdf|json[&language=cs]`

> **Upřesnění D20:** #90 uvádí `/api/prints/{printId}/{table}/{id}`.
> Podle konvence repa (`/_reports`) je prefix `/_prints` a tabulka se
> v URL neopakuje — určuje ji deklarace tisku.

- Práva (D21): `TableAccessGuard::guardTable()` na tabulku deklarace —
  kdo smí detail, smí tisk. `format=json` jen pro `isAdmin`, jinak 403
  `FORBIDDEN_ADMIN_ONLY`.
- `pdf` (default): `Response::binary`, `Content-Type: application/pdf`,
  `Content-Disposition: inline; filename="…"` (z `meta.fileName`).
- `json`: standardní obálka `{success, data}`, `data` = `PrintData`.
- Chyby (JSON): `PRINT_NOT_FOUND` 404, `RECORD_NOT_FOUND` 404,
  `PRINT_NOT_AVAILABLE` 409 (stav / typ záznamu), `BAD_REQUEST` 400
  (neplatný `format` / `language`), `RENDER_UNAVAILABLE` 503
  (`unconfigured | unreachable | timeout`), `RENDER_FAILED` 500
  (`engineError | invalidInput`), u obou s `errorKind` v `details`.
- `ReadOnlyPolicy`: čtení.

## UI (D19)

- `ViewerController::detail()` po `renderDetail()` připojí do
  `detail.actions` akci tisku, pokud `PrintRegistry::forRecord()`
  vrátí něco — **generický háček, ne kód v `DocsHeadsViewer`**, aby
  tisky jiných tabulek (karta majetku) fungovaly bez dalšího zásahu.
  Jeden tisk → `{id: "print", kind: "button", label: "Tisk",
  target: {printId}}`; víc tisků → `kind: "dropdown"` s `items`
  (`label` = název tisku, `value` = `printId`). Popisky lokalizované
  (`core.system.viewerDefaults` nebo i18n backendu — jak je zvykem
  u existujících akcí).
- `Viewer.svelte::handleDetailAction`: `print` → otevře
  `PrintPreviewDialog` (nová komponenta `components/viewer/`):
  fetch PDF přes `getBlob()` (Bearer auth), náhled v `<iframe>`
  z object URL, tlačítka **Stáhnout** (název z `Content-Disposition`,
  `utils/download.js`) a Zavřít; spinner během renderu; chyba
  (`translateError`, 503 = „Tisková služba není dostupná“) v dialogu.
  Object URL uvolnit při zavření.
- Mobil: dialog fullscreen; když `<iframe>` PDF neumí zobrazit, ukázat
  jen Stáhnout.
- Ve formuláři nic (D19).

## CLI

```
shpd-ds print-run <printId> <recordId> [--format=json|pdf] [--language=cs] [--output=<soubor>]
```

`json` bez `--output` na stdout (pretty print), `pdf` vyžaduje `--output`.
Chyby jako u REST, exit kód ≠ 0. Zápis do `docs/cli.md`.

## Task breakdown

### Commit 1 — Core: deklarace, registr, runner, obálka

- `composer require twig/twig chillerlan/php-qrcode` (zatím jen
  instalace, použití v commitu 3).
- `ModuleDefinition`: klíč `prints` (`[{file}]`, vzor `reports`).
- `src/Core/Prints/`: `PrintDefinition`, `PrintRegistry`
  (`get`, `getAll`, `forRecord`), `PrintFormat` (enum), `PrintRequest`,
  `PrintBuilder` (rozhraní), `PrintBuildResult`, `PrintRunner`
  (bez rendereru — `pdf` zatím `PrintRenderException` `unconfigured`),
  `PrintLanguageResolver`, výjimky.
- `src/Api/PrintDefinitionLoader.php` (vzor `ReportDefinitionLoader`,
  i18n přes `ConfigLocalizer`).
- Unit testy: parsování a validace deklarace, duplicitní id,
  `forRecord` (tabulka, filtr, stavy, řazení), obálka z runneru
  s fake builderem.

**Hotovo když:** testy `--filter Print` zelené, `ds-upgrade` dev DS
projde beze změny chování.

### Commit 2 — Builder dokladu, SPAYD, deklarace, JSON

- `modules/docs/core/src/Prints/`: `DocPrintBuilder`, bloky (viz výše),
  `TitleVariantResolver`, `PaymentQr/` (`PaymentQrGenerator`,
  `SpaydGenerator`, `PaymentQrResolver`, `CzIbanCalculator`).
- `config/prints.jsonc` + klíč `prints` v `docs.invoicesOut`
  a `docs.proformasOut`.
- Katalogy `messages.jsonc` (`cs`, `en`) pro titulky a název souboru
  (zbytek klíčů přibude v commitu 3).
- CLI `print-run` (zatím `--format=json`).
- Testy: unit `SpaydGenerator` (escapování, částka, chybějící
  symboly, dopočet IBAN s předčíslím i bez), `TitleVariantResolver`
  (plátce / neplátce / proforma / neznámý typ), `PaymentQrResolver`;
  integrační nad dev DS: fixture faktura (plátce, dvě sazby, textový
  řádek, odpočet zálohy, cizí měna) a fixture proforma → porovnání JSON
  `data` se snapshotem v `tests/Fixtures/Prints/` (D15; `generatedAt`
  a id vynechat z porovnání), koncept → `PrintNotAvailableException`,
  doklad 40 bez snapshotu → chyba builderu.

**Hotovo když:** `print-run docs.invoicesOut.invoice <id> --format=json`
na dev DS vrátí kontrakt výše; snapshot testy zelené.

### Commit 3 — Twig, šablona, PDF

- `src/Core/Prints/Twig/`: `PrintTwigFactory`, `PrintSecurityPolicy`,
  `PrintTwigExtension` (filtry, `t`, `qr_svg`), `PrintTranslator`.
- `PrintRenderer` (Twig → HTML + header + footer + assety →
  `RenderClient`), napojení do `PrintRunner`.
- Layout a partials `modules/docs/core/prints/`, stránkové šablony
  `modules/docs/invoicesOut/prints/invoice/` a
  `modules/docs/proformasOut/prints/proforma/`, kompletní katalogy
  `cs` a `en`.
- `print-run --format=pdf --output=…`.
- Testy: unit — filtry `cs`/`en`, sandbox odmítne nepovolený tag /
  filtr / přístup k metodě, `t()` fallbacky, **úplnost katalogů**
  (každý klíč z `cs` existuje v `en` a naopak), render šablony do HTML
  nad fixture `PrintData` z commitu 2 (obsahuje číslo dokladu, titulek,
  QR `<svg`, u proformy větu o nedaňovém dokladu, u neplátce bez
  sloupců DPH); integrační gated `SHIPARD_INTEGRATION_GOTENBERG_URL` —
  PDF začíná `%PDF`, `pdftotext` obsahuje číslo dokladu.

**Hotovo když:** `print-run … --format=pdf` vyrobí na dev DS čitelnou
fakturu i proformu; ručně zkontrolováno vizuálně (plátce, neplátce,
cizí měna, záloha, víc stran).

### Commit 4 — REST a UI

- Routa `/_prints/{printId}/{recordId}` v `Router.php`,
  `PrintsController`, mapování chyb, `ReadOnlyPolicy`.
- Háček v `ViewerController::detail()` (akce `print`).
- `PrintPreviewDialog.svelte`, obsluha v `Viewer.svelte`, i18n klíče
  frontendu (`cs`, `en`).
- Testy: controller (404, 409 koncept, 403 `json` pro ne-admina,
  503 bez render služby, `pdf` hlavičky), háček detailu (akce jen
  u stavu 40, dropdown při víc tiscích — fake registr).

**Hotovo když:** v detailu faktury ve stavu V pořádku je Tisk,
náhled se otevře a Stáhnout uloží `faktura-<číslo>.pdf`; u konceptu
akce není; mobilní šířka použitelná.

### Commit 5 — Dokumentace a hlavička tasku

- Nový `docs/prints.md`: doména, deklarace, `PrintData` (kontrakt
  bloků dokladu), šablony a sandbox, filtry, překlady, REST, CLI,
  jak přidat tisk (krok za krokem pro kartu majetku jako příklad).
  Odkaz z `docs/README.md` a z `docs/reports.md` §1.
- `docs/cli.md` — `print-run`.
- Roadmapa M4 — řádek „Tisk / PDF vydaného dokladu“ aktualizovat.
- Hlavička tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu (potvrzená)

Zamčeno v #90: D1–D21. Upřesnění vzniklá při psaní PRD, potvrzená
2026-10-02 (zapsáno v #90):

- ✓ **`messages` v obálce** (doplnění D12). Builder hlásí měkké problémy
  (QR nevznikl — chybí účet) jako `messages: [{severity: "warning", code,
  text}]` po vzoru `ReportMessage`; náhled je ukáže nad PDF. Tvrdé chyby
  (chybí snapshot) dál výjimkou.
- ✓ **Jazyk v1 = výchozí jazyk DS.** Osoby jazyk nemají; D8 (jazyk
  partnera) se doplní v #94. `PrintLanguageResolver` je jediné místo
  změny. Katalogy `cs` i `en` hned, protože výchozí jazyk DS může být `en`.
- ✓ **Endpoint `/_prints/{printId}/{recordId}`** (upřesnění D20) —
  konvence `/_reports`, tabulku určuje deklarace.
- ✓ **Šablony:** společný layout, partials a katalog dokladů v
  `docs.core`; deklarace a tenká stránková šablona v typovém modulu
  (`docs.invoicesOut/invoice`, `docs.proformasOut/proforma`). Builder
  je společný.
- ✓ **QR knihovna `chillerlan/php-qrcode`** (čisté SVG bez GD/Imagick).

## Odchylky od zadání (implementace 2026-10-02)

- **`PrintTranslator` a katalogy už v commitu 2** (jádro, ne `Twig/`) —
  builder z katalogu bere titulek, název souboru a texty hlášení.
- **`ConfigRuntime` v jazyce tisku.** Runner dostává továrnu konfigurace per
  jazyk; jazyk requestu se pro popisky číselníků nepoužívá.
- **Záhlaví a zápatí se jmenují `header.html.twig` / `footer.html.twig` i ve
  sdíleném layoutu** (ne `doc-header` / `doc-footer`). Adresáře z `catalogs`
  jsou „sdílené adresáře tisku“: dávají katalog, assety i výchozí záhlaví
  a zápatí — jádro tak nemusí znát názvy layoutu dokladů.
- **Titulek, číslo dokladu a logo jsou v záhlaví** (na každé straně), ne
  v těle stránky; blok `title` v těle nese text dokladu.
- **`paper.margins`** v deklaraci — horní okraj musí pokrýt výšku záhlaví.
- **Blok `advances` není samostatný blok šablony** — odpočet záloh kreslí
  `totals.html.twig`.
- **Sandbox ve striktním režimu:** povolené jsou i funkce `parent`
  a výčet testů (`defined`, `null`, `empty`, …) — mimo striktní režim je
  Twig propouští mlčky, i když v politice nejsou.
- **QR platba:** symbol mimo 1–10 číslic se z kódu vynechá a builder přidá
  varování (schváleno před implementací).
- **`X-Print-Messages`:** měkká hlášení builderu nese PDF odpověď
  v hlavičce — binární tělo pro ně jiné místo nemá.
- **Chybový kód `PRINT_DATA_MISSING` (409)** pro doklad bez snapshotu —
  zadání ho mezi kódy nemělo.
- **`world.vat`:** kódy DPH dostaly `print:en`, poznámky `text:en`
  (schváleno před implementací).
- **`ViewerDetailModal` akce detailu nezobrazuje** — v čtecím modalu byly
  bez obsluhy mrtvé.
- **Nápověda** (`help/faktury-vydane/tisk-faktury.md` a opravy stránek,
  které tvrdily, že tisk nejde) šla s UI v commitu 4.
- **Fixture doklady** vkládá integrační test přímo se snapshoty z fixture
  souboru — ukázkový zdroj nemá žádnou fakturu ve stavu V pořádku.
