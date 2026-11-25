# Moduly nesou své CLI příkazy a úlohy cronu (#172 D1–D6)

**Stav:** hotovo — 2026-11-25 (#172 D1–D6); zbývá nasazení na alfu

> Task pro Claude Code (5 commitů). Design: issue #172. Navazuje na #170
> (doplňky) a #115 D15 (dispatcher respektuje aktivní moduly). První
> odběratel je doplněk Evidence tržeb (#171), který potřebuje vlastní
> příkaz a úlohu cronu. Konfigurace serveru z modulů (#172 D7) je
> samostatný task.

## Kontext

- `shpd-ds` registruje asi 70 příkazů natvrdo v `src/Cli/DsApplicationFactory.php`.
  Většina patří jednomu modulu, a tak je `shpd-ds` nabízí i zdroji dat,
  který modul nemá — např. `vat-periods-ensure` ve zdroji dat hostingu
  (`install.hosting` má jen `core.system`, `core.alerts`, `hosting.core`).
- `shpd-server` registruje příkazy v `src/Cli/ServerApplicationFactory.php`;
  modulu patří jen `einvoice-render` (`core.exchange`).
- Cron: `CronCommand::SLOT_JOBS` (slot → úlohy), `JOB_ALLOWED_STATES`
  (úloha → stavy zdroje dat) a `JOB_MODULES` (úloha → modul, #115 D15).
  Registr modulů je zapsaný v jádře; komentář v kódu plánuje přesun do
  `module.jsonc`.
- Vzor, který už princip splňuje: `alertChecks` v `module.jsonc`
  a `AlertCheckLoader` (bere jen moduly aktivní ve zdroji dat).

## Rozhodnutí (#172)

- **D1** — Co patří jen modulu, žije v modulu: deklarace v `module.jsonc`,
  třídy v `modules/…/src/`. Zdroj dat bez modulu to nevidí. Jádro nese
  mechanismy a to, co je společné všem zdrojům dat.
- **D2** — `module.jsonc` → `commands` (příkazy `shpd-ds`) a
  `serverCommands` (příkazy `shpd-server`). `shpd-ds` registruje příkazy
  modulů aktivních ve zdroji dat v aktuálním adresáři, mimo zdroj dat jen
  jádro; moduly nejde určit → příkazy všech modulů a varování.
  `shpd-server` registruje `serverCommands` všech modulů ve stromu. Jména
  příkazů jsou unikátní napříč jádrem a moduly.
- **D3** — Příkaz patří nejnižšímu modulu, který (tranzitivně) závisí na
  všech modulech, jejichž kód nebo tabulky příkaz potřebuje; když takový
  není, zůstává v jádře. V jádře zůstávají i nástroje přes víc modulů
  a agenti serveru (viz tabulka v kroku 2).
- **D4** — Příkaz neaktivního modulu skončí chybou, která modul jmenuje.
- **D5** — Nápověda: jádro ručně psané jako dnes, příkazy modulů
  generované po modulech z popisu příkazu; drift test hlídá obojí.
- **D6** — `module.jsonc` → `cronJobs` (příkaz téhož modulu + volby, slot,
  pořadí, stavy zdroje dat). Dispatcher je skládá z aktivních modulů,
  konstanty `SLOT_JOBS`, `JOB_ALLOWED_STATES`, `JOB_MODULES` zmizí.
  Serverové úlohy (`SERVER_SLOT_JOBS`: `hosting-sync`, `ds-state-check`)
  zůstávají v jádře.

## Před implementací přečti

- `docs/modules.md` — kap. 5 (pole `module.jsonc`), kap. 9 (Doplňky,
  odstavec „Jádro o doplňku neví“)
- `docs/cli.md` — úvod, `cron`, část `shpd-ds`
- `docs/ds-state.md` §8 (přepínač periodických úloh, aktivní moduly D15)
- `src/Cli/DsApplicationFactory.php`, `src/Cli/ServerApplicationFactory.php`,
  `bin/shpd-ds`, `bin/shpd-server` (`ModuleClassLoader` se registruje před
  factory)
- `src/Command/DataSource/HelpCommand.php`, `src/Command/Server/HelpCommand.php`
- `src/Command/Server/CronCommand.php`, `src/Core/Server/DataSourceModules.php`,
  `src/Core/Server/CronProvisioner.php` (`SLOTS`)
- `src/Core/Module/ModuleDefinition.php` (`fromArray`, vzor validace
  `kind` a `parseSections`), `ModuleLoader`, `ModuleKindRules` a jeho test
  nad celým stromem
- `src/Api/AlertCheckLoader.php` — vzor „jen aktivní moduly“
- `tests/Unit/Cli/HelpDriftTest.php`, `tests/Unit/Cli/CommandHelpTest.php`,
  `tests/Unit/Command/Server/CronCommandTest.php`

## 1. Pole `commands`, `serverCommands` a `cronJobs` v `module.jsonc`

```jsonc
"commands": [
    "Shipard\\Module\\Economy\\Vat\\Command\\VatPeriodsEnsureCommand"
],
"serverCommands": [],
"cronJobs": [
    {"command": "vat-periods-ensure", "slot": "daily"},
    // volitelně: "order": 500 (výchozí), "states": ["active"] (výchozí)
]
```

- `commands` / `serverCommands` — seznam FQCN tříd odvozených od
  `Symfony\Component\Console\Command\Command`. Třída leží v namespace
  modulu (`Shipard\Module\<Skupina>\<Modul>\Command\…`, soubor
  `modules/<skupina>/<modul>/src/Command/`).
- `cronJobs[].command` — jméno příkazu, případně s volbami oddělenými
  mezerou (`mail-preprocess --sweep`). První slovo musí být jméno příkazu
  z `commands` **téhož** modulu.
- `cronJobs[].slot` — jeden z `CronProvisioner::SLOTS`.
- `cronJobs[].order` — celé číslo, výchozí 500. Pořadí ve slotu: `order`
  vzestupně, pak id modulu, pak pořadí deklarace.
- `cronJobs[].states` — lifecycle stavy zdroje dat (`DataSourceState`),
  ve kterých úloha běží; výchozí `["active"]` (stejný fail-closed výchozí
  stav jako dnešní `JOB_ALLOWED_STATES`).

**Validace při načtení modulu** (`ModuleDefinition::fromArray`, výjimka
jako u `kind`): typy polí, neznámý slot, neznámý stav, `order` není celé
číslo, prázdný `command`. Načtení tříd se v loaderu nedělá.

**Test nad celým stromem `modules/`** (vzor test `ModuleKindRules`):

- každá třída z `commands` / `serverCommands` existuje, je `Command`
  a leží v namespace svého modulu;
- jména příkazů jsou unikátní napříč jádrem (obě factory) a všemi moduly;
- první slovo každého `cronJobs[].command` je příkaz z `commands` téhož
  modulu; tentýž řetězec příkazu není ve slotu dvakrát.

Commit 1 (`feat(modules): pole commands, serverCommands a cronJobs v module.jsonc (#172 D2, D6)`)
přidá parser, validaci, test stromu a dokumentaci polí v `docs/modules.md`
kap. 5 (tabulka polí + podkapitola). Ještě bez registrace a bez přesunů.

## 2. Registrace příkazů a přestěhování

### Registrace

- `DsApplicationFactory::create()` dostane volitelný parametr se seznamem
  aktivních modulů (pro testy). Bez něj:
  - aktuální adresář má `config/main.json` → aktivní moduly přes
    `DataSourceModules::activeIds()` (definice modulů jednou; když chybí
    veřejný přístup k definicím, přidej ho do `DataSourceModules`, ne
    druhé načítání);
  - `activeIds()` vrátí `null` → příkazy všech modulů a jeden řádek
    varování na stderr (`modules of the data source cannot be determined —
    offering commands of all modules; see shipard.log`);
  - bez `config/main.json` → jen příkazy jádra (dnešní `help` tam stejně
    končí chybou „Not a Shipard data source directory“).
- **D4:** jméno příkazu, který patří modulu neaktivnímu ve zdroji dat,
  skončí chybou `Command "vat-periods-ensure" belongs to module
  economy.vat, which is not active in this data source.` s exit 1. Mapu
  jméno → modul postav z definic všech modulů (instancování příkazů je
  levné, `configure()` jen nastavuje jméno a volby); obecné „command not
  found“ zůstane pro neznámá jména.
- `ServerApplicationFactory::create()` registruje `serverCommands` všech
  modulů ve stromu (resolver jako `bin/shpd-server`, bez čitelného
  `server.json` jen vestavěný `modules/`).
- Změř dobu `shpd-ds version` před a po — načtení definic všech modulů
  nemá start citelně zpomalit (orientačně do 100 ms navíc). Výsledek do
  implementačních poznámek.

### Přestěhování (D3)

Rozhoduje pravidlo D3, tabulka je očekávaný výsledek. Kde ti z importů
a tabulek vyjde jiný modul, řiď se pravidlem a rozdíl zapiš do
implementačních poznámek; když pravidlo vede k nesmyslu, zastav se a
zeptej se.

| Modul | Příkazy |
|-------|---------|
| `core.mail` | `mail-router-bootstrap`, `mail-router-setup`, `mail-idempotency-prune`, `mail-outbox-run`, `mail-outbox-retry`, `mail-send-test`, `mail-analysis-reap`, `mail-preprocess`, `mail-analyze`, `mail-target-backfill`, `ai-profile-reload`, `ai-eval`, `ai-eval-compare`, `print-send` (odesílání žije v `core.mail`, `RecordSendService`) |
| `core.ai` | `ai-backend-set-key`, `ai-test` |
| `core.alerts` | `alerts-run`, `alerts-prune` |
| `core.exchange` | `einvoice-render` (`serverCommands`); `booking-history` podle D3 — kód je v `core.exchange`, potřebuje ale i `economy.items` |
| `base.persons` | `values-normalize` |
| `base.registry` | `registry-extract-texts` |
| `economy.accounting` | `doc-reaccount` |
| `economy.accbal` | `accbal-match`, `accbal-regenerate` |
| `economy.bank` | `bank-import-statement` |
| `economy.vat` | `vat-periods-ensure`, `vat-filing-compose`, `vat-filing-files`, `vat-filing-xml-diff`, `vat-filing-account`, `vat-filing-import` |
| `economy.assets` | `assets-import-verify` |
| `economy.workOrders` | `work-orders-invoice-run` |
| `economy.exchangeRates` | `exchange-rates-import`, `exchange-rate`, `exchange-rates-download` |
| `hosting.core` | `hosting-oidc-init`, `hosting-oidc-client`, `hosting-server-key`, `hosting-router-key`, `hosting-ai-gw-init`, `hosting-ai-token` — ověř, že všechny běží ve zdroji dat hostingu |
| **jádro** | `version`, `help`, `ds-upgrade`, `ds-reset`, `ds-setting`, `ds-state`, `ds-class`, `ds-module`, `user-create`, `user-set-admin`, `auth-emergency-login`, `api-key-*`, `ds-secret`, `ds-secrets-*`, `dataset-*`, `video-run`, `report-run`, `report-diff`, `print-run`, `hosting-stats` (běží v zákaznickém zdroji dat pro `hosting-sync`); celé `shpd-server` kromě `einvoice-render` |

- Třídy se přestěhují do `modules/<skupina>/<modul>/src/Command/`
  s namespace modulu; jména příkazů, volby, exit kódy a výstupy se
  **nemění** (volá je cron, `hosting-sync`, dev dashboard, skripty
  a návody).
- Testy přestěhovaných příkazů jdou tam, kde žijí testy tříd modulu
  (`tests/Unit/Module/<Skupina>/<Modul>/…`), se stejným obsahem.
- Projdi přímé odkazy na třídy příkazů mimo factory (`grep -rn
  "Command\\\\DataSource\\\\" --include=*.php src modules tests scripts`)
  a komentáře, které na ně nebo na `SLOT_JOBS` odkazují.

Commit 2 (`feat(cli): příkazy z module.jsonc, jen aktivní moduly, chyba pro neaktivní modul (#172 D2, D4)`) —
registrace a D4, ještě bez přesunů (factory zatím registruje i dosavadní
seznam; test D4 nad testovacím modulem / fixturou).
Commit 3 (`refactor(cli): příkazy modulů v modulech (#172 D1, D3)`) — přesuny
podle tabulky, deklarace `commands` v `module.jsonc`, odebrání z factory.

## 3. Úlohy cronu z modulů (D6)

- Registr úloh (např. `Shipard\Core\Server\CronJobRegistry`) složený
  z definic modulů: slot → seřazené úlohy `{command, module, order,
  states}`. Definice načte jednou za běh (sdílej je s `DataSourceModules`).
- `CronCommand`:
  - platné sloty z `CronProvisioner::SLOTS` (i text u `--slot`);
  - per zdroj dat: přepínač → stav (`states` úlohy) → aktivní moduly —
    pořadí kontrol, počítadla v heartbeatu a logování beze změny;
  - moduly zdroje dat nejde určit (`null`) → všechny úlohy slotu jako
    dnes (#115 D15, fail-loud);
  - `--ds` vypisuje vyřazené úlohy jako dnes (`skipped (module <id> not
    active)`);
  - `SLOT_JOBS`, `JOB_ALLOWED_STATES`, `JOB_MODULES` zmizí;
    `SERVER_SLOT_JOBS` zůstává.
- Deklarace v `module.jsonc` — přesně dnešní obsah konstant:

| Modul | Úloha | Slot | Stavy | Pořadí |
|-------|-------|------|-------|--------|
| `core.mail` | `mail-outbox-run`, `mail-analysis-reap`, `mail-preprocess --sweep`, `mail-analyze --sweep` (v tomto pořadí) | `minute` | `active` | výchozí |
| `core.mail` | `mail-idempotency-prune` | `daily` | `active`, `read_only` | výchozí |
| `economy.exchangeRates` | `exchange-rates-download` | `five-minutes` | `active` | výchozí |
| `core.alerts` | `alerts-run` | `five-minutes` | `active` | 900 — upozornění až po úlohách, které mění data |
| `core.alerts` | `alerts-prune` | `weekly` | `active`, `read_only` | výchozí |
| `economy.vat` | `vat-periods-ensure` | `daily` | `active` | výchozí |
| `economy.workOrders` | `work-orders-invoice-run` | `daily` | `active` | výchozí |

  (Konstanty stavů ověř v `DataSourceState` — tabulka používá jejich
  řetězcové hodnoty.)
- **Test shody:** registr složený z `module.jsonc` dává pro každý slot
  stejné úlohy, stejné pořadí a stejné stavy jako dnešní konstanty
  (napiš ho proti konstantám, než je smažeš, a nech ho jako test proti
  zapsané očekávané tabulce).
- Projdi ostatní čtenáře konstant (`grep -rn "SLOT_JOBS\|JOB_MODULES\|JOB_ALLOWED_STATES"`)
  — kód, testy, `docs/`.

Commit 4 (`refactor(cron): úlohy periodických úloh z module.jsonc (#172 D6)`).

## 4. Nápověda a dokumentace

- **Nápověda (D5).** `shpd-ds help`: ručně psaná část jen pro příkazy
  jádra (přestěhované z ní zmizí); pod ní generovaná část „Modules:“ —
  pro každý aktivní modul s příkazy nadpis `<id modulu> — <název modulu>`
  a řádky `<jméno>  <popis>`. Popis = `getDescription()` příkazu; text,
  který dnes nese jen ručně psaná nápověda, přenes do `setDescription()`,
  ať se informace neztratí. `shpd-server help` obdobně pro
  `serverCommands`.
- `HelpDriftTest`: jádro jako dnes + test, že pro zadaný seznam
  aktivních modulů nápověda obsahuje všechny jejich příkazy a žádné
  příkazy neaktivních modulů. `CommandHelpTest` (`--help` u příkazů)
  musí projít i pro přestěhované příkazy.
- `docs/cli.md`: úvodní odstavec (příkazy modulů jsou jen ve zdroji dat
  s aktivním modulem, chyba D4, nápověda D5); u každého přestěhovaného
  příkazu řádek „Modul: `<id>`“; sekce `cron` — odkud se berou úlohy.
- `docs/modules.md` kap. 9 Doplňky — mezi body rozšíření doplň
  `commands` a `cronJobs`.
- `docs/ds-state.md` §8 — `JOB_MODULES` nahradit `cronJobs`.
- `docs/exchange-rates.md` a další zmínky `SLOT_JOBS` / `JOB_MODULES`.
- `CLAUDE.md` — v přehledu architektury u `Command/` poznámka, že příkazy
  modulů žijí v `modules/…/src/Command/` a registrují se přes
  `module.jsonc`.
- README modulů, které mají vlastní seznam CLI, doplň o přestěhované
  příkazy.

Commit 5 (`docs: příkazy a úlohy cronu z modulů (#172 D1–D6)`) včetně
`**Stav:**` tohoto tasku a `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `module.jsonc` umí `commands`, `serverCommands`, `cronJobs`;
      vadná deklarace selže při načtení modulu; test stromu hlídá třídy,
      namespace, unikátní jména a vazbu úlohy na příkaz modulu
- [x] `shpd-ds` ve zdroji dat nabízí jen příkazy jádra a aktivních modulů;
      příkaz neaktivního modulu končí chybou s jeho id (D4); nečitelné
      moduly → příkazy všech modulů + varování
- [x] ve zdroji dat s `install.hosting` není `vat-periods-ensure` ani
      `mail-outbox-run`, jsou tam příkazy `hosting-*` a `alerts-*`;
      v běžném zdroji dat `install.base` nejsou `hosting-oidc-*` apod.
- [x] `shpd-server einvoice-render` funguje beze změny z `core.exchange`
- [x] příkazy modulů leží v `modules/…/src/Command/`, factory je neznají;
      jména, volby a výstupy beze změny
- [x] dispatcher skládá úlohy z `cronJobs`; test shody s dřívějšími
      konstantami prochází; `cron --slot=<slot> --ds=<id>` na dev serveru
      vypíše stejné úlohy jako před změnou
- [x] `shpd-ds help` a `shpd-server help` ukazují příkazy modulů po
      modulech; drift test pokrývá jádro i moduly
- [x] `shpd-ds version` se nezpomalil citelně (měření v poznámkách)
- [x] dokumentace podle kroku 4, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný
- [x] celá sada PHPUnit lokálně zelená

## Implementační poznámky

Hotovo 2026-11-25 v pěti commitech podle zadání.

**Odchylky od tabulky přesunů (D3).**

- `ai-eval` je v **`core.exchange`**, ne v `core.mail`: příkaz přímo
  importuje třídy `core.exchange` (datové sady, porovnání, ISDOC)
  a `core.mail` na `core.exchange` nezávisí. `ai-eval-compare` zůstal
  v `core.mail`. V nápovědě jsou tak oba příkazy pod různými moduly.
  (Kód `core.mail` sám `core.exchange` používá bez deklarované závislosti —
  pravidlo jsem proto posuzoval podle přímých importů a tabulek třídy
  příkazu, ne tranzitivně.)
- Ostatní řádky tabulky vyšly podle pravidla. `mail-target-backfill` je
  v `core.mail`: `docs_core_heads` čte jen pro zprávy navázané na doklad.
  Šest příkazů `hosting-*` pracuje s tabulkami `hosting_core_*` nebo klíči
  v `secrets/` zdroje dat hostingu; ověřeno na zdroji dat `gn5c-m`
  (`install.hosting`).

**Změna chování, kterou zadání nejmenuje.** `ai-eval-compare`
a `vat-filing-xml-diff` jen porovnávají dva soubory a dřív běžely
odkudkoli. Jako příkazy modulu jdou spustit jen ze zdroje dat s aktivním
modulem; mimo zdroj dat končí chybou `Command "…" belongs to module <id>;
run it from a data source directory where the module is active.`
(rozhodnuto při plánování, popsáno v `docs/cli.md`).

**Jak je to udělané.**

- Jedinečnost jmen se hlídá **v rámci binárky** (`shpd-ds`: jádro + moduly,
  `shpd-server`: jádro + `serverCommands`) — `version` a `help` mají obě
  factory, napříč binárkami se neporovnává. Pravidla jsou čistá funkce
  `ModuleCommandRules` (vzor `ModuleKindRules`), počítají i aliasy.
- D4 je přepis `find()` v `Shipard\Cli\ShipardApplication`: **přesné** jméno
  příkazu modulu, které v aplikaci registrované není, končí
  `CommandNotFoundException` s id modulu (exit 1) dřív, než ho Symfony zkusí
  doplnit na jiný příkaz. Mapa jméno → modul (`ModuleCommands::dsOwner`) se
  staví až při prvním dotazu; mimo zdroj dat se strom modulů nenačte vůbec,
  dokud někdo nezadá příkaz, který jádro nezná.
- Varování „modules of the data source cannot be determined“ nejde z factory
  přes `fwrite`, ale z aplikace na chybový výstup před během příkazu — jednou,
  a jde testovat.
- `bin/shpd-ds` a `bin/shpd-server` předávají factory `DataSourceModules`
  nad resolverem, který už mají (druhý sken kořenů modulů odpadl).
- Dispatcher čte definice modulů **vždy jednou na začátku běhu** (potřebuje
  je pro úlohy slotu); dřív až pro první zdroj dat, kterému po stavu zbyla
  úloha. Nejdou-li načíst, končí infra chybou (exit 1) — zadání tenhle
  případ neřeší, tichý prázdný slot by schoval rozbitý strom modulů.
- Test shody (`CronJobRegistryTest`) vznikl proti konstantám `SLOT_JOBS` /
  `JOB_MODULES` / `JOB_ALLOWED_STATES`, prošel, a teprve pak konstanty
  zanikly; zůstal proti zapsané tabulce.
- Nápověda: generovanou část píše `ModuleCommandHelp`. Do `setDescription()`
  jsem přenesl text ruční nápovědy u 18 příkazů, kde nesl víc (volby, exit
  kódy); u čtyř je popis sloučený z obou. Ruční sekce Mail, Alerts, Registry
  a AI zanikly, „Economy“ je „Reports and prints“ (zbyly `report-run`,
  `report-diff`, `print-run`). Popis `einvoice-render` nese i syntaxi a exit
  kódy, které měla sekce Options nápovědy `shpd-server`.
- Fixtury pro testy: `tests/Fixtures/modules/test/commands` a `commandsTwo`
  (třídy v namespace modulu, načítá je `ModuleClassLoader` nad druhým
  kořenem).

**Měření.** `shpd-ds version` ve zdroji dat `4l3j-z`: na výchozím commitu
medián 0,27 s (10 běhů), po změně 0,29 s (15 běhů) — asi 20 ms navíc,
pod hranicí 100 ms ze zadání. Z toho načtení definic všech 40 modulů asi
15 ms. Mimo zdroj dat se definice nenačítají (0,06 s).

**Ověřeno na dev serveru.** Zdroj dat s `install.hosting`: `shpd-ds list`
má jen `alerts-*` a `hosting-*` z příkazů modulů, `vat-periods-ensure`
a `mail-outbox-run` končí chybou D4; běžný zdroj dat nemá `hosting-oidc-*`,
`hosting-stats` (jádro) má. `cron --slot=<slot> --ds=<id>` pro všech pět
slotů na obou zdrojích dává stejný výpis jako před commitem 4 (20 řádků
úloh, včetně `skipped (module … not active)`).

**Integrační sada.** Celá nad `blkm-i`: 626 testů bez selhání, 21
přeskočených (Gotenberg, PDF tisky, testy závislé na stavu dat — žádný se
netýká příkazů, cronu ani modulů).

**Neověřeno.** Strom s dalším kořenem modulů (`extraModulesPath`) ručně —
pokrývají ho jen jednotkové testy s fixturovým kořenem.

**Dokumentace.** Devět přesunutých příkazů nemá v `docs/cli.md` vlastní
kapitolu (neměly ji ani dřív) — je u nich tabulka příkaz → modul.
