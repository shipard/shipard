<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Overview\OverviewBlockRegistry;
use Shipard\Core\Overview\OverviewCollector;
use Shipard\Core\Overview\OverviewContext;

/**
 * Přehled firmy — `GET /_ui/overview` (tasks/company-overview.md, #120).
 *
 * Jen pro adminy (D10): `adminOnly` na panelu je úklid navigace, bariéra
 * je tady — ne-admin dostane 403, nepřihlášený 401. Kontext (včetně
 * `ReportRunner`, který skenuje moduly) se staví až po kontrole práv.
 */
final class OverviewController
{
    /** @param \Closure(): OverviewContext $contextFactory */
    public function __construct(
        private readonly OverviewBlockRegistry $registry,
        private readonly OverviewCollector $collector,
        private readonly \Closure $contextFactory,
    ) {}

    public function index(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$auth->isAdmin) {
            return Response::error('FORBIDDEN', 'Admin access required', 403);
        }

        $ctx = ($this->contextFactory)();

        return Response::success($this->collector->collect($ctx, $this->registry));
    }
}
