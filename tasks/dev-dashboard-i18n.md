# Vývojářský dashboard — čeština a angličtina (fáze 2)

**Stav:** hotovo — 2026-10-17, fáze 2 podle #129 D12; fáze 3 (D10–D11 zastaralost a Aktualizovat) je samostatná

## Kontext / Cíl

Vývojářský dashboard (`/_dev/`) má po fázi 1 (`dev-dashboard-look.md`)
vzhled shipard.dev, ale texty jen česky, napsané přímo v komponentách.
Nový vývojář nebo tester, který česky neumí, se v něm neorientuje.

**Cíl fáze 2:** dashboard v češtině a angličtině — vlastní slovníky a `t()`
dashboardu nad `intl-messageformat`, výchozí jazyk podle prohlížeče,
přepínač v liště, volba v `localStorage`, a kontrola `check:i18n`, která
pokryje i slovníky dashboardu a klíče, které komponenty skutečně používají.

**Mimo rozsah:** výstupy CLI ve streamu a chybové zprávy serveru
(`ApiError.message`) se nepřekládají (D12); štítek zastaralosti
a Aktualizovat (D10–D11, fáze 3).

## Návaznost

- **#129** — D8 (místo pro přepínač v liště), D12 (toto zadání)
- `tasks/dev-dashboard-look.md` — fáze 1: lišta `DevApp.svelte`
  (`.dev-topbar`, komentář s místem pro přepínač), `theme.css`, pravidla
  tlačítek; texty psané celými větami právě proto, aby šly vytáhnout
- Aplikace: `frontend/src/stores/language.svelte.js` (vzor `t()` nad
  `IntlMessageFormat` s cache, reload po přepnutí), `frontend/src/i18n/`,
  `frontend/scripts/check-i18n.mjs`, `tasks/frontend-i18n-phase1a.md`

## Před implementací přečti

- `docs/frontend.md` → Vývojářský dashboard; → Vícejazyčnost
- `frontend/src/dev/**` — všechny soubory s textem (inventura: `grep -l
  "[ěščřžýáíéúů]" frontend/src/dev -r`), zvlášť `format.js`, `logs.js`,
  `testing/jobs.js`, `testing/metrics.js` (popisky v čistých modulech)
- `frontend/tests/Unit/dev/*.test.mjs` — testy, které asertují české popisky

## 1. Slovník a helper (D12)

`frontend/src/dev/i18n/`:

- `cs.js`, `en.js` — ploché objekty, tečkové klíče podle obrazovky
  (`app.*`, `common.*`, `ds.*`, `dsCreate.*`, `dsDelete.*`, `dsLoad.*`,
  `logs.*`, `action.*`, `notFound.*`, `testing.*`, `jobs.*`, `metrics.*`,
  `format.*`). ICU MessageFormat jako v aplikaci: parametry `{name}`,
  plurály `{n, plural, one {…} few {…} other {…}}` (uvnitř `#`), apostrof
  `''`. České texty jsou dnešní texty komponent **beze změny**.
- `index.js` — `t(key, params)` nad `IntlMessageFormat` s cache formátovačů,
  fallback jazyk → `en` → klíč; `has(key)`; `label(prefix, code)` =
  popisek kódu ze serveru (klíč chybí → kód sám, ne klíč); `currentLanguage()`;
  čistá `resolveLanguage(stored, navigatorLanguage)`; `use(lang)` bez
  persistence (start, testy); `setLanguage(lang)` = `localStorage`
  `shpd_dev_language` + `location.reload()`.
- **Ne** store aplikace (`stores/language.svelte.js` tahá `api/account.js`
  a per-user nastavení; dashboard uživatele nemá) a ne `t()` z `i18n/index.js`.

## 2. Volba jazyka a přepínač

- Výchozí jazyk: uložená platná hodnota `shpd_dev_language`, jinak
  `navigator.language` — čeština → `cs`, cokoli jiného → `en`.
- `main.js` před mountem nastaví `document.documentElement.lang`; `dev.html`
  nechá `lang="cs"` jako výchozí před startem skriptu.
