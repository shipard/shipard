<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Endpoints:
 *   GET   /_alerts/registry                       — list zaregistrovaných checků + jejich runtime info
 *   POST  /_alerts/checks/{checkId}/run           — synchronní re-run jednoho checku
 *   POST  /_alerts/alerts/{id}/snooze             — body: {"duration":"PT1H"} | {"hours":1} | {"days":7} | {"minutes":30}
 *   POST  /_alerts/alerts/{id}/dismiss
 *   POST  /_alerts/alerts/{id}/unsnooze
 */
class AlertsController
{
    public const ALERTS_TABLE = AlertReconciler::ALERTS_TABLE;
    public const CHECK_STATES_TABLE = AlertReconciler::CHECK_STATES_TABLE;

    /** Min 5 minut snooze (ochrana před zacyklenými snooze/expire). */
    private const SNOOZE_MIN_SECONDS = 300;
    /** Max 1 rok snooze (deliberate "navždy" je dismiss, ne snooze). */
    private const SNOOZE_MAX_SECONDS = 365 * 86400;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {}

    /** GET /_alerts/registry */
    public function registry(): Response
    {
        $stateRows = $this->db->fetchAll(
            'SELECT check_id, enabled, next_run_at, last_run_at, last_run_status,'
            . ' last_run_duration_ms, last_run_findings, last_run_error,'
            . ' is_running, running_since FROM %n',
            self::CHECK_STATES_TABLE,
        );
        $stateByCheck = [];
        foreach ($stateRows as $row) {
            $stateByCheck[(string) $row['check_id']] = $row;
        }

        $data = [];
        foreach ($this->registry->getAll() as $def) {
            $state = $stateByCheck[$def->id] ?? null;
            $data[] = [
                'id'              => $def->id,
                'name'            => $def->name,
                'description'     => $def->description,
                'class'           => $def->class,
                'moduleId'        => $def->moduleId,
                'severity'        => $def->severity,
                'interval'        => $def->interval,
                'intervalSeconds' => $def->intervalSeconds,
                'enabled'         => $def->enabled,
                'tags'            => $def->tags,
                // Runtime info — null pokud check ještě neběžel
                'enabledRuntime'  => $state !== null ? (bool) $state['enabled'] : null,
                'nextRunAt'       => $state['next_run_at'] ?? null,
                'lastRunAt'       => $state['last_run_at'] ?? null,
                'lastRunStatus'   => $state['last_run_status'] ?? null,
                'lastRunDurationMs' => $state !== null ? ($state['last_run_duration_ms'] ?? null) : null,
                'lastRunFindings' => $state !== null ? ($state['last_run_findings'] ?? null) : null,
                'lastRunError'    => $state['last_run_error'] ?? null,
                'isRunning'       => $state !== null ? (bool) $state['is_running'] : false,
            ];
        }

        return Response::success($data);
    }

    /** POST /_alerts/run-due — spustí všechny due (a dosud nikdy nespuštěné) enabled checky */
    public function runDue(): Response
    {
        $reconciler = new AlertReconciler($this->db, $this->registry, $this->config, $this->language, $this->dsConfig);
        $checkIds   = $reconciler->getDueCheckIds();

        $results = [];
        $stats   = ['ok' => 0, 'found' => 0, 'error' => 0, 'skipped' => 0];
        $totalFindings = 0;
        $totalNew      = 0;

        foreach ($checkIds as $checkId) {
            $r = $reconciler->runCheck($checkId);
            $results[] = $r->toArray();
            $stats[$r->status] = ($stats[$r->status] ?? 0) + 1;
            $totalFindings    += $r->findingsCount;
            $totalNew         += $r->newCount;
        }

        return Response::success([
            'checksRun'      => count($checkIds),
            'totalFindings'  => $totalFindings,
            'newFindings'    => $totalNew,
            'stats'          => $stats,
            'results'        => $results,
        ]);
    }

