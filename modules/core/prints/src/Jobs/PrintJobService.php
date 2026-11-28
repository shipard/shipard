<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

use Shipard\Api\AuthContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\PrinterProfile;
use Shipard\Core\Prints\EscPos\PrinterTestReceipt;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Module\Core\Devices\DeviceId;

/**
 * Tiskové úlohy (#177 D4, D25, U36–U39; docs/prints.md kap. 14): jediné
 * místo, které řádky `core_prints_jobs` zakládá a mění.
 *
 * Úloha vzniká složená: server podle role najde periferii zařízení
 * (`PrintJobRouter`), podle jejího profilu složí bajty a uloží je s úlohou.
 * Tiskárně je předává klient (ve verzi 1 webová aplikace přes most) a
 * výsledek hlásí zpět; stav úlohy je vždy poslední nahlášený výsledek.
 * Úloha bez výsledku zůstává `created` — „neověřeno“.
 *
 * Kdo smí tisknout záznam (`TableAccessGuard` nad tabulkou tisku), hlídá
 * controller; služba hlídá, že úlohu čte a uzavírá její autor nebo
 * administrátor.
 */
final class PrintJobService
{
    public const string TABLE = 'core_prints_jobs';

    public const string FORMAT_ESCPOS = 'escpos';
    public const string DEFAULT_ROLE = 'receipt';

    /** Limit dat úlohy po dekódování (kontrakt §3.6, U8). */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** Po kolika dnech úklid úlohu smaže (U39). */
    public const int RETENTION_DAYS = 30;

