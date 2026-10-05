<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\PrintsController;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\ReadOnlyPolicy;
use Shipard\Api\ReadOnlyVerdict;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\SendDraft;
use Shipard\Module\Core\Mail\Sent\SendRequest;
use Shipard\Module\Core\Mail\Sent\SendResult;
use Shipard\Tests\Unit\Core\Prints\PrintRunnerTest;

/**
 * REST odeslání záznamu e-mailem (#90 D38): routy, klasifikace pro read-only
 * zdroj dat, `PrintsController::sendDraft()` / `send()` (práva, tělo
 * požadavku, mapování chyb) a háček `ViewerController::detail()` — akce
 * Odeslat a sekce Odeslaná pošta.
 */
class PrintSendApiTest extends TestCase
{
    private const PRINT_ID = 'test.prints.card';
    private const RECORD   = ['id' => 5, 'kind' => 'a', 'docState' => 40, 'name' => 'Záznam', 'partner' => 12];

    private const LANGUAGES = [['id' => 'cs', 'label' => 'čeština'], ['id' => 'en', 'label' => 'English']];

    /** @param array<string, mixed> $overrides */
    private static function definition(array $overrides = []): PrintDefinition
    {
        return PrintDefinition::fromArray($overrides + [
            'id'              => self::PRINT_ID,
            'name'            => 'Karta',
            'table'           => 'test_prints_records',
            'filter'          => ['kind' => ['a']],
            'docStates'       => [40],
            'builder'         => PrintsApiFakeBuilder::class,
            'template'        => '@test.prints/sample',
            'order'           => 10,
            'sendPurpose'     => 'invoices',
            'recipientPerson' => 'partner',
        ], 'test.prints');
    }

    private static function registry(PrintDefinition ...$definitions): PrintRegistry
    {
        $registry = new PrintRegistry();
        foreach ($definitions as $definition) {
            $registry->add($definition);
        }
        return $registry;
    }

    private function controller(?RecordSendService $service, ?PrintRegistry $registry = null): PrintsController
    {
        $registry ??= self::registry(self::definition());

        return new PrintsController(
            $registry,
            new PrintRunner($registry, null, static fn (string $language) => null, PrintRunnerTest::languages()),
            $service === null ? null : static fn (): RecordSendService => $service,
            self::LANGUAGES,
        );
    }

    private function draft(): SendDraft
    {
        return new SendDraft(
            printId: self::PRINT_ID,
            recordId: 5,
            table: 'test_prints_records',
            language: 'cs',
            purpose: 'invoices',
            targetLabel: 'Karta 5',
            fileName: 'karta-5.pdf',
            recipientPerson: ['id' => 12, 'name' => 'Odběratel s.r.o.'],
            to: [['email' => 'ucetni@odberatel.example', 'name' => 'Účtárna', 'source' => 'contact', 'label' => 'Kontakt Účtárna']],
            cc: [],
            from: ['email' => 'fakturace@firma.example', 'name' => 'Naše firma s.r.o.', 'source' => 'default'],
            allowedSenders: [['email' => 'fakturace@firma.example', 'source' => 'default']],
            subject: 'Karta 5 — Naše firma s.r.o.',
            body: "Dobrý den,\n",
            attachments: [],
            mergeAttachments: false,
            messages: [],
        );
    }

    private static function user(): AuthContext
    {
        return new AuthContext(true, 2, 'session', 'shpd_st_y');
    }

