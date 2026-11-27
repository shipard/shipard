<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

use Shipard\Core\Net\LocalAddresses;

/**
 * Kontroly sekce *Development HTTPS* příkazu `doctor` (#183 U1, U11) nad
 * tímtéž rozhodnutím jako `dev-https` ({@see DevHttpsPlanner}):
 *
 * - certifikát každého zapnutého https vstupu existuje a je čitelný;
 *   vypršel, nenese adresu serveru / jméno nebo k němu nepatří klíč → chyba;
 *   méně než {@see DevCertificate::WARN_BEFORE_DAYS} dní do konce → varování;
 * - jméno se na serveru překládá na tento server ({@see LocalAddresses},
 *   pravidlo #103 D97) — jinak by na něj nedosáhlo testovací kolo ani videa;
 * - site `shipard-https.conf` je aktivní a odpovídá konfiguraci, jinak
 *   varování s příkazem;
 * - `devAccess` v režimu `production` → varování, sekce se nepoužije.
 *
 * Jen čte. Shodu klíče s certifikátem ověří jen tam, kde klíč přečte (root).
 *
 * @phpstan-type Finding array{level: 'ok'|'info'|'warning'|'error', message: string, hints: list<string>}
 */
final class DevHttpsDoctor
{
    private const string RUN_HINT = 'Run: sudo shpd-server dev-https';

    public function __construct(
        private readonly DevHttpsPlanner $planner,
        private readonly LocalAddresses $local = new LocalAddresses(),
    ) {
    }

    /**
     * @return list<array{level: string, message: string, hints: list<string>}>
     */
    public function check(DevAccess $access, \DateTimeImmutable $now): array
    {
        if (!$access->development) {
            return $access->isIgnoredInProduction()
                ? [self::finding('warning', "'devAccess' is set in server.json, but the server runs in production mode — the section is ignored", ['Remove the section, or run this server in development mode.'])]
                : [self::finding('info', '(development servers only — skipped)')];
        }

        $findings = [];
        foreach ($access->problems as $problem) {
            $findings[] = self::finding('warning', 'server.json: ' . $problem, ['The value is not used — fix devAccess in /etc/shipard/server.json.']);
        }

        $plan = $this->planner->plan($access, $now);

        if (!$access->hasHttpsEntry()) {
            $findings[] = self::finding('info', '(no https entry enabled — http on the IP address only)');
            if (!$plan->siteUpToDate()) {
                $findings[] = self::finding('warning', 'nginx site ' . DevHttpsSite::FILE_NAME . ' is still installed', [self::RUN_HINT . ' (removes it)']);
            }
            return $findings;
        }

        if ($access->wantsHttpsIp()) {
            $findings = [...$findings, ...$this->checkIpEntry($plan, $now)];
        }
        if ($access->httpsHost() !== null) {
            $findings = [...$findings, ...$this->checkHostEntry($plan, $access->httpsHost(), $now)];
        }

        // Site soubor — blokery, které se netýkají adres (ty hlásí vstup přes IP).
        $siteBlockers = array_values(array_filter($plan->blockers, static fn (string $b): bool => !str_contains($b, 'no address of this server')));
        foreach ($siteBlockers as $blocker) {
            $findings[] = self::finding('error', $blocker);
        }
        if ($plan->blockers === []) {
            if ($plan->siteUpToDate()) {
                if ($plan->expectedSite !== null) {
                    $findings[] = self::finding('ok', 'nginx site ' . DevHttpsSite::FILE_NAME . ' is enabled and up to date');
                }
            } else {
                $action = array_values(array_filter($plan->pendingActions(), static fn (string $a): bool => str_starts_with($a, 'nginx site')))[0] ?? 'nginx site needs a refresh';
                $findings[] = self::finding('warning', $action, [self::RUN_HINT]);
            }
        }

        return $findings;
    }

