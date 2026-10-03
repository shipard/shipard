<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintLanguageNotCompiledException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintRunnerFactory;

/**
 * Runner z továrny čte kompilovanou konfiguraci zdroje dat v jazyce tisku.
 * Jazyk, pro který zdroj dat konfiguraci nemá (čeká na `ds-upgrade`), končí
 * čitelnou chybou — ne tiskem bez popisků (#90 D29).
 */
class PrintRunnerFactoryTest extends TestCase
{
    private string $dsDir = '';

    protected function setUp(): void
    {
        FactoryFakePrintBuilder::$lastRequest = null;

        $this->dsDir = sys_get_temp_dir() . '/shpd_print_factory_' . uniqid('', true);
        mkdir($this->dsDir . '/config/configuration', 0755, true);
        mkdir($this->dsDir . '/modules', 0755, true);
        file_put_contents(
            $this->dsDir . '/config/configuration/compiled.en.json',
            (string) json_encode(['items' => ['test.item' => ['name' => 'Item']]]),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dsDir . '/config/configuration/compiled.en.json');
        @rmdir($this->dsDir . '/config/configuration');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir . '/modules');
        @rmdir($this->dsDir);
    }

    private function runner(): PrintRunner
    {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(
            PrintDefinitionTest::declaration(['builder' => FactoryFakePrintBuilder::class, 'catalogs' => []]),
            'docs.invoicesOut',
        ));

        $dsConfig = $this->createStub(DataSourceConfig::class);
        $dsConfig->method('getDataSourceDir')->willReturn($this->dsDir);
        $dsConfig->method('getCountry')->willReturn('cz');

        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 123, 'doc_type' => 'invno', 'docState' => 40]);

        return PrintRunnerFactory::create(
            $registry,
            $dsConfig,
            $db,
            new ModulePathResolver([$this->dsDir . '/modules']),
        );
    }

    public function testBuilderGetsCompiledConfigInPrintLanguage(): void
    {
        $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'en');

        $this->assertSame(
            ['name' => 'Item'],
            FactoryFakePrintBuilder::$lastRequest?->config?->cfgItem('test.item'),
        );
    }

    public function testLanguageWithoutCompiledConfigIsAReadableError(): void
    {
        try {
            $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'cs');
            $this->fail('Tisk v jazyce bez kompilované konfigurace nesmí vzniknout.');
        } catch (PrintLanguageNotCompiledException $e) {
            $this->assertSame('cs', $e->language);
            $this->assertStringContainsString("'cs'", $e->getMessage());
            $this->assertStringContainsString('ds-upgrade', $e->getMessage());
        }
        $this->assertNull(FactoryFakePrintBuilder::$lastRequest, 'builder se nespustil');
    }
}

class FactoryFakePrintBuilder implements PrintBuilder
{
    public static ?PrintRequest $lastRequest = null;

    public function build(PrintRequest $request): PrintBuildResult
    {
        self::$lastRequest = $request;

        return new PrintBuildResult(data: [], title: 'Faktura', fileName: 'faktura.pdf');
    }

    public function version(): int
    {
        return 1;
    }
}
