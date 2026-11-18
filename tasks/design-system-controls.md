# Design system — jednotná výška, písmo a rámeček ovládacích prvků (D106–D109)

**Stav:** hotovo — #156, D106–D109 (2026-11-18)

## Kontext / Cíl

Ovládací prvky ve stejné liště mají různou výšku, protože ji každý
odvozuje z vlastního odsazení, písma a řádkování (audit v #156):

| prvek | písmo | výška (výpočet) |
|---|---|---|
| `Button` `md` | 16 px | ~34 px |
| `Button` `sm` | 14 px | ~24 px |
| `Input`, `Select`, `DateInput`, `NumberInput`, `ComboInput`, `MultiselectInput`, `LookupInput` | 16 px | ~30 px |
| spouštěč období (`PeriodPicker`, `VatPeriodPicker`) | 14 px | ~27 px |
| `SegmentedControl` `md` | 14 px | ~27 px |
| pilulky filtru feedu (`FeedFilter`) | 14 px | ~35 px |

Rámeček polí, spouštěčů, segmentů a zaškrtávátek (`--shpd-color-border`
/ `-strong`) má vůči pozadí 1,2–1,5 : 1; práh hranice ovládacího prvku je
3 : 1 (WCAG 1.4.11; doplnění z #153).

**Cíl:** všechny ovládací prvky v liště nebo řádku stejně vysoké, stejné
písmo, rámeček nad prahem v obou vzhledech; výška z tokenu, ne z
kombinace odsazení a řádkování.

**Mimo rozsah:**

- rozložení formuláře na úzké šířce (popisky vs. pole) — #157;
- patička review na telefonu (počet tlačítek) — #155;
- vzhled `primary` / `danger` / `success` tlačítek mimo výšku, písmo
  a odsazení.

## Návaznost

- **#156** — D106–D109 (komentář s auditem)
- **#153** — D95 (obrys fokusu), D103 (rámeček vybrané volby
  `primary-text`), `scripts/check-contrast.py`
- **#149** — D77 (segmentový přepínač)

## Před implementací přečti

- `docs/design-system.md` §3 Borders & focus, §5–§8 (tlačítka,
  formulářové prvky, segmentový přepínač, volitelné řádky), Layout
  konvence → breakpoint shellu
- `frontend/src/styles/variables.css` (spacing, typografie, `border*`,
  `--shpd-input-padding-y`)
- `frontend/src/components/ui/Button.svelte`, `Input.svelte`,
  `Select.svelte`, `TextArea.svelte`, `DateInput.svelte`,
  `NumberInput.svelte`, `ComboInput.svelte`, `LookupInput.svelte`,
  `MultiselectInput.svelte`, `SegmentedControl.svelte`, `Checkbox.svelte`,
  `OptionRow.svelte`
- `scripts/check-css-units.py` (breakpointy, povolené px),
  `scripts/check-contrast.py`

## Rozhodnutí (potvrzená v #156)

- ✓ **D106** `--shpd-control-height` `2rem`, `--shpd-control-height-sm`
  `1.75rem`; na telefonu (≤ 48rem) `2.5rem` / `2rem`. Běžnou výšku
  sdílí tlačítka, pole, výběry, spouštěče popoverů, segmentový přepínač
  a pilulky filtru; víceřádková pole ji mají jako minimum.
- ✓ **D107** `Button` `md` = běžná výška, `sm` = kompaktní; ikonové
  tlačítko a `ghost` jsou čtverec téže výšky; vodorovné odsazení `md`
  `space-md` (dnes `space-lg`). Každá lišta a tlačítko vedle pole mají
  běžnou výšku; kompaktní jen akce v řádku tabulky, na kartě a v hlavičce
  bloku. Hlavička dashboardu (filtr feedu, „Projít frontu“) běžnou výškou.
- ✓ **D108** Písmo ovládacích prvků `--shpd-font-size-body` (14 px); na
  telefonu pole 16 px (iOS přiblížení při fokusu).
- ✓ **D109** `--shpd-color-border-control` světlý `#7c8796`, tmavý
  `#737c8c` — pole, výběry, spouštěče, segment, pilulky filtru,
  nezaškrtnuté zaškrtávátko a radio, sekundární tlačítko. Neaktivní prvek
  zůstává `--shpd-color-border`.

## 1. Tokeny (D106, D108, D109)

`variables.css`:

- `--shpd-control-height: 2rem`, `--shpd-control-height-sm: 1.75rem`;
  v `@media (width <= 48rem)` (breakpoint shellu) `2.5rem` / `2rem`.
  Ověř, že `check-css-units.py` breakpoint ve `variables.css` připustí
  (povolená hodnota je stejná); pokud ne, rozšiř kontrolu o tokeny
  layoutu — ne značku `px-ok`.
- `--shpd-control-font-size: var(--shpd-font-size-body)`;
  `--shpd-field-font-size: var(--shpd-font-size-body)`, na telefonu
  `var(--shpd-font-size-base)` (jen pole a výběry — text, který uživatel
  píše; tlačítka a spouštěče zůstávají 14 px i na telefonu). Názvy
  tokenů uprav podle konvence, jen ať jsou dva: písmo prvku a písmo pole.
- `--shpd-color-border-control` světlý `#7c8796`, tmavý `#737c8c`
  (komentář s poměry a D109).
- `--shpd-input-padding-y` zanikne, pokud po převodu nikdo nepoužívá
  (výška je z tokenu; svislé odsazení 0, obsah centruje flex nebo
  `line-height`).

`scripts/check-contrast.py`: dvojice `border-control` × `bg`,
`bg-secondary` s prahem 3 (D109). `bg-hover` jen pokud se pole objevuje
na ploše `bg-hover` — tmavý vzhled tam má 3,01.

## 2. Tlačítko (D107, D108, D109)

`ui/Button.svelte`:

- `md`: `height: var(--shpd-control-height)`, vodorovně `space-md`,
  písmo `--shpd-control-font-size`; `sm`: `--shpd-control-height-sm`,
  vodorovně `space-sm` (nebo co vychází opticky — zapiš), písmo stejné.
- ikonové (`--icon-only`) a `ghost`: `width` = `height` podle velikosti,
  bez svislého odsazení.
- `--wrap` (víceřádkové tlačítko): `min-height` místo `height`, svislé
  odsazení zachovat.
- sekundární tlačítko: rámeček `--shpd-color-border-control`
  (`--shpd-btn-secondary-border` fallback upravit).
- `line-height` a centrování tak, aby text i ikona seděly svisle uprostřed
  při obou velikostech a s ikonou i bez ní.

## 3. Pole a výběry (D106, D108, D109)

`Input`, `Select`, `DateInput`, `NumberInput`, `ComboInput`,
`LookupInput`, `MultiselectInput`, `TextArea`, `settings/ColorField`,
`settings/SettingsPage` (všechna místa s `--shpd-input-padding-y`):

- jednořádkové: `height: var(--shpd-control-height)`; `MultiselectInput`
  a `TextArea`: `min-height`;
- písmo `--shpd-field-font-size`;
- rámeček `--shpd-color-border-control`; `:disabled` zůstává
  `--shpd-color-border`; fokus a chyba beze změny (D95, `danger`).

## 4. Spouštěče, segmenty, filtry, zaškrtávátka (D106–D109)

- `reports/PeriodPicker.svelte`, `reports/VatPeriodPicker.svelte` —
  `.shpd-period__trigger`: výška, písmo, rámeček z tokenů.
- `ui/SegmentedControl.svelte` — obě velikosti (dnes má odsazení jen
  `md`; výchozí velikost ověř v prohlížeči): běžná výška, písmo, rámeček
  nevybraného segmentu `border-control`. Vybraný segment beze změny (D103).
- `dashboard/FeedFilter.svelte` — pilulky: běžná výška, písmo, rámeček
  `border-control`; tečka odznaku (D88, D96) zůstává celá.
- `dashboard/Dashboard.svelte` — „Projít frontu“ `size="md"` (D107).
- `ui/Checkbox.svelte`, radio v `ui/OptionRow.svelte` — rámeček
  nezaškrtnutého `border-control`; velikost značky beze změny.

**Audit vlastních prvků:** grep `<button` s vlastním `padding` a
`border` ve stylu komponenty (`--include=*.svelte` po složkách
`components/*`), spouštěče popoverů, vlastní vyhledávací pole a
`size="sm"` u `Button` v lištách. Každé místo: převést na tokeny, nebo
zapsat do Odchylek s důvodem (prvek, který není ovládací prvek lišty —
odkaz v textu, položka menu, buňka tabulky). Tlačítka `sm` v liště nebo
vedle pole převést na `md` (D107); `sm` v řádku tabulky, na kartě a
v hlavičce bloku zůstává.

## 5. Dokumentace

`docs/design-system.md`:

- nový odstavec **Výška ovládacího prvku** (§5 nebo §8): tokeny D106,
  co je sdílí, kdy kompaktní (D107), písmo (D108), telefon;
- §3 Borders & focus: `border-control` (D109) vedle `border` a
  `border-focus`, neaktivní prvek;
- §5 Tlačítka: velikosti `md` / `sm`, ikonové a `ghost` čtverec,
  odsazení;
- §6 Anti-patterny: výška ovládacího prvku z odsazení a řádkování místo
  tokenu; `sm` tlačítko v liště.

## Commity

1. `feat(frontend): tokeny výšky, písma a rámečku ovládacího prvku (#156, D106, D108, D109)` — krok 1
2. `fix(frontend): tlačítko podle výšky ovládacího prvku (#156, D107)` — krok 2
3. `fix(frontend): pole a výběry podle výšky ovládacího prvku (#156, D106, D108, D109)` — krok 3
4. `fix(frontend): spouštěče, segmenty, filtr feedu a zaškrtávátka podle tokenů (#156, D106–D109)` — krok 4 vč. auditu vlastních prvků
5. `docs: výška, písmo a rámeček ovládacích prvků (#156, D106–D109)` — krok 5 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `check-contrast.py`, `check-css-tokens.py`, `check-css-units.py`;
  `cd frontend && npm run build`, `npm test`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis, oba
  vzhledy, šířka 1280 px a 390 px): **změřit výšku** (`getBoundingClientRect`)
  všech ovládacích prvků v těchto místech — lišta reportu (výběr období,
  parametry, Export), hlavička dashboardu (filtr, Projít frontu), lišta
  seznamu (hledání, Přidat, další akce), stavová lišta formuláře,
  formulář faktury (pole, výběr, datum, číslo, lookup, tlačítko vedle
  pole), patička dialogu, Nastavení účtu → Základní (segmenty),
  Nastavení s formulářem. Tabulka před / po: každý prvek v liště má
  běžnou výšku (32 / 40 px), kompaktní jen v řádcích a na kartách.
