# Design system — tónové barvy a hex v komponentách (D69–D72)

**Stav:** hotovo — 2026-11-11 (#133 Další nesoulady, D69, D70, D71, D72); tečky a donut na pozadí stránky berou `-text`, ne plochu (viz Odchylky); tečky odznaků na sidebaru a text na tónovaném podkladu zůstávají pod prahem (viz Odchylky); změřeno v Chromiu nad demo sadou IT servis v obou vzhledech, ruční ověření na dev serveru prošlo (viz Ruční ověření); tečky a donut dále upravilo D74 (`design-system-mark-colors.md`)

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

- [x] Tokeny podle D70 v obou vzhledech, nové `-text` tokeny
- [x] Text a ikony tónu na pozadí používají `-text`, plochy základní token
      s `text-on-fill`
- [x] Hex jen v `variables.css`, `dev/theme.css` a zdůvodněných výjimkách;
      kontrola v pre-commitu ho hlídá
- [x] Varování v reportu textem i ikonou (D72)
- [x] Měření kontrastu zapsané, vše nad prahem nebo v odchylkách
- [x] Dokumentace podle kroku 6, `**Stav:**` aktualizovaný, index tasků
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

## Odchylky od zadání

1. **Tečky a donut na pozadí stránky berou `-text`, ne plochu** (schváleno
   v konverzaci 2026-11-11). D69 řadí tečku k plochám, jenže tečka nenese
   text a její kontrast je vůči pozadí: plocha z D70 by v tmavém vzhledu
   dala na `bg` / `bg-hover` 2,98 / 2,52 (warning, success) a 3,10 / 2,62
   (danger). Týká se teček sekcí ve `Feed`, tečky ve `FeedFilter`, tečky
   chyby na tabu ve `FormEditor`, donutu jistoty (`FeedCard`,
   `FeedRowCompact`) a teček stavu v `LogsPage` dashboardu. V tmavém
   vzhledu se tím jejich barva nemění.
2. **`--shpd-color-alert-success-bar` odkazuje na `success-text`**, ne na
   plochu (zadání: „rámeček hlášky je plocha, zůstává“). S plochou by
   rámeček hlášky success v tmavém vzhledu ztmavl z `#22c55e` na `#15803d`
   (2,8 : 1 na pozadí hlášky); takhle se v tmavém vzhledu nemění.
3. **Počty:** zadání odhaduje kolem 50 převodů a 10 ploch; použití je 118 —
   93 na `-text` (včetně 9 teček z bodu 1 a rámečku hlášky z bodu 2),
   24 ploch, 1 sidebar.
4. **Chyba v sidebaru** (`.shpd-sidebar__status--error`): `danger-text` na
   výchozím sidebaru dá 2,64 : 1 (světlý vzhled, i vlastní vzhled s výchozí
   barvou), v tmavém 4,91 : 1. Podle zadání je text `--shpd-color-text-sidebar`
   (10,9 / 11,1 : 1) a tón nese ikona — nově i v rozbaleném sidebaru, kde
   dřív ikona nebyla. Ikona má ve světlém vzhledu 2,64 : 1 jako dosud.
5. **Hex ve vývojářském dashboardu** je nad rámec výčtu i v `PdfPane`
   (`#525659`) a `VideosPage` (`#000`) — obojí na tokeny `--dev-*`.
6. **Výjimky kontroly:** `ThemeField`, `DsThemeField`, `ThemeSwatches`
   a `ColorField` mají hex jen ve `<script>`, kam kontrola nesahá; výjimka
   se dnes uplatní jen u `SandboxedHtml` (v konstantě je všech pět, D71).
7. **`rgba()` s barvou, která má token** (neopraveno, mimo rozsah):
   fallback `rgba(34, 102, 238, …)` u `--shpd-color-primary-soft`
   v `RegistryImportWizard` (2×) a `BankAccountBridgeDialog`.
8. **Pod prahem zůstává** (vzhled se nemění, rozhodnutí patří do #133):
   - tečky odznaků sekcí na sidebaru (`NavTree`, `TopMenuBar`) — plocha
     na `#00345c` 2,54 (warning, dřív 4,01) a 2,64 (danger, beze změny),
     v tmavém vzhledu na `#2a2e38` 2,70 (dřív 6,32) a 2,81 (dřív 3,6);
     na aktivní položce horního menu (`primary`) danger 1,73 / 1,24.
     `-text` světlého vzhledu by na sidebaru dal totéž — chce to tón pro
     sidebar;
   - odznak `SectionRail` vůči sidebaru 2,54–2,81 (text v odznaku
     4,83 / 5,02 projde);
   - text na tónovaném podkladu 14 % ve světlém vzhledu: danger 3,88
     (termín po lhůtě ve `FeedCard`, naměřeno; odznak v Přehledu firmy
     stejná dvojice), warning 4,14 (výpočet); beze změny proti dřívějšku
     u danger;
   - světlý `danger-text` na `danger-soft` 4,41 (chyba portálu, výpočet —
     portál na demo sadě není);
   - tmavý danger na tónovaném podkladu nad `bg-hover` 4,30 (výpočet);
   - proužek karty Připraveno (`FeedCard`, `FeedReadySection`) je plocha:
     v tmavém vzhledu 2,98 : 1 vůči `bg` (výpočet);
   - tmavý vzhled, tlačítko `danger` pod kurzorem vůči pozadí dialogu
     2,31 (text na něm 6,47);
   - dashboard: `success-text` na jeho `bg-hover` (`#eef3f8`) 4,49
     (`.dev-lvl--ok` v řádku pod kurzorem), `warning-text` 4,50 (výpočet).

## Ověření v Chromiu (2026-11-11)

Playwright, zdroj dat `zey0-g…` (třída `free`, demo sada IT servis),
1280 px, kontrast z vykreslených barev (`getComputedStyle`, pozadí složené
přes předky). Zprávy reportu, odznaky sekcí a chyba navigace podvržené
v odpovědi API. Světlý / tmavý vzhled:

| místo | dvojice | světlý | tmavý |
|-------|---------|--------|-------|
| tlačítko `danger` — text na ploše | `text-on-fill` / `danger` | 4,83 | 4,83 |
| tlačítko `danger` pod kurzorem | `text-on-fill` / `danger-hover` | 6,47 | 6,47 |
| chyba pod polem, hvězdička povinného pole | `danger-text` / pozadí sekce | 4,54 | 6,10 |
| rámeček pole s chybou | `danger-text` / pozadí sekce | 4,54 | 5,41 |
| tab formuláře s chybou + tečka | `danger-text` / `bg` | 4,83 | 5,41 |
| štítek `--success` v seznamu (banka) | `success-text` / `bg` | 5,02 | 6,56 |
| totéž, řádek pod kurzorem | `success-text` / `bg-hover` | 4,71 | 5,55 |
| report — řádek s chybou, text a ikona | `danger-text` / `bg` | 4,83 | 5,41 |
| report — mezisoučet s chybou | `danger-text` / `bg-hover` | 4,54 | 4,58 |
| report — řádek s varováním, text a ikona | `warning-text` / `bg` | 5,02 | 6,96 |
| report — mezisoučet s varováním | `warning-text` / `bg-hover` | 4,72 | 5,89 |
| report — zprávy pod čarou (chyba / varování) | `-text` / pozadí stránky | 4,54 / 4,72 | 6,10 / 7,86 |
| relace v Účtu — text na `success-soft` | `success-text` / `success-soft` | 4,79 | 6,76 |
| relace v Účtu — rámeček štítku | `success-text` / `bg` | 4,79 | 6,56 |
| feed — tečka sekce (warning) | `warning-text` / pozadí stránky | 4,72 | 7,86 |
| feed — varování na kartě, donut | `warning-text` / `bg` | 5,02 | 6,96 |
| feed — termín po lhůtě (tónovaný podklad) | `danger-text` / 14 % | **3,88** | 4,97 |
| sidebar — ikona chyby navigace | `danger-text` / `bg-sidebar` | **2,64** | 4,91 |
| `NavTree`, `TopMenuBar` — tečka warning | `warning` / `bg-sidebar` | **2,54** | **2,70** |
| `NavTree`, `TopMenuBar` — tečka danger | `danger` / `bg-sidebar` | **2,64** | **2,81** |
| `SectionRail` — text v odznaku (danger / warning) | `text-on-fill` / plocha | 4,83 / 5,02 | 4,83 / 5,02 |

Vývojářský dashboard (jen světlý): text chyby v logu 5,74, stav „ok“ ve
Videích 5,02 (v řádku pod kurzorem **4,49**), tečka běhu logů 4,63,
rámeček zapnutého štítku úrovně 3,97 / 4,51.

Co demo sada nevykreslila a platí pro to výpočet z hodnot tokenů (stejné
dvojice jako výše): štítky `--danger` / `--warning` v seznamu a v sub-tabulce
formuláře, tlačítko `success` (bílý text 5,02, hover 7,13), odznaky
Přehledu firmy, chyba portálu, štítek průvodce Spisovny, `FeedFilter`
s tečkou.

Snímky před / po (1280 px, oba vzhledy; dashboard, Přehled firmy, seznam,
formulář s chybou, report s podvrženými zprávami, přihlášení, vývojářský
dashboard): beze změny rozvržení. Liší se jen tónové barvy — ve světlém
vzhledu tmavší zelená a oranžová, v tmavém světlejší červený text
a tmavší plochy tlačítek; v reportu varování nově barví text řádku.
Přihlášení, Přehled firmy a seznam faktur jsou pixelově shodné. Dialog
mazání ze sub-tabulky se skriptem otevřít nepodařilo — tlačítko `danger`
je změřené ve formuláři.

Kontrola hex: podvržené případy (komentář s `#133`, selektor `#app`,
`url(#…)`, `href="#top"`, hex ve `<script>`) nález nedávají; hex v hodnotě
deklarace, ve fallbacku `var()`, v atributu `style` a direktivě `style:`
ano. Nad stromem před úpravami hlásí 36 výskytů (+ 1 ve výjimce), nad
současným žádný.

## Ruční ověření (2026-11-11)

Firefox, dev zdroj `lh6x`, světlý i tmavý vzhled — v pořádku: chyby ve
formuláři (text, hvězdička, rámeček, tečka na tabu), tlačítka Smazat
a Ukončit platnost i pod kurzorem, dialog potvrzení se Smazat (řádek
sub-tabulky), report s varováním (Kontrola evidence × deník — text řádku
i zprávy oranžově, D72), termín po lhůtě na kartě dashboardu, vývojářský
dashboard beze změny.

Nálezy:

- **tečky a donut na dashboardu** ve světlém vzhledu příliš tmavé
  (`-text`) → D74, task `design-system-mark-colors.md`;
- **zelené štítky v seznamu** (banka „zaúčtováno“ apod.) ve světlém vzhledu
  příliš tmavé — otevřeno v #133 (text musí mít 4,5 : 1, `success-text`
  má na řádku pod kurzorem 4,71, prostor pro zesvětlení není).

Bez dat na `lh6x`: faktury po splatnosti (červený text v seznamu).
