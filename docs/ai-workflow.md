# Vývoj s Claudem — role, postup, návyky

Tento dokument říká, **jak se v projektu pracuje s AI asistentem** (Claude v chatu
i Claude Code). Je určen člověku, který se zapojuje do vývoje, a zároveň ho čte
Claude sám (odkaz z `CLAUDE.md`). Doplňuje `DEVELOPERS.md` (úvod a rozcestník)
a `tasks/README.md` (formát zadání).

Co sem **nepatří**: adresy serverů, názvy zdrojů dat, cesty k heslům, konfigurace
osobních nástrojů. Repozitář je veřejný. Specifika jednoho stroje patří do
negitovaného `CLAUDE.local.md`; týmové přístupy (testovací server apod.) žijí
v soukromém repu `shipard/dev-env` — **k práci na Shipardu ho nepotřebuješ**,
všechno nutné je tady. Viz kapitoly 6 a 7.

---

## 1. Dvě role, dva nástroje

| Kdo | Co dělá | Co nedělá |
|-----|---------|-----------|
| **Claude v chatu** (claude.ai, Projekt) | návrh a diskuse, zamykání rozhodnutí, psaní PRD/tasků a `docs/`, zakládání a aktualizace issues, **ověřování** hotové implementace (čtení kódu, diffů, cílené testy), diagnostika na testovacím serveru | neimplementuje kód aplikace; na testovacím serveru nic nemění bez schválení |
| **Claude Code** (v checkoutu repa) | implementace podle task filu, testy, build, commity po logických krocích dle task filu | neodesílá práci z vlastní iniciativy — push a PR jen na výslovný pokyn (§3); nevymýšlí zadání mimo task |
| **Člověk** | rozhoduje, čte diffy, rozhoduje o odeslání (pushuje sám, nebo push zadá a potvrdí), nasazuje | — |

Rozdělení není dogma — je to způsob, jak mít návrh a implementaci ve dvou hlavách
a jak udržet přehled o tom, co se změnilo a proč.

**Který nástroj kdy.** Na běžnou práci — implementace, testy, drobné opravy —
**stačí Claude Code** v checkoutu. Claude v chatu s MCP mostem
`remote-dev-bridge` (kapitola 7) se vyplatí hlavně na:

- **návrhovou práci** — diskuse, zamykání rozhodnutí, psaní tasků, review
  hotové implementace; chat drží delší kontext rozhovoru a nemíchá návrh
  s psaním kódu;
- **práci přes víc strojů najednou** — každý cíl mostu je samostatný
  `project_id`, takže Claude v jednom rozhovoru čte kód na vývojovém stroji,
  diagnostikuje testovací server nebo porovnává data mezi dvěma servery
  (ladění importu, ověření instalace na čistém stroji).

Oba nástroje jdou kombinovat: chat píše task a ověřuje, Claude Code
implementuje.

---

## 2. Postup: issue → rozhodnutí → PRD → implementace → ověření

1. **Design issue** na GitHubu (`shipard/shipard`). Diskuse probíhá v chatu, závěry se
   propisují do issue komentářem.
2. **Rozhodnutí se číslují a zamykají** (`D1`, `D2`, …) dřív, než se napíše PRD.
   PRD i issue na ně odkazují. Když se rozhodnutí později změní, dostane nové číslo
   nebo se explicitně označí jako nahrazené — nemění se potichu.
3. **PRD = task file** v `tasks/` podle `tasks/README.md`: hlavička `**Stav:**`,
   sekce „Před implementací přečti" (relevantní `docs/*`), kroky s commit strategií,
   checklist „Hotovo když".
4. **Implementace v Claude Code**: „implementuj `tasks/<název>.md`". Claude Code
   čte `CLAUDE.md`, task a dokumenty ze sekce „Před implementací přečti".
5. **Ověření**: Claude v chatu projde diff a kód read-only, spustí cílené testy,
   po nasazení zkontroluje chování na testovacím serveru. Člověk projde `git diff`
   a pushne — sám, nebo pokynem Claude Code (§3).
