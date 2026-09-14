# Shipard — Tabulkový layout vieweru (grid)

**Designový dokument.** Grid = druhý prezentační režim viewer systému:
klasická tabulka se sloupci, sticky hlavičkou a nekonečným skrolováním.
Cílové use-casy: účetní deník, bankovní transakce, saldokonto — obecně
data, kde řádek = záznam s mnoha souřadnými hodnotami a list formát
(t1/t2/t3) je málo hustý.

> **Stav:** Rozhodnutí D1–D12 uzavřena. **Fáze 1 hotová (2026-07-19)** —
> infrastruktura (TableViewer grid metody, ViewerController layout/meta/
> footer, `ViewerGrid.svelte`, `ViewerDetailDrawer.svelte`, sdílené
> `viewerSpans.js` + `SpanBadge.svelte`) + pilot `JournalViewer`
> (grid default, footer Σ MD/DAL); zadání `tasks/viewer-grid-phase1.md`.
> **Fáze 2 hotová (2026-07-19)** — řazení (`setSort` +
> `buildSortedOrderBy`, sort UI), toggle list ↔ grid s localStorage
> persistencí (`utils/viewerLayout.js`), pilot `BankTransactionsViewer`
> + sortable sloupce deníku; zadání `tasks/viewer-grid-phase2.md`.
> Následuje saldokonto grid (kontrakt D12, vstup do accbal Fáze 4).

---

## 1. Motivace a princip

Starý Shipard měl ~3 varianty tabulkového prohlížeče (`TableViewGrid`
vedle `TableView`, plus editační odnože) — grid byl **samostatná třída**,
takže read-only vs. editovatelné prohlížeče se rozešly v hierarchii
a přepínání list ↔ tabulka nebylo kam zavěsit.

Nový design to obrací: **layout je prezentační režim jednoho vieweru,
nikdy typ vieweru** (D1). Všechno doménové a drahé je sdílené a
layout-agnostické:

| Sdílené (beze změny)              | Per-layout                       |
|-----------------------------------|----------------------------------|
| `selectRows()` — SQL, filtry, search, stránkování | `renderRow()` → list (t1/i1/t2/i2/t3) |
| `getFilters()`, `getViewGroups()`, `getNumberSeries()` | `renderGridRow()` → grid (cells) |
| `getToolbarActions()`, formuláře, `renderDetail()` | `getGridColumns()` — deklarace sloupců |

Důsledky:

- **Read-only vs. editovatelný grid není architektonická otázka.**
  Editovatelnost už dnes řídí `getToolbarActions()` + `FormDialog`;
  read-only viewer (deník) má prázdný toolbar, editovatelný má akce +
  dvojklik. Grid na tom nic nemění.
- **Přepínání layoutů** (Fáze 2) = viewer implementuje oba renderery,
  meta vrátí `layouts: ["grid", "list"]`, frontend ukáže toggle. Přepnutí
  je refetch stejných dat s jiným `layout` parametrem.
- **`renderRow()` zůstává povinný pro všechny viewery** (D2) — je to
  mobilní formát. Na ≤ 768 px grid viewer automaticky degraduje na list
  layout; grid-only viewer neexistuje.

## 2. Rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Layout (`list` / `grid`) je prezentační režim jednoho vieweru, nikdy samostatná třída. `selectRows`/filtry/search/detail/toolbar sdílené. |
| D2 | `renderRow()` zůstává abstraktní/povinný; na mobilu grid degraduje na list. |
| D3 | Grid aktivuje implementace `getGridColumns()` + `renderGridRow()`; `rows` endpoint má `layout` parametr; meta vrací `layouts` + `grid`. Formátování hodnot (peníze, data) dělá backend. |
| D4 | Detail v grid layoutu jako slide-over drawer zprava se stávající `ViewerDetail` komponentou. Upřesnění: **non-modální** (bez ztmavení, klik na jiný řádek přepíná detail) — viz §6. |
| D5 | Buňka = stávající span formát + nová badge varianta `{text, badge: style}`; badge se zavádí i pro list řádky. |
| D6 | Skupinové řádky: řádek nese `group: {key, label}`, hlavičky vkládá frontend při změně klíče — bezstavové přes stránky infinite scrollu. |
| D7 | Volitelné součty `renderGridFooter()` — agregační SQL přes **celý filtrovaný set** (ne jen načtené řádky), posílané s page 0. |
| D8 | Fázování: F1 infrastruktura + pilot `JournalViewer` (vč. footeru); F2 řazení + `BankTransactionsViewer` + toggle; saldokonto grid jako vstup do accbal Fáze 4. |
| D9 | Řazení (F2): sort injektuje controller přes `setSort()` — signatura `selectRows()` se NEmění (20+ implementací). Whitelist = sloupce se `sortable: true`; helper `buildSortedOrderBy()`; frontend cyklus asc → desc → výchozí; sort se posílá jen v grid layoutu. |
| D10 | Toggle (F2): ikona vedle searche (desktop, `layouts.length > 1`); persistence v localStorage per-DS klíč `shpd_viewer_layout` (mapa viewerId → layout); priorita persisted > `defaultLayout`; mobil dál vynucuje list. |
| D11 | Bank grid (F2): default grid; **bez footeru** (SUM přes mix měn je nesmysl); částka + měna v jedné buňce (dva spany); stav = proužek (docStateMain) + badge sloupec Zaúčtování. |
| D12 | Kontrakt skupin: viewer s `group` MUSÍ řadit primárně podle skupinového klíče (`{#each}` klíčuje hlavičky přes `group.key` — nesouvislá skupina = duplicitní klíč = pád renderu). Řazení klikem se u skupinových viewerů omezí na sloupce, které clustering zachovávají. |

