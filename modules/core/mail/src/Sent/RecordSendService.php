<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Mail\SenderResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Module\Base\Persons\Send\RecipientResolver;
use Shipard\Module\Core\Attachments\AttachmentService;

/**
 * Odeslání záznamu e-mailem (#90 D42, D44) — nad libovolným tiskem ven
 * s deklarovaným účelem (`sendPurpose`). Volá ho REST (dialog), CLI
 * `print-send` a později dávka; službu samotnou HTTP ani UI nezajímá.
 *
 * `prepare()` jen navrhne (komu, odkud, s jakým textem a přílohami) a nic
 * nemění. `send()` vytvoří **vždy novou** zprávu v Odeslané poště: PDF tisku
 * vyrobí znovu, příjemce dohledá živě z osoby a kontaktů (ne ze snapshotu
 * dokladu) a přílohy záznamu zkopíruje do zprávy nebo připojí do PDF. Na
 * záznamu samotném nic nevzniká — co odešlo, ví přes zprávy, které na něj
 * ukazují.
 */
class RecordSendService
{
    private const PDF_MIME = 'application/pdf';

    private const LABELS_ITEM = 'core.mail.sendLabels';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @var \Closure(string, int, PrintFormat, ?string): PrintOutput */
    private readonly \Closure $runPrint;