6. **Uzavření**: aktualizace `**Stav:**` v tasku ve stejném commitu jako kód,
   `python3 scripts/tasks-index.py`, komentář nebo uzavření issue.

---

## 3. Pravidla, která přebíjejí vše ostatní

- **Veřejné repo.** V issues, komentářích, commitech, `docs/` a `tasks/` se
  reálné zdroje dat nikdy nepojmenovávají — jen prefixem ID. Částky a počty jen
  agregovaně. Platí i pro Claude při práci s `gh`. Detail: `CLAUDE.md` →
  *Zdroje dat ve veřejných textech*.
- **Reálná data.** Nese je testovací server a na vývojovém stroji zdroje dat
  s režimem *reálná kopie* v `CLAUDE.local.md` (kapitola 7). Čtení (soubory, SQL
  přes read-only uživatele) je volné. **Jakákoli mutace** — zápisové SQL, mutující
  CLI, zápis souboru (i do `/tmp`), restart služby — jen po explicitním schválení
  v chatu, **jednotlivě**. Na testovacím serveru se zdrojový kód needituje, slouží
  k diagnostice. Do odpovědí nepatří výpisy osobních dat — agregovat, ukazovat jen
  nezbytné řádky. Zdroje s režimem *volný* (ukázková a seedovaná data) omezení
  nemají.
- **Secrets.** Nikdy nečíst `config/main.json` zdroje dat ani `secrets/`. Heslo
  read-only uživatele se předává přes proměnnou prostředí, nikdy do chatu ani do
  argumentů příkazu. V Claude Code přímé čtení blokuje `.claude/settings.json`
  (`permissions.deny`) v každém režimu — je to pojistka, ne úplná ochrana:
  zachytí nástroje pro čtení a příkazy, které soubor jmenují (`cat`, `head`, …),
  ne skript nebo aplikaci, která si soubor otevře sama.
- **O odeslání rozhoduje vždy člověk.** Push, pull request a merge spouští
  Claude Code **jen na výslovný pokyn** v dané konverzaci — nikdy z vlastní
  iniciativy. `git push`, `gh pr create` a `gh pr merge` si v každém režimu
  včetně auto vyžádají potvrzení (`.claude/settings.json`, `permissions.ask`).
  Pravidlo zachytí běžný tvar příkazu, ne každou obměnu (`git -C … push`) —
  hranicí jsou práva na GitHubu: právo zápisu do repozitáře mají jen
  správci, ostatní posílají pull requesty z forku. Větev `stable` navíc
  chrání ruleset proti force-push a smazání (platí i pro správce). Claude
  v chatu (MCP most) potvrzovací dotaz nemá, proto nepushuje nikdy.

---

## 4. Nástroje a návyky ověřené v praxi

**GitHub** — přes `gh` CLI, vždy s `--repo shipard/shipard`. Víceřádkové texty přes
`--body-file /tmp/soubor.md`, ne přes `--body` (uvozovky a diakritika). Přes vzdálený
most (bez TTY) volat `gh issue view` vždy s `--json number,title,body,comments` —
bez `--json` vrátí prázdný výstup s exit 0, tedy tiché selhání.

**PHPUnit** — vždy úzký filtr: `vendor/bin/phpunit --filter 'TestA|TestB'`;
široký filtr nebo celá sada přes vzdálený nástroj vyprší (držet timeout ≈ 120 s).
Celou sadu spouští Claude Code lokálně až na konci.

**Grep** — nejdřív `-l` pro seznam souborů, pak číst zasažené soubory. Vždy s
`--include=*.php --include=*.jsonc`; široká rekurze přes `modules/` bez filtru
vyprší.

**Editace přes vzdálené nástroje** — selhání patche může být tiché. Po každém
`patch_file` ověřit `git diff`; při neúspěchu soubor **znovu načíst** (vyhledávací
řetězec se mohl změnit) a zkusit znovu. Vícesouborové úpravy s diakritikou jsou
spolehlivější přes Python heredoc s `io.open(..., encoding='utf-8')`.

**JavaScript** — v kódu jen ASCII uvozovky. České typografické `„…"` patří do
textů pro uživatele, v JS řetězci způsobí parse error.

