<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailRelayConfig;
use Shipard\Core\Mail\MailSafetyConfig;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Server\CronProvisioner;
use Shipard\Core\Server\DomainsFile;
use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\DataSourceStateScanner;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DoctorCommand extends Command
{
    /** Mapa binárka → apt balíček (hint pro admina). */
    private const ATTACHMENT_TOOLS = [
        'pdftocairo'    => 'poppler-utils',
        'pdftotext'     => 'poppler-utils',
        // Embedded ISDOC v PDF (IsdocImportService) — bez binárky detekce
        // tiše degraduje, doctor na chybějící nástroj upozorní.
        'pdfdetach'     => 'poppler-utils',
        // Post-processing PDF renderů (EmbedIsdocStep fallback, AppendPdfsStep).
        'pdfattach'     => 'poppler-utils',
        'pdfunite'      => 'poppler-utils',
        'rsvg-convert'  => 'librsvg2-bin',
        'vipsthumbnail' => 'libvips-tools',
        'vips'          => 'libvips-tools',
    ];

    protected string $serverConfigPath = '/etc/shipard/server.json';

    public function __construct(
        private readonly ?PermissionSpec $injectedSpec = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('doctor')
             ->setDescription('Diagnose shpd server configuration and permissions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Shipard server health check</info>');
        $output->writeln(str_repeat('═', 55));

        $configFile = $this->serverConfigPath;
        if (!is_file($configFile)) {
            $output->writeln("<error>Config file not found or not accessible: {$configFile}</error>");
            $output->writeln('<comment>→ If it exists, you likely lack permission — run as the shipard user (or root):</comment>');
            $output->writeln('<comment>    sudo -u shipard shpd-server doctor</comment>');
            $output->writeln('<comment>→ If it is genuinely missing, initialize the server:</comment>');
            $output->writeln('<comment>    sudo shpd-server server-init --mode=...</comment>');
            return Command::FAILURE;
        }

        $configContent = @file_get_contents($configFile);
        if ($configContent === false) {
            $output->writeln("<error>Config file not readable: {$configFile}</error>");
            $output->writeln('<comment>→ Run as the shipard user (or root): sudo -u shipard shpd-server doctor</comment>');
            return Command::FAILURE;
        }

        $config = json_decode($configContent, true);
        if (!is_array($config)) {
            $output->writeln("<error>Config file is not valid JSON: {$configFile}</error>");
            return Command::FAILURE;
        }

        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'unknown';
        $spec = $this->injectedSpec ?? $this->buildSpec($mode);
        $shipardUser = $spec->getShipardUser();

        $output->writeln('');
        $output->writeln("  Mode:                {$mode}");
        $output->writeln("  Shipard user:        {$shipardUser}");

        $checker = new HealthChecker($spec);
        $poolUser = $this->detectPoolUser($checker);
        $poolDisplay = $poolUser ?? 'not configured';
        $poolStatus = ($poolUser === $shipardUser) ? '✓' : '✗ (mismatch)';
        $output->writeln("  PHP-FPM pool user:   {$poolDisplay}   {$poolStatus}");

        $output->writeln('');
        $output->writeln('<info>Filesystem checks</info>');

        $issues = $checker->checkAll();
        if (count($issues) === 0) {
            $output->writeln('  All paths OK ✓');
        } else {
            foreach ($issues as $issue) {
                $marker = $issue['severity'] === 'error' ? '✗' : '⚠';
                $output->writeln("  {$marker} {$issue['path']}: {$issue['message']}");
            }
        }

        $output->writeln('');
        $output->writeln('<info>Nginx + PHP-FPM routing</info>');
        $expectedSocket = $this->detectShipardSocket();
        $fpmErrors = $this->checkFpmSocket($output, $expectedSocket);
        $nginxErrors = $this->checkNginxRouting($output, $expectedSocket);

        $output->writeln('');
        $output->writeln('<info>System config includes</info>');
        $this->checkSystemConfigIncludes($output);

        $output->writeln('');
        $output->writeln('<info>Hosting domains file</info>');
        $this->checkHostingDomainsFile($config, $shipardUser, $output);

        $output->writeln('');
        $output->writeln('<info>Cron</info>');
        $cronErrors = $this->checkCron($output, $mode);

        $output->writeln('');
        $output->writeln('<info>Attachment tools</info>');
        $toolErrors = $this->checkAttachmentTools($output);

        $output->writeln('');
        $output->writeln('<info>Render service</info>');
        $renderErrors = $this->checkRenderService($config, $output);

        $output->writeln('');
        $output->writeln('<info>Data source DB connections</info>');
        $dsErrors = $this->checkDataSourceConnections($spec, $output, $mode);

        $output->writeln('');
        $output->writeln('<info>Data source states</info>');
        $stateErrors = $this->checkDataSourceStates($spec, $output);

        $output->writeln('');
        $output->writeln('<info>Outbound mail</info>');
        $mailErrors = $this->checkMailSafety($config, $output)
                    + $this->checkMailOutbound($spec, $config, $output);

        $output->writeln('');
        $output->writeln(str_repeat('─', 55));

        $totalIssues = count($issues) + $dsErrors + $fpmErrors + $nginxErrors + $toolErrors
                     + $mailErrors + $cronErrors + $renderErrors + $stateErrors
                     + ($poolUser !== $shipardUser ? 1 : 0);
        if ($totalIssues === 0) {
            $output->writeln('<info>✓ All checks passed.</info>');
            return Command::SUCCESS;
        }

        $output->writeln("<error>Issues found: {$totalIssues}</error>");
        $fixableCount = count(array_filter($issues, static fn($i) => $i['fixable']));
        if ($fixableCount > 0) {
            $output->writeln('<comment>→ Run: sudo shpd-server fix-permissions</comment>');
        }
        return Command::FAILURE;
    }

    protected function buildSpec(string $mode): PermissionSpec
    {
        return new PermissionSpec($this->detectShipardUser($mode));
    }

    protected function detectPoolUser(HealthChecker $checker): ?string
    {
        return $checker->detectPoolUser();
    }

    protected function detectShipardUser(string $mode): string
    {
        return PermissionSpec::detectShipardUser($mode);
    }

    /**
     * @return int number of DS that failed to connect
     */
    protected function checkDataSourceConnections(PermissionSpec $spec, OutputInterface $output, string $mode): int
    {
        $dsList = $spec->discoverDataSources();
        if (count($dsList) === 0) {
            $output->writeln('  (no data sources)');
            return 0;
        }

        $errors = 0;
        foreach ($dsList as $dsDir) {
            $id = basename($dsDir);
            try {
                $cfg = new DataSourceConfig($dsDir);
                if ($mode === 'production' && $cfg->allowsReset()) {
                    $output->writeln("  ⚠ {$id}: enableReset is set — data source is resettable on a production server.");
                }
                $conn = new DataSourceConnection($cfg);
                $conn->fetchRow('SELECT 1');
                $output->writeln("  ✓ {$id}");
            } catch (\Throwable $e) {
                $output->writeln("  ✗ {$id}: " . $e->getMessage());
                $errors++;
            }
        }
        return $errors;
    }

    /**
     * Stavy DS (#56 D8): počty per efektivní stav, ⚠ pro maintenance déle
     * než práh (zapomenutá?), ✗ pro fail-closed state.json (DS zavřený
     * omylem — jediné místo, kde to admin uvidí bez čtení logu). Ne-active
     * DS se vypisují všechny, aby read_only/suspended nebyly překvapení.
     *
     * @return int number of errors (corrupted state files)
     */
    protected function checkDataSourceStates(PermissionSpec $spec, OutputInterface $output): int
    {
        $entries = (new DataSourceStateScanner($spec->getDataSourcesDir()))->scan($this->now());
        if (count($entries) === 0) {
            $output->writeln('  (no data sources)');
            return 0;
        }

        $counts = DataSourceStateScanner::countByState($entries);
        $output->writeln(sprintf(
            '  active %d · read_only %d · suspended %d (maintenance %d) · pending_deletion %d',
            $counts['active'], $counts['read_only'], $counts['suspended'], $counts['maintenance'], $counts['pending_deletion'],
        ));

        $errors = 0;
        $threshold = DataSourceStateScanner::MAINTENANCE_WARN_DAYS;
        foreach ($entries as $e) {
            if ($e->isCorrupted()) {
                $output->writeln("  ✗ {$e->dsId}: state.json unusable — fail-closed as suspended (see shipard.log, fix or remove the file)");
                $errors++;
                continue;
            }
            if ($e->state->isMaintenanceActive()) {
                $reason = $e->state->getMaintenanceReason() ?? '?';
                $days = $e->maintenanceDays;
                if ($e->isMaintenanceOverdue($threshold)) {
                    $output->writeln("  ⚠ {$e->dsId}: maintenance ({$reason}) for {$days} days — forgotten? shpd-ds ds-state maintenance --off");
                } else {
                    $output->writeln("  · {$e->dsId}: maintenance ({$reason}), " . ($days === null ? 'since unknown' : "{$days} day(s)"));
                }
                continue;
            }
            if ($e->effectiveState() !== DataSourceState::ACTIVE) {
                $output->writeln("  · {$e->dsId}: {$e->effectiveState()}");
            }
        }
        if ($errors === 0 && $counts['maintenance'] === 0 && $counts['active'] === count($entries)) {
            $output->writeln('  All data sources active ✓');
        }
        return $errors;
    }

    /**
     * Pojistka odchozí pošty (`mail.safety`, #95 D7): režim, odkud se vzal
     * a kam pošta jde. Chybná sekce je chyba (nic neodejde), vypnutá
     * pojistka na neprodukčním serveru varování.
     *
     * @param array $serverConfig dekódovaný server.json
     * @return int number of errors
     */
    protected function checkMailSafety(array $serverConfig, OutputInterface $output): int
    {
        $safety = MailSafetyConfig::fromServerData($serverConfig);

        if ($safety->source === MailSafetyConfig::SOURCE_INVALID) {
            $output->writeln("  ✗ Mail safety: {$safety->mode} (invalid) — {$safety->problem}");
            $output->writeln('    <comment>→ Fix mail.safety in server.json; until then nothing is sent</comment>');
            return 1;
        }

        $detail = match ($safety->mode) {
            MailSafetyConfig::MODE_REDIRECT  => "redirect → {$safety->redirectTo}",
            MailSafetyConfig::MODE_ALLOWLIST => sprintf(
                'allowlist (%d allowed), others → %s',
                count($safety->allow),
                $safety->redirectTo ?? 'dropped',
            ),
            MailSafetyConfig::MODE_DROP      => 'drop — nothing is sent',
            default                          => 'off',
        };
        $source = $safety->source === MailSafetyConfig::SOURCE_DEFAULT
            ? 'default, no mail.safety in server.json'
            : 'configured';

        if (!$safety->isActive() && ($serverConfig['mode'] ?? null) !== 'production') {
            $output->writeln("  ⚠ Mail safety: {$detail} ({$source}) on a non-production server — mail reaches real recipients");
            return 0;
        }

        $output->writeln("  ✓ Mail safety: {$detail} ({$source})");
        return 0;
    }

    /**
     * Stav odchozí pošty per DS: relay konfigurace (DS override ?? server
     * default) / aktivní senders, terminálně selhané zprávy za 24 h,
     * hloubka fronty a overdue pending (> 30 min = error, fronta stojí).
     * Chybějící tabulky (před ds-upgrade) = skip, ne chyba.
     *
     * @param array $serverConfig dekódovaný server.json
     * @return int number of errors
     */
    protected function checkMailOutbound(PermissionSpec $spec, array $serverConfig, OutputInterface $output): int
    {
        $serverRelay = null;
        try {
            $relayData = $serverConfig['mail']['relay'] ?? null;
            if (is_array($relayData)) {
                $serverRelay = MailRelayConfig::fromArray($relayData);
            }
        } catch (\Throwable $e) {
            $output->writeln('  ✗ server.json mail.relay is invalid: ' . $e->getMessage());
            return 1;
        }

        $dsList = $spec->discoverDataSources();
        if (count($dsList) === 0) {
            $output->writeln('  (no data sources)');
            return 0;
        }

        $errors = 0;
        foreach ($dsList as $dsDir) {
            $id = basename($dsDir);
            try {
                $cfg = new DataSourceConfig($dsDir);
                $conn = new DataSourceConnection($cfg);

                $relay = $cfg->getMailRelay() ?? $serverRelay;
                $senders = (int) $conn->fetchSingle(
                    'SELECT COUNT(*) FROM core_mail_senders WHERE is_active = 1',
                );

                $transport = $relay !== null
                    ? "relay {$relay->host}:{$relay->port}"
                    : 'no relay';
                $transport .= ", {$senders} sender(s)";

                if ($relay === null && $senders === 0) {
                    $output->writeln("  ⚠ {$id}: outbound mail not configured ({$transport})");
                    continue;
                }

                $now = new \DateTimeImmutable();
                $failed = (int) $conn->fetchSingle(
                    "SELECT COUNT(*) FROM core_mail_outbox WHERE state = 'failed'"
                    . ' AND id IN (SELECT outbox_id FROM core_mail_outbox_log WHERE ts >= %s)',
                    $now->modify('-24 hours')->format('Y-m-d H:i:s'),
                );
                $pending = (int) $conn->fetchSingle(
                    "SELECT COUNT(*) FROM core_mail_outbox WHERE state = 'pending'",
                );
                $overdue = (int) $conn->fetchSingle(
                    "SELECT COUNT(*) FROM core_mail_outbox WHERE state = 'pending' AND next_attempt <= %s",
                    $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
                );

                if ($overdue > 0) {
                    $output->writeln("  ✗ {$id}: {$overdue} pending overdue > 30 min ({$transport}, queue {$pending})");
                    $output->writeln('    <comment>→ Worker not running or transport down — check cron and `shpd-ds mail-send-test`</comment>');
                    $errors++;
                } elseif ($failed > 0) {
                    $output->writeln("  ⚠ {$id}: {$failed} failed in last 24 h ({$transport}, queue {$pending})");
                    $output->writeln('    <comment>→ Re-queue after fixing: shpd-ds mail-outbox-retry --id N</comment>');
                } else {
                    $output->writeln("  ✓ {$id}: {$transport}, queue {$pending}");
                }
            } catch (\Throwable $e) {
                // Tabulky ještě nemusí existovat (před ds-upgrade) nebo DS
                // nejde připojit — connectivity hlásí checkDataSourceConnections.
                $output->writeln("  - {$id}: skipped (" . $e->getMessage() . ')');
            }
        }
        return $errors;
    }

    /**
     * Servery s provisioning agentem (sekce `hosting` v server.json):
     * `hosting-sync` běží z cronu jako shipard user a krok domain-add
     * zapisuje domains soubor atomicky (tmp + rename) — potřebuje tedy
     * zápis na ADRESÁŘ souboru. Výchozí /etc/shipard je root-managed
     * (root:shipard 750), pro agenta read-only → doporučení je override
     * `domainsFile` v server.json do app-writable cesty. Warn-only —
     * bez agenta se domény přidávají ručně (sudo) a nález neplatí.
     */
    protected function checkHostingDomainsFile(array $config, string $shipardUser, OutputInterface $output): void
    {
        if (!isset($config['hosting'])) {
            $output->writeln('  (server not hosting-managed — skipped)');
            return;
        }

        $domainsFile = is_string($config['domainsFile'] ?? null)
            ? $config['domainsFile']
            : DomainsFile::DEFAULT_DOMAINS_FILE;
        $dir = dirname($domainsFile);

        $writable = $this->isWritableByUser($dir, $shipardUser);
        if ($writable === null) {
            $output->writeln("  ⚠ cannot stat {$dir} — verify manually that '{$shipardUser}' can write {$domainsFile}");
            return;
        }
        if ($writable) {
            $output->writeln("  ✓ {$domainsFile} (directory writable by {$shipardUser})");
            return;
        }

        $output->writeln("  ⚠ {$domainsFile}: directory {$dir} not writable by '{$shipardUser}' — hosting-sync domain-add will fail");
        $output->writeln("    <comment>→ Point 'domainsFile' in server.json to an app-writable path (e.g. /opt/shipard/domains.json)</comment>");
        $output->writeln('    <comment>  and move the existing file there; /etc/shipard stays root-managed.</comment>');
    }

    /**
     * Zápisové oprávnění uživatele k cestě z owner/group/mode — doctor
     * typicky neběží pod tím uživatelem, `is_writable()` nelze použít.
     * Null = nelze zjistit (stat selhal / uživatel neexistuje).
     */
    protected function isWritableByUser(string $path, string $user): ?bool
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }
        $pw = posix_getpwnam($user);
        if ($pw === false) {
            return null;
        }
        if ($pw['uid'] === 0) {
            return true;
        }

        if ($stat['uid'] === $pw['uid']) {
            return ($stat['mode'] & 0200) !== 0;
        }

        $inGroup = $stat['gid'] === $pw['gid'];
        if (!$inGroup) {
            $group = posix_getgrgid($stat['gid']);
            $inGroup = is_array($group) && in_array($user, $group['members'] ?? [], true);
        }
        if ($inGroup) {
            return ($stat['mode'] & 0020) !== 0;
        }

        return ($stat['mode'] & 0002) !== 0;
    }

    protected function getCronFilePath(): string
    {
        return CronProvisioner::CRON_FILE;
    }

    protected function getRunDir(): string
    {
        return CronProvisioner::RUN_DIR;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /**
     * Systémový cron: soubor existuje a odpovídá aktuální verzi šablony,
     * heartbeaty slotů nejsou zatuchlé. Zatuchlý minute/five-minutes = error
     * (cron démon neběží nebo dispatcher padá), daily/weekly jen warn.
     * Selhané joby v posledním běhu = warn (per-DS problém, ne infra).
     *
     * @return int number of cron issues
     */
    protected function checkCron(OutputInterface $output, string $mode): int
    {
        $hint = '    <comment>→ Run: sudo shpd-server upgrade (or sudo shpd-server cron-install)</comment>';

        $cronFile = $this->getCronFilePath();
        if (!is_file($cronFile)) {
            if ($mode !== 'production') {
                $output->writeln('  (cron not provisioned — skipped)');
                return 0;
            }
            $output->writeln("  ✗ {$cronFile} missing — periodic jobs (mail outbox, alerts) are not running");
            $output->writeln($hint);
            return 1;
        }

        $errors = 0;
        $content = (string) @file_get_contents($cronFile);
        $version = CronProvisioner::parseTemplateVersion($content);
        if ($version !== CronProvisioner::TEMPLATE_VERSION) {
            $output->writeln(sprintf(
                '  ✗ %s is outdated (template version %s, expected %d)',
                $cronFile,
                $version === null ? 'unknown' : (string) $version,
                CronProvisioner::TEMPLATE_VERSION,
            ));
            $output->writeln($hint);
            $errors++;
        } else {
            $output->writeln("  ✓ {$cronFile} (template version {$version})");
        }

        $now = $this->now()->getTimestamp();
        // Čerstvě nainstalovaný cron ještě nemusel odběhnout — chybějící
        // heartbeat pak není chyba.
        $justInstalled = ($now - (@filemtime($cronFile) ?: 0)) < 900;

        // slot => [max stáří v sekundách, true = error / false = warn]
        $limits = [
            'minute'       => [600, true],
            'two-minutes'  => [900, true],
            'five-minutes' => [1200, true],
            'daily'        => [2 * 86400, false],
            'weekly'       => [14 * 86400, false],
        ];
        foreach ($limits as $slot => [$maxAge, $strict]) {
            $hbPath = CronProvisioner::heartbeatPath($slot, $this->getRunDir());
            if (!is_file($hbPath)) {
                if ($justInstalled || !$strict) {
                    $output->writeln("  ⚠ {$slot}: not yet run"
                        . ($justInstalled ? ' (cron installed recently)' : ''));
                } else {
                    $output->writeln("  ✗ {$slot}: heartbeat missing — cron daemon not running or dispatcher failing");
                    $output->writeln($hint);
                    $errors++;
                }
                continue;
            }

            $data = json_decode((string) @file_get_contents($hbPath), true);
            $ts = is_array($data) && is_string($data['ts'] ?? null) ? strtotime($data['ts']) : false;
            if ($ts === false) {
                $ts = @filemtime($hbPath) ?: 0;
            }
            $age = $now - $ts;
            $ageText = $this->formatAge($age);

            if ($age > $maxAge) {
                if ($strict) {
                    $output->writeln("  ✗ {$slot}: heartbeat stale ({$ageText} old) — cron daemon not running or dispatcher failing");
                    $errors++;
                } else {
                    $output->writeln("  ⚠ {$slot}: heartbeat stale ({$ageText} old)");
                }
                continue;
            }

            $failedCount = is_array($data) ? (int) ($data['failedCount'] ?? 0) : 0;
            if ($failedCount > 0) {
                $output->writeln("  ⚠ {$slot}: last run {$ageText} ago, {$failedCount} failed job(s) — see shipard.log");
            } else {
                $output->writeln("  ✓ {$slot}: last run {$ageText} ago");
            }
        }

        return $errors;
    }

    private function formatAge(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => intdiv($seconds, 60) . ' min',
            $seconds < 2 * 86400 => intdiv($seconds, 3600) . ' h',
            default => intdiv($seconds, 86400) . ' d',
        };
    }

    protected function getPoolConfigGlob(): string
    {
        return '/etc/php/*/fpm/pool.d/shipard.conf';
    }

    protected function getNginxSitesEnabledDir(): string
    {
        return '/etc/nginx/sites-enabled';
    }

    /**
     * Parses the shipard PHP-FPM pool config and returns the `listen` socket path.
     * Returns null when no shipard pool config is present.
     */
    protected function detectShipardSocket(): ?string
    {
        foreach (glob($this->getPoolConfigGlob()) ?: [] as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/^\s*listen\s*=\s*(\S+)/m', $content, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Warn-only: verifies that the live nginx site and FPM pool configs include
     * the versioned system parameter files, and that those files exist in the
     * repo. Never affects the exit code — without the includes the app still
     * works, only with default (low) upload limits.
     */
    protected function checkSystemConfigIncludes(OutputInterface $output): void
    {
        $repoRoot = $this->getRepoRoot();
        $repoFiles = [
            'docs/nginx/shipard-common.conf',
            'docs/nginx/shipard-tls.conf',
            'docs/php/shipard-fpm-common.conf',
        ];
        foreach ($repoFiles as $rel) {
            if (!is_file($repoRoot . '/' . $rel)) {
                $output->writeln("  ⚠ Include file missing in repo: {$rel}");
            }
        }

        $checked = 0;

        $siteFile = $this->getNginxSitesEnabledDir() . '/shipard.conf';
        if (is_file($siteFile)) {
            $checked++;
            $content = (string) @file_get_contents($siteFile);
            // Strip `# ...` comments so a commented-out include does not count.
            $stripped = preg_replace('/#[^\n]*/', '', $content) ?? $content;
            if (str_contains($stripped, 'shipard-common.conf')) {
                $output->writeln('  ✓ nginx site includes shipard-common.conf');
            } else {
                $output->writeln("  ⚠ {$siteFile}: missing include of shipard-common.conf");
                $output->writeln('    <comment>→ Add to each server block: include /opt/shipard/shpd/docs/nginx/shipard-common.conf;</comment>');
                $output->writeln('    <comment>  HTTPS (443 ssl) blocks also: include /opt/shipard/shpd/docs/nginx/shipard-tls.conf;</comment>');
            }
        }

        foreach (glob($this->getPoolConfigGlob()) ?: [] as $poolFile) {
            $checked++;
            $content = (string) @file_get_contents($poolFile);
            // Strip `; ...` comments so a commented-out include does not count.
            $stripped = preg_replace('/^\s*;[^\n]*/m', '', $content) ?? $content;
            if (str_contains($stripped, 'shipard-fpm-common.conf')) {
                $output->writeln('  ✓ FPM pool includes shipard-fpm-common.conf');
            } else {
                $output->writeln("  ⚠ {$poolFile}: missing include of shipard-fpm-common.conf");
                $output->writeln('    <comment>→ Add to the pool: include=/opt/shipard/shpd/docs/php/shipard-fpm-common.conf</comment>');
            }
        }

        if ($checked === 0) {
            $output->writeln('  (no live configs found — skipped)');
        }
    }

    /**
     * Finds a binary in PATH. Pure-PHP scan (no exec) — deterministic and
     * overridable in tests.
     */
    protected function findBinary(string $name): ?string
    {
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        foreach (explode(':', $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = $dir . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Checks that the CLI tools used by ThumbnailGenerator and TextExtractor
     * are installed. A missing tool means silent degradation (generic icons,
     * empty extracted text) — exactly the kind of issue doctor surfaces.
     *
     * @return int number of missing attachment tools
     */
    protected function checkAttachmentTools(OutputInterface $output): int
    {
        $missing = 0;
        foreach (self::ATTACHMENT_TOOLS as $tool => $aptPackage) {
            $binPath = $this->findBinary($tool);
            if ($binPath !== null) {
                $output->writeln("  ✓ {$tool} ({$binPath})");
            } else {
                $output->writeln("  ✗ {$tool}: not found in PATH");
                $output->writeln("    <comment>→ Install: sudo apt install {$aptPackage}</comment>");
                $missing++;
            }
        }
        return $missing;
    }

    /**
     * PDF rendering služba (Gotenberg): chybějící klíč `render` = info
     * (validní stav, konzumenti degradují na errorKind=unconfigured),
     * nakonfigurovaná služba bez odpovědi na health = error.
     *
     * @param array $serverConfig dekódovaný server.json
     * @return int number of render issues
     */
    protected function checkRenderService(array $serverConfig, OutputInterface $output): int
    {
        $renderData = $serverConfig['render'] ?? null;
        if ($renderData === null) {
            $output->writeln('  (render service not configured — skipped)');
            return 0;
        }

        try {
            if (!is_array($renderData)) {
                throw new \RuntimeException("Server config 'render' must be an object");
            }
            $renderConfig = RenderConfig::fromArray($renderData);
        } catch (\Throwable $e) {
            $output->writeln('  ✗ server.json render is invalid: ' . $e->getMessage());
            return 1;
        }

        if ($this->renderHealth($renderConfig)) {
            $output->writeln("  ✓ render service responding at {$renderConfig->url}");
            return 0;
        }

        $output->writeln("  ✗ render service at {$renderConfig->url} is not responding");
        $output->writeln('    <comment>→ Check the container: systemctl status shpd-render (docs/operations/render-service.md)</comment>');
        return 1;
    }

    /** Health check přes RenderClient — protected seam pro testy. */
    protected function renderHealth(RenderConfig $renderConfig): bool
    {
        return (new RenderClient($renderConfig))->health();
    }

    /**
     * @return int number of FPM-related issues
     */
    protected function checkFpmSocket(OutputInterface $output, ?string $expectedSocket): int
    {
        if ($expectedSocket === null) {
            $globDir = dirname($this->getPoolConfigGlob());
            $output->writeln("  ✗ Shipard PHP-FPM pool config not found in {$globDir}");
            $output->writeln('    <comment>→ Re-run: sudo bash scripts/install-packages.sh --mode=...</comment>');
            return 1;
        }
        if (!file_exists($expectedSocket)) {
            $output->writeln("  ✗ FPM socket missing: {$expectedSocket}");
            $output->writeln('    <comment>→ Pool config exists but daemon did not create the socket.</comment>');
            $output->writeln('    <comment>→ Restart: sudo systemctl restart php8.5-fpm</comment>');
            return 1;
        }
        $stat = @stat($expectedSocket);
        if ($stat === false) {
            $output->writeln("  ⚠ Cannot stat FPM socket: {$expectedSocket}");
            return 0;
        }
        $ownerInfo = posix_getpwuid($stat['uid']);
        $ownerName = $ownerInfo['name'] ?? (string) $stat['uid'];
        $output->writeln("  ✓ FPM socket: {$expectedSocket} (owner: {$ownerName})");
        return 0;
    }

    /**
     * Extracts every fastcgi_pass target from an nginx config snippet, ignoring
     * directives inside `#` comments. Works for multi-line as well as inline
     * blocks like `{ fastcgi_pass unix:/x.sock; }`.
     *
     * @return list<string>
     */
    protected function extractFastcgiPassTargets(string $content): array
    {
        // Strip `# ... end-of-line` comments so commented-out directives don't match.
        $stripped = preg_replace('/#[^\n]*/', '', $content);
        if ($stripped === null) {
            $stripped = $content;
        }
        if (!preg_match_all('/\bfastcgi_pass\s+(\S+?);/', $stripped, $matches)) {
            return [];
        }
        return $matches[1];
    }

    /**
     * Iterates ALL files in sites-enabled (regardless of extension — nginx
     * loads `sites-enabled/*` literally) and verifies that every fastcgi_pass
     * directive routes to the shipard FPM socket.
     *
     * @return int number of nginx routing issues
     */
    protected function checkNginxRouting(OutputInterface $output, ?string $expectedSocket): int
    {
        $sitesEnabled = $this->getNginxSitesEnabledDir();
        if (!is_dir($sitesEnabled)) {
            $output->writeln('  ⚠ nginx sites-enabled directory not found');
            return 0;
        }
        if ($expectedSocket === null) {
            $output->writeln('  ⚠ Cannot verify routing — shipard pool config not found');
            return 0;
        }

        $files = glob($sitesEnabled . '/*') ?: [];
        if (count($files) === 0) {
            $output->writeln('  ⚠ No site configs in sites-enabled');
            return 0;
        }

        $shipardSites = 0;
        $foreignSites = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            foreach ($this->extractFastcgiPassTargets($content) as $target) {
                $socketPath = str_starts_with($target, 'unix:')
                    ? substr($target, 5)
                    : $target;
                if ($socketPath === $expectedSocket) {
                    $shipardSites++;
                } else {
                    $foreignSites[] = ['file' => $file, 'target' => $target];
                }
            }
        }

        if ($shipardSites > 0 && count($foreignSites) === 0) {
            $output->writeln("  ✓ nginx routes to shipard socket ({$shipardSites} active site(s))");
            return 0;
        }

        $errors = 0;
        if ($shipardSites === 0) {
            $output->writeln('  ✗ No nginx site routes to shipard FPM socket');
            $output->writeln("    <comment>→ Expected fastcgi_pass: unix:{$expectedSocket}</comment>");
            $errors++;
        }
        foreach ($foreignSites as $site) {
            $output->writeln("  ✗ {$site['file']}");
            $output->writeln("    fastcgi_pass {$site['target']} (not shipard socket)");
            $output->writeln('    <comment>→ Edit this file to use shipard socket, or remove if obsolete</comment>');
            $output->writeln('    <comment>  (Note: nginx loads `sites-enabled/*` regardless of file extension)</comment>');
            $errors++;
        }
        return $errors;
    }
}
