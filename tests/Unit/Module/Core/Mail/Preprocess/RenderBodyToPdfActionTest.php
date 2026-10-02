<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Preprocess\Action\GeneratedAttachments;
use Shipard\Module\Core\Mail\Preprocess\Action\RenderBodyToPdfAction;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;

/**
 * renderBodyToPdf: prázdné tělo, size cap, selhání renderu (vč.
 * nenakonfigurované služby) jako výsledek bez výjimky; úspěch → PDF
 * příloha s provenance a názvem ze subjectu; idempotence dle (ruleId,
 * action); UTF-8 hlavička vložená do HTML pro engine.
 */
class RenderBodyToPdfActionTest extends TestCase
{
    private const PDF = "%PDF-1.7\n%fake\n";

    /** @var list<array{id: int, extra: array<string, mixed>}> */
    private array $merged = [];
    /** @var list<array<string, mixed>> */
    private array $uploads = [];

    protected function setUp(): void
    {
        RenderClient::resetWarningForTesting();
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath(sys_get_temp_dir() . '/shpd_render_action_test.log');
        $this->merged = [];
        $this->uploads = [];
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        RenderClient::resetWarningForTesting();
        @unlink(sys_get_temp_dir() . '/shpd_render_action_test.log');
    }

    /** @param list<array<string, mixed>> $existing */
    private function attachments(array $existing = [], bool $uploadOk = true): AttachmentService
    {
        $att = $this->createMock(AttachmentService::class);
        $att->method('listAttachments')->willReturn($existing);
        $att->method('upload')->willReturnCallback(
            function (int $tableId, int $recordId, string $name, string $tmp) use ($uploadOk): array {
                $this->uploads[] = [
                    'name' => $name,
                    'tmp' => $tmp,
                    'content' => is_file($tmp) ? (string) file_get_contents($tmp) : null,
                    'table' => $tableId,
                    'record' => $recordId,
                ];
                if (!$uploadOk) {
                    return ['success' => false, 'error' => 'disk full'];
                }
                @unlink($tmp);
                return ['success' => true, 'data' => ['id' => 91, 'name' => $name]];
            },
        );
        $att->method('mergeMetadata')->willReturnCallback(function (int $id, array $extra): bool {
            $this->merged[] = ['id' => $id, 'extra' => $extra];
            return true;
        });
        return $att;
    }

    /** @return array{0: RenderBodyToPdfAction, 1: FakeRenderEngine} */
    private function action(?RenderResult $result = null, ?AttachmentService $att = null): array
    {
        $engine = new FakeRenderEngine($result ?? RenderResult::success(self::PDF));
        $client = new RenderClient(new RenderConfig('http://127.0.0.1:3000', 30), $engine);
        return [new RenderBodyToPdfAction($att ?? $this->attachments(), $client), $engine];
    }

