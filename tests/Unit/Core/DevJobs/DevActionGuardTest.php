<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevJobs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevJobs\DevActionGuard;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;

/**
 * Pojistka D60: jen development, jen třída `free` (realCopy, bez třídy
 * i nečitelný main.json = 403), sada ze seznamu; `create` bez zdroje dat.
 */
final class DevActionGuardTest extends TestCase
{
    private string $dir;
    private const array SETS = ['cz-vat-payer', 'cz-non-payer'];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_guard_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/config/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*/config') ?: [] as $d) {
            @rmdir($d);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    private function ds(string $id, string|array $config): DataSourceInfo
    {
        mkdir($this->dir . '/' . $id . '/config', 0700, true);
        $json = is_array($config)
            ? json_encode(['id' => $id, 'name' => 'DS ' . $id, 'database_name' => 'x'] + $config)
            : $config;
        file_put_contents($this->dir . '/' . $id . '/config/main.json', $json);
        $info = (new DataSourceInventory($this->dir))->read($this->dir . '/' . $id);
        $this->assertNotNull($info);
        return $info;
    }

    public function testFreeDataSourceInDevelopmentPasses(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', ['dataClass' => 'free']);

        $this->assertNull(DevActionGuard::check('development', $ds, true, 'cz-vat-payer', self::SETS));
        $this->assertNull(DevActionGuard::check('development', $ds, true, null, self::SETS));
    }

    public function testRealCopyIsRefused(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', ['dataClass' => 'realCopy', 'purpose' => 'účtárna']);
        $problem = DevActionGuard::check('development', $ds, true, 'cz-vat-payer', self::SETS);

        $this->assertSame(DevActionGuard::CODE_DS_NOT_FREE, $problem['code'] ?? null);
        $this->assertSame(403, $problem['status'] ?? null);
    }

    public function testMissingClassCountsAsRealCopy(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', []);
        $problem = DevActionGuard::check('development', $ds, true, null, self::SETS);

        $this->assertSame(403, $problem['status'] ?? null);
        $this->assertStringContainsString('not set in main.json', $problem['message'] ?? '');
    }

    public function testUnreadableMainJsonIsRefused(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', '{not json');
        $problem = DevActionGuard::check('development', $ds, true, null, self::SETS);

        $this->assertSame(DevActionGuard::CODE_DS_NOT_FREE, $problem['code'] ?? null);
        $this->assertStringContainsString('unreadable', $problem['message'] ?? '');
    }

    public function testOtherServerModesAreRefusedEvenForFree(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', ['dataClass' => 'free']);

        foreach (['production', 'staging', 'unknown'] as $mode) {
            $problem = DevActionGuard::check($mode, $ds, true, 'cz-vat-payer', self::SETS);
            $this->assertSame(DevActionGuard::CODE_FORBIDDEN, $problem['code'] ?? null, $mode);
            $this->assertSame(403, $problem['status'] ?? null, $mode);
        }
    }

    public function testUnknownDatasetIsRefused(): void
    {
        $ds = $this->ds('aaaa-bbbb-cccc-dddd', ['dataClass' => 'free']);
        $problem = DevActionGuard::check('development', $ds, true, '../secret', self::SETS);

        $this->assertSame(DevActionGuard::CODE_UNKNOWN_DATASET, $problem['code'] ?? null);
        $this->assertSame(400, $problem['status'] ?? null);
    }

    public function testCreateWithoutDataSource(): void
    {
        $this->assertNull(DevActionGuard::check('development', null, false, 'cz-vat-payer', self::SETS));

        $problem = DevActionGuard::check('development', null, true, 'cz-vat-payer', self::SETS);
        $this->assertSame(DevActionGuard::CODE_DS_NOT_FOUND, $problem['code'] ?? null);
        $this->assertSame(404, $problem['status'] ?? null);
    }
}
