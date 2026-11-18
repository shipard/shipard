# Design system — vložené dokumenty a klik mimo dočasnou vrstvu (D124)

**Stav:** naplánováno — #160, D124

## Kontext / Cíl

Ruční ověření `tasks/design-system-escape-layers.md` (D121–D123): v náhledu
dokladu na telefonu v tabu **PDF** klepnutí mimo otevřenou bublinu (kebab ⋮)
bublinu nezavře, v tabu Náhled ano. Klepnutí do vloženého dokumentu
(`<iframe>`) zůstane uvnitř něj a nedojde k posluchači kliku mimo na
`document` (`ui/Popover.svelte` `handleDocClick`, `ThemePanel`, `UserMenu`,
nabídky polí).

Totéž platí i na desktopu: panel rozhodnutí otevřený vedle PDF v náhledu
dokladu, bublina nad textem e-mailu (`ui/SandboxedHtml.svelte`), náhled
tisku (`viewer/PrintPreviewDialog.svelte`), velké náhledy příloh
(`viewer/AttachmentGrid.svelte`, `mode="full"`), PDF v náhledu dokladu
(`exchange/PdfViewerPanel.svelte`).

**Cíl:** klepnutí do vloženého dokumentu při otevřené dočasné vrstvě ji
zavře stejně jako klepnutí kamkoli jinam; bez otevřené dočasné vrstvy se
vložené dokumenty chovají jako dnes.

**Mimo rozsah:**

- vykreslení PDF na telefonu — #161;
- postranní panely a modal (nejsou dočasné vrstvy, D122) — klepnutí do
  dokumentu je nezavírá ani dnes;
- Esc s fokusem uvnitř `<iframe>` (prohlížeč klávesu do aplikace nepustí —
  `tasks/design-system-escape-layers.md` → Odchylky).

## Návaznost

- **#160** — D121–D124 (komentář s ručním ověřením a D124)
- `tasks/design-system-escape-layers.md` — zásobník `utils/layerStack.js`,
  druhy vrstev
- **#161** — PDF na telefonu

## Před implementací přečti

- `frontend/src/utils/layerStack.js` a jeho test
- `docs/design-system.md` §8 Zavírání vrstev (D121–D123)
- komponenty s `openLayer()` (grep `--include=*.svelte` v `components/`)
  a komponenty s `<iframe>` z Kontextu

## Rozhodnutí (potvrzené v #160)

- ✓ **D124** (doplňuje D122) Dokud je otevřená dočasná vrstva (bublina,
  nabídka pole, menu, panel vzhledu, paleta), vložené dokumenty (PDF, text
  e-mailu, náhled tisku, náhledy příloh) klepnutí nepřijímají — klepnutí
  na ně zavře vrstvu stejně jako jinde; další klepnutí do dokumentu projde
  normálně. Jedno pravidlo nad zásobníkem vrstev, ne v každé komponentě.

## 1. Dočasná vrstva v zásobníku

`utils/layerStack.js`:

- dočasnost je vlastnost vrstvy, ne jejího druhu — `panel` je dnes panel
  vzhledu (dočasný, klik mimo zavírá) i drawer detailu a boční chat
  (nedočasné, D122). Např. volba `transient: true` v `openLayer()`
  (nebo jiný tvar, jen ať je dočasnost výslovná u každé vrstvy);
- dočasné: `Popover` (tedy i `KebabMenu`, panel rozhodnutí, výběr období),
  nabídky `LookupInput` a `MultiselectInput`, `UserMenu`, `ThemePanel`,
  `CommandPalette`; nedočasné: `Modal`, `ViewerDetailDrawer`,
  `ChatPanel`, drawer sidebaru (`SidebarShell` — zavírá ho klepnutí na
  překryv, ne pravidlo D124);
- dokud je v zásobníku aspoň jedna dočasná vrstva, zásobník to vystaví
  jedním příznakem na kořeni dokumentu (např. atribut na `<html>`);
  zásobník ho nastaví a uklidí sám (i při odebrání vrstvy ze středu);
- test v `tests/Unit/` (příznak při otevření, po zavření poslední dočasné
  vrstvy pryč, nedočasná vrstva ho nenastaví).

## 2. Pravidlo pro vložené dokumenty

Jedno globální pravidlo (`styles/base.css`): s příznakem z kroku 1
`iframe { pointer-events: none; }`. Klepnutí tak dopadne na prvek pod
dokumentem, posluchač kliku mimo ho uvidí a vrstvu zavře.

- Výjimka: `<iframe>` **uvnitř** dočasné vrstvy (dnes žádný — ověř grepem)
  klepnutí přijímat musí; když se najde, pravidlo ho vynechá.
- Ověř, že klepnutí na plochu pod `<iframe>` nespustí jinou akci (obal
  dokumentu s vlastním `onclick`) — pokud ano, zapiš do Odchylek a oprav.
- Posouvání PDF kolečkem / prstem při otevřené vrstvě: s
  `pointer-events: none` se posouvá rodič místo dokumentu — přijatelné
  (vrstva se prvním klepnutím zavře); zapiš, jak se to chová.

## 3. Dokumentace

- `docs/design-system.md` §8 Zavírání vrstev: dočasné a nedočasné vrstvy
  výslovně (seznam), vložené dokumenty při otevřené dočasné vrstvě (D124).
- `docs/frontend.md` — zásobník vrstev (volba dočasnosti, příznak).

## Commity

1. `fix(frontend): klepnutí do vloženého dokumentu zavře dočasnou vrstvu (#160, D124)` — kroky 1 a 2 s testem
2. `docs: dočasné vrstvy a vložené dokumenty (#160, D124)` — krok 3 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `npm test`, `npm run build`, `check-css-tokens.py`, `check-css-units.py`.
- Chromium (Playwright), demo sada IT servis:
  - telefon 390 px: náhled dokladu, tab PDF, otevřený kebab ⋮ → klepnutí
    do plochy PDF zavře kebab; další klepnutí kebab neotevře ani nic
    jiného nespustí;
  - desktop 1280 px: náhled dokladu, otevřený panel rozhodnutí → klik do
    PDF vlevo panel zavře, náhled zůstane;
  - Došlá pošta: text e-mailu (vložený dokument) a otevřené uživatelské
    menu nebo paleta → klik do textu menu zavře;
  - náhled tisku s otevřeným výběrem jazyka (je-li bublina) → klik do PDF
    ji zavře;
  - bez otevřené dočasné vrstvy: PDF i text e-mailu reagují na klik,
    výběr textu a posouvání jako dřív;
  - otevřený drawer detailu nebo boční chat (nedočasné) → příznak není
    nastavený, vložené dokumenty klik přijímají.

## Hotovo když

- klepnutí do vloženého dokumentu zavře otevřenou dočasnou vrstvu ve všech
  scénářích ověření, na telefonu i desktopu;
- bez dočasné vrstvy jsou vložené dokumenty beze změny;
- dočasnost je výslovná u každé vrstvy v zásobníku a pokrytá testem;
- `docs/design-system.md` a `docs/frontend.md` odpovídají implementaci.

## Odchylky

_(doplní implementace)_
