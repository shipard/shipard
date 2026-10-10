<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;

/**
 * „Analýza pošty stojí“ (tasks/ai-analyzer-removal.md D23): zdroj dat má
 * použitelný backend ({@see AnalysisBackendProbe}) a ve frontě analýzy
 * ({@see AnalysisQueue}, tentýž predikát jako runner) leží zpráva déle než
 * {@see STALLED_AFTER_SECONDS} podle `modified`. Jednotlivá selhání běhů
 * vidí uživatel na Dashboardu; tohle hlídá, že runner vůbec běží — cron
 * (`mail-analyze --sweep`), limit `ai.analysis.maxConcurrent`, práva ke
 * slotům. Bez použitelného backendu check mlčí: fronta bez klíče není
 * porucha, ale nedokončené nastavení.
 *
 * Registrace: modules/core/mail/module.jsonc → alertChecks
 * (id core.mail.analysis_stalled, interval 15m).
 */
class AnalysisStalledAlertCheck extends AlertCheck
{
    public const STALLED_AFTER_SECONDS = 900;

    public function run(): array
    {
        if (!new AnalysisBackendProbe($this->db)->hasUsableBackend()) {
            return [];
        }

        $now = $this->now();
        $stalled = new AnalysisQueue($this->db)->stalled(
            self::STALLED_AFTER_SECONDS,
            $now->format('Y-m-d H:i:s'),
        );
        if ($stalled['count'] <= 0) {
            return [];
        }

        return [$this->stalledFinding($stalled['count'], (string) ($stalled['oldest'] ?? ''))];
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    private function stalledFinding(int $count, string $oldest): AlertFinding
    {
        $isCs = $this->language === 'cs';
        $minutes = (int) (self::STALLED_AFTER_SECONDS / 60);

        return new AlertFinding(
            findingKey: 'queue_stalled',
            title: $isCs
                ? 'Analýza pošty stojí'
                : 'Mail analysis is stalled',
            message: $isCs
                ? "{$count} zpráv čeká na AI analýzu déle než {$minutes} minut"
                . " (nejstarší od {$oldest}), přestože je AI backend nastavený."
                . ' Runner `shpd-ds mail-analyze` zprávy nebere — ověř cron'
                . ' (`mail-analyze --sweep` každou minutu), limit'
                . ' `ai.analysis.maxConcurrent` v server.json (0 = analýza'
                . ' vypnutá) a práva k adresáři slotů `/opt/shipard/run`.'
                : "{$count} messages have been waiting for AI analysis for over"
                . " {$minutes} minutes (oldest since {$oldest}) although an AI"
                . ' backend is configured. The `shpd-ds mail-analyze` runner is'
                . ' not picking them up — check cron (`mail-analyze --sweep`'
                . ' every minute), the `ai.analysis.maxConcurrent` limit in'
                . ' server.json (0 = analysis disabled) and permissions of the'
                . ' slot directory `/opt/shipard/run`.',
            severity: 'warning',
            context: ['count' => $count, 'oldest' => $oldest],
        );
    }
}
