<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\Version;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitStub;

class VersionTest extends TestCase
{
    /** @var list<GitStub> */
    private array $stubs = [];

    protected function tearDown(): void
    {
        foreach ($this->stubs as $stub) {
            $stub->cleanup();
        }
        $this->stubs = [];
    }

    /** @param array<string, string> $answers */
    private function stub(array $answers = GitStub::HEAD): GitStub
    {
        $stub = GitStub::create($answers);
        $this->stubs[] = $stub;
        return $stub;
    }

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

    /** #103 D135: git z pevného PATH a prázdné prostředí (jako PHP-FPM s `clear_env`) — žádný shell, žádný PATH procesu. */
    public function testReadGitInfoWithEmptyEnvironmentReadsHashesBranchAndCommitDate(): void
    {
        $stub = $this->stub();
        $git = $stub->git('/repo');

        $this->assertSame($stub->dir . '/git', $git->binary(), 'binárka nalezená findTool v PATH prostředí');
        $this->assertSame([
            'hash'       => GitStub::SHORT_HASH,
            'fullHash'   => GitStub::FULL_HASH,
            'branch'     => GitStub::BRANCH,
            'commitDate' => GitStub::COMMIT_DATE,
        ], Version::readGitInfo('/repo', $git));
    }

    public function testReadGitInfoWithoutGitInPathIsNull(): void
    {
        $stub = $this->stub();
        $git = new Git('/repo', $stub->envWithoutGit());

        $this->assertNull($git->binary());
        $this->assertNull(Version::readGitInfo('/repo', $git));
    }

    public function testReadGitInfoDetachedHeadHasNoBranch(): void
    {
        $detached = $this->stub([GitStub::Q_BRANCH => 'HEAD'] + GitStub::HEAD);
        $this->assertNull(Version::readGitInfo('/repo', $detached->git('/repo'))['branch']);

        $failing = $this->stub(array_diff_key(GitStub::HEAD, [GitStub::Q_BRANCH => '']));
        $info = Version::readGitInfo('/repo', $failing->git('/repo'));
        $this->assertNotNull($info, 'rev-parse větve selhal → větev neznámá, zbytek platí');
        $this->assertNull($info['branch']);
        $this->assertSame(GitStub::FULL_HASH, $info['fullHash']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function brokenGitAnswers(): iterable
    {
        yield 'rev-parse HEAD selže (mimo repozitář)' => [array_diff_key(GitStub::HEAD, [GitStub::Q_HEAD => ''])];
        yield 'krátký hash selže' => [array_diff_key(GitStub::HEAD, [GitStub::Q_SHORT => ''])];
        yield 'datum commitu selže' => [array_diff_key(GitStub::HEAD, [GitStub::Q_DATE => ''])];
        yield 'krátký hash není prefix plného' => [[GitStub::Q_SHORT => 'abcdef0'] + GitStub::HEAD];
        yield 'plný hash moc krátký' => [[GitStub::Q_HEAD => GitStub::SHORT_HASH] + GitStub::HEAD];
        yield 'ne-hex znaky' => [[GitStub::Q_HEAD => 'e4ab901z848e88b0954ed13330abb928c971a2f3', GitStub::Q_SHORT => 'e4ab901z'] + GitStub::HEAD];
        yield 'datum mimo ISO 8601' => [[GitStub::Q_DATE => 'Fri Oct 17 10:17:42 2026'] + GitStub::HEAD];
        yield 'hláška gitu místo hashe' => [[GitStub::Q_HEAD => 'fatal: not a git repository'] + GitStub::HEAD];
    }

    /** @param array<string, string> $answers */
    #[DataProvider('brokenGitAnswers')]
    public function testReadGitInfoRejectsBrokenOutput(array $answers): void
    {
        $this->assertNull(Version::readGitInfo('/repo', $this->stub($answers)->git('/repo')));
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
