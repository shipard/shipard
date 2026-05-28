# Task: Import číselníků (Fáze 02)

## Kontext

Fáze 02 doplňuje deset per-typ subkomand pro import číselníků ze starého
Shipardu do nového. Staví na infrastruktuře z Fáze 01 (`ImportApp`,
`HttpClient`, `LocalIdMap`, `ImportRunner` base class).

**Cíl Fáze 02:** Funkční idempotentní import všech číselníků přes generický
REST CRUD nového Shipardu. Po dokončení musí jít:

```bash
shpd-app cli-action --action=imports.newShipard/import vat-registrations
shpd-app cli-action --action=imports.newShipard/import fiscal-years
shpd-app cli-action --action=imports.newShipard/import bank-accounts
shpd-app cli-action --action=imports.newShipard/import cost-centers
shpd-app cli-action --action=imports.newShipard/import warehouses
shpd-app cli-action --action=imports.newShipard/import cash-desks
shpd-app cli-action --action=imports.newShipard/import number-series
shpd-app cli-action --action=imports.newShipard/import item-kinds
shpd-app cli-action --action=imports.newShipard/import all-codebooks
```

Každý subkomand musí být:

- **Idempotentní** — opakované spuštění aktualizuje existující záznamy
  (přes LocalIdMap mapování), nevytvoří duplicity.
- **Logovaný** — pro každý záznam vypsat status `created` / `updated` /
  `skipped` (a důvod).
- **Dry-run aware** — `--dry-run` flag vypíše plán bez REST volání.
- **Verbose aware** — `-v` / `--verbose` flag emituje request/response detaily.

**Mimo scope Fáze 02:**

- Osoby (Fáze 03), položky (Fáze 04), doklady (Fáze 05). Tyto jdou přes
  exchange formát, ne generický CRUD — jiný typ runneru.
- `all` orchestrátor přes všechny typy (codebooks + persons + items + docs).
  Fáze 02 dělá jen `all-codebooks` — kompletní `all` přijde ve Fázi 06
  jako polish, kdy budou všechny dílčí runnery hotové.
- VAT periods pro **future roky** — to dělá `VatPeriodsProvisioner` v
  novém Shipardu automaticky. Fáze 02 importuje **jen historii** (řádná
  období ze starého `taxperiods`).
- Validace, že se "vše uloží" — REST CRUD vrací 4xx pokud payload nevalidní;
  runner respektuje, ale neopravuje. Manuální oprava dat v starém DS je
  out-of-scope.

## Před implementací přečti

Z infrastruktury Fáze 01 (musí být hotové):

- **`modules/imports/newShipard/libs/ImportApp.php`** — dispatcher, kam
  přibydou nové case-y.
- **`modules/imports/newShipard/libs/HttpClient.php`** — máme GET/POST/PUT/PATCH/DELETE.
  Phase 02 přidá tenkou `CrudClient` třídu nad HttpClient (per-table
  helpers `createRecord` / `updateRecord` / `findRecord` se znalostí
  konvencí endpointu `/api/v1/{table}` resp. `/api/v1/{table}/{id}`).
- **`modules/imports/newShipard/libs/LocalIdMap.php`** — API už máme.
  Phase 02 začne plnit `ENTITY_*` konstanty.
- **`modules/imports/newShipard/libs/ImportRunner.php`** — base class.
  Phase 02 přidá `BaseCodebookRunner extends ImportRunner` jako další
  base layer specifický pro generic-CRUD codebook import.

REST API nového Shipardu — generický CRUD (viz `nov_shipard:src/Api/Router.php`):

- `GET    /api/v1/{table}`        — list
- `POST   /api/v1/{table}`        — create (vrátí `{success: true, data: {...}, id: N}`)
- `GET    /api/v1/{table}/{id}`   — show
- `PUT    /api/v1/{table}/{id}`   — full update
- `PATCH  /api/v1/{table}/{id}`   — partial update (preferujeme pro update)
- `DELETE /api/v1/{table}/{id}`   — delete (NE-používáme, soft-delete jen v UI)

Per-tabulkové schémata v novém Shipardu (kompletně přečíst před per-runner
implementací):

- `nov_shipard:modules/economy/codebooks/tables/economy_codebooks_*.jsonc`
- `nov_shipard:modules/economy/codebooks/config/*.jsonc` — cfgItem mapování
  pro `vatPeriodKinds`, `vatTaxpayerKinds`, `fiscalPeriodTypes`.
- `nov_shipard:modules/docs/core/tables/docs_core_number_series.jsonc`
- `nov_shipard:modules/economy/items/tables/economy_items_kinds.jsonc`
- `nov_shipard:modules/economy/items/src/ItemKindsProvisioner.php` — ten
  seedovaný 4 kindy. Důležité pro mapování (viz sekce 4.10).

Per-tabulkové schémata ve starém Shipardu (zdrojová data):

- `modules/e10doc/base/tables/bankaccounts.json`
- `modules/e10doc/base/tables/cashboxes.json`
- `modules/e10doc/base/tables/centres.json`
- `modules/e10doc/base/tables/docnumbers.json`
- `modules/e10doc/base/tables/fiscalyears.json`
- `modules/e10doc/base/tables/fiscalmonths.json`
- `modules/e10doc/base/tables/taxRegs.json`
- `modules/e10doc/base/tables/taxperiods.json`
- `modules/e10doc/base/tables/warehouses.json`
- `modules/e10/witems/tables/itemtypes.json`

