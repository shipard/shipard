# Shipard — MCP server

MCP server vystavuje **nástroje** (doménové operace nad daty Shipardu) jako
funkce volatelné jazykovým modelem. Je to společný základ, který konzumuje
vnitřní chat (in-process) i externí MCP klienti (přes HTTP). Přehled celého
subsystému: [`ai.md`](ai.md).

- **Endpoint:** `POST /api/v1/_mcp` (Streamable HTTP, single endpoint)
- **Protokol:** JSON-RPC 2.0
- **Kód:** `src/Api/Mcp/` + `src/Api/Controller/McpController.php`; nástroje v
  `modules/*/*/src/Mcp/`

---

## 1. Protokol (JSON-RPC 2.0)

Tělo požadavku je jeden JSON-RPC objekt `{jsonrpc, id, method, params?}`.
Podporované metody:

| Metoda | Účel | Odpověď |
|--------|------|---------|
| `initialize` | Handshake | `{protocolVersion, capabilities:{tools:{}}, serverInfo}` |
| `notifications/initialized` | Notifikace (bez `id`) | HTTP 202, prázdné tělo |
| `tools/list` | Seznam nástrojů | `{tools: [{name, description, inputSchema}]}` |
| `tools/call` | Spuštění nástroje | MCP `result` (viz §4) |

**`protocolVersion`** se negociuje echem klientovy hodnoty z `initialize`;
fallback na nominální konstantu `McpController::PROTOCOL_VERSION` (`2025-06-18`),
když klient verzi nepošle.

### Chybové kódy a HTTP status

| Situace | JSON-RPC | HTTP |
|---------|----------|------|
| Tělo není platný JSON / špatný tvar obálky | `-32700` / `-32600` (`id=null`) | 200 |
| Neznámá metoda | `-32601` | 200 |
| Neznámý nástroj / špatné `arguments` / chybějící povinný argument | `-32602` | 200 |
| Korektní odpověď (i s `error`) | — | 200 |
| Notifikace | — | 202 |
| Chybějící/neplatný Bearer token | — | 401 |

**Vykonávací chyba nástroje** (např. DB) NENÍ JSON-RPC chyba — vrací se `result`
s `isError: true` a textovým `content`, ať na to LLM umí zareagovat.

---

## 2. Autentizace a scoping

- `AuthMiddleware` vrací bez tokenu `AuthContext::anonymous()` (nezamítá);
  vynucení přihlášení je proto na `McpController` — bez autentizace vrací
  HTTP 401 (transport-level, před JSON-RPC).
- v1 přijímá oba typy tokenu (`shpd_ak_` API klíč i `shpd_st_` session). MCP
  OAuth pro cizí klienty je odložený.
- **DS scoping je automatický.** Zdroj dat je resolvnutý z hostu/cesty před
  dispatchem; nástroj dotazuje DB té DS. Uživatel je v kontextu k dispozici
  (`McpInvocationContext::$auth`).

---

## 3. Rozhraní nástroje

```php
interface McpTool
{
    public function isReadOnly(): bool;     // tier: čtení (true) vs zápis (false)
    public function name(): string;          // 'persons_search' — [a-z0-9_], bez tečky
    public function description(): string;   // text pro LLM (= prompt; viz konvence)
    public function inputSchema(): array;    // JSON Schema (type: object)
    /** @return array doménová obálka {summary, items, pagination} */
    public function call(array $arguments, McpInvocationContext $ctx): array;
}
```

`McpInvocationContext` (readonly) nese `$auth` (AuthContext), `$db`
(DataSourceConnection), `$tables` (array), `$config` (?ConfigRuntime).

---

## 4. Doménová obálka a wire mapping

**Nástroj vrací doménovou obálku**, `McpController` ji mapuje na MCP wire formát
— jediné místo zná wire formát.

Obálka:

```php
[
  'summary'    => 'Nalezeno 3 osob pro "Novák".',
  'items'      => [
    ['ref' => ['type' => 'person', 'id' => 123], 'full_name' => 'Jan Novák', /* … */],
  ],
  'pagination' => ['limit' => 20, 'offset' => 0, 'returned' => 3, 'has_more' => false],
]
```

Wire (`tools/call` result):

```json
{
  "content": [{ "type": "text", "text": "<summary + kompaktní výpis položek>" }],
  "structuredContent": { "summary": "...", "items": [...], "pagination": {...} },
  "isError": false
}
```

`content` text je vždy (univerzálně podporováno); `structuredContent` aditivně
pro klienty, co ho umí.

**Kompaktní řádek položky** (`McpController::compactLine()`) je
`#id popisek — doplňky`. `#id` se vypíše jen u položek s `ref`; doplňky jsou
volitelné klíče, které položka nese — `company_id` (IČO), `vat_id` (DIČ),
`value` + `currency` a `share_pct`. Nástroj, jehož číselný výstup má do
textového kanálu dojít, používá tyto klíče (viz `documents_aggregate`) —
`structuredContent` čtou jen některé klienty, `content` všechny.

