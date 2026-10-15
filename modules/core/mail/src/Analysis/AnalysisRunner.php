<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;

/**
 * AI analýza došlé zprávy v procesu (tasks/mail-analysis-inprocess.md
 * D12–D18) — kroky stavového automatu analýzy volané jako služby:
 *
 *   1. slot souběhu (flock, neblokující) — bez slotu konec, zpráva čeká
 *   2. {@see AnalysisQueue::isEligible()} — ne → konec
 *   3. {@see AnalysisClaimService::claim()} 10 → 20, lease; profil + rozhodnutí
 *      o volání (připojení, model, ladění, strop, klíč — AI funkce
 *      `mail.extraction` s přepsáním z profilu, tasks/ai-functions.md D40)
 *   4.–6. {@see AnalysisComputation} (sdílená s `ai-eval`, tasks/testing-phase4.md
 *      D34): přílohy → prompt → model (streamovaně; opakování přechodných
 *      chyb, před každým pokusem prodloužení lease — vypršelá lease = konec
 *      bez zápisu) → parser → tělo v4 + validace canonicalu
 *   7. {@see AnalysisResultWriter} 20 → 30 / 10 / 70 (s předanou validací)
 *
 * Mapování chyb: chyba claimu z konfigurace (`NO_PROFILE`, `NO_BACKEND`,
 * `BACKEND_KEY_*`, `SECRETS_UNAVAILABLE`, `BACKEND_CONFIG_INVALID`) = konec bez zápisu, zpráva
 * zůstává ve frontě, jedno varování; `schema_error` bez opakování;
 * chyba nastavení (klíč, oprávnění, neznámý model, vyčerpaný strop
 * útraty — `LlmApiException::isConfigurationError()`) = `config_error`
 * bez opakování; jiná chyba API = `ai_error`, opakovatelná podle `isTransient()` a stropu
 * tří selhaných běhů za hodinu; neúplný výsledek
 * (`!LlmChatResult::isComplete()` — `max_tokens`, `refusal`,
 * `model_context_window_exceeded`, …) = `ai_error` bez opakování
 * (tasks/ai-models-phase0.md F0-D4); neplatný claim při zápisu = jen
 * varování včetně ceny;
 * nečekaná výjimka po claimu (DB, disk, chyba kódu) = `ai_error`
 * „internal: <třída>: <text>“ se stejným stropem jako přechodné chyby
 * (tasks/mail-analysis-queue-drain.md D26) — selže-li i zápis selhání,
 * claim se nechá vypršet a reaper zprávu vrátí do fronty (při třetím
 * vypršení za hodinu ji sám přepne na stav 70). Běh v procesu zapisuje
 * `created_by = NULL` (strojový kontext).
 *
 * `drain()` (CLI `--message`, tasks/mail-analysis-queue-drain.md D25):
 * po dokončení zprávy s jakýmkoli výsledkem drží slot a bere další zprávu
 * z fronty, nejstarší první — každou nejvýš jednou za proces (zpráva
 * vrácená do fronty se v témže procesu znovu nebere), `not_configured`
 * dobírání ukončí, po rozpočtu {@see DRAIN_BUDGET_SECONDS} od startu
 * novou zprávu nezačne: uvolní slot a pro první zbývající spustí nástupce
 * (jen když sám aspoň jednu zprávu dokončil — `done` / `failed`).
 *
 * `sweep()` (minutový cron za reaperem): pro zprávy ve frontě bez
 * aktivního claimu spustí runner — nejvýš tolik, kolik je volných slotů;
 * každý pak dobírá.
 */
