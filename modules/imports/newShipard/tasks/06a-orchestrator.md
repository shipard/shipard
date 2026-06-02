# Task: Orchestrátor `all` + logování + statistiky + reset + chunkování dokladů (Fáze 06a)

## Kontext

Import ze starého Shipardu má hotové všechny dílčí fáze (číselníky, osoby,
položky, doklady) jako samostatné subkomandy. Chybí zastřešení pro produkční
běh:

1. **Orchestrátor `all`** — spustit celý řetězec jedním příkazem
   (číselníky → osoby → položky → doklady), v závislostně správném pořadí.
2. **Logování do souboru** — dnes jde výstup jen na konzoli (`echo`).
   Produkční migrace potřebuje perzistentní log běhu k pozdější kontrole.
3. **Souhrnné statistiky** — každý runner vypíše vlastní `Done …` řádek, ale
   chybí agregovaný souhrn na konci celého běhu.
4. **`reset`** — smazat lokální SQLite mapu (`import-newShipard.sqlite`) před
   startem, aby se čistý re-import nemusel dělat ručně.
5. **Chunkování dokladů** — `fetchSourceRows()` dnes dělá `fetchAll()` (vše
   do paměti najednou). U desítek tisíc dokladů (každý s řádky + lookupy
   partnera) PHP padá na `memory_limit`. Doklady je potřeba importovat po
   menších časových úsecích.

## Rozsah

Vše ve starém Shipardu, `modules/imports/newShipard/`:

- **A) Logger** — tee výstup konzole + soubor.
- **B) `ImportStats`** — sdílený akumulátor statistik napříč fázemi.
- **C) `AllRunner`** — orchestrátor `all`.
- **D) `reset`** — flag `--reset` + subkomand `reset`.
- **E) Chunkování dokladů** — refaktor `BaseExchangeRunner` (extrakce
  `processRows`) + časové úseky v `DocsRunner`.
- **F) CLI dispatch + usage** — zapojit `all` a `reset`.

**Mimo rozsah** (navazující task 06b): `--force-reimport=<entity>` (smazání
mappingu jen pro jednu entitu bez smazání celé mapy).

**Bez chunkování:** osoby a položky zůstávají na `fetchAll()` — jejich objem
je řádově menší a nemají těžké sub-dotazy jako doklady. Pokud i tam vznikne
paměťový problém, řeší se stejným vzorem později (viz Otevřené body 4).

## Před implementací přečti

- **`libs/ImportApp.php`** — `run()` (vytváří `LocalIdMap`, dispatch),
  `dispatch()` (switch subkomand), `printUsage()`. Sem patří `all`, `reset`,
  `--reset`, vytvoření Loggeru.
- **`libs/ImportContext.php`** — readonly DTO. Přidat `logger` + `stats`.
- **`libs/ImportRunner.php`** — output helpery (`info`/`ok`/`warn`/`err`/
  `debug`). Přesměrovat přes Logger.
- **`libs/BaseExchangeRunner.php`** — `run()` + `processOneRow()`. Extrahovat
  `processRows()`; zapojit `stats`.
- **`libs/runners/AllCodebooksRunner.php`** — vzor orchestrátoru (sekvence
  runnerů, agregace OK/error).
- **`libs/runners/DocsRunner.php`** — `sourceQuery()`, `run()` (z
  `BaseExchangeRunner`), `finalDocState`. Sem časové chunkování.
- **`libs/LocalIdMap.php`** — cesta sqlite (`path()`), `forgetAll()`.

## Co implementovat

### A) Logger — tee konzole + soubor

Nová třída `libs/Logger.php`:

```php
<?php
namespace imports\newShipard\libs;

final class Logger
{
    private $handle = null;   // resource|null

    public function __construct(private readonly ?string $filePath)
    {
        if ($filePath !== null)
        {
            @mkdir(dirname($filePath), 0700, true);
            $this->handle = @fopen($filePath, 'ab');
            if ($this->handle === false)
                $this->handle = null;   // log soubor je best-effort, nikdy neblokuje běh
        }
    }

    /** Echo na konzoli + (volitelně) řádek do souboru s časovou značkou. */
    public function line(string $text): void
    {
        echo $text . "\n";
        if ($this->handle !== null)
            fwrite($this->handle, '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n");
    }

    /** Víceřádkový blok (dump payloadů) — bez per-řádek časové značky. */
    public function block(string $text): void
    {
        echo $text . "\n";
        if ($this->handle !== null)
            fwrite($this->handle, $text . "\n");
    }

    public function path(): ?string { return $this->filePath; }

    public function close(): void
    {
        if ($this->handle !== null)
        {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
```

