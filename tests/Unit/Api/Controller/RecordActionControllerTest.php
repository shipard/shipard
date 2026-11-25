<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\RecordActionController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Module\Test\UiExtensions\FixtureDialogAction;
use Shipard\Module\Test\UiExtensions\FixturePingAction;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/**
 * `POST /_ui/record-action/{table}/{id}/{akce}` (#172 D12–D15): dohledání
 * akce, oprávnění, dostupnost, vstup JSON i multipart, validace, provedení
 * a log bez hodnot polí.
 */
final class RecordActionControllerTest extends TestCase
{
    use UiExtensionFixtures;

    private const RECORD = ['id' => 7, 'name' => 'Záznam', 'kind' => 'main', 'docState' => 40, 'contact_payment' => 0];

    private const PING = 'test.uiExtensions.ping';
    private const CONFIRM = 'test.uiExtensions.confirmPayment';
    private const UPLOAD = 'test.uiExtensions.uploadCertificate';
    private const PURGE = 'test.uiExtensions.purge';

    private const VALID = [
        'title' => 'Main',
        'password' => 'hunter2-secret',
        'paidAt' => '2026-11-25T14:30',
        'mode' => 'test',
    ];

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
        FixturePingAction::$executions = [];
        FixtureDialogAction::$lastInput = null;
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    private static function user(): AuthContext
    {
        return new AuthContext(true, 5, 'session', 'shpd_st_test');
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true);
    }

    /**
     * @param array<string, mixed>|null $record řádek, který vrátí databáze
     * @param array<string, TableDefinition>|null $tables
     */
    private function controller(
        ?array $record = self::RECORD,
        ?ModuleUiExtensions $extensions = null,
        ?array $tables = null,
        ?ServerConfig $serverConfig = null,
    ): RecordActionController {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);

        return new RecordActionController(
            $extensions ?? $this->uiExtensionsOf(),
            $db,
            $tables ?? $this->uiTables(),
            null,
            $this->uiDataSource(),
            $serverConfig,
            'cs',
        );
    }

    /** Registr s jedinou akcí `test.uiExtensions.run` nad fixturovou třídou. */
    private function single(string $class, bool $admin = false): ModuleUiExtensions
    {
        return ModuleUiExtensions::fromModules([ModuleDefinition::fromArray([
            'id' => 'test.uiExtensions',
            'name' => 'addon',
            'recordActions' => [
                ['id' => 'run', 'table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . $class, 'admin' => $admin],
            ],
        ])]);
    }

    /** @param array<string, mixed>|null $values */
    private static function json(?array $values = null): Request
    {
        return Request::fromArray(
            'POST',
            '/api/v1/_ui/record-action/x/7/y',
            [],
            $values === null ? '' : (string) json_encode(['values' => $values]),
            ['CONTENT_TYPE' => 'application/json'],
        );
    }

    private static function multipart(int $contentLength = 512): Request
    {
        return Request::fromArray('POST', '/api/v1/_ui/record-action/x/7/y', [], '', [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----shpd',
            'CONTENT_LENGTH' => (string) $contentLength,
        ]);
    }

    /**
     * `$_FILES` s jedním souborem `files[certificate]`.
     *
     * @return array<string, mixed>
     */
    private function certificate(int $bytes = 64, int $error = UPLOAD_ERR_OK, string $name = 'private-name.p12'): array
    {
        $path = $this->uiTmpDir . '/upload-' . $bytes;
        file_put_contents($path, str_repeat('x', $bytes));
        return ['files' => [
            'name' => ['certificate' => $name],
            'type' => ['certificate' => 'text/html'],
            'tmp_name' => ['certificate' => $error === UPLOAD_ERR_OK ? $path : ''],
            'error' => ['certificate' => $error],
            'size' => ['certificate' => $bytes],
        ]];
    }

    /** @return array{code: string, message: string, details?: list<array<string, mixed>>} */
    private static function error(Response $response): array
    {
        return $response->getPayload()['error'];
    }

    /** @return list<array<string, mixed>> záznamy logu „Record action“ */
    private function actionLog(): array
    {
        return $this->uiLogEntries('Record action');
    }

    // ── dohledání akce ──────────────────────────────────────────────────────

    public function testAnonymousGets401(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PING, AuthContext::anonymous(), self::json());

        $this->assertSame(401, $response->getStatus());
        $this->assertSame([], $this->actionLog());
    }

    public function testUnknownActionIs404(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, 'test.uiExtensions.nope', self::user(), self::json());

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('RECORD_ACTION_NOT_FOUND', self::error($response)['code']);
        $this->assertSame([], $this->actionLog(), 'neznámá akce není pokus o provedení');
    }

    public function testActionOfAnotherTableIs404(): void
    {
        $tables = $this->uiTables();
        $tables['other_table'] = $tables[self::UI_TABLE];

        $response = $this->controller(tables: $tables)->run('other_table', 7, self::PING, self::user(), self::json());

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('RECORD_ACTION_NOT_FOUND', self::error($response)['code']);
    }

    public function testActionOfInactiveModuleIs404(): void
    {
        $response = $this->controller(extensions: $this->uiExtensionsOf(['test.uiHost']))
            ->run(self::UI_TABLE, 7, self::PING, self::user(), self::json());

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('RECORD_ACTION_NOT_FOUND', self::error($response)['code']);
    }

    public function testTableUnknownToDataSourceIs404(): void
    {
        $response = $this->controller(tables: [])->run(self::UI_TABLE, 7, self::PING, self::user(), self::json());

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('RECORD_ACTION_NOT_FOUND', self::error($response)['code']);
    }

    // ── oprávnění ───────────────────────────────────────────────────────────

    public function testAdminOnlyTableIsForbiddenForUser(): void
    {
        $tables = $this->uiTables();
        $tables[self::UI_TABLE] = $this->adminOnlyTable($tables[self::UI_TABLE]);

        $response = $this->controller(tables: $tables)->run(self::UI_TABLE, 7, self::PING, self::user(), self::json());

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('FORBIDDEN_ADMIN_ONLY', self::error($response)['code']);
        $this->assertSame([], FixturePingAction::$executions);
        $this->assertSame('forbidden', $this->actionLog()[0]['result']);
    }

    public function testAdminActionIsForbiddenForUser(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PURGE, self::user(), self::json());

        $this->assertSame(403, $response->getStatus());
        $this->assertSame('FORBIDDEN', self::error($response)['code']);
        $this->assertSame(
            [['user' => 5, 'action' => self::PURGE, 'table' => self::UI_TABLE, 'record' => 7, 'result' => 'forbidden']],
            $this->actionLog(),
        );
    }

    public function testAdminActionRunsForAdmin(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PURGE, self::admin(), self::json());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['message' => 'Purged', 'level' => 'info'], $response->getPayload()['data']);
    }

    // ── záznam a dostupnost ─────────────────────────────────────────────────

    public function testMissingRecordIs404(): void
    {
        $response = $this->controller(record: null)->run(self::UI_TABLE, 99, self::PING, self::user(), self::json());

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('RECORD_NOT_FOUND', self::error($response)['code']);
        $this->assertSame('record_not_found', $this->actionLog()[0]['result']);
    }

    public function testUnavailableActionIs409(): void
    {
        // Záznam se od vykreslení detailu změnil — akce pro něj už není.
        $response = $this->controller(record: ['kind' => 'skip'] + self::RECORD)
            ->run(self::UI_TABLE, 7, self::PING, self::user(), self::json());

        $this->assertSame(409, $response->getStatus());
        $this->assertSame('RECORD_ACTION_NOT_AVAILABLE', self::error($response)['code']);
        $this->assertSame([], FixturePingAction::$executions);
        $this->assertSame('not_available', $this->actionLog()[0]['result']);
    }

    public function testFailingAvailableIs500WithoutDetail(): void
    {
        $response = $this->controller(extensions: $this->single('ThrowingAvailableAction'))
            ->run(self::UI_TABLE, 7, 'test.uiExtensions.run', self::user(), self::json());

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('RECORD_ACTION_FAILED', self::error($response)['code']);
        $this->assertStringNotContainsString('available failed', (string) json_encode($response->getPayload()));
        $this->assertSame('available failed', $this->uiExtensionFailures()[0]['message'], 'detail jen v logu');
        $this->assertSame('failed', $this->actionLog()[0]['result']);
    }

    public function testDescriptorOutsideContractIs500(): void
    {
        $response = $this->controller(extensions: $this->single('ThrowingDescribeAction'))
            ->run(self::UI_TABLE, 7, 'test.uiExtensions.run', self::user(), self::json());

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('RECORD_ACTION_FAILED', self::error($response)['code']);
        $this->assertCount(1, $this->uiExtensionFailures());
    }

    // ── provedení bez dialogu ───────────────────────────────────────────────

    public function testActionWithoutDialogRuns(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PING, self::user(), self::json());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['message' => 'Ping 7', 'level' => 'success'], $response->getPayload()['data']);
        $this->assertSame(
            [['table' => self::UI_TABLE, 'record' => self::RECORD, 'userId' => 5, 'isAdmin' => false, 'language' => 'cs']],
            FixturePingAction::$executions,
            'akce dostane holý řádek tabulky a uživatele v kontextu',
        );
        $this->assertSame(
            [['user' => 5, 'action' => self::PING, 'table' => self::UI_TABLE, 'record' => 7, 'result' => 'success']],
            $this->actionLog(),
        );
    }

    public function testEmptyValuesObjectIsAccepted(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PING, self::user(), self::json([]));

        $this->assertSame(200, $response->getStatus());
    }

    public function testResultLevelIsPassedThrough(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::CONFIRM, self::user(), self::json());

        $this->assertSame(['message' => 'Confirmed with a warning', 'level' => 'warning'], $response->getPayload()['data']);
        $this->assertSame('warning', $this->actionLog()[0]['result']);
    }

    public function testValueForActionWithoutDialogIs400(): void
    {
        $response = $this->controller()->run(self::UI_TABLE, 7, self::PING, self::user(), self::json(['force' => true]));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('RECORD_ACTION_UNKNOWN_FIELD', self::error($response)['code']);
        $this->assertSame([], FixturePingAction::$executions);
        $this->assertSame('unknown_field', $this->actionLog()[0]['result']);
    }

    public function testValuesMustBeAnObject(): void
    {
        $request = Request::fromArray('POST', '/x', [], '{"values": "text"}', ['CONTENT_TYPE' => 'application/json']);

        $response = $this->controller()->run(self::UI_TABLE, 7, self::PING, self::user(), $request);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('BAD_REQUEST', self::error($response)['code']);
    }

    public function testExceptionFromExecuteIs500WithoutDetail(): void
    {
        $response = $this->controller(extensions: $this->single('ThrowingExecuteAction'))
            ->run(self::UI_TABLE, 7, 'test.uiExtensions.run', self::user(), self::json());

        $this->assertSame(500, $response->getStatus());
        $this->assertSame('RECORD_ACTION_FAILED', self::error($response)['code']);
        $this->assertStringNotContainsString('secret-detail', (string) json_encode($response->getPayload()));
        $this->assertSame('secret-detail-of-the-failure', $this->uiExtensionFailures()[0]['message']);
        $this->assertSame('failed', $this->actionLog()[0]['result']);
    }

    // ── dialog: validace ────────────────────────────────────────────────────

    public function testUndeclaredFieldIs400(): void
    {
        $response = $this->controller()
            ->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::multipart(), ['values' => json_encode(self::VALID + ['extra' => 1])], $this->certificate());

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('RECORD_ACTION_UNKNOWN_FIELD', self::error($response)['code']);
        $this->assertStringContainsString("'extra'", self::error($response)['message']);
        $this->assertNull(FixtureDialogAction::$lastInput);
    }

    public function testValidationErrorsAre422WithFieldDetails(): void
    {
        // JSON bez souboru: chybí povinný text, heslo, čas i soubor; číslo
        // mimo meze a hodnota mimo nabídku.
        $response = $this->controller()->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::json([
            'title' => '',
            'copies' => 9,
            'mode' => 'live',
            'validFrom' => '25.11.2026',
        ]));

        $this->assertSame(422, $response->getStatus());
        $error = self::error($response);
        $this->assertSame('RECORD_ACTION_INVALID', $error['code']);
        $this->assertSame(
            [
                'title' => 'required',
                'password' => 'required',
                'copies' => 'out_of_range',
                'validFrom' => 'invalid_date',
                'paidAt' => 'required',
                'mode' => 'not_in_options',
                'certificate' => 'required',
            ],
            array_column($error['details'] ?? [], 'code', 'field'),
        );
        foreach ($error['details'] ?? [] as $detail) {
            $this->assertSame(['field', 'code', 'message'], array_keys($detail), 'tvar chyb polí jako u formulářů');
        }
        $this->assertNull(FixtureDialogAction::$lastInput);
        $this->assertSame('invalid', $this->actionLog()[0]['result']);
    }

    public function testFileOverFieldLimitIs422(): void
    {
        $response = $this->controller()->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode(self::VALID)],
            $this->certificate(FixtureDialogAction::MAX_BYTES + 1),
        );

        $this->assertSame(422, $response->getStatus());
        $this->assertSame(
            ['certificate' => 'file_too_large'],
            array_column(self::error($response)['details'] ?? [], 'code', 'field'),
        );
    }

    public function testFileOverServerLimitIs422(): void
    {
        $response = $this->controller()->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode(self::VALID)],
            $this->certificate(error: UPLOAD_ERR_INI_SIZE),
        );

        $this->assertSame(422, $response->getStatus());
        $this->assertSame(
            ['certificate' => 'file_too_large'],
            array_column(self::error($response)['details'] ?? [], 'code', 'field'),
        );
    }

    public function testValidationExceptionFromActionIs422(): void
    {
        $response = $this->controller()->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode(['password' => 'wrong'] + self::VALID)],
            $this->certificate(),
        );

        $this->assertSame(422, $response->getStatus());
        $error = self::error($response);
        $this->assertSame('RECORD_ACTION_INVALID', $error['code']);
        $this->assertSame(
            [['field' => 'password', 'code' => 'invalid', 'message' => 'The password does not match the certificate.']],
            $error['details'] ?? null,
        );
        $this->assertSame([], $this->uiExtensionFailures(), 'chyba vstupu není selhání poskytovatele');
        $this->assertSame('invalid', $this->actionLog()[0]['result']);
    }

    public function testFormLevelValidationExceptionFromAction(): void
    {
        $response = $this->controller()->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode(['title' => 'fail'] + self::VALID)],
            $this->certificate(),
        );

        $this->assertSame(422, $response->getStatus());
        $this->assertSame(
            [['field' => '_form', 'code' => 'invalid', 'message' => 'The certificate cannot be stored.']],
            self::error($response)['details'] ?? null,
        );
    }

    // ── dialog: provedení ───────────────────────────────────────────────────

    public function testMultipartInputReachesTheAction(): void
    {
        $response = $this->controller()->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode(self::VALID + ['copies' => 2, 'notify' => true, 'validFrom' => '2026-11-01', 'note' => 'Hi'])],
            $this->certificate(64),
        );

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['message' => 'Certificate uploaded', 'level' => 'success'], $response->getPayload()['data']);

        $input = FixtureDialogAction::$lastInput;
        $this->assertNotNull($input);
        $this->assertSame('Main', $input->string('title'));
        $this->assertSame('Hi', $input->string('note'));
        $this->assertSame('hunter2-secret', $input->string('password'));
        $this->assertSame(2, $input->value('copies'));
        $this->assertTrue($input->bool('notify'));
        $this->assertSame('test', $input->value('mode'));
        $this->assertSame('2026-11-01', $input->dateTime('validFrom')?->format('Y-m-d'));
        $this->assertSame('2026-11-25 14:30', $input->dateTime('paidAt')?->format('Y-m-d H:i'));

        $file = $input->file('certificate');
        $this->assertNotNull($file);
        $this->assertSame('private-name.p12', $file->name);
        $this->assertSame(64, $file->size);
        $this->assertSame(str_repeat('x', 64), file_get_contents($file->path));
        $this->assertSame('text/plain', $file->mimeType, 'MIME z obsahu, ne z hlavičky prohlížeče (text/html)');
    }

    public function testMultipartWithoutValuesPartUsesEmptyValues(): void
    {
        $response = $this->controller()
            ->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::multipart(), [], $this->certificate());

        $this->assertSame(422, $response->getStatus());
        $this->assertArrayNotHasKey(
            'certificate',
            array_column(self::error($response)['details'] ?? [], 'code', 'field'),
            'soubor dorazil, chybí jen hodnoty',
        );
    }

    public function testMalformedValuesPartIs400(): void
    {
        $response = $this->controller()
            ->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::multipart(), ['values' => '{not json'], $this->certificate());

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('BAD_REQUEST', self::error($response)['code']);
    }

    public function testSeveralFilesForOneFieldAre400(): void
    {
        $files = ['files' => [
            'name' => ['certificate' => ['a.p12', 'b.p12']],
            'tmp_name' => ['certificate' => ['/tmp/a', '/tmp/b']],
            'error' => ['certificate' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK]],
            'size' => ['certificate' => [1, 1]],
        ]];

        $response = $this->controller()
            ->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::multipart(), ['values' => json_encode(self::VALID)], $files);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('BAD_REQUEST', self::error($response)['code']);
    }

    public function testBodyOverPostLimitIs413(): void
    {
        // PHP nad `post_max_size` zahodí $_POST i $_FILES.
        $response = $this->controller()
            ->run(self::UI_TABLE, 7, self::UPLOAD, self::user(), self::multipart(200_000_000), [], []);

        $this->assertSame(413, $response->getStatus());
        $this->assertSame('PAYLOAD_TOO_LARGE', self::error($response)['code']);
    }

    // ── log ─────────────────────────────────────────────────────────────────

    public function testLogNeverContainsFieldValuesOrFileName(): void
    {
        $controller = $this->controller();
        $run = fn (array $values): Response => $controller->run(
            self::UI_TABLE,
            7,
            self::UPLOAD,
            self::user(),
            self::multipart(),
            ['values' => json_encode($values)],
            $this->certificate(),
        );

        // úspěch, chyba obecné validace, chyba z akce a nedeklarované pole
        $this->assertSame(200, $run(self::VALID)->getStatus());
        $this->assertSame(422, $run(['copies' => 99] + self::VALID)->getStatus());
        $this->assertSame(422, $run(['title' => 'fail'] + self::VALID)->getStatus());
        $this->assertSame(400, $run(self::VALID + ['extra-secret' => 'hunter2-secret'])->getStatus());

        $this->assertSame(['success', 'invalid', 'invalid', 'unknown_field'], array_column($this->actionLog(), 'result'));
        foreach ($this->actionLog() as $entry) {
            $this->assertSame(['user', 'action', 'table', 'record', 'result'], array_keys($entry));
            $this->assertSame([5, self::UPLOAD, self::UI_TABLE, 7], [$entry['user'], $entry['action'], $entry['table'], $entry['record']]);
        }

        $log = implode("\n", $this->uiLogLines());
        $this->assertNotSame('', $log);
        $this->assertStringNotContainsString('hunter2-secret', $log, 'heslo');
        $this->assertStringNotContainsString('private-name', $log, 'jméno souboru');
        $this->assertStringNotContainsString('extra-secret', $log, 'jméno nedeklarovaného pole');
        $this->assertStringNotContainsString('Main', $log, 'hodnota textového pole');
    }

    public function testServerConfigReachesTheContext(): void
    {
        // Kontext se staví jednou a nese uživatele i ServerConfig — ověřeno
        // na provedení: akce bez dialogu dostane správce.
        $this->controller(serverConfig: new ServerConfig())->run(self::UI_TABLE, 7, self::PING, self::admin(), self::json());

        $this->assertSame([1, true], [FixturePingAction::$executions[0]['userId'], FixturePingAction::$executions[0]['isAdmin']]);
    }

    /** Kopie definice tabulky s `adminOnly: true`. */
    private function adminOnlyTable(TableDefinition $table): TableDefinition
    {
        $reflection = new \ReflectionClass($table);
        $copy = $reflection->newInstanceWithoutConstructor();
        foreach ($reflection->getProperties() as $property) {
            if ($property->isInitialized($table)) {
                $property->setValue($copy, $property->getName() === 'adminOnly' ? true : $property->getValue($table));
            }
        }
        return $copy;
    }
}
