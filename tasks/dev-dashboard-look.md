# Vývojářský dashboard — vzhled podle shipard.dev (fáze 1)

**Stav:** naplánováno — zadání podle #129 (B1, B2, D1–D9, D13–D15)

## Kontext / Cíl

Vývojářský dashboard (`/_dev/`) je první obrazovka, kterou nový vývojář
nebo pokročilý tester uvidí. Dnes přebírá generický tmavý režim aplikace
(špatný kontrast tlačítek), nemá ikony ani logo, lišta při rolování
odjede a jsou v něm dvě vizuální chyby (B1, B2).

**Cíl fáze 1:** dashboard ve světlém vzhledu webu shipard.dev — paleta,
písmo, logo, ikony, tlačítka s významovými barvami, lepicí oranžový pruh
s lištou, verze s hashem commitu, prázdný stav pro nováčka. Opravit obě
chyby a zavést kontrolu, která podobné chyby příště zachytí.

**Mimo rozsah:** čeština / angličtina (D12, fáze 2), štítek zastaralosti
a tlačítko Aktualizovat (D10–D11, fáze 3), jakákoli změna vzhledu
aplikace kromě oprav tokenů z B1.

## Návaznost

- **#129** — rozhodnutí D1–D15, chyby B1 a B2, fáze 1–3
- **#103** F6a/F6b (dashboard ve Svelte, úlohy), D71 (kebab menu), `tasks/ds-delete.md`
- **#128** — vzor „jeden řádek, krátké štítky, detail v bublině“ (pro fázi 3)
- Web shipard.dev: `shpd_web:web-dev/static/style.css` (paleta),
  `shpd_web:web-dev/static/shipard-logo.svg` (logo)

## Před implementací přečti

- `docs/frontend.md` → Vývojářský dashboard
- `frontend/src/dev/DevApp.svelte`, `dev.css`, `main.js`, `info.svelte.js`,
  `components/DevKebabMenu.svelte`, `components/DevDialog.svelte`,
  `components/PdfPane.svelte`, `pages/DataSourcesPage.svelte`
- `frontend/src/styles/variables.css`, `components/ui/Button.svelte`,
  `components/ui/Popover.svelte` (`position: fixed; z-index: 1000`)
- `frontend/src/icons.js` — pojmenování podle významu
- `src/Api/Controller/DevDashboardController.php` (`getInfo()`),
  `src/Core/Version.php`, `src/Core/Config/ConfigCompiler.php`
- `tests/Unit/Api/Controller/DevDashboardControllerTest.php`,
  `tests/Unit/Core/VersionTest.php`, `tests/Unit/Core/Config/ConfigCompilerTest.php`
- `.githooks/pre-commit`, `scripts/check-sensitive.py` (vzor kontroly v Pythonu)

## 1. Chyby

**B1 — nedefinované tokeny.** `dev.css` používá `--shpd-color-surface`,
`--shpd-color-surface-alt` a `--shpd-color-text-muted`, které v
`variables.css` nejsou — karta dialogu nemá pozadí. Stejné tokeny bez
fallbacku používá i aplikace (`text-muted` 39×, `surface` 12×, jedenáct
komponent, např. `DocumentExchangePreviewModal`,
`AssetsDepreciationRunDialog`, `SessionsPanel` s `--shpd-font-size-xs`).
Oprava náhradou za existující tokeny, žádné aliasy:

| Nedefinovaný | Nahradit |
|---|---|
| `--shpd-color-surface` | `--shpd-color-bg` |
| `--shpd-color-surface-alt` | `--shpd-color-bg-secondary` |
| `--shpd-color-text-muted` | `--shpd-color-text-secondary` |
| `--shpd-font-size-xs` | doplnit do škály v `variables.css`: `0.75rem` |

V aplikaci to je viditelná změna (šedý pomocný text, plochy s pozadím) —
proto je v „Hotovo když“ proklik dotčených komponent ve světlém
i tmavém vzhledu.

