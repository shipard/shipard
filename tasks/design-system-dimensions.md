# Design system — pevné rozměry a layout tokeny v rem

**Stav:** hotovo — 2026-11-10 (#138 task 4, D46, D47; drobnosti z ověření tasku 3); zbývá ruční ověření ve Firefoxu (písmo 20 px) a na telefonu

## Kontext / Cíl

Čtvrtý task #138 (zastřešující #133). Mezery, písmo a zaoblení jsou od
#134 v rem a hlídá je pre-commit (D10). Šířky, výšky a layout tokeny
zůstaly v px (D11 je odložilo, protože převod mění chování při větším
písmu prohlížeče). Ve `frontend/src` je jich v deklaracích `width`,
`height`, `min-*`, `max-*` a `flex*` přes 180, k tomu šířky předávané
komponentám propem (`Modal width="760px"`, `Popover width="400px"`)
a layout tokeny ve `variables.css`.

**Cíl:**

1. Pevné rozměry odvozené od textu a ovládání a layout tokeny v rem
   1 : 1 (D46) — při výchozím písmu 16 px vizuálně beze změny (jako D9).
2. Kontrola v pre-commitu hlídá i rozměrové vlastnosti a rozměrové propy
   (D47).
3. Dvě drobnosti z ověření tasku 3 (`docs/ai-workflow.md` §2).

**Mimo rozsah:**

- **úzký detail vedle seznamu** v okně těsně nad hranicí shellu
  (otevřený bod v #138) — čeká na nové rozhodnutí v #133, vlastní task;
  tady se šířka seznamu jen převede 1 : 1 (`400px` → `25rem`);
- čáry, obrysy, stíny a stavový / aktivní proužek — zůstávají v px
  (D46), jen dostanou značku `px-ok`;
- rozměry podle okna (`vh`, `dvh`, `vw`) a polohování z JS (`Popover`
  `GAP` / `MARGIN`, výpočty pozic) — beze změny;
- ikony (`em`), atributy `width` / `height` na `<svg>` a `<img>`
  a rozměry v JS (grafy, canvas);
- HTML se vzhledem od backendu (`.snapshots-container`) — dluh v #138.

## Návaznost

- **#133** — D1, D9, D10, D11, D12, D42, D46, D47
- **#134** — převod mezer a písma na rem, kontrola `check-css-units.py`
- **#138** task 1 — breakpointy v pre-commitu (stejný skript); task 3 —
  tabulky, drobnosti z jeho ověření

## Před implementací přečti

- `docs/design-system.md` — Jednotky a mezery, §8 Layout konvence
  (Responzivita), §13 Soubory
- `scripts/check-css-units.py` — celé (docstring, `PROPERTY`, výjimka
  `px-ok`, sken atributu `style` a direktivy `style:`)
- `frontend/src/styles/variables.css` — sekce Layout
- `frontend/src/components/ui/Modal.svelte`, `Popover.svelte` (prop
  `width`, jak se dosazuje)

## Rozhodnutí (potvrzená v #133)

- ✓ **D46** Pevné rozměry odvozené od textu a ovládání (šířky panelů,
  modalů a sloupců, `max-width` textu, výšky ovládacích prvků, avatary)
  a layout tokeny (`--shpd-sidebar-width`, `--shpd-header-height`, šířky
  pásů shellu) přejdou na rem 1 : 1 jako D9. V px zůstávají čáry, obrysy,
  stíny a stavový proužek; rozměry podle okna (`vh`, `dvh`) a polohování
  z JS se nemění; ikony zůstávají v `em`.
- ✓ **D47** Kontrola v pre-commitu: … Po převodu D46 hlídá i `width`,
  `height`, `min-*`, `max-*` a `flex-basis` v px; výjimka značkou
  `px-ok` (čáry).
- ✓ **D9** Převod 1 : 1, hodnota shodná s tokenem → token, nic se
  nezaokrouhluje.
- ✓ **D12** Platí i pro vývojářský dashboard.

## 1. Převod

**Pravidlo:** `N px` → `N / 16 rem`, přesně, bez zaokrouhlení
(`260px` → `16.25rem`, `28px` → `1.75rem`, `7px` → `0.4375rem`). Hodnota
shodná s tokenem mezery (`--shpd-space-*`) → token jen tam, kde jde
o mezeru (odsazení, rozestup), ne u šířky prvku.

**Na rem:**

- layout tokeny ve `variables.css`: `--shpd-sidebar-width` 260,
  `--shpd-sidebar-width-collapsed` 48, `--shpd-header-height` 56,
  `--shpd-classic-strip-width` 76, `--shpd-rail-width` 76;
- šířky panelů a dílů shellu: seznam vieweru (`Viewer.svelte`
  `.shpd-viewer__list-panel` 400), drawer detailu (`ViewerDetailDrawer`
  560; `max-width: 90vw` zůstává), panel chatu, flyout a tab strip
  navigace, uživatelské menu;
- šířky modalů a popoverů — **i propy**: `Modal` výchozí `'640px'` →
  `'40rem'` (a JSDoc příklad), všechna `width="…px"` u `Modal`,
  `Popover`, `ConfirmDialog`, `DevConfirm` a dialogů v aplikaci
  i v `dev/`; `Popover` `min-width` / `max-width` v CSS;
- `max-width` textu a formulářů (`760px`, `720px`, `560px`, `420px`, …),
  přihlašovací obrazovky (`400px`, logo `200 × 48`);
- ovládací prvky a avatary (`28`, `32`, `36`, `40 px` tlačítka, ikony
  v kroužku, avatar), značky a tečky u textu (`6–9 px` — rostou s textem),
  `min-height` míst, která drží výšku obsahu (`60px` placeholder,
  `200px`, `320px`, `560px` náhledu tisku);
- hodnoty uvnitř `min()`, `max()`, `clamp()` a `calc()`
  (`min(90vw, 560px)` → `min(90vw, 35rem)`,
  `calc(180px + 320px + …)` → `calc(11.25rem + 20rem + …)`),
  `flex: 0 0 200px` / `flex-basis`;
- šířky sloupců sub-tabulky (`colStyle` v `FormSubTable` skládá
  `width` v px z `width` sloupce z backendu — převést výpočet na rem
  `px / 16`, kontrakt backendu beze změny), `min(280px, 50%)` filtru;
- vývojářský dashboard (`dev/dev.css` má deklarace na jednom řádku —
  prohledat i je, ne jen řádky začínající vlastností).

**Zůstává v px se značkou** `/* px-ok: čára */` (nebo konkrétní důvod):

- čáry a oddělovače (`height: 1px`, `width: 1px`);
- aktivní / stavový proužek (`3px`, `4px` u položky navigace, karty,
  řádku);
- obrys a stín (ty kontrola nehlídá — jen pokud by šly přes `width`).

Hraniční případ (není to ani čára, ani prvek u textu — např. úchyt
posuvníku, šipka bubliny) rozhodnout podle otázky „má růst s písmem?“,
zapsat do Odchylek.

## 2. Kontrola v pre-commitu (`scripts/check-css-units.py`)

- `PROPERTY` rozšířit o `width`, `height`, `min-width`, `max-width`,
  `min-height`, `max-height`, `inline-size`, `block-size`
  (a `min-` / `max-` varianty), `flex-basis` a zkratku `flex` (px v ní je
  `flex-basis`). Lookbehind už vyřazuje custom properties a delší názvy
  (`border-width`, `outline-width`, `stroke-width` projdou — ověř testem
  regulárního výrazu, že se nechytají).
- **Rozměrové propy komponent:** v šabloně `.svelte` atribut
  `width="…px"` / `height="…px"` (a `width={'…px'}`) na **komponentě**
  (značka s velkým počátečním písmenem) je chyba; `<svg width="16">`
  a `<img>` (malá písmena, číslo bez jednotky) projdou. Výjimka `px-ok`
  na řádku platí stejně.
- **Layout tokeny:** sekce Layout ve `variables.css` — custom property
  `--shpd-*-width` / `--shpd-*-height` s px je chyba (ostatní tokeny
  s px, stíny a `--shpd-radius-pill`, dál projdou).
- Docstring: co se hlídá nově (D46, D47), co dál ne (JS, `vh`, ikony,
  SVG atributy).
- Před zapnutím musí celý `frontend/src` projít — nejdřív převod (§1),
  pak kontrola v tomtéž commitu, nebo kontrola v posledním commitu.

## 3. Drobnosti z ověření tasku 3

- **Report na telefonu láme název řádku po slovech** (`ReportView`):
  tabulka je širší než obal a sloupec názvu dostane nejmenší šířku.
  Sloupci názvu dát `min-width` v rem (hodnotu posoudit na 390 px —
  název účtu se má vejít na dva až tři řádky, ne po slovech; zdůvodnit
  v komentáři). Tabulka pak roluje do strany ve svém obalu.
- **Detail úlohy ve vývojářském dashboardu roluje při 480 px do strany**
  kvůli dlouhým hodnotám v `dl.dev-kv` (`dev/dev.css`):
  `grid-template-columns: max-content minmax(0, 1fr)` a `dd` s
  `overflow-wrap: anywhere` (hodnoty jsou ID, cesty a JSON bez mezer).

## 4. Dokumentace

- `docs/design-system.md`:
  - Jednotky a mezery: rozměry a layout tokeny v rem (D46), co zůstává
    v px (čáry, obrysy, stíny, proužek), propy `width` komponent v rem;
    věty „šířky zůstávají v px, řeší #138“ pryč;
  - §8 Responzivita: při větším písmu prohlížeče roste i shell a panely
    (důsledek pro úzký detail — odkaz na otevřený bod v #138);
  - §13 Soubory: `check-css-units.py` hlídá i rozměry.
- `docs/frontend.md` — pokud uvádí `Modal width` v px (příklad), přepsat.
- `tasks/README.md` — řádek v sekci Design system.

## Commity

1. `refactor(frontend): layout tokeny a šířky shellu v rem (#138, D46)` —
   `variables.css`, shelly, navigace, seznam, drawer, chat
2. `refactor(frontend): pevné rozměry obsahu, modalů a ovládání v rem (#138, D46)` —
   zbytek aplikace včetně propů `width`
3. `refactor(dev): pevné rozměry vývojářského dashboardu v rem (#138, D12, D46)`
4. `chore: kontrola rozměrů v pre-commitu (#138, D47)` — §2
5. `fix: drobnosti z ověření tabulek (#138)` — §3
6. `docs: rozměry v rem (#138, D46, D47)` — §4, `**Stav:**`,
   `python3 scripts/tasks-index.py`

Každá výjimka `px-ok` patří do popisu commitu (pravidlo D10).

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
# nic v px mimo značky px-ok (kontrola musí projít, tohle je kontrola kontroly)
grep -rnE --include=*.svelte --include=*.css "(width|height|flex(-basis)?)\s*:[^;]*[1-9][0-9]*px" frontend/src | grep -v "px-ok\|--shpd-shadow\|border\|outline"
grep -rnE --include=*.svelte 'width="[0-9]+px"' frontend/src
```

V prohlížeči (Firefox):

- **výchozí písmo 16 px**: shell (všechny tři — sidebar, classic, wild),
  seznam a detail, drawer gridu, modaly (potvrzení, odeslání, náhled
  tisku, odpisy), popovery review, chat, přihlašovací obrazovka,
  Nastavení, vývojářský dashboard — **beze změny proti dnešku** (porovnat
  se snímky před změnou při 1280 a 1600 px);
- **písmo prohlížeče 20 px** (Nastavení Firefoxu → Velikost písma): shell,
  seznam a modaly úměrně větší, nic nepřetéká, modal se vejde do okna
  (`90vw` / `max-height` drží), popover review se nevysouvá mimo okno;
- **telefon 390 px**: report — název řádku se neláme po slovech, tabulka
  roluje ve svém obalu; vývojářský dashboard → detail úlohy při 480 px
  neroluje do strany.

## Hotovo když

- [x] layout tokeny, šířky shellu, panelů, modalů, popoverů, ovládání
      a textu v rem — v aplikaci i v `dev/`, včetně propů `width`
- [x] v px jen čáry, obrysy, stíny a proužek, každý se značkou `px-ok`
- [x] `check-css-units.py` hlídá rozměrové vlastnosti, propy komponent
      a layout tokeny; celý `frontend/src` projde
- [x] drobnosti z ověření tasku 3
- [x] `docs/design-system.md` (Jednotky, §8, §13)
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Odchylky od zadání a výsledek ověření (2026-11-10)

**Rozsah.** 223 hodnot v rozměrových deklaracích a propech převedl
jednorázový skript nad parserem `check-css-units.py` (přesně px / 16),
19 rozměrů zůstalo v px se značkou; ručně layout tokeny, výchozí hodnoty
propů a styl složený v JS.

**Nad rámec zadání** (místa, která se musela převést spolu s ním):

- **`ViewerGrid.colStyle`** skládá šířku sloupce z backendu v px stejně
  jako `FormSubTable` — oba dosazují `px / 16` rem; v rem jsou i pevné
  `<col>` (číslo řádku `2.75rem`, sloupec akcí sub-tabulky `9.25` / `4.5rem`).
- **`grid-template-columns` s px** (6 míst: přílohy, změna hesla, logy
  a split dashboardu). `ChangePasswordPanel` má sloupce `180px` + `320px`
  a k nim `max-width: calc(180px + 320px + …)` ze zadání — bez mřížky by
  se při větším písmu rozešly. Kontrola je nehlídá (D47 je nejmenuje).
- **Polohy, které stojí na převedeném rozměru**: `ThemePanel` `top: 64px`
  → `calc(var(--shpd-header-height) + var(--shpd-space-sm))` (při písmu
  20 px by panel najel 6 px do lišty), toast dashboardu nad ChatLauncherem
  (`+ 72px` → `4.5rem`), odznak v railu (`right`, `line-height` k výšce
  `0.875rem`), `PdfPane` (odhad výšky lišty `5.25rem`, odečet `6.875rem`).
  Ostatní polohy (`top: 4px` u proužků, odstupy flyoutu) zůstaly v px.
- **`--dev-top-height`** v `dev/theme.css` je výchozí odhad `5.25rem`;
  naměřenou výšku dál dosazuje `DevApp` v px (měří vykreslený prvek).
- **Obsahové modaly** `FormDialog` a `ViewerDetailModal`:
  `clamp(75rem, 80vw, 106.25rem)` × `clamp(45rem, 88vh, 68.75rem)`
  (`docs/edit-forms.md` §9).

**Hraniční případy** (otázka „má růst s písmem?“):

- rem: fajfka a pomlčka v `Checkbox` (`5 × 9 px`, `9 px`), tečky `6–8 px`
  (`FormEditor`, `FeedFilter`, `Feed`, `ThemeSwatches`), průběhový pruh
  dashboardu (`.dev-progress` `8px`), svislý oddělovač tabů ve `WildShell`
  (výška `22px`; jeho šířka `1px` je čára);
- px se značkou: tloušťka posuvníku lišty tabů (`TabBar`,
  `::-webkit-scrollbar` `3px`), dva okraje ve výšce výběru barvy
  (`ColorField`, `calc(… + 2px)`).

**Zůstává v px bez značky** (kontrola to nevidí, je to v JS): odsazení
vnořeného modalu `depth × 30 px` v `Modal.cardStyle` — vzniká
`calc(40rem - 60px)`, což je platné CSS; rozlišuje hierarchii, s písmem
nemá růst. V JS se px s rem řetězcem nikde nesčítá.

**Kontrola.** Rozměrové propy hledá atribut `width` / `height` s px
a k němu zpětně poslední otevírací značku (hledání `<Tag …>` by se rozbilo
o `>` v `onClose={() => …}`). Značka `px-ok` u propu smí být i HTML
komentář. Layout tokeny podle názvu `--shpd-*-width*` / `--shpd-*-height*`
v celém `variables.css`, ne podle komentáře sekce. Výchozí hodnotu propu
ve `<script>` (`width = '40rem'`) kontrola nevidí — JavaScript záměrně
přeskakuje; je to v docstringu a v `docs/design-system.md` §10. Regulární
výraz ověřen na 64 zkušebních deklaracích (37 má projít — mj.
`border-width`, `outline-width`, `stroke-width`, `column-rule-width`,
`line-height`, `<svg width="16">`; 27 má selhat).

**Drobnosti.** Sloupec názvu reportu má `min-width: 14rem`: při 390 px se
u přiznání k DPH zúžil na 99 px (až 8 řádků po slovech), se `14rem` jsou
názvy na 2–3 řádcích (Hlavní kniha: 77 z 84 řádků nejvýš 3 řádky;
`12rem` dávalo 66). `docs/frontend.md` `Modal width` v px neuváděl —
upravena jen věta o kontrole; navíc `docs/edit-forms.md` §9,
`docs/dev/dev-daily.md` a řádek o skriptu v `CLAUDE.md`.

**Ověřeno:**

- `npm test` (277), `npm run build`, obě kontroly CSS, oba grepy ze zadání
  bez nálezu (kromě řádků `px-ok`).
- **Shoda při 16 px na úrovni CSS**: sestavené CSS před převodem a po něm
  je po přepočtu rem × 16 shodné — aplikace 1716 pravidel, dashboard 379;
  jediný rozdíl je `top` panelu motivu (`calc()` se stejným výsledkem
  64 px). V JS bundlu se změnily jen řetězce propů a `colStyle`.
- **Snímky v Chromiu (Playwright)** nad volným demo zdrojem dat, 1280
  a 1600 px, před a po: přihlášení, tři shelly (domů, sekce, seznam
  s detailem), formulář, odeslání, náhled tisku, uživatelské menu,
  Nastavení, vývojářský dashboard — 29 z 38 snímků shodných po pixelech,
  zbylé se liší jen proměnlivým obsahem (hash verze v pruhu dashboardu,
  řádky logu, hover tlačítka).
- **Písmo 20 px** (CDP `Page.setFontSizes`), 1280 px: sidebar 325 px,
  seznam 500 px, detail 455 px; pás classic a rail wild 95 px, horní lišta
  70 px; stránka nikde nepřetéká, modaly se vejdou do okna (formulář
  1220 × 810, odeslání 950 × 810, náhled tisku 1200 × 810 v okně
  1280 × 900), popover review (500 px) drží 8 px od okraje.
- **Úzký detail při 20 px** (otevřený bod #138, tady neřešeno): okno
  1024 px, sidebar shell — detail vedle seznamu má 199 px; obsah se složí,
  tabulky rolují ve svém obalu, stránka nepřetéká. Classic a wild shell
  nechávají detailu 429 px.
- **Telefon 390 px**: report — název na 2–3 řádcích, tabulka roluje
  ve svém obalu, stránka ne. **Dashboard 480 px**: detail úlohy do strany
  neroluje (dřív stránka 627 px v okně 480 px).

**Neověřeno** (ruční): Firefox s písmem 20 px v nastavení prohlížeče,
skutečný telefon, panel motivu ve třech shellech (poloha je `calc()` nad
tokeny, ve snímcích není) a chat panel.

## Pasti

- **1 : 1, ne „hezčí čísla“.** `260px` je `16.25rem`, ne `16rem`. Vizuální
  změny nejsou cílem — při 16 px musí být všechno pixel na pixel stejné.
- **JS, který počítá s px.** Kde JS čte nebo skládá rozměr (`colStyle`,
  propy `width`, výpočty pozic popoveru, drawer, resize), ověřit, že
  hodnotu jen dosazuje do CSS (rem projde), nebo měří vykreslený prvek
  (`getBoundingClientRect` — v px je to správně). Číslo v px se v JS
  nesmí sčítat s rem řetězcem.
- **Layout tokeny v `calc()`.** `themePanelLeftOffset` a podobné skládají
  `calc(var(--shpd-sidebar-width) + …)` — rem v tokenu projde, ověřit
  polohu panelu motivu ve všech třech shellech.
- **Kontrola nesmí chytat čáry jinde.** `border-width`, `outline-width`,
  `stroke-width`, `column-rule-width` nejsou rozměry — regulární výraz
  s lookbehind je vynechá; doložit v komentáři skriptu.
- **Větší písmo zúží detail.** S rem roste při větším písmu i sidebar
  a seznam — detail vedle seznamu v úzkém okně bude ještě užší. Je to
  otevřený bod v #138 (vlastní task), tady ho neřešit, jen zmínit
  v Odchylkách, co bylo vidět při 20 px.
