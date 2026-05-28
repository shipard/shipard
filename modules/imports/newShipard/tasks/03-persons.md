# Task: Import osob (Fáze 03)

## Kontext

Fáze 03 implementuje import osob ze starého Shipardu do nového přes
**exchange formát** `shpd.persons.person.v1` (endpoint
`POST /api/v1/_exchange/persons/person/apply`).

**Klíčový rozdíl od Fáze 02** (codebooks): persons jdou přes exchange
applier, ne přes generic CRUD. Pipeline:

1. Runner zkonstruuje canonical payload ze starého Shipardu (`persons` +
   `properties` + `personsContacts` + `personsBA` + `contacts`).
2. POST na apply endpoint nového Shipardu.
3. Response obsahuje enriched canonical s `_resolve` state + `savedPersonId`.
4. Runner reaguje na `_resolve.header.status`:
   - `matched` → zapsat do LocalIdMap, log `updated`
   - `canCreate` → applier vytvořil, zapsat do LocalIdMap, log `created`
   - `ambiguous` → ?? (runner musí rozhodnout — viz sekce 3.4)
5. Zapsat lineage (source_kind, source_ref) — applier to dělá sám podle
   `source` v payloadu.

**Cíl Fáze 03:** Po dokončení musí jít:

```bash
shpd-app cli-action --action=imports.newShipard/import persons
shpd-app cli-action --action=imports.newShipard/import persons --dry-run
shpd-app cli-action --action=imports.newShipard/import persons -v
shpd-app cli-action --action=imports.newShipard/import persons --limit=50    # nové
```

**Mimo scope Fáze 03:**

- Položky (Fáze 04) — jiný exchange formát.
- Doklady (Fáze 05) — jiný exchange formát + filtr datum.
- `all` orchestrátor přes codebooks + persons + items + docs — Fáze 06.
- Ruční rozhodování o `ambiguous` matchích — runner v Phase 03 buď
  preferuje `useExisting` (pokud LocalIdMap má hit), nebo skipne s
  warningem. Žádný interaktivní prompt.
- Import `e10_persons_keys` (přístupové RFID karty, PIN) — sub-systém
  starého Shipardu, který nový Shipard nemá. Ignorováno.
- Import `e10_persons_groups` (skupiny) — zatím out of scope, pokud bude
  potřeba dořešit přes samostatný subkomand.
- Import "user accounts" (login + heslo) — `e10_persons_persons.accountType`,
  `login`, `loginHash`, atd. Importujeme jen základní persona data;
  user account je out of scope (uživatele lze vytvořit ručně pak v UI
  nového Shipardu).

## Prerekvizita v novém Shipardu

**Drobná úprava cfgItem `base.persons.sourceKinds`.** V
`nov_shipard:modules/base/persons/config/sourceKinds.jsonc` přidat nový
klíč:

```jsonc
"import.oldShipard": {
    "name": "Import from legacy Shipard",
    "name:cs": "Import ze starého Shipardu",
    "name:en": "Import from legacy Shipard"
}
```

Bez toho cfgItem klíče by applier validation odmítl payload se
`source.kind = "import.oldShipard"`. Tato změna **patří do nového
Shipardu**, ale je natolik malá, že ji uděláme v rámci tohoto tasku.
Pro analogii: stejný klíč už existuje v `economy.items.sourceKinds`
(Fáze 04 prerekvizita).

## Před implementací přečti

Z infrastruktury Fáze 01–02 (musí být hotové):

- **`modules/imports/newShipard/libs/HttpClient.php`** — GET/POST atd.
  Phase 03 přidá tenký `ExchangeClient` nad ním pro apply endpoint.
- **`modules/imports/newShipard/libs/CrudClient.php`** — sourozenec.
- **`modules/imports/newShipard/libs/LocalIdMap.php`** — má `ENTITY_PERSON`
  konstantu (přidat).
- **`modules/imports/newShipard/libs/BaseCodebookRunner.php`** — pattern
  inspirace, ale Phase 03 si dělá vlastní `BaseExchangeRunner` (jiný flow).
- **`modules/imports/newShipard/libs/ImportRunner.php`** — společný
  ancestor.

V novém Shipardu (kompletně přečíst):

- **`nov_shipard:docs/exchange-format-persons.md`** — kanonická spec.
  Klíčové sekce: 3 (top-level structure), 5 (Address), 6 (BankAccount),
  7 (Contact), 8 (Resolve), 9 (Merge strategies), 10 (`_resolve` state),
  11 (Apply pipeline), 12 (REST API).
