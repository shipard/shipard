# Produkční instalace

Postup pro nasazení Shipardu do produkčního (ostrého) provozu na čistém
Ubuntu LTS. Vývojové prostředí řeší [`../dev/linux-install.md`](../dev/linux-install.md);
tenhle dokument popisuje produkční mód, který se od dev liší ve třech věcech:

- běží pod dedikovaným systémovým uživatelem `shipard` (ne pod tvým účtem),
- servíruje se přes HTTPS na doméně (ne přes HTTP na IP),
- datové zdroje se zakládají z CLI — **vývojářský dashboard `/_dev/`
  je v produkci vypnutý** (vrací 404).

> **Stav:** Shipard je v alfa fázi. Produkční nasazení je zatím
> ověřované v praxi — ber tenhle postup jako výchozí a počítej s ručním
> doladěním, hlavně u nginx/TLS.

---

## Předpoklady

- **Ubuntu LTS** — nové servery **26.04**; 24.04 zůstává podporovaná pro
  stávající servery, které se postupně upgradují
- **root přístup** přes `sudo`
- **doména** směřující na server a **TLS certifikát** (řešíš ručně — viz
  kapitola 6)

---

## 1. Stažení repozitáře

V produkci klonuj rovnou do datového rootu (ne do `~`):

```bash
sudo mkdir -p /opt/shipard
sudo git clone https://github.com/shipard/shipard.git /opt/shipard/shpd
```

(Ownership se srovná v dalším kroku instalačním skriptem.)

---

## 2. Instalace systémových balíčků a setup

```bash
cd /opt/shipard/shpd
sudo bash scripts/install-packages.sh --mode=production
```

Oproti dev módu skript navíc **vytvoří systémového uživatele `shipard`**
(`useradd --system --shell /usr/sbin/nologin --home-dir /opt/shipard`),
pod kterým poběží PHP-FPM pool. Dále nainstaluje PHP 8.5 (na 24.04 z PPA
`ondrej/php`, na 26.04 ze systémových repozitářů), MariaDB, nginx,
composer, Node.js 24 (LTS, z NodeSource — pro build frontendu; už
nainstalovaný Node ≥ 22 se ponechá), vytvoří `/opt/shipard/`
a `/etc/shipard/` a aktivuje nginx site ze šablony
`docs/nginx/production.conf`. Na jiné verzi systému skript skončí chybou
dřív, než cokoli nainstaluje.

Permission kontrakt je stejný single-user model jako v dev, jen je OS
uživatel `shipard` — detaily v
[`permissions.md`](permissions.md).

**Převlastni clone na uživatele `shipard`.** V kroku 1 jsi klonoval jako
root, takže repozitář patří `root:root`. Instalační skript samotný clone
**nepřevlastňuje** (počítá s tím, že už patří shipard uživateli) — a když
`/opt/shipard/shpd` existuje jako skutečný adresář, nechá ho být. Sjednoť
vlastnictví ručně, jinak `composer install` ani pozdější `git pull` pod
uživatelem `shipard` nezapíšou (`Permission denied` při zápisu do `vendor/`):

```bash
sudo chown -R shipard:shipard /opt/shipard/shpd
```

