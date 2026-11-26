<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Security\DsSecretCipher;

/**
 * Dočasný adresář zdroje dat pro testy: `config/main.json` a šifrovací klíč
 * v `secrets/`. Úklid volá test v `tearDown()`.
 */
trait TempDataSource
{
    private ?string $dataSourceDir = null;

    protected function createDataSourceDir(): string
    {
        $dir = sys_get_temp_dir() . '/shpd-salesreg-' . bin2hex(random_bytes(8));
        mkdir($dir . '/config', 0750, true);
        mkdir($dir . '/secrets', 0700, true);
        chmod($dir, 0750);
        file_put_contents($dir . '/config/main.json', json_encode([
            'id'            => 'test-test-test-test',
            'name'          => 'Test',
            'database_name' => 'test_test_test_test',
            'database_user' => 'shpd_testtest',
            'created'       => date('c'),
        ]));
        chmod($dir . '/config/main.json', 0600);
        file_put_contents($dir . '/secrets/secrets.key', random_bytes(DsSecretCipher::KEY_BYTES));
        chmod($dir . '/secrets/secrets.key', 0600);
        chmod($dir . '/secrets', 0700);

        return $this->dataSourceDir = $dir;
    }

    protected function dataSourceConfig(): DataSourceConfig
    {
        return new DataSourceConfig($this->dataSourceDir ?? $this->createDataSourceDir());
    }

    protected function removeDataSourceDir(): void
    {
        DsSecretCipher::resetCache();
        if ($this->dataSourceDir !== null) {
            self::removeTree($this->dataSourceDir);
            $this->dataSourceDir = null;
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
