# Shipard — Frontend Architecture

## 1. Přehled

Shipard frontend je single-page aplikace (SPA) postavená na **Svelte 5**, která komunikuje s existujícím REST API. Aplikace se chová jako desktopová — bez klasické URL navigace, s interním stavem řídícím co je vidět.

### Principy

- **Server-driven UI** — server definuje strukturu (sloupce tabulek, pole formulářů, navigaci), klient renderuje podle schémat
- **Definovat, ne programovat** — nový modul = nová definice na serveru, zero práce na klientovi
- **Konzistentní UX** — uživatel se naučí prohlížeč tabulek a formuláře → umí celou aplikaci
- **Minimální klientská logika** — silná JS knihovna se základními komponentami, inteligentní renderery
- **Žádné URL routing** — stav navigace interně (sidebar, taby, dialogy), ne přes URL

### Technologický stack

| Komponenta | Technologie | Důvod |
|------------|------------|-------|
| UI framework | Svelte 5 | Kompiluje do čistého JS (žádný runtime), reaktivita bez boilerplate, blízké vanilla HTML/JS |
| Build tool | Vite | Nativní podpora Svelte, rychlý HMR pro vývoj |
| CSS | Vlastní (bez frameworku) | Plná kontrola, žádný overhead z nepoužívaných stylů |
| Ikony | Font Awesome (SVG/JS) | Tree-shakeable, jen použité ikony v bundle, inline SVG |
| HTTP klient | Fetch API | Nativní v moderních prohlížečích, nepotřebujeme knihovnu |
| State management | Svelte stores (runes) | Vestavěný v Svelte 5, nepotřebujeme externí knihovnu |

### Cílové prohlížeče

Chrome, Firefox, Edge — poslední 2 roky. Žádná podpora IE nebo starších verzí.

### Jazyk

- **UI texty:** čeština (výchozí jazyk aplikace), s podporou vícejazyčnosti přes existující i18n systém
- **Kód a komentáře:** angličtina

---

## 2. Adresářová struktura

```
frontend/
├── package.json
├── vite.config.js
├── svelte.config.js
├── index.html
├── src/
│   ├── main.js                         # Bootstrap — mount Svelte app
│   ├── App.svelte                      # Root — přepíná login/app shell
│   ├── api/
│   │   ├── config.js                   # Detekce DS ID z URL, API_BASE_URL
│   │   ├── client.js                   # HTTP klient (fetch wrapper s auth, refresh, retry)
│   │   └── auth.js                     # Login, refresh, logout (raw fetch)
│   ├── icons.js                        # Centrální registr ikon (importy, mapování, resolveIcon)
│   ├── stores/
│   │   ├── auth.svelte.js              # Auth stav (token, user, isAuthenticated)
│   │   ├── navigation.svelte.js        # Aktivní položka navigace
│   │   └── theme.svelte.js             # Light/dark/auto theme s persistencí
│   ├── components/
│   │   ├── ui/                         # Základní UI prvky
│   │   │   ├── Button.svelte
│   │   │   ├── Input.svelte
│   │   │   ├── NumberInput.svelte
│   │   │   ├── TextArea.svelte
│   │   │   ├── Select.svelte
│   │   │   ├── Checkbox.svelte
│   │   │   ├── DateInput.svelte
│   │   │   ├── Icon.svelte             # Univerzální ikona (inline SVG z FA definition)
│   │   │   └── Modal.svelte
│   │   ├── chrome/                     # Primitivy aplikačního chrome — shelly je komponují
│   │   │   ├── NavTree.svelte          # Rekurzivní renderer navigačního stromu
│   │   │   ├── NavIconStrip.svelte     # Plochý pás ikon leafů (sbalený sidebar)
│   │   │   ├── NavFlyoutStrip.svelte   # Pás uzlů úrovně 2 s Popover flyouty (classic)
│   │   │   ├── NavTabStrip.svelte      # Ikonové záložky uzlů úrovně 2 s dropdowny (wild)
│   │   │   ├── UserMenu.svelte         # Avatar + dropdown (nastavení, jazyk, odhlásit)
│   │   │   ├── BrandingHeader.svelte   # Ikona aplikace + logo
│   │   │   └── ModeBackBar.svelte      # „← Zpět do aplikace" v settings/account
│   │   ├── shells/
│   │   │   ├── index.js                # Registry shellů (jméno → komponenta)
│   │   │   ├── SidebarShell.svelte     # Výchozí shell (sidebar/drawer + content)
│   │   │   ├── ClassicShell.svelte     # Classic shell (horní menu + levý pás)
│   │   │   ├── classic/TopMenuBar.svelte # Horní menu classic shellu
│   │   │   ├── WildShell.svelte        # Wild shell (rail + záložky + AI asistent sekce)
│   │   │   └── wild/SectionRail.svelte # Svislý rail sekcí wild shellu
│   │   ├── layout/
│   │   │   ├── AppShell.svelte         # Globální starosti + resolver shellu
│   │   │   ├── Sidebar.svelte          # Sidebar — kompozice chrome primitiv
│   │   │   └── ContentArea.svelte      # Hlavní oblast — renderuje aktivní položku
│   │   ├── auth/
│   │   │   └── LoginScreen.svelte      # Přihlašovací obrazovka
│   │   ├── browser/
│   │   │   └── TableBrowser.svelte     # Generický prohlížeč tabulek
│   │   ├── viewer/
│   │   │   ├── Viewer.svelte           # Viewer shell (tab bar, search, infinite scroll, detail)
│   │   │   ├── ViewerRow.svelte        # Jeden řádek seznamu (t1/t2/t3, stateStyle)
│   │   │   ├── ViewerDetail.svelte     # Detail panel: hlavička + taby s content bloky
│   │   │   └── ViewerToolbar.svelte    # Toolbar akcí (Přidat, Otevřít, …)
│   │   └── form/
│   │       ├── FormField.svelte        # Dynamický field renderer (typ → komponenta)
│   │       ├── FormRenderer.svelte     # Generický formulář z metadat
│   │       └── FormDialog.svelte       # Modal wrapper pro FormRenderer
│   ├── utils/
│   │   ├── navTree.js                  # Čisté helpery nad navigačním stromem (flattenLeaves, findLeafById, findRootSectionId)
│   │   └── shell.js                    # resolveShell + normalizace hodnot shellu (KNOWN_SHELLS)
│   └── styles/
│       ├── variables.css               # CSS custom properties (barvy, spacing, typography)
│       ├── reset.css                   # CSS reset
│       └── base.css                    # Základní typografie a layout
```

### Build a nasazení

```bash
cd frontend
npm install
npm run build     # → výstup do ../public/app/
```

Nginx konfigurace: viz `docs/nginx/app.conf`.

### Dev mód — DS ID v URL

V dev módu (IP adresa) se aplikace otevírá na `http://{ip}/{ds-id}/app/`. Frontend automaticky detekuje DS ID z URL a přidává ho jako prefix ke všem API voláním (`/{ds-id}/api/v1/...`). Logika je v `api/config.js`.

V produkčním módu (subdoména) se DS ID nepoužívá — API je na `/api/v1/...`.

---

## 3. Přihlášení a autentizace

### API endpointy

```
POST /api/v1/_auth/login      # login + password → session token
POST /api/v1/_auth/refresh    # starý token → nový token
DELETE /api/v1/_auth/logout   # invalidace tokenu
```

Token má prefix `shpd_st_`, expirace 24h, uložen v `core_system_sessions`.

### Flow

1. `App.svelte` kontroluje auth store → není přihlášen → `LoginScreen`
2. Uživatel zadá login + heslo → `POST /_auth/login`
3. Úspěch → token + user do auth store + `localStorage`
4. App přepne na `AppShell`

### Bezpečnost

- Token v `localStorage` (přijatelné pro session token s expirací)
- Automatický refresh při 401 s retry původního requestu
- Logout vyčistí `localStorage`
- HTTPS v produkci

---

## 4. Aplikační shell

### Shelly (volba chrome, UI shells Fáze 4)

Aplikace má vyměnitelné navigační chrome — **shelly** (`docs/ui-shells.md`).
Shell je Svelte komponenta registrovaná v mapě
`components/shells/index.js` (`{sidebar, classic, wild}`); jména drží
`KNOWN_SHELLS` v `utils/shell.js` (store nesmí importovat komponenty).

- **`AppShell.svelte`** už nekreslí layout — zůstaly mu globální starosti
  (Ctrl/Cmd+K, badge polling, ThemePanel, CommandPalette, ChatPanel,
  load app navigace) a **resolver shellu**: alternativní shell platí jen
  na desktopu v app módu (`!layoutStore.isMobile && mode === 'app'`);
  mobil a settings/account módy dostávají vždy `SidebarShell`. Přepnutí
  = soft swap komponenty — `navigationStore` přežije, uživatel zůstane
  na aktivní položce.
- **`SidebarShell.svelte`** — 1:1 extrakce původního layoutu AppShellu:
  desktopový sidebar i mobilní větev (MobileTopBar + drawer + overlay).
- **`ClassicShell.svelte`** — horní menu agend (`classic/TopMenuBar`)
  + levý pás `chrome/NavFlyoutStrip` (uzly úrovně 2 aktivní sekce; leaf
  naviguje, skupina otevírá `ui/Popover` flyout s úrovní 3, oddělovače
  podskupin; max jeden otevřený flyout, pás scrolluje). Domeček = `_top`
  (root-level leafy v pásu), badge sekcí vč. `_top` čte shell přímo ze
  `sectionBadgesStore`. Desktop-only.
- **`WildShell.svelte`** (UI shells Fáze 6) — AI-first kompaktní shell:
  svislý rail sekcí (`wild/SectionRail`, ikony s badge vč. `_top` na
  domečku, dole paleta + UserMenu compact) + horní ikonové záložky
  úrovně 2 (`chrome/NavTabStrip`) s AI záložkou jako prvním vstupem do
  sekce (`chat/SectionAssistant`, ikona chatu, gate = Chat leaf ve
  stromu). Stav prohlížení je shell-lokální
  (`stores/wildShell.svelte.js`): `browsingSection` + paměť poslední
  záložky per sekce (`lastTabBySection`) — klik na rail nenaviguje, jen
  mění prohlíženou sekci; přistání řeší čistá funkce
  `utils/wildLanding.js` (první vstup → AI záložka, jinak poslední
  stav; domeček AI nemá a padá na dashboard). Externí navigace
  (paleta, deep link) srovnává prohlížení efektem reagujícím jen na
  změnu `activeSection`/`activeId` proti poslední adoptované navigaci
  — tu drží store, takže mount dožene i navigaci provedenou bez
  namontovaného shellu (paleta ze settings módu). `ContentArea` zůstává při AI
  záložce mounted (skrytá přes CSS) — viewery nepřicházejí o stav.
  Paměť přežije settings/account mód (module-level store), reload ji
  čistí. Desktop-only.
- **`stores/shell.svelte.js`** — follow/override/dsDefault po vzoru
  theme, ale bez anti-flash mechaniky (localStorage `shpd_shell` /
  `shpd_ds_shell` jen boot cache); efektivní shell přes čistý
  `resolveShell()` s fallbackem `sidebar`. Volba v Nastavení účtu
  (`ShellField`, okamžitě) a Nastavení aplikace (`DsShellField`, přes
  Uložit) — `docs/app-settings.md`.
- **ThemePanel** dostává levý offset jako CSS délku (`leftOffset`) —
  hlásí ji aktivní shell přes bind (sidebar dle collapsed, classic
  konstantou `--shpd-classic-strip-width`, wild konstantou
  `--shpd-sidebar-width-collapsed` = šířka railu).
- **App nav strom** načítá `navigationStore.loadAppNavTree()` (trigger:
  AppShell `$effect` při vstupu do app módu) — strom potřebují všechny
  shelly i paleta, fetch nesmí záviset na namontovaném shellu. Ordering
  side-effectů (strom → deep-link reportu → default položka) drží store.

Výchozí (sidebar) layout je bez horní lišty — logo a uživatelské info
jsou integrovány v sidebaru:

