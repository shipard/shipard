# Shipard — Varianty UI (shells)

> **Stav: návrh (design doc).** Popisuje koncepty a cílový stav, ne implementaci.
> Realizace probíhá po fázích přes zastřešující issue [#45](https://github.com/shipard/shipard/issues/45); každá fáze má
> vlastní PRD v `tasks/`. Sekce dokumentu se po realizaci přesouvají /
> přepisují do `frontend.md` a souvisejících dokumentů (dokumentujeme, co
> existuje — plány žijí tady).

## 1. Motivace

Jedno rozhraní pro všechny skončí kompromisem. Uživatelé se dělí zhruba na:

- **konzervativní** — zvyklí na starý Shipard (menu hlavních agend nahoře,
  levý pás ikon), chtějí „primitivnější" a hustší rozhraní,
- **mainstream** — současný sidebar jim vyhovuje (standard dnešních aplikací),
- **AI-first** — průřezový pohled na firmu, primární interakce přes AI
  asistenta per „oddělení", maximální kompaktnost (malé notebooky).

Klíčové pozorování: všechny varianty jsou **různé projekce téhož navigačního
stromu na jiné regiony chrome**. „Vnitřek" (viewery, formuláře, dashboard,
reporty, chat) je už dnes shell-agnostický a **zůstává beze změny**. Neměníme
tedy aplikaci, ale zavádíme tenkou vyměnitelnou vrstvu navigačního chrome.

## 2. Terminologie

- **Shell** — varianta aplikačního chrome: rozložení navigace a globálních
  prvků (user menu, branding, vstupy do nastavení). Nezaměňovat s *layoutem
  vieweru* (list/grid, `docs/viewer-grid.md`) — to je prezentační režim
  jednoho vieweru, ortogonální koncept.
- **Sekce** — položka úrovně 1 navigačního stromu (`global.navSections`:
  `basic`, `purchase`, `sales`, `accounting`, `system`). Sémantická „oddělení
  firmy".
- **Screen surface** — kontrakt, kterým aktivní obrazovka publikuje svůj
  povrch (titul, akce, kontext list/detail, back handler) a shell rozhoduje,
  kde ho vykreslí. Zobecnění dnešního `topBar*` kanálu v `layout.svelte.js`.
- **Akční slovník** — existující množina navigačních/otevíracích akcí:
  `navigate`, `navigateToViewer`, `navigateToPanel`, `open_form`,
  `open_viewer`.

## 3. Invarianty (co se nemění)

- `ContentArea` a všechny obsahové komponenty (Viewer, TableBrowser,
  Dashboard, SettingsPage, FormDialog, ReportsPage, ChatView…) — sdílené,
  shell je pouze obaluje.
- Server-driven princip: server dodává **data** (navigační strom, stavy
  sekcí, AI kontexty), klient má **registrované renderery v kódu**. Shell je
  schopnost klienta jako `Viewer.svelte` — **žádný JSON layout jazyk**.
  JSON nese jen volbu shellu a jeho parametry (analogie
  `getGridOptions()`).
- Endpoint `GET /_ui/navigation` zůstává jediný a vrací strom; shelly ho
  projektují. Per-shell endpointy nezavádíme.
- Mode systém (app / settings / account) a sémantika `navigationStore`
  (activeItem per mode, pending hinty).

## 4. Shell kontrakt

> Realizováno Fází 4 (`frontend/src/components/shells/` — registry
> `index.js`, `SidebarShell`, `ClassicShell`; Fází 6 přibyl `WildShell`)
> — viz `frontend.md` §4 *Shelly*.

Shell je Svelte komponenta registrovaná v klientské mapě
`shells/index.js` (vzor `panelComponents` v `ContentArea`); jména drží
`KNOWN_SHELLS` v `utils/shell.js`.

**Dostává:** navigační strom (`navigationStore.appNavTree`),
`navigationStore` (mode, activeItem, activeSection, navigate…), screen
surface (čte), stavy sekcí (badge data), `onLogout`, `onOpenThemePanel`
+ bindable `themePanelLeftOffset` (CSS délka pro pozici panelu).

**Renderuje:** vlastní chrome + sdílenou `ContentArea`. Globální overlaye
(ThemePanel, CommandPalette, ChatPanel) renderuje **AppShell** — shell
dodává jen trigger/offset (mobilní drawer má transform, fixed panel
uvnitř by se uvěznil).

**Povinně umí (checklist):**

