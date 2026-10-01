<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Utils;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\Slug;

class SlugTest extends TestCase
{
    public function testTransliteratesAndCollapsesSeparators(): void
    {
        $this->assertSame('hlavni-kniha', Slug::make('Hlavní kniha'));
        $this->assertSame('zluty-kun-s-r-o', Slug::make('  Žlutý kůň, s. r. o. '));
        $this->assertSame('prehled-dph-2026-05', Slug::make('Přehled DPH 2026/05'));
    }

    public function testFallbackAndMaxLength(): void
    {
        $this->assertSame('record', Slug::make('***'));
        $this->assertSame('report', Slug::make('', fallback: 'report'));

        $slug = Slug::make(str_repeat('abc ', 40), 10);
        $this->assertLessThanOrEqual(10, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }
}
