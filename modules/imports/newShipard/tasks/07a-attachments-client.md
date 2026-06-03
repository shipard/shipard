# Task: Import příloh — obecný klient (Fáze 07a)

## Kontext

Importer zatím neumí přenášet **přílohy** (`e10_attachments_files`) do nového
Shipardu. Tato fáze přidává **obecný aparát** pro upload příloh přes REST — ne jen
pro poštu. Přílohy nejsou vázané jen na zprávy; postupně je budeme importovat u
mnoha tabulek (osoby, doklady, položky…). Proto je klient **entity-agnostický**:
umí zkopírovat všechny přílohy starého záznamu `(oldTableId, oldRecId)` na nový
záznam `(newTableIdNumeric, newRecordId)`.

Prvním (a jediným v tomto kole) konzumentem je `MailRunner` (Fáze 07b), který přenese
PDF příloh zpráv došlé pošty. Ostatní tabulky se napojí později — bez změny tohoto
klienta.

**Stav nového Shipardu:** endpoint `POST /api/v1/_attachments/upload`
(`multipart/form-data`: `table_id`, `record_id`, `file`) **už existuje a je obecný**
(viz `nov_shipard:src/Api/Controller/AttachmentController.php` a
`nov_shipard:docs/attachments.md`). Dedup přes SHA-256 v rámci `(table_id, record_id)`
— duplicitní obsah vrátí `warning: DUPLICATE_CHECKSUM` (upload „uspěje", ale neukládá
podruhé). Žádný symlink koncept (na rozdíl od starého `symlinkTo`).

**Fyzické úložiště starého Shipardu:** soubor je na
`__APP_DIR__ . '/att/' . $row['path'] . $row['filename']` (ověřeno napříč kódem —
`src/Report/MailMessage.php`, `src/UI/Core/ContentRenderer.php` aj.). `path` už
obsahuje koncové lomítko. Importer běží z rootu DS, takže `__APP_DIR__` je dostupné.

## Před implementací přečti

- **`modules/imports/newShipard/libs/HttpClient.php`** — sem přidat multipart upload.
  Pozor na throttling (`applyThrottle`) a retry (`shouldRetry`) — upload je má
  respektovat stejně jako JSON requesty.
- **`modules/imports/newShipard/libs/HttpException.php`** — výjimka + `withRequest`.
- **`modules/imports/newShipard/libs/ImportRunner.php`** — `http()`, `db()`, `app()`,
  log helpery (`info/ok/warn/err/debug`).
- **`modules/e10/base/tables/attachments.json`** — zdrojová tabulka
  `e10_attachments_files`: `tableid` (string), `recid` (int), `path`, `filename`,
  `name` (zobrazovaný), `filetype`, `fileSize`, `deleted` (soft-delete),
  `symlinkTo` (logická kopie sdílející fyzický soubor přes stejné `path`+`filename`),
  index `s1 (tableid, recid)`.
- **`nov_shipard:src/Api/Controller/AttachmentController.php`** — `upload()`: čte
  `$_POST['table_id']`, `$_POST['record_id']`, `$_FILES['file']`; odpověď 201
  `{success, data:{id, …}}`, případně `warning:{code:'DUPLICATE_CHECKSUM', existing_attachment_id}`.
- **`nov_shipard:docs/attachments.md`** — formát odpovědi.

## Co implementovat

### 1. `HttpClient::uploadFile()`

Multipart POST jednoho souboru, integrovaný s throttle + retry.

```php
/**
 * Multipart upload jednoho souboru. Integrováno s throttling + retry
 * (stejně jako request()). $fields jsou textová pole (table_id, record_id…),
 * $filePath se přiloží jako pole `file` přes CURLFile.
 *
 * @param array<string,scalar> $fields
 * @return array  Dekódovaná JSON odpověď (data + případný warning).
 * @throws HttpException při 4xx/5xx (kromě retryovatelných)
 */
public function uploadFile(string $path, array $fields, string $filePath, string $fileFieldName = 'file', ?string $clientFileName = null): array
```

