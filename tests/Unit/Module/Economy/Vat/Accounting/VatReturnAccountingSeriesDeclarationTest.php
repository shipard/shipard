<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingService;

/**
 * Řada pro zaúčtování přiznání je id řádku `docs_core_number_series`, který
 * `ds-reset` dropuje — modul proto deklaruje `clearSettingsOnReset`
 * (#131 D42); po resetu klíč zafixuje `ds-upgrade` při založení řady
 * „Majetek“.
 */
class VatReturnAccountingSeriesDeclarationTest extends TestCase
{
    public function testModuleDeclaresTheSettingForClearingOnReset(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/economy/vat/module.jsonc'));

        $this->assertSame(
            [['key' => VatReturnAccountingService::SETTING_SERIES, 'table' => 'docs_core_number_series']],
            $module->clearSettingsOnReset,
        );
    }
}
