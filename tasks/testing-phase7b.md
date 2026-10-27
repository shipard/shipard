# F7b — videa nad demo sadou ve vývojářském dashboardu

**Stav:** naplánováno — zadání 2026-10-27 (#103 D119–D124, D125–D129 k potvrzení)

## Kontext / Cíl

Video-runner (`tools/video-runner/`, #48) umí ze scénáře natočit video,
ale README uvádí známé dluhy: scénáře běží nad tím, co na instanci zrovna
je (S1 počítá s ručně připravenou kartou), „dnes“ je skutečné dnes,
a i `check` zprávu spotřebuje — každý záběr potřebuje čerstvá data.
Spouští se jen z CLI, hotová videa servíruje galerie přes nginx.

**Cíl:** scénář nese datovou sadu; jedním příkazem (a jednou akcí ve
vývojářském dashboardu) se demo sada sestaví s kotvou = dnešek, naseeduje
do vyhrazeného volného zdroje dat a scénář se ověří (`check`) nebo natočí
(`build`). Videa se prohlížejí a stahují v dashboardu; galerie přes nginx
se ruší.

Z F7a se znovu použije: přeseedování před během, krátkodobá session bez
hesla (`E2eSession`, D92), lokální adresa s připíchnutím (`LocalBaseUrl`,
`PinnedBaseUrl`, D97), úlohy dashboardu a vzor jednoho příkazu s
`--progress-file` (D91).

## Návaznost

- **#103** D119–D124 (komentář 2026-10-27), D91, D92, D97, D55 (zdroj dat
  sady v dashboardu)
- **#48** — video-runner, D11–D14 (testidy), D15–D17 (D15 zrušeno, D16
  nahrazeno, D17 splněno — komentář 2026-10-27)
- **#131** — demo sada „IT servis“, D7 (kotva)
- `tasks/testing-phase7a.md`, `tasks/testing-phase7a-fixes.md` (stavební
  kameny), `tasks/video-publish.md` (galerie, která se ruší)

## Před implementací přečti

- `tools/video-runner/README.md`, `INSTALL.md`, `src/config.mjs`,
  `src/scenario.mjs` (`locale`, `timezone` už existují), `src/runner.mjs`
  (kontext, `storageState`), `src/browser.mjs` (spuštění Chromia),
  `src/verbs/check.mjs`, `build.mjs`, `compose.mjs`, `src/gallery.mjs`
- `demo/scenarios/s1-prijata-faktura.jsonc`, `spike-dashboard.jsonc`
- `demo/datasets/README.md` (kotva, `--anchor`, `--out`), `demo/datasets/build.py`
- `src/Command/DataSource/DatasetE2eCommand.php` a
  `modules/core/exchange/src/Dataset/E2e/*` (preflight, session, adresa,
  průběh, report, úklid při SIGTERM)
- `src/Api/Controller/DevEvalController.php` (akce `e2e`, zdroj dat sady),
  `src/Core/DevJobs/*` (`JobRecord::TYPES`, `JobLauncher`)
- `frontend/src/dev/pages/testing/*` (panel sady, `E2eResult.svelte`,
  `JobDetailPage.svelte`), navigace dashboardu
- `docs/frontend.md` § 9 (testidy), `docs/test-corpus.md` § 19

## Rozhodnutí

Zamčená v #103: **D119** (scénář nad sadou), **D120** (kotva = dnešek,
pevný čas dne v prohlížeči), **D121** (jazyk ze scénáře), **D122** (videa
v dashboardu, galerie přes nginx zrušena), **D123** (videa mimo repo),
**D124** (`check` v F8). K potvrzení v tomto PRD:

- **D125 — Jeden příkaz `shpd-ds video-run`,** úloha dashboardu je jeho
  obal (vzor D91): preflight → sestavení sady s kotvou → přeseedování →
  session → video-runner → úklid → report.
- **D126 — Vyhrazený zdroj dat pro videa per sada** (účel „videa — <sada>“),
  dashboard ho najde nebo založí stejně jako zdroj dat sady pro evaluace
  (D55). Jen třída `free`.
- **D127 — Úložiště videí na serveru** vedle adresáře úloh dashboardu
  (`…/videos/<id scénáře>/`): hotové `<id>.mp4`, `meta.json` (sada, kotva,
  commit, čas, délka, velikost) a výsledek posledního ověření. Jedna verze
  na scénář — nové natočení přepíše předchozí. Pracovní soubory
  (`raw.mp4`, `timeline.json`) v podadresáři, přepisují se.
- **D128 — Sestavená sada s kotvou se drží v mezipaměti** per sada a datum
  kotvy (dočasný adresář na serveru); druhé natáčení téhož dne sadu znovu
  nestaví. Starší sestavení se mažou.
