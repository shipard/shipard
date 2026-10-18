# Vývojářský dashboard — zastaralost a Aktualizovat (fáze 3)

**Stav:** hotovo — 2026-10-17, #129 fáze 3 (D16–D22, U1–U8); testy nad dočasnými git repozitáři, živě na dev stroji (`ahead 1`, pojistky, kontrola na pozadí přes PHP-FPM) a 2026-10-18 celý průchod „pozadu → Aktualizovat“ na VM z bootstrapu

## Kontext / Cíl

Po fázi 1 a 2 ukazuje pruh vývojářského dashboardu verzi a hash commitu.
Nevidí ale, že je checkout pozadu za `stable` — VM z bootstrapu snadno
zůstane týdny stará a pokročilý tester (ne vývojář) neví, že má udělat
`git pull`, `scripts/dev-update.sh` a upgrade zdrojů dat.

**Cíl fáze 3:**

1. Štítek v pruhu „N commitů pozadu“ s bublinou (co přibylo, jak staré),
   kontrolovaný líně z dashboardu (D16–D19).
2. Tlačítko **Aktualizovat** v bublině: stažení, fast-forward,
   `dev-update.sh`, upgrade všech zdrojů dat — jako úloha dashboardu
   s pojistkami (D20, D21).
3. Obojí i z terminálu: `shpd-server dev-update-check` a
   `shpd-server dev-update` (D22).

**Mimo rozsah:** štítek „cron neběží“ a výsledek doctora v pruhu
(samostatně později), automatická aktualizace bez kliknutí, aktualizace
mimo režim `development`.

## Návaznost

- **#129** — D7–D9 (pruh, verze), D10–D11 (původní záměr), D16–D22
  (komentář „Fáze 3 — ověření a rozhodnutí“)
- `tasks/dev-dashboard-look.md` (pruh, `--dev-stripe-chip-bg`),
  `tasks/dev-dashboard-i18n.md` (slovník, `t()`)
- #103 F6b D58 — úlohy na pozadí (`src/Core/DevJobs/`), `tasks/ds-delete.md`
  (úloha nad CLI příkazem jako vzor)
- #115 — `cron-install --check`; #96 — `install-packages.sh`,
  `dev-bootstrap.sh` (pool a cron pod vývojářem, klon přes HTTPS)

## Před implementací přečti

- `src/Core/DevJobs/` — `JobRecord` (typy, `dataSourceId` povinné),
  `JobLauncher` (zámek `dev-jobs/.lock`, `activeJobFor`), `JobStore`
  (`list`, `view`, `activeJobFor`), `JobStep`, `JobStepRunner`,
  `DevActionGuard`
- `src/Command/Server/DevJobRunCommand.php` (obal úlohy),
  `src/Command/Server/DsDeleteCommand.php` (příkaz spouštěný úlohou, `--yes`)
- `src/Api/Controller/DevDashboardController.php` (`dispatch`, `getInfo`,
  `deleteDataSource` jako vzor spuštění úlohy), `DevJobsController.php`
- `src/Core/Process/DetachedProcess.php`, `src/Core/Version.php`,
  `src/Core/Server/CronProvisioner.php` (`RUN_DIR`)
- `scripts/dev-update.sh`, `scripts/install-packages.sh` (pool `shipard`
  pod `SUDO_USER` v režimu development)
- `frontend/src/dev/DevApp.svelte` (pruh), `components/DevKebabMenu.svelte`
  (vzor nad `Popover`), `pages/testing/JobsPage.svelte`,
  `JobDetailPage.svelte`, `i18n/`
- `docs/frontend.md` → Vývojářský dashboard, `docs/dev/dev-daily.md` § 1

## Zjištěno při návrhu (2026-10-17)

- Pool PHP-FPM i cron běží v režimu development pod vývojářem, který
  vlastní checkout — git z PHP projde bez `safe.directory`.
- `git ls-remote https://github.com/shipard/shipard.git` projde bez
  `HOME`, agenta i dotazu na heslo (~0,5 s). SSH `origin` jen s klíčem bez
  hesla.