- Kontrast rámečku vykreslených polí vůči skutečnému podkladu (stránka,
  šedý panel, dialog) v obou vzhledech.
- Snímky před / po stejných míst. iOS přiblížení nejde v Chromiu ověřit —
  změřit jen `font-size` pole na 390 px (16 px).

## Hotovo když

- [x] Tokeny výšky, písma a rámečku (D106, D108, D109), dvojice
  v `check-contrast.py`
- [x] `Button` podle D107; ikonové a `ghost` čtverec
- [x] Pole a výběry z tokenů; `--shpd-input-padding-y` pryč, nebo důvod
- [x] Spouštěče, segmenty, filtr feedu, zaškrtávátka, radio z tokenů;
  „Projít frontu“ `md`
- [x] Audit vlastních prvků — převedeno nebo v Odchylkách
- [x] Výšky změřené v prohlížeči na obou šířkách (Ověření)
- [x] Dokumentace, `**Stav:**`, index tasků

## Pasti

- `height` na `<select>` a `<input type="date">` se v prohlížečích
  chová různě (vnitřní odsazení UA) — ověř Firefox i Chromium; Firefox
  je prohlížeč ručního ověření.
- Tlačítko s `--wrap` a víceřádkový text nesmí dostat pevnou `height`.
- Zmenšení písma pole na 14 px nesmí platit na telefonu — jinak iOS
  při fokusu přiblíží stránku.
