# Shipard — Architektura

## 1. Přehled vrstev

```
┌─────────────────────────────────────────────────┐
│  REST API vrstva (public/index.php)             │
│  src/Api/                                       │
├─────────────────────────────────────────────────┤
│  CLI vrstva (bin/shpd-server, bin/shpd-ds)      │
│  src/Command/                                   │
├─────────────────────────────────────────────────┤
│  Modulový systém          │  Konfigurace        │
│  src/Core/Module/         │  src/Core/Config/   │
├───────────────────────────┤                     │
│  Databázová vrstva        │  I18n               │
│  src/Core/Database/       │  src/Core/I18n/     │
├─────────────────────────────────────────────────┤
│  Utility                                        │
│  src/Core/Utils/                                │
└─────────────────────────────────────────────────┘
```

Závislosti tečou shora dolů. Nižší vrstvy nikdy nezávisí na vyšších.

---

---

## 2. REST API vrstva (`src/Api/`)

Zpracovává HTTP požadavky příchozí na `public/index.php`. Každá subdoména je mapována na jeden zdroj dat přes `domains.json`.

### Pipeline požadavku

```
HTTP request
  → Request::fromGlobals()
  → CorsMiddleware (OPTIONS → 204, ostatní pokračují)
  → ServerConfig + DataSourceResolver (subdoména → DS)
      → kontrola config/state.json před připojením k DB
        (suspended / maintenance / pending_deletion → 503 DS_UNAVAILABLE + Retry-After)
  → TableLoader (načtení definic tabulek pro DS)
  → Router (path + method → Route)
  → AuthMiddleware (Bearer token → AuthContext)
  → ReadOnlyPolicy — jen při efektivním stavu read_only
      (mutace → 403 DS_READ_ONLY, strojový ingest → 503, neznámá routa fail-closed 403)
  → RateLimitMiddleware (kontrola limitu, X-RateLimit-* hlavičky)
  → Controller dispatch
  → CorsMiddleware.applyTo() + rate-limit hlavičky
  → Response.send()
```

### HTTP abstrakce

| Třída | Účel |
|-------|------|
| `Request` | Immutabilní obal HTTP požadavku. Factory `fromGlobals()` a `fromArray()` pro testování. `getHeader()` case-insensitive, `getClientIp()` respektuje `X-Forwarded-For`. |
| `Response` | JSON obálka `{success, data/error, meta}`. Immutabilní — `withHeader()` vrací novou instanci. `send()` nastaví HTTP kód, Content-Type a vypíše tělo (přeskočí pro 204). |
| `Route` | Readonly datová třída: `controller`, `action`, `?table`, `?id`. |
| `Router` | Mapuje path + metodu na `Route`. Speciální endpointy (`_meta`, `_openapi`, `_auth`) před generickým `{table}`. Neznámá URL → 404, nepovolená metoda → 405. |

### Resolvery

| Třída | Účel |
|-------|------|
| `DataSourceResolver` | Načte `domains.json`, přeloží hostname na `DataSourceConfig` + `DataSourceConnection`. Hází `UnknownHostException` pro neznámou subdoménu. Před vytvořením connection čte `config/state.json` (`Core\Config\DataSourceState`) a pro zavřený DS hází `DataSourceUnavailableException` (`dsId`, `effectiveState`, `maintenanceReason`) → `index.php` 503. Viz [ds-state.md](ds-state.md). |
| `ResolvedDataSource` | Readonly `DataSourceConfig` + `DataSourceConnection` + `DataSourceState` (`state`, `isReadOnly()`) — stav načtený resolverem cestuje pipeline (politika, `/_app/info`, MCP, navigace). |
| `ReadOnlyPolicy` | Tabulka `(controller, action)` → `ReadOnlyVerdict` (`Allow` / `Deny403` / `Deny503`) pro DS ve stavu `read_only`; neznámá kombinace = `Deny403` (fail-closed). Volá `index.php` krok 6.5 za auth. Viz [ds-state.md](ds-state.md) §6. |
| `TableLoader` | Z `DataSourceConfig` a `modulesBasePath` sestaví `array<string, TableDefinition>` pro všechny aktivní moduly DS (s extensions, lokalizovaný). |

### Middleware

