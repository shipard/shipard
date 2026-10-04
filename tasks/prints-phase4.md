# Tisky — Fáze 4: odesílání e-mailem (účely kontaktů, příjemci, odesílatel, Odeslaná pošta)

**Stav:** částečně — implementace hotová (7 commitů); zbývá ověření skutečného odeslání přes SMTP (dev server nemá relay), ruční proklik dialogu a náhledů PDF, `ds-upgrade` zdrojů dat při nasazení a revize `sk` / `de` textů e-mailu (`tasks/prints-languages.md`)

> PRD pro Claude Code (7 commitů). Design: issue #90, komentáře
> „Rozhodnutí: fáze 4 — odesílání e-mailem (D34–D39)“ a „Rozhodnutí:
> Odeslaná pošta (D40–D45)“ (nahrazuje D10 a D36); základ D11 (tělo #90). Navazuje na #94 (jazyk osoby, spojování příloh do PDF,
> příznak „Odeslat se záznamem“) a na `tasks/prints-languages.md`.

## Kontext

Tisky umí vyrobit PDF libovolného deklarovaného tisku (`docs/prints.md`),
odchozí pošta umí frontu, transporty a přílohy (`docs/mail/outbound.md`).
Chybí to mezi nimi: komu poslat, odkud, s jakým textem, co poslat s tím
a kde je vidět, že se poslalo. Roadmapa M4 to vede jako blokátor migrace.

Jádrem je **Odeslaná pošta** (D40–D45): evidence odeslaných zpráv nad
libovolnou tabulkou, oddělená od transportu. Odeslání faktury vytvoří
zprávu (obsah — předmět, tělo, příjemci, přílohy — je pevný) a zpráva se
předá outboxu. Faktura ví, co odešlo, přes zprávy, které na ni ukazují;
zprávu jde kdykoliv odeslat znovu se stejnými přílohami.

Primární cesta budoucnosti je automatické a hromadné odesílání (D11) —
tahle fáze ho **nestaví**, ale staví službu, kterou dávka i plánovač
později zavolají beze změny. Ruční dialog je aparát pro výjimky
(„faktura mi nepřišla, pošlete ji znovu“).

Odesílat se musí umět **cokoliv**, co je deklarované jako tisk ven,
nad libovolnou tabulkou — ne jen doklady.

## Před implementací přečti

- Issue #90 — D2, D4, D11, D21, D34–D45 (D40–D45 nahrazují D10 a D36); issue #94 — D1–D11
- `docs/prints.md` celé; `docs/mail/outbound.md` celé
- `src/Core/Prints/` — `PrintDefinition`, `PrintRegistry`, `PrintRunner`,
  `PrintLanguageResolver`; `src/Core/I18n/DocumentLanguageResolver.php`
- `src/Core/Mail/OutboundMessage.php`, `MailOutboxService.php`,
  `MailComposer.php`, `TransportResolver.php`,
  `modules/core/mail/tables/core_mail_outbox.jsonc`,
  `core_mail_senders.jsonc`
- `modules/core/attachments/src/AttachmentService.php` (`upload`,
  `copyTo`, `listAttachments`, `setSendWithRecord`),
  `core_attachments_files.jsonc` (`send_with_record`)
