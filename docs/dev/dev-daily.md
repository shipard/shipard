# První kroky a každodenní práce

Společný návod pro všechny tři cesty instalace — Multipass na Macu a WSL
ve Windows ([`local-dev.md`](local-dev.md)) i vlastní Linux
([`linux-install.md`](linux-install.md)). Předpokládá hotovou instalaci se
zeleným `shpd-server doctor`.

---

## 1. Vývojářský dashboard

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

## 2. První zdroj dat

Zdroj dat je jedna firma — vlastní databáze a soubory. Založíš ho
v dashboardu:

1. Klikni na **+ New DS**.
2. Vyplň **Name**, **Admin login** a **Admin password**; jazyk, zemi
   a instalační modul můžeš nechat, jak jsou.
3. Zaškrtni **Seed test data** — dostaneš ukázkové osoby a poštu.
4. **Create Data Source**. Průběh se vypisuje do stránky; na konci je odkaz
   **Open data source →**.
5. Přihlas se loginem a heslem z kroku 2.

Zdroj dat pak najdeš v seznamu na dashboardu pod tlačítkem **Open**.

---

## 3. Po `git pull`

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
viz oddíl 1.)

### Automatizace přes git hooks (volitelné)

```bash
git config core.hooksPath .githooks
```

Stačí spustit jednou v repu. Kdo prostředí rozjel skriptem
`dev-bootstrap.sh`, má hooky zapnuté už od něj — `dev-update.sh` se po
`git pull` spustí sám.

---

## 4. Závislosti

`composer.lock` je verzovaný, `composer install` tak všude nainstaluje stejné
verze balíků. Závislost přidávej přes `composer require <balík>`, verzi měň
přes `composer update <balík>`; po ruční úpravě `composer.json` (např.
`ext-*`) přepočti lock příkazem `composer update --lock`. `composer.json`
a `composer.lock` patří vždy do stejného commitu.

---

## 5. Až budeš chtít posílat změny

Checkout z návodů je stažený přes HTTPS — funguje i bez SSH klíče na
GitHubu. Kdo bude pushovat, může klonovat rovnou přes SSH
(`git@github.com:shipard/shipard.git`) nebo si `remote` přepnout později
(`git remote set-url origin …`).

Jak práci poslat — fork, větev a pull request — popisuje
[`claude-code-intro.md`](claude-code-intro.md#8-repozitář-větve-a-odeslání-práce)
(kapitola 8).

---

## 6. Příkazy

Přehled všech příkazů `shpd-server` a `shpd-ds` i pomocných skriptů je
v [`cli.md`](../cli.md). Po základním setupu nepotřebuješ `sudo` pro běžné
shipard operace.

---

## 7. Něco nefunguje?

Většinu potíží s rozchozením odhalí health check:

```bash
shpd-server doctor
```

Pokud hlásí problém s právy:

```bash
sudo shpd-server fix-permissions --dry-run    # co by se změnilo
sudo shpd-server fix-permissions              # oprav
```

`fix-permissions` se před opravou ptá na potvrzení. Ve skriptu nebo přes
vzdálený nástroj, kde není kdo by odpověděl, použij `--dry-run`, případně
`--force`.

Logy aplikace najdeš ve vývojářském dashboardu pod **Logs**
(`/_dev/logs/`) — s filtrováním podle úrovně a fulltextu — nebo přímo
v souboru `/opt/shipard/log/shipard.log`.

Když po `git pull` aplikace hlásí nesoulad schématu, „dojely" ti definice
tabulek — spusť `shpd-server ds-upgrade-all` (nebo **Upgrade All**
v dashboardu).

**Stovky řádků `Deprecation Notice` od composeru** na Ubuntu 24.04 jsou
neškodné — composer z balíčků je starší než PHP 8.5. Na 26.04 se neobjevují.

Pořád to nejde? Založ issue na
[GitHubu](https://github.com/shipard/shipard/issues) s výstupem
`shpd-server doctor` a relevantními řádky z logu — díky tomu to
rozklíčujeme nejrychleji.

A když jde jen o rychlý dotaz, na který se nechce zakládat issue —
„je tohle záměr, nebo bug?“, „jak se dělá X?“ — stav se na našem
**[Discordu](https://discord.gg/PWTt5EUFAV)**. Je nás tam málo, ale
odpovídáme ochotně a rychle.
