# Task: Rate limiting v HTTP klientu (Fáze 03a)

## Kontext

Při importu osob na DS s nízkými tisíci záznamy narazil importer na rate
limit nového Shipardu. Konkrétní log:

```
[http] POST .../api/v1/_exchange/persons/person/apply (body 1185 B)
[http] → 429 (body 171 B)
✗ Failed person (old ndx=1609): HTTP 429: RATE_LIMITED — Too many requests.
  Retry after 6 seconds. | details: _retry_after [SECONDS]: 6
✗ Aborting (use --continue-on-error to skip failed rows).
```

**Limit v novém Shipardu** (`src/Api/Middleware/RateLimitMiddleware.php`):

- API klíče: **1000 requestů / 60 s** (per API klíč, ne per IP).
- Sliding window: fixní 60s buckety (`floor(time() / 60) * 60`).
- 429 response obsahuje `details._retry_after` = počet sekund do konce
  aktuálního okna.

Tj. importer s ~3000 osobami, který posílá 1 request per osoba bez pauzy,
spotřebuje limit za prvních ~30–60 sekund a server odmítne další. Při
průměru 16.7 req/s pojede import bezpečně, při burst se zasekne.

**Cíl Fáze 03a:** Doplnit `HttpClient` o:

1. **Respect `Retry-After` v 429** — automatický retry s pauzou podle
   serveru. Standard HTTP chování.
2. **Proaktivní throttling** — konfigurovatelný minimum interval mezi
   requesty (default 80 ms = ~12 req/s, bezpečně pod limitem).
3. **Exponential backoff pro 5xx + network errors** — robust transient
   error handling (drobný bonus, ale logicky patří sem).

Po dokončení musí jít:

```bash
shpd-app cli-action --action=imports.newShipard/import persons
```

běžet stabilně proti DS s tisíci osob, bez Aborting na 429. Verbose
log zobrazí retry pokusy, ale ne fatal.

**Mimo scope:**

- Zvyšování limitu v novém Shipardu — limit je obranný, ne k překonání.
  Per-IP override (`is_local_network`) by se hodil pro DevOps automatizaci,
  ale to je samostatná diskuse pro nový Shipard tým.
- Batch apply endpoint — víc osob v jednom requestu by snížilo počet
  HTTP volání, ale to je rozšíření exchange formátu (Phase 1 limit pro
  všechny tři applisty). Phase 06 follow-up.
- Distributed rate limit awareness — pokud dvě instance importu jedou
  paralelně na stejný DS, šly by si do limit. Phase 03a neřeší — jedno
  spuštění per DS.

## Před implementací přečti

Z existujícího kódu:

- **`modules/imports/newShipard/libs/HttpClient.php`** — současný stav,
  bez retry. Klíčová metoda `request()` provádí jeden curl_exec a hned
  hází exception při non-2xx.
- **`modules/imports/newShipard/libs/HttpException.php`** — žádná změna
  potřeba, `statusCode = 429` se předává jako dnes.
- **`modules/imports/newShipard/libs/ImportConfig.php`** — přidat tři
  nové getters a tři nové validations.
- **`modules/imports/newShipard/libs/ImportApp.php`** — předat nové
  parametry HttpClient konstruktoru.

Z nového Shipardu (referenční):

- **`nov_shipard:src/Api/Middleware/RateLimitMiddleware.php`** — exact
  shape 429 response. Důležité:
  - Status: 429
  - `error.code: "RATE_LIMITED"`
  - `error.details[0]._retry_after = SECONDS` (string)
  - Header `X-RateLimit-Reset: <unix-timestamp>`
  - **Žádný HTTP standard `Retry-After` header** — info je jen v body!
    Toto je odchylka od standardu, kterou musíme respektovat.

## Co implementovat

### 1. Rozšíření `HttpClient` konstruktoru

Přidat tři nové parametry, všechny s defaulty pro backwards compat:

