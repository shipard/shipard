# Modul `core.mail` — dokumentace

Modul spravuje e-mailovou komunikaci. **Fáze 1** implementuje evidenci došlé pošty:
datový model, ruční pořízení, prohlížeč, fake data. **Fáze 2a** přidává HTTP
endpoint pro příjem pošty z externí služby `shipard-mail-router` (samostatný
repozitář). Fáze 3+ přidává AI analyzátor a odeslanou poštu.

## 1. Rozsah Fáze 1

**V rozsahu:**

- 3 tabulky: `core_mail_mailboxes`, `core_mail_incoming_messages`, `core_mail_message_analyses`
- 2 cfgItemy: `core.mail.primaryTypes`, `core.mail.docStatesIncoming`
- PHP třídy: `IncomingMessagesViewer`, `IncomingMessagesForm`, `IncomingMessageDocument`, `FakeMailboxGenerator`, `FakeIncomingMessageGenerator`
- CLI: `seed-mail`, `seed-mail-clear`
- Kontrakt pro API endpoint (Fáze 2) — viz [`docs/mail/api-contract.md`](../../../../docs/mail/api-contract.md)

**Fáze 2a — přidáno:**

- Sloupec `core_mail_mailboxes.is_default` (boolean) s aplikační validací "max 1 per DS"
- Nová tabulka `core_mail_incoming_idempotency` pro deduplikaci requestů z mail-routeru
- Sloupec `core_system_users.is_system` pro označení systémových účtů
- PHP třídy: `MailboxDocument`, `MailRouterProvisioner`, `IdempotencyStore`
- Controller: [`src/Api/Controller/MailController.php`](../../../../src/Api/Controller/MailController.php)
- Endpoint `POST /api/v1/_mail/incoming` — viz [`docs/mail/api-contract.md`](../../../../docs/mail/api-contract.md)
- CLI: `mail-router-bootstrap` (automaticky z `ds-upgrade`), `mail-router-setup`, `mail-idempotency-prune`

**Mimo rozsah:**

- Mail-router daemon samotný (SMTP/IMAP příjem, parsing `.eml`) — **samostatný repozitář**, nasazuje se nezávisle
- AI analýza — Fáze 3, runner v procesu ([ai-analysis.md](ai-analysis.md))
- Threading (`In-Reply-To`, `References`) — ukládáme, nezpracováváme
- Deduplikace dle `external_message_id` — ukládáme, nevyužíváme
- Automatické matchování odesílatele na `base_persons_persons` — naplňuje Fáze 3
- Odeslaná pošta (`core_mail_outgoing_messages`) — pozdější fáze

## 2. Terminologie

| EN (kód)          | CZ (UI)        | Popis                                                     |
|-------------------|----------------|-----------------------------------------------------------|
| Mailbox           | Schránka       | Jedna e-mailová adresa s konfigurací                      |
| Incoming message  | Došlá zpráva   | Jednotka doručená do schránky                             |
| Primary type      | Primární typ   | Předpokládaný druh dokumentu (cfgItem klíč)               |
| Message analysis  | Analýza zprávy | Výstup AI pipeline (1:N na zprávu)                        |
| Raw source        | Originál       | Uložený `.eml` pro audit/debugging                        |

**Zpráva ≠ dokument.** Zpráva je pouze transportní jednotka. Business entita
(přijatá faktura, objednávka, …) vzniká zpracováním zprávy a je držena v jiné
tabulce. Vazba je polymorfní přes `target_table_id` + `target_row` na
`core_mail_incoming_messages`.

## 3. Datový tok

```
┌──────────────────┐
│ External source  │   Mail-router (Fáze 2), API, manuální pořízení
│  (SMTP / IMAP)   │
└────────┬─────────┘
         │ POST /_mail/incoming  (kontrakt pro Fázi 2)
         ▼
┌─────────────────────────────┐
│ core_mail_incoming_messages │   docState = 10 (Nová)
│ + core_attachments_files    │   obsahové přílohy + raw .eml
└────────┬────────────────────┘
         │  zařazení do AI fronty (analysis_state = 10, ortogonální osa)
         ▼
┌──────────────────────────┐
│  AI analýza (runner)     │─→ core_mail_message_analyses
└────────┬─────────────────┘        (historie běhů; MAX(analyzed_at) = aktuální
         │                           návrh: canonical_json + proposed_type)
         ▼                           docState 10 → 20 (K řešení)
┌──────────────────────────┐
│  Uživatel použije návrh  │  target_table_id, target_row → business entita
│  (apply) a vznikne       │  (docs_core_heads / base_registry_documents);
│  business entita         │  doklad zpětně nese source_message
└──────────────────────────┘           docState → 40 (Hotovo)
```

