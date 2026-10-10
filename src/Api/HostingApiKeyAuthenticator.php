<?php

declare(strict_types=1);

namespace Shipard\Api;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Validace hosting API klíčů `shpd_hk_…` — sdílená serverovými
 * (`hosting_core_servers`) a mail-routerovými (`hosting_core_mail_routers`)
 * endpointy. Tabulky nesou stejné sloupce `api_key_prefix`, `api_key_hash`,
 * `last_seen`, `docState`; liší se jen názvem.
 *
 * Prefix lookup + `hash_equals` nad sha256 celého tokenu (vzor
 * AuthMiddleware::handleApiKey); hash je nad tokenem VČETNĚ prefixu
 * `shpd_hk_`, sloupec prefix drží prvních 12 znaků náhodné části.
 * Úspěch aktualizuje `last_seen` a vrací celý řádek. Klíče plní CLI
 * `hosting-server-key` / `hosting-router-key`.
 */
final class HostingApiKeyAuthenticator
{
    public const TOKEN_PREFIX = 'shpd_hk_';
    public const KEY_PREFIX_LENGTH = 12;

    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    public const ACTIVE_DOC_STATES = [10, 40, 80];

    public function __construct(
        private readonly string $table,
        private readonly string $errorMessage = 'API key required',
        private readonly string $invalidMessage = 'Invalid API key',
    ) {}

    /**
     * Bearer shpd_hk_… → řádek zadané tabulky, jinak 401. Update last_seen.
     *
     * @return array<string, mixed>|Response
     */
    public function authenticate(Request $request, DataSourceConnection $db): array|Response
    {
        $header = (string) ($request->getHeader('authorization') ?? '');
        if (!str_starts_with($header, 'Bearer ')) {
            return Response::error('UNAUTHORIZED', $this->errorMessage, 401);
        }
        $token = trim(substr($header, strlen('Bearer ')));
        if (!str_starts_with($token, self::TOKEN_PREFIX)) {
            return Response::error('UNAUTHORIZED', $this->errorMessage, 401);
        }

        $keyPart = substr($token, strlen(self::TOKEN_PREFIX));
        $prefix = substr($keyPart, 0, self::KEY_PREFIX_LENGTH);
        $row = $db->fetchRow(
            'SELECT * FROM ' . $this->table . ' WHERE api_key_prefix = %s',
            $prefix,
        );
        if ($row === null
            || ($row['api_key_hash'] ?? null) === null
            || !hash_equals((string) $row['api_key_hash'], hash('sha256', $token))
            || !in_array((int) $row['docState'], self::ACTIVE_DOC_STATES, true)
        ) {
            return Response::error('UNAUTHORIZED', $this->invalidMessage, 401);
        }

        $db->updateWhere(
            $this->table,
            ['last_seen' => date('Y-m-d H:i:s')],
            'id = %i',
            (int) $row['id'],
        );

        return $row;
    }
}
