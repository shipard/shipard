# Task: Import starých podání DPH — `POST /_vat/filing-import`, `origin`, override podaných hodnot z XML (#55 D21, D32–D39)

**Stav:** k implementaci — 2026-09-13. Protějšek: `old_shipard`
`modules/imports/newShipard/tasks/37-vat-filings-import.md` (runner). Nasazení:
nejdřív tato strana (`ds-upgrade`: sloupec `origin`), pak runner; ověření
`ds-reset` + reimport `btpg-p` (D39).
**Issue:** #55 — komentář „Import starých podání (D21) — rozhodnutí D32–D39 (2026-09-13)".

## Kontext

Podání dnes vznikají jen composerem nad živými doklady (F2), XML se generuje při
přechodu do 40 (F3 X6), účtují se akcí (F4b). Starý Shipard má za zdroj 689089
~380 podaných podání 2016–2026 (DP3 / KH / SH) s původními XML a PDF jako
přílohami a jeden `accDocument` na instanci. Bez jejich importu nemá dodatečné
podání za období podané ve starém systému proti čemu diffovat, kontrola zůstatků
343 (F4a/F4b) nemá `acc_document` a zamčené instance nemají v UI žádné podání.

Klíčová rozhodnutí: **snapshot = composer nad dnešními doklady**, podané
hodnoty DP3 z původního XML (D33); **`origin = imported`** (D34); soubory =
původní přílohy, žádné generování (D34/D36); `acc_document` na poslední podané
podání instance (D37).

## Návaznost

- `tasks/vat-filings.md` (F2: snapshot, `FilingDocument`, `FROZEN_COLUMNS`,
  `sequence`/`previous_filing`, D18 pořadí druhů), `tasks/vat-filing-xml.md`
  (F3: `VatXmlMapping`, `EpoXmlDiff`, X6 generování při 40, `FilingAttachmentGuard`),
  `tasks/vat-period-lock.md` (F4a: `ClosedPeriodBalanceService`),
  `tasks/vat-filing-accounting.md` (F4b: `acc_document`, `messages` smí měnit jen
  uložení s `acc_document`).
- `old_shipard` task 30 (instance), 36 (zámek, správce daně), 37 (tento import).

## Před implementací přečti

- `modules/economy/vat/src/FilingDocument.php` — `validate()` (ř. 300–330:
  `not_composed`, `validateXml` při 40), `validateOrder()` (ř. 455–494),
  `FROZEN_COLUMNS`, hook generování souborů (ř. 365–380).
- `modules/economy/vat/src/FilingComposer.php` — `compose()`, `writeReturnRows()`,
  `cumulativeFiledRows()`, `buildHeader()`/`prefillHeader()`, tvar `messages`.
- `modules/economy/vat/config/vat-xml-cz.jsonc` — `return.rows` (řádek → věta /
  atribut base/full/reduced), hlavička (`vetaDFields`, VetaP), KH sekce, SH.
- `modules/economy/vat/src/Xml/EpoXmlDiff.php` (normalizace hodnot),
  `FilingXmlInputLoader.php`, `FilingHeaderResolver.php`.
- `modules/economy/vat/src/VatFilingController.php` — vzor endpointů
  (`filing-account`, `registration-tax-office`), `src/Api/Router.php` prefix `/_vat/`.
- `src/Api/Controller/AttachmentController.php` `upload` (`table_id`, `record_id`,
  multipart `file`), `modules/economy/vat/src/FilingAttachmentGuard.php`.
- `modules/economy/vat/src/FilingsViewer.php` (detail, akce), `docs/vat-filings.md`
  (nebo README modulu — kde je popis snapshotu).

## Scope

### 1. Sloupec `origin` na `economy_vat_filings` (D34)

- `origin` enumString(10), not null, default `composed`, cfgItem
  `economy.vat.filingOrigins` (`composed` „Sestaveno", `imported` „Importováno").
  Do `FROZEN_COLUMNS`. `.md` tabulky, `ds-upgrade`.
- `FilingDocument`: pro `origin = imported`
  - přechod do 40 **nevolá** `validateXml()` ani `generateFiles()` — původní
    soubory jsou pravda; chybějící XML se projeví jen zprávou (§3);
  - `validateOrder()`: pravidlo „řádné jen jedno / navazující až po podaném" se
    pro import nevynucuje jako chyba, ale zapíše `imported_order_irregular`
    (starý report mohl vzniknout až po ručně podaném řádném); `draft_exists`
    zůstává chybou — import do instance s živým konceptem se odmítne (§2 vrací
    422 `DRAFT_EXISTS`, runner hlásí; řešení = zrušit koncept nebo `ds-reset`);
  - `messages` smí měnit i uložení s `origin = imported` v importní transakci
    (rozšíření výjimky z F4b), po přechodu do 40 platí F2/F4b pravidla beze změny.
