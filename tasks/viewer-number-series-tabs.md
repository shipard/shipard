# Task: Spodní taby s číselnými řadami ve vieveru

**Stav:** hotovo

> Mechanismus zobecnil `tasks/viewer-bottom-tabs.md` (2026-09-29):
> `getNumberSeries()` / meta `numberSeries` / `filter[number_series]`
> nahradily `TableViewer::getBottomTabs()` / meta `bottomTabs` /
> `filter[bottomTab]`; řadu do nového dokladu nese `newRecordDefaults`
> záložky. Níže je původní zadání.

## Status / Cíl

Přidat do per-type doc viewerů (`ReceivedInvoicesViewer`, `IssuedInvoicesViewer`,
do budoucna další) **spodní lištu se záložkami číselných řad**. Záložka filtruje
seznam dokladů na danou řadu. Při vytvoření nového dokladu z aktivní záložky
se ta řada automaticky předvyplní do formuláře.

Důvody:

- Uživatel hned vidí, do které řady doklad spadá (záložka = filtr; číslo
  dokladu v řádku už ten kontext nese, ale není to na první pohled).
- Při běžné práci „pořizuji další faktury v této řadě" už není nutné při
  každém novém dokladu vybírat `number_series` v selectu — předvyplní se
  z aktivního filtru.

Backendově sahá feature jen do PHP — schema `docs_core_number_series` zůstává
beze změny, číselné řady už dnes generuje `NumberSeriesProvisioner` při
`ds-upgrade`. Frontendově přidáváme druhou (ortogonální) lištu k existujícím
viewGroup tabům.

Inspirace: starý Shipard měl řady jako spodní taby v každém viewru dokladů.

## Návaznost

- `docs-invoices.md` (Fáze 6) — postavené `ReceivedInvoicesViewer` /
  `IssuedInvoicesViewer` nad `DocsHeadsViewer`. Tato fáze zmiňuje
  „Spodní taby s číselnými řadami (jako v screenshotu od Davida)" jako
  připravený-ale-neimplementovaný kus — tento task to dodává.
- `docs/docs-mvp.md` sekce 5 — backend číselných řad, `NumberSeriesProvisioner`
  zajišťuje aspoň jednu defaultní řadu per `doc_type`.
- `frontend-phase5-viewers.md` — `Viewer.svelte` má dnes horní lištu
  viewGroups; přidáváme druhou lištu dole.
- `viewer-row-icons-and-numbers.md` — vzor pro „backend doplní metadata
  → frontend je vykreslí v levém panelu vieweru", stejná struktura jako
  zde.

## Scope

### V rozsahu

- **Backend:**
  - Nová metoda `TableViewer::getNumberSeries(): array` (default `[]`).
  - Refactor `DocsHeadsViewer`: zavést `protected ?string $scopedDocType = null`,
    odvodit z něj jak filter v `selectRows`, tak `getNumberSeries()`
    a `getNewRecordDefaults()`. Zbavíme se duplicit v podtřídách.
  - `ReceivedInvoicesViewer` + `IssuedInvoicesViewer` zjednodušit na pouhé
    nastavení `$scopedDocType` (zmizí `const DOC_TYPE`, override `selectRows`
    a `getNewRecordDefaults`).
  - `selectRows` zpracuje nový filter `number_series` (`WHERE h.number_series = %i`).
  - `ViewerController::meta()` vrátí pole `numberSeries`.
- **Frontend:**
  - `Viewer.svelte` — nový state `activeSeriesId`, lišta dole v list-panelu.
  - Při kliku na záložku se aplikuje filter `number_series=<id>`.
  - Při create akci se aktivní řada přimerg-uje do `formDefaultData` jako
    `number_series`.
- **Dokumentace:** `docs/frontend.md` (sekce Viewer systém), `docs/docs-mvp.md`
  Fáze 6 (✓ implementováno), `CLAUDE.md` pokud potřeba.

### Mimo rozsah

- Žádné DB / schema změny — `docs_core_number_series` zůstává beze změny.
- **Žádný „Vše" tab** — vždycky je vybraná jedna konkrétní řada.
- **Záložku ukazujeme jen pro řady ve stavu „V pořádku" (`docState = 40`).**
  Koncepty (10) ani Archivované (70) ani Smazané (90) řady v tabu nejsou,
  a jejich doklady tedy nejsou ve standardním viewru vidět (vědomá volba
  — viz Rozhodnutí k designu).
