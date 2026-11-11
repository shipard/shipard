# Design system — tónové barvy a hex v komponentách (D69–D72)

**Stav:** naplánováno — 2026-11-11 (#133 Další nesoulady, D69, D70, D71, D72)

## Kontext / Cíl

Bod checklistu #133 „Hex barvy v komponentách“. Dvě věci, které spolu
souvisí:

1. **Tónové barvy mají dvě role, ale jednu hodnotu.** `--shpd-color-danger`,
   `-warning` a `-success` jsou zároveň plochou s bílým textem (tlačítka
   Smazat / Použít, odznaky) a barvou textu a ikon na pozadí stránky (chyby
   u polí, štítky v seznamech, řádky reportu). Jedna hodnota obojí nesplní
   (kontrast z tasku reportů a z výpočtu 2026-11-11):

   | tón | vzhled | text na `bg` / `bg-hover` | bílý text na ploše |
   |-----|--------|---------------------------|--------------------|
   | danger `#dc2626` | světlý | 4,83 / 4,54 | 4,83 |
   | danger `#ef4444` | tmavý | 3,97 / 3,36 | 3,76 |
   | warning `#d97706` | světlý | 3,19 / 2,99 | 3,19 |
   | warning `#f59e0b` | tmavý | 6,96 / 5,89 | 2,15 |
   | success `#16a34a` | světlý | 3,30 / 3,10 | 3,30 |
   | success `#22c55e` | tmavý | 6,56 / 5,55 | 2,28 |

2. **Hex v komponentách.** Pravidlo 1 v `docs/design-system.md` §6 nic
   nevynucuje. V aplikaci 31 výskytů ve 20 souborech (bílý text na ploše
   12×, fallback ve `var()` 10×, data vlastního vzhledu, `SandboxedHtml`),
   ve vývojářském dashboardu `dev/dev.css`.

**Cíl:**

1. Tón = dva tokeny, plocha a text, s kontrastem podle D69 (D70 hodnoty).
2. Hex jen v souborech s definicemi tokenů, kontrola v pre-commitu (D71).
3. Varování v reportu zase i textem (D72).

**Mimo rozsah:**

- tokeny hlášek `--shpd-color-alert-*`, stavů dokladů
  `--shpd-color-state-*` a badge — mají vlastní páry pozadí / text;
- primary, accent a sidebar (vlastní vzhled, §9);
- `rgb()` / `rgba()` / `color-mix()` literály — jen hex (D71); pokud
  narazíš na `rgba()` s barvou, která má token, zapiš do odchylek, neopravuj;
- přepracování vzhledu komponent — mění se jen barvy podle tokenů.

## Návaznost

- **#133** — D12 (jedno pravidlo pro celý frontend), D59
  (`--shpd-color-text-on-fill`), D61 (tón chyby u prvku barvou textu), D64,
  D69–D72; checklist „Další nesoulady“ (bod Hex barvy)
- `tasks/design-system-report-table.md` — Odchylky (měření kontrastu,
  varování jen ikonou)
- `tasks/design-system-small-fixes.md` — kontrola nedefinovaných tokenů,
  D59

## Před implementací přečti

- `docs/design-system.md` §3 Stavové (sémantické), §6 Pravidla
  a Anti-patterny, §8 Hlášky a pruhy (chyba u prvku, D61, D64), §9 Vzhledy
- `frontend/src/styles/variables.css` — světlý `:root` a `[data-theme="dark"]`
- `frontend/src/dev/theme.css` (přepisuje `danger` pro dashboard)
- `scripts/check-css-tokens.py`
- `frontend/src/components/reports/ReportView.svelte` (řádek a zprávy se
  zprávou, D64)

## Rozhodnutí (potvrzená v #133)

- ✓ **D69** Tónové barvy (`danger`, `warning`, `success`) mají dva tokeny:
  `--shpd-color-{tón}` = plocha (tlačítko, odznak, tečka; bílý text na ní
  `text-on-fill` ≥ 4,5 : 1 v obou vzhledech) a `--shpd-color-{tón}-text` =
  text a ikona na pozadí stránky (≥ 4,5 : 1 na `bg` i `bg-hover` v obou
  vzhledech).
- ✓ **D70** Hodnoty: plocha `danger` `#dc2626`, `warning` `#b45309`,
  `success` `#15803d` v obou vzhledech (hover o stupeň tmavší: `#b91c1c`,
  `#92400e`, `#166534`); text světlý `#dc2626` / `#b45309` / `#15803d`,
  tmavý `#f87171` / `#f59e0b` / `#22c55e`. Tokeny `alert-*` a stavů
  dokladů beze změny.
- ✓ **D71** Hex jen v souborech s definicemi tokenů
  (`styles/variables.css`, `dev/theme.css`); bílý text na ploše
  `text-on-fill` (D59), fallback ve `var()` pryč, ostatní na tokeny —
  i ve vývojářském dashboardu (D12, tokeny v `dev/theme.css`). Výjimky
  s důvodem: data vlastního vzhledu (`ThemeField`, `DsThemeField`,
  `ThemeSwatches`, `ColorField`) a `SandboxedHtml` (bílý papír cizího
  e-mailu). Hlídá `check-css-tokens.py` v pre-commitu.
- ✓ **D72** Varování v reportu nese tón i textem (`warning-text`) jako
  chyba — odchylka od D64 kvůli kontrastu s D70 odpadá.

## 1. Tokeny (D69, D70)

`variables.css`, světlý `:root` i `[data-theme="dark"]`:

| token | světlý | tmavý |
|-------|--------|-------|
| `--shpd-color-danger` | `#dc2626` | `#dc2626` |
| `--shpd-color-danger-hover` | `#b91c1c` | `#b91c1c` |
| `--shpd-color-warning` | `#b45309` | `#b45309` |
| `--shpd-color-warning-hover` | `#92400e` | `#92400e` |
| `--shpd-color-success` | `#15803d` | `#15803d` |
| `--shpd-color-success-hover` | `#166534` | `#166534` |
| `--shpd-color-danger-text` (nový) | `#dc2626` | `#f87171` |
| `--shpd-color-warning-text` (nový) | `#b45309` | `#f59e0b` |
| `--shpd-color-success-text` (nový) | `#15803d` | `#22c55e` |

`-soft` tokeny beze změny. Komentář u bloku: plocha vs text, odkaz D69.

`dev/theme.css` přepisuje `danger` na `#c81e1e` — doplň `danger-text`
stejnou hodnotou (dashboard je jen světlý, 5,6 : 1 na bílé); `warning`
a `success` dědí hodnoty aplikace.

Tokeny, které dnes na tónové barvy odkazují (`--shpd-color-alert-success-bar`
→ `var(--shpd-color-success)`, `--shpd-color-error-ring` apod.), projdi:
rámeček hlášky je plocha (zůstává), prstenec chyby `rgb(220 38 38 / .15)`
je beze změny.

## 2. Převod použití (D69)

Každé `var(--shpd-color-{danger,warning,success}[-hover])` v komponentách
zařaď:

- **text, ikona, rámeček prvku na pozadí stránky / karty / modalu**
  (`color`, `border-color`, `fill` / `stroke` ikony, `border-bottom-color`)
  → `--shpd-color-{tón}-text`. Rámeček ve stavu chyby (input) je hranice
  prvku: 3 : 1 splní text token s rezervou.
- **plocha** (`background`, tečka, odznak, tlačítko, proužek karty)
  → beze změny `--shpd-color-{tón}`; text na ní `--shpd-color-text-on-fill`.
- **tónovaný podklad** (`color-mix(… var(--shpd-color-danger) 14% …)`,
  `-soft`) → beze změny; text na něm `-text` — změř (krok 6).
- **text na pozadí sidebaru** (`Sidebar` `.shpd-sidebar__status--error`)
  ani jedna role nepokrývá: změř `-text` na `bg-sidebar` (světlý vzhled
  `#00345c`, tmavý `#2a2e38`, i vlastní vzhled s výchozí barvou). Pod
  4,5 : 1 → text `--shpd-color-text-sidebar` a tón jen ikonou (vzor D64
  před D72); zapiš do odchylek s čísly.

Odhad: kolem 50 míst `color: var(--shpd-color-…)` přejde na `-text`,
zhruba 10 ploch zůstane.

## 3. Hex v komponentách (D71)

Aplikace (výčet 2026-11-11, ověř vlastním průchodem):

- **bílý text na ploše → `--shpd-color-text-on-fill`:** `Button` (3×),
  `LoginScreen`, `SetPasswordScreen`, `OpAuthScreen`, `StatusScreen`,
  `ToolCallChip`, `FeedFilter`, `MailUploadModal`, `PortalContent`,
  `SectionRail`;
- **fallback ve `var()` → smazat** (token je definovaný, kontrola
  nedefinovaných tokenů to hlídá): `CommandPalette`, `NavTree` (2×),
  `Sidebar`, `RegistryImportWizard` (2×), `TopMenuBar` (2×),
  `SectionRail` (2×); `RegistryImportWizard` má navíc fallback
  `rgba(…)` u `warning-soft` — taky pryč;
- **výjimky (beze změny, důvod v kontrole):** `ThemeField`,
  `DsThemeField`, `ThemeSwatches`, `ColorField` — výchozí a náhledové
  barvy vlastního vzhledu jsou data; `SandboxedHtml` — dokument cizího
  e-mailu v iframe má bílý papír i v tmavém vzhledu.

Vývojářský dashboard (D12): hex v `dev/dev.css` (bílý text na štítcích
a v pruhu, černé pozadí, blok výstupu konzole se svými barvami) → tokeny
definované v `dev/theme.css` (`--shpd-*` přepsáním, nové jako
`--dev-…` — kontrola tokenů prefix `--dev-` zná). Bílý text na ploše
i tady `text-on-fill`.

## 4. Report (D72)

`ReportView.svelte`: řádek s varováním (`.shpd-report__tr--warning`) má
text buněk `--shpd-color-warning-text` jako chyba `--shpd-color-danger-text`;
zpráva pod čarou `--warning` taky textem. Ikony `-text`. Komentáře v souboru
(dnes „varování nese tón jen ikonou… nedá textu 4,5 : 1“) podle nového
stavu.

## 5. Kontrola (D71)

`scripts/check-css-tokens.py` — nová kontrola hex barev:

- hledá hex barvu (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`) v CSS:
  bloky `<style>` v `.svelte`, soubory `.css`, atribut `style="…"`
  a direktiva `style:` — **mimo komentáře** (`/* */`, `<!-- -->`, `//`);
  komentáře obsahují odkazy na issues (`#133`), které jsou formálně
  platný hex;
- povolené soubory s definicemi: `styles/variables.css`, `dev/theme.css`;
- výjimky po souborech (konstanta s důvodem u každého): `ThemeField`,
  `DsThemeField`, `ThemeSwatches`, `ColorField`, `SandboxedHtml`;
- hláška: soubor, řádek, hodnota + rada („bílý text na ploše →
  `--shpd-color-text-on-fill`, jinak token z `variables.css`; fallback ve
  `var()` nepiš“).

Hex v JS datech mimo CSS (např. výchozí barvy v `<script>`) kontrola
nehlídá — zapiš to do komentáře skriptu jako vědomou mez.

## 6. Dokumentace

- `docs/design-system.md` §3 Stavové: tabulka s plochou, hover, `-text`
  a `-soft`, kdy který (D69, D70) s kontrastem; §6 Pravidla — pravidlo 1
  (hex) s výjimkami a kontrolou (D71), Anti-patterny (tón jako barva textu
  → `-text`); §8 Hlášky a pruhy — chyba u prvku `-text` tokeny, report
  D72 (věty o „varování jen ikonou“ pryč).
- `CLAUDE.md` — sekvence po tasku zmiňuje, co hlídá `check-css-tokens.py`;
  doplnit hex (D71).
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `feat(frontend): tónové barvy jako plocha a text (#133, D69, D70)` —
   kroky 1–2
2. `refactor(frontend): bez hex barev v komponentách (#133, D71)` — krok 3
3. `refactor(frontend): varování v reportu i textem (#133, D72)` — krok 4
4. `chore: kontrola hex barev v CSS (#133, D71)` — krok 5
5. `docs: tónové barvy a hex (#133, D69–D72)` — krok 6 + `**Stav:**`
   tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `python3 scripts/check-css-tokens.py`, `check-css-units.py`,
  `cd frontend && npm run build`, `npm test`.
- **Měření kontrastu** v Chromiu (Playwright, zdroj dat `free`, světlý
  i tmavý vzhled) z vykreslených barev, výsledky jako tabulka do sekce
  Zjištění:
  - tlačítka `danger` a `success` (text na ploše, i hover);
  - chyba pod polem formuláře, štítky `--danger` / `--warning` /
    `--success` v seznamu vieweru a v sub-tabulce formuláře;
  - řádek reportu s chybou a s varováním (podvržená odpověď API jako
    v tasku reportů) na `bg` i na mezisoučtu (`bg-hover`), zprávy pod
    čarou;
  - text na `-soft` podkladech (relace v Účtu, chyba portálu, štítek
    průvodce Spisovny — kde jde vyvolat);
  - odznaky v `NavTree`, `TopMenuBar`, `SectionRail`, `FeedFilter`;
  - stav chyby v sidebaru (krok 2).
  Vše textové ≥ 4,5 : 1, ikony, tečky a rámečky ≥ 3 : 1; co neprojde,
  do odchylek.
- Snímky před a po (1280 px, oba vzhledy): dashboard, seznam vieweru se
  štítky, formulář s chybou, dialog se Smazat, report s varováním,
  přihlášení; vývojářský dashboard (jen světlý) — beze změny vzhledu
  kromě tónových barev.
- `grep` v komponentách: žádné `color: var(--shpd-color-(danger|warning|success))`
  bez `-text` (výjimky zdůvodnit v odchylkách).

## Hotovo když

- [ ] Tokeny podle D70 v obou vzhledech, nové `-text` tokeny
- [ ] Text a ikony tónu na pozadí používají `-text`, plochy základní token
      s `text-on-fill`
- [ ] Hex jen v `variables.css`, `dev/theme.css` a zdůvodněných výjimkách;
      kontrola v pre-commitu ho hlídá
- [ ] Varování v reportu textem i ikonou (D72)
- [ ] Měření kontrastu zapsané, vše nad prahem nebo v odchylkách
- [ ] Dokumentace podle kroku 6, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- Tmavý vzhled sdílí plochy se světlým (D70), ale ne texty — přidej
  `-text` do obou bloků, jinak tmavý vzhled zdědí světlý odstín
  (`#dc2626` na tmavém pozadí 3,4 : 1).
- `[data-theme="dark"]` a vlastní vzhled (§9) přepisují tokeny — ověř, že
  vlastní vzhled tónové barvy nepřepisuje (jen sidebar); pokud ano, zapiš.
- Vývojářský dashboard nikdy nenastavuje `data-theme`; jeho `danger` je
  z `dev/theme.css` — bez `danger-text` tam by vzal hodnotu aplikace.
- Kontrola hex: `#133` v komentáři je formálně hex — komentáře vynechat
  dřív, než se hledá; `#fff` uvnitř URL nebo selektoru `#id` v CSS
  (`#app`) není barva — hledej jen v hodnotách deklarací.
- Kontrola tokenů jde celým stromem — commit 4 až po commitech 2 a 3.
