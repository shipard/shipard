# Design system — základy: jednotky, tokeny, lint CSS

**Stav:** naplánováno — #134, rozhodnutí D9–D12 potvrzená

## Kontext / Cíl

Část #134 zastřešující issue #133 (pravidla vzhledu a úklid CSS). Mezery
a zaoblení jsou dnes v px — tokeny `--shpd-space-*` a `--shpd-radius-*`
i deklarace psané natvrdo mimo tokeny (188 v aplikaci, 78 ve vývojářském
dashboardu). Pravidlo D1 z #133 říká: rem pro mezery,
písmo a layout, px jen pro okraje, stíny a stavový proužek.

**Cíl:** převést mezery, odsazení, zaoblení a velikosti písma na rem
a zavést kontrolu v pre-commitu, aby se px do těchto vlastností nevrátily.
**Převod je vizuálně neutrální** (D9): při výchozím písmu prohlížeče
(16 px) vypadá aplikace po změně stejně jako před ní. Vizuální změny
(základní text 16 px — D6, typografické role) přináší pilot #135.

**Mimo rozsah:**

- pevné šířky a výšky (`width`, `height`, `min-*`, `max-*`, 95 výskytů),
  layout tokeny (`--shpd-sidebar-width`, `--shpd-header-height`, šířky
  pásů shellu) a breakpointy `@media` — patří k responzivitě #138 (D11);
- polohování (`top`, `left`, `right`, `bottom`, `inset`), `letter-spacing`,
  `line-height`, `transform`;
- okraje, obrysy a stíny (`border`, `outline`, `box-shadow`) — px tu
  zůstávají podle D1;
- sjednocení velikostí písma, které neodpovídají žádnému tokenu
  (`0.8125rem`, `0.6875rem`, `0.7rem`, `1.1rem`, …) — rozhodne pilot #135
  spolu s typografickými rolemi;
- styly generované v JS a inline `style=""` s šířkou.

## Návaznost

- **#133** — rozhodnutí D1–D8 (D1 jednotky, D2 pravidla hlídá nástroj)
- **#134** — tato část
- **#135** pilot (typografické role, D6), **#138** responzivita
  (breakpointy, šířky, container queries)
