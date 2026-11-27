# HTTPS na vývojovém serveru — fáze 1: adresování, nginx, vstupy, certifikát pro IP (#183)

**Stav:** částečně — kód, testy a dokumentace hotové 2026-11-27 (6 commitů, celá sada 13 686 testů; šablony nginx, certifikát přes `openssl`, sonda a Playwright ověřené na dočasném nginx bez roota); zbývá člověk: `sudo shpd-server dev-https` na dev serveru s `devAccess`, proklik `https://<IP>/<ID>/app/` a vstupu se jménem, testovací kolo z dashboardu přes https, bootstrap na čisté VM a ve WSL

> Task pro Claude Code (6 commitů). Design: issue #183. Tajemství serveru
> mimo `server.json` (D7) jsou samostatný task, vlastní jména pro zdroje
> dat a nástroj na certifikáty jsou mimo fázi 1. **Kroky s rootem**
> (`sudo shpd-server dev-https`, přegenerování nginx) **dělá člověk** —
> Claude Code je jen připraví a popíše.

## Před implementací přečti

- issue #183 — celé (D1–D7, U1, Pasti pro task)
- `docs/rest-api.md` §2 (produkce × development), `docs/cli.md` (oddíl
  `shpd-server`, vzor `cron-install --check`)
- `docs/operations/permissions.md` — kontrakt `/etc/shipard`
- `docs/dev/local-dev.md`, `docs/dev/linux-install.md`,
  `docs/dev/dev-daily.md` (oddíl 4 cron jako vzor hintu, oddíl 5
  proklikání a videa, #103 D97)
- `docs/native-bridge.md` — odstavec *Zabezpečený kontext* v oddílu
  simulace; `docs/frontend.md` — oddíl Vývojářský dashboard (`/_dev/`)
- `scripts/install-packages.sh` §9 (nginx site), `scripts/dev-bootstrap.sh`,
  `scripts/dev-update.sh`
- `src/Api/DataSourceResolver.php`, `src/Api/Request.php`
  (`getScheme()`, `getOrigin()`), `public/index.php` (dispatch, `$baseUrl`)
- `src/Command/Server/DoctorCommand.php`, `UpgradeCommand.php`,
  `ServerInitCommand.php`, `src/Core/Config/ServerConfig.php` (`CORE_KEYS`)
- `modules/core/exchange/src/Dataset/E2e/LocalBaseUrl.php` — pravidlo
  „adresa míří na tento server“ a adresy rozhraní
- `tasks/cron-dev-install.md` — vzor: root příkaz + `--check` + hint
  v `dev-update.sh` + `doctor`

## Kontext

Vývojový režim dnes znamená „host je IPv4“: `DataSourceResolver` zapne
adresování přes ID v cestě jen pro IP, cokoli jiného hledá v
`domains.json` (`404 UNKNOWN_HOST` pro `localhost` i jména). Ze stejného
příznaku (`ResolvedDataSource::isDevMode()`) se odvozuje schéma — čtyři
místa mají `devMode ? 'http' : 'https'`. nginx ve vývoji umí jen
`listen 80` pro IP.

Výsledek fáze 1: na vývojovém serveru fungují tři vstupy (D3) —
`http://<IP>/<ID>/` jako dnes, `https://<IP>/<ID>/` se self-signed
certifikátem, který vyrobí server sám, a volitelně `https://<jméno>/<ID>/`
s certifikátem od vývojáře. WSL dostane funkční `http://localhost/<ID>/`.
Dashboard vstupy nabídne a `doctor` hlídá certifikáty (U1).

## Rozhodnutí (potvrzená 2026-11-27)

- **D1** — adresování cestou podle režimu serveru, ne podle hostu; v
  `production` se nepoužije.
- **D2** — schéma z požadavku (`Request::getScheme()`).
- **D3** — tři druhy vstupu ve vývoji, popsané v konfiguraci serveru;
  přepínač v dashboardu.
- **D4** — https v samostatném site souboru, zapínaném jen s certifikátem;
  pevné cesty k certifikátům; blok pro IP `default_server`; společné
  `location` v includu; generuje instalace z konfigurace.
- **D5** — ve vývoji bez HSTS (rozdělení `shipard-tls.conf`).
- **D6** — certifikát pro IP vyrobí instalace (self-signed s IP v SAN);
  pro jméno ho dodá vývojář.
- **U1** — `doctor` hlídá certifikáty už ve fázi 1: varování 14 dní před
  koncem platnosti; chyba po vypršení, při nesouladu SAN nebo klíče;
  jméno se na serveru musí překládat na tento server. Certifikát pro IP
  obnoví upgrade serveru sám.

## Upřesnění (potvrzená 2026-11-27)

- **U2 — konfigurace vstupů.** Sekce `devAccess` v `server.json` (nový
  klíč v `ServerConfig::CORE_KEYS`, getter, `ServerConfigTest`):

  ```json
  "devAccess": {
      "httpsIp": true,
      "host": "shpd.example.com",
      "preferred": "https-host"
  }
  ```

  - `httpsIp` — https na IP; chybějící sekce = `false`, takže stávající
    servery se bez zásahu nezmění. `server-init --mode=development` ho
    v nových instalacích zapíše jako `true` (mimo WSL, U12).
  - `host` — volitelné jméno pro `https://<jméno>/`; bez něj vstup chybí.
  - `preferred` — `http-ip` | `https-ip` | `https-host`; výchozí je
    nejvyšší zapnutý (`https-host` → `https-ip` → `http-ip`).
  - `ip` — volitelný přepis adresy pro odkazy (server s víc rozhraními);
    jinak se adresy zjistí z rozhraní (globální IPv4 i IPv6, bez loopbacku
    a link-local — stejný zdroj jako `LocalBaseUrl`).
  - Výklad sekce na jednom místě: `Shipard\Core\Server\DevAccess`
    (vstupy, adresy, preferovaný origin). V režimu `production` se sekce
    nepoužije a `doctor` ji hlásí jako varování.
  - `server.json` do D7 nese tajemství, proto API dashboardu vydává jen
    allowlist z `DevAccess`, nikdy surovou konfiguraci.

- **U3 — příkaz `shpd-server dev-https`.** Jen režim `development`, běží
  jako root (vzor `cron-install`), idempotentní:
  1. podle `devAccess` vyrobí nebo obnoví certifikát pro IP (U5),
  2. u jména ověří, že certifikát na pevné cestě existuje, sedí ke klíči
     a SAN obsahuje jméno,
  3. vygeneruje `/etc/nginx/sites-available/shipard-https.conf` ze šablony
     (U6) a odkáže ho do `sites-enabled`; když žádný https vstup není
     zapnutý, odkaz i soubor odstraní,
  4. `nginx -t` a reload; při chybě vrátí předchozí stav site souboru
     a skončí nenulově.

  Když jiný web na serveru už má `default_server` na 443, příkaz to
  ohlásí srozumitelně a blok pro IP nevytvoří. `--check` běží bez roota,
  nic nemění a skončí nenulově, když by běh něco udělal (chybějící nebo
  expirující certifikát pro IP, změna adres, zastaralý site soubor).

  Kdo ho volá:
  - `dev-bootstrap.sh` po `server-init` (`sudo shpd-server dev-https`),
  - `shpd-server upgrade` v režimu `development` jako poslední krok před
    `doctor` (obnova certifikátu pro IP podle U1),
  - `dev-update.sh` (bez roota) jen vypíše hint, když `dev-https --check`
    selže — stejně jako dnes u `cron-install --check`.

- **U4 — cesty a práva.** `/etc/shipard/tls/` (`root`, shipard-user,
  `0750`); `dev-ip/` a `dev-host/`, v každém `fullchain.pem` (`0644`)
  a `privkey.pem` (`root`, `0600` — čte ho jen nginx master pod rootem).
  U vlastního certifikátu pro jméno smí být oba soubory symlink (certbot
  apod.). Zapsat do `docs/operations/permissions.md`.
  `doctor` čte jen `fullchain.pem`; nečitelný (symlink do adresáře jen pro
  root) hlásí jako varování s radou spustit `doctor` pod rootem. Shodu
  klíče s certifikátem ověřuje `dev-https` (root) a `nginx -t`; `doctor`
  ji kontroluje jen pod rootem.

- **U5 — certifikát pro IP.** Klíč EC P-256, SAN = všechny adresy z U2
  (IPv4 i IPv6), `basicConstraints CA:FALSE`, `extendedKeyUsage
  serverAuth`, CN `Shipard dev <hostname>`, platnost 397 dní. Vyrábí ho
  `openssl` CLI (`req -x509 … -addext subjectAltName=…`, OpenSSL 3 je na
  podporovaných Ubuntu). `dev-https` ho obnoví, když zbývá méně než
  30 dní nebo SAN nesedí na aktuální adresy. Nový certifikát znamená
  v prohlížeči nové varování — dokumentovat.

- **U6 — šablony nginx.**
  - `docs/nginx/development-locations.conf` — společné bloky `location`
    pro http i https **bez** bloku PHP (cesta k socketu FPM se liší
    podle serveru a do verzovaného includu nepatří).
  - `docs/nginx/development.conf` — blok `listen 80` přejde na include;
    blok PHP s dosazeným socketem zůstává v generovaném souboru jako dnes.
    Stávající vygenerované soubory fungují beze změny, přegenerování
    (`--force-nginx`) je volitelné.
  - `docs/nginx/development-https.conf` — šablona se dvěma bloky: IP
    (`listen 443 ssl default_server`, certifikát `dev-ip`) a jméno
    (`server_name` dosadí `dev-https`, certifikát `dev-host`). Každý blok
    je v šabloně ohraničený značkami, aby `dev-https` mohl vynechat
    vypnutý vstup. `listen [::]:443` jen při zapnutém IPv6 (existuje
    `/proc/net/if_inet6`). Socket FPM dosazuje `dev-https` stejným
    pravidlem jako `install-packages.sh` (`DoctorCommand::detectShipardSocket()`).
  - `docs/nginx/app.conf` (zastaralá kombinace obou režimů) se odstraní;
    odkazy v `docs/README.md`, `docs/frontend.md` a `docs/ds-state.md`
    povedou na `development.conf` / `production.conf`.

- **U7 — rozdělení TLS.** Nový `docs/nginx/shipard-tls-params.conf`
  (protokoly, session cache, křivky). `shipard-tls.conf` si **ponechá
  jméno** a bude `include shipard-tls-params.conf` + HSTS, takže
  produkční site soubory fungují bez přegenerování. Vývoj includuje jen
  `shipard-tls-params.conf`.

- **U8 — resolver (D1).** `DataSourceResolver` dostane režim serveru
  (konstruktor, `public/index.php` ho předá z `ServerConfig`).
  - `development`: první segment ve tvaru ID (`IdGenerator::ID_PATTERN`)
    → zdroj dat z cesty pro jakýkoli host; jinak `domains.json`.
  - `production`: jen `domains.json`; host s IP skončí `UNKNOWN_HOST`.
  - `isDevMode()` → přejmenovat na `isPathAddressed()` (význam „ID
    v cestě“), volající upravit. `AppController::manifest()` a prefixy
    beze změny chování.
  - Testy v `DataSourceResolverTest`: IP, `localhost`, `127.0.0.1`
    a jméno s ID v cestě ve vývoji; jméno bez ID ve vývoji přes
    `domains.json`; IP v produkci → `UnknownHostException`.

- **U9 — základní adresa (D2).** Jeden helper (např.
  `RequestBaseUrl::for(Request, ResolvedDataSource)`): `scheme://host`
  podle `Request::getScheme()` a hostu, jak ho poslal prohlížeč (i s
  portem), plus `/<ID>` při adresování cestou. Použijí ho `$baseUrl`
  v `public/index.php`, `OidcController`, `HostingOidcController`
  a `PasswordController` — `devMode ? 'http' : 'https'` z kódu zmizí
  (kontrola grepem v testu nebo v checklistu). Konzument bez požadavku
  ve fázi 1 není; `DevAccess::preferredOrigin()` použije dashboard a do
  budoucna CLI a cron.

- **U10 — dashboard.**
  - `/_dev/api/info` vrátí `access`: seznam vstupů `{kind, origin,
    preferred, current}` z `DevAccess` (aktuální podle originu
    požadavku).
  - V oranžovém pruhu vedle serveru menu **Adresa** (vzor `VersionMenu`):
    vstupy, aktuální označený, klik otevře stejnou cestu na jiném originu.
    Pod seznamem věta, že každá adresa má vlastní přihlášení i nastavení.
  - Na `http` mimo `localhost` (`!window.isSecureContext`) ukáže stránka
    Zdroje dat `Notice`: co nepojede (schránka, simulace mostu, PWA)
    a odkaz na preferovaný https vstup, je-li zapnutý.
  - U vstupu `https-ip` krátká nápověda k varování prohlížeče (jednou
    prokliknout; service worker nepojede).
  - Stránka Most: hláška o chybějícím zabezpečeném kontextu odkáže na
    https vstup.
  - Texty v `src/dev/i18n/` česky i anglicky.

- **U11 — `doctor` (U1).** Nová sekce *Development HTTPS*, jen v režimu
  `development`, pro každý zapnutý https vstup:
  - certifikát existuje a je čitelný (U4),
  - vypršel nebo SAN nesedí (IP: všechny adresy z U2; jméno: `host`)
    → chyba; méně než 14 dní do konce → varování,
  - jméno se na serveru překládá na tento server — pravidlo
    `LocalBaseUrl` (#103 D97); použít ho, případně přesunout do jádra,
  - site `shipard-https.conf` je aktivní a odpovídá konfiguraci
    (`dev-https --check`) — jinak varování s příkazem,
  - `devAccess` v režimu `production` → varování.

- **U12 — WSL a Multipass.**
  - `dev-bootstrap.sh` ve WSL `httpsIp` nezapne: prohlížeč ve Windows
    chodí přes `localhost`, ten je zabezpečený kontext sám o sobě a po D1
    funguje i aplikace.
  - `docs/dev/local-dev.md`: WSL `http://localhost/_dev/` včetně
    aplikace; Multipass `https://<adresa VM>/_dev/` s jedním proklikem
    varování a poznámkou, že se při změně adresy VM certifikát obnoví
    přes `sudo shpd-server dev-https` (hlídá `doctor`).

- **U13 — Playwright.** Testovací kolo i videa (`dataset-e2e`,
  `video-run`) při `https` v `--base-url` povolí `ignoreHTTPSErrors` —
  cíl je vždy ověřený a připíchnutý `LocalBaseUrl` na tento server, takže
  výjimka nemůže odnést session jinam. Předání přes proměnnou prostředí
  do `tests/e2e/playwright.config.mjs` a do video runneru.

## Upřesnění při implementaci (potvrzená 2026-11-27)

Vyplynula z porovnání zadání s kódem; U20–U22 rozhodl člověk.

- **U14 — port.** Šablony nginx předávaly PHP `HTTP_HOST` z `$host`, tedy
  bez portu, a U9 by port nikdy neviděla. Bloky PHP vývojových šablon
  předávají `$http_host`; resolver hledá v `domains.json` host malými
  písmeny a bez portu. Stávající vygenerovaný `shipard.conf` zůstává
  u `$host` do přegenerování.
- **U15 — pravidlo lokální adresy v jádře.** `LocalBaseUrl` žije v modulu
  `core.exchange`, `DevAccess` a `doctor` v jádře. Adresy rozhraní,
  normalizace IP a pravidlo „jméno míří na tento server“ se přesunou do
  `Shipard\Core\Net\LocalAddresses`; `LocalBaseUrl` na ně deleguje.
- **U16 — základní adresa v kontrolerech.** Kontrolery nemají
  `ResolvedDataSource`, jen konfiguraci a příznak. `RequestBaseUrl` má
  proto `for(Request, ResolvedDataSource)` a `of(Request, string $dsId,
  bool $pathAddressed)`. Cesta bez tvaru ID se ve vývoji řeší hostem —
  i pro IP tedy `UNKNOWN_HOST` místo dřívějšího `UNKNOWN_DATASOURCE`.
- **U17 — sonda před prohlížečem.** `E2ePreflight::httpStatus()` sahá na
  `--base-url` přes curl dřív než Playwright; výjimka z ověření certifikátu
  podle U13 platí i pro ni (stejné zdůvodnění — cíl je připíchnutý).
- **U18 — stránka Most.** Hlášku o zabezpečeném kontextu dnes nese jen
  simulace v aplikaci (`native.mock.insecure`), která vstupy nezná. Stránka
  Most v dashboardu dostane vlastní `Notice` s odkazem na https vstup.
- **U19 — práva `dev-host/`.** `PermissionSpec` hlídá adresáře `tls/`
  a soubory `dev-ip/`; soubory v `dev-host/` ne, aby `fix-permissions`
  nesahal na cíle symlinků.
- **U20 — obnova certifikátu pro IP kvůli adresám.** Jen když některá
  aktuální adresa serveru v SAN **chybí**; adresa navíc (zaniklá dočasná
  IPv6) obnovu nespustí. Totéž pravidlo má `doctor` (upřesňuje U5 a U11).
- **U21 — cizí `default_server` na 443.** `dev-https` blok pro IP
  nevytvoří, vypíše varování a skončí úspěšně; `--check` konflikt nepočítá
  jako potřebu běhu. Trvale ho hlásí `doctor` varováním.
- **U22 — selhání `dev-https` v `upgrade`.** Upgrade se nepřeruší:
  selhání se vypíše v souhrnu, `doctor` proběhne a upgrade skončí nenulově.

- **U23 — hláška v dashboardu.** Dashboard sdílenou `Notice` nepoužívá
  (hlášky má jako `.dev-alert`); upozornění na http je proto dev-lokální
  `InsecureContextAlert` ve stejném vzhledu jako ostatní hlášky stránky.

## Rozsah

- resolver a základní adresa (D1, D2, U8, U9)
- šablony nginx a rozdělení TLS (D4, D5, U6, U7)
- `devAccess`, `DevAccess`, `shpd-server dev-https`, certifikát pro IP,
  napojení do `server-init`, `dev-bootstrap.sh`, `upgrade`,
  `dev-update.sh` (D3, D6, U2–U5, U12)
- `doctor` (U1, U11)
- dashboard (U10), Playwright (U13)
- dokumentace

**Mimo rozsah:** tajemství serveru (D7, samostatný task), vlastní jména
pro zdroje dat, nástroj na certifikáty a vlastní CA, pruh „nezabezpečený
kontext“ v aplikaci, produkční nginx kromě rozdělení TLS.

## Testy

- `DataSourceResolverTest` podle U8.
- `RequestBaseUrl` — http × https, `X-Forwarded-Proto`, port, s ID
  v cestě i bez.
- `DevAccess` — výchozí hodnoty, `preferred`, přepis `ip`, zjištěné
  adresy (šev pro rozhraní), produkce.
- `DevHttpsCommand` — generování site souboru ze šablony (vynechání
  vypnutého bloku, dosazení jména a socketu, IPv6), rozhodnutí o obnově
  certifikátu (zbývající dny, změna SAN), `--check`; systémové kroky
  (`openssl`, `nginx -t`, reload) za švy.
- `DoctorCommand` — sekce Development HTTPS: chybějící, vypršelý,
  expirující a nesedící certifikát, jméno mimo server, produkce.
- `ServerConfigTest` — nový klíč v `CORE_KEYS`.
- Dashboard: čistá funkce pro sestavení odkazu na jiný vstup (stejná
  cesta, jiný origin) s testem v `tests/Unit/dev/`.
- PHPUnit jen s úzkým `--filter` přes vzdálené nástroje; celou sadu
  lokálně na konci.

## Dokumentace

- `docs/rest-api.md` §2 — vývoj: ID v cestě pro jakýkoli host, tři vstupy,
  schéma z požadavku; příklady URL.
- `docs/cli.md` — `shpd-server dev-https` (`--check`), `doctor` sekce.
- `docs/operations/permissions.md` — `/etc/shipard/tls/` (U4).
- `docs/dev/linux-install.md` — https na IP, vlastní jméno a certifikát
  (cesty U4, DNS-01 pro jméno na privátní IP, `/etc/hosts`, ochrana proti
  DNS rebinding), `sudo shpd-server dev-https`.
- `docs/dev/local-dev.md` — U12.
- `docs/dev/dev-daily.md` — vstupy a přepínač Adresa, co na http
  nepojede, obnova certifikátu, Playwright přes https.
- `docs/native-bridge.md`, `docs/frontend.md` — odstavec o zabezpečeném
  kontextu odkáže na https vstup místo `127.0.0.1`.
- `docs/README.md` — výčet nginx šablon.

## Commity

1. `feat(server): adresování cestou podle režimu serveru, adresa ze schématu požadavku (#183 D1, D2)` —
   resolver, `RequestBaseUrl`, kontrolery, `public/index.php`, testy,
   `docs/rest-api.md` §2
2. `feat(nginx): https šablony pro vývoj, TLS bez HSTS (#183 D4, D5)` —
   `development-locations.conf`, `development.conf`,
   `development-https.conf`, `shipard-tls-params.conf`, `shipard-tls.conf`,
   odstranění `app.conf` a odkazů
3. `feat(server): vstupy vývojového serveru a shpd-server dev-https (#183 D3, D6)` —
   `ServerConfig`, `DevAccess`, `DevHttpsCommand`, `server-init`,
   `dev-bootstrap.sh`, `upgrade`, `dev-update.sh`, testy, `docs/cli.md`,
   `docs/operations/permissions.md`
4. `feat(doctor): kontrola certifikátů vývojových https vstupů (#183 U1)`
5. `feat(dev): přepínač adresy v dashboardu a upozornění na http (#183 D3)` —
   API, menu Adresa, `Notice`, Most, i18n, Playwright (U13)
6. `docs(dev): https na vývojovém serveru (#183)` — návody v `docs/dev/`,
   `docs/native-bridge.md`, `docs/frontend.md`, **Stav** tohoto tasku,
   `python3 scripts/tasks-index.py`

## Hotovo když

- [x] ve vývoji `Host: localhost` i jméno s ID v cestě vrací zdroj dat
      (curl na `/<ID>/api/v1/_app/info`), v produkci IP → `UNKNOWN_HOST`
      (produkce jen unit testem — režim dev serveru se nepřepínal)
- [x] `devMode ? 'http' : 'https'` v kódu není; OIDC redirect a odkaz
      na nastavení hesla mají schéma požadavku
- [ ] `sudo shpd-server dev-https` na dev serveru s `httpsIp: true`
      vyrobí certifikát pro IP, site `shipard-https.conf`, projde
      `nginx -t`; `https://<IP>/<ID>/app/` po prokliku varování otevře
      aplikaci a `window.isSecureContext` je `true` (člověk)
- [ ] s `host` a certifikátem v `dev-host/` funguje
      `https://<jméno>/<ID>/app/` bez varování (člověk, kdo má certifikát)
- [ ] vypnutí všech https vstupů a nový běh `dev-https` site odstraní,
      http na IP běží dál (příkaz to má v testu; na serveru ověří člověk)
- [x] `dev-https --check` bez roota nic nemění a hlásí potřebu běhu;
      `dev-update.sh` vypíše hint
- [x] `doctor` hlásí vypršelý, expirující a nesedící certifikát podle U11
- [x] dashboard ukáže menu Adresa, hlášku na http (U23) a přepnutí zachová
      cestu (headless Chromium)
- [ ] testovací kolo z dashboardu otevřeného přes `https://<IP>` projde
- [x] produkční `shipard-tls.conf` dál posílá HSTS (`nginx -T` na
      produkční šabloně), vývojová https odpověď hlavičku HSTS nemá
      (dočasný nginx na vysokých portech)
- [x] dokumentace podle oddílu Dokumentace; `python3 scripts/tasks-index.py --check` projde
- [x] žádné názvy reálných zdrojů dat, firem ani osob
      (`scripts/check-sensitive.py`)
