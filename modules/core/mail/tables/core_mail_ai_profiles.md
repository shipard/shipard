# Tabulka: AI profily (core_mail_ai_profiles)

Profil popisuje **co** se v došlé poště analyzuje — jaký prompt, jaké
výstupní JSON schéma, jaké typy dokumentů profil pokrývá, v jakém jazyce
a s jakými prahy jistoty. Per DS obvykle 1–2 profily (např.
`czech_general`, `english_invoices`).

Profil je vždy vázán na konkrétní backend (provider + model) — admin tak
může pro různé typy pošty zvolit jiný model.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `profile_id` | varchar(50), NOT NULL, UNIQUE | Lidský identifikátor (`czech_general`) |
| `name` | varchar(100), NOT NULL | Zobrazovaný název v UI |
| `backend` | int → `core_ai_backends`, NOT NULL | Backend, přes který profil běží |

### Záběr (scope)

| Sloupec | Typ | Popis |
|---|---|---|
| `supported_doc_types` | text, NOT NULL | JSON pole klíčů z `core.mail.primaryTypes` |
| `language` | varchar(5), NOT NULL, default `cs` | ISO 639-1 — pro user-facing texty v promptu |

### Prompt

| Sloupec | Typ | Popis |
|---|---|---|
| `prompt_version` | varchar(50), NOT NULL, default `v1.0.0` | Verze promptu (SemVer). Loguje se do `message_analyses.prompt_version`. |
| `prompt_template` | longtext, NOT NULL | Vlastní text promptu s Jinja-style placeholders |
| `max_tokens` | int, NOT NULL, default 0 | Volitelný override limitu output tokenů — kaskáda profil → backend → default provideru analyzeru; `0` = nenastaveno. Admin pole: seed ani reload profilu ho nemění. |

### Výstupní schéma (schema)

| Sloupec | Typ | Popis |
|---|---|---|
| `output_schema` | longtext, NOT NULL | JSON Schema, proti kterému se validuje výstup AI |

### Prahy (thresholds)

| Sloupec | Typ | Popis |
|---|---|---|
| `confidence_thresholds` | text, NOT NULL | JSON `{"ready": 0.9, "review": 0.6}` — prahy runtime confidence pásem návrhu (`AnalysisConfidenceResolver`; pásmo se nepersistuje) |

### Příznaky (flags)

| Sloupec | Typ | Popis |
|---|---|---|
| `is_default` | boolean, default false | Výchozí profil DS. Smí být `true` jen u jedné řádky (vynuceno aplikačně). |
| `is_active` | boolean, default true | Vypnuté profily nelze použít při claim |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created`, `created_by`, `modified` | datetime / int | Audit |
| `docState`, `docStateMain` | tinyint (system) | Stav dokumentu — `core.system.docStatesArchive` |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_profile_id` | unique | `profile_id` |
| `idx_backend` | index | `backend` |
| `idx_is_default` | index | `is_default` |

## Životní cyklus

1. **Auto-provisioning** při `ds-upgrade`: vznikne profil `czech_general`
   (default) ze šablony `modules/core/mail/profiles/czech_general.jsonc`.
2. **Editace**: admin upravuje `prompt_template`, `output_schema`,
   `confidence_thresholds` přímo v UI / DB. Při netriviální změně se zvedá
   `prompt_version` (manuálně, není vynucováno).
3. **Použití**: `AnalysisController::claim()` vrátí celý profil v claim
   response (template, schéma, prahy, podporované typy).

## Vztah k běhu analýzy

Při uložení výsledku (`AnalysisResultWriter::storeResult()`) se
`profile.id` propíše do `core_mail_message_analyses.profile`, takže historie
analýz je auditovatelná i po pozdějších změnách profilu.

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_ai_backends](../../ai/tables/core_ai_backends.md) | `ai_profiles.backend` → `core_ai_backends.id` | Backend, na kterém profil běží (modul core/ai) |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `message_analyses.profile` → `ai_profiles.id` | Audit běhu |
| [core_mail_incoming_messages](core_mail_incoming_messages.md) | `incoming_messages.profile_override` → `ai_profiles.id` | Per-zpráva override profilu pro znovu-analýzu |
