# AI — katalog modelů a sestavení požadavku podle schopností modelu (#85 fáze 1a)

**Stav:** hotovo — kód (5 commitů) a ověření 1–5 na dev DS `4l3j` 2026-10-10 (#85); komentář do #85 připravený, zapíše se po potvrzení

## Status / cíl

Znalost „jak s modelem mluvit“ je dnes rozdělená mezi data a kód:

- ladicí parametry backendu se posílají tak, jak jsou zapsané — kombinaci,
  kterou model nepřijímá, vrátí API chybou 400 (fáze 0 to vědomě
  neověřovala, F0-D2),
- ceny drží ruční tabulka `AnthropicPricing`,
- konec modelu řeší konstanta `RETIRED_MODELS` v provisioneru pošty,
- výchozí model je řetězec v provisioneru.

Cíl: **jeden katalog modelů v repu** (ID, úroveň, schopnosti, ceny,
životní cyklus) a **jedno místo, které podle něj tvaruje požadavek** — co
model nepřijímá, se nepošle nebo nahradí nejbližší platnou hodnotou.
Ceník, tabulka vyřazených modelů a výchozí model se čtou z katalogu.

Task **nemění data ani nastavení**: backend dál nese model i ladění,
volající dál berou výchozí backend. Rozdělení na připojení a AI funkce
je navazující task (fáze 1b).

GitHub Issue: shipard/shipard#85 (D2, D27, D29; fáze 1a).

## Návaznost

- `tasks/ai-models-phase0.md` — `LlmChatParams` (`thinking`, `effort`),
  `AiBackendResolver::tuning()`, `AnthropicPricing`, `RETIRED_MODELS`,
  `LlmChatResult::isComplete()`.
- `tasks/mail-analysis-inprocess.md` — runner analýzy jako pátý volající.
- Navazuje fáze 1b (připojení, AI funkce, resolver, D27, D28) a 1c
  (sekce AI v Nastavení).

## Před implementací přečti

- `docs/ai.md` — §4 cesty k modelu, §5 backendy.
- `docs/modules.md` — cfgItemy a compiled config (jak se cfgItem
  registruje v `module.jsonc` a čte přes `ConfigRuntime::cfgItem()`;
  vzor čtení: `AnalysisErrorPresenter`).
- Kód: `src/Core/Ai/` (všechno), `modules/core/ai/module.jsonc`
  a `config/`, `modules/core/mail/src/MailAiProvisioner.php`
  (`RETIRED_MODELS`, výchozí model), `src/Command/DataSource/DsUpgradeCommand.php`
  (přepis vyřazených modelů), `src/Api/Controller/ChatController.php`
  (start konverzace a tool smyčka), místa vzniku klienta:
  `public/index.php` (dvakrát), `BookingHistoryCommand`,
  `AnalysisRunnerFactory`, `RowEnrichmentPipeline`.
- Dokumentace API (zdroj pravdy pro obsah katalogu):
  - <https://platform.claude.com/docs/en/about-claude/models/overview>
  - <https://platform.claude.com/docs/en/models/sonnet-5-5/migration-guide>
  - <https://platform.claude.com/docs/en/about-claude/model-deprecations>
  - <https://platform.claude.com/docs/en/about-claude/pricing>
  - <https://platform.claude.com/docs/en/build-with-claude/claude-in-amazon-bedrock>

## Rozhodnutí k designu (potvrzená)

### Směr (#85, zamčeno 2026-10-10)

- ✓ **D2** — Katalog modelů v repu; sestavení požadavku se řídí
  schopnostmi modelu a API dialektem.
- ✓ **D27** — Výchozí model určuje katalog, ne data (plně až ve fázi 1b;
  zde jen výchozí model provisioneru).
- ✓ **D29** — Chat hlídá katalog: model, jehož tool smyčku klient
  neumí obsloužit, pro asistenta nejde použít.

### Prováděcí (#85, potvrzeno 2026-10-10)

- ✓ **D30 — Katalog je cfgItem `core.ai.models`** v modulu `core.ai`
  (`config/models.jsonc`). Klíč položky = ID modelu na přímém API bez
  datové přípony (`claude-sonnet-4-6`). Položka:
  - `name`, `vendor` (`anthropic`), `dialect` (`anthropic-messages`),
    `order`,
  - `tier`: `fast` | `balanced` | `strong` | `null`,
  - `tierDefault`: `true` u modelu, který je výchozí pro svou úroveň,
  - `platformIds`: ID modelu na platformě — `anthropic`, `bedrock`
    (`anthropic.claude-…`); chybějící platforma = model tam není,
  - `capabilities` (D31), `prices` (D33), `lifecycle` (D33).

  PHP: `Shipard\Core\Ai\ModelCatalog` (`fromConfig(?ConfigRuntime)`,
  `find(string $modelId): ?ModelSpec`, `defaultModel(string $tier)`,
  `costUsd(…)`) a hodnotový objekt `ModelSpec`. `find()` přijme ID
  z kterékoli platformy i s datovou příponou
  (`claude-sonnet-4-5-20250929`, `anthropic.claude-sonnet-5-5`); vyhrává
  nejdelší shoda.
- ✓ **D31 — Schopnosti: jen to, podle čeho se rozhodujeme.**

  | Pole | Význam |
  |------|--------|
  | `temperature` | `true` / `false` — přijímá nevýchozí teplotu |
  | `thinkingModes` | přijímané hodnoty `thinking.type` |
  | `thinkingDefault` | `off` / `adaptive` — chování bez parametru |
  | `effortLevels` | přijímané úrovně; `[]` = parametr nezná |
  | `maxOutputTokens` | horní mez `max_tokens` |
  | `chatToolLoop` | `true` / `false` — tool smyčka funguje bez vracení thinking bloků |

  **`null` u kteréhokoli pole = nevíme → neomezovat** (hodnota se pošle
  beze změny). Co nejde doložit z dokumentace, zůstává `null`; žádné
  odhady.
- ✓ **D32 — Tvarování požadavku na jednom místě.** Nová třída
  `Shipard\Core\Ai\RequestShaper`: z `LlmChatParams` a `ModelSpec` vrátí
  upravené parametry + seznam úprav. Pravidla:
  1. `temperature`: model ji nepřijímá → vynechat.
  2. `thinking`: hodnota není v `thinkingModes` → náhrada `disabled` ↔
     `between_tools` (podle toho, kterou model má); jinak vynechat.
  3. `effort`: úroveň není v `effortLevels` → nejbližší nižší přijímaná;
     model effort nezná → vynechat. Při `between_tools` nejvýš `high`.
  4. `maxTokens` nad `maxOutputTokens` → snížit na mez.

  Volá ho `AnthropicLlmClient::streamChat()` před sestavením těla —
  platí tak pro všech pět volajících bez změny jejich kódu. Klient
  dostává katalog konstruktorem (`?ModelCatalog`, `null` = netvarovat).
  Úpravy se logují jednou za volání (`ErrorLogger::info`, model + seznam
  úprav); model mimo katalog se netvaruje a varování padne jednou za
  proces.
- ✓ **D33 — Ceny a životní cyklus z katalogu.**
  - `prices`: `input`, `output` (USD za milion tokenů), volitelně
    `longPrompt` (`over`, `input`, `output`) pro Haiku 5.5. Položka smí
    nést **jen ceny** (bez schopností) — kvůli modelům, které dnes ceník
    zná a nepoužíváme je.
  - `lifecycle`: `status` (`active` | `deprecated` | `retired`),
    `retiresAt` (pevné datum konce), `retiresNotBefore`, `replacedBy`
    (naše ID modelu, kterým se vyřazený model při `ds-upgrade` přepíše).
  - `AnthropicPricing` zaniká (volající přejdou na
    `ModelCatalog::costUsd()`); `MailAiProvisioner::RETIRED_MODELS`
    zaniká — přepis při `ds-upgrade` čte `replacedBy` u modelů se
    statusem `deprecated` / `retired`.
- ✓ **D34 — Výchozí model provisioneru z katalogu.**
  `ModelCatalog::defaultModel('balanced')` místo řetězce. Katalog má
  `tierDefault` zatím **jen pro `balanced`** (`claude-sonnet-4-6`);
  výchozí modely úrovní `fast` a `strong` se doplní po evaluaci (fáze 2).
- ✓ **D35 — Pojistka chatu (D29).** `ChatController` před prvním voláním
  modelu zjistí model výchozího backendu; má-li `chatToolLoop: false`,
  vrátí chybu `AI_MODEL_NOT_SUPPORTED_FOR_CHAT` s textem pro uživatele
  („Asistent zatím neumí pracovat s modelem …; správce nastaví jiný“)
  a model nevolá. `null` a `true` = beze změny. Pojistka zmizí s fází 4.

### Upřesnění při implementaci (potvrzeno 2026-10-10)

- **Schopnost `thinkingOffMaxEffort`** (rozšíření D31): nejvyšší effort
  přijímaný spolu s vypnutým thinkingem (`disabled` / `between_tools`).
  Dokumentace ho dokládá u Sonnetu 5.5 (`between_tools`), Opusu 5 a Haiku
  5.5 (`disabled`): `high`. Pravidlo 3 v D32 tak platí pro oba vypnuté
  režimy a čte mez z katalogu místo pevného `high` jen pro `between_tools`.
- **`chatToolLoop`** = tool smyčka funguje s výchozím chováním modelu,
  tj. model bez parametru nepřemýšlí (`thinkingDefault: off`). Modely,
  které přemýšlejí ve výchozím stavu, ale přijímají `disabled` (Sonnet 5,
  Haiku 5.5, Opus 5), mají `false` bez výjimky — pojistka zmizí s fází 4.
- **Doručení chyby D35:** JSON 503 `AI_MODEL_NOT_SUPPORTED_FOR_CHAT`
  s modelem v `details`; `frontend/src/api/chat.js` při ne-200 odpovědi
  čte JSON tělo (dřív jen „HTTP 503“), překlad přes `translateError`
  (`{value}` = model). Vedlejší přínos: i `NO_BACKEND` a `LLM_NOT_CONFIGURED`
  se zobrazují přeložené.
- **Regionální prefixy Bedrocku** (`us.anthropic.…`) `find()` nezná —
  fáze 3.
- **Katalog v `ds-upgrade`:** `MailAiProvisioner` dostává katalog
  konstruktorem; `DsUpgradeCommand::loadModelCatalog()` je chráněný seam
  (compiled config po kompilaci, bez něj prázdný katalog → `[WARN]`
  a `[SKIP]` backendu i profilu, nikdy odhad modelu). Testovací subclass
  bere katalog ze souboru v repu.

## Obsah katalogu

Doložené údaje (stav 2026-10-10). Ostatní pole a modely **ověřit na
stránce modelu**; nedoložené = `null`.

| Model | `tier` | teplota | `thinkingModes` | bez parametru | `effortLevels` | `chatToolLoop` |
|-------|--------|---------|-----------------|---------------|----------------|----------------|
| `claude-sonnet-5-5` | balanced | ne | `adaptive`, `between_tools` | adaptive | `low` … `max` včetně `xhigh` | ne |
| `claude-sonnet-5` | balanced | ne | `adaptive`, `disabled` | adaptive | ověřit | ověřit |
| `claude-sonnet-4-6` | balanced, **výchozí** | ano | `adaptive`, `disabled`, `enabled` | off | ověřit | ano |
| `claude-sonnet-4-5` | balanced | ano | `disabled`, `enabled` | off | `[]` | ano |
| `claude-haiku-5-5` | fast | ne | ověřit | ověřit | ověřit | ověřit |
| `claude-haiku-4-5` | fast | ano | `disabled`, `enabled` | off | ověřit | ano |
| `claude-opus-5-5`, `claude-opus-5`, `claude-opus-4-8` | strong | ne | ověřit | ověřit | ověřit | ověřit |

- Teplotu odmítají všechny modely řady 4.7 a novější.
- Hodnotu `enabled` (thinking s rozpočtem tokenů) naše parametry
  neposílají — v katalogu je jen pro úplnost.
- **Ceny:** tabulka z `tasks/ai-models-phase0.md` F0-D9 (ověřená proti
  ceníku). Modely, které dnes zná jen ceník (Opus 4.5–4.7, 4, 4.1, řada 3,
  Fable, Mythos), přejdou do katalogu jako položky jen s cenami.
- **Životní cyklus:** `claude-sonnet-4-5` — `deprecated`, `retiresAt`
  2026-11-30, `replacedBy` `claude-sonnet-4-6` (naše volba přemostění,
  F0-D8; poskytovatel doporučuje 5.5). `retiresNotBefore`:
  `claude-sonnet-4-6` 2027-02-17, `claude-haiku-4-5` 2026-10-15,
  `claude-sonnet-5` 2027-06-30, `claude-sonnet-5-5` 2027-09-28,
  `claude-haiku-5-5` 2027-10-07, `claude-opus-4-8` 2027-05-28,
  `claude-opus-5` 2027-07-24, `claude-opus-5-5` 2027-09-22.
- **Bedrock:** ID `anthropic.<id>` jen u modelů, které stránka Bedrocku
  uvádí (řada 4.7 a novější, Haiku 4.5).

## Scope

### V rozsahu

- cfgItem `core.ai.models`, `ModelCatalog`, `ModelSpec` (D30, D31).
- `RequestShaper` a jeho zapojení do `AnthropicLlmClient`; předání
  katalogu na všech místech vzniku klienta (D32).
- Ceny a přepis vyřazených modelů z katalogu; zánik `AnthropicPricing`
  a `RETIRED_MODELS` (D33).
- Výchozí model provisioneru z katalogu (D34).
- Pojistka chatu (D35) včetně zobrazení chyby v UI asistenta.
- Dokumentace: `docs/ai.md` (nová sekce Katalog modelů: pole, pravidla
  tvarování, jak přidat model), `core_ai_backends.md` (ladění se
  přizpůsobuje modelu), cfgItemy `thinkingModes` / `effortLevels`
  (poznámka „neověřujeme“ přestává platit).

### Mimo rozsah

- Připojení vs. model, AI funkce, resolver, vlastní ID modelu (D1, D3,
  D4, D28) — fáze 1b.
- Sekce AI v Nastavení, výběr modelu ze seznamu ve formuláři (D8) — 1c.
- Výchozí modely úrovní `fast` a `strong`, přechod výchozího modelu na
  řadu 5 — po evaluaci (fáze 2).
- Druhý dialekt, volání přes Bedrock (fáze 3); thinking bloky v tool
  smyčce (fáze 4).
- Upozornění „model brzy končí“ — fáze 1b (až budou modely u funkcí).

## Kroky

Každý krok je samostatný commit.

### 1. Katalog

- `modules/core/ai/config/models.jsonc` + registrace v `module.jsonc`.
  V hlavičce souboru komentář: význam polí, pravidlo „`null` = nevíme“,
  odkazy na zdroje, datum poslední kontroly.
- `ModelCatalog`, `ModelSpec` v `src/Core/Ai/`. Bez compiled configu
  (`null`) je katalog prázdný — nic se netvaruje, ceny jsou 0 s varováním.
- Test `ModelCatalogTest`: `find()` pro ID s datovou příponou, s prefixem
  Bedrocku, nejdelší shoda (`claude-opus-4` vs `claude-opus-4-8`),
  neznámý model; `defaultModel()`; ceny včetně dvou pásem Haiku 5.5
  (převzít případy z `AnthropicPricingTest`).
- Test konzistence katalogu nad skutečným souborem: každá položka má
  `prices`; `tierDefault` nejvýš jednou na úroveň; `replacedBy` ukazuje
  na existující aktivní model; `thinkingModes` a `effortLevels` obsahují
  jen hodnoty z cfgItemů `core.ai.thinkingModes` / `effortLevels`
  (kromě `enabled`).

### 2. Tvarování požadavku

- `RequestShaper` + `RequestShaperTest` (každé pravidlo zvlášť, `null`
  schopnost = beze změny, kombinace `between_tools` + `xhigh`, model
  mimo katalog).
- `AnthropicLlmClient`: konstruktor `?ModelCatalog`; tvarování před
  sestavením těla; logování úprav.
- Předat katalog na místech vzniku klienta (`ModelCatalog::fromConfig`
  z `ConfigRuntime`, který tam už je nebo se dá načíst stejně jako
  v `AnalysisRunnerFactory`).
- `AnthropicLlmClientTest`: `temperature = 0` na modelu řady 5 se
  nepošle; `thinking: disabled` na Sonnetu 5.5 odejde jako
  `between_tools`; bez katalogu identické tělo jako dnes.

### 3. Ceny, vyřazené modely, výchozí model

- `AnalysisRunner` a případní další volající → `ModelCatalog::costUsd()`;
  smazat `AnthropicPricing` a jeho test (případy jsou v kroku 1).
- `MailAiProvisioner`: `RETIRED_MODELS` a pevný název modelu pryč;
  přepis i výchozí model z katalogu. `MailAiProvisionerTest`
  a `DsUpgradeCommandTest` beze změny očekávání.

### 4. Pojistka chatu

- `ChatController`: kontrola před prvním voláním modelu; odpověď ve
  tvaru, který UI asistenta už umí zobrazit jako chybu (ověřit ve
  frontendu; nový kód chyby doplnit do překladů).
- `ChatControllerTest`: `chatToolLoop: false` → chyba, model se nevolá;
  `null` / `true` → beze změny.

### 5. Dokumentace a uzavření

- Dokumenty podle „V rozsahu“; `**Stav:**` + `python3 scripts/tasks-index.py`.

## Ověření (zdroj dat v režimu `volný`)

1. `ds-upgrade` + přegenerování compiled configu; nahrát doklad → analýza
   na výchozím backendu (Sonnet 4.6, teplota 0) doběhne a **tělo
   požadavku je stejné jako před změnou** (test + log bez úprav).
2. Profil se Sonnetem 5.5 a backendem, kde zůstala teplota `0`
   a `thinking: disabled`: analýza doběhne (dřív 400), v logu záznam
   o dvou úpravách.
3. Cena běhu odpovídá ceníku pro Sonnet 4.6, Sonnet 5.5 i Haiku 5.5.
4. Výchozí backend dočasně přepnout na `claude-sonnet-5-5` → asistent
   vrátí srozumitelnou chybu místo pádu; shrnutí dashboardu funguje.
   Vrátit zpět.
5. Druhý `ds-upgrade` nic nepřepisuje; na zdroji dat s backendem
   `claude-sonnet-4-5` (uměle) přepis na 4.6 proběhne jako dřív.

## Pasti

- **Žádné odhady v katalogu.** Špatně vyplněná schopnost je horší než
  `null`: `null` nechá rozhodnout API, chybná hodnota potichu změní
  požadavek. U každé vyplněné hodnoty musí jít dohledat zdroj.
- **Tiché úpravy.** Tvarování mění to, co si správce nastavil. Musí být
  vidět v logu; fáze 1c to ukáže i v Nastavení.
- **Compiled config je per zdroj dat a jazyk.** Katalog nemá lokalizované
  texty, ale čte se stejnou cestou — bez configu se nesmí nic rozbít
  (CLI před `ds-upgrade`, testy).
- **Prefixy.** `find()` nesmí přiřadit `claude-sonnet-4-5` modelu
  `claude-sonnet-4`, ani `claude-opus-4-8` modelu `claude-opus-4`.
- **Gateway hostingu** model jen předává dál — katalog se uplatní na
  straně zdroje dat, na gateway se nic nemění.
- **`enabled`** v `thinkingModes` není v našem cfgItemu
  `core.ai.thinkingModes` — test konzistence ho musí vynechat.

## Hotovo když

- [x] Kroky 1–5 jako samostatné commity, `php -l`, cílené testy
      (`--filter 'ModelCatalogTest|RequestShaperTest|AnthropicLlmClientTest|AnalysisRunnerTest|MailAiProvisionerTest|DsUpgradeCommandTest|ChatControllerTest'`),
      pak celá sada (8882 testů OK).
- [x] Tělo požadavku pro výchozí backend (Sonnet 4.6, teplota 0, `auto`)
      je identické jako před změnou (test).
- [x] Ověření 1–5 provedeno 2026-10-10 na `4l3j` (viz níže); komentář do
      #85 připravený, zapíše se po potvrzení.
- [x] Dokumentace podle kroku 5.
- [x] `**Stav:**` aktualizovaný, `tasks-index.py` spuštěný.

## Výsledek ověření (2026-10-10, dev DS `4l3j`)

| Krok | Výsledek |
|------|----------|
| 1 | `ds-upgrade` bez `[MODEL]` i `[WARN]`, compiled config nese 22 modelů, `tierDefault` jen Sonnet 4.6. Analýza na výchozím backendu (Sonnet 4.6, teplota 0, `auto`) doběhla: 8 632 / 835 tokenů, 12,6 s, 0,0384 USD; v logu žádná úprava požadavku. |
| 2 | Backend Sonnet 5.5 s teplotou `0` a `thinking: disabled` (ve fázi 0 HTTP 400): analýza doběhla, 10 455 / 1 130 tokenů, 6,2 s, 0,0322 USD; log `request shaped to the model capabilities` se dvěma úpravami (`temperature 0 omitted`, `thinking disabled → between_tools`). Vedlejší pozorování: klasifikátor štítků na témže backendu vrátil ne-JSON výstup (`ContentTagClassifier: non-JSON output discarded`) — `between_tools` s výchozím effortem `high`; ve fázi 0 s `low` štítek vyšel. Chování modelu, ne katalogu; na analýze nic nemění. |
| 3 | `cost_usd` tří běhů (Sonnet 4.6, Sonnet 5.5, Haiku 5.5 vč. pásma do 100 000 tokenů) odpovídá `ModelCatalog::costUsd()` na 6 míst. |
| 4 | Výchozí backend dočasně `claude-sonnet-5-5`: `POST /_chat/conversations/{id}/messages` → 503 `AI_MODEL_NOT_SUPPORTED_FOR_CHAT` s `details[0].value` = model; v headless prohlížeči asistent ukáže pruh „Asistent zatím neumí pracovat s modelem claude-sonnet-5-5. Správce nastaví v AI backendu jiný model“. Shrnutí dashboardu po vyprázdnění cache vygenerované na 5.5 (28 textových událostí, 423 / 152 tokenů). Model vrácen na 4.6. |
| 5 | Druhý `ds-upgrade` nic nepřepisuje; backend uměle s `claude-sonnet-4-5-20250929` → `[MODEL] backend 'haiku55' (id=3): claude-sonnet-4-5-20250929 → claude-sonnet-4-6 (retired model)`, další běh bez výpisu; model vrácen na Haiku 5.5. |

Po ověření jsou backendy, profily a zprávy 388 / 389 ve stavu před testem;
smoke uživatel a jeho konverzace smazané.
