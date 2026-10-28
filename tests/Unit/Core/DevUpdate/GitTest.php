<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\Git;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitFixture;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitStub;

/**
 * Wrapper gitu: binárka jen z `PATH` prostředí (bez ní nic nespouští),
 * krátký hash a datum commitu (#103 D135) — nad atrapou v prázdném
 * prostředí a nad skutečným klonem.
 */
final class GitTest extends TestCase
{
    /** @var list<GitStub> */
    private array $stubs = [];
    private ?GitFixture $fx = null;

    protected function tearDown(): void
    {
        foreach ($this->stubs as $stub) {
            $stub->cleanup();
        }
        $this->stubs = [];
        $this->fx?->cleanup();
        $this->fx = null;
    }

    /** @param array<string, string> $answers */
    private function stub(array $answers = GitStub::HEAD): GitStub
    {
        $stub = GitStub::create($answers);
        $this->stubs[] = $stub;
        return $stub;
    }

    public function testBinaryComesFromPathOfTheGivenEnvironmentOnly(): void
    {
        $stub = $this->stub();
        $this->assertSame($stub->dir . '/git', $stub->git('/repo')->binary());
        $this->assertNull((new Git('/repo', $stub->envWithoutGit()))->binary());
        $this->assertSame('/opt/git/bin/git', (new Git('/repo', $stub->env(), '/opt/git/bin/git'))->binary(), 'explicitní binárka má přednost');
    }

    public function testWithoutBinaryNothingRunsAndEveryQueryFails(): void
    {
        $git = new Git('/repo', $this->stub()->envWithoutGit());

        $this->assertSame(['exitCode' => null, 'stdout' => '', 'stderr' => 'git not found in PATH', 'timedOut' => false], $git->run(['rev-parse', 'HEAD']));
        $this->assertNull($git->line(['rev-parse', 'HEAD']));
        $this->assertNull($git->lines(['log', '--format=%cI', 'HEAD']));
        $this->assertFalse($git->isRepository());
        $this->assertNull($git->headHash());
        $this->assertNull($git->shortHash());
        $this->assertNull($git->commitDate());
        $this->assertNull($git->branch());
        $this->assertNull($git->isDirty());
    }

    public function testShortHashAndCommitDateFromStubInEmptyEnvironment(): void
    {
        $git = $this->stub()->git('/repo');

        $this->assertSame(GitStub::FULL_HASH, $git->headHash());
        $this->assertSame(GitStub::SHORT_HASH, $git->shortHash());
        $this->assertSame(GitStub::COMMIT_DATE, $git->commitDate());
        $this->assertSame(GitStub::BRANCH, $git->branch());
        $this->assertFalse($git->isDirty());
    }

    public function testShortHashRejectsNonHexAndCommitDateRejectsNonIso(): void
    {
        $git = $this->stub([GitStub::Q_SHORT => 'fatal: bad revision', GitStub::Q_DATE => 'Fri Oct 17 10:17:42 2026'] + GitStub::HEAD)->git('/repo');
        $this->assertNull($git->shortHash());
        $this->assertNull($git->commitDate());

        $empty = $this->stub([GitStub::Q_SHORT => '', GitStub::Q_DATE => ''] + GitStub::HEAD)->git('/repo');
        $this->assertNull($empty->shortHash());
        $this->assertNull($empty->commitDate());
    }

    /** #146 N8: git ≥ 2.45 vypisuje `%cI` s nulovým posunutím jako `Z` — na výstupu vždy `+00:00`. */
    public function testCommitDateIsNormalizedToAtomRegardlessOfGitVersion(): void
    {
        $zulu = $this->stub([GitStub::Q_DATE => '2026-09-20T08:00:00Z'] + GitStub::HEAD)->git('/repo');
        $this->assertSame('2026-09-20T08:00:00+00:00', $zulu->commitDate());

        $offset = $this->stub([GitStub::Q_DATE => '2026-09-20T10:00:00+02:00'] + GitStub::HEAD)->git('/repo');
        $this->assertSame('2026-09-20T10:00:00+02:00', $offset->commitDate(), 'nenulové posunutí zůstává');

        $this->assertSame('2026-09-20T08:00:00+00:00', Git::isoDate('2026-09-20T08:00:00Z'));
        $this->assertSame('2026-09-20T08:00:00+00:00', Git::isoDate('2026-09-20T08:00:00+00:00'));
        $this->assertNull(Git::isoDate(''));
        $this->assertNull(Git::isoDate('Fri Oct 17 10:17:42 2026'));
        $this->assertNull(Git::isoDate('2026-09-20 08:00:00'));
    }

    public function testShortHashAndCommitDateOfARealClone(): void
    {
        if (!GitFixture::available()) {
            $this->markTestSkipped('git is not available');
        }
        $this->fx = GitFixture::create();
        $git = new Git($this->fx->checkout, $this->fx->env());

        $full = $git->headHash();
        $short = $git->shortHash();
        $this->assertNotNull($full);
        $this->assertNotNull($short);
        $this->assertStringStartsWith($short, $full);
        $this->assertSame('2026-01-01T10:00:00+00:00', $git->commitDate(), 'committer date prvního commitu fixture');
        $this->assertSame('stable', $git->branch());
        $this->assertFalse($git->isDirty());
    }
}
