# Task: Import do nového Shipardu — Bootstrap (Fáze 01)

## Kontext

Začínáme nový modul ve starém Shipardu, který bude **importovat data ze
starého Shipardu do nového Shipardu přes HTTPS REST API**. Tento task
zakládá infrastrukturu — modul, CLI router, HTTP klient, konfigurační
soubor, lokální mapovací SQLite a první funkční subkomandu jako sanity
check celého řešení.

**Cíl Fáze 01:** Hotová kostra, na kterou navážou další fáze:

- Fáze 02 — číselníky (bankovní spojení, fiskální období, DPH registrace,
  střediska, sklady, pokladny, číselné řady).
- Fáze 03 — osoby.
- Fáze 04 — položky.
- Fáze 05 — doklady.

Po dokončení této fáze musí jít z DS adresáře spustit:

```bash
cd /var/lib/shipard/data-sources/<dsid>
shpd-app cli-action --action=imports.newShipard/import status
```

a dostat smysluplnou odpověď, která prokáže, že:

1. Modul je zaregistrovaný a CLI dispatcher funguje.
2. Konfigurační soubor `config/import-newShipard.json` se načítá.
3. HTTP klient se umí přihlásit k novému Shipardu (Bearer token) a zavolat
   API endpoint.
4. Lokální mapovací SQLite existuje a je čitelná.

**Pořadí závislostí.** Tento task má dvě tvrdé prerekvizity v novém
Shipardu, obě hotové:

- `nov_shipard:tasks/api-key-cli.md` — CLI příkaz pro vytvoření API klíče.
  Aby vůbec mohl uživatel získat klíč, kterým importer komunikuje.
- `nov_shipard:tasks/exchange-format-items-phase1.md` — formát
  `shpd.items.item.v1`. Pro Fázi 01 ne nutné (status check se obejde bez
  něj), ale později ho potřebujeme.

**Mimo scope Fáze 01:**

- Jakákoliv reálná data — žádné `import bank-accounts`, žádné `import
  persons`. Pouze sanity-check `status` subkomand.
- Resolver business klíčů pro číselníky bez exchange formátu — Fáze 02.
- Concurrency / paralelní zpracování — sériový loop stačí.
- Error recovery napříč subkomandy — `status` selže okamžitě při první
  chybě, není potřeba sofistikovaný retry stav.

## Před implementací přečti

Klíčové existující soubory:

- **`modules/imports/erps/pohoda/module.json`** — vzor pro náš
  `module.json`. Minimální struktura `{id, name, tables, modules}`.
- **`modules/imports/erps/pohoda/services.php`** — vzor pro náš
  `services.php`. Ukazuje `ModuleServices extends \Shipard\CLI\ModuleServices`
  pattern s override `onCliAction($actionId)`.
- **`src/CLI/ModuleServices.php`** — parent class. Public API:
  `$this->app()` vrací `\Shipard\CLI\Application`, `$this->db()` vrací
  Dibi connection. Hook `onCliAction($actionId)` je naše vstupní brána.
- **`src/CLI/Application.php`** — `Application::arg($name)` pro `--name=value`
  opce, `Application::command($idx)` pro pozicní argumenty. Pozor:
  parseArgs řadí pozicní argumenty od indexu 0 začínaje **po** prvním
  shifted argumentu (skript), takže pro nás `command(0)` = `cli-action`
  (jméno akce shpd-app), `command(1)` = `status` (naše subkomanda).
- **`tools/shpd-app.php`** — `cliAction()` metoda (řádky ~810–860)
  ukazuje, jak se action volá. Parsuje `--action=moduleId/actionId`,
  načte modul `services.php`, instanciuje `ModuleServices`, volá
  `onCliAction($actionId)`. **Nemusíš měnit** — používáme stávající
  mechanismus.
- **`modules/imports/erps/pohoda/libs/ImportPohodaDocs.php`** — vzor pro
  strukturu engine třídy (extends Utility, má `app()` / `db()` přístup).
  Není identický usecase, ale shows organization.
- **`src/_deprecated/lib/objects/ClientEngine.php`** — **deprecated, ale
  inspirace** pro curl-based HTTP klienta. Není šablona — používá
  zastaralý `e10-api-key` header pattern. Nový klient bude mít `Authorization: Bearer …`.

