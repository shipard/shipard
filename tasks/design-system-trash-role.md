# Design system — Koš výraznější než Archiv, role v Saldu pohybů textem (D104, D105)

**Stav:** hotovo — #153, D104, D105 (2026-11-17); ručně ověřeno 2026-11-17

## Kontext / Cíl

Ruční ověření `tasks/design-system-row-states.md` (D98–D103) prošlo se
dvěma připomínkami:

- **Koš málo odlišený od Archivu.** Řádky mají po D101 stejný šedý text;
  rozdíl nese jen proužek a štítek. Ve světlém vzhledu je proužek koše
  (`#94a3b8`) jen o málo tmavší než archivu (`#cbd5e1`); v tmavém vzhledu
  (proužky nemají tmavou variantu) je archiv výraznější než koš.
  Štítky „V archívu“ a „Smazáno“ jsou v tmavém vzhledu totožné.
- **Role v mřížce Salda pohybů** (Předpis / Úhrada / Příjem / Výdaj) byla
  pilulka `primary` / `success`; po D99 stojí vedle sebe pilulka a tečka.
  Role je druh pohybu, ne stav.

**Cíl:** smazaný záznam na první pohled odlišný od archivního v obou
vzhledech; role v mřížce jako v řádku seznamu.

**Mimo rozsah:** šedý text archivních a smazaných řádků (D101 beze změny),
ostatní stavy a jejich proužky.

## Návaznost

- **#153** — D101, D104, D105 (komentář s ručním ověřením)
- `tasks/design-system-row-states.md` (D99, D101)
- #69 D23 (popis druhu pohybu v Saldu pohybů)

## Před implementací přečti

- `docs/design-system.md` §4 Doc-state systém (tabulka stavů)
- `frontend/src/styles/variables.css` — `state-archive-*`, `state-trash-*`
  v `:root` a `[data-theme="dark"]`
- `modules/economy/accbal/src/LedgerViewer.php` — `roleOf()`,
  `renderGridRow()` (sloupec `role`), `renderRow()` (`t2`)

## Rozhodnutí (potvrzená v #153)

- ✓ **D104** Koš výraznější než Archiv v obou vzhledech: proužek koše
  tmavší ve světlém, v tmavém ztlumený proužek archivu; štítek Smazáno plná
  tmavě šedá pilulka se světlým textem.
- ✓ **D105** Role v mřížce Salda pohybů prostým textem.

## 1. Koš a Archiv (D104)

`variables.css`:

| token | světlý | tmavý |
|-------|--------|-------|
| `--shpd-color-state-archive-bar` | `#cbd5e1` (beze změny) | `#4b5563` (nový override) |
| `--shpd-color-state-trash-bar` | `#64748b` | `#94a3b8` (override, dnes dědí ze světlého) |
| `--shpd-color-state-trash-bg` | `#4b5563` | `#4b5563` |
| `--shpd-color-state-trash-text` | `#ffffff` | `#e5e7eb` |

Kontrast štítku: světlý 7,56 : 1, tmavý 6,10 : 1 (dvojici hlídá
`check-contrast.py`, komentáře u tokenů aktualizuj). Archivní štítek
(`state-archive-bg` / `-text`) beze změny.

Tokeny se propíšou samy — ověř grepem, že `state-trash-*` /
`state-archive-bar` nikde nejsou použité jinak, než jak počítá tabulka:
proužek (`--shpd-row-bar` v `base.css`) v `ViewerRow`, `ViewerGrid`,
`FormSubTable`, `FeedCard`; štítek Smazáno v `ui/Badge`,
`viewer/SpanBadge`, `form/FormStateBadge`. Pokud `trash-bg` někde slouží
jako podklad plochy (ne pilulky), zapiš do Odchylek — plná tmavá šedá by
tam nepatřila.

## 2. Role v mřížce Salda pohybů (D105)

`LedgerViewer`: buňka `role` v `renderGridRow()` vrací prostý text
(`['text' => …]`, bez `badge`); `roleOf()` vrací jen text (`string`),
docblock přepsat (barva podle `bal_side` zaniká; odkaz na #69 D23 a #153
D105). `renderRow()` (`t2`, šedý text) beze změny.

