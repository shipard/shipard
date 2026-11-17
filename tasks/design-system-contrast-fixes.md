# Design system — opravy z ověření kontrastu (D96, D97)

**Stav:** naplánováno — #153, D96, D97; drobnost v klasickém rozložení

## Kontext / Cíl

Ruční ověření `tasks/design-system-contrast.md` (D88–D95) prošlo, se
třemi připomínkami ke vzhledu:

- bílý obrys teček odznaků (D88, `0,125rem`) je tlustý — kolem malé
  tečky je víc bílé než barvy;
- v klasickém rozložení (horní menu) je tečka odznaku těsně za názvem
  sekce;
- šedá pilulka klidné lhůty na kartě feedu (`Badge` `neutral`) splývá
  s kartou v obou vzhledech.

**Cíl:** tenčí obrys, mezera v horním menu, klidná lhůta bez pilulky.

**Mimo rozsah:** štítek Koš, řádek pod kurzorem ve světlém vzhledu a další
nálezy z ověření — seznam „Na později“ v #153.

## Návaznost

- **#153** — D88, D90, D96, D97 (komentář s ručním ověřením)
- `tasks/design-system-contrast.md` — kroky 3 a 4, Odchylky 3 a 4

## Před implementací přečti

- `docs/design-system.md` §3 Stavové (bod Na pozadí sidebaru), §5 Badge
  systém
- `frontend/src/components/chrome/NavTree.svelte`,
  `shells/classic/TopMenuBar.svelte`, `shells/wild/SectionRail.svelte`,
  `dashboard/FeedFilter.svelte`, `dashboard/FeedCard.svelte`

## Rozhodnutí (potvrzená v #153)

- ✓ **D96** Obrys značky z D88 má šířku `1px` místo `0,125rem`. Barva
  (`text-on-fill`) i kontrast tečky vůči obrysu beze změny.
- ✓ **D97** Lhůta na kartě feedu v klidu (`dueState` `later` nebo jiný než
  `overdue` / `soon`) je šedý text bez pilulky; `Badge` `danger` jen po
  termínu nebo do 3 dnů.

## 1. Obrys teček (D96)

`box-shadow: 0 0 0 1px var(--shpd-color-text-on-fill)` ve čtyřech místech
z D88: `NavTree` (`.shpd-navtree__badge-dot`), `TopMenuBar`
(`.shpd-topmenu__badge-dot`), `SectionRail` (`.shpd-rail__badge`),
`FeedFilter` (`.shpd-feed-filter__dot`). Stín v px `check-css-units.py`
povoluje; pokud ne, značka `/* px-ok: obrys značky D96 */`.

Rezervy proti ořezu z D88 (kontejner chipů feedu `0.25rem`, rail) nech —
s tenčím obrysem nevadí; jen ověř snímkem, že tečka chipu zůstává celá.

## 2. Mezera v horním menu

`TopMenuBar`: odznak (`.shpd-topmenu__badge`) za názvem sekce i za ikonou
Domů dostane `margin-left: var(--shpd-space-xs)` — s mezerou tlačítka
(`gap` xs) celkem jako `--shpd-space-sm`. Mezera mezi ikonou a názvem
sekce se nemění.

## 3. Klidná lhůta bez pilulky (D97)

`FeedCard.svelte`: lhůta `overdue` / `soon` = `Badge` `danger` jako dnes;
jinak prostý text v roli meta (`--shpd-font-size-meta`) barvou
`--shpd-color-text-secondary` (na kartě 5,68 / 5,34 : 1 světlý, 5,88 /
4,97 tmavý — `bg` / `bg-hover`). `data-testid="card-due"` má prvek s
textem lhůty v obou větvích. Komentář u lhůty doplň o D97.

## 4. Dokumentace

`docs/design-system.md`:

- §3 bod Na pozadí sidebaru — šířka obrysu `1px` (D96, nahrazuje
  `0,125rem` z D88);
- §5 Badge systém — klidný stav nevyčnívá: neutrální informace (lhůta
  v klidu) je text, ne pilulka (D97).

## Commity

1. `fix(frontend): tenčí obrys teček, mezera odznaku v horním menu, klidná lhůta bez pilulky (#153, D96, D97)` — kroky 1–3
2. `docs: obrys značky a klidná lhůta (#153, D96, D97)` — krok 4 + `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

Může být jeden commit.

## Ověření

- `python3 scripts/check-contrast.py`, `check-css-tokens.py`,
  `check-css-units.py`; `cd frontend && npm run build`, `npm test`.
- Snímky před a po (Chromium, zdroj dat `free` s demo sadou IT servis,
  světlý i tmavý vzhled, podvržené odznaky sekcí a karty s lhůtou jako
  v `tasks/design-system-contrast.md`): sidebar s odznaky, horní menu
  (klasické rozložení) s odznakem u aktivní i neaktivní sekce a u Domů,
  rail, chip feedu s tečkou (aktivní i neaktivní), karta K vyřízení
  s lhůtou `later` a `overdue`. Jinde beze změny.
- Kontrast textu klidné lhůty na kartě i na kartě pod kurzorem ≥ 4,5 : 1.

## Hotovo když

- [ ] Obrys `1px` ve čtyřech místech (D96)
- [ ] Mezera mezi názvem sekce / ikonou Domů a odznakem v horním menu
- [ ] Klidná lhůta jako šedý text, `Badge` `danger` jen `overdue` / `soon`
  (D97); `data-testid` zachovaný
- [ ] Dokumentace, `**Stav:**`, index tasků

## Pasti

- `card-due` může hledat e2e test (`tests/e2e/`) nebo scénář videa
  (`demo/scenarios/`) — po změně musí najít text lhůty v obou větvích.

## Odchylky od zadání

(vyplní implementace)

## Ověření

(vyplní implementace)