- `git`, `node` 24, `npm`, `composer` jsou v `/usr/bin` nebo
  `/usr/local/bin`. PHP-FPM nepředává `PATH` (`clear_env`) — co spouští
  podprocesy, musí `PATH` nastavit samo.

## 1. Jádro — `src/Core/DevUpdate/`

**Upstream (D16, D17):**

- Konstanty `UPSTREAM_URL = 'https://github.com/shipard/shipard.git'`,
  `UPSTREAM_BRANCH = 'stable'`. Nikdy z požadavku ani z konfigurace;
  jediný přepis je testovací seam v konstruktoru (lokální bare repo).
- Cílová reference: když `origin` (fetch URL) ukazuje na
  `shipard/shipard` — `https://github.com/shipard/shipard(.git)`,
  `git@github.com:shipard/shipard(.git)`, `ssh://git@github.com/shipard/shipard(.git)`
  — tak `refs/remotes/origin/stable`; jinak `refs/shipard/upstream/stable`.
- Fetch: `git fetch --quiet --no-tags <UPSTREAM_URL>
  +refs/heads/stable:<cílová reference>` s prostředím
  `GIT_TERMINAL_PROMPT=0`, `GIT_ASKPASS=/bin/true`, explicitní `PATH`,
  timeout 30 s. Žádné přihlašovací údaje.

**Kontrola (D18, D19):** po fetchi spočítá

- `behind` = `git rev-list --count HEAD..<ref>`,
  `ahead` = `git rev-list --count <ref>..HEAD`;
- `oldestMissingAt` = datum commitu nejstaršího z `HEAD..<ref>`;
- `commits` = posledních 10 z `HEAD..<ref>` (krátký hash, plný hash,
  předmět, datum commitu);
- `upstreamHash`, `head` (hash, větev), `compareUrl` =
  `https://github.com/shipard/shipard/compare/<HEAD>...stable`.

Výsledek zapíše atomicky (dočasný soubor + `rename`) do
`/opt/shipard/run/dev-update/state.json` s `checkedAt`, při selhání
`ok: false` a `error` (krátká věta pro bublinu, např. „GitHub
nedostupný“, „časový limit“). Kontrola běží pod `flock`
na `dev-update/check.lock` — dvě naráz nikdy; druhá skončí hned s tím,
že kontrola už běží.

**Pojistky aktualizace (D20)** — počítají se vždy znovu, ne z cache
(stav pracovního stromu a úloh se mění):

| Kód | Kdy | Rada v bublině |
|---|---|---|
| `notStable` | aktuální větev není `stable` | přepni na `stable`, nebo aktualizuj ručně |
| `dirty` | `git status --porcelain --untracked-files=no` není prázdný | commitni nebo odlož změny (`git stash`) |
| `notFastForward` | `ahead > 0` (lokální commity, které upstream nemá) | pushni nebo srovnej ručně (`git pull --rebase`) |
| `jobRunning` | běží jakákoli úloha dashboardu | počkej na doběhnutí úlohy |
| `missingTool` | chybí `git`, `composer` nebo `npm` v `PATH` úlohy | doinstaluj nástroj (`install-packages.sh`) |
| `notChecked` | stav je neznámý nebo kontrola selhala | zkontroluj znovu |

## 2. Příkazy (D22)

Oba jen v režimu `development` (jinde exit 1 se zprávou), registrace
v `ServerApplicationFactory`, výstup anglicky jako ostatní CLI.

- **`shpd-server dev-update-check [--json]`** — fetch + výpočet + zápis
  `state.json`; bez `--json` čitelný výpis (pozadu / napřed, commity,
  pojistky), s `--json` stav včetně pojistek. Exit 0 i když je checkout
  pozadu; 1 jen při selhání kontroly.
