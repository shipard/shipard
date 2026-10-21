# `ds-reset` — reset datového zdroje pro opakované testování importů

**Stav:** hotovo

## Status / Cíl

Nový příkaz `shpd-ds ds-reset`, který uvede datový zdroj do čistého stavu
pro opakovaný test kompletního importu ze starého Shipardu. Smaže všechny
„datové" tabulky (číselníky, osoby, položky, doklady, došlá pošta, …),
ponechá „systémové" (uživatelé, API klíče, AI backend s klíčem) a znovu
vytvoří schéma plus referenční data delegací na `ds-upgrade`.

Které tabulky reset přežijí, se řeší **deklarativně** novým polem
`keepOnReset` v `module.jsonc` — každý modul si sám určí, co je systém
a co data. Funguje to i pro third-party moduly z `extraModulesPath`.

## Návaznost

- Vychází z `DsUpgradeCommand`
  (`src/Command/DataSource/DsUpgradeCommand.php`) — ten zůstává **nedotčený**.
  `ds-reset` ho po dropnutí tabulek jen spustí v rámci stejného procesu
  (`getApplication()->find('ds-upgrade')->run(...)`), čímž zdarma získá
  rekreaci schématu (`CREATE TABLE`) i běh všech idempotentních
  provisionerů (units, item kinds, fiskální roky, VAT období, číselné řady,
  mail router, AI analyzer).
- Klíčová vlastnost schématu: **Shipard nepoužívá databázové cizí klíče**
  (viz `docs/table-definitions.md` §9). Tabulky lze proto dropovat
  v libovolném pořadí bez řešení FK constraintů — jedním
  `DROP TABLE IF EXISTS a, b, c`.
- Vzor destruktivního příkazu: `SeedClearCommand`. Vzor potvrzení přes
  `QuestionHelper`: standardní Symfony Console.
- Test harness: `tests/Unit/Command/DataSource/DsUpgradeCommandTest.php`
  (subclassing `Testable*Command` + mock `DataSourceConfig`/`DataSourceConnection`
  + `CommandTester`).

## Mechanismus

1. `keepOnReset` v `module.jsonc` = pole názvů **vlastních** tabulek modulu,
   které reset nesmaže.
2. `ds-reset` zresolvuje aktivní moduly (jako `ds-upgrade`), posbírá
   `keepSet` = sjednocení všech `keepOnReset`.
3. `SHOW TABLES` → existující tabulky. `dropSet` = existující − `keepSet`
   (− případné ad-hoc `--keep`). Tím se uklidí i osiřelé tabulky po
   odebraných modulech.
4. Po potvrzení: `DROP TABLE IF EXISTS …` na `dropSet`, vyčištění obsahu
   `att/` a `cache/thumbnails/` (jen pokud se dropuje `core_attachments_files`),
   a delegace na `ds-upgrade`.
