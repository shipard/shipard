<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Mail\AddressList;

/**
 * Návrh odeslání záznamu (#90 D38, D42) — co by odešlo, komu a odkud, dřív
 * než cokoli vznikne. Dialog ho ukáže k úpravě, `--dry-run` vypíše.
 */
final readonly class SendDraft
{
    /**
     * @param ?array{id: int, name: string} $recipientPerson Osoba příjemce.
     * @param list<array<string, mixed>> $to Příjemci s důvodem (`email`, `name`,
     *        `source`, `label`, případně `contactId`).
     * @param list<string> $cc
     * @param ?array{email: string, name: ?string, source: string} $from Odesílatel;
     *        null = nejde určit (viz `messages`).
     * @param list<array{email: string, source: string}> $allowedSenders
     * @param list<array<string, mixed>> $attachments PDF tisku (`kind: print`)
     *        a přílohy záznamu (`kind: record`) s `selected` a `merged`.
     * @param list<array{severity: string, code: string, text: string}> $messages
     */
    public function __construct(
        public string $printId,
        public int $recordId,
        public string $table,
        public string $language,
        public string $purpose,
        public string $targetLabel,
        public string $fileName,
        public ?array $recipientPerson,
        public array $to,
        public array $cc,
        public ?array $from,
        public array $allowedSenders,
        public string $subject,
        public string $body,
        public array $attachments,
        public bool $mergeAttachments,
        public array $messages,
    ) {}

    /** @return list<string> */
    public function toEmails(): array
    {
        return AddressList::parse(array_column($this->to, 'email'));
    }

    /** První chyba, která odeslání brání; null = odeslat jde. */
    public function blockingError(): ?array
    {
        foreach ($this->messages as $message) {
            if ($message['severity'] === 'error') {
                return $message;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'printId'          => $this->printId,
            'recordId'         => $this->recordId,
            'table'            => $this->table,
            'language'         => $this->language,
            'purpose'          => $this->purpose,
            'targetLabel'      => $this->targetLabel,
            'recipientPerson'  => $this->recipientPerson,
            'to'               => $this->to,
            'cc'               => $this->cc,
            'from'             => $this->from,
            'allowedSenders'   => $this->allowedSenders,
            'subject'          => $this->subject,
            'body'             => $this->body,
            'attachments'      => $this->attachments,
            'mergeAttachments' => $this->mergeAttachments,
            'canSend'          => $this->blockingError() === null,
            'messages'         => $this->messages,
        ];
    }
}
