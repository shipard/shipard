<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

/**
 * Tisková úloha tak, jak ji služba vrací volajícímu: data jsou bajty pro
 * tiskárnu, do odpovědi REST je kóduje controller (base64).
 */
final class PrintJob
{
    /**
     * @param ?string $peripheralId ID periferie v aplikaci; null, když záznam
     *        periferie mezitím zmizel
     * @param list<array{severity: string, code: string, text: string}> $warnings
     */
    public function __construct(
        public readonly int $id,
        public readonly string $jobId,
        public readonly PrintJobKind $kind,
        public readonly PrintJobState $state,
        public readonly ?string $peripheralId,
        public readonly string $format,
        public readonly string $data,
        public readonly int $attempts,
        public readonly array $warnings,
        public readonly ?int $createdBy,
    ) {
    }
}