    /**
     * @param \Closure(string, int, PrintFormat, ?string): PrintOutput $runPrint
     *        Běh tisku — `PrintRunner::run(...)`; služba tisk nikdy neskládá sama.
     * @param array<string, TableDefinition> $tables Definice tabulek zdroje dat.
     * @param AttachmentService $attachments Bez guardů — služba zakládá
     *        přílohy zprávy, které jsou pro ostatní zamčené.
     * @param ?ConfigRuntime $config Konfigurace v jazyce rozhraní — texty hlášek.
     * @param ?\Closure(): \DateTimeImmutable $clock Čas (testy).
     */
    public function __construct(
        private readonly PrintRegistry $prints,
        \Closure $runPrint,
        private readonly PrintCatalogLoader $catalogs,
        private readonly PrintEmailRenderer $emails,
        private readonly RecipientResolver $recipients,
        private readonly SenderResolver $senders,
        private readonly AttachmentService $attachments,
        private readonly RenderClient $renderClient,
        private readonly SentMessageStore $store,
        private readonly SentMessageTransport $transport,
        private readonly DataSourceConnection $db,
        private readonly array $tables,
        private readonly ?ConfigRuntime $config = null,
        ?\Closure $clock = null,
    ) {
        $this->runPrint = $runPrint;
        $this->clock    = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Návrh odeslání — bez vedlejších účinků. Chybějící příjemce nebo
     * odesílatel není výjimka, ale chyba v `messages`: dialog ji ukáže
     * a uživatel adresu doplní.
     *
     * @throws PrintNotFoundException Neznámý tisk.
     * @throws RecordSendException `PRINT_NOT_SENDABLE`.
     * @throws PrintRecordNotFoundException|PrintNotAvailableException Jako u tisku.
     */
    public function prepare(SendRequest $request): SendDraft
    {
        return $this->compose($request, PrintFormat::Json)[0];
    }

    /**
     * Odešle záznam: nová zpráva v Odeslané poště + řádek fronty. Ruční
     * odeslání zkusí odeslat hned; selhání transportu nechá zprávu ve
     * frontě (další pokusy) a výsledek to řekne stavem `queued`.
     *
     * @throws RecordSendException Chybí příjemce nebo odesílatel, neplatná
     *         adresa, cizí příloha, prázdný text — nic nevznikne.
     * @throws PrintRenderException PDF nevzniklo — nic nevznikne.
     */
    public function send(SendRequest $request): SendResult
    {
        [$draft, $output, $definition, $recordAttachments] = $this->compose($request, PrintFormat::Pdf);

        $blocking = $draft->blockingError();
        if ($blocking !== null) {
            throw new RecordSendException($blocking['code'], $blocking['text']);
        }
        if (trim($draft->subject) === '' || trim($draft->body) === '') {
            throw new RecordSendException(
                RecordSendException::EMPTY_MESSAGE,
                $this->text('emptyMessage', 'The message needs both a subject and a body.'),
            );
        }

        $selected = array_values(array_filter(
            $recordAttachments,
            static fn (array $a): bool => $a['selected'],
        ));
        $pdf = $this->mergedPdf((string) $output->pdfContent, $selected);

        $now      = ($this->clock)();
        $created  = [];
        $priority = $request->trigger === SendRequest::TRIGGER_MANUAL;

        try {
            [$messageId, $outboxId] = NestedTransaction::run(
                $this->db->getDibiConnection(),
                function () use ($draft, $definition, $request, $pdf, $selected, $now, $priority, &$created): array {
                    $messageId = $this->store->create([
                        'subject'          => $draft->subject,
                        'body_text'        => $draft->body,
                        'email_from'       => $draft->from['email'],
                        'email_from_name'  => $draft->from['name'],
                        'email_to'         => AddressList::format($draft->toEmails()),
                        'email_cc'         => $draft->cc === [] ? null : AddressList::format($draft->cc),
                        'recipient_person' => $draft->recipientPerson['id'] ?? null,
                        'target_table_id'  => $definition->table,
                        'target_row'       => $request->recordId,
                        'target_label'     => mb_substr($draft->targetLabel, 0, 250),
                        'purpose'          => $draft->purpose,
                        'language'         => $draft->language,
                        'print_id'         => $definition->id,
                        'send_trigger'     => $request->trigger,
                        'created_by'       => $request->userId,
                    ], $now);

                    $created[] = $this->uploadPrint($messageId, $draft->fileName, $pdf, $request->userId);
                    foreach ($selected as $attachment) {
                        if ($attachment['merged']) {
                            continue;
                        }
                        $created[] = $this->copyAttachment((int) $attachment['id'], $messageId, $request->userId);
                    }

                    return [$messageId, $this->transport->enqueue($messageId, $priority, $request->userId)];
                },
            );
        } catch (\Throwable $e) {
            // Transakce se vrátila — soubory příloh na disku ne.
            foreach ($created as $attachment) {
                $path = $this->attachments->getFilePath($attachment);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }

        // Okamžitý pokus až po commitu: rollback nesmí přijít po odeslání.
        if ($priority) {
            $this->transport->attempt($outboxId);
        }

        $message   = $this->store->get($messageId);
        $transport = (new SentMessageTransportInfo($this->db, $this->config))->state($message ?? []);

        return new SendResult(
            $messageId,
            $outboxId,
            $transport['state'],
            $draft->messages,
            $transport['safety'],
        );
    }

    /**
     * Společné tělo návrhu a odeslání. Tisk běží jednou: návrh chce jen
     * data (`json`), odeslání rovnou PDF — obojí nese `PrintData` pro texty.
     *
     * @return array{0: SendDraft, 1: PrintOutput, 2: PrintDefinition, 3: list<array<string, mixed>>}
     */
    private function compose(SendRequest $request, PrintFormat $format): array
    {
        $definition = $this->prints->get($request->printId);
        if ($definition === null) {
            throw new PrintNotFoundException("Unknown print '{$request->printId}'");
        }
        if (!$definition->isSendable()) {
            throw new RecordSendException(
                RecordSendException::PRINT_NOT_SENDABLE,
                "Print '{$definition->id}' is not declared for sending (no sendPurpose)",
            );
        }

        $record = $this->db->fetchRow('SELECT * FROM %n WHERE [id] = %i', $definition->table, $request->recordId);
        if ($record === null) {
            throw new PrintRecordNotFoundException(
                "Record {$request->recordId} not found in '{$definition->table}'",
            );
        }
        if (!$definition->matches($record)) {
            throw new PrintNotAvailableException(
                "Print '{$definition->id}' is not available for this record in its current state",
            );
        }

        $messages = [];

        // Příjemci — živě z osoby a kontaktů (D44); přepsané pole má přednost.
        $personId = (int) ($record[(string) $definition->recipientPerson] ?? 0);
        $person   = $personId > 0 ? $this->person($personId) : null;
        $to       = $this->recipientsFor($request, $person, (string) $definition->sendPurpose, $messages);
        $cc       = $this->addresses($request->cc ?? [], $messages);

        $sender = $this->senders->resolve($definition->table, $record, $request->from);
        if (!$sender->isResolved()) {
            $messages[] = $this->error((string) $sender->errorCode, (string) $sender->errorText);
        }

        $output    = ($this->runPrint)($definition->id, $request->recordId, $format, $request->language);
        $printData = $output->printData;
        foreach ($printData->messages as $message) {
            $messages[] = $message->toArray();
        }

        $email = $this->emails->render(
            $definition,
            $printData,
            $this->catalogs->translator($definition, $printData->language),
        );

        $merge             = $person !== null && (int) ($person['send_attachments_merged'] ?? 0) === 1;
        $recordAttachments = $this->recordAttachments($definition, $request, $merge);

        $draft = new SendDraft(
            printId: $definition->id,
            recordId: $request->recordId,
            table: $definition->table,
            language: $printData->language,
            purpose: (string) $definition->sendPurpose,
            targetLabel: $printData->title,
            fileName: $printData->fileName,
            recipientPerson: $person === null
                ? null
                : ['id' => (int) $person['id'], 'name' => (string) ($person['full_name'] ?? '')],
            to: $to,
            cc: $cc,
            from: $sender->isResolved()
                ? ['email' => (string) $sender->email, 'name' => $sender->name, 'source' => (string) $sender->source]
                : null,
            allowedSenders: $this->senders->allowed()->addresses(),
            subject: $request->subject ?? $email->subject,
            body: $request->body ?? $email->body,
            attachments: [
                [
                    'id'        => null,
                    'kind'      => 'print',
                    'name'      => $printData->fileName,
                    'mimeType'  => self::PDF_MIME,
                    'fileSize'  => null,
                    'selected'  => true,
                    'merged'    => false,
                ],
                ...$recordAttachments,
            ],
            mergeAttachments: $merge,
            messages: $messages,
        );

        return [$draft, $output, $definition, $recordAttachments];
    }

    /**
     * „Komu“: adresy z požadavku (dialog, CLI), jinak z kontaktů a osoby.
     *
     * @param ?array<string, mixed> $person
     * @param list<array{severity: string, code: string, text: string}> $messages
     * @return list<array<string, mixed>>
     */
    private function recipientsFor(SendRequest $request, ?array $person, string $purpose, array &$messages): array
    {
        if ($request->to !== null) {
            $to = array_map(
                static fn (string $email): array => ['email' => $email, 'name' => '', 'source' => 'manual', 'label' => ''],
                $this->addresses($request->to, $messages),
            );
            if ($to === [] && $this->firstError($messages) === null) {
                $messages[] = $this->error(
                    RecordSendException::NO_RECIPIENT,
                    $this->text('noRecipient', 'The message has no recipient.'),
                );
            }
            return $to;
        }

        $resolution = $this->recipients->resolve($person === null ? null : (int) $person['id'], $purpose);
        array_push($messages, ...$resolution->messages);

        return array_map(static fn ($recipient): array => $recipient->toArray(), $resolution->recipients);
    }

    /**
     * Ručně zadané adresy: bez duplicit, každá syntakticky platná.
     *
     * @param list<mixed> $input
     * @param list<array{severity: string, code: string, text: string}> $messages
     * @return list<string>
     */
    private function addresses(array $input, array &$messages): array
    {
        $addresses = AddressList::parse($input);
        $invalid   = AddressList::invalid($addresses);
        if ($invalid !== null) {
            $messages[] = $this->error(
                RecordSendException::INVALID_EMAIL,
                str_replace(
                    '{email}',
                    $invalid,
                    $this->text('invalidEmail', "'{email}' is not a valid e-mail address."),
                ),
            );
        }
        return $addresses;
    }

    /**
     * Přílohy záznamu pro návrh: všechny nesmazané, zaškrtnuté podle
     * příznaku „Odeslat se záznamem“ (nebo podle výběru z požadavku).
     * `merged` = PDF, které se podle volby osoby připojí za PDF tisku (#94).
     *
     * @return list<array<string, mixed>>
     */
    private function recordAttachments(PrintDefinition $definition, SendRequest $request, bool $merge): array
    {
        $tableId = ($this->tables[$definition->table] ?? null)?->tableId;
        if ($tableId === null) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [file_name], [mime_type], [file_size], [send_with_record]'
            . ' FROM [core_attachments_files]'
            . ' WHERE [table_id] = %i AND [record_id] = %i AND [is_deleted] = 0'
            // Pořadí podle kontraktu odeslání záznamu (docs/attachments.md).
            . ' ORDER BY [att_order], [name], [id]',
            $tableId,
            $request->recordId,
        );

        $known = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        foreach ($request->attachmentIds ?? [] as $attachmentId) {
            if (!in_array((int) $attachmentId, $known, true)) {
                throw new RecordSendException(
                    RecordSendException::INVALID_ATTACHMENT,
                    "Attachment {$attachmentId} does not belong to the record being sent",
                );
            }
        }

        return array_map(function (array $row) use ($request, $merge): array {
            $mime     = (string) ($row['mime_type'] ?? '');
            $selected = $request->attachmentIds === null
                ? (int) ($row['send_with_record'] ?? 0) === 1
                : in_array((int) $row['id'], array_map('intval', $request->attachmentIds), true);

            return [
                'id'       => (int) $row['id'],
                'kind'     => 'record',
                'name'     => (string) ($row['name'] ?? $row['file_name']),
                'mimeType' => $mime,
                'fileSize' => (int) ($row['file_size'] ?? 0),
                'selected' => $selected,
                // Spojují se jen PDF; ostatní soubory jdou vždy zvlášť (#94 D11).
                'merged'   => $merge && $mime === self::PDF_MIME,
            ];
        }, $rows);
    }

    /**
     * PDF tisku s připojenými PDF přílohami (volba osoby, #94 D5).
     *
     * @param list<array<string, mixed>> $selected Vybrané přílohy záznamu.
     * @throws PrintRenderException Spojení PDF selhalo.
     */
    private function mergedPdf(string $pdf, array $selected): string
    {
        $append = [];
        foreach ($selected as $attachment) {
            if (!$attachment['merged']) {
                continue;
            }
            $row  = $this->attachments->getAttachment((int) $attachment['id']);
            $path = $row === null ? null : $this->attachments->getFilePath($row);
            if ($path === null || !is_file($path)) {
                throw new PrintRenderException(
                    RenderErrorKind::InvalidInput,
                    "Attachment {$attachment['id']} file is missing on disk",
                );
            }
            $append[] = (string) file_get_contents($path);
        }
        if ($append === []) {
            return $pdf;
        }

        $result = $this->renderClient->postProcess($pdf, [['step' => 'appendPdfs', 'params' => ['pdfs' => $append]]]);
        if (!$result->ok || $result->pdfContent === null) {
            throw new PrintRenderException($result->errorKind ?? RenderErrorKind::EngineError, (string) $result->note);
        }
        return $result->pdfContent;
    }

    /** @return array<string, mixed> řádek nové přílohy zprávy */
    private function uploadPrint(int $messageId, string $fileName, string $pdf, ?int $userId): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shpd-send-');
        if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
            throw new \RuntimeException('Cannot write the print PDF to a temporary file');
        }

