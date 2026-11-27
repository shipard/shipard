<?php

declare(strict_types=1);

namespace Shipard\Core\Net;

/**
 * Adresy tohoto serveru a pravidlo „adresa míří na tento server“ (#103 D97,
 * #183 U15 — jediné místo): používá ho proklikání a videa (`LocalBaseUrl`),
 * vstupy vývojového serveru (`DevAccess`), certifikát pro IP (`dev-https`)
 * i `doctor`.
 *
 * Jména překládá systémový resolver (`socket_addrinfo_lookup`, tedy i
 * `/etc/hosts` — stejně jako Chromium a curl; `dns_get_record` by hosts
 * obešel, `gethostbynamel` nezná IPv6). Adresy rozhraní dává
 * `net_get_interfaces()`; IPv6 s rozsahem (`fe80::1%eth0`) se porovnává
 * bez `%scope`, IPv4 mapované do IPv6 (`::ffff:127.0.0.1`) jako IPv4.
 * Oba zdroje jsou ševy pro testy.
 */
final class LocalAddresses
{
    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolve;
    /** @var \Closure(): list<string> */
    private readonly \Closure $interfaces;

    /**
     * @param ?\Closure(string): list<string> $resolve host → všechny adresy; null = systémový resolver
     * @param ?\Closure(): list<string> $interfaces adresy rozhraní serveru; null = `net_get_interfaces()`
     */
    public function __construct(?\Closure $resolve = null, ?\Closure $interfaces = null)
    {
        $this->resolve = $resolve ?? self::systemResolver(...);
        $this->interfaces = $interfaces ?? self::interfaceAddresses(...);
    }

    /**
     * Všechny adresy rozhraní v kanonickém tvaru, bez opakování (i loopback
     * a link-local).
     *
     * @return list<string>
     */
    public function all(): array
    {
        $out = [];
        foreach (($this->interfaces)() as $address) {
            $normalized = is_string($address) ? self::normalizeIp($address) : null;
            if ($normalized !== null && !in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * Adresy, na kterých je server dosažitelný zvenku: bez loopbacku
     * a link-local, IPv4 před IPv6, jinak v pořadí rozhraní.
     *
     * @return list<string>
     */
    public function reachable(): array
    {
        $v4 = [];
        $v6 = [];
        foreach ($this->all() as $address) {
            if (self::isLoopback($address) || self::isLinkLocal($address)) {
                continue;
            }
            if (self::isIpv4($address)) {
                $v4[] = $address;
            } else {
                $v6[] = $address;
            }
        }
        return [...$v4, ...$v6];
    }

    /**
     * Globální IPv6 adresy rozhraní (#183 U27) — adresy, na které může
     * dosáhnout internet, pokud je síť nefiltruje: IPv6 bez loopbacku,
     * link-local a ULA (`fc00::/7`).
     *
     * @return list<string>
     */
    public function globalIpv6(): array
    {
        $out = [];
        foreach ($this->all() as $address) {
            if (self::isIpv4($address) || self::isLoopback($address)
                || self::isLinkLocal($address) || self::isUniqueLocal($address)) {
                continue;
            }
            $out[] = $address;
        }
        return $out;
    }

    /**
     * Adresy hostu v kanonickém tvaru, bez opakování: IP literál je sám
     * sebou, jméno přeloží resolver (A i AAAA). Prázdný seznam = jméno
     * nejde přeložit.
     *
     * @return list<string>
     */
    public function addressesOf(string $host): array
    {
        $host = trim($host, '[]');
        $literal = self::normalizeIp($host);
        if ($literal !== null) {
            return [$literal];
        }
        $out = [];
        foreach (($this->resolve)($host) as $address) {
            $normalized = is_string($address) ? self::normalizeIp($address) : null;
            if ($normalized !== null && !in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * Které z adres **nemíří** na tento server — nejsou loopback ani adresa
     * vlastního rozhraní. Prázdný seznam = všechny míří sem.
     *
     * @param list<string> $addresses kanonické adresy ({@see addressesOf()})
     * @return list<string>
     */
    public function foreign(array $addresses): array
    {
        $local = array_fill_keys($this->all(), true);
        $out = [];
        foreach ($addresses as $address) {
            if (!self::isLoopback($address) && !isset($local[$address])) {
                $out[] = $address;
            }
        }
        return $out;
    }

    /** Loopback: `127.0.0.0/8` nebo `::1` (adresa už normalizovaná). */
    public static function isLoopback(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    /** Link-local: `169.254.0.0/16` nebo `fe80::/10` (adresa už normalizovaná). */
    public static function isLinkLocal(string $ip): bool
    {
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }
        $binary = @inet_pton($ip);
        return is_string($binary) && strlen($binary) === 16
            && ord($binary[0]) === 0xfe && (ord($binary[1]) & 0xc0) === 0x80;
    }

    /** Unikátní lokální adresa IPv6 (ULA, `fc00::/7`; adresa už normalizovaná). */
    public static function isUniqueLocal(string $ip): bool
    {
        $binary = @inet_pton($ip);
        return is_string($binary) && strlen($binary) === 16 && (ord($binary[0]) & 0xfe) === 0xfc;
    }

    public static function isIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
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

    /** Origin z adresy: IPv6 v hranatých závorkách (`https://[2001:db8::7]`). */
    public static function originHost(string $ip): string
    {
        return self::isIpv4($ip) || self::normalizeIp($ip) === null ? $ip : '[' . $ip . ']';
    }
}
