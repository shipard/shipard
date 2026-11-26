<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Command;

use Shipard\Module\Economy\PeriodicInvoicing\Command\PeriodicInvoicingRunCommand;
use Shipard\Module\Economy\WorkOrders\WorkOrderInvoicingSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Alias běhu periodické fakturace pro zakázky (#175 D6) — **zanikne
 * v dalším vydání**, používej `periodic-invoicing-run`:
 *
 *   work-orders-invoice-run                     = periodic-invoicing-run --source=workOrders
 *   work-orders-invoice-run --work-order=<číslo> = … --source=workOrders --record=<číslo>
 *
 * Samostatná třída, ne `setAliases` (jiná volba `--work-order`); úlohu
 * cronu nemá — denní běh je `periodic-invoicing-run`.
 */
class WorkOrdersInvoiceRunCommand extends PeriodicInvoicingRunCommand
{
    protected function configure(): void
    {
        $this->setName('work-orders-invoice-run')
            ->setDescription('Alias periodic-invoicing-run --source=workOrders (zanikne v dalším vydání) — koncepty faktur periodických zakázek za splatná období; --work-order=<číslo> jen jedna zakázka bez pojistky dohánění, --date, --dry-run')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Run date YYYY-MM-DD (default today)')
            ->addOption('work-order', null, InputOption::VALUE_REQUIRED, 'Only this work order (number); issues all due periods regardless of the catch-up guard')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List periods and documents that would be issued, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>work-orders-invoice-run je alias periodic-invoicing-run --source=workOrders a zanikne v dalším vydání.</comment>');

        return $this->runInvoicing($input, $output, WorkOrderInvoicingSource::ID, self::stringOption($input, 'work-order'));
    }
}
