# Periodické úlohy — cron ve vývojové instalaci (#115 fáze 2)

**Stav:** naplánováno — PRD z 2026-10-12, upřesnění U1–U5 potvrzena; `tasks/ds-state-file-ownership.md` hotový 2026-10-12 — lze začít

> PRD pro Claude Code (4 commity). Design: issue #115 (D6, D10, D12),
> navazuje na fázi 1 (`tasks/cron-ds-switch.md`). Souvisí: #96
> (`tasks/local-dev-bootstrap.md`), #108 (`tasks/dev-docs-intro.md`).
> Roadmapa: vývojová infrastruktura mimo milníky.

## Kontext

Fáze 1 přidala přepínač periodických úloh per zdroj dat. Cron na
vývojářských strojích ale dál neběží: `dev-bootstrap.sh` ho neinstaluje
a `doctor` mimo produkci chybějící cron mlčky přeskočí. Fronta odchozí
pošty, upozornění, období DPH ani periodická fakturace tak při vývoji
samy nepoběží.

Tento task cron do vývojové instalace přidává. Stroje, které už nesou
zdroje dat, musí přechod přežít beze změny dat: při první instalaci
cronu se všem existujícím zdrojům dat cron vypne a vývojář zapne ty,
které chce (D10).

## Předpoklad

`tasks/ds-state-file-ownership.md` musí být hotový. `cron-install` běží
pod rootem a při první instalaci zapisuje `state.json` všem zdrojům dat;
bez opravy by je všechny zavřel fail-closed.

## Před implementací přečti

- Issue #115 — komentáře s rozhodnutími D1–D14 a „Fáze 1 hotová“
- `docs/ds-state.md` §8, `docs/cli.md` § `cron-install`, `cron --slot`,
  `ds-cron`, `doctor`
- `src/Command/Server/CronInstallCommand.php`,
  `src/Core/Server/CronProvisioner.php`,
  `src/Command/Server/DsCronCommand.php`
- `src/Command/Server/DoctorCommand.php` (`checkCron`)
- `src/Command/Server/UpgradeCommand.php` (krok `cron install`)
- `scripts/dev-bootstrap.sh`, `scripts/dev-update.sh`,
  `scripts/install-packages.sh`, `.githooks/_run-update`
- `docs/dev/dev-daily.md`, `docs/dev/linux-install.md`,
  `docs/dev/local-dev.md`, `docs/logging.md` (Logrotate),
  `docs/migration-guide.md`, `docs/ai-workflow.md` §7
- testy: `tests/Unit/Command/Server/CronInstallCommandTest.php`,
  `DoctorCommandTest.php`

## Scope

**Uvnitř:** první instalace cronu v `cron-install`, kontrola
`cron-install --check`, `doctor` mimo produkci, krok v
`dev-bootstrap.sh`, připomínka v `dev-update.sh`, balíčky a rotace logů
v `install-packages.sh`, návody a dokumentace.

**Mimo:**

- automatika při obnově ze zálohy (D12 — až budou nástroje); tento task
  jen doplní ruční krok do návodu,
- přepínání cronu z vývojářského dashboardu,
- opakované varování sweepu AI analýzy na zdroji dat bez klíče
  (`docs/dev/local-dev.md`, AI na lokální instalaci) — s cronem se
  objeví na každém stroji; případná úprava je samostatný task,
- nástroje na vzdálenou diagnostiku (vlastní issue).

## 1. `cron-install` — první instalace mimo produkci (D10, U1)

„První instalace“ = soubor `/etc/cron.d/shipard` před během neexistuje.

Když je to první instalace **a** server není v režimu `production`:

- před zápisem cron souboru se všem zdrojům dat na stroji vypne cron —
  stejnou cestou jako `ds-cron --off --all` (znovupoužij logiku
  `DsCronCommand`, neduplikuj ji),
- vypíše se seznam zdrojů dat a jak cron zapnout
  (`shpd-server ds-cron --on <id>…`),
- **pořadí je závazné:** nejdřív vypnout, potom zapsat cron soubor.
  Opačně by první tik stihl proběhnout nad kopiemi dat,
- selže-li vypnutí u kteréhokoli zdroje dat, cron soubor se nezapíše
  a příkaz skončí `FAILURE` s výpisem, co opravit. Zdroj dat s poškozeným
  `state.json` instalaci neblokuje — je fail-closed zavřený a žádnou
  úlohu nedostane,
- stroj bez zdrojů dat: nic se nevypíná, jen se zapíše cron soubor.

