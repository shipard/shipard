<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\NullOpenItemLookup;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accbal\ClearingRouter;
use Shipard\Module\Economy\Accbal\RouteSummary;

/**
 * Endpoints:
 *   POST /_accbal/match — dávkové přeúčtování clearingových úhrad na účet
 *   otevřeného předpisu ({@see ClearingRouter::rerouteAll}, #69 D4).
 *
 * Body (všechna pole volitelná): {"scope": "all", "partner": <int>,
 * "fiscalYear": <int>, "dryRun": <bool>}. Vyžaduje `scope: "all"` nebo aspoň
 * jeden filtr — validace zrcadlí CLI `accbal-match` (--all / --partner /
 * --fiscal-year). Response nese jen agregát z RouteSummary (verze kontraktu 2,
 * docs/accbal.md §5.7); per-result řádky (mohou být tisíce) se neserializují.
 * Primární konzument: import ze starého Shipardu (závěrečný krok `all`).
 */
class AccbalController
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly JournalEventDispatcher $journalEvents,
        private readonly ?OpenItemLookup $openItems = null,
        private readonly ?JournalContributorSet $journalContributors = null,
    ) {}

    /** POST /_accbal/match */
    public function match(Request $request): Response
    {
        // Běh nad velkým DS trvá nízké desítky sekund — nesmí ho utnout max_execution_time.
        set_time_limit(0);

        $body = $request->getBody() ?? [];

        $scope = $body['scope'] ?? null;
        if ($scope !== null && $scope !== 'all') {
            return Response::error('VALIDATION', 'Unsupported scope; only "all" is allowed', 400);
        }

        $filters = [];
        foreach (['partner', 'fiscalYear'] as $key) {
            if (!isset($body[$key])) {
                continue;
            }
            if (!is_int($body[$key]) || $body[$key] <= 0) {
                return Response::error('VALIDATION', "{$key} must be a positive integer", 400);
            }
            $filters[$key] = $body[$key];
        }

        if ($scope !== 'all' && $filters === []) {
            return Response::error(
                'VALIDATION',
                'Requires "scope": "all" or at least one filter (partner / fiscalYear)',
                400,
            );
        }

        $dryRun = $body['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return Response::error('VALIDATION', 'dryRun must be a boolean', 400);
        }

        $summary = $this->runMatch($filters, $dryRun);

        return Response::success([
            'dryRun'       => $dryRun,
            'candidates'   => $summary->candidates(),
            'routed'       => $summary->routed,
            'planned'      => $summary->planned,
            'skipped'      => $summary->skipped,
            'routedAmount' => $summary->routedAmount,
        ]);
    }

    /**
     * Seam pro testy (subclassing) — ClearingRouter je final, stubuje se
     * až celý běh dávky.
     *
     * @param array{partner?: int, fiscalYear?: int} $filters
     */
    protected function runMatch(array $filters, bool $dryRun): RouteSummary
    {
        $router = new ClearingRouter(
            $this->db->getDibiConnection(),
            $this->config,
            $this->journalEvents,
            // Bez lookupu (DS bez saldokonta) nemá co routovat → vše no_open_item.
            $this->openItems ?? new NullOpenItemLookup(),
            $this->journalContributors,
        );
        return $router->rerouteAll($filters, $dryRun);
    }
}
