<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Jednorázové výzvy pro podpis zařízení (#177 U20, kontrakt
 * docs/native-bridge.md §7.1): 32 náhodných bajtů v base64url, platnost
 * 60 s, vázané na účel a zařízení, nejvýš 5 nevyužitých platných výzev na
 * zařízení.
 *
 * Spotřeba je atomický `UPDATE … WHERE used_at IS NULL` rozhodnutý počtem
 * ovlivněných řádků (vzor `AuthTokenService::consume`) — ze souběžných
 * pokusů o tutéž výzvu uspěje jeden. Kdo smí o výzvu žádat (session
 * administrátora u registrace, aktivní zařízení jinak), rozhoduje volající.
 */
final class ChallengeService
{
    public const string TABLE = 'core_devices_challenges';

    public const int TTL_SECONDS = 60;
    public const int MAX_OPEN = 5;

    private const int CHALLENGE_BYTES = 32;
    /** 32 bajtů v base64url bez zarovnání. */
    private const string CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock čas pro testy */
    public function __construct(
        private readonly DataSourceConnection $db,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Vydá výzvu pro zařízení a účel.
     *
     * @throws DeviceException `RATE_LIMITED`, když má zařízení už 5 nevyužitých platných výzev
     */
    public function issue(string $deviceId, DevicePurpose $purpose): string
    {
        $now = ($this->clock)();

        $open = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_devices_challenges
             WHERE device_id = %s AND used_at IS NULL AND expires_at > %dt',
            $deviceId,
            $now,
        );
        if ($open >= self::MAX_OPEN) {
            throw DeviceException::rateLimited();
        }

        $challenge = rtrim(strtr(base64_encode(random_bytes(self::CHALLENGE_BYTES)), '+/', '-_'), '=');
        $this->db->insertRow(self::TABLE, [
            'challenge'  => $challenge,
            'device_id'  => $deviceId,
            'purpose'    => $purpose->value,
            'created_at' => $now,
            'expires_at' => $now->modify('+' . self::TTL_SECONDS . ' seconds'),
        ]);

        return $challenge;
    }

    /**
     * Spotřebuje výzvu. False = výzva neexistuje, vypršela, už byla použitá,
     * nebo patří jinému účelu či zařízení — volající rozdíl nepozná a nemá.
     */
    public function consume(string $challenge, string $deviceId, DevicePurpose $purpose): bool
    {
        if (preg_match(self::CHALLENGE_PATTERN, $challenge) !== 1) {
            return false;
        }

        $now = ($this->clock)();
        $this->db->execute(
            'UPDATE core_devices_challenges SET used_at = %dt
             WHERE challenge = %s AND device_id = %s AND purpose = %s
               AND used_at IS NULL AND expires_at > %dt',
            $now,
            $challenge,
            $deviceId,
            $purpose->value,
            $now,
        );

        return $this->db->getAffectedRows() === 1;
    }

    /** Smaže použité a prošlé výzvy (úloha cronu modulu). Vrací počet smazaných. */
    public function prune(): int
    {
        $this->db->execute(
            'DELETE FROM core_devices_challenges WHERE used_at IS NOT NULL OR expires_at < %dt',
            ($this->clock)(),
        );
        return $this->db->getAffectedRows();
    }

    /** Zahodí všechny výzvy zařízení — po zneplatnění nesmí žádná zůstat použitelná. */
    public function forgetDevice(string $deviceId): void
    {
        $this->db->deleteWhere(self::TABLE, 'device_id = %s', $deviceId);
    }
}