- **`shpd-server dev-update [--yes]`** — kroky (D21), každý s hlavičkou
  `==> [n/5] …` do výstupu:
  1. kontrola (fetch) a pojistky — kterákoli selže → exit 1 s radou,
     nic se nemění;
  2. `git merge --ff-only <cílová reference>`;
  3. `bash scripts/dev-update.sh` (už nová verze z kroku 2);
  4. `shpd-server ds-upgrade-all` (nový proces, nový kód);
  5. `shpd-server cron-install --check` — exit 1 = jen upozornění
     „cron je zastaralý nebo chybí“ s příkazem, ne selhání.
  
  Na konci nový `dev-update-check` (štítek zmizí). Bez `--yes` se
  v terminálu zeptá; z dashboardu jde vždy s `--yes`. Kroky 2–5 dostávají
  explicitní `PATH` (`/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin`).

## 3. Úloha dashboardu (D21)

- Nový typ `JobRecord::TYPE_UPDATE = 'update'`, **bez zdroje dat** (U1):
  `dataSourceId` smí být prázdné jen u typů v nové konstantě
  `SERVER_TYPES` (zatím jen `update`); `toArray()` dá `dataSource: null`.
- Zámek v `JobLauncher::launch()` (pod stávajícím `flock`): `update` se
  nespustí, když běží jakákoli úloha; úloha se zdrojem dat se nespustí,
  když běží `update` (409 `JobConflict` jako dnes). `JobStore` dostane
  `activeJobs()`.
- Jeden krok: `shpd-server dev-update --yes`, `cwd` = kořen checkoutu.
- **Nejde zrušit** (U2): API zrušení i vynucení pro `update` vrátí 409,
  UI tlačítka nenabídne. Přerušení uprostřed `composer install` nebo buildu
  by nechalo checkout v polovičatém stavu; zaseknutou úlohu řeší vývojář
  v terminálu.
- `JobsPage` a `JobDetailPage`: místo zdroje dat „celý server“, bez odkazu
  Otevřít; popisek typu ve slovníku (`jobs.type.update`).

## 4. API

V `DevDashboardController`, pojistka `DevActionGuard::check($mode, null,
false, null, [])` (jen `development`):

- **`GET /_dev/api/updates`** — stav z cache + pojistky spočítané teď +
  `job` (běžící úloha `update`, je-li) + `checking` (běží kontrola).
  Je-li cache starší než hodina nebo chybí a kontrola neběží, spustí
  `shpd-server dev-update-check` přes `DetachedProcess::spawn()`
  a vrátí `checking: true` (U3). Odpověď nikdy nečeká na síť.
- **`POST /_dev/api/updates/check`** — totéž bez ohledu na stáří cache
  („Zkontrolovat teď“), 202.
- **`POST /_dev/api/updates/run`** — pojistky znovu na serveru; nesplněné
  → 409 `UPDATE_BLOCKED` s kódy; jinak spustí úlohu `update` → `{job}`.

## 5. Štítek a bublina (D19, D20)

- Nová komponenta (např. `dev/components/UpdateChip.svelte`) v pruhu
  vlevo od serveru, nad sdíleným `Popover` (vzor `DevKebabMenu`: Esc,
  klik mimo, fokus zpět na štítek).
- Store `dev/updates.svelte.js`: načte stav při startu; při `checking`
  dotazuje každých 5 s, dokud kontrola nedoběhne; po návratu na kartu
  (focus) znovu, nejvýš jednou za minutu.
- **Štítek** (tlačítko, `aria-expanded`):
  - `behind > 0` → „N commitů pozadu“ (plurál přes ICU); nejstarší
    chybějící změna starší než 7 dní → plný štítek (pozadí
    `--dev-stripe-ink`, bílý text), jinak světlý (`--dev-stripe-chip-bg`);
  - kontrola selhala → „nelze ověřit“ (světlý);
  - aktuální, nebo stav zatím neznámý → štítek se neukazuje;
  - běží úloha `update` → „aktualizuji…“ s odkazem na úlohu.
