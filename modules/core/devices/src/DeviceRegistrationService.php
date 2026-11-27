<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Registrace a zneplatnění zařízení (#177 D5, D27, U18, kontrakt
 * docs/native-bridge.md §7.2–7.3). Jediné místo, kde záznam zařízení vzniká
 * a kde končí — akce Zneplatnit i příkaz `device-revoke` volají `revoke()`.
 *
 * Registrace: tvar těla → klíč P-256 → zařízení ještě není → spotřeba výzvy
 * `register` (vydané pro session administrátora) → podpis nad **dodaným**
 * klíčem → záznam. Že volá administrátor se session, hlídá controller.
 *
 * Zápisy jdou mimo gateway: vložení řádku a atomický podmíněný UPDATE při
 * zneplatnění. Co má na zneplatnění navázat (task 6: session zařízení), se
 * volá odsud, ne z handleru změny stavu.
 */
final class DeviceRegistrationService
{
    /** `mock` hlásí jen simulace mostu pro vývoj (kontrakt §9a). */
    public const array PLATFORMS = ['macos', 'ipados', 'mock'];
    public const array KEY_STORAGES = ['secureEnclave', 'keychain', 'mock'];

    private const int APP_VERSION_MAX_LENGTH = 40;

    private readonly ChallengeService $challenges;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
        ?ChallengeService $challenges = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->challenges = $challenges ?? new ChallengeService($db, $this->clock);
    }

    /**
     * @param array<string, mixed> $body tělo `POST /_devices/register`
     * @param int $adminUserId přihlášený administrátor
     * @return array{deviceId: string, name: string, mode: string, state: string} odpověď 201
     *
     * @throws DeviceException `VALIDATION_ERROR`, `SIGNATURE_INVALID`, `DEVICE_EXISTS`,
     *         `DEVICE_REVOKED`, `CHALLENGE_INVALID`
     */
    public function register(array $body, int $adminUserId): array
    {
        $input = $this->validated($body);

        $key = DeviceKey::fromSpkiBase64($input['publicKey']);
        if ($key === null) {
            throw DeviceException::signatureInvalid();
        }

        // Zneplatněné `deviceId` se znovu neregistruje — nová registrace
        // téhož zařízení znamená nové `deviceId` (kontrakt §7.3).
        $existing = $this->db->fetchRow(
            'SELECT id, docState FROM core_devices_devices WHERE device_id = %s',
            $input['deviceId'],
        );
        if ($existing !== null) {
            throw (int) $existing['docState'] === DeviceDocument::STATE_ACTIVE
                ? DeviceException::exists()
                : DeviceException::revoked();
        }

        if (!$this->challenges->consume($input['challenge'], $input['deviceId'], DevicePurpose::Register)) {
            throw DeviceException::challengeInvalid();
        }
        if (!$key->verify(DevicePurpose::Register->value, $input['deviceId'], $input['challenge'], $input['signature'])) {
            throw DeviceException::signatureInvalid();
        }

        try {
            $this->db->insertRow(DeviceDocument::TABLE, [
                'device_id'     => $input['deviceId'],
                'name'          => $input['name'],
                'mode'          => $input['mode'],
                'platform'      => $input['platform'],
                'app_version'   => $input['appVersion'],
                'public_key'    => $input['publicKey'],
                'key_algorithm' => DeviceKey::ALGORITHM,
                'key_storage'   => $input['keyStorage'],
                'registered_by' => $adminUserId,
                'registered_at' => ($this->clock)(),
                'docState'      => DeviceDocument::STATE_ACTIVE,
                'docStateMain'  => DeviceDocument::STATE_MAIN_ACTIVE,
            ]);
        } catch (\Dibi\UniqueConstraintViolationException) {
            // Souběžná registrace téhož zařízení — vyhrála ta druhá.
            throw DeviceException::exists();
        }

        return [
            'deviceId' => $input['deviceId'],
            'name'     => $input['name'],
            'mode'     => $input['mode'],
            'state'    => 'active',
        ];
    }

    /**
     * Zneplatní aktivní zařízení a zahodí jeho výzvy. Konečné.
     *
     * @param int $id ID řádku zařízení
     * @param ?int $userId kdo zneplatnil; null = příkazová řádka
     * @return bool false = zařízení neexistuje nebo už aktivní není
     */
    public function revoke(int $id, ?int $userId): bool
    {
        $this->db->execute(
            'UPDATE core_devices_devices
             SET docState = %i, docStateMain = %i, revoked_at = %dt, revoked_by = %i
             WHERE id = %i AND docState = %i',
            DeviceDocument::STATE_REVOKED,
            DeviceDocument::STATE_MAIN_REVOKED,
            ($this->clock)(),
            $userId,
            $id,
            DeviceDocument::STATE_ACTIVE,
        );
        if ($this->db->getAffectedRows() !== 1) {
            return false;
        }

        $row = $this->db->fetchRow('SELECT device_id FROM core_devices_devices WHERE id = %i', $id);
        if ($row !== null) {
            $this->challenges->forgetDevice((string) $row['device_id']);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{deviceId: string, publicKey: string, challenge: string, signature: string,
     *               name: string, mode: string, platform: string, appVersion: ?string, keyStorage: ?string}
     * @throws DeviceException `VALIDATION_ERROR` se všemi nálezy
     */
    private function validated(array $body): array
    {
        $errors = [];
        $fail = static function (string $field, string $message) use (&$errors): void {
            $errors[] = ['field' => $field, 'message' => $message];
        };

        if (!DeviceId::isValid($body['deviceId'] ?? null)) {
            $fail('deviceId', "Field 'deviceId' must be a UUID");
        }
        if (($body['algorithm'] ?? null) !== DeviceKey::ALGORITHM) {
            $fail('algorithm', "Field 'algorithm' must be " . DeviceKey::ALGORITHM);
        }
        foreach (['publicKey', 'challenge', 'signature'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                $fail($field, "Field '{$field}' is required");
            }
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '') {
            $fail('name', "Field 'name' is required");
        } elseif (mb_strlen($name) > DeviceDocument::NAME_MAX_LENGTH) {
            $fail('name', "Field 'name' must have at most " . DeviceDocument::NAME_MAX_LENGTH . ' characters');
        }

        $mode = $body['mode'] ?? null;
        if (!is_string($mode) || !DeviceModes::fromConfig($this->config)->isValid($mode)) {
            $fail('mode', "Field 'mode' must be one of: " . implode(', ', DeviceModes::fromConfig($this->config)->ids()));
        }

        $platform = $body['platform'] ?? null;
        if (!is_string($platform) || !in_array($platform, self::PLATFORMS, true)) {
            $fail('platform', "Field 'platform' must be one of: " . implode(', ', self::PLATFORMS));
        }

        $appVersion = $body['appVersion'] ?? null;
        if ($appVersion !== null
            && (!is_string($appVersion) || $appVersion === '' || strlen($appVersion) > self::APP_VERSION_MAX_LENGTH)) {
            $fail('appVersion', "Field 'appVersion' must be a text of at most " . self::APP_VERSION_MAX_LENGTH . ' characters');
        }

        $keyStorage = $body['keyStorage'] ?? null;
        if ($keyStorage !== null && (!is_string($keyStorage) || !in_array($keyStorage, self::KEY_STORAGES, true))) {
            $fail('keyStorage', "Field 'keyStorage' must be one of: " . implode(', ', self::KEY_STORAGES));
        }

        if ($errors !== []) {
            throw DeviceException::validation($errors);
        }

        return [
            'deviceId'   => (string) $body['deviceId'],
            'publicKey'  => (string) $body['publicKey'],
            'challenge'  => (string) $body['challenge'],
            'signature'  => (string) $body['signature'],
            'name'       => $name,
            'mode'       => (string) $mode,
            'platform'   => (string) $platform,
            'appVersion' => $appVersion,
            'keyStorage' => $keyStorage,
        ];
    }
}
