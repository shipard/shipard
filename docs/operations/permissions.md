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
| `/opt/shipard/` | shipard-user | shipard-user | `0751` | root datový adresář; `others=x` aby nginx mohl traversovat do `/opt/shipard/shpd/public` |
| `/opt/shipard/data-sources/` | shipard-user | shipard-user | `0750` | parent všech DS |
| `/opt/shipard/data-sources/<id>/` | shipard-user | shipard-user | `0750` | per-DS root |
| `/opt/shipard/data-sources/<id>/config/` | shipard-user | shipard-user | `0750` | |
| `/opt/shipard/data-sources/<id>/config/main.json` | shipard-user | shipard-user | `0600` | obsahuje DB heslo |
| `/opt/shipard/data-sources/<id>/config/configuration/` | shipard-user | shipard-user | `0750` | compiled configs |
| `/opt/shipard/data-sources/<id>/secrets/` | shipard-user | shipard-user | `0700` | striktní |
| `/opt/shipard/data-sources/<id>/secrets/secrets.key` | shipard-user | shipard-user | `0600` | encryption key pro `encrypted_text` |
| `/opt/shipard/data-sources/<id>/att/` | shipard-user | shipard-user | `0750` | uploady |
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

### Recursive ownership

Adresáře `log/`, `config/configuration/`, `att/`, `cache/` a `secrets/` mají
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
