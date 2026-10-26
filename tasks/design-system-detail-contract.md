# Design system — kontrakt detailu, task A: builder, štítek stavu, přílohy

**Stav:** hotovo — 2026-10-23, #136 task A (D18–D24): builder, štítek stavu, přílohy, doklady, Majetek, Zakázky; převod zbývajících 49 viewerů (`DetailContractTest::NOT_CONVERTED`) je task B

## Kontext / Cíl

Část #136 zastřešující issue #133. Hlavičku detailu dnes posílá 17 z 65
viewerů (10 vlastním kódem, 7 dokladových dědí z `DocsHeadsViewer`),
přílohy ukazuje v detailu 5 z 15 tabulek, které mají ve formuláři tab
Přílohy. Každý viewer skládá detail ručně: dotaz na přílohy je zkopírovaný
pětkrát, štítek stavu sedmkrát, tab Přehled nad vlastnostmi dvakrát
(`overviewDetail()` v `AssetsViewerBase` a `WorkOrdersViewerBase`).
Nový viewer tak na hlavičku nebo přílohy snadno zapomene.

**Cíl tasku A:**

1. Detail skládá `TableViewer` (builder): viewer dodá části — záznam,
   hlavičku, další štítky, taby, akce, vlastní skupiny příloh. Hlavička
   má výchozí podobu z deklarace vieweru nebo z `displayPattern` tabulky
   (D18, D19).
2. Štítek stavu přidá builder sám; stav „V pořádku“ se v hlavičce
   neukazuje — určuje to příznak v konfiguraci stavů (D20).
3. Přílohy záznamu dodá builder obecně jako `detail.attachments`; frontend
   je kreslí jednou sdílenou komponentou na konci Přehledu, nebo ve
   vlastním tabu, když to viewer deklaruje (D21). Odeslaná pošta jen na
   konci Přehledu, za přílohami (D22).
4. Kontraktní test (unit + integrační) se seznamem nepřevedených viewerů,
   který se smí jen zmenšovat (D23).
5. Převod nejtěžších případů: doklady (`DocsHeadsViewer` + 7 potomků,
   přílohy zdrojové pošty jako vlastní skupina), celý modul
   `economy.assets` (Majetek s tabem Přílohy) a celý modul
   `economy.workOrders`. Soukromé kopie zaniknou (D24).

**Mimo rozsah:**

- převod ostatních viewerů — task B (seznam nepřevedených na nulu,
  `renderDetail()` jako `final`);
- `FormStateBadge` — formulář dál ukazuje i „V pořádku“; pravidlo D20 je
  jen pro hlavičku detailu;
