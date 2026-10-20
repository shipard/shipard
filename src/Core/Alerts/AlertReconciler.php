<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Sjednocuje výsledek `$check->run()` s existujícími alerty v
 * `core_alerts_alerts` a aktualizuje `core_alerts_check_states`.
 *
 * Reconciler je jediná cesta, jak se alerty dostávají do `core_alerts_alerts`
 * (uživatelské `snooze/dismiss/unsnooze` přes API NE-prochází přes reconciler
 * — píší si přímo). Reconciler **nikdy** nemaže řádky; resolved/dismissed
 * alerty zůstávají v tabulce pro audit, dokud je nepokosí `alerts-prune`.
 *
 * Spec: `tasks/alerts-01.md` §7.5.
 */
final class AlertReconciler
{
    public const ALERTS_TABLE       = 'core_alerts_alerts';
    public const CHECK_STATES_TABLE = 'core_alerts_check_states';

    public const STATE_ACTIVE    = 10;
    public const STATE_SNOOZED   = 20;
    public const STATE_RESOLVED  = 70;
    public const STATE_DISMISSED = 80;

    /** Po jak dlouho považujeme `is_running=1` lock za odumřelý a převezmeme ho. */
    public const STALE_LOCK_SECONDS = 300;   // 5 minut

    /** Mapping severity string (z AlertFinding / AlertCheckDefinition) na DB enumInt. */
    private const SEVERITY_TO_INT = [
        'info'    => 10,
        'warning' => 20,
        'error'   => 30,
    ];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ?DataSourceConfig $dsConfig = null,
    ) {}

    /**
     * Vrátí seznam check_id, které je čas spustit, seřazeno nejstarší napřed.
     * Zahrnuje i checky, které ještě nikdy neběžely (row v check_states chybí
     * → registry je popisuje, ale tabulka je nezná).
     *
     * @return string[]
     */
    public function getDueCheckIds(?\DateTimeImmutable $now = null): array
    {
        $now    ??= new \DateTimeImmutable();
        $nowTs    = $now->getTimestamp();
        $enabled  = $this->registry->getEnabled();
        if ($enabled === []) {
            return [];
        }

        $ids = array_map(static fn (AlertCheckDefinition $d) => $d->id, $enabled);

        $rows = $this->db->fetchAll(
            'SELECT %n, %n, %n FROM %n WHERE %n IN %in',
            'check_id', 'enabled', 'next_run_at',
            self::CHECK_STATES_TABLE,
            'check_id', $ids,
        );

        $stateByCheck = [];
        foreach ($rows as $row) {
            $stateByCheck[(string) $row['check_id']] = $row;
        }

        $due = [];
        foreach ($enabled as $def) {
            $state = $stateByCheck[$def->id] ?? null;
            if ($state === null) {
                // Ještě nikdy neběžel — je due.
                $due[$def->id] = 0;
                continue;
            }
            if ((int) $state['enabled'] === 0) {
                // Manuální vypnutí v check_states (mimo JSONC) — přeskočit.
                continue;
            }
            $nextRunAt = $state['next_run_at'] ?? null;
            if ($nextRunAt === null) {
                $due[$def->id] = 0;
                continue;
            }
            // DataSourceConnection normalizuje datetime na ISO 8601 (s 'T'),
            // takže string compare s `H:i:s` formátem by srovnával "T" > " ".
            // Bezpečné je převést na timestamp.
            $nextTs = strtotime((string) $nextRunAt);
            if ($nextTs !== false && $nextTs <= $nowTs) {
                $due[$def->id] = $nextTs;
            }
        }

        // Seřadit od nejstarších (0 = nikdy neběželo → na začátek).
        asort($due);
        return array_keys($due);
    }

    /**
     * Spustí jeden check a sjednotí jeho výsledek s DB.
     *
     * - Pokud check_id v registry chybí → `status=error`, žádný zápis do DB.
     * - Pokud je v registry `enabled=false` → `status=skipped`, žádný zápis.
     * - Pokud běží paralelně jiný proces a lock je čerstvý → `status=skipped`.
     * - Pokud check hodí výjimku → `status=error`, zápis do check_states, ale
     *   existující alerty se NEresolvují.
     * - Jinak: sjednocení findings s alerts (INSERT/UPDATE/auto-resolve) +
     *   update check_states; status `ok` nebo `found` podle počtu findings.
     */
    public function runCheck(string $checkId, ?\DateTimeImmutable $now = null): AlertRunResult
    {
        $now    ??= new \DateTimeImmutable();
        $nowStr   = $now->format('Y-m-d H:i:s');
        $startMs  = (int) round(microtime(true) * 1000);

        $def = $this->registry->get($checkId);
        if ($def === null) {
            return new AlertRunResult(
                checkId: $checkId,
                status: AlertRunResult::STATUS_ERROR,
                errorMessage: "Check '{$checkId}' is not registered",
            );
        }

        // Zajistit, že existuje řádek v check_states; vrátí aktuální data.
        $state = $this->ensureCheckStateRow($def->id, $nowStr);

        if (!$def->enabled) {
            return new AlertRunResult(
                checkId: $checkId,
                status: AlertRunResult::STATUS_SKIPPED,
                skippedReason: 'check is disabled in module.jsonc',
            );
        }
        if (isset($state['enabled']) && (int) $state['enabled'] === 0) {
            return new AlertRunResult(
                checkId: $checkId,
                status: AlertRunResult::STATUS_SKIPPED,
                skippedReason: 'check is disabled in check_states row',
            );
        }

        // Lock — pokud někdo právě běží a lock je čerstvý, přeskočit.
        // Pokud je lock starší než STALE_LOCK_SECONDS, převzít a varovat.
        if ((int) ($state['is_running'] ?? 0) === 1) {
            $since = $state['running_since'] ?? null;
            $age   = $since !== null ? max(0, $now->getTimestamp() - strtotime((string) $since)) : PHP_INT_MAX;
            if ($age < self::STALE_LOCK_SECONDS) {
                return new AlertRunResult(
                    checkId: $checkId,
                    status: AlertRunResult::STATUS_SKIPPED,
                    skippedReason: "another process holds lock (age {$age}s)",
                );
            }
            ErrorLogger::warn(
                'AlertReconciler: stale lock overridden',
                ['checkId' => $checkId, 'ageSeconds' => $age, 'sinceRaw' => $since],
            );
        }

        // Vzít lock.
        $this->db->updateWhere(
            self::CHECK_STATES_TABLE,
            ['is_running' => 1, 'running_since' => $nowStr],
            '%n = %s', 'check_id', $checkId,
        );

        try {
            $checkInstance = $this->instantiateCheck($def->class);
            $findings      = $checkInstance->run();
            $findings      = $this->validateFindings($checkId, $findings);

            $merged = $this->mergeFindings($checkId, $findings, $now, $nowStr);

            $durationMs = (int) round(microtime(true) * 1000) - $startMs;
            $status     = count($findings) > 0 ? AlertRunResult::STATUS_FOUND : AlertRunResult::STATUS_OK;

            $this->finishRun(
                checkId: $checkId,
                nowStr: $nowStr,
                nextRunAt: $now->modify("+{$def->intervalSeconds} seconds")->format('Y-m-d H:i:s'),
                durationMs: $durationMs,
                status: $status,
                findingsCount: count($findings),
                errorMessage: null,
            );

            return new AlertRunResult(
                checkId: $checkId,
                status: $status,
                findingsCount: count($findings),
                newCount: $merged['new'],
                updatedCount: $merged['updated'],
                resolvedCount: $merged['resolved'],
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;
            $this->finishRun(
                checkId: $checkId,
                nowStr: $nowStr,
                nextRunAt: $now->modify("+{$def->intervalSeconds} seconds")->format('Y-m-d H:i:s'),
                durationMs: $durationMs,
                status: AlertRunResult::STATUS_ERROR,
                findingsCount: 0,
                errorMessage: $e->getMessage(),
            );

            ErrorLogger::logException($e, "AlertReconciler: check '{$checkId}' failed");

            return new AlertRunResult(
                checkId: $checkId,
                status: AlertRunResult::STATUS_ERROR,
                durationMs: $durationMs,
                errorMessage: $e->getMessage(),
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function instantiateCheck(string $fqcn): AlertCheck
    {
        if (!class_exists($fqcn)) {
            throw new \RuntimeException("AlertCheck class not found: {$fqcn}");
        }
        $instance = new $fqcn($this->db, $this->config, $this->language, $this->dsConfig);
        if (!$instance instanceof AlertCheck) {
            throw new \RuntimeException("Class {$fqcn} does not extend AlertCheck");
        }
        return $instance;
    }

    /**
     * @param mixed[] $findings
     * @return AlertFinding[]
     */
    private function validateFindings(string $checkId, array $findings): array
    {
        $out = [];
        $seenKeys = [];
        foreach ($findings as $i => $f) {
            if (!$f instanceof AlertFinding) {
                throw new \RuntimeException(
                    "Check '{$checkId}' returned a non-AlertFinding entry at index {$i}",
                );
            }
            if (isset($seenKeys[$f->findingKey])) {
                throw new \RuntimeException(
                    "Check '{$checkId}' returned duplicate findingKey '{$f->findingKey}'",
                );
            }
            $seenKeys[$f->findingKey] = true;
            $out[] = $f;
        }
        return $out;
    }

    /**
     * @param AlertFinding[] $findings
     * @return array{new:int, updated:int, resolved:int}
     */
    private function mergeFindings(string $checkId, array $findings, \DateTimeImmutable $now, string $nowStr): array
    {
        $existing = $this->db->fetchAll(
            'SELECT id, finding_key, alert_state, snoozed_until, seen_count FROM %n
             WHERE check_id = %s AND alert_state IN %in',
            self::ALERTS_TABLE,
            $checkId,
            [self::STATE_ACTIVE, self::STATE_SNOOZED],
        );

        $existingByKey = [];
        foreach ($existing as $row) {
            $existingByKey[(string) $row['finding_key']] = $row;
        }

        $newCount = $updatedCount = $resolvedCount = 0;

        $this->db->begin();
        try {
            $seenKeys = [];
            foreach ($findings as $f) {
                $seenKeys[$f->findingKey] = true;
                $existingRow = $existingByKey[$f->findingKey] ?? null;

                $payload = [
                    'title'            => $f->title,
                    'message'          => $f->message !== '' ? $f->message : null,
                    'severity'         => self::SEVERITY_TO_INT[$f->severity],
                    'actions'          => $f->actions !== [] ? json_encode($f->actions, JSON_UNESCAPED_UNICODE) : null,
                    'context'          => $f->context !== null ? json_encode($f->context, JSON_UNESCAPED_UNICODE) : null,
                    'subject_table_id' => $f->subjectTableId,
                    'subject_row_id'   => $f->subjectRowId,
                ];

                if ($existingRow === null) {
                    $this->db->insertRow(self::ALERTS_TABLE, $payload + [
                        'check_id'      => $checkId,
                        'finding_key'   => $f->findingKey,
                        'alert_state'   => self::STATE_ACTIVE,
                        'first_seen_at' => $nowStr,
                        'last_seen_at'  => $nowStr,
                        'seen_count'    => 1,
                    ]);
                    $newCount++;
                    continue;
                }

                $payload['last_seen_at'] = $nowStr;
                $payload['seen_count']   = ((int) $existingRow['seen_count']) + 1;

                // Snoozed s vypršenou platností → re-aktivovat.
                // (Datetime sloupce přijdou v ISO 8601 s 'T' separátorem, takže
                //  string compare s mým 'Y-m-d H:i:s' formátem by selhal —
                //  srovnáváme timestamps.)
                if ((int) $existingRow['alert_state'] === self::STATE_SNOOZED) {
                    $snoozedUntil = $existingRow['snoozed_until'] ?? null;
                    if ($snoozedUntil !== null) {
                        $snoozedTs = strtotime((string) $snoozedUntil);
                        if ($snoozedTs !== false && $snoozedTs <= $now->getTimestamp()) {
                            $payload['alert_state']   = self::STATE_ACTIVE;
                            $payload['snoozed_until'] = null;
                        }
                    }
                }

                $this->db->updateWhere(
                    self::ALERTS_TABLE,
                    $payload,
                    '%n = %i', 'id', (int) $existingRow['id'],
                );
                $updatedCount++;
            }

            // Auto-resolve: existující Open alerty, které check už nepotvrdil.
            foreach ($existingByKey as $key => $row) {
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $this->db->updateWhere(
                    self::ALERTS_TABLE,
                    [
                        'alert_state' => self::STATE_RESOLVED,
                        'resolved_at' => $nowStr,
                    ],
                    '%n = %i', 'id', (int) $row['id'],
                );
                $resolvedCount++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['new' => $newCount, 'updated' => $updatedCount, 'resolved' => $resolvedCount];
    }

    /**
     * Zajistí, že v check_states existuje řádek pro checkId, a vrátí jeho data.
     * Insert používá `INSERT IGNORE` na unq_check_id, takže concurrent
     * insert dvou procesů se nepokazí.
     *
     * @return array{check_id:string, enabled:int|bool, is_running:int|bool, running_since:?string, next_run_at:?string}
     */
    private function ensureCheckStateRow(string $checkId, string $nowStr): array
    {
        $row = $this->db->fetchRow(
            'SELECT check_id, enabled, is_running, running_since, next_run_at FROM %n WHERE check_id = %s',
            self::CHECK_STATES_TABLE,
            $checkId,
        );
        if ($row !== null) {
            return $row;
        }

        $this->db->execute(
            'INSERT IGNORE INTO %n (check_id, enabled, is_running, next_run_at) VALUES (%s, %i, %i, NULL)',
            self::CHECK_STATES_TABLE,
            $checkId, 1, 0,
        );

        $row = $this->db->fetchRow(
            'SELECT check_id, enabled, is_running, running_since, next_run_at FROM %n WHERE check_id = %s',
            self::CHECK_STATES_TABLE,
            $checkId,
        );
        if ($row === null) {
            throw new \RuntimeException(
                "AlertReconciler: failed to ensure check_states row for '{$checkId}'",
            );
        }
        return $row;
    }

    private function finishRun(
        string $checkId,
        string $nowStr,
        string $nextRunAt,
        int $durationMs,
        string $status,
        int $findingsCount,
        ?string $errorMessage,
    ): void {
        $this->db->updateWhere(
            self::CHECK_STATES_TABLE,
            [
                'is_running'           => 0,
                'running_since'        => null,
                'last_run_at'          => $nowStr,
                'last_run_status'      => $status,
                'last_run_duration_ms' => $durationMs,
                'last_run_findings'    => $findingsCount,
                'last_run_error'       => $errorMessage,
                'next_run_at'          => $nextRunAt,
            ],
            '%n = %s', 'check_id', $checkId,
        );
    }
}
