<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use Shipard\Core\Net\LocalAddresses;
use Shipard\Core\Server\DevAccess;
use Shipard\Core\Server\DevHttpsPlanner;
use Shipard\Core\Server\DevHttpsSite;

/**
 * Dočasný „server“ pro testy https vstupů (#183): `tls/`, `sites-available/`,
 * `sites-enabled/` a skutečná šablona z `docs/nginx/`.
 */
final class DevHttpsEnvironment
{
    public const string SOCKET = '/run/php/php8.5-fpm-shipard.sock';
    public const array ADDRESSES = ['10.0.0.7', '2001:db8::7'];

    public readonly string $root;
    public readonly string $tlsDir;
    public readonly string $sitesAvailable;
    public readonly string $sitesEnabled;
    public readonly string $siteFile;
    public readonly string $siteLink;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/shpd-devhttps-' . uniqid();
        $this->tlsDir = $this->root . '/tls';
        $this->sitesAvailable = $this->root . '/sites-available';
        $this->sitesEnabled = $this->root . '/sites-enabled';
        $this->siteFile = $this->sitesAvailable . '/' . DevHttpsSite::FILE_NAME;
        $this->siteLink = $this->sitesEnabled . '/' . DevHttpsSite::FILE_NAME;
        mkdir($this->sitesAvailable, 0750, true);
        mkdir($this->sitesEnabled, 0750, true);
    }

    public static function templateFile(): string
    {
        return dirname(__DIR__, 4) . '/docs/nginx/development-https.conf';
    }

    public function planner(?string $socket = self::SOCKET, bool $ipv6 = false, ?string $templateFile = null, bool $privileged = true): DevHttpsPlanner
    {
        return new DevHttpsPlanner(
            $templateFile ?? self::templateFile(),
            $socket,
            $this->tlsDir,
            $this->sitesAvailable,
            $this->sitesEnabled,
            $ipv6,
            [$this->root . '/conf.d/*.conf'],
            $privileged,
        );
    }

    /**
     * @param array<string, mixed>|null $section
     * @param list<string> $addresses
     */
    public static function access(?array $section, string $mode = 'development', array $addresses = self::ADDRESSES): DevAccess
    {
        return DevAccess::fromSection($section, $mode, new LocalAddresses(
            static fn (): array => [],
            static fn (): array => ['127.0.0.1', ...$addresses],
        ));
    }

    /** @return array{cert: string, key: string} */
    public function writeIpCertificate(array $ips = self::ADDRESSES, int $days = 397): array
    {
        return DevCertificateFixture::write($this->tlsDir . '/' . DevHttpsPlanner::IP_DIR, $ips, [], $days);
    }

    /** @return array{cert: string, key: string} */
    public function writeHostCertificate(array $names, int $days = 90): array
    {
        return DevCertificateFixture::write($this->tlsDir . '/' . DevHttpsPlanner::HOST_DIR, [], $names, $days);
    }

    /** Cizí web v sites-enabled (obsah konfigurace nginx). */
    public function writeForeignSite(string $name, string $content): string
    {
        $file = $this->sitesEnabled . '/' . $name;
        file_put_contents($file, $content);
        return $file;
    }

    /** Site soubor, jaký vyráběla fáze 1 na serveru s IPv6 — s `listen [::]:443` (#183 D8). */
    public function siteWithIpv6Listen(?string $host = null): string
    {
        return (string) DevHttpsSite::render((string) file_get_contents(self::templateFile()), true, $host, self::SOCKET, true);
    }

    public function installSite(string $content): void
    {
        file_put_contents($this->siteFile, $content);
        @unlink($this->siteLink);
        symlink($this->siteFile, $this->siteLink);
    }

    public function remove(): void
    {
        self::removeDir($this->root);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
