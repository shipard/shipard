# Claude Code pro začátečníky

Návod pro člověka, který s Claude Code (nebo podobným nástrojem) ještě
nepracoval. Předpokládá hotové vývojové prostředí podle
[`local-dev.md`](local-dev.md) nebo `DEVELOPERS.md`. Jak se v projektu
dělí práce mezi Claude v chatu, Claude Code a člověka, popisuje
[`ai-workflow.md`](../ai-workflow.md) — tady jde o to, jak s Claude Code
prakticky začít.

Podrobnosti o samotném nástroji jsou v oficiální dokumentaci
[code.claude.com/docs](https://code.claude.com/docs). Tady jsou jen věci,
které potřebuješ pro Shipard, a místa, kde se dá snadno udělat chyba.

---

## 1. Co je Claude Code

Claude Code je Claude, který běží v terminálu přímo v adresáři projektu.
Čte soubory, spouští příkazy, upravuje kód a pouští testy — sám, krok za
krokem, podle toho, co mu napíšeš. Ty mu zadáváš práci obyčejnou češtinou
a rozhoduješ, co se s výsledkem stane.

Od Clauda v chatu (claude.ai) se liší tím, že **pracuje přímo v tvém
checkoutu**. Chat se v projektu používá hlavně na návrh a ověřování;
Claude Code na implementaci. Na běžnou práci stačí Claude Code sám
(`ai-workflow.md` §1).

---

## 2. Co budeš potřebovat

- **Vývojové prostředí** — Ubuntu v Multipassu nebo ve WSL podle
  [`local-dev.md`](local-dev.md). Claude Code se instaluje **dovnitř**
  Ubuntu, ne na Mac nebo do Windows.
- **Placený účet Claude** — Pro, Max, Team nebo Enterprise (případně účet
  v Claude Console). Bezplatný plán Claude Code nezahrnuje.
- **Účet na GitHubu** — až budeš chtít poslat svou práci (kapitola 8).

---

## 3. Instalace a přihlášení

V terminálu Ubuntu (Mac: `multipass shell shipard`, Windows: okno Ubuntu):

```bash
curl -fsSL https://claude.ai/install.sh | bash
```

Pak otevři **nový** terminál (nebo se odhlas a přihlas) a ověř:

```bash
claude --version
```

Když příkaz `claude` neexistuje, instalátor ho nepřidal do `PATH` — řešení
je v oficiální dokumentaci (*Troubleshoot installation*). Claude Code se
pak aktualizuje sám.

**Přihlášení:** při prvním spuštění `claude` tě Claude Code vyzve
k přihlášení přes prohlížeč. Ve virtuálním stroji prohlížeč není — Claude
Code vypíše odkaz; otevři ho v prohlížeči na svém počítači, přihlas se
a případný kód, který stránka ukáže, vlož zpátky do terminálu.

---

## 4. První spuštění

```bash
cd ~/sw/shpd
claude
```

- Claude Code se zeptá, jestli **důvěřuješ souborům v adresáři** — potvrď,
  je to tvůj checkout.
- **`/init` nespouštěj.** Projekt už má `CLAUDE.md` — soubor s pravidly,
  který Claude Code načte sám na začátku každé session.
- Napiš `/context` a v sekci **Memory files** zkontroluj, že je načtený
  `CLAUDE.md` (a `CLAUDE.local.md`, pokud sis ho založil —
  `ai-workflow.md` §7).

Na první zkoušku se zeptej na něco, co nic nemění, třeba:

> Vysvětli mi, jak je v projektu organizovaný modul `economy` a kde se
> definují tabulky.

Uvidíš, jak Claude prochází soubory a skládá odpověď. Session ukončíš
`/exit` nebo `Ctrl+D`.

---

## 5. Oprávnění — co smí Claude dělat bez ptaní

Claude Code má **režimy oprávnění**. Přepínají se klávesou **`Shift+Tab`**
a aktuální režim je vidět ve stavovém řádku pod polem pro psaní.

| Režim | Co Claude dělá bez ptaní | Kdy ho použít |
|-------|--------------------------|---------------|
| **Manual** (`manual mode on`) | jen čte; na úpravy souborů i příkazy se ptá | **začátek** — vidíš každý krok a učíš se, co Claude dělá a proč |
| **acceptEdits** (`accept edits on`) | upravuje soubory; příkazy pořád schvaluješ | když už se v nástroji vyznáš; změny pak projdeš přes `git diff` |
| **plan** (`plan mode on`) | jen zkoumá a navrhne postup, nic nemění, dokud plán neschválíš | před větší změnou — „nejdřív mi řekni, co uděláš“ |
| **auto** (`auto mode on`) | téměř vše; akce místo tebe posuzuje bezpečnostní kontrola | až s porozuměním a jen v lokální VM se seedovanými daty |

Nové verze Claude Code **startují v režimu auto**. Na začátku proto stiskni
`Shift+Tab`, dokud neuvidíš `manual mode on`. Režim, ve kterém Claude
přeskakuje všechny kontroly (`bypassPermissions`,
`--dangerously-skip-permissions`), v projektu **nepoužívej**.

Když se Claude ptá na povolení, přečti si, **co** chce spustit. Odpověď
„ano a už se neptej“ dávej jen příkazům, kterým rozumíš (např. testy).

**Co repo hlídá vždy.** Sdílené nastavení `.claude/settings.json` v repu
platí v každém režimu, i v auto:

- **`git push`, `gh pr create`, `gh pr merge` — Claude se vždy zeptá.**
  Odeslání práce spouští jen na tvůj výslovný pokyn a ty ho potvrzuješ
  (kapitola 8). Když se na odeslání zeptá, aniž jsi o něj požádal,
  odmítni.
- **Čtení konfigurace a secrets zdrojů dat** (`config/main.json`,
  `secrets/`) **je zakázané.** Zákaz zachytí přímé čtení souboru;
  aplikace a její příkazy si konfiguraci čtou dál.

Když Claude narazí na zákaz, není to chyba — ten krok vynech, nebo ho
udělej sám.

---

## 6. Jak zadávat práci

**Malá věc** (překlep, drobná oprava, jedna funkce) — napiš rovnou, ale
konkrétně: **co** chceš, **kde** to je a **jak se pozná, že je hotovo**.

> V dev dashboardu je u tlačítka „Upgrade All“ anglický popisek. Najdi,
> kde se generuje, a navrhni opravu — zatím nic neměň.

Věta „zatím nic neměň“ je užitečný zvyk: Claude nejdřív ukáže, co našel,
a ty rozhodneš.

**Větší věc** — v projektu se zadává **task filem** v `tasks/`
(`ai-workflow.md` §2, formát `tasks/README.md`). Pak stačí:

> implementuj tasks/nazev-tasku.md

Claude si přečte task i dokumenty, na které odkazuje, a postupuje podle
něj. Task obvykle napíše Claude v chatu spolu s tebou, nebo ho najdeš
hotový v `tasks/` (stav „naplánováno“).

**Plan mode** — u čehokoli, co se dotkne víc souborů, přepni `Shift+Tab`
na plan. Claude prozkoumá kód a předloží plán; ty ho schválíš, upravíš,
nebo zamítneš.

**Jeden úkol = jedna session.** Mezi nesouvisejícími úkoly napiš
`/clear` — Claude začne s čistou hlavou a nemíchá si úkoly. Když je
session dlouhá a Claude začne zapomínat, co se domluvilo, pomůže
`/compact` (shrne dosavadní rozhovor), ale lepší je úkol dokončit
a začít znovu.

---

## 7. Jak zkontrolovat, co Claude udělal

„Hotovo“ od Clauda znamená, že **on** si myslí, že je hotovo. Ověř to:

1. **Přečti si změny.** Požádej Clauda o shrnutí („co přesně jsi změnil
   a proč?“), ale změny si projdi i sám: `git diff` v terminálu, nebo
   diff v editoru. Nečekané soubory ve změnách jsou důvod se zeptat.
2. **Podívej se do aplikace.** Po změně frontendu je potřeba build
   (`bash scripts/dev-update.sh`) a v prohlížeči tvrdé obnovení
   (`Cmd+Shift+R` / `Ctrl+Shift+R`). Po změně tabulek nebo konfigurace
   modulů `shpd-server ds-upgrade-all`.
3. **Testy.** Claude spouští cílené testy sám; když chceš ověřit
   konkrétní věc, řekni mu, ať pustí testy k té změně. Celá sada trvá
   dlouho — nech ji na konec.
4. **Když něco nesedí**, napiš mu to stejně konkrétně jako zadání
   („po uložení formuláře se v seznamu neobjeví nový záznam“).

---

## 8. Repozitář, větve a odeslání práce

S gitem a GitHubem nemusíš umět pracovat z hlavy — **Claude to umí**
a příkazy `git` a [`gh`](https://cli.github.com) (GitHub v terminálu)
používá sám. Potřebuješ jen rozumět pojmům a vědět, které kroky jsou
tvoje.

**Pojmy:**

- **commit** — uložená a popsaná sada změn;
- **větev** — vlastní linie commitů, ve které pracuješ, aniž bys
  ovlivnil hlavní větev `stable`;
- **fork** — tvoje kopie repozitáře na GitHubu; do hlavního repa
  `shipard/shipard` zapisovat nemůžeš, do forku ano;
- **pull request (PR)** — žádost, aby tým tvou větev z forku převzal
  do `stable`. Tým ji projde a sloučí.

**Jednou na začátku** — přihlášení do GitHubu a fork:

```bash
gh auth login
```

Pak požádej Clauda:

> Udělej mi fork repozitáře shipard/shipard a nastav checkout tak,
> aby origin byl můj fork a upstream původní repo.

**Při každé práci:**

1. **Větev** — „založ větev pro opravu popisku v dashboardu“.
2. **Práce a kontrola** — kapitoly 6 a 7.
3. **Commit** — „commitni to“. U tasku Claude commituje podle jeho
   commit strategie. Commit je zatím jen u tebe, nikam neodešel.
4. **Odeslání — jen na tvůj pokyn.** Požádej Clauda, ať připraví popis
   PR do souboru („napiš popis PR do /tmp/pr.md“), a přečti si ho. Pak:

   > Pushni větev do mého forku a založ pull request do shipard/shipard
   > s popisem z /tmp/pr.md.

   Claude se před pushem i před založením PR **zeptá** — přečti si, co
   a kam odesílá, a potvrď. Odkaz na PR pak vypíše. Stejný krok můžeš
   udělat i sám v terminálu:

   ```bash
   gh pr create --repo shipard/shipard --body-file /tmp/pr.md
   ```

   `gh` se zeptá, kam větev pushnout (do tvého forku) a jaký má mít PR
   název.
5. **Aktuální stav** — před další prací „stáhni změny z upstreamu do
   stable a založ novou větev“. Po stažení se závislosti a frontend
   aktualizují samy (git hooky z bootstrapu).

**Proč o odeslání rozhoduješ ty:** repozitář je veřejný. Co jednou
odejde, vidí všichni — proto Claude nic neodešle sám od sebe a každé
odeslání potvrzuješ ty. Repo to vynucuje i technicky (kapitola 5).
Přímo do hlavní větve `stable` navíc smí zapisovat jen několik lidí;
ostatní posílají pull requesty.

---

## 9. Typické pasti

- **Veřejné repo.** Do commitů, PR, issues a dokumentace nepatří jména
  reálných firem, osob ani zdrojů dat — jen prefix ID (`CLAUDE.md` →
  *Zdroje dat ve veřejných textech*). Git hook při commitu kontroluje
  typické případy, ale ne všechny.
- **Frontend „se nezměnil“** — chybí build nebo tvrdé obnovení
  (kapitola 7).
- **Nová tabulka nebo pole „neexistuje“** — chybí
  `shpd-server ds-upgrade-all`.
- **Příkaz visí** — čeká na potvrzení, které v Claude Code nikdo nedá
  (typicky `shpd-server fix-permissions` bez `--dry-run`;
  `ai-workflow.md` §4).
- **Claude se točí v kruhu** — zastav ho (`Esc`), popiš, co vidíš ty,
  a případně začni znovu s `/clear` a přesnějším zadáním.
- **Claude si „vzpomíná“ na něco, co neplatí** — Claude Code si dělá
  vlastní poznámky (auto memory, `/memory`). Jsou jen na tvém stroji;
  co má platit pro všechny, patří do souboru (`ai-workflow.md` §5).

---

## 10. Kam dál

- [`ai-workflow.md`](../ai-workflow.md) — role, postup, pravidla, Claude
  v chatu a `remote-dev-bridge`
- [`tasks/README.md`](../../tasks/README.md) — formát tasků a jejich stav;
  rozpracované a naplánované tasky jsou dobrý start
- [`README.md`](../README.md) — mapa dokumentace
- [`roadmap.md`](../roadmap.md) — kam projekt míří
- **[Discord](https://discord.gg/PWTt5EUFAV)** — když nevíš, čím začít,
  nebo se zasekneš
