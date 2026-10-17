# První kroky a každodenní práce

Společný návod pro všechny tři cesty instalace — Multipass na Macu a WSL
ve Windows ([`local-dev.md`](local-dev.md)) i vlastní Linux
([`linux-install.md`](linux-install.md)). Předpokládá hotovou instalaci se
zeleným `shpd-server doctor`.

---

## 1. Vývojářský dashboard

V development módu běží na kořeni serveru webový dashboard (Svelte SPA
sestavená do `public/_dev/`, zdroj `frontend/src/dev/`), který shrnuje
vše, co při testování potřebuješ — bez ručního skládání URL a hledání ID
zdrojů dat v terminálu.

Otevři v prohlížeči:

```
http://<adresa-serveru>/_dev/
```

Kořen `/` se v dev módu automaticky přesměruje na `/_dev/`, takže stačí
zadat jen adresu serveru. V produkčním módu dashboard neexistuje —
`/_dev/...` vrací 404. Bez sestaveného frontendu (`bash scripts/dev-update.sh`
nebo `cd frontend && npm run build`) ukáže `/_dev/` jen stránku s návodem,
jak build spustit.

Sekce v horní liště:

- **Zdroje dat** — všechny zdroje dat s názvem, třídou dat (**volný** /
  **reálná kopie**; bez třídy v `main.json` šedě — platí reálná kopie;
  značka **AI** = zdroj používá serverové AI připojení), účelem, datem
  vytvoření a stavem periodických úloh (Cron). Totéž v terminálu:
  `shpd-server ds-list`. U každého řádku **Otevřít** (aplikace
  `/{ds-id}/app/` v novém tabu) a kebab menu **⋮** — jde ovládat
  i klávesnicí (Enter otevře, šipky přepínají, Esc zavře) — s položkami
  **Logy**, **Upgrade**, **Nahrát datovou sadu** (jen volný zdroj dat, a jen
  když je k dispozici sekce Testování), **Kopírovat ID** a **Smazat…** (jen
  volný zdroj dat). Položky, které zrovna nejdou, se v menu neukazují —
  třeba při běžící úloze. **Nahrát datovou sadu**: dialog nabídne sady se
  zemí zdroje dat, ukáže dnešní sadu a upozorní, že reset smaže data —
  uživatelé, nastavení a API klíče zůstanou; jiná než dnešní sada chce
  druhé potvrzení „Nahradit sadu X sadou Y“. **Smazat…**: dialog vypíše,
  co zmizí (databáze, DB uživatel, adresář zdroje dat, přístupová práva
  ostatních DB účtů), varuje, že je to nevratné, a tlačítko Smazat odemkne
  až přesně opsaný název zdroje dat; pak běží úloha na pozadí
  (`shpd-server ds-delete <id> --yes`) a řádek po jejím doběhnutí zmizí.
  Reálnou kopii dashboard smazat nenabídne a server to odmítne
  (`DS_NOT_FREE`) — nejdřív vědomě `shpd-ds ds-class set free`. Běžící
  úloha je vidět v řádku. Pod účelem je vidět, kterou sadu zdroj dat drží.
  Seznam se sám obnovuje (odpočet v hlavičce).
- **Nový zdroj dat** — založení zdroje dat z formuláře (jazyk, země,
  instalační modul, volitelný účel, login a heslo administrátora, v poli
  **Data** volitelně datová sada z `tests/datasets` — země se pak zamkne
  podle sady a prázdný účel se předvyplní „datová sada <název>“); takto
  založený zdroj je ve vývoji **volný** (třída dat `free`). Průběh
  (`ds-create` → `ds-upgrade` → `user-create` s heslem přes stdin → se
  sadou `dataset-seed`) se vypisuje živě do stránky; po dokončení vede
  odkaz přímo do nového zdroje dat. Když selže až seed, zdroj dat i admin
  jsou použitelné — sadu dohraješ akcí **Nahrát datovou sadu**.
- **Testování** — evaluace AI nad testovacími sadami (`tests/datasets`,
  `tests/eval`, `docs/test-corpus.md` § 18): sady, běhy sady, detail běhu,
  detail dokladu s PDF vedle očekávání a výstupu modelu, porovnání dvou
  běhů, doklad napříč běhy. Od F6b se z dashboardu i **spouští** (jen na
  volných zdrojích dat, viz níže): na stránce sady je panel **Zdroje dat
  se sadou** — které zdroje dat sadu drží, zda je aktuální a čistý, akce
  **Přeseedovat**, **Ověřit zaúčtování** (`dataset-verify`), **Spustit
  evaluaci** (odhad ceny → strop útraty → úloha), **Založit evaluační
  zdroj dat** (`ds-create --no-cron` + seed, bez uživatele) a přeseedování
  jiného volného zdroje dat. Dál **Úlohy na pozadí** (seznam, detail
  s průběhem, zrušení) a **Varianty** (editor souborů
  `tests/eval/variants/`). Na serveru bez `tests/` (instalace z balíčku)
  se sekce nenabídne.
