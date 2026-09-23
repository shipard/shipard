# CLI nástroje — kompletní reference

Shipard má dvě hlavní CLI utility a dva podpůrné shell skripty. Dohromady
pokrývají instalaci serveru, vytváření a správu datových zdrojů (DS),
údržbu (secrets, mail-router, AI analyzer) a vývojový workflow.

| Nástroj | Účel | Spouštět z |
|---------|------|-----------|
| `shpd-server` | Správa serveru a DS na úrovni serveru (vytváření DS, hromadné upgrade, mapování domén) | libovolný CWD |
| `shpd-ds` | Správa konkrétního datového zdroje (schéma, uživatelé, secrets, mail, AI, seedy) | adresář DS (musí obsahovat `config/main.json`) |
| `scripts/dev-update.sh` | Po `git pull` — sync composer/npm/build | repo root (skript si dohledá sám) |
| `scripts/install-packages.sh` | Jednorázová instalace systémových balíčků (PHP, MariaDB, nginx, …) | repo root, jako root |

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
  verzi šablony; heartbeaty slotů nejsou zatuchlé (`minute` > 10 min,
  `five-minutes` > 20 min = error; `daily`/`weekly` jen warning; selhané
  joby z posledního běhu = warning). Na dev serveru bez cron souboru se
  sekce přeskočí.
- **Data source DB connections** — pokus o `SELECT 1` na každý DS
- **Data source states** — stavy z `config/state.json`
  ([ds-state.md](ds-state.md)): počty per stav, `⚠` DS v maintenance déle
  než 7 dní (zapomenutá? D8), `✗` poškozený `state.json` (DS fail-closed
  zavřený — počítá se jako chyba), `·` ostatní ne-active DS

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

### `ds-create --name <název> --language <cs|en> --country <cc> [--module <id>] [--ds-id <id>]`

```bash
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz --module=install.base
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz --ds-id ab12-cd34-ef56-gh78   # provisioning agent
```

Vytvoří nový datový zdroj:

- vygeneruje unikátní ID (`xxxx-xxxx-xxxx-xxxx`)
- vytvoří `/opt/shipard/data-sources/<id>/` (config, att, cache)
- vytvoří MariaDB databázi a runtime DB uživatele
- vygeneruje `secrets/secrets.key` pro per-DS šifrování `encrypted_text` sloupců
- zapíše `config/main.json` (práva 0600) se zvoleným install modulem,
  jazykem (`defaultLanguage`) a zemí (`country`) — vrstva A dle
  `docs/ds-setup.md` §5.1

Po vytvoření je třeba spustit `shpd-ds ds-upgrade` z adresáře nového DS,
aby se založilo schéma a načetla výchozí konfigurace modulů.

| Opce | Význam |
|------|--------|
| `--name <název>` | **povinné** — lidsky čitelný název DS |
| `--language <cs\|en>` | **povinné** — výchozí jazyk DS, ISO 639-1. Zapíše se do `main.json` → `defaultLanguage`. |
| `--country <cc>` | **povinné** — země subjektu, ISO 3166-1 alpha-2 lower-case (např. `cz`, `sk`). Validuje se jen tvar (`^[a-z]{2}$`) — sémantika proti `world.base.countries` patří volajícím (formulář hostingu, dev dashboard), cfgItem v okamžiku `ds-create` ještě není zkompilovaný. |
| `--module <id>` | volitelné (default: `install.base`) — install modul k aktivaci. Musí odpovídat adresáři `modules/install/<suffix>/`, jehož `module.jsonc` má id `install.<suffix>`. Seznam dostupných modulů: `ls modules/install/`. |
| `--ds-id <id>` | volitelné — explicitní ID místo generovaného (formát `xxxx-xxxx-xxxx-xxxx`, a-z0-9). Používá agent `hosting-sync` (ID generuje hosting). Existující adresář = chyba. |

Oba povinné přepínače se validují **před** jakoukoli mutací — chybějící
nebo nevalidní hodnota nenechá po sobě adresář ani databázi.