- Akce „Přepočítat snapshot" a „Vytvořit soubory" se pro `imported` nenabízejí;
  „Zaúčtovat" ano (D37 může FK nechat prázdné a uživatel ho doplní akcí);
  „Stáhnout XML" bere přílohu.

### 2. `POST /_vat/filing-import` (D38)

Body:

```json
{
  "reportPeriodId": 123,
  "reportType": "return",
  "filingKind": "regular",
  "name": "Přiznání DPH 2019/8",
  "dateIssue": "2019-09-20", "dateFiled": "2019-09-22", "dateFound": null,
  "accDocumentId": 36950,
  "xml": "<?xml …>",
  "legacy": {"filingNdx": 812, "reportNdx": 140}
}
```

Jedna transakce, třída `FilingImportService` (`modules/economy/vat/src/Import/`):

1. Validace: instance existuje a `report_type` sedí; `filingKind` z
   `economy.vat.filingKinds`; `accDocumentId` (nullable) je `docs_core_heads`
   typu `cmnbkp` mimo 30/90 — jinak `warning` `imported_acc_document_missing`
   a FK prázdné (D37).
2. Založí podání přes `TableGateway::saveDocument` ve stavu 10 s
   `origin = imported`, `name`, `date_issue`, `date_found`, `previous_filing` =
   poslední podané podání instance (i importované — pořadí drží runner podle
   starého `ndx`), `sequence` přidělí Document.
3. `FilingComposer::compose()` — položky a řádky nad dnešními doklady
   (dodatečné jako diff proti kumulativnímu stavu, který už tvoří dřívější
   importovaná podání).
4. **Override podaných hodnot (jen `return`, jen je-li `xml`):**
   `Import/Dp3XmlReader` — parse XML, přes `VatXmlMapping::rows()` obráceně
   (věta+atribut → řádek, sloupec base/full/reduced), hodnoty normalizovat jako
   `EpoXmlDiff` (číslo, chybějící = 0). Pro každý řádek mapování:
   `*_filed` := hodnota z XML; liší-li se od dosavadní podané (= zaokrouhlené
   přesné z D17) → `messages[] = {code: imported_row_mismatch, row, composed,
   filed}`. Řádky v XML mimo mapování (nové řádky formuláře, které starý
   systém neznal, jsou naopak prázdné) → `imported_row_unmapped`. Hlavička:
   `prefillHeader()` z profilu, pak přepsat atributy VetaD/VetaP z XML, které
   `vetaDFields`/schema zná (`dic`, `c_ufo`, `c_pracufo`, `typ_ds`, jména…);
   `d_zjist` → `date_found`, `dapdph_forma` musí odpovídat `filingKind`
   (nesoulad = `imported_kind_mismatch`, řídí runner, ne XML).
   KH (`DPHKH1`) a SH (`DPHSHV`): porovnat řádky composeru s XML
   (`Import/EpoXmlLineComparer`, klíč = sekce + ev. číslo + DIČ + kód) a
   zapsat jen `imported_line_mismatch` {section, key, field, composed, filed}
   s počty; řádky se nepřepisují. Bez `xml` → `imported_without_xml`.
5. Dva endpointy, protože přílohy jsou multipart a `FilingAttachmentGuard` je
   po 40 zamyká: **`filing-import`** = kroky 1–4 + `acc_document` (D37), podání
   zůstane v 10 s `origin = imported`; runner nahraje přílohy přes
   `POST /_attachments/upload` (`table_id 443`, `record_id = filingId`);
   **`POST /_vat/filing-import-finish {filingId}`** = přechod do 40 s
   `date_filed` (Document podle §1 nevaliduje XML ani negeneruje soubory),
   doplní `imported_files: n` z `core_attachments_files`; idempotentní
   (už 40 → no-op), runner ho volá vždy (i bez příloh). Koncept `imported` bez
   `finish` (přerušený běh) najde runner při re-runu podle `legacy.filingNdx`
   v `messages` a dokončí.
6. Odpověď `filing-import`: `{filingId, sequence, messages: {mismatchRows: n,
   lineMismatches: n, flags: [...]}}`; `finish`: `{filingId, docState, files}`.
7. Chyby: `PERIOD_NOT_FOUND` 404, `DRAFT_EXISTS` 422, `INVALID_KIND` 422,
   `XML_UNREADABLE` 422 (XML se nedá parsovat — runner pošle bez `xml` a
   ohlásí); cokoli po založení = rollback celé transakce kroku.

