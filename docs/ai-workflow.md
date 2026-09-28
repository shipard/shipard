# Vývoj s Claudem — role, postup, návyky

Tento dokument říká, **jak se v projektu pracuje s AI asistentem** (Claude v chatu
i Claude Code). Je určen člověku, který se zapojuje do vývoje, a zároveň ho čte
Claude sám (odkaz z `CLAUDE.md`). Doplňuje `DEVELOPERS.md` (rozchození prostředí)
a `tasks/README.md` (formát zadání).

Co sem **nepatří**: adresy serverů, názvy zdrojů dat, cesty k heslům, konfigurace
osobních nástrojů. Repozitář je veřejný. Konkrétní přístupy žijí v soukromém repu
`shipard/dev-env` (soubory `dev-env.md`, `alpha.md`) — viz kapitola 6.

---

## 1. Dvě role, dva nástroje

| Kdo | Co dělá | Co nedělá |
|-----|---------|-----------|
| **Claude v chatu** (claude.ai, Projekt) | návrh a diskuse, zamykání rozhodnutí, psaní PRD/tasků a `docs/`, zakládání a aktualizace issues, **ověřování** hotové implementace (čtení kódu, diffů, cílené testy), diagnostika na testovacím serveru | neimplementuje kód aplikace; na testovacím serveru nic nemění bez schválení |
| **Claude Code** (v checkoutu repa) | implementace podle task filu, testy, build, commity po logických krocích dle task filu | nepushuje; nevymýšlí zadání mimo task |
| **Člověk** | rozhoduje, čte diffy, pushuje, nasazuje | — |

Rozdělení není dogma — je to způsob, jak mít návrh a implementaci ve dvou hlavách
a jak udržet přehled o tom, co se změnilo a proč.

---

## 2. Postup: issue → rozhodnutí → PRD → implementace → ověření

1. **Design issue** na GitHubu (`shipard/shipard`). Diskuse probíhá v chatu, závěry se
   propisují do issue komentářem.
2. **Rozhodnutí se číslují a zamykají** (`D1`, `D2`, …) dřív, než se napíše PRD.
   PRD i issue na ně odkazují. Když se rozhodnutí později změní, dostane nové číslo
   nebo se explicitně označí jako nahrazené — nemění se potichu.
3. **PRD = task file** v `tasks/` podle `tasks/README.md`: hlavička `**Stav:**`,
   sekce „Před implementací přečti" (relevantní `docs/*`), kroky s commit strategií,
   checklist „Hotovo když". U protějšku ve starém Shipardu (soukromé repo) žijí
   tasky v `modules/imports/newShipard/tasks/` a číslují se pořadově — před
   přidělením čísla zkontrolovat existující soubory i README.
4. **Implementace v Claude Code**: „implementuj `tasks/<název>.md`". Claude Code
   čte `CLAUDE.md`, task a dokumenty ze sekce „Před implementací přečti".
5. **Ověření**: Claude v chatu projde diff a kód read-only, spustí cílené testy,
   po nasazení zkontroluje chování na testovacím serveru. Člověk projde `git diff`
   a pushne.
6. **Uzavření**: aktualizace `**Stav:**` v tasku ve stejném commitu jako kód,
   `python3 scripts/tasks-index.py`, komentář nebo uzavření issue.

Pořadí nasazení mezi repozitáři: **nový Shipard před starým**, když změna ve
starém (import runner) závisí na novém applieru nebo poli.

---

## 3. Pravidla, která přebíjejí vše ostatní

- **Veřejné repo.** V issues, komentářích, commitech, `docs/` a `tasks/` se
  reálné zdroje dat nikdy nepojmenovávají — jen prefixem ID. Částky a počty jen
  agregovaně. Platí i pro Claude při práci s `gh`. Detail: `CLAUDE.md` →
  *Zdroje dat ve veřejných textech*.