Konvence ve starém Shipardu, které musíme respektovat:

- **Namespace** odpovídá cestě: `modules/imports/newShipard/` → namespace
  `imports\newShipard`.
- **`module.json`** má `id` v dot notation: `imports.newShipard`.
- **`services.php`** definuje class `ModuleServices` v daném namespace.
- **DS adresář** je `__APP_DIR__` (globální konstanta, definovaná v
  `tools/shpd-app.php`). Konfigurace, SQLite mapa, vše per-DS žije pod
  `__APP_DIR__`.
- **Code style** sleduj okolní kód — tabulator místo spaces, opening
  brace na novém řádku.

## Co implementovat

### 1. Struktura modulu

```
modules/imports/newShipard/
├── module.json
├── services.php
├── README.md
└── libs/
    ├── ImportApp.php
    ├── ImportConfig.php
    ├── ImportException.php
    ├── HttpClient.php
    ├── HttpException.php
    ├── LocalIdMap.php
    ├── ImportRunner.php
    └── runners/
        └── StatusRunner.php
```

`tasks/` adresář v modulu už existuje (vytvořený PRDem). Nezasahuj do něj.

### 2. `module.json`

Minimální registrace modulu:

```json
{
    "id": "imports.newShipard",
    "name": "Import to new Shipard",
    "tables": [],
    "modules": []
}
```

Žádné tabulky — lokální stav je v SQLite mimo MySQL DS DB. Žádné
sub-moduly.

### 3. `README.md`

Dokument modulu — analogie `modules/imports/erps/pohoda/` (které README
nemá, ale nový modul by ho mít měl). Sekce:

- **Účel** — jednou větou: importer dat ze starého Shipardu do nového
  přes HTTPS REST API.
- **Předpoklady** — nový Shipard reachable přes HTTPS, vytvořený API klíč
  v novém Shipardu, konfigurační soubor v DS root.
- **Spuštění** — příklad `shpd-app cli-action --action=imports.newShipard/import status`.
- **Subkomandy** — seznam (zatím jen `status`; ostatní v navazujících
  fázích).
- **Konfigurace** — odkaz na sekci v PRD níže (po implementaci přepiš
  na popis souboru).
- **Stav** — sekce "Hotovo / Plánováno" s checkboxy. Po Fázi 01 je
  hotovo: bootstrap, `status`. Plánováno: codebooks (Fáze 02), persons
  (Fáze 03), items (Fáze 04), docs (Fáze 05).

Délkou cca 50–100 řádků, ne víc.

### 4. `services.php` — CLI dispatcher

```php
<?php

namespace imports\newShipard;

class ModuleServices extends \Shipard\CLI\ModuleServices
{
    public function onCliAction($actionId)
    {
        if ($actionId !== 'import') {
            return parent::onCliAction($actionId);
        }

        $importApp = new \imports\newShipard\libs\ImportApp($this->app());
        return $importApp->run();
    }
}
```

Žádná další logika — to vše patří do `ImportApp`.

### 5. `libs/ImportApp.php` — subkomand router

Hlavní engine, který:

1. Načte konfiguraci přes `ImportConfig`.
2. Inicializuje `HttpClient` a `LocalIdMap`.
3. Vyhodnotí subkomandu z `$this->app->command(1)`.
4. Dispatchne na odpovídající `*Runner` třídu.

