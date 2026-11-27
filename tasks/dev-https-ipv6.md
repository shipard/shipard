# HTTPS na vývojovém serveru — IPv6 jen na vyžádání (#183 D8)

**Stav:** částečně — kód, testy a dokumentace hotové 2026-11-27 (2 commity; site s IPv6 a bez něj ověřený na dočasném nginx bez roota, `doctor` a `dev-https --check` naživo na dev serveru); zbývá člověk: `sudo shpd-server dev-https` na dev serveru, `ss -ltn` (443 jen na IPv4) a čistá sekce *Development HTTPS* v `doctor`

> Task pro Claude Code (2 commity). Design: issue #183 D8. Navazuje na
> `tasks/dev-https.md` (fáze 1). **Kroky s rootem** (`sudo shpd-server
> dev-https` na dev serveru) **dělá člověk.**

## Před implementací přečti

- issue #183 — D3, D4, D8; U5, U6, U11, U20
- `tasks/dev-https.md` — U2–U6, U11, U20 a stav implementace
- `src/Core/Server/DevAccess.php`, `DevHttpsPlanner.php`, `DevHttpsSite.php`,
  `DevHttpsDoctor.php`, `src/Core/Net/LocalAddresses.php`
- `docs/cli.md` — oddíl `dev-https` a `devAccess`; `docs/dev/dev-daily.md`
  — odstavec o ochraně dashboardu („důvěryhodná síť“)

## Kontext

Fáze 1 (U6) zapíná `listen [::]:443` u obou https bloků vždy, když má jádro
IPv6. Vývojový server s globální IPv6 adresou (SLAAC od routeru) tak
poslouchá i na adrese dosažitelné z internetu — pokud ji router nefiltruje.
Vývojářský dashboard `/_dev/` nemá přihlášení (spouští `ds-create`,
upgrady, testovací kola, ukazuje logy) a počítá s důvěryhodnou sítí;
na stejné adrese jsou i zdroje dat `realCopy`. Http blok na portu 80
poslouchá jen na IPv4, takže dřív Shipard na IPv6 vůbec nebyl.

## Rozhodnutí

**D8 — https na IPv6 jen na výslovné vyžádání.** Nový klíč
`devAccess.ipv6` (výchozí `false`). Bez něj https bloky poslouchají jen na
IPv4 jako http a certifikát pro IP nese jen adresy IPv4. `doctor` hlásí,
když Shipard poslouchá na IPv6 a server má globální IPv6 adresu.
Dokumentace k volbě říká, že dashboard je bez přihlášení. Veřejný provoz
(vlastní jména, přístupová politika pro `realCopy`) zůstává v oddílu
Později v #183.

## Upřesnění

- **U24 — konfigurace.** `devAccess.ipv6`: `true` / `false`, jiná hodnota
  = problém konfigurace (jako `httpsIp`) a platí `false`. `server-init`
  klíč nezapisuje.
- **U25 — adresy.** `DevAccess::addresses()` bez `ipv6` vrací jen IPv4
  (z `LocalAddresses::reachable()` a přepis `ip`); s `ipv6` i IPv6 jako dnes.
  Přepis `ip` s adresou IPv6 bez `ipv6: true` = problém konfigurace
  a přepis se nepoužije. Tím se řídí SAN certifikátu pro IP, `linkAddress()`
  i kontrola pokrytí adres v `doctor`. Stávající certifikát s IPv6 navíc
  se podle U20 neobnovuje — adresa navíc obnovu nespouští.
- **U26 — šablona.** Řádky `# ipv6` v `development-https.conf` se vykreslí,
  jen když `devAccess.ipv6` **a zároveň** jádro má IPv6
  (`/proc/net/if_inet6`). `ipv6: true` bez IPv6 v jádře = varování plánu
  (`dev-https` i `doctor`), bloky bez IPv6. Po nasazení `dev-https --check`
  na serveru se starým site souborem hlásí zastaralý site — `upgrade` ho
  přegeneruje, ručně `sudo shpd-server dev-https`.
- **U27 — `doctor`.** V sekci *Development HTTPS* nová kontrola: některý
  site soubor Shipardu (`shipard.conf`, `shipard-https.conf`) obsahuje
  `listen` na IPv6 (`[::]` nebo konkrétní adresa) **a** server má globální
  IPv6 adresu (ne loopback, link-local ani ULA `fc00::/7`) → varování
  s adresou a větou, že dashboard je bez přihlášení a síť musí blokovat
  příchozí spojení z internetu. Platí i při `ipv6: true` (záměr, ale
  připomínka zůstává) a i pro ručně upravený http site. ULA a server bez
  globální IPv6 → bez nálezu. Kontrolu zařadit tak, aby proběhla i bez
  zapnutého https vstupu.