- **Bublina:** nadpis „Na GitHubu je N nových změn“, řádek „větev stable ·
  nejstarší chybějící před X dny · kontrola před Y min“, seznam commitů
  (hash v IBM Plex Mono jako odkaz na commit, předmět), „a M dalších na
  GitHubu“ (odkaz `compareUrl`), „Zkontrolovat teď“.
  Dole „Aktualizace stáhne změny, spustí dev-update.sh a upgraduje všechny
  zdroje dat.“ a tlačítka **Aktualizovat** (`primary`) a Zobrazit na
  GitHubu (`secondary`). Nesplněná pojistka → Aktualizovat neaktivní
  a nad tlačítky žlutá poznámka s radou z tabulky v § 1. Na jiné větvi
  než `stable` se tlačítka nezobrazí, jen poznámka.
- Po spuštění úlohy bublina přejde na odkaz „Průběh aktualizace“ (detail
  úlohy). Po úspěšném doběhnutí ukáže detail úlohy i štítek výzvu
  **Obnovit stránku** (`location.reload()`) — build přepsal soubory
  dashboardu (U7).
- Texty v obou slovnících (`cs.js`, `en.js`), `npm run check:i18n`.

## Kroky

1. **Jádro a příkazy:** `src/Core/DevUpdate/`, `dev-update-check`,
   `dev-update`; testy nad dočasnými git repozitáři (bare „upstream“
   + klon jako checkout; URL přes testovací seam): pozadu / aktuální /
   napřed, fork vs. kanonický `origin` (cílová reference), každá pojistka,
   selhání fetch, zámek kontroly, režim jiný než `development`.
2. **Úloha a API:** `TYPE_UPDATE`, `SERVER_TYPES`, zámek update ↔
   ostatní, zákaz zrušení, `/_dev/api/updates*`; testy controlleru
   a launcheru (409 oběma směry, spuštění kontroly při staré cache, žádné
   spuštění, když kontrola běží).
3. **UI:** štítek, bublina, store, úlohy bez zdroje dat, slovník.
4. **Ověření a dokumentace** (níže), hlavička tasku, `tasks-index.py`.

## Hotovo když

- [x] `vendor/bin/phpunit --filter 'DevUpdate|DevUpdatesController|DevDashboardControllerTest|DevJobsControllerTest|JobLauncherTest|JobRecordTest|JobStoreTest|HelpDriftTest'`
      zelené, pak celá sada (10 081 testů); `cd frontend && npm test` (205)
      `&& npm run check:i18n && npm run build`; `scripts/check-css-tokens.py`
- [x] na VM z bootstrapu (pozadu, `origin` HTTPS): štítek se objeví po
      první kontrole, bublina ukáže commity, **Aktualizovat** doběhne,
      po obnovení stránky je štítek pryč a pruh ukazuje nový hash;
      `git status` hlásí „up to date with origin/stable“ — **živě
      2026-10-18** (Ubuntu 26.04, Aktualizovat přes `POST
      /_dev/api/updates/run`): všech pět kroků, `ds-upgrade-all`
      1 upgraded / 0 failed, krok 5 upozornil na chybějící cron, štítek
      pryč; zrušení odmítnuto (`JOB_NOT_CANCELLABLE`), druhá aktualizace
      zablokovaná (`jobRunning`). První průchod 2026-10-17 skončil
      `failed` v kroku 4 na převodu starých AI připojení (#85, oprava
      6aaa2a47) — úloha selhání nahlásila s radou, checkout zůstal
      konzistentní. Navíc testem pokryto nad dočasnými repozitáři
      (`UpdateCheckerTest`, `DevUpdateCommandTest`: fast-forward, hooky
      vypnuté, pořadí kroků) a v headless Chromiu s podvrženou odpovědí
      API (štítek, bublina, Aktualizovat → „aktualizuji…“ → Obnovit stránku)
- [x] na stroji s lokálními nepushnutými commity: štítek ano,
      Aktualizovat neaktivní s radou `notFastForward` — živě na dev stroji
      (`ahead 1`: `dev-update-check` i `GET /_dev/api/updates` hlásí
      `dirty` + `notFastForward`; štítek se neukáže, protože `behind 0`)
