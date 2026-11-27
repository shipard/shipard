<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Rozhodnutí `shpd-server dev-https` (#183 U3–U6, U20, U21, U26) bez vedlejších
 * účinků — jen čte: certifikáty na pevných cestách, šablonu site souboru,
 * site soubor na disku a ostatní weby nginx. Běží i bez roota (`--check`,
 * `doctor`); shodu klíče s certifikátem ověří jen tam, kde klíč přečte.
 *
 * Cesty a prostředí jsou parametry konstruktoru (ševy pro testy).
 */
final class DevHttpsPlanner
{
    public const string TLS_DIR = '/etc/shipard/tls';
    public const string IP_DIR = 'dev-ip';
    public const string HOST_DIR = 'dev-host';
    public const string CERT_FILE = 'fullchain.pem';
    public const string KEY_FILE = 'privkey.pem';
    /** Site soubory Shipardu na vývojovém serveru: http (`install-packages.sh`) a https (`dev-https`). */
    public const array SHIPARD_SITES = ['shipard.conf', DevHttpsSite::FILE_NAME];

    /**
     * @param ?string $socket socket poolu shipard ({@see FpmPool::shipardSocket()}); null = pool není
     * @param ?bool $ipv6 jádro má IPv6; null = podle `/proc/net/if_inet6`. Řádky `listen [::]`
     *                    vzniknou, jen když to navíc chce `devAccess.ipv6` (#183 D8, U26)
     * @param list<string> $extraNginxGlobs další konfigurace nginx mimo sites-enabled (hledání cizího default_server)
     * @param ?bool $privileged proces čte i soubory jen pro root; null = podle euid
     */
    public function __construct(
        private readonly string $templateFile,
        private readonly ?string $socket,
        private readonly string $tlsDir = self::TLS_DIR,
        private readonly string $sitesAvailableDir = '/etc/nginx/sites-available',
        private readonly string $sitesEnabledDir = '/etc/nginx/sites-enabled',
        private readonly ?bool $ipv6 = null,
        private readonly array $extraNginxGlobs = ['/etc/nginx/conf.d/*.conf'],
        private readonly ?bool $privileged = null,
    ) {
    }

    public static function forServer(string $repoRoot): self
    {
        return new self($repoRoot . '/docs/nginx/development-https.conf', FpmPool::shipardSocket());
    }

    public function certFile(string $entryDir): string
    {
        return $this->tlsDir . '/' . $entryDir . '/' . self::CERT_FILE;
    }

    public function keyFile(string $entryDir): string
    {
        return $this->tlsDir . '/' . $entryDir . '/' . self::KEY_FILE;
    }

    public function tlsDir(): string
    {
        return $this->tlsDir;
    }

    public function plan(DevAccess $access, \DateTimeImmutable $now): DevHttpsPlan
    {
        $siteFile = $this->sitesAvailableDir . '/' . DevHttpsSite::FILE_NAME;
        $siteLink = $this->sitesEnabledDir . '/' . DevHttpsSite::FILE_NAME;
        $currentSite = is_file($siteFile) ? (string) @file_get_contents($siteFile) : null;
        $siteLinked = is_link($siteLink) || is_file($siteLink);

        $blockers = [];
        $warnings = [];

        // ── vstup přes IP ───────────────────────────────────────────────────
        $ipCertificate = DevCertificate::read($this->certFile(self::IP_DIR));
        $ipBlock = false;
        $ipConflict = null;
        $ipCertRenewal = null;
        if ($access->wantsHttpsIp()) {
            $ipConflict = $this->foreignDefaultServer($siteLink);
            if ($ipConflict !== null) {
                $warnings[] = "another nginx site already is the default server on port 443 ({$ipConflict}) — "
                    . 'the https entry on the IP address is not created; remove default_server there, or set devAccess.httpsIp to false';
            } elseif ($access->addresses() === []) {
                $blockers[] = 'no address of this server found for the https entry on the IP address '
                    . '(set devAccess.ip; a server with IPv6 addresses only needs devAccess.ipv6)';
            } else {
                $ipBlock = true;
                $ipCertRenewal = $this->ipRenewalReason($ipCertificate, $access->addresses(), $now);
            }
        }

        // ── vstup přes jméno ────────────────────────────────────────────────
        $host = $access->httpsHost();
        $hostCertificate = null;
        $hostProblem = null;
        if ($host !== null) {
            $hostCertificate = DevCertificate::read($this->certFile(self::HOST_DIR));
            $hostProblem = $this->hostProblem($hostCertificate, $host);
        }
        $hostBlock = $host !== null && $hostProblem === null;
        // Bez roota nejde nečitelný certifikát posoudit (symlink do adresáře
        // jen pro root, U4) — blok se bere tak, jak je na disku, aby `--check`
        // a `doctor` nehlásily zastaralý site soubor jen kvůli právům.
        if ($host !== null && !$this->isPrivileged() && $hostCertificate?->status === DevCertificate::UNREADABLE) {
            $hostBlock = $currentSite !== null
                && str_contains(preg_replace('/#[^\n]*/', '', $currentSite) ?? '', 'server_name ' . $host . ';');
        }

        // ── IPv6 jen na vyžádání a jen s IPv6 v jádře (D8, U26) ─────────────
        $ipv6Unavailable = $access->hasHttpsEntry() && $access->wantsIpv6() && !$this->kernelIpv6();
        if ($ipv6Unavailable) {
            $warnings[] = 'devAccess.ipv6 is on, but IPv6 is not enabled on this server (/proc/net/if_inet6 is missing) — '
                . 'the https entries listen on IPv4 only';
        }
        $ipv6Listen = $access->wantsIpv6() && !$ipv6Unavailable;

        // ── site soubor ─────────────────────────────────────────────────────
        $expectedSite = null;
        if ($ipBlock || $hostBlock) {
            $template = is_file($this->templateFile) ? @file_get_contents($this->templateFile) : false;
            if ($template === false) {
                $blockers[] = 'nginx template not found: ' . $this->templateFile;
            } elseif ($this->socket === null) {
                $blockers[] = 'PHP-FPM pool "shipard" not found (' . FpmPool::CONFIG_GLOB . ') — run scripts/install-packages.sh first';
            } else {
                try {
                    $expectedSite = DevHttpsSite::render($template, $ipBlock, $hostBlock ? $host : null, $this->socket, $ipv6Listen);
                } catch (\InvalidArgumentException $e) {
                    $blockers[] = 'nginx template cannot be rendered: ' . $e->getMessage();
                }
            }
        }

        return new DevHttpsPlan(
            $access, $blockers, $warnings,
            $ipBlock, $ipConflict, $ipCertificate, $ipCertRenewal,
            $hostBlock, $hostCertificate, $hostProblem,
            $expectedSite, $currentSite, $siteLinked, $siteFile, $siteLink,
            $ipv6Unavailable, $this->ipv6ListenSites(),
        );
    }

    /**
     * Proč certifikát pro IP obnovit (U5, U20); null = vyhovuje.
     *
     * @param list<string> $addresses
     */
    private function ipRenewalReason(DevCertificate $certificate, array $addresses, \DateTimeImmutable $now): ?string
    {
        if ($certificate->status === DevCertificate::MISSING) {
            return 'missing';
        }
        if (!$certificate->isOk()) {
            return 'file is not a readable certificate';
        }
        $keyFile = $this->keyFile(self::IP_DIR);
        if (!file_exists($keyFile)) {
            return 'private key is missing';
        }
        if ($certificate->matchesKeyFile($keyFile) === false) {
            return 'private key does not match the certificate';
        }
        $missing = $certificate->missingIps($addresses);
        if ($missing !== []) {
            return 'does not cover ' . implode(', ', $missing);
        }
        $days = (int) $certificate->daysLeft($now);
        if ($days < DevCertificate::RENEW_BEFORE_DAYS) {
            return $days < 0 ? 'expired' : "expires in {$days} days";
        }
        return null;
    }

    /** Proč blok pro jméno nevznikne (U3 bod 2); null = certifikát vyhovuje. */
    private function hostProblem(DevCertificate $certificate, string $host): ?string
    {
        $certFile = $this->certFile(self::HOST_DIR);
        $keyFile = $this->keyFile(self::HOST_DIR);
        return match (true) {
            $certificate->status === DevCertificate::MISSING => "certificate for {$host} not found: {$certFile}",
            $certificate->status === DevCertificate::UNREADABLE => "certificate for {$host} is not readable: {$certFile}",
            !$certificate->isOk() => "{$certFile} is not a certificate",
            !$certificate->coversName($host) => "certificate {$certFile} does not cover {$host} (SAN: " . (implode(', ', $certificate->names) ?: 'none') . ')',
            !file_exists($keyFile) && !is_link($keyFile) => "private key for {$host} not found: {$keyFile}",
            $certificate->matchesKeyFile($keyFile) === false => "private key {$keyFile} does not match the certificate",
            default => null,
        };
    }

    private function isPrivileged(): bool
    {
        return $this->privileged ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);
    }

    private function kernelIpv6(): bool
    {
        return $this->ipv6 ?? file_exists('/proc/net/if_inet6');
    }

    /**
     * Site soubory Shipardu, které nginx načítá a které mají `listen` na
     * IPv6 — `[::]` i konkrétní adresa (#183 U27). Čte se `sites-enabled`
     * (to, co nginx skutečně použije), i ručně upravený http site.
     *
     * @return list<string> jména souborů
     */
    private function ipv6ListenSites(): array
    {
        $out = [];
        foreach (self::SHIPARD_SITES as $name) {
            $file = $this->sitesEnabledDir . '/' . $name;
            $content = is_file($file) ? @file_get_contents($file) : false;
            if ($content === false) {
                continue;
            }
            $stripped = preg_replace('/#[^\n]*/', '', $content) ?? $content;
            if (preg_match('/\blisten\s+\[[^\]]*\]/', $stripped) === 1) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * Cizí web s `default_server` na 443 (U3, U21): soubor konfigurace
     * nginx mimo náš site soubor, který má `listen … 443 … default_server`.
     */
    private function foreignDefaultServer(string $ownLink): ?string
    {
        $files = glob($this->sitesEnabledDir . '/*') ?: [];
        foreach ($this->extraNginxGlobs as $pattern) {
            $files = [...$files, ...(glob($pattern) ?: [])];
        }
        foreach ($files as $file) {
            if ($file === $ownLink || !is_file($file)) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $stripped = preg_replace('/#[^\n]*/', '', $content) ?? $content;
            if (preg_match('/\blisten\s+(?:[^;]*[:\s])?443\b[^;]*\bdefault_server\b[^;]*;/', $stripped) === 1) {
                return $file;
            }
        }
        return null;
    }
}
