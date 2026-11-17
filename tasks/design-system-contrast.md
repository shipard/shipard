# Design system — kontrast tónů mimo pozadí stránky (D88–D95)

**Stav:** naplánováno — #153, D88–D95; zelené štítky a stav „V pořádku“ v seznamech jsou druhý task

## Kontext / Cíl

Tónové tokeny D69–D74 jsou laděné na pozadí stránky (`bg`, `bg-hover`).
Jinde kontrast nestačí: na pozadí sidebaru, na tónovaném podkladu, na
vybraném řádku seznamu a v tmavém vzhledu u `primary` jako barvy textu.
Výpočet z tokenů `variables.css` (obou vzhledů) potvrdil čísla z #153
a přidal dva nálezy:

- `primary` jako text a ikona má v tmavém vzhledu 2,1–2,8 : 1 na `bg`,
  `bg-hover`, `bg-secondary` i `primary-soft` — odkazy (`base.css`), Badge
  a SpanBadge `primary`, „aktuální volba“ v rozhodovacích panelech náhledu
  importu, avatar řádku a další (27 komponent);
- vybraný řádek v tmavém vzhledu pod kurzorem: `text-secondary` 3,81,
  `danger-text` 3,51, `success-text` 4,26.

Šedý panel (`bg-secondary`) je v pořádku — `-text`, `-mark` i
`text-secondary` projdou v obou vzhledech kromě `info-mark` v tmavém (D93).

**Cíl:** všechna místa z #153 splní prahy (text 4,5 : 1, značka a ikona
3 : 1) v obou vzhledech, nebo mají zdůvodněnou výjimku v
`docs/design-system.md`; hlídané dvojice tokenů počítá skript v pre-commitu.

**Mimo rozsah:**

