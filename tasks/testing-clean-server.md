# F8b — testovací kolo na čistém serveru (Multipass)

**Stav:** částečně — kód a docs hotové 2026-10-27 (#146 D1–D9), ověřeno nanečisto proti maketám; zbývá ruční ověření 1–4 na Macu (člověk), časy do `docs/test-corpus.md` § 22 a výsledek do #146

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
  a `environment.json` se zapíší; poznámka, že kolo ve VM může běžet dál
  jako úloha dashboardu (`multipass exec` padne, úloha `round` ne).
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

- [ ] **N1 — zavádějící poznámka po přerušení.** Přerušení během
  `cloud-init` nebo `bootstrap-2` dá do souhrnu „kolo ve VM může běžet dál
  jako úloha dashboardu“, ačkoli kolo nezačalo (souhrn zároveň hlásí „Kolo:
  neproběhlo“). Příčina: `on_interrupt` testuje `PHASE_ID` až po
  `phase_end`, který ho vynuluje — podmínka platí vždy. Oprava: přerušenou
  fázi si zapamatovat **před** `phase_end` a poznámku psát jen pro `round`
  a `eval`. Ověř maketou: přerušení během `bootstrap-2` → bez poznámky,
  během `round` → s poznámkou.
- [x] **N2 — mezipaměť `raw.githubusercontent.com`.** Soubory drží až
  5 minut, takže hned po pushnutí může skript stáhnout starý
  `shipard-dev.yaml` a cloud-init starý `dev-bootstrap.sh` (druhý běh
  bootstrapu jede z čerstvého checkoutu, toho se to netýká). Doplněno do
  pastí `docs/test-corpus.md` § 22 a do ručního ověření výše.

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
