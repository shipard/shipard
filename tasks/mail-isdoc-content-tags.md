# Task: Došlá pošta — obsahové štítky u ISDOC importu, import v runneru (#81)

**Stav:** hotovo — implementováno a ověřeno na dev DS 2026-09-29 (5 commitů)

## Status / cíl

ISDOC faktura s řádky, ke kterým chybí historie partnera, dnes zůstane bez
položky. Deterministický import (`IsdocImportService`) totiž volá jen
Vrstvu 0 (`RowHistoryEnricher`), obsahovou eskalaci nespouští a
`core_mail_message_analyses.content_tag` nepersistuje. Karta „Nová
kategorie" na dashboardu (`ContentTagSuggestionsSource`) proto u ISDOC
nevznikne. Uživatel musí položky zakládat ručně, nebo generovat celou sadu
účetních položek v Nastavení.

Cíl: **ISDOC import prochází stejnou obsahovou eskalací jako AI analýza**
(pravidlo IČO → štítek, jinak LLM klasifikace), persistuje `content_tag`
a z potvrzených ISDOC dokladů se učí pravidla. LLM volání přitom nesmí
zpomalit intake ani ruční nahrání, takže import se přesouvá do detached
runneru předzpracování.

GitHub Issue: shipard/shipard#81.

## Návaznost

- `tasks/mail-isdoc-import.md` — deterministický ISDOC import (vzor, invarianty).
- `tasks/mail-message-centric.md` Fáze D — embedded ISDOC v PDF a dedup identitou.
- `tasks/content-tag-enrichment.md` — `RowEnrichmentPipeline`, D14 (strop
  pásma review), D16 (LLM jen při `/result`). §8 nechal ISDOC na čistém
  `RowHistoryEnricher` jako nezamčený default; tenhle task ho mění.
- `tasks/content-tag-ui.md` — karta „Nová kategorie" (D25), materializace (D26).
- `tasks/mail-preprocess.md` — runner, spawner, sweep, gate AI fronty (D8, D10, D12).

## Před implementací přečti

- `modules/core/mail/docs/ai-analysis.md` — sekce „Deterministický ISDOC
  import" a „Obsahová eskalace (content tags)".
- `modules/core/mail/docs/preprocess.md` — stavy `preprocess_state`, tok
  intake → runner, sweep.
- `docs/mail/api-contract.md` §9.1 — gate `preprocess_state NOT IN (10, 20)`.
- `docs/dashboard.md` §5.3 — `ContentTagSuggestionsSource` (beze změny,
  jen pochopit, co karta čte).
- `docs/help-authoring.md` — před úpravou `help/`.
- Kód:
  - `modules/core/mail/src/IsdocImportService.php` — `doImport()` kroky 1–2
    (kandidáti, dedup) jsou budoucí `detect()`.
  - `src/Api/Controller/MailController.php` — `receiveIncoming()`
    (větvení za commitem), `uploadMessages()` (smyčka `$isdocBatches`),
    `runIsdocImport()`, `spawnPreprocess()`.
  - `modules/core/mail/src/Preprocess/PreprocessRunner.php` — `run()`
    (prázdný plán = chyba, ř. ~181), `runIsdocImport()`, `sweep()`,
    `encodeLog()` / `decodeLog()`.
  - `modules/core/mail/src/Preprocess/PreprocessRunnerFactory.php` — wiring ISDOC service.
  - `modules/core/exchange/src/Enrich/RowEnrichmentPipeline.php` —
    `create()`, `enrichAtResult()`.
  - `src/Api/Controller/AnalysisController.php` — `extractContentTag()`
    (ř. ~1171) a INSERT analýzy s `content_tag`.
  - `modules/core/exchange/src/Enrich/ContentTagRuleCaptureHandler.php` —
    filtr `source_kind`.
  - `modules/core/mail/src/IncomingMessagesViewer.php` —
    `buildPreprocessItems()`.
  - `public/index.php` — lazy wiring `$isdocImportFactory` pro `MailController`
    (ř. ~1012).

## Rozhodnutí k designu (potvrzená)

