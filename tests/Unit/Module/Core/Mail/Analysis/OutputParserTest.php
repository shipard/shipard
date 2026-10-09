<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\Analysis\OutputParser;
use Shipard\Module\Core\Mail\Analysis\SchemaValidationException;
use Shipard\Module\Core\Mail\AnalysisErrorPresenter;

/**
 * Výstup modelu → objekt (D18): čistý JSON, markdown blok, nevalidní JSON,
 * porušení schématu v textovém tvaru jsonschema (včetně výběru nejhlubší
 * chyby v `oneOf` dokumentu reálného profilu) a pole místo objektu.
 * Presenter z hlášek musí vyčíst stejný druh chyby jako z démona.
 */
final class OutputParserTest extends TestCase
{
    private const SIMPLE_SCHEMA = [
        'type' => 'object',
        'required' => ['message_classification'],
        'properties' => [
            'message_classification' => ['type' => 'object'],
            'document' => ['type' => ['object', 'null']],
            'overall_confidence' => ['type' => 'number'],
        ],
    ];

    /** @return array<string, mixed> */
    private function profileSchema(): array
    {
        $profile = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/core/mail/profiles/czech_general.jsonc');
        return $profile['output_schema'];
    }

    /** @return array<string, mixed> */
    private function happyCanonical(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 6) . '/tests/Fixtures/Exchange/invoiceReceived_happy.json'), true);
    }

    /** @return array<string, mixed> */
    private function docsOutput(): array
    {
        return [
            'overall_confidence' => 0.9,
            'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.95, 'title' => 'Faktura'],
            'document' => ['doc_type' => 'invoiceReceived', 'confidence' => 0.9, 'extracted_json' => $this->happyCanonical()],
        ];
    }

    private function expectSchemaError(string $expectedMessage, callable $call): void
    {
        try {
            $call();
            $this->fail('expected SchemaValidationException');
        } catch (SchemaValidationException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }
    }

    private function presenterKind(string $message): string
    {
        $db = $this->createMock(DataSourceConnection::class);
        return new AnalysisErrorPresenter($db, null)->fromErrorMessage('[schema_error] ' . $message)->kind;
    }

    public function testParsesCleanJson(): void
    {
        $out = new OutputParser()->parse(
            '{"message_classification": {"primary_type": "other"}, "document": null, "overall_confidence": 0.0}',
            self::SIMPLE_SCHEMA,
        );

        $this->assertSame(['message_classification' => ['primary_type' => 'other'], 'document' => null, 'overall_confidence' => 0.0], $out);
    }

    public function testStripsMarkdownFence(): void
    {
        $raw = "Sure, here you go:\n\n```json\n{\n  \"message_classification\": {\"primary_type\": \"invoiceReceived\"},\n  \"document\": {\"doc_type\": \"invoiceReceived\"},\n  \"overall_confidence\": 0.9\n}\n```\n";
        $out = new OutputParser()->parse($raw, self::SIMPLE_SCHEMA);

        $this->assertSame('invoiceReceived', $out['document']['doc_type']);
        $this->assertSame(0.9, $out['overall_confidence']);
    }

    public function testStripsUnmarkedFence(): void
    {
        $out = new OutputParser()->parse("```\n{\"message_classification\": {}, \"document\": null}\n```", self::SIMPLE_SCHEMA);

        $this->assertNull($out['document']);
        $this->assertSame([], $out['message_classification']);
    }

    public function testInvalidJsonRaises(): void
    {
        $this->expectSchemaError('output is not valid JSON', fn() => new OutputParser()->parse('not json at all', self::SIMPLE_SCHEMA));
        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_INVALID_JSON, $this->presenterKind('output is not valid JSON'));
    }

    public function testInvalidFencedJsonRaises(): void
    {
        try {
            new OutputParser()->parse("```json\n{\"a\": \n```", self::SIMPLE_SCHEMA);
            $this->fail('expected exception');
        } catch (SchemaValidationException $e) {
            $this->assertStringStartsWith('fenced JSON is invalid: ', $e->getMessage());
            $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_INVALID_JSON, $this->presenterKind($e->getMessage()));
        }
    }

    public function testMissingRequiredProperty(): void
    {
        $this->expectSchemaError(
            "output does not match schema: 'message_classification' is a required property at []",
            fn() => new OutputParser()->parse('{"overall_confidence": 0.5}', self::SIMPLE_SCHEMA),
        );
    }

    public function testTopLevelArrayRejected(): void
    {
        $this->expectSchemaError('output JSON must be an object at the top level', fn() => new OutputParser()->parse('[]', ['type' => 'array']));
        $this->expectSchemaError(
            "output does not match schema: [] is not of type 'object' at []",
            fn() => new OutputParser()->parse('[]', self::SIMPLE_SCHEMA),
        );
    }

    public function testValidDocsOutputAgainstShippedProfile(): void
    {
        $out = new OutputParser()->parse((string) json_encode($this->docsOutput()), $this->profileSchema());

        $this->assertSame('2026000123', $out['document']['extracted_json']['docNumber']);
    }

    public function testEmptyObjectsSurviveRoundTripWithObjectSchema(): void
    {
        $schema = json_decode('{"type":"object","properties":{"meta":{"type":"object","additionalProperties":false}}}');

        $out = new OutputParser()->parse('{"meta": {}}', $schema);

        $this->assertSame(['meta' => []], $out);
    }

    public function testDeepAdditionalPropertyWinsOverOneOfBranches(): void
    {
        $output = $this->docsOutput();
        $output['document']['extracted_json']['supplier']['contact']['contact_person'] = 'Jan';

        $message = "output does not match schema: Additional properties are not allowed ('contact_person' was unexpected) at ['document', 'extracted_json', 'supplier', 'contact']";
        $this->expectSchemaError($message, fn() => new OutputParser()->parse((string) json_encode($output), $this->profileSchema()));
        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ADDITIONAL_PROPERTY, $this->presenterKind($message));
    }

    public function testRegistryBranchAdditionalKindField(): void
    {
        $output = [
            'overall_confidence' => 0.9,
            'message_classification' => ['primary_type' => 'insurance', 'confidence' => 0.9],
            'document' => ['doc_type' => 'insurance', 'confidence' => 0.9, 'extracted_json' => [
                'schema' => 'shpd.registry.document.v1', 'docType' => 'insurance', 'title' => 'Pojistka',
                'kindFields' => ['insurer' => 'X', 'bogus' => 1, 'other' => 2],
            ]],
        ];

        $this->expectSchemaError(
            "output does not match schema: Additional properties are not allowed ('bogus', 'other' were unexpected) at ['document', 'extracted_json', 'kindFields']",
            fn() => new OutputParser()->parse((string) json_encode($output), $this->profileSchema()),
        );
    }

    public function testEnumViolation(): void
    {
        $output = $this->docsOutput();
        $output['document']['doc_type'] = 'weird';

        $message = "output does not match schema: 'weird' is not one of ['invoiceReceived', 'creditNote', 'contract', 'insurance', 'quotation', 'certificate', 'official'] at ['document', 'doc_type']";
        $this->expectSchemaError($message, fn() => new OutputParser()->parse((string) json_encode($output), $this->profileSchema()));
        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ENUM, $this->presenterKind($message));
    }

    public function testMaxLengthViolation(): void
    {
        $output = ['overall_confidence' => 1.0, 'message_classification' => ['primary_type' => 'other', 'title' => str_repeat('x', 121)], 'document' => null];

        $message = "output does not match schema: '" . str_repeat('x', 121) . "' is too long at ['message_classification', 'title']";
        $this->expectSchemaError($message, fn() => new OutputParser()->parse((string) json_encode($output), $this->profileSchema()));
        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_TOO_LONG, $this->presenterKind($message));
    }

    public function testFormatsAreNotValidated(): void
    {
        // Démon formáty nekontroloval; placeholder z ukázky v promptu server přepíše (D12).
        $output = $this->docsOutput();
        $output['document']['extracted_json']['source']['extractedAt'] = '<ISO timestamp from current time>';

        $out = new OutputParser()->parse((string) json_encode($output), $this->profileSchema());

        $this->assertSame('<ISO timestamp from current time>', $out['document']['extracted_json']['source']['extractedAt']);
    }

    public function testMinimumMaximumAndPattern(): void
    {
        $schema = ['type' => 'object', 'properties' => ['c' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'd' => ['type' => 'string', 'pattern' => '^\d{4}$']]];

        $this->expectSchemaError(
            "output does not match schema: 1.5 is greater than the maximum of 1 at ['c']",
            fn() => new OutputParser()->parse('{"c": 1.5}', $schema),
        );
        $this->expectSchemaError(
            "output does not match schema: -1 is less than the minimum of 0 at ['c']",
            fn() => new OutputParser()->parse('{"c": -1}', $schema),
        );
        // Python repr() zdvojuje zpětné lomítko ve vzoru.
        $this->expectSchemaError(
            "output does not match schema: 'ab' does not match '^\\\\d{4}$' at ['d']",
            fn() => new OutputParser()->parse('{"d": "ab"}', $schema),
        );
    }

    public function testTypeViolationWithNullAndObject(): void
    {
        $this->expectSchemaError(
            "output does not match schema: None is not of type 'object' at ['message_classification']",
            fn() => new OutputParser()->parse('{"message_classification": null}', self::SIMPLE_SCHEMA),
        );
        $this->expectSchemaError(
            "output does not match schema: {'a': True} is not of type 'number' at ['overall_confidence']",
            fn() => new OutputParser()->parse('{"message_classification": {}, "overall_confidence": {"a": true}}', self::SIMPLE_SCHEMA),
        );
    }
}