    /** POST /_alerts/checks/{checkId}/run */
    public function runCheck(string $checkId): Response
    {
        if ($checkId === '') {
            return Response::error('VALIDATION_ERROR', 'Missing checkId', 422);
        }
        if ($this->registry->get($checkId) === null) {
            return Response::error('NOT_FOUND', "Check '{$checkId}' is not registered", 404);
        }

        $reconciler = new AlertReconciler($this->db, $this->registry, $this->config, $this->language, $this->dsConfig);
        $result     = $reconciler->runCheck($checkId);

        // Plus aktuální seznam open alertů z tohoto checku.
        $openAlerts = $this->db->fetchAll(
            'SELECT id, finding_key, title, message, severity, alert_state,'
            . ' snoozed_until, first_seen_at, last_seen_at, seen_count, actions'
            . ' FROM %n WHERE check_id = %s AND alert_state IN %in'
            . ' ORDER BY last_seen_at DESC',
            self::ALERTS_TABLE,
            $checkId,
            [AlertReconciler::STATE_ACTIVE, AlertReconciler::STATE_SNOOZED],
        );

        return Response::success([
            'result'     => $result->toArray(),
            'openAlerts' => array_map(fn (array $r) => $this->decorateAlert($r), $openAlerts),
        ]);
    }

    /** POST /_alerts/alerts/{id}/snooze */
    public function snooze(int $id, Request $request): Response
    {
        if ($id <= 0) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }

        $body = $request->getBody() ?? [];
        try {
            $seconds = self::parseDurationSeconds($body);
        } catch (\InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 422);
        }

        $alert = $this->db->fetchRow(
            'SELECT id, alert_state, check_id FROM %n WHERE id = %i',
            self::ALERTS_TABLE, $id,
        );
        if ($alert === null) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }
        if ($this->isSetupAlert((string) ($alert['check_id'] ?? ''))) {
            return $this->setupAlertError();
        }
        if (!in_array((int) $alert['alert_state'], [AlertReconciler::STATE_ACTIVE, AlertReconciler::STATE_SNOOZED], true)) {
            return Response::error(
                'INVALID_STATE',
                'Only Active or Snoozed alerts can be snoozed',
                409,
            );
        }

        $now           = new \DateTimeImmutable();
        $snoozedUntil  = $now->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');

        $this->db->updateWhere(
            self::ALERTS_TABLE,
            [
                'alert_state'   => AlertReconciler::STATE_SNOOZED,
                'snoozed_until' => $snoozedUntil,
            ],
            '%n = %i', 'id', $id,
        );

        return Response::success([
            'id'           => $id,
            'alertState'   => AlertReconciler::STATE_SNOOZED,
            'snoozedUntil' => $snoozedUntil,
        ]);
    }

    /** POST /_alerts/alerts/{id}/dismiss */
    public function dismiss(int $id): Response
    {
        if ($id <= 0) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }

        $alert = $this->db->fetchRow(
            'SELECT id, alert_state, check_id FROM %n WHERE id = %i',
            self::ALERTS_TABLE, $id,
        );
        if ($alert === null) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }
        if ($this->isSetupAlert((string) ($alert['check_id'] ?? ''))) {
            return $this->setupAlertError();
        }
        if (!in_array((int) $alert['alert_state'], [AlertReconciler::STATE_ACTIVE, AlertReconciler::STATE_SNOOZED], true)) {
            return Response::error(
                'INVALID_STATE',
                'Only Active or Snoozed alerts can be dismissed',
                409,
            );
        }

        $nowStr = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->updateWhere(
            self::ALERTS_TABLE,
            [
                'alert_state'   => AlertReconciler::STATE_DISMISSED,
                'dismissed_at'  => $nowStr,
                'snoozed_until' => null,
            ],
            '%n = %i', 'id', $id,
        );

        return Response::success([
            'id'           => $id,
            'alertState'   => AlertReconciler::STATE_DISMISSED,
            'dismissedAt'  => $nowStr,
        ]);
    }

    /** POST /_alerts/alerts/{id}/unsnooze */
    public function unsnooze(int $id): Response
    {
        if ($id <= 0) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }

        $alert = $this->db->fetchRow(
            'SELECT id, alert_state FROM %n WHERE id = %i',
            self::ALERTS_TABLE, $id,
        );
        if ($alert === null) {
            return Response::error('NOT_FOUND', 'Alert not found', 404);
        }
        if ((int) $alert['alert_state'] !== AlertReconciler::STATE_SNOOZED) {
            return Response::error(
                'INVALID_STATE',
                'Only Snoozed alerts can be unsnoozed',
                409,
            );
        }

        $this->db->updateWhere(
            self::ALERTS_TABLE,
            [
                'alert_state'   => AlertReconciler::STATE_ACTIVE,
                'snoozed_until' => null,
            ],
            '%n = %i', 'id', $id,
        );

        return Response::success([
            'id'         => $id,
            'alertState' => AlertReconciler::STATE_ACTIVE,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Decode JSON sloupce + zjednoduš na key conventions vhodné pro frontend. */
    /**
     * D13 (docs/ds-setup.md): alerty checků s `tags: ["setup"]` nejde
     * snoozovat ani dismissovat — položka checklistu zmizí sama doplněním
     * nastavení, odklikat ji je proti D3 (stav se dopočítává, nepamatuje).
     * Chybějící definice v registry → fail-open (nezakazovat).
     */
    private function isSetupAlert(string $checkId): bool
    {
        $def = $this->registry->get($checkId);
        return $def !== null && in_array('setup', $def->tags, true);
    }

    private function setupAlertError(): Response
    {
        return Response::error(
            'SETUP_ALERT',
            'Setup alerts cannot be snoozed or dismissed'
            . ' — the item disappears once the setting is filled in',
            409,
        );
    }

    private function decorateAlert(array $row): array
    {
        $actions = null;
        if (!empty($row['actions']) && is_string($row['actions'])) {
            $decoded = json_decode($row['actions'], true);
            $actions = is_array($decoded) ? $decoded : null;
        }
        return [
            'id'            => (int) $row['id'],
            'findingKey'    => (string) ($row['finding_key'] ?? ''),
            'title'         => (string) ($row['title'] ?? ''),
            'message'       => $row['message'] ?? null,
            'severity'      => isset($row['severity']) ? (int) $row['severity'] : null,
            'alertState'    => isset($row['alert_state']) ? (int) $row['alert_state'] : null,
            'snoozedUntil'  => $row['snoozed_until'] ?? null,
            'firstSeenAt'   => $row['first_seen_at'] ?? null,
            'lastSeenAt'    => $row['last_seen_at'] ?? null,
            'seenCount'     => isset($row['seen_count']) ? (int) $row['seen_count'] : null,
            'actions'       => $actions,
        ];
    }

    /**
     * Body může mít jeden z:
     *   {"duration": "PT1H"}      (ISO 8601 duration)
     *   {"duration": "1h"}        (shipard zjednodušený suffix — viz IntervalParser)
     *   {"minutes": 30}
     *   {"hours": 1}
     *   {"days": 7}
     */
    private static function parseDurationSeconds(array $body): int
    {
        $seconds = null;
        if (isset($body['duration']) && is_string($body['duration']) && $body['duration'] !== '') {
            $seconds = self::parseDurationString($body['duration']);
        } elseif (isset($body['minutes']) && is_numeric($body['minutes'])) {
            $seconds = (int) ((float) $body['minutes'] * 60);
        } elseif (isset($body['hours']) && is_numeric($body['hours'])) {
            $seconds = (int) ((float) $body['hours'] * 3600);
        } elseif (isset($body['days']) && is_numeric($body['days'])) {
            $seconds = (int) ((float) $body['days'] * 86400);
        }

        if ($seconds === null) {
            throw new \InvalidArgumentException(
                'Missing snooze duration — provide one of: duration (PT1H or 1h), minutes, hours, days',
            );
        }
        if ($seconds < self::SNOOZE_MIN_SECONDS) {
            throw new \InvalidArgumentException(
                'Snooze duration too short — minimum is 5 minutes',
            );
        }
        if ($seconds > self::SNOOZE_MAX_SECONDS) {
            throw new \InvalidArgumentException(
                'Snooze duration too long — maximum is 365 days',
            );
        }
        return $seconds;
    }

    /**
     * Akceptuje ISO 8601 duration (`PT1H`, `P7D`, `PT30M`) i shipard suffix
     * format (`1h`, `30m`, `7d`).
     */
    private static function parseDurationString(string $raw): int
    {
        // Shipard suffix format má prioritu — je jednodušší.
        if (preg_match('/^(\d+)([smhd])$/', $raw)) {
            return \Shipard\Core\Alerts\IntervalParser::parse($raw);
        }

        try {
            $interval = new \DateInterval($raw);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                "Invalid duration: '{$raw}'. Expected ISO 8601 (PT1H, P7D) or simple suffix (1h, 7d)",
            );
        }

        $now = new \DateTimeImmutable('@0');
        $end = $now->add($interval);
        return $end->getTimestamp();
    }
}
