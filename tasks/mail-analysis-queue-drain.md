# Došlá pošta — runner AI analýzy dobírá frontu, strop pro pády (#85 D25, D26)

**Stav:** naplánováno — rozhodnutí D25, D26 potvrzena 2026-10-09 (#85)

## Status / cíl

`tasks/mail-analysis-inprocess.md` přenesl analýzu pošty do `shpd`.
Ověření ukázalo dvě mezery v zadání:

1. **Fronta se bez cronu nehýbe.** Runner bez volného slotu skončí
   a zprávu má dohledat `mail-analyze --sweep` z minutového cronu. Na
   stroji bez cronu (dnes všechny vývojářské) zůstane po nahrání pěti
   dokladů naráz tři „Ve frontě“ napořád. I s cronem se fronta odbavuje
   nejvýš `maxConcurrent` zprávami za minutu, i když analýza trvá kolem
   půl minuty.
2. **Pád runneru nemá strop.** Nečekaná výjimka nechá claim vypršet
   (15 minut), reaper zprávu vrátí do fronty a kolo se opakuje bez
   omezení. Pád po volání modelu stojí v každém kole peníze.

Cíl: runner po dokončení zprávy **pokračuje další zprávou z fronty**
a opakované pády končí ve stavu „Analýza selhala“ stejně jako opakované
chyby modelu.

GitHub Issue: shipard/shipard#85 (D25, D26).

## Návaznost

- `tasks/mail-analysis-inprocess.md` — runner, sloty, sweep, strop tří
  selhání za hodinu (D14, D15, D18). **Předpoklad.**
- `tasks/mail-phase3a.md` §3 — reaper expirovaných claimů.
- `tasks/mail-analysis-error-messages.md` — katalog hlášek selhané analýzy.

## Před implementací přečti

- `modules/core/mail/docs/ai-analysis.md` — sekce „Analýza v procesu“.
- `modules/core/mail/src/Analysis/AnalysisRunner.php` — `run()`,
  `runInSlot()`, `analyze()`, `fail()`, `sweep()`.
- `modules/core/mail/src/Analysis/AnalysisQueue.php`,
  `AnalysisSpawner.php`, `AnalysisSlots.php`,
  `AnalysisResultWriter.php` (`storeFailure()`, `countRecentFailures()`).
- `modules/core/mail/src/AnalysisClaimReaper.php`.
- `src/Command/DataSource/MailAnalyzeCommand.php`.
- `modules/core/mail/src/AnalysisErrorPresenter.php` a cfgItem
  `core.mail.analysisErrorKinds`.

## Rozhodnutí k designu (potvrzená)

- ✓ **D25 — Runner dobírá frontu.** `mail-analyze --message <id>` po
  dokončení zprávy (s jakýmkoli výsledkem) drží slot a bere další zprávu
  z fronty, nejstarší první, dokud nějaká je. Pravidla:
  - **Každou zprávu nejvýš jednou za proces.** Zpráva, která po běhu
    zůstala ve frontě (přechodná chyba, neúspěšný claim), se v témže
    procesu znovu nebere — jinak by se runner točil na místě.
  - **`not_configured` dobírání ukončí** — chybí profil, backend nebo
    klíč, další zprávy by dopadly stejně.
  - **Rozpočet 10 minut** od startu procesu: po jeho vypršení runner
    novou zprávu nezačne. Když ve frontě ještě něco zbývá, uvolní slot
    a spustí nástupce (`AnalysisSpawner`). Důvod: dlouhá fronta nesmí
    držet slot jedním procesem donekonečna (ostatní zdroje dat na
    serveru, nový kód po upgradu).
  - `mail-analyze --sweep` zůstává jako záchrana (zprávy vrácené do
    fronty, vypršelé claimy, stroj po restartu).
- ✓ **D26 — Strop i pro pády.**
  - Výjimka zachycená v runneru po claimu se zapíše jako selhání
    (`ai_error`, zpráva `internal: <třída>: <text>`) se stejným stropem
    jako přechodné chyby modelu: do fronty nejvýš třikrát za hodinu, pak
    stav 70. Když selže i zápis selhání, claim se nechá vypršet (dnešní
    chování).
  - Reaper: když zprávě za poslední hodinu vypršel claim potřetí,
    nevrací ji do fronty, ale přepne na stav 70 a zapíše selhaný běh
    (`ai_error`, „analysis did not finish 3 times within an hour (claim
    expired)“). Kryje pády, které PHP nezachytí (paměť, zabitý proces).
  - Oba stropy počítají totéž okno jako D18 (`MAX_FAILURES_PER_HOUR`,
    `FAILURE_WINDOW_SECONDS`) — konstanty na jednom místě.

## Scope

### V rozsahu

- Dobírání fronty v runneru a nástupce po vypršení rozpočtu (D25).
- Strop pro zachycené pády a pro vypršelé claimy (D26).
- **Chyby nastavení:** odpovědi modelu 401, 403 (klíč, oprávnění) a 404
  (`not_found_error` — neznámý nebo vyřazený model) se hlásí jako
  `config_error` bez opakování, ne jako `ai_error`.
- **Hláška `configError`:** text v `core.mail.analysisErrorKinds`
  (cs, en) dnes mluví o „spojení mezi analyzerem a Shipardem“. Nově:
  AI není správně nastavená (klíč, model nebo vyčerpaný limit útraty)
  — řeší správce; opakování nepomůže, dokud se nastavení neopraví.
- Dokumentace: `modules/core/mail/docs/ai-analysis.md` (dobírání,
  rozpočet, stropy), `docs/dev/local-dev.md` (věta o varování sweepu
  „jednou za minutu“ platí jen na stroji s cronem — přeformulovat),
  `docs/cli.md` (`mail-analyze`).

### Mimo rozsah

- Cron na vývojářských strojích — samostatný úkol.
- Spravedlivé rozdělení slotů mezi zdroje dat na sdíleném serveru (dnes:
  kdo dřív přijde; rozpočet z D25 jen brání trvalému obsazení).
- Zrušení pull protokolu — `tasks/ai-analyzer-removal.md`.

## Kroky

### 1. Dobírání fronty (D25)

- `AnalysisRunner::drain(int $firstMessageId): array` — vnější smyčka nad
  `runInSlot()`: slot se získá jednou, uvolní na konci. Výsledek: seznam
  výsledků jednotlivých zpráv + důvod ukončení (`queue_empty`,
  `not_configured`, `budget`, `no_slot`, `disabled`).
- Další zpráva: `AnalysisQueue::eligible()` s vynecháním už zpracovaných
  id (nový volitelný parametr `array $excludeIds`).
- Rozpočet: konstanta `DRAIN_BUDGET_SECONDS = 600`; čas injektovatelný
  kvůli testům. Nástupce: po uvolnění slotu `spawn` první zbývající
  zprávy — jen když proces aspoň jednu zprávu dokončil (stav `done`
  nebo `failed`).
- `MailAnalyzeCommand --message` volá `drain()`; výpis po zprávách
  a souhrnný řádek. Návratové kódy beze změny.
- `sweep()` beze změny (spouští nejvýš tolik runnerů, kolik je volných
  slotů; každý pak dobírá).

### 2. Strop pro pády (D26)

- `AnalysisRunner::run()` / `drain()`: `\Throwable` z `analyze()` →
  `fail(…, 'ai_error', 'internal: …', $retryable)` se stropem; teprve
  když selže i to, dnešní větev „claim left to expire“. Pád jedné zprávy
  dobírání nepřeruší.
- `AnalysisClaimReaper::reapExpired()`: před návratem zprávy do fronty
  spočítat její claimy s `release_reason = expired` za poslední hodinu
  (včetně právě uvolněného). Při třetím → `analysis_state = 70` a řádek
  v `core_mail_message_analyses` (status 3, `error_type`, `error_message`,
  `created_by = NULL`); jinak jako dnes. Zápis selhaného běhu sdílet
  s `AnalysisResultWriter` (žádná druhá kopie INSERTu).
- Podmínka „jen když je zpráva stále ve stavu 20“ zůstává.

### 3. Chyby nastavení a hláška

- `AnalysisRunner::analyze()`: `LlmApiException` se stavem 401, 403, 404
  → `config_error`, `retryable: false`, zpráva
  `anthropic: HTTP <stav> <typ>: <text>`. Rozhodnutí patří do
  `LlmApiException` (`isConfigurationError()` — sem i strop útraty),
  runner jen větví.
- cfgItem `core.mail.analysisErrorKinds`: `configError` — nové texty cs
  i en; `FALLBACK` v `AnalysisErrorPresenter` stejně.

### 4. Dokumentace a uzavření

- Dokumenty podle „V rozsahu“; `**Stav:**` + `python3 scripts/tasks-index.py`.

## Testy

- `AnalysisRunnerTest`: dobírání tří zpráv jedním slotem; zpráva vrácená
  do fronty se v procesu nebere znovu; `not_configured` ukončí dobírání;
  rozpočet → nástupce spuštěn až po uvolnění slotu a jen při postupu;
  výjimka v `analyze()` → selhaný běh, stav 10, při třetím za hodinu 70,
  další zpráva se zpracuje; 401 / 403 / 404 → `config_error`, jediné
  volání.
- `AnalysisQueueTest`: `excludeIds`.
- `AnalysisClaimReaperTest`: první a druhé vypršení → stav 10; třetí za
  hodinu → stav 70 + selhaný běh; vypršení starší než hodina se
  nepočítá; zpráva mimo stav 20 se nemění.
- `AnalysisErrorPresenterTest`: `config_error` → `configError` s novým
  textem.
- `MailAnalyzeCommandTest`: výpis dobírání.

## Ověření na dev serveru (zdroj dat v režimu `volný`, bez cronu)

1. `maxConcurrent = 2`, nahrát z Dashboardu pět dokladů naráz → všech
   pět „Analyzováno“ bez ručního sweepu; v `analysis.log` dva procesy.
2. Backend bez klíče, tři zprávy ve frontě, `mail-analyze --message` →
   jedno varování, proces skončí, žádná smyčka.
3. Vyřazený nebo neexistující model v backendu → „Analýza selhala“
   s hláškou o nastavení AI, jediné volání.
4. Uměle shodit runner po claimu třikrát za sebou (`kill -9`, lease
   zkrácená v testovacím volání) → po třetím vypršení reaper přepne
   zprávu na „Analýza selhala“.

## Pasti

- **Smyčka na místě.** Bez evidence zpracovaných id by runner bral tutéž
  zprávu dokola (zůstává ve frontě po přechodné chybě i při
  `claim_failed`).
- **Nástupce a slot.** Nástupce se spouští až **po** uvolnění slotu,
  jinak skončí na „no free slot“ a řetěz se přetrhne.
- **Dva dobírající runnery** na jednom zdroji dat sáhnou po téže
  nejstarší zprávě; claim je atomický — poražený ji zařadí mezi
  zpracované a jde dál. Žádné čekání, žádný zámek navíc.
- **Reaper běží i pro démona.** Dokud pull protokol existuje, strop
  z D26 platí i pro jeho claimy — je to žádoucí.
- **Paměť dlouhého procesu.** Přílohy a tělo požadavku uvolnit po každé
  zprávě (žádné držení v polích výsledků).

## Hotovo když

- [ ] Kroky 1–4 jako samostatné commity, `php -l`, cílené testy
      (`--filter 'AnalysisRunnerTest|AnalysisQueueTest|AnalysisClaimReaperTest|AnalysisErrorPresenterTest|MailAnalyzeCommandTest'`),
      pak celá sada.
- [ ] Ověření 1–4 provedeno, výsledek do #85.
- [ ] Dokumentace podle kroku 4.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