## 3. Backend

### 3.1 `TableViewer` — nové metody

Vše s defaulty; existující viewery se nemění a zůstávají list-only.

```php
/**
 * Deklarace sloupců grid layoutu. Null = viewer grid nepodporuje.
 * @return list<array{id: string, label: string, width?: int,
 *                    align?: 'left'|'right', grow?: bool}>|null
 */
public function getGridColumns(): ?array { return null; }

/** Volitelné grid opce. Podporované klíče: showIndex (default true). */
public function getGridOptions(): array { return []; }

/**
 * Render řádku pro grid layout. Volá se jen když getGridColumns() !== null.
 * @return array{id: int, cells: array<string, mixed>, stateStyle?: ?string,
 *               rowClass?: ?string, group?: ?array{key: string, label: string}}
 */
public function renderGridRow(array $rowData): array { return []; }

/** 'list' | 'grid'. Grid se uplatní jen když ho viewer podporuje. */
public function getDefaultLayout(): string { return 'list'; }

/**
 * Volitelný součtový footer — agregace přes CELÝ filtrovaný set
 * (samostatný SELECT SUM(...) se stejným WHERE jako selectRows()).
 * Vrací mapu columnId => hodnota buňky (span formát), nebo null.
 * Controller volá jen pro layout=grid a page 0.
 */
public function renderGridFooter(?string $search, array $filters): ?array { return null; }
```

### 3.2 Definice sloupce

Záměrně minimální — **žádné typy, žádné formátovací direktivy**. Hodnoty
formátuje viewer v `renderGridRow()` (stejně jako dnes `renderRow()` přes
`formatMoney`/`formatDate`), frontend zůstává hloupý (server-driven UI).

| Klíč | Význam |
|---|---|
| `id` | Klíč do `cells` mapy. |
| `label` | Hlavička sloupce — lokalizovaná backendem (per-viewer, stejný vzor jako group titles v `renderDetail()`). |
| `width` | Volitelná šířka v px. Bez `width` a `grow`: auto. |
| `align` | `'right'` = číselný sloupec (zarovnání + `tabular-nums`, hlavička i buňky) — konzistentní s `table` content typem v detailu. |
| `grow` | `true` = flexibilní sloupec, bere zbylou šířku (typicky Text). Víc grow sloupců → dělí se rovným dílem. |

### 3.3 Formát grid řádku

```json
{
    "id": 815,
    "stateStyle": null,
    "rowClass": "error",
    "group": {"key": "p42", "label": "AKIMA, spol. s r.o."},
    "cells": {
        "accounting_date": "10.04.2017",
        "doc_number":      {"text": "3111700001", "class": "primary"},
        "money_dr":        {"text": "10 000,00", "class": "amount"},
        "partner_name":    "Ing. Mitrenga Libor",
        "labels":          [{"text": "Chyba", "badge": "danger"}]
    }
}
```

- `cells` — mapa columnId → hodnota buňky. Hodnota = **stejný span formát
  jako t1/t2/…**: string, `{text, class?, badge?}`, nebo pole spanů.
  Chybějící/null klíč = prázdná buňka.
- `stateStyle` — levý stavový proužek na `<tr>` přes stejné `docState_*`
  třídy jako v listu (konzistence; výběr přepíše na accent, viz
  design-system.md §4). Viewery s docStates fungují beze změny.
- `rowClass` — klasifikace řádku mimo doc-state systém, stejný slovník
  jako `_class` v detail `table` contentu: `error` (červené podbarvení).
  `total` v datovém streamu nedává smysl — součty řeší footer.