- ✓ **D1** — ISDOC import s obsahovou klasifikací běží **vždy v detached
  runneru předzpracování**, ne inline v intake/upload requestu. Import
  volá `RowEnrichmentPipeline::enrichAtResult()` a persistuje `content_tag`.
  Důvody:
  - LLM latence zůstane mimo HTTP request (upload až 20 souborů),
  - import má jeden vstupní bod,
  - znovu se použije spawner, sweep a gate AI fronty.

  DS bez AI backendu degraduje na samotné pravidlo IČO, protože classifier
  je null-safe.
- ✓ **D2** — `ContentTagRuleCaptureHandler` se učí i z dokladů se
  `source_kind = 'isdoc'`. Podmínka `tagSource = 'llm'` zůstává beze změny.
- ✓ **D3** — Bez backfillu. Změna platí jen pro novou poštu.
- ✓ **D4** — **Detekce zůstává inline**: parse, validace schématu,
  dedup identitou, tedy dnešní kroky 1–2 `doImport()`. Do runneru se
  odkládá jen zpráva s platným jednoznačným ISDOC dokladem.
  - Přepnutí na `preprocess_state = 10` je podmíněné
    `analysis_state IN (0, 10)`.
  - PDF bez ISDOC a všechny případy, kdy se ISDOC větev vzdá, jdou do AI
    fronty jako dnes.

## Scope

**V rozsahu**

- `IsdocImportService`: rozdělení na `detect()` a import, `RowEnrichmentPipeline`
  místo `RowHistoryEnricher`, persist `content_tag`.
- Sdílený helper pro vytažení štítku z canonicalu (přesun z `AnalysisController`).
- `PreprocessRunner`: spuštění pouze kvůli ISDOC (`trigger: 'isdoc'`)
  s prázdným plánem není chyba.
- `PreprocessRunnerFactory`: wiring `RowEnrichmentPipeline::create()`.
- `MailController`: intake i upload volají detekci, odložení a spawn místo
  inline importu. Wiring v `public/index.php`.
- `ContentTagRuleCaptureHandler`: `source_kind ∈ {aiExtraction, isdoc}`.
- Detail zprávy: sekce předzpracování srozumitelná pro ISDOC-only běh.
- Dokumentace `modules/core/mail/docs/`, `help/`, hlavička tasku.

**Mimo rozsah**

- Rychlost AI analyzeru (polling, `max_concurrent`) — komponenta `ai-analyzer`, samostatně.
- `mail-preprocess --force` pro ISDOC-only zprávy (viz Pasti).
- Backfill existujících ISDOC analýz (D3).
- Změny `ContentTagSuggestionsSource`, `AnalysisConfidenceResolver`
  a `MessageProposalApplier`. ISDOC řádek s `content_tag` už dnešní dotaz
  karty zahrne a pásmo stropuje review stejně jako u AI.

## Nový tok

```
intake / upload — commit tx (analysis_state 10 nebo 0, preprocess_state 0)
  ├─ intake s plánem pravidel → spawn runneru (beze změny)
  └─ jinak: isPotentialCandidate? (.isdoc/.isdocx/XML/PDF)
        └─ ano → IsdocImportService::detect()   ← parse + schéma + dedup, bez zápisu
              ├─ false → konec, AI fronta (dnešní chování)
              └─ true  → UPDATE … SET preprocess_state = 10,
                           preprocess_log = {plan: [], trigger: 'isdoc', …}
                         WHERE id = ? AND preprocess_state = 0
                           AND analysis_state IN (0, 10)
                         ├─ 1 řádek → spawnPreprocess()
                         └─ 0 řádků → analyzer vyhrál, nic

PreprocessRunner (detached)
  ├─ claim 10 → 20
  ├─ plán (u trigger 'isdoc' prázdný, není to chyba)
  ├─ IsdocImportService::tryImport
  │    detekce znovu → enrichAtResult (pravidlo IČO | LLM) → tx:
  │    INSERT analýzy vč. content_tag, analysis_state → 30, …
  └─ preprocess_state → 30 | 40 → gate otevřena
       (import selhal → analysis_state zůstal 10 → AI fronta)
```

## Kroky

### 1. `IsdocImportService` — detekce, pipeline, `content_tag`

Commit: `feat(mail): ISDOC import s obsahovou eskalací a detect() (#81 D1, D4)`