**B2 — kebab menu na dalším řádku.** `.dev-actions` je v `dev.css`
dvakrát; pozdější pravidlo (F6b) dává `display: flex; flex-wrap: wrap`
a zasáhne i `<td class="dev-actions">` v přehledu zdrojů dat. Řadu
tlačítek z F6b přejmenuj na `.dev-btnrow` (`DataSourcesPanel`,
`EvalRunDialog`, `VariantEditorPage`); buňka tabulky zůstane
`white-space: nowrap` bez flexu (obsah v `inline-flex` obalu).

## 2. Kontrola CSS tokenů (D14)

`scripts/check-css-tokens.py` — Python jako ostatní kontroly v hooku
(Node v neinteraktivním shellu není v `PATH`):

- projde `frontend/src/**/*.{css,svelte,js}`;
- **definice** = `--shpd-…:` kdekoli (CSS, `style="…"` v markupu),
  Svelte direktiva `style:--shpd-…=`, `setProperty('--shpd-…'` v JS;
- **použití bez fallbacku** = `var(--shpd-…)` bez čárky; `var(--x, y)`
  je v pořádku;
- nedefinované použití → exit 1 s výpisem token → soubory.

Zapojit do `.githooks/pre-commit` za kontrolu tasků, se stejným tvarem
hlášky („Commit zastaven: …“). Kontroluje celý strom, ne jen staged
soubory (je rychlá a nedefinovaný token může vzniknout smazáním definice).

## 3. Verze (D9)

- `Version::gitInfo(): ?array` → `{hash, fullHash, branch, commitDate}`
  (krátký a plný hash, větev, datum commitu ISO 8601). Jedno volání
  `git log -1 --format=…` + `git rev-parse --abbrev-ref HEAD`
  (odpojená HEAD → `branch: null`). Parsování výstupu jako čistá funkce
  s unit testem; výsledek cachovaný po dobu procesu jako `gitHash()`.
  Bez gitu nebo s odmítnutím (PHP-FPM pod jiným uživatelem než vlastník
  checkoutu → „dubious ownership“) vrací `null` — **nepřidávat**
  `safe.directory` automaticky.
- `GET /_dev/api/info` přidá `version: {version, hash, fullHash, branch,
  commitDate}` (`version` = `Version::VERSION`, ostatní mohou být `null`).
  Stávající klíče beze změny.
- `ConfigCompiler` ztratí vlastní `VERSION = '0.1.0'` a do `_meta.version`
  zapíše `Version::VERSION`; upravit aserci v `ConfigCompilerTest`.

## 4. Vzhled — vrstva dashboardu (D1–D3, D13)

**`frontend/src/dev/theme.css`**, importovaný v `src/dev/main.js` po
`variables.css` a `base.css`, před `dev.css`. Přepisuje hodnoty tokenů
v `:root` jen v bundlu dashboardu:

| Token | Hodnota | Na webu |
|---|---|---|
| `--shpd-color-primary` / `-hover` | `#005089` / `#00436f` | `--blue` / `--blue-deep` |
| `--shpd-color-primary-soft` | `#e6eef5` | |
| `--shpd-color-accent` / `-hover` | `#EB6507` / `#cf5804` | `--orange` / `--orange-deep` |
| `--shpd-color-danger` / `-hover` | `#c81e1e` / `#a51818` | (bílý text 5,8 : 1) |
| `--shpd-color-text` / `-secondary` | `#1c2b3a` / `#5a6b7b` | `--text` / `--text-muted` |
| `--shpd-color-border` / `-strong` / `-subtle` | `#d7dee6` / `#c3cfdb` / `#e8edf2` | `--border` |
| `--shpd-color-bg` / `-secondary` / `-hover` | `#ffffff` / `#f4f6f8` / `#eef3f8` | `--card-gray`, `--bg-light` |
| `--shpd-font-family` | `'IBM Plex Sans', -apple-system, 'Segoe UI', sans-serif` | |

Tokeny jen dashboardu (prefix `--dev-`):

| Token | Hodnota | K čemu |
|---|---|---|
| `--dev-stripe-bg` / `-ink` | `#EB6507` / `#14202c` | pruh; tmavý text 5 : 1 (D13) |
| `--dev-stripe-chip-bg` | `rgb(20 32 44 / 0.14)` | štítky v pruhu (fáze 3) |
| `--dev-toolbar-bg` | `#005089` | lišta |
| `--dev-toolbar-link` / `-active` | `#cfe0ee` / `#ffffff` | navigace v liště |
| `--dev-class-real-bg` / `-text` | `#fff3c4` / `#5a4300` | štítek „reálná kopie“ (žlutá z pracovního pruhu webu) |
| `--dev-top-height` | výška pruhu + lišty | odsazení lepicích prvků pod lištou |