**Cesta log souboru:** `log/import-YYYYMMDD-HHMMSS.log` v rootu DS
(`__APP_DIR__ . '/log/import-' . date('Ymd-His') . '.log'`). Jeden soubor na
běh — snadno se páruje s konkrétním spuštěním. Adresář `log/` se vytvoří
automaticky (0700).

**Zapojení v `ImportRunner`:** output helpery místo přímého `echo` volají
Logger z kontextu:

```php
protected function logger(): Logger { return $this->context->logger; }

protected function info(string $msg): void { $this->logger()->line($msg); }
protected function ok(string $msg): void   { $this->logger()->line("✓ " . $msg); }
protected function warn(string $msg): void { $this->logger()->line("! " . $msg); }
protected function err(string $msg): void  { $this->logger()->line("✗ " . $msg); }
protected function debug(string $msg): void
{
    if ($this->isVerbose())
        $this->logger()->line("[debug] " . $msg);
}
```

`BaseExchangeRunner::dumpJson()` přepsat na `$this->logger()->block(...)` (ať
jdou dumpnuté payloady taky do logu).

### B) `ImportStats` — sdílený akumulátor

Nová třída `libs/ImportStats.php`:

```php
<?php
namespace imports\newShipard\libs;

final class ImportStats
{
    /** @var array<string, array{created:int,updated:int,skipped:int,failed:int}> */
    private array $byEntity = [];

    public function add(string $entity, string $status): void
    {
        if (!isset($this->byEntity[$entity]))
            $this->byEntity[$entity] = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        if (isset($this->byEntity[$entity][$status]))
            $this->byEntity[$entity][$status]++;
    }

    /** @return array<string, array{created:int,updated:int,skipped:int,failed:int}> */
    public function byEntity(): array { return $this->byEntity; }

    public function totalFailed(): int
    {
        $n = 0;
        foreach ($this->byEntity as $s) $n += $s['failed'];
        return $n;
    }
}
```

Sdílený přes `ImportContext`. `BaseExchangeRunner` (i `BaseCodebookRunner`)
po každém řádku zavolá `$this->context->stats->add($this->entityLabel(),
$result['status'])` — **vedle** stávajícího lokálního `$stats` počítadla (to
zůstává pro per-runner `Done …` řádek). Orchestrátor pak souhrn čte z
`context->stats`.

### C) `AllRunner` — orchestrátor

Nový `libs/runners/AllRunner.php` (vzor `AllCodebooksRunner`):

```php
final class AllRunner extends ImportRunner
{
    public function run(): bool
    {
        $continueOnError = (bool) $this->app()->arg('continue-on-error');
        $allOk = true;

        // Pořadí závislé: číselníky (units/series/…) → osoby → položky →
        // doklady (potřebují osoby jako partnery, položky na řádcích,
        // číselníky pro number_series/vat).
        $phases = [
            ['All codebooks', fn() => (new AllCodebooksRunner($this->context))->run()],
            ['Persons',       fn() => (new PersonsRunner($this->context))->run()],
            ['Items',         fn() => (new ItemsRunner($this->context))->run()],
            ['Documents',     fn() => (new DocsRunner($this->context))->run()],
        ];

        foreach ($phases as [$label, $fn])
        {
            $this->info("");
            $this->info("######## {$label} ########");
            $ok = $fn();
            if (!$ok)
            {
                $allOk = false;
                if (!$continueOnError)
                {
                    $this->err("Aborting `all` at phase '{$label}' (use --continue-on-error to keep going).");
                    $this->printSummary();
                    return false;
                }
            }
        }

        $this->printSummary();
        if ($allOk)
            $this->ok("Full import finished.");
        else
            $this->warn("Full import finished with errors — see log above.");
        return $allOk;
    }

    private function printSummary(): void
    {
        $this->info("");
        $this->info("==== Souhrn ====");
        foreach ($this->context->stats->byEntity() as $entity => $s)
            $this->info(sprintf("  %-16s created=%d updated=%d skipped=%d failed=%d",
                $entity, $s['created'], $s['updated'], $s['skipped'], $s['failed']));
    }
}
```

**`--from`/`--to` přes `all`:** žádná speciální logika není potřeba.
Argumenty jsou globální na `$this->app()`; `DocsRunner::sourceQuery()` je čte
sám, ostatní fáze je ignorují. Takže `all --from=2024-01-01 --to=2024-12-31`
naimportuje **všechny** číselníky/osoby/položky, ale **jen doklady daného
období**. Pouze zdokumentovat v usage.

