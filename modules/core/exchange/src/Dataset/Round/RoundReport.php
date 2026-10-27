<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/** Čitelný výpis souhrnu kola (`result.json`, {@see RoundResult}) pro terminál a jednořádkový formát kroku pro sledování. */
final class RoundReport
{
    private const array LAYER_LABELS = [RoundSelection::LAYER_CODE => 'kód', RoundSelection::LAYER_DATASETS => 'sady', RoundSelection::LAYER_VIDEOS => 'videa'];

    private function __construct()
    {
    }

    /** @param array<string, mixed> $step */
    public static function stepLine(array $step): string
    {
        $where = implode(' · ', array_filter([
            self::LAYER_LABELS[$step['layer'] ?? ''] ?? (string) ($step['layer'] ?? '?'),
            is_string($step['dataset'] ?? null) ? $step['dataset'] : null,
            is_string($step['scenario'] ?? null) ? $step['scenario'] : null,
            (string) ($step['label'] ?? $step['step'] ?? '?'),
        ]));
        $status = (string) ($step['status'] ?? '?');
        $extras = [];
        if (is_array($step['counts'] ?? null)) {
            $extras[] = sprintf('%d testů, %d selhalo', (int) ($step['counts']['tests'] ?? 0), (int) ($step['counts']['failed'] ?? 0));
        }
        if (is_array($step['eval'] ?? null)) {
            $e = $step['eval'];
            $extras[] = sprintf('shoda polí %s, cena %s USD', is_numeric($e['fieldsHit'] ?? null) ? number_format((float) $e['fieldsHit'] * 100, 1) . ' %' : '—', is_numeric($e['costUsd'] ?? null) ? number_format((float) $e['costUsd'], 2) : '—');
        }
        if (is_numeric($step['durationMs'] ?? null) && (int) $step['durationMs'] > 0) {
            $extras[] = self::duration((int) $step['durationMs']);
        }
        if (is_string($step['jobId'] ?? null)) {
            $extras[] = 'úloha ' . $step['jobId'];
        }
        if (is_string($step['reason'] ?? null) && $step['reason'] !== '') {
            $extras[] = $step['reason'];
        }
        return sprintf('[%s] %s: %s%s', $where, '', self::statusTag($status), $extras === [] ? '' : ' · ' . implode(' · ', $extras));
    }

    /** @param array<string, mixed> $result */
    public static function writeText(array $result, OutputInterface $output): void
    {
        $git = is_array($result['git'] ?? null) ? $result['git'] : [];
        $output->writeln(sprintf(
            '<comment>Testovací kolo %s</comment> — %s, commit %s%s (%s), %s → %s',
            (string) ($result['jobId'] ?? '?'),
            self::statusTag((string) ($result['status'] ?? '?')),
            is_string($git['commit'] ?? null) ? substr($git['commit'], 0, 8) : '?',
            ($git['dirty'] ?? null) === true ? ' + necommitnuté změny' : '',
            (string) ($git['branch'] ?? '?'),
            (string) ($result['startedAt'] ?? '?'),
            (string) ($result['finishedAt'] ?? '…'),
        ));
        foreach ((array) ($result['dataSources'] ?? []) as $ds) {
            if (!is_array($ds)) {
                continue;
            }
            $output->writeln(sprintf('  zdroj dat %s: %s (%s)%s', (string) ($ds['role'] ?? ''), (string) ($ds['id'] ?? ''), (string) ($ds['dataset'] ?? ''), ($ds['created'] ?? false) ? ' — založen kolem' : ''));
        }
        $table = new Table($output);
        $table->setHeaders(['Vrstva', 'Sada', 'Scénář', 'Krok', 'Stav', 'Doba', 'Úloha', 'Důvod / počty']);
        foreach ((array) ($result['steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $note = [];
            if (is_array($step['counts'] ?? null)) {
                $note[] = sprintf('%d testů, %d selhalo', (int) ($step['counts']['tests'] ?? 0), (int) ($step['counts']['failed'] ?? 0));
            }
            if (is_array($step['eval'] ?? null) && is_numeric($step['eval']['fieldsHit'] ?? null)) {
                $note[] = sprintf('shoda polí %s %%, %s USD', number_format((float) $step['eval']['fieldsHit'] * 100, 1), number_format((float) ($step['eval']['costUsd'] ?? 0), 2));
            }
            if (is_string($step['reason'] ?? null) && $step['reason'] !== '') {
                $note[] = $step['reason'];
            }
            $table->addRow([
                self::LAYER_LABELS[$step['layer'] ?? ''] ?? (string) ($step['layer'] ?? ''),
                (string) ($step['dataset'] ?? ''),
                (string) ($step['scenario'] ?? ''),
                (string) ($step['label'] ?? $step['step'] ?? ''),
                self::statusTag((string) ($step['status'] ?? '')),
                is_numeric($step['durationMs'] ?? null) && (int) $step['durationMs'] > 0 ? self::duration((int) $step['durationMs']) : '',
                (string) ($step['jobId'] ?? ''),
                implode('; ', $note),
            ]);
        }
        $table->render();
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $output->writeln(sprintf(
            'Celkem: %d ok, %d selhalo, %d přeskočeno, %d zrušeno → exit %s',
            (int) ($summary['ok'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['skipped'] ?? 0),
            (int) ($summary['cancelled'] ?? 0),
            var_export($result['exitCode'] ?? null, true),
        ));
    }

    public static function statusTag(string $status): string
    {
        return match ($status) {
            RoundResult::STEP_OK => '<info>ok</info>',
            RoundResult::STEP_FAILED => '<error>failed</error>',
            RoundResult::STEP_SKIPPED, RoundResult::STEP_CANCELLED => '<comment>' . $status . '</comment>',
            default => $status,
        };
    }

    public static function duration(int $ms): string
    {
        if ($ms < 60_000) {
            return number_format($ms / 1000, 1, ',', '') . ' s';
        }
        return sprintf('%d:%02d min', intdiv($ms, 60_000), intdiv($ms % 60_000, 1000));
    }
}