```php
public function __construct(
    private readonly string $baseUrl,
    private readonly string $apiKey,
    private readonly int $timeout = 30,
    private readonly bool $verbose = false,
    private readonly int $throttleMs = 0,       // ← NEW: minimum interval mezi requesty
    private readonly int $maxRetries = 0,       // ← NEW: počet retry pokusů
    private readonly int $retryDelayMs = 1000,  // ← NEW: base delay pro exp. backoff
) {}
```

**Defaults `throttleMs = 0` a `maxRetries = 0`** zachovají existující
chování (no throttle, no retry). ImportApp pak předá konkrétní hodnoty
z config souboru.

### 2. Throttling

Nová private metoda `applyThrottle()` a sledování času posledního
requestu:

```php
private float $lastRequestTime = 0.0;  // microtime(true) z posledního requestu

private function applyThrottle(): void
{
    if ($this->throttleMs <= 0) {
        return;
    }
    $now = microtime(true);
    if ($this->lastRequestTime === 0.0) {
        $this->lastRequestTime = $now;
        return;
    }
    $elapsedMs = ($now - $this->lastRequestTime) * 1000;
    $waitMs = $this->throttleMs - $elapsedMs;
    if ($waitMs > 0) {
        usleep((int) ($waitMs * 1000));
    }
    $this->lastRequestTime = microtime(true);
}
```

Klíčové: měříme čas od posledního requestu, ne fixní pauza po. Pokud
PHP mezitím něco dělalo (např. načítání další osoby z DB), čekání už
běželo "samo" a `applyThrottle()` nic nepřidá. To je defenzivní design
— nepřidává zbytečné latence.

Volá se v `request()` **na začátku** (před curl_init).

### 3. Retry logic v `request()`

Refactor `request()`:

```php
private function request(string $method, string $path, ?array $body): array
{
    if ($path === '' || $path[0] !== '/') {
        throw new \InvalidArgumentException(...);
    }
    $url = $this->baseUrl . $path;

    $attempt = 0;
    while (true) {
        $this->applyThrottle();

        try {
            return $this->executeRequest($method, $url, $body);
        } catch (HttpException $e) {
            $attempt++;
            $retryAfterSeconds = $this->shouldRetry($e, $attempt);
            if ($retryAfterSeconds === null) {
                throw $e;  // no retry — fatal
            }
            if ($this->verbose) {
                fwrite(STDERR, sprintf(
                    "[http] retry %d/%d after %d s (HTTP %d: %s)\n",
                    $attempt, $this->maxRetries, $retryAfterSeconds,
                    $e->statusCode, $e->errorCode ?? '?',
                ));
            }
            sleep($retryAfterSeconds);
        }
    }
}
```

Pomocná metoda `executeRequest($method, $url, $body)` obsahuje původní
curl logiku (curl_init / setopt / exec / parse / throw). Bezstavová,
nemá retry awareness — volá se ze smyčky.

### 4. `shouldRetry` rozhodnutí

Centrální rozhodnutí, jestli a po jak dlouhé pauze retry. Vrací počet
sekund pro sleep, nebo `null` pokud retry není namístě.

```php
/**
 * Vrátí počet sekund pro sleep před retry, nebo null pokud retry odmítáme.
 */
private function shouldRetry(HttpException $e, int $attemptNumber): ?int
{
    if ($attemptNumber > $this->maxRetries) {
        return null;
    }

    // 429 Rate Limited — preferuj _retry_after z body, fallback exp. backoff
    if ($e->statusCode === 429) {
        $retryAfter = $this->parseRetryAfterSeconds($e);
        if ($retryAfter !== null) {
            return min($retryAfter, 60);  // cap na 60s, ať nečekáme věčně
        }
        return $this->exponentialBackoffSeconds($attemptNumber);
    }

    // 5xx Server Error — exp. backoff
    if ($e->statusCode >= 500 && $e->statusCode < 600) {
        return $this->exponentialBackoffSeconds($attemptNumber);
    }

    // Network errors (statusCode = 0) — exp. backoff
    if ($e->statusCode === 0) {
        return $this->exponentialBackoffSeconds($attemptNumber);
    }

    // 4xx (kromě 429) — fatal, žádný retry
    return null;
}

private function exponentialBackoffSeconds(int $attempt): int
{
    // 1s, 2s, 4s, 8s, ...
    $base = (int) ceil($this->retryDelayMs / 1000);
    return min($base * (2 ** ($attempt - 1)), 30);
}

private function parseRetryAfterSeconds(HttpException $e): ?int
{
    if ($e->responseBody === null) {
        return null;
    }
    // Shape: { error: { details: [{ field: "_retry_after", code: "SECONDS", message: "6" }] } }
    $details = $e->responseBody['error']['details'] ?? null;
    if (!is_array($details)) {
        return null;
    }
    foreach ($details as $detail) {
        if (($detail['field'] ?? null) === '_retry_after') {
            $value = (int) ($detail['message'] ?? '0');
            return $value > 0 ? $value : null;
        }
    }
    return null;
}
```

