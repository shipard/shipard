<?php
declare(strict_types=1);

namespace Shipard\Api;

/**
 * Základní adresa zdroje dat, jak ji vidí prohlížeč (#183 D2, jediné místo):
 * schéma z požadavku ({@see Request::getScheme()} — `X-Forwarded-Proto`,
 * `HTTPS`), host tak, jak ho prohlížeč poslal (i s portem), a při adresování
 * cestou prefix `/<ID>`. Bez lomítka na konci.
 *
 * Schéma se nikdy neodvozuje z režimu serveru ani z toho, zda je zdroj dat
 * adresovaný cestou — vývojový server má vstupy http i https (D3).
 */
final class RequestBaseUrl
{
	public static function for(Request $request, ResolvedDataSource $resolved): string
	{
		return self::of($request, $resolved->config->getId(), $resolved->isPathAddressed());
	}

	/** Pro kontrolery, které dostávají jen ID zdroje dat a příznak adresování cestou. */
	public static function of(Request $request, string $dsId, bool $pathAddressed): string
	{
		return $request->getScheme() . '://' . $request->getHost()
			. ($pathAddressed ? '/' . $dsId : '');
	}
}
