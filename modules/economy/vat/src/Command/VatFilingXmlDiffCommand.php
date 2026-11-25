<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Command;

use Shipard\Module\Economy\Vat\Xml\EpoXmlDiff;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Porovnání dvou souborů pro EPO po větách a atributech (issue #55, X9).
 *
 * Nástroj zlatého testu: „dává Shipard za leden totéž, co se doopravdy
 * podalo?". Ignorují se atributy, které se legitimně liší (software,
 * datum podání, kontakty) — `--strict` je porovná taky.
 *
 * Nepotřebuje zdroj dat, jen dva soubory; exit 0 = shoda, 1 = rozdíl.
 */
class VatFilingXmlDiffCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('vat-filing-xml-diff')
            ->setDescription('Porovná dva soubory podání DPH pro EPO po větách a atributech')
            ->addArgument('expected', InputArgument::REQUIRED, 'Referenční soubor (co bylo podáno)')
            ->addArgument('actual', InputArgument::REQUIRED, 'Porovnávaný soubor (co vygeneroval Shipard)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Porovnat i software, datum podání a kontakty');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = [];
        foreach (['expected', 'actual'] as $argument) {
            $path = (string) $input->getArgument($argument);
            if (!is_file($path)) {
                $output->writeln("<error>Soubor '{$path}' neexistuje.</error>");
                return Command::INVALID;
            }
            $files[$argument] = (string) file_get_contents($path);
        }

        try {
            $differences = EpoXmlDiff::compare(
                $files['expected'],
                $files['actual'],
                $input->getOption('strict') ? [] : EpoXmlDiff::DEFAULT_IGNORED,
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::INVALID;
        }

        if ($differences === []) {
            $output->writeln('<info>Soubory se shodují.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Rozdílů: ' . count($differences) . '</comment>');
        $output->writeln(EpoXmlDiff::format($differences));
        return Command::FAILURE;
    }
}
