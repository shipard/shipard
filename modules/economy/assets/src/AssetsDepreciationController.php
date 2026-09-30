<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;

/**
 * HTTP obal „Odpisů za období“ (D33), routy `/_assets/depreciation-run…`:
 *
 *   GET  …/options                                   nabídka dialogu
 *   GET  …/preview?scope=tax|acc&period=<id>[&asset=<id>]
 *   POST …  {scope, period, asset?}                  provedení
 *
 * Logika je v DepreciationRunService; sem patří jen překlad vstupu
 * a výstupu (řádky plánu se klientovi neposílají).
 */
class AssetsDepreciationController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
    ) {
    }

    public function options(Request $request): Response
    {
        return Response::success($this->service()->options());
    }

    public function preview(Request $request): Response
    {
        $params = $request->getQueryParams();
        [$scope, $period, $asset] = self::input($params);
        if ($scope === null || $period === null) {
            return Response::error('BAD_REQUEST', 'Query must contain scope (tax|acc) and period id', 400);
        }
        try {
            $preview = $this->service()->preview($scope, $period, $asset);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        }
        return Response::success(self::stripRows($preview));
    }

    public function run(Request $request): Response
    {
        $body = $request->getBody();
        [$scope, $period, $asset] = self::input(is_array($body) ? $body : []);
        if ($scope === null || $period === null) {
            return Response::error('BAD_REQUEST', 'Body must contain scope (tax|acc) and period id', 400);
        }
        try {
            $result = $this->service()->run($scope, $period, $asset);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return Response::error('DEPRECIATION_RUN_FAILED', $e->getMessage(), 422);
        }
        return Response::success($result);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{?string, ?int, ?int} [scope, period, asset]
     */
    private static function input(array $params): array
    {
        $scope = isset($params['scope']) ? (string) $params['scope'] : null;
        $period = isset($params['period']) && ctype_digit((string) $params['period']) ? (int) $params['period'] : null;
        $asset = isset($params['asset']) && ctype_digit((string) $params['asset']) && (int) $params['asset'] > 0
            ? (int) $params['asset']
            : null;
        if ($scope !== null && !in_array($scope, ['tax', 'acc'], true)) {
            $scope = null;
        }
        return [$scope, $period, $asset];
    }

    /**
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private static function stripRows(array $preview): array
    {
        foreach ($preview['assets'] as &$item) {
            $item['periods'] = array_map(
                static fn(PlanRow $r): array => ['begin' => $r->period?->begin, 'end' => $r->period?->end, 'amount' => $r->amount],
                $item['rows'],
            );
            unset($item['rows']);
        }
        unset($item);
        return $preview;
    }

    private function service(): DepreciationRunService
    {
        $dibi = $this->db->getDibiConnection();
        $planService = new AssetPlanService($dibi, $this->config, $this->dsConfig->getCountry(), new SettingsStore($this->db));

        return new DepreciationRunService(
            $planService,
            new SystemDepreciationWriter($dibi),
            $dibi,
            new PlanMessageTexts($this->config),
        );
    }
}
