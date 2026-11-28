<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing;

/**
 * Parametry běhu periodické fakturace (docs/work-orders.md D5, Q4; #175 D6).
 *
 *  - `date` = datum běhu (splatnost období), `now` = časová značka zápisů;
 *  - `sourceId` = jen jeden zdroj fakturace (CLI `--source`), null = všechny;
 *  - `recordId` = jen jeden záznam zdroje (akce Vystavit dlužná období, CLI
 *    `--record`) — id záznamu je jednoznačné jen ve zdroji, proto bez
 *    `sourceId` nejde;
 *  - `dryRun` = jen výčet, žádný zápis (ani založení období);
 *  - `force` = bez pojistky dohánění (víc než MAX_CATCHUP splatných období).
 */
final readonly class RunOptions
{
    public function __construct(
        public string $date,
        public ?string $sourceId = null,
        public ?int $recordId = null,
        public bool $dryRun = false,
        public bool $force = false,
        public ?string $now = null,
    ) {
        if ($recordId !== null && $sourceId === null) {
            throw new \InvalidArgumentException('RunOptions: recordId requires sourceId');
        }
    }

    public static function today(?string $sourceId = null, ?int $recordId = null, bool $dryRun = false, bool $force = false): self
    {
        return new self(date('Y-m-d'), $sourceId, $recordId, $dryRun, $force, date('Y-m-d H:i:s'));
    }

    public function timestamp(): string
    {
        return $this->now ?? ($this->date . ' 00:00:00');
    }
}
