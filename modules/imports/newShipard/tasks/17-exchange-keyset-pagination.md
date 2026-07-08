# 17 — BaseExchangeRunner: dávkové čtení zdrojových řádků (keyset) — OOM u banky a osob

## Kontext

Import bankovních výpisů padá na velkých datech na stejný OOM jako pošta
(task 16); osoby mají identický vzor a padat začnou s většími instancemi
(D10 z tasku 16 přestalo být hypotetické).

Klíčové zjištění z průchodu kódem: neomezený `fetchAll()` **není
v jednotlivých runnerech**, ale ve sdíleném
`BaseExchangeRunner::fetchSourceRows()` (~ř. 144), který volá base `run()`
(~ř. 45) a per-chunk i `DocsRunner::run()` (~ř. 235). Oprava se proto dělá
v base třídě — pokryje `PersonsRunner`, `BankStatementsRunner`,
`ItemsRunner` i `DocsRunner` (uvnitř měsíčních chunků) najednou.

Princip stejný jako v tasku 16: mysqlnd bufferuje result set z PHP memory
limitu, iterace přes `Dibi\Result` nepomůže — musí se omezit velikost
samotného dotazu (keyset: `WHERE alias.[ndx] > kurzor ORDER BY alias.[ndx]
LIMIT batch`).

Per-záznamové dotazy (personsBA/contacts/properties per osoba, řádky výpisu
`WHERE r.[document] = %i` per výpis) jsou bounded — beze změny.

## Návaznost

- **Task 16** (`16-mail-keyset-pagination.md`) — stejný vzor a **stejný
  `--batch` argument** (jednotné UX; `MailRunner` nedědí
  z `BaseExchangeRunner`, řeší se odděleně). Tasky jsou na sobě nezávislé,
  lze implementovat v jedné session.
- `processRows()` v base je už dnes navržený pro opakované volání (docs
  chunky) — dávková smyčka ho volá per dávka, signatura se nemění.
- Idempotence přes idMap v `processOneRow()` — beze změny, dávkování na ni
  nemá vliv.
- `BaseCodebookRunner` (~ř. 103) má také `fetchAll()` — číselníky jsou malé,
  mimo rozsah (viz Otevřené body).

## Před implementací přečti

- `libs/BaseExchangeRunner.php` — celý; hlavně `run()` (~ř. 45),
  `processRows()` (~ř. 82), `fetchSourceRows()` (~ř. 144)
- `sourceQuery()` všech čtyř runnerů: `PersonsRunner` (~ř. 38, alias `p`),
  `ItemsRunner` (~ř. 69, alias `i`, **má JOIN** — kurzor musí být
  alias-qualified), `BankStatementsRunner` (~ř. 64, alias `h`),
  `DocsRunner` (~ř. 338, alias `h`, chunkFrom/chunkTo)
- `DocsRunner::run()` (~ř. 203) — chunk smyčka, `--limit` přes
  `array_sum($stats)`, `unset($rows)`
- `tasks/16-mail-keyset-pagination.md` — vzor a sdílené pasti

## Scope

**V rozsahu:** dávkové čtení + COUNT + kurzorová smyčka
v `BaseExchangeRunner`, změna kontraktu `sourceQuery()` (bez ORDER BY) +
nová `sourceAlias()` ve všech 4 runnerech, úprava `DocsRunner::run()`
(dávky uvnitř chunků), `--batch` v help textu.

**Mimo rozsah:**

- `MailRunner` — task 16.
- `BaseCodebookRunner` — malé číselníky.
- Změna chunkování docs (`--chunk-months` zůstává, viz D14).
- Rozšíření `tick()` o celkový počet (otevřený bod tasku 14).

## Co implementovat

1. **Kontrakt `sourceQuery()`:** nově vrací dotaz **bez `ORDER BY`** —
   končí ve WHERE klauzuli. Pořadí, kurzor a LIMIT skládá base. Aktualizovat
   docblock abstract metody + odstranit závěrečný `' ORDER BY x.[ndx]'`
   prvek ze všech 4 implementací.

2. **Nová abstract metoda `sourceAlias(): string`** — alias hlavní tabulky
   pro kurzor a ORDER BY (`p` persons, `i` items, `h` bank statements
   i docs).

3. **`fetchSourceRowsBatch(int $afterNdx, int $batchSize): array`** v base —
   `sourceQuery()` + ` AND {alias}.[ndx] > %i` + ` ORDER BY {alias}.[ndx]`
   + ` LIMIT %i`. Normalizace řádků (`toArray()`) jako dnes. Původní
   `fetchSourceRows()` odstranit (jediný volající mimo base `run()` je
   `DocsRunner`, který se upraví — bod 6).