```php
<?php

namespace imports\newShipard\libs;

class ImportApp
{
    /** @var \Shipard\CLI\Application */
    private $app;
    private ?ImportConfig $config = null;
    private ?HttpClient $httpClient = null;
    private ?LocalIdMap $idMap = null;

    public function __construct(\Shipard\CLI\Application $app)
    {
        $this->app = $app;
    }

    public function run(): bool
    {
        $subcommand = $this->app->command(1);

        if ($subcommand === '' || $subcommand === false) {
            return $this->printUsage();
        }

        // Bootstrap shared state for runners
        try {
            $this->config = ImportConfig::load(__APP_DIR__);
        } catch (ImportException $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            return false;
        }

        $this->httpClient = new HttpClient(
            baseUrl: $this->config->targetBaseUrl(),
            apiKey:  $this->config->targetApiKey(),
            timeout: $this->config->timeout(),
        );

        $this->idMap = new LocalIdMap(__APP_DIR__ . '/import-newShipard.sqlite');

        return $this->dispatch($subcommand);
    }

    private function dispatch(string $subcommand): bool
    {
        switch ($subcommand) {
            case 'status':
                return (new runners\StatusRunner($this->context()))->run();

            // Following subcommands will be wired in later phases:
            //   case 'all':            → orchestrate codebooks → persons → items → docs
            //   case 'bank-accounts':  → Phase 02
            //   case 'persons':        → Phase 03
            //   …
        }

        echo "Unknown subcommand: '{$subcommand}'\n";
        return $this->printUsage();
    }

    private function context(): ImportContext
    {
        return new ImportContext(
            $this->app,
            $this->config,
            $this->httpClient,
            $this->idMap,
        );
    }

    private function printUsage(): bool
    {
        echo "Usage: shpd-app cli-action --action=imports.newShipard/import <subcommand> [options]\n";
        echo "\n";
        echo "Subcommands:\n";
        echo "  status        Sanity check — connection, config, local map.\n";
        echo "\n";
        echo "Common options:\n";
        echo "  --verbose, -v     More verbose output.\n";
        echo "\n";
        return true;
    }
}
```

**`ImportContext`** je drobný DTO ve stejném souboru nebo samostatném —
nese sdílené reference (app, config, httpClient, idMap) do runnerů, aby
runner měl všechno potřebné v jediném konstruktoru.

```php
final class ImportContext
{
    public function __construct(
        public readonly \Shipard\CLI\Application $app,
        public readonly ImportConfig $config,
        public readonly HttpClient $httpClient,
        public readonly LocalIdMap $idMap,
    ) {}
}
```

Umístění: `libs/ImportContext.php` — vlastní soubor, oddělený od ImportApp.

### 6. `libs/ImportConfig.php` — config loader

Načítá konfiguraci z `__APP_DIR__/config/import-newShipard.json`.

**Formát souboru:**

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

**`ImportConfig` API:**

```php
final class ImportConfig
{
    public static function load(string $dsRootDir): self;

    public function targetBaseUrl(): string;
    public function targetApiKey(): string;
    public function timeout(): int;        // default 30
    public function batchSize(): int;      // default 100
    public function verbose(): bool;
    public function dryRun(): bool;

    /**
     * Surový obsah souboru — pro testy / debug.
     */
    public function raw(): array;
}
```

**Validace při `load()`:**

- Soubor existuje (`__APP_DIR__/config/import-newShipard.json`). Pokud ne
  → `ImportException` s instrukcí, jaké minimum napsat. Příklad výstupu:

  ```
  ERROR: Config file '/var/lib/shipard/data-sources/<dsid>/config/import-newShipard.json' not found.

  Create the file with at minimum:
  {
      "target": {
          "baseUrl": "https://<new-shipard-host>/api/v1",
          "apiKey": "shpd_ak_..."
      }
  }

  Make sure to chmod 0600 the file (it contains an API key).
  ```

- JSON je validní. Pokud ne → `ImportException` s line number z
  `json_last_error_msg()`.
- `target.baseUrl` vyplněno a je URL (basic check: `filter_var(..., FILTER_VALIDATE_URL)`).
- `target.apiKey` vyplněno a začíná `shpd_ak_`.
- `target.timeout` je integer 1–300 (default 30 pokud chybí).
- `options.batchSize` je integer 1–1000 (default 100).
- `options.verbose` / `options.dryRun` jsou boolean (default false).

**Bezpečnost:** v `load()` ověř, že soubor má mode 0600 (jen vlastník
read/write). Pokud má jiná práva, emit **warning** (ne error) na stderr
s instrukcí `chmod 0600`. Nemělo by to brát klíče, jen upozorní.

### 7. `libs/HttpClient.php` — REST klient

Curl-based HTTP klient pro komunikaci s API nového Shipardu. Nezávislý
na Guzzle (necheck-em jeho dostupnost ve `vendor/`, používáme nativní
`curl_*`).

