<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\Utils\GitState;
use Shipard\Tests\Fixtures\Core\DevUpdate\GitStub;

/**
 * Stav checkoutu pro soubory běhů evaluace (D36) nad atrapou gitu
 * v prázdném prostředí (#103 D135): bez shellu, bez PATH procesu.
 */
final class GitStateTest extends TestCase
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

    public function testCleanCheckout(): void
    {
        $this->assertSame(['commit' => GitStub::SHORT_HASH, 'dirty' => false], GitState::describe('/repo', $this->stub()->git('/repo')));
    }

    public function testDirtyCheckout(): void
    {
        $stub = $this->stub([GitStub::Q_STATUS => ' M src/Core/Version.php'] + GitStub::HEAD);
        $this->assertSame(['commit' => GitStub::SHORT_HASH, 'dirty' => true], GitState::describe('/repo', $stub->git('/repo')));
    }

    public function testStatusFailureLeavesDirtyUnknown(): void
    {
        $stub = $this->stub(array_diff_key(GitStub::HEAD, [GitStub::Q_STATUS => '']));
        $this->assertSame(['commit' => GitStub::SHORT_HASH, 'dirty' => null], GitState::describe('/repo', $stub->git('/repo')));
    }

    public function testBrokenShortHashMeansNoCommit(): void
    {
        $stub = $this->stub([GitStub::Q_SHORT => 'fatal: not a git repository'] + GitStub::HEAD);
        $this->assertSame(['commit' => null, 'dirty' => null], GitState::describe('/repo', $stub->git('/repo')));
    }

    public function testWithoutGitInPathBothAreNull(): void
    {
        $git = new Git('/repo', $this->stub()->envWithoutGit());
        $this->assertSame(['commit' => null, 'dirty' => null], GitState::describe('/repo', $git));
    }

    public function testDescribeOfThisCheckoutNeverThrows(): void
    {
        $state = GitState::describe();
        $this->assertSame(['commit', 'dirty'], array_keys($state));
        if ($state['commit'] === null) {
            $this->assertNull($state['dirty']);
        } else {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7,40}$/', $state['commit']);
            $this->assertIsBool($state['dirty']);
        }
        $this->assertSame($state, GitState::describe(dirname(__DIR__, 4)), 'výchozí kořen = kořen checkoutu');
    }
}