- **`nov_shipard:modules/core/exchange/schemas/shpd.persons.person.v1.jsonc`** —
  schema definice. Důležité pro validaci payloadů před POST.
- **`nov_shipard:modules/core/exchange/src/Person/PersonApplier.php`** —
  abychom rozuměli, co applier dělá.
- **`nov_shipard:modules/base/persons/tables/base_persons_persons.jsonc`**,
  `addresses`, `bank_accounts`, `contacts` — cílové tabulky.
- **`nov_shipard:src/Api/Controller/ExchangeController.php`** — response
  shape (`{success, data: {canonical, savedPersonId}}`).

Ve starém Shipardu (zdrojová data — kompletně přečíst):

- **`modules/e10/persons/tables/persons.json`** — hlavní tabulka.
- **`modules/e10/persons/tables/personsContacts.json`** — adresy + kontakty
  (komplexní; mixed bag of address + contact data).
- **`modules/e10/persons/tables/contacts.json`** — samostatné kontakty
  (telefon, email) přiřazené přes (tableNdx, recNdx).
- **`modules/e10/persons/tables/personsBA.json`** — bankovní účty.
- **`modules/e10/base/tables/properties.json`** — `e10_base_properties`
  drží IČO/DIČ jako (group='ids', property='oid'/'taxid'). **Toto je
  klíčová znalost** — bez IČO/DIČ z properties není person resolve
  spolehlivý.
- **`modules/e10/persons/libs/register/PersonRegister.php`** — vzor pro
  SQL queries (jak se IČO/DIČ načítá z properties; jak se mapují adresy).

## Co implementovat

### 1. Úprava nového Shipardu — cfgItem klíč

Editovat **`nov_shipard:modules/base/persons/config/sourceKinds.jsonc`**:

```jsonc
{
    "manual": { ... },
    "aiExtraction": { ... },
    "import.ares": { ... },
    "import.rpo": { ... },
    "import.handelsregister": { ... },
    "import.shipardRegistry": { ... },
    "import.csv": { ... },
    "import.oldShipard": {
        "name": "Import from legacy Shipard",
        "name:cs": "Import ze starého Shipardu",
        "name:en": "Import from legacy Shipard"
    }
}
```

Po změně spustit `shpd-ds upgrade` proti cílovému DS, ať se cfgItem
přerolloval do `cfg.data`.

### 2. `libs/ExchangeClient.php` — apply klient

Tenký wrapper nad `HttpClient` pro exchange flow. Sourozenec `CrudClient`.

```php
final class ExchangeClient
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * POST /api/v1/_exchange/{flow}/{type}/apply.
     *
     * Vrátí decoded response s klíči `success`, `data.canonical`,
     * `data.savedPersonId` (nebo `savedItemId` / `savedDocId`).
     *
     * Při HTTP 4xx/5xx vyhodí HttpException — runner reaguje (4xx logged,
     * 5xx fatal).
     *
     * @return array{
     *     success: bool,
     *     canonical: array,
     *     savedId: int|null,
     *     statusCode: int,
     * }
     */
    public function apply(string $flow, string $type, array $canonical, string $savedIdKey): array;

    /**
     * POST .../preview — bez DB writes. Vrací stejný shape jako apply,
     * ale `savedId` je null.
     */
    public function preview(string $flow, string $type, array $canonical, string $savedIdKey): array;

    /**
     * POST .../validate — jen schema + PHP validation. Vrací jen
     * issues v canonical._resolve.issues.
     */
    public function validate(string $flow, string $type, array $canonical, string $savedIdKey): array;
}
```

Implementační detaily:

- Cesta: `"_exchange/{$flow}/{$type}/apply"` — pro persons je flow=`persons`,
  type=`person`.
- Response shape z controlleru: `{success: true, data: {canonical, savedPersonId}}`.
  Klíč `savedPersonId` je dynamický — applier vrátí `savedItemId` pro items,
  `savedDocId` pro docs. ExchangeClient přijme klíč jako parametr.
- 422 / 409 vyhodí `HttpException` s `errorCode` z `error.code`. Runner
  je catch-uje a loguje per-row, ne fatalně.

### 3. `libs/BaseExchangeRunner.php` — base class pro exchange flow

Abstract base class **pro entity importované přes exchange applier**.

