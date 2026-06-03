# Task: Import došlé pošty (Fáze 07b)

## Kontext

Import zpráv došlé pošty ze starého Shipardu (`wkf_core_issues`, `issueType=1` =
Došlá pošta) do nového (`core_mail_incoming_messages`). Včetně:

- mapování **sekcí** (`wkf_base_sections`) na **schránky** (`core_mail_mailboxes`),
- přenosu **PDF příloh** (přes obecný klient z Fáze 07a),
- **provázání na doklady** (vazba `e10docs-inbox` → `message.target`).

Starý systém míchal v `issues` poštu, úkoly, diskuze atd. (`issueType`). Importujeme
**jen `issueType=1` (inbox)**; ostatní typy ignorujeme. Druh/typ/priority/termíny a
další workflow pole nepřenášíme.

### Prerekvizity

1. **`nov_shipard:tasks/mail-phase4-import-endpoint.md`** — endpoint
   `POST /_mail/import` (zprávy nelze založit přes generický CRUD: `message_id` se
   generuje v `beforeSave`, který CRUD nevolá).
2. **Fáze 07a** (`07a-attachments-client.md`) — `AttachmentImporter` pro upload příloh.
3. **Fáze 03 (persons)** — `LocalIdMap` `ENTITY_PERSON` pro `sender_person`.
4. **Fáze 05 (docs)** — `LocalIdMap` `ENTITY_DOC` pro vazbu na doklad. **Doklady se
   importují PŘED poštou.** Pošta je terminální fáze řetězce
   `codebooks → persons → items → docs → mail`.

### Klíčová rozhodnutí (z PRD diskuze)

- **Vazba doklad↔zpráva** je autoritativně v `e10_base_doclinks`:
  `linkId='e10docs-inbox'`, `srcTableId='e10doc.core.heads'` (doklad),
  `dstTableId='wkf.core.issues'` (zpráva). Tj. **doklad → zpráva** (1 doklad : N
  zpráv). Sloupce `issues.tableNdx`/`recNdx` **nepoužíváme**.
- **Best-effort vazby.** Zpráva se importuje **vždy**, i když se její doklad
  nedohledá (mimo importované okno / mimo scope). Nedohledaná → `target` NULL +
  počítadlo `unlinked`.
- **Pořadí + okno.** Doklady první. Pošta má vlastní `--from/--to` na `dateIncoming`
  (default vše). Pro test importuj doklady i poštu přes stejné okno (vazby pak
  většinou sednou; rozsypou se jen okrajové případy kolem hranice roku).
- **`--require-linked-doc`** (opt-in): importovat **jen** zprávy s dohledatelným
  dokladem (přeskočit obecnou korespondenci). Default vypnuto.
- **Schránky:** `mailbox_id` = `section.shipardEmailId` (pokud je); jinak fallback
  `sec-{sectionNdx}`. `email_address` = `{mailbox_id}@imported.invalid`. Plochá
  struktura (strom sekcí zahodit), `is_default=false`.
- **`sender_email` fallback:** `systemInfo.email.from[0].address` →
  `systemInfo.webForm.from.address` → e-mail autora (osoby) → placeholder
  `unknown@imported.invalid`.
- **`source_type`:** mapování starého `issues.source` → nové: 0(Ručně)→1,
  1(E-mail)→2, 2(API)→3, 3(Test)→1.
- **`primary_type`:** navázaný doklad je faktura přijatá (`invni`) → `invoiceReceived`;
  jinak `other`.
- **`docState`:** navázaná zpráva → **40** (Zpracovaná); nenavázaná → **10** (Nová).

```bash
shpd-app cli-action --action=imports.newShipard/import mail
shpd-app cli-action --action=imports.newShipard/import mail --from=2025-01-01 --to=2025-12-31 -v
shpd-app cli-action --action=imports.newShipard/import mail --require-linked-doc --limit=20 -v
shpd-app cli-action --action=imports.newShipard/import mail --no-attachments
```

## Před implementací přečti

Ze starého Shipardu:

- **`modules/wkf/core/tables/issues.json`** — `issueType` (1=inbox), `subject`,
  `body`/`text`, `section`, `author` (FK `e10.persons.persons`), `dateIncoming`,
  `dateCreate`, `source`, `systemInfo` (memo, JSON s e-mail hlavičkami), `docState`.
