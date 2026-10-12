# `state.json` — vlastník souboru při zápisu pod rootem

**Stav:** naplánováno — nález z přípravy #115 fáze 2 (2026-10-12); blokuje `tasks/cron-dev-install.md`

> Jednorázový task pro Claude Code (2 commity). Souvisí: #56
> (`tasks/ds-state-phase1.md`), #115 (`tasks/cron-ds-switch.md`).

## Kontext

`DataSourceState::save()` zapisuje `config/state.json` atomicky přes
dočasný soubor a `rename`. Nastaví mód `0640`, **vlastníka ne**.

Když příkaz běží pod rootem, vznikne soubor jako `root:root 0640`.
Adresář `config/` má `0750` a patří vlastníkovi zdroje dat, bez setgid.
PHP-FPM ani dispatcher periodických úloh (oba běží jako shipard-user)
takový soubor nepřečtou. `DataSourceState::load()` pak vrací fail-closed
stav: zdroj dat je `suspended`, HTTP odpovídá 503 a cron ho přeskakuje.

Pod rootem se přitom zapisuje běžně:

- `docs/cli.md` uvádí `sudo shpd-ds ds-state …` u všech zápisových akcí,
  `docs/migration-guide.md` stejně u `maintenance --on`,
- `shpd-server ds-cron` (#115 fáze 1) je serverový příkaz,
- #115 fáze 2 bude přepínač zapisovat z `cron-install`, který pod rootem
  běžet musí — a to pro **všechny** zdroje dat na stroji naráz.

`config/state.json` navíc není v `PermissionSpec`, takže ho
`fix-permissions` neopraví a `doctor` jeho práva nekontroluje. `doctor`
spuštěný pod rootem soubor přečte a nic nepozná.

Zjištěno čtením kódu a práv adresářů, **ne spuštěním pod rootem** —
první krok je reprodukce.

`ds-create --no-cron` postižený není: soubor zapisuje před rekurzivním
`chown` na konci příkazu.

## Před implementací přečti

- `src/Core/Config/DataSourceState.php` (`load`, `save`)
- `src/Core/Server/PermissionSpec.php` (`getDataSourceEntries`),
  `docs/operations/permissions.md`
- `src/Command/Server/CronInstallCommand.php` (`applyOwnership` — vzor
  švu pro testy bez roota)
- `src/Core/Server/HealthChecker.php`, `src/Command/Server/FixPermissionsCommand.php`
- `docs/ds-state.md` §3, `docs/cli.md` § `ds-state`
- testy: `tests/Unit/Core/Config/DataSourceStateTest.php`,
  `tests/Unit/Core/Server/PermissionSpecTest.php`

## Co je potřeba udělat

### 1. Reprodukce

V dočasném adresáři (`/tmp/…/config/main.json` jako atrapa, vlastník =
tvůj uživatel) spusť pod `sudo` z toho adresáře
`shpd-ds ds-state cron --off` a potom **bez** `sudo` `shpd-ds ds-state`.
Očekávaný stav před opravou: soubor `root:root`, výpis hlásí nečitelný
`state.json`.

Když `sudo` není k dispozici bez hesla, reprodukci přeskoč, napiš to do
`**Stav:**` a nech ji na člověku. Reálné zdroje dat k reprodukci
nepoužívej.

### 2. `DataSourceState::save()` — vlastník podle zdroje dat

- Dočasný soubor dostane před `rename` vlastníka a skupinu podle
  `config/main.json`; když neexistuje, podle adresáře `config/`.
- Mění se jen tehdy, když se vlastník nebo skupina liší (běžný běh pod
  shipard-userem nic nevolá).
- Nepovede-li se vlastníka nastavit, `save()` skončí výjimkou, dočasný
  soubor smaže a původní `state.json` nechá být. Soubor, který by zdroj
  dat zavřel, se nesmí zapsat potichu.
- Mód `0640` zůstává.
- Zvol šev, který jde otestovat bez roota (viz `applyOwnership()`
  v `CronInstallCommand`).

### 3. `PermissionSpec` — `state.json` v kontraktu

`getDataSourceEntries()` dostane `config/state.json`: volitelný soubor,
vlastník i skupina `user`, mód `0640`. `doctor` pak špatného vlastníka
nahlásí a `fix-permissions` opraví soubory, které už pod rootem vznikly.

### 4. Ostatní zápisy do adresáře zdroje dat — jen přehled

Projdi další atomické zápisy (`rename(` v `src/Core/` a `src/Command/`)
do adresáře zdroje dat a ověř, jestli mají stejnou díru. **Neopravuj je
v tomto tasku** — nález napiš do `**Stav:**`.

### 5. Dokumentace

- `docs/ds-state.md` §3 Zápis — vlastník souboru, proč.
- `docs/operations/permissions.md` — řádek `config/state.json`.
- `docs/cli.md` § `ds-state` — poznámka, že zápis pod rootem zachová
  vlastníka zdroje dat.

## Testy

- `DataSourceStateTest` — shodný vlastník: nic se nemění, soubor je
  čitelný; lišící se vlastník: nastaví se podle `main.json`; selhání
  nastavení: výjimka, původní `state.json` beze změny, dočasný soubor
  pryč; chybějící `main.json`: bere se adresář `config/`.
- `PermissionSpecTest` — `config/state.json` v položkách zdroje dat,
  volitelný.
- test `doctor` / `HealthChecker` — špatný vlastník `state.json` je
  nahlášený, chybějící soubor ne.

## Commity

1. `fix(ds-state): state.json zapsaný pod rootem patří vlastníkovi zdroje dat (#56, #115)`
   — kroky 2–3 a testy.
2. `docs: vlastník state.json při zápisu pod rootem (#56, #115)` — krok 5,
   `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] reprodukce před opravou zapsaná ve `**Stav:**` (nebo proč chybí)
- [ ] `sudo shpd-ds ds-state cron --off` v dočasném adresáři zanechá
      `state.json` s vlastníkem adresáře a `shpd-ds ds-state` bez `sudo`
      ho přečte
- [ ] `doctor` hlásí `state.json` se špatným vlastníkem,
      `fix-permissions --dry-run` ho nabízí k opravě
- [ ] přehled ostatních zápisů (krok 4) ve `**Stav:**`
- [ ] `php -l`, cílené testy, celá sada
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`
