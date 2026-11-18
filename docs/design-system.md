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
  v navigaci (sidebar, shelly). **V seznamech záznamů
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
| `--shpd-color-primary` | `#005089` | **Plocha**: primary tlačítka, aktivní stav navigace, výplň zaškrtnutého radia a zaškrtávátka |
| `--shpd-color-primary-hover` | `#003e6b` | Hover na primary tlačítku — jen plocha, ne barva textu |
| `--shpd-color-primary-text` | světlý `var(--primary)`, tmavý `#7fb2dc` | **Text, ikona a odkaz** v barvě primary (D92) — odkazy, ikony stavu, aktivní tab, Badge `primary`, avatar řádku |
| `--shpd-color-primary-soft` | `#e6eef5` | Světlé pozadí pro primary badge a jemné zvýraznění |
| `--shpd-color-primary-soft-2` | `#cfdcea` | O stupeň silnější varianta |

Primary má **dvě role** jako tóny (D92, #153): plocha (`primary`, pod
`--shpd-color-text-on-fill`) a text (`primary-text`). Ve světlém vzhledu
mají stejnou hodnotu; v tmavém je plocha ztlumená (`#2d6890`) a jako text
by na pozadí dala jen 2,1–2,8 : 1, proto má text vlastní světlejší
hodnotu — 6,63 na `bg`, 5,61 na `bg-hover`, 7,48 na `bg-secondary`, 6,07
na `primary-soft`. **Hover odkazu barvu nemění**, jen podtrhne.

### Accent (oranžová)

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-accent` | `#eb6507` | Levý proužek aktivní položky v navigaci, avatar VIP, focus přízvuky |
| `--shpd-color-accent-hover` | `#c45405` | Hover na accent prvcích |
| `--shpd-color-accent-soft` | `#fdebdc` | Světlý podklad v barvě značky (štítek `accent` zanikl, D102) |

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
| `--shpd-color-text-on-fill` | `#ffffff` | Text na plné barvě — tlačítko, aktivní buňka a podobné plochy v barvě primary, accent nebo stavové (D59); stejná hodnota v obou motivech, vlastní vzhled ho neodvozuje |

### Borders & focus

| Token | Hodnota | Použití |
|---|---|---|
| `--shpd-color-border` | `#e2e8f0` | Standardní hranice |
| `--shpd-color-border-strong` | `#cbd5e1` | Silnější hranice |
| `--shpd-color-border-control` | `var(--border)` (tmavý `#737c8c`) | Rámeček ovládacího prvku (D109, D111) |
| `--shpd-color-border-focus` | `var(--primary-text)` | Hranice focusovaného inputu a obrys fokusu (D95) |
| `--shpd-color-focus-ring` | `rgb(0 80 137 / 0.18)` | Glow okolo focusovaného inputu |
| `--shpd-color-error-ring` | `rgb(220 38 38 / 0.15)` | Glow okolo chybného inputu |

