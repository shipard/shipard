<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report `dataset-verify`: sada, apply, výsledky kontrol, souhrn; text pro
 * člověka a `--json` se stejným obsahem. Exit 0 = vše v pořádku (přeskočené
 * kontroly nevadí), 1 = rozdíl nebo selhání apply.
 */
final class VerifyReport
{
    /** @param list<CheckResult> $results */
    public function __construct(
        public readonly DatasetManifest $manifest,
        public readonly string $dataSourceId,
        public readonly ApplyReport $apply,
        public readonly array $results,
    ) {}

    public function exitCode(): int
    {
        if ($this->apply->failed !== []) {
            return 1;
        }
        foreach ($this->results as $result) {
            if ($result->status === CheckStatus::Diff) {
                return 1;
            }
        }
        return 0;
    }

    /** @return array{ok: int, diff: int, skipped: int} */
    public function summary(): array
    {
        $summary = ['ok' => 0, 'diff' => 0, 'skipped' => 0];
        foreach ($this->results as $result) {
            $summary[$result->status->value]++;
        }
        return $summary;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dataset'    => [
                'name'    => $this->manifest->name,
                'profile' => $this->manifest->profile,
                'country' => $this->manifest->country,
            ],
            'dataSource' => $this->dataSourceId,
            'apply'      => $this->apply->toArray(),
            'checks'     => array_map(static fn (CheckResult $r): array => $r->toArray(), $this->results),
            'summary'    => $this->summary(),
            'exitCode'   => $this->exitCode(),
        ];
    }

    public function writeText(OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<comment>Sada %s</comment> (profil %s, země %s) → zdroj dat %s',
            $this->manifest->name,
            $this->manifest->profile ?? '—',
            $this->manifest->country ?? '—',
            $this->dataSourceId,
        ));
        if ($this->apply->ran) {
            $output->writeln(sprintf(
                'Apply: použito %d, selhalo %d',
                count($this->apply->applied),
                count($this->apply->failed),
            ));
        } else {
            $output->writeln('Apply: vynecháno (--no-apply)');
        }
        foreach ($this->apply->failed as $failure) {
            $output->writeln("   <error>selhalo {$failure['messageId']}: {$failure['code']} {$failure['message']}</error>");
        }
        $output->writeln('');

        foreach ($this->results as $n => $result) {
            $status = match ($result->status) {
                CheckStatus::Ok => '<info>ok</info>',
                CheckStatus::Diff => '<error>rozdíl</error> (' . count($result->differences) . ')',
                CheckStatus::Skipped => '<comment>přeskočeno</comment> — ' . ($result->reason ?? ''),
            };
            $output->writeln(sprintf('<comment>%d. %s</comment> … %s', $n + 1, $result->label, $status));
            foreach ($result->differences as $line) {
                $output->writeln("   - {$line}");
            }
            foreach ($result->notes as $note) {
                $output->writeln("   · {$note}");
            }
        }

        $summary = $this->summary();
        $output->writeln('');
        $output->writeln(sprintf(
            'Souhrn: ok %d, rozdíl %d, přeskočeno %d → %s',
            $summary['ok'],
            $summary['diff'],
            $summary['skipped'],
            $this->exitCode() === 0 ? '<info>V pořádku.</info>' : '<error>Rozdíly.</error>',
        ));
    }
}