    /** @return array<string, mixed> */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'subject' => 'Fwd: Vaše faktura od Apple',
            'body_html' => '<div>Apple Distribution International — faktura č. 1</div>',
            'body_plain' => 'Apple Distribution International',
        ], $overrides);
    }

    // --- happy path ------------------------------------------------------

    public function testRendersBodyAndStoresPdfWithProvenance(): void
    {
        [$action, $engine] = $this->action();
        $message = $this->message();

        $result = $action->execute($message, 'apple-invoice-body', ['action' => 'renderBodyToPdf']);

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame([91], $result->attachmentIds);
        $this->assertStringContainsString('attachment 91', $result->note);

        $this->assertCount(1, $engine->renders);
        $this->assertSame([], $engine->renders[0]['assets'], 'bez assetů — vzdálené obrázky se nenačítají');
        $this->assertSame(30, $engine->renders[0]['timeoutSec'], 'Untrusted strop');
        $this->assertStringContainsString('<meta charset="utf-8">', $engine->renders[0]['html']);
        $this->assertStringContainsString($message['body_html'], $engine->renders[0]['html']);

        $this->assertCount(1, $this->uploads);
        $this->assertSame('Fwd_ Vaše faktura od Apple.pdf', $this->uploads[0]['name']);
        $this->assertSame(self::PDF, $this->uploads[0]['content']);
        $this->assertSame(GeneratedAttachments::MAIL_TABLE_ID, $this->uploads[0]['table']);
        $this->assertSame(42, $this->uploads[0]['record']);
        $this->assertFileDoesNotExist($this->uploads[0]['tmp']);

        $this->assertCount(1, $this->merged);
        $extra = $this->merged[0]['extra'];
        $this->assertSame(91, $this->merged[0]['id']);
        $this->assertSame('preprocess', $extra['generatedBy']);
        $this->assertSame('apple-invoice-body', $extra['ruleId']);
        $this->assertSame('renderBodyToPdf', $extra['action']);
        $this->assertSame(hash('sha256', $message['body_html']), $extra['bodySha256']);
        $this->assertArrayHasKey('renderedAt', $extra);
        $this->assertArrayNotHasKey('sourceUrl', $extra);
    }

    public function testEmptySubjectFallsBackToGenericName(): void
    {
        [$action] = $this->action();

        $result = $action->execute($this->message(['subject' => '  ']), 'r', []);

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame('message-body.pdf', $this->uploads[0]['name']);
    }

    public function testSubjectWithForbiddenCharsAndLengthIsSanitized(): void
    {
        [$action] = $this->action();
        $subject = 'Re: a/b\\c:d*e?f"g<h>i|j' . str_repeat('x', 200) . '.PDF';

        $result = $action->execute($this->message(['subject' => $subject]), 'r', []);

        $this->assertTrue($result->ok, $result->note);
        $name = $this->uploads[0]['name'];
        $this->assertStringStartsWith('Re_ a_b_c_d_e_f_g_h_i_j', $name);
        $this->assertStringEndsWith('.pdf', $name);
        $this->assertLessThanOrEqual(GeneratedAttachments::FILE_NAME_MAX_LENGTH, mb_strlen($name));
        $this->assertDoesNotMatchRegularExpression('~\.PDF\.pdf$~', $name, 'existující přípona se nezdvojuje');
    }

    // --- idempotence -----------------------------------------------------

    public function testExistingAttachmentWithSameRuleAndActionSkipsRender(): void
    {
        $existing = [[
            'id' => 55,
            'metadata' => json_encode(['generatedBy' => 'preprocess', 'action' => 'renderBodyToPdf', 'ruleId' => 'apple-invoice-body']),
        ]];
        [$action, $engine] = $this->action(null, $this->attachments($existing));

        $result = $action->execute($this->message(), 'apple-invoice-body', []);

        $this->assertTrue($result->ok);
        $this->assertSame([55], $result->attachmentIds);
        $this->assertStringContainsString('already present', $result->note);
        $this->assertSame([], $engine->renders);
        $this->assertSame([], $this->uploads);
    }

    public function testAttachmentFromOtherRuleOrActionDoesNotCount(): void
    {
        $existing = [
            ['id' => 55, 'metadata' => json_encode(['generatedBy' => 'preprocess', 'action' => 'renderBodyToPdf', 'ruleId' => 'other-rule'])],
            ['id' => 56, 'metadata' => json_encode(['generatedBy' => 'preprocess', 'action' => 'fetchLinkedDocument', 'ruleId' => 'apple-invoice-body'])],
            ['id' => 57, 'metadata' => null],
        ];
        [$action, $engine] = $this->action(null, $this->attachments($existing));

        $result = $action->execute($this->message(), 'apple-invoice-body', []);

        $this->assertTrue($result->ok, $result->note);
        $this->assertCount(1, $engine->renders);
    }

    // --- selhání ---------------------------------------------------------

    public function testMissingHtmlBodyFails(): void
    {
        [$action, $engine] = $this->action();

        foreach ([null, '', "  \n"] as $body) {
            $result = $action->execute($this->message(['body_html' => $body]), 'r', []);
            $this->assertFalse($result->ok);
            $this->assertStringContainsString('no HTML body', $result->note);
            $this->assertSame(PreprocessFailureCode::BODY_RENDER, $result->code);
        }
        $this->assertSame([], $engine->renders);
    }

    public function testOversizedBodyFailsBeforeRender(): void
    {
        [$action, $engine] = $this->action();
        $body = '<p>' . str_repeat('a', RenderBodyToPdfAction::HTML_MAX_BYTES) . '</p>';

        $result = $action->execute($this->message(['body_html' => $body]), 'r', []);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('size cap', $result->note);
        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $result->code);
        $this->assertSame([], $engine->renders);
    }

    public function testRenderFailureIsReportedWithKindAndNote(): void
    {
        [$action] = $this->action(RenderResult::failure(RenderErrorKind::Timeout, 'exceeded 30 s'));

        $result = $action->execute($this->message(), 'r', []);

        $this->assertFalse($result->ok);
        $this->assertSame('render failed: timeout: exceeded 30 s', $result->note);
        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $result->code);
        $this->assertSame([], $this->uploads);
    }

    public function testUnconfiguredRenderClientFailsWithoutException(): void
    {
        $action = new RenderBodyToPdfAction($this->attachments(), new RenderClient(null));

        $result = $action->execute($this->message(), 'r', []);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('unconfigured', $result->note);
        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $result->code);
        $this->assertSame([], $this->uploads);
    }

    public function testUploadFailureIsReportedAndTempCleaned(): void
    {
        [$action] = $this->action(null, $this->attachments([], false));

        $result = $action->execute($this->message(), 'r', []);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('disk full', $result->note);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $result->code);
        $this->assertFileDoesNotExist($this->uploads[0]['tmp']);
        $this->assertSame([], $this->merged);
    }

    // --- UTF-8 wrapper -----------------------------------------------------

    public function testEnsureUtf8DocumentCoversFragmentHeadHtmlAndExistingCharset(): void
    {
        $meta = '<meta charset="utf-8">';

        $this->assertSame(
            '<!DOCTYPE html><html><head>' . $meta . '</head><body><p>x</p></body></html>',
            RenderBodyToPdfAction::ensureUtf8Document('<p>x</p>'),
            'fragment se obalí celým dokumentem',
        );
        $this->assertSame(
            '<html><head lang="cs">' . $meta . '<title>t</title></head><body>x</body></html>',
            RenderBodyToPdfAction::ensureUtf8Document('<html><head lang="cs"><title>t</title></head><body>x</body></html>'),
            'meta se vloží na začátek existujícího head',
        );
        $this->assertSame(
            '<HTML><head>' . $meta . '</head><body>x</body></HTML>',
            RenderBodyToPdfAction::ensureUtf8Document('<HTML><body>x</body></HTML>'),
            'html bez head dostane head',
        );
        foreach ([
            '<html><head><meta charset="windows-1250"></head><body>x</body></html>',
            '<html><head><META http-equiv="Content-Type" content="text/html; charset=iso-8859-2"></head></html>',
        ] as $declared) {
            $this->assertSame($declared, RenderBodyToPdfAction::ensureUtf8Document($declared), 'deklarované kódování se nepřepisuje');
        }
    }
}
