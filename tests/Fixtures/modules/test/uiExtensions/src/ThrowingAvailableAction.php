<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;

/** Fixture (#172 D19): `available()` spadne — akce se v detailu vynechá. */
class ThrowingAvailableAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        throw new \RuntimeException('available failed');
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor('Never shown');
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
