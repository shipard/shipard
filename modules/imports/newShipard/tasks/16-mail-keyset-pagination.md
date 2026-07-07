# 16 — MailRunner: dávkové čtení zpráv (keyset pagination) místo fetchAll

## Kontext

Import došlé pošty na velkých datech (stovky tisíc zpráv) padá po fázi
schránek na OOM:

```
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted
(tried to allocate 245760 bytes) in .../Dibi/Drivers/MySqliDriver.php on line 148
```

Příčina: `MailRunner::fetchIssues()` dělá `SELECT i.* FROM wkf_core_issues …`
bez limitu a `fetchAll()`. `i.*` zahrnuje velké textové sloupce (tělo zprávy),
celý result set se materializuje najednou.

Klíčový detail: mysqlnd bufferuje result set z PHP memory limitu — OOM padá
už v driveru. Pouhá náhrada `fetchAll()` iterací přes `Dibi\Result` by tedy
nepomohla; je nutné omezit velikost samotného dotazu.

Per-záznamové `fetchAll()` v MailRunneru (doclinks, properties, e-maily osob)
jsou v pořádku — vracejí jednotky řádků.

## Návaznost

- Sémantika `run()` (bool návrat, `--continue-on-error`, exit codes z tasku
  14) se nemění; mění se jen způsob načítání zdrojových řádků.
- `tick()` z tasku 14 zůstává beze změny. COUNT dopředu (D8) mimochodem
  připravuje data pro otevřený bod tasku 14 („progress s celkovým počtem"),
  ale tick se v tomto tasku nerozšiřuje.
- `ensureMailboxes()` se nemění (default schránka Sekretariátu — commit
  4545c9a1).
- Idempotence zpráv: `processIssue()` skipuje přes `ENTITY_MESSAGE` idMap —
  dávkování na tom nic nemění, re-run je dál bezpečný.

## Před implementací přečti

- `libs/runners/MailRunner.php` — `run()` (~ř. 76), `fetchIssues()`
  (~ř. 556), `appendDateWindow()`, začátek `processIssue()` (idMap skip)
- `libs/ImportApp.php` — čtení argů (`$this->app->arg(...)` — generické
  `--key=value`, není třeba nic registrovat) + help text (`--limit`,
  `--chunk-months` jako vzor formátu)
- `libs/runners/DocsRunner.php` — sémantika `--limit` pro konzistenci

## Scope

**V rozsahu:** dávkové čtení v MailRunneru (`fetchIssuesBatch`), smyčka
v `run()`, COUNT dopředu, `--batch` arg + help text, `--limit` přes
zastavení smyčky.

**Mimo rozsah:**

- Stejný vzor v `PersonsRunner` / `BankStatementsRunner` (D10 — odloženo,
  viz Otevřené body).
- Rozšíření `tick()` o celkový počet (otevřený bod tasku 14).
- Unbuffered queries, zvyšování `memory_limit` — neřeší se.
- `ensureMailboxes()` — beze změny.

## Co implementovat

1. **`countIssues(): int`** — `SELECT COUNT(*)` se stejným WHERE jako
   dnešní `fetchIssues()` (issueType=1, docState != 9800,
   `appendDateWindow`). Info řádek `Found N inbox messages.` zůstává,
   jen zdroj čísla je COUNT.

2. **`fetchIssues()` → `fetchIssuesBatch(int $afterNdx, int $batchSize): array`**
   — stejný SELECT + ` AND i.[ndx] > %i` (`$afterNdx`) + `ORDER BY i.[ndx]`
   + ` LIMIT %i` (`$batchSize`). Návratový tvar beze změny (pole polí).

3. **Smyčka v `run()`:**
   - kurzor `$afterNdx = 0`; opakovaně `fetchIssuesBatch($afterNdx, $batch)`;
     prázdná dávka → konec;
   - uvnitř dávky `processIssue()` + `tick()` jako dnes;
   - **kurzor = `ndx` posledního řádku dávky** — posouvá se i přes
     failed/skipped záznamy (ndx posledního *načteného*, ne posledního
     úspěšného — jinak nekonečná smyčka s `--continue-on-error`);
   - `--limit N`: čítač zpracovaných napříč dávkami; po dosažení N ukončit
     obě smyčky (dnešní `array_slice` zrušit).

4. **`--batch` arg** — default 500, sanitace `max(1, (int) …)`; help text
   v `ImportApp` (`Mail import batch size (keyset). Default 500. 'mail' only.`).

5. **Neměnná sémantika:** pořadí zpracování (`ORDER BY ndx`), `--from/--to`
   okno, `--dry-run`, `--require-linked-doc`, `--no-attachments`,
   `--continue-on-error`, exit codes.

## Hotovo když

- Import na DS, kde to dnes padá, doběhne s `memory_limit = 128M`;
  špička paměti neroste s počtem zpráv (ověřit
  `memory_get_peak_usage()` v závěrečném logu nebo `ps` během běhu).
- `--limit 750` s `--batch 500` zpracuje přesně 750 zpráv (limit přes
  hranici dávky).
- `--batch 100` vs. default dávají identické `Done` statistiky na
  referenčním menším DS.
- `--from/--to` funguje beze změny; `--dry-run` beze změny.
- Re-run (idempotence přes `ENTITY_MESSAGE`) beze změny.

## Commit

Jeden commit:
`fix(imports/newShipard): mail import čte zprávy po dávkách (keyset) — OOM na velkých datech`

## Rozhodnutí ✓ (odsouhlaseno v konverzaci)

- **D6:** Keyset pagination (`WHERE i.[ndx] > kurzor ORDER BY i.[ndx]
  LIMIT batch`) místo date-chunků po vzoru DocsRunneru — paměť je omezená
  velikostí dávky bez ohledu na rozložení dat; date-chunk velikost
  negarantuje (nápor pošty v jednom měsíci, obří těla zpráv). `ndx` je PK,
  seek je efektivní. ✓
- **D7:** Velikost dávky `--batch`, default 500. ✓
- **D8:** `SELECT COUNT(*)` dopředu pro `Found N inbox messages.`. ✓
- **D9:** `--limit` zastavením smyčky po N zpracovaných (místo
  `array_slice`). ✓
- **D10:** `PersonsRunner`/`BankStatementsRunner` — stejný vzor **odloženo**,
  dokud nepadají. ✓

## Otevřené body

- D10 — `PersonsRunner` (`SELECT p.* FROM e10_persons_persons`),
  `BankStatementsRunner` (`SELECT h.*` / `r.*` z heads/rows) mají stejný
  neomezený vzor; předělat stejně, až to bude potřeba.
- `tick()` s celkovým počtem (`12500/48213`) — COUNT už bude k dispozici,
  snadné doplnit (viz otevřený bod tasku 14).
