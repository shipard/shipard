# Periodické úlohy — dispatcher respektuje aktivní moduly (#115 D15)

**Stav:** hotovo — 2026-10-13, 2 commity; mapa `CronCommand::JOB_MODULES`,
`Core\Server\DataSourceModules` (`activeIds()` / `isActive()`, šev
`loadDefinitions()`), filtr po stavu v obou cestách dispatcheru, `--ds`
vypisuje vyřazené úlohy; ověřeno na vývojářském stroji (heartbeat slotu
`minute` bez selhání, `--ds` na testovacím zdroji hostingu vypsal čtyři
vynechané úlohy). `HostingSyncRunner::isModuleActiveForDs()` **nepřeveden**:
čte surový JSON bez validace `DataSourceConfig`, chyby resolveru ignoruje
(částečný výsledek) a výjimku loguje do výstupu `hosting-sync` — převod by
mu změnil chování v těchto okrajích; zůstává samostatně.

> Jednorázový task pro Claude Code (2 commity). Design: issue #115 (D15).
> Navazuje na `tasks/cron-ds-switch.md` a `tasks/cron-dev-install.md`.

## Kontext

Dispatcher (`shpd-server cron --slot=…`) pouští každému zdroji dat
všechny úlohy slotu. O modulech neví — registr úloh je statický seznam
v `CronCommand`.

Zdroj dat, který nemá modul úlohy aktivní, nemá ani její tabulky. Úloha
na něm skončí chybou „Table … doesn't exist“, a to při každém běhu
slotu. První běh cronu na vývojářském stroji to ukázal na testovacím
zdroji hostingu (aktivní jen `install.hosting`, tedy bez `core.mail`):

- čtyři selhané úlohy každou minutu,
- `doctor` u slotu `minute` trvale varuje,
- `cron.log` roste asi o 7 kB za minutu — každé selhání nese výpis
  zásobníku.

Totéž čeká denní slot na zdrojích bez `economy.vat`
a `economy.workOrders`. Na produkci se to dosud neprojevilo jen proto,
že tam poštu mají všechny zdroje dat.

**D15:** dispatcher pustí úlohu jen na zdroji dat, který má aktivní modul
úlohy. Vazba úloha → modul je statická mapa v `CronCommand`. Deklarativní
registr v `module.jsonc` (D2) zůstává odložený.

## Před implementací přečti

- `src/Command/Server/CronCommand.php` celé (`SLOT_JOBS`,
  `JOB_ALLOWED_STATES`, `jobsForState`, `executeSingle`)
- `src/Core/Server/HostingSyncRunner.php` — `isModuleActiveForDs()`
  (stejné zjišťování, dnes soukromé)
- `src/Core/Module/ModuleLoader.php`, `ModuleResolver.php`,
  `ModulePathResolver.php`, `src/Core/Config/DataSourceConfig.php`
  (`getModules`)
- `docs/cli.md` § `cron --slot`, `docs/ds-state.md` §8
- `tests/Unit/Command/Server/CronCommandTest.php`

## Co je potřeba udělat

### 1. Mapa úloha → modul

Nová konstanta v `CronCommand` vedle `JOB_ALLOWED_STATES`:

| Úloha | Modul |
|---|---|
| `mail-outbox-run`, `mail-analysis-reap`, `mail-preprocess --sweep`, `mail-analyze --sweep`, `mail-idempotency-prune` | `core.mail` |
| `alerts-run`, `alerts-prune` | `core.alerts` |
| `vat-periods-ensure` | `economy.vat` |
| `work-orders-invoice-run` | `economy.workOrders` |

- Hodnota `null` = úloha bez vazby na modul, běží všude.
- **Každá úloha ze `SLOT_JOBS` musí mít v mapě záznam** (i `null`). Test
  to hlídá — nová úloha bez rozhodnutí o modulu neprojde.
- Serverové úlohy (`SERVER_SLOT_JOBS`) se netýká.

### 2. Aktivní moduly zdroje dat

Stejně jako zbytek aplikace: přímé moduly z `main.json`
(`DataSourceConfig::getModules()`) rozvinuté přes
`ModuleResolver::resolve()`.

- Definice modulů (`ModuleLoader::loadAllModules`) se načtou **jednou za
  běh dispatcheru**, ne per zdroj dat.
- Cesty k modulům jako v `HostingSyncRunner::isModuleActiveForDs()`
  (`ModulePathResolver::fromServerConfig`, při chybě vestavěná cesta).