- **`modules/wkf/base/config/issues.types.json`** — potvrzení `1 = inbox`.
- **`modules/wkf/base/tables/sections.json`** — `fullName`, `shortName`,
  `shipardEmailId`.
- **`modules/e10/base/tables/doclinks.json`** — `linkId`, `srcTableId/srcRecId`,
  `dstTableId/dstRecId`. Vazba `e10docs-inbox`.
- **`modules/wkf/core/documentCards/Issue.php`** — jak se čte `systemInfo['email']`
  (`from`, `headers`). Vzor pro parsování odesílatele.
- **Zdroj e-mailu autora** — jak jsou uložené e-maily osob. Vzor:
  `modules/wkf/core/libs/IssueEmailForwardEngine.php` (`… 'email', 'contacts' …`).
  Ověř tabulku/sloupec (kontakty osob) a použij pro fallback `sender_email`.

Z hotové infrastruktury:

- **`modules/imports/newShipard/libs/ImportRunner.php`** — base (`http`, `db`, `app`,
  `idMap`, log helpery, `isDryRun`, `isVerbose`).
- **`modules/imports/newShipard/libs/CrudClient.php`** — `create()` (založení
  schránky), `findOneBy()`.
- **`modules/imports/newShipard/libs/runners/DocsRunner.php`** — vzor runneru se
  source query, `--from/--to` parsováním (`dateArg`), chunkováním (zde netřeba).
- **Fáze 07a** — `AttachmentImporter`, `AttachmentUploadClient`,
  `HttpClient::uploadFile`.

Z nového Shipardu:

- **`tasks/mail-phase4-import-endpoint.md`** + výsledný endpoint `POST /_mail/import`
  — kontrakt (pole, odpověď `{ndx, message_id}`).
- **`modules/core/mail/tables/core_mail_mailboxes.jsonc`** — pole schránky
  (`mailbox_id`, `name`, `email_address`, `default_primary_type`, `is_default`).
- **`modules/core/mail/config/primaryTypes.jsonc`** — klíče (`invoiceReceived`,
  `other`).
- **`modules/core/mail/tables/core_mail_incoming_messages.jsonc`** — `tableId = 303`
  (pro upload příloh), `target_table_id` = `docs_core_heads`.

## Co implementovat

### 1. `LocalIdMap` — nové entity

```php
public const ENTITY_MAILBOX = 'mailbox';
public const ENTITY_MESSAGE = 'message';
```

### 2. `libs/runners/MailRunner.php extends ImportRunner`

Dvoufázový běh: **(A)** sekce → schránky, **(B)** issues → zprávy.

#### 2.1 `run()`

```php
public function run(): bool
{
    $this->info("Importing incoming mail (issueType=1)...");

    // (A) zajisti schránky pro sekce, do kterých padá importovaná pošta
    if (!$this->ensureMailboxes())
        return false;

    // (B) zprávy
    $rows = $this->fetchIssues();           // issueType=1 + --from/--to na dateIncoming
    $limit = (int) ($this->app()->arg('limit') ?? 0);
    if ($limit > 0)
        $rows = array_slice($rows, 0, $limit);

    $this->info("Found " . count($rows) . " inbox messages.");

    $stats = ['created'=>0, 'skipped'=>0, 'failed'=>0, 'linked'=>0, 'unlinked'=>0,
              'att_uploaded'=>0, 'att_duplicate'=>0];

    foreach ($rows as $issue) {
        if (!$this->processIssue($issue, $stats) && !$this->isContinueOnError())
            return false;
    }

    $this->printDone($stats);
    return $stats['failed'] === 0;
}
```

#### 2.2 Fáze A — schránky (`ensureMailboxes`)

Pro každou **sekci referencovanou importovanými inbox zprávami** zajisti schránku
(idempotentně přes `ENTITY_MAILBOX`).

