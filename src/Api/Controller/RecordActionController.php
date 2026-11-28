<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleUiExtension;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\RecordAction\RecordAction;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInputValidator;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;

/**
 * `POST /_ui/record-action/{table}/{recordId}/{globalActionId}` — provedení
 * akce záznamu s obsluhou v modulu (`recordActions`, #172 D12–D15). Jeden
 * endpoint pro všechny akce všech modulů:
 *
 *  1. akce z aktivních modulů zdroje dat pro tabulku — jinak 404
 *     `RECORD_ACTION_NOT_FOUND`;
 *  2. přístup k tabulce jako u vieweru (`TableAccessGuard`), akce
 *     `admin: true` jen pro správce — jinak 403;
 *  3. záznam — 404; `available()` je `false` — 409
 *     `RECORD_ACTION_NOT_AVAILABLE`;
 *  4. vstup: JSON `{"values": {...}}`, nebo `multipart/form-data` s částí
 *     `values` (JSON) a soubory `files[<id pole>]`; pole, které dialog
 *     nedeklaruje — 400 `RECORD_ACTION_UNKNOWN_FIELD`; chyby polí — 422
 *     `RECORD_ACTION_INVALID` s `details[] {field, code, message}`;
 *  5. `execute()`; chyba vstupu z akce — 422 téhož tvaru; jiná výjimka —
 *     500 `RECORD_ACTION_FAILED` bez podrobností;
 *  6. 200 `{message, level}`.
 *
 * Akce je mutace: v `ReadOnlyPolicy` controller záměrně není, zdroj dat jen
 * pro čtení dá 403 `DS_READ_ONLY` ještě před controllerem. Zámky záznamu
 * (`documentLockProviders`) endpoint neuplatňuje — rozhoduje akce.
 *
 * Každý pokus za dohledáním akce jde do logu (uživatel, akce, tabulka,
 * záznam, výsledek); hodnoty polí ani jméno souboru nikdy.
 */
final class RecordActionController
{
    private const string LOG_MESSAGE = 'Record action';

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly ModuleUiExtensions $extensions,
        private readonly DataSourceConnection $db,
        private readonly array $tables,
        private readonly ?ConfigRuntime $config = null,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?ServerConfig $serverConfig = null,
        private readonly string $language = 'en',
        private readonly ?DocumentRegistry $documents = null,
        private readonly ?DocumentEventDispatcher $dispatcher = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $post `$_POST` (testy); null = superglobál
     * @param array<string, mixed>|null $files `$_FILES` (testy); null = superglobál
     */
    public function run(
        string $table,
        int $recordId,
        string $actionId,
        AuthContext $auth,
        Request $request,
        ?array $post = null,
        ?array $files = null,
    ): Response {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $extension = isset($this->tables[$table]) ? $this->extensions->recordAction($table, $actionId) : null;
        if ($extension === null) {
            return Response::error(
                'RECORD_ACTION_NOT_FOUND',
                "Record action '{$actionId}' not found for table '{$table}'",
                404,
            );
        }

        $log = fn (string $result): null => $this->log($auth, $extension, $recordId, $result);

        $guardErr = TableAccessGuard::guardTable($table, $auth, $this->tables[$table]);
        if ($guardErr !== null) {
            $log('forbidden');
            return $guardErr;
        }
        if ($extension->admin && !$auth->isAdmin) {
            $log('forbidden');
            return Response::error('FORBIDDEN', 'Record action requires administrator rights', 403);
        }

        $record = $this->db->fetchRow('SELECT * FROM `' . $table . '` WHERE `id` = %i', $recordId);
        if ($record === null) {
            $log('record_not_found');
            return Response::error('RECORD_NOT_FOUND', "Record {$recordId} not found", 404);
        }

        $context = new RecordActionContext(
            $table,
            $this->db,
            $this->config,
            $this->dsConfig,
            $this->language,
            $this->tables[$table],
            $auth->userId,
            $auth->isAdmin,
            $this->serverConfig,
            $this->documents,
            $this->tables,
            $this->dispatcher,
        );

        // Výjimku akce zaloguje a spolkne `call()` — tady z ní zbývá `null`.
        $available = $this->extensions->call(
            $extension,
            static fn (RecordAction $action): bool => $action->available($table, $record, $context),
        );
        if ($available === null) {
            $log('failed');
            return self::failed();
        }
        if ($available === false) {
            $log('not_available');
            return Response::error(
                'RECORD_ACTION_NOT_AVAILABLE',
                'Record action is not available for this record',
                409,
            );
        }

        $descriptor = $this->extensions->call(
            $extension,
            static fn (RecordAction $action): RecordActionDescriptor => $action->describe($table, $record, $context),
        );
        if ($descriptor === null) {
            $log('failed');
            return self::failed();
        }

        $raw = self::rawInput($request, $post, $files);
        if ($raw instanceof Response) {
            $log('bad_request');
            return $raw;
        }

        $check = RecordActionInputValidator::validate($descriptor->fields(), $raw['values'], $raw['files']);
        if ($check->unknownField !== null) {
            $log('unknown_field');
            return Response::error(
                'RECORD_ACTION_UNKNOWN_FIELD',
                "Field '{$check->unknownField}' is not a field of the record action dialog",
                400,
            );
        }
        if ($check->input === null) {
            $log('invalid');
            return self::invalid($check->errors);
        }

        // Chybu vstupu z akce vracíme jako hodnotu, aby ji `call()` nevzal
        // za selhání poskytovatele; cokoli jiného zaloguje a vrátí `null`.
        $input = $check->input;
        $outcome = $this->extensions->call(
            $extension,
            static function (RecordAction $action) use ($table, $record, $input, $context): RecordActionResult|RecordActionValidationException {
                try {
                    return $action->execute($table, $record, $input, $context);
                } catch (RecordActionValidationException $e) {
                    return $e;
                }
            },
        );
        if ($outcome === null) {
            $log('failed');
            return self::failed();
        }
        if ($outcome instanceof RecordActionValidationException) {
            $log('invalid');
            $errors = [];
            foreach ($outcome->fieldErrors() as $field => $message) {
                $errors[] = ['field' => $field, 'code' => 'invalid', 'message' => $message];
            }
            return self::invalid($errors);
        }

        $log($outcome->level);
        return Response::success($outcome->toArray());
    }