- **D129 — Pilotní scénář `spike-dashboard` se ruší** (podle vlastní
  hlavičky „obsah je jedno“), spolu s galerií (`gallery.mjs`, sekce nginx
  v `INSTALL.md`, `video-publish` jako historie).

## 1. Video-runner

- **Pevný čas dne (D120):** runner přijme datum a čas (proměnná prostředí,
  např. `VIDEO_FIXED_TIME`, ISO s časovou zónou scénáře) a před prvním
  krokem nastaví `page.clock.setFixedTime`. Bez proměnné se chová jako
  dnes. Výchozí čas dne pro `video-run`: 09:30 v časové zóně scénáře;
  scénář ho smí přebít polem (např. `clockTime`).
- **Jazyk (D121):** ověřit, že `locale` a `timezone` scénáře (už existují)
  opravdu řídí jazyk aplikace — výchozí `cs-CZ`, `Europe/Prague`;
  `Accept-Language` podle `locale`.
- **Připíchnutá adresa (D97):** Chromium runneru dostane mapování hostitele
  (`--host-resolver-rules`) z proměnné prostředí, stejně jako proklikání.
- **Pole `dataset`** validátor scénáře vyžaduje (název sady ze seznamu sad
  v checkoutu); runner sám ho nepoužívá — čte ho `video-run`.
- **Galerie pryč (D129):** `gallery.mjs`, volání v `compose`, testy,
  sekce v `INSTALL.md`. `VIDEO_OUT_DIR` zůstává (nastaví ho `video-run`).
- Verb `login` a `.env` zůstávají pro ruční použití runneru mimo Shipard
  (runner o Shipardu neví nic) — README to řekne.
- README: aktualizovat „Známé dluhy“ (sady, čas, jazyk vyřešené).

## 2. Příkaz `shpd-ds video-run`

`shpd-ds video-run <scénář> [--verb check|build] [--anchor YYYY-MM-DD]
[--base-url <url>] [--json] [--progress-file <soubor>]`

- `<scénář>` = id nebo cesta v `demo/scenarios/`. Výchozí verb `check`.
  `--anchor` výchozí dnešek (pro ladění jiný den).
- **Preflight:** režim `development`, třída `free`, `LocalBaseUrl`
  (D97, odmítnutí před session), scénář platný a jeho `dataset` existuje,
  `node`, `npx`, `ffmpeg` a `python3` přes `UpdateEnvironment::findTool`,
  `tools/video-runner/node_modules` a prohlížeč. Chybějící věc → odmítnutí
  s přesnou větou, co spustit (podle `INSTALL.md`).
- **Kroky:** sestavení sady s kotvou do mezipaměti (D128) →
  `dataset-seed` z ní → `E2eSession` (uživatel, session, `storageState`
  `0600`) → `node bin/video-runner.mjs <verb> <scénář>` jako podproces
  s `SHPD_BASE_URL`, `SHPD_STORAGE_STATE`, `VIDEO_WORK_DIR`,
  `VIDEO_OUT_DIR`, `VIDEO_FIXED_TIME`, mapováním hostitele → zneplatnění
  session a smazání `storageState` (i při chybě a SIGTERM) → `meta.json`
  a report.
- **Report** (`--json` i čitelně): verb, scénář, sada, kotva, výsledek
  (`ok` / `failed` s krokem a selektorem z runneru), u `build` cesta,
  délka a velikost videa. Exit 0 / 1 (scénář nesedí) / 2 (preflight).
- **Průběh** (`--progress-file`): `started` / `step` (sestavení sady,
  přeseedování, ověření / natáčení, skládání) / `finished` / `error` —
  tvar jako u `dataset-e2e`.
- Token se nikdy neobjeví ve výstupu, reportu, logu ani v argumentech
  (test, jako u `dataset-e2e`).

Unit testy: preflight, sestavení příkazu a prostředí podprocesu,
mezipaměť sady (stejný den znovu nestaví, starší maže), report, úklid
session při výjimce.

## 3. Dashboard — oddíl Videa

- Nová stránka **Videa** v navigaci dashboardu: scénáře z `demo/scenarios/`
  (id, sada, popis z hlavičky scénáře), stav posledního ověření a natočení
  (z `meta.json` a výsledku ověření), přehrávač hotového videa a stažení.
- Akce **Ověřit** a **Natočit** = nový typ úlohy `video` (`JobRecord`),
  jeden krok `video-run … --json --progress-file …`, zrušitelná; zdroj dat
  pro videa sady (D126) — najít, nebo nabídnout založení. Jedna aktivní
  úloha na zdroj dat platí.
