# Dokumentace pro vývojáře — úvod a rozcestník, návody v `docs/dev/`

**Stav:** hotovo — implementováno 2026-10-07 (3 commity, #108 D1–D5, D9); web (D6, D8), návod „první modul“ (D7) a odkazy v soukromém `dev-env` mimo tento task

## Cíl

Návody pro vývojáře existují, ale chybí to, co má být před nimi: úvod,
proč a jak Shipard stavíme, a rozcestník, který člověka pošle na správný
návod. `DEVELOPERS.md` je dnes instalační příručka pro Linux.

Po tomto tasku:

- `DEVELOPERS.md` je úvod a rozcestník (a zdroj budoucí stránky
  „Pro vývojáře“ na webu);
- návody krok za krokem žijí v `docs/dev/`;
- `docs/README.md` je členěný podle čtenáře;
- sekce „Pro vývojáře“ v `README.md` má jediný vstup — `DEVELOPERS.md`.

Je to čistě dokumentační task: žádný kód aplikace, žádné testy, žádná
změna chování skriptů (ve skriptech se mění jen cesty v textech).

## Před implementací přečti

- `DEVELOPERS.md` (celý — rozděluje se)
- `docs/local-dev.md`, `docs/claude-code-intro.md` (přesouvají se)
- `docs/README.md`
- `docs/ai-workflow.md` §1, §5–§7
- `README.md` — sekce „Pro vývojáře“
- `docs/modules.md` kapitola 10 (co soukromé moduly umí a co ne)
- `docs/help-authoring.md` — úvod (žánrová hranice `help/` × `docs/`)

## Rozhodnutí (potvrzená, issue #108)

- ✓ **D1** — `DEVELOPERS.md` je úvod a rozcestník. Rychlý start je na první
  obrazovce, příběh pod ním.
- ✓ **D2** — úvod říká doslova, že kód píše AI, a hned ukazuje metodiku
  (návrh a rozhodnutí → zadání → implementace jiným modelem → revize →
  člověk čte diff a rozhoduje o odeslání). Bez názvů konkrétních modelů.
  „Ruční programování skončilo“ jako vlastní zkušenost, ne obecná teze.
- ✓ **D3** — rozcestník podle záměru čtenáře, ne podle souborů.
- ✓ **D4** — dnešní `DEVELOPERS.md`: kapitoly 1–5 a instalační část 9 →
  `docs/dev/linux-install.md`; kapitoly 6–7 a obecná část 9 →
  `docs/dev/dev-daily.md`; kapitola 8 zaniká ve prospěch `docs/cli.md`.
- ✓ **D5** — adresář určuje čtenáře a žánr (tabulka v sekci 6). Do
  `docs/dev/` se přesouvají `local-dev.md` a `claude-code-intro.md`.
  Specifikace se nestěhují. `docs/ai-workflow.md` zůstává v kořeni `docs/`.
- ✓ **D6** — web: `DEVELOPERS.md` → `/pro-vyvojare/`, `docs/dev/**` pod ní,
  anglicky od začátku. **Mimo tento task** (repozitář webu).
- ✓ **D7** — návod „první modul s Claude Code“ je navazující task; do té
  doby rozcestník odkazuje jen na `docs/modules.md`.
- ✓ **D8** — na web až po ověření cesty přes WSL; změny v repu nečekají.
- ✓ **D9** — sekce „Pro vývojáře“ v `README.md`: krátký odstavec a jediný
  odkaz na `DEVELOPERS.md`.

Upřesnění k implementaci (rozhodnuto při psaní tasku):

- **Staré cesty bez náhradních souborů.** `docs/local-dev.md`
  a `docs/claude-code-intro.md` po přesunu neexistují, žádné „přesunuto“.
- **Tasky v `tasks/` se nepřepisují** — jsou to momentky; odkazy na staré
  cesty v nich zůstávají.
- **Přesouvaný text se nepřepisuje.** Kapitoly se stěhují doslova; mění se
  jen číslování nadpisů, odkazy a věty, které odkazují na „kapitolu N“.

## Co je potřeba udělat

### 1. Přesun návodů do `docs/dev/` (D5)

```bash
mkdir -p docs/dev
git mv docs/local-dev.md docs/dev/local-dev.md
git mv docs/claude-code-intro.md docs/dev/claude-code-intro.md
```

**Odkazy uvnitř přesunutých souborů** — o úroveň hlouběji:
`ai-workflow.md` → `../ai-workflow.md`, `README.md` → `../README.md`,
`roadmap.md` → `../roadmap.md`, `operations/…` → `../operations/…`,
`../scripts/…` → `../../scripts/…`, `../tasks/…` → `../../tasks/…`,
`../DEVELOPERS.md` → `../../DEVELOPERS.md` (a cíl podle kroků 2–3 níže).
Odkazy mezi oběma soubory (`local-dev.md` ↔ `claude-code-intro.md`)
zůstávají.

**Živé odkazy jinde** (stav k 2026-10-07 — před úpravou si seznam ověř
grepem, viz Ověření):

| Soubor | Co |
|--------|----|
| `CLAUDE.md` | „`docs/claude-code-intro.md` kapitola 8“ → `docs/dev/…` (`AGENTS.md` je symlink, needituje se) |
| `docs/ai-workflow.md` | §7 kroky 1–2 a odstavec o mostu: `local-dev.md`, `claude-code-intro.md` → `dev/…`; „na vlastním Linux serveru podle `DEVELOPERS.md`“ → `dev/linux-install.md`; úvodní odstavec „Doplňuje `DEVELOPERS.md` (rozchození prostředí)“ → „(úvod a rozcestník)“ |
| `docs/cli.md` | popis `dev-bootstrap.sh`: „kroky z `DEVELOPERS.md`“ → `dev/linux-install.md`; odkaz `local-dev.md` → `dev/local-dev.md` |
| `docs/operations/production.md` | „Vývojové prostředí řeší `DEVELOPERS.md`“ → `../dev/linux-install.md` |
| `scripts/dev-bootstrap.sh` | `DOCS_URL`, komentáře v hlavičce a text nápovědy (`docs/local-dev.md` → `docs/dev/local-dev.md`; „steps of DEVELOPERS.md“ → `docs/dev/linux-install.md`) |
| `scripts/multipass/shipard-dev.yaml` | komentář „Návod krok za krokem“ |
| `docs/README.md`, `README.md`, `DEVELOPERS.md` | řeší kroky 4–6 |

Patičky „Průvodce vývojáře → `../DEVELOPERS.md`“ v `docs/README.md`,
`docs/services.md` a `docs/cli.md` zůstávají — `DEVELOPERS.md` je dál
vstupní bod. `TESTERS.md` a `docs/help-authoring.md` beze změny.

### 2. `docs/dev/linux-install.md` — ruční instalace na Linuxu (D4)

Nový soubor, obsah **přesunutý** z dnešního `DEVELOPERS.md`:

- úvod (2–4 věty): pro koho to je — vlastní Linux server nebo Ubuntu přímo
  v počítači; Mac a Windows → `local-dev.md`; totéž udělá jedním příkazem
  `scripts/dev-bootstrap.sh`, tady jsou kroky jednotlivě (dnešní poznámka
  „Kroky 1–5 níže spouští za sebou…“);
- blok o alfa stavu z hlavičky sem **nepatří** (je v novém `DEVELOPERS.md`);
- **Požadavky** a kapitoly **1–5** beze změny textu, kromě:
  - poznámka o přepnutí `remote` na SSH z kapitoly 1 → do `dev-daily.md`
    (krok 3), tady jen věta s odkazem;
  - odstavec o `composer.lock` z kapitoly 3 → do `dev-daily.md`;
  - věta „Stejný skript budeš pouštět po každém `git pull` (kapitola 7)“
    → odkaz na `dev-daily.md`;
- závěr **„Kam dál“**: `dev-daily.md` (dashboard a první zdroj dat),
  `claude-code-intro.md`, zpět na `../../DEVELOPERS.md`.

### 3. `docs/dev/dev-daily.md` — první kroky a každodenní práce (D4)

Nový soubor, společný pro všechny tři cesty instalace (Multipass, WSL,
Linux). Úvodní věta: předpokládá hotovou instalaci se zeleným
`shpd-server doctor`. Oddíly:

1. **Vývojářský dashboard** — dnešní kapitola 6 z `DEVELOPERS.md` doslova.
2. **První zdroj dat** — pět kroků z `docs/local-dev.md` kapitoly 4
   doslova (včetně věty, co je zdroj dat).
3. **Po `git pull`** — dnešní kapitola 7 včetně git hooků; doplnit větu,
   že `dev-bootstrap.sh` hooky zapíná sám.
4. **Závislosti** — odstavec o `composer.lock` z kapitoly 3.
5. **Až budeš chtít posílat změny** — poznámka HTTPS × SSH `remote`
   z kapitoly 1 a odkaz na `claude-code-intro.md` kapitolu 8 (fork
   a pull request).
6. **Příkazy** — jedna věta a odkaz na `../cli.md`. Tabulky z kapitoly 8
   se **nepřenášejí**; před smazáním ověř, že každý příkaz z nich
   v `docs/cli.md` je (viz Ověření).
7. **Něco nefunguje?** — dnešní kapitola 9 doslova (`doctor`,
   `fix-permissions`, logy, nesoulad schématu, issue, Discord).

**`docs/dev/local-dev.md` po úpravě:**

- kapitola 4 „První zdroj dat“ → dvě tři věty (v dashboardu **+ New DS**
  s testovacími daty) a odkaz na `dev-daily.md`;
- kapitola 6 „Každodenní práce“ → zůstává, jak se dostat do terminálu,
  a tabulka voleb bootstrapu; část o `git pull` a `ds-upgrade-all` →
  odkaz na `dev-daily.md`; věta „přepni ho podle kapitoly 1
  v `DEVELOPERS.md`“ → odkaz na `dev-daily.md` oddíl 5;
- kapitola 7 → zůstávají potíže bootstrapu a Multipassu; obecné
  (`doctor`, `fix-permissions`, composer na 24.04, „Pořád to nejde?“) →
  odkaz na `dev-daily.md` oddíl 7. Poznámku o deprecation výpisech
  composeru přenes do `dev-daily.md`, ať nezmizí;
- úvodní blok „Máš linuxový server…“ → odkaz na `linux-install.md`.

Pozor: `docs/dev/claude-code-intro.md` a `docs/ai-workflow.md` odkazují na
čísla kapitol těchto souborů — po úpravě je projdi.

### 4. `DEVELOPERS.md` — úvod a rozcestník (D1–D3)

Celý soubor nahraď textem níže. **Text převezmi doslova** — formulace
jsou odsouhlasené. Tvoje práce je ověřit, že každý odkaz vede na
existující soubor a kotvu a že uvedená fakta platí (počty, požadavky,
licence); co neplatí, oprav na skutečnost a uveď to v závěrečném shrnutí.
Sekce nepřidávej ani neubírej.

```markdown
# Pro vývojáře

Shipard je otevřený účetní systém (licence MIT), jehož kód píše AI.
Lidé rozhodují o architektuře, pravidlech a zadání — a čtou každý diff.

> **Chceš si ho rovnou pustit?**
> Mac nebo Windows → [instalace jedním příkazem](docs/dev/local-dev.md) ·
> Linux → [ruční instalace](docs/dev/linux-install.md)

Shipard je v **alfa verzi**: hlavní věci běží, leccos se ještě mění.
Co dnes umí a co ne, popisuje [README](README.md).

---

## Proč znovu a proč takhle

Nový Shipard je přepis staršího systému stejného jména. Stojí za ním
dvacet let vývoje podobných systémů — víme, co bychom dnes udělali jinak,
a tak to děláme znovu a od základu.

Od prvního dne přitom platí jedno pravidlo: **kód píše AI**. V celém
systému není řádek kódu, který by napsal člověk. Lidé řídí architekturu,
pravidla a zadání a výsledek kontrolují.

Není to „napiš mi účetnictví“. Je to řízený vývoj se zamčenými
rozhodnutími, podrobnou dokumentací principů a pravidly, která AI svazují.
Děláme to tak, protože to vychází: při téhle metodice má kód psaný AI
lepší poměr ceny a výsledku než kód psaný ručně. Ruční programování pro
nás skončilo.

## Jak to děláme

1. **Návrh.** Problém se probere s Claudem v chatu. Rozhodnutí se očíslují
   a zamknou v issue na GitHubu dřív, než vznikne zadání.
2. **Zadání.** Z rozhodnutí vznikne task v [`tasks/`](tasks/README.md):
   co si přečíst, co udělat a podle čeho poznat, že je hotovo.
3. **Implementace.** Claude Code podle zadání napíše kód a testy —
   zpravidla jiný model než ten, se kterým vznikl návrh.
4. **Revize.** Hotová práce se vrací do chatu a prochází se celá, proti
   zadání i proti kódu okolo.
5. **Člověk** čte diff a rozhoduje, co se odešle. AI neodesílá nic
   z vlastní iniciativy.

Kódu se tak dostane víc pozornosti, než kdyby ho psali jen lidé.
Podrobně: [Vývoj s Claudem](docs/ai-workflow.md).

V historii repozitáře najdeš i commity bez podpisu Clauda. Commituje
člověk — kód v nich přesto psala AI.

## Dokumentaci nemusíš číst předem

Pravidla a dokumentace jsou rozsáhlé: [`CLAUDE.md`](CLAUDE.md), desítky
dokumentů v [`docs/`](docs/README.md) a přes tři sta zadání v `tasks/`.
Jsou psané hlavně pro AI, která je čte při každé práci. Ty je předem číst
nemusíš.

**Chceš vědět, jak něco funguje? Zeptej se Claude Code** ve svém
checkoutu. Odpoví podle kódu a dokumentace, ne z hlavy.

## Základ a moduly

Shipard je základ: uživatelé a přihlašování, modulový systém, uživatelské
rozhraní řízené serverem a nad tím běžné agendy — doklady, účetnictví,
DPH, banka, majetek.

To hlavní je rozšiřitelnost. Když ti něco chybí:

- **Hodí se to všem?** Patří to do základu — založ issue nebo pošli
  pull request.
- **Je to potřeba jedné firmy?** Udělej z toho soukromý modul mimo hlavní
  repozitář. Modul přidává tabulky, přehledy, formuláře a vlastní logiku;
  vlastní komponenty frontendu zatím ne
  ([`docs/modules.md`](docs/modules.md), kapitola 10).

V obou případech nepíšeš kód, ale zadání. A za výsledek ručíš ty.

## Jak se Shipard vyvíjí

Shipard běží na Linuxu (Ubuntu) jako server — nginx, PHP, MariaDB. Vyvíjí
se proto **vzdáleně** (remote development): kód neleží na disku tvého Macu
nebo Windows, ale na linuxovém stroji, ke kterému se připojuješ. Claude
Code běží přímo na něm a ty mu zadáváš práci z terminálu.

Tím strojem může být:

- **virtuální stroj ve tvém počítači** — Multipass na Macu, WSL ve
  Windows. Je to tentýž server, jen uvnitř tvého počítače. Stačí na
  vyzkoušení i na běžnou práci.
- **vlastní Linux server** — pro tým a vážný vývoj: běží pořád, unese víc
  zdrojů dat a přihlásí se k němu víc lidí.

## Co budeš potřebovat

- **Počítač** s asi 20 GB místa a 4 GB paměti pro virtuální stroj, nebo
  server s Ubuntu 26.04.
- **Placený účet Claude** (Pro, Max, Team nebo Enterprise) pro Claude
  Code. Na pouhé puštění Shipardu potřeba není.
- **Účet na GitHubu**, až budeš chtít poslat svou práci.

Programovat umět nemusíš. Musíš umět přesně popsat, co chceš, a být
ochotný výsledek zkontrolovat.

## Kudy dál

### Chci si Shipard pustit

1. Prostředí — [na Macu nebo ve Windows](docs/dev/local-dev.md), nebo
   [na Linux serveru](docs/dev/linux-install.md).
2. [První kroky a každodenní práce](docs/dev/dev-daily.md) — vývojářský
   dashboard, první zdroj dat, co dělat po `git pull`.

### Chci něco opravit nebo přidat

1. [Claude Code pro začátečníky](docs/dev/claude-code-intro.md) —
   instalace, oprávnění, jak zadávat a kontrolovat práci.
2. [Vývoj s Claudem](docs/ai-workflow.md) — role, postup od issue po
   ověření, pravidla.
3. [Jak vypadá zadání](tasks/README.md) — a přes tři sta hotových jako
   vzor.
4. [Fork a pull request](docs/dev/claude-code-intro.md#8-repozitář-větve-a-odeslání-práce)
   — jak svou práci poslat.

### Chci vlastní modul

[Modulový systém](docs/modules.md) popisuje strukturu modulu a v kapitole
10 moduly mimo hlavní repozitář. Nejrychlejší cesta: popiš Claude Code,
co má modul umět, a nech si navrhnout tabulky a formuláře.

### Chci Shipard pro svou zemi

Začínáme českým účetnictvím; cílem je systém pro firmy kdekoli v EU.
Další země není překlad rozhraní, ale modul země postavený na jádru —
záměr popisuje [roadmapa](docs/roadmap.md) (oddíl „Za horizontem“).
Znáš účetní a daňové reálie své země? Ozvi se nám.

### Hledám, jak je něco udělané

- [Technická dokumentace](docs/README.md) — rozcestník specifikací.
- [Architektura](docs/architecture.md) a [CLI](docs/cli.md).
- [Roadmapa](docs/roadmap.md) a [přehled funkcí a plánů](docs/features.md).
- [Produkční instalace](docs/operations/production.md) a další provozní
  postupy v `docs/operations/`.

## Kde se ptát

Rychlý dotaz — „je tohle záměr, nebo chyba?“, „jak se dělá X?“ — patří na
**[Discord](https://discord.gg/PWTt5EUFAV)**. Je nás tam hrstka,
odpovídáme rychle.

Chyby a nápady zakládej jako
[issue na GitHubu](https://github.com/shipard/shipard/issues). Hlášení
jsou veřejná — nepatří do nich skutečná jména, částky ani čísla účtů.
```

### 5. `README.md` — sekce „Pro vývojáře“ (D9)

Celou sekci (nadpis až konec souboru) nahraď:

```markdown
## Pro vývojáře

Kód Shipardu píše AI — lidé řídí architekturu, pravidla a zadání a čtou
každý diff. Pod kapotou je modulární backend v **PHP 8.5** s **MariaDB**,
REST API a frontend ve **Svelte 5**; jeden server unese víc firem
s oddělenými daty. Co v základu chybí, doplňují moduly — i soukromé, mimo
tento repozitář.

**[Jak začít s vývojem →](DEVELOPERS.md)** — proč a jak Shipard stavíme,
zprovoznění prostředí na Macu, ve Windows i na Linuxu a rozcestník návodů.
```

Odkazy na technickou dokumentaci a přehled funkcí, které ze sekce mizí,
nese rozcestník v `DEVELOPERS.md`.

### 6. `docs/README.md` a `CLAUDE.md` — členění podle čtenáře (D5)

**`docs/README.md`:** jednu tabulku rozděl na oddíly; řádky se jen
přeskupují, popisy neměň (kromě cest přesunutých souborů):

1. **Kam co patří** — hned pod nadpis tato tabulka:

   | Adresář | Čtenář | Žánr |
   |---------|--------|------|
   | [`help/`](../help/README.md) | uživatel, vestavěný asistent | jak to udělám |
   | `docs/` | Claude, vývojář | jak je to udělané — specifikace |
   | [`docs/dev/`](dev/) | vývojář, hlavně začínající | návod krok za krokem |
   | [`docs/operations/`](operations/) | správce systému | provozní postup |

   Dnešní závěrečný odstavec „Tady jsou technické specifikace. Návody pro
   uživatele…“ tím zaniká.
2. **Návody pro vývojáře** (`dev/`) — `local-dev.md`, `linux-install.md`,
   `dev-daily.md`, `claude-code-intro.md`; první řádek odkaz na
   `../DEVELOPERS.md` jako úvod.
3. **Jak se v projektu pracuje** — `roadmap.md`, `features.md`,
   `ai-workflow.md`, `documentation.md`, `help-authoring.md`, `services.md`.
4. **Specifikace** — všechno ostatní z dnešní hlavní tabulky.
5. **Dokumenty k jednotlivým modulům** a **Provoz** — jako dnes.

Doplň soubory, které v rozcestníku dnes chybí (popis jednou větou podle
úvodu dokumentu): `auth.md`, `booking-history-format.md`, `datasets.md`,
`design-import-row-operations.md`, `design-import-wave-d.md`, `hosting.md`,
`registry-mvp.md`, `structured-fields.md`, `ui-shells.md`,
`vat-calculation.md`, `viewer-grid.md` do Specifikací;
`operations/ai-gateway.md`, `operations/hosting-adopt-existing.md`,
`operations/production.md` do Provozu.

**`CLAUDE.md`:** do sekce „Dokumentace“ pod úvodní větu přidej krátké
pravidlo, kam patří **nový** dokument (stejné čtyři řádky jako tabulka
výše, jako odrážky) a větu: „Návod krok za krokem pro člověka patří do
`docs/dev/`, provozní postup pro správce do `docs/operations/`;
specifikace zůstávají v kořeni `docs/`.“ Nic dalšího v `CLAUDE.md` neměň
kromě cesty z kroku 1.

## Commit strategie

1. `docs: návody pro vývojáře do docs/dev/ (#108 D5)` — jen `git mv`
   a opravy odkazů z kroku 1 (čistý přesun, ať historie souborů drží).
2. `docs: DEVELOPERS.md jako úvod a rozcestník, instalace a každodenní práce v docs/dev/ (#108 D1–D4, D9)`
   — kroky 2–5 najednou (obsah se stěhuje mezi soubory, mezistav by ho
   měl dvakrát nebo vůbec).
3. `docs: rozcestník dokumentace podle čtenáře (#108 D5)` — krok 6,
   hlavička tohoto tasku, řádek v `tasks/README.md` už existuje (oblast
   „Server, CLI a provoz“) + `python3 scripts/tasks-index.py`.

Push ani pull request nedělej — rozhoduje člověk.

## Ověření

- **Staré cesty:** mimo `tasks/` nic nenajde

  ```bash
  grep -rn --include=*.md --include=*.sh --include=*.yaml --include=*.json \
      --exclude-dir=tasks --exclude-dir=node_modules --exclude-dir=vendor \
      -E 'docs/local-dev\.md|docs/claude-code-intro\.md|\]\((\.\./)?(local-dev|claude-code-intro)\.md' .
  ```

  (odkazy uvnitř `docs/dev/` mezi sebou jsou v pořádku — projdi výsledek
  očima).
- **Odkazy a kotvy:** jednorázovým skriptem (necommitovat) projdi
  `DEVELOPERS.md`, `README.md`, `docs/README.md`, `docs/dev/*.md`,
  `docs/ai-workflow.md`, `docs/cli.md`, `docs/operations/production.md` —
  každý relativní odkaz vede na existující soubor, každá kotva `#…` na
  existující nadpis.
- **Nic se neztratilo:** každý odstavec dnešního `DEVELOPERS.md` kapitol
  1–7 a 9 je v `linux-install.md` nebo `dev-daily.md`; každý příkaz
  z tabulek kapitoly 8 je v `docs/cli.md`.
- **Rozcestník je úplný:** každý `docs/**/*.md` mimo `archive/` je
  v `docs/README.md`.
- **Skripty:** `bash -n scripts/dev-bootstrap.sh`,
  `bash scripts/dev-bootstrap.sh --help` vypíše novou cestu návodu;
  v `shipard-dev.yaml` se změnil jen komentář.
- `python3 scripts/check-sensitive.py`, `python3 scripts/tasks-index.py --check`,
  `python3 scripts/help-index.py --check`.

## Hotovo když

- [x] `docs/dev/` obsahuje `local-dev.md`, `linux-install.md`,
      `dev-daily.md`, `claude-code-intro.md`; staré cesty neexistují
- [x] `DEVELOPERS.md` je úvod a rozcestník podle sekce 4, všechny odkazy
      a kotvy platí
- [x] z původního `DEVELOPERS.md` se neztratil žádný postup
- [x] sekce „Pro vývojáře“ v `README.md` podle sekce 5
- [x] `docs/README.md` členěný podle čtenáře a úplný; pravidlo v `CLAUDE.md`
- [x] živé odkazy mimo `tasks/` míří na nové cesty (včetně skriptů)
- [x] hlavička tasku aktualizovaná, `tasks-index.py` spuštěný

## Vazby a mimo rozsah

- **Web** (D6, D8) — sekce „Pro vývojáře“ na vývojovém webu je task
  v repozitáři webu. Web renderuje `README.md`; po tomto tasku ohlásí
  zastaralý anglický překlad a změněný cíl odkazu na `DEVELOPERS.md` —
  řeší se tam.
- **Anglická verze** `DEVELOPERS.md` a návodů — překlady žijí u webu.
- **Návod „první modul s Claude Code“** (D7) — samostatný task.
- **Soukromý `shipard/dev-env`** odkazuje na `DEVELOPERS.md` jako na
  rozchození prostředí a na `docs/local-dev.md` — opraví se tam zvlášť.
- Přesun nebo přejmenování specifikací v `docs/`; nové dokumenty pro
  správce systému.
