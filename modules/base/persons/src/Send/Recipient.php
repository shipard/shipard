<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Send;

/**
 * Adresa příjemce s důvodem, proč se na ni odesílá (#90 D35) — uživatel
 * v dialogu vidí, odkud adresa je, a ví, kde ji opravit.
 */
final readonly class Recipient
{
    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_PERSON  = 'person';

    /**
     * @param string $label Důvod lidsky, v jazyce rozhraní („Kontakt Účtárna
     *        — Faktury a daňové doklady“, „E-mail osoby“).
     */
    public function __construct(
        public string $email,
        public string $name,
        public string $source,
        public string $label,
        public ?int $contactId = null,
    ) {}

    /** @return array{email: string, name: string, source: string, label: string, contactId?: int} */
    public function toArray(): array
    {
        $out = [
            'email'  => $this->email,
            'name'   => $this->name,
            'source' => $this->source,
            'label'  => $this->label,
        ];
        if ($this->contactId !== null) {
            $out['contactId'] = $this->contactId;
        }
        return $out;
    }
}
