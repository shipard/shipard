<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadState;
use Shipard\Tests\Fixtures\Core\Settings\InMemoryKeyValueStore;

/** Stav pokusů v nastavení (#121 task B §4.3): čtení, zápis jen po změně, prořezání měsíců mimo okno. */
class DownloadStateTest extends TestCase
{
    public function testReadsStoredStateAndIgnoresGarbage(): void
    {
        $settings = new InMemoryKeyValueStore([DownloadState::KEY => [
            'lastError' => 1000,
            'attempts'  => ['cz.cnb.daily|2026-10' => 2000, 'bad' => 'x', 5 => 7],
        ]]);
        $state = new DownloadState($settings);

        $this->assertSame(1000, $state->lastError());
        $this->assertSame(2000, $state->attemptAt('cz.cnb.daily', '2026-10'));
        $this->assertNull($state->attemptAt('cz.cnb.daily', '2026-09'));
        $this->assertSame(['lastError' => 1000, 'attempts' => ['cz.cnb.daily|2026-10' => 2000]], $state->toArray());

        $this->assertNull((new DownloadState(new InMemoryKeyValueStore([DownloadState::KEY => 'junk'])))->lastError());
    }

    public function testSaveWritesOnlyAfterChangeAndPrunesMonthsOutsideWindowByMonthOnly(): void
    {
        $settings = new InMemoryKeyValueStore([DownloadState::KEY => ['lastError' => null, 'attempts' => ['cz.cnb.daily|2024-01' => 1, 'cz.cnb.other|2026-09' => 2, 'cz.cnb.other|2027-01' => 3]]]);
        $state = new DownloadState($settings);

        $state->save('2025-10', '2026-10');
        $this->assertSame(0, $settings->writes, 'beze změny se nic nezapisuje, ani prořezání');

        $state->recordAttempt('cz.cnb.daily', '2026-10', 500);
        $state->save('2025-10', '2026-10');
        $this->assertSame(1, $settings->writes);
        $this->assertSame(
            ['lastError' => null, 'attempts' => ['cz.cnb.other|2026-09' => 2, 'cz.cnb.daily|2026-10' => 500]],
            $settings->values[DownloadState::KEY],
            'měsíce mimo okno pryč, cizí typ v okně zůstal',
        );

        $state->save('2025-10', '2026-10');
        $this->assertSame(1, $settings->writes, 'po zápisu je stav čistý');

        $state->recordError(600);
        $state->save('2025-10', '2026-10');
        $this->assertSame(600, $settings->values[DownloadState::KEY]['lastError']);
    }
}
