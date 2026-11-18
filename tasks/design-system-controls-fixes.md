# Design system — větší ovládací prvky, světlý rámeček, výjimka plných prvků (D110–D112)

**Stav:** hotovo — #156, D110–D112 (2026-11-18); ručně ověřeno 2026-11-18 (Firefox, oba vzhledy, telefon); iOS neověřeno (zařízení není)

## Kontext / Cíl

Ruční ověření `tasks/design-system-controls.md` (D106–D109): stejná výška
prvků v liště sedí všude, ale

- rámeček `border-control` (`#7c8796`) je ve **světlém** vzhledu příliš
  tmavý — ruší; tmavý vzhled v pořádku;
- písmo 14 px v polích formuláře je malé;
- tlačítka v patičkách formuláře a dialogu jsou malá — raději zpět větší
  všude; na telefonu je text tlačítek malý.

Měření našlo navíc plné prvky v barvě `primary` v tmavém vzhledu pod
prahem 3 : 1 vůči podkladu (stav před #156).

**Cíl:** běžné ovládací prvky 36 px s písmem 16 px, ve světlém vzhledu
původní jemný rámeček, obě výjimky zdokumentované a vyjmenované
v `check-contrast.py`.

**Mimo rozsah:** tlačítka panelu rozhodnutí v náhledu importu na telefonu
(#155); kompaktní výška (28 / 32 px) beze změny.

## Návaznost

- **#156** — D106–D109, D110–D112 (komentář s ručním ověřením)
- `tasks/design-system-controls.md` (tokeny, výčet převedených míst
  v Odchylkách)
- **#153** — D94 (výjimka `danger` hover), `scripts/check-contrast.py`

## Před implementací přečti

- `tasks/design-system-controls.md` — Odchylky (kde se tokeny používají,
  kompaktní místa, `TextArea` / `MultiselectInput` odsazení)
- `docs/design-system.md` — odstavec Výška ovládacího prvku, §3 Borders &
  focus, výjimky
- `frontend/src/styles/variables.css` — `--shpd-control-height*`,
  `--shpd-font-size-control`, `--shpd-font-size-field`,
  `--shpd-color-border-control`
- `scripts/check-contrast.py` — `PAIRS`, `EXCEPTIONS`

## Rozhodnutí (potvrzená v #156)

- ✓ **D110** (upravuje D106–D108) Běžná výška `2.25rem` (36 px); telefon
  `2.5rem` beze změny; kompaktní `1.75rem` / `2rem` beze změny. Písmo
  běžných ovládacích prvků 16 px (`base`) — pole, tlačítka, spouštěče,
  segmenty, pilulky filtru, desktop i telefon; kompaktní prvky 14 px.
  Tlačítko `md` vodorovně `space-lg`.
- ✓ **D111** (upravuje D109) Rámeček ovládacího prvku ve světlém vzhledu
  `#e2e8f0` (= `--shpd-color-border`) — zdůvodněná výjimka; tmavý
  `#737c8c` zůstává.
- ✓ **D112** Zdůvodněná výjimka (rozšíření D94): plné prvky `primary`
  (primární tlačítko, zaškrtnuté zaškrtávátko, aktivní pilulka a segment)
  v tmavém vzhledu 2,49–2,81 : 1 vůči podkladu; identifikuje je bílý text
  nebo značka uvnitř.

## 1. Tokeny (D110, D111)

`variables.css`:

| token | dnes | nově |
|---|---|---|
| `--shpd-control-height` | `2rem` | `2.25rem` (telefon `2.5rem` beze změny) |
| `--shpd-font-size-control` | `body` | `base` |
| `--shpd-font-size-field` | `body`, telefon `base` | `base` (override pro telefon zbytečný — smazat) |
| `--shpd-font-size-control-sm` | — | nový, `body` — písmo kompaktních prvků |
| `--shpd-color-border-control` světlý | `#7c8796` | `var(--shpd-color-border)` |
| `--shpd-color-border-control` tmavý | `#737c8c` | beze změny |

Komentáře u tokenů přepsat (D110, D111; poměry). Pokud se
`--shpd-font-size-control` a `--shpd-font-size-field` po změně nijak
neliší, nech je oba (role se mohou rozejít) — jen zapiš.

## 2. Komponenty (D110)

- `ui/Button.svelte`: `md` vodorovně `space-lg`; `sm` písmo
  `--shpd-font-size-control-sm` (14 px). Ikonové a `ghost` bez popisku
  zůstávají čtverec (teď 36 px).
- Všechna místa s **kompaktní** výškou (`--shpd-control-height-sm`;
  výčet v Odchylkách předchozího tasku — karty feedu a review, řádky
  a hlavička `FormSubTable`, výběr kódu DPH v řádku review…) písmo
  `--shpd-font-size-control-sm`. Grep na `control-height-sm`.
- `TextArea` (dopočet svislého odsazení) a `MultiselectInput` (pevné
  2 px, čipy) — ověřit s výškou 36 px a písmem 16 px, že jednořádkové pole
  má přesně běžnou výšku; případně upravit.
- Položky nabídky `LookupInput` a `MultiselectInput`, popisek `Checkbox`
  — sledují tokeny písma, ověřit.
- Pilulky `FeedFilter`: tečka (D88, D96) celá i s větším písmem.

## 3. Výjimky v `check-contrast.py` (D111, D112)

- D111: dvojice `border-control` × `bg`, `bg-secondary`, `bg-hover` ve
  **světlém** vzhledu jako výjimka s důvodem (tmavý vzhled zůstává
  hlídaný s prahem 3). Pokud skript výjimku per vzhled neumí, doplň to.
- D112: `primary` × `bg`, `bg-secondary` (případně `bg-hover`) v tmavém
  vzhledu jako výjimka s důvodem — vedle D94; `--list` je vypíše.
- Kontrola „zbytečné výjimky“ musí obě výjimky přijmout (dvojice opravdu
  práh nesplní).

## 4. Dokumentace

`docs/design-system.md`:

- Výška ovládacího prvku — hodnoty D110 (36 / 40 px, kompaktní 28 / 32),
  písmo 16 / 14 px, odsazení tlačítka; odkaz D106–D108 → D110;
- §3 Borders & focus — `border-control` světlý = `border` (D111),
  tmavý `#737c8c`;
- výjimky (u D94) — D111 a D112 s důvodem.

`tasks/design-system-controls.md` — do Stavu odkaz na tento task.

## Commity

1. `fix(frontend): ovládací prvky 36 px s písmem 16 px, kompaktní 14 px (#156, D110)` — kroky 1 (výška, písmo) a 2
2. `fix(frontend): světlý rámeček ovládacího prvku, výjimky D111 a D112 v kontrole kontrastu (#156, D111, D112)` — krok 1 (rámeček) a 3
3. `docs: větší ovládací prvky, výjimky rámečku a plných prvků (#156, D110–D112)` — krok 4 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `check-contrast.py` (výjimky D111, D112 ve `--list`),
  `check-css-tokens.py`, `check-css-units.py`, `npm run build`, `npm test`.
- Chromium (zdroj `free` s demo sadou IT servis, oba vzhledy, 1280 a
  390 px): **změřit výšku a písmo** na stejných místech jako
  `tasks/design-system-controls.md` (lišta seznamu, hlavička detailu,
  hlavička dashboardu, lišta reportu, formulář faktury, dialog odeslání,
  Nastavení účtu, kompaktní místa). Tabulka: běžné 36 px / 16 px
  (telefon 40 / 16), kompaktní 28 / 14 (telefon 32 / 14).
- Snímky po v obou vzhledech: lišta reportu, formulář faktury s patičkou,
  dialog, karta feedu.

## Hotovo když

- [x] Tokeny podle tabulky (D110, D111), `--shpd-font-size-control-sm`
- [x] `Button` `md` odsazení `space-lg`, kompaktní místa písmo 14 px
- [x] `TextArea`, `MultiselectInput` přesně běžná výška
- [x] Výjimky D111 a D112 v `check-contrast.py`
- [x] Výšky a písmo změřené (Ověření)
- [x] Dokumentace, `**Stav:**`, index tasků

## Pasti

- Tlačítka s dlouhým popiskem v úzkých místech (patičky dialogů na
  telefonu, panel rozhodnutí) se s 16 px a `space-lg` rozšíří — zalamování
  (`--wrap`) a přetečení ověř snímkem na 390 px; nové přetečení zapiš
  (panel rozhodnutí je #155).
- Vývojářský dashboard sdílí `Button` — dostane větší tlačítka; vlastní
  prvky `dev/` neměnit, jen zapsat.

## Odchylky od zadání

**Tokeny a písmo**

- Vedle `--shpd-font-size-control-sm` přibyl `--shpd-font-size-field-sm`
  (14 px, na telefonu 16 px). Bere ho jediné kompaktní **pole** — výběr
  kódu DPH v řádku review: se 14 px by iOS při fokusu stránku přiblížil
  (důvod D108). Tabulka měření má proto u tohoto prvku na telefonu
  32 / 16, ne 32 / 14.
- Kompaktní výšku (`--shpd-control-height-sm`) mají v kódu jen dvě místa —
  `Button` `sm` a výběr kódu DPH; karty feedu a review, řádky a hlavička
  `FormSubTable` jsou tlačítka `sm`. Jiné místo se neměnilo.
- `--shpd-font-size-control` a `--shpd-font-size-field` mají teď stejnou
  hodnotu; zůstávají oba (zapsáno v komentáři u tokenů).
- S tokeny narostlo na 16 px i to, co je sleduje: popisek `Checkbox`,
  položky nabídky `LookupInput` a `MultiselectInput`, pole chatu a panelu
  rozhodnutí. Čipy `MultiselectInput` zůstávají 14 px (`font-size-sm`),
  popisky polí formuláře 14 px (`body`) — nejsou ovládací prvek.
- `TextArea` a `MultiselectInput` beze změny: dopočet z `1lh` a pevné 2 px
  dávají s 36 px a 16 px přesně běžnou výšku (měření níže).

**Tlačítko**

- Odsazení `space-lg` dostala i dvě vlastní tlačítka v podobě sekundárního
  `Button` — rozbalovací akce v hlavičce detailu (Tisk ▾) a Upravit
  u vzhledu (`ThemeField`). Samostatný commit, proto jsou commity kódu tři.
- Segment přepínače a pilulka filtru mají dál vodorovně `space-md` — D110
  mluví jen o tlačítku `md`.

**Výjimky kontrastu**

- `check-contrast.py` výjimku per vzhled uměl (`Exemption.themes`) —
  logika se neměnila, přibyly jen záznamy.
- D112: výjimka je pro `primary` × `bg` a `bg-secondary`, mimo `PAIRS`
  jako D94 (jen dokumentace ve `--list`). Na `bg-hover` vychází 2,11 : 1 —
  mimo rozsah rozhodnutí (2,49–2,81), výjimku nemá; je zapsané
  v `docs/design-system.md`.
- D112 jmenuje „aktivní segment“ — vybraný segment plný není (D103:
  rámeček `primary-text`, pozadí `primary-soft`). Plné jsou primární
  tlačítko, zaškrtnuté zaškrtávátko, vybrané radio v `OptionRow`
  a aktivní pilulka filtru; tak je to v důvodu výjimky i v dokumentaci.

**Přetečení na 390 px (větší písmo a odsazení)**

- **Patička náhledu importu (review)**: před změnou se nevešlo jedno
  tlačítko ze čtyř (Vystavit a uzavřít), teď dvě (Vystavit koncept
  oříznuté, Vystavit a uzavřít mimo obrazovku) — #155.
- **Akce v hlavičce detailu** faktury se zalamují do tří řad místo dvou;
  nic se neořezává.
- Dialog odeslání, formulář faktury (stavová lišta s kebabem), lišta
  reportu, hlavička dashboardu a panel rozhodnutí (tlačítka přes šířku
  panelu) bez přetečení.

**Vývojářský dashboard** sdílí `Button` a `variables.css`: tlačítka `md`
tam mají 36 px, písmo 16 px a širší odsazení, `sm` beze změny (28 px,
14 px). Rámeček sekundárního tlačítka si dál určuje `dev/theme.css`;
vlastní prvky `dev/` jsem neměnila.

## Ověření

Kontroly: `check-contrast.py` (63 dvojic, 6 výjimek — D111 a D112 ve
`--list`), `check-css-tokens.py`, `check-css-units.py`, `npm run build`,
`npm test` (305) — v pořádku.

Chromium (Playwright), zdroj dat `zey0-g…` (volný, demo sada IT servis),
světlý i tmavý vzhled, 1280 a 390 px; „před“ = stav po D106–D109. Výška
`getBoundingClientRect` / písmo v px, stejně v obou vzhledech.

| místo | prvek | před 1280 | po 1280 | před 390 | po 390 |
|---|---|---|---|---|---|
| lišta seznamu | Přidat, Otevřít | 32 / 14 | 36 / 16 | lišta telefonu | beze změny |
| | hledání, filtr (výběr) | 32 / 14 | 36 / 16 | 40 / 16 | 40 / 16 |
| hlavička detailu | akce, Tisk ▾ | 32 / 14 | 36 / 16 | 40 / 14 | 40 / 16 |
| hlavička dashboardu | pilulky filtru, Projít frontu, Nahrát, Obnovit | 32 / 14 | 36 / 16 | 40 / 14 | 40 / 16 |
| lišta reportu | období, parametr, segmenty, Export | 32 / 14 | 36 / 16 | 40 / 14 (výběr 16) | 40 / 16 |
| formulář faktury | pole, výběr, lookup, číslo, datum | 32 / 14 | 36 / 16 | 40 / 16 | 40 / 16 |
| | stavová lišta (patička) | 32 / 14 | 36 / 16 | 40 / 14 | 40 / 16 |
| odeslání e-mailem | pole + Přidat vedle, patička | 32 / 14 | 36 / 16 | 40 / 14 (pole 16) | 40 / 16 |
| nastavení účtu / aplikace | segmenty, pole, Uložit, Nahrát | 32 / 14 | 36 / 16 | 40 / 14 (pole 16) | 40 / 16 |
| panel rozhodnutí (review) | pole hledání, tlačítka | 32 / 14 | 36 / 16 | 40 / 14 (pole 16) | 40 / 16 |
| **kompaktní** — karta feedu | Zkontrolovat, Zamítnout | 28 / 14 | 28 / 14 | 32 / 14 | 32 / 14 |
| **kompaktní** — sub-tabulka | Přidat, ikony řádku | 28 / 14 | 28 / 14 | 32 / 14 | 32 / 14 |
| **kompaktní** — řádek review | výběr kódu DPH | 28 / 14 | 28 / 14 | 32 / 16 | 32 / 16 |

Ve změřených místech má každý běžný prvek 36 px / 16 px (101 prvků), na
telefonu 40 / 16 (91), kompaktní tlačítko 28 / 14 a 32 / 14 (17). Řádek
sub-tabulky zůstal 30 px.

Pravidla s dopočtem na samostatné stránce (stejné CSS, výška 36 / 40 px):
`TextArea` s jedním řádkem 36 / 40 px, `MultiselectInput` prázdný
i s jedním řádkem čipů 36 / 40 px, zalamované tlačítko `wrap` jednořádkové
36 / 40 px (`sm` 28 / 32), dvouřádkové 80 px (`sm` 47).

Kontrast vykresleného rámečku vůči skutečnému podkladu:

| podklad | světlý před | světlý po | tmavý (beze změny) |
|---|---|---|---|
| stránka, dialog (`bg`) | 3,64 | 1,23 | 3,55 |
| šedý panel formuláře, pozadí seznamu (`bg-secondary`) | 3,42 | 1,16 | 4,01 |
| neaktivní pole | 1,16 | 1,16 | 1,60 |
| plný prvek `primary` na `bg` / `bg-secondary` (D112) | 8,37 / 7,86 | beze změny | 2,49 / 2,81 |

Tečka filtru feedu (D88, D96) s větším písmem: snímek ve trojnásobném
zvětšení na obou šířkách, neaktivní i aktivní pilulka — tečka i obrys celé.

Snímky po v obou vzhledech (lišta reportu, formulář faktury s patičkou,
dialog odeslání, karta feedu) prohlédnuté; přetečení na 390 px měřené
proti sestavení před taskem — nálezy v Odchylkách.

**Neověřeno v prohlížeči:**

- **Firefox** a iOS — ruční ověření.
- Odsazení Tisk ▾ a Upravit u vzhledu po srovnání s tlačítkem `md` —
  změna jedné hodnoty, znovu neměřeno.
- `MultiselectInput` a jednořádková `TextArea` v aplikaci (jen na
  samostatné stránce); dialog odpisů, reanalýza, nastavení hesla,
  prohlížeč tabulek, panel vlastního vzhledu, chat, portál, přihlašovací
  obrazovka, potvrzovací dialogy — sdílejí změřené komponenty a tokeny.
- Vývojářský dashboard jen pohledem na Zdroje dat (tlačítko `sm` 28 px).

### Ruční ověření (2026-11-18, Firefox, oba vzhledy, telefon)

- Formulář faktury: větší písmo v polích, větší tlačítka ve stavové
  liště, pole včetně data a výběru stejně vysoká — v pořádku, ponechat.
- Dialog odeslání e-mailem (pole + Přidat, patička) — v pořádku.
- Rámečky ve světlém vzhledu zpět jemné, v tmavém výraznější — v pořádku.
- Lišta reportu, hlavička dashboardu (tečka filtru celá, kompaktní
  tlačítka na kartách) — v pořádku.
- Hlavička detailu dokladu: akce a Tisk ▾ stejně velké — v pořádku.
- Nastavení účtu → Základní (segmenty, zaškrtávátka) — v pořádku.
- Telefon: větší text tlačítek, zalamování akcí v hlavičce detailu —
  v pořádku. Patička náhledu importu na telefonu → #155.
- iOS přiblížení při fokusu neověřeno (zařízení není k dispozici).
