<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core\Command;

use Shipard\Core\Hosting\OpKeyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Vygeneruje podpisový RSA klíč OIDC OP hostingu do secrets/oidc-op.key
 * (PEM, 0600). Existující klíč → chyba; rotace = vědomé smazání souboru
 * (nový klíč zneplatní JWKS cache na klientských DS až po refresh throttle).
 *
 * Spec: tasks/hosting-02-oidc-op.md, docs/hosting.md §5.4 (D2).
 */
class HostingOidcInitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hosting-oidc-init')
             ->setDescription('Generate the OIDC OP RSA signing key (secrets/oidc-op.key)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        try {
            $result = OpKeyStore::generateKey($dsDir);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>OIDC OP key created: ' . OpKeyStore::keyFilePath($dsDir) . '</info>');
        $output->writeln("kid: {$result['kid']}");
        foreach ($result['warnings'] as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        $output->writeln('');
        $output->writeln('<comment>Next steps:</comment>');
        $output->writeln('  1. Set the issuer: Settings -> Hosting -> OIDC provider (hosting.oidc.issuer)');
        $output->writeln('  2. Register client data sources: shpd-ds hosting-oidc-client --ds <ds_id> ...');

        return Command::SUCCESS;
    }
}
