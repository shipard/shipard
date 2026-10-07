# Tabulka: Došlé zprávy (core_mail_incoming_messages)

Hlavní tabulka evidence došlé pošty. Jednotkou je zpráva doručená do konkrétní
schránky (`mailbox`). Z jedné zprávy může časem vzniknout business entita (např.
přijatá faktura) — vazba je polymorfní přes `target_table_id` + `target_row`.

**Zpráva ≠ dokument.** Zpráva je pouze transportní jednotka; business entity
žijí v jiných tabulkách a zpráva na ně jen odkazuje.

## Struktura

Sloupce jsou organizovány do skupin:

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `message_id` | varchar(30), NOT NULL, UNIQUE | Lidský kód zprávy (`MSG-YYYYMMDD-NNNN`) — generovaný v `IncomingMessageDocument::beforeSave()` |
| `mailbox` | int → `core_mail_mailboxes`, NOT NULL | Schránka, do které zpráva dorazila |
| `primary_type` | enumString(30), NOT NULL | Předpokládaný druh dokumentu — viz [primaryTypes.jsonc](../config/primaryTypes.jsonc). Default `other`. |
| `primary_type_source` | enumString(10), NOT NULL | Kdo naposledy určil `primary_type`: `mailbox` (default schránky / mail-router), `user` (ruční změna ve formuláři), `ai` (klasifikace z analýzy). AI nikdy nepřepisuje `user`. Viz [primaryTypeSources.jsonc](../config/primaryTypeSources.jsonc). |

### Hlavičky (headers)

| Sloupec | Typ | Popis |
|---|---|---|
| `subject` | text, NOT NULL | Předmět zprávy |
| `sender_email` | varchar(200), NOT NULL | E-mailová adresa odesílatele |
| `sender_name` | varchar(200) | Display name odesílatele (z hlavičky `From`) |
| `sender_person` | int → `base_persons_persons` | Matching na osobu v systému (NULL ve Fázi 1, naplňuje Fáze 3) |
| `received_at` | datetime, NOT NULL | Čas doručení — u manuálu `= created` |
| `external_message_id` | varchar(255) | RFC822 `Message-ID` (pro budoucí deduplikaci) |
| `in_reply_to` | varchar(255) | Hlavička `In-Reply-To` (ukládáme, ve Fázi 1 nevyužíváme) |
| `reply_references` | text | Hlavička `References` (ukládáme, ve Fázi 1 nevyužíváme). Pojmenováno se suffixem `reply_`, protože `references` je rezervované slovo SQL. |
| `is_bulk` | tinyint, default 0 | Signál hromadné pošty z hlaviček `.eml` (`List-Unsubscribe`, `Precedence: bulk\|list`, `Auto-Submitted` ≠ `no`, `List-Id`) — plní `BulkHeadersDetector` při ingestu. Jen signál (D7), sám nikdy nearchivuje. |

### Tělo (body)

| Sloupec | Typ | Popis |
|---|---|---|
| `body_plain` | longtext | Tělo zprávy v prostém textu |
| `body_html` | longtext | Tělo zprávy v HTML (když je k dispozici) |
| `raw_source_attachment` | int → `core_attachments_files` | Odkaz na přílohu typu `.eml` (originál). Nezobrazuje se v panelu "obsahových" příloh — přístup přes samostatný tab "Originál". |

### Směrování (routing)

| Sloupec | Typ | Popis |
|---|---|---|
| `target_table_id` | varchar(100) | Jméno cílové tabulky (`docs_core_heads` / `base_registry_documents` / …) pro business entitu, která ze zprávy vznikla |
| `target_row` | int | ID záznamu v cílové tabulce |

