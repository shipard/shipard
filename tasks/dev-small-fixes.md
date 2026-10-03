# Drobnosti z ověření lokální instalace — admin v dev dashboardu, práva adresářů DS, composer.lock

**Stav:** částečně — D13 a D14 hotové 2026-10-03 (2 commity + docs, #96), ověřeno na dev serveru: nový DS z dev dashboardu má administrátora a `doctor` u něj nic nehlásí; zbývá `composer.lock` — je v `.gitignore`, přepočet hashe jde jen lokálně, rozhodnout, zda lock verzovat

## Cíl

Při ověřování lokální instalace na čistých Multipass VM (#96) vyšly tři
drobnosti, které na první pohled potká každý nový vývojář:

1. **Dev dashboard zakládá uživatele bez administrátorských práv.** Krok
   „Creating admin user“ v `DevDashboardController` volá `shpd-ds
   user-create` bez `--admin`. Nový uživatel pak nevidí systémová nastavení
   a musí se to dohnat z CLI. Hosting provisioning (`HostingSyncRunner`)
   `--admin` předává správně.
2. **`ds-upgrade` zakládá `config/configuration` s `0755`**, kontrakt
   (`PermissionSpec`) chce `0750` → `shpd-server doctor` hlásí ✗ u každého
   nově založeného DS. Příčina: `ConfigCompiler.php` —
   `mkdir($outputPath, 0755, true)`. Na dev serveru má stejný stav část
   existujících DS.
3. **`composer.lock` neodpovídá `composer.json`** — po přidání
   `ext-openssl` (`tasks/secrets-openssl-cipher.md`) se lock
   neaktualizoval a každý `composer install` varuje „The lock file is not
   up to date“.

## Před implementací přečti

- `src/Api/Controller/DevDashboardController.php` (vytvoření DS, krok
  `user-create`)
- `src/Command/DataSource/UserCreateCommand.php` (volba `--admin`)
- `src/Core/Config/ConfigCompiler.php`
- `src/Core/Server/PermissionSpec.php` (kontrakt práv uvnitř DS)
- `docs/operations/permissions.md`
- `docs/ai-workflow.md` §4

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D13** — uživatel založený v dev dashboardu je administrátor.
- ✓ **D14** — adresáře uvnitř zdroje dat se zakládají s právy podle
  kontraktu (`PermissionSpec`, typicky `0750`). Existující DS srovná
  `shpd-server fix-permissions`; do `ds-upgrade` se oprava práv
  nepřidává.

## Co je potřeba udělat

### 1. Dev dashboard — `--admin` (D13)

V kroku `user-create` přidat `--admin`. Pokud dashboard v UI nebo ve
výstupu kroku popisuje uživatele, ať odpovídá skutečnosti („admin“). Nic
dalšího se nemění.

### 2. Práva adresářů uvnitř DS (D14)

- `ConfigCompiler`: `config/configuration` zakládat s `0750`.
- Projít ostatní `mkdir(…, 0755 …)`, které zakládají cesty **uvnitř
  adresáře zdroje dat** (např. `DsCreateCommand`, `DsUpgradeCommand`,
  `FileStorage`, `ThumbnailGenerator`, `AvatarStorage`,
  `BrandingStorage`, `DatasetWriter` — seznam ověř grepem s
  `--include=*.php`) a srovnat je s `PermissionSpec`. Kde kontrakt cestu
  nepopisuje, rozhodni podle sousedních položek a případně ji do
  kontraktu doplň. Cesty **mimo** DS (`/etc/shipard`, log, dočasné
  soubory tisku) se neřeší.
- Ověřit i práva **souborů**, které tyto cesty plní (zkompilované
  konfigurace, přílohy, náhledy), proti kontraktu — jen pokud se liší,
  opravit.
- Pokud se tatáž hodnota opakuje na více místech, zvaž společnou
  konstantu / pomocnou metodu, ať se to znovu nerozjede — ale bez
  velkého refaktoringu.

### 3. `composer.lock`

`composer update --lock` (jen přepočet `content-hash`, žádná změna
závislostí). Ověřit, že `git diff composer.lock` mění jen hash.

**Zjištění při implementaci (2026-10-03):** `composer.lock` je
v `.gitignore`, v repozitáři tedy není co opravit. Varování vzniká na každém
checkoutu, který má lokální lock z doby před změnou `composer.json`; odstraní
ho lokální `composer update --lock` (přepočte `content-hash` a doplní
`ext-openssl` do bloku `platform`, balíky nemění). Čistý klon lock nemá
a varování nevypíše. Otevřené: verzovat lock, nebo nechat ignorovaný a stav
řešit v `scripts/dev-update.sh` — zastaralý lock navíc zastaví
`composer install`, jakmile do `composer.json` přibude nový balík.

### 4. `docs/ai-workflow.md` §4 — past nástroje

Doplnit krátký odstavec: `shpd-server fix-permissions` bez `--dry-run`
čeká na potvrzení „Proceed? [y/N]“; z Claude (bridge, Claude Code) a ze
skriptů jen `--dry-run`, nebo po schválení `--force` — jinak příkaz visí
do timeoutu.

## Commit strategie

1. `dev dashboard: uživatel nového DS je administrátor (#96 D13)`
2. `ds: adresáře uvnitř DS s právy podle kontraktu (#96 D14)` — kód,
   případně kontrakt a test
3. `composer: aktualizace lock hash po ext-openssl`
4. `docs: fix-permissions v neinteraktivním shellu; hlavička tasku (#96)`
   — `ai-workflow.md`, případně `permissions.md`, hlavička tasku,
   `tasks/README.md` (oblast „Dev dashboard“ nebo „Server, CLI a provoz“)
   + `python3 scripts/tasks-index.py`

## Ověření

- PHPUnit úzce na dotčené třídy (`--filter`)
- na VM nebo dev serveru: nový DS přes dev dashboard (s testovacími daty)
  → přihlášení → systémová nastavení jsou vidět
- nový DS → `shpd-server doctor` bez ✗ u adresářů tohoto DS
- existující DS s `configuration 0755` → `fix-permissions --dry-run`
  ukáže opravu (na dev serveru jen `--dry-run`; aplikovat jen na DS
  v režimu „volný“ nebo po schválení)
- `composer install` bez varování o lock souboru

## Hotovo když

- [x] uživatel z dev dashboardu má administrátorská práva
- [x] nově založený DS (dashboard i CLI `ds-create` + `ds-upgrade`) projde
      `doctor` bez ✗ ve vlastních adresářích
- [ ] `composer install` bez varování o lock souboru — jen lokálně po
      `composer update --lock`, viz zjištění u kroku 3
- [x] `ai-workflow.md` §4 zmiňuje `--force` u `fix-permissions`
- [x] hlavička tasku a `tasks/README.md` aktualizované

## Mimo rozsah

- Hesla v argumentech příkazů, které dev dashboard spouští (vývojový
  nástroj v důvěryhodné síti — samostatné téma, pokud vůbec).
- Automatická oprava práv při `ds-upgrade`.