Vytvořený `config/main.json` bude obsahovat `"modules": ["<id>"]`. Install
modul je top-level bundle, který deklaruje své závislosti (`core.system`,
`core.attachments`, …), které `ds-upgrade` tranzitivně rozresolvuje.

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
`/etc/cron.d/shipard`) → `completion-install` (vždy, jen pod rootem —
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

### `cron --slot=<slot>`

```bash
shpd-server cron --slot=minute        # z /etc/cron.d/shipard, ručně jen při ladění
```

Dispatcher periodických úloh — volá ho generovaný `/etc/cron.d/shipard`
(viz `cron-install`). Pro každý DS (adresář v
`/opt/shipard/data-sources` s `config/main.json`) spustí per-DS příkazy
slotu subprocesem `shpd-ds <cmd>` s cwd v adresáři DS — **filtrované
podle stavu DS** (`config/state.json`, [ds-state.md](ds-state.md)):

| Slot | Kadence | Příkazy | Běží ve stavech |
|------|---------|---------|-----------------|
| `minute` | každou minutu | `mail-outbox-run`, `mail-analysis-reap`, `mail-preprocess --sweep` | `active` |
| `two-minutes` | à 2 min | server-level: `hosting-sync` | vždy (server-level) |
| `five-minutes` | à 5 min | `alerts-run` (self-throttling přes `next_run_at`) | `active` |
| `daily` | denně 03:17 | `mail-idempotency-prune` (`active`, `read_only`), `vat-periods-ensure` (`active`); server-level: `ds-state-check` | viz příkazy (server-level vždy) |
| `weekly` | neděle 04:43 | `alerts-prune` | `active`, `read_only` |

Registr povolených stavů je `CronCommand::JOB_ALLOWED_STATES`; job bez
záznamu běží jen v `active`. V `suspended` / maintenance /
`pending_deletion` neběží per-DS nic. Přeskočený DS se neloguje (minute
slot by spamoval) — počet jde do heartbeatu.

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
  `corruptedStateFiles` = DS s nečitelným `state.json`, které jsou
  fail-closed zavřené) — čte ho `doctor`.
- Loguje do centrálního `shipard.log`; stdout je minimální (cron redirect
  do `/opt/shipard/log/cron.log` je jen poslední záchrana).

Exit code: **SUCCESS i při selhaných jobech** (reportuje doctor a alert
checky, cron nesmí spamovat MAILTO); FAILURE jen infra chyba (neznámý
slot, nečitelný seznam DS, nezapsatelný heartbeat).

| Opce | Význam |
|------|--------|
| `--slot <slot>` | Povinné: `minute`, `two-minutes`, `five-minutes`, `daily`, `weekly` |

### `cron-install`

```bash
sudo shpd-server cron-install            # zapíše /etc/cron.d/shipard + /opt/shipard/run
shpd-server cron-install --dry-run       # náhled rendrovaného obsahu, lze bez sudo
```

Idempotentní generátor `/etc/cron.d/shipard` (marker s verzí šablony,
přepis jen když se obsah liší, atomicky přes tmp + rename) a runtime
adresáře `/opt/shipard/run` (0750, owner shipard — locky a heartbeaty).
Volá ho `shpd-server upgrade` jako subproces; ručně je potřeba jen při
prvním nasazení nebo když ho doctor ohlásí jako chybějící/zastaralý.
Cron soubor neobsahuje žádné per-DS řádky — `ds-create`/`ds-delete`
regeneraci nevyžadují.

| Opce | Význam |
|------|--------|
| `--dry-run` | Vypíše cíl a rendrovaný obsah, nic nezapíše. Lze bez sudo. |

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
   `shipard-id`, `autoLinkEmail: false`, atomicky, 0600) → `user-create`
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

---

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

Synchronizuje DS s aktuálním stavem modulů. Šest kroků (všechny idempotentní —
opakované spuštění bez efektu, pokud se nic nezměnilo):

1. **Modules resolve** — spočítá závislosti modulů, ověří absenci cyklů
2. **Table definitions + extensions** — sloučí tabulky modulu s extension sloupci
3. **Config compile** — vygeneruje `compiled.{cs,en}.json` z JSONC zdrojů
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
#79 D2), AI analyzer, pravidla předzpracování pošty a vázané řady.
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

**AI analyzer provisioning běží vždy**, i pod `skipProvisioning` — user
`_ai_analyzer`, default backend, default profil i version sync profilu
nejsou migrovaná data, ale systémový kontrakt modulů `core.mail`/`core.ai`
(stejně jako clearing infrastruktura). Na DS bez modulu `core.mail` se
tiše přeskočí (verbose `[SKIP]`).

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
  (`ai-analyzer-set-key`),