**Klíčový design point:** `_retry_after` je v `error.details[]`, **ne**
v HTTP header `Retry-After`. To je rozhodnutí v novém Shipardu — pro
Phase 03a respektujeme realitu, ne RFC. (Otevřený bod: doplnit do
nového Shipardu `Retry-After` header? Out of scope, viz Open Issue 1.)

### 5. ImportConfig — nové klíče

V **`libs/ImportConfig.php`** přidat:

#### 5.1 Nové getters

```php
public function throttleMs(): int      // default 80
public function maxRetries(): int      // default 3
public function retryDelayMs(): int    // default 1000
```

#### 5.2 Validace v `load()`

Přidat do existující validation sekce:

- `target.throttleMs` — integer 0–10000, default 80.
- `target.maxRetries` — integer 0–10, default 3.
- `target.retryDelayMs` — integer 100–60000, default 1000.

Pokud klíče v config souboru chybí, použít defaults bez warning.

#### 5.3 Příklad rozšíření config souboru

V README a v error message pro chybějící config doplnit:

```jsonc
{
    "target": {
        "baseUrl": "https://<host>/api/v1",
        "apiKey": "shpd_ak_...",
        "timeout": 30,
        "throttleMs": 80,        // ← NEW: pauza mezi requesty v ms (default 80)
        "maxRetries": 3,         // ← NEW: počet retry pokusů (default 3)
        "retryDelayMs": 1000     // ← NEW: base delay pro exp. backoff v ms (default 1000)
    },
    "options": {
        "verbose": false,
        "dryRun": false,
        "batchSize": 100
    }
}
```

### 6. ImportApp — předávání parametrů

V `ImportApp::run()` při instanciaci `HttpClient`:

```php
$this->httpClient = new HttpClient(
    baseUrl:      $this->config->targetBaseUrl(),
    apiKey:       $this->config->targetApiKey(),
    timeout:      $this->config->timeout(),
    verbose:      $this->config->verbose() || (bool) $this->app->arg('verbose') || (bool) $this->app->arg('v'),
    throttleMs:   (bool) $this->app->arg('no-throttle') ? 0 : $this->config->throttleMs(),
    maxRetries:   $this->config->maxRetries(),
    retryDelayMs: $this->config->retryDelayMs(),
);
```

**CLI flag `--no-throttle`** dovoluje vypnout throttling pro testování
(když chceš ručně zjistit, kde se rate limit projeví). `--max-retries`
override pro CLI Phase 03a nedoplňujeme — Open Issue.

### 7. Update `printUsage()`

Přidat do společných opcí:

```
  --no-throttle              Disable client-side throttling (for testing).
```

### 8. README aktualizace

V `modules/imports/newShipard/README.md`:

#### 8.1 Sekce Konfigurace — přidat 3 nové klíče

Doplnit do tabulky / textu o `throttleMs`, `maxRetries`, `retryDelayMs`
s vysvětlením.

#### 8.2 Sekce Společné opce — `--no-throttle`

#### 8.3 Sekce "Behavior — rate limiting" (nová)

Stručná pasáž (~200 slov) o tom, jak klient reaguje na 429:

