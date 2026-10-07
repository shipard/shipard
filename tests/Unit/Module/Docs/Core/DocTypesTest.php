<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * Atribut `tax_document` typu dokladu (#79 D1): chybí → daňový doklad,
 * `false` → nedaňový. Jediná autorita čtení je `DocTypes`.
 */
class DocTypesTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $docTypes */
    private function config(array $docTypes): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    public function testMissingAttributeMeansTaxDocument(): void
    {
        $config = $this->config(['invno' => ['trade_dir' => 1]]);

        $this->assertTrue(DocTypes::isTaxDocument($config, 'invno'));
    }

    public function testExplicitFalseMeansNonTaxDocument(): void
    {
        $config = $this->config([
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
            'invni' => ['trade_dir' => 2],
            'invpi' => ['trade_dir' => 2, 'tax_document' => false],
        ]);

        $this->assertFalse(DocTypes::isTaxDocument($config, 'invpo'));
        $this->assertFalse(DocTypes::isTaxDocument($config, 'invpi'), 'zálohová faktura přijatá je nedaňová (#106 D1)');
        $this->assertTrue(DocTypes::isTaxDocument($config, 'invno'));
        $this->assertTrue(DocTypes::isTaxDocument($config, 'invni'));
    }

    public function testExplicitTrueAndOtherValuesMeanTaxDocument(): void
    {
        $config = $this->config([
            'a' => ['tax_document' => true],
            'b' => ['tax_document' => 0],
            'c' => ['tax_document' => null],
        ]);

        $this->assertTrue(DocTypes::isTaxDocument($config, 'a'));
        $this->assertTrue(DocTypes::isTaxDocument($config, 'b'), 'jen striktní false je nedaňový');
        $this->assertTrue(DocTypes::isTaxDocument($config, 'c'));
    }

    public function testUnknownTypeAndMissingConfigDefaultToTaxDocument(): void
    {
        $config = $this->config(['invpo' => ['tax_document' => false]]);

        $this->assertTrue(DocTypes::isTaxDocument($config, 'unknown'));
        $this->assertTrue(DocTypes::isTaxDocument($config, ''));
        $this->assertTrue(DocTypes::isTaxDocument(null, 'invpo'));
    }

    public function testNonTaxDocTypesListsOnlyExplicitFalse(): void
    {
        $config = $this->config([
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
            'invpi' => ['trade_dir' => 2, 'tax_document' => false],
            'x'     => ['tax_document' => true],
            'y'     => 'not-an-array',
        ]);

        $this->assertSame(['invpo', 'invpi'], DocTypes::nonTaxDocTypes($config));
        $this->assertSame([], DocTypes::nonTaxDocTypes(null));
        $this->assertSame([], DocTypes::nonTaxDocTypes($this->config([])));
    }

    /** Pojistka nad reálnou konfigurací: nedaňové jsou jen zálohové faktury (vydaná #79, přijatá #106). */
    public function testRealConfigMarksOnlyProformasAsNonTax(): void
    {
        $docTypes = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/docs/core/config/docTypes.jsonc',
        );
        $config = $this->config($docTypes);

        $this->assertSame(['invpo', 'invpi'], DocTypes::nonTaxDocTypes($config));
        foreach (['invno', 'invni', 'cmnbkp', 'cash', 'cashreg'] as $type) {
            $this->assertTrue(DocTypes::isTaxDocument($config, $type), $type);
        }
    }
}
