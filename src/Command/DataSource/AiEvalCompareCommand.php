<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalRunComparison;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds ai-eval-compare <A.json> <B.json> [--json]` — dva soubory
 * výsledků evaluace vedle sebe (#103 D38, {@see EvalRunComparison}):
 * metriky s rozdílem a označením „v rámci šumu“, pole zlepšená
 * / zhoršená, cena a čas. Běhy nad různými sadami odmítne (exit 2). Nic
 * nevolá a nezapisuje — běží i mimo adresář zdroje dat.
 */
class AiEvalCompareCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ai-eval-compare')
            ->setDescription('Porovnání dvou běhů evaluace AI (soubory tests/eval/<sada>/*.json): metriky s rozdílem, šum opakování, pole zlepšená / zhoršená')
            ->addArgument('a', InputArgument::REQUIRED, 'Soubor výsledků A')
            ->addArgument('b', InputArgument::REQUIRED, 'Soubor výsledků B')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Porovnání jako JSON na stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $a = EvalRunStore::read((string) $input->getArgument('a'));
            $a['_file'] = basename((string) $input->getArgument('a'));
            $b = EvalRunStore::read((string) $input->getArgument('b'));
            $b['_file'] = basename((string) $input->getArgument('b'));
            $comparison = EvalRunComparison::of($a, $b);
        } catch (EvalException $e) {
            $err->writeln('<error>ai-eval-compare odmítnuto:</error>');
            $err->writeln('  - ' . $e->getMessage());
            return Command::INVALID;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($comparison->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
            return Command::SUCCESS;
        }
        foreach ($comparison->lines() as $line) {
            $output->writeln($line);
        }
        return Command::SUCCESS;
    }
}
