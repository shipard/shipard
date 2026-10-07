# Modul: docs.proformasIn

Modul pro **Zálohové faktury přijaté** (`doc_type = 'invpi'`, zkratka FPZ).
Polymorfní subclass nad `docs.core`, zrcadlo `docs.proformasOut` na vstupní
straně a téměř-kopie `docs.invoicesIn` (#106 D1).

## Účel

Zálohová faktura přijatá (výzva k platbě) od dodavatele říká, kolik a kam
máme zaplatit předem. My jsme odběratel (snapshot customer), partner je
dodavatel (snapshot supplier), `trade_dir = 2` — viz cfgItem
`docs.core.docTypes`. Starý Shipard měl typ `prfmin` (kód 22, FPZ).

**Není daňový doklad** (`tax_document: false` v `docTypes.jsonc`): DPH se
počítá jen informativně (sazby na řádcích, rekapitulace, součet s DPH —
tak, jak ho dodavatel na výzvě uvádí), ale doklad nemá DUZP ani DPPD
a nevstupuje do přiznání DPH ani kontrolního hlášení. To řídí atribut typu
čtený přes `DocTypes::isTaxDocument()` (docs.core) — `DocDocument` DUZP/DPPD
nuluje, economy.vat nuluje `vat_period` / `cs_period` / `rs_period` i ruční
hodnotu a `VatDocumentSelection` nedaňové typy vyřazuje. Tento modul o tom
nic neví; kód nikdy neporovnává `doc_type === 'invpi'`.

## Co modul přidává

- **Document třída** `ProformaInDocument extends ReceivedInvoiceDocumentBase`
  (docs.core) — dědí doporučení bankovního spojení dodavatele při Potvrzení
  (warning `partner_bank_recommended`, uložení projde), nic navíc.
- **Viewer** `ProformasInViewer extends DocsHeadsViewer` — viewer v sekci
  Nákup (`navOrder` 15, za Fakturami přijatými) s fixním filtrem
  `doc_type = 'invpi'` (`$scopedDocType`); `getNewRecordDefaults()` vrací
  `{doc_type: 'invpi'}`, takže formulář při „Přidat“ předvybere řadu typu
  invpi. Ikona `invoice-proforma-in` (frontend `icons.js`) — stejný klíč
  používá hlavička formuláře i detail (`DocsHeadsViewer::detailIconForDocType`).
- **Editační formulář** `ProformaInForm extends ReceivedInvoiceFormBase` —
  layout hlavičky a tab „Nastavení“ sdílí s FPB v `ReceivedInvoiceFormBase`
  (docs.core); tady jen titulky („Zálohová faktura přijatá“ / „Nová zálohová
  faktura přijatá“) a header-info hooky. Base pro nedaňový typ skrývá DUZP,
  DPPD a ruční zařazení do KH.
- **Polymorfní registrace** `documentClasses` + `forms` s `typeColumn`
  `doc_type` — merge s `docs.core` a ostatními per-typ moduly přes
  `DocumentLoader::mergeDocumentClasses` / `FormLoader::mergeForms`.
- **Pohyby řádků**: `purchase.goods`, `purchase.services` a `purchase.other`
  (v `docs.core` `rowOperations.jsonc`). Odpočet zálohy, zdanění zálohy,
  majetek ani `acc.entry` na výzvě nedávají smysl. Pohyby účetně nic
  neznamenají — předpis `invpi` účtuje jen hlavičku.

## Co modul NEpřidává

- Žádné nové tabulky — doklady leží v `docs_core_heads`.
- Žádné nové cfgItems — typ je v `docs.core.docTypes`, číselnou řadu založí
  běžný `NumberSeriesProvisioner` při `ds-upgrade`.
- Žádný tisk — přijatý doklad se netiskne.
- Účtování, osnovu a skupinu saldokonta — `economy.accounting`
  (`accountingRules.cz.jsonc`, předpis `799100 MD / 757100 DAL`)
  a `economy.accbal` (skupina `proformas_in`, #106 D2), nasazují se spolu
  s tímto modulem. Úhradu výzvy (bankovní výdaj nebo pokladní
  `advance.given` s VS výzvy) účtuje engine na poskytnutou zálohu 314
  a `CaseClosureContributor` výzvu na podrozvaze uzavře.
- Výzvu k platbě z došlé pošty (#106 D4), hlídání storna uhrazené výzvy
  (#106 D3), odpočet zálohy na konečné faktuře jinak než ručním řádkem
  a daňový doklad k poskytnuté záloze (#107), import ze starého Shipardu
  (typ `prfmin` se v importovaných zdrojích nepoužívá).

## Vztah k `docs.invoicesIn`

Faktura přijatá (`invni`) je daňový doklad se stejným layoutem formuláře.
Oba moduly mají stejnou strukturu (Document, Form, Viewer, registrace);
liší se v `doc_type`, titulcích a v tom, že výzva nemá DUZP, DPPD ani
období DPH. Sdílené báze `ReceivedInvoiceFormBase` a
`ReceivedInvoiceDocumentBase` žijí v `docs.core`.
