# Task: Import účtového rozvrhu (Fáze 08)

## Kontext

Nový codebook runner pro import **účtového rozvrhu** ze starého Shipardu
(`e10doc_debs_accounts`) do nového (`economy_accounting_accounts`) přes
generický CRUD. Modul `economy.accounting` v novém Shipardu je hotový
(tabulka, enumy, `AccountDocument`, `AccountChartProvisioner`, seed osnovy).

Z designu uzavřeno:

- **Standardní `BaseCodebookRunner` subclass** — vzor `CostCentersRunner`.
  Idempotence přes `LocalIdMap` (klíč = starý `ndx`), POST create, **žádný
  find-by-number ani PATCH**.
- Migrovaný DS **nemá naseedovanou standardní osnovu** (provisioning vypnutý
  přes `skipProvisioning`), takže nehrozí konflikt na UNIQUE `number`.
- **Runner si `account_level` / `g1` / `g2` / `g3` počítá sám** v `mapRow()`
  (mirror `AccountDocument::deriveStructure`), protože generický
  `CrudController::create()` **`beforeSave` nevolá**. Tyto sloupce nejsou
  `system`, takže projdou `filterWritableFields` a vloží se z payloadu.
  `account_level` je NOT NULL → musí se počítat vždy.
- **`is_system = 0`** — importované účty jsou reálná osnova firmy, ne šablona.
- Mapování enumů ze starého (číselné kódy jsou shodné s novými cfgItemy):
  - `accountKind` → `account_kind`: vynech `99` (→ NULL), **ponech `0` = Aktiva**.
  - `costsType` → `costs_type`: jen je-li `> 0`.
  - `resultsType` → `results_type`: jen je-li `> 0` (vč. `3` = Mimořádný).
  - Staré `accMethod` / `nontax` / `excludeFromReports` / `toBalance` /
    `accItem` / `useFor` / `useBalance` se **ignorují**.
  - Staré `g1`/`g2`/`g3` se **nepřebírají** — re-derivují se z `number`
    (jediný zdroj pravdy, konzistence s UI i provisionerem).
- `short_name` prázdný → fallback na `name` (konzistentní se seed soubory).
- Filtr zdroje: `docState != 9800` (jen smazané ven). Starý `docState`
  namapuje base `mapDocState()` (4000→40, 1000→10, 8000→80, 9000→70).

## Návaznost

- Vzor runneru: `libs/runners/CostCentersRunner.php`.
- Base: `libs/BaseCodebookRunner.php` (`run` / `processRow` / `mapDocState` /
  `dateToString`).
- `libs/CrudClient.php` (`create`), `libs/LocalIdMap.php` (entity konstanty),
  `libs/ImportApp.php` (dispatch + usage), `libs/runners/AllCodebooksRunner.php`
  (SEQUENCE).
- Cíl v novém Shipardu: tabulka `economy_accounting_accounts`, endpoint
  `POST /api/v1/economy_accounting_accounts`.

## Před implementací přečti

- `libs/BaseCodebookRunner.php` — kontrakt template-method, `mapDocState`,
  `dateToString`, idempotence přes `LocalIdMap`.
- `libs/runners/CostCentersRunner.php` — vzor (entityType / targetTable /
  entityLabel / sourceQuery / mapRow).
- `libs/runners/AllCodebooksRunner.php` — `SEQUENCE`.
- `libs/LocalIdMap.php` — konvence `ENTITY_*` konstant + `record` / `lookup`.
- `libs/ImportApp.php` — `case` dispatch a `usage` blok.
- **nov_shipard** (pro mirror derivace a kontrolu sloupců):
  `modules/economy/accounting/src/AccountDocument.php` (`deriveStructure`) a
  `modules/economy/accounting/tables/economy_accounting_accounts.jsonc`
  (povinné `number`/`name`, enum cfgItemy, `is_system`).

## Co implementovat

### 1. `LocalIdMap` — nová entita

Přidat konstantu `ENTITY_ACCOUNT` (hodnota dle existující konvence `ENTITY_*`,
např. `'accounting-account'`).

### 2. `AccountsRunner`

Soubor `libs/runners/AccountsRunner.php`,
`final class AccountsRunner extends BaseCodebookRunner`.

