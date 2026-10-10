<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Ai\AiFunctionSettingsDocument;

/**
 * AiFunctionSettingsDocument (tasks/ai-functions.md D38, D43): funkce
 * z registru a neměnná, hodnoty ladění z cfgItemu, strop tokenů, vlastní
 * ID modelu s `model_like`, normalizace prázdných hodnot, zákaz mazání.
 */
final class AiFunctionSettingsDocumentTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768, 'temperature' => 0], 'requires' => ['pdf']],
        'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
    ];

    private const MODELS = [
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
        'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 0.1, 'output' => 0.5]],
    ];

    private function config(): ConfigRuntime
    {
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => match ($id) {
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => ['balanced' => ['model' => 'claude-sonnet-4-6']],
            'core.ai.thinkingModes' => ['default' => ['name' => 'D'], 'auto' => ['name' => 'A'], 'adaptive' => ['name' => 'Ad']],
            'core.ai.effortLevels' => ['default' => ['name' => 'D'], 'auto' => ['name' => 'A'], 'high' => ['name' => 'H']],
            default => null,
        });
        return $cfg;
    }

    private function doc(bool $withConfig = true): AiFunctionSettingsDocument
    {
        $doc = new AiFunctionSettingsDocument();
        if ($withConfig) {
            $doc->setConfig($this->config());
        }
        return $doc;
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return ['function_id' => 'mail.extraction', 'connection' => null, 'model' => null, 'model_like' => null,
            'thinking' => 'default', 'effort' => 'default', 'max_tokens' => null];
    }

    /** @return list<string> */
    private function errorColumns(\Shipard\Core\Document\ValidationResult $result): array
    {
        return array_column($result->toArray(), 'column');
    }

    public function testValidDataPasses(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());

        $data = ['function_id' => 'chat.default', 'model' => 'claude-haiku-5-5', 'thinking' => 'adaptive', 'effort' => 'high', 'max_tokens' => '8000'];
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testFunctionMustBeDeclared(): void
    {
        $data = ['function_id' => ''] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertContains('function_id', $this->errorColumns($result));

        $data = ['function_id' => 'mail.titles'] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('unknown_function', $result->toArray()[0]['code']);

        // Bez compiled configu (prázdný registr) se existence neověřuje.
        $this->assertTrue($this->doc(false)->validate($data)->isValid());
    }

    public function testTuningValuesComeFromTheCfgItem(): void
    {
        $data = ['thinking' => 'enabled'] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertContains('thinking', $this->errorColumns($result));

        $data = ['effort' => 'xhigh'] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertContains('effort', $this->errorColumns($result));

        $data = ['thinking' => '', 'effort' => 'auto'] + $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid(), 'prázdné doplní beforeSave na default');
    }

    public function testMaxTokensMustBeEmptyOrPositiveInteger(): void
    {
        foreach ([null, '', 0, '0', 4096, '4096'] as $ok) {
            $data = ['max_tokens' => $ok] + $this->validData();
            $this->assertTrue($this->doc()->validate($data)->isValid(), var_export($ok, true));
        }
        foreach (['abc', -1, '12.5'] as $bad) {
            $data = ['max_tokens' => $bad] + $this->validData();
            $result = $this->doc()->validate($data);
            $this->assertContains('max_tokens', $this->errorColumns($result), var_export($bad, true));
        }
    }

    public function testCustomModelRequiresModelLikeFromTheCatalog(): void
    {
        // Model z katalogu: model_like netřeba.
        $data = ['model' => 'claude-haiku-5-5'] + $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());

        // Vlastní ID bez model_like.
        $data = ['model' => 'claude-sonnet-6'] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('model_like', $result->toArray()[0]['column']);
        $this->assertSame('model_like_required', $result->toArray()[0]['code']);

        // Vlastní ID s model_like z katalogu.
        $data = ['model' => 'claude-sonnet-6', 'model_like' => 'claude-sonnet-4-6'] + $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());

        // model_like mimo katalog.
        $data = ['model' => 'claude-sonnet-6', 'model_like' => 'gpt-5'] + $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertSame('unknown_model', $result->toArray()[0]['code']);

        // Bez katalogu (compiled config chybí) se model neověřuje.
        $data = ['model' => 'claude-sonnet-6'] + $this->validData();
        $this->assertTrue($this->doc(false)->validate($data)->isValid());
    }

    public function testBeforeSaveNormalizesEmptyValuesAndAudit(): void
    {
        $data = ['function_id' => ' mail.extraction ', 'connection' => '', 'model' => ' ', 'model_like' => '',
            'thinking' => '', 'effort' => '', 'max_tokens' => ''];
        $this->doc()->beforeSave($data);

        $this->assertSame('mail.extraction', $data['function_id']);
        $this->assertNull($data['connection']);
        $this->assertNull($data['model']);
        $this->assertNull($data['model_like']);
        $this->assertSame('default', $data['thinking']);
        $this->assertSame('default', $data['effort']);
        $this->assertNull($data['max_tokens']);
        $this->assertNotEmpty($data['created']);
        $this->assertNotEmpty($data['modified']);

        $data = ['id' => 5, 'connection' => '3', 'model' => 'claude-haiku-5-5', 'max_tokens' => '0'];
        $this->doc()->beforeSave($data, ['id' => 5, 'function_id' => 'mail.extraction']);
        $this->assertSame(3, $data['connection']);
        $this->assertNull($data['max_tokens'], '0 = podle funkce → NULL');
        $this->assertArrayNotHasKey('created', $data);
    }

    public function testFunctionIdCannotChangeOnExistingRow(): void
    {
        // validate: uložená hodnota z DB rozhoduje → chyba pole (422), ne výjimka.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row(['function_id' => 'mail.extraction']));
        $doc = $this->doc();
        $doc->setDb($db);
        $data = ['id' => 5, 'function_id' => 'chat.default'] + $this->validData();
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('immutable', $result->toArray()[0]['code']);

        $data = ['id' => 5, 'function_id' => 'mail.extraction'] + $this->validData();
        $this->assertTrue($doc->validate($data)->isValid());

        // beforeSave jako druhá pojistka pro volající mimo gateway.
        $data = ['id' => 5, 'function_id' => 'chat.default'];
        $this->expectException(\DomainException::class);
        $this->doc()->beforeSave($data, ['id' => 5, 'function_id' => 'mail.extraction']);
    }

    public function testRowsCannotBeDeleted(): void
    {
        $this->expectException(\DomainException::class);
        $this->doc()->beforeDelete(['id' => 5, 'function_id' => 'mail.extraction']);
    }
}