```
┌──────────┬──────────────────────────────────────────┐
│ Shipard  │                                          │
│ [◀]      │  ContentArea                             │
├──────────┤                                          │
│ Sidebar  │  ┌─ Viewer ───────────────────────────┐ │
│ (server) │  │  [Přidat]  [Otevřít]               │ │
│          │  │  [Aktivní][Archív][Vše][Koš]        │ │
│ ─ Systém │  │  [🔍 Hledat...]                    │ │
│   Users  │  │  ┌─ Seznam ──────┐ ┌─ Detail ────┐ │ │
│   Sett.  │  │  │  Řádek 1      │ │  Tab 1 Tab2 │ │ │
│ ─ Základ │  │  │  Řádek 2      │ │  ...obsah...│ │ │
│   Osoby  │  │  │  ...          │ │             │ │ │
│   ...    │  │  └───────────────┘ └─────────────┘ │ │
├──────────┤  └───────────────────────────────────────┘ │
│ J. Novák │                                          │
│ Odhlásit │                                          │
└──────────┴──────────────────────────────────────────┘
```

### Mobilní režim (drawer)

Na viewportu ≤ 768px se shell přepne do mobilního režimu (řídí
`layout.svelte.js` store přes `window.matchMedia`):

- Nahoře se objeví `MobileTopBar` — hamburger (otevře drawer), titul
  aktuální obrazovky (`navigationStore.activeItem.label`), vpravo akce.
  Akce + kontext (list/detail) publikuje aktuální obrazovka přes
  **screen surface** kanál v `layout.svelte.js` (`setScreenSurface` /
  `clearScreenSurface`, gettery `surface*`) — obecný kontrakt „obrazovka
  publikuje, shell rozhoduje kde vykreslí" (`docs/ui-shells.md` §6);
  MobileTopBar je jeho mobilní konzument. Když nikdo nic nepublikuje
  (`surfaceContext === null`, např. dashboard), fallback na hamburger
  + titul z navigace + prázdný slot. Detaily publikování viz
  **Mobilní viewer (list/detail)** v sekci 7.
- Sidebar vystoupí z toku layoutu a stane se z něj **drawer** — vysune
  se zleva přes obsah (`position: fixed`, `transform: translateX`),
  zbytek ztmaví overlay. Recykluje stejný `Sidebar.svelte` jako desktop.
- Drawer se zavírá: klikem na overlay, ✕ tlačítkem v hlavičce drawera
  (nahrazuje desktopový collapse toggle), klávesou Esc, a klikem na
  položku navigace.
- Sbalovací logika (`collapsed`) se na mobilu nepoužívá — drawer je buď
  otevřený, nebo zavřený.

Na desktopu (> 768px) zůstává sidebar pevným sloupcem se sbalováním
beze změny.

**Breakpoint 768px** je definovaný na dvou místech, která musí ladit:
JS konstanta `MOBILE_BREAKPOINT` v `layout.svelte.js` a literál
v `@media` queries v komponentách. Stejný vzor jako theme/language
bootstrap ↔ store.

### Mode systém — App / Settings / Account

Aplikace má tři navigační módy: `'app'` (běžná práce), `'settings'`
(Nastavení aplikace, DS-scoped) a `'account'` (Nastavení účtu, per-user).
Mode drží `navigation.svelte.js` ve `$state`.

- **Vstup do Nastavení aplikace**: dropdown v patce sidebaru → „Nastavení
  aplikace" → `navigationStore.enterSettings()`
- **Vstup do Nastavení účtu**: dropdown v patce sidebaru → „Nastavení
  účtu" → `navigationStore.enterAccount()`
- **Výstup**: tlačítko „← Zpět do aplikace" v hlavičce sidebaru pod
  logem → `navigationStore.exitToApp()` (společné pro settings i account)
- **Stav per mode**: každý mode si pamatuje vlastní `activeItem`
  (`appActiveItem` / `settingsActiveItem` / `accountActiveItem`). Přepnutí
  app→settings→app (i app→account→app) vrátí uživatele na poslední položku
  v app módu

Navigační strom per mode:
- `'app'` → `GET /_ui/navigation` — načítá `navigationStore.loadAppNavTree()`
  (volá AppShell; strom sdílí všechny shelly i paleta), Sidebar ho čte
  z `navigationStore.appNavTree`
- `'settings'` → `GET /_ui/settings/navigation` — lokální fetch v Sidebaru
- `'account'` → `GET /_ui/account/navigation` — lokální fetch v Sidebaru

V režimu `!== 'app'` je v hlavičce sidebaru navíc tlačítko „Zpět do
aplikace" (pod logem); v dropdownu patky se zobrazují jen ta nastavení, ve
kterých uživatel právě není — v settings módu se skrývá „Nastavení
aplikace", v account módu „Nastavení účtu" (`mode !== 'account'` resp.
`mode !== 'settings'`). Stránka **Základní** v account módu nese pole vzhledu
(`ThemeField`) a jazyka (`LanguageField`) — řízené widgety vázané na
`themeStore` / `language` (viz sekci 11). Detaily account módu a per-user
úložiště: `docs/app-settings.md` sekce 8.

Žádné URL routing — mode se nepamatuje napříč reloady (po F5 se vrátí
do `'app'` módu). Persistence módu je out of scope této fáze.

### Sidebar — struktura

`Sidebar.svelte` je **kompozice sdílených primitiv chrome**
(`components/chrome/`) — budoucí shelly (viz `docs/ui-shells.md`) tytéž
primitivy komponují jinak, místo reimplementace:

| Primitiv | Obsah |
|---|---|
| `NavTree` | rekurzivní renderer stromu (skupiny s toggle, leafy, aktivní stav); interní stav rozbalení + auto-expand cesty k aktivní položce |
| `NavIconStrip` | plochý pás ikon leafů (`flattenLeaves`) — sbalený režim |
| `NavFlyoutStrip` | pás uzlů úrovně 2 (classic shell): leaf naviguje, skupina otevírá Popover flyout s úrovní 3 |
| `NavTabStrip` | horizontální ikonové záložky uzlů úrovně 2 (wild shell): leaf naviguje, skupina otevírá Popover dropdown s úrovní 3 — chování 1:1 s NavFlyoutStrip, jen orientace; o AI záložce nic neví (kreslí ji WildShell vedle) |
| `UserMenu` | avatar + dropdown (Nastavení účtu/aplikace, jazyk, odhlásit); `compact` varianta se side-overlay dropdownem, `direction="down"` pro horizontální top bar |
| `BrandingHeader` | ikona aplikace (branding slot) + logo/shortName, čte `appInfoStore` sám |
| `ModeBackBar` | „← Zpět do aplikace" v settings/account módu, `compact` varianta |

Sidebaru samotnému zůstává: fetch settings/account navigace (app strom
drží `navigationStore`), `collapsed` stav + toggle (specifikum sidebar
shellu), loading/error stav a normalizace kliknuté položky
(`handleItemClick` → `navigationStore.navigate`).
Layout je flex column: header (BrandingHeader + toggle), volitelný
ModeBackBar, scrollovatelný nav (`flex: 1`, NavTree/NavIconStrip),
UserMenu v patce.

App strom drží `navigationStore` (`loadAppNavTree()`) — z něj store
derivuje getter `activeSection` (id sekce úrovně 1, do níž patří aktivní
leaf; `_top` leafy a sekundární módy → `null`). Čistá derivace bez zápisu
při navigaci, funguje i pro `navigateToViewer()` z dashboardu a deep linky.
Tree helpery (`flattenLeaves`, `findLeafById`, `findRootSectionId`) žijí
v `utils/navTree.js` jako čisté, unit-testované funkce.

### Sidebar — kolapsibilní

Sidebar je kolapsibilní na úzký proužek (48px). Ve sbaleném stavu:

- Ikona aplikace, logo a sekce navigace (groups, sub-groups) jsou skryté
- Klikatelné položky menu (leaves) zůstávají vidět jako plochý seznam
  ikon (`NavIconStrip`) — `flattenLeaves(navTree)` rekurzivně vybere
  všechny nody s `type` v depth-first pořadí. Každá ikona má `title`
  atribut s názvem položky.
- Aktivní položka se zvýrazní stejně jako v rozbaleném stavu
  (oranžový accent proužek vlevo + modré primary pozadí)
- V settings módu zůstává v hlavičce sidebaru kompaktní tlačítko zpět
  (`ModeBackBar` s `compact`, jen ikona `iconChevronLeft`)
- V patce zůstává jen kruhový avatar uživatele (`UserMenu` s `compact`);
  klik otevře dropdown menu jako overlay vpravo od sidebaru
- Rozbalení/sbalení jen přes toggle tlačítko v hlavičce. Hover myší
  sidebar nerozbaluje (klávesová zkratka pro toggle je plánovaná do
  budoucna).

Stav řídí Svelte runes: `collapsed` (toggle tlačítkem); dle něj Sidebar
přepíná `NavTree` ↔ `NavIconStrip` a předává `compact` do UserMenu
a ModeBackBar.

### Sidebar — dynamická navigace ze serveru

Sidebar načítá navigační strom z `GET /_ui/navigation`. Server seskupuje
viewery a tabulky do **sémantických sekcí** — ne podle prefixu module ID, ale
podle pole `navSection`, které každý viewer/tabulka deklaruje.

- **Sekce definuje cfgItem `global.navSections`**
  (`modules/install/base/config/navSections.jsonc`) — `id`, `name`/`name:cs`/
  `name:en`, `icon`, `order`. Analogie k `global.settingsSections`.
  `NavigationController` ho čte přes `ConfigRuntime::cfgItem` (jako
  `SettingsController` settingsSections); když compiled config chybí, použije
  vestavěný PHP fallback (degradovaně, ne crash).
- **`navSection` + `navOrder` na vieweru** (v `module.jsonc` `viewers[]`) určují,
  do které sekce viewer patří a v jakém pořadí. Tabulky bez vieweru (generický
  fallback item) mohou `navSection`/`navOrder` nést v `*.jsonc`.
- **Sentinel `navSection: "_top"`** = root-level leaf nad sekcemi (Došlá pošta,
  Úkoly). Řadí se dle `navOrder` a vkládá za Dashboard/Chat, před sekce.
- **Dashboard a Chat** zůstávají hardcoded root leaves (nejsou viewery).
- **Fallback:** co nemá `navSection` (nebo má neznámou sekci) → sekce `system`
  (nic nezmizí, kdyby přibyl viewer bez konfigurace). Prázdné sekce se vynechají.
- **Skrytí z navigace:** `hideFromNavigation: true` funguje i na **vieweru**
  (nejen na tabulce). Použito pro souhrnný `docs.core.heads` — skryje JEN ten
  viewer; sdílenou tabulku `docs_core_heads` dál zobrazují per-typ viewery
  Faktury přijaté/vydané. (Tabulka reprezentovaná jakýmkoli viewerem se nikdy
  nevykreslí jako syrový fallback table item — viz `tablesWithViewer`.)

Cílové uspořádání: Dashboard → Chat → Došlá pošta → Úkoly → Základní → Nákup →
Prodej → Účtárna → Systém.

```json
{
    "success": true,
    "data": [
        {"id": "dashboard", "label": "Dashboard", "type": "dashboard", "icon": "dashboard"},
        {"id": "chat", "label": "Chat", "type": "chat", "icon": "chat"},
        {"id": "viewer:core.mail.incoming", "label": "Došlá pošta", "type": "viewer", "viewerId": "core.mail.incoming", "icon": "mail"},
        {
            "id": "basic",
            "label": "Základní",
            "icon": "folder",
            "children": [
                {"id": "viewer:base.persons", "label": "Osoby", "type": "viewer", "viewerId": "base.persons", "icon": "user"}
            ]
        }
    ]
}
```

API tvar (`id`/`label`/`children`/`type`/`icon`/`viewerId`/`table`) je shodný
jako u dřívějšího prefix-groupingu — `NavTree` rozlišuje root-leaf
(má `type`) vs skupinu (má `children`) a nemění se. Klik v sidebaru přímo
nahradí obsah hlavní oblasti. `navigation.svelte.js` spravuje jedinou aktivní
položku (`activeItem`). `ContentArea` renderuje obsah podle typu (`table` →
`TableBrowser`, `viewer` → `Viewer`).