Zpráva má **nejvýše jeden dokumentový návrh** — canonical poslední úspěšné
analýzy; verdikt uživatele (Použít/Zamítnout) se zapisuje na řádek analýzy.
Detaily: [ai-analysis.md](ai-analysis.md).

Výjimka z AI fronty: zpráva s ISDOC (samostatná příloha nebo ISDOC
embedded v PDF) se po intake zpracuje **deterministicky**
(`IsdocImportService`) — dokumentový návrh vznikne parserem s confidence
1.0, `analysis_state` přeskočí frontu rovnou na 30 a v tabu Analýzy je
záznam `model_name='isdoc'` s canonical návrhem. Vadný ISDOC → zpráva jde
normálně do AI fronty. Viz [ai-analysis.md](ai-analysis.md), sekce
„Deterministický ISDOC import".

## 4. Datový model

Podrobnosti ke každé tabulce jsou v samostatných Markdown souborech:

- [`tables/core_mail_mailboxes.md`](../tables/core_mail_mailboxes.md) — schránky
- [`tables/core_mail_incoming_messages.md`](../tables/core_mail_incoming_messages.md) — zprávy
- [`tables/core_mail_message_analyses.md`](../tables/core_mail_message_analyses.md) — analýzy

### 4.1 Raw `.eml` jako separátní sloupec

`core_mail_incoming_messages.raw_source_attachment` je FK do
`core_attachments_files`, ale **nikoli** obsahová příloha zprávy. Viewer
a editor ji v panelu „Přílohy" nezobrazují — je dostupná pouze v tabu „Originál".
Důvod: oddělit audit artefakty od uživatelsky relevantních dokumentů.

### 4.2 Obsahové přílohy

Uloženy standardně v `core_attachments_files` s `table_id = 303` (tableId
`core_mail_incoming_messages`) a `record_id = message.id`. Přes standardní
`AttachmentService` — žádný vlastní flow.

### 4.3 AI analýzy bez `is_current` flagu

Historie je chronologická (`analyzed_at` DESC). „Aktuální" analýza = první
řádek podle `MAX(analyzed_at)` per `message`. Nezavádíme `is_current`,
abychom se vyhnuli nutnosti ho přepínat při každém novém pokusu.

## 5. Stavový automat

Definován v [`config/docStatesIncoming.jsonc`](../config/docStatesIncoming.jsonc)
(`core.mail.docStatesIncoming`). Viz také [`docs/doc-states.md §8.1`](../../../../docs/doc-states.md).

| docState | Název | viewGroup | Přechody do |
|---|---|---|---|
| 10 | Nová | active | 20, 40, 80, 90 |
| 20 | K řešení | active | 40, 10, 80, 90 |
| 40 | Hotovo | active (readOnly) | 80, 90, 10 |
| 80 | Archiv | archive | 10, 90 |
| 90 | Smazáno | trash | 10 |

Je to čistě uživatelský workflow — status AI analýzy žije v ortogonálním
sloupci `analysis_state` (cfgItem `core.mail.analysisStates`). Pipeline na
`docState` sahá jediným místem (result s dokumentovým návrhem: 10 → 20);
do Hotovo zprávu posouvá verdikt nad návrhem (apply/reject). Viz
[ai-analysis.md](ai-analysis.md), sekce „Stavy zprávy".

## 6. Komponenty

### 6.1 Viewer (`IncomingMessagesViewer`)

PHP třída extends `TableViewer`. Layout řádku 5-slotový:

- `t1` — subject (bold, left)
- `i1` — received_at relativně („před 2 h", „včera 14:32", „12. 3.")
- `t2` — sender_name, fallback sender_email
- `i2` — badge s názvem primárního typu (barva dle mapy)
- `t3` — `[mailbox.name]` + první řádek body_plain (preview, zkrácené)

Detail panel: 4 taby — Obsah (včetně sekce příloh) / Analýzy / Návrh /
Originál (labely z cfgItem `core.mail.viewerDetailLabels`).

Fulltext hledá v `subject`, `sender_email`, `sender_name`, `body_plain`.

### 6.2 Formulář (`IncomingMessagesForm`)

PHP třída extends `TableForm`. Tři taby:

1. **Zpráva** — dva sloupce (1:1). Vlevo primární typ (select),
   odesílatel (email + jméno), předmět, tělo (textarea); vpravo
   read-only náhledy příloh (`component` `attachmentsView`, PDF a
   obrázky nahoře, scroll uvnitř sloupce — fill mechanismus, viz
   `docs/edit-forms.md` / Layout)
2. **Přílohy** — standardní `AttachmentPanel` (drag & drop, tableId = 303);
   správa příloh (upload / mazání / přejmenování)
3. **Nastavení** — schránka (select, trigger reload) a datum doručení

Změna schránky spustí `recalculate` — pokud uživatel nemá `primary_type`
vyplněný, doplní se `mailbox.default_primary_type` (fallback `other`).

### 6.3 Document (`IncomingMessageDocument`)

Životní hooky:

- `validate()` — povinné pole `mailbox`, `subject`, `sender_email` (validní e-mail), `received_at`
- `beforeSave()` — normalizace sender_email (lowercase/trim), default `source_type = 1`,
  generování `message_id` ve tvaru `MSG-YYYYMMDD-NNNN`, audit pole
- `beforeDelete()` — cascade delete přidružených analýz a obsahových attachmentů
  (Shipard nepoužívá FOREIGN KEY, integritu řeší aplikace)

## 7. CLI příkazy

```bash
# Z adresáře data sourcu
cd /opt/shipard/data-sources/<ds-id>

shpd-ds seed-mail                  # 3 schránky (TEST-invoices, -info, -support) + 60 zpráv
shpd-ds seed-mail --count 80       # ručně 80 zpráv
shpd-ds seed-mail --attachment-ratio 50  # jen 50 % zpráv dostane PDF přílohu

shpd-ds seed-mail-clear            # smaže TEST- schránky a TEST-MSG- zprávy včetně analýz a attachmentů
```

Seed příkaz je idempotentní nad `TEST-` schránkami — pokud existují, použije je.
`message_id` indexy pokračují od posledního použitého (lze volat opakovaně a doplňovat).

## 8. Extensions (budoucí)

Jiné moduly mohou rozšířit tabulky `core_mail` o sloupce, které je zajímají.
Příklady:

- `economy.docs` přidá `extensions/ext-core-mail-incoming-messages.jsonc` s FK
  na `invoice_ref` pro rychlý přímý odkaz bez polymorfní vazby.
- `base.persons` přidá auto-match logiku na `sender_email` → `sender_person`.

Ve Fázi 1 žádný jiný modul tabulky `core.mail` nerozšiřuje.

## 9. Mail-router integrace (Fáze 2a)

### 9.1 Auto-provisioning

Při každém `ds-upgrade` se zajistí existence:

- Systémového uživatele `_mail_router` (`is_system = 1`, nelogovatelný heslem)
- Výchozí schránky `default` s `is_default = 1` a `email_address = {ds-id}@shipard.email`

Implementace: [`MailRouterProvisioner`](../src/MailRouterProvisioner.php). Pro
existující DS založené před Fází 2a lze spustit ručně přes
`bin/shpd-ds mail-router-bootstrap`.

### 9.2 API klíče

Endpoint `/_mail/incoming` se autentizuje přes `shpd_ak_` klíč. Klíč se generuje
přes `bin/shpd-ds mail-router-setup` (viz [api-contract.md §6](../../../../docs/mail/api-contract.md)).

Controller navíc vynucuje, aby volající uživatel byl `_mail_router` — klíče
vydané ostatním uživatelům tento endpoint nesmí volat (403).

### 9.3 Idempotence

Mail-router posílá `X-Idempotency-Key` hlavičku (sha256 z `domain/local_part/Message-ID`).
Shipard drží 7denní cache v `core_mail_incoming_idempotency` — při retry vrátí
identickou odpověď s `idempotent_replay: true`. Cleanup přes cron:
`bin/shpd-ds mail-idempotency-prune`.

### 9.4 Atomicita

Uložení zprávy + raw `.eml` + přílohy proběhne v jedné DB transakci. Při selhání
uprostřed se provede rollback a orphan soubory na disku se vyčistí.

## 10. Bezpečnost (výhled)

- Rate limiting pro `/_mail/incoming` (zatím nevynuceno — router je trusted).
- Sanitace HTML těla při zobrazení (klient: trustované rendering nebo iframe).
- Per-request size limit na úrovni nginx / PHP.
- Scope restrictions na API klíče (jen vybrané endpointy) — follow-up.

## 11. Odkazy

- PRD Fáze 1: [`tasks/mail-phase1.md`](../../../../tasks/mail-phase1.md)
- PRD Fáze 2a: [`tasks/mail-phase2a.md`](../../../../tasks/mail-phase2a.md)
- API kontrakt: [`docs/mail/api-contract.md`](../../../../docs/mail/api-contract.md)
- docStates specifikace: [`docs/doc-states.md`](../../../../docs/doc-states.md)
- Attachments systém: [`docs/attachments.md`](../../../../docs/attachments.md)
