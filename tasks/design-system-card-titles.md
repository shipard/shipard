# Design system — typografie a ikony nadpisů karet

**Stav:** hotovo — 2026-10-27 (#137 task 2b, D36–D38); ikony sekcí po ověření: Pohledávky / Závazky podle typu saldokonta, DPH = Podání DPH (`docs/overview.md` §8)

## Kontext / Cíl

Doplnění tasku 2 #137 (`tasks/design-system-cards.md`) po prokliku:

- Nadpisy karet Přehledu firmy dostaly v tasku 2 roli heading (14 px), ale
  hlavní částka karty má roli title (18 px) — číslo je větší než nadpis
  karty. Karty stránek v Nastavení mají nadpis `lg` + `600` s ikonou
  (literály, vlastní třída na každé stránce).
- Karta feedu má po tasku 2 dva literály mimo role: částka (`xl`, `700`)
  a hodnota rozbaleného detailu (`500`); nadpis sekce feedu `sm` + `600`
  natvrdo (kandidát v komentáři #137).

**Cíl:**

1. `SectionHeading` a `Card` umí nadpis v roli title a ikonu (D36, D38).
2. Přehled firmy: nadpisy karet title + ikona sekce z konfigurace.
3. Karty Nastavení: nadpis a ikona přes `Card`, mezinadpisy
   `SectionHeading`; vlastní třídy nadpisů pryč.
4. Karta feedu a nadpis sekce feedu na rolích (D37).

**Mimo rozsah:** nadpisy stránek (`h1` Přehledu firmy a dashboardu), ikony
jinde než v nadpisu karty, vnitřní rozložení karty feedu.

## Návaznost

- **#133** — D15, D28, D33, D36, D37, D38
- **#137** task 2 — `Card`, `CardGrid`; komentář s kandidátem typografie
  feedu
- **#120** — Přehled firmy (`docs/overview.md` §2, §3)

## Před implementací přečti

- `docs/design-system.md` §8 Karty, §11 Typografie
- `frontend/src/components/ui/SectionHeading.svelte`, `Card.svelte`,
  `Icon.svelte`; `frontend/src/icons.js` (`resolveIcon`)
- `frontend/src/components/overview/CompanyOverview.svelte`
- `frontend/src/components/settings/SettingsPage.svelte`, `DsAbout.svelte`,
  `DsSetup.svelte`, `frontend/src/components/account/AccountSecurity.svelte`
- `frontend/src/components/dashboard/FeedCard.svelte`, `Feed.svelte`
- `src/Core/Overview/OverviewCollector.php`,
  `modules/core/system/config/overviewSections.jsonc`, `docs/overview.md`

## Rozhodnutí (potvrzená v #133)

- ✓ **D36** Nadpis karty podle jejího místa: karta jako samostatná část
  stránky (Přehled firmy, karty Nastavení) má nadpis v roli **title**
  (lg, strong); karta uvnitř obsahu (strany dokladu, karta návrhu, karty
  feedu) **heading**. `Card` volí roli nadpisu; mezinadpisy uvnitř karet
  Nastavení jsou `SectionHeading`.
- ✓ **D37** Karta feedu na rolích: název karty heading, částka title
  strong, hodnota rozbaleného detailu body normal, nadpis sekce feedu
  heading.
- ✓ **D38** Karta jako samostatná část stránky má v nadpisu ikonu tématu —
  stejnou jako příslušný prohlížeč / report v menu nebo stránka
  v Nastavení (žádné nové ikony), před nadpisem, velikost podle textu
  nadpisu, šedá (`--shpd-color-text-secondary`). Ikonu určuje definice
  stránky nebo sekce, pevné stránky frontendu ji mají v kódu. Mezinadpisy
  ikonu nemají. Karty Nastavení přecházejí na tentýž prvek (barva textu →
  šedá).

## 1. `SectionHeading` a `Card`

- `SectionHeading`:
  - prop `role`: `heading` (výchozí, dnešní vzhled) | `title` →
    `--shpd-font-size-title`, `--shpd-font-weight-strong`, `line-height`
    jako titulek detailu;
  - prop `icon` (objekt ikony, jako `Icon`) → `Icon size="md"` před textem,
    `color: var(--shpd-color-text-secondary)`, mezera `--shpd-space-sm`,
    zarovnání na střed řádku; bez `icon` beze změny.
- `Card`: props `titleRole` (`heading` výchozí | `title`) a `icon` předává
  do `SectionHeading`. Pod nadpisem v roli title je mezera rovná odsazení
  karty (md, u `spacious` lg) — dnešní karty Nastavení mají lg.
- Komentáře obou komponent doplnit o D36 / D38.

## 2. Přehled firmy

- `overviewSections.jsonc`: ke každé sekci `icon` — ikona prohlížeče nebo
  reportu, kam sekce vede (`module.jsonc` / menu):
  - `money` → ikona výpisů (`economy.bank.statements`),
  - `receivables`, `payables` → ikona případů saldokonta
    (`economy.accbal.cases`),
  - `vat` → ikona živého přiznání DPH v menu `economy.vat`,
  - `result` → ikona výsledovky v menu `economy.accounting`.
  Kde položka menu ikonu nemá, napiš to do §6 Odchylky a vezmi ikonu
  nejbližší položky téhož modulu — novou ikonu nepřidávat.
- `OverviewCollector`: sekce nese `icon` (string, nepovinný; chybí →
  klíč se neposílá); výchozí sekce v kódu (fallback) taky. Test
  v `OverviewCollectorTest`.
- `docs/overview.md` §3 kontrakt: `section.icon`.
- `CompanyOverview.svelte`: `Card titleRole="title"
  icon={section.icon ? resolveIcon(section.icon) : undefined}`.

## 3. Karty Nastavení

`SettingsPage`, `DsAbout`, `DsSetup`, `AccountSecurity`:

- vlastní `<h2 class="…__title">` s `Icon` → `Card title titleRole="title"
  icon` (ikona z `definition.icon` přes `resolveIcon`, u pevných stránek
  dnešní importovaná ikona);
- mezinadpisy (`.shpd-ds-about__block-title`,
  `.shpd-ds-setup__optional-title`, dnes `font-size-base` + `600`) →
  `SectionHeading` (heading, bez ikony);
- zaniklé třídy smazat a ověřit grepem (`--include=*.svelte --include=*.js`).

## 4. Karta feedu (D37)

`FeedCard.svelte`:

| Prvek | Dnes | Na |
|---|---|---|
| `__title` | body + strong | `--shpd-font-size-heading` + strong |
| `__amount` | `xl`, `700` | `--shpd-font-size-title`, `--shpd-font-weight-strong` |
| `__detail-value` | `500` | `--shpd-font-size-body`, normal |

`Feed.svelte`: `.shpd-feed__section-title` (`sm` + `600`) →
`SectionHeading` (heading); pokud nese počet nebo akci vpravo, do `aside`.

Žádné `500` / `700` ve `FeedCard.svelte` a `Feed.svelte` po úpravě.

## 5. Dokumentace

- `docs/design-system.md` §8 Karty: D36 (role nadpisu podle místa karty),
  D38 (ikona tématu — odkud, kde, jaká barva; mezinadpisy bez ikony).
- §11 tabulka rolí, sloupec Kde: title i u nadpisu karty jako samostatné
  části stránky; dashboard (feed) mezi místy s rolemi.
- `docs/overview.md` §3 a §6: `section.icon`, nadpis karty title.

## Commity

1. `feat(frontend): nadpis karty v roli title s ikonou tématu (#137, D36, D38)`
   — `SectionHeading`, `Card`, karty Nastavení
2. `feat(core.overview): ikony sekcí Přehledu firmy (#137, D38)` —
   konfigurace, collector, test, `CompanyOverview`
3. `refactor(frontend): karta feedu na typografických rolích (#137, D37)`
4. `docs: nadpisy a ikony karet (#137, D36–D38)` — dokumentace,
   `**Stav:**` tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
vendor/bin/phpunit --filter 'OverviewCollectorTest'
```

V prohlížeči, světlý i tmavý vzhled:

- Přehled firmy — nadpisy karet 18 px s šedou ikonou, částky beze změny;
  sekce nedostupná / s chybou (tón rámečku) má ikonu taky;
- Nastavení — Aplikace a další stránka nastavení, O zdroji dat, Nastavení
  zdroje dat (mezinadpis „Volitelné“ bez ikony), Zabezpečení účtu:
  nadpis a mezera pod ním jako dnes, ikona šedá;
- dashboard — název karty a částka (o krok menší), rozbalený detail,
  nadpis sekce feedu.

## Hotovo když

- [x] `SectionHeading` / `Card` s rolí nadpisu a ikonou
- [x] Přehled firmy s ikonami sekcí z konfigurace, kontrakt a test
- [x] karty Nastavení na `Card` nadpisu, mezinadpisy `SectionHeading`
- [x] karta feedu a nadpis sekce feedu bez literálů tloušťky
- [x] dokumentace, `**Stav:**`, `tasks-index.py`

## Odchylky od zadání

1. **Ikony sekcí po ověření v prohlížeči:** DPH má ikonu Podání DPH
   (`filing`), ne živého přiznání v menu — reporty tam mají jen obecnou
   `chart` (`NavigationController`), DPH by splynula s Výsledkem;
   Pohledávky a Závazky mají ikony položek saldokonta podle typu
   (`receivable` / `payable`), ne obecnou ikonu Saldokonta. Zapsáno
   v `docs/overview.md` §8.
2. **`SectionHeading` má navíc prop `spacing`** (`xs` | `md` | `lg`) —
   bez něj `Card` neumí dát pod nadpis v roli title mezeru rovnou svému
   odsazení; s `aside` ji nese řádek.
3. **Úvodní odstavec** O zdroji dat a Nastavení zdroje dat je pod nadpisem
   o `lg` (dřív `sm`) — pravidlo „mezera rovná odsazení karty“ platí pro
   všechny čtyři karty stejně.
4. **Nadpis sekce feedu** přišel o verzálky a šedou — důsledek D37 a §11
   (verzálky jen ve větších nadpisech); tečka a počet zůstaly v nadpisu,
   Archivovat vše v `aside`.

## Pasti

- **`Icon` velikost je v `em`** — ikona v nadpisu role title je větší
  než v heading sama; nepřepisovat ji px.
- **Ikona je dekorace** — `Icon` bez `label` (pak má `aria-hidden`), nadpis čte jen text.
- **`resolveIcon` má fallback** (`iconTable`) — u Přehledu bez `icon`
  ikonu nekreslit vůbec, ne fallback.
- **Kompilace konfigurace** — nový klíč v `overviewSections.jsonc` se
  projeví až po `vendor/bin/shpd-ds ds-upgrade` v dev DS (`docs/modules.md`
  → kompilace konfigurace); ověřit, že se `icon` do compiled configu
  dostane.
- **px v CSS** zastaví pre-commit.