**API:**

```php
final class HttpClient
{
    public function __construct(
        private readonly string $baseUrl,    // např. https://host/api/v1
        private readonly string $apiKey,
        private readonly int $timeout = 30,
        private readonly bool $verbose = false,
    ) {}

    /**
     * GET request. Vrací parsed JSON response.
     *
     * @throws HttpException on network error, non-2xx response, or
     *                       JSON parse failure.
     */
    public function get(string $path, array $queryParams = []): array;

    /**
     * POST request s JSON body.
     */
    public function post(string $path, array $body): array;

    /**
     * PUT — pro CRUD update.
     */
    public function put(string $path, array $body): array;

    /**
     * PATCH — pro CRUD partial update.
     */
    public function patch(string $path, array $body): array;

    /**
     * DELETE.
     */
    public function delete(string $path): array;

    /**
     * Sanity check pro `status` subkomandu. Volá `GET /_meta/tables`
     * (existující endpoint v novém Shipardu) a vrací success/failure
     * info — neházet exception, vracet info objekt.
     *
     * @return array{ok: bool, statusCode: int, message: string, body: array|null}
     */
    public function ping(): array;
}
```

**Implementační detaily:**

- `path` má začínat `/` (kompozici dělá klient: `$baseUrl . $path`).
- Headers vždy:
  - `Authorization: Bearer <apiKey>`
  - `Content-Type: application/json` (pro POST/PUT/PATCH)
  - `Accept: application/json`
  - `User-Agent: shipard-importer/1.0`
- Body — `json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`.
- Response — `json_decode($body, true, 512, JSON_THROW_ON_ERROR)`. Při
  parse error → `HttpException`.
- 2xx = success. 4xx/5xx = `HttpException` s `statusCode`, `errorCode`
  (z `error.code` v JSON body), `errorMessage`, raw body.
- Network error (curl errno != 0) → `HttpException` s `statusCode = 0`.
- **Retry pravidla v Phase 01:** žádné. Pokud request selže, selže.
  Sofistikovaný retry s exponential backoff se přidá až bude potřeba
  pro batch importy.
- **Verbose mode** (`verbose: true` z config nebo `-v` flag) → emit na
  stderr request URL + method + body length + response status code +
  response body length. Žádný klíč nelogovat (`Authorization` header
  ne).

**`HttpException`** je vlastní třída v `libs/HttpException.php`:

```php
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,        // 0 = network error
        public readonly ?string $errorCode,
        public readonly string $errorMessage,
        public readonly ?array $responseBody,
    ) {
        parent::__construct(
            "HTTP {$statusCode}: " . ($errorCode ?? '?') . " — " . $errorMessage,
        );
    }
}
```

### 8. `libs/LocalIdMap.php` — SQLite ID mapování

Lokální SQLite databáze pro idempotentní mapování starých ID na nová ID.
Žije v `__APP_DIR__/import-newShipard.sqlite` (vedle config souboru, ale
nikoli pod `config/` — sqlite je runtime stav, ne konfigurace).

**Schéma tabulky:**

```sql
CREATE TABLE IF NOT EXISTS id_map (
    entity_type   VARCHAR(50)  NOT NULL,
    old_ndx       INTEGER      NOT NULL,
    new_id        INTEGER      NOT NULL,
    imported_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_updated  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entity_type, old_ndx)
);

CREATE INDEX IF NOT EXISTS idx_id_map_new_id
    ON id_map (entity_type, new_id);
```

**API:**