class AnalysisRunner
{
    public const LEASE_SECONDS = 900;
    public const STALL_TIMEOUT_SECONDS = AnalysisComputation::STALL_TIMEOUT_SECONDS;
    public const CALL_TIMEOUT_SECONDS = AnalysisComputation::CALL_TIMEOUT_SECONDS;
    /** Pauzy mezi pokusy při přechodné chybě (celkem tři pokusy). */
    public const RETRY_DELAYS = AnalysisComputation::RETRY_DELAYS;
    /** Přechodná chyba vrací zprávu do fronty nejvýš tolikrát za hodinu. */
    public const MAX_FAILURES_PER_HOUR = 3;
    public const FAILURE_WINDOW_SECONDS = 3600;
    public const ERROR_MESSAGE_MAX_LENGTH = 2000;
    /** Dobírání fronty: po tolika sekundách od startu procesu runner novou zprávu nezačne (D25). */
    public const DRAIN_BUDGET_SECONDS = 600;

    private const MESSAGES_TABLE = 'core_mail_incoming_messages';

    /**
     * @param \Closure(int): void|null $spawn Spuštění runneru pro zprávu
     *        (sweep); null = sweep jen vypíše, co by spustil.
     * @param \Closure(int): void|null $sleep Pauza mezi pokusy (testy).
     * @param string|null $analyzerId `analyzer_id` claimu; null =
     *        `internal:<hostname>:<pid>`.
     * @param \Closure(): float|null $clock Čas v sekundách pro rozpočet
     *        dobírání (testy); null = `microtime(true)`.
     * @param ModelCatalog|null $catalog Ceník pro `cost_usd` (#85 D33,
     *        přes `ResolvedAiCall::costUsd()` s násobitelem platformy D60);
     *        null = prázdný katalog, cena 0 s varováním.
     * @param AnalysisBackendProbe|null $probe Sonda použitelného připojení
     *        pro sweep; null = sonda bez serverového připojení pro vývoj
     *        (factory ji staví s konfigurací zdroje dat, D40).
     * @param AnalysisComputation|null $computation Výpočet analýzy; null =
     *        složit z příloh, promptu, parseru, klienta, katalogu a validátoru
     *        (`services->results`).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AnalysisServices $services,
        AttachmentPreparer $attachments,
        PromptRenderer $prompts,
        OutputParser $parser,
        LlmClient $llm,
        private readonly AnalysisSlots $slots,
        private readonly ?\Closure $spawn = null,
        ?\Closure $sleep = null,
        private readonly ?string $analyzerId = null,
        private readonly ?\Closure $clock = null,
        ?ModelCatalog $catalog = null,
        private readonly ?AnalysisBackendProbe $probe = null,
        ?AnalysisComputation $computation = null,
    ) {
        $this->computation = $computation ?? new AnalysisComputation($attachments, $prompts, $parser, $llm, $services->results, $catalog, $sleep);
    }

    private readonly AnalysisComputation $computation;

    /**
     * @return array{
     *     status: 'disabled'|'no_slot'|'not_eligible'|'not_configured'|'claim_failed'|'done'|'failed'|'lost_claim'|'crashed',
     *     message: int,
     *     note?: string,
     *     analysisNdx?: int,
     *     hasDocument?: bool,
     *     errorType?: string,
     *     retryable?: bool,
     *     newState?: int
     * }
     */
    public function run(int $messageId): array
    {
        $slot = $this->acquireSlot($messageId, $refusal);
        if ($slot === null) {
            return $refusal;
        }

        try {
            return $this->runOne($messageId);
        } finally {
            $slot->release();
        }
    }

