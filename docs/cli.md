# CLI nástroje — kompletní reference

Shipard má dvě hlavní CLI utility a tři podpůrné shell skripty. Dohromady
pokrývají instalaci serveru, vytváření a správu datových zdrojů (DS),
údržbu (secrets, mail-router, AI připojení) a vývojový workflow.

| Nástroj | Účel | Spouštět z |
|---------|------|-----------|
| `shpd-server` | Správa serveru a DS na úrovni serveru (vytváření DS, hromadné upgrade, mapování domén) | libovolný CWD |
| `shpd-ds` | Správa konkrétního datového zdroje (schéma, uživatelé, secrets, mail, AI, seedy) | adresář DS (musí obsahovat `config/main.json`) |
| `scripts/dev-update.sh` | Po `git pull` — sync composer/npm/build | repo root (skript si dohledá sám) |
| `scripts/install-packages.sh` | Jednorázová instalace systémových balíčků (PHP, MariaDB, nginx, …) | repo root, jako root |
| `scripts/dev-bootstrap.sh` | Celé vývojové prostředí jedním příkazem (Multipass, WSL, čisté Ubuntu) | odkudkoli, pod běžným uživatelem |

`scripts/install-packages.sh` vytvoří symlinky `/usr/bin/shpd-server`
a `/usr/bin/shpd-ds`, takže oba nástroje jsou poté volatelné odkudkoliv.
V repu lze nástroje spustit i přímo přes `php bin/shpd-server` /
`php bin/shpd-ds` — stejné chování, žádný setup nepotřeba.

---

## `shpd-server` — kompletní reference

Server-level příkazy. Nepředpokládají žádný konkrétní DS jako CWD; pracují
nad `/etc/shipard/server.json` a `/opt/shipard/data-sources/`.

### `version`

```bash
shpd-server version
```

Vypíše verzi nástroje včetně git hashe HEAD (např. `Shipard 0.1.1 (abc1234)`;
bez dostupného gitu jen `Shipard 0.1.1`). Žádné options, žádné side-effecty.
Zdroj verze: `Shipard\Core\Version`.

### `help`

```bash
shpd-server help
```

Vypíše seznam příkazů a jejich popis. Default command — `shpd-server` bez
argumentů spustí `help`.

### `server-init`

```bash
sudo shpd-server server-init                              # development, user z $SUDO_USER
sudo shpd-server server-init --mode=production            # default user 'shipard'
sudo shpd-server server-init --mode=development --user=alice
```

Inicializuje server: vytvoří `/etc/shipard/server.json` (ownership `root:<user>`,
mode 0640), vygeneruje admin DB credentials (uživatel pro `CREATE DATABASE` /
`CREATE USER`). Spouští se **jednou** při instalaci serveru, typicky po
`scripts/install-packages.sh`.

| Opce | Význam |
|------|--------|
| `--mode <development\|production>` | Operační režim. Zapíše se do `server.json` jako `mode`. Default: `development`. |
| `--user <name>` | Shipard user (vlastní `/opt/shipard/`, běží jako PHP-FPM pool). Default: `$SUDO_USER` v development, `shipard` v production. |

### `doctor`

```bash
shpd-server doctor
```

Read-only health check. Vypíše:

- **Mode** z `/etc/shipard/server.json`
- **Shipard user** (detekce z owner `/opt/shipard/`)
- **PHP-FPM pool user** (z `/etc/php/*/fpm/pool.d/shipard.conf`)
- **Filesystem checks** — per-cesta kontrola existence + type + owner + group + mode
  dle [permission kontraktu](operations/permissions.md)
- **System config includes** — warn-only: živý nginx site a FPM pool
  includují verzované `shipard-common.conf` / `shipard-fpm-common.conf`
  (viz [production.md §6](operations/production.md)); nikdy nemění exit code
