# Tabulka: AI backendy (core_ai_backends)

Konfigurace AI providerů — **sdílený pool** (provider, model a šifrovaný
klíč), využívaný analýzou došlé pošty i vnitřním chatem (modul `core/ai`). Per DS
může existovat více
backendů (např. Anthropic + lokální Ollama), právě jeden může mít
`is_default = true`. Per DS se obvykle vystačí s jedním Anthropic backendem
auto-provisioned při `ds-upgrade`.

## Citlivá data

Sloupec `api_key` je typu `encrypted_text` — viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md). Plaintext
neleží v DB; šifrování řeší `AIBackendDocument::beforeSave()` přes
`DsSecretCipher`. Plaintext se nesmí logovat ani vracet do view; do API
response (claim endpoint) se vkládá jen v paměti dočasně po dobu zpracování
zprávy.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `backend_id` | varchar(50), NOT NULL, UNIQUE | Lidský identifikátor (`default`, `claude-opus`) |
| `name` | varchar(100), NOT NULL | Zobrazovaný název v UI |

### Provider

| Sloupec | Typ | Popis |
|---|---|---|
| `provider` | varchar(30), NOT NULL, default `anthropic` | Identifikátor providera. V MVP pouze `anthropic`. |
| `model` | varchar(100), NOT NULL | Model name (`claude-sonnet-4-6`, …). Vyřazené modely přepisuje `ds-upgrade` (`AIAnalyzerProvisioner::RETIRED_MODELS`, viz Životní cyklus). |
| `base_url` | varchar(200) | Volitelný custom endpoint (pro non-default proxy) |

### Přístup (credentials)

| Sloupec | Typ | Popis |
|---|---|---|
| `api_key` | encrypted_text | API klíč šifrovaný `DsSecretCipher`. Ukládá `AIBackendDocument::beforeSave` při dirty change. |

### Ladění (tuning)

| Sloupec | Typ | Popis |
|---|---|---|
| `max_tokens` | int, NOT NULL, default 0 | Max output tokenů na request. `0` = automaticky — spadne na default provideru analyzeru; přebít může nenulová hodnota na AI profilu (kaskáda profil → backend → provider). Chat si při 0 drží vlastní fallback 4096. |
| `temperature` | numeric(3,2), NULL | `NULL` = parametr se neposílá, model běží se svou výchozí teplotou. **Čte ji jen analýza pošty**; chat, shrnutí dashboardu a klasifikátory štítků teplotu neposílají nikdy. Provisioner zakládá backend s `NULL`; existující řádky s `0` zůstávají (pro Sonnet 4.6 platná hodnota). **Při přepnutí backendu na model řady 5 teplotu vymaž** — řada 5 nevýchozí teplotu odmítá (HTTP 400). |
| `thinking` | enumString(15), NOT NULL, default `auto`, cfgItem `core.ai.thinkingModes` | Režim přemýšlení: `auto` = parametr se neposílá; `adaptive` / `between_tools` / `disabled` odchází jako `thinking: {"type": …}`. Které hodnoty model přijímá, se neověřuje — chybu 400 vrátí API (např. `disabled` na Sonnetu 5.5). |
| `effort` | enumString(10), NOT NULL, default `auto`, cfgItem `core.ai.effortLevels` | Úsilí: `auto` = parametr se neposílá; `low` … `max` odchází jako `output_config: {"effort": …}`. Thinking se na řadě 5 počítá do `max_tokens`. |

Ladicí parametry převádí na jednom místě `AiBackendResolver::tuning()`
(`auto` → `null`, `NULL` teplota → `null`); všichni volající z něj berou
`thinking` a `effort`, teplotu jen runner analýzy pošty. Sloupce `thinking`
a `effort` jsou přechodné — katalog modelů (#85 fáze 1) je přesune do AI
úloh. Zadání: `tasks/ai-models-phase0.md` (F0-D1, F0-D2, F0-D7).

### Příznaky (flags)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_default` | boolean, default false | Výchozí backend DS. Smí být `true` jen u jedné řádky (vynuceno aplikačně v `AIBackendDocument::validate`). |
| `is_active` | boolean, default false | Aktivuje se po nastavení `api_key` přes `ai-analyzer-set-key`. |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas založení |
| `created_by` | int → `core_system_users` | Uživatel, který backend založil |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` | tinyint (system) | Stav dokumentu — viz `core.system.docStatesArchive` |
| `docStateMain` | tinyint (system) | Řazení podle stavu |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_backend_id` | unique | `backend_id` | Lidský kód unikátní per DS |
| `idx_is_default` | index | `is_default` | Rychlé vyhledání default backendu při claim |
| `idx_is_active` | index | `is_active` | Filter aktivních backendů |

## Životní cyklus

1. **Auto-provisioning** při `ds-upgrade`: vznikne backend `default`
   s `model=claude-sonnet-4-6`, `temperature=NULL`, `is_default=true`,
   `is_active=false`, `api_key=NULL`. Tentýž běh u **všech** backendů
   jednorázově a idempotentně přepíše vyřazený model
   (`claude-sonnet-4-5`, i s datovou příponou) na jeho náhradu podle
   `AIAnalyzerProvisioner::RETIRED_MODELS` a vypíše `[MODEL]`; ID
   s prefixem platformy (`anthropic.…` na Bedrocku) nechává být.
2. **Nastavení klíče**: admin spustí `bin/shpd-ds ai-analyzer-set-key`,
   který klíč zašifruje a nastaví `is_active=true`.
3. **Claim**: `AnalysisController::claim()` načte default aktivní backend,
   `DsSecretCipher::decrypt()` vrátí plaintext, plaintext se vloží do response
   a okamžitě zapomene.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_ai_profiles](core_mail_ai_profiles.md) | `ai_profiles.backend` → `ai_backends.id` | Profil je vždy vázán na konkrétní backend |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `message_analyses.backend` → `ai_backends.id` | Audit: kdo analyzoval |
