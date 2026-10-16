<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Overview;

use Shipard\Core\Overview\OverviewBlock;
use Shipard\Core\Overview\OverviewBlockResult;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Overview\OverviewItem;

/** Blok s jednou pevnou položkou — pro registr z configu a controller. */
final class StaticBlock implements OverviewBlock
{
    public function isAvailable(OverviewContext $ctx): bool
    {
        return true;
    }

    public function collect(OverviewContext $ctx): OverviewBlockResult
    {
        return new OverviewBlockResult([
            new OverviewItem('fixture:1', 'Fixture', 'fixture', OverviewItem::amount(1.5, 'czk'), $ctx->todayIso()),
        ]);
    }
}
