# F7b — videa nad demo sadou ve vývojářském dashboardu

**Stav:** hotovo — implementace, ověření a natočení S1 z CLI i z dashboardu 2026-10-27 (#103 D119–D129); nálezy N1–N7 níže

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
**D124** (`check` v F8). Potvrzená 2026-10-27 a zapsaná v #103:

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

- [x] `shpd-ds video-run s1-prijata-faktura --verb check` doběhne `ok` na
      vyhrazeném zdroji dat demo sady; druhý běh hned po něm taky (sada se
      přeseeduje, mezipaměť se použije) — 2026-10-27 na `360b-n`: první
      běh sestavení 10 s + seed 30 s + průchod 17 s, druhý z mezipaměti.
- [x] `--verb build` vytvoří video; v něm je česky aplikace, datum kotvy
      = dnešek a čas dne 09:30 (kontrola: snímek z videa) — 17 s, 1,8 MiB,
      první karta Energie Jiskra 13. 10. 2026, uživatelka Jana Zelená.
- [x] Z dashboardu jde scénář ověřit, natočit, přehrát (s posouváním —
      206 `Content-Range` i při skoku) a stáhnout; úlohu jde zrušit
      (tlačítko Zrušit v detailu, grace 180 s; zrušení ověřeno unit testy).
- [x] Preflight s cizí adresou odmítne před session; bez Node / ffmpeg /
      závislostí odmítne s přesnou větou; akce v dashboardu neaktivní
      s nápovědou (unit testy `VideoRunCommandTest`, `VideoPreflightTest`,
      `DevVideosControllerTest`; v dashboardu nápověda i u běžící úlohy).
- [x] Token se neobjeví ve výstupu, reportu, logu ani v `ps` (`shpd_st_`
      v `output.log`, `result.json`, `progress.jsonl` úlohy 0×; argv jen
      cesta scénáře a soubor průběhu).
- [x] Galerie a sekce nginx pryč; `spike-dashboard` smazaný.
- [x] `vendor/bin/phpunit` celá sada (10 895 testů), testy video-runneru
      (48), `npm test` (230), `npm run build`, `npm run check:i18n`,
      `check-css-tokens.py`, `check-css-units.py`.
- [x] Dokumentace podle § 5, `**Stav:**`, index tasků.

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

- **N1 — Čerstvý zdroj dat z `ds-create` nemá tabulky.** API odpoví 500
  (`core_system_rate_limits` neexistuje) a preflight `video-run` ho
  odmítne jako nedosažitelný. Akce **Založit zdroj dat** proto má dva
  kroky, `ds-create` a `ds-upgrade` (bez seedu — sadu s kotvou seeduje až
  první běh); z CLI před prvním `video-run` spustit `ds-upgrade`.
- **N2 — `--no-cron` je vidět na videu.** Pruh „Periodické úlohy jsou na
  tomto zdroji dat vypnuté“ nad dashboardem. Zdroj dat pro videa se
  proto zakládá **s cronem** (na rozdíl od evaluačních zdrojů dat); sada
  se stejně před každým během přeseeduje, úlohy cronu nemají co pokazit.
  Existujícímu zdroji dat `shpd-ds ds-state cron --on`.
- **N3 — Název zdroje dat je v záhlaví aplikace.** Sidebar ukazuje
  „Videa — Dem…“ — demo sada nenastavuje `app.name`. Pro video na web má
  příběh nést název firmy (Zelený Terminál s.r.o.) v `setup/settings.jsonc`
  sady (#131, mimo tento task).
- **N4 — Nativní datumová pole jsou v americkém formátu** (`10/12/2026`)
  i v české aplikaci: Chromium formátuje `<input type=date>` a číselná
  pole podle locale **svého procesu** (`LANG` / `LC_ALL`), ne podle
  `locale` kontextu Playwrightu ani `--lang` (ověřeno sondou nad pěti
  variantami). Runner nově spouští Chromium s `LANG` / `LC_ALL`
  z `locale` scénáře (`cs_CZ.UTF-8`); týká se nativních prvků formuláře
  v posledních vteřinách S1.
- **N5 — Doby na dev stroji:** sestavení sady s kotvou 10 s (Gotenberg
  v síti, cache PDF v mezipaměti kotvy), přeseedování 25–36 s (295
  dokladů, 20 výpisů — nepřerušitelné, proto grace úlohy 180 s), ověření
  16–17 s, natáčení 25 s, skládání 8 s. Celé natočení z mezipaměti ≈ 1,5
  minuty.
- **N6 — Kotva = dnešek podle PHP, tedy UTC** (tak server počítá
  splatnosti). Mezi 22:00 a 24:00 UTC je v Praze už další den; datum ve
  videu je přesto shodné s tím, co server považuje za dnešek.
- **N7 — Prostředí úlohy dashboardu je prázdné** (`clear_env` PHP-FPM):
  bez `PATH`, `HOME`, `LANG`. Všechny podprocesy dostávají prostředí
  složené příkazem (pevný `PATH`, `HOME` z `posix_getpwuid`,
  `GOTENBERG_URL` ze `server.json`, `PYTHONUTF8=1`, `VIDEO_HEADFUL=0`);
  `Version::gitInfo()` pod úlohou git nenajde, `commit` v `meta.json`
  je pak `null`.
