# Instalace na čistém Ubuntu — opravy `install-packages.sh` a `DEVELOPERS.md`

**Stav:** částečně — skript a dokumentace hotové 2026-10-03 (2 commity, #96 D5 a D9); 26.04 ověřeno na čisté VM; dev server ověřen; zbývá 24.04 znovu na čisté VM (první `server-init` jednou selhal, nereprodukováno) a přeskočení Node ≥ 22

## Cíl

`scripts/install-packages.sh` dnes na **čistém** Ubuntu neprojde: tiše skončí
v kroku Node.js s `exit=127` (ověřeno na čerstvé Multipass VM s 24.04).
Na existujících serverech to nikdo nepoznal, protože Node tam už byl.
Tento task opraví skript a `DEVELOPERS.md` tak, aby postup prošel od
`git clone` po zelený `shpd-server doctor` na čistém Ubuntu 24.04 i 26.04.

Je to samostatný malý krok. Navazující bootstrap pro lokální vývoj
(Multipass na macOS, WSL na Windows) přijde v dalším tasku a staví na něm.

## Před implementací přečti

- `scripts/install-packages.sh` (celý)
- `DEVELOPERS.md` kapitoly Požadavky, 1–3
- `docs/operations/production.md` kapitoly Předpoklady a 2
- `docs/cli.md` sekce `scripts/install-packages.sh`

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D5** — podporované verze jsou **Ubuntu 24.04 a 26.04 LTS**. 22.04 se
  vyřazuje úplně (dev i produkce). Na 24.04 se PHP 8.5 bere z PPA
  `ondrej/php`, na 26.04 ze standardních repozitářů Ubuntu (PHP 8.5 tam je
  nativně) — PPA se na 26.04 nepřidává.
- ✓ **D9** — opravy instalace jsou samostatný task. Node.js se instaluje ve
  verzi **24 (LTS)** z NodeSource; už nainstalovaný Node **≥ 22** se
  ponechá.

## Co je potřeba udělat

### 1. `scripts/install-packages.sh`

**a) Detekce verze Ubuntu** — hned po kontrole roota (krok 2) načíst
`/etc/os-release` a rozhodnout:

| `ID` / `VERSION_ID` | Chování |
|---------------------|---------|
| `ubuntu` / `24.04` | PHP z PPA `ondrej/php` |
| `ubuntu` / `26.04` | PHP ze standardních repozitářů, bez PPA |
| cokoliv jiného | chyba s jasnou hláškou (podporované verze 24.04 a 26.04), `exit 1` — **před** jakoukoli instalací |

Hlášky skriptu zůstávají anglicky (konzistence se zbytkem skriptu).

**b) `apt-get update` před první instalací.** Dnes se volá až po přidání
PPA; na čerstvých image (cloud, WSL) jsou seznamy balíčků zastaralé a první
`apt-get install` padá na 404. Pořadí: `apt-get update` → prerekvizity →
(jen 24.04) PPA → `apt-get update` → hlavní balíčky.

**c) Prerekvizity.** `apt-transport-https` vypustit (na podporovaných
verzích zbytečný). `software-properties-common` instalovat jen tam, kde se
přidává PPA (24.04).

**d) Node.js — oprava pádu.** Současný řádek

```bash
NODE_MAJOR="$(node -v 2>/dev/null | tr -d 'v' | cut -d. -f1)"
```

při `set -euo pipefail` a chybějícím `node` vrátí z přiřazení 127 a skript
skončí bez hlášky. Nahradit detekcí přes `command -v node`; když Node chybí,
`NODE_MAJOR` je prázdné. Práh pro ponechání stávajícího Node **≥ 22**,
instalace z `https://deb.nodesource.com/setup_24.x`. Výpis „Installing
Node.js 22 LTS“ přepsat na 24.

Pozn.: Node z nvm pod `sudo` vidět není (`secure_path`), takže se vedle nvm
nainstaluje systémový Node 24. To je v pořádku — build frontendu běží pod
uživatelem a použije ten, který má v `PATH`.

**e) Ostatní substituce příkazů** ve skriptu projít pohledem stejného typu
chyby (pipeline v `$(...)` při `set -e`/`pipefail`, kde první příkaz může
chybět). Pokud žádná další není, nic neměnit.

**f) Hlavička skriptu** — komentář „Ubuntu LTS (22.04 / 24.04)“ → „Ubuntu
LTS (24.04 / 26.04)“.

Skript musí zůstat **idempotentní**: opakované spuštění na už nainstalovaném
24.04 serveru nesmí nic rozbít (PPA už přidané, Node už přítomný, nginx site
existuje).

### 2. `DEVELOPERS.md`

- **Požadavky:** „Ubuntu LTS — 24.04 nebo 26.04“. Odstavec o repozitáři
  MariaDB pro 22.04 smazat; požadavek MariaDB ≥ 10.10 ponechat s poznámkou,
  že ho obě podporované verze splňují.
- **Kapitola 1:** klonovat přes HTTPS
  (`git clone https://github.com/shipard/shipard.git ~/sw/shpd`) — funguje
  bez SSH klíče na GitHubu. Jednou větou doplnit, že kdo bude pushovat, může
  klonovat přes SSH nebo si později přepnout `remote`.
