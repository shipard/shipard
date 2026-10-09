<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\User\UserApplier;
use Shipard\Module\Economy\Assets\Import\AssetDocLinkService;
use Shipard\Module\Economy\Assets\Import\AssetImportApplier;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;

/**
 * REST endpoints for the canonical exchange formats. Parallel flavours
 * sharing the same response shape:
 *
 *   POST /api/v1/_exchange/docs/document/{validate|preview|apply}
 *   POST /api/v1/_exchange/persons/person/{validate|preview|apply}
 *   POST /api/v1/_exchange/items/item/{validate|preview|apply}
 *   POST /api/v1/_exchange/bank/statement/{validate|preview|apply}
 *
 * plus the user import (#93 D7, D13), which answers `{userId, created}`
 * and is limited to an admin or an API key:
 *
 *   POST /api/v1/_exchange/users/user/{validate|apply}
 *   POST /api/v1/_exchange/assets/asset/{validate|apply}   (#83 fáze 6, karta majetku s historií)
 *   POST /api/v1/_exchange/assets/doc-links/apply           (#83 fáze 6, karta na importovaných dokladech)
 *   POST /api/v1/_exchange/workOrders/workOrder/{validate|apply}  (#110 D25, D26, zakázka s předpisem)
 *
 * The controller is intentionally thin — body validation + delegate to
 * the relevant Applier + map ApplyResult to Response. Error shape
 * follows docs/exchange-format.md §"Error response shape" exactly:
 *
 *   { success: false, error: { code, message, details: <enriched canonical> } }
 *
 * `PersonApplier` / `ItemApplier` are injected optionally so document-only
 * deployments and existing unit tests can stub the controller without
 * wiring the person/item flows. Calling a /persons/* or /items/* endpoint
 * without the corresponding configured applier returns 500 INTERNAL_ERROR.
 */
final class ExchangeController
{
    public function __construct(
        private readonly DocumentApplier $applier,
        private readonly ?PersonApplier $personApplier = null,
        private readonly ?ItemApplier $itemApplier = null,
        private readonly ?BankStatementApplier $bankApplier = null,
        private readonly ?UserApplier $userApplier = null,
        private readonly ?AssetImportApplier $assetApplier = null,
        private readonly ?AssetDocLinkService $assetDocLinks = null,
        private readonly ?WorkOrderImportApplier $workOrderApplier = null,
    ) {}

    // ── Document flow ──────────────────────────────────────────────────

