<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\Preprocess\PreprocessErrorPresenter;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureInfo;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;

/**
 * Výběr kategorie katalogu `core.mail.preprocessErrorKinds` z kódů
 * neúspěšných záznamů `preprocess_log.results`
 * (tasks/mail-preprocess-error-messages.md D1–D3): priorita více kódů,
 * záznam bez kódu, ISDOC, nic neselhalo, texty pro tab Návrh a kartu.
 * Fixtury jen s fiktivními doménami — poznámky nesou URL.
 */
final class PreprocessErrorPresenterTest extends TestCase
{
    private const URL_NOTE = 'HTTP 404 at https://example.com/dl/abc?token=secret';

    /** Katalog z dodávaného souboru, lokalizovaný jako compiled config. */
    private function shippedConfig(string $lang = 'cs'): ConfigRuntime
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 6) . '/modules/core/mail/config/preprocessErrorKinds.jsonc',
        );
        $this->assertIsArray($raw);
        $localized = ConfigLocalizer::localize($raw, $lang);

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === PreprocessErrorPresenter::CFG_ITEM ? $localized : null,
        );
        return $config;
    }

    private function presenter(bool $withConfig = true, string $lang = 'cs'): PreprocessErrorPresenter
    {
        return new PreprocessErrorPresenter($withConfig ? $this->shippedConfig($lang) : null);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function log(array $results, array $extra = []): array
    {
        return $extra + [
            'plan'       => [['ruleId' => 'bolt-invoice-link', 'ruleNdx' => 1, 'actions' => [['action' => 'fetchLinkedDocument']]]],
            'results'    => $results,
            'attempts'   => 1,
            'isdoc'      => 'none',
            'finishedAt' => '2026-10-02T10:00:00+02:00',
        ];
    }

    /** @return array<string, mixed> */
    private function failed(
        string $code,
        string $note = self::URL_NOTE,
        string $ruleId = 'bolt-invoice-link',
        string $action = 'fetchLinkedDocument',
    ): array {
        return ['ruleId' => $ruleId, 'action' => $action, 'ok' => false, 'note' => $note, 'code' => $code];
    }

    // ── kategorie ────────────────────────────────────────────────────────

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function categories(): iterable
    {
        yield 'linkNotFound'      => [PreprocessFailureCode::LINK_NOT_FOUND, 'V e-mailu chybí odkaz na dokument'];
        yield 'linkExpired'       => [PreprocessFailureCode::LINK_EXPIRED, 'Odkaz na dokument nefunguje'];
        yield 'remoteUnavailable' => [PreprocessFailureCode::REMOTE_UNAVAILABLE, 'Server s dokumentem nebyl dostupný'];
        yield 'unexpectedContent' => [PreprocessFailureCode::UNEXPECTED_CONTENT, 'Na odkazu není použitelný dokument'];
        yield 'bodyRender'        => [PreprocessFailureCode::BODY_RENDER, 'Text e-mailu se nepodařilo převést do PDF'];
        yield 'ruleConfig'        => [PreprocessFailureCode::RULE_CONFIG, 'Pravidlo předzpracování je nastavené chybně'];
        yield 'internal'          => [PreprocessFailureCode::INTERNAL, 'Předzpracování selhalo na straně Shipardu'];
    }

    #[DataProvider('categories')]
    public function testEachCodeMapsToCatalogCategory(string $code, string $title): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([$this->failed($code)]));

        $this->assertNotNull($info);
        $this->assertSame($code, $info->kind);
        $this->assertSame(PreprocessFailureInfo::VARIANT_WARNING, $info->variant);
        $this->assertSame($title, $info->title);
        $this->assertNotSame('', $info->description);
        $this->assertNotSame('', $info->hint);
        $this->assertStringNotContainsString('{ruleId}', $info->hint);
        $this->assertSame(1, $info->failedCount);
        $this->assertSame('bolt-invoice-link', $info->ruleId);
        $this->assertSame('bolt-invoice-link / fetchLinkedDocument: ' . self::URL_NOTE, $info->technical);
    }

    public function testRuleIdIsSubstitutedIntoHint(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([$this->failed(PreprocessFailureCode::RULE_CONFIG)]));

        $this->assertSame(
            'Zkontroluj pravidlo bolt-invoice-link v Nastavení → Pošta → Pravidla předzpracování, nebo nám dej vědět.',
            $info?->hint,
        );
    }

    public function testMissingRuleIdDropsTokenFromHint(): void
    {
        // Neznámá akce z plánu bez ruleId → ruleConfig bez pravidla (U3).
        $info = $this->presenter()->fromLog(40, $this->log([
            $this->failed(PreprocessFailureCode::RULE_CONFIG, "unknown action 'x'", '', 'x'),
        ]));

        $this->assertNull($info?->ruleId);
        $this->assertSame(
            'Zkontroluj pravidlo v Nastavení → Pošta → Pravidla předzpracování, nebo nám dej vědět.',
            $info?->hint,
        );
    }

    // ── priorita a fallbacky ─────────────────────────────────────────────

    public function testMostSpecificCodeWinsAndCarriesItsRuleId(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([
            $this->failed(PreprocessFailureCode::REMOTE_UNAVAILABLE, 'transport error: timeout', 'rule-a'),
            $this->failed(PreprocessFailureCode::LINK_EXPIRED, 'HTTP 410 at https://example.com/x', 'rule-b'),
        ]));

        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, $info?->kind);
        $this->assertSame('rule-b', $info?->ruleId);
        $this->assertSame(2, $info?->failedCount);
        $this->assertSame(
            "rule-a / fetchLinkedDocument: transport error: timeout\nrule-b / fetchLinkedDocument: HTTP 410 at https://example.com/x",
            $info?->technical,
        );
    }

    public function testBodyRenderRanksBelowUnexpectedContentAndAboveRemoteUnavailable(): void
    {
        $presenter = $this->presenter();

        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $presenter->fromLog(40, $this->log([
            $this->failed(PreprocessFailureCode::BODY_RENDER, 'render failed', 'apple-invoice-body', 'renderBodyToPdf'),
            $this->failed(PreprocessFailureCode::UNEXPECTED_CONTENT),
        ]))?->kind);
        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $presenter->fromLog(40, $this->log([
            $this->failed(PreprocessFailureCode::REMOTE_UNAVAILABLE),
            $this->failed(PreprocessFailureCode::BODY_RENDER, 'render failed', 'apple-invoice-body', 'renderBodyToPdf'),
        ]))?->kind);
    }

    public function testRecordWithoutCodeIsUnknown(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([
            ['ruleId' => 'r', 'action' => 'fetchLinkedDocument', 'ok' => false, 'note' => 'legacy note'],
        ]));

        $this->assertSame(PreprocessErrorPresenter::KIND_UNKNOWN, $info?->kind);
        $this->assertSame('Předzpracování skončilo s chybou', $info?->title);
        $this->assertSame('Dej nám vědět, o jakou zprávu šlo.', $info?->hint);
        $this->assertSame('r', $info?->ruleId);
        $this->assertSame('r / fetchLinkedDocument: legacy note', $info?->technical);
    }

    public function testUnknownCodeLosesToKnownCode(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([
            $this->failed('weird', 'something', 'rule-a'),
            $this->failed(PreprocessFailureCode::INTERNAL, 'RuntimeException: boom', 'rule-b'),
        ]));

        $this->assertSame(PreprocessFailureCode::INTERNAL, $info?->kind);
        $this->assertSame('rule-b', $info?->ruleId);
    }

    public function testSuccessfulRecordsAreIgnored(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([
            ['ruleId' => 'rule-a', 'action' => 'renderBodyToPdf', 'ok' => true, 'note' => 'rendered → attachment 5', 'attachmentId' => 5],
            $this->failed(PreprocessFailureCode::LINK_NOT_FOUND, 'no link matching linkHrefRegex found in the message body'),
        ]));

        $this->assertSame(PreprocessFailureCode::LINK_NOT_FOUND, $info?->kind);
        $this->assertSame(1, $info?->failedCount);
        $this->assertStringNotContainsString('attachment 5', (string) $info?->technical);
    }

    public function testPlanAndSweepRecordsAreInternalWithoutRule(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([
            ['action' => 'plan', 'ok' => false, 'note' => 'stored plan is empty', 'code' => PreprocessFailureCode::INTERNAL],
            ['action' => 'sweep', 'ok' => false, 'note' => 'gave up after 3 attempts (stuck in state 20)', 'code' => PreprocessFailureCode::INTERNAL],
        ]));

        $this->assertSame(PreprocessFailureCode::INTERNAL, $info?->kind);
        $this->assertNull($info?->ruleId);
        $this->assertSame("plan: stored plan is empty\nsweep: gave up after 3 attempts (stuck in state 20)", $info?->technical);
    }

    public function testStateFortyWithoutFailedRecordsIsUnknown(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([]));

        $this->assertSame(PreprocessErrorPresenter::KIND_UNKNOWN, $info?->kind);
        $this->assertSame(0, $info?->failedCount);
        $this->assertNull($info?->technical);
        $this->assertNull($info?->ruleId);
    }

    // ── ISDOC a nic neselhalo ────────────────────────────────────────────

    public function testIsdocFailedOutsideStateFortyIsInfo(): void
    {
        $info = $this->presenter()->fromLog(30, $this->log([], ['isdoc' => 'failed']));

        $this->assertSame(PreprocessErrorPresenter::KIND_ISDOC_FAILED, $info?->kind);
        $this->assertSame(PreprocessFailureInfo::VARIANT_INFO, $info?->variant);
        $this->assertSame('Přílohu ISDOC se nepodařilo načíst', $info?->title);
        $this->assertSame('Návrh zkontroluj obvyklým způsobem.', $info?->hint);
        $this->assertSame(0, $info?->failedCount);
        $this->assertNull($info?->technical);
    }

    public function testStateFortyBeatsIsdocFailed(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log(
            [$this->failed(PreprocessFailureCode::BODY_RENDER, 'render failed', 'apple-invoice-body', 'renderBodyToPdf')],
            ['isdoc' => 'failed'],
        ));

        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $info?->kind);
        $this->assertSame(PreprocessFailureInfo::VARIANT_WARNING, $info?->variant);
    }

    public function testNothingFailedReturnsNull(): void
    {
        $presenter = $this->presenter();

        $this->assertNull($presenter->fromLog(0, null));
        $this->assertNull($presenter->fromLog(0, ''));
        $this->assertNull($presenter->fromLog(10, $this->log([])));
        $this->assertNull($presenter->fromLog(20, $this->log([])));
        $this->assertNull($presenter->fromLog(30, $this->log([['ruleId' => 'r', 'action' => 'a', 'ok' => true]])));
        $this->assertNull($presenter->fromLog(30, $this->log([], ['isdoc' => 'imported'])));
        $this->assertNull($presenter->fromLog(30, $this->log([], ['isdoc' => 'skipped'])));
    }

    public function testAcceptsRawJsonLog(): void
    {
        $raw = PreprocessRunner::encodeLog($this->log([$this->failed(PreprocessFailureCode::LINK_EXPIRED)]));

        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, $this->presenter()->fromLog(40, $raw)?->kind);
    }

    // ── texty pro tab Návrh a kartu ──────────────────────────────────────

    public function testProposalWarningAndCardWarningCarryNoUrl(): void
    {
        $presenter = $this->presenter();
        $info = $presenter->fromLog(40, $this->log([$this->failed(PreprocessFailureCode::LINK_EXPIRED)]));
        $this->assertNotNull($info);

        $warning = $presenter->proposalWarning($info);
        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, $warning['kind']);
        $this->assertSame('Návrh vznikl bez výsledku předzpracování', $warning['title']);
        $this->assertSame(
            'AI pracovala bez dokumentu, který mělo předzpracování vytvořit, takže návrh nebo klasifikace nemusí sedět. Podrobnosti jsou v záložce Obsah.',
            $warning['text'],
        );

        $this->assertSame('Předzpracování: Odkaz na dokument nefunguje', $presenter->cardWarning($info));

        // URL s tokenem zůstává jen v technických podrobnostech.
        $this->assertStringContainsString('example.com', (string) $info->technical);
        $this->assertStringNotContainsString('example.com', $presenter->cardWarning($info));
        $this->assertStringNotContainsString('example.com', $warning['title'] . $warning['text']);
        $this->assertStringNotContainsString('example.com', $info->title . $info->description . $info->hint);
    }

    public function testToArrayIsFailureCardCompatible(): void
    {
        $info = $this->presenter()->fromLog(40, $this->log([$this->failed(PreprocessFailureCode::INTERNAL)]));
        $this->assertNotNull($info);

        $array = $info->toArray();

        $this->assertSame(
            ['kind', 'variant', 'title', 'description', 'detail', 'hint', 'failedCount', 'technical'],
            array_keys($array),
        );
        $this->assertNull($array['detail']);
        $this->assertSame('warning', $array['variant']);
        $this->assertSame(1, $array['failedCount']);
    }

    // ── jazyk a fallback ─────────────────────────────────────────────────

    public function testEnglishCatalog(): void
    {
        $presenter = $this->presenter(true, 'en');
        $info = $presenter->fromLog(40, $this->log([$this->failed(PreprocessFailureCode::LINK_EXPIRED)]));

        $this->assertSame('The document link does not work', $info?->title);
        $this->assertSame('Preprocessing: The document link does not work', $presenter->cardWarning($info));
    }

    public function testFallbackWithoutConfigIsEnglish(): void
    {
        $presenter = $this->presenter(false);

        $info = $presenter->fromLog(40, $this->log([$this->failed(PreprocessFailureCode::RULE_CONFIG)]));
        $this->assertSame('The preprocessing rule is set up incorrectly', $info?->title);
        $this->assertSame('Check rule bolt-invoice-link in Settings → Mail → Preprocess rules, or let us know.', $info?->hint);
        $this->assertSame('The proposal was created without the preprocessing result', $presenter->proposalWarning($info)['title']);

        $isdoc = $presenter->fromLog(30, $this->log([], ['isdoc' => 'failed']));
        $this->assertSame('The ISDOC attachment could not be read', $isdoc?->title);
    }

    // ── PreprocessFailureCode ────────────────────────────────────────────

    public function testMostSpecificHelper(): void
    {
        $this->assertNull(PreprocessFailureCode::mostSpecific([]));
        $this->assertNull(PreprocessFailureCode::mostSpecific(['', 'weird', null, 7]));
        $this->assertSame(
            PreprocessFailureCode::LINK_EXPIRED,
            PreprocessFailureCode::mostSpecific([PreprocessFailureCode::LINK_NOT_FOUND, PreprocessFailureCode::INTERNAL, PreprocessFailureCode::LINK_EXPIRED]),
        );
        $this->assertSame(
            PreprocessFailureCode::LINK_NOT_FOUND,
            PreprocessFailureCode::mostSpecific(['weird', PreprocessFailureCode::LINK_NOT_FOUND]),
        );
        $this->assertSame(
            PreprocessFailureCode::RULE_CONFIG,
            PreprocessFailureCode::mostSpecific([PreprocessFailureCode::UNEXPECTED_CONTENT, PreprocessFailureCode::RULE_CONFIG, PreprocessFailureCode::REMOTE_UNAVAILABLE]),
        );
        $this->assertTrue(PreprocessFailureCode::isKnown(PreprocessFailureCode::BODY_RENDER));
        $this->assertFalse(PreprocessFailureCode::isKnown('unknown'));
        $this->assertFalse(PreprocessFailureCode::isKnown(''));
    }
}