```php
abstract class BaseExchangeRunner extends ImportRunner
{
    abstract protected function entityType(): string;        // LocalIdMap entity type
    abstract protected function exchangeFlow(): string;      // "persons" | "items" | "docs"
    abstract protected function exchangeType(): string;      // "person" | "item" | "document"
    abstract protected function savedIdKey(): string;        // "savedPersonId" | "savedItemId" | "savedDocId"
    abstract protected function sourceQuery(): array;        // hlavní query přes hlavičky
    abstract protected function buildCanonical(array $oldRow): array;  // mapping starý row → canonical payload
    abstract protected function entityLabel(): string;       // pro logy

    public function run(): bool
    {
        $this->info("Importing {$this->entityLabel()} via exchange flow...");
        $rows = $this->fetchSourceRows();

        $limit = (int) ($this->app()->arg('limit') ?? 0);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
            $this->info("Limit applied: processing first {$limit} rows.");
        }

        $this->info("Found " . count($rows) . " source rows.");

        $exchange = new ExchangeClient($this->http());
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $oldRow) {
            try {
                $result = $this->processOneRow($oldRow, $exchange);
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

    protected function processOneRow(array $oldRow, ExchangeClient $exchange): array
    {
        $oldNdx = (int) $oldRow['ndx'];
        $canonical = $this->buildCanonical($oldRow);

        // Fast-lookup: pokud LocalIdMap má hit, force matched přes _resolve
        $cachedNewId = $this->idMap()->lookup($this->entityType(), $oldNdx);
        if ($cachedNewId !== null) {
            $canonical['_resolve'] = [
                'header' => ['userAction' => 'useExisting:' . $cachedNewId],
            ];
        }

        if ($this->isDryRun()) {
            $this->debug("DRY-RUN: would POST apply " . json_encode($canonical));
            return ['status' => 'skipped', 'reason' => 'dry-run'];
        }

        $response = $exchange->apply(
            $this->exchangeFlow(),
            $this->exchangeType(),
            $canonical,
            $this->savedIdKey(),
        );

        if (!$response['success']) {
            // 4xx / 422 / 409 → runner pokračuje, log failed
            return [
                'status' => 'failed',
                'reason' => 'apply_returned_error',
                'canonical' => $response['canonical'] ?? [],
            ];
        }

        $savedId = $response['savedId'];
        if ($savedId === null) {
            return ['status' => 'failed', 'reason' => 'apply_returned_no_id'];
        }

        // Zapsat do LocalIdMap (idempotent — update pokud existuje)
        $this->idMap()->record($this->entityType(), $oldNdx, $savedId);

        $headerStatus = $response['canonical']['_resolve']['header']['status'] ?? 'unknown';
        $status = match ($headerStatus) {
            'matched'   => 'updated',
            'canCreate' => 'created',
            'ambiguous' => 'skipped',   // ambiguous = applier nematchnul, runner skipne
            default     => 'created',   // unknown — default na created (safe)
        };

        return [
            'status' => $status,
            'newId'  => $savedId,
            'headerStatus' => $headerStatus,
        ];
    }

    protected function fetchSourceRows(): array
    {
        $rows = $this->db()->query($this->sourceQuery())->fetchAll();
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    protected function logRow(array $oldRow, array $result): void
    {
        $label = $this->entityLabel();
        $oldNdx = $oldRow['ndx'];
        $name = $oldRow['fullName'] ?? $oldRow['name'] ?? '???';

        switch ($result['status']) {
            case 'created':
                $this->ok(sprintf("[%s] %d → %d  %s", $label, $oldNdx, $result['newId'], $name));
                break;
            case 'updated':
                $this->info(sprintf("[%s] %d ↻ %d  %s", $label, $oldNdx, $result['newId'], $name));
                break;
            case 'skipped':
                $this->warn(sprintf("[%s] %d skipped (%s)  %s",
                    $label, $oldNdx, $result['reason'] ?? '?', $name));
                break;
            case 'failed':
                $this->err(sprintf("[%s] %d FAILED (%s)  %s",
                    $label, $oldNdx, $result['reason'] ?? '?', $name));
                break;
        }
    }

    protected function isContinueOnError(): bool
    {
        return (bool) $this->app()->arg('continue-on-error');
    }
}
```

### 4. `libs/runners/PersonsRunner.php`

Implementace `BaseExchangeRunner` pro osoby.

#### 4.1 Identifikace

```php
final class PersonsRunner extends BaseExchangeRunner
{
    protected function entityType(): string  { return LocalIdMap::ENTITY_PERSON; }
    protected function exchangeFlow(): string { return 'persons'; }
    protected function exchangeType(): string { return 'person'; }
    protected function savedIdKey(): string  { return 'savedPersonId'; }
    protected function entityLabel(): string { return 'person'; }
    // ...
}
```

#### 4.2 Source query

