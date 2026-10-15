<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\DevAiConfig;

/** Sekce `devAi` server.json (tasks/testing-phase4.md D40): výchozí platforma, adresa bez lomítka, validace. */
final class DevAiConfigTest extends TestCase
{
    public function testMissingSectionMeansAnthropicWithTheDefaultUrl(): void
    {
        $config = DevAiConfig::fromArray(null);
        $this->assertSame('anthropic', $config->platform);
        $this->assertNull($config->baseUrl);
    }

    public function testSectionIsParsedAndTrimmed(): void
    {
        $config = DevAiConfig::fromArray(['platform' => ' bedrock-eu ', 'baseUrl' => 'https://bedrock.example/anthropic/ ']);
        $this->assertSame('bedrock-eu', $config->platform);
        $this->assertSame('https://bedrock.example/anthropic', $config->baseUrl);

        $this->assertNull(DevAiConfig::fromArray(['baseUrl' => ''])->baseUrl);
    }

    public function testInvalidValuesAreRejected(): void
    {
        foreach ([
            ['platform' => ''],
            ['platform' => 'Bad Platform'],
            ['platform' => 5],
            ['baseUrl' => 'bedrock.example'],
            ['baseUrl' => 7],
        ] as $section) {
            try {
                DevAiConfig::fromArray($section);
                $this->fail(json_encode($section));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('devAi', $e->getMessage());
            }
        }
    }
}