| Třída | Účel |
|-------|------|
| `CorsMiddleware` | OPTIONS → 204 s CORS hlavičkami. `applyTo(Response)` přidá hlavičky k libovolné odpovědi. Povolená doména: `https://*.shipard.cz`. |
| `AuthMiddleware` | Ověřuje Bearer token. API klíče (`shpd_ak_`): SHA-256 lookup v DB, kontrola expirace, is_active, IP allowlist, update last_used_at. Session tokeny (`shpd_st_`): kontrola expirace. Vrací `AuthContext`. Exempt: login, OIDC routy (`/_auth/oidc/*` — flow běží před vznikem tokenu), veřejné `/_app` GET. Viz `docs/auth.md`. |
| `RateLimitMiddleware` | Okno 60 s. Limity: 1000 (api_key), 300 (session), 10 (login-class per IP: login, oidcStart, oidcExchange), 60 (anon). Ukládá do `core_system_rate_limits`. Nastavuje `X-RateLimit-Limit/Remaining/Reset`. |

### Kontrolery

| Třída | Účel |
|-------|------|
| `AuthController` | `login` (password_verify → session token), `refresh` (nový token), `logout` (204). Session TTL 86400 s. |
| `CrudController` | Univerzální CRUD pro libovolnou tabulku. `list` (filtry, řazení, stránkování), `show`, `create`, `update`, `patch`, `delete`. Filtrovací operátory: eq/neq/gt/gte/lt/lte/like/in/null/notnull. Password sloupce jsou odstraněny z výstupu. |
| `MetaController` | `tables` (seznam tabulek s metadaty), `table` (detail sloupců + indexů). Lokalizovaný výstup. |
| `OpenApiController` | `spec` → OpenAPI 3.1 JSON generovaný ze `SpecGenerator`. Podmíněný přístup dle `openApiPublic`. |
| `DashboardController` | `dashboard` → `GET /_ui/dashboard` — feed akčních karet `{summary, cards[]}`. Karty agregují napevno registrované `FeedSource` zdroje (`MailSuggestionsSource`, `AlertsSource`) přes sdílené `collectCards()`, `sortAndCap` řadí dle kind + času. `summary` → `GET /_ui/dashboard/summary` — SSE stream generovaného AI shrnutí feedu (`DashboardSummaryService`: digest/hash cache v `core_ai_dashboard_summary`, LLM přes `AiBackendResolver` + `LlmClient`). Detaily viz [`dashboard.md`](dashboard.md). |

### Validace

| Třída | Účel |
|-------|------|
| `InputValidator` | Validuje vstupní data oproti `TableDefinition`. Mód `create` — kontroluje povinná pole. Mód `patch` — validuje jen přítomná pole. Automatické sloupce (`id`, `created`, `modified`) ignorovány. |

### Generátory

| Třída | Účel |
|-------|------|
| `SpecGenerator` | Generuje OpenAPI 3.1 spec ze `TableDefinition[]`. Fixní cesty (auth, meta, openapi) + 6 CRUD cest per tabulku. 4 schémata per tabulku (`_item`, `_create`, `_list_response`, `_single_response`). |

**Tok dat pro CRUD požadavek:**
```
GET /api/v1/economy_docs_invoices?filter[status][eq]=open
  → Router → Route(crud, list, table=economy_docs_invoices)
  → AuthMiddleware → AuthContext(isAuthenticated=true, tokenType=api_key)
  → RateLimitMiddleware → null (pokračuje)
  → CrudController.list()
    → InputValidator (query params)
    → DataSourceConnection.fetchAll()
  → Response::success(data, 200, meta{total, limit, offset})
  → applyAllHeaders (CORS + X-RateLimit-*)
  → send()
```

---

## 3. Utility (`src/Core/Utils/`)

Bezstavové pomocné třídy bez závislostí na zbytku systému.

| Třída | Účel |
|-------|------|
| `JsoncParser` | Parsování JSONC souborů (strip komentářů, trailing čárek) → `json_decode`. Statické metody `parse()` a `parseFile()`. |
| `IdGenerator` | Generování ID zdrojů dat (`xxxx-xxxx-xxxx-xxxx`, a-z0-9). Kryptograficky bezpečný (`random_int`). |

---

## 4. I18n (`src/Core/I18n/`)

Vícejazyčnost — rozbalení polí se suffixem `:lang`.