## Co implementovat

### 1. Rozšíření infrastruktury

#### 1.1 `libs/CrudClient.php`

Tenká fasáda nad `HttpClient`, která zná konvenci `/api/v1/{table}`
endpointu. Snižuje boilerplate v každém runneru.

```php
final class CrudClient
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /api/v1/{table} → vrátí id vytvořeného záznamu.
     *
     * @throws HttpException při 4xx/5xx
     */
    public function create(string $table, array $payload): int;

    /**
     * PATCH /api/v1/{table}/{id} → partial update.
     *
     * @throws HttpException při 4xx/5xx
     */
    public function patch(string $table, int $id, array $payload): void;

    /**
     * GET /api/v1/{table}/{id} → record nebo null pokud 404.
     *
     * Nehází exception při 404 — vrací null. Ostatní non-2xx házejí.
     */
    public function show(string $table, int $id): ?array;

    /**
     * GET /api/v1/{table}?filter={key}={value} → první match nebo null.
     *
     * Helper pro lookup podle business klíče (např. `code = "BA001"`).
     * Pokud generic CRUD nepodporuje filter query, nebo vrátí 0 nebo 1+
     * záznamů, vyhodnotí to runner ve své logice. V Phase 02 jen `null`
     * nebo první match.
     *
     * Otázka pro implementaci: ověř, jak generic CRUD list endpoint
     * filtruje (`?filter[code]=BA001` nebo `?code=BA001` nebo SQL-like?).
     * Pokud filtrování nepodporuje, vrať warning a fallbackni na full
     * list scan (limit 1000). Drobně horší výkon, neporušuje funkčnost.
     */
    public function findOneBy(string $table, string $column, mixed $value): ?array;
}
```

Implementační detail: `create()` parsuje response per existující shape z
ostatních endpointů (`{success: true, data: {id: N, ...}}` nebo
`{success: true, id: N}` — viz response shape v
`nov_shipard:src/Api/Response.php`). Ověř konkrétní formát.

#### 1.2 `libs/BaseCodebookRunner.php`

Abstract base class **pro číselníky importované přes generic CRUD**.
Dědí z `ImportRunner` (Fáze 01).

```php
abstract class BaseCodebookRunner extends ImportRunner
{
    abstract protected function entityType(): string;     // LocalIdMap entity type
    abstract protected function targetTable(): string;    // table name v novém Shipardu
    abstract protected function sourceQuery(): array;     // Dibi query array pro starý Shipard
    abstract protected function mapRow(array $oldRow): array;  // mapping starý row → nový payload
    abstract protected function entityLabel(): string;    // pro logy ("bank account", "fiscal year")

    /**
     * Hlavní orchestrace — iteruje přes staré řádky, mapuje, posílá REST.
     */
    public function run(): bool
    {
        $this->info("Importing {$this->entityLabel()}...");
        $rows = $this->fetchSourceRows();
        $this->info("Found " . count($rows) . " source rows.");

        $crud = new CrudClient($this->http());
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $oldRow) {
            try {
                $result = $this->processRow($oldRow, $crud);
                $stats[$result['status']]++;
                $this->logRow($oldRow, $result);
            } catch (HttpException $e) {
                $stats['failed']++;
                $this->err("Failed {$this->entityLabel()} (old ndx={$oldRow['ndx']}): "
                    . $e->getMessage());
                if (!$this->isContinueOnError()) {
                    return false;
                }
            }
        }

        $this->info("");
        $this->info(sprintf(
            "Done: created=%d, updated=%d, skipped=%d, failed=%d",
            $stats['created'], $stats['updated'], $stats['skipped'], $stats['failed'],
        ));

        return $stats['failed'] === 0;
    }

    protected function fetchSourceRows(): array
    {
        $rows = $this->db()->query($this->sourceQuery())->fetchAll();
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    protected function processRow(array $oldRow, CrudClient $crud): array
    {
        $oldNdx = (int) $oldRow['ndx'];
        $payload = $this->mapRow($oldRow);

        // Phase 02 fixed policy: insertem nastavujeme docState 40 + docStateMain 3
        $payload['docState'] = 40;
        $payload['docStateMain'] = 3;

        $existingNewId = $this->idMap()->lookup($this->entityType(), $oldNdx);

        if ($this->isDryRun()) {
            $this->debug("DRY-RUN: would " . ($existingNewId ? "PATCH" : "POST")
                . " " . $this->targetTable() . " "
                . ($existingNewId ?? '?') . " payload=" . json_encode($payload));
            return ['status' => 'skipped', 'reason' => 'dry-run'];
        }

        if ($existingNewId !== null) {
            $crud->patch($this->targetTable(), $existingNewId, $payload);
            return ['status' => 'updated', 'newId' => $existingNewId];
        }

        $newId = $crud->create($this->targetTable(), $payload);
        $this->idMap()->record($this->entityType(), $oldNdx, $newId);
        return ['status' => 'created', 'newId' => $newId];
    }

    protected function logRow(array $oldRow, array $result): void
    {
        $label = $this->entityLabel();
        $oldNdx = $oldRow['ndx'];
        $name = $oldRow['fullName'] ?? $oldRow['title'] ?? $oldRow['name'] ?? '???';

        switch ($result['status']) {
            case 'created':
                $this->ok(sprintf("[%s] %d → %d  %s",
                    $label, $oldNdx, $result['newId'], $name));
                break;
            case 'updated':
                $this->info(sprintf("[%s] %d ↻ %d  %s",
                    $label, $oldNdx, $result['newId'], $name));
                break;
            case 'skipped':
                $this->warn(sprintf("[%s] %d skipped (%s)  %s",
                    $label, $oldNdx, $result['reason'], $name));
                break;
        }
    }

    protected function isContinueOnError(): bool
    {
        return (bool) $this->app()->arg('continue-on-error');
    }
}
```