    /**
     * Dobírání fronty (D25): první zpráva, pak další ze fronty, dokud nějaká
     * je — viz docblock třídy. `results` = výsledky {@see run()} v pořadí
     * zpracování; `reason` = proč dobírání skončilo; `successor` = zpráva,
     * pro kterou po vyčerpání rozpočtu běží nástupce (null = žádný).
     *
     * @return array{
     *     reason: 'disabled'|'no_slot'|'queue_empty'|'not_configured'|'budget',
     *     results: list<array<string, mixed>>,
     *     successor: int|null
     * }
     */
    public function drain(int $firstMessageId): array
    {
        $slot = $this->acquireSlot($firstMessageId, $refusal);
        if ($slot === null) {
            return ['reason' => $refusal['status'], 'results' => [$refusal], 'successor' => null];
        }

        $startedAt = $this->now();
        $results = [];
        $processed = [];
        $progress = false;
        $successor = null;
        $messageId = $firstMessageId;

        try {
            while (true) {
                $result = $this->runOne($messageId);
                $results[] = $result;
                $processed[] = $messageId;
                $progress = $progress || in_array($result['status'], ['done', 'failed'], true);
                // Přílohy a tělo požadavku jsou lokální v analyze(); cykly uvolnit hned.
                gc_collect_cycles();

                if ($result['status'] === 'not_configured') {
                    // Chybí profil, backend nebo klíč — další zprávy by dopadly stejně.
                    $reason = 'not_configured';
                    break;
                }
                $next = $this->nextQueued($processed);
                if ($next === null) {
                    $reason = 'queue_empty';
                    break;
                }
                if ($this->now() - $startedAt >= self::DRAIN_BUDGET_SECONDS) {
                    $reason = 'budget';
                    $successor = $progress ? $next : null;
                    break;
                }
                $messageId = $next;
            }
        } finally {
            $slot->release();
        }

        if ($successor !== null && $this->spawn !== null) {
            // Až po uvolnění slotu — jinak nástupce skončí na „no free slot“.
            ($this->spawn)($successor);
        }

        return ['reason' => $reason, 'results' => $results, 'successor' => $successor];
    }

    /**
     * Slot pro běh; bez něj vrací přes `$refusal` výsledek `disabled` /
     * `no_slot` ve tvaru {@see run()}.
     *
     * @param array<string, mixed>|null $refusal
     */
    private function acquireSlot(int $messageId, ?array &$refusal): ?AnalysisSlot
    {
        $refusal = null;
        if ($this->slots->isDisabled()) {
            $refusal = ['status' => 'disabled', 'message' => $messageId, 'note' => 'in-process analysis is disabled (ai.analysis.maxConcurrent = 0)'];
            return null;
        }
        $slot = $this->slots->tryAcquire();
        if ($slot === null) {
            $refusal = ['status' => 'no_slot', 'message' => $messageId, 'note' => 'no free analysis slot — message stays queued for the sweep'];
        }
        return $slot;
    }