    private function log(AuthContext $auth, ModuleUiExtension $extension, int $recordId, string $result): null
    {
        ErrorLogger::info(self::LOG_MESSAGE, [
            'user' => $auth->userId,
            'action' => $extension->globalId(),
            'table' => $extension->table,
            'record' => $recordId,
            'result' => $result,
        ]);
        return null;
    }

    private static function failed(): Response
    {
        return Response::error('RECORD_ACTION_FAILED', 'Record action failed', 500);
    }

    /** @param list<array{field: string, code: string, message: string}> $errors */
    private static function invalid(array $errors): Response
    {
        return Response::error('RECORD_ACTION_INVALID', 'Record action input is not valid', 422, $errors);
    }

    /**
     * Surový vstup: hodnoty a nahrané soubory. JSON tělo nese `values`;
     * multipart část `values` (JSON řetězec) a soubory `files[<id pole>]`.
     *
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $files
     * @return array{values: array<array-key, mixed>,
     *               files: array<array-key, array{path: string, name: string, size: int, error: int, mimeType: string}>}|Response
     */
    private static function rawInput(Request $request, ?array $post, ?array $files): array|Response
    {
        $contentType = strtolower((string) $request->getHeader('content-type'));
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            $values = $request->getBody()['values'] ?? [];
            return is_array($values)
                ? ['values' => $values, 'files' => []]
                : Response::error('BAD_REQUEST', "'values' must be an object", 400);
        }

        // Soubor ze superglobálu musí být skutečný upload; testy ho podstrkují.
        $requireUpload = $files === null;
        $post ??= $_POST;
        $files ??= $_FILES;

        // Tělo nad `post_max_size` PHP zahodí celé — $_POST i $_FILES prázdné.
        if ($post === [] && $files === [] && (int) $request->getHeader('content-length') > 0) {
            return Response::error('PAYLOAD_TOO_LARGE', 'Request body is too large', 413);
        }

        $values = [];
        if (isset($post['values'])) {
            $values = is_string($post['values']) ? json_decode($post['values'], true) : null;
            if (!is_array($values)) {
                return Response::error('BAD_REQUEST', "'values' must be a JSON object", 400);
            }
        }

        $uploads = [];
        $entry = $files['files'] ?? null;
        if ($entry !== null) {
            if (!is_array($entry) || !is_array($entry['error'] ?? null)) {
                return Response::error('BAD_REQUEST', "Files must be sent as 'files[<field id>]'", 400);
            }
            foreach ($entry['error'] as $field => $error) {
                // `files[pole][]` = víc souborů pro jedno pole — pole nese jeden.
                if (!is_int($error)) {
                    return Response::error('BAD_REQUEST', "Field '{$field}' accepts a single file", 400);
                }
                $path = (string) ($entry['tmp_name'][$field] ?? '');
                $ok = $error === UPLOAD_ERR_OK && $path !== '' && is_file($path)
                    && (!$requireUpload || is_uploaded_file($path));
                $uploads[$field] = [
                    'path' => $ok ? $path : '',
                    'name' => (string) ($entry['name'][$field] ?? ''),
                    'size' => $ok ? (int) filesize($path) : 0,
                    'error' => $error,
                    'mimeType' => $ok ? self::mimeType($path) : '',
                ];
            }
        }

        return ['values' => $values, 'files' => $uploads];
    }

    /** MIME z obsahu souboru — hlavičce prohlížeče se nevěří. */
    private static function mimeType(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
