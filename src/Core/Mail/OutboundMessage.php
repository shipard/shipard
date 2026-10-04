<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

/**
 * Odchozí zpráva — vstup pro MailOutboxService::enqueue(). Kdo posílá
 * rozhoduje volající: explicitní $from, jinak DS default ze settings
 * (`mail.defaultFrom`). Kudy zpráva půjde (custom sender vs. relay)
 * rozhoduje až TransportResolver při pokusu o odeslání.
 */
final readonly class OutboundMessage
{
    /**
     * @param string|list<string> $to Jedna adresa, nebo seznam adres „Komu“.
     * @param int[] $attachments id příloh z core_attachments_files
     * @param list<string> $cc Kopie.
     * @param ?string $fromName Jméno odesílatele vedle adresy; o transportu
     *        dál rozhoduje jen adresa.
     */
    public function __construct(
        public string|array $to,
        public string $subject,
        public string $sourceModule,
        public ?string $from = null,
        public ?string $bodyText = null,
        public ?string $bodyHtml = null,
        public array $attachments = [],
        public ?int $recipientPersonId = null,
        public ?string $sourceRef = null,
        public int $priority = 0,
        public ?int $createdBy = null,
        public array $cc = [],
        public ?string $fromName = null,
    ) {
    }

    /** Stejná zpráva s jinou prioritou — ostatní pole beze změny. */
    public function withPriority(int $priority): self
    {
        return clone($this, ['priority' => $priority]);
    }
}