```php
private function ensureMailboxes(): bool
{
    // distinct sekce z inbox issues v rozsahu (stejný WHERE jako fetchIssues bez ORDER)
    $sectionNdxs = $this->db()->query(
        'SELECT DISTINCT i.[section] FROM [wkf_core_issues] i'
        . ' WHERE i.[issueType] = %i', 1,
        ' AND i.[section] > %i', 0,
        /* + --from/--to na dateIncoming, viz fetchIssues */
    )->fetchPairs();

    $crud = new CrudClient($this->http());
    foreach ($sectionNdxs as $secNdx) {
        $secNdx = (int) $secNdx;
        if ($this->idMap()->lookup(LocalIdMap::ENTITY_MAILBOX, $secNdx) !== null)
            continue;   // už existuje

        $sec = $this->db()->query('SELECT * FROM [wkf_base_sections] WHERE [ndx] = %i', $secNdx)->fetch();
        if ($sec === null) continue;
        $sec = (array) $sec;

        $mailboxCode = $this->mailboxCode($sec);                 // shipardEmailId | sec-{ndx}
        $payload = [
            'mailbox_id'    => $mailboxCode,
            'name'          => $this->emptyToNull($sec['fullName'] ?? null) ?? $mailboxCode,
            'email_address' => $mailboxCode . '@imported.invalid',
            'is_default'    => false,
            // docState: nastav na aktivní stav (viz Open Issue 1)
        ];
        if ($this->isDryRun()) { $this->debug("DRY-RUN: would create mailbox {$mailboxCode}"); continue; }

        try {
            $newId = $crud->create('core_mail_mailboxes', $payload);
            $this->idMap()->record(LocalIdMap::ENTITY_MAILBOX, $secNdx, $newId);
            $this->ok("[mailbox] section {$secNdx} → {$newId} ({$mailboxCode})");
        } catch (HttpException $e) {
            $this->err("Failed mailbox for section {$secNdx}: {$e->getMessage()}");
            if (!$this->isContinueOnError()) return false;
        }
    }
    return true;
}

/** mailbox_id kód deterministicky ze sekce: shipardEmailId, fallback sec-{ndx}. */
private function mailboxCode(array $section): string
{
    $eid = trim((string) ($section['shipardEmailId'] ?? ''));
    return $eid !== '' ? $eid : 'sec-' . (int) $section['ndx'];
}
```

> `core_mail_mailboxes` jde založit generickým CRUD — nemá generovaná pole
> (`mailbox_id`/`email_address` dodáváme, unique). `MailboxDocument::validate`
> (max 1 default) CRUD obchází, ale `is_default=false` žádný konflikt netvoří.

#### 2.3 Fáze B — zpráva (`processIssue`)

```php
private function processIssue(array $issue, array &$stats): bool
{
    $oldNdx = (int) $issue['ndx'];

    if ($this->idMap()->lookup(LocalIdMap::ENTITY_MESSAGE, $oldNdx) !== null) {
        $stats['skipped']++; return true;   // idempotence
    }

    // vazba na doklad
    [$targetTableId, $targetRow, $linkedDocOldNdx] = $this->resolveDocLink($oldNdx);
    if ($this->app()->arg('require-linked-doc') && $targetRow === null) {
        $stats['skipped']++; $this->debug("[mail] {$oldNdx} skipped (no linked doc)"); return true;
    }
    $targetRow === null ? $stats['unlinked']++ : $stats['linked']++;

    // odesílatel
    [$senderEmail, $senderName] = $this->resolveSender($issue);
    $senderPerson = null;
    $authorNdx = (int) ($issue['author'] ?? 0);
    if ($authorNdx > 0)
        $senderPerson = $this->idMap()->lookup(LocalIdMap::ENTITY_PERSON, $authorNdx);

    $sectionNdx = (int) ($issue['section'] ?? 0);
    $sec = $this->db()->query('SELECT * FROM [wkf_base_sections] WHERE [ndx] = %i', $sectionNdx)->fetch();
    $mailboxCode = $sec !== null ? $this->mailboxCode((array) $sec) : '';

    $payload = [
        'mailbox'        => $mailboxCode,                       // endpoint resolvuje kód → id
        'subject'        => $this->emptyToNull($issue['subject'] ?? null) ?? '(bez předmětu)',
        'sender_email'   => $senderEmail,
        'sender_name'    => $senderName,
        'sender_person'  => $senderPerson,
        'received_at'    => $this->dateTimeToIso($issue['dateIncoming'] ?? null)
                              ?? $this->dateTimeToIso($issue['dateCreate'] ?? null),
        'body_plain'     => $this->emptyToNull($issue['body'] ?? null)
                              ?? $this->emptyToNull($issue['text'] ?? null),
        'source_type'    => $this->mapSourceType((int) ($issue['source'] ?? 0)),
        'primary_type'   => $this->primaryTypeFor($linkedDocOldNdx),
        'target_table_id'=> $targetTableId,
        'target_row'     => $targetRow,
        'docState'       => $targetRow === null ? 10 : 40,
    ];

    if ($this->isDryRun()) {
        $stats['created']++; $this->debug("DRY-RUN: would import message (old ndx={$oldNdx})");
        return true;
    }

    try {
        $resp = $this->http()->post('/_mail/import', $payload);
        $newId = (int) ($resp['data']['ndx'] ?? 0);
        if ($newId <= 0) { $stats['failed']++; $this->err("[mail] {$oldNdx} no ndx in response"); return $this->isContinueOnError(); }

        $this->idMap()->record(LocalIdMap::ENTITY_MESSAGE, $oldNdx, $newId);
        $stats['created']++;
        $this->ok("[mail] {$oldNdx} → {$newId} ({$resp['data']['message_id']})");

        // přílohy (Fáze 07a) — table_id 303
        if (!$this->app()->arg('no-attachments'))
            $this->importAttachments($oldNdx, $newId, $stats);

        return true;
    } catch (HttpException $e) {
        $stats['failed']++;
        $this->err("[mail] {$oldNdx} FAILED: {$e->getMessage()}");
        return $this->isContinueOnError();
    }
}
```

