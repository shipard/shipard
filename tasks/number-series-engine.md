# Společný engine čísel — vytažení z číselných řad dokladů

**Stav:** naplánováno — #110 D17; N1–N3 potvrzené 2026-10-08

> PRD pro jednu Claude Code session (3 commity). Design:
> `docs/work-orders.md` §4 (D17), §5.7; issue #110. Předpoklad pro
> `tasks/work-orders-phase1.md` (číselné řady zakázek). Na
> `tasks/dimensions-core.md` nezávisí.

## Kontext

Číslování dokladů žije celé v `DocDocument`: vyhodnocení vzorce
(`resolvePattern`, popisek roku), přidělení pořadí z čítače
(`assignDocumentNumber`), synchronizace čítače při importu
(`applyImportNumber`), uvolnění čísla při návratu do Konceptu
(`releaseDocumentNumber`, `filterStateTransitions`). Validace vzorce je
v `NumberSeriesDocument`. Zakázky (D17) potřebují totéž — vzorec, kód
řady, restart po roce nebo průběžně, čítače — nad vlastní tabulkou řad
a čítačů.

Tenhle task vytáhne doménově nezávislé jádro do `src/Core/Numbering/`
a doklady na něj přepojí. **Beze změny chování, SQL dotazů a schématu** —
je to refaktoring, který jen připraví druhého uživatele.

## Cíl

1. `NumberPattern` — validace a vyhodnocení vzorce čísla.
2. `SequenceCounter` — přidělení, synchronizace importu a uvolnění pořadí
   nad parametrizovanou tabulkou čítačů.
3. `DocDocument` a `NumberSeriesDocument` přepojené na jádro; existující
   testy číslování projdou beze změny asercí.

## Před implementací přečti

- `docs/work-orders.md` §4 D17, §5.7
- `modules/docs/core/tables/docs_core_number_series.md` (Vzorec čísla
  dokladu, Validace) a `docs_core_number_counters.md` (algoritmus
  přidělení, NULL a UNIQUE)
- `modules/docs/core/src/DocDocument.php`: `processStateTransition`,
  `assignDocumentNumber`, `applyImportNumber`,
  `numberSeriesResetScope`, `releaseDocumentNumber`,
  `filterStateTransitions`, `resolvePattern`, `getFiscalYearLabel`,
  `resolveFiscalYearId`
- `modules/docs/core/src/NumberSeriesDocument.php` (`KNOWN_PLACEHOLDERS`,
  `validate`)
- `src/Core/Document/Document.php` — `$externalTransaction` (uvnitř
  transakce Applieru se nesmí otevírat vlastní)
- `tasks/doc-number-release-on-data-save.md` — pojistka proti falešnému
  uvolnění čísla při uložení dat; nesmí se rozbít
- `modules/economy/codebooks/src/FiscalMonthLookup.php` (vzor pomocné
  třídy nad fiskálními roky)
- testy `tests/Unit/Module/Docs/Core/DocDocumentNumberingTest.php`,
  `DocDocumentImportNumberTest.php`, `NumberSeriesDocumentTest.php`
- `docs/architecture.md` (mapa `src/Core/`)

## Scope

**Uvnitř:** vše v Cíli, testy jádra, dokumentace.

**Mimo:** číselné řady zakázek, jejich tabulky a UI
(`work-orders-phase1`); jakákoli změna schématu nebo dat čítačů; nové
placeholdery; inventární čísla majetku (`AssetNumberAllocator` — jiný
model: prefix druhu + pořadí bez řady a čítače); změny UI číselných řad
dokladů.

## 1. Vzorec — `Shipard\Core\Numbering\NumberPattern`

- **Obecné placeholdery:** `%C` (kód řady), `%y` / `%Y` (popisek roku
  2- a 4-místně), `%3`–`%6` (pořadí doplněné nulami).
- **Doménové placeholdery** dodává volající jako mapu znak → hodnota;
  doklady: `%D` = `doc_id_code` typu dokladu. Validace dostane seznam
  povolených doménových znaků.
- `validate(...)`: prázdný vzorec, neznámý placeholder, `%C` bez kódu
  řady — **kódy chyb a texty beze změny** (`NumberSeriesDocumentTest`).
- `resolve(...)` nad hodnotovým objektem kontextu (pořadí, kód řady,
  popisek roku, doménové hodnoty). Výstup totožný s dnešním
  `resolvePattern` včetně chování u neznámého placeholderu.
- Popisek roku a id fiskálního roku určuje volající (fiskální roky jsou
  `economy.codebooks`, jádro o nich neví). Logiku z `DocDocument`
  (`resolveFiscalYearId`, `getFiscalYearLabel`) přesuň do pomocné třídy
  v `economy.codebooks` vedle `FiscalMonthLookup` — zakázky ji použijí
  stejně. Chování (prefix roku, fallback na rok účetního data / aktuální
  rok) beze změny.

## 2. Čítač — `Shipard\Core\Numbering\SequenceCounter`

