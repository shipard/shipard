<?php
declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Vznik a zánik session tokenů `shpd_st_`. Jediné místo, které sessions
 * zapisuje — sdílí ho lokální login (AuthController), OIDC callback
 * (OidcController) i break-glass CLI (auth-emergency-login). Čtení a kontrola
 * expirace zůstává v AuthMiddleware.
 */
class SessionService
{
	public const int SESSION_TTL_SECONDS = 86400; // 24 hours

	/**
	 * @param ?int $ttlSeconds platnost session; null = {@see SESSION_TTL_SECONDS}
	 *        (24 h). Kratší nebo delší TTL dává jen volající s vlastním důvodem
	 *        — proklikání datové sady (`dataset-e2e`, #103 F7a I7).
	 * @return array{0: string, 1: string} [token, expires_at ISO-8601]
	 */
	public function createSession(int $userId, DataSourceConnection $db, ?string $clientIp = null, ?int $ttlSeconds = null): array
	{
		$ttl = $ttlSeconds ?? self::SESSION_TTL_SECONDS;
		if ($ttl <= 0) {
			throw new \InvalidArgumentException('Session TTL must be a positive number of seconds');
		}
		$token = 'shpd_st_' . $this->generateToken(48);
		$expiresAt = date('c', time() + $ttl);

		$db->insertRow('core_system_sessions', [
			'user_id'    => $userId,
			'token'      => $token,
			'ip_address' => $clientIp,
			'created'    => date('Y-m-d H:i:s'),
			'expires'    => date('Y-m-d H:i:s', time() + $ttl),
		]);

		return [$token, $expiresAt];
	}

	public function invalidateSession(string $token, DataSourceConnection $db): void
	{
		$db->execute(
			'DELETE FROM core_system_sessions WHERE token = %s',
			$token,
		);
	}

	/** Zneplatní všechny sessions uživatele (reset hesla). Vrací počet. */
	public function invalidateAllForUser(int $userId, DataSourceConnection $db): int
	{
		$db->execute(
			'DELETE FROM core_system_sessions WHERE user_id = %i',
			$userId,
		);
		return $db->getAffectedRows();
	}

	/**
	 * Zneplatní všechny sessions uživatele kromě aktuální (změna hesla,
	 * „odhlásit ostatní zařízení"). Vrací počet.
	 */
	public function invalidateOthers(int $userId, string $currentToken, DataSourceConnection $db): int
	{
		$db->execute(
			'DELETE FROM core_system_sessions WHERE user_id = %i AND token != %s',
			$userId,
			$currentToken,
		);
		return $db->getAffectedRows();
	}

	public function generateToken(int $length): string
	{
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$charCount = strlen($chars);
		$result = '';
		for ($i = 0; $i < $length; $i++) {
			$result .= $chars[random_int(0, $charCount - 1)];
		}
		return $result;
	}
}
