<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalVariant;

/** Varianta evaluace (D35): baseline z repa, šablona ze souboru, přepsání, validace. */
final class EvalVariantTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_evalvariant_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    public function testBaselineFromTheRepositoryLoadsWithoutOverrides(): void
    {
        $variant = EvalVariant::load(dirname(__DIR__, 6) . '/tests/eval/variants/baseline.jsonc');

        $this->assertSame('baseline', $variant->name);
        $this->assertSame('czech_general', $variant->profile);
        $this->assertNull($variant->promptTemplate);
        $this->assertNull($variant->promptHash());
        $this->assertTrue($variant->callOverride()->isEmpty());
        $this->assertNull($variant->contentTagsOverride());
        $this->assertSame(['connection' => null, 'model' => null, 'thinking' => null, 'effort' => null, 'maxTokens' => null], $variant->toArray()['call']);
    }

    public function testOverridesAndPromptFile(): void
    {
        file_put_contents($this->tmp . '/prompt.txt', "Subject: {{ message.subject }}\n");
        $variant = EvalVariant::fromArray([
            'format' => EvalVariant::FORMAT,
            'name' => 'sonnet-5.5',
            'profile' => 'czech_general',
            'promptTemplateFile' => 'prompt.txt',
            'call' => ['connection' => 'server', 'model' => ' claude-sonnet-5-5 ', 'effort' => 'high', 'maxTokens' => 20000],
            'contentTags' => ['model' => 'claude-haiku-5-5'],
        ], $this->tmp . '/v.jsonc');

        $this->assertSame("Subject: {{ message.subject }}\n", $variant->promptTemplate);
        $this->assertSame(hash('sha256', "Subject: {{ message.subject }}\n"), $variant->promptHash());
        $call = $variant->callOverride();
        $this->assertSame('server', $call->connectionCode);
        $this->assertSame('claude-sonnet-5-5', $call->model);
        $this->assertSame('high', $call->effort);
        $this->assertNull($call->thinking);
        $this->assertSame(20000, $call->maxTokens);
        $tags = $variant->contentTagsOverride();
        $this->assertNotNull($tags);
        $this->assertSame('claude-haiku-5-5', $tags->model);
        $this->assertSame('prompt.txt', $variant->toArray()['promptTemplateFile']);
    }

    public function testInvalidVariantsAreRejected(): void
    {
        $valid = ['format' => EvalVariant::FORMAT, 'name' => 'x', 'profile' => 'czech_general'];
        foreach ([
            ['format' => 'other'],
            ['name' => 'Bad Name'],
            ['profile' => ''],
            ['promptTemplateFile' => 'missing.txt'],
            ['call' => ['foo' => 1]],
            ['call' => ['maxTokens' => 0]],
            ['call' => ['model' => 5]],
            ['call' => ['a', 'b']],
            ['contentTags' => ['maxTokens' => 10]],
            ['extra' => 1],
        ] as $change) {
            try {
                EvalVariant::fromArray($change + $valid, $this->tmp . '/v.jsonc');
                $this->fail(json_encode($change));
            } catch (EvalException $e) {
                $this->assertStringContainsString('varianta v.jsonc', $e->getMessage());
            }
        }

        $this->expectException(EvalException::class);
        EvalVariant::load($this->tmp . '/nope.jsonc');
    }
}
