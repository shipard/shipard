# Sdílené nastavení Claude Code — co je zakázané v každém režimu

**Stav:** naplánováno — #96 D27

## Cíl

Nové verze Claude Code startují v režimu oprávnění **auto**, kde akce
neschvaluje člověk, ale bezpečnostní klasifikátor. Ten ve výchozím stavu
**povoluje push do repozitáře, ve kterém se pracuje**. Pravidlo projektu
„push je vždy lidský“ (`docs/ai-workflow.md` §3) je dnes jen v textu
a v `CLAUDE.md` chybí úplně.

Repo dostane verzované `.claude/settings.json` s pravidly `deny`, která
platí ve **všech** režimech (včetně auto), a `CLAUDE.md` dostane jednu
větu o odesílání práce. Návod `docs/claude-code-intro.md` (kapitola 5)
už s tímhle nastavením počítá.

## Před implementací přečti

- oficiální dokumentace Claude Code — *Permissions* (syntaxe pravidel
  `Bash(…)` a `Read(…)`, absolutní cesty, jak se `Read` pravidla
  uplatňují na příkazy shellu) a *Settings* (umístění a priorita
  `.claude/settings.json`)
- `docs/ai-workflow.md` §3, `docs/claude-code-intro.md` kapitoly 5 a 8
- `.gitignore` (dnes ignoruje jen `/.claude/settings.local.json`)

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D27** — sdílené `.claude/settings.json` s `permissions.deny`:
  odeslání práce (`git push`, `gh pr create`, `gh pr merge`) a čtení
  konfigurace a secrets zdrojů dat. Do `CLAUDE.md` věta „nikdy
  nepushuj“.
- ✓ **D28** — začátečníci začínají v režimu Manual (návod) — nastavení
  výchozí režim **nevnucuje**.

## Co je potřeba udělat

### 1. `.claude/settings.json`

`permissions.deny` (přesnou syntaxi ověř v dokumentaci a na své verzi
Claude Code):

- `Bash(git push *)` a varianta bez argumentů (`git push`);
- `Bash(gh pr create *)`, `Bash(gh pr merge *)` — `gh pr create` větev
  sám pushne, takže bez toho jde zákaz pushe obejít;
- čtení `config/main.json` a obsahu `secrets/` libovolného zdroje dat
  pod `/opt/shipard/data-sources/` (`Read(…)` s absolutní cestou).
  Ověř, jestli se `Read` pravidla uplatní i na rozpoznané příkazy
  shellu (`cat`, `head`, …); pokud ne, doplň odpovídající `Bash(…)`
  pravidla pro nejběžnější čtecí příkazy a v hlavičce souboru (nebo
  v tasku) poznamenej, že jde o pojistku, ne o úplnou ochranu.

Nic jiného (allow pravidla, výchozí režim, hooky) do souboru nedávat.

`.gitignore`: `.claude/settings.json` verzovat; ostatní obsah `.claude/`
(lokální stav nástroje, např. zámky) ignorovat — `settings.local.json`
zůstává ignorovaný.

### 2. `CLAUDE.md`

Krátce, na místo, kde jsou obecná pravidla práce: odeslání práce (push,
pull request, merge) dělá vždy člověk; Claude připraví commity a popis PR
do souboru. Odkaz na `docs/ai-workflow.md` §3 a `docs/claude-code-intro.md`
kapitolu 8.

### 3. Dokumentace

`docs/ai-workflow.md` §3, odrážka „Push je vždy lidský“: doplnit, že ho
v Claude Code vynucuje `.claude/settings.json`. Pokud se při implementaci
ukáže, že popis v `docs/claude-code-intro.md` kapitole 5 neodpovídá
skutečnému chování (např. rozsah blokace čtení), opravit ho.

## Commit strategie

1. `claude: sdílené .claude/settings.json — zákaz pushe a čtení secrets (#96 D27)`
   — settings, `.gitignore`, `CLAUDE.md`
2. `docs: push vynucený nastavením Claude Code (#96 D27)` —
   `ai-workflow.md`, případně `claude-code-intro.md`, hlavička tasku,
   `tasks/README.md` (oblast „Server, CLI a provoz“) +
   `python3 scripts/tasks-index.py`

## Ověření

V nové session Claude Code v checkoutu, v režimu **auto** i **Manual**:

- `/permissions` ukazuje pravidla z `.claude/settings.json`;
- pokus o `git push --dry-run` je zablokovaný bez dotazu;
- pokus o `gh pr create --help` projde (help není odeslání), pokus
  o `gh pr create --title x --body x` je zablokovaný;
- pokus o čtení `config/main.json` libovolného DS (nástrojem Read
  i `cat`) je zablokovaný — **nic se nevypíše** (ověřovat na DS
  v režimu „volný“);
- `git status`, `git diff`, `git commit` fungují dál.

## Hotovo když

- [ ] `.claude/settings.json` ve verzi, ostatní obsah `.claude/` ignorovaný
- [ ] push i `gh pr create` / `gh pr merge` zablokované v auto i Manual
- [ ] čtení `config/main.json` a `secrets/` zablokované (v rozsahu, který
      Claude Code umožňuje — případné mezery popsané)
- [ ] `CLAUDE.md` a `ai-workflow.md` §3 zmiňují pravidlo a jeho vynucení
- [ ] hlavička tasku a `tasks/README.md` aktualizované
