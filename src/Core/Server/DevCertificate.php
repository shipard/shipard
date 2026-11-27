<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Net\LocalAddresses;

/**
 * Certifikát https vstupu vývojového serveru (#183 D6, U4, U5): čtení
 * `fullchain.pem` (platnost, SAN) pro `dev-https` a `doctor`, a předpis
 * self-signed certifikátu pro IP, který vyrábí `openssl` CLI.
 *
 * Čte se jen certifikát; soukromý klíč smí číst jen root
 * ({@see matchesKeyFile()} vrací null, když na něj proces nedosáhne).
 */
final class DevCertificate
{
    public const string OK = 'ok';
    public const string MISSING = 'missing';
    public const string UNREADABLE = 'unreadable';
    public const string INVALID = 'invalid';

    /** Platnost nově vyrobeného certifikátu pro IP ve dnech (U5). */
    public const int VALIDITY_DAYS = 397;
    /** `dev-https` obnoví certifikát pro IP, když zbývá méně dní (U5). */
    public const int RENEW_BEFORE_DAYS = 30;
    /** `doctor` varuje, když zbývá méně dní (U1). */
    public const int WARN_BEFORE_DAYS = 14;

    /**
     * @param list<string> $ips adresy ze SAN v kanonickém tvaru
     * @param list<string> $names jména ze SAN malými písmeny
     */
    private function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $notAfter = null,
        public readonly array $ips = [],
        public readonly array $names = [],
        private readonly ?string $pem = null,
    ) {
    }

    /**
     * Přečte certifikát ze souboru (první certifikát řetězu). Symlink se
     * následuje; nedosažitelný cíl (adresář jen pro root) = `unreadable`.
     */
    public static function read(string $path): self
    {
        if (!file_exists($path)) {
            return new self($path, is_link($path) ? self::UNREADABLE : self::MISSING);
        }
        if (!is_readable($path)) {
            return new self($path, self::UNREADABLE);
        }
        $pem = @file_get_contents($path);
        if ($pem === false) {
            return new self($path, self::UNREADABLE);
        }
        return self::fromPem($pem, $path);
    }

    public static function fromPem(string $pem, string $path = ''): self
    {
        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return new self($path, self::INVALID);
        }
        $ips = [];
        $names = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        foreach (is_string($san) ? explode(',', $san) : [] as $item) {
            $item = trim($item);
            if (str_starts_with($item, 'IP Address:')) {
                $ip = LocalAddresses::normalizeIp(substr($item, 11));
                if ($ip !== null && !in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            } elseif (str_starts_with($item, 'DNS:')) {
                $name = strtolower(trim(substr($item, 4)));
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        $notAfter = (new \DateTimeImmutable('@' . (int) $parsed['validTo_time_t']))
            ->setTimezone(new \DateTimeZone('UTC'));

        return new self($path, self::OK, $notAfter, $ips, $names, $pem);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /** Celé dny do konce platnosti (záporné po vypršení); null bez certifikátu. */
    public function daysLeft(\DateTimeImmutable $now): ?int
    {
        if ($this->notAfter === null) {
            return null;
        }
        return (int) floor(($this->notAfter->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->notAfter !== null && $this->notAfter->getTimestamp() <= $now->getTimestamp();
    }

    /**
     * Adresy serveru, které v SAN **chybí** (#183 U20: adresa v certifikátu
     * navíc nevadí — zaniklá dočasná IPv6 obnovu nespouští).
     *
     * @param list<string> $addresses kanonické adresy
     * @return list<string>
     */
    public function missingIps(array $addresses): array
    {
        return array_values(array_diff($addresses, $this->ips));
    }

    /** SAN nese jméno — přesně, nebo zástupným znakem pro jeden štítek (`*.example.com`). */
    public function coversName(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->names as $name) {
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $dot = strpos($host, '.');
                if ($dot !== false && $dot > 0 && substr($host, $dot) === substr($name, 1)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Patří soukromý klíč k certifikátu? Null = klíč není čitelný (běh bez
     * roota) nebo není certifikát — nejde rozhodnout.
     */
    public function matchesKeyFile(string $keyPath): ?bool
    {
        if ($this->pem === null || !is_readable($keyPath)) {
            return null;
        }
        $key = @file_get_contents($keyPath);
        if ($key === false) {
            return null;
        }
        return @openssl_x509_check_private_key($this->pem, $key);
    }

    /**
     * Konfigurace `openssl req` pro self-signed certifikát pro IP (U5): SAN =
     * adresy serveru, `CA:FALSE`, `serverAuth`, CN `Shipard dev <hostname>`.
     * Vlastní soubor místo `-addext`, aby rozšíření nezávisela na systémovém
     * `openssl.cnf` (ten k `req -x509` přidává `CA:TRUE`).
     *
     * @param list<string> $addresses kanonické adresy, aspoň jedna
     */
    public static function opensslConfig(string $hostname, array $addresses): string
    {
        $hostname = preg_replace('/[^A-Za-z0-9.-]/', '-', $hostname) ?? '';
        $cn = substr('Shipard dev ' . ($hostname !== '' ? $hostname : 'server'), 0, 64);

        $san = '';
        foreach (array_values($addresses) as $i => $address) {
            $san .= 'IP.' . ($i + 1) . ' = ' . $address . "\n";
        }

        return "[req]\n"
            . "distinguished_name = dn\n"
            . "x509_extensions = ext\n"
            . "prompt = no\n"
            . "\n[dn]\n"
            . 'CN = ' . $cn . "\n"
            . "\n[ext]\n"
            . "basicConstraints = critical, CA:FALSE\n"
            . "keyUsage = critical, digitalSignature\n"
            . "extendedKeyUsage = serverAuth\n"
            . "subjectAltName = @san\n"
            . "\n[san]\n"
            . $san;
    }

    /**
     * Argumenty `openssl` (bez shellu): klíč EC P-256 bez hesla a certifikát
     * na {@see VALIDITY_DAYS} dní.
     *
     * @return list<string>
     */
    public static function opensslArgs(string $configFile, string $keyFile, string $certFile): array
    {
        return [
            'openssl', 'req', '-x509',
            '-newkey', 'ec', '-pkeyopt', 'ec_paramgen_curve:prime256v1',
            '-nodes', '-sha256',
            '-days', (string) self::VALIDITY_DAYS,
            '-config', $configFile,
            '-keyout', $keyFile,
            '-out', $certFile,
        ];
    }
}
