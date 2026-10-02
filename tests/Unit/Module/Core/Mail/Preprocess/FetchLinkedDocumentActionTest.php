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
use Shipard\Module\Core\Mail\Preprocess\Action\FetchLinkedDocumentAction;
use Shipard\Module\Core\Mail\Preprocess\Http\HttpFetcher;
use Shipard\Module\Core\Mail\Preprocess\Http\HttpResponse;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;

/** Mapa URL → odpověď; zaznamenává requesty (URL + pinovaná IP). */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var list<array{url: string, ip: string}> */
    public array $requests = [];

    /** @param array<string, HttpResponse> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'ip' => $pinnedIp];
        return $this->responses[$url] ?? new HttpResponse(404);
    }
}

/**
 * fetchLinkedDocument: redirect chain přes tracking wrapper, allowlist na
 * finální URL, SSRF blok privátních adres per hop, size cap, content-type,
 * idempotence dle provenance, selhání jako výsledek (bez výjimky).
 */
class FetchLinkedDocumentActionTest extends TestCase
{
    private const WRAPPER = 'https://awstrack.me/L0/https:%2F%2Finvoice.bolt.eu%2Fdl%2Fabc/1/0100/x-y';
    private const FINAL = 'https://invoice.bolt.eu/dl/abc';
    private const PDF = "%PDF-1.7\n%fake\n";

    /** @var list<array{id: int, extra: array<string, mixed>}> */
    private array $merged = [];
    /** @var list<array{name: string, tmp: string, tmpExisted: bool}> */
    private array $uploads = [];

