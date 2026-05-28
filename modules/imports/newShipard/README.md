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
| `vat-registrations` | ✅ Fáze 02  | Registrace k DPH (jen `taxArea='VAT'`).              |
| `fiscal-years`      | ✅ Fáze 02  | Fiskální roky + fiskální měsíce (sub-import).        |
| `bank-accounts`     | ✅ Fáze 02  | Vlastní bankovní spojení.                            |
| `cost-centers`      | ✅ Fáze 02  | Střediska.                                           |
| `warehouses`        | ✅ Fáze 02  | Sklady.                                              |
| `cash-desks`        | ✅ Fáze 02  | Pokladny.                                            |
| `number-series`     | ✅ Fáze 02  | Číselné řady dokladů (jen typy známé v novém DS).    |
| `item-kinds`        | ✅ Fáze 02  | Druhy položek (s mapováním na seedované system_code).|
| `all-codebooks`     | ✅ Fáze 02  | Všechny číselníky v pořadí závislostí.               |
| `persons`           | ✅ Fáze 03  | Osoby (lidé + firmy) přes exchange applier.          |
| `items`             | ⏳ Fáze 04  | Import položek.                                      |
| `docs`              | ⏳ Fáze 05  | Import dokladů.                                      |
| `all`               | ⏳ Fáze 06  | Orchestrace všech fází v pořadí závislostí.          |

### Společné options

- `--verbose`, `-v` — verbose výstup (HTTP requesty + per-row debug na stderr).
- `--dry-run` — neprovádět zápisy do cílového Shipardu.
- `--continue-on-error` — pokračovat i když jednotlivý řádek selže (default: stop).
- `--limit=N` — zpracuj jen prvních N řádků (jen exchange runnery, vhodné pro testing).

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

## Konfigurace

Soubor `config/import-newShipard.json` v DS rootu:

```jsonc
{
    "target": {
        "baseUrl": "https://abcd-efgh-ijkl-mnop.shipard.app/api/v1",
        "apiKey": "shpd_ak_1234567890abcdef1234567890abcdef",
        "timeout": 30
    },
    "options": {
        "verbose": false,
        "dryRun": false,
        "batchSize": 100
    }
}
```

Pole `target.baseUrl` a `target.apiKey` jsou povinné. `target.timeout`
(1–300 s) je volitelný, default 30. Sekce `options` je volitelná.

**Bezpečnost:** soubor obsahuje API klíč — nastavte `chmod 0600`. Modul
varuje na stderr, pokud má soubor jiná práva.

## Stav

- [x] Bootstrap (modul, dispatcher, HTTP klient, lokální mapa).
- [x] `status` — sanity check.
- [x] Číselníky (Fáze 02).
- [x] Osoby (Fáze 03).
- [ ] Položky (Fáze 04).
- [ ] Doklady (Fáze 05).

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
