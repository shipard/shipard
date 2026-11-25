<?php

declare(strict_types=1);

namespace Shipard\Module\Test\UiExtensions;

use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;

/**
 * Fixture (#172 D12): akce bez dialogu a bez potvrzení. Záznamu druhu
 * `skip` se netýká. Provedení si pamatuje, co dostalo.
 */
class FixturePingAction implements RecordAction
{
    /** @var list<array{table: string, record: array<string, mixed>, userId: ?int, isAdmin: bool, language: string}> */
    public static array $executions = [];

    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return ($record['kind'] ?? null) !== 'skip';
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor('Fixture ping', icon: 'check');
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        self::$executions[] = [
            'table' => $table,
            'record' => $record,
            'userId' => $context->userId,
            'isAdmin' => $context->isAdmin,
            'language' => $context->language,
        ];
        return RecordActionResult::success('Ping ' . ($record['id'] ?? '?'));
    }
}
