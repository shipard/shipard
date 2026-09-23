# Modul: docs.proformasOut

Modul pro **Zálohové faktury vydané** (`doc_type = 'invpo'`, zkratka FVZ).
Polymorfní subclass nad `docs.core`, téměř-kopie `docs.invoicesOut` (#79 D1).

## Účel

Zálohová faktura (proforma) odběrateli sděluje, kolik a kam má zaplatit
předem. My jsme dodavatel (snapshot supplier), partner je odběratel
(snapshot customer), `trade_dir = 1` — viz cfgItem `docs.core.docTypes`.

**Není daňový doklad** (`tax_document: false` v `docTypes.jsonc`): DPH se
počítá jen informativně (sazby na řádcích, rekapitulace, součet s DPH —
tak, jak ho zákazník čeká), ale doklad nemá DUZP ani DPPD a nevstupuje do
přiznání DPH, kontrolního ani souhrnného hlášení. To řídí atribut typu
čtený přes `DocTypes::isTaxDocument()` (docs.core) — `DocDocument` DUZP/DPPD
nuluje, economy.vat nuluje `vat_period` / `cs_period` / `rs_period` i ruční
hodnotu a `VatDocumentSelection` nedaňové typy vyřazuje. Tento modul o tom
nic neví; kód nikdy neporovnává `doc_type === 'invpo'`.

## Co modul přidává

- **Document třída** `ProformaOutDocument extends DocsHeadsDocument` —
  per-typ validace: `bank_account` povinný při Potvrzení (jako FVB).
- **Viewer** `ProformasOutViewer extends DocsHeadsViewer` — viewer v sekci
  Prodej (`navOrder` 15, mezi Fakturami vydanými a Prodejkami) s fixním
  filtrem `doc_type = 'invpo'` (`$scopedDocType`); `getNewRecordDefaults()`
  vrací `{doc_type: 'invpo'}`, takže formulář při „Přidat“ předvybere řadu
  typu invpo. Ikona `invoice-proforma` (frontend `icons.js`) — stejný klíč
  používá hlavička formuláře i detail (`DocsHeadsViewer::detailIconForDocType`).
- **Editační formulář** `ProformaOutForm extends IssuedInvoiceFormBase` —
  layout hlavičky a tab „Nastavení“ sdílí s FVB v `IssuedInvoiceFormBase`
  (docs.core); tady jen titulky („Zálohová faktura vydaná“ / „Nová zálohová
  faktura vydaná“) a header-info hooky. Base pro nedaňový typ skrývá DUZP
  a ruční zařazení do KH.
- **Polymorfní registrace** `documentClasses` + `forms` s `typeColumn`
  `doc_type` — merge s `docs.core` a ostatními per-typ moduly přes
  `DocumentLoader::mergeDocumentClasses` / `FormLoader::mergeForms`.
- **Pohyby řádků**: `sale.services` a `sale.goods` (v `docs.core`
  `rowOperations.jsonc`). Zálohové řádky, `acc.entry` ani zdanění záloh na
  proformě nedávají smysl. Pohyby účetně nic neznamenají — předpis `invpo`
  účtuje jen hlavičku.

## Co modul NEpřidává

- Žádné nové tabulky — doklady leží v `docs_core_heads`.
- Žádné nové cfgItems — typ je v `docs.core.docTypes`, číselnou řadu založí
  běžný `NumberSeriesProvisioner` při `ds-upgrade`.
- Účtování, osnovu a skupinu saldokonta — `tasks/accbal-proformas-out.md`
  (#79 D2, D3a), nasazuje se spolu s tímto modulem. **Do té doby** skončí
  potvrzená proforma s hláškou `rules_not_found` (stav účtování „chyba“
  a alert `AccountingErrorsCheck`) — očekávaný přechodný stav.
- Tisk, fakturu z proformy, daňový doklad k záloze, storno, `invpi`
  (přijatá proforma) ani import ze starého Shipardu (#79 D4–D7).

## Vztah k `docs.invoicesOut`

Faktura vydaná (`invno`) je daňový doklad se stejným layoutem formuláře.
Oba moduly mají stejnou strukturu (Document, Form, Viewer, registrace);
liší se v `doc_type`, titulcích a v tom, že proforma nemá DUZP a období DPH.
