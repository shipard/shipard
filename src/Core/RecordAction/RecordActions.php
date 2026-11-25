<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

use Shipard\Core\Module\ModuleUiExtensions;

/**
 * Akce záznamu z modulů pro detail (`recordActions`, #172 D12, D19) — sběr
 * pro builder `TableViewer::renderDetail()`. V pořadí registru (`order`,
 * id modulu, deklarace) se zeptá každé akce tabulky, jestli je pro záznam
 * dostupná, a z jejího popisu složí položku `detail.actions`:
 *
 * ```json
 * {"id": "economy.salesRegistration.uploadCertificate", "kind": "record_action",
 *  "label": "Nahrát certifikát", "variant": "secondary",
 *  "confirm": null, "dialog": {...},
 *  "target": {"table": "…", "recordId": 42}}
 * ```
 *
 * Akci `admin: true` uživatel, který není správce, nedostane. Výjimka
 * z `available()` / `describe()` i popis mimo kontrakt se zaloguje a akce
 * se vynechá (`ModuleUiExtensions::call()`), ostatní akce zůstávají.
 */
final class RecordActions
{
    public const string KIND = 'record_action';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $record holý řádek tabulky (stejný dostane `execute()`)
     * @return list<array<string, mixed>>
     */
    public static function forDetail(
        ModuleUiExtensions $extensions,
        RecordActionContext $context,
        array $record,
        int $recordId,
    ): array {
        $actions = [];
        foreach ($extensions->for(ModuleUiExtensions::RECORD_ACTIONS, $context->table) as $extension) {
            if ($extension->admin && !$context->isAdmin) {
                continue;
            }

            // Nedostupná akce = prázdné pole; `null` je selhání poskytovatele.
            $described = $extensions->call(
                $extension,
                static fn (RecordAction $action): array => $action->available($context->table, $record, $context)
                    ? $action->describe($context->table, $record, $context)->toArray()
                    : [],
            );
            if ($described === null || $described === []) {
                continue;
            }

            $actions[] = ['id' => $extension->globalId(), 'kind' => self::KIND]
                + $described
                + ['target' => ['table' => $context->table, 'recordId' => $recordId]];
        }
        return $actions;
    }
}