### D) `reset`

Smazání lokální SQLite mapy před startem. Dva vstupy:

- **subkomand `reset`** — smaže mapu a skončí (nic neimportuje).
- **flag `--reset`** — smaže mapu a pokračuje zvoleným subkomandem (čistá
  mapa pro daný běh, typicky `all --reset`).

Implementace v `ImportApp::run()` **před** `new LocalIdMap(...)`:

```php
$sqlitePath = __APP_DIR__ . '/import-newShipard.sqlite';

$wantsReset = ($subcommand === 'reset') || (bool) $this->app->arg('reset');
if ($wantsReset)
{
    foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm'] as $f)
        if (is_file($f)) @unlink($f);
    echo "! Local id map reset (deleted {$sqlitePath}).\n";
    echo "! POZOR: idempotence je pryč — re-import do neprázdného cílového DS\n";
    echo "!        může vytvořit duplikáty (business-key match nemusí vše zachytit).\n";

    if ($subcommand === 'reset')
        return true;   // jen reset, nic dál
}

$this->idMap = new LocalIdMap($sqlitePath);   // čerstvá mapa
```

Smazání WAL/SHM souborů je nutné (SQLite v WAL módu) — jinak by zůstaly
zbytky. Warning o duplikátech je důležitý: `reset` je bezpečný jen při
současném vyčištění cílového DS (čistý re-test); jinak hrozí duplicitní
osoby/položky/doklady, protože LocalIdMap skip je hlavní idempotenční
mechanismus a business-key match (IČO/číslo) nepokrývá vše (FO bez IČO).

### E) Chunkování dokladů

#### E.1 Refaktor `BaseExchangeRunner` — extrakce `processRows`

Z `run()` vyjmout per-řádkový loop do chráněné metody, aby ho `DocsRunner`
mohl volat opakovaně (po úsecích) se sdíleným `$stats`:

```php
public function run(): bool
{
    $this->info("Importing {$this->entityLabel()} via exchange flow...");
    $rows = $this->fetchSourceRows();

    $limit = (int) ($this->app()->arg('limit') ?? 0);
    if ($limit > 0)
    {
        $rows = array_slice($rows, 0, $limit);
        $this->info("Limit applied: processing first {$limit} rows.");
    }
    $this->info("Found " . count($rows) . " source rows.");

    $exchange = new ExchangeClient($this->http());
    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

    if (!$this->processRows($rows, $exchange, $stats))
        return false;   // abort (failed + !continueOnError)

    $this->printDone($stats);
    return $stats['failed'] === 0;
}

/**
 * Zpracuje dávku řádků. Vrátí false jen při abortu (failed bez
 * --continue-on-error). Aktualizuje $stats (lokální) i context->stats.
 *
 * @param array<int, array<string,mixed>> $rows
 * @param array{created:int,updated:int,skipped:int,failed:int} $stats
 */
protected function processRows(array $rows, ExchangeClient $exchange, array &$stats): bool
{
    foreach ($rows as $oldRow)
    {
        try
        {
            $result = $this->processOneRow($oldRow, $exchange);
            $stats[$result['status']]++;
            $this->context->stats->add($this->entityLabel(), $result['status']);
            $this->logRow($oldRow, $result);
        }
        catch (HttpException $e)
        {
            if ($e->errorCode === 'unresolved_required')
            {
                $stats['skipped']++;
                $this->context->stats->add($this->entityLabel(), 'skipped');
                $this->logRow($oldRow, ['status' => 'skipped', 'reason' => 'ambiguous-header']);
                continue;
            }
            $stats['failed']++;
            $this->context->stats->add($this->entityLabel(), 'failed');
            $oldNdx = (int) ($oldRow['ndx'] ?? 0);
            $desc = $this->rowDescriptor($oldRow);
            $this->err("Failed {$this->entityLabel()} (old ndx={$oldNdx}"
                . ($desc !== '' ? ", {$desc}" : '') . "): " . $e->getMessage());
            if (!$this->isContinueOnError())
            {
                $this->err("Aborting (use --continue-on-error to skip failed rows).");
                return false;
            }
        }
    }
    return true;
}

protected function printDone(array $stats): void
{
    $this->info("");
    $this->info(sprintf("Done %s: created=%d, updated=%d, skipped=%d, failed=%d",
        $this->entityLabel(),
        $stats['created'], $stats['updated'], $stats['skipped'], $stats['failed']));
}
```

(Chování zůstává identické pro persons/items — jen extrahováno.)

#### E.2 `DocsRunner` — časové úseky