```php
final class LocalIdMap
{
    /** Vytvoří SQLite soubor pokud neexistuje, spustí migrace. */
    public function __construct(string $sqliteFilePath);

    /**
     * Vrátí mapped new_id pro (entityType, oldNdx) nebo null.
     */
    public function lookup(string $entityType, int $oldNdx): ?int;

    /**
     * Vrátí všechny záznamy pro daný typ. Užitečné pro debug / report.
     *
     * @return array<int, array{old_ndx: int, new_id: int, imported_at: string, last_updated: string}>
     */
    public function listByType(string $entityType): array;

    /**
     * Reverse lookup: new_id → old_ndx. Vrací null pokud neexistuje.
     */
    public function lookupByNewId(string $entityType, int $newId): ?int;

    /**
     * Uloží mapování. Pokud existuje, aktualizuje new_id a last_updated.
     */
    public function record(string $entityType, int $oldNdx, int $newId): void;

    /**
     * Smaže mapování (idempotentně). Pro testy / forced re-import.
     */
    public function forget(string $entityType, int $oldNdx): void;

    /**
     * Smaže všechna mapování daného typu. Pro testy / forced re-import.
     */
    public function forgetAll(string $entityType): void;

    /**
     * Statistika: počet mapování per typ.
     *
     * @return array<string, int>  entityType → count
     */
    public function stats(): array;

    /**
     * Cesta k souboru SQLite — pro debug.
     */
    public function path(): string;
}
```

**Konvence `entityType`:** camelCase řetězec, např. `bankAccount`,
`costCenter`, `warehouse`, `cashDesk`, `numberSeries`, `fiscalYear`,
`fiscalMonth`, `vatRegistration`, `vatPeriod`, `person`, `item`, `doc`.
Definuj jako konstanty v `LocalIdMap` (`const ENTITY_BANK_ACCOUNT = 'bankAccount';` …) — Phase 02+ runnery je budou používat.

**Bezpečnost:** SQLite soubor `chmod 0600` při vytvoření (`chmod($path, 0600)`).

**PHP SQLite:** použij `\PDO` s driver `sqlite:`. Foreign keys ne potřeba.
WAL mode pro lepší performance (`PRAGMA journal_mode=WAL`).

### 9. `libs/ImportRunner.php` — base class pro subkomandy

Abstract base class. Každý runner (StatusRunner, později
BankAccountsRunner atd.) dědí a implementuje `run()`.

```php
abstract class ImportRunner
{
    protected ImportContext $context;

    public function __construct(ImportContext $context)
    {
        $this->context = $context;
    }

    abstract public function run(): bool;

    // ── Shortcuts ────────────────────────────────────────────────

    protected function app(): \Shipard\CLI\Application
    {
        return $this->context->app;
    }

    protected function db(): \Dibi\Connection
    {
        return $this->app()->db();
    }

    protected function config(): ImportConfig
    {
        return $this->context->config;
    }

    protected function http(): HttpClient
    {
        return $this->context->httpClient;
    }

    protected function idMap(): LocalIdMap
    {
        return $this->context->idMap;
    }

    // ── CLI flags shared across runners ──────────────────────────

    protected function isDryRun(): bool
    {
        return (bool) $this->app()->arg('dry-run') || $this->config()->dryRun();
    }

    protected function isVerbose(): bool
    {
        return (bool) $this->app()->arg('verbose')
            || (bool) $this->app()->arg('v')
            || $this->config()->verbose();
    }

    // ── Output helpers ───────────────────────────────────────────

    protected function info(string $msg): void { echo $msg . "\n"; }
    protected function ok(string $msg): void   { echo "✓ " . $msg . "\n"; }
    protected function warn(string $msg): void { echo "! " . $msg . "\n"; }
    protected function err(string $msg): void  { echo "✗ " . $msg . "\n"; }
    protected function debug(string $msg): void
    {
        if ($this->isVerbose()) {
            echo "[debug] " . $msg . "\n";
        }
    }
}
```

### 10. `libs/runners/StatusRunner.php` — první funkční subkomanda