- **Jen světlý vzhled (D2):** z `dev.html` zmizí skript, který podle
  `prefers-color-scheme` nastavuje `data-theme="dark"`. Dashboard
  `data-theme` nenastavuje nikdy. `.dev-output` (výstup příkazů) zůstává
  tmavý.
- **Písmo (D3):** `@fontsource/ibm-plex-sans` (400, 500, 600, 700)
  a `@fontsource/ibm-plex-mono` (400, 500), licence OFL-1.1, do
  `dependencies` vedle Font Awesome; importy jen v `src/dev/main.js`.
  `code` a `.dev-code` v dashboardu na IBM Plex Mono. Velikosti písma
  a hustota zůstávají z aplikace. Žádné Google Fonts.
- **Focus:** `:focus-visible` u odkazů a tlačítek v dashboardu oranžový
  obrys 2 px s odsazením 2 px (jako web); pole formulářů si ponechají
  modrý rámeček a záři.
- **Sdílený `Button`:** vedlejší tlačítko dostane volitelné tokeny
  s fallbackem — `color: var(--shpd-btn-secondary-text,
  var(--shpd-color-text))`, `border-color: var(--shpd-btn-secondary-border,
  var(--shpd-color-border))`. Aplikace je nedefinuje, vzhled se jí nemění;
  `theme.css` je nastaví na `--shpd-color-primary` a `--shpd-color-border-strong`.

## 5. Pruh a lišta (D6–D8, D13)

`DevApp.svelte`:

```
<div class="dev-shell">
  <header class="dev-top">          ← position: sticky; top: 0; z-index: 100
    <div class="dev-stripe">…</div>
    <div class="dev-toolbar">…</div>
  </header>
  <main class="dev-main">…</main>
</div>
```

- **Pruh** (`--dev-stripe-bg`, text `--dev-stripe-ink`, 13 px, jeden
  řádek, při úzkém okně smí zalomit): vlevo tučně s prostrkáním
  **DEVELOPMENT DASHBOARD**; vpravo ikona serveru + hostname, pak ikona
  commitu + `v0.1.1 · 02e2d3b7 · stable` v IBM Plex Mono jako odkaz
  `https://github.com/shipard/shipard/commit/<fullHash>` (nový tab),
  `title` s datem commitu. Bez hashe jen `v0.1.1` bez odkazu; při
  načítání `…`, při chybě `?` (jako dnes hostname). Text „DEVELOPMENT
  MODE — nenasazovat veřejně“ zmizí.
- **Lišta** (`--dev-toolbar-bg`, min. výška 60 px, `flex-wrap`): vlevo
  logo (výška 28 px, `alt="Shipard"`) jako odkaz na Zdroje dat přes
  router — místo titulku „Shipard · vývojářský dashboard“, který zmizí;
  pak navigace s ikonami: barva `--dev-toolbar-link`, hover a aktivní
  `--dev-toolbar-link-active`, aktivní položka podtržená 2 px
  `--shpd-color-accent` a `aria-current="page"` (vzor
  `.nav-links a[aria-current="page"]` na webu). Vpravo odkaz
  **Dokumentace** (ikona knihy) na `https://shipard.dev/pro-vyvojare/`
  v novém tabu; místo pro přepínač jazyka (fáze 2) nech volné.
  Hostname se z lišty přesouvá do pruhu.
- **Logo:** zkopíruj `shpd_web:web-dev/static/shipard-logo.svg` do
  `frontend/src/dev/assets/shipard-logo.svg` a importuj ho přes Vite
  jako URL (bílý nápis s oranžovým „i“ — patří na modrou lištu).
- **Favicon:** `dev.html` dostane `<link rel="icon">` na ikonu aplikace
  zkopírovanou do `frontend/src/dev/assets/` (`publicDir` je pro
  dashboard vypnutý).
