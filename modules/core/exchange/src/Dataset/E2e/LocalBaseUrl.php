<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Core\Net\LocalAddresses;

/**
 * Pravidlo lokální adresy běhu proklikání (#103 D97 — používá ho
 * `shpd-ds dataset-e2e` i akce Proklikat dashboardu): host z `--base-url`
 * musí být IP literál, nebo jméno, jehož **všechny** adresy (A i AAAA) jsou
 * loopback (`127.0.0.0/8`, `::1`) nebo adresa vlastního síťového rozhraní.
 * Jinak by Playwright mohl odnést token session uživatele `e2e` na cizí
 * host (S1). Ověřená adresa se vrací jako {@see PinnedBaseUrl} k připíchnutí.
 *
 * Adresy rozhraní, překlad jmen a samotné pravidlo „adresa míří na tento
 * server“ žijí v jádře ({@see LocalAddresses}, #183 U15) — sdílí je vstupy
 * vývojového serveru a `doctor`. Tady zůstává jen čtení `--base-url`, věty
 * preflightu a volba adresy k připíchnutí. Oba zdroje jsou ševy pro testy.
 */
final class LocalBaseUrl
{
    private readonly LocalAddresses $local;

    /**
     * @param ?\Closure(string): list<string> $resolve host → všechny adresy; null = systémový resolver
     * @param ?\Closure(): list<string> $localAddresses adresy rozhraní serveru; null = `net_get_interfaces()`
     */
    public function __construct(?\Closure $resolve = null, ?\Closure $localAddresses = null)
    {
        $this->local = new LocalAddresses($resolve, $localAddresses);
    }

    /**
     * Ověří adresu serveru a vrátí ji s připíchnutou IP.
     *
     * @throws E2eException věta pro preflight (odmítnutí před založením session)
     */
    public function verify(string $baseUrl): PinnedBaseUrl
    {
        $url = rtrim(trim($baseUrl), '/');
        $parts = $url === '' ? false : parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (
            !is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || !isset($parts['host']) || $parts['host'] === ''
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new E2eException("--base-url musí být absolutní http(s) adresa serveru bez cesty (např. http://127.0.0.1), ne '{$baseUrl}'");
        }

        $hostRaw = strtolower($parts['host']);
        $host = trim($hostRaw, '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $origin = $scheme . '://' . $hostRaw . (isset($parts['port']) ? ':' . $port : '');

        $isLiteral = LocalAddresses::normalizeIp($host) !== null;
        $addresses = $this->local->addressesOf($host);
        if ($addresses === []) {
            throw new E2eException("Jméno '{$host}' z --base-url nejde přeložit na adresu — proklikání jde jen proti tomuto serveru (IP nebo jméno s lokální adresou)");
        }

        foreach ($this->local->foreign($addresses) as $address) {
            throw new E2eException(
                "Adresa {$address} (host '{$host}' z --base-url) není loopback ani adresa rozhraní tohoto serveru"
                . ' — proklikání jde jen proti lokálnímu serveru, token session by jinak odešel jinam',
            );
        }

        $pin = $addresses[0];
        foreach ($addresses as $address) {
            if (LocalAddresses::isIpv4($address)) {
                $pin = $address;
                break;
            }
        }

        return new PinnedBaseUrl($origin, $host, $port, $pin, $isLiteral);
    }

    /** @see LocalAddresses::isLoopback() */
    public static function isLoopback(string $ip): bool
    {
        return LocalAddresses::isLoopback($ip);
    }

    /** @see LocalAddresses::normalizeIp() */
    public static function normalizeIp(string $ip): ?string
    {
        return LocalAddresses::normalizeIp($ip);
    }

    /**
     * @see LocalAddresses::systemResolver()
     * @return list<string>
     */
    public static function systemResolver(string $host): array
    {
        return LocalAddresses::systemResolver($host);
    }

    /**
     * @see LocalAddresses::interfaceAddresses()
     * @return list<string>
     */
    public static function interfaceAddresses(): array
    {
        return LocalAddresses::interfaceAddresses();
    }
}