```php
<?php

namespace imports\newShipard\libs\runners;

use imports\newShipard\libs\ImportRunner;
use imports\newShipard\libs\HttpException;

final class StatusRunner extends ImportRunner
{
    public function run(): bool
    {
        $this->info("Import to new Shipard — status check");
        $this->info("");

        // 1. Config
        $this->info("Configuration:");
        $this->info("  Target base URL : " . $this->config()->targetBaseUrl());
        $this->info("  Timeout         : " . $this->config()->timeout() . " s");
        $this->info("  Batch size      : " . $this->config()->batchSize());
        $this->info("  Dry-run mode    : " . ($this->config()->dryRun() ? 'yes' : 'no'));
        $this->info("  Verbose mode    : " . ($this->config()->verbose() ? 'yes' : 'no'));
        $this->info("");

        // 2. HTTP connectivity
        $this->info("API connection:");
        try {
            $ping = $this->http()->ping();
            if ($ping['ok']) {
                $this->ok("HTTP " . $ping['statusCode'] . " — " . $ping['message']);
            } else {
                $this->err("HTTP " . $ping['statusCode'] . " — " . $ping['message']);
                return false;
            }
        } catch (HttpException $e) {
            $this->err("Network/HTTP error: " . $e->getMessage());
            return false;
        }
        $this->info("");

        // 3. Local ID map
        $this->info("Local ID map:");
        $this->info("  File: " . $this->idMap()->path());
        $stats = $this->idMap()->stats();
        if ($stats === []) {
            $this->info("  (empty — no entities imported yet)");
        } else {
            foreach ($stats as $type => $count) {
                $this->info(sprintf("  %-20s  %d", $type, $count));
            }
        }
        $this->info("");

        $this->ok("Status OK.");
        return true;
    }
}
```

Subkomand vypíše:

1. Hodnoty z konfiguračního souboru (kromě API klíče — ten nikdy
   nezobrazovat).
2. Stav HTTP připojení — `GET /_meta/tables` (existující endpoint, vyžaduje
   auth — slouží jako auth check současně). Pokud vrátí 200, OK.
3. Statistiky lokální mapy (počet záznamů per `entity_type`).
4. Závěr `Status OK.` nebo `Status FAILED.` (návratový kód odpovídá).

### 11. Exception hierarchie

`libs/ImportException.php` — bázová třída pro doménové chyby:

```php
class ImportException extends \RuntimeException
{
}
```

`libs/HttpException.php` — viz sekce 7.

Žádné další exception třídy v Phase 01.

### 12. Composer / autoload

Modul **nepoužívá Composer** — autoload jde přes existující mechanismus
starého Shipardu (`__SHPD_MODULES_DIR__` + namespace = path mapping).

Není potřeba ani `composer.json` v modulu, ani touch on `extlibs/composer.json`.
Curl je nativní PHP, PDO/SQLite je nativní PHP.

### 13. Manuální smoke test (popis do `README.md`)

V README dej "Smoke test" sekci:

```markdown
## Smoke test

1. Na novém Shipardu vytvoř API klíč pro nově vytvořeného uživatele
   `_legacy_importer`:

   cd /path/to/new/shipard/data-source
   shpd-ds api-key-create --user=_legacy_importer --name=legacy-import \\
       --ip=<starý-shipard-IP>

   Zachyť plaintext klíče — bude zobrazen jen jednou.

2. Na starém Shipardu vytvoř config soubor:

   cd /var/lib/shipard/data-sources/68908901448295
   cat > config/import-newShipard.json <<'JSON'
   {
       "target": {
           "baseUrl": "https://<new-shipard-host>/api/v1",
           "apiKey": "shpd_ak_..."
       }
   }
   JSON
   chmod 0600 config/import-newShipard.json

3. Spusť status check:

   shpd-app cli-action --action=imports.newShipard/import status

   Očekávaný výstup:

   Import to new Shipard — status check

   Configuration:
     Target base URL : https://...
     Timeout         : 30 s
     ...

   API connection:
   ✓ HTTP 200 — Tables endpoint reachable.

   Local ID map:
     File: /var/lib/shipard/data-sources/68908901448295/import-newShipard.sqlite
     (empty — no entities imported yet)

   ✓ Status OK.
```

## Hotovo když

1. **`modules/imports/newShipard/`** existuje s plnou strukturou ze sekce 1.
2. **`module.json`** registruje modul jako `imports.newShipard`.
3. **`services.php`** implementuje `ModuleServices::onCliAction()` který
   dispatchuje `action = "import"` na `ImportApp`.
4. **`ImportApp::run()`** parsuje subkomandu z `$this->app->command(1)`,
   načte config, inicializuje HTTP klient + ID mapu, dispatchne na
   runner.
5. **`ImportConfig::load()`** načte `config/import-newShipard.json`,
   provede validace, vrátí immutable config objekt. Chyba souboru →
   `ImportException` s instrukcí pro vytvoření.