- `FeedFilter` má v kontejneru rezervu proti ořezu tečky (D88) — při
  změně výšky pilulky zkontroluj snímkem.
- Pole v tabulce (`FormSubTable`, editovatelné buňky) — pokud používají
  `Input`, dostanou běžnou výšku; ověř, že řádky sub-tabulky nepovyrostou
  nečekaně (případně kompaktní výška, zapsat).
- Vývojářský dashboard (`dev/`) má vlastní styl — mimo rozsah, pokud
  sdílené komponenty nepřebírá; jinak zapsat.

## Odchylky od zadání

**Tokeny**

- Písmo se jmenuje `--shpd-font-size-control` a `--shpd-font-size-field`
  (konvence rolí písma `--shpd-font-size-*`), ne `--shpd-control-font-size`
  / `--shpd-field-font-size`.
- `check-contrast.py` hlídá `border-control` i vůči `bg-hover`: pod
  kurzorem na něm stojí sekundární tlačítko, segment a pilulka filtru.
  Tmavý vzhled má 3,01 : 1 — změna `bg-hover` kontrolu shodí.
- `check-css-units.py` beze změny — breakpoint `48rem` ve `variables.css`
  připouští.

**Tlačítko**

- `sm` má vodorovné odsazení 12 px (`0.75rem`), ne `space-sm`: s 8 px se
  text lepil na zaoblený roh.