- **Logy** — prohlížeč logu (`/opt/shipard/log/shipard.log`) s filtrováním
  podle úrovně, zdroje dat a fulltextu, obnovování ve stylu `tail -f`
  (při rozbaleném záznamu se pozastaví) a detail záznamu včetně trace
  výjimky.
- **Doctor** — spustí `shpd-server doctor` a zobrazí report.
- **Upgrade všech** — spustí `shpd-server ds-upgrade-all` na všech zdrojích
  dat; **Upgrade** u řádku zdroje dat spustí `shpd-ds ds-upgrade` jen pro něj.

Dashboard je chráněný pouze kontrolou `mode: development` — počítá s tím,
že **vývojový server běží v důvěryhodné síti**. Oranžový pruh
„DEVELOPMENT MODE" nahoře je připomínka, ať se prostředí neplete
s produkčním.

### Úlohy na pozadí a pojistky (F6b)

Delší operace z Testování (přeseedování, ověření, evaluace, založení
zdroje dat) a smazání zdroje dat ze seznamu zdrojů dat neběží v HTTP
požadavku, ale jako **úloha na pozadí**: API
zapíše zadání do `/opt/shipard/run/dev-jobs/<id>/` a spustí obal
`shpd-server dev-job-run` (skrytý příkaz, `setsid`), který kroky provede
a průběh ukládá do téhož adresáře (`job.json` zadání, `state.json` stav
a výsledky kroků, `progress.jsonl` průběh `ai-eval`, `output.log` výstup,
`result.json` výsledek `dataset-verify` / `ai-eval --json`). Úloha přežije
zavření prohlížeče; detail se obnovuje sám. Na jednom zdroji dat běží
nejvýš jedna úloha (druhou API odmítne 409 s odkazem na běžící). Starší
než 14 dní se při výpisu mažou.

- **Zrušit** pošle SIGTERM celé skupině procesů: `ai-eval` nezačne další
  volání modelu, rozběhnuté doběhne (až 14 minut při zaseknutém volání)
  a rozpracovaný běh se uloží jako zrušený; seed / verify se přeruší.
  **Vynutit ukončení** = SIGKILL (výsledek se neuloží, zdroj dat může
  zůstat v půlce — pak přeseedovat).
- **Pojistka na serveru** (D60): spouštěcí akce projdou jen v režimu
  `development`, jen na zdroji dat s třídou **`free`** (reálná kopie, chybějící
  nebo rozbitá třída = 403 `DS_NOT_FREE`) a jen pro sadu ze seznamu
  (smazání zdroje dat sadu nemá). UI
  tlačítka u reálných kopií nenabízí, ale ochranu dělá API — žádný parametr
  ji neobchází.
- **Útrata**: evaluace z dashboardu má vždy strop útraty (`--max-cost`,
  předvyplněný 1,5× odhad; bez něj API úlohu nespustí). Odhad
  (`ai-eval --estimate`) nic nespustí ani nezapíše. Výsledky jdou do
  `tests/eval/<sada>/` v pracovním stromu — commit dělá člověk.
- **Která sada ve zdroji dat je**: `dataset-seed` zapíše do `main.json`
  pole `dataset` (název, hash obsahu sady, čas). Zdroje dat seedované před
  F6b ho nemají — panel je ukáže jen podle původu `dataset-seed <sada>`
  s poznámkou „přeseedovat“; ostatní volné zdroje dat jsou v nabídce
  „Přeseedovat jiný volný zdroj dat“.
- **Výměna sady** (F6c): přeseedování zdroje dat, který drží jinou sadu,
  server pustí jen s výslovným potvrzením (`replaceDataset`, jinak 409
  `DATASET_MISMATCH`) — v seznamu zdrojů dat je to druhé potvrzení
  „Nahradit sadu X sadou Y“. Panel sady nabízí jen zdroje dat s touž
  sadou nebo bez sady, takže se ho to netýká.
- Totéž z terminálu: `shpd-ds dataset-seed <cesta> -y`, `shpd-ds
  dataset-verify <cesta> --json`, `shpd-ds ai-eval <cesta> --variant
  <soubor> --estimate --json` / `--yes --max-cost <USD>`
  (`docs/cli.md`).

---

## 2. První zdroj dat

Zdroj dat je jedna firma — vlastní databáze a soubory. Založíš ho
v dashboardu:

1. Klikni na **Nový zdroj dat**.
2. Vyplň **Název**, **Login administrátora** a **Heslo administrátora**;
   jazyk, zemi a instalační modul můžeš nechat, jak jsou.
3. V poli **Data** vyber datovou sadu (např. `cz-non-payer`) — dostaneš
   firmu s doklady a došlou poštou; země se nastaví podle sady. Bez sady
   vznikne prázdný zdroj dat.
4. **Vytvořit zdroj dat**. Průběh se vypisuje do stránky; na konci je odkaz
   **Otevřít zdroj dat →**.
5. Přihlas se loginem a heslem z kroku 2.

Zdroj dat pak najdeš v seznamu na dashboardu pod tlačítkem **Otevřít**.

---

## 3. Po `git pull`