#### 2.4 Vazba na doklad (`resolveDocLink`)

```php
/**
 * @return array{0:?string, 1:?int, 2:?int}  [target_table_id, target_row, linkedDocOldNdx]
 */
private function resolveDocLink(int $issueNdx): array
{
    $links = $this->db()->query(
        'SELECT [srcRecId] FROM [e10_base_doclinks]'
        . ' WHERE [linkId] = %s', 'e10docs-inbox',
        ' AND [dstTableId] = %s', 'wkf.core.issues',
        ' AND [dstRecId] = %i', $issueNdx,
        ' AND [srcTableId] = %s', 'e10doc.core.heads',
        ' ORDER BY [ndx]',
    )->fetchPairs();

    if ($links === []) return [null, null, null];
    if (count($links) > 1)
        $this->warn("[mail] issue {$issueNdx}: " . count($links) . " linked docs, using first");

    $oldDocNdx = (int) $links[0];
    $newDocId = $this->idMap()->lookup(LocalIdMap::ENTITY_DOC, $oldDocNdx);
    if ($newDocId === null) {
        $this->debug("[mail] issue {$issueNdx}: linked doc {$oldDocNdx} not imported (out of scope)");
        return [null, null, $oldDocNdx];   // známe starý doklad, ale není v cíli
    }
    return ['docs_core_heads', $newDocId, $oldDocNdx];
}

/** invni → invoiceReceived; jinak other. */
private function primaryTypeFor(?int $linkedDocOldNdx): string
{
    if ($linkedDocOldNdx === null) return 'other';
    $r = $this->db()->query('SELECT [docType] FROM [e10doc_core_heads] WHERE [ndx] = %i', $linkedDocOldNdx)->fetch();
    $docType = $r !== null ? (string) ((array) $r)['docType'] : '';
    return $docType === 'invni' ? 'invoiceReceived' : 'other';
}
```

#### 2.5 Odesílatel (`resolveSender`)

