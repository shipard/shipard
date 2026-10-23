<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

/**
 * Pravidlo lokální adresy běhu proklikání (#103 D97, jediné místo — používá
 * ho `shpd-ds dataset-e2e` i akce Proklikat dashboardu): host z `--base-url`
 * musí být IP literál, nebo jméno, jehož **všechny** adresy (A i AAAA) jsou
 * loopback (`127.0.0.0/8`, `::1`) nebo adresa vlastního síťového rozhraní.
 * Jinak by Playwright mohl odnést token session uživatele `e2e` na cizí
 * host (S1). Ověřená adresa se vrací jako {@see PinnedBaseUrl} k připíchnutí.
 *
 * Jména překládá systémový resolver (`socket_addrinfo_lookup`, tedy i
 * `/etc/hosts` — stejně jako Chromium a curl; `dns_get_record` by hosts
 * obešel, `gethostbynamel` nezná IPv6). Adresy rozhraní dává
 * `net_get_interfaces()`; IPv6 s rozsahem (`fe80::1%eth0`) se porovnává
 * bez `%scope`, IPv4 mapované do IPv6 (`::ffff:127.0.0.1`) jako IPv4.
 * Oba zdroje jsou ševy pro testy.
 */
final class LocalBaseUrl
{
    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolve;
    /** @var \Closure(): list<string> */
    private readonly \Closure $localAddresses;

    /**
     * @param ?\Closure(string): list<string> $resolve host → všechny adresy; null = systémový resolver
     * @param ?\Closure(): list<string> $localAddresses adresy rozhraní serveru; null = `net_get_interfaces()`
     */
    public function __construct(?\Closure $resolve = null, ?\Closure $localAddresses = null)
    {
        $this->resolve = $resolve ?? self::systemResolver(...);
        $this->localAddresses = $localAddresses ?? self::interfaceAddresses(...);
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

        $literal = self::normalizeIp($host);
        if ($literal !== null) {
            $addresses = [$literal];
            $isLiteral = true;
        } else {
            $addresses = [];
            foreach (($this->resolve)($host) as $address) {
                $normalized = is_string($address) ? self::normalizeIp($address) : null;
                if ($normalized !== null && !in_array($normalized, $addresses, true)) {
                    $addresses[] = $normalized;
                }
            }
            if ($addresses === []) {
                throw new E2eException("Jméno '{$host}' z --base-url nejde přeložit na adresu — proklikání jde jen proti tomuto serveru (IP nebo jméno s lokální adresou)");
            }
            $isLiteral = false;
        }

        $local = [];
        foreach (($this->localAddresses)() as $address) {
            $normalized = is_string($address) ? self::normalizeIp($address) : null;
            if ($normalized !== null) {
                $local[$normalized] = true;
            }
        }
        foreach ($addresses as $address) {
            if (!self::isLoopback($address) && !isset($local[$address])) {
                throw new E2eException(
                    "Adresa {$address} (host '{$host}' z --base-url) není loopback ani adresa rozhraní tohoto serveru"
                    . ' — proklikání jde jen proti lokálnímu serveru, token session by jinak odešel jinam',
                );
            }
        }

        $pin = $addresses[0];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $pin = $address;
                break;
            }
        }

        return new PinnedBaseUrl($origin, $host, $port, $pin, $isLiteral);
    }

    /** Loopback: `127.0.0.0/8` nebo `::1` (adresa už normalizovaná). */
    public static function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    /**
     * Kanonický tvar adresy: bez `%scope`, IPv4 mapovaná do IPv6 jako IPv4,
     * IPv6 zkrácená (`inet_ntop`). Null = není IP adresa.
     */
    public static function normalizeIp(string $ip): ?string
    {
        $ip = trim(explode('%', $ip, 2)[0], '[]');
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && substr($binary, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            $binary = substr($binary, 12);
        }
        $text = @inet_ntop($binary);
        return $text === false ? null : $text;
    }

    /**
     * Všechny adresy jména systémovým resolverem (getaddrinfo: `/etc/hosts`
     * i DNS, A i AAAA). Bez `ext-sockets` jen IPv4 přes `gethostbynamel`.
     *
     * @return list<string>
     */
    public static function systemResolver(string $host): array
    {
        if (!function_exists('socket_addrinfo_lookup')) {
            return array_values(gethostbynamel($host) ?: []);
        }
        $infos = @socket_addrinfo_lookup($host, null, ['ai_socktype' => SOCK_STREAM]);
        if ($infos === false) {
            return [];
        }
        $out = [];
        foreach ($infos as $info) {
            $explained = socket_addrinfo_explain($info);
            $address = $explained['ai_addr']['sin_addr'] ?? $explained['ai_addr']['sin6_addr'] ?? null;
            if (is_string($address) && $address !== '' && !in_array($address, $out, true)) {
                $out[] = $address;
            }
        }
        return $out;
    }

    /**
     * Unicast adresy všech rozhraní (`net_get_interfaces()`), i link-local
     * a s rozsahem — normalizuje až {@see normalizeIp()}.
     *
     * @return list<string>
     */
    public static function interfaceAddresses(): array
    {
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return [];
        }
        $out = [];
        foreach ($interfaces as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                if (is_array($unicast) && isset($unicast['address']) && is_string($unicast['address'])) {
                    $out[] = $unicast['address'];
                }
            }
        }
        return $out;
    }
}
