<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Verify;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyPreflight;

class VerifyPreflightTest extends TestCase
{
    public function testProductionIsAProblem(): void
    {
        $this->assertSame([], VerifyPreflight::serverModeProblems('development'));
        $this->assertSame([], VerifyPreflight::serverModeProblems('test'));
        $this->assertCount(1, VerifyPreflight::serverModeProblems('production'));
    }

    public function testCleanSeededDataSourcePasses(): void
    {
        $problems = VerifyPreflight::membershipProblems(
            ['MSG-A', 'MSG-B'],
            [10 => 'MSG-A', 11 => 'MSG-B'],
            [100 => 10, 101 => 11],
        );
        $this->assertSame([], $problems);
        $this->assertSame([], VerifyPreflight::membershipProblems(['MSG-A'], [], []), 'ještě nenaseedovaný zdroj dat není cizí data');
    }

    public function testForeignMessagesAndDocumentsAreListed(): void
    {
        $problems = VerifyPreflight::membershipProblems(
            ['MSG-A'],
            [10 => 'MSG-A', 11 => 'MSG-OTHER'],
            [100 => 10, 101 => null, 102 => 11, 103 => 999],
        );
        $this->assertSame([
            'zpráva #11 (MSG-OTHER) není ze sady',
            'doklad #101 nemá zdrojovou zprávu',
            'doklad #102 vznikl ze zprávy #11 mimo sadu',
            'doklad #103 vznikl ze zprávy #999 mimo sadu',
        ], $problems);
    }

    public function testLongListsAreCapped(): void
    {
        $messages = [];
        for ($i = 1; $i <= 15; $i++) {
            $messages[$i] = "MSG-X{$i}";
        }
        $problems = VerifyPreflight::membershipProblems([], $messages, []);
        $this->assertCount(11, $problems);
        $this->assertSame('… a 5 dalších', $problems[10]);
    }
}
