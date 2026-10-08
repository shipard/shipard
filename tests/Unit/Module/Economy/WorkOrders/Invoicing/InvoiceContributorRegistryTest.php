<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\AbstractInvoiceContributor;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\ContributionContext;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\ContributionResult;
use Shipard\Module\Economy\WorkOrders\Invoicing\Contributor\InvoiceContributorRegistry;

/**
 * Registr přispěvatelů (D10) z cfgItem složeného z registrací modulů:
 * instance s injektovanou DB, nabídka pro formulář, známá id; chybná
 * třída = chyba konfigurace.
 */
class InvoiceContributorRegistryTest extends TestCase
{
    private function config(?array $declared): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === InvoiceContributorRegistry::CFG_ITEM ? $declared : null,
        );
        return $config;
    }

    public function testFromConfigInstantiatesContributorsAndInjectsDependencies(): void
    {
        $db = $this->createMock(Connection::class);
        $registry = InvoiceContributorRegistry::fromConfig(
            $this->config(['energy.consumption' => ['class' => RegistryTestContributor::class, 'name' => 'Spotřeba energií']]),
            $db,
        );

        $this->assertSame(['energy.consumption'], $registry->ids());
        $this->assertFalse($registry->isEmpty());
        $contributor = $registry->get('energy.consumption');
        $this->assertInstanceOf(RegistryTestContributor::class, $contributor);
        $this->assertSame($db, $contributor->db());
        $this->assertNull($registry->get('water'));
    }

    public function testWithoutConfigRegistryIsEmptyAndIdsAreNotChecked(): void
    {
        $registry = InvoiceContributorRegistry::fromConfig(null);
        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], InvoiceContributorRegistry::options(null));
        $this->assertNull(InvoiceContributorRegistry::isKnown(null, 'anything'));
        $this->assertNull(InvoiceContributorRegistry::isKnown($this->config(null), 'anything'));
    }

    public function testOptionsAndIsKnownFollowTheConfig(): void
    {
        $config = $this->config(['energy.consumption' => ['class' => RegistryTestContributor::class, 'name' => 'Spotřeba energií']]);
        $this->assertSame([['value' => 'energy.consumption', 'label' => 'Spotřeba energií']], InvoiceContributorRegistry::options($config));
        $this->assertTrue(InvoiceContributorRegistry::isKnown($config, 'energy.consumption'));
        $this->assertFalse(InvoiceContributorRegistry::isKnown($config, 'water'));
    }

    public function testUnknownClassIsAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);
        InvoiceContributorRegistry::fromConfig($this->config(['x' => ['class' => 'Nope\\Missing', 'name' => 'X']]));
    }

    public function testClassWithoutInterfaceIsAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);
        InvoiceContributorRegistry::fromConfig($this->config(['x' => ['class' => \stdClass::class, 'name' => 'X']]));
    }
}

class RegistryTestContributor extends AbstractInvoiceContributor
{
    public function id(): string
    {
        return 'energy.consumption';
    }

    public function contribute(ContributionContext $context): ContributionResult
    {
        return ContributionResult::waiting('test');
    }

    public function db(): ?Connection
    {
        return $this->db;
    }
}
