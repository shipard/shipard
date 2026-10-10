# AI modely — fáze 0: odchod ze Sonnetu 4.5 a minimum pro aktuální generaci modelů

**Stav:** naplánováno — rozhodnutí F0-D1, F0-D2, F0-D4–F0-D9 potvrzena 2026-10-10 (#85)

## Status / cíl

Všechny AI backendy dnes běží na `claude-sonnet-4-5`. Ten je od 30. 9. 2026
deprecated a **30. 11. 2026 končí** — požadavky na něj začnou selhávat.
Doporučená náhrada `claude-sonnet-5-5` se ale chová jinak (odmítá
`temperature`, přemýšlí i bez parametru, v tool smyčce chce zpět thinking
bloky), takže ji nejde jen dosadit.

Task proto dělá dvě věci:

1. **Přemostění:** výchozí model se mění na `claude-sonnet-4-6` — stejná
   cena, stejný tokenizer, přijímá `temperature`, bez parametru nepřemýšlí;
   aktivní nejméně do 17. 2. 2027. Stávající chování (včetně chatu) se
   nemění a termín 30. 11. přestává hrozit.
2. **Minimum pro řadu 5:** aby šly modely `claude-sonnet-5-5`
   a `claude-haiku-5-5` vyzkoušet na analýze pošty a obsahových štítcích
   přes druhý backend — volitelná teplota, parametry `thinking` a `effort`,
   kontrola `stop_reason`, vyšší stropy `max_tokens`, správný ceník.

Velká přestavba (připojení vs. model, katalog modelů, AI úlohy) je fáze 1
v #85 a tento task ji nepředjímá.

GitHub Issue: shipard/shipard#85.

## Návaznost

- `tasks/mail-analysis-inprocess.md`, `tasks/mail-analysis-queue-drain.md`
  — analýza pošty běží v `shpd` (`AnalysisRunner`); démon `ai-analyzer`
  končí. Původní F0-D3 a commit „claim payload a kontrakt“ proto odpadly.
- `tasks/ai-provisioning-unconditional.md` — co `ds-upgrade` zakládá.
- `tasks/mail-isdoc-content-tags.md`, `tasks/booking-history-import.md`
  — volající, kterých se task dotýká (obsahové štítky, historie účtování).

## Před implementací přečti

- `docs/ai.md` — §4 cesty k modelu, §5 backendy.
- `modules/core/ai/tables/core_ai_backends.md`.
- `docs/table-definitions.md` §10 — bezpečné změny při `ds-upgrade`
  (uvolnění `NOT NULL` → `NULL` `SchemaComparator` provádí).
- Kód: `src/Core/Ai/` (`LlmChatParams`, `LlmChatResult`,
  `AnthropicLlmClient`, `AiBackendResolver`, `AnthropicPricing`),
  `modules/core/mail/src/Analysis/AnalysisRunner.php` (`chatParams()`,
  `temperatureOf()`, zpracování `stopReason` v `analyze()`),
  `modules/core/exchange/src/Enrich/ContentTagClassifier.php`,
  `modules/core/exchange/src/BookingHistory/BookingHistoryClassifier.php`,
  `src/Core/Dashboard/DashboardSummaryService.php`,
  `src/Api/Controller/ChatController.php`,
  `modules/core/mail/src/AIAnalyzerProvisioner.php`.
- Dokumentace API:
  - <https://platform.claude.com/docs/en/about-claude/model-deprecations>
  - <https://platform.claude.com/docs/en/models/sonnet-5-5/migration-guide>
  - <https://platform.claude.com/docs/en/about-claude/pricing>

### Co platí pro `claude-sonnet-5-5` (ověřeno 2026-10-10)

- Nevýchozí `temperature` / `top_p` / `top_k` → 400.
- Bez pole `thinking` běží adaptive thinking; přijímá jen `adaptive`
  a `between_tools` (`disabled` i `enabled` → 400). `between_tools` jen
  s effortem `low` / `medium` / `high`.
- Effort `low` | `medium` | `high` | `xhigh` | `max` v `output_config.effort`,
  výchozí `high`. Thinking se počítá do `max_tokens` a účtuje jako výstup.
- Vynucené volání nástroje (`tool_choice` `any` / `tool`) a prefill → 400.
  Ani jedno dnes nepoužíváme.
- Odmítnutí vrací `stop_reason: "refusal"`; může ho spustit i neškodný obsah.
- Novější tokenizer: zhruba o 30 % víc tokenů na stejný text.
- V tool smyčce se thinking bloky vracejí beze změny a historie musí být
  jen přidávaná — proto chat zůstává mimo rozsah (F0-D6).

## Rozhodnutí k designu (potvrzená)

- ✓ **F0-D1 — `temperature` volitelná.** Sloupec
  `core_ai_backends.temperature` `nullable: true`, default `NULL`. `NULL`
  = parametr se neposílá. Provisioner zakládá backend s `NULL`.
  **Existující řádky se nemigrují** — hodnota `0` je pro Sonnet 4.6 platná;
  kdo backend přepne na řadu 5, teplotu ve formuláři vymaže.
  **Teplotu čte jen analýza pošty** (jako dnes): chat, shrnutí dashboardu
  a klasifikátory ji neposílají a posílat nezačnou — jinak by se jim
  stávající hodnotou `0` změnilo chování.
- ✓ **F0-D2 — `thinking` a `effort` na backendu (přechodně).** Dva nové
  sloupce `enumString`, `NOT NULL`, default `auto`:
  - `thinking`: `auto` | `adaptive` | `between_tools` | `disabled`
    (cfgItem `core.ai.thinkingModes`),
  - `effort`: `auto` | `low` | `medium` | `high` | `xhigh` | `max`
    (cfgItem `core.ai.effortLevels`).

  `auto` = parametr se neposílá. Jiná hodnota jde jako
  `thinking: {"type": "<hodnota>"}` resp. `output_config: {"effort":
  "<hodnota>"}`. Které hodnoty konkrétní model přijímá, **neověřujeme** —
  chybu 400 vrátí API a projeví se jako selhání volání. Validaci přinese
  katalog modelů ve fázi 1, která tyto sloupce přesune do AI úloh.
- ~~F0-D3 — claim payload~~ — odpadlo (#85 D9).
- ✓ **F0-D4 — Kontrola `stop_reason`.** `LlmChatResult::isComplete()`:
  `true` pro `end_turn`, `tool_use`, `stop_sequence` a `null` (mocky bez
  pole), jinak `false` (`max_tokens`, `refusal`,
  `model_context_window_exceeded`, …).
  - Klasifikátory a shrnutí při neúplném výsledku zalogují
    `ErrorLogger::warn` (stop reason, model, limit) a vrátí totéž co při
    selhání (`null`); **shrnutí se necachuje**. Chat jen loguje.
  - **Runner analýzy:** `refusal` a `model_context_window_exceeded` končí
    jako `ai_error` bez opakování (`anthropic: stop_reason <hodnota>`),
    stejně jako dnes `max_tokens`. Dnes by propadly do parsování a skončily
    zavádějící chybou schématu.
- ✓ **F0-D5 — `MAX_TOKENS` jako strop, ne cena.** Na modelu bez thinking
  se spotřeba nezmění. Nové hodnoty: `ContentTagClassifier` 500 → **8000**,
  `BookingHistoryClassifier` 4000 → **16000**, `DashboardSummaryService`
  300 → **2000** (délku shrnutí dál drží prompt).
- ✓ **F0-D6 — Chat mimo rozsah; výchozí backend na Sonnet 4.6.** Tool
  smyčka chatu na modelech s thinking vyžaduje vracet thinking bloky (fáze
  4 v #85) — `AnthropicLlmClient::finalizeBlocks()` je dnes zahazuje. Chat
  a shrnutí dashboardu používají **výchozí backend**; ten se přepíná na
  `claude-sonnet-4-6` (F0-D8), **ne na řadu 5**. Řada 5 se zkouší přes
  **druhý backend**: navázaný na AI profil pošty, nastavený
  v `exchange.contentTag.backend`, nebo `--backend` u `booking-history`.
- ✓ **F0-D7 — Rozšíření `LlmChatParams`.** Nová pole `?string $thinking =
  null`, `?string $effort = null` (`null` = neposílat). Převod řádku
  backendu na ladicí parametry na jednom místě:
  `AiBackendResolver::tuning(array $backend): array{temperature: ?float,
  thinking: ?string, effort: ?string}` (`auto` → `null`). `thinking`
  a `effort` z něj berou všichni volající (pět: chat, shrnutí, dva
  klasifikátory, runner analýzy); `temperature` jen runner (F0-D1).
- ✓ **F0-D8 — Přemostění přes Sonnet 4.6.**
  - Provisioner zakládá výchozí backend s modelem `claude-sonnet-4-6`.
  - `ds-upgrade` jednorázově a idempotentně přepíše u **všech** backendů
    model `claude-sonnet-4-5` (i s datovou příponou
    `claude-sonnet-4-5-…`) na `claude-sonnet-4-6` a vypíše, co změnil.
    ID s prefixem platformy (`anthropic.…` na Bedrocku) nechává být —
    partnerské platformy mají vlastní termíny.
  - Mapování vyřazených modelů jako konstanta na jednom místě
    (`RETIRED_MODELS`), připravená na další řádky.
- ✓ **F0-D9 — Ceník v PHP podle skutečnosti.** `AnthropicPricing` má
  převzaté chyby a chybí mu řada 5. Nové sazby (USD za milion tokenů,
  vstup / výstup):

  | Prefix modelu | Vstup | Výstup |
  |---------------|-------|--------|
  | `claude-sonnet-5-5`, `claude-sonnet-5` | 2 | 10 |
  | `claude-sonnet-4-6`, `claude-sonnet-4-5`, `claude-sonnet-4` | 3 | 15 |
  | `claude-haiku-5-5` (prompt do 100 000 tokenů) | 0,10 | 0,50 |
  | `claude-haiku-5-5` (prompt nad 100 000 tokenů) | 0,50 | 2,50 |
  | `claude-haiku-4-5` | 1 | 5 |
  | `claude-opus-5-5` | 4 | 20 |
  | `claude-opus-5`, `claude-opus-4-8`, `-4-7`, `-4-6`, `-4-5` | 5 | 25 |
  | `claude-opus-4-1`, `claude-opus-4` | 15 | 75 |
  | `claude-fable-5`, `claude-mythos-5` (i `-5-1`) | 10 | 50 |

  Řádky pro řadu 3 zůstávají. Dočasné — ceny převezme katalog modelů
  (fáze 1).

## Scope

### V rozsahu

- Schéma a formulář backendu (F0-D1, F0-D2), provisioner a přemostění
  (F0-D8).
- LLM klient, `tuning()`, pět volajících, `stop_reason` (F0-D4, F0-D5,
  F0-D7).
- Ceník (F0-D9).
- Dokumentace `docs/ai.md`, `core_ai_backends.md`.

### Mimo rozsah

- Katalog modelů, AI úlohy, rozdělení na připojení a model (#85 fáze 1).
- Thinking bloky v tool smyčce chatu, přepnutí chatu na řadu 5 (fáze 4).
  **Termín:** Sonnet 4.6 je aktivní nejméně do 17. 2. 2027.
- Validace hodnot `thinking` / `effort` proti modelu.
- Migrace existujících hodnot `temperature = 0` na `NULL`.
- Ceny cache tokenů a ID modelů s prefixem platformy v ceníku (fáze 3).
- Přepnutí výchozího modelu na řadu 5 — až po evaluaci (fáze 2).

## Kroky

Každý krok je samostatný commit.

### 1. Schéma backendu

- `modules/core/ai/tables/core_ai_backends.jsonc`: `temperature`
  `nullable: true`, bez `default`; nové sloupce `thinking` a `effort`
  ve skupině ladění, popisky `name:cs` / `name:en`.
- cfgItemy `core.ai.thinkingModes` a `core.ai.effortLevels`
  (vzor `core.mail.senderRuleDispositions` — soubor v `config/` + registrace
  v `module.jsonc`). Popisek `auto`: „Výchozí modelu (neposílat)“
  / „Model default (not sent)“.
- `modules/core/ai/forms/core_ai_backends.jsonc`: v sekci Ladění `thinking`
  a `effort` (select z cfgItem). Ověřit, že prázdné pole teploty se uloží
  jako `NULL`, ne `0` — pokud ne, doplnit normalizaci pro nullable
  `numeric` v cestě ukládání formuláře obecně, ne speciálním případem.
- `core_ai_backends.md`: sekce Ladění — nové sloupce, význam `NULL`
  / `auto`, teplotu čte jen analýza pošty, poznámka „při přepnutí na model
  řady 5 teplotu vymaž“.

### 2. Přemostění (F0-D8)

- `AIAnalyzerProvisioner`: výchozí backend s `model => 'claude-sonnet-4-6'`
  a `temperature => null`; nová metoda pro přepis vyřazených modelů
  (`RETIRED_MODELS`), volaná z `ds-upgrade` na stejném místě jako
  provisioning; výpis změněných backendů (`backend_id`, starý → nový
  model).
- Testy: `AIAnalyzerProvisionerTest` — nový backend má 4.6 a `NULL`
  teplotu; přepis `claude-sonnet-4-5` i `claude-sonnet-4-5-20250929`;
  jiné modely a `anthropic.claude-sonnet-4-5…` beze změny; druhý běh nic
  nemění.

### 3. LLM klient a volající

- `LlmChatParams`: pole `thinking`, `effort`; docblock u `temperature`
  zobecnit (řada 4.7 a novější ji odmítá).
- `AnthropicLlmClient::streamChat()`: `thinking` →
  `$body['thinking'] = ['type' => …]`, `effort` →
  `$body['output_config'] = ['effort' => …]`, obojí jen když není `null`.
- `LlmChatResult::isComplete()` (F0-D4).
- `AiBackendResolver::tuning()` (F0-D7).
- Volající:
  - `AnalysisRunner` — `chatParams()` bere všechny tři parametry
    z `tuning()` (zaniká `temperatureOf()`); `refusal`
    a `model_context_window_exceeded` → `ai_error` bez opakování (F0-D4).
  - `ContentTagClassifier`, `BookingHistoryClassifier` (`baseParams()`
    i `classifyBatch()`), `DashboardSummaryService` — `thinking` / `effort`
    z `tuning()`, `temperature` dál `null`; `isComplete()`; nové
    `MAX_TOKENS` (F0-D5); shrnutí při neúplném výsledku neukládat do cache
    a vrátit `text: null`.
  - `ChatController` — jen předat `thinking` / `effort` a zalogovat
    neúplný výsledek; chování smyčky beze změny.
- Testy:
  - `AnthropicLlmClientTest`: tělo požadavku obsahuje `thinking`
    / `output_config` jen při nenulové hodnotě; `temperature` chybí při
    `null`.
  - `AiBackendResolverTest` (nový): `auto` → `null`, `NULL` teplota →
    `null`, `0` → `0.0`.
  - `AnalysisRunnerTest`: backend s `temperature = 0` a `auto` posílá
    **stejný požadavek jako dnes**; `NULL` teplota se neposílá; `refusal`
    → stav 70, jediné volání.
  - `ContentTagClassifierTest`, `BookingHistoryClassifierTest`: výsledek
    se `stopReason: 'max_tokens'` → `null`; `'refusal'` totéž; teplota se
    neposílá ani při `temperature = 0` na backendu.
  - `DashboardSummaryServiceTest`: useknutý výsledek se necachuje.

### 4. Ceník (F0-D9)

- `AnthropicPricing`: sazby podle tabulky; nejdelší prefix dál vyhrává
  (pozor na `claude-opus-4` vs `claude-opus-4-5` až `-4-8`). Haiku 5.5:
  druhá sazba, když `tokensInput > 100 000` — `costUsd()` počet vstupních
  tokenů má.
- `AnthropicPricingTest`: každý řádek tabulky, obě pásma Haiku 5.5, ID
  s datovou příponou, neznámý model.

### 5. Dokumentace a uzavření

- `docs/ai.md` §4 / §5: ladicí parametry backendu (teplota jen pro analýzu
  pošty, thinking, effort, význam `NULL` / `auto`), kontrola `stop_reason`,
  výchozí model a přemostění, omezení chatu, postup „zkoušet nový model
  přes druhý backend“ (F0-D6), termín konce Sonnetu 4.6.
- `docs/roadmap.md`: řádek pro #85 fázi 4 (thinking bloky v chatu,
  přechod na řadu 5) s termínem — Sonnet 4.6 je aktivní nejméně do
  17. 2. 2027.
- `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Ověření (zdroj dat v režimu `volný`)

1. `ds-upgrade` — `temperature` je nullable, `thinking` / `effort` existují
   s `auto`; výchozí backend má model `claude-sonnet-4-6` (výpis přepisu);
   druhý `ds-upgrade` nic nemění.
2. **Regrese na 4.6:** nahrát doklad bez ISDOC → analýza doběhne, návrh
   odpovídá běhu na 4.5 (struktura, klíčová pole); asistent odpoví na dotaz
   s použitím nástroje; shrnutí dashboardu se vygeneruje.
3. Založit druhý backend: `claude-sonnet-5-5`, teplota prázdná, klíč přes
   `ai-analyzer-set-key --backend <id>`; navázat na druhý AI profil pošty
   a na `exchange.contentTag.backend`.
4. Analýza téhož dokladu na 5.5 ve variantách `auto` (adaptive, `high`)
   a `between_tools` + `low` — doběhne, v logu žádné 400 ani varování
   o neúplném výsledku; zapsat tokeny, čas a `cost_usd` obou variant.
5. Totéž na `claude-haiku-5-5` (varianta `auto`).
6. Negativní test: `claude-sonnet-5-5` + `thinking: disabled` → volání
   selže chybou 400, čitelně v logu a v tabu Analýzy.
7. Výsledky 2, 4 a 5 (kvalita návrhu, cena, čas) do #85 — podklad pro
   fázi 2.

## Pasti

- **Teplota u ostatních volajících.** Chat, shrnutí a klasifikátory dnes
  posílají `temperature: null`. `tuning()` jim teplotu **nesmí** začít
  posílat — existující řádky mají `0` a změnilo by se chování.
- **Thinking a `max_tokens`.** Na řadě 5 se thinking počítá do limitu;
  stropy z F0-D5 jsou proto řádově vyšší než délka odpovědi.
- **Thinking a stall timeout.** Runner má `stallTimeoutSeconds = 180`.
  Při vynechaném zobrazení thinking drží spojení jen `ping` události —
  ověřit při kroku 4, že dlouhé přemýšlení nekončí timeoutem.
- **`between_tools` + `xhigh` / `max`** → 400; `disabled` na 5.5 → 400.
  Nevalidujeme (F0-D2) — chyba musí být v logu čitelná.
- **Přepis modelu a hosting.** Backend hostovaného zdroje dat ukazuje na
  gateway; model se přepisuje stejně (gateway ho jen předává dál).
- **Ceník a prefixy.** `claude-opus-4` (15 / 75) nesmí chytit
  `claude-opus-4-5` až `-4-8` (5 / 25); `claude-haiku-5-5` nesmí spadnout
  do žádného staršího prefixu.

## Hotovo když

- [ ] Kroky 1–5 jako samostatné commity, `php -l` na změněných souborech.
- [ ] Cílené testy: `vendor/bin/phpunit --filter 'AnthropicLlmClientTest|AiBackendResolverTest|AnthropicPricingTest|AnalysisRunnerTest|ContentTagClassifierTest|BookingHistoryClassifierTest|DashboardSummaryServiceTest|AIAnalyzerProvisionerTest'`, pak celá sada.
- [ ] Backend s `temperature = 0` a `auto` posílá z runneru analýzy
      identický požadavek jako před změnou (test).
- [ ] Ověření 1–7 provedeno, výsledek do #85.
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