Volba `--keep-ds-cron` vypínání přeskočí (stroj jen s ukázkovými daty,
skripty).

Na produkčním serveru se chování nemění: `cron-install` na zdroje dat
nesahá nikdy.

`--dry-run` u první instalace mimo produkci navíc vypíše, kterým zdrojům
dat by cron vypnul. Nic nezapisuje.

Přegenerování existujícího souboru (změna šablony nebo cest) zdroje dat
nemění.

## 2. `cron-install --check` (U2)

Nová volba pro skripty: bez výstupu, bez roota.

- exit 0 — cron soubor existuje a odpovídá šabloně,
- exit 1 — chybí nebo se liší.

## 3. `doctor` mimo produkci (D6)

`DoctorCommand::checkCron`, režim jiný než `production`:

- chybějící cron soubor: místo „skipped“ varování
  `⚠ … missing — periodic jobs are not running` s radou
  `sudo shpd-server cron-install` a větou, že první instalace vypne cron
  existujícím zdrojům dat. Počet chyb se nezvyšuje,
- zatuchlý nebo chybějící heartbeat slotů `minute`, `two-minutes`
  a `five-minutes`: varování místo chyby (uspaný notebook, zastavená VM),
- zastaralá verze šablony zůstává chybou — oprava je jeden příkaz,
- produkční chování beze změny.

## 4. Vývojová instalace (D6, U2–U4)

### `scripts/install-packages.sh`

- balíčky `cron` a `logrotate` do instalace (na cloudových obrazech už
  jsou, na minimálních ne); služba `cron` zapnutá a spuštěná,
- pravidlo `/etc/logrotate.d/shipard` podle `docs/logging.md`, vlastník
  v řádku `create` = shipard-user daného režimu. Zapisuje se **jen když
  soubor neexistuje** — ruční úpravu správce skript nepřepíše. Platí pro
  oba režimy,
- ověř `logrotate -d /etc/logrotate.d/shipard` (adresář logu patří
  shipard-userovi, ne rootu).

Důvod: výchozí úroveň logu je `debug` a dispatcher s každým během slotu
zapisuje do `shipard.log` i `cron.log`. Bez rotace by logy na
vývojářském stroji rostly bez omezení.

### `scripts/dev-bootstrap.sh`

- nový krok za `server-init`: `sudo shpd-server cron-install`,
- výpis první instalace (seznam vypnutých zdrojů dat) zůstane v průběhu
  vidět; závěrečné shrnutí připomene `shpd-server ds-cron`,
- opakovaný běh je bezpečný (`cron-install` je idempotentní),
- volání `dev-update.sh` z bootstrapu připomínku o cronu potlačí
  proměnnou prostředí — cron se instaluje o dva kroky dál.

### `scripts/dev-update.sh`

Skript `sudo` nevolá a cron sám neinstaluje (stejně jako
`ds-upgrade-all`). Na konci:

- `shpd-server cron-install --check` selže → připomínka
  `sudo shpd-server cron-install`; u chybějícího souboru s větou, že
  první instalace vypne cron existujícím zdrojům dat a vypíše je,
- jinak nic.

## 5. Dokumentace

- `docs/dev/dev-daily.md` — nová kapitola „Periodické úlohy (cron)“: co
  na stroji běží, kde je to vidět (`shpd-server ds-cron`, sloupec Cron
  v dashboardu, pruh v aplikaci), že kopie reálných dat mají mít cron
  vypnutý, co udělá první instalace, jeden tik přes `cron --slot --ds`,
  kde jsou logy.
- `docs/dev/linux-install.md` — krok s `cron-install` mezi inicializací
  configu a ověřením.
- `docs/dev/local-dev.md` — odstavec o AI na lokální instalaci: cron na
  stroji běží, věta „cron k tomu není potřeba“ a poznámka o varování
  sweepu podle nové skutečnosti.
- `docs/cli.md` — `cron-install` (první instalace, `--keep-ds-cron`,
  `--check`), `doctor` (varování mimo produkci).
- `docs/ds-state.md` §8 — první instalace, odkaz na návod.
- `docs/logging.md` — rotaci instaluje `install-packages.sh`.
- `docs/migration-guide.md` — obnova na neprodukční server: před
  `maintenance --off` spustit `shpd-ds ds-state cron --off` (D12, do
  doby, než budou nástroje obnovy).
- `docs/ai-workflow.md` §7 — u režimu `reálná kopie` věta, že takový
  zdroj dat má mít cron vypnutý; `CLAUDE.local.example.md` podle toho.
- `CLAUDE.md` — seznam CLI příkazů.

## Testy

