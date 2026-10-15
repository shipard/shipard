<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiCallOverride;

/** Skládání přepsání (varianta evaluace nad profilem, tasks/testing-phase4.md D35) a kód připojení. */
final class AiCallOverrideTest extends TestCase
{
    public function testMergeTakesTheStrongerValuesAndReplacesTheConnectionAsAWhole(): void
    {
        $profile = new AiCallOverride(connectionId: 3, model: 'claude-sonnet-4-6', thinking: 'adaptive', effort: 'high', maxTokens: 20000);
        $variant = new AiCallOverride(model: 'claude-sonnet-5-5', effort: 'default', connectionCode: 'server');

        $merged = $profile->merge($variant);

        $this->assertNull($merged->connectionId, 'připojení varianty nahrazuje id profilu');
        $this->assertSame('server', $merged->connectionCode);
        $this->assertSame('claude-sonnet-5-5', $merged->model);
        $this->assertSame('adaptive', $merged->thinking);
        $this->assertSame('high', $merged->effort, '`default` ve variantě = podle profilu');
        $this->assertSame(20000, $merged->maxTokens);

        $same = $profile->merge(AiCallOverride::none());
        $this->assertSame(3, $same->connectionId);
        $this->assertNull($same->connectionCode);
        $this->assertSame('claude-sonnet-4-6', $same->model);

        $auto = $profile->merge(new AiCallOverride(thinking: 'auto', maxTokens: 0));
        $this->assertSame('auto', $auto->thinking, '`auto` přepisuje');
        $this->assertSame(20000, $auto->maxTokens, '0 = nepřepisovat');
    }

    public function testConnectionCodeCountsAsAnOverride(): void
    {
        $this->assertTrue(AiCallOverride::none()->isEmpty());
        $this->assertFalse((new AiCallOverride(connectionCode: 'server'))->isEmpty());
        $this->assertNull(AiCallOverride::fromRow(['backend' => 2])->connectionCode);
    }
}
