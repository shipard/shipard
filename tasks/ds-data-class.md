# Třída dat zdroje dat, `ds-list` a serverové AI podle třídy (#126 task A)

**Stav:** naplánováno — PRD k implementaci (#126 D6–D12, upřesnění U1–U8 potvrzena 2026-10-16)

> PRD pro Claude Code (7 commitů). Design: issue #126, komentář
> „Rozhodnutí (2026-10-16)“, body D6–D12. Task B (tajemství do `secrets/`,
> D1–D5) je samostatný a na tomto nezávisí. Navazuje na serverové AI
> připojení pro vývoj (`tasks/testing-phase4.md` D40), přepínač periodických
> úloh (`tasks/cron-ds-switch.md`) a vývojářský dashboard ve Svelte
> (`tasks/testing-phase6a.md`). Roadmapa: `ds-list` z M5 vzniká dřív jako
> vývojářský nástroj; `ds-delete` a záloha / obnova zůstávají v M5.

## Kontext

Přehled o zdrojích dat na vývojářském stroji je dnes jen v ručně vedené
tabulce v `CLAUDE.local.md` (účel, původ, režim *volný* / *reálná kopie*).
Claude se podle ní řídí při mutacích, ale tabulka zastarává a nic ji
nekontroluje. Serverové AI připojení pro vývoj řídí samostatný příznak
`ai.useServerConnection` v `config/main.json`, který s režimem nesouvisí —
cíl importu založený bez `--no-server-ai` ho dostane omylem.

Task zavádí **třídu dat** přímo v `config/main.json` (`free` / `realCopy`,
s účelem a původem), odvozuje z ní serverové AI připojení, přidává
`shpd-server ds-list` jako jediný zdroj přehledu (i pro Clauda) a třídu
ukazuje tam, kde hrozí omyl: v dotazu `ds-reset` a pruhem v aplikaci.

Chybějící třída = `realCopy` (D6). Po nasazení jsou tedy **všechny
stávající zdroje dat `realCopy`**, dokud je vlastník stroje neoznačí —
včetně seedovaných sad, které do označení ztratí serverové AI připojení
(oddíl „Nasazení a ověření“).

## Před implementací přečti

- Issue #126 — komentář „Rozhodnutí (2026-10-16)“ (popis issue je historický)
- `docs/ai-workflow.md` §3 a §7, `CLAUDE.local.example.md`
- `docs/cli.md` § `ds-create`, `ds-cron`, `dev-ai-key`, `ds-reset`,
  `dataset-seed`, workflow 2 a 7
- `docs/ai.md` (serverové připojení), `docs/datasets.md` (`provision`)
- `src/Core/Config/DataSourceConfig.php`
- `src/Core/Server/MainConfigFile.php`, `src/Core/Server/MainConfigPatcher.php`
- `src/Command/Server/DsCreateCommand.php`, `DsCronCommand.php`,
  `DomainListCommand.php`, `src/Cli/ServerApplicationFactory.php`
- `src/Command/DataSource/DsStateCommand.php` (vzor příkazu s akcemi),
  `DsResetCommand.php`, `DatasetSeedCommand.php`, `DsUpgradeCommand.php`,
  `src/Cli/DsApplicationFactory.php`
- `modules/core/exchange/src/Dataset/DatasetManifest.php`,
  `tests/datasets/cz/*/manifest.jsonc`
- `src/Core/Ai/AiBackendResolver.php`, `ServerAiConnection.php`,
  `AiFunctionResolver.php` (hláška override),
  `src/Command/Server/DevAiKeyCommand.php`
- `src/Core/Server/DataSourceCronSwitch.php`, `DataSourceStateScanner.php`,
  `HostingSyncRunner.php` (`isModuleActiveForDs`)
- `src/Api/Controller/DevDashboardController.php` (`listDataSources`,
  `dsCreate`, `runDsCreatePipeline`),
  `frontend/src/dev/pages/DataSourcesPage.svelte`, `DsCreatePage.svelte`
- `src/Api/Controller/AppController.php` (`info`), `public/index.php`
  (`dispatchApp`), `frontend/src/stores/appInfo.svelte.js`,
  `frontend/src/components/ui/CronOffBanner.svelte`,
  `frontend/src/components/layout/ContentArea.svelte`,
  `frontend/src/i18n/cs.js`, `en.js`

## Scope

**Uvnitř:** třída, účel a původ v `main.json`; zápis třídy v `ds-create`,
`dataset-seed` a novém `shpd-ds ds-class`; serverové AI podle třídy a zánik
příznaku `ai.useServerConnection`; sdílený čtenář seznamu zdrojů dat
a `shpd-server ds-list [--json]`; třída v dotazu `ds-reset`
a `dataset-seed`; pruh „Kopie reálných dat“; sloupce a pole Účel ve
vývojářském dashboardu; testy; dokumentace.

**Mimo:**

- tajemství mimo `main.json` a uvolnění pravidla pro AI (D1–D5, task B) —
  `ds-list` ale tajemství nevypisuje už teď (oddíl 5),
- automatika cronu podle třídy (D7) — cron dál řídí `--no-cron`
  a `ds-state cron`,
- obnova ze zálohy a `ds-delete` (M5),
- osnova ve výpisu (D10),
- opt-out serverového AI pro volný zdroj (D9),
- změna třídy z dashboardu nebo z aplikace,
- sloučení stavových pruhů do jednoho řádku (#128),
- `enableReset` (D8 — beze změny),
- uživatelská nápověda v `help/` — pruh říká sám, co se děje.

## 1. Třída v `config/main.json` (D6)

```json
{
  "id": "…",
  "name": "…",
  "dataClass": "free",
  "purpose": "Ruční zkoušení UI",
  "origin": "dataset-seed cz/vat-payer",
  "dataClassChanged": "2026-10-16T12:00:00Z",
  "…": "ostatní klíče beze změny"
}
```

- **`dataClass`** — `free` nebo `realCopy`. Klíč chybí, je `null`, má jiný
  typ nebo neznámou hodnotu → platí `realCopy` (fail-safe). Neplatná hodnota
  zdroj dat **nezavírá** a nic nevyhazuje — ukáže ji `ds-class` a `ds-list`
  (U6).
- **`purpose`** — volný text, jeden řádek, nejvýš 200 znaků; prázdný = bez
  účelu.
- **`origin`** — kdo třídu naposledy nastavil: `ds-create`,
  `ds-create --real-copy`, `dataset-seed <název sady>`, `ds-class`.
  Zapisuje ho příkaz, ručně se nevyplňuje.
- **`dataClassChanged`** — ISO 8601 v UTC, kdy se třída naposledy nastavila
  (U1).

Kód:

- Enum `Shipard\Core\Config\DataClass: string` — `Free = 'free'`,
  `RealCopy = 'realCopy'`; `fromConfig(mixed $raw): self` (jen přesně
  řetězec `'free'` je `Free`), `isValidConfigValue(mixed $raw): bool`.
- `DataSourceConfig`: `getDataClass(): DataClass`, `isFree(): bool`,
  `hasExplicitDataClass(): bool` (klíč je a hodnota je platná),
  `getPurpose(): ?string`, `getOrigin(): ?string`,
  `getDataClassChanged(): ?string`. Povinná pole v `load()` se nemění.
- `MainConfigPatcher` (čisté funkce):
  - `withDataClass(array $config, DataClass $class, string $origin, string $changedAt): array`
    — nastaví `dataClass`, `origin`, `dataClassChanged` a odebere zaniklý
    `ai.useServerConnection` (prázdná sekce `ai` zmizí, ostatní klíče `ai`
    zůstávají),
  - `withPurpose(array $config, ?string $purpose): array` — `null` nebo
    prázdný řetězec klíč odebere,
  - `normalizePurpose(string $raw): string` — trim, jeden řádek bez
    řídicích znaků, nejvýš 200 znaků; jinak `\InvalidArgumentException`.
    Jediná validace účelu pro `ds-create`, `ds-class` i dashboard.
- Zápis do existujícího souboru vždy přes `MainConfigFile::patch`
  (atomicky, `0600`, vlastník podle `config/` i pod rootem). `ds-create`
  soubor zakládá sám jako dnes — klíče jen přidá do `$mainConfig`.

## 2. Kdo třídu nastavuje (D7)

### `ds-create`

| Režim serveru | bez volby | `--real-copy` |
|---------------|-----------|---------------|
| `development` | `free`, původ `ds-create` | `realCopy`, původ `ds-create --real-copy` |
| `production` | `realCopy`, původ `ds-create` | `realCopy`, původ `ds-create --real-copy` |

- `--purpose=<text>` volitelně. Neplatný účel = exit 1 **před** založením
  databáze.
- `--real-copy` cron nemění (D7). Bez `--no-cron` souhrn jen připomene:
  `Cron: on (a real copy usually runs without periodic jobs — --no-cron or shpd-ds ds-state cron --off)`.
- `--no-server-ai` zaniká (D9). Jedno vydání zůstane jako zastaralý alias
  `--real-copy` s popisem `Deprecated — use --real-copy` a upozorněním ve
  výstupu (U3).
- Souhrn: řádky `Data class:` a `Purpose:`; v režimu `development` řádek
  `Server AI:` podle oddílu 4 — `on (free data source)`,
  `off (realCopy)`, `off (no server AI key — shpd-server dev-ai-key)`.
- Provisioning hostingu (`ds-create --ds-id` v produkci) dostane `realCopy`
  bez další změny.

### `dataset-seed`

- **S resetem:** po **úspěšném** resetu nastaví `free`, původ
  `dataset-seed <name z manifestu>`. Účel = `title` manifestu, jen když
  zdroj dat účel nemá (U2). Selže-li reset, třída se nemění — data jsou
  původní.
- Přechod `realCopy` → `free` tu interaktivní potvrzení nevyžaduje (U4):
  reset reálná data odstraní dřív, než se třída změní, a dotaz seedu třídu
  pojmenuje (oddíl 6). Platí v obou režimech serveru (zdroj s `enableReset`
  na produkci).
- **`--no-reset`:** třídu ani účel nemění.
- Manifest `provision.serverAi` zaniká (D9): pryč z
  `DatasetManifest::PROVISION_KEYS` (neznámý klíč je pak chyba manifestu
  jako dnes), `wantsServerAi()`, seam `enableServerAi()` a ze tří manifestů
  `tests/datasets/cz/*/manifest.jsonc`. Seed vypíše `Data class: free`
  a stav serverového AI stejně jako `ds-create`.

### `ds-reset`

Třídu nemění — cíl importu po `ds-reset -y` a novém importu zůstane
`realCopy`.

### Hosting, `hosting-sync`

Beze změny.

## 3. `shpd-ds ds-class` (D8)

```
shpd-ds ds-class                                   show (výchozí akce)
shpd-ds ds-class set realCopy [--purpose=<text>]
shpd-ds ds-class set free [--purpose=<text>]       přechod na free jen interaktivně
```

- Nový `DsClassCommand` v `src/Command/DataSource/`, registrace
  v `DsApplicationFactory` vedle `ds-state`. Struktura podle
  `DsStateCommand` (akce jako argument, seamy `getDataSourceDir()`, `now()`).
- **show:** třída (u výchozí `not set in main.json — counts as realCopy`,
  u neplatné `invalid value '…' — counts as realCopy`), účel, původ, čas
  změny; v režimu `development` efektivní serverové AI (oddíl 4);
  upozornění na zaniklý `ai.useServerConnection`, je-li v souboru.
- **set realCopy:** bez potvrzení, kdykoli — bezpečný směr.
- **set free** na zdroji, který `free` není (explicitně ani výchozí):
  - `!$input->isInteractive()` → chyba
    `Marking a data source as free requires an interactive terminal — run the command yourself.`,
    exit 1, soubor beze změny. Symfony přepne vstup na neinteraktivní
    s `-n` i tehdy, když stdin není terminál, takže přes MCP most ani
    v Claude Code přechod neprojde. Je to pojistka proti omylu, ne proti
    úmyslnému obejití.
  - jinak `ConfirmationQuestion` (výchozí ne) s názvem zdroje, třídou
    a účelem, např.
    `Data source '<name>' is a real copy (purpose: …). Mark it as free — Claude may then change it without approval and, on a development server, it uses the server AI connection? [y/N]`.
  - příkaz **nemá** volbu `--yes`.
- **Stejná třída:** třída, původ ani čas se nemění; `--purpose` se zapíše,
  je-li uvedené; výstup `unchanged`. `set free --purpose=…` na zdroji, který
  už `free` je, tedy nic nepotvrzuje.
- Zápis: `MainConfigFile::patch` + `withDataClass(…, 'ds-class', now)`,
  případně `withPurpose`. Funguje v obou režimech serveru.
- Exit 1: není adresář zdroje dat, neznámá akce nebo třída, neplatný účel,
  přechod na `free` bez terminálu, chyba zápisu. Zamítnutý dotaz =
  `Aborted.`, exit 0 (jako `ds-reset`).

## 4. Serverové AI podle třídy (D9)

Serverové připojení smí zdroj dat použít, když platí všechno: režim
serveru `development`, třída `free`, serverový klíč existuje
(`ServerAiConnection::detect() !== null`). Vlastní aktivní připojení
s klíčem má dál přednost. Opt-out se nezavádí.

- `AiBackendResolver::serverBackend()`: `usesServerAiConnection()` →
  `isFree()`. Zbytek beze změny.
- Odebrat `DataSourceConfig::usesServerAiConnection()`;
  `MainConfigPatcher::withServerAi()` odchází až s posledním zapisovatelem
  (commit 3).
- Texty: hláška override v `AiFunctionResolver`
  (`… a development server with a dev-ai-key and a free data source (dataClass) are required`),
  řádek *Who uses it* v `DevAiKeyCommand`, docblocky `ServerAiConnection`,
  `AiBackendResolver`, `DataSourceConfig`.
- **Zaniklý příznak.** `ai.useServerConnection` se nečte. `ds-upgrade` na
  serveru v režimu `development` u zdroje s `ai.useServerConnection: true`
  **bez explicitní třídy** vypíše varování, i bez `-v`:
  `[WARN] main.json ai.useServerConnection is no longer used — without a data class this data source counts as realCopy (no server AI). If it holds no real data: shpd-ds ds-class set free`.
  Klíč sám neodebírá a třídu nenastavuje (D9) — klíč zmizí s prvním
  zápisem třídy (`withDataClass`), varování do té doby opakuje (U8).
  Režim serveru přes seam jako `DsResetCommand::getServerMode()`;
  v produkci nic.

## 5. Sdílený čtenář a `shpd-server ds-list` (D10)

### `DataSourceInventory`

Nové `src/Core/Server/DataSourceInventory.php` a `DataSourceInfo`
(readonly hodnota):

- `scan(): list<DataSourceInfo>` — adresáře `data-sources/*`
  s `config/main.json`, seřazené podle ID; `read(string $dsDir): ?DataSourceInfo`
  pro jeden zdroj (`null` bez `main.json`).
- `main.json` čte shovívavě: nečitelný nebo nevalidní soubor dá záznam
  s `readable = false` a názvem `?`, nikdy nevyhodí. `state.json` přes
  `DataSourceState::load` (poškozený = `corrupted`).
- **Allowlist.** `DataSourceInfo` nese jen: `id` (název adresáře), `name`,
  `created`, `country`, `defaultLanguage`, `modules` (přímé moduly
  z `main.json`), `databaseName`, `dataClass`, `dataClassExplicit`,
  `dataClassInvalid`, `purpose`, `origin`, `dataClassChanged`, `state`
  (efektivní stav nebo `corrupted`), `cronEnabled`, `legacyServerAiFlag`,
  `readable`. Nic jiného z `main.json` do struktury nevstoupí — heslo
  k databázi, `auth`, `mail` ani budoucí klíče. Výpis je tak bez tajemství
  nezávisle na tasku B (D5).
- Efektivní serverové AI: `DataSourceInfo::usesServerAi(bool $serverOffersAi): bool`
  = `$serverOffersAi && dataClass === Free`. `$serverOffersAi` počítá
  volající jednou (`ServerAiConnection::detect() !== null`).

### Převod stávajících čtenářů `main.json`

- `DevDashboardController::listDataSources` — přes inventář. Stávající
  klíče JSON (`id`, `name`, `created`, `database_name`, `cron`, `state`)
  zůstávají, přibydou `dataClass`, `dataClassExplicit`, `purpose`,
  `origin`, `dataClassChanged`, `serverAi`. Řazení podle názvu zůstává.
- `DataSourceCronSwitch::displayName` a `DomainListCommand::resolveDsName`
  — název přes `read()`; chování při chybě beze změny (`?`, `<unknown>`).
- `HostingSyncRunner::isModuleActiveForDs` — přímé moduly přes `read()`.
- `DataSourceStateScanner` (`doctor`, `ds-state-check`, `ds-cron`) zůstává
  — čte jen `state.json`.
- Po převodu parsuje `main.json` jen `DataSourceConfig`, `MainConfigFile`
  a inventář (`DsCreateCommand` soubor jen zakládá). Ověřit
  `grep -rn --include=*.php "main.json" src` a projít výsledky.

### `shpd-server ds-list [--json]`

- Nový `DsListCommand` v `src/Command/Server/`, registrace
  v `ServerApplicationFactory`; adresář zdrojů dat přes seam
  `getDataSourcesDir()` jako `DsCronCommand`. Jen čte soubory, do databáze
  se nepřipojuje, sudo nepotřebuje — stačí uživatel, který čte `main.json`
  (vlastník zdrojů dat). Nečitelné řádky dostanou `?` a pod tabulkou radu
  spustit příkaz jako vlastník nebo se sudo.
- Tabulka: `ID | Name | Class | Purpose | Origin | Country | Modules | Cron | State | Server AI`;
  sloupec *Server AI* jen v režimu `development`. Výchozí třída
  `realCopy*` (v `main.json` chybí), neplatná `realCopy!`; legenda pod
  tabulkou. Účel zkrácený na 40 znaků (U7).
- Souhrn: `N data source(s): X free, Y realCopy (Z without explicit class)`.
- `--json` — stabilní kontrakt (čte ho Claude a skripty):

```json
{
  "server": { "mode": "development", "serverAi": true },
  "dataSources": [
    {
      "id": "…",
      "name": "…",
      "created": "…",
      "dataClass": "free",
      "dataClassExplicit": true,
      "dataClassInvalid": false,
      "purpose": "…",
      "origin": "dataset-seed cz/vat-payer",
      "dataClassChanged": "…",
      "country": "cz",
      "defaultLanguage": "cs",
      "modules": ["install.base"],
      "databaseName": "…",
      "state": "active",
      "cron": true,
      "serverAi": true,
      "readable": true
    }
  ]
}
```

- Exit 0 i s nečitelnými řádky a bez zdrojů dat (prázdný seznam).

## 6. Třída v dotazu `ds-reset` a `dataset-seed` (D11)

- `DsResetCommand`: za řádek `Data source: <name> (<id>)` přidat
  `Data class: free` nebo `Data class: realCopy — <účel | no purpose set>`
  (u `realCopy` jako `<comment>`). U `realCopy` dotaz
  `This data source is a REAL COPY (purpose: …). Drop N table(s) and recreate? [y/N]`.
  Řádek se vypíše i s `--yes` a `--dry-run`.
- `DatasetSeedCommand`: stejný řádek před dotazem; u `realCopy` dotaz
  s resetem uvede `… is a REAL COPY (purpose: …) …`. S `--yes` se řádek
  vypíše taky (a delegovaný `ds-reset` ho zopakuje — nevadí).
- Chování `--yes` se nemění, žádné nové odmítnutí.

## 7. Pruh „Kopie reálných dat“ (D12)

- `GET /_app/info` dostane `realCopy: bool` — `true` jen v režimu serveru
  `development` **a** u třídy `realCopy` (i výchozí). Na produkci vždy
  `false`; hodnota třídy se ven neposílá (U5).
- Režim serveru v `dispatchApp` z `ServerConfig`
  (`$serverConfig?->getMode() === 'development'`; bez `server.json` pruh
  ne). **Ne** `ResolvedDataSource::isDevMode()` — to je adresování přes IP,
  ne režim serveru.
- `AppController::info($dsState, $cronEnabled, $realCopy)`.
- `appInfoStore.realCopy`; `RealCopyBanner.svelte` ve stylu
  `CronOffBanner` (`role="status"`, `data-testid="real-copy-banner"`);
  v `ContentArea` ve společném sticky kontejneru, pořadí jen ke čtení →
  kopie reálných dat → vypnutý cron.
- i18n `dsState.realCopy.banner` — cs
  `Kopie reálných dat — i na vývojovém serveru jde o skutečné údaje, pracujte s nimi opatrně.`,
  en
  `Copy of real data — even on a development server these are real records, handle them with care.`
- Sloučení pruhů řeší #128 — tady nic nepředělávat.

## 8. Vývojářský dashboard

- `DataSourcesPage.svelte`: sloupce **Třída** (`volný` / `reálná kopie`;
  výchozí šedě s `title` „v main.json chybí — platí reálná kopie“; v `title`
  i původ a čas změny; značka `AI`, když zdroj používá serverové připojení)
  a **Účel** (šedě, zkrácený, celý v `title`).
- `DsCreatePage.svelte`: volitelné pole **Účel**. Endpoint
  `/_dev/api/ds-create` ho ověří přes `normalizePurpose()` (chyba =
  `VALIDATION` 400) a `--purpose` předá jen neprázdný. Zdroj založený
  z dashboardu je ve vývoji `free`.
- F6a tabulku převedl do Svelte — sloupce přidat, nic nepřepisovat.

## 9. Dokumentace

- `docs/cli.md` — `ds-create` (`--real-copy`, `--purpose`, zastaralý
  `--no-server-ai`), nový `ds-list`, `dev-ai-key` (kdo používá klíč),
  nový `shpd-ds ds-class` (u `ds-state`), `ds-reset` a `dataset-seed`
  (třída v dotazu, `dataset-seed` nastaví `free`); workflow 2 a 7 (cíl
  importu: `ds-create --real-copy --no-cron`).
- `docs/ai-workflow.md` §3 (reálná data = zdroje s třídou `realCopy`,
  přehled `shpd-server ds-list --json`; pravidlo o `main.json` a `secrets/`
  zůstává do tasku B), §7 (tabulka režimů → třída v `main.json`, výchozí
  `realCopy`, na `free` přepíná jen člověk v terminálu, tabulka zdrojů
  v `CLAUDE.local.md` odpadá), §8 (text instrukcí projektu).
- `CLAUDE.local.example.md` — tabulku zdrojů dat nahradit odkazem na
  `shpd-server ds-list`; read-only uživatel databáze zůstává.
- `CLAUDE.md` — řádky `shpd-server` (`ds-create`, `ds-list`) a `shpd-ds`
  (`ds-class`), zmínky o příznaku `ai.useServerConnection`.
- `docs/dev/dev-daily.md` — přehled zdrojů dat `ds-list`, třída dat;
  oddíl 4 (vypnutý cron už neodpovídá „režimu v `CLAUDE.local.md`“).
- `docs/ai.md`, `docs/test-corpus.md`, `docs/datasets.md` — serverové
  připojení podle třídy, zánik `provision.serverAi`.
- `docs/ds-state.md` — jedna věta: třída dat není stav zdroje, žije
  v `main.json` (`ds-class`).
- `docs/roadmap.md` M5 — `ds-list` hotový (#126), `ds-delete` zbývá.
- `tasks/README.md` — řádek v oblasti Server, CLI a provoz.

## 10. Testy

PHPUnit jen s úzkým `--filter` (CLAUDE.md).

- `DataSourceConfigTest` — výchozí `realCopy`; `free`; neplatné hodnoty
  (`'Free'`, `true`, `null`, číslo) = `realCopy` a
  `hasExplicitDataClass() === false`; účel (trim, prázdný = `null`);
  odebrané `usesServerAiConnection`.
- `MainConfigPatcherTest` — `withDataClass` (klíče, odebrání
  `ai.useServerConnection`, ostatní klíče `ai` zůstávají), `withPurpose`,
  `normalizePurpose` (dlouhý, víceřádkový, řídicí znaky).
- `DsCreateCommandTest` — matice režim × `--real-copy` (oddíl 2),
  `--purpose`, neplatný účel bez založení databáze, `--no-server-ai` jako
  alias s upozorněním, žádný klíč `ai` v novém `main.json`, souhrn.
- `DsClassCommandTest` (nový) — show (výchozí, neplatná, zaniklý příznak);
  `set realCopy`; `set free` neinteraktivně → exit 1 a soubor beze změny;
  interaktivně ano / ne; stejná třída s účelem; chybějící `main.json`.
- `DatasetSeedCommandTest` — `free` až po úspěšném resetu; selhaný reset
  třídu nemění; `--no-reset` nemění; účel z `title` jen bez účelu; řádek
  třídy a dotaz u `realCopy`.
- `DatasetManifestTest` — `provision.serverAi` je neznámý klíč.
- `AiBackendResolverTest` — `isFree()` místo `usesServerAiConnection()`;
  `AiFunctionResolverTest`, `DevAiKeyCommandTest` — texty.
- `DsUpgradeCommandTest` — varování u zaniklého příznaku (vývoj ano,
  produkce ne, s třídou ne).
- `DataSourceInventoryTest` (nový) — allowlist: `main.json`
  s `database_password` a `auth.providers[].clientSecret` → žádný z těch
  klíčů ani hodnot v `DataSourceInfo`; nečitelný `main.json`; poškozený
  `state.json`; řazení.
- `DsListCommandTest` (nový) — tabulka, `--json` (tvar, žádné tajné klíče
  ani hodnoty), sloupec *Server AI* jen ve vývoji.
- `DevDashboardControllerTest` — nové klíče seznamu, zachované staré;
  `--purpose` v příkazu `ds-create` a jeho validace.
- `DsResetCommandTest` — řádek třídy, dotaz u `realCopy`, `--yes` beze
  změny.
- `AppControllerTest` — `realCopy` v `info`.
- `DsCronCommandTest`, `HostingSyncRunnerTest` — beze změny chování.
- Frontend: `npm test`, pokud vznikne čistá funkce (popisek třídy); jinak
  ruční kontrola.

## Commity

1. **Třída v `main.json`** — `DataClass`, gettery `DataSourceConfig`,
   `withDataClass` / `withPurpose` / `normalizePurpose`, testy (D6).
2. **Serverové AI podle třídy** — `AiBackendResolver`, odebrané
   `usesServerAiConnection`, texty, varování v `ds-upgrade`, testy (D9).
3. **Zápis třídy** — `ds-create` (`--real-copy`, `--purpose`, alias
   `--no-server-ai`), `dataset-seed` (třída po resetu, zánik
   `provision.serverAi` a `withServerAi`), `ds-class`, testy (D7, D8).
4. **Inventář a `ds-list`** — `DataSourceInventory`, `DsListCommand`,
   převod čtenářů `main.json`, testy (D10).
5. **Třída v dotazu a pruh** — `ds-reset`, `dataset-seed`, `/_app/info`,
   `RealCopyBanner`, i18n, testy (D11, D12).
6. **Vývojářský dashboard** — sloupce Třída a Účel, pole Účel (D10).
7. **Dokumentace** (oddíl 9) a hlavička tasku.

Frontend podle `CLAUDE.md` (`cd frontend && npm run build`).

## Nasazení a ověření

**Vývojářský stroj** (člověk, po `scripts/dev-update.sh`):

1. `shpd-server ds-list` — stávající zdroje jsou `realCopy*`.
2. Vlastník označí volné zdroje v terminálu (ne přes Clauda):
   `cd <adresář zdroje> && shpd-ds ds-class set free --purpose="…"`.
   Do té doby nemají seedované sady serverové AI připojení (evaluace AI).
3. Tabulku zdrojů dat v `CLAUDE.local.md` nahradit odkazem na `ds-list`;
   upravit instrukce Projektu v claude.ai podle `docs/ai-workflow.md` §8.
4. Kontroly:
   - `ds-class set free` přes most nebo v Claude Code → exit 1,
     `main.json` beze změny,
   - `dataset-seed` na volném testovacím zdroji → `free`, původ
     `dataset-seed …`,
   - dotaz `ds-reset` na zdroji `realCopy` (odpovědět ne) uvádí třídu,
   - pruh v aplikaci na zdroji `realCopy`, na `free` ne,
   - `ds-list --json` neobsahuje klíče `database_password` ani
     `clientSecret`,
   - `ds-upgrade` zdroje se zaniklým příznakem bez třídy vypíše varování.

**Testovací server:** `ds-upgrade` po nasazení; všechny zdroje zůstanou
`realCopy`, pruh se nezobrazuje (produkce), nic dalšího.

## Rozhodnutí k designu (potvrzená)

D6–D12 jsou v #126 (komentář „Rozhodnutí (2026-10-16)“). Upřesnění
U1–U8 potvrzena 2026-10-16:

- ✓ **U1** Vedle `dataClass`, `purpose` a `origin` (D6) i `dataClassChanged`
  — kdy se třída naposledy nastavila.
- ✓ **U2** `dataset-seed` s resetem vyplní účel z `title` manifestu, jen když
  zdroj dat účel nemá.
- ✓ **U3** `ds-create --no-server-ai` zůstane jedno vydání jako zastaralý
  alias `--real-copy` (staré návody a skripty importu), pak zmizí.
- ✓ **U4** `dataset-seed` s resetem přepne `realCopy` → `free` bez
  interaktivního potvrzení — reset reálná data odstraní dřív a třída se
  mění až po úspěšném resetu. `ds-reset` sám třídu nemění (reimport).
- ✓ **U5** `/_app/info` dostane jen `realCopy: bool` (true jen ve vývoji),
  ne hodnotu třídy.
- ✓ **U6** Neplatná hodnota `dataClass` = `realCopy`; zdroj dat se kvůli ní
  nezavře, `ds-class` a `ds-list` ji označí.
- ✓ **U7** `ds-list` řadí podle ID (jako `ds-cron`), účel v tabulce zkracuje;
  plné hodnoty v `--json`.
- ✓ **U8** Zaniklý `ai.useServerConnection`: `ds-upgrade` ve vývoji varuje
  u zdroje bez explicitní třídy, dokud ho vlastník neoznačí; klíč odebere
  první zápis třídy.