    protected function setUp(): void
    {
        RenderClient::resetWarningForTesting();
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath(sys_get_temp_dir() . '/shpd_fetch_action_test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        RenderClient::resetWarningForTesting();
        @unlink(sys_get_temp_dir() . '/shpd_fetch_action_test.log');
    }

    /** @param list<array<string, mixed>> $existing */
    private function attachments(array $existing = [], bool $uploadOk = true): AttachmentService
    {
        $att = $this->createMock(AttachmentService::class);
        $att->method('listAttachments')->willReturn($existing);
        $att->method('upload')->willReturnCallback(
            function (int $tableId, int $recordId, string $name, string $tmp) use ($uploadOk): array {
                $this->uploads[] = ['name' => $name, 'tmp' => $tmp, 'tmpExisted' => is_file($tmp), 'table' => $tableId, 'record' => $recordId];
                if (!$uploadOk) {
                    return ['success' => false, 'error' => 'disk full'];
                }
                @unlink($tmp); // FileStorage soubor přesouvá
                return ['success' => true, 'data' => ['id' => 77, 'name' => $name]];
            },
        );
        $att->method('mergeMetadata')->willReturnCallback(function (int $id, array $extra): bool {
            $this->merged[] = ['id' => $id, 'extra' => $extra];
            return true;
        });
        return $att;
    }

    /** @param array<string, HttpResponse> $responses */
    private function action(array $responses, ?AttachmentService $att = null, ?\Closure $resolver = null, ?RenderClient $render = null): array
    {
        $http = new FakeHttpFetcher($responses);
        $action = new FetchLinkedDocumentAction(
            $att ?? $this->attachments(),
            $http,
            $resolver ?? static fn(string $host): array => ['93.184.216.34'],
            $render,
        );
        return [$action, $http];
    }

    /** @return array{0: RenderClient, 1: FakeRenderEngine} */
    private function renderClient(?RenderResult $result = null): array
    {
        $engine = new FakeRenderEngine($result ?? RenderResult::success(self::PDF));
        return [new RenderClient(new RenderConfig('http://127.0.0.1:3000', 30), $engine), $engine];
    }

    /** @return array<string, mixed> */
    private function message(string $html = '', ?string $plain = null): array
    {
        return [
            'id' => 42,
            'body_html' => $html !== '' ? $html : '<p>Fwd:</p><a href="' . self::WRAPPER . '">Download invoice</a>',
            'body_plain' => $plain,
        ];
    }

    /** @return array<string, mixed> */
    private function params(array $overrides = []): array
    {
        return array_merge([
            'action' => 'fetchLinkedDocument',
            'linkHrefRegex' => 'invoice\.bolt\.eu',
            'allowedDomains' => ['bolt.eu'],
        ], $overrides);
    }

    // --- happy path ------------------------------------------------------

    public function testFollowsTrackingRedirectAndStoresPdfWithProvenance(): void
    {
        [$action, $http] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => self::FINAL]),
            self::FINAL => new HttpResponse(200, [
                'content-type' => 'application/pdf',
                'content-disposition' => 'attachment; filename="Bolt-Invoice-2026.pdf"',
            ], self::PDF),
        ]);

        $result = $action->execute($this->message(), 'bolt-invoice-link', $this->params());

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame([77], $result->attachmentIds);
        $this->assertSame([self::WRAPPER, self::FINAL], array_column($http->requests, 'url'));
        $this->assertSame('93.184.216.34', $http->requests[0]['ip'], 'ověřená IP se pinuje do requestu');

        $this->assertCount(1, $this->uploads);
        $this->assertSame('Bolt-Invoice-2026.pdf', $this->uploads[0]['name']);
        $this->assertSame(303, $this->uploads[0]['table']);
        $this->assertSame(42, $this->uploads[0]['record']);
        $this->assertTrue($this->uploads[0]['tmpExisted']);

        $this->assertCount(1, $this->merged);
        $this->assertSame(77, $this->merged[0]['id']);
        $extra = $this->merged[0]['extra'];
        $this->assertSame('preprocess', $extra['generatedBy']);
        $this->assertSame('bolt-invoice-link', $extra['ruleId']);
        $this->assertSame('fetchLinkedDocument', $extra['action']);
        $this->assertSame(self::WRAPPER, $extra['sourceUrl'], 'provenance nese původní odkaz z těla');
        $this->assertSame(self::FINAL, $extra['finalUrl']);
    }

    public function testOctetStreamWithPdfMagicIsAccepted(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/octet-stream'], self::PDF),
        ]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame('abc.pdf', $this->uploads[0]['name'], 'název z URL, doplněná přípona');
    }

    public function testRelativeLocationIsResolvedAgainstCurrentUrl(): void
    {
        [$action, $http] = $this->action([
            'https://invoice.bolt.eu/r/1' => new HttpResponse(302, ['location' => '/dl/abc']),
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ]);

        $result = $action->execute($this->message('<a href="https://invoice.bolt.eu/r/1">x</a>'), 'r', $this->params());

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame(self::FINAL, $http->requests[1]['url']);
    }

    // --- idempotence -------------------------------------------------------

    public function testExistingAttachmentWithSameProvenanceSkipsFetch(): void
    {
        $existing = [[
            'id' => 55,
            'metadata' => json_encode([
                'generatedBy' => 'preprocess',
                'action' => 'fetchLinkedDocument',
                'ruleId' => 'bolt-invoice-link',
                'sourceUrl' => self::WRAPPER,
            ]),
        ]];
        [$action, $http] = $this->action([], $this->attachments($existing));

        $result = $action->execute($this->message(), 'bolt-invoice-link', $this->params());

        $this->assertTrue($result->ok);
        $this->assertSame([55], $result->attachmentIds);
        $this->assertStringContainsString('already present', $result->note);
        $this->assertSame([], $http->requests);
        $this->assertSame([], $this->uploads);
    }

    public function testDifferentRuleIdDoesNotCountAsExisting(): void
    {
        $existing = [[
            'id' => 55,
            'metadata' => json_encode(['generatedBy' => 'preprocess', 'action' => 'fetchLinkedDocument', 'ruleId' => 'other', 'sourceUrl' => self::WRAPPER]),
        ]];
        [$action, $http] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => self::FINAL]),
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ], $this->attachments($existing));

        $result = $action->execute($this->message(), 'bolt-invoice-link', $this->params());

        $this->assertTrue($result->ok);
        $this->assertCount(2, $http->requests);
    }

    // --- bezpečnost ---------------------------------------------------------

    public function testFinalHostOutsideAllowlistIsRejected(): void
    {
        [$action] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => 'https://evil.example/invoice.bolt.eu.pdf']),
            'https://evil.example/invoice.bolt.eu.pdf' => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ]);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('not in allowedDomains', $result->note);
        $this->assertSame(PreprocessFailureCode::RULE_CONFIG, $result->code);
        $this->assertSame([], $this->uploads);
    }

    public function testFinalUrlMustMatchRegexEvenOnAllowedHost(): void
    {
        [$action] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => 'https://bolt.eu/marketing.pdf']),
            'https://bolt.eu/marketing.pdf' => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ]);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('does not match linkHrefRegex', $result->note);
        $this->assertSame(PreprocessFailureCode::RULE_CONFIG, $result->code);
    }

    public function testPrivateAddressIsBlockedBeforeAnyRequest(): void
    {
        foreach (['10.0.0.5', '127.0.0.1', '192.168.1.1', '169.254.169.254', '[::1]'] as $host) {
            [$action, $http] = $this->action([]);
            $url = "http://{$host}/invoice.bolt.eu/x";

            $result = $action->execute($this->message('<a href="' . $url . '">x</a>'), 'r', $this->params());

            $this->assertFalse($result->ok, $host);
            $this->assertStringContainsString('public address', $result->note, $host);
            $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code, $host);
            $this->assertSame([], $http->requests, $host);
        }
    }

    public function testHostnameResolvingToPrivateRangeIsBlockedPerHop(): void
    {
        // Wrapper je veřejný, ale redirect vede na hostname, který se přeloží
        // do privátního rozsahu — druhý hop se nesmí odeslat.
        $resolver = static fn(string $host): array => $host === 'internal.bolt.eu' ? ['10.1.2.3'] : ['93.184.216.34'];
        [$action, $http] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => 'https://internal.bolt.eu/invoice.bolt.eu']),
        ], null, $resolver);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('internal.bolt.eu', $result->note);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code);
        $this->assertCount(1, $http->requests);
    }

    public function testUnresolvableHostFails(): void
    {
        [$action, $http] = $this->action([], null, static fn(string $host): array => []);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code);
        $this->assertSame([], $http->requests);
    }

    public function testNonHttpSchemeIsRejected(): void
    {
        [$action, $http] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => 'file:///etc/passwd?invoice.bolt.eu']),
        ]);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('non-http', $result->note);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code); // U1: odkaz existoval, hop vede jinam
        $this->assertCount(1, $http->requests);
    }

    public function testTooManyRedirectsFails(): void
    {
        $responses = [self::WRAPPER => new HttpResponse(302, ['location' => 'https://invoice.bolt.eu/r/0'])];
        for ($i = 0; $i < 10; $i++) {
            $responses["https://invoice.bolt.eu/r/{$i}"] = new HttpResponse(302, ['location' => 'https://invoice.bolt.eu/r/' . ($i + 1)]);
        }
        [$action, $http] = $this->action($responses);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('too many redirects', $result->note);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code);
        $this->assertCount(FetchLinkedDocumentAction::MAX_REDIRECTS + 1, $http->requests);
    }

    // --- obsah -----------------------------------------------------------------

    public function testSizeCapIsReportedAsFailure(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], '', null, true),
        ]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('size cap', $result->note);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code);
        $this->assertSame([], $this->uploads);
    }

    // --- renderIfHtml (D17) --------------------------------------------------------

    private const HTML_RESPONSE_HEADERS = ['content-type' => 'text/html; charset=utf-8'];

    public function testHtmlDocumentWithoutFlagFailsWithHint(): void
    {
        [$render, $engine] = $this->renderClient();
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<html>invoice</html>'),
        ], null, null, $render);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('renderIfHtml', $result->note);
        $this->assertSame(PreprocessFailureCode::RULE_CONFIG, $result->code);
        $this->assertSame([], $engine->renders, 'bez flagu se render nevolá');
        $this->assertSame([], $this->uploads);
    }

    public function testHtmlDocumentWithFlagIsRenderedAndStoredAsPdf(): void
    {
        [$render, $engine] = $this->renderClient();
        [$action] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => self::FINAL]),
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<div>Faktura č. 1</div>'),
        ], null, null, $render);

        $result = $action->execute($this->message(), 'bolt-invoice-link', $this->params(['renderIfHtml' => true]));

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame([77], $result->attachmentIds);
        $this->assertCount(1, $engine->renders);
        $this->assertStringContainsString('<meta charset="utf-8">', $engine->renders[0]['html']);
        $this->assertStringContainsString('Faktura č. 1', $engine->renders[0]['html']);
        $this->assertSame([], $engine->renders[0]['assets']);

        $this->assertSame('abc.pdf', $this->uploads[0]['name'], 'název z URL, vynucená přípona .pdf');
        $extra = $this->merged[0]['extra'];
        $this->assertTrue($extra['rendered']);
        $this->assertSame(self::WRAPPER, $extra['sourceUrl']);
        $this->assertSame(self::FINAL, $extra['finalUrl']);
        $this->assertSame('fetchLinkedDocument', $extra['action']);
    }

    public function testRenderFailureIsCandidateNoteAndNextCandidateIsTried(): void
    {
        [$render, $engine] = $this->renderClient(RenderResult::failure(RenderErrorKind::Unreachable, 'connection refused'));
        $html = '<a href="https://invoice.bolt.eu/page">page</a> <a href="' . self::FINAL . '">pdf</a>';
        [$action] = $this->action([
            'https://invoice.bolt.eu/page' => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<p>x</p>'),
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ], null, null, $render);

        $result = $action->execute($this->message($html), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertTrue($result->ok, $result->note);
        $this->assertCount(1, $engine->renders, 'render se zkusil jen u HTML kandidáta');
        $this->assertArrayNotHasKey('rendered', $this->merged[0]['extra'], 'PDF kandidát není renderovaný');
    }

    public function testRenderFailureOnOnlyCandidateIsReportedWithKind(): void
    {
        [$render] = $this->renderClient(RenderResult::failure(RenderErrorKind::Timeout, 'exceeded'));
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<p>x</p>'),
        ], null, null, $render);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('render failed: timeout: exceeded', $result->note);
        $this->assertSame([], $this->uploads);
    }

    public function testFinalPdfDoesNotInvokeRenderEvenWithFlag(): void
    {
        [$render, $engine] = $this->renderClient();
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ], null, null, $render);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertTrue($result->ok, $result->note);
        $this->assertSame([], $engine->renders);
        $this->assertArrayNotHasKey('rendered', $this->merged[0]['extra']);
    }

    public function testEmptyHtmlBodyWithFlagFails(): void
    {
        [$render, $engine] = $this->renderClient();
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, "  
"),
        ], null, null, $render);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('empty HTML body', $result->note);
        $this->assertSame([], $engine->renders);
    }

    public function testFlagWithoutRenderClientFailsWithNote(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<p>x</p>'),
        ]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('no render client', $result->note);
    }

    public function testUnconfiguredRenderClientWithFlagFailsWithoutException(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<p>x</p>'),
        ], null, null, new RenderClient(null));

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('unconfigured', $result->note);
    }

    public function testUnsupportedContentTypeFails(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, ['content-type' => 'image/png'], "\x89PNG"),
        ]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString("unsupported content-type 'image/png'", $result->note);
    }

    public function testExpiredLinkIsFailureNotException(): void
    {
        [$action] = $this->action([
            self::WRAPPER => new HttpResponse(302, ['location' => self::FINAL]),
            self::FINAL => new HttpResponse(410),
        ]);

        $result = $action->execute($this->message(), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('HTTP 410', $result->note);
    }

    public function testTransportErrorIsFailure(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(0, [], '', 'Operation timed out after 20000 ms'),
        ]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('timed out', $result->note);
    }

    public function testUploadFailureIsReported(): void
    {
        [$action] = $this->action([
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ], $this->attachments([], false));

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('disk full', $result->note);
        $this->assertFileDoesNotExist($this->uploads[0]['tmp'], 'temp soubor po selhání uklizen');
    }

    public function testSecondCandidateIsTriedWhenFirstFails(): void
    {
        $html = '<a href="https://invoice.bolt.eu/old">old</a> <a href="' . self::FINAL . '">new</a>';
        [$action] = $this->action([
            'https://invoice.bolt.eu/old' => new HttpResponse(404),
            self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF),
        ]);

        $result = $action->execute($this->message($html), 'r', $this->params());

        $this->assertTrue($result->ok, $result->note);
    }

    // --- parametry a kandidáti --------------------------------------------------

    public function testMissingParamsFail(): void
    {
        [$action, $http] = $this->action([]);

        $this->assertStringContainsString('linkHrefRegex', $action->execute($this->message(), 'r', $this->params(['linkHrefRegex' => '']))->note);
        $this->assertStringContainsString('allowedDomains', $action->execute($this->message(), 'r', $this->params(['allowedDomains' => []]))->note);
        $this->assertStringContainsString('not a valid regex', $action->execute($this->message(), 'r', $this->params(['linkHrefRegex' => '(']))->note);
        $this->assertSame([], $http->requests);
    }

    public function testNoCandidateLinkFails(): void
    {
        [$action, $http] = $this->action([]);

        $result = $action->execute($this->message('<p>no links here</p>', 'plain text'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('no link matching', $result->note);
        $this->assertSame([], $http->requests);
    }

    public function testExtractCandidateUrlsDecodesEntitiesDedupesAndReadsPlainText(): void
    {
        $html = '<a href="https://invoice.bolt.eu/a?x=1&amp;y=2">a</a>'
            . '<a href=\'https://invoice.bolt.eu/a?x=1&amp;y=2\'>dup</a>'
            . '<a href="https://other.example/">no</a>'
            . '<a href="' . self::WRAPPER . '">wrapped</a>';
        $plain = "Text https://invoice.bolt.eu/plain. And mailto:x@y.example";

        $urls = FetchLinkedDocumentAction::extractCandidateUrls($html, $plain, 'invoice\.bolt\.eu');

        $this->assertSame([
            'https://invoice.bolt.eu/a?x=1&y=2',
            self::WRAPPER,
            'https://invoice.bolt.eu/plain',
        ], $urls);
    }

    public function testHelpers(): void
    {
        $this->assertSame(['bolt.eu', 'example.com'], FetchLinkedDocumentAction::normalizeDomains(['Bolt.eu', ' example.com. ', 'bolt.eu']));
        $this->assertSame(['bolt.eu', 'a.example'], FetchLinkedDocumentAction::normalizeDomains('bolt.eu, a.example'));
        $this->assertTrue(FetchLinkedDocumentAction::hostAllowed('invoice.bolt.eu', ['bolt.eu']));
        $this->assertFalse(FetchLinkedDocumentAction::hostAllowed('notbolt.eu', ['bolt.eu']));
        $this->assertSame('https://a.example/x/y', FetchLinkedDocumentAction::resolveRelative('https://a.example/x/z', 'y'));
        $this->assertSame('https://a.example/y', FetchLinkedDocumentAction::resolveRelative('https://a.example/x/z', '/y'));
        $this->assertSame('https://b.example/q', FetchLinkedDocumentAction::resolveRelative('https://a.example/x', '//b.example/q'));
        $this->assertSame('faktura č. 1.pdf', FetchLinkedDocumentAction::fileNameFor("attachment; filename*=UTF-8''faktura%20%C4%8D.%201.pdf", 'https://x/y'));
        $this->assertSame('download.pdf', FetchLinkedDocumentAction::fileNameFor('', 'https://x/download?id=1'));
        $this->assertSame('document.pdf', FetchLinkedDocumentAction::fileNameFor('', 'https://x/'));
        $this->assertSame('document.pdf', FetchLinkedDocumentAction::fileNameFor('', 'https://x/' . str_repeat('a', 80)));
        $this->assertSame('a_b.pdf', FetchLinkedDocumentAction::fileNameFor('attachment; filename="a/b"', 'https://x/y'));
    }

    // --- kódy selhání (tasks/mail-preprocess-error-messages.md D1) ----------

    public function testMissingOrInvalidParametersAreRuleConfig(): void
    {
        [$action, $http] = $this->action([]);

        foreach ([['linkHrefRegex' => ''], ['linkHrefRegex' => '('], ['allowedDomains' => []]] as $override) {
            $result = $action->execute($this->message(), 'r', $this->params($override));
            $this->assertFalse($result->ok);
            $this->assertSame(PreprocessFailureCode::RULE_CONFIG, $result->code, json_encode($override));
        }
        $this->assertSame([], $http->requests);
    }

    public function testNoMatchingLinkIsLinkNotFound(): void
    {
        [$action, $http] = $this->action([]);

        $result = $action->execute($this->message('<p>no links here</p>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::LINK_NOT_FOUND, $result->code);
        $this->assertSame([], $http->requests);
    }

    public function testHttpStatusMapsToLinkExpiredOrRemoteUnavailable(): void
    {
        $expected = [
            403 => PreprocessFailureCode::LINK_EXPIRED,
            404 => PreprocessFailureCode::LINK_EXPIRED,
            410 => PreprocessFailureCode::LINK_EXPIRED,
            408 => PreprocessFailureCode::REMOTE_UNAVAILABLE,
            429 => PreprocessFailureCode::REMOTE_UNAVAILABLE,
            500 => PreprocessFailureCode::REMOTE_UNAVAILABLE,
            503 => PreprocessFailureCode::REMOTE_UNAVAILABLE,
        ];
        foreach ($expected as $status => $code) {
            [$action] = $this->action([self::FINAL => new HttpResponse($status)]);

            $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

            $this->assertFalse($result->ok, (string) $status);
            $this->assertSame($code, $result->code, (string) $status);
            $this->assertStringContainsString("HTTP {$status}", $result->note);
        }
    }

    public function testTransportErrorIsRemoteUnavailable(): void
    {
        [$action] = $this->action([self::FINAL => new HttpResponse(0, [], '', 'timeout after 20 s')]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::REMOTE_UNAVAILABLE, $result->code);
        $this->assertStringContainsString('timeout after 20 s', $result->note);
    }

    public function testUnsupportedContentTypeIsUnexpectedContent(): void
    {
        [$action] = $this->action([self::FINAL => new HttpResponse(200, ['content-type' => 'image/png'], 'PNG')]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::UNEXPECTED_CONTENT, $result->code);
    }

    public function testHtmlWithoutRenderClientIsInternal(): void
    {
        [$action] = $this->action([self::FINAL => new HttpResponse(200, self::HTML_RESPONSE_HEADERS, '<html>x</html>')]);

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params(['renderIfHtml' => true]));

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $result->code);
    }

    public function testUploadFailureIsInternal(): void
    {
        [$action] = $this->action(
            [self::FINAL => new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF)],
            $this->attachments([], false),
        );

        $result = $action->execute($this->message('<a href="' . self::FINAL . '">x</a>'), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $result->code);
        $this->assertStringContainsString('disk full', $result->note);
    }

    public function testMostSpecificCodeWinsAcrossCandidates(): void
    {
        // Kandidát 1 transportní chyba (remoteUnavailable), kandidát 2
        // HTTP 404 (linkExpired) → vyhrává konkrétnější linkExpired,
        // poznámka nese oba pokusy.
        $html = '<a href="https://invoice.bolt.eu/a">a</a> <a href="https://invoice.bolt.eu/b">b</a>';
        [$action, $http] = $this->action([
            'https://invoice.bolt.eu/a' => new HttpResponse(0, [], '', 'connection reset'),
            'https://invoice.bolt.eu/b' => new HttpResponse(404),
        ]);

        $result = $action->execute($this->message($html), 'r', $this->params());

        $this->assertFalse($result->ok);
        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, $result->code);
        $this->assertStringContainsString('connection reset', $result->note);
        $this->assertStringContainsString('HTTP 404', $result->note);
        $this->assertCount(2, $http->requests);
    }

    public function testCodeForHttpStatusBoundaries(): void
    {
        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, FetchLinkedDocumentAction::codeForHttpStatus(400));
        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, FetchLinkedDocumentAction::codeForHttpStatus(499));
        $this->assertSame(PreprocessFailureCode::REMOTE_UNAVAILABLE, FetchLinkedDocumentAction::codeForHttpStatus(408));
        $this->assertSame(PreprocessFailureCode::REMOTE_UNAVAILABLE, FetchLinkedDocumentAction::codeForHttpStatus(429));
        $this->assertSame(PreprocessFailureCode::REMOTE_UNAVAILABLE, FetchLinkedDocumentAction::codeForHttpStatus(500));
        $this->assertSame(PreprocessFailureCode::REMOTE_UNAVAILABLE, FetchLinkedDocumentAction::codeForHttpStatus(599));
    }
}
