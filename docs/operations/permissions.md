# Permission kontrakt — `/opt/shipard` a `/etc/shipard`

Tento dokument popisuje **závazný kontrakt** vlastnictví a oprávnění pro
shipard serverové adresáře. Kontrakt je kódovaný v
[`src/Core/Server/PermissionSpec.php`](../../src/Core/Server/PermissionSpec.php)
a vynucovaný přes:

- `shpd-server doctor` — read-only kontrola
- `sudo shpd-server fix-permissions` — oprava

## Single-user model

V obou módech (`development` i `production`) je **jediný OS user**
vlastníkem `/opt/shipard/` a běží jako PHP-FPM pool. Žádné group sdílení,
žádné permission triky.

| Mód | Vlastník (shipard-user) | Detekce |
|-----|-------------------------|---------|
| `production` | systémový `shipard` (automaticky vytvořený) | `--mode=production` v install skriptu |
| `development` | vývojářský uživatel (např. `sebik`) | `--mode=development`, default `$SUDO_USER` |

V dalším textu **shipard-user** = ten správný uživatel pro aktuální mód.

## Permission matrix

| Cesta | Owner | Group | Mode | Poznámka |
|-------|-------|-------|------|----------|
| `/etc/shipard/` | `root` | shipard-user | `0750` | adresář |
| `/etc/shipard/server.json` | `root` | shipard-user | `0640` | admin DB credentials |
| `/etc/shipard/secrets/` | shipard-user | shipard-user | `0700` | (volitelné — jen vývojový server) serverové klíče pro vývoj; obsah nejvýš `0600` |
| `/etc/shipard/secrets/dev-ai-<platforma>.key` | shipard-user | shipard-user | `0600` | serverový AI klíč pro vývojové DS (`shpd-server dev-ai-key`, #103 D40); čte ho runtime, proto shipard-user, ne root |
| `/opt/shipard/` | shipard-user | shipard-user | `0751` | root datový adresář; `others=x` aby nginx mohl traversovat do `/opt/shipard/shpd/public` |
| `/opt/shipard/data-sources/` | shipard-user | shipard-user | `0750` | parent všech DS |
| `/opt/shipard/data-sources/<id>/` | shipard-user | shipard-user | `0750` | per-DS root |
| `/opt/shipard/data-sources/<id>/config/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/data-sources/<id>/config/main.json` | shipard-user | shipard-user | `0600` | konfigurace bez tajemství (do tasku B2 z #126 může ještě nést heslo před migrací `ds-upgrade`) |
| `/opt/shipard/data-sources/<id>/config/state.json` | shipard-user | shipard-user | `0640` | (volitelné — bez souboru je DS `active`); stav DS ([ds-state.md](../ds-state.md) §3). Zapisuje se i pod rootem — špatný vlastník = DS fail-closed `suspended` |
| `/opt/shipard/data-sources/<id>/config/configuration/` | shipard-user | shipard-user | `0750` | compiled configs |
| `/opt/shipard/data-sources/<id>/secrets/` | shipard-user | shipard-user | `0700` | striktní |
| `/opt/shipard/data-sources/<id>/secrets/secrets.key` | shipard-user | shipard-user | `0600` | encryption key pro `encrypted_text` |
| `/opt/shipard/data-sources/<id>/secrets/db.password`, `mail-relay.password`, `oidc-<id>.client-secret` | shipard-user | shipard-user | `0600` | tajemství konfigurace zdroje dat ([secrets.md](secrets.md) §5, #126 B1); hlídá rekurzivní kontrola `secrets/` |
| `/opt/shipard/data-sources/<id>/att/` | shipard-user | shipard-user | `0750` | uploady |
| `/opt/shipard/data-sources/<id>/branding/` | shipard-user | shipard-user | `0750` | loga, avatary |
| `/opt/shipard/data-sources/<id>/cache/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/data-sources/<id>/cache/thumbnails/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/log/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/log/shipard.log` | shipard-user | shipard-user | `0640` | (volitelné — vzniká za běhu) |
| nadřazené adresáře checkoutu (dev: `/home/<user>` a další) | beze změny | beze změny | alespoň `o+x` | průchod nginx k `/opt/shipard/shpd/public`; typicky `/home/<user>` → `0751` — viz [Průchozí cesta ke checkoutu](#průchozí-cesta-ke-checkoutu) |
| `/etc/php/8.5/fpm/pool.d/shipard.conf` | `root` | `root` | `0644` | systémový — install-packages.sh |
| `/etc/nginx/sites-available/shipard.conf` | `root` | `root` | `0644` | systémový — install-packages.sh |

### Proč `0751` na `/opt/shipard/`

V dev módu nginx (`www-data`) přímo servíruje SPA assety z
`/opt/shipard/shpd/public/app/`. Aby mohl `www-data` traversovat
přes `/opt/shipard/`, potřebuje `x` bit pro `others`.

Obsahy (`data-sources/`, `log/`) mají `0750` — `www-data` se do nich
nedostane.

### Průchozí cesta ke checkoutu

`/opt/shipard/shpd` je v dev módu symlink na checkout v
`/home/<user>/…`. nginx proto musí projít i **každým nadřazeným adresářem
checkoutu**. Ubuntu od verze 21.04 zakládá domovské adresáře
s `HOME_MODE 0750` (`/etc/login.defs`) a přes ten `www-data` neprojde:
ostatní kontroly jsou v pořádku, ale `/app/…` vrací 500 a nginx loguje

```
stat() "/opt/shipard/shpd/public/app/index.html" failed (13: Permission denied)
```

Kontrakt proto vyžaduje bit `x` pro `others` na všech nadřazených
adresářích skutečné cesty k `public/` (po rozbalení symlinků, bez kořene
`/`) — typicky `/home/<user>` → `0751`, stejně jako `/opt/shipard/`. Samotné
`x` bez `r` dovolí adresářem jen projít, ne vypsat jeho obsah. Vlastník ani
zbytek módu se nemění a `www-data` se do skupiny uživatele nepřidává.

- `install-packages.sh` v dev módu bit doplní a vypíše
  `Granted traverse (o+x): <cesta>`. V produkci je checkout reálný adresář
  v `/opt/shipard/` a krok se přeskočí.
- `doctor` cestu kontroluje v obou módech a neprůchozí adresář hlásí jako
  chybu s příkazem k opravě.
- `fix-permissions` bit doplní (i v `--dry-run` náhledu).

Skript i `fix-permissions` mění **jen adresáře, které vlastní
shipard-user**. Na neprůchozím adresáři jiného vlastníka skript skončí
chybou a `fix-permissions` ho vypíše mezi „Unfixable issues“ — oprav ho
ručně (`chmod o+x <cesta>`), nebo přesuň checkout.

### Co netvoří součást kontraktu

- `/opt/shipard/shpd/` — symlink (dev) nebo real clone (prod) na project source.
  `install-packages.sh` ho vytvoří jako symlink na `$PROJECT_DIR`.
- `php-fpm` a `nginx` system config (kromě shipard pool / site) — řízeno OS.

### Adresáře zakládané aplikací

Adresáře uvnitř zdroje dat zakládá aplikace přes
`PermissionSpec::ensureDsDir()`: každá nově založená úroveň dostane `0750`
(`PermissionSpec::DS_DIR_MODE`) bez ohledu na umask. Platí to i pro
podadresáře, které matice nevyjmenovává (`att/RRRR/MM/DD/…`,
`branding/avatars/`, `cache/oidc/`). Existující adresáře metoda nemění —
zdroj dat založený starší verzí srovná `fix-permissions`.

Stejně se chová log: `ErrorLogger` při zápisu založí chybějící adresář logu
s `0750` a chybějící `shipard.log` s `0640`, obojí bez ohledu na umask.
Soubor zakládá výlučně (uspěje právě jeden ze souběžných procesů), takže
existující adresář ani soubor nemění — log vzniklý starší verzí s `0644`
srovná `fix-permissions`.

Výjimkou je `cache/twig/`, kterou si zakládá knihovna Twig sama s módem
podle umask; leží pod `cache/` (`0750`), takže se do ní nikdo další
nedostane.

### Soubory zapisované pod rootem

Některé příkazy zapisují do adresáře zdroje dat pod rootem (`sudo shpd-ds
ds-state …`, `shpd-server ds-cron`, `cron-install`). Adresáře zdroje dat
nemají setgid, takže nový soubor by patřil `root:root` a shipard-user by
ho nepřečetl. `config/state.json` proto zapisovatel před `rename` srovná
podle vlastníka `config/main.json` (`Core\Server\FileOwnership`,
[ds-state.md](../ds-state.md) §3); když to nejde, zápis skončí chybou
a původní soubor zůstane. Soubory z doby před touto opravou srovná
`fix-permissions`, `doctor` je hlásí jako `owner root, expected
<shipard-user>`. Stejnou cestou jde nový klíč z `ds-secrets-rotate`
(`sudo` je u něj dokumentované; selhání zastaví rotaci před změnou DB,
[secrets.md](secrets.md) §3) a `config/main.json` přepsaný agentem
`hosting-sync` při doplnění `auth.providers` (ruční běh `sudo shpd-server
hosting-sync`; vzorem je adresář `config/`, protože přepisovaný
`main.json` může nést špatného vlastníka z dřívějšího běhu a nemůže být
vzorem sám sobě; selhání = krok provisioningu `failed`, původní soubor
zůstává, [hosting.md](../hosting.md) §5.2). Soubory zakládané `SecretsFileWriter`
(`secrets.key` při `ds-create` / `ds-upgrade`, klíče hostingu) srovnává
jeho `alignOwnership()` podle kořene zdroje dat — pod rootem chownuje,
jinak jen varuje.

### Recursive ownership

Adresáře `log/`, `config/configuration/`, `att/`, `branding/`, `cache/` a `secrets/` mají
**rekurzivní kontrolu vlastnictví** — `doctor` projde jejich obsah a hlásí
ownership mismatches u všech souborů (i v nested adresářích). `fix-permissions`
je rekurzivně chownuje.

Mode souborů uvnitř těchto adresářů **není vynucován** — různé typy obsahu
mají různé konvence (JSON configy, uploadované PDF, cache thumbnaily, log
rotace) a nepatří do kontraktu. Pokud máš striktní požadavek na mode, řeš
přes umask v aplikační vrstvě.

## Diagnostika a oprava

### `shpd-server doctor`

Read-only kontrola. Vypíše:
- mode (z `/etc/shipard/server.json`)
- shipard-user (detekce z owner `/opt/shipard/`)
- PHP-FPM pool user (z `/etc/php/*/fpm/pool.d/shipard.conf`)
- Per-cesta: existence + type + owner + group + mode
- Průchod nginx ke checkoutu: `o+x` na nadřazených adresářích
  `/opt/shipard/shpd/public`
- Per-DS: pokus o DB connection (`SELECT 1`)

Exit kódy:
- `0` — vše OK
- `1` — alespoň jeden issue

### `sudo shpd-server fix-permissions`

Aplikuje fixable issues. `chown`/`chgrp`/`chmod`, žádné mazání ani
vytváření. Mimo `/opt/shipard` a `/etc/shipard` sahá jen na nadřazené
adresáře checkoutu, a to jen přidáním `o+x`.

```bash
sudo shpd-server fix-permissions --dry-run   # preview, bez sudo lze
sudo shpd-server fix-permissions             # apply (interactive confirm)
sudo shpd-server fix-permissions --force     # apply bez konfirmace
```

Bez `--dry-run` a `--force` příkaz čeká na potvrzení „Proceed? [y/N]“.
V neinteraktivním shellu (skript, vzdálený nástroj) proto visí do timeoutu —
tam jen `--dry-run`, nebo `--force`.

Cesty které neexistují (chybějící povinné, např. `/opt/shipard/`) jsou
označené jako `fixable: false` — `fix-permissions` je nevytvoří. Použij
`install-packages.sh` pro initial setup.

## Migrace ze staršího layoutu

Pokud máš ručně postavený starý layout (typicky `sebik:www-data` group
hack s `chmod 775`), proveď:

```bash
sudo bash scripts/install-packages.sh --mode=development
sudo shpd-server fix-permissions --dry-run    # review
sudo shpd-server fix-permissions              # apply
shpd-server doctor                            # verify
```

`install-packages.sh` přepíše `/etc/nginx/sites-enabled/development.conf`
(uloží do `.disabled-TIMESTAMP`), vytvoří `shipard` PHP-FPM pool,
nastaví ownership na `/opt/shipard` a `/etc/shipard`.
`fix-permissions` projde existující DS a sjednotí ownership/mode.