- [x] necommitnutá změna sledovaného souboru → `dirty`; nesledovaný
      soubor aktualizaci neblokuje (`UpdateGuardsTest`)
- [x] s běžící úlohou na kterémkoli zdroji dat nejde Aktualizovat
      spustit (UI i API 409) a během `update` nejde spustit jiná úloha
      (`JobLauncherTest`, `DevUpdatesControllerTest`; UI headless)
- [x] bez sítě: „nelze ověřit“, stránka se nezasekne (selhaný fetch
      v `UpdateCheckerTest`; štítek a bublina headless s `ok: false`)
- [x] `shpd-server dev-update-check` a `dev-update` v terminálu;
      v režimu `production` oba odmítnou (testem)
- [x] dokumentace: `docs/cli.md` (oba příkazy, `dev-update.sh`); `CLAUDE.md`
      (seznam příkazů `shpd-server`, sekce Vývojářský dashboard);
      `docs/frontend.md` → Vývojářský dashboard (štítek, store, API);
      `docs/dev/dev-daily.md` § 1 a § 3; `docs/dev/local-dev.md` § 6;
      `docs/roadmap.md` M6; `tasks/README.md` → sekce Dev dashboard beze
      změny
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Implementační poznámky (2026-10-17)

- **Soubory:** `src/Core/DevUpdate/` (`Upstream`, `UpdateEnvironment`,
  `Git`, `UpdateStateStore`, `UpdateChecker`, `UpdateGuards`, `UpdateStatus`,
  `UpdateStepRunner`), `src/Command/Server/DevUpdateCheckCommand.php`,
  `DevUpdateCommand.php`, `src/Api/Controller/DevUpdatesController.php`,
  `frontend/src/dev/updates.js`, `updates.svelte.js`,
  `components/UpdateChip.svelte`; úpravy `JobRecord`, `JobConflict`,
  `JobLauncher`, `JobStore::activeJobs()`, `DevJobsController::cancel`,
  `DevJobRunCommand` (preload `JobLauncher`), `CapturedProcess::run(…, env)`,
  `DevApp.svelte`, `JobsPage`, `JobDetailPage`, `dev.css`, slovníky.
  Testy: `tests/Fixtures/Core/DevUpdate/GitFixture.php` (bare upstream
  + autorský klon + checkout, vlastní `bin/` se symlinkem na git a stuby
  `composer` / `npm`, `GIT_CONFIG_GLOBAL=/dev/null`), `tests/Unit/Core/DevUpdate/*`,
  `DevUpdateCheckCommandTest`, `DevUpdateCommandTest`, `DevUpdatesControllerTest`,
  `tests/Unit/dev/updates.test.mjs`.
- **Hooky při merge vypnuté** (`git -c core.hooksPath=/dev/null merge
  --ff-only`): na VM z bootstrapu je `core.hooksPath=.githooks` a `post-merge`
  spouští `dev-update.sh` po každém merge včetně fast-forwardu — krok 3 by
  běžel dvakrát. Zadání hooky nezmiňovalo.
- **`dev-update --job=<id>`** (odchylka od „jeden krok `dev-update --yes`“):
  stejná jako u `ds-delete` — úloha dashboardu je sama aktivní úloha
  a pojistka `jobRunning` by ji bez `--job` považovala za cizí. Z terminálu
  bez `--job` blokuje i běžící `update` z dashboardu.
- **Cache neplatí po změně HEAD:** `state.json` nese `head.hash`; liší-li se
  od aktuálního HEAD (lokální commit, merge), je stav `stale` →
  `notChecked` a kontrola na pozadí, štítek se neukazuje (`chipKind`
  porovnává hash). Jinak by po lokálním commitu lhal až hodinu.
- **Značka `check.requested`:** API po spawnu `dev-update-check` zapíše
  značku (mtime, platí 60 s, kontrola ji po zápisu smaže); `checking` =
  zámek držený **nebo** platná značka. Bez ní by UI hned po „Zkontrolovat
  teď“ vidělo `checking: false` (proces ještě nedržel zámek) a přestalo se
  ptát.