- [x] navigace stromem (všechny typy leafů vč. `panel`, `report:*`)
- [x] mode přepínání app / settings / account + návrat (settings/account
      chrome řeší resolver — vždy sidebar-style, D6)
- [x] user menu (sdílený primitiv)
- [x] branding slot
- [x] otevření ThemePanel a ChatPanel (trigger/offset; render vlastní AppShell)
- [x] konzumace screen surface (kde vykreslí titul/akce, je věc shellu;
      classic na desktopu region nemá — D7)
- [x] badge stavů sekcí (kde je vykreslí, je věc shellu)
- [x] command palette trigger

Kontrakt předepisuje **co** (schopnosti), ne **jak** (rozložení) — jinak
shelly zkonvergují ke kompromisu, kterému se chceme vyhnout.

## 5. Sdílené primitivy chrome

> Realizováno Fází 1 (`frontend/src/components/chrome/`) — viz
> `frontend.md` §4 *Sidebar — struktura*.

`Sidebar.svelte` je rozbitý na skládatelné kusy; shelly je **komponují**,
neimplementují znovu:

| Primitiv | Obsah (dnes v Sidebaru) |
|---|---|
| `NavTree` | rekurzivní renderer stromu (skupiny, leafy, aktivní stav) |
| `NavIconStrip` | plochý pás ikon leafů (collapsed režim, `flattenLeaves`) |
| `NavFlyoutStrip` | pás uzlů úrovně 2 s Popover flyouty úrovně 3 (classic, Fáze 4) |
| `UserMenu` | avatar + dropdown (Nastavení účtu/aplikace, jazyk, odhlásit); `direction="down"` pro top bar |
| `BrandingHeader` | ikona aplikace + logo |
| `ModeBackBar` | „← Zpět do aplikace" v settings/account módu |

## 6. Screen surface

> Realizováno Fází 1 (`layout.svelte.js`: `setScreenSurface` /
> `clearScreenSurface`, gettery `surface*`) — viz `frontend.md` §4.

Bývalý `topBar*` kanál, přejmenovaný a zobecněný: obrazovka publikuje
`{context, actions, title, back}`, **libovolný** shell ho konzumuje po svém
(mobilní top bar, toolbar klasického shellu, tab lišta divokého shellu).
Publikující strana (Viewer, FormStateBar…) se nezměnila — kontrakt už
existoval, jen přestal být „mobilní"; MobileTopBar je jeho konzument.

## 7. Aktivní sekce (`navigationStore`)

> Realizováno Fází 1 (getter `activeSection`, derivace z `appNavTree`
> přes `utils/navTree.js`) — viz `frontend.md` §4.

Jediná skutečná změna sdíleného stavu: vedle `activeItem` existuje
`activeSection` (id sekce úrovně 1, do níž aktivní leaf patří; `_top` leafy
mají sekci `null`). Potřebují ji shelly s odděleným regionem sekcí (classic:
horní menu, wild: levý rail) — po kliknutí na sekci se zobrazí její leafy
a vybere výchozí (první leaf sekce; přesné chování dořeší PRD). Sidebar
shell `activeSection` jen odvozuje pro zvýraznění.

## 8. Stav sekcí (badge)

> Realizováno Fází 3 (`tasks/ui-shells-phase3.md`): extrakce
> `Core\Feed\FeedCollector` z DashboardControlleru, opt-in pole
> `navSection` v kartovém kontraktu (mail → `_top`, content-tag →
> `basic`, alerty per check přes `alertChecks[].navSection`), endpoint
> `GET /_ui/section-badges` + pilot v sidebaru (badge na root sekcích
> rozbaleného NavTree, store `sectionBadges.svelte.js`, polling 60 s +
> focus) — viz `dashboard.md` §3/§4/§7, `frontend.md`.

„Oranžové kolečko u Účtárny" = serverová **agregace dashboard feedu per
sekce**, žádný nový subsystém (rozhodnuto: začít jednoduše).

- Finální datový tvar (rezervu `source?` lze doplnit později):
  `{sections: {<sectionId>: {count: int, severity: "warning"|"danger"}}}`
  — jen neprázdné sekce, `_top` platný klíč. Počítají se jen karty pásem
  `urgent` (→ danger) a `review` (→ warning); `ready`/`info` ne — trvale
  svítící badge není signál.
- Transport: samostatný endpoint `GET /_ui/section-badges` (odlišná
  kadence obnovování než strom navigace).
- Mapování: existující `FeedSource` karty → sekce přes opt-in pole
  `navSection` (mail → `_top`, alerty → per check). Karta bez pole se
  nepočítá.
