<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Dibi\UniqueConstraintViolationException;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Mail\AddressList;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Import odeslané zprávy ze starého systému (#104 D1–D4): jedno volání
 * založí zprávu i s přílohami, atomicky. Obsah, časy, vazbu, osobu, účel,
 * autora a stav přebírá z payloadu; stav transportu odvozuje sám — zpráva
 * s adresou příjemce odešla (`sent`), bez adresy zdroj výsledek
 * nezaznamenal (`unknown`). Volajícím je runner importu pod API klíčem.
 *
 * Identita ve zdrojovém systému (`import_ref`) je unikátní: opakovaný
 * import vrátí existující zprávu a nic nezaloží — ani soubory.
 */
class SentMessageImportService
{
    private const PURPOSES_ITEM   = 'base.persons.sendPurposes';
    private const DOC_STATES_ITEM = 'core.mail.docStatesSent';

    /** `docStateMain` stavů bez zkompilované konfigurace (`core.mail.docStatesSent`). */
    private const MAIN_STATE_FALLBACK = [
        SentMessageStore::DOC_STATE_SENT     => 1,
        SentMessageStore::DOC_STATE_ARCHIVED => 4,
        SentMessageStore::DOC_STATE_DELETED  => 5,
    ];

    private const PERSONS_TABLE = 'base_persons_persons';
    private const USERS_TABLE   = 'core_system_users';

    private const DEFAULT_SUBJECT = '(bez předmětu)';

    private const MAX_IMPORT_REF   = 100;
    private const MAX_FROM_NAME    = 200;
    private const MAX_ADDRESS_LIST = 2000;
    private const MAX_SUBJECT      = 500;
    private const MAX_TARGET_LABEL = 250;

    /**
     * @param AttachmentService $attachments Bez guardů — přílohy odeslané
     *        zprávy jsou pro ostatní zamčené (`SentMessageAttachmentGuard`).
     * @param array<string, TableDefinition> $tables Tabulky zdroje dat —
     *        existence cílové tabulky vazby.
     * @param ?ConfigRuntime $config Účely odesílání a stavy zprávy; bez
     *        konfigurace se účel neověřuje a `docStateMain` jde z fallbacku.
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly SentMessageStore $store,
        private readonly AttachmentService $attachments,
        private readonly array $tables,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @param array<string, mixed> $payload Kontrakt D3.
     * @param list<array{name: string, tmp_name: string}> $files Přílohy
     *        v pořadí, ve kterém mají být u zprávy; název = název přílohy.
     * @param ?int $apiUserId Uživatel API klíče — autor příloh, když payload
     *        autora nenese.
     * @throws SentMessageImportException Porušený kontrakt — nic nevzniklo.
     * @throws \Throwable Chyba zápisu — transakce vrácená, soubory smazané.
     */
    public function import(array $payload, array $files, ?int $apiUserId): SentMessageImportResult
    {
        $importRef = $this->importRef($payload);

        $existing = $this->store->findByImportRef($importRef);
        if ($existing !== null) {
            return new SentMessageImportResult($existing, false);
        }

        $fields           = $this->fields($payload, $importRef);
        $attachmentAuthor = $fields['created_by'] ?? $apiUserId;

        $created = [];
        try {
            $id = NestedTransaction::run(
                $this->db->getDibiConnection(),
                function () use ($fields, $files, $attachmentAuthor, &$created): int {
                    $id = $this->store->import($fields);
                    foreach ($files as $file) {
                        $created[] = $this->upload($id, $file, $attachmentAuthor);
                    }
                    return $id;
                },
            );
        } catch (UniqueConstraintViolationException $e) {
            // Souběh dvou importů téže zprávy: vyhrál ten druhý — odpověď
            // jako při deduplikaci.
            $this->unlink($created);
            $existing = $this->store->findByImportRef($importRef);
            if ($existing !== null) {
                return new SentMessageImportResult($existing, false);
            }
            throw $e;
        } catch (\Throwable $e) {
            // Transakce se vrátila — soubory příloh na disku ne.
            $this->unlink($created);
            throw $e;
        }

        return new SentMessageImportResult($id, true, count($files));
    }

    /** @param array<string, mixed> $payload */
    private function importRef(array $payload): string
    {
        $ref = trim((string) ($payload['import_ref'] ?? ''));
        if ($ref === '') {
            throw $this->error('import_ref', 'required', 'import_ref is required');
        }
        if (mb_strlen($ref) > self::MAX_IMPORT_REF) {
            throw $this->error('import_ref', 'too_long', 'import_ref must be at most 100 characters');
        }
        return $ref;
    }

    /**
     * Řádek zprávy podle kontraktu D3 + odvozené sloupce (D4).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function fields(array $payload, string $importRef): array
    {
        $created = $this->created($payload['created'] ?? null);

        $from = trim((string) ($payload['email_from'] ?? ''));
        if ($from === '') {
            throw $this->error('email_from', 'required', 'email_from is required');
        }
        if (!AddressList::isValid($from)) {
            throw $this->error('email_from', 'invalid_email', "'{$from}' is not a valid e-mail address");
        }

        $to = $this->addresses($payload['email_to'] ?? null, 'email_to');
        $cc = $this->addresses($payload['email_cc'] ?? null, 'email_cc');

        $subject = trim((string) ($payload['subject'] ?? ''));
        if ($subject === '') {
            $subject = self::DEFAULT_SUBJECT;
        }

        $body = $payload['body_text'] ?? null;
        if ($body !== null && !is_string($body)) {
            throw $this->error('body_text', 'invalid', 'body_text must be a string');
        }

        $recipientPerson = $this->optionalId($payload, 'recipient_person');
        if ($recipientPerson !== null && !$this->rowExists(self::PERSONS_TABLE, $recipientPerson)) {
            throw $this->error('recipient_person', 'not_found', "Person {$recipientPerson} does not exist");
        }

        [$targetTable, $targetRow] = $this->target($payload);

        $purpose = self::nullIfEmpty($payload['purpose'] ?? null);
        if ($purpose !== null) {
            $purposes = $this->config?->cfgItem(self::PURPOSES_ITEM);
            if (is_array($purposes) && !isset($purposes[$purpose])) {
                throw $this->error('purpose', 'unknown_purpose', "Unknown send purpose '{$purpose}'");
            }
        }

        $docState = $payload['doc_state'] ?? SentMessageStore::DOC_STATE_SENT;
        if (!is_numeric($docState) || !isset(self::MAIN_STATE_FALLBACK[(int) $docState])) {
            throw $this->error('doc_state', 'invalid_state', 'doc_state must be one of 40, 70, 90');
        }
        $docState = (int) $docState;

        $createdBy = $this->optionalId($payload, 'created_by');
        if ($createdBy !== null && !$this->rowExists(self::USERS_TABLE, $createdBy)) {
            throw $this->error('created_by', 'not_found', "User {$createdBy} does not exist");
        }

        $sent = $to !== [];

        return [
            'channel'          => SentMessageStore::CHANNEL_EMAIL,
            'subject'          => mb_substr($subject, 0, self::MAX_SUBJECT),
            'body_text'        => self::nullIfEmpty($body),
            'email_from'       => $from,
            'email_from_name'  => self::truncate($payload['email_from_name'] ?? null, self::MAX_FROM_NAME),
            'email_to'         => AddressList::format($to),
            'email_cc'         => $cc === [] ? null : AddressList::format($cc),
            'recipient_person' => $recipientPerson,
            'target_table_id'  => $targetTable,
            'target_row'       => $targetRow,
            'target_label'     => self::truncate($payload['target_label'] ?? null, self::MAX_TARGET_LABEL),
            'purpose'          => $purpose,
            'language'         => null,
            'print_id'         => null,
            // Stav transportu se odvozuje (D4): s adresou zpráva odešla
            // v okamžiku vzniku, bez adresy zdroj výsledek nezaznamenal.
            'transport_state'  => $sent ? SentMessageStore::TRANSPORT_SENT : SentMessageStore::TRANSPORT_UNKNOWN,
            'sent_at'          => $sent ? $created : null,
            'send_count'       => $sent ? 1 : 0,
            'last_error'       => null,
            'last_outbox_id'   => null,
            'safety_action'    => null,
            'safety_target'    => null,
            'send_trigger'     => SentMessageStore::TRIGGER_IMPORT,
            'import_ref'       => $importRef,
            'created'          => $created,
            // Klíč vždy přítomný: autor jen z payloadu, bez něj NULL.
            'created_by'       => $createdBy,
            'modified'         => $created,
            'docState'         => $docState,
            'docStateMain'     => $this->mainState($docState),
        ];
    }

    /**
     * ISO 8601 → datetime databáze. Bez časové zóny platí zóna serveru;
     * se zónou se čas do zóny serveru převede.
     */
    private function created(mixed $raw): string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            throw $this->error('created', 'required', 'created is required');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            throw $this->error('created', 'invalid_datetime', 'created must be an ISO 8601 datetime');
        }
        try {
            $time = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw $this->error('created', 'invalid_datetime', 'created must be an ISO 8601 datetime');
        }
        return $time->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /**
     * Seznam adres: každá platná, celkem do délky sloupce.
     *
     * @return list<string>
     */
    private function addresses(mixed $value, string $field): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value) && !is_string($value)) {
            throw $this->error($field, 'invalid', "{$field} must be a list of e-mail addresses");
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw $this->error($field, 'invalid', "{$field} must be a list of e-mail addresses");
                }
            }
        }

        $addresses = AddressList::parse($value);
        $invalid   = AddressList::invalid($addresses);
        if ($invalid !== null) {
            throw $this->error($field, 'invalid_email', "'{$invalid}' is not a valid e-mail address");
        }
        if (mb_strlen(AddressList::format($addresses)) > self::MAX_ADDRESS_LIST) {
            throw $this->error($field, 'too_long', "{$field} exceeds 2000 characters");
        }
        return $addresses;
    }

    /**
     * Vazba na záznam: obojí, nebo nic; tabulka známá zdroji dat, řádek
     * existuje.
     *
     * @param array<string, mixed> $payload
     * @return array{0: ?string, 1: ?int}
     */
    private function target(array $payload): array
    {
        $table = self::nullIfEmpty($payload['target_table_id'] ?? null);
        $row   = $this->optionalId($payload, 'target_row');

        if ($table === null && $row === null) {
            return [null, null];
        }
        if ($table === null) {
            throw $this->error('target_table_id', 'incomplete', 'target_table_id is required together with target_row');
        }
        if ($row === null) {
            throw $this->error('target_row', 'incomplete', 'target_row is required together with target_table_id');
        }
        if (!isset($this->tables[$table])) {
            throw $this->error('target_table_id', 'unknown_table', "Table '{$table}' is not known to this data source");
        }
        if (!$this->rowExists($table, $row)) {
            throw $this->error('target_row', 'not_found', "Record {$row} does not exist in '{$table}'");
        }
        return [$table, $row];
    }

    /** Volitelné kladné celé číslo; chybějící nebo null = null. */
    private function optionalId(array $payload, string $field): ?int
    {
        $value = $payload[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== (string) $value) {
            throw $this->error($field, 'invalid', "{$field} must be a positive integer");
        }
        return (int) $value;
    }

    private function rowExists(string $table, int $id): bool
    {
        return $this->db->fetchSingle('SELECT [id] FROM %n WHERE [id] = %i', $table, $id) !== null;
    }

    private function mainState(int $docState): int
    {
        $states = $this->config?->cfgItem(self::DOC_STATES_ITEM);
        $main   = is_array($states) ? ($states[(string) $docState]['mainState'] ?? null) : null;
        return is_numeric($main) ? (int) $main : self::MAIN_STATE_FALLBACK[$docState];
    }

    /**
     * @param array{name: string, tmp_name: string} $file
     * @return array<string, mixed> řádek nové přílohy
     */
    private function upload(int $messageId, array $file, ?int $userId): array
    {
        $result = $this->attachments->upload(
            SentMessageStore::TABLE_ID,
            $messageId,
            (string) $file['name'],
            (string) $file['tmp_name'],
            $userId,
        );
        if (!$result['success']) {
            throw new \RuntimeException(
                "Cannot store attachment '{$file['name']}': " . ($result['error'] ?? 'unknown error'),
            );
        }
        return $result['data'];
    }

    /** @param list<array<string, mixed>> $attachments */
    private function unlink(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $path = $this->attachments->getFilePath($attachment);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function error(string $field, string $code, string $message): SentMessageImportException
    {
        return new SentMessageImportException($field, $code, $message);
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private static function truncate(mixed $value, int $max): ?string
    {
        $text = self::nullIfEmpty($value);
        return $text === null ? null : mb_substr($text, 0, $max);
    }
}
