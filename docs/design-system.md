# Shipard — Design system

## 1. Přehled

Vizuální systém aplikace — paleta barev, doc-state konvence, stavové badge,
focus/hover stavy. Tento dokument je referenční — popisuje **co která barva
znamená** a **kde se používá**, ne jak se kompiluje CSS.

Veškeré barevné rozhodnutí je centralizováno v `frontend/src/styles/variables.css`
jako CSS custom properties (tokeny). Komponenty používají tokeny, ne hex hodnoty.
Když se rozhoduje o nové barvě, vždy nejdřív sáhni do `variables.css`.

### Filozofie

- **Brand má dvě barvy** — modrou (`#005089`) a oranžovou (`#EB6507`). Zbytek
  je servisní paleta (stavy, neutrály, hovery).
- **Modrá = primary akce a navigace.** Aktivní položka v sidebaru, primary
  tlačítka, odkazy, focus indikátor.
- **Oranžová = brand accent, „kde jsem".** Levý proužek u aktivní položky
  v navigaci (sidebar, shelly), accent badge (VIP). **V seznamech záznamů
  se oranžová pro výběr nepoužívá** — výběr nese jen modré pozadí, aby
  se accent nebil se stavovým proužkem.
  **Oranžová není stav** — žádný doc-state ji nesmí používat.
- **Confirmed/done = klid.** Většina záznamů je v pořádku. UI je nemá
  zdůrazňovat. Žádný proužek, žádné křiklavé pozadí, tichá šedá u badge.
- **Stavy mimo „v pořádku" mají barvu pruhu** (žlutá, fialová, červená, šedá).
  Pozadí řádku zůstává bílé — barevné pozadí je vyhrazené pro výběr.
- **Méně je více.** Když uživatel otevře seznam 200 záznamů, většina je
  bílá. Barvu mají jen položky, které stojí za pozornost.

---

## 2. Paleta — brand barvy

### Primary (modrá)

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-primary` | `#005089` | Primary tlačítka, aktivní stav navigace, odkazy, ikony stavu |
| `--shpd-color-primary-hover` | `#003e6b` | Hover na primary tlačítku |
| `--shpd-color-primary-soft` | `#e6eef5` | Světlé pozadí pro primary badge a jemné zvýraznění |
| `--shpd-color-primary-soft-2` | `#cfdcea` | O stupeň silnější varianta |

### Accent (oranžová)

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-accent` | `#eb6507` | Levý proužek aktivní položky v navigaci, avatar VIP, focus přízvuky |
| `--shpd-color-accent-hover` | `#c45405` | Hover na accent prvcích |
| `--shpd-color-accent-soft` | `#fdebdc` | Pozadí accent badge |

---

## 3. Paleta — servisní

### Plochy

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-bg` | `#ffffff` | Hlavní pozadí (panely, řádky) |
| `--shpd-color-bg-secondary` | `#f6f8fa` | Hover stavy, sekundární plochy |
| `--shpd-color-bg-selected` | `#d4e2ee` | Vybraný řádek v seznamu |
| `--shpd-color-bg-selected-hover` | `#bdd1e3` | Vybraný řádek, hover |
| `--shpd-color-overlay` | `rgb(0 0 0 / 0.4)` | Modální overlay |

### Sidebar

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-bg-sidebar` | `#00345c` | Pozadí sidebaru — tmavá varianta brand modré |
| `--shpd-color-bg-sidebar-elevated` | `#014b80` | Dropdown / popover nad sidebarem (o stupeň světlejší, aby šlo poznat hranice) |
| `--shpd-color-bg-sidebar-hover` | `rgb(255 255 255 / 0.08)` | Hover na položce sidebaru |
| `--shpd-color-bg-sidebar-border` | `rgb(255 255 255 / 0.10)` | Děliče v sidebaru |
| `--shpd-color-sidebar-active-bg` | `var(--shpd-color-primary)` | Pozadí aktivní položky sidebaru; custom téma přepisuje na neutrální alpha |
| `--shpd-color-sidebar-active-bg-hover` | `var(--shpd-color-primary-hover)` | Hover na aktivní položce |

### Text

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-text` | `#0f172a` | Primární text |
| `--shpd-color-text-secondary` | `#5b6878` | Sekundární text (popisky, meta) |
| `--shpd-color-text-sidebar` | `#e6eef5` | Text v sidebaru |
| `--shpd-color-text-sidebar-muted` | `#9bb4cc` | Tlumený text v sidebaru (nadpisy skupin) |

### Borders & focus

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-border` | `#e2e8f0` | Standardní hranice |
| `--shpd-color-border-strong` | `#cbd5e1` | Silnější hranice |
| `--shpd-color-border-focus` | `var(--primary)` | Hranice focusovaného inputu |
| `--shpd-color-focus-ring` | `rgb(0 80 137 / 0.18)` | Glow okolo focusovaného inputu |
| `--shpd-color-error-ring` | `rgb(220 38 38 / 0.15)` | Glow okolo chybného inputu |

### Stavové (semantické)

| Token | Hodnota | Hover token | Soft token | Použití |
|---|---|---|---|---|
| `--shpd-color-danger` | `#dc2626` | `--shpd-color-danger-hover` (`#b91c1c`) | `--shpd-color-danger-soft` (`#fef2f2`) | Mazání, error, zamítnutí |
| `--shpd-color-success` | `#16a34a` | `--shpd-color-success-hover` (`#15803d`) | — | Použít, potvrdit pozitivní akci |
| `--shpd-color-warning` | `#d97706` | `--shpd-color-warning-hover` (`#b45309`) | — | Varování (mimo doc-state systém) |

---

## 4. Doc-state systém

Záznamy v aplikaci procházejí stavy (koncept → v pořádku → archív → koš atd.).
Stav záznamu se kreslí na **dvou místech**:

1. **V seznamu** (`ViewerRow.svelte`) — barevný **levý proužek 6px**, pozadí
   řádku zůstává bílé.
2. **V detailu / formuláři** (`ViewerDetail.svelte`, `FormStateBadge.svelte`)
   — **barevný badge** se jménem stavu.

Backend posílá `stateStyle` (např. `"edit"`) v API odpovědích. Frontend ho
mapuje na CSS třídy `docState_{stateStyle}` (pro pruh řádku) a CSS třídy
badge (`shpd-detail__badge--{stateStyle}` resp. `shpd-form-state-badge.docState_{stateStyle}`).

### Tabulka stavů

| stateStyle | Pruh v seznamu | Badge v detailu | Význam |
|---|---|---|---|
| `confirmed` | žádný | tichá šedá | „V pořádku" — default stav, neruší |
| `done` | žádný | zelená | „Hotovo" — pozitivní cílový stav (např. použitý dokumentový návrh z pošty) |
| `concept` | žlutá `#facc15` | žlutá | Rozpracováno, dopiš to |
| `edit` | fialová `#a78bfa` | fialová | Právě se edituje (v opravě) |
| `archive` | šedá `#cbd5e1` | šedá | Archív — tichý, mimo aktivní práci |
| `trash` | tmavší šedá `#94a3b8` + line-through | šedá + line-through | V koši |
| `cancelled` | červená `#ef4444` | červená | Zrušeno, pozor |
| `error` | — | červená | Chyba (např. AI selhala) |

### Tokeny

Každý stav má až tři tokeny:

- `--shpd-color-state-{name}-bar` — barva levého proužku v `ViewerRow`
- `--shpd-color-state-{name}-bg` — pozadí badge v hlavičce detailu / formuláři
- `--shpd-color-state-{name}-text` — text uvnitř badge

Plus speciální `--shpd-color-state-archive-row` pro tlumený text řádku
v archive stavu (jiný kontext než badge text). Confirmed a done nemají
`-bar` token, protože jejich proužek je záměrně absent.

Všechny tokeny jsou definované v `frontend/src/styles/variables.css`.
Komponenty (`ViewerRow`, `ViewerDetail`, `FormStateBadge`) je sdílí,
takže změna barvy stavu v jednom místě se projeví všude.

### Konvence

