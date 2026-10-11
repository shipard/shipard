# AI asistent — modely s thinkingem: thinking bloky v tool smyčce (#85 fáze 4)

**Stav:** hotovo — 2026-10-11 (5 commitů, ověření 1–7 na 4l3j; `ds-upgrade` jen 4l3j, zbývá alfa a komentář #85)

## Status / cíl

Asistent dnes funguje jen na modelech, které bez parametru nepřemýšlejí
(Sonnet 4.6 a starší). Modely řady 5 v tool smyčce vyžadují, aby se jim
jejich thinking bloky vracely beze změny; `AnthropicLlmClient` je ale ze
streamu vůbec nesbírá, takže druhý krok smyčky by skončil chybou. Proto
je v resolveru pojistka (D35), která asistentovi takový model zakazuje.

Sonnet 4.6 je aktivní nejméně do 17. 2. 2027 a nový endpoint Bedrocku
(EU inference, fáze 3) starší modely nenabízí. Cíl: **asistent funguje na
kterémkoli modelu z katalogu** — klient thinking bloky zachytí a v rámci
jedné otázky je vrací; pojistka zaniká.

Výchozí model asistenta se **nemění** (mapa úrovní zůstává na Sonnetu 4.6).

GitHub Issue: shipard/shipard#85 (D29, D53–D56; fáze 4).

## Návaznost

- `tasks/ai-model-catalog.md` — schopnost `chatToolLoop`, pojistka D35.
- `tasks/ai-functions.md` — `AiFunctionResolver`
  (`MODEL_NOT_SUPPORTED_FOR_FUNCTION`), funkce `chat.default`.
- `tasks/ai-settings-section.md` — texty stavu resolveru v detailu funkce
  a nápověda; **tento task běží po něm** (mění stejné texty).
- `tasks/chat-phase2a-streaming.md`, `tasks/chat-phase2b-tools.md`,
  `tasks/chat-phase3-ui.md` — streaming, tool smyčka a UI asistenta.

## Před implementací přečti

- `docs/ai.md` — asistent, katalog, resolver.
- Kód:
  - `src/Core/Ai/AnthropicLlmClient.php` — `dispatchEvent()`,
    `finalizeBlocks()`, `normalizeMessages()`; `LlmClient`,
    `LlmChatResult`.
  - `src/Api/Controller/ChatController.php` — `runAgenticLoop()`,
    `insertMessage()`, `buildAnthropicMessages()`, `aiFunctionError()`.
  - `src/Core/Ai/AiFunctionResolver.php` (`assertSupported()`),
    `ModelSpec`, `modules/core/ai/config/models.jsonc`.
  - `frontend/src/api/chat.js` (události SSE) a komponenta asistenta.
- Dokumentace API (zdroj pravdy):
  - <https://platform.claude.com/docs/en/build-with-claude/preserved-thinking>
  - <https://platform.claude.com/docs/en/build-with-claude/thinking>
  - <https://platform.claude.com/docs/en/models/sonnet-5-5/migration-guide>

### Co platí pro thinking bloky (ověřeno 2026-10-11)

- V tool smyčce se asistentův tah vrací **přesně tak, jak přišel**: každý
  blok, ve stejném pořadí, **včetně thinking bloků s prázdným textem**
  (na řadě 5.5 je text úvahy ve výchozím stavu prázdný a nese ho
  `signature`). Blok vrácený s prázdnou `signature` selže.
- Ve streamu blok přijde jako `content_block_start` (typ `thinking`),
  volitelné `thinking_delta`, **`signature_delta`** a `content_block_stop`.
  Existuje i typ `redacted_thinking` (pole `data`).
- Řada 5.5 ověřuje **prefix** každého vráceného thinking bloku: `system`,
  `tools` a všechny zprávy před ním musí být stejné jako při jeho vzniku,
  jinak 400 `invalid_request_error` (účty založené od 31. 8. 2026
  ve výchozím stavu).
- Thinking bloky smí klient z historie **odebrat od začátku nebo všechny**;
  model jen přijde o dřívější úvahy. Nesmí vzniknout mezera (odebrat
  prostřední a nechat pozdější) a odebraný blok se nesmí vrátit.
- Bloky Sonnetu 5.5 a Haiku 5.5 jsou vázané na účet, který je vytvořil.
- Thinking se počítá do `max_tokens` a účtuje jako výstup.
- Text mezi voláními nástrojů chodí na Sonnetu 5.5 jako thinking blok,
  ne jako `text`.

## Rozhodnutí k designu (potvrzená)

- ✓ **D53 — Thinking bloky jen uvnitř běžící tool smyčky.** Klient je ze
  streamu zachytí a `ChatController` je v rámci jedné otázky uživatele
  vrací modelu beze změny. **Do databáze se neukládají a do dalších
  otázek se neposílají** — historie z databáze je vždy bez nich.
  Důvod: náš `system` prompt nese dnešní datum a seznam nástrojů se mění
  s nasazením, takže uchování úvah přes celou konverzaci by vyžadovalo
  zmrazit `system` i `tools` pro každou konverzaci. Uvnitř jedné smyčky
  se nemění nic, kontrola prefixu nemá na čem selhat. Cena: model si
  úvahy mezi otázkami tvoří znovu (jednotky procent výstupních tokenů).
- ✓ **D54 — Pojistka chatu zaniká.** Kontrola `assertSupported()`
  v resolveru, kód `MODEL_NOT_SUPPORTED_FOR_FUNCTION`, chyba
  `AI_MODEL_NOT_SUPPORTED_FOR_CHAT` a schopnost `chatToolLoop` v katalogu
  mizí. `requires: ["tools"]` v deklaraci funkce zůstává jako popis.
- ✓ **D55 — Strop tokenů asistenta 16 000** (deklarace `chat.default`,
  dnes 4096) — thinking se počítá do limitu.
- ✓ **D56 — Indikace přemýšlení.** Nová událost SSE `thinking` při
  začátku thinking bloku; UI ukáže „Přemýšlím…“ do prvního textu,
  volání nástroje nebo konce odpovědi. Text úvah se nezobrazuje ani
  nepřenáší.

## Scope

### V rozsahu

- Sběr thinking bloků v `AnthropicLlmClient`, oznámení začátku úvahy.
- Tool smyčka: vracení bloků v rámci otázky, ukládání bez nich.
- Zánik pojistky a schopnosti `chatToolLoop` (D54), strop tokenů (D55).
- Událost `thinking` a indikace v UI (D56).
- Dokumentace a nápověda.

### Mimo rozsah

- Uchování úvah přes celou konverzaci (zmrazený `system` / `tools`,
  `prefix_mismatch_behavior`).
- Zobrazení shrnutí úvah (`thinking.display`).
- `chat.deep` a přepínač silnějšího modelu v UI.
- Prompt caching.
- Změna výchozího modelu nebo výchozího ladění asistenta (po evaluaci,
  #103).

## Kroky

Každý krok je samostatný commit.

### 1. Klient: thinking bloky ze streamu

- `dispatchEvent()`: `content_block_start` typu `thinking` → blok
  `{type, thinking, signature}`; `thinking_delta` → připojit k `thinking`;
  `signature_delta` → připojit k `signature`. Typ `redacted_thinking` →
  blok uložit tak, jak přišel. Neznámý typ bloku → jednou zalogovat
  varování (dnes se tiše zahodí).
- `finalizeBlocks()`: thinking bloky zařadit do `contentBlocks` na jejich
  pozici (pořadí podle indexu). Blok bez textu **nevynechat**.
- `LlmClient::streamChat()`: třetí, volitelný parametr
  `?callable $onThinking = null` — zavolá se jednou při začátku každého
  thinking bloku (bez textu). Upravit všechny implementace a falešné
  klienty v testech.
- `LlmChatResult`: pomocník `contentWithoutThinking()` (bloky bez
  `thinking` / `redacted_thinking`); `text` a `toolUses` beze změny.
- `normalizeMessages()`: thinking bloky nechat přesně tak, jak jsou
  (žádné přetypování, žádné vynechání prázdných polí).
- Testy (`AnthropicLlmClientTest`, SSE fixtury): thinking blok s textem;
  s prázdným textem a `signature_delta`; `redacted_thinking`; pořadí
  `[thinking, text, tool_use]` zachováno; `onThinking` voláno jednou na
  blok; odpověď bez thinking beze změny.

### 2. Tool smyčka

- `runAgenticLoop()`:
  - do `$messages` pro další krok téže smyčky jde asistentův tah
    **celý** (`contentBlocks`),
  - `insertMessage()` dostává `contentWithoutThinking()`.
- `buildAnthropicMessages()`: obranně odfiltrovat thinking bloky i ze
  starých řádků (dnes tam žádné nejsou) a bloky `text` s prázdným textem;
  asistentův tah, kterému nezbude žádný blok, do požadavku neposílat.
- Předat `onThinking` → `sse('thinking', [])`.
- Testy (`ChatControllerTest`): druhý požadavek téže smyčky obsahuje
  thinking blok z prvního, beze změny a na stejné pozici; uložená zpráva
  ho neobsahuje; první požadavek další otázky neobsahuje žádný thinking
  blok; událost `thinking` se odešle; tah jen s thinking a `tool_use`
  se uloží jako `tool_use`.

### 3. Zánik pojistky a strop tokenů

- `AiFunctionResolver::assertSupported()` a
  `AiFunctionException::MODEL_NOT_SUPPORTED_FOR_FUNCTION` pryč;
  `ChatController::aiFunctionError()` bez větve
  `AI_MODEL_NOT_SUPPORTED_FOR_CHAT`; překlady chyby ve frontendu.
- Katalog: `chatToolLoop` z `models.jsonc`, `ModelSpec`, testu
  konzistence, přehledu modelů a z textů detailu funkce
  (`ai-settings-section`).
- `chat.default`: `maxTokens` 16000.
- Testy resolveru a controlleru upravit (model řady 5 pro asistenta
  projde).

### 4. UI

- `frontend/src/api/chat.js`: událost `thinking`; komponenta asistenta:
  stav „Přemýšlím…“ (překlady cs / en) do další události; `npm run build`.

### 5. Dokumentace a uzavření

- `docs/ai.md` — asistent a thinking (pravidlo D53 a proč), katalog bez
  `chatToolLoop`, resolver bez pojistky.
- `help/` — `co-dnes-nejde.md` a stránka o AI z fáze 1c: omezení
  asistenta na modely bez thinkingu zmizelo; `scripts/help-index.py`.
- `**Stav:**` + `python3 scripts/tasks-index.py`.

## Ověření (zdroj dat v režimu `volný`)

Provedeno 2026-10-11 na 4l3j (dočasný admin `smoke-thinking`, po testu
smazaný i s relacemi a konverzacemi; headless Chromium + `curl -sN`;
otázka „najdi firmu v adresáři a spočítej vydané faktury“ = dva nástroje).

1. ✓ Výchozí nastavení (Sonnet 4.6): dva nástroje v jednom tahu, odpověď
   za 5,0 s, 12 044 / 190 tokenů, uložené zprávy bez thinking bloku.
2. ✓ `chat.default` = `claude-sonnet-5-5`: `thinking` před nástroji, dva
   nástroje, odpověď za 4,7 s, 14 783 / 367 tokenů, žádné 400; UI
   ukázalo „Přemýšlím…“.
3. ✓ Druhá otázka v téže konverzaci (`curl`): `thinking` → `tool-call` →
   text → `message-complete`, 16 195 / 295 tokenů.
4. ✓ Konverzace z 10. 10. (vedená na 4.6, jiný den v system promptu)
   + nová otázka na 5.5: `thinking`, 2× `tool-call`, odpověď, 18 463 / 394
   tokenů; konverzace vrácená původnímu uživateli vč. tokenů a `modified`.
5. ✓ `thinking: between_tools` + Sonnet 5.5: odpověď za 6,2 s, model
   žádnou úvahu mezi nástroji neudělal. `claude-haiku-5-5`: 2× `tool-call`
   → `thinking` → text, 14 675 / 327 tokenů. Cesta „Přemýšlím…“ po čipech
   nástrojů v UI potvrzená deterministicky podvrženým proudem (rámce po
   250 ms; `MutationObserver` zachytil vložení indikátoru při jednom čipu
   a jeho zhasnutí s prvním textem).
6. ✓ `core_chat_messages`: v 26 zprávách šesti konverzací žádný
   `thinking`, `redacted_thinking` ani `signature`.
7. ✓ Vráceno na výchozí (model podle úrovně, thinking `default`). Stejný
   dotaz: 4.6 = 5,0 s a 12 044 / 190 tokenů; 5.5 = 4,7 s a 14 783 / 367
   tokenů (úvahy ≈ +180 výstupních tokenů, tahy s `tool_use` 288 místo 148).

Neověřeno: měření spotřeby gateway hostingu na odpovědi s thinkingem
(gn5c bez chatu s reálným tokenem). `GwUsageExtractor` čte
`message_delta.usage.output_tokens`, kam API thinking počítá — kód se
neměnil.

## Pasti

- **Prázdný thinking blok je platný a povinný.** Serializace nesmí
  zahodit prázdné `thinking` ani změnit pořadí bloků — jinak 400 až
  u druhého kroku smyčky, tedy jen u dotazů s nástroji.
- **`signature` je dlouhý neprůhledný řetězec** — nelogovat, neukládat,
  neposílat do UI.
- **Mezera v řadě bloků.** V rámci smyčky vracet všechny thinking bloky
  všech kroků té smyčky; nevybírat jen poslední.
- **Zprávy bez obsahu.** Po odfiltrování thinking může tahu zbýt prázdný
  text; API prázdný textový blok odmítá.
- **Ostatní volající** (`analysis`, klasifikátory, shrnutí) čtou
  `$result->text`; `contentBlocks` s thinking bloky jim nesmí změnit
  chování — ověřit grepem.
- **`max_tokens` a `between_tools`.** Na Sonnetu 5.5 je `between_tools`
  povolené jen do effortu `high` — hlídá `RequestShaper`.
- **Gateway hostingu** stream jen přeposílá; nové typy událostí jí
  nevadí, ale ověřit měření spotřeby na odpovědi s thinkingem.

## Hotovo když

- [x] Kroky 1–5 jako samostatné commity, `php -l`, cílené testy
      (`--filter 'AnthropicLlmClientTest|ChatControllerTest|AiFunctionResolverTest|ModelCatalog'`),
      celá sada (9 001 testů), `npm run build`.
- [x] Ověření 1–7 provedeno; výsledek do #85 po pushi.
- [x] `**Stav:**` aktualizovaný, `tasks-index.py` a `help-index.py`
      spuštěné.

## Poznámky z implementace (2026-10-11)

- Překlad `chat.thinking` („Přemýšlím…“) už existoval a `ChatThread` ho
  ukazoval, dokud nepřišel první text. D56 je tedy stav `thinking` ve
  store a indikátor i po čipech nástrojů (dřív bublina po `tool-call`
  mlčela); nové překlady nejsou.
- `onThinking` se volá i pro `redacted_thinking`. Neznámý typ bloku
  zaloguje varování jednou za proces per typ
  (`resetUnknownBlockTypeWarningsForTesting`).
- Tah jen s thinking blokem (bez textu i nástroje) končí jako finální
  odpověď a uloží se jako prázdný text; `buildAnthropicMessages()` ho
  vynechá (API dva uživatelské tahy za sebou sloučí).
- `AiFunction::requiresTools()` zůstal jako popisný accessor (symetrie
  s `requiresPdf()`, používá ho test registru); resolver ho nevolá.
- `ScriptedLlmClient` v `ChatControllerTest` volá `onThinking` per thinking
  blok výsledku a drží všechna `calls`. `AiTestCommandTest`: pořadí funkcí
  není mail / dashboard / chat — asserty nezávislé na pořadí.
- Test varování: `ErrorLogger` každý zápis zrcadlí přes `error_log()`
  (CLI = stderr) — v testu `ini_set('error_log', '/dev/null')`.
- Headless smoke streamu: Svelte vykreslí mezistav jen když rámce přijdou
  v různých chuncích; `route.fulfill` jedním tělem ho neukáže,
  `route.continue({url})` na jiný port visí. Funguje mock `window.fetch`
  v `addInitScript` s `ReadableStream` a prodlevou mezi rámci.
- Chování modelů (pozorování, ne pravidlo): Sonnet 5.5 přemýšlí před
  prvním nástrojem, po výsledcích často rovnou text; Haiku 5.5 úvaha až
  po výsledcích; `between_tools` na Sonnetu 5.5 žádný thinking blok.