    public function validate(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->validate($payload), 'savedDocId');
    }

    public function preview(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->preview($payload), 'savedDocId');
    }

    public function apply(Request $request): Response
    {
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->applier->apply($payload), 'savedDocId');
    }

    // ── Person flow ────────────────────────────────────────────────────

    public function validatePerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->validate($payload), 'savedPersonId');
    }

    public function previewPerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->preview($payload), 'savedPersonId');
    }

    public function applyPerson(Request $request): Response
    {
        if ($this->personApplier === null) {
            return $this->personFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->personApplier->apply($payload), 'savedPersonId');
    }

    // ── Item flow ──────────────────────────────────────────────────────

    public function validateItem(Request $request): Response
    {
        if ($this->itemApplier === null) {
            return $this->itemFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->itemApplier->validate($payload), 'savedItemId');
    }

    public function previewItem(Request $request): Response
    {
        if ($this->itemApplier === null) {
            return $this->itemFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->itemApplier->preview($payload), 'savedItemId');
    }

    public function applyItem(Request $request): Response
    {
        if ($this->itemApplier === null) {
            return $this->itemFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->itemApplier->apply($payload), 'savedItemId');
    }

    // ── Bank statement flow ────────────────────────────────────────────

    public function validateBankStatement(Request $request): Response
    {
        if ($this->bankApplier === null) {
            return $this->bankFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->bankApplier->validate($payload), 'savedStatementId');
    }

    public function previewBankStatement(Request $request): Response
    {
        if ($this->bankApplier === null) {
            return $this->bankFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->bankApplier->preview($payload), 'savedStatementId');
    }

    public function applyBankStatement(Request $request): Response
    {
        if ($this->bankApplier === null) {
            return $this->bankFlowUnavailable();
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }
        return $this->respond($this->bankApplier->apply($payload), 'savedStatementId');
    }

    // ── User import flow (#93 D7, D13) ─────────────────────────────────

    public function validateUser(Request $request, AuthContext $auth): Response
    {
        return $this->userFlow($request, $auth, false);
    }

    public function applyUser(Request $request, AuthContext $auth): Response
    {
        return $this->userFlow($request, $auth, true);
    }

    /**
     * Smí admin nebo API klíč (D13). API klíč tím přihlášení nezíská:
     * applier zakládá jen neaktivní účty bez hesla a bez práv admina
     * a existující uživatele nemění (kromě vazby na Osobu).
     */
    private function userFlow(Request $request, AuthContext $auth, bool $apply): Response
    {
        $denied = $this->requireAdminOrApiKey($auth, 'User import');
        if ($denied !== null) {
            return $denied;
        }
        if ($this->userApplier === null) {
            return Response::error('INTERNAL_ERROR', 'User exchange flow is not wired in this dispatcher.', 500);
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }

        $result = $apply ? $this->userApplier->apply($payload) : $this->userApplier->validate($payload);
        if (!$result->success) {
            return $this->respond($result, 'userId');
        }
        return Response::success(
            ['userId' => $result->savedId, 'created' => $result->statusCode === 201],
            $result->statusCode,
        );
    }

    // ── Asset import flow (#83 fáze 6) ─────────────────────────────────

    public function validateAsset(Request $request, AuthContext $auth): Response
    {
        return $this->assetFlow($request, $auth, false);
    }

    public function applyAsset(Request $request, AuthContext $auth): Response
    {
        return $this->assetFlow($request, $auth, true);
    }

    /**
     * Karta majetku s historií (`shpd.assets.asset.v1`): oprávnění jako
     * import uživatelů. Úspěch `{status, assetId, warnings}`, chyba ve
     * společném tvaru s `details.issues` (cesta do payloadu + `sourceRef`).
     */
    private function assetFlow(Request $request, AuthContext $auth, bool $apply): Response
    {
        $denied = $this->requireAdminOrApiKey($auth, 'Asset import');
        if ($denied !== null) {
            return $denied;
        }
        if ($this->assetApplier === null) {
            return Response::error('INTERNAL_ERROR', 'Asset exchange flow is not available on this data source.', 500);
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }

        $result = $apply ? $this->assetApplier->apply($payload) : $this->assetApplier->validate($payload);
        if (!$result->success) {
            return Response::error(
                $result->errorCode ?? 'internal_error',
                $result->errorMessage ?? 'Unknown error',
                $result->statusCode,
                ['issues' => $result->issues],
            );
        }
        return Response::success($result->toArray(), $result->statusCode);
    }

    /**
     * Doplnění karty na doklad (D80): stav párování je obsah odpovědi, ne
     * HTTP chyba — runner čte `status` (`linked`, `unchanged`, `ambiguous`,
     * `notFound`, `conflict`, `turnover_changed`, `accounting_failed`).
     * Chybný tvar payloadu je 400 s `details.issues`.
     */
    public function applyAssetDocLinks(Request $request, AuthContext $auth): Response
    {
        $denied = $this->requireAdminOrApiKey($auth, 'Asset backfill');
        if ($denied !== null) {
            return $denied;
        }
        if ($this->assetDocLinks === null) {
            return Response::error('INTERNAL_ERROR', 'Asset document backfill is not available on this data source.', 500);
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }

        $result = $this->assetDocLinks->apply($payload);
        if ($result['status'] === AssetDocLinkService::STATUS_INVALID) {
            return Response::error('schema_invalid', 'Struktura doplnění karty neodpovídá tvaru.', 400, ['issues' => $result['issues']]);
        }
        return Response::success($result);
    }

    // ── Work order import flow (#110 D25, D26) ─────────────────────────

    public function validateWorkOrder(Request $request, AuthContext $auth): Response
    {
        return $this->workOrderFlow($request, $auth, false);
    }

    public function applyWorkOrder(Request $request, AuthContext $auth): Response
    {
        return $this->workOrderFlow($request, $auth, true);
    }

    /**
     * Zakázka s předpisem a řádky (`shpd.workOrders.workOrder.v1`):
     * oprávnění jako import majetku. Úspěch `{status, workOrderId, number,
     * warnings}` (201 při založení), chyba ve společném tvaru
     * s `details.issues` (cesta do payloadu).
     */
    private function workOrderFlow(Request $request, AuthContext $auth, bool $apply): Response
    {
        $denied = $this->requireAdminOrApiKey($auth, 'Work order import');
        if ($denied !== null) {
            return $denied;
        }
        if ($this->workOrderApplier === null) {
            return Response::error('INTERNAL_ERROR', 'Work order exchange flow is not available on this data source.', 500);
        }
        $payload = $this->extractPayload($request);
        if ($payload instanceof Response) {
            return $payload;
        }

        $result = $apply ? $this->workOrderApplier->apply($payload) : $this->workOrderApplier->validate($payload);
        if (!$result->success) {
            return Response::error(
                $result->errorCode ?? 'internal_error',
                $result->errorMessage ?? 'Unknown error',
                $result->statusCode,
                ['issues' => $result->issues],
            );
        }
        return Response::success($result->toArray(), $result->statusCode);
    }

    // ── Shared plumbing ────────────────────────────────────────────────

    /** Importy pro migraci smí admin nebo API klíč (#93 D13); null = povoleno. */
    private function requireAdminOrApiKey(AuthContext $auth, string $what): ?Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$auth->isAdmin && $auth->tokenType !== 'api_key') {
            return Response::error('FORBIDDEN', "{$what} requires an administrator or an API key", 403);
        }
        return null;
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function extractPayload(Request $request): array|Response
    {
        $body = $request->getBody();
        if (!is_array($body)) {
            return Response::error(
                'schema_invalid',
                'Tělo požadavku musí být JSON objekt.',
                400,
            );
        }
        return $body;
    }

    private function respond(ApplyResult $result, string $savedKey): Response
    {
        if ($result->success) {
            return Response::success(
                [
                    'canonical' => $result->canonical,
                    $savedKey   => $result->savedId,
                ],
                $result->statusCode,
            );
        }
        return Response::error(
            $result->errorCode ?? 'internal_error',
            $result->errorMessage ?? 'Unknown error',
            $result->statusCode,
            $result->canonical !== [] ? ['canonical' => $result->canonical] : [],
        );
    }

    private function personFlowUnavailable(): Response
    {
        return Response::error(
            'INTERNAL_ERROR',
            'Person exchange flow is not wired in this dispatcher.',
            500,
        );
    }

    private function itemFlowUnavailable(): Response
    {
        return Response::error(
            'INTERNAL_ERROR',
            'Item exchange flow is not wired in this dispatcher.',
            500,
        );
    }

    private function bankFlowUnavailable(): Response
    {
        return Response::error(
            'INTERNAL_ERROR',
            'Bank statement exchange flow is not wired in this dispatcher.',
            500,
        );
    }
}