- `CronInstallCommandTest` — první instalace mimo produkci vypne cron
  všem zdrojům dat **před** zápisem souboru; na produkci ne; s
  `--keep-ds-cron` ne; při přegenerování existujícího souboru ne; stroj
  bez zdrojů dat; selhání vypnutí = soubor nevznikne a `FAILURE`;
  poškozený `state.json` instalaci neblokuje; `--dry-run` nic nezapíše
  a vypíše dotčené zdroje dat; `--check` (shoda, rozdíl, chybí; bez
  roota).
- `DoctorCommandTest` — mimo produkci: chybějící soubor = varování,
  zatuchlý heartbeat = varování, exit kód beze změny; produkce beze
  změny.
- Skripty: `bash -n` na všech třech; `shellcheck`, pokud je na stroji.

## Commity

1. `feat(cron): první instalace mimo produkci vypne cron existujícím zdrojům dat; cron-install --check (#115 D10)`
   — sekce 1 a 2.
2. `feat(doctor): chybějící cron a zatuchlý heartbeat mimo produkci jako varování (#115 D6)`
   — sekce 3.
3. `feat(dev): cron a rotace logů ve vývojové instalaci (#115 D6)` —
   sekce 4.
4. `docs: periodické úlohy na vývojářských strojích (#115)` — sekce 5,
   `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření — pozor

- **`sudo shpd-server cron-install` na vývojářském stroji nespouštěj.**
  Mění systémový cron a zapisuje do všech zdrojů dat včetně kopií
  reálných dat — to dělá člověk. Totéž platí pro opakovaný běh
  `dev-bootstrap.sh` a pro `install-packages.sh`.
- Bez roota smíš: `cron-install --dry-run`, `cron-install --check`,
  `doctor`, `ds-cron` (přehled).
- Čistou instalaci ověřuje člověk na čisté VM podle
  `docs/dev/local-dev.md` (jako u `tasks/local-dev-bootstrap.md`). Co
  ověřené není, zůstává ve `**Stav:**` jako zbývající.

## Hotovo když

- [ ] první `cron-install` mimo produkci vypne cron existujícím zdrojům
      dat, vypíše je a teprve pak zapíše cron soubor
- [ ] `--keep-ds-cron`, `--dry-run` a `--check` podle sekcí 1 a 2
- [ ] produkční chování `cron-install` i `doctor` beze změny
- [ ] `doctor` mimo produkci hlásí chybějící cron a zatuchlý heartbeat
      jako varování
- [ ] `dev-bootstrap.sh` instaluje cron; `dev-update.sh` na chybějící
      nebo zastaralý cron upozorní
- [ ] `install-packages.sh` zajistí `cron`, `logrotate` a pravidlo
      rotace
- [ ] dokumentace podle sekce 5
- [ ] `php -l`, `bash -n`, cílené testy, celá sada
- [ ] čistá VM: bootstrap skončí zeleným `doctor` a heartbeat slotu
      `minute` se obnovuje (člověk)
- [ ] stroj se zdroji dat: první instalace je všechny vypne (člověk)
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`

## Rozhodnutí k designu (potvrzená)

Zamčeno v #115:

- ✓ **D6** — cron je součást vývojové instalace; `doctor` mimo produkci
  hlásí chybějící cron a zatuchlý heartbeat jako varování
- ✓ **D10** — první instalace cronu na vývojářském stroji vypne cron
  všem existujícím zdrojům dat a vypíše je
- ✓ **D12** — obnova ze zálohy vypne cron automaticky, až budou
  nástroje; do té doby ruční krok v návodu

Upřesnění přijatá v tomto PRD (v issue nejsou; potvrzeno 2026-10-12):

- ✓ **U1** — D10 se děje uvnitř `cron-install` (mimo produkci, když cron
  soubor ještě neexistuje), ne ve skriptu. Bezpečná je tak každá cesta:
  bootstrap, ruční příkaz, `shpd-server upgrade` i rada z `doctor`.
- ✓ **U2** — `dev-update.sh` cron neinstaluje ani nepřegenerovává, jen
  upozorní (nevolá `sudo`, stejně jako u `ds-upgrade-all`). Zužuje
  formulaci D6 „aktualizace ho regeneruje“.
- ✓ **U3** — `install-packages.sh` instaluje pravidlo rotace logů, v obou
  režimech a jen když soubor neexistuje.
- ✓ **U4** — balíčky `cron` a `logrotate` jsou součást instalace.
- ✓ **U5** — zastaralá šablona cron souboru zůstává v `doctor` chybou
  i mimo produkci.