`DocsRunner` přepíše `run()` tak, že rozseká rozsah účetních dat na měsíční
úseky a každý zpracuje samostatně (malá množina v paměti). Mezi úseky se
`$rows` uvolní.

```php
public function run(): bool
{
    if (!$this->isDryRun() && !$this->hasOwnCompany())
    {
        // … stávající pre-flight check vlastní firmy …
        return false;
    }

    [$from, $to] = $this->effectiveDateRange();   // z --from/--to nebo MIN/MAX dateAccounting
    if ($from === null || $to === null)
    {
        $this->info("No documents to import (empty date range).");
        return true;
    }

    $chunkMonths = max(1, (int) ($this->app()->arg('chunk-months') ?? 1));
    $chunks = $this->monthlyChunks($from, $to, $chunkMonths);

    $this->info("Importing documents in " . count($chunks) . " chunk(s) of {$chunkMonths} month(s), {$from} … {$to}.");

    $exchange = new ExchangeClient($this->http());
    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $limit = (int) ($this->app()->arg('limit') ?? 0);

    foreach ($chunks as [$cFrom, $cTo])
    {
        $this->chunkFrom = $cFrom;
        $this->chunkTo   = $cTo;
        $rows = $this->fetchSourceRows();

        if ($limit > 0)
        {
            $remaining = $limit - array_sum($stats);
            if ($remaining <= 0) break;
            if (count($rows) > $remaining)
                $rows = array_slice($rows, 0, $remaining);
        }

        $this->info("— chunk {$cFrom} … {$cTo}: " . count($rows) . " docs");
        if (!$this->processRows($rows, $exchange, $stats))
            return false;

        unset($rows);   // uvolnit před dalším úsekem
    }

    $this->printDone($stats);
    return $stats['failed'] === 0;
}
```

**`sourceQuery()`** upravit, aby preferovala `chunkFrom`/`chunkTo` (interní
properties) před `--from`/`--to` argumenty:

```php
private ?string $chunkFrom = null;
private ?string $chunkTo = null;

// v sourceQuery(): místo $this->dateArg('from')/('to') použij
$from = $this->chunkFrom ?? $this->dateArg('from');
$to   = $this->chunkTo   ?? $this->dateArg('to');
```

**`effectiveDateRange()`** — když uživatel nezadal `--from`/`--to`, zjistí
rozsah z dat (`SELECT MIN(dateAccounting), MAX(dateAccounting)` přes stejný
WHERE jako `sourceQuery` bez datumového filtru, tj. `docState != 9800 AND
docType IN (invni,invno)`). Argumenty `--from`/`--to` rozsah ohraničí (vezmi
`max(from, dataMin)` … `min(to, dataMax)`).

**`monthlyChunks($from, $to, $months)`** — vrátí pole `[start, end]` dvojic
(YYYY-MM-DD), zarovnané na začátky měsíců, krok `$months`, poslední úsek
oříznutý na `$to`. Měsíce bez dokladů jsou levné (prázdný fetch).

**Pozn. k paměti:** těžká část (řádky dokladu, lookup partnera) se i dnes
počítá per-doklad v `buildCanonical`; chunkování řeší primárně velikost
`fetchAll()` hlaviček a akumulaci přes celý rozsah. Měsíční úsek udrží
množinu malou. Pokud i jeden měsíc je extrémní (>~10k dokladů), uživatel
sníží krok není potřeba — místo toho viz Otevřené body 2.

### F) CLI dispatch + usage

`ImportApp::dispatch()` — přidat:

```php
case 'all':   return (new runners\AllRunner($this->context()))->run();
// 'reset' se odbaví v run() před LocalIdMap; sem se nedostane, ale pro
// jistotu fallthrough na usage (nebo success no-op).
```

`printUsage()` — doplnit:

```
  Phase 06 — orchestrator:
    all               Run codebooks → persons → items → docs in order.
    reset             Delete the local id map (import-newShipard.sqlite).

Common options (additions):
  --reset             Delete the local id map before running (clean re-import).
  --chunk-months=N    Document import chunk size in months (default 1). 'docs'/'all'.
```

Aktualizovat i `--from`/`--to` popis: platí pro `docs` i `all` (omezí jen
doklady).

## Hotovo když

1. **`all`** spustí číselníky → osoby → položky → doklady ve správném pořadí;
   `--continue-on-error` pokračuje přes selhání fáze, jinak abort + souhrn.
2. **`all --from=… --to=…`** omezí jen doklady; číselníky/osoby/položky jedou
   kompletní.
