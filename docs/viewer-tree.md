# Shipard — Stromový layout vieweru (tree)

Tree = třetí prezentační režim viewer systému vedle seznamu a tabulky
([`viewer-grid.md`](viewer-grid.md)): odsazené řádky s rozbalováním nad
tabulkou, která má sloupec rodiče. Design: issue #169 (D8–D12, D20–D25),
zadání `tasks/viewer-tree-layout.md`. Pilot jsou **Místa**
([`places.md`](places.md)); později skladová místa, zakázky
s podzakázkami apod.

---

## 1. Princip

Stejně jako grid je strom **layout jednoho vieweru, ne jiná třída**.
Filtry, hledání, záložky stavů, toolbar, formuláře a detail jsou sdílené;
strom přidává jen vlastní výběr řádků a jejich render.

| Sdílené (beze změny) | Jen strom |
|---|---|
| `getFilters()`, `getViewGroups()`, `getToolbarActions()` | `getTreeOptions()` — sloupec rodiče a sloupce |
| formuláře, `renderDetail()` | `selectTreeRows()`, `selectTreeRowsByIds()` — výběr bez stránkování |
| `renderRow()` — seznam zůstává druhým layoutem | `compareTreeRows()`, `renderTreeRow()` |

Tři věci strom od seznamu a gridu odlišují:

- **Nestránkuje se.** Celý strom přijde v jedné odpovědi; `selectRows()`
  se stránkováním v kontraktu se nevolá a jeho signatura se nemění.
- **Ukazuje i řádky, které filtrem neprošly** — předky nalezených uzlů
  jako kontext (§ 4).
- **Řadí PHP, ne SQL.** Předci přicházejí druhým dotazem; řazení v dotazu
  by je postavilo za ostatní sourozence.

## 2. Rozhodnutí

| # | Rozhodnutí |
|---|---|
| D8 | Layout `tree` v obecném vieweru: deklarace sloupce rodiče, celý strom v jedné odpovědi, odsazené řádky s rozbalováním, detail vždy v nemodálním draweru. |
| D9 | Pevné řazení podle pořadí sourozenců (sloupce se neřadí); rozbalení si pamatuje prohlížeč; Rozbalit vše / Sbalit vše; hledání ukáže shody i předky. |
| D10 | Přidat v liště = záznam nejvyšší úrovně; na řádku a v detailu akce Přidat podřízené (`open_form` s `preset`). |
| D20 | Řádek stromu má tvar řádku gridu (`cells`) + `parent`, `depth`, `childCount`, `match`. Pořadí, hloubku a předky skládá `TreeRowsBuilder`. Telefon kreslí první buňku a pod ní ostatní spojené ` · `. |
| D21 | Strom ukazuje uzly, které projdou filtry a hledáním, a jejich předky jako kontext (`match: false`, tlumeně, klikatelní) — i v Archivu a Koši. |
| D22 | Rozbalení se pamatuje v prohlížeči per zdroj dat a viewer; bez uloženého stavu jsou rozbalené kořeny. Při hledání, vlastním filtru nebo jiné záložce stavů je strom dočasně rozbalený celý a nic se neukládá. |
| D24 | Seznam zůstává druhým layoutem přes přepínač. Telefon kreslí strom, když je zvolený; grid dál degraduje na seznam. |
| D25 | Víc než 5 000 vybraných uzlů → strom se nevykreslí, odpověď nese `tooMany`. Strom se nikdy neořezává napůl. |
| D26 | Odkaz na záznam téhož vieweru v detailu (Podřízená místa) vybere záznam ve stromu a přepne drawer — akce `select_row`. Když záznam mezi načtenými řádky není, otevře se read-only náhled jako `open_detail`. |

## 3. Backend

### 3.1 `TableViewer`

Všechno volitelné — viewer bez stromu nic neimplementuje.