- review modal dokladu na dashboardu (#139);
- sdílené karty, sekce a seznam vlastností (#137) — tento task přidává
  jen sdílenou sekci příloh;
- `AttachmentGrid.svelte` (dlaždice beze změny), lišta akcí, zámek,
  tisky a odeslání (controller beze změny kromě umístění Odeslané pošty
  na frontendu);
- složené řádky a úzká šířka (#138).

## Návaznost

- **#133** — D2 (pravidla hlídá nástroj), D8 (přílohy), D13–D17
  (hlavička, typografie), D18–D24 (kontrakt detailu)
- **#135** / `tasks/design-system-detail-pilot.md` — vzhled detailu,
  `DocumentDetail.svelte` jako referenční implementace, kapitoly
  Typografie a Detail záznamu v `docs/design-system.md`
- **#136** task B — převod zbytku, navazuje na tento task
- **#139** review modal — netýká se, ale bere `DocumentDetail` jako vzor

## Před implementací přečti

- `docs/design-system.md` — §4 (doc-state systém, proč „V pořádku“ nemá
  proužek), §12 Detail záznamu
- `docs/frontend.md` — §7 Formát detail panelu, Akce detailu, Registrace
  vieweru
- `docs/doc-states.md` — konfigurace stavů
- `src/Core/Viewer/TableViewer.php`, `ViewerDefinition.php`,
  `ViewerRegistry.php`
- `src/Api/Controller/ViewerController.php` — `detail()` (zámek, tisky,
  Odeslaná pošta)
- `src/Core/Accounting/JournalDimension.php` — `labelColumns()`, `label()`
  (vzor, který se vytáhne do sdílené třídy)
- `src/Core/Document/DocStateConfig.php`
- `modules/docs/core/src/DocsHeadsViewer.php` — `renderDetail()`,
  `buildDetailHeader()`, `detailAttachmentGroups()`,
  `sourceAttachmentGroups()`, `buildDetailActions()`
- `modules/economy/assets/src/AssetsViewerBase.php`, `AssetsViewer.php`
  a ostatní viewery modulu
- `modules/economy/workOrders/src/WorkOrdersViewerBase.php`,
  `WorkOrdersViewer.php` a ostatní viewery modulu
- `frontend/src/components/viewer/ViewerDetail.svelte` — hlavička,
  `renderContent`, sekce Odeslaná pošta
- `frontend/src/components/viewer/DocumentDetail.svelte` — sekce příloh
  (přesouvá se), `openSourceMessage()`
- `tests/Unit/Module/Docs/Core/DocsHeadsViewerDetailTest.php`,
  `tests/Unit/Module/Economy/Assets/AssetsViewerTest.php`,
  `tests/Unit/Module/Economy/WorkOrders/WorkOrdersViewerTest.php`,
  `WorkOrderSettingsViewersTest.php`
- `tests/Integration/README.md`, `tests/Integration/IntegrationTestCase.php`

## Rozhodnutí (potvrzená v #133)

- ✓ **D18** Detail skládá `TableViewer`, ne viewer: viewer dodá hlavičku
  a taby, celek složí společná metoda; `renderDetail()` po převodu
  zanikne.
- ✓ **D19** Výchozí hlavička z konfigurace: `detailHeader: {title,
  subtitle}` v `module.jsonc` s placeholdery jako `displayPattern`; bez
  deklarace titulek z `displayPattern` tabulky, ikona z `viewers[].icon`.
  Titulek je název, podle kterého uživatel záznam pozná; kód nebo číslo
  jde do podtitulku.
- ✓ **D20** Štítek stavu přidá builder; „V pořádku“ se v hlavičce
  neukazuje — příznak u stavu v konfiguraci, ne styl stavu (K řešení,
  V práci, Hotovo, Odeslaná, Podáno zůstávají).
- ✓ **D21** Přílohy obecně podle `tableId` jako `detail.attachments`,
  jedna sdílená komponenta na konci Přehledu; viewer smí přidat vlastní
  skupiny; umístění `overview` / `tab` / `content`.
- ✓ **D22** Odeslaná pošta jen na konci Přehledu, pořadí Přílohy →
  Odeslaná pošta.
- ✓ **D23** Kontraktní test unit + integrační, seznam nepřevedených
  viewerů se jen zmenšuje.
- ✓ **D24** Task A = builder, sekce příloh, test, doklady, Majetek,
  Zakázky; task B = zbytek.

Upřesnění při psaní tasku: D18 zmiňuje, že obecné části doplní
controller. Přílohy skládá **builder v `TableViewer`**, protože vlastní
skupiny vieweru (zdrojová pošta dokladu) potřebují záznam a builder jde
otestovat bez controlleru. Controller zůstává u zámku, tisků, odeslání
a Odeslané pošty.

## 1. Sdílený vzor popisku záznamu (`RecordPattern`)

Logiku `JournalDimension::labelColumns()` / `label()` vytáhni do
`src/Core/Database/RecordPattern.php`:

```php
final class RecordPattern
{
    public function __construct(public readonly string $pattern) {}

    /** @return list<string> sloupce z placeholderů `{column}` */
    public function columns(): array;

    /**
     * Vyplní vzor hodnotami záznamu. DateTimeInterface → `j. n. Y`.
     * Prázdné hodnoty se vynechají i se svým oddělovačem
     * („{code} — {name}“ bez kódu → „Název“, ne „— Název“).
     * Null, když je výsledek prázdný.
     */
    public function render(array $record, string $prefix = ''): ?string;
}
```

Oddělovače, které se při prázdné hodnotě zahazují: ` — `, ` – `, ` · `,
` - `, mezera. `JournalDimension` přepni na `RecordPattern` tak, aby se
jeho chování nezměnilo (stávající testy dimenzí musí projít beze změny,
včetně `#id` fallbacku, který zůstává v `JournalDimension`).

Testy: `tests/Unit/Core/Database/RecordPatternTest.php` — sloupce, datum,
prázdná hodnota na začátku / uprostřed / na konci, všechny prázdné → null,
vzor bez placeholderu.

## 2. Deklarace vieweru (`ViewerDefinition`, `ViewerRegistry`)

`ViewerDefinition` dostane:

```php
/** @var array{title?: string, subtitle?: string}|null */
public readonly ?array $detailHeader = null,
/** 'overview' | 'tab' | 'content' */
public readonly string $detailAttachments = 'overview',
```

`ViewerRegistry::loadFromModules()` je čte z `viewers[]`; neznámá hodnota
`detailAttachments` je chyba konfigurace (`InvalidArgumentException`
s id vieweru). `createViewer()` předá definici vieweru novým setterem
`TableViewer::setDefinition(ViewerDefinition $def)`. Viewer vytvořený
v testu bez definice musí fungovat (výchozí hodnoty, žádná ikona).

Zápis v `module.jsonc`:

```jsonc
{
    "id": "economy.assets.assets",
    "icon": "box",
    "table": "economy_assets_assets",
    "class": "…\\AssetsViewer",
    // Titulek je název, inventární číslo v podtitulku (#133 D19).
    "detailHeader": {"title": "{name}", "subtitle": "{asset_number}"},
    // Fotky, smlouvy, doklady — vlastní tab Přílohy (#133 D8, D21).
    "detailAttachments": "tab"
}
```

## 3. Příznak „neukazovat v hlavičce“ ve stavech

Do konfigurace stavů přidej u stavu **40 „V pořádku“** klíč
`"detailBadge": false` (chybí = `true`) ve třech souborech:

- `modules/docs/core/config/docStates.jsonc`
- `modules/core/system/config/docStatesArchive.jsonc`
- `modules/economy/workOrders/config/docStates.jsonc`

Jinde nic. `DocStateConfig::showsDetailBadge(int $docState): bool`.
Komentář v JSONC: proč (D20 — běžný stav bez proužku v seznamu, štítek
by jen šuměl; formulář ho ukazuje dál). Změna cfgItem = `ds-upgrade`.

## 4. Builder v `TableViewer`

`renderDetail(int $recordId)` dostane výchozí implementaci — builder.
V tasku A zůstává přepsatelná (nepřevedené viewery ji přepisují
celou); v tasku B bude `final`.

```php
public function renderDetail(int $recordId): array
{
    $record = $this->loadDetailRecord($recordId);
    if ($record === null) {
        return ['tabs' => []];
    }
    // hlavička → štítky (stav, pak detailBadges) → taby → přílohy → akce
}

/** Záznam pro detail; výchozí loadRecord(), viewer s JOINy přepisuje. */
protected function loadDetailRecord(int $recordId): ?array;

/** Výchozí: deklarace detailHeader → displayPattern tabulky → '#id'; ikona z definice. */
protected function detailHeader(array $record): DetailHeader;

/** Štítky za štítkem stavu (Chyba účtování, Po termínu…). */
protected function detailBadges(array $record): array;   // výchozí []

/** Taby detailu, první je Přehled. */
protected function detailTabs(array $record): array;     // výchozí []

protected function detailActions(array $record): array;  // výchozí []

/** Skupiny příloh vieweru, kreslí se před vlastními přílohami záznamu. */
protected function detailAttachmentGroups(array $record): array; // výchozí []

/** Tab Přehled nad skupinami vlastností (nahrazuje overviewDetail()). */
protected function propertiesTab(array $groups): array;
```

`src/Core/Viewer/Detail/DetailHeader.php` — readonly `title` (string,
neprázdný), `subtitle` (?string), `icon` (?string). Štítky nejsou
součástí `DetailHeader`, aby štítek stavu nešel obejít.

**Štítek stavu:** cfgItem stavů z `$this->tables[$this->table]->docStates`
(`DocStatesDefinition::$cfgItem`), fallback `$docStatesCfgItem`; sloupec
stavu z téže definice (`stateColumn`, výchozí `docState`). Štítek
`{label: stateName, style: stateStyle}` jen když
`showsDetailBadge()` vrací true. Tabulka bez stavů = bez štítku.

**Přílohy:** `src/Core/Viewer/Detail/RecordAttachments.php` — jeden dotaz
místo pěti kopií:

```sql
SELECT id, name, file_name, file_size, mime_type, send_with_record
FROM core_attachments_files
WHERE table_id = %i AND record_id = %i AND is_deleted = 0
ORDER BY att_order ASC, name ASC
```

Položka `{id, name (fallback file_name), mime_type, file_size}`,
`send_with_record: true` jen když je nastavené (#94 D6). `tableId`
z `$this->tables[$this->table]->tableId`; bez definic tabulek se vlastní
přílohy nenačítají (žádná chyba).

Skládání podle `detailAttachments` z definice:

- `content` → builder přílohy nenačítá vůbec;
- jinak `groups = [...detailAttachmentGroups($record), {kind: 'record',
  attachments}]` (vlastní skupina jen když nějaké přílohy jsou); prázdné
  skupiny → klíč `attachments` v detailu chybí;
- `overview` → `detail.attachments = {groups}`;
- `tab` → navíc tab na konec `tabs`: `{id: 'attachments', label,
  content: {type: 'attachments'}}`, label přes
  `detailTabLabel('core.system.viewerDetailLabels', 'attachments',
  'Attachments')` — klíč `attachments` (cs „Přílohy“) přidej do
  `modules/core/system/config/viewerDetailLabels.jsonc`. Bez příloh tab
  není.

Výstup builderu má stejný tvar jako dnešní detail (`title`, `subtitle`,
`badges`, `icon`, `tabs`, volitelně `actions`, `attachments`) — controller
a frontend jiný tvar nedostanou.

Testy: `tests/Unit/Core/Viewer/TableViewerDetailBuilderTest.php` —
výchozí hlavička z `displayPattern`, z deklarace, prázdný kód
v podtitulku → null; štítek stavu ukázaný / skrytý příznakem; pořadí
štítků; přílohy `overview` / `tab` / `content`; vlastní skupiny vieweru
před skupinou záznamu; bez příloh bez klíče i bez tabu;
`send_with_record`; nenalezený záznam → `['tabs' => []]`.

## 5. Frontend: sdílená sekce příloh, Odeslaná pošta jen v Přehledu

**`frontend/src/components/viewer/DetailAttachments.svelte`** (nová) —
sekce příloh přesunutá z `DocumentDetail.svelte` beze změny vzhledu (§12
Přílohy): skupiny s nadpisem, skupina `mail` s kódem zprávy jako odkazem
a datem, skupina `record` s nadpisem „Přílohy“, přepínač Miniatury /
Velké náhledy v řádku nadpisu první skupiny, `AttachmentGrid`. Props
`groups`, `onAction`; `openSourceMessage()` se stěhuje sem (s fallbackem
`navigationStore.navigateToViewer`, když `onAction` chybí).

**`ViewerDetail.svelte`:**

- pod obsahem **prvního tabu** (`detail.tabs[0]`, Přehled): nejdřív
  `DetailAttachments` z `detail.attachments.groups` — jen když detail
  nemá tab s obsahem typu `attachments` — pak sekce Odeslaná pošta;
- Odeslaná pošta se pod ostatními taby už nekreslí (D22);
- nový typ obsahu `attachments` → `DetailAttachments` nad
  `detail.attachments.groups`.

**`DocumentDetail.svelte`:** sekce příloh, `attachmentGroups` a styly
`.shpd-docdetail__att*` pryč; obsah `document` přílohy už nenese.

**i18n:** klíče `viewer.document.attachments.doc`, `.viewGrid`,
`.viewFull` přejmenuj na `viewer.attachments.record`, `.viewGrid`,
`.viewFull` (cs „Přílohy“, „Miniatury“, „Velké náhledy“, en obdobně) —
použití najdi grepem v `frontend/src`. `npm run check:i18n`.

Nadpis vlastních příloh dokladu se tím mění z „Přílohy dokladu“ na
„Přílohy“ — stejně u všech záznamů.

## 6. Doklady (`DocsHeadsViewer`)

- `loadDetailRecord()` — dnešní `SELECT` s JOINy z `renderDetail()`.
- `detailHeader()` — dnešní `buildDetailHeader()` bez štítků (titulek
  protistrana, podtitul číslo · text, ikona podle `doc_type`). Štítek
  stavu dodá builder; doklad v pořádku tak štítek nemá, ostatní stavy ano.
- `detailTabs()` — tab Přehled s obsahem `document` **bez** klíče
  `attachments`, tab Zaúčtování jako dnes.
- `detailActions()` — dnešní `buildDetailActions()`.
- `detailAttachmentGroups()` — jen skupiny zdrojové pošty
  (`sourceAttachmentGroups()`); dotaz na vlastní přílohy dokladu zaniká.
- `renderDetail()` z třídy pryč; ověř, že žádný ze 7 potomků
  (`class … extends DocsHeadsViewer`) `renderDetail()` nepřepisuje.

`DocsHeadsViewerDetailTest` uprav: stav V pořádku bez štítku, Koncept se
štítkem, přílohy v `detail.attachments` (skupiny `mail` před `record`,
`send_with_record`), obsah `document` bez `attachments`.

## 7. Majetek (`economy.assets`)

- `AssetsViewerBase::overviewDetail()` zaniká → `propertiesTab()`
  z `TableViewer`.
- `AssetsViewer`: `loadDetailRecord()` (dnešní dotaz s JOINy),
  `detailTabs()` (Přehled, Plán, Náklady a výnosy … jako dnes),
  `detailActions()` (akce deníku), deklarace v `module.jsonc`:
  `detailHeader` `{name}` / `{asset_number}`, `detailAttachments: "tab"`.
- `AssetTypesViewer`, `AssetTypeGroupsViewer`, `AccountingGroupsViewer`:
  `detailTabs()`; hlavička výchozí — u Účetních skupin deklarace
  `{name}` / `{code}` (jejich `displayPattern` je `{code} — {name}`),
  ostatní stačí `displayPattern` `{name}`.

`AssetsViewerTest` uprav podle nového tvaru (hlavička, tab Přílohy jen
s přílohou).

## 8. Zakázky (`economy.workOrders`)

- `WorkOrdersViewerBase::overviewDetail()` zaniká → `propertiesTab()`.
- `WorkOrdersViewer`: má vlastní `loadDetailRecord()` — sjednoť se
  signaturou z `TableViewer` (protected, `?array`); `detailTabs()`
  (Přehled se stromem, Fakturace, Deník jako dnes); deklarace `detailHeader`
  `{title}` / `{number}`.
- `KindsViewer`, `WorkOrderSeriesViewer`: `detailTabs()`, hlavička
  z `displayPattern` `{name}`.

Testy `WorkOrdersViewerTest`, `WorkOrderSettingsViewersTest` uprav.

## 9. Kontraktní test

**Unit — `tests/Unit/Core/Viewer/DetailContractTest.php`.** Načte
všechny `module.jsonc` (`viewers[]`) a definice tabulek včetně rozšíření
(vzor najdi v existujícím testu, který prochází všechny moduly). Pro
každý viewer:

1. třída existuje a dědí `TableViewer`;
2. když `renderDetail()` deklaruje sama třída nebo předek pod
   `TableViewer` (reflexe `getDeclaringClass()`), musí být viewer
   v `public const NOT_CONVERTED` (seznam id viewerů); viewer ze seznamu,
   který už `renderDetail()` nepřepisuje, test **shodí** — seznam se
   musí zmenšit hned (D23);
3. placeholdery `detailHeader` existují jako sloupce tabulky;
4. viewer bez tabulky, který není v `NOT_CONVERTED`, přepisuje
   `detailHeader()` (nemá `displayPattern`);
5. `detailAttachments` je `overview` / `tab` / `content`.

Výchozí `NOT_CONVERTED` = všechny viewery kromě převedených v krocích
6–8; každý commit převodu z něj odebere své viewery. Komentář nad
konstantou: seznam jen ubývá, task B ho vyprázdní.

**Integrační — `tests/Integration/Api/ViewerDetailContractTest.php`.**
Jen čtení, nad libovolným zdrojem dat z `SHIPARD_INTEGRATION_DS_PATH`
(demo sada #131 je dobrý kandidát). Pro každý tabulkový viewer mimo
`NOT_CONVERTED`: první záznam (`ORDER BY id LIMIT 1`, prázdná tabulka =
přeskočit) → `ViewerController::detail()` → neprázdný `title`
a `icon`. Pro každou tabulku s přílohou (`core_attachments_files`,
`is_deleted = 0`) a převedený viewer s umístěním `overview` / `tab`:
záznam s přílohou má její `id` v `detail.attachments.groups`. Demo sada
nese přílohy jen u spisovny a pošty (umístění `content`), takže na ní se
druhá část přeskočí — přílohy v Přehledu pokrývá unit test builderu.

## 10. Dokumentace

- `docs/frontend.md` §7 Formát detail panelu: builder a jeho háčky,
  deklarace `detailHeader` / `detailAttachments`, `detail.attachments`
  (skupiny `mail` / `record`), typ obsahu `attachments` (stávající
  řádek tabulky typů, který `attachments` popisuje jako „přílohy
  seskupené po zprávách“, neodpovídá kódu — přepsat), Odeslaná pošta jen
  pod prvním tabem; Registrace vieweru — nový příklad bez
  `renderDetail()`; hlavička už není volitelná pro převedené viewery.
- `docs/design-system.md` §12: hlavička — výchozí titulek a podtitul
  (D19), štítek stavu a výjimka „V pořádku“ (D20); obsah — pořadí
  Přílohy → Odeslaná pošta na konci Přehledu, vlastní tab Přílohy jako
  výjimka (D21, D22). V §4 jedna věta, že „V pořádku“ nemá ani štítek
  v hlavičce detailu (formulář ano).
- `docs/doc-states.md`: klíč `detailBadge`.
- `CLAUDE.md`: do části o viewerech jedna odrážka — detail skládá
  builder, viewer dodává háčky, nový viewer `renderDetail()` nepřepisuje
  (kontraktní test).

## Commity

1. `refactor(core): sdílený vzor popisku záznamu RecordPattern (#136)`
2. `feat(core): builder detailu vieweru — hlavička, štítek stavu, přílohy (#136)`
   — kroky 2–4, `DetailContractTest` s plným `NOT_CONVERTED`, test
   builderu
3. `feat(frontend): sdílená sekce příloh, Odeslaná pošta jen v Přehledu (#136)`
4. `refactor(docs.core): detail dokladu přes builder (#136)`
5. `refactor(economy.assets): detail přes builder, tab Přílohy u majetku (#136)`
6. `refactor(economy.workOrders): detail přes builder (#136)`
7. `test(core): integrační kontrakt detailu (#136)`
8. `docs: kontrakt detailu záznamu (#136)` — včetně `**Stav:**` tohoto
   tasku a `python3 scripts/tasks-index.py`

Commity 4–6 odebírají své viewery z `NOT_CONVERTED`.

## Ověření

```bash
vendor/bin/phpunit --filter 'RecordPatternTest|JournalDimension'
vendor/bin/phpunit --filter 'TableViewerDetailBuilderTest|DetailContractTest'
vendor/bin/phpunit --filter 'DocsHeadsViewer|AssetsViewerTest|WorkOrdersViewerTest|WorkOrderSettingsViewersTest'
SHIPARD_INTEGRATION_DS_PATH=… vendor/bin/phpunit --filter ViewerDetailContractTest
cd frontend && npm run build && npm run check:i18n && npm test
python3 scripts/check-css-units.py && python3 scripts/check-css-tokens.py
vendor/bin/shpd-ds ds-upgrade   # na dev zdroji dat — příznak detailBadge, popisek tabu Přílohy
```

V prohlížeči (dev zdroj dat s třídou `free`):

- faktura V pořádku — hlavička bez štítku; Koncept, V opravě, Storno se
  štítkem;
- faktura z pošty s vlastní přílohou — na konci Přehledu skupina zprávy,
  pak Přílohy; přepínač Miniatury / Velké náhledy; klik na kód zprávy
  otevře zprávu; značka „Odeslat s dokladem“ zůstala;
- vydaná faktura s Odeslanou poštou — sekce pod Přílohami, v tabu
  Zaúčtování už není;
- karta majetku s přílohou — tab Přílohy, v Přehledu přílohy nejsou;
  karta bez přílohy — tab chybí;
- zakázka, druh zakázky, typ majetku, účetní skupina — hlavička s ikonou,
  titulek název, kód / číslo v podtitulku;
- nepřevedený viewer (Osoby, Položky) — beze změny.

## Hotovo když

- [x] `RecordPattern` + `JournalDimension` na něm, testy dimenzí beze změny
- [x] `ViewerDefinition` s `detailHeader` / `detailAttachments`, definice předaná vieweru
- [x] příznak `detailBadge: false` u „V pořádku“ ve třech sadách stavů
- [x] builder v `TableViewer` s háčky, `DetailHeader`, `RecordAttachments`, popisek tabu Přílohy
- [x] `DetailAttachments.svelte`, Odeslaná pošta jen pod prvním tabem, `DocumentDetail` bez příloh
- [x] doklady, `economy.assets` a `economy.workOrders` přes builder; `overviewDetail()` a `detailAttachmentGroups()` s vlastními přílohami zanikly
- [x] `DetailContractTest` zelený, `NOT_CONVERTED` bez převedených viewerů; integrační test zelený na dev zdroji dat
- [x] dokumentace (`frontend.md`, `design-system.md`, `doc-states.md`, `CLAUDE.md`)
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Odchylky při implementaci

- Viewerů je 64 (ne 65); `NOT_CONVERTED` začínal na 64 a po převodech
  drží 49.
- Soukromé metody kolidující s háčky (`protected` v `TableViewer` ×
  `private` v potomkovi = fatal) přejmenovány už v commitu builderu:
  `AssetsViewer::detailActions()` → `eventActions()`,
  `DocsHeadsViewer::detailAttachmentGroups(int)` → dočasně
  `documentAttachmentGroups()`, při převodu zaniklo.
- Majetek a Zakázky drží výpočty sdílené taby a akcemi (plán, události,
  pořízení; Fakturace, Deník) v memu per id záznamu — builder volá
  `detailTabs()` a `detailActions()` zvlášť.
- Prázdné akce už klíč `actions` nenesou (karta majetku dřív `[]`).
- `ViewerDetailModal` s `tabId` jiného než prvního tabu zahodí
  `attachments` i `sentMessages`, aby D22 platilo i v modalu.
- `JournalDimension` přes `RecordPattern`: částečně prázdný vzor dá
  „Soustruh“ místo „— Soustruh“ (jediná změna chování, testy beze změny).
- Integrační test se spouští `--testsuite Integration` (výchozí sada
  v `phpunit.xml` je Unit).

## Pasti

- **`renderDetail()` musí v tasku A zůstat přepsatelná** — 49 viewerů ji
  přepisuje celou a builder je nesmí rozbít. `final` až v tasku B.
- **Dvojí přílohy.** Převedený viewer nesmí přílohy kreslit ve svém
  obsahu (`composite` s `attachment-grid`, `document.attachments`) —
  jinak se ukážou dvakrát. Nepřevedené viewery (Položky, Bankovní
  výpisy) builder nepoužívají, takže dvojí přílohy u nich nevzniknou.
- **Sedm potomků `DocsHeadsViewer`** sdílí detail; kontrola, že žádný
  nepřepisuje `renderDetail()` ani háčky jinak, než je zamýšleno.
- **Bez `ds-upgrade` příznak chybí** → „V pořádku“ se ukáže (tichá
  degradace, ne chyba). Ve `DocStateConfig` chybějící klíč = `true`.
- **Vzor s prázdnou hodnotou**: `{code} — {name}` bez kódu nesmí dát
  „— Název“; datum v placeholderu formátovat (`j. n. Y`).
- **Testy bez definic tabulek** (`setTables` nevolané) — builder nesmí
  spadnout: žádné vlastní přílohy, štítek stavu přes `$docStatesCfgItem`.
- **Pořadí štítků**: stav vždy první, pak `detailBadges()`.
- **Odeslaná pošta** dál přichází z controlleru v `detail.sentMessages`;
  mění se jen místo vykreslení na frontendu.
- **`WorkOrdersViewer::loadDetailRecord()`** už existuje — sjednotit
  viditelnost a návratový typ s háčkem, ne zakládat druhý.
- **Klíče i18n** `viewer.document.attachments.*` se mohou používat i mimo
  `DocumentDetail` — přejmenovat všechna použití.
- **`FormStateBadge`** se nemění — formulář „V pořádku“ ukazuje dál.
