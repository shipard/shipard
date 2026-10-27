# Design system — karty (`Card`, `CardGrid`)

**Stav:** hotovo — #137 task 2 (D33–D35), 2026-10-26

## Kontext / Cíl

Druhý task #137 (zastřešující #133). Audit karet (výsledek v #137) našel
deset kopií CSS karty v obsahu a čtyři kopie karty stránky v Nastavení.
Liší se odsazením, zaoblením a nadpisem. Mřížka Přehledu firmy má
`align-items: start` a sloupec bez `min()`, takže karty v řádku mají
různou výšku a na úzké šířce přetékají. Feed dashboardu to má dobře.

**Cíl:**

1. Sdílené komponenty `Card` a `CardGrid` v `frontend/src/components/ui/`
   (D33, D34).
2. Všechny karty v obsahu na nich (seznam v §3); karty v Nastavení se
   zaoblením md (D35).
3. Obsah karet Přehledu firmy na typografické role (§11).
4. Pravidlo do `docs/design-system.md`.

**Mimo rozsah** (kandidáti v #137):

- plovoucí plochy se stínem — `Modal`, přihlášení, `StatusScreen`, panel
  vzhledu, `Popover`, uživatelské menu (D35: nejsou karta);
- vložené panely se šedým pozadím (`FormSection`, nabídka položek,
  souhrny v dialozích);
- hlášky a bannery (chyba, varování, zámek dokladu) — s taskem 3;
- volitelné řádky — `.shpd-registry-wizard__row` (tlačítko volby),
  `.shpd-bank-bridge__row` (řádek seznamu);
- vývojářský dashboard (`.dev-card`, vlastní vzhled), portál hostingu;
- nadpis stránky Přehledu firmy (`h1`, `xl`) — nadpisy stránek se
  sjednotí zvlášť;
- vnitřní rozložení karty feedu (ikona, akce, přílohy) — mění se jen obal
  a literály písma na tokeny.

## Návaznost

- **#133** — D2, D3, D15, D16, D28, D33, D34, D35
- **#137** task 1 — `SectionHeading` (nadpis karty)
- **#120** — Přehled firmy (`docs/overview.md`)
- **#101** — feed dashboardu (`docs/dashboard.md`)
- **#139** — review modal; strany v náhledu importu jsou jeho součástí

## Před implementací přečti

- `docs/design-system.md` §8 Layout konvence, §11 Typografie, §12
  Stavební prvky obsahu (Karta), Úzká šířka
- `frontend/src/components/ui/SectionHeading.svelte`, `PropertyGroups.svelte`
  (vzor sdílené komponenty a komentářů)
- `frontend/src/components/overview/CompanyOverview.svelte`
- `frontend/src/components/dashboard/Feed.svelte` (`.shpd-feed__grid`),
  `FeedCard.svelte`, `AiSummaryCard.svelte`
- `frontend/src/components/settings/SettingsPage.svelte`, `DsAbout.svelte`,
  `DsSetup.svelte`, `frontend/src/components/account/AccountSecurity.svelte`

## Rozhodnutí (potvrzená v #133)

- ✓ **D33** `Card` — jedna sdílená komponenta pro kartu v obsahu: rámeček
  `--shpd-color-border`, bílé pozadí, zaoblení md, odsazení md; karty
  stránek v Nastavení odsazení lg (`spacious`). Nadpis karty je
  `SectionHeading` (volitelně prvek vpravo). Tón `warning` / `danger` =
  barva rámečku, `info` = tónované pozadí. Stavový proužek feedu kreslí
  karta feedu nad `Card`.
- ✓ **D34** `CardGrid` — `repeat(auto-fill, minmax(min(<šířka>, 100%), 1fr))`,
  mezera md; karty v řádku vždy stejně vysoké (obsah nahoře), šířku
  sloupce určuje použití (Přehled firmy 20 rem, feed 22,5 rem).
- ✓ **D35** Rámeček + zaoblení md = karta v obsahu; zaoblení lg + stín =
  plovoucí plocha. Karta stín nemá; karty v Nastavení přecházejí z lg na md.

## 1. `Card.svelte`

- Kořen: `border: 1px solid var(--shpd-color-border)`,
  `border-radius: var(--shpd-radius-md)`, `background: var(--shpd-color-bg)`,
  `color: var(--shpd-color-text)`, `padding: var(--shpd-space-md)`,
  `box-sizing: border-box`, `min-width: 0`. Žádný stín, žádný vnější okraj
  (mezery dává rodič / `CardGrid`).
- Props:
  - `title` (nepovinný text) → `SectionHeading` nahoře, `headingLevel`
    (výchozí 3);
  - snippet `aside` → vpravo v řádku nadpisu (`SectionHeading` `aside`);
    bez `title` se nekreslí;
  - `tone`: `default` | `warning` | `danger` | `info` — `warning` /
    `danger` mění jen barvu rámečku (tokeny warning / danger, jaké dnes
    používá Přehled firmy), `info` dává pozadí `--shpd-color-primary-soft`
    a rámeček `--shpd-color-primary-soft-2` (dnešní AI shrnutí);
  - `spacious` (bool) → odsazení `--shpd-space-lg`;
  - `as` — značka kořene (`section` výchozí, `div`, `article`, `li`);
  - zbylé atributy (`data-testid`, `role`, `aria-*`, `class`) na kořen.
- Obsah `children`. Karta je `display: flex; flex-direction: column`, aby
  v roztaženém řádku mřížky zůstal obsah nahoře a případná patička mohla
  dostat `margin-top: auto`.

## 2. `CardGrid.svelte`

- `display: grid`,
  `grid-template-columns: repeat(auto-fill, minmax(min(var(--card-min), 100%), 1fr))`,
  `gap: var(--shpd-space-md)`, `align-items: stretch`.
- Prop `minWidth` (rem jako řetězec, výchozí `'20rem'`) → custom property
  `--card-min` na kořeni.
- Položky mřížky jsou přímo karty. Kde mezi mřížkou a `Card` musí být
  obal (komponenta s vlastním kořenem), obal dostane `height: 100%`
  a karta taky — jinak se výška v řádku nesrovná.

## 3. Převod

| Kde | Dnes | Na |
|---|---|---|
| `CompanyOverview.svelte` | `.shpd-overview__grid`, `__card`, `__card--unavailable/--error`, `__card-title` | `CardGrid minWidth="20rem"`, `Card title tone` (unavailable → `warning`, error → `danger`), štítek stavu sekce v `aside` |
| `Feed.svelte` | `.shpd-feed__grid` | `CardGrid minWidth="22.5rem"` |
| `FeedCard.svelte` | `.shpd-feed-card` (rámeček, zaoblení, pozadí) | `Card as="div"` jako kořen; stavový proužek (`__bar`), větší horní odsazení kvůli proužku a vnitřní flex rozložení zůstávají ve FeedCard; `__title` `font-weight: 600` → `--shpd-font-weight-strong` |
| `AiSummaryCard.svelte` | `.shpd-ai-summary` | `Card tone="info"`, vnitřní flex rozložení zůstává |
| `SettingsPage.svelte`, `DsAbout.svelte`, `DsSetup.svelte`, `AccountSecurity.svelte` | `__card` (lg / lg) | `Card spacious` — zaoblení md (D35) |
| `DocumentDetail.svelte` | `.shpd-docdetail__party` (0.75rem) | `Card` s `title` (Dodavatel / Odběratel); mřížka stran `.shpd-docdetail__parties` (dva sloupce, `@container` 30 rem, D3) zůstává — není to `CardGrid` |
| `ViewerDetail.svelte` | `.shpd-extracted__card` (karta návrhu z pošty) | `Card` |
| `FailureCard.svelte` | `.shpd-failure-card` (rámeček + levý pruh 4 px) | `Card tone` podle varianty (`error` → `danger`, `warning` → `warning`, `info` → `info`); levý pruh zaniká (D33: tón = barva rámečku) |
| `DocumentExchangePreview.svelte` | `.shpd-exchange__party` (odsazení sm) | `Card` — odsazení md |
| `RegistryImportWizard.svelte` | `.shpd-registry-wizard__card` (md lg) | `Card` — odsazení md |

U každého převodu smazat třídy, které tím zanikly, a grepem
(`--include=*.svelte --include=*.js`) ověřit, že je nic nepoužívá.
Testovací selektory (`data-testid`) zachovat.

## 4. Obsah karet Přehledu firmy na role (§11)

| Prvek | Dnes | Role |
|---|---|---|
| nadpis karty | `font-size-base`, `600` | heading (`SectionHeading` přes `Card title`) |
| popisek položky (i odkaz) | `font-size-base` | body |
| částka | `font-size-lg`, `700` | title (`--shpd-font-size-title`, strong) — hlavní číslo karty, obdoba řádku Celkem |
| částka chybějící hodnoty | `400`, šedá | title, normal, šedá — beze změny významu |
| splatnost, poznámka, „stav k“ | `font-size-sm`, šedá | meta, šedá (doplněk) |
| splatnost blízko / po termínu | barva + `600` | meta, jen barva (D15: splatnost se tučně nezvýrazňuje) |
| štítky stavu (`__badge`) | `font-size-sm`, `500` | meta, normal |
| zprávy (`__messages`) | — | body |

Tloušťky jen přes `--shpd-font-weight-*`, žádné `500` / `700`.

## 5. Dokumentace

- `docs/design-system.md`:
  - nová podkapitola v §8 Layout konvence (nebo vlastní kapitola za
    §12) **Karty**: D33–D35, kdy karta a kdy ne (plovoucí plocha, vložený
    panel, hláška, volitelný řádek), `CardGrid` a stejná výška v řádku;
    pravidlo „nová karta = `Card`, ne vlastní třída“;
  - §12 Stavební prvky obsahu: Karta (strany dokladu) odkazuje na `Card`;
  - §11: Přehled firmy mezi místy s rolemi;
  - §13 Soubory: `Card.svelte`, `CardGrid.svelte`.
- `docs/overview.md`: vzhled karet přes `Card` / `CardGrid` (jedna věta
  u `CompanyOverview.svelte`).

## Commity

1. `feat(frontend): sdílená karta a mřížka karet (#137)` — `Card`,
   `CardGrid`, Přehled firmy včetně rolí (§4)
2. `refactor(frontend): dashboard a Nastavení na sdílené kartě (#137)` —
   feed, karta feedu, AI shrnutí, čtyři karty Nastavení
3. `refactor(frontend): karty v detailu, náhledu importu a průvodci Spisovny (#137)`
   — strany dokladu, karta návrhu, karta chyby, strany v náhledu importu,
   průvodce
4. `docs: karty (#137, D33–D35)` — dokumentace, `**Stav:**` tasku,
   `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
```

V prohlížeči, světlý i tmavý vzhled, široké i úzké okno:

- Přehled firmy — karty v řádku stejně vysoké, na úzké šířce jeden
  sloupec bez přetečení; sekce nedostupná / s chybou má rámeček v barvě
  tónu; písmo podle §4;
- dashboard — feed beze změny rozložení, stavový proužek nahoře, karty
  v řádku stejně vysoké; AI shrnutí s tónovaným pozadím;
- Nastavení — stránka nastavení, O zdroji dat, Nastavení zdroje dat,
  Zabezpečení účtu: zaoblení md, odsazení lg;
- detail faktury — karty stran beze změny kromě zaoblení / odsazení,
  které už md mají;
- došlá pošta — karta návrhu, karta chyby předzpracování (rámeček
  v barvě chyby, bez levého pruhu);
- náhled importu dokladu — karty stran; průvodce importem Spisovny.

## Hotovo když

- [x] `Card` a `CardGrid` v `components/ui/`
- [x] všechny karty z §3 na nich, zaniklé třídy pryč
- [x] Přehled firmy: stejná výška v řádku, role podle §4
- [x] `docs/design-system.md`, `docs/overview.md`
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Odchylky od zadání

- `SectionHeading` přijímá i `level` 2 — karty Přehledu firmy zůstaly `h2`
  pod `h1` stránky (přes `Card headingLevel`).
- Přehled firmy: linka jen mezi položkami, ne pod nadpisem karty (D28:
  heading bez linky).
- Karta feedu: tloušťky `600` na token; `700` (částka) a `500` (hodnota
  detailu) token nemají a jejich změna je rozhodnutí o typografii feedu,
  ne o obalu — kandidát do #137.
- Karta selhání: `margin-bottom` kořene zanikl (D33, karta bez vnějšího
  okraje); mezeru dává rodič — `.shpd-extracted` má gap, v kartě návrhu
  obal `__failure`.
- Náhled importu a průvodce Spisovny: jen obal `Card`, vlastní hlavičky
  karet zůstaly (typografie stran náhledu patří #139).

## Pasti

- **Stejná výška jen pro přímé položky mřížky.** `align-items: stretch`
  roztáhne položku mřížky, ne kartu uvnitř obalu (§2).
- **`min()` ve `minmax`** — bez něj sloupec 20 rem přeteče na úzké šířce;
  custom property musí být uvnitř `min()`.
- **Tmavý vzhled** — tóny jen přes tokeny (`--shpd-color-warning`,
  `--shpd-color-danger`, `--shpd-color-primary-soft*`), žádná barva
  natvrdo; ověřit kontrast rámečku a pozadí `info` v obou vzhledech.
- **Svelte scoped CSS** — rodič nestyluje kořen `Card` přes vlastní třídu
  bez `:global`; co karta potřebuje, má být prop, ne přepis zvenku.
  Vnitřní rozložení (flex řádek feedu) patří do obsahu karty, ne na její
  kořen.
- **px v CSS** zastaví pre-commit (`check-css-units.py`); 4px levý pruh
  karty chyby zaniká, nepřenášet ho.
