# Průvodce vývojáře — Shipard

Vítej v projektu Shipard! Tenhle dokument tě provede od nuly k funkčnímu vývojovému prostředí.

> **Stav projektu:** Shipard je v **alfa fázi**. Backend, REST API i Svelte
> frontend běží, ale věci se ještě aktivně mění a místy něco zaskřípe.
> Když na něco narazíš, dej nám vědět — viz poslední kapitola
> [Něco nefunguje?](#9-něco-nefunguje).

---

## Požadavky

- **Ubuntu LTS** — pro novou instalaci **26.04**; 24.04 zůstává podporovaná
  pro stávající instalace
- **MariaDB ≥ 10.10** — hledání bez diakritiky používá collation `uca1400`
  (obě podporované verze Ubuntu požadavek splňují)
- **git** (obvykle předinstalovaný — pokud není, `sudo apt install git`)
- **root přístup** přes `sudo` pro one-time instalaci

---

## 1. Stažení repozitáře

```bash
git clone https://github.com/shipard/shipard.git ~/sw/shpd
cd ~/sw/shpd
```

Klon přes HTTPS funguje i bez SSH klíče na GitHubu; kdo bude pushovat, může
klonovat rovnou přes SSH (`git@github.com:shipard/shipard.git`) nebo si
`remote` přepnout později (`git remote set-url origin …`).

---

## 2. Instalace systémových balíčků a setup

```bash
sudo bash scripts/install-packages.sh --mode=development
```

Skript je idempotentní a zařídí:

- Instalaci PHP 8.5 s rozšířeními, MariaDB, nginx, composeru a Node.js 24
  (LTS), pokud už není nainstalovaný Node ≥ 22. Na Ubuntu 24.04 se PHP bere
  z PPA `ondrej/php`, na 26.04 ze systémových repozitářů. Na jiné verzi
  systému skript skončí chybou dřív, než cokoli nainstaluje
- Vytvoření `/opt/shipard/` (datový root) a `/etc/shipard/` (config root)
  s ownership vlastněným tvým uživatelem (detekce přes `$SUDO_USER`)
- Konfiguraci samostatného **PHP-FPM poolu `shipard`** běžícího pod tvým
  uživatelem (žádný group hack se `www-data`)
- Symlink `/opt/shipard/shpd` → tento clone (kvůli nginx root path)
- Aktivaci nginx site `shipard.conf` (existující `development.conf` se
  uloží jako `.disabled-TIMESTAMP`)

Permission kontrakt je popsán v
[`docs/operations/permissions.md`](docs/operations/permissions.md).

---

## 3. Závislosti a build frontendu

```bash
bash scripts/dev-update.sh
```

Skript spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Bez buildu frontendu by aplikace `/{ds-id}/app/` neměla
co servírovat. Spouštěj ho pod svým uživatelem, ne přes `sudo`. Závěrečnou
výzvu k `ds-upgrade-all` můžeš při prvním setupu ignorovat — žádný datový
zdroj ještě neexistuje. Stejný skript budeš pouštět po každém `git pull`
(kapitola 7).

Na Ubuntu 24.04 vypíše composer při každém spuštění stovky řádků
`Deprecation Notice: Constant E_STRICT is deprecated…` — composer z apt
(2.7.1) je starší než PHP 8.5. Výpisy jsou neškodné, instalace proběhne
v pořádku; na 26.04 se neobjevují.

`composer.lock` je verzovaný, `composer install` tak všude nainstaluje stejné
verze balíků. Závislost přidávej přes `composer require <balík>`, verzi měň
přes `composer update <balík>`; po ruční úpravě `composer.json` (např.
`ext-*`) přepočti lock příkazem `composer update --lock`. `composer.json`
a `composer.lock` patří vždy do stejného commitu.

---

## 4. Inicializace server configu

```bash
sudo shpd-server server-init --mode=development
```

Vytvoří `/etc/shipard/server.json` s admin DB credentials. Soubor má
ownership `root:<tvůj-user>` a mode `0640` — root ho edituje, ty čteš
přes group membership.

---

## 5. Ověření

```bash
shpd-server doctor
```

Vypíše report: mode, shipard-user, PHP-FPM pool user, kontrolu cest,
DB connection per DS. Exit 0 = vše OK.

Pokud něco nesouhlasí:

```bash
sudo shpd-server fix-permissions --dry-run    # preview
sudo shpd-server fix-permissions              # apply
```

Až je doctor zelený, otevři vývojářský dashboard (viz kapitola 6) — odtud
už můžeš vytvořit první datový zdroj a aplikaci otevřít.

---

## 6. Vývojářský dashboard

V development módu běží na kořeni serveru jednoduchý webový dashboard,
který shrnuje vše, co při testování potřebuješ — bez ručního skládání URL
a hledání ID datových zdrojů v terminálu.

Otevři v prohlížeči:

```
http://<adresa-serveru>/_dev/
```

Kořen `/` se v dev módu automaticky přesměruje na `/_dev/`, takže stačí
zadat jen adresu serveru. V produkčním módu dashboard neexistuje —
`/_dev/...` vrací 404.

Co dashboard umí:

- **Seznam datových zdrojů** — všechny DS s názvem, datem vytvoření a
  databází. U každého tlačítka **Open** (otevře aplikaci `/{ds-id}/app/`
  v novém tabu), **Logs** a **Upgrade**. ID lze jedním klikem zkopírovat
  do schránky. Seznam se sám obnovuje.
- **+ New DS** — vytvoření nového datového zdroje rovnou z formuláře
  (výběr instalačního modulu, admin login a heslo, volitelně testovací
  data). Průběh (`ds-create` → `ds-upgrade` → `user-create` → příp. seed)
  se streamuje živě do stránky; po dokončení vede odkaz přímo do nového DS.
- **Logs** — prohlížeč logu (`/opt/shipard/log/shipard.log`) s filtrováním
  podle úrovně, datového zdroje a fulltextu, auto-refresh ve stylu
  `tail -f` a rozbalitelný detail záznamu včetně exception trace.
- **Doctor** — spustí `shpd-server doctor` a zobrazí report.
- **Upgrade All** — spustí `shpd-server ds-upgrade-all` na všech DS.

Dashboard je chráněný pouze kontrolou `mode: development` — počítá s tím,
že **vývojový server běží v důvěryhodné síti**. Oranžový banner
„DEVELOPMENT MODE" nahoře je připomínka, ať se prostředí neplete
s produkčním.

---

## 7. Po `git pull`

Po každém stažení nové verze:

```bash
bash scripts/dev-update.sh
```

Skript vždy spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Všechny kroky jsou idempotentní — pokud se nic
nezměnilo, projdou během pár sekund.

Pokud se měnily definice tabulek nebo cfgItems modulů, je potřeba
zaktualizovat i datové zdroje:

```bash
shpd-server ds-upgrade-all
```

(Totéž lze spustit tlačítkem **Upgrade All** ve vývojářském dashboardu —
viz kapitola 6.)

### Automatizace přes git hooks (volitelné)

```bash
git config core.hooksPath .githooks
```

Stačí spustit jednou v repu.

---

## 8. CLI utility

Přehled v [`docs/cli.md`](docs/cli.md). Po základním setupu nepotřebuješ
`sudo` pro běžné shipard operace.

### `shpd-server` — správa serveru

| Příkaz | Popis |
|--------|-------|
| `shpd-server version` | Verze aplikace |
| `shpd-server help` | Nápověda |
| `shpd-server ds-create --name <název>` | Vytvoří nový datový zdroj |
| `shpd-server ds-upgrade-all` | Spustí `ds-upgrade` na všech DS |
| `shpd-server doctor` | Health check kontraktu a DB konektivity |
| `sudo shpd-server fix-permissions` | Opraví ownership/mode dle kontraktu |
| `sudo shpd-server server-init` | Inicializace server configu |
| `shpd-server next-table-id` | Vrátí další volné tableId |

### `shpd-ds` — správa datového zdroje

Spouštěj z adresáře datového zdroje (musí obsahovat `config/main.json`).

| Příkaz | Popis |
|--------|-------|
| `shpd-ds version` | Verze aplikace |
| `shpd-ds help` | Nápověda |
| `shpd-ds ds-upgrade` | Synchronizuje DB schéma podle modulů |
| `shpd-ds ds-secrets-health` | Kontrola encryption key pro `encrypted_text` |
| `shpd-ds ds-secrets-rotate` | Rotace encryption key |

---

## 9. Něco nefunguje?

Většinu potíží s rozchozením odhalí health check:

```bash
shpd-server doctor
```

Pokud hlásí problém s právy:

```bash
sudo shpd-server fix-permissions --dry-run    # co by se změnilo
sudo shpd-server fix-permissions              # oprav
```

Logy aplikace najdeš ve vývojářském dashboardu pod **Logs**
(`/_dev/logs/`) — s filtrováním podle úrovně a fulltextu — nebo přímo
v souboru `/opt/shipard/log/shipard.log`.

Když po `git pull` aplikace hlásí nesoulad schématu, „dojely" ti definice
tabulek — spusť `shpd-server ds-upgrade-all` (nebo **Upgrade All**
v dashboardu).

Pořád to nejde? Založ issue na
[GitHubu](https://github.com/shipard/shipard/issues) s výstupem
`shpd-server doctor` a relevantními řádky z logu — díky tomu to
rozklíčujeme nejrychleji.

A když jde jen o rychlý dotaz, na který se nechce zakládat issue —
„je tohle záměr, nebo bug?“, „jak se dělá X?“ — stav se na našem
**[Discordu](https://discord.gg/PWTt5EUFAV)**. Je nás tam málo, ale
odpovídáme ochotně a rychle.

---

## 10. Kam dál

- **Architektura projektu:** [`docs/architecture.md`](docs/architecture.md)
- **Modulový systém:** [`docs/modules.md`](docs/modules.md)
- **Definice databázových tabulek:** [`docs/table-definitions.md`](docs/table-definitions.md)
- **Permission kontrakt:** [`docs/operations/permissions.md`](docs/operations/permissions.md)
- **CLI reference:** [`docs/cli.md`](docs/cli.md)
- **Přehled dokumentace:** [`docs/README.md`](docs/README.md)
- **Hlavní README:** [`README.md`](README.md)
