<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Assets\Posting\AssetPostingDocuments;
use Shipard\Module\Economy\Assets\Posting\AssetPostingException;
use Shipard\Module\Economy\Assets\Posting\AssetPostingService;

/**
 * HTTP obal „Odpisů za období“ (D33), routy `/_assets/depreciation-run…`:
 *
 *   GET  …/options                                   nabídka dialogu
 *   GET  …/preview?scope=tax|acc&period=<id>[&asset=<id>]
 *   POST …  {scope, period, asset?}                  provedení
 *
 * a „Odpisů a zaúčtování za období“ (D50–D55), routy `/_assets/posting…`
 * — účetní okruh: odpisy + účetní doklad za období:
 *
 *   GET  …/preview?period=<id>                       náhled
 *   POST …  {period}                                 zaúčtování
 *   POST …/cancel  {period}                          zrušení zaúčtování období
 *
 * Logika je v DepreciationRunService a AssetPostingService; sem patří jen
 * překlad vstupu a výstupu (řádky plánu se klientovi neposílají).
 */
class AssetsDepreciationController
{
    /**
     * Registry dokumentů, definice tabulek a dispatcher potřebuje jen
     * zaúčtování — doklad jde přes TableGateway se stejnými handlery jako
     * formulář.
     *
     * @param array<string, TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config,
        private readonly DataSourceConfig $dsConfig,
        private readonly ?DocumentRegistry $documents = null,
        private readonly array $tables = [],
        private readonly ?DocumentEventDispatcher $dispatcher = null,
    ) {
    }

    public function options(Request $request): Response
    {
        return Response::success(
            $this->service()->options() + ['lastPosting' => $this->postingService()->lastPosting()],
        );
    }

    // ── Odpisy a zaúčtování za období ───────────────────────────────────────

    public function postingPreview(Request $request): Response
    {
        $period = self::period($request->getQueryParams());
        if ($period === null) {
            return Response::error('BAD_REQUEST', 'Query must contain period id', 400);
        }
        try {
            $preview = $this->postingService()->preview($period);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        }
        return Response::success($preview);
    }

    public function posting(Request $request): Response
    {
        $body = $request->getBody();
        $period = self::period(is_array($body) ? $body : []);
        if ($period === null) {
            return Response::error('BAD_REQUEST', 'Body must contain period id', 400);
        }
        try {
            $result = $this->postingService()->post($period);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (AssetPostingException $e) {
            return Response::error('ASSET_POSTING_FAILED', $e->getMessage(), 422, self::details($e));
        } catch (\DomainException $e) {
            return Response::error('ASSET_POSTING_FAILED', $e->getMessage(), 422);
        }
        return Response::success($result);
    }

    public function postingCancel(Request $request): Response
    {
        $body = $request->getBody();
        $period = self::period(is_array($body) ? $body : []);
        if ($period === null) {
            return Response::error('BAD_REQUEST', 'Body must contain period id', 400);
        }
        try {
            $result = $this->postingService()->unpost($period);
        } catch (\InvalidArgumentException $e) {
            return Response::error('BAD_REQUEST', $e->getMessage(), 400);
        } catch (AssetPostingException $e) {
            return Response::error('ASSET_POSTING_CANCEL_FAILED', $e->getMessage(), 422, self::details($e));
        }
        return Response::success($result);
    }

    /** @param array<string, mixed> $params */
    private static function period(array $params): ?int
    {
        return isset($params['period']) && ctype_digit((string) $params['period']) ? (int) $params['period'] : null;
    }

    /**
     * Kód odmítnutí služby + podrobnosti (validace dokladu, zprávy účtování).
     *
     * @return list<array<string, mixed>>
     */
    private static function details(AssetPostingException $e): array
    {
        $details = [['field' => '', 'code' => $e->errorCode, 'message' => $e->getMessage()]];
        foreach ($e->details as $detail) {
            $details[] = ['field' => '', 'code' => (string) $detail['code'], 'message' => (string) $detail['message']];
        }
        return $details;
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

    private function postingService(): AssetPostingService
    {
        $dibi = $this->db->getDibiConnection();
        $settings = new SettingsStore($this->db);
        $planService = new AssetPlanService($dibi, $this->config, $this->dsConfig->getCountry(), $settings);
        $writer = new SystemDepreciationWriter($dibi);

        return new AssetPostingService(
            $dibi,
            $planService,
            new DepreciationRunService($planService, $writer, $dibi, new PlanMessageTexts($this->config)),
            $writer,
            new AssetPostingDocuments($dibi, $this->config, $this->dsConfig, $this->documents, $this->tables, $this->dispatcher),
            $settings,
        );
    }
}