- **Pilot v současném sidebaru** (badge u skupin, tečka v barvě severity
  + počet, cap 99+) — ověření užitečnosti bez čekání na nové shelly.
  `_top` se v sidebaru nerenderuje (položky jsou trvale viditelné);
  collapsed pás zůstává bez badge. Classic shell (Fáze 4) badge kreslí
  na položkách horního menu a `_top` badge na domečku. Wild shell
  (Fáze 6) je kreslí jako pilulku s počtem na ikonách railu — vč. `_top`
  na domečku.

## 9. Command palette

> Realizováno Fází 2 (`tasks/ui-shells-phase2.md`): overlay
> `chrome/CommandPalette.svelte` + `stores/palette.svelte.js`, zkratka
> Ctrl/Cmd+K + lupa v sidebaru — viz `frontend.md` §4 *Command palette*.

Spotlight/Cmd-K overlay, **shell-nezávislý** (renderuje AppShell, shelly mají
jen trigger + klávesovou zkratku — položka checklistu v §4). Tenké UI nad
existujícími zdroji:

- **Provider kontrakt (hotový koncept):** zdroj nabídky = záznam v
  `SOURCE_DEFS` store palety — dodává položky se svou skupinou výsledků,
  `results` je skládá. V1 providery: tři navigační stromy (app / settings /
  account, lazy + session cache) a recents (localStorage, jen app mód,
  cap 7, plní se i běžnou navigací). Nápověda/dokumentace a záznamy přes
  fulltext vieweru se později přidají jako **další provider, ne přepis**
  (serverové zdroje přes debounced dotaz).
- **Akce po výběru:** existující akční slovník — v1 `navigate()`
  s originálním leafem stromu (+ přepnutí módu); `open_form` a spol. ve v2.
- Fuzzy match na klientu s foldingem diakritiky, ranking prefix > začátek
  slova > subsequence, remíza → boost z recents (`utils/paletteMatch.js`).

## 10. Sekční AI kontexty

**Realizováno** (Fáze 5, `tasks/ui-shells-phase5.md`): cfgItem
`core.chat.sectionContexts` (prompt per sekce), sloupec `section` na
konverzaci + sekční blok system promptu, čtecí nástroj `feed_cards`
(feed pullem, D3), karty sekce jako UI v prázdné scoped konverzaci
(`GET /_ui/dashboard?section=`), chip u inputu + tlačítko chatu
v chrome obou shellů. Detaily: `docs/chat.md` §5/§7,
`docs/dashboard.md` §7, `docs/mcp-server.md` §5.

Původní návrh: konfigurace, ne UI — rozšíření sekcí o AI kontext:
prompt „oddělení", výsek feedu, který asistent vidí, dostupné nástroje.
Chat komponenta existuje; nové je **scope chatu = sekce**. „Upozornění
z dashboardu v chatu" = sekční feed vložený do kontextu konverzace
(není to AI generující upozornění; na kartu lze konverzačně navázat).
Využitelné ze všech shellů (chat panel otevřený z Účtárny zná sekci);
wild shell to jen povyšuje na první záložku sekce.

## 11. Volba shellu

> Realizováno Fází 4 (`tasks/ui-shells-phase4.md`): field typ `shell`
> (allowlist `SettingsController::SHELLS`), pole `app.shell` /
> `account.shell` v `core.system`, DS default v `/_app/info`, klient
> `stores/shell.svelte.js` + `utils/shell.js` — viz `app-settings.md`
> a `frontend.md` §4 *Shelly*.

Přesně vzor vzhledu (theme, Fáze 4): **DS default + user follow/override**.

- `app.shell` (scope ds) — default pro DS, edituje Nastavení aplikace
  (přes Uložit),
- `account.shell` (scope user) — `{follow:true}` nebo
  `{follow:false, shell, params}`, mění se okamžitě,
- absence hodnoty = follow; DS bez defaultu = `sidebar`; neznámé jméno
  padá na `sidebar` (server allowlist + klientský `resolveShell`).

**Parametry shellu** (hustota/kompakt, umístění prvků, výchozí obrazovka)
jsou options konkrétního shellu — ortogonální osa k volbě shellu; v1 se
jen přenášejí (`params`), žádné UI. Hustota může pokrýt část
„konzervativní" poptávky levněji než celý shell.

Změna shellu = **soft swap** komponenty v AppShellu (žádný reload) —
`navigationStore` přežije, uživatel zůstane na aktivní položce. Mobil
(≤ 48rem) a settings/account módy volbu ignorují — vždy sidebar-style
chrome (řeší resolver v AppShellu jedním výrazem).