```php
protected function sourceQuery(): array
{
    $q = [];
    $q[] = 'SELECT p.* FROM e10_persons_persons AS p';
    $q[] = ' WHERE p.docState != %i', 9800;          // not deleted
    $q[] = ' AND p.personType IN (%in)', [1, 2];     // jen Člověk + Firma; ne Robot, ne undefined
    $q[] = ' ORDER BY p.ndx ASC';
    return $q;
}
```

#### 4.3 `buildCanonical` — mapping core

Pro každou osobu (každý starý row) sestavit canonical payload. Hlavní
metoda volá pomocné metody per sub-kolekce.

```php
protected function buildCanonical(array $oldRow): array
{
    $oldNdx = (int) $oldRow['ndx'];

    // Properties — IČO/DIČ/govDataBox
    $properties = $this->loadProperties($oldNdx);

    // Addresses — z personsContacts
    $addresses = $this->loadAddresses($oldNdx);

    // Bank accounts
    $bankAccounts = $this->loadBankAccounts($oldNdx);

    // Contacts
    $contacts = $this->loadContacts($oldNdx);

    // Country — preferuj z hlavní adresy, fallback "cz"
    $country = $this->resolveCountry($addresses);

    return [
        'format'        => 'shpd.persons.person',
        'formatVersion' => '1.0',

        'source' => [
            'kind'        => 'import.oldShipard',
            'fetchedAt'   => date('c'),
            'registryRef' => (string) $oldNdx,
        ],

        'personType' => $this->mapPersonType((int) $oldRow['personType']),
        'country'    => $country,
        'personId'   => null,   // nový Shipard si generuje, my nevnucujeme

        'companyId'         => $properties['oid'] ?? null,
        'taxId'             => null,
        'vatId'             => $properties['taxid'] ?? null,
        'courtRegistration' => null,
        'govEBoxId'         => $properties['govDataBox'] ?? null,

        'name' => $this->buildNameObject($oldRow),

        'personal' => $this->buildPersonalObject($oldRow),  // null pro company

        'contact' => [
            'email' => $this->topLevelContactEmail($contacts),
            'phone' => $this->topLevelContactPhone($contacts),
            'web'   => null,
        ],

        'status' => [
            'isClosed'   => (bool) ($oldRow['personCanceled'] ?? false),
            'closedDate' => $this->dateToString($oldRow['personCancelDate'] ?? null),
            'isOwn'      => false,   // Phase 03 hardcoded; uživatel ručně označí v UI
            'docState'   => 40,
        ],

        'addresses'    => $addresses,
        'bankAccounts' => $bankAccounts,
        'contacts'     => $contacts,

        'applyOptions' => [
            'mergeStrategy'  => 'fullSync',
            'targetDocState' => 40,
            'createPersonId' => true,
            'rejectOnIssues' => ['error'],
        ],
    ];
}
```

#### 4.4 `mapPersonType`

```php
private function mapPersonType(int $oldType): string
{
    return match ($oldType) {
        2 => 'company',
        1 => 'person',
        default => 'company',  // 0=undefined, 3=Robot fallback (Robot je filtered out v query)
    };
}
```

#### 4.5 `loadProperties` — IČO/DIČ/govDataBox

Načte z `e10_base_properties` pro daný person. Vrátí asociativní pole
`{oid?: string, taxid?: string, govDataBox?: string}`.

```php
private function loadProperties(int $personNdx): array
{
    $rows = $this->db()->query(
        'SELECT [group], property, valueString FROM e10_base_properties'
        . ' WHERE tableid = %s', 'e10.persons.persons',
        ' AND recid = %i', $personNdx,
        ' AND [group] IN (%in)', ['ids', 'contacts'],
        ' AND property IN (%in)', ['oid', 'taxid', 'govDataBox'],
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $val = trim((string) $row['valueString']);
        if ($val === '') {
            continue;
        }
        $result[$row['property']] = $val;
    }
    return $result;
}
```

#### 4.6 `buildNameObject`

```php
private function buildNameObject(array $oldRow): array
{
    return [
        'fullName'    => $oldRow['fullName'] ?? '',
        'titleBefore' => $this->emptyToNull($oldRow['beforeName'] ?? null),
        'firstName'   => $this->emptyToNull($oldRow['firstName'] ?? null),
        'middleName'  => $this->emptyToNull($oldRow['middleName'] ?? null),
        'lastName'    => $this->emptyToNull($oldRow['lastName'] ?? null),
        'titleAfter'  => $this->emptyToNull($oldRow['afterName'] ?? null),
    ];
}
```

