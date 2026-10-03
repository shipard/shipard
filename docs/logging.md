# Logging

Centralizované logování aplikace. Vše prochází přes
`Shipard\Core\Logging\ErrorLogger` — žádné přímé `error_log()` v aplikačním
kódu (s výjimkou fallbacku v `ErrorLogger` samotném).

## Cesta logu

Default: `/opt/shipard/log/shipard.log`

Konfigurovatelné v `/etc/shipard/server.json`:

```jsonc
{
    "logFile": "/opt/shipard/log/shipard.log",
    "logLevel": "debug"
}
```

`logLevel` může být `"debug"`, `"info"`, `"warn"`, `"error"`. Záznamy
s nižší úrovní se zahodí (neuloží do souboru, nezavolá `error_log`).

## Formát záznamu

Single-line JSON, jeden záznam per řádek:

```json
{"ts":"2026-05-07T12:34:56+02:00","level":"error","ds":"...","request":"GET /...","msg":"...","exception":{},"ctx":{}}
```

Pole:

| Pole | Typ | Popis |
|---|---|---|
| `ts` | string | ISO 8601 s timezone, `date('c')` |
| `level` | string | `debug` / `info` / `warn` / `error` |
| `ds` | ?string | DS ID nebo `null` (chyby před rozpoznáním DS) |
| `request` | ?string | `"METHOD /path"` nebo `null` (CLI / non-HTTP) |
| `msg` | string | Krátká lidská zpráva |
| `exception` | ?object | Jen u `logException`, viz níže |
| `ctx` | object | Strukturovaný kontext (vždy objekt, i prázdný) |

`exception` objekt:

```json
{
    "class": "Dibi\\DriverException",
    "message": "Unknown column 'docState' in 'WHERE'",
    "at": "vendor/dibi/.../MySqliDriver.php:179",
    "trace": ["#0 ...", "#1 ..."],
    "previous": {}
}
```

`trace` je oříznutý na 20 frames. `previous` rekurzivně pro chained
exceptions, max 5 úrovní hloubky.

## Použití v kódu

```php
use Shipard\Core\Logging\ErrorLogger;

ErrorLogger::debug("Something happened", ["request_id" => $id]);
ErrorLogger::info("User logged in", ["user_id" => $userId]);
ErrorLogger::warn("Viewer not found, skipping", ["viewer_id" => $vid]);
ErrorLogger::error("Save failed", ["table" => $t, "error" => $errMsg]);
ErrorLogger::logException($e);
ErrorLogger::logException($e, "Document save failed for doc {$docId}");
```

Pravidla:

- **`msg` = lidská zpráva**, nikdy nezahrnuj parametry stringovým concatenací
- **`ctx` = strukturovaná data**, dostane se do JSON `ctx` field
- **`logException`** zachytí celou výjimku včetně stack trace; `msg`
  parametr je volitelný kontext

## Co se loguje automaticky

`ErrorLogger` se volá z několika míst v aplikaci automaticky, bez nutnosti
zasahovat do volajícího kódu:

- **`public/index.php` `\Throwable` catch handler** — všechny výjimky, které
  doletou až na nejvyšší úroveň (typicky bugy v controllerech, rozbitý
  config, nezachycené chyby z infrastrukturních komponent), prolézají
  `ErrorLogger::logException`.
- **`TableGateway::saveDocument` `\Throwable` catch** — nečekané výjimky uvnitř
  save transakce (SQL chyby, type mismatchy, problémy s připojením) se zalogujou
  i tehdy, když gateway vrátí `DocumentResult::error()` zpět do
  controlleru. Bez toho jsme uměli získat jen `INTERNAL_ERROR` v response,
  ale samotný stack trace nikde — docházelo k tichým 500 bez stop.
- **`\DomainException`** je výjimkou: gateway ji neloguje, protože
  to je očekávaný business outcome (např. „can't release number with gap
  in sequence”). Pro tyhle případy je log šum.

Volání `ErrorLogger::warn/info/debug/error` z controllerů a Document
classes je vždy explicitní — logger nemá žádnou globální magii, která
by je nastřelila sama.

## Lifecycle (bootstrap)

`public/index.php` nastaví logger v tomto pořadí:

1. Po `Request::fromGlobals()` — `setRequestContext($method . ' ' . $path)`
2. Po `ServerConfig::load()` — `setLogPath()`, `setLogLevel()`
3. Po `DataSourceResolver::resolve()` — `setDsId($resolved->config->getId())`