```php
protected function entityType(): string  { return LocalIdMap::ENTITY_ACCOUNT; }
protected function targetTable(): string { return 'economy_accounting_accounts'; }
protected function entityLabel(): string { return 'account'; }

protected function sourceQuery(): array
{
    return [
        'SELECT [ndx], [id], [fullName], [shortName], [accountKind], [costsType],'
        . ' [resultsType], [validFrom], [validTo], [note], [docState]'
        . ' FROM [e10doc_debs_accounts] WHERE [docState] != %i', 9800,
        ' ORDER BY [id]',
    ];
}

protected function mapRow(array $oldRow): ?array
{
    $number = trim((string) ($oldRow['id'] ?? ''));
    if ($number === '') {
        $this->warn('account (old ndx=' . (int) ($oldRow['ndx'] ?? 0) . '): prázdné číslo účtu, skip');
        return null;
    }
    $name  = (string) ($oldRow['fullName'] ?? '');
    $short = trim((string) ($oldRow['shortName'] ?? ''));
    $st    = $this->structure($number);

    $payload = [
        'number'        => $number,
        'name'          => $name,
        'short_name'    => $short !== '' ? $short : $name,
        'account_level' => $st['account_level'],
        'g1'            => $st['g1'],
        'g2'            => $st['g2'],
        'g3'            => $st['g3'],
        'is_system'     => 0,
        'valid_from'    => $this->dateToString($oldRow['validFrom'] ?? null),
        'valid_to'      => $this->dateToString($oldRow['validTo'] ?? null),
    ];

    $note = trim((string) ($oldRow['note'] ?? ''));
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $ak = (int) ($oldRow['accountKind'] ?? 99);
    if ($ak !== 99) {            // 0 = Aktiva se vkládá
        $payload['account_kind'] = $ak;
    }
    $ct = (int) ($oldRow['costsType'] ?? 0);
    if ($ct > 0) {
        $payload['costs_type'] = $ct;
    }
    $rt = (int) ($oldRow['resultsType'] ?? 0);
    if ($rt > 0) {
        $payload['results_type'] = $rt;
    }

    // docState NEnastavujeme — base->processRow ho doplní z mapDocState($oldRow).
    return $payload;
}

/**
 * Mirror nového AccountDocument::deriveStructure().
 * @return array{account_level:int, g1:?string, g2:?string, g3:?string}
 */
private function structure(string $number): array
{
    $len   = strlen($number);
    $level = match (true) {
        $len === 1 => 1, // třída
        $len === 2 => 2, // skupina
        $len === 3 => 3, // syntetika
        default    => 4, // analytický účet
    };
    return [
        'account_level' => $level,
        'g1' => $len >= 1 ? substr($number, 0, 1) : null,
        'g2' => $len >= 2 ? substr($number, 0, 2) : null,
        'g3' => $len >= 3 ? substr($number, 0, 3) : null,
    ];
}
```

### 3. `ImportApp` — dispatch + usage

- `case 'accounts': return (new runners\AccountsRunner($this->context()))->run();`
  (do bloku číselníků, vedle `cost-centers` / `units`).
- Do `usage` přidat řádek, např.:
  `echo "    accounts          Chart of accounts (účtový rozvrh).\n";`

### 4. `AllCodebooksRunner` — sekvence

Přidat `AccountsRunner::class` do `SEQUENCE` (pořadí nezáleží — žádná FK
závislost; klidně za `UnitsRunner::class`).

## Hotovo když

- `shpd-app cli-action --action=imports.newShipard/import accounts` proběhne;
  `--dry-run` vypíše plán bez volání, `-v` ukáže payloady.
- Účty vzniknou v novém Shipardu s:
  - `account_level` + `g1/g2/g3` dopočítanými z `number`,
  - `account_kind` / `costs_type` / `results_type` namapovanými (vč. Aktiva=0
    a Mimořádný=3),
  - `is_system = 0`, `docState` dle starého stavu.
- Hierarchické řádky (třída/skupina/syntetika, staré `accGroup=1`) se
  importují také (`account_level` 1/2/3).
- Re-běh skipuje již importované (LocalIdMap). Čistý re-import =
  `LocalIdMap::forgetAll(ENTITY_ACCOUNT)` (konzistentní s ostatními
  číselníky Fáze 02).
- `all-codebooks` (a tím i `all`) zahrne accounts.

## Otevřené body / poznámky

- **Pozdější import účtování (žurnálu)** bude účty resolvovat — starý
  `journal.accountId = accounts.id` (číslo účtu, ne `ndx`). LocalIdMap
  (`ndx` → newId) k tomu přímo nestačí; ta fáze si doplní lookup účtu podle
  `number`. Mimo scope teď, jen poznámka.
- Idempotence drží `LocalIdMap` na starém `ndx` (stejně jako ostatní
  codebooks). Účty ve stavu 40 jsou v novém Shipardu readOnly — proto se
  záměrně nepatchují.
