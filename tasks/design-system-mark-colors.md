# Design system — barvy značek bez textu (D74)

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady, D74)

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

- [ ] Tokeny `-mark` ve světlém i tmavém vzhledu podle D74
- [ ] Značky z kroku 2 na `-mark`, kontrast ≥ 3 : 1 změřený
- [ ] Tmavý vzhled beze změny (kromě proužku Připraveno)
- [ ] Dokumentace, `**Stav:**` aktualizovaný, index tasků přegenerovaný

## Pasti

- `info-mark` v tmavém vzhledu odkazuje na `primary` — vlastní vzhled
  (§9) přebarvuje jen sidebar, primary ne; pokud ano, zapiš.
- Donut bere barvu přes `currentColor` (`style="color: …"`) — změna
  barvy textu prvku nesmí přebarvit procenta uvnitř (ty mají vlastní
  `fill`).
