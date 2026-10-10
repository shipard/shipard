# Tabulka: Analýzy zpráv (core_mail_message_analyses)

Historie AI analýz došlých zpráv. Vztah 1:N na [core_mail_incoming_messages](core_mail_incoming_messages.md)
— každá zpráva může mít více pokusů o analýzu (první pokus selhal, druhý uspěl
s upraveným promptem; znovu analyzováno po upgradu modelu apod.).

Od message-centrického modelu ([tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md))
je řádek analýzy zároveň **nositelem dokumentového návrhu** (`canonical_json`
+ `proposed_type`) a **verdiktu uživatele** (`resolution` + spol.) — tabulka
`core_mail_extracted_documents` zanikla.

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message` | int → `core_mail_incoming_messages`, NOT NULL | Zpráva, ke které analýza patří |
| `profile` | int → `core_mail_ai_profiles` | Profil použitý při běhu (NULL u legacy záznamů z Fáze 1) |
| `backend` | int → `core_ai_backends` | Backend použitý při běhu (NULL u legacy záznamů z Fáze 1) |
| `analyzed_at` | datetime, NOT NULL | Čas dokončení analýzy (start nebo konec — nastavuje AI pipeline) |
| `status` | tinyint, default 1 | `1` = pending, `2` = success, `3` = failed |

### Model (model)

| Sloupec | Typ | Popis |
|---|---|---|
| `model_name` | varchar(100), NOT NULL | Identifikátor modelu (`claude-sonnet-4`, `gpt-4o`, …). Deterministický ISDOC import zapisuje `isdoc` — takový běh nemá profil, backend, cost ani tokens. |
| `model_version` | varchar(100) | Volitelná verze modelu (např. datum snapshotu; u ISDOC importu `@version` z XML) |
| `prompt_version` | varchar(50), NOT NULL | Verze použitého promptu — SemVer nebo git-hash (u ISDOC importu konstanta `isdoc`) |

### Výsledek (result)

| Sloupec | Typ | Popis |
|---|---|---|
| `analysis_json` | longtext | Strukturovaný výstup AI — surový JSON běhu (vč. `message_classification` a `secondary_findings`). NULL u `status = failed`. |
| `confidence` | numeric(4,3) | Confidence dokumentového návrhu (`document.confidence`; běh bez dokumentu `overall_confidence`) v rozsahu `0.000`–`1.000` |
| `canonical_json` | longtext, NULL | Validovaný + obohacený canonical návrhu (`shpd.docs.document.v1`, resp. `shpd.registry.document.v1`). NULL = běh žádný dokument nenavrhl. Při selhání validace forenzní wrapper `{_validationError, _validationIssues, _rawOutput}`. |
| `user_actions_json` | longtext, NULL | Rozhodnutí uživatele z review modalu — flat mapa `{cesta: userAction}` ve tvaru `_resolve` z `POST /apply` (např. `{"supplier": "useExisting:42", "rows[0].item": "skip"}`). Ukládá průběžně `POST /_mail/messages/{ndx}/decisions`, vázáno na tento běh (reanalýza = nový řádek bez rozhodnutí). Po verdiktu se nemaže — historie toho, co uživatel rozhodl. NULL = nic nerozhodnuto. Viz [tasks/mail-review-decisions-persist.md](../../../../tasks/mail-review-decisions-persist.md). |
| `proposed_type` | enumString(30), NULL, cfgItem `core.mail.primaryTypes` | Typ dokumentu navržený tímto během. Historický záznam — na rozdíl od mutable `message.primary_type` se po zápisu nemění. Ve wire kontraktu se pole jmenuje `doc_type`. |
| `content_tag` | enumString(40), NULL, cfgItem `core.exchange.contentTags` | Obsahový štítek dokladu z obsahové eskalace párování (tasks/content-tag-enrichment.md) — denormalizace `_resolve.contentTag.tag` z `canonical_json`. Zdroj štítku (rule/llm) nese jen audit blok v canonicalu. |
| `error_message` | text | Detail chyby u `status = failed` |

### Verdikt (resolution)

Verdikt uživatele nad dokumentovým návrhem běhu — zapisuje ho
`MessageProposalApplier` (apply/reject), unapply ho vrací na NULL.

| Sloupec | Typ | Popis |
|---|---|---|
| `resolution` | enumInt, NULL, cfgItem `core.mail.analysisResolutions` | NULL = otevřený návrh / běh bez návrhu; `40` = applied (cílová entita existuje); `50` = rejected |
| `rejected_reason` | text, NULL | Povinný při `resolution = 50` |
| `resolved_at` | datetime, NULL | Čas verdiktu |
| `resolved_by` | int → `core_system_users`, NULL | Kdo rozhodl |

### Telemetrie (telemetry)

| Sloupec | Typ | Popis |
|---|---|---|
| `tokens_input` | int | Počet vstupních tokenů (cost tracking) |
| `tokens_output` | int | Počet výstupních tokenů |
| `duration_ms` | int | Trvání volání v milisekundách |
| `cost_usd` | numeric(10,6) | Cena volání v USD podle ceníku modelů (počítá runner; starší běhy zrušeného externího analyzeru ji hlásily samy) |

### Auditní pole (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime | Čas založení záznamu |
| `created_by` | int → `core_system_users` | NULL = běh runneru v procesu (strojový kontext). Starší běhy zrušeného externího analyzeru nesou uživatele `_ai_analyzer` — účet je deaktivovaný, nemaže se (#85 D21) |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `idx_message` | index | `message` | Vyhledávání analýz pro zprávu |
| `idx_message_analyzed` | index | `message`, `analyzed_at` DESC | „Current" analýza = `MAX(analyzed_at)` per message |

## Současná analýza = aktuální návrh

Žádný flag `is_current` — **poslední úspěšný běh je aktuální dokumentový
návrh zprávy** (konvence D2 z mail-message-centric):

```sql
SELECT * FROM core_mail_message_analyses
WHERE message = :messageId AND status = 2
ORDER BY analyzed_at DESC, id DESC
LIMIT 1
```

Nad tímto řádkem operují všechny message-centrické akce
(preview/apply/reject/unapply — `MessageProposalApplier`), dashboard feed
(`MailSuggestionsSource`) i tab „Návrh" v detailu zprávy. Reanalýza jen
přidá nový běh — starý zůstává v historii (koncept `superseded` zanikl,
je implicitní). Tab „Analýzy" ukazuje u každého běhu sloupec **Návrh**
(ano/ne z `canonical_json IS NOT NULL`) a **Verdikt** (`resolution`).

## Mazání

Při mazání zprávy (`IncomingMessageDocument::beforeDelete()`) se nejprve smažou
všechny analýzy této zprávy — Shipard nepoužívá FOREIGN KEY, referenční integritu
kontroluje aplikační kód.
