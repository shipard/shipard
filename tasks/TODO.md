# TODO — poznámky k budoucímu řešení

## Země vlastního subjektu má dva zdroje

**Zjištěno:** 08/2026 při přípravě `vat-payer-01`.

`AccountingEngine::resolveOwnCompanyCountry()` bere zemi z **adresy sídla
vlastní Osoby** (`OwnCompanyResolver::getOwnHeadquartersAddress()`)
s natvrdo zadrátovaným fallbackem `'cz'`. Task `ds-setup-01` přitom
zavedl `DataSourceConfig::getCountry()` jako parametr vrstvy A
(`docs/ds-setup.md` D1).

**Proč to není jen kosmetika:** na čerstvém DS adresa sídla neexistuje,
dokud ji nezaloží průvodce, takže engine tiše počítá s `'cz'`. U slovenské
firmy to znamená špatně vyhodnocené vat kódy ještě předtím, než si toho kdo
všimne.

**Směr řešení:** země subjektu (vrstva A) a země sídla nejsou totéž, takže
ne slepé sjednocení. Nejspíš: primárně `getCountry()`, adresa sídla jako
upřesnění tam, kde na něm záleží, a fallback `'cz'` zrušit.

**Priorita:** nízká dokud jsou všechny zdroje české; roste s prvním
nečeským DS. Vědomě **mimo oblast** `ds-setup` (rozhodnutí Anny).

---

## `dismiss` alertu není trvalý — check ho při dalším běhu obnoví

**Zjištěno:** 08/2026 při přípravě Fáze 3 `ds-setup`.

`AlertReconciler::mergeFindings()` si existující řádky tahá
`WHERE check_id = %s AND alert_state IN (ACTIVE, SNOOZED)`. Dismissnutý řádek
v lookupu není, takže `$existingRow === null` a vloží se **nový** řádek se
stavem `ACTIVE`. Index `idx_check_finding_state` je `type: "index"` nad
`(check_id, finding_key, alert_state)`, takže ani nekoliduje.

**Proti záměru:** komentář v `AlertsController` říká
`Max 1 rok snooze (deliberate "navždy" je dismiss, ne snooze)` — dismiss má
tedy být trvalý, ale fakticky funguje jako „skryj do dalšího běhu“
a v tabulce se hromadí mrtvé řádky.

**Směr řešení:** buď zahrnout `DISMISSED` do lookupu a dismissnutý řádek
neoživovat (dismiss = trvalé potlačení této `finding_key`), nebo dismiss
přeznačit na „potlač do změny obsahu findingu“. Rozhodnutí je věcné, ne
technické — u různých checků se hodí různě.

**Pro `ds-setup` to není blokující:** setup checky mají snooze i dismiss
zakázaný (`ds-setup.md` D13), takže se jich chování dismissu netýká.

**Priorita:** nízká, ale je to tichá nekonzistence mezi kódem a dokumentovaným
záměrem — čím dřív se rozhodne, tím míň řádků bude třeba uklidit.

---

## Settings stránky neumí pole typu `select` a `checkbox`

**Zjištěno:** 08/2026 při návrhu panelu `dsSetup` (`docs/ds-setup.md` D14).

`ModuleDefinition::fromArray()` whitelistuje field typy
`text`, `image`, `theme`, `language`, `avatar`; `app-settings.md` odkládá
`select` a `checkbox` na „první stránku, která je potřebuje“.

**Proč to není úkol oblasti `ds-setup`:** parametry vrstvy C se ovládají
v ručně psaném panelu, který si select vyrenderuje sám (D14). Zůstává to
tedy jako obecná mezera settings stránek, ne jako blokace.

**Směr řešení:** rozšířit whitelist, doplnit větev v
`SettingsController::savePage()` (vzor `language` — validace proti seznamu
povolených hodnot) a render ve `SettingsPage.svelte`. U `select` rozhodnout,
jestli se volby berou z literálního `options` v module.jsonc, nebo z `cfgItem`
(`world.base.currencies` a spol.) — druhá varianta je lokalizovaná, ale
vyžaduje přístup ke `ConfigRuntime` při sestavování definice stránky.

**Poznámka k `checkbox`:** tříhodnotové příznaky (nerozhodnuto / ano / ne)
checkbox neunese — pro ty je správná odpověď `select` s prázdnou volbou
(typ `text` už dnes mapuje prázdný string na `null`, tedy smazání klíče).