- `core.mail` — `core_mail_ai_profiles`: AI profil je konfigurace (vč.
  admin úprav a lokálně laděného promptu), ne migrovaná data. Spolu se
  zachovanými backendy a klíči tak po resetu analyzer funguje bez
  jakékoliv ruční akce.

Vše ostatní se dropuje, **včetně osiřelých tabulek** po odebraných modulech
(`dropSet` = existující tabulky − `keepSet`). Pokud se dropuje
`core_attachments_files`, vyčistí se i obsah `att/` a `cache/thumbnails/`
(prázdné adresáře `ds-upgrade` následně znovu zajistí).

**Produkční pojistka (`enableReset`):** v `production` módu příkaz tvrdě
odmítne (`FAILURE`) bez jakéhokoliv dropu i DB spojení — je to záměrně
destruktivní vývojový/testovací nástroj. Výjimku má jen zdroj dat vědomě
označený jako testovací: volitelný boolean `"enableReset": true` v jeho
`config/main.json` guard pro tento konkrétní DS obchází. Při uplatnění příkaz
vypíše hlasitý warning (`resetting a PRODUCTION data source`); konfirmační
dotaz zůstává (obchází ho jen `--yes`, ne flag). Flag nikdy nenastavuj na DS
s ostrými daty — `shpd-server doctor` na něj na produkci upozorňuje. Žádný
`--force` neexistuje záměrně. V development módu se flag nečte.

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
```

Ovládání `config/state.json` — stavový model viz [ds-state.md](ds-state.md).
Dvě osy: lifecycle stav (`active` | `read_only` | `suspended` |
`pending_deletion`) a maintenance overlay (`import` | `restore` |
`migration` | `manual`). Aktivní maintenance má přednost — DS se chová
jako `suspended`; `--off` vrátí přesně uložený lifecycle stav. Po každém
zápisu příkaz vypíše **efektivní stav**.

| Akce | Chování |
|------|---------|
| `show` (default) | lidsky čitelný výpis; poškozený `state.json` → exit 1 + nápověda k opravě (DS je fail-closed `suspended`) |
| `set <state>` | zapíše lifecycle stav (`changedBy: cli`). `pending_deletion` vyžaduje `--delete-after=<ISO 8601>` v budoucnosti a potvrzení (`--yes` / `-y` pro skripty); přechod jinam `deleteAfter` zahodí. Přepíše i poškozený soubor |
| `maintenance --on [--reason=<r>]` | zapne overlay; `since` se drží od prvního zapnutí i při změně reasonu |
| `maintenance --off` | vypne overlay; bez aktivního maintenance no-op (exit 0) |

Exit code: SUCCESS; FAILURE při nevalidním stavu/reasonu/datu, odmítnutém
potvrzení, selhání zápisu, nebo `show` nad poškozeným souborem.

HTTP: `suspended` / maintenance / `pending_deletion` → 503 `DS_UNAVAILABLE`
+ `Retry-After: 300` (i pro `/_mail/incoming` — mail-router frontuje);
`read_only` → čtení běží, mutace 403 `DS_READ_ONLY`, pošta a analyzer
503, chat vypnutý ([ds-state.md](ds-state.md) §6). Na hostovaných DS bude
stav řídit hosting (`hosting-sync`, fáze 3); lokální CLI je pro nehostované
DS a nouzové zásahy.

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

### Users

#### `user-create`

```bash
sudo shpd-ds user-create --login=jan --name="Jan Novák" --email=jan@example.com
sudo shpd-ds user-create --login=admin --password=Tajne123 --name="Admin" --admin
```

Vytvoří nového uživatele v DS. **Doporučení:** heslo nezadávat — účet
vznikne bez lokálního hesla (NULL hash) a uživatel si ho nastaví sám přes
pozvánku (akce „Poslat pozvánku“ v detailu uživatele v Nastavení, nebo
`POST /_users/{id}/invite`). Heslo tak nikdy neputuje mimo pásmo. Viz
`docs/auth.md`.

| Opce | Význam |
|------|--------|
| `--login <login>` | **povinné** — login |
| `--password <heslo>` | volitelné — heslo; bez něj účet čeká na pozvánku |
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
role-specifické bootstrap subsystémů (mail-router, AI analyzer) viz
příslušné sekce níže — ty jsou samostatné a tyto generické příkazy
je nenahrazují.

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
```

