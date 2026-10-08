<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/** Jeden řádek výsledku běhu: zakázka × období → výsledek. */
final readonly class RunLine
{
    public function __construct(
        public int $workOrderId,
        public string $workOrderNumber,
        public string $workOrderTitle,
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
            'workOrderId'     => $this->workOrderId,
            'workOrderNumber' => $this->workOrderNumber,
            'workOrderTitle'  => $this->workOrderTitle,
            'periodId'        => $this->periodId,
            'periodFrom'      => $this->periodFrom,
            'periodTo'        => $this->periodTo,
            'outcome'         => $this->outcome,
            'docId'           => $this->docId,
            'message'         => $this->message,
        ];
    }
}