6. **`HttpClient`** má GET/POST/PUT/PATCH/DELETE + `ping()`. Bearer
   token v `Authorization` header. JSON serialize/parse. 2xx success,
   non-2xx vyhodí `HttpException`. **Žádný retry v Phase 01.**
7. **`LocalIdMap`** ukládá do SQLite v `__APP_DIR__/import-newShipard.sqlite`,
   chmod 0600, schema migrace v konstruktoru. Plné API per sekce 8.
   Konstanty `ENTITY_*` pro budoucí typy.
8. **`ImportRunner`** base class poskytuje `app()` / `db()` / `config()` /
   `http()` / `idMap()` / `isDryRun()` / `isVerbose()` + output helpers
   (`info` / `ok` / `warn` / `err` / `debug`).
9. **`StatusRunner`** vypíše config (bez API klíče), úspěšně zavolá
   `GET /_meta/tables` přes HttpClient, vypíše stats z LocalIdMap.
   Návratový kód odpovídá úspěchu/neúspěchu.
10. **`README.md`** obsahuje účel, předpoklady, spuštění, seznam
    subkomand, smoke test, sekci Stav.
11. **Manuální smoke test** projde od začátku do konce per sekce 13.

Žádné automatické testy v Phase 01 — starý Shipard nemá ustavený test
framework v stylu PHPUnit jako nový Shipard. Validace je per smoke test.

## Doporučené pořadí implementace

1. **`module.json`** + prázdné `services.php` se class skeletonem.
2. **`ImportException`** + **`HttpException`** (jen třídy, žádná logika).
3. **`ImportConfig`** — load + validate + getters. Zkus načíst dummy config
   soubor a printuj raw obsah.
4. **`HttpClient`** — implementuj curl základy (GET, POST, headers),
   pak `ping()`. Otestuj ručně proti localhost (nebo proti reálnému
   novému Shipardu pokud máš accessible).
5. **`LocalIdMap`** — PDO/SQLite konstruktor + schema migrace + lookup +
   record + stats. Otestuj ručně přes `php -r`.
6. **`ImportRunner`** + **`ImportContext`** — bázová třída + DTO.
7. **`StatusRunner`** — sleduj template ze sekce 10.
8. **`ImportApp`** + dispatcher — propoj všechno dohromady.
9. **`services.php`** finalizace — `onCliAction` hook na `ImportApp`.
10. **`README.md`** — sepsání po dokončení kódu, ať reflektuje skutečnost.
11. **Smoke test** proti reálnému novému Shipardu.

Po každém kroku ověř, že:
- `shpd-app cli-action --action=imports.newShipard/import status` jde
  spustit bez fatal errors (i když selže smysluplně — `Config file not
  found.`).
- PHP nevyhodí parse error / class not found error (autoload funguje).

## Otevřené body / rozhodnutí

### 1. Verbose flag — `-v` vs `--verbose`

`parseArgs` ve `\Shipard\CLI\Application` přijímá `-v` jako short flag
(viz src/CLI/Application.php:138 — single-char short opce). Implementuj
oba: `$this->app->arg('v') || $this->app->arg('verbose')`. Test ručně,
že obojí funguje.

### 2. Output stream — stdout vs stderr

V Phase 01 jde všechno do `stdout` přes `echo`. Logicky by `[debug]` a
`!` warningy měly jít do `stderr`, ale starý Shipard tuto distinkci
nedělá konzistentně. Pro konzistenci s ostatními commandy starého
Shipardu zůstaň u `stdout`. Refactor na stderr je Phase 06 polish, pokud
vůbec.

### 3. Smoke test endpoint — `/_meta/tables` vs jiný

`/_meta/tables` jsem zvolil, protože:

- Existuje (viz `src/Api/Router.php` v `nov_shipard`).
- Vyžaduje auth (Bearer token check).
- Vrátí JSON.
- Žádný side-effect.

Alternativy:

- `/_openapi.json` — public bez auth, takže neověří klíč.
- `/_ui/navigation` — vyžaduje auth, ale vrátí "navigation" UI data,
  spíš sémantický mismatch.

