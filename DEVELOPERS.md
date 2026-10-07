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