- `group` — viz §5. Null/chybí = řádek bez skupiny.
- Ikona se v gridu nerenderuje (na rozdíl od listu) — stav nese proužek,
  typ záznamu je v gridu zřejmý z kontextu vieweru.

### 3.4 Span formát — badge rozšíření (D5)

Span objekt se rozšiřuje o `badge: <style>` — místo barevného textu se
vykreslí **pilulka** s paletou badge systému (`neutral`, `primary`,
`accent`, `success`, `warning`, `danger` + doc-state styly, viz
design-system.md §5). `class` a `badge` se vylučují; `badge` má přednost.

Platí pro **oba layouty** — normalizace spanů se vytahuje z
`ViewerRow.svelte` do sdílené utility a badge tak dostávají i list řádky
(úkoly: štítky Vylepšení / Chyba / projekt přímo v t2).

### 3.5 `ViewerController` + meta

**`GET /_ui/viewer/{id}/meta`** — nové klíče:

```json
{
    "layouts": ["grid", "list"],
    "defaultLayout": "grid",
    "grid": {
        "columns": [{"id": "accounting_date", "label": "Datum", "width": 96}, "…"],
        "showIndex": true
    }
}
```

- `layouts` — odvozeno: `list` vždy; + `grid` když `getGridColumns()
  !== null`. List-only viewer: `["list"]`, `grid` klíč chybí.
- `defaultLayout` — z `getDefaultLayout()`, validováno proti `layouts`
  (nepodporovaná hodnota → `list`).

**`GET /_ui/viewer/{id}/rows`** — nový parametr `layout` (default
`list`):

- `layout=list` (nebo chybí) → `renderRow()` — stávající chování,
  beze změny tvaru.
- `layout=grid` → `renderGridRow()`; odpověď:

```json
{"rows": ["…grid řádky…"], "hasMore": true, "footer": {"money_dr": "…"} }
```

- `footer` — jen když `page === 0` a `renderGridFooter()` vrátí non-null;
  na dalších stránkách klíč chybí (frontend si drží footer z page 0).
  Footer dostává **stejné** `$search`/`$filters` jako `selectRows()`
  (včetně viewGroup a number_series, které controller předává jako
  filter položky).
- `layout=grid` na vieweru bez gridu → `400 LAYOUT_NOT_SUPPORTED`
  (guard; meta-driven frontend to nikdy nepošle).

`detail` endpoint beze změny — detail je layout-agnostický.

## 4. Frontend

### 4.1 `Viewer.svelte` — orchestrace

Zůstává orchestrátorem; taby viewGroups, search, `ViewerFilters`,
číselné řady, infinite scroll, formuláře, mobile top bar se **nemění**.
Přibývá:

- `activeLayout` state — inicializace po fetchMeta:
  `meta.defaultLayout`. **Efektivní layout** =
  `layoutStore.isMobile ? 'list' : activeLayout` — mobil vždy list (D2).
- `fetchRowsExplicit()` dostává `layout` parametr (stejná disciplína
  explicitních parametrů, žádné čtení $state v $effect) a posílá
  `&layout=grid`. Tvary řádků obou layoutů jsou různé → **změna
  efektivního layoutu = reset page + refetch** (v F1 nastává jen při
  resize přes breakpoint; v F2 přibude toggle).
- Render větvení: efektivní layout `grid` → `<ViewerGrid>`; `list` →
  stávající `ViewerRow` seznam. Na mobilu se nic nemění (list/detail
  přepínání, top bar).
- Toolbar v grid layoutu zůstává v list kontextu (`meta.toolbar`) —
  detail akce žijí v draweru (§6).

### 4.2 `ViewerGrid.svelte`

- `<table>` uvnitř scroll containeru (vertikální infinite scroll stejným
  `onscroll` mechanismem; horizontální overflow-x auto pro úzká okna).
- Sticky `<thead>` (`position: sticky; top: 0`), sticky footer řádek
  (`bottom: 0`) když footer existuje.
- Kompaktní jednořádkové řádky (~34 px, font-size sm); `align: right`
  sloupce s `tabular-nums`.
- Pořadové číslo `#` — frontend-computed z pozice v poli (jako list),
  vlastní úzký sloupec, řídí `grid.showIndex`.
- Levý stavový proužek na řádku přes `docState_*`; výběr = accent proužek
  + `bg-selected` (stejné tokeny jako `ViewerRow`).
- Klik na řádek → `handleRowClick` (výběr + fetch detail + drawer).
  Dvojklik → `edit` akce, pokud ji toolbar nabízí (vzor `TableBrowser`).
