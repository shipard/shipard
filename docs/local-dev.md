# Lokální vývoj na macOS a Windows

Návod pro toho, kdo chce Shipard rozjet na vlastním počítači a Linux server
nikdy nespravoval. Na konci máš běžící Shipard a v prohlížeči jeho
vývojářský dashboard.

Shipard běží na Ubuntu. Na Macu ho proto spustíš ve virtuálním stroji
(**Multipass**), na Windows ve **WSL** — v obou případech je to Ubuntu uvnitř
tvého počítače. Instalaci udělá jeden skript,
[`scripts/dev-bootstrap.sh`](../scripts/dev-bootstrap.sh).

> **Máš linuxový server, nebo Ubuntu přímo v počítači?** Tenhle návod
> nepotřebuješ — postup pro Linux je v [`DEVELOPERS.md`](../DEVELOPERS.md).
> Bootstrap jen spouští jeho kroky za sebou.

---

## 1. Co budeš potřebovat

- **Mac** (Apple silicon i Intel), nebo **Windows 10** (verze 21H2 a novější)
  či **Windows 11**
- asi **20 GB** místa na disku a **4 GB** paměti pro Ubuntu
- připojení k internetu — instalace stahuje balíčky a chvíli trvá
- účet na GitHubu jen tehdy, když chceš přispívat; na vyzkoušení potřeba není

Zdrojový kód žije **uvnitř Ubuntu** v adresáři `~/sw/shpd`, ne na disku Macu
nebo Windows. Soubory sdílené z hostitele jsou pomalé a rozbíjejí práva,
na kterých instalace stojí.

---

## 2. macOS — Multipass

