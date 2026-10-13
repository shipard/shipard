# `hosting-sync` — vlastník `main.json` při běhu pod rootem

**Stav:** hotovo — implementováno 2026-10-13 (2 commity), ověřeno testy; pod rootem nespuštěno (běh agenta potřebuje napojený hosting), ruční ověření pod `sudo` na testovacím hostingu je na člověku; nález z `tasks/ds-state-file-ownership.md` krok 4 (2026-10-12)

> Jednorázový task pro Claude Code (2 commity). Souvisí:
> `tasks/ds-state-file-ownership.md`, `tasks/secrets-rotate-key-owner.md`
> (stejná díra u `state.json` a `secrets.key`), `docs/hosting.md`.

## Kontext

`HostingSyncRunner::patchAuthProvider()` doplňuje při zakládání zdroje
dat poskytovatele přihlášení do `config/main.json`: přečte soubor,
zapíše `main.json.tmp`, nastaví mód `0600` a přes `rename` ho vymění.
Vlastníka nenastaví.

Z cronu běží `hosting-sync` pod shipard-userem a nic se neděje. Ruční
běh pod rootem (`sudo shpd-server hosting-sync`) ale zanechá `main.json`
jako `root:root 0600`:

- `ds-create` o krok dřív adresář zdroje dat správně předá shipard-userovi,
- `patchAuthProvider()` pak `main.json` přepíše souborem roota,
- PHP-FPM ani cron konfiguraci nepřečtou — zdroj dat je nedostupný celý.

`main.json` je v `PermissionSpec`, takže `doctor` chybu nahlásí
a `fix-permissions` ji opraví — až po škodě. Dokumentace ruční běh se
`sudo` neuvádí, ale u prvního nasazení agenta je nasnadě.

Zjištěno čtením kódu, pod rootem nespuštěno.

## Před implementací přečti

- `CLAUDE.md` → Soubory zapisované z CLI do adresáře zdroje dat
- `src/Core/Server/HostingSyncRunner.php` (`provisionSteps`,
  `patchAuthProvider`), `src/Core/Server/MainConfigPatcher.php`
- `src/Core/Server/FileOwnership.php`,
  `tests/Fixtures/Core/Server/RecordingFileOwnership.php`
- `src/Command/DataSource/DsSecretsRotateCommand.php` — vzor použití
  a švu pro testy
- `docs/operations/permissions.md` → Soubory zapisované pod rootem
- `docs/hosting.md` (provisioning agent), `docs/cli.md` § `hosting-sync`
- `tests/Unit/Core/Server/HostingSyncRunnerTest.php`

## Co je potřeba udělat

### 1. Vlastník dočasného souboru před `rename`

`patchAuthProvider()` po `chmod` a před `rename` zavolá
`FileOwnership::matchTo()` na `main.json.tmp`.

- **Vzor je adresář `config/`, ne `main.json`.** Přepisovaný soubor může
  mít špatného vlastníka z dřívějšího běhu a chybu by přenesl dál;
  `FileOwnership::dataSourceReference()` proto tady nepoužívej.
- Selhání: dočasný soubor smazat, `main.json` nechat být, krok vrátí
  chybu (`auth.providers: …` s větou výjimky). Provisioning tím skončí
  jako neúspěšný a nahlásí se hostingu stejně jako jiné chyby kroku.

### 2. Šev pro testy

Volitelný parametr konstruktoru `HostingSyncRunner`
(`?FileOwnership $ownership`), výchozí je skutečná implementace. Místa,
která runner zakládají, se nemění.

### 3. Dokumentace

- `docs/operations/permissions.md` → Soubory zapisované pod rootem:
  řádek pro `main.json` z `hosting-sync`.
- `docs/hosting.md` — u kroku `auth.providers` věta o vlastníkovi.
- `docs/cli.md` § `hosting-sync` — ruční běh pod rootem zachová
  vlastníka zdroje dat.

## Mimo rozsah

- sjednocení `SecretsFileWriter::alignOwnership()` na `FileOwnership`
  (mění kontrakt: varování místo výjimky, jiný vzor),
- soubory z `ConfigCompiler` zapsané pod `sudo shpd-ds ds-upgrade`.

## Testy

`HostingSyncRunnerTest`:

- dočasný soubor dostane vlastníka podle adresáře `config/` před
  `rename` (zaznamenané volání, vzor není `main.json`),
- selhání nastavení vlastníka: krok vrátí chybu, `main.json` má původní
  obsah, `main.json.tmp` neexistuje, další kroky provisioningu se
  nespustí,
- shodný vlastník: beze změny chování (stávající testy).

## Commity

1. `fix(hosting): main.json z hosting-sync pod rootem patří vlastníkovi zdroje dat`
   — kroky 1–2 a testy.
2. `docs: vlastník main.json při hosting-sync pod rootem` — krok 3,
   `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

Běh agenta potřebuje napojený hosting, pod rootem ho na vývojářském
stroji nespouštěj. Stačí testy; ruční ověření pod `sudo` na testovacím
hostingu je na člověku a zapíše se do `**Stav:**`.

## Hotovo když

- [x] `main.json` přepsaný agentem pod rootem má vlastníka adresáře
      `config/` (test)
- [x] selhání nastavení vlastníka zastaví provisioning a `main.json`
      nezmění (test)
- [x] dokumentace podle kroku 3
- [x] `php -l`, cílené testy, celá sada
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`
