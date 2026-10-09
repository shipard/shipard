<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Import;

/**
 * Výsledek importu zakázky (`shpd.workOrders.workOrder.v1`,
 * docs/work-orders.md §6). Úspěch nese stav `created` / `skipped`, id
 * a číslo zakázky (runner si ho uloží pro `dimensions.workOrder` dokladů)
 * a varování; chyba kód, zprávu a nálezy s cestou do payloadu
 * (`workOrder.invoicing.numberSeries`, `rows.2.vatCode`).
 */
final class WorkOrderImportResult
{
    /**
     * @param list<array{code: string, message: string, path?: string}> $warnings
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function __construct(
        public readonly bool $success,
        public readonly int $statusCode,
        public readonly ?string $status,
        public readonly ?int $workOrderId,
        public readonly ?string $number,
        public readonly array $warnings,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $issues,
    ) {
    }

    /** @param list<array{code: string, message: string, path?: string}> $warnings */
    public static function ok(string $status, ?int $workOrderId, ?string $number, array $warnings = [], int $statusCode = 200): self
    {
        return new self(true, $statusCode, $status, $workOrderId, $number, $warnings, null, null, []);
    }

    /** @param list<array{severity: string, path: string, code: string, message: string}> $issues */
    public static function error(string $code, string $message, array $issues = [], int $statusCode = 422): self
    {
        return new self(false, $statusCode, null, null, null, [], $code, $message, $issues);
    }

    /** @return array<string, mixed> tělo odpovědi (úspěch) */
    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'workOrderId' => $this->workOrderId,
            'number'      => $this->number,
            'warnings'    => $this->warnings,
        ];
    }
}