Pokud při implementaci uvidíš, že `/_meta/tables` je špatná volba (např.
vrací 500 v některých konfiguracích), použij jiný auth-required GET
endpoint a doplň komentář proč.

### 4. `__APP_DIR__` resolution pro `LocalIdMap` umístění

`__APP_DIR__` je definovaná v `tools/shpd-app.php` na řádku 3 jako
`getcwd()` — tj. **adresář, ze kterého se spustil `shpd-app`**. Spuštění
přes `cli-action` z DS adresáře tedy funguje. Pokud někdo spustí
`shpd-app cli-action --action=...` z jiného adresáře, `__APP_DIR__`
ukáže jinam. To je dnes problém všech `shpd-app` commandů, ne náš —
spoléháme na konvenci.

V `ImportApp::run()` na začátku ověř, že `__APP_DIR__ . '/config/main.json'`
nebo nějaký jiný DS-marker existuje. Pokud ne → emit jasnou chybovou
zprávu `Not a Shipard data source directory.` a exit 1. Sleduj pattern
z `MailRouterSetupCommand::execute()` z nového Shipardu (kontroluje
`config/main.json`).

### 5. SQLite v testovacím prostředí

Když Claude Code implementuje, nemůže (pravděpodobně) spustit `shpd-app`
proti reálnému DS. Smoke test musí David spustit ručně po implementaci.
Claude Code ověří správnost kódu jen statickou inspekcí + `php -l` na
každý soubor (syntax check).

Pokud Claude Code má k dispozici izolované PHP prostředí, **doporučeno
napsat aspoň minimální smoke skript** v `libs/runners/`, který by
emuloval ImportContext a otestoval LocalIdMap insertion + lookup
sám. Není required pro Hotovo když, ale je užitečné.

### 6. Konfigurační JSON — JSONC vs strict JSON

`config/import-newShipard.json` je strict JSON (ne JSONC). Důvod: je
uživatelsky napsaný, často přes copy-paste, JSONC by zmátl. Pokud
budou komentáře potřeba, doplň je do `README.md`.

### 7. Wrapper script pro UX

Volání `shpd-app cli-action --action=imports.newShipard/import status`
je verbose. Hodil by se wrapper script `bin/shpd-ds-import`, který by
interně volal cli-action. **Phase 01 to nedělá** — uživatel si může
vytvořit shell alias:

```bash
alias shpd-ds-import='shpd-app cli-action --action=imports.newShipard/import'
```

Pokud se ukáže potřeba, doplníme wrapper v Phase 06 polish.

### 8. Reuse `ImportConfig` napříč DSs (`shpd-app app-walk`)

Starý Shipard má `shpd-app app-walk <command>`, který iteruje přes
všechny DSs a spustí daný command per DS. To by mohlo být užitečné pro
hromadný import více DSs. Phase 01 to však nemyslí — `ImportConfig`
předpokládá single-DS spuštění a per-DS config soubor.

Pokud se ukáže potřeba multi-DS importu, nejprve doplníme orchestraci
v shell skriptu (cyklus `for ds in dss; do cd $ds && shpd-app cli-action ...; done`),
ne v PHP modulu. Komplexita per-DS configu (různé API klíče, různé cílové
servery) tomu nahrává.

## Příprava pro Fázi 02

Po skončení Phase 01:

- `LocalIdMap::ENTITY_BANK_ACCOUNT`, `_COST_CENTER`, `_WAREHOUSE` atd.
  jsou definované konstanty — Phase 02 je začne plnit.
- `HttpClient::post()` + `put()` jsou připravené — Phase 02 přes ně
  bude volat generický CRUD nového Shipardu (`POST /api/v1/economy_codebooks_bank_accounts`,
  `PUT /api/v1/economy_codebooks_bank_accounts/<id>`).
- Pattern `runners/<EntityType>Runner.php` je etablovaný — Phase 02
  přidá `BankAccountsRunner`, `CostCentersRunner`, atd.
- Dispatcher `ImportApp::dispatch()` má TODO komentáře pro nové
  subkomandy — Phase 02 je odkomentuje a implementuje.

Phase 02 nepotřebuje znovu řešit infrastrukturu — staví na hotové bázi.