### Sidebar — badge stavů sekcí

Signalizace „v této sekci na tebe něco čeká" (UI shells Fáze 3, issue #45)
— pilot v rozbaleném NavTree.

- **Data:** `GET /_ui/section-badges` — serverová agregace dashboard feedu
  per `navSection` karet (`{sections: {"<id>": {count, severity}}}`, jen
  neprázdné sekce; `docs/dashboard.md` §7).
- **Store `stores/sectionBadges.svelte.js`:** `badges` ($state mapa),
  `refresh()`, `startPolling()`/`stopPolling()`. Polling à 60 s + refresh
  při focusu okna; tick se přeskočí při `document.hidden`. Chyba fetche
  i 401 ponechají poslední známý stav — tichá degradace (vzor AI shrnutí).
  Životní cyklus řídí `AppShell` (`$effect` s cleanup).
- **Render:** `Sidebar` předává mapu do `NavTree` propem `sectionBadges`
  (jen app mód — settings/account strom badge nemá). `NavTree` kreslí na
  root hlavičkách sekcí tečku v barvě severity (`--shpd-color-warning` /
  `--shpd-color-danger`) + počet (cap `99+`), `aria-label` s ICU plurálem
  (`sidebar.sectionBadge`). Klíč `_top` se ignoruje přirozeně (není uzlem
  stromu) — položky `_top` jsou trvale viditelné.
- **Rozsah:** rozbalený strom — tedy i mobilní drawer (renderuje tutéž
  `Sidebar`); collapsed pás ikon (`NavIconStrip`) zůstává bez badge.
  Classic shell (Fáze 4) kreslí badge na položkách horního menu
  a `_top` badge na domečku (`TopMenuBar` čte store přímo).

### Command palette

