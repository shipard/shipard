<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

/**
 * Výsledek založení importovaného podání (před `finish`): id, pořadí
 * v instanci a souhrn toho, co import zapsal do zpráv podání.
 */
final readonly class FilingImportResult
{
    /**
     * @param list<string> $flags kódy zpráv `imported_*` bez řádkových detailů
     *        (`imported_without_xml`, `imported_order_irregular`, …)
     * @param list<string> $warnings lidsky čitelná varování (chybějící účetní doklad)
     * @param list<array<string, mixed>> $messages všechny zprávy, které import připojil
     */
    public function __construct(
        public int $filingId,
        public int $sequence,
        public int $mismatchRows,
        public int $lineMismatches,
        public array $flags,
        public array $warnings,
        public array $messages,
        public bool $dryRun = false,
    ) {}

    /** @return array<string, mixed> tvar odpovědi endpointu */
    public function toArray(): array
    {
        return [
            'filingId' => $this->filingId,
            'sequence' => $this->sequence,
            'messages' => [
                'mismatchRows'   => $this->mismatchRows,
                'lineMismatches' => $this->lineMismatches,
                'flags'          => $this->flags,
            ],
            'warnings' => $this->warnings,
            'dryRun'   => $this->dryRun,
        ];
    }
}
