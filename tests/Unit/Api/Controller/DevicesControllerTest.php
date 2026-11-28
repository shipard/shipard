<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\DevicesController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;
use Shipard\Tests\Unit\Module\Core\Devices\DeviceKeys;
use Shipard\Tests\Unit\Module\Core\Devices\DevicesConfig;

/**
 * Endpointy `/_devices/*` (#177, kontrakt §7.2): kdo smí o výzvu žádat,
 * registrace jen se session administrátora, podepsaná volání bez session,
 * tvary odpovědí a chybové kódy.
 */
class DevicesControllerTest extends TestCase
{
    use DeviceKeys;
    use DevicesConfig;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';

    private InMemoryDevicesDb $db;
    private \DateTimeImmutable $now;
    /** @var array{private: \OpenSSLAsymmetricKey, public: string} */
    private array $pair;
    private string $dsDir;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->now = new \DateTimeImmutable('2026-11-27 10:00:00');
        $this->pair = self::generateEcKey();

        $this->dsDir = sys_get_temp_dir() . '/shpd-devices-' . bin2hex(random_bytes(6));
        mkdir($this->dsDir . '/config', 0700, true);
        $this->writeMainJson([]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    /** @param array<string, mixed> $extra */
    private function writeMainJson(array $extra): void
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode($extra + [
            'id'            => 'test-0001-test-0001',
            'name'          => 'Test DS',
            'database_name' => 'test_0001_test_0001',
            'database_user' => 'shpd_test',
            'created'       => '2026-07-03T10:00:00+02:00',
        ]));
    }

    private function controller(): DevicesController
    {
        return new DevicesController(
            new DataSourceConfig($this->dsDir),
            $this->devicesConfig(),
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        $def = TableDefinition::fromArray([
            'tableId' => 472,
            'name'    => 'Devices',
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ]);
        return [DeviceDocument::TABLE => $def];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers název hlavičky → hodnota
     */
    private function request(string $method, string $path, ?array $body = null, array $headers = []): Request
    {
        $server = ['HTTP_HOST' => 'firma.example.com'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return Request::fromArray($method, '/api/v1/_devices/' . $path, [], $body === null ? '' : (string) json_encode($body), $server);
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, 3, 'session', 'shpd_st_test', true);
    }

    private static function user(): AuthContext
    {
        return new AuthContext(true, 4, 'session', 'shpd_st_test', false);
    }

    /** @return array<string, mixed> */
    private static function data(Response $response): array
    {
        return $response->getPayload()['data'];
    }

    private function assertError(string $code, int $status, Response $response): void
    {
        $this->assertSame($status, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame($code, $response->getPayload()['error']['code']);
    }

    private function seedDevice(int $state = DeviceDocument::STATE_ACTIVE): int
    {
        return $this->db->seed(DeviceDocument::TABLE, [
            'device_id'         => self::DEVICE,
            'name'              => 'Pokladna 2',
            'mode'              => 'shared',
            'auto_lock_minutes' => 10,
            'platform'          => 'ipados',
            'app_version'       => '1.0.0',
            'public_key'        => $this->pair['public'],
            'key_algorithm'     => 'ES256',
            'last_seen_at'      => null,
            'docState'          => $state,
        ]);
    }

    /** @return array<string, string> hlavičky podepsaného volání */
    private function signedHeaders(DevicePurpose $purpose, ?string $signAs = null): array
    {
        $issued = $this->controller()->challenge(
            $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => $purpose->value]),
            AuthContext::anonymous(),
            $this->db,
            $this->tables(),
        );
        $challenge = self::data($issued)['challenge'];
        return [
            'X-Shipard-Device'    => self::DEVICE,
            'X-Shipard-Challenge' => $challenge,
            'X-Shipard-Signature' => self::sign($this->pair['private'], $signAs ?? $purpose->value, self::DEVICE, $challenge),
        ];
    }

    // ── gating ──────────────────────────────────────────────────────────────

    public function testDataSourceWithoutTheModuleAnswersNotFound(): void
    {
        $ctrl = $this->controller();
        $request = $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => 'self']);

        $this->assertError('NOT_FOUND', 404, $ctrl->challenge($request, self::admin(), $this->db, []));
        $this->assertError('NOT_FOUND', 404, $ctrl->register($request, self::admin(), $this->db, []));
        $this->assertError('NOT_FOUND', 404, $ctrl->selfState($request, $this->db, []));
        $this->assertError('NOT_FOUND', 404, $ctrl->peripherals($request, $this->db, []));
        $this->assertSame([], $this->db->writes);
    }

    // ── challenge ───────────────────────────────────────────────────────────

    public function testRegisterChallengeNeedsAnAdminSession(): void
    {
        $request = $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => 'register']);
        $ctrl = $this->controller();

        $this->assertError('UNAUTHORIZED', 401, $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables()));
        $this->assertError('FORBIDDEN', 403, $ctrl->challenge($request, self::user(), $this->db, $this->tables()));
        // API klíč administrátorem není.
        $apiKey = new AuthContext(true, 3, 'api_key', 'shpd_ak_test', true);
        $this->assertError('FORBIDDEN', 403, $ctrl->challenge($request, $apiKey, $this->db, $this->tables()));
        $this->assertSame([], $this->db->rows(ChallengeService::TABLE));

        $response = $ctrl->challenge($request, self::admin(), $this->db, $this->tables());
        $this->assertSame(200, $response->getStatus());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', self::data($response)['challenge']);
        $this->assertSame(60, self::data($response)['expiresIn']);
        $this->assertSame('register', $this->db->rows(ChallengeService::TABLE)[0]['purpose']);
    }

    public function testOtherChallengesGoOnlyToARegisteredActiveDeviceWithoutSession(): void
    {
        $request = $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => 'self']);
        $ctrl = $this->controller();

        // Neznámé zařízení — ani administrátorovi; výzva nevznikne.
        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables()));
        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->challenge($request, self::admin(), $this->db, $this->tables()));
        $this->assertSame([], $this->db->rows(ChallengeService::TABLE));

        $id = $this->seedDevice();
        $response = $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables());
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(60, self::data($response)['expiresIn']);

        $this->db->tables[DeviceDocument::TABLE][$id]['docState'] = DeviceDocument::STATE_REVOKED;
        $this->assertError('DEVICE_REVOKED', 403, $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables()));
        $this->assertCount(1, $this->db->rows(ChallengeService::TABLE));
    }

    public function testChallengeRequestMustNameAKnownPurposeAndDevice(): void
    {
        $ctrl = $this->controller();

        foreach ([[], ['deviceId' => self::DEVICE], ['deviceId' => self::DEVICE, 'purpose' => 'pin-login'], ['deviceId' => self::DEVICE, 'purpose' => 5]] as $body) {
            $this->assertError('VALIDATION_ERROR', 422, $ctrl->challenge($this->request('POST', 'challenge', $body), self::admin(), $this->db, $this->tables()));
        }
        $this->assertError('VALIDATION_ERROR', 422, $ctrl->challenge(
            $this->request('POST', 'challenge', ['deviceId' => 'neni-uuid', 'purpose' => 'register']),
            self::admin(),
            $this->db,
            $this->tables(),
        ));
        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->challenge(
            $this->request('POST', 'challenge', ['deviceId' => ['x'], 'purpose' => 'self']),
            AuthContext::anonymous(),
            $this->db,
            $this->tables(),
        ));
    }

    public function testSixthUnusedChallengeIsRateLimited(): void
    {
        $this->seedDevice();
        $request = $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => 'self']);
        $ctrl = $this->controller();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables())->getStatus());
        }
        $this->assertError('RATE_LIMITED', 429, $ctrl->challenge($request, AuthContext::anonymous(), $this->db, $this->tables()));
    }

    // ── register ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function registerBody(): array
    {
        $issued = $this->controller()->challenge(
            $this->request('POST', 'challenge', ['deviceId' => self::DEVICE, 'purpose' => 'register']),
            self::admin(),
            $this->db,
            $this->tables(),
        );
        $challenge = self::data($issued)['challenge'];
        return [
            'deviceId'   => self::DEVICE,
            'algorithm'  => 'ES256',
            'publicKey'  => $this->pair['public'],
            'challenge'  => $challenge,
            'signature'  => self::sign($this->pair['private'], 'register', self::DEVICE, $challenge),
            'name'       => 'Pokladna 2',
            'mode'       => 'shared',
            'platform'   => 'mock',
            'appVersion' => '1.0.0',
            'keyStorage' => 'mock',
        ];
    }

    public function testRegistrationNeedsAnAdminSession(): void
    {
        $request = $this->request('POST', 'register', $this->registerBody());
        $ctrl = $this->controller();

        $this->assertError('UNAUTHORIZED', 401, $ctrl->register($request, AuthContext::anonymous(), $this->db, $this->tables()));
        $this->assertError('FORBIDDEN', 403, $ctrl->register($request, self::user(), $this->db, $this->tables()));
        $this->assertSame([], $this->db->rows(DeviceDocument::TABLE));
    }

    public function testRegistrationAnswers201WithTheDevice(): void
    {
        $response = $this->controller()->register(
            $this->request('POST', 'register', $this->registerBody()),
            self::admin(),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(201, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame(
            ['deviceId' => self::DEVICE, 'name' => 'Pokladna 2', 'mode' => 'shared', 'state' => 'active'],
            self::data($response),
        );
        $row = $this->db->rows(DeviceDocument::TABLE)[0];
        $this->assertSame(3, $row['registered_by']);
        $this->assertSame('mock', $row['key_storage']);
    }

    public function testRegistrationErrorsCarryContractCodes(): void
    {
        $ctrl = $this->controller();
        $body = $this->registerBody();

        $invalid = $ctrl->register($this->request('POST', 'register', ['name' => ''] + $body), self::admin(), $this->db, $this->tables());
        $this->assertError('VALIDATION_ERROR', 422, $invalid);
        $this->assertSame('name', $invalid->getPayload()['error']['details'][0]['field']);

        $forged = ['signature' => self::sign(self::generateEcKey()['private'], 'register', self::DEVICE, $body['challenge'])] + $body;
        $this->assertError('SIGNATURE_INVALID', 401, $ctrl->register($this->request('POST', 'register', $forged), self::admin(), $this->db, $this->tables()));
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->register($this->request('POST', 'register', $body), self::admin(), $this->db, $this->tables()));

        $this->assertSame(201, $ctrl->register($this->request('POST', 'register', $this->registerBody()), self::admin(), $this->db, $this->tables())->getStatus());
        $this->assertError('DEVICE_EXISTS', 409, $ctrl->register($this->request('POST', 'register', $this->registerBody()), self::admin(), $this->db, $this->tables()));
    }

    // ── self ────────────────────────────────────────────────────────────────

    public function testSelfReturnsTheRegistrationState(): void
    {
        $id = $this->seedDevice();

        $response = $this->controller()->selfState(
            $this->request('GET', 'self', headers: $this->signedHeaders(DevicePurpose::Self) + ['X-Shipard-App-Version' => '1.1.0']),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        $this->assertSame([
            'mode'            => 'shared',
            'name'            => 'Pokladna 2',
            'state'           => 'active',
            'users'           => [],
            'pinPolicy'       => ['enabled' => false, 'minLength' => 4, 'maxLength' => 8, 'maxAttempts' => 5],
            'autoLockMinutes' => 10,
        ], self::data($response));
        // Pokladnu odpověď nenese (U16).
        $this->assertArrayNotHasKey('cashDesk', self::data($response));

        $row = $this->db->tables[DeviceDocument::TABLE][$id];
        $this->assertEquals($this->now, $row['last_seen_at']);
        $this->assertSame('1.1.0', $row['app_version']);
    }

    public function testSelfReturnsThePinPolicyOfTheDataSource(): void
    {
        $this->seedDevice();
        $this->writeMainJson(['auth' => ['pin' => ['enabled' => true, 'minLength' => 6, 'maxAttempts' => 3]]]);

        $response = $this->controller()->selfState(
            $this->request('GET', 'self', headers: $this->signedHeaders(DevicePurpose::Self)),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(
            ['enabled' => true, 'minLength' => 6, 'maxLength' => 8, 'maxAttempts' => 3],
            self::data($response)['pinPolicy'],
        );
    }

    public function testSelfWithoutAValidSignatureIsRejected(): void
    {
        $id = $this->seedDevice();
        $ctrl = $this->controller();

        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->selfState($this->request('GET', 'self'), $this->db, $this->tables()));

        $headers = $this->signedHeaders(DevicePurpose::Self);
        $this->assertSame(200, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables())->getStatus());
        // Použitá výzva.
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables()));
        // Výzva jiného účelu — účel určuje routa.
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->selfState(
            $this->request('GET', 'self', headers: $this->signedHeaders(DevicePurpose::Peripherals)),
            $this->db,
            $this->tables(),
        ));
        // Podpis jiného účelu nad výzvou správného účelu.
        $this->assertError('SIGNATURE_INVALID', 401, $ctrl->selfState(
            $this->request('GET', 'self', headers: $this->signedHeaders(DevicePurpose::Self, signAs: 'peripherals')),
            $this->db,
            $this->tables(),
        ));

        $headers = $this->signedHeaders(DevicePurpose::Self);
        $this->db->tables[DeviceDocument::TABLE][$id]['docState'] = DeviceDocument::STATE_REVOKED;
        $this->assertError('DEVICE_REVOKED', 403, $ctrl->selfState($this->request('GET', 'self', headers: $headers), $this->db, $this->tables()));
    }

    // ── peripherals ─────────────────────────────────────────────────────────

    public function testPeripheralsNeedASignatureAndAValidBody(): void
    {
        $this->seedDevice();
        $ctrl = $this->controller();
        $body = ['peripherals' => [['id' => 'p-1', 'kind' => 'printer', 'name' => 'Účtenky']]];

        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->peripherals($this->request('PUT', 'self/peripherals', $body), $this->db, $this->tables()));
        // Výzva účelu `self` na hlášení periferií nestačí.
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->peripherals(
            $this->request('PUT', 'self/peripherals', $body, $this->signedHeaders(DevicePurpose::Self)),
            $this->db,
            $this->tables(),
        ));
        $this->assertError('VALIDATION_ERROR', 422, $ctrl->peripherals(
            $this->request('PUT', 'self/peripherals', ['peripherals' => 'p-1'], $this->signedHeaders(DevicePurpose::Peripherals)),
            $this->db,
            $this->tables(),
        ));
        $this->assertSame([], $this->db->rows('core_devices_peripherals'));
    }

    // ── printer-profiles ────────────────────────────────────────────────────

    public function testPrinterProfilesReturnTheCatalogInContractShape(): void
    {
        $this->seedDevice();

        $response = $this->controller()->printerProfiles(
            $this->request('GET', 'printer-profiles', headers: $this->signedHeaders(DevicePurpose::PrinterProfiles)),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(200, $response->getStatus());
        $this->assertSame([
            'profiles' => [
                [
                    'id'           => 'escpos-80-cp852',
                    'name'         => 'ESC/POS 80 mm, CP852',
                    'columns'      => 42,
                    'columnsSmall' => 56,
                    'dotsPerLine'  => 512,
                    'codePage'     => 'cp852',
                    'features'     => ['cut', 'drawer'],
                    'models'       => [],
                ],
                [
                    'id'           => 'escpos-58-cp852',
                    'name'         => 'ESC/POS 58 mm, CP852',
                    'columns'      => 32,
                    'columnsSmall' => 42,
                    'dotsPerLine'  => 384,
                    'codePage'     => 'cp852',
                    'features'     => ['cut', 'drawer'],
                    'models'       => [],
                ],
            ],
        ], self::data($response));
    }

    public function testPrinterProfilesNeedASignatureOfTheirOwnPurpose(): void
    {
        $this->seedDevice();
        $ctrl = $this->controller();

        $this->assertError('DEVICE_UNKNOWN', 401, $ctrl->printerProfiles($this->request('GET', 'printer-profiles'), $this->db, $this->tables()));
        // Výzva účelu `self` katalog neotevře.
        $this->assertError('CHALLENGE_INVALID', 401, $ctrl->printerProfiles(
            $this->request('GET', 'printer-profiles', headers: $this->signedHeaders(DevicePurpose::Self)),
            $this->db,
            $this->tables(),
        ));
        $this->assertError('SIGNATURE_INVALID', 401, $ctrl->printerProfiles(
            $this->request('GET', 'printer-profiles', headers: $this->signedHeaders(DevicePurpose::PrinterProfiles, signAs: 'self')),
            $this->db,
            $this->tables(),
        ));
        // Zdroj dat bez modulu zařízení endpoint nemá.
        $this->assertSame(404, $ctrl->printerProfiles(
            $this->request('GET', 'printer-profiles'),
            $this->db,
            [],
        )->getStatus());
    }

    public function testPrinterProfilesAreEmptyWithoutThePrintsModule(): void
    {
        $this->seedDevice();
        $ctrl = new DevicesController(
            new DataSourceConfig($this->dsDir),
            $this->devicesConfig(['core.prints.printerProfiles' => null]),
            fn () => $this->now,
        );

        $response = $ctrl->printerProfiles(
            $this->request('GET', 'printer-profiles', headers: $this->signedHeaders(DevicePurpose::PrinterProfiles)),
            $this->db,
            $this->tables(),
        );

        $this->assertSame(['profiles' => []], self::data($response));
    }
}
