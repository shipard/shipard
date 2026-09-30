# AI modely — fáze 0: minimum pro aktuální generaci modelů

**Stav:** naplánováno — čeká na potvrzení rozhodnutí F0-D1–F0-D7 (#85)

## Cíl

Umožnit vyzkoušet aktuální generaci modelů (např. `claude-sonnet-5-5`) na
analýze pošty a obsahových štítcích **bez rozbití** stávajícího provozu na
`claude-sonnet-4-5`. Jen nutné minimum — velká přestavba (připojení vs. model,
katalog modelů, AI úlohy) je fáze 1 v issue #85 a tento task ji nepředjímá.

Proč dnes nejde jen změnit ID modelu (detail v #85, Kontext bod 1):

1. **`temperature`** — `core_ai_backends.temperature` je `NOT NULL default 0`,
   claim payload ji posílá vždy a analyzer ji vždy předá API. Aktuální modely
   nevýchozí hodnotu `temperature` / `top_p` / `top_k` odmítají chybou 400.
2. **Thinking** — aktuální modely přemýšlejí i bez parametru `thinking`
   (adaptive thinking, výchozí effort `high`) a thinking se počítá do
   `max_tokens`. `ContentTagClassifier` (500) a `DashboardSummaryService` (300)
   mají limity, do kterých se thinking nevejde → odpověď skončí na
   `stop_reason: max_tokens` s prázdným nebo useknutým textem.
3. **`stop_reason`** — `AnthropicLlmClient` ho sice vrací v `LlmChatResult`,
   ale nikdo ho nečte. Useknutý nebo odmítnutý výstup (`refusal`) projde tiše:
   štítky zmizí bez chyby, shrnutí dashboardu se useknuté **uloží do cache**.

## Před implementací přečti

- `docs/ai.md` (§4 cesty k modelu, §5 backendy)
- `docs/mail/api-contract.md` §9.2 (claim payload)
- `docs/services.md` §12 (pravidla pro změnu kontraktu)
- `modules/core/ai/tables/core_ai_backends.md`
- `docs/table-definitions.md` §10 (bezpečné změny při `ds-upgrade` — uvolnění
  `NOT NULL` → `NULL` `SchemaComparator` provádí, viz `$relaxesNullability`)
- Souběžný task v analyzeru: `ai_analyzer:tasks/sampling-thinking-params.md`

Dokumentace API k chování modelů:
<https://platform.claude.com/docs/en/models/sonnet-5-5/migration-guide>

## Rozhodnutí k designu (návrh, čeká na potvrzení)

- **F0-D1 — `temperature` volitelná.** Sloupec `core_ai_backends.temperature`
  `nullable: true`, default `NULL`. `NULL` = parametr se modelu neposílá
  (výchozí chování modelu). Provisioner zakládá default backend s `NULL`.
  **Existující řádky se nemigrují** — hodnota `0` je pro Sonnet 4.5 platná;
  kdo backend přepne na aktuální model, teplotu ve formuláři vymaže.
- **F0-D2 — `thinking` a `effort` na backendu (přechodně).** Dva nové sloupce
  `enumString`, `NOT NULL`, default `auto`:
  - `thinking`: `auto` | `adaptive` | `between_tools` | `disabled`
    (cfgItem `core.ai.thinkingModes`)
  - `effort`: `auto` | `low` | `medium` | `high` | `xhigh` | `max`
    (cfgItem `core.ai.effortLevels`)

  `auto` = parametr se neposílá. Jiná hodnota se pošle jako
  `thinking: {"type": "<hodnota>"}` resp. `output_config: {"effort": "<hodnota>"}`.
  Které hodnoty konkrétní model přijímá, **neověřujeme** (Sonnet 4.5 nemá
  effort, `between_tools` zná jen Sonnet 5.5, `disabled` naopak Sonnet 5.5
  odmítá) — chybu 400 vrátí API a projeví se jako selhání volání. Validaci
  proti schopnostem modelu přinese katalog modelů ve fázi 1, která tyto sloupce
  přesune do AI úloh (bez ostrých dat stačí jednoduchá migrace).
- **F0-D3 — Claim payload: jen volitelná pole.** `backend.temperature` se při
  `NULL` **vynechá** (ne `null` — starý analyzer dělá `float(...)`), `thinking`
  a `effort` se přidají jen při hodnotě ≠ `auto`. Obě strany tak jdou nasadit
  v libovolném pořadí: starý analyzer nová pole ignoruje a chybějící
  `temperature` nahradí `0.0` (= dnešní chování), nový analyzer se starým
  shpd dostane `temperature: 0.0` jako dnes.
- **F0-D4 — Kontrola `stop_reason` v PHP.** `LlmChatResult::isComplete()`:
  `true` pro `end_turn`, `tool_use`, `stop_sequence` a `null` (mocky bez pole),
  jinak `false` (`max_tokens`, `refusal`, `model_context_window_exceeded`, …).
  Klasifikátory a shrnutí při neúplném výsledku zalogují `ErrorLogger::warn`
  (stop reason, model, limit) a vrátí stejný výsledek jako při selhání
  (`null`); **shrnutí se necachuje**. Chat jen loguje.
- **F0-D5 — `MAX_TOKENS` jako strop, ne cena.** Limit je horní mez; na modelu
  bez thinking se spotřeba nezmění. Nové hodnoty: `ContentTagClassifier`
  500 → **8000**, `BookingHistoryClassifier` 4000 → **16000**,
  `DashboardSummaryService` 300 → **2000** (délku shrnutí dál drží prompt).
- **F0-D6 — Chat mimo rozsah.** Tool-loop chatu na modelech s thinking
  vyžaduje posílat thinking bloky zpět (fáze 4 v #85) — dnes je
  `AnthropicLlmClient::finalizeBlocks()` zahazuje. Chat a shrnutí dashboardu
  používají **default backend**, ten se proto na aktuální model nepřepíná.
  Zkouší se přes **druhý backend**: navázaný na AI profil pošty, nastavený
  v `exchange.contentTag.backend`, nebo `--backend` u `booking-history`.
  Zapsat do `docs/ai.md`.
- **F0-D7 — Rozšíření `LlmChatParams`.** Nová pole `?string $thinking = null`,
  `?string $effort = null` (`null` = neposílat). Převod řádku backendu na
  ladicí parametry (`temperature`, `thinking`, `effort`; `auto` → `null`)
  na jednom místě: `AiBackendResolver::tuning(array $backend)`. Používají ho
  všichni čtyři PHP volající — žádné další kopírování `?? 'anthropic'`
  a `base_url` logiky navíc.

## Co je potřeba udělat

### Commit 1 — schéma backendu

- `modules/core/ai/tables/core_ai_backends.jsonc`:
  - `temperature`: `nullable: true`, bez `default` (resp. `default: null`).
  - nové sloupce `thinking` a `effort` ve skupině `tuning` (F0-D2), popisky
    `name:cs` / `name:en`.
- cfgItemy `core.ai.thinkingModes` a `core.ai.effortLevels` v `modules/core/ai/`
  (vzor: `core.mail.senderRuleDispositions` — soubor v `config/` + registrace
  v `module.jsonc`). Popisky `auto`: „Výchozí modelu (neposílat)“ /
  „Model default (not sent)“.
- `modules/core/ai/forms/core_ai_backends.jsonc`: v sekci Ladění přidat
  `thinking` a `effort` (select z cfgItem). Ověřit, že prázdné pole teploty
  se uloží jako `NULL`, ne `0` — pokud ne, doplnit normalizaci pro nullable
  `numeric` v cestě ukládání formuláře (ne speciálním případem pro tuto
  tabulku).
- `AIAnalyzerProvisioner`: default backend zakládá s `temperature => null`;
  nové sloupce nechá na defaultu `auto`.
- `modules/core/ai/tables/core_ai_backends.md`: sekce Ladění — nové sloupce,
  význam `NULL` / `auto`, poznámka „při přepnutí na model, který sampling
  parametry nepřijímá, vymaž teplotu“.
- Testy: `AIAnalyzerProvisionerTest` (NULL teplota v insertu).

### Commit 2 — PHP LLM klient a volající

- `LlmChatParams`: pole `thinking`, `effort` (F0-D7); aktualizovat docblock
  (`temperature` komentář o Opus 4.7/4.8 zobecnit).
- `AnthropicLlmClient::streamChat()`: `thinking` → `$body['thinking'] =
  ['type' => …]`, `effort` → `$body['output_config'] = ['effort' => …]`,
  obojí jen když není `null`.
- `LlmChatResult::isComplete()` (F0-D4).
- `AiBackendResolver::tuning(array $backend): array{temperature: ?float,
  thinking: ?string, effort: ?string}` (F0-D7).
- Volající — předat ladicí parametry z `tuning()` a ošetřit `isComplete()`:
  - `ContentTagClassifier` (+ `MAX_TOKENS` dle F0-D5)
  - `BookingHistoryClassifier` — `baseParams()` i `classifyBatch()`
    (+ `MAX_TOKENS`)
  - `DashboardSummaryService` (+ `MAX_TOKENS`; neúplný výsledek neukládat
    do cache, vrátit `text: null`)
  - `ChatController` — jen předat parametry a zalogovat neúplný výsledek
    (F0-D6); chování smyčky beze změny
- Testy:
  - `AnthropicLlmClientTest`: tělo požadavku obsahuje `thinking`
    / `output_config` jen při nenulové hodnotě; `temperature` chybí při `null`.
  - `ContentTagClassifierTest`, `BookingHistoryClassifierTest`: výsledek se
    `stopReason: 'max_tokens'` → `null` (dávka selhala); `'refusal'` totéž.
  - `DashboardSummaryServiceTest`: useknutý výsledek se necachuje.

### Commit 3 — claim payload a kontrakt

- **Nejdřív dokumentace** (`docs/services.md` §12 bod 1):
  `docs/mail/api-contract.md` §9.2 — příklad odpovědi bez `temperature`,
  odstavec o volitelných polích `temperature`, `thinking`, `effort` (F0-D3):
  chybějící pole = neposílat modelu; analyzer je předává beze změny.
- Kontrakt zatím nemá verzi ani historii změn (`docs/services.md` §12 bod 2):
  doplnit na začátek řádek `**Verze:**` a na konec sekci **Historie změn**
  s prvním záznamem (tato změna, #85).
- `AnalysisController` claim (`'backend' => […]`): `temperature` jen když
  sloupec není `NULL`; `thinking` / `effort` jen když ≠ `auto`.
- Test v `AnalysisControllerTest`: payload bez `temperature` při `NULL`,
  s `thinking`/`effort` při nastavení, beze změny pro dnešní backend
  (`temperature = 0`, `auto`).

### Commit 4 — dokumentace a uzavření

- `docs/ai.md` §4/§5: ladicí parametry backendu (teplota, thinking, effort,
  význam `NULL`/`auto`), kontrola `stop_reason`, omezení chatu a postup
  „zkoušet nový model přes druhý backend“ (F0-D6).
- Hlavička `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Mimo rozsah

- Katalog modelů, AI úlohy, rozdělení na připojení a model (#85 fáze 1).
- Thinking bloky v tool-loopu chatu (#85 fáze 4).
- Validace hodnot `thinking` / `effort` proti modelu.
- Migrace existujících hodnot `temperature = 0` na `NULL`.
- Změna default modelu v provisioneru (zůstává `claude-sonnet-4-5`).

## Ověření (po nasazení obou tasků, na DS v režimu `volný`)

1. `ds-upgrade` — `temperature` je nullable, `thinking`/`effort` existují
   s `auto`; stávající default backend beze změny, analýza pošty běží jako
   dřív (regrese).
2. Založit druhý backend: model `claude-sonnet-5-5`, teplota prázdná,
   `thinking` a `effort` podle zkoušené varianty; klíč přes
   `ai-analyzer-set-key --backend <id>`.
3. Navázat ho na (druhý) AI profil pošty a na `exchange.contentTag.backend`;
   nahrát doklad, spustit analýzu.
4. Kontrola: analýza doběhne, štítky vzniknou; v logu žádné `400` ani varování
   o neúplném výsledku. Porovnat aspoň varianty `between_tools` + `low`
   a `auto` (adaptive, `high`) — čas a `cost_usd` z analýzy.
5. Negativní test: backend s kombinací, kterou model odmítá (např.
   `claude-sonnet-5-5` + `thinking: disabled`) → volání selže chybou 400,
   v logu čitelně (ne tiché `null` bez stopy).

## Hotovo když

- [ ] Commity 1–4 podle rozpisu, `php -l` na změněných souborech.
- [ ] Cílené testy: `vendor/bin/phpunit --filter 'AnthropicLlmClientTest|ContentTagClassifierTest|BookingHistoryClassifierTest|DashboardSummaryServiceTest|AnalysisControllerTest|AIAnalyzerProvisionerTest'`, pak celá sada.
- [ ] Stávající backend (Sonnet 4.5, `temperature = 0`, `auto`) posílá
      identický požadavek jako před změnou (test).
- [ ] `docs/mail/api-contract.md` má verzi a historii změn.
- [ ] Ověření 1–5 provedeno (výsledek do #85).
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.
