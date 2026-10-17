# Testování F6b: spouštění evaluací a evaluační zdroje dat z dashboardu

**Stav:** hotovo — 2026-10-17 (#103 D54–D56, D58–D63), ověřeno z dashboardu na volném zdroji dat vč. evaluace se stropem útraty a zrušení

## Kontext / Cíl

F6a (`tasks/testing-phase6a.md`) dala vývojářský dashboard ve Svelte
a prohlížeč výsledků evaluací. Spouštět se ale dá jen z CLI: přeseedování
zdroje dat, `dataset-verify` i `ai-eval` s odhadem ceny. F6b to přenese
do dashboardu tak, aby to bezpečně mohli používat všichni vývojáři
na svých strojích i na sdíleném vývojovém serveru.

1. **Úlohy na pozadí** (D58) — delší operace běží mimo HTTP požadavek,
   průběh je vidět a přežije zavření prohlížeče.
2. **Evaluační zdroje dat** (D55, D59) — u každé sady je vidět, které
   zdroje dat ji drží a v jakém jsou stavu. Jedním klikem jde přeseedovat
   nebo založit nový.
3. **Spouštění evaluace** (D54, D61) — výběr sady a varianty, odhad ceny,
   potvrzení, strop útraty, průběh. Výsledek se rovnou otevře v prohlížeči.
4. **`dataset-verify`** z dashboardu (D63) a **editor variant** (D62).
5. **Nástroje evaluace** (D56) — cena volání štítků a odhad podle
   skutečného modelu.

Vše jen na zdrojích dat s třídou `free`, hlídá to server (D60).

## Návaznost

- **#103** D54–D56, D58–D63 (oddíl na konci); F6a D51–D53, D57; F4 D34–D41
- **#126** — třída dat (`free` / `realCopy`), `ds-list`, `DataSourceInventory`,
  `MainConfigPatcher`, `origin`; serverové AI jen pro `free`
- `tasks/testing-phase6a.md`, `docs/dev/dev-daily.md` § 1, `docs/test-corpus.md`

## Před implementací přečti

- `src/Api/Controller/DevTestingController.php`, `DevDashboardController.php`
  (`streamCommand`, cesty k `bin/shpd-server` a `bin/shpd-ds`)
- `frontend/src/dev/` (router, `api.js`, `stream.js`, `testing/`)
- `src/Core/Process/DetachedProcess.php` a jeho použití
  (`modules/core/mail/src/Analysis/AnalysisSpawner.php`)
- `src/Command/DataSource/AiEvalCommand.php`, `modules/core/mail/src/Eval/`
  (`EvalPreflight`, `EvalCostEstimator`, `EvalRunner`, `EvalRunStore::datasetHash()`,
  `EvalVariant`)
- `src/Command/DataSource/DatasetSeedCommand.php`, `src/Core/Config/MainConfigPatcher.php`,
  `DataSourceInventory`, `docs/cli.md` → `ds-list`, `ds-class`, `dataset-seed`,
  `dataset-verify`, `ai-eval`
- `modules/core/exchange/src/Enrich/ContentTagClassifier.php`

## 1. Úlohy na pozadí (D58)

- **Spuštění:** `DetachedProcess::spawn()` s argv (žádný shell) a `cwd`
  = adresář zdroje dat. Proces běží pod uživatelem dashboardu.
- **Adresář úlohy** mimo repo (např. `/opt/shipard/run/dev-jobs/<jobId>/`,
  ověř konvenci adresářů serveru):
  - `job.json`: typ, zdroj dat, sada, varianta, parametry, čas spuštění,
    IP klienta, PID, stav;
  - `progress.jsonl` — průběh;
  - `output.log` — stdout a stderr.
- **Stav:** běží (PID žije), doběhla (poslední událost `finished`
  a exit 0), selhala, zrušena. Úloha, jejíž PID zmizel bez `finished`,
  je selhaná.
- **Zrušení:** SIGTERM skupině procesů (`setsid` dává vlastní session).
  `ai-eval` na SIGTERM nezačne další volání, uloží rozpracovaný výsledek
  jako zrušený běh, nebo nic, a skončí.
- **Zámek:** na jednom zdroji dat běží nejvýš jedna úloha. Druhou API
  odmítne (409) s odkazem na běžící.
- **Seznam úloh:** posledních N dní, filtr podle sady a zdroje dat,
  odkaz na výsledek (běh evaluace v prohlížeči, výstup `dataset-verify`).
  Starší úlohy se uklidí (konstanta dní).
- **API:** `GET /_dev/api/testing/jobs`, `GET …/jobs/{id}` (stav
  a průběh od offsetu, pro polling), `POST …/jobs/{id}/cancel`.
  Spouštění úloh je u jednotlivých akcí níže.

Typy úloh: `eval`, `verify`, `reseed`, `create` (založení evaluačního
zdroje dat).

## 2. Která sada je ve zdroji dat (D59)

- `dataset-seed` zapíše do `config/main.json` pole
  `dataset: {name, hash, seededAt}`. `hash` počítá
  `EvalRunStore::datasetHash()`, tedy stejně jako hash sady v souboru
  výsledků. Pole se zapisuje nezávisle na třídě dat a nemění `origin`.
- `DataSourceInventory` a `ds-list --json` pole přečtou a vypíšou.
- **Stav zdroje dat vůči sadě** (API, v přehledu sady):
  - **sada:** aktuální (hash = hash sady v pracovním stromu) / zastaralá;
  - **použití:** čistý (žádný použitý doklad ani pravidlo IČO → štítek;
    logika `EvalPreflight`) / s použitými doklady;
  - **třída** (`free` / `realCopy`), **serverové AI**, **běžící úloha**.
- Zdroje dat seedované před F6b pole nemají. Přehled je pozná podle
  `origin` `dataset-seed <sada>` a ukáže je s poznámkou „přeseedovat“.

## 3. Pojistka na straně serveru (D60)

Každá spouštěcí akce API (eval, verify, reseed) ověří na serveru:
režim `development`, cílový zdroj dat má třídu **`free`** (podle
`DataSourceInventory`) a sada je ze seznamu sad. Jinak vrátí 403 a nic
nespustí. UI tlačítka u reálných kopií neukáže, ale ochranu dělá API.
Test: pokus o reseed, verify i eval na zdroji dat `realCopy` → 403.

## 4. Evaluační zdroje dat (D55)

Obrazovka sady dostane panel „Zdroje dat“ se seznamem zdrojů dat, které
sadu drží (§ 2). Akce:

- **Přeseedovat** (`reseed`): potvrzení v UI → `dataset-seed <sada> --yes`.
  Výsledek: čistý zdroj dat s aktuální sadou.
- **Ověřit zaúčtování** (`verify`, § 6).
- **Spustit evaluaci** (§ 5) — jen u čistého zdroje dat s aktuální sadou.
  U ostatních je tlačítko neaktivní a nese důvod.
- **Založit evaluační zdroj dat** (`create`): `ds-create --no-cron`
  s názvem „Evaluace — <titulek sady>“ a účelem „evaluace AI — <sada>“,
  pak `dataset-seed`. Nový zdroj dat je `free` a má serverové AI.

## 5. Spouštění evaluace (D54, D61)

- **Formulář:** sada (z panelu), zdroj dat (čistý, aktuální), varianta
  (soubory `tests/eval/variants/`), `--repeat` (1–5), volitelně `--only`.
- **Odhad:** `ai-eval … --estimate --json` (nová volba: preflight
  a odhad bez běhu, nic nespustí ani nezapíše). Výsledek: počet volání,
  model extrakce a štítků, odhad ceny a jeho zdroj. UI ho ukáže
  a předvyplní **strop útraty 1,5 × odhad**.
- **Potvrzení** → úloha `eval`:
  `ai-eval … --yes --max-cost <USD> --progress-file <úloha>/progress.jsonl`.
- **`--max-cost`** (nová volba): po každém volání se sečte útrata
  a jakmile dosáhne stropu, další volání se nezačne. Běh se uloží jako
  neúplný (`stoppedBy: maxCost`) a ve výpisu i v prohlížeči je to vidět.
- **`--progress-file`** (nová volba): JSON řádky `started` (počet volání,
  odhad), `call` (zpráva, opakování, stav, cena, čas), `finished`
  (soubor výsledků, útrata) nebo `error`.
- **Průběh v UI:** počet hotových volání a z kolika, útrata vs. strop,
  poslední dokončené doklady, zrušení. Po dokončení odkaz do detailu běhu.
- Výsledky se zapisují do `tests/eval/<sada>/` v pracovním stromu
  (D52) a commit dělá člověk.

## 6. `dataset-verify` z dashboardu (D63)

- Úloha `verify`: `dataset-verify <sada> --json` (s apply).
  Volitelně `--no-apply`.
- Výsledek se ukáže jako tabulka kontrol (stav, rozdíly, přeskočené
  doklady s důvodem). Ukládá se jen do adresáře úlohy, ne do repa.
- `--write-ledger-snapshot` a schvalování snímků zůstává v CLI
  (lidský krok, #103 D29).

## 7. Editor variant (D62)

- Seznam variant a formulář:
  - název, popis;
  - profil pošty;
  - šablona promptu ze souboru (výběr z repa, volitelné);
  - přepis volání: připojení, model z katalogu (jen modely dostupné
    na platformě serverového připojení), `thinking`, `effort`, strop tokenů;
  - přepis funkce štítků: model, `thinking`, `effort`.
- Uloží `tests/eval/variants/<název>.jsonc` (formát
  `shpd.eval.variant.v1`, ověří ho stávající načítač). Přepsání existující
  varianty jen s potvrzením. Název `[a-z0-9-]+`.
- API: `GET …/variants`, `GET …/variants/{název}`, `PUT …/variants/{název}`.

## 8. Nástroje evaluace (D56)

- **Cena štítků:** `ContentTagClassifier` vrátí vedle výsledku i spotřebu
  volání (tokeny, cena, model). `AnalysisComputation` a `ai-eval` ji
  zapíšou do volání v souboru výsledků zvlášť (`tags: {tokensIn,
  tokensOut, costUsd, model}`). Celková cena dokladu = extrakce + štítky.
  Prohlížeč (F6a) ukáže obojí. Starší běhy pole nemají.
- **Odhad ceny:** pořadí zdrojů je
  1. poslední běh nad sadou se **stejným skutečným modelem** extrakce,
  2. běh s modelem téže řady,
  3. typická velikost dokladu.

  Zdroj odhadu je ve výstupu `--estimate`.

## Kroky

1. `dataset-seed` → pole `dataset` v `main.json`; `DataSourceInventory`
   a `ds-list` ho vypíšou.
2. `ai-eval`: `--estimate`, `--max-cost`, `--progress-file`, chování
   na SIGTERM; cena štítků; odhad podle skutečného modelu. Unit testy.
3. Úlohy na pozadí (služba + API) + pojistka D60 + testy (zámek, 403
   na `realCopy`, stav po zmizelém PID, zrušení).
4. API evaluačních zdrojů dat (stav vůči sadě) a akce reseed, create, verify.
5. API a UI editoru variant.
6. UI: panel Zdroje dat, formulář evaluace s odhadem a stropem, průběh,
   seznam úloh, výsledek `verify`.
7. Ověření (níže), dokumentace, hlavička tasku.

## Hotovo když

- [x] `ds-list --json` ukazuje `dataset` u zdrojů dat seedovaných po změně
- [x] `ai-eval --estimate --json` nic nespustí; `--max-cost` zastaví běh
      po dosažení stropu (unit test s falešným LLM); `--progress-file` píše
      události; SIGTERM ukončí běh bez dalšího volání
- [x] API odmítne reseed, verify a eval na `realCopy` (403) i mimo `development`;
      druhá úloha na tomtéž zdroji dat 409
- [x] z dashboardu na volném zdroji dat: založení evaluačního zdroje dat,
      přeseedování, `dataset-verify` s tabulkou kontrol, evaluace `baseline`
      `--only` 2 dokladů (odhad, potvrzení, průběh, výsledek v prohlížeči),
      zrušení běžící evaluace. **Útrata ověřovacích běhů až po schválení
      v chatu** (orientačně pod 0,5 USD).
- [x] editor variant: nová varianta uložená a použitelná v `ai-eval`
- [x] soubor výsledků nese cenu štítků; prohlížeč ji ukáže
- [x] `docs/dev/dev-daily.md` § 1 (spouštění, úlohy, pojistky),
      `docs/test-corpus.md` (`--estimate`, `--max-cost`, `--progress-file`,
      cena štítků, odhad), `docs/cli.md` (`ai-eval`, `dataset-seed`, `ds-list`)
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Implementace 2026-10-16 — co je jinak než v zadání

- **Obal úlohy** `shpd-server dev-job-run <adresář>` (skrytý): `DetachedProcess`
  přes `setsid -f` PID nevrací, proto API spawnuje obal, který kroky pouští
  `proc_open` s argv, drží `flock` na `wrapper.lock` (živost místo hádání PID)
  a píše `state.json`; `job.json` píše jen API (žádný souběh zápisů). Stav se
  odvozuje při čtení (`starting` → po 60 s `failed`, `running` + volný zámek
  → `failed`, značka `cancel.json` → `cancelling`). Pod php-fpm není `pcntl`,
  signály jako čísla (`15` / `9`).
- **Zrušení**: skupinový SIGTERM; `ai-eval` předává do `AnalysisRequest`
  hook `beforeAttempt`, který po zrušení zastaví i opakování po přechodné
  chybě (SIGTERM přeruší `sleep()` v `LlmRetry`); běžící HTTP stream
  doběhne, proto grace zrušení evaluace 900 s, ostatní 60 s, k tomu
  `force=1` = SIGKILL. Zrušený běh = exit 130, soubor jen s ≥ 1 hotovým
  voláním; soubor nese `plannedCalls` a `stoppedBy`.
- **Přeseedování jiného volného zdroje dat** (schváleno v chatu): panel
  nabízí volné zdroje dat bez pole `dataset` (a bez původu z jiné sady),
  protože `ds-class set free` přepsalo `origin` a záložní rozpoznání podle
  `dataset-seed <sada>` na dev stroji nic nenajde.
- **Založení bez uživatele** (schváleno v chatu): `create` = `ds-create
  --no-cron` (+ `--language cs --country <země sady> --module install.base
  --ds-id <předgenerované>`) a `dataset-seed`; do aplikace přes
  `auth-emergency-login`. Žádné heslo v argv ani v `job.json`.
- **Pole `dataset`** se v `main.json` maže na začátku resetu a zapisuje až
  po úspěšném seedu; `--no-reset` ho odebere (zdroj dat už není čistá kopie
  jedné sady).
- **Cena štítků**: `metrics.cost.usd` zůstává jen extrakce; nové `tags.*`
  a `cost.totalUsd` / `cost.totalPerDocument`; volání nese `tags`
  (`null` = LLM se nevolalo).
- **Odhad** `basis` `sameModel` (nejnovější běh nad sadou se stejným
  `effective.catalogModel`, bez ohledu na variantu a hash; cena/volání vč.
  `tags.usd`, u starších běhů typická cena štítků) → `series` → `catalog`.
- **Odhad z API** = synchronní podproces `ai-eval --estimate --json`
  (`CapturedProcess`, 60 s); s `--json` jde při odmítnutí na stdout
  i `{ok: false, problems}`.
- **URL**: `/_dev/api/testing/jobs…` (`DevJobsController`)
  a `/_dev/api/testing/eval/…` (`DevEvalController`); routy SPA
  `testing/jobs…`, `testing/variants…` před `testing/:set` — sada se nesmí
  jmenovat `jobs` ani `variants`.
- **Editor variant**: identita = název souboru bez `.jsonc`; nová varianta
  `[a-z0-9][a-z0-9-]{0,63}`; nabídky (připojení, modely platformy
  serverového připojení, profily) z vybraného volného zdroje dat
  (`GET …/variants/options?dataSource=`). Dialogy dev SPA jsou lokální
  (`DevDialog` / `DevConfirm`), ne sdílený `Modal`.
- `EvalException` už není `final` (`EvalVariantExistsException`,
  `EvalCancelledException`).

## Ověřeno 2026-10-16/17 na dev stroji

Přes API dashboardu na novém volném zdroji dat `wb85-i…` (založený akcí
**Založit evaluační zdroj dat**, 32 s): panel ho ukázal jako aktuální a čistý;
`dataset-verify` s apply (5 kontrol ok, 14 dokladů) → blockers „použitý zdroj
dat“ a `canEval: false`; druhá úloha na tomtéž zdroji dat 409 `JOB_RUNNING`
s odkazem; zrušení běžícího přeseedování (`termSignal 15`) a plné přeseedování
→ znovu čistý, `ds-list --json` nese `dataset`. Evaluace `baseline --only
0101,0201`: odhad 0,1025 USD (`sameModel` podle posledního běhu Sonnet 5.5),
strop 0,16 USD, 2 volání za 0,0912 USD vč. štítků (0,0029 / 0,0032 USD),
soubor `tests/eval/cz-vat-payer/2026-10-17-baseline.json`. Zrušení druhé
evaluace během prvního volání: volání doběhlo (13,6 s), druhé nezačalo,
běh uložený se `stoppedBy: cancelled` (1 z 2), exit 130, úloha `cancelled`.
Celková útrata ověření 0,14 USD. Oba soubory běhů s výběrem zůstávají
v pracovním stromu necommitnuté (D52 — commit dělá člověk; neúplný
`2026-10-17-baseline-2.json` lze smazat). Headless smoke stránek úloh,
panelu, dialogu a detailů bez chyb; unit testy 9985 PHP + 192 node.

## Pasti

- **Reálné kopie.** Pojistka D60 je na serveru, ne v UI. Žádný parametr
  ji neobchází.
- **Útrata.** Strop `--max-cost` je povinný parametr úlohy `eval`
  z dashboardu, UI ho nesmí vynechat.
- **Shell.** Argv pole, žádné skládání příkazové řádky z parametrů požadavku.
- **Pracovní strom.** Dashboard zapisuje jen do `tests/eval/` (výsledky,
  varianty), nic jiného v repu nemění.

## Rozhodnutí k designu (potvrzená)

- ✓ **D54 — Spouštění evaluace z dashboardu:** sada, varianta, opakování,
  odhad, potvrzení, průběh na pozadí, zrušení, výsledek v prohlížeči.
- ✓ **D55 — Evaluační zdroje dat per sada:** přehled zdrojů dat se sadou
  a jejich stav, přeseedování, založení, `dataset-verify`.
- ✓ **D56 — Nástroje evaluace:** cena volání štítků ve výsledcích, odhad
  podle skutečného modelu, pak téže řady.
- ✓ **D58 — Úlohy na pozadí** přes `DetachedProcess` s adresářem úlohy
  mimo repo; jedna úloha na zdroj dat; seznam úloh s časem a IP klienta.
- ✓ **D59 — `dataset-seed` zapíše do `main.json` pole `dataset`**
  (`name`, `hash`, `seededAt`) nezávisle na třídě dat; `ds-list` ho vypíše.
- ✓ **D60 — Spouštěcí akce jen na `free`** a v režimu `development`,
  ověřuje API.
- ✓ **D61 — Odhad bez běhu (`--estimate`) a strop útraty (`--max-cost`)**,
  z dashboardu předvyplněný na 1,5 × odhad.
- ✓ **D62 — Editor variant** ukládá soubor do `tests/eval/variants/`.
- ✓ **D63 — `dataset-verify` z dashboardu** s výsledkem v UI, mimo repo;
  schvalování snímků zůstává v CLI.