Pro `personType = company` jsou firstName/lastName neutrální (`null`).
Schema to akceptuje.

#### 4.7 `buildPersonalObject`

```php
private function buildPersonalObject(array $oldRow): ?array
{
    $personType = (int) ($oldRow['personType'] ?? 0);
    if ($personType !== 1) {
        return null;  // company nemá personal block
    }

    return [
        'birthDate'    => null,  // starý Shipard nemá explicit birthDate v persons
        'nationalId'   => $this->emptyToNull($oldRow['personalId'] ?? null),
        'idCardNumber' => null,
    ];
}
```

`personalId` ve starém Shipardu je obecný "osobní číslo" — bezpečně mapovat
na nový `nationalId` (rodné číslo).

#### 4.8 `loadAddresses` — z `personsContacts`

```php
private function loadAddresses(int $personNdx): array
{
    $rows = $this->db()->query(
        'SELECT pc.*, c.id AS country_iso'
        . ' FROM e10_persons_personsContacts AS pc'
        . ' LEFT JOIN e10_world_countries AS c ON pc.adrCountry = c.ndx'
        . ' WHERE pc.person = %i', $personNdx,
        ' AND pc.docState != %i', 9800,
        ' AND pc.flagAddress = %i', 1,
        ' ORDER BY pc.systemOrder, pc.ndx',
    )->fetchAll();

    $addresses = [];
    foreach ($rows as $row) {
        $addresses[] = $this->mapAddress($row);
    }
    return $addresses;
}

private function mapAddress($row): array
{
    // addressType: 1=Sídlo (flagMainAddress), 3=Provozovna (flagOffice), 2=Doručovací (jinak)
    $addressType = 2;  // default
    if ((int) ($row['flagMainAddress'] ?? 0) === 1) {
        $addressType = 1;
    } elseif ((int) ($row['flagOffice'] ?? 0) === 1) {
        $addressType = 3;
    }

    // placeRegId — pro Provozovny IČP
    $placeRegId = null;
    $placeRegType = null;
    if ($addressType === 3 && !empty($row['id1'])) {
        $placeRegId = (string) $row['id1'];
        $placeRegType = 'ICP';
    }

    return [
        'addressType'   => $addressType,
        'name'          => $this->emptyToNull($row['adrSpecification'] ?? null),
        'placeRegType'  => $placeRegType,
        'placeRegId'    => $placeRegId,
        'isStandardized' => (bool) ((int) ($row['flagStandardized'] ?? 0) === 1),

        'street'             => $this->emptyToNull($row['adrStreet'] ?? null),
        'houseNumber'        => $this->emptyToNull($row['saHouseNr'] ?? null),
        'orientationNumber' => null,
        'city'               => $this->emptyToNull($row['adrCity'] ?? null),
        'cityPart'           => $this->emptyToNull($row['saCityPartName'] ?? null),
        'district'           => null,
        'zip'                => $this->emptyToNull($row['adrZipCode'] ?? null),
        'country'            => strtolower($row['country_iso'] ?? 'cz'),
        'registryCode'       => null,  // RÚIAN ADM — starý Shipard má jiné kódy
        'divisionCode'       => null,

        'latitude'  => $this->numberOrNull($row['adrLocLat'] ?? null),
        'longitude' => $this->numberOrNull($row['adrLocLon'] ?? null),
        'manualGps' => (bool) ((int) ($row['adrLocManual'] ?? 0) === 1),

        'displayLine'  => null,
        'displayBlock' => null,

        'orderPos'   => (int) ($row['systemOrder'] ?? 0),
        'validFrom'  => $this->dateToString($row['validFrom'] ?? null),
        'validTo'    => $this->dateToString($row['validTo'] ?? null),
        'note'       => null,
    ];
}
```

#### 4.9 `loadBankAccounts`

```php
private function loadBankAccounts(int $personNdx): array
{
    $rows = $this->db()->query(
        'SELECT * FROM e10_persons_personsBA'
        . ' WHERE person = %i', $personNdx,
        ' AND docState != %i', 9800,
        ' ORDER BY ndx',
    )->fetchAll();

    $banks = [];
    foreach ($rows as $row) {
        $banks[] = [
            'name'          => null,
            'accountNumber' => $this->emptyToNull($row['bankAccount'] ?? null),
            'iban'          => null,   // starý Shipard personsBA nemá IBAN
            'bic'           => null,
            'currency'      => 'czk',  // default; starý nemá per-account měnu
            'source'        => 0,      // manual
            'orderPos'      => 0,
            'validFrom'     => $this->dateToString($row['validFrom'] ?? null),
            'validTo'       => $this->dateToString($row['validTo'] ?? null),
        ];
    }
    return $banks;
}
```