Popisek položky bere `compactLine()` z **`label`**, s fallbackem na `full_name`
(osoby). Volitelný klíč **`text`** je víceřádkový obsah položky — připojí se
pod hlavičku a používá ho nástroj, jehož výstupem je sám text
(`help_get_page`); bez něj by do textového kanálu nedošel.

---

## 5. Tiery podle rizika

`isReadOnly()` zařazuje nástroj do tieru. Vnitřní chat nabízí modelu **jen
čtecí nástroje** (`isReadOnly()===true`) a před spuštěním tier re-checkuje
(bezpečnostní invariant — viz [`chat.md`](chat.md)). MCP server na DS ve
stavu `active` vystavuje v `tools/list` všechny; filtr je na konzumentovi.

Na DS ve stavu **`read_only`** ([ds-state.md](ds-state.md) §6, #56 D5) je
filtr na serveru: `McpController` s `readOnly` vrací v `tools/list` jen
čtecí tier (`McpToolRegistry::readOnlyView()` — tentýž filtr, který
používá chat) a `tools/call` na zápisový nástroj odpoví JSON-RPC `-32602`
„Tool … is not available: data source is read-only" — ne HTTP 403, MCP
klienti čtou JSON-RPC. Chat samotný je v `read_only` vypnutý (403 na
routách).

| Tier | `isReadOnly()` | Nástroje |
|------|----------------|----------|
| Čtení | `true` | `persons_search`, `persons_get`, `documents_search`, `documents_aggregate`, `mail_list_pending`, `registry_search`, `help_search`, `help_get_page`, `feed_cards` |
| Zápis (koncepty/akce) | `false` | `mail_draft_document` |

`feed_cards` (UI shells Fáze 5) je první core nástroj v `src/Api/Mcp/`:
čte feed upozornění a návrhů přes `FeedCollector` (tentýž sběr jako
dashboard), volitelný parametr `section` filtruje na jednu sekci navigace,
projekce jen `{kind, feedSection, title, subtitle, navSection, timestamp}`;
karty po stropu per sekce feedu (`FeedResult::$cards`), `pagination.limit`
= strop sekce a `has_more` = některá sekce přetekla (#101, celofeedově
i při filtru `section`). Jazyk
a `AlertCheckRegistry` nejsou v `McpInvocationContext` — injektují se
konstruktorem při registraci v `buildMcpRegistry()` (vzor
`ReportToolSupport`).

---

## 6. Jak přidat nový nástroj

Vzor: [`modules/base/persons/src/Mcp/PersonsSearchTool.php`](../modules/base/persons/src/Mcp/PersonsSearchTool.php).

1. **Vytvoř třídu** v modulu, kam operace patří: `modules/{skupina}/{modul}/src/Mcp/{Název}Tool.php`,
   namespace `Shipard\Module\{Skupina}\{Modul}\Mcp\`, `implements McpTool`.
2. **`name()`** — `modul_operace` se snake_case (`documents_search`). Tečku ne
   (někteří provideři ji ve jméně nepovolí).
3. **`description()` je prompt.** Model se podle něj rozhoduje, kdy nástroj
   zavolat. Buď přesný, uveď kdy použít i **kdy ne** (např. odlišení lokální
   evidence od ARES). Nepřesný popis = model dělá tiše chybné věci.
4. **`inputSchema()`** — JSON Schema (`type: object`), povinná pole v `required`.
5. **`call()`** — vrať **doménovou obálku** `{summary, items, pagination}`:
   - výstup tvaruj pro LLM, ne syrové DB sloupce; každá položka má `ref`
     (`{type, id}`) pro navazující volání;
   - u velkých výsledků počítej se stránkováním (`limit`/`offset`/`has_more`);
   - chybějící povinný argument → `throw new \InvalidArgumentException(...)`
     (controller ho mapuje na `-32602`).
6. **`isReadOnly()`** — `true` jen pokud nástroj **nic nemění**. Zápisové
   nástroje (`false`) chat v1 nenabízí.
7. **Registruj** v `dispatchMcp` (`public/index.php`) přes
   `McpToolRegistry::register(new …Tool())`. Čtecí nástroje jsou bezstavové
   (`new …Tool()`); nástroje se závislostí (např. `mail_draft_document` se
   službou) ji dostávají konstruktorem. Tentýž registr filtruje na read-only
   pro chat smyčku.
8. **Testy** — `tools/list` obsahuje nástroj; `tools/call` happy path + edge
   (prázdný výsledek, chybný argument → `-32602`).

> Pozn.: in-code registrace je záměrná (analogie `dispatchExchange`).
> Module-driven registrace přes `module.jsonc` je odložená — rozhraní je
> navržené tak, aby přechod byl jen přesun místa registrace.

---

## 7. Související dokumenty

- [`ai.md`](ai.md) — přehled subsystému a tiery
- [`chat.md`](chat.md) — vnitřní chat jako konzument (in-process, read-only)
- [`rest-api.md`](rest-api.md) — obecná API vrstva, auth, routing
