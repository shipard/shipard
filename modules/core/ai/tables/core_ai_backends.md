# Tabulka: AI připojení (core_ai_backends)

**AI připojení** = přístup k platformě jazykových modelů: platforma,
volitelná adresa (AI gateway hostingu) a šifrovaný API klíč (modul
`core/ai`). Per DS může existovat víc připojení (vlastní klíč, gateway,
druhý účet), právě jedno má `is_default = true`; obvykle stačí jedno,
auto-provisionované při `ds-upgrade`.

Připojení **nenese model ani ladění** (tasks/ai-functions.md D39): co se
volá a s jakými parametry, určuje **AI funkce**
([core_ai_function_settings](core_ai_function_settings.md): připojení,
model, thinking / effort, strop tokenů per funkce) a u vytěžení pošty
navíc AI profil ([core_mail_ai_profiles](../../mail/tables/core_mail_ai_profiles.md)).
Jediné místo, které z připojení, funkce a profilu skládá parametry
volání, je `Shipard\Core\Ai\AiFunctionResolver` (`docs/ai.md`). Název
tabulky a sloupce `provider` zůstává z historických důvodů („backend“).

## Citlivá data

Sloupec `api_key` je typu `encrypted_text` — viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md). Plaintext
neleží v DB; šifrování řeší `AIBackendDocument::beforeSave()` přes
`DsSecretCipher`, dešifruje `AiBackendResolver::apiKey()` pro resolver.
Plaintext se nesmí logovat ani vracet do view; žije jen v paměti po dobu
volání modelu (`ResolvedAiCall::$apiKey`, `toArray()` ho nevrací).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `backend_id` | varchar(50), NOT NULL, UNIQUE | Lidský kód (`default`, `gateway`) — používá `ai-backend-set-key --backend` |
| `name` | varchar(100), NOT NULL | Zobrazovaný název v UI |

### Platforma (provider)

| Sloupec | Typ | Popis |
|---|---|---|
| `provider` | varchar(30), NOT NULL, default `anthropic` | Platforma (popisek „Platforma“, #85 D39): klíč z cfgItemu `core.ai.platforms` — dialekt API a výchozí adresa. Zatím jen `anthropic`; hodnotu hlídá `AIBackendDocument::validate` (D45). ID modelu pro platformu bere resolver z katalogu (`platformIds`). |
| `model` | varchar(100), NULL | **Nepoužívá se od fáze 1b.** Zůstává v definici jako nullable, mimo formulář i viewer: v databázích byl NOT NULL bez výchozí hodnoty a `ds-upgrade` sloupce nemaže, takže by po vyjmutí z definice selhal INSERT nového připojení. Vyplněná hodnota = připojení ještě neprošlo převodem D41; `ds-upgrade` ji po převodu vynuluje (D46). Odstraní servisní výmaz. |
| `base_url` | varchar(200), NULL | Vlastní adresa API — AI gateway hostingu (`…/_hosting/ai-gw`, `docs/hosting.md` §5.5); prázdné = výchozí adresa platformy z `core.ai.platforms`. |

### Přístup (credentials)

| Sloupec | Typ | Popis |
|---|---|---|
| `api_key` | encrypted_text | API klíč (nebo gateway token `shpd_gw_…`) šifrovaný `DsSecretCipher`. Ukládá `AIBackendDocument::beforeSave` při dirty change, nastavuje `ai-backend-set-key`. |

### Příznaky (flags)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_default` | boolean, default false | Výchozí připojení DS — platí pro každou AI funkci a profil bez vlastního připojení. Smí být `true` jen u jedné řádky (vynuceno aplikačně v `AIBackendDocument::validate`). |
| `is_active` | boolean, default false | Aktivuje se po nastavení `api_key` přes `ai-backend-set-key`; neaktivní připojení resolver nepoužije (`NO_CONNECTION`). |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime, NOT NULL | Čas založení |
| `created_by` | int → `core_system_users` | Uživatel, který připojení založil |
| `modified` | datetime, NOT NULL | Čas poslední změny |
| `docState` | tinyint (system) | Stav dokumentu — viz `core.system.docStatesArchive` |
| `docStateMain` | tinyint (system) | Řazení podle stavu |

### Sloupce ladění z fáze 0 (jen v databázi)

`max_tokens`, `temperature`, `thinking` a `effort` z definice zmizely
(tasks/ai-functions.md, #85 fáze 1b); v databázích upgradovaných před
tím zůstávají, protože `ds-upgrade` sloupce nemaže. Nic je nečte — jejich
hodnoty převedl `ds-upgrade` jednorázově do řádků funkcí a profilů (D41)
a pak je vrátil na výchozí (D46). Odstraní servisní výmaz.

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_backend_id` | unique | `backend_id` | Lidský kód unikátní per DS |
| `idx_is_default` | index | `is_default` | Rychlé vyhledání výchozího připojení v resolveru |
| `idx_is_active` | index | `is_active` | Filter aktivních připojení |

## Životní cyklus

1. **Auto-provisioning** při `ds-upgrade` (`MailAiProvisioner`): vznikne
   připojení `default` (platforma `anthropic`, `is_default=true`,
   `is_active=false`, `api_key=NULL`, bez modelu). Nepotřebuje katalog
   modelů. Tentýž běh jednorázově převede dřívější model a ladění
   připojení do profilů pošty a řádků funkcí (`[CONVERT]`), přepíše
   vyřazené modely u profilů a funkcí (`[MODEL]`) a převedená připojení
   označí vynulováním `model` (D41, D46).
2. **Nastavení klíče**: admin spustí `bin/shpd-ds ai-backend-set-key`
   (skrytý vstup; `--base-url` pro gateway), který klíč zašifruje
   a nastaví `is_active=true`.
3. **Volání**: `AiFunctionResolver::resolve()` vybere připojení
   (přepsání volajícího → řádek funkce → výchozí), dešifruje klíč
   a vrátí `ResolvedAiCall`; plaintext žije jen v paměti po dobu volání.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_ai_function_settings](core_ai_function_settings.md) | `connection` → `core_ai_backends.id` | Připojení přepsané u AI funkce (NULL = výchozí) |
| [core_mail_ai_profiles](../../mail/tables/core_mail_ai_profiles.md) | `ai_profiles.backend` → `core_ai_backends.id` | Připojení přepsané v profilu pošty (NULL = podle funkce) |
| [core_mail_message_analyses](../../mail/tables/core_mail_message_analyses.md) | `message_analyses.backend` → `core_ai_backends.id` | Audit: přes které připojení běh šel |