    /** Kód chyby z `print.submit`: velká písmena, nejvýš jako sloupec `error_code`. */
    private const string ERROR_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,63}$/D';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param \Closure(string, int, PrinterProfile): PrintOutput $renderPrint tisk záznamu do ESC/POS
     *        (`PrintRunner::run(…, PrintFormat::Escpos, null, $profile)`); volá se jen u druhu `print`
     * @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly PrintJobRouter $router,
        private readonly PrintRegistry $prints,
        private readonly \Closure $renderPrint,
        private readonly ?ConfigRuntime $config,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Služba pro údržbu (úklid starých úloh) — bez konfigurace a tisků;
     * zakládat úlohy s ní nejde.
     *
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public static function forMaintenance(DataSourceConnection $db, ?\Closure $clock = null): self
    {
        return new self(
            $db,
            new PrintJobRouter($db, null),
            new PrintRegistry(),
            static fn (): never => throw new \LogicException('Maintenance print job service cannot render'),
            null,
            $clock,
        );
    }

    /**
     * Založí úlohu se složenými daty.
     *
     * @param ?string $printId  id tisku — jen druh `print`
     * @param ?int    $recordId id záznamu — jen druh `print`
     * @param mixed   $deviceId `deviceId` zařízení, které bude tisknout
     *
     * @throws PrintJobException chyby směrování (viz `PrintJobRouter`), `VALIDATION_ERROR`,
     *         `PRINT_NOT_FOUND`, `PRINT_FORMAT_UNSUPPORTED`, chyby tisku jako `/_prints`,
     *         `PAYLOAD_TOO_LARGE`
     */
    public function create(
        PrintJobKind $kind,
        ?string $printId,
        ?int $recordId,
        mixed $deviceId,
        string $role,
        AuthContext $auth,
    ): PrintJob {
        $definition = null;
        if ($kind === PrintJobKind::Print) {
            $missing = [];
            if ($printId === null || $printId === '') {
                $missing[] = ['field' => 'printId', 'message' => 'Print job of kind print needs printId'];
            }
            if ($recordId === null || $recordId < 1) {
                $missing[] = ['field' => 'recordId', 'message' => 'Print job of kind print needs recordId'];
            }
            if ($missing !== []) {
                throw PrintJobException::validation($missing);
            }

            $definition = $this->prints->get((string) $printId);
            if ($definition === null) {
                throw PrintJobException::printNotFound((string) $printId);
            }
            if (!$definition->isEscPos()) {
                throw PrintJobException::formatUnsupported($definition->id);
            }
        }

        try {
            $route = $this->router->route($deviceId, $role, $kind === PrintJobKind::Drawer);
            [$bytes, $warnings] = match ($kind) {
                PrintJobKind::Print  => $this->printBytes((string) $printId, (int) $recordId, $route->profile),
                PrintJobKind::Test   => $this->testBytes($route->profile),
                PrintJobKind::Drawer => [$this->drawerBytes($route->profile), []],
            };
        } catch (PrintJobException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw PrintJobException::fromPrintError($e) ?? $e;
        }

        $size = strlen($bytes);
        if ($size > self::MAX_BYTES) {
            throw PrintJobException::payloadTooLarge($size, self::MAX_BYTES);
        }

        $warnings = array_map(
            static fn (PrintMessage $message): array => $message->toArray(),
            [...$route->warnings, ...$warnings],
        );
        $jobId = self::uuid();

        $id = $this->db->insertRow(self::TABLE, [
            'job_id'       => $jobId,
            'kind'         => $kind->value,
            'print_id'     => $definition?->id,
            'record_table' => $definition?->table,
            'record_id'    => $definition !== null ? $recordId : null,
            'device'       => $route->deviceRowId,
            'peripheral'   => $route->peripheralRowId,
            'role'         => $route->role,
            'format'       => self::FORMAT_ESCPOS,
            'profile_id'   => $route->profile->id,
            // Bajty, ne text: bez `%bin` by je Dibi poslalo jako řetězec
            // v kódování spojení.
            'data%bin'     => $bytes,
            'data_size'    => $size,
            'warnings'     => $warnings === [] ? null : json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'state'        => PrintJobState::Created->value,
            'attempts'     => 0,
            'created_by'   => $auth->userId,
            'created_at'   => ($this->clock)(),
        ]);

        return new PrintJob(
            id: $id,
            jobId: $jobId,
            kind: $kind,
            state: PrintJobState::Created,
            peripheralId: $route->peripheralId,
            format: self::FORMAT_ESCPOS,
            data: $bytes,
            attempts: 0,
            warnings: $warnings,
            createdBy: $auth->userId,
        );
    }

    /**
     * Úloha i s daty — pro Zkusit znovu.
     *
     * @throws PrintJobException `JOB_NOT_FOUND`, `FORBIDDEN` (cizí úloha)
     */
    public function get(mixed $jobId, AuthContext $auth): PrintJob
    {
        // Tvar UUID je stejný jako u `deviceId`; cokoli jiného úloha není.
        if (!DeviceId::isValid($jobId)) {
            throw PrintJobException::jobNotFound();
        }

        $row = $this->db->fetchRow(
            'SELECT j.*, p.peripheral_id AS peripheral_app_id
             FROM core_prints_jobs j
             LEFT JOIN core_devices_peripherals p ON p.id = j.peripheral
             WHERE j.job_id = %s',
            $jobId,
        );
        if ($row === null) {
            throw PrintJobException::jobNotFound();
        }

        $createdBy = isset($row['created_by']) ? (int) $row['created_by'] : null;
        if (!$auth->isAdmin && ($auth->userId === null || $createdBy !== $auth->userId)) {
            throw PrintJobException::foreignJob();
        }

        return new PrintJob(
            id: (int) $row['id'],
            jobId: (string) $row['job_id'],
            kind: PrintJobKind::from((string) $row['kind']),
            state: PrintJobState::from((string) $row['state']),
            peripheralId: isset($row['peripheral_app_id']) ? (string) $row['peripheral_app_id'] : null,
            format: (string) $row['format'],
            data: (string) $row['data'],
            attempts: (int) $row['attempts'],
            warnings: self::decodeWarnings($row['warnings'] ?? null),
            createdBy: $createdBy,
        );
    }

    /**
     * Zapíše výsledek `print.submit`. Každé hlášení je jeden pokus; stav
     * úlohy je poslední nahlášený výsledek.
     *
     * @param mixed $status    `sent` | `failed`
     * @param mixed $errorCode u `failed` povinný kód chyby mostu, u `sent` se nečte
     * @return array{state: string, attempts: int}
     *
     * @throws PrintJobException `JOB_NOT_FOUND`, `FORBIDDEN`, `VALIDATION_ERROR`
     */
    public function report(mixed $jobId, mixed $status, mixed $errorCode, AuthContext $auth): array
    {
        $job = $this->get($jobId, $auth);

        $state = PrintJobState::reportable($status);
        if ($state === null) {
            throw PrintJobException::validation([['field' => 'status', 'message' => "Status must be 'sent' or 'failed'"]]);
        }
        if ($state === PrintJobState::Failed
            && (!is_string($errorCode) || preg_match(self::ERROR_CODE_PATTERN, $errorCode) !== 1)) {
            throw PrintJobException::validation([[
                'field'   => 'errorCode',
                'message' => 'Failed result needs errorCode in capitals, at most 64 characters',
            ]]);
        }

        $this->db->execute(
            'UPDATE core_prints_jobs
             SET state = %s, error_code = %s, attempts = attempts + 1, result_at = %dt
             WHERE id = %i',
            $state->value,
            $state === PrintJobState::Failed ? $errorCode : null,
            ($this->clock)(),
            $job->id,
        );

        return [
            'state'    => $state->value,
            'attempts' => (int) $this->db->fetchSingle('SELECT attempts FROM core_prints_jobs WHERE id = %i', $job->id),
        ];
    }

    /** Smaže úlohy starší než `$days` dní (úloha cronu). Vrací počet smazaných. */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        $this->db->execute(
            'DELETE FROM core_prints_jobs WHERE created_at < %dt',
            ($this->clock)()->modify('-' . max(0, $days) . ' days'),
        );
        return $this->db->getAffectedRows();
    }

    /** @return array{0: string, 1: list<PrintMessage>} bajty a varování builderu i sazby */
    private function printBytes(string $printId, int $recordId, PrinterProfile $profile): array
    {
        /** @var PrintOutput $output */
        $output = ($this->renderPrint)($printId, $recordId, $profile);
        return [(string) $output->bytes, [...$output->printData->messages, ...$output->warnings]];
    }

    /** @return array{0: string, 1: list<PrintMessage>} */
    private function testBytes(PrinterProfile $profile): array
    {
        try {
            $receipt = PrinterTestReceipt::fromConfig($this->config, $this->clock)->render($profile);
        } catch (EscPosConfigException $e) {
            throw new PrintRenderException(RenderErrorKind::Unconfigured, $e->getMessage());
        }
        return [$receipt->bytes, $receipt->warnings];
    }

    /** Otevření šuplíku je úloha bez papíru — jen příkaz ovladače profilu. */
    private function drawerBytes(PrinterProfile $profile): string
    {
        try {
            return EscPosDrivers::fromConfig($this->config)->get($profile->driver)->openCashDrawer();
        } catch (EscPosConfigException $e) {
            throw new PrintRenderException(RenderErrorKind::Unconfigured, $e->getMessage());
        }
    }

    /** @return list<array{severity: string, code: string, text: string}> */
    private static function decodeWarnings(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    /** UUID verze 4. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
