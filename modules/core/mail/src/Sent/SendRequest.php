<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

/**
 * Požadavek na odeslání záznamu e-mailem (#90 D42). Jen `printId`
 * a `recordId` jsou povinné — co volající neurčí, dohledá služba: příjemce
 * z kontaktů a osoby, odesílatele z číselné řady, texty ze šablon tisku,
 * přílohy podle příznaku „Odeslat se záznamem“.
 */
final readonly class SendRequest
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_CLI    = 'cli';
    /** Rezerva pro hromadné a automatické odesílání (D11). */
    public const TRIGGER_BATCH  = 'batch';

    public const TRIGGERS = [self::TRIGGER_MANUAL, self::TRIGGER_CLI, self::TRIGGER_BATCH];

    /**
     * @param ?list<string> $to „Komu“; null = příjemci z kontaktů a osoby.
     * @param ?list<string> $cc Kopie; null = žádné.
     * @param ?list<int> $attachmentIds Přílohy záznamu, které se pošlou;
     *        null = ty s příznakem „Odeslat se záznamem“.
     */
    public function __construct(
        public string $printId,
        public int $recordId,
        public ?string $language = null,
        public ?string $from = null,
        public ?array $to = null,
        public ?array $cc = null,
        public ?string $subject = null,
        public ?string $body = null,
        public ?array $attachmentIds = null,
        public ?int $userId = null,
        public string $trigger = self::TRIGGER_MANUAL,
    ) {
        if (!in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException("Unknown send trigger '{$trigger}'");
        }
    }
}