```php
/** @return array{0:string, 1:?string}  [sender_email, sender_name] */
private function resolveSender(array $issue): array
{
    // 1) systemInfo JSON: email.from[0] | webForm.from
    $si = $this->emptyToNull($issue['systemInfo'] ?? null);
    if ($si !== null) {
        $j = json_decode($si, true);
        if (is_array($j)) {
            $from = $j['email']['from'][0] ?? $j['webForm']['from'] ?? null;
            if (is_array($from)) {
                $addr = $this->emptyToNull($from['address'] ?? null);
                $name = $this->emptyToNull($from['name'] ?? null);
                if ($addr !== null && filter_var($addr, FILTER_VALIDATE_EMAIL))
                    return [strtolower($addr), $name];
            }
        }
    }

    // 2) e-mail autora (osoby) — ověř zdrojovou tabulku kontaktů osob
    $authorNdx = (int) ($issue['author'] ?? 0);
    if ($authorNdx > 0) {
        $email = $this->loadPersonEmail($authorNdx);   // viz IssueEmailForwardEngine vzor
        if ($email !== null) {
            $name = $this->loadPersonName($authorNdx);  // fullName
            return [strtolower($email), $name];
        }
    }

    // 3) placeholder
    return ['unknown@imported.invalid', null];
}
```

`loadPersonEmail`/`loadPersonName` — dohledej v old DB (osoby + jejich kontakty;
vzor dotazu v `IssueEmailForwardEngine`). Endpoint vyžaduje validní e-mail, proto
placeholder musí být validní (`unknown@imported.invalid` je).

#### 2.6 Přílohy (`importAttachments`)

```php
private function importAttachments(int $issueNdx, int $newMsgId, array &$stats): void
{
    $importer = new \imports\newShipard\libs\AttachmentImporter(
        new \imports\newShipard\libs\AttachmentReader($this->db(), __APP_DIR__),
        new \imports\newShipard\libs\AttachmentUploadClient($this->http()),
    );
    $r = $importer->importFor('wkf.core.issues', $issueNdx, 303, $newMsgId);  // 303 = core_mail_incoming_messages
    $stats['att_uploaded']  += $r['uploaded'];
    $stats['att_duplicate'] += $r['duplicate'];
    if ($r['uploaded'] > 0 || $r['duplicate'] > 0)
        $this->debug("[mail] issue {$issueNdx}: attachments uploaded={$r['uploaded']} dup={$r['duplicate']}");
}
```

#### 2.7 `fetchIssues` + helpery

- `fetchIssues()` — `SELECT i.* FROM [wkf_core_issues] i WHERE i.[issueType]=1 AND
  i.[docState] != <trash> ` + volitelně `AND i.[dateIncoming] >= %d / <= %d`
  (`--from`/`--to`, parser jako `DocsRunner::dateArg`) `ORDER BY i.[ndx]`.
  Ověř hodnotu trash docState ve `wkf.issues.docStates.default` (vynech smazané).
- `mapSourceType(int $old): int` — `[0=>1, 1=>2, 2=>3, 3=>1]`, default 1.
- `dateTimeToIso(mixed): ?string` — `DateTime`/string → ISO8601; `0000-…`/prázdné → null.
- `emptyToNull` — jako v ostatních runnerech.
- `printDone` — vypíše created/skipped/failed + **linked/unlinked** + attachments.

### 3. Dispatch + usage (`ImportApp`)

```php
case 'mail': return (new runners\MailRunner($this->context()))->run();
```

`printUsage()` rozšířit o `mail` a opce `--require-linked-doc`, `--no-attachments`
(+ `--from`/`--to` platí i pro `mail`).

### 4. Orchestrátor `all` (volitelné, doporučeno)

Do `AllRunner` přidej `mail` **na konec** sekvence (po `docs`). `--from`/`--to`
nechť omezí i poštu (stejně jako doklady). Pokud orchestrátor refaktor není triviální,
nech `mail` zatím jen jako samostatný subcommand a poznamenej do README.

### 5. README

Tabulka subkomand: `mail | ✅ Fáze 07`. Dokumentuj pořadí (docs před mail), okno,
`--require-linked-doc`, `--no-attachments`, best-effort vazby a syntetické schránky.

## Hotovo když

1. `LocalIdMap::ENTITY_MAILBOX` + `ENTITY_MESSAGE` existují.
2. **Schránky:** sekce s inbox zprávami mají schránku (`mailbox_id` = shipardEmailId |
   `sec-{ndx}`, `email = …@imported.invalid`), idempotentně přes `ENTITY_MAILBOX`.
3. **Zprávy:** `issueType=1` se importují přes `POST /_mail/import` se správným
   `subject`, `body_plain`, `received_at`, `source_type`, `sender_email`/`name`,
   `sender_person`, `mailbox`.
