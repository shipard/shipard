<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Version;

class VersionTest extends TestCase
{
    private const LOG_LINE = "e4ab901a\te4ab901a848e88b0954ed13330abb928c971a2f3\t2026-10-17T10:17:42+00:00\n";

    public function testFullStartsWithVersion(): void
    {
        $this->assertStringStartsWith(Version::VERSION, Version::full());
    }

    public function testGitHashIsNullOrShortHex(): void
    {
        $hash = Version::gitHash();
        if ($hash !== null) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $hash);
        } else {
            $this->assertNull($hash);
        }
    }

    public function testFullFormatMatchesGitHashAvailability(): void
    {
        $hash = Version::gitHash();
        if ($hash === null) {
            $this->assertSame(Version::VERSION, Version::full());
        } else {
            $this->assertSame(Version::VERSION . ' (' . $hash . ')', Version::full());
        }
    }

    public function testGitHashIsStableAcrossCalls(): void
    {
        $this->assertSame(Version::gitHash(), Version::gitHash());
    }

    public function testGitInfoIsCachedAndGitHashIsItsShortHash(): void
    {
        $info = Version::gitInfo();
        $this->assertSame($info, Version::gitInfo());
        $this->assertSame($info['hash'] ?? null, Version::gitHash());
        if ($info !== null) {
            $this->assertSame(['hash', 'fullHash', 'branch', 'commitDate'], array_keys($info));
            $this->assertStringStartsWith($info['hash'], $info['fullHash']);
        }
    }

    public function testParseGitInfoReadsHashesBranchAndCommitDate(): void
    {
        $this->assertSame([
            'hash'       => 'e4ab901a',
            'fullHash'   => 'e4ab901a848e88b0954ed13330abb928c971a2f3',
            'branch'     => 'stable',
            'commitDate' => '2026-10-17T10:17:42+00:00',
        ], Version::parseGitInfo(self::LOG_LINE, "stable\n"));
    }

    public function testParseGitInfoDetachedHeadHasNoBranch(): void
    {
        $this->assertNull(Version::parseGitInfo(self::LOG_LINE, "HEAD\n")['branch']);
        $this->assertNull(Version::parseGitInfo(self::LOG_LINE, '')['branch'], 'rev-parse selhal → větev neznámá, zbytek platí');
    }

    /** @return iterable<string, array{string}> */
    public static function brokenLogLines(): iterable
    {
        yield 'prázdný výstup' => [''];
        yield 'jen hash' => ['e4ab901a'];
        yield 'krátký hash není prefix plného' => ["abcdef0\te4ab901a848e88b0954ed13330abb928c971a2f3\t2026-10-17T10:17:42+00:00"];
        yield 'plný hash moc krátký' => ["e4ab901a\te4ab901a\t2026-10-17T10:17:42+00:00"];
        yield 'ne-hex znaky' => ["e4ab901z\te4ab901z848e88b0954ed13330abb928c971a2f3\t2026-10-17T10:17:42+00:00"];
        yield 'datum mimo ISO 8601' => ["e4ab901a\te4ab901a848e88b0954ed13330abb928c971a2f3\tFri Oct 17 10:17:42 2026"];
        yield 'hláška gitu' => ["fatal: not a git repository"];
    }

    #[DataProvider('brokenLogLines')]
    public function testParseGitInfoRejectsBrokenOutput(string $logLine): void
    {
        $this->assertNull(Version::parseGitInfo($logLine, 'stable'));
    }

    /** Bez repozitáře (adresář mimo git) je HEAD neznámá — pruh dashboardu ukáže jen číslo verze. */
    public function testReadGitInfoOutsideRepositoryIsNull(): void
    {
        $dir = sys_get_temp_dir() . '/shpd-version-test-' . uniqid();
        mkdir($dir, 0700);
        try {
            $this->assertNull(Version::readGitInfo($dir));
        } finally {
            rmdir($dir);
        }
    }

    public function testReadGitInfoOfThisCheckoutMatchesGitInfo(): void
    {
        $this->assertSame(Version::gitInfo(), Version::readGitInfo(dirname(__DIR__, 3)));
    }
}