    private static function statusOf(Response $response): int
    {
        return (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }

    private static function assertError(Response $response, int $status, string $code): void
    {
        self::assertSame($status, self::statusOf($response));
        self::assertSame($code, $response->getPayload()['error']['code']);
    }

    // ── routy + read-only ───────────────────────────────────────────────────

    public function testRouterResolvesSendDraftAndSend(): void
    {
        $router = new Router();

        $draft = $router->resolve('/api/v1/_prints/docs.invoicesOut.invoice/123/send-draft', 'GET');
        $this->assertInstanceOf(Route::class, $draft);
        $this->assertSame(['prints', 'sendDraft', 'docs.invoicesOut.invoice', 123], [$draft->controller, $draft->action, $draft->table, $draft->id]);

        $send = $router->resolve('/api/v1/_prints/docs.invoicesOut.invoice/123/send', 'POST');
        $this->assertInstanceOf(Route::class, $send);
        $this->assertSame(['prints', 'send', 'docs.invoicesOut.invoice', 123], [$send->controller, $send->action, $send->table, $send->id]);
    }

    public function testRouterRejectsWrongMethods(): void
    {
        $router = new Router();

        foreach ([
            ['/api/v1/_prints/docs.invoicesOut.invoice/123/send-draft', 'POST'],
            ['/api/v1/_prints/docs.invoicesOut.invoice/123/send', 'GET'],
        ] as [$path, $method]) {
            $response = $router->resolve($path, $method);
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(405, self::statusOf($response), "{$method} {$path}");
        }
        $this->assertSame(404, self::statusOf($router->resolve('/api/v1/_prints/docs.invoicesOut.invoice/123/sendx', 'POST')));
    }

    public function testReadOnlyDataSourceAllowsDraftButRefusesSend(): void
    {
        $policy = new ReadOnlyPolicy();

        $this->assertSame(ReadOnlyVerdict::Allow, $policy->verdict(new Route('prints', 'run')));
        $this->assertSame(ReadOnlyVerdict::Allow, $policy->verdict(new Route('prints', 'sendDraft')));
        // Odeslání vytváří zprávu a řádek fronty — na read-only zdroji 403.
        $this->assertSame(ReadOnlyVerdict::Deny403, $policy->verdict(new Route('prints', 'send')));
    }

    // ── send-draft ──────────────────────────────────────────────────────────

    public function testDraftCarriesProposalAndPrintLanguages(): void
    {
        $captured = null;
        $service  = $this->createMock(RecordSendService::class);
        $service->method('prepare')->willReturnCallback(function (SendRequest $request) use (&$captured): SendDraft {
            $captured = $request;
            return $this->draft();
        });
        $service->expects($this->never())->method('send');

        $response = $this->controller($service)->sendDraft(self::PRINT_ID, 5, ['language' => 'en'], self::user(), []);
        $data     = $response->getPayload()['data'];

        $this->assertSame(200, self::statusOf($response));
        $this->assertSame('ucetni@odberatel.example', $data['to'][0]['email']);
        $this->assertSame('fakturace@firma.example', $data['from']['email']);
        $this->assertTrue($data['canSend']);
        $this->assertSame(self::LANGUAGES, $data['languages']);
        $this->assertSame([5, 'en', 2], [$captured->recordId, $captured->language, $captured->userId]);
        $this->assertNull($captured->to, 'návrh hledá příjemce sám');
    }

    public function testDraftErrors(): void
    {
        $service = $this->createMock(RecordSendService::class);

        self::assertError($this->controller($service)->sendDraft('test.prints.none', 5, [], self::user(), []), 404, 'PRINT_NOT_FOUND');
        // Zdroj dat bez Odeslané pošty odesílat neumí.
        self::assertError($this->controller(null)->sendDraft(self::PRINT_ID, 5, [], self::user(), []), 409, 'PRINT_NOT_SENDABLE');

        foreach ([
            [new RecordSendException(RecordSendException::PRINT_NOT_SENDABLE, 'no purpose'), 409, 'PRINT_NOT_SENDABLE'],
            [new PrintNotAvailableException('draft'), 409, 'PRINT_NOT_AVAILABLE'],
            [new PrintRecordNotFoundException('missing'), 404, 'RECORD_NOT_FOUND'],
            [new \InvalidArgumentException("Parameter 'language' must be one of cs|en"), 400, 'BAD_REQUEST'],
        ] as [$exception, $status, $code]) {
            $service = $this->createMock(RecordSendService::class);
            $service->method('prepare')->willThrowException($exception);

            self::assertError($this->controller($service)->sendDraft(self::PRINT_ID, 5, [], self::user(), []), $status, $code);
        }
    }

    public function testDraftAndSendFollowTableAccessRights(): void
    {
        $service = $this->createMock(RecordSendService::class);
        $service->expects($this->never())->method('prepare');
        $service->expects($this->never())->method('send');

        $tables = ['test_prints_records' => TableDefinition::fromArray([
            'tableId'   => 9001,
            'name'      => 'Records',
            'adminOnly' => true,
            'columns'   => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ])];

        self::assertError($this->controller($service)->sendDraft(self::PRINT_ID, 5, [], self::user(), $tables), 403, 'FORBIDDEN_ADMIN_ONLY');
        self::assertError($this->controller($service)->send(self::PRINT_ID, 5, [], self::user(), $tables), 403, 'FORBIDDEN_ADMIN_ONLY');
    }

    // ── send ────────────────────────────────────────────────────────────────

    public function testSendPassesDialogValuesAndReturnsTransportState(): void
    {
        $captured = null;
        $service  = $this->createMock(RecordSendService::class);
        $service->method('send')->willReturnCallback(function (SendRequest $request) use (&$captured): SendResult {
            $captured = $request;
            return new SendResult(701, 31, 'queued', [
                ['severity' => 'warning', 'code' => 'builder.note', 'text' => 'Poznámka builderu'],
            ]);
        });

        $response = $this->controller($service)->send(self::PRINT_ID, 5, [
            'from'          => 'ucet@firma.example',
            'to'            => ['ucetni@odberatel.example', 'jana@odberatel.example'],
            'cc'            => ['kopie@firma.example'],
            'subject'       => 'Vlastní předmět',
            'body'          => 'Vlastní text.',
            'language'      => 'en',
            'attachmentIds' => [81, 82],
        ], self::user(), []);

        $this->assertSame(200, self::statusOf($response));
        $this->assertSame(
            [
                'sentMessageId'  => 701,
                'transportState' => 'queued',
                'safety'         => null,
                'messages'       => [['severity' => 'warning', 'code' => 'builder.note', 'text' => 'Poznámka builderu']],
            ],
            $response->getPayload()['data'],
        );

        $this->assertSame('ucet@firma.example', $captured->from);
        $this->assertSame(['ucetni@odberatel.example', 'jana@odberatel.example'], $captured->to);
        $this->assertSame(['kopie@firma.example'], $captured->cc);
        $this->assertSame('Vlastní předmět', $captured->subject);
        $this->assertSame('Vlastní text.', $captured->body);
        $this->assertSame('en', $captured->language);
        $this->assertSame([81, 82], $captured->attachmentIds);
        $this->assertSame(2, $captured->userId);
        $this->assertSame(SendRequest::TRIGGER_MANUAL, $captured->trigger);
    }

    public function testSendWithEmptyBodyLeavesEverythingToTheService(): void
    {
        $captured = null;
        $service  = $this->createMock(RecordSendService::class);
        $service->method('send')->willReturnCallback(function (SendRequest $request) use (&$captured): SendResult {
            $captured = $request;
            return new SendResult(701, 31, 'sent');
        });

        $this->controller($service)->send(self::PRINT_ID, 5, null, self::user(), []);

        $this->assertNull($captured->to);
        $this->assertNull($captured->from);
        $this->assertNull($captured->subject);
        $this->assertNull($captured->attachmentIds);
    }

    public function testSendDomainErrorsAreUnprocessable(): void
    {
        foreach ([
            RecordSendException::NO_RECIPIENT,
            RecordSendException::NO_SENDER,
            RecordSendException::SENDER_NOT_ALLOWED,
            RecordSendException::INVALID_EMAIL,
            RecordSendException::INVALID_ATTACHMENT,
            RecordSendException::EMPTY_MESSAGE,
        ] as $code) {
            $service = $this->createMock(RecordSendService::class);
            $service->method('send')->willThrowException(new RecordSendException($code, 'nope'));

            self::assertError($this->controller($service)->send(self::PRINT_ID, 5, [], self::user(), []), 422, $code);
        }
    }

    public function testSendRenderErrorsMapLikePrint(): void
    {
        foreach ([[RenderErrorKind::Unreachable, 503, 'RENDER_UNAVAILABLE'], [RenderErrorKind::EngineError, 500, 'RENDER_FAILED']] as [$kind, $status, $code]) {
            $service = $this->createMock(RecordSendService::class);
            $service->method('send')->willThrowException(new PrintRenderException($kind, 'render'));

            self::assertError($this->controller($service)->send(self::PRINT_ID, 5, [], self::user(), []), $status, $code);
        }
    }

    public function testSendRejectsMalformedBody(): void
    {
        $service = $this->createMock(RecordSendService::class);
        $service->expects($this->never())->method('send');

        foreach ([
            ['to' => 'ucetni@odberatel.example'],
            ['to' => [12]],
            ['cc' => 'kopie@firma.example'],
            ['subject' => ['x']],
            ['attachmentIds' => ['81']],
            ['attachmentIds' => 81],
        ] as $body) {
            self::assertError($this->controller($service)->send(self::PRINT_ID, 5, $body, self::user(), []), 400, 'BAD_REQUEST');
        }
    }

    // ── háček detailu: akce Odeslat a sekce Odeslaná pošta ──────────────────

    /** @var list<array{string, list<mixed>}> Dotazy `fetchAll` háčku detailu. */
    private array $queries = [];

    /**
     * @param list<array<string, mixed>> $messages Zprávy, které vrátí dotaz na Odeslanou poštu.
     * @return array<string, mixed> `detail` z odpovědi
     */
    private function detail(?PrintRegistry $prints, bool $withSentMail = true, array $messages = []): array
    {
        $viewers = new ViewerRegistry();
        $viewers->register(new ViewerDefinition(
            id: 'test.prints.records',
            name: 'Records',
            table: 'test_prints_records',
            class: PrintsApiPlainViewer::class,
            moduleId: 'test.prints',
            icon: null,
        ));

        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(self::RECORD);
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) use ($messages): array {
            $this->queries[] = [$sql, $args];
            return str_contains($sql, 'core_attachments_files')
                ? [['id' => 101, 'name' => 'karta-5.pdf', 'file_name' => 'k.pdf', 'file_size' => 2048, 'mime_type' => 'application/pdf']]
                : $messages;
        });