| Třída | Účel |
|-------|------|
| `LocalizedFieldResolver` | Resolví jedno vícejazyčné pole. Fallback: `field:XX` → `field:en` → `field` (holé). |
| `ConfigLocalizer` | Rekurzivně projde celou datovou strukturu a aplikuje `LocalizedFieldResolver` na všechna vícejazyčná pole. Odstraní `:lang` suffixy z výstupu. |
| `DocumentLanguageResolver` | Jazyk dokumentu pro partnera (#94): jazyk osoby → hlavní jazyk země strany → hlavní jazyk vlastní země; mimo jazyky dokumentů `en`. Čistá služba nad cfgItemy `world.base.documentLanguages` / `countries`, používá ji tisk (`docs/prints.md` §3). |

**Tok dat:**
```
JSONC soubor s "name", "name:cs", "name:en"
  → ConfigLocalizer.localize(data, "cs")
  → výstup obsahuje jen "name" s českou hodnotou
```

---

## 5. Konfigurace (`src/Core/Config/`)

| Třída | Účel |
|-------|------|
| `ServerConfig` | Načtení a validace `/etc/shipard/server.json` (DB credentials, mód nasazení). |
| `DataSourceConfig` | Načtení a validace `config/main.json` zdroje dat (ID, DB credentials, moduly, defaultLanguage). Volitelná pole: `defaultLanguage` (default `en`), `skipProvisioning` (bool, default `false` — dočasně vypne auto-provisioning v `ds-upgrade`; viz `docs/cli.md`), `enableReset` (bool, default `false` — povolí `ds-reset` na produkčním serveru pro testovací DS; viz `docs/cli.md`). |
| `ConfigCompiler` | Kompilace konfiguračních souborů ze všech aktivních modulů. Generuje `compiled.{lang}.json` pro každý jazyk. Používá `ConfigLocalizer`. |
| `ConfigRuntime` | Runtime přístup ke zkompilované konfiguraci. Metoda `cfgItem(id)` vrací konfigurační položku. |

**Tok dat při kompilaci:**
```
module.jsonc → config[].file → JSONC soubory
  → JsoncParser → ConfigLocalizer (per jazyk)
  → compiled.<jazyk>.json (cs, en + jazyky dokumentů sk, de)
```

**Tok dat za běhu:**
```
compiled.cs.json → ConfigRuntime::load() → cfgItem('economy.docs.vatRates')
```

---

## 6. Modulový systém (`src/Core/Module/`)

| Třída | Účel |
|-------|------|
| `ModuleDefinition` | Datová třída pro `module.jsonc` — id, name, dependencies, tables, extensions, config. Factory `fromArray()`. |
| `ModuleLoader` | Načítání modulů ze souborového systému. `loadModule(path)` pro jeden modul, `loadAllModules(basePath)` pro všechny. Prochází `modules/{skupina}/{modul}/`. |
| `ModuleResolver` | Řešení závislostí. Vstup: všechny moduly + aktivované ID. Výstup: topologicky seřazené moduly. Detekuje cykly (DFS), řeší tranzitivní závislosti. |

**Tok dat:**
```
modules/{skupina}/{modul}/module.jsonc
  → ModuleLoader.loadAllModules()
  → ModuleResolver.resolve(definitions, activeIds)
  → seřazené pole ModuleDefinition[]
```

---

## 7. Databázová vrstva (`src/Core/Database/`)

### Definice schématu

| Třída | Účel |
|-------|------|
| `ColumnDefinition` | Datová třída pro sloupec — id, type, length, precision, scale, nullable, default, cfgItem, group atd. Validace podle typu. |
| `IndexDefinition` | Datová třída pro index — id, type (index/unique/fulltext), columns s order (ASC/DESC). |
| `TableDefinition` | Datová třída pro tabulku — tableId, name, columnGroups, columns, indexes. Factory `fromArray()`. Validace PK. |
| `ExtensionDefinition` | Datová třída pro extension — table (cílová), columns, columnGroups, indexes. Factory `fromArray()`. |
| `TableMerger` | Sloučení `TableDefinition` + `ExtensionDefinition`. Přidá sloupce, indexy, columnGroups. Detekuje kolize. |

### Správa schématu

| Třída | Účel |
|-------|------|
| `SchemaComparator` | Porovná `TableDefinition` s existujícím stavem DB. Vrací seznam operací (create_table, add_column, modify_column, create_index). Rozpozná bezpečné změny. |
| `SchemaValidator` | Validace definic před upgrade — duplicitní tableId, chybějící PK, kolize sloupců/indexů. Vrací chyby (fatální) a varování. |
| `SqlGenerator` | Generování SQL z definic — CREATE TABLE, ALTER TABLE ADD/MODIFY, CREATE INDEX. Ošetřuje specifika typů (enumString → CHAR + ASCII, numeric → NUMERIC(p,s), boolean → TINYINT(1)). |
| `DatabaseManager` | Správa DB na úrovni serveru — vytváření/mazání databází a uživatelů. Používá admin credentials ze ServerConfig. |
| `DataSourceConnection` | Připojení k DB konkrétního zdroje dat přes Dibi. Používá credentials z DataSourceConfig. |

**Tok dat při ds-upgrade:**
```
TableDefinition + ExtensionDefinition
  → TableMerger.merge()
  → SchemaValidator.validate()
  → SchemaComparator.compare(definition, existingDB)
  → SqlGenerator.generateXxx()
  → Dibi execute
```

---

## 8. Číslování (`src/Core/Numbering/`)

Společný engine čísel pro záznamy v číselných řadách (#110 D17): dnes
doklady (`docs.core`), dál číselné řady zakázek (`economy.workOrders`).
Jádro nezná fiskální roky ani typy dokladů — rozsah čítače a doménové
placeholdery dodává volající. Čítače zůstávají per doména (vlastní
tabulka), engine je parametrizovaný popisem tabulek.

| Třída | Účel |
|-------|------|
| `NumberPattern` | Vzorec čísla — `validate()` (prázdný vzorec, `%C` bez kódu řady, neznámý placeholder; texty a kódy chyb jsou kontrakt formuláře řady) a `resolve()` nad `NumberContext`. Obecné placeholdery `%C`, `%y` / `%Y`, `%3`–`%6`; doménové (`%D` typ dokladu) jako mapa znak → hodnota. Neznámý placeholder zůstává literál. |
| `NumberContext` | Readonly hodnoty pro vyhodnocení: pořadí, kód řady, popisek roku (string nebo líná closure — u dokladů dotaz do fiskálních let až po přidělení pořadí, nejvýš jednou), doménové hodnoty. |
| `SequenceStorage` | Readonly popis tabulky čítačů (tabulka, sloupec řady, rozsahu, hodnoty) a tabulky záznamů (tabulka, sloupec řady, rozsahu, pořadí). Identifikátory jdou do SQL jako `[name]`, konstruktor je omezuje na `[a-z][a-z0-9_]*`. |
| `SequenceCounter` | `next()` (`INSERT IGNORE` → `SELECT … FOR UPDATE` → `UPDATE`), `syncImported()` (`GREATEST`, idempotentní, nezávislé na pořadí), `maxSequence()` (guard „poslední v řadě“ nad tabulkou záznamů), `release()` (snížení jen když čítač stojí na uvolňovaném pořadí). Rozsah NULL-safe (`<=>`). Vlastní transakce jen když volající nedrží vnější (`Document::$externalTransaction`). Zápisy přes executor (výchozí `Connection::query`; doklad předává své `executeSql`, testovací šev). |

**Doklady** (`modules/docs/core/src/DocDocument.php`): `assignDocumentNumber`
načte řadu, určí rozsah (`reset_scope` = `fiscal_year` → id fiskálního roku
přes `FiscalYearLookup::yearIdForDate` z `economy.codebooks`, jinak NULL),
vezme pořadí z `next()` a číslo z `resolvePattern()` (`%D` = `doc_id_code`
typu, popisek roku `FiscalYearLookup::yearLabel`). `applyImportNumber` →
`syncImported`, `releaseDocumentNumber` → `maxSequence` + `release`,
`filterStateTransitions` → `maxSequence`. Kdy se číslo přiděluje
(`{0, 10} → 40`) a uvolňuje (`80 → 10`), placeholder `!id` po uvolnění
a mazání snapshotů zůstávají v dokladu. `NumberSeriesDocument::validate`
mapuje chyby `NumberPattern::validate` na sloupce `doc_number_pattern` /
`doc_number_code`. Tabulky: `modules/docs/core/tables/docs_core_number_series.md`,
`docs_core_number_counters.md`.

**Mimo engine:** inventární čísla majetku (`AssetNumberAllocator` — prefix
druhu + pořadí bez řady a čítače, #110 N3).

---

## 9. CLI příkazy (`src/Command/`)

### Server (`src/Command/Server/`)

| Příkaz | Třída | Popis |
|--------|-------|-------|
| `shpd-server version` | `VersionCommand` | Zobrazí verzi |
| `shpd-server help` | `HelpCommand` | Nápověda |
| `shpd-server ds-create` | `DsCreateCommand` | Vytvoří nový zdroj dat (adresář, DB, uživatel, config) |
| `shpd-server server-init` | `ServerInitCommand` | Inicializace serveru |
| `shpd-server next-table-id` | `NextTableIdCommand` | Projde moduly, vypíše další volné tableId |
| `shpd-server domain-add` | `DomainAddCommand` | Přidá mapování host → DS ID do `domains.json` |
| `shpd-server domain-list` | `DomainListCommand` | Vypíše tabulku host → DS ID → DS name |
| `shpd-server domain-remove` | `DomainRemoveCommand` | Odstraní mapování z `domains.json` |

### Data Source (`src/Command/DataSource/`)

| Příkaz | Třída | Popis |
|--------|-------|-------|
| `shpd-ds version` | `VersionCommand` | Zobrazí verzi |
| `shpd-ds help` | `HelpCommand` | Nápověda |
| `shpd-ds ds-upgrade` | `DsUpgradeCommand` | Kompilace konfigurace + kontrola/aktualizace DB schématu |

**DsUpgradeCommand** je nejkomplexnější příkaz — orchestruje celý flow:
1. `DataSourceConfig` → načte main.json
2. `ModuleLoader` → načte všechny moduly
3. `ModuleResolver` → vyřeší závislosti, topologické řazení
4. `TableDefinition` + `TableMerger` → sestaví definice tabulek s extensions
5. `ConfigCompiler` → zkompiluje konfigurace per jazyk
6. `SchemaValidator` → validace
7. `SchemaComparator` + `SqlGenerator` → DB změny

---

## 10. Mapa závislostí mezi třídami

```
JsoncParser ←── ModuleLoader
             ←── ConfigCompiler
             ←── TableDefinition (načítání z JSONC)

LocalizedFieldResolver ←── ConfigLocalizer ←── ConfigCompiler

ModuleDefinition ←── ModuleLoader ←── ModuleResolver

ColumnDefinition ─┐
IndexDefinition  ─┼── TableDefinition ←── TableMerger ←── TableLoader
                  │                    ←── SchemaComparator
ExtensionDefinition ──── TableMerger

SchemaComparator ─┐
SqlGenerator     ─┼── DsUpgradeCommand
SchemaValidator  ─┤
ConfigCompiler   ─┤
ModuleResolver   ─┤
ModuleLoader     ─┘

ServerConfig ←── DsCreateCommand, ServerInitCommand
DataSourceConfig ←── DsUpgradeCommand
             ←── DataSourceConnection ←── DataSourceResolver ←── index.php
             ←── TableLoader ←── index.php
DatabaseManager ←── DsCreateCommand

NumberContext ←── NumberPattern ←── DocDocument (resolvePattern)
                                ←── NumberSeriesDocument (validate)
SequenceStorage ←── SequenceCounter ←── DocDocument (sequenceCounter)
FiscalYearLookup (economy.codebooks) ←── DocDocument

Request ──────────────────────────────────┐
AuthContext ←── AuthMiddleware            │
Route ←── Router ←── index.php ──────────┤
Response ←── Controller/* ←── index.php  │
                                          ↓
CorsMiddleware, RateLimitMiddleware ←── index.php
InputValidator ←── CrudController
SpecGenerator  ←── OpenApiController
```

---

## 11. Konvence v kódu

### Datové třídy (Definition)
- Readonly properties nebo gettery
- Factory metoda `fromArray(array $data): self` pro vytvoření z parsovaného JSONC/JSON
- Validace v konstruktoru nebo factory metodě
- Žádná business logika

### Příkazy (Command)
- Dědí ze Symfony Console `Command`
- Testovatelné přes subclassing (přetížení závislostí pro mockování)
- Formátovaný výstup přes Symfony Console output

### Testování
- Unit testy v `tests/Unit/`, zrcadlí `src/`
- DB-dependent testy: mockování přes reflexi nebo subclassing
- Fixture data: inline v testech nebo v `tests/fixtures/`