- Generický `DocsHeadsViewer` (bez `$scopedDocType`) lištu **neukáže** —
  „všechny doklady napříč typy" by museli mít taby napříč doc_typy, což
  zatím nepotřebujeme.
- **Doc count badge na záložkách** (kolik dokladů řada obsahuje) — nice-to-have,
  dodáme později.
- **Per-series sidebar leaves** (každá řada jako samostatná navigační
  položka v sidebaru) — nepatří sem.
- **Persistence aktivní záložky** mezi sessions / refreshem — výchozí je
  vždy první řada abecedně. Persistence per-uživatele dodáme později,
  pokud bude potřeba.

## Datový tok (high-level)

```
docs_core_number_series  (DB)
        │
        ▼
DocsHeadsViewer::getNumberSeries()
   - filtruje doc_type podle $scopedDocType
   - filtruje docState = 40
   - ORDER BY name ASC
        │
        ▼
ViewerController::meta()  →  { numberSeries: [{id, name}, ...] }
        │                                  └── volá $viewer->getNumberSeries()
        ▼
Viewer.svelte
   - default activeSeriesId = numberSeries[0]?.id  (nebo null)
   - render lišty dole (pokud numberSeries.length > 1)
   - fetchRows přidá filter[number_series]=<activeSeriesId>
   - create action: formDefaultData.number_series = activeSeriesId
        │
        ▼ filter[number_series]=5  v URL
        ▼
DocsHeadsViewer::selectRows()  →  WHERE h.number_series = %i
```

## Co je potřeba udělat

### 1. `TableViewer::getNumberSeries()` — nová metoda

**Soubor:** `src/Core/Viewer/TableViewer.php`

Přidat veřejnou metodu vedle `getViewGroups()`:

```php
/**
 * Returns the list of number series available as bottom tabs in the
 * viewer's row list. Empty array = no series tabs.
 *
 * Subclasses scoped to a single doc_type (e.g. ReceivedInvoicesViewer)
 * override this to expose the active series for their type. Generic
 * viewers leave the default empty.
 *
 * @return list<array{id: int, name: string}>
 */
public function getNumberSeries(): array
{
    return [];
}
```

Žádná další změna v base třídě — `meta()` endpoint si to zavolá sám.

### 2. `ViewerController::meta()` — vystavit `numberSeries`

**Soubor:** `src/Api/Controller/ViewerController.php`

V `meta()` doplnit do response:

```php
return Response::success([
    'id'                 => $def->id,
    'name'               => $def->name,
    'table'              => $def->table,
    'filters'            => $viewer->getFilters(),
    'toolbar'            => $viewer->getToolbarActions(null),
    'viewGroups'         => $viewer->getViewGroups(),
    'numberSeries'       => $viewer->getNumberSeries(),
    'newRecordDefaults'  => $viewer->getNewRecordDefaults(),
]);
```

Pořadí klíčů zachovat takhle (viewGroups → numberSeries → newRecordDefaults),
ať to čte konzistentně s tím, jak je to ve frontend handleru.

### 3. `DocsHeadsViewer` — refactor na `$scopedDocType`

**Soubor:** `modules/docs/core/src/DocsHeadsViewer.php`

#### 3a) Nová property + helper logika

Před existujícími metodami přidat:

```php
/**
 * When set, this viewer is scoped to a single doc_type
 * (e.g. 'invni' for received invoices). Drives the implicit doc_type
 * filter in selectRows(), the bottom number-series tab list, and
 * the default doc_type for newly created records.
 *
 * Generic viewers (cross-type "all documents") leave this null.
 */
protected ?string $scopedDocType = null;
```

#### 3b) `selectRows` — odvodit doc_type filter z property

V dnešní `selectRows` máme handling pro virtuální filter `_doc_type`,
který si tam podtřídy ručně vkládají. Logiku zachováme (kvůli zpětné
kompatibilitě s případnými dalšími voláními), ale když je `$scopedDocType`
nastavený, použijeme jeho hodnotu jako fallback / authoritative:

