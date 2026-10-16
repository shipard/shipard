<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\DataSourceInventory;

/**
 * Sdílený čtenář seznamu zdrojů dat (#126 D10): allowlist bez tajemství,
 * shovívavé čtení `main.json`, stav ze `state.json`, řazení podle ID.
 */
class DataSourceInventoryTest extends TestCase
{
    private string $tmpDir;
    private string $dsDir;

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ds-inventory-' . uniqid();
        $this->dsDir = $this->tmpDir . '/data-sources';
        mkdir($this->dsDir, 0755, true);
        ErrorLogger::setLogPath($this->tmpDir . '/shipard.log');
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rw ' . escapeshellarg($this->tmpDir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->tmpDir));
        ErrorLogger::resetForTesting();
    }

    /** @param array<string, mixed>|string $main */
    private function createDs(string $id, array|string $main, ?string $stateJson = null): string
    {
        $dir = $this->dsDir . '/' . $id;
        mkdir($dir . '/config', 0755, true);
        file_put_contents($dir . '/config/main.json', is_array($main) ? json_encode($main, JSON_UNESCAPED_UNICODE) : $main);
        if ($stateJson !== null) {
            file_put_contents($dir . '/config/state.json', $stateJson);
        }
        return $dir;
    }

    /** @return array<string, mixed> plný main.json vč. tajemství, která do přehledu nesmí */
    private function fullMainJson(): array
    {
        return [
            'id'                => 'aaaa-aaaa-aaaa-aaaa',
            'name'              => 'Alpha',
            'modules'           => ['install.base', 'core.mail'],
            'defaultLanguage'   => 'cs',
            'country'           => 'cz',
            'database_name'     => 'aaaa_aaaa_aaaa_aaaa',
            'database_user'     => 'shpd_aaaaaaaa',
            'database_password' => 'S3cret!Pw',
            'created'           => '2026-10-01T10:00:00+02:00',
            'dataClass'         => 'free',
            'purpose'           => '  Ruční zkoušení UI  ',
            'origin'            => 'dataset-seed cz-vat-payer',
            'dataClassChanged'  => '2026-10-16T12:00:00Z',
            'auth'              => ['local' => true, 'providers' => [['id' => 'shipard-id', 'clientSecret' => 'oidc-secret-value']]],
            'mail'              => ['relay' => ['host' => 'smtp.example', 'password' => 'relay-pw-value']],
            'ai'                => ['useServerConnection' => true],
            'enableReset'       => true,
        ];
    }

    public function testReadsTheAllowlistAndNeverSecrets(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', $this->fullMainJson());

        $infos = (new DataSourceInventory($this->dsDir))->scan();
        $this->assertCount(1, $infos);
        $info = $infos[0];

        $this->assertSame('aaaa-aaaa-aaaa-aaaa', $info->id);
        $this->assertSame('Alpha', $info->name);
        $this->assertSame('2026-10-01T10:00:00+02:00', $info->created);
        $this->assertSame('cz', $info->country);
        $this->assertSame('cs', $info->defaultLanguage);
        $this->assertSame(['install.base', 'core.mail'], $info->modules);
        $this->assertSame('aaaa_aaaa_aaaa_aaaa', $info->databaseName);
        $this->assertSame(DataClass::Free, $info->dataClass);
        $this->assertTrue($info->dataClassExplicit);
        $this->assertFalse($info->dataClassInvalid);
        $this->assertSame('Ruční zkoušení UI', $info->purpose, 'účel se trimuje');
        $this->assertSame('dataset-seed cz-vat-payer', $info->origin);
        $this->assertSame('2026-10-16T12:00:00Z', $info->dataClassChanged);
        $this->assertSame('active', $info->state);
        $this->assertTrue($info->cronEnabled);
        $this->assertTrue($info->legacyServerAiFlag);
        $this->assertTrue($info->readable);

        // D5: žádné tajemství — ani klíče, ani hodnoty, ani celé sekce auth / mail.
        foreach ([json_encode((array) $info), json_encode($info->toArray(true))] as $dump) {
            foreach (['database_password', 'S3cret!Pw', 'database_user', 'shpd_aaaaaaaa', 'clientSecret', 'oidc-secret-value', 'relay-pw-value', '"auth"', '"mail"', 'enableReset'] as $needle) {
                $this->assertStringNotContainsString($needle, (string) $dump, "'{$needle}' nepatří do přehledu");
            }
        }
    }

    public function testUsesServerAiOnlyForAFreeDataSourceWhenTheServerOffersIt(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', $this->fullMainJson());
        $this->createDs('bbbb-bbbb-bbbb-bbbb', ['id' => 'bbbb-bbbb-bbbb-bbbb', 'name' => 'Bravo', 'dataClass' => 'realCopy']);
        [$free, $realCopy] = (new DataSourceInventory($this->dsDir))->scan();

        $this->assertTrue($free->usesServerAi(true));
        $this->assertFalse($free->usesServerAi(false), 'server klíč nenabízí');
        $this->assertFalse($realCopy->usesServerAi(true), 'kopie reálných dat serverové AI nedostane');
        $this->assertTrue($free->toArray(true)['serverAi']);
        $this->assertFalse($realCopy->toArray(true)['serverAi']);
    }

    public function testMissingAndInvalidDataClassCountAsRealCopy(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', ['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'Alpha']);
        $this->createDs('bbbb-bbbb-bbbb-bbbb', ['id' => 'bbbb-bbbb-bbbb-bbbb', 'name' => 'Bravo', 'dataClass' => 'Free']);
        $this->createDs('cccc-cccc-cccc-cccc', ['id' => 'cccc-cccc-cccc-cccc', 'name' => 'Charlie', 'dataClass' => null]);
        [$missing, $invalid, $null] = (new DataSourceInventory($this->dsDir))->scan();

        $this->assertSame(DataClass::RealCopy, $missing->dataClass);
        $this->assertFalse($missing->dataClassExplicit);
        $this->assertFalse($missing->dataClassInvalid, 'chybějící klíč není neplatná hodnota');
        $this->assertFalse($missing->legacyServerAiFlag);
        $this->assertNull($missing->purpose);

        $this->assertSame(DataClass::RealCopy, $invalid->dataClass);
        $this->assertFalse($invalid->dataClassExplicit);
        $this->assertTrue($invalid->dataClassInvalid, 'U6: neplatná hodnota se označí');

        $this->assertFalse($null->dataClassInvalid, 'null = jako chybějící');
    }

    public function testInvalidJsonGivesAnUnreadablePlaceholderAndDirsWithoutMainJsonAreSkipped(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', '{not json', '{"version":1,"state":"active","cron":false}');
        mkdir($this->dsDir . '/lost+found', 0755, true);
        mkdir($this->dsDir . '/bbbb-bbbb-bbbb-bbbb/config', 0755, true); // adresář bez main.json

        $inventory = new DataSourceInventory($this->dsDir);
        $infos = $inventory->scan();
        $this->assertCount(1, $infos);
        $info = $infos[0];

        $this->assertFalse($info->readable);
        $this->assertSame('aaaa-aaaa-aaaa-aaaa', $info->id, 'ID = název adresáře');
        $this->assertSame('?', $info->name);
        $this->assertSame([], $info->modules);
        $this->assertNull($info->databaseName);
        $this->assertSame(DataClass::RealCopy, $info->dataClass);
        $this->assertFalse($info->dataClassExplicit);
        $this->assertFalse($info->usesServerAi(true));
        $this->assertSame('active', $info->state, 'state.json se čte nezávisle na main.json');
        $this->assertFalse($info->cronEnabled);
        $this->assertFalse($info->toArray(true)['readable']);

        $this->assertNull($inventory->read($this->dsDir . '/bbbb-bbbb-bbbb-bbbb'));
        $this->assertNull($inventory->read($this->dsDir . '/nope'));
    }

    public function testUnreadableFileGivesAnUnreadablePlaceholder(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root přečte i soubor bez práv');
        }
        $dir = $this->createDs('aaaa-aaaa-aaaa-aaaa', $this->fullMainJson());
        chmod($dir . '/config/main.json', 0000);

        $info = (new DataSourceInventory($this->dsDir))->read($dir);
        $this->assertNotNull($info);
        $this->assertFalse($info->readable);
        $this->assertSame('?', $info->name);
    }

    public function testStateAndCronComeFromStateJson(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', ['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'Alpha'], '{"version":1,"state":"active","cron":false}');
        $this->createDs('bbbb-bbbb-bbbb-bbbb', ['id' => 'bbbb-bbbb-bbbb-bbbb', 'name' => 'Bravo'],
            '{"version":1,"state":"read_only","maintenance":{"reason":"import","since":"2026-10-01T00:00:00Z"}}');
        $this->createDs('cccc-cccc-cccc-cccc', ['id' => 'cccc-cccc-cccc-cccc', 'name' => 'Charlie'], '{broken');
        [$cronOff, $maintenance, $corrupted] = (new DataSourceInventory($this->dsDir))->scan();

        $this->assertSame('active', $cronOff->state);
        $this->assertFalse($cronOff->cronEnabled);
        $this->assertSame('suspended', $maintenance->state, 'maintenance překrývá stav');
        $this->assertTrue($maintenance->cronEnabled);
        $this->assertSame('corrupted', $corrupted->state);
        $this->assertTrue($corrupted->readable, 'main.json je v pořádku, poškozený je jen state.json');
    }

    public function testScanIsSortedByIdAndMissingDirIsEmpty(): void
    {
        $this->createDs('cccc-cccc-cccc-cccc', ['id' => 'cccc-cccc-cccc-cccc', 'name' => 'Charlie']);
        $this->createDs('aaaa-aaaa-aaaa-aaaa', ['id' => 'aaaa-aaaa-aaaa-aaaa', 'name' => 'Alpha']);
        $this->createDs('bbbb-bbbb-bbbb-bbbb', ['id' => 'bbbb-bbbb-bbbb-bbbb', 'name' => 'Bravo']);

        $ids = array_map(static fn($i) => $i->id, (new DataSourceInventory($this->dsDir))->scan());
        $this->assertSame(['aaaa-aaaa-aaaa-aaaa', 'bbbb-bbbb-bbbb-bbbb', 'cccc-cccc-cccc-cccc'], $ids);

        $this->assertSame([], (new DataSourceInventory($this->tmpDir . '/missing'))->scan());
    }

    public function testToArrayFollowsTheJsonContract(): void
    {
        $this->createDs('aaaa-aaaa-aaaa-aaaa', $this->fullMainJson());
        $array = (new DataSourceInventory($this->dsDir))->scan()[0]->toArray(false);

        $this->assertSame(
            ['id', 'name', 'created', 'dataClass', 'dataClassExplicit', 'dataClassInvalid', 'purpose', 'origin',
             'dataClassChanged', 'country', 'defaultLanguage', 'modules', 'databaseName', 'state', 'cron', 'serverAi', 'readable'],
            array_keys($array),
        );
        $this->assertSame('free', $array['dataClass']);
        $this->assertTrue($array['cron']);
        $this->assertFalse($array['serverAi']);
        $this->assertArrayNotHasKey('legacyServerAiFlag', $array, 'zaniklý příznak do kontraktu nepatří');
    }
}
