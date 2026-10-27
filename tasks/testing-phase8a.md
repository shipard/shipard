# F8a — testovací kolo jedním příkazem

**Stav:** hotovo — 2026-10-27 (#103 D130–D135); kolo bez evaluace ověřené z CLI i z dashboardu, evaluace v kole jen unit testy (útrata čeká na schválení)

## Kontext / Cíl

Po F1–F7 má vývojářský server všechny stavební kameny testování, ale
pouští se po kouskách: testy kódu ručně (v repu není CI), u každé
veřejné sady zvlášť `dataset-verify` a `dataset-e2e`, u každého
videoscénáře `video-run --verb check`, evaluace z dashboardu.

**Cíl:** jedním příkazem (a jednou akcí ve vývojářském dashboardu)
projít celé kolo — kód, veřejné sady, videoscénáře, volitelně evaluace —
a dostat jeden souhrn. Kolo nesmí nic utratit, pokud o to člověk
výslovně nepožádá.

## Návaznost

- **#103** D130–D135 (komentář 2026-10-27); D55 (zdroj dat sady), D58–D60
  (úlohy dashboardu), D88, D91, D96, D124, D126
- `tasks/testing-phase3.md` (`dataset-verify`), `testing-phase4*.md`
  (`ai-eval`, odhad ceny), `testing-phase6b.md` / `6c` (úlohy, panel sady),
  `testing-phase7a.md` (`dataset-e2e`), `testing-phase7b.md` (`video-run`)
- **#129** U1 (serverová úloha `update` vylučuje ostatní)
- **#131** — demo sada (název firmy, D135)

## Před implementací přečti

- `docs/test-corpus.md` § 17–20
- `src/Core/DevJobs/*` (`JobRecord::TYPES`, `SERVER_TYPES`, `JobLauncher::launch`,
  `JobStore::activeJobFor`, `JobStepRunner`, `dev-job-run`)
- `src/Api/Controller/DevEvalController.php` (jak se zakládají úlohy
  `reseed`, `verify`, `e2e`, `eval`, `create` a zdroj dat sady),
  `DevVideosController.php` (úloha `video`, zdroj dat pro videa)
- `src/Command/DataSource/DatasetVerifyCommand.php`, `DatasetE2eCommand.php`,
  `VideoRunCommand.php`, `AiEvalCommand.php` (`--estimate`)
- `src/Core/DevUpdate/UpdateEnvironment.php` (`findTool`, pevný `PATH`),
  `src/Core/Version.php` (`gitInfo`)
- `frontend/src/dev/pages/testing/*` (`TestingPage`, `JobsPage`,
  `JobDetailPage`, výsledky `VerifyResult`, `E2eResult`)
- `demo/datasets/stories/it-servis/story.jsonc`, `setup/settings.jsonc`,
  `demo/datasets/README.md`

## Rozhodnutí

Zamčená v #103:

- **D130 — Kolo = `shpd-server test-round`,** serverová úloha, tři vrstvy:
  **kód** (celý PHPUnit, testy generátoru korpusu, frontendu — `npm test`,
  `check:i18n`, kontroly CSS —, video-runneru a specu proklikání),
  **sady** (pro každou veřejnou sadu `dataset-verify` na čerstvém seedu
  a `dataset-e2e`), **videa** (`video-run --verb check` všech scénářů).
  Výběr `--only code|datasets|videos`, `--dataset`, `--scenario`.
- **D131 — Výchozí kolo nic neutrácí.** Evaluace jen výslovně
  (`--eval <varianta> --repeat N --max-cost <USD>`): nejdřív odhad, nad
  limitem kolo nezačne; dashboard ukáže odhad a čeká na potvrzení.
- **D132 — Jen vlastní zdroje dat:** zdroj dat sady (D55) a zdroj pro
  videa (D126); chybějící kolo založí (volné, bez uživatele; videa
  s cronem a `ds-upgrade`, F7b N1–N2). Reálnou kopii odmítne.
- **D133 — Pokračuje po selhání, souhrn na konci:** ok / failed / skipped
  per vrstva, sada a scénář, odkazy na dílčí výsledky, commit a příznak
  necommitnutých změn. Exit 0 jen při úplném úspěchu. Výsledek v adresáři
  úlohy, ne v repu; dashboard ukáže poslední kolo na stránce Testování,
  starší v historii úloh.
- **D134 — Zámky postupně:** kolo bere zdroje dat jeden po druhém; jiné
  úlohy na zdroji, se kterým zrovna pracuje, dashboard odmítne jako dnes.
  Současně smí běžet jen jedno kolo.
- **D135 — Drobnosti z F7b:** `meta.json` videa nese commit i z úlohy
  dashboardu (`git` přes pevný `PATH`); demo sada nastaví název firmy
  aplikace, aby záhlaví videa neukazovalo název zdroje dat.

## 1. Kolo jako posloupnost dílčích úloh

- Nový serverový typ úlohy `round` (`SERVER_TYPES`); **na rozdíl od
  `update` nevylučuje ostatní úlohy** — vylučuje jen druhé kolo (D134).
- Kroky na zdrojích dat kolo spouští jako **běžné dílčí úlohy** stávajících
  typů (`reseed`, `verify`, `e2e`, `video`, `eval`) přes `JobLauncher`,
  s odkazem na rodičovské kolo, a čeká na jejich konec. Tím platí zámek
  „jedna aktivní úloha na zdroj dat“, zrušení, logy i stávající zobrazení
  výsledků bez druhé implementace. Když je zdroj dat obsazený jinou
  úlohou, krok se označí `skipped` s důvodem (nečeká se).
- Zrušení kola zruší běžící dílčí úlohu a další už nespustí.
- **Pořadí:** kód → pro každou sadu `reseed` → `verify` → `e2e` (sám
  přeseeduje) → [`reseed` → `eval`, je-li vyžádaná] → videoscénáře.
- **Vrstva kódu** běží jako kroky samotného kola (bez zdroje dat):
  `vendor/bin/phpunit`, `python3 -m unittest discover -s tests`
  v `tools/invoice-generator`, ve `frontend/` `npm test` a
  `npm run check:i18n`, `scripts/check-css-tokens.py`,
  `scripts/check-css-units.py`, `npm test` v `tools/video-runner`,
  `npm run test:unit` v `tests/e2e`. Nástroje přes
  `UpdateEnvironment::findTool`; chybějící nástroj = krok `failed`
  s přesnou větou, co doinstalovat. Výstup každého kroku do logu kola,
  do souhrnu jen počty (prošlo / selhalo) a první selhání.
- **CLI a dashboard stejně:** `shpd-server test-round` založí stejný
  záznam úlohy jako dashboard; výchozí běh v popředí s průběhem na
  terminálu, `--detach` vrátí id úlohy. Kolo z CLI je vidět v dashboardu.

## 2. Evaluace v kole (D131)

- `--eval <varianta>` (z `tests/eval/variants/`), `--repeat N`
  (výchozí 1), `--max-cost <USD>` povinný s `--eval`.
- Před prvním krokem odhad ceny pro všechny vybrané sady (stejná služba
  jako `ai-eval --estimate`); součet nad limitem → kolo nezačne, exit 2,
  výpis odhadu po sadách.
- Výsledky evaluací se zapisují jako dnes do `tests/eval/<sada>/`
  (commit dělá člověk); souhrn kola uvede soubory a shodu polí.

## 3. Zdroje dat (D132)

- Najít zdroj dat sady a zdroj pro videa stejnou logikou jako dashboard;
  chybějící založit (dílčí úloha `create` / stávající postup F7b).
  Do souhrnu, které zdroje dat kolo založilo.
- Třída `realCopy` → odmítnutí (fail-closed, `DevActionGuard`).

## 4. Souhrn (D133)

- `result.json` kola: commit, větev, necommitnuté změny (ano / ne),
  začátek a konec, výběr vrstev, tabulka výsledků (vrstva / sada /
  scénář / krok → stav, doba, id dílčí úlohy, krátký důvod při selhání),
  u evaluací shoda polí a cena.
- Čitelný výpis v CLI (tabulka), `--json` pro stroje. Exit 0 / 1 (něco
  selhalo nebo přeskočeno) / 2 (kolo nezačalo — preflight, limit ceny).

## 5. Dashboard

- Stránka Testování: akce **Spustit testovací kolo** (výběr vrstev,
  volitelně evaluace s odhadem a potvrzením), karta **posledního kola**
  se souhrnem.
- Detail kola: tabulka kroků s odkazy na detail dílčích úloh (stávající
  `VerifyResult`, `E2eResult`, výsledek videa, výsledek evaluace),
  živý průběh, zrušení.
- Texty `src/dev/i18n/` (cs + en), `check:i18n`.

## 6. Drobnosti z F7b (D135)

- `Version::gitInfo()` (nebo místo, odkud ho bere `video-run`) najde
  `git` přes `UpdateEnvironment::findTool` — commit v `meta.json` i pod
  úlohou dashboardu. Unit test s prázdným prostředím.
- Demo sada: název firmy aplikace (nastavení, které aplikace zobrazuje
  v záhlaví) = název firmy z příběhu (`story.jsonc`), odvozený generátorem,
  ne ručně v `settings.jsonc`. Přegenerovat `demo/datasets/it-servis/`
  (`build.py`), bajtová shoda s dumpem dál platí.

## Kroky

1. D135 → commit `fix(video): commit v meta.json i z úlohy dashboardu; demo sada s názvem firmy (#103 D135)`
2. Kolo — orchestrace, vrstva kódu, dílčí úlohy, souhrn, CLI (§ 1, 3, 4)
   + unit testy → commit `feat(dev): testovací kolo — shpd-server test-round (#103 D130, D132–D134)`
3. Evaluace v kole (§ 2) + unit testy → commit `feat(dev): evaluace v testovacím kole s limitem ceny (#103 D131)`
4. Dashboard (§ 5) → commit `feat(dev): testovací kolo ve vývojářském dashboardu (#103 D130, D133)`
5. Ověření: celé kolo bez evaluace z CLI i z dashboardu na dev serveru,
   jedno kolo `--only datasets --dataset cz-non-payer --eval baseline
   --repeat 1 --max-cost 1` **jen po schválení útraty v chatu**;
   dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py` →
   commit `docs: testovací kolo (#103 F8a)`

## Hotovo když

- [ ] `shpd-server test-round` projde všechny tři vrstvy a vydá souhrn;
      exit 0 na čistém stavu.
- [ ] Uměle rozbitý krok (např. dočasně chybný snímek v kopii sady mimo
      repo, nebo neexistující scénář) → kolo pokračuje, souhrn ho označí,
      exit 1.
- [ ] Obsazený zdroj dat → krok `skipped` s důvodem; druhé kolo nejde
      spustit, dokud běží první; zrušení kola zruší dílčí úlohu.
- [ ] `--eval` bez `--max-cost` odmítne; nad limitem kolo nezačne (unit
      test, bez skutečného volání AI).
- [ ] Dashboard: spuštění, živý průběh, detail s odkazy na dílčí úlohy,
      karta posledního kola, zrušení.
- [ ] Kolo z CLI je vidět v dashboardu.
- [ ] D135: commit v `meta.json` videa natočeného z dashboardu; záhlaví
      videa S1 ukazuje název firmy z příběhu.
- [ ] Unit testy nových částí (`--filter`), `npm test`, `npm run build`,
      `check:i18n`, kontroly CSS.
- [ ] `docs/test-corpus.md` nový § 21 „Testovací kolo (F8a)“, `docs/cli.md`
      + řádek v `CLAUDE.md`, `docs/dev/dev-daily.md`; `**Stav:**`, index tasků.

## Pasti

- **Celý PHPUnit běží minuty** — krok kódu musí ukazovat průběh a dát se
  zrušit; při ladění kola stačí `--only datasets`.
- **Evaluace stojí peníze** — v tomto tasku žádné skutečné volání AI bez
  výslovného schválení v chatu (krok 5).
- **Prostředí úlohy je prázdné** (`clear_env`, F7b N7) — všechny kroky
  vrstvy kódu dostávají prostředí složené kolem (`PATH`, `HOME`, `LANG`).
- **Kolo nepřepíná větev ani nedělá `git pull`** — testuje checkout, jak
  je; necommitnuté změny jen zapíše do souhrnu.
- **Reálné kopie a cizí zdroje dat nepoužívat** — kolo pracuje jen se
  zdroji dat sad a videí.

## Nálezy

Rozhodnutí při implementaci (nad rámec D130–D135; podrobně `docs/test-corpus.md` § 21):

- **O1 — vyhrazený zdroj dat kola per sada** (účel přesně `testovací kolo
  — <sada>`, vzor D126): na dev serveru má jedna sada čtyři volné zdroje
  dat (pokus, korpus, evaluace…) a „první vhodný“ by přeseedoval cizí
  experiment. Ostatní zdroje dat sady kolo nikdy nepřeseeduje; chybějící
  založí (`ds-create --no-cron` + seed) a `create` nahradí první `reseed`.
- **Kolo = jeden krok úlohy** (`test-round-run`, orchestrace uvnitř):
  obal `dev-job-run` po prvním selhání zbytek přeskočí, kolo má pokračovat
  (D133). `result.json` píše orchestrátor sám průběžně
  (`JobStore::writeResult`) — `captureStdout` by po SIGKILL nechal prázdný
  soubor; dashboard tak má živou tabulku bez událostí průběhu.
- **CLI spouští úlohu a sleduje ji** (jedna cesta provedení; kolo
  z terminálu je v dashboardu vždy): první Ctrl+C zruší, druhé odpojí,
  `--follow <id>` připojí znovu. Preflight hlídá vlastníka adresáře úloh
  (kolo pod `sudo` by dashboard neviděl ani nezrušil).
- **Pravidlo konfliktů**: `round` je druhá úloha celého serveru, ale
  nevýlučná (`JobRecord::EXCLUSIVE_TYPES = [update]`; kolo blokuje jen
  druhé kolo). Dílčí úlohy nesou `parent`, `JobLauncher::cancel` kaskáduje
  (dítě má vlastní session, signál skupině obalu ho nezasáhne).
- **Zámky úloh dědili potomci** (`fopen` mód `c`): obal dílčí úlohy
  spuštěný z kola by držel `wrapper.lock` kola i po jeho smrti. Od F8a mód
  `ce` (close-on-exec) u `wrapper.lock` i `dev-jobs/.lock`.
- **`ai-eval --estimate` odmítal použitý zdroj dat** (po `verify` má
  doklady), kolo odhad potřebuje před přeseedováním → `EvalPreflight`
  rozdělen na prostředí a čistotu; odhad kontroluje jen prostředí.
- **`CI=1` v prostředí kroků kódu** shodilo `CancellableProcessTest`
  a `VideoRunCommandTest` (hlídají, že skládání prostředí podprocesů
  `CI` nepřidá — PHPUnit ho zdědil z kola). Kolo přidává jen locale
  a `PYTHONUTF8`; `node --test` dává TAP mimo TTY i bez `CI`. Tyto testy
  padají na každém stroji s `CI` v prostředí.
- **Demo sada není ve vrstvě sad** (bez `expected/`); jen ve videích.
- **Vrstva sad = jen `tests/datasets`**; `--dataset` bere i adresář
  s `manifest.jsonc` (kopie sady mimo repo pro umělé rozbití).
- **D135 ověření**: `Version::gitInfo()` / `GitState::describe()` přes
  `Git` (argv, `findTool` v pevném `PATH`); `app.name` doplňuje generátor
  z `own.name` příběhu (ručně zapsaný = chyba příběhu), `app.name` přežije
  přeseedování jinou sadou (dokumentováno).

Ověření 2026-10-27 na dev serveru: `test-round --only code` — první běh
exit 1 (nález `CI=1`), po opravě vrstva kódu celá `ok` (PHPUnit 11 044
testů ~1,5 min); celé kolo `--detach` + sledování v dashboardu založilo
tři zdroje dat kola a prošlo sady i videa (výsledky v souhrnu úlohy);
kolo z dashboardu, zrušení uprostřed kroku kódu i dílčí úlohy — viz
`docs/test-corpus.md` § 21. Evaluace v kole: jen unit testy bez volání AI
(`--only datasets --dataset cz-non-payer --eval baseline --repeat 1
--max-cost 1` čeká na schválení útraty).
