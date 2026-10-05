<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Symfony\Component\Mime\Email;

/**
 * Co pojistka odchozí pošty udělala s jedním e-mailem (#95 D4, D6).
 * `email` je zpráva, která má odejít — u `dropped` se neposílá vůbec.
 */
final readonly class MailSafetyResult
{
    /** Pojistka do příjemců nezasáhla. */
    public const ACTION_NONE = 'none';
    /** Skuteční příjemci jsou jiní než na řádku fronty. */
    public const ACTION_REDIRECTED = 'redirected';
    /** Zpráva se neodešle. */
    public const ACTION_DROPPED = 'dropped';

    /**
     * @param ?string $target Adresa přesměrování; null u `redirected` znamená,
     *        že část příjemců vypadla a nikam se nepřesměrovala.
     */
    public function __construct(
        public string $action,
        public Email $email,
        public ?string $target = null,
    ) {
    }

    public function isDropped(): bool
    {
        return $this->action === self::ACTION_DROPPED;
    }

    /** Pojistka zasáhla — zpráva nese stopu. */
    public function intervened(): bool
    {
        return $this->action !== self::ACTION_NONE;
    }
}
