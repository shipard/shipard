<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\AnalysisErrorPresenter;

/**
 * Rozbor `error_message` selhané analýzy na kategorii katalogu
 * `core.mail.analysisErrorKinds` (tasks/mail-analysis-error-messages.md
 * D1–D2) a pravidlo doporučené reanalýzy (D4). Hodnoty v hláškách jsou
 * syntetické — technická hláška může nést data z dokladu.
 */
final class AnalysisErrorPresenterTest extends TestCase
{
    /** Katalog z dodávaného souboru, lokalizovaný jako compiled config. */
    private function shippedConfig(string $lang = 'cs'): ConfigRuntime
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/core/mail/config/analysisErrorKinds.jsonc',
        );
        $this->assertIsArray($raw);
        $localized = ConfigLocalizer::localize($raw, $lang);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === AnalysisErrorPresenter::CFG_ITEM ? $localized : null,
        );
        return $config;
    }

    /** @param array<string,mixed>|null $profileRow návrat fetchRow (verze výchozího profilu) */
    private function presenter(?array $profileRow = null, ?ConfigRuntime $config = null, bool $withConfig = true): AnalysisErrorPresenter
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($profileRow);
        return new AnalysisErrorPresenter($db, $withConfig ? ($config ?? $this->shippedConfig()) : null);
    }

    // ── schema_error ──────────────────────────────────────────────────────

    public function testAdditionalPropertyWithKeyAndStrippedPath(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: Additional properties are not allowed ('contact_person' was unexpected) at ['document', 'extracted_json', 'customer', 'contact']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ADDITIONAL_PROPERTY, $info->kind);
        $this->assertSame('AI vrátila data v nečekaném tvaru', $info->title);
        $this->assertSame('Není to chyba ve zprávě ani v příloze, ale v nastavení analýzy Shipardu.', $info->description);
        $this->assertSame('AI přidala pole „contact_person“ (customer.contact), které formát dokladu nezná.', $info->detail);
        $this->assertStringStartsWith('[schema_error]', (string) $info->technical);
    }

    public function testAdditionalPropertiesPluralJoinsKeys(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: Additional properties are not allowed ('foo', 'bar' were unexpected) at ['document', 'extracted_json']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ADDITIONAL_PROPERTY, $info->kind);
        // Cesta je jen obal → závorkový doplněk zmizí, klíče zůstanou.
        $this->assertSame('AI přidala pole „foo, bar“, které formát dokladu nezná.', $info->detail);
    }

    public function testAdditionalPropertyAtRootHasNoPathSuffix(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: Additional properties are not allowed ('extra' was unexpected) at []",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ADDITIONAL_PROPERTY, $info->kind);
        $this->assertSame('AI přidala pole „extra“, které formát dokladu nezná.', $info->detail);
    }

    public function testTooLongUsesPathOnlyNeverTheValue(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: 'Lorem ipsum dolor sit amet consectetur' is too long at ['document', 'title']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_TOO_LONG, $info->kind);
        $this->assertSame('Hodnota v poli title je delší, než formát dovoluje.', $info->detail);
        $this->assertStringNotContainsString('Lorem', (string) $info->detail);
    }

    public function testEnumKeepsNumericRowIndexInPath(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: 'weird' is not one of ['a', 'b', 'c'] at ['document', 'extracted_json', 'rows', 0, 'vat', 'code']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_ENUM, $info->kind);
        $this->assertSame('Hodnota v poli rows.0.vat.code není z povolených možností.', $info->detail);
    }

    public function testUnknownSchemaMessageFallsToSchemaOtherWithoutDetail(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: 12 is not of type 'string' at ['document', 'extracted_json', 'docNumber']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_OTHER, $info->kind);
        $this->assertSame('AI vrátila data v nečekaném tvaru', $info->title);
        $this->assertNull($info->detail);
    }

    public function testTwoErrorsInOneMessageFallToSchemaOther(): void
    {
        // Budoucí tvar z iter_errors (ai_analyzer#1) — nesmí spadnout ani
        // předstírat jednu konkrétní chybu.
        $info = $this->presenter()->fromErrorMessage(
            "[schema_error] output does not match schema: 'x' is too long at ['document', 'a']; Additional properties are not allowed ('y' was unexpected) at ['document', 'b']",
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_OTHER, $info->kind);
        $this->assertNull($info->detail);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJsonMessages(): iterable
    {
        yield 'fenced'    => ['[schema_error] fenced JSON is invalid: Expecting value: line 1 column 1 (char 0)'];
        yield 'not json'  => ['[schema_error] output is not valid JSON'];
        yield 'not object' => ['[schema_error] output JSON must be an object at the top level'];
    }

    #[DataProvider('invalidJsonMessages')]
    public function testInvalidJsonVariants(string $message): void
    {
        $info = $this->presenter()->fromErrorMessage($message);

        $this->assertSame(AnalysisErrorPresenter::KIND_SCHEMA_INVALID_JSON, $info->kind);
        $this->assertSame('Odpověď AI není platný JSON.', $info->detail);
    }

    // ── ai_error / config_error / neznámé ─────────────────────────────────

    public function testTruncatedOutputIsItsOwnKind(): void
    {
        $info = $this->presenter()->fromErrorMessage('[ai_error] anthropic: output truncated at max_tokens=8192');

        $this->assertSame(AnalysisErrorPresenter::KIND_AI_TRUNCATED, $info->kind);
        $this->assertSame('Odpověď AI se nevešla do limitu', $info->title);
        $this->assertNull($info->detail);
    }

    public function testOtherProviderErrorsAreAiError(): void
    {
        $presenter = $this->presenter();
        foreach ([
            '[ai_error] anthropic permanent: Error code: 400 - invalid document',
            '[ai_error] anthropic sdk: boom',
            '[ai_error] anthropic error: boom',
            "[ai_error] unsupported provider: 'foo'",
            '[ai_error]',
        ] as $message) {
            $this->assertSame(AnalysisErrorPresenter::KIND_AI_ERROR, $presenter->fromErrorMessage($message)->kind, $message);
        }
        $this->assertSame('Službě AI se nepodařilo zprávu zpracovat', $presenter->fromErrorMessage('[ai_error] x')->title);
    }

    public function testConfigError(): void
    {
        $info = $this->presenter()->fromErrorMessage(
            '[config_error] shpd rejected /result body (422 VALIDATION_ERROR): message_classification required',
        );

        $this->assertSame(AnalysisErrorPresenter::KIND_CONFIG_ERROR, $info->kind);
        $this->assertSame('Chyba propojení analyzátoru se Shipardem', $info->title);
    }

    public function testUnknownShapesFallToUnknown(): void
    {
        $presenter = $this->presenter();
        foreach ([null, '', '   ', 'no prefix at all', '[mime_error] cannot decode', '[weird] x'] as $message) {
            $info = $presenter->fromErrorMessage($message);
            $this->assertSame(AnalysisErrorPresenter::KIND_UNKNOWN, $info->kind, var_export($message, true));
            $this->assertSame('Analýza selhala', $info->title);
            $this->assertNull($info->detail);
        }
        $this->assertNull($presenter->fromErrorMessage('')->technical);
        $this->assertSame('[weird] x', $presenter->fromErrorMessage('[weird] x')->technical);
    }

    public function testInvalidOutputKind(): void
    {
        $info = $this->presenter()->forInvalidOutput('v4.3.0');

        $this->assertSame(AnalysisErrorPresenter::KIND_INVALID_OUTPUT, $info->kind);
        $this->assertSame('AI vrátila nepoužitelný návrh', $info->title);
        $this->assertSame('Návrh neprošel kontrolou formátu dokladu a nedá se použít.', $info->description);
        $this->assertNull($info->technical);
    }

    // ── jazyk / fallback ──────────────────────────────────────────────────

    public function testEnglishCatalogAndFallbackWithoutConfigAgree(): void
    {
        $message = "[schema_error] output does not match schema: 'x' is too long at ['document', 'title']";
        $fromEn = $this->presenter(null, $this->shippedConfig('en'))->fromErrorMessage($message);
        $fromFallback = $this->presenter(null, null, false)->fromErrorMessage($message);

        $this->assertSame('AI returned data in an unexpected shape', $fromEn->title);
        $this->assertSame($fromEn->title, $fromFallback->title);
        $this->assertSame($fromEn->description, $fromFallback->description);
        $this->assertSame('The value in field title is longer than the format allows.', $fromFallback->detail);
        $this->assertSame($fromEn->detail, $fromFallback->detail);
        $this->assertSame($fromEn->hint, $fromFallback->hint);
    }

    public function testToArrayCarriesAllFields(): void
    {
        $arr = $this->presenter(['prompt_version' => 'v9.0.0'])
            ->fromErrorMessage('[ai_error] x', 'v4.0.0')
            ->toArray();

        $this->assertSame(
            ['kind', 'title', 'description', 'detail', 'hint', 'reanalysisRecommended', 'technical'],
            array_keys($arr),
        );
        $this->assertTrue($arr['reanalysisRecommended']);
    }

    // ── D4: doporučení reanalýzy ──────────────────────────────────────────

    public function testNewerDefaultProfileRecommendsReanalysis(): void
    {
        $presenter = $this->presenter(['prompt_version' => 'v4.4.0']);

        $this->assertTrue($presenter->isReanalysisRecommended('v4.3.0'));
        $info = $presenter->fromErrorMessage('[ai_error] x', 'v4.3.0');
        $this->assertTrue($info->reanalysisRecommended);
        $this->assertSame('Analýza se mezitím aktualizovala — zkus Znovu analyzovat.', $info->hint);
    }

    public function testSameOrOlderProfileDoesNotRecommend(): void
    {
        $presenter = $this->presenter(['prompt_version' => 'v4.3.0']);

        $this->assertFalse($presenter->isReanalysisRecommended('v4.3.0'));
        $this->assertFalse($presenter->isReanalysisRecommended('v4.9.0'));
        $info = $presenter->fromErrorMessage('[ai_error] x', 'v4.3.0');
        $this->assertFalse($info->reanalysisRecommended);
        $this->assertStringStartsWith('Opakování se stejnou verzí analýzy skončí stejně.', $info->hint);
    }

    public function testUnparsableVersionsNeverRecommend(): void
    {
        $presenter = $this->presenter(['prompt_version' => 'v4.4.0']);

        $this->assertFalse($presenter->isReanalysisRecommended(null));
        $this->assertFalse($presenter->isReanalysisRecommended(''));
        $this->assertFalse($presenter->isReanalysisRecommended('isdoc'));
        $this->assertFalse($presenter->isReanalysisRecommended('unknown'));

        $this->assertFalse($this->presenter(['prompt_version' => 'unknown'])->isReanalysisRecommended('v1.0.0'));
        $this->assertFalse($this->presenter(null)->isReanalysisRecommended('v1.0.0'));
    }

    public function testVersionPrefixIsOptionalAndNumericCompare(): void
    {
        $presenter = $this->presenter(['prompt_version' => '4.10.0']);

        $this->assertTrue($presenter->isReanalysisRecommended('v4.9.0'));
        $this->assertFalse($presenter->isReanalysisRecommended('4.10.0'));
    }

    public function testDefaultProfileVersionIsReadOnce(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchRow')->willReturn(['prompt_version' => 'v4.4.0']);
        $presenter = new AnalysisErrorPresenter($db, $this->shippedConfig());

        $presenter->fromErrorMessage('[ai_error] a', 'v4.3.0');
        $presenter->fromErrorMessage('[ai_error] b', 'v4.3.0');
        $presenter->forInvalidOutput('v4.3.0');
    }

    public function testUnparsableFailedVersionSkipsProfileLookup(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');
        $presenter = new AnalysisErrorPresenter($db, $this->shippedConfig());

        $this->assertFalse($presenter->isReanalysisRecommended('isdoc'));
    }

    // ── detail karty Dashboardu ───────────────────────────────────────────

    public function testCardDetailsTwoRowsWithoutTechnical(): void
    {
        $presenter = $this->presenter(['prompt_version' => 'v4.4.0']);
        $info = $presenter->fromErrorMessage(
            "[schema_error] output does not match schema: 'Lorem' is too long at ['document', 'title']",
            'v4.3.0',
        );

        $details = $presenter->cardDetails($info);
        $this->assertSame(['Co se stalo', 'Co dělat'], array_column($details, 'label'));
        $this->assertSame(
            'Není to chyba ve zprávě ani v příloze, ale v nastavení analýzy Shipardu. Hodnota v poli title je delší, než formát dovoluje.',
            $details[0]['value'],
        );
        $this->assertSame($info->hint, $details[1]['value']);
        $this->assertStringNotContainsString('Lorem', json_encode($details, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testCardDetailsWithoutDetailIsDescriptionOnly(): void
    {
        $presenter = $this->presenter();
        $details = $presenter->cardDetails($presenter->fromErrorMessage('[ai_error] x'));

        $this->assertSame('Například kvůli nepodporované nebo poškozené příloze.', $details[0]['value']);
    }
}
