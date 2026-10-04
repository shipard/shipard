# Sdílené nastavení Claude Code — co platí v každém režimu

**Stav:** hotovo — #96 D27, D30, D31; 2026-10-04 ověřeno v nové session Claude Code v režimech auto i Manual (`/permissions`: 4× ask, 2× deny; push se ptá, čtení `config/main.json` odmítnuto)

## Cíl

Nové verze Claude Code startují v režimu oprávnění **auto**, kde akce
neschvaluje člověk, ale bezpečnostní klasifikátor. Ten ve výchozím stavu
**povoluje push do repozitáře, ve kterém se pracuje**. Pravidlo projektu
„o odeslání rozhoduje člověk“ (`docs/ai-workflow.md` §3) bylo jen v textu
a v `CLAUDE.md` chybělo úplně.

Repo dostane verzované `.claude/settings.json` s pravidly, která platí ve
**všech** režimech (včetně auto): odeslání práce si vždy vyžádá potvrzení
člověka (`ask`), čtení konfigurace a secrets zdrojů dat je zakázané
(`deny`). `CLAUDE.md` dostane pravidlo o odesílání práce. Návod
`docs/claude-code-intro.md` (kapitoly 5 a 8) s tímhle nastavením počítá.

## Před implementací přečti

- oficiální dokumentace Claude Code — *Permissions* (syntaxe pravidel
  `Bash(…)` a `Read(…)`, absolutní cesty, jak se `Read` pravidla
  uplatňují na příkazy shellu), *Permission modes* (co který režim
  dělá s pravidly `ask` a `deny`) a *Settings* (umístění a priorita
  `.claude/settings.json`)
- `docs/ai-workflow.md` §3, `docs/claude-code-intro.md` kapitoly 5 a 8
- `.gitignore`

## Rozhodnutí (potvrzená, issue #96)

- ✓ **D27** — sdílené `.claude/settings.json` s pravidly platnými v každém
  režimu a věta o odesílání do `CLAUDE.md`. Část o odeslání (`deny` pro
  `git push`, `gh pr create`, `gh pr merge`) je **nahrazena D30**; zákaz
  čtení konfigurace a secrets zdrojů dat (`deny`) platí.
- ✓ **D28** — začátečníci začínají v režimu Manual (návod) — nastavení
  výchozí režim **nevnucuje**.
- ✓ **D30** — nahrazuje D27 v části o odeslání a upravuje D29: příkazy
  odeslání jsou v `permissions.ask`, ne v `deny`. Claude Code je spouští
  **jen na výslovný pokyn** a člověk každé odeslání potvrzuje — v každém
  režimu včetně auto. Důvod: `deny` by zakázal push i na výslovnou žádost
  (řešení konfliktů s commity na remote, rebase); přímý zápis do `stable`
  omezují práva na GitHubu (zápis mají jen správci), ostatní posílají
  pull requesty z forku.
  Claude v chatu (MCP most) potvrzovací dotaz nemá — tam dál platí
  „nikdy nepushuj“.
- ✓ **D31** — větev `stable` chrání ruleset na GitHubu: zákaz force-push
  a smazání, bez výjimek (platí i pro správce); přímý push správců
  neomezuje. Založeno 2026-10-04.

## Co je potřeba udělat

### 1. `.claude/settings.json`

`permissions.ask` — odeslání práce:

- `Bash(git push)` a `Bash(git push *)`;
- `Bash(gh pr create *)`, `Bash(gh pr merge *)` — `gh pr create` větev
  sám pushne, takže bez něj jde potvrzení pushe obejít.

`permissions.deny` — čtení `config/main.json` a obsahu `secrets/`
libovolného zdroje dat pod `/opt/shipard/data-sources/` (`Read(…)`
s absolutní cestou, tj. s `//` na začátku).

Nic jiného (allow pravidla, výchozí režim, hooky) do souboru nedávat.

`.gitignore`: `.claude/settings.json` verzovat; ostatní obsah `.claude/`
(lokální stav nástroje, např. zámky) ignorovat — `settings.local.json`
zůstává ignorovaný.

### 2. `CLAUDE.md`

Krátce, na místo, kde jsou obecná pravidla práce: o odeslání práce (push,
pull request, merge) rozhoduje vždy člověk; Claude ho spouští jen na
výslovný pokyn, jinak připraví commity a popis PR do souboru. Odkaz na
`docs/ai-workflow.md` §3 a `docs/claude-code-intro.md` kapitolu 8.

### 3. Dokumentace

`docs/ai-workflow.md`: tabulka rolí (§1), krok ověření (§2) a §3 —
odrážka o odeslání (pokyn + potvrzení, vynucení nastavením) a u secrets
zmínka o blokaci čtení. Instrukce Projektu v claude.ai (§8) se nemění.