**Konfigurace** — změna `.jsonc` cfgItem se projeví až po rekompilaci a
`shpd-ds ds-upgrade`. Frontend bundle není v gitu — po nasazení `npm run build`;
prohlížeč může držet starou SPA do hard refresh.

**Node v neinteraktivním shellu** — není v `PATH`; každý stroj má jinou cestu →
patří do `CLAUDE.local.md` (kapitola 7).

**Databáze na dev/test** — čtení přes read-only uživatele, názvy databází =
ID zdroje s podtržítky. Heslo se předává přes `MYSQL_PWD` načtené ze souboru;
cesta k němu je per stroj v `CLAUDE.local.md` (testovací server: `alpha.md`).
Read-only uživatel smí jen `SELECT` — žádné `CREATE TEMPORARY TABLE`,
vícekrokovou analýzu psát jako CTE nebo poddotazy v jednom příkazu. Delší SQL
posílat heredocem na stdin (`mysql … <<'EOF'`), ne přes soubor v `/tmp` — zápis
souboru je na testovacím serveru mutace. Dotaz `IN (poddotaz)`, který vrací
`NULL`, tiše vyřadí řádky — raději `LEFT JOIN … IS NOT NULL`.

**Integrační testy** — bez proměnné `SHIPARD_INTEGRATION_DS_PATH` se tiše
přeskočí (výsledek *Skipped*, 0 asercí). Nastavit ji inline v příkazu na
dev zdroj dat z `CLAUDE.local.md`; podrobnosti `tests/Integration/README.md`.

**`fix-permissions` v neinteraktivním shellu** — `shpd-server fix-permissions`
bez `--dry-run` čeká na potvrzení „Proceed? [y/N]“. Z Claude (vzdálený most,
Claude Code) a ze skriptů volat jen s `--dry-run`, nebo po schválení v chatu
s `--force` — jinak příkaz visí do timeoutu.

---

## 5. Když Claude zjistí něco, co má platit příště

Paměť Claude v chatu je vázaná na účet uživatele a na Projekt; auto-paměť Claude
Code je vázaná na stroj. **Ani jedna se nesdílí s kolegy.** Co má platit pro
všechny, patří do souboru — podle vrstvy:

| Zjištění | Kam |
|----------|-----|
| konvence kódu, architektura, „vždy udělej X" | `CLAUDE.md` (stručně) nebo `docs/*.md` (podrobně) |
| postup práce, návyk, past nástroje | tento dokument |
| týmový přístup, adresa, testovací server | `shipard/dev-env` (soukromé) |
| specifikum stroje: `project_id`, Node, zdroje dat na dev serveru, cesta k heslu, osobní workflow | `CLAUDE.local.md` (negitované, kapitola 7) |

Spouštěč je jednoduchý: druhá stejná oprava v chatu = zápis do souboru. Claude
změnu **navrhne** a člověk ji commitne; do `CLAUDE.md` se nepíše potichu.

---

## 6. Mapa zdrojů — co Claude odkud čte

| Vrstva | Soubory | Jak se dostane ke kolegovi |
|--------|---------|----------------------------|
| **repo `shipard/shipard`** (veřejné) | `CLAUDE.md`, `docs/`, `tasks/README.md`, tento dokument | `git clone`; Claude Code načte `CLAUDE.md` automaticky |
| **repo `shipard/dev-env`** (soukromé, jen tým) | `dev-env.md` (týmové cíle mostu, přístupy), `alpha.md` (testovací server), `old-shipard.md` (import ze starého Shipardu), `README.md` (checklist člena týmu) | `git clone`; v claude.ai přes GitHub sync do knowledge Projektu — kdo není v týmu, tuhle vrstvu nemá a nepotřebuje |
| **stroj** | `CLAUDE.local.md` v kořeni checkoutu, `~/.claude/*` | nesdílí se; vzor `CLAUDE.local.example.md` (kapitola 7) |
| **claude.ai Projekt** | instrukce + knowledge (GitHub sync z `shipard/shipard` aspoň `CLAUDE.md`, `docs/`, `tasks/README.md`; členové týmu navíc `dev-env`) | Team plán: jeden sdílený Projekt; Pro/Max: každý si založí vlastní; text instrukcí je v kapitole 8 |

