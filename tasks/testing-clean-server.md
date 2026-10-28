# F8b — testovací kolo na čistém serveru (Multipass)

**Stav:** částečně — kód a docs hotové 2026-10-27 (#146 D1–D9), ověřeno nanečisto proti maketám; první běh na Macu (AVF) našel N3–N10 — k opravě N3 a N5–N10 (N7 blokuje nasazení na testovací server), N4 po běhu na QEMU; pak ruční ověření 1–4 na AVF i QEMU, časy do § 22 a výsledek do #146

## Kontext / Cíl

Testovací kolo (`shpd-server test-round`, #103 F8a) běží na dlouho žijícím
vývojářském serveru s nahromaděným stavem: nainstalované nástroje, staré
zdroje dat, ručně doladěná konfigurace. Že instalace a provisioning fungují
i na čistém stroji, dnes nic neověřuje — poslední čistá instalace proběhla
ručně při #96.

**Cíl:** jedním příkazem na hostiteli založit čistou Multipass VM, nainstalovat
Shipard **stejnou cestou jako nový vývojář**, pustit celé testovací kolo
(volitelně s evaluací), výsledky odnést z VM a VM po úspěchu zahodit. Kolo
tím testuje i návod `docs/dev/local-dev.md` a bootstrap.

Dnes by na čistém stroji vrstvy sad (proklikání) a videí skončily `failed`
v preflightu: bootstrap neinstaluje `ffmpeg` ani Playwright a jeho Chromium
pro `tests/e2e` a `tools/video-runner`, návod je zmiňuje jen větami
v `docs/dev/dev-daily.md` § 1. Proto D3.

## Návaznost

- **#146** D1–D9 (komentář 2026-10-27)
- **#103** F8a — D130–D135 (`tasks/testing-phase8a.md`), D10 (serverové AI
  připojení), D131 (evaluace jen výslovně a se stropem), D132 (kolo si
  zakládá vlastní zdroje dat)
- **#96** — bootstrap, cloud-init, Multipass (`tasks/local-dev-bootstrap.md`,
  D16–D25)
- Incus (vyhrazený server, plánování) je další fáze #146 — mimo tento task.

## Před implementací přečti

