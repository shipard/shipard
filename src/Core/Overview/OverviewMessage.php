<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

/**
 * Zpráva sekce Přehledu firmy — místo čísla nebo vedle něj: pojistka
 * počátečních stavů (D6 / D15), nespárované platby (D18), chyba bloku.
 * `code` je stabilní pro frontend i testy, `text` už lokalizovaný ze
 * serveru (katalog `*.overviewTexts`).
 */
final readonly class OverviewMessage
{
    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR   = 'error';

    /** @param ?array{kind: string, target: array<string, mixed>} $link */
    public function __construct(
        public string $severity,
        public string $code,
        public string $text,
        public ?array $link = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code'     => $this->code,
            'text'     => $this->text,
            'link'     => $this->link,
        ];
    }
}
