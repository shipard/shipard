<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Compare;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\TextSimilarity;

/** Podobnost textu nad znaky (čeština), sjednocení mezer (#103 D37). */
final class TextSimilarityTest extends TestCase
{
    public function testRatioOverCharactersNotBytes(): void
    {
        $this->assertSame(1.0, TextSimilarity::ratio('Dřevomat Haná', 'Dřevomat Haná'));
        $this->assertSame(1.0, TextSimilarity::ratio('', ''));
        $this->assertSame(0.0, TextSimilarity::ratio('', 'abc'));
        // Jeden znak z deseti: 0,9 — háčky jsou znaky, ne byty.
        $this->assertSame(0.9, TextSimilarity::ratio('Příbramská', 'Příbramski'));
        $this->assertSame(0.8, TextSimilarity::ratio('Příbramská', 'Pribramská'));
        $this->assertSame(0.5, TextSimilarity::ratio('abcd', 'abxy'));
        $this->assertLessThan(0.5, TextSimilarity::ratio('Spárovka smrková', 'Deska dubová'));
    }

    public function testCollapseWhitespace(): void
    {
        $this->assertSame('Dřevomat Haná s.r.o.', TextSimilarity::collapseWhitespace("  Dřevomat \t Haná\n s.r.o. "));
        $this->assertSame('', TextSimilarity::collapseWhitespace("  \n"));
    }
}