4. **`countSourceRows(): int`** v base — obal
   `SELECT COUNT(*) FROM ( …sourceQuery()… ) tmp` složený merge-em dibi
   array formy (garantuje identické WHERE bez duplikace; derived table
   **musí mít alias**). Použití: `Found N source rows.` v base `run()`
   a per-chunk log v `DocsRunner` (COUNT respektuje chunkFrom/chunkTo).

5. **Sdílená kurzorová smyčka** — vyčlenit do base metody (např.
   `processAllRows(ExchangeClient $exchange, array &$stats, int $limit): bool`),
   kterou volá base `run()` i `DocsRunner` per chunk, ať logika kurzoru
   a limitu není dvakrát:
   - `$afterNdx = 0`; opakovaně `fetchSourceRowsBatch($afterNdx, $batch)`;
     prázdná dávka → konec;
   - **kurzor = `ndx` posledního *načteného* řádku dávky** (i failed /
     skipped — jinak nekonečná smyčka s `--continue-on-error`);
   - `--limit N`: před `processRows()` oříznout dávku na zbývající počet
     (`$remaining = $limit - array_sum($stats)` — vzor z dnešního
     `DocsRunner::run()`; každý zpracovaný řádek inkrementuje právě jeden
     status, sémantika limitu se nemění), po vyčerpání konec;
   - `processRows($batch, $exchange, $stats)` per dávka.
   - Pozn.: u docs je `$stats` sdílený přes chunky (kumulativní) —
     `$remaining` přes `array_sum($stats)` proto funguje správně i napříč
     chunky, jako dnes.

6. **`DocsRunner::run()`** — uvnitř chunk smyčky nahradit jediné
   `fetchSourceRows()` + slice voláním sdílené smyčky z bodu 5 (kurzor se
   resetuje per chunk; chunkFrom/chunkTo dál tečou přes `sourceQuery()`).
   Per-chunk info log `— chunk {from} … {to}: N docs` vezme N
   z `countSourceRows()`.

7. **`--batch`** — stejný argument jako task 16 (default 500,
   `max(1, (int) …)`); čte se v base. Help text v `ImportApp`: popis
   rozšířit na exchange runnery + mail (pokud už task 16 řádek přidal,
   jen ho zobecnit).

## Hotovo když

- `bank-statements` a `persons` na DS, kde dnes padají, doběhnou
  s `memory_limit = 128M`; špička paměti neroste s počtem řádků.
- `items` a `docs` dávají na referenčním DS **identické `Done` statistiky**
  jako před změnou; `--chunk-months` funguje beze změny; per-chunk logy
  ukazují správné počty.
- `--limit 750` s `--batch 500` zpracuje přesně 750 řádků; u docs funguje
  limit i přes hranici chunku (jako dnes).
- `--batch 100` vs. default → identické statistiky (persons, referenční DS).
- `--from/--to` u bank statements a docs beze změny; `--dry-run` beze změny.
- Re-run idempotence (idMap skip) beze změny.
- `php -l` na všech dotčených souborech.

## Commit

Jeden commit (jeden logický celek — kontrakt base + adaptace runnerů
nelze oddělit):
`fix(imports/newShipard): exchange runnery čtou zdrojové řádky po dávkách (keyset) — OOM na velkých datech`

## Rozhodnutí ✓

- **D11:** Oprava v `BaseExchangeRunner`, ne per-runner — jedna
  implementace pokryje persons, bank-statements, items i docs. ✓
- **D12:** Kontrakt: `sourceQuery()` bez ORDER BY + nová `sourceAlias()`;
  kurzor/ORDER BY/LIMIT skládá výhradně base. ✓
- **D13:** COUNT přes derived-table obal `sourceQuery()` — žádná duplikace
  WHERE podmínek, nemůže se rozjet. ✓
- **D14:** `DocsRunner` si **ponechává** měsíční chunky (sémantika
  per-chunk logů, `--chunk-months` UX); uvnitř chunku se čte po dávkách.
  Kurzorová smyčka je sdílená v base (bod 5). ✓
- **D15:** `--batch` sdílený s taskem 16, default 500. ✓

## Otevřené body

- `BaseCodebookRunner::fetchAll()` — číselníky (units, accounts, …) jsou
  řádově stovky záznamů; předělat stejně jen kdyby se objevil obří číselník.
- `tick()` s celkovým počtem — COUNT je nově k dispozici i pro exchange
  runnery (viz otevřený bod tasku 14 a 16).