- **Lepení (D7):** `.dev-top` je sticky se `z-index: 100` — pod
  `Popover` a `DevDialog` (oba 1000). `PdfPane` (Testování) je sám
  sticky s `top: var(--shpd-space-md)`: posuň ho na
  `calc(var(--dev-top-height) + var(--shpd-space-md))`, jinak zajede
  pod lištu. Totéž zkontroluj u všeho dalšího sticky v `src/dev/`.
- `document.title` beze změny.

## 6. Ikony (D5)

Existující ikony použij, nové přidej do `icons.js` podle konvence
(import + export + `iconMap`, pojmenování podle významu):

| Kde | Ikona |
|---|---|
| Zdroje dat | `iconDatabase` |
| Testování | nová `iconTesting` (`faFlask`) |
| Logy (navigace i kebab) | `iconDocument` (`faFileLines`) |
| Doctor | nová `iconDoctor` (`faStethoscope`) |
| Upgrade všech, Upgrade (kebab) | nová `iconUpgrade` (`faArrowUpFromBracket`) |
| Dokumentace | `iconBook` |
| Server v pruhu | `iconServer` |
| Verze v pruhu | nová `iconCommit` (`faCodeCommit`) |
| Otevřít | `iconOpenExternal` |
| Nahrát datovou sadu, Kopírovat ID, Smazat… | `iconUpload`, `iconCopy`, `iconDelete` |
| Obnovit, Nový … | `iconRefresh`, `iconAdd` |
| Běžící úloha v řádku (dnes znak „⟳“) | `iconRefresh` |
| Cron vypnutý | nová `iconClock` (`faClock`) |

`DevKebabMenu` dostane u položky volitelné `icon`; ikona před textem,
u položky `danger` v barvě `--shpd-color-danger` jako text. Před
Smazat… oddělovač.

## 7. Tlačítka (D4)

- **Modrá (`primary`)** — hlavní akce obrazovky nebo dialogu, nejvýš
  jedna: Nový zdroj dat, Vytvořit zdroj dat, Spustit (Doctor, Upgrade),
  Spustit evaluaci (v dialogu), Uložit variantu / změny, Založit
  evaluační zdroj dat, Nová varianta, Porovnat vybrané.
- **Bílá s modrým textem (`secondary`)** — vše ostatní: Obnovit, Zavřít,
  Zrušit v dialogu, Spočítat odhad, Vytvořit další, Zkusit znovu,
  Přeseedovat, Ověřit zaúčtování. Akce opakovaná v každém řádku tabulky
  je `secondary`, i když jde o hlavní akci řádku — `Spustit evaluaci`
  v řádcích `DataSourcesPanel` přejde z `primary` na `secondary`.
- **Červená (`danger`)** — tlačítko, které nevratnou akci **provede**:
  Smazat, Nahrát / Nahradit sadu (reset dat), Zrušit a Vynutit ukončení
  úlohy, potvrzení v `DevConfirm` s `variant: 'danger'`. Tlačítko, které
  jen otevře potvrzovací dialog, zůstává `secondary`.
- Oranžová se na tlačítka nepoužívá.
- Projdi všechny stránky (`LogsPage` a `ActionPage` mají tlačítka bez
  explicitní varianty) a srovnej je podle těchto pravidel.

## 8. Přehled zdrojů dat a prázdný stav (D15)

`DataSourcesPage.svelte`:

- nadpis stránek (`.dev-page__head h1`) v barvě `--shpd-color-primary`,
  velikost 1.625rem;
- ID v `code` s rámečkem `--shpd-color-border`;
- **třída dat jako štítek:** volný = `--shpd-color-primary-soft`
  / `--shpd-color-primary`; reálná kopie = `--dev-class-real-bg`
  / `--dev-class-real-text`; bez explicitní třídy (`dataClassExplicit:
  false`) štítek jen s rámečkem a sekundárním textem, bublina beze změny;
  značka AI jako štítek s rámečkem;
- **cron:** „zapnutý“ / „vypnutý“; vypnutý v `--shpd-color-danger`
  s ikonou hodin, bublina beze změny;
- **Otevřít:** odkaz stylovaný jako tlačítko s modrým rámečkem a textem
  a ikonou `iconOpenExternal` za textem; kebab tlačítko 32 × 32 px,
  otevřené ve stavu `primary-soft` s modrým rámečkem;
