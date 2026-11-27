<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Ověření podepsaného volání zařízení (#177 U4, kontrakt
 * docs/native-bridge.md §7.1) — bez session, jen podpisem výzvy klíčem
 * registrovaného zařízení. Hodnoty hlaviček předává controller, účel určuje
 * routa (ne klient).
 *
 * Pořadí: zařízení existuje → je aktivní → výzva se spotřebuje → podpis
 * sedí. Výzva se spotřebuje **před** ověřením podpisu, takže jedna výzva
 * dává jeden pokus. Po úspěchu se zapíše `last_seen_at` a verze aplikace
 * z nepovinné hlavičky `X-Shipard-App-Version` (U25).
 */
final class DeviceRequestAuthenticator
{
    public const string HEADER_DEVICE = 'x-shipard-device';
    public const string HEADER_CHALLENGE = 'x-shipard-challenge';
    public const string HEADER_SIGNATURE = 'x-shipard-signature';
    public const string HEADER_APP_VERSION = 'x-shipard-app-version';

    /** Verze aplikace: číslice, písmena a oddělovače, nejvýš jako sloupec `app_version`. */
    private const string APP_VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/D';

    private readonly ChallengeService $challenges;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy */
    public function __construct(
        private readonly DataSourceConnection $db,
        ?ChallengeService $challenges = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->challenges = $challenges ?? new ChallengeService($db, $this->clock);
    }

    /**
     * @param ?string $deviceId   hlavička `X-Shipard-Device`
     * @param ?string $challenge  hlavička `X-Shipard-Challenge`
     * @param ?string $signature  hlavička `X-Shipard-Signature`
     * @param ?string $appVersion hlavička `X-Shipard-App-Version` (nepovinná)
     *
     * @throws DeviceException `DEVICE_UNKNOWN`, `DEVICE_REVOKED`, `CHALLENGE_INVALID`, `SIGNATURE_INVALID`
     */
    public function authenticate(
        DevicePurpose $purpose,
        ?string $deviceId,
        ?string $challenge,
        ?string $signature,
        ?string $appVersion = null,
    ): DeviceContext {
        $row = $this->activeDevice($deviceId);

        if ($challenge === null || $challenge === ''
            || !$this->challenges->consume($challenge, (string) $row['device_id'], $purpose)) {
            throw DeviceException::challengeInvalid();
        }

        $key = DeviceKey::fromSpkiBase64((string) ($row['public_key'] ?? ''));
        if ($key === null || $signature === null
            || !$key->verify($purpose->value, (string) $row['device_id'], $challenge, $signature)) {
            throw DeviceException::signatureInvalid();
        }

        $seen = ['last_seen_at' => ($this->clock)()];
        if ($appVersion !== null && preg_match(self::APP_VERSION_PATTERN, $appVersion) === 1) {
            $seen['app_version'] = $appVersion;
        }
        $this->db->updateWhere(DeviceDocument::TABLE, $seen, 'id = %i', (int) $row['id']);

        return DeviceContext::fromRow($row);
    }

    /**
     * Registrované aktivní zařízení — i pro vydání výzvy (U20): neznámému
     * a zneplatněnému zařízení server výzvu vůbec nevydá.
     *
     * @return array<string, mixed> řádek `core_devices_devices`
     * @throws DeviceException `DEVICE_UNKNOWN`, `DEVICE_REVOKED`
     */
    public function activeDevice(?string $deviceId): array
    {
        if (!DeviceId::isValid($deviceId)) {
            throw DeviceException::unknownDevice();
        }
        $row = $this->db->fetchRow('SELECT * FROM core_devices_devices WHERE device_id = %s', $deviceId);
        if ($row === null) {
            throw DeviceException::unknownDevice();
        }
        if ((int) ($row['docState'] ?? 0) !== DeviceDocument::STATE_ACTIVE) {
            throw DeviceException::revoked();
        }
        return $row;
    }
}