#### 4.10 `loadContacts` — z `e10_persons_contacts`

```php
private function loadContacts(int $personNdx): array
{
    // tableNdx pro e10.persons.persons je 1000 (viz persons.json:ndx)
    $rows = $this->db()->query(
        'SELECT * FROM e10_persons_contacts'
        . ' WHERE tableNdx = %i', 1000,
        ' AND recNdx = %i', $personNdx,
        ' AND docState != %i', 9800,
        ' ORDER BY ndx',
    )->fetchAll();

    $contacts = [];
    foreach ($rows as $row) {
        $contacts[] = [
            'name'      => $this->emptyToNull($row['name'] ?? null) ?? 'Kontakt',
            'role'      => $this->emptyToNull($row['role'] ?? null),
            'email'     => $this->emptyToNull($row['email'] ?? null),
            'phone'     => $this->emptyToNull($row['phone'] ?? null),
            'note'      => null,
            'orderPos'  => 0,
            'validFrom' => null,
            'validTo'   => null,
        ];
    }
    return $contacts;
}
```

**`name` required** ve schema, ale starý `e10_persons_contacts` ho může
mít prázdný. Fallback `"Kontakt"` zaručí, že schema validation projde.
Pokud je `email` nebo `phone` jediný obsah, vznikne contact s genericnm
name — z hlediska business hodnoty OK.

#### 4.11 `resolveCountry`

```php
private function resolveCountry(array $addresses): string
{
    // Preferuj country z adresy s addressType=1 (Sídlo)
    foreach ($addresses as $addr) {
        if (($addr['addressType'] ?? 0) === 1 && !empty($addr['country'])) {
            return $addr['country'];
        }
    }
    // Fallback: první adresa s country
    foreach ($addresses as $addr) {
        if (!empty($addr['country'])) {
            return $addr['country'];
        }
    }
    return 'cz';
}
```

#### 4.12 `topLevelContactEmail` / `topLevelContactPhone`

Pro hlavičkové `contact.email` a `contact.phone` propisujeme **první**
hodnotu z `contacts[]`. Tj. pokud osoba má 3 kontaktní osoby, vezme se
ten první (po `ORDER BY ndx`) a její email/phone se propíše do hlavičky.

```php
private function topLevelContactEmail(array $contacts): ?string
{
    foreach ($contacts as $c) {
        if (!empty($c['email'])) {
            return $c['email'];
        }
    }
    return null;
}

private function topLevelContactPhone(array $contacts): ?string
{
    foreach ($contacts as $c) {
        if (!empty($c['phone'])) {
            return $c['phone'];
        }
    }
    return null;
}
```

#### 4.13 Helpery

```php
private function emptyToNull(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

private function numberOrNull($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $f = (float) $value;
    return $f === 0.0 ? null : $f;
}

private function dateToString($date): ?string
{
    if ($date === null) {
        return null;
    }
    if ($date instanceof \DateTimeInterface) {
        return $date->format('Y-m-d');
    }
    $s = (string) $date;
    if ($s === '' || $s === '0000-00-00' || str_starts_with($s, '0000-00-00')) {
        return null;
    }
    return substr($s, 0, 10);  // truncate timestamp na date
}
```

### 5. Rozšíření `LocalIdMap`

Přidat konstantu:

```php
public const ENTITY_PERSON = 'person';
```

### 6. Rozšíření `ImportApp::dispatch()`

Přidat case:

```php
case 'persons':
    return (new runners\PersonsRunner($this->context()))->run();
```

A `printUsage()` aktualizovat.

### 7. Aktualizace `README.md`

V `modules/imports/newShipard/README.md` aktualizovat sekci "Subkomandy":

| Subkomand | Stav |
|---|---|
| `persons` | ✅ Fáze 03 |

A přidat společnou opci `--limit=N` mezi opce.

## Hotovo když

1. **`import.oldShipard`** je v cfgItem `base.persons.sourceKinds` v
   novém Shipardu. `shpd-ds upgrade` proběhl.
2. **`ExchangeClient`** existuje a má `apply` / `preview` / `validate`
   metody, kompatibilní s response shape z `ExchangeController`.
3. **`BaseExchangeRunner`** abstract class implementuje pattern z
   sekce 3 (orchestrace + fast-lookup přes LocalIdMap + status mapping
   z `_resolve.header.status`).
