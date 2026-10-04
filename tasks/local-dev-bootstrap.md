# Lokální vývoj na macOS a Windows — bootstrap, Multipass, WSL

**Stav:** částečně — skripty, cloud-init a docs hotové 2026-10-03 (4 commity, #96 D17–D25); ověřeno na čisté Multipass VM 26.04 (aarch64) podle `docs/local-dev.md`: cloud-init bootstrap, opakovaný běh s `--with-render --with-ssh`; zbývá WSL na Windows (čeká na Windows build `remote-dev-bridge`)

## Cíl

Rozjet vývojové prostředí Shipardu na Macu (Multipass) a na Windows (WSL)
co nejmenším počtem kroků: jeden příkaz na hostiteli, jeden bootstrap
uvnitř Ubuntu, na konci zelený `shpd-server doctor` a adresa vývojářského
dashboardu. Volitelně SSH pro `remote-dev-bridge` a render služba.

Ruční postup je ověřený na čistých Multipass VM (Ubuntu 24.04 i 26.04,
aarch64) — tasky `dev-install-fixes`, `dev-install-home-traverse`,
`secrets-openssl-cipher`, `dev-small-fixes`, `dev-install-followups`.
Bootstrap ho jen skládá dohromady; nesmí obcházet `install-packages.sh`
ani `DEVELOPERS.md` — opravy patří tam.

## Před implementací přečti

- `DEVELOPERS.md` (celý — ověřené pořadí kroků)
- `scripts/install-packages.sh`, `scripts/dev-update.sh`
- `src/Command/Server/ServerInitCommand.php` (idempotence, výstup)
- `docs/operations/render-service.md` (Single-server, Ověření)
- `docs/operations/permissions.md`
- `docs/ai-workflow.md` §1 a §4

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D6** — checkout žije uvnitř VM / WSL (`~/sw/shpd`), žádné mounty
  z hostitele.
- ✓ **D7** — bootstrap končí zeleným `doctor` a adresou dashboardu; DS se
  seedem si člověk založí v dashboardu.
- ✓ **D8** — render služba volitelně, výchozí vypnuto.
- ✓ **D16** — doporučená verze pro nové instalace je Ubuntu 26.04
  (24.04 podporovaná).
- ✓ **D17** — vstupní bod `scripts/dev-bootstrap.sh`. Běží jako běžný
  uživatel, `sudo` volá sám (aby `install-packages.sh` určil vývojáře
  přes `$SUDO_USER`). Spustitelný z checkoutu i jedním řádkem
  `curl -fsSL https://raw.githubusercontent.com/shipard/shipard/stable/scripts/dev-bootstrap.sh | bash`
  — pak si sám naklonuje repo přes HTTPS do `~/sw/shpd`. Idempotentní.
- ✓ **D18** — Multipass: cloud-init `scripts/multipass/shipard-dev.yaml`
  spustí bootstrap jako `ubuntu`, log do `~/shipard-bootstrap.log`;
  doporučeno `--cpus 2 --memory 4G --disk 20G`; veřejný SSH klíč pro
  bridge si člověk vloží do yaml před spuštěním. Jen výchozí síť
  Multipassu, nikdy bridged do LAN (dashboard nemá přihlášení).
- ✓ **D19** — WSL: `wsl --install Ubuntu-26.04` a uvnitř jednořádkový
  bootstrap; dashboard na `localhost`. Cloud-init ve WSL zatím ne.
- ✓ **D20** — `install-packages.sh` nastaví `DEBIAN_FRONTEND=noninteractive`
  a `NEEDRESTART_MODE=a` (interaktivní terminál se jinak může zastavit na
  dialogu needrestart).
- ✓ **D21** — `--with-render`: unit `shpd-render`, klíč `render`
  v `server.json` (ostatní klíče zachovat, nic z něj nevypisovat), ověření
  `/health`.
- ✓ **D22** — Claude Code bootstrap neinstaluje; jen na konci odkáže na
  návod.
- ✓ **D23** — souhrn na konci: adresa dashboardu (WSL `localhost`, jinak
  IP stroje), další kroky; každé selhání = nenulový exit a srozumitelná
  hláška, nikdy tiše.
- ✓ **D24** — nový `docs/local-dev.md` (Mac + Windows krok za krokem),
  odkaz z `DEVELOPERS.md` a `README.md`; `DEVELOPERS.md` zůstává referencí
  pro Linux server.
- ✓ **D25** — `--with-ssh` (určeno pro WSL, funguje kdekoli): `openssh-server`,
  port **2222**, jen přihlášení klíčem; veřejný klíč z volby
  `--ssh-pubkey-file <cesta>` (ve WSL typicky
  `/mnt/c/Users/<user>/.ssh/id_ed25519.pub`).

## Co je potřeba udělat

### 1. `scripts/install-packages.sh` (D20)

Na začátku (po parsování argumentů) `export DEBIAN_FRONTEND=noninteractive`
a `export NEEDRESTART_MODE=a`. Nic jiného se nemění.

### 2. `scripts/dev-bootstrap.sh` (D17, D21, D23, D25)

**Volby:** `--with-render`, `--with-ssh`, `--ssh-pubkey-file <cesta>`,
`--branch <větev>` (výchozí `stable`), `--dir <cesta>` (výchozí
`~/sw/shpd`), `-h|--help`. Neznámá volba = chyba.

**Kroky** (každý s nadpisem `==> …`, ať je log čitelný):

1. **Kontroly:** neběží jako root (jinak chyba s vysvětlením); `sudo` je
   k dispozici; OS Ubuntu 24.04/26.04 (stejná detekce jako v
   `install-packages.sh` — sdílet nebo okopírovat jednoduchou kontrolu,
   hlavní validaci dělá install skript). `--with-ssh` bez
   `--ssh-pubkey-file` (a bez existujícího `~/.ssh/authorized_keys`) =
   chyba. Na začátku jednou `sudo -v`, ať se heslo (WSL) zadá hned
   a ne uprostřed.
2. **Checkout:** když `--dir` neexistuje, `apt-get install git` (pokud
   chybí) a `git clone --branch <větev> https://github.com/shipard/shipard.git`;
   když existuje a je to git checkout, nic nestahovat (žádný `git pull`
   — bootstrap nesahá do rozpracované práce); jinak chyba.
3. `sudo bash scripts/install-packages.sh --mode=development`
4. `bash scripts/dev-update.sh`
5. `sudo shpd-server server-init --mode=development` (je idempotentní —
   „already initialized“ je v pořádku). Výstup nefiltrovat, jen
   nevypisovat nic navíc ze `server.json`.
6. `git config core.hooksPath .githooks` v checkoutu (pre-commit kontrola
   citlivých údajů a indexu tasků, post-pull `dev-update.sh` — viz
   `DEVELOPERS.md` kap. 7).
7. **`--with-render`:** podle `render-service.md` (Single-server) — unit
   do `/etc/systemd/system/`, `daemon-reload`, `enable --now`, čekání na
   `curl http://127.0.0.1:3000/health` (rozumný timeout, první stažení
   image trvá), doplnění `"render": {"url": "http://127.0.0.1:3000",
   "timeoutSec": 30}` do `/etc/shipard/server.json` jako root krátkým
   PHP (načíst JSON, doplnit klíč, zapsat se zachováním vlastníka
   `root:<user>` a módu `0640`; existující klíč `render` nepřepisovat).
8. **`--with-ssh`:** `openssh-server`; drop-in
   `/etc/ssh/sshd_config.d/shipard-dev.conf` s `Port 2222`,
   `PasswordAuthentication no`, `KbdInteractiveAuthentication no`; klíč
   z `--ssh-pubkey-file` připojit do `~/.ssh/authorized_keys` (bez
   duplicit, `~/.ssh` `0700`, soubor `0600`); restart služby. Pozor:
   Ubuntu ≥ 22.10 používá socket aktivaci (`ssh.socket`) a port čte
   generátor z `sshd_config` — po změně `daemon-reload` a restart
   `ssh.socket`; ověřit, že se naslouchá na 2222
   (`ss -ltn`).
9. **`shpd-server doctor`** — při ✗ konec s nenulovým kódem a odkazem na
   `fix-permissions --dry-run` a `docs/local-dev.md`.
10. **Souhrn (D23):** WSL se pozná podle `/proc/sys/kernel/osrelease`
    (obsahuje `microsoft`/`WSL`) → `http://localhost/_dev/`; jinak
    `http://<první IP z hostname -I>/_dev/`. Dál: „založ zdroj dat
    v dashboardu (+ New DS, s testovacími daty)“, odkaz na
    `docs/local-dev.md` (Claude Code, bridge), u `--with-ssh` údaje pro
    bridge (host, port, uživatel, root checkoutu).

Skript `set -euo pipefail`; žádné `$(…)` pipeline, které by při chybějícím
příkazu tiše ukončily běh (viz `dev-install-fixes`). Hesla, tokeny ani
obsah `server.json` se nikdy nevypisují.

### 3. `scripts/multipass/shipard-dev.yaml` (D18)

Cloud-init pro `multipass launch … --cloud-init`:

- `users: [default]` + `ssh_authorized_keys` s jasně označeným místem
  pro vlastní veřejný klíč (komentář v češtině, co tam vložit a proč);
- `runcmd`: bootstrap jako `ubuntu` (`sudo -u ubuntu -H bash -c '…'`),
  výstup do `/home/ubuntu/shipard-bootstrap.log`;
- žádné heslo uživatele, žádná změna sítě.

V `docs/local-dev.md` popsat, jak sledovat průběh
(`multipass exec shipard -- cloud-init status --wait`,
`multipass exec shipard -- tail -f shipard-bootstrap.log`) a jak zjistit
IP (`multipass info shipard`).

### 4. `docs/local-dev.md` (D24)

Pro člověka, který Linux server nikdy nespravoval. Česky, krok za krokem:

1. **Co to je a co budeš potřebovat** — Mac s Apple silicon nebo Intel /
   Windows 10 21H2+ nebo 11; ~20 GB disku, 4 GB RAM pro VM; účet na
   GitHubu jen pro přispívání.
2. **macOS — Multipass:** instalace Multipassu (odkaz na oficiální
   stránku), stažení `shipard-dev.yaml`, vložení klíče, `multipass
   launch 26.04 …`, sledování průběhu, otevření dashboardu. Upozornění:
   výchozí síť, ne bridged.
3. **Windows — WSL:** `wsl --install Ubuntu-26.04`, první spuštění
   (vytvoření uživatele), jednořádkový bootstrap, volitelně `--with-ssh`
   s klíčem z Windows. Checkout v linuxovém FS (`~/sw/shpd`), nikdy
   v `/mnt/c` (D6).
4. **První zdroj dat** — dashboard → + New DS s testovacími daty →
   Open → přihlášení.
5. **Napojení na Claude** — krátce, s odkazem na `docs/ai-workflow.md`:
   Claude Code uvnitř VM / WSL (na běžnou práci stačí); `remote-dev-bridge`
   pro Claude v chatu (designová práce, víc strojů) — cíl: host, port
   (Multipass 22, WSL 2222), uživatel, root `~/sw/shpd`. macOS: podepsané
   vydání bridge, jinak „No route to host“ (oprávnění Místní síť).
6. **Každodenní práce** — `git pull` (hooky spustí `dev-update.sh`),
   `shpd-server ds-upgrade-all` po změně tabulek, `doctor` při potížích.
7. **Něco nefunguje?** — `doctor`, `fix-permissions --dry-run` (z
   neinteraktivního shellu `--force`), log bootstrapu, Discord / issue.
   Zmínka o deprecation výpisech composeru na 24.04 (neškodné).

Odkaz na `docs/local-dev.md` z `DEVELOPERS.md` (úvod: „Mac nebo
Windows? → …“) a z `README.md`; řádek do `docs/README.md`.

## Commit strategie

1. `install-packages: neinteraktivní apt (#96 D20)`
2. `scripts: dev-bootstrap.sh (#96 D17, D21, D23, D25)`
3. `scripts: cloud-init pro Multipass (#96 D18)`
4. `docs: lokální vývoj na macOS a Windows (#96 D19, D24)` — včetně
   odkazů, hlavičky tasku a `tasks/README.md` (oblast „Server, CLI
   a provoz“) + `python3 scripts/tasks-index.py`

## Ověření

Implementace na dev serveru; spouštění na čistých strojích dělá člověk,
případně Claude v chatu přes bridge:

- `bash -n scripts/dev-bootstrap.sh`, `--help`, neznámá volba, běh jako
  root → chyba;
- **Multipass 26.04** z `shipard-dev.yaml`: po `cloud-init status --wait`
  zelený `doctor`, souhrn s IP, `/_dev/` 200, bridge se připojí klíčem
  z yaml;
- **opakovaný běh** bootstrapu na hotovém stroji: projde, nic nerozbije,
  `server-init` „already initialized“;
- **`--with-render`** na VM: `/health` ok, `server.json` má `render`
  a zachované ostatní klíče, `doctor` sekce Render ✓;
- **WSL 26.04** (Windows, ověřuje člověk): jednořádkový bootstrap s
  `--with-ssh --ssh-pubkey-file …`, dashboard na `localhost`, bridge
  přes `localhost:2222`. Ověřit, zda WSL po zavření terminálu instanci
  nezastaví (pak bridge nepřipojí) — výsledek a případné doporučení
  zapsat do `docs/local-dev.md`.

## Hotovo když

- [x] `install-packages.sh` nastavuje neinteraktivní apt
- [x] bootstrap z čistého Ubuntu 26.04 skončí zeleným `doctor`
      a souhrnem s adresou dashboardu
- [x] opakovaný běh je bezpečný — `server-init` „already initialized“, nic
      nerozbito
- [x] Multipass z `shipard-dev.yaml` bez ručního zásahu (kromě vložení klíče)
      — bridge se připojil klíčem z yaml
- [x] `--with-render` a `--with-ssh` fungují dle popisu — `/health` ok,
      `server.json` `root:ubuntu 0640` se zachovanými klíči, sshd na 22 i 2222,
      souhrn s IP VM i po vzniku `podman0`
- [ ] WSL ověřené na Windows (nebo v tasku poznamenáno, co zbývá)
- [x] `docs/local-dev.md` s odkazy z `DEVELOPERS.md`, `README.md`,
      `docs/README.md`
- [x] hlavička tasku a `tasks/README.md` aktualizované

## Stav implementace (2026-10-03)

Hotové jsou všechny čtyři commity. Na dev serveru není `sudo`, Multipass
ani WSL, takže skript běžel jen **nasucho**: `bash -n`, `--help`, chybné
volby, běh jako root, a celý průchod proti maketám `sudo` / `systemctl` /
`sshd` / `curl` (první běh, opakovaný běh, větve selhání, klon přes
`curl | bash`). Úprava `server.json` je ověřená na kopii (ostatní klíče,
`{}` i `30.0` zůstávají, existující `render` se nepřepisuje, vlastník
a mód se přenášejí).

### Odchylky od textu zadání

- **`sudo -v`** se volá až po neúspěšném `sudo -n true` a jen s terminálem.
  Uživatel `ubuntu` na cloud image je i ve skupině `sudo` (řádek bez
  `NOPASSWD`), `sudo -v` by proto pod cloud-initem chtělo heslo. Platnost
  sudo během běhu drží keep-alive na pozadí.
- **`--with-ssh` mimo WSL** nechává vedle 2222 i porty, na kterých sshd
  naslouchal dosud (samotné `Port 2222` ruší výchozí 22 → na Multipass VM
  by přestal fungovat `multipass shell`). Ve WSL jen 2222.
- **Efektivní konfigurace sshd** se po zápisu drop-inu ověřuje přes
  `sshd -T`; když přihlášení heslem zapíná jiný soubor dřív, drop-in se
  odstraní a skript skončí chybou.
- **`multipass launch --timeout 1800`** v návodu — bez něj launch po
  5 minutách ohlásí chybu, i když instalace pokračuje. V `runcmd` je
  `set -o pipefail`.
- **Výchozí `--dir`** při spuštění z checkoutu je ten checkout; kontrola
  běžícího systemd (WSL 1 / WSL bez systemd) hned v kroku 1.
- Skript je navíc popsaný v `docs/cli.md` (Pomocné skripty).

### Zbývá ověřit na čistých strojích

Podle sekce Ověření; u každého bodu na co se dívat:

- **Multipass 26.04 z yaml** — projde krok 1 bez terminálu (`sudo -n`);
  `multipass shell` funguje i s vlastním klíčem v `ssh_authorized_keys`;
  `cloud-init status --wait` → `done`; bridge se připojí na port 22.
- **Opakovaný běh** na hotovém stroji.
- **`--with-render`** — `/health`, klíč `render`, `doctor` sekce Render ✓.
- **`--with-ssh` na VM** — drop-in obsahuje `Port 22` i `Port 2222`,
  restart `ssh.socket` na 26.04 proběhne, `multipass shell` dál funguje.
- **WSL 26.04** — jednořádkový bootstrap s `--with-ssh`, dashboard na
  `localhost`, bridge přes `localhost:2222`; zda je v image `curl`; zda
  WSL po zavření terminálu instanci zastaví (`docs/local-dev.md` zatím
  jen doporučuje nechat okno otevřené — podle výsledku upřesnit).

## Mimo rozsah

- Instalace Claude Code, přihlášení, `gh auth` (D22) — návod
  `docs/claude-code-intro.md` (D10).
- Veřejná dokumentace `remote-dev-bridge` v `ai-workflow.md`, zúžení
  `dev-env` (D4) — samostatný task.
- Cloud-init ve WSL, Docker / Dev Containers.
- Windows build `remote-dev-bridge` (repo `shipard/remote-dev-bridge`).
