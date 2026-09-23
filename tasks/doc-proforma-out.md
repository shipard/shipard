# Zálohová faktura vydaná — typ dokladu `invpo`

**Stav:** naplánováno — zadání 2026-09-23 (#79 D1)
**Issue:** #79 (rozhodnutí D1–D7 v komentáři 2026-09-23 „Revize rozhodnutí“);
souvisí s #69 (saldokonto) a #72.
**Milník:** M2 — bez proforem se saldokonto importovaného DS neporovná se
starým systémem (neuhrazené proformy ve starém saldu chybí v novém).
**Návaznost:** `tasks/accbal-proformas-out.md` (osnova, účtovací předpis,
skupina saldokonta — #79 D2, D3a) **se nasazuje spolu s tímto taskem**:
bez předpisu skončí potvrzená proforma s `rules_not_found`. Dál navazují
`JournalContributor` + uzavírání proforem (#79 D3b/c), faktura z proformy
(D7), import v `old_shipard` (D4), storno a uzavření zbytku (D5) — samostatné
tasky.

## Cíl

Nový typ dokladu **Zálohová faktura vydaná** (`invpo`, zkratka FVZ):
odběrateli sděluje, kolik a kam má zaplatit předem. **Není daňový doklad**:
DPH se na ní počítá jen informativně (cena s DPH po sazbách, jak ji zákazník
čeká), ale doklad nemá DUZP ani DPPD a nevstupuje do přiznání DPH,
kontrolního ani souhrnného hlášení. Účtuje se až v navazujícím tasku, na
podrozvahu.

Starý Shipard: typ `invpo` („Faktura vydaná zálohová“, zkratka FVZ,
`docIdCode` 12, vzorec `%D%y%C%4`, `taxDocument: 0`), řádky s pohyby Prodej
služeb / Prodej zásob. Proformy se tam neúčtovaly — do salda šly natvrdo
podle typu dokladu. Nový Shipard je bude účtovat (#79 D2).

## Před implementací přečti

- `CLAUDE.md` — Editační formuláře — polymorfismus per typ (vzor
  `docs.invoicesOut`), Dokumentový systém
- `docs/document-system.md` (hooky, polymorfní Document třídy),
  `docs/edit-forms.md` kap. 23
- `docs/accounting.md` §2 (rowOperations)
- `docs/vat-report-periods.md` (přiřazení dokladu do instancí tvrzení —
  `DocsHeadsVatPeriodHandler`, `VatPeriodAssigner`)
- `docs/exchange-format.md` §5 (kanonické typy dokladů)
- `docs/documentation.md`, `docs/help-authoring.md`
- `modules/docs/invoicesOut/` celý (`module.jsonc`, tři třídy, `README.md`)
  — vzor, nový modul je jeho téměř-kopie
- `modules/docs/core/config/docTypes.jsonc` (hlavičkový komentář atributů),
  `rowOperations.jsonc`, `applyRowOperations.jsonc`
- `modules/docs/core/src/DocDocument.php` (`applyDateDefaults`,
  `validateBindingAndDirection` — vzor čtení atributů typu z cfg),
  `DocsHeadsFormBase.php` (defaulty `vat_duzp` z `issue_date` — dvě místa,
  pole `vat_duzp`/`vat_dppd` s `hidden: !$hasVat`, selecty
  `vat_period`/`cs_period`/`rs_period`), `DocsHeadsViewer.php`
  (`detailIconForDocType`)
- `modules/economy/vat/src/DocsHeadsVatPeriodHandler.php`,
  `VatDocumentSelection.php`
- `modules/core/exchange/src/Document/DocumentApplier.php` (`DOC_TYPE_MAP`),
  `modules/core/exchange/src/Export/DocumentExporter.php` (mapa typů,
  role snapshotů stran)
- `modules/install/base/module.jsonc` (instalační sada — kde je
  `docs.invoicesOut`)

## Rozhodnutí (#79)

- **D1** Typ `invpo`; `invpi` (přijatá) zatím ne. Řádky standardní prodejní,
  DPH jen informativně, bez `vat_period`/`cs_period`/`rs_period`.
- D2–D7 řeší navazující tasky (viz Návaznost).

## Scope

### 1. Typ dokladu a atribut `tax_document`

`docTypes.jsonc` (a úvodní komentář souboru — výčet typů):

```jsonc
// Zálohová faktura vydaná (#79 D1): pevně výstup, není daňový doklad
// (tax_document: false) — DPH jen informativně, bez DUZP a bez zařazení
// do tvrzení DPH. Účtuje se na podrozvahu (accountingRules, #79 D2).
"invpo": {
    "name": "Issued proforma invoice",
    "name:cs": "Zálohová faktura vydaná",
    "name:en": "Issued proforma invoice",
    "shortcut": "FVZ",
    "shortcut:cs": "FVZ",
    "shortcut:en": "PI",
    "doc_id_code": "12",
    "trade_dir": 1,
    "tax_document": false,
    "doc_number_pattern_default": "%D%y%C%4",
    "subclass": "Shipard\\Module\\Docs\\ProformasOut\\ProformaOutDocument"
}
```

Nový atribut **`tax_document`** zdokumentovat v hlavičkovém komentáři
souboru: `false` = doklad není daňový; chybí = `true` (všechny dosavadní
typy beze změny). Čtení atributu **na jednom místě** — statický helper
v `docs.core` (např. `DocTypes::isTaxDocument(?ConfigRuntime, string $docType): bool`,
vzor čtení v `DocDocument::validateBindingAndDirection`); všechna místa
níže volají jej, žádné porovnání `=== 'invpo'` v kódu.

Číselnou řadu založí běžný `NumberSeriesProvisioner` (nevázaný typ) — ověř,
že bez dalšího zásahu vznikne default řada pro `invpo` při `ds-upgrade`.

### 2. Nedaňový doklad — DUZP, DPPD, období DPH

Pro typ s `tax_document: false`:

- `DocDocument::applyDateDefaults` — `vat_duzp` a `vat_dppd` se
  **nedoplňují** a v `beforeSave` se vynulují (i kdyby přišly v payloadu
  nebo z importu). `accounting_date` a `due_date` beze změny.
- `DocsHeadsFormBase` — defaulty `vat_duzp` z `issue_date` (obě místa) se
  pro takový typ nepoužijí; pole `vat_duzp`, `vat_dppd` a selecty
  `vat_period`/`cs_period`/`rs_period` jsou skrytá. Rekapitulace DPH,
  sazby na řádcích a součty s DPH zůstávají viditelné (`$hasVat` se
  nemění).
- `DocsHeadsVatPeriodHandler::onBeforeSave` — pro nedaňový typ nastaví
  všechna tři období na `null`, **i ruční hodnotu** z payloadu (doklad do
  tvrzení nepatří, výjimka není).
- `VatDocumentSelection::load` — pojistka: vyřadí doklady typů
  s `tax_document: false` (i kdyby na nich období historicky viselo).
  Seznam nedaňových typů odvodit z cfg přes helper z bodu 1, ne natvrdo
  v SQL.
- Rekapitulace DPH (`docs_core_vat_recap`) se počítá a ukládá jako
  u faktury — potřebuje ji budoucí tisk i faktura z proformy.

### 3. Modul `docs.proformasOut`

Nový modul `modules/docs/proformasOut/` podle `docs.invoicesOut`:

- `module.jsonc` — `id: docs.proformasOut`, závislost `docs.core`;
  viewer `docs.proformasOut.heads` „Zálohové faktury vydané“,
  `navSection: "sales"`, `navOrder: 15` (mezi Fakturami vydanými 10
  a Prodejkami 20); `documentClasses` a `forms` s `typeColumn: doc_type`,
  `invpo → …`. Přidat do instalační sady vedle `docs.invoicesOut`
  (`modules/install/base/module.jsonc`).
- `ProformaOutDocument extends DocsHeadsDocument` — `bank_account` povinný
  ve stavech 40/80 (jako `IssuedInvoiceDocument`; proforma je hlavně
  výzva k platbě). Nic dalšího.
- `ProformaOutForm extends DocsHeadsFormBase` — titulky „Zálohová faktura
  vydaná“ / „Nová zálohová faktura vydaná“ (+ en); `buildExtraTabs` jako
  `IssuedInvoiceForm` (Měna, Ostatní: `bank_account`, `vat_registration`,
  `vat_calc_source`, `total_rounding_mode`, `vat_rounding_mode`,
  `constant_symbol`). Pokud by kopie byla doslovná, vytáhni společnou
  část do base / traitu místo duplikace.
- `ProformasOutViewer extends DocsHeadsViewer` — `$scopedDocType = 'invpo'`.
- Ikona: nový klíč `invoice-proforma` v `frontend/src/icons.js` (import +
  export + `iconMap`, FA ikona dle uvážení, odlišná od `invoice-out`),
  použitý ve vieweru i v `DocsHeadsViewer::detailIconForDocType`.
- `README.md` modulu (vzor `docs.invoicesOut/README.md`): co přidává, co ne,
  vztah k `docs.invoicesOut`, nedaňový charakter, odkaz na #79.

### 4. Pohyby řádků

`rowOperations.jsonc`: `sale.services` → `"invpo": {"order": 100}`,
`sale.goods` → `"invpo": {"order": 200}`. Žádné další (zálohové řádky,
`acc.entry` ani zdanění záloh na proformě nedávají smysl). Pohyby na
proformě účetně nic neznamenají — předpis `invpo` (navazující task) účtuje
jen hlavičku. `applyRowOperations.jsonc` bez záznamu (AI apply proformy
netvoří). `ApplyRowOperationsParityTest` a `OperationSidesTest` musí projít.

### 5. Výměnný formát

- `DocumentApplier::DOC_TYPE_MAP`: `'proformaIssued' => 'invpo'`.
- `DocumentExporter`: `'invpo' => 'proformaIssued'`, role stran
  `'invpo' => ['supplier', 'customer']` (jako `invno`).
- `docs/exchange-format.md` §5: nový kanonický typ; `vatDuzp`/`vatDppd`
  se u něj ignorují a doklad nenese období DPH.
- Import mód: proforma z importu projde s `_importNumber` stejně jako
  faktura — test vzorem `DocumentApplierTest` (`invoiceIssued`).

### 6. Dokumentace

- `docs/document-system.md` nebo `docs/docs-mvp.md` — kde je výčet typů
  dokladů, doplnit `invpo` a atribut `tax_document`.
- `CLAUDE.md` → Editační formuláře — polymorfismus per typ: jedna věta
  o `docs.proformasOut` (`invpo → ProformaOutForm`) a o `tax_document`.
- `docs/vat-report-periods.md` — nedaňové typy do instancí nepatří.
- `help/faktury-vydane/zalohova-faktura.md` — nová stránka „Zálohová
  faktura“ (šablona a front matter dle `docs/help-authoring.md`): k čemu
  je, že není daňový doklad, jak ji vystavit, že úhradu páruje saldokonto.
  Názvy sekcí a tlačítek ověř v `module.jsonc` a `cs.js`.
- `help/co-dnes-nejde.md` — proformu nevytiskneš (stejně jako fakturu);
  fakturu z proformy zatím nevystavíš jedním klikem (odečet zálohy zadat
  ručně řádkem „Odpočet přijaté zálohy“ s VS proformy); daňový doklad
  k přijaté záloze zatím neexistuje.
- `python3 scripts/help-index.py`.

## Mimo scope

- Účtování, osnova, saldokonto — `tasks/accbal-proformas-out.md`.
- Tisk (tisk dokladů v novém Shipardu zatím neexistuje ani u faktur).
- Faktura z proformy, daňový doklad k záloze, storno a uzavření zbytku,
  upomínky, `invpi`, import ze starého Shipardu.

## Testy

- Unit: helper `tax_document` (chybí → true, `false` → false).
- `DocDocument` / `DocsHeadsDocument`: `invpo` po `beforeSave` nemá
  `vat_duzp`/`vat_dppd` ani s hodnotou v payloadu; rekapitulace DPH se
  spočítá; `invno` beze změny (regrese).
- `DocsHeadsVatPeriodHandler`: `invpo` → tři období `null` i s ruční
  hodnotou; `invno` beze změny.
- `VatDocumentSelection`: doklad `invpo` s (historicky) vyplněným
  `vat_period` se nevybere.
- `ProformaOutDocument`: chybí `bank_account` ve stavu 40 → chyba
  `required`.
- `FormRegistry` dispatch `invpo → ProformaOutForm`, titulky; pole DUZP
  a období skrytá.
- Applier: `proformaIssued` v import módu vytvoří `invpo` bez DUZP.
- Parity testy rowOperations / applyRowOperations zelené.

Filtrem, např.
`vendor/bin/phpunit --filter 'Proforma|VatPeriodHandler|VatDocumentSelection|DocTypes|ApplyRowOperationsParity|OperationSides'`;
celá sada na konci lokálně.

## Commity

1. `docs: typ dokladu invpo, atribut tax_document, modul docs.proformasOut (#79 D1, 1/4)`
   — docTypes, helper, modul (Document, Form, Viewer, ikona), rowOperations.
2. `docs+vat: nedaňový doklad bez DUZP a období DPH (#79 D1, 2/4)` — bod 2 + testy.
3. `exchange: kanonický typ proformaIssued (#79 D1, 3/4)` — bod 5 + testy.
4. `docs+help: zálohová faktura vydaná; task (#79 D1, 4/4)` — dokumentace,
   help, `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] `ds-upgrade` na dev DS založí řadu `invpo`; v sidebaru Prodej je
      „Zálohové faktury vydané“ s vlastní ikonou.
- [ ] Nová proforma se sazbami DPH: rekapitulace a součet s DPH sedí,
      DUZP/DPPD ani období DPH ve formuláři nejsou a v DB jsou `NULL`.
- [ ] Potvrzení bez bankovního účtu hlásí chybu u pole.
- [ ] Report DPH / podání za období proformy ji neobsahuje.
- [ ] Faktury vydané (`invno`) se chovají beze změny — DUZP, období, testy.
- [ ] Dokumentace a help aktualizované, `help-index.py` a
      `tasks-index.py --check` projdou.
- [ ] Nasazení na alfu až spolu s `tasks/accbal-proformas-out.md`.