#### 1.3 Helper utility v `BaseCodebookRunner`

Pomocné metody, které několik runnerů použije:

```php
/**
 * Vygeneruje code pro entity, která ho ve starém Shipardu nemá (sloupec `id`
 * je prázdný nebo příliš dlouhý). Strategie:
 *
 *   1. Pokud `$rawCode` je vyplněn a délka ≤ $maxLen → vrátit (lowercase trim).
 *   2. Pokud `$rawCode` je vyplněn ale příliš dlouhý → truncate na $maxLen.
 *   3. Pokud prázdný → fallback "{$prefix}{$oldNdx}" (např. "BA42").
 *
 * Verbose info: emit `debug` zprávu, když strategie 2 nebo 3 zafungovala.
 */
protected function deriveCode(?string $rawCode, int $oldNdx, string $prefix, int $maxLen = 10): string;

/**
 * Konverze data (Dibi DateTime nebo string) na ISO `Y-m-d`. Null → null.
 */
protected function dateToString(mixed $date): ?string;

/**
 * Resolve oldFK → newId přes LocalIdMap. Když nenalezen, emit warning a vrátit null.
 * Volitelně: vyhodit ImportException, pokud caller předá $required=true.
 */
protected function resolveFk(string $entityType, ?int $oldFkValue, bool $required = false): ?int;
```

### 2. Rozšíření `ImportApp::dispatch()`

Přidat nové case-y:

```php
case 'vat-registrations':  return (new runners\VatRegistrationsRunner($this->context()))->run();
case 'fiscal-years':       return (new runners\FiscalYearsRunner($this->context()))->run();
case 'bank-accounts':      return (new runners\BankAccountsRunner($this->context()))->run();
case 'cost-centers':       return (new runners\CostCentersRunner($this->context()))->run();
case 'warehouses':         return (new runners\WarehousesRunner($this->context()))->run();
case 'cash-desks':         return (new runners\CashDesksRunner($this->context()))->run();
case 'number-series':      return (new runners\NumberSeriesRunner($this->context()))->run();
case 'item-kinds':         return (new runners\ItemKindsRunner($this->context()))->run();
case 'all-codebooks':      return (new runners\AllCodebooksRunner($this->context()))->run();
```

A do `printUsage()` přidat nové subkomandy.

### 3. Rozšíření `LocalIdMap`

Přidat konstanty entity typů:

```php
public const ENTITY_VAT_REGISTRATION = 'vatRegistration';
public const ENTITY_VAT_PERIOD       = 'vatPeriod';
public const ENTITY_FISCAL_YEAR      = 'fiscalYear';
public const ENTITY_FISCAL_MONTH     = 'fiscalMonth';
public const ENTITY_BANK_ACCOUNT     = 'bankAccount';
public const ENTITY_COST_CENTER      = 'costCenter';
public const ENTITY_WAREHOUSE        = 'warehouse';
public const ENTITY_CASH_DESK        = 'cashDesk';
public const ENTITY_NUMBER_SERIES    = 'numberSeries';
public const ENTITY_ITEM_KIND        = 'itemKind';
```

Phase 03+ doplní `ENTITY_PERSON`, `ENTITY_ITEM`, `ENTITY_DOC`. Sem už nepatří.

### 4. Per-runner implementace

Každý runner žije v `libs/runners/{Name}Runner.php`. Sleduje pattern z
BaseCodebookRunner. Sekce 4.1–4.8 popisují per-runner specifika.

**Společné konvence pro všechny runnery:**

- Source query filtruje `docState != 9800` (smazané záznamy ve starém Shipardu).
  Hodnota 9800 je konvence starého Shipardu pro "v koši", nepleť s novými
  docState hodnotami (10/40/70/80/90).
- Target payload **vždy** obsahuje `docState = 40`, `docStateMain = 3`
  (V pořádku — číselník je hned aktivní).
- Pokud starý záznam má `docState = 9700` (V opravě) → mapovat na nový
  `docState = 80, docStateMain = 5`. Pokud `docState = 9500` (Koncept) →
  `docState = 10, docStateMain = 1`. Pro zjednodušení v MVP **ignoruj
  jiné stavy** a všechno bude `docState = 40`.

#### 4.1 `VatRegistrationsRunner`