- **Oprava po odevzdání (2026-10-17):** bez sítě se kontrola spouštěla při
  každém dotazu dokola (`isStale()` je pro `ok: false` vždy true) —
  `UpdateStateStore::isAutoCheckDue()` odděluje „stav neplatí“ (pojistka
  `notChecked`, tlačítko neaktivní beze změny) od „spustit automaticky“:
  po selhání nejdřív za 10 minut od jeho `checkedAt`; „Zkontrolovat teď“
  se backoffem neřídí.
- **Samostatný `DevUpdatesController`** (prefix `/_dev/api/updates`,
  dispatch z `DevDashboardController` jako u úloh) místo dalších metod
  v 700řádkovém controlleru; seam `updatesController()` pro testy.
- **Prostředí podprocesů** = zděděné + pevný `PATH` ze zadání (vždy, ne jen
  když chybí — terminál i úloha tak mají totéž a `missingTool` platí pro
  obojí) + `GIT_TERMINAL_PROMPT=0`, `GIT_ASKPASS=/bin/true`, `LC_ALL=C`;
  `git` a `bash` se spouštějí absolutní cestou z téhož PATH.
  `CapturedProcess::run()` dostal volitelný `$env` (5. argument `proc_open`).
- **Kód se mění pod běžícím procesem:** `dev-update` resolvuje vše před
  krokem 2 (prostředí, cesty, runner, argv kroků) a kroky 3–5 i závěrečný
  `dev-update-check` běží přes `UpdateStepRunner` (načtený krokem 2) jako
  samostatné procesy; `dev-job-run` si před kroky načte `JobLauncher`, který
  jinak lazy-loadovala až cesta zrušení.
- **`dev-update.sh` běží s `SHPD_DEV_UPDATE_NO_CRON_HINT=1`** — cron hlídá
  krok 5, připomínka skriptu by byla dvakrát.
- **Aktuální checkout** (`behind 0` po pojistkách) = „nothing to do“, exit 0,
  kroky 2–5 neběží; selhaný fetch je `notChecked`, ne „aktuální“.
- **Chyba kontroly** jde do cache jako `errorCode` (`timeout` / `fetchFailed`
  / `notARepository` / `gitFailed`) + anglický detail; bublina překládá kód
  (`updates.error.*`) a detail ukáže v závorce.
- **Ověřeno živě** na dev stroji (`origin` SSH kanonický → `refs/remotes/origin/stable`,
  HEAD 1 napřed): `dev-update-check` (výpis i `--json`), `GET /_dev/api/updates`
  přes nginx + PHP-FPM, `POST …/check` spustil odpojenou kontrolu pod FPM
  (prázdný PATH) — `check.log` a nový `checkedAt`. Headless Chromium
  s podvrženým API: štítek světlý / plný (10 dní), bublina (commity, „a 3
  další“, Zkontrolovat teď → POST, Aktualizovat → POST run → odkaz
  „aktualizuji…“ → po doběhnutí **Obnovit stránku**), pojistky (žlutá
  poznámka, tlačítko neaktivní; na jiné větvi bez tlačítek), „nelze
  ověřit“, aktuální bez štítku, Esc zavře a vrátí fokus; seznam a detail
  úlohy `update` („celý server“, bez Zrušit, Obnovit stránku); bez chyb
  v konzoli. Skutečný běh `dev-update` s merge na tomto stroji neproběhl
  (HEAD napřed = pojistka) — zůstává na VM z bootstrapu.

## Pasti

- **Kód se mění pod běžícím procesem.** Po `git merge` a `composer
  install` jsou na disku jiné PHP soubory i jiný autoloader. `dev-update`
  i obal `dev-job-run` nesmí po kroku 2 načítat žádnou dosud nenačtenou
  třídu — vše potřebné načti předem, kroky 3–5 pouštěj jen jako
  samostatné procesy. Rozhodnutí zapiš do implementačních poznámek.
