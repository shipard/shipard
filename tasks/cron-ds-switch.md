# Periodické úlohy — přepínač per zdroj dat a jeho ovládání (#115 fáze 1)

**Stav:** hotovo — implementováno 2026-10-12 (6 commitů podle PRD), ruční ověření na volném zdroji dat (`vlm9-y`) vč. pruhu v headless prohlížeči; nasazení na testovacím serveru ověřeno 2026-10-28 (heartbeat slotů nese `cronDisabledDataSources`, sloty bez selhání)

> PRD pro Claude Code (6 commitů). Design: issue #115, komentáře
> „Přehodnocení směru; zamčeno D1–D12“ a „D9 → D13, D14“. Navazuje na
> stavy zdroje dat z #56 (`tasks/ds-state-phase1.md`,
> `tasks/ds-state-phase2.md`) a na dispatcher (`tasks/ops-cron-runner.md`).
> Roadmapa: vývojová infrastruktura mimo milníky (jako #96, #108).

## Kontext

Dispatcher periodických úloh (`shpd-server cron --slot=…`) spouští úlohy
pro každý zdroj dat a řídí se jen stavem z `config/state.json` (#56).
Na vývojářských strojích cron dnes neběží, takže vše, co na něm stojí
(fronta odchozí pošty, upozornění, období DPH, periodická fakturace),
tam tiše nefunguje. Fáze 2 cron na vývojářských strojích zapne.

Předtím je potřeba umět cron pro jednotlivý zdroj dat vypnout. Zdroj
dat, který je kopií reálných dat (import ze starého Shipardu, později
obnova ze zálohy), má zůstat v původním stavu — žádná úloha v něm nemá
nic vystavovat, zakládat ani mazat. Kategorie úloh se nezavádějí (D1):
cron pro zdroj dat buď běží celý, nebo vůbec.

Tento task přidává přepínač, jeho ovládání z CLI a místa, kde je vypnutý
cron vidět. Cron na vývojářských strojích sám nezapíná.

## Před implementací přečti

- Issue #115 — oba komentáře s rozhodnutími (popis issue je historický)
- `docs/ds-state.md` celé, `docs/cli.md` § `cron --slot`, `ds-state`,
  `ds-create`, `doctor`
- `src/Core/Config/DataSourceState.php`
- `src/Command/Server/CronCommand.php`, `src/Core/Server/CronProvisioner.php`
- `src/Command/DataSource/DsStateCommand.php`
- `src/Command/Server/DsCreateCommand.php`, `src/Cli/ServerApplicationFactory.php`
- `src/Core/Server/DataSourceStateScanner.php`, `DataSourceStateEntry.php`
- `src/Command/Server/DoctorCommand.php` (`checkDataSourceStates`, `checkCron`)
- `src/Api/Controller/AppController.php` (`info`), `public/index.php`
  (`dispatchApp`), `src/Api/Controller/DevDashboardController.php`
  (`listDataSources`, `renderRow`)
- `frontend/src/stores/appInfo.svelte.js`,
  `frontend/src/components/ui/ReadOnlyBanner.svelte`,
  `frontend/src/components/layout/ContentArea.svelte`
- testy: `tests/Unit/Core/Config/DataSourceStateTest.php`,
  `tests/Unit/Command/Server/CronCommandTest.php`,
  `tests/Unit/Command/DataSource/DsStateCommandTest.php`

## Scope

**Uvnitř:** klíč `cron` v `config/state.json`, filtrování v dispatcheru,
`cron --ds`, `ds-state cron`, `ds-create --no-cron`, nový příkaz
`shpd-server ds-cron`, heartbeat, `doctor`, vývojářský dashboard
(zobrazení), pruh v aplikaci, testy, dokumentace.

**Mimo:**

- instalace cronu na vývojářských strojích, první zapnutí a návody
  (D6, D10 — fáze 2, vlastní task),
- automatika při importu (D13 — zrušeno) a při obnově ze zálohy (D12 —
  až budou nástroje),
- deklarace úloh v `module.jsonc` (D2), kategorie úloh (D1),
- řízení přepínače z hostingu,
- přepínání z vývojářského dashboardu a z UI aplikace,
- zkrácené ID zdroje dat (prefix) v argumentech příkazů,
- uživatelská nápověda v `help/` — stavy zdroje dat tam zatím popsané
  nejsou (ani `read_only`); pruh říká sám, co se děje.

## 1. Stavový soubor (D1, D3, D4)

`config/state.json` dostane volitelný klíč `cron`:

```json
{
  "version": 1,
  "state": "active",
  "cron": false,
  "changedBy": "cli",
  "changed": "2026-10-12T10:00:00Z"
}
```

- Klíč chybí nebo je `true` → cron zapnutý (výchozí stav, D4). `false` →
  vypnutý. Jiný typ → fail-closed jako ostatní nevalidní hodnoty
  (`failClosed`, důvod `cron must be a boolean`).
- `VERSION` zůstává `1`. Starší kód neznámý klíč při čtení ignoruje;
  zvýšení verze by ho donutilo zdroj dat fail-closed zavřít.
- `DataSourceState`: vlastnost `cronEnabled` (výchozí `true`), getter
  `isCronEnabled(): bool`, mutátor `withCron(bool $enabled): self`.
  `toArray()` zapisuje `"cron": false` jen při vypnutí.
- **Všechny stávající mutátory i `save()` musí hodnotu přenést.**
  Konstruktor má poziční parametry a každý mutátor staví novou instanci —
  vynechaný parametr by přepínač při `maintenance --on` tiše zapnul.
- Přepínač je nezávislý na lifecycle stavu i na maintenance: `set`
  a `maintenance --on/--off` ho nemění.

## 2. Dispatcher (D1, D7, D8)

`CronCommand::execute`, smyčka přes zdroje dat:

- Po `DataSourceState::load()` a počítání poškozených souborů: když
  `!isCronEnabled()`, zdroj dat se přeskočí celý — včetně úklidových
  úloh. Bez logu per běh (minute slot by spamoval).
- Kontrola přepínače je **před** `jobsForState`. Zdroj dat se počítá jen
  do jednoho čítače: vypnutý cron → `cronDisabledDataSources`, jinak
  případně `skippedDataSources` (stav) jako dnes.
- Heartbeat dostane klíč `cronDisabledDataSources`. Stejný počet do logu
  `cron slot finished` a do souhrnného řádku na stdout.
- Serverové úlohy (`SERVER_SLOT_JOBS`) se přepínačem neřídí.
- Přepínač řídí jen dispatcher (D7). Příkazy spuštěné přímo
  (`shpd-ds work-orders-invoice-run`) ani odpojené procesy po příjmu
  zprávy ho nečtou — nic v nich neměň.

### `--ds=<id>` — jeden tik pro jeden zdroj dat (D8)

```bash
shpd-server cron --slot=daily --ds=<id>
```

- Spustí úlohy slotu jen pro jmenovaný zdroj dat. Přepínač ignoruje;
  `jobsForState` platí dál (maintenance, `suspended` → nic, s vysvětlením
  na výstupu).
- Serverové úlohy slotu nespouští.
- **Nebere zámek slotu a nezapisuje heartbeat.** Čerstvý heartbeat
  z ručního běhu by v `doctor` zakryl neběžící cron. Souběh s plánovaným
  během je stejná situace jako ruční `shpd-ds <příkaz>`, kterou D7
  povoluje vždy.
- Výstup: řádek per úloha (název, exit kód nebo timeout, trvání),
  u selhané úlohy konec jejího výstupu.
- Exit `FAILURE`: některá úloha selhala, neznámé ID, adresář bez
  `config/main.json`. (Plánovaný běh bez `--ds` vrací `SUCCESS` i při
  selhaných úlohách — beze změny.)
- ID jen celé, ve tvaru `xxxx-xxxx-xxxx-xxxx`; formát ověřit **před**
  složením cesty.
- Kontext logu `cli: cron --slot=<slot> --ds=<id>`.

## 3. `shpd-ds ds-state cron --on|--off` (D14)

Nová akce `cron` v `DsStateCommand`, používá stávající volby `--on`
a `--off`:

- bez volby nebo s oběma → chyba s nápovědou použití,
- poškozený `state.json` → odmítnout jako u `maintenance` (nejdřív
  opravit přes `set`),
- zdroj dat už v cílovém stavu → „nothing to do“, exit 0, soubor se
  nepřepisuje,
- zápis přes `withCron()` + `save()` s `changedBy: cli`.

Výpis `ds-state` (akce `show`) dostane řádek `Cron:` — `on`, nebo
zvýrazněné `off` s poznámkou, že periodické úlohy neběží.

## 4. `shpd-server ds-create --no-cron` (D14)

- Nová volba `--no-cron` (bez hodnoty). S ní příkaz hned po zápisu
  `main.json` zapíše `state.json`:
  `DataSourceState::active()->withCron(false)->save($dataSourceDir, 'ds-create')`.
  Zápis musí proběhnout **před** rekurzivním `chown` na konci příkazu.
- Bez volby se `state.json` nezakládá (dnešní chování).
- Selhání zápisu = chyba příkazu s radou
  `shpd-ds ds-state cron --off`. Volba existuje proto, aby cíl importu
  nikdy neběžel se zapnutým cronem — tiché pokračování by ji popřelo.
- Souhrn na konci příkazu: s volbou řádek `Cron: off`.

## 5. `shpd-server ds-cron` (D14)

Nový `DsCronCommand` v `src/Command/Server/`, registrace
v `ServerApplicationFactory`.

```bash
shpd-server ds-cron                       # přehled
shpd-server ds-cron --off --all           # vypnout všem
shpd-server ds-cron --off <id> [<id>…]    # vypnout vyjmenovaným
shpd-server ds-cron --on <id> [<id>…]     # zapnout vyjmenovaným
```

**Přehled** (bez voleb): tabulka `ID | Name | Cron | State`, řazená
podle ID.

- `Name` z `main.json` zdroje dat; když ho nelze přečíst, `?` (příkaz
  kvůli tomu neselže).
- `State` = efektivní stav; maintenance s důvodem; poškozený
  `state.json` jako `corrupted`.
- Pod tabulkou souhrn: počet zdrojů dat a počet s vypnutým cronem.
- Seznam bere z `DataSourceStateScanner` (jen adresáře s
  `config/main.json`).

**Přepnutí:**

- právě jedna z voleb `--on` / `--off`; `--all`, nebo aspoň jedno ID —
  ne obojí; jinak chyba s nápovědou použití,
- neznámé ID, poškozený `state.json` (nepřepisovat) a selhání zápisu
  jsou chyba **toho jednoho** zdroje dat — příkaz pokračuje dalšími
  a skončí `FAILURE`,
- zdroj dat už v cílovém stavu → `unchanged`, soubor se nepřepisuje,
- bez potvrzovacího dotazu (volá se ze skriptů),
- `changedBy: cli`,
- po přepnutí vypíše přehled.

## 6. `doctor` a vývojářský dashboard (D11)

**Scanner:** `DataSourceStateScanner::countByState` přidá `cronOff`,
`DataSourceStateEntry` metodu `isCronOff()`.

**`doctor`, sekce Data source states** (`checkDataSourceStates` dostane
režim serveru):

- souhrnný řádek doplnit o `cron off N`,
- per zdroj dat s vypnutým cronem:
  - produkční server: `⚠ <id>: cron off — periodic jobs are not running
    (shpd-ds ds-state cron --on)`,
  - jinak: `· <id>: cron off`,
- řádek se vypisuje nezávisle na řádku o stavu (zdroj dat v maintenance
  s vypnutým cronem má oba),
- varování nezvyšuje počet chyb ani nemění exit kód,
- „All data sources active ✓“ jen když zároveň žádný zdroj dat nemá
  cron vypnutý.

**Vývojářský dashboard** (`/_dev/`): `listDataSources()` přidá `cron`
(bool) a `state` (efektivní stav) z `DataSourceState::load()`; tabulka
dostane sloupec Cron (`on`, zvýrazněné `off`). Jen zobrazení.

## 7. Pruh v aplikaci (D11)

- `GET /_app/info` dostane klíč `cronEnabled` (bool).
  `AppController::info(string $dsState, bool $cronEnabled = true)`;
  hodnotu dodá `dispatchApp` z `$resolved->state->isCronEnabled()`.
  Endpoint je veřejný — jen boolean.
- `appInfoStore`: `cronDisabled` (`cronEnabled === false`).
- Nová komponenta `CronOffBanner.svelte` podle `ReadOnlyBanner.svelte`,
  render v `ContentArea`. Vidí ho všichni přihlášení uživatelé.
- Oba pruhy mohou být vidět naráz a oba jsou `position: sticky; top: 0`
  — musí se skládat pod sebe, ne překrývat (společný sticky kontejner).
- Texty (`cs.js`, `en.js`, klíč `dsState.cronOff.banner`):
  - cs: „Periodické úlohy jsou na tomto zdroji dat vypnuté — pošta ve
    frontě se neodesílá, upozornění se nevyhodnocují a automatická
    fakturace neběží.“
  - en: “Scheduled jobs are turned off for this data source — queued
    mail is not sent, alerts are not evaluated and automatic invoicing
    does not run.”

  V JS řetězcích jen ASCII uvozovky.

## 8. Dokumentace

- `docs/ds-state.md` — nová sekce „Přepínač periodických úloh“ (smysl,
  nezávislost na stavu a maintenance, co neběží, `--ds`), klíč `cron`
  ve formátu souboru, tabulka chování per subsystém, tabulka tříd,
  ovládání.
- `docs/cli.md` — `ds-state` (akce `cron`, řádek ve výpisu),
  `cron --slot` (volba `--ds`, heartbeat `cronDisabledDataSources`,
  tabulka voleb), `ds-create` (`--no-cron`), nový `ds-cron`, `doctor`.
- `docs/operations/production.md` — odstavec Cron: věta o přepínači
  a o varování v `doctor`.
- `CLAUDE.md` — řádek `docs/ds-state.md` v tabulce dokumentů a seznam
  CLI příkazů (`ds-create`, `cron --slot`, `ds-cron`, `ds-state`).
- `HelpCommand` pro `shpd-server` i `shpd-ds`; ověř, jestli doplňování
  příkazů (`CompletionInstaller`) potřebuje úpravu.
- `docs/migration-guide.md` a návody v `docs/dev/` až ve fázi 2.

## Testy

- `DataSourceStateTest` — klíč chybí = zapnuto; `false` = vypnuto;
  jiný typ = fail-closed; `toArray()` klíč při zapnutí nepíše;
  `withState`, `withMaintenance`, `withoutMaintenance`,
  `withDeleteAfter` a `save()` přepínač zachovají.
- `CronCommandTest` — zdroj dat s vypnutým cronem nedostane žádnou
  úlohu a je v `cronDisabledDataSources`, ne ve `skippedDataSources`;
  serverové úlohy běží dál; `--ds` přepínač ignoruje, stav respektuje,
  nespouští serverové úlohy, nezapisuje heartbeat, nebere zámek, vrací
  `FAILURE` při selhané úloze, neznámém ID a neplatném tvaru ID.
- `DsStateCommandTest` — `cron --on` / `--off`, no-op, chybějící
  a dvojí volba, poškozený soubor, řádek ve výpisu, maintenance
  přepínač nemění.
- `DsCreateCommandTest` — s `--no-cron` vznikne `state.json` s `cron:
  false`, bez volby soubor nevznikne.
- nový `DsCronCommandTest` — přehled, `--off --all`, `--on <id>`,
  neplatné kombinace voleb, neznámé ID a poškozený soubor nepřeruší
  ostatní a dají `FAILURE`, `unchanged`.
- `DataSourceStateScannerTest`, `DoctorCommandTest` — počty; varování na
  produkci, informační řádek jinde, exit kód beze změny.
- `AppControllerTest` — `cronEnabled` v `/_app/info`;
  `DevDashboardControllerTest` — `cron` a `state` v seznamu.
- `DsResetCommandTest` — `ds-reset` nechává `config/state.json` být
  (přepínač přežije reset; opírá se o to D13).
- Frontend: `npm run check:i18n`, `npm run build`.

## Commity

1. `feat(ds-state): přepínač periodických úloh v state.json a ds-state cron (#115)`
   — sekce 1 a 3, test `ds-reset`.
2. `feat(cron): dispatcher přeskakuje zdroje dat s vypnutým cronem, volba --ds (#115)`
   — sekce 2.
3. `feat(cli): ds-create --no-cron a shpd-server ds-cron (#115)` — sekce 4 a 5.
4. `feat(doctor): vypnutý cron v doctor a ve vývojářském dashboardu (#115)`
   — sekce 6.
5. `feat(app): pruh o vypnutých periodických úlohách (#115)` — sekce 7.
6. `docs: přepínač periodických úloh per zdroj dat (#115)` — sekce 8,
   `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

## Ověření na vývojářském stroji — pozor

- **Slot bez `--ds` ručně nespouštěj.** Proběhl by nad všemi zdroji dat
  na stroji včetně kopií reálných dat.
- **`ds-cron --off --all` / `--on --all` nespouštěj.** Zapisuje do všech
  zdrojů dat; na kopiích reálných dat je to mutace, kterou schvaluje
  člověk.
- Ruční ověření jen na zdroji dat s režimem `volný` (`CLAUDE.local.md`):
  `ds-state cron --off`, `ds-cron` (přehled je jen čtení),
  `cron --slot=five-minutes --ds=<id>`, pruh v aplikaci,
  `ds-state cron --on`.

## Hotovo když

- [x] `state.json` s `"cron": false` vypne zdroji dat všechny úlohy
      dispatcheru; chybějící klíč = zapnuto; nevalidní hodnota =
      fail-closed
- [x] `maintenance --on/--off` a `set` přepínač nemění
- [x] heartbeat nese `cronDisabledDataSources`
- [x] `cron --slot=<slot> --ds=<id>` spustí úlohy i při vypnutém cronu,
      nezapíše heartbeat a vrátí `FAILURE` při selhané úloze
- [x] `ds-state cron --on|--off` a řádek `Cron` ve výpisu
- [x] `ds-create --no-cron` založí zdroj dat s vypnutým cronem
- [x] `ds-cron` vypíše přehled a přepíná jednotlivě i hromadně
- [x] `doctor`: varování na produkčním serveru, informační řádek jinde
- [x] vývojářský dashboard ukazuje sloupec Cron
- [x] pruh v aplikaci při vypnutém cronu, souběžně s pruhem „jen pro
      čtení“ bez překryvu
- [x] ruční ověření na zdroji dat s režimem `volný` (viz výše)
- [x] dokumentace podle sekce 8, `php -l`, cílené testy, celá sada,
      `npm run build`
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`

## Rozhodnutí k designu (potvrzená)

Zamčeno v #115:

- ✓ **D1** — žádné kategorie úloh; vypnuto = dispatcher přeskočí zdroj
  dat celý, serverové úlohy se přepínačem neřídí
- ✓ **D3** — přepínač v `config/state.json`, nezávisle na stavu
  a maintenance z #56
- ✓ **D4** — výchozí stav je zapnuto, na produkčním i vývojářském serveru
- ✓ **D7** — přepínač řídí jen dispatcher
- ✓ **D8** — `cron --slot --ds` přepínač ignoruje, stav z #56 platí dál
- ✓ **D11** — vypnutý cron je vidět: pruh v aplikaci, `doctor`
  (na produkci varování), vývojářský dashboard
- ✓ **D13** — import nic nenastavuje; cíl importu řídí člověk přes CLI
- ✓ **D14** — sada příkazů: `ds-state cron`, `ds-create --no-cron`,
  `cron --ds`, `ds-cron`

Upřesnění přijatá v tomto PRD (v issue nejsou; potvrzeno 2026-10-12):

- ✓ klíč `cron` jako boolean, zapisovaný jen při vypnutí; verze souboru
  zůstává 1
- ✓ `--ds` bez zámku slotu a bez heartbeatu, s exit kódem podle výsledku
  úloh
- ✓ samostatný čítač `cronDisabledDataSources`, přepínač se vyhodnocuje
  před stavem
- ✓ `ds-cron` přepíná bez potvrzovacího dotazu
- ✓ selhání zápisu `state.json` při `ds-create --no-cron` je chyba příkazu