- **Testovací server nese reálná data.** Čtení (soubory, SQL přes read-only
  uživatele) je volné. **Jakákoli mutace** — zápisové SQL, mutující CLI, zápis
  souboru, restart služby — jen po explicitním schválení v chatu, **jednotlivě**.
  Zdrojový kód se tam needituje, slouží k diagnostice. Do odpovědí nepatří výpisy
  osobních dat — agregovat, ukazovat jen nezbytné řádky.
- **Secrets.** Nikdy nečíst `config/main.json` zdroje dat ani `secrets/`. Heslo
  read-only uživatele se předává přes proměnnou prostředí, nikdy do chatu ani do
  argumentů příkazu.
- **Push je vždy lidský.**

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
ID zdroje s podtržítky. Vzor volání je v `dev-env.md`.

---

## 5. Když Claude zjistí něco, co má platit příště

Paměť Claude v chatu je vázaná na účet uživatele a na Projekt; auto-paměť Claude
Code je vázaná na stroj. **Ani jedna se nesdílí s kolegy.** Co má platit pro
všechny, patří do souboru — podle vrstvy:

| Zjištění | Kam |
|----------|-----|
| konvence kódu, architektura, „vždy udělej X" | `CLAUDE.md` (stručně) nebo `docs/*.md` (podrobně) |
| postup práce, návyk, past nástroje | tento dokument |
| přístup, adresa, cesta k heslu, ID zdroje | `shipard/dev-env` (soukromé) |
| specifikum jednoho stroje | `CLAUDE.local.md` (negitované) |

Spouštěč je jednoduchý: druhá stejná oprava v chatu = zápis do souboru. Claude
změnu **navrhne** a člověk ji commitne; do `CLAUDE.md` se nepíše potichu.

---

## 6. Mapa zdrojů — co Claude odkud čte

| Vrstva | Soubory | Jak se dostane ke kolegovi |
|--------|---------|----------------------------|
| **repo `shipard/shipard`** (veřejné) | `CLAUDE.md`, `docs/`, `tasks/README.md`, tento dokument | `git clone`; Claude Code načte `CLAUDE.md` automaticky |
| **repo `shipard/dev-env`** (soukromé) | `dev-env.md` (prostředí, nástroje, přístupy), `alpha.md` (testovací server), `claude-project-instructions.md` (text instrukcí Projektu), `README.md` (checklist nového člověka) | `git clone`; v claude.ai přes GitHub sync do knowledge Projektu |
| **stroj** | `CLAUDE.local.md` v kořeni checkoutu, `~/.claude/*` | nesdílí se; vzor v kapitole 7 |
| **claude.ai Projekt** | instrukce + knowledge (GitHub sync `dev-env` + z `shipard/shipard` aspoň `CLAUDE.md`, `docs/`, `tasks/README.md`) | Team plán: jeden sdílený Projekt; Pro/Max: každý si založí vlastní podle `claude-project-instructions.md` |

Důležité: **Claude v chatu `CLAUDE.md` sám od sebe nečte.** Musí být v knowledge
Projektu (GitHub sync) nebo si ho Claude načte z repa přes MCP most na začátku
práce — instrukce Projektu na to ukazují.

---

## 7. Nastavení pro nového člověka

Prostředí podle `DEVELOPERS.md`. Zbytek je v `shipard/dev-env/README.md`
(přístupy, MCP most, `gh auth`, založení Projektu). Veřejná je jen šablona
osobního souboru pro Claude Code — vytvoř `CLAUDE.local.md` v kořeni checkoutu
(je v `.gitignore`):

```markdown
# CLAUDE.local.md — specifika tohoto stroje (negitované)

- Node: `export PATH=$HOME/.nvm/versions/node/<verze>/bin:$PATH`
- Dev zdroj dat pro testy: `<ds-id>` (`/opt/shipard/data-sources/<ds-id>`)
- Prostředí týmu (kopie `dev-env.md` ze soukromého repa):
  @~/.claude/shipard-dev-env.md
```

Import z domovského adresáře Claude Code při prvním spuštění potvrdí dialogem —
odsouhlasit. Že se soubory načetly, ověří `/context` (sekce *Memory files*).