- **prázdný stav** (`items.length === 0` bez chyby) místo řádku
  v tabulce:
  - karta: štítek „Začni tady“, nadpis „Na tomhle stroji zatím nejsou
    žádné zdroje dat“, odstavec „Zdroj dat je jedna firma s vlastní
    databází. Nejrychleji začneš zdrojem dat s ukázkovou datovou sadou —
    dostaneš firmu s doklady, poštou a uživatelem, pod kterým se
    přihlásíš.“, kroky „Založ zdroj dat a v poli Data vyber datovou
    sadu.“ / „Počkej, až průběh doběhne.“ / „Klikni na Otevřít
    a přihlas se zvoleným uživatelem.“, tlačítko `primary` **Nový
    zdroj dat s ukázkovými daty** (routa `dsCreate`) a odkaz jako
    `secondary` **Návod pro začátečníky**
    (`https://shipard.dev/pro-vyvojare/`);
  - vedle karty panel „Co tu ještě najdeš“ (pozadí
    `--shpd-color-bg-hover`): Logy — „Chyby a varování aplikace,
    filtrované podle zdroje dat.“, Doctor — „Kontrola stroje — práva,
    služby, konfigurace.“, Upgrade všech — „Po stažení nových změn
    převede všechny zdroje dat na nové tabulky a nastavení.“;
  - na úzkém okně se panel zařadí pod kartu (`flex-wrap`).

Texty zůstávají česky přímo v komponentách (D12 až ve fázi 2). Piš je
celými větami, ne skládáním kousků — ať jdou později vytáhnout do
slovníku beze změny.

## Kroky

1. **Kontrola tokenů a B1:** `check-css-tokens.py`, hook, náhrada
   nedefinovaných tokenů v aplikaci i v dashboardu,
   `--shpd-font-size-xs`. Commit `frontend: kontrola CSS tokenů, oprava
   nedefinovaných tokenů (#129 D14, B1)`.
2. **Verze:** `Version::gitInfo()`, `/_dev/api/info`, `ConfigCompiler`,
   testy (`VersionTest`, `DevDashboardControllerTest`,
   `ConfigCompilerTest`).
3. **Vrstva vzhledu:** `theme.css`, písma, tokeny vedlejšího tlačítka
   v `Button`, odstranění tmavého bootstrapu, B2.
4. **Pruh a lišta:** sticky blok, pruh s verzí, lišta s logem,
   navigací s ikonami a Dokumentací, favicon, `PdfPane`, ikony
   v kebab menu.
5. **Tlačítka a stránky:** varianty podle § 7, přehled zdrojů dat
   a prázdný stav podle § 8.