- **Kapitola 2:** v seznamu „Node.js 22 (LTS)“ → „Node.js 24 (LTS), pokud už
  není nainstalovaný Node ≥ 22“; zmínit, že na 24.04 se PHP bere z PPA
  `ondrej/php`, na 26.04 ze systému.
- **Kapitola 3:** místo samotného `composer install` spustit
  `bash scripts/dev-update.sh` (composer + `npm install` + build frontendu).
  Dnes se frontend při prvním setupu nebuildí vůbec a aplikace
  `/{ds-id}/app/` pak nemá co servírovat. Nadpis kapitoly upravit („Závislosti
  a build frontendu“). Kapitola 7 („Po `git pull`“) zůstává.

### 3. Ostatní dokumentace

- `docs/operations/production.md` — Předpoklady: „24.04 nebo 26.04“; v kapitole
  2 „Node.js 22“ → „Node.js 24“.
- `docs/cli.md`, sekce `scripts/install-packages.sh` — pokud zmiňuje verze
  Ubuntu nebo Node, sjednotit.
- `grep -rn --include=*.md "22.04" docs DEVELOPERS.md README.md` — žádný
  zbývající výskyt v kontextu podporovaných verzí (historické tasky v
  `tasks/` neměnit).

### 4. Index tasků

Řádek do `tasks/README.md`, oblast „Server, CLI a provoz“, a
`python3 scripts/tasks-index.py`.

### 5. Doplněno při implementaci (odsouhlaseno 2026-10-03)

- `docs/cli.md` — sekce skriptu verze dosud nezmiňovala; doplněny podporované
  verze Ubuntu (s chybou na ostatních) a Node.js 24.
- „Next steps“ na konci skriptu začínají v development módu krokem
  `bash scripts/dev-update.sh` — bez `vendor/` by `shpd-server server-init`
  spadl.
- Kontrola verze Ubuntu běží před určením uživatele: v produkčním módu ten
  krok volá `useradd`, což je už zásah do systému.

## Commit strategie

1. `install-packages: podpora 24.04/26.04, apt-get update, oprava detekce Node, Node 24 (#96 D5, D9)`
2. `docs: instalace na 24.04/26.04, clone přes HTTPS, build frontendu v prvním setupu (#96 D5, D9)`
   — včetně aktualizace hlavičky tohoto tasku a indexu

## Ověření

Implementace v Claude Code běží na dev serveru; čistou instalaci ověřuje
člověk (případně Claude v chatu přes most) na **čerstvých** Multipass VM:

```bash
multipass launch 24.04 --name shpd-2404 --cpus 2 --memory 4G --disk 20G
multipass launch 26.04 --name shpd-2604 --cpus 2 --memory 4G --disk 20G
# v každé VM:
git clone https://github.com/shipard/shipard.git ~/sw/shpd && cd ~/sw/shpd
git checkout <větev s opravou>
sudo bash scripts/install-packages.sh --mode=development; echo "exit=$?"
bash scripts/dev-update.sh
sudo shpd-server server-init --mode=development
shpd-server doctor
```

Pak v prohlížeči `http://<ip-vm>/_dev/`, „+ New DS“ s testovacími daty
a otevření aplikace.

## Hotovo když

- [x] `bash -n scripts/install-packages.sh` projde; `shellcheck` (pokud je
      k dispozici) bez nových varování — na dev serveru `shellcheck` není
- [ ] Na čerstvém Ubuntu **24.04** projde postup z `DEVELOPERS.md` až po
      zelený `shpd-server doctor`
- [x] Na čerstvém Ubuntu **26.04** totéž, bez přidání PPA `ondrej/php` —
      2026-10-03, Multipass VM (aarch64), včetně nového DS
- [x] Na nepodporované verzi skript skončí jasnou chybou před instalací —
      ověřeno během skriptu bez roota (podvržený `os-release`, privilegované
      příkazy nahrazené atrapami): 22.04, Debian i chybějící soubor končí
      `exit 1` bez jediného volání `apt-get` / `useradd`
- [x] Opakované spuštění na stávajícím dev serveru (24.04) projde a nic
      nezmění (PPA, Node, nginx site, FPM pool) — 2026-10-03 `exit=0`;
      první běh doinstaloval systémový Node 24 vedle nvm (pod `sudo` nvm
      není vidět, očekávané chování)
- [ ] Bez předinstalovaného Node se nainstaluje Node 24; s Node ≥ 22 se
      instalace přeskočí — instalace Node 24 ověřena na obou VM;
      přeskočení zbývá
- [x] Po prvním setupu existuje `public/app/index.html` a dashboard otevře
      aplikaci nového DS — 2026-10-03 na obou VM (DS z dev dashboardu,
      přihlášení jako admin)
- [x] `DEVELOPERS.md`, `production.md`, `cli.md` bez zmínky o 22.04 jako
      podporované verzi
- [x] Hlavička tohoto tasku a `tasks/README.md` aktualizované

## Mimo rozsah

- Bootstrap skript a cloud-init pro Multipass, návod pro WSL, render služba
  v lokálním prostředí (D7, D8) — navazující task.
- Veřejná dokumentace `remote-dev-bridge` a úpravy `docs/ai-workflow.md`
  (D4), úvod do Claude Code pro začátečníky (D10).