- **`confirmed` / `done` nemá pruh.** Záměrné. Většina záznamů je v tomto stavu;
  proužek by jen šuměl. Badge ve formuláři má smysl (uživatel chce vidět,
  že je vše OK), ale tichou šedou — ne zelenou, ne modrou. V **hlavičce
  detailu** záznamu se štítek „V pořádku“ nekreslí vůbec (`detailBadge:
  false` u stavu, #136 D20) — formulář ho ukazuje dál.
- **Když přidáváš nový stav**, drž se palety. Žádné nové barvy nezavádět
  bez aktualizace tohoto dokumentu.
- **`edit` ≠ oranžová.** Oranžová je rezervovaná pro brand accent. `edit`
  je fialový. Před touto úpravou byla oranžová a kolizovala s aktivní/výběr
  proužkem.

### Při výběru řádku

Když uživatel označí řádek v seznamu:

- Pozadí řádku se zbarví na `--shpd-color-bg-selected` (hover
  `--shpd-color-bg-selected-hover`).
- **Stavový proužek zůstává** — koncept je dál žlutý, hotový záznam dál
  bez proužku (průhledný pruh splyne s modrým pozadím). Výběr stav nepřebíjí.

Dříve výběr přepisoval proužek na oranžový accent; bilo se to se stavy
(žlutý koncept po kliknutí zoranžověl), proto bylo přepsání odstraněno.
Oranžový proužek „kde jsem" zůstal jen v navigaci.

Logika je v `ViewerRow.svelte` a `ViewerGrid.svelte` přes CSS proměnnou
`--shpd-row-bar`, kterou nastavují globální `docState_*` třídy
(`styles/base.css`). `--selected` ji **nepřepisuje**.

### Alert severity (samostatná paleta)

`core.alerts` modul má **vlastní paletu** — alert není doc-state, má svůj
lifecycle (Active / Snoozed / Resolved / Dismissed). Stejná struktura tokenů
(`-bar`, `-bg`, `-text`), ale jiný prefix:

| severity | Bar v seznamu | Badge bg / text | Význam |
|---|---|---|---|
| `info` | modrá `#3b82f6` | světle modrá / tmavě modrá | Informace, FYI |
| `warning` | oranžová `#f59e0b` | světle žlutá / tmavě hnědá | Něco potřebuje pozornost |
| `error` | červená `#ef4444` | světle červená / tmavě červená | Něco je rozbité |

Mapování stringu `severity` (z `core.alerts.severities.style`) na CSS třídy:
`shpd-alert--severity-{style}`. Tokeny: `--shpd-color-alert-{info|warning|error}-{bar|bg|text}`.

Proč ne sdílet s `--shpd-color-state-*`? Alerty nemají stavy v doc-state
smyslu — `severity` je horizontální (závažnost), zatímco `alert_state`
(Active/Snoozed/…) je životní cyklus a kreslí se jiným způsobem (typicky jen
opacity/text indikací, ne bar).

---

## 5. Badge systém

Frontend má **tři vrstvy badge** s podobnou paletou:

| Komponenta | CSS prefix | Účel |
|---|---|---|
| `ui/Badge.svelte` (hlavička detailu, blok `badges`) | `.shpd-badge--*` | Štítek v hlavičce detailu — stav a vlastnosti, které nejsou zřejmé z kontextu (VIP…); typ záznamu nese ikona (D13, §12). Tutéž komponentu kreslí blok `badges` v obsahu (stav zaúčtování, #137 task 3); neznámý `style` = `neutral` |
| `ViewerDetail.svelte` (tab Návrh) | `.shpd-extracted__badge--*` | Badge pásma/verdiktu u dokumentového návrhu zprávy |
| `FormStateBadge.svelte` | `.shpd-form-state-badge.docState_*` | Stavový badge v hlavičce editačního formuláře |

Všechny tři používají **stejnou doc-state paletu** (concept, edit, confirmed,
archive, trash, cancelled). Plus mají vlastní **typové varianty** mimo doc-state:

### Typové varianty (jen `.shpd-badge--*`)

| Třída | Pozadí / text | Použití |
|---|---|---|
| `--neutral` | šedá | Default, typový badge bez významu (např. „Fyzická osoba") |
| `--primary` | modrá soft | Důležitá info (kód, vlastnost záznamu) |
| `--accent` | oranžová soft | „VIP klient", důležité, „za pozornost" |
| `--success` | zelená | Úspěch (mimo doc-state) |
| `--warning` | žlutá | Varování |
| `--danger` | červená | Chyba |

### API kontrakt pro backend

Backend posílá v `data.detail`:

```json
{
    "title": "Dodavatel s.r.o.",
    "subtitle": "FP-2026-0142 · Kancelářské potřeby",
    "badges": [
        { "label": "V pořádku", "style": "done" }
    ],
    "icon": "invoice-in",
    "tabs": [ ... ]
}
```

`title` je povinný pokud se má hlavička detailu vykreslit, a je to, podle
čeho uživatel záznam pozná — u dokladu protistrana; číslo dokladu a text
dokladu jdou do `subtitle`, doklad bez partnera má titulkem číslo (D14).
Podtitul skládá server, frontend ho jen vypíše. **Štítek typu záznamu se
neposílá** — typ nese `icon` (D13); `badges` jsou pro stav a pro
vlastnosti, které nejsou zřejmé z kontextu (např. „VIP klient“ se stylem
`accent`). Volitelný `icon` (klíč z `icons.js`, typicky shodný
s `viewers[].icon` v `module.jsonc`) přidá 40×40 ikonu vlevo od title.
Bez `title` se hlavička přeskočí (graceful fallback — backend nemusí být
upraven). Rozvržení a písmo hlavičky: §12 Detail záznamu.

---

## 6. CSS proměnné — best practices

### Pravidla

1. **Žádné hardcoded hex hodnoty v komponentách.** Pokud potřebuješ barvu,
   která není v `variables.css`, přidej ji tam jako token a použij přes
   `var(--shpd-color-...)`.
2. **Nepoužívej `:global(.docState_*)`** — kolizuje napříč komponentami,
   které mají stejné `docState_*` třídy. Místo toho použij child selectory
   (`.shpd-component-name.docState_edit`).
3. **Tlačítka řeš přes `<Button>`** komponentu, ne přes vlastní hardcoded
   styly s `!important`. `Button` má varianty `primary | secondary |
   danger | success | ghost`.
4. **Mezery, odsazení, zaoblení a písmo bez px.** Token (`--shpd-space-*`,
   `--shpd-radius-*`, `--shpd-font-size-*`) nebo rem; px jen okrajům,
   stínům a stavovému proužku. Hlídá `scripts/check-css-units.py` —
   viz §10 Jednotky a mezery.

### Anti-patterny

- ❌ `.shpd-extracted__btn-apply { background: #16a34a !important; }` — vlastní
  tlačítka mimo `<Button>` systém. ✅ Použij `<Button variant="success">`.
- ❌ `:global(.docState_edit) { background: #ffedd5 }` v `FormStateBadge.svelte`
  přebíjí `.docState_edit` v `ViewerRow.svelte`. ✅ Použij child selektor
  `.shpd-form-state-badge.docState_edit`.
- ❌ `box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15)` (stará Tailwind modrá)
  ve focus stavech inputů. ✅ Použij `var(--shpd-color-focus-ring)`.

---

## 7. Avatary v seznamu

Komponenta `ViewerRow.svelte` podporuje volitelné kruhové avatary s iniciálami.
Vykreslí se kdykoli backend pošle `row.avatar` jako string s iniciálami.
Pokud `row.avatar` chybí, fallback na `row.icon` (emoji nebo unicode glyph).

### Konvence

| Typ entity | Co posílat |
|---|---|
| Fyzická osoba | `avatar`: iniciály z `first_name + last_name` (např. `"PB"`) |
| Právnická osoba (firma) | `avatar`: iniciály z názvu (`"BG"` pro Beta Gastro) **nebo** `icon`: `"🏢"` |
| Faktura, dokument | `icon` (emoji), bez avataru |
| Došlá pošta | `icon` (emoji), bez avataru |

### Vzhled

- 32×32 px kruh (stejná sloupcová šířka jako `__icon`, ať se řádky bez
  a s avatarem zarovnají)
- Pozadí `--shpd-color-primary-soft`, text `--shpd-color-primary`
- `text-transform: uppercase` (z `"pb"` udělá `"PB"`)

---

## 8. Layout konvence

### Sidebar

- Aktivní položka má oranžový **3px proužek vlevo** (`::before`) + plné modré
  pozadí (`var(--shpd-color-primary)`)
- Neaktivní položky mají hover s lehkou bílou (`var(--shpd-color-bg-sidebar-hover)`)

### Mobilní drawer

Na ≤ 48rem (768 px při výchozím písmu; breakpoint shellu, viz
*Responzivita* níže) se sidebar mění na overlay drawer (vysune zleva přes
obsah, overlay ztmaví zbytek). Spouští ho hamburger v `MobileTopBar`. Detaily
v [`frontend.md`](frontend.md) sekce *Aplikační shell → Mobilní režim*.

### User dropdown v patce sidebaru

Uživatelské menu (avatar + jméno, klik otevře dropdown s položkami
Nastavení účtu / Odhlásit) používá `--shpd-color-bg-sidebar-elevated`
ladící se sidebarem — dropdown vizuálně patří k němu, ne k „bílé části
stránky".

Implementační past s `closeMenu()` před asynchronní akcí (zaveření menu
zasahuje do click bubbling cesty a může zrušit právě spuštěnou akci) je
popsaná v [`frontend.md` — sekce Konvence](frontend.md#9-konvence)
v pod-sekci *Dropdown / popover komponenty*.

### Viewer (seznam + detail)

- Vybraný řádek má sytější modré pozadí (`var(--shpd-color-bg-selected)`);
  stavový **6px proužek vlevo** zůstává podle `docState`, výběr ho nemění
- Detail má volitelnou hlavičku (title + subtitle + badges) — vykreslí se
  jen pokud backend pošle `detail.title` (kontrakt §5, rozvržení a písmo
  §12 Detail záznamu)

### Karty

Rozhodnutí D33–D35 (task 2 #137) a D36–D38 (task 2b) z #133. Karta
v obsahu je sdílená `Card.svelte`, mřížka karet `CardGrid.svelte` (obě
`components/ui/`).

- **Karta** (D33): rámeček `--shpd-color-border`, pozadí `--shpd-color-bg`,
  zaoblení `--shpd-radius-md`, odsazení `--shpd-space-md`; karty stránek
  v Nastavení (stránka nastavení, O zdroji dat, Nastavení zdroje dat,
  Zabezpečení účtu) `spacious` = odsazení `--shpd-space-lg`. Žádný stín,
  žádný vnější okraj — mezery dává rodič nebo `CardGrid`. Nadpis karty je
  `SectionHeading` (`title`, `headingLevel` 2–4, role a ikona podle místa
  karty níže; snippet `aside` sedí vpravo v řádku nadpisu — štítek stavu
  sekce v Přehledu firmy). Tón
  `warning` / `danger` mění jen barvu rámečku (`--shpd-color-warning` /
  `--shpd-color-danger`), `info` dává tónované pozadí
  `--shpd-color-primary-soft` s rámečkem `--shpd-color-primary-soft-2`
  (AI shrnutí). Kořen je flex sloupec: v roztaženém řádku mřížky zůstává
  obsah nahoře a patička může dostat `margin-top: auto`. Zbylé atributy
  (`data-testid`, `role`, `class` se stavovou třídou feedu) jdou na kořen;
  `as` volí značku (`section` výchozí, `div`, `article`, `li`). Vnitřní
  rozložení (řádek ikona + tělo karty feedu, stavový proužek) je obsah
  karty — rodič kořen `Card` přes vlastní třídu nestyluje, co karta
  potřebuje, je prop.
- **Nadpis podle místa karty** (D36): karta jako **samostatná část
  stránky** (Přehled firmy, karty stránek v Nastavení) má nadpis v roli
  **title** (`titleRole="title"` → `SectionHeading role="title"`: lg,
  strong, `line-height` jako titulek detailu); karta **uvnitř obsahu**
  (strany dokladu, karta návrhu z pošty, karty feedu) **heading**
  (výchozí). Pod nadpisem v roli title je mezera rovná odsazení karty
  (`--shpd-space-md`, u `spacious` `--shpd-space-lg`; `SectionHeading`
  prop `spacing`), pod heading jen xs jako v detailu. Mezinadpisy uvnitř
  karet Nastavení (bloky O zdroji dat, Volitelné v Nastavení zdroje dat)
  jsou `SectionHeading` v roli heading, ne vlastní třída.
- **Ikona tématu v nadpisu** (D38): karta jako samostatná část stránky
  má před nadpisem ikonu tématu — **stejnou jako příslušný prohlížeč /
  report v menu nebo stránka v Nastavení**, žádné nové ikony. Prop `icon`
  na `Card` (objekt ikony jako pro `Icon`); kreslí ji `SectionHeading`:
  `Icon size="md"` (velikost v `em` roste s písmem nadpisu, px
  nepřepisovat), šedá `--shpd-color-text-secondary`, mezera
  `--shpd-space-sm`, na střed řádku, bez `label` (dekorace, `aria-hidden`
  — nadpis čte jen text). Odkud: Přehled firmy z konfigurace sekce
  (`core.overview.sections[].icon`, `docs/overview.md` §3 — bez ikony se
  klíč neposílá a karta ikonu nemá, ne fallback `resolveIcon`), stránka
  nastavení z `definition.icon`, pevné stránky frontendu (O zdroji dat,
  Nastavení zdroje dat, Zabezpečení účtu) importovanou ikonou v kódu.
  Mezinadpisy ikonu nemají; karta uvnitř obsahu taky ne.
- **Mřížka** (D34): `repeat(auto-fill, minmax(min(<šířka>, 100%), 1fr))`,
  mezera md, `align-items: stretch` — karty v řádku vždy stejně vysoké,
  obsah nahoře. Šířku sloupce určuje použití (`minWidth`: Přehled firmy
  20 rem, feed dashboardu 22,5 rem). `min()` uvnitř `minmax` je nutné —
  bez něj sloupec na úzké šířce přeteče. Roztáhne se jen přímá položka
  mřížky: kde mezi mřížkou a `Card` musí být obal (komponenta s vlastním
  kořenem), obal i karta dostanou `height: 100%`. Dva pevné sloupce stran
  dokladu (`.shpd-docdetail__parties`, `.shpd-exchange__parties`) mřížkou
  karet nejsou.
- **Karta, nebo ne** (D35): rámeček + zaoblení md = karta v obsahu;
  zaoblení lg + stín = plovoucí plocha (`Modal`, přihlášení, `StatusScreen`,
  panel vzhledu, `Popover`, uživatelské menu) — karta stín nemá. Kartou
  nejsou ani vložené panely se šedým pozadím (`FormSection`, nabídka
  položek, souhrny v dialozích), hlášky a pruhy (chyba, varování, zámek
  dokladu — `Notice` / `PageBanner`, viz Hlášky a pruhy níže) ani
  volitelné řádky (tlačítko volby v průvodci, řádek seznamu).
  Hláška selhání analýzy (`FailureCard`) je karta s tónem — rámeček
  v barvě, bez levého pruhu.
- **Pravidlo:** nová karta = `Card` (v mřížce `CardGrid`), ne vlastní
  třída s kopií rámečku. Dnes na nich stojí Přehled firmy, feed dashboardu
  (karta feedu, AI shrnutí), čtyři karty Nastavení, strany dokladu
  v detailu i v náhledu importu, karta návrhu z pošty, karta selhání
  a náhled v průvodci importem ze Spisovny.

### Hlášky a pruhy

Rozhodnutí D40 a D41 (task 3 #137) z #133. Hláška v obsahu je sdílená
`Notice.svelte`, pruh nad obsahem `PageBanner.svelte` (obě `components/ui/`).

- **Hláška** (D40, `Notice`): tóny `info` / `success` / `warning` /
  `danger`, barvy jen z rodiny `--shpd-color-alert-*` — pozadí `-bg`, text
  `-text`, rámeček 1 px `-bar` v barvě tónu (`success` jsou aliasy na
  existující zelené, `danger` mapuje na `alert-error-*`); zaoblení
  `--shpd-radius-md`, odsazení `--shpd-space-sm` / `--shpd-space-md`, písmo
  body. Bez levého pruhu, bez stínu a **bez vnějšího okraje** — mezeru dává
  rodič (obal se třídou, nebo mezera flex sloupce). Volitelně nadpis
  `title` (strong, velikost body), ikona `icon` vlevo (dekorace) a seznam
  `items` — položka text, nebo `{label, text}` („popisek: text“, popisek
  strong: validace formuláře, zámek dokladu); jiný obsah dává `children`.
  Role `alert` jen u `danger` (čte se hned — jen chyba), jinak `status`,
  volající ji smí přepsat; `data-testid` a `aria-*` jdou na kořen, `class`
  jen pro stavové třídy, nikdy pro mezery.
- **Pruh nad obsahem** (D41, `PageBanner`): jiný prvek než hláška — celá
  šířka, odsazení xs / lg, pozadí a text `alert-<tón>-*`, linka 2 px dole
  `alert-<tón>-bar`, body strong, na střed; bez zaoblení a bez okraje.
  Tón `warning` výchozí, `info` / `danger` pro budoucí pruhy. Dnes: jen ke
  čtení, kopie reálných dat, cron vypnutý, pojistka pošty — texty a logika
  zůstávají v jejich komponentách, vzhled má jedno místo.
- **Hláška, pruh, karta, nebo toast**: hláška patří k místu, kde vznikla
  (formulář, dialog, tab detailu), a trvá, dokud trvá důvod; pruh je stav
  celého zdroje dat nad obsahem; karta s tónem (`FailureCard`, Karty výše)
  je obsah s vlastním nadpisem a akcemi; toast (`GlobalToast`) je pomíjivé
  potvrzení akce. Rámeček 1 px v barvě tónu má jen hláška — karta má
  rámeček `--shpd-color-border`, nebo tón jen v barvě rámečku.
- **Pravidlo:** nová hláška = `Notice`, nový pruh = `PageBanner`, ne
  vlastní třída s kopií pozadí a rámečku. Dnes na `Notice` stojí chyba
  a potvrzení přihlášení, chyby OP přihlášení a nastavení hesla, relace
  účtu, bannery formuláře (chyba načtení, validace, varování), chyba
  sub-tabulky, chyby reportů, hlášení náhledu tisku, zámek dokladu,
  pojistka pošty v dialogu Odeslat a blok `notice` v detailu (§12).
  Zbylé vlastní hlášky (zpráva stránky Nastavení a změny hesla, chyby
  nahrání pošty a nového zdroje dat, varování průvodce zdroje dat, zprávy
  Přehledu firmy, nálezy náhledu importu) jsou kandidáti na převod.

### Modální dialogy

Všechny modální dialogy v aplikaci používají sdílenou `Modal.svelte` komponentu —
včetně vnořených dialogů (modál nad modálem). Modal má vlastní stack management,
takže Esc reaguje jen na top modál a body scroll lock se uvolňuje až při zavření
posledního.

**Konvence**:
- Overlay `var(--shpd-color-overlay)` (40 % černá v light, 65 % v dark)
- Tlačítka v patce přes snippet `footer` + `<Button>` komponentu
- Title + volitelný `headerExtra` snippet pro badge/stav v hlavičce
- Width default 640px, lze předělat (`width="800px"`, `width="480px"` atd.)
- **Odsazení těla**: tělo má výchozí padding `--shpd-space-lg` (vodorovně
  lícuje s hlavičkou a patičkou) a krajním dětem ruší vnější margin. Obsah
  malého dialogu si padding **nenese sám** — zdvojil by se. Dialogy
  s vlastním rozvržením na celou plochu (`FormDialog`, `ViewerDetailModal`,
  review modal, průvodce importem z registru) ho vypínají propem `flush`
  a odsazení řeší po sekcích
- **Mobil (≤ 48rem, 768 px)**: každý modál je fullscreen (`100vw × 100dvh`,
  bez zaoblení/okrajů), footer tlačítka na plnou šířku, header summary
  skryt, depth-shrink vypnut (vnořený modál překryje rodiče). Pevné
  width/height props se přebijí. Čistě CSS `@media` v `Modal.svelte`
  s breakpointem shellu (D42, *Responzivita* níže) — tatáž hodnota jako
  `MOBILE_BREAKPOINT`, shodu hlídá kontrola v pre-commitu. Detaily v
  [`edit-forms.md`](edit-forms.md) sekce *Velikost modalu → Mobilní fullscreen*.

**Použití**:
```svelte
<Modal title="Zamítnout dokument" {open} onClose={close} width="480px">
  <p>Obsah dialogu</p>
  {#snippet footer()}
    <Button label="Zrušit" variant="secondary" onclick={close} />
    <Button label="Zamítnout" variant="danger" onclick={submit} />
  {/snippet}
</Modal>
```

### Responzivita

Rozhodnutí D42, D43 a D47 z #133 (#138 task 1):

**Media query patří jen shellu aplikace** — tomu, co se řídí oknem:
drawer sidebaru, modal přes celou obrazovku, přepínání seznam / detail
ve vieweru, taby PDF / údaje v review modalu a prvky vázané na výšku okna
(`vh`). Shell má jedinou hodnotu, stejnou v CSS i v JS
(`MOBILE_BREAKPOINT` v `stores/layout.svelte.js` je řetězec s jednotkou,
`matchMedia` ji dosazuje přímo). Vývojářský dashboard má vlastní shell
s vlastní hodnotou.

**Obsah reaguje na šířku svého kontejneru**, ne okna — detail, formulář,
stránka nastavení nebo dialog bývají v panelu vedle seznamu, v draweru
i v modalu a šířka okna o jejich šířce nic neříká (D3). Takové bloky
používají `@container`, ne `@media`.

| Hodnota | Kde | K čemu |
|---|---|---|
| `48rem` (768 px) | `@media` v aplikaci | shell: drawer, fullscreen modal, seznam / detail, taby review modalu, výšky ve `vh` |
| `68.75rem` (1100 px) | `@media` ve `frontend/src/dev/` | shell vývojářského dashboardu (split dvou sloupců) |
| `30rem` (480 px) | `@container` | úzký obsah: sloupce pod sebe, popisky nad pole, strany dokladu pod sebe, patička dialogu zalomená |
| `36rem` (576 px) | `@container` | složené řádky tabulky dokladu (#139) |

Px v závorce platí při výchozím písmu prohlížeče 16 px.

**Proč `rem` v `@media`.** V media query se `rem` nevztahuje k `font-size`
kořene stránky, ale k výchozímu písmu prohlížeče. Aplikace kořenové písmo
nenastavuje (§10), takže při 16 px je `48rem` přesně 768 px a nic se
nemění; kdo si písmo zvětší na 20 px, dostane mobilní rozložení už při
960 px okna — text i rozvržení se škálují spolu. `matchMedia` v JS počítá
stejně jako CSS, proto se drawer a CSS shellu přepnou současně. Kořenové
písmo se **nenastavuje** i kvůli tomu.

**Jak převést obsah na kontejner.** Kořen bloku dostane
`container-type: inline-size` a dnešní `@media (max-width: …)` se změní na
`@container (max-width: 30rem)`:

- Kontejner je obal **na celou šířku rodiče**, mřížka nebo pole až uvnitř:
  dotaz platí pro potomky kontejneru, ne pro něj sám. `container-type`
  nepatří na prvek, který se smršťuje podle obsahu (flex položka bez
  `flex: 1`, inline prvek) — inline-size containment mu dá nulovou šířku.
- Nepojmenovaný `@container` se ptá nejbližšího předka s `container-type`.
  Když se dva prvky musí přepnout ve stejný okamžik, pojmenuj kontejner
  a ptej se ho jménem — vzor `form-section`: sekce formuláře
  (`FormSection`) má `container-name: form-section` a náhled příloh uvnitř
  jejího sloupce (`FormAttachmentsView`) píše
  `@container form-section (max-width: 30rem)`, takže se přepne spolu se
  sloupci, ne podle nejbližšího kontejneru.
- Obsah vykreslený snippetem `Modal` (patička přes `footer`) je mimo kořen
  komponenty — kořenový kontejner na něj nedosáhne. Takový kus dostane
  vlastní obal na celou šířku snippetu (`PrintPreviewDialog`:
  `.shpd-print__footer-box` s `flex: 1; min-width: 0`), řádek až uvnitř.
- Kontejner nemění chování absolutně a fixně polohovaných vrstev uvnitř
  (lookup, select, `Popover`) — `container-type` zavádí inline-size
  a style containment, ne layout containment; ověřeno v Chromiu (lookup
  dropdown ve `FormSection` sedí pod polem a není ořezaný).
- Vzory: `PropertyGroups` (kontejner na obalu mřížky), `DocumentDetail`
  (strany dokladu), `SettingsPage`, `SendDialog`, `FormSection`.

**Nová hodnota = nové rozhodnutí v #133.** Kontrola v pre-commitu
(`scripts/check-css-units.py`, §10) odmítne `@media` s rysem šířky mimo
hodnotu shellu, `@container` mimo prahy výše i JS konstantu, která
s CSS neladí; značka `px-ok` na breakpointy neplatí. Pevné šířky, výšky
a layout tokeny v px řeší #138 task 4; obaly tabulek s posunem do strany
a složené řádky #138 task 3 a #139.

---

## 9. Vzhledy (themes)

Aplikace podporuje tři režimy vzhledu:

- **Shipard** (interně `light`) — default; light tokeny v `:root`,
  žádný `data-theme` atribut, žádné inline tokeny
- **Tmavý** (`dark`) — atribut `data-theme="dark"` na `<html>`,
  v `variables.css` jsou pod selektorem `[data-theme="dark"]`
  předefinované všechny barevné tokeny
- **Vlastní** (`custom`) — uživatel vybere barvu sidebaru (preset paleta
  nebo color picker) + světlou/tmavou bázi těla; sidebar tokeny se
  přepíšou inline custom properties na `<html>` za běhu

Dřívější režim Auto (sledování `prefers-color-scheme`) zanikl — uložená
hodnota `'auto'` se migruje na `'light'` (resp. na follow, viz níže).

### Dvě úrovně + efektivní vzhled (Fáze 4)

Od Fáze 4 je vzhled **nastavení, ne rychlý přepínač** — dropdown vzhledu
v patce sidebaru zanikl, vše se řeší v **Nastavení účtu → Základní**
(otevírá panel přes `ThemeField`) a v **Nastavení aplikace → Aplikace**
(DS-wide default).

Efektivní vzhled se počítá ze dvou serverových hodnot:

```
efektivní vzhled = (uživatel má vlastní vzhled) ? user override
                                                 : (DS default ?? Shipard)
```

- **DS default** — `app.theme` v `core_system_settings` (scope `ds`),
  nastaví ho kdokoli s přístupem do Nastavení aplikace. Platí pro všechny
  uživatele, kteří nemají vlastní. Na klienta jde přes `appInfo` store
  (vedle brandingu) → `themeStore.setDsDefault()`.
- **User override / follow** — `account.theme` v `core_system_user_settings`
  (scope `user`) nese `follow` flag: `{follow:true}` = sleduj DS default
  (včetně jeho budoucích změn), `{follow:false, mode, custom}` = vlastní
  override. Nový uživatel i absence hodnoty = follow. **Legacy
  `account.theme` bez follow se interpretuje jako override.**

Vstup uživatele do „mám vlastní" řeší přepínač **„Vlastní vzhled"** na
stránce Základní: vypnuto = follow (výběr skrytý + poznámka „Řídí se
nastavením aplikace" + mini náhled DS defaultu), zapnuto = override
(první zapnutí předvyplní zděděnou DS hodnotou — „začni od toho, co
vidíš").

`localStorage` zůstává **anti-flash cache** (server je zdroj pravdy):
`shpd_theme` = `'follow'`|`'light'`|`'dark'`|`'custom'`, override cache
`shpd_theme_custom`/`shpd_theme_tokens`, a DS default cache
`shpd_ds_theme`/`shpd_ds_theme_tokens` (aby ani follow-uživatel neviděl
flash). V dev módu per-DS prefix. Po loginu `accountPrefs.load()` +
`appInfo.load()` sesynchronizují server → store + cache.

Implementace store + bootstrap + panel + server sync je
v [`frontend.md`](frontend.md) (sekce *Theme management*); per-user
úložiště, DS default pole a account mód v
[`app-settings.md`](app-settings.md) (sekce 8).

### Princip custom tématu: barví se jen sidebar

Tělo stránky se **nebarví nikdy** — na bílých plochách žije doc-state
systém (barevné pruhy, badge, selection) a tónování obsahu by rozbilo
jeho čitelnost. Obsah stránky (tlačítka, odkazy, doc-states, selection)
drží brand barvy beze změny ve všech tématech.

Jediná výjimka v sidebaru: aktivní položka používá token
`--shpd-color-sidebar-active-bg` — v built-in tématech ukazuje na
primary modrou (vzhled beze změny), v custom tématu ho store přepíše
na neutrální white/black-alpha, aby fungoval na libovolné barvě.
**Oranžový proužek aktivní položky zůstává ve všech tématech** — pokud
by v praxi kolidoval s červenými presety (terakota, vínová), zruší se
plošně ve všech tématech kvůli konzistenci, ne per-téma.

### Odvozované tokeny custom tématu

Custom konfigurace = solid barva nebo gradient dvou stopů + báze
(light/dark) + opacity (0–100). `deriveSidebarTokens(sidebar, base,
opacity)` (`frontend/src/utils/themeColor.js`, OKLab/OKLCH matematika
bez závislostí) odvodí tokeny z **efektivní barvy**:

1. Stopy se podle opacity mixují v OKLab směrem k pozadí báze aplikace
   (`BASE_BG` v `themeColor.js` zrcadlí `--shpd-color-bg` ve
   `variables.css` — bílá pro light, `#232730` pro dark; sync komentáře
   na obou místech). OKLab = lineární interpolace bez hue wraparound
   problémů.
2. Efektivní barva: u solid barva po mixu, u gradientu OKLab střed
   (aritmetický průměr složek) obou stopů po mixu.
3. Elevated plocha a větvení světlý/tmavý text se počítají z efektivní
   barvy — jeden pipeline pro solid i gradient.

| Token | Hodnota |
|---|---|
| `--shpd-color-bg-sidebar` | `oklch()` barvy po opacity mixu (u gradientu **horní stop** — fallback a plochy bez gradientu jako mobile topbar navazují na začátek přechodu) |
| `--shpd-sidebar-bg-image` | **jen gradient**: `linear-gradient(180deg, stop1, stop2)` — vertikální |
| `--shpd-color-bg-sidebar-elevated` | `oklch(min(L+0.06, 0.98) C H)` z efektivní barvy — dropdown nad sidebarem |
| `--shpd-color-text-sidebar` | `rgb(255 255 255 / 0.92)` tmavý sidebar / `rgb(15 23 42 / 0.88)` světlý |
| `--shpd-color-text-sidebar-muted` | `… / 0.58` / `… / 0.56` |
| `--shpd-color-bg-sidebar-hover` | `rgb(255 255 255 / 0.08)` / `rgb(0 0 0 / 0.06)` |
| `--shpd-color-bg-sidebar-border` | `rgb(255 255 255 / 0.10)` / `rgb(0 0 0 / 0.10)` |
| `--shpd-color-sidebar-active-bg` | `rgb(255 255 255 / 0.16)` / `rgb(0 0 0 / 0.10)` |
| `--shpd-color-sidebar-active-bg-hover` | `rgb(255 255 255 / 0.22)` / `rgb(0 0 0 / 0.15)` |

Větvení světlý/tmavý sidebar: `L >= 0.65` (efektivní barvy) → světlý
sidebar, tmavý text. Světlé barvy i gradienty tak dostanou čitelný
text automaticky; opacity mix ke světlé bázi překlopí text na tmavý,
jakmile barva dostatečně zbledne.

`--shpd-sidebar-bg-image` **není deklarovaný ve `variables.css`** —
existuje jen jako inline property z theme storu. Fallback pattern
`var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar))`
v `Sidebar.svelte` funguje jen pro neexistující property; deklarovaná
prázdná hodnota by fallback rozbila.

### Preset paleta — plné barvy

`frontend/src/components/layout/themePresets.js` (`THEME_PRESETS`) —
12 kurátorovaných barev (10 tmavých + 2 světlé). Tmavé tóny drží
lightness pásmo brand modré (~L 0.30 v OKLCH), aby bílý text měl všude
srovnatelný kontrast:

| Preset | Hex | | Preset | Hex |
|---|---|---|---|---|
| Ocelová modř | `#2E6494` | | Tlumená magenta | `#8C2F5D` |
| Petrolejová | `#0E4F5C` | | Švestková | `#4A2C66` |
| Smaragdová | `#115E4B` | | Indigo | `#34307D` |
| Lahvová zelená | `#2F5D3A` | | Grafit | `#2F343D` |
| Terakota | `#9A3B26` | | Písková (světlá) | `#E3D5B8` |
| Vínová | `#6D1F2C` | | Mlhavá modř (světlá) | `#DBE4EE` |

### Preset paleta — gradienty

`THEME_GRADIENT_PRESETS` — 12 vertikálních přechodů (180deg, shora
dolů; horizontální zamítnut — úzký vysoký sidebar dává přechodu
prostor vertikálně). Dvojice stopů drží podobné lightness pásmo
(**ΔL ≤ ~0.08** v OKLCH): přechody jsou posuny odstínu, ne světlosti,
aby text a alpha zvýraznění fungovaly po celé výšce sidebaru. Stopy
z velké části recyklují solid paletu:

| Preset | Stopy | | Preset | Stopy |
|---|---|---|---|---|
| Oceán | `#00345C → #0E4F5C` | | Ostružina | `#6D1F2C → #4A2C66` |
| Louka | `#6E6320 → #2F5D3A` | | Západ slunce | `#9A3B26 → #6D1F2C` |
| Mech | `#115E4B → #46702F` | | Žár | `#9A3B26 → #8C2F5D` |
| Polární záře | `#2E6494 → #115E4B` | | Bouřka | `#2F343D → #34307D` |
| Půlnoc | `#34307D → #00345C` | | Svítání (světlý) | `#DBE4EE → #E3D5B8` |
| Vřes | `#4A2C66 → #8C2F5D` | | Pivoňka (světlý) | `#EAD9E2 → #DBE4EE` |

### Designové principy dark módu

- **Pozadí je tmavá šedá, ne čistá černá** (`#232730` pro obsah,
  `#1a1d23` pro "podloží"). Černé pozadí je příliš ostré a unavuje oči.
- **Sidebar je SVĚTLEJŠÍ než obsah** (`#2a2e38` vs `#232730`) — obsah
  dominuje, sidebar ustupuje. Naopak než v light módu, kde je sidebar
  tmavší než obsah.
- **Brand modrá je posunutá ztlumeným směrem** (z `#005089` na `#2d6890`)
  — aby fungovala na tmavém pozadí jako akcent, ale nebila do očí
  na aktivní položce sidebaru.
- **Brand oranžová zůstává podobná** (jen lehce světlejší `#ff7a26`) —
  funguje na obou módech.
- **Doc-state pruhy zůstávají stejné** — jasné barvy (žlutá, fialová,
  červená) fungujou na tmavém pozadí bez úpravy.
- **Doc-state badge mají invertovanou logiku** — bg je tmavá saturovaná
  varianta barvy, text je světlý. Např. `concept` v light má
  bg `#fef3c7` / text `#854d0e`, v dark bg `#3d3214` / text `#fde047`.

### bg-secondary vs bg-hover

V light módu jsou tyto dva tokeny shodou okolností stejné (`#f6f8fa`).
V dark se rozcházejí:

- `--shpd-color-bg-secondary` (`#1a1d23`) — "podloží" pod prohlížečem,
  TabBar, header okolí. **Tmavší než bg.**
- `--shpd-color-bg-hover` (`#2d3340`) — hover stavy nad obsahem.
  **Světlejší než bg.**

Když implementuješ hover, používej `bg-hover` (rozjasní). Když implementuješ
 trvalou sekundární plochu, používej `bg-secondary` (utlumí).

### Anti-flash bootstrap

Téma se aplikuje před prvním renderem inline `<script>` v `index.html`
— jinak by uživatel s uloženou `dark`/`custom`/`follow` volbou viděl
flash defaultního vzhledu před načtením Svelte. Bootstrap čte
`shpd_theme`: pro `custom` aplikuje předpočítané override tokeny z cache
`shpd_theme_tokens`, pro `follow` (sleduj DS default) tokeny z DS cache
`shpd_ds_theme_tokens` — žádná OKLCH matematika před renderem. Bootstrap
čte stejné `localStorage` klíče jako store; detaily a **čtyři
synchronizovaná místa** v [`frontend.md`](frontend.md)
(sekce *Theme management*).

---

## 10. Jednotky a mezery

Pravidlo D1 z #133: **mezery, odsazení, zaoblení a velikost písma se píší
v rem** — tokenem, nebo rem literálem; **px zůstávají jen okrajům, obrysům,
stínům a stavovému proužku** (linka má být 1 px bez ohledu na písmo).
Breakpointy řeší §8 *Responzivita* (`@media` jen shell v `rem`, obsah
přes `@container`, #138 task 1); pevné šířky a výšky a layout tokeny
(`--shpd-sidebar-width`, `--shpd-header-height`, šířky pásů shellu) řeší
#138 task 4 — do té doby zůstávají v px (D11).

### Proč rem a proč se kořenové písmo nenastavuje

`rem` se odvíjí od velikosti písma kořene `<html>`. Aplikace ji **nenastavuje**
— respektuje volbu uživatele v prohlížeči (výchozí 16 px). Kdo si písmo
zvětší, dostane úměrně větší i mezery a zaoblení; s px by text narostl, ale
rozvržení zůstalo těsné. Převod základů (#134, D9) byl vizuálně neutrální:
hodnoty se přepočetly 1 : 1 (px / 16), při 16 px vypadá aplikace stejně jako
před ním. Vizuální změny (typografické role, základní text detailu 14 px)
přinesl pilot #135 — §11 Typografie a §12 Detail záznamu.

### Tokeny mezer, zaoblení a písma

Hodnoty tokenů jsou v rem; sloupec px je ekvivalent při 16 px.

| Token | Hodnota | px | Použití |
|---|---|---|---|
| `--shpd-space-xs` | `0.25rem` | 4 | mezera ikona ↔ text, drobné odsazení |
| `--shpd-space-sm` | `0.5rem` | 8 | vodorovné odsazení inputů a tlačítek |
| `--shpd-space-md` | `1rem` | 16 | mezera mezi bloky, odsazení obsahu |
| `--shpd-space-lg` | `1.5rem` | 24 | tělo dialogu, strany sekce formuláře |
| `--shpd-space-xl` | `2rem` | 32 | velké odsazení stránky |
| `--shpd-input-padding-y` | `0.25rem` | 4 | svislé odsazení inputů |
| `--shpd-form-section-gap` | `0.5rem` | 8 | mezera mezi sekcemi formuláře |
| `--shpd-form-section-padding-y` | `0.5rem` | 8 | svislé odsazení sekce formuláře |
| `--shpd-radius-sm` | `0.25rem` | 4 | inputy, štítky, drobné prvky |
| `--shpd-radius-md` | `0.375rem` | 6 | tlačítka, karty |
| `--shpd-radius-lg` | `0.5rem` | 8 | dialogy, panely |
| `--shpd-radius-full` | `9999px` | — | pilulka: čipy, štítky stavů |
| `--shpd-font-size-xs` … `-xl` | `0.75` / `0.875` / `1` / `1.125` / `1.25rem` | 12 / 14 / 16 / 18 / 20 | škála velikostí písma |
| `--shpd-font-size-title` / `-heading` / `-body` / `-meta` | `lg` / `sm` / `sm` / `xs` | 18 / 14 / 14 / 12 | typografické role (§11) |
| `--shpd-font-weight-normal` / `-strong` | `400` / `600` | — | dvě tloušťky písma (§11) |

`--shpd-radius-full` je jediná px hodnota zaoblení: znamená „maximum“, ne
velikost (prohlížeč ji sám ořízne na polovinu kratší strany), v rem by
nedávala smysl. Kruh je `border-radius: 50%`. Stíny `--shpd-shadow-*`
zůstávají v px.

### Jak psát hodnoty

- Hodnota rovná tokenu → token: `gap: var(--shpd-space-xs)`,
  `border-radius: var(--shpd-radius-md)`, `font-size: var(--shpd-font-size-sm)`.
- Jiná hodnota → rem jako px / 16, bez zaokrouhlování k tokenu:
  `padding: 0.125rem 0.625rem` (2 a 10 px), `margin-bottom: -0.0625rem`.
  Zkratky po složkách (`padding: 0.125rem var(--shpd-space-sm)`), totéž
  uvnitř `calc()`. Nula bez jednotky (`0`, ne `0px`).
- Nové tokeny mezer (2 px, 12 px…) se nezavádějí mimochodem — pilot #135
  žádný nezavedl (odsazení karty strany a hlavičky detailu `0.75rem`
  zůstává rem literál); hodnota mimo škálu = rem literál.
- **`em`** jen tam, kde se prvek řídí okolním textem, ne stránkou: ikona
  vedle textu (`width: 1em`), vnitřní odsazení štítku, který má růst se
  svým písmem. Mezery mezi bloky a odsazení sekcí nikdy v em — násobí se
  s každým vnořením.
- **V px zůstává:** `border`, `outline`, `box-shadow`, stavový proužek
  (`::before` 3 px / 6 px), `width` / `height` / `min-*` / `max-*`
  (do #138 task 4), polohování (`top`, `left`, `inset`), `letter-spacing`,
  `transform`; `line-height` bez jednotky. Breakpointy `@media`
  a `@container` jsou v `rem` (§8 Responzivita).

### Kontrola v pre-commitu

`scripts/check-css-units.py` (#134 D10) projde celý `frontend/src` včetně
vývojářského dashboardu (D12) — soubory `.css` a `.svelte`: blok `<style>`,
atribut `style=""` i direktiva `style:vlastnost=` — a zastaví commit, když má
`padding*`, `margin*`, `gap`, `row-gap`, `column-gap`, `font-size` nebo
`border-radius*` nenulovou hodnotu v px, i uvnitř `calc()` nebo fallbacku
`var()`. Custom properties (definice tokenů), komentáře a JavaScript
nehlídá. Výjimka jen značkou s důvodem na stejném řádku:

```css
padding: 1px; /* px-ok: optické zarovnání ikony na základní čáru */
```

Značka bez důvodu je chyba. Každou výjimku uveď v popisu commitu; po
převodu základů je výjimek nula. Ruční spuštění:
`python3 scripts/check-css-units.py` (exit 1, výpis `soubor:řádek:
deklarace` na stderr). Styly vkládané z JavaScriptu kontrola nevidí —
jediný takový případ je základní CSS iframe e-mailu v `SandboxedHtml.svelte`,
což je vlastní dokument, kde px nevadí.

Tentýž skript hlídá **breakpointy** (D47): `@media` s rysem šířky
(`width`, `min-width`, `max-width`, i rozsahový zápis `width <= …`) smí mít
jen hodnotu shellu — `48rem` v aplikaci, `68.75rem` ve `frontend/src/dev/`;
rysy bez šířky (`hover`, `pointer`, `prefers-*`, `print`) projdou.
`@container` smí mít jen `30rem` a `36rem`. `MOBILE_BREAKPOINT`
v `stores/layout.svelte.js` musí být řetězec rovný hodnotě shellu, bez
jednotky za dosazením do `matchMedia`. Povolené hodnoty jsou konstanty
nahoře ve skriptu; značka `px-ok` tu neplatí — jiná hodnota je nové
rozhodnutí v #133 (§8 Responzivita).

---

## 11. Typografie

Pravidla D6, D15 a D16 z #133, zavedená pilotem #135 (detail dokladu).
**Čtyři velikosti, dvě tloušťky, žádné verzálky.** Role jsou tokeny ve
`variables.css` a odkazují na škálu `--shpd-font-size-*`; žádná nová
velikost nevzniká. Základní text je **14 px** (`sm`): D6 původně mířilo na
16 px, při ověření pilotu se ukázalo přestřelené a 14 px se drží všude
dál — proto se role mění na jednom místě. Nadpis (heading) je od #137
task 2b **16 px** (`base`): do té doby měl velikost body a lišil se jen
tloušťkou, při ověření v prohlížeči byl název karty feedu a mezinadpis
v kartě Nastavení moc malý, a rozdělení proběhlo tím jedním místem.

| Role | Token | Velikost | Tloušťka | Barva | Kde |
|---|---|---|---|---|---|
| title | `--shpd-font-size-title` | `lg` (1.125rem) | strong | text | titulek detailu, řádek Celkem, nadpis karty jako samostatné části stránky (Přehled firmy, karty Nastavení — D36), hlavní částka karty (Přehled firmy, feed) |
| heading | `--shpd-font-size-heading` | `base` (1rem) | strong | text | nadpis sekce (detail i feed dashboardu), nadpis karty uvnitř obsahu, název entity v kartě, název karty feedu, mezinadpis v kartě Nastavení |
| body | `--shpd-font-size-body` | `sm` (0.875rem) | normal (hlavička sloupce strong) | text | popisky i hodnoty, řádky tabulek i hlavička sloupce (ve velikosti řádků, silnější), taby |
| meta | `--shpd-font-size-meta` | `xs` (0.75rem) | normal | text; šedá (`--shpd-color-text-secondary`) jen u kontaktů a doplňků | podtitul, štítky, kontakty, přepočet v domácí měně |

Tloušťky: `--shpd-font-weight-normal` (400) a `--shpd-font-weight-strong`
(600). `heading` a `body` byly původně stejně velké a lišily se jen
tloušťkou — dvě role právě proto, aby šly rozdělit jedním místem, což se
v #137 task 2b stalo. Hlavička sloupce tabulky je body + strong, ne
heading: má být ve velikosti řádků.

### Pravidla

- **Popisek a hodnota stejnou barvou i tloušťkou** — liší se umístěním
  (popisek vlevo od hodnoty nebo nad ní), ne vzhledem. Údaje se tučně
  nezvýrazňují: ani splatnost, ani IČO, ani částky mimo součty (D15).
- **Jedna tučná úroveň.** Tučné je jen to, co má roli nadpisu: nadpis
  sekce, název entity v kartě strany (D16), hlavička sloupce, titulek,
  hodnoty součtů. Vždy přes `--shpd-font-weight-strong` — i u `<strong>`,
  jehož výchozích 700 by bylo čtvrtou tloušťkou.
- **Verzálky jen ve větších nadpisech** (`text-transform: uppercase`
  s `letter-spacing`); v detailu záznamu žádné. Popisek verzálkami
  nezmenšuj — menší text verzálkami se čte hůř, ne lépe.
- **Šedá je pro doplňky**, ne pro popisky: kontakty, přepočet v domácí
  měně, datum u odeslané pošty. Adresa, identifikátory a účet jsou běžné
  údaje barvou textu.
- **Nové komponenty používají role**, ne literály ani škálu, tam kde role
  sedí; škála zůstává prvkům mimo role (ikony podle textu v `em`, lišta
  akcí a tlačítka). Mimo detail (seznamy, formuláře, dashboard) se role
  zavádějí postupně — Přehled firmy je má (#137 task 2: popisek body,
  částka title jako hlavní číslo karty, doplňky a štítky meta, splatnost
  jen barvou; task 2b: nadpis karty title s ikonou) a dashboard taky
  (#137 task 2b, D37: název karty feedu heading, částka title strong,
  hodnota rozbaleného detailu body normal, nadpis sekce feedu heading
  přes `SectionHeading` — verzálky a šedá nadpisu sekce zanikly, §11
  verzálky jen ve větších nadpisech). `check-css-units.py` hlídá jen px
  — role nehlídá nic než tahle kapitola.

---

## 12. Detail záznamu

Detail záznamu (pravý panel vieweru, drawer na mobilu, read-only modal
`ViewerDetailModal`) kreslí sdílený `ViewerDetail.svelte`; obsah typu
`document` uvnitř kreslí `DocumentDetail.svelte`, který je **referenční
implementací** této kapitoly. Datový kontrakt (hlavička, taby, typy
obsahu) popisuje `docs/frontend.md`; tady je vzhled.

### Pořadí shora dolů

1. **Lišta akcí** (`detail.actions`) — nad hlavičkou, beze změny vůči
   zbytku aplikace (`Button size="sm"`, škála `sm`).
2. **Hlavička**: ikona typu záznamu 40×40 vlevo, titulek (title),
   podtitul (meta, barvou textu), štítky (meta, normální tloušťka,
   `padding: 0.125rem 0.625rem`, pilulka). Odsazení
   `0.75rem var(--shpd-space-md)`, mezera ikona–text `0.75rem`.
   - **Hlavička neopakuje kontext** (D13): štítek typu záznamu do ní
     nepatří, typ nese ikona a název vieweru. Štítky jen pro stav a pro
     vlastnosti, které nejsou zřejmé z kontextu.
   - **Titulek je to, podle čeho uživatel záznam pozná** (D14): u dokladu
     protistrana, číslo a text dokladu v podtitulku
     (`FP-2026-0142 · Kancelářské potřeby`); doklad bez partnera má
     titulkem číslo. Titulek jde i do horní lišty na mobilu.
   - **Výchozí titulek a podtitul** (#136 D19) dává builder z deklarace
     `detailHeader` vieweru (`{name}` / `{asset_number}`), bez ní
     z `displayPattern` tabulky; název je titulek, kód nebo číslo jde do
     podtitulku. Prázdná hodnota se vynechá i s oddělovačem.
   - **Štítek stavu** přidává builder sám ze sady stavů; „V pořádku“ se
     v hlavičce neukazuje (`detailBadge: false`, D20) — K řešení, V práci,
     Hotovo, Odeslaná, Podáno zůstávají. Další štítky (Chyba účtování,
     Po termínu) až za ním.
   - **Přehled neopakuje hlavičku** (#140 D26): co je v hlavičce — titulek,
     údaje podtitulku, štítky — se v prvním tabu znovu nevypisuje; skupina
     vlastností, která tím zůstane prázdná, se nezobrazí (`propertiesTab()`
     ji filtruje, ručně skládané skupiny si hlídá viewer). Zkrácený název
     (účty, saldokonta, karty a typy majetku) se ukáže jen, když se liší od
     názvu. Náhradní titulek (pohyb saldokonta bez partnera má titulkem
     text, uživatel bez jména login) chybí v Přehledu jen v tom případě —
     viewer rozhoduje z téhož záznamu jednou privátní metodou
     (`LedgerViewer::titleOf()`, `BankTransactionsViewer::partyOf()`,
     `BankStatementsViewer::reconciliationBadge()`). Karta strany dokladu
     s názvem partnera je součást návrhu (D16), ne opakování. Shoda hodnot,
     která opakováním není (druh položky pojmenovaný jako typ, součty
     případu s jediným pohybem, pořadí bankovního účtu rovné kódu,
     systémový kód jednotky, splatnost zápisu rovná datu), zůstává. Hlídá to
     `ViewerDetailContractTest::testOverviewDoesNotRepeatHeader` nad pěti
     nejnovějšími záznamy každého vieweru: chyba je jen soustavná shoda,
     výjimky `OVERVIEW_REPEAT_ALLOWED` s komentářem proč.
3. **Taby** — body, aktivní strong s modrou linkou.
4. **Obsah** — sekce oddělené `--shpd-space-lg`, každá s nadpisem
   (`SectionHeading`: heading, bez linky, `margin-bottom:
   var(--shpd-space-xs)`; mezeru mezi sekcemi dává rodič, D28).

### Stavební prvky obsahu

- **Nadpis sekce** (D28, `components/ui/SectionHeading.svelte`): jeden
  nadpis pro skupinu vlastností, blok `heading`, kartu strany, přílohy
  i Odeslanou poštu — heading, barvou textu, bez linky, `margin: 0 0
  var(--shpd-space-xs)` a žádné horní odsazení: sekce od sebe odděluje
  mezera `--shpd-space-lg`, kterou dává rodič (obal bloků `composite`,
  mřížka skupin), ne nadpis. `level` 2–4 (karty Přehledu firmy h2 pod
  h1 stránky, skupiny vlastností v obecném detailu h4 jako dřív);
  volitelný snippet `aside` sedí
  vpravo ve stejném řádku (přepínač náhledů příloh). Blok `heading`
  v `composite` je nadpis bloku pod ním — ten mezeru nad sebou nemá.
  Propy `role="title"`, `icon` a `spacing` jsou pro nadpis karty jako
  samostatné části stránky (§8 Karty, D36, D38) — v detailu záznamu je
  nadpis sekce vždy heading bez ikony.
- **Karta** (strany dokladu): sdílená `Card` (§8 Karty, D33) s nadpisem
  `title` — rámeček `--shpd-color-border`, zaoblení `--shpd-radius-md`,
  odsazení `--shpd-space-md`; položky pod sebou s mezerou `--shpd-space-xs`,
  název entity heading (odkaz na záznam modře), identifikátory, adresa
  a účet body barvou textu, kontakty meta šedě s malou mezerou nad (D16).
- **Popisek–hodnota** (D29, `PropertyList.svelte` + `PropertyGroups.svelte`
  v `components/ui/`): jedna sdílená komponenta pro obecný detail i detail
  dokladu. `<dl>` jedné skupiny je mřížka `grid-template-columns:
  max-content 1fr`, mezera `--shpd-space-xs` svisle / `--shpd-space-md`
  vodorovně, oba sloupce body barvou textu (D15), hodnota se zalamuje
  (`overflow-wrap: anywhere` — IBAN, e-mail); prázdná hodnota se nevykreslí
  (`0` prázdná není), prázdná skupina celá; položka s prázdným popiskem
  (`label: ''`, text poznámky) má hodnotu přes oba sloupce. Skupiny vedle
  sebe ve dvou sloupcích (`repeat(2, minmax(0, 1fr))`, mezera
  `--shpd-space-md` / `--shpd-space-lg`) po řádcích v pořadí od backendu —
  frontend je nepřehazuje a nepoužívá CSS `columns`; výšku řádku určuje
  vyšší skupina. Skupina s `wide: true` (poznámka, popis, zpráva — volný
  text, označuje viewer) jde přes celou šířku jako tabulky a ostatní bloky.
  Výběr vykreslitelných skupin a položek je čistá funkce `visibleGroups()`
  v `components/ui/propertyGroups.js`.
  - **Řazení skupin** (D31): dvojice v řádku patří věcně k sobě a jsou
    podobně dlouhé. Skupina, která u části záznamů mizí (Odpisy jen
    u odpisovaného majetku), stojí na konci, nebo její položky patří do
    stálé skupiny (Zkrácený název je první položka Zařazení karty
    majetku) — jinak se dvojice u různých záznamů posouvají. Hlídá review,
    kontrola na to není.
  - **Sama v řádku** (D32): skupina, která by v řádku zůstala sama
    (poslední při lichém počtu, nebo skupina před `wide`), jde přes celou
    šířku. Příznak `full` dopočítá `visibleGroups()` kurzorem sloupce nad
    viditelnými skupinami (`wide` kurzor nuluje), ne CSS sudá / lichá —
    komponenta ho jen kreslí.
- **Tabulka**: body; hlavička sloupce body + strong (stejná velikost jako
  řádky, silnější, barvou textu, bez verzálek — ne heading, ten je od
  #137 task 2b větší), čára pod ní
  `--shpd-color-border-strong`, mezi řádky `--shpd-color-border`; buňky
  `padding: 0.375rem var(--shpd-space-xs)`, čísla vpravo
  s `tabular-nums`. Štítek stavu v buňce = meta.
- **Součty**: vpravo pod tabulkou, hodnoty strong (token), řádek Celkem
  title s čarou nad; přepočet v domácí měně meta šedě. Rekapitulace DPH
  vlevo se nezvýrazňuje.
- **Přílohy**: na konci Přehledu (D8, #136 D21), sdílená
  `DetailAttachments.svelte` nad `detail.attachments.groups` u každého
  vieweru: skupiny s nadpisem `SectionHeading` (kód zprávy jako odkaz,
  datum normální tloušťkou; vlastní přílohy záznamu s nadpisem „Přílohy“);
  přepínač Miniatury / Velké náhledy ve snippetu `aside` nadpisu první
  skupiny vpravo, velikost meta. Dlaždice kreslí `AttachmentGrid.svelte`. Spisovna
  má přílohy dokumentu na konci prvního tabu Obsah toutéž sekcí (#140 D27).
  Výjimka: viewer s `detailAttachments: "tab"` — dnes jen karta majetku
  (fotky, smlouvy) — má přílohy ve vlastním tabu Přílohy, který bez přílohy
  chybí.
- **Odeslaná pošta**: jen na konci Přehledu, za přílohami (D22) — pod
  ostatními taby se nekreslí.
- **Hláška** (D30, D40): blok `notice` `{tone, title?, text? | items?}`
  kreslí sdílená `Notice` (§8 Hlášky a pruhy) — stav zaúčtování
  s hlášeními enginu (danger u chyby, warning u varování).
- **Štítky v obsahu**: blok `badges` `{items: [{label, style}]}` je řada
  `Badge` (§5) s mezerou `--shpd-space-sm`, styly jako štítky hlavičky —
  stav zaúčtování (warning / success / danger).
- **Prázdný stav**: blok `empty` `{text}` — odstavec body
  `--shpd-color-text-secondary`; text z katalogu popisků vieweru
  (`*.viewerDetailLabels` sekce `texts`, `TableViewer::detailText()`),
  cs i en, ne natvrdo v PHP.
- **Prostý text**: blok `text` `{text}` — odstavec body, `white-space:
  pre-wrap` a `overflow-wrap: anywhere` (tělo pošty bez HTML, AI shrnutí
  Spisovny).
- **Kód**: blok `code` `{text}` — `<pre>` v `--shpd-font-family-mono`,
  velikost meta, pozadí `--shpd-color-bg-secondary`, zaoblení md, odsazení
  sm, dlouhý řádek roluje v bloku (JSON kontextu upozornění).
- **Backend HTML se vzhledem neposílá** (D30): typ `html` v kontraktu
  detailu není; všechny typy nesou čistý text a frontend ho vkládá jako
  text, nikdy `{@html}`. Jediné HTML je `untrusted-html` — tělo e-mailu
  v sandboxu. Hlídá `DetailContent::TYPES` (unit `DetailContractTest`:
  žádný PHP zdroj blok `html` neskládá; integrační
  `ViewerDetailContractTest`: obsah tabů prvního záznamu každého vieweru
  jen ze známých typů).
- **Obecný detail i doklad kreslí tytéž komponenty**: `ViewerDetail.svelte`
  (`properties`, `heading`, `notice`, `badges`, `empty`, `text`, `code`,
  Odeslaná pošta), `DocumentDetail.svelte` (karty
  stran, údaje dokladu, Řádky) i `DetailAttachments.svelte` používají
  `SectionHeading` a `PropertyGroups` — každý detail má stejné písmo
  i mezery; obecný typ `table` používá tytéž role. **Pravidlo:** nový nadpis
  sekce, seznam popisek–hodnota, hláška nebo štítek v detailu je sdílená
  komponenta, ne vlastní třída s kopií CSS.

### Úzká šířka

Kořen obsahu dokladu (`.shpd-docdetail`) i obal skupin vlastností
(`PropertyGroups`, `.shpd-property-groups`) jsou `container-type:
inline-size`; pod prahem úzkého kontejneru `30rem` (480 px, §8
*Responzivita*) jdou strany pod sebe a skupiny vlastností do jednoho
sloupce přes `@container (max-width: 30rem)` — ne `@media`, protože detail
bývá v panelu vedle seznamu, v draweru i v modalu a šířka okna o jeho šířce
nic neříká (D3). Práh vychází ze skutečných šířek obsahu: panel vedle
seznamu má šířku okna bez 693 px (sidebar, seznam, odsazení), drawer
528 px, modal přes 1100 px — dvě karty vedle sebe tak zůstávají všude
kromě úzkého okna a mobilu. Původních 40 rem stohovalo strany už při okně
užším než 1333 px. Dotaz platí pro potomky kontejneru, ne pro něj sám —
proto je kontejnerem obal mřížky na celou šířku, ne mřížka samotná;
`container-type` nepatří na prvky, které se mají smrštit na obsah.
Složené řádky tabulky dokladu na úzké šířce dostanou práh `36rem` (#139);
obal tabulky s posunem do strany řeší #138 task 3.

---

## 13. Soubory

| Soubor | Obsah |
|---|---|
| `frontend/src/styles/variables.css` | **Centrum pravdy** — všechny barvy, spacing, typografie, layout tokeny |
| `frontend/src/styles/base.css` | Základní typografie a layout |
| `frontend/src/styles/reset.css` | CSS reset |
| `frontend/src/components/ui/Button.svelte` | Tlačítkový primitiv s variantami |
| `frontend/src/components/ui/SectionHeading.svelte` | Nadpis sekce detailu (§12, D28) — heading bez linky, `level` 2–4, snippet `aside`; `role` title, `icon` tématu a `spacing` pro nadpis karty (§8 Karty, D36, D38) |
| `frontend/src/components/ui/Card.svelte` | Karta v obsahu (§8 Karty, D33) — rámeček, odsazení md / `spacious` lg, nadpis `SectionHeading` + `aside`, `titleRole` a `icon` podle místa karty (D36, D38), tóny `warning` / `danger` / `info`, `as`, bez stínu a okraje |
| `frontend/src/components/ui/CardGrid.svelte` | Mřížka karet (§8 Karty, D34) — `auto-fill` s `min()` ve `minmax`, `minWidth` podle použití, karty v řádku stejně vysoké |
| `frontend/src/components/ui/PropertyList.svelte` | Seznam popisek–hodnota jedné skupiny (§12, D29) — mřížka `max-content 1fr`, zalamování, prázdný popisek přes oba sloupce |
| `frontend/src/components/ui/PropertyGroups.svelte` | Skupiny vlastností ve dvou sloupcích, `wide`, container query 30 rem (§12, D29); výběr skupin čistá funkce `propertyGroups.js` |
| `frontend/src/components/ui/Notice.svelte` | Hláška v obsahu (§8 Hlášky a pruhy, D40) — tóny z rodiny `alert-*`, rámeček 1 px, `title`, `icon`, `items` (text nebo `{label, text}`), role podle tónu, bez okraje |
| `frontend/src/components/ui/Badge.svelte` | Štítek hlavičky detailu a bloku `badges` (§5) — obecné i doc-state varianty, pilulka meta |
| `frontend/src/components/ui/PageBanner.svelte` | Pruh nad obsahem (§8 Hlášky a pruhy, D41) — celá šířka, linka 2 px dole, tóny `warning` / `info` / `danger` |
| `frontend/src/components/viewer/ViewerRow.svelte` | Řádek seznamu se stavovým pruhem |
| `frontend/src/components/viewer/ViewerDetail.svelte` | Detail s hlavičkou, taby, štítky (`Badge`); obecné typy obsahu (`properties`, `heading`, `notice`, `badges`, `empty`, `text`, `code`) sdílenými komponentami a podle rolí (§12); HTML jen `untrusted-html` v sandboxu (D30) |
| `frontend/src/components/viewer/DocumentDetail.svelte` | Detail dokladu — referenční implementace §12 (role, karty stran, údaje přes `PropertyGroups`, tabulky, součty, container query) |
| `frontend/src/components/form/FormStateBadge.svelte` | Stavový badge ve formuláři |

---

## 14. Plánovaná rozšíření