4. **Vazba na doklad** přes `e10_base_doclinks` (`e10docs-inbox`, dst=zpráva) →
   `ENTITY_DOC` → `target_table_id='docs_core_heads'`, `target_row`. Více vazeb →
   první + warning.
5. **Best-effort:** nedohledaný doklad → `target` NULL, zpráva se přesto importuje;
   `--require-linked-doc` takové zprávy přeskočí.
6. **`primary_type`** = `invoiceReceived` pro navázané `invni`, jinak `other`.
   **`docState`** = 40 navázané / 10 nenavázané.
7. **Přílohy** se nahrají k nové zprávě (table_id 303) přes Fázi 07a; `--no-attachments`
   to vypne; druhý běh → `duplicate` (žádné duplicity).
8. **Idempotence:** druhý běh přeskočí zprávy i schránky (`ENTITY_MESSAGE`/
   `ENTITY_MAILBOX`).
9. **Statistika** rozlišuje created/skipped/failed/linked/unlinked + přílohy.
10. `ImportApp` routuje `mail`, `printUsage` aktualizován; README aktualizován.
11. **Smoke test** (DS s reálnou poštou):
    - `docs --from=2025-01-01 --to=2025-12-31` (prereq), pak
      `mail --from=2025-01-01 --to=2025-12-31 --limit=20 -v`.
    - Ověř v UI: zprávy ve schránkách, přílohy u zpráv, navázané zprávy `docState`
      Zpracovaná; po Fázi `docs-source-mail-attachments` v `nov_shipard` se PDF
      objeví i v detailu dokladu.
    - Druhý běh → vše skipped/duplicate.

## Doporučené pořadí implementace

1. `ENTITY_MAILBOX`/`ENTITY_MESSAGE` + dispatch + printUsage skeleton.
2. `fetchIssues` (bez filtru) → vypsat počet inbox zpráv.
3. `ensureMailboxes` → schránky vznikají (idempotentně).
4. `processIssue` minimal (bez vazby, bez příloh, sender placeholder) → zprávy
   vznikají přes `/_mail/import`, `message_id` se generuje.
5. `resolveSender` (systemInfo → author email → placeholder).
6. `resolveDocLink` + `primaryTypeFor` + docState 40/10.
7. `--from`/`--to` + `--require-linked-doc`.
8. Přílohy přes Fázi 07a (`--no-attachments` vypínač).
9. End-to-end na okno dat, idempotence, README, (orchestrátor `all`).

## Otevřené body / rozhodnutí

1. **docState schránky.** `core_mail_mailboxes` používá `core.system.docStatesArchive`.
   Vytvoř schránku v aktivním stavu (ne Koncept), ať je normálně viditelná —
   zkontroluj, jaký stav má default schránka z `MailRouterProvisioner`, a sjednoť.
2. **Zdroj e-mailu autora.** Ověř tabulku/sloupec kontaktů osob (vzor v
   `IssueEmailForwardEngine`). Když fallback nenajde e-mail, placeholder
   `unknown@imported.invalid` (validní, projde validací endpointu).
3. **Nenavázané zprávy = docState 10 (Nová).** Pokud bude vadit, že se historická
   nenavázaná pošta tváří jako „k vyřízení", zvaž místo toho 80 (Archiv). Drobnost,
   snadná změna — pro teď 10.
4. **Relink po doimportu dokladů.** Když se doklady doimportují *po* poště, dřívější
   zprávy se zpětně nepřelinkují (skip přes `ENTITY_MESSAGE`). Pro ostrý import dělej
   doklady (celý rozsah) před poštou. Případný `relink` subcommand je follow-up.
5. **`systemInfo` tvar.** Předpoklad `email.from[]` / `webForm.from` dle
   `Issue.php`/`ContactForm.php`. Pokud reálná data mají jiný tvar, uprav
   `resolveSender` a sleduj poměr placeholderů v logu.
6. **Body HTML.** Staré `issues` nemají vyhrazené HTML tělo (jen `body`/`text`) →
   `body_html` neplníme. Pokud `body` obsahuje HTML, importuje se as-is do
   `body_plain` (akceptováno pro MVP).