Pár (`target_table_id`, `target_row`) je polymorfní FK — aplikační kód ho
kontroluje, DB úroveň nikoli (Shipard nepoužívá FOREIGN KEY). Plní ho
**AI apply** dokumentového návrhu pro **oba targety** (docs
`DocumentApplier::writeLineageTargets`, registry `RegistryApplier`) —
atomicky v transakci uložení cílové entity — i ruční zařazení do Spisovny.
Lineage je **obousměrná** (D6 z mail-message-centric): doklad zpětně nese
`docs_core_heads.source_message` (registry záznam `source_message`).
Obsazený `target_row` zároveň slouží jako klíč idempotence apply a guard
reanalýzy; unapply obě strany nuluje.

### Zdroj (source)

| Sloupec | Typ | Popis |
|---|---|---|
| `source_type` | tinyint, default 1 | Způsob vzniku zprávy: `1` = manuální pořízení, `2` = e-mail (mail-router), `3` = API, `4` = scan. Ve Fázi 1 jsou všechny testovací i ručně pořízené zprávy `source_type = 1`. |

### AI analýza (ai)

| Sloupec | Typ | Popis |
|---|---|---|
| `analysis_state` | enumInt, NOT NULL, default 0 | Pipeline status AI analýzy — ortogonální k `docState`, přežívá Koš i Archiv. Hodnoty: `0` bez analýzy, `10` ve frontě, `20` analyzuje se (read-only zámek formuláře), `30` analyzováno, `70` selhala. Viz [analysisStates.jsonc](../config/analysisStates.jsonc). |
| `ai_analysis_enabled` | boolean (nullable) | Override per zpráva. `NULL` = zděděno ze schránky (`mailboxes.ai_analysis_disabled`); `true`/`false` = explicitní přepis. |
| `needs_reanalysis` | boolean, default false | Příznak nastavený akcí "Znovu analyzovat" — zapne se po reanalyze hooku, vypne se při dalším úspěšném `result`. |
| `profile_override` | int → `core_mail_ai_profiles` | Pro ad-hoc znovu-analýzu s jiným profilem. NULL = použít default profil DS. |

### Technické předzpracování (ai)

| Sloupec | Typ | Popis |
|---|---|---|
| `preprocess_state` | enumInt, NOT NULL, default 0 | Stav předzpracování dle pravidel [core_mail_preprocess_rules](core_mail_preprocess_rules.md) — ortogonální k `docState` i `analysis_state`. Hodnoty: `0` netýká se, `10` čeká, `20` běží, `30` hotovo, `40` hotovo s chybami. Ve stavech 10/20 zprávu `/queue` nevydá. Viz [preprocessStates.jsonc](../config/preprocessStates.jsonc). |
| `preprocess_log` | longtext (JSON) | `{plan: [{ruleId, actions}], results: [{action, ok, note, attachmentId?}], attempts, createdAt, startedAt, finishedAt}`. Plán je snapshot z intake — runner vykonává jej, ne aktuální pravidla. |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `created` | datetime | Čas založení záznamu |
| `created_by` | int → `core_system_users` | Uživatel, který záznam vytvořil |
| `modified` | datetime | Čas poslední změny |
| `auto_disposed_by` | int → `core_mail_sender_rules` | Pravidlo, které zprávu auto-archivovalo — při ingestu (`archive`, `analysis_state` 0), po analýze nebo při potvrzení pravidla (`analysis_state` 30; `PostAnalysisDisposer`). NULL = zpráva prošla normálně. Auditní stopa — digest karta i „Vrátit vše" se derivují dotazem. |
| `auto_disposed_at` | datetime | Čas auto-archivace. Undo obojí nuluje; `analysis_state` 30 přitom ponechá (zpráva se vrátí bez nové analýzy, D7). |
| `docState` | tinyint (system) | Stav zprávy — viz [docStatesIncoming.jsonc](../config/docStatesIncoming.jsonc) |
| `docStateMain` | tinyint (system) | Řazení podle stavu |

## Indexy

