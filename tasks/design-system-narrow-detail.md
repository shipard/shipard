# Design system — úzký detail vedle seznamu: detail v draweru podle šířky vieweru

**Stav:** hotovo — 2026-11-10 (#138 task 5, D58); ruční ověření ve Firefoxu prošlo, doplnění z něj (drawer nepřesahuje přes navigaci) hotové

## Kontext / Cíl

Pátý task #138 (zastřešující #133). Viewer s layoutem `list` má na
desktopu seznam (`25rem`) a vedle něj detail. Těsně nad hranicí shellu
(`48rem`) zabere navigace a seznam skoro celou šířku: v sidebar shellu
při okně 800 px zbude detailu asi 140 px, při 1024 px asi 364 px, a po
#138 task 4 (rozměry v rem) při písmu prohlížeče 20 px a okně 1024 px
jen 199 px. Mobilní přepínání seznam / detail se řídí šířkou okna, ne
šířkou vieweru, takže ho tohle pásmo nezapne.

**Cíl:** viewer se seznamem měří svou šířku; když je užší než seznam
plus nejužší rozumný detail (`55rem`), seznam se roztáhne přes celou
šířku a detail se otevře v draweru — stejně jako u tabulkových seznamů
(layout `grid`, `ViewerDetailDrawer`). Platí ve všech shellech a při
jakékoli velikosti písma.

**Mimo rozsah:**

- mobil (pod `48rem` okna) — přepínání seznam / detail přes celou šířku
  zůstává (D42);
- tabulkové seznamy (layout `grid`) — detail v draweru mají už dnes;
- `ViewerDetailModal` (read-only detail z odkazu) a modaly;
- vzhled a obsah draweru (`ViewerDetailDrawer`) — jen se použije i pro
  list layout; šířka `35rem` / `max-width: 90vw` beze změny;
- chyba stránky chatu (#133 Další nesoulady) — jiná stránka.

## Návaznost

- **#133** — D42, D43, D46, D58
- **#138** task 1 (breakpointy, `MOBILE_BREAKPOINT`), task 4 (rem)
- `docs/viewer-grid.md` §6 — drawer detailu (D4 gridu: non-modální,
  klik na jiný řádek přepíná detail)

## Před implementací přečti

- `docs/viewer-grid.md` §4.1 (layouty), §6 (drawer)
- `docs/design-system.md` §8 Layout konvence → Responzivita
- `frontend/src/components/viewer/Viewer.svelte` — `effectiveLayout`,
  `isGrid`, `toolbarActions`, třídy `shpd-viewer__body--mobile` /
  `--detail`, `shpd-viewer__list-panel--grid`, render detail panelu
  a `ViewerDetailDrawer`, `handleDrawerClose`
- `frontend/src/components/viewer/ViewerDetailDrawer.svelte`
- `frontend/src/stores/layout.svelte.js` (`MOBILE_BREAKPOINT`)
- `scripts/check-css-units.py` — kontrola JS konstanty `MOBILE_BREAKPOINT`

## Rozhodnutí (potvrzená v #133)

- ✓ **D58** Úzký detail vedle seznamu: viewer se seznamem (layout
  `list`) přepíná podle **své šířky, ne okna** — když je užší než seznam
  + nejužší rozumný detail (`25rem` + `30rem` = `55rem`), seznam se
  roztáhne přes celou šířku a detail se otevře v draweru jako
  u tabulkových seznamů (`ViewerDetailDrawer`). Funguje stejně ve všech
  shellech i při větším písmu (D43: obsah reaguje na svůj kontejner);
  mobilní přepínání seznam / detail pod `48rem` (D42) beze změny.
  Zamítnuto: pružná šířka seznamu (při okně 800 px detail pořád úzký),
  automaticky sbalený sidebar (jen jeden shell, druhá hodnota media
  query shellu).

## 1. Měření šířky vieweru

- Konstanta `VIEWER_SPLIT_MIN = '55rem'` ve `stores/layout.svelte.js`
  vedle `MOBILE_BREAKPOINT`, s komentářem (D58: `25rem` seznam + `30rem`
  nejužší detail = práh úzkého obsahu D43).
- `Viewer.svelte`: `ResizeObserver` na `.shpd-viewer__body` (ten má
  šířku seznamu + detailu; drawer je `position: fixed`, šířku body nemění
  — přepnutí proto nemůže kmitat). Stav `isNarrow = šířka < práh v px`,
  práh přepočtený z rem podle `getComputedStyle(document.documentElement)
  .fontSize` **při každém měření** (velikost písma se může změnit; se
  změnou písma se mění i šířka sidebaru v rem, takže observer vystřelí).
- Pomocná čistá funkce (`remToPx(value, rootFontSizePx)` / `isNarrowViewer
  (widthPx, rootFontSizePx)`) v `utils/` s unit testem — parsování
  `'55rem'`, porovnání na hraně (rovná se prahu = ne úzký).
- Observer jen na desktopu (na mobilu nic neměří), odpojit v cleanupu
  `$effect`; při prvním vykreslení se nesmí mihnout detail panel vedle
  seznamu — výchozí `isNarrow` spočítat synchronně z první velikosti,
  nebo detail panel do prvního měření nevykreslovat (zvol a zdůvodni).

## 2. Detail v draweru i pro list layout

- Nový derived `detailInDrawer = !layoutStore.isMobile && (isGrid ||
  isNarrow)`. **`effectiveLayout` se nemění** — tvar řádků a dotaz zůstávají
  `list` (karty seznamu), mění se jen kde je detail; žádný refetch.
- Místo `isGrid` tam, kde jde o umístění detailu:
  - `toolbarActions` — v draweru jsou akce detailu v hlavičce draweru,
    horní lišta zůstává v kontextu seznamu (jako grid);
  - třída seznamu přes celou šířku (`shpd-viewer__list-panel--grid` →
    přejmenovat na `--full`, nebo druhá třída; obsah gridu ji dál
    používá);
  - render detail panelu vedle seznamu (`{#if !isGrid}` → `{#if
    !detailInDrawer}`) a `ViewerDetailDrawer` (`{#if isGrid && …}` →
    `{#if detailInDrawer && …}`).
- **Výběr přežívá přepnutí:** vybraný záznam při zúžení okna otevře
  drawer se stejným detailem, při rozšíření se detail vrátí do panelu.
  Zavření draweru (křížek, Escape) zruší výběr jako v gridu
  (`handleDrawerClose`). Prázdný stav „Vyberte záznam“ se v úzkém režimu
  nezobrazuje.
- Klik na jiný záznam v seznamu přepne detail v otevřeném draweru
  (non-modální jako v gridu); vybraný řádek v seznamu zvýrazněný.
- Navigace na záznam odjinud (`navigateToViewer` s `recordId`, výsledky
  vyhledávání, akce `open_viewer`) v úzkém režimu otevře drawer.
- Komentáře u `ViewerDetailDrawer` („jen grid“, „na mobilu se drawer
  nepoužívá“) a ve `Viewer.svelte` upravit na D58.

## 3. Kontrola (D47)

`scripts/check-css-units.py`: vedle `MOBILE_BREAKPOINT` ověřit, že
`VIEWER_SPLIT_MIN` ve `stores/layout.svelte.js` je řetězec `'55rem'`
(konstanta nahoře ve skriptu s odkazem na D58); jiná hodnota = nové
rozhodnutí v #133.

## 4. Dokumentace

- `docs/design-system.md` §8 Responzivita: odstavec D58 — tři režimy
  vieweru se seznamem (mobil: přepínání přes celou šířku; úzký viewer:
  seznam přes celou šířku a detail v draweru; široký: seznam a detail
  vedle sebe), práh `55rem` a proč, že se řídí šířkou vieweru; zmínku
  o otevřeném bodu úzkého detailu (task 4) nahradit.
- `docs/viewer-grid.md` §6: drawer slouží i list layoutu v úzkém vieweru
  (D58), odkaz na design-system.
- `docs/frontend.md` — pokud popisuje rozložení seznam / detail vieweru,
  doplnit.
- `**Stav:**` tasku (řádek v `tasks/README.md` přibyl s taskem).

## Commity

1. `feat(frontend): úzký viewer otevírá detail v draweru (#138, D58)` — §1, §2, unit test
2. `chore: kontrola prahu úzkého vieweru (#138, D47, D58)` — §3
3. `docs: detail v draweru v úzkém vieweru (#138, D58)` — §4, `**Stav:**`,
   `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
```

V prohlížeči (Firefox, písmo 16 px; responzivní režim nebo zmenšené
okno), viewer se seznamem — Faktury přijaté:

- **sidebar shell**: okno 1280 — seznam a detail vedle sebe jako dnes;
  okno 1024 a 800 — seznam přes celou šířku, klik na záznam otevře drawer
  zprava s detailem a akcemi (Upravit, …) v jeho hlavičce, klik na jiný
  záznam přepne detail, křížek / Escape drawer zavře a zruší výběr;
- **classic a wild shell** (Uživatelské menu → Rozložení): totéž, práh
  přijde při jiné šířce okna (užší levý pás);
- **přepnutí za běhu**: otevřený detail při 1280 → zúžit okno pod práh →
  detail v draweru; rozšířit → zpět v panelu, výběr zůstává; žádné
  kmitání na hraně;
- **sbalení sidebaru** (sidebar shell) při okně těsně pod prahem →
  viewer se rozšíří a detail se vrátí do panelu;
- **písmo prohlížeče 20 px** a okno 1280: sidebar shell — detail
  v draweru (viewer užší než `55rem` = 1100 px), classic / wild podle
  šířky;
- **tabulkové seznamy** (Účetní deník) beze změny; **telefon 390 px** —
  přepínání seznam / detail beze změny;
- formulář z akce v draweru (Upravit) se otevře nad drawerem; náhled
  odkazem (`ViewerDetailModal`) beze změny.

## Hotovo když

- [x] `VIEWER_SPLIT_MIN`, měření šířky vieweru, unit test
- [x] list layout v úzkém vieweru: seznam přes celou šířku, detail
      v draweru, výběr přežívá přepnutí, akce detailu v hlavičce draweru
- [x] široký viewer, grid a mobil beze změny
- [x] kontrola konstanty v `check-css-units.py`
- [x] `docs/design-system.md` §8, `docs/viewer-grid.md` §6
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Jak to dopadlo (2026-11-10)

- **První vykreslení:** zvoleno synchronní měření v `$effect` (běží po
  připojení DOM, před vykreslením) + `ResizeObserver` pro další změny.
  Varianta „detail panel do prvního měření nevykreslovat“ by přidala
  třetí stav a v širokém vieweru by problikla opačně. Ověřeno sondou
  po snímcích: v úzkém okně žádný snímek s detail panelem.
- **Hystereze není:** přepnutí šířku `.shpd-viewer__body` nemění; na
  hraně (viewer 879 / 880 px) se nic nepřepíná opakovaně.
- **Práh jde čistým funkcím parametrem** (`isNarrowViewer(šířka, práh,
  písmo)`), konstantu předává `Viewer.svelte` — `layout.svelte.js`
  používá runy a v unit testu (`node --test`) ho načíst nejde.
- **Šířka 0 se ignoruje** (element bez rozměru) — zůstává poslední stav.
- **Akce Reanalyze** (Došlá pošta) hledala své profily v akcích horní
  lišty; s detailem v draweru nese lišta akce seznamu, proto se hledá
  v `detailToolbar`.
- **Přepnutí panel ↔ drawer** připojí `ViewerDetail` znovu — výběr
  a načtená data zůstávají, vnitřní stav detailu (aktivní tab) začíná
  od prvního tabu.
- **Uživatelská nápověda** beze změny: „v pravém panelu“
  (`help/uctarna/`) platí i pro drawer vpravo.
- **Ověřeno v Chromiu** (Playwright, volný demo zdroj dat, Faktury
  přijaté): sidebar shell 1280 / 1140 / 1139 / 1100 / 1024 / 800 px
  včetně sbalení sidebaru, formuláře nad drawerem a Escape; classic
  a wild 1280 / 1024 / 800 px; písmo 20 px při 1280 a 1600 px; Účetní
  deník (grid) a telefon 390 px beze změny. Neprošlo ruční ověření ve
  Firefoxu a navigace na záznam odjinud (`navigateToViewer` s `recordId`)
  v úzkém režimu — ta jde stejnou cestou jako klik na řádek.
- **Ruční ověření ve Firefoxu (2026-11-10)** prošlo — tři shelly,
  přepínání za běhu, písmo 20 px, navigace odjinud, grid a telefon —
  kromě jedné drobnosti: drawer v úzkém okně přesahoval přes navigaci.
  Opraveno v Doplnění níže.

## Doplnění po ručním ověření (2026-11-10)

Ruční proklik ve Firefoxu prošel (tři shelly, přepínání za běhu, písmo
20 px, navigace odjinud, grid a telefon beze změny) kromě jednoho —
drobnost z ověření podle `docs/ai-workflow.md` §2, řeší se v tomto tasku.

**Drawer v úzkém okně překrývá navigaci.** Sidebar shell, okno 800 px:
viewer má 540 px, drawer `35rem` (560 px; `max-width: 90vw` = 720 px
nepomůže) přesahuje o 20 px přes sidebar — u sbalovacích sekcí navigace
je uříznutá šipka vpravo. Platí až k hranici mobilu (`48rem`) a stejně
i pro drawer tabulkových seznamů (Účetní deník) — stav před taskem,
oprava jedna pro oba.

Úprava:

- Drawer nesmí být širší než viewer: `Viewer.svelte` už měří
  `.shpd-viewer__body` — předá změřenou šířku draweru jako custom
  property na jeho kořeni (`--shpd-viewer-width`, px z měření je
  v pořádku — měří vykreslený prvek, D46), drawer
  `max-width: min(90vw, var(--shpd-viewer-width, 90vw))`. Měření běží
  i pro grid (dnes se pro něj neměří, jen pro list — rozšířit; na mobilu
  dál ne).
- Šířka `35rem` zůstává, mění se jen horní mez. Drawer tak sahá nejvýš
  k levému okraji vieweru, navigace zůstane celá.
- Komentář u `max-width` v `ViewerDetailDrawer` (proč viewer, ne okno).

Ověření: sidebar shell 800 a 900 px (list i Účetní deník) — drawer končí
na hraně sidebaru, šipky sekcí celé; se sbaleným sidebarem drawer
`35rem`; classic a wild 800 px — drawer nepřekrývá levý pás; 1280 px
beze změny.

Commit: `fix(frontend): drawer detailu nepřesahuje přes navigaci (#138, D58)`
— s `**Stav:** hotovo` a řádkem výsledku ručního ověření v Jak to dopadlo.

**Hotovo (2026-11-10).** `Viewer.svelte` měřil šířku už pro oba layouty
(měření se neváže na layout, jen na desktop) — přibyl stav `bodyWidth`
a prop `viewerWidth` draweru; drawer má `max-width: min(90vw,
var(--shpd-viewer-width, 90vw))`. Ověřeno v Chromiu (Playwright, volný
demo zdroj dat): sidebar shell 800 px — list i Účetní deník: viewer
i drawer 540 px, levá hrana draweru na hraně sidebaru, šipky sekcí celé;
900 a 1024 px drawer 560 px; sbalený sidebar při 800 px drawer 560 px;
zúžení 1024 → 800 px s otevřeným drawerem mez přepočítá; classic a wild
800 px (viewer 724 px) drawer 560 px, levý pás volný; 1280 px (grid)
a 1100 px (list) beze změny; písmo 20 px, okno 1000 px: viewer i drawer
675 px (drawer by měl 700). Důsledek: v sidebar shellu pod 820 px okna
drawer zakrývá celý viewer včetně horní lišty — seznam je zpět po
zavření draweru (křížek, Escape).

**Poznámka k ověření:** „Otevřít“ na kartě dashboardu otevírá náhled
v modalu nad dashboardem (`open_detail`), ne viewer — v pořádku, mimo
D58.

## Pasti

- **Neměnit `effectiveLayout`.** Grid a list mají jiný tvar řádků
  a jiný dotaz; změna efektivního layoutu spustí reset a refetch. Úzký
  viewer mění jen umístění detailu.
- **Kmitání.** Drawer je `position: fixed` — šířku vieweru neovlivní.
  Kdyby se při přepnutí měnila šířka body (posuvník, skrytý detail
  panel), přidat malou hysterezi a zdůvodnit.
- **rem v JS.** Práh je v rem kvůli většímu písmu — přepočet na px vždy
  z aktuální velikosti písma kořene, ne z konstanty 16.
- **Mobilní top bar.** `$effect` se screen surface běží jen na mobilu —
  v úzkém desktopovém vieweru se nesmí spustit (akce detailu jsou
  v hlavičce draweru, ne v top baru).
- **Zvýraznění vybraného řádku** v seznamu karet musí zůstat, i když
  detail není vedle — uživatel vidí, který záznam je v draweru.
