<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\DevUpdate;

use PHPUnit\Framework\TestCase;
use Shipard\Core\DevUpdate\Upstream;

/** Cílová reference podle `origin` (#129 D17) a odkazy na GitHub. */
final class UpstreamTest extends TestCase
{
    public function testCanonicalOriginInAllThreeForms(): void
    {
        foreach ([
            'https://github.com/shipard/shipard.git',
            'https://github.com/shipard/shipard',
            'git@github.com:shipard/shipard.git',
            'git@github.com:shipard/shipard',
            'ssh://git@github.com/shipard/shipard.git',
            'ssh://git@github.com/shipard/shipard',
            "https://github.com/shipard/shipard.git\n",
        ] as $url) {
            $this->assertTrue(Upstream::isCanonicalOrigin($url), $url);
            $this->assertSame('refs/remotes/origin/stable', Upstream::targetRef($url), $url);
        }
    }

    public function testForkOrMissingOriginUsesPrivateRef(): void
    {
        foreach ([
            'https://github.com/someone/shipard.git',
            'git@github.com:shipard/shipard-fork.git',
            'https://github.com/shipard/shipard-docs.git',
            '/tmp/upstream.git',
            '',
            null,
        ] as $url) {
            $this->assertFalse(Upstream::isCanonicalOrigin($url), var_export($url, true));
            $this->assertSame('refs/shipard/upstream/stable', Upstream::targetRef($url), var_export($url, true));
        }
    }

    public function testRefspecAndUrls(): void
    {
        $this->assertSame('+refs/heads/stable:refs/remotes/origin/stable', Upstream::refspec(Upstream::REF_ORIGIN));
        $this->assertSame('https://github.com/shipard/shipard/compare/abc123...stable', Upstream::compareUrl('abc123'));
        $this->assertSame('https://github.com/shipard/shipard.git', Upstream::URL);
        $this->assertSame('stable', Upstream::BRANCH);
    }
}