- Přepínač v liště mezi navigací a Dokumentací (D8): `CS · EN` ve stylu
  `.lang-switch` webu — aktuální jazyk zvýrazněný, druhý tlačítko, které
  volá `setLanguage()`; `aria-label` Jazyk / Language.
- Přepnutí = reload (jako v aplikaci). Dashboard nedrží rozpracovaný stav,
  který by reload ztratil, a `t()` tak zůstane čistá funkce bez reaktivity.

## 3. Co se překládá a co ne

Překládá se vše, co píše dashboard sám: nadpisy, navigace, `document.title`,
popisky sloupců a tlačítek, bubliny (`title`), hlášky stavů (Načítám…,
prázdné stavy), validace formuláře Nový zdroj dat, potvrzovací dialogy,
popisky kódů ze serveru (stav úlohy, typ úlohy, krok, stav pole, skupiny
a metriky evaluace), prázdný `dev-output` (CSS `content` → `attr(data-empty)`).

Nepřekládá se: řádky streamu z CLI, `ApiError.message` a `error.problems`
ze serveru, popisky skupin a tabulek v detailu dokladu evaluace (`group.label`,
`table.label` posílá server), názvy sad z manifestu, kódy a ID (`exit 1`,
`pid`, `c.status` v tabulce kontrol), hodnoty enumů ukazované jako kód.

## 4. Formáty

`format.js` formátuje podle jazyka: `cs-CZ` / `en-GB` pro čísla a data
(`16. 10. 2026 14:05` / `16/10/2026, 14:05`), jednotka procentních bodů
`b.` / `pp`, `dataClassLabel` / `dataClassTitle` přes slovník. `DevTable`
řadí `localeCompare` podle aktuálního jazyka.

## 5. Kontrola parity a použitých klíčů

`frontend/scripts/check-i18n.mjs` (`npm run check:i18n`) nově:

- parita klíčů cs ↔ en i pro `src/dev/i18n/`;
- každý statický klíč `t('…')` / `label('prefix', …)` v `frontend/src/dev/**`
  musí ve slovníku dashboardu být; klíč slovníku, který nikde nepoužívá ani
  statické volání, ani prefix dynamického (`t(\`jobs.status.${…}\`)`,
  `label('jobs.status', …)`), je chyba — slovník zůstane čistý.

## Kroky

1. Slovník, helper, testy (`tests/Unit/dev/i18n.test.mjs`), `check:i18n`.
2. `format.js`, `logs.js`, `testing/jobs.js`, `testing/metrics.js` — popisky
   přes slovník; stávající testy přepnou `use('cs')`, doplnit `en` případy.
3. `DevApp` (navigace, pruh, titulky, přepínač), komponenty, stránky Zdroje
   dat, dialogy, Nový zdroj dat, Logy, akce, 404.
4. Sekce Testování (sady, běhy, detail běhu, doklad, porovnání, doklad
   napříč běhy, úlohy, detail úlohy, varianty, editor, panel zdrojů dat,
   dialog evaluace, výsledek ověření).
5. Build, `npm test`, `check:i18n`, headless v obou jazycích.
6. Dokumentace a uzavření.

## Hotovo když

- [x] `cd frontend && npm test && npm run check:i18n && npm run build` zelené;
      `python3 scripts/check-css-tokens.py` projde
- [x] `grep -rn "[ěščřžýáíéúů]" frontend/src/dev --include=*.svelte` najde jen
      komentáře (žádný text pro člověka mimo slovník)
- [x] headless: s `shpd_dev_language=en` je lišta, přehled zdrojů dat, prázdný
      stav, Logy, Doctor, Testování a dialog Smazat anglicky a `<html lang="en">`;
      bez uložené volby a s `navigator.language` `cs-CZ` česky, s `de-DE` anglicky;
      přepínač v liště přepne a volbu uloží
- [x] dokumentace: `docs/frontend.md` → Vývojářský dashboard (slovník, `t()`,
      přepínač, co se nepřekládá, `check:i18n`), → Vícejazyčnost (odkaz);
      `CLAUDE.md` → Frontend — Vícejazyčnost (dashboard má vlastní slovník
      a klíč `shpd_dev_language`, `check:i18n` kontroluje i použité klíče);
      `docs/dev/dev-daily.md` § 1 (přepínač CS · EN)
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Dva slovníky, dvě `t()`.** Dashboard nesmí importovat `i18n/index.js`
  aplikace ani `stores/language.svelte.js` (tahají `api/account.js`,
  `api/client.js`); aplikace nesmí importovat `src/dev/i18n`.
