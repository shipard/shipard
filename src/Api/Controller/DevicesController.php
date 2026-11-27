<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceContext;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\DeviceId;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Module\Core\Devices\DeviceRegistrationService;
use Shipard\Module\Core\Devices\DeviceRequestAuthenticator;
use Shipard\Module\Core\Devices\PeripheralSync;

/**
 * Zařízení ↔ server (#177, kontrakt docs/native-bridge.md §7.2).
 *
 * | Endpoint                          | Ověření                                   |
 * |-----------------------------------|-------------------------------------------|
 * | POST /_devices/challenge          | účel `register`: session administrátora;  |
 * |                                   | ostatní účely: registrované aktivní zařízení, bez session |
 * | POST /_devices/register           | session administrátora, podpis v těle     |
 * | GET  /_devices/self               | podpis, účel `self`                       |
 * | PUT  /_devices/self/peripherals   | podpis, účel `peripherals`                |
 *
 * `self` a `peripherals` jsou v `AuthMiddleware` výjimkou (session nenesou);
 * `challenge` a `register` ne — bez hlavičky `Authorization` dostanou
 * anonymní kontext, se session administrátora ho tu controller vyžádá.
 *
 * Logika žije v modulu `core.devices`; router o modulech neví, proto
 * controller odpoví 404, když zdroj dat modul nemá.
 */
final class DevicesController
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy */
    public function __construct(
        private readonly DataSourceConfig $dsConfig,
        private readonly ?ConfigRuntime $config = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @param array<string, TableDefinition> $tables */
    public function challenge(Request $request, AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        if (($gate = self::gate($tables)) !== null) {
            return $gate;
        }

        $body = $request->getBody() ?? [];
        $purpose = is_string($body['purpose'] ?? null) ? DevicePurpose::tryFrom($body['purpose']) : null;
        if ($purpose === null) {
            return Response::error('VALIDATION_ERROR', "Field 'purpose' is not a known purpose", 422);
        }
        $deviceId = $body['deviceId'] ?? null;

        try {
            if ($purpose->requiresAdminSession()) {
                if (($denied = self::requireAdminSession($auth)) !== null) {
                    return $denied;
                }
                if (!DeviceId::isValid($deviceId)) {
                    return Response::error('VALIDATION_ERROR', "Field 'deviceId' must be a UUID", 422);
                }
            } else {
                // Neznámému ani zneplatněnému zařízení výzva nevznikne (U20).
                $this->authenticator($db)->activeDevice(is_string($deviceId) ? $deviceId : null);
            }

            $challenge = $this->challenges($db)->issue((string) $deviceId, $purpose);
        } catch (DeviceException $e) {
            return self::error($e);
        }

        return Response::success(['challenge' => $challenge, 'expiresIn' => ChallengeService::TTL_SECONDS]);
    }

    /** @param array<string, TableDefinition> $tables */
    public function register(Request $request, AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        if (($gate = self::gate($tables)) !== null) {
            return $gate;
        }
        if (($denied = self::requireAdminSession($auth)) !== null) {
            return $denied;
        }

        try {
            $device = (new DeviceRegistrationService($db, $this->config, $this->challenges($db), $this->clock))
                ->register($request->getBody() ?? [], (int) $auth->userId);
        } catch (DeviceException $e) {
            return self::error($e);
        }

        return Response::success($device, 201);
    }

    /** @param array<string, TableDefinition> $tables */
    public function selfState(Request $request, DataSourceConnection $db, array $tables): Response
    {
        if (($gate = self::gate($tables)) !== null) {
            return $gate;
        }
        $device = $this->authenticate($request, $db, DevicePurpose::Self);
        if ($device instanceof Response) {
            return $device;
        }

        return Response::success([
            'mode'  => $device->mode,
            'name'  => $device->name,
            // Zneplatněné zařízení sem nedojde (`DEVICE_REVOKED`).
            'state' => 'active',
            // Zařazené uživatele doplní task 6.
            'users' => [],
            'pinPolicy'       => $this->dsConfig->getPinPolicy()->toArray(),
            'autoLockMinutes' => $device->autoLockMinutes,
        ]);
    }

    /** @param array<string, TableDefinition> $tables */
    public function peripherals(Request $request, DataSourceConnection $db, array $tables): Response
    {
        if (($gate = self::gate($tables)) !== null) {
            return $gate;
        }
        $device = $this->authenticate($request, $db, DevicePurpose::Peripherals);
        if ($device instanceof Response) {
            return $device;
        }

        try {
            $count = (new PeripheralSync($db, $this->clock))->sync($device->id, $request->getBody());
        } catch (DeviceException $e) {
            return self::error($e);
        }

        return Response::success(['count' => $count]);
    }

    private function authenticate(Request $request, DataSourceConnection $db, DevicePurpose $purpose): DeviceContext|Response
    {
        try {
            return $this->authenticator($db)->authenticate(
                $purpose,
                $request->getHeader(DeviceRequestAuthenticator::HEADER_DEVICE),
                $request->getHeader(DeviceRequestAuthenticator::HEADER_CHALLENGE),
                $request->getHeader(DeviceRequestAuthenticator::HEADER_SIGNATURE),
                $request->getHeader(DeviceRequestAuthenticator::HEADER_APP_VERSION),
            );
        } catch (DeviceException $e) {
            return self::error($e);
        }
    }

    private function authenticator(DataSourceConnection $db): DeviceRequestAuthenticator
    {
        return new DeviceRequestAuthenticator($db, $this->challenges($db), $this->clock);
    }

    private function challenges(DataSourceConnection $db): ChallengeService
    {
        return new ChallengeService($db, $this->clock);
    }

    /**
     * Registrovat smí ve verzi 1 jen administrátor zdroje dat se session
     * (U6) — API klíč administrátorem není.
     */
    private static function requireAdminSession(AuthContext $auth): ?Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$auth->isAdmin || $auth->tokenType !== 'session' || $auth->userId === null) {
            return Response::error('FORBIDDEN', 'Admin access required', 403);
        }
        return null;
    }

    /** @param array<string, TableDefinition> $tables */
    private static function gate(array $tables): ?Response
    {
        return isset($tables[DeviceDocument::TABLE]) ? null : Response::error('NOT_FOUND', 'Not found', 404);
    }

    private static function error(DeviceException $e): Response
    {
        return Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
    }
}