- Parametrizovaný popisem tabulky čítačů (tabulka, sloupec řady, sloupec
  rozsahu, sloupec hodnoty) a tabulky záznamů (tabulka, sloupec řady,
  rozsahu a pořadí). Doklady: `docs_core_number_counters`
  (`number_series`, `fiscal_year`, `last_assigned`) a `docs_core_heads`
  (`number_series`, `fiscal_year`, `sequence_number`).
- `next(series, scope, ownTransaction)` — `INSERT IGNORE` řádku čítače,
  `SELECT … FOR UPDATE`, `UPDATE`; rozsah NULL-safe (`<=>`). Bez vlastní
  transakce, pokud ji volající nesmí otevřít (`externalTransaction`).
- `syncImported(series, scope, sequence)` — `GREATEST`, idempotentní
  a nezávislé na pořadí importu.
- `maxSequence(series, scope)` — nejvyšší pořadí v tabulce záznamů (guard
  „poslední v řadě“ pro uvolnění i pro nabídku přechodu).
- `release(series, scope, sequence, ownTransaction)` — snížení čítače
  jen když `last_assigned = sequence`.
- **SQL zůstává stejné** jako dnes (stejné příkazy, stejné pořadí), jen
  s názvy tabulek a sloupců z popisu.
- Převod `reset_scope` (`none` / `fiscal_year`) na rozsah (NULL / id
  fiskálního roku) zůstává v doménové vrstvě — jádro zná jen rozsah.

## 3. Přepojení dokladů

- `DocDocument`: `assignDocumentNumber`, `applyImportNumber`,
  `releaseDocumentNumber`, `filterStateTransitions` a `resolvePattern`
  delegují na jádro. Chráněné metody, které používají testy přes
  `*Pub` wrappery, zůstanou jako tenké delegace — testy se nemění, jen
  pokud to jinak nejde (pak jen mechanicky, aserce stejné).
- `NumberSeriesDocument::validate` použije `NumberPattern`.
- Číslo při `0 / 10 → 40`, uvolnění při `80 → 10`, `!`-placeholder
  čísla po uvolnění a mazání snapshotů zůstávají v `DocDocument`
  (doménové chování dokladu).

## Testy

- Nové `tests/Unit/Core/Numbering/NumberPatternTest.php`
  (placeholdery, doménové hodnoty, validace) a `SequenceCounterTest.php`
  (sled SQL nad mock DB po vzoru `DocDocumentNumberingTest`: vlastní
  a vnější transakce, rozsah NULL, `GREATEST`, guard uvolnění).
- Beze změny asercí projdou: `DocDocumentNumberingTest`,
  `DocDocumentImportNumberTest`, `NumberSeriesDocumentTest`,
  `NumberSeriesProvisionerTest`, `BoundNumberSeriesProvisionerTest`
  a testy z `doc-number-release-on-data-save.md`.
- `vendor/bin/phpunit --filter 'Numbering|NumberSeries|DocDocument'`, pak
  celá sada.

## Dokumentace

- `docs/architecture.md` — `src/Core/Numbering/` v mapě.
- `CLAUDE.md` — strom `src/Core/` v rychlém přehledu architektury.
- `docs_core_number_series.md` a `docs_core_number_counters.md` — kde
  žije kód (algoritmus beze změny).
- `docs/work-orders.md` §7 — stav řádku tasku.

## Task breakdown

1. **Vzorec** — `NumberPattern` + pomocná třída fiskálního roku v
   `economy.codebooks`, přepojení `resolvePattern` a
   `NumberSeriesDocument::validate` + testy.
2. **Čítač** — `SequenceCounter`, přepojení přidělení, importu,
   uvolnění a nabídky přechodu + testy.
3. **Dokumentace a stav** — dokumentace výše, Stav tasku +
   `python3 scripts/tasks-index.py`.

## Hotovo když

- `ds-upgrade` nehlásí žádnou změnu schématu.
- Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný): potvrzení
  faktury vydané přidělí číslo navazující na předchozí; návrat V opravě
  → Koncept u posledního dokladu číslo uvolní a další potvrzení ho použije
  znovu; neposlednímu dokladu se přechod do Konceptu nenabídne; uložení
  dat dokladu V pořádku číslo nemění; `dataset-seed` s doklady posune
  čítače tak, že další nový doklad pokračuje za importovaným.
- Řada s neznámým placeholderem nejde uložit se stejnou hláškou jako dřív.
- Celá sada PHPUnit zelená.

## Rozhodnutí k designu (potvrzená)

- ✓ **N1 — Jádro v `src/Core/Numbering/`**, ne v modulu: číslování
  potřebují doklady i zakázky a případně další evidence.
- ✓ **N2 — Čítače zůstávají per doména** (`docs_core_number_counters`;
  zakázky dostanou vlastní tabulku), engine je parametrizovaný. Sdílená
  tabulka čítačů by znamenala migraci dat čítačů bez užitku.
- ✓ **N3 — Inventární čísla majetku zůstávají mimo** — jiný model (prefix
  druhu + pořadí, bez řady a čítače); sjednocení případně později.