- `modules/base/persons/tables/base_persons_persons.jsonc` (`email`,
  `language`, volba spojování příloh z #94 D5),
  `base_persons_contacts.jsonc` + `.md`, `PersonsForm.php`
- `modules/docs/core/tables/docs_core_number_series.jsonc`,
  `NumberSeriesForm.php`
- `src/Core/Render/RenderClient.php` — post-processing `appendPdfs`
- `src/Api/Controller/PrintsController.php`, `ViewerController::detail()`
  (háček akce Tisk), `src/Api/TableAccessGuard.php`, `ReadOnlyPolicy`
- `frontend/src/components/viewer/PrintPreviewDialog.svelte`,
  `frontend/src/api/prints.js`
- `modules/core/mail/tables/core_mail_incoming_messages.jsonc` — **vzor
  konvence sloupců** (odesílatel, partner, vazba `target_table_id` /
  `target_row`), jeho formulář a viewer (agenda v sekci Pošta)
- `modules/core/system/config/docStatesArchive.jsonc` — vzor sady stavů
- `docs/auth.md` — model oprávnění (D16: jen `is_admin`)

## Scope

**Uvnitř:**

- Účely odesílání (cfgItem), sada účelů na kontaktu osoby, formulář (D34).
- Resolver příjemců s důvody (D35).
- Rozšíření odchozí pošty: víc adres v „Komu“, kopie, jméno odesílatele.
- Odesílatel: resolver, volba „Odesílat z“ na číselné řadě (D39).
- `sendPurpose` v deklaraci tisku, šablony předmětu a těla e-mailu (D37).
- Odeslaná pošta: tabulka, stavy, formulář, agenda (D40, D41, D45).
- Služba odeslání záznamu, transport zprávy, Odeslat znovu (D42–D44).
- REST, CLI `print-send`, dialog Odeslat, sekce Odeslaná pošta v detailu
  záznamu (D38, D45).
- Testy, `docs/prints.md`, `docs/mail/outbound.md`, nápověda.

**Mimo:**

- Hromadné a automatické odesílání (dávka, plánovač, idempotence) —
  samostatná diskuse; Odeslaná pošta a služba ji umožní.
- Ruční zpráva bez tisku (e-mail osobě s vlastními přílohami) — model
  ji umožní, UI později.
- Kanál datová schránka (sloupec `channel` jen připravený).
- Společný pohled došlá + odeslaná pošta, vlákna.
- Import vazeb kontaktů ze starého Shipardu — samostatný task.
- ISDOC (generátor i vložení do PDF) — samostatný task.
- Uživatelské texty e-mailů (fáze 3, D9).
- Režim odesílatele „autor dokladu“ (#93).
- Sledování doručení, bounce, přečtení.
- HTML tělo e-mailu (v1 jen text).

## 1. Účely odesílání (D34)

- cfgItem **`base.persons.sendPurposes`** (`modules/base/persons/config/sendPurposes.jsonc`):
  ```jsonc
  {
      "invoices":     { "name": "Invoices and tax documents", "name:cs": "Faktury a daňové doklady", "name:en": "…", "name:sk": "…", "name:de": "…", "order": 10 },
      "reminders":    { "name": "Payment reminders", "name:cs": "Upomínky", …, "order": 20 },
      "offersOrders": { "name": "Offers and orders", "name:cs": "Nabídky a objednávky", …, "order": 30 },
      "reports":      { "name": "Statements and reports", "name:cs": "Přehledy a výpisy", …, "order": 40 }
  }
  ```
  Moduly ho můžou rozšířit stejným mechanismem, jakým se slučují jiné
  cfgItemy z více modulů — **ověř, jak to dnes funguje**, a popiš
  v `docs/prints.md`; když slučování neexistuje, zastav se a zeptej se.
- Kontakt: nový sloupec **`send_purposes`** (`json`, pole id účelů,
  nullable = žádný účel) v `base_persons_contacts`. Validace: jen id
  z cfgItemu, bez duplicit. `.md` tabulky doplnit.
- Formulář kontaktu: výběr více účelů (použij existující widget pro
  více hodnot, je-li; jinak skupina zaškrtávátek). Seznam kontaktů
  v detailu osoby ukáže účely jako štítky.
- Výměnný formát osob (`docs/exchange-format-persons.md`): pole
  `sendPurposes` u kontaktu — export i applier, aby import ze starého
  Shipardu (samostatný task) měl kam zapisovat. Verze formátu podle
  pravidel dokumentu.

## 2. Příjemci (D35)

`RecipientResolver::resolve(int $personId, string $purpose): RecipientResolution`

1. Platné kontakty osoby — `docState IN (10, 40, 80)`, `valid_from`
   ≤ dnes ≤ `valid_to` (prázdné = neomezeno) — s neprázdným e-mailem
   a s `purpose` v `send_purposes`. **Všechny** do „Komu“, seřazené
   podle `order_pos`, `id`; duplicitní adresy (bez ohledu na velikost
   písmen) jen jednou.
2. Když žádný: `base_persons_persons.email`, je-li neprázdný.
3. Jinak prázdný výsledek s chybou `NO_RECIPIENT`.

- Kontakt bez účelu se **nepoužije** (ani v kroku 2).
- Každá adresa nese důvod: `{email, name, source: "contact"|"person",
  contactId?, label}` — `label` lidsky („Kontakt Účtárna — Faktury
  a daňové doklady“, „E-mail osoby“), v jazyce UI.
- Adresy se validují (syntakticky); nevalidní se přeskočí s varováním
  v `messages`.
- Záznam bez osoby (tisk nad tabulkou, kde partner chybí) → `NO_RECIPIENT`.
  Osobu záznamu určuje deklarace tisku (viz §5, `recipientPerson`).

## 3. Odchozí pošta: víc příjemců, kopie, jméno odesílatele

Dnes `OutboundMessage::$to` je jedna adresa (`email_to` varchar 200)
a odesílatel nemá jméno. Rozšířit zpětně kompatibilně:

- `OutboundMessage`: `to` může být seznam (`string|array`), nové
  `cc` (`array`, výchozí prázdné), `fromName` (`?string`).
- `core_mail_outbox`: `email_to` zvětšit (`text` nebo dostatečný
  `varchar`) a ukládat čárkami oddělený seznam; nové `email_cc`
  (nullable) a `email_from_name` (nullable). Migrace podle pravidel
  schématu.
- `MailComposer`: `to(...)`, `cc(...)`, `from(new Address($email, $name))`.
- `TransportResolver` dál podle adresy odesílatele (jméno nehraje roli).
- Existující volající beze změny chování; testy outboxu rozšířit.
- `docs/mail/outbound.md` doplnit.

## 4. Odesílatel (D39)

`SenderResolver::resolve(PrintDefinition, array $record, ?string $chosen): SenderResolution`

1. `$chosen` (z dialogu / CLI) — musí být v **povolených adresách**,
   jinak chyba `SENDER_NOT_ALLOWED`.
2. Poskytovatel odesílatele pro tabulku záznamu — rozhraní
   `RecordSenderProvider`, registrované per tabulka (v1 jen
   `docs_core_heads`: podle číselné řady dokladu).
3. `mail.defaultFrom`.
4. Nic → chyba `NO_SENDER`.

- **Povolené adresy** = `mail.defaultFrom` + `email_from` aktivních
  záznamů `core_mail_senders`. Endpoint pro nabídky (dialog, číselná
  řada) — použij existující lookup, je-li, jinak
  `GET /_mail/sender-addresses`.
- **Jméno odesílatele**: z číselné řady (je-li), jinak název vlastní
  firmy (`OwnCompanyResolver` → osoba), jinak bez jména.
- **Číselná řada** (`docs_core_number_series`): nové `email_from`
  (nullable — NULL = „Automaticky“) a `email_from_name` (nullable).
  Formulář řady: sekce „Odesílání e-mailem“, výběr z povolených adres
  + „Automaticky“, jméno volitelné. Uložená adresa, která přestala
  být povolená (odesílatel deaktivován), → při odeslání `SENDER_NOT_ALLOWED`
  s jasnou hláškou (ne tichý fallback).
- Volba „autor dokladu“ až s #93 — enum nezavádět předem.

## 5. Deklarace tisku a texty e-mailu (D34, D37)

- Deklarace `prints` — nová volitelná pole:
  - `sendPurpose` — id účelu; jen u `audience: external` (jinak chyba
    loaderu). Tisk bez `sendPurpose` nejde odeslat.
  - `recipientPerson` — sloupec záznamu s osobou příjemce
    (pro `docs_core_heads` `partner`). Povinné, když je `sendPurpose`.
- Doplnit: faktura, zálohová faktura, pokladní doklad, prodejka →
  `sendPurpose: "invoices"`, `recipientPerson: "partner"`. Kontace ne.
- **Šablony e-mailu** v adresáři šablony tisku:
  `email-subject.txt.twig` a `email-body.txt.twig`, render stejným
  sandboxem a nad stejným `PrintData` (D6) — ale **bez HTML autoescape**
  (výstup je prostý text; ověřit, že z dat nejde podstrčit hlavičky —
  předmět se zbaví konců řádků). Texty přes `t()` z katalogů tisku
  (klíče `email.subject.*`, `email.body.*`), ve všech jazycích tisku —
  úplnost hlídá stávající test katalogů.
- Sdílené výchozí šablony dokladů v `@docs.core/_layout/`
  (`email-subject.txt.twig`, `email-body.txt.twig`); šablona tisku je
  může přebít vlastními soubory (stejně jako záhlaví a zápatí).
  Tisk se `sendPurpose` bez dosažitelných e-mailových šablon = chyba
  loaderu / testu deklarací.
- Obsah v1 (doklady): předmět „<titulek> <číslo> — <dodavatel>“; tělo
  krátké — oslovení, co je v příloze, částka k úhradě a splatnost (jsou-li),
  podpis názvem dodavatele. Formulace navrhni, jazyky jako katalogy
  šablon (slovenské a německé texty přidej k „Formulacím k revizi“
  v `tasks/prints-languages.md`).

## 6. Odeslaná pošta (D40, D41)

Nová tabulka **`core_mail_sent_messages`** v modulu `core.mail` —
dokument nad libovolnou tabulkou. Názvy sloupců drž v konvenci došlé
pošty (`core_mail_incoming_messages`), kde to dává smysl.

| Sloupec | Popis |
|---|---|
| `id`, `doc_state` | PK, stav dokumentu (níže) |
| `channel` | `email` (rezerva `databox`) |
| `subject`, `body_text` | předmět, tělo (prostý text) |
| `email_from`, `email_from_name` | odesílatel |
| `email_to`, `email_cc` | příjemci (seznam), kopie (nullable) |
| `recipient_person` | osoba příjemce (nullable) |
| `target_table_id`, `target_row` | záznam, ke kterému zpráva patří (nullable) |
| `purpose`, `language`, `print_id` | účel, jazyk, tisk (nullable — zpráva nemusí být z tisku) |
| `transport_state` | `queued` / `sent` / `failed` — výsledek posledního průchodu transportem |
| `sent_at`, `send_count`, `last_error` | poslední úspěšné odeslání, počet úspěšných odeslání, poslední chyba |
| `last_outbox_id` | poslední řádek outboxu (nullable po úklidu) |
| `trigger` | `manual` / `cli` (rezerva `batch`) |
| `created`, `created_by`, `modified` | |

- Indexy: `(target_table_id, target_row, created)`, `recipient_person`,
  `doc_state`.
- **Přílohy zprávy** = přílohy v `core_attachments_files` s tabulkou
  `core_mail_sent_messages` a řádkem zprávy.
- **Stavy** — vlastní sada (ne `docStatesArchive`, ta má Koncept
  a V opravě): `40` Odeslaná, `70` V archivu, `90` Smazaná;
  `40 → 70, 90`, `70 → 40`, `90 → 40`. Zpráva vzniká rovnou ve stavu
  40, žádný Koncept (D41).
- **Obsah je pevný** (D41): po vytvoření nejde měnit předmět, tělo,
  odesílatele, příjemce, vazbu ani přílohy — formulář je jen pro čtení
  kromě stavu; API zápisu tyto sloupce i přílohy odmítne. Fyzicky se
  zpráva nemaže nikdy (ani ze stavu 90).
- **Formulář** zprávy: hlavička (kdy, kdo, odesílatel, příjemci, osoba,
  odkaz na záznam), předmět, tělo, přílohy (náhled), stav transportu
  a historie pokusů (z `core_mail_outbox_log`, dokud existuje), akce
  **Odeslat znovu** (§8), Archivovat, Smazat, Obnovit.
- **Agenda Odeslaná pošta** v sekci Pošta (vedle došlé): datum, osoba
  a adresy příjemce, předmět, záznam (popisek), stav transportu.
  Archivované a smazané podle běžných filtrů stavu.

## 7. Služba odeslání záznamu (D42, D44)

`RecordSendService::prepare(SendRequest): SendDraft` a
`RecordSendService::send(SendRequest): SendResult`

`SendRequest`: `printId`, `recordId`, volitelně `language`, `from`,
`to[]`, `cc[]`, `subject`, `body`, `attachmentIds[]` (null = výchozí),
`userId`, `trigger` (`manual` | `cli`; rezervováno `batch`).

**`prepare`** (návrh pro dialog, bez vedlejších účinků):
1. Definice + dostupnost (D2, stav záznamu) jako u tisku; bez
   `sendPurpose` → `PRINT_NOT_SENDABLE`.
2. Osoba příjemce (`recipientPerson`), jazyk dokumentu (#94 D2 /
   parametr), příjemci (§2 — **živě z osoby a kontaktů, ne ze snapshotu
   dokladu**, D44), odesílatel (§4) a nabídka povolených adres.
3. Předmět a tělo (§5) v jazyce dokumentu.
4. Přílohy: přílohy záznamu s `send_with_record = 1`, u každé příznak,
   zda se připojí do PDF (PDF + volba osoby #94 D5 / D11), nebo půjde
   zvlášť.
5. Vrátí návrh + `messages` (`NO_RECIPIENT`, `NO_SENDER`, varování
   builderu tisku).

**`send`** — vždy vytvoří **novou** zprávu (D44):
1. `prepare` s hodnotami z požadavku (přepsané pole má přednost).
   Prázdné „Komu“ nebo chybějící odesílatel → chyba, nic se nevytvoří.
2. PDF tisku (`PrintRunner`, `pdf`, zvolený jazyk), znovu vyrobené;
   připojované PDF přílohy přes `appendPdfs` render služby.
3. Zpráva v Odeslané poště (§6, stav 40, `transport_state = queued`,
   vazba na záznam, osoba, účel, jazyk, tisk).
4. Přílohy zprávy: PDF tisku (`AttachmentService::upload`, název
   `meta.fileName`) + přílohy posílané zvlášť zkopírované ze záznamu
   (`AttachmentService::copyTo`). Na záznamu se **nic nevytváří** —
   zmrazená kopie na záznamu není (D42 nahrazuje D10).
5. Předání transportu (§8).

Body 3–5 v jedné transakci; při chybě nic nezůstane (soubory příloh
na disku uklidit). Ruční odeslání (`manual`) se pokusí odeslat hned
a výsledek vrátí; selhání transportu = zpráva zůstane ve frontě
(stávající retry), dialog to řekne.

Služba nečte HTTP ani UI — volá ji REST, CLI a později dávka.

## 8. Transport zprávy (D43, D44)

`SentMessageTransport::dispatch(int $sentMessageId, bool $sendNow): int`
(vrací id outboxu)

- Sestaví `OutboundMessage` ze zprávy: odesílatel se jménem, všichni
  v „Komu“, kopie, `bodyText`, přílohy = **přílohy zprávy**,
  `recipientPersonId`, `sourceModule: "core.mail"`,
  `sourceRef: "sentMessage:<id>"`; `enqueue` nebo `enqueueAndSend`.
- Zpráva: `transport_state = queued`, `last_outbox_id`.
- **Zpětné propsání výsledku:** `MailOutboxService` při přechodu řádku
  do konečného stavu (odesláno / trvalá chyba) zavolá posluchače
  registrovaného pro `sourceRef` s prefixem `sentMessage:` (malé
  rozhraní `OutboxSourceListener`, registrace podle prefixu). Ten
  nastaví `transport_state`, `sent_at`, `send_count + 1` nebo
  `last_error`. Úklid outboxu tak historii nevezme (D43). Mezistavy
  (retry) zpráva nevidí jinak než `queued`.
- **Odeslat znovu** (D44): `dispatch` téže zprávy — stejní příjemci,
  stejné přílohy, nic nového se nevytváří. Jen pro zprávu ve stavu 40
  (archivovanou nejdřív obnovit). Zpráva právě ve frontě (`queued`) →
  `409 ALREADY_QUEUED`.
- Kanál `databox` jen jako hodnota sloupce; `dispatch` pro jiný kanál
  než `email` = `LogicException`.

## 9. REST a CLI

- `GET /_prints/{printId}/{recordId}/send-draft[?language=]` → návrh
  z `prepare` (příjemci s důvody, povolení odesílatelé + výchozí,
  předmět, tělo, jazyk, jazyky tisku, přílohy, `messages`).
- `POST /_prints/{printId}/{recordId}/send` → `send`; tělo
  `{from, to[], cc[], subject, body, language, attachmentIds[]}`;
  odpověď `{sentMessageId, transportState, messages}`.
- `POST /_sent-messages/{id}/resend` → Odeslat znovu; odpověď
  `{transportState}`.
- Agenda, formulář a změny stavu Odeslané pošty běžnými CRUD /
  viewer endpointy (zápis obsahu odmítnutý, §6).
- Chyby: `PRINT_NOT_SENDABLE` 409, `PRINT_NOT_AVAILABLE` 409,
  `NO_RECIPIENT` 422, `NO_SENDER` 422, `SENDER_NOT_ALLOWED` 422,
  `INVALID_EMAIL` 422, `ALREADY_QUEUED` 409, `INVALID_STATE` 409
  (Odeslat znovu u archivované / smazané), chyby renderu jako u tisku.
- **Práva (D38):** model oprávnění zná jen administrátora (`docs/auth.md`
  D16), takže „smí záznam upravovat“ = projde `guardTable()` na tabulku
  deklarace **a** `ReadOnlyPolicy` jako zápis (POST). Totéž pro
  Odeslat znovu (na `core_mail_sent_messages`). Zapsat do
  `docs/prints.md`, že s jemnějšími právy se tohle zpřísní.
- CLI `shpd-ds print-send <printId> <recordId> [--to=…] [--cc=…]
  [--from=…] [--language=…] [--dry-run]` — `--dry-run` vypíše návrh
  (JSON) a nic nevytvoří. Zápis do `docs/cli.md`.

## 10. UI (D38, D45)

- **Akce Odeslat** v detailu — háček ve `ViewerController::detail()`
  vedle akce Tisk: jen pro tisky se `sendPurpose` dostupné ve stavu
  záznamu; jeden tisk → tlačítko, víc → dropdown.
- **Dialog odeslání** (nová komponenta vedle `PrintPreviewDialog`):
  - Od: výběr z povolených adres (předvybraný výsledek §4).
  - Komu: seznam adres jako štítky s důvodem (tooltip / podtext),
    odebrat, přidat ručně (validace). Kopie: totéž, výchozí prázdná.
  - Jazyk: výběr (jazyky tisku); změna znovu načte návrh (předmět,
    tělo — ručně upravený text se před přepsáním potvrdí).
  - Předmět, tělo (textarea).
  - Přílohy: PDF tisku (nelze odebrat; náhled), přílohy záznamu se
    zaškrtnutím podle `send_with_record` a označením „připojí se do PDF“.
  - `messages` nahoře (žádný příjemce, žádný odesílatel, varování tisku).
  - Odeslat / Zrušit; po odeslání výsledek (odesláno / ve frontě /
    chyba) a obnovení sekce Odeslaná pošta.
  - Mobil: fullscreen.
- **Sekce Odeslaná pošta v detailu záznamu** — generický háček pro
  **libovolnou** tabulku: když na záznam ukazuje aspoň jedna zpráva ve
  stavu 40. Každá zpráva: hlavička (datum a čas, komu, stav transportu)
  a náhledy jejích PDF příloh (stávající náhled příloh). Klik na
  hlavičku otevře formulář zprávy (§6) — tam Odeslat znovu,
  Archivovat, Smazat.
- **Opravená adresa:** uživatel upraví e-mail na osobě / kontaktu
  a v detailu záznamu dá znovu **Odeslat** → nová zpráva s novou
  adresou; předchozí zpráva zůstává v historii (D44).
- **Číselná řada**: sekce „Odesílání e-mailem“ (§4).
- **Kontakt osoby**: účely (§1).
- i18n frontendu `cs` / `en`.

## 11. Bezpečnost testování

Dev servery nemají zachytávání pošty a relay posílá ven (technickou
pojistku řeší #95). Do té doby:

- Integrační testy jen `enqueue` / `prepare`, **nikdy** `attemptSend` ani
  zpracování fronty; transport v testech fake.
- Ruční ověření odeslání **jen na volném zdroji dat** s vlastními
  adresami příjemců. Na zdrojích „reálná kopie“ (`CLAUDE.local.md`)
  nic neodesílat — ani `print-send` bez `--dry-run`.
- `print-send` bez `--to` na zdroji, který není volný, odmítne
  (pojistka: CLI zjistí režim zdroje, je-li dostupný; jinak vyžaduje
  `--to` vždy).

## Testy

- **Unit:** účely (validace sloupce, cfgItem), `RecipientResolver`
  (účel, platnost, stav, pořadí, duplicity, fallback na osobu, kontakt
  bez účelu ignorován, nevalidní adresa, adresa vždy živá — ne ze
  snapshotu), `SenderResolver` (pořadí,
  povolené adresy, deaktivovaný odesílatel na řadě), `PrintDefinition`
  (`sendPurpose` jen u external, `recipientPerson` povinný), render
  e-mailových šablon (všechny jazyky, předmět bez konců řádků, žádné
  HTML escapování v textu), `OutboundMessage` / `MailComposer` (víc
  „Komu“, kopie, jméno), `RecordSendService` s fake závislostmi
  (`prepare` bez vedlejších účinků; `send` vytvoří zprávu + přílohy
  zprávy + outbox v transakci a na záznamu nic; chyba renderu nic
  nezanechá; dvě odeslání = dvě zprávy), `SentMessageTransport`
  (Odeslat znovu = nový řádek outboxu se stejnými přílohami, žádná nová
  zpráva; `ALREADY_QUEUED`; posluchač outboxu propíše `sent` / `failed`,
  `send_count`), pevný obsah zprávy (zápis obsahu i příloh odmítnut,
  změna stavu povolena), přechody stavů.
- **Integrační (volný DS):** faktura s kontaktem s účelem → oba
  příjemci z kontaktů; bez kontaktu → e-mail osoby; bez e-mailu →
  `NO_RECIPIENT`; přílohy se `send_with_record` — spojené do PDF při
  volbě osoby, jinak zvlášť (kopie v přílohách zprávy, záznam beze
  změny); zpráva + outbox ve stavu `queued` (bez odeslání); slovenský
  partner → slovenský předmět a PDF; změna e-mailu osoby → další
  odeslání jde na novou adresu, stará zpráva beze změny.
- **Controller:** draft, send, resend, chybové kódy, read-only session
  → 403; háček detailu (sekce Odeslaná pošta jen se zprávami ve stavu 40).

## Task breakdown

### Commit 1 — Odchozí pošta: víc příjemců, kopie, jméno

§3 + testy outboxu + `docs/mail/outbound.md`.

**Hotovo když:** stávající odesílání beze změny; `mail-send-test`
umí víc `--to` a `--cc` (rozšířit, je-li to malé).

### Commit 2 — Účely a příjemci

§1 (cfgItem, sloupec, formulář, výměnný formát), §2 resolver, testy.

**Hotovo když:** na kontaktu jde vybrat účely; resolver vrací adresy
s důvody podle pravidel.

### Commit 3 — Odesílatel

§4 (resolver, povolené adresy, číselná řada — sloupce a formulář), testy.

### Commit 4 — Odeslaná pošta

§6 (tabulka, stavy, pevný obsah, formulář, agenda), §8 (transport,
posluchač outboxu, Odeslat znovu), testy.

**Hotovo když:** zprávu vytvořenou testem / CLI jde v agendě otevřít,
odeslat znovu, archivovat a smazat; výsledek transportu se propíše
do zprávy.

### Commit 5 — Služba odeslání záznamu

§5 (deklarace, e-mailové šablony a katalogy), §7, CLI `print-send`,
testy.

**Hotovo když:** `print-send … --dry-run` vrátí úplný návrh;
`print-send … --to=<vlastní adresa>` na volném DS vytvoří zprávu
v Odeslané poště s PDF v přílohách a řádek ve frontě.

### Commit 6 — REST a UI

§9 REST, §10 UI, testy controlleru.

**Hotovo když:** z detailu faktury jde otevřít dialog, poslat na vlastní
adresu (volný DS); v detailu faktury je sekce Odeslaná pošta s náhledem
PDF, klik otevře zprávu a Odeslat znovu funguje.

### Commit 7 — Dokumentace a nápověda

- `docs/prints.md`: kapitola Odesílání (účely, příjemci, odesílatel,
  služba, šablony e-mailu, REST, CLI, práva, rozšíření účelů modulem,
  jak udělat tisk odesílatelný).
- `docs/mail/outbound.md` (nebo nový `docs/mail/sent.md`): Odeslaná
  pošta — model, pevný obsah, stavy, transport a posluchač outboxu,
  Odeslat znovu; odkaz z `docs/README.md`.
- `docs/cli.md`.
- Nápověda: odeslání faktury (včetně „partner hlásí novou adresu“),
  Odeslaná pošta, kontakty a účely (osoby), číselná řada (Odesílat z) —
  podle pravidel nápovědy.
- Roadmapa M4 — řádek „Odeslání dokladu odběrateli e-mailem“.
- Hlavička tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu

Zamčeno v #90: D34–D45 (D40–D45 nahrazují D10 a D36). Upřesnění z PRD:

- Odeslaná pošta má vlastní sadu stavů (40 Odeslaná, 70 V archivu,
  90 Smazaná), ne `docStatesArchive` — pevný obsah nesnese Koncept ani
  V opravě.
- Výsledek transportu si zpráva propisuje sama přes posluchače outboxu
  podle `sourceRef` — outbox zůstává čistě transportní frontou.
- Odeslat znovu jen u zprávy ve stavu 40, ne když už je ve frontě.
- Deklarace tisku dostane `recipientPerson` (sloupec s osobou příjemce),
  aby služba fungovala nad libovolnou tabulkou.
- Předmět a tělo jako Twig šablony tisku (`email-*.txt.twig`) nad
  `PrintData`, texty v katalozích — žádný nový mechanismus vedle D6/D8.
- Právo odesílat = `guardTable` + zápis přes `ReadOnlyPolicy`, dokud
  model oprávnění nezná víc než administrátora.
- Ruční odeslání posílá hned, selhání transportu nechá zprávu ve frontě.

## Implementace

Hotovo v sedmi commitech podle task breakdownu. Odchylky a upřesnění proti
zadání:

- **Účely jsou klíč `sendPurposes` v `module.jsonc`**, ne soubor
  `config/sendPurposes.jsonc`: cfgItemy se mezi moduly neslučují (pozdější
  modul by dřívější přepsal), proto je skládá `ConfigCompiler` do cfgItemu
  `base.persons.sendPurposes` — vzor `journalDimensions`.
- **Agenda je root-level položka** hned za Došlou poštou (`_top`,
  `navOrder: 35`) — sekce „Pošta“ v navigaci není.
- **Odeslat znovu ve formuláři** je form komponenta
  `sentMessageTransport` (formuláře nemají vlastní akce); Archivovat /
  Smazat / Obnovit jsou běžné přechody stavu.
- **Okamžitý pokus o odeslání běží až po commitu** transakce se zprávou,
  přílohami a řádkem fronty — rollback nesmí přijít po odeslání.
- **`print-send` vyžaduje `--to` vždy** (bez `--dry-run`): zdroj dat žádný
  „režim“ nemá, takže platí záložní větev §11.
- **`SenderResolver::resolve()` bere název tabulky**, ne `PrintDefinition`
  — odesílatele má i zpráva, která z tisku nevzniká.
- **Sloupec se jmenuje `send_trigger`** (`trigger` je v MariaDB vyhrazené
  slovo) a přibyl **`target_label`** — popisek záznamu v době odeslání pro
  agendu.
- **Pevný obsah** hlídají read-only stavy, nový příznak tabulky
  `systemManaged` (generické CRUD jinak zakládá a maže mimo dokumentový
  lifecycle), `SentMessageDocument` a `SentMessageAttachmentGuard`; strážce
  příloh k tomu dostal operaci `upload`.
- **`ReadOnlyPolicy` má pro `prints` výčet akcí** místo `ANY` — jinak by
  `POST …/send` prošel i na read-only zdroji.
- **Posluchač fronty slyší i `requeued`** (`mail-outbox-retry`), aby se
  selhaná zpráva vrátila na „ve frontě“.
- `TableMerger` přenáší `adminOnly` (a nový `systemManaged`) i přes
  rozšíření tabulky — dřív se `adminOnly` rozšířením ztrácel.

Ověřeno na volném zdroji dat bez relay: návrh, odeslání z dialogu i z CLI,
zpráva s přílohami (zvlášť i spojené do PDF), řádek fronty, propsání
trvalého selhání, Odeslat znovu, archivace, zámky obsahu a příloh. Skutečné
doručení přes SMTP ověřené není.