- **Cron** — `/etc/cron.d/shipard` existuje a marker odpovídá aktuální
  verzi šablony (zastaralá šablona = error v každém režimu, #115 U5);
  heartbeaty slotů nejsou zatuchlé (`minute` > 10 min, `two-minutes`
  > 15 min, `five-minutes` > 20 min; `daily`/`weekly` jen warning; selhané
  joby z posledního běhu = warning). Na **produkci** je chybějící soubor
  i zatuchlý nebo chybějící heartbeat krátkých slotů error. **Mimo
  produkci** (#115 D6) jen varování bez vlivu na exit code: chybějící
  soubor `⚠ … missing — periodic jobs … are not running` s radou
  `sudo shpd-server cron-install` a větou, že první instalace vypne cron
  existujícím zdrojům dat; zatuchlý heartbeat `⚠` s radou
  `systemctl status cron` (uspaný notebook, zastavená VM).
- **Data source DB connections** — pokus o `SELECT 1` na každý DS
- **Data source secrets** — tajemství zdrojů dat v `secrets/`
  ([operations/secrets.md](operations/secrets.md) §5, #126 B1): `⚠` tajemství
  ještě v `config/main.json` (`… still in config/main.json — run shpd-ds
  ds-upgrade`; v tasku B2 se stane chybou), `✗` chybějící heslo k databázi
  (v souboru ani v `main.json` — počítá se jako chyba), `⚠` OIDC provider
  bez `secrets/oidc-<id>.client-secret` (přihlášení přes něj selže), `⚠`
  `mail.relay` s `username` bez hesla; souhrn `N data source(s) still keep
  secrets in config/main.json` / `All secrets in secrets/ ✓`. Čte jen
  přítomnost souborů, nikdy hodnoty
- **Data source states** — stavy z `config/state.json`
  ([ds-state.md](ds-state.md)): počty per stav a `cron off N`, `⚠` DS
  v maintenance déle než 7 dní (zapomenutá? D8), `✗` poškozený
  `state.json` (DS fail-closed zavřený — počítá se jako chyba), `·` ostatní
  ne-active DS; vypnutý přepínač periodických úloh ([ds-state.md](ds-state.md)
  §8) na **produkčním** serveru `⚠ <id>: cron off — periodic jobs are not
  running (shpd-ds ds-state cron --on)`, jinde `· <id>: cron off` — řádek
  nezávislý na řádku o stavu, nikdy nezvyšuje počet chyb
- **Outbound mail** — řádek `Mail safety` s režimem pojistky odchozí pošty
  ([mail/outbound.md](mail/outbound.md) § Pojistka): cíl přesměrování
  a zdroj režimu (`configured` / `default`); `✗` chybná sekce `mail.safety`
  (nic neodejde — počítá se jako chyba), `⚠` vypnutá pojistka na
  neprodukčním serveru. Pak per DS relay / odesílatelé a stav fronty

Žádné side-effecty. Exit code `0` (vše OK) nebo `1` (alespoň jeden issue).
Bez sudo (jen čte).

### `fix-permissions`

```bash
sudo shpd-server fix-permissions --dry-run        # preview, lze i bez sudo
sudo shpd-server fix-permissions                  # interaktivní confirm
sudo shpd-server fix-permissions --force          # bez confirm
```

Sjednotí ownership a modes dle [permission kontraktu](operations/permissions.md).
Volá `chown`/`chgrp`/`chmod`. Žádné mazání ani vytváření — cesty které
chybí jsou označené jako `fixable: false` a fix-permissions se jich nedotkne.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše plánované změny, nic neaplikuje. Lze spustit i bez sudo. |
| `--force` | Přeskočí interaktivní `[y/N]` konfirmaci |

Typické použití po migraci ze staršího layoutu (`sebik:www-data` group hack):

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo shpd-server fix-permissions
shpd-server doctor    # ověř že vše zelené
```

### `ds-create --name <název> --language <cs|en> --country <cc> [--module <id>] [--ds-id <id>] [--no-cron] [--real-copy] [--purpose <text>]`

```bash
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz --module=install.base
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz --ds-id ab12-cd34-ef56-gh78   # provisioning agent
sudo shpd-server ds-create --name "Import" --language cs --country cz --real-copy --no-cron --purpose="Ověřování importu"   # cíl importu: kopie reálných dat, cron vypnutý
```

Vytvoří nový datový zdroj:

- vygeneruje unikátní ID (`xxxx-xxxx-xxxx-xxxx`)
- vytvoří `/opt/shipard/data-sources/<id>/` (config, att, cache)
- zapíše heslo runtime uživatele do `secrets/db.password` (ještě před
  založením databáze; `main.json` heslo nenese — #126 D1, D2,
  [operations/secrets.md](operations/secrets.md) §5); selhání = exit 1
- vytvoří MariaDB databázi a runtime DB uživatele
- vygeneruje `secrets/secrets.key` pro per-DS šifrování `encrypted_text` sloupců
- zapíše `config/main.json` (práva 0600) se zvoleným install modulem,
  jazykem (`defaultLanguage`), zemí (`country`) — vrstva A dle
  `docs/ds-setup.md` §5.1 — a **třídou dat** (`dataClass`, `origin`,
  `dataClassChanged`, volitelně `purpose`; #126 D6–D7): na serveru v režimu
  `development` `free` (ukázková / testovací data), s `--real-copy` nebo
  v produkci `realCopy`. Přehled tříd dává `ds-list`, změna `shpd-ds ds-class`.

Po vytvoření je třeba spustit `shpd-ds ds-upgrade` z adresáře nového DS,
aby se založilo schéma a načetla výchozí konfigurace modulů.

| Opce | Význam |
|------|--------|
| `--name <název>` | **povinné** — lidsky čitelný název DS |
| `--language <cs\|en>` | **povinné** — výchozí jazyk DS, ISO 639-1. Zapíše se do `main.json` → `defaultLanguage`. |
| `--country <cc>` | **povinné** — země subjektu, ISO 3166-1 alpha-2 lower-case (např. `cz`, `sk`). Validuje se jen tvar (`^[a-z]{2}$`) — sémantika proti `world.base.countries` patří volajícím (formulář hostingu, dev dashboard), cfgItem v okamžiku `ds-create` ještě není zkompilovaný. |
| `--module <id>` | volitelné (default: `install.base`) — install modul k aktivaci. Musí odpovídat adresáři `modules/install/<suffix>/`, jehož `module.jsonc` má id `install.<suffix>`. Seznam dostupných modulů: `ls modules/install/`. |
| `--ds-id <id>` | volitelné — explicitní ID místo generovaného (formát `xxxx-xxxx-xxxx-xxxx`, a-z0-9). Používá agent `hosting-sync` (ID generuje hosting). Existující adresář = chyba. |
| `--no-cron` | volitelné — hned po zápisu `main.json` zapíše `config/state.json` s `"cron": false` (`changedBy: ds-create`), takže DS nikdy neběží se zapnutým cronem ([ds-state.md](ds-state.md) §8). Pro cíl importu (#115 D14). Selhání zápisu je chyba příkazu s radou `shpd-ds ds-state cron --off`; souhrn hlásí `Cron: off`. Bez volby se `state.json` nezakládá. |
| `--real-copy` | volitelné — třída dat `realCopy` (kopie skutečných dat; cíl importu ze starého Shipardu, obnova zálohy), původ `ds-create --real-copy`. Bez volby je nový DS ve vývoji `free`; v produkci je `realCopy` vždy. Kopie reálných dat nedostane na vývojovém serveru serverové AI připojení (`dev-ai-key`, [ai.md](ai.md) §5.1, #126 D9) — bez vlastního připojení zůstane bez AI a frontu pošty nespustí. Cron tím **nemění** (D7): bez `--no-cron` souhrn jen připomene `Cron: on (a real copy usually runs without periodic jobs …)`. |
| `--purpose <text>` | volitelné — účel zdroje dat (jeden řádek, nejvýš 200 znaků; `main.json` → `purpose`). Neplatný účel = exit 1 ještě **před** založením databáze. Ukazuje ho `ds-list` a vývojářský dashboard. |
| `--no-server-ai` | **zastaralé** (U3) — jedno vydání zůstává jako alias `--real-copy` s upozorněním ve výstupu, pak zmizí. |

Souhrn vypíše `Data class:`, `Purpose:` (je-li) a v režimu `development`
řádek `Server AI:` — `on (free data source)`, `off (realCopy)` nebo
`off (no server AI key — shpd-server dev-ai-key)`. Nový `main.json` žádný
příznak `ai.useServerConnection` nenese (zanikl, #126 D9).

Oba povinné přepínače se validují **před** jakoukoli mutací — chybějící
nebo nevalidní hodnota nenechá po sobě adresář ani databázi.

Vytvořený `config/main.json` bude obsahovat `"modules": ["<id>"]`. Install
modul je top-level bundle, který deklaruje své závislosti (`core.system`,
`core.attachments`, …), které `ds-upgrade` tranzitivně rozresolvuje.

### `ds-delete <id> [--dry-run] [--yes]`

```bash
shpd-server ds-delete ab12-cd34-ef56-gh78 --dry-run   # jen vypíše, co by smazal
shpd-server ds-delete ab12-cd34-ef56-gh78             # potvrzení opsáním ID
shpd-server ds-delete ab12-cd34-ef56-gh78 --yes       # neinteraktivně (úloha dashboardu, skript)
```

Tvrdé smazání **volného** zdroje dat na vývojovém nebo testovacím serveru
(#103 D68–D69, `tasks/ds-delete.md`) — bez koše a bez zálohy. Smaže
v tomto pořadí:

1. **granty ostatních účtů** na databázi (`mysql.db`, na vývojovém serveru
   typicky `claude_ro`) — `DROP DATABASE` je neodebere; název se hledá
   surový i s escapovanými podtržítky (`a\_b`, tak ho ukládá phpMyAdmin),
   odebírá se `REVOKE ALL PRIVILEGES` (+ `GRANT OPTION`). Selhání je jen
   varování `[WARN]` — osiřelý řádek opraví člověk;
2. **databázi** (`DROP DATABASE IF EXISTS`);
3. **DB uživatele** zdroje dat (`DROP USER IF EXISTS`);
4. **adresář** zdroje dat rekurzivně (`config/`, `secrets/`, přílohy, …;
   symlink se odstraní jako soubor, nikdy se nenásleduje).

**Preflight** (nic se nemění, exit 1 se zprávou) odmítne:

- server v režimu `production` — i s `--yes`; mazání ostrého zdroje dat
  (záloha, portál hostingu) je samostatný postup ([roadmap.md](roadmap.md) M5);
- neplatné ID, chybějící adresář, adresář jako symlink, nečitelný nebo
  rozbitý `config/main.json`;
- třídu dat jinou než `free` — reálnou kopii (i zdroj bez třídy
  v `main.json`) smažeš jen po vědomém `cd <adresář> && shpd-ds ds-class
  set free`; žádný `--force` není;
- běžící úlohu vývojářského dashboardu na zdroji dat
  (`JobStore::activeJobFor`) — s odkazem na úlohu. Výjimka `--job=<id>`:
  úloha dashboardu, která příkaz sama spouští (předává ji `POST
  /_dev/api/data-sources/{id}/delete`; ručně se nepoužívá).

Před mazáním příkaz vypíše plán — databázi a uživatele s poznámkou „already
gone“, pokud už neexistují, a granty ostatních účtů; na to potřebuje admin
spojení k databázi i v `--dry-run`. **Potvrzení** = opsání ID zdroje dat
v interaktivním terminálu (přesměrovaný stdin nebo `-n` → exit 1 s radou
`--yes`); jiný text = `Aborted`, exit 1, nic smazáno. `--yes` potvrzení
přeskočí, preflight ne.

**Opakovatelnost:** chybějící databáze nebo uživatel nejsou chyba, adresář
se maže až nakonec — po částečném selhání (`Failed to drop database …`)
zůstane adresář i `main.json` a příkaz jde pustit znovu. **Nemaže:** logy
(`/opt/shipard/log`), adresáře úloh dashboardu (`/opt/shipard/run/dev-jobs`),
mapování domén (`domains.json` → `domain-remove`) ani granty na úrovni
tabulek (`mysql.tables_priv`). Sudo není potřeba — stačí vlastník zdrojů
dat s čitelným `server.json` (vývojový server); pod rootem funguje stejně.
Z dashboardu: kebab menu řádku → **Smazat…** ([dev/dev-daily.md](dev/dev-daily.md) § 1).

### `ds-upgrade-all`

```bash
sudo shpd-server ds-upgrade-all
sudo shpd-server ds-upgrade-all --ds=abcd-efgh-ijkl-mnop
sudo shpd-server ds-upgrade-all --dry-run
sudo shpd-server ds-upgrade-all --stop-on-error
```

Pustí `shpd-ds ds-upgrade` na **všech** DS v `/opt/shipard/data-sources/`.
Každý DS běží v subprocesu, takže havárie jednoho neovlivní ostatní.
Závěrem vypíše souhrn `Summary: X upgraded, Y failed`. Návratový kód `0`
jen když projde 100 % DS.

| Opce | Význam |
|------|--------|
| `--ds=<id>` | Pustit jen na DS s daným ID (jinak SUCCESS + zpráva, pokud ID neexistuje) |
| `--stop-on-error` | Po první chybě zastavit (default: pokračovat dalšími DS) |
| `--dry-run` | Jen vypsat seznam DS, které by se upgradovaly |

Použití typicky po `git pull`, pokud se měnily definice tabulek nebo
cfgItems modulů — viz [Workflow scénáře](#workflow-scénáře).

**Verbosity propagace:** `-v` se předává do vnitřního volání
`shpd-ds ds-upgrade` na každém DS:

```bash
sudo shpd-server ds-upgrade-all -v
```

### `upgrade`

```bash
sudo shpd-server upgrade --dry-run       # náhled: příchozí commity + plán kroků
sudo shpd-server upgrade                 # reálný běh
sudo shpd-server upgrade --full          # vynutí composer + frontend build
sudo shpd-server upgrade --skip-ds-upgrade
```

Orchestruje nasazení nové verze: `git pull --ff-only` → `composer install
--no-dev --optimize-autoloader` (jen při změně `composer.json`/`composer.lock`)
→ frontend build (jen při změně pod `frontend/`) → `ds-upgrade-all` →
`cron-install` (vždy, jen pod rootem — idempotentní regenerace
`/etc/cron.d/shipard`; na stroji mimo produkci, kde cron soubor ještě
neexistuje, je to první instalace a vypne cron existujícím zdrojům dat,
#115 D10 / U1) → `completion-install` (vždy, jen pod rootem —
bash completion obou binárek) → reload služeb (jen při změně verzovaných
systémových confů: `docs/nginx/**` → `nginx -t && systemctl reload nginx`,
`docs/php/**` → `systemctl reload php<ver>-fpm`; jen pod rootem, jinak
vypíše ruční příkazy) → `doctor`.
Každý krok běží jako subproces — příkaz aktualizuje kód, ze kterého sám běží,
takže orchestrátor od pullu dál nenačítá žádné nové třídy; `ds-upgrade-all`
i `doctor` už běží z nové verze.

Pre-flight: vyžaduje čistý worktree a branch (ne detached HEAD); po fetchi
vypíše příchozí commity. Bez příchozích commitů (a bez `--full`) skončí
`Already up to date.` Selhání kroku běh zastaví (žádný automatický rollback);
selhání doctoru vrátí FAILURE, ale kód zůstává nasazený. Selhání reload
kroku (typicky `nginx -t` nad rozbitým site configem) taky — kód je
nasazený, rozbitý je jen config služby; příkaz vypíše ruční reload příkazy.
Summary: `Upgraded <old> → <new> (<N> commits)`.

Uživatelé: pod rootem běží kroky přes `sudo -u shipard -H`, doctor přímo.
Přímo pod shipard userem běží kroky bez sudo a doctor se přeskočí
(`sudo shpd-server doctor` ručně). Jiný uživatel na produkci → abort.

| Opce | Význam |
|------|--------|
| `--dry-run` | Fetch + výpis příchozích commitů a plánu kroků, nic nemění |
| `--full` | Vynutí composer i frontend krok bez ohledu na změněné soubory |
| `--skip-ds-upgrade` | Přeskočí krok `ds-upgrade-all` |

**Verbosity propagace:** `-v` se předává do vnitřního `ds-upgrade-all`.

### `cron --slot=<slot> [--ds=<id>]`

```bash
shpd-server cron --slot=minute                          # z /etc/cron.d/shipard, ručně jen při ladění
shpd-server cron --slot=daily --ds=ab12-cd34-ef56-gh78  # jeden tik pro jeden DS (ladění, dohnání)
```

Dispatcher periodických úloh — volá ho generovaný `/etc/cron.d/shipard`
(viz `cron-install`). Pro každý DS (adresář v
`/opt/shipard/data-sources` s `config/main.json`) spustí per-DS příkazy
slotu subprocesem `shpd-ds <cmd>` s cwd v adresáři DS — **filtrované
podle stavu DS** (`config/state.json`, [ds-state.md](ds-state.md)):

| Slot | Kadence | Příkazy | Běží ve stavech | Modul |
|------|---------|---------|-----------------|-------|
| `minute` | každou minutu | `mail-outbox-run`, `mail-analysis-reap`, `mail-preprocess --sweep`, `mail-analyze --sweep` | `active` | `core.mail` |
| `two-minutes` | à 2 min | server-level: `hosting-sync` | vždy (server-level) | — |
| `five-minutes` | à 5 min | `exchange-rates-download` (síť jen při chybějícím lístku: nejvýš 6 dotazů, pauza 1 h), `alerts-run` (self-throttling přes `next_run_at`) | `active` | `economy.exchangeRates`, `core.alerts` |
| `daily` | denně 03:17 | `mail-idempotency-prune` (`active`, `read_only`), `vat-periods-ensure` (`active`), `work-orders-invoice-run` (`active`); server-level: `ds-state-check` | viz příkazy (server-level vždy) | `core.mail`, `economy.vat`, `economy.workOrders`; server-level — |
| `weekly` | neděle 04:43 | `alerts-prune` | `active`, `read_only` | `core.alerts` |

Registr povolených stavů je `CronCommand::JOB_ALLOWED_STATES`; job bez
záznamu běží jen v `active`. V `suspended` / maintenance /
`pending_deletion` neběží per-DS nic. Přeskočený DS se neloguje (minute
slot by spamoval) — počet jde do heartbeatu.

Po stavu se vyřadí **úlohy bez aktivního modulu** (sloupec Modul,
`CronCommand::JOB_MODULES`, #115 D15): DS bez modulu nemá jeho tabulky
a úloha by jen selhala. Aktivní moduly DS = přímé moduly z `config/main.json`
rozvinuté o závislosti (`Core\Server\DataSourceModules`; definice modulů
se načítají jednou za běh, moduly se zjišťují až pro DS, kterému po stavu
zbyla aspoň jedna úloha). Vyřazená úloha se **nespouští ani nepočítá** —
není běh, selhání ani skip stavem, v heartbeatu čítač nemá. Nelze-li
moduly určit (nečitelný `main.json`, chyba resolveru), běží všechny úlohy
jako dřív a do logu jde `warn` — chyba se ukáže jako selhaná úloha, tiché
vynechání by ji schovalo. Úloha s hodnotou `null` v mapě běží všude;
server-level jobů se moduly netýkají. Pořadí kontrol: přepínač → stav →
moduly.

Před stavem se vyhodnocuje **přepínač periodických úloh** (`"cron": false`
v `state.json`, [ds-state.md](ds-state.md) §8, #115 D1): vypnutý = DS se
přeskočí celý včetně úklidových jobů a počítá se do
`cronDisabledDataSources` (ne do `skippedDataSources`). Server-level joby
se přepínačem neřídí. Přepínač čte jen dispatcher — příkaz spuštěný přímo
(`shpd-ds alerts-run`) běží vždy (D7).

**`--ds=<id>`** (D8) spustí joby slotu jen pro jmenovaný DS: přepínač
ignoruje, stav DS platí dál (`suspended` / maintenance → nic,
s vysvětlením). Nebere zámek slotu, **nezapisuje heartbeat** (ruční běh
nesmí v `doctor` zakrýt neběžící cron) a nespouští server-level joby.
Výstup: řádek per job (`ok` / `failed, exit N` / `timed out`, trvání),
u selhaného jobu konec jeho výstupu; úloha vyřazená kvůli modulu
`· <job>: skipped (module <id> not active)` (na exit kód nemá vliv).
Exit **FAILURE** při selhaném jobu, neznámém ID nebo špatném tvaru ID
(ověřuje se před složením cesty). Kontext logu
`cli: cron --slot=<slot> --ds=<id>`.

Kromě per-DS jobů má slot volitelně **server-level příkazy**
(`CronCommand::SERVER_SLOT_JOBS`) — spouští se subprocesem
`shpd-server <cmd>` **jednou za běh slotu**, ne per DS, a **neřídí se
stavem DS**. Uživatelé: `hosting-sync` (two-minutes; na serveru bez sekce
`hosting` v server.json rychlý exit 0) a `ds-state-check` (daily, D8).

Chování:

- **Lock per slot** (flock na `/opt/shipard/run/cron-<slot>.lock`) —
  překrývající se běh se tiše ukončí (exit 0, info do logu), minute slot
  se nehromadí.
- **Continue-on-error** — chyba jobu na jednom DS nezastaví ostatní;
  timeout jobu 10 min (SIGTERM, po 5 s SIGKILL).
- **Heartbeat** — po doběhnutí zapíše
  `/opt/shipard/run/cron-<slot>.heartbeat` (JSON: timestamp, verze, počty
  DS/jobů/selhání, `skippedDataSources` = DS přeskočené kvůli stavu,
  `cronDisabledDataSources` = DS s vypnutým přepínačem periodických úloh,
  `corruptedStateFiles` = DS s nečitelným `state.json`, které jsou
  fail-closed zavřené) — čte ho `doctor`. Běh s `--ds` heartbeat nepíše.
- Loguje do centrálního `shipard.log`; stdout je minimální (cron redirect
  do `/opt/shipard/log/cron.log` je jen poslední záchrana).

Exit code: **SUCCESS i při selhaných jobech** (reportuje doctor a alert
checky, cron nesmí spamovat MAILTO); FAILURE jen infra chyba (neznámý
slot, nečitelný seznam DS, nezapsatelný heartbeat).

| Opce | Význam |
|------|--------|
| `--slot <slot>` | Povinné: `minute`, `two-minutes`, `five-minutes`, `daily`, `weekly` |
| `--ds <id>` | Volitelné: jen tento DS (celé ID `xxxx-xxxx-xxxx-xxxx`); přepínač cronu ignoruje, stav i moduly respektuje (vyřazené úlohy vypíše), bez zámku, heartbeatu a server-level jobů; exit podle výsledku spuštěných jobů |

### `cron-install [--dry-run | --check] [--keep-ds-cron]`

```bash
sudo shpd-server cron-install            # zapíše /etc/cron.d/shipard + /opt/shipard/run
shpd-server cron-install --dry-run       # náhled obsahu (+ koho by první instalace vypnula), lze bez sudo
shpd-server cron-install --check         # pro skripty: exit 0 = soubor odpovídá šabloně, 1 = chybí/liší se; bez výstupu
```

Idempotentní generátor `/etc/cron.d/shipard` (marker s verzí šablony,
přepis jen když se obsah liší, atomicky přes tmp + rename) a runtime
adresáře `/opt/shipard/run` (0750, owner shipard — locky a heartbeaty).
Volá ho `shpd-server upgrade` jako subproces a `scripts/dev-bootstrap.sh`;
ručně je potřeba při prvním nasazení nebo když ho doctor ohlásí jako
chybějící/zastaralý. Cron soubor neobsahuje žádné per-DS řádky —
`ds-create`/`ds-delete` regeneraci nevyžadují.

**První instalace mimo produkci** ([ds-state.md](ds-state.md) §8, #115 D10):
když `/etc/cron.d/shipard` před během neexistuje a server není v režimu
`production`, příkaz nejdřív **vypne periodické úlohy všem zdrojům dat na
stroji** (tatáž logika jako `ds-cron --off --all` — `DataSourceCronSwitch`),
vypíše je s názvem a poradí `sudo shpd-server ds-cron --on <id>…`, a teprve
potom zapíše cron soubor; opačně by první tik stihl proběhnout nad kopiemi
reálných dat. Selže-li vypnutí u kteréhokoli zdroje dat, cron soubor se
nezapíše a příkaz skončí `FAILURE` s výpisem, co opravit. Poškozený
`state.json` instalaci neblokuje (`·` řádek `skipped` — zdroj dat je
fail-closed zavřený a žádnou úlohu nedostane). Stroj bez zdrojů dat: jen
zápis souboru. Na produkci se na zdroje dat nesahá nikdy; přegenerování
existujícího souboru (nová šablona, jiné cesty) je nemění.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše cíl a rendrovaný obsah; u první instalace mimo produkci i seznam zdrojů dat (`cron on → off` / `cron off (unchanged)` / `state.json unusable — skipped`). Nic nezapíše. Lze bez sudo. |
| `--check` | Pro skripty (`dev-update.sh`): exit `0` = soubor existuje a odpovídá šabloně, `1` = chybí nebo se liší. Bez výstupu, bez sudo; má přednost před `--dry-run`. |
| `--keep-ds-cron` | První instalace mimo produkci nesáhne na zdroje dat (stroj jen s ukázkovými daty, skripty). |

### `completion-install`

```bash
sudo shpd-server completion-install      # zapíše /etc/bash_completion.d/{shpd-server,shpd-ds}
```

Idempotentní instalace bash completion pro `shpd-server` i `shpd-ds`.
Completion skripty generuje vestavěný Symfony Console příkaz
(`<binárka> completion bash`) — `completion-install` jen resolvne binárky
z PATH a výstup atomicky zapíše do `/etc/bash_completion.d/<name>`,
přepis jen když se obsah liší. Binárka mimo PATH, selhané generování nebo
chybějící `/etc/bash_completion.d` (bez balíčku bash-completion) → WARN
a přeskočení (exit 0); FAILURE jen při chybě zápisu. Volá ho
`shpd-server upgrade` jako subproces a `server-init` (best-effort).

Completion pro zsh/fish se neinstaluje — uživatel si skript vygeneruje
sám vestavěným příkazem a nainstaluje podle konvence svého shellu:

```bash
shpd-server completion zsh               # analogicky shpd-ds, fish
```

### `next-table-id`

```bash
shpd-server next-table-id
```

Vrátí další volné `tableId` napříč všemi moduly (čte `*.jsonc` definice
v `modules/*/*/tables/`). Pomocný příkaz při tvorbě nové tabulky —
hodnotu se vepíše do `tableId` v JSONC definici.

### `einvoice-render --format=isdoc --input=<canonical.json> --output=<soubor> --uuid-name=<text> [--issuing-system=<text>]`

```bash
shpd-server einvoice-render --format=isdoc --input=doc.json --output=doc.isdoc \
    --uuid-name=corpus/cz/vat-payer/0102-domestic-goods
```

Strukturovaná e-faktura (ISDOC 6.0.1) z canonicalu `shpd.docs.document.v1`
v JSON souboru — papírová projekce dokladu + `rows[].computed`
(#119 D9, [einvoice.md](einvoice.md)). Vstupní bod buildu korpusu
testovacích dokladů (`tests/corpus/cz/build.py`); nepotřebuje zdroj dat
ani `server.json`, názvy zemí bere z `modules/world/base/config/countries.jsonc`.
UUID dokladu je UUIDv5 z `--uuid-name` (stejné jméno = stejný soubor),
`--issuing-system` výchozí `Shipard` (bez verze a commitu — stejný vstup
dává stejné bajty). Výstup se před zápisem ověří proti XSD 6.0.1 i 6.0.2
a uloží atomicky (dočasný soubor vedle cíle + `rename`).

Exit 0 zapsáno; 1 vstup — neznámý `--format`, nečitelný JSON, chybějící
nebo nepodporovaná hodnota (cesty v canonicalu na stderr, nic se nezapíše);
2 výstup neprošel schématem (chyba generátoru, nic se nezapíše).

### `domain-add`, `domain-list`, `domain-remove`

```bash
sudo shpd-server domain-add --host firma1.shipard.cz --ds abcd-efgh-ijkl-mnop
shpd-server domain-list
sudo shpd-server domain-remove --host firma1.shipard.cz
```

Mapování hostname → DS ID. Načítá se při HTTP requestu pro výběr DS.
Zápis `domains.json` je atomický (tmp + rename). `domain-add` je
idempotentní: stejný host → stejný DS = no-op (exit 0), stejný host →
jiný DS = chyba.

| Příkaz | Opce |
|--------|------|
| `domain-add` | `--host <hostname>` (povinné), `--ds <ds-id>` (povinné) |
| `domain-list` | bez opcí |
| `domain-remove` | `--host <hostname>` (povinné) |

### `hosting-sync`

```bash
shpd-server hosting-sync              # jeden běh: reconcile + fronta + confirm + stats
shpd-server hosting-sync --dry-run    # vypíše frontu bez akcí (queue?peek=1)
shpd-server hosting-sync --stats      # vynutí stats krok i bez stats_wanted
```

Pull agent hostingu (D3, `docs/hosting.md` §5.2). Vyžaduje sekci
`hosting` v `/etc/shipard/server.json` (§5.1: `url`, `serverId`,
`apiKey` = `shpd_hk_…` z `hosting-server-key`); bez ní informativně
skončí s exit 0 — hosting je plně opt-in. Periodicky ho spouští
`shpd-server cron --slot=two-minutes`.

Jeden běh:

1. **Reconcile** — POST inventura lokálních DS (id, name, modules)
   + verze shpd; hosting aktualizuje `last_seen`/`last_version`
   a rozdíly loguje.
2. **Fronta** — GET požadavky (`lifecycle` request/creating) → pro každý:
   `ds-create --ds-id --language --country` (existující adresář = skip,
   chybějící jazyk/země v payloadu = chyba požadavku) → `ds-upgrade` →
   `domain-add` → merge `auth.providers` do `main.json` (provider
   `shipard-id`, `autoLinkEmail: false`, atomicky, 0600, vlastník podle
   adresáře `config/` — ruční běh pod `sudo` zachová vlastníka zdroje
   dat; když vlastníka nejde nastavit, požadavek skončí `failed`
   a `main.json` se nezmění) → `user-create`
   vlastníka (`--admin --if-not-exists` + předpropojená identita) →
   POST confirm `ok`/`failed` (chyba jednoho požadavku nezastaví další).
3. **Stats push** (D7) — jen když reconcile response vrátí
   `stats_wanted: true` (nejstarší snapshot serveru starší ~10 min)
   nebo běh dostal `--stats`: `shpd-ds hosting-stats --json` per
   lokální DS → jedním POST `…/stats` (selhání jednoho DS = skip
   + log, prázdný sběr se neposílá).

HTTPS povinné (`http` jen pro localhost dev); `--dry-run` frontu
nepřeklápí, payload neobsahuje client_secret a stats krok neběží.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše frontu požadavků bez jakýchkoli akcí. |
| `--stats` | Vynutí stats krok i bez `stats_wanted` z reconcile. |

### `ds-state-check`

```bash
shpd-server ds-state-check        # z cron daily slotu, ručně jen při ladění
```

D8 z [ds-state.md](ds-state.md): projde `config/state.json` všech DS a pro
každý v maintenance déle než **7 dní** (`DataSourceStateScanner::
MAINTENANCE_WARN_DAYS`) zapíše `warn` do `shipard.log` (`data source in
maintenance longer than threshold — forgotten?`) a řádek `⚠` na stdout;
poškozený `state.json` (DS fail-closed zavřený) vypíše jako `✗`. Server-level
job `daily` slotu — alert checky běží uvnitř DS a v maintenance jsou
vypnuté, zapomenutou maintenance musí hlásit server. Exit vždy `0`
(nález není chyba jobu; interaktivně totéž ukáže `doctor`).

### `ds-cron [--on|--off] [--all | <id>…]`

```bash
shpd-server ds-cron                                   # přehled: ID | Name | Cron | State
shpd-server ds-cron --off --all                       # vypnout periodické úlohy všem DS na stroji
shpd-server ds-cron --off ab12-cd34-ef56-gh78 …       # vypnout vyjmenovaným
shpd-server ds-cron --on ab12-cd34-ef56-gh78 …        # zapnout vyjmenovaným
```

Přepínač periodických úloh přes všechny DS na stroji, bez přechodu do
adresáře DS ([ds-state.md](ds-state.md) §8, #115 D14). Per-DS varianta
téhož je `shpd-ds ds-state cron`.

**Přehled** (bez voleb): tabulka řazená podle ID — `Name` z `main.json`
(nečitelný → `?`, příkaz kvůli tomu neselže), `Cron` `on` / `off`
(poškozený `state.json` → `?`), `State` = efektivní stav, maintenance
s důvodem, poškozený soubor jako `corrupted`. Pod tabulkou počet DS
a počet s vypnutým cronem. Seznam bere `DataSourceStateScanner` (jen
adresáře s `config/main.json`).

**Přepnutí:** právě jedna z `--on` / `--off` a `--all`, nebo aspoň jedno ID
(ne obojí) — jinak chyba s nápovědou a nic se nezapíše. Bez
potvrzovacího dotazu (volá se ze skriptů). Vlastní přepnutí dělá
`DataSourceCronSwitch`, kterou používá i první instalace cronu
v `cron-install` (D10). `changedBy: cli`. Po přepnutí vypíše přehled.
Pod rootem zapsaný `state.json` patří vlastníkovi zdroje dat (podle
`config/main.json`), ne rootu — viz `ds-state`.

| Výsledek per DS | Význam |
|---|---|
| `✓ <id>: cron on/off` | zapsáno |
| `· <id>: unchanged` | už v cílovém stavu, soubor se nepřepisuje |
| `✗ <id>: …` | neznámé ID, špatný tvar ID, poškozený `state.json` (nepřepisuje se — oprava přes `ds-state set`), selhání zápisu; příkaz pokračuje dalšími |

Exit code: SUCCESS; FAILURE při chybě aspoň jednoho DS nebo neplatné
kombinaci voleb.

> Na vývojářském stroji s kopiemi reálných dat spouštěj `--all` vědomě —
> zapisuje do všech DS.

### `ds-list [--json]`

```bash
shpd-server ds-list              # tabulka: ID | Name | Class | Purpose | Origin | Country | Modules | Cron | State [| Server AI]
shpd-server ds-list --json       # stabilní kontrakt pro skripty a Clauda
```

Přehled zdrojů dat na stroji (#126 D10) — **jediný zdroj přehledu i pro
Clauda** (ruční tabulka v `CLAUDE.local.md` odpadla, [ai-workflow.md](ai-workflow.md)
§7). Čte jen `config/main.json` a `config/state.json` přes sdílený čtenář
`DataSourceInventory` (allowlist polí — bez hesla k databázi, `auth`
a `mail`, nezávisle na tasku B z #126), do databáze se nepřipojuje, funguje
i pro zdroj v údržbě a sudo nepotřebuje — stačí uživatel, který `main.json`
přečte (vlastník zdrojů dat). Řazení podle ID (jako `ds-cron`).

**Tabulka:** `Class` = `free` | `realCopy` | `realCopy*` (třída v `main.json`
chybí — platí realCopy) | `realCopy!` (neplatná hodnota — platí realCopy);
legenda pod tabulkou. `Purpose` zkrácený na 40 znaků (plná hodnota
v `--json`). `Cron` `on` / `off` (poškozený `state.json` → `?`), `State`
efektivní stav nebo `corrupted`. Sloupec `Server AI` jen na serveru v režimu
`development`: `on` = zdroj je `free` a server má klíč (`dev-ai-key`).
Nečitelný `main.json` (0600 pod jiným uživatelem, rozbitý JSON) dá v jeho
sloupcích `?` a pod tabulkou radu spustit příkaz jako vlastník nebo se
sudo. Souhrn: `N data source(s): X free, Y realCopy (Z without explicit class)`.

**`--json`:**

```json
{
  "server": { "mode": "development", "serverAi": true },
  "dataSources": [
    {
      "id": "…", "name": "…", "created": "…",
      "dataClass": "free", "dataClassExplicit": true, "dataClassInvalid": false,
      "purpose": "…", "origin": "dataset-seed cz-vat-payer", "dataClassChanged": "2026-10-16T12:00:00Z",
      "dataset": { "name": "cz-vat-payer", "hash": "427e83…", "seededAt": "2026-10-16T12:00:00Z" },
      "country": "cz", "defaultLanguage": "cs", "modules": ["install.base"], "databaseName": "…",
      "state": "active", "cron": true, "serverAi": true, "readable": true
    }
  ]
}
```

`dataset` = která sada ve zdroji dat je (#103 F6b D59): zapisuje
`dataset-seed` po úspěšném seedu (`hash` = hash obsahu sady jako v souboru
výsledků `ai-eval`), `null` u zdrojů dat seedovaných před F6b, po
`--no-reset` nebo bez sady. Jen v `--json`, tabulka ho nevypisuje.

Exit 0 i s nečitelnými řádky a bez zdrojů dat (prázdný seznam, tabulka
`(no data sources)`).

### `dev-ai-key --set-key | --status`

```bash
sudo shpd-server dev-ai-key --set-key          # klíč skrytým vstupem (nebo ze STDIN bez TTY)
sudo shpd-server dev-ai-key --status           # existence, mtime, práva — nikdy obsah
echo "$KEY" | sudo shpd-server dev-ai-key --set-key
```

Serverový AI klíč pro vývojové zdroje dat (#103 D10, D40; [ai.md](ai.md)
§5.1): `/etc/shipard/secrets/dev-ai-<platforma>.key` (0600, vlastník
shipard-user; adresář `secrets/` 0700 — hlídá `doctor`, opraví
`fix-permissions`). Platformu a volitelnou adresu určuje sekce `devAi`
v `server.json` (`{"platform": "anthropic", "baseUrl": null}`; chybí =
`anthropic` s výchozí adresou platformy). Klíč nikdy nejde argv (shell
history); přepsání = rotace. Příkaz **odmítne server mimo režim
`development`** — serverové připojení tam neexistuje.

Použije ho jen zdroj dat s třídou dat `free` (`dataClass` v `config/main.json`,
#126 D9 — `ds-create` ve vývoji, `dataset-seed` po resetu, `shpd-ds ds-class
set free`; kopie reálných dat `realCopy` i zdroj bez třídy ho nedostanou
nikdy, opt-out neexistuje) a jen když nemá vlastní aktivní připojení
s klíčem. Kdo ho používá, ukáže `ds-list` (sloupec *Server AI*). Ověření:
`cd /opt/shipard/data-sources/<id> && shpd-ds ai-test --function
mail.extraction` ukáže připojení `server`. Dřívější příznak
`ai.useServerConnection` zanikl — `ds-upgrade` ve vývoji na zdroj s tímto
příznakem a bez třídy upozorní (`[WARN]`), klíč odebere první zápis třídy. Exit 0; FAILURE mimo
`development`, při špatné kombinaci voleb, prázdném klíči, chybě zápisu
a u `--status` při jiných právech než 0600.

### `dev-update-check [--json]`

```bash
shpd-server dev-update-check          # fetch stable z GitHubu: pozadu / napřed, commity, pojistky aktualizace
shpd-server dev-update-check --json   # totéž jako JSON (stav, pojistky, HEAD) — čte ho dashboard
```

Kontrola zastaralosti vývojového checkoutu (#129 D16–D18,
`tasks/dev-dashboard-update.md`): `git fetch --quiet --no-tags` větve
`stable` z pevné adresy `https://github.com/shipard/shipard.git` —
anonymně, `GIT_TERMINAL_PROMPT=0`, timeout 30 s; nikdy `fetch origin` ani
`pull` (SSH s heslem, cizí fork). Cíl fetche je `refs/remotes/origin/stable`,
když `origin` ukazuje na `shipard/shipard`, jinak `refs/shipard/upstream/stable`.
Spočítá `behind` / `ahead`, nejstarší chybějící commit, posledních 10 commitů
a odkaz na porovnání a zapíše to atomicky do
`/opt/shipard/run/dev-update/state.json` (cache pro štítek v pruhu
dashboardu; běží pod `flock` na `check.lock` — druhá kontrola naráz skončí
hned s exit 0). Pojistky aktualizace se počítají při každém volání znovu,
ne z cache: `notStable`, `dirty` (jen sledované soubory), `notFastForward`
(lokální commity navíc), `jobRunning`, `missingTool` (`git`, `composer`,
`npm` v pevném PATH úlohy), `notChecked`. Dashboard příkaz spouští jako
odpojený proces, když je cache starší než hodinu, chybí, nebo se od ní
změnil HEAD; po selhané kontrole (bez sítě) nejdřív za 10 minut
(„Zkontrolovat teď“ kdykoli). Jen režim `development`. Exit 0 i když je checkout
pozadu; 1 jen při selhání kontroly (`ok: false`, `errorCode` `timeout` /
`fetchFailed` / `notARepository` / `gitFailed`) a mimo development.

### `dev-update [--yes] [--job=<id>]`

```bash
shpd-server dev-update          # kontrola → dotaz → fast-forward → dev-update.sh → ds-upgrade-all → cron check
shpd-server dev-update --yes    # bez dotazu (úloha dashboardu, skript)
```

Aktualizace vývojového checkoutu v pěti krocích s hlavičkami `==> [n/5]`
(#129 D20–D22): 1. `dev-update-check` + pojistky — kterákoli nesplněná =
exit 1 s radou, nic se nemění (aktuální checkout = „nothing to do“, exit 0);
2. `git -c core.hooksPath=/dev/null merge --ff-only <cílová reference>` —
hook `post-merge` z `.githooks` by jinak pustil `dev-update.sh` podruhé;
3. `bash scripts/dev-update.sh` (už nová verze skriptu, bez připomínky
cronu); 4. `shpd-server ds-upgrade-all`; 5. `shpd-server cron-install
--check` — exit 1 je jen upozornění s příkazem `sudo shpd-server
cron-install`, ne selhání. Na konci nový `dev-update-check`, aby štítek
v dashboardu zmizel. Podprocesy dostávají pevný `PATH`
`/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin` (PHP-FPM
ho nepředává) a git bez dotazů na heslo. **Kód se mění pod běžícím
procesem:** po kroku 2 příkaz nenačte žádnou novou třídu — vše resolvuje
předem a kroky 3–5 běží jako samostatné procesy (vzor `upgrade`). Bez
`--yes` se v terminálu zeptá; neinteraktivně bez `--yes` odmítne. Jen
režim `development`. Exit 1 při nesplněné pojistce nebo selhání kroků 2–4
(po selhání kroku 3 nebo 4 je checkout už na nové verzi — dokonči ručně).

| Opce | Význam |
|------|--------|
| `--yes`, `-y` | Bez potvrzení (úloha dashboardu, skripty). |
| `--job=<id>` | Id úlohy dashboardu, která příkaz spouští — vlastní běžící úloha se v pojistce `jobRunning` nepočítá (stejně jako `ds-delete --job`). |

Z dashboardu: štítek **N commitů pozadu** v pruhu → **Aktualizovat**
([dev/dev-daily.md](dev/dev-daily.md) § 1); úloha `update` nemá zdroj dat
a nejde zrušit.

---

### `test-round [--only=<vrstvy>] [--dataset=<n|adresář>]… [--scenario=<id>]… [--eval=<varianta> --repeat=<n> --max-cost=<USD>] [--base-url=<origin>] [--detach | --follow=<id>] [--json]`

```bash
shpd-server test-round                       # celé kolo: kód → veřejné sady → videa; sleduje ho v terminálu
shpd-server test-round --only code           # jen vrstva kódu (PHPUnit, generátor, frontend, CSS, video-runner, spec)
shpd-server test-round --only datasets --dataset cz-non-payer --json
shpd-server test-round --detach              # vypíše id úlohy a skončí; shpd-server test-round --follow <id> připojí znovu
shpd-server test-round --only datasets --dataset cz-non-payer --eval baseline --repeat 1 --max-cost 1
```

Testovací kolo jedním příkazem (#103 F8a D130–D135, `docs/test-corpus.md`
§ 21): tři vrstvy — **kód** (celý PHPUnit s `memory_limit=1G`, `python3 -m
unittest` v `tools/invoice-generator`, `npm test` a `npm run check:i18n` ve
`frontend`, kontroly CSS, `npm test` ve `tools/video-runner`, `npm run
test:unit` v `tests/e2e`), **sady** (pro každou veřejnou sadu
z `tests/datasets` přeseedování, `dataset-verify` a `dataset-e2e`) a
**videa** (`video-run --verb check` každého scénáře). Kolo **vždy běží
jako úloha dashboardu** typu `round`: příkaz udělá preflight (jen režim
`development`; adresář `/opt/shipard/run/dev-jobs` vlastněný uživatelem
příkazu — spouštěj pod uživatelem poolu php-fpm, jinak kolo dashboard
neuvidí ani nezruší; `--base-url` jen lokální host, D97; známé sady
a scénáře), založí úlohu a bez `--detach` ji sleduje: řádky přechodů
kroků, na konci tabulka vrstva / sada / scénář / krok / stav / doba /
dílčí úloha / důvod (`--json` celý `result.json`), `-v` navíc streamuje
`output.log`. První Ctrl+C kolo zruší (i běžící dílčí úlohu) a sleduje
dál, druhé odpojí terminál — kolo běží dál. Kroky na zdrojích dat jsou
dílčí úlohy dashboardu (`reseed` / `create` / `verify` / `e2e` / `video` /
`eval`) nad **vlastními** zdroji dat kola (účel `testovací kolo — <sada>`,
chybějící kolo založí `ds-create --no-cron` + seedem; videa `videa —
<sada>`); cizí zdroj dat se nikdy nepřeseeduje, obsazený = krok `skipped`.
Kolo pokračuje po selhání; souhrn nese commit, větev a příznak
necommitnutých změn. Výchozí kolo nic neutrácí: evaluace jen s `--eval`
a povinným `--max-cost` (strop za celé kolo) — nejdřív odhad per sada
(`ai-eval --estimate`), nad stropem kolo nezačne; odhad potřebuje
existující zdroj dat kola.

| Opce | Význam |
|------|--------|
| `--only=<vrstvy>` | `code`, `datasets`, `videos` čárkami; výchozí všechny. |
| `--dataset=<název \| adresář>` | Jen tato veřejná sada (název z `tests/datasets`, nebo adresář s `manifest.jsonc` — kopie sady mimo repo); opakovatelné; neznámá = exit 2. Filtruje jen vrstvu sad. |
| `--scenario=<id>` | Jen tento scénář z `demo/scenarios`; opakovatelné; neznámé id = exit 2. |
| `--eval=<varianta>` | Spustit i evaluaci (varianta z `tests/eval/variants`); vyžaduje `--max-cost`. |
| `--repeat=<n>` | Opakování evaluace per sada (1–5, výchozí 1). |
| `--max-cost=<USD>` | Strop útraty za evaluace celého kola; dílčí `eval` dostane zbytek po předchozích. |
| `--base-url=<origin>` | Lokální adresa aplikace pro proklikání a videa (výchozí `http://127.0.0.1`). |
| `--detach` | Jen spustit a vypsat id úlohy. |
| `--follow=<id>` | Připojit se k běžícímu (nebo hotovému) kolu místo nového. |
| `--json` | Na konci `result.json` místo tabulky. |

Exit `0` jen když všechny kroky `ok`, `1` cokoli selhalo nebo se
přeskočilo, `2` kolo nezačalo (preflight, limit ceny, běží kolo nebo
`update`), `130` zrušeno / odpojeno. Z dashboardu: Testování → **Spustit
testovací kolo** ([dev/dev-daily.md](dev/dev-daily.md) § 1).

---

### `dev-job-run` (interní, skrytý)

```bash
shpd-server dev-job-run /opt/shipard/run/dev-jobs/<id>     # spouští jen vývojářský dashboard
```

Obal úlohy na pozadí vývojářského dashboardu (#103 F6b D58,
`docs/test-corpus.md` § 18 → Úlohy na pozadí, `docs/dev/dev-daily.md` § 1):
`JobLauncher` ho spustí přes `setsid -f` jako session leadera, obal drží
`flock` na `wrapper.lock`, provede kroky z `job.json` (`proc_open` s argv,
stdout kroku do `result.json` nebo `output.log`) a stav zapisuje do
`state.json`. SIGTERM = zrušení (běžící krok dostane SIGTERM, po
`cancelGraceSeconds` SIGKILL), exit 0 hotovo / 1 selhání / 130 zrušeno.
V nápovědě se neukazuje; ručně se nespouští.

### `test-round-run` (interní, skrytý)

```bash
shpd-server test-round-run /opt/shipard/run/dev-jobs/<id>  # jediný krok úlohy `round`; spouští obal dev-job-run
```

Orchestrátor testovacího kola (#103 F8a, `docs/test-corpus.md` § 21):
čte výběr z `job.json` úlohy `round`, kroky kódu pouští jako podprocesy,
kroky na zdrojích dat jako dílčí úlohy s `parent`, souhrn píše průběžně do
`result.json` úlohy, log na stderr (`output.log`). SIGTERM = zrušení
(běžící krok kódu SIGTERM, běžící dílčí úloha `cancel`, zbytek `skipped`).
Exit 0 / 1 / 130. Ručně se nespouští — bez obalu by nebyl `state.json`;
k tomu je `test-round`.

## `shpd-ds` — kompletní reference

> **Pozor:** `shpd-ds` se musí spouštět z **adresáře datového zdroje**, který
> obsahuje `config/main.json`. Většina příkazů jinak skončí chybou.
> Typicky `cd /opt/shipard/data-sources/<id>` a poté `sudo shpd-ds <command>`.

### Základ

#### `version`

```bash
shpd-ds version
```

Verze nástroje.

#### `help`

```bash
shpd-ds help
```

Vypíše seznam příkazů.

#### `ds-upgrade`

```bash
sudo shpd-ds ds-upgrade
```

Synchronizuje DS s aktuálním stavem modulů. Sedm kroků (všechny idempotentní —
opakované spuštění bez efektu, pokud se nic nezměnilo):

0. **Secrets migration** — tajemství z `config/main.json`
   (`database_password`, `mail.relay.password`,
   `auth.providers[].clientSecret`) do `secrets/` (`db.password`,
   `mail-relay.password`, `oidc-<id>.client-secret`), teprve potom
   odebrání klíčů z `main.json` (#126 D4,
   [operations/secrets.md](operations/secrets.md) §5). Jen soubory, před
   připojením k databázi; `[INFO] Moved N secret(s) from config/main.json
   to secrets/: …` i bez `-v`, `[WARN]` u souboru s jinou hodnotou
   (přepíše se hodnotou z `main.json`) nebo providera s neplatným `id`
   (tajemství zůstává), `[ERROR]` + exit 1 u nečitelného `main.json`
   (nic se nemění). Bez tajemství v `main.json` no-op; zůstává natrvalo
   (obnova starší zálohy)
1. **Modules resolve** — spočítá závislosti modulů, ověří absenci cyklů
2. **Table definitions + extensions** — sloučí tabulky modulu s extension sloupci
3. **Config compile** — vygeneruje `compiled.<jazyk>.json` z JSONC zdrojů
   pro jazyky rozhraní (`cs`, `en`) a jazyky dokumentů
   (`world.base.documentLanguages`, dnes navíc `sk`, `de`) — tisk čte
   konfiguraci v jazyce dokladu ([prints.md](prints.md) §3)
4. **Schema sync** — `CREATE TABLE` / `ADD COLUMN` / bezpečný `MODIFY`
   (nikdy nesmaže — viz [docs/table-definitions.md](table-definitions.md))
5. **Provisioning** — výchozí číselníky a referenční data. Součástí je i
   **sync AI profilu** ze šablony v repu
   (`modules/core/mail/profiles/czech_general.jsonc`): pokud má
   šablona novější `prompt_version` než DB, aktualizuje obsahová pole
   profilu (`[UPDATE] profile ...`). Jen upgrade — nikdy downgrade (DB
   novější → `[WARN]`, na vědomý downgrade je `ai-profile-reload --force`);
   admin pole (`name`, `is_default`, `is_active`, `backend`) se nedotýká
6. **Secrets health** — varuje, pokud `secrets/secrets.key` chybí nebo má špatná práva

Spustit po každém `git pull`, pokud se měnily definice tabulek nebo
cfgItems modulů.

**Verbosity:** výchozí výstup obsahuje jen akce a varování (`[CREATE]`,
`[ALTER]`, `[INFO]`, `[WARN]`, `[ERROR]`). Pro kompletní výpis včetně
průběhu kompilace konfigurace, kontroly schématu po tabulkách a
provisioner detailů použij `-v`:

```bash
shpd-ds ds-upgrade -v
```

**Vypnutí provisioningu (`skipProvisioning`):** volitelný boolean v
`config/main.json`. Když je `true`, `ds-upgrade` synchronizuje schéma, ale
přeskočí generování referenčních dat (units, druhy položek, fiskální roky,
VAT období, číselné řady, mail router). Bezpodmínečně (i pod
`skipProvisioning`) běží jen enginové kontrakty: clearing 261200/261300
se skupinou Nespárované platby, tranzit 261100, podrozvahové účty
756100/799100 s opravou povahy 75–79 (`OffBalanceAccountsProvisioner`,
#79 D2), AI připojení, řádky AI funkcí a profil pošty, pravidla předzpracování pošty a vázané řady.
Určeno pro import dat
z jiného systému, kde tyto údaje dodává sám import. Po dokončení importu
nastav `skipProvisioning` zpět na `false` a spusť `ds-upgrade` znovu —
provisionery jsou idempotentní, doplní jen chybějící data. Při zapnutém
flagu `ds-upgrade` při každém běhu hlásí `[SKIP] Provisioning disabled via
config`.

**Saldokonta pod `skipProvisioning` vznikají ve variantě legacy** (#69
D18): seed skupin se založí bez sign-pravidel dobropisů a s částkami
„Všechny“ — chování starého Shipardu, dobropis zůstává záporně ve své
skupině. Import nastavení saldokont ze starého systému se zrušil
(`accbal.md` §12). Skupina nese `provisioning_variant = legacy`; pozdější
`ds-upgrade` s flagem `false` do ní sign-pravidla nedoplní, přepnutí na
výchozí chování je ruční úkon účetní na přelomu roku (`accbal.md` §3.2).

**Provisioning AI pošty běží vždy**, i pod `skipProvisioning` — default
backend, default profil i version sync profilu nejsou migrovaná data, ale
systémový kontrakt modulů `core.mail`/`core.ai` (stejně jako clearing
infrastruktura). Tentýž krok jednorázově deaktivuje systémového uživatele
`_ai_analyzer` zrušeného externího analyzeru a zneplatní jeho API klíče
(`[DEACTIVATE]`, jen při změně; účet se nemaže kvůli `created_by` starých
běhů — #85 D21). Na DS bez modulu `core.mail` se tiše přeskočí (verbose
`[SKIP]`).

#### `ds-reset`

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-reset --dry-run     # co by se smazalo
sudo shpd-ds ds-reset               # interaktivní potvrzení [y/N]
sudo shpd-ds ds-reset -y            # bez potvrzení (skriptování)
sudo shpd-ds ds-reset --keep=core_mail_mailboxes -y
```

Uvede DS do čistého stavu pro **opakovaný test kompletního importu**.
Smaže všechny „datové" tabulky (číselníky, osoby, položky, doklady, došlá
pošta, …) a delegací na `ds-upgrade` znovu vytvoří schéma i referenční data.

Které tabulky reset přežijí, se řeší **deklarativně** polem `keepOnReset`
v `module.jsonc` každého modulu (viz [docs/modules.md](modules.md)). Ve
výchozím stavu zůstávají:

- `core.system` — uživatelé, relace, nastavení, API klíče (vč. importního),
  rate limity (login funguje i po resetu),
- `core.ai` — `core_ai_backends` kvůli zašifrovanému AI klíči
  (`ai-backend-set-key`),
- `core.mail` — `core_mail_ai_profiles`: AI profil je konfigurace (vč.
  admin úprav a lokálně laděného promptu), ne migrovaná data. Spolu se
  zachovanými backendy a klíči tak po resetu AI analýza funguje bez
  jakékoliv ruční akce.

Vše ostatní se dropuje, **včetně osiřelých tabulek** po odebraných modulech
(`dropSet` = existující tabulky − `keepSet`). Pokud se dropuje
`core_attachments_files`, vyčistí se i obsah `att/` a `cache/thumbnails/`
(prázdné adresáře `ds-upgrade` následně znovu zajistí).

**Nastavení s id dropnutých řádků** (`clearSettingsOnReset` v `module.jsonc`,
#131 D42): `core_system_settings` reset přežije, ale klíč, jehož hodnota je
id řádku dropované tabulky, by po `ds-upgrade` ukazoval na cizí řádek —
provisionery vyplněný klíč nepřepisují (řada „Majetek“ by nevznikla
a `economy.assets.accountingSeries` by mířilo na první řadu založenou
později). Reset proto takové klíče smaže hned po DROP a před delegací na
`ds-upgrade`, který je nastaví znovu; v reportu a v `--dry-run` je vypíše
jako `[clear] <klíč> (id in <tabulka>)`. Klíč, jehož tabulku drží `--keep`,
zůstává. Dnes: `economy.assets.accountingSeries` (economy.assets)
a `economy.vat.filingAccountingSeries` (economy.vat), obě
`docs_core_number_series`. Viz [docs/modules.md](modules.md) →
`clearSettingsOnReset`.

**Produkční pojistka (`enableReset`):** v `production` módu příkaz tvrdě
odmítne (`FAILURE`) bez jakéhokoliv dropu i DB spojení — je to záměrně
destruktivní vývojový/testovací nástroj. Výjimku má jen zdroj dat vědomě
označený jako testovací: volitelný boolean `"enableReset": true` v jeho
`config/main.json` guard pro tento konkrétní DS obchází. Při uplatnění příkaz
vypíše hlasitý warning (`resetting a PRODUCTION data source`); konfirmační
dotaz zůstává (obchází ho jen `--yes`, ne flag). Flag nikdy nenastavuj na DS
s ostrými daty — `shpd-server doctor` na něj na produkci upozorňuje. Žádný
`--force` neexistuje záměrně. V development módu se flag nečte.

**Třída dat v dotazu (#126 D11):** za řádkem `Data source: <název> (<id>)`
příkaz vypíše `Data class: free`, nebo `Data class: realCopy — <účel |
no purpose set>` — i s `--yes` a `--dry-run`. U kopie reálných dat
potvrzovací dotaz třídu zopakuje: `This data source is a REAL COPY
(purpose: …). Drop N table(s) and recreate? [y/N]`. Chování `--yes` se
nemění; reset třídu **nemění** (cíl importu zůstane `realCopy` i po
`ds-reset -y` a novém importu) — `free` nastavuje jen `dataset-seed`
po resetu a `shpd-ds ds-class`.

| Opce | Význam |
|------|--------|
| `--keep <tabulka>` | ad-hoc další tabulka k zachování (opakovatelné); aditivní k deklarativní `keepOnReset` |
| `--dry-run` | vypsat `[keep]`/`[drop]` a počty, nic neměnit |
| `--yes`, `-y` | přeskočit potvrzovací dotaz |

#### `ds-setting`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-setting list                                        # všechny uložené klíče
shpd-ds ds-setting get economy.accountChart                    # hodnota, exit 1 když klíč není
sudo shpd-ds ds-setting set economy.accountChart default       # nastavení
sudo shpd-ds ds-setting set economy.accountChart --unset       # smazání klíče
```

CLI přístup ke klíčům `core_system_settings` (scope ds, přes
`SettingsStore` — viz [docs/app-settings.md](app-settings.md)). Do
příchodu průvodce nastavením (ds-setup Fáze 4) je to **jediná cesta**
k parametrům vrstvy C ([docs/ds-setup.md](ds-setup.md) §5.2):

| Klíč | Hodnoty | Význam |
|------|---------|--------|
| `economy.accountChart` | `default` \| `npo` \| `none` | varianta účtové osnovy k naseedování |
| `economy.fiscalYearStartMonth` | 1–12 | první měsíc fiskálního roku (1 = leden) |
| `economy.vatAgenda` | `true` \| `false` | vede agendu DPH — předvolba (výchozí režim DPH nových dokladů, viditelnost agendy DPH v Nastavení), **ne** zdroj pravdy o plátcovství; tou jsou Registrace DPH |

**Absence klíče = nerozhodnuto** (D2): `ds-upgrade` bez rozhodnutí osnovu
ani fiskální roky neseeduje a na konci vypíše `[TODO]` blok s hotovými
příkazy k nastavení. `set($key, --unset)` klíč maže — vrací parametr do
nerozhodnutého stavu (existující naseedované řádky se **nemažou**,
provisionery neuklízí).

`set` drží **whitelist**: parametry vrstvy C + klíče deklarované
v `settingsPages` aktivních modulů (scope `ds`). Neznámý klíč → chyba
s výpisem povolených; hodnoty parametrů vrstvy C se validují při zápisu.
Klíče se strukturovanými hodnotami spravovanými aplikací (typy `image`,
`avatar`, `theme` — branding, vzhled) přes CLI nastavit nejdou.

#### `ds-state`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-state                                              # show: stav, maintenance, efektivní stav
sudo shpd-ds ds-state set read_only                           # lifecycle stav
sudo shpd-ds ds-state set pending_deletion --delete-after=2026-10-01   # + interaktivní potvrzení
sudo shpd-ds ds-state maintenance --on --reason=import        # dočasně zavřít (reason default manual)
sudo shpd-ds ds-state maintenance --off                       # znovu otevřít
sudo shpd-ds ds-state cron --off                              # vypnout periodické úlohy (kopie reálných dat)
sudo shpd-ds ds-state cron --on                               # zapnout zpět
```

Ovládání `config/state.json` — stavový model viz [ds-state.md](ds-state.md).
Dvě osy: lifecycle stav (`active` | `read_only` | `suspended` |
`pending_deletion`) a maintenance overlay (`import` | `restore` |
`migration` | `manual`). Aktivní maintenance má přednost — DS se chová
jako `suspended`; `--off` vrátí přesně uložený lifecycle stav. Třetí,
nezávislá osa je **přepínač periodických úloh** `cron`
([ds-state.md](ds-state.md) §8) — `set` ani `maintenance` ho nemění. Po
každém zápisu příkaz vypíše **efektivní stav**.

| Akce | Chování |
|------|---------|
| `show` (default) | lidsky čitelný výpis vč. řádku `Cron: on` / `off (periodic jobs are not running)`; poškozený `state.json` → exit 1 + nápověda k opravě (DS je fail-closed `suspended`) |
| `set <state>` | zapíše lifecycle stav (`changedBy: cli`). `pending_deletion` vyžaduje `--delete-after=<ISO 8601>` v budoucnosti a potvrzení (`--yes` / `-y` pro skripty); přechod jinam `deleteAfter` zahodí. Přepíše i poškozený soubor |
| `maintenance --on [--reason=<r>]` | zapne overlay; `since` se drží od prvního zapnutí i při změně reasonu |
| `maintenance --off` | vypne overlay; bez aktivního maintenance no-op (exit 0) |
| `cron --on` / `cron --off` | přepínač periodických úloh (#115 D14): zapíše `"cron": false` / klíč odstraní (`changedBy: cli`); DS už v cílovém stavu → „nothing to do", exit 0, soubor se nepřepisuje; bez volby nebo s oběma → chyba; poškozený soubor odmítá (oprava přes `set`). Hromadně přes `shpd-server ds-cron` |

Exit code: SUCCESS; FAILURE při nevalidním stavu/reasonu/datu, odmítnutém
potvrzení, selhání zápisu, nebo `show` nad poškozeným souborem.

Zápis pod `sudo` zachová vlastníka zdroje dat: `state.json` dostane
vlastníka a skupinu podle `config/main.json`, ne `root:root`
([ds-state.md](ds-state.md) §3). Když to nejde (běh pod jiným uživatelem
než root a než vlastník), příkaz skončí chybou s radou `chown` a původní
soubor zůstane. Soubor vzniklý starší verzí pod rootem hlásí `shpd-server
doctor` a opraví `sudo shpd-server fix-permissions`.

HTTP: `suspended` / maintenance / `pending_deletion` → 503 `DS_UNAVAILABLE`
+ `Retry-After: 300` (i pro `/_mail/incoming` — mail-router frontuje);
`read_only` → čtení běží, mutace 403 `DS_READ_ONLY`, příjem pošty
503, chat vypnutý ([ds-state.md](ds-state.md) §6). Na hostovaných DS bude
stav řídit hosting (`hosting-sync`, fáze 3); lokální CLI je pro nehostované
DS a nouzové zásahy.

#### `ds-class`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-class                                   # show (výchozí akce)
sudo shpd-ds ds-class set realCopy [--purpose="…"] # kdykoli — bezpečný směr
sudo shpd-ds ds-class set free [--purpose="…"]     # jen interaktivně, s potvrzením [y/N]
sudo shpd-ds ds-class set free --purpose=""        # na volném zdroji jen odebere účel
```

Třída dat zdroje dat v `config/main.json` (#126 D6–D9,
[`tasks/ds-data-class.md`](../tasks/ds-data-class.md)): `free` = ukázková,
seedovaná nebo testovací data (Claude s nimi smí pracovat bez schválení, na
vývojovém serveru dostane zdroj serverové AI připojení), `realCopy` = kopie
skutečných dat (import ze starého Shipardu, obnova zálohy). Třída **není
stav** zdroje dat ([ds-state.md](ds-state.md)) — chybějící nebo neplatná
hodnota zdroj nezavírá, platí jako `realCopy` (U6). Vedle třídy nese
`main.json` `purpose` (účel, jeden řádek, nejvýš 200 znaků), `origin` (kdo
třídu nastavil: `ds-create`, `ds-create --real-copy`, `dataset-seed <sada>`,
`ds-class`) a `dataClassChanged` (ISO 8601 v UTC); nezávisle na třídě
i `dataset` (`{name, hash, seededAt}` — která sada ve zdroji dat je, píše
jen `dataset-seed`, #103 F6b D59). Přehled přes všechny zdroje:
`shpd-server ds-list`.

- **`show`** — třída (u výchozí `not set in main.json — counts as realCopy`,
  u neplatné `invalid value "…" — counts as realCopy`), účel, původ, čas
  změny; v režimu `development` efektivní `Server AI:` (`on (free data
  source)` / `off (realCopy)` / `off (no server AI key — shpd-server
  dev-ai-key)`); `[WARN]`, má-li soubor ještě zaniklý `ai.useServerConnection`.
- **`set realCopy`** — bez potvrzení, kdykoli (i na zdroji bez třídy —
  udělá ji explicitní).
- **`set free`** na zdroji, který `free` není: vyžaduje **interaktivní
  terminál** — s `-n`, s přesměrovaným stdin (`echo y | …`), přes MCP most
  nebo z Claude Code skončí chybou `Marking a data source as free requires
  an interactive terminal — run the command yourself.` (exit 1, soubor beze
  změny; příkaz kontroluje i to, že stdin je terminál — Symfony sám vstup
  bez `-n` za neinteraktivní nepovažuje). Je to pojistka proti omylu, ne
  proti úmyslu: volné zdroje označuje vlastník stroje sám.
  Dotaz uvádí název zdroje, třídu a účel (`[y/N]`, výchozí ne); zamítnutí
  = `Aborted.`, exit 0. Volba `--yes` záměrně **neexistuje**.
- **Stejná třída** — třída, původ ani čas se nemění, zapíše se jen
  `--purpose` (je-li uvedené; prázdný řetězec účel odebere), výstup
  `unchanged`. `set free --purpose=…` na volném zdroji tedy nic nepotvrzuje.
- Zápis přes `MainConfigFile::patch` (atomicky, 0600, vlastník podle
  `config/` i pod rootem); první zápis třídy odebere zaniklý
  `ai.useServerConnection` (U8). Funguje v obou režimech serveru.

Exit 1: není adresář zdroje dat nebo nečitelný `main.json`, neznámá akce
nebo třída, neplatný účel, přechod na `free` bez terminálu, chyba zápisu.

#### `doc-reaccount <docId> [--force]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds doc-reaccount 4711            # přegeneruje deník dokladu ve stavu 40
shpd-ds doc-reaccount 4711 --force    # i přes zámek období — zaloguje se
```

Totéž co `POST /_accounting/reaccount` (akce **Přeúčtovat** v detailu
dokladu). Doklad mimo stav 40 → FAILURE. Zamčený doklad (zamčená instance
tvrzení DPH nebo fiskální měsíc, `documentLockProviders`) příkaz odmítne
s výčtem důvodů; `--force` zámek vědomě obejde a zapíše `warn` do
`shipard.log` (`document lock bypassed by force (doc-reaccount)`). Deník je
derivát dokladu — force slouží k opravě rozvrhu nebo předpisu nad uzavřeným
obdobím, obsah dokladu se nemění. Viz [accounting.md](accounting.md) §7.6.

#### `assets-import-verify [--asset=<číslo>] [--json]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds assets-import-verify                 # celý zdroj dat, text
shpd-ds assets-import-verify --asset MA0012  # jedna karta (kontrola po letech se vynechá)
shpd-ds assets-import-verify --json          # pro log runneru
```

Ověření importu majetku (`docs/assets.md` D82, §5.7) — čte, nic nemění:

1. **Zlatý test daňového okruhu:** u každého importovaného daňového odpisu
   hodnota, kterou engine spočítal z historie před ním, proti importované
   částce (karta, rok, import, engine, rozdíl); karta se zablokovaným
   okruhem je „chyba plánu“.
2. **Účetní okruh × deník:** per karta a účetní rok součet účetních odpisů
   z událostí × obrat účtů odpisů s dimenzí karty v deníku — od prvního
   roku, kdy deník kartu na účtech odpisů nese (`summary.accFromYear`);
   dřívější roky se neporovnávají.
3. **Kontrola evidence × deník** za každý účetní rok od prvního s dimenzí
   `asset` v deníku do roku dnešního data (další rok ještě nemá počáteční
   stavy): stav a počty zpráv po kódech.
4. **Uplynulá doba účetního odpisování:** karty, jejichž plán by u časové
   účetní metody odepsal zůstatek v jednom období (konec doby, částka
   nejbližšího plánovaného období) — upozornění s vlastním počtem
   v souhrnu, oprava je delší doba na kartě.

Exit 0 bez rozdílů, 1 s rozdíly nebo chybou plánu nebo rokem kontroly ve
stavu `errors` (varování a uplynulá doba neshazují); FAILURE i při chybě
prostředí.

#### `work-orders-invoice-run [--date=YYYY-MM-DD] [--work-order=<číslo>] [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds work-orders-invoice-run --dry-run                   # která období by se vystavila a jaké doklady
shpd-ds work-orders-invoice-run                             # vystaví koncepty za splatná období (denní cron)
shpd-ds work-orders-invoice-run --date=2026-11-01 --dry-run # simulace běhu k datu
shpd-ds work-orders-invoice-run --work-order=S260001        # jedna zakázka — i přes pojistku dohánění
```

Běh periodické fakturace ([work-orders.md](work-orders.md) §5.5, #110 D5):
pro každou periodickou zakázku **V pořádku** s „fakturovat od“ založí
chybějící splatná období (den fakturace = počátek / konec období ≤ datum
běhu) a vystaví je jako **koncepty** faktur nebo zálohových faktur
vydaných přes výměnný formát (`DocumentApplier`, Q1). Založení dokladu
a zápis do evidence období jsou jedna transakce; `issued` období se
nikdy nevystaví podruhé (idempotentní, smazaný koncept = zastaveno).
Víc než 3 dlužná období jedné zakázky běh nevystaví (pojistka dohánění,
Q4) — vystaví je `--work-order` nebo akce *Vystavit dlužná období*
v detailu zakázky. Chyba jednoho období (chybějící řada, validace
dokladu, žádný platný řádek) zastaví jen to období; důvod zůstane
v evidenci a hlásí ho upozornění. Výstup: řádek per zakázka × období
(vystaveno / čeká na podklady / selhalo / pojistka) a souhrn. Na DS bez
modulu zakázek rychlý exit 0.

Exit 0; 1 když se některé období nepodařilo vystavit; INVALID při špatném
datu nebo neznámém čísle zakázky.

| Opce | Význam |
|------|--------|
| `--date=YYYY-MM-DD` | datum běhu (splatnost období); výchozí dnešek |
| `--work-order=<číslo>` | jen tato zakázka, bez pojistky dohánění |
| `--dry-run` | jen výčet, nic se nezapíše (ani období se nezaloží) |

#### `exchange-rates-import <soubor|složka> [--replace]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds exchange-rates-import kurzy/2026-10-16-cnb-daily.jsonc   # jeden lístek
shpd-ds exchange-rates-import kurzy/                              # všechny .jsonc / .json ve složce, podle jména
shpd-ds exchange-rates-import kurzy/ --replace                    # lístky s jiným obsahem přepsat
```

Nahraje kurzovní lístky ve formátu `shpd.exchangeRates.list.v1`
([exchange-rates.md](exchange-rates.md) §2, #121 D2) — jeden soubor = jeden
lístek — přes `ExchangeRateListImporter` se zdrojem *soubor*. Importer
ověří schéma i sémantiku (typ lístku známý pro zemi zdroje dat
z `world.<země>.exchangeRateLists`, domácí měna lístku = měna typu = domácí
měna zdroje dat, žádná měna dvakrát, `validTo` ≥ `validFrom`, typ bez
`maxAgeDays` vyžaduje `validTo`) a uloží hlavičku s hodnotami v jedné
transakci. Měnu mimo `world.base.currencies` (např. XDR v denním lístku
ČNB) z lístku vyřadí a vypíše jako *přeskočené měny* u souboru — výsledek
zůstává ok (task B U1); lístek bez jediné známé měny je neplatný. Lístek
téhož typu a data platnosti, který už existuje: shodný obsah (porovnaný po
vyřazení neznámých měn) = *beze změny*, jiný obsah = *jiný obsah* (chyba)
— přepíše ho jen `--replace`. Výstup: řádek per soubor s výsledkem
a chybami, souhrn založeno / beze změny / nahrazeno / selhalo. Bez sítě —
stahování ze služby dělá `exchange-rates-download`.

Exit 0; 1 při jakémkoli selhání (neplatný lístek, jiný obsah bez
`--replace`, chyba zápisu, chybějící modul `economy.exchangeRates`);
INVALID při neexistující cestě nebo složce bez souborů.

| Opce | Význam |
|------|--------|
| `--replace` | existující lístek s jiným obsahem nahradit (hodnoty v jedné transakci) |

#### `exchange-rate <měna> [<datum>]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds exchange-rate eur               # kurz EUR k dnešku
shpd-ds exchange-rate eur 2026-10-18    # kurz k datu (sobota → páteční lístek)
```

Vypíše kurz měny k datu tak, jak ho dohledá `ExchangeRateResolver`
([exchange-rates.md](exchange-rates.md) §4, #121 D4): kurz za jednotku,
typ lístku, platnost od a číslo lístku; domácí měna = kurz 1. Když kurz
nenajde, vypíše důvod per typ lístku: žádný lístek k datu, poslední lístek
starší než `maxAgeDays` typu (U1), lístek po `validTo`, měna v posledním
lístku není (starší se nezkouší, U2). Slouží k ověření a podpoře.

Exit 0 nalezen; 1 nenalezen nebo chybějící modul; INVALID při špatné měně
nebo datu.

#### `exchange-rates-download [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--type=<id>] [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds exchange-rates-download --dry-run                       # okno, chybějící data a dotazy; bez sítě a zápisu
shpd-ds exchange-rates-download                                 # běh jako z cronu: nejvýš 6 dotazů, pauza 1 h na měsíc
shpd-ds exchange-rates-download --from=2026-01-01               # ruční doplnění od data po dnešek, bez limitu a pauzy
shpd-ds exchange-rates-download --type=cz.cnb.other --dry-run   # jen jeden typ
```

Stáhne chybějící kurzovní lístky ze služby
([exchange-rates.md](exchange-rates.md) §9, #121 D2, D3): per typ
s `download` spočítá očekávaná data (pracovní dny kalendáře po dnešek
v Europe/Prague — dnešní lístek až po 14:35; měsíční 1. den měsíce)
v okně od začátku předchozího fiskálního roku, srovná je s přítomnými
lístky a chybějící stáhne po kalendářních měsících od nejnovějšího.
**Nic nechybí = žádná síť, žádný zápis, žádný log** — proto smí běžet
každých 5 minut (slot `five-minutes`, před `alerts-run`). Lístky zapisuje
přes `ExchangeRateListImporter` se zdrojem *služba*; lístek k datu, které
typ už má, ignoruje bez porovnání, konflikt (jen při souběhu) nepřepisuje.
Adresa služby = `exchangeRates.serviceUrl` v `server.json`; prázdný
řetězec stahování vypne (zpráva, exit 0). Neaktivní modul = zpráva, exit 0.
Stav pokusů (pauza 1 h na měsíc, hodina po chybě služby) žije v nastavení
`economy.exchangeRates.downloadState`; ruční okno (`--from` / `--to`) ho
nečte ani nepíše.

Exit 0; 1 při chybě služby nebo sítě (běh skončí, další běhy hodinu
čekají), při konfliktu nebo selhání zápisu; INVALID při špatném datu
(`--to` nejvýš dnešek, `--from` ≤ `--to`) nebo neznámém `--type`.

| Opce | Význam |
|------|--------|
| `--from=YYYY-MM-DD` | začátek okna místo předchozího fiskálního roku; ruční mód bez limitu, pauzy a stavu |
| `--to=YYYY-MM-DD` | konec okna (výchozí a nejvýš dnešek); ruční mód jako `--from` |
| `--type=<id>` | jen jeden typ lístku (`cz.cnb.daily`, `cz.cnb.other`) |
| `--dry-run` | vypíše okno, per typ očekáváno / přítomno / chybí, varování a dotazy (pozastavené, nad limit, jen dnešek); žádná síť ani zápis |

#### `accbal-match --all | --partner=<id> | --fiscal-year=<id> [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds accbal-match --all --dry-run       # plán: které clearingové úhrady by šly na 311/321
shpd-ds accbal-match --all                 # přeúčtuje je (reaccount transakce → re-derivace salda)
shpd-ds accbal-match --partner=42          # jen úhrady partnera
```

Dávkové přeúčtování bankovních úhrad z clearingu 261200/261300 na účet
otevřeného předpisu (#69 D4) — totéž co `POST /_accbal/match`. Pro každou
úhradu na clearingu dohledá otevřený předpis pro klíč partner + VS + SS +
měna (`OpenItemLookup`); bez partnera nebo bez zásahu úhradu přeskočí
(důvody `no_partner` / `no_open_item` / `engine_error` v souhrnu). Běžně
není třeba: engine routuje už při zaúčtování transakce a po zaúčtování
předpisu čekající úhrady přeúčtuje trigger; dávka slouží importu a
dorovnání. Bez `--all` ani filtru příkaz nic neudělá. Viz
[accbal.md](accbal.md) §5.7, [bank.md](bank.md) §6.1.

#### `accbal-regenerate --all | --doc=<id> | --fiscal-year=<id> [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds accbal-regenerate --all --dry-run     # kolik pohybů by se vložilo / aktualizovalo / smazalo
shpd-ds accbal-regenerate --all               # přegeneruje saldo pohyby všech zdrojů
shpd-ds accbal-regenerate --doc=4711 -v       # jeden doklad, -v vypíše zdroje se změnou
shpd-ds accbal-regenerate --fiscal-year=7     # jen zdroje daného fiskálního roku (id)
```

Hromadná re-derivace saldo pohybů (`economy_accbal_ledger`) z účetního
deníku — totéž, co po každém (pře)zápisu deníku dělá handler události
`journalWritten`, jen dávkově přes všechny zdroje. Zdroje = sjednocení
zdrojů deníku a ledgeru, takže osiřelý pohyb bez deníku se smaže. Per
zdroj idempotentní UPSERT podle `movement_key` (#69 D13); `id` pohybů se
stejným klíčem zůstávají, pohyby z doby před D13 (klíč `NULL`) se nahradí
novými. Nevysílá `journalWritten` — deník se nemění; přeúčtování clearingu
je věc `accbal-match`. Použití: po změně generátoru, po `ds-upgrade`
s novým klíčem pohybu (postup nasazení v [accbal.md](accbal.md) §4.6),
při podezření na rozjetý ledger. Bez `--all` / `--doc` / `--fiscal-year`
příkaz nic neudělá. Na importovaném DS běží nízké desítky sekund.

#### `values-normalize [--dry-run] [--json] [--only=<druhy>] [-v]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds values-normalize --dry-run     # co by se změnilo a co zůstane nevalidní; nic se nezapíše
shpd-ds values-normalize               # ostrý běh — jedna transakce, jen změněné sloupce
shpd-ds values-normalize --json        # tentýž report jako JSON (do issue, pro skript)
shpd-ds values-normalize --dry-run -v  # každá změna a nález s osobou a hodnotami — jen na obrazovku
shpd-ds values-normalize --only=phone  # ostrý běh jen pro telefony (postupně po druzích)
```

Jednorázová, opakovatelná normalizace existujících údajů adresáře (#118
D22, `tasks/value-rules-backfill.md`): osoby (`company_id`, `vat_id`,
`tax_id`, `phone`, `email`, `web`), adresy (`zip`, `country`), kontakty
(`email`, `phone`), účty osob a vlastní účty (`account_number`, `iban`,
`bic`) převede do kanonického tvaru **týmiž funkcemi jako uložení**
(`PersonValues`, `BankAccountShape` — `docs/exchange-format-persons.md`
§ 5–7), včetně přesunu IBAN ze sloupce čísla účtu do `iban` (D20). Země
osoby podle uložených adres (sídlo → první adresa se zemí → země zdroje
dat); adresa bez země dostane pro PSČ zemi osoby, `country` se nedoplňuje
(D24). Tabulka vlastních účtů jen s aktivním modulem `economy.codebooks`
(jinak se přeskočí a vypíše).

Zapisuje jen změněné řádky a jen dotčené sloupce přímým UPDATE — bez
změny stavu, bez tříd dokumentů a událostí; celý běh v jedné transakci
(chyba = nic), dávky po 1000 řádcích přes `id`. Idempotentní: druhý běh
hlásí 0 změn. **Nemění** doklady a jejich snapshoty stran, `display_line`
/ `display_block` adres, pravidla obsahových štítků, bankovní výpisy ani
historii. Nevalidní hodnotu (kódy D13: `company_id_invalid`,
`zip_invalid`, `iban_invalid`, …) ani podezření `account_number_checksum`
(číslo účtu bez mod 11, D30) neopravuje — jen je vypíše k ruční opravě
či ověření.

Výpis: řádek per tabulka (záznamů, změněno, po sloupcích, u účtů „IBAN
přesunut z čísla účtu“), součet a nevalidní hodnoty po opravě tvaru jako
`tabulka · id · sloupec · kód` — bez hodnot, jmen ani adres, takže výstup
smí do issue. `--json`: `{dryRun, only?, verbose?, tables: {<tabulka>:
{rows, changed, columns, ibanMoved?, skipped?}}, changes?, invalid:
[{table, id, column, code}], totals: {rows, changed, invalid}}`. Exit 0
i s nevalidními hodnotami; nenulový jen chyba volání nebo infrastruktury.

**Postupný běh `--only=<druhy>`** (#118 D27, čárkami): `phone`, `email`,
`web`, `zip` (PSČ a země adresy), `ids` (IČO, DIČ, daňové číslo),
`accounts` (číslo účtu, IBAN, BIC včetně přesunu IBAN). Ostatní sloupce
se v běhu nemění ani nepočítají, nálezy jen pro vybrané druhy; tabulka bez
vybraného sloupce se vypíše jako `přeskočeno — mimo --only`
(`skipped: not_selected`). Neznámý druh = chyba volání (exit 2), nic se
nespustí. Bez `--only` všechno.

**Podrobný výpis `-v` / `--verbose`** (vestavěná volba Symfony Console):
za součty sekce `Změny (N):` — u každé změny `tabulka · id · osoba <id>
<kód> „<název>“` (u vlastního účtu `účet <kód> „<název>“`) a sloupce
`„před“ → „po“`; přesun IBAN ze sloupce čísla účtu je jedna položka se
všemi dotčenými sloupci s předponou `IBAN přesunut z čísla účtu:`.
U nálezů navíc hodnota, srozumitelný důvod podle příčiny (u IBAN tvar /
kontrolní číslice / chybná délka, u čísla účtu nepovolené znaky /
chybějící kód banky / samé nuly; podezření `account_number_checksum`
„neprojde kontrolou mod 11 — ověřte ho“, D30) a, kde to jde
z redundance, `návrh: „…“` — IČO z osmimístného českého DIČ, IBAN
z tuzemského čísla účtu, které projde vším včetně mod 11, a naopak;
podezření návrh nenese. **Návrh se nikdy neprovede**,
ani v ostrém běhu; oprava je na člověku. V JSONu `changes: [{table, id,
person: {id, code, name}|null, account: {code, name}|null, ibanMoved,
columns: {<sloupec>: {from, to}}}]` a `invalid[]` navíc `person`,
`account`, `value`, `reason`, `suggestion`. **Podrobný výpis obsahuje
osobní údaje — jen na obrazovku, nikdy do issue, tasku ani commitu.**
Bez `-v` je výstup beze změny.

**Kopie reálných dat:** ostrý běh pouští člověk po `--dry-run` (Claude
Code tam smí jen `--dry-run`). Párování (`PartyResolver`,
`BankAccountResolver`, import výpisů) funguje před i po běhu stejně —
páruje přes normalizované tvary na obou stranách.

#### `vat-filing-account <filingId> [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds vat-filing-account 33 --dry-run   # řádky účetního dokladu přiznání bez zápisu
shpd-ds vat-filing-account 33             # založí cmnbkp (koncept) a naváže ho na podání
```

Totéž co `POST /_vat/filing-account` (akce **Zaúčtovat** v detailu podání
DPH, #55 D28–D31). Zapisuje jen nad **podaným** podáním typu přiznání;
`--dry-run` vypíše plán (účet, strana, částka, popis, partner, VS/SS/KS,
splatnost) i nad konceptem podání — tak se porovnává s doklady starého
systému. Živý účetní doklad na podání → `ALREADY_ACCOUNTED` (nejdřív
storno); chybějící účet v rozvrhu nebo zbytek mimo toleranci zaokrouhlení
→ `PLAN_FAILED`, doklad nevznikne; zamčený fiskální měsíc konce období →
`SAVE_FAILED` s důvodem zámku. Řadu dokladu určuje parametr vrstvy C
`economy.vat.filingAccountingSeries` (`ds-setting set … <id řady cmnbkp>`),
bez něj jen jediná aktivní řada typu. Viz README modulu `economy.vat` →
Zaúčtování přiznání.

#### `vat-filing-import --period=<id> --type=return|cs|rs [--kind=…] [--xml=<soubor>] [--attach=<soubor>]… [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds vat-filing-import --period=140 --type=return --xml=podano-2019-08.xml --dry-run
shpd-ds vat-filing-import --period=140 --type=return --xml=podano-2019-08.xml \
  --name="Přiznání DPH 2019/8" --date-filed=2019-09-22 --acc-document=36950 --attach=opis.pdf
shpd-ds vat-filing-import --period=141 --type=return --kind=supplementary \
  --xml=dodatecne.xml --date-found=2019-10-01
```

Ruční doplnění jednoho **starého podání** (papírové, jiný systém) — totéž
co `POST /_vat/filing-import` + přílohy + `POST /_vat/filing-import-finish`
(#55 D21, D32–D39). Založí podání s původem *Importováno* (snapshot sestaví
composer nad dnešními doklady), u přiznání přepíše **podané hodnoty**
řádků z XML (rozdíly proti zaokrouhleným přesným vypíše jako tabulku
Řádek / Sloupec / Sestaveno / Podáno), u hlášení řádky writeru s XML jen
porovná (Sekce / Klíč / Pole). XML i `--attach` soubory nahraje jako
původní přílohy a podání převede do stavu Podáno — soubory se
**negenerují**, původní jsou pravda. `--dry-run` totéž v transakci
s rollbackem (nic nezůstane) — nástroj pro D39 nad `btpg-p`.

Odmítnutí bez zápisu: `PERIOD_NOT_FOUND` (instance chybí nebo má jiný
typ), `INVALID_KIND`, `DATE_FOUND_REQUIRED` (dodatečné / následné bez
`--date-found` a bez `d_zjist` v XML), `PREVIOUS_FILING_MISSING` (dodatečné
bez podaného základu — nejdřív řádné), `DRAFT_EXISTS` (živý koncept
v instanci — dokončete přes finish, nebo ho zrušte), `XML_UNREADABLE`,
`XML_TYPE_MISMATCH`. Chybějící účetní doklad je jen varování (FK zůstane
prázdné, doplní se akcí Zaúčtovat). Selhání nahrání přílohy nechá podání
jako koncept — dokončete přes `POST /_vat/filing-import-finish`. Viz README
modulu `economy.vat` → Import starých podání.

#### `report-run <reportId> … [--param id=hodnota]… [--format=json|xlsx|csv] [--output=<soubor>]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds report-run economy.accounting.generalLedger \
    --fiscal-year 2026 --month-from 5 --month-to 5 > hk.json          # ReportResult jako JSON
shpd-ds report-run economy.accounting.profitLoss \
    --fiscal-year 2026 --month-from 1 --month-to 12 --detail synthetic --pretty
shpd-ds report-run economy.vat.controlStatementLive --period 141       # report s obdobím DPH
shpd-ds report-run economy.accounting.balanceSheet \
    --fiscal-year 2026 --month-from 1 --month-to 12 \
    --format=xlsx --output=rozvaha-2026.xlsx                           # export do sešitu
shpd-ds report-run economy.accounting.generalLedger \
    --fiscal-year 2026 --month-from 5 --month-to 5 --format=csv > hk.csv
```

Spustí report a vypíše výsledek. Období se zadává podle zdroje období
reportu: fiskální reporty `--fiscal-year` (název roku) + `--month-from` /
`--month-to` (pořadí běžného měsíce v roce), reporty DPH `--period` (id
instance daňového tvrzení). `--detail analytic|synthetic` jen u reportů,
které ho deklarují (default `analytic`). Ostatní parametry reportu jdou
obecně přes opakovatelné `--param id=hodnota` (např.
`--param groupBy=type --param category=all`); nabídku a výchozí hodnoty
určuje deklarace reportu, neznámý parametr nebo hodnota končí chybou.

| `--format` | Výstup |
|---|---|
| `json` (default) | `ReportResult` jako čistý JSON na stdout (`--pretty` = odsazený) — vstup pro `report-diff` a skripty |
| `csv` | tabulka reportu: UTF-8 s BOM, `;`, desetinná čárka; bez `--output` na stdout |
| `xlsx` | sešit s úvodním blokem, tabulkou a listem zpráv; **vyžaduje `--output`** |

`--output=<soubor>` zapíše výstup do souboru (jde i s `json` a `csv`).
Výsledek se `status: errors|warnings` je legitimní výstup — poznámka jde na
stderr, exit 0. Exit `INVALID` (2): neznámý report (vypíše dostupné),
chybějící / nevalidní období, neznámý `--format`, `xlsx` bez `--output`.
Pravidla převodu: [reports.md](reports.md) §15.

#### `print-run <printId> [<recordId>] [--format=json|pdf|html] [--language=cs|en|sk|de] [--output=<cíl>] [--data=<PrintData.json>]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds print-run docs.invoicesOut.invoice 123                       # PrintData jako JSON
shpd-ds print-run docs.invoicesOut.invoice 123 --language=en > faktura.json
shpd-ds print-run docs.proformasOut.proforma 456 \
    --format=pdf --output=zalohova-faktura.pdf                        # PDF přes render službu
shpd-ds print-run docs.invoicesOut.invoice 123 \
    --format=html --output=/tmp/faktura                               # HTML do adresáře
shpd-ds print-run docs.invoicesOut.invoice --data=faktura.json \
    --format=html --output=/tmp/faktura                               # render hotových dat bez databáze
```

Spustí tisk nad jedním záznamem ([prints.md](prints.md)). `recordId` je id
záznamu v tabulce, kterou určuje deklarace tisku.

| `--format` | Výstup |
|---|---|
| `json` (default) | `PrintData` jako odsazený JSON na stdout — ladění builderu a kontraktu bez renderu |
| `pdf` | hotové PDF; **vyžaduje `--output`** a nakonfigurovanou render službu (`render` v `server.json`) |
| `html` | přesně to, co jde do render služby; **vyžaduje `--output=<adresář>`**: `index.html` (stránka), `header.html`, `footer.html` a assety (CSS, logo) pod svými jmény. Adresář se vytvoří, soubory stejného jména se přepíší, jiné zůstanou. Render službu nepotřebuje |

`index.html` jde otevřít přímo v prohlížeči (assety odkazuje relativně).
Záhlaví a zápatí jsou samostatné dokumenty — do okrajů stránky je skládá až
render služba, v prohlížeči se u stránky nezobrazí.

**`--data=<soubor>`** vezme hotový `PrintData` (výstup `--format=json` nebo
fixture z `tests/Fixtures/Prints/`) a přeskočí databázi, kontrolu
dostupnosti i builder — rovnou renderuje `html` nebo `pdf`. `recordId` se
nezadává. `printId` musí souhlasit s `printId` v souboru a verze dat nesmí
být vyšší, než builder tisku zná. Jazyk se bere ze souboru, `--language` ho
přebije (překlady šablony; popisky v `data` zůstávají, jak jsou). Slouží
vývoji šablon: jednou `--format=json > data.json`, pak už jen upravovat
šablonu a pouštět `--data=data.json --format=html`.

`--language` určí jazyk tisku; bez něj se volí podle partnera dokladu
(jazyk osoby, jinak země z adresy na dokladu, jinak vlastní země zdroje
dat — `docs/prints.md` §3). Interní tisky partnera neřeší.
Měkká hlášení builderu (QR platba nevznikla) jdou na stderr, exit 0.
Exit `INVALID` (2): neznámý tisk (vypíše dostupné), neznámý `--format` /
`--language`, `pdf` nebo `html` bez `--output`, chybějící či nečíselné
`recordId`, `--data` s `recordId` nebo s `--format=json`, nečitelný soubor
`--data`, data jiného tisku nebo s neplatnou obálkou. Exit `FAILURE`
(1): záznam neexistuje, tisk pro něj není dostupný (koncept, jiný typ
záznamu), záznamu chybí data pro tisk, výstup nejde zapsat, nebo PDF
nevzniklo (druh selhání render služby je ve výpisu).

#### `print-send <printId> <recordId> [--to=<adresa>]… [--cc=<adresa>]… [--from=<adresa>] [--language=cs|en|sk|de] [--dry-run]`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds print-send docs.invoicesOut.invoice 123 --dry-run             # návrh jako JSON, nic nevznikne
shpd-ds print-send docs.invoicesOut.invoice 123 --to=ja@firma.example # zpráva + řádek fronty
shpd-ds print-send docs.invoicesOut.invoice 123                       # příjemci z kontaktů partnera
shpd-ds print-send docs.invoicesOut.invoice 123 \
    --to=ucetni@odberatel.example --cc=obchod@firma.example --language=en
```

Odešle záznam e-mailem ([prints.md](prints.md) §9): vytvoří zprávu
v Odeslané poště s PDF tisku v přílohách a zařadí ji do fronty odchozí
pošty — odešle ji worker `mail-outbox-run`. Odeslat jde jen tisk
s deklarovaným účelem (`sendPurpose`).

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše návrh odeslání (JSON na stdout): příjemce s důvody, odesílatele, předmět, text, přílohy a hlášení. Nic nevytvoří |
| `--to <addr>` | Příjemce; lze opakovat. Bez něj jde zpráva na adresy z kontaktů partnera — ty, které ukáže `--dry-run`. Povinné jen na neprodukčním serveru s vypnutou pojistkou pošty |
| `--cc <addr>` | Kopie; lze opakovat |
| `--from <addr>` | Adresa odesílatele — jedna z povolených; výchozí podle číselné řady, jinak `mail.defaultFrom` |
| `--language <kód>` | Jazyk zprávy i PDF; výchozí podle partnera |

**Kdy je `--to` povinné:** zdroj dat neví, jestli nese ostrá data, nebo
jejich kopii. Chrání ho pojistka odchozí pošty na úrovni serveru
(`mail.safety`, [mail/outbound.md](mail/outbound.md) § Pojistka): se
zapnutou pojistkou zpráva skutečným příjemcům nedojde, na produkčním
serveru jim dojít má. `--to` proto příkaz vyžaduje jen tam, kde by pošta
z kopie dat odešla nechtěně — na neprodukčním serveru s pojistkou `off`.

Výstup: `Sent message #<id>: outbox #<id>, transport '<stav>'`; na
serveru se zapnutou pojistkou i řádek `Mail safety: <režim> — …`, který
říká, kam zpráva (ne)půjde, až ji fronta odešle. Měkká hlášení tisku na
stderr. Exit `INVALID` (2): neznámý tisk, nečíselné
`recordId`, neplatný jazyk, chybějící `--to`. Exit `FAILURE` (1): tisk
nejde odeslat nebo není pro záznam dostupný, chybí odesílatel, neplatná
adresa (`<KÓD>: hláška`), PDF nevzniklo.

#### `report-diff <fileA> <fileB> [--strict] [--json]`

```bash
shpd-ds report-diff old.json new.json            # lidský výpis rozdílů
shpd-ds report-run … | shpd-ds report-diff - old.json --json
```

Porovná dva `ReportResult` JSON soubory (`-` = stdin pro jednu stranu) —
kontrola importu ze starého Shipardu. Porovnává detailní řádky podle účtu
a součty podle popisku, sloupce v průniku dle `id`, tolerance 0,005.
Exit 0 shoda, 1 rozdíly, 2 chyba vstupu; `--strict` odmítne stranu se
`status: errors`. Viz [reports.md](reports.md) §7.4 a §13.

### Users

#### `user-create`

```bash
sudo shpd-ds user-create --login=jan --name="Jan Novák" --email=jan@example.com
printf '%s\n' "$ADMIN_PW" | sudo shpd-ds user-create --login=admin --password-stdin --name="Admin" --admin
```

Vytvoří nového uživatele v DS. **Doporučení:** heslo nezadávat — účet
vznikne bez lokálního hesla (NULL hash) a uživatel si ho nastaví sám přes
pozvánku (akce „Poslat pozvánku“ v detailu uživatele v Nastavení, nebo
`POST /_users/{id}/invite`). Heslo tak nikdy neputuje mimo pásmo. Viz
`docs/auth.md`. Když heslo zadat musíš (skript, vývojářský dashboard),
pošli ho na stdin přes `--password-stdin` — `--password` ho nechává
v argv, shell history a výpisu procesů (#103 D65).

| Opce | Význam |
|------|--------|
| `--login <login>` | **povinné** — login |
| `--password-stdin` | volitelné — heslo z **prvního řádku stdin** (konec řádku se odřízne); prázdný nebo žádný řádek = chyba, s `--password` zároveň = chyba. Formulář Nový zdroj dat ho používá vždy |
| `--password <heslo>` | volitelné — heslo na příkazové řádce (raději `--password-stdin`); bez obou účet čeká na pozvánku |
| `--name <jméno>` | **povinné** — celé jméno |
| `--email <email>` | volitelné — e-mailová adresa (pro pozvánku nutná) |
| `--admin` | volitelné — založit rovnou s administrátorskými právy (`is_admin = 1`) |
| `--if-not-exists` | volitelné — existující login není chyba (info + exit 0, pokračuje se případným propojením identity). Pro idempotentní provisioning (agent `hosting-sync`). |
| `--identity-issuer <url>` | volitelné (jen s `--identity-subject`) — po založení/nalezení uživatele zajistí řádek `core_system_user_identities` `(issuer, subject)` → user. Existující vazba na téhož uživatele = no-op; na **jiného** uživatele = chyba. Issuer se ukládá bez trailing slash. |
| `--identity-subject <sub>` | volitelné (jen s `--identity-issuer`) — OIDC subject identity |
| `--identity-provider <id>` | volitelné (default `oidc`) — hodnota sloupce `provider` identity; agent posílá `shipard-id` (shodné s `auth.providers[].id`) |

#### `user-set-admin`

```bash
sudo shpd-ds user-set-admin --login=admin
sudo shpd-ds user-set-admin --login=alice --revoke
```

Nastaví nebo odebere `is_admin` existujícímu uživateli. Admin je nutný pro
práci se systémovými tabulkami (`core_system_*`) přes API i UI — po nasazení
na existující DS je potřeba tento příkaz spustit pro adminské účty, jinak
sekce Systém v Nastavení zmizí všem.

| Opce | Význam |
|------|--------|
| `--login <login>` | **povinné** — login uživatele |
| `--revoke` | odebrat práva místo udělení |

Pojistka: `--revoke` odmítne odebrat práva poslednímu **aktivnímu** adminovi
DS (ochrana proti zamčení). Neaktivnímu adminovi lze práva odebrat vždy.

### API keys

Generické příkazy pro správu API klíčů libovolného uživatele. Pro
role-specifický bootstrap mail-routeru viz sekce níže — je samostatný
a tyto generické příkazy ho nenahrazují.

Klíč žije v `core_system_api_keys`: ukládá se jen SHA-256 hash + 12-znakový
`key_prefix` pro lookup. Plaintext token (`shpd_ak_` + 32 hex chars) se
zobrazí pouze jednou, ihned po vytvoření. Revoke nemaže row — jen nastaví
`is_active = 0`, kvůli auditu.

#### `api-key-create`

```bash
shpd-ds api-key-create --user=alice --name=import-from-old-shipard
shpd-ds api-key-create --user=alice --name=ci-bot --ip=10.0.0.5 --ip=10.0.0.6
shpd-ds api-key-create --user=5 --name=temp --expires=+30d
shpd-ds api-key-create --user=alice@example.com --name=k --expires="2026-12-31 23:59:59"
```

Vygeneruje nový API klíč pro existujícího uživatele. Plaintext token vypíše
ve výstupu — zachyť ho ihned, podruhé už k němu nemáš přístup.

| Opce | Význam |
|------|--------|
| `--user <login\|email\|id>` | **povinné** — uživatel. Ambiguous match (login a email se prolnuly) → chyba, použij `--user=<id>`. |
| `--name <text>` | **povinné** — lidsky čitelný popisek (max 100 znaků). Není unique. |
| `--ip <addr>` | volitelné, lze opakovat nebo dát comma-separated (`--ip=1.2.3.4,5.6.7.8`). Bez opce = bez IP restrikce. |
| `--expires <date>` | volitelné — datum/čas expirace. Akceptuje `YYYY-MM-DD`, `YYYY-MM-DD HH:MM:SS`, relative `+30d` / `+1y`, nebo cokoli, co umí PHP `DateTimeImmutable`. Bez opce = bez expirace. |

#### `api-key-list`

```bash
shpd-ds api-key-list                          # všichni useři, jen aktivní
shpd-ds api-key-list --user=alice             # filter per uživatel
shpd-ds api-key-list --include-inactive       # včetně revokovaných
```

Tabulkový výpis API klíčů. Plaintext se nikdy nezobrazí — jen prefix.
Allowed IPs nejsou v listu (sloupec by byl moc široký).

| Opce | Význam |
|------|--------|
| `--user <login\|email\|id>` | Filtrovat jen klíče tohoto uživatele. |
| `--include-inactive` | Zobrazit i revokované klíče (jinak filtr `is_active = 1`). |

#### `api-key-revoke`

```bash
shpd-ds api-key-revoke --id=42                # interaktivní potvrzení
shpd-ds api-key-revoke --id=42 --yes          # bez potvrzení
shpd-ds api-key-revoke --prefix=aabbccdd1122  # identifikace přes prefix
```

Deaktivuje klíč (`is_active = 0`). Idempotentní — opakované volání na už
revokovaný klíč vrátí success bez změny. Identifikace přes `--id`
(preferované) nebo `--prefix` (přesných 12 znaků); přesně jedno z těch dvou.

| Opce | Význam |
|------|--------|
| `--id <N>` | Numerické ID klíče (`core_system_api_keys.id`). |
| `--prefix <12chars>` | Prefix. Pokud sdílí prefix víc klíčů (vzácné), command vyzve k `--id`. |
| `--yes`, `-y` | Skip interaktivního potvrzení. |

### Secrets

#### `ds-secret`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-secret                                      # list (výchozí akce)
sudo shpd-ds ds-secret set mail-relay.password         # skrytý dotaz v terminálu
sudo shpd-ds ds-secret set oidc-github.client-secret
cat secret.txt | sudo shpd-ds ds-secret set oidc-shipard-id.client-secret   # ze stdin (jedno koncové \n se odstraní)
```

Tajemství konfigurace zdroje dat v `secrets/` — co tajemství, to soubor
(#126 B1, U4, [operations/secrets.md](operations/secrets.md) §5,
[`tasks/ds-secrets-split.md`](../tasks/ds-secrets-split.md)). Ruční zadání
toho, co správce dřív psal do `config/main.json`: GitHub, Google, „Shipard
ID“ z `hosting-oidc-client --generate`, relay pošty.

- **`list`** — tabulka `Name | Used by | Status`: `db.password` (vždy),
  `mail-relay.password` (má-li `main.json` sekci `mail.relay`; bez
  `username` `missing (optional)`), `oidc-<id>.client-secret` pro každý
  provider z `auth.providers`. Stav `present` / `missing` /
  `in main.json (run ds-upgrade)`. Ostatní soubory v `secrets/`
  (`secrets.key`, klíče hostingu) jen počtem. Nikdy hodnoty ani délky.
- **`set <name>`** — jen `mail-relay.password` a `oidc-<id>.client-secret`
  (`id` providera `[a-z0-9-]+`; není-li provider v `main.json`, zapíše
  a varuje). Hodnota **nikdy z argumentu**: interaktivní terminál → skrytý
  dotaz, jinak se čte stdin do EOF a odstraní jedno koncové `\n`; prázdná
  hodnota = exit 1. Zápis `SecretsFileWriter` (0700 / 0600, vlastník zdroje
  dat i pod `sudo`, varování vlastnictví vypíše). Je-li totéž tajemství
  ještě v `main.json`, odebere ho — v přechodném období by jinak mělo
  přednost (U1). Výstup `Secret <name> written.`
- `db.password` nastavit nejde — musí sedět na uživatele databáze
  (zapisuje `ds-create`, přesouvá `ds-upgrade`).

Exit 1: není adresář zdroje dat nebo nečitelný `main.json`, neznámá akce
nebo název, `db.password`, prázdná hodnota, chyba zápisu.

#### `ds-secrets-health`

```bash
shpd-ds ds-secrets-health
```

Health check infrastruktury per-DS šifrování (`secrets/secrets.key`):
existence, práva 0600, velikost klíče, čitelnost. Žádný side-effect.
Detaily v [docs/operations/secrets.md](operations/secrets.md).

#### `ds-secrets-rotate`

```bash
sudo shpd-ds ds-secrets-rotate            # ostrá rotace
sudo shpd-ds ds-secrets-rotate --dry-run  # jen výpis, co by se přešifrovalo
```

**Před spuštěním vždy backupnout DS** (DB dump + adresář). Příkaz vygeneruje
nový `secrets.key`, projde všechny `encrypted_text` sloupce a každou hodnotu
přešifruje pomocí nového klíče. Starý klíč se po úspěšné rotaci přepíše.
Pod `sudo` dostane nový klíč vlastníka zdroje dat podle `config/main.json`,
ne `root:root` — jinak by ho PHP-FPM nepřečetl a zdroj dat by nerozšifroval
nic. Když vlastníka nastavit nejde, příkaz skončí `Refusing to rotate…`
před jakoukoli změnou (DB i klíč netknuté).

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše, co by se přešifrovalo, ale nic nezmění |

### Mail

#### `mail-router-bootstrap`

```bash
sudo shpd-ds mail-router-bootstrap
```

Idempotentně zajistí systémového uživatele `_mail_router` a výchozí
mailbox. Spouští se jednou před prvním nasazením mail integrace.

#### `mail-router-setup`

```bash
sudo shpd-ds mail-router-setup
sudo shpd-ds mail-router-setup --force
sudo shpd-ds mail-router-setup --ip 203.0.113.10
sudo shpd-ds mail-router-setup --force --json
```

Vygeneruje (nebo rotuje) API klíč pro externí mail-router.

| Opce | Význam |
|------|--------|
| `--force` | Deaktivovat stávající aktivní klíč a vygenerovat nový |
| `--ip <addr>` | Omezit klíč na konkrétní zdrojovou IP |
| `--json` | Stdout = jediný JSON objekt `{"api_key": "shpd_ak_…", "user_id": N}`, chyby na stderr — strojové rozhraní pro provisioning agenta (D4) |

#### `mail-idempotency-prune`

```bash
sudo shpd-ds mail-idempotency-prune
sudo shpd-ds mail-idempotency-prune --days 30
```

Odstraní idempotency klíče incoming mailů starší než TTL.

| Opce | Význam |
|------|--------|
| `--days <N>` | TTL v dnech (default: hodnota `IdempotencyStore::TTL_DAYS`) |

#### `mail-outbox-run`

```bash
shpd-ds mail-outbox-run
shpd-ds mail-outbox-run --limit 10
```

Worker fronty odchozí pošty — zpracuje due `pending` zprávy (priorita
DESC, stáří ASC) a předtím vrátí do fronty `sending` zaseklé po pádu
workeru (starší 10 min). Spouští se z cronu per DS (každou minutu).
Exit SUCCESS i při selhaných zprávách (reportuje je alert check
`core.mail.outbox_health` a doctor); FAILURE jen při infra chybě.

| Opce | Význam |
|------|--------|
| `--limit <N>` | Max zpráv v jednom běhu (default 50) |

#### `mail-outbox-retry`

```bash
shpd-ds mail-outbox-retry --id 42
```

Vrátí terminálně selhanou (`failed`) zprávu do fronty s vynulovaným
počítadlem pokusů. Použij po opravě transportu — viz runbook
[`docs/mail/outbound.md`](mail/outbound.md).

#### `mail-send-test`

```bash
shpd-ds mail-send-test --to admin@example.com
shpd-ds mail-send-test --to admin@example.com --from ucet@firma.cz
shpd-ds mail-send-test --to admin@example.com --to druhy@example.com --cc kopie@example.com
```

Smoke test transportu: synchronně odešle testovací zprávu (zapíše se do
outboxu) a vypíše stav, použitý transport, trvání a SMTP odpověď. Exit
podle výsledku. Bez `--from` se použije settings klíč `mail.defaultFrom`.

Na serveru se zapnutou pojistkou odchozí pošty
([mail/outbound.md](mail/outbound.md) § Pojistka) přibude řádek `Safety:`
— `redirected to <adresa>`, nebo `held by mail safety — nothing was sent`
(transport `safety:<režim>`, stav `sent`, exit 0). Zpráva pak na `--to`
nedorazí; pro test skutečného doručení musí být adresa povolená
(`allowlist`) nebo cílem přesměrování.

| Opce | Význam |
|------|--------|
| `--to <addr>` | Příjemce (povinné); lze opakovat — všechny adresy jdou do „Komu“ jedné zprávy |
| `--cc <addr>` | Kopie; lze opakovat |
| `--from <addr>` | From adresa — rozhoduje o transportu (sender vs. relay) |
| `--subject <s>` | Předmět (default `Shipard mail-send-test`) |

### AI

Výchozí AI připojení `default`, řádky AI funkcí a výchozí profil analýzy
pošty zakládá `ds-upgrade` sám (bezpodmínečně, i pod `skipProvisioning`).
Jediný ruční krok při zřízení zdroje dat je klíč připojení:

#### `ai-backend-set-key`

```bash
sudo shpd-ds ai-backend-set-key                       # klíč zadáš skrytě na výzvu
sudo shpd-ds ai-backend-set-key --backend=second      # jiné než výchozí připojení
echo "$KEY" | sudo shpd-ds ai-backend-set-key         # ze STDIN (skripty)
sudo shpd-ds ai-backend-set-key --api-key=sk-xxx      # jen pro provisioning agenta
```

Nastaví (nebo rotuje) API klíč AI připojení (`core_ai_backends`) — platí
pro všechny AI funkce, které připojení používají (analýza pošty, chat,
shrnutí dashboardu, obsahové štítky; model a ladění se nastavují
u AI funkcí, `docs/ai.md` §5.3). Bez `--api-key` se klíč čte skrytým
vstupem z terminálu, nebo ze STDIN pipe; neinteraktivní běh bez klíče
končí chybou. Klíč se před uložením zašifruje přes `DsSecretCipher`
a připojení se aktivuje. `--base-url` směruje připojení na AI gateway
hostingu (D5/D6) — klíčem je pak gateway token `shpd_gw_…`; prázdná
hodnota (`--base-url ''`) vrací připojení na přímé Anthropic API.

| Opce | Význam |
|------|--------|
| `--backend <kód>` | Kód připojení (default `default`) |
| `--api-key <klíč>` | Klíč v argumentu — pro provisioning agenta hostingu (`HostingSyncRunner`), kde argv nejde přes shell; ručně dej přednost skrytému vstupu |
| `--base-url <url>` | Base URL API (AI gateway); prázdný řetězec = přímé Anthropic |

`ai-analyzer-set-key` je alias z doby externího analyzeru (zrušen #85
D20–D21) — jedno vydání funguje s upozorněním na stderr. Příkazy
`ai-analyzer-bootstrap` a `ai-analyzer-setup` zanikly; uživatele
`_ai_analyzer` `ds-upgrade` deaktivuje (`[DEACTIVATE]`, viz výše).

#### `ai-test`

```bash
sudo shpd-ds ai-test                                  # všechny AI funkce
sudo shpd-ds ai-test --function mail.extraction       # jedna funkce
sudo shpd-ds ai-test --json                           # strojově: jeden JSON objekt
```

Test AI funkcí (#85 D52, `docs/ai.md` §5.7): pro jednu nebo všechny
deklarované funkce provede rozhodnutí resolveru a pošle modelu minimální
požadavek („Reply with OK.“, strop 64 tokenů, bez teploty a nástrojů,
thinking vypnutý, kde ho model umí vypnout, effort `low`). Vypíše řádek
`OK` s připojením, modelem, časem, tokeny, cenou z katalogu
a `stop_reason`, nebo `FAIL` se stejnou větou jako v detailu funkce
v Nastavení (`core.ai.texts` v jazyce zdroje dat): chyba nastavení (bez
volání modelu), nedešifrovatelný klíč, chyba API podle druhu (401 neplatný
klíč, 404 model na platformě není, vyčerpaný limit útraty…). Exit 0, když
všechny funkce prošly, jinak 1; FAILURE i bez compiled configu
(`ds-upgrade`). **Stojí peníze** (zlomky centu na funkci) a na hostovaném
zdroji dat jde přes gateway do společného limitu — jen ručně, nikdy
z cronu.

| Opce | Význam |
|------|--------|
| `--function <id>` | Jen tato funkce (`mail.extraction`, `chat.default`, …); neznámé id = FAIL |
| `--json` | Stdout = jediný JSON objekt `{ok, functions: [{id, ok, connection, connectionOrigin, model, elapsedMs, inputTokens, outputTokens, costUsd, stopReason, reply}]}`, u selhání `{id, ok: false, code, error, …}`; lidské řádky jdou na stderr. `connectionOrigin` = původ připojení (`override` / `function` / `default` / `server` — serverové připojení pro vývoj, #103 D40; `connection` je pak `server`) |

#### `ai-eval`

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ai-eval /cesta/k/repu/tests/datasets/cz/vat-payer --variant /cesta/k/repu/tests/eval/variants/baseline.jsonc
shpd-ds ai-eval … --variant … --only 0101,0902 --yes              # jen dvě buňky, bez dotazu
shpd-ds ai-eval … --variant … --limit 3 --repeat 3 --save-raw /tmp/eval-raw
shpd-ds ai-eval … --variant … --yes --json > /tmp/run.json       # soubor výsledků i na stdout
shpd-ds ai-eval … --variant … --estimate --json                  # jen preflight a odhad, nic nespustí
shpd-ds ai-eval … --variant … --yes --max-cost 0.5 --progress-file /tmp/progress.jsonl   # strop útraty, průběh pro úlohu dashboardu
```

Evaluace AI analýzy nad datovou sadou (#103 F4, `docs/test-corpus.md`
§ 18, `tasks/testing-phase4.md` D34–D41): zprávy sady projdou analýzou
**nanečisto** (`AnalysisComputation` — stejný pipeline jako ostrá analýza,
bez claimu, zápisu a změny stavu zprávy) a výstup se porovná po polích
s `canonicalJson` ideální analýzy ze souborů sady (porovnávač v režimu
evaluace: `match` / `matchNormalized` / `missing` / `extra` / `wrong`,
řádky po pozicích, výjimky štítků řádků podle `rowIndex`). Zpráva s ISDOC
(jak ji rozpozná produkce) jde jako v produkci cestou **`isdoc`** —
import ISDOC → canonical + obohacení štítků **bez AI extrakce** (cena jen
štítky, model `isdoc`), pole, která ISDOC nenese, mají stav `notCarried`
mimo shodu i odchylky; ostatní zprávy cestou `ai` (#103 D76). Výsledek jde do `tests/eval/<název sady>/<datum>-<varianta>[-n].json`
(`shpd.eval.run.v1`: sada s hashem obsahu, git commit, varianta
rozepsaná na skutečné hodnoty, metriky vč. rozpadu po cestách `byPath`,
per zpráva cesta `path` a jen odchylky).

**Preflight** (exit 2, nic se nemění): server jen v režimu `development`;
země sady = země zdroje dat a schémata; zprávy zdroje dat jsou přesně
zprávy sady; **žádný doklad** ze sady není použitý a **žádné pravidlo
IČO → štítek** (historie řádků a pravidla by pokryly štítky dřív než AI
— zdroj dat po `dataset-verify` se přeseeduje); profil varianty je
aktivní; volání `mail.extraction` s přepsáním varianty jde sestavit.

**Odhad a potvrzení:** před během vypíše počet volání (zprávy × opakování),
model, připojení a odhad ceny (z posledního běhu nad sadou se **stejným
skutečným modelem** extrakce, bez ohledu na variantu a hash, vč. ceny
štítků — F6b D56; jinak z tokenů na doklad posledního běhu nad sadou
s modelem téže řady — první číslo v ID katalogu — × ceník plánovaného
modelu; jinak z ceníku katalogu a typické velikosti dokladu; zprávy podoby
PDF + ISDOC se počítají jen za štítky — řádek „Z toho N volání cestou
ISDOC“, v JSON `estimate.aiCalls` / `isdocCalls` / `isdocPerCall`). S `--estimate`
příkaz po odhadu skončí (exit 0, s `--json` objekt `{ok, set, variant, calls,
model, tagsModel, estimate, …}` na stdout) — nic nespustí ani nezapíše;
s `--json` jde při odmítnutí preflightem na stdout i `{ok: false, problems}`.
Bez `--yes`
se ptá jen na skutečném terminálu (STDIN je TTY); bez něj — `-n`,
přesměrovaný nebo prázdný vstup (`< /dev/null`, roura) — končí bez dotazu
exit 2, útrata je vědomý krok. Odpověď N na terminálu = `Aborted.`,
exit 0. **Stojí peníze.**

| Opce | Význam |
|------|--------|
| `<path>` | Složka sady (s `expected/`) nebo `.zip` |
| `--variant <soubor>` | **povinné** — varianta `shpd.eval.variant.v1` (`tests/eval/variants/baseline.jsonc`): profil pošty, šablona promptu ze souboru, přepsání volání (`connection` = kód připojení nebo `server`, model, thinking, effort, maxTokens), přepsání funkce `exchange.contentTags` |
| `--only <seznam>` | Jen tyto doklady: čísla buněk (`0101`), stemy, varianty korpusu (`eu-goods`) nebo messageId, čárkami |
| `--limit <n>` | Nejvýš tolik zpráv (po `--only`) |
| `--repeat <n>` | Každou zprávu tolikrát (výchozí 1): metriky jako průměr a rozpětí, stabilita polí (D41) |
| `--save-raw <adresář>` | Syrové výstupy modelu `<messageId>-<n>.txt` mimo repo |
| `--out <adresář>` | Složka výsledků místo `tests/eval/<název sady>/` v repu |
| `-y, --yes` | Bez potvrzení odhadu |
| `--json` | Soubor výsledků (+ `file`) jako jediný JSON objekt na stdout, lidské řádky na stderr |
| `--estimate` | Jen preflight a odhad (s `--json` objekt na stdout), nic nespustí ani nezapíše (F6b D61) |
| `--max-cost <USD>` | Strop útraty: po jeho dosažení (extrakce + štítky) další volání nezačne; běh se uloží jako neúplný (`stoppedBy: maxCost`, `plannedCalls`) |
| `--progress-file <soubor>` | Průběh jako JSON řádky `started` / `call` / `finished` / `error` (úloha dashboardu, `docs/test-corpus.md` § 18) |

Exit 0 = běh doběhl (i se špatnými výsledky — ty jsou měření), 1 = některé
volání selhalo infrastrukturně (`ai_error`, `config_error`, `internal`),
2 = preflight nebo vstup, 130 = zrušeno. Selhání jednoho volání (síť,
parser, nevalidní canonical) je výsledek zprávy, ne pád. **SIGTERM / SIGINT**
(Ctrl-C, zrušení úlohy z dashboardu): další volání modelu nezačne — ani
opakování po přechodné chybě — rozběhnuté doběhne a běh se uloží se
`stoppedBy: cancelled` (bez jediného hotového volání bez souboru).
Výstup nikdy přes `| head` (SIGPIPE) — `--json > soubor`.

#### `ai-eval-compare`

```bash
shpd-ds ai-eval-compare tests/eval/cz-vat-payer/2026-10-15-baseline.json tests/eval/cz-vat-payer/2026-10-20-sonnet55.json
shpd-ds ai-eval-compare A.json B.json --json
```

Dva soubory výsledků `ai-eval` vedle sebe (#103 D38): metriky s rozdílem
B − A a směrem (`lepší` / `horší`; u ceny, času a selhání je lepší nižší
hodnota), rozdíl menší nebo rovný největšímu rozpětí opakování některého
z běhů označený **v rámci šumu** (jen má-li některý běh `--repeat > 1`),
pole zlepšená / zhoršená (uzel projekce, počet volání s odchylkou A→B),
jiný výběr zpráv (`--only` / `--limit`) jako varování.

**Běhy s různým počtem opakování** (× 1 proti × 3, D41) jsou souměřitelné
jen na jedno opakování: součty přes volání (`calls.*`, `tokens.input`
/ `.output`, `cost.usd`, `time.ms`, počty polí `fields.*.n|missing|extra|wrong`)
se dělí počtem opakování — pak sedí i k rozpětí, které je per opakování;
podíly (`fields.*.hit`, `documents.*`) a hodnoty na doklad
(`cost.perDocument`, `tokens.*PerDocument`, `time.perDocumentMs`) už průměr
jsou. Stejně se dělí počet volání s odchylkou per uzel; za zlepšení
/ zhoršení pole platí rozdíl aspoň **0,5 volání na opakování** (při × 1
každý rozdíl, při × 3 aspoň dvě volání ze tří — jediné odlišné volání
z opakovaného běhu není změna). Příklad nad `2026-10-15-baseline.json`
(× 1) a `2026-10-15-sonnet-5-5.json` (× 3): `supplier.contact.web`
16 → 48 volání je 16 → 16 na opakování, beze změny; `dates.taxPointDate`
4 → 1 zlepšené; `vat.reverseCharge` 0 → 1,7 zhoršené.

Běhy nad různými sadami (hash obsahu `mail/` + `expected/`) odmítne
(exit 2). Nic nevolá ani nezapisuje; běží i mimo adresář zdroje dat.
`--json` = porovnání jako JSON (`set`, `runs`, `metrics[{key, a, b, delta,
noise, withinNoise, better, perRepetition}]` — `perRepetition: true`
u součtů přepočtených na opakování, `fields{basis: "perRepetition",
threshold, improved[{node, a, b, delta}], worsened[…]}` s počty na
opakování, `sameSelection`).

#### `ai-profile-reload`

```bash
sudo shpd-ds ai-profile-reload
sudo shpd-ds ai-profile-reload --profile=mail-classifier
sudo shpd-ds ai-profile-reload --dry-run
```

Načte AI profil z JSONC šablony do DB. Běžný upgrade (šablona s novější
`prompt_version`) probíhá automaticky v rámci `ds-upgrade` — manuální
příkaz slouží pro `--force` (downgrade / přepis stejné verze), `--dry-run`
a `--template-path` scénáře. Bez `--force` odmítne stejnou nebo nižší verzi
šablony; nikdy nepřepisuje admin pole (`name`, `is_default`, `is_active`,
`backend`).

| Opce | Význam |
|------|--------|
| `--profile <kód>` | Očekávaný profil — musí odpovídat `profile_id` šablony (default: kód ze šablony) |
| `--template-path <cesta>` | Override cesty k JSONC šabloně |
| `--force` | Přepsat i při stejné či nižší verzi šablony (downgrade) |
| `--dry-run` | Jen ukázat, co by se změnilo |

#### `mail-analysis-reap`

```bash
sudo shpd-ds mail-analysis-reap
```

Uvolní vypršené AI analysis claims (zaseknutí workeři) a re-queueuje
postižené zprávy. Zprávu, které za poslední hodinu vypršel claim potřetí,
do fronty nevrací: přepne ji na **Analýza selhala** a zapíše selhaný běh
(`ai_error`, „analysis did not finish 3 times within an hour (claim
expired)“) — strop pro pády, které PHP nezachytí (#85 D26); takový řádek
výpis označí. Bezpečné spouštět opakovaně (např. z cronu).

#### `mail-preprocess`

```bash
sudo shpd-ds mail-preprocess --message 1234          # vykoná uložený plán zprávy
sudo shpd-ds mail-preprocess --message 1234 --force  # re-match pravidel + přegenerování
sudo shpd-ds mail-preprocess --sweep                 # záchrana zaseknutých (cron, minute slot)
```

Runner technického předzpracování došlé zprávy (`modules/core/mail/docs/preprocess.md`).
`--message` claimne zprávu ve stavu Čeká (10 → 20) a vykoná **plán uložený
při intake** — změna pravidel po přijetí plán nemění; zpráva, která není
ve stavu 10, skončí tiše (`Skipped`). Primárně ho spouští intake detached
spawnem, ručně se hodí při ladění. Selhání akce není chyba příkazu — zpráva
skončí ve stavu Hotovo s chybami (40) a projde do AI fronty.

| Opce | Význam |
|------|--------|
| `--message <id>` | Id zprávy (`core_mail_incoming_messages.id`) |
| `--force` | Re-match dle **aktuálních** potvrzených pravidel, smazání dříve vygenerovaných příloh (dle provenance) a přegenerování. Funguje i na stavech 0/30/40 — ladění nového pravidla nad starou zprávou. Odmítne zprávu s aktivním AI claimem (`analysis_state=20`) |
| `--sweep` | Rescue: stav 10 starší než 5 min (spawn selhal) a stav 20 starší než 15 min (proces umřel) → zpět na 10 + spawn; po 3 pokusech stav 40. Exit 0 i bez nálezu |

#### `mail-analyze`

```bash
sudo shpd-ds mail-analyze --message 1234   # AI analýza jedné zprávy ve frontě
sudo shpd-ds mail-analyze --sweep          # runnery pro frontu bez claimu (cron, minute slot)
```

Runner AI analýzy došlé zprávy v procesu (`modules/core/mail/docs/ai-analysis.md`
§ Analýza v procesu, #85 D9). `--message` vezme slot souběhu, ověří, že je
zpráva ve frontě (stav Ve frontě, mimo Archiv a Koš, po předzpracování,
schránka s AI, bez claimu), claimne ji (10 → 20), připraví přílohy
a prompt, zavolá model a zapíše výsledek (20 → 30) nebo selhání (20 → 10 /
70). Po dokončení zprávy **dobírá frontu** (#85 D25): drží slot a bere
další zprávy, nejstarší první, každou nejvýš jednou za proces; po
10 minutách od startu novou nezačne, uvolní slot a pro první zbývající
spustí nástupce. Primárně ho spouští příjem, nahrání, konec
předzpracování a reanalýza detached spawnem; ručně se hodí při ladění.
Selhaná analýza **není** chyba příkazu — FAILURE jen špatné volání
a chyby infrastruktury (kterákoli zpráva `crashed`).

| Opce | Význam |
|------|--------|
| `--message <id>` | Id první zprávy (`core_mail_incoming_messages.id`). Bez volného slotu skončí bez zápisu; mimo frontu nebo po claimu jiným runnerem pokračuje další zprávou z fronty; bez nastaveného backendu / klíče skončí hned (jedno varování). Výpis: řádek per zpráva a souhrn (počty, důvod konce, nástupce) |
| `--sweep` | Záchrana: zprávy ve frontě bez aktivního claimu → spawn runneru, nejvýš tolik, kolik je volných slotů; bez použitelného backendu nic (jedno varování). Exit 0 i bez nálezu |

Limit souběhu per server `ai.analysis.maxConcurrent` v `server.json`
(výchozí 2, `0` = analýza pošty na tomto serveru vypnutá), sloty = `flock` na `/opt/shipard/run/ai-analysis-<n>.lock`;
viz [operations/production.md](operations/production.md) § 10. Běh zvedne
`memory_limit` na 512 MB, je-li nižší (přílohy v base64 a v těle požadavku).

#### `mail-target-backfill`

```bash
sudo shpd-ds mail-target-backfill --dry-run   # jen počty + ukázka titulků
sudo shpd-ds mail-target-backfill             # ostrý běh
sudo shpd-ds mail-target-backfill --limit 500 # po částech
```

Doplní partnera (`partner_person`, `partner_name`) a titulek (`ai_title`)
u došlých zpráv **navázaných na doklad** — z hlavičky toho dokladu
(`tasks/mail-import-partner-title.md`). Řeší historii, kterou nepokryje
ani AI, ani ISDOC: zprávy naimportované ze starého Shipardu (do AI fronty
nikdy nevstoupí), zprávy aplikované přes Použít před zavedením partnera
zprávy a zprávy, jejichž doklad se doimportoval později.

Zapisuje **jen do prázdných sloupců**, takže ručně vybraného partnera ani
titulek od AI nepřepíše a druhý běh hlásí `updated = 0`. Jednorázový —
nepatří do cronu. Exit 0 i bez nálezu.

| Opce | Význam |
|------|--------|
| `--dry-run` | Nic nezapíše, vypíše počty a prvních 10 změn k namátkové kontrole |
| `--limit <N>` | Zpracovat nejvýš N zpráv (0 = bez limitu, default) |
| `--batch <N>` | Kolik řádků načíst jedním dotazem (default 500) — tabulka má na produkci 100k+ řádků, stránkuje se keysetem přes `id` |

Souhrn: `scanned` (prohlédnuté zprávy), `updated` (doplněné), `skipped`
(cíl mimo doklady nebo doklad, který v DS není), `unchanged` (nebylo co
doplnit).

### Hosting

Příkazy pro DS s modulem `hosting.core` (centrální správa DS —
`docs/hosting.md`). Spouštějí se z adresáře **hosting DS**.

#### `hosting-oidc-init`

```bash
sudo shpd-ds hosting-oidc-init
```

Vygeneruje privátní klíč OIDC OP (`secrets/oidc-op.key`, RS256) a vypíše
kid. Další krok: nastavit issuer v Nastavení → Hosting
(`hosting.oidc.issuer`).

#### `hosting-oidc-client`

```bash
sudo shpd-ds hosting-oidc-client --ds abcd-efgh-ijkl-mnop --redirect-uri https://firma.example.com/api/v1/_auth/oidc/callback --generate
```

Registrace/aktualizace OIDC klienta OP: nastaví `oidc_client_secret`
(šifrovaný přes Document hook) a `oidc_redirect_uri` na řádku
`hosting_core_data_sources`. `--generate` vytiskne secret **jednou** —
patří do `auth.providers` klientského DS. Alternativa `--secret <s>`
uloží dodanou hodnotu. Fáze 2: pro nové DS z portálu tohle dělá
`HostingDataSourceDocument::beforeSave` + agent automaticky.

| Opce | Význam |
|------|--------|
| `--ds <ds-id>` | **povinné** — klientský DS (ds_id) |
| `--redirect-uri <url>` | registrovaná redirect URI (exact match) |
| `--secret <s>` / `--generate` | uložit dodaný secret / vygenerovat a vytisknout jednou |

#### `hosting-server-key`

```bash
sudo shpd-ds hosting-server-key --server 3 --generate
sudo shpd-ds hosting-server-key --server 3 --revoke
```

API klíč serveru pro provisioning endpointy `/_hosting/server/*` (D3).
`--generate` vytvoří token `shpd_hk_…`, na řádek `hosting_core_servers`
uloží jen prefix + SHA-256 hash a token vytiskne **jednou** — patří do
`hosting.apiKey` v server.json DS serveru. `--revoke` klíč zneplatní
(server se okamžitě odpojí).

| Opce | Význam |
|------|--------|
| `--server <ndx>` | **povinné** — id řádku serveru (`hosting_core_servers.id`) |
| `--generate` / `--revoke` | právě jedna z opcí |

#### `hosting-router-key`

```bash
sudo shpd-ds hosting-router-key --router 1 --generate
sudo shpd-ds hosting-router-key --router 1 --revoke
```

API klíč mail-routeru pro lookup endpoint `/_hosting/mail/lookup` (D4) —
zrcadlo `hosting-server-key` nad `hosting_core_mail_routers`.
`--generate` vytvoří token `shpd_hk_…`, uloží jen prefix + SHA-256 hash
a token vytiskne **jednou** — patří do `lookup_sync.api_key`
v config.yaml na mail-router stroji. `--revoke` klíč zneplatní (router
jede dál na stale lookup).

| Opce | Význam |
|------|--------|
| `--router <ndx>` | **povinné** — id řádku routeru (`hosting_core_mail_routers.id`) |
| `--generate` / `--revoke` | právě jedna z opcí |

#### `hosting-ai-gw-init`

```bash
sudo shpd-ds hosting-ai-gw-init --set-key    # klíč z promptu (skrytý vstup) / STDIN
sudo shpd-ds hosting-ai-gw-init --status     # existence + mtime + práva, nikdy obsah
echo "sk-ant-…" | sudo shpd-ds hosting-ai-gw-init --set-key   # non-TTY pipe
```

Klíč organizace pro AI gateway (D5) — `secrets/ai-gw-anthropic.key`
(0600, vzor `hosting-oidc-init`). Klíč se čte z promptu/STDIN, **nikdy
z argv** (shell history). Opakovaný `--set-key` = rotace (gateway čte
soubor per-request).

| Opce | Význam |
|------|--------|
| `--set-key` / `--status` | právě jedna z opcí |

#### `hosting-ai-token`

```bash
sudo shpd-ds hosting-ai-token --ds 7 --generate [--note "backfill vlm9"]
sudo shpd-ds hosting-ai-token --revoke 3
```

Gateway token DS pro AI gateway `/_hosting/ai-gw/v1/messages` (D5).
`--generate` vytvoří token `shpd_gw_…`, na řádek `hosting_core_ai_tokens`
uloží prefix + SHA-256 hash + šifrovaný plaintext (queue payload)
a token vytiskne **jednou** — patří do `ai-backend-set-key --api-key`
na klientském DS. Určeno pro ruční backfill existujících DS; nové
požadavky mintuje queue payload sám. `--revoke` nastaví `active = 0`
(gateway token okamžitě odmítá, 401).

| Opce | Význam |
|------|--------|
| `--ds <ndx>` | id řádku DS (`hosting_core_data_sources.id`) — povinné pro `--generate` |
| `--generate` / `--revoke <ndx>` | právě jedna z opcí; revoke bere id řádku tokenu |
| `--note <text>` | volitelná poznámka na řádek tokenu |

#### `hosting-stats`

```bash
shpd-ds hosting-stats             # lidsky čitelný výpis počtů
shpd-ds hosting-stats --json      # {"alerts": N|null, "mail": N|null}
```

Read-only agregát „kolik čeho čeká" pro hosting (D7) — počty se
sémantikou karet dashboard feedu, ale laciné COUNTy bez per-user
kontextu: `alerts` = aktivní alerty, `mail` = extrahované doklady
ve stavech 10/20/30 (bez `doc_type = 'other'`) + zprávy s trvale
selhanou AI analýzou mimo Archiv/Koš. Chybějící tabulky modulu →
`null` (modul na DS není aktivní). Jen SELECTy, nic nezapisuje.

S `--json` je stdout jediný JSON objekt — strojové rozhraní pro
stats krok agenta `hosting-sync`; hosting snapshoty upsertuje do
`hosting_core_ds_stats` a portál z nich kreslí badge „k řešení".

| Opce | Význam |
|------|--------|
| `--json` | Stdout = jediný JSON objekt (chyby jdou na stderr). |

### Dataset

Přenosné datové sady (`shpd.dataset.v1`) — složka nebo zip s obsahem DS
ve výměnných formátech. Formát sady, manifest a mail formát popisuje
`docs/datasets.md`; zadání a rozhodnutí `tasks/dataset-phase1.md` (#40).

#### `dataset-dump`

```bash
sudo shpd-ds dataset-dump /tmp/web-demo
sudo shpd-ds dataset-dump /tmp/web-demo --zip --force
sudo shpd-ds dataset-dump /tmp/web-demo --name=web-demo --title="Demo webu" --description="…"
```

Vyexportuje **celý DS** (všechny záznamy mimo koš, `docState != 90`) do
složky sady: `setup/` (číselníky, které reset neobnoví), `persons/`,
`items/`, `docs/`, `registry/`, `mail/` + `manifest.jsonc`. Soubory se
jmenují `NNNN-<slug>.jsonc` v deterministickém pořadí (přirozené klíče,
ne interní id), přílohy záznamu leží v sidecar složce `NNNN-<slug>.files/`.
Sekce modulů, které na DS nejsou aktivní, se vynechají. Věci, které formát
nenese (řádkový partner účetního dokladu, vlastní účet bez kódu…), příkaz
vypíše jako `warning:` — exit code zůstává 0.

| Opce | Význam |
|------|--------|
| `<dir>` | Cílová složka; musí být prázdná nebo neexistovat |
| `--zip[=cesta]` | Zabalit i do zipu (bez hodnoty `<dir>.zip`) |
| `-f, --force` | Přepsat obsah existující sady ve složce (maže jen manifest a známé sekce) |
| `--name` | Identifikátor sady `[a-z0-9][a-z0-9._-]*` (default: slug názvu složky) |
| `--title` | Titulek (default: název DS) |
| `--description` | Popis sady |

#### `dataset-seed`

```bash
sudo shpd-ds dataset-seed /tmp/web-demo
sudo shpd-ds dataset-seed /tmp/web-demo.zip -y
sudo shpd-ds dataset-seed /tmp/web-demo --no-reset
```

Naplní DS obsahem sady. Výchozí režim **resetuje DS** (delegace na
`ds-reset` — na produkci vyžaduje `enableReset: true` v `config/main.json`,
stejně jako `ds-reset` sám) a poté importuje sekce v pořadí setup →
fiskální roky → účetní položky → persons → items → docs → registry → mail.
Osoby, položky a doklady jdou přes standardní appliery (čísla dokladů se
zachovávají, doklady ve stavu V pořádku se zaúčtují), dokumenty spisovny
a zprávy se zapisují přímo přes Document hooky; přílohy do `att/`, analýzy
zpráv jako snapshot (žádné volání AI). Vazby zpráva ↔ doklad se obnoví
podle čísla dokladu. Fiskální roky se založí pro roky, do kterých spadají
data sady; účetní položky z nabídky osnovy jen když o ně manifest žádá
(`provision.accountingItems`, `docs/datasets.md` §1 a §3).

**Třída dat (#126 D7, D11):** před dotazem příkaz vypíše `Data class: free`
nebo `Data class: realCopy — <účel | no purpose set>`; u kopie reálných dat
dotaz s resetem uvede `… — a REAL COPY (purpose: …) …`. Po **úspěšném**
resetu nastaví zdroji dat třídu `free` s původem `dataset-seed <název
sady>` a účelem z titulku sady, jen když zdroj dat účel nemá (U2) —
atomicky přes `MainConfigFile::patch`, vlastník souboru zůstává zdroji dat.
Přechod `realCopy` → `free` tu interaktivní potvrzení nechce (U4): reset
reálná data odstranil dřív, než se třída mění; platí v obou režimech
serveru (zdroj s `enableReset` na produkci). Selže-li reset, třída se
nemění — data jsou původní.

**Která sada ve zdroji dat je (#103 F6b D59):** po úspěšném seedu zapíše
do `main.json` pole `dataset` `{name, hash, seededAt}` — `hash` je hash
obsahu sady (`mail/` + `expected/`, týž jako v souboru výsledků `ai-eval`),
takže vývojářský dashboard pozná zastaralou sadu. Starý záznam se maže už
na začátku resetu (po pádu seedu `main.json` netvrdí aktuální sadu);
`--no-reset` záznam odebere (zdroj dat už není čistá kopie jedné sady) —
souhrn `Dataset: <sada> (<hash>)` nebo `Dataset: not recorded`. Na třídu
dat ani `origin` nesahá; čte ho `shpd-server ds-list --json`. Zdroje dat
seedované před F6b pole nemají — stačí je přeseedovat.
Souhrn vypíše `Data class: free (origin: …)`
a ve vývoji stav serverového AI jako `ds-create` (`Server AI: on (free data
source)` / `off (no server AI key …)`). `--no-reset` třídu ani účel nemění.
Dřívější `provision.serverAi` v manifestu zaniklo (D9) — je to neznámý klíč
a chyba manifestu.

Před jakoukoli změnou proběhne **preflight** — manifest, země sady vs.
země DS (`country` v manifestu), každý soubor proti schématu
a validátorům, přítomnost příloh. Chyba preflightu = nic se nesmaže.
Čerstvý DS hned po `ds-create` (bez schématu) si příkaz nejdřív
upgraduje sám. Na konci souhrn per sekce (ok / failed / skipped)
a porovnání s `counts` z manifestu; jakákoli chyba záznamu = nenulový
exit code.

| Opce | Význam |
|------|--------|
| `<path>` | Složka sady nebo `.zip` |
| `--no-reset` | Nemazat DS — doplnit sadu do existujícího obsahu (osoby/položky `mergeAdd`, duplicitní číslo dokladu nebo kód zprávy = chyba záznamu); třída dat se nemění |
| `-y, --yes` | Bez potvrzení |

> Manifest s `dateMode` jiným než `fixed` seed odmítne („not implemented“ —
> relativní datování je mimo rozsah fáze 1).

#### `dataset-verify`

```bash
shpd-ds dataset-verify /cesta/k/repu/tests/datasets/cz/vat-payer
shpd-ds dataset-verify /cesta/k/repu/tests/datasets/cz/vat-payer --no-apply
shpd-ds dataset-verify /cesta/k/repu/tests/datasets/cz/vat-payer --json
shpd-ds dataset-verify /cesta/k/repu/tests/datasets/cz/vat-payer --no-apply --write-ledger-snapshot=/tmp/general-ledger.json
```

Ověření zaúčtování a DPH nad zdrojem dat naseedovaným datovou sadou
s očekáváními (`expected/`, #103 F3, `docs/test-corpus.md` § 17): použije
všechny otevřené návrhy zpráv sady jako doklady **V pořádku** (jádro
Použít, `MessageProposalApplier`; doklad v cizí měně dostane kurz
z lístků sady — sekce `exchangeRates/`, #121 D6) a spustí šest kontrol
nad všemi doklady sady, i z dřívějšího běhu — druhý běh dá stejný
výsledek: věrnost apply (export dokladu ≟ návrh na polích papírové
projekce), položky a účty (Použít nezaložilo položku, řádky mají položku
s účtem a štítkem, deník bez chyb), přiznání DPH a kontrolní hlášení
proti nezávislému očekávání per období, náklady dokladů (deník třída 5
bez zaokrouhlení, v domácí měně), kurzy dokladů v cizí měně (účetní datum
a kurz ≟ `expected/exchange-rates.json`, kurz resolveru k témuž datu
≟ totéž; #103 D79) a hlavní kniha proti schválenému snímku.

**Preflight** (nic se nemění, exit 2): server mimo `production`, země sady
= země zdroje dat a schémata sady (`DatasetPreflight`), každá zpráva ve
zdroji dat je ze sady a každý doklad vznikl ze zprávy sady. Zdroj dat
s jakýmikoli jinými daty příkaz nepustí, žádný `--force`.

Výstup: per kontrola `ok` / `rozdíl` (seznam rozdílů) / `přeskočeno`
(důvod) a souhrn; `--json` tentýž obsah strojově. Exit `0` vše v pořádku
(přeskočené nevadí), `1` rozdíl nebo selhání apply, `2` chyba vstupu
nebo odmítnutý preflight. Selhání apply jedné zprávy je výsledek, ne pád.

| Opce | Význam |
|------|--------|
| `<path>` | Složka sady (s `expected/`) nebo `.zip` |
| `--no-apply` | Jen kontroly — návrhy nepoužívat |
| `--json` | Report jako JSON na stdout |
| `--write-ledger-snapshot=<soubor>` | Zapsat aktuální hlavní knihu (`ReportResult` JSON) a vypsat obraty po účtech; kontrola deníku pak končí „přeskočeno“ — snímek schvaluje člověk a commituje do `tests/corpus/<země>/profiles/<profil>/approved/` |

#### `dataset-e2e`

```bash
shpd-ds dataset-e2e /cesta/k/repu/tests/datasets/cz/non-payer
shpd-ds dataset-e2e /cesta/k/repu/tests/datasets/cz/non-payer --json
shpd-ds dataset-e2e /cesta/k/repu/tests/datasets/cz/non-payer --no-reseed --output-dir /tmp/e2e-non-payer
shpd-ds dataset-e2e /cesta/k/repu/tests/datasets/cz/non-payer --base-url http://10.0.0.5
```

Proklikání datové sady v prohlížeči jako účetní (#103 F7a, D91–D96,
`docs/test-corpus.md` § 19): zdroj dat přeseeduje (`dataset-seed --yes`),
založí vyhrazeného uživatele `e2e` (administrátor, systémový, bez hesla;
přeseedování ho zachová) a krátkodobou session, token zapíše jen do
souboru `storageState` (0600) v adresáři běhu, spustí Playwright Test
(`tests/e2e/`: dashboard → Zkontrolovat → nerozhodnuté reference
Vytvořit → Vystavit a uzavřít → toast, karty v pořadí serveru), session
zneplatní a soubor smaže (i při chybě a SIGTERM), spustí `dataset-verify
--no-apply` a vydá report. Stejný příkaz spouští úloha vývojářského
dashboardu (**Proklikat** v panelu sady).

**Preflight** (nic se nemění, exit 2): režim `development`, třída zdroje
dat `free`, sada ze seznamu sad v checkoutu, `node` v systémovém `PATH`,
`tests/e2e/node_modules`, Chromium Playwrightu pod uživatelem, který
příkaz spouští, **lokální host** `--base-url` (IP literál nebo jméno,
jehož všechny adresy jsou loopback či vlastní rozhraní serveru — token
session nesmí odejít jinam, #103 D97; ověřená adresa se připíchne curl
i Chromiu) a dosažitelnost API `<base-url>/<ds-id>/api/v1/_app/info`
(jméno, které aplikace nezná — bez záznamu v `/etc/shipard/domains.json`
— odmítne jako `UNKNOWN_HOST`; bez mapy domén jde jen IP). Chybějící
Node, závislosti nebo prohlížeč: postup v [`dev/dev-daily.md`](dev/dev-daily.md)
§ 5 (*Nástroje pro proklikání, videa a testovací kolo*), jedním příkazem
`bash scripts/dev-bootstrap.sh --with-testing`.

Výstup: karty použité / selhané (id karty, krok, důvod, cesta reference,
trace), chyby prohlížeče, odpovědi API ≥ 400, souhrn kontrol
`dataset-verify`, cesty k HTML reportu a trace souborům, stav `ok` /
`failed`; `--json` tentýž obsah jako jeden JSON na stdout (lidské řádky
na stderr). Exit `0` jen při `ok`, `1` nálezy (selhaná karta, chyba
prohlížeče, 5xx, kontrola jiná než `ok`), `2` odmítnutý preflight, `130`
zrušeno (SIGTERM: Playwright dostane SIGINT, session se uklidí).

| Opce | Význam |
|------|--------|
| `<path>` | Složka sady (s `expected/`) nebo `.zip` — musí být sada z checkoutu |
| `--no-reseed` | Bez přeseedování (jen ladění; zdroj dat musí tutéž sadu držet) — druhý běh bez karet skončí nesouladem počtu |
| `--base-url <url>` | Origin aplikace, výchozí `http://127.0.0.1`; jen IP nebo jméno tohoto serveru (D97), jinak exit 2; adresa aplikace = `<url>/<ds-id>/app/` |
| `--output-dir <adresář>` | Adresář běhu (`walkthrough.json`, `report.json`, `playwright-report/`, `traces/`, `storageState` jen po dobu běhu); výchozí nový adresář v systémovém temp, cesta je ve výstupu |
| `--json` | Report jako JSON na stdout |
| `--progress-file <soubor>` | JSON řádky průběhu `started` / `step` / `card` / `finished` / `error` (úloha dashboardu) |

#### `video-run`

```bash
shpd-ds video-run s1-prijata-faktura
shpd-ds video-run s1-prijata-faktura --verb build --json
shpd-ds video-run /cesta/k/repu/demo/scenarios/s1-prijata-faktura.jsonc --anchor 2027-01-20
shpd-ds video-run s1-prijata-faktura --base-url http://10.0.0.5 --progress-file /tmp/video.jsonl
```

Video ze scénáře nad demo sadou (#103 F7b, D119–D129,
`docs/test-corpus.md` § 20): demo sadu scénáře (`dataset` v JSONC)
sestaví generátorem s **kotvou = dnešek** do mezipaměti
`/opt/shipard/run/video-datasets/<sada>/<kotva>/` (druhý běh téhož dne
sadu nestaví; změna příběhu nebo generátoru ji přestaví; starší kotvy
sady se mažou), zdroj dat přeseeduje (`dataset-seed --yes`), založí
vyhrazeného uživatele `video` (administrátor, systémový, bez hesla) a
krátkodobou session jen v souboru `storageState` (0600, soukromý
adresář), spustí video-runner (`tools/video-runner/`) jako podproces
s pevným časem v prohlížeči (kotva + `clockTime` scénáře v jeho časové
zóně, výchozí 09:30) a jazykem podle `locale` scénáře, session zneplatní
a soubor smaže (i při chybě a SIGTERM). `--verb check` = průchod bez
záznamu; `--verb build` = `record` + `compose`, hotové video
`/opt/shipard/run/videos/<id>/<id>.mp4` s `meta.json` (sada, kotva,
commit, čas, délka, velikost) — jedna verze na scénář, nové natočení
přepíše předchozí; výsledek posledního ověření v `check.json`. Stejný
příkaz spouští úloha vývojářského dashboardu (oddíl **Videa**, akce
**Ověřit** / **Natočit**, úloha `video`).

**Preflight** (nic se nemění, exit 2): režim `development`, zdroj dat
třídy `free` (vyhrazený zdroj dat pro videa sady má účel `videa —
<sada>`; čerstvý zdroj dat z `ds-create` potřebuje `ds-upgrade`, jinak
API odpoví 500), scénář z `demo/scenarios/` platný (`dataset`
z `demo/datasets/` druhu demo s příběhem v `demo/datasets/stories/`),
Gotenberg v `server.json` (`render.url`; před stavbou sady i `/health`),
`node`, `ffmpeg` a `python3` v systémovém `PATH`,
`tools/video-runner/node_modules` a Chromium Playwrightu **runneru** pod
uživatelem, který příkaz spouští, **lokální host** `--base-url` (D97)
a dosažitelnost API `<base-url>/<ds-id>/api/v1/_app/info`. Chybějící
`ffmpeg`, závislosti runneru nebo prohlížeč: postup
v [`dev/dev-daily.md`](dev/dev-daily.md) § 5 (*Nástroje pro proklikání,
videa a testovací kolo*), jedním příkazem `bash scripts/dev-bootstrap.sh
--with-testing`.

Výstup: scénář, sada, kotva, pevný čas, kroky s exit kódy a časy
(`build` i „z mezipaměti“, `reseed`, `check` nebo `record` + `compose`),
výsledek runneru (`result.json`: stav, u selhání krok a selektor), u
`build` cesta, délka a velikost videa; `--json` tentýž obsah jako jeden
JSON na stdout (lidské řádky na stderr). Exit `0` jen při `ok`, `1`
nálezy (scénář nesedí, sada nebo seed selhaly, video nevzniklo), `2`
odmítnutý preflight, `130` zrušeno (SIGTERM: generátor dostane SIGTERM,
runner SIGINT, session se uklidí, nic rozepsaného se nepovýší).

| Opce | Význam |
|------|--------|
| `<scenario>` | Id scénáře (`demo/scenarios/<id>.jsonc`), nebo cesta k souboru v tomto adresáři |
| `--verb check\|build` | `check` = průchod bez záznamu (výchozí), `build` = natočit a složit video |
| `--anchor YYYY-MM-DD` | Kotva sady; výchozí dnešek (podle času serveru, UTC) — jiný den jen pro ladění (kotva v jiném fiskálním roce zakládá roky při seedu) |
| `--base-url <url>` | Origin aplikace, výchozí `http://127.0.0.1`; jen IP nebo jméno tohoto serveru (D97), jinak exit 2 |
| `--json` | Report jako JSON na stdout |
| `--progress-file <soubor>` | JSON řádky průběhu `started` / `step` (`build` / `reseed` / `check` / `record` / `compose`) / `finished` / `error` (úloha dashboardu) |

---

## Pomocné skripty

### `scripts/dev-update.sh`

```bash
bash scripts/dev-update.sh
```

Po `git pull` synchronizuje vývojové prostředí — `composer install`,
`npm install`, `npm run build`. Všechny tři kroky jsou idempotentní;
pokud se nic nezměnilo, projdou během pár sekund. Skript lze spustit
z libovolného CWD (sám si dohledá repo root).

Na konci připomene, co sám neudělá (nevolá `sudo`): `ds-upgrade-all` po
změně tabulek nebo cfgItems a — když `shpd-server cron-install --check`
selže — `sudo shpd-server cron-install`; u chybějícího cron souboru
s větou, že první instalace vypne cron existujícím zdrojům dat a vypíše je
(#115 D6, U2). Připomínku potlačí proměnná `SHPD_DEV_UPDATE_NO_CRON_HINT=1`
(nastavuje ji `dev-bootstrap.sh`, který cron instaluje sám o dva kroky dál).

Pro automatizaci přes git hooks:

```bash
git config core.hooksPath .githooks
```

Po této jednorázové konfiguraci se `dev-update.sh` spustí automaticky
po `git pull`, `git pull --rebase` a `git checkout <branch>`.

Celou aktualizaci včetně stažení `stable` a `ds-upgrade-all` dělá
`shpd-server dev-update` (v dashboardu tlačítko **Aktualizovat** ve štítku
zastaralosti) — skript volá jako krok 3 s `SHPD_DEV_UPDATE_NO_CRON_HINT=1`
a cron zkontroluje sám; hooky při svém merge vypíná, aby skript neběžel
dvakrát.

### `scripts/install-packages.sh`

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo bash scripts/install-packages.sh --mode=production
```

Jednorázová idempotentní instalace systémových balíčků (musí běžet jako root).
Podporované systémy jsou **Ubuntu 24.04 a 26.04 LTS** — na jiné verzi skript
skončí chybou dřív, než cokoli nainstaluje.

- PHP 8.5 (cli, fpm, mysql, xml, mbstring, curl, zip, intl) — na 24.04 z PPA
  `ondrej/php`, na 26.04 ze systémových repozitářů
- MariaDB, nginx
- `cron` a `logrotate` (na minimálních obrazech chybí); služba `cron`
  zapnutá a spuštěná (#115 D6)
- composer, git, unzip
- Node.js 24 (LTS, z NodeSource) — už nainstalovaný Node ≥ 22 se ponechá

Dále zařídí:

- Detekci **shipard usera** (development → `$SUDO_USER`, production → systémový `shipard`)
- Vytvoření `/opt/shipard/` (data root) a `/etc/shipard/` (config root) s ownership
  dle [permission kontraktu](operations/permissions.md)
- Symlink `/opt/shipard/shpd` → tento repo clone (kvůli nginx root path)
- Konfiguraci samostatného **PHP-FPM poolu `shipard`** běžícího pod shipard userem
  (`/etc/php/8.5/fpm/pool.d/shipard.conf` + restart php-fpm)
- Aktivaci nginx site `shipard.conf` (existující `development.conf`/`production.conf`
  se uloží jako `.disabled-TIMESTAMP`)
- Symlinky `/usr/bin/shpd-server` a `/usr/bin/shpd-ds` — utility volatelné odkudkoliv
- Pravidlo rotace logů `/etc/logrotate.d/shipard` podle [`logging.md`](logging.md)
  (vlastník v řádku `create` = shipard user daného režimu), **jen když soubor
  neexistuje** — ruční úpravu správce nepřepíše; ověří `logrotate -d`

Cron soubor `/etc/cron.d/shipard` skript nezapisuje — to dělá
`shpd-server cron-install` (`dev-bootstrap.sh`, nebo ručně).

Po instalaci spusť `shpd-server doctor` pro ověření kontraktu.

| Opce | Význam |
|------|--------|
| `--mode <development\|production>` | Operační režim. Default: interaktivní volba. |

Skript běží s neinteraktivním apt (`DEBIAN_FRONTEND=noninteractive`,
`NEEDRESTART_MODE=a`) — nezastaví se na dialogu.

### `scripts/dev-bootstrap.sh`

```bash
bash scripts/dev-bootstrap.sh [volby]
curl -fsSL https://raw.githubusercontent.com/shipard/shipard/stable/scripts/dev-bootstrap.sh | bash
curl -fsSL … | bash -s -- --with-ssh --ssh-pubkey-file <cesta>
```

Vývojové prostředí jedním příkazem — skládá za sebe kroky z [`dev/linux-install.md`](dev/linux-install.md):
checkout → `install-packages.sh --mode=development` → `dev-update.sh` →
`server-init` → `cron-install` → `git config core.hooksPath .githooks` →
volitelné části → `shpd-server doctor` → souhrn s adresou dashboardu. Návod pro macOS
(Multipass, cloud-init `scripts/multipass/shipard-dev.yaml`) a Windows (WSL):
[`dev/local-dev.md`](dev/local-dev.md).

- Běží **pod běžným uživatelem**, `sudo` volá sám (jako root skončí chybou —
  vývojář se určuje ze `$SUDO_USER`). Heslo chce jednou na začátku.
- **Idempotentní.** Existující checkout nechá být (žádný `git pull`),
  `server-init` na hotovém serveru nic nemění, `cron-install` existující
  soubor jen ověří.
- **První instalace cronu** na stroji vypne periodické úlohy všem
  existujícím zdrojům dat a vypíše je (#115 D10) — výpis zůstává v průběhu
  vidět, závěrečné shrnutí odkáže na `shpd-server ds-cron`. Volání
  `dev-update.sh` z bootstrapu má připomínku o cronu potlačenou
  (`SHPD_DEV_UPDATE_NO_CRON_HINT=1`).
- Každé selhání končí nenulovým kódem a pojmenuje krok, ve kterém nastalo.
- Podporované systémy jako u `install-packages.sh`; navíc vyžaduje běžící
  systemd (WSL 2).

| Opce | Význam |
|------|--------|
| `--with-render` | Render služba dle [`operations/render-service.md`](operations/render-service.md): unit `shpd-render`, čekání na `/health`, klíč `render` do `/etc/shipard/server.json` (ostatní klíče beze změny, existující `render` se nepřepisuje). |
| `--with-ssh` | `openssh-server` pro `remote-dev-bridge`: drop-in `/etc/ssh/sshd_config.d/shipard-dev.conf`, port 2222, přihlášení jen klíčem. Mimo WSL zůstávají vedle 2222 i porty, na kterých sshd naslouchal dosud. Když se nastavení neuplatní (jiný soubor zapíná hesla dřív), drop-in se odstraní a skript skončí chybou. |
| `--ssh-pubkey-file <cesta>` | Veřejný klíč, který se připojí do `~/.ssh/authorized_keys` (bez duplicit). Povinný s `--with-ssh`, pokud je `authorized_keys` prázdný. |
| `--branch <větev>` | Větev pro nový checkout. Default `stable`. |
| `--dir <cesta>` | Adresář checkoutu. Default `~/sw/shpd`; při spuštění z checkoutu ten checkout. |

---

## Workflow scénáře

### 1. Nasazení nové verze

```bash
sudo shpd-server upgrade --dry-run   # náhled
sudo shpd-server upgrade             # pull + composer + frontend + ds-upgrade-all + doctor
```

Ve vývoji alternativně:

```bash
bash scripts/dev-update.sh
sudo shpd-server ds-upgrade-all   # když se měnily moduly nebo definice tabulek
```

### 2. Vytvoření nového DS

```bash
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz --purpose="Ruční zkoušení UI"
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-upgrade
sudo shpd-ds user-create --login=admin --password=... --name="Admin"
shpd-server ds-list                 # kontrola: ve vývoji Class free, na produkci realCopy
```

Na vývojovém serveru je takto založený zdroj `free` (ukázková data, Claude
s ním smí pracovat bez schválení); cíl importu reálných dat zakládej
s `--real-copy --no-cron` (workflow 7).

### 2b. Nastavení čerstvého DS z konzole

Čerstvý DS nemá účtovou osnovu ani fiskální roky — parametry vrstvy C
jsou nerozhodnuté a `ds-upgrade` je na konci hlásí jako `[TODO]`
s hotovými příkazy. Do příchodu průvodce (ds-setup Fáze 4) se nastavují
z konzole:

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-upgrade                                       # → [TODO] blok
sudo shpd-ds ds-setting set economy.accountChart default      # nebo npo / none
sudo shpd-ds ds-setting set economy.fiscalYearStartMonth 1
sudo shpd-ds ds-setting set economy.homeCurrency czk
sudo shpd-ds ds-setting set economy.vatAgenda true            # neplátce DPH: false
sudo shpd-ds ds-upgrade                                       # naseeduje osnovu a roky
```

Neplátce DPH (`economy.vatAgenda false`) dostane nové doklady s výchozím
režimem „Bez DPH" a agenda DPH (Registrace, Období) se mu schová
z Nastavení — dokud nikdy neměl žádnou registraci. Historické doklady
a ukončené registrace zůstávají viditelné vždy (docs/ds-setup.md D10/D11).

### 3. Upgrade všech DS najednou

```bash
sudo shpd-server ds-upgrade-all
```

### 4. Upgrade jen jednoho konkrétního DS

```bash
sudo shpd-server ds-upgrade-all --ds=abcd-efgh-ijkl-mnop
```

### 5. Zapnutí mail integrace na DS

```bash
cd /opt/shipard/data-sources/<id>
sudo shpd-ds mail-router-bootstrap
sudo shpd-ds mail-router-setup           # vrátí API klíč pro externí router
```

### 6. Rotace per-DS šifrovacího klíče

```bash
# 1) vždycky napřed backup (viz docs/migration-guide.md)
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-secrets-rotate --dry-run  # ověřit rozsah
sudo shpd-ds ds-secrets-rotate            # provést
sudo shpd-ds ds-secrets-health
```

### 7. Opakovaný test importu

```bash
# 0. cíl importu: kopie reálných dat (bez serverového AI), cron vypnutý
sudo shpd-server ds-create --name "Import" --language cs --country cz --real-copy --no-cron --purpose="Ověřování importu"
cd /opt/shipard/data-sources/<id>
# 1. zapnout import mód
#    config/main.json:  "skipProvisioning": true
sudo shpd-ds ds-reset -y            # čistý stav, schéma bez provisioningu; řádek Data class: realCopy — …
# 2. … spustit import ze starého Shipardu …
# 3. vypnout import mód
#    config/main.json:  "skipProvisioning": false
sudo shpd-ds ds-upgrade             # doplní zbývající referenční data
```

`ds-reset` třídu dat nemění — cíl importu zůstává `realCopy` přes celý
cyklus reset → reimport (`shpd-server ds-list` to ukáže).

`skipProvisioning` se propíše i přes interní `ds-upgrade` v `ds-reset`,
takže při zapnutém flagu reset rekreuje jen schéma a žádné referenční data
nevytvoří (výjimky: clearing infrastruktura, tranzitní účty, saldokonta ve
variantě legacy — enginové kontrakty, které import potřebuje mít před sebou). Po celou dobu opakovaného testování zůstává flag `true`; na
`false` se přepne, až je import hotový „naostro". AI analýza žádnou ruční
akci nevyžaduje: profil, backend i klíč reset přežívají (`keepOnReset`)
a `ds-upgrade` je zajišťuje i pod `skipProvisioning`.

### 8. Připojení DS serveru k hostingu

```bash
# Na hostingu (portál):
# 1. založit řádek serveru (Nastavení → Hosting → Servery),
#    zaškrtnout „Smí zakládat DS" (can_provision)
# 2. vygenerovat klíč serveru
cd /opt/shipard/data-sources/<hosting-ds-id>
sudo shpd-ds hosting-server-key --server <ndx> --generate   # token vytiskne jednou
# 3. nastavit hosting.baseDomain (Nastavení → Hosting)

# Na DS serveru:
# 4. /etc/shipard/server.json — přidat sekci:
#    "hosting": {
#        "url": "https://portal.example.com",
#        "serverId": <ndx>,
#        "apiKey": "shpd_hk_…"
#    }
# 5. přegenerovat cron (slot two-minutes) a ověřit
sudo shpd-server cron-install
shpd-server hosting-sync --dry-run     # náhled fronty, bez akcí
```

Od té chvíle agent každé 2 minuty rekonciliuje a zpracovává požadavky na
nové DS z portálu. Viz `docs/hosting.md` §5.

### 9. Připojení mail-routeru k hostingu

```bash
# Na hostingu (portál):
# 1. založit řádek routeru (Nastavení → Hosting → Mail-routery),
#    vyplnit obsluhované domény (čárkami oddělené)
# 2. vygenerovat klíč routeru
cd /opt/shipard/data-sources/<hosting-ds-id>
sudo shpd-ds hosting-router-key --router <ndx> --generate   # token vytiskne jednou

# Na mail-router stroji:
# 3. /etc/shipard-mail-router/config.yaml — přidat sekci:
#    lookup_sync:
#      url:     https://portal.example.com/api/v1/_hosting/mail/lookup
#      api_key: shpd_hk_…
# 4. zapnout timer a ověřit
sudo systemctl enable --now shipard-mail-router-lookup-sync.timer
sudo -u shipard-mail-router /opt/shipard-mail-router/venv/bin/shipard-mail-router-lookup-sync
cat /etc/shipard-mail-router/lookup.json
```

Od té chvíle si router každé 2 minuty stahuje lookup — nový DS založený
z portálu (s aktivním `core.mail`) přijímá poštu bez ručního zásahu.
Detailní postup vč. backfillu existujících DS:
`docs/operations/mail-router.md`.

---

## Konvence

### Exit codes

- `0` — úspěch
- `1` — chyba (chybějící povinný argument, neexistující DS, nevalidní stav, výjimka)
- `2` — chyba vstupu nebo odmítnutý preflight u porovnávacích příkazů
  (`report-diff`, `dataset-verify`), aby šel rozdíl (`1`) odlišit od
  špatného zadání

### Výstupní tagy (Symfony Console)

| Tag | Barva | Použití |
|-----|-------|---------|
| `<info>` | zelená | název příkazu, úspěšný krok |
| `<comment>` | žlutá | nadpisy, varování, sekce v helpu |
| `<error>` | červená | chyby (pozor: ne `<e>` — to není standardní tag) |

### Pojmenování příkazů

| Prefix | Doména |
|--------|--------|
| `ds-*` | datový zdroj jako celek (`ds-create`, `ds-upgrade`, `ds-upgrade-all`, `ds-secrets-*`) |
| `mail-*` | core.mail modul a router |
| `ai-*` | AI připojení, profily a test volání (`ai-test`) |
| `domain-*` | hostname → DS routing |
| `*-bootstrap` | jednorázová idempotentní inicializace (system uživatel, default mailbox, default profil) |
| `*-setup` | opakovatelná konfigurace (typicky generování/rotace API klíčů) |

### Idempotence

`ds-upgrade`, `*-bootstrap` a `*-setup` (bez `--force`) musí
být idempotentní — opakované spuštění bez efektu, pokud se nic nezměnilo.
To je conscious design choice; umožňuje pouštět `ds-upgrade-all` po každém
deployi bez obav.

Výjimkou je `ds-reset` — ten je **záměrně destruktivní** (vždy dropuje
datové tabulky a rekreuje schéma, není idempotentní v běžném smyslu), ale
je bezpečný k opakování: opakovaný běh skončí znovu čistým stavem.

---

[← docs/README.md](README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
