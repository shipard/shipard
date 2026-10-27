<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\E2e;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\PlaywrightInstall;

/** Instalace Playwrightu jednoho projektu: revize z `browsers.json`, adresář prohlížeče, značka hotové instalace. */
final class PlaywrightInstallTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_pw_install_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/node_modules/playwright-core', 0700, true);
        mkdir($this->dir . '/browsers', 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    public function testRevisionBrowserDirAndMarker(): void
    {
        $install = new PlaywrightInstall($this->dir . '/node_modules/', $this->dir . '/browsers/');
        $this->assertSame($this->dir . '/node_modules/playwright-core/browsers.json', $install->browsersJsonPath());
        $this->assertNull($install->chromiumRevision(), 'bez browsers.json');
        $this->assertNull($install->browserDir());
        $this->assertFalse($install->isInstalled());

        file_put_contents($install->browsersJsonPath(), '{"browsers":[{"name":"firefox","revision":"1"},{"name":"chromium","revision":" 1234 "}]}');
        $this->assertSame('1234', $install->chromiumRevision());
        $this->assertSame($this->dir . '/browsers/chromium-1234', $install->browserDir());
        $this->assertFalse($install->isInstalled(), 'bez značky');
        mkdir($this->dir . '/browsers/chromium-1234', 0700, true);
        file_put_contents($this->dir . '/browsers/chromium-1234/INSTALLATION_COMPLETE', '');
        $this->assertTrue($install->isInstalled());

        file_put_contents($install->browsersJsonPath(), '{"browsers":[{"name":"chromium"}]}');
        $this->assertNull($install->chromiumRevision(), 'bez revize');
        file_put_contents($install->browsersJsonPath(), 'nope');
        $this->assertNull($install->chromiumRevision(), 'rozbitý JSON');
    }
}
