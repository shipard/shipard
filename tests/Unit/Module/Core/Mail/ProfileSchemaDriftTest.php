<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * The AI profile's `output_schema.document.oneOf[1].extracted_json` is a
 * oneOf of two inline schema copies:
 *   [0] `modules/core/exchange/schemas/shpd.docs.document.v1.json`
 *   [1] `modules/base/registry/schemas/shpd.registry.document.v1.json`
 * Analyzers receive `output_schema` over the wire (`/claim` response)
 * and don't resolve `$ref` across files, so we keep both canonical
 * schemas inlined. This test catches drift when one is updated and the
 * other isn't.
 *
 * Repair: copy the canonical file content into the corresponding
 * `extracted_json.oneOf[...]` branch of the profile JSONC.
 */
class ProfileSchemaDriftTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: string} [profile, modulesRoot] */
    private function loadProfile(): array
    {
        $modulesRoot = dirname(__DIR__, 5) . '/modules';
        $profile = JsoncParser::parseFile(
            $modulesRoot . '/core/mail/profiles/czech_general.jsonc',
        );
        $this->assertIsArray($profile, 'profile JSONC must parse');
        return [$profile, $modulesRoot];
    }

    /** @return array<int, mixed> */
    private function extractedJsonOneOf(array $profile): array
    {
        // document je oneOf [null, objekt] — objekt s extracted_json je [1].
        $document = $profile['output_schema']['properties']['document']['oneOf'][1] ?? null;
        $this->assertIsArray($document, 'profile output_schema.document.oneOf[1] (object variant) missing');
        $extractedJson = $document['properties']['extracted_json'] ?? null;
        $this->assertIsArray($extractedJson, 'profile output_schema.document.…extracted_json missing');
        $this->assertArrayHasKey('oneOf', $extractedJson, 'extracted_json must be a oneOf of [docs, registry] embeds');
        return $extractedJson['oneOf'];
    }

    public function testProfileDocsSchemaMatchesCanonical(): void
    {
        [$profile, $modulesRoot] = $this->loadProfile();

        $canonical = json_decode(
            (string) file_get_contents($modulesRoot . '/core/exchange/schemas/shpd.docs.document.v1.json'),
            true,
        );
        $this->assertIsArray($canonical, 'docs canonical schema must be valid JSON');

        $this->assertSame(
            $canonical,
            $this->extractedJsonOneOf($profile)[0] ?? null,
            "Drift between docs canonical schema and profile's `extracted_json.oneOf[0]`. "
                . "Copy shpd.docs.document.v1.json content into the profile.",
        );
    }

    public function testProfileRegistrySchemaMatchesCanonical(): void
    {
        [$profile, $modulesRoot] = $this->loadProfile();

        $canonical = json_decode(
            (string) file_get_contents($modulesRoot . '/base/registry/schemas/shpd.registry.document.v1.json'),
            true,
        );
        $this->assertIsArray($canonical, 'registry canonical schema must be valid JSON');

        $this->assertSame(
            $canonical,
            $this->extractedJsonOneOf($profile)[1] ?? null,
            "Drift between registry canonical schema and profile's `extracted_json.oneOf[1]`. "
                . "Copy shpd.registry.document.v1.json content into the profile.",
        );
    }

    public function testProfileMetadata(): void
    {
        [$profile] = $this->loadProfile();

        $this->assertSame('czech_general', $profile['profile_id']);
        $this->assertSame('v4.6.0', $profile['prompt_version']);
        $this->assertContains('invoiceReceived', $profile['supported_doc_types']);
        foreach (['contract', 'insurance', 'quotation', 'certificate', 'official'] as $registryType) {
            $this->assertContains($registryType, $profile['supported_doc_types']);
        }
        $this->assertSame(0.9, $profile['confidence_thresholds']['ready']);
    }

    public function testClassificationTitleIsOptionalAndBounded(): void
    {
        // tasks/mail-message-title-partner.md D2/P3/P8: title musí být ve
        // schématu (additionalProperties: false by jinak odmítlo celý
        // výstup), ale nesmí být required (starší analyzer bez title projde).
        [$profile] = $this->loadProfile();
        $classification = $profile['output_schema']['properties']['message_classification'];

        $this->assertSame(['type' => 'string', 'maxLength' => 120], $classification['properties']['title']);
        $this->assertNotContains('title', $classification['required']);

        $prompt = $profile['prompt_template'];
        $this->assertStringContainsString('"title"', $prompt);
        $this->assertStringContainsString('120 znaků', $prompt);
        // Verze v promptu (source.promptVersion + ukázka) sleduje prompt_version profilu.
        $this->assertSame(2, substr_count($prompt, $profile['prompt_version']));
        $this->assertStringNotContainsString('v4.5.0', $prompt);
        $this->assertStringNotContainsString('v4.4.0', $prompt);
        $this->assertStringNotContainsString('v4.2.0', $prompt);
    }

    public function testPromptEnumeratesKindFieldsExactly(): void
    {
        // Prompt vyjmenovává přesné názvy kindFields per druh — nesoulad
        // s docKinds znamená tiché prázdno v metadatech. Strojová pojistka
        // nad textem promptu.
        [$profile, $modulesRoot] = $this->loadProfile();
        $prompt = (string) $profile['prompt_template'];

        $docKinds = JsoncParser::parseFile($modulesRoot . '/base/registry/config/docKinds.jsonc');
        foreach (['contract', 'insurance', 'quotation', 'certificate', 'official'] as $kind) {
            $expected = sprintf(
                '- %s: %s',
                $kind,
                implode(', ', array_map(static fn(string $f): string => "\"{$f}\"", $docKinds[$kind]['fields'])),
            );
            $this->assertStringContainsString(
                $expected,
                $prompt,
                "Prompt must enumerate kindFields of '{$kind}' exactly as in docKinds.jsonc",
            );
        }

        $this->assertStringContainsString('"' . $profile['prompt_version'] . '"', $prompt, 'prompt must pin its own version');
        $this->assertStringNotContainsString('v4.5.0', $prompt, 'stale prompt version reference');
        $this->assertStringNotContainsString('v4.4.0', $prompt, 'stale prompt version reference');
        $this->assertStringNotContainsString('v4.2.0', $prompt, 'stale prompt version reference');
        $this->assertStringNotContainsString('v4.0.0', $prompt, 'stale prompt version reference');
        $this->assertStringNotContainsString('v3.2.0', $prompt, 'stale prompt version reference');
        // Kontrakt v4 (mail-message-centric D11): nejvýše jeden document,
        // žádné plurální documents / source_attachment_ndxs.
        $this->assertStringNotContainsString('"documents"', $prompt, 'plural documents field is gone in v4');
        $this->assertStringNotContainsString('source_attachment_ndxs', $prompt, 'source_attachment_ndxs is gone in v4');
        $this->assertStringContainsString('"secondary_findings"', $prompt, 'prompt must describe secondary_findings');
    }

    public function testPromptEnumeratesVatSignalsAndSchemaEnums(): void
    {
        // tasks/exchange-received-reverse-charge.md D1/D5: model schéma
        // nevidí — enum bez výslovného výčtu v textu = schema_error celé
        // analýzy. Kód DPH určuje systém, prompt ho musí zakázat.
        [$profile] = $this->loadProfile();
        $prompt = (string) $profile['prompt_template'];
        $docs = $this->extractedJsonOneOf($profile)[0];

        $vat = $docs['properties']['vat']['properties'];
        $this->assertSame(['domestic', 'intracom', 'thirdCountry', null], $vat['place']['enum']);
        $this->assertSame(['fromBase', 'fromTotal', 'none', null], $vat['mode']['enum']);
        $this->assertSame(['boolean', 'null'], $vat['reverseCharge']['type']);
        $rowVat = $docs['$defs']['RowVat']['properties'];
        $this->assertSame(['goods', 'services', null], $rowVat['supplyKind']['enum']);
        $this->assertArrayHasKey('reverseChargeCode', $rowVat);

        foreach (['"domestic"', '"intracom"', '"thirdCountry"', '"fromBase"', '"fromTotal"', '"none"',
                  '"goods"', '"services"', 'reverseCharge', 'reverseChargeCode', 'supplyKind'] as $needle) {
            $this->assertStringContainsString($needle, $prompt, "prompt must mention {$needle}");
        }
        $this->assertStringContainsString('vat.code a "vatRecap"[].vatCode vracej VŽDY null', $prompt);
        $this->assertStringContainsString('registrationCountry VYNECH', $prompt);
        // „none“ jen bez DPH — model ho u reverse charge s DPH 0 volil a doklad
        // by skončil Bez DPH (pojistka je i v applieru).
        $this->assertStringContainsString('"none" použij JEN', $prompt);
        // Ukázka: žádný konkrétní kód DPH, jinak ho model opisuje.
        $this->assertStringNotContainsString('"cz-110"', $prompt);
        $this->assertStringNotContainsString('isReversePair": false', $prompt);
    }
}