    /** @return list<array{level: string, message: string, hints: list<string>}> */
    private function checkIpEntry(DevHttpsPlan $plan, \DateTimeImmutable $now): array
    {
        $label = 'https on the IP address';
        if ($plan->ipConflict !== null) {
            return [self::finding('warning', "{$label}: not active — another nginx site is the default server on port 443 ({$plan->ipConflict})", [
                'Remove default_server there, or set devAccess.httpsIp to false.',
            ])];
        }
        if (!$plan->ipBlock) {
            return [self::finding('error', "{$label}: no address of this server found", ['Set devAccess.ip in /etc/shipard/server.json.'])];
        }

        $certificate = $plan->ipCertificate;
        $renewal = $plan->ipCertRenewal;
        $days = $certificate->daysLeft($now);

        if ($renewal !== null && !str_starts_with($renewal, 'expires in')) {
            return [self::finding('error', "{$label}: certificate " . ($renewal === 'missing' ? 'is missing' : $renewal) . " ({$certificate->path})", [self::RUN_HINT])];
        }
        $covers = 'covers ' . implode(', ', $plan->access->addresses());
        if ($days !== null && $days < DevCertificate::WARN_BEFORE_DAYS) {
            return [self::finding('warning', "{$label}: certificate expires in {$days} days", [self::RUN_HINT . ' (sudo shpd-server upgrade renews it too)'])];
        }
        if ($renewal !== null) {
            return [self::finding('info', "· {$label}: certificate valid for {$days} more days, {$covers} — renewal is due, the next dev-https / upgrade makes a new one")];
        }
        return [self::finding('ok', "{$label}: certificate valid for {$days} more days, {$covers}")];
    }

    /** @return list<array{level: string, message: string, hints: list<string>}> */
    private function checkHostEntry(DevHttpsPlan $plan, string $host, \DateTimeImmutable $now): array
    {
        $label = "https on {$host}";
        $findings = [];
        $certificate = $plan->hostCertificate;

        if ($certificate === null || $certificate->status === DevCertificate::UNREADABLE) {
            // Symlink do adresáře jen pro root (certbot live/) — bez roota nejde posoudit (U4).
            $findings[] = self::finding('warning', "{$label}: certificate is not readable for this user (" . $this->planner->certFile(DevHttpsPlanner::HOST_DIR) . ')', [
                'Run: sudo shpd-server doctor',
            ]);
        } elseif ($plan->hostProblem !== null) {
            $findings[] = self::finding('error', "{$label}: " . $plan->hostProblem, [
                'Put the certificate and its key (symlinks are fine) to ' . dirname($certificate->path) . '/, then ' . lcfirst(self::RUN_HINT),
            ]);
        } elseif ($certificate->isExpired($now)) {
            $findings[] = self::finding('error', "{$label}: certificate expired on " . $certificate->notAfter?->format('Y-m-d'), [
                'Renew the certificate; nginx picks it up after: sudo systemctl reload nginx',
            ]);
        } else {
            $days = (int) $certificate->daysLeft($now);
            $findings[] = $days < DevCertificate::WARN_BEFORE_DAYS
                ? self::finding('warning', "{$label}: certificate expires in {$days} days", ['Renew the certificate; nginx picks it up after: sudo systemctl reload nginx'])
                : self::finding('ok', "{$label}: certificate valid for {$days} more days");
        }

        $addresses = $this->local->addressesOf($host);
        if ($addresses === []) {
            $findings[] = self::finding('error', "{$label}: the name does not resolve on this server", [
                'Add it to DNS, or to /etc/hosts on this server (the test round and videos open the application by this name).',
            ]);
        } else {
            $foreign = $this->local->foreign($addresses);
            $findings[] = $foreign === []
                ? self::finding('ok', "{$label}: resolves to this server (" . implode(', ', $addresses) . ')')
                : self::finding('error', "{$label}: resolves to " . implode(', ', $foreign) . ', which is not an address of this server', [
                    'Point the name to this server, or override it in /etc/hosts here.',
                ]);
        }

        return $findings;
    }

    /**
     * @param list<string> $hints
     * @return array{level: string, message: string, hints: list<string>}
     */
    private static function finding(string $level, string $message, array $hints = []): array
    {
        return ['level' => $level, 'message' => $message, 'hints' => $hints];
    }
}
