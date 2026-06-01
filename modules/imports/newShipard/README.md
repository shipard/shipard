# imports.newShipard

Importer dat ze starého Shipardu do nového Shipardu přes HTTPS REST API.

## Předpoklady

- Nový Shipard reachable přes HTTPS.
- V novém Shipardu vytvořený API klíč (např. pro uživatele
  `_legacy_importer`) — viz `shpd-ds api-key-create`.
- V DS root starého Shipardu:
  - modul `imports/newShipard` přidaný do `config/modules.json` (jinak
    cliAction skončí s "Invalid moduleId").
  - konfigurační soubor `config/import-newShipard.json` s URL a API klíčem.
- PHP 8.1+ (kvůli `readonly` promoted properties).
- PHP rozšíření: `curl`, `pdo_sqlite`.

## Spuštění

Z DS adresáře:

```bash
cd /var/lib/shipard/data-sources/<dsid>
shpd-app cli-action --action=imports.newShipard/import <subcommand> [options]
```

Doporučený alias:

```bash
alias shpd-ds-import='shpd-app cli-action --action=imports.newShipard/import'
```

## Subkomandy

| Subkomanda          | Stav        | Popis                                                |
| ------------------- | ----------- | ---------------------------------------------------- |
| `status`            | ✅ Fáze 01  | Sanity check — config, HTTP připojení, lokální mapa. |
| `vat-registrations` | ✅ Fáze 02  | Registrace k DPH (jen `taxType='vat'`).              |
| `fiscal-years`      | ✅ Fáze 02  | Fiskální roky + fiskální měsíce (sub-import).        |
| `bank-accounts`     | ✅ Fáze 02  | Vlastní bankovní spojení.                            |
| `cost-centers`      | ✅ Fáze 02  | Střediska.                                           |
| `warehouses`        | ✅ Fáze 02  | Sklady.                                              |
| `cash-desks`        | ✅ Fáze 02  | Pokladny.                                            |
| `number-series`     | ✅ Fáze 02  | Číselné řady dokladů (jen typy známé v novém DS).    |
| `item-kinds`        | ✅ Fáze 02  | Druhy položek (s mapováním na seedované system_code).|
| `all-codebooks`     | ✅ Fáze 02  | Všechny číselníky v pořadí závislostí.               |
| `persons`           | ✅ Fáze 03  | Osoby (lidé + firmy) přes exchange applier.          |
| `items`             | ✅ Fáze 04  | Položky (zboží, služby) přes exchange applier.       |
| `docs`              | ✅ Fáze 05  | Doklady — faktury (`invni`/`invno`). Viz [Doklady](#doklady). |
| `all`               | ⏳ Fáze 06  | Orchestrace všech fází v pořadí závislostí.          |

### Společné options

- `--verbose`, `-v` — verbose výstup (HTTP requesty + per-row debug na stderr).
- `--dry-run` — neprovádět zápisy do cílového Shipardu.
- `--continue-on-error` — pokračovat i když jednotlivý řádek selže (default: stop).
- `--limit=N` — zpracuj jen prvních N řádků (jen exchange runnery, vhodné pro testing).
- `--no-throttle` — vypne klientské throttling mezi requesty (viz [Rate limiting](#rate-limiting)). Vhodné pro testování chování serveru pod zátěží.

### Options jen pro `docs`

- `--from=YYYY-MM-DD` / `--to=YYYY-MM-DD` — filtr období na `dateAccounting`
  (datum zaúčtování — zajišťuje kompletní fiskální období, na rozdíl od data
  vystavení). Nevalidní formát se ignoruje s warningem.
- `--target-state=10` — importovat doklady jako koncept (docState 10) místo
  výchozího potvrzeno (20).

### Doklady

MVP scope importu dokladů jsou **faktury přijaté (`invni`) a vydané (`invno`)**.
Ostatní typy (pokladní, bankovní, objednávky, dodací listy) jsou mimo scope
prvního pokusu.

**Prerekvizita — označená vlastní firma.** Doklady používají `selfParty`
resolution, která v cílovém Shipardu hledá firmu označenou `is_own = 1`.
Po Fázi 03 jsou všechny osoby `is_own = false`, takže před importem dokladů
je nutné svou firmu ručně označit (UI nebo SQL):

```sql
-- Zjistit ID vlastní firmy podle IČO:
SELECT id, full_name, company_id FROM base_persons_persons WHERE company_id = '<vlastní-IČO>';
-- Označit jako vlastní:
UPDATE base_persons_persons SET is_own = 1 WHERE id = <id>;
```

`DocsRunner` na začátku ověří existenci `is_own = 1` firmy a bez ní abortuje
s instrukcí.

**Pořadí importu:** codebooks → persons → items → **docs**. Doklady spoléhají
na to, že partneři (osoby) a položky už v cíli jsou; jinak je applier
zkusí vytvořit (`autoCreateMode: safe`), což vede k neúplným záznamům.

**Známá omezení:**

- **Středisko a sklad se ztrácí** — `docs_core_heads` v novém Shipardu zatím
  nemá `cost_center` ani `warehouse`. Importované doklady tato data nenesou.
- **Faktury s partnerem bez IČO se přeskočí** — `autoCreateMode: safe` vytvoří
  partnera jen pokud má `company_id`. Partner-fyzická osoba bez IČO, který
  zároveň není předem naimportovaný (persons), způsobí `unresolved_required`
  → celý doklad je `skipped`. Proto importuj persons před docs.
- **Vydané faktury (`invno`) — vlastní bankovní účet a dvoukrokový import.**
  Nový Shipard u vydané faktury vyžaduje při potvrzení (docState 20+) vlastní
  `bank_account` (kam má zákazník zaplatit; `IssuedInvoiceDocument::validate`).
  Exchange formát ho ale neumí přenést. Proto se `invno` vkládá nejdřív jako
  **koncept (10)** a runner ho v `afterApplied` povýší na 20 spolu s účtem,
  který dohledá ze starého `myBankAccount` přes LocalIdMap (Fáze 02
  `bank-accounts`). **Bez naimportovaných bank-accounts** (nebo když starý
  doklad nemá `myBankAccount`) zůstane vydaná faktura konceptem (10) +
  warning. Přijatých faktur (`invni`) ani `--target-state=10` se to netýká.
- **Číslo vydané faktury** — applier dává `docNumber` do `partner_doc_number`
  a vlastní `doc_number` přiděluje number_series až při přechodu 10→20. Runner
  proto až **po povýšení** přepíše vygenerované číslo na původní (non-fatal při
  unique konfliktu).
- **Řádky typu sada** (`rowType`) se importují tak, jak jsou — rozložené sady
  mohou vytvořit duplicitní řádky. K ověření na reálných datech.

**Konverze polí na řádcích:**

- **Kódy DPH** — starý formát `EUCZ{NNN}` se převádí na nový `cz-{NNN}`
  (deterministicky, prefix `EUCZ` → `cz-`). Kódy `EUCZ000` (nedaňový řádek)
  a `EUCZ113` (artefakt zdroje) v novém Shipardu neexistují → mapují se na
  `null` (řádek bez kódu DPH). Mapování je **CZ-only**; kódy jiných zemí se
  pošlou beze změny a applier je případně odmítne. `vat.pct` se posílá souběžně
  jako fallback. Zdroj pravdy: `nov_shipard:modules/world/vat/config/vat-cz.jsonc`.
- **Jednotka `none`** — systémová jednotka starého Shipardu pro řádky bez
  jednotky se mapuje na `null` (prázdný sloupec `unit`), aby applier nehlásil
  `unit_not_found`.

### Rate limiting

Nový Shipard má API rate limit **1000 requestů / 60 s per API klíč**
(viz `nov_shipard:src/Api/Middleware/RateLimitMiddleware.php`). Importer má
tři vrstvy obrany, aby se do něj nedostal:

1. **Proaktivní throttling** — minimum interval mezi requesty (default 80 ms
   = ~12.5 req/s, 25% rezerva pod limit). Měřeno přes `microtime(true)` od
   posledního requestu — pokud aplikace mezi nimi dělá DB queries / mapování,
   čekání už probíhalo "samo" a throttle nic nepřidá.

2. **Respect `_retry_after` při 429** — pokud server přesto vrátí 429
   RATE_LIMITED, klient přečte `error.details[].field='_retry_after'` (sekundy)
   z body a počká přesně tu dobu. Pokud `_retry_after` chybí, fallback na
   exp. backoff.

3. **Exponential backoff pro 5xx a network errory** — při 500-599, network
   timeoutu nebo DNS chybě čekáme `retryDelayMs * 2^(attempt-1)` (1s, 2s, 4s,
   …, cap 30 s).

Maximum počet retry pokusů je `maxRetries` (default 3). Po jejich vyčerpání
runner zafailuje per řádek (`--continue-on-error` umožní pokračovat).
**4xx errory kromě 429** (validation, schema_invalid, 404, ...) **NEretryjeme**
— jsou fatální, vyžadují opravu zdrojových dat.

Verbose log (`-v`) zobrazí každý retry: `[http] retry 1/3 after 6 s (HTTP 429: RATE_LIMITED)`.

## Konfigurace

Soubor `config/import-newShipard.json` v DS rootu:

```jsonc
{
    "target": {
        "baseUrl": "https://abcd-efgh-ijkl-mnop.shipard.app/api/v1",
        "apiKey": "shpd_ak_1234567890abcdef1234567890abcdef",
        "timeout": 30,

        // Rate limiting (volitelné, defaulty stačí pro většinu situací):
        "throttleMs":   80,    // pauza mezi requesty (ms); 0 = off
        "maxRetries":   3,     // počet retry pokusů pro 429 / 5xx / network
        "retryDelayMs": 1000   // base delay pro exp. backoff (ms)
    },
    "options": {
        "verbose": false,
        "dryRun": false,
        "batchSize": 100
    }
}
```

Pole `target.baseUrl` a `target.apiKey` jsou povinné. Volitelné:

| Klíč | Typ | Default | Rozsah | Popis |
|---|---|---|---|---|
| `target.timeout` | int | 30 | 1–300 | curl timeout v sekundách |
| `target.throttleMs` | int | 80 | 0–10000 | minimum pauza mezi requesty v ms |
| `target.maxRetries` | int | 3 | 0–10 | počet retry pokusů |
| `target.retryDelayMs` | int | 1000 | 100–60000 | base delay pro exp. backoff v ms |

Sekce `options` je volitelná.

**Bezpečnost:** soubor obsahuje API klíč — nastavte `chmod 0600`. Modul
varuje na stderr, pokud má soubor jiná práva.

### Idempotence

Runner respektuje LocalIdMap: pokud `(entity_type, old_ndx)` mapping existuje,
záznam se přeskočí (status `skipped`, reason `already-imported`). Druhý běh
nevytváří duplicity ani neaktualizuje existující data — záznamy v novém
Shipardu jsou po vložení ve stavu `docState=40` (V pořádku), který je
readOnly. Pro re-import po opravě zdrojových dat:

```php
// V PHP REPL / debug skriptu:
$idMap = new LocalIdMap('<DS>/import-newShipard.sqlite');
$idMap->forgetAll('bankAccount');   // smaže všechen mapping pro daný typ
```

Nový import pak založí nové záznamy v novém Shipardu paralelně se starými.
Pro tabulky s unique `code` (bank-accounts / cost-centers / warehouses /
cash-desks) v takovém případě hrozí konflikt — ručně smaž staré nové
záznamy přes UI nebo si zaveď distinct kódy.

## Stav

- [x] Bootstrap (modul, dispatcher, HTTP klient, lokální mapa).
- [x] `status` — sanity check.
- [x] Číselníky (Fáze 02).
- [x] Osoby (Fáze 03).
- [x] Položky (Fáze 04).
- [x] Doklady (Fáze 05).

## Smoke test

1. Na **novém** Shipardu založ systémového uživatele `_legacy_importer`
   (pokud ještě neexistuje):

   ```bash
   cd /path/to/new/shipard/data-source
   shpd-ds user-create \
       --login=_legacy_importer \
       --password="$(openssl rand -hex 32)" \
       --name="Legacy Importer (system)" \
       --email=legacy-importer@local
   ```

   Heslo je jen placeholder — importer se přihlašuje API klíčem, ne
   heslem. Náhodný `openssl rand -hex 32` jen zajistí, že účet nepůjde
   zneužít interaktivním loginem.

2. Vytvoř pro něj API klíč:

   ```bash
   shpd-ds api-key-create --user=_legacy_importer --name=legacy-import \
       --ip=<starý-shipard-IP>
   ```

   Zachyť plaintext klíče — bude zobrazen jen jednou.

3. Na **starém** Shipardu zaregistruj modul a vytvoř config soubor:

   ```bash
   cd /var/lib/shipard/data-sources/<dsid>
   ```

   Do `config/modules.json` doplň `"imports/newShipard"` (do existujícího
   JSON pole), poté:

   ```bash
   cat > config/import-newShipard.json <<'JSON'
   {
       "target": {
           "baseUrl": "https://<new-shipard-host>/api/v1",
           "apiKey": "shpd_ak_..."
       }
   }
   JSON
   chmod 0600 config/import-newShipard.json
   ```

4. Spusť status check:

   ```bash
   shpd-app cli-action --action=imports.newShipard/import status
   ```

   Očekávaný výstup:

   ```
   Import to new Shipard — status check

   Configuration:
     Config file     : /var/lib/shipard/data-sources/<dsid>/config/import-newShipard.json
     Target base URL : https://<new-shipard-host>/api/v1
     Timeout         : 30 s
     Batch size      : 100
     Dry-run mode    : no
     Verbose mode    : no

   API connection:
   ✓ HTTP 200 — Tables endpoint reachable.

   Local ID map:
     File: /var/lib/shipard/data-sources/<dsid>/import-newShipard.sqlite
     (empty — no entities imported yet)

   ✓ Status OK.
   ```

## Lokální stav

- `<DS>/import-newShipard.sqlite` — SQLite s mapováním `old_ndx → new_id`
  per entitní typ. Vytváří se automaticky při prvním běhu (`chmod 0600`).
- Žádný stav v MySQL DS DB — modul nemá `tables` v `module.json`.