### 3. CLI

- `shpd-ds vat-filing-import --period=<id> --type=return --kind=regular
  --xml=<file> [--acc-document=<id>] [--date-filed=…]` — obálka nad službou pro
  ruční doplnění jednoho starého podání (papírové / jiný systém). Volitelné,
  malé; `--dry-run` = compose do paměti + porovnání s XML bez zápisu
  (užitečné i pro D39 nad `btpg-p` bez runneru).
- `vat-filing-xml-diff` beze změny — D39 ho pouští nad importovanými podáními
  (přiložené XML vs. writer nad snapshotem).

### 4. UI (`FilingsViewer`)

- Řádek: čip „Import" u `origin = imported`; detail: „Importováno ze starého
  Shipardu", počet `imported_row_mismatch` / `imported_line_mismatch` s
  rozbalením (řádek, sestaveno, podáno), zprávy `imported_*` v sekci Zprávy.
- Akce dle §1. Přílohy: standardní panel (guard je zamyká jako u podaných).

### 5. Kontroly a alerty

- `ClosedPeriodBalanceService` beze změny — po importu počítá `acc_document`
  importovaných podání; očekávaný výsledek na `btpg-p` viz D39.
- Nový nález `economy.vat.imported_filing_mismatch`? **Ne** — rozdíly jsou
  historický záznam v `messages`, ne stav k řešení; UI je ukáže na podání.

### 6. Testy

- `FilingImportServiceTest` (integrační, 4l3j): řádné DP3 s XML → 40,
  `origin = imported`, `*_filed` z XML, `imported_row_mismatch` tam, kde se
  liší; bez XML → `imported_without_xml` + D17 hodnoty; dodatečné po
  importovaném řádném → diff proti kumulativnímu stavu; KH s XML →
  `imported_line_mismatch`, řádky nepřepsané; `DRAFT_EXISTS`; `acc_document`
  chybí → warning; `finish` idempotentní; přechod do 40 nevytvořil přílohy.
- `Dp3XmlReaderTest` (unit): fixture XML → řádky (`210` = `210.00`, chybějící
  atribut = 0, `dapdph_forma`, `d_zjist`, VetaP).
- `FilingDocumentTest` +: `imported` přeskočí `validateXml`, `imported_order_irregular`
  místo chyby, `origin` zmrazený.
- `HelpDriftTest`, `ReadOnlyPolicyTest` beze změny (žádné nové read-only edity).

### 7. Dokumentace

README modulu vat (sekce Import starých podání + `origin`),
`tables/economy_vat_filings.md`, `docs/cli.md` (`vat-filing-import`), help
`dph-podani.md` („Importovaná podání" — co jde a nejde), `docs/exchange` jen
odkazem (není to exchange formát, je to dedikovaný endpoint).

## Mimo scope

- Import rozpracovaných starých podání (1000/8000), OSS (neexistuje v praxi).
- Přepis KH/SH řádků podle XML (jen porovnání, D33).
- Zpětné generování nového XML k importovanému podání (původní je pravda).
- Mapa zámků, importér pro jiné než CZ písemnosti.

## Commity

1. `origin` + `FilingDocument` (validace, potlačení X6, `imported_order_irregular`) + testy.
2. `Dp3XmlReader` + `EpoXmlLineComparer` + unit testy.
3. `FilingImportService` + endpointy `filing-import` / `filing-import-finish` + integrační testy.
4. CLI `vat-filing-import` + UI (čip, detail, akce) + docs + help.

## Hotovo když

- [ ] `ds-upgrade` na 4l3j i `btpg-p` projde (`origin`).
- [ ] Testy §6 zelené (úzké `--filter`).
- [ ] 4l3j: ruční `vat-filing-import` řádného DP3 s fixture XML → podání 40,
      `origin = imported`, přílohy nahrané před `finish` zůstaly, žádné nové
      soubory, detail ukazuje rozdíly.
- [ ] **D39 na `btpg-p` po reimportu (`old_shipard` task 37):** všechna podaná
      stará podání importována (počty per typ = souhrn runneru); podání s
      `imported_row_mismatch` vypsána (očekávány jednotky, 08/2019 mezi nimi);
      `closed_period_balance` po `alerts-run --check` = jen 08/2019;
      `filed_unlocked_periods` prázdné; `vat-filing-xml-diff` původní XML vs.
      writer nad snapshotem pro všechna DP3 — počet rozdílů = počet
      `imported_row_mismatch` (nic navíc).
- [ ] Dodatečné podání založené nad `btpg-p` za instanci s importovaným řádným
      se sestaví jako diff proti importovaným podaným hodnotám.

## Odchylky od zadání

(doplní implementace)