## 12. Plánované shelly

| Shell | Sekce (úroveň 1) | Leafy sekce | Pozn. |
|---|---|---|---|
| `sidebar` | strom v levém panelu | tamtéž | dnešní stav, default |
| `classic` | horní menu (+ domeček `_top`) | levý pás **uzlů úrovně 2** — leaf naviguje, skupina otevírá flyout s úrovní 3 | „starý Shipard"; **realizován Fází 4**. Klik na sekci → první leaf. Starý Shipard měl overflow „malé ikony dole" — v1 neřeší, pás scrolluje |
| `wild` | levý rail ikon s popisky a badge | horní záložky-ikony obsahu; 1. záložka = AI asistent sekce | AI-first, maximální kompaktnost; **realizován Fází 6** (`tasks/ui-shells-phase6.md`). V1 = kompozice hotových kusů (Fáze 1–5), rozvoj iterativně dle chování; Issue #51 přidal popisky ikon railu (šířka = `--shpd-rail-width`) a text v aktivní záložce. Stav prohlížení shell-lokální (`stores/wildShell.svelte.js`), paměť poslední záložky per sekce přežije settings mód, reload ne |
| `mobile` | — | — | dnes režim uvnitř shellu; cílově vlastní shell, rezoluce = f(volba, form factor) |

`classic` a `wild` jsou zrcadlové projekce (sekce nahoře/vlevo, leafy
vlevo/nahoře) — potvrzuje nosnost kontraktu. Mobil jako samostatný shell
zbaví desktopové shelly starosti o drawer a umožní návrh „odspodu" pro
telefon; do té doby zůstává dnešní `isMobile` větvení.

## 13. Úprava navigačního stromu — zánik sekce „Základní"

Osoby a Položky se přesunou z `navSection: "basic"` do `"_top"` (po vzoru
starého Shipardu, kde základní agendy žily v první „domečkové" sekci).
Sekce `basic` z `global.navSections` zmizí — nevymyslíme pro ni víc než dvě
položky. Čistě konfigurační změna (`module.jsonc` u `base.persons`
a `economy.items` + `navSections.jsonc`), lze provést kdykoli průběžně;
shelly na počtu sekcí nezávisí.

## 14. Fázování

| Fáze | Obsah | Viditelný přínos |
|---|---|---|
| 1 | Refaktor primitiv (§5), screen surface (§6), `activeSection` (§7) | žádný (enabler); nulová změna UI |
| 2 | Command palette (§9) | všichni uživatelé, všechny shelly |
| 3 | Badge sekcí (§8) + pilot v sidebaru | signalizace „kam mám zajít" |
| 4 | Shell `classic` + volba shellu (§11, §12) | konzervativní uživatelé |
| 5 | Sekční AI kontexty (§10) | příprava wild shellu, přínos i v sidebaru |
| 6 | Shell `wild` | AI-first uživatelé; rozvíjet iterativně dle chování |

Každá fáze má hodnotu sama o sobě i v současném sidebaru — žádný krok není
sázka na úspěch pozdějších fází. Abstrakce se staví se **dvěma** shelly
(sidebar + classic); wild ji následně testuje, negeneralizujeme na tři
dopředu.

## 15. Mimo scope tohoto tématu

- **Živé prvky UI v chatu** (viewer/sestava jako „artefakt" vedle
  konverzace) — má fungovat všude, vlastní trať; technicky nakročeno
  (obsahové komponenty jsou samostatné, `open_viewer` akce existuje).
- **Varianty obsahu dashboardu** (uživatel se 3 doklady/týden vs. 20/den) —
  serverové téma (složení feedu, summary strategie), ne shell.
- **URL routing / persistence módu** — beze změny stávajícího rozhodnutí.

## 16. Otevřené body

- ~~Přesné chování kliku na sekci v classic/wild~~ — rozhodnuto Fází 4:
  první leaf sekce (chování starého Shipardu); budoucí `defaultItem`
  v `navSections.jsonc` možný.
- ~~Transport badge dat a kadence refreshe~~ — rozhodnuto Fází 3:
  samostatný endpoint, polling 60 s + focus.
- ~~Mechanika přepnutí shellu~~ — rozhodnuto Fází 4: soft swap (§11).
- Úzká místa kompaktních shellů (reporty, široké gridy) — wild v1
  neřeší (obsah je tatáž `ContentArea`, jen bez sidebaru = víc šířky);
  vyhodnotit iterativně dle chování.
