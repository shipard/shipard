# Design system — větší ovládací prvky, světlý rámeček, výjimka plných prvků (D110–D112)

**Stav:** naplánováno — #156, D110–D112

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

- [ ] Tokeny podle tabulky (D110, D111), `--shpd-font-size-control-sm`
- [ ] `Button` `md` odsazení `space-lg`, kompaktní místa písmo 14 px
- [ ] `TextArea`, `MultiselectInput` přesně běžná výška
- [ ] Výjimky D111 a D112 v `check-contrast.py`
- [ ] Výšky a písmo změřené (Ověření)
- [ ] Dokumentace, `**Stav:**`, index tasků

## Pasti

- Tlačítka s dlouhým popiskem v úzkých místech (patičky dialogů na
  telefonu, panel rozhodnutí) se s 16 px a `space-lg` rozšíří — zalamování
  (`--wrap`) a přetečení ověř snímkem na 390 px; nové přetečení zapiš
  (panel rozhodnutí je #155).
- Vývojářský dashboard sdílí `Button` — dostane větší tlačítka; vlastní
  prvky `dev/` neměnit, jen zapsat.

## Odchylky od zadání

(vyplní implementace)

## Ověření

(vyplní implementace)