Testy: `tests/Unit/Module/Economy/Accbal/LedgerViewerTest.php` a
integrační `tests/Integration/Accbal/LedgerViewerTest.php` — upravit
očekávání buňky role (`--filter LedgerViewerTest`).

## 3. Dokumentace

- `docs/design-system.md` §4 — tabulka stavů: Archiv a Koš (proužek
  v obou vzhledech, štítek Smazáno plná pilulka), věta o rozlišení koše
  a archivu (D104).
- Dokumentace saldokonta, pokud popisuje barvu role (grep „Předpis“
  v `docs/` a `modules/economy/accbal/`).

## Commity

1. `fix(frontend): Koš výraznější než Archiv v obou vzhledech (#153, D104)` — krok 1
2. `fix(accbal): role v mřížce Salda pohybů prostým textem (#153, D105)` — krok 2
3. `docs: Koš a Archiv, role v Saldu pohybů (#153, D104, D105)` — krok 3 + `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `check-contrast.py`, `check-css-tokens.py`, `check-css-units.py`;
  `npm run build`, `npm test`; `vendor/bin/phpunit --filter LedgerViewerTest`.
- Chromium (zdroj `free` s demo sadou IT servis, oba vzhledy; archivní
  a smazaný záznam podvržený v odpovědi API jako v předchozím tasku):
  snímky před / po — Osoby se záložkou Vše (archivní a smazaná osoba pod
  sebou), detail smazané a archivní osoby (štítky), mřížka se štítkem
  Smazáno, karta feedu archivního záznamu, Saldo pohybů v mřížce. Kontrast
  štítku Smazáno a proužky (poměr vůči pozadí řádku) do tabulky.

## Hotovo když

- [x] Tokeny podle tabulky, štítek Smazáno plná pilulka, koš výraznější
  než archiv ve světlém i tmavém vzhledu (D104)
- [x] Role v mřížce Salda pohybů textem, testy (D105)
- [x] Dokumentace, `**Stav:**`, index tasků

## Pasti

- Tmavý blok `variables.css` proužky dosud nepřepisuje — nový override
  patří k ostatním stavovým tokenům tmavého bloku.
- Štítek Smazáno v mřížce (`SpanBadge` `trash`) a v hlavičce (`Badge`,
  `FormStateBadge`) musí vypadat stejně.

## Odchylky od zadání

Hodnoty tokenů i rozsah jsou podle zadání. Zjištění, která zadání
nepředpokládalo:

- **Sub-tabulka formuláře proužek nekreslí.** `FormSubTable` dává řádku
  třídu `docState_*` jen kvůli tlumenému textu (D101); `--shpd-row-bar`
  čtou `ViewerRow`, `ViewerGrid` a `FeedCard`. Změna proužku se
  sub-tabulky netýká.
- **Pilulku `trash` do mřížky dnes backend neposílá.** Stav v řádku
  seznamu i mřížky vypisuje `rowStateTag()` jako tlumený text (D98).
  Varianta `SpanBadge` `trash` sdílí tokeny s `Badge` a `FormStateBadge`,
  takže vypadá stejně — ověřeno na buňce podvržené v odpovědi API.
- **`state-trash-bg` neslouží nikde jako podklad plochy** — jen pilulky
  (`Badge`, `SpanBadge`, `FormStateBadge`). `state-archive-bg` / `-text`
  používá i `ViewerDetail` (štítek návrhu) a vývojářský dashboard
  (`dev.css`) — beze změny.
- **Integrační `LedgerViewerTest` buňku role netestuje** — beze změny,
  jen spuštěn. Dokumentace saldokonta (`docs/accbal.md`,
  `docs/viewer-grid.md`, `docs/frontend.md`) barvu role nepopisuje —
  beze změny.
- V `docs/design-system.md` §3 opraven rozsah kontrastu dvojic `Badge`
  (5,7–9,9 → 5,3–9,9): štítek V archívu má v tmavém vzhledu 5,35 : 1 už
  od D101.

## Ověření

`check-contrast.py` (60 dvojic, 120 měření), `check-css-tokens.py`,
`check-css-units.py`, `npm run build`, `npm test` (303), `phpunit --filter
LedgerViewerTest` (unit 17, integrační 2) a celá sada (11 586 testů,
3 přeskočené) — v pořádku.

Chromium (Playwright, volný zdroj `zey0-g` s demo sadou IT servis, oba
vzhledy), snímky před / po a kontrast z vykreslených barev. Archivní
a smazaný záznam, štítky v hlavičce detailu a ve formuláři, pilulky
v buňce mřížky a karty feedu byly podvržené v odpovědi API (do dat se
nic nezapsalo). Hodnoty světlý / tmavý vzhled.

| Místo | Před | Po |
|-------|------|----|
| Proužek koše — seznam, mřížka, karta feedu | 2,56 / 5,83 | 4,76 / 5,83 |
| Proužek archivu — seznam, mřížka, karta feedu | 1,48 / 10,07 | 1,48 / 1,98 |
| Proužek koše pod kurzorem | 2,41 / 4,94 | 4,47 / 4,94 |
| Proužek archivu pod kurzorem | 1,39 / 8,53 | 1,39 / 1,67 |
| Proužek koše, vybraný řádek / pod kurzorem | 1,94 / 4,58 · 1,64 / 3,78 | 3,61 / 4,58 · 3,04 / 3,78 |
| Proužek archivu, vybraný řádek / pod kurzorem | 1,13 / 7,91 · 1,06 / 6,54 | 1,13 / 1,55 · 1,06 / 1,28 |
| Štítek Smazáno — text (detail, formulář, mřížka) | 6,10 / 5,35 | 7,56 / 6,10 |
| Štítek Smazáno — pilulka vůči pozadí | 1,24 / 1,10 | 7,56 / 1,98 |
| Štítek V archívu — text | 8,33 / 5,35 | beze změny |
| Štítek V archívu — pilulka vůči pozadí | 1,24 / 1,10 | beze změny |
| Saldo pohybů, sloupec Role (50 řádků) | 26 pilulek, 24 teček | 50× prostý text |

Štítek Smazáno vypadá stejně v hlavičce detailu (`Badge`), ve formuláři
(`FormStateBadge`) i v buňce mřížky (`SpanBadge`). V tmavém vzhledu je
proužek archivu dál vidět (1,98 : 1), na vybraném řádku skoro splývá
(1,55 / 1,28) — stejně jako ve světlém (1,13 / 1,06).

**V prohlížeči neověřeno:** skutečně archivovaný / smazaný záznam (jen
podvržený).

Ruční kontrola (Anna, dev server, oba vzhledy):

1. Osoby → záložka Vše s archivovanou a smazanou osobou pod sebou:
   proužek koše zřetelně tmavší (v tmavém vzhledu světlejší) než archivu;
   po výběru řádku i pod kurzorem.
2. Detail a formulář smazané osoby: štítek Smazáno plná tmavě šedá
   pilulka; detail archivní osoby: štítek V archívu světle šedý.
3. Dashboard: karta feedu archivního záznamu (pokud v datech je) —
   horní proužek tichý i v tmavém vzhledu.
4. Saldo pohybů v mřížce: sloupec Role prostým textem, bez pilulky
   a tečky; seznam (úzké okno) a detail pohybu beze změny.

### Ruční ověření (2026-11-17, Firefox, oba vzhledy)

- Osoby, záložka Vše s archivní a smazanou osobou pod sebou: proužek koše
  výraznější než archivu ve světlém i tmavém vzhledu, i na vybraném řádku
  a pod kurzorem — v pořádku.
- Štítek Smazáno (plná tmavě šedá pilulka) v detailu i formuláři, štítek
  V archívu světle šedý — v pořádku.
- Saldo pohybů: sloupec Role v mřížce prostým textem — v pořádku.
