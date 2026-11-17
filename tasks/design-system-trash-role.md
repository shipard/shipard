# Design system — Koš výraznější než Archiv, role v Saldu pohybů textem (D104, D105)

**Stav:** naplánováno — #153, D104, D105

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

- [ ] Tokeny podle tabulky, štítek Smazáno plná pilulka, koš výraznější
  než archiv ve světlém i tmavém vzhledu (D104)
- [ ] Role v mřížce Salda pohybů textem, testy (D105)
- [ ] Dokumentace, `**Stav:**`, index tasků

## Pasti

- Tmavý blok `variables.css` proužky dosud nepřepisuje — nový override
  patří k ostatním stavovým tokenům tmavého bloku.
- Štítek Smazáno v mřížce (`SpanBadge` `trash`) a v hlavičce (`Badge`,
  `FormStateBadge`) musí vypadat stejně.

## Odchylky od zadání

(vyplní implementace)

## Ověření

(vyplní implementace)
