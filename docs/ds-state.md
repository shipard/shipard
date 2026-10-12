# Stavy zdroje dat

Stavový model DS podle issue [shipard/shipard#56](https://github.com/shipard/shipard/issues/56)
(rozhodnutí D1–D10). Tento dokument popisuje **fázi 1** (stavový soubor,
503 pro zavřené DS, cron gating, CLI) a **fázi 2** (read-only vynucení na
routách, MCP read-tier, stavová obrazovka SPA, hlídání zapomenuté
maintenance — sekce 6). Fáze 3 (hosting orchestrace) je v závěru.
**Přepínač periodických úloh** per zdroj dat (issue
[shipard/shipard#115](https://github.com/shipard/shipard/issues/115), fáze 1)
žije ve stejném souboru jako třetí, nezávislá osa — sekce 8.

## 1. Model — dvě osy

**Lifecycle stav** (`state`):

| Stav | Význam |
|------|--------|
| `active` | běžný provoz |
| `read_only` | klient „jen kouká" — čtení a reporty fungují, zápisy ne: HTTP mutace **403 `DS_READ_ONLY`** (sekce 6), chat vypnutý, MCP jen čtecí nástroje, pošta se nepřijímá (503), cron jen úklidové joby |
| `suspended` | zavřeno (neplatící klient, ruční zásah) — HTTP 503, cron nic |
| `pending_deletion` | čeká na fyzické smazání po `deleteAfter` — HTTP 503, cron nic; mazací job je fáze 3 |

**Maintenance overlay** (`maintenance {reason, since}`) — dočasné zavření
nezávislé na lifecycle stavu: `reason` ∈ `import` | `restore` | `migration`
| `manual`. Aktivní maintenance **má přednost**: DS se navenek chová jako
`suspended`, ať je lifecycle stav jakýkoli. Po `--off` se vrátí přesně do
uloženého lifecycle stavu — DS v `read_only` se po přesunu na jiný server
vrátí do `read_only`, ne do `active` (D1).

**Efektivní stav** = `maintenance aktivní ? suspended : state`. Všechny
subsystémy rozhodují podle efektivního stavu.

## 2. Chování per subsystém

| Efektivní stav | HTTP | `/_mail/incoming` | cron per-DS joby | cron server-level joby |
|---|---|---|---|---|
| `active` | běží | běží | všechny | běží |
| `read_only` | čtení běží, mutace **403** `DS_READ_ONLY`, chat celý 403 (sekce 6) | **503** `DS_UNAVAILABLE` + `Retry-After: 300` (D4 — router frontuje) | jen `mail-idempotency-prune`, `alerts-prune` | běží |
| `suspended` | **503** `DS_UNAVAILABLE` + `Retry-After: 300` | 503 | žádné | běží |
| `pending_deletion` | **503** `DS_UNAVAILABLE` + `Retry-After: 300` | 503 | žádné | běží |

Proč 503 a ne 404 (D3): mail-router a monitoring by 404 vyhodnotily jako
permanentní chybu a poštu zahodily; 503 znamená „zkus později" — router
zprávy frontuje a po otevření DS doručí. Zavřený DS **není chyba** —
odmítnutý request se loguje na úrovni `info`, ne `error`.

Registr cron jobů a jejich povolených stavů je
`CronCommand::JOB_ALLOWED_STATES` — job bez záznamu běží **jen** v
`active` (fail-closed). Přeskočený DS se neloguje per běh (minute slot by
spamoval); počet jde do heartbeat souboru slotu (`skippedDataSources`,
`corruptedStateFiles`), viz [cli.md](cli.md) → `cron --slot`.

Sloupec „cron per-DS joby" navíc podléhá **přepínači periodických úloh**
(`cron: false`, sekce 8): vypnutý přepínač vyřadí DS z per-DS sloupce
celý bez ohledu na stav — ani úklidové joby; server-level joby běží dál.

## 3. Zdroj pravdy — `config/state.json`

Soubor v adresáři DS, **ne** DB (D2): musí fungovat, když DB neexistuje
nebo je uprostřed restore. Čte se v `DataSourceResolver` **před**
připojením k databázi a v `CronCommand` (jen tento soubor, `main.json`
se nedotýká).

Formát v1:

```json
{
  "version": 1,
  "state": "active",
  "maintenance": { "reason": "import", "since": "2026-09-01T10:00:00Z" },
  "deleteAfter": "2026-10-01T00:00:00Z",
  "cron": false,
  "changedBy": "cli",
  "changed": "2026-09-01T10:00:00Z"
}
```

`maintenance`, `deleteAfter` a `cron` volitelné. `deleteAfter` má smysl jen u
`pending_deletion` — přechod do jiného stavu ho zahodí. `cron` je přepínač
periodických úloh (sekce 8): chybí nebo `true` = zapnuto, `false` =
vypnuto; zapisuje se **jen při vypnutí**, verze souboru zůstává 1 (starší
kód neznámý klíč ignoruje, zvýšení verze by ho donutilo DS fail-closed
zavřít). Časy UTC ISO 8601. `changedBy` je `cli` (`ds-state`, `ds-cron`)
nebo `ds-create` (`--no-cron`); hosting-sync (fáze 3) zapíše vlastní
identifikátor.

### Sémantika čtení (`DataSourceState::load`)

| Situace | Výsledek |
|---|---|
| soubor neexistuje | `active` bez overlay — zpětná kompatibilita, existující DS nic nemění |
| validní v1 | přečtený stav |
| nečitelný soubor, nevalidní JSON, neznámá `version`, neznámý `state`, neznámý `reason`, `cron` jiného typu než boolean | **fail-closed**: efektivní `suspended` + `error` do logu |

Fail-closed je záměr: poškozený stavový soubor nesmí tiše otevřít DS,
který měl být zavřený. `ds-state` (show) poškozený soubor ohlásí a poradí
opravu (`set <state>` soubor přepíše).

### Zápis (`DataSourceState::save`)

Atomicky tmp + rename (vzor `DomainsFile`), každý krok kontrolovaný,
selhání = výjimka — soubor čte resolver při každém requestu a cron každou
minutu, roztržený zápis by DS fail-closed zavřel. Nastavuje `changed`
a `changedBy`.

**Vlastník souboru.** Zápis běží i pod rootem (`sudo shpd-ds ds-state …`,
`shpd-server ds-cron`, `cron-install` ve fázi 2 #115). Adresář `config/`
má `0750` bez setgid, takže soubor založený rootem by patřil `root:root`
a PHP-FPM ani dispatcher periodických úloh (shipard-user) by ho
nepřečetli — `load()` by vrátil fail-closed `suspended`, HTTP 503 a cron
by DS přeskakoval. `save()` proto před `rename` srovná vlastníka
a skupinu dočasného souboru podle `config/main.json` (bez něj podle
adresáře `config/`) přes `Core\Server\FileOwnership::matchTo()`. Mění jen
to, co se liší — běžný běh pod shipard-userem `chown` nevolá. Když
vlastníka nastavit nejde (jiný uživatel než root), `save()` skončí
výjimkou, dočasný soubor smaže a původní `state.json` nechá být; soubor,
který by DS zavřel, se nesmí zapsat potichu. Mód zůstává `0640`. Soubor
je zároveň v `PermissionSpec` (volitelný, `user:user 0640`), takže
`doctor` špatného vlastníka nahlásí a `fix-permissions` opraví soubory
vzniklé starší verzí ([operations/permissions.md](operations/permissions.md)).

## 4. Ovládání

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-state                                        # show
shpd-ds ds-state set read_only
shpd-ds ds-state maintenance --on --reason=import
shpd-ds ds-state maintenance --off
shpd-ds ds-state set pending_deletion --delete-after=2026-10-01 [--yes]
shpd-ds ds-state cron --off | --on                      # přepínač periodických úloh (sekce 8)

shpd-server ds-cron                                     # přehled všech DS: ID | Name | Cron | State
shpd-server ds-cron --off --all | --on|--off <id>…      # přepínač hromadně, bez cd do adresáře DS
shpd-server ds-create … --no-cron                       # založit DS rovnou s vypnutým cronem
shpd-server cron --slot=<slot> --ds=<id>                # jeden tik pro jeden DS (přepínač ignoruje)
```

Reference: [cli.md](cli.md) → `ds-state`, `ds-cron`, `ds-create`,
`cron --slot`. Na hostovaných DS drží desired state hosting (D6) — lokální
CLI je pro nehostované DS a nouzové zásahy; orchestrace přes `hosting-sync`
je fáze 3.

**Typické scénáře**

- **Import ze starého Shipardu:** hned po `ds-create` zapnout
  `maintenance --on --reason=import`; API, cron i pošta jsou po celou dobu
  importu mrtvé (503, router frontuje). Aktivace `--off` je explicitní
  krok na konci.
- **Přesun DS na jiný server:** na zdroji `maintenance --on
  --reason=migration` **před** dumpem; `state.json` cestuje v tarballu,
  DS je na cíli automaticky zavřený, dokud po sanity checku operátor
  neřekne `--off`. Viz [migration-guide.md](migration-guide.md).
- **Ukončení činnosti klienta „chce jen vidět účetnictví":** `set read_only`
  — přihlášení, prohlížení, reporty a přílohy fungují, zápisy server odmítá
  (sekce 6).

## 5. Třídy

| Třída | Účel |
|---|---|
| `Core\Config\DataSourceState` | readonly stav; `load()` / `save()`, gettery `getEffectiveState()`, `blocksHttp()`, `isCronEnabled()`, mutátory `withState()` / `withMaintenance()` / `withoutMaintenance()` / `withDeleteAfter()` / `withCron()` — konstruktor nese přepínač bez výchozí hodnoty, aby ho žádný mutátor tiše nezapnul |
| `Core\Server\FileOwnership` | `matchTo(cesta, vzor)` — vlastník a skupina dočasného souboru před `rename` (sekce 3, zápis pod rootem); vzor dává `dataSourceReference(dsDir)` = `config/main.json` → `config/` → adresář DS, stejné pravidlo používá `ds-secrets-rotate` ([operations/secrets.md](operations/secrets.md) §3); chráněné `ownerOf` / `chown` / `chgrp` jsou šev pro testy bez roota (`tests/Fixtures/Core/Server/RecordingFileOwnership`) |
| `Api\Exception\DataSourceUnavailableException` | hází resolver pro zavřený DS; nese `dsId`, `effectiveState`, `maintenanceReason`; `index.php` → 503 |
| `Api\ResolvedDataSource::$state` / `isReadOnly()` | stav načtený resolverem cestuje pipeline (politika, `/_app/info`, MCP, navigace) — disk se čte jednou |
| `Api\ReadOnlyPolicy` + `Api\ReadOnlyVerdict` | klasifikace rout pro `read_only` (sekce 6) |
| `Api\Mcp\McpToolRegistry::readOnlyView()` | registr jen s `isReadOnly()` nástroji — sdílí chat i `/_mcp` v `read_only` |
| `Core\Server\DataSourceStateScanner` + `DataSourceStateEntry` | sken `data-sources/*/config/state.json` pro doctor, `ds-state-check` (D8) a `ds-cron`; `countByState()['cronOff']`, `isCronOff()` |
| `Command\Server\CronCommand::JOB_ALLOWED_STATES` | registr job → povolené lifecycle stavy; smyčka dispatcheru čte přepínač před stavem, `--ds` = `executeSingle()` |
| `Command\Server\DsStateCheckCommand` | `shpd-server ds-state-check` — daily warning D8 |
| `Command\Server\DsCronCommand` | `shpd-server ds-cron` — přehled a hromadné přepínání (sekce 8) |
| `Command\DataSource\DsStateCommand` | `shpd-ds ds-state` vč. akce `cron` |

## 6. Fáze 2 — read-only vynucení, SPA, D8

### 6.1 `ReadOnlyPolicy` — verdikt per routa

Vynucení sedí v `public/index.php` jako krok **6.5** — za `AuthMiddleware`
(anonym dostane 401 dřív než informaci o stavu DS), před rate limitem.
Volá se **jen** při efektivním stavu `read_only`; `active` politiku
vůbec nevidí. Klasifikuje se **per routa `(controller, action)`, ne per
HTTP metoda** (D7): SSE chat je GET a spouští LLM, login je POST a fungovat
musí. Centrální tabulka, ne flag na `Route` — `Router` je ručně psaný
if-chain s desítkami konstrukcí a tabulka se testuje jako celek
(`tests/Unit/Api/ReadOnlyPolicyTest.php`).

Tři verdikty:

| Verdikt | Odpověď | Komu |
|---|---|---|
| `Allow` | routa běží | čtení, auth, reporty, přílohy download, MCP (filtr uvnitř) |
| `Deny403` | **403** `DS_READ_ONLY` „Data source is read-only" | uživatelské mutace — klient zobrazí, neretryuje |
| `Deny503` | **503** `DS_UNAVAILABLE` + `Retry-After: 300` (shodné s zavřeným DS) | strojový ingest: `/_mail/incoming` (D4) — volající frontuje, práci nezahazuje. (Callbacky AI analyzeru zanikly s pull protokolem, #85 D20.) |

**Fail-closed:** controller nebo akce mimo tabulku = `Deny403`. Nová routa
přidaná bez rozmyslu je v read-only zavřená, dokud ji někdo v
`ReadOnlyPolicy::TABLE` vědomě nepovolí. Zdroj pravdy o verdiktech je kód;
princip a odchylky:

- `auth`, `password` celé ALLOW — přihlášení, refresh, samoobsluha účtu
  (heslo, sessions, pozvánky) na read-only DS fungují.
- `chat` celý 403 (D5) včetně `list`/`show`; entry pointy (chat leaf
  v navigaci, capability `chat` i `mailUpload` v `/_ui/dashboard`) skrývá
  **server** — `NavigationController` i `DashboardController` dostávají
  `readOnly` a podmínka chat leafu zůstává identická s capability.
  `dashboard.summary` (LLM) 403.
- `hosting*` celé ALLOW — endpointy řídí lifecycle *jiných* DS a mají
  vlastní klíčovou auth; read-only hosting DS, který by zablokoval
  rekonciliaci flotily, je horší selhání než teoretická mutace hosting dat.
- `exchange` matchuje suffix akce (`person:apply` → `apply`):
  `validate`/`preview` ALLOW, `apply` 403. `analysis.previewMessage`
  (GET bez zápisu) ALLOW.
- `setup` čtecí akce zůstávají fail-closed 403 (kromě `checklist`) —
  jejich read-only povaha nebyla ověřena; povolit lze kdykoli.

### 6.2 MCP read-tier

`McpController` dostává `readOnly`: `tools/list` vrací jen nástroje
s `isReadOnly() === true` (`McpToolRegistry::readOnlyView()`, tentýž filtr
jako chat tool-use loop), `tools/call` na zápisový nástroj vrací JSON-RPC
`-32602` s důvodem „data source is read-only" — **ne** HTTP 403, MCP
klienti čtou JSON-RPC. Viz [mcp-server.md](mcp-server.md) §5.

### 6.3 SPA — stavová obrazovka a banner

SPA se servíruje staticky nginxem z `public/app/` (`docs/nginx/app.conf`),
`index.php` pro `/app/` neběží — stavová stránka tedy **není PHP HTML**, ale
SPA. Boot volá veřejný `GET /_app/info`:

- zavřený DS → 503 `DS_UNAVAILABLE` (details `_state` = efektivní stav)
  → `appInfo.unavailable` → `App.svelte` ukáže `StatusScreen` (generický
  Shipard vizuál — DS config se nečte; jeden text pro suspended /
  maintenance / pending_deletion, tlačítko Zkusit znovu = reload);
- `read_only` → odpověď nese `dsState: "read_only"` → `appInfo.readOnly`
  → trvalý `ReadOnlyBanner` v `ContentArea` (sdílí všechny shelly);
  `client.js` na 403 `DS_READ_ONLY` zobrazí jednotný toast
  (`noticeStore` + `GlobalToast` v `AppShell`), i18n `error.DS_READ_ONLY`.

`/_app/info` vystavuje jen `active` | `read_only` — blokované stavy
skončí 503 už v resolveru, nic citlivého neprozrazuje. Plošné skrývání
editačních prvků (Nový záznam, uložit ve formulářích, drag&drop příloh)
se dolaďuje průběžně — server vynucuje, banner + toast dávají
srozumitelnost.

### 6.4 D8 — zapomenutá maintenance

Alert checky běží *uvnitř* DS a v maintenance jsou cronem vypnuté — D8
je proto server-level. `DataSourceStateScanner` projde
`data-sources/*/config/state.json` (fail-closed soubory hlásí jako
`corrupted`), práh `MAINTENANCE_WARN_DAYS = 7` (konstanta; do
`server.json` až bude důvod). Dvě ústí:

- `shpd-server doctor` → sekce **Data source states**: počty per stav,
  `⚠` per DS v maintenance déle než 7 dní, `✗` per DS s poškozeným
  souborem (počítá se jako chyba), `·` ostatní ne-active DS.
- `shpd-server ds-state-check` v `daily` slotu
  (`CronCommand::SERVER_SLOT_JOBS`) → `warn` do `shipard.log` per DS
  (jednou denně, ne spam). Exit vždy 0 — nález není chyba jobu.

## 7. Fázování

- **Fáze 1:** `state.json`, 503 za resolverem, cron gating, CLI. Task
  `tasks/ds-state-phase1.md`.
- **Fáze 2 (sekce 6):** `ReadOnlyPolicy`, MCP read-tier, SPA stavová
  obrazovka + banner, D8. Task `tasks/ds-state-phase2.md`.
- **Fáze 3:** hosting — desired state v `hosting_core_data_sources`,
  aplikace přes `hosting-sync`, rekonciliace actual state zpět,
  `pending_deletion` mazací job se zálohou (D10). Rozlišení maintenance
  reason ve stavové obrazovce (503 details nesou jen efektivní stav).
- **Přepínač periodických úloh (#115):** fáze 1 = sekce 8 (task
  `tasks/cron-ds-switch.md`); fáze 2 = cron ve vývojové instalaci, první
  zapnutí přes `ds-cron --off --all` + seznam, návody v `docs/dev/`
  a doplnění `migration-guide.md` (D6, D10, D12).

## 8. Přepínač periodických úloh (#115 fáze 1)

**Smysl.** Zdroj dat, který je kopií reálných dat (import ze starého
Shipardu, později obnova ze zálohy), má zůstat v původním stavu — žádná
úloha v něm nemá nic vystavovat, zakládat ani mazat. Kategorie úloh se
nezavádějí (D1): cron pro DS buď běží celý, nebo vůbec. Výchozí stav je
**zapnuto** (D4), na produkčním i vývojářském serveru; import nic
nenastavuje (D13) — cíl importu řídí člověk přes CLI (`ds-create
--no-cron`, `ds-state cron --off`).

**Nezávislost.** Přepínač je třetí osa vedle lifecycle stavu
a maintenance: `set` ani `maintenance --on/--off` ho nemění a on nemění je.
Vypnutý cron nemá vliv na HTTP, poštu ani MCP — DS funguje běžně, jen
bez plánovaných běhů. `ds-reset` nechává `state.json` být, přepínač tedy
přežije cyklus reset → reimport.

**Co při vypnutí neběží** — všechny per-DS úlohy dispatcheru
(`CronCommand::SLOT_JOBS`) včetně úklidových: fronta odchozí pošty
(`mail-outbox-run`), reaper a sweepy analýzy pošty, upozornění
(`alerts-run`, `alerts-prune`), úklid idempotence pošty, instance tvrzení
DPH (`vat-periods-ensure`), periodická fakturace
(`work-orders-invoice-run`). Server-level joby (`hosting-sync`,
`ds-state-check`) se přepínačem neřídí.

**Co přepínač neřídí (D7).** Jen dispatcher. Příkazy spuštěné přímo
(`shpd-ds work-orders-invoice-run`, `shpd-ds alerts-run`) i odpojené
procesy po příjmu zprávy (analýza pošty) běží vždy — práce vyvolaná
uživatelem nebo příchodem zprávy není cron.

**Jeden tik pro jeden DS (D8).** `shpd-server cron --slot=<slot> --ds=<id>`
spustí úlohy slotu jen pro jmenovaný DS a přepínač ignoruje; stav z #56
platí dál (`suspended` / maintenance → nic, s vysvětlením). Nebere zámek
slotu a **nezapisuje heartbeat** — ruční běh by jinak v `doctor` zakryl
neběžící cron. Exit `FAILURE` při selhané úloze (plánovaný běh bez `--ds`
vrací `SUCCESS` i při selhání — beze změny).

**Heartbeat a log.** Přeskočený DS jde do samostatného čítače
`cronDisabledDataSources` (vedle `skippedDataSources` za stav) — do
heartbeatu slotu, logu `cron slot finished` i souhrnu na stdout. Přepínač
se vyhodnocuje **před** stavem, DS se počítá jen jednou.

**Viditelnost (D11).**

| Místo | Chování |
|---|---|
| `shpd-ds ds-state` (show) | řádek `Cron: on` / `off (periodic jobs are not running)` |
| `shpd-server ds-cron` | přehled `ID \| Name \| Cron \| State` + souhrn počtu vypnutých |
| `shpd-server doctor`, sekce Data source states | souhrn `cron off N`; per DS na **produkci** `⚠ … cron off — periodic jobs are not running (shpd-ds ds-state cron --on)`, jinde `· … cron off`; nezávisle na řádku o stavu, bez vlivu na exit kód; „All data sources active ✓" jen bez vypnutého cronu |
| vývojářský dashboard `/_dev/` | sloupec Cron (`on` / zvýrazněné `off`), `GET /_dev/api/data-sources` nese `cron` a `state`; jen zobrazení |
| aplikace | `GET /_app/info` → `cronEnabled` (jen boolean, endpoint je veřejný) → `appInfoStore.cronDisabled` → trvalý `CronOffBanner` v `ContentArea` pro všechny přihlášené; s `ReadOnlyBanner` sdílí sticky kontejner `.shpd-content__banners`, pruhy se skládají pod sebe |

**Ovládání.** `shpd-ds ds-state cron --on|--off` (per DS, z adresáře DS),
`shpd-server ds-cron` (přehled; `--off --all`, `--on|--off <id>…` —
hromadně, bez potvrzení, chyba jednoho DS nezastaví ostatní),
`shpd-server ds-create --no-cron` (cíl importu nikdy neběží se zapnutým
cronem; selhání zápisu `state.json` je chyba příkazu). Reference:
[cli.md](cli.md). Mimo fázi 1: řízení z hostingu, přepínání z dashboardu
a z UI, automatika při obnově ze zálohy (D12).
