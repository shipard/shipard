<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Preprocess;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Analysis\AnalysisQueue;
use Shipard\Module\Core\Mail\IsdocImportService;

/**
 * Runner technického předzpracování došlé zprávy (tasks/mail-preprocess.md
 * §5). Volá ho CLI `shpd-ds mail-preprocess`:
 *
 *   --message <id>   claim (10 → 20 atomickým UPDATE), vykonání **uloženého
 *                    plánu** z preprocess_log (D12), ISDOC import nad všemi
 *                    obsahovými přílohami (D10 — intake větev byla
 *                    přeskočena), stav 30 / 40 při dílčím selhání.
 *                    Zpráva odložená jen kvůli ISDOC (#81 D1, log
 *                    `trigger: 'isdoc'`, {@see isdocOnlyLog()}) má plán
 *                    prázdný záměrně — není to chyba, běží jen import
 *                    (s obsahovou eskalací, LLM mimo HTTP request).
 *   --force          re-match dle aktuálních pravidel, smazání dříve
 *                    vygenerovaných příloh dle provenance, přegenerování;
 *                    funguje i na stavech 0/30/40.
 *   --sweep          záchrana: zaseknuté 10 (spawn selhal) / 20 (proces
 *                    umřel) → zpět na 10 + spawn; nad MAX_ATTEMPTS → 40.
 *
 * Selhání akce **nikdy neblokuje** — zpráva vždy doteče do stavu 30/40
 * a tím projde gate AI fronty. Neúspěšný záznam v `results` nese vedle
 * `note` i `code` ({@see PreprocessFailureCode}) — z něj
 * PreprocessErrorPresenter skládá hlášku pro uživatele; záznamy mimo
 * akce (prázdný plán, vzdaný sweep, neznámá akce, výjimka) ho dostávají
 * tady (tasks/mail-preprocess-error-messages.md D1).
 */
final class PreprocessRunner
{
    public const STATE_NONE = 0;
    public const STATE_PENDING = 10;
    public const STATE_RUNNING = 20;
    public const STATE_DONE = 30;
    public const STATE_DONE_WITH_ERRORS = 40;

    /** Sweep: po tolika pokusech (běhy + záchrany) zprávu vzdá do stavu 40. */
    public const MAX_ATTEMPTS = 3;
    /** Sweep: stav 10 starší než tolik sekund = spawn selhal. */
    public const STALE_PENDING_SECONDS = 300;
    /** Sweep: stav 20 starší než tolik sekund = proces umřel. */
    public const STALE_RUNNING_SECONDS = 900;

    /**
     * `preprocess_log.trigger` běhu spuštěného jen kvůli ISDOC importu
     * (#81 D1). Chybějící trigger = běh podle pravidel (zpětná kompatibilita).
     */
    public const TRIGGER_ISDOC = 'isdoc';

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const MAIL_TABLE_ID = 303;
    /** analysis_state „Analyzuje se" — aktivní claim analyzeru, --force odmítne. */
    private const ANALYSIS_ANALYZING = 20;

