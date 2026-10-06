# Tabulka: Odeslaná pošta (core_mail_sent_messages)

## Účel

Evidence odeslaných zpráv nad libovolnou tabulkou (#90 D40–D45) — co, komu
a kdy odešlo a k jakému záznamu to patří. Obsah je oddělený od transportu:
zpráva drží předmět, tělo, příjemce a přílohy, fronta `core_mail_outbox`
jen doručuje. Záznam (faktura) ví, co odešlo, přes zprávy, které na něj
ukazují.

Zpráva vzniká **až odesláním** záznamu (`RecordSendService`), rovnou ve
stavu Odeslaná, nebo **importem** ze starého systému
(`SentMessageImportService`, #104 — viz `docs/mail/sent.md` → Import).
Ručně ji založit nejde a fyzicky se nemaže.

## Sloupce

### `message` — pevný obsah

| Sloupec | Typ | Popis |
|---|---|---|
| `channel` | enumString(10) → `core.mail.sentChannels` | `email`; `databox` je zatím jen hodnota sloupce bez transportu |
| `subject` | varchar(500) | Předmět |
| `body_text` | text | Tělo — prostý text |
| `email_from`, `email_from_name` | varchar(200) | Odesílatel a jeho jméno |
| `email_to` | varchar(2000) | „Komu“ — adresy oddělené čárkou (`AddressList`) |
| `email_cc` | varchar(2000), nullable | Kopie |
| `recipient_person` | int, nullable → `base_persons_persons` | Osoba příjemce |

### `target` — záznam, ke kterému zpráva patří

| Sloupec | Typ | Popis |
|---|---|---|
| `target_table_id` | varchar(100), nullable | **Název** tabulky záznamu (`docs_core_heads`) — stejná konvence jako došlá pošta |
| `target_row` | int, nullable | Id záznamu |
| `target_label` | varchar(250), nullable | Popisek záznamu v době odeslání („Faktura – daňový doklad 2260011“) |
| `purpose` | varchar(50), nullable | Účel odesílání (`base.persons.sendPurposes`) |
| `language` | varchar(5), nullable | Jazyk zprávy |
| `print_id` | varchar(100), nullable | Tisk, ze kterého zpráva vznikla — zpráva nemusí být z tisku |

### `transport` — výsledek posledního průchodu frontou

| Sloupec | Typ | Popis |
|---|---|---|
| `transport_state` | enumString(10) → `core.mail.transportStates` | `queued` / `sent` / `failed`; `unknown` = importovaná zpráva bez adresy příjemce (#104 D4) |
| `sent_at` | datetime, nullable | Poslední úspěšné odeslání |
| `send_count` | int | Počet úspěšných odeslání |
| `last_error` | varchar(500), nullable | Poslední chyba transportu |
| `last_outbox_id` | int, nullable | Poslední řádek fronty; po úklidu fronty může ukazovat do prázdna |

### `status`

| Sloupec | Typ | Popis |
|---|---|---|
| `send_trigger` | enumString(10) → `core.mail.sendTriggers` | `manual` / `cli` / `import` (zpráva převzatá ze starého systému, #104 D2), rezerva `batch` |
| `import_ref` | varchar(100), nullable, unikátní | Identita zprávy ve zdrojovém systému (`oldShipard:<ndx>`); u zpráv vzniklých odesláním NULL. Opakovaný import téže zprávy vrátí tu existující |
| `created`, `created_by`, `modified` | | U importu čas a autor ze zdroje; `modified` = `created` |
| `docState`, `docStateMain` | | Stavy níže |

## Stavy

Vlastní sada `core.mail.docStatesSent` — ne `docStatesArchive`, pevný obsah
nesnese Koncept ani V opravě:

| Stav | Název | Přechody |
|---|---|---|
| 40 | Odeslaná | → 70, 90 |
| 70 | V archivu | → 40 |
| 90 | Smazaná | → 40 |

Všechny stavy jsou jen pro čtení. Archivovaná zpráva se neukazuje u záznamu,
v agendě zůstává.

## Pevný obsah

Po vytvoření se nemění předmět, tělo, odesílatel, příjemci, vazba ani
přílohy — jen stav. Hlídá to víc vrstev, protože zápisových cest je víc:

- read-only stavy — formulář i generické `PUT` / `PATCH` změnu obsahu
  odmítnou (`DOCUMENT_READONLY`);
- `systemManaged` v definici tabulky — generické `POST` a `DELETE` vrací
  405 `TABLE_SYSTEM_MANAGED`;
- `SentMessageDocument` — pojistka pro ostatní volající gateway (založení,
  změna obsahu, smazání);
- `SentMessageAttachmentGuard` — přílohy zprávy nejde smazat, přejmenovat,
  přeřadit ani přidat další (409 `ATTACHMENT_LOCKED`).

Import (`SentMessageImportService`) zapisuje mimo tyto vrstvy —
`SentMessageStore::import()` a `AttachmentService` bez guardů — a je
jedinou cestou, jak zpráva vznikne jinak než odesláním. Importovanou zprávu
(`send_trigger = import`) nejde odeslat znovu (409 `IMPORTED`, #104 D5).

## Přílohy

Přílohy zprávy jsou řádky `core_attachments_files` s touto tabulkou
(`table_id` 455) — PDF tisku a přílohy záznamu zkopírované v okamžiku
odeslání. Odeslat znovu posílá právě tyto soubory.

## Indexy

| Index | Sloupce | Účel |
|---|---|---|
| `idx_target` | `target_table_id`, `target_row`, `created` DESC | Zprávy u záznamu (sekce Odeslaná pošta v detailu) |
| `idx_recipient_person` | `recipient_person` | Co odešlo osobě |
| `idx_doc_state` | `docStateMain`, `created` DESC | Agenda |
| `unq_import_ref` | `import_ref` (unikátní) | Deduplikace importu; NULL u odeslaných zpráv unikátnost neporušuje |

## Návaznosti

- Transport a propsání výsledku: `SentMessageTransport`,
  `SentMessageOutboxListener` — `docs/mail/sent.md`.
- Odeslání záznamu: `docs/prints.md` → Odesílání.
