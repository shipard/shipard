<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Shipard\Module\Economy\SalesRegistration\Queue\AttemptResult;
use Shipard\Module\Economy\SalesRegistration\Sales\SyncResult;

/**
 * Výsledek nastavení nebo zrušení kontaktní platby ({@see ContactPaymentService}).
 *
 * Buď se nic nezapsalo (`refusal` říká proč), nebo se příznak zapsal a pak:
 * `sync` je výsledek rozdílového výpočtu (null = výpočet spadl, tržbu dorovná
 * záchranná síť) a `attempt` výsledek okamžitého odeslání (null = tržba
 * zůstala frontě).
 */
final readonly class ContactPaymentOutcome
{
    /** Doklad se mezitím změnil (stav, příznak) — akce už neplatí. */
    public const string REFUSAL_CONFLICT = 'conflict';
    /** Typ dokladu nebo způsob úhrady příznak nést nesmí (U14). */
    public const string REFUSAL_NOT_ALLOWED = 'notAllowed';
    /** Čas platby není čitelný. */
    public const string REFUSAL_TIME = 'time';
    /** Čas platby je v budoucnu. */
    public const string REFUSAL_FUTURE = 'future';
    /** Doklad by ani s příznakem do evidence nespadl — `evaluationReason` říká proč (U15). */
    public const string REFUSAL_NOT_APPLICABLE = 'notApplicable';

    private function __construct(
        public ?string $refusal,
        public ?string $evaluationReason = null,
        public ?SyncResult $sync = null,
        public ?AttemptResult $attempt = null,
    ) {
    }

    public static function refused(string $refusal, ?string $evaluationReason = null): self
    {
        return new self($refusal, $evaluationReason);
    }

    public static function written(?SyncResult $sync, ?AttemptResult $attempt = null): self
    {
        return new self(null, sync: $sync, attempt: $attempt);
    }

    public function isWritten(): bool
    {
        return $this->refusal === null;
    }
}