- Vytáhni to do malé třídy v `src/Core/Server/` se švem pro testy.
  `HostingSyncRunner` na ni převeď jen tehdy, když to vyjde bez změny
  chování; jinak ho nech být a napiš to do `**Stav:**`.

### 3. Filtrování v dispatcheru

- Po `jobsForState` se vyřadí úlohy, jejichž modul zdroj dat nemá.
  Vyřazená úloha se **nespouští a nepočítá** — není to běh ani selhání.
- Pořadí kontrol: přepínač cronu → stav → moduly. Moduly se zjišťují až
  pro zdroj dat, kterému zbyla aspoň jedna úloha.
- **Nelze-li moduly určit** (nečitelný `main.json`, chyba resolveru):
  chovej se jako dnes a pusť všechny úlohy. Chyba se pak ukáže jako
  selhaná úloha; tiché vynechání všeho by ji schovalo.
- Heartbeat se nemění.

### 4. `--ds`

`executeSingle` filtruje stejně. Vyřazené úlohy vypíše:
`· <úloha>: skipped (module <id> not active)`. Na exit kód nemají vliv.

### 5. Dokumentace

- `docs/cli.md` § `cron --slot` — sloupec Modul v tabulce slotů, věta
  o vynechání, výpis `--ds`.
- `docs/ds-state.md` §8 — pořadí kontrol (přepínač, stav, moduly).
- docblock `CronCommand`.

## Mimo rozsah

- registr úloh v `module.jsonc` (D2),
- ochrana v jednotlivých příkazech (`shpd-ds mail-outbox-run` spuštěný
  ručně na zdroji bez modulu selže dál — správně),
- čítač vynechaných úloh v heartbeatu a v `doctor`.

## Testy

`CronCommandTest`:

- zdroj dat bez `core.mail`: poštovní úlohy se nespustí, `failedCount`
  je 0, `jobsRun` je nezapočítá; `alerts-run` na něm běží,
- zdroj dat s modulem: beze změny,
- moduly nelze určit: běží všechny úlohy jako dnes,
- definice modulů se načítají jednou za běh (počet volání švu),
- zdroj dat s vypnutým cronem nebo zavřený stavem se na moduly neptá,
- `--ds`: vyřazené úlohy ve výpisu, exit `SUCCESS`,
- každá úloha ze `SLOT_JOBS` má záznam v mapě modulů.

Test nové třídy: rozvinutí závislostí (přímý modul `install.*` →
`core.mail` aktivní), chybějící a poškozený `main.json`.

## Commity

1. `feat(cron): dispatcher pouští úlohu jen na zdroji dat s aktivním modulem (#115 D15)`
   — kroky 1–4 a testy.
2. `docs: periodické úlohy a aktivní moduly (#115 D15)` — krok 5,
   `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření na vývojářském stroji

Na stroji teď běží systémový cron **z tohoto checkoutu** — dispatcher
každou minutu spouští kód v pracovním stromu, i rozpracovaný.

- Po commitu 1 počkej na další tik a přečti
  `/opt/shipard/run/cron-minute.heartbeat`: `failedCount` má být 0.
- `shpd-server cron --slot=minute --ds=<id>` na testovacím zdroji
  hostingu (režim `volný` v `CLAUDE.local.md`) vypíše čtyři vynechané
  úlohy.
- Slot bez `--ds` ručně nespouštěj.

## Hotovo když

- [x] úloha se nespustí na zdroji dat bez jejího modulu a nepočítá se
      jako selhání
- [x] každá úloha ze `SLOT_JOBS` má záznam v mapě modulů (test)
- [x] neurčitelné moduly = chování jako dnes
- [x] `--ds` vypisuje vynechané úlohy
- [x] heartbeat slotu `minute` na vývojářském stroji bez selhání
- [x] dokumentace podle kroku 5, `php -l`, cílené testy, celá sada
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`

## Rozhodnutí k designu (potvrzená)

- ✓ **D15** — dispatcher respektuje aktivní moduly; vazba úloha → modul
  je statická mapa v `CronCommand`, registr v `module.jsonc` (D2) zůstává
  odložený

Upřesnění přijatá v tomto tasku (v issue nejsou; potvrzeno 2026-10-13):

- ✓ moduly se berou z `main.json` přes resolver (jako zbytek aplikace), ne
  z kompilované konfigurace
- ✓ neurčitelné moduly = pustit vše jako dnes, ne vynechat
- ✓ vynechané úlohy nemají čítač v heartbeatu
