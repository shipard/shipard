# Viewer — generický layout `tree`, pilot Místa (task B)

**Stav:** naplánováno — design #169 D8–D12, D20–D25

> PRD pro jednu Claude Code session (4 commity). Design: issue #169
> (D8–D12 zamčené, D20–D25 níže). Navazuje na task A
> (`tasks/places-model.md`, hotovo) — Místa z list layoutu převede na
> strom s detailem v draweru.

## Kontext

Task A dodal číselník míst s hierarchií, ale prohlížeč je zatím plochý
seznam s cestou ve druhém řádku. #169 D8 chce **generický** stromový
layout vieweru — Místa jsou pilot, později ho dostanou skladová místa,
zakázky s podzakázkami apod. Vzor je layout `grid` (`docs/viewer-grid.md`):
prezentační režim jednoho vieweru, ne jiná třída; filtry, hledání, detail
a formuláře zůstávají sdílené.

Mockup (plátno v chatu, ne v repu) ukazoval: řádek stromu jako lehkou
tabulku (název s odsazením, chevronem a ikonou; Kód; Druh), u sbaleného
uzlu šedě počet podřízených, akci „+“ při najetí a u vybraného řádku,
detail v nemodálním draweru, na telefonu strom přes celou šířku
s dvouřádkovými řádky.

## Cíl

1. Backend kontrakt layoutu `tree` v `TableViewer` + obecný
   `TreeRowsBuilder` + `ViewerController` (meta, rows).
2. Frontend `ViewerTree.svelte`: odsazené řádky, rozbalování, paměť
   rozbalení, hledání s předky, akce řádku, detail vždy v draweru,
   telefon.
3. Pilot `PlacesViewer`: strom jako výchozí layout, akce Přidat podřízené
   na řádku a v detailu s předvyplněným nadřazeným místem a druhem.
4. Dokumentace (`docs/viewer-tree.md`), `docs/frontend.md`, `docs/places.md`,
   nápověda.

## Před implementací přečti

- issue #169 (D8–D12, komentář k tasku A), tento task D20–D25
- `docs/viewer-grid.md` celý — vzor kontraktu (meta `layouts`, `rows`
  s `layout=`, guard `LAYOUT_NOT_SUPPORTED`, drawer §6, toggle §7.2)
- `docs/frontend.md` §7 (Viewer systém, mobilní viewer, API endpointy)
- `docs/design-system.md` §8 Responzivita (drawer, úzký viewer),
  řádky vieweru a tokeny; `docs/places.md`
- `src/Core/Viewer/TableViewer.php`, `src/Api/Controller/ViewerController.php`
- `frontend/src/components/viewer/Viewer.svelte` (`effectiveLayout`,
  `isGrid`, `detailInDrawer`, `hasLayoutToggle`, `fetchRowsExplicit`,
  layout-change `$effect`, `handleDetailAction` → `open_form` s `preset`),
  `ViewerGrid.svelte`, `ViewerRow.svelte`, `ViewerDetailDrawer.svelte`,
  `viewerSpans.js`, `utils/viewerLayout.js`
- `modules/base/places/src/PlacesViewer.php`, `PlaceTree.php`, `PlaceTypes.php`,
  `config/types.jsonc`

## Scope

**Uvnitř:** vše v Cíli.