Smoke test transportu: synchronně odešle testovací zprávu (zapíše se do
outboxu) a vypíše stav, použitý transport, trvání a SMTP odpověď. Exit
podle výsledku. Bez `--from` se použije settings klíč `mail.defaultFrom`.

| Opce | Význam |
|------|--------|
| `--to <addr>` | Příjemce (povinné) |
| `--from <addr>` | From adresa — rozhoduje o transportu (sender vs. relay) |
| `--subject <s>` | Předmět (default `Shipard mail-send-test`) |

### AI Analyzer

#### `ai-analyzer-bootstrap`

```bash
sudo shpd-ds ai-analyzer-bootstrap
```

Idempotentně zajistí systémového uživatele `_ai_analyzer`, výchozí AI
backend a výchozí profil.

Totéž automaticky pokrývá `ds-upgrade` (bezpodmínečně, i pod
`skipProvisioning`) — příkaz slouží pro ruční zásah mimo upgrade.

#### `ai-analyzer-setup`

```bash
sudo shpd-ds ai-analyzer-setup
sudo shpd-ds ai-analyzer-setup --force
sudo shpd-ds ai-analyzer-setup --ip 203.0.113.10
```

Vygeneruje (nebo rotuje) API klíč pro externí AI analyzer.

| Opce | Význam |
|------|--------|
| `--force` | Deaktivovat stávající aktivní klíč a vygenerovat nový |
| `--ip <addr>` | Omezit klíč na konkrétní zdrojovou IP |

#### `ai-analyzer-set-key`

```bash
sudo shpd-ds ai-analyzer-set-key --api-key=sk-xxx
sudo shpd-ds ai-analyzer-set-key --backend=openai-gpt4 --api-key=sk-xxx
```

Nastaví (nebo rotuje) API klíč na konkrétním AI backendu. Klíč se před
uložením zašifruje přes `DsSecretCipher`. `--base-url` směruje backend na
AI gateway hostingu (D5/D6) — klíčem je pak gateway token `shpd_gw_…`;
prázdná hodnota (`--base-url ''`) vrací backend na přímé Anthropic API.

| Opce | Význam |
|------|--------|
| `--backend <kód>` | Backend code (default: `default`) |
| `--api-key <klíč>` | **povinné** — plaintext API klíč |
| `--base-url <url>` | base URL API (AI gateway); `''` = reset na přímé Anthropic; nezadaná = beze změny |

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
postižené zprávy. Bezpečné spouštět opakovaně (např. z cronu).

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
a token vytiskne **jednou** — patří do `ai-analyzer-set-key --api-key`
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
stejně jako `ds-reset` sám) a poté importuje sekce v pořadí setup → persons
→ items → docs → registry → mail. Osoby, položky a doklady jdou přes
standardní appliery (čísla dokladů se zachovávají, doklady ve stavu
V pořádku se zaúčtují), dokumenty spisovny a zprávy se zapisují přímo přes
Document hooky; přílohy do `att/`, analýzy zpráv jako snapshot (žádné
volání AI). Vazby zpráva ↔ doklad se obnoví podle čísla dokladu.

Před jakoukoli změnou proběhne **preflight** — manifest, každý soubor proti
schématu a validátorům, přítomnost příloh. Chyba preflightu = nic se
nesmaže. Na konci souhrn per sekce (ok / failed / skipped) a porovnání
s `counts` z manifestu; jakákoli chyba záznamu = nenulový exit code.

| Opce | Význam |
|------|--------|
| `<path>` | Složka sady nebo `.zip` |
| `--no-reset` | Nemazat DS — doplnit sadu do existujícího obsahu (osoby/položky `mergeAdd`, duplicitní číslo dokladu nebo kód zprávy = chyba záznamu) |
| `-y, --yes` | Bez potvrzení |

> Manifest s `dateMode` jiným než `fixed` seed odmítne („not implemented“ —
> relativní datování je mimo rozsah fáze 1).

### Seed (testovací data)

> Seed příkazy jsou určené pro vývoj a demo. **Nepouštět na produkční DS.**

#### `seed-persons`

```bash
sudo shpd-ds seed-persons
sudo shpd-ds seed-persons --count=100 --with-contacts --with-bank-accounts
sudo shpd-ds seed-persons -c 20 --company-ratio=60
```