```php
/** Null = viewer strom nepodporuje. */
public function getTreeOptions(): ?array
// ['parentColumn' => 'parent',
//  'columns' => [{id, label, width?, align?, grow?}, …],  // tvar sloupců gridu, první = stromový
//  'maxNodes' => 5000]                                    // volitelné, výchozí TREE_MAX_NODES

/** Vybrané uzly: filtr stavů, vlastní filtry a hledání. BEZ stránkování
 *  a řazení, nejvýš maxNodes + 1 řádků. Raw řádky nesou id a sloupec rodiče. */
public function selectTreeRows(?string $search, array $filters): array

/** Raw řádky podle id bez ohledu na filtry — předci jako kontext. */
public function selectTreeRowsByIds(array $ids): array

/** Pořadí sourozenců. Výchozí: podle id. */
public function compareTreeRows(array $a, array $b): int

/** Render uzlu: {id, cells, icon?, stateStyle?, rowAction?}. */
public function renderTreeRow(array $rowData): array
```

`getDefaultLayout()` smí vracet `'tree'`. Sloupce mají tvar sloupců gridu
(`viewer-grid.md` § 3.2) bez `sortable` — strom se klikem neřadí. Řádek
nad strop (`maxNodes + 1`) slouží jen k poznání, že je uzlů příliš.

`rowAction` je nejvýš jedna akce ve formátu akcí detailu (`id`, `label`,
`icon`, `kind`, `target` — `frontend.md` → Akce detailu); kreslí se jako
ikonové tlačítko na konci řádku. Dnes frontend umí `kind: 'open_form'`.

### 3.2 `TreeRowsBuilder`

`src/Core/Viewer/TreeRowsBuilder.php` — čistá třída bez databáze:

```php
TreeRowsBuilder::build(array $selected, callable $loadByIds, string $parentColumn, callable $compare): array
// → [{row, parent: ?int, depth: int, childCount: int, match: bool}, …] v pořadí průchodu do hloubky
```

1. vybrané řádky = `match: true`
2. **předci po vrstvách**: chybějící rodiče se dotahují přes callback,
   dokud nějací chybí; každé id se žádá nejvýš jednou, strop
   `MAX_DEPTH = 100` vrstev. Dotažení = `match: false`
3. rodič, který neexistuje ani po dotažení (nebo `0` / `null`) → kořen
4. děti seřazené komparátorem, při shodě podle id; průchod do hloubky bez
   rekurze s navštívenými id; `childCount` = počet dětí **v sestaveném
   stromu**, ne v databázi
5. **cyklus v datech** nic neshodí: uzly nedosažitelné z kořenů se vydají
   jako další kořeny, každý cyklus jednou od uzlu, který na něm leží —
   uzel zavěšený pod cyklem tak zůstane pod svým rodičem

### 3.3 `ViewerController`

**`GET /_ui/viewer/{id}/meta`** — `layouts` dostane `'tree'`, když
`getTreeOptions() !== null`, a přibude klíč:

```json
{
    "layouts": ["list", "tree"],
    "defaultLayout": "tree",
    "tree": {
        "columns": [{"id": "name", "label": "Název", "grow": true}, "…"],
        "parentColumn": "parent"
    }
}
```

`parentColumn` frontend potřebuje jen k jedné věci: poznat akci, která
zakládá podřízený záznam (§ 5.4).

**`GET /_ui/viewer/{id}/rows?layout=tree`** — parametry `search`
a `filter[…]` jako jinde; `page` a `sort` se nečtou.

```json
{
    "rows": [
        {"id": 2, "parent": null, "depth": 0, "childCount": 2, "match": false,
         "icon": "place-building", "stateStyle": "done",
         "cells": {"name": [{"text": "Budova A"}], "code": "A", "kind": "Budova"},
         "rowAction": {"id": "addChild", "label": "Přidat podřízené", "icon": "add",
                       "kind": "open_form", "target": {"table": "base_places_places", "preset": {"parent": 2, "kind": 14}}}}
    ],
    "hasMore": false
}
```

- řádek bez `icon` dostane ikonu vieweru jako v seznamu
- víc vybraných uzlů než strop → `{"rows": [], "hasMore": false, "tooMany": true}`.
  Do stropu se počítají jen vybrané uzly, předci jako kontext ne
- `layout=tree` na vieweru bez stromu → `400 LAYOUT_NOT_SUPPORTED`

## 4. Předci jako kontext (D21)

Bez dotažení předků by hledání „101“ vrátilo místnosti bez budov
a builder by z nich udělal kořeny — uživatel by neviděl, kde místnost je.
Kontext je proto jádro stromu, ne kosmetika:

- hledání „101“ v Aktivních → obě místnosti 101, nad nimi jejich podlaží
  a budovy tlumeně