```php
$docTypeFilter = $this->scopedDocType;
foreach ($filters as $filter) {
    $id = $filter['id'] ?? null;
    if ($id === 'viewGroup') {
        $viewGroup = (string) $filter['value'];
    } elseif ($id === '_doc_type') {
        // Explicit override (e.g. cross-type viewer pinning a type manually).
        $docTypeFilter = (string) $filter['value'];
    } elseif ($id === 'number_series') {
        $numberSeriesFilter = (int) $filter['value'];
    }
}
```

Před cyklem deklarovat `$numberSeriesFilter = null;`. Za blokem s
`$docTypeFilter` (kde se aplikuje `h.doc_type = %s`) přidat:

```php
if ($numberSeriesFilter !== null && $numberSeriesFilter > 0) {
    $conditions[] = 'h.`number_series` = %i';
    $params[] = $numberSeriesFilter;
}
```

#### 3c) `getNumberSeries()` override

Přidat metodu:

```php
/**
 * Bottom-tab number series for this viewer.
 *
 * Returns only series in "V pořádku" state (docState = 40):
 *  - Koncept (10) — řada se ještě nepoužívá pro pořizování dokladů.
 *  - Archivovaná (70) — minulá řada, neukazujeme (její doklady tím
 *    pádem v defaultním viewu nejsou viditelné — vědomá volba).
 *  - Smazaná (90) — pryč.
 *
 * Result is empty for cross-type viewers ($scopedDocType === null) —
 * the generic DocsHeadsViewer (and any subclass that doesn't pin a
 * type) shows no series tabs.
 *
 * @return list<array{id: int, name: string}>
 */
public function getNumberSeries(): array
{
    if ($this->scopedDocType === null) {
        return [];
    }
    $rows = $this->db->fetchAll(
        'SELECT `id`, `name` FROM `docs_core_number_series`'
        . ' WHERE `doc_type` = %s AND `docState` = 40'
        . ' ORDER BY `name` ASC',
        $this->scopedDocType,
    );
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id'   => (int) $row['id'],
            'name' => (string) $row['name'],
        ];
    }
    return $out;
}
```