Implementace: stejná smyčka jako `request()` (`applyThrottle()` → pokus →
`shouldRetry()` → `sleep()`), ale curl tělo je multipart:

- Hlavičky: `Authorization: Bearer …`, `Accept: application/json`,
  `User-Agent` — **bez** `Content-Type` (curl ho s CURLFile nastaví sám i s
  boundary).
- `CURLOPT_POSTFIELDS => $fields + [$fileFieldName => new \CURLFile($filePath, mimeOrNull, $clientFileName ?? basename($filePath))]`.
- Timeout zvaž vyšší (velké PDF) — např. `max($this->timeout, 60)`, nebo nový
  konfig klíč; pro MVP stačí stávající `timeout`.
- Parsování odpovědi/erroru a mapování na `HttpException` **sdílej** se stávajícím
  kódem — vyčleň společnou část `executeRequest()` do helperu, který bere připravené
  curl opts, ať se logika status/JSON/error neduplikuje. (Refactor `executeRequest`
  tak, aby přijal pole `curl options` a hlavičky; `request()` i `uploadFile()` ho
  volají.)

### 2. `libs/AttachmentUploadClient.php`

Tenká fasáda nad `HttpClient::uploadFile` pro `/_attachments/upload`.

```php
final class AttachmentUploadClient
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * Upload souboru k záznamu. Vrací:
     *   ['status' => 'uploaded'|'duplicate', 'id' => int]
     * 'duplicate' = server vrátil warning DUPLICATE_CHECKSUM (soubor už u záznamu je).
     *
     * @throws HttpException při tvrdé chybě (4xx/5xx)
     */
    public function upload(int $tableId, int $recordId, string $filePath, string $displayName): array
    {
        $resp = $this->http->uploadFile(
            '/_attachments/upload',
            ['table_id' => $tableId, 'record_id' => $recordId],
            $filePath,
            'file',
            $displayName,
        );
        $id = (int) ($resp['data']['id'] ?? 0);
        $isDup = isset($resp['warning']['code']) && $resp['warning']['code'] === 'DUPLICATE_CHECKSUM';
        return ['status' => $isDup ? 'duplicate' : 'uploaded', 'id' => $id];
    }
}
```

### 3. `libs/AttachmentReader.php` — čtení starých příloh

Čte `e10_attachments_files` pro `(oldTableId, oldRecId)`, resolvuje fyzickou cestu,
přeskakuje smazané a chybějící soubory.

```php
final class AttachmentReader
{
    public function __construct(private readonly \Dibi\Connection $db, private readonly string $dsRoot) {}

    /**
     * Přílohy starého záznamu připravené k uploadu. Pořadí dle defaultImage, order, name.
     * Vynechává deleted=1 a soubory chybějící na disku.
     *
     * @return list<array{displayName:string, filePath:string, fileName:string}>
     */
    public function attachmentsFor(string $oldTableId, int $oldRecId): array
    {
        $rows = $this->db->query(
            'SELECT [name], [path], [filename], [filetype] FROM [e10_attachments_files]'
            . ' WHERE [tableid] = %s AND [recid] = %i AND [deleted] = %i'
            . ' ORDER BY [defaultImage] DESC, [order], [name]',
            $oldTableId, $oldRecId, 0,
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $row = (array) $r;
            $physical = $this->dsRoot . '/att/' . (string) $row['path'] . (string) $row['filename'];
            if (!is_file($physical)) {
                continue;   // missing — handler nahlásí výš
            }
            // Zobrazovaný název: preferuj `name`, fallback `filename`.
            $display = trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : (string) $row['filename'];
            $out[] = ['displayName' => $display, 'filePath' => $physical, 'fileName' => (string) $row['filename']];
        }
        return $out;
    }
}
```