    /**
     * @param \Closure(): IsdocImportService|null $isdocImportFactory Lazy
     *        wiring ISDOC importu; null = ISDOC krok přeskočen (testy).
     * @param \Closure(int): void|null $spawn Respawn runneru při sweepu
     *        (produkčně PreprocessSpawner::spawn); null = jen reset stavu.
     * @param PreprocessRuleMatcher|null $matcher Re-match pro --force.
     * @param \Closure(int): void|null $spawnAnalysis Spawn runneru AI analýzy
     *        po konci běhu, když je zpráva ve frontě (produkčně
     *        AnalysisSpawner::spawn, tasks/mail-analysis-inprocess.md D14);
     *        null = bez spawnu (sweep).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AttachmentService $attachments,
        private readonly ActionRegistry $actions,
        private readonly ?\Closure $isdocImportFactory = null,
        private readonly ?\Closure $spawn = null,
        private readonly ?PreprocessRuleMatcher $matcher = null,
        private readonly ?\Closure $spawnAnalysis = null,
    ) {
    }

    /**
     * @return array{
     *     status: 'done'|'done_with_errors'|'skipped'|'not_found'|'refused'|'no_match'|'lost_race',
     *     message: int,
     *     note?: string,
     *     results?: list<array<string, mixed>>,
     *     isdoc?: string
     * }
     */
    public function run(int $messageId, bool $force = false): array
    {
        $now = date('Y-m-d H:i:s');

        if ($force) {
            $message = $this->fetchMessage($messageId);
            if ($message === null) {
                return ['status' => 'not_found', 'message' => $messageId];
            }
            if ((int) ($message['analysis_state'] ?? 0) === self::ANALYSIS_ANALYZING) {
                return [
                    'status' => 'refused',
                    'message' => $messageId,
                    'note' => 'message has an active AI analysis claim (analysis_state=20) — retry after it finishes',
                ];
            }
            if ((int) ($message['preprocess_state'] ?? 0) === self::STATE_RUNNING) {
                return [
                    'status' => 'refused',
                    'message' => $messageId,
                    'note' => 'runner is active (preprocess_state=20) — use --sweep if the process is dead',
                ];
            }
            if ($this->matcher === null) {
                throw new \LogicException('--force requires a PreprocessRuleMatcher');
            }

            $plan = $this->matcher->match(
                (string) ($message['sender_email'] ?? ''),
                (string) ($message['subject'] ?? ''),
                isset($message['body_html']) ? (string) $message['body_html'] : null,
                isset($message['body_plain']) ? (string) $message['body_plain'] : null,
            );
            if ($plan === null) {
                return ['status' => 'no_match', 'message' => $messageId, 'note' => 'no confirmed rule matches this message'];
            }

            $oldLog = self::decodeLog($message['preprocess_log'] ?? null);
            $log = [
                'plan' => $plan,
                'results' => [],
                'attempts' => (int) ($oldLog['attempts'] ?? 0),
                'createdAt' => $oldLog['createdAt'] ?? date('c'),
                'forcedAt' => date('c'),
                'deletedAttachments' => $this->deleteGeneratedAttachments($messageId),
            ];
            $this->db->updateWhere(
                self::MESSAGES_TABLE,
                ['preprocess_state' => self::STATE_RUNNING, 'preprocess_log' => self::encodeLog($log), 'modified' => $now],
                'id = %i',
                $messageId,
            );
        } else {
            // Claim: prohraný závod / už hotovo = 0 řádků = tichý konec.
            $this->db->execute(
                'UPDATE %n SET preprocess_state = %i, modified = %s WHERE id = %i AND preprocess_state = %i',
                self::MESSAGES_TABLE,
                self::STATE_RUNNING,
                $now,
                $messageId,
                self::STATE_PENDING,
            );
            if ($this->db->getAffectedRows() === 0) {
                return [
                    'status' => 'skipped',
                    'message' => $messageId,
                    'note' => 'message is not pending (already running, done, or claimed by another runner)',
                ];
            }
            $message = $this->fetchMessage($messageId);
            if ($message === null) {
                return ['status' => 'not_found', 'message' => $messageId];
            }
            $log = self::decodeLog($message['preprocess_log'] ?? null);
            $plan = is_array($log['plan'] ?? null) ? $log['plan'] : [];
        }

        $log['attempts'] = (int) ($log['attempts'] ?? 0) + 1;
        $log['startedAt'] = date('c');
        $log['results'] = [];
        $allOk = true;

        foreach ($plan as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ruleId = (string) ($entry['ruleId'] ?? '');
            foreach ((array) ($entry['actions'] ?? []) as $params) {
                $params = is_array($params) ? $params : [];
                $key = trim((string) ($params['action'] ?? ''));
                $result = $this->executeAction($message, $ruleId, $key, $params);

                $entryLog = ['ruleId' => $ruleId, 'action' => $key, 'ok' => $result->ok, 'note' => $result->note];
                if (!$result->ok) {
                    $entryLog['code'] = $result->code;
                }
                if ($result->attachmentIds !== []) {
                    $entryLog['attachmentId'] = $result->attachmentIds[0];
                    if (count($result->attachmentIds) > 1) {
                        $entryLog['attachmentIds'] = $result->attachmentIds;
                    }
                }
                $log['results'][] = $entryLog;
                if (!$result->ok) {
                    $allOk = false;
                }
            }
        }

        if ($log['results'] === [] && ($log['trigger'] ?? null) !== self::TRIGGER_ISDOC) {
            // Bez triggeru je prázdný plán vadný stav (D12 — runner vykonává
            // uložený plán). ISDOC-only běh (#81) plán nemá záměrně.
            $allOk = false;
            $log['results'][] = ['action' => 'plan', 'ok' => false, 'note' => 'stored plan is empty', 'code' => PreprocessFailureCode::INTERNAL];
        }

        // ISDOC nad všemi obsahovými přílohami — původními (intake větev
        // byla přeskočena, D10) i právě vygenerovanými.
        $log['isdoc'] = $this->runIsdocImport($message);

        $state = $allOk ? self::STATE_DONE : self::STATE_DONE_WITH_ERRORS;
        $log['finishedAt'] = date('c');

        $this->db->execute(
            'UPDATE %n SET preprocess_state = %i, preprocess_log = %s, modified = %s WHERE id = %i AND preprocess_state = %i',
            self::MESSAGES_TABLE,
            $state,
            self::encodeLog($log),
            date('Y-m-d H:i:s'),
            $messageId,
            self::STATE_RUNNING,
        );
        if ($this->db->getAffectedRows() === 0) {
            // Sweep mezitím zprávu resetoval (běh trval déle než timeout) —
            // výsledek nepřepisujeme, další běh ho zopakuje idempotentně.
            return ['status' => 'lost_race', 'message' => $messageId, 'results' => $log['results'], 'isdoc' => $log['isdoc']];
        }

        $this->spawnAnalysisIfQueued($messageId);

        return [
            'status' => $allOk ? 'done' : 'done_with_errors',
            'message' => $messageId,
            'results' => $log['results'],
            'isdoc' => $log['isdoc'],
        ];
    }