| Old (`e10doc_base_taxRegs`) | New (`economy_codebooks_vat_registrations`) | Pozn. |
|---|---|---|
| `ndx` | (přemapováno LocalIdMap) | |
| `title` | `name` (varchar 50) | truncate na 50 |
| — | `region` | hardcode `"eu"` v MVP (cfgItem `world.trade.unions`) |
| `taxCountry` | `country` (lowercase) | enumString z `world.base.countries` |
| `payerKind` | `taxpayer_kind` (enumInt) | mapping: 1=plátce → 0 (Klasický), jiné hodnoty → warning + 0 |
| `taxId` | `vat_id` (varchar 30, nullable) | |
| `periodType` | `tax_period_kind` (enumInt 1=Měsíční, 2=Čtvrtletní) | starý 0 → warning + default 1 |
| `periodTypeVatCS` | `report_period_kind` | stejné mapování jako `tax_period_kind` |
| (žádné explicitní valid_from) | `valid_from` | **`'2010-01-01'`** jako safe default; viz Open Issues |
| (žádné valid_to) | `valid_to` | null |
| `taxOffice` | — | **IGNORE** (rozhodnuto v PRD diskusi) |

**Filtr query:** `WHERE taxArea = 'VAT' AND docState != 9800`. Ostatní
taxAreas (income tax, road tax) přeskočit s `info` zprávou.

**Specifické edge cases:**

- `taxCountry` může být prázdný ve starém DS pro old records → fallback
  `'cz'` + warning.
- `taxId` může být prázdný → akceptovat null.
- `payerKind` enum mapování závisí na cfgItem `e10doc.base.tagsRegsPayerKinds`.
  Pojďme být liberální: 1 (běžný plátce) → 0 (klasický). Cokoliv jiného
  → warning a default 0.

#### 4.2 `FiscalYearsRunner` (+ embedded `fiscal-months`)

| Old (`e10doc_base_fiscalyears`) | New (`economy_codebooks_fiscal_years`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `fullName` | `name` (varchar 20) | truncate na 20 |
| `mark` | `doc_number_prefix` (varchar 10) | fallback na první 2 znaky `fullName` pokud prázdné |
| `start` | `date_begin` | |
| `end` | `date_end` | |
| `currency` | `currency` (lowercase) | |
| — | `locked` | hardcode `0` v MVP |
| `accMethod` / `stockAccMethod` / `propertyDepsMethod` / `disableCheckOpenStates` | — | IGNORE |

**Sub-table fiscal_months:** Po vytvoření / update fiscal_year importer:

1. Načte staré `e10doc_base_fiscalmonths WHERE fiscalYear = $oldNdx`.
2. Pro každý měsíc vytvoří nový `economy_codebooks_fiscal_months` přes
   `POST /api/v1/economy_codebooks_fiscal_months`.
3. Mapování per řádek:

| Old | New | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap entity=fiscalMonth) | |
| `fiscalYear` | `fiscal_year` | přemapováno přes LocalIdMap entity=fiscalYear |
| `fiscalType` | `period_type` | **!!! přemapovat:** old 0→1, old 1→0, old 2→2 (viz cfgItem) |
| `calendarYear` | `calendar_year` | |
| `calendarMonth` | `calendar_month` | |
| `start` | `date_begin` | |
| `end` | `date_end` | |
| `localOrder` / `globalOrder` | — | IGNORE (new schema je nemá) |

**Idempotence pro fiscal_months:** lookup přes LocalIdMap
`(fiscalMonth, oldNdx)` jako pro ostatní entity. Žádný unique constraint
v novém — důvěra v idMap.

**Žádný `docState` v `economy_codebooks_fiscal_months`** — schema ho
nemá. Vynech z payloadu.

#### 4.3 `BankAccountsRunner`