Důležité: **Claude v chatu `CLAUDE.md` sám od sebe nečte.** Musí být v knowledge
Projektu (GitHub sync) nebo si ho Claude načte z repa přes MCP most na začátku
práce — instrukce Projektu na to ukazují. `CLAUDE.local.md` v knowledge není
(negituje se), Claude v chatu si ho vždy čte přes most.

---

## 7. Nastavení pro nového člověka

1. **Prostředí** — na Macu nebo ve Windows podle
   [`dev/local-dev.md`](dev/local-dev.md), na vlastním Linux serveru podle
   [`dev/linux-install.md`](dev/linux-install.md).
2. **Claude Code** v checkoutu (`~/sw/shpd`) — `CLAUDE.md` se načte sám;
   první kroky a režimy oprávnění v [`dev/claude-code-intro.md`](dev/claude-code-intro.md).
3. **`CLAUDE.local.md`** ze šablony (níže) — hlavně zdroje dat a jejich režimy.
4. **GitHub CLI** — `gh auth login`, když budeš pracovat s issues (kapitola 4).
5. Volitelně **Claude v chatu**: MCP most `remote-dev-bridge` (níže) a Projekt
   v claude.ai s instrukcemi z kapitoly 8 a knowledge z `shipard/shipard`.

Členové týmu navíc procházejí checklist v soukromém
`shipard/dev-env/README.md` (testovací server, týmové cíle mostu).

### MCP most `remote-dev-bridge`