- Archiv → archivovaná místnost pod svým (aktivním) podlažím a budovou
- předek je klikatelný jako každý jiný řádek — otevře detail

`selectTreeRowsByIds()` proto filtr stavů ani hledání neuplatňuje.

## 5. Frontend

### 5.1 Čisté funkce

| Soubor | Co nese |
|---|---|
| `utils/viewerTree.js` | `visibleTreeRows()` (jeden průchod, hloubka nejbližšího sbaleného předka), `isExpanded()`, `ancestorIds()`, `defaultExpanded()` (kořeny s dětmi), `expandableIds()`, `toggleExpanded()` / `withExpanded()` / `pruneExpanded()` (vždy nová sada), `isTemporaryView()` |
| `utils/viewerLayoutPick.js` | `availableLayouts()`, `effectiveLayoutOf()`, `nextLayout()` — který layout se kreslí a kam vede přepínač |
| `utils/viewerTreeState.js` | paměť rozbalení v `localStorage` |

Testy: `frontend/tests/Unit/viewerTree.test.mjs`, `viewerLayoutPick.test.mjs`.

### 5.2 `ViewerTree.svelte`

Dostává všechny řádky, sloupce, vybraný řádek a stav rozbalení
(`expanded`: `Set`, `expandAll`); sám stav nedrží, jen hlásí `onSelect`,
`onToggle`, `onRowAction`, `onRowDblClick`.

- **desktop**: lehká tabulka se sticky hlavičkou (styly a tokeny jako
  `ViewerGrid`). První buňka: odsazení `depth × 1.375rem`, chevron
  (tlačítko s `aria-expanded` a popiskem Rozbalit / Sbalit; uzel bez dětí
  má mezeru stejné šířky), ikona, název; sbalený uzel s dětmi má šedě
  počet podřízených. Ostatní buňky přes sdílené `viewerSpans.js`
- **šířky sloupců**: tabulka má pevné rozvržení (`table-layout: fixed`) —
  sloupec s `width` ji opravdu dostane, sloupec bez šířky (stromový
  s `grow`) bere zbytek, nejmíň `14rem`; pod tím se strom roluje do
  strany. Sloupce mimo stromový proto deklaruj se šířkou
- **akce řádku**: tlačítko na konci řádku, vidět při najetí, u vybraného
  řádku a při fokusu z klávesnice; na dotykovém zařízení
  (`@media (hover: none)`) vždy. Klik řádek nevybere
