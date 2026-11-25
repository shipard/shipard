<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;

/** Fixture (#172 D19): `describe()` vrátí popis mimo kontrakt (neznámá varianta). */
class ThrowingDescribeAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return true;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor('Bad variant', 'success');
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
