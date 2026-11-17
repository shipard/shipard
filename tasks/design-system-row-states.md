# Design system — stavy a štítky v řádcích seznamů (D98–D103)

**Stav:** naplánováno — #153, D98–D103

## Kontext / Cíl

Druhý task #153 (první: `design-system-contrast.md`, opravy
`design-system-contrast-fixes.md`). Audit stavů a štítků v seznamech
(komentář v #153) našel:

- zhruba 25 seznamů vypisuje stav v řádku, kdykoli záznam není
  v Konceptu — u běžného záznamu všude zelené „V pořádku“; každý seznam
  má vlastní kopii mapy stav → třída spanu (`STATE_SPAN_CLASS`);
- zelený text v řádcích (stavy typu *done*, příznaky „výchozí“,
  „aktivní“…) a zelené pilulky („Zaúčtováno“) — klidný stav vyčnívá;
- tlumený text v řádku (`muted`, průhlednost) má 2,4–3,1 : 1;
- štítek Smazáno 3,90 / 2,81 : 1 (výjimka v `check-contrast.py`),
  smazaný řádek v sub-tabulce 2,56 : 1, tlumení archivních a smazaných
  řádků v seznamech nefunguje;
- nepoužívaná varianta štítku `accent` (3,92 : 1), rámeček vybrané volby
  v tmavém vzhledu 2,49 : 1, dva vlastní štítky mimo `Badge`.

**Cíl:** řádek seznamu ukazuje stav jen tam, kde nese informaci; klidné
stavy nevyčnívají; všechny texty a značky v řádcích splní prahy v obou
vzhledech bez výjimky.

**Mimo rozsah:**

- řádek pod kurzorem ve světlém vzhledu (`bg-hover`) — rozhodnuto nechat
  (#153);
- rámeček nevyplněných polí a zaškrtávátek — #156;
- stavy v hlavičce detailu (D20) a `Badge` mimo varianty `trash` a
  `accent` — beze změny.

## Návaznost

- **#153** — D98–D103 (komentář s auditem), D20, D88–D97
- `tasks/design-system-contrast.md` (D90, D91, `check-contrast.py`),
  `tasks/design-system-contrast-fixes.md` (D96, D97)

## Před implementací přečti

- `docs/design-system.md` §3 Stavové, §4 Doc-state systém (tabulka stavů,
  Při výběru řádku), §5 Badge systém
- `docs/frontend.md` → formát řádku vieweru (spany `t1`–`t3`, `class`,
  `badge`), `docs/viewer-grid.md` §3.4 (`SpanBadge`)
- `src/Core/Viewer/TableViewer.php` (sdílené texty, `DocStateConfig`)
- `modules/docs/core/src/DocsHeadsViewer.php` — `stateTag()` (vzor: Koncept
  a V pořádku se nevypisují)
- `frontend/src/components/viewer/ViewerRow.svelte`, `ViewerGrid.svelte`,
  `SpanBadge.svelte`, `form/FormSubTable.svelte`, `ui/Badge.svelte`,
  `form/FormStateBadge.svelte`, `styles/base.css` (`.docState_*`)

## Rozhodnutí (potvrzená v #153)

- ✓ **D98** Řádek seznamu nevypisuje stav Koncept (`stateStyle`
  `concept`) ani stav s `detailBadge: false` (V pořádku). Ostatní stavy se
  vypisují. Jedna sdílená metoda místo kopií mapy.
- ✓ **D99** Klidný tón v řádku (`success` — span i pilulka) = tečka
  `--shpd-color-success-mark` před textem, text barvou řádku. Ostatní tóny
  a pilulky beze změny.
- ✓ **D100** Tlumený text v řádku (`muted`) je barvou `text-secondary`
  bez průhlednosti.
- ✓ **D101** Štítek Smazáno tmavší šedou (`state-trash-text` světlý
  `#4b5563`, tmavý `#9ca3af`); archivní a smazaný řádek textem
  `text-secondary`; **přeškrtnutí smazaného záznamu zaniká všude**; token
  `state-archive-row` zaniká.
- ✓ **D102** Varianta `accent` v `Badge` a `SpanBadge` zaniká.
- ✓ **D103** Rámeček vybrané volby (`OptionRow`, `SegmentedControl`) bere
  `--shpd-color-primary-text`; výplň zaškrtnutého zaškrtávátka a radia
  zůstává `primary`.

## 1. Stav v řádku jednou metodou (D98)

`TableViewer` dostane chráněnou metodu (název navrhni, např.
`rowStateTag(int $docState): ?array`), která:

- z `$this->docStatesCfgItem` přes `DocStateConfig` vezme stav;
- vrátí `null` pro `stateStyle` `concept` a pro stav s `detailBadge: false`;
- jinak vrátí span `['text' => stateName, 'class' => …]` podle jedné
  sdílené mapy (`concept`/`edit` → `warning`, `confirmed` → `primary`,
  `done` → `success`, `cancelled` → `danger`, `archive`/`trash` →
  `muted`). Viewer smí mapu pro jeden styl přepsat (dnes `TasksViewer`:
  `edit` = Pozastaveno → `muted`) — chráněná konstanta nebo parametr,
  jak to vyjde čistěji.

Převeď všechny viewery s vlastní `STATE_SPAN_CLASS` (výčet z grepu:
Osoby, Schránky, Došlá pošta, Jednotky, Konverzace chatu, Bankovní účty,
Pokladny, Střediska, Fiskální roky, Platební terminály, Dopravy,
Registrace DPH, Sklady, Druhy položek, Položky, Účty, Bankovní výpisy,
Saldokontní účty, Saldokonto, Koeficienty odpočtu, Období DPH, Podání
DPH, Majetek, Zakázky, Číselné řady, Doklady, Úkoly, hosting: Mail
routery, Uživatelé, Zdroje dat, Servery). Podmínky `!== 10`, `!== 40`,
`!== DOC_STATE_FILED` a výjimka `showAllStates` v Osobách zaniknou —
pravidlo je jedno. Viewer se zvláštní logikou stavu (Došlá pošta —
analýza, Konverzace) převeď, pokud stav vypisuje stejným vzorem;
jinak zapiš do Odchylek.

Změna chování, kterou zapiš i do dokumentace: podání DPH ve stavu
Sestaveno (`concept`) a koeficienty / období DPH v Konceptu stav textem
nevypisují — nese ho proužek.

Test: unit test sdílené metody (concept → null, `detailBadge: false` →
null, ostatní styly → třída) a kontrola, že žádný viewer nemá vlastní
`STATE_SPAN_CLASS` (grep v testu, vzor `ViewerSourceTextsTest`).

## 2. Klidný tón jako tečka (D99)

Jen frontend — server posílá dál `class: success` / `badge: success`.

- `ViewerRow`, `ViewerGrid`, `FormSubTable`: span `--success` =
  tečka `success-mark` (`::before`, velikost jako tečky feedu) + text
  barvou řádku (`inherit`), mezera `--shpd-space-xs`.
- `SpanBadge` styl `success` se vykreslí stejně (tečka + text, bez
  pilulky). Ostatní styly pilulek beze změny.
- Vybraný řádek (D91): tečka zůstává a dostane obrys `1px`
  `text-on-fill` (D88, D96); text už má barvu běžného textu.
- Kontrast tečky: `bg` 3,30, `bg-hover` 3,10, `bg-secondary` 3,10
  (dvojice `success-mark` už hlídá `check-contrast.py`).

## 3. Tlumený text (D100)

`ViewerRow` (`__span--muted`), `ViewerGrid`, `FormSubTable` (dnes
`opacity: 0.7; font-style: italic` — kurzíva zůstává): barva
`--shpd-color-text-secondary`, žádná průhlednost. Zrušit i výjimku
`opacity: 0.8` pro vybraný řádek z D91 — na vybraném řádku má `muted`
barvu běžného textu jako ostatní šedé texty.

## 4. Koš a Archiv (D101)

- `variables.css`: `--shpd-color-state-trash-text` světlý `#4b5563`,
  tmavý `#9ca3af`; `--shpd-color-state-archive-row` smazat (ověř grepem,
  že ho nic dalšího nepoužívá).
- Přeškrtnutí pryč: `ui/Badge.svelte` (`--trash`), `viewer/SpanBadge.svelte`
  (`--trash`), `form/FormStateBadge.svelte` (`.docState_trash`),
  `styles/base.css` (`.docState_trash`) + komentáře u nich.
- `styles/base.css`: `.docState_archive` a `.docState_trash` — text
  `--shpd-color-text-secondary` (proužek beze změny). Tlumení musí platit
  ve **všech** místech, která třídu nesou: `ViewerRow` (dnes přebíjí
  vlastní `color` řádku), buňky `ViewerGrid` (barvu nedědí),
  `FormSubTable`, `FeedCard`. Na vybraném řádku platí D91 (běžný text).
- `scripts/check-contrast.py`: výjimku Koše z `EXCEPTIONS` odstranit
  (dvojice projde 6,10 / 5,35).

## 5. `accent`, rámeček výběru, dva štítky (D102, D103, D90)

- D102: `accent` z povolených stylů `Badge` (`STYLES`) a `SpanBadge`
  a jejich CSS; tokeny `accent-*` zůstávají (barva značky).
- D103: `ui/OptionRow.svelte` (rámeček vybraného řádku) a
  `ui/SegmentedControl.svelte` (rámeček vybraného segmentu) —
  `border-color: var(--shpd-color-primary-text)`; komentáře v hlavičkách
  komponent upravit. Výplně (`background: primary`) beze změny. Dvojici
  `primary-text` × `primary-soft` už hlídá `check-contrast.py`.
- D90: `registry/RegistryImportWizard.svelte` (`warning-text` na
  `warning-soft`) a `account/SessionsPanel.svelte` (`success-text` na
  `success-soft`) na `Badge` `warning` / `success`. Pokud tím `-soft`
  tokeny přestanou být použité, zapiš to do Odchylek (nemazat).

## 6. Dokumentace

- `docs/design-system.md` §4: tabulka stavů (trash bez přeškrtnutí, text
  `text-secondary`), nový odstavec **Stav v řádku seznamu** (D98, D99,
  D100), Při výběru řádku (tečka s obrysem, `muted`); §5: bez `accent`,
  `SpanBadge` `success` = tečka; §8 Volitelné řádky / Segmentový přepínač:
  rámeček výběru `primary-text` (D103); §3: výjimka Koše pryč.
- `docs/frontend.md` (formát řádku) a `docs/viewer-grid.md` §3.4 —
  význam `success` a `muted` ve spanu, styly `SpanBadge`.
- `CLAUDE.md` → Viewery: jedna věta o sdílené metodě stavu v řádku (D98).

## Commity

1. `refactor(viewers): stav v řádku jednou sdílenou metodou, bez Konceptu a V pořádku (#153, D98)` — krok 1
2. `fix(frontend): klidný tón v řádku tečkou, tlumený text bez průhlednosti (#153, D99, D100)` — kroky 2, 3
3. `fix(frontend): Koš a Archiv bez přeškrtnutí, tlumené řádky šedým textem (#153, D101)` — krok 4
4. `refactor(frontend): bez štítku accent, rámeček výběru primary-text, štítky přes Badge (#153, D90, D102, D103)` — krok 5
5. `docs: stavy a štítky v řádcích seznamů (#153, D98–D103)` — krok 6 + `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `php -l`, `vendor/bin/phpunit --filter` na nové testy a dotčené
  viewery (testy viewerů, `ViewerDetailContractTest`, `ViewerSourceTextsTest`),
  pak celá sada; `check-contrast.py` (bez výjimky Koše),
  `check-css-tokens.py`, `check-css-units.py`, `npm run build`, `npm test`.
- Chromium (Playwright, zdroj dat `free` s demo sadou IT servis, oba
  vzhledy): snímky před / po a kontrast vykreslených barev —
  Osoby (bez „V pořádku“; archivní a smazaná osoba šedě, bez přeškrtnutí),
  Bankovní výpisy a Bankovní transakce (tečka místo zeleného textu
  a pilulky), Jednotky („základní“), Úkoly, číselník v Nastavení, sub-
  tabulka formuláře se smazaným řádkem, hlavička detailu smazaného
  záznamu (štítek Smazáno), vybraný řádek s tečkou i pod kurzorem,
  volitelný řádek a segment ve výběru v tmavém vzhledu, průvodce importem
  Spisovny, Zabezpečení. Smazaný / archivní záznam podvrhni, pokud v sadě
  není. Tabulka do Ověření.

## Hotovo když

- [ ] Jedna metoda stavu v řádku, žádná kopie `STATE_SPAN_CLASS`, test
- [ ] Koncept a V pořádku se v řádku nevypisují (D98)
- [ ] `success` v řádku a `SpanBadge` = tečka + text (D99)
- [ ] `muted` barvou `text-secondary` (D100)
- [ ] Koš bez přeškrtnutí, tmavší štítek, archiv a koš šedým textem ve
  všech místech, výjimka z `check-contrast.py` pryč (D101)
- [ ] Bez `accent`, rámeček výběru `primary-text`, dva štítky na `Badge`
  (D90, D102, D103)
- [ ] Kontrast vykreslených barev v obou vzhledech (Ověření)
- [ ] Dokumentace, `CLAUDE.md`, `**Stav:**`, index tasků

## Pasti

- `DocsHeadsViewer::stateTag()` už pravidlo z D98 má (natvrdo 10 a 40) —
  nahradit sdílenou metodou, ne nechat dvě.
- `stateStyle` řádku (proužek) se nemění — mění se jen text stavu v `t2`.
- Odstraněním stavu může zůstat `t2` prázdné — vrátit `null`, ne `[]`
  (vzor v `PersonsViewer`).
- Testy, e2e (`tests/e2e/`) nebo scénáře videí (`demo/scenarios/`) mohou
  hledat text „V pořádku“ v seznamu nebo `line-through` — grep.
- `ViewerRow` nastavuje `color` řádku ve scoped stylu se specifičností
  vyšší než globální `.docState_*` — proto dnes tlumení nefunguje.
- MCP nástroje (`*/src/Mcp/*SearchTool.php`) používají `stateName` pro
  výstup AI — neměnit.

## Odchylky od zadání

(vyplní implementace)

## Ověření

(vyplní implementace)