5. Doplněno 2026-10-21 (#131 D42): `clearSettingsOnReset` v `module.jsonc`
   = objekty `{key, table}` — nastavení v `core_system_settings` s id řádku
   tabulky `table`. Reset klíč smaže hned po DROP (jen když `table` dropuje),
   `ds-upgrade` ho nastaví znovu. Důvod: provisionery vyplněný klíč
   nepřepisují, takže řada „Majetek“ po resetu nevznikala a klíč mířil na
   cizí řadu. Viz `docs/modules.md` → `clearSettingsOnReset`.

## Co je potřeba udělat

### 1. `ModuleDefinition` — nové pole `keepOnReset`

Soubor `src/Core/Module/ModuleDefinition.php`.

Přidat readonly property **na konec** konstruktoru (aby se nerozbila
poziční konstrukce v existujícím kódu — `lookups`/`alertChecks` už mají
defaulty):

```php
public readonly array $alertChecks = [],
public readonly array $keepOnReset = [],
```

V `fromArray()` zparsovat a zvalidovat — položky musí být neprázdné
stringy a **musí to být vlastní tabulky modulu** (záchyt překlepů a
zákaz „chránit" cizí tabulku):

```php
$keepOnReset = [];
if (isset($data['keepOnReset'])) {
    if (!is_array($data['keepOnReset']) || !array_is_list($data['keepOnReset'])) {
        throw new \InvalidArgumentException(
            "Module '{$data['id']}': keepOnReset must be a JSON array of table names",
        );
    }
    $ownTables = $data['tables'] ?? [];
    foreach ($data['keepOnReset'] as $i => $t) {
        if (!is_string($t) || $t === '') {
            throw new \InvalidArgumentException(
                "Module '{$data['id']}': keepOnReset[{$i}] must be a non-empty string",
            );
        }
        if (!in_array($t, $ownTables, true)) {
            throw new \InvalidArgumentException(
                "Module '{$data['id']}': keepOnReset[{$i}] '{$t}' is not a table owned by this module",
            );
        }
        $keepOnReset[] = $t;
    }
}
```

A předat v `return new self(...)` jako pojmenovaný argument
`keepOnReset: $keepOnReset,`.

### 2. Naplnit `keepOnReset` ve dvou modulech

**`modules/core/system/module.jsonc`** — přidat (chráníme všechny jeho
tabulky: přihlášení, relace, nastavení, API klíče, rate limity):

```jsonc
"keepOnReset": [
    "core_system_users",
    "core_system_sessions",
    "core_system_settings",
    "core_system_api_keys",
    "core_system_rate_limits"
],
```

**`modules/core/mail/module.jsonc`** — přidat (chráníme jen AI backend
kvůli zašifrovanému API klíči z `ai-analyzer-set-key`; schránky, profily,
zprávy, analýzy i extrahované doklady se resetují):

```jsonc
"keepOnReset": [
    "core_mail_ai_backends"
],
```

Umístit pole logicky (např. za `tables`). Žádný jiný modul `keepOnReset`
nedostává → všechny ostatní tabulky se resetují.

### 3. `DataSourceConnection` — `getAllTableNames()`

Soubor `src/Core/Database/DataSourceConnection.php`. Přidat metodu (SHOW
TABLES vrací řádky s proměnným názvem sloupce `Tables_in_<db>`, proto se
bere první hodnota):

```php
/** @return string[] all base table names in the database */
public function getAllTableNames(): array
{
    $rows = $this->connection->query('SHOW TABLES')->fetchAll();
    $names = [];
    foreach ($rows as $row) {
        foreach ($row as $value) {
            $names[] = (string) $value;
            break;
        }
    }
    return $names;
}
```

### 4. Nový soubor: `src/Command/DataSource/DsResetCommand.php`

Symfony Console příkaz, namespace `Shipard\Command\DataSource`, strict_types.
Protected metody jako testovací švy (stejný princip jako `DsUpgradeCommand`).

#### `configure()`

- `setName('ds-reset')`
- `setDescription('Reset data source — drop all data tables and recreate them via ds-upgrade, keeping users, API keys and other protected tables')`
- Opce:
  - `--keep` (`VALUE_REQUIRED | VALUE_IS_ARRAY`) — ad-hoc další tabulka(y)
    k zachování (opakovatelné); aditivní k deklarativní `keepOnReset`
  - `--dry-run` (`VALUE_NONE`) — vypsat drop/keep, nic neměnit
  - `--yes` / `-y` (`VALUE_NONE`) — přeskočit potvrzení

#### Konstruktor a protected švy

```php
public function __construct(
    private readonly ?DataSourceConfig $dsConfig = null,
    private readonly ?DataSourceConnection $dsConnection = null,
    private readonly ?ServerConfig $serverConfig = null,
) {
    parent::__construct();
}

protected function getDataSourceDir(): string
{
    return getcwd();
}

protected function getModulePathResolver(): ModulePathResolver
{
    $cfg = $this->serverConfig;
    if ($cfg === null) {
        $cfg = new ServerConfig();
        $cfg->load();
    }
    return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
}

/** Server mode; 'development' když server.json chybí (produkce ho má vždy). */
protected function getServerMode(): string
{
    try {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return $cfg->getMode();
    } catch (\Throwable) {
        return 'development';
    }
}

/** Spustí ds-upgrade v rámci stejného procesu. Samostatná metoda kvůli test override. */
protected function runUpgrade(OutputInterface $output): int
{
    $app = $this->getApplication();
    if ($app === null) {
        $output->writeln('<error>Cannot run ds-upgrade: no application context</error>');
        return Command::FAILURE;
    }
    return $app->find('ds-upgrade')->run(new ArrayInput([]), $output);
}

/** Rekurzivně smaže *obsah* adresáře, samotný adresář ponechá. */
protected function cleanDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $this->cleanDir($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
```

#### `execute()` flow

1. **Guard adresáře DS:** když `dsConfig === null` a chybí
   `config/main.json` → `<error>` + FAILURE (vzor `SeedClearCommand`).
2. **Produkční pojistka:** když `getServerMode() === 'production'` → tvrdě
   odmítnout:
   ```php
   $output->writeln('<error>Refusing to reset a data source in production mode.</error>');
   $output->writeln('ds-reset is a destructive development/testing tool.');
   return Command::FAILURE;
   ```
3. Sestavit `$dsConfig` / `$dsConnection` / `$modulePathResolver` (lazy,
   jako v `DsUpgradeCommand`).
4. **Zresolvovat moduly** a posbírat `keepSet`:
   ```php
   $allModules = ModuleLoader::loadAllModules($modulePathResolver);
   $errors = [];
   $resolvedModules = ModuleResolver::resolve($allModules, $dsConfig->getModules(), $errors);
   foreach ($errors as $error) {
       $output->writeln('<error>' . $error . '</error>');
   }

   $keepSet = [];
   foreach ($resolvedModules as $module) {
       foreach ($module->keepOnReset as $t) {
           $keepSet[$t] = true;
       }
   }
   foreach ($input->getOption('keep') as $t) {
       $keepSet[(string) $t] = true;
   }
   ```
5. **Spočítat dropSet/keptList:**
   ```php
   $existing = $dsConnection->getAllTableNames();
   sort($existing);
   $dropList = array_values(array_filter($existing, fn(string $t) => !isset($keepSet[$t])));
   $keptList = array_values(array_filter($existing, fn(string $t) =>  isset($keepSet[$t])));
   ```
6. **Report** (vždy): název DS + id, seznam `[keep]` a `[drop]` tabulek
   s počty. Pokud `in_array('core_attachments_files', $dropList, true)`,
   informovat, že se vyčistí `att/` a `cache/thumbnails/`.
7. **`--dry-run`** → `<info>--dry-run: nothing changed.</info>` + SUCCESS.
8. **Prázdný dropSet** → „Nothing to drop. Running ds-upgrade to ensure
   schema..." + `return $this->runUpgrade($output);`.
9. **Potvrzení** (pokud ne `--yes`): `ConfirmationQuestion`
   `'Drop N table(s) and recreate? [y/N] '` (default `false`). Při „ne"
   → „Aborted." + SUCCESS.
10. **Drop:**
    ```php
    $quoted = array_map(fn(string $t) => '`' . $t . '`', $dropList);
    $dsConnection->executeSQL('DROP TABLE IF EXISTS ' . implode(', ', $quoted));
    ```
11. **Čištění složek** (jen když se dropoval `core_attachments_files`):
    `cleanDir($dsDir . '/att')` + `cleanDir($dsDir . '/cache/thumbnails')`.
    (`ds-upgrade` prázdné adresáře následně znovu zajistí.)
12. **Rekreace:** `runUpgrade($output)`. Když ≠ SUCCESS → `<error>` se
    zprávou „Re-run ds-upgrade to recover." + FAILURE.
13. Závěr: `<info>Data source reset complete.</info>` + SUCCESS.

Importy: `DataSourceConfig`, `ServerConfig`, `DataSourceConnection`,
`ModuleLoader`, `ModulePathResolver`, `ModuleResolver`, Console `Command`,
`ArrayInput`, `InputInterface`, `InputOption`, `OutputInterface`,
`ConfirmationQuestion`.

### 5. Registrace v `bin/shpd-ds`

Přidat k ostatním DS příkazům (logicky hned za `DsUpgradeCommand`):

```php
$app->add(new \Shipard\Command\DataSource\DsResetCommand());
```

### 6. Update `src/Command/DataSource/HelpCommand.php`

Do sekce `Basic:` přidat řádek (drž zarovnání stávajícího stylu):

```php
$output->writeln('  <info>ds-reset</info>                Reset the data source — drop all data tables and recreate them, keeping users, API keys and protected tables');
```

Do sekce `Examples:` přidat:

```php
$output->writeln('  shpd-ds ds-reset --dry-run');
$output->writeln('  shpd-ds ds-reset -y');
```

### 7. Testy

#### 7a. `tests/Unit/Core/Module/ModuleDefinitionTest.php` — doplnit

- `keepOnReset` se zparsuje do property (modul s `tables` obsahujícími
  danou tabulku).
- Chybějící `keepOnReset` → property je `[]`.
- Položka mimo vlastní `tables` → `InvalidArgumentException`
  (assert message obsahuje „not a table owned by this module").
- Nestringová / prázdná položka → `InvalidArgumentException`.

#### 7b. Nový `tests/Unit/Command/DataSource/DsResetCommandTest.php`

Vzor 1:1 jako `DsUpgradeCommandTest` — temp dir s fixture modulem,
mock `DataSourceConfig`/`DataSourceConnection`, `CommandTester`.

`TestableDsResetCommand` přepíše:
- `getDataSourceDir()` → temp dsDir
- `getModulePathResolver()` → `new ModulePathResolver([$modulesPath])`
- `getServerMode()` → konfigurovatelně (default `'development'`)
- `runUpgrade()` → zaznamená volání (`public bool $upgradeRan = false;`)
  a vrátí `Command::SUCCESS` (žádný reálný ds-upgrade v testu)

Fixture modul má `keepOnReset` (např. `keep_me`) a `tables`
`['keep_me', 'drop_me']`. Mock `getAllTableNames()` vrací
`['keep_me', 'drop_me', 'orphan_table']`. `executeSQL` zachytávat do pole.
Příkaz přidat do `new Application()` (kvůli `QuestionHelper` a
`getApplication()`).

Test cases:
- **dropSet vylučuje keep:** spustit s `-y`, ověřit, že zachycené
  `DROP TABLE` obsahuje `drop_me` a `orphan_table`, **ne** `keep_me`;
  `upgradeRan === true`.
- **`--dry-run`:** žádné `executeSQL`, `upgradeRan === false`, výstup
  obsahuje `[drop] drop_me` i `[keep] keep_me`.
- **produkční mód:** `getServerMode()` → `'production'` → FAILURE,
  žádné `executeSQL`, výstup „production mode".
- **potvrzení odmítnuto:** bez `-y`, `$tester->setInputs(['n'])` →
  žádné `executeSQL`, `upgradeRan === false`, výstup „Aborted".
- **potvrzení přijato:** bez `-y`, `setInputs(['y'])` → drop proběhne,
  `upgradeRan === true`.
- **`--keep=orphan_table`:** ad-hoc keep → `orphan_table` v keep,
  není v `DROP`.
- **prázdný dropSet:** `getAllTableNames()` vrací jen `keep_me` →
  žádné `executeSQL`, ale `upgradeRan === true`.
- **čištění složek:** přidat `core_attachments_files` do
  `getAllTableNames()`, vytvořit `dsDir/att/x.bin` a
  `dsDir/cache/thumbnails/y.jpg`, spustit s `-y`, ověřit, že soubory jsou
  pryč a adresáře `att/` + `cache/thumbnails/` existují.
- **adresář bez core_attachments_files:** vytvořit soubor v `att/`, ale
  `core_attachments_files` není mezi tabulkami → soubor zůstane (čištění
  se nespustí).

### 8. Dokumentace

#### 8a. `docs/cli.md` — sekce `shpd-ds`

Přidat podsekci `### ds-reset` (hned za `### ds-upgrade`). Popsat:
účel (čistý stav pro opakovaný import), že drží users + API klíče + AI
backend přes deklarativní `keepOnReset`, že dropuje vše ostatní (vč.
osiřelých tabulek) a deleguje na `ds-upgrade`, čištění `att/` +
`cache/thumbnails/`, produkční pojistka. Tabulka opcí (`--keep`,
`--dry-run`, `--yes`/`-y`). Příklady:

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-reset --dry-run     # co by se smazalo
sudo shpd-ds ds-reset               # interaktivní potvrzení [y/N]
sudo shpd-ds ds-reset -y            # bez potvrzení (skriptování)
sudo shpd-ds ds-reset --keep=core_mail_mailboxes -y
```

Do sekce **Workflow scénáře** přidat scénář „Opakovaný test importu":

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-reset -y            # čistý stav (zůstane login + importní API klíč)
# … spustit import ze starého Shipardu …
```

Do sekce **Konvence → Idempotence** poznámku, že `ds-reset` je
**záměrně destruktivní** (ne idempotentní v běžném smyslu — vždy dropuje
a rekreuje), ale je bezpečný k opakování.

#### 8b. `docs/modules.md` — pole `keepOnReset`

Do referenční sekce polí `module.jsonc` přidat popis `keepOnReset`:
volitelné pole, pole názvů **vlastních** tabulek modulu, které příkaz
`shpd-ds ds-reset` nesmaže (systémové/konfigurační tabulky vs. data).
Validace: položky musí být vlastní tabulky modulu (jinak fatální chyba
při načtení modulu). Příklad `core.system` (všech 5 tabulek) a `core.mail`
(`core_mail_ai_backends` kvůli zašifrovanému AI klíči). Zmínit, že to
funguje i pro third-party moduly z `extraModulesPath`.

## Co netřeba

- Žádný TRUNCATE — vědomě `DROP` + rekreace (čistý stav vč. schématu).
- Žádné obalování dropů do transakce — DDL je v MariaDB stejně mimo
  transakci; při selhání v půlce dorovná opakovaný `ds-upgrade`.
- Žádné řešení FK pořadí — Shipard FK nepoužívá.
- Žádný `--keep-module` — deklarativní `keepOnReset` + ad-hoc `--keep`
  pokrývají potřeby.
- Neměnit `DsUpgradeCommand` ani provisionery.

## Konvence k dodržení

- PHP: `declare(strict_types=1)`, PSR-4, namespace
  `Shipard\Command\DataSource`.
- Výstupní tagy `<info>` / `<comment>` / `<error>` (NE `<e>` — viz
  `docs/cli.md`).
- Czech v UI textech a docs, English v kódu a identifikátorech.
- Test pattern: subclassing přes `Testable*Command` + `CommandTester`
  + mock `DataSourceConfig`/`DataSourceConnection`, ne reálná DB.
- Potvrzení přes `QuestionHelper`/`ConfirmationQuestion`, default `false`.

## Hotovo když

- `vendor/bin/phpunit` — vše prochází včetně nových testů
  (`ModuleDefinitionTest` doplnění + `DsResetCommandTest`).
- `php bin/shpd-ds help` zobrazuje `ds-reset`, žádný `<e>` tag.
- `php bin/shpd-ds ds-reset --dry-run` v reálném DS vypíše seznam
  `[keep]`/`[drop]` a nic nezmění.
- `php bin/shpd-ds ds-reset -y` v dev DS: dropne datové tabulky, ponechá
  `core_system_*` + `core_mail_ai_backends`, vyčistí `att/` +
  `cache/thumbnails/`, doběhne `ds-upgrade` a DS je v čistém
  importovatelném stavu (login i importní API klíč fungují, AI klíč
  zůstal nastavený).
- Reset na DS v `production` módu skončí FAILURE bez jakéhokoliv dropu.
- Překlep v `keepOnReset` (cizí/neexistující tabulka) je odhalen jako
  fatální chyba při `ds-upgrade`/`ds-reset`.
- `docs/cli.md` má sekci `ds-reset` + workflow scénář; `docs/modules.md`
  popisuje pole `keepOnReset`.
