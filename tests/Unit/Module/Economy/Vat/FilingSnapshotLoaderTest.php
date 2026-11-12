<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\FilingSnapshotLoader;

/**
 * Součty daně per kód DPH ze snapshotu podání: předmět plnění tuzemského
 * přenesení dělí součet jen tam, kde má vlastní analytiku (#89 D9).
 */
final class FilingSnapshotLoaderTest extends TestCase
{
    public function testTaxByCodeSplitsOnlyDistinguishedSubjects(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args): array {
            $this->assertStringContainsString('[kh_reverse_charge_code]', (string) $args[0]);
            $this->assertStringContainsString('[dp3_row] IS NOT NULL', (string) $args[0]);
            return [
                new \Dibi\Row(['vat_code' => 'cz-110', 'subject' => null, 'tax' => '21.00']),
                new \Dibi\Row(['vat_code' => 'cz-115', 'subject' => '14', 'tax' => '100.00']),
                new \Dibi\Row(['vat_code' => 'cz-115', 'subject' => '4', 'tax' => '420.00']),
                new \Dibi\Row(['vat_code' => 'cz-115', 'subject' => '5', 'tax' => '210.00']),
                new \Dibi\Row(['vat_code' => 'cz-203', 'subject' => '5', 'tax' => '210.00']),
            ];
        });
        $loader = new FilingSnapshotLoader($db);

        $this->assertSame(
            ['cz-110' => 21.0, 'cz-115' => 520.0, 'cz-115|5' => 210.0, 'cz-203' => 210.0],
            $loader->taxByCode(9, ['cz-115' => ['5']]),
        );
        $this->assertSame(
            ['cz-110' => 21.0, 'cz-115' => 730.0, 'cz-203' => 210.0],
            $loader->taxByCode(9),
            'bez rozlišovaných předmětů jen per kód',
        );
    }
}