> **`symlinkTo` neřešíme zvlášť.** Symlinkovaná příloha sdílí stejné `path`+`filename`
> jako originál → na disku jeden soubor. Nahrajeme prostě každou řádku; nová strana
> dedupne podle SHA-256 v rámci `(table_id, record_id)`. (Dvě různé staré přílohy
> stejného obsahu u téhož záznamu → jeden upload + jeden „duplicate".)

> **`__APP_DIR__` vs `dsRoot`.** Předej `dsRoot` z volajícího (runner má přístup k
> `__APP_DIR__`). Drž to jako parametr, ať je třída testovatelná.

### 4. `libs/AttachmentImporter.php` — orchestrace

Spojí Reader + UploadClient: „zkopíruj všechny přílohy starého záznamu na nový".

```php
final class AttachmentImporter
{
    public function __construct(
        private readonly AttachmentReader $reader,
        private readonly AttachmentUploadClient $client,
    ) {}

    /**
     * @return array{uploaded:int, duplicate:int, missing:int}
     */
    public function importFor(string $oldTableId, int $oldRecId, int $newTableIdNumeric, int $newRecordId): array
    {
        $stats = ['uploaded' => 0, 'duplicate' => 0, 'missing' => 0];
        $items = $this->reader->attachmentsFor($oldTableId, $oldRecId);
        // missing počítej rozdílem proti DB (volitelné) — nebo Reader vrať i missing.
        foreach ($items as $a) {
            $res = $this->client->upload($newTableIdNumeric, $newRecordId, $a['filePath'], $a['displayName']);
            $stats[$res['status']]++;   // 'uploaded' | 'duplicate'
        }
        return $stats;
    }
}
```

(Pokud chceš `missing` skutečně počítat, ať `AttachmentReader` vrací i počet
přeskočených kvůli chybějícímu souboru — drobnost, vhodí se do logu.)

### 5. Napojení v `ImportContext` (volitelné)

Pokud `ImportContext` drží sdílené služby, můžeš tam `AttachmentImporter`
přidat (lazy). Není nutné — `MailRunner` si ho v 07b sestaví z `http()` + `db()` +
`dsRoot`.

## Hotovo když

1. `HttpClient::uploadFile()` umí multipart upload a **respektuje throttling + retry**
   (sdílí status/JSON/error logiku s `request()` po refactoru).
2. `AttachmentUploadClient::upload()` volá `/_attachments/upload` a rozlišuje
   `uploaded` vs `duplicate` (DUPLICATE_CHECKSUM).
3. `AttachmentReader::attachmentsFor()` čte `e10_attachments_files`, resolvuje cestu
   `<DS>/att/{path}{filename}`, vynechává `deleted=1` a chybějící soubory.
4. `AttachmentImporter::importFor()` zkopíruje všechny přílohy `(oldTable,oldRec)` →
   `(newTableIdNumeric,newRec)` a vrátí statistiku.
5. Klient je **entity-agnostický** (žádná závislost na poště) — připravený pro
   budoucí konzumenty.
6. Smoke (proběhne v 07b): přílohy reálné zprávy se nahrají k nové zprávě, druhý běh
   → `duplicate` (žádné duplicity na disku).

## Doporučené pořadí implementace

1. Refactor `executeRequest` na sdílený helper (curl opts + hlavičky).
2. `uploadFile()` + manuální curl smoke proti běžícímu novému Shipardu (libovolný
   existující záznam, malý soubor).
3. `AttachmentUploadClient`.
4. `AttachmentReader` (ověř fyzickou cestu na reálném DS).
5. `AttachmentImporter` (orchestrace + stats).

## Otevřené body / rozhodnutí

1. **Velikost / timeout.** Velká PDF mohou potřebovat vyšší timeout než 30 s. Pro
   MVP použij `max(timeout, 60)`. Pokud se objeví timeouty, přidej konfig klíč
   `target.uploadTimeout`.
2. **Limit velikosti.** Server má nginx/PHP limity (`client_max_body_size`,
   `upload_max_filesize` — viz `nov_shipard:docs/attachments.md §9`). Přílohy nad
   limit spadnou na 413/400 — loguj jako `failed`/`missing`, neukončuj běh.
3. **`name` vs `filename`.** Zobrazovaný název bereme z `name` (fallback `filename`).
   Nová strana stejně sanitizuje a přidává hash k `file_name` na disku.
4. **`missing` reporting.** Chybějící fyzický soubor (DB řádek bez souboru) jen
   zaloguj a pokračuj — v legacy datech se vyskytují.
