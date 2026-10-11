<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\ModelSpec;
use Shipard\Core\Ai\RequestShaper;

/**
 * Tvarování požadavku podle schopností modelu (tasks/ai-model-catalog.md
 * D32): každé pravidlo zvlášť, `null` schopnost = beze změny, kombinace
 * `between_tools` + `xhigh`, položka jen s cenami.
 */
final class RequestShaperTest extends TestCase
{
    /** @param array<string, mixed> $capabilities */
    private function spec(array $capabilities = []): ModelSpec
    {
        return ModelSpec::fromArray('claude-test', [
            'name' => 'Test', 'platformIds' => ['anthropic' => 'claude-test'],
            'capabilities' => $capabilities,
        ]);
    }

    private function sonnet55(): ModelSpec
    {
        return $this->spec([
            'temperature' => false, 'thinkingModes' => ['adaptive', 'between_tools'], 'thinkingDefault' => 'adaptive',
            'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinkingOffMaxEffort' => 'high',
            'maxOutputTokens' => 128000, 'chatToolLoop' => false,
        ]);
    }

    private function sonnet46(): ModelSpec
    {
        return $this->spec([
            'temperature' => true, 'thinkingModes' => ['adaptive', 'disabled', 'enabled'], 'thinkingDefault' => 'off',
            'effortLevels' => ['low', 'medium', 'high', 'max'], 'maxOutputTokens' => 128000, 'chatToolLoop' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function params(array $overrides = []): LlmChatParams
    {
        $base = [
            'provider' => 'anthropic', 'model' => 'claude-test', 'apiKey' => 'sk', 'baseUrl' => '',
            'system' => 'sys', 'messages' => [['role' => 'user', 'content' => 'hi']], 'maxTokens' => 4096,
            'temperature' => null, 'tools' => [['name' => 't', 'description' => 'd', 'input_schema' => []]],
            'stallTimeoutSeconds' => 180, 'timeoutSeconds' => 840, 'thinking' => null, 'effort' => null,
        ];
        return new LlmChatParams(...array_merge($base, $overrides));
    }

    public function testNothingToChangeReturnsTheSameInstance(): void
    {
        $params = $this->params(['temperature' => 0.0, 'thinking' => 'disabled', 'effort' => 'high']);
        [$shaped, $changes] = RequestShaper::shape($params, $this->sonnet46());

        $this->assertSame([], $changes);
        $this->assertSame($params, $shaped);
    }

    public function testUnknownCapabilitiesLeaveEverythingAlone(): void
    {
        // Položka jen s cenami: všechny schopnosti null → hodnoty odejdou beze změny.
        $params = $this->params(['temperature' => 0.0, 'thinking' => 'disabled', 'effort' => 'xhigh', 'maxTokens' => 999_999]);
        [$shaped, $changes] = RequestShaper::shape($params, $this->spec());

        $this->assertSame([], $changes);
        $this->assertSame($params, $shaped);
    }

    public function testRule1TemperatureIsOmittedWhenTheModelRejectsIt(): void
    {
        [$shaped, $changes] = RequestShaper::shape($this->params(['temperature' => 0.0]), $this->sonnet55());

        $this->assertNull($shaped->temperature);
        $this->assertCount(1, $changes);
        $this->assertStringContainsString('temperature 0 omitted', (string) $changes[0]);

        // Model s teplotou: beze změny. Bez teploty: nic k vynechání.
        [, $changes] = RequestShaper::shape($this->params(['temperature' => 0.7]), $this->sonnet46());
        $this->assertSame([], $changes);
        [, $changes] = RequestShaper::shape($this->params(), $this->sonnet55());
        $this->assertSame([], $changes);
    }

    public function testRule2ThinkingIsSwappedBetweenDisabledAndBetweenTools(): void
    {
        // disabled na Sonnetu 5.5 → between_tools
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'disabled']), $this->sonnet55());
        $this->assertSame('between_tools', $shaped->thinking);
        $this->assertStringContainsString('thinking disabled → between_tools', (string) $changes[0]);

        // between_tools na Sonnetu 4.6 → disabled
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'between_tools']), $this->sonnet46());
        $this->assertSame('disabled', $shaped->thinking);
        $this->assertStringContainsString('thinking between_tools → disabled', (string) $changes[0]);

        // Přijímaná hodnota beze změny.
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'adaptive']), $this->sonnet55());
        $this->assertSame('adaptive', $shaped->thinking);
        $this->assertSame([], $changes);
    }

    public function testRule2ThinkingWithoutReplacementIsOmitted(): void
    {
        // Opus 5.5 zná jen adaptive: disabled nemá náhradu → vynechat.
        $opus55 = $this->spec(['thinkingModes' => ['adaptive']]);
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'disabled']), $opus55);
        $this->assertNull($shaped->thinking);
        $this->assertStringContainsString('thinking disabled omitted', (string) $changes[0]);

        // Sonnet 4.5 nezná adaptive → vynechat (bez parametru nepřemýšlí).
        $sonnet45 = $this->spec(['thinkingModes' => ['disabled', 'enabled']]);
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'adaptive']), $sonnet45);
        $this->assertNull($shaped->thinking);
        $this->assertStringContainsString('thinking adaptive omitted', (string) $changes[0]);

        // Model bez thinking parametru.
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'adaptive']), $this->spec(['thinkingModes' => []]));
        $this->assertNull($shaped->thinking);
        $this->assertStringContainsString('no thinking parameter', (string) $changes[0]);
    }

    public function testRule3EffortFallsToTheNearestLowerAcceptedLevel(): void
    {
        // xhigh na Sonnetu 4.6 (low, medium, high, max) → high
        [$shaped, $changes] = RequestShaper::shape($this->params(['effort' => 'xhigh']), $this->sonnet46());
        $this->assertSame('high', $shaped->effort);
        $this->assertStringContainsString('effort xhigh → high', (string) $changes[0]);

        // Pod nejnižší přijímanou → nejnižší přijímaná.
        $mediumPlus = $this->spec(['effortLevels' => ['medium', 'high']]);
        [$shaped] = RequestShaper::shape($this->params(['effort' => 'low']), $mediumPlus);
        $this->assertSame('medium', $shaped->effort);

        // Neznámá úroveň → nejnižší přijímaná.
        [$shaped] = RequestShaper::shape($this->params(['effort' => 'ultra']), $this->sonnet46());
        $this->assertSame('low', $shaped->effort);

        // Přijímaná úroveň beze změny.
        [, $changes] = RequestShaper::shape($this->params(['effort' => 'max']), $this->sonnet46());
        $this->assertSame([], $changes);
    }

    public function testRule3EffortIsOmittedWhenTheModelHasNoEffortParameter(): void
    {
        $sonnet45 = $this->spec(['effortLevels' => []]);
        [$shaped, $changes] = RequestShaper::shape($this->params(['effort' => 'high']), $sonnet45);

        $this->assertNull($shaped->effort);
        $this->assertStringContainsString('effort high omitted (model has no effort parameter)', (string) $changes[0]);
    }

    public function testRule3ThinkingOffCapsTheEffort(): void
    {
        // between_tools + xhigh na Sonnetu 5.5 → effort high
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'between_tools', 'effort' => 'xhigh']), $this->sonnet55());
        $this->assertSame('between_tools', $shaped->thinking);
        $this->assertSame('high', $shaped->effort);
        $this->assertCount(1, $changes);
        $this->assertStringContainsString('effort xhigh → high (thinking between_tools allows at most high)', (string) $changes[0]);

        // Strop platí i po náhradě disabled → between_tools (dvě úpravy).
        [$shaped, $changes] = RequestShaper::shape($this->params(['thinking' => 'disabled', 'effort' => 'max']), $this->sonnet55());
        $this->assertSame('between_tools', $shaped->thinking);
        $this->assertSame('high', $shaped->effort);
        $this->assertCount(2, $changes);

        // disabled + xhigh na Opusu 5 / Haiku 5.5 → high
        $opus5 = $this->spec(['thinkingModes' => ['adaptive', 'disabled'], 'effortLevels' => ['low', 'medium', 'high', 'xhigh', 'max'], 'thinkingOffMaxEffort' => 'high']);
        [$shaped] = RequestShaper::shape($this->params(['thinking' => 'disabled', 'effort' => 'xhigh']), $opus5);
        $this->assertSame('high', $shaped->effort);

        // Adaptivní thinking strop nemá; effort pod stropem beze změny; bez effortu nic.
        [, $changes] = RequestShaper::shape($this->params(['thinking' => 'adaptive', 'effort' => 'max']), $this->sonnet55());
        $this->assertSame([], $changes);
        [, $changes] = RequestShaper::shape($this->params(['thinking' => 'between_tools', 'effort' => 'low']), $this->sonnet55());
        $this->assertSame([], $changes);
        [, $changes] = RequestShaper::shape($this->params(['thinking' => 'between_tools']), $this->sonnet55());
        $this->assertSame([], $changes);
    }

    public function testRule4MaxTokensIsCappedAtTheModelLimit(): void
    {
        $sonnet45 = $this->spec(['maxOutputTokens' => 64000]);
        [$shaped, $changes] = RequestShaper::shape($this->params(['maxTokens' => 100_000]), $sonnet45);
        $this->assertSame(64000, $shaped->maxTokens);
        $this->assertStringContainsString('max_tokens 100000 → 64000', (string) $changes[0]);

        [, $changes] = RequestShaper::shape($this->params(['maxTokens' => 64000]), $sonnet45);
        $this->assertSame([], $changes);
    }

    public function testShapedParamsKeepEverythingElse(): void
    {
        $params = $this->params(['temperature' => 0.0, 'thinking' => 'disabled', 'effort' => 'xhigh', 'maxTokens' => 200_000]);
        [$shaped, $changes] = RequestShaper::shape($params, $this->sonnet55());

        $this->assertCount(4, $changes);
        $this->assertSame($params->provider, $shaped->provider);
        $this->assertSame($params->model, $shaped->model);
        $this->assertSame($params->apiKey, $shaped->apiKey);
        $this->assertSame($params->baseUrl, $shaped->baseUrl);
        $this->assertSame($params->system, $shaped->system);
        $this->assertSame($params->messages, $shaped->messages);
        $this->assertSame($params->tools, $shaped->tools);
        $this->assertSame($params->stallTimeoutSeconds, $shaped->stallTimeoutSeconds);
        $this->assertSame($params->timeoutSeconds, $shaped->timeoutSeconds);
        $this->assertNull($shaped->temperature);
        $this->assertSame('between_tools', $shaped->thinking);
        $this->assertSame('high', $shaped->effort);
        $this->assertSame(128000, $shaped->maxTokens);
    }
}