`docs/claude-code-intro.md`: kapitola 5 („vždy se zeptá“ × „vždy
zakázané“) a kapitola 8 (odeslání na pokyn s potvrzením).

## Zjištění při implementaci

Claude Code 2.1.258, dokumentace *Permissions*, *Permission modes*
a *Auto mode config*:

- `ask` se vyhodnocuje před klasifikátorem auto režimu a vynutí dotaz
  v každém režimu (i `bypassPermissions`). Má přednost před `allow`,
  takže ho lokální nastavení ani „už se neptej“ nevypne. V režimu bez
  dotazů (`dontAsk`) se volání zamítne.
- Klasifikátor auto režimu se ze sdíleného nastavení řídit nedá — blok
  `autoMode` se z `.claude/settings.json` v repu záměrně nečte. `ask` je
  jediný sdílený mechanismus lidského potvrzení.
- `ask` nerozliší pokyn člověka od iniciativy Clauda — v obou případech
  vyskočí dotaz. Proto pravidlo „jen na výslovný pokyn“ nese i `CLAUDE.md`.
- `Bash(git push *)` podle dokumentace kryje i holý `git push`;
  `Bash(git push)` je pojistka navíc.
- `Bash(gh pr create *)` zachytí i `gh pr create --help`; nápověda mimo
  pravidlo je `gh help pr create`.
- `settings.json` je striktní JSON bez komentářů — poznámky jsou tady
  a v `docs/ai-workflow.md` §3.

**Pojistka, ne úplná ochrana:**

- Bash pravidla zachytí příkaz v běžném tvaru; `git -C . push` nebo
  `git -c x=y push` ne. Širší vzor (`Bash(git * push *)`) by zachytil
  i `git stash push` a commit se slovem „push“ ve zprávě — záměrně není.
  Hranicí jsou práva na GitHubu (zápis jen správci) a ruleset na `stable`
  (zákaz force-push a smazání, #96 D31).
- `Read` pravidla platí pro nástroje Read / Grep / Glob, pro příkazy,
  které soubor jmenují (`cat`, `head`, `tail`, `sed`, …), a pro
  přesměrování; relativní cesta se vyhodnotí vůči aktuálnímu adresáři.
  Neplatí pro `grep -r` nad adresářem ani pro skript, který si soubor
  otevře sám — díky tomu dál fungují `shpd-ds` a integrační testy.

## Commit strategie

1. `claude: sdílené .claude/settings.json — potvrzení pushe a zákaz čtení secrets (#96 D30)`
   — settings, `.gitignore`, `CLAUDE.md`
2. `docs: odeslání práce na pokyn a s potvrzením (#96 D30)` —
   `ai-workflow.md`, `claude-code-intro.md`, tento task,
   `tasks/README.md` (oblast „Server, CLI a provoz“) +
   `python3 scripts/tasks-index.py`

## Ověření

V nové session Claude Code v checkoutu, v režimu **auto** i **Manual**:

- `/permissions` ukazuje pravidla z `.claude/settings.json` (Ask a Deny);
- v režimu auto si `git push --dry-run` vyžádá potvrzení (v Manual se
  Claude ptá i bez pravidla, průkazný je auto); odmítnutí = nic neodejde;
- `gh pr create --title x --body x` si vyžádá potvrzení — **odmítnout**;
- čtení `config/main.json` libovolného DS (nástrojem Read i `cat`) je
  zablokované — **nic se nevypíše**. Bezpečná sonda: `head -c 0 <soubor>`
  (bez pravidla nevypíše nic, s pravidlem je zamítnutá); ověřovat na DS
  v režimu „volný“;
- `git status`, `git diff`, `git commit` fungují dál.

Při implementaci ověřeno (režim auto): nástroj Read i `head` s absolutní
a relativní cestou na `config/main.json` a `head` na `secrets/secrets.key`
jsou zablokované; `git status` a `git commit` fungují. Zda u
`git push --dry-run` vyskočil dotaz, vidí jen člověk — proto zbývá ruční
ověření.

## Hotovo když

- [x] `.claude/settings.json` ve verzi, ostatní obsah `.claude/` ignorovaný
- [x] push i `gh pr create` / `gh pr merge` si vyžádají potvrzení v auto
      i Manual (ruční ověření v nové session)
- [x] čtení `config/main.json` a `secrets/` zablokované (v rozsahu, který
      Claude Code umožňuje — mezery popsané výše)
- [x] `CLAUDE.md` a `ai-workflow.md` §3 zmiňují pravidlo a jeho vynucení
- [x] hlavička tasku a `tasks/README.md` aktualizované
