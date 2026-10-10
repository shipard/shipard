# Shipard — AI subsystém

Vestavěný AI asistent („chat nad svými daty") a sdílená infrastruktura pro
volání jazykových modelů. Subsystém stojí na jednom společném základu — **sadě
nástrojů (MCP serveru)** nad daty Shipardu — který konzumuje několik klientů.
Jazykový model je vyměnitelný díl; nástroje jsou napsané jednou.

> Tento dokument je **přehled**. Detaily viz [`mcp-server.md`](mcp-server.md)
> (jak přidat nástroj) a [`chat.md`](chat.md) (orchestrátor a frontend).

---

## 1. Vrstvená mapa

```
  Konzumenti:   vnitřní chat        externí MCP         analýza pošty
                (core/chat,         klienti             (mail-analyze,
                 in-process)        (/_mcp, HTTP)        PHP runner)
                     │                   │                    │
                     └──── nástroje ─────┘                    │ vlastní cesta
                              ▼                               │ (claim/result
              ┌─────────────────────────────────┐            │  jako služby,
              │ MCP nástroje (src/Api/Mcp)       │            │  ne přes MCP)
              │ doménové operace nad daty        │            │
              └───────────────┬─────────────────┘            │
                              ▼                               │
              ┌─────────────────────────────────┐            │
              │ Shipard data (moduly, PHP)       │            │
              └─────────────────────────────────┘            │
                                                              │
  Mozek:   LLM provider ◄───── core/ai backendy ─────────────┘
           (Anthropic)         (provider / model / klíč; sdílené)
```

Klíčový poznatek: **MCP server (= nástroje nad Shipardem) je společný základ,
vnitřní chat je jen jeden z jeho klientů.** Nástroje volá vnitřní chat
in-process (registr je hned vedle), externí klienti přes `/_mcp` po HTTP.
Analýza došlé pošty má vlastní cestu — runner `shpd-ds mail-analyze` v PHP
(fronta → claim → model → zápis, #85 D9); backend i LLM klienta ale sdílí.
Dočasně vedle něj funguje i pull protokol pro Python daemon `ai_analyzer`
(zrušení řeší `tasks/ai-analyzer-removal.md`).

---

## 2. Komponenty a kde leží

| Vrstva | Umístění | Co |
|--------|----------|----|
| Sdílené backendy | `modules/core/ai/` | `core_ai_backends` + `AIBackendDocument`/`Lookup`/`Viewer`; provider, model, šifrovaný klíč |
| MCP server | `src/Api/Mcp/` + `src/Api/Controller/McpController.php`; routa `POST /api/v1/_mcp` | JSON-RPC 2.0 (`initialize`, `tools/list`, `tools/call`), registr nástrojů, mapování obálky |
| Nástroje | `modules/*/*/src/Mcp/` | doménové operace (viz §3) |
| Chat orchestrátor | `src/Api/Controller/ChatController.php` + `modules/core/chat/` | konverzace, SSE smyčka, in-process volání nástrojů |
| LLM klient | `src/Core/Ai/` (`LlmClient`, `AnthropicLlmClient`, `AiBackendResolver`, `LlmRetry`, `AnthropicPricing`) | streamovaný Anthropic Messages API (+ tool-use, timeouty); resolver default backendu + dešifrování klíče; opakování přechodných chyb; tabulka cen |
| Analýza pošty | `modules/core/mail/src/Analysis/` + `src/Command/DataSource/MailAnalyzeCommand.php` | služby fronty / claimu / zápisu výsledku (sdílené s pull endpointy), příprava příloh, prompt v Twig sandboxu, parser výstupu, runner a sloty souběhu — viz [`../modules/core/mail/docs/ai-analysis.md`](../modules/core/mail/docs/ai-analysis.md) § Analýza v procesu |
| Dashboard shrnutí | `src/Core/Dashboard/DashboardSummaryService.php` + `modules/core/ai/tables/core_ai_dashboard_summary.jsonc` | generované shrnutí feedu (SSE, cache dle hashe digestu) — viz [`dashboard.md`](dashboard.md) §11 |
| Frontend | `frontend/src/components/chat/` + `api/chat.js` | pohled „Chat", SSE konzumace přes `fetch` + reader |

---

## 3. Katalog nástrojů a tiery podle rizika

Nástroje se neřadí podle modulů, ale podle **rizika** — to určuje, kolik
samostatnosti dostane AI. Vnitřní chat v1 nabízí modelu **jen čtecí tier**
(`McpTool::isReadOnly()`).

| Tier | Brzda | Nástroje |
|------|-------|----------|
| **Čtení** | žádná (bezpečné) | `persons_search`, `persons_get`, `documents_search`, `documents_aggregate`, `mail_list_pending`, `registry_search`, `help_search`, `help_get_page` |
| **Koncepty** | zápis do `docState` Konceptu (10) + lidská revize; `autoCreateMode='safe'` (nezakládá master data) | `mail_draft_document` |
| **Akce** | (potvrzení / zatím nezavedeno) | — |

`documents_search` vrací **konkrétní doklady**, `documents_aggregate` **součty
a počty** seskupené podle dimenze (partner / typ dokladu / fiskální měsíc /
období DPH) — žebříčky a časové řady. Agregace patří do SQL, ne do sčítání
odstránkovaných výsledků modelem.

Katalog roste podle schopností systému: nástroj smí tvrdit jen to, co data
umí pravdivě zodpovědět — a jen to, co vrací **on sám** (např. „po splatnosti"
ano, „neuhrazeno" ne: stav úhrady žádný z dokladových nástrojů nevrací).

---

## 4. Cesty k jazykovému modelu

| Cesta | Kdo volá LLM | Režim | Nástroje |
|-------|--------------|-------|----------|
| **Analýza pošty** | PHP `AnalysisRunner` (CLI `shpd-ds mail-analyze`: spawn po příjmu / předzpracování / reanalýze + minutový sweep) přes `AnthropicLlmClient`; dočasně i Python daemon `ai_analyzer` přes pull protokol `AnalysisController` | strukturovaný výstup (JSON dle `output_schema` profilu), streamovaně, timeouty 180 / 840 s, opakování přechodných chyb | — |
| **Vnitřní chat** | PHP `AnthropicLlmClient` (in-process) | streamovaně (SSE), tool-use smyčka | čtecí MCP nástroje |
| **Dashboard shrnutí** | PHP `AnthropicLlmClient` přes `DashboardSummaryService` | streamovaně (SSE), **bez tools**, `maxTokens` 2000 (délku drží prompt) | — |
| **Klasifikace štítků** | PHP `ContentTagClassifier` (obsahová eskalace řádků při analýze) a `BookingHistoryClassifier` (CLI `booking-history`) | jedno volání na doklad / dávku textů, `maxTokens` 8000 / 16000 | — |

Všechny cesty čtou backend (provider/model/klíč) z `core_ai_backends`; default
backend na PHP straně resolvuje `AiBackendResolver`, analýza pošty bere
profil a backend z claimu (`AnalysisClaimService`). Souběh analýz hlídá limit
per server `ai.analysis.maxConcurrent` (sloty `flock`), ne AI gateway
hostingu (#85 D11, D15).

`max_tokens` je kaskáda **AI profil → backend → default runneru**
(`AnalysisRunner::DEFAULT_MAX_TOKENS` = 32768; démon drží totéž číslo ve
svém provideru); `0` = nenastaveno, spadni níž. Jediné skutečné číslo žije
v kódu — limit tak nezkamení v datech každého DS. Chat backendový
`max_tokens` respektuje, při 0/NULL drží vlastní fallback 4096
(`ChatController`); dashboard shrnutí a klasifikátory mají vlastní konstanty
a backend limit nečtou. Na modelech s thinking se přemýšlení počítá do
`max_tokens`, proto jsou tyhle konstanty **strop, ne cena** — řádově vyšší
než délka odpovědi (2000 / 8000 / 16000; #85 F0-D5). Na modelu bez thinking
se spotřeba nemění.

**Ladicí parametry backendu** (`core_ai_backends`, #85 F0-D1, F0-D2, F0-D7):
`temperature` (NULL = neposílat), `thinking` a `effort` (`auto` = neposílat,
jinak `thinking: {type}` / `output_config: {effort}`). Na parametry je
převádí jediné místo, `AiBackendResolver::tuning()`; `thinking` a `effort`
z něj berou všichni volající, **teplotu jen runner analýzy pošty** — chat,
shrnutí i klasifikátory posílají `temperature: null` a posílat nezačnou
(existující řádky mají `0`, změnilo by se jim chování). Hodnoty se proti
modelu neověřují: nepřijatelnou kombinaci (řada 5 s nevýchozí teplotou nebo
`thinking: disabled`, `between_tools` s `xhigh` / `max`) vrátí API jako
HTTP 400 a volání selže čitelně v logu, u analýzy pošty jako `config_error`
v tabu Analýzy. Validaci přinese katalog modelů (fáze 1).

**Kontrola `stop_reason`** (#85 F0-D4): `LlmChatResult::isComplete()` je
`true` jen pro `end_turn`, `tool_use`, `stop_sequence` a chybějící stop
reason; `max_tokens`, `refusal` (bezpečnostní klasifikátor řady 5, může
zasáhnout i neškodný obsah), `model_context_window_exceeded` a nové hodnoty
jsou neúplný výsledek. Runner analýzy ho ukládá jako `ai_error` bez
opakování (`anthropic: stop_reason <hodnota>`, u `max_tokens` dnešní
hláška), klasifikátory a shrnutí vrací `null` a **shrnutí se necachuje**,
chat jen loguje.

Výsledek extrakce navíc prochází obohacením řádků (`RowEnrichmentPipeline`):
deterministická vrstva z historie dokladů partnera (`RowHistoryEnricher`,
bez LLM volání) + obsahová eskalace pro nepokryté řádky (klasifikace do
taxonomie štítků — pravidlem IČO, jinak levným LLM voláním) — viz
`modules/core/mail/docs/ai-analysis.md`, sekce „Obohacení řádků z historie"
a „Obsahová eskalace (content tags)".

**Soukromí digestu shrnutí**: prompt shrnutí obsahuje titulky karet
(partneři/částky z hlaviček dokladů) — stejná data, jaká analyzer LLM už
posílá při extrakci; žádná nová datová hranice. Plný `canonical_json` se do
promptu nikdy nedává.

---

## 5. Backendy a konfigurace

`core_ai_backends` je **sdílený pool** providerů (provider, model, šifrovaný
klíč). Per DS může být víc backendů, právě jeden `is_default`. Detaily sloupců:
[`core_ai_backends.md`](../modules/core/ai/tables/core_ai_backends.md).

- Klíč je šifrovaný přes `DsSecretCipher` — viz [`operations/secrets.md`](operations/secrets.md).
- Nastavení klíče: `bin/shpd-ds ai-analyzer-set-key --backend default --api-key <api-key>` (aktivuje backend). Auto-provisioning vytvoří `default` backend při `ds-upgrade`.
- **Výchozí model a přemostění (#85 F0-D8):** nový backend vzniká
  s `claude-sonnet-4-6` a `temperature` NULL. Sonnet 4.5 je od 30. 9. 2026
  deprecated a **30. 11. 2026 končí**; `ds-upgrade` proto u všech backendů
  jednorázově přepíše `claude-sonnet-4-5` (i s datovou příponou) na 4.6
  podle `AIAnalyzerProvisioner::RETIRED_MODELS` a vypíše `[MODEL]`. ID
  s prefixem platformy (`anthropic.…`) nechává být. Sonnet 4.6 je stejná
  cena i tokenizer, přijímá `temperature` a bez parametru nepřemýšlí —
  chování se nemění. **Aktivní je nejméně do 17. 2. 2027**; do té doby musí
  proběhnout fáze 4 (thinking bloky v tool smyčce chatu) a přechod na řadu 5.
- **Chat zůstává na výchozím backendu se Sonnetem 4.6 (#85 F0-D6):** tool
  smyčka na modelech s thinking vyžaduje vracet thinking bloky beze změny
  a historii jen přidávat — `AnthropicLlmClient::finalizeBlocks()` je dnes
  zahazuje. Totéž platí pro shrnutí dashboardu (sdílí výchozí backend).
- **Jak zkoušet model řady 5 (`claude-sonnet-5-5`, `claude-haiku-5-5`)
  přes druhý backend:** založit backend v Nastavení → AI backendy
  (teplota prázdná, thinking / effort `auto` nebo konkrétní hodnota),
  klíč přes `ai-analyzer-set-key --backend <kód>`; pro analýzu pošty
  druhý AI profil s tímto backendem (reanalýza zprávy s profilem, nebo
  `profile_override`), pro štítky nastavení `exchange.contentTag.backend`
  = id backendu, pro historii účtování `booking-history --backend`. Co
  pro řadu 5 platí (odmítá teplotu, bez `thinking` přemýšlí adaptivně,
  `between_tools` jen s effortem do `high`, `refusal`, o ~30 % víc tokenů
  na stejný text, odmítá `tool_choice` any/tool a prefill):
  `tasks/ai-models-phase0.md`. Ceny drží `AnthropicPricing` (F0-D9,
  dočasně do katalogu modelů).
- **AI přes hosting gateway (D5/D6):** DS hostovaný pod portálem může místo
  vlastního klíče používat AI gateway hostingu — backend má `base_url` =
  gateway (`…/api/v1/_hosting/ai-gw`) a `api_key` = gateway token
  (`shpd_gw_…`). Na straně DS se nemění žádný kód: `AnthropicLlmClient`
  i Python analyzer si na `base_url` sami připojují `/v1/messages`
  a autentizují se `x-api-key`. Zápis: `ai-analyzer-set-key --backend
  default --api-key shpd_gw_… --base-url https://portal…/_hosting/ai-gw`
  (u nových DS to dělá provisioning agent automaticky). **Vlastní klíč
  zůstává rovnocennou cestou** (D6) — `--base-url ''` vrátí backend na
  přímé Anthropic API. Detaily gateway: [`hosting.md`](hosting.md) §5.5,
  runbook [`operations/ai-gateway.md`](operations/ai-gateway.md).
- **Lifecycle:** jediné ruční kroky jsou jednorázové při prvním zřízení DS —
  `ai-analyzer-set-key` (klíč backendu) a `ai-analyzer-setup` (API klíč
  analyzeru). Všechno ostatní drží `ds-upgrade` automaticky a bezpodmínečně
  (i pod `skipProvisioning`): user `_ai_analyzer`, default backend, default
  profil + version sync profilu ze šablony. `ds-reset` backendy s klíči,
  profily i uživatele/API klíče zachovává (`keepOnReset`), takže reset ani
  upgrade žádnou ruční AI akci nevyžadují.
- **Provider scope:** v1 jen `anthropic`; rozhraní `LlmClient` drží dveře pro
  další providery (lokální, OpenAI) otevřené, aniž by se předčasně abstrahoval
  formát streamu.

---

## 6. Bezpečnostní zásady

- **Auth + DS scoping.** Každý nástroj běží v rámci přihlášeného uživatele a
  jeho zdroje dat (DS je resolvnutý z hostu/cesty před dispatchem). MCP server
  nesmí být cesta, jak obejít oprávnění.
- **Read-only invariant chatu.** Smyčka nabízí modelu a spouští **jen** nástroje
  s `isReadOnly()===true` — i kdyby si model vyžádal jiný či zápisový nástroj
  (vrátí se `tool_result` s `is_error`, nespustí se).
- **Brzda u konceptů.** `mail_draft_document` zakládá jen **Koncept**
  (`targetDocState=10`) a jede `autoCreateMode='safe'` — nikdy nezakládá novou
  master data (dodavatele/položky) ani nefinalizuje doklad; to dělá člověk přes
  stavový automat dokladu.
- **Bez MCP OAuth v1.** Cizí klienti se autentizují stávajícím Bearer tokenem /
  API klíčem (first-party); MCP OAuth flow je odložený.

---

## 7. Datum a kontext v chatu

Jazykový model nemá vlastní smysl pro „dnešek". `ChatController::systemPrompt()`
proto k systémovému promptu při každém požadavku **přilepí aktuální datum** a
instrukci „neodhaduj podle tréninkových dat — ověř nástrojem". Bez toho model
spadne na své tréninkové datum a může pokládat současný rok za budoucnost.

---

## 8. Související dokumenty

- [`mcp-server.md`](mcp-server.md) — MCP server a jak přidat nástroj (dev guide)
- [`chat.md`](chat.md) — orchestrátor, SSE kontrakt, datový model, frontend
- [`core_ai_backends.md`](../modules/core/ai/tables/core_ai_backends.md),
  [`core_chat_conversations.md`](../modules/core/chat/tables/core_chat_conversations.md),
  [`core_chat_messages.md`](../modules/core/chat/tables/core_chat_messages.md)
- [`operations/secrets.md`](operations/secrets.md) — šifrování klíčů
- [`cli.md`](cli.md) — `ai-analyzer-set-key` a další příkazy
- [`mail/api-contract.md`](mail/api-contract.md) — analýza došlé pošty (sousední cesta)
