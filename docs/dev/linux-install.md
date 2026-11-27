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
[`dev-daily.md`](dev-daily.md#6-až-budeš-chtít-posílat-změny) (oddíl 6).

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
přes group membership. Nová vývojová instalace (mimo WSL) dostane rovnou
sekci `"devAccess": {"httpsIp": true}` — https na adrese stroje, viz
oddíl 6.

---

## 5. Periodické úlohy (cron)

```bash
sudo shpd-server cron-install
```

Zapíše `/etc/cron.d/shipard` a runtime adresář `/opt/shipard/run`; službu
`cron` a rotaci logů nainstaloval už `install-packages.sh`. Na stroji, kde už
zdroje dat jsou, první instalace všem **vypne** periodické úlohy a vypíše je —
zapni je těm, které chceš (`sudo shpd-server ds-cron --on <id>`). Podrobně
[`dev-daily.md`](dev-daily.md#4-periodické-úlohy-cron), oddíl 4.

---

## 6. https

Prohlížeč řadu funkcí nabízí jen v **zabezpečeném kontextu** — na `https`,
`localhost` a `127.0.0.1`. Na `http://<adresa stroje>/` proto nejde
kopírování do schránky, simulace nativní aplikace nedostane klíč zařízení,
aplikace nejde nainstalovat (PWA) a skuteční poskytovatelé přihlášení
(OIDC) redirect odmítnou. Vývojový server má tři vstupy; každý je pro
prohlížeč jiný web, takže přihlášení ani nastavení se mezi nimi nesdílejí:

| Vstup | Certifikát | Kdy |
|-------|------------|-----|
| `http://<IP>/<ID>/` | žádný | vždy — rychlý vstup |
| `https://<IP>/<ID>/` | server si ho vyrobí sám | nová instalace ho má zapnutý |
| `https://<jméno>/<ID>/` | dodáš ty | volitelně |

Vstupy řídí sekce `devAccess` v `/etc/shipard/server.json` (edituje root;
klíče popisuje [`cli.md`](../cli.md#dev-https---check)) a provede je:

```bash
sudo shpd-server dev-https
```

Příkaz je idempotentní — spusť ho po každé změně `devAccess` a po změně
adresy stroje. `shpd-server upgrade` ho volá sám, `dev-update.sh` připomene,
když je potřeba, a `shpd-server doctor` certifikáty hlídá.

### https na IP adrese

```json
"devAccess": { "httpsIp": true }
```

Server si vyrobí certifikát se všemi adresami stroje (platí 397 dní,
obnovuje se sám měsíc před koncem a když stroj dostane novou adresu).
Prohlížeč ho nezná, takže **při první návštěvě varuje** — klikni na
„Rozšířené“ a pokračuj; od té chvíle je stránka zabezpečený kontext. Po
obnově certifikátu se varování objeví znovu.

Co na tomhle vstupu nepojede: service worker (instalovaná aplikace) —
prohlížeč ho na webu s nedůvěryhodným certifikátem nezaregistruje —
a nativní aplikace Shipard. K tomu je vstup se jménem.

Stávající server bez sekce `devAccess` zůstává jen u http; https zapneš
doplněním sekce a příkazem výše. Když už na stroji jiný web drží
`default_server` na portu 443, vstup přes IP nevznikne a příkaz to řekne.

### IPv6

Shipard na vývojovém serveru poslouchá **jen na IPv4** — http i https,
a certifikát pro IP nese jen adresy IPv4. Důvod: stroj za domácím nebo
firemním routerem mívá vedle privátní IPv4 i **globální IPv6 adresu**,
a ta je dosažitelná z internetu, pokud ji router nefiltruje. Vývojářský
dashboard `/_dev/` přitom **nemá přihlášení** — zakládá a maže zdroje dat,
pouští upgrady a ukazuje logy — a na stejné adrese jsou i zdroje dat
s kopií reálných dat.

https na IPv6 zapneš výslovně:

```json
"devAccess": { "httpsIp": true, "ipv6": true }
```

Smysl to dává jen v síti, kde víš, že příchozí spojení z internetu na
stroj nedojdou (firewall na routeru nebo na stroji), nebo kde má stroj jen
lokální IPv6 adresy (ULA `fd…`). `shpd-server doctor` na Shipard na IPv6
při globální adrese stroje upozorňuje vždy — i když jsi `ipv6` zapnul
záměrně, a i když sis IPv6 `listen` doplnil ručně do http site.

Jméno se záznamem AAAA funguje i bez `ipv6`: prohlížeč zkusí IPv6, spojení
se nepovede a přejde na záznam A (IPv4).

### https se jménem

```json
"devAccess": { "httpsIp": true, "host": "shpd.example.com" }
```

Certifikát a klíč dodáš na pevné cesty (soubory, nebo symlinky — třeba do
adresáře certbotu):

```
/etc/shipard/tls/dev-host/fullchain.pem
/etc/shipard/tls/dev-host/privkey.pem
```

Adresář založí první běh `sudo shpd-server dev-https`; dokud v něm
certifikát není, sedí ke klíči a nese jméno, vstup se jménem nevznikne
a příkaz skončí chybou s těmito cestami. Ostatní vstupy tím dotčené nejsou.

Kde certifikát vzít:

- **Skutečný certifikát pro vlastní doménu.** Dev server bývá na privátní
  adrese, kam certifikační autorita z internetu nedosáhne — ověření přes
  HTTP nepůjde, potřebuješ **ověření přes DNS** (DNS-01; certbot s pluginem
  tvého poskytovatele DNS, `acme.sh` apod.). Po obnově certifikátu stačí
  `sudo systemctl reload nginx`.
- **Vlastní certifikační autorita** ve stylu `mkcert`: certifikát vydáš
  sám a autoritu přidáš mezi důvěryhodné v prohlížeči (a v systému, kde
  běží nativní aplikace).

Jméno se musí překládat na tento stroj — v prohlížeči **i na serveru
samotném** (testovací kolo a videa otevírají aplikaci stejnou adresou jako
ty; `doctor` to kontroluje). Veřejný DNS záznam, který míří na privátní
adresu, některé routery a resolvery zahazují (ochrana proti DNS rebinding);
pak pomůže záznam v `hosts` na tvém počítači a na serveru:

```
10.0.0.7   shpd.example.com
```

Nepoužívej jména z domén `.dev` a `.app` — prohlížeče u nich https
s důvěryhodným certifikátem vyžadují vždy a varování nejde prokliknout.
Hlavičku HSTS vývojový server neposílá, takže se k http na jiném jméně
můžeš kdykoli vrátit.

---

## 7. Ověření

```bash
shpd-server doctor
```

Vypíše report: mode, shipard-user, PHP-FPM pool user, kontrolu cest, cron
(soubor a heartbeat slotů — čerstvě po instalaci jen „not yet run“),
https vstupy (certifikáty a site nginx), DB connection per DS. Exit 0 = vše OK.

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