Po každém stažení nové verze:

```bash
bash scripts/dev-update.sh
```

Skript vždy spustí `composer install`, `npm install` (ve `frontend/`)
a `npm run build`. Všechny kroky jsou idempotentní — pokud se nic
nezměnilo, projdou během pár sekund. Na konci připomene, co sám neudělá
(nevolá `sudo`): upgrade datových zdrojů a instalaci cronu, pokud na stroji
chybí nebo je zastaralý (oddíl 4).

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

## 4. Periodické úlohy (cron)

Část systému běží na pozadí z cronu: fronta odchozí pošty, upozornění,
instance období DPH, periodická fakturace zakázek, úklid. Dispatcher
`shpd-server cron --slot=<slot>` spouští sloty `minute`, `two-minutes`,
`five-minutes`, `daily` a `weekly` z `/etc/cron.d/shipard` a projde všechny
zdroje dat na stroji. Bez cronu tohle při vývoji tiše nefunguje — proto je
cron součást vývojové instalace: `install-packages.sh` zajistí službu `cron`
a rotaci logů, `dev-bootstrap.sh` nainstaluje cron soubor.

**Instalace a kontrola.** Stačí jednou; soubor se přegeneruje jen při změně
šablony:

```bash
sudo shpd-server cron-install
shpd-server doctor               # sekce Cron: soubor + heartbeat slotů
```

Když cron chybí nebo je soubor zastaralý, připomene to `dev-update.sh` na
konci každého běhu (i po `git pull` přes git hooky) a `doctor` to hlásí jako
varování — mimo produkci nikdy jako chybu, uspaný notebook není porucha.

**Kopie reálných dat mají mít cron vypnutý.** Zdroj dat importovaný ze
starého Shipardu nebo obnovený ze zálohy má zůstat, jak je — žádná úloha
v něm nemá nic vystavovat ani posílat. Proto **první instalace cronu na
stroji vypne periodické úlohy všem zdrojům dat, které už existují**,
a vypíše jejich seznam; zapni je jen těm, které chceš:

```bash
shpd-server ds-cron                            # přehled: ID | Name | Cron | State
sudo shpd-server ds-cron --on <id> [<id>…]     # zapnout vyjmenovaným
sudo shpd-server ds-cron --off <id>            # vypnout (např. cíl importu)
```

Zdroj dat založený až po instalaci cronu má cron zapnutý (výchozí stav);
cíl importu zakládej s `ds-create --real-copy --no-cron`. Vypnutý cron je
vidět ve sloupci **Cron** vývojářského dashboardu, v `shpd-server ds-list`,
v `doctor` a jako pruh v aplikaci. Třída dat `realCopy` (kopie reálných dat,
[`../ai-workflow.md`](../ai-workflow.md#7-nastavení-pro-nového-člověka),
kapitola 7) cron sama nemění — vypnutý cron je u ní zvyk, ne automatika;
`ds-list` ukáže obojí vedle sebe.

**Jeden tik ručně.** Úlohy slotu pro jediný zdroj dat, bez ohledu na
přepínač (ladění, dohnání):

```bash
shpd-server cron --slot=daily --ds=<id>
```

**Logy.** Výstup slotů jde do `/opt/shipard/log/cron.log`, záznamy úloh do
`shipard.log` (prohlížeč **Logs** v dashboardu). Oba soubory rotuje
`logrotate` podle `/etc/logrotate.d/shipard`.

---

## 5. Závislosti

`composer.lock` je verzovaný, `composer install` tak všude nainstaluje stejné
verze balíků. Závislost přidávej přes `composer require <balík>`, verzi měň
přes `composer update <balík>`; po ruční úpravě `composer.json` (např.
`ext-*`) přepočti lock příkazem `composer update --lock`. `composer.json`
a `composer.lock` patří vždy do stejného commitu.

---

## 6. Až budeš chtít posílat změny

Checkout z návodů je stažený přes HTTPS — funguje i bez SSH klíče na
GitHubu. Kdo bude pushovat, může klonovat rovnou přes SSH
(`git@github.com:shipard/shipard.git`) nebo si `remote` přepnout později
(`git remote set-url origin …`).

Jak práci poslat — fork, větev a pull request — popisuje
[`claude-code-intro.md`](claude-code-intro.md#8-repozitář-větve-a-odeslání-práce)
(kapitola 8).

---

## 7. Příkazy

Přehled všech příkazů `shpd-server` a `shpd-ds` i pomocných skriptů je
v [`cli.md`](../cli.md). Po základním setupu nepotřebuješ `sudo` pro běžné
shipard operace.

---

## 8. Něco nefunguje?

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

Logy aplikace najdeš ve vývojářském dashboardu pod **Logy**
(`/_dev/logs/`) — s filtrováním podle úrovně a fulltextu — nebo přímo
v souboru `/opt/shipard/log/shipard.log`.

Když po `git pull` aplikace hlásí nesoulad schématu, „dojely" ti definice
tabulek — spusť `shpd-server ds-upgrade-all` (nebo **Upgrade všech**
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
