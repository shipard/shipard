# Ruční instalace na Linuxu

Návod pro toho, kdo má vlastní Linux server, nebo Ubuntu přímo v počítači.
Na Macu a ve Windows je cesta jiná — Ubuntu ve virtuálním stroji
a instalace jedním příkazem podle [`local-dev.md`](local-dev.md).

Totéž, co je tady rozepsané po krocích, udělá jedním příkazem
[`scripts/dev-bootstrap.sh`](../../scripts/dev-bootstrap.sh) — kroky 1–5
níže spouští za sebou. Tenhle dokument zůstává referencí pro toho, kdo chce
každý krok vidět a řídit sám.

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

Klon přes HTTPS funguje i bez SSH klíče na GitHubu. Jak `remote` přepnout,
až budeš chtít posílat změny, říká
[`dev-daily.md`](dev-daily.md#5-až-budeš-chtít-posílat-změny) (oddíl 5).

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
[`docs/operations/permissions.md`](../operations/permissions.md).

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
([`dev-daily.md`](dev-daily.md#3-po-git-pull), oddíl 3).

Na Ubuntu 24.04 vypíše composer při každém spuštění stovky řádků
`Deprecation Notice: Constant E_STRICT is deprecated…` — composer z apt
(2.7.1) je starší než PHP 8.5. Výpisy jsou neškodné, instalace proběhne
v pořádku; na 26.04 se neobjevují.

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

Až je doctor zelený, otevři vývojářský dashboard
([`dev-daily.md`](dev-daily.md#1-vývojářský-dashboard), oddíl 1) — odtud
už můžeš vytvořit první datový zdroj a aplikaci otevřít.

---

## Kam dál

- [`dev-daily.md`](dev-daily.md) — vývojářský dashboard, první zdroj dat,
  co dělat po `git pull`
- [`claude-code-intro.md`](claude-code-intro.md) — Claude Code pro
  začátečníky
- zpět na úvod a rozcestník: [`DEVELOPERS.md`](../../DEVELOPERS.md)