    /**
     * Jedna zpráva v drženém slotu; nečekaná chyba před claimem (gate,
     * claim) nebo při zápisu selhání končí jako `crashed` — claim, pokud
     * vznikl, se nechá vypršet a reaper zprávu vrátí do fronty.
     *
     * @return array<string, mixed>
     */
    private function runOne(int $messageId): array
    {
        try {
            return $this->runInSlot($messageId);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, "AnalysisRunner: message {$messageId} crashed — claim left to expire");
            return ['status' => 'crashed', 'message' => $messageId, 'note' => get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * Nejstarší zpráva ve frontě mimo už zpracované; id, které fronta vrátí
     * podruhé, se bere jako prázdná fronta (pojistka proti točení na místě).
     *
     * @param list<int> $processed
     */
    private function nextQueued(array $processed): ?int
    {
        $rows = $this->services->queue->eligible(1, null, $processed);
        if ($rows === []) {
            return null;
        }
        $next = (int) $rows[0]['ndx'];
        if (in_array($next, $processed, true)) {
            ErrorLogger::warn('AnalysisRunner: queue returned an already processed message — drain stops', ['message' => $next]);
            return null;
        }
        return $next;
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    /** @return array<string, mixed> */
    private function runInSlot(int $messageId): array
    {
        if (!$this->services->queue->isEligible($messageId)) {
            return ['status' => 'not_eligible', 'message' => $messageId, 'note' => 'message is not queued for analysis (state, workflow, preprocessing gate, flags or an active claim)'];
        }

        try {
            $claim = $this->services->claims->claim($messageId, $this->analyzerId(), self::LEASE_SECONDS);
        } catch (AnalysisClaimException $e) {
            if ($e->isConfigurationError()) {
                ErrorLogger::warn('AnalysisRunner: AI analysis is not configured — message stays queued', [
                    'message' => $messageId,
                    'code' => $e->errorCode,
                    'error' => $e->getMessage(),
                ]);
                return ['status' => 'not_configured', 'message' => $messageId, 'note' => $e->errorCode . ': ' . $e->getMessage()];
            }
            return ['status' => 'claim_failed', 'message' => $messageId, 'note' => $e->errorCode . ': ' . $e->getMessage()];
        }

        try {
            return $this->analyze($messageId, $claim);
        } catch (\Throwable $e) {
            return $this->failInternal($messageId, $claim, $e);
        }
    }

    /**
     * Nečekaná výjimka po claimu (D26): selhaný běh `ai_error` „internal:
     * <třída>: <text>“ — do fronty nejvýš {@see MAX_FAILURES_PER_HOUR}krát
     * za hodinu, pak stav 70 (stejné okno jako u přechodných chyb modelu).
     * Když selže i zápis selhání, claim se nechá vypršet (dnešní chování).
     *
     * @return array<string, mixed>
     */
    private function failInternal(int $messageId, AnalysisClaim $claim, \Throwable $e): array
    {
        $note = 'internal: ' . get_class($e) . ': ' . $e->getMessage();
        ErrorLogger::logException($e, "AnalysisRunner: message {$messageId} — unexpected error after claim, stored as a failed run");
        try {
            $retryable = $this->services->results->countRecentFailures($messageId, self::FAILURE_WINDOW_SECONDS) < self::MAX_FAILURES_PER_HOUR;
            return $this->fail($messageId, $claim, 'ai_error', $note, $retryable, null, null);
        } catch (\Throwable $storeError) {
            ErrorLogger::logException($storeError, "AnalysisRunner: message {$messageId} crashed and the failure could not be stored — claim left to expire");
            return ['status' => 'crashed', 'message' => $messageId, 'note' => get_class($e) . ': ' . $e->getMessage()];
        }
    }

    /**
     * Výpočet přes {@see AnalysisComputation} (hook prodlužuje lease —
     * zaniklý claim = konec bez zápisu), pak zápis: selhání se stropem
     * opakování pro přechodné chyby, úspěch `storeResult` s předanou
     * validací canonicalu (neopakuje LLM klasifikaci štítků).
     *
     * @return array<string, mixed>
     */
    private function analyze(int $messageId, AnalysisClaim $claim): array
    {
        $request = new AnalysisRequest(
            messageId: $messageId,
            message: $this->loadMessage($messageId),
            profile: $claim->profile,
            call: $claim->call,
            beforeAttempt: function (int $attempt) use ($claim): void {
                if (!$this->services->claims->extend($claim->claimId, self::LEASE_SECONDS)) {
                    throw new AnalysisClaimLostException("claim {$claim->claimId} is no longer active (attempt {$attempt})");
                }
            },
        );

        try {
            $outcome = $this->computation->compute($request);
        } catch (AnalysisClaimLostException $e) {
            return $this->lostClaim($messageId, $claim, $e->getMessage(), null);
        }

        if (!$outcome->ok) {
            $retryable = $outcome->transient
                && $this->services->results->countRecentFailures($messageId, self::FAILURE_WINDOW_SECONDS) < self::MAX_FAILURES_PER_HOUR;
            return $this->fail($messageId, $claim, (string) $outcome->errorType, (string) $outcome->note, $retryable, $outcome->tokensIn, $outcome->costUsd);
        }

        if (!$this->services->claims->isActive($claim->claimId)) {
            return $this->lostClaim($messageId, $claim, 'before storing the result', $outcome->costUsd);
        }
        try {
            $analysisNdx = $this->services->results->storeResult($messageId, $claim->claimId, (array) $outcome->body, null, $outcome->validated);
        } catch (AnalysisResultException $e) {
            if ($e->errorCode !== AnalysisResultException::VALIDATION_ERROR) {
                throw $e;
            }
            // Writer tělo odmítl (tvar kontraktu) — bez explicitního selhání
            // by claim jen vypršel a zpráva se točila dokola s cenou modelu.
            return $this->fail($messageId, $claim, 'config_error', 'result rejected (' . $e->errorCode . '): ' . $e->getMessage(), false, $outcome->tokensIn, $outcome->costUsd);
        }

        return [
            'status' => 'done',
            'message' => $messageId,
            'analysisNdx' => $analysisNdx,
            'hasDocument' => $outcome->hasDocument(),
            'note' => sprintf('tokens %d/%d, %d ms, %.4f USD', (int) $outcome->tokensIn, (int) $outcome->tokensOut, (int) $outcome->durationMs, (float) $outcome->costUsd),
        ];
    }

    /**
     * Sweep (D14): zprávy ve frontě bez aktivního claimu → spawn runneru,
     * nejvýš tolik, kolik je volných slotů. Bez použitelného backendu
     * (aktivní výchozí profil → aktivní backend s klíčem) nedělá nic —
     * jedno varování za sweep.
     *
     * @return array{spawned: list<int>, skipped: string|null}
     */
    public function sweep(): array
    {
        if ($this->slots->isDisabled()) {
            return ['spawned' => [], 'skipped' => 'in-process analysis is disabled (ai.analysis.maxConcurrent = 0)'];
        }
        if (!($this->probe ?? new AnalysisBackendProbe($this->db))->hasUsableBackend()) {
            ErrorLogger::warn('AnalysisRunner sweep: no usable AI backend (active default profile with an active backend holding an API key) — queue left as is');
            return ['spawned' => [], 'skipped' => 'no usable AI backend'];
        }
        $free = $this->slots->freeCount();
        if ($free <= 0) {
            return ['spawned' => [], 'skipped' => 'no free analysis slot'];
        }

        $spawned = [];
        foreach ($this->services->queue->eligible($free) as $row) {
            $messageId = (int) $row['ndx'];
            if ($this->spawn !== null) {
                ($this->spawn)($messageId);
            }
            $spawned[] = $messageId;
        }

        return ['spawned' => $spawned, 'skipped' => null];
    }

    // ── pomocníci ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function fail(int $messageId, AnalysisClaim $claim, string $errorType, string $note, bool $retryable, ?int $tokensUsed, ?float $cost): array
    {
        $note = mb_substr($note, 0, self::ERROR_MESSAGE_MAX_LENGTH);
        if (!$this->services->claims->isActive($claim->claimId)) {
            return $this->lostClaim($messageId, $claim, "before storing the failure [{$errorType}] {$note}", $cost);
        }
        $newState = $this->services->results->storeFailure(
            $messageId,
            $claim->claimId,
            $errorType,
            $note,
            $retryable,
            $tokensUsed,
            $claim->model(),
            (string) ($claim->profile['prompt_version'] ?? ''),
            null,
        );

        return [
            'status' => 'failed',
            'message' => $messageId,
            'errorType' => $errorType,
            'retryable' => $retryable,
            'newState' => $newState,
            'note' => $note,
        ];
    }

    /** @return array<string, mixed> */
    private function lostClaim(int $messageId, AnalysisClaim $claim, string $where, ?float $cost): array
    {
        ErrorLogger::warn('AnalysisRunner: claim expired during the run — nothing stored', [
            'message' => $messageId,
            'claim' => $claim->claimId,
            'where' => $where,
            'costUsd' => $cost,
        ]);
        return ['status' => 'lost_claim', 'message' => $messageId, 'note' => $where];
    }

    /** @return array<string, mixed> */
    private function loadMessage(int $messageId): array
    {
        $message = $this->db->fetchRow(
            'SELECT subject, sender_email, sender_name, body_plain, body_html, received_at, raw_source_attachment
               FROM %n WHERE id = %i',
            self::MESSAGES_TABLE,
            $messageId,
        );
        if ($message === null) {
            throw new \RuntimeException("message {$messageId} vanished after claim");
        }
        return $message;
    }

    private function analyzerId(): string
    {
        return $this->analyzerId ?? ('internal:' . (gethostname() ?: 'unknown') . ':' . getmypid());
    }
}