3. **Log soubor** `log/import-<ts>.log` vznikne a obsahuje celý výstup běhu
   (info/ok/warn/err + dumpy); konzole se chová jako dřív.
4. **Souhrn** na konci `all` vypíše per-entita created/updated/skipped/failed.
5. **`reset`** smaže sqlite (+ WAL/SHM) a skončí; **`--reset`** smaže a
   pokračuje; oba vypíšou warning o duplikátech.
6. **Doklady se importují po měsíčních úsecích** (`--chunk-months` konfiguruje
   krok); paměť se mezi úseky uvolňuje. Plný import desítek tisíc dokladů
   doběhne bez `memory_limit` pádu.
7. **Persons/items beze změny chování** (jen extrakce `processRows`).
8. **Idempotence zachována** — druhý běh `all` bez `--reset` vše přeskočí
   (LocalIdMap hit); counter dokladů se nemění.

### Testy / ověření

- **Smoke `all --dry-run -v`** — projde všechny fáze, nic nezapíše, log
  soubor vznikne.
- **`all --from=2024-01-01 --to=2024-12-31`** na DS `68908901448295` —
  číselníky/osoby/položky kompletní, doklady jen 2024, log + souhrn sedí.
- **Paměť**: `docs` přes víceletý rozsah s `--chunk-months=1` — sleduj
  `memory_get_peak_usage`; nesmí růst lineárně s počtem dokladů (drží se na
  úrovni jednoho měsíce). Lze ověřit i `-d memory_limit=256M`.
- **`reset`** — vytvoř mapu (libovolný import), `reset`, ověř že soubor zmizel
  a `status` hlásí prázdnou mapu.
- **`--reset` + `all`** — po předchozím plném importu `all --reset` vše znovu
  vytvoří (created, ne skipped).
- **Idempotence**: `all` dvakrát za sebou (bez reset) → druhý běh samé
  skipped, žádné duplikáty, counter dokladů beze změny.

## Doporučené pořadí implementace

1. **Logger + zapojení v `ImportRunner`** — izolované, hned viditelné (log
   soubor vzniká). Otestuj na libovolném existujícím subkomandu.
2. **`ImportStats` + `ImportContext`** rozšíření + zápis v `processRows`/
   codebook runneru.
3. **Refaktor `BaseExchangeRunner`** (`processRows`/`printDone`) — ověř, že
   persons/items jedou identicky jako dřív.
4. **`DocsRunner` chunkování** (`effectiveDateRange`, `monthlyChunks`,
   `chunkFrom/To`, override `run`).
5. **`AllRunner`** + dispatch `all`.
6. **`reset`** (flag + subkomand) v `ImportApp::run`.
7. **Usage** + smoke testy.

## Otevřené body / rozhodnutí

### 1. `reset` a duplikáty
`reset` je bezpečný jen při čistém cílovém DS. Do neprázdného DS smazání mapy
znamená spoléhat na business-key match (IČO/DIČ pro osoby, číslo pro doklady),
který nepokrývá osoby bez IČO → duplikáty. Proto explicitní warning. Pokud
chceš, lze později přidat `reset` confirmaci (`--yes`), ale pro dev-migraci
stačí warning.

### 2. Granularita chunkování
Měsíční úsek řeší běžné rozložení dokladů. Extrémně hustý měsíc (>~10k dokladů)
by se dal řešit keyset-stránkováním uvnitř úseku (po `ndx`), ale to je
invazivnější refaktor `fetchSourceRows`. Necháno na později — `--chunk-months`
default 1 stačí na reálná data. Pokud narazíš, doplníme keyset.

### 3. Pořadí fází v `all` a `--continue-on-error`
Doklady závisí na osobách (partneři) a položkách (řádky). Při
`--continue-on-error` a selhání osob/položek poběží doklady dál, ale s vyšší
chybovostí (nenalezení partneři → skip/fail). To je přijatelné (chyby jsou
v logu + souhrnu); orchestrátor je nezastavuje, protože uživatel explicitně
zvolil pokračování.

### 4. Chunkování persons/items
Zatím `fetchAll()`. Pokud reálná data ukážou paměťový problém i u osob/položek
(desetitisíce), zobecní se `processRows` na keyset-batching v base runneru
(persons/items mají taky `ORDER BY ndx`). Mimo rozsah 06a.

### 5. Log soubor — retence a velikost
Jeden soubor na běh, append mód (kdyby běh padl a opakoval se týž timestamp,
což je nepravděpodobné). Bez automatické rotace/mazání — dev nástroj, log
adresář si uživatel uklízí sám. Verbose běh s `--dump-payload` může být velký;
to je záměr (ladění).
