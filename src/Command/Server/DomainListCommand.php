<?php
declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Server\DomainsFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends Command
{
	public function __construct(
		private readonly ?string $domainsFile    = null,
		private readonly ?string $dataSourcesDir = null,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->setName('domain-list')
			->setDescription('List all host → data source mappings');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$domainsFile    = DomainsFile::effectiveDomainsFile($this->domainsFile);
		$dataSourcesDir = DomainsFile::effectiveDataSourcesDir($this->dataSourcesDir);

		if (!file_exists($domainsFile)) {
			$output->writeln('<comment>No domains file found. Use domain-add to create mappings.</comment>');
			return Command::SUCCESS;
		}

		try {
			$map = DomainsFile::load($domainsFile);
		} catch (\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		if (empty($map)) {
			$output->writeln('<comment>No domain mappings configured.</comment>');
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['Host', 'DS ID', 'DS Name']);

		foreach ($map as $host => $dsId) {
			$dsName = $this->resolveDsName($dataSourcesDir, $dsId);
			$table->addRow([$host, $dsId, $dsName]);
		}

		$table->render();
		return Command::SUCCESS;
	}

	/** Název přes sdílený čtenář (#126 D10); chybějící nebo nečitelný main.json = `<unknown>`. */
	protected function resolveDsName(string $dataSourcesDir, string $dsId): string
	{
		$info = (new DataSourceInventory($dataSourcesDir))->read($dataSourcesDir . '/' . $dsId);
		return $info === null || !$info->readable ? '<unknown>' : $info->name;
	}
}