| Index | Typ | Sloupce | Poznámka |
|---|---|---|---|
| `unq_message_id` | unique | `message_id` | Lidský kód zprávy je unikátní |
| `idx_mailbox` | index | `mailbox` | Filtrace dle schránky |
| `idx_received_at` | index | `received_at` DESC | Chronologický výpis, newest-first |
| `idx_external_message_id` | index | `external_message_id` | Pro budoucí deduplikaci dle RFC822 Message-ID |
| `idx_doc_state` | index | `docStateMain` ASC, `received_at` DESC | Řazení ve vieweru |
| `idx_analysis_state` | index | `analysis_state` ASC, `received_at` ASC | Fronta analyzeru (`analysis_state=10 ORDER BY received_at ASC`) |
| `idx_target` | index | `target_table_id`, `target_row` | Zpětné vyhledávání zpráv ze kterých vznikla entita |
| `idx_sender_email` | index | `sender_email` | Filtr dle odesílatele |
| `idx_sender_person` | index | `sender_person` | Filtr dle osoby v systému |
| `idx_auto_disposed` | index | `auto_disposed_at` | Digest karta a „Vrátit vše" (zprávy auto-archivované v daném dni) |
| `idx_preprocess_state` | index | `preprocess_state`, `modified` | Rescue sweep `mail-preprocess --sweep` (zaseknuté stavy 10/20) |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [core_mail_mailboxes](core_mail_mailboxes.md) | `messages.mailbox` → `mailboxes.id` | Patří do schránky |
| [core_mail_message_analyses](core_mail_message_analyses.md) | `analyses.message` → `messages.id` | Historie AI analýz (1:N) |
| `core_attachments_files` | `messages.raw_source_attachment` → `attachments.id` | Originál `.eml` |
| `core_attachments_files` | přes `table_id + record_id` | Obsahové přílohy zprávy |
| `base_persons_persons` | `messages.sender_person` → `persons.id` | Odesílatel v CRM |
| `docs_core_heads` | `heads.source_message` → `messages.id`; dopředně `target_table_id='docs_core_heads'` + `target_row` | Doklad vzniklý apply návrhu (obousměrná lineage) |
| `base_registry_documents` | `documents.source_message` → `messages.id`; dopředně `target_*` | Záznam Spisovny vzniklý ze zprávy |
| [core_mail_sender_rules](core_mail_sender_rules.md) | `messages.auto_disposed_by` → `sender_rules.id` | Pravidlo, které zprávu auto-archivovalo |
| [core_mail_preprocess_rules](core_mail_preprocess_rules.md) | `preprocess_log.plan[].ruleId` → `rule_id` | Snapshot plánu předzpracování |

## Workflow

Zpráva má dvě ortogonální osy — uživatelský `docState` (Nová 10 / K řešení 20 /
Hotovo 40 / Archiv 80 / Koš 90) a pipeline `analysis_state`. Detailně viz
[ai-analysis.md](../docs/ai-analysis.md) „Stavy zprávy".

1. **Vznik** — ruční pořízení, mail-router nebo import. `docState = 10` (Nová);
   `analysis_state = 10` pokud je AI analýza povolená a dostupná, jinak 0
   (importy v Hotovo mají vždy 0).
2. **AI analýza** — `analysis_state` 10 → 20 → 30/70 (claim/result/failed);
   `docState` se posouvá jen resultem s dokumentovým návrhem: 10 → 20
   (K řešení).
3. **Verdikt** — uživatel návrh použije (apply) nebo zamítne (reject);
   obojí zapíše `resolution` na řádek analýzy a přepne zprávu na
   `docState = 40` (Hotovo). Při apply vznikne business entita a naplní se
   `target_table_id` / `target_row` (obousměrná lineage, viz Směrování);
   unapply celý apply vrátí (40 → 20).
4. **Archivace / smazání** — `docState` → 80 (Archiv) nebo 90 (Koš); obojí
   vyřadí zprávu z fronty analyzeru, `analysis_state` zůstává.
