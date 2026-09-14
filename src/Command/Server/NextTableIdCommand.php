<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NextTableIdCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $help = <<<HELP
Prints the next available tableId across all configured module roots
(the in-repo modules/ directory plus any extra paths listed in
server.json's extraModulesPath).

Reserved tableId ranges (convention, not enforced):
      1 -  9 999  Core (official Shipard modules)
  10 000 - 19 999 Custom (in-house customer modules)
  20 000 - 29 999 Vendor (third-party paid modules)
  30 000 - 65 535 Reserve

Use --range to constrain the search to a specific range. Useful
when allocating IDs for a customer or vendor module:

  bin/shpd-server next-table-id --range=10000:10099

Without --range, the command returns max(used) + 1 across all roots,
or 1 if no tableIds exist yet.
HELP;

        $this->setName('next-table-id')
             ->setDescription('Print the next available table ID')
             ->setHelp($help)
             ->addOption(
                 'range',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Allocate within an inclusive range N:M (e.g. 10000:10099)',
             );
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resolver = $this->getModulePathResolver();

        $rangeOption = $input->getOption('range');
        $range = null;
        if ($rangeOption !== null) {
            $range = self::parseRange((string) $rangeOption);
            if ($range === null) {
                $output->writeln('<error>Invalid --range format. Expected N:M with 1 <= N <= M <= 65535.</error>');
                return Command::FAILURE;
            }
        }

        $used = self::collectUsedIds($resolver);

        if ($range !== null) {
            [$low, $high] = $range;
            for ($i = $low; $i <= $high; $i++) {
                if (!isset($used[$i])) {
                    $output->writeln((string) $i);
                    return Command::SUCCESS;
                }
            }
            $slots = $high - $low + 1;
            $output->writeln("<error>No free tableId in range $low:$high (all $slots slots taken)</error>");
            return Command::FAILURE;
        }

        $next = empty($used) ? 1 : (max(array_keys($used)) + 1);
        $output->writeln((string) $next);
        return Command::SUCCESS;
    }

    /**
     * @return array{int,int}|null  [low, high] or null on parse failure.
     */
    private static function parseRange(string $raw): ?array
    {
        if (!preg_match('/^(\d+):(\d+)$/', $raw, $m)) return null;
        $low  = (int) $m[1];
        $high = (int) $m[2];
        if ($low < 1 || $high > 65535 || $low > $high) return null;
        return [$low, $high];
    }

    /**
     * Walks all modules in all roots and returns a map of
     * `tableId => path of the .jsonc file declaring it`.
     *
     * Duplicates are silently overwritten by the later occurrence — this
     * command only hands out the next id, it does not validate. ds-upgrade
     * has its own collision detection.
     *
     * @return array<int, string>
     */
    private static function collectUsedIds(ModulePathResolver $resolver): array
    {
        $used = [];
        foreach ($resolver->allModuleIds() as $moduleId) {
            $modulePath = $resolver->getPath($moduleId);
            if ($modulePath === null) continue;
            $tablesDir = $modulePath . '/tables';
            if (!is_dir($tablesDir)) continue;
            $entries = @scandir($tablesDir) ?: [];
            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '.jsonc')) continue;
                $file = $tablesDir . '/' . $entry;
                if (!is_file($file)) continue;
                try {
                    $data = JsoncParser::parseFile($file);
                } catch (\Throwable) {
                    continue;
                }
                if (isset($data['tableId']) && is_int($data['tableId']) && $data['tableId'] > 0) {
                    $used[$data['tableId']] = $file;
                }
            }
        }
        return $used;
    }
}