Pozn.: konzistence s `DocsHeadsFormBase::resolveNumberSeriesOptions()` —
ten dnes bere `docState IN (10, 40, 80)`. To je formová logika („do
které řady smím doklad nově zařadit"), což je širší než tabová logika
(„které řady stojí za to ukázat jako záložku"). Schválně se neunifikujeme
— jiný účel.

#### 3d) `getNewRecordDefaults` v base

V `DocsHeadsViewer` přidat:

```php
public function getNewRecordDefaults(): array
{
    return $this->scopedDocType !== null
        ? ['doc_type' => $this->scopedDocType]
        : [];
}
```

### 4. `ReceivedInvoicesViewer` — zjednodušit

**Soubor:** `modules/docs/invoicesIn/src/ReceivedInvoicesViewer.php`

Po refactoru z kroku 3 redukujeme na pouhou property:

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesIn;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for received invoices (doc_type = 'invni').
 *
 * All behavior — doc_type filter in selectRows, number-series bottom tabs,
 * newRecordDefaults for the create form — is derived in DocsHeadsViewer
 * from $scopedDocType.
 */
class ReceivedInvoicesViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invni';
}
```

`const DOC_TYPE`, override `selectRows()` i `getNewRecordDefaults()` mizí
— jsou nahrazené property.

### 5. `IssuedInvoicesViewer` — stejná zjednodušení

**Soubor:** `modules/docs/invoicesOut/src/IssuedInvoicesViewer.php`

```php
<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\InvoicesOut;

use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Per-type viewer for issued invoices (doc_type = 'invno').
 *
 * All behavior — doc_type filter in selectRows, number-series bottom tabs,
 * newRecordDefaults for the create form — is derived in DocsHeadsViewer
 * from $scopedDocType.
 */
class IssuedInvoicesViewer extends DocsHeadsViewer
{
    protected ?string $scopedDocType = 'invno';
}
```

### 6. `Viewer.svelte` — spodní lišta a integrace

**Soubor:** `frontend/src/components/viewer/Viewer.svelte`

#### 6a) Nový state

Vedle `activeViewGroup`:

```js
// --- Number series tabs (bottom bar) ---
// `null` = no series filter (viewer doesn't expose series or list is empty).
// Otherwise an int matching one of meta.numberSeries[].id.
let activeSeriesId = $state(null);
```

#### 6b) Derived: zda lištu ukázat

```js
let numberSeries = $derived(meta?.numberSeries ?? []);
let hasNumberSeriesTabs = $derived(numberSeries.length > 1);
```

Lišta se ukáže jen když je víc než 1 aktivní řada. Při jedné se filter
stejně aplikuje (přes `activeSeriesId`), ale UI neukazuje — vizuálně by
single-tab nedával smysl.

#### 6c) Default selekce při fetch meta

V `fetchMeta()` po úspěšném načtení doplnit:

```js
async function fetchMeta(viewerId) {
    loadingMeta = true;
    const result = await get(`/_ui/viewer/${viewerId}/meta`);
    if (result?.success) {
      meta = result.data;
      // Vyber první řadu (abecedně) jako default. Pokud viewer žádné
      // řady neexponuje (generic viewer), zůstane null a filter se
      // neaplikuje.
      const series = meta.numberSeries ?? [];
      activeSeriesId = series.length > 0 ? series[0].id : null;
    }
    loadingMeta = false;
}
```

#### 6d) Aplikace filtru v `fetchRowsExplicit`

Upravit signaturu, aby brala explicitní `seriesId`, a v query string ho
přimerg-ovat:

```js
async function fetchRowsExplicit(viewerId, search, viewGroup, seriesId, page, append = false) {
    // ... existing code ...
    let path = `/_ui/viewer/${viewerId}/rows?page=${page}`;
    if (search) {
      path += `&search=${encodeURIComponent(search)}`;
    }
    if (viewGroup && viewGroup !== 'all') {
      path += `&filter[viewGroup]=${encodeURIComponent(viewGroup)}`;
    }
    if (seriesId != null) {
      path += `&filter[number_series]=${encodeURIComponent(seriesId)}`;
    }
    // ... rest unchanged
}
```

Wrapper `fetchRows()` aktualizovat tak, aby předával `activeSeriesId`.
Všechna volání `fetchRowsExplicit(tab.viewerId, ..., 0)` v existujícím
kódu (handleTabClick, handleSearchInput, handleSearchClear,
handleRunDue, refreshAfterAction, handleDetailRefresh, handleScroll,
handleRegistryWizardSaved, submitReanalyze, $effect inicializace,
handleFormSaved) — všude přidat `activeSeriesId` před `page`.

Důležité: v hlavním `$effect` (re-initialize při změně viewerId) se
`activeSeriesId` resetuje na default **až po** `fetchMeta()` (ten ho
nastaví). Volání `fetchRowsExplicit` v `.then(...)` musí použít už
naplněnou hodnotu — pojďme to spojit tak, že fetchRowsExplicit v
$effect odložíme až po fetchMeta:

```js
$effect(() => {
    const viewerId = tab.viewerId;

    meta = null;
    rows = [];
    selectedRowId = null;
    detail = null;
    detailToolbar = [];
    activeSearch = '';
    activeViewGroup = 'active';
    activeSeriesId = null;
    pageNumber = 0;
    hasMore = false;

    if (searchInputEl) {
      searchInputEl.value = '';
    }

    const pendingRecord = navigationStore.consumePendingRecordId();

    // Sequence: meta first (sets activeSeriesId), then rows with that filter,
    // then optional pending record detail.
    fetchMeta(viewerId).then(() => {
      fetchRowsExplicit(viewerId, '', 'active', activeSeriesId, 0).then(() => {
        if (pendingRecord != null) {
          selectedRowId = pendingRecord;
          fetchDetail(pendingRecord);
        }
      });
    });
});
```

#### 6e) Click handler pro series tab

```js
function handleSeriesTabClick(seriesId) {
    if (seriesId === activeSeriesId) return;
    activeSeriesId = seriesId;
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, seriesId, 0);
}
```

#### 6f) Předvyplnění při create

V `handleToolbarAction('create')`:

```js
if (actionId === 'create') {
    editRecordId = null;
    // Per-type viewers (e.g. issued/received invoices) expose
    // newRecordDefaults so the form can pre-fill doc_type. On top of
    // that, when a specific number series is selected as a bottom tab,
    // pre-fill that series too so the user doesn't have to choose it
    // again in the form.
    const base = meta?.newRecordDefaults ?? {};
    formDefaultData = activeSeriesId != null
        ? { ...base, number_series: activeSeriesId }
        : base;
    formOpen = true;
}
```

#### 6g) Markup — lišta na dně list-panelu

V `.shpd-viewer__list-panel` **za** `.shpd-viewer__rows`:

```svelte
{#if hasNumberSeriesTabs}
  <div class="shpd-viewer__series-tabs">
    {#each numberSeries as ns (ns.id)}
      <button
        class="shpd-viewer__series-tab"
        class:shpd-viewer__series-tab--active={activeSeriesId === ns.id}
        onclick={() => handleSeriesTabClick(ns.id)}
        type="button"
      >
        {ns.name}
      </button>
    {/each}
  </div>
{/if}
```

#### 6h) Styly

V `<style>` na konec před spinner blok:

```css
/* Spodní lišta záložek číselných řad. Ortogonální k viewGroup tabům
 * nahoře — viewGroup filtruje docState, series filtruje number_series.
 *
 * V 400px-wide list-panelu se 4+ řad začne tísnit, proto horizontální
 * scroll s jemným odsazením. Žádné wrapping — pořád jedna řádka. */
.shpd-viewer__series-tabs {
    display: flex;
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
}

.shpd-viewer__series-tab {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border: none;
    border-top: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: color 0.12s, border-color 0.12s;
}

.shpd-viewer__series-tab:hover {
    color: var(--shpd-color-text);
}

.shpd-viewer__series-tab--active {
    color: var(--shpd-color-primary);
    border-top-color: var(--shpd-color-primary);
    font-weight: 600;
}
```

Border-top (ne -bottom) zarovnává aktivní indikátor s horní hranou lišty
— vizuálně tak „zespoda navazuje" na seznam řádků.

### 7. Dokumentace — `docs/frontend.md`

**Soubor:** `docs/frontend.md`, sekce **7. Viewer systém**.

Doplnit popis `numberSeries` v meta endpoint kontraktu:

```
`numberSeries` (list, optional) — pole `{id, name}` aktivních číselných
řad pro tento viewer (jen řady ve stavu V pořádku, docState = 40). Per-type
viewery (`ReceivedInvoicesViewer`, `IssuedInvoicesViewer`) ho exponují
přes `getNumberSeries()` v base třídě `DocsHeadsViewer` — odvozeno
z property `$scopedDocType`. Cross-type viewery vrací prázdné pole.
Frontend z toho vykreslí spodní lištu záložek, když je řad víc než
jedna. Při kliku na záložku se posílá filter `number_series=<id>`;
při vytváření dokladu se id přimerg-uje do `formDefaultData`.
```

### 8. Dokumentace — `docs/docs-mvp.md`

**Soubor:** `docs/docs-mvp.md`, sekce **11. Implementační plán → Fáze 6**.

V seznamu úkolů Fáze 6 odškrtnout / poznamenat, že spodní taby s řadami
jsou hotové. Najít řádek

```
- Spodní taby s číselnými řadami (jako v screenshotu od Davida)
```

a doplnit za něj `— ✓ viewer-number-series-tabs.md`.

### 9. `CLAUDE.md` (volitelné)

Pokud bude potřeba zmínit refaktor `$scopedDocType` (sjednocení per-type
viewerů), přidat krátkou poznámku do sekce **Frontend — Viewers** nebo
**Architektura — rychlý přehled**. Pokud to ale plyne organicky z kódu,
nepřidávat — `CLAUDE.md` nemá zaplnit každý detail.

## Akceptace

- `cd frontend && npm run build 2>&1` projde bez chyb / warningů
- `vendor/bin/phpunit 2>&1` projde
- Manuální smoke test v dev DS (`http://{ip}/{ds-id}/app/`):
  - **Faktury přijaté** otevřít → po `ds-upgrade` je jen defaultní řada,
    spodní lišta se **neukáže** (1 řada). Filter `number_series=<id>`
    se ale tiše aplikuje.
  - V Nastavení → Číselné řady přidat druhou řadu typu `invni` ve stavu
    V pořádku (např. „FPB tuzemsko" + původní). Zpět na Faktury přijaté
    → spodní lišta se objeví, obsahuje obě záložky.
  - Klik na druhou záložku → seznam řádků se přefiltruje (žádné doklady
    nebo jen ty z této řady).
  - Klik na *Přidat* z aktivní druhé záložky → formulář nové FPB má
    předvyplněnou tuto řadu v selectu `Číselná řada`.
  - Stejně pro **Faktury vydané** (po dodání aspoň druhé řady typu `invno`).
  - **Generický viewer Dokladů** (pokud je v sidebaru exponovaný) → lišta
    se neukáže ani s víc řadami napříč typy.
  - Třetí řadu archivovat (přepnout `docState = 70`) → ihned zmizí ze
    spodní lišty, ale stávající doklady této řady už nikde nejsou vidět
    (vědomá volba).
  - Hledání / přepnutí viewGroup (Aktivní → Archiv → Vše) zachová
    aktivní záložku řady. Po refreshi stránky se výchozí záložka znovu
    nastaví na první abecedně.

## Rozhodnutí k designu (potvrzená)

- ✓ **Žádný „Vše" tab** — vždy je vybraná konkrétní řada. Pro hledání
  napříč řadami slouží fulltext (search bar nad seznamem).
- ✓ **Zobrazujeme jen řady ve stavu V pořádku (`docState = 40`).** Koncepty
  ani archivované řady v lištách nejsou.
- ✓ **Doklady patřící do archivovaných řad nejsou ve standardním viewru
  vidět.** Vědomá volba — uživatel archivuje řadu typicky až když ji
  přestal používat, a historické doklady se k němu dostane jinou cestou
  (přímý odkaz, hledání). Pokud se ukáže, že tohle bolí, dodáme později
  speciální „Archiv řad" tab nebo viewGroup variantu.
- ✓ **Lišta se schová, když je jen jedna řada.** Filter se ale stejně
  aplikuje (konzistence: vždy je doklad zobrazen v kontextu konkrétní
  řady).
- ✓ **Default = první řada abecedně** (podle `name` ASC). Žádná persistence
  per uživatele zatím.
- ✓ **Jen `name` v záložce.** Žádný počet dokladů ani odznáček — dodáme
  později, pokud bude poptávka.
- ✓ **Horizontální scroll při overflow** (4+ řady v 400px panelu). Žádné
  wrapping, žádné truncation.
- ✓ **Záložka aktivního stavu indikovaná `border-top`** (ne -bottom),
  aby vizuálně navazovala zespoda na seznam dokladů.
- ✓ **Opt-in přes `$scopedDocType` v `DocsHeadsViewer`.** Refactor zjednoduší
  `ReceivedInvoicesViewer` / `IssuedInvoicesViewer` na jeden řádek property.
  Mizí `const DOC_TYPE` a duplikované overridy `selectRows` /
  `getNewRecordDefaults` — všechno se odvodí z property v base třídě.
- ✓ **Sidebar struktura nezměněna.** Jedna položka „Faktury přijaté"
  v sidebaru, taby řad jen *uvnitř* vieweru. Per-series sidebar leaves
  nejsou v rozsahu.
- ✓ **Filter `number_series` v query stringu** jako `filter[number_series]=<id>`
  — konzistentní s existujícími filtry (viewGroup atd.), pasuje do
  `selectRows($filters)` smlouvy.
- ✓ **Při create se aktivní řada přimerg-uje do `formDefaultData`** vedle
  `doc_type`. Form (`DocsHeadsFormBase::applyClientDefaults`) už dnes
  předvyplnění `number_series` umí — jen mu ho explicitně předáme.

## Mimo rozsah / nezasahujeme

- `docs_core_number_series` schema, `NumberSeriesProvisioner`,
  `NumberSeriesDocument`, `NumberSeriesForm`, `NumberSeriesViewer`
  (správa řad v Nastavení) — beze změny.
- `DocsHeadsFormBase::resolveNumberSeriesOptions()` — beze změny.
  Formová logika (Koncept + V pořádku + V opravě) je širší než tabová
  (jen V pořádku) a má jiný účel.
- `getViewGroups()` mechanismus — beze změny, jen ho doplňujeme.
- `assignDocumentNumber` / atomické generování čísla dokladu — beze
  změny (Fáze 4 docs MVP, není součástí tohoto tasku).