- `ghost` **s popiskem** čtverec být nemůže (osm míst: Nahrát a Obnovit na
  dashboardu, Náhled v odeslání…): má výšku z tokenu a odsazení `space-sm`.
  Čtverec je každé tlačítko bez popisku — i bez `iconOnly`.

**Pole**

- `TextArea` dopočítává svislé odsazení z výšky a řádku
  (`(control-height − 1lh) / 2 − 1px`), `MultiselectInput` má pevné 2 px —
  čip je vyšší než řádek textu a s dopočtem by pole s jedním řádkem čipů
  bylo vyšší než výběr vedle.
- Položky nabídky `LookupInput` a `MultiselectInput` mají písmo pole (jinak
  by nabídka byla větší než pole); popisek `Checkbox` má písmo ovládacího
  prvku (14 px, dřív 16).

**Segmentový přepínač** má jen velikost `md` (jinou hodnotu komponenta
přepíše na `md`) — „obě velikosti“ ze zadání odpadají.

**Audit vlastních prvků — převedeno na tokeny:** hledání v liště seznamu
a pole reanalýzy (`Viewer`), `ViewerFilters`, rozbalovací akce v hlavičce
detailu (`ViewerDetail` — i zaoblení a odsazení jako sekundární tlačítko),
kebab `FormStateBar`, pole a obě tlačítka `ResolveDecisionPanel` (tlačítka
`min-height`, popisek se zalamuje), `SetPasswordPrompt`,
`RejectReasonPrompt`, výběr období `AssetsDepreciationRunDialog`,
stránkování `TableBrowser`, `ThemeField` (Upravit), `ThemeSwatches`
(přepínač báze), `ChatInput`, `ChatLauncher` (rámeček obalu, písmo pole),
políčko v nabídce `MultiselectInput`. Výběr kódu DPH v řádku review
(`DocumentExchangePreview`) má **kompaktní** výšku — je v řádku tabulky.