4. **`PersonsRunner`** implementuje per-row mapping z sekce 4 pro
   všechny tabulky (persons + properties + personsContacts + personsBA +
   contacts) a posílá canonical payload na apply endpoint.
5. **`LocalIdMap::ENTITY_PERSON`** konstanta existuje.
6. **`ImportApp::dispatch()`** routuje `persons` na `PersonsRunner`.
   `printUsage()` aktualizován.
7. **`--limit=N`** flag funguje (zpracuje jen prvních N řádků). Užitečné
   pro inkrementální testování velkých DS.
8. **`--dry-run`** vypíše plán bez REST volání.
9. **`-v` / `--verbose`** emituje request/response detaily.
10. **`--continue-on-error`** pokračuje při failed záznamu.
11. **Idempotence ověřená manuálně:** spustit `persons` dvakrát po sobě
    na DS `68908901448295` — druhý běh: `updated=N, created=0` (LocalIdMap
    cache hit + applier `matched` přes companyId/vatId/useExisting).
12. **README aktualizovaný** o `persons` subkomand + `--limit` opce.

## Doporučené pořadí implementace

1. **`import.oldShipard` cfgItem** v novém Shipardu — drobná úprava,
   commit, deploy, `shpd-ds upgrade` na cílový DS.
2. **`ExchangeClient`** — base na `HttpClient.post()`, parse response
   shape `{success, data: {canonical, savedXxxId}}`.
3. **`BaseExchangeRunner`** — abstract metody, generic flow, fast-lookup
   přes LocalIdMap, status mapping.
4. **`LocalIdMap::ENTITY_PERSON`** konstanta + `ImportApp` dispatch +
   `printUsage`.
5. **`PersonsRunner`** — postupně:
   - 5.1 Kostra třídy (`entityType`, `exchangeFlow`, etc.).
   - 5.2 `sourceQuery` + smoke test (vypsat počet rows ze starého DS).
   - 5.3 `buildCanonical` minimal verze (jen format, source, personType,
     name) → otestovat apply na jedné osobě (např. `--limit=1`).
   - 5.4 Postupně přidat `loadProperties`, `loadAddresses`,
     `loadBankAccounts`, `loadContacts` — vždy s `--limit=1` ověřit, že
     payload je validní a applier ho přijme.
   - 5.5 Helpery (`emptyToNull`, `dateToString`, `resolveCountry`, etc.).
6. **Smoke test full**: `persons --limit=10`, ověřit v UI nového Shipardu,
   že 10 osob existuje. Pak `persons` bez limitu (možná tisíce řádků).
7. **Idempotence test**: spustit znovu, čekat `updated=N`.
8. **README aktualizace**.

## Otevřené body / rozhodnutí

### 1. `personId` — generovat vs zachovat

Starý `e10_persons_persons.id` (varchar 16) je "Kód osoby" — někdy
vyplněno, jindy ne. Nový `base_persons_persons.person_id` je analog,
generuje ho `PersonDocument::beforeSave` jako krátký hash.

Phase 03 doporučení: **vždy posílat `personId: null`** v canonical
(viz sekce 4.3). Applier vygeneruje nový. Důvod: starý `id` může
kolikovat s vygenerovaným v novém DS, a obvykle nejde o důležitý business
identifier (IČO/DIČ jsou důležitější).

Alternativa: posílat starý `id` (pokud vyplněn), riskovat 409 conflict.
Pokud uživatel potřebuje zachovat staré "Kódy osob", doplníme v Phase 06
flag `--preserve-person-id`. Pro MVP necháváme null.

### 2. Multi-property pro IČO

Vzácný edge case: osoba má v `e10_base_properties` víc rowů s
`property='oid'` (např. po fúzi firem). `loadProperties` v Phase 03
vezme **poslední** podle order v query — to je nepředvídatelné.

Doporučení: SQL `ORDER BY ndx DESC LIMIT 1` per property. Pak je výběr
deterministic. Logni warning, pokud najdeš víc rowů.

### 3. `divisionCode` (ZÚJ) — nemapujeme

Starý Shipard má `saAdmUnit11Id` (IČZUJ) na `personsContacts`. Nový
schema má `divisionCode` v address sub-objektu, mapuje se na
`world_divisions.code`.

Phase 03 to **nemapuje** — applier použije null. Důvod: starý IČZUJ je
číselný `int`, nový `divisionCode` je string z `world_divisions`. Mapping
vyžaduje JOIN s `world_divisions` a ověření, že kódy se kryjí. To je
detail, který v MVP přeskočíme — adresa se uloží bez ZÚJ a uživatel
ji v UI doplní (pokud chce filtraci podle ZÚJ).

