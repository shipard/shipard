# `state.json` — vlastník souboru při zápisu pod rootem

**Stav:** hotovo — implementováno 2026-10-12 (2 commity), týž den ověřeno člověkem pod `sudo` v dočasném adresáři (soubor s vlastníkem adresáře, `0640`, čtení bez `sudo` exit 0); stav před opravou nereprodukován — díra zjištěna čtením kódu; přehled ostatních zápisů v sekci Výsledek — `ds-secrets-rotate` opraven v `tasks/secrets-rotate-key-owner.md`, `hosting-sync` jen při ručním běhu pod rootem; odblokuje `tasks/cron-dev-install.md`

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

- [x] reprodukce před opravou zapsaná ve `**Stav:**` (nebo proč chybí) —
      chybí, díra zjištěna čtením kódu; `sudo` v Claude Code chce heslo
- [x] `sudo shpd-ds ds-state cron --off` v dočasném adresáři zanechá
      `state.json` s vlastníkem adresáře a `shpd-ds ds-state` bez `sudo`
      ho přečte — ověřil člověk 2026-10-12 (postupem níže): soubor
      `<uživatel>:<skupina> 0640`, výpis `Cron: off`, exit 0
- [x] `doctor` hlásí `state.json` se špatným vlastníkem,
      `fix-permissions --dry-run` ho nabízí k opravě — jednotkové testy
      (`HealthCheckerTest`, `FixPermissionsCommandTest`); nad reálným
      souborem `root:root` ověřit spolu s reprodukcí
- [x] přehled ostatních zápisů (krok 4) — sekce Výsledek
- [x] `php -l`, cílené testy, celá sada (9095 testů)
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`

## Výsledek (2026-10-12)

### Reprodukce a ověření pod rootem — postup pro člověka

`sudo` na vývojovém stroji vyžaduje heslo, Claude Code krok neprovedl.
Před opravou (commit `fix(ds-state)…` odložený stranou, nebo starší
checkout) má soubor vlastníka `root:root` a výpis bez `sudo` hlásí
nečitelný `state.json`; po opravě patří vlastníkovi adresáře.
**Ověřeno člověkem 2026-10-12 po opravě** (stav před opravou se
nereprodukoval): zápis pod `sudo` prošel, soubor `0640` s vlastníkem
a skupinou uživatele, čtení bez `sudo` vypsalo `Cron: off`, exit 0.

```bash
DIR="$(mktemp -d /tmp/shpd-state-owner-XXXX)"; mkdir -p "$DIR/config"
echo '{"id":"zzzz-zzzz-zzzz-zzzz","name":"repro"}' > "$DIR/config/main.json"
chmod 0750 "$DIR/config"; chmod 0600 "$DIR/config/main.json"
( cd "$DIR" && sudo php ~/sw/shpd/bin/shpd-ds ds-state cron --off )
ls -l "$DIR/config/state.json"          # očekávaně <uživatel>:<skupina> 0640
( cd "$DIR" && php ~/sw/shpd/bin/shpd-ds ds-state )   # Cron: off, exit 0
sudo rm -rf "$DIR"
```

Stejný adresář poslouží k ověření `doctor` / `fix-permissions`: soubor
ručně `sudo chown root:root`, `shpd-server doctor` ho má hlásit jako
`owner root, expected <shipard-user>`, `sudo shpd-server fix-permissions
--dry-run` nabídnout `chown` — jen u zdroje dat pod
`/opt/shipard/data-sources`, dočasný adresář `doctor` nevidí; na dev
stroji použij volný zdroj dat.

### Krok 4 — ostatní atomické zápisy (`rename(`) do adresáře zdroje dat

Jen čtení kódu, nic z toho se v tomto tasku neopravuje:

| Místo | Nález |
|---|---|
| `DsSecretsRotateCommand` (`secrets/secrets.key`) | **stejná díra**: vlastní `writeTmpKeyFile` + `rename` bez `chown`, `docs/cli.md` příkaz uvádí se `sudo`. Nový klíč `root:root 0600` → DS nerozšifruje nic. `doctor` to odhalí přes rekurzivní kontrolu `secrets/`. **Opraveno týž den** v `tasks/secrets-rotate-key-owner.md` (`FileOwnership::matchTo()` podle `main.json` před transakcí) |
| `HostingSyncRunner::patchAuthProvider` (`config/main.json`) | **podmíněně**: tmp + `rename` + `chmod 0600` bez `chown`. Z cronu běží pod shipard-userem; ruční první běh (`docs/cli.md` § `hosting-sync`) pod `sudo` by `main.json` převedl na `root:root` a DS zavřel úplně. Dokumentace `sudo` neuvádí |
| `SecretsFileWriter::write` (`secrets/*`) | v pořádku — vlastní `alignOwnership()` podle kořene DS (root chownuje, ne-root varuje). Sjednocení na `FileOwnership` jako follow-up |
| `BrandingStorage::store` (`branding/`) | v pořádku — běží jen pod PHP-FPM (upload z UI) |
| `CronCommand::writeHeartbeat` (`run/`) | mimo adresář DS; `--ds` heartbeat nepíše |
| `DomainsFile`, `CompletionInstaller`, `CronInstallCommand` | systémové soubory (`/etc/…`), root je záměr |
| `DsCreateCommand --no-cron` | v pořádku — `state.json` před rekurzivním `chown` na konci příkazu |

Mimo rozsah přehledu (ne-atomické zápisy): `ConfigCompiler` zapisuje
`config/configuration/compiled.*.json` přímo `file_put_contents`; pod
`sudo shpd-ds ds-upgrade` vznikají jako `root:root` s módem podle umask
(obvykle čitelné), `doctor` ho hlásí přes rekurzivní kontrolu
`config/configuration/`.