        $tables = $withSentMail
            ? ['core_mail_sent_messages' => TableDefinition::fromArray([
                'tableId' => 455,
                'name'    => 'Sent messages',
                'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
            ])]
            : [];

        $response = (new ViewerController())->detail(
            'test.prints.records', 5, self::user(), $viewers, $tables, $db, null, 'cs', prints: $prints,
        );
        $this->assertSame(200, self::statusOf($response));
        return $response->getPayload()['data']['detail'];
    }

    public function testDetailOffersSendForSendablePrintOnly(): void
    {
        $detail = $this->detail(self::registry(
            self::definition(),
            // Interní tisk jde vytisknout, odeslat ne.
            self::definition(['id' => 'test.prints.journal', 'name' => 'Kontace', 'audience' => 'internal',
                'sendPurpose' => null, 'recipientPerson' => null, 'order' => 20]),
        ));

        $actions = array_column($detail['actions'], null, 'id');
        $this->assertSame('dropdown', $actions['print']['kind']);
        $this->assertSame('button', $actions['send']['kind']);
        $this->assertSame(self::PRINT_ID, $actions['send']['target']['printId']);
        $this->assertSame('Send', $actions['send']['label']);
        $this->assertSame(['print', 'send'], array_column($detail['actions'], 'id'), 'Odeslat je hned za Tiskem');
    }

    public function testDetailWithoutSentMailModuleHasNoSendActionNorSection(): void
    {
        $detail = $this->detail(self::registry(self::definition()), withSentMail: false);

        $this->assertSame(['print'], array_column($detail['actions'], 'id'));
        $this->assertArrayNotHasKey('sentMessages', $detail);
        $this->assertSame([], $this->queries, 'bez tabulky Odeslané pošty se na zprávy neptá');
    }

    public function testDetailListsSentMessagesOfTheRecord(): void
    {
        $detail = $this->detail(self::registry(self::definition()), messages: [[
            'id'              => 7,
            'subject'         => 'Karta 5 — Naše firma s.r.o.',
            'email_to'        => 'ucetni@odberatel.example, jana@odberatel.example',
            'created'         => '2026-10-04 14:30:00',
            'transport_state' => 'sent',
            'docState'        => 40,
        ]]);

        $this->assertSame([[
            'id'          => 7,
            'createdAt'   => '04.10.2026 14:30',
            'to'          => ['ucetni@odberatel.example', 'jana@odberatel.example'],
            'subject'     => 'Karta 5 — Naše firma s.r.o.',
            'transport'   => ['state' => 'sent', 'stateLabel' => 'sent', 'stateStyle' => 'neutral', 'safety' => null],
            'attachments' => [['id' => 101, 'name' => 'karta-5.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048]],
        ]], $detail['sentMessages']);

        // U záznamu jen zprávy ve stavu Odeslaná — archivované a smazané ne.
        [$sql, $args] = $this->queries[0];
        $this->assertStringContainsString('[docState] = %i', $sql);
        $this->assertSame(['core_mail_sent_messages', 'test_prints_records', 5, 40], $args);
    }

    public function testDetailSurvivesDataSourceWithoutSentMailTable(): void
    {
        // Kód je nasazený, zdroj dat ještě neprošel `ds-upgrade`: tabulka
        // v definicích je, v databázi ne.
        $viewers = new ViewerRegistry();
        $viewers->register(new ViewerDefinition(
            id: 'test.prints.records',
            name: 'Records',
            table: 'test_prints_records',
            class: PrintsApiPlainViewer::class,
            moduleId: 'test.prints',
            icon: null,
        ));
        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(self::RECORD);
        $db->method('fetchAll')->willThrowException(
            new \Dibi\DriverException("Table 'core_mail_sent_messages' doesn't exist"),
        );
        $tables = ['core_mail_sent_messages' => TableDefinition::fromArray([
            'tableId' => 455,
            'name'    => 'Sent messages',
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ])];

        $response = (new ViewerController())->detail(
            'test.prints.records', 5, self::user(), $viewers, $tables, $db, null, 'cs',
            prints: self::registry(self::definition()),
        );

        $this->assertSame(200, self::statusOf($response));
        $this->assertArrayNotHasKey('sentMessages', $response->getPayload()['data']['detail']);
    }

    public function testDetailWithoutMessagesHasNoSection(): void
    {
        $detail = $this->detail(self::registry(self::definition()), messages: []);

        $this->assertArrayNotHasKey('sentMessages', $detail);
    }
}
