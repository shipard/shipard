<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\ContributionContext;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;

/**
 * Běh periodické fakturace (docs/work-orders.md D5, Q4; #175 D6).
 *
 * Projde zdroje fakturace (`InvoicingSources`) a pro každý záznam, který
 * zdroj vrátí mezi kandidáty, založí chybějící splatná období (`planned`)
 * v evidenci období zdroje a vystaví všechna `planned` a `waiting`:
 * InvoiceBuilder → DocumentApplier (koncept, Q1/Q7) → zápis dokladu do
 * období. Záznam mimo kandidáty se přeskočí — jeho
 * období dožene první běh, ve kterém mezi kandidáty zase je.
 *
 * Evidence období je per zdroj (D1): repozitář se bere přes `periodsFor()`
 * a id období se nikdy nepoužívá bez zdroje.
 *
 *  - Atomicita: zámek řádku období (`FOR UPDATE`), apply a zápis `doc` v
 *    jedné transakci (NestedTransaction; applier uvnitř použije SAVEPOINT).
 *    Nikdy doklad bez vazby na období. Souběh hlídá UNIQUE klíč a zámek.
 *  - Pojistka dohánění (Q4): víc než MAX_CATCHUP dlužných období jedné
 *    záznamu běh nevystaví (`result = catchup`); vystaví je `force`
 *    (akce Vystavit dlužná období, CLI `--record`).
 *  - Chyba jednoho záznamu / období zastaví jen ji: období zůstane bez
 *    dokladu s `result` a `message`, běh pokračuje.
 *  - `issued` období se nikdy znovu nevystaví — zastavené (doklad v koši)
 *    odzastaví jen Obnovit nebo obnovení dokladu z koše.
 *  - Dry-run nic nezapíše, ani období nezaloží.
 */
class InvoicingRunService
{
    public const MAX_CATCHUP = 3;

    /** @var array<string, PeriodRepository> id zdroje → evidence období */
    private array $periodRepositories = [];

    /**
     * @param ?\Closure(PeriodicInvoicingSource): PeriodRepository $periodsFactory
     *        evidence období zdroje; null = `PeriodRepository::forSource`
     */
    public function __construct(
        protected readonly Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly InvoicingSources $sources,
        protected readonly InvoiceBuilder $builder,
        protected readonly DocumentApplier $applier,
        protected readonly ?InvoiceContributorRegistry $contributors = null,
        private readonly ?\Closure $periodsFactory = null,
    ) {
    }

    public function sources(): InvoicingSources
    {
        return $this->sources;
    }

    /**
     * @throws \InvalidArgumentException neznámé `sourceId`
     */
    public function run(RunOptions $options): RunReport
    {
        $report = new RunReport($options);
        foreach ($this->sourcesOf($options) as $source) {
            foreach ($source->candidates($options->recordId) as $record) {
                $this->runRecord($source, $record, $options, $report);
            }
        }
        return $report;
    }

    /** @return list<PeriodicInvoicingSource> */
    private function sourcesOf(RunOptions $options): array
    {
        if ($options->sourceId === null) {
            return $this->sources->all();
        }
        $source = $this->sources->get($options->sourceId);
        if ($source === null) {
            throw new \InvalidArgumentException("Unknown periodic invoicing source '{$options->sourceId}'");
        }
        return [$source];
    }