        try {
            $result = $this->attachments->upload(SentMessageStore::TABLE_ID, $messageId, $fileName, $tmp, $userId);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        if (!$result['success']) {
            throw new \RuntimeException('Cannot store the print PDF: ' . ($result['error'] ?? 'unknown error'));
        }
        return $result['data'];
    }

    /** @return array<string, mixed> řádek nové přílohy zprávy */
    private function copyAttachment(int $attachmentId, int $messageId, ?int $userId): array
    {
        $result = $this->attachments->copyTo($attachmentId, SentMessageStore::TABLE_ID, $messageId, $userId);
        if (!$result['success']) {
            throw new \RuntimeException(
                "Cannot copy attachment {$attachmentId} to the message: " . ($result['error'] ?? 'unknown error'),
            );
        }
        return $result['data'];
    }

    /** @return array<string, mixed>|null */
    private function person(int $personId): ?array
    {
        return $this->db->fetchRow('SELECT * FROM [base_persons_persons] WHERE [id] = %i', $personId);
    }

    /** @param list<array{severity: string, code: string, text: string}> $messages */
    private function firstError(array $messages): ?array
    {
        foreach ($messages as $message) {
            if ($message['severity'] === 'error') {
                return $message;
            }
        }
        return null;
    }

    /** @return array{severity: string, code: string, text: string} */
    private function error(string $code, string $text): array
    {
        return ['severity' => 'error', 'code' => $code, 'text' => $text];
    }

    /** Text z `core.mail.sendLabels`; bez zkompilované konfigurace anglický fallback. */
    private function text(string $key, string $fallback): string
    {
        $labels = $this->config?->cfgItem(self::LABELS_ITEM);
        $text   = is_array($labels) ? ($labels[$key]['name'] ?? null) : null;
        return is_string($text) && $text !== '' ? $text : $fallback;
    }
}