- Kroky 1–2 z `doImport()` (kandidáti, dedup identitou, preference
  samostatná příloha > embedded) vyčlenit do privátní
  `collectDocument(int $messageNdx, array $uploadedFiles): ?array`.
  Vrací vybraný dokument, nebo `null`. Logy a warningy zůstanou beze změny.
- Nová veřejná `detect(int $messageNdx, array $uploadedFiles): bool`:
  - vrací `collectDocument() !== null`,
  - nic nezapisuje a nespouští enrichment,
  - výjimky polyká a vrací `false`, stejně jako `tryImport`.
- `doImport()` volá `collectDocument()`, zbytek toku se nemění.
- Konstruktor: `?RowHistoryEnricher $enricher` → `?RowEnrichmentPipeline $enricher`.
  Obohacení volá `enrichAtResult()`, stále **před** otevřením zápisové tx,
  a selhání zůstává nefatální.
- INSERT analýzy dostane `'content_tag' => RowEnrichmentPipeline::contentTagOf($canonical)`.
- Helper: `public static function contentTagOf(array $canonical): ?string`
  na `RowEnrichmentPipeline`. Je to tělo dnešního
  `AnalysisController::extractContentTag()` bez kontroly validity.
  - `AnalysisController::extractContentTag()` si ponechá guard
    `$documentValid` / `null` a volá helper.
  - U ISDOC je canonical validní, protože prošel `passesSchema`.
- Docblock třídy: datový tok (volá runner, `MailController` jen `detect()`)
  a invariant „LLM mimo tx".

### 2. Runner — ISDOC-only běh

Commit: `feat(mail): runner předzpracování pro ISDOC import (#81 D1)`

- `PreprocessRunner`: konstanta `TRIGGER_ISDOC = 'isdoc'`. Chybějící
  `trigger` v logu znamená dnešní běh podle pravidel (zpětná kompatibilita).
- `run()`: když je `$log['results'] === []` **a** `trigger === 'isdoc'`,
  není to chyba. Nepřidávat řádek `stored plan is empty` a `allOk`
  ponechat. Bez triggeru se chování nemění (regresní test).
- Veřejná statická továrna logu, např. `PreprocessRunner::isdocOnlyLog(): array`
  (`plan: [], trigger, results: [], attempts: 0, createdAt`). Serializuje
  se přes existující `encodeLog()`, ať `MailController` neskládá JSON sám.
- `PreprocessRunnerFactory`: `ConfigRuntime::load()` přesunout před stavbu
  enricheru a stavět `RowEnrichmentPipeline::create($db, $configRuntime, $dsConfig)`.
  Při výjimce se použije `null` a warning jako dnes (import poběží bez obohacení).

### 3. Intake a upload — detekce a odložení

Commit: `feat(mail): ISDOC zprávy přes runner místo inline importu (#81 D4)`

- `MailController::runIsdocImport()` přejmenovat na `deferIsdocImport()`:
  1. Rychlý filtr `isPotentialCandidate` beze změny. Bez kandidáta se
     service vůbec nestaví.
  2. `($this->isdocImportFactory)()->detect($messageId, $contentAttachments)`.
  3. Při `true` podmíněný UPDATE z diagramu výše:
     `preprocess_state = PREPROCESS_PENDING`,
     `preprocess_log = PreprocessRunner::encodeLog(PreprocessRunner::isdocOnlyLog())`,
     `modified`.
  4. Při zasaženém řádku zavolat `spawnPreprocess($messageId)`.
  5. Celé je to v try-catch jako dnes — nikdy nesmí shodit intake ani upload.
- `receiveIncoming()`: větev bez plánu volá `deferIsdocImport()`. Větev
  s plánem pravidel se nemění (runner ISDOC import udělá sám, nově i se štítkem).
- `uploadMessages()`: smyčka `$isdocBatches` po commitu volá `deferIsdocImport()`.
- `public/index.php`: `$isdocImportFactory` pro `MailController` staví
  service jen pro detekci, tedy bez enricheru, `partnerWriter` a
  `titleComposer` (`detect()` je nepotřebuje). Komentář upravit.
