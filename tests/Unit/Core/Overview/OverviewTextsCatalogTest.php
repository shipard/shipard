<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Overview;

use Shipard\Tests\Unit\Core\Feed\TextsCatalogTestBase;

/**
 * Úplnost katalogů textů Přehledu firmy (#120) — cfgItemy `*.overviewTexts`
 * modulů, které dodávají bloky; pravidla v {@see TextsCatalogTestBase}.
 */
final class OverviewTextsCatalogTest extends TextsCatalogTestBase
{
    protected static function suffix(): string
    {
        return '.overviewTexts';
    }

    protected static function expected(): array
    {
        return [
            'core.system.overviewTexts', 'economy.bank.overviewTexts', 'economy.accounting.overviewTexts',
            'economy.accbal.overviewTexts', 'economy.vat.overviewTexts',
        ];
    }

    protected static function sources(): array
    {
        return [
            'core.system.overviewTexts'        => ['src/Core/Overview/OverviewCollector.php'],
            'economy.bank.overviewTexts'       => ['modules/economy/bank/src/Overview/BankBalancesBlock.php'],
            'economy.accounting.overviewTexts' => [
                'modules/economy/accounting/src/Overview/CashBalancesBlock.php',
                'modules/economy/accounting/src/Overview/ProfitLossBlock.php',
            ],
            'economy.accbal.overviewTexts'     => [
                'modules/economy/accbal/src/Overview/AccbalBlockBase.php',
                'modules/economy/accbal/src/Overview/ReceivablesBlock.php',
                'modules/economy/accbal/src/Overview/PayablesBlock.php',
            ],
            'economy.vat.overviewTexts' => ['modules/economy/vat/src/Overview/VatBlock.php'],
        ];
    }
}