| Old (`e10doc_base_bankaccounts`) | New (`economy_codebooks_bank_accounts`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `id` (string 10) | `code` (varchar 10, NOT NULL UNIQUE) | viz "Code derivation" |
| `fullName` | `name` (varchar 150) | |
| — | `notice` | null |
| `bank` (FK na person) | `bank_name` | resolve FK → pull `fullName` ze starého `e10_persons_persons` (NE remap přes LocalIdMap, persons ještě nejsou) |
| `bankAccount` | `account_number` | |
| `iban` | `iban` | |
| `swift` | `bic` (varchar 11) | truncate na 11 |
| `currency` | `currency` (lowercase) | |
| — | `is_default` | hardcode `0` (viz Open Issues 2) |
| (žádné valid_from/to) | `valid_from`, `valid_to` | null |
| `order` | `sort_order` (smallint) | default 0 |

**Code derivation:** starý `id` může být prázdný. Použít helper
`deriveCode($oldRow['id'], $oldRow['ndx'], 'BA', 10)`.

**`bank` FK trick:** Starý `bankaccounts.bank` ukazuje na osobu (banku
jako právnickou osobu). Nový má jen `bank_name` jako string. Při importu
runner provede SQL JOIN nebo lookup na `e10_persons_persons` a vezme
`fullName`. Pokud bank persona neexistuje → fallback prázdný string.

```sql
SELECT ba.*, p.fullName AS bank_full_name
FROM e10doc_base_bankaccounts ba
LEFT JOIN e10_persons_persons p ON ba.bank = p.ndx
WHERE ba.docState != 9800
```

#### 4.4 `CostCentersRunner`

| Old (`e10doc_base_centres`) | New (`economy_codebooks_cost_centers`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `id` | `code` | `deriveCode(..., 'CC', 10)` |
| `fullName` | `name` | |
| (žádné valid_from/to) | `valid_from`, `valid_to` | null |
| — | `sort_order` | default 0 |

Nejjednodušší runner — minimální mapping.

#### 4.5 `WarehousesRunner`

| Old (`e10doc_base_warehouses`) | New (`economy_codebooks_warehouses`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `id` | `code` | `deriveCode(..., 'WH', 10)` |
| `fullName` | `name` | |
| `order` | `sort_order` | |
| (žádné valid_from/to) | `valid_from`, `valid_to` | null |
| `street`, `city`, `zipcode`, `country`, `ownerOffice` | — | IGNORE (new schema je nemá; address bude jinak v budoucnu) |
| `useTransportOnDocs`, `usePersonsOffice` | — | IGNORE |

#### 4.6 `CashDesksRunner`

| Old (`e10doc_base_cashboxes`) | New (`economy_codebooks_cash_desks`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `id` | `code` | `deriveCode(..., 'CD', 10)` |
| `fullName` | `name` | |
| — | `notice` | null |
| `currency` | `currency` (lowercase) | |
| — | `is_default` | hardcode `0` |
| (žádné valid_from/to) | `valid_from`, `valid_to` | null |
| `order` | `sort_order` | |
| `warehouseCashreg`, `warehousePurchase` | — | IGNORE (separate concerns) |

#### 4.7 `NumberSeriesRunner`

| Old (`e10doc_base_docnumbers`) | New (`docs_core_number_series`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `docType` | `doc_type` (enumString z `docs.core.docTypes`) | viz mapping níže |
| `fullName` | `name` (varchar 100) | |
| — | `notice` | null |
| `docKeyId` | `doc_number_code` | varchar 10, nullable |
| — | `doc_number_pattern` (varchar 50, NOT NULL) | **odvodit** z fiskálního období a `docKeyId` — viz "Pattern derivation" |
| — | `reset_scope` | hardcode `"fiscal_year"` |
| (žádné valid_from/to) | `valid_from`, `valid_to` | null |
| `useDocKinds`, `docKind`, `activitiesGroup`, `order`, `usePersonsOffice`, `emailSender`, `emailFromAddress`, `emailFromName`, `firstNumberSet`, `firstNumber`, `firstNumberFiscalPeriod`, `tabName`, `shortName` | — | IGNORE (new schema nemá ekvivalent) |

**Mapping `docType` (enumString):** starý cfgItem `e10.docs.types` má
hodnoty jako `invni`, `invno`, `cash`, atd. Ověř, že nový cfgItem
`docs.core.docTypes` (v
`nov_shipard:modules/docs/core/config/docTypes.jsonc`) má stejné hodnoty.
Pokud ano → pass-through. Pokud ne → mapping tabulka per docType. Pro
neznámé hodnoty emit warning + skip (return `'skipped'` ze `processRow`).

**Pattern derivation:** Starý Shipard má v `docnumbers` jen `docKeyId`
(např. `"VF"`) a aplikace si pattern skládá dynamicky. Nový má explicitní
`doc_number_pattern` (např. `"%Y%C%5N"`).

Default v MVP: `"%Y{docKeyId}%5N"` (rok + key + 5-místné číslo). Pokud
`docKeyId` prázdný, jen `"%Y%5N"`. Viz Open Issues 3 — toto je hrubé,
ale stačí pro první pokus.

#### 4.8 `ItemKindsRunner`

| Old (`e10_witems_itemtypes`) | New (`economy_items_kinds`) | Pozn. |
|---|---|---|
| `ndx` | (LocalIdMap) | |
| `fullName` | `name` (varchar 100) | |
| `id` (string 15) | `system_code` (varchar 25, nullable, unique) | viz "System code handling" |
| `type` (enumInt 0/1/2/3) | `item_type` (enumInt) | 1:1 (oba mají stejné mapování Služba/Zásoba/Účetní/Ostatní) |
| `validFrom` | `valid_from` | |
| `validTo` | `valid_to` | |
| `shortName`, `icon` | — | IGNORE |

**System code handling — speciální logika pro seedované kindy:**

Nový Shipard seeduje 4 systémové kindy přes `ItemKindsProvisioner`:

| `system_code` | `item_type` | seeded `name` |
|---|---|---|
| `service` | 0 | Service |
| `stock` | 1 | Stock item |
| `accounting` | 2 | Accounting |
| `other` | 3 | Other |

**Strategie importu per starý záznam:**

1. **Pokud starý `id` matchuje seeded `system_code`** (`service` / `stock`
   / `accounting` / `other`):
   - Lookup nové kindu přes `findOneBy(table, 'system_code', $oldId)`.
   - Pokud existuje → zapsat do LocalIdMap (`mapping na seeded id`),
     status `skipped` s reason `matched-seeded`. Žádný PATCH —
     seedovaný kind nepřepisujeme.
   - Pokud neexistuje (DS nemá seeded — žádný `ds-upgrade` neproběhl) →
     vytvořit normálně přes POST. Edge case.

2. **Pokud starý `id` neprázdný a nematchuje seeded:**
   - `findOneBy(table, 'system_code', $oldId)`:
     - Existuje → matched, zapsat do idMap, status `skipped` s
       reason `matched-by-system-code`. Žádný PATCH.
     - Neexistuje → POST s `system_code = $oldId`, status `created`.

3. **Pokud starý `id` prázdný:**
   - POST s `system_code = null`, status `created`.

**Edge case:** dva staré kindy se stejným neprázdným `id` → druhý a další
selžou (unique constraint v novém). Loger emit `failed` + zpráva. Run
pokračuje, jen ten konkrétní kind je špatně. Uživatel musí ve starém DS
opravit kódy a re-importovat.

#### 4.9 `AllCodebooksRunner`

Orchestrátor, který volá runnery v pořadí závislostí:

```php
public function run(): bool
{
    $sequence = [
        VatRegistrationsRunner::class,
        FiscalYearsRunner::class,
        BankAccountsRunner::class,
        CostCentersRunner::class,
        WarehousesRunner::class,
        CashDesksRunner::class,
        NumberSeriesRunner::class,
        ItemKindsRunner::class,
    ];

    foreach ($sequence as $runnerClass) {
        $runner = new $runnerClass($this->context);
        $ok = $runner->run();
        if (!$ok && !$this->isContinueOnError()) {
            $this->err("Aborting all-codebooks due to failure in " . basename(str_replace('\\', '/', $runnerClass)));
            return false;
        }
    }

    $this->ok("All codebooks imported.");
    return true;
}
```

**Pořadí závislostí:**

- `vat-registrations` — žádné FK
- `fiscal-years` — žádné FK
- `bank-accounts` — `bank` lookup ze starého persons table (read-only),
  ne FK do LocalIdMap
- `cost-centers` — žádné FK
- `warehouses` — žádné FK
- `cash-desks` — žádné FK
- `number-series` — žádné FK (původní `firstNumberFiscalPeriod` ignorováno)
- `item-kinds` — žádné FK

Žádný z runnerů ve Fázi 02 **nepoužívá** přemapování přes LocalIdMap z
jiné Fáze 02 entity. Tj. pořadí je v praxi libovolné, jen kvůli logickému
uspořádání ho fixujeme.

### 5. Aktualizace `README.md`

V `modules/imports/newShipard/README.md` rozšířit sekci "Subkomandy":

```markdown
## Subkomandy

| Subkomand | Stav | Popis |
|---|---|---|
| `status` | ✅ Fáze 01 | Sanity check. |
| `vat-registrations` | ✅ Fáze 02 | Registrace DPH. |
| `fiscal-years` | ✅ Fáze 02 | Fiskální roky + měsíce. |
| `bank-accounts` | ✅ Fáze 02 | Vlastní bankovní spojení. |
| `cost-centers` | ✅ Fáze 02 | Střediska. |
| `warehouses` | ✅ Fáze 02 | Sklady. |
| `cash-desks` | ✅ Fáze 02 | Pokladny. |
| `number-series` | ✅ Fáze 02 | Číselné řady dokladů. |
| `item-kinds` | ✅ Fáze 02 | Druhy položek. |
| `all-codebooks` | ✅ Fáze 02 | Vše výše v pořadí závislostí. |
| `persons` | 🚧 Fáze 03 | Osoby. |
| `items` | 🚧 Fáze 04 | Položky. |
| `docs` | 🚧 Fáze 05 | Doklady. |
| `all` | 🚧 Fáze 06 | Vše v plné sekvenci. |

## Společné opce

- `--dry-run` — vypíše plán bez REST volání.
- `-v` / `--verbose` — verbose output (request/response detaily).
- `--continue-on-error` — pokračovat i při failed záznamu (default: stop).
```

## Hotovo když

1. **`CrudClient`** existuje a zná konvenci `/api/v1/{table}` (create,
   patch, show, findOneBy).
2. **`BaseCodebookRunner`** poskytuje generic flow (fetch source → map →
   POST/PATCH → log stats). Helper utility (`deriveCode`, `dateToString`,
   `resolveFk`).
3. **8 per-typ runnerů** v `libs/runners/`:
   - `VatRegistrationsRunner`
   - `FiscalYearsRunner` (včetně sub-table fiscal_months)
   - `BankAccountsRunner`
   - `CostCentersRunner`
   - `WarehousesRunner`
   - `CashDesksRunner`
   - `NumberSeriesRunner`
   - `ItemKindsRunner`
4. **`AllCodebooksRunner`** orchestruje všechny v pevném pořadí.
5. **`LocalIdMap`** má všech 10 nových `ENTITY_*` konstant.
6. **`ImportApp::dispatch()`** rozšířen o 9 nových case-y. `printUsage()`
   aktualizován.
7. **`README.md`** aktualizován tabulkou subkomand a společnými opcemi.
8. **Idempotence ověřená manuálně:** spustit `all-codebooks` dvakrát po
   sobě, druhý běh musí mít `created=0, updated=N` (pro každý typ).
9. **Dry-run ověřen:** `--dry-run` flag emituje plán bez side-effects v
   novém Shipardu.
10. **Manuální smoke test** na DS `68908901448295` projde:
    - `vat-registrations` vytvoří ≥1 registraci (pravděpodobně CZ DPH).
    - `fiscal-years` vytvoří fiskální roky + správný počet měsíců
      (typicky 14 měsíců per rok: 1 otevření + 12 běžných + 1 uzavření).
    - `bank-accounts` vytvoří vlastní bankovní účty.
    - `cost-centers`, `warehouses`, `cash-desks`, `number-series`,
      `item-kinds` všechny projdou s nějakými řádky.
    - `all-codebooks` projde end-to-end.

## Doporučené pořadí implementace

1. **Sdílená infrastruktura:**
   - `CrudClient` — naimplementuj a otestuj proti reálnému novému
     Shipardu (POST na nějakou jednoduchou table, jako `cost_centers`,
     prázdný payload → očekává validation error 422, jen ověřit, že
     transport funguje).
   - `BaseCodebookRunner` — abstract metody, generic flow, helpers.
   - `LocalIdMap` konstanty — drobná úprava existujícího souboru.

2. **Jednodušší runnery napřed** (žádné komplikované enumy ani sub-tabulky):
   - `CostCentersRunner` — minimum mapping.
   - `WarehousesRunner` — podobně.
   - `CashDesksRunner` — drobně víc polí.
   - `ItemKindsRunner` — speciální seeded handling.

3. **Komplexnější runnery:**
   - `BankAccountsRunner` — SQL JOIN na persons pro bank_name.
   - `NumberSeriesRunner` — pattern derivation, docType mapping.
   - `VatRegistrationsRunner` — enum mappings.

4. **Sub-table runner:**
   - `FiscalYearsRunner` s embedded fiscal_months handling.

5. **Orchestrátor:**
   - `AllCodebooksRunner` — volá vše v pořadí.

6. **Dispatch + usage:**
   - `ImportApp::dispatch()` rozšířit.
   - `ImportApp::printUsage()` rozšířit.

7. **README:**
   - Aktualizovat tabulku subkomand.

8. **Smoke test:** end-to-end proti DS `68908901448295`.

Po každém runneru spustit ho izolovaně proti reálnému DS, ověřit, že
záznamy v novém Shipardu vznikají správně. Pak `--dry-run` ověřit, že
nemění nic. Pak druhé spuštění bez dry-run → ověřit idempotenci (`updated=N`).

## Otevřené body / rozhodnutí

### 1. `valid_from` pro `vat_registrations` — co dát default

Starý `taxRegs` nemá `valid_from`. Nový vyžaduje (NOT NULL). Návrhy:

- **A) Hardcode `'2010-01-01'`** — bezpečně starý datum, pokrývá všechny
  historické doklady.
- **B) Spočítat z minima `taxperiods.start`** pro tento `vatReg` — přesnější,
  ale komplikuje implementaci (extra query).
- **C) `--vat-valid-from=YYYY-MM-DD` CLI flag** — uživatel rozhoduje.

**Doporučení:** A, s log informací o použitém datu. Pokud uživatel chce
přesnost, dodá ručně před importem v starém DS přidáním sloupce v paměti
runneru. C přidat ve Fázi 06 polish.

### 2. `is_default` pro bank-accounts / cash-desks

Starý nemá. Nový má. Hardcode `0` pro všechny → uživatel ručně označí v UI
po importu. Alternativa: detect přes `exclFromDashboard = 0` heuristiku
(opačný význam), ale to neodpovídá smyslu `is_default` (default pro emise
faktur).

**Doporučení:** hardcode `0`, info zpráva po importu "set is_default
manually in new Shipard UI for primary accounts/desks".

### 3. `doc_number_pattern` derivace

Starý Shipard si pattern skládá dynamicky podle pravidel:

- Roční čísla: `YYYY{docKeyId}NNNNN` (např. `2024VF00001`)
- Bez `docKeyId`: `YYYYNNNNN`

Nový má explicitní šablonu (`%Y%C%5N` resp. `%Y%5N`). Default v MVP:

```php
$pattern = $oldRow['docKeyId']
    ? '%Y' . $oldRow['docKeyId'] . '%5N'
    : '%Y%5N';
```

**Edge cases:** starý může mít `useDocKinds = 1` a `docKind` vyplněný →
pattern zahrnuje druh dokladu. Nový to neumí (zatím). MVP **ignoruje
docKind** v patternu — uživatel ručně doplní v UI po importu.

### 4. `period_type` v fiscal_months — enum drift

Starý `fiscalmonths.fiscalType`:
- 0 = běžné
- 1 = otevření
- 2 = uzavření

Nový `economy_codebooks_fiscal_months.period_type` (cfgItem `economy.codebooks.fiscalPeriodTypes`):
- 0 = Otevření
- 1 = Běžné
- 2 = Uzavření

Mapování:
```php
$periodType = match ((int) $oldRow['fiscalType']) {
    0 => 1,  // běžné → Běžné
    1 => 0,  // otevření → Otevření
    2 => 2,  // uzavření → Uzavření
    default => 1, // fallback
};
```

Implementuj jako konstantu / pure helper v `FiscalYearsRunner`, ať je to
viditelně doloženo.

### 5. `docType` enum mapping (starý vs nový)

Hodnoty `e10.docs.types` ve starém Shipardu vs `docs.core.docTypes`
v novém:

- Pravděpodobně overlap (`invni`, `invno`, `cash`, atd.).
- Ověř načtením obou cfgItemů a porovnáním.
- Pokud existují klíče ve starém které v novém nejsou → emit warning,
  vynech ten řádek se `status = 'skipped'`, reason `unknown-doc-type`.

Mapping tabulku (pokud potřeba) drž v `NumberSeriesRunner` jako konstantu:

```php
private const DOC_TYPE_MAP = [
    'invni' => 'invni',
    'invno' => 'invno',
    'cash'  => 'cash',
    // explicit mappings if values diverge
];
```

V MVP zkus identity mapping a logni warningy pro neznámé. Až bude jasné,
co reálně doleze, doplň konkrétní přemapování.

### 6. Number Series + FiscalYear orphan reference

Starý `docnumbers.firstNumberFiscalPeriod` ukazuje na `fiscalyears.ndx`.
Pokud bychom chtěli zachovat, museli bychom resolve přes LocalIdMap
(`fiscalYear` mapování). Phase 02 to **ignoruje** — `firstNumber` je v
novém Shipardu řešen jinak (counter v `docs_core_number_counters`, který
se inicializuje automaticky při prvním dokladu).

### 7. `vat_periods` — automatický provisioning vs explicitní import

`VatPeriodsProvisioner` v novém Shipardu generuje current + next year
měsíční/čtvrtletní period pro každou aktivní registraci. To se spustí
při `ds-upgrade`.

Pro **historická** období (před current rokem) potřebujeme pro doklady.
Phase 02 **ignoruje** import historických period a spoléhá na provisioner.

Pokud bude Phase 05 (docs) potřebovat historické period, doplníme runner
`VatPeriodsRunner` jako Phase 02 follow-up. Pro teď: assumption je, že
provisioner běží automaticky v `ds-upgrade`, vat_periods pro current
+ next rok jsou k dispozici, doklady mimo toto okno do MVP nepatří.

Pokud bys to chtěl jinak (přímý import historie ze `taxperiods` s
`periodType = 0`), řekni, doplním Runner do Phase 02 explicitně. Pro
první pokus to ale není potřeba.

### 8. `findOneBy` filter format pro generic CRUD

`CrudClient::findOneBy` musí umět zavolat GET `/api/v1/{table}?{filter}`.
Konkrétní formát filtru nevidím v existujících příkladech. Tři možnosti:

- `?{column}={value}` (např. `?code=BA001`)
- `?filter[{column}]={value}`
- SQL-like `?where=code%3DBA001`

Ověř `nov_shipard:src/Api/Controller/CrudController.php` `list` metodu
a sleduj, jak parsuje query string. Phase 02 použije ten konkrétní
formát.

Pokud generic CRUD list endpoint **nepodporuje žádný filter**, alternativa:

- GET `/api/v1/{table}?limit=1000`, full scan, klient filtruje.

Drobně horší výkon, ale pro číselníky (< 100 záznamů typicky) přijatelné.
Phase 02 můžeme začít s full-scan strategií a později optimalizovat.

### 9. Continue-on-error semantika

`--continue-on-error` flag:

- Defaultně runner stopne při první HTTP exception.
- S flag pokračuje, jen zvýší `failed` counter.

**Otázka:** failed status u jednoho záznamu — uložit ho do LocalIdMap?
Phase 02 doporučuje **ne** — failed záznam se neuloží, opakovaný běh se
ho znovu pokusí vytvořit. To je rozumné: pokud HTTP error byl transient
(timeout), retry projde; pokud byl systémový (validation), uživatel
opraví zdroj a re-spustí.

### 10. Logging mid-progress při velkém objemu

Pro DS s tisíci záznamy (např. number-series jich může být desítky)
runner logguje per řádek. To je dost spamu.

Phase 02 to **nezohledňuje** — verbose flag rozdíl mezi `info` a `debug`
už dělá. Pokud `--verbose` není, runner emituje jen `created` / `failed`
/ `skipped` per řádek. `updated` (idempotentní opakované spuštění) by
mohlo být kondenzované — ale pro Fázi 02 stačí basic.

Polish v Phase 06.

## Příprava pro Fázi 03+

Po Phase 02:

- LocalIdMap má všechny codebook mappings — Phase 03 (persons) je
  nepoužívá; Phase 05 (docs) je intenzivně využije (středisko, sklad,
  pokladna, bankovní účet, číselná řada, fiskální rok, registrace DPH
  → každý je FK v `docs_core_heads`).
- `CrudClient` je stabilní fasáda — Phase 03 ho rozšíří o
  `ExchangeClient` (volání exchange formátu apply endpointů).
- `BaseCodebookRunner` pattern je etablovaný — Phase 03 ho replikuje
  v `BasePersonsRunner` / `BaseExchangeRunner` se specifika exchange
  flow (request body je canonical, response obsahuje `_resolve` state).