- Buňky renderuje přes sdílenou span utilitu (§4.3), včetně badge.

### 4.3 Sdílená normalizace spanů

`normalizeSpans()` se přesouvá z `ViewerRow.svelte` do
`frontend/src/components/viewer/viewerSpans.js`; badge rendering jako
sdílený snippet/komponenta. Používají `ViewerRow` (beze změny chování +
badge), `ViewerGrid`.

## 5. Skupinové řádky (D6)

Starý `groupName` vkládal skupinové hlavičky na serveru — láme se na
hranicích stránek (server je per-page bezstavový). Nově:

- Každý grid řádek nese volitelné `group: {key, label}`.
- **Hlavičku vkládá frontend**, když se `group.key` liší od předchozího
  řádku v načteném poli (řádky bez group → žádná hlavička). Deterministické
  přes libovolné stránkování, server nic neví.
- Render: `<tr class="…group"><td colspan="všechny">label</td></tr>`.
  Pořadová čísla běží přes skupiny průběžně (jako list).

První konzument: saldokonto (skupina = partner). `JournalViewer` group
nepoužívá (plochý chronologický seznam) — F1 dodává jen frontend logiku.

**Kontrakt řazení (D12):** řádky MUSÍ přicházet seřazené primárně podle
skupinového klíče — `{#each}` klíčuje hlavičky přes `group.key`, takže
nesouvislá skupina by vytvořila duplicitní klíč a shodila render. Sloupce,
jejichž řazení by clustering rozbilo, nesmí být `sortable`.

## 6. Detail drawer (D4)

Grid potřebuje plnou šířku, ale detail je hodnotný (deník: celý zápis +
odkaz na zdrojový doklad). Řešení: **non-modální slide-over panel
zprava** (~560 px):

- Klik na řádek vysune drawer; uvnitř stávající `ViewerDetail`
  (props `detail`/`loading`/`onRefresh`/`onAction` beze změny — je už
  plně odstíněná).