(`chown -R` zahrne i `.git`, takže `sudo -u shipard git pull` nebude hlásit
„dubious ownership".)

---

## 3. Instalace PHP závislostí

```bash
cd /opt/shipard/shpd
sudo -u shipard composer install --no-dev --optimize-autoloader
```

`--no-dev` vynechá vývojové závislosti, `--optimize-autoloader` sestaví
classmap. Instalační skript composer **nespouští** — je to samostatný krok.

Na Ubuntu 24.04 vypíše composer při každém spuštění stovky řádků
`Deprecation Notice: Constant E_STRICT is deprecated…` — composer z apt
(2.7.1) je starší než PHP 8.5. Výpisy jsou neškodné, instalace proběhne
v pořádku; na 26.04 se neobjevují.

---

## 4. Build frontendu

Frontend (Svelte 5 SPA) se sestavuje do `public/app` a nginx ho odtud
servíruje. Node.js pro build nainstaloval instalační skript v kroku 2. Sestav frontend:

```bash
cd /opt/shipard/shpd/frontend
sudo -u shipard npm ci
sudo -u shipard npm run build
```

`npm ci` je pro produkci vhodnější než `npm install` — instaluje přesně
podle `package-lock.json`. Výstup skončí v `/opt/shipard/shpd/public/app/`
(base cesta `/app/`).

---

## 5. Inicializace server configu

```bash
sudo shpd-server server-init --mode=production --user=shipard
```

Vytvoří `/etc/shipard/server.json` s admin DB credentials (ownership
`root:shipard`, mode `0640`).

---

## 6. nginx a TLS

Instalační skript aktivoval site ze šablony `docs/nginx/production.conf`.
Živý `/etc/nginx/sites-available/shipard.conf` je po instalaci **ručně
spravovaný soubor** — git na něj už nedosáhne. Config se proto dělí na
dvě vrstvy:

### Verzované přes include (spravuje repo)

Systémové parametry žijí ve verzovaných souborech v repu a do site configu
se **includují**. Změna parametru = `git pull` + reload služby, žádné ruční
mergování:

```nginx
# V KAŽDÉM server bloku:
include /opt/shipard/shpd/docs/nginx/shipard-common.conf;

# NAVÍC jen v HTTPS (listen 443 ssl) blocích:
include /opt/shipard/shpd/docs/nginx/shipard-tls.conf;
```

- `shipard-common.conf` — systémové parametry pro všechny bloky
  (`client_max_body_size 128M` — bez něj padají uploady příloh na 413).
- `shipard-tls.conf` — TLS policy (TLSv1.3, session cache, HSTS).
  **Patří výhradně do 443 ssl bloků** — HSTS hlavička poslaná přes plain
  HTTP je chyba.

Šablony include řádky už obsahují; při ruční tvorbě nebo úpravě configu
je nezapomeň doplnit. `shpd-server doctor` chybějící include hlásí jako
warning; `shpd-server upgrade` po změně těchto souborů nginx sám reloadne
(viz §11). Stejný pattern má PHP-FPM pool — `include=` na
`docs/php/shipard-fpm-common.conf` (upload limity), generuje ho
instalační skript. Třetí upgrade-spravovaný systémový soubor je
`/etc/cron.d/shipard` — generuje ho `shpd-server cron-install`
(idempotentně, marker s verzí šablony), viz §10.

### Ruční část (per-server, git se jí nedotýká)

- **TLS certifikáty** — šablona má `listen 443 ssl;`, ale žádné
  `ssl_certificate` / `ssl_certificate_key`. Bez nich `nginx -t`
  neprojde. Doplň cesty ke svým certifikátům.
- **HTTP → HTTPS redirect** — přidej `server { listen 80; ... return 301
  https://$host$request_uri; }` (a případně location pro ACME challenge,
  pokud budeš certy obnovovat automaticky). Do tohoto bloku TLS include
  nepatří.
- **`server_name`** — uprav z `*.shipard.cz` na svou doménu.

Poznámka: `fastcgi_pass` v šabloně míří na výchozí socket, ale instalační
skript ho při nasazení automaticky přepíše na socket poolu `shipard`.
Ověř výsledný `/etc/nginx/sites-available/shipard.conf`.

Po úpravách:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 7. Ověření

```bash
sudo -u shipard shpd-server doctor
```

Spouštěj doctor **jako uživatel `shipard`** — konfigurace `/etc/shipard`
je `root:shipard 0750`, takže tvůj přihlašovací účet do ní bez členství
ve skupině `shipard` nevidí (a doctor by mylně hlásil „config missing").

Vypíše report: mode (musí být `production`), shipard-user, PHP-FPM pool
user, kontrolu cest a DB konektivitu. Exit 0 = OK.

Kdyby doctor hlásil problém s právy:

```bash
sudo shpd-server fix-permissions --dry-run
sudo shpd-server fix-permissions
```

---

## 8. Vytvoření datového zdroje a uživatele

V produkci se DS zakládají z CLI. Spouštěj je **jako uživatel `shipard`**,
aby soubory vznikly se správným ownership:

```bash
# 1) Nový datový zdroj (default instalační modul install.base)
sudo -u shipard shpd-server ds-create --name "Moje firma" --module install.base
```

DS vznikne v `/opt/shipard/data-sources/<id>`. Do jeho adresáře přejdi a
dokonči inicializaci:

```bash
cd /opt/shipard/data-sources/<id>

# 2) Synchronizace DB schématu podle modulů
sudo -u shipard shpd-ds ds-upgrade

# 3) Založení admin uživatele
sudo -u shipard shpd-ds user-create \
    --login admin \
    --password 'ZVOL_SILNE_HESLO' \
    --name 'Správce' \
    --email admin@example.cz

# 4) Ověřit práva — ds-create nastavuje kontraktní módy (0750) sám;
#    fix-permissions je záchranná brzda pro starší DS nebo ruční zásahy
sudo shpd-server fix-permissions
```

`ds-create` sám nespouští `ds-upgrade` ani nezakládá uživatele — jsou to
samostatné kroky (stejný sled, jaký v dev provádí dashboard).

`secrets.key` pro šifrování citlivých dat se vytvoří automaticky při
`ds-create` (mode `0600 shipard:shipard`). Viz [`secrets.md`](secrets.md).

> **Práva čerstvého DS:** `ds-create` nastavuje adresářům kontraktní módy
> (0750) explicitně a při běhu pod rootem (hosting provisioning) strom
> rovnou chownuje na uživatele shipard. `sudo shpd-server fix-permissions`
> (krok 4 výše) tak slouží jako záchranná brzda pro DS založené staršími
> verzemi nebo po ručních zásazích. Na závěr ověř
> `sudo -u shipard shpd-server doctor`.

Datový zdroj je pak dostupný na `https://<tvoje-doména>/<id>/app/`.

> **Testovací DS na produkci (`enableReset`):** `shpd-ds ds-reset` na
> produkčním serveru tvrdě odmítá. Zdroj dat určený k opakovaným importním
> testům označ `"enableReset": true` v jeho `config/main.json` — jen pro něj
> se reset povolí (s hlasitým warningem, konfirmace zůstává). Před přechodem
> DS do ostrého provozu flag odstraň; `shpd-server doctor` na zapomenutý flag
> upozorňuje. Viz [`cli.md`](../cli.md) § `ds-reset`.

---

## 9. Namapování domény na datový zdroj

V produkci aplikace vybírá datový zdroj podle **hostname** požadavku. Mapu
`host → ds-id` drží soubor `/etc/shipard/domains.json` a spravuje ji CLI:

```bash
# Přiřaď doménu datovému zdroji (spouštěj jako root — zapisuje do /etc/shipard)
sudo shpd-server domain-add --host data-source-name.example.com --ds <id>

# Výpis všech mapování
sudo shpd-server domain-list

# Zrušení mapování
sudo shpd-server domain-remove --host data-source-name.example.com
```

Mapa se čte při každém požadavku, takže po `domain-add` není potřeba nic
restartovat — stačí obnovit stránku. Aplikace pak běží na
`https://<host>/app/`.

Předpoklady, aby to fungovalo:

- **DNS** hostu míří na server.
- **nginx `server_name`** pokrývá tento host (viz kapitola 6).
- **Datový zdroj existuje** (kapitola 8) — `domain-add` jeho existenci ověří.

> Pozn.: dev režim (přístup přes IP adresu) domény neřeší — tam se ID
> zdroje bere z první části cesty (`/{id}/app/`). `domains.json` se uplatní
> jen u přístupu přes hostname. Když host v mapě není (nebo mapa ještě
> neexistuje), aplikace vrátí `404 Unknown host`.

> **Servery s provisioning agentem** (sekce `hosting` v `server.json`):
> `domain-add` pouští cron agent jako shipard user a atomický zápis
> (tmp + rename) vyžaduje zápis na **adresář** mapy — root-managed
> `/etc/shipard` agentovi nestačí. Přesměruj umístění klíčem
> `domainsFile` v `server.json` na app-writable cestu (např.
> `/opt/shipard/domains.json`) a soubor tam přesuň; stejný klíč čte
> HTTP resolver i všechny `domain-*` commandy (`dataSources` funguje
> obdobně pro adresář zdrojů dat). Nezapisovatelný adresář hlásí
> `shpd-server doctor`.

---

## 10. Zálohování a provoz

- **Databáze:** pravidelný dump (mariadb-dump) všech DS databází.
- **`secrets/` adresáře:** `/opt/shipard/data-sources/<id>/secrets/`
  **musí** být součástí zálohy. **Ztráta `secrets.key` = ztráta všech
  šifrovaných dat** (API klíče, tokeny) daného DS. Tarball adresáře DS
  klíč zahrnuje; po obnově ověř permissions a spusť `shpd-ds
  ds-secrets-health`. Detaily v [`secrets.md`](secrets.md).
- **Logy:** `/opt/shipard/log/shipard.log` (viz [`../logging.md`](../logging.md)).
- **Firewall:** ven vystav jen 80/443 (a SSH). PHP-FPM socket je lokální.
- **Cron:** všechny periodické úlohy (outbox worker, alerts runner,
  prune joby) běží přes generovaný `/etc/cron.d/shipard` — čtyři sloty
  volají dispatcher `shpd-server cron --slot=…`, který sám iteruje
  aktivní DS. Soubor zapisuje `shpd-server upgrade` (resp. `shpd-server
  cron-install`), žádné ruční cron řádky se nespravují a
  `ds-create`/`ds-delete` regeneraci nevyžadují. Dispatcher loguje do
  `shipard.log`, stdout slotů jde do `/opt/shipard/log/cron.log`
  (rotovaný stávajícím logrotate pravidlem pro `/opt/shipard/log/*.log`).
  Živost hlídá `shpd-server doctor` (sekce Cron: existence + verze
  souboru, stáří heartbeatů v `/opt/shipard/run/`). Sloty a per-DS
  příkazy viz [`../cli.md`](../cli.md) § `cron`.

  Mail relay se konfiguruje klíčem `mail.relay` v `server.json` (per-DS
  override v `main.json`); stav fronty hlídá `shpd-server doctor` a alert
  check `core.mail.outbox_health`.

- **Pojistka odchozí pošty (`mail.safety`):** klíč v `server.json` vedle
  `mail.relay`, jen na úrovni serveru. Rozhoduje, jestli pošta dojde
  skutečným příjemcům — režimy a přesné chování viz
  [`../mail/outbound.md`](../mail/outbound.md) § Pojistka.

  | Server | Nastavení |
  |--------|-----------|
  | produkční | nic — bez sekce a s `mode: production` pošta odchází beze změny |
  | testovací (kopie ostrých dat) | **vždy výslovně** `redirect` na týmovou adresu, i když běží v `mode: production` |
  | vývojový | bez sekce nic neodejde (`drop`); kdo chce poštu vidět, nastaví `redirect` na svou adresu nebo `allowlist` s vlastní doménou |

  ```json
  "mail": {
      "relay":  { "host": "relay.example.com" },
      "safety": { "mode": "redirect", "redirectTo": "testy@example.com" }
  }
  ```

  Chybná sekce (neznámý režim, `redirect` bez `redirectTo`, neplatná
  adresa) znamená, že neodejde nic — i na produkčním serveru. Po každé
  úpravě ověř `shpd-server doctor` (řádek `Mail safety` v sekci Outbound
  mail) a `shpd-ds mail-send-test --to <adresa>`. Konkrétní adresu
  přesměrování drž jen v `server.json`, ne ve veřejných textech.

---

## 11. Nasazení nové verze

Nasazení orchestruje `shpd-server upgrade` — jedno spuštění pod rootem:

```bash
sudo shpd-server upgrade --dry-run   # náhled: příchozí commity + plán kroků
sudo shpd-server upgrade             # reálný běh
```

Příkaz provede (kroky přes `sudo -u shipard -H`, doctor přímo jako root):

1. `git fetch` + pre-flight — čistý worktree povinný, `git pull --ff-only`
2. `composer install --no-dev --optimize-autoloader` — jen když se změnil
   `composer.json`/`composer.lock` (nebo `--full`)
3. frontend build (`npm ci && npm run build`) — jen při změně pod `frontend/`
   (nebo `--full`)
4. `shpd-server ds-upgrade-all` (vynechatelné přes `--skip-ds-upgrade`)
5. `shpd-server cron-install` — idempotentní regenerace
   `/etc/cron.d/shipard` + `/opt/shipard/run` (viz §10). Běží jen pod
   rootem; jinak příkaz vypíše ruční `sudo` příkaz.
6. `shpd-server completion-install` — idempotentní instalace bash
   completion obou binárek do `/etc/bash_completion.d`. Běží jen pod
   rootem; jinak příkaz vypíše ruční `sudo` příkaz.
7. reload služeb — jen při změně verzovaných systémových confů (viz §6):
   `docs/nginx/**` → `nginx -t && systemctl reload nginx`, `docs/php/**` →
   `systemctl reload php<ver>-fpm`. Běží jen pod rootem; jinak příkaz
   vypíše ruční `sudo` příkazy.
8. `shpd-server doctor`

Bez příchozích commitů skončí `Already up to date.`. Selhání kroku běh
zastaví (žádný automatický rollback) — dokonči ruční kroky níže. Selhání
doctoru vrátí FAILURE, ale kód už je nasazený.

`composer.lock` je verzovaný — server instaluje přesně verze z repozitáře.
Na serveru proto nikdy nespouštěj `composer update`: změnil by lock,
worktree by přestal být čistý a další `upgrade` by skončil v pre-flightu.
Když se to stane, vrať lock a nainstaluj z něj:

```bash
cd /opt/shipard/shpd
sudo -u shipard git checkout composer.lock
sudo -u shipard composer install --no-dev --optimize-autoloader
```

**Ruční fallback** (co příkaz dělá pod kapotou):

```bash
cd /opt/shipard/shpd
sudo -u shipard git pull
sudo -u shipard composer install --no-dev --optimize-autoloader
(cd frontend && sudo -u shipard npm ci && sudo -u shipard npm run build)
sudo -u shipard shpd-server ds-upgrade-all
sudo shpd-server cron-install
sudo shpd-server completion-install
sudo shpd-server doctor
```

> Pozn.: `scripts/dev-update.sh` je určený pro vývoj (`npm install`, bez
> `--no-dev`) — v produkci použij `shpd-server upgrade`.

> Pozn. k opcache: default `opcache.validate_timestamps=1` znamená, že
> PHP-FPM si změněné soubory načte sám — reload FPM po upgradu není potřeba.
> Kdyby se validace timestampů někdy vypnula, patří na konec upgradu
> `systemctl reload php8.5-fpm`.