- **Bash čte skript průběžně.** Kdyby aktualizace běžela jako shellový
  skript z checkoutu, `git merge` by ho přepsal pod rukama. Buď orchestrace
  v PHP (výše), nebo skript se vším ve funkcích a voláním na posledním
  řádku (vzor `dev-bootstrap.sh`).
- **Žádné přihlašovací údaje.** Nikdy `git fetch origin` ani `git pull`
  (SSH s heslem, cizí fork). Jen pevná HTTPS URL, `GIT_TERMINAL_PROMPT=0`.
- **`PATH` z PHP-FPM je prázdný** — podprocesy ho dostávají explicitně;
  pojistka `missingTool` ho kontroluje ve stejném prostředí, v jakém
  poběží úloha.
- **Soubory dashboardu se během aktualizace přepíšou.** UI musí přežít
  chvilková selhání dotazů na stav úlohy (opakovat, nehlásit chybu)
  a nakonec nabídnout obnovení stránky.
- **Pozadu ≠ fast-forward.** Na vývojářském stroji s nepushnutými commity
  je `behind > 0` i `ahead > 0` — štítek ano, tlačítko ne.
- **JS jen s ASCII uvozovkami.**

## Upřesnění (potvrzená 2026-10-17)

- ✓ **U1** — `update` je jediný typ úlohy bez zdroje dat (`SERVER_TYPES`);
  zámek: `update` vylučuje jakoukoli jinou úlohu a naopak.
- ✓ **U2** — úloha `update` nejde z dashboardu zrušit ani vynutit; zaseknutá
  se řeší v terminálu.
- ✓ **U3** — kontrola (fetch) není úloha dashboardu (v seznamu úloh se
  neobjeví): spouští ji API jako odpojený proces `dev-update-check`
  s vlastním zámkem.
- ✓ **U4** — cache drží jen stav vůči GitHubu; pojistky (větev, pracovní
  strom, úlohy, nástroje) se počítají při každém dotazu.
- ✓ **U5** — lokální commity navíc (`ahead > 0`) = `notFastForward`;
  štítek pozadu se ukazuje dál.
- ✓ **U6** — nesledované soubory aktualizaci neblokují.
- ✓ **U7** — po úspěšné aktualizaci výzva **Obnovit stránku**, ne
  automatický reload.
- ✓ **U8** — prahy jsou konstanty v kódu (hodina, 7 dní, 10 commitů,
  timeout fetch 30 s), ne nastavení. Doplnění 2026-10-17: po selhané
  kontrole další automatická nejdřív za 10 minut
  (`UpdateStateStore::RETRY_AFTER_FAILURE_SECONDS`).

## Rozhodnutí k designu (potvrzená)

Úplné znění v #129 (komentář „Fáze 3 — ověření a rozhodnutí“).

- ✓ **D16** — porovnává se vždy se `stable` veřejného repa přes anonymní
  HTTPS, bez ohledu na `origin`; URL a větev napevno.
- ✓ **D17** — kanonický `origin` → `refs/remotes/origin/stable`, fork →
  `refs/shipard/upstream/stable`.
- ✓ **D18** — kontrola líně z dashboardu, cache v
  `/opt/shipard/run/dev-update/`, starší než hodina → kontrola na pozadí;
  „Zkontrolovat teď“.
- ✓ **D19** — štítek jen když pozadu nebo „nelze ověřit“; bublina
  s počtem, stářím, 10 commity a porovnáním; plný štítek nad 7 dní.
- ✓ **D20** — Aktualizovat jen na `stable`, bez necommitnutých změn
  sledovaných souborů, fast-forward, bez běžící úlohy, s nástroji.
- ✓ **D21** — stažení → `merge --ff-only` → `dev-update.sh` →
  `ds-upgrade-all` → `cron-install --check`; úloha se zámkem na celý
  server; výzva k obnovení stránky.
- ✓ **D22** — `shpd-server dev-update-check [--json]`
  a `shpd-server dev-update` (jen `development`).