6. **Dokumentace a uzavření:** níže; hlavička tasku,
   `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] `python3 scripts/check-css-tokens.py` projde; hook commit
      s dočasně přidaným nedefinovaným tokenem zastaví (ověřeno ručně,
      změna se nekomituje)
- [ ] `vendor/bin/phpunit --filter 'VersionTest|DevDashboardControllerTest|ConfigCompilerTest'`
      zelené, pak celá sada
- [ ] `cd frontend && npm run build` a `npm test` projdou
- [ ] dashboard v prohlížeči se systémovým tmavým režimem zůstává
      světlý; písmo IBM Plex se načítá z vlastního bundlu (žádný
      požadavek na cizí doménu)
- [ ] pruh a lišta zůstávají nahoře při rolování dlouhé stránky (Logy);
      kebab menu i dialogy jsou nad lištou; `PdfPane` nezajede pod lištu
- [ ] pruh: DEVELOPMENT DASHBOARD, hostname, verze s hashem jako odkaz
      na commit; bez gitu jen číslo verze (testem)
- [ ] dialog Smazat zdroj dat má pozadí (B1); kebab menu se v přehledu
      zdrojů dat nezalomí ani při šířce okna kolem 1 100 px (B2)
- [ ] tlačítka na všech stránkách podle § 7; ikony podle § 6
- [ ] prázdný stav ověřený bez mazání zdrojů dat (např. dočasnou
      podmínkou, která se nekomituje, nebo na čisté VM)
- [ ] v aplikaci proklik komponent dotčených B1 (aspoň
      `DocumentExchangePreviewModal`, `AssetsDepreciationRunDialog`,
      `SessionsPanel`) ve světlém i tmavém vzhledu — pomocný text šedě,
      plochy s pozadím, nic nezmizelo
- [ ] dokumentace: `docs/frontend.md` → Vývojářský dashboard
      (`theme.css`, písma, tokeny `--dev-*`, tokeny vedlejšího tlačítka,
      logo, kontrola tokenů); `CLAUDE.md` → Vývojářský dashboard
      (sdílí také `theme.css` a tokeny tlačítka); `docs/dev/dev-daily.md`
      § 1 (pruh DEVELOPMENT DASHBOARD s verzí, odkaz Dokumentace,
      prázdný stav — dnešní věta o pruhu „DEVELOPMENT MODE“); popis
      kontroly tokenů u pre-commit hooku tam, kde jsou popsané ostatní
      kontroly; `docs/roadmap.md` M6 — řádek „Vývojářský dashboard:
      vzhled, verze, čeština a angličtina, aktualizace (#129)“;
      `tasks/README.md` → sekce Dev dashboard — řádek tohoto tasku
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Vzhled aplikace se nemění** kromě oprav tokenů z B1. `theme.css`
  a písma importuje jen `src/dev/main.js`; sdílený `Button` dostane jen
  tokeny s fallbackem na dnešní hodnoty.
- **`data-theme` v dashboardu nikdy.** `variables.css` má blok
  `[data-theme="dark"]`, který by vrstvu dashboardu přepsal.
- **Z-index:** `Popover` a `DevDialog` mají 1000, lišta 100. Nic
  v dashboardu nesmí lištu překrýt ani pod ní zmizet.
- **Hash z PHP** běží pod uživatelem PHP-FPM. Když git odmítne
  (vlastník checkoutu je jiný), je to `null`, ne chyba stránky.
- **JS jen s ASCII uvozovkami**; české `„…“` jen v textech pro člověka.
- **Kontrola tokenů** nesmí hlásit `var(--x, fallback)` ani tokeny
  nastavené direktivou `style:--…` nebo `setProperty` (např.
  `--shpd-form-section-cols` ve `FormSection.svelte`, tokeny vlastního
  vzhledu v `utils/themeColor.js`).

## Rozhodnutí k designu (potvrzená)

Úplné znění v #129.

- ✓ **D1** — vrstva vzhledu `frontend/src/dev/theme.css` přepisuje
  hodnoty tokenů `--shpd-*` jen v bundlu dashboardu; sdílený `Button`
  dostane volitelné tokeny vedlejšího tlačítka s fallbackem.
- ✓ **D2** — jen světlý vzhled, jako shipard.dev.
- ✓ **D3** — IBM Plex Sans / Mono z `@fontsource/*`, jen v dashboardu;
  velikosti a hustota z aplikace.
- ✓ **D4** — modrá = hlavní akce (nejvýš jedna), bílá s modrým textem =
  vedlejší, červená = nevratné; oranžová patří pruhu, podtržení aktivní
  položky a focusu.
- ✓ **D5** — ikony Font Awesome přes `icons.js` v navigaci, tlačítkách
  a kebab menu.
- ✓ **D6** — logo shipard.dev v liště místo titulku; favicon.
- ✓ **D7** — pruh a lišta jako jeden sticky blok.
- ✓ **D8** — pruh = jeden řádek (DEVELOPMENT DASHBOARD, server, verze);
  lišta = logo, navigace, Dokumentace; samostatný status bar ne.
- ✓ **D9** — verze, hash, větev a datum commitu v `/_dev/api/info`
  a v pruhu; `ConfigCompiler` bez vlastní konstanty verze.
- ✓ **D13** — tmavý text v oranžovém pruhu (`#14202c`).
- ✓ **D14** — `scripts/check-css-tokens.py` v pre-commit hooku;
  nedefinované tokeny opravené v dashboardu i v aplikaci.
- ✓ **D15** — prázdný stav „Začni tady“ a odkaz Dokumentace na
  `https://shipard.dev/pro-vyvojare/`.
- D10–D11 (zastaralost, Aktualizovat) a D12 (čeština a angličtina)
  jsou samostatné fáze 3 a 2.