    /**
     * Rescue sweep (D8): zaseknuté zprávy zpět do fronty runneru + spawn,
     * nad MAX_ATTEMPTS vzdát do stavu 40 (zpráva projde gate AI fronty).
     *
     * @return array{requeued: list<int>, failed: list<int>}
     */
    public function sweep(?int $now = null): array
    {
        $now ??= time();
        $rows = $this->db->fetchAll(
            'SELECT id, preprocess_state, preprocess_log FROM %n'
            . ' WHERE (preprocess_state = %i AND modified < %s) OR (preprocess_state = %i AND modified < %s)'
            . ' ORDER BY id ASC',
            self::MESSAGES_TABLE,
            self::STATE_PENDING,
            date('Y-m-d H:i:s', $now - self::STALE_PENDING_SECONDS),
            self::STATE_RUNNING,
            date('Y-m-d H:i:s', $now - self::STALE_RUNNING_SECONDS),
        );

        $requeued = [];
        $failed = [];
        $stamp = date('Y-m-d H:i:s', $now);

        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) $row['id'];
            $state = (int) $row['preprocess_state'];
            $log = self::decodeLog($row['preprocess_log'] ?? null);
            if (!is_array($log['results'] ?? null)) {
                $log['results'] = [];
            }
            $attempts = (int) ($log['attempts'] ?? 0);

            if ($attempts >= self::MAX_ATTEMPTS) {
                $log['results'][] = [
                    'action' => 'sweep',
                    'ok' => false,
                    'note' => "gave up after {$attempts} attempts (stuck in state {$state})",
                    'code' => PreprocessFailureCode::INTERNAL,
                ];
                $log['finishedAt'] = date('c', $now);
                $this->db->execute(
                    'UPDATE %n SET preprocess_state = %i, preprocess_log = %s, modified = %s WHERE id = %i AND preprocess_state = %i',
                    self::MESSAGES_TABLE,
                    self::STATE_DONE_WITH_ERRORS,
                    self::encodeLog($log),
                    $stamp,
                    $id,
                    $state,
                );
                if ($this->db->getAffectedRows() > 0) {
                    $failed[] = $id;
                }
                continue;
            }

