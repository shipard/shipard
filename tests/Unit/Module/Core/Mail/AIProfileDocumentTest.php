<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\AIProfileDocument;

class AIProfileDocumentTest extends TestCase
{
    private function doc(): AIProfileDocument
    {
        return new AIProfileDocument();
    }

    private function validData(): array
    {
        return [
            'profile_id' => 'czech_general',
            'name' => 'Obecná analýza pošty (česky)',
            'backend' => 1,
            'prompt_template' => 'Jsi asistent…',
            'output_schema' => '{"type":"object"}',
            'supported_doc_types' => '["invoiceReceived"]',
            'confidence_thresholds' => '{"ready":0.9,"review":0.6}',
        ];
    }

    public function testValidatePassesOnValidData(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingProfileIdFails(): void
    {
        $data = $this->validData();
        unset($data['profile_id']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertContains('profile_id', array_column($result->toArray(), 'column'));
    }

    public function testBackendIsOptionalAndEmptyMeansByFunction(): void
    {
        // Připojení je přepsání AI funkce mail.extraction (tasks/ai-functions.md D4):
        // chybějící i prázdné = podle funkce, beforeSave normalizuje na NULL.
        $data = $this->validData();
        unset($data['backend']);
        $this->assertTrue($this->doc()->validate($data)->isValid());

        $data = ['backend' => '', 'model' => ' ', 'model_like' => '', 'thinking' => '', 'effort' => '', 'max_tokens' => ''] + $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->doc()->beforeSave($data);
        $this->assertNull($data['backend']);
        $this->assertNull($data['model']);
        $this->assertNull($data['model_like']);
        $this->assertSame('default', $data['thinking']);
        $this->assertSame('default', $data['effort']);
        $this->assertSame(0, $data['max_tokens']);

        $data = ['backend' => '7', 'model' => 'claude-haiku-5-5', 'max_tokens' => '16000'] + $this->validData();
        $this->doc()->beforeSave($data);
        $this->assertSame(7, $data['backend']);
        $this->assertSame('claude-haiku-5-5', $data['model']);
        $this->assertSame(16000, $data['max_tokens']);
    }

    public function testCustomModelRequiresModelLikeWhenCatalogIsKnown(): void
    {
        $cfg = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => match ($id) {
            \Shipard\Core\Ai\ModelCatalog::CFG_ITEM => [
                'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
            ],
            'core.ai.thinkingModes' => ['default' => ['name' => 'D'], 'auto' => ['name' => 'A']],
            default => null,
        });
        $doc = $this->doc();
        $doc->setConfig($cfg);

        $data = ['model' => 'claude-sonnet-6'] + $this->validData();
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('model_like_required', $result->toArray()[0]['code']);

        $data = ['model' => 'claude-sonnet-6', 'model_like' => 'claude-sonnet-4-6'] + $this->validData();
        $this->assertTrue($doc->validate($data)->isValid());

        $data = ['thinking' => 'enabled'] + $this->validData();
        $result = $doc->validate($data);
        $this->assertContains('thinking', array_column($result->toArray(), 'column'));
    }

    public function testValidateInvalidOutputSchemaJsonFails(): void
    {
        $data = $this->validData();
        $data['output_schema'] = '{not json';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'output_schema',
        );
        $this->assertCount(1, $errors);
        $this->assertSame('invalid_json', array_values($errors)[0]['code']);
    }

    public function testValidateOutputSchemaMustBeObjectNotArray(): void
    {
        $data = $this->validData();
        $data['output_schema'] = '[1,2,3]';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'output_schema',
        );
        $this->assertSame('invalid_json_shape', array_values($errors)[0]['code']);
    }

    public function testValidateSupportedDocTypesMustBeArray(): void
    {
        $data = $this->validData();
        $data['supported_doc_types'] = '{"foo":"bar"}';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'supported_doc_types',
        );
        $this->assertSame('invalid_json_shape', array_values($errors)[0]['code']);
    }

    public function testValidateIsDefaultWithExistingDefaultFails(): void
    {
        $row = new \Dibi\Row(['profile_id' => 'english_invoices', 'name' => 'English']);
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn($row);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = $this->validData();
        $data['is_default'] = true;

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'is_default',
        );
        $this->assertSame('duplicate_default', array_values($errors)[0]['code']);
    }

    public function testBeforeSaveCoercesFlagsAndSetsAudit(): void
    {
        $doc = $this->doc();
        $data = $this->validData() + ['is_default' => true, 'is_active' => false];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['is_default']);
        $this->assertSame(0, $data['is_active']);
        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
    }
}
