<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\SenderRuleMatcher;

/**
 * Matchování pravidel odesílatelů — parametry dotazu (jen potvrzená 40,
 * bez filtru dispozice — D6, e-mail > doména) a lowercase normalizace vstupu.
 */
class SenderRuleMatcherTest extends TestCase
{
    /** @var list<mixed> */
    private array $captured = [];

    private function matcher(?\Dibi\Row $row = null): SenderRuleMatcher
    {
        $this->captured = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (...$args) use ($row) {
                $this->captured = $args;
                return $row;
            },
        );

        return new SenderRuleMatcher($db);
    }

    public function testMatchReturnsRuleAsArray(): void
    {
        $matcher = $this->matcher(new \Dibi\Row([
            'id' => 3,
            'pattern_kind' => 'email',
            'pattern' => 'news@example.com',
            'disposition' => 'archive',
        ]));

        $rule = $matcher->match('news@example.com');

        $this->assertNotNull($rule);
        $this->assertSame(3, $rule['id']);
        $this->assertSame('email', $rule['pattern_kind']);
    }

    public function testMatchNormalizesEmailToLowercase(): void
    {
        $matcher = $this->matcher();

        $matcher->match('  News@EXAMPLE.com ');

        $this->assertContains('news@example.com', $this->captured);
        $this->assertContains('example.com', $this->captured);
    }

    public function testMatchFiltersConfirmedStateOnlyAndReturnsDisposition(): void
    {
        $matcher = $this->matcher();

        $matcher->match('news@example.com');

        // D6: dispozici vyhodnocuje volající — SQL filtruje jen stav 40,
        // dispozice se vrací ve sloupcích.
        $sql = (string) $this->captured[0];
        $this->assertStringContainsString('docState = %i', $sql);
        $this->assertStringNotContainsString('disposition = %s', $sql);
        $this->assertStringContainsString('SELECT id, pattern_kind, pattern, disposition', $sql);
        $this->assertContains(40, $this->captured);
        $this->assertNotContains('archive', $this->captured);
    }

    public function testMatchReturnsArchiveIfOtherRuleUnfiltered(): void
    {
        // E-mailové `archiveIfOther` vrácené dotazem (řazení e-mail > doména)
        // projde beze změny — i když na doméně je `archive`, rozhoduje
        // konkrétnější pravidlo a jeho dispozice (D6).
        $matcher = $this->matcher(new \Dibi\Row([
            'id' => 11,
            'pattern_kind' => 'email',
            'pattern' => 'scan@example.com',
            'disposition' => 'archiveIfOther',
        ]));

        $rule = $matcher->match('scan@example.com');

        $this->assertSame('archiveIfOther', $rule['disposition']);
        $this->assertSame(11, $rule['id']);
    }

    public function testMatchOrdersEmailKindBeforeDomain(): void
    {
        $matcher = $this->matcher();

        $matcher->match('news@example.com');

        $sql = (string) $this->captured[0];
        $this->assertStringContainsString("ORDER BY pattern_kind = %s DESC", $sql);
    }

    public function testMatchUsesDomainPartAfterLastAt(): void
    {
        $matcher = $this->matcher();

        $matcher->match('"weird@local"@example.org');

        $this->assertContains('example.org', $this->captured);
    }

    public function testEmptySenderReturnsNullWithoutQuery(): void
    {
        $matcher = $this->matcher();

        $this->assertNull($matcher->match('   '));
        $this->assertSame([], $this->captured);
    }

    public function testSenderWithoutAtReturnsNullWithoutQuery(): void
    {
        $matcher = $this->matcher();

        $this->assertNull($matcher->match('not-an-email'));
        $this->assertSame([], $this->captured);
    }

    public function testNoMatchReturnsNull(): void
    {
        $matcher = $this->matcher();

        $this->assertNull($matcher->match('news@example.com'));
    }
}