- `tasks/dev-dashboard-look.md` — vzor kontroly CSS v pre-commitu
  (`scripts/check-css-tokens.py`, #129 D14)

## Před implementací přečti

- `docs/design-system.md` — celý (§6 best practices, §10 soubory)
- `frontend/src/styles/variables.css` (tokeny), `base.css`, `reset.css`
- `frontend/src/dev/theme.css` — přepisuje jen barevné tokeny, mezery
  a zaoblení ne (ověřeno)
- `scripts/check-css-tokens.py` a `.githooks/pre-commit` — vzor skriptu
  a místo, kam se nová kontrola zapojí
- komponenty s nejvíc výskyty: `components/exchange/DocumentExchangePreview.svelte`,
  `components/viewer/ViewerDetail.svelte`, `components/shells/wild/SectionRail.svelte`,
  `components/viewer/DocumentDetail.svelte`, `components/portal/PortalContent.svelte`,
  `dev/dev.css`

## 1. Tokeny ve `variables.css`

Hodnoty se převádějí 1 : 1 (px / 16), názvy tokenů se nemění:

| Token | Dnes | Nově |
|---|---|---|
| `--shpd-space-xs` | `4px` | `0.25rem` |
| `--shpd-space-sm` | `8px` | `0.5rem` |
| `--shpd-space-md` | `16px` | `1rem` |
| `--shpd-space-lg` | `24px` | `1.5rem` |
| `--shpd-space-xl` | `32px` | `2rem` |
| `--shpd-input-padding-y` | `4px` | `0.25rem` |
| `--shpd-form-section-gap` | `8px` | `0.5rem` |
| `--shpd-form-section-padding-y` | `8px` | `0.5rem` |
| `--shpd-radius-sm` | `4px` | `0.25rem` |
| `--shpd-radius-md` | `6px` | `0.375rem` |
| `--shpd-radius-lg` | `8px` | `0.5rem` |

Nový token **`--shpd-radius-full: 9999px`** pro tvar „pilulka“ — nahradí
`999px` (14×) a `9999px` (1×) v komponentách. Je to jediná px hodnota
zaoblení: znamená „maximum“, ne velikost, a v rem by nedávala smysl.
`border-radius: 50%` (kruh) zůstává.

Stíny (`--shpd-shadow-*`) zůstávají v px (D1).

## 2. Převod deklarací v komponentách

Rozsah: všechny `.svelte` a `.css` ve `frontend/src` **včetně
vývojářského dashboardu** (`frontend/src/dev/`, D12). Vlastnosti:
`padding*`, `margin*`, `gap`, `row-gap`, `column-gap`, `font-size`,
`border-radius*`.

Pravidla převodu, v tomto pořadí:

1. **Hodnota odpovídá tokenu → token.** Mezery 4 / 8 / 16 / 24 / 32 px →
   `--shpd-space-xs` … `-xl`; zaoblení 4 / 6 / 8 px → `--shpd-radius-sm`
   … `-lg`; `999px` / `9999px` → `--shpd-radius-full`; písmo `12px` →
   `--shpd-font-size-xs`, `14px` → `--shpd-font-size-sm`.
2. **Literál velikosti písma v rem, který odpovídá tokenu → token**
   (`0.75rem` → xs, `0.875rem` → sm, `1rem` → base, `1.125rem` → lg,
   `1.25rem` → xl). Ostatní rem literály písma zůstávají beze změny (pilot).
3. **Ostatní px → rem 1 : 1** (`2px` → `0.125rem`, `6px` → `0.375rem`,
   `10px` → `0.625rem`, `1px` → `0.0625rem`, záporné hodnoty stejně).
   Bez zaokrouhlování — dělení šestnácti dává vždy konečné číslo.
4. **Zkratky po složkách:** `padding: 2px 8px` →
   `padding: 0.125rem var(--shpd-space-sm)`; px uvnitř `calc()` taky.

Hodnoty se **nezaokrouhlují k tokenu** (např. `6px` na `--shpd-space-xs`),
i když by to bylo „čistší“ — změnilo by to vzhled (D9). Nové tokeny mezer
(`2xs` pro 2 px apod.) se nezavádějí; když se ukážou potřeba, přidají se
s pravidly detailu v pilotu.

Lokální custom properties s px, které se používají v uvedených
vlastnostech, převést taky (`--dev-top-height` je výška — zůstává).

## 3. Kontrola `scripts/check-css-units.py`

Nový skript vedle `check-css-tokens.py` (jedna odpovědnost na skript),
zapojený do `.githooks/pre-commit` za něj, se stejným tvarem hlášky.

- Prochází celý `frontend/src` (`.svelte`, `.css`; bez `node_modules`),
  ne jen staged soubory — stejně jako `check-css-tokens.py`.
- Ignoruje komentáře `/* … */`.
- Hlídá deklarace vlastností `padding*`, `margin*`, `gap`, `row-gap`,
  `column-gap`, `font-size`, `border-radius*` — v CSS, v bloku `<style>`
  i v atributu `style="…"`. Custom properties (`--x: 4px`) ne, ty jsou
  definice tokenů.
- Chyba = jakákoli nenulová hodnota v px (`0px` projde, ale převést na `0`).
- **Výjimka** jen značkou na stejném řádku s důvodem:
  `padding: 1px; /* px-ok: optické zarovnání ikony na základní čáru */`.
  Značka bez textu důvodu je chyba. Po převodu (krok 2) má být výjimek
  nula; každá výjimka se uvede v popisu commitu.
- Výstup: `soubor:řádek: deklarace` na stderr, exit 1; při úspěchu jeden
  řádek s počtem prošlých souborů (jako `check-css-tokens.py`).
- Docstring v češtině podle vzoru `check-css-tokens.py` (co hlídá, proč,
  jak výjimku zapsat, odkaz na #133 D1–D2).

## 4. Dokumentace

- `docs/design-system.md`: nová kapitola **Jednotky a mezery** (před §10
  Soubory, čísla dalších kapitol posunout): pravidlo D1 vč. výjimek,
  tabulka tokenů mezer a zaoblení, `--shpd-radius-full`, kdy em (prvek
  řízený okolním textem — ikona u textu, vnitřní odsazení štítku), proč
  se kořenová velikost písma nenastavuje (respektuje nastavení
  prohlížeče), značka `px-ok`, odkaz na skript. Do §6 Pravidla doplnit
  bod „Mezery, odsazení, zaoblení a písmo bez px“ s odkazem na kapitolu.
- `docs/design-system.md` §8 Modální dialogy a §7 Avatary: hodnoty
  zmíněné v px, které se v kroku 2 převedly (padding těla apod.),
  aktualizovat; rozměry avataru (32×32) jsou šířka — zůstávají.
- `CLAUDE.md` → Testování, krok 3: vedle `check-css-tokens.py` uvést
  `python3 scripts/check-css-units.py`.

## Commity

1. `refactor(frontend): tokeny mezer a zaoblení v rem, --shpd-radius-full (#134)`
   — krok 1
2. `refactor(frontend): mezery, odsazení, zaoblení a písmo aplikace bez px (#134)`
   — krok 2 mimo `src/dev/`
3. `refactor(dev): mezery, odsazení, zaoblení a písmo dashboardu bez px (#134)`
   — krok 2 v `src/dev/`
4. `feat(scripts): kontrola px v CSS v pre-commitu (#134)` — krok 3 +
   `CLAUDE.md`
5. `docs(design-system): kapitola Jednotky a mezery (#134)` — krok 4 +
   `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

Po každém commitu `cd frontend && npm run build`.

## Ověření

- `python3 scripts/check-css-units.py` → exit 0, nula výjimek (nebo každá
  zdůvodněná v commitu); `python3 scripts/check-css-tokens.py` → exit 0.
- `cd frontend && npm run build` a `npm test` projdou.
- Kontrola neutrality: `git diff` kroku 2 nemá změnu, která by nebyla
  přesný převod podle pravidel 1–4 (žádné změněné číslo kromě jednotky).
  Před a po převodu porovnat vybrané obrazovky v prohlížeči při 100 %
  a výchozím písmu: Dashboard (karty feedu), seznam a detail přijaté
  faktury, editační formulář faktury, review modal, Přehled firmy,
  Nastavení → Aplikace, Nastavení účtu, přihlašovací obrazovka,
  vývojářský dashboard — světlý i tmavý vzhled. Nic se nesmí posunout.
- Zkouška kontroly: dočasně přidat `padding: 3px;` do libovolné
  komponenty → skript selže s cestou a řádkem; se značkou `px-ok` bez
  důvodu taky selže; se značkou a důvodem projde. Zkušební změny vrátit.

## Hotovo když

- [ ] tokeny mezer a zaoblení v rem, `--shpd-radius-full` zaveden
- [ ] v `padding*`, `margin*`, `gap`, `font-size`, `border-radius*` nikde
      px (mimo zdůvodněné výjimky)
- [ ] `scripts/check-css-units.py` v pre-commitu
- [ ] aplikace i vývojářský dashboard vizuálně beze změny
- [ ] kapitola Jednotky a mezery v `docs/design-system.md`, `CLAUDE.md`
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **Neutralita je kontrakt.** Pokušení „rovnou to uhladit“ (6 px na
  8 px, 13 px písmo na 14 px) patří do pilotu #135. Tady by rozmazalo
  ověření, že převod nic nerozbil.
- **`999px` není mezera.** Pilulkové zaoblení převést na
  `--shpd-radius-full`, ne na `62.4375rem`.
- **Zkratky s více hodnotami** (`margin: 0 auto 4px`) převádět po
  složkách; `auto`, `0`, `%` a `var()` zůstávají.
- **`em` ne.** Převod je px → rem. Na em se mění jen tam, kde to
  výslovně říká pilot nebo #138.
- **Media queries nechat být** — `@media (max-width: 768px)` zůstává
  v px a ladí s `MOBILE_BREAKPOINT` v `stores/layout.svelte.js`;
  breakpointy řeší #138.
- **Kontrola jde celým stromem**, takže krok 4 (skript) commitovat až
  po kroku 2 — jinak pre-commit zastaví vlastní převod.
- **Vývojářský dashboard má vlastní `dev.css`** (43 výskytů) a vlastní
  vzhled (#129) — převod stejný, barvy ani písmo se nemění.

## Rozhodnutí k designu (potvrzená)

- ✓ **D9** Základy jsou vizuálně neutrální: převod px → rem 1 : 1, hodnoty
  shodné s tokenem → token, nic se nezaokrouhluje. Vizuální změny
  (16 px, typografické role, sjednocení meziměr) přináší pilot #135.
- ✓ **D10** Kontrola px hlídá `padding*`, `margin*`, `gap`, `font-size`,
  `border-radius*` v celém `frontend/src`; výjimka jen značkou
  `/* px-ok: důvod */` na řádku. Šířky, polohování, okraje a stíny
  nehlídá.
- ✓ **D11** Breakpointy, pevné šířky a layout tokeny se přesouvají z #134
  do #138 — patří k responzivitě a container queries, převod by tady
  měnil chování.
- ✓ **D12** Rozsah včetně vývojářského dashboardu (`frontend/src/dev/`) —
  jedno pravidlo pro celý frontend, kontrola bez výjimek po adresářích.