Spotlight/Cmd-K overlay pro rychlou navigaci — **shell-nezávislá**: renderuje
ji `AppShell`, shelly dodávají jen trigger (kontrakt `docs/ui-shells.md` §4,
koncept §9). Realizováno Fází 2 UI shells (issue #45).

- **Trigger:** globální zkratka `Ctrl/Cmd+K` (keydown v `AppShell`; nereaguje
  při focusu v `input`/`textarea`/`contenteditable` mimo paletu) + lupa
  v hlavičce sidebaru (rozbalený režim a mobilní drawer — otevření palety
  drawer zavře; ve sbaleném režimu ikona nad `NavIconStrip`). Tooltip nese
  zkratku dle platformy (`⌘K` / `Ctrl+K`).
- **Architektura providerů** (`stores/palette.svelte.js`): zdroj nabídky =
  záznam `SOURCE_DEFS` (mód + i18n klíč skupiny + URL stromu) — v1 tři
  navigační stromy (app / settings / account) + skupina recents na prázdný
  vstup. Přidání zdroje (nápověda, fulltext záznamů — v2) = nový provider,
  ne přepis. Stromy se stahují lazy při prvním otevření a cachují po dobu
  session; app strom přednostně z `navigationStore.appNavTree`. Selhání
  jednoho zdroje = chybový řádek ve výsledcích, paleta zůstává použitelná.
- **Matching** (`utils/paletteMatch.js`, čisté funkce): folding diakritiky
  („uctarna" → „Účtárna") per znak s mapou indexů — zvýraznění shod
  (`ranges`) se mapuje na původní label. Ranking prefix > začátek slova >
  subsequence; remíza → boost položek z recents; max 10 výsledků na skupinu,
  žádná virtualizace.
- **Recents** (`utils/recents.js`): localStorage `shpd_recents_<userId>`
  (DS izolaci řeší origin/subdomain), pole `{id, label, icon, type, ts}`,
  cap 7, dedup dle id. Zaznamenává `navigationStore.navigate()` — **jen app
  mód** a jen položky s `id`, takže se učí i z běžné navigace sidebarem;
  ad-hoc cíle (`navigateToViewer`/`navigateToPanel`) se neukládají. Paleta
  při zobrazení resolvuje živý leaf ze stromu podle id (zmizelé položky se
  přeskočí).
- **Výběr cíle z jiného módu** = přepnutí módu
  (`enterSettings`/`enterAccount`/`exitToApp`) + `navigate()` s originálním
  objektem leafu ze stromu.
- **UI** (`chrome/CommandPalette.svelte`): vlastní overlay v horní třetině
  (ne `ui/Modal`), lifecycle dle vzoru `ThemePanel` (Esc, klik na backdrop,
  keydown listener registrovaný až po otevření), šipky/Enter, autofocus,
  `aria-activedescendant`; aktivní řádek mění `mousemove` (ne hover),
  aby se výběr nehýbal pod rukama při psaní.

---

## 5. Prohlížeč tabulek (TableBrowser)

Generická komponenta — dostane název tabulky a vykreslí prohlížeč s daty z API.

### Flow

1. Fetch metadata: `GET /_meta/tables/{table}` → sloupce, typy, groups
2. Fetch data: `GET /{table}?limit=20&offset=0` → záznamy
3. Vykreslí tabulku podle metadat

### Funkce

- **Dynamické sloupce** — z metadat, filtrované (bez id, password_hash, json)
- **Formátování podle typu** — varchar→text, int→číslo vpravo, boolean→Ano/Ne, date→dd.mm.yyyy, datetime→dd.mm.yyyy hh:mm, numeric→desetinná místa
- **Řazení** — klik na hlavičku, toggle asc/desc, API sort parametr
- **Stránkování** — offset-based, 20/50/100 na stránku, předchozí/další
- **Tlačítko „Nový záznam"** — otevře FormDialog pro vytvoření
- **Dvojklik na řádek** — otevře FormDialog pro editaci

---

## 6. Editační formuláře

Generický renderer — `FormRenderer` dostane tabulku a volitelně ID záznamu, stáhne metadata a vykreslí formulář.

### Flow

1. Fetch metadata: `GET /_meta/tables/{table}` → sloupce, typy, groups, nullable
2. Pokud editace: fetch záznamu `GET /{table}/{id}`
3. Vykreslí formulář — `FormField` mapuje typ sloupce na UI komponentu
4. Uložení: `POST /{table}` (nový) nebo `PUT /{table}/{id}` (editace)
5. Validační chyby ze serveru se mapují na pole formuláře

### Mapování typ → komponenta (FormField)

| Typ sloupce | Komponenta |
|-------------|-----------|
| varchar | Input (text) |
| text, longtext | TextArea |
| int, smallint, bigint | NumberInput (step=1) |
| numeric | NumberInput (step z scale) |
| boolean | Checkbox |
| date | DateInput |
| datetime | Input (datetime-local) |
| enumInt, enumString | Select (budoucí: options z konfigurace) |

### Layout

- Pole seskupené podle `columnGroups` z metadat
- Dvousloupcový grid (responzivní → 1 sloupec na úzkých obrazovkách)
- Auto-managed pole (id, created, modified) a systémové pole (`system: true`) se nezobrazují
- password_hash se nezobrazuje v editaci

**`FormInline` na mobilu** — inline skupina (víc polí na jednom řádku,
např. „Platnost od / do") se na ≤ 768px rozpadne na samostatná pole pod
sebou, každé se svým labelem vedle inputu (splyne s běžnými poli). Je to
strukturní přepnutí markupu řízené `layoutStore.isMobile` (ne CSS) —
desktop renderuje flex skupinu s mini-labely, mobil sérii label+input
grid sourozenců, které `FormColumn` grid naskládá pod sebe. Input je
sdílený mezi větvemi přes Svelte snippet `inputFor`. Detaily v
[`edit-forms.md`](edit-forms.md) sekce *4.4 inline*.

**`FormStateBar` na mobilu** — footer formuláře má na desktopu Uložit
+ všechny přechody dokladu (Potvrdit, Archivovat, Storno…) vedle sebe.
Na ≤ 768px se na úzkou obrazovku nevejdou, proto jdou do kebab menu (⋮)
přes `Popover` (placement `top` — footer je dole, otevírá se nahoru):
**destruktivní** přechody (`archive`/`trash`/`cancelled` — Archivovat,
Stornovat, Smazat; v kebabu červeně), **`concept`** (Uložit jako koncept
— pomocná akce, v kebabu neutrálně) a přechody s příznakem **`mobileKebab`**
v docStates (vedlejší akce, jejíž `stateStyle` na rozlišení nestačí — např.
„Pozastavit" u úkolů má `edit` stejně jako „Opravit" u faktur). **Postupové**
přechody (Potvrdit, V pořádku, Opravit…; v daném stavu jich je max pár)
zůstávají viditelné vedle Uložit. Stejný vzor jako
kebab ve vieweru (`MobileTopBar`) — strukturní přepnutí (tlačítka → kebab)
řízené `layoutStore.isMobile` (ne CSS). Kebab volá stejný `onTransition`
jako tlačítko, takže případný `confirm` proběhne stejně. Detaily v
[`edit-forms.md`](edit-forms.md) sekce *Toolbar formuláře (FormStateBar)*.

### FormDialog

Modal wrapper — otevírá se z TableBrowser (tlačítko / dvojklik). Po uložení se prohlížeč automaticky refreshuje.

---

## 7. Viewer systém

Viewer je specializovaný prohlížeč pro složitější tabulky — na rozdíl od generického `TableBrowser` (který funguje čistě z metadat) viewer implementuje vlastní renderování řádků, filtrování a detail panel. Každý viewer je PHP třída dědící `TableViewer`.

### Grid layout (tabulka)

Vedle výchozího list layoutu (t1/i1/t2/i2/t3) umí viewer **grid** — klasickou
tabulku se sloupci, sticky hlavičkou, volitelným součtovým footerem a detailem
v non-modálním slide-over draweru. Layout je prezentační režim jednoho vieweru
(ne jiná třída): `selectRows()`, filtry, search i detail zůstávají sdílené,
viewer navíc implementuje `getGridColumns()` + `renderGridRow()` (volitelně
`getDefaultLayout()`, `renderGridFooter()`, `getGridOptions()`). Meta pak vrací
`layouts`/`defaultLayout`/`grid`, endpoint `rows` přijímá `layout=grid`.
Na mobilu (≤ 768 px) grid degraduje na list — `renderRow()` zůstává povinný.
Buňky používají stejný span formát jako list vč. badge varianty
(`{text, badge: style}` → pilulka, sdílená `SpanBadge.svelte` +
`viewerSpans.js`).

**Řazení** (F2): sloupce se `sortable: true` mají klikatelnou hlavičku
(cyklus asc → desc → výchozí, indikátor ↑/↓); server dostává
`sort=<colId>:<asc|desc>`, controller validuje proti sortable sloupcům
a injektuje přes `TableViewer::setSort()` — viewer řadí helperem
`buildSortedOrderBy()` (signatura `selectRows()` se nemění). **Toggle
list ↔ grid** (F2): ikona vedle searche (desktop, `layouts.length > 1`),
volba se persistuje per-DS v localStorage `shpd_viewer_layout`
(`utils/viewerLayout.js`), priorita persisted > `defaultLayout`.
Kompletní kontrakty a rozhodnutí: `docs/viewer-grid.md`. Piloty:
`JournalViewer` (grid default + footer Σ MD/DAL, 4 sortable sloupce),
`BankTransactionsViewer` (první editovatelný grid, badge Zaúčtování,
bez footeru).

### Mobilní viewer (list/detail)

Na ≤ 768px se viewer přepne z dvoupanelu na list/detail přepínání:
v daný moment je vidět buď seznam, nebo detail (řídí `selectedRowId`).
Bez vybraného záznamu se zobrazí seznam přes celou šířku; po kliknutí
na řádek se seznam skryje (CSS přes třídy `shpd-viewer__body--mobile`
a `shpd-viewer__body--detail`) a detail zabere celou šířku.

Akce se přesouvají do `MobileTopBar` (přes screen surface kanál
v `layout.svelte.js`):

- Seznam: hamburger + titul + akce seznamu jako ikony (Přidat, …).
- Detail: ← zpět (vlevo, místo hamburgeru) + titul záznamu + hlavní
  akce jako ikona + kebab (⋮) se zbytkem akcí (`Popover`).

Viewer publikuje akce reaktivně přes `layoutStore.setScreenSurface(...)` podle
`selectedRowId`; akce nesou navázaný `onClick`, takže MobileTopBar
o vieweru nic neví — jen volá `action.onClick()`. Seznam mapuje
`meta.toolbar`, detail `detailToolbar` (= `result.data.toolbar`); obojí
přes `handleToolbarAction`, stejně jako desktopový `ViewerToolbar`.
Hlavní akce v detailu = heuristika „první v `detailToolbar`". Snooze/
dismiss/recheck a `kind` akce nejsou v `detailToolbar` — žijí v
`detail.actions` uvnitř `ViewerDetail` (na mobilu plná šířka detailu),
takže do top baru nepatří. Při unmountu / přepnutí na desktop viewer
volá `clearScreenSurface()`. Na desktopu zůstává `ViewerToolbar` ve vieweru
beze změny.

### Architektura

```
Viewer.svelte          (frontend — tab bar, search, infinite scroll, detail panel)
  ↕ REST API
ViewerController       (PHP — meta, rows, detail)
  ↕
TableViewer (abstract) (PHP — bázová třída se všemi helpers)
  ↕
PersonsViewer          (PHP — konkrétní viewer pro base.persons)
```

### API endpointy vieweru

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/viewer/{id}/meta` | Metadata: name, table, filters, toolbar, viewGroups, numberSeries |
| `GET /_ui/viewer/{id}/rows` | Záznamy (stránkované, fulltext, viewGroup + number_series filter) |
| `GET /_ui/viewer/{id}/detail/{recordId}` | Detail vybraného záznamu (tabs) |

Parametry pro `rows`:
- `page=0` — číslo stránky (0-based), server vrátí pageSize+1 pro detekci `hasMore`
- `search=text` — fulltext hledání
- `filter[viewGroup]=active` — filtr skupiny stavů (active / archive / trash; bez = vše)
- `filter[number_series]=<id>` — filtr na konkrétní číselnou řadu (per-type doc viewery)
- `filter[<id>]=<hodnota>` — custom filtry vieweru (definice z `meta.filters`,
  UI z `ViewerFilters.svelte` — viz níže). `ViewerController::rows` parsuje
  `filter[...]` generericky a předává do `selectRows()` jako
  `[{id, value}, …]`
- `layout=grid` — grid render řádků (`renderGridRow()`, tvar `cells`);
  bez parametru list. Na page 0 odpověď obsahuje i `footer`, pokud viewer
  implementuje `renderGridFooter()`. Viz `docs/viewer-grid.md` §3
- `sort=<colId>:<asc|desc>` — řazení gridu (jen s `layout=grid`); colId
  musí být `sortable` sloupec, nevalidní hodnota se tiše ignoruje.
  Viz `docs/viewer-grid.md` §7.1

### Tab bar (doc state taby)

Pokud viewer vrací neprázdné `viewGroups` v meta odpovědi, `Viewer.svelte` zobrazí tab bar:

| Tab | Filtr | Popis |
|-----|-------|-------|
| **Aktivní** | `filter[viewGroup]=active` | Koncepty, V opravě, V pořádku |
| **Archív** | `filter[viewGroup]=archive` | Archivované záznamy |
| **Koš** | `filter[viewGroup]=trash` | Smazané záznamy |
| **Vše** | bez filtru | Všechny záznamy |

Přepnutí tabu resetuje stránku a výběr záznamu. Výchozí tab: Aktivní.

### Spodní lišta — číselné řady

`numberSeries` (list, volitelné) — pole `{id, name}` aktivních číselných řad
pro tento viewer (jen řady ve stavu V pořádku, `docState = 40`). Per-type
viewery (`ReceivedInvoicesViewer`, `IssuedInvoicesViewer`) ho exponují přes
`getNumberSeries()` v base třídě `DocsHeadsViewer` — odvozeno z property
`$scopedDocType`. Cross-type viewery vrací prázdné pole.

`Viewer.svelte` z toho vykreslí spodní lištu záložek na dně list-panelu (ortogonální
k horním viewGroup tabům — viewGroup filtruje `docState`, tahle `number_series`),
když je řad víc než jedna. Default je první řada abecedně. Klik na záložku posílá
`filter[number_series]=<id>`; při vytváření dokladu se id přimerg-uje do
`formDefaultData` (`number_series`) vedle `doc_type`, takže nová faktura má
předvyplněnou řadu z aktivní záložky.

### Filtry vieweru (`ViewerFilters.svelte`)

Viewer deklaruje filtry v PHP přes `TableViewer::getFilters()`; meta
endpoint je vrací jako `filters` a `Viewer.svelte` z nich (pokud je aspoň
jeden podporovaného typu) vykreslí filtr bar pod searchem. Hodnoty se
posílají jako `filter[id]=value`; prázdná hodnota filtr ruší. Změna
filtru resetuje stránkování; přepnutí vieweru filtry vrací na výchozí
hodnoty (viz `default` níže).

Podporované typy:

```php
public function getFilters(): array
{
    return [
        ['id' => 'fiscal_year', 'label' => 'Fiskální rok', 'type' => 'select',
         'options' => [['value' => 3, 'label' => '2026']],
         'default' => '3'],                         // výchozí hodnota (string)
        ['id' => 'fiscal_month', 'label' => 'Měsíc', 'type' => 'select',
         'parentFilter' => 'fiscal_year',          // závislý select
         'options' => [['value' => 15, 'label' => '5/2026', 'parent' => 3]]],
        ['id' => 'account', 'label' => 'Účet', 'type' => 'text'],      // debounce 300 ms
        ['id' => 'only_errors', 'label' => 'Jen chyby', 'type' => 'checkbox'], // '1' / nic
    ];
}
```

- **select** — `<select>` s prázdnou option „— vše —". Závislý select:
  `parentFilter` odkazuje na id jiného filtru, options se omezí na ty
  s `option.parent === hodnota rodiče`; bez zvoleného rodiče je select
  disabled a změna rodiče hodnotu potomka zruší.
- **text** — debounced input; sémantiku (prefix/contains) určuje backend
  v `selectRows()`.
- **checkbox** — posílá `'1'`, odškrtnutí filtr odstraní.

Jiné typy (historický `enum` v `AlertsViewer`) se přeskakují — bar se
nezobrazí, dokud viewer nedeklaruje aspoň jeden podporovaný typ. Labely
jdou z backendu (lokalizace přes `$this->language` / cfgItems), frontend
překládá jen prázdnou option (`viewer.filters.all`).

**Výchozí hodnota — `default`.** Definice může nést `'default' =>
<hodnota>` (string pro `select` / `text`, `'1'` pro `checkbox`). Backend
nic dalšího nedělá, hodnota jde klientovi v `meta.filters`;
`utils/viewerFilters.js` (`initialFilterValues()`) z ní při otevření
vieweru sestaví `activeFilters` a první fetch jde už s ní. Precedence:
**`pendingFilters` z akce `open_viewer` > `default`** (`{...defaults,
...pending}`) — volající, který chce jinou hodnotu, ji posílá explicitně;
pending s prázdnou hodnotou default ruší. Default závislého selectu platí
jen když má rodič hodnotu. Uživatel výchozí hodnotu mění a ruší jako
každou jinou („— vše —" klíč maže); přepnutí vieweru ji obnoví. Jediný
helper pro „aktuální fiskální rok" je `Core\Viewer\FiscalYearFilter`
(rok obsahující dnešek, jinak nejnovější) — používá ho deník
i saldokonto.

První uživatel: `JournalViewer` (`economy.accounting.journal`) — fiskální
rok (výchozí aktuální rok přes `FiscalYearFilter`) / měsíc (závislý
select), prefix účtu, partner, jen chyby.

### Formát řádku (`renderRow()`)

```json
{
    "id": 42,
    "stateStyle": "done",
    "t1": "Název záznamu",
    "i1": "#kód",
    "t2": [{"text": "IČO: 12345"}, {"text": "V pořádku", "class": "success"}],
    "t3": "email@example.com"
}
```

Pole `t1`, `i1`, `t2`, `i2`, `t3` přijímají string, objekt `{text, class?}` nebo pole objektů. `stateStyle` se mapuje na CSS třídu `docState_{stateStyle}` na řádku. Dostupné span třídy: `amount`, `muted`, `bold`, `primary`, `success`, `warning`, `danger`.

Pole `icon` (string, optional) — identifikátor ikony z `iconMap`
(`user`, `company`, `invoice`, …). Když ho `renderRow()` nevrátí,
backend doplní default z `module.jsonc` (`viewers[].icon`). Frontend
přes `resolveIcon()` přeloží na FA icon definition, fallback `iconTable`.

**Pořadové číslo** v každém řádku je čistě frontend záležitost —
`Viewer.svelte` ho počítá z pozice v poli `rows` (1, 2, 3, … souvisle
přes celý načtený seznam). Při infinite scrollu pokračuje (50 → 51 → …),
při změně tabu / filtru / hledání reset na 1.

### Formát detail panelu (`renderDetail()`)

Vrací volitelnou hlavičku (`title`, `subtitle`, `badges`) a taby s obsahem:

```json
{
    "title": "Faktura 2026/0412 — dodávka serverů",
    "subtitle": "Jan Novák <jan@example.com> · Fakturace (FAKT) · 9. 6. 2026 14:32",
    "badges": [{"label": "Nová", "style": "concept"}],
    "icon": "mail",
    "tabs": [
        {"id": "overview", "label": "Přehled", "content": {
            "type": "properties",
            "groups": [{"title": "Identifikace", "items": [{"label": "IČO", "value": "12345"}]}]
        }},
        {"id": "journal", "label": "Zaúčtování", "content": {
            "type": "table",
            "columns": [{"id": "name", "label": "Název"}, {"id": "amount", "label": "Částka", "align": "right"}],
            "rows": [
                {"name": "Jan Novák", "amount": "6 000,00"},
                {"name": "Σ", "amount": "6 000,00", "_class": "total"}
            ]
        }}
    ]
}
```

Hlavička je volitelná (bez `title` se nerenderuje) a zůstává viditelná
při přepínání tabů. Volitelný `icon` (klíč z `icons.js`, typicky shodný
s `viewers[].icon` v `module.jsonc` daného modulu) se vykreslí ve 40×40
boxu vlevo od title — stejný vizuál jako ikona ve formulářovém modalu
(`shpd-modal__header-icon`). Badge `style` přijímá obecné varianty (`neutral`,
`primary`, `accent`, `success`, `warning`, `danger`) i doc-state styly
(`concept`, `edit`, `confirmed`, `done`, `archive`, `trash`, `cancelled`,
`error`) — viz `docs/design-system.md`. Hlavičku používají všechny hlavní
detaily: došlá pošta, Osoby, Položky, Úkoly i doklady (`DocsHeadsViewer`).

Typy obsahu:

| Typ | Popis |
|---|---|
| `properties` | label/value grid ve skupinách |
| `table` | tabulka (`columns` + `rows`); `columns[].align: "right"` = číselný sloupec (zarovnání doprava + `tabular-nums`, header i buňky); `rows[]._class` = klasifikace řádku — `error` (červené podbarvení, chybové řádky deníku) nebo `total` (tučný součtový řádek s horní linkou); `_class` není sloupec, do buněk se nerenderuje |
| `html` | surové HTML — **bez sanitizace**, backend musí hodnoty escapovat; **pouze pro trusted, backend-generovaný obsah** — pro cizí HTML použít `untrusted-html`; scoped styly komponenty se na `{@html}` nevztahují, vzhled jde přes globální CSS proměnné (vzor: stavový blok tabu Zaúčtování) |
| `untrusted-html` | HTML z nedůvěryhodného zdroje (tělo e-mailu); renderuje se v sandboxovaném `<iframe srcdoc>` bez `allow-scripts` (`SandboxedHtml.svelte`): izolace skriptů i CSS oběma směry, odkazy do nového tabu (`<base target="_blank">`, whitelist protokolů — `javascript:` apod. se zahazuje), odstranění `meta refresh`, auto-height dle obsahu. **Nikdy nerozšiřovat sandbox o `allow-scripts`** — s `allow-same-origin` by skript z e-mailu četl Bearer token z localStorage |
| `heading` | mezititulek sekce (`text`) |
| `attachment-grid` | plochý grid příloh (`attachments`: `id`, `name`, `mime_type`, `file_size` v bajtech); přepínání miniatury/velké náhledy přes sdílený store `attachmentView.svelte.js` |
| `composite` | seznam `blocks[]` — každý blok je libovolný z ostatních typů, renderuje se rekurzivně týmž snippetem |
| `proposal`, `attachments`, `document` | doménové typy: dokumentový návrh zprávy (tab „Návrh" — jedna karta s typem, pásmem/verdiktem a akcemi Použít/Zamítnout/Zobrazit detail), přílohy seskupené po zprávách, textový detail dokladu (`DocumentDetail`; hlavička dokladu je nově nad taby, klíč `header` se v contentu už neposílá). V `document` contentu je jméno partnera klikatelné (klíč `person_id` přidává backend jen na partnerské straně podle `trade_dir` — vlastní firma ho nedostane) a popis řádku s vazbou na Položku též (`item_id` se posílá jen když položka v DB existuje, včetně archivu a koše; tehdy má řádek i badge `item_state: {label, style}`). Klik volá `onAction` s generickou akcí `kind: open_form` — otevře `FormDialog` osoby (`base_persons_persons`) / položky (`economy_items`), po uložení se detail i seznam refreshnou |

### Akce detailu (`detail.actions`)

`renderDetail()` může vedle `tabs` vrátit i `actions` — řádek per-record
tlačítek nad taby (vzor `AlertsViewer::buildDetailActions`):

```json
{"id": "reaccount", "label": "Přeúčtovat", "kind": "button", "variant": "secondary",
 "confirm": "Opravdu?"}
```

- `kind: "button"` (default) — obsluhu řeší `Viewer.svelte::handleDetailAction`
  podle `action.id` (sdílený slovník id: `snooze`, `dismiss`, `unsnooze`,
  `recheck`, `reaccount`); po úspěchu `refreshAfterAction()` (detail
  i seznam), chyba → `alert(translateError(...))`.
- `kind: "dropdown"` — `items: [{label, value}]`, výběr položky volá akci
  s hodnotou (bez confirm).
- `kind: "open_form"` — otevře `FormDialog` dle `target.{table, mode, id, preset}`.
  Stejný handler využívá i `DocumentDetail` pro klikatelného partnera a položky
  řádků — akce nejde z `detail.actions`, ale komponenta ji skládá sama a volá
  `onAction('open_record', …)`; id `open_record` není ve sdíleném slovníku
  vestavěných akcí, takže propadá na generickou obsluhu podle `kind`.
- `kind: "open_viewer"` — cross-viewer navigace: `viewerId` + `recordId` →
  `navigationStore.navigateToViewer()` (cílový viewer záznam předvybere
  přes `pendingRecordId`). Volitelně `viewGroup` (chip, na kterém se má
  cílový viewer otevřít — `pendingViewGroup`) a `filters` (`{filterId:
  value}` custom filtrů cílového vieweru — `pendingFilters`; viewer je
  slije s výchozími hodnotami filtrů (`default`, pending vítězí) do
  `activeFilters`, takže jsou v panelu filtrů viditelné a uživatel je
  může uvolnit, a použije je pro první fetch). Cílový viewer s výchozím
  filtrem (období) může `recordId` z jiného období schovat ze seznamu —
  detail se otevře, řádek ne; volající proto posílá i období
  (`fiscal_year`). Všechny tři hinty jsou jednorázové a manuální navigace
  je maže. Používá deník pro odkaz na zdrojový doklad a saldokonto
  (viewer případů → „Pohyby případu" otevře pohyby s chipem saldokonta,
  obdobím případu a filtry partner / VS / SS).

### Registrace vieweru

V `module.jsonc`:

```jsonc
"viewers": [
    {
        "id": "base.persons",
        "name:cs": "Osoby",
        "icon": "user",
        "table": "base_persons_persons",
        "class": "Shipard\\Module\\Base\\Persons\\PersonsViewer"
    }
]
```

PHP třída vieweru žije v `modules/{skupina}/{modul}/src/` a dědí `TableViewer`. Pro podporu stavů dokumentů nastaví `$docStatesCfgItem`:

```php
class PersonsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    public function selectRows(?string $search, array $filters, int $pageNumber): array { /* ... */ }
    public function renderRow(array $rowData): array { /* ... */ }
    public function renderDetail(int $recordId): array { /* ... */ }
}
```

Viz také `docs/doc-states.md` — sekce Viewer systém.

### Existující viewery

| Viewer ID | Modul | Třída | Zvláštnosti |
|---|---|---|---|
| `base.persons` | `base.persons` | `PersonsViewer` | Archivační docStates, fulltext search přes full_name/company_id/email/person_id |
| `core.mail.incoming` | `core.mail` | `IncomingMessagesViewer` | Vlastní docStates (`core.mail.docStatesIncoming`), JOIN na schránku, relativní formátování received_at, 4 detail taby (Obsah / Přílohy / Analýzy / Originál) |
| `tasks.core` | `tasks.core` | `TasksViewer` | Vlastní docStates (`tasks.core.docStatesTasks`), JOIN na `core_system_users` kvůli zobrazení autora, indikace po termínu v t2 |
| `economy.accounting.journal` | `economy.accounting` | `JournalViewer` | Read-only (prázdný toolbar, bez docStates, bez formu), custom filtry přes `getFilters()` vč. závislého selectu a výchozího aktuálního roku (`default`), detail akce `open_viewer` na zdrojový doklad |

Nové viewery přidávají moduly přes `module.jsonc.viewers[]` — jakmile je viewer registrován, automaticky se objeví v navigaci (ikona z `iconMap`, fallback `iconTable`).

---

## 7.5 Dashboard

Home obrazovka aplikace — výchozí pohled po loginu (`type: 'dashboard'` jako root-level
leaf v sidebar navigaci). Prioritizovaný feed akčních karet (došlá pošta +
alerty) s generovaným AI shrnutím dne nahoře a plovoucím AI chat launcherem.

Komponenty: `Dashboard.svelte` (top-level fetch + layout), `AiSummaryCard.svelte`,
`Feed.svelte` / `FeedCard.svelte` / `FeedFilter.svelte`, `ChatLauncher.svelte`.
API klient: `api/dashboard.js → fetchDashboard()`, `streamDashboardSummary()`.

Akce karet `open_form` (a toast „Otevřít“) mountují `<FormDialog table recordId>`
rovnou nad dashboardem a po close refetchují **jen pokud došlo k save**
(sledováno přes `wasSaved` flag z `onSaved` callbacku). Detaily v
[`dashboard.md`](dashboard.md).

---

## 8. UI API endpointy

### Implementované

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/navigation` | Navigační strom ze serveru (moduly → skupiny → tabulky/viewery) — vč. Dashboard leaf na začátku |
| `GET /_ui/settings/navigation` | Navigační strom režimu Nastavení (sekce + položky podle `settingsItems[]` napříč moduly) |
| `GET/POST /_ui/settings/page/{pageId}` | Settings page — definice + hodnoty / uložení (viz [`app-settings.md`](app-settings.md)) |
| `GET /_app/info` | Veřejné info aplikace — název, zkrácený název, ikona, logo (titulek, favicon, sidebar, login) |
| `GET /_app/manifest` | Veřejný web app manifest pro PWA instalaci — per-DS jméno, `start_url`/`scope` dle režimu (viz sekce 13) |
| `GET/POST/DELETE /_app/branding/{slot}` | Branding obrázky — GET veřejný s immutable cache, zápis s auth |
| `GET /_ui/dashboard` | Feed akčních karet pro home obrazovku (viz [`dashboard.md`](dashboard.md)) |
| `GET /_ui/viewer/{id}/meta` | Metadata vieweru (name, table, filters, toolbar, viewGroups, numberSeries) |
| `GET /_ui/viewer/{id}/rows` | Záznamy vieweru (page, search, filter) |
| `GET /_ui/viewer/{id}/detail/{recordId}` | Detail panel záznamu (tabs) |

### Budoucí

| Endpoint | Popis |
|----------|-------|
| `GET /_ui/browser/{table}` | UI-specifická metadata prohlížeče (viditelné sloupce, výchozí řazení, akce) |
| `GET /_ui/form/{table}` | Rozšířená definice formuláře (custom layout, widgety, závislosti mezi poli) |

Zatím prohlížeč i formuláře fungují čistě z `_meta/tables/{table}` — UI endpointy přidají vrstvu přizpůsobení.

---

## 9. Konvence

### Svelte

- Svelte 5 syntax (runes: `$state`, `$derived`, `$effect`)
- Jedna komponenta na soubor
- Props přes `$props()`
- Události přes callback props, ne custom events
- `$effect` nesmí synchronně číst `$state` proměnné, které nemají být sledovány jako závislosti — funkce pro fetch přijímají explicitní parametry

### CSS

- CSS custom properties pro theming (`--shpd-color-primary`, `--shpd-space-md`)
- BEM-like naming: `.shpd-button`, `.shpd-button--primary`, `.shpd-button__icon`
- Scoped styles v Svelte komponentách
- `:global()` pro třídy aplikované dynamicky (např. `docState_concept` na řádcích vieweru)

Detailní dokumentace barevného systému (paleta, doc-state konvence, badge varianty,
focus stavy) je v [`design-system.md`](design-system.md).

### API komunikace

- Vždy přes `api/client.js` (nikdy přímý fetch, kromě auth.js)
- `api/config.js` řeší DS ID prefix automaticky
- Automatický 401 → refresh → retry
- Chyby přes return value, ne exceptions
- **Envelope konvence:** všechny odpovědi mají tvar `{ success, data, meta? }` nebo `{ success, error }`. Data jsou vždy v `res.data`, nikdy přímo v `res`. Např. `res.data.formDefinition`, ne `res.formDefinition`.

### Pojmenování

- Soubory komponent: PascalCase (`LoginScreen.svelte`)
- Soubory utilit/stores: camelCase (`auth.svelte.js`)
- CSS třídy: `shpd-{component}` prefix

### Dropdown / popover komponenty

Dropdown menu (např. user menu v patce sidebaru) typicky implementují dva
kódové vzorce: **toggle při kliku na trigger** a **close-on-outside přes
document click listener registrovaný v `$effect`**. Tyhle dva vzorce spolu
mají jednu nepříjemnou interakci, na které se dá lehce spálit.

#### Past: zavírání menu z handleru položky uvnitř menu

Nechci zavírat menu pomocí `closeMenu()` v handleru položky, která spouští
asynchronní akci (logout, navigace, fetch). Špatně:

```js
function handleLogoutFromMenu() {
  closeUserMenu();        // ❌ synchronně nastaví userMenuOpen = false
  handleLogout();         // ...sem se může nedostat, viz níže
}
```

Sekvence událostí:

1. Click na položku v menu spustí handler.
2. `closeUserMenu()` synchronně nastaví `userMenuOpen = false` a Svelte
   reaktivně odmontuje `{#if userMenuOpen}` blok — položka (target eventu)
   se stává detached elementem.
3. Click event pokračuje bublat k document listeneru.
4. Listener testírá `menuRoot.contains(e.target)` — detached element není
   v DOMu, `contains()` vrátí **false**, listener spadne do větve
   „klik mimo menu“.
5. `$effect` cleanup během stejného microtasku odregistruje listener
   a může zasáhnout do běhu následující asynchronní akce.

Výsledek: menu zmizí, ale akce problému docálu z prvního kliku se ztratí.
Druhý klik už funguje, protože běží s novým `$effect` cyklem.

#### Řešení

Pokud akce stejně změní kontext tak, že menu přestane existovat (logout,
navigace pryč), `closeMenu()` se vůbec nevolá — celý sidebar / komponenta
zmizí sám:

```js
function handleLogoutFromMenu() {
  // Záměrně nezavíráme menu — sidebar zmizí sám,
  // jakmile authStore.clearAuth() přepne na LoginScreen.
  handleLogout();
}
```

Pokud akce kontext nezmění (běžný případ), zavírám menu **až po dokončení
akce**, ne předem:

```js
async function handleAction() {
  await doSomething();
  closeMenu();
}
```

Nebo zavři menu **a počkej jeden tick** než spustíš další akci, aby se
stihl render flush a click bubbling dokončily:

```js
function handleAction() {
  closeMenu();
  setTimeout(doSomething, 0);  // nebo queueMicrotask
}
```

#### Robustní logout / fetch v handleru

Asynchronní akce volané z handlerů, které změní auth state, by měly přežít
i pád API volání. Příklad: `logout` musí úspět z perspektivy uživatele i když
backend fetch selhal (token už neplatný, síť nedostupná atd.):

```js
async function handleLogout() {
  try {
    await logout();
  } catch (err) {
    console.warn('Logout API call failed (continuing):', err);
  }
  authStore.clearAuth();
  onLogout?.();
}
```

### `data-testid` (video-runner, smoke E2E)

Stabilní selektory pro automatizaci — video-runner (`tools/video-runner/`,
zkratka `@name` → `[data-testid="name"]`) a později smoke E2E testy.
Rozhodnutí D11–D14 z #48:

- **Odvozování před vymýšlením.** Kde existuje serverem definované,
  jazykově neutrální id, testid se z něj odvozuje mechanicky — navigace
  má `nav-{node.id}` (např. `nav-viewer:core.mail.incoming`, `nav-dashboard`)
  a `navgroup-{group.id}` na headerech skupin (`NavTree.svelte`). Ručně
  pojmenované testidy jen tam, kde žádné serverové id není — a jen na
  zlaté cestě prvního videa (login, shell, dashboard + feed, viewer,
  paleta), ne plošně.
- **Jmenná konvence `oblast-konkretum`** (`login-name`, `viewer-rows`,
  `palette-input`). Testid identifikuje prvek, ne cestu k němu — žádné
  hierarchie v názvu. Singletony mají unikátní testid; opakované prvky
  nesou typový marker (`viewer-row`, `feed-card`) a konzument si bere
  první / n-tý.
- **Testidy zůstávají v produkčním buildu.** Nestripovat: videa se točí
  proti skutečným buildům a tytéž testidy poslouží smoke E2E.
- **Generické komponenty propouštějí testid propem.** `Button` a `Modal`
  mají volitelný prop `testid` → `data-testid` na kořenovém elementu
  (tlačítko / karta modalu). Zlatá cesta S1 (#48): akční tlačítka feed
  karty `card-action-{action.id}` (odvozeno z id akce, D11), review modal
  `review-modal` + `review-apply-draft` / `review-apply-final` /
  `review-reject` / `review-skip` / `review-close` / `review-total`
  (celková částka), toast `toast` / `toast-open`, FormDialog
  `form-dialog`.

---

## 10. Ikony

Aplikace používá **Font Awesome** (SVG/JS varianta) pro ikony napříč celým UI.

### Balíčky

- `@fortawesome/fontawesome-svg-core` — základní knihovna
- `@fortawesome/free-solid-svg-icons` — sada solid ikon

### Architektura

- **`src/icons.js`** — centrální registr. Všechny ikony se importují a re-exportují z jednoho místa. Pojmenování podle *významu* (ne podle vzhledu): `iconAdd`, `iconEdit`, `iconUser`. Obsahuje `iconMap` pro překlad řetězců z API a funkci `resolveIcon(name, fallback)`.
- **`components/ui/Icon.svelte`** — univerzální komponenta. Přijímá FA icon definition a vykreslí inline SVG. Podporuje velikosti (`xs`/`sm`/`md`/`lg`/`xl`) a animaci `spin`.
- **`components/ui/Button.svelte`** — rozšířený o prop `icon`, `iconOnly` a variantu `ghost`.

### Ikony v navigaci (server-driven)

Navigační položky mohou mít volitelnou ikonu definovanou na serveru v `module.jsonc`. Frontend používá `resolveIcon(item.icon)` s fallbackem na `iconTable`.

### Přidání nové ikony

1. V `icons.js`: import z `@fortawesome/free-solid-svg-icons`, pojmenovaný export (`iconNěco`)
2. Pokud ji server posílá jako string: přidat záznam do `iconMap`
3. V komponentě: importovat z `icons.js` a předat do `<Icon>` nebo `<Button>`

---

## 11. Theme management

Aplikace podporuje tři vzhledy: **Shipard** (`light`), **Tmavý** (`dark`)
a **Vlastní** (`custom` — uživatelská barva sidebaru + světlá/tmavá báze
těla). Vizuální paleta, odvozované tokeny a designové principy jsou
v [`design-system.md`](design-system.md) (sekce *Vzhledy (themes)*).
Tato sekce popisuje **implementaci** — store, bootstrap, panel, přepínač.

Od **Fáze 4** je vzhled dvouúrovňový: **DS default** (`app.theme`, scope
ds) + **user follow/override** (`account.theme`, scope user). Efektivní
vzhled = `follow ? (DS default ?? Shipard) : user override`. Vzhled je
nastavení (ne rychlý přepínač) — **dropdown vzhledu v patce sidebaru
zanikl**, vše se řeší v Nastavení účtu → Základní a Nastavení aplikace →
Aplikace.

Dřívější režim `'auto'` (sledování `prefers-color-scheme`) zanikl —
`loadInitialState()` migruje uloženou hodnotu `'auto'` na `'follow'`
s okamžitým write-backem; bootstrap zachází s neznámými hodnotami jako
s `'follow'` (prázdná DS cache → Shipard light).

### Soubory

| Soubor | Co dělá |
|---|---|
| `frontend/src/styles/variables.css` | Light tokeny v `:root`, dark tokeny v `[data-theme="dark"]`, tokeny `--shpd-color-sidebar-active-bg(-hover)` |
| `frontend/index.html` | Inline `<script>` bootstrap — aplikuje téma před prvním renderem (anti-flash); pro `custom` čte override token cache, pro `follow` DS default cache |
| `frontend/src/stores/theme.svelte.js` | Store s `mode`, `custom`, `follow`, `dsDefault`, `setMode/setCustom/setFollow/setDsDefault/applyFromServer`; efektivní vzhled; persistence (override + DS cache) |
| `frontend/src/stores/appInfo.svelte.js` | Nese DS default (`theme`); po loadu `themeStore.setDsDefault()` (push appInfo → theme) |
| `frontend/src/utils/themeColor.js` | `hexToOklch()`, `hexToOklab()`, `mixOklab()`, `oklabToCss()`, `deriveSidebarTokens()`, `SIDEBAR_TOKEN_NAMES`, `BASE_BG` — OKLab/OKLCH odvozování, bez závislostí |
| `frontend/src/components/layout/themePresets.js` | `THEME_PRESETS` (12 plných barev) + `THEME_GRADIENT_PRESETS` (12 přechodů) |
| `frontend/src/components/settings/ThemeModeSegments.svelte` | Sdílený segmented control Shipard/Tmavý/Vlastní (mode + onSelect) |
| `frontend/src/components/settings/ThemeSwatches.svelte` | Sdílený editor (báze/presety/opacity/picker), controlled — `custom` + callbacky |
| `frontend/src/components/settings/ThemeField.svelte` | User-scope widget: follow přepínač + segmenty + „Upravit barvu" (otevírá ThemePanel) |
| `frontend/src/components/settings/DsThemeField.svelte` | DS-scope widget: segmenty + inline ThemeSwatches, controlled (ukládá přes Uložit) |
| `frontend/src/components/layout/ThemePanel.svelte` | Panel custom tématu (desktop fixed vedle sidebaru, mobil Modal), obsah = `ThemeSwatches` |
| `frontend/src/components/layout/Sidebar.svelte` | Dropdown vzhledu **odstraněn**; jen jazyk + Nastavení účtu/aplikace |
| `frontend/src/components/layout/AppShell.svelte` | Vlastní stav `themePanelOpen`, renderuje `<ThemePanel>`; `onOpenThemePanel` jde do ContentArea (ne Sidebaru) |

### Režimy

- `'light'` — Shipard default; žádný `data-theme` atribut, žádné inline tokeny
- `'dark'` — `data-theme="dark"` na `<html>`, žádné inline tokeny
- `'custom'` — `data-theme` podle `custom.base` (`'dark'` → atribut,
  `'light'` → odebrat); sidebar tokeny z `deriveSidebarTokens(sidebar,
  base, opacity)` se nastaví jako inline custom properties na `<html>`
  (u gradientu včetně `--shpd-sidebar-bg-image`)

Mode platí pro override i pro DS default config. Stav `follow` (default
`true`) rozhoduje, zda se aplikuje DS default, nebo user override. Default
pro nové uživatele: `follow` (efektivně DS default, nebo Shipard když DS
default chybí).

### localStorage — per-DS klíče

Klíče se v dev módu (DS ID v URL path) prefixují přes
`storageKey(name)` → `name:{dsId}`, aby se volby pro různé DS na
stejném originu nemíchaly; v produkci (subdoména per DS) izoluje origin
automaticky.

| Klíč (base) | Obsah |
|---|---|
| `shpd_theme` | `'follow'` (sleduj DS default) / `'light'` / `'dark'` / `'custom'` (override mode) |
| `shpd_theme_custom` | JSON override custom konfigurace (viz níže) |
| `shpd_theme_tokens` | JSON cache override tokenů pro anti-flash bootstrap |
| `shpd_ds_theme` | JSON DS default `{mode, custom}` — follow anti-flash cache (Fáze 4) |
| `shpd_ds_theme_tokens` | JSON cache DS default tokenů (jen když DS default je `custom`) |

Formát `shpd_theme_custom` — navržený jako sdílený pro budoucí úrovně
persistence (server per-user = Fáze 3, DS-wide default = Fáze 4):

```json
{
  "version": 1,
  "base": "light",
  "opacity": 100,
  "sidebar": { "type": "solid", "color": "#6D1F2C" }
}
```

```json
{
  "version": 1,
  "base": "dark",
  "opacity": 85,
  "sidebar": { "type": "gradient", "stops": ["#00345C", "#0E4F5C"] }
}
```

- `opacity` (0–100, default 100) je **top-level** záměrně — přepnutí
  solid ↔ gradient posílá kompletní `sidebar` objekt a opacity nesmí
  být přepsána. Configy z Fáze 1 bez `opacity` normalizuje
  `loadInitialCustom()` na 100.
- `sidebar.type: 'gradient'` má `stops` (pole dvou `#RRGGBB`), nemá
  `color`. Směr je fixně vertikální (180deg); `angle` se do formátu
  doplní až s vlastními gradienty.
- `version` zůstává 1 — rozšíření je čistě aditivní.

Cache `shpd_theme_tokens` zapisuje store při override (mode `custom`)
a maže při built-in. DS cache `shpd_ds_theme(_tokens)` zapisuje store,
kdykoli zná DS default (vč. override-uživatelů — pro pozdější follow).
Bootstrap je jen čte a aplikuje.

**Čtyři synchronizovaná místa** pro localStorage klíče/formáty a DS
detekci: `theme.svelte.js`, bootstrap v `index.html` (duplikuje DS regex,
protože běží před načtením modulů, + čte DS cache klíče), `api/config.js`
(`DS_ID_PATTERN`) a DS default cache klíče `shpd_ds_theme*` sdílené mezi
store a bootstrapem. Při změně kteréhokoli aktualizovat komentáře u všech.

### Anti-flash bootstrap

Před prvním renderem běží krátký inline `<script>` v `index.html`:
detekuje DS ID z URL (stejný regex jako `api/config.js`), přečte mode
z `shpd_theme` (default `'follow'`):
- `'follow'` → přečte DS cache `shpd_ds_theme`; podle `ds.mode` nastaví
  `data-theme` (dark báze) a pro `custom` aplikuje `shpd_ds_theme_tokens`.
  Prázdná DS cache → Shipard light.
- `'dark'` → `data-theme`.
- `'custom'` → `data-theme` podle `cfg.base` + override tokeny ze
  `shpd_theme_tokens`.

Vždy přes `setProperty()` z předpočítaných tokenů — **žádná OKLCH
matematika v inline scriptu**.

Bootstrap je záměrně malý a defenzivní (try/catch okolo localStorage
kvůli private mode / disabled storage), aby selhal tiše s fallbackem
na light, ne aby blokoval render.

### `themeStore` API

```js
import { themeStore } from '../../stores/theme.svelte.js';

themeStore.mode;      // 'light' | 'dark' | 'custom' — override mode (platí když !follow)
themeStore.custom;    // {version, base, opacity, sidebar: {type, color|stops}} — override config
themeStore.follow;    // bool — sleduji DS default?
themeStore.dsDefault; // {mode, custom} | null — DS default z appInfo
themeStore.setMode('dark');                 // override (follow=false) + persistence + apply + push
themeStore.setCustom({ base: 'dark' });     // override merge; implikuje mode 'custom'; push (debounce)
themeStore.setFollow(true);                 // přepne follow/override; první override předvyplní z DS; push
themeStore.setDsDefault({mode, custom});    // nastaví DS default (z appInfo); follow → re-apply; BEZ pushe
themeStore.applyFromServer(accountTheme);   // aplikace account.theme ze serveru (follow tvary) BEZ pushe
```

`pushToServer()` posílá follow tvar: `{follow:true}` nebo
`{follow:false, mode, custom}`. `applyFromServer()` rozpozná
`{follow:true}` / `{follow:false, ...}` / legacy `{mode, custom}`
(= override). `setMode`/`setCustom` implikují override (`follow=false`)
— uživatel aktivně volí.

`applyTheme()` (privátní) při `custom` **nejdřív vyčistí inline
tokeny a pak nastaví nové** (clear-then-set) — derivace nemusí vrátit
všechny tokeny (`--shpd-sidebar-bg-image` má jen gradient) a přepnutí
gradient → solid by jinak nechalo viset starý inline gradient. Poté
zapíše token cache. Při built-in tématech inline tokeny vyčistí
(`removeProperty` přes `SIDEBAR_TOKEN_NAMES`) a cache smaže.

Opacity mixuje barvu/stopy směrem k pozadí báze v OKLab — mix targety
`BASE_BG` v `themeColor.js` **zrcadlí `--shpd-color-bg`** ve
`variables.css` (`:root` a `[data-theme="dark"]`); sync komentáře na
obou místech. Odvozování všech tokenů běží z efektivní barvy (solid =
barva po mixu, gradient = OKLab střed stopů po mixu) — detaily
v [`design-system.md`](design-system.md) sekce 9.

Bootstrap v `index.html` se Fází 2 **nemění** — token cache nese
i `--shpd-sidebar-bg-image` a aplikační loop je generický.

### Per-user persistence (server) — Fáze 3

Vzhled (a jazyk) jsou **per-uživatelské nastavení na serveru**; localStorage
zůstává anti-flash cache. Zdroj pravdy je server, lokální cache jen drží
poslední známý stav pro první render.

- **Klíče v user store** (`core_system_user_settings`, scope `user`):
  `account.theme` (JSON — follow tvar, viz níže), `account.language` (string).
- **Načtení po loginu**: `stores/accountPrefs.svelte.js` `load()` —
  `GET /_ui/settings/page/accountBasic`, z `values` aplikuje
  `themeStore.applyFromServer()` a (liší-li se od cache) `language.setMode()`.
  Volá se z `App.svelte` onSuccess (fresh login) a z `main.js` při
  autentizovaném startu (reload s platným tokenem). Guard `languageApplied`
  brání reload-smyčce.
- **Zápis na server**: `themeStore.setMode/setCustom/setFollow` a
  `language.setMode` pushují přes `api/account.js` (`pushAccountPrefs` →
  `POST /_ui/settings/page/accountBasic`). `setCustom` má debounce ~300 ms
  (color picker `oninput`); `language.setMode` await-uje POST před
  `location.reload()`. Selhání je tiché — lokál platí pro session.
- **Žádný kruhový import**: server push žije v `api/account.js`, ne
  v `accountPrefs` storu (ten importuje `theme`/`language`). `theme`/
  `language` stores importují jen `api/account.js`. **DS default jde
  opačným směrem** — `appInfo` po loadu volá `themeStore.setDsDefault()`,
  takže theme store neimportuje `appInfo`.
- **Cross-device flash**: na novém zařízení s čistou cache je první render
  default Shipard; po `accountPrefs.load()` + `appInfo.load()` se aplikuje
  efektivní vzhled a naplní cache (další reloady bez flashe) — akceptováno.

#### DS default + follow (Fáze 4)

- **DS default** přichází přes `appInfo` (`/_app/info` → `theme`) →
  `themeStore.setDsDefault({mode, custom} | null)`. Store ho drží v
  `dsDefault` a cachuje do `shpd_ds_theme(_tokens)`. Změna DS defaultu
  (uložená správcem) se u follow-uživatele projeví po jeho příštím loadu;
  `SettingsPage` po Uložit volá `appInfoStore.load()`, takže follow-admin
  vidí změnu hned.
- **`account.theme` follow tvar**: `{follow:true}` (sleduj DS default) nebo
  `{follow:false, mode, custom}` (override). Legacy `{mode, custom}` bez
  follow = override. Absence hodnoty = follow (nový uživatel).
- **Efektivní vzhled**: `effectiveConfig()` = `follow ? (dsDefault ??
  Shipard) : {mode, custom}`. `applyEffective()` je jediné místo, které
  píše `shpd_theme` (`'follow'` vs override mode) a synchronizuje cache.
- **Přepínač „Vlastní vzhled"** (`ThemeField`, `setFollow`): vypnuto =
  follow (výběr skrytý + poznámka + mini náhled DS defaultu), zapnuto =
  override. První zapnutí předvyplní override zděděnou DS hodnotou, pokud
  je override ještě „pristine" (Shipard default) — `overrideIsPristine()`.

Volba je dostupná na dvou místech, obě čtou/píší jednu pravdu ve storu:
`ThemePanel` a stránka **Základní** v account módu (`ThemeField`). DS
default edituje `DsThemeField` (Nastavení aplikace, ukládá přes Uložit).
Dropdown vzhledu v patce sidebaru **zanikl**.

### ThemePanel

`ThemePanel.svelte` — props `open`, `onClose`, `collapsed`. Obsah je
sdílená komponenta **`ThemeSwatches`** (Nastavení): přepínač báze těla
(světlá/tmavá), **stránkovaný** grid preset swatchů (stránka 1 = plné
barvy, stránka 2 = gradienty; šipky po stranách bez wrap-aroundu,
klikatelné tečky pod gridem), opacity slider (0–100, step 5) a nativní
`<input type="color">` s `oninput` (live preview při tažení). Custom
color input je **solid-only** — při aktivním gradientu zobrazuje první
stop a interakce přepne na solid. Panel předává callbacky, které volají
`themeStore.setCustom()` — aplikace okamžitá, žádné tlačítko Uložit.
`ThemeSwatches` je **controlled** (dostane `custom` + callbacky, neimportuje
themeStore) — stejnou komponentu používá i `DsThemeField` (DS default).

- **Desktop**: fixed panel vedle sidebaru (`left` podle
  `collapsed` stavu); zavírání ✕ / Esc / klik mimo (document listener
  v `$effect` — stejný vzor jako user menu).
- **Mobil** (`layoutStore.isMobile`): strukturní přepnutí — obsah se
  renderuje uvnitř `<Modal>` (fullscreen automaticky).

Panel renderuje **AppShell**, ne Sidebar — mobilní drawer má
`transform` (containing block pro `position: fixed`) a `.shpd-sidebar`
má `overflow: hidden`, panel/Modal uvnitř by se ořízl. Panel otevírá
**`ThemeField`** (Nastavení účtu → Základní) přes callback prop
`onOpenThemePanel` probublaný z AppShellu skrz `ContentArea` →
`SettingsPage`. Sidebar `collapsed` stav zrcadlí do AppShellu přes
`$bindable` prop. Otevření panelu deferujeme za aktuální klik
(`setTimeout 0`) — viz past s click bubbling v sekci *Konvence →
Dropdown / popover komponenty*.

### Implementační poznámka: `state_referenced_locally`

Při mountu modulu se v `theme.svelte.js` volá IIFE `applyInitial()`,
které čte lokální `const` (`initial`, `initialCustom`, `initialDsDefault`),
ne `$state` proměnné. Kdybychom četli přímo `mode`/`follow` (`$state`),
Svelte 5 by hlásilo varování `state_referenced_locally` — čtení `$state`
v top-level modulu zachycuje jen počáteční hodnotu, ne reaktivně. Tady je
to schválně (jednorázová aplikace při mountu); reaktivní updaty následují
přes `setMode/setCustom/setFollow/setDsDefault`. Stejný vzor v
`ThemeSwatches` — `presetPage` init čte prop `custom` přes `untrack()`
(jen počáteční stránka, dál řízeno uživatelem).

---

## 12. Internacionalizace (i18n)

Aplikace podporuje češtinu a angličtinu, výběr per-zařízení (volba se ukládá
do localStorage, ne na uživatele). Přihlášený uživatel přepíná v patce
sidebaru pod přepínačem vzhledu, nepřihlášený přímo na LoginScreen
v patce karty. Výběr má tři hodnoty: `cs`, `en`, `auto` (auto čte
`navigator.language`).

> **Pokrytí:** UI chrome komponent (sidebar, viewer, formuláře, browser,
> login, modaly) i server-driven labels (toolbar tlačítka, taby formulářů,
> taby detailu, titulky modulů) jsou lokalizované. Backend čte
> `Accept-Language` a vrací data ve zvoleném jazyce přes cfgItem
> mechanismus (viz níže *Pokrytí a co se nepřekládá v `t()`*). Validační
> chybové kódy mapuje frontend přes `i18n/errors.js` `translateError()`
> na lokalizovaný text, neznámé kódy fallback na server `message`.

### Soubory

| Soubor | Co dělá |
|---|---|
| `frontend/src/stores/language.svelte.js` | Store s `mode`, `current`, `setMode()`, helpery `t()` / `tn()`, ICU formatter cache |
| `frontend/src/i18n/cs.js`, `en.js` | Ploché slovníky s tečkovou notací klíčů |
| `frontend/src/i18n/index.js` | Barrel export — komponenty importují odsud |
| `frontend/index.html` | Inline `<script>` bootstrap — nastavuje `<html lang>` před prvním renderem |
| `frontend/src/api/client.js` | Posílá `Accept-Language: {language.current}` v každém requestu |
| `frontend/scripts/check-i18n.mjs` | Lint — detekuje chybějící klíče mezi cs a en (`npm run check:i18n`) |
| `frontend/src/components/layout/Sidebar.svelte` | UI přepínač v dropdownu patky (přihlášený uživatel) |
| `frontend/src/components/auth/LoginScreen.svelte` | Native `<select>` přepínač v patce login karty (nepřihlášený) |

### Režimy

- `'cs'` / `'en'` — force daný jazyk
- `'auto'` — vezme z `navigator.language` (první 2 znaky), pokud to není
  `cs` / `en`, fallback `en`

Default pro nové uživatele: `'auto'`.

### localStorage

Volba se persistuje pod klíčem `shpd_language`. Hodnoty: `'cs'`, `'en'`,
`'auto'`. **Stejný klíč čte i bootstrap script** v `index.html` — pokud
měníš klíč nebo detekci, musíš změnit obě místa.

### Anti-flash bootstrap

Před prvním renderem běží inline `<script>` v `index.html`, který nastaví
`document.documentElement.lang` na efektivní jazyk. Bez něj by `<html lang>`
zůstal s defaultem (`en`) až do hydratace JS, což by mátlo screen readery
a SEO crawlery, byť jen krátce.

### Reload po přepnutí

`language.setMode()` persistuje volbu do localStorage a okamžitě volá
`location.reload()`. Důvody:

- nulové riziko stale stavu (server-driven názvy v navigaci, viewer
  metadatech, stavech dokumentů)
- jednoduchost — nemusíme udržovat list všech kešovaných API odpovědí
- chování konzistentní s typickými enterprise systémy

Soft refetch lze přidat později, pokud reload bude vadit.

### `language` store API

```js
import { language, t, tn } from '../../i18n/index.js';

language.mode;             // 'cs' | 'en' | 'auto' — uživatelská volba
language.current;          // 'cs' | 'en' — efektivní jazyk po rozbalení 'auto'
language.setMode('cs');    // přepne, persistuje, reloadne stránku

t('common.cancel');        // → 'Zrušit' / 'Cancel'
t('viewer.empty', { table: 'Faktury' });
                           // → 'Tabulka Faktury je prázdná'
t('viewer.recordCount', { count: 3 });
                           // ICU plural: → '3 záznamy'
tn('viewer.recordCount', 3);  // shortcut když je `count` hlavní param
```

### Slovníky

Klíče jsou **ploché s tečkovou notací** (`'sidebar.language'`,
`'viewer.tab.active'`). Žádná hluboká struktura — usnadňuje to grep
a lint script.

Pojmenování klíčů je v **anglické konvenci** (`'common.cancel'`,
`'sidebar.language'`), aby ladilo se zbytkem kódu.

Pluralizace přes ICU MessageFormat:

```js
// v cs.js:
'viewer.recordCount': '{count, plural, one {# záznam} few {# záznamy} many {# záznamů} other {# záznamů}}',
// v en.js:
'viewer.recordCount': '{count, plural, one {# record} other {# records}}',
```

### Fallback chain

`t(klíč)` zkouší v tomto pořadí: aktuální jazyk → `en` → klíč samotný.
Když klíč chybí v obou slovnících, vrátí se holý klíč jako `'sidebar.foo'` —
viditelný signál pro vývojáře, žádný runtime exception. Při chybě formátu
ICU řetězce se warning loguje do konzole a vrací se taky klíč.

### Přidání klíče

1. Přidat řádek do `frontend/src/i18n/cs.js` a do `en.js` (oba!).
2. Spustit `npm run check:i18n` v `frontend/` — musí projít.
3. V komponentě: `import { t } from '../../i18n/index.js'`, použít `{t('můj.klíč')}`.

### `Accept-Language` header

`api/client.js` posílá `Accept-Language: {language.current}` v každém requestu.
Backend `public/index.php` čte hlavičku v `resolveLanguage()` (s fallbackem
`'en'`) a předává do `TableLoader`, `MetaController`, `NavigationController`,
`SettingsController`, `ViewerLoader`, kde `LocalizedFieldResolver` /
`ConfigLocalizer` vyberou správnou variantu z JSONC definic.

### Lint

`npm run check:i18n` zkontroluje, že `cs.js` a `en.js` mají stejnou sadu
klíčů. Vrací exit 1 a vypíše chybějící klíče, jinak 0. Není v CI, volá
se ručně před commitem.

### ICU MessageFormat runtime

Použitý balíček: [`intl-messageformat`](https://www.npmjs.com/package/intl-messageformat)
(repo `@formatjs/intl-messageformat`). Tenké runtime (~12 KB gzip),
vestavěná CLDR pravidla pro plural — funguje pro libovolný jazyk,
takže přidání třetího jazyka je jen otázka nového slovníku, ne rewrite
helperu.

Store si cachuje zkompilované `IntlMessageFormat` instance per
`lang::klíč`, takže opakované rendery stejného klíče nejsou drahé.

### Konvence klíčů

Pojmenování `oblast.komponenta.element` (max tři tečky):

- **oblast** — funkční doména: `viewer`, `form`, `login`, `sidebar`,
  `attachments`, `browser`, `app`, `tabbar`, `subtable`, `common`
- **komponenta / element** — co konkrétně se překládá: typicky `label`,
  `placeholder`, `title`, `empty`, `loading`, `failed`, `confirmDelete`

Pravidlo: **žádné pořadí slov přes konkatenaci**. Vždy přes ICU
placeholder — slovanské jazyky mají jiné pořadí než angličtina:

```js
// Špatně:
{t('viewer.tab.label')} ({count})

// Správně — v slovníku:
'viewer.tab.activeWithCount': '{tab} ({count, plural, one {# záznam} other {# záznamů}})'
// v komponentě:
{t('viewer.tab.activeWithCount', { tab: t('viewer.tab.active'), count })}
```

### Pokrytí a co se nepřekládá v `t()`

`t()` pokrývá UI chrome komponent: sidebar, viewer (taby, hledání,
modaly), formuláře (FormDialog, FormEditor, FormStateBar, AttachmentPanel,
FormSubTable), TableBrowser, app shell (Header, ContentArea, TabBar),
LoginScreen, Modal `aria-label`. Slovníky mají v této chvíli ~96 klíčů.

**Server-driven labels se nepřekládají v `t()`** — generuje je backend
a posílá v API odpovědi v aktuálním jazyce (`Accept-Language` header):

- toolbar tlačítka ve vieweru (`Add` / `Přidat`, `Open` / `Otevřít`) —
  `TableViewer::getToolbarActions()` čte z cfgItem
  `core.system.viewerDefaults.toolbarActions`. Module-specific override
  (např. mail `New message` / `Nová zpráva`) v `core.mail.viewerDefaults`.
- záložky editačních formulářů (`Contact` / `Kontakt`, `General` / `Obecné`)
  — `JsoncFormLoader` aplikuje `ConfigLocalizer::localize()` na `:cs`/`:en`
  varianty v `forms/{table}.jsonc`. `AutoFormBuilder` čte label syntetického
  General tabu z `core.system.formDefaults.generalTabLabel`.
- detail taby ve vieweru (`Overview` / `Přehled`, `Content` / `Obsah`,
  `Attachments` / `Přílohy`, …) — `TableViewer::detailTabLabel()` /
  `defaultOverviewLabel()` čte z `*.viewerDetailLabels.tabs.*` per-modul
  (`core.system`, `base.persons`, `core.mail`, `economy.codebooks`,
  `economy.items`).
- názvy modulů, tabulek, sloupců — `ConfigLocalizer` /
  `LocalizedFieldResolver` z jsonc.

Tyto cfgItems žijí ve `compiled.{cs,en}.json` v adresáři DS (`config/configuration/`)
— generuje je `vendor/bin/shpd-ds ds-upgrade`. Pokud config není
zkompilovaný (čerstvá DS, chybí soubor), `TableViewer` / `AutoFormBuilder`
fallback na anglický řetězec.

### Mapování chybových kódů — `i18n/errors.js`

Server vrací při chybě `{code, message, details?}`. Helper
`translateError(error)` zkusí přeložit `error.code` přes klíč `error.<CODE>`
ve slovníku, jinak fallback na `error.message` (anglicky ze serveru),
v krajním případě `t('common.unknownError')`.

```js
import { translateError } from '../../i18n/errors.js';

if (!result?.success) {
  alert(translateError(result.error));
}
```

Pokrývané kódy v `cs.js` / `en.js`: `VALIDATION_ERROR`, `NOT_FOUND`,
`RECORD_NOT_FOUND`, `TABLE_NOT_FOUND`, `UNAUTHORIZED`, `FORBIDDEN`,
`BAD_REQUEST`, `METHOD_NOT_ALLOWED`, `INTERNAL_ERROR`, `UPLOAD_ERROR`,
`NETWORK_ERROR`. Méně časté kódy z analyzer pipeline a podobně se
nemapují — fallback na server `message` stačí.

`details[].field` (id sloupce) zůstává v anglickém ID podle backendu;
field-level error display ve `FormEditor` zobrazí `details[].message`
přímo — mapování `field` na lokalizovaný název sloupce z
`TableDefinition` je future enhancement.

### Otevřené body

- Volba je per-zařízení; per-uživatel volba (sloupec `preferred_language`
  v `core_system_users`) je odložená — localStorage je dle rozhodnutí
  dostatečná, řeší se až bude potřeba (typicky pro multi-device UX).
- Inline labels v `renderDetail()` content (group titles
  `Identifikace`/`Předmět`/…, column labels v table content
  `Název`/`Funkce`/…) zůstávají v PHP hardcoded česky. Lokalizace přes
  `TableDefinition.column.name` (která je už lokalizovaná) by byla
  natural follow-up; mimo scope Fáze 1C.

---

## 13. PWA — instalovatelná aplikace

Aplikace jde nainstalovat do telefonu i na desktop jako PWA
(`tasks/pwa-v1.md`, issue #52). V1 je **manifest-only**: žádný service
worker, žádná offline cache, žádná invalidace po deployi. Od ~2023 stačí
Chromu/Edgi pro instalační prompt manifest + HTTPS; Safari umí „Přidat na
plochu" vždy. Service worker přijde až s push notifikacemi (vlastní issue).

### Manifest

Jméno a ikona jsou per-DS, proto manifest **generuje PHP**, ne statický
soubor: `GET /api/v1/_app/manifest` (`AppController::manifest()`, veřejný —
`AuthMiddleware::isExempt()`, prohlížeč ho fetchuje bez tokenu).

- `name`/`short_name` — `app.name`/`app.shortName` s fallbacky jako
  `/_app/info` (sdílený helper `resolveNames()`).
- `id`/`start_url`/`scope` — `/app/` v prod, `/{ds-id}/app/` v dev. Režim
  rozhoduje `ResolvedDataSource::isDevMode()` (předává `dispatchApp()`),
  žádné vlastní parsování URL.
- `icons` — **absolutní cesty** s týmž prefixem; relativní by se resolvovaly
  proti URL manifestu (`/api/v1/…`), ne proti `scope`.
- `display: standalone`, `lang` z `defaultLanguage` DS, `theme_color`/
  `background_color` = literály `--shpd-color-primary`/`--shpd-color-bg`
  z `variables.css` (ne runtime derivace).
- `Content-Type: application/manifest+json`, `Cache-Control: public,
  max-age=3600`. `Response::send()` respektuje explicitní Content-Type
  z `withHeader()` — do té doby ho JSON default přepisoval.

### Linky v `index.html`

`frontend/index.html` (ne `public/app/index.html` — to je build artefakt
mimo git) má za bootstrap scripty:

```html
<link rel="manifest" href="../api/v1/_app/manifest" />
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png" />
<meta name="theme-color" content="#005089" />
```

Href jsou **relativní záměrně**: dokument žije vždy přesně na `/app/`
resp. `/{ds-id}/app/` (SPA bez URL routingu), takže míří správně v obou
režimech bez změny nginx. Kdyby někdy přibyl URL routing s hlubšími
cestami, je potřeba `<base>` nebo absolutizace. Vite tyto href nechává
být (neresolvují se na soubor v `frontend/`, ENOENT se polyká).
`theme-color` je statická — přebarvování podle DS vzhledu je mimo scope.

### Ikony

Statická defaultní sada v `frontend/public/icons/` (Vite `publicDir` →
`public/app/icons/`): `icon-192.png`, `icon-512.png` (`purpose: any`,
zaoblené rohy), `icon-maskable-192.png`, `icon-maskable-512.png` (motiv
v 80 % safe zone, pozadí do krajů), `apple-touch-icon.png` 180×180 bez
průhlednosti. Písmeno „S" na Shipard primary, vygenerováno jednorázově
(SVG → `rsvg-convert`). Výměna za skutečné logo = přepsání PNG, žádná
změna kódu.

### Follow-upy (mimo V1)

- per-DS instalační ikony z branding slotu `icon`,
- push notifikace + service worker,
- hosting PWA — PWA je vázaná na origin, DS appky na jiných doménách do
  hosting PWA zabalit nejde (otevřený bod v #52).

---

## 14. Budoucí rozšíření

- **Filtrování v prohlížeči** — toolbar s filtry podle typu sloupce
- **Mazání záznamů** — tlačítko v řádku nebo hromadně
- **Inline editace** — editace přímo v tabulce
- **Enum hodnoty** — Select s options z konfigurační položky (cfgItem)
- **Navigace s filtry** — položky sidebar s předdefinovaným filtrem (Faktury vydané = doc_type:INV)
- **Výběr sloupců** — uživatel si vybere které sloupce vidí
- **Export** — CSV/Excel export z prohlížeče
- **Oprávnění** — skrývání položek navigace podle uživatelských práv
- **Editační formuláře pro doc states** — stavová tlačítka, zamčení readOnly formuláře, badge stavu v hlavičce (Fáze 4 stavů dokumentů)
