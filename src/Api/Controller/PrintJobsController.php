<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Prints\Jobs\PrintJob;
use Shipard\Module\Core\Prints\Jobs\PrintJobException;
use Shipard\Module\Core\Prints\Jobs\PrintJobKind;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;

/**
 * REST tiskových úloh (#177 U36, U38; docs/prints.md kap. 14, kontrakt
 * docs/native-bridge.md §5):
 *
 *   POST /_print-jobs                 {kind?, printId?, recordId?, deviceId, role?}
 *   GET  /_print-jobs/{jobId}         data úlohy znovu — pro Zkusit znovu
 *   POST /_print-jobs/{jobId}/result  {status, errorCode?}
 *
 * Práva: úlohu `print` založí, kdo smí číst tabulku tisku (jako `/_prints`);
 * `test` a `drawer` kterýkoli přihlášený uživatel. Znovu načíst úlohu
 * a zapsat výsledek smí jen autor nebo administrátor — hlídá služba.
 * V režimu jen pro čtení projde jen `GET` (`ReadOnlyPolicy`).
 */
final class PrintJobsController
{
    public function __construct(
        private readonly PrintRegistry $registry,
        private readonly PrintJobService $service,
    ) {
    }

    /**
     * Zdroj dat tiskové úlohy umí, když má jejich tabulku i zařízení.
     *
     * @param array<string, TableDefinition> $tables
     */
    public static function available(array $tables): bool
    {
        return isset($tables[PrintJobService::TABLE], $tables[DeviceDocument::TABLE]);
    }

    /**
     * @param ?array<string, mixed> $body
     * @param array<string, TableDefinition> $tables
     */
    public function create(?array $body, AuthContext $auth, array $tables): Response
    {
        if (($denied = self::requireUser($auth)) !== null) {
            return $denied;
        }
        $body ??= [];

        $errors = [];
        $kind = PrintJobKind::Print;
        if (array_key_exists('kind', $body)) {
            $given = is_string($body['kind']) ? PrintJobKind::tryFrom($body['kind']) : null;
            if ($given === null) {
                $errors[] = ['field' => 'kind', 'message' => 'Unknown print job kind'];
            } else {
                $kind = $given;
            }
        }

        $role = $body['role'] ?? PrintJobService::DEFAULT_ROLE;
        if (!is_string($role)) {
            $errors[] = ['field' => 'role', 'message' => 'Role must be a string'];
        }

        $printId = null;
        $recordId = null;
        if ($kind === PrintJobKind::Print) {
            $printId = is_string($body['printId'] ?? null) ? $body['printId'] : null;
            $recordId = self::positiveInt($body['recordId'] ?? null);
            if ($printId === null || $printId === '') {
                $errors[] = ['field' => 'printId', 'message' => 'Print job of kind print needs printId'];
            }
            if ($recordId === null) {
                $errors[] = ['field' => 'recordId', 'message' => 'Print job of kind print needs recordId'];
            }
        }
        if ($errors !== []) {
            return self::error(PrintJobException::validation($errors));
        }

        if ($kind === PrintJobKind::Print) {
            $definition = $this->registry->get((string) $printId);
            if ($definition === null) {
                return self::error(PrintJobException::printNotFound((string) $printId));
            }
            $guardErr = TableAccessGuard::guardTable($definition->table, $auth, $tables[$definition->table] ?? null);
            if ($guardErr !== null) {
                return $guardErr;
            }
        }

        try {
            $job = $this->service->create($kind, $printId, $recordId, $body['deviceId'] ?? null, (string) $role, $auth);
        } catch (PrintJobException $e) {
            return self::error($e);
        }

        return Response::success([
            'jobId'        => $job->jobId,
            'peripheralId' => $job->peripheralId,
            'format'       => $job->format,
            'data'         => base64_encode($job->data),
            'warnings'     => $job->warnings,
        ], 201);
    }

    public function get(string $jobId, AuthContext $auth): Response
    {
        if (($denied = self::requireUser($auth)) !== null) {
            return $denied;
        }

        try {
            $job = $this->service->get($jobId, $auth);
        } catch (PrintJobException $e) {
            return self::error($e);
        }

        return Response::success(self::jobData($job));
    }

    /** @param ?array<string, mixed> $body */
    public function result(string $jobId, ?array $body, AuthContext $auth): Response
    {
        if (($denied = self::requireUser($auth)) !== null) {
            return $denied;
        }
        $body ??= [];

        try {
            return Response::success(
                $this->service->report($jobId, $body['status'] ?? null, $body['errorCode'] ?? null, $auth),
            );
        } catch (PrintJobException $e) {
            return self::error($e);
        }
    }

    /** @return array<string, mixed> */
    private static function jobData(PrintJob $job): array
    {
        return [
            'jobId'        => $job->jobId,
            'kind'         => $job->kind->value,
            'state'        => $job->state->value,
            'peripheralId' => $job->peripheralId,
            'format'       => $job->format,
            'data'         => base64_encode($job->data),
            'attempts'     => $job->attempts,
            'warnings'     => $job->warnings,
        ];
    }

    private static function requireUser(AuthContext $auth): ?Response
    {
        return $auth->isAuthenticated ? null : Response::error('UNAUTHORIZED', 'Authentication required', 401);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    private static function error(PrintJobException $e): Response
    {
        return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
    }
}
