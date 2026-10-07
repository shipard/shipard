# Import Odeslané pošty — endpoint `POST /_mail/sent/import` (#104)

**Stav:** hotovo — implementováno 2026-10-06 (3 commity, #104 D1–D5); `ds-upgrade` a E2E přes API klíč na `4l3j` ověřeny, 2026-10-07 ověřeno reimportem čtyř migrovaných zdrojů na dev serveru (runner ve starém Shipardu); zbývá `ds-upgrade` na alfě při nasazení

## Kontext

Odeslaná pošta (#90 D40–D45, `docs/mail/sent.md`) eviduje, co, komu a kdy
odešlo, nad libovolným záznamem. Obsah zprávy je pevný (D41): zpráva vzniká
jen odesláním, rovnou ve stavu Odeslaná, a přílohy k ní nejde přidat.

Starý Shipard měl totéž jako typ zprávy „Odeslaná pošta“. Import do nové
strany dnes nejde:

- generické CRUD odmítne POST (`systemManaged`),
- `SentMessageDocument` odmítne založení,
- `SentMessageAttachmentGuard` odmítne nahrání přílohy,
- `SentMessageStore::create()` přepíše `created` na aktuální čas
  a transport na „Ve frontě“.

Tento task přidává **importní endpoint**: jedno atomické volání založí
zprávu i s přílohami. Volajícím je runner importu ve starém Shipardu
(soukromé repo), který se píše zvlášť a nasazuje až po této straně.

Data (šest migrovaných zdrojů, jen počty): ≈ 28 tis. zpráv, ≈ 46 tis.
příloh (PDF tisku a ISDOC, ≈ 3 GB). Zhruba 4 % zpráv nemá zaznamenanou
adresu příjemce. Odesílatele starý systém neukládal nikdy — runner ho
dopočítá pravidlem starého formuláře odeslání. Detail v #104.

## Před implementací přečti

- `docs/mail/sent.md` — model, pevný obsah, transport, Odeslat znovu, UI
- `modules/core/mail/tables/core_mail_sent_messages.md` (+ `.jsonc`)
- `modules/core/mail/src/Sent/`:
  - `SentMessageStore.php` — `create()`, stavy, konstanty,
  - `SentMessageDocument.php` — `MUTABLE_COLUMNS`,
  - `SentMessageAttachmentGuard.php`,
  - `SentMessageTransport.php` — `resend()`,
  - `SentMessageTransportInfo.php` — `state()`, `describe()`, `canResend`,
  - `RecordSendService.php` a `RecordSendServiceFactory.php` — zpráva
    a přílohy v jedné transakci, `AttachmentService` **bez guardů**
- `src/Api/Controller/MailController.php`:
  - `importMessage()` — vzor importu pod API klíčem,
  - `collectAttachmentFiles()` a `cleanupOrphanedFiles()` — multipart
    a úklid souborů po chybě
- `src/Api/Controller/SentMessagesController.php`, `src/Api/Router.php`,
  `src/Api/ReadOnlyPolicy.php`
- `modules/base/registry/src/RegistryImportService.php` — deduplikace
  podle identity ze starého systému, odpověď `created`
- `modules/core/attachments/src/AttachmentService.php` — `upload()`
- `docs/table-definitions.md` — bezpečné změny schématu
- `docs/mail/outbound.md` — `AddressList`
- `help/posta/odeslana-posta.md`, `docs/help-authoring.md`
- `frontend/src/components/form/SentMessageTransport.svelte`

## Scope

**V rozsahu:**

- sloupec `import_ref`,
- hodnoty `send_trigger = import` a `transport_state = unknown`,
- služba importu a endpoint,
- zákaz Odeslat znovu u importované zprávy (server i UI),
- dokumentace a nápověda.

**Mimo rozsah:**

- runner ve starém Shipardu (soukromé repo),
- kanál datová schránka (ve zdrojích se nevyskytuje),
- HTML tělo,
- ruční import z UI.

## 1. Schéma a číselníky (D2, D4)

- **`core_mail_sent_messages.import_ref`** — `varchar(100)`, nullable,
  skupina `status`. Identita zprávy ve zdrojovém systému
  (`oldShipard:<ndx>`); u zpráv vzniklých odesláním NULL. Unikátní index
  `idx_import_ref` (MariaDB připouští víc NULL). Doplnit
  `core_mail_sent_messages.md`.
- **`core.mail.sendTriggers`** + `import` — „Import“ / „Import“.
- **`core.mail.transportStates`** + `unknown` — „Nezjištěno“ / „Unknown“.
  Neutrální varianta štítku z `docs/design-system.md` §5, ne
  success / warning / danger.
- `SentMessageDocument` nic nového nepotřebuje — `import_ref` není
  v `MUTABLE_COLUMNS`, takže změnu obsahu odmítne jako ostatní sloupce.
  Pokryj to testem.

## 2. Služba importu (D1–D4)

Nová třída `SentMessageImportService` v `modules/core/mail/src/Sent/`:

```php
public function import(array $payload, array $files, int $apiUserId): SentMessageImportResult
// $files = list<array{name: string, tmp_name: string}> v pořadí z požadavku
```

`SentMessageStore` dostane `import(array $fields): int` (vloží řádek tak,
jak přišel — `created` ani transport nepřepisuje) a
`findByImportRef(string $ref): ?int`. `create()` zůstává beze změny.

### Kontrakt payloadu (D3)

| Pole | Povinné | Pravidlo |
|---|---|---|
| `import_ref` | ano | neprázdné, max 100 znaků |
| `created` | ano | ISO 8601; bez zóny = čas serveru |
| `email_from` | ano | platná adresa |
| `email_from_name` | ne | ořez na 200 |
| `email_to` | ne | pole adres; každá musí být platná, uloží se ve formátu `AddressList`; celkem max 2000 znaků |
| `email_cc` | ne | totéž; prázdné → NULL |
| `subject` | ne | prázdné → „(bez předmětu)“; ořez na 500 |
| `body_text` | ne | prostý text |
| `recipient_person` | ne | existující osoba |
| `target_table_id`, `target_row` | ne | obojí, nebo nic; tabulka známá zdroji dat, řádek existuje |
| `target_label` | ne | ořez na 250 |
| `purpose` | ne | id z cfgItemu `base.persons.sendPurposes` |
| `doc_state` | ne | 40 (výchozí), 70 nebo 90 |
| `created_by` | ne | existující uživatel |

Porušení pravidla → 422 `VALIDATION_ERROR` s `field` a `code`.

### Co služba doplní sama

| Sloupec | Hodnota |
|---|---|
| `channel` | `email` |
| `send_trigger` | `import` |
| `language`, `print_id`, `last_outbox_id`, `last_error`, `safety_*` | NULL |
| `modified` | = `created` |
| `docStateMain` | z `core.mail.docStatesSent` |

Stav transportu (D4) se **odvozuje, neposílá**:

| `email_to` | `transport_state` | `sent_at` | `send_count` |
|---|---|---|---|
| neprázdné | `sent` | `created` | 1 |
| prázdné | `unknown` | NULL | 0 |

### Průběh

1. **Deduplikace** — `findByImportRef()`. Při shodě nic nezakládat
   (ani soubory) a vrátit `{id, created: false}`. Obsah se neporovnává.
2. **Validace** payloadu (tabulka výše).
3. **Transakce:**
   - `store->import()`,
   - přílohy v pořadí `$files` přes `AttachmentService` **bez guardů**
     (`upload(SentMessageStore::TABLE_ID, $id, $name, $tmp, $createdBy)`,
     vzor `RecordSendService`); stejný obsah dvakrát v jedné zprávě
     se uloží dvakrát (tak odešel),
   - nula příloh je v pořádku.
4. **Chyba v transakci** (zápis řádku, uložení souboru) → rollback
   a smazání už uložených souborů (vzor `cleanupOrphanedFiles`).
   Unikátní index na `import_ref` při souběhu → stejná odpověď jako
   deduplikace.

## 3. Endpoint (D1)

`POST /_mail/sent/import` → `SentMessagesController::import()`, routa
v `Router` vedle `/_mail/import`.

- **Autorizace:** API klíč (jako `/_mail/import`); jiný token → 401.
- **Tělo:** `multipart/form-data`:
  - pole `payload` = JSON objekt (kontrakt výše),
  - soubory `attachments[]` v pořadí, ve kterém mají být u zprávy;
    název souboru z multipartu = název přílohy.
- **`ReadOnlyPolicy`:** routa nemá výjimku → na zdroji jen pro čtení 403.

| Odpověď | Kdy |
|---|---|
| 201 `{id, created: true, attachments: N}` | zpráva založena |
| 200 `{id, created: false}` | `import_ref` už existuje |
| 400 `BAD_REQUEST` | chybí `payload` nebo to není JSON objekt |
| 401 `UNAUTHORIZED` | bez API klíče |
| 422 `VALIDATION_ERROR` | porušený kontrakt |

## 4. Odeslat znovu u importované zprávy (D5)

- `SentMessageException::IMPORTED`. `SentMessageTransport::resend()` ho
  vyhodí pro `send_trigger = import`, hned po kontrole existence.
  `SentMessagesController` → 409 `IMPORTED`.
- `SentMessageTransportInfo::describe()`: `canResend = false` pro
  `send_trigger = import`.
- Frontend (`SentMessageTransport.svelte`): u importované zprávy tlačítko
  **Odeslat znovu** nezobrazovat (ne jen disabled) a místo něj krátký
  text „Importovaná zpráva — znovu ji neodešleš; pro nové odeslání použij
  Odeslat u záznamu“. Rozhodne se podle hodnoty z `describe()` (přidej
  `imported: bool`), ne podle textu. i18n `cs` / `en` a `error.IMPORTED`
  v `frontend/src/i18n/errors.js`.
- Stav `unknown` se vykreslí z cfgItemu jako ostatní stavy. Ověř, že
  `state()`, agenda, formulář i sekce u záznamu nepředpokládají jen tři
  hodnoty a že prázdné „Komu“ ukazují jako „—“. Štítek pojistky se
  u `unknown` neukazuje — `state()` ho už bere jen u `sent`.

## 5. Dokumentace a nápověda

- `docs/mail/sent.md`:
  - nová sekce **Import** — kontrakt endpointu, D1–D5, odvození
    transportu, deduplikace,
  - v sekci Odeslat znovu `IMPORTED`.
- `modules/core/mail/tables/core_mail_sent_messages.md` — `import_ref`,
  nové hodnoty.
- `CLAUDE.md` — řádek `docs/mail/sent.md` v tabulce dokumentů doplnit
  o „import (`POST /_mail/sent/import`, #104)“.
- `help/posta/odeslana-posta.md`:
  - stav **Nezjištěno** do tabulky stavů,
  - odstavec „Zprávy ze starého Shipardu“ do Na co narazíš: štítek
    Import, odesílatel dopočítaný, Odeslat znovu nejde,
  - klíčová slova.

  Poté `python3 scripts/help-index.py`. Popisky ověř ve zdroji
  (`cs.js`, cfgItemy).

## Testy

- `SentMessageImportServiceTest`:
  - každé pravidlo kontraktu (422 s `field`),
  - odvození transportu,
  - zachované `created`,
  - deduplikace bez zápisu a bez souborů,
  - pořadí příloh = pořadí v požadavku,
  - chyba uložení přílohy → žádný řádek, žádné soubory.
- `SentMessageStoreTest` — `import()`, `findByImportRef()`.
- `SentMessageDocumentTest` — změna `import_ref` odmítnuta.
- `SentMessageTransportTest` — `resend()` u importu → `IMPORTED`.
- `SentMessageTransportInfo` — `canResend = false`, `imported = true`,
  stav `unknown`.
- Router — metoda a cesta.
- Integrační test služby nad volným zdrojem z `CLAUDE.local.md`
  v transakci s rollbackem (vzor `RecordSendTest`), soubory do dočasného
  adresáře s úklidem.

PHPUnit jen s úzkým `--filter`, celou sadu až na konci.

## Task breakdown

### Commit 1 — Schéma, store a služba importu (D2–D4)

Sloupec, cfgItemy, `SentMessageStore::import()` / `findByImportRef()`,
`SentMessageImportService`, testy.

### Commit 2 — Endpoint a Odeslat znovu (D1, D5)

Routa, controller, `IMPORTED`, `describe()`, frontend + i18n, testy.

### Commit 3 — Dokumentace a nápověda

`docs/mail/sent.md`, tabulka `.md`, `CLAUDE.md`, nápověda,
`help-index.py`, `**Stav:**` + `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `ds-upgrade` na volném zdroji přidá sloupec a index; existující
      zprávy beze změny. (`4l3j`, 2026-10-06: `added column: import_ref`,
      `added index: unq_import_ref`)
- [x] Import přes API klíč na volném zdroji (zpráva navázaná na doklad,
      PDF + ISDOC) → 201. Zpráva je v agendě i v sekci Odeslaná
      pošta u dokladu s náhledem PDF, Spuštěno „Import“, bez tlačítka
      Odeslat znovu. (Na `4l3j` nejsou faktury vydané — navázáno na
      fakturu přijatou, mechanismus je stejný; formulář ověřen i v headless
      Chromiu.)
- [x] `POST /_sent-messages/{id}/resend` → 409 `IMPORTED`.
- [x] Opakovaný import se stejným `import_ref` → 200 `created: false`,
      žádná nová zpráva ani příloha.
- [x] Zpráva s prázdným `email_to` → stav Nezjištěno, „Komu“ „—“.
- [x] Generický upload přílohy k importované zprávě → 409
      `ATTACHMENT_LOCKED` (guard beze změny).
- [x] Zdroj jen pro čtení → 403 `DS_READ_ONLY`.
- [x] Testy (`--filter`), `npm run build`, `npm run check:i18n`.
- [x] Dokumentace, nápověda a indexy aktualizované.

## Odchylky od zadání při implementaci

- Unikátní index se jmenuje `unq_import_ref` (konvence
  `docs/table-definitions.md` → Pojmenování indexů), ne `idx_import_ref`.
- `created_by` zprávy jde výhradně z payloadu (bez něj NULL — uživatel API
  klíče zprávu nepodepisuje, vzor `RegistryImportService`); autor příloh =
  `created_by` z payloadu, bez něj uživatel API klíče.
- Sběr souborů z multipartu je sdílený helper `Shipard\Api\MultipartFiles`
  (dřív privátní metoda `MailController`), aby se nekopíroval.
- Detail zprávy v agendě navíc ukazuje položku **Spuštěno** (label
  z `core.mail.sendTriggers`) — bez ní by „Import“ nebylo kde vidět.
- `email_to` přijímá seznam i text s čárkami (oboje umí `AddressList`).

## Ověření importem (2026-10-07)

Úplný reimport čtyř migrovaných zdrojů na dev serveru runnerem ve starém
Shipardu (tři s přílohami, jeden bez). Kontrola SQL proti staré databázi,
jen počty:

- zpráv 26 251 = staré zprávy mimo koš na všech čtyřech zdrojích;
  duplicitní `import_ref` 0;
- přílohy 6 699 ze 6 700 (jeden soubor chybí na disku zdroje); první
  příloha zprávy je PDF tam, kde byla první i ve starém;
- `created` = starý čas odeslání (min, max i součet hodin shodné — žádný
  posun časové zóny);
- vazby na doklady, osoby a došlou poštu sedí na staré vazby mimo záznamy,
  které se neimportovaly (koš, zakázky);
- stav Nezjištěno má 1 247 zpráv (bez adresy, s prázdnou nebo neplatnou
  adresou), placeholder odesílatele 0.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1** — `POST /_mail/sent/import`, jedno atomické multipart volání
  (zpráva + přílohy v jedné transakci, `AttachmentService` bez guardů),
  žádný mezistav, guard beze změny.
- ✓ **D2** — `send_trigger = import` a unikátní `import_ref`; při shodě
  vrátí existující zprávu (`created: false`).
- ✓ **D3** — obsah, časy, vazbu, popisek, osobu, účel, autora a stav
  přebírá z payloadu; `language` a `print_id` prázdné; validují se
  existence vazby, osoby a uživatele.
- ✓ **D4** — stav transportu `unknown` „Nezjištěno“ pro zprávu bez
  adresy; s adresou `sent`, `sent_at` = čas odeslání, `send_count` 1.
- ✓ **D5** — importovanou zprávu nejde odeslat znovu (409 `IMPORTED`,
  tlačítko skryté); nové odeslání = Odeslat na záznamu.