Pokud krok 2 nebo 3 selže, příslušná pole zůstanou na svém defaultu —
logger funguje degradovaně, ale neztratí záznam.

## Čtení logu

Pro lidské sledování v dev / produkci:

```bash
# Live tail s formátováním
tail -f /opt/shipard/log/shipard.log | jq -c .

# Jen errors
tail -f /opt/shipard/log/shipard.log | jq 'select(.level == "error")'

# Jeden konkrétní DS
tail -f /opt/shipard/log/shipard.log | jq 'select(.ds == "abcd-efgh-ijkl-mnop")'

# Kolik chyb za den
grep '"level":"error"' /opt/shipard/log/shipard.log | wc -l

# Top 10 chybových hlášek za týden
grep '"level":"error"' /opt/shipard/log/shipard.log \
    | jq -r '.msg' \
    | sort | uniq -c | sort -rn | head
```

## Deploy

Adresář `/opt/shipard/log` zakládá `scripts/install-packages.sh`: patří
shipard-userovi (v produkci `shipard`, ve vývoji tvůj uživatel), pod kterým
běží PHP-FPM pool i CLI, a má práva `0750`.

Soubor `shipard.log` vzniká při prvním zápisu s právy `0640`; chybějící
adresář si `ErrorLogger` založí sám s `0750`. Obojí bez ohledu na umask.
Existující adresář ani soubor logger nemění — odchylky od kontraktu hlásí
`shpd-server doctor` a srovná `shpd-server fix-permissions`, viz
[`operations/permissions.md`](operations/permissions.md).

### Bez instalačního skriptu

Kde instalační skript neběžel, default cesta `/opt/shipard/log/` není
zapisovatelná. Buď:

```bash
sudo mkdir -p /opt/shipard/log
sudo chown $USER:$USER /opt/shipard/log
sudo chmod 0750 /opt/shipard/log
```

nebo přepiš `logFile` v `/etc/shipard/server.json` na cestu, kterou
vlastníš (např. `~/shipard-logs/shipard.log`). Bez toho `ErrorLogger`
spadne na PHP `error_log()` fallback — zápisy půjdou do PHP-FPM logu
nebo `stderr` (CLI).

### Logrotate

Doporučená konfigurace `/etc/logrotate.d/shipard`:

```
/opt/shipard/log/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 shipard shipard
    sharedscripts
}
```

Řádek `create` drží kontrakt práv — vlastník je shipard-user (ve vývoji
tvůj uživatel místo `shipard`) a mód `0640`; s volnějším módem by `doctor`
po první rotaci hlásil chybu.

30 dnů historie, gzip kompresí starších log souborů. Žádný `postrotate`
hook potřeba — `ErrorLogger` otevírá soubor při každém zápisu (append +
LOCK_EX), takže rotace bez signálu funguje. Glob `*.log` pokrývá i
`/opt/shipard/log/cron.log` (stdout redirect slotů z generovaného
`/etc/cron.d/shipard`).

### Systemd-tmpfiles (alternativa pro logrotate)

Pokud máš systemd-spravovaný host:

```
# /etc/tmpfiles.d/shipard.conf
d /opt/shipard/log 0750 shipard shipard 30d
```

## Vztah k PHP error_log

`ErrorLogger` paralelně volá PHP `error_log()` — každý zápis projde i
do tradičního PHP error logu (PHP-FPM, syslog, nebo wherever `php.ini`
ukáže). To je záměr:

- Pokud by se `/opt/shipard/log/shipard.log` rozbil (oprávnění,
  filesystem full), PHP error log je fallback
- Produkční pipelines (fluentbit, journald) často konzumují PHP-FPM log
  centrálně — takže získají stejné záznamy

## Future work

- **CLI integrace** — `bin/shpd-ds` příkazy by měly volat
  `ErrorLogger::setRequestContext("cli: " . $commandName)` na začátku.
  Aktuálně `ds` i `request` zůstávají `null` v CLI kontextu, ostatní
  pole fungují normálně.
- **Per-DS soubory** — pokud se ukáže, že jeden globální soubor je
  pro multi-tenant nepraktický, řeší se buď logrotate splittingem
  podle `ds` field, nebo refaktorem `ErrorLogger` na per-DS path.
- **Log shipping** (Loki, Elasticsearch, fluentbit) — JSON formát to
  připravuje. Integrace je `tail -f` source → parse json → ship.
