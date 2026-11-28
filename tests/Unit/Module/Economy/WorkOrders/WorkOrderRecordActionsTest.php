<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\PeriodicInvoicing\Action\IssueDueAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RegenerateAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RestoreAction;
use Shipard\Module\Economy\WorkOrders\WorkOrderInvoicingSource;

/**
 * Akce záložky Fakturace zakázky (#175 D8): akce záznamu se společnými
 * třídami enginu, deklarované nad tabulkou zakázek — žádné id akce napevno
 * ve frontendu.
 */
class WorkOrderRecordActionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../..';

    public function testModuleDeclaresTheThreeInvoicingActionsOverItsSourceTable(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::ROOT . '/modules/economy/workOrders/module.jsonc'));
        $table = (new WorkOrderInvoicingSource())->table();

        $this->assertSame(
            [
                ['issueDue', $table, IssueDueAction::class, false],
                ['regenerate', $table, RegenerateAction::class, false],
                ['restore', $table, RestoreAction::class, false],
            ],
            array_map(static fn(array $a): array => [$a['id'], $a['table'], $a['class'], $a['admin']], $module->recordActions),
        );

        $extensions = ModuleUiExtensions::fromModules([$module]);
        $ids = [];
        foreach ($extensions->for('recordActions', $table) as $extension) {
            $ids[] = $extension->globalId();
            $this->assertInstanceOf(RecordAction::class, new ($extension->class)());
        }
        // Pořadí tlačítek jako dřív: Vystavit dlužná období, Přegenerovat, Obnovit.
        $this->assertSame(['economy.workOrders.issueDue', 'economy.workOrders.regenerate', 'economy.workOrders.restore'], $ids);
        $this->assertNotNull($extensions->recordAction($table, 'economy.workOrders.regenerate'));
    }

    public function testFrontendAndCoreNoLongerKnowWorkOrderActions(): void
    {
        $viewer = (string) file_get_contents(self::ROOT . '/frontend/src/components/viewer/Viewer.svelte');
        $this->assertStringNotContainsString('workOrder', $viewer);
        $this->assertFileDoesNotExist(self::ROOT . '/frontend/src/api/workOrders.js');
        foreach (['cs', 'en'] as $language) {
            $this->assertStringNotContainsString('viewer.detail.workOrder', (string) file_get_contents(self::ROOT . "/frontend/src/i18n/{$language}.js"));
        }
        $this->assertStringNotContainsString('_work-orders', (string) file_get_contents(self::ROOT . '/src/Api/Router.php'));
        $this->assertStringNotContainsString("'workOrders'", (string) file_get_contents(self::ROOT . '/src/Api/ReadOnlyPolicy.php'));
        $this->assertStringNotContainsString('dispatchWorkOrders', (string) file_get_contents(self::ROOT . '/public/index.php'));
    }
}