- **U28 — dashboard.** Beze změny: vstupy a odkazy vedou na `linkAddress()`,
  ta je po U25 bez `ipv6` vždy IPv4.

## Upřesnění při implementaci

- **U29 — odkud `doctor` čte.** Kontrola U27 čte site soubory
  v `sites-enabled` (to, co nginx skutečně načte), ne `sites-available`.
  Seznam souborů je `DevHttpsPlanner::SHIPARD_SITES`, nález nese plán
  (`DevHttpsPlan::$ipv6ListenSites`) — počítá se i bez https vstupu.
- **U30 — globální adresa.** `LocalAddresses::globalIpv6()`: IPv6 adresy
  rozhraní bez loopbacku, link-local a ULA (`isUniqueLocal()`). Varování
  vypíše nejvýš tři adresy a počet zbylých (SLAAC s dočasnými adresami).
- **U31 — `ipv6: true` bez IPv6 v jádře.** Varování plánu
  (`DevHttpsPlan::$ipv6Unavailable`) jen se zapnutým https vstupem; bez
  něj klíč nic neřídí. `needsRun()` se tím nemění.
- **U32 — server jen s adresami IPv6.** Bez `ipv6: true` nemá žádnou
  adresu pro vstupy (http na portu 80 je jen na IPv4); hláška o chybějící
  adrese proto zmiňuje i `devAccess.ipv6`.
- **U33 — stávající testy.** Sdílená fixtura serveru nese i globální IPv6
  adresu; očekávání testů fáze 1 bez klíče `ipv6` jsou přepsaná na IPv4
  (je to zároveň regresní test D8), původní chování kryjí varianty
  s `ipv6: true`.

## Testy

- `DevAccessTest` — výchozí `ipv6` false, filtr adres, přepis IPv6 bez
  `ipv6`, neplatná hodnota.
- `DevHttpsPlannerTest` — site bez `[::]` při jádru s IPv6 a bez `ipv6`;
  s `ipv6` a jádrem s IPv6 s `[::]`; `ipv6` bez jádra = varování; SAN
  podle U25; starý site s `[::]` → zastaralý.
- `DevHttpsDoctorTest` — U27: `[::]` v https i ručně v http site
  s globální IPv6 → varování; ULA, jen link-local a bez IPv6 listen →
  bez nálezu; `ipv6: true` → varování.
- PHPUnit jen s úzkým `--filter`, celá sada lokálně na konci.

## Dokumentace

- `docs/cli.md` — `devAccess.ipv6` v tabulce klíčů, `listen [::]:443`
  a SAN podle U25/U26, nová kontrola `doctor`.
- `docs/dev/linux-install.md` — odstavec o IPv6: výchozí jen IPv4; kdy
  `ipv6: true` dává smysl a že dashboard je bez přihlášení; jméno se
  záznamem AAAA bez `ipv6` jde přes IPv4 (prohlížeč přejde na A záznam).
- `docs/dev/dev-daily.md` — v odstavci o ochraně dashboardu zmínit IPv6
  a kontrolu `doctor`.

## Commity

1. `feat(server): https na IPv6 jen na vyžádání, doctor hlídá globální IPv6 (#183 D8)` —
   `DevAccess`, `DevHttpsPlanner`, `DevHttpsDoctor`, testy
2. `docs(dev): https na IPv6 jen na vyžádání (#183 D8)` — dokumentace,
   **Stav** tohoto tasku, odkaz v `tasks/dev-https.md`,
   `python3 scripts/tasks-index.py`

## Hotovo když

- [x] bez `devAccess.ipv6` vygenerovaný `shipard-https.conf` nemá
      `listen [::]` ani na serveru s IPv6 v jádře
- [x] nový certifikát pro IP bez `ipv6` nese jen IPv4
- [x] `doctor` hlásí IPv6 listen při globální IPv6 adrese (i ručně v http
      site), při ULA a bez IPv6 listen mlčí
- [ ] na dev serveru po `sudo shpd-server dev-https` naslouchá Shipard
      na 443 jen na IPv4 (`ss -ltn`) a `doctor` je v sekci čistý (člověk)
- [x] dokumentace podle oddílu Dokumentace;
      `python3 scripts/tasks-index.py --check` projde
- [x] žádné názvy reálných zdrojů dat, firem ani osob
      (`scripts/check-sensitive.py`)