    /** Evidence období zdroje (jedna instance per zdroj). */
    public function periodsFor(PeriodicInvoicingSource $source): PeriodRepository
    {
        return $this->periodRepositories[$source->id()] ??= $this->periodsFactory !== null
            ? ($this->periodsFactory)($source)
            : PeriodRepository::forSource($this->db, $source);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function runRecord(PeriodicInvoicingSource $source, array $record, RunOptions $options, RunReport $report): void
    {
        $recordId = (int) $record['id'];
        $periods = $this->periodsFor($source);
        $kind = $source->kind($record);
        $settings = InvoicingSettingsResolver::resolve($record, $kind);
        $periodicity = (string) ($record['inv_periodicity'] ?? '');
        $invoiceFrom = self::isoDate($record['inv_from'] ?? null);
        if (!InvoicingSettings::isPeriodicity($periodicity) || $invoiceFrom === null) {
            $report->add($this->line($source, $record, null, RunReport::OUTCOME_FAILED, message: 'Předpis nemá periodicitu nebo datum „fakturovat od“.'));
            return;
        }

        $due = PeriodCalendar::duePeriods(
            $invoiceFrom,
            self::isoDate($record['date_end'] ?? null),
            $options->date,
            $periodicity,
            $settings->timing,
        );
        if (!$options->dryRun && $due !== []) {
            $periods->ensurePlanned($recordId, $due, $options->timestamp());
        }

        $existing = [];
        foreach ($periods->listFor($recordId) as $row) {
            $existing[(string) self::isoDate($row['period_from'])] = $row;
        }
        // Otevřená období: uložená planned / waiting + (dry-run) splatná,
        // která by běh teprve založil. Od nejstaršího.
        $open = [];
        foreach ($existing as $row) {
            if (in_array((string) $row['state'], [PeriodRepository::STATE_PLANNED, PeriodRepository::STATE_WAITING], true)) {
                $open[(string) self::isoDate($row['period_from'])] = $row;
            }
        }
        if ($options->dryRun) {
            foreach ($due as $period) {
                if (!isset($existing[$period->from])) {
                    $open[$period->from] = ['id' => null, 'period_from' => $period->from, 'period_to' => $period->to, 'state' => PeriodRepository::STATE_PLANNED];
                }
            }
        }
        ksort($open);

        $planned = array_filter($open, static fn(array $row): bool => (string) $row['state'] === PeriodRepository::STATE_PLANNED);
        $blocked = !$options->force && count($planned) > self::MAX_CATCHUP;

        $rows = $source->rows($record);
        foreach ($open as $periodRow) {
            if ($blocked && (string) $periodRow['state'] === PeriodRepository::STATE_PLANNED) {
                $message = sprintf('Pojistka dohánění: %d dlužných období — vystav je akcí Vystavit dlužná období.', count($planned));
                if (!$options->dryRun && $periodRow['id'] !== null) {
                    $periods->markResult((int) $periodRow['id'], PeriodRepository::RESULT_CATCHUP, $message, $options->timestamp());
                }
                $report->add($this->line($source, $record, $periodRow, RunReport::OUTCOME_CATCHUP, message: $message));
                continue;
            }
            $this->issuePeriod($source, $record, $kind, $periodRow, $rows, $options, $report);
        }
    }

    /**
     * Jedno období: v dry-run jen sestavení dokladu, jinak zámek řádku +
     * apply + zápis dokladu v jedné transakci. Výjimka = rollback a zápis
     * výsledku mimo transakci.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $kind
     * @param array<string, mixed> $periodRow
     * @param list<array<string, mixed>> $rows
     */
    private function issuePeriod(PeriodicInvoicingSource $source, array $record, ?array $kind, array $periodRow, array $rows, RunOptions $options, RunReport $report): void
    {
        $periods = $this->periodsFor($source);
        $period = new Period((string) self::isoDate($periodRow['period_from']), (string) self::isoDate($periodRow['period_to']));

        if ($options->dryRun) {
            try {
                $built = $this->builder->build($source, $record, $kind, $period, $rows);
                $report->add($this->line($source, $record, $periodRow, RunReport::OUTCOME_PLANNED, message: $built->canonical['docText'] ?? null));
            } catch (InvoiceBuildException $e) {
                $report->add($this->line($source, $record, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
            }
            return;
        }

        $periodId = (int) $periodRow['id'];
        try {
            $line = NestedTransaction::run($this->db, function () use ($source, $periods, $record, $kind, $period, $periodId, $rows, $options): RunLine {
                $locked = $periods->lockForUpdate($periodId);
                if ($locked === null || !in_array((string) $locked['state'], [PeriodRepository::STATE_PLANNED, PeriodRepository::STATE_WAITING], true)) {
                    return $this->line($source, $record, ['id' => $periodId, 'period_from' => $period->from, 'period_to' => $period->to], RunReport::OUTCOME_SKIPPED, message: 'Období mezitím vystavil jiný běh.');
                }
                if ((string) $locked['state'] === PeriodRepository::STATE_WAITING) {
                    return $this->continueWaiting($source, $record, $kind, $period, $locked, $rows, $options);
                }
                return $this->issueLocked($source, $record, $kind, $period, $locked, $rows, $options);
            });
            $report->add($line);
        } catch (InvoiceBuildException $e) {
            $this->recordFailure($periods, $periodRow, $e->reason, $e->getMessage(), $options);
            $report->add($this->line($source, $record, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
        } catch (\Throwable $e) {
            $this->recordFailure($periods, $periodRow, PeriodRepository::RESULT_FAILED, $e->getMessage(), $options);
            $report->add($this->line($source, $record, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
        }
    }

    // ── Přegenerovat a Obnovit (D24) ────────────────────────────────────────

    /**
     * Přegenerovat (D24, Q2): koncept období se nahradí z aktuálního
     * záznamu přes `applyOptions.replaceConcept` — stejné id, přílohy
     * a autor. Jen období s dokladem v Konceptu; jinak \DomainException.
     * Id období platí jen v evidenci zdroje, proto vždy se zdrojem.
     */
    public function regenerate(PeriodicInvoicingSource $source, int $periodId, RunOptions $options): RunLine
    {
        $periods = $this->periodsFor($source);
        $periodRow = $periods->find($periodId);
        if ($periodRow === null) {
            throw new \DomainException('Období neexistuje.', 404);
        }
        $record = $source->record((int) $periodRow[$periods->recordColumn()]);
        if ($record === null) {
            throw new \DomainException('Záznam období neexistuje.', 404);
        }
        $docInfo = $periods->docInfo([(int) ($periodRow['doc'] ?? 0)]);
        $doc = $docInfo[(int) ($periodRow['doc'] ?? 0)] ?? null;
        if ($doc === null || (int) ($doc['docState'] ?? 0) !== 10
            || !in_array((string) $periodRow['state'], [PeriodRepository::STATE_ISSUED, PeriodRepository::STATE_WAITING], true)
        ) {
            throw new \DomainException('Přegenerovat jde jen období s dokladem v Konceptu.', 409);
        }

        $kind = $source->kind($record);
        $rows = $source->rows($record);
        $period = new Period((string) self::isoDate($periodRow['period_from']), (string) self::isoDate($periodRow['period_to']));

        try {
            return NestedTransaction::run($this->db, function () use ($source, $periods, $record, $kind, $period, $periodId, $rows, $options, $doc): RunLine {
                $locked = $periods->lockForUpdate($periodId);
                if ($locked === null || (int) ($locked['doc'] ?? 0) !== (int) $doc['id']) {
                    throw new \DomainException('Období se mezitím změnilo — načti detail znovu.', 409);
                }
                $built = $this->builder->build($source, $record, $kind, $period, $rows);
                $canonical = $built->canonical;
                $canonical['applyOptions']['replaceConcept'] = (int) $doc['id'];
                $docId = $this->applyCanonical($canonical);
                $hash = $this->contentHashOf($docId);
                $periods->markIssued($periodId, $docId, $hash, $options->timestamp());

                return $this->line($source, $record, $locked, RunReport::OUTCOME_ISSUED, $docId, $built->canonical['docText'] ?? null);
            });
        } catch (InvoiceBuildException $e) {
            throw new \DomainException($e->getMessage(), 422, $e);
        }
    }

    /**
     * Obnovit (D24): zastavené období (doklad v koši nebo chybí) se vrátí
     * do `planned` a hned vystaví během pro tento záznam (bez pojistky
     * dohánění). Vrací řádek obnoveného období z běhu.
     */
    public function restore(PeriodicInvoicingSource $source, int $periodId, RunOptions $options): RunLine
    {
        $periods = $this->periodsFor($source);
        $periodRow = $periods->find($periodId);
        if ($periodRow === null) {
            throw new \DomainException('Období neexistuje.', 404);
        }
        $docInfo = $periods->docInfo([(int) ($periodRow['doc'] ?? 0)]);
        if (!PeriodRepository::isStopped($periodRow, $docInfo)) {
            throw new \DomainException('Obnovit jde jen zastavené období — doklad je smazaný nebo chybí.', 409);
        }
        $periods->unlinkDoc($periodId, $options->timestamp());

        $report = $this->run(new RunOptions(
            date: $options->date,
            sourceId: $source->id(),
            recordId: (int) $periodRow[$periods->recordColumn()],
            dryRun: false,
            force: true,
            now: $options->now,
        ));
        foreach ($report->lines as $line) {
            if ($line->sourceId === $source->id() && $line->periodId === $periodId) {
                return $line;
            }
        }
        throw new \DomainException('Záznam není mezi záznamy k fakturaci, nebo období už není splatné — obnovené období se nevystavilo.', 409);
    }

    /**
     * Vystavení zamčeného období uvnitř transakce: sestavení, apply,
     * zápis dokladu a otisku obsahu. Období `waiting` (D10) se znovu
     * sestaví podle přispěvatelů — rozšíření v další fázi.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $kind
     * @param array<string, mixed> $locked
     * @param list<array<string, mixed>> $rows
     */
    protected function issueLocked(PeriodicInvoicingSource $source, array $record, ?array $kind, Period $period, array $locked, array $rows, RunOptions $options): RunLine
    {
        $periods = $this->periodsFor($source);
        $built = $this->builder->build($source, $record, $kind, $period, $rows);
        [$canonical, $waiting] = $this->contribute($source, $record, $period, $built);
        $docId = $this->applyCanonical($canonical);
        $hash = $this->contentHashOf($docId);
        if ($waiting !== null) {
            // Úroveň Koncept (D10): doklad vznikl hned, řádky přispěvatele
            // s množstvím 0; další běhy přispěvatele volají znovu.
            $periods->markWaiting((int) $locked['id'], $docId, $hash, $waiting, $options->timestamp(), $locked['waiting_since'] ?? null);
            return $this->line($source, $record, $locked, RunReport::OUTCOME_WAITING, $docId, $waiting);
        }
        $periods->markIssued((int) $locked['id'], $docId, $hash, $options->timestamp());

        return $this->line($source, $record, $locked, RunReport::OUTCOME_ISSUED, $docId, $canonical['docText'] ?? null);
    }

    /**
     * Období `waiting` (D10): přispěvatelé znovu. Ready → koncept se
     * přegeneruje v místě (replaceConcept), ale jen když ho nikdo ručně
     * neupravil (otisk obsahu = otisk při vzniku); jinak `result = edited`
     * a upozornění „podklady jsou, koncept upravený — Přegenerovat“.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $kind
     * @param array<string, mixed> $locked
     * @param list<array<string, mixed>> $rows
     */
    protected function continueWaiting(PeriodicInvoicingSource $source, array $record, ?array $kind, Period $period, array $locked, array $rows, RunOptions $options): RunLine
    {
        $periods = $this->periodsFor($source);
        $periodId = (int) $locked['id'];
        $docId = (int) ($locked['doc'] ?? 0);
        $built = $this->builder->build($source, $record, $kind, $period, $rows);
        [$canonical, $waiting] = $this->contribute($source, $record, $period, $built);
        if ($waiting !== null) {
            $periods->markResult($periodId, PeriodRepository::RESULT_WAITING, $waiting, $options->timestamp());
            return $this->line($source, $record, $locked, RunReport::OUTCOME_WAITING, $docId, $waiting);
        }
        if ($docId > 0 && $this->contentHashOf($docId) !== (string) ($locked['content_hash'] ?? '')) {
            $message = 'Podklady jsou k dispozici, ale koncept byl ručně upraven — použij Přegenerovat.';
            $periods->markResult($periodId, PeriodRepository::RESULT_EDITED, $message, $options->timestamp());
            return $this->line($source, $record, $locked, RunReport::OUTCOME_WAITING, $docId, $message);
        }
        if ($docId > 0) {
            $canonical['applyOptions']['replaceConcept'] = $docId;
        }
        $savedId = $this->applyCanonical($canonical);
        $periods->markIssued($periodId, $savedId, $this->contentHashOf($savedId), $options->timestamp());

        return $this->line($source, $record, $locked, RunReport::OUTCOME_ISSUED, $savedId, $canonical['docText'] ?? null);
    }

    /**
     * Zavolá přispěvatele řádků předpisu s `contributor` (D10) nad
     * rozpracovaným dokladem. Failed = InvoiceBuildException
     * (`contributor_failed`), Waiting = doklad beze změny + důvod, Ready =
     * řádky přispěvatele nahrazené vrácenými (pořadí zachované, `orderPos`
     * přečíslované).
     *
     * @param array<string, mixed> $record
     * @return array{0: array<string, mixed>, 1: ?string} [kanonický doklad, důvod čekání]
     */
    protected function contribute(PeriodicInvoicingSource $source, array $record, Period $period, BuiltInvoice $built): array
    {
        $canonical = $built->canonical;
        $byContributor = [];
        foreach ($built->rows as $index => $row) {
            $id = trim((string) ($row['contributor'] ?? ''));
            if ($id !== '') {
                $byContributor[$id][] = ['index' => $index, 'row' => $row];
            }
        }
        if ($byContributor === []) {
            return [$canonical, null];
        }

        $waiting = [];
        $replacements = [];
        foreach ($byContributor as $id => $entries) {
            $contributor = $this->contributors?->get($id);
            if ($contributor === null) {
                throw new InvoiceBuildException(
                    InvoiceBuildException::CONTRIBUTOR_FAILED,
                    "Přispěvatel obsahu '{$id}' není registrovaný — řádek předpisu se nedá doplnit.",
                );
            }
            $indexes = array_map(static fn(array $e): int => (int) $e['index'], $entries);
            $result = $contributor->contribute(new ContributionContext(
                $source->id(),
                $record,
                $period,
                $built->billingDate,
                array_map(static fn(array $e): array => $e['row'], $entries),
                $canonical,
                $indexes,
            ));
            if ($result->isFailed()) {
                throw new InvoiceBuildException(
                    InvoiceBuildException::CONTRIBUTOR_FAILED,
                    "Přispěvatel '{$id}': " . (string) $result->message,
                );
            }
            if ($result->isWaiting()) {
                $waiting[] = (string) $result->message;
                continue;
            }
            $replacements[] = ['indexes' => $indexes, 'rows' => $result->rows];
        }
        if ($waiting !== []) {
            return [$canonical, implode('; ', $waiting)];
        }

        return [self::replaceRows($canonical, $replacements), null];
    }

    /**
     * Nahradí řádky přispěvatelů v kanonickém dokladu: vrácené řádky jdou
     * na místo prvního řádku přispěvatele, ostatní jeho řádky mizí.
     *
     * @param array<string, mixed> $canonical
     * @param list<array{indexes: list<int>, rows: list<array<string, mixed>>}> $replacements
     * @return array<string, mixed>
     */
    private static function replaceRows(array $canonical, array $replacements): array
    {
        $insertAt = [];
        $removed = [];
        foreach ($replacements as $replacement) {
            $first = min($replacement['indexes']);
            $insertAt[$first] = $replacement['rows'];
            foreach ($replacement['indexes'] as $index) {
                $removed[$index] = true;
            }
        }

        $rows = [];
        foreach ($canonical['rows'] ?? [] as $index => $row) {
            if (isset($insertAt[$index])) {
                foreach ($insertAt[$index] as $newRow) {
                    $rows[] = $newRow;
                }
            }
            if (isset($removed[$index])) {
                continue;
            }
            $rows[] = $row;
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['orderPos'] = $i + 1;
        }
        $canonical['rows'] = $rows;
        return $canonical;
    }

    /**
     * Uložení kanonického dokladu applierem; neúspěch = výjimka (rollback
     * období).
     *
     * @param array<string, mixed> $canonical
     */
    protected function applyCanonical(array $canonical): int
    {
        // Generovaný doklad = pokyn (#166 U10): builder ví, co chce uložit.
        $result = $this->applier->apply($canonical);
        if (!$result->success || $result->savedId === null) {
            throw new \RuntimeException(self::applyErrorMessage($result));
        }
        return (int) $result->savedId;
    }

    private static function applyErrorMessage(ApplyResult $result): string
    {
        $message = (string) ($result->errorCode ?? 'apply_failed');
        if ($result->errorMessage !== null && $result->errorMessage !== '') {
            $message .= ': ' . $result->errorMessage;
        }
        $issues = [];
        foreach ($result->canonical['_resolve']['issues'] ?? $result->canonical['issues'] ?? [] as $issue) {
            if (is_array($issue) && ($issue['severity'] ?? '') === 'error') {
                $issues[] = (string) ($issue['path'] ?? '') . ' ' . (string) ($issue['message'] ?? $issue['code'] ?? '');
            }
        }
        if ($issues !== []) {
            $message .= ' (' . implode('; ', array_slice($issues, 0, 5)) . ')';
        }
        return mb_substr($message, 0, 500);
    }

    /**
     * Výsledek selhání mimo transakci: planned období zůstane bez dokladu,
     * waiting si doklad nechá.
     *
     * @param array<string, mixed> $periodRow
     */
    private function recordFailure(PeriodRepository $periods, array $periodRow, string $result, string $message, RunOptions $options): void
    {
        $id = (int) ($periodRow['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $message = mb_substr($message, 0, 500);
        if ((string) ($periodRow['state'] ?? '') === PeriodRepository::STATE_WAITING) {
            $periods->markResult($id, $result, $message, $options->timestamp());
        } else {
            $periods->markPlanned($id, $result, $message, $options->timestamp());
        }
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $periodRow
     */
    protected function line(PeriodicInvoicingSource $source, array $record, ?array $periodRow, string $outcome, ?int $docId = null, ?string $message = null): RunLine
    {
        return new RunLine(
            sourceId: $source->id(),
            recordId: (int) $record['id'],
            recordLabel: $source->recordLabel($record),
            periodFrom: $periodRow !== null ? self::isoDate($periodRow['period_from'] ?? null) : null,
            periodTo: $periodRow !== null ? self::isoDate($periodRow['period_to'] ?? null) : null,
            outcome: $outcome,
            docId: $docId,
            message: $message,
            periodId: isset($periodRow['id']) ? (int) $periodRow['id'] : null,
        );
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** Otisk obsahu uloženého dokladu (hlavička + řádky z DB). */
    protected function contentHashOf(int $docId): string
    {
        return ContentHash::ofStoredDocument($this->db, $docId);
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
