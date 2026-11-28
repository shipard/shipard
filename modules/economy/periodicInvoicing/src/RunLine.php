<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing;

/**
 * Jeden řádek výsledku běhu: záznam zdroje × období → výsledek.
 * `recordLabel` dává zdroj (číslo a název záznamu).
 */
final readonly class RunLine
{
    public function __construct(
        public string $sourceId,
        public int $recordId,
        public string $recordLabel,
        public ?string $periodFrom,
        public ?string $periodTo,
        public string $outcome,
        public ?int $docId = null,
        public ?string $message = null,
        public ?int $periodId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceId'    => $this->sourceId,
            'recordId'    => $this->recordId,
            'recordLabel' => $this->recordLabel,
            'periodId'    => $this->periodId,
            'periodFrom'  => $this->periodFrom,
            'periodTo'    => $this->periodTo,
            'outcome'     => $this->outcome,
            'docId'       => $this->docId,
            'message'     => $this->message,
        ];
    }
}