- **Klíč místo textu.** `t()` neznámého klíče vrátí klíč — proto kontrola
  použitých klíčů v `check:i18n`; `label()` pro kódy ze serveru vrací kód.
- **ICU:** `{` `}` v textu jen jako parametry; apostrof `''`; uvnitř plurálu
  `#`. Texty s `<id>` a cestami souborů jsou v pořádku.
- **Node testy:** `navigator.language` je v Node `en-US` → bez `use('cs')`
  by testy českých popisků padaly; `localStorage` v Node není.
- **JS jen s ASCII uvozovkami**; české `„…“` jen v textech slovníku.

## Rozhodnutí k designu (potvrzená)

- ✓ **D12** (#129) — vlastní slovníky a `t()` dashboardu nad
  `intl-messageformat`; výchozí jazyk z `navigator.language` (čeština → cs,
  jinak en); přepínač v liště; volba v `localStorage` `shpd_dev_language`;
  `check:i18n` pokryje i slovníky dashboardu; výstupy CLI a chybové zprávy
  serveru se nepřekládají.
- Upřesnění v tomto zadání: přepnutí reloadem (§ 2), rozsah překladu (§ 3),
  formáty podle jazyka (§ 4), kontrola použitých a nepoužitých klíčů (§ 5).

## Odchylky a upřesnění při implementaci (2026-10-17)

- **Slovník má 570 klíčů**; české texty jsou původní texty komponent až na
  věty, které měly uvnitř `<code>` (nápovědy formulářů, poznámky o souborech,
  hláška o nahrazení sady): ty jsou ve slovníku jako jedna věta bez značek
  — `t()` vrací prostý text a drobení věty na úlomky kolem `<code>` by
  rozbilo slovosled v angličtině. Technické výrazy shodné v obou jazycích
  (`Model`, `Prompt`, `Thinking`, `Effort`, `Cron`, `ID`, `pid`, `exit`)
  zůstaly v markupu.
- **`ignoreTag: true`** v `IntlMessageFormat` (helper i kontrola): texty
  s `<id>` / `<název>` by jinak parser bral jako značky. Dashboard
  formátovací značky nepoužívá.
- **Kontrola nepoužitých klíčů** bere jako použití jakýkoli tečkovaný
  literál v kódu, ne jen `t('…')` — klíče v mapě (`TITLE_KEYS`) nebo
  v podmínce `t(a ? 'x' : 'y', …)` jinak vycházely jako nepoužité. Kontrola
  chybějících klíčů zůstává přísná (jen první argument `t('…')`).
- **Popisky kódů ze serveru** (`jobs.status.*`, `jobs.type.*`, `jobs.step.*`,
  `jobs.reason.*`, `jobs.stoppedBy.*`, `metrics.state.*`, `metrics.group.*`,
  `metrics.metric.*`, `metrics.part.*`, `ds.state.*`, `testing.evalRun.basis.*`)
  nahradily exportované mapy `JOB_*_LABELS`, `STATE_LABELS`, `FIELD_GROUPS`
  funkcemi `jobTypeLabel()`, `stateLabel()`, `fieldGroupLabel()` apod.;
  `DOCUMENT_METRICS` / `COST_METRICS` jsou pole klíčů, popisek dává
  `metricLabel()`.
- **Formáty:** `en-GB` (`16/10/2026, 14:05`, `1,234.5`); jednotky `ms`,
  `s`, `min`, `USD` jsou společné, procentní body `b.` / `pp`.
- Ověřeno headless: uložená volba `en` (lišta, přehled, dialog Smazat, Logy,
  Doctor, Testování, Úlohy), prohlížeč `cs-CZ` bez volby → česky, `de-DE`
  → anglicky, přepínač CS uloží volbu a po reloadu je dashboard česky,
  prázdný stav anglicky; `npm test` 198, `check:i18n` 570 klíčů, build.