**Tlačítka `sm` → `md` (48 míst):** lišta seznamu (přepínač rozložení)
a reportu (Export), hlavička dashboardu (Nahrát, Obnovit, Projít frontu),
Přehled firmy (Obnovit), chat (Nová konverzace, Zpět na seznam), portál
(Nový zdroj dat), **hlavička detailu** (`ViewerDetail`,
`ViewerDetailDrawer`) a šipky předchozí / další v hlavičce `FormDialog`,
patičky dialogů (`ConfirmDialog`, `SendDialog`, `RejectReasonPrompt`,
`MailUploadModal`, `NewDatasourceModal`, `PrintPreviewDialog`,
`AssetsDepreciationRunDialog`, `SetPasswordPrompt`, reanalýza,
`QueueCategoriesPrompt`), tlačítka vedle pole (Přidat v `SendDialog`,
`ColorField`, `ChatLauncher`, obrázek a avatar v nastavení, výběr souborů
a přepínač rozsahu odpisů) a akční řádek pod seznamem v nastavení
(`ContentTagsSettings`, `AccountingItemsOffer`).

`sm` zůstává: řádky a hlavička `FormSubTable`, karty feedu a review,
`DsSetup`, `SessionsPanel`, `AttachmentPanel`, hromadné akce
`DocumentExchangePreview`, hlavička chat panelu, řádek konverzace,
tlačítko uvnitř hlášky (`Notice`), řádek tabulky štítků.

**Audit — ponecháno, není ovládací prvek lišty:**

- přihlašovací obrazovka (`LoginScreen`, `SetPasswordScreen`) a hlavní
  hledání `RegistryImportWizard` — záměrně větší pole; mění se jen rámeček;
- `CommandPalette` — pole bez rámečku v hlavičce palety;
- výběr barvy v `ThemeSwatches` — výška vzorku (2,25rem), jen rámeček;
- přejmenování přílohy v řádku (`AttachmentPanel`) — pole v textu řádku;
- záložky (`TabBar`, taby vieweru a detailu), buňky a položky popoveru
  období, vzorky barev, čip „+N“ na kartě feedu, přepínače příloh
  v detailu, kopírování tabulky v chatu, proměnné tisku, ikony uvnitř
  `LookupInput` (24 px);
- lišta telefonu (`MobileTopBar`), zavírání dialogu, `DetailHeader` —
  pevných 2,5rem, chrome shellu;
- nativní zaškrtávátko a radio (`ThemeField`, `ViewerFilters`,
  `BankAccountBridgeDialog`) — kreslí prohlížeč.

**Vývojářský dashboard** sdílí `Button` a `variables.css`: tlačítka tam mají
novou výšku a písmo (`sm` 24 → 28 px, řádky tabulek o to vyšší). Rámeček
sekundárního tlačítka si dál určuje `dev/theme.css`; vlastní prvky `dev/`
jsem neměnila.

**Mimo rozsah, nalezeno měřením:** plné prvky v barvě `primary` (primární
tlačítko, zaškrtnuté zaškrtávátko, aktivní pilulka) mají v tmavém vzhledu
vůči podkladu 2,49–2,81 : 1 — stav před taskem, D109 se týká nevyplněných
prvků.

## Ověření

Kontroly: `check-contrast.py` (63 dvojic), `check-css-tokens.py`,
`check-css-units.py`, `npm run build`, `npm test` (305) — v pořádku.

Chromium (Playwright), zdroj dat `zey0-g…` (volný, demo sada IT servis),
světlý i tmavý vzhled, 1280 a 390 px; „před“ = sestavení z commitu před
taskem. Výška `getBoundingClientRect`, v závorce písmo.