Vygeneruje fake osoby s prefixem `TEST-` v názvu — později snadno smazatelné
přes `seed-clear`.

| Opce | Význam |
|------|--------|
| `-c, --count <N>` | Počet osob (default: `50`) |
| `--with-contacts` | Také kontakty |
| `--with-bank-accounts` | Také bankovní účty |
| `--company-ratio <0-100>` | Procento firem (default: `40`) |

#### `seed-clear`

```bash
sudo shpd-ds seed-clear
```

Smaže všechny seedované osoby (`TEST-` prefix) včetně jejich kontaktů
a bankovních účtů.

#### `seed-mail`

```bash
sudo shpd-ds seed-mail
sudo shpd-ds seed-mail -c 60
sudo shpd-ds seed-mail --attachment-ratio=20
```

Vygeneruje fake mailboxy a incoming zprávy pro `core.mail` modul.

| Opce | Význam |
|------|--------|
| `-c, --count <N>` | Počet zpráv (default: `60`, doporučení 40–80) |
| `--attachment-ratio <0-100>` | Procento zpráv s ukázkovou PDF přílohou |

#### `seed-mail-clear`

```bash
sudo shpd-ds seed-mail-clear
```

Smaže všechna seedovaná mail data (`TEST-` mailboxy + `TEST-MSG-` zprávy).

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

Pro automatizaci přes git hooks:

```bash
git config core.hooksPath .githooks
```

Po této jednorázové konfiguraci se `dev-update.sh` spustí automaticky
po `git pull`, `git pull --rebase` a `git checkout <branch>`.

### `scripts/install-packages.sh`

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo bash scripts/install-packages.sh --mode=production
```

Jednorázová idempotentní instalace systémových balíčků (musí běžet jako root):

- PHP 8.5 (cli, fpm, mysql, xml, mbstring, curl, zip, intl)
- MariaDB, nginx
- composer, git, unzip

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

Po instalaci spusť `shpd-server doctor` pro ověření kontraktu.

| Opce | Význam |
|------|--------|
| `--mode <development\|production>` | Operační režim. Default: interaktivní volba. |

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
sudo shpd-server ds-create --name "Moje firma s.r.o." --language cs --country cz
cd /opt/shipard/data-sources/<id>
sudo shpd-ds ds-upgrade
sudo shpd-ds user-create --login=admin --password=... --name="Admin"
```

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
cd /opt/shipard/data-sources/<id>
# 1. zapnout import mód
#    config/main.json:  "skipProvisioning": true
sudo shpd-ds ds-reset -y            # čistý stav, schéma bez provisioningu
# 2. … spustit import ze starého Shipardu …
# 3. vypnout import mód
#    config/main.json:  "skipProvisioning": false
sudo shpd-ds ds-upgrade             # doplní zbývající referenční data
```

`skipProvisioning` se propíše i přes interní `ds-upgrade` v `ds-reset`,
takže při zapnutém flagu reset rekreuje jen schéma a žádné referenční data
nevytvoří (výjimky: clearing infrastruktura, tranzitní účty, saldokonta ve
variantě legacy — enginové kontrakty, které import potřebuje mít před sebou). Po celou dobu opakovaného testování zůstává flag `true`; na
`false` se přepne, až je import hotový „naostro". AI analyzer žádnou ruční
akci nevyžaduje: profil, backend i klíče reset přežívají (`keepOnReset`)
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
| `ai-*` | AI analyzer |
| `seed-*` | testovací data |
| `domain-*` | hostname → DS routing |
| `*-bootstrap` | jednorázová idempotentní inicializace (system uživatel, default mailbox, default profil) |
| `*-setup` | opakovatelná konfigurace (typicky generování/rotace API klíčů) |

### Idempotence

`ds-upgrade`, `*-bootstrap`, `*-setup` (bez `--force`), `seed-*-clear` musí
být idempotentní — opakované spuštění bez efektu, pokud se nic nezměnilo.
To je conscious design choice; umožňuje pouštět `ds-upgrade-all` po každém
deployi bez obav.

Výjimkou je `ds-reset` — ten je **záměrně destruktivní** (vždy dropuje
datové tabulky a rekreuje schéma, není idempotentní v běžném smyslu), ale
je bezpečný k opakování: opakovaný běh skončí znovu čistým stavem.

---

[← docs/README.md](README.md) · [Průvodce vývojáře](../DEVELOPERS.md)
