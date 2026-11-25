<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;

/** Fixture (#172 D12): akce, která pro žádný záznam není dostupná. */
class UnavailableAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return false;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor('Never available');
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        return RecordActionResult::success('never');
    }
}