| místo | prvek | před 1280 | po 1280 | před 390 | po 390 |
|---|---|---|---|---|---|
| lišta seznamu | Přidat, Otevřít | 34 (16) | 32 (14) | lišta telefonu 40 | beze změny |
| | hledání | 31 (14) | 32 (14) | 31 (14) | 40 (16) |
| | filtr (výběr) | 29 (14) | 32 (14) | 29 (14) | 40 (16) |
| hlavička detailu | akce, Tisk ▾ | 29,6 (14) | 32 (14) | 24 / 29,6 | 40 (14) |
| hlavička dashboardu | pilulky filtru | 39 (14) | 32 (14) | 39 | 40 (14) |
| | Projít frontu, Nahrát, Obnovit | 24 (14) | 32 (14) | 24 | 40 (14) |
| lišta reportu | období | 31 (14) | 32 (14) | 31 | 40 (14) |
| | parametr (výběr) | 34 (16) | 32 (14) | 34 (16) | 40 (16) |
| | segmenty | 31 (14) | 32 (14) | 31 | 40 (14) |
| | Export | 24 (14) | 32 (14) | 24 | 40 (14) |
| formulář faktury | pole, výběr, lookup, číslo | 34 (16) | 32 (14) | 34 (16) | 40 (16) |
| | datum | 36 (16) | 32 (14) | 36 (16) | 40 (16) |
| | stavová lišta | 34 (16) | 32 (14) | 40 (16) | 40 (14) |
| odeslání e-mailem | pole + Přidat vedle | 34 / 34 | 32 / 32 | 34 / 34 | 40 / 40 |
| | patička dialogu | 24 (14) | 32 (14) | 24 | 40 (14) |
| nastavení účtu / aplikace | segmenty | 31 (14) | 32 (14) | 31 | 40 (14) |
| | pole, Uložit, Nahrát | 34 / 34 / 24 | 32 | 34 / 34 / 24 | 40 |
| **kompaktní** — karta feedu | Zkontrolovat, Zamítnout | 24 (14) | 28 (14) | 24 | 32 (14) |
| **kompaktní** — sub-tabulka | Přidat, ikony řádku | 24 / 22,3 | 28 | 24 / 22,3 | 32 |

Po změně má ve změřených místech každý prvek lišty 32 px (101 prvků) /
na telefonu 40 px (95), kompaktní 28 / 32 px (17). Řádek sub-tabulky
zůstal 30 px (na telefonu 114 px, karta řádku) — ikonová tlačítka ho
nezvýšila.

Naměřené výšky „před“ se liší od výpočtu v zadání: `Button` `sm`
v hlavičce detailu 29,6 px (roztažený řádkem), pole 34 px (řádkování 1,5),
datum 36 px, pilulky filtru 39 px.

Kontrast vykresleného rámečku vůči skutečnému podkladu:

| podklad | světlý před | světlý po | tmavý před | tmavý po |
|---|---|---|---|---|
| stránka, dialog (`bg`) | 1,23 | 3,64 | 1,42 | 3,55 |
| šedý panel formuláře, pozadí seznamu (`bg-secondary`) | 1,16 | 3,42 | 1,60 | 4,01 |
| neaktivní pole | 1,16 | 1,16 | 1,60 | 1,60 |

Tečka filtru feedu (D88) po změně výšky pilulky: snímek ve trojnásobném
zvětšení na obou šířkách, neaktivní i aktivní pilulka — tečka i obrys celé.

`font-size` pole na 390 px: 16 px (vstup, výběr, lookup, víceřádkový text,
hledání a filtr seznamu); tlačítka, spouštěč, segmenty a pilulky 14 px.

**Neověřeno v prohlížeči:**

- **Firefox** — `<select>` a `<input type="date">` s pevnou výškou; ruční
  ověření.
- iOS přiblížení při fokusu (jen `font-size`).
- Převedená místa, kam se měření nedostalo: panel rozhodnutí v review,
  dialog odpisů, reanalýza, nastavení hesla uživatele, prohlížeč tabulek,
  panel vlastního vzhledu, chat (bez AI klíče), portál (hosting na stroji
  není), přihlašovací obrazovka, `MultiselectInput` s čipy — změna je
  v CSS stejného tvaru jako u změřených prvků.
- Zalamované tlačítko `wrap` jen mimo aplikaci: stejná pravidla na
  samostatné stránce dala jednořádkové 32 / 28 px (jako bez `wrap`),
  dvouřádkové 47 px a `TextArea` s jedním řádkem 32 px.
- Vývojářský dashboard jen pohledem na Zdroje dat (tlačítko `sm` 28 px).
