# Design system — jednotná výška, písmo a rámeček ovládacích prvků (D106–D109)

**Stav:** naplánováno — #156, D106–D109

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

- [ ] Tokeny výšky, písma a rámečku (D106, D108, D109), dvojice
  v `check-contrast.py`
- [ ] `Button` podle D107; ikonové a `ghost` čtverec
- [ ] Pole a výběry z tokenů; `--shpd-input-padding-y` pryč, nebo důvod
- [ ] Spouštěče, segmenty, filtr feedu, zaškrtávátka, radio z tokenů;
  „Projít frontu“ `md`
- [ ] Audit vlastních prvků — převedeno nebo v Odchylkách
- [ ] Výšky změřené v prohlížeči na obou šířkách (Ověření)
- [ ] Dokumentace, `**Stav:**`, index tasků

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

(vyplní implementace)

## Ověření

(vyplní implementace)