**Obrys fokusu jde vždy přes token** (D95, #153): `--shpd-color-border-focus`
odkazuje na `primary-text`, v tmavém vzhledu tak má vůči `bg` 6,63 : 1
místo 2,49. `outline` u `:focus-visible` ani okraj pole s fokusem nebere
`--shpd-color-primary` napřímo. Přerušovaný rámeček cíle přetažení souboru
(`AttachmentPanel`, `Dashboard`) bere `--shpd-color-primary-text`.

**Rámeček ovládacího prvku je `--shpd-color-border-control`** (D109,
D111, #156). Token berou pole a výběry, spouštěč popoveru (výběr období),
nevybraný segment přepínače, pilulka filtru feedu, nezaškrtnuté
zaškrtávátko a radio a sekundární tlačítko — komponenta nebere `border`
napřímo, ani když má token ve světlém vzhledu stejnou hodnotu.

- **Tmavý vzhled** drží práh hranice ovládacího prvku 3 : 1
  (WCAG 1.4.11): `#737c8c` má 3,55 : 1 na `bg`, 4,01 na `bg-secondary`
  a 3,01 na `bg-hover` — těsně nad prahem, změna jedné z obou hodnot
  znamená přepočítat druhou (hlídá `check-contrast.py`).
- **Světlý vzhled** je zdůvodněná výjimka (D111): token má hodnotu
  `border` (`#e2e8f0`) — 1,23 : 1 na `bg`, 1,16 na `bg-secondary`
  a `bg-hover`. Rámeček nad prahem (`#7c8796`, D109) byl při ručním
  ověření příliš tmavý a rušil; pole na šedém panelu formuláře nese
  i bílá plocha.

- **Neaktivní prvek zůstává na `--shpd-color-border`** — nejde s ním
  pracovat, hranice ho nemá zvýrazňovat (`:disabled` pole, tlačítka,
  segmentu, zaškrtávátka).
- **`border` a `border-strong` zůstávají** dělicím čarám, kartám, panelům,
  tabulkám, čipům uvnitř pole a rámečku nabídky — tedy všemu, co není
  hranice ovládacího prvku.
- Fokus a chyba se nemění: `border-focus` (D95) a `danger-text` mají
  přednost před rámečkem prvku; vybraná volba má `primary-text` (D103).
- Nativní zaškrtávátko a radio (pár míst mimo `Checkbox` / `OptionRow`)
  kreslí prohlížeč; token se jich netýká.

**Barvou výběru je `primary`** (D77, #149): vybraná volba — volitelný řádek
i segment přepínače — má rámeček `--shpd-color-primary-text` a pozadí
`--shpd-color-primary-soft` (D103, #153: plocha `primary` měla jako čára
v tmavém vzhledu 2,49 : 1, `primary-text` má vůči podkladu výběru 6,07;
světlý vzhled beze změny). Výplň zaškrtnutého radia a zaškrtávátka zůstává
`primary`. `accent` zůstává barvou značky a výběr neznačí.

### Stavové (semantické)

Tón má **tři role a tři tokeny** (#133 D69, D70, D74) — jedna hodnota
nesplní kontrast bílého textu na ploše i kontrast textu na pozadí stránky
v obou vzhledech, a značka bez textu nemá být tak těžká jako malé písmo:

| Tón | Plocha `--shpd-color-{tón}` | Hover `-hover` | Text `-text` světlý / tmavý | Soft `-soft` světlý / tmavý | Použití |
|---|---|---|---|---|---|
| `danger` | `#dc2626` | `#b91c1c` | `#dc2626` / `#f87171` | `#fef2f2` / `#2d1414` | Mazání, error, zamítnutí |
| `success` | `#15803d` | `#166534` | `#15803d` / `#22c55e` | `#f0fdf4` / `#14291c` | Použít, potvrdit pozitivní akci |
| `warning` | `#b45309` | `#92400e` | `#b45309` / `#f59e0b` | `#fffbeb` / `#2e2410` | Varování (mimo doc-state systém) |

- **Plocha** (`--shpd-color-{tón}`, `-hover`): pozadí tlačítka a odznaku
  s textem. Text na ní je `--shpd-color-text-on-fill`. Hodnoty
  jsou **v obou vzhledech stejné**; bílý text drží 4,8–5,0 : 1 (hover
  6,5–7,1 : 1).
- **Text** (`--shpd-color-{tón}-text`): text, ikona a rámeček prvku (input
  ve stavu chyby, karta s tónem) na pozadí stránky, karty nebo dialogu. Na
  `bg` / `bg-hover` drží světlý vzhled 4,8 / 4,5 (danger), 5,0 / 4,7
  (warning, success), tmavý 5,4 / 4,6 (danger), 7,0 / 5,9 (warning),
  6,6 / 5,6 (success).
- **Značka** (`--shpd-color-{tón}-mark`, D74): prvek, který **nenese text
  ani samostatný význam bez okolí** — tečka sekce a filtru, donut jistoty
  (jen oblouk; procenta uvnitř jsou `--shpd-color-text`), barevný proužek
  karty, tečka chyby na tabu, tečka stavu a varovná výplň průběhového
  pruhu ve vývojářském dashboardu. Stačí jí práh grafiky **3 : 1** vůči pozadí, ve
  světlém vzhledu je proto světlejší než `-text`; v tmavém na `-text`
  odkazuje. Čtvrtý tón `info` (modrá tečka Nové položky) má jen tuto roli
  a v obou vzhledech stejnou hodnotu (D93).
  **Ikona s významem je text**, ne značka: varování ve feedu, ikona řádku
  reportu a každá ikona s vlastním popiskem (`title`, `aria-label`) berou
  `-text`.

  | Tón | Značka světlý | Značka tmavý | Světlý na `bg` / `bg-hover` | Tmavý na `bg` / `bg-secondary` |
  |---|---|---|---|---|
  | `info` | `#3b82f6` | `#3b82f6` (D93) | 3,68 / 3,45 | 4,07 / 4,59 |
  | `success` | `#16a34a` | `-text` (`#22c55e`) | 3,30 / 3,10 | 6,56 / 7,41 |
  | `warning` | `#d06f06` | `-text` (`#f59e0b`) | 3,52 / 3,31 | 6,96 / 7,86 |
  | `danger` | `#dc2626` | `-text` (`#f87171`) | 4,83 / 4,54 | 5,41 / 6,10 |

  Tmavý `info` do D93 odkazoval na `primary` (2,49 : 1 na `bg`); na
  `bg-hover` má dnes 3,44.
- **Text na tónovaném podkladu kreslí jen `Badge`** (D90, #153, §5).
  Tónový text na tónovaném podkladu vyrobeném na místě kontrast nedrží —
  `danger-text` na `color-mix(danger 14 %)` má ve světlém vzhledu 3,9 : 1,
  na `danger-soft` 4,4 — a nikdo ho nespočítá, protože barva vzniká až
  v prohlížeči. `Badge` bere dvojice tokenů `state-…-bg` / `-text`
  (5,3–9,9 : 1 v obou vzhledech, hlídá `check-contrast.py`). Tónový
  `color-mix(…)` v komponentě je nález `check-css-tokens.py`. `-soft` je
  **pozadí bez tónového textu** (podbarvení plochy, řádku).
- **Na ploše, která není pozadí stránky** — sidebar, aktivní položka
  navigace, aktivní chip — neplatí žádná z rolí: `-text` světlého vzhledu
  dá na `#00345c` jen 2,6 : 1, tečka v ploše tónu 2,5–2,8 a na aktivní
  položce 1,2–1,7; vlastní vzhled navíc barvu sidebaru mění. Proto:
  - **značka má obrys** (D88): `box-shadow: 0 0 0 1px
    var(--shpd-color-text-on-fill)` — čára `1px` (D96, nahrazuje
    `0,125rem` z D88: kolem malé tečky bylo víc bílé než barvy) —
    a plochu tónu (`--shpd-color-danger` /
    `-warning`, ne `-mark` — ta je v tmavém vzhledu světlejší a vůči bílé
    by měla 2,8). Tečka má vůči obrysu 4,83 (danger) a 5,02 (warning) : 1
    v obou vzhledech na jakékoli barvě pod ní. Platí pro tečku odznaku
    sekce (`NavTree`, `TopMenuBar`), pilulku s počtem v railu
    (`SectionRail`) a tečku chipu feedu (`FeedFilter`; posuvný kontejner
    chipů má pro ni rezervu, jinak by obrys ořízl). Stejně se kreslí
    tečka klidného tónu na **vybraném řádku seznamu** (D99, §4): plocha
    `--shpd-color-success` s obrysem, 5,02 : 1 vůči obrysu — `success-mark`
    má vůči `bg-selected` ve světlém vzhledu jen 2,1–2,5;
  - **text i ikona mají barvu textu sidebaru** (D89): chyba načtení
    navigace nese význam tvarem ikony a textem, ne tónem
    (`--shpd-color-text-sidebar`, 10,9 / 11,1 : 1).
- **Výjimky** z prahů — vyjmenované i v `scripts/check-contrast.py`
  (konstanta `EXCEPTIONS`):
  - **D94** — okraj tlačítka `danger` pod kurzorem v tmavém vzhledu má
    vůči dialogu 2,31 : 1 (`danger-hover` na `bg`); tlačítko identifikuje
    text (6,47 : 1), ne okraj.
  - **D111** — rámeček ovládacího prvku ve světlém vzhledu
    (`border-control` na `bg` 1,23 : 1, na `bg-secondary` a `bg-hover`
    1,16): hodnota `border`, protože rámeček nad prahem rušil (#156,
    výše Rámeček ovládacího prvku). Tmavý vzhled výjimku nemá.
  - **D112** (rozšíření D94) — plný prvek v barvě `primary` v tmavém
    vzhledu má vůči podkladu 2,49 : 1 (`bg`) a 2,81 (`bg-secondary`):
    primární tlačítko, zaškrtnuté zaškrtávátko, vybrané radio volitelného
    řádku a aktivní pilulka filtru. Prvek identifikuje bílý text nebo
    značka uvnitř (6,01 : 1 vůči ploše), ne hrana plochy. Vybraný segment
    přepínače plný není (D103: rámeček `primary-text`, pozadí
    `primary-soft`). Na `bg-hover` (zaškrtávátko v řádku pod kurzorem)
    vychází 2,11 : 1 — mimo vyjmenované dvojice.

  Štítek stavu Koš výjimkou už není (D101): `state-trash-text` má na
  `state-trash-bg` 7,56 / 6,10 : 1 (plná pilulka, D104; po D101
  6,10 / 5,35).

Vývojářský dashboard přepisuje `danger` i `danger-text` na `#c81e1e`
(`dev/theme.css`), ostatní dědí — i značky: `danger-mark` je tam `#dc2626`,
o odstín světlejší než červená dashboardu (4,46 : 1 na jeho pozadí),
`success-mark` má na pozadí dashboardu 3,04 : 1.

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
| `archive` | tichá šedá `#cbd5e1` / tmavý `#4b5563`, text řádku `text-secondary` | světle šedá | Archív — tichý, mimo aktivní práci |
| `trash` | výrazná šedá `#64748b` / tmavý `#94a3b8`, text řádku `text-secondary` | **plná tmavě šedá pilulka** `#4b5563`, text `#ffffff` / `#e5e7eb` | V koši — **bez přeškrtnutí**, smazaný záznam má jít přečíst (D101); výraznější než archiv (D104) |
| `cancelled` | červená `#ef4444` | červená | Zrušeno, pozor |
| `error` | — | červená | Chyba (např. AI selhala) |

### Tokeny

Každý stav má až tři tokeny:

- `--shpd-color-state-{name}-bar` — barva levého proužku v `ViewerRow`
- `--shpd-color-state-{name}-bg` — pozadí badge v hlavičce detailu / formuláři
- `--shpd-color-state-{name}-text` — text uvnitř badge

Text archivního a smazaného řádku bere `--shpd-color-text-secondary`
(D101, #153; token `state-archive-row` zanikl). Confirmed a done nemají
`-bar` token, protože jejich proužek je záměrně absent.

Proužky (`-bar`) mají v tmavém vzhledu stejnou hodnotu jako ve světlém —
kromě archivu a koše, které tmavý blok přepisuje (D104, níže).

### Koš a Archiv

Smazaný záznam má být na první pohled odlišný od archivního v **obou
vzhledech** (D104, #153). Text řádku mají oba stejný (`text-secondary`,
D101), rozdíl proto nese proužek a štítek:

- **Proužek koše je výrazný, archivu tichý.** Poměr vůči pozadí řádku:

  | Proužek | Světlý `bg` / pod kurzorem / vybraný / vybraný pod kurzorem | Tmavý |
  |---|---|---|
  | archiv `#cbd5e1` / `#4b5563` | 1,48 / 1,39 / 1,13 / 1,06 | 1,98 / 1,67 / 1,55 / 1,28 |
  | koš `#64748b` / `#94a3b8` | 4,76 / 4,47 / 3,61 / 3,04 | 5,83 / 4,94 / 4,58 / 3,78 |

  Proužek koše drží 3 : 1 i na vybraném řádku pod kurzorem. Do D104 měl
  koš ve světlém vzhledu 2,56 : 1 a v tmavém dědily oba proužky světlé
  hodnoty — archiv (10,07 : 1) tam byl výraznější než koš (5,83).
- **Štítek Smazáno je plná tmavě šedá pilulka** se světlým textem
  (`state-trash-bg` `#4b5563` v obou vzhledech, text 7,56 / 6,10 : 1);
  štítek V archívu zůstává světle šedý (v tmavém vzhledu byly oba štítky
  totožné). Tokeny sdílí `Badge` (hlavička detailu), `FormStateBadge`
  (formulář) i `SpanBadge` (buňka mřížky) — štítek vypadá všude stejně.
  `state-trash-bg` je podklad pilulky, ne plochy.

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

### Stav v řádku seznamu

Řádek seznamu vypisuje stav textem jen tam, kde nese informaci (#153):

- **Koncept ani „V pořádku“ se nevypisují** (D98). Koncept (`stateStyle`
  `concept`) nese proužek, stav s `detailBadge: false` je běžný stav —
  totéž pravidlo jako štítek v hlavičce detailu (D20). Ostatní stavy se
  vypisují spanem. Rozhoduje jediná metoda `TableViewer::rowStateTag()`;
  třídu spanu dává `rowStateClass()` z jedné mapy (`concept` / `edit` →
  `warning`, `confirmed` → `primary`, `done` → `success`, `cancelled` →
  `danger`, `archive` / `trash` → `muted`). Viewer si vlastní mapu ani
  podmínku na hodnotu stavu nepíše (`TableViewerRowStateTagTest`); přepsat
  smí třídu jednoho stylu (Úkoly: `edit` = Pozastaveno → `muted`). Podání
  DPH tak v řádku nevypisuje Sestaveno, ale Podáno ano; koeficienty odpočtu
  a období DPH nevypisují Koncept.
- **Klidný tón je tečka, ne zelený text** (D99). Span `success` i pilulka
  `SpanBadge` `success` se kreslí jako tečka `--shpd-color-success-mark`
  (`0.5rem`, jako tečky nadpisů feedu) před textem v barvě okolí —
  v seznamu, mřížce i sub-tabulce formuláře, a všude jinde, kde se
  `SpanBadge` používá (stav „Odesláno“ v Odeslané poště). Tečka má
  3,30 : 1 na `bg` a 3,10 pod kurzorem (tmavý 6,56 / 5,55). Ostatní tóny
  (`primary`, `warning`, `danger`) a pilulky zůstávají.
- **Tlumený text je barva, ne průhlednost** (D100). Span `muted` a třetí
  řádek seznamu berou `--shpd-color-text-secondary` (5,68 : 1 na `bg`,
  5,34 pod kurzorem; tmavý 5,88 / 4,97). Průhlednost dávala 2,5–3,1 : 1
  u spanu a 3,5–4,3 u třetího řádku.
- **Archivní a smazaný řádek** (D101) má text `text-secondary` — v seznamu,
  mřížce i sub-tabulce — a nic se nepřeškrtává. `ViewerRow` a `ViewerGrid`
  si barvu řádku / buňky nastavují samy, proto mají pro `.docState_archive`
  a `.docState_trash` vlastní pravidlo (globální třída scoped barvu
  nepřebije); sub-tabulka barvu dědí. Karta feedu se netlumí.

### Při výběru řádku

Když uživatel označí řádek v seznamu:

- Pozadí řádku se zbarví na `--shpd-color-bg-selected` (hover
  `--shpd-color-bg-selected-hover`).
- **Stavový proužek zůstává** — koncept je dál žlutý, hotový záznam dál
  bez proužku (průhledný pruh splyne s modrým pozadím). Výběr stav nepřebíjí.
- **Tónové a šedé texty mají barvu běžného textu** (D91, #153): tónové
  spany (`primary`, `warning`, `danger`), tlumený span `muted`, text
  archivního a smazaného řádku, sekundární texty
  řádku (číslo, druhý a třetí řádek, údaj vpravo), text chybového řádku
  mřížky a štítek sekce vybrané konverzace berou `--shpd-color-text`.
  Na `bg-selected` a hlavně `bg-selected-hover` tón ani `text-secondary`
  4,5 : 1 nedrží (`text-secondary` 4,31 / 3,62 ve světlém, 4,61 / 3,81
  v tmavém; `success-text` pod kurzorem 3,2 / 4,3); běžný text má
  13,5 / 11,4 a 9,6 / 7,9. **Tečka klidného tónu** je na vybraném řádku
  plocha `--shpd-color-success` s obrysem `1px` `text-on-fill` (D99, D88,
  D96) — vůči obrysu 5,02 : 1 v obou vzhledech; text u ní má barvu řádku
  vždy. Nemění se pilulky `SpanBadge` a avatar (vlastní podklad) ani ikona
  řádku (stačí jí 3 : 1). Jedno pravidlo na
  komponentu — selektor vybraného řádku přepíše barvu potomků, žádné
  podmínky v markupu (`ViewerRow`, `ViewerGrid`, `ConversationList`).

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
Rodinu smí v komponentách použít jen `Notice` a `PageBanner` (§8 Hlášky
a pruhy, D61).

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

**`Badge` je jediný způsob, jak napsat tónový text na tónovaný podklad**
(D90, #153). Komponenta si vlastní štítek s `color-mix(…)` nebo `-soft`
pod tónovým textem nekreslí — použije `<Badge style="danger" label=… />`
(lhůta karty feedu po termínu nebo do 3 dnů, odznaky Přehledu firmy,
„Selhalo“ v portálu).
`Badge` nese jen text a předává `...rest` (`data-testid`); rozmístění řeší
obal, ne `class` na komponentě (přepsala by její vlastní třídy), ikona
patří vedle štítku. Dvojice podklad / text hlídá `check-contrast.py`.

**Klidný stav nevyčnívá** (D97, #153): neutrální informace, která jen
doplňuje kartu nebo řádek, je text v roli meta
(`--shpd-font-size-meta`, `--shpd-color-text-secondary`), ne pilulka.
Lhůta karty K vyřízení je štítek `danger` jen po termínu nebo do 3 dnů;
lhůta v klidu je šedý text — šedá pilulka `neutral` s kartou splývala
v obou vzhledech. `neutral` zůstává štítkům v hlavičce detailu.

### Typové varianty (jen `.shpd-badge--*`)

| Třída | Pozadí / text | Použití |
|---|---|---|
| `--neutral` | šedá | Default, typový badge bez významu (např. „Fyzická osoba") |
| `--primary` | modrá soft, text `primary-text` | Důležitá info (kód, vlastnost záznamu) |
| `--success` | zelená | Úspěch (mimo doc-state) |
| `--warning` | žlutá | Varování |
| `--danger` | červená | Chyba |

Varianta `accent` zanikla (D102, #153): nikdo ji nepoužíval a měla
3,92 : 1; tokeny `accent-*` zůstávají barvou značky.

`SpanBadge` (štítek v řádku seznamu, buňce mřížky a sub-tabulky) má tytéž
styly. Styl `success` ale nekreslí jako pilulku: je to tečka + text (D99,
§4 Stav v řádku seznamu) — klidný stav nevyčnívá. `Badge` v hlavičce
detailu zůstává pilulkou i pro `success`.

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
`primary`). Volitelný `icon` (klíč z `icons.js`, typicky shodný
s `viewers[].icon` v `module.jsonc`) přidá 40×40 ikonu vlevo od title.
Bez `title` se hlavička přeskočí (graceful fallback — backend nemusí být
upraven). Rozvržení a písmo hlavičky: §12 Detail záznamu.

---

## 6. CSS proměnné — best practices

### Pravidla

1. **Žádné hex hodnoty v komponentách** (D71). Hex smí být jen v souborech
   s definicemi tokenů — `styles/variables.css` a `dev/theme.css` (tokeny
   vývojářského dashboardu `--dev-*`). Pokud potřebuješ barvu, která tam
   není, přidej ji jako token a použij přes `var(--shpd-color-...)`. Bílý
   text na plné barvě = `--shpd-color-text-on-fill` (D59), ne `#fff`.
   **Fallback ve `var(--x, #hex)` se nepíše**: token musí existovat (jinak
   tiše platí fallback) a druhá hodnota jen zastará. Hlídá
   `scripts/check-css-tokens.py` v pre-commitu — hex v hodnotách deklarací
   (`.css`, bloky `<style>`), v atributu `style` a direktivě `style:`,
   mimo komentáře. Výjimky po souborech s důvodem (konstanta `HEX_EXEMPT`):
   komponenty vlastního vzhledu (`ThemeField`, `DsThemeField`,
   `ThemeSwatches`, `ColorField` — barva jako data) a `SandboxedHtml`
   (bílý papír cizího e-mailu). Hex v JS datech (`<script>`, soubory `.js`)
   kontrola nehlídá.
2. **Nepoužívej `:global(.docState_*)`** — kolizuje napříč komponentami,
   které mají stejné `docState_*` třídy. Místo toho použij child selectory
   (`.shpd-component-name.docState_edit`).
3. **Tlačítka řeš přes `<Button>`** komponentu, ne přes vlastní hardcoded
   styly s `!important`. `Button` má varianty `primary | secondary |
   danger | success | ghost` a velikosti `md` (běžná výška ovládacího
   prvku) a `sm` (kompaktní) — kdy kterou, říká §8 Výška ovládacího prvku.
4. **Mezery, odsazení, zaoblení, písmo a pevné rozměry bez px.** Token
   (`--shpd-space-*`, `--shpd-radius-*`, `--shpd-font-size-*`) nebo rem; px
   jen čarám, okrajům, stínům a stavovému / aktivnímu proužku. Hlídá
   `scripts/check-css-units.py` — viz §10 Jednotky a mezery.
5. **Datum a čas jen přes sdílené funkce** (D73) — `DisplayDate`
   v backendu, `utils/formatDate.js` ve frontendu; žádné vlastní
   `format('j. n. Y')` ani `toLocaleDateString()`. Viz §11 → Datum a čas.
6. **Kontrast hlídaných dvojic tokenů počítá skript** (#153 D88–D95).
   `scripts/check-contrast.py` v pre-commitu vyhodnotí z `variables.css`
   světlý (`:root`) i tmavý vzhled (`:root` přepsaný blokem
   `[data-theme="dark"]`, `var()` až v cílovém vzhledu) a zastaví commit,
   když dvojice popředí × pozadí nesplní práh — text **4,5 : 1**, značka,
   ikona a obrys **3 : 1**. Hlídá: tón jako text a značku na `bg` /
   `bg-hover` / `bg-secondary`, bílý text na plochách, značku vůči obrysu
   (D88, D99), `primary-text` na pozadích stránky a `primary-soft` (D92),
   obrys fokusu (D95), běžný a sekundární text, text na vybraném řádku
   (D91), text sidebaru (D89), dvojice `Badge` (`state-…`, D90)
   a `Notice` (`alert-…`). `--list` vypíše všechny dvojice s poměrem —
   čísla v tomto dokumentu jsou z něj. Výjimky jsou pojmenovaná konstanta
   s důvodem (§3 Stavové → Výjimky); výjimka, kterou dvojice už
   nepotřebuje, je nález. Skript nevidí vykreslené barvy (`opacity`,
   pozadí složené přes předky), vlastní vzhled ani vývojářský dashboard.

### Anti-patterny

- ❌ `.shpd-extracted__btn-apply { background: #16a34a !important; }` — vlastní
  tlačítka mimo `<Button>` systém. ✅ Použij `<Button variant="success">`.
- ❌ `:global(.docState_edit) { background: #ffedd5 }` v `FormStateBadge.svelte`
  přebíjí `.docState_edit` v `ViewerRow.svelte`. ✅ Použij child selektor
  `.shpd-form-state-badge.docState_edit`.
- ❌ `box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15)` (stará Tailwind modrá)
  ve focus stavech inputů. ✅ Použij `var(--shpd-color-focus-ring)`.
- ❌ `color: var(--shpd-color-danger)` — plocha jako barva textu; v tmavém
  vzhledu dá na pozadí jen 3,1 : 1. ✅ Text, ikona a rámeček prvku
  berou `var(--shpd-color-danger-text)` (D69), značka bez textu (tečka,
  donut, proužek karty) `var(--shpd-color-danger-mark)` (D74);
  `--shpd-color-danger` je jen pozadí pod `--shpd-color-text-on-fill`.
- ❌ `var(--shpd-color-warning, #d97706)` — fallback s hexem. ✅
  `var(--shpd-color-warning)` (D71).
- ❌ `color: var(--shpd-color-primary)` — plocha primary jako barva textu;
  v tmavém vzhledu dá na pozadí 2,1–2,8 : 1. ✅ Text, ikona a odkaz berou
  `var(--shpd-color-primary-text)` (D92), rámeček výběru také (D103);
  `--shpd-color-primary` je jen plocha. Totéž platí pro `primary-hover` jako barvu textu
  při hoveru — odkaz se jen podtrhne.
- ❌ `background: color-mix(in srgb, var(--shpd-color-danger) 14%, transparent)`
  pod tónovým textem — podklad vyrobený na místě, kontrast 3,9 : 1. ✅
  `<Badge style="danger" label=… />` (D90).
- ❌ `outline: 2px solid var(--shpd-color-primary)` u `:focus-visible`. ✅
  `var(--shpd-color-border-focus)` (D95).
- ❌ `padding: var(--shpd-space-xs) var(--shpd-space-sm)` jako výška pole,
  spouštěče nebo vlastního tlačítka — výška ze součtu odsazení, písma
  a řádkování dá v jedné liště čtyři různé výšky. ✅
  `height: var(--shpd-control-height)` a svislé odsazení 0 (D106);
  víceřádkový prvek `min-height`.
- ❌ `<Button size="sm">` v liště, v patce dialogu nebo vedle pole. ✅
  Výchozí `md`; `sm` jen pro akci v řádku tabulky, na kartě a v hlavičce
  bloku (D107).
- ❌ Vlastní tlačítko ⋮ s `Popover`em a položkami v komponentě. ✅
  `ui/KebabMenu` s polem `items` (D114, §8 Akce na telefonu).
- ❌ `if (e.key === 'Escape') onClose()` v komponentě vrstvy (vlastní
  posluchač na `document` / `window` / prvku) — jeden stisk pak zavře
  bublinu i modal pod ní. ✅ `openLayer({ kind, transient, onEscape })`
  z `utils/layerStack.js` v `$effect` (D121, §8 Zavírání vrstev);
  hlídá `escapeUsage.test.mjs`.
- ❌ Posluchač kliku mimo u postranního panelu (detail v draweru, boční
  chat). ✅ Klik mimo zavírá jen dočasné vrstvy (D122).
- ❌ Vlastní obsluha klepnutí do `<iframe>` v komponentě vrstvy (překryv
  nad dokumentem, posluchač `blur` okna). ✅ Vrstva řekne zásobníku
  `transient: true`, zbytek je jedno pravidlo v `styles/base.css` (D124,
  §8 Zavírání vrstev).
- ❌ Zavřít / Zrušit v patičce `Modal`u bez `dismiss` — na telefonu zabírá
  místo akcím a opakuje ×. ✅ `<Button dismiss … />`; výjimkou je odpověď
  potvrzovacího dotazu (D116, §8 Modální dialogy).
- ❌ `{#if !layoutStore.isMobile}` kolem zavíracího tlačítka dialogu. ✅
  Tlačítko se označí a skryje ho `Modal` — pravidlo je na jednom místě.
- ❌ `grid-template-columns: max-content 1fr` v mřížce popisek–pole —
  nejdelší popisek zúží pole bez omezení. ✅ `fit-content(40%)
  minmax(0, 1fr)` (D118, §8 Popisky a pole); `max-content` zůstává mřížkám
  jen ke čtení (`PropertyList`).
- ❌ `white-space: nowrap` na popisku pole. ✅ Popisek se smí zalomit;
  první řádek drží na středu pole horní odsazení, ne účaří (D118).
- ❌ `{#if layoutStore.isMobile}` kolem popisků nebo skupiny polí —
  formulář bývá v úzkém modalu i na desktopu. ✅ `@container` s prahem
  `30rem` (ve formuláři pojmenovaný `form-section`), jedno DOM pro obě
  podoby (D117, D119).
- ❌ `border: 1px solid var(--shpd-color-border)` na poli, výběru nebo
  spouštěči — v tmavém vzhledu 1,4 : 1 vůči podkladu. ✅
  `var(--shpd-color-border-control)` (D109); ve světlém vzhledu mají oba
  tokeny stejnou hodnotu (výjimka D111), komponenta přesto bere token
  ovládacího prvku.

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
Otevřený drawer je vrstva zásobníku (*Zavírání vrstev* níže): Esc ho zavře,
jen když nad ním není otevřené uživatelské menu.

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
- **Řetěz výšky** (#133): obsahová oblast `.shpd-content` je rolovací
  kontejner. Obsah, který roluje po částech, má kořen `height: 100%;
  overflow: hidden` a pod ním už jen flex (`flex: 1; min-height: 0`) —
  seznam a detail rolují každý zvlášť, `.shpd-content` sama neroluje.
  **Stránka chatu** (`ChatView`) používá stejný řetěz: seznam konverzací
  a vlákno rolují každé zvlášť, pole zprávy drží dole.
- **Vedle kořene s `height: 100%` nesmí v `.shpd-content` ležet nic
  dalšího** — obsah by byl o výšku toho prvku vyšší než oblast, spodek
  uříznutý a celá stránka by rolovala. Proto pruhy nad obsahem leží nad
  rolovací oblastí, ne v ní (*Hlášky a pruhy* níže). Totéž o úroveň níž:
  `height: 100%` uvnitř flex sloupce s dalším prvkem (lišta, hlavička
  panelu) dá víc než 100 % — patří tam `flex: 1; min-height: 0`
  (`ChatThread` v `ChatView`, `ChatPanel` i `SectionAssistant`).

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
  `warning` / `danger` mění jen barvu rámečku (`--shpd-color-warning-text` /
  `--shpd-color-danger-text`), `info` dává tónované pozadí
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
  nejsou ani vložené panely se šedým pozadím (`Panel`, viz Vložené panely
  níže), hlášky a pruhy (chyba, varování, zámek dokladu — `Notice` /
  `PageBanner`, viz Hlášky a pruhy níže) ani volitelné řádky (`OptionRow`,
  viz Volitelné řádky níže).
  Hláška selhání analýzy (`FailureCard`) je karta s tónem — rámeček
  v barvě, bez levého pruhu.
- **Pravidlo:** nová karta = `Card` (v mřížce `CardGrid`), ne vlastní
  třída s kopií rámečku. Dnes na nich stojí Přehled firmy, feed dashboardu
  (karta feedu, AI shrnutí), čtyři karty Nastavení, strany dokladu
  v detailu i v náhledu importu, karta návrhu z pošty, karta selhání
  a náhled v dialogu Přidat firmu z registru.

### Vložené panely

Rozhodnutí D75 z #149. Šedá plocha uvnitř obsahu je sdílený
`ui/Panel.svelte` — kartou není (D35).

- **Vzhled:** pozadí `--shpd-color-bg-secondary`, rámeček
  `--shpd-color-border-subtle`, zaoblení `--shpd-radius-md`, odsazení
  `--shpd-space-md`; žádný stín ani vnější okraj — mezery dává rodič.
  V tmavém vzhledu bez úprav (tokeny se přepínají; `bg-secondary` je tam
  tmavší než plocha, §9).
- **Varianta `compact`:** bez rámečku, odsazení `--shpd-space-sm`, zaoblení
  `--shpd-radius-sm` — drobná nápověda a „aktuální volba“ v popoveru
  rozhodovacích panelů importu.
- **Tón `info`:** pozadí `--shpd-color-primary-soft`, bez rámečku
  („aktuální volba“ = `compact` + `info`).
- **`padding="form"`:** husté odsazení sekce formuláře
  (`--shpd-form-section-padding-y` × `--shpd-space-lg`) — jen `FormSection`,
  která na panelu stojí (`as="section"`).
- **`as`** volí značku (`div` výchozí, `section`, `li`, `dl`, `p`); zbylé
  atributy (`data-testid`, `role`, `class`) jdou na kořen.
- **Rodič kořen nestyluje:** pozadí, rámeček, odsazení a zaoblení jsou
  propy panelu. Třída rodiče na kořeni smí nést jen rozložení obsahu
  (mřížka souhrnu, `max-width` palety, pojmenovaný kontejner
  `form-section`) — v CSS rodiče jako `:global(.třída)` pod vlastním
  selektorem, protože kořen cizí komponenty třídu rozsahu nenese.
- **Panel v panelu se nepoužívá** a panel neleží na `bg-secondary`
  podkladu (šedá na šedé) — patří na plochu `bg` (karta, dialog, popover).
  Souhrn uvnitř nabídky položek je proto jen text, ne další box.
- **Panelem není** rozbalený detail karty feedu (bez pozadí a vlastního
  odsazení, jen mezera nad a mezi řádky).
- **Kde:** sekce formuláře, nabídka položek a položky průvodce zdrojem
  dat, paleta výchozího vzhledu, souhrn předvyplnění registrace DPH,
  nápověda a „aktuální volba“ v rozhodovacích panelech importu.
- **Pravidlo:** nová šedá plocha v obsahu = `Panel`, ne vlastní třída
  s kopií pozadí a rámečku.

### Výška ovládacího prvku

Rozhodnutí D106–D112 z #156 (D110–D112 upravují D106–D109 po ručním
ověření). Ovládací prvky ve stejné liště nebo řádku jsou stejně vysoké
a mají stejné písmo a rámeček. Výška je **token**, ne součet odsazení,
písma a řádkování.

| Token | Běžná šířka | Telefon (≤ 48rem) | Kdo ho bere |
|---|---|---|---|
| `--shpd-control-height` | `2.25rem` (36 px) | `2.5rem` (40 px) | `Button` `md`, pole, výběr, spouštěč popoveru, segment, pilulka filtru |
| `--shpd-control-height-sm` | `1.75rem` (28 px) | `2rem` (32 px) | `Button` `sm`, výběr v řádku tabulky |
| `--shpd-font-size-control` | 16 px | 16 px | tlačítko `md`, spouštěč, segment, pilulka, popisek zaškrtávátka |
| `--shpd-font-size-field` | 16 px | 16 px | pole, výběr, víceřádkový text a položky jejich nabídky |
| `--shpd-font-size-control-sm` | 14 px | 14 px | `Button` `sm` |
| `--shpd-font-size-field-sm` | 14 px | 16 px | pole v řádku tabulky (výběr kódu DPH v review) |

- **Jednořádkový prvek** má `height` z tokenu a svislé odsazení 0 — obsah
  centruje flex nebo sám prohlížeč. **Víceřádkový** (`TextArea`,
  `MultiselectInput`, zalamované tlačítko `wrap`, pole chatu) má token
  jako `min-height`; `TextArea` a `wrap` dopočítají svislé odsazení tak,
  aby jeden řádek dal přesně výšku prvku.
- **Kdy běžná, kdy kompaktní** (D107): každá lišta — seznam, report,
  hlavička dashboardu, hlavička detailu, stavová lišta formuláře, patička
  dialogu — a tlačítko vedle pole mají běžnou výšku (`Button` bez `size`).
  Kompaktní (`size="sm"`) je jen akce v řádku tabulky, na kartě, v hlášce
  a v hlavičce bloku (Přidat nad sub-tabulkou, Nahrát nad přílohami,
  hromadné akce review, hlavička chat panelu).
- **`Button`**: vodorovné odsazení `md` `--shpd-space-lg` (D110), `sm`
  12 px. Tlačítko bez popisku je čtverec své výšky (i `ghost`); `ghost`
  s popiskem má výšku z tokenu a odsazení `--shpd-space-sm`. Ikona v poli
  (`LookupInput` — upravit, zrušit) zůstává 24 px uvnitř pole. Vlastní
  `<button>` v podobě sekundárního tlačítka (rozbalovací akce v hlavičce
  detailu, Upravit u vzhledu) má odsazení stejné.
- **Písmo** (D110, upravuje D108): běžný ovládací prvek má 16 px (`base`)
  na každé šířce — pole, tlačítko, spouštěč, segment i pilulka; 14 px
  z D108 bylo při ručním ověření malé. `control` a `field` mají stejnou
  hodnotu a zůstávají dva tokeny, protože role se mohou rozejít.
  Kompaktní prvek má 14 px (`body`): tlačítko `sm` z `control-sm`, pole
  v řádku tabulky z `field-sm` — to má na telefonu 16 px, protože iOS při
  fokusu pole s menším písmem stránku přiblíží.
- **Telefon**: tokeny se přepisují v bloku `@media (max-width: 48rem)`
  ve `variables.css` (breakpoint shellu, D42) — komponenty vlastní
  `@media` kvůli výšce nepíšou.
- **Rámeček**: `--shpd-color-border-control` (§3 Borders & focus).
- **Nový ovládací prvek** = sdílená komponenta (`Button`, `Input`,
  `Select`, `SegmentedControl`…). Vlastní `<button>`, `<input>` nebo
  `<select>` v liště bere tytéž tokeny — výšku, písmo i rámeček.
- **Výjimky** s vlastním měřítkem: pole přihlašovací obrazovky a hlavní
  hledání průvodce registrem (záměrně větší; rámeček z tokenu mají),
  příkazová paleta. Ovládací prvek lišty nejsou: záložky, položky nabídek
  a popoverů, vzorky barev, čipy a přepínače o velikosti `meta`.

### Popisky a pole

Rozhodnutí D117–D119 z #157. Platí pro každou mřížku **popisek–ovládací
prvek**: sloupec editačního formuláře (`FormColumn`, `FormFieldRow`,
`FormInline`), stránku Nastavení (`SettingsPage`), změnu hesla
(`ChangePasswordPanel`) a dialog odeslání (`SendDialog`). Mřížky jen ke
čtení (`PropertyList`, souhrny dialogů) se jimi neřídí.

- **Strop sloupce popisků** (D118): `grid-template-columns:
  fit-content(40%) minmax(0, 1fr)`. Sloupec popisků je široký jako
  nejdelší popisek, nejvýš ale 40 % šířky mřížky; delší popisek se zalomí
  a zůstává zarovnaný doprava. `overflow-wrap: anywhere` nedovolí jednomu
  dlouhému slovu strop přetlačit.
- **Zarovnání nahoru** (D118): `align-items: start`, ne účaří. Popisek má
  horní odsazení `calc((var(--shpd-control-height) - 1lh) / 2)` — první
  řádek sedí na svislém středu pole, další řádky pod ním; výraz platí
  i na telefonu, výšku mění token. Pole se k zalomenému popisku
  nenatahuje a víceřádkové pole má popisek u svého prvního řádku
  (s účařím seděl popisek u posledního řádku `TextArea`).
- **Pole, jehož první řádek není vysoký jako ovládací prvek**, se
  dorovná v komponentě pole, ne výjimkou v popisku: `TextArea`
  a `MultiselectInput` dopočítávají svislé odsazení tak, aby první řádek
  (řada čipů) měl střed ve výšce ovládacího prvku; čip adresy
  v `SendDialog` stejným výrazem (adresa na středu, důvod pod ní čip jen
  prodlouží) a řádek přílohy má výšku ovládacího prvku jako minimum.
- **Popisky nad pole** (D117): pod prahem úzkého kontejneru `30rem` má
  mřížka jednu stopu, popisek je vlevo nad polem a nemá horní odsazení.
  Mezera popisek–pole `--shpd-space-xs`, mezi dvojicemi
  `--shpd-space-md`. Kde jsou popisek a pole sourozenci přímo v mřížce
  (`FormColumn`, `SendDialog`), má mřížka `row-gap` `md` a pole se
  k popisku přitáhne záporným horním okrajem `calc(xs − md)` — nepotřebuje
  pravidlo pro první popisek a skrytý prvek nenechá mezeru. Kde dvojici
  drží obal (`SettingsPage`, `ChangePasswordPanel`), má obal `row-gap`
  `xs`. Formulář se ptá pojmenovaného kontejneru `form-section`, takže se
  popisky přepnou ve stejný okamžik jako sloupce sekce; mezera mezi
  sloupci naskládanými pod sebe je také `md`.
- **Skupina polí vedle sebe** (`inline`, D119) se přepíná podle šířky
  sekce, ne okna: na široké šířce popisek prvního pole ve sloupci popisků
  a další pole s malým popiskem před sebou, pod prahem pole pod sebou,
  každé s popiskem nad sebou. Obě podoby jsou jedno DOM — každé pole má
  jeden `<label for>`, který se pod prahem jen přesune nad pole. Na široké
  šířce (D120) jsou položky skupiny buňky mřížky `repeat(auto-fit,
  minmax(min(100%, 12rem), 1fr))`: dokud má každá položka (malý popisek
  + pole) aspoň `12rem`, stojí v jedné řadě stejně široké; v užším sloupci
  (dvousloupcová sekce na tabletu nebo v úzkém modalu) se zalomí na další
  řádek, místo aby se pole smrskla a přetekla do sousedního sloupce.
- **Popisek nad polem na každé šířce** (krátký dotaz v dialogu, pole
  průvodce) má mezi popiskem a polem také `--shpd-space-xs`.

### Volitelné řádky

Rozhodnutí D76, D80 a D81 z #149. Jedna volba ze seznamu je sdílený
`ui/OptionRow.svelte`, ve dvou velikostech podle místa:

- **Velký** (`size="large"`, výchozí) — volba s popisem, která se
  potvrzuje tlačítkem dialogu: odsazení `--shpd-space-md`, zaoblení
  `--shpd-radius-md`, ukazatel volby vždy vlevo (`indicator` `radio`
  výchozí | `checkbox`), název body strong, popis (snippet `description`)
  **body barvou textu**, tloušťka normal (D80), obsah vpravo (snippet
  `aside` — štítky, doplňková volba).
- **Popis nese běžné údaje** — adresu, IČO a DIČ, číslo účtu a měnu —
  a ty jsou podle §11 body barvou textu; od názvu se liší jen tloušťkou.
  Šedá meta patří jen skutečnému doplňku a dává si ji obsah popisu sám
  (vlastní třída uvnitř snippetu), ne `OptionRow`.
- **`aside` jen s obsahem:** rodič snippet předá, jen když má co ukázat
  (`aside={maStitky ? stitky : undefined}`, snippet pojmenovaný vedle
  řádku). Podmínka uvnitř snippetu nechá vpravo prázdný obal s mezerou
  md.
- **Úzká šířka (D81):** velký řádek je pojmenovaný kontejner
  `option-row`. Když je jeho obsah užší než práh úzkého obsahu
  (`@container option-row (max-width: 30rem)`, §8 Responzivita — měří se
  vnitřek řádku bez odsazení), `aside` se přesune pod popis, zarovnaný
  s textem (za ukazatelem volby; řádek bez ukazatele bez odsazení), a název
  i popis jdou přes celou šířku řádku. Svislá mezera je mezera řádku (md).
  Obsah `aside` si pod popisem drží vlastní šířku — ovládací prvek uvnitř
  se neroztáhne přes řádek a klik vedle něj dál volí řádek. Štítky, které
  jsou vpravo pod sebou, si rodič stejným dotazem (jménem kontejneru) řadí
  vedle sebe a nechá je zalomit, když se nevejdou.
- **Kompaktní** (`size="compact"`) — jednořádková volba aplikovaná
  kliknutím (popover): odsazení xs × sm, zaoblení `--shpd-radius-sm`, bez
  ukazatele.
- **Stavy:** základ rámeček `--shpd-color-border` na ploše `bg`; vybraný
  rámeček `--shpd-color-primary-text` (D103, #153 — v tmavém vzhledu 6,07 : 1
  vůči podkladu místo 2,49) + pozadí `--shpd-color-primary-soft`; pod
  kurzorem `--shpd-color-bg-hover` (ne `bg-secondary`, §9); zakázaný
  průhlednost 0,6 a `cursor: not-allowed`; `:focus-visible` obrys 2 px
  `primary` (u kompaktního dovnitř, ať ho rolovací seznam neořízne).
- **Sémantika s ukazatelem:** nativní `<input type="radio|checkbox">`
  v `<label>`; radia jedné skupiny sdílí `name` a rodič jim dá obal
  `role="radiogroup"` s `aria-label`. Label je roztažený přes celý řádek
  (`::after`), klik kamkoli volí. `aside` leží mimo label: jeho
  neinteraktivní obsah klik propustí na řádek, ovládací prvky uvnitř
  (radio „výchozí“ u bankovních účtů) fungují samy — vnořený `<label>`
  by byl neplatný. Ukazatel kreslí `OptionRow` stejnými tokeny jako
  `Checkbox`; sdílený `Checkbox` je sám `<label>` s vlastním okrajem a do
  řádku vložit nejde.
- **Sémantika bez ukazatele:** `<button>` s `role` podle rodiče — `radio`
  (výchozí, `aria-checked`) nebo `option` (`aria-selected`, rodič je
  `listbox`).
- **Řízený:** `selected` drží rodič, `onselect(checked)` hlásí změnu
  (checkbox stav po přepnutí, radio a tlačítko `true`). `data-testid`
  jde na kořen / na tlačítko.
- **Kde:** výsledky hledání v dialogu Přidat firmu z registru (velký,
  radio), převod bankovních
  účtů (velký, checkbox), kód DPH, předmět přenesení a kandidáti partnera
  v náhledu importu (kompaktní, `option`). Štítky uvnitř řádků mají dál
  vlastní třídy — sjednotí je badge systém (§5).
- **Pravidlo:** nový volitelný řádek = `OptionRow`, ne vlastní třída
  s kopií rámečku a stavů.

### Segmentový přepínač

Rozhodnutí D77 a D79 z #149. Jedna volba z několika málo je sdílený
`ui/SegmentedControl.svelte`.

- **Vzhled:** oddělená tlačítka s mezerou `--shpd-space-xs`, která se
  zalamují (`flex-wrap`); tlačítko má běžnou výšku ovládacího prvku
  (`--shpd-control-height`, D106, D110), vodorovné odsazení md, rámeček
  `--shpd-color-border-control` (D109, D111), zaoblení `--shpd-radius-md`,
  pozadí `bg`, písmo `--shpd-font-size-control`; pod kurzorem
  `--shpd-color-bg-hover`. Vybraná volba jako volitelný
  řádek: rámeček `primary` + pozadí `primary-soft`, **tloušťka normal** —
  stav nese barva, ne tučné písmo ani fajfka.
- **Props:** `options` `[{value, label, icon?, disabled?}]` (hodnota
  libovolného typu, i `null` / boolean — nerozhodnuto je legitimní volba),
  `value` (bindable), `onchange(value)`, `label` (`aria-label` skupiny —
  z existujícího textu: popisek pole nastavení, název parametru),
  `disabled`, `size` (jen `md`). `icon` jen tam, kde je součástí popisku
  (vzhled). Řízené použití bez `bind:` — rodič po `onchange` hodnotu
  změní.
- **Sémantika a klávesnice** (D79 — výběr až potvrzením):
  `role="radiogroup"`, tlačítka `role="radio"` s `aria-checked`. Tab
  vstoupí na vybranou volbu (bez výběru na první povolenou); šipky vlevo
  / vpravo (nahoru / dolů) a Home / End přesouvají **jen fokus**, dokola
  přes zakázané volby; volbu vybere mezerník, Enter nebo klik. Roving
  tabindex sleduje fokus a po odchodu ze skupiny se vrací na vybranou
  volbu. Je to záměrná odchylka od ARIA vzoru radio group, který vybírá
  už šipkou: volby tu mají okamžitý účinek (jazyk znovu načte stránku,
  průvodce zdrojem dat ukládá). Nativních radií ve volitelných řádcích
  se netýká — tam výběr potvrzuje tlačítko dialogu.
- **Kde:** vzhled a rozvržení (Nastavení aplikace i účtu — tenké obaly
  `ThemeModeSegments`, `ShellSegments` s konkrétními volbami), jazyk,
  plátce DPH v průvodci zdrojem dat, formát čísel reportu, způsob nahrání
  pošty.
- **Pravidlo:** nový přepínač = `SegmentedControl`, ne vlastní třída;
  barvou výběru je `primary`, nikdy `accent` (§3); rámeček vybraného
  segmentu bere `--shpd-color-primary-text` jako volitelný řádek (D103).

### Nadpis stránky

Rozhodnutí D78 z #149. Stránka, která není prohlížeč ani detail, má
nadpis ze sdíleného `ui/PageHeader.svelte` — vždy `<h1>`.

- **V toku stránky** (`variant="flow"`, výchozí): typografická role
  **page** (`--shpd-font-size-page` = xl, strong; §11), bez pozadí
  a vnějšího okraje — mezeru pod nadpisem dává stránka.
- **V liště** (`variant="bar"`): tvar hlavičky detailu — role title,
  odsazení `--shpd-bar-padding-y` × `--shpd-space-md` (token sdílený
  s `DetailHeader`), pozadí `bg`, linka dole.
- **Ikona** (obdoba D38): z položky navigace, odkud stránka vznikla —
  stejná jako v menu. Aktivní položka navigace nese klíč `icon`
  (`stores/navigation.svelte.js`), stránka ho dostane v `item` a předá
  `item?.icon ? resolveIcon(item.icon) : undefined`. Stránka bez ikony
  v navigaci (ad-hoc cíl) má nadpis bez ikony — žádný fallback. Kreslí se
  jako v `SectionHeading`: `Icon size="md"`, šedá
  `--shpd-color-text-secondary`, mezera sm, dekorace (`aria-hidden`).
- **Obsah vpravo** (snippet `aside` — akce, datum „k“, výběr období):
  sedí vpravo v řádku nadpisu a při nedostatku místa se zalomí pod nadpis
  (`flex-wrap`), nadpis se nestlačuje.
- **Kde:** Dashboard, Přehled firmy, Štítky obsahu, Portál (`flow`),
  reporty (`bar` — výběr období, parametry, formát a export v `aside`).
  Titulek dialogu (`Modal`) a obrazovky s kartou uprostřed mají vlastní
  nadpis.
- **Pravidlo:** nový nadpis stránky = `PageHeader`, ne vlastní `<h1>`
  s velikostí ze škály.

### Obrazovka s kartou uprostřed

Přihlášení, nastavení hesla, schválení OIDC transakce a stavová obrazovka
nedostupného zdroje dat stojí na `ui/CenteredScreen.svelte`, ne na vlastním
obalu: obal přes celou obrazovku (`min-height: 100dvh`, pozadí
`bg-secondary`, odsazení `--shpd-space-md` ze všech stran) a plovoucí
plocha uprostřed (`max-width: 25rem`, odsazení `--shpd-space-xl`, zaoblení
lg, stín — D35). Na telefonu karta zůstává kartou, jen má okraj od krajů
obrazovky; na 360 px se formulář s odsazením `xl` vejde, proto obal
container query nemá. Obsah karty (logo, nadpis, formulář) dává a styluje
obrazovka sama jako `children`.

### Hlášky a pruhy

Rozhodnutí D40 a D41 (task 3 #137), D60 a D61
(`tasks/design-system-notices.md`) z #133. Hláška v obsahu je sdílená
`Notice.svelte`, pruh nad obsahem `PageBanner.svelte` (obě `components/ui/`).

- **Hláška** (D40, `Notice`): tóny `info` / `success` / `warning` /
  `danger`, barvy jen z rodiny `--shpd-color-alert-*` — pozadí `-bg`, text
  `-text`, rámeček 1 px `-bar` v barvě tónu (`success` jsou aliasy na
  existující zelené, `danger` mapuje na `alert-error-*`); zaoblení
  `--shpd-radius-md`, odsazení `--shpd-space-sm` / `--shpd-space-md`, písmo
  body. Bez levého pruhu, bez stínu a **bez vnějšího okraje** — mezeru dává
  rodič (obal se třídou, nebo mezera flex sloupce). Volitelně nadpis
  `title` (strong, velikost body), ikona `icon` vlevo (dekorace) a seznam
  `items` — položka text, nebo `{label, text, meta}` („popisek: text“,
  popisek strong: validace formuláře, zámek dokladu; `meta` za textem
  v roli meta šedě — cesta problému z vytěžení v review, #139 D53); jiný
  obsah dává `children`.
  Role `alert` jen u `danger` (čte se hned — jen chyba), jinak `status`,
  volající ji smí přepsat; `data-testid` a `aria-*` jdou na kořen, `class`
  jen pro stavové třídy, nikdy pro mezery.
- **Pruh nad obsahem** (D41, `PageBanner`): jiný prvek než hláška — celá
  šířka, odsazení xs / lg, pozadí a text `alert-<tón>-*`, linka 2 px dole
  `alert-<tón>-bar`, body strong, na střed; bez zaoblení a bez okraje.
  Tón `warning` výchozí, `info` / `danger` pro budoucí pruhy. Dnes: jen ke
  čtení, kopie reálných dat, cron vypnutý, pojistka pošty — texty a logika
  zůstávají v jejich komponentách, vzhled má jedno místo. Pruhy kreslí
  `ContentArea` v kontejneru `.shpd-content__banners` **nad** rolovací
  oblastí `.shpd-content` (sourozenec ve flex sloupci shellu, ne
  `position: sticky` uvnitř) — jsou stále vidět a obsahu neubírají
  výšku naslepo (#133 D62, řetěz výšky u *Viewer* výše).
- **Hláška, pruh, karta, nebo toast**: hláška patří k místu, kde vznikla
  (formulář, dialog, tab detailu), a trvá, dokud trvá důvod; pruh je stav
  celého zdroje dat nad obsahem; karta s tónem (`FailureCard`, Karty výše)
  je obsah s vlastním nadpisem a akcemi; toast (`GlobalToast`) je pomíjivé
  potvrzení akce. Rámeček 1 px v barvě tónu má jen hláška — karta má
  rámeček `--shpd-color-border`, nebo tón jen v barvě rámečku.
- **Hláška, nebo chyba u prvku** (D61): chyba nebo varování, které patří
  k **jednomu poli, položce seznamu nebo řádku**, zůstává textem u prvku —
  tón nese jen barva textu (`--shpd-color-danger-text`,
  `--shpd-color-warning-text`, D69), bez pozadí a rámečku. **Výsledek akce a stav celého formuláře, dialogu
  nebo panelu** je `Notice`.
  - Chyba u prvku: chyby inputů (`ui/*Input`, `Select`, `TextArea`),
    chyba karty v kontrole front (`QueueCategoriesPrompt`), varování
    u jednotlivého majetku v řádku dialogu odpisů, chyba nahrání obrázku
    (`ImageSlotField`, `AvatarSlotField`), chyba pole v předvyplnění
    registrace DPH a v průvodci nastavení, chyba adresy v dialogu Odeslat,
    chyba hledání u pole (`LookupInput`, rozhodnutí v review), řádek
    reportu se zprávou (`ReportView`, D64 — ikona před názvem; chyba
    i varování barví text řádku, D72). Jejich sjednocení mezi sebou je
    případný další bod.
  - Hláška: uložení Nastavení, změna hesla, limit souborů a chyba nahrání
    pošty, chyba založení zdroje dat, chyba chatu (nad polem zprávy, mimo
    rolovací oblast zpráv), varování průvodce nastavení (jedna hláška se
    seznamem), blokace zaúčtování odpisů (jedna hláška se seznamem), chyba
    odeslání, stav reportu nad tabulkou (`ReportView`, D64).
  - Ani jedno: varování na kartě a řádku feedu je součást karty, text
    potvrzovacího dialogu je tělo dialogu.

  Rodina `--shpd-color-alert-*` **je** vzhled hlášky — použít ji
  (`var(…)`) smí jen `Notice` a `PageBanner`. Kdo ji potřebuje jinde,
  kopíruje `Notice`. Hlídá `scripts/check-css-tokens.py` v pre-commitu;
  jedinou výjimku má vyjmenovanou: vývojářský dashboard s vlastním
  vzhledem (D12).
- **Chyba načtení místo obsahu** (D60): když chyba **nahrazuje obsah**
  (seznam, detail, modal, stránka), je to `Notice` v tónu `danger` nahoře
  v oblasti, kde by obsah byl — ne vycentrovaný červený text ve volné
  ploše. Odsazení od krajů dává obal oblasti (`--shpd-space-md`, nemá-li
  oblast vlastní — modal `flush`, prohlížeč tabulek). „Zkusit znovu“,
  pokud ho místo má, je `Button` `secondary` `sm` uvnitř hlášky pod textem
  (Přehled firmy, portál, katalog reportů). Chyba při obnovení, kdy stará
  data zůstávají na obrazovce, obsah nenahrazuje a sem nepatří.
- **Pravidlo:** nová hláška = `Notice`, nový pruh = `PageBanner`, ne
  vlastní třída s kopií pozadí a rámečku. Dnes na `Notice` stojí chyba
  a potvrzení přihlášení, chyby OP přihlášení a nastavení hesla, relace
  účtu, bannery formuláře (chyba načtení, validace, varování), chyba
  sub-tabulky, chyby reportů, hlášení náhledu tisku, zámek dokladu,
  pojistka pošty, nálezy návrhu a chyba odeslání v dialogu Odeslat, blok
  `notice` v detailu (§12), nálezy review dokladu, výsledek uložení
  Nastavení a změny hesla, hlášky nahrání pošty, nového zdroje dat
  a chatu, varování průvodce nastavení, příznak odeslání přílohy,
  transport odeslané zprávy, dialog odpisů, chyby akcí v Nastavení
  (registrace DPH, bankovní účet, štítky obsahu, nabídka položek, průvodce
  registru), chyby načtení podle D60 a **zprávy sekcí Přehledu firmy** —
  každá zpráva vlastní hláška s tónem podle závažnosti (`info` / `warning`
  / `error` → `danger`), odkaz *Otevřít* uvnitř vpravo od textu, při
  nedostatku místa pod ním; seznam zpráv zůstává `<ul>`.

### Modální dialogy

Všechny modální dialogy v aplikaci používají sdílenou `Modal.svelte` komponentu —
včetně vnořených dialogů (modál nad modálem). Otevřený modal je vrstva sdíleného
zásobníku (*Zavírání vrstev* níže), takže Esc zavře jen naposledy otevřenou
vrstvu; body scroll lock se uvolňuje až při zavření posledního modalu.

**Konvence**:
- Overlay `var(--shpd-color-overlay)` (40 % černá v light, 65 % v dark)
- Tlačítka v patce přes snippet `footer` + `<Button>` komponentu
- Title + volitelný `headerExtra` snippet pro badge/stav v hlavičce
- Width default `40rem`, lze předělat (`width="50rem"`, `width="30rem"` atd.)
  — vždy v rem (D46, §10 Pevné rozměry); px v propu zastaví pre-commit
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
- **Zavírací tlačítko patičky** (#155 D116): tlačítko, které dialog **jen
  zavírá** — Zavřít / Zrušit se stejným účinkem jako × v hlavičce a Esc,
  včetně stráže neuložených změn — nese prop `dismiss`
  (`<Button dismiss … />`, značka `data-modal-dismiss`, žádná vlastní
  logika kliku). Na telefonu ho `Modal` skryje: zavírá × a místo v patičce
  zůstává akcím. Zbude-li v patičce jen zavírací tlačítko (dialog po
  odeslání e-mailu, po zaúčtování odpisů), zmizí na telefonu celá patička.
  Pravidlo je **jen v `Modal.svelte`** (`@media` shellu, D42) — dialog
  kvůli němu `isMobile` nečte a tlačítko nevynechává sám. Skrývá se přes
  potomky patičky (tlačítko smí být v obalu snippetu), prázdná patička se
  pozná podle přímých dětí. Mimo telefon beze změny.
  - **Výjimka — potvrzovací dotaz**: `ConfirmDialog`, dotaz na důvod
    zamítnutí (`RejectReasonPrompt`) a jiný dotaz s odpovědí ano / ne
    (smazání konverzace chatu). Zrušit je tam **odpověď na otázku**, ne
    zavření — `dismiss` nenese a na telefonu zůstává.
  - **Neoznačuje se** tlačítko, které dělá víc než zavření: vrací krok
    průvodce (Zpět v importu z registru), ruší běžící operaci, zahazuje
    rozpracovaná data jinak než ×.

**Použití**:
```svelte
<Modal title="Nahrát do došlé pošty" {open} onClose={close} width="32.5rem">
  <p>Obsah dialogu</p>
  {#snippet footer()}
    <Button label="Zrušit" variant="secondary" dismiss onclick={close} />
    <Button label="Nahrát" onclick={submit} />
  {/snippet}
</Modal>
```

### Zavírání vrstev

Rozhodnutí D121–D124 z #160. **Vrstva** je všechno, co se dá zavřít: modal,
bublina (`Popover`, kebab, panel rozhodnutí, výběr období), nabídka pole
(lookup, výběr více hodnot), detail v draweru, boční chat, panel vzhledu,
uživatelské menu, paleta příkazů a drawer sidebaru na telefonu.

Vrstva je buď **dočasná** — otevře se pro jednu volbu a klik mimo ji
zavře —, nebo **nedočasná**. Dočasnost je vlastnost vrstvy, ne jejího
druhu (`panel` je dočasný panel vzhledu i nedočasný detail v draweru):

| dočasná (`transient: true`) | nedočasná (`transient: false`) |
|---|---|
| bublina `Popover` — i `KebabMenu`, panel rozhodnutí, výběr období | `Modal` (zavírá ho klik na ztmavené pozadí) |
| nabídka `LookupInput` a `MultiselectInput` | detail v draweru `ViewerDetailDrawer` |
| uživatelské menu `UserMenu` | boční chat `ChatPanel` |
| panel vzhledu `ThemePanel` (na telefonu je to `Modal`) | drawer sidebaru na telefonu (zavírá ho klik na překryv) |
| paleta příkazů `CommandPalette` | |

- **Esc zavře jednu vrstvu — tu naposledy otevřenou** (D121). Další stisk
  zavře vrstvu pod ní. Esc v otevřené nabídce lookupu zavře nabídku,
  formulář až dalším stiskem; Esc v panelu rozhodnutí zavře panel, náhled
  dokladu zůstává; paleta otevřená nad modalem zavře jen sebe.
- **Jeden stisk = jedna vrstva**, i když zavření otevře další: Esc na
  formuláři se změnami otevře dotaz „Neuložené změny“ a týž stisk ho
  nezavře. Držená klávesa další vrstvy nezavírá.
- **Klik mimo zavírá jen dočasné vrstvy** (D122): bubliny, nabídky polí,
  uživatelské menu, panel vzhledu, paletu a drawer sidebaru (klik na
  ztmavený překryv). **Postranní panely** — detail v draweru a boční chat —
  klik mimo nezavírá, pracuje se vedle nich. Modal zavírá klik na ztmavené
  pozadí.
- **Boční chat** zavírá × a Esc (D123), i při psaní do pole zprávy;
  rozepsaný text zavření přežije (žije v `chatStore`, maže ho odeslání).
- **Vložené dokumenty při otevřené dočasné vrstvě klepnutí nepřijímají**
  (D124, doplňuje D122). Klepnutí do `<iframe>` — PDF v náhledu dokladu,
  text e-mailu, velké náhledy příloh, náhled tisku — by jinak zůstalo
  uvnitř vloženého dokumentu a vrstvu by nezavřelo. Dokud je dočasná vrstva
  otevřená, klepnutí na dokument dopadne na stránku pod ním a zavře vrstvu
  jako klepnutí kamkoli jinam; **další klepnutí už do dokumentu projde**.
  Bez dočasné vrstvy se vložené dokumenty chovají jako vždy — i vedle
  otevřeného detailu v draweru a bočního chatu.

**Implementace** — jeden zásobník `utils/layerStack.js` a jeden posluchač
`keydown` na `window`. Komponenta vrstvy se do něj zapíše na dobu otevření
a **vlastní posluchač Esc nemá**:

```js
import { openLayer } from '../../utils/layerStack.js';

$effect(() => {
  if (!open) return;
  return openLayer({ kind: 'popover', transient: true, onEscape: () => onClose() }).remove;
});
```

**`transient` je povinné u každé vrstvy** — bez něj `openLayer()` vyhodí
výjimku a `tests/Unit/layerTransientUsage.test.mjs` zastaví `npm test`.
Dokud je otevřená dočasná vrstva, drží zásobník na `<html>` atribut
`data-transient-layer` (nastaví ho i uklidí sám, i když vrstva zmizí ze
středu zásobníku) a jediné pravidlo ve `styles/base.css` podle něj vypne
klepnutí do vložených dokumentů:

```css
html[data-transient-layer] iframe { pointer-events: none; }
```

Komponenta vrstvy ani komponenta s `<iframe>` proto klepnutí do dokumentu
neřeší. Dvě upřesnění:

- **Dočasná vrstva pod později otevřeným modalem se nepočítá**
  (`hasTransientLayer()`): panel rozhodnutí zůstává otevřený pod vnořeným
  formulářem z lookupu a kliky v něm záměrně ignoruje — vložený dokument
  ve formuláři by jinak klepnutí nepřijal a žádné klepnutí by ho neodemklo.
- **`<iframe>` uvnitř dočasné vrstvy** by pravidlo muselo vynechat, jinak
  by v něm nešlo klepnout. Žádný takový není; kdo ho přidá, doplní
  výjimku do selektoru.

Kolečko nebo tah prstem nad dokumentem při otevřené dočasné vrstvě posouvá
jeho okolí, ne dokument (PDF v náhledu dokladu okolí k posunutí nemá, takže
se neposune nic) — vrstva se prvním klepnutím zavře a dokument se posouvá
zase sám. Nabídka lookupu zavírá už na stisk tlačítka myši (`mousedown`):
vrstva zmizí dřív, než tlačítko pustíš, a týž klik v dokumentu nic
nespustí.

`openLayer()` vrací `remove()` (úklid efektu — vrstva zmizí při zavření
i při zničení komponenty), `isTop()` (je naposledy otevřená — `Modal` podle
něj pouští `onKeydown`, takže Alt+←/→ nepřepne záznam pod otevřenou bublinou
nebo paletou) a `depth` (pořadí mezi vrstvami téhož druhu — zmenšení
vnořených modalů). Druhy: `modal`, `popover`, `menu`, `panel`, `palette`,
`drawer`.

**Editace textu, která Esc ruší sama** (přejmenování přílohy), zůstává
lokální a stisk označí `e.preventDefault()` — zásobník Esc
s `defaultPrevented` nechá být, takže se zároveň nezavře formulář. Výjimky
jsou vyjmenované s důvodem v `tests/Unit/escapeUsage.test.mjs`, který
v `npm test` zastaví každé další čtení klávesy `'Escape'` mimo zásobník.

Meze: nativní nabídka prohlížeče (`<select>`, výběr data) si Esc řeší sama
a do aplikace ho nepustí; totéž platí pro fokus uvnitř `<iframe>` (náhled
PDF) — tam Esc vrstvu nezavře. Vývojářský dashboard má vlastní `DevDialog`
s vlastním posluchačem; sdílený `Popover` v něm jde přes zásobník
a pravidlo pro vložené dokumenty platí i tam (`base.css` je sdílený).

### Akce na telefonu

Rozhodnutí D113–D115 z #155. Lišta akcí, která se na telefon nevejde,
se **nezalamuje do dvou řádků** a tlačítka se nezmenšují: viditelná zůstane
hlavní akce a ostatní jdou do kebabu ⋮.

- **Kebab je jedna komponenta** — `ui/KebabMenu` (D114): tlačítko ⋮
  (čtverec výšky ovládacího prvku s rámečkem, `flex: none`) a bublina
  `Popover` s položkami `{key, label, danger?, disabled?, testid?, run}`.
  Bez položek se nevykreslí. Klik na položku bublinu zavře a zavolá
  `run()`; druhé ťuknutí na ⋮ bublinu zavře. `placement` `top` (lišta
  dole, výchozí) nebo `bottom`; `variant="plain"` je tlačítko bez rámečku
  v barvě lišty pro horní lištu na barvě sidebaru (rozměr a hover dává
  lišta přes `--shpd-kebab-size` / `--shpd-kebab-hover-bg`). Popisek
  tlačítka je „Další akce“.
- **Co jde do kebabu**: destruktivní akce (v menu červeně, `danger` — tón
  `danger-text`) a vedlejší akce. **Viditelná zůstává hlavní akce** —
  zelená / primární, ta, kterou uživatel na obrazovce nejčastěji dokončuje
  — a postupové akce, kterých je v daném stavu pár. Neaktivní akce se
  v kebabu ukáže neaktivní, ne skrytá; položka nese stejný `data-testid`
  jako tlačítko na desktopu. Zavírací tlačítko dialogu do kebabu
  **nepatří** — na telefonu ho skrývá `Modal` (D116, výše).
- **O telefonu rozhoduje `layoutStore.isMobile`** (D115) — breakpoint
  shellu, tentýž, při kterém `Modal` roztáhne patičku. Je to strukturní
  přepnutí (tlačítka → kebab) v šabloně, ne CSS a ne container query.
  Výčet akcí kebabu patří volajícímu, ne komponentě kebabu.
- **Pořadí**: hlavní akce vlevo, ⋮ na pravém konci lišty.
- **Vzory**: stavová lišta formuláře (`FormStateBar` — Uložit a postupové
  přechody viditelné, destruktivní, `concept` a `mobileKebab` v kebabu),
  horní lišta detailu (`MobileTopBar`, `plain`, `bottom`) a patička
  náhledu dokladu z pošty (D113, §12 Review): **Vystavit a uzavřít** (u
  Spisovny **Zařadit**) a ⋮ s **Vystavit koncept**, **Zamítnout**
  a ve frontě **Přeskočit** — výčet v `exchange/reviewFooter.js`.
- Vývojářský dashboard má vlastní `DevKebabMenu` (sdílí jen vybrané
  komponenty).

### Responzivita

Rozhodnutí D42, D43 a D47 z #133 (#138 task 1) a D58 (#138 task 5 —
úzký viewer se seznamem, níže):

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
| `30rem` (480 px) | `@container` | úzký obsah: sloupce pod sebe, popisky nad pole a skupina `inline` pod sebe (Popisky a pole výše, #157 D117, D119), strany dokladu pod sebe, patička dialogu zalomená, obsah vpravo volitelného řádku pod popis (`option-row`, #149 D81) |
| `36rem` (576 px) | `@container` | složené řádky dokladu (`DocRows`, pojmenovaný kontejner `doc-rows`, #139 D45) |
| `55rem` (880 px) | JS, šířka vieweru (`VIEWER_SPLIT_MIN`) | viewer se seznamem: užší viewer má seznam přes celou šířku a detail v draweru (D58) |

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
  sloupci, ne podle nejbližšího kontejneru. Stejně `DocRows` (#139): kořen
  má `container-name: doc-rows` a dotaz `@container doc-rows (max-width:
  36rem)`, protože uvnitř detailu je kontejnerem i `.shpd-docdetail`
  (strany pod sebe) a nepojmenovaný dotaz by se ptal jeho. A `OptionRow`
  (#149 D81): velký řádek má `container-name: option-row`; ptá se ho
  sám (`aside` pod popis) i rodič, který do `aside` vkládá štítky
  (`RegistryImportWizard`) — řádek bývá v `Modal` a uvnitř dalších
  kontejnerů.
- Obsah vykreslený snippetem `Modal` (patička přes `footer`) je mimo kořen
  komponenty — kořenový kontejner na něj nedosáhne. Takový kus dostane
  vlastní obal na celou šířku snippetu (`PrintPreviewDialog`:
  `.shpd-print__footer-box` s `flex: 1; min-width: 0`), řádek až uvnitř.
- Kontejner nemění chování absolutně a fixně polohovaných vrstev uvnitř
  (lookup, select, `Popover`) — `container-type` zavádí inline-size
  a style containment, ne layout containment; ověřeno v Chromiu (lookup
  dropdown ve `FormSection` sedí pod polem a není ořezaný).
- Vzory: `PropertyGroups` (kontejner na obalu mřížky), `DocumentDetail`
  (strany dokladu), `SettingsPage`, `SendDialog`, `FormSection`,
  `OptionRow` (pojmenovaný kontejner `option-row` přímo na kořeni řádku —
  kořen má `width: 100%`; `flex-wrap` je na kořeni mimo dotaz, dotaz mění
  jen potomka `aside`).

**Nová hodnota = nové rozhodnutí v #133.** Kontrola v pre-commitu
(`scripts/check-css-units.py`, §10) odmítne `@media` s rysem šířky mimo
hodnotu shellu, `@container` mimo prahy výše i JS konstantu, která
s CSS neladí nebo má jinou hodnotu než rozhodnutí (`MOBILE_BREAKPOINT`,
`VIEWER_SPLIT_MIN`); značka `px-ok` na breakpointy neplatí.

**Při větším písmu prohlížeče roste i shell a panely** (D46, #138 task 4).
Layout tokeny, šířka seznamu vieweru, drawer, modaly a popovery jsou v rem
(§10 Pevné rozměry), takže s písmem 20 px má sidebar 325 px místo 260
a seznam 500 px místo 400 — text i rozvržení se škálují spolu, stejně jako
breakpoint shellu. Modal a drawer drží v okně strop podle okna
(`max-width: calc(100vw - …)`, `90vw`, `max-height: 90vh`). Detailu
vedle seznamu by tak v okně těsně nad hranicí shellu zbylo ještě méně
místa než dřív (okno 1024 px, písmo 20 px, sidebar shell: 199 px) — řeší
to úzký viewer níže (D58).

**Viewer se seznamem má tři režimy** (D58, #138 task 5). Těsně nad
hranicí shellu zabere navigace a seznam (`25rem`) skoro celou šířku —
v sidebar shellu při okně 800 px by detailu zbylo asi 140 px, při 1024 px
asi 364 px. Viewer s layoutem `list` proto přepíná podle **své šířky, ne
okna** (obsah reaguje na svůj kontejner, D43):

| Režim | Kdy | Seznam a detail |
|---|---|---|
| mobil | okno pod `48rem` (D42) | přepínání seznam / detail přes celou šířku, akce v top baru |
| úzký viewer | okno nad `48rem`, viewer užší než `55rem` | seznam přes celou šířku, detail v draweru (`ViewerDetailDrawer`) jako u tabulkových seznamů — akce detailu v hlavičce draweru, horní lišta v kontextu seznamu |
| široký viewer | viewer `55rem` a širší | seznam `25rem` a detail vedle sebe |

Práh `55rem` = seznam `25rem` + nejužší rozumný detail `30rem` (práh
úzkého obsahu D43); viewer široký přesně `55rem` úzký není. Je to JS
konstanta `VIEWER_SPLIT_MIN` ve `stores/layout.svelte.js`, ne media
query: `Viewer.svelte` měří `.shpd-viewer__body` přes `ResizeObserver`
a práh přepočítává z rem podle aktuálního písma kořene při každém měření
(`utils/viewerSplit.js`). Drawer je `position: fixed` a šířku vieweru
nemění, takže na hraně nic nekmitá. Tatáž změřená šířka je horní mezí
šířky draweru (`max-width: min(90vw, var(--shpd-viewer-width, 90vw))`,
vlastnost nastavuje `Viewer.svelte` na kořeni draweru): drawer `35rem`
nikdy nepřesáhne přes navigaci vlevo od vieweru — v sidebar shellu při
okně 800 px má viewer 540 px a drawer právě tolik. Platí i pro drawer
tabulkových seznamů. Platí stejně ve všech shellech — práh
přijde při jiné šířce okna (sidebar shell 1140 px, classic a wild s užším
levým pásem dřív, sbalený sidebar také) — i při větším písmu (písmo 20 px,
okno 1280 px, sidebar shell: viewer 955 px < 1100 px → drawer).

Mění se jen **umístění detailu**: řádky seznamu zůstávají kartami, dotaz
se nemění a nic se nenačítá znovu. Výběr přežívá přepnutí — zúžení okna
otevře drawer se stejným záznamem, rozšíření vrátí detail do panelu;
zavření draweru (křížek, Escape) výběr ruší a prázdný stav „Vyberte
záznam“ se v úzkém režimu neukazuje. Tabulkové seznamy (layout `grid`)
mají detail v draweru vždy (`docs/viewer-grid.md` §6). Zamítnuto: pružná
šířka seznamu (při okně 800 px detail pořád úzký) a automaticky sbalený
sidebar (jen jeden shell, druhá hodnota media query shellu).

**Stránka ani panel nerolují do strany — posune se tabulka ve svém obalu**
(D44, D57). Tabulky jsou dvojího druhu: **tabulka v toku obsahu** (detail,
nastavení, dialogy, chat, vývojářský dashboard) má sdílený obal
`TableScroll` s posunem jen do strany; **datová mřížka s držící
hlavičkou** (seznam vieweru, prohlížeč tabulek, report, sub-tabulka
formuláře) má obal, který zabere zbytek výšky své oblasti a roluje v obou
osách — `TableScroll fill`, u seznamu vieweru a prohlížeče tabulek vlastní
obal. Podrobnosti §12 Tabulka; složené řádky dokladu pod `36rem` §12 Úzká
šířka. Položka mřížky nebo flexu, která nese tabulku, potřebuje
`min-width: 0` — jinak se roztáhne podle tabulky a do strany roluje
stránka.

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
- **Primary jako plocha a jako text jsou v tmavém vzhledu dvě hodnoty**
  (D92): ztlumená plocha `#2d6890` nese bílý text (6,0 : 1), ale sama
  jako text na tmavém pozadí dá 2,1–2,8 : 1. Text, ikona, odkaz a obrys
  fokusu berou `primary-text` `#7fb2dc` (6,6 : 1 na `bg`). Modrá tečka
  Nové položky (`info-mark`) má z téhož důvodu vlastní hodnotu `#3b82f6`
  a na `primary` neodkazuje (D93).
- **Brand oranžová zůstává podobná** (jen lehce světlejší `#ff7a26`) —
  funguje na obou módech.
- **Doc-state pruhy zůstávají stejné** — jasné barvy (žlutá, fialová,
  červená) fungujou na tmavém pozadí bez úpravy.
- **Tónové plochy zůstávají stejné, světlejší je jen tónový text** (D70) —
  tlačítko Smazat má v obou vzhledech `#dc2626` s bílým textem; text
  a ikona tónu na tmavém pozadí berou `-text` (`#f87171`, `#f59e0b`,
  `#22c55e`) a značka bez textu (`-mark`, D74) na tytéž hodnoty odkazuje —
  světlejší značku má jen světlý vzhled. Plocha použitá jako barva textu
  by na tmavém pozadí dala jen kolem 3 : 1.
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
v rem** — tokenem, nebo rem literálem; **px zůstávají jen čarám, okrajům,
obrysům, stínům a stavovému / aktivnímu proužku** (linka má být 1 px bez
ohledu na písmo). Totéž platí pro **pevné rozměry a layout tokeny** (D46,
#138 task 4) — viz *Pevné rozměry a layout tokeny* níže. Breakpointy řeší
§8 *Responzivita* (`@media` jen shell v `rem`, obsah přes `@container`,
#138 task 1).

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
| `--shpd-space-sm` | `0.5rem` | 8 | vodorovné odsazení polí |
| `--shpd-space-md` | `1rem` | 16 | mezera mezi bloky, odsazení obsahu, vodorovné odsazení segmentu a pilulky filtru |
| `--shpd-space-lg` | `1.5rem` | 24 | tělo dialogu, strany sekce formuláře, vodorovné odsazení tlačítka `md` (D110) |
| `--shpd-space-xl` | `2rem` | 32 | velké odsazení stránky |
| `--shpd-control-height` / `-sm` | `2.25rem` / `1.75rem` (telefon `2.5rem` / `2rem`) | 36 / 28 (40 / 32) | výška ovládacího prvku — běžná a kompaktní (§8 Výška ovládacího prvku) |
| `--shpd-form-section-gap` | `0.5rem` | 8 | mezera mezi sekcemi formuláře |
| `--shpd-form-section-padding-y` | `0.5rem` | 8 | svislé odsazení sekce formuláře |
| `--shpd-radius-sm` | `0.25rem` | 4 | inputy, štítky, drobné prvky |
| `--shpd-radius-md` | `0.375rem` | 6 | tlačítka, karty |
| `--shpd-radius-lg` | `0.5rem` | 8 | dialogy, panely |
| `--shpd-radius-full` | `9999px` | — | pilulka: čipy, štítky stavů |
| `--shpd-font-size-xs` … `-xl` | `0.75` / `0.875` / `1` / `1.125` / `1.25rem` | 12 / 14 / 16 / 18 / 20 | škála velikostí písma |
| `--shpd-font-size-page` / `-title` / `-heading` / `-body` / `-meta` | `xl` / `lg` / `base` / `sm` / `xs` | 20 / 18 / 16 / 14 / 12 | typografické role (§11) |
| `--shpd-font-size-control` / `-field` | `base` / `base` | 16 / 16 | písmo běžného ovládacího prvku a pole (§8 Výška ovládacího prvku) |
| `--shpd-font-size-control-sm` / `-field-sm` | `body` / `body` (telefon `body` / `base`) | 14 / 14 (14 / 16) | písmo kompaktního tlačítka a pole v řádku tabulky (D110) |
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
- **V px zůstává:** `border`, `outline`, `box-shadow`, čára (`height: 1px`)
  a stavový / aktivní proužek (`::before` 3 px / 4 px / 6 px) — u rozměru
  se značkou `px-ok`; drobné polohování (`top`, `left`, `inset` o pár px),
  `letter-spacing`, `transform`; `line-height` bez jednotky. Breakpointy
  `@media` a `@container` jsou v `rem` (§8 Responzivita).

### Pevné rozměry a layout tokeny

Rozhodnutí D46 z #133 (#138 task 4): **pevné rozměry odvozené od textu
a ovládání jsou v rem** — šířky panelů, modalů, popoverů a sloupců,
`max-width` textu a formulářů, výšky ovládacích prvků, avatary, značky
a tečky u textu, `min-height` míst držících výšku obsahu — a stejně
**layout tokeny**. Převod byl 1 : 1 (px / 16, bez zaokrouhlení: `260px` je
`16.25rem`, ne `16rem`); při výchozím písmu 16 px se nic nezměnilo.

| Token | Hodnota | px při 16 px |
|---|---|---|
| `--shpd-sidebar-width` | `16.25rem` | 260 |
| `--shpd-sidebar-width-collapsed` | `3rem` | 48 |
| `--shpd-header-height` | `3.5rem` | 56 |
| `--shpd-classic-strip-width` | `4.75rem` | 76 |
| `--shpd-rail-width` | `4.75rem` | 76 |
| `--shpd-bar-padding-y` | `0.75rem` | 12 |

`--shpd-bar-padding-y` je svislé odsazení lišty nad obsahem — sdílí ho
hlavička detailu (`DetailHeader`) a nadpis stránky v liště (`PageHeader`
`bar`, D78).

- **Šířka modalu a popoveru je prop v rem**: `<Modal width="30rem">`,
  `<Popover width="25rem">`, výchozí `Modal` `40rem`. Obsahové modaly
  (`FormDialog`, `ViewerDetailModal`) mají jednotně
  `clamp(75rem, 80vw, 106.25rem)` × `clamp(45rem, 88vh, 68.75rem)`.
- Hodnota shodná s tokenem mezery se tokenem píše jen tam, kde **jde
  o mezeru**; šířka prvku `1.5rem` zůstává literál, ne `--shpd-space-lg`.
- Uvnitř `min()`, `max()`, `clamp()`, `calc()` a `flex` totéž:
  `max-width: min(90vw, 35rem)`, `flex: 1 1 17.5rem`; sloupce mřížky
  (`grid-template-columns: 11.25rem minmax(0, 20rem)`) taky, i když je
  kontrola nehlídá.
- **Rozměry podle okna** (`vh`, `dvh`, `vw`) se nemění; **ikony** zůstávají
  v `em` (§10 výše), atributy `width` / `height` na `<svg>` a `<img>`
  a rozměry v JS (grafy) jsou mimo pravidlo.
- **Rozměr složený v JavaScriptu.** JS smí hodnotu jen dosadit do CSS, nebo
  měřit vykreslený prvek (`getBoundingClientRect`, `clientHeight` — px je
  tam správně: polohy `Popover`, výška lišty dashboardu). Číslo v px se
  nesmí sčítat s rem řetězcem. Šířka sloupce z backendu (`col.width` v px,
  kontrakt se nemění) jde do CSS jako `px / 16` rem (`colStyle`
  ve `ViewerGrid` a `FormSubTable`). Odsazení vnořeného modalu (30 px na
  úroveň, `Modal.cardStyle`) je px uvnitř `calc()` — rozlišuje hierarchii,
  s písmem neroste.
- **Poloha, která stojí na převedeném rozměru, jde do rem s ním**: panel
  motivu pod horní lištou (`top: calc(var(--shpd-header-height) +
  var(--shpd-space-sm))`), toast nad ChatLauncherem, odznak v railu.
- **Hraniční případy** rozhoduje otázka „má růst s písmem?“: fajfka
  zaškrtávátka, tečky stavu a průběhový pruh dashboardu ano (rem);
  tloušťka posuvníku ne (px se značkou).

V px se značkou `px-ok` zůstává 19 rozměrů: čáry 1 px (oddělovače menu,
flyoutu, dropdownu, formuláře, tabů), aktivní proužek 3 px položek navigace,
stavový proužek 4 px / 6 px karet a řádků, tloušťka posuvníku lišty tabů
a dva okraje ve výšce výběru barvy.

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
height: 1px; /* px-ok: čára */
```

Značka bez důvodu je chyba. Každou výjimku uveď v popisu commitu; v mezerách
a písmu je výjimek nula, v rozměrech jen čáry a proužky (výše). Ruční
spuštění: `python3 scripts/check-css-units.py` (exit 1, výpis `soubor:řádek:
deklarace` na stderr). Styly vkládané z JavaScriptu kontrola nevidí —
základní CSS iframe e-mailu v `SandboxedHtml.svelte` (vlastní dokument, kde
px nevadí), styl složený v JS (`colStyle`) a výchozí hodnotu propu
(`width = '40rem'` v `Modal`); ty hlídá jen tato kapitola.

Po převodu D46 hlídá tentýž skript i **rozměry** (D47):

- vlastnosti `width`, `height`, `min-*` / `max-*`, `inline-size`,
  `block-size`, `flex-basis` a zkratku `flex` — i uvnitř `min()`, `clamp()`
  a `calc()`. `border-width`, `outline-width`, `stroke-width`,
  `column-rule-width` ani `line-height` rozměry nejsou a neprojdou sem;
- **rozměrové propy komponent** v šabloně `.svelte`: `width="…px"` /
  `height="…px"` (i `width={'…px'}`) na značce s velkým počátečním
  písmenem; `<svg width="16">` a `<img>` projdou. Značka `px-ok` tu smí
  být i jako HTML komentář na stejném řádku;
- **layout tokeny**: custom property `--shpd-*-width*` / `--shpd-*-height*`
  s px ve `styles/variables.css` (ostatní tokeny s px — stíny,
  `--shpd-radius-full` — projdou).

Tentýž skript hlídá **breakpointy** (D47): `@media` s rysem šířky
(`width`, `min-width`, `max-width`, i rozsahový zápis `width <= …`) smí mít
jen hodnotu shellu — `48rem` v aplikaci, `68.75rem` ve `frontend/src/dev/`;
rysy bez šířky (`hover`, `pointer`, `prefers-*`, `print`) projdou.
`@container` smí mít jen `30rem` a `36rem`. `MOBILE_BREAKPOINT`
v `stores/layout.svelte.js` musí být řetězec rovný hodnotě shellu, bez
jednotky za dosazením do `matchMedia`; `VIEWER_SPLIT_MIN` tamtéž (práh
úzkého vieweru, D58) musí být řetězec `55rem`. Povolené hodnoty jsou konstanty
nahoře ve skriptu; značka `px-ok` tu neplatí — jiná hodnota je nové
rozhodnutí v #133 (§8 Responzivita).

---

## 11. Typografie

Pravidla D6, D15 a D16 z #133, zavedená pilotem #135 (detail dokladu).
**Pět velikostí, dvě tloušťky, žádné verzálky.** Role jsou tokeny ve
`variables.css` a odkazují na škálu `--shpd-font-size-*`; žádná nová
velikost nevzniká — pátou roli page (#149 D78) nese `xl`, který ve škále
je. Základní text je **14 px** (`sm`): D6 původně mířilo na
16 px, při ověření pilotu se ukázalo přestřelené a 14 px se drží všude
dál — proto se role mění na jednom místě. Nadpis (heading) je od #137
task 2b **16 px** (`base`): do té doby měl velikost body a lišil se jen
tloušťkou, při ověření v prohlížeči byl název karty feedu a mezinadpis
v kartě Nastavení moc malý, a rozdělení proběhlo tím jedním místem.

| Role | Token | Velikost | Tloušťka | Barva | Kde |
|---|---|---|---|---|---|
| page | `--shpd-font-size-page` | `xl` (1.25rem) | strong | text | nadpis stránky v toku (`PageHeader` — Dashboard, Přehled firmy, Štítky obsahu, Portál; D78). Nadpis stránky v liště (reporty) je title |
| title | `--shpd-font-size-title` | `lg` (1.125rem) | strong | text | titulek detailu, řádek Celkem, nadpis karty jako samostatné části stránky (Přehled firmy, karty Nastavení — D36), hlavní částka karty (Přehled firmy, feed) |
| heading | `--shpd-font-size-heading` | `base` (1rem) | strong | text | nadpis sekce (detail i feed dashboardu), nadpis karty uvnitř obsahu, název entity v kartě, název karty feedu, mezinadpis v kartě Nastavení |
| body | `--shpd-font-size-body` | `sm` (0.875rem) | normal (hlavička sloupce strong) | text | popisky i hodnoty, řádky tabulek i hlavička sloupce (ve velikosti řádků, silnější), taby, podtitul hlavičky detailu (D56) |
| meta | `--shpd-font-size-meta` | `xs` (0.75rem) | normal | text; šedá (`--shpd-color-text-secondary`) jen u kontaktů a doplňků | štítky, kontakty, přepočet v domácí měně |

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

### Datum a čas

Datum na obrazovce má **jeden zápis podle jazyka rozhraní** (D73):

| Jazyk | Datum | Datum s časem |
|-------|-------|---------------|
| čeština | `01.01.2026` | `01.01.2026 14:05` |
| angličtina (a každý jiný jazyk) | `2026-01-01` | `2026-01-01 14:05` |

Oba zápisy mají pevnou délku (data ve sloupci pod sebou „neskáčou“)
a jsou jednoznačné — anglické rozhraní nedostane americký ani český zápis.
Platí pro detail, seznam, mřížku, review, report na obrazovce, hlavičku
formuláře i sub-tabulku.

Zápis tvoří jedno místo v backendu a jedno ve frontendu:

- **Backend** — `Shipard\Core\I18n\DisplayDate::date()` / `dateTime()`
  s jazykem požadavku. Viewer volá `TableViewer::formatDate()` /
  `formatDateTime()`, formulář `TableForm::formatDate()` /
  `formatDateTime()`, buňka sub-tabulky `SubtableCellFormatter::date()`
  (jazyk povinný), hlavička detailu `RecordPattern` (jazyk od vieweru).
  Formuláři a lookupu dává jazyk registr (`FormRegistry::setLanguage()`,
  `LookupRegistry::setLanguage()`).
- **Frontend** — `utils/formatDate.js`: `formatDate(value)`
  a `formatDateTime(value)` v jazyce rozhraní; zápis tvoří čisté jádro
  `utils/formatDateCore.js` (jazyk parametrem, bez `Intl`, testy). Jádro
  sdílí i vývojářský dashboard se svým jazykem.

Pravidla:

- **Prázdná hodnota** → backend `null` (vlastnost se v detailu vynechá),
  frontend `''`; pomlčku si doplní volající. **Hodnota, která datem není,
  se vrací beze změny** — neztratí se.
- **Časové pásmo se nepřevádí.** Čisté datum (`2026-01-01`) a čas bez
  posunu se zapíšou tak, jak v hodnotě stojí; frontend je čte z řetězce
  ručně (`new Date('2026-01-01')` je půlnoc UTC a západně od UTC by dala
  31. 12.). Jen hodnota s výslovným posunem (`…Z`, `…+02:00`) je okamžik
  a frontend ji ukáže v místním čase prohlížeče.
- **Česká věta bez jazyka požadavku** (validační hláška, zámek — vrstva
  dokumentů jazyk nezná) bere `DisplayDate` s jazykem `cs`: datum ladí
  s větou.

Mimo pravidlo (vlastní formát, beze změny):

- pole formuláře pro zadání data (`DateInput`);
- tisk (Twig, formáty přes `ext-intl` v jazyce dokladu), export reportu
  (XLSX / CSV), výměnný formát, ISDOC, XML podání;
- **texty uložené v datech** — text upozornění z běhu kontroly, popis
  účetního zápisu, zprávy importu: vznikly jednou v jednom jazyce;
- relativní čas („před 3 d“, „za 5 d“) — jen jeho konec, kde už se píše
  datum, jde přes sdílenou funkci.

Kopie hlídají dva testy: `DisplayDateUsageTest` (žádný literál se vzorem
`j. n. Y`, `d.m.Y`, `d. m. Y`, `j. n.` ve zdrojích `src/` a modulů mimo
vyjmenované soubory s důvodem) a `dateFormatUsage.test.mjs` v `npm test`
(`toLocaleDateString`, `toLocaleTimeString`, `toLocaleString`
a `Intl.DateTimeFormat` ve zdrojích aplikace jen ve výjimkách s důvodem).

---

## 12. Detail záznamu

Detail záznamu (pravý panel vieweru, drawer na mobilu, read-only modal
`ViewerDetailModal`) kreslí sdílený `ViewerDetail.svelte`; obsah typu
`document` uvnitř kreslí `DocumentDetail.svelte`, který je **referenční
implementací** této kapitoly. Datový kontrakt (hlavička, taby, typy
obsahu) popisuje `docs/frontend.md`; tady je vzhled.

### Pořadí shora dolů

1. **Lišta akcí** (`detail.actions`) — nad hlavičkou; je to lišta, takže
   tlačítka i rozbalovací akce (Tisk) mají běžnou výšku ovládacího prvku
   (`Button` bez `size`, #156 D107).
2. **Hlavička** (`components/ui/DetailHeader.svelte`, #139 D48 — sdílená
   pro `ViewerDetail` i review dokladu): ikona typu záznamu 40×40 vlevo,
   titulek (title), podtitul (body, barvou textu, D56), štítky (`Badge`: meta,
   normální tloušťka, `padding: 0.125rem 0.625rem`, pilulka). Odsazení
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
  vpravo ve stejném řádku (přepínač náhledů příloh, hromadné akce review);
  když se vedle nadpisu nevejde, zalomí se pod něj (`flex-wrap`, #139),
  nadpis se nestlačuje do víc řádků. Blok `heading`
  v `composite` je nadpis bloku pod ním — ten mezeru nad sebou nemá.
  Propy `role="title"`, `icon` a `spacing` jsou pro nadpis karty jako
  samostatné části stránky (§8 Karty, D36, D38) — v detailu záznamu je
  nadpis sekce vždy heading bez ikony.
- **Karta strany** (`components/document/PartyCard.svelte`, #139 D48,
  D50): sdílená `Card` (§8 Karty, D33) s nadpisem `title` — rámeček
  `--shpd-color-border`, zaoblení `--shpd-radius-md`, odsazení
  `--shpd-space-md`; obsah v pořadí D16, položky pod sebou s mezerou
  `--shpd-space-xs`: název entity heading (odkaz na záznam modře dává
  snippet `name`), identifikátory IČO / DIČ / VAT ID, adresa a účet body
  barvou textu bez tučného (D15), kontakty meta šedě s malou mezerou nad;
  bez strany „—“. Jeden tvar dat s hotovými texty (adaptér
  `documentParts.js`). Review přidává snippety `status` (stav strany vpravo
  v nadpisu — `Card` `aside`), `bankStatus` (značka účtu za číslem; účet se
  pak ukáže i bez čísla) a `footer` („Založit z registru“); kontaktní osoba
  je první položka `contacts`.
- **Popisek–hodnota** (D29, `PropertyList.svelte` + `PropertyGroups.svelte`
  v `components/ui/`): jedna sdílená komponenta pro obecný detail i detail
  dokladu. `<dl>` jedné skupiny je mřížka `grid-template-columns:
  max-content 1fr`, mezera `--shpd-space-xs` svisle / `--shpd-space-md`
  vodorovně, oba sloupce body barvou textu (D15), hodnota se zalamuje
  (`overflow-wrap: anywhere` — IBAN, e-mail); prázdná hodnota se nevykreslí
  (`0` prázdná není), prázdná skupina celá; položka s prázdným popiskem
  (`label: ''`, text poznámky) má hodnotu přes oba sloupce. Volba
  `emptyValue` (#139 D51) prázdnou hodnotu nahradí textem („—“ v review —
  kontroluje se vytěžení, prázdné pole je informace) a skupina se pak
  nevynechá; detail (výchozí `null`) prázdné pole dál vynechává. Položka
  se `snippet` (Svelte snippet `(item)`) kreslí snippet místo hodnoty
  a je viditelná vždy (výběr režimu a místa plnění DPH v review); položka
  projde do snippetu celá i s vlastními klíči volajícího. Skupiny vedle
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
  s `tabular-nums` a nikdy zalomená (`white-space: nowrap`). Štítek stavu
  v buňce = sdílený `Badge`. **Každá tabulka má vlastní obal**
  (`components/ui/TableScroll.svelte`, D44, D57): bez vzhledu navíc, bez
  `tabindex` (posuvný obal bez fokusovatelného obsahu dělá fokusovatelným
  prohlížeč sám, obal s `tabindex` by přidal zastávku tabulátoru ke každé
  tabulce; volitelný `label` → `role="region"` pro tabulku bez vlastního
  nadpisu sekce). Stránka ani panel nikdy nerolují do strany — v úzkém
  obalu se posune tabulka. Dva druhy:
  - **Tabulka v toku obsahu** — `<TableScroll>`, `overflow-x: auto`: posun
    jen do strany, dolů roluje stránka nebo panel, hlavička nedrží. Řádky
    dokladu, rekapitulace DPH, obecný typ `table` v detailu, tabulka
    v chatu, relace, obsahové štítky, dialog odpisů majetku a všechny
    tabulky vývojářského dashboardu (tam uvnitř rámu `.dev-table-wrap`,
    který jen kreslí čáru a zaoblení a neroluje).
  - **Datová mřížka s držící hlavičkou** — `<TableScroll fill>`
    (`flex: 0 1 auto; min-height: 0; overflow: auto`): obal zabere nejvýš
    zbytek výšky své oblasti, roluje v obou osách a sticky hlavička drží
    vůči němu. Report (`ReportView`) a sub-tabulka formuláře
    (`FormSubTable`); seznam vieweru (`ViewerGrid`) a prohlížeč tabulek
    (`TableBrowser`) mají týž vzor s vlastním obalem. Rodič `fill` musí být
    flex sloupec s **určenou výškou** — každý článek řetězu `flex: 1;
    min-height: 0` nebo výška, ne `min-height`; jinak obal naroste
    s obsahem a roluje zase stránka. Obal neroste nad výšku tabulky:
    krátká tabulka má další obsah (zprávy reportu) hned pod sebou.

  Proč dva druhy: prvek s `overflow-x: auto` má spočítané i `overflow-y:
  auto` — je rolovací oblastí, která svisle neroluje, a `position: sticky`
  uvnitř se drží jeho okraje. Obal s posunem jen do strany proto držící
  hlavičku ruší. Tabulka s držící hlavičkou má `border-collapse: separate;
  border-spacing: 0` (s `collapse` čáry hlavičky odjedou s obsahem);
  u víceřádkové hlavičky drží celý `thead` (`position: sticky; top: 0`),
  ne jednotlivé `th` — ty by se s `top: 0` v obou řádcích překryly.

  Tabulka v kontejneru s `word-break: break-word` (bublina chatu) si
  v buňkách vrací `word-break: normal`: `break-word` zmenšuje i nejmenší
  šířku obsahu, tabulka by se pak vždy vešla, lámala text po znacích
  a obal by nikdy nerozroloval (`chat/TableBlock.svelte`).
- **Řádky dokladu** (`components/document/DocRows.svelte`, #139 D45, D48):
  `SectionHeading` (`title`, snippet `aside` — hromadné akce review)
  a tabulka v `TableScroll`. Sloupce Poz. · Popis · [Účet] · Množství · MJ
  · Cena za MJ · DPH · Celkem; textový řádek = pořadí + popis přes zbytek;
  prázdná hodnota „—“. Snippety s výchozím textem z řádku: `description`
  (detail: odkaz na položku + `Badge` stavu položky), `below` (pod popisem
  v obou režimech — napárování), `account` (přítomnost zapíná sloupec
  Účet), `unit`, `vat`, `meta` (řádek složeného režimu). Složený řádek pod
  `36rem` viz Úzká šířka.
- **Součty** (`components/document/DocTotals.svelte`, #139 D52, D55):
  rekapitulace DPH vlevo (sdílená `VatRecapTable.svelte` v kompaktní
  podobě — tabulka v `TableScroll`, nezvýrazňuje se), souhrn vpravo — Základ, DPH, Zaokrouhlení (jen když je), Celkem title
  s čarou nad, hodnoty strong (token); přepočet v domácí měně meta šedě.
  Popisek řádku součtu je **„Celkem“**, ne „Celkem k úhradě“ (D55): u
  pokladního dokladu, zaplacené faktury nebo interního dokladu by
  „k úhradě“ bylo zavádějící. Review přidává snippety `before` (zdroj čísel
  nad rekapitulací, meta), `rate` (sazba s kódem DPH, dvojice přenesené
  povinnosti) a `after` (`Notice` při nesouladu s částkou dodavatele);
  `totalTestId` → `data-testid` řádku Celkem.
- **Rekapitulace DPH ve formuláři** (`VatRecapTable.svelte` s `extended`,
  komponenta formuláře `docVatRecap`, #150 D86): podrobnější než v detailu
  a review, ale táž tabulka a tytéž styly. Sloupce Sazba (název kódu DPH,
  u tuzemského přenesení s předmětem plnění), %, Měna, Základ, DPH, Celkem;
  čísla vpravo, `tabular-nums`, nezalomená — zalomit se smí jen název
  sazby. U dokladu v cizí měně je pod řádkem v měně dokladu řádek v domácí
  měně **barvou textu**, bez čáry mezi nimi; Sazba a % jdou přes oba řádky
  (`rowspan`), zarovnané nahoru. **Šedá (`--shpd-color-text-secondary`) má
  v tabulce jediný význam: hodnota, která se nesčítá do součtu dokladu**
  (přenesená daňová povinnost) — žádné podbarvení řádku, žádná průhlednost.
  Patička: řádek Celkem strong s čarou nad (jako součtový řádek tabulky),
  u cizí měny s kurzem běžnou tloušťkou a s řádkem součtu v domácí měně.
  Pořadové číslo řádku tabulka nemá. Prázdná rekapitulace je blok `empty`.
  Detail a review zůstávají u kompaktní podoby.
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
- **Zdroj textů detailu i seznamu** (#133 D66): popisek vlastnosti shodný
  s popiskem sloupce jde z definice tabulky (`TableViewer::columnLabel()`,
  stejný text jako ve formuláři), nadpisy skupin, štítky, zástupné texty
  a vzory s hodnotou (`"IČO: {value}"`) z katalogu modulu; text společný
  třem a více modulům z `core.system.viewerDetailLabels` (`sharedText()`).
  Literál v PHP jen v jednom jazyce zastaví `ViewerSourceTextsTest` (D67).
- **Prostý text**: blok `text` `{text}` — odstavec body, `white-space:
  pre-wrap` a `overflow-wrap: anywhere` (tělo pošty bez HTML, AI shrnutí
  Spisovny).
- **Nápověda** (#150 D84): blok `hint` `{text}` — odstavec body
  `--shpd-color-text-secondary`, `white-space: pre-wrap`, bez vnějšího
  okraje a **bez tučného zvýraznění** (§11 — tučné je jen role nadpisu);
  cesta v Nastavení se píše prostým textem („Nastavení → Účetnictví → …“).
  Vysvětlující věta nad poli formuláře (Registrace DPH, Podání DPH); smí ji
  nést i detail.
- **Kód**: blok `code` `{text}` — `<pre>` v `--shpd-font-family-mono`,
  velikost meta, pozadí `--shpd-color-bg-secondary`, zaoblení md, odsazení
  sm, dlouhý řádek roluje v bloku (JSON kontextu upozornění).
- **Backend HTML se vzhledem neposílá** (D30, #150 D82): typ `html` není
  v kontraktu detailu **ani formuláře**; všechny typy nesou čistý text
  a frontend ho vkládá jako text, nikdy `{@html}`. Jediné HTML je
  `untrusted-html` — tělo e-mailu v sandboxu. Detail hlídá
  `DetailContent::TYPES` (unit `DetailContractTest`: žádný PHP zdroj blok
  `html` neskládá; integrační `ViewerDetailContractTest`: obsah tabů prvního
  záznamu každého vieweru jen ze známých typů). Formulář nese jednoduchý
  obsah prvkem `content` s týmiž bloky (`DetailContent::SHARED_TYPES`),
  rekapitulaci DPH a strany dokladu pojmenovanými komponentami nad
  sdílenými částmi dokladu; hlídá `FormContractTest` (prvek `html`
  neexistuje, žádný zdroj formuláře nevolá `->html(`). Globální třídy
  `.vat-recap*` a `.snapshot*` v `base.css` zanikly. Viz
  `docs/edit-forms.md` §4.5–4.6.
- **Obecný detail i doklad kreslí tytéž komponenty**: jednoduché bloky
  (`properties`, `table`, `heading`, `composite`, `notice`, `badges`,
  `empty`, `text`, `code`, `hint`) kreslí sdílená
  `components/content/ContentBlock.svelte` (#150 D83) — v detailu
  (`ViewerDetail.svelte`, který jí snippetem dodává bloky vázané na viewer)
  i ve formuláři (prvek `content`); `ViewerDetail.svelte` (Odeslaná pošta),
  `DocumentDetail.svelte` i `DetailAttachments.svelte`
  používají `SectionHeading` a `PropertyGroups` — každý detail má stejné
  písmo i mezery; obecný typ `table` používá tytéž role. **Sdílené části
  dokladu** (#139 D48): `DocumentDetail.svelte` jen skládá `PartyCard`,
  `PropertyGroups`, `DocRows` a `DocTotals` z `components/document/` přes
  adaptér `documentParts.js` (čisté funkce, `t` parametrem: snake_case
  obsah `document` → jeden tvar dat s hotovými texty; review bude mít
  adaptér z canonical) — vlastní má jen kořen, mřížku stran a snippety
  s odkazy. Části jsou prezentační: stav a interaktivní prvky (značky,
  rozhodnutí, hromadné akce) dodává volající snippety. **Pravidlo:** nový
  nadpis sekce, seznam popisek–hodnota, hláška nebo štítek v detailu je
  sdílená komponenta, ne vlastní třída s kopií CSS; nový prvek dokladu je
  sdílená část v `components/document/`, ne kopie v detailu nebo review.

### Úzká šířka

Kořen obsahu dokladu (`.shpd-docdetail`) i obal skupin vlastností
(`PropertyGroups`, `.shpd-property-groups`) jsou `container-type:
inline-size`; pod prahem úzkého kontejneru `30rem` (480 px, §8
*Responzivita*) jdou strany pod sebe a skupiny vlastností do jednoho
sloupce přes `@container (max-width: 30rem)` — ne `@media`, protože detail
bývá v panelu vedle seznamu, v draweru i v modalu a šířka okna o jeho šířce
nic neříká (D3). Práh vychází ze skutečných šířek obsahu: panel vedle
seznamu má šířku okna bez 693 px (sidebar, seznam, odsazení), drawer
528 px, modal přes 1100 px (při písmu 16 px; šířky jsou v rem, §10) — dvě
karty vedle sebe tak zůstávají všude
kromě úzkého okna a mobilu. Původních 40 rem stohovalo strany už při okně
užším než 1333 px. Dotaz platí pro potomky kontejneru, ne pro něj sám —
proto je kontejnerem obal mřížky na celou šířku, ne mřížka samotná;
`container-type` nepatří na prvky, které se mají smrštit na obsah.
**Složený řádek** (D45, #139): řádky dokladu (`DocRows`) pod `36rem`
(576 px) kontejneru přepnou na složený řádek — jedno DOM, přepíná jen
CSS: hlavička tabulky a buňky Účet / Množství / MJ / Cena / DPH jsou
skryté, zůstává pořadí · popis · celkem vpravo a pod popisem řádek
„množství jednotka × cena · sazba“ v roli meta šedě (`rowMetaLine`;
v review navíc „· účet“ se značkou); textový řádek jen popis. Při okně
1280 px má panel vedle seznamu 587 px (tabulka), drawer 528 px a mobil
složené řádky; při písmu 20 px se složený režim zapne už při 1280 px
(`rem`). Kontejner je kořen `DocRows` a je **pojmenovaný**
(`container-name: doc-rows`): uvnitř detailu je kontejnerem
i `.shpd-docdetail` a nepojmenovaný dotaz by se ptal nejbližšího předka.
Textový řádek drží `colspan` i ve složeném režimu — skryté sloupce bez
vlastní buňky mají v Chromiu nulovou šířku, tabulka se neroztáhne. Cena
čistě CSS řešení: snippety buněk review (`unit`, `vat`, `account`, `meta`)
jsou v DOM dvakrát, vidět je jen jedno. Obal s posunem do strany
(`TableScroll`, D44) má každá tabulka v obsahu; datové mřížky rolují ve
svém obalu v obou osách (D57, viz Tabulka výše).

### Review dokladu a náhled Spisovny

Review modal (`components/exchange/DocumentExchangePreview.svelte`)
a náhled Spisovny (`RegistryExtractedPreview.svelte`) kreslí od #139
(D49–D55) **tytéž sdílené části jako detail dokladu** — `DetailHeader`,
`PartyCard`, `PropertyGroups`, `DocRows`, `DocTotals`, `Notice` — přes
adaptér `components/exchange/reviewParts.js` (čisté funkce, `t`
parametrem: canonical → hotové texty v tvaru částí; peníze, datum
a adresu formátuje adaptér, dřív pomocné funkce komponenty). Review má
vypadat jako detail téhož dokladu; liší se jen tím, co je navíc ke
kontrole. Části jsou prezentační (D48): stav review — rozhodnutí, stavové
značky, popovery, výběr DPH, rychlé založení z registru — zůstává
v `DocumentExchangePreview` a do částí jde snippety přes uzávěr.

- **Hlavička** (D49): `DetailHeader` bez ikony; titulek protistrana podle
  `selfParty` (D14; bez ní číslo dokladu, bez obojího „—“), podtitul
  „číslo · text“. Štítky **typ výsledku analýzy** (`primary`, „Faktura
  přijatá“) a **souhrnný stav kontroly vždy** — „Vše napárováno“
  (`success`) / „Vyžaduje pozornost“ (`warning`). Na rozdíl od detailu
  (D13: typ nese ikona, D20: „V pořádku“ se neukazuje) je review místo
  kontroly výsledku analýzy — typ říká, co analýza určila, stav je
  výsledek kontroly, ne stav záznamu.
- **Hlášky** (D53): problémy z vytěžení jako `Notice` hned pod hlavičkou
  (nadpis „Upozornění“, tón podle nejvyšší závažnosti, položky od
  nejzávažnější, cesta jako `meta`); selhání AI `Notice` danger
  s problémy formátu a surovým výstupem rozbalovacím pod ní (blok `code`);
  chyba rychlého založení `Notice` danger v patičce karty strany;
  nesoulad s částkou dodavatele `Notice` warning pod souhrnem.
- **Strany** (D50): `PartyCard` ×2 v mřížce dvou sloupců (pod 30 rem pod
  sebe) se snippety `status` (stav strany vpravo v nadpisu karty —
  `Card` `aside`), `bankStatus` (značka účtu za číslem; účet se ukáže
  i bez čísla, když má vlastní rozhodnutí) a `footer` („Vytvořit
  z registru: název“ jako `Button` secondary sm s ikonou `iconAdd`
  a `wrap` — popisek s názvem firmy se zalomí uvnitř tlačítka, které je
  nejvýš tak široké jako karta; stejné tlačítko v rozhodovacím panelu
  partnera má „+“ také jako ikonu); kontaktní osoba je první
  položka kontaktů („Kontaktní osoba: …“).
- **Údaje** (D51): `PropertyGroups` se skupinami Data / Platba / DPH
  a `emptyValue="—"` — prázdné pole se ukazuje, kontroluje se vytěžení;
  popisky sdílené s detailem (`document.*`), režim a místo plnění DPH
  jako položky se snippetem (select „Automaticky (…)“ a zdroj v roli meta).
- **Řádky** (D45): `DocRows` — hromadné akce („Založit položky (n)“, „Kód
  DPH u řádků (n)“) jako textová tlačítka v řádku nadpisu (`aside`), ne
  kolečka v hlavičce sloupců; snippety `description` (text řádku, kód
  dodavatele, značka položky, ⟲), `below` (napárování), `account` (zapíná
  sloupec Účet), `unit`, `vat`, `meta` (řádek složeného režimu „množství
  jednotka × cena · sazba kód · účet“ se značkami u svých částí — skládá
  se v komponentě se stejnými oddělovači jako `rowMetaLine`). Ve složeném
  režimu jsou značky jednotky, kódu DPH a účtu **v DOM dvakrát** (skryté
  široké buňky + řádek meta), obě volají totéž rozhodnutí; proklikání
  sady (#103) hledá jen viditelné odznaky.
- **Součty** (D52, D55): `DocTotals` s `before` (zdroj čísel: převzaté /
  přepočtené / bez přepočtu, meta), `rate` (kód DPH meta, „oddaňovací
  řádek“ u dvojice přenesené povinnosti) a `after` (`Notice` nesouladu);
  popisek „Celkem“, `data-testid="review-total"`.
- **Spisovna** (D54): `DetailHeader` (titulek název dokumentu, podtitul
  „protistrana · IČO“, štítek „Spisovna · druh“, bez stavu), shrnutí jako
  prostý text přes celou šířku, pole druhu a návrh šanonu (poslední
  položka) v jedné skupině `PropertyGroups`.
- **Patička na telefonu** (#155 D113): hlavní akce a kebab ⋮
  (`review-more`) — §8 Akce na telefonu. Hláška o neuloženém rozhodnutí
  (`review-save-error`) má na telefonu vlastní řádek nad tlačítky; patička
  má proto vlastní obal na celou šířku (`__footer-box`, vzor náhledu
  tisku). V panelu rozhodnutí jsou tlačítka „Vytvořit …“ mřížkou
  `auto 1fr` s `align-content: center` — popisek svisle uprostřed, ikona
  na účaří prvního řádku i po zalomení.
- **Typografie** (D54): v `components/exchange/` jen role — body pro
  texty, vstupy a tlačítka, meta pro doplňky (kódy, napárování, hinty,
  štítky, sekundární řádek výsledku), nadpisy sekcí panelů meta strong
  bez verzálek; stavové značky (kolečko se znakem) zůstávají ovládacím
  prvkem, znak v roli meta normální tloušťkou. Rozměry koleček a popoveru
  jsou v rem (D46, §10 Pevné rozměry).

---

## 13. Soubory

| Soubor | Obsah |
|---|---|
| `frontend/src/styles/variables.css` | **Centrum pravdy** — všechny barvy, spacing, typografie, layout tokeny (v rem, D46) |
| `scripts/check-css-units.py` | Kontrola jednotek v pre-commitu (§10): px v mezerách, zaoblení, písmu a **rozměrech** (`width`, `height`, `min-*`, `max-*`, `flex`), rozměrové propy komponent, layout tokeny; breakpointy (§8) |
| `scripts/check-contrast.py` | Kontrola kontrastu v pre-commitu (§6 pravidlo 6, #153): hlídané dvojice tokenů z `variables.css` ve světlém i tmavém vzhledu, prahy 4,5 : 1 (text) a 3 : 1 (značka, ikona, obrys), výjimky v konstantě `EXCEPTIONS`; `--list` vypíše všechny dvojice s poměrem |
| `scripts/check-css-tokens.py` | Kontrola v pre-commitu: každý token ve `var(--x…)`, i s fallbackem, musí mít definici; u `var(--a, var(--b))` oba. Bez seznamu výjimek — definicí je i přiřazení do JS objektu (tokeny vlastního vzhledu). Druhé pravidlo (D61): rodina `--shpd-color-alert-*` smí být použitá jen v `Notice` a `PageBanner` — nález vypíše soubor, řádek a token; jediná výjimka v pojmenované konstantě (vývojářský dashboard). Třetí pravidlo (D71): hex barva jen ve `styles/variables.css` a `dev/theme.css` — hledá se v hodnotách deklarací, atributu `style` a direktivě `style:` mimo komentáře; výjimky po souborech s důvodem (`HEX_EXEMPT`), hex v JS datech se nehlídá. Čtvrté pravidlo (#153 D90): `color-mix(…)` s tónovým tokenem (`danger`, `warning`, `success`, `info`) je nález — text na tónovaném podkladu kreslí jen `Badge`; tónovaná plocha bez textu jen jako výjimka po souborech (`TONE_MIX_EXEMPT`, dnes prázdná), `primary` s nízkým podílem pod cílem přetažení souboru se nehlídá |
| `frontend/src/styles/base.css` | Základní typografie a layout |
| `frontend/src/styles/reset.css` | CSS reset |
| `frontend/src/components/ui/Button.svelte` | Tlačítkový primitiv s variantami a velikostmi `md` / `sm` — výška z tokenu ovládacího prvku, bez popisku čtverec (§8 Výška ovládacího prvku). Popisek je vždy na jednom řádku (`nowrap`); prop `wrap` (opt-in) ho dovolí zalomit — text vlevo, výška prvku jako minimum, ikona u prvního řádku, nejvýš šířka rodiče, jednořádkové stejně vysoké jako bez `wrap`. Jen pro tlačítko s proměnným textem v úzkém místě (rychlé založení z registru s názvem firmy), ne jako obecný vzhled. Prop `dismiss` označí tlačítko, které dialog jen zavírá — na telefonu ho skryje `Modal` (#155 D116) |
| `frontend/src/components/ui/KebabMenu.svelte` | Kebab (§8 Akce na telefonu, #155 D114) — tlačítko ⋮ a bublina s akcemi, `items`, `placement`, `variant` `control` / `plain`; jediná implementace pro stavovou lištu formuláře, horní lištu na telefonu a patičku náhledu dokladu |
| `frontend/src/components/ui/Modal.svelte` | Modální dialog (§8 Modální dialogy) — stack, fullscreen na telefonu, patička; na telefonu skrývá zavírací tlačítko `dismiss` a patičku, ve které nic jiného není (#155 D116) |
| `frontend/src/components/ui/SectionHeading.svelte` | Nadpis sekce detailu (§12, D28) — heading bez linky, `level` 2–4, snippet `aside`; `role` title, `icon` tématu a `spacing` pro nadpis karty (§8 Karty, D36, D38) |
| `frontend/src/components/ui/Card.svelte` | Karta v obsahu (§8 Karty, D33) — rámeček, odsazení md / `spacious` lg, nadpis `SectionHeading` + `aside`, `titleRole` a `icon` podle místa karty (D36, D38), tóny `warning` / `danger` / `info`, `as`, bez stínu a okraje |
| `frontend/src/components/ui/Panel.svelte` | Vložený panel (§8 Vložené panely, D75) — `bg-secondary` s rámečkem `border-subtle`, `variant` compact, `tone` info, `padding` form, `as`; na něm `FormSection` |
| `frontend/src/components/ui/OptionRow.svelte` | Volitelný řádek (§8 Volitelné řádky, D76, D80, D81) — velký s ukazatelem radio / checkbox a snippety `description` (body barvou textu) / `aside` (na úzké šířce pod popisem, kontejner `option-row`), kompaktní jako tlačítko s `role` option / radio; vybraný `primary` + `primary-soft` |
| `frontend/src/components/ui/SegmentedControl.svelte` | Segmentový přepínač (§8, D77) — `radiogroup` oddělených tlačítek, `options`, bindable `value`, `onchange`; šipky přesouvají jen fokus, vybírá mezerník / Enter / klik (D79) |
| `frontend/src/components/ui/PageHeader.svelte` | Nadpis stránky (§8, D78) — `h1` s ikonou z navigace a `aside`; `flow` (role page) / `bar` (lišta jako hlavička detailu) |
| `frontend/src/components/ui/CenteredScreen.svelte` | Obrazovka s kartou uprostřed (§8) — obal `100dvh` s odsazením md a plovoucí plocha `25rem`; přihlášení, nastavení hesla, OpAuth, `StatusScreen` |
| `frontend/src/components/ui/CardGrid.svelte` | Mřížka karet (§8 Karty, D34) — `auto-fill` s `min()` ve `minmax`, `minWidth` podle použití, karty v řádku stejně vysoké |
| `frontend/src/components/ui/PropertyList.svelte` | Seznam popisek–hodnota jedné skupiny (§12, D29) — mřížka `max-content 1fr`, zalamování, prázdný popisek přes oba sloupce; `emptyValue` a položka se `snippet` (D51) |
| `frontend/src/components/ui/PropertyGroups.svelte` | Skupiny vlastností ve dvou sloupcích, `wide`, container query 30 rem (§12, D29), `emptyValue`; výběr skupin čistá funkce `propertyGroups.js` |
| `frontend/src/components/ui/Notice.svelte` | Hláška v obsahu (§8 Hlášky a pruhy, D40) — tóny z rodiny `alert-*`, rámeček 1 px, `title`, `icon`, `items` (text nebo `{label, text, meta}`), role podle tónu, bez okraje |
| `frontend/src/components/ui/Badge.svelte` | Štítek hlavičky detailu a bloku `badges` (§5) — obecné i doc-state varianty, pilulka meta |
| `frontend/src/components/ui/DetailHeader.svelte` | Hlavička detailu (§12 Pořadí, #139 D48) — ikona 40×40, titulek title, štítky `Badge`, podtitul body (D56); sdílená pro `ViewerDetail` i review dokladu |
| `frontend/src/components/ui/TableScroll.svelte` | Obal tabulky (§12 Tabulka, D44, D57) — tabulka v toku obsahu `overflow-x: auto`; `fill` = datová mřížka s držící hlavičkou (zbytek výšky oblasti, rolování v obou osách); bez `tabindex`, volitelný `label` → region |
| `frontend/src/components/document/documentParts.js` | Adaptér detailu dokladu (čisté funkce, `t` parametrem, #139 D48): `detailParty`, `detailRows`, `detailTotals`, `formatAddress`, `rowMetaLine` — jeden tvar dat částí s hotovými texty |
| `frontend/src/components/document/PartyCard.svelte` | Karta strany dokladu (§12, D50) — `Card`, pořadí D16, snippety `name` / `status` / `bankStatus` / `footer` |
| `frontend/src/components/document/DocRows.svelte` | Řádky dokladu (§12, D45) — tabulka v `TableScroll`, složený řádek pod `36rem` (pojmenovaný kontejner `doc-rows`), snippety `description` / `below` / `account` / `unit` / `vat` / `meta`, `aside` nadpisu |
| `frontend/src/components/document/DocTotals.svelte` | Součty dokladu (§12, D52, D55) — rekapitulace DPH (`VatRecapTable` kompaktní), souhrn s řádkem Celkem, snippety `before` / `rate` / `after`, `totalTestId` |
| `frontend/src/components/document/VatRecapTable.svelte` | Tabulka rekapitulace DPH (§12 Součty, #150 D86) — v `TableScroll`; kompaktní podoba pro detail a review, `extended` pro formulář dokladu (měna, řádky domácí měny, nesčítané hodnoty šedě, patička Celkem) |
| `frontend/src/components/content/ContentBlock.svelte` | Jeden blok obsahu (§12, #150 D83) — sdílené vykreslení jednoduchých bloků kontraktu `DetailContent` pro detail i prvek formuláře `content`; `composite` rekurzivně, ostatní typy snippetem `other` hostitele |
| `frontend/src/components/form/FormDocVatRecap.svelte`, `FormDocParties.svelte` | Komponenty formuláře dokladu `docVatRecap` / `docParties` (#150 D85, D86) — rekapitulace DPH a strany dokladu nad `VatRecapTable` a `PartyCard` |
| `frontend/src/components/ui/PageBanner.svelte` | Pruh nad obsahem (§8 Hlášky a pruhy, D41) — celá šířka, linka 2 px dole, tóny `warning` / `info` / `danger` |
| `frontend/src/components/viewer/ViewerRow.svelte` | Řádek seznamu se stavovým pruhem |
| `frontend/src/components/viewer/ViewerDetail.svelte` | Detail s hlavičkou (`DetailHeader`), taby, štítky (`Badge`); obecné typy obsahu kreslí sdílená `ContentBlock` (§12), bloky vázané na viewer (návrh, přílohy, doklad) jí dodává snippetem; HTML jen `untrusted-html` v sandboxu (D30) |
| `frontend/src/components/viewer/DocumentDetail.svelte` | Detail dokladu — referenční implementace §12: skládá sdílené části dokladu (`PartyCard`, `PropertyGroups`, `DocRows`, `DocTotals`) přes adaptér `documentParts.js`; vlastní jen kořen (container query stran), mřížka stran a odkazy na záznamy |
| `frontend/src/components/exchange/reviewParts.js` | Adaptér review dokladu a náhledu Spisovny (§12 Review, #139 D49–D53): `counterparty`, `reviewHeader`, `registryHeader`, `reviewParty`, `reviewRows`, `reviewTotals`, `issueNotice`, formátování peněz / data / adresy — canonical → tvar sdílených částí |
| `frontend/src/components/exchange/DocumentExchangePreview.svelte` | Review dokladu na sdílených částech (§12 Review): stav review (rozhodnutí, značky, popovery, výběr DPH, registr) a snippety do částí; vlastní CSS jen mřížka stran, stavové značky, napárování, kódy, výběr DPH |
| `frontend/src/components/exchange/RegistryExtractedPreview.svelte` | Náhled Spisovny na `DetailHeader`, textu a `PropertyGroups` (§12 Review, D54) |
| `frontend/src/components/form/FormStateBadge.svelte` | Stavový badge ve formuláři |

---

## 14. Plánovaná rozšíření