- **Servírování videa:** read-only endpoint nad úložištěm (D127), jen
  soubory, které `meta.json` jmenuje, s podporou HTTP `Range` (přehrávač
  bez ní neposouvá). Jen `development`, přes `DevActionGuard`.
- Akce neaktivní s nápovědou, když preflight hlásí chybějící nástroj.
- Texty v `src/dev/i18n/` (cs + en), testidy podle konvence dashboardu.

## 4. Scénáře

- **S1** (`s1-prijata-faktura.jsonc`): `dataset: "it-servis"`, smazat
  blok „PŘEDPOKLADY DAT“ a nahradit jednou větou, co scénář od sady čeká.
  Ověřit, že první karta na dashboardu demo sady je návrh přijaté faktury
  se všemi referencemi spárovanými; když ne, upravit scénář (konkrétní
  karta místo `.first()` — testid podle D11), ne sadu.
- `spike-dashboard.jsonc` smazat (D129).

## 5. Dokumentace

- `tools/video-runner/README.md` a `INSTALL.md` (bez nginx, prostředí
  pro `video-run`).
- `docs/cli.md` + řádek v `CLAUDE.md` → `shpd-ds`: `video-run`.
- `docs/test-corpus.md`: nový § 20 „Videa (F7b)“ — D119–D129, příprava,
  jak číst výsledek, pasti.
- `docs/dev/dev-daily.md`: videa z dashboardu i z CLI.
- `tasks/video-publish.md`: poznámka nahoře, že galerie je zrušená (#103 D122).

## Kroky

1. Video-runner (§ 1) → commit `feat(video): pevný čas dne, připíchnutá adresa, dataset ve scénáři; galerie zrušena (#103 D120–D122, D129)`
2. `shpd-ds video-run` + unit testy (§ 2) → commit `feat(dataset): video-run — demo sada s kotvou, přeseedování, session, check / build (#103 D119, D120, D125, D127, D128)`
3. Dashboard (§ 3) → commit `feat(dev): videa ve vývojářském dashboardu — ověřit, natočit, přehrát (#103 D122, D126)`
4. Scénáře (§ 4), ověření a natočení S1 z dashboardu → commit `feat(demo): S1 nad demo sadou IT servis, pilotní scénář zrušen (#103 D119, D129)`
5. Dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py` → commit `docs: videa nad demo sadou (#103 F7b)`

## Hotovo když

- [ ] `shpd-ds video-run s1-prijata-faktura --verb check` doběhne `ok` na
      vyhrazeném zdroji dat demo sady; druhý běh hned po něm taky (sada se
      přeseeduje, mezipaměť se použije).
- [ ] `--verb build` vytvoří video; v něm je česky aplikace, datum kotvy
      = dnešek a čas dne 09:30 (kontrola: snímek z videa).
- [ ] Z dashboardu jde scénář ověřit, natočit, přehrát (s posouváním)
      a stáhnout; úlohu jde zrušit.
- [ ] Preflight s cizí adresou odmítne před session; bez Node / ffmpeg /
      závislostí odmítne s přesnou větou; akce v dashboardu neaktivní
      s nápovědou.
- [ ] Token se neobjeví ve výstupu, reportu, logu ani v `ps`.
- [ ] Galerie a sekce nginx pryč; `spike-dashboard` smazaný.
- [ ] `vendor/bin/phpunit --filter` na nové a dotčené testy, testy
      video-runneru, `npm test` a `npm run build` ve `frontend/`,
      `npm run check:i18n`, `python3 scripts/check-css-tokens.py`.
- [ ] Dokumentace podle § 5, `**Stav:**`, index tasků.

## Pasti

- **Evaluační zdroje dat a zdroje „korpus“ nepoužívat** — videa mají
  vlastní zdroj dat (D126).
- **Sestavení demo sady s kotvou trvá** (PDF desítek dokladů) — proto
  mezipaměť; první běh dne počítá s minutami, průběh to musí ukázat.
- **Kotva přes hranici fiskálního roku** (`demo/datasets/README.md`) —
  seed musí založit fiskální roky, do kterých sada spadá (D23); ověřit
  na kotvě v lednu (`--anchor 2027-01-20`).
- **`page.clock.setFixedTime` zmrazí jen `Date` v prohlížeči** — server
  počítá „dnes“ sám; proto kotva = skutečný dnešek, jinak se splatnosti
  ve videu rozejdou s tím, co spočítá server.
- **Prohlížeč a ffmpeg patří uživateli, pod kterým běží úloha** (jako
  u proklikání) — preflight to musí rozpoznat.

## Nálezy

_(doplní implementace)_