**Priorita:** nízká, dokud žádná settings stránka takové pole nepotřebuje.

---

## Gridový prohlížeč se serverovým hledáním pro sub-tabulky

**Zjištěno:** 09/2026 při fázi 1 sub-tabulek (issue #53, `tasks/subtable-phase1.md`).

Sub-tabulky ve formulářích (řádky dokladu, adresy osoby…) mají od fáze 1
**klientský filtr** — zobrazí se od 11 řádků a filtruje přes texty všech
vyrenderovaných buněk bez diakritiky (`FormSubTable.svelte`). Načítají se
vždy všechny řádky rodiče jedním dotazem.

**Kdy to přestane stačit:** rodič s řádově stovkami dětských řádků (adresy
importované z registru u velkého subjektu, dlouhé účetní doklady). Pak dává
smysl povýšit sub-tabulku na plný grid (`ViewerGrid` — infinite scroll,
serverové řazení a hledání). Kontrakt endpointu `/subtable` má záměrně tvar
`TableViewer::getGridColumns()`, aby se to obešlo bez přepisu.

**Priorita:** nízká, dokud někdo nenarazí na pomalý nebo nepřehledný filtr.

---

## Zbývající `window.confirm` nahradit `ConfirmDialog`

**Zjištěno:** 09/2026 při fázi 1 sub-tabulek (issue #53).

Fáze 1 zavedla `ui/ConfirmDialog.svelte` (Enter / Esc, `fixedSize`, testidy)
a nasadila ho v `FormSubTable`; fáze 2 jím nahradila `window.confirm`
i ve `FormDialog` (neuložené změny — Zahodit / Zůstat). Nativní
`window.confirm` zůstává v `AttachmentPanel` (smazání přílohy),
`ViewerDetail` (akce s `confirm`) a `Viewer` (smazání pravidla štítku).

**Směr řešení:** mechanická náhrada — lokální state `confirmOpen` + dialog
s `variant="danger"`; u `ViewerDetail` text pochází ze serverové akce
(`action.confirm`), titulek generický.

**Priorita:** nízká; vizuální nekonzistence, ne funkční chyba.

---

## Sjednotit privátní `formatMoney()` napříč viewery

**Zjištěno:** 09/2026 při fázi 1 sub-tabulek (issue #53).

Formátování částek `number_format(x, 2, ',', ' ')` žije jako privátní
metoda v `JournalViewer`, `BankStatementsViewer`, `BankTransactionsViewer`,
`LedgerViewer`, `DocsHeadsViewer` (+ `formatTrimmedNumber`), `DocsHeadsFormBase`
a `Mcp/DocumentsAggregateTool`; `MailSuggestionsSource::formatAmount` je
další varianta. Fáze 1 zavedla sdílený `src/Core/Form/SubtableCellFormatter`
(`money`, `number`, `trimmedNumber`, `price`, `date`, `dateTime`, `boolean`)
a použila ho v sub-tabulkách — další kopie nepřidávat.

**Směr řešení:** přejmenovat / přesunout formatter na neutrální místo
(např. `Core\Utils\NumberFormatter`) a viewery na něj přepnout; pozor na
rozdíly v chování `null` (viewer vrací `null`, `DocsHeadsFormBase::formatMoney`
vrací „0,00").

**Priorita:** nízká — úklid; roste s každým dalším viewerem.

---

## Náhled návrhu: texty „doklad dodavatele“ u vystavených dokladů

**Zjištěno:** 10/2026 při ověření `exchange-preview-vat-recompute.md` (#87, task A).

Náhled počítá `_resolve.computed` pro všechny typy dokladů z
`DocumentApplier::preview()` (D7), texty ale předpokládají přijatý doklad:
zpráva warningu `computed_total_mismatch` („Částka k úhradě na dokladu
dodavatele …“) a i18n klíče `exchange.preview.recap.declared`
(„převzatá z dokladu dodavatele“) a `exchange.preview.totals.supplierTotal`
(„Na dokladu dodavatele“). U vystaveného dokladu (`selfParty: supplier`)
jsme dodavatel my.

**Směr řešení:** neutrální texty („na dokladu“, „deklarovaná částka“),
nebo varianta podle `selfParty`.

**Priorita:** nízká — z došlé pošty chodí jen přijaté doklady; vystavené
přes náhled jdou jen z importu / API.