- zelené štítky v seznamech a stav „V pořádku“ v seznamech (druhý task
  #153 po auditu);
- vývojářský dashboard (`dev/theme.css`, jen světlý vzhled) — skript ho
  nepočítá;
- `-soft` tokeny a jejich použití bez tónového textu.

## Návaznost

- **#153** — D88–D95 (komentáře s rozhodnutími a čísly)
- **#133** — D69, D70, D72, D74 (role tónu: plocha, `-text`, `-mark`)
- `tasks/design-system-tone-colors.md` (Odchylky 8),
  `tasks/design-system-mark-colors.md` (Odchylky 1 a 3)

## Před implementací přečti

- `docs/design-system.md` §2 Primary, §3 Stavové, §4 Při výběru řádku,
  §5 Badge systém, §6 Anti-patterny, §9 Designové principy dark módu
- `frontend/src/styles/variables.css` — `:root` i `[data-theme="dark"]`
- `scripts/check-css-tokens.py` (struktura kontrol, vzor výjimek)
- `.githooks/pre-commit`

## Rozhodnutí (potvrzená v #153)

- ✓ **D88** Značka bez textu (D74) na ploše, která není pozadí stránky —
  sidebar, aktivní položka navigace, aktivní chip — dostane obrys
  `0,125rem` v barvě `--shpd-color-text-on-fill`. Tečka má vůči obrysu
  4,8 (danger) a 5,0 (warning) : 1 v obou vzhledech i ve vlastním vzhledu
  s libovolnou barvou sidebaru.
- ✓ **D89** Ikona chyby na pozadí sidebaru má barvu textu sidebaru;
  význam nese tvar ikony a text.
- ✓ **D90** Text na tónovaném podkladu jen přes sdílený `Badge` (dvojice
  tokenů 5,7–9,9 : 1 v obou vzhledech). Tónový `color-mix` pod textem
  v komponentách zaniká.
- ✓ **D91** Na vybraném řádku seznamu mají tónové a šedé texty barvu
  běžného textu. Stavový proužek zůstává.
- ✓ **D92** Token `--shpd-color-primary-text` pro `primary` jako text
  a ikonu: světlý = `primary`, tmavý `#7fb2dc`. Plocha `primary` beze
  změny.
- ✓ **D93** `--shpd-color-info-mark` v tmavém vzhledu `#3b82f6` (jako ve
  světlém) místo odkazu na `primary`.
- ✓ **D94** Zdůvodněná výjimka: okraj tlačítka `danger` pod kurzorem
  v tmavém vzhledu má vůči dialogu 2,31 : 1; tlačítko identifikuje text
  (6,47 : 1).
- ✓ **D95** `--shpd-color-border-focus` odkazuje na `--shpd-color-primary-text`
  (tmavý vzhled `#7fb2dc`, vůči `bg` 6,6 : 1 místo 2,49). Obrysy fokusu
  s `primary` napřímo přejdou na `border-focus`, rámeček cíle přetažení
  souboru na `primary-text`.

## 1. `primary-text`, `info-mark` a obrys fokusu (D92, D93, D95)

`variables.css`:

| token | světlý | tmavý |
|-------|--------|-------|
| `--shpd-color-primary-text` | `var(--shpd-color-primary)` | `#7fb2dc` |
| `--shpd-color-info-mark` | `#3b82f6` (beze změny) | `#3b82f6` |

Kontrast `#7fb2dc` v tmavém vzhledu: `bg` 6,63, `bg-hover` 5,61,
`bg-secondary` 7,48, `primary-soft` 6,07. `info-mark` `#3b82f6` v tmavém:
`bg` 4,07, `bg-hover` 3,44, `bg-secondary` 4,59. Komentáře u bloků tokenů
doplň (D92 role text, D93 tmavý vzhled už neodkazuje na `primary`).

Převod: každé `color: var(--shpd-color-primary)` ve `frontend/src`
(komponenty i `styles/`) přejde na `--shpd-color-primary-text` — text,
ikona i odkaz. Výchozí výčet (38 míst, ověř vlastním grepem):
`styles/base.css` (`a`), `ui/Badge` (`--primary`), `viewer/SpanBadge`
(`--primary`), `viewer/ViewerRow` (avatar, span `--primary`),
`viewer/ViewerGrid`, `form/FormSubTable`, `form/FormEditor`,
`form/AttachmentPanel`, `viewer/DetailAttachments`, `viewer/AttachmentGrid`,
`viewer/DocumentDetail`, `viewer/Viewer`, `viewer/ViewerDetail`,
`browser/TableBrowser`, `exchange/PdfViewerPanel`,
`exchange/DocumentExchangePreviewModal`, rozhodovací panely
`exchange/ResolveDecisionPanel`, `VatCodeDecisionPanel`,
`ReverseChargeDecisionPanel` („aktuální volba“), `dashboard/AiSummaryCard`,
`Feed`, `FeedCard`, `FeedReadySection`, `settings/ContentTagsSettings`,
`BankAccountBridgeDialog`, `reports/ReportView`, `content/ContentBlock`.

Plochy (`background`, `border-color` tlačítek a aktivní položky) zůstávají
na `--shpd-color-primary`.

**Hover odkazu:** `color: var(--shpd-color-primary-hover)` jako barva
textu (`base.css` `a:hover`, `ReportView`, `ContentBlock`) má v tmavém
vzhledu kolem 3 : 1. Nahraď ho: hover zůstává `primary-text` a přidá
`text-decoration: underline`. `primary-hover` zůstane jen pro plochy.

**Obrys fokusu (D95):** `--shpd-color-border-focus: var(--shpd-color-primary-text)`
(dnes `primary`; 21 použití tokenu se srovná samo). Obrysy, které berou
`primary` napřímo:

- `outline` u `:focus-visible` — `ui/SegmentedControl`, `ui/OptionRow`,
  `exchange/DocumentExchangePreview` (2×) → `--shpd-color-border-focus`;
- přerušovaný rámeček cíle přetažení souboru — `form/AttachmentPanel`,
  `dashboard/Dashboard` → `--shpd-color-primary-text` (podklad
  `color-mix(primary …)` zůstává).

Ověř grepem, že žádný jiný `outline` / `box-shadow` fokusu `primary`
napřímo nebere.

## 2. Měřicí skript (D88–D95)

`scripts/check-contrast.py` — počítá kontrast hlídaných dvojic tokenů
z `frontend/src/styles/variables.css` ve světlém (`:root`) i tmavém
(`:root` + `[data-theme="dark"]`) vzhledu. Řeší odkazy `var(--x)`
(i řetězené) a hex; `rgb(… / alfa)` složí nad pozadím dvojice. Vzorec
relativní luminance a kontrastu podle WCAG 2.x. Výstup: nalezené dvojice
pod prahem (token, vzhled, poměr, práh); exit 1 při nálezu. `--list`
vypíše všechny dvojice s poměrem (podklad pro dokumentaci).

Hlídané dvojice (každá v obou vzhledech):

| popředí | pozadí | práh | proč |
|---|---|---|---|
| `{danger,warning,success}-text` | `bg`, `bg-hover`, `bg-secondary` | 4,5 | D70 |
| `{info,danger,warning,success}-mark` | `bg`, `bg-hover`, `bg-secondary` | 3 | D74, D93 |
| `text-on-fill` | `{danger,warning,success,primary}` a `-hover` | 4,5 | D69 |
| `{danger,warning}` (plocha tečky) | `text-on-fill` (obrys) | 3 | D88 |
| `primary-text` | `bg`, `bg-hover`, `bg-secondary`, `primary-soft` | 4,5 | D92 |
| `border-focus` | `bg`, `bg-hover`, `bg-secondary` | 3 | D95 |
| `text`, `text-secondary` | `bg`, `bg-hover`, `bg-secondary` | 4,5 | |
| `text` | `bg-selected`, `bg-selected-hover` | 4,5 | D91 |
| `text-sidebar` | `bg-sidebar` | 4,5 | D89 |
| `state-{concept,edit,confirmed,archive,trash,cancelled,done,error}-text` | `state-…-bg` téhož stavu | 4,5 | D90 (Badge) |
| `primary-text` | `primary-soft` | 4,5 | D90, D92 (Badge `primary`) |
| `alert-{info,warning,error,success}-text` | `alert-…-bg` | 4,5 | Notice |

Dvojice, které token nemá v daném vzhledu (např. stav bez `-bg`), skript
vynechá a uvede ve `--list`. Výjimky jsou pojmenovaná konstanta s důvodem
a odkazem na rozhodnutí — zatím jen D94 (`danger-hover` vůči `bg`
v tmavém vzhledu; do tabulky dvojic ji proto nepřidávej, uveď ji jako
zdokumentovanou výjimku ve výpisu `--list`).

Pre-commit (`.githooks/pre-commit`): blok po `check-css-units.py` se
zprávou ve stejném stylu (co se stalo, jak to spustit ručně). Skript jde
celým souborem tokenů, je rychlý — pouštět vždy.

Krok 3 v `CLAUDE.md` → Testování doplň o `python3 scripts/check-contrast.py`
(jedna věta: kontrast hlídaných dvojic tokenů v obou vzhledech, #153).

## 3. Značky s obrysem a ikona chyby v sidebaru (D88, D89)

Obrys: `box-shadow: 0 0 0 0.125rem var(--shpd-color-text-on-fill)`.

- `chrome/NavTree.svelte` — `.shpd-navtree__badge-dot`;
- `shells/classic/TopMenuBar.svelte` — `.shpd-topmenu__badge-dot`;
- `shells/wild/SectionRail.svelte` — `.shpd-rail__badge` (pilulka s počtem);
- `dashboard/FeedFilter.svelte` — `.shpd-feed-filter__dot` (na aktivním
  i neaktivním chipu stejně — na světlém chipu obrys splyne, tečka má
  vůči bílé 4,8 : 1).

Barva teček zůstává (plocha `warning` / `danger`, u chipu `danger-mark`).

D89: `layout/Sidebar.svelte` — `.shpd-sidebar__status-icon` z
`danger-text` na `--shpd-color-text-sidebar`; komentář u pravidla přepiš
(tón nenese ani ikona, D89).

## 4. Text na tónovaném podkladu přes `Badge` (D90)

Tři místa na `ui/Badge.svelte`:

| místo | dnes | `Badge` |
|---|---|---|
| `dashboard/FeedCard.svelte` — lhůta (`.shpd-feed-card__due`) | `overdue` / `soon` danger na `color-mix(danger 14 %)`, jinak `text-secondary` na `color-mix(text-secondary 14 %)` | `danger` / `neutral`; `data-testid="card-due"` zachovat (`Badge` předává `...rest`) |
| `overview/CompanyOverview.svelte` — `.shpd-overview__badge--warning` / `--danger` (4 výskyty) | tón na `color-mix(… 14 %)` | `warning` / `danger` |
| `portal/PortalContent.svelte` — `.portal__failed` | `danger-text` na `danger-soft` | `danger` |

Vlastní třídy štítků a jejich CSS zaniknou; rozmístění (odstup nad lhůtou
na kartě apod.) řeší obal nebo `class` na `Badge`. Vzhled se změní
záměrně: podklad sytější (dvojice `state-…`), tvar a písmo podle `Badge`.

Kontrola v `scripts/check-css-tokens.py` — čtvrtá kontrola: `color-mix(…)`
s tónovým tokenem (`--shpd-color-(danger|warning|success|info)…`) v CSS
komponent je nález (D90; text na tónu jen přes `Badge`). Výjimky
pojmenovanou konstantou s důvodem, pokud najdeš oprávněnou (tónovaná
plocha bez textu); `primary` s nízkým podílem jako podklad drag-and-drop
(`AttachmentPanel`, `Dashboard`, `MailUploadModal`) kontrola nehlídá.
Docstring skriptu a zprávu v pre-commitu doplň.

## 5. Vybraný řádek (D91)

Na vybraném řádku (`--selected`, i pod kurzorem) mají barvu
`--shpd-color-text` všechny texty, které jinak berou tón nebo šedou:
`-text` tóny, `primary-text`, `text-secondary`, `state-error-text`.

- `viewer/ViewerRow.svelte` — spany `--success` / `--warning` / `--danger`
  / `--primary`, sekundární texty řádku (číslo, meta, datum…);
- `viewer/ViewerGrid.svelte` — `.shpd-grid__tr--selected` totéž (spany,
  `text-secondary` buňky, chyba `state-error-text`);
- `chat/ConversationList.svelte` — vybraná konverzace (`text-secondary`).

Nemění se: stavový proužek, `SpanBadge` (pilulka s vlastním podkladem),
ikony řádku s vlastní plochou. Jedno pravidlo per komponenta (selektor
vybraného řádku přepíše barvu potomků), ne podmínky v markupu.

## 6. Dokumentace

`docs/design-system.md`:

- §2 Primary — řádek `--shpd-color-primary-text` (D92); u `primary`
  „odkazy, ikony stavu“ přesunout k `primary-text`.
- §3 Stavové — tabulka značek: `info` tmavý `#3b82f6` (D93), čísla
  doplnit, věta „Modrá tečka v tmavém vzhledu zůstává pod prahem“ pryč;
  bod **Soft / tónovaný podklad** přepsat (D90: text na tónu jen `Badge`,
  `-soft` je pozadí bez tónového textu); bod **Na pozadí sidebaru** přepsat
  (D88 obrys značky, D89 ikona v barvě sidebaru — tón na sidebaru nese jen
  značka s obrysem); nový bod **Výjimky** s D94.
- §4 Při výběru řádku — D91.
- §5 Badge systém — D90: Badge je jediný způsob tónového textu na podkladu.
- §6 Anti-patterny — `color: var(--shpd-color-primary)` pro text (D92);
  tónový `color-mix` pod textem (D90).
- §6 nebo §10 Kontrola v pre-commitu — `check-contrast.py` a co hlídá.
- §9 Designové principy dark módu — `primary` jako plocha vs. text.
- §3 Borders & focus — `border-focus` = `primary-text` (D95), obrys
  fokusu vždy přes token, ne `primary` napřímo.

Hodnoty kontrastu do dokumentace ber z `check-contrast.py --list`.

## Commity

1. `feat(frontend): token primary-text, info-mark a obrys fokusu v tmavém vzhledu (#153, D92, D93, D95)` — krok 1
2. `feat(scripts): kontrola kontrastu tokenů v pre-commitu (#153, D88–D95)` — krok 2
3. `fix(frontend): značky s obrysem mimo pozadí stránky, ikona chyby v barvě sidebaru (#153, D88, D89)` — krok 3
4. `refactor(frontend): text na tónovaném podkladu přes Badge (#153, D90)` — krok 4 vč. kontroly v `check-css-tokens.py`
5. `fix(frontend): vybraný řádek bez tónových a šedých textů (#153, D91)` — krok 5
6. `docs: kontrast mimo pozadí stránky (#153, D88–D95)` — krok 6 + `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `python3 scripts/check-contrast.py` (exit 0), `--list` do sekce Ověření
  tohoto tasku; `check-css-tokens.py`, `check-css-units.py`;
  `cd frontend && npm run build`, `npm test`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis, světlý
  i tmavý vzhled): kontrast **vykreslených** barev (pozadí složené přes
  předky) u každého převedeného místa — tečky v sidebaru (NavTree, horní
  menu včetně aktivní položky, rail), tečka chipu feedu, ikona chyby
  sidebaru (vyvolat chybu načtení navigace), lhůta karty feedu, odznaky
  Přehledu firmy, vybraný řádek seznamu (Bankovní výpisy, Faktury) i pod
  kurzorem, odkaz v textu, obrys fokusu (Tab na poli a na segmentovém
  přepínači), „aktuální volba“ v náhledu importu (doklad
  s rozhodovacím panelem), modrá tečka Nové položky. Tabulka do Ověření.
- Snímky před a po (oba vzhledy): dashboard, Přehled firmy, seznam s
  vybraným řádkem, sidebar s odznaky. Mimo převedená místa beze změny.
- Ruční ověření (člověk) — postup dá chat po implementaci.

## Hotovo když

- [ ] `primary-text` a `info-mark` podle D92, D93; žádné
  `color: var(--shpd-color-primary)` ve `frontend/src`
- [ ] Obrys fokusu a rámeček přetažení podle D95
- [ ] `check-contrast.py` v pre-commitu, exit 0, výjimka D94 vyjmenovaná
- [ ] Značky s obrysem (D88), ikona chyby v barvě sidebaru (D89)
- [ ] Tři štítky na `Badge`, kontrola tónového `color-mix` v
  `check-css-tokens.py` (D90)
- [ ] Vybraný řádek podle D91 ve `ViewerRow`, `ViewerGrid`,
  `ConversationList`
- [ ] Kontrast vykreslených barev změřený v obou vzhledech (Ověření)
- [ ] Dokumentace, `CLAUDE.md` krok 3, `**Stav:**`, index tasků

## Pasti

- `box-shadow` obrysu ořízne rodič s `overflow: hidden` (sbalený sidebar,
  rail s odznakem v rohu `top: 0`) — zkontroluj na snímku, případně
  odsazení odznaku.
- `primary-text` ve světlém vzhledu odkazuje na `primary` — vlastní vzhled
  `primary` nepřebarvuje (`deriveSidebarTokens()`), pokud ano, zapiš.
- D91: `SpanBadge` uvnitř vybraného řádku má vlastní podklad — selektor
  nesmí přebarvit jeho text.
- Selektory převáděných štítků (`feed-card__due`, `overview__badge`,
  `portal__failed`, `navtree__badge`, `feed-filter__dot`) mohou používat
  e2e testy (`tests/e2e/`) nebo scénáře videí (`demo/scenarios/`) — grep
  a případně přepnout na `data-testid`.
- `check-contrast.py`: tmavý vzhled = `:root` přepsaný blokem
  `[data-theme="dark"]` (tokeny, které tmavý blok nemá, dědí světlé).
  `var()` vyhodnoť až v cílovém vzhledu (`info-mark` dnes odkazuje
  na `primary` jen v tmavém).

## Odchylky od zadání

(vyplní implementace)

## Ověření

(vyplní implementace)
