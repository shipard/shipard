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
  že je vše OK), ale tichou šedou — ne zelenou, ne modrou.
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
| `ViewerDetail.svelte` (hlavička) | `.shpd-detail__badge--*` | Badge v hlavičce detailu (typ záznamu, stav, VIP atd.) |
| `ViewerDetail.svelte` (tab Návrh) | `.shpd-extracted__badge--*` | Badge pásma/verdiktu u dokumentového návrhu zprávy |
| `FormStateBadge.svelte` | `.shpd-form-state-badge.docState_*` | Stavový badge v hlavičce editačního formuláře |

Všechny tři používají **stejnou doc-state paletu** (concept, edit, confirmed,
archive, trash, cancelled). Plus mají vlastní **typové varianty** mimo doc-state:

### Typové varianty (jen `__badge--*`)

| Třída | Pozadí / text | Použití |
|---|---|---|
| `--neutral` | šedá | Default, typový badge bez významu (např. „Fyzická osoba") |
| `--primary` | modrá soft | Důležitá info (typ záznamu, kód) |
| `--accent` | oranžová soft | „VIP klient", důležité, „za pozornost" |
| `--success` | zelená | Úspěch (mimo doc-state) |
| `--warning` | žlutá | Varování |
| `--danger` | červená | Chyba |

### API kontrakt pro backend

Backend posílá v `data.detail`:

```json
{
    "title": "Petra Benešová",
    "subtitle": "TEST-0074",
    "badges": [
        { "label": "Fyzická osoba", "style": "primary" },
        { "label": "VIP klient",    "style": "accent"  }
    ],
    "icon": "user",
    "tabs": [ ... ]
}
```

`title` je povinný pokud se má hlavička detailu vykreslit. Volitelný
`icon` (klíč z `icons.js`, typicky shodný s `viewers[].icon` v
`module.jsonc`) přidá 40×40 ikonu vlevo od title. Bez `title` se
hlavička přeskočí (graceful fallback — backend nemusí být upraven).

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

Na ≤ 768px se sidebar mění na overlay drawer (vysune zleva přes obsah,
overlay ztmaví zbytek). Spouští ho hamburger v `MobileTopBar`. Detaily
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
  jen pokud backend pošle `detail.title`

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
- **Mobil (≤ 768px)**: každý modál je fullscreen (`100vw × 100dvh`,
  bez zaoblení/okrajů), footer tlačítka na plnou šířku, header summary
  skryt, depth-shrink vypnut (vnořený modál překryje rodiče). Pevné
  width/height props se přebijí. Čistě CSS `@media` v `Modal.svelte`;
  breakpoint 768px ladí s `MOBILE_BREAKPOINT`. Detaily v
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

## 10. Soubory

| Soubor | Obsah |
|---|---|
| `frontend/src/styles/variables.css` | **Centrum pravdy** — všechny barvy, spacing, typografie, layout tokeny |
| `frontend/src/styles/base.css` | Základní typografie a layout |
| `frontend/src/styles/reset.css` | CSS reset |
| `frontend/src/components/ui/Button.svelte` | Tlačítkový primitiv s variantami |
| `frontend/src/components/viewer/ViewerRow.svelte` | Řádek seznamu se stavovým pruhem |
| `frontend/src/components/viewer/ViewerDetail.svelte` | Detail s hlavičkou, taby, badge |
| `frontend/src/components/form/FormStateBadge.svelte` | Stavový badge ve formuláři |

---

## 11. Plánovaná rozšíření
