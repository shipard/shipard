<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportRequest;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Reports\AssetReportSupport;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetPlanService;

/**
 * AssetReportSupport nad pamětí: karty a události drží
 * `TestAssetPlanService` (kalendářní roky 2021–2026, skutečná konfigurace
 * pravidel CZ).
 */
class TestAssetReportSupport extends AssetReportSupport
{
    public function __construct(public readonly TestAssetPlanService $plans)
    {
    }

    public function planService(ReportRequest $request): AssetPlanService
    {
        return $this->plans;
    }

    protected function loadCards(ReportRequest $request): array
    {
        $cards = array_values(array_filter(
            $this->plans->cards,
            static fn(array $card): bool => in_array((int) ($card['docState'] ?? 40), self::CARD_STATES, true),
        ));
        usort($cards, static fn(array $a, array $b): int
            => [$a['asset_number'] ?? '', $a['id']] <=> [$b['asset_number'] ?? '', $b['id']]);

        return $cards;
    }
}
