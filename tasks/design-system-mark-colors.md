# Design system — barvy značek bez textu (D74)

**Stav:** hotovo — 2026-11-11 (#133 Další nesoulady, D74); modrá tečka Nové položky zůstává v tmavém vzhledu pod prahem 3 : 1 a na `-mark` přešla i varovná výplň průběhového pruhu dashboardu (viz Odchylky); změřeno v Chromiu nad demo sadou IT servis v obou vzhledech, ručně ověřeno na dev serveru 2026-11-11

## Kontext / Cíl

Ruční ověření tasku `design-system-tone-colors.md` (D69–D72): ve světlém
vzhledu jsou tečky na dashboardu a donut jistoty AI analýzy příliš tmavé.
Task G je přepnul z plochy na `-text` tokeny (Odchylky 1), protože plocha
z D70 má v tmavém vzhledu proti pozadí jen 2,5–3 : 1. `-text` je ale
laděný na 4,5 : 1 pro malé písmo; značka bez textu potřebuje jen 3 : 1
(grafika). Modrá tečka Nové položky (`--shpd-color-primary` `#005089`)
se v G neměnila, vedle tmavších teček ale působí stejně těžce.

**Cíl:** značky bez textu mají vlastní token `-mark` (D74) — ve světlém
vzhledu světlejší, v tmavém beze změny.

**Mimo rozsah:**

- tečky a odznaky na pozadí sidebaru (`NavTree`, `TopMenuBar`,
  `SectionRail`) — vlastní bod #133 (tón pro sidebar);
- ikony vedle textu nebo s významem (varování ve feedu, ikona řádku
  reportu) — dál `-text` (D72);
- text na tónovaném podkladu — vlastní bod #133;
- plochy s bílým textem (tlačítka, odznaky s číslem) — D70 beze změny.

## Návaznost

- **#133** — D69, D70, D72, D74
- `tasks/design-system-tone-colors.md` — Odchylky 1 (tečky a donut na
  `-text`) a 8 (proužek karty Připraveno v tmavém vzhledu 2,98 : 1)

## Před implementací přečti

- `docs/design-system.md` §3 Stavové (tabulka plocha / `-text`, D69, D70)
- `frontend/src/styles/variables.css` — tónové tokeny ve světlém
  `:root` i `[data-theme="dark"]`
- `frontend/src/components/dashboard/Feed.svelte`, `FeedFilter.svelte`,
  `FeedCard.svelte`, `FeedRowCompact.svelte`, `FeedReadySection.svelte`,
  `frontend/src/components/form/FormEditor.svelte`,
  `frontend/src/dev/pages/LogsPage.svelte`

## Rozhodnutí (potvrzené v #133)

- ✓ **D74** Značky bez textu (tečka, donut jistoty, barevný proužek karty,
  tečka chyby na tabu) mají vlastní token `--shpd-color-{tón}-mark`
  (`info`, `success`, `warning`, `danger`) s prahem 3 : 1 vůči pozadí.
  Světlý vzhled: `info` `#3b82f6`, `success` `#16a34a`, `warning`
  `#d06f06`, `danger` `#dc2626`; tmavý beze změny proti dnešku (`info` =
  dnešní primary, ostatní = `-text`). Úprava D69 po ručním ověření.

## 1. Tokeny

`variables.css`:

| token | světlý | tmavý |
|-------|--------|-------|
| `--shpd-color-info-mark` | `#3b82f6` | `var(--shpd-color-primary)` |
| `--shpd-color-success-mark` | `#16a34a` | `var(--shpd-color-success-text)` |
| `--shpd-color-warning-mark` | `#d06f06` | `var(--shpd-color-warning-text)` |
| `--shpd-color-danger-mark` | `#dc2626` | `var(--shpd-color-danger-text)` |

Kontrast světlých hodnot na `bg` / `bg-hover`: 3,68 / 3,45 · 3,30 / 3,10 ·
3,52 / 3,31 · 4,83 / 4,54. Komentář u bloku: značka bez textu, práh 3 : 1,
D74. Vývojářský dashboard (`dev/theme.css`) nic nepřepisuje — pokud ano
(`danger`), ověř, že `danger-mark` dává smysl, a zapiš.

## 2. Převod

Na `-mark` přejdou značky bez textu (výčet z G, ověř vlastním průchodem):

- `Feed` — tečky sekcí (`--newItems` z `primary` na `info-mark`,
  `--ready`, `--review`, `--attention`, `--failed`, `--alerts`;
  `--other` zůstává `text-secondary`);
- `FeedFilter` — tečka;
- `FeedCard` — donut jistoty (`donutColor`), proužek karty Připraveno
  (`.shpd-feed-card__bar` u `docState_done`, dnes plocha `success`);
- `FeedRowCompact` — donut (jen oblouk; ikona s významem a `cursor: help`
  zůstává `-text`);
- `FeedReadySection` — proužek (dnes plocha `success`);
- `FormEditor` — tečka chyby na tabu;
- `dev/pages/LogsPage` — tečky stavu běhu.

Text uvnitř donutu (procenta) zůstává `--shpd-color-text`.

## 3. Dokumentace

- `docs/design-system.md` §3 Stavové: třetí role **značka** (`-mark`,
  3 : 1, D74) vedle plochy a textu; tabulka hodnot; kdy kterou roli
  (značka = nenese text ani samostatný význam bez okolí; ikona s významem
  je text).
- Odchylky 1 a 8 v `tasks/design-system-tone-colors.md` neměň — jen v tomto
  tasku zapiš, co D74 vyřešilo.
- Checklist #133: nic nového k zaškrtnutí; výsledek se zapíše při ověření
  (chat).

## Commity

1. `fix(frontend): značky bez textu vlastním tónem (#133, D74)` — kroky 1–2
2. `docs: značky bez textu (#133, D74)` — krok 3 + `**Stav:**` tohoto
   tasku + `python3 scripts/tasks-index.py`

Může být jeden commit.

## Ověření

- `python3 scripts/check-css-tokens.py`, `check-css-units.py`,
  `cd frontend && npm run build`.
- Chromium (Playwright, zdroj dat `free`, světlý i tmavý vzhled):
  kontrast každé převedené značky vůči skutečnému pozadí (vykreslené
  barvy, pozadí složené přes předky) — vše ≥ 3 : 1; tabulka do Ověření.
  Tmavý vzhled: snímek dashboardu před a po pixelově shodný kromě proužku
  karty Připraveno (ten se zesvětlí).
- Snímky světlého vzhledu před a po: dashboard (tečky sekcí, karta
  s donutem, karta Připraveno), formulář s chybou na tabu.

## Hotovo když

- [x] Tokeny `-mark` ve světlém i tmavém vzhledu podle D74
- [x] Značky z kroku 2 na `-mark`, kontrast ≥ 3 : 1 změřený (výjimky
  v Odchylkách 1 a 3)
- [x] Tmavý vzhled beze změny (kromě proužku Připraveno)
- [x] Dokumentace, `**Stav:**` aktualizovaný, index tasků přegenerovaný

## Pasti

- `info-mark` v tmavém vzhledu odkazuje na `primary` — vlastní vzhled
  (§9) přebarvuje jen sidebar, primary ne; pokud ano, zapiš.
- Donut bere barvu přes `currentColor` (`style="color: …"`) — změna
  barvy textu prvku nesmí přebarvit procenta uvnitř (ty mají vlastní
  `fill`).

## Odchylky od zadání

1. **Modrá tečka Nové položky je v tmavém vzhledu pod prahem.** D74 tmavý
   vzhled nemění (`info-mark` = `primary` `#2d6890`), Ověření ale chce vše
   ≥ 3 : 1: na pozadí stránky (`bg-secondary`) má tečka 2,81 : 1, na `bg`
   by měla 2,49 a na `bg-hover` 2,11 (výpočet). Vzhled se nemění,
   rozhodnutí patří do #133.
2. **Varovná výplň průběhového pruhu dashboardu** (`.dev-progress__bar--warn`
   v `dev/dev.css`) je nad rámec výčtu: značka bez textu, která brala plochu
   `warning`. Nově `warning-mark` — 3,25 : 1 vůči dráze pruhu
   (`bg-secondary` dashboardu, výpočet; dashboard při ověření žádnou úlohu
   neměl).
3. **Tečka filtru na aktivním chipu** (`FeedFilter`): vůči modré ploše
   chipu 1,73 : 1 (světlý) a 2,17 : 1 (tmavý), vůči pozadí stránky, do
   kterého z rohu chipu přesahuje, 4,54 a 6,10. Hodnota `danger-mark` je
   v obou vzhledech shodná s dosavadní `danger-text`, takže beze změny
   proti dřívějšku; tón na ploše `primary` je stejný otevřený bod jako
   odznaky na aktivní položce horního menu.
4. **Vývojářský dashboard `-mark` nepřepisuje.** `danger` a `danger-text`
   tam jsou `#c81e1e`, `danger-mark` dědí `#dc2626`: tečka pozastavených
   logů je o odstín světlejší než červený text vedle (4,46 : 1 místo
   5,30 : 1). Zelená tečka běhu logů má na pozadí dashboardu 3,04 : 1 —
   těsně nad prahem.
5. **Vlastní vzhled** (§9) `primary` nepřebarvuje — `deriveSidebarTokens()`
   odvozuje jen tokeny sidebaru; past z tasku nenastává.
6. Komentáře u tónových tokenů ve `variables.css`, anti-pattern v §6,
   princip tmavého vzhledu v §9 `docs/design-system.md` a poznámka
   u `check-css-tokens.py` v `CLAUDE.md` posílaly tečku na `-text` —
   opraveno na `-mark`.

**Co D74 vyřešilo z `tasks/design-system-tone-colors.md`:** Odchylka 1 —
tečky a donut už neberou `-text`, ale vlastní roli; ve světlém vzhledu jsou
světlejší, v tmavém stejné. Odchylka 8 — proužek karty Připraveno
(`FeedCard`, `FeedReadySection`) má v tmavém vzhledu 6,56 : 1 vůči `bg`
místo 2,98 : 1. Ostatní body Odchylky 8 (sidebar, text na tónovaném
podkladu, tlačítko `danger` pod kurzorem) zůstávají.

## Ověření v Chromiu (2026-11-11)

Playwright, zdroj dat `zey0-g…` (třída `free`, demo sada IT servis),
1280 px, kontrast z vykreslených barev (`getComputedStyle`, pozadí složené
přes předky). Feed demo sady má jen sekce Ke kontrole a K vyřízení —
ostatní sekce, karta a pruh Připraveno a urgentní karta (tečka filtru)
jsou podvržené v odpovědi `/_ui/dashboard`. Světlý vzhled před → po, tmavý
po (značky se v něm kromě proužku nemění):

| značka | pozadí | světlý před → po | tmavý |
|--------|--------|------------------|-------|
| tečka sekce Nové položky (`info`) | pozadí stránky | 7,86 → 3,45 | **2,81** |
| tečka sekce Připraveno (`success`) | pozadí stránky | 4,71 → 3,10 | 7,41 |
| tečky sekcí Ke kontrole, K vyřízení, Upozornění (`warning`) | pozadí stránky | 4,72 → 3,31 | 7,86 |
| tečka sekce Nepodařilo se zpracovat (`danger`) | pozadí stránky | 4,54 → 4,54 | 6,10 |
| tečka filtru | chip / chip pod kurzorem / okolí | 4,83 / 4,54 / 4,54 beze změny | 5,41 / 4,58 / 6,10 |
| tečka filtru na aktivním chipu | plocha `primary` | **1,73** beze změny | **2,17** |
| donut karty Připraveno (`success`) | `bg` karty | 5,02 → 3,30 | 6,56 |
| donut karty Ke kontrole (`warning`) | `bg` karty | 5,02 → 3,52 | 6,96 |
| donut řádku v pruhu Připraveno | `bg` | 5,02 → 3,30 | 6,56 |
| proužek karty Připraveno | `bg` karty / pozadí stránky | 5,02 / 4,71 → 3,30 / 3,10 | 2,98 / 3,37 → 6,56 / 7,41 |
| proužek pruhu Připraveno | `bg` / pozadí stránky | 5,02 / 4,71 → 3,30 / 3,10 | 2,98 / 3,37 → 6,56 / 7,41 |
| tečka chyby na tabu formuláře | `bg` dialogu | 4,83 → 4,83 | 5,41 |

Karta ani řádek pod kurzorem pozadí nemění. Procenta v donutu zůstávají
`--shpd-color-text` (17,85 světlý, 12,18 tmavý), ikona varování v řádku
Připraveno `warning-text` (5,02 / 6,96).

Vývojářský dashboard (jen světlý): tečka běhu logů 4,63 → 3,04, tečka
pozastavených logů 5,30 → 4,46.

Snímky před / po (dashboard se skutečným i podvrženým feedem, rozbalený
pruh Připraveno, formulář osoby s chybou na tabu, logy dashboardu):

- **tmavý vzhled** — dashboard se skutečným feedem i formulář s chybou
  shodné až na 2–4 px vyhlazení písma v sidebaru, které se liší i mezi
  dvěma běhy téhož sestavení; s podvrženým feedem se liší jen proužek karty
  Připraveno a proužek pruhu Připraveno;
- **světlý vzhled** — liší se jen tečky sekcí, donuty a oba proužky;
  formulář s chybou je pixelově shodný;
- logy dashboardu — liší se tečka stavu (a štítek verze v pruhu, který se
  změnou nesouvisí).

## Ruční ověření

Potvrzeno na dev serveru 2026-11-11; seznam, podle kterého se ověřovalo:

- světlý vzhled, dashboard: tečky sekcí, donut jistoty na kartě a v řádku
  pruhu Připraveno, zelený proužek karty a pruhu Připraveno — světlejší
  než dřív, modrá tečka Nové položky ne těžší než ostatní;
- tmavý vzhled, dashboard: beze změny kromě světlejšího proužku Připraveno;
- formulář s chybou na tabu: tečka beze změny;
- vývojářský dashboard: tečka běhu logů (zelená těsně nad prahem) a varovná
  výplň průběhového pruhu v detailu úlohy.