- `IncomingMessagesViewer::buildPreprocessItems()`: u `trigger === 'isdoc'`
  přidat řádek „Spuštěno: ISDOC import". Prázdný „Pravidla" `addItem`
  vynechá sám, ověřit v detailu.

### 4. Učení pravidel z ISDOC

Commit: `feat(exchange): učení pravidel štítků i z ISDOC dokladů (#81 D2)`

- `ContentTagRuleCaptureHandler::onStateChanged()`: filtr
  `source_kind !== 'aiExtraction'` změnit na
  `!in_array($source_kind, ['aiExtraction', 'isdoc'], true)`.
  Docblock doplnit.

### 5. Dokumentace a uzavření

Commit: `docs(mail): ISDOC import v runneru, obsahové štítky (#81)`

- `modules/core/mail/docs/ai-analysis.md`:
  - „Deterministický ISDOC import": datový tok (inline `detect()` →
    odložení → runner), bod 3 (pipeline místo `RowHistoryEnricher`,
    persist `content_tag`), vztah k frontě (gate), degradace bez AI.
  - „Obsahová eskalace": odstranit výjimku „kromě ISDOC importu".
- `modules/core/mail/docs/preprocess.md`:
  - tabulka stavů (stav 10 nastavuje i ISDOC detekce),
  - diagram toku,
  - `trigger: 'isdoc'` v popisu logu,
  - sweep: selhání spawnu = ISDOC zpráva až po `STALE_PENDING_SECONDS`.
- `help/polozky/obsahove-stitky.md`: AI zařazuje podle obsahu i faktury
  ISDOC a karta Nová kategorie se objeví i pro ně. Formulovat podle
  `docs/help-authoring.md`, bez technických názvů.