- Proaktivní throttling default 80 ms (= ~12 req/s).
- Při 429 čeká `_retry_after` sekund (z body), max 60 s, max `maxRetries`x.
- Při 5xx / network exp. backoff (1s, 2s, 4s, …, max 30 s).
- Při překročení `maxRetries` runner zafailuje per row (continue-on-error
  nebo abort).

## Hotovo když

1. **`HttpClient`** má tři nové konstruktor parametry (`throttleMs`,
   `maxRetries`, `retryDelayMs`) s defaulty 0/0/1000 pro backwards compat.
2. **Throttling** funguje: `applyThrottle()` se zavolá před každým
   requestem, drží minimum interval. Měřeno přes `microtime(true)`.
3. **Retry logic** v `request()`:
   - 429 → parse `_retry_after` z body, sleep, retry. Fallback exp. backoff
     pokud `_retry_after` chybí.
   - 5xx / network (statusCode=0) → exp. backoff retry.
   - 4xx (kromě 429) → fatal, žádný retry.
   - Max `maxRetries` retry pokusy. Pak vyhodí HttpException jako dosud.
4. **Verbose logging** retry pokusů na stderr (`[http] retry 1/3 after 6 s ...`).
5. **`ImportConfig`** má getters `throttleMs()`, `maxRetries()`, `retryDelayMs()`
   s defaults 80/3/1000 a validacemi v `load()`.
6. **`ImportApp`** předává nové parametry do `HttpClient` konstruktoru.
7. **`--no-throttle`** CLI flag funguje (přepíše config throttleMs na 0).
8. **README** dokumentuje rate limiting chování a nové konfigurační klíče.
9. **Test manuálně:** `import persons` na DS `68908901448295` (tisíce
   osob) musí proběhnout bez Aborting na 429. Verbose log ukáže občasné
   retry, ale celkový průběh stabilní.
10. **Test manuálně:** `import persons --no-throttle` znovu spustí
    rychleji, ale s vyšší pravděpodobností retry pokusů — pořád musí
    doběhnout úspěšně (retry logic to ošetří).
11. **Backwards compat:** `import status` (Fáze 01) i `import all-codebooks`
    (Fáze 02) pojedou beze změny — `HttpClient` s default parametry
    chová stejně jako před patchem.

## Doporučené pořadí implementace

1. **`HttpClient` parametry + throttle** — drobná úprava konstruktoru a
   nová metoda `applyThrottle()`. Test: spustit `import status` (1 request,
   throttle by neměl nic přidat).
2. **`HttpClient` retry logic** — refactor `request()` na smyčku +
   pomocné metody. Test: simulovat 429 přes manuálně sestavený scénář.
3. **`ImportConfig` getters + validace** — přidat tři klíče. Test: spustit
   `import status` s a bez klíčů v config souboru.
4. **`ImportApp` wire-up** — předat parametry konstruktoru.
5. **`--no-throttle` CLI flag** — v `ImportApp::run()` přepsat hodnotu.
6. **README update** — sekce Konfigurace + Společné opce + Behavior.
7. **End-to-end test** — `import persons` na velký DS, ověřit stabilitu.

## Otevřené body / rozhodnutí

### 1. `Retry-After` HTTP header v novém Shipardu

Standard RFC 7231 §7.1.3 definuje `Retry-After` header pro 429 odpovědi.
Nový Shipard ho **nevrací** — info je jen v `error.details[]._retry_after`.

Phase 03a respektuje realitu (parsuje body). Pokud by se v budoucnu
nový Shipard rozhodl přidat header, `HttpClient` ho **může** preferovat
jako primární zdroj — drobná úprava v `parseRetryAfterSeconds()`. Pro
Phase 03a to nedělej.

Pokud bys chtěl doplnit `Retry-After` header v novém Shipardu, je to
samostatný drobný task pro nový Shipard (úprava `RateLimitMiddleware`
+ `Response` třídy). **Mimo scope Phase 03a.**

### 2. Throttle ms — proč 80 ms

Limit je 1000 req / 60 s = 16.7 req/s = 60 ms / req minimum.

Default `throttleMs = 80` (= 12.5 req/s) má 25% rezervu. Pokud:

- DS importu má víc paralelních klientů → 80 ms není dost. Phase 03a
  není distributed-aware.
- Server overhead (DB lock, slow query) způsobí, že request trvá > 80 ms
  vlastně → throttle "samo" sníží efektivní rate.

V praxi 80 ms = ~12 req/s = 5000 osob za ~7 minut. Akceptovatelné. Pokud
bys chtěl rychleji, snížíš na 60 ms a spolehni se na retry logic.

### 3. Max retries cap a max sleep cap

Default `maxRetries = 3`. Sequence pro 429 s `_retry_after = 6`: čekej 6,
retry; čekej 6, retry; čekej 6, retry; fatal. **Celkový max wait = 18s
+ 3 requesty.**

Pro 5xx / network exp. backoff: 1s, 2s, 4s. **Celkový max = 7s + 3 requesty.**

Cap `max(retry_after) = 60s` chrání proti server malice (kdyby vrátil
`_retry_after: 3600`). Cap `max(exp_backoff) = 30s` chrání proti
zacyklení.

Tyto čísla nejsou v PRD konfigurovatelná — hardcoded v `HttpClient`.
Pokud se ukáže potřeba ladit, doplní se.

### 4. `--max-retries` CLI flag

Phase 03a doplňuje jen `--no-throttle`. CLI override `--max-retries`
ne — uživatel ladí přes config soubor. Pokud bys chtěl rychlé experimentování
přes CLI, dodáme v Phase 06.

### 5. Retry-After v `429` přes header (RFC mode)

`HttpClient` momentálně neukládá response headers — jen status code +
body. Pokud bychom chtěli RFC-compatible `Retry-After` header support,
muselo by se přidat:

- `CURLOPT_HEADER = true` nebo `CURLOPT_HEADERFUNCTION` callback.
- Parsing header z response.
- Preferred v `parseRetryAfterSeconds()`.

Phase 03a to **nedělá** — body parsing stačí. Pokud se ukáže potřeba
(jiný server v budoucnu), je to drobná úprava.

### 6. Per-Endpoint throttle

Generic CRUD (codebooks) a exchange flow (persons/items/docs) mohou mít
různou citlivost na throttle. Phase 03a používá **single global throttle**
pro všechny endpointy.

V praxi codebooks jsou rychlé (< 100 řádků typicky), takže throttle se
téměř neuplatní. Persons/items/docs jsou problém. Single throttle 80 ms
je univerzální kompromis.

Pokud by se ukázalo, že některý endpoint má jiný rate limit (Phase 06+
endpoints), doplníme per-endpoint mapování.

### 7. Throttle vypnutí pro CRUD subkomandy

Argument: `import bank-accounts` má max desítky requestů. Throttle 80 ms
je zbytečný (přidá < 1 sekundu, ale i tak je to overhead).

Phase 03a to **nezohledňuje** — throttle je global. Pokud bude potřeba,
runner sám může nastavit `httpClient->setThrottle(0)` před during, ale
to znamená mutable state v HttpClient (dnes je immutable). Nedoporučeno.

Alternativa: per-runner throttle override v konfiguraci. Phase 06.

### 8. Logování retry do souboru

Verbose `--verbose` emituje retry info na **stderr**. To může být
problém pro long-running import (zápis v terminálu mizí). Phase 03a
nedoplňuje persistent log soubor.

Pokud by se ukázalo potřeba, runner může redirect stderr do souboru:

```bash
shpd-app cli-action --action=imports.newShipard/import persons -v 2>import.log
```

Doplnit do README jako tip. Phase 06 doplní real persistent logging.

## Vztah k pozdějším fázím

Po Phase 03a:

- **Fáze 04 (items)** automaticky dědí retry + throttle přes
  `BaseExchangeRunner` (žádná změna v itemsRunner potřeba).
- **Fáze 05 (docs)** automaticky dědí. Pro tisíce dokladů to bude
  klíčové.
- **Fáze 06 polish** — pokud bude potřeba batch apply endpoint (víc
  entit v jednom requestu), throttle/retry se nezruší — bude potřeba
  ještě méně requestů, ale stejný mechanismus.
