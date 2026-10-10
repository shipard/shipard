# Tabulka: AI funkce (core_ai_function_settings)

Nastavení **AI funkce** per zdroj dat (tasks/ai-functions.md, #85 D38).
AI funkce je to, co modul volá na jazykovém modelu — vytěžení došlé pošty,
klasifikace obsahových štítků, shrnutí dashboardu, chat asistenta.
Deklaruje ji modul v `module.jsonc` (`aiFunctions`: úroveň modelu, výchozí
ladění, požadavky; `docs/modules.md`), kompilace ji složí do cfgItemu
`core.ai.functions`. Tahle tabulka drží jen to, co si zdroj dat **přepsal**:
připojení, model a ladění. Prázdná hodnota (`NULL` / `default`) = podle
deklarace funkce a mapy úrovní `core.ai.tiers`.

Řádky zakládá `ds-upgrade` (`AiFunctionProvisioner`, idempotentně, jeden
na každou funkci deklarovanou aktivním modulem). Uživatel řádky **nezakládá
ani nemaže** — viewer AI funkce nenabízí Přidat, `AiFunctionSettingsDocument`
mazání odmítne. Hotové parametry volání z řádku, deklarace a mapy úrovní
skládá `Shipard\Core\Ai\AiFunctionResolver` (`docs/ai.md`).

Tabulka je v `keepOnReset` — přepsání přežije `ds-reset` stejně jako
připojení s klíčem.

## Struktura

### Funkce (function)

| Sloupec | Typ | Popis |
|---|---|---|
| `function_id` | varchar(80), NOT NULL, UNIQUE | ID z deklarace (`mail.extraction`). Po založení se nemění; neznámé ID (modul už funkci nedeklaruje) řádek nebrání, resolver ho ignoruje. |

### Přepsání (override)

| Sloupec | Typ | Popis |
|---|---|---|
| `connection` | int → `core_ai_backends`, NULL | AI připojení pro funkci. `NULL` = výchozí aktivní připojení zdroje dat. Neaktivní nebo smazané připojení = chyba volání `NO_CONNECTION`. |
| `model` | varchar(100), NULL | ID modelu — z katalogu `core.ai.models`, nebo vlastní (D43). `NULL` = model úrovně funkce z mapy `core.ai.tiers`. Vlastní ID odchází na platformu tak, jak je napsané. |
| `model_like` | varchar(100), NULL | Jen u vlastního ID: model z katalogu, podle kterého se požadavek tvaruje (`RequestShaper`) — povinné, jinak `UNKNOWN_MODEL`. U modelu z katalogu se ignoruje. |
| `thinking` | enumString(15), NOT NULL, default `default`, cfgItem `core.ai.thinkingModes` | `default` = podle deklarace funkce (žádná dnes thinking neposílá), `auto` = výslovně neposílat, jinak hodnota `thinking.type`. Hodnotu, kterou model nepřijímá, nahradí nebo vynechá katalog (`docs/ai.md` §5.1). |
| `effort` | enumString(10), NOT NULL, default `default`, cfgItem `core.ai.effortLevels` | Obdobně `output_config.effort`. |
| `max_tokens` | int, NULL | Strop výstupu. `NULL` = `defaults.maxTokens` z deklarace funkce. Strop, ne cena — na modelech s thinking se přemýšlení počítá do `max_tokens`. |

**`default` není `auto`:** `default` = převzít z deklarace funkce, `auto` =
parametr neposílat. Dnes vedou obě ke stejnému tělu požadavku, protože žádná
funkce thinking ani effort nedeklaruje; po evaluaci (fáze 2) se rozejdou.

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas založení (`ds-upgrade`) |
| `created_by` | int → `core_system_users` | Plní gateway z přihlášeného uživatele; řádky z `ds-upgrade` mají NULL |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_function_id` | unique | `function_id` | Jeden řádek na funkci |
| `idx_connection` | index | `connection` | Dohledání funkcí vázaných na připojení |

## Pořadí zdrojů hodnot

Pro každou hodnotu zvlášť (`AiFunctionResolver`, D40):

1. přepsání volajícího (AI profil pošty, volba příkazu `booking-history`),
2. tento řádek,
3. deklarace funkce (`defaults`, `tier`) a mapa úrovní `core.ai.tiers`.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_ai_backends](core_ai_backends.md) | `connection` → `core_ai_backends.id` | Přepsané připojení (NULL = výchozí) |
| [core_mail_ai_profiles](../../mail/tables/core_mail_ai_profiles.md) | — | Profil pošty přepisuje totéž pro funkci `mail.extraction` o úroveň výš |
