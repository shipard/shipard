# Design system — kontrast tónů mimo pozadí stránky (D88–D95)

**Stav:** hotovo — 2026-11-17, #153 (D88–D95), 6 commitů; výjimka štítku Koš potřebuje číslo rozhodnutí v #153, odchylky níže; zelené štítky a stav „V pořádku“ v seznamech jsou druhý task

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

- [x] `primary-text` a `info-mark` podle D92, D93; žádné
  `color: var(--shpd-color-primary)` ve `frontend/src`
- [x] Obrys fokusu a rámeček přetažení podle D95
- [x] `check-contrast.py` v pre-commitu, exit 0, výjimka D94 vyjmenovaná
- [x] Značky s obrysem (D88), ikona chyby v barvě sidebaru (D89)
- [x] Tři štítky na `Badge`, kontrola tónového `color-mix` v
  `check-css-tokens.py` (D90)
- [x] Vybraný řádek podle D91 ve `ViewerRow`, `ViewerGrid`,
  `ConversationList`
- [x] Kontrast vykreslených barev změřený v obou vzhledech (Ověření)
- [x] Dokumentace, `CLAUDE.md` krok 3, `**Stav:**`, index tasků

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

1. **Štítek stavu Koš je výjimka** (rozhodnuto při implementaci, číslo
   rozhodnutí v #153 doplní chat). Tabulka dvojic hlídá
   `state-trash-text` na `state-trash-bg`; má 3,90 (světlý) a 2,81 (tmavý)
   : 1 a skript by skončil exit 1. Barvy se nemění — smazaný záznam je
   záměrně tlumený a přeškrtnutý; výjimka je v konstantě `EXCEPTIONS`
   vedle D94 a v `docs/design-system.md` §3.
2. **`color: var(--shpd-color-primary)` bylo 53, ne 38.** 38 míst
   v aplikaci sedí s výčtem; dalších 12 je ve vývojářském dashboardu
   (`dev/dev.css`, `VersionMenu`, `DevKebabMenu`, `LogsPage`,
   `LogException`) — převedena také, aby platilo „žádné ve
   `frontend/src`“; dashboard je jen světlý a `primary-text` tam ukazuje
   na jeho `primary`, vzhled se nemění. Tři místa měla zápis s fallbackem
   `var(--shpd-color-primary, var(--shpd-color-text))`, který grep minul:
   odkaz v Přehledu firmy (v tmavém 2,49 : 1 — našlo ho až měření
   v prohlížeči), `LookupInput` („+ Vytvořit nový“), `MultiselectInput`
   (zatržítko). Hover `dev-btn-outline` v dashboardu (`primary-hover`
   jako barva textu) zůstal — jen světlý vzhled.
3. **Tečka chipu feedu bere plochu `danger`, ne `danger-mark`.** Zadání
   barvu nechávalo a počítalo s 4,8 : 1 vůči bílé; `danger-mark` je ale
   v tmavém vzhledu `#f87171` a vůči bílému obrysu má 2,77. Plocha drží
   4,83 v obou vzhledech jako ostatní tečky s obrysem.
4. **Posuvný kontejner chipů tečku ořezával** (`overflow-x: auto` ořezává
   i svisle; tečka přesahuje roh chipu o 2 px — ořez byl vidět už před
   změnou). Kontejner dostal rezervu `0.25rem` nahoře a vpravo, záporný
   horní okraj drží rozvržení — snímek dashboardu před / po se liší jen
   v tečce. Rail obrys neořezává (snímek; rezerva `0.125rem` nad první
   položkou stačí přesně), ve sbaleném sidebaru se odznaky sekcí
   nekreslí.
5. **`Badge` neumí ikonu ani `class`.** Štítek „Selhalo“ v portálu má
   ikonu varování **před** štítkem v `danger-text` na pozadí karty
   (rozhodnuto při implementaci); `class` na `Badge` by přes `...rest`
   přepsala jeho vlastní třídy, rozmístění proto drží obal
   (`.shpd-feed-card__due`, `.portal__failed`). Portál na dev stroji není
   (zdroj dat bez `hosting.core`) — převod ověřen jen sestavením.
6. **Řádky Přehledu firmy s odznakem jsou o ~4 px vyšší** — `Badge` má
   vlastní odsazení a výšku řádku; lhůta karty feedu je naopak menší
   (role meta, normální tloušťka místo 14 px tučně). Záměrná změna vzhledu
   podle zadání, uvedeno kvůli snímkům.
7. **Vybraný řádek — nad rámec výčtu D91:**
   - tlumený span (`--muted`, průhlednost 0,6) měl na vybraném řádku
     2,0–2,6 : 1; na vybraném řádku má průhlednost 0,8 (5,7–7,9 : 1);
   - **archivní a smazaný řádek pravidlo nepotřebují** (rozhodnutí
     „zahrnout“ je bez práce): `color` z globálních `docState_archive` /
     `docState_trash` přebíjí už dnes vlastní barva řádku (scoped pravidlo
     `ViewerRow` má vyšší specifičnost, buňky `ViewerGrid` barvu nedědí) —
     text archivního i smazaného řádku má v seznamech barvu běžného
     textu, zůstává proužek a přeškrtnutí. Ověřeno měřením s podvrženou
     třídou. Token `state-archive-row` se tak v seznamech neprojevuje —
     nález pro #153, vzhled se nemění;
   - ikona řádku a ikony akcí konverzace zůstávají `text-secondary`
     (4,3 / 3,6 světlý, 4,6 / 3,8 tmavý — práh ikony 3 : 1);
   - oddělovač „·“ mezi spany (`__sep`, barva okraje) je dekorace a do
     měření nevstupuje.
8. **Kontrola zbytečné výjimky.** `check-contrast.py` hlásí jako nález
   i výjimku, kterou dvojice už nepotřebuje (práh splňuje) — zadání to
   nežádalo; drží seznam výjimek aktuální.
9. **Nálezy mimo rozsah** (nic se neměnilo, pro druhý task #153):
   - Badge a SpanBadge `accent` — `accent-hover` na `accent-soft` má ve
     světlém vzhledu 3,92 : 1 (tmavý 6,62); tabulka dvojic ho nehlídá;
   - dva vlastní štítky s tónovým textem na `-soft` mimo `Badge`:
     průvodce importem ze Spisovny (`warning-text` na `warning-soft`,
     4,84 / 7,11) a „aktuální relace“ v Zabezpečení (`success-text` na
     `success-soft`, 4,79 / 6,76) — prahy drží, D90 by je ale chtělo
     přes `Badge`; `danger-soft` po převodu portálu nikdo nepoužívá;
   - text smazaného řádku mimo seznamy (`docState_trash`, barva
     `state-trash-bar`) má ve světlém vzhledu 2,56 : 1 na `bg`;
   - rámeček výběru v barvě `primary` (zatržítko `MultiselectInput`,
     `Checkbox`, vybraný `OptionRow` / segment) má v tmavém vzhledu vůči
     `bg` 2,49 : 1 — zadání plochy a rámečky výběru nechává na `primary`.
10. **Vlastní vzhled** `primary` nepřebarvuje (`deriveSidebarTokens()`
    odvozuje jen tokeny sidebaru) — past ze zadání nenastává.
11. **`CodeSteps` testovacího kola** (vrstva kódu `test-round`) nový skript
    nepouští — zadání to nežádá; pouští ho pre-commit.

## Ověření

Kontroly (2026-11-17): `python3 scripts/check-contrast.py` exit 0,
`check-css-tokens.py` (čtvrtá kontrola na stavu před převodem najde přesně
tři místa `FeedCard` a `CompanyOverview`, po převodu nic),
`check-css-units.py`, `npm run build`, `npm test` (303 testů).

### `check-contrast.py --list`

```
popředí               pozadí              práh  světlý   tmavý  proč
danger-text           bg                   4,5    4,83    5,41  D70 tón jako text
danger-text           bg-hover             4,5    4,54    4,58  D70 tón jako text
danger-text           bg-secondary         4,5    4,54    6,10  D70 tón jako text
warning-text          bg                   4,5    5,02    6,96  D70 tón jako text
warning-text          bg-hover             4,5    4,72    5,89  D70 tón jako text
warning-text          bg-secondary         4,5    4,72    7,86  D70 tón jako text
success-text          bg                   4,5    5,02    6,56  D70 tón jako text
success-text          bg-hover             4,5    4,71    5,55  D70 tón jako text
success-text          bg-secondary         4,5    4,71    7,41  D70 tón jako text
info-mark             bg                   3,0    3,68    4,07  D74, D93 značka
info-mark             bg-hover             3,0    3,45    3,44  D74, D93 značka
info-mark             bg-secondary         3,0    3,45    4,59  D74, D93 značka
danger-mark           bg                   3,0    4,83    5,41  D74, D93 značka
danger-mark           bg-hover             3,0    4,54    4,58  D74, D93 značka
danger-mark           bg-secondary         3,0    4,54    6,10  D74, D93 značka
warning-mark          bg                   3,0    3,52    6,96  D74, D93 značka
warning-mark          bg-hover             3,0    3,31    5,89  D74, D93 značka
warning-mark          bg-secondary         3,0    3,31    7,86  D74, D93 značka
success-mark          bg                   3,0    3,30    6,56  D74, D93 značka
success-mark          bg-hover             3,0    3,10    5,55  D74, D93 značka
success-mark          bg-secondary         3,0    3,10    7,41  D74, D93 značka
text-on-fill          danger               4,5    4,83    4,83  D69 text na ploše
text-on-fill          danger-hover         4,5    6,47    6,47  D69 text na ploše
text-on-fill          warning              4,5    5,02    5,02  D69 text na ploše
text-on-fill          warning-hover        4,5    7,09    7,09  D69 text na ploše
text-on-fill          success              4,5    5,02    5,02  D69 text na ploše
text-on-fill          success-hover        4,5    7,13    7,13  D69 text na ploše
text-on-fill          primary              4,5    8,37    6,01  D69 text na ploše
text-on-fill          primary-hover        4,5   11,04    4,67  D69 text na ploše
danger                text-on-fill         3,0    4,83    4,83  D88 značka s obrysem
warning               text-on-fill         3,0    5,02    5,02  D88 značka s obrysem
primary-text          bg                   4,5    8,37    6,63  D92 primary jako text
primary-text          bg-hover             4,5    7,86    5,61  D92 primary jako text
primary-text          bg-secondary         4,5    7,86    7,48  D92 primary jako text
primary-text          primary-soft         4,5    7,14    6,07  D90, D92 Badge primary
border-focus          bg                   3,0    8,37    6,63  D95 obrys fokusu
border-focus          bg-hover             3,0    7,86    5,61  D95 obrys fokusu
border-focus          bg-secondary         3,0    7,86    7,48  D95 obrys fokusu
text                  bg                   4,5   17,85   12,18  text
text                  bg-hover             4,5   16,77   10,31  text
text                  bg-secondary         4,5   16,77   13,75  text
text-secondary        bg                   4,5    5,68    5,88  text
text-secondary        bg-hover             4,5    5,34    4,97  text
text-secondary        bg-secondary         4,5    5,34    6,63  text
text                  bg-selected          4,5   13,53    9,56  D91 vybraný řádek
text                  bg-selected-hover    4,5   11,39    7,91  D91 vybraný řádek
text-sidebar          bg-sidebar           4,5   10,88   11,06  D89 sidebar
state-concept-text    state-concept-bg     4,5    6,15    9,57  D90 Badge
state-edit-text       state-edit-bg        4,5    7,57    8,04  D90 Badge
state-confirmed-text  state-confirmed-bg   4,5    6,92    9,05  D90 Badge
state-archive-text    state-archive-bg     4,5    8,33    5,35  D90 Badge
state-trash-text      state-trash-bg       4,5   3,90*   2,81*  D90 Badge
state-cancelled-text  state-cancelled-bg   4,5    5,74    8,46  D90 Badge
state-done-text       state-done-bg        4,5    6,49    9,93  D90 Badge
state-error-text      state-error-bg       4,5    5,74    8,46  D90 Badge
alert-info-text       alert-info-bg        4,5    7,15    6,38  Notice
alert-warning-text    alert-warning-bg     4,5    6,37    9,63  Notice
alert-error-text      alert-error-bg       4,5    6,80    8,46  Notice
alert-success-text    alert-success-bg     4,5    6,49    9,93  Notice

* = zdůvodněná výjimka

Výjimky:
  danger-hover / bg  tmavý  2,31 : 1 (práh 3,00)
      D94: okraj tlačítka `danger` pod kurzorem v tmavém vzhledu; tlačítko identifikuje text (6,47 : 1), ne okraj
  state-trash-text / state-trash-bg  světlý  3,90 : 1 (práh 4,50)
      #153, tasks/design-system-contrast.md Odchylky: štítek stavu Koš je záměrně tlumený a přeškrtnutý — smazaný záznam nemá poutat pozornost
  state-trash-text / state-trash-bg  tmavý  2,81 : 1 (práh 4,50)
      #153, tasks/design-system-contrast.md Odchylky: štítek stavu Koš je záměrně tlumený a přeškrtnutý — smazaný záznam nemá poutat pozornost

Kontrast tokenů v pořádku: 59 dvojic ve dvou vzhledech (118 měření), výjimek: 2.
```

### Kontrast vykreslených barev (Chromium)

Playwright nad volným zdrojem dat `zey0-g…` (demo sada IT servis), 1280 px,
světlý i tmavý vzhled, shelly sidebar / classic / wild. Barva popředí vůči
pozadí složenému přes předky (včetně `opacity`), nejhorší hodnota místa;
u značky s obrysem po změně poměr **vůči obrysu**. Podvrženo: odznaky sekcí
(`/_ui/section-badges`), karty feedu s lhůtou a sekce Nové položky
(`/_ui/dashboard`), stavy sekcí a položek Přehledu firmy (`/_ui/overview`),
chyba navigace (500), konverzace chatu; `:hover` vybraného řádku mřížky
vynucen přes CDP (detail je v draweru). Celkem 522 měření po změně,
**žádné pod prahem**.

| místo | stav | světlý před → po | tmavý před → po | práh |
|---|---|---|---|---|
| tečka odznaku sekce (NavTree) (po: vůči obrysu) | dashboard | 2,54 → 4,83 | 2,70 → 4,83 | 3 |
| tečka chipu feedu (po: vůči obrysu) | dashboard, chip neaktivní | 4,83 → 4,83 | 5,41 → 4,83 | 3 |
| modrá tečka Nové položky | dashboard | 3,45 → 3,45 | 2,81 → 4,59 | 3 |
| lhůta karty feedu | dashboard | 3,88 → 5,74 | 4,60 → 8,46 | 4,5 |
| primary jako text — ikona | dashboard | 7,14 → 7,14 | 2,28 → 6,07 | 3 |
| primary jako text — text | dashboard | 7,14 → 7,14 | 2,28 → 6,07 | 4,5 |
| lhůta karty feedu | dashboard, karta pod kurzorem | 3,88 → 5,74 | 4,60 → 8,46 | 4,5 |
| tečka chipu feedu (po: vůči obrysu) | dashboard, chip pod kurzorem | 4,54 → 4,83 | 4,58 → 4,83 | 3 |
| tečka chipu feedu (po: vůči obrysu) | dashboard, chip aktivní | 1,73 → 4,83 | 2,17 → 4,83 | 3 |
| primary jako text — ikona | náhled importu | 7,14 → 7,14 | 2,28 → 6,07 | 3 |
| primary jako text — text | náhled importu | 7,14 → 7,14 | 2,28 → 6,07 | 4,5 |
| primary jako text — ikona | náhled importu, rozhodovací panel review-ref:rows[0].item | 7,14 → 7,14 | 2,28 → 6,07 | 3 |
| primary jako text — text | náhled importu, rozhodovací panel review-ref:rows[0].item | 7,14 → 7,14 | 2,28 → 6,07 | 4,5 |
| odznak Přehledu firmy | přehled firmy | 3,88 → 5,74 | 4,97 → 8,46 | 4,5 |
| primary jako text — text | přehled firmy | 8,37 → 8,37 | 2,49 → 6,63 | 4,5 |
| primary jako text — text | seznam Faktury přijaté | 8,37 → 8,37 | 2,49 → 6,63 | 4,5 |
| vybraný řádek — ikona | Faktury přijaté, vybraný | 4,31 → 4,31 | 4,61 → 4,61 | 3 |
| vybraný řádek — text | Faktury přijaté, vybraný | 2,21 → 7,94 | 2,64 → 6,77 | 4,5 |
| vybraný řádek — ikona | Faktury přijaté, vybraný pod kurzorem | 3,62 → 3,62 | 3,81 → 3,81 | 3 |
| vybraný řádek — text | Faktury přijaté, vybraný pod kurzorem | 2,03 → 7,06 | 2,34 → 5,74 | 4,5 |
| primary jako text — text | seznam Faktury vydané | 8,37 → 8,37 | 2,49 → 6,63 | 4,5 |
| vybraný řádek — ikona | Faktury vydané, vybraný | 4,31 → 4,31 | 4,61 → 4,61 | 3 |
| vybraný řádek — text | Faktury vydané, vybraný | 2,21 → 7,94 | 2,64 → 6,77 | 4,5 |
| vybraný řádek — ikona | Faktury vydané, vybraný pod kurzorem | 3,62 → 3,62 | 3,81 → 3,81 | 3 |
| vybraný řádek — text | Faktury vydané, vybraný pod kurzorem | 2,03 → 7,06 | 2,34 → 5,74 | 4,5 |
| primary jako text — text | seznam Bankovní výpisy | 8,37 → 8,37 | 2,49 → 6,63 | 4,5 |
| vybraný řádek — ikona | Bankovní výpisy, vybraný | 4,31 → 4,31 | 4,61 → 4,61 | 3 |
| vybraný řádek — text | Bankovní výpisy, vybraný | 2,21 → 7,94 | 2,64 → 6,77 | 4,5 |
| vybraný řádek — ikona | Bankovní výpisy, vybraný pod kurzorem | 3,62 → 3,62 | 3,81 → 3,81 | 3 |
| vybraný řádek — text | Bankovní výpisy, vybraný pod kurzorem | 2,03 → 7,06 | 2,34 → 5,74 | 4,5 |
| primary jako text — text | seznam Bankovní transakce (tabulka) | 8,37 → 8,37 | 2,49 → 6,63 | 4,5 |
| vybraný řádek — text | Bankovní transakce (tabulka), vybraný | — → 7,94 | — → 6,77 | 4,5 |
| vybraný řádek — text | Bankovní transakce (tabulka), vybraný pod kurzorem | — → 7,06 | — → 5,74 | 4,5 |
| vybraný řádek — text | Bankovní transakce (tabulka), vybraný chybový řádek (třída podvržena) | — → 7,94 | — → 6,77 | 4,5 |
| vybraná konverzace — text | chat, vybraná konverzace | 4,31 → 13,53 | 4,61 → 9,56 | 4,5 |
| vybraná konverzace — ikona | chat, vybraná konverzace | 4,31 → 4,31 | 4,61 → 4,61 | 3 |
| vybraná konverzace — text | chat, vybraná konverzace pod kurzorem | 4,31 → 13,53 | 4,61 → 9,56 | 4,5 |
| vybraná konverzace — ikona | chat, vybraná konverzace pod kurzorem | 4,31 → 4,31 | 4,61 → 4,61 | 3 |
| obrys fokusu pole (hledání) | seznam, pole hledání s fokusem | 7,86 → 7,86 | 2,49 → 6,63 | 3 |
| obrys fokusu pole (formulář) | formulář faktury, pole s fokusem | 8,37 → 8,37 | 2,49 → 6,63 | 3 |
| obrys fokusu segmentového přepínače | nastavení účtu, segment s fokusem | 8,37 → 8,37 | 2,49 → 6,63 | 3 |
| ikona chyby sidebaru | sidebar, chyba načtení navigace | 2,64 → 10,88 | 4,91 → 11,06 | 4,5 |
| text chyby sidebaru | sidebar, chyba načtení navigace | 10,88 → 10,88 | 11,06 → 11,06 | 4,5 |
| tečka odznaku (horní menu) (po: vůči obrysu) | horní menu, neaktivní položka | 2,54 → 4,83 | 2,70 → 4,83 | 3 |
| tečka odznaku (horní menu) (po: vůči obrysu) | horní menu, aktivní položka | 1,73 → 4,83 | 1,24 → 4,83 | 3 |
| tečka odznaku (horní menu) (po: vůči obrysu) | horní menu, položka pod kurzorem | 2,10 → 4,83 | 2,34 → 4,83 | 3 |
| odznak railu (po: vůči obrysu) | rail, neaktivní položka | 2,54 → 4,83 | 2,70 → 4,83 | 3 |
| odznak railu (po: vůči obrysu) | rail, aktivní položka | 1,73 → 4,83 | 1,24 → 4,83 | 3 |
| odznak railu (po: vůči obrysu) | rail, položka pod kurzorem | 2,10 → 4,83 | 2,34 → 4,83 | 3 |

„primary jako text“ = každý prvek s textem nebo ikonou v barvě `primary` /
`primary-text` na dané obrazovce: odkazy a přepínač detailu karty, AI
shrnutí, Badge `primary`, aktivní taby vieweru a detailu, odkazy v detailu
dokladu a příloh, „aktuální volba“ a „Vytvořit“ v rozhodovacím panelu
náhledu importu, odkazy Přehledu firmy. V rozhodovacích panelech prošlo
šest odkazů náhledu (partner, položky, kód DPH); demo sada nemá doklad
s předmětem přenesení (`ReverseChargeDecisionPanel` ověřen jen převodem
tokenu).

Modrá tečka Nové položky: světlý 3,45 (na `bg-secondary`), tmavý
2,81 → 4,59.

### Snímky před a po

42 dvojic snímků (dashboard, Přehled firmy, seznamy s vybraným řádkem
i pod kurzorem, sidebar, horní menu a rail s odznaky, chip feedu, chyba
navigace, fokus pole a segmentu, rozhodovací panel, chat) v obou
vzhledech. Světlý vzhled se liší jen na převedených místech: tečky
s obrysem, štítky jako `Badge`, barva textů vybraného řádku, ikona chyby
sidebaru; fokus, rozhodovací panel a ostatní `primary` texty jsou pixelově
shodné. Tmavý vzhled navíc mění všechny `primary` texty a ikony
(`#2d6890` → `#7fb2dc`), obrys fokusu a modrou tečku.

### Co zůstává ručně

- portál (`PortalContent`, štítek „Selhalo“) — na dev stroji není zdroj
  dat s `hosting.core`;
- vlastní vzhled se světlým sidebarem — tečka s obrysem na světlé barvě
  sidebaru (obrys splyne, tečka má vůči bílé 4,8 / 5,0 : 1);
- cíl přetažení souboru (rámeček `primary-text`) — přetažení souboru
  Playwright nezkoušel;
- mobilní drawer a úzká šířka (měřeno jen 1280 px).