            $log['attempts'] = $attempts + 1;
            $log['sweeps'] = is_array($log['sweeps'] ?? null) ? $log['sweeps'] : [];
            $log['sweeps'][] = ['at' => date('c', $now), 'fromState' => $state];
            $this->db->execute(
                'UPDATE %n SET preprocess_state = %i, preprocess_log = %s, modified = %s WHERE id = %i AND preprocess_state = %i',
                self::MESSAGES_TABLE,
                self::STATE_PENDING,
                self::encodeLog($log),
                $stamp,
                $id,
                $state,
            );
            if ($this->db->getAffectedRows() === 0) {
                continue; // mezitím se pohnula sama
            }
            $requeued[] = $id;
            if ($this->spawn !== null) {
                ($this->spawn)($id);
            }
        }

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    /**
     * Příloha vygenerovaná předzpracováním (provenance D5) — sdílí viewer
     * (označení v detailu) i --force (mazání před přegenerováním).
     *
     * @param array<string, mixed> $file Řádek core_attachments_files.
     */
    public static function isGeneratedAttachment(array $file): bool
    {
        $metadata = $file['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        return is_array($metadata) && (($metadata['generatedBy'] ?? null) === 'preprocess');
    }

    /**
     * Log pro běh spuštěný jen kvůli ISDOC importu (#81 D1): MailController
     * jím po commitu intake / uploadu odloží zprávu s platným ISDOC do
     * runneru (`preprocess_state` 10 + spawn). Prázdný plán je záměr —
     * run() ho u tohoto triggeru nebere jako chybu. Serializace přes
     * encodeLog(), ať kontroler neskládá JSON sám.
     *
     * @return array<string, mixed>
     */
    public static function isdocOnlyLog(): array
    {
        return [
            'plan' => [],
            'trigger' => self::TRIGGER_ISDOC,
            'results' => [],
            'attempts' => 0,
            'createdAt' => date('c'),
        ];
    }

    /** @return array<string, mixed> */
    public static function decodeLog(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) ($raw ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $log */
    public static function encodeLog(array $log): string
    {
        return (string) json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $message */
    private function executeAction(array $message, string $ruleId, string $key, array $params): ActionResult
    {
        $action = $this->actions->get($key);
        if ($action === null) {
            return ActionResult::failure($key === '' ? 'missing action key' : "unknown action '{$key}'", PreprocessFailureCode::RULE_CONFIG);
        }

        try {
            return $action->execute($message, $ruleId, $params);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, "Preprocess action '{$key}' threw — recorded as failed");
            return ActionResult::failure(get_class($e) . ': ' . $e->getMessage(), PreprocessFailureCode::INTERNAL);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return 'imported'|'none'|'skipped'|'failed'
     */
    private function runIsdocImport(array $message): string
    {
        if ($this->isdocImportFactory === null) {
            return 'skipped';
        }

        $messageId = (int) $message['id'];
        $rawId = (int) ($message['raw_source_attachment'] ?? 0);
        $files = [];
        $hasCandidate = false;
        foreach ($this->attachments->listAttachments(self::MAIL_TABLE_ID, $messageId) as $file) {
            $file = (array) $file;
            if ((int) ($file['id'] ?? 0) === $rawId) {
                continue;
            }
            if (IsdocImportService::isPotentialCandidate($file)) {
                $hasCandidate = true;
            }
            $files[] = $file;
        }
        if (!$hasCandidate) {
            return 'none';
        }

        try {
            return ($this->isdocImportFactory)()->tryImport($messageId, $files) ? 'imported' : 'none';
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'PreprocessRunner ISDOC import failed — message stays in AI queue');
            return 'failed';
        }
    }

    /**
     * Po konci běhu (stav 30 / 40) zpráva prošla gate AI fronty — spawn
     * runneru analýzy (tasks/mail-analysis-inprocess.md D14). Stav fronty
     * se čte znovu: ISDOC import mezitím mohl analýzu přepnout na 30
     * (importováno) a schránka ji může mít vypnutou. Selhání nikdy
     * neshodí běh — zprávu dohledá `mail-analyze --sweep`.
     */
    private function spawnAnalysisIfQueued(int $messageId): void
    {
        if ($this->spawnAnalysis === null) {
            return;
        }
        try {
            if (new AnalysisQueue($this->db)->isEligible($messageId)) {
                ($this->spawnAnalysis)($messageId);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'PreprocessRunner analysis spawn failed — sweep will pick the message up');
        }
    }

    /** Soft-delete příloh s provenance předzpracování; vrací počet. */
    private function deleteGeneratedAttachments(int $messageId): int
    {
        $deleted = 0;
        foreach ($this->attachments->listAttachments(self::MAIL_TABLE_ID, $messageId) as $file) {
            $file = (array) $file;
            if (self::isGeneratedAttachment($file) && $this->attachments->softDelete((int) $file['id'])) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @return array<string, mixed>|null */
    private function fetchMessage(int $messageId): ?array
    {
        return $this->db->fetchRow('SELECT * FROM %n WHERE id = %i', self::MESSAGES_TABLE, $messageId);
    }
}