- Zkontrolovat, že `help/posta/prijem-posty.md` a
  `help/posta/kontrola-vytezeni.md` („převezme přímo, bez AI") pořád
  platí — čtení dokladu AI nepotřebuje, jen zařazení položek. Případně
  upřesnit jednou větou.
- Hlavička `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

## Testy

PHPUnit jen s úzkým `--filter`.

| Test | Případy |
|------|---------|
| `IsdocImportServiceTest` | `detect()` true pro validní samostatný ISDOC a pro embedded; false pro PDF bez embedded, vadný samostatný ISDOC, dvě odlišné identity; `detect()` nic nezapisuje; import persistuje `content_tag` ze štítku pipeline; bez pipeline / bez štítku `content_tag` NULL |
| `RowEnrichmentPipelineTest` | `contentTagOf()`: blok se štítkem, bez bloku, prázdný štítek |
| `PreprocessRunnerTest` | `trigger: 'isdoc'` + prázdný plán → stav 30, žádný řádek `plan`; bez triggeru prázdný plán → 40 (regrese) |
| `MailControllerTest` / `MailControllerUploadTest` | ISDOC zpráva → `preprocess_state` 10, log s triggerem, spawner zavolán (seam `preprocessSpawner`); `detect` false → stav 0, spawner ne; `analysis_state` 20 → guard nic nezmění; upload dávky → spawn per ISDOC zpráva |
| `ContentTagRuleCaptureHandlerTest` | `source_kind = 'isdoc'` + LLM štítek → pravidlo; jiný `source_kind` → nic |

`RowEnrichmentPipeline` je `final`. V testech `IsdocImportService` ji
stavět ze skutečných závislostí s mocky (vzor `RowEnrichmentPipelineTest::pipeline()`),
nebo předat `null`.

## Ověření na dev DS

Na dev DS, ne na alfě. ISDOC fixtury z `tests/Fixtures/Exchange/isdoc/`,
případně upravená kopie s řádkem bez historie.

1. DS s nastavenou osnovou, dodavatel fixtury bez historie a bez pravidla
   štítku, položka pro očekávaný štítek neexistuje. **Nahrát** ISDOC
   z dashboardu.
   - Request se vrátí okamžitě.
   - Do pár sekund je v detailu zprávy předzpracování Hotovo a ISDOC `imported`.
   - SQL: poslední analýza má `model_name = 'isdoc'` a vyplněný `content_tag`.
   - Na dashboardu je karta „Nová kategorie".
2. Založit položku z karty → otevřít návrh. Řádek má položku a návrh je
   v pásmu Ke kontrole.
3. Použít → doklad potvrdit do V pořádku → v Nastavení → Položky →
   Pravidla obsahových štítků vzniklo naučené pravidlo pro IČO dodavatele.
4. Druhý ISDOC téhož dodavatele → `_resolve.contentTag.tagSource = 'rule'`
   v `canonical_json`, v logu žádné LLM volání.
5. Nahrát PDF bez ISDOC → `preprocess_state` 0, zpráva jde do AI fronty
   jako dřív.
6. Nahrát naráz 5 ISDOC (`perFile`) → request rychlý, všech 5 zpráv doběhne
   do Analyzováno.
7. DS bez AI backendu → ISDOC import projde, `content_tag` NULL, návrh
   vznikne jako dnes.
8. E-mail s plánem pravidla předzpracování (pokud je na dev DS k dispozici)
   → runner vykoná akce i ISDOC import se štítkem, stav se nemění proti dnešku.

## Pasti

- **Závod s analyzerem.** Mezi commitem intake a UPDATE odložení je zpráva
  ve frontě (`analysis_state` 10) a `detect()` může trvat (`pdfdetach`).
  UPDATE musí být podmíněný `analysis_state IN (0, 10) AND preprocess_state = 0`,
  jinak se claimnutá zpráva zasekne za gate. `analysis_state` 0 musí
  projít (DS bez AI).
- **LLM mimo tx.** `enrichAtResult()` zůstává před `begin()`. Neobalovat
  LLM volání transakcí se `FOR UPDATE`.
- **Dvojí detekce je záměr (D4).** `detect()` v requestu a znovu
  `collectDocument()` v runneru. Nesnažit se předat výsledek přes DB, runner
  čte přílohy znovu (`listAttachments`).
- **`--force` u ISDOC-only zprávy** vrátí `no_match`, protože matcher hledá
  pravidla odesílatele. Mimo rozsah, jen zdokumentovat v `preprocess.md`.
- **Souběh.** Upload 20 souborů může spawnout až 20 runnerů naráz, každý
  s jedním malým LLM voláním. To je v pořádku, neserializovat.
- **Sweep.** Selhání spawnu znamená, že ISDOC zprávu zvedne sweep až po
  `STALE_PENDING_SECONDS` (300 s). Týká se jen ISDOC, ne běžné PDF pošty (D4).
- **Počítadlo pravidla.** `enrichAtResult()` volá `markRuleHit` před zápisovou tx.
  Když runner spadne mezi enrichmentem a commitem, nebo když závod vyhraje
  analyzer, hit se započte bez použitého návrhu, případně po sweepu dvakrát.
  Stejné riziko má `/result`. Je to jen statistika, neřešit.
- **`tagLabel`** se při `/result` nepersistuje a u ISDOC taky ne — label
  doplňuje fresh čtení (D23 content-tag-ui).
- **Nesahat na `ContentTagSuggestionsSource`.** Dotaz karty ISDOC řádky
  zahrne sám (`status = 2`, `canonical_json`, `content_tag`).
- Commit messages česky s prefixem `feat(scope):` a číslem issue. Bez
  názvů reálných firem a DS v diagnostice.

## Hotovo když

- [x] `IsdocImportService::detect()` existuje a `doImport()` sdílí `collectDocument()`
- [x] ISDOC import volá `enrichAtResult()` a persistuje `content_tag`
- [x] `RowEnrichmentPipeline::contentTagOf()` používá `AnalysisController` i ISDOC import
- [x] Runner bere `trigger: 'isdoc'` s prázdným plánem jako úspěch; bez triggeru beze změny
- [x] `PreprocessRunnerFactory` staví `RowEnrichmentPipeline`
- [x] Intake i upload inline neimportují; platný ISDOC → podmíněné odložení + spawn
- [x] Detail zprávy ukazuje ISDOC-only běh srozumitelně
- [x] `ContentTagRuleCaptureHandler` se učí i z `isdoc`
- [x] Testy z tabulky zelené (úzké filtry)
- [x] Ověření na dev DS body 1–7 (8 dle dostupnosti)
- [x] `ai-analysis.md`, `preprocess.md`, `help/polozky/obsahove-stitky.md` aktualizované
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`, pre-commit kontroly prošly