Pokud bude potřeba doplnit, je to lokální změna v `mapAddress`.

### 4. Standardizované adresy (RÚIAN)

Starý Shipard má víc způsobů, jak držet adresu: plain (`adrStreet`/`adrCity`)
nebo standardizovanou (`saStreetName`/`saCityName`/`saStreetId` etc.).
Nový schema má `isStandardized` flag + možnost mít buď standardized nebo
plain.

Phase 03 doporučení: **posílat plain pole** (`street`, `city`, `zip`,
`country`) i pro standardizované adresy. Žádné mapování `registryCode`
(RÚIAN ADM kód). Nový applier je nebude validovat proti registru.

Pokud bude potřeba zachovat RÚIAN propojení, doplníme `registryCode` z
`saAdmUnit11Id` (nebo `natAddressGeoId`) — Phase 06.

### 5. `ambiguous` status — co dělat

Pokud applier vrátí `_resolve.header.status = "ambiguous"` (víc kandidátů
v novém DS pro fuzzy match), Phase 03 runner:

1. Pokud má LocalIdMap cache hit (`cachedNewId`), poslal `useExisting:<id>`
   → applier respektuje a vrátí `matched`. Žádný ambiguous v praxi.
2. Pokud LocalIdMap MISS a applier vrátí `ambiguous` → status `skipped`,
   warning v logu. Uživatel musí ručně rozhodnout v UI nebo opravit
   data v starém DS.

To je v `processOneRow` already implementováno přes match statement.

### 6. `isOwn` — vlastní firma

Starý Shipard označuje "vlastní firmu" přes cfgItem nebo speciální
pattern v `accountType`. Pro Phase 03 hardcodujeme `isOwn = false` pro
všechny — uživatel po importu ručně označí v UI.

Pokud bys chtěl heuristiku (např. firma s konkrétním IČO se označí jako
own), doplníme v Phase 06 přes config soubor (`config/import-newShipard.json`
sekce `options.ownCompanyIcos: ["12345678"]`).

### 7. `createPersonId` flag

`applyOptions.createPersonId: true` — applier vygeneruje `person_id`
pokud chybí. Phase 03 vždy posílá `true`.

Pokud bys chtěl ne-generovat (např. pro testovací importy), bylo by
to změnit. Nemělo by smysl — `person_id` je užitečný identifier v UI.

### 8. Source query `personType` filter

Phase 03 filtruje `personType IN (1, 2)` — Člověk + Firma. `personType = 0`
(undefined) i `personType = 3` (Robot) jsou excluded.

Edge case: starý DS může mít legacy osoby s `personType = NULL` nebo
`personType = 0`. Pokud na ně narazíš a chceš je importovat, doplň do
filtru `IS NULL OR personType IN (0, 1, 2)` a v mappingu udělej fallback
`personType = 0 → "company"`.

### 9. Bank accounts — chybí IBAN, BIC, currency

Starý `personsBA` má jen `bankAccount` (CZ formát). Nový schema přijímá
to v `accountNumber` + null IBAN/BIC. Currency default `czk`.

Pokud applier vyžaduje IBAN (validation), Phase 03 selže. Otestuj proti
reálnému DS — pokud problém, runner musí buď IBAN přeskočit (skip bank),
nebo vygenerovat IBAN z accountNumber přes nějakou knihovnu (out of scope
pro MVP).

### 10. Velké DS — performance

Phase 03 spouští **N × HTTP requestů** per persona (jeden apply). Pro DS
s 10k osobami to je 10k requestů. Při ~100ms per request = ~17 minut.

Pro MVP přijatelné. Pokud bude potřeba batch apply, Phase 06 polish:
- batchování (Phase 06 batch apply v ApplyOptions specifikované; persons
  v Phase 1 to nemá; přidá se v Phase 03 follow-up).
- async pipeline (paralelní HTTP requests, worker pool).

Phase 03 funguje sériově, `--limit=N` pro inkrementální testing.

## Příprava pro Fázi 04+

Po Phase 03:

- LocalIdMap má `ENTITY_PERSON` — Fáze 04 (items) ho použije pro supplier
  resolution v supplierCodes sub-kolekci, Fáze 05 (docs) pro hlavičkového
  partnera.
- `BaseExchangeRunner` je etablovaný — Fáze 04 a 05 ho replikují s vlastní
  `buildCanonical` logikou pro items / docs.
- `ExchangeClient` je stabilní — funkční pro všechny tři exchange flows.