- **Bez overlay ztmavení** — grid zůstává plně interaktivní; klik na
  jiný řádek přepne detail v otevřeném draweru (zachovává dnešní
  „proklikávání" záznamů z list/detail režimu). Stín + border oddělí
  drawer od gridu.
- Hlavička draweru: akce z `detailToolbar` (filtr `create` jako
  v mobile top baru — Přidat patří jen seznamu) + zavírací ✕.
- Zavření: ✕, Esc, výběr se zruší (`selectedRowId = null`). Esc musí
  koordinovat s Modal stackem — když je nad drawerem otevřený modál
  (FormDialog z detail akce), Esc zavírá modál, ne drawer.
- Mobil drawer nepoužívá — tam platí stávající list/detail full-width
  přepínání.

## 7. Fáze 2 — řazení, toggle, BankTransactionsViewer

### 7.1 Řazení klikem na hlavičku (D9)

**Backend:**

- Sloupec v `getGridColumns()` dostává volitelné `sortable: true`.
  Frontend třídicí UI ukáže jen na těchto sloupcích.
- `rows` endpoint: nový parametr `sort=<colId>:<asc|desc>`. Controller ho
  bere v úvahu **jen pro `layout=grid`**; validuje colId proti sortable
  sloupcům a směr proti whitelistu — nevalidní hodnota se tiše ignoruje
  (padá na výchozí řazení vieweru).
- **Signatura `selectRows()` se nemění** (20+ existujících implementací;
  PHP nedovoluje přidat parametr abstraktní metodě bez rozbití potomků).
  Controller místo toho volá `$viewer->setSort(['column' => …, 'dir' =>
  …])` před `selectRows()`; `TableViewer` drží `protected ?array $sort`.
- Helper pro viewery:

```php
/**
 * ORDER BY klauzule respektující aktivní sort. $columnMap mapuje colId
 * na SQL výraz (grid id ≠ nutně sloupec — např. partner_name →
 * p.`full_name`). Bez aktivního sortu (nebo mimo mapu) vrací $default.
 * Unikátní tail (typicky qualifikované `id`) se připojuje VŽDY —
 * pravidlo deterministického stránkování.
 */
protected function buildSortedOrderBy(array $columnMap, string $default, string $uniqueTail): string
```

- Řazení nemá vliv na footer (agregace je na pořadí nezávislá).

**Frontend (`ViewerGrid` + `Viewer.svelte`):**

- Klik na sortable hlavičku cykluje **asc → desc → výchozí** (bez sortu);
  indikátor ↑ / ↓ v hlavičce.
- `activeSort` state ve `Viewer.svelte` (`{column, dir} | null`), nový
  explicitní parametr `fetchRowsExplicit`; změna sortu = reset page +
  refetch (výběr a drawer zůstávají — řazení nemění identitu záznamů).
- Sort **přežívá** změnu viewGroup tabu / filtrů / hledání (je
  ortogonální); resetuje se při přepnutí vieweru a při přepnutí layoutu
  na list (list má vlastní pevné řazení).

### 7.2 Toggle layoutů (D10)

- Ikona vedle searche (desktop only), viditelná když
  `meta.layouts.length > 1`; přepíná `activeLayout` list ↔ grid —
  refetch řeší stávající layout-change `$effect`.
- **Persistence:** localStorage klíč `shpd_viewer_layout` (per-DS přes
  `storageKey` vzor — `DATA_SOURCE_ID` z `api/config.js`), hodnota JSON
  mapa `{viewerId: 'list'|'grid'}`. Malý modul
  `frontend/src/utils/viewerLayout.js` (get/set, try/catch okolo
  localStorage). Server-side persistence (user settings) až bude potřeba.
- Inicializace `activeLayout` po fetchMeta: **persisted > `meta.defaultLayout`**
  (persisted hodnota mimo `meta.layouts` se ignoruje).
- Mobil beze změny — efektivní layout vynucuje list, toggle se nerenderuje.

### 7.3 Pilot: `BankTransactionsViewer` (D11)

První editovatelný grid — editace beze změny přes stávající mechaniku
(toolbar Open na výběru, dvojklik → edit, drawer akce, stavové přechody
ve FormDialogu). Grid default, list přes toggle.

- Sloupce: Datum (`date_transaction`, 96, sortable) · Částka (`amount`,
  right, 130, sortable — znaménko dle `direction`, druhý span kód měny
  muted) · Protistrana (`counterparty_name`, grow) · Partner
  (`partner_name`) · VS (`payment_reference`, 120) · Operace
  (`operation`, label z cfgItem) · Zaúčtování (badge: `accounting_state`
  1 → success „zaúčtováno“, 2 → danger „chyba účtování“, jinak prázdné).
- `stateStyle` z `docStateMain` (jako `renderRow()`) → stavový proužek;
  viewGroup taby fungují beze změny.
- **Bez footeru** — SUM přes mix měn nedává smysl. Případný per-měna
  součet až s měnovým filtrem (mimo scope).
- Výchozí řazení zůstává `docStateMain ASC, date_transaction DESC, id
  DESC`; sortable sloupce mapují na `t.`-qualifikované výrazy.

### 7.4 Další výhled (mimo F2)

- ~~**Saldokonto grid**~~ — hotovo: `LedgerViewer` (pohyby, skupiny per
  partner, footer v HC) a `CasesViewer` (případy, #69 D1 — skupinový
  řádek nese součet zůstatku partnera přes filtrovaný set spočítaný
  oknem `SUM() OVER (PARTITION BY partner)` v labelu skupiny; footer
  Σ předpisy / úhrady / zůstatek). Viz `accbal.md` §3.4.
- CSV export, cell akce (klikatelný doklad v buňce), inline editace
  buněk — server-driven `cells` formát nic z toho neblokuje.

## 8. Vztah k `TableBrowser`

`TableBrowser` zůstává zero-config fallback čistě z `_meta/tables`
(stránkovaný, generický). Grid layout je **kurátorovaný** — sloupce,
formátování a filtry deklaruje viewer. Dlouhodobě může grid TableBrowser
vytlačit (auto-generovaný grid viewer z metadat), ale to není cíl této
práce.

## 9. Soubory

| Soubor | Role |
|---|---|
| `src/Core/Viewer/TableViewer.php` | Nové metody §3.1 |
| `src/Api/Controller/ViewerController.php` | `layout` parametr, meta klíče, footer |
| `frontend/src/components/viewer/Viewer.svelte` | `activeLayout`, refetch, render větvení, drawer stav |
| `frontend/src/components/viewer/ViewerGrid.svelte` | **Nový** — tabulka |
| `frontend/src/components/viewer/ViewerDetailDrawer.svelte` | **Nový** — slide-over |
| `frontend/src/components/viewer/viewerSpans.js` | **Nový** — sdílené spany + badge |
| `frontend/src/components/viewer/ViewerRow.svelte` | Používá sdílenou utilitu, badge |
| `modules/economy/accounting/src/JournalViewer.php` | Pilot (F1) |
| `frontend/src/utils/viewerLayout.js` | **Nový (F2)** — persistence volby layoutu |
| `modules/economy/bank/src/BankTransactionsViewer.php` | Pilot F2 — první editovatelný grid |