**Mimo:** drag&drop (#169 D12, druhá fáze); klávesová navigace šipkami ve
stromu; virtualizace řádků; řazení klikem na sloupec (D9 — pevné pořadí);
strom pro jiné viewery než Místa (jen kontrakt, žádný další pilot); serverová
persistence rozbalení.

## Rozhodnutí k designu

Zamčené v #169:

- ✓ **D8** layout `tree` v obecném vieweru: deklarace sloupce rodiče,
  celý strom v jedné odpovědi bez stránkování, odsazené řádky
  s rozbalováním, detail vždy v nemodálním draweru (`ViewerDetailDrawer`)
- ✓ **D9** pevné řazení podle pořadí; rozbalení si pamatuje prohlížeč;
  Sbalit vše / Rozbalit vše; hledání ukáže shody i předky (rozbalené,
  předci tlumeně); filtry stavů jako jinde
- ✓ **D10** Přidat v liště = místo nejvyšší úrovně; na řádku a v detailu
  Přidat podřízené (`open_form` s `preset`, nadřazené místo + druh o úroveň
  níž)
- ✓ **D11** detail podle pravidel detailu (task A ho už má); telefon:
  strom přes celou šířku, detail přepnutím (#138 D42)
- ✓ **D12** přesun polem Nadřazené místo (task A), drag&drop až později

Upřesnění (potvrzená, #169):

- ✓ **D20 — Kontrakt stromu.** Řádek stromu má tvar řádku gridu (`cells`
  podle deklarovaných sloupců, první sloupec je stromový: odsazení,
  chevron, ikona) a k tomu `parent`, `depth`, `childCount`, `match`.
  Pořadí, hloubku a předky skládá obecný `TreeRowsBuilder`; viewer dodá
  vybrané řádky, řádky podle id (předci), porovnání sourozenců a render
  řádku. Na telefonu se kreslí první buňka a pod ní ostatní spojené ` · `.
- ✓ **D21 — Kontext předků.** Strom ukazuje uzly, které projdou filtrem stavů,
  vlastními filtry a hledáním, a k nim jejich předky jako kontext
  (`match: false`, tlumeně, klikatelní). Stejně v záložce Aktivní s hledáním
  i v Archivu a Koši — archivovaná místnost se ukáže pod svým (aktivním)
  podlažím a budovou.
- ✓ **D22 — Rozbalení.** Paměť per zdroj dat a viewer v `localStorage`
  (vzor `viewerLayout.js`). Bez uloženého stavu jsou rozbalené kořeny
  (vidět druhá úroveň). Při hledání, vlastním filtru nebo jiné záložce
  stavů než výchozí je strom dočasně rozbalený celý a nic se neukládá.
  Výběr řádku, který je schovaný (např. otevření záznamu odjinud), rozbalí
  jeho předky; akce Přidat podřízené rozbalí svůj řádek, aby nové místo
  bylo po uložení vidět.
- ✓ **D23 — Navržený druh u Přidat podřízené.** Druh posledního podřízeného
  místa (mimo koš); když žádné není, výchozí podřízený typ z cfgItem typů
  (nový klíč `defaultChildType`: areál → budova, budova → podlaží, část
  budovy → podlaží, podlaží → místnost, místnost → místo, venkovní
  plocha → místo, místo a ostatní → žádný) a z něj seedový druh, jinak
  první živý druh toho typu podle názvu. Bez vhodného druhu se předvyplní
  jen nadřazené místo. Akce chybí u typu, pod který nemůže nic (žádný typ
  s úrovní vyšší než jeho), tj. u typu Místo.
- ✓ **D24 — Seznam zůstává.** List layout z tasku A zůstává jako druhý layout
  přes existující přepínač (stojí nula práce, hodí se pro „všechny
  kanceláře“); výchozí je strom. Telefon kreslí strom, když je zvolený,
  grid dál degraduje na list.
- ✓ **D25 — Strop.** Víc než 5 000 vybraných uzlů → strom se nevykreslí,
  odpověď nese `tooMany: true` a viewer ukáže hlášku „Míst je příliš mnoho
  na strom — zužte hledáním nebo přepněte na seznam.“ Strom se nikdy
  neořezává napůl.

## Backend

### `TableViewer` — nové metody

Vše volitelné; viewer bez stromu nic neimplementuje.

```php
/** Null = viewer strom nepodporuje. */
public function getTreeOptions(): ?array
// ['parentColumn' => 'parent',
//  'columns' => [ {id, label, width?, align?, grow?}, … ]  // tvar sloupců gridu, první = stromový
//  'maxNodes' => 5000]                                     // volitelné, výchozí TREE_MAX_NODES

/** Vybrané uzly: filtr stavů, vlastní filtry a hledání; BEZ stránkování,
 *  nejvýš maxNodes + 1 řádků (detekce stropu). Raw řádky musí nést id
 *  a parentColumn. */
public function selectTreeRows(?string $search, array $filters): array

/** Raw řádky podle id bez ohledu na filtry — předci jako kontext (D21).
 *  Stejný tvar jako selectTreeRows(). */
public function selectTreeRowsByIds(array $ids): array

/** Pořadí sourozenců (D9). Výchozí: podle id. */
public function compareTreeRows(array $a, array $b): int

/** Render jednoho uzlu: {id, cells, icon?, stateStyle?, rowAction?}.
 *  rowAction = jedna akce ve formátu akcí detailu (id, label, icon, kind,
 *  target) nebo null — „+“ na řádku. parent/depth/childCount/match doplní
 *  builder. */
public function renderTreeRow(array $rowData): array
```

`getDefaultLayout()` smí vracet `'tree'`.

### `TreeRowsBuilder` (`src/Core/Viewer/TreeRowsBuilder.php`)

Čistá třída bez DB (testovatelná): vstup = vybrané raw řádky, callback na
`selectTreeRowsByIds`, `parentColumn`, komparátor. Postup:

1. vybrané řádky = `match: true`
2. předci: po vrstvách dotáhnout chybějící rodiče přes callback, dokud
   nějací chybí (strop průchodu `MAX_DEPTH = 100`, navštívená id — žádná
   nekonečná smyčka na poškozených datech); dotažení = `match: false`
3. rodič, který neexistuje ani po dotažení → uzel je kořen
4. děti podle rodiče, seřazené komparátorem; průchod do hloubky od kořenů;
   `depth` od 0; `childCount` = počet dětí v sestaveném stromu
5. výstup: plochý seznam v DFS pořadí s `parent` (id nebo null), `depth`,
   `childCount`, `match` a raw řádkem pro render

### `ViewerController`

- **meta:** `layouts` dostane `'tree'`, když `getTreeOptions() !== null`;
  klíč `tree: {columns, parentColumn}`; `defaultLayout` validovaný proti
  `layouts` jako dnes.
- **rows s `layout=tree`:** guard `LAYOUT_NOT_SUPPORTED` (400) u vieweru bez
  stromu; `selectTreeRows()` → víc než `maxNodes` → `{rows: [], hasMore:
  false, tooMany: true}` (D25); jinak builder → `renderTreeRow()` pro každý
  uzel, doplnit `parent`, `depth`, `childCount`, `match`, výchozí ikonu
  vieweru jako u listu; `hasMore: false`. Parametr `page` se ignoruje.

## Frontend

### Čisté helpery (`frontend/src/utils/viewerTree.js`)

- `visibleTreeRows(rows, expandedIds, expandAll)` — jeden průchod DFS
  seznamem: řádek je vidět, když jsou rozbalení všichni předci (sledovat
  hloubku nejbližšího sbaleného předka)
- `ancestorIds(rows, id)` — pro rozbalení cesty k vybranému řádku
- `defaultExpanded(rows)` — id kořenů s dětmi (D22)

Testy v `frontend/tests/Unit/viewerTree.test.mjs` (`node --test`).

### Paměť rozbalení (`frontend/src/utils/viewerTreeState.js`)

Vzor `viewerLayout.js`: klíč `shpd_viewer_tree_open:<DS>`, mapa
`{viewerId: [id, …]}`; `getExpanded(viewerId)` → `Set | null` (null = nic
uloženo), `setExpanded(viewerId, set)`. Chybná data / nedostupná storage =
jako nic uloženo.

Do `viewerLayout.js` doplnit `'tree'` mezi platné hodnoty.

### `ViewerTree.svelte`

- vstup: `rows` (DFS, ze serveru), `columns`, `selectedRowId`, `expanded`
  (Set), `expandAll`, callbacky `onSelect`, `onToggle`, `onRowAction`
- hlavička sloupců jako v gridu (sticky); řádek: chevron (tlačítko
  s `aria-expanded` a `aria-label` Rozbalit / Sbalit, jinak mezera stejné
  šířky), ikona, první buňka; u sbaleného uzlu s dětmi šedě počet
  podřízených (text ze serveru není — skloňování „1 místo / 2 místa /
  5 míst“ přes `t()` s plurálem, ověř jak i18n plurály řeší, jinak
  neutrální „podřízená: N“); další buňky přes sdílené `viewerSpans.js`
- odsazení `depth × 1.375rem` (rem, ne px — `check-css-units.py`)
- `match: false` → text barvou sekundárního textu (ne světlejší — kontrast
  drží tokeny, `check-css-tokens.py`)
- výběr a stavový proužek stejnými tokeny jako `ViewerRow` / `ViewerGrid`
- `rowAction`: tlačítko s ikonou na konci řádku, viditelné při najetí
  a u vybraného řádku (na dotykovém zařízení vždy viditelné); klik
  nepropaguje výběr řádku
- telefon (`layoutStore.isMobile`): bez hlavičky sloupců, řádek min.
  2,75rem, chevron jako 44px dotykový cíl, druhý řádek = ostatní buňky
  spojené ` · ` (D20)

### `Viewer.svelte`

- `effectiveLayout`: `tree`, když je zvolený a meta ho nabízí — i na
  telefonu (D24); `grid` na telefonu dál → `list`
- `isTree`; `detailInDrawer = !isMobile && (isGrid || isTree || isNarrow)`
- `fetchRowsExplicit(…, layout)`: `&layout=tree`, bez stránkování
  a infinite scrollu; `tooMany` → hláška místo stromu (D25, i18n klíč)
- rozbalení: po načtení meta `getExpanded(viewerId) ?? null`; po prvním
  načtení řádků bez uloženého stavu `defaultExpanded(rows)`; `expandAll` =
  hledání neprázdné || vlastní filtr aktivní || záložka stavů ≠ výchozí
  (D22) — v tom režimu se toggle drží v dočasné kopii a neukládá se
- Rozbalit vše / Sbalit vše: dvě ikonová tlačítka vedle hledání jen
  v layoutu `tree` (desktop i telefon), ukládají
- výběr skrytého řádku → rozbalit `ancestorIds` (a uložit)
- `rowAction` → stejná obsluha jako akce detailu (`open_form` s `preset`);
  před otevřením formuláře rozbalit řádek akce (D22)
- **přepínač layoutů** obecně: další layout v pořadí `meta.layouts`
  (cyklus), ikona a popisek podle cíle (`list` → seznam, `grid` → tabulka,
  `tree` → strom, nový klíč ikony `tree`); persistence beze změny
- Výběr přežívá refetch (po uložení formuláře) jako dnes; nové místo se
  po uložení nevybírá (mimo scope)

Texty: `viewer.layout.showTree`, `viewer.tree.expandAll`,
`viewer.tree.collapseAll`, `viewer.tree.expand`, `viewer.tree.collapse`,
`viewer.tree.tooMany`, počet podřízených — `frontend/src/i18n/cs.js`
a `en.js`.

## Pilot `PlacesViewer`

- `getTreeOptions()`: `parentColumn: 'parent'`, sloupce Název (stromový,
  `grow`), Kód, Druh — popisky přes `columnLabel()`; `getDefaultLayout()` =
  `'tree'`
- `selectTreeRows()`: stejný SELECT a WHERE jako `selectRows()` (filtr
  stavů, hledání v názvu, zkráceném názvu a kódu) + `p.parent`,
  `p.order_pos`, `p.kind`, bez LIMIT/OFFSET, `LIMIT maxNodes + 1`;
  společné části vytáhnout, ať se SELECT nekopíruje
- `selectTreeRowsByIds()`: týž SELECT s `WHERE p.id IN %in` (i stavy
  mimo filtr — kontext)
- `compareTreeRows()`: pořadí → kód → název (stejná pravidla jako
  `PlaceTree::children()`, sdílet porovnání)
- `renderTreeRow()`: buňky název / kód / druh, stavový tag u názvu jako
  v listu (`rowStateTag`), ikona druhu, `stateStyle`, `rowAction` =
  Přidat podřízené (D23) nebo null
- `detailActions()`: tatáž akce Přidat podřízené (když je k dispozici)
- **D23:** helper `childPreset(int $id, string $type): ?array` →
  `['parent' => $id, 'kind' => ?int]`; druh posledního podřízeného
  (`PlaceTree::children()` — poslední podle pořadí), jinak
  `PlaceTypes::defaultChildType($type)` → seedový druh (`system_code` =
  typ, živý) → první živý druh typu podle názvu; null akce, když
  `PlaceTypes::canHaveChildren($type)` je false (žádný typ s úrovní
  vyšší; `other` jako rodič = true). Druhy načíst jednou na request
  (memo), ne per řádek
- `types.jsonc`: klíč `defaultChildType` u každého typu (D23),
  `PlaceTypes::defaultChildType()`, `canHaveChildren()`
- texty akce v `base.places.viewerLabels` (`labelText()`)

## Kroky a commity

1. **`feat(viewer): layout tree — kontrakt a TreeRowsBuilder`** —
   `TableViewer`, `TreeRowsBuilder`, `ViewerController`. Testy:
   `TreeRowsBuilderTest` (DFS pořadí podle komparátoru, hloubka, počet
   dětí, dotažení předků po vrstvách s `match: false`, chybějící rodič =
   kořen, cyklus v datech nespadne), `ViewerControllerTest` (meta
   `layouts`/`tree`, guard, `tooMany`, ignorovaný `page`).
2. **`feat(viewer): stromový layout ve frontendu`** — `viewerTree.js`
   + test, `viewerTreeState.js`, `ViewerTree.svelte`, `Viewer.svelte`,
   ikona `tree`, i18n.
3. **`feat(places): strom míst a Přidat podřízené`** — `PlacesViewer`,
   `types.jsonc`, `PlaceTypes`, texty. Testy: `PlacesViewersTest`
   (strom — sloupce, render, předci při hledání; `childPreset` — druh
   sourozence, výchozí podřízený typ se seedovým druhem, bez seedového
   první živý, Místo bez akce, Ostatní jako rodič), `PlaceTypesTest`
   (`defaultChildType`, `canHaveChildren`).
4. **`docs(viewer): layout tree, nápověda, stav tasku`** —
   `docs/viewer-tree.md` (kontrakt, builder, frontend, D20–D25, vztah
   ke gridu), `docs/frontend.md` §7 (odstavec + odkaz, endpoint
   `layout=tree`), `docs/viewer-grid.md` §7.2 (přepínač je teď cyklus
   přes `meta.layouts`), `docs/places.md` (strom, Přidat podřízené, D23),
   řádek v tabulce dokumentů `CLAUDE.md`; nápověda
   `help/mista/mista-a-druhy-mist.md` (strom, rozbalení, Přidat
   podřízené), `help/co-dnes-nejde.md` (smazat „Místa se neukazují jako
   strom“, přetahování myší zůstává), `help-index.py`; `**Stav:**`
   + `tasks-index.py`.

## Testy a kontroly

- PHPUnit cíleně (`--filter TreeRows|ViewerController|Places`), pak celá
  sada; `ViewerSourceTextsTest`, `ViewerDetailContractTest` beze změny
  výjimek
- `npm test` (frontend `node --test`), `npm run build`
- `check-css-tokens.py`, `check-css-units.py`

## Ověření

Na vývojovém zdroji dat s třídou `free`: Nastavení → Místa se otevře jako
strom, kořeny rozbalené; rozbalení přežije přepnutí jinam a zpět i reload;
Rozbalit vše / Sbalit vše; hledání „101“ ukáže obě místnosti 101 s cestou
(budovy a podlaží tlumeně) a po smazání hledání se vrátí uložené rozbalení;
Archiv ukáže archivované místo pod jeho předky; „+“ u podlaží otevře
formulář s podlažím a druhem posledního sourozence, po uložení je nové
místo vidět; „+“ u stání chybí; klik na řádek otevře drawer, klik na jiný
přepne detail, Esc zavře; přepínač strom ↔ seznam; telefon (DevTools
390 px): strom přes celou šířku, dvouřádkové řádky, detail přepnutím.

## Hotovo když

- [ ] kontrakt `tree` v `TableViewer` + `TreeRowsBuilder` + controller s testy
- [ ] `ViewerTree.svelte` s rozbalením, pamětí, hledáním s předky, akcí
      řádku, drawerem a telefonem; helpery s testy
- [ ] Místa výchozí jako strom, Přidat podřízené na řádku i v detailu (D23)
- [ ] dokumentace a nápověda
- [ ] PHPUnit, frontend testy, build a CSS kontroly projdou
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **Strom se nestránkuje.** `selectRows()` má stránkování v kontraktu
  (pageSize + 1) — strom jde vlastní cestou `selectTreeRows()`, signatura
  `selectRows()` se nemění (20+ implementací, vzor grid D9).
- **Předci mimo filtr.** Bez dotažení předků by hledání „101“ vrátilo
  místnosti bez budov a builder by z nich udělal kořeny — kontext je jádro
  D21, ne kosmetika.
- **Pořadí sourozenců dělá komparátor, ne SQL.** Dotažení předci přicházejí
  zvlášť; řazení jen v SQL by je postavilo za ostatní sourozence.
- **`$effect` a stav rozbalení.** Rozbalení je `$state` s `Set` — čtení
  uvnitř async fetch helperu v `$effect` vytvoří nechtěnou závislost
  (refetch při každém rozbalení); `untrack()` / explicitní parametry jako
  `fetchRowsExplicit`. Rozbalení nikdy nespouští refetch.
- **`Set` v `$state`** se nemutuje na místě (`.add()` reaktivitu
  nespustí) — vždy nová instance.
- **Dočasné rozbalení při hledání neukládat** — po smazání hledání se musí
  vrátit uložený stav (D22).
- **Akce řádku nesmí vybrat řádek** (`stopPropagation`) a na dotyku musí
  být vidět bez najetí myší.
- **Telefon nedegraduje strom na list** — jen grid (D24); stávající
  podmínka `isMobile → 'list'` se musí rozdělit.
- **Komparátor míst sdílet s `PlaceTree`** — dvě různá řazení sourozenců
  (strom vs. detail Podřízená místa) by se rozjela.
