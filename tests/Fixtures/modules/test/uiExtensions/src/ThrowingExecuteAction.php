<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;

/** Fixture (#172 D14): `execute()` spadne výjimkou, která není chybou vstupu; hláška nese „tajemství“. */
class ThrowingExecuteAction implements RecordAction
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return true;
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor('Fixture crash');
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        throw new \RuntimeException('secret-detail-of-the-failure');
    }
}
