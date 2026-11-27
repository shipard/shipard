<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Co by `shpd-server dev-https` udělal (#183 U3) — výsledek
 * {@see DevHttpsPlanner}. Sdílí ho příkaz (běh i `--check`) a `doctor`,
 * aby se rozhodnutí o certifikátu a site souboru nepočítalo dvakrát.
 */
final class DevHttpsPlan
{
    /**
     * @param list<string> $blockers proč běh nejde (chybí šablona, socket, adresy) — bez změn
     * @param list<string> $warnings co běh neudělá a proč (cizí default_server, U21)
     * @param ?string $ipCertRenewal důvod obnovy certifikátu pro IP; null = obnova není potřeba
     * @param ?string $hostProblem proč blok pro jméno nevznikne (certifikát chybí, nesedí); null = v pořádku nebo vstup vypnutý
     * @param ?string $expectedSite obsah, který má site soubor mít; null = soubor nemá existovat
     * @param ?string $currentSite obsah site souboru na disku; null = neexistuje
     * @param bool $ipv6Unavailable `devAccess.ipv6` je zapnuté, ale jádro IPv6 nemá — bloky jsou bez IPv6 (U26)
     * @param list<string> $ipv6ListenSites site soubory Shipardu v sites-enabled s `listen` na IPv6 (U27)
     */
    public function __construct(
        public readonly DevAccess $access,
        public readonly array $blockers,
        public readonly array $warnings,
        public readonly bool $ipBlock,
        public readonly ?string $ipConflict,
        public readonly DevCertificate $ipCertificate,
        public readonly ?string $ipCertRenewal,
        public readonly bool $hostBlock,
        public readonly ?DevCertificate $hostCertificate,
        public readonly ?string $hostProblem,
        public readonly ?string $expectedSite,
        public readonly ?string $currentSite,
        public readonly bool $siteLinked,
        public readonly string $siteFile,
        public readonly string $siteLink,
        public readonly bool $ipv6Unavailable = false,
        public readonly array $ipv6ListenSites = [],
    ) {
    }

    /** Site soubor na disku odpovídá tomu, co by běh zapsal (obsah i odkaz v sites-enabled). */
    public function siteUpToDate(): bool
    {
        if ($this->expectedSite === null) {
            return $this->currentSite === null && !$this->siteLinked;
        }
        return $this->currentSite === $this->expectedSite && $this->siteLinked;
    }

    /**
     * Změnil by běh něco? (`--check`: nenulový exit.) Problém certifikátu
     * pro jméno ani cizí `default_server` sem nepatří — běh je nespraví.
     */
    public function needsRun(): bool
    {
        return $this->blockers === [] && ($this->ipCertRenewal !== null || !$this->siteUpToDate());
    }

    /**
     * Věty o tom, co by běh udělal.
     *
     * @return list<string>
     */
    public function pendingActions(): array
    {
        $out = [];
        if ($this->ipCertRenewal !== null) {
            $out[] = 'certificate for the IP entry: ' . $this->ipCertRenewal;
        }
        if (!$this->siteUpToDate()) {
            $out[] = match (true) {
                $this->expectedSite === null => 'nginx site ' . DevHttpsSite::FILE_NAME . ' would be removed (no https entry enabled)',
                $this->currentSite === null => 'nginx site ' . DevHttpsSite::FILE_NAME . ' is missing',
                $this->currentSite !== $this->expectedSite => 'nginx site ' . DevHttpsSite::FILE_NAME . ' is outdated',
                default => 'nginx site ' . DevHttpsSite::FILE_NAME . ' is not enabled',
            };
        }
        return $out;
    }
}