- `scripts/dev-bootstrap.sh`, `scripts/multipass/shipard-dev.yaml`,
  `scripts/dev-update.sh`, `scripts/install-packages.sh` (neinteraktivní apt, D20 #96)
- `tasks/local-dev-bootstrap.md` — hlavně *Odchylky od textu zadání*
  (`sudo -n` bez terminálu, `multipass launch --timeout`, `set -o pipefail`
  v `runcmd`)
- `docs/dev/local-dev.md` (kapitoly 2 a 6), `docs/dev/dev-daily.md` § 1
  (Proklikat, Videa) a § 5
- `docs/test-corpus.md` § 19 (preflight proklikání `E2ePreflight`), § 20
  (preflight videí `VideoPreflight`, Gotenberg), § 21 (testovací kolo:
  exit kódy, sledování, adresář úloh, evaluace v kole a proč potřebuje
  existující zdroj dat kola)
- `src/Command/Server/TestRoundCommand.php`, `src/Command/Server/DevAiKeyCommand.php`
  (`--set-key` čte STDIN bez TTY), `src/Core/DevJobs/JobStore.php` (adresář úloh)
- `docs/cli.md` § Pomocné skripty

## Rozhodnutí (potvrzená, #146)

- ✓ **D1 — Multipass teď, incus v další fázi, WSL se neautomatizuje.**
  Řídicí skript `scripts/multipass/test-round.sh` běží na hostiteli (macOS,
  Linux s Multipassem). WSL ne: všechny distribuce WSL 2 sdílejí síťový
  namespace (kolize portů 80, 3306, 2222, 3000 s vývojářovou distribucí),
  řídicí skript by musel být v PowerShellu a specifika WSL jsou věc
  instalace, ne kola — ověřují se ručně.
- ✓ **D2 — Instalace cestou nováčka a podruhé.** VM vznikne
  z `shipard-dev.yaml` testované větve (mění se jen větev) → cloud-init
  bootstrap přesně podle návodu; pak přes `multipass exec` druhý běh
  bootstrapu s volbami pro kolo — ověří i idempotenci a doplnění volitelných
  částí, které návod slibuje.
- ✓ **D3 — `dev-bootstrap.sh --with-testing`** (zahrnuje `--with-render`):
  `ffmpeg`, `npm ci` v `tests/e2e` a `tools/video-runner`, Chromium
  Playwrightu pod vývojářem a jeho systémové závislosti. `dev-update.sh` po
  `git pull` tyto závislosti aktualizuje, pokud existují.
- ✓ **D4 — Testuje se pushnutý kód z GitHubu:** výchozí `stable`,
  `--branch` pro jinou větev; lokální nepushnuté změny ne. Image Ubuntu
  26.04, `--image 24.04` volitelně; architektura podle hostitele.
- ✓ **D5 — Kolo celé (kód, sady, videa) bez evaluací; evaluace volitelně**
  druhým voláním `test-round --only datasets --eval …` (odhad potřebuje
  zdroj dat kola, který založí první kolo). Klíč ze souboru na hostiteli
  (`--ai-key-file`) rourou do `sudo shpd-server dev-ai-key --set-key` —
  nikdy v argv ani v logu. Evaluace je součástí tohoto tasku.
- ✓ **D6 — Výsledky na hostiteli mimo checkout** (`~/shipard-rounds/<vm>/`):
  souhrn a log kola, adresáře úloh, logy obou běhů bootstrapu, `doctor`,
  `environment.json` (verze, commit, časy fází). Exit skriptu = exit kola;
  2 = instalace selhala nebo kolo nezačalo. Do issue se výsledek zapisuje
  ručně.
- ✓ **D7 — VM `shipard-round-<čas>`,** výchozí síť Multipassu, žádný SSH
  klíč, 4 CPU / 8 GB / 30 GB (volby). Po úspěchu se smaže, **po selhání
  zůstane** (výpis, jak se do ní dostat a jak ji smazat), `--keep` ji
  nechá vždy.
- ✓ **D8 — Bez mezipamětí.** Čistý server stahuje vše; časy fází se měří
  (D6). O klonu VM po bootstrapu nebo snapshotech se rozhodne podle čísel,
  a jen pro opakování kola, ne pro test instalace.
- ✓ **D9 — Spouští se ručně z terminálu hostitele;** plánování a CI až
  s incusem. Dokumentace `docs/test-corpus.md` § 22, `--with-testing`
  v `docs/dev/`.
- ✓ **D10 — `doctor` kontroluje rozšíření PHP** podle `ext-*`
  z `composer.json` (✗ s názvem balíčku k doinstalování); chybějící
  rozšíření se tak už neschová za náhodně vybavený server (z N7,
  2026-10-27).

## Upřesnění pro implementaci

- **U1 — Řídicí skript je jeden samostatný soubor.** Na hostiteli nemusí
  být checkout: člověk ho stáhne `curl -fsSLO …/stable/scripts/multipass/test-round.sh`
  stejně jako `shipard-dev.yaml`. Žádné další soubory z repa, žádné `jq`
  ani `python` na hostiteli. Musí běžet v **bash 3.2** (výchozí na macOS)
  s BSD i GNU nástroji: žádná asociativní pole, `mapfile`, `${x,,}`,
  `sed -i`, `date -d`, `readlink -f`, `grep -P`; `PIPESTATUS` je v pořádku.
- **U2 — `shipard-dev.yaml` ze serveru, ne z disku.** Stáhne se z testované
  větve (`https://raw.githubusercontent.com/shipard/shipard/<větev>/scripts/multipass/shipard-dev.yaml`);
  HTTP 404 = neznámá větev, konec **před** `multipass launch` (exit 2).
  Pro `stable` se yaml použije beze změny. Pro jinou větev se nahradí jen
  `…/stable/scripts/dev-bootstrap.sh` → `…/<větev>/scripts/dev-bootstrap.sh`
  a `| bash >` → `| bash -s -- --branch <větev> >`; **každá náhrada musí
  trefit právě jedno místo**, jinak konec s větou „shipard-dev.yaml se
  změnil — uprav scripts/multipass/test-round.sh“. Zástupný řádek SSH klíče
  zůstává (není to platný klíč — do VM se jde jen přes `multipass exec`).
  Použitý yaml se uloží do výsledků.
- **U3 — Ruční postup je v návodu, bootstrap ho skládá.** Nástroje pro
  testování (D3) se nejdřív popíšou v `docs/dev/dev-daily.md` § 5 (platí pro
  Multipass, WSL i Linux) a `--with-testing` provede přesně ty kroky —
  stejný princip jako u ostatních kroků bootstrapu (`tasks/local-dev-bootstrap.md`).
- **U4 — Kolo bez `--json`.** Průběh kola jde do terminálu a přes `tee` do
  `round.log` (exit přes `PIPESTATUS`); strojový souhrn je `result.json`
  úlohy kola v archivu úloh. Před startem kola skript vypíše adresu
  dashboardu VM (`http://<IPv4 z multipass info>/_dev/`) — kolo jde sledovat
  živě v prohlížeči.
- **U5 — Ctrl+C** = exit 130, VM zůstane (jako selhání), výpis jak ji smazat.

## Co je potřeba udělat

### 1. Nástroje pro testování — návod, bootstrap, `dev-update.sh` (D3, U3)

**`docs/dev/dev-daily.md` § 5** — nová podsekce *Nástroje pro proklikání,
videa a testovací kolo*. Kdy je potřeba (Proklikat, Videa, `test-round`),
že patří **uživateli, pod kterým běží php-fpm** (na Multipassu `ubuntu`,
jinde vývojář) a ruční postup z kořene checkoutu:

1. render služba — `--with-render` / `docs/operations/render-service.md`;
2. `sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get install -y ffmpeg`;
3. `npm ci` v `tests/e2e` a v `tools/video-runner`;
4. systémové závislosti Chromia jako root **lokálním CLI Playwrightu**
   (ne `npx` — stahoval by z registru a pod `sudo` nemusí najít Node):
   `sudo env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a "$(command -v node)" tests/e2e/node_modules/@playwright/test/cli.js install-deps chromium`;
5. Chromium pod vývojářem pro oba balíčky (stejná revize = druhý příkaz nic
   nestahuje): `node tests/e2e/node_modules/@playwright/test/cli.js install chromium`
   a obdobně CLI v `tools/video-runner/node_modules/playwright/`.

Přesné cesty k CLI ověř v `node_modules` (Playwright 1.62). Věty
v § 1 (Proklikat, Videa) a v `docs/test-corpus.md` § 19–20, které dnes
uvádějí `cd tests/e2e && npm ci && npx playwright install chromium`,
přepiš na odkaz na tuto podsekci (jeden zdroj postupu).

**`scripts/dev-bootstrap.sh`** — volba `--with-testing` (nastaví i
`WITH_RENDER=1`), krok `setup_testing` po `setup_render`:

- kroky 2–5 výše, idempotentně (`npm ci` při každém běhu je v pořádku);
  každé selhání `fail` s větou, co se nepovedlo;
- `npm` a `node` pod vývojářem, `apt-get` a `install-deps` přes `sudo`
  (stejně jako ostatní kroky — `sudo -n` bez terminálu, viz odchylky
  v `tasks/local-dev-bootstrap.md`);
- `usage()`, komentář v hlavičce skriptu, závěrečný souhrn (že jsou
  k dispozici Proklikat, Videa a `shpd-server test-round`).

**`scripts/dev-update.sh`** — pokud existuje `tests/e2e/node_modules`,
resp. `tools/video-runner/node_modules`: `npm install --no-audit --no-fund`
v daném adresáři (stejně jako frontend) a `… cli.js install chromium` pod
aktuálním uživatelem. Bez `node_modules` se krok přeskočí bez hlášky
(vývojář nástroje nechce). Systémové závislosti ne (chtějí root) — když nová
revize Chromia potřebuje nové knihovny, ozve se preflight; do § 5 věta, že
pak pomůže krok 4. Číslování `[1/3]` … uprav.

### 2. `scripts/multipass/test-round.sh` (D1, D2, D4–D8, U1, U2, U4, U5)

```
bash test-round.sh [volby] [-- <volby pro shpd-server test-round>]

  --branch <větev>          testovaná větev na GitHubu (výchozí stable)
  --image <verze>           26.04 (výchozí) nebo 24.04
  --cpus <n> --memory <x> --disk <x>   výchozí 4 / 8G / 30G
  --out <adresář>           kořen výsledků (výchozí ~/shipard-rounds)
  --keep                    VM nechat i po úspěchu
  --eval <varianta> --repeat <n> --max-cost <USD> --ai-key-file <soubor>
                            evaluace po kole (všechny čtyři volby spolu)
  --eval-dataset <sada>     sada pro evaluaci (opakovatelné; výchozí všechny)
  -h, --help
```

Vše za `--` jde beze změny do prvního `shpd-server test-round` (`--only`,
`--dataset`, `--scenario`). Kombinaci, se kterou evaluace nemůže proběhnout
(první kolo bez vrstvy sad), nehlídá skript — `test-round` ji odmítne
s vlastní větou (odhad bez zdroje dat kola, `docs/test-corpus.md` § 21).

Průběh (každá fáze s časem začátku a konce do `environment.json`):

1. **Preflight hostitele** — `multipass` v `PATH` (verze do výsledků),
   `curl`, zapisovatelný `--out`; s evaluací čitelný neprázdný
   `--ai-key-file` a úplná sada voleb; kontrola hodnot voleb. Selhání = exit 2,
   žádná VM.
2. **yaml** (U2) → `<out>/<vm>/shipard-dev.yaml`.
3. **Launch** — `multipass launch <image> --name <vm> --cpus … --memory …
   --disk … --timeout 3600 --cloud-init <yaml>`. Nenulový exit launch
   s existující VM není konec (timeout) — pokračuje se čekáním.
4. **Cloud-init** — `multipass exec <vm> -- cloud-init status --wait`,
   pak `cloud-init status --long` do `cloud-init.txt` a kopie
   `~/shipard-bootstrap.log` jako `bootstrap-1.log`. `error` (nebo VM
   neexistuje) = exit 2, VM zůstane; `degraded` = pokračovat s varováním
   v souhrnu.
5. **Druhý bootstrap** (D2) — `multipass exec <vm> -- bash ~/sw/shpd/scripts/dev-bootstrap.sh --with-testing`,
   výstup do `bootstrap-2.log` (a do terminálu); nenulový exit = exit 2,
   VM zůstane.
6. **Klíč AI** (jen s evaluací, D5) — `multipass exec <vm> -- sudo shpd-server dev-ai-key --set-key < "$AI_KEY_FILE"`
   a `dev-ai-key --status` do logu. Klíč se nikdy neobjeví v argv, v proměnné
   vypsané na výstup ani v souboru výsledků.
7. **Kolo** (U4) — adresa dashboardu VM do terminálu; `multipass exec <vm>
   -- bash -lc 'cd ~/sw/shpd && shpd-server test-round <volby za -->'` pod
   uživatelem `ubuntu` (vlastník adresáře úloh — **nikdy `sudo`**), `tee`
   do `round.log`, exit kola.
8. **Evaluace** (jen s `--eval`) — po prvním kole bez ohledu na jeho exit,
   pokud kolo začalo (exit ≠ 2): `test-round --only datasets [--dataset <sada>]…
   --eval <varianta> --repeat <n> --max-cost <USD>`, `tee` do
   `round-eval.log`.
9. **Sběr** — `shpd-server doctor` do `doctor.txt`; archiv
   `/opt/shipard/run/dev-jobs` (`tar -czf -` přes `multipass exec` na
   stdout) jako `jobs.tgz`; s evaluací nové soubory v `tests/eval/`
   (`git status --porcelain tests/eval`) jako `eval.tgz` — v checkoutu VM
   zůstanou necommitnuté a s VM zaniknou; `environment.json`: hostitel
   (OS, architektura, verze Multipassu), VM (jméno, image, CPU/RAM/disk),
   v VM `/etc/os-release` (`VERSION_ID`), `uname -m`, verze PHP, MariaDB,
   Node, npm, commit a větev checkoutu, časy a exit kódy fází. JSON se
   skládá v bashi (hodnoty bez uvozovek a zpětných lomítek, ošetři je).
   Sběr běží i po selhání, pokud VM existuje — co nejde sebrat, chybí se
   zápisem do souhrnu, sběr kvůli tomu nekončí chybou.
10. **Souhrn** do terminálu a do `summary.txt`: fáze s časy a stavem, exit
    kola a evaluace, cesta k výsledkům, osud VM.
11. **Úklid** (D7) — vše `ok` a bez `--keep` → `multipass delete --purge <vm>`;
    jinak VM zůstane a souhrn vypíše `multipass shell <vm>`
    a `multipass delete --purge <vm>`.

**Exit:** `0` vše prošlo (kolo i případná evaluace exit 0); `1` kolo nebo
evaluace skončily `1` (selhání, přeskočený krok, strop útraty); `2` preflight,
launch, cloud-init nebo druhý bootstrap selhaly, nebo kolo nezačalo (exit 2
kola); `130` přerušeno (U5).

**Výsledky** — `<out>/<vm>/`:

```
summary.txt          souhrn z terminálu
environment.json     hostitel, VM, verze, commit, časy a exit kódy fází
shipard-dev.yaml     použitý cloud-init
cloud-init.txt       cloud-init status --long
bootstrap-1.log      ~/shipard-bootstrap.log (cloud-init, cesta nováčka)
bootstrap-2.log      druhý běh s --with-testing
round.log            průběh kola
round-eval.log       průběh kola s evaluací (jen s --eval)
doctor.txt           shpd-server doctor po kole
jobs.tgz             /opt/shipard/run/dev-jobs — kolo a dílčí úlohy
eval.tgz             nové soubory tests/eval/ (jen s --eval)
```

Hlavička skriptu: k čemu je, jak ho stáhnout a spustit, že běží na
hostiteli (macOS, Linux; Windows ne — D1), odkaz na `docs/test-corpus.md` § 22.
Hlášky a souhrn česky, nadpisy fází anglicky jako v `dev-bootstrap.sh`.

### 3. Dokumentace (D9)

- **`docs/test-corpus.md` § 22 *Testovací kolo na čistém serveru (F8b)*:**
  proč (D1–D2), jak spustit (stažení skriptu, příklady: stable, větev,
  `-- --only datasets`, evaluace), průběh a výsledky, exit kódy, osud VM,
  klíč AI, odhad doby (z ověření), pasti; odkaz z § 21.
- **`docs/dev/local-dev.md`** — `--with-testing` v tabulce voleb kapitoly 6
  a věta, kdy ho použít (odkaz na `dev-daily.md` § 5).
- **`docs/cli.md` § Pomocné skripty** — `--with-testing` v tabulce opcí
  `dev-bootstrap.sh`, `dev-update.sh` (nové kroky), nový oddíl
  `scripts/multipass/test-round.sh` a řádek v přehledové tabulce.
- **`docs/dev/dev-daily.md` § 5** — z kroku 1.

## Commit strategie

1. `docs, scripts: nástroje pro testování — dev-daily § 5, dev-bootstrap --with-testing, dev-update (#146 D3)`
2. `scripts: multipass/test-round.sh — testovací kolo na čisté VM (#146 D1, D2, D4–D8)`
3. `docs: testovací kolo na čistém serveru — test-corpus § 22, local-dev, cli (#146 D9)`
   + `**Stav:**` tohoto tasku a `python3 scripts/tasks-index.py`

## Ověření

**Claude Code** (vývojářský server nemá Multipass ani `sudo`, skripty jdou
jen nanečisto — jako u `tasks/local-dev-bootstrap.md`):

- `bash -n` obou skriptů, `--help`, chybné a neúplné volby (evaluace bez
  `--ai-key-file`, neznámá volba, prázdná hodnota);
- `grep` zakázaných konstrukcí z U1 v `test-round.sh`;
- `test-round.sh` proti **maketě `multipass`** v `PATH` (skript, který
  zapisuje argv do souboru a simuluje `version`, `launch`, `exec`, `info`,
  `delete`) a maketě `curl` (yaml ze stable, 404):
  - úspěch → `delete --purge`, výsledky kompletní, exit 0;
  - cloud-init `error` → exit 2, bez kola, VM zůstane;
  - kolo exit 1 → sběr proběhne, VM zůstane, exit 1; `--keep` při úspěchu;
  - neznámá větev (404) → exit 2 bez `launch`; větev ≠ stable → v yaml obě
    náhrady právě jednou, stable → yaml beze změny; yaml bez očekávaného
    řádku → věta z U2;
  - evaluace: obsah klíčového souboru se nevyskytuje v argv makety ani ve
    výsledcích (`grep -rFf <klíčový soubor> <log argv> <výsledky>` → nic —
    `-f`, aby klíč nebyl v argv grepu); evaluace proběhne i po kole s exit 1,
    po kole s exit 2 ne;
  - Ctrl+C (SIGINT) během kola → exit 130, VM zůstane;
- `dev-update.sh` na vývojářském serveru reálně (`tests/e2e/node_modules`
  existuje → proběhne; dočasně přejmenované → krok přeskočen);
- `dev-bootstrap.sh --with-testing` nanečisto proti maketám `sudo` / `apt-get`
  / `node` / `npm` (pořadí kroků, uživatel vs. root, opakovaný běh).

Celou sadu PHPUnit pouštět není potřeba (mění se jen skripty a docs).

**Člověk** (Mac s Multipassem; skript i yaml se berou z GitHubu, takže až
po pushnutí — a pak pár minut počkat, `raw.githubusercontent.com` drží
soubory v mezipaměti až 5 minut, viz N2):

1. `bash test-round.sh` (stable, 26.04) → exit 0, VM smazaná, výsledky
   kompletní; časy fází do § 22.
2. `bash test-round.sh -- --scenario neexistuje` → kolo nezačne (exit 2),
   VM zůstane, souhrn vypíše, jak ji smazat; smazat.
3. **Evaluace — jen po schválení v chatu** (stojí peníze):
   `bash test-round.sh --eval baseline --repeat 1 --max-cost 1 --eval-dataset cz-non-payer --ai-key-file <soubor>`
   → evaluace v souhrnu a v `eval.tgz`; `grep -rFf <soubor s klíčem> ~/shipard-rounds/<vm>/` nic nenajde.
4. Volitelně `--image 24.04`.

Adresář výsledků vyhodnotí Claude v chatu a výsledek jde komentářem do #146.

## Hotovo když

- [x] `dev-daily.md` § 5 popisuje nástroje pro testování; § 1 a `test-corpus.md` § 19–20 na ni odkazují
- [x] `dev-bootstrap.sh --with-testing` (implikuje render), idempotentní, popsaný v `usage`, `cli.md`, `local-dev.md`
- [x] `dev-update.sh` aktualizuje závislosti `tests/e2e` a `tools/video-runner`, jen když existují
- [x] `scripts/multipass/test-round.sh` podle kroku 2, bash 3.2, bez `jq` / `python` na hostiteli
- [x] klíč AI jen přes STDIN, nikde v argv ani ve výsledcích (ověřeno maketou)
- [x] VM po úspěchu smazaná, po selhání / Ctrl+C zůstává s návodem
- [x] `docs/test-corpus.md` § 22, odkaz z § 21
- [ ] ověření nanečisto prošlo (2026-10-27); ruční ověření 1–3 na Macu (člověk) s výsledkem v #146
- [x] `**Stav:**` + `tasks-index.py`

## Stav implementace (2026-10-27)

Tři commity podle strategie. Vývojový server nemá Multipass ani `sudo`,
takže `test-round.sh` a `dev-bootstrap.sh --with-testing` běžely jen
**nasucho** proti maketám (`multipass`, `curl`, `sudo`, `apt-get`, `node`,
`npm`); `dev-update.sh` reálně (kroky `[4/5]` a `[5/5]` proběhly,
Chromium už byl — bez stahování; přeskočení bez `node_modules` ověřeno
v kopii struktury repa s maketami). Scénáře z Ověření prošly všechny:
úspěch → `delete --purge` + kompletní výsledky + exit 0; `--keep`;
cloud-init `error` → exit 2 bez kola, `degraded` → varování; kolo exit 1 →
sběr, VM zůstane, exit 1; kolo exit 2 → exit 2; druhý bootstrap selhal →
exit 2 se sběrem; `launch` nenulový s živou VM → pokračuje; bez VM → exit 2;
neznámá větev 404 → exit 2 bez `launch`; `feature/x` → obě náhrady v yaml
právě jednou, `stable` → yaml shodný; yaml bez vzoru → věta z U2; evaluace
po kole exit 1 proběhne, po exit 2 ne, `grep -rFf <klíč>` nad argv
i výsledky nic; SIGINT během kola → exit 130, VM zůstane, bez `delete`.
`shellcheck` na serveru není; bash 3.2 také ne — kompatibilita ověřena
jen `grep`em zakázaných konstrukcí, skutečný běh na Macu je na člověku.

### Odchylky od textu zadání

- **`ai-key.log`** navíc ve výsledcích: výstup `dev-ai-key --set-key`
  a `--status` (jen existence a práva klíče) — `round.log` v tu chvíli
  ještě neexistuje.
- **Adresa dashboardu** se zjišťuje hned po `launch`, ne až před kolem —
  souhrn po selhání cloud-initu nebo druhého bootstrapu ji tak nese také.
- **Ctrl+C**: sběr z VM se neprovádí (VM zůstává se vším), `summary.txt`
  a `environment.json` se zapíší; při přerušení během kola nebo evaluace
  poznámka, že kolo ve VM může běžet dál jako úloha dashboardu
  (`multipass exec` padne, úloha `round` ne) — N1.
- **`jobs.tgz` / `eval.tgz`** se po stažení ověří `tar -tzf`; poškozený
  nebo prázdný archiv je poznámka v souhrnu. `eval.tgz` bere soubory
  z `git ls-files --others --modified --exclude-standard -- tests/eval`.
- **Neznámá větev a změněný yaml** končí stejnou cestou jako selhání fází
  (`summary.txt` a `environment.json` s „VM nevznikla“), ne holým výpisem.
- **Exit 2 i pro evaluační kolo s exit 2** (odhad ceny selhal, kolo
  nezačalo) — zadání jmenuje jen první kolo.
- **`--branch`** se validuje na `^[A-Za-z0-9._/-]+$` (bezpečné do URL
  i do `sed`); `--image` jen `26.04` / `24.04`, `--repeat` 1–5.
- **Opsaný postup** `npm ci && npx playwright install chromium` nahrazen
  odkazem i v `docs/cli.md` (`dataset-e2e`, `video-run`) a v `dev-daily.md`
  § 3; PHP konstanty `E2ePreflight::REMEDY` / `VideoPreflight::VIDEO_REMEDY`
  (hlášky s testy) beze změny.
- **`dev-update.sh`** čísluje `[n/N]` jen kroky, které poběží (3–5).

## Nálezy z revize (2026-10-27)

Revize v chatu: syntaxe všech tří skriptů, zakázané konstrukce bash 3.2,
`check-sensitive`; vlastní maketa `multipass` / `curl` — úspěch s jinou
větví, evaluací a volbami za `--` (exit 0, VM smazaná, výsledky kompletní,
`environment.json` platný JSON, v yaml právě dvě náhrady, klíč 20 B na
STDIN a nikde v argv ani ve výsledcích), kolo exit 1 (sběr, VM zůstane,
exit 1), přerušení (TERM) během druhého bootstrapu → N1.

- [x] **N1 — zavádějící poznámka po přerušení.** Přerušení během
  `cloud-init` nebo `bootstrap-2` dá do souhrnu „kolo ve VM může běžet dál
  jako úloha dashboardu“, ačkoli kolo nezačalo (souhrn zároveň hlásí „Kolo:
  neproběhlo“). Příčina: `on_interrupt` testuje `PHASE_ID` až po
  `phase_end`, který ho vynuluje — podmínka platí vždy. Oprava: přerušenou
  fázi si zapamatovat **před** `phase_end` a poznámku psát jen pro `round`
  a `eval`. Ověř maketou: přerušení během `bootstrap-2` → bez poznámky,
  během `round` → s poznámkou.
  **Opraveno 2026-10-27:** `on_interrupt` čte `PHASE_ID` do lokální
  proměnné jako první a poznámku píše jen pro fáze `round` a `eval`.
  Ověřeno maketou se SIGINT (skript ve vlastní session přes `setsid -f`
  — proces spuštěný přes `&` má SIGINT ignorovaný a trap se neuplatní):
  `bootstrap-2` → bez poznámky, `round` i `eval` → s poznámkou; vždy
  exit 130, VM zůstává, bez `delete`.
- [x] **N2 — mezipaměť `raw.githubusercontent.com`.** Soubory drží až
  5 minut, takže hned po pushnutí může skript stáhnout starý
  `shipard-dev.yaml` a cloud-init starý `dev-bootstrap.sh` (druhý běh
  bootstrapu jede z čerstvého checkoutu, toho se to netýká). Doplněno do
  pastí `docs/test-corpus.md` § 22 a do ručního ověření výše.

## Nálezy z prvního běhu na Macu (2026-10-27)

**Pro Claude Code:** oprav N3 a N5–N10 (N4 až po běhu na QEMU). Commity po
nálezech (N7 s D10 samostatně — jde do produkce), každý s testem, kde
to jde; N7 ověř i na vývojářském serveru (`doctor` sekce rozšíření ✓;
chybějící rozšíření nasimuluj v testu, ne odinstalací). Celou sadu PHPUnit
na konci. U každého nálezu označ `[x]` s větou, jak je opraven a ověřen.

Běh 1 (stable, Ubuntu 26.04, Apple silicon, Multipass 1.17 s backendem
**Apple Virtualization Framework** — novinka 1.17; testy #96 běžely na QEMU).
Instalace cestou nováčka prošla (zelený `doctor`, dashboard funguje),
cloud-init `degraded` jen kvůli nepovedenému přejmenování síťového
rozhraní (`enp0s1` → `eth0`, věc Multipassu a cloud-initu — skript to bere
jako varování).

- [x] **N3 — zastaralý souhrn bootstrapu.** `print_summary`
  v `dev-bootstrap.sh` radí „+ New DS → zaškrtni „Seed test data“ → Create
  Data Source → Open“ — stav před #103 F6c. Dnes **Nový zdroj dat**, pole
  **Data** s výběrem sady, **Vytvořit zdroj dat**, **Otevřít zdroj dat →**
  (`docs/dev/dev-daily.md` § 2). Kroky neopisovat: jedna věta s názvy
  tlačítek (ověř v `frontend/src/dev/i18n/cs.js`) a odkaz na § 2.
  **Opraveno 2026-10-28:** `print_summary` radí tlačítko „Nový zdroj dat“,
  pole „Data“, „Vytvořit zdroj dat“ a „Otevřít zdroj dat →“ s odkazem na
  `dev-daily.md` § 2 (názvy z `frontend/src/dev/i18n/cs.js`); ověřeno
  `bash -n`, text souhrnu bez testu.
- [ ] **N4 — `multipass launch` na AVF nečeká na cloud-init.** VM
  vznikla v 19:15, launch vrátil „Launched“ v 19:19:47 (≈ 4,7 min), bootstrap
  běžel do ≈ 19:21 — přes `--timeout 3600`. Skript na cloud-init čeká sám,
  ale `docs/dev/local-dev.md` kapitola 2 krok 4 („příkaz čeká, dokud
  instalace uvnitř nedoběhne“) a krok 5 (`multipass exec shipard -- tail -f
  shipard-bootstrap.log` — na AVF během startu jen točící se lomítko, viz
  N5) platí jen pro QEMU. Text upravit podle výsledku běhu na QEMU; do
  pastí § 22 backend jako údaj (`environment.json` → `host.driver`
  z `multipass get local.driver`).
- [ ] **N5 — `multipass exec` spuštěný během startu VM nevrátí (AVF).**
  `multipass exec … cloud-init status --wait` z 19:19:48 visel půl hodiny:
  ve VM příkaz doběhl (session `sshd-session` bez potomka), spojení mělo
  `Send-Q 0` — data Mac převzal, visí klient `multipass`. Stejný příznak měl
  ruční `tail -f` logu bootstrapu (jen točící se lomítko). Krátké i dlouhé
  příkazy spuštěné **po** startu fungují (15 s s průběžným výpisem
  i s přesměrovaným výstupem); běh pokračoval po `kill` visicího klienta.
  Oprava ve fázi `cloud-init`: žádný dlouhý `exec` — každých 10 s krátké
  `multipass exec <vm> -- cloud-init status` (`running` / `done` /
  `degraded` / `error`) s celkovým limitem 3600 s a větou při vypršení;
  každé volání ohlídané hlídačem (`timeout` na macOS není: `exec` na
  pozadí, po 30 s `kill` a další pokus), protože nevíme, jestli visí jen
  dlouhé volání, nebo každé zahájené během startu. Výstup posledního
  dotazu do `cloud-init.txt` jako dnes (`status --long` až po skončení).
  Ověření maketou: `exec`, který prvních N volání visí, a stav
  `running` → `done`; vypršení limitu → exit 2, VM zůstane.
- [x] **N6 — varování buildu dashboardu.** `vite-plugin-svelte` při každém
  `npm run build`: `frontend/src/dev/pages/testing/DatasetsPage.svelte:72`
  `<div>` uvnitř `<p>` (karta Testovací kolo z #103 F8a) — prohlížeč HTML
  přeskládá. Vnější `<p>` → `<div>` (se stejnou třídou), vzhled beze změny.
  **Opraveno 2026-10-28:** vnější `<p>` větve *poslední kolo* → `<div>`
  (bez třídy; `reset.css` nuluje okraje všech prvků, vzhled beze změny);
  `npm run build` bez varování.

**Kolo** (commit `362f87df`, 26.04 aarch64, PHP 8.5.4, Node 24.22.1): 11 ok,
8 selhalo, exit 1. Fáze: launch 4:39, cloud-init 34:11 (z toho ≈ 30 min
visící `exec`, N5 — instalace sama ≈ 6 min od startu VM), bootstrap-2
7:18, kolo 5:28 (zkrácené selháními). Vrstva kódu kromě PHPUnit zelená.

- [ ] **N7 — chybí rozšíření PHP `bcmath` (blokuje nasazení).** Od #103
  D84 (`c61cca00`, přepočet do domácí měny v desítkové aritmetice) volá
  `DocDocument::toHome()` / `homeTax()` funkce `bcmul` / `bcround` — při
  každém výpočtu částek dokladu, i v domácí měně. `install-packages.sh`
  `php8.5-bcmath` neinstaluje, `composer.json` `ext-bcmath` nevyžaduje,
  `doctor` rozšíření nekontroluje; vývojářský server ho má náhodou
  (balíček z PPA). Na čistém serveru: `Call to undefined function …\bcround()`
  → 66 chyb PHPUnit (většina z 92 selhání), `dataset-verify` nepoužil ani
  jeden doklad (`internal_error`), `dataset-e2e` 500 na každém Použít, seed
  demo sady 310 chyb. **Testovací server rozšíření nemá** a je na commitu
  před D84 — nasazení dnešního `stable` bez opravy = žádný doklad nepůjde
  uložit. Oprava: `php8.5-bcmath` do `install-packages.sh` (`shpd-server
  upgrade` ho volá, existující servery ho tak dostanou), `"ext-bcmath": "*"`
  do `composer.json` + `composer update --lock`, `doctor` kontroluje
  rozšíření PHP podle `ext-*` z `composer.json` (✗ s názvem balíčku).
- [x] **N8 — `%cI` z novejšího gitu.** `UpdateCheckerTest::testBehindReports…`
  čeká `2026-09-20T08:00:00+00:00`, git na 26.04 vrací `…T08:00:00Z`
  (vývojářský server git 2.43). `Git` předává `%cI` beze změny do
  `state.json` a UI. Normalizovat na jednom místě v `Git` (`DateTimeImmutable`
  → `DATE_ATOM`), test ponechat.
  **Opraveno 2026-10-28:** `Git::isoDate()` převádí `%cI` na `DATE_ATOM`
  (`Z` → `+00:00`, nenulové posunutí zůstává, jiný tvar = null) v `commitDate()`,
  `log()` i `commitDates()`; test `GitTest::testCommitDateIsNormalizedToAtomRegardlessOfGitVersion`
  s atrapou gitu vracející `Z`; `UpdateCheckerTest` beze změny (git 2.43 na
  vývojářském serveru dává `+00:00` i bez opravy — selhání na 26.04 ověří
  další kolo).
- [x] **N9 — rozpoznání ISDOC z nevalidního XML.**
  `AttachmentPreparerTest::testIsdocIsNeverSentToTheModel`: příloha
  `broken.xml` (useknutý `<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>`)
  má zůstat textem pro model, na 26.04 ji `isIsdoc()` vyhodnotí jako ISDOC
  a vyřadí. `rootNamespace()` čte `XMLReader` jen do prvního elementu —
  libxml2 2.9 (vývojářský server) chybu ohlásí dřív, novejší libxml2
  první element vrátí. Dokumentace metody slibuje „null u nevalidního XML“:
  dokument dočíst až do konce (namespace prvního elementu si zapamatovat,
  chyba čtení = null).
  **Opraveno 2026-10-28:** `rootNamespace()` čte dokument až do konce,
  namespace prvního elementu si pamatuje a vrací ho jen bez chyby libxml
  (`libxml_get_errors()`); test `testTruncatedXmlWithIsdocRootIsNotIsdoc`
  s dlouhým useknutým dokumentem (11 kB) — ten na libxml2 2.9 vracel
  namespace i před opravou, chyba je tak reprodukovaná i na vývojářském
  serveru, ne jen na 26.04.
- [ ] **N10 — stopy prokliknutí třikrát.** Každá selhaná úloha
  `dataset-e2e` má ≈ 200 MB: tytéž trace leží v `e2e/traces/`,
  `e2e/test-results/…/attachments/` i `e2e/playwright-report/data/`
  (po ≈ 77 MB) — na vývojářském serveru i v `jobs.tgz` (619 MB). Nechat
  jedinou kopii (tu, kterou nabízí detail úlohy ke stažení — ověř
  v `E2eResult` / API), ostatní po běhu smazat nebo Playwright nastavit,
  ať je nevytváří. `docs/test-corpus.md` § 19 upravit.

## Pasti

- **`multipass launch` bez `--timeout`** hlásí chybu po 5 minutách, ačkoli
  instalace pokračuje — skript dává 3600 s a nenulový exit launch s živou
  VM bere jako timeout, ne jako konec.
- **`multipass exec` nemá TTY** — `sudo` musí projít bez hesla (`sudo -n`;
  na cloud image Multipassu má `ubuntu` NOPASSWD). Bootstrap to už řeší.
- **Kolo nikdy pod `sudo`** — adresář úloh vlastní uživatel php-fpm
  (`ubuntu`); jinak `test-round` odmítne (`docs/test-corpus.md` § 21).
- **Exit přes `tee`** — v bash 3.2 `${PIPESTATUS[0]}` hned za rourou;
  `set -o pipefail` sám nestačí (vrátí exit `tee`, když selže zápis).
- **`npx` pod `sudo`** — secure_path, stahování z registru; proto lokální
  `cli.js` přes `$(command -v node)` (krok 1).
- **Systémové závislosti Chromia na 26.04** podporuje Playwright od 1.61
  (máme 1.62) — kdyby `install-deps` přesto selhal, je to nález pro #146,
  ne důvod obejít ho ručním seznamem balíků.
- **Výsledky mimo checkout** (D6) — `tests/eval/` z VM se do repa
  nekopírují; o commitu výsledků evaluace z čistého serveru se nerozhoduje
  v tomto tasku.
- **Multipass na Windows** existuje, ale skript je bash — na Windows se
  nespouští (D1).