[`remote-dev-bridge`](https://github.com/shipard/remote-dev-bridge) je aplikace
na tvém počítači (macOS, Windows, Linux), přes kterou má Claude v **desktopové
aplikaci Claude** přístup k souborům a shellu na vývojovém stroji přes SSH.
Instalace, napojení do Claude Desktop a konfigurace jsou v README mostu;
v kostce:

1. Nainstaluj vydání ze stránky *Releases*. Na macOS vždy **podepsané
   vydání** — nepodepsaný vlastní build nedostane oprávnění Místní síť
   a spojení na stroje v lokální síti (Multipass VM) končí chybou
   „No route to host“.
2. V menu mostu *Copy Claude Desktop Config*, vložit do konfigurace Claude
   Desktop a **jednou** restartovat Claude Desktop. Další změny serverů
   a projektů se načtou samy.
3. **Server** = host, port, uživatel a SSH klíč **bez passphrase** (most
   jinou zatím neumí). **Projekt** = server + root (checkout) + povolené
   příkazy; jeho ID je `project_id`, který Claude uvádí v každém volání.
   Pro lokální VM jsou konkrétní údaje v `dev/local-dev.md`.

**Konvence `project_id`** — dodržet, ať instrukce Projektu a tento dokument
platí pro všechny. Root = **tvůj** checkout, cesty se liší per člověk.

| `project_id` | Root |
|--------------|------|
| `shipard` | checkout `shipard/shipard` — **ne** `shpd`, to je jen název adresáře |
| `mail_router` | checkout samostatné komponenty (`docs/services.md`); `ai_analyzer` zrušen (#85 D24), repozitář archivován |
| jiné (testovací stroj, čerstvá VM) | libovolné, popsat v `CLAUDE.local.md` |

**Provozní poznámky:**

- `run_command` má strop 120 s → dlouhé běhy (instalace, build) pouštět na
  pozadí (`nohup … > log &`) a průběh číst z logu; PHPUnit a grep viz
  kapitola 4.
- Selhání `patch_file` může být tiché → po každém patchi `git diff`.
- **Projekt musí mířit na správný stroj.** Když root neexistuje, příkaz
  poběží v `$HOME` serveru; když projekt odkazuje na špatný server, poběží
  na špatném stroji — bez chyby. U nového cíle proto prvním příkazem
  `hostname` a `pwd`; při pochybnosti `list_projects`.

### `CLAUDE.local.md` — osobní soubor stroje

Volitelný, v `.gitignore`, v kořeni checkoutu. Popisuje to, co je u každého
jiné: `project_id` v mostu, cestu k Node, **zdroje dat na dev serveru**, cestu
k heslu read-only uživatele a případně vlastní workflow. Začít ze šablony:

```bash
cp CLAUDE.local.example.md CLAUDE.local.md
```

Každý zdroj dat v tabulce má **režim**, podle kterého se Claude chová:

| Režim | Co v něm je | Claude smí |
|-------|-------------|------------|
| `volný` | ukázková nebo seedovaná data | číst, resetovat, seedovat, zapisovat |
| `reálná kopie` | kopie ostrých dat (např. import) | číst; mutace jen po schválení, jako na testovacím serveru (kapitola 3) |

Zdroj, který v tabulce chybí, se bere jako `reálná kopie`.

Kdo to načte:
- **Claude Code** automaticky; `@cesta` importy uvnitř (např. soubor ze
  soukromého `dev-env`) při prvním spuštění potvrdí dialogem. Co se načetlo,
  ukáže `/context` (sekce *Memory files*). Načítá se do každé session — držet
  krátké, delší text dát do importovaného souboru.
- **Claude v chatu** přes MCP most na začátku práce (instrukce Projektu,
  kapitola 8).

V `CLAUDE.local.md` smí být názvy zdrojů dat (je mimo repozitář); do
veřejných textů se z něj přenáší jen prefix ID.

---

## 8. Instrukce Projektu v claude.ai

Text níže se vloží do pole *Instrukce* Projektu (Team: jednou do sdíleného,
Pro/Max: každý do svého). Držet krátké — detail je v souborech, na které
odkazuje. Po změně tady instrukce v Projektu ručně přepsat.

```markdown
Pracuješ jako vývojový partner na **Novém Shipardu** — přepisu ERP systému
Shipard do PHP 8.5 / MariaDB / Svelte 5 (repo `shipard/shipard`, veřejné).
Komunikace, dokumentace a UI texty česky; identifikátory v kódu anglicky.

**Tvoje role** (podrobně `docs/ai-workflow.md` v repu): návrh a diskuse,
zamykání číslovaných rozhodnutí (D1, D2, …) před psaním PRD, psaní tasků do
`tasks/` podle `tasks/README.md`, práce s issues přes `gh --repo shipard/shipard`,
ověřování hotové implementace read-only. Kód implementuje Claude Code z task
filů; commit a push dělá člověk.

**Na začátku práce** si načti `CLAUDE.md` a `docs/ai-workflow.md` z repa
(z knowledge, nebo přes MCP most s `project_id` `shipard`). Pokud v kořeni
checkoutu existuje `CLAUDE.local.md`, přečti ho přes most taky — popisuje můj
stroj a zdroje dat na dev serveru. Týmové přístupy (testovací server apod.)
jsou v knowledge, pokud je mám.

**Tvrdá pravidla:**
- Veřejné texty (issues, komentáře, commity, `docs/`, `tasks/`) bez názvů
  reálných zdrojů dat, firem a osob — jen prefix ID; částky agregovaně.
- Reálná data (testovací server; na dev serveru zdroje s režimem
  „reálná kopie“ v `CLAUDE.local.md`): čtení volné, **jakákoli mutace jen
  po mém explicitním schválení v chatu, jednotlivě**. Na testovacím serveru
  se zdrojový kód needituje. Do odpovědí žádné výpisy osobních dat.
- Nikdy nečti `config/main.json` zdrojů dat ani `secrets/`. Hesla nepatří do
  chatu ani do argumentů příkazů.
- Po každém `patch_file` ověř `git diff`. PHPUnit jen s úzkým `--filter`.
  Grep s `--include`, ne široká rekurze přes `modules/`.
- Nikdy nepushuj.

Když zjistíš něco, co má platit i příště, navrhni zápis do správného souboru
podle `docs/ai-workflow.md` §5 — paměť Projektu se s kolegy nesdílí.
```