- **`match: false`**: text barvou sekundárního textu; stejně archiv a koš.
  Na vybraném řádku běžný text (#153 D91)
- **telefon**: bez hlavičky, řádek nejmíň `2.75rem`, chevron a akce jako
  dotykové cíle `2.75rem`, druhý řádek = ostatní neprázdné buňky spojené ` · `
- vybraný řádek se doroluje do zorného pole (výběr odjinud)

Klávesová navigace šipkami a virtualizace řádků nejsou — strop D25 drží
počet řádků v mezích.

### 5.3 Rozbalení (D22)

Paměť: klíč `shpd_viewer_tree_open:<DS>`, hodnota mapa `{viewerId: [id, …]}`.
`null` = nic uloženo, prázdná sada je platný stav (Sbalit vše).

| Stav | Co je rozbalené | Ukládá se |
|---|---|---|
| nic uloženo | kořeny s dětmi — vidět je druhá úroveň | až první změnou |
| uloženo | uložená sada | ano, každou změnou |
| **dočasný pohled** — hledání, vlastní filtr (jiný než výchozí), jiná záložka stavů než výchozí | všechno; klikání na chevrony žije jen v dočasné kopii | ne |

Po opuštění dočasného pohledu platí zase uložené rozbalení. Rozbalit vše
/ Sbalit vše (dvě ikony vedle hledání, jen ve stromu) v dočasném pohledu
mění jen dočasnou kopii.

Rozbalení navíc otevírají dvě věci a obě ukládají:

- **výběr schovaného řádku** (otevření záznamu odjinud) rozbalí cestu k němu
- **akce zakládající podřízený záznam** rozbalí svůj řádek (§ 5.4)

Před uložením se sada pročistí na uzly, které ve stromu jsou a mají děti
(`pruneExpanded()`), aby v prohlížeči nezůstávaly smazané záznamy. Jediná
výjimka je řádek akce — pod ním první podřízený teprve vznikne.

Stav rozbalení je `$state` se `Set`, který se **nikdy nemění na místě**,
a fetch helpery ho nečtou: rozbalení nikdy nespustí nové načtení řádků.

### 5.4 `Viewer.svelte`

- `effectiveLayout = effectiveLayoutOf(zvolený, meta.layouts, isMobile)`;
  `detailInDrawer = !isMobile && (isGrid || isTree || isNarrow)` — detail
  stromu je na desktopu vždy v draweru (`viewer-grid.md` § 6), na telefonu
  přepnutím seznam / detail
- `fetchRowsExplicit(…, layout)` posílá `&layout=tree`; `tooMany` → hláška
  místo stromu
- **akce řádku** jde stejnou cestou jako akce detailu `open_form`
  (`openFormFromAction()`), jen nezávisí na výběru. Když akce cílí na
  tabulku vieweru a její `preset` nese sloupec `meta.tree.parentColumn`
  s id řádku ve stromu, řádek se před otevřením formuláře rozbalí — nový
  záznam je po uložení vidět. Platí pro „+“ na řádku i tutéž akci v detailu
- **přepínač layoutů** je obecný: další layout v pořadí `meta.layouts`,
  po posledním zase první; ikona a popisek ukazují cíl. Na telefonu se
  ukáže, jen když jsou tam aspoň dva layouty (grid tam není)
- výběr přežívá nové načtení řádků (po uložení formuláře); nově založený
  záznam se sám nevybírá
- **akce `select_row`** (D26) — `target {viewerId, recordId}` jako
  `open_detail`. Záznam téhož vieweru, který je mezi načtenými řádky, se
  vybere (`revealTreeRow()` odkryje cestu) a detail se přepne; jinak
  read-only náhled v modalu. Funguje i v seznamu (výběr načteného řádku).
  Hostitelé mimo viewer (dashboard, reporty, `ViewerDetailModal`) ji
  neznají — viewer ji posílá jen v detailu vlastních záznamů

Texty: `viewer.layout.showTree`, `viewer.tree.*` v `i18n/cs.js` a `en.js`.
Počet podřízených a hláška D25 jsou obecné („3 položky“, „Záznamů je
příliš mnoho na strom…“) — komponenta neví, co viewer ukazuje.

## 6. Jak dát vieweru strom

1. `getTreeOptions()` — sloupec rodiče a sloupce (popisky přes `columnLabel()`)
2. `selectTreeRows()` se stejným WHERE jako `selectRows()` — společnou
   část dotazu vytáhnout, ať se SELECT nekopíruje; bez `ORDER BY`, s
   `LIMIT maxNodes + 1`
3. `selectTreeRowsByIds()` — týž SELECT s `WHERE id IN %in`, bez filtrů
4. `compareTreeRows()` — pořadí sourozenců; když ho modul má i jinde
   (podřízené záznamy v detailu), jedno sdílené porovnání
5. `renderTreeRow()` — buňky, ikona, `stateStyle`, případně `rowAction`
6. volitelně `getDefaultLayout()` → `'tree'`

Vzor: `modules/base/places/src/PlacesViewer.php`.

## 7. Mimo rozsah

Přetahování myší (změna rodiče a pořadí, #169 D12 — druhá fáze); klávesová
navigace šipkami; virtualizace řádků; řazení klikem na sloupec; serverová
paměť rozbalení; líné načítání větví.

## 8. Soubory

| Soubor | Role |
|---|---|
| `src/Core/Viewer/TableViewer.php` | metody § 3.1, `TREE_MAX_NODES` |
| `src/Core/Viewer/TreeRowsBuilder.php` | skládání stromu |
| `src/Api/Controller/ViewerController.php` | meta `tree`, `rows` s `layout=tree`, `tooMany` |
| `frontend/src/components/viewer/ViewerTree.svelte` | strom |
| `frontend/src/components/viewer/Viewer.svelte` | layout, rozbalení, akce řádku, přepínač |
| `frontend/src/utils/viewerTree.js`, `viewerLayoutPick.js`, `viewerTreeState.js` | čisté funkce a paměť |
| `modules/base/places/src/PlacesViewer.php` | pilot |
