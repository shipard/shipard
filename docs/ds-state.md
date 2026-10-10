# Stavy zdroje dat

Stavový model DS podle issue [shipard/shipard#56](https://github.com/shipard/shipard/issues/56)
(rozhodnutí D1–D10). Tento dokument popisuje **fázi 1** (stavový soubor,
503 pro zavřené DS, cron gating, CLI) a **fázi 2** (read-only vynucení na
routách, MCP read-tier, stavová obrazovka SPA, hlídání zapomenuté
maintenance — sekce 6). Fáze 3 (hosting orchestrace) je v závěru.

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
  "changedBy": "cli",
  "changed": "2026-09-01T10:00:00Z"
}
```

`maintenance` a `deleteAfter` volitelné. `deleteAfter` má smysl jen u
`pending_deletion` — přechod do jiného stavu ho zahodí. Časy UTC ISO 8601.
`changedBy` je ve fázi 1 vždy `cli`; hosting-sync (fáze 3) zapíše vlastní
identifikátor.

### Sémantika čtení (`DataSourceState::load`)

| Situace | Výsledek |
|---|---|
| soubor neexistuje | `active` bez overlay — zpětná kompatibilita, existující DS nic nemění |
| validní v1 | přečtený stav |
| nečitelný soubor, nevalidní JSON, neznámá `version`, neznámý `state`, neznámý `reason` | **fail-closed**: efektivní `suspended` + `error` do logu |

Fail-closed je záměr: poškozený stavový soubor nesmí tiše otevřít DS,
který měl být zavřený. `ds-state` (show) poškozený soubor ohlásí a poradí
opravu (`set <state>` soubor přepíše).

### Zápis (`DataSourceState::save`)

Atomicky tmp + rename (vzor `DomainsFile`), každý krok kontrolovaný,
selhání = výjimka — soubor čte resolver při každém requestu a cron každou
minutu, roztržený zápis by DS fail-closed zavřel. Nastavuje `changed`
a `changedBy`.

## 4. Ovládání

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-state                                        # show
shpd-ds ds-state set read_only
shpd-ds ds-state maintenance --on --reason=import
shpd-ds ds-state maintenance --off
shpd-ds ds-state set pending_deletion --delete-after=2026-10-01 [--yes]
```

Reference: [cli.md](cli.md) → `ds-state`. Na hostovaných DS drží desired
state hosting (D6) — lokální CLI je pro nehostované DS a nouzové zásahy;
orchestrace přes `hosting-sync` je fáze 3.

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
| `Core\Config\DataSourceState` | readonly stav; `load()` / `save()`, gettery `getEffectiveState()`, `blocksHttp()`, mutátory `withState()` / `withMaintenance()` / `withoutMaintenance()` / `withDeleteAfter()` |
| `Api\Exception\DataSourceUnavailableException` | hází resolver pro zavřený DS; nese `dsId`, `effectiveState`, `maintenanceReason`; `index.php` → 503 |
| `Api\ResolvedDataSource::$state` / `isReadOnly()` | stav načtený resolverem cestuje pipeline (politika, `/_app/info`, MCP, navigace) — disk se čte jednou |
| `Api\ReadOnlyPolicy` + `Api\ReadOnlyVerdict` | klasifikace rout pro `read_only` (sekce 6) |
| `Api\Mcp\McpToolRegistry::readOnlyView()` | registr jen s `isReadOnly()` nástroji — sdílí chat i `/_mcp` v `read_only` |
| `Core\Server\DataSourceStateScanner` + `DataSourceStateEntry` | sken `data-sources/*/config/state.json` pro doctor a `ds-state-check` (D8) |
| `Command\Server\CronCommand::JOB_ALLOWED_STATES` | registr job → povolené lifecycle stavy |
| `Command\Server\DsStateCheckCommand` | `shpd-server ds-state-check` — daily warning D8 |
| `Command\DataSource\DsStateCommand` | `shpd-ds ds-state` |

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
