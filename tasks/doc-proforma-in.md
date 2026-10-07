# Zálohová faktura přijatá — typ dokladu `invpi`, podrozvaha, saldokonto

**Stav:** hotovo — implementace 2026-10-07 (4 commity), ověřeno na 4l3j; zbývá nasazení na alfu
**Issue:** #106 (D1, D2). Souvisí s #79 (vydaná strana, D1–D3), #105 D8
(výzvy k platbě v poště) a #107 (příjem dokladů a saldokonto).
**Milník:** M2 — přijatý doklad až do spárované úhrady; výzvy k platbě
z pošty (#105 D8) potřebují cílový typ dokladu.
**Návaznost:** zrcadlo tří tasků vydané strany: `tasks/doc-proforma-out.md`,
`tasks/accbal-proformas-out.md`, `tasks/accbal-proforma-closure.md`.
Enginy, lookup, contributor a reroute handlery jsou obecné nad nastavením
skupin saldokonta. Tento task jim jen dodá novou skupinu a nový kód do nich
nepřidává. Navazují samostatné tasky: hlídání storna proforem (#106 D3)
a pošta (#106 D4, až po #105). Odpočet zálohy na konečné faktuře řeší #107.

## Cíl

Uživatel ručně pořídí **zálohovou fakturu přijatou** (`invpi`, FPZ), tedy
výzvu dodavatele k platbě předem. **Není to daňový doklad:** DPH je na ní
jen informativně, doklad nemá DUZP, DPPD ani období DPH. Potvrzená výzva se
zaúčtuje na podrozvahu `799100 MD / 757100 DAL`. Saldokonto ji ukáže ve
skupině **Zálohové faktury přijaté** jako otevřenou položku k úhradě.

Bankovní výdaj nebo pokladní `advance.given` s partnerem a VS výzvy se
zaúčtuje jako poskytnutá záloha `314 MD / 221 DAL`. Stávající
`CaseClosureContributor` přidá `757100 MD / 799100 DAL` a případ výzvy tím
uzavře. Konečnou fakturu přijatou s ručním řádkem „Odpočet poskytnuté
zálohy“ (VS a SS výzvy) uzavře zálohu 314 jako dnes.

Starý Shipard má typ `prfmin` (Zálohová faktura přijatá, FPZ, kód 22),
v importovaných zdrojích se ale nepoužívá. Import se proto tohoto tasku
netýká.

## Před implementací přečti

- `CLAUDE.md` — Editační formuláře: polymorfismus per typ (hierarchie
  `IssuedInvoiceFormBase`) a odstavec Nedaňový typ dokladu
- `tasks/doc-proforma-out.md`, `tasks/accbal-proformas-out.md`,
  `tasks/accbal-proforma-closure.md` včetně sekcí „Poznámky z implementace“
  — vzory, tenhle task je jejich zrcadlo
- `docs/accounting.md` §4 (Zálohová faktura vydaná — podrozvaha), §5
- `docs/accbal.md` §3.1, §5.1 (přirozená / opačná skupina, výdaj), §5.2,
  §5.8 (uzavření případu úhradou mimo skupinu)
- `docs/bank.md` §6.1
- `docs/exchange-format.md` §5, `docs/help-authoring.md`
- `modules/docs/proformasOut/` a `modules/docs/invoicesIn/` celé
- `modules/docs/core/src/IssuedInvoiceFormBase.php` (vzor báze),
  `DocsHeadsFormBase::isTaxDocument`, `DocsHeadsViewer::detailIconForDocType`
- `modules/docs/core/config/docTypes.jsonc`, `rowOperations.jsonc`,
  `applyRowOperations.jsonc`
- `modules/economy/accounting/config/accountingRules.cz.jsonc` (`invpo`,
  `invni`), `accountChartDefault.jsonc` a `accountChartNpo.jsonc` (třída 7),
  `src/OffBalanceAccountsProvisioner.php`
- `modules/economy/accbal/config/balancesDefault.cz.jsonc`,
  `src/LedgerOpenItemLookup.php`, `src/CaseClosureContributor.php`,
  `src/ClearingRerouteHandler.php`, `src/CaseClosureRerouteHandler.php`
- `modules/core/exchange/src/Document/DocumentApplier.php` (`DOC_TYPE_MAP`),
  `DocumentValidator.php` (`checkPerDocType`, `checkPartnerDocNumber`),
  `Export/DocumentExporter.php`

## Rozhodnutí (#106)

- **D1** Typ `invpi` „Zálohová faktura přijatá“, FPZ, `doc_id_code` 22,
  `trade_dir` 2, `tax_document: false`. Modul `docs.proformasIn`, viewer
  v Nákupu za Fakturami přijatými. Formulář má layout faktury přijaté přes
  společnou bázi `ReceivedInvoiceFormBase` v `docs.core` a nemá DUZP, DPPD,
  období DPH ani ruční KH. Řádky jsou `purchase.*`. Kanonický typ
  `proformaReceived`.
- **D2** Účtování `799100 MD / 757100 DAL` celkovou částkou hlavičky.
  Předpis je na straně DAL, aby skupina byla pro výdej přirozená. Skupina
  `proformas_in` „Zálohové faktury přijaté“ má pořadí 25,
  `payment_category: advances.given` a `closing_category: offbalance.contra`.
- D3 (storno proformy s úhradou) a D4 (pošta) jsou samostatné tasky.

## Scope

### 1. Typ dokladu

`docTypes.jsonc` — doplnit `invpi` do úvodního komentáře (výčet typů)
a přidat za `invni`:

```jsonc
// Zálohová faktura přijatá (#106 D1): pevně vstup, není daňový doklad
// (tax_document: false) — DPH jen informativně, bez DUZP a bez zařazení
// do tvrzení DPH. Účtuje se na podrozvahu (accountingRules, #106 D2).
// Kód 22 a zkratka FPZ jako starý typ prfmin.
"invpi": {
    "name": "Received proforma invoice",
    "name:cs": "Zálohová faktura přijatá",
    "name:en": "Received proforma invoice",
    "name:sk": "Zálohová faktúra prijatá",
    "name:de": "Eingangs-Proformarechnung",
    "shortcut": "FPZ",
    "shortcut:cs": "FPZ",
    "shortcut:en": "RPI",
    "doc_id_code": "22",
    "trade_dir": 2,
    "tax_document": false,
    "doc_number_pattern_default": "%D%y%C%4",
    "subclass": "Shipard\\Module\\Docs\\ProformasIn\\ProformaInDocument"
}
```

Nedaňový charakter (DUZP/DPPD null, období DPH null i při ruční hodnotě,
vyřazení z `VatDocumentSelection`, sazba k datu vystavení, applier
ignoruje `taxPointDate`) zařídí stávající čtení přes `DocTypes`. Kód na
`=== 'invpi'` nevětvit. Ověř, že `NumberSeriesProvisioner` při
`ds-upgrade` založí výchozí řadu `invpi` bez dalšího zásahu.

### 2. Báze přijatých faktur v `docs.core`

Vzor `IssuedInvoiceFormBase` (FVB + FVZ):

- **`ReceivedInvoiceFormBase`** (abstract, `extends DocsHeadsFormBase`):
  přesunout sem `buildHeaderTab()`, `buildExtraTabs()` a `buildSettingsTab()`
  z `ReceivedInvoiceForm`. Pro nedaňový typ (`isTaxDocument()`) skrýt
  `vat_duzp`, `vat_dppd` (dnes je skrývá jen `$hasVat`) a `cs_mode`. Sazby,
  rekapitulace a součty s DPH zůstávají viditelné. Docblock podle
  `IssuedInvoiceFormBase`.
- `ReceivedInvoiceForm` (FPB) zůstane tenký: titulky a header-info hooky.
  **FPB se musí vykreslit beze změny** — stávající testy formulářů
  (`PaymentIntermediaryFormTest`, `InvoiceFormsCashDeskTest`,
  `DimensionFormFieldsTest`, `DocAuthorFormTest`, …) zelené bez úprav
  očekávání.
- **Doporučení bankovního spojení dodavatele** (warning
  `partner_bank_recommended` v `ReceivedInvoiceDocument::validate`) platí
  i pro výzvu, protože výzva je hlavně podklad k platbě. Přesunout do
  abstraktní báze v `docs.core` (např. `ReceivedInvoiceDocumentBase extends
  DocsHeadsDocument`). `ReceivedInvoiceDocument` i nový `ProformaInDocument`
  z ní dědí, nic se nekopíruje.

### 3. Modul `docs.proformasIn`

Nový modul `modules/docs/proformasIn/` podle `docs.proformasOut`:

- `module.jsonc`: `id: docs.proformasIn`, závislost `docs.core`. Viewer
  `docs.proformasIn.heads` „Zálohové faktury přijaté“ (+ en),
  `navSection: "purchase"`, `navOrder: 15` (za Fakturami přijatými 10).
  `documentClasses` a `forms` přes `typeColumn: doc_type`, `invpi → …`.
  **Bez `prints`**, přijatý doklad se netiskne.
- Instalační sada `modules/install/base/module.jsonc`: přidat za
  `docs.invoicesIn`.
- `ProformaInDocument extends ReceivedInvoiceDocumentBase` — nic navíc.
- `ProformaInForm extends ReceivedInvoiceFormBase` — titulky „Zálohová
  faktura přijatá“ / „Nová zálohová faktura přijatá“ (+ en), štítek typu
  „Zálohová faktura přijatá“, ikona.
- `ProformasInViewer extends DocsHeadsViewer` — `$scopedDocType = 'invpi'`.
- Ikona: nový klíč `invoice-proforma-in` v `frontend/src/icons.js` (import,
  export, `iconMap`). FA ikonu vyber tak, aby se lišila od `invoice-in`
  i `invoice-proforma`. Použít ve vieweru, v hlavičce formuláře
  a v `DocsHeadsViewer::detailIconForDocType`.
- `README.md` modulu podle `docs.proformasOut/README.md`: co přidává,
  nedaňový charakter, podrozvaha, vztah k `docs.invoicesIn`, odkaz na #106.

### 4. Pohyby řádků

`rowOperations.jsonc`: `purchase.goods` → `"invpi": {"order": 100}`,
`purchase.services` → `"invpi": {"order": 200}`, `purchase.other` →
`"invpi": {"order": 300}`. Nic dalšího: pohyby na výzvě účetně nic
neznamenají, předpis účtuje jen hlavičku. `applyRowOperations.jsonc` zatím
bez záznamu, doplní ho task pošty (#106 D4). `ApplyRowOperationsParityTest`
a `OperationSidesTest` musí projít.

### 5. Výměnný formát

- `DocumentApplier::DOC_TYPE_MAP`: `'proformaReceived' => 'invpi'`.
- `DocumentExporter`: `'invpi' => 'proformaReceived'`. Role stran spadnou
  do výchozí větve (`customer` / `supplier`, jako `invni`) — ověř testem.
- `DocumentValidator::checkPerDocType`: `proformaReceived` → dodavatel
  povinný („U zálohové faktury přijaté je dodavatel povinný.“).
  `checkPartnerDocNumber` platí i pro `proformaReceived`.
- `docs/exchange-format.md` §5: nový kanonický typ. `vatDuzp` / `vatDppd`
  se u něj ignorují a doklad nenese období DPH.

### 6. Podrozvahové účty

- Seed `accountChartDefault.jsonc`: za `756100` přidat `757` a `757100`
  „Přijaté zálohové faktury“, kind 6. `accountChartNpo.jsonc` totéž.
  Komentář nad blokem třídy 7 doplnit.
- `OffBalanceAccountsProvisioner::ACCOUNTS`: přidat `757` a `757100` (kind 6),
  docblock doplnit. Existující DS dostanou účty při dalším `ds-upgrade`
  (provisioner běží bezpodmínečně, i pod `skipProvisioning`).

### 7. Účtovací předpis

`accountingRules.cz.jsonc`:

- `categories`: `"proformas.in": {"name:cs": "Zálohové faktury přijaté (podrozvaha)"}`.
- `accounts`: `{"cat": "proformas.in", "accountMask": "757"}`.
- `documents`:

```jsonc
// Zálohová faktura přijatá (#106 D2): jen podrozvaha, celkovou částkou
// hlavičky, zrcadlo invpo — předpis na straně DAL jako závazek, aby
// skupina proformas_in byla pro výdej přirozená (LedgerOpenItemLookup).
// Žádné DPH, náklady ani rozvaha. Partner, VS, SS a splatnost z hlavičky
// → klíč případu. Úhradu uzavírá CaseClosureContributor (757 MD / 799 DAL).
{"docType": "invpi",
    "accounting": [
        {"cat": "offbalance.contra", "src": "head", "col": "total", "side": 0,
            "text": "Zálohová faktura přijatá"},
        {"cat": "proformas.in", "src": "head", "col": "total", "side": 1,
            "text": "Zálohová faktura přijatá"}
    ]
}
```

Bez `partnerSrc` a bez větvení podle `payment_method`: výzva jde vždy na
podrozvahu, platba v hotovosti je samostatný pokladní doklad
`advance.given`.

### 8. Skupina saldokonta `proformas_in`

`balancesDefault.cz.jsonc` — za `payables`:

```jsonc
// Zálohové faktury přijaté (#106 D2): podrozvahová evidence výzev
// k platbě. Předpis = výzva (757 DAL), úhrada = uzavření (757 MD,
// CaseClosureContributor). Výdaj nalezený v této skupině se účtuje na
// poskytnutou zálohu (payment_category), ne na 757. Pořadí 25: VS, který
// sedí i na otevřenou fakturu, najde nejdřív Závazky (20).
{
    "code": "proformas_in",
    "name": "Zálohové faktury přijaté",
    "short_name": "Zál. faktury přij.",
    "sort_order": 25,
    "show_in_navigation": 1,
    "payment_category": "advances.given",
    "closing_category": "offbalance.contra",
    "accounts": [
        {"account_number": "757", "acc_side": 1, "amounts_sign": 1, "bal_side": 0, "modify_sign": false, "note": "Zálohová faktura přijata"},
        {"account_number": "757", "acc_side": 0, "amounts_sign": 1, "bal_side": 1, "modify_sign": false, "note": "Zálohová faktura uhrazena / uzavřena"}
    ]
}
```

Skupina vznikne i v legacy variantě (`amounts_sign` → 0, stejně jako
u `proformas_out`) a na existujících DS při dalším `ds-upgrade`.

### 9. Úhrada a uzavření — bez nového kódu

Očekávaný tok, ověřit testy (oddíl Testy):

- **Banka:** výdaj s partnerem a VS výzvy → lookup prohledá přirozené
  skupiny výdaje v pořadí nastavení (Závazky 20, Zálohové faktury přijaté
  25, …) → zásah v `proformas_in` → `OpenItem::paymentCategory =
  advances.given` → engine `314 MD / 221 DAL` pod klíčem výzvy. Generátor
  z 314 MD udělá předpis v Poskytnutých zálohách (#69 D23). Contributor:
  spouštěcí řádek 314 MD (strana opačná k předpisu DAL) → `757100 MD /
  799100 DAL` do výše rezidua.
- **Pokladna:** výdaj `advance.given` s VS výzvy → 314 MD z předpisu
  pokladny → contributor stejně.
- **Platba dřív než výzva:** banka → clearing 261300 → po zaúčtování výzvy
  `ClearingRerouteHandler` přeúčtuje na 314 + uzavření. Pokladna →
  `CaseClosureRerouteHandler`.
- **Cizí měna:** uzavření kurzem výzvy (#79 D3c), podrozvaha na nulu.
- **Konečná faktura přijatá** s ručním řádkem `purchase.advanceDeduction`
  (VS + SS výzvy, bez DPH) uzavře předpis v Poskytnutých zálohách.

Když test odhalí předpoklad zadrátovaný pro vydanou stranu (směr příjmu,
324, 756), oprav ho **obecně** nad nastavením skupiny, ne větví pro
`invpi`, a zapiš to do Poznámek z implementace.

### 10. Popisy pro AI asistenta

`DocumentsSearchTool` a `DocumentsAggregateTool` — v popisu parametru
`doc_type` doplnit `'invpi'` (zálohové přijaté = dodavatelé).

### 11. Dokumentace

- `CLAUDE.md` → Editační formuláře — polymorfismus per typ: hierarchie
  s `ReceivedInvoiceFormBase` a `docs.proformasIn` (`invpi →
  ProformaInForm`); odstavec Nedaňový typ dokladu: „zatím jen `invpo`“
  → `invpo`, `invpi`.
- `docs/edit-forms.md` kap. 23 — hierarchie tříd.
- `docs/docs-mvp.md` — výčet typů dokladů.
- `docs/accounting.md` §4 — nový pododdíl „Zálohová faktura přijatá —
  podrozvaha (#106 D2)“ vedle vydané.
- `docs/accbal.md` — seed skupin, §5.1 (výdaj, `advances.given`), §5.8
  (contributor obsluhuje obě skupiny), log rozhodnutí (#106 D2).
- `docs/bank.md` §6.1 — zásah ve skupině s `payment_category` na výdajové
  straně.
- `modules/economy/vat/docs/README.md` — nedaňové typy.
- `docs/exchange-format.md` §5 (viz bod 5).
- **Help:** nová stránka `help/faktury-prijate/zalohova-faktura-prijata.md`
  (šablona a front matter dle `docs/help-authoring.md`). Obsah: k čemu je,
  že není daňový doklad, jak ji pořídit, že úhrada se objeví jako
  poskytnutá záloha a výzvu uzavře, že výzvu, kterou platit nebudeš,
  stornuješ (**jen nezaplacenou** — hlídání přijde s #106 D3), a že na
  konečnou fakturu se ručně přidá řádek Odpočet poskytnuté zálohy s VS
  a SS výzvy. Názvy sekcí, tlačítek a pohybů ověř v `module.jsonc`,
  `rowOperations.jsonc` a `cs.js`.
- `help/co-dnes-nejde.md`: výzvu k platbě z pošty Shipard zatím sám
  nezaloží (#106 D4); odpočet zálohy na konečné faktuře přijaté se zadává
  ručně (#107).
- `python3 scripts/help-index.py`.

## Mimo scope

- Hlídání storna proforem s úhradou v obou směrech (#106 D3).
- Pošta: primární typ `proformaReceived`, prompt, `applyRowOperations`
  pro `invpi` (#106 D4, až po #105).
- Odpočet zálohy na konečné faktuře (ruční výběr i párování z pošty)
  a daňový doklad k poskytnuté záloze (#107).
- Uzavření zbytku, přehled výzev k vyřízení (#79 D5), tisk, import.

## Testy

Unit:

- `DocTypesTest`: `invpi` je nedaňový.
- `DocDocumentDefaultsTest`, `DocsHeadsVatPeriodHandlerTest`,
  `VatDocumentSelectionTest`: `invpi` bez DUZP/DPPD, bez období i s ruční
  hodnotou, ze selekce vyřazený.
- `ProformaInFormTest`, `ProformaInFormHeaderInfoTest`,
  `ProformasInViewerTest` (vzor `ProformaOut*`): dispatch `invpi →
  ProformaInForm`, titulky, skryté DUZP/DPPD/`cs_mode`, scope vieweru.
- `ProformaInDocument`: bez bankovního spojení dodavatele ve stavu 40 →
  warning `partner_bank_recommended`, uložení projde; FPB beze změny.
- `DocumentApplierTest`: `proformaReceived` v import módu → `invpi` bez
  DUZP. `DocumentExporterTest`: `invpi` → `proformaReceived`, partner jako
  `supplier`. Validator: `proformaReceived` bez dodavatele → `required`.
- `OffBalanceAccountingRulesTest`: drift masky `757` ↔ konstanta
  provisioneru ↔ seedy osnovy; předpis `invpi` s celkem 12 100,00 →
  `799100 MD 12 100 / 757100 DAL 12 100`, partner a VS z hlavičky, žádný
  řádek 343/5xx/321.
- `OffBalanceAccountsProvisionerTest`: založí `757` a `757100`.
- `BalancesProvisionerTest`: `proformas_in` s `payment_category`
  a `closing_category`, normální i legacy varianta.
- `LedgerOpenItemLookupTest`: výdaj s VS otevřené výzvy → `OpenItem`
  z `proformas_in` s `paymentCategory = 'advances.given'`; VS na Závazky
  i výzvu → Závazky.
- `CaseClosureContributorTest`: skupina s předpisem na DAL — spouští
  314 MD (`payment.out`, `advance.given`), pár `757100 MD / 799100 DAL`,
  částečná úhrada, přeplatek jen do rezidua, 314 DAL nic.
- `ApplyRowOperationsParityTest`, `OperationSidesTest` zelené.

Integrační (vzor `ProformaAccountingTest`, `BankPaymentRoutingTest`,
`ProformaClosureTest`), na volném dev DS:

- Zaúčtovaná `invpi` nezmění rozvahu ani výsledovku, deník je vyrovnaný,
  případ je otevřený v Zálohových fakturách přijatých.
- Bankovní výdaj celé částky → `314/221` + `757/799`, výzva uzavřená,
  v Poskytnutých zálohách otevřený předpis. Částečná úhrada nechá zbytek
  otevřený.
- Pokladní `advance.given` → totéž.
- Platba dřív než výzva: banka i pokladna.
- Konečná faktura přijatá s ručním odpočtem (VS + SS výzvy) uzavře
  Poskytnuté zálohy.
- Storno neuhrazené výzvy → případ ze saldokonta zmizí.
- Vydaná strana beze změny (stávající `Proforma*` testy zelené).

Filtrem, např.
`vendor/bin/phpunit --filter 'Proforma|OffBalance|BalancesProvisioner|LedgerOpenItemLookup|CaseClosure|BankTransactionAccountingEngine|DocTypes|VatPeriodHandler|VatDocumentSelection|DocumentApplier|DocumentExporter|DocumentValidator|ApplyRowOperationsParity|OperationSides|FormTest'`;
integrační s `SHIPARD_INTEGRATION_DS_PATH` na volný dev DS; celá sada na
konci lokálně.

## Commity

1. `docs: typ dokladu invpi, báze přijatých faktur, modul docs.proformasIn (#106 D1, 1/4)`
   — docTypes, `ReceivedInvoiceFormBase` a báze dokumentu, modul, ikona,
   rowOperations, popisy MCP nástrojů, testy.
2. `exchange: kanonický typ proformaReceived (#106 D1, 2/4)` — bod 5 + testy.
3. `accounting+accbal: podrozvaha 757100, předpis invpi, skupina proformas_in (#106 D2, 3/4)`
   — body 6–9 + testy úhrady a uzavření.
4. `docs+help: zálohová faktura přijatá; task (#106, 4/4)` — dokumentace,
   help, `**Stav:**`, `python3 scripts/tasks-index.py`,
   `python3 scripts/help-index.py`.

## Poznámky z implementace (2026-10-07)

- Zadání sedělo na kód: enginy, lookup, `CaseClosureContributor` ani
  reroute handlery nedostaly žádnou změnu — vše jde z `request_side`
  a `payment_category` / `closing_category` skupiny. Integrační
  `ProformaInClosureTest` (zrcadlo `ProformaClosureTest`) to ověřuje
  vč. cizí měny, jiného fiskálního roku, platby dřív než výzva (banka
  přes clearing 261300, pokladna přes `CaseClosureRerouteHandler`),
  konečné faktury s ručním odpočtem a storna.
- Navíc proti zadání: `NavigationControllerTest` vyjmenovává viewery
  sekce Nákup natvrdo; `OffBalanceAccountingRulesTest` a
  `OffBalanceAccountsProvisionerTest` mají natvrdo seznam účtů 75x/79x
  (rozšířeno o 757/757100); `DocTypesTest::testRealConfigMarksOnlyProformasAsNonTax`
  nad reálným configem čeká oba nedaňové typy;
  `DocumentValidator::checkPartnerDocNumber` měl `!== 'invoiceReceived'`
  → seznam obou přijatých typů.
- `vat_dppd` na hlavičce FPB dřív skrývalo jen `$hasVat` — báze přidává
  `|| !$taxDocument` pro DUZP i DPPD, jinak by výzva DPPD zobrazila.
- `ReceivedInvoiceForm` instancuje přímo sedm testů v
  `tests/Unit/Module/Docs/Core`; třída zůstala a jen dědí bázi, takže
  prošly beze změny.
- Ikona `invoice-proforma-in` = `faFileImport` (šipka do dokumentu
  zvenčí; `invoice-in` má `faFileArrowDown`, vydaná proforma
  `faFileContract`). Používá se jen v `icons.js` a
  `DocsHeadsViewer::detailIconForDocType`, žádná Svelte komponenta typ
  dokladu nemapuje.
- `BalancesProvisionerTest` počítá skupiny ze seedu, novou skupinu
  absorboval sám; `ApplyRowOperationsParityTest` iteruje jen záznamy
  `applyRowOperations`, bez `invpi` projde (doplní #106 D4).
- 4l3j nemá pokladnu s účtem 211 → pokladní případy integračních testů
  (vydané i přijaté strany) skipují; banka, clearing reroute, konečná
  faktura a storno prošly.

## Hotovo když

- [x] `ds-upgrade` na volném dev DS: řada `invpi`, účty `757`/`757100`
      (kind 6), skupina Zálohové faktury přijaté v Nastavení i v navigaci;
      v Nákupu „Zálohové faktury přijaté“ s vlastní ikonou (4l3j:
      provisionery založily 2 účty, 1 skupinu, 1 řadu; navigace přes
      `NavigationControllerTest`; zbývá proklik v prohlížeči).
- [x] Nová výzva se sazbami DPH: rekapitulace a součet s DPH sedí,
      DUZP/DPPD, období ani KH ve formuláři nejsou a v DB jsou `NULL`.
      Faktura přijatá vypadá a chová se beze změny (unit testy formuláře,
      `DocDocumentDefaultsTest`, `DocsHeadsVatPeriodHandlerTest`; zbývá
      proklik).
- [x] Potvrzená výzva → deník `799100 / 757100`, případ otevřený;
      rozvaha a výsledovka beze změny (`ProformaInAccountingTest`).
- [x] Bankovní výdaj s VS výzvy → `314/221` + `757/799`, výzva uzavřená,
      záloha otevřená v Poskytnutých zálohách; konečná faktura přijatá
      s ručním odpočtem zálohu uzavře (`ProformaInClosureTest`).
- [x] Platba zaúčtovaná dřív než výzva se po potvrzení výzvy sama
      přeúčtuje (banka ověřena na 4l3j; pokladna jen unit testy
      contributoru — DS nemá pokladnu s účtem 211).
- [x] Storno neuhrazené výzvy ji ze saldokonta odstraní
      (`ProformaInClosureTest::testClearingUnpaidProformaRemovesItFromBalances`).
- [x] Vydané proformy a platby bez výzvy se chovají beze změny
      (regresní testy zelené).
- [x] Dokumentace a help aktualizované, `tasks-index.py --check`
      a `help-index.py` projdou.
- [ ] Nasazení na alfu (`ds-upgrade`) — rozhodne člověk.