**1. Nainstaluj Multipass** podle
[oficiální stránky](https://canonical.com/multipass/install).

**2. Stáhni konfiguraci** — v aplikaci Terminál:

```bash
curl -fsSLO https://raw.githubusercontent.com/shipard/shipard/stable/scripts/multipass/shipard-dev.yaml
```

**3. Vlož svůj veřejný SSH klíč** (jen když chceš napojit Clauda v chatu —
kapitola 5; jinak krok přeskoč). Klíč pro vývojový stroj vytvoř zvlášť
a na dotaz na heslo (passphrase) jen stiskni Enter — `remote-dev-bridge`
klíč s heslem neumí:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/shipard_dev
```

Pokud klíč `~/.ssh/shipard_dev` už máš, `ssh-keygen` se zeptá na přepsání —
odpověz `n`, stávající klíč stačí.

Pak klíč vlož do `shipard-dev.yaml` — tenhle příkaz nahradí zástupný řádek
obsahem veřejného klíče a druhý výsledek vypíše:

```bash
sed -i '' "s|ssh-ed25519 AAAA_NAHRAD_TENTO_RADEK_SVYM_VEREJNYM_KLICEM|$(cat ~/.ssh/shipard_dev.pub)|" shipard-dev.yaml
grep -A1 'ssh_authorized_keys:' shipard-dev.yaml
```

Pod `ssh_authorized_keys:` má být `- ssh-ed25519 AAAA…` s tvým klíčem, ne
zástupný text `AAAA_NAHRAD_…`.

Když chceš soubor upravit ručně: `open -e shipard-dev.yaml` (TextEdit).
Na řádku `- ssh-ed25519 AAAA_NAHRAD_TENTO_RADEK_SVYM_VEREJNYM_KLICEM` nahraď
všechno za pomlčkou obsahem souboru `~/.ssh/shipard_dev.pub`; pomlčku
a odsazení nech. Vkládá se vždy soubor **`.pub`** — soubor bez přípony je
soukromý klíč a nikam se nekopíruje.

**4. Spusť virtuální stroj:**

```bash
multipass launch 26.04 --name shipard --cpus 2 --memory 4G --disk 20G \
    --timeout 1800 --cloud-init shipard-dev.yaml
```

Příkaz čeká, dokud instalace uvnitř nedoběhne. Volba `--timeout` je potřeba:
instalace trvá déle než výchozích 5 minut, po kterých by Multipass ohlásil
chybu, přestože stroj dál pracuje.

> **Síť nech výchozí.** Nepřidávej `--network` ani `--bridged`. Vývojářský
> dashboard nemá přihlášení — ve výchozí síti Multipassu je dostupný jen
> z tvého počítače, v místní síti by ho viděl kdokoli.

**5. Sleduj průběh** — v druhém okně Terminálu:

```bash
multipass exec shipard -- tail -f shipard-bootstrap.log
```

Hotovo je, když výpis skončí souhrnem
`==> Hotovo — vývojové prostředí Shipardu běží.` s adresou dashboardu
(`Ctrl+C` sledování ukončí). Totéž řekne
`multipass exec shipard -- cloud-init status --wait` — vypíše `status: done`.

**6. Otevři dashboard.** Adresu stroje ukáže řádek `IPv4`:

```bash
multipass info shipard
```

V prohlížeči otevři `http://<IPv4>/_dev/`.

Příkazy, které se budou hodit:

| Příkaz | K čemu |
|--------|--------|
| `multipass shell shipard` | terminál uvnitř Ubuntu |
| `multipass stop shipard` / `multipass start shipard` | vypnutí a zapnutí stroje |
| `multipass info shipard` | stav a adresa (po restartu ji ověř, může se změnit) |
| `multipass delete --purge shipard` | smazání stroje **včetně dat** |

---

## 3. Windows — WSL

**1. Nainstaluj Ubuntu.** Otevři PowerShell jako správce:

```powershell
wsl --install Ubuntu-26.04
```

Když si instalace řekne o restart Windows, restartuj; kdyby se pak Ubuntu
neotevřelo samo, spusť příkaz znovu. Dostupné distribuce vypíše
`wsl --list --online`.

**2. První spuštění.** Ubuntu se zeptá na jméno a heslo nového uživatele.
Heslo si zapamatuj — bude ho chtít `sudo`.

**3. Spusť bootstrap** — v okně Ubuntu:

```bash
curl -fsSL https://raw.githubusercontent.com/shipard/shipard/stable/scripts/dev-bootstrap.sh | bash
```

Skript se na začátku jednou zeptá na heslo a pak běží sám. Skončí souhrnem
`==> Hotovo — vývojové prostředí Shipardu běží.`

Kdyby Ubuntu hlásilo `curl: command not found`, doinstaluj ho
(`sudo apt update && sudo apt install -y curl`) a příkaz zopakuj.

**4. Otevři dashboard** v prohlížeči ve Windows: `http://localhost/_dev/`.

**5. Volitelně: SSH pro Clauda v chatu** (kapitola 5). Klíč vytvoř ve
Windows — v PowerShellu, na dotaz na heslo (passphrase) jen stiskni Enter:

```powershell
ssh-keygen -t ed25519
```

Pak v okně Ubuntu spusť bootstrap znovu s volbou `--with-ssh` (místo
`<uživatel>` dosaď své jméno ve Windows):

```bash
bash ~/sw/shpd/scripts/dev-bootstrap.sh --with-ssh \
    --ssh-pubkey-file /mnt/c/Users/<uživatel>/.ssh/id_ed25519.pub
```

SSH pak naslouchá na portu **2222** a pustí jen držitele klíče, heslem se
přihlásit nedá. Obojí jde i najednou při první instalaci:
`curl -fsSL … | bash -s -- --with-ssh --ssh-pubkey-file …`.

Na co si dát pozor:

- **Checkout nech v Ubuntu** (`~/sw/shpd`), nikdy v `/mnt/c/…`.
- **Při práci nech okno Ubuntu otevřené.** WSL může distribuci bez otevřeného
  terminálu po chvíli zastavit — dashboard i SSH pak přestanou odpovídat,
  dokud Ubuntu znovu neotevřeš.
- Bootstrap potřebuje **WSL 2 se systemd**. Nové instalace Ubuntu ho mají
  zapnutý; když skript hlásí, že systemd neběží, napíše i co nastavit.

---

## 4. První zdroj dat

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

## 5. Napojení na Clauda

V projektu se pracuje s AI asistentem ve dvou rolích — popisuje je
[`ai-workflow.md`](ai-workflow.md).

**Claude Code** běží přímo uvnitř Ubuntu, v adresáři `~/sw/shpd`. Na běžnou
práci (implementace, testy) stačí on. Bootstrap ho neinstaluje — instalace,
přihlášení a první kroky jsou v [`claude-code-intro.md`](claude-code-intro.md).

**Claude v chatu** (návrh, zadání, ověřování) se k Ubuntu připojuje přes SSH
aplikací [`remote-dev-bridge`](https://github.com/shipard/remote-dev-bridge)
na tvém počítači — instalace a napojení do desktopové aplikace Claude
v [`ai-workflow.md` §7](ai-workflow.md#7-nastavení-pro-nového-člověka).
V nastavení mostu přidej server a projekt s ID **`shipard`**:

| | Multipass | WSL |
|--|-----------|-----|
| host | adresa z `multipass info shipard` | `localhost` |
| port | `22` | `2222` |
| uživatel | `ubuntu` | tvůj uživatel v Ubuntu |
| klíč | soukromý klíč k tomu, který jsi vložil do `shipard-dev.yaml` | soukromý klíč z Windows (`C:\Users\<uživatel>\.ssh\id_ed25519`) |
| root projektu | `/home/ubuntu/sw/shpd` | `/home/<uživatel>/sw/shpd` |

Na konci bootstrapu s `--with-ssh` jsou tyhle údaje vypsané v souhrnu.
Po uložení se zeptej Clauda v chatu na `hostname` stroje — ověříš tím, že
projekt míří na správný stroj.

Na macOS použij **podepsané vydání** ze stránky
[Releases](https://github.com/shipard/remote-dev-bridge/releases). Vlastní
nepodepsaný build končí chybou „No route to host“ — macOS mu nedá oprávnění
Místní síť, bez kterého se k virtuálnímu stroji nedostane.

---

## 6. Každodenní práce

Terminál v Ubuntu: na Macu `multipass shell shipard`, ve Windows okno Ubuntu.

```bash
cd ~/sw/shpd
git pull
```

Po `git pull` se závislosti a frontend aktualizují samy — bootstrap zapnul
git hooky, které spustí `scripts/dev-update.sh`. Když se měnily definice
tabulek, aktualizuj ještě zdroje dat:

```bash
shpd-server ds-upgrade-all
```

(nebo tlačítko **Upgrade All** v dashboardu). Podrobnosti a další příkazy:
[`DEVELOPERS.md`](../DEVELOPERS.md) od kapitoly 6.

Bootstrap jde kdykoli spustit znovu — hotové kroky jen ověří. Stejně se
doplní volitelné části:

| Volba | Co přidá |
|-------|----------|
| `--with-render` | PDF rendering službu (tisky dokladů) — viz [`operations/render-service.md`](operations/render-service.md) |
| `--with-ssh` | SSH server na portu 2222, přihlášení jen klíčem |
| `--ssh-pubkey-file <cesta>` | veřejný klíč, který se smí přes SSH přihlásit |
| `--branch <větev>` | větev pro nový checkout (výchozí `stable`) |
| `--dir <cesta>` | adresář checkoutu (výchozí `~/sw/shpd`) |

```bash
bash ~/sw/shpd/scripts/dev-bootstrap.sh --with-render
```

Checkout je stažený přes HTTPS. Až budeš chtít posílat změny, přepni ho
podle kapitoly 1 v [`DEVELOPERS.md`](../DEVELOPERS.md).

---

## 7. Něco nefunguje?

**Bootstrap skončil chybou.** Poslední řádky říkají, ve kterém kroku
(`==> Bootstrap selhal v kroku: …`), nad nimi je příčina. Na Macu je výpis
v souboru:

```bash
multipass exec shipard -- tail -n 50 shipard-bootstrap.log
```

Po odstranění příčiny spusť bootstrap znovu — v terminálu uvnitř Ubuntu
`bash ~/sw/shpd/scripts/dev-bootstrap.sh`, a když checkout ještě neexistuje,
příkazem `curl … | bash` z kapitoly 3 (krok 3).

**`multipass launch` hlásí „timed out“.** Stroj běží a instalace pokračuje —
sleduj ji podle kroku 5 v kapitole 2.

**Dashboard se neotevře.** Na Macu ověř adresu a stav stroje
(`multipass info shipard`), ve Windows měj otevřené okno Ubuntu.

**Aplikace hlásí chybu nebo se chová divně.** Zdravotní kontrola:

```bash
shpd-server doctor
```

Když najde potíže s právy:

```bash
sudo shpd-server fix-permissions --dry-run    # co by se změnilo
sudo shpd-server fix-permissions              # oprav
```

`fix-permissions` se před opravou ptá na potvrzení. Ve skriptu nebo přes
vzdálený nástroj, kde není kdo by odpověděl, použij `--dry-run`, případně
`--force`.

**Stovky řádků `Deprecation Notice` od composeru** na Ubuntu 24.04 jsou
neškodné — composer z balíčků je starší než PHP 8.5. Na 26.04 se neobjevují.

**Pořád to nejde?** Napiš na [Discord](https://discord.gg/PWTt5EUFAV), nebo
založ [issue](https://github.com/shipard/shipard/issues) s výstupem
`shpd-server doctor` a posledními řádky z bootstrapu.
