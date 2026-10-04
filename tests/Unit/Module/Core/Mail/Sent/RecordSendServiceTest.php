<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Mail\SenderResolution;
use Shipard\Core\Mail\SenderResolver;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Module\Base\Persons\Send\Recipient;
use Shipard\Module\Base\Persons\Send\RecipientResolution;
use Shipard\Module\Base\Persons\Send\RecipientResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\SendRequest;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Tests\Unit\Core\Prints\PrintDefinitionTest;

/**
 * Služba odeslání záznamu (#90 D42, D44) s falešnými závislostmi: návrh
 * nic nemění; odeslání vytvoří zprávu, její přílohy a řádek fronty v jedné
 * transakci a na záznamu nic; chyba nic nezanechá.
 */
class RecordSendServiceTest extends TestCase
{
    private const PRINT_ID  = 'docs.invoicesOut.invoice';
    private const RECORD_ID = 55;
    private const PDF       = '%PDF-1.4 print';

    private DataSourceConnection&MockObject $db;
    private \Dibi\Connection&MockObject $dibi;
    private RecipientResolver&MockObject $recipients;
    private SenderResolver&MockObject $senders;
    private AttachmentService&MockObject $attachments;
    private RenderClient&MockObject $renderClient;
    private SentMessageStore&MockObject $store;
    private SentMessageTransport&MockObject $transport;

    /** @var array<string, mixed> Řádek odesílaného záznamu. */
    private array $record = ['id' => self::RECORD_ID, 'doc_type' => 'invno', 'docState' => 40, 'partner' => 12];
    /** @var array<string, mixed>|null Osoba příjemce. */
    private ?array $person = ['id' => 12, 'full_name' => 'Odběratel s.r.o.', 'send_attachments_merged' => 0];
    /** @var list<array<string, mixed>> Přílohy záznamu. */
    private array $recordAttachments = [];

    /** @var list<array{string, int, PrintFormat, ?string}> Běhy tisku. */
    private array $printRuns = [];
    private ?\Throwable $printFails = null;

    /** @var list<array<string, mixed>> Založené zprávy. */
    private array $createdMessages = [];
    /** @var list<array{int, int, string}> Nahrané přílohy [tableId, recordId, název]. */
    private array $uploads = [];
    /** @var list<array{int, int, int}> Zkopírované přílohy [id, tableId, recordId]. */
    private array $copies = [];
    /** @var list<string> Pořadí kroků transakce a transportu. */
    private array $log = [];
    /** @var list<string> Dočasné soubory „příloh“ na disku. */
    private array $files = [];

    protected function setUp(): void
    {
        $this->dibi = $this->createMock(\Dibi\Connection::class);
        $this->dibi->method('fetchSingle')->willReturn(0);
        $this->dibi->method('begin')->willReturnCallback(function (): void { $this->log[] = 'begin'; });
        $this->dibi->method('commit')->willReturnCallback(function (): void { $this->log[] = 'commit'; });
        $this->dibi->method('rollback')->willReturnCallback(function (): void { $this->log[] = 'rollback'; });

        $this->db = $this->createMock(DataSourceConnection::class);
        $this->db->method('getDibiConnection')->willReturn($this->dibi);
        $this->db->method('fetchRow')->willReturnCallback(
            fn (string $sql): ?array => str_contains($sql, 'base_persons_persons') ? $this->person : $this->record,
        );
        $this->db->method('fetchAll')->willReturnCallback(fn (): array => $this->recordAttachments);

        $this->recipients = $this->createMock(RecipientResolver::class);
        $this->recipients->method('resolve')->willReturn(new RecipientResolution([
            new Recipient('ucetni@odberatel.example', 'Účtárna', Recipient::SOURCE_CONTACT, 'Kontakt Účtárna — Faktury', 31),
            new Recipient('jana@odberatel.example', 'Jana', Recipient::SOURCE_CONTACT, 'Kontakt Jana — Faktury', 32),
        ]));

        $allowed = $this->createMock(AllowedSenders::class);
        $allowed->method('addresses')->willReturn([['email' => 'fakturace@firma.example', 'source' => 'default']]);
        $this->senders = $this->createMock(SenderResolver::class);
        $this->senders->method('allowed')->willReturn($allowed);
        $this->senders->method('resolve')->willReturn(
            new SenderResolution('fakturace@firma.example', 'Naše firma s.r.o.', SenderResolution::SOURCE_DEFAULT),
        );

        $this->attachments = $this->createMock(AttachmentService::class);
        $this->attachments->method('upload')->willReturnCallback(
            function (int $tableId, int $recordId, string $name, string $tmpPath): array {
                $this->log[]     = 'upload';
                $this->uploads[] = [$tableId, $recordId, $name, (string) file_get_contents($tmpPath)];
                return ['success' => true, 'data' => $this->storedFile($name)];
            },
        );
        $this->attachments->method('copyTo')->willReturnCallback(
            function (int $attachmentId, int $tableId, int $recordId): array {
                $this->log[]    = 'copy';
                $this->copies[] = [$attachmentId, $tableId, $recordId];
                return ['success' => true, 'data' => $this->storedFile("copy-{$attachmentId}")];
            },
        );
        $this->attachments->method('getFilePath')->willReturnCallback(
            static fn (array $attachment): string => (string) $attachment['file_path'],
        );

        $this->renderClient = $this->createMock(RenderClient::class);

        $this->store = $this->createMock(SentMessageStore::class);
        $this->store->method('create')->willReturnCallback(function (array $fields): int {
            $this->log[]             = 'create';
            $this->createdMessages[] = $fields;
            return 700 + count($this->createdMessages);
        });
        $this->store->method('get')->willReturn(['id' => 701, 'transport_state' => 'sent']);

        $this->transport = $this->createMock(SentMessageTransport::class);
        $this->transport->method('enqueue')->willReturnCallback(function (): int {
            $this->log[] = 'enqueue';
            return 31;
        });
        $this->transport->method('attempt')->willReturnCallback(function (): bool {
            $this->log[] = 'attempt';
            return true;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** Řádek přílohy, kterou „služba příloh“ uložila na disk. */
    private function storedFile(string $name): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'shpd-send-test-');
        file_put_contents($path, $name);
        $this->files[] = $path;
        return ['id' => 900 + count($this->files), 'name' => $name, 'file_path' => $path, 'file_name' => basename($path)];
    }

    private function service(): RecordSendService
    {
        $modules  = new ModulePathResolver([dirname(__DIR__, 6) . '/modules']);
        $paths    = new PrintTemplatePaths($modules);
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::declaration([
            'sendPurpose'     => 'invoices',
            'recipientPerson' => 'partner',
        ]), 'docs.invoicesOut'));
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::declaration([
            'id'       => 'economy.accounting.docJournal',
            'audience' => 'internal',
        ]), 'docs.invoicesOut'));

        $runPrint = function (string $printId, int $recordId, PrintFormat $format, ?string $language): PrintOutput {
            $this->printRuns[] = [$printId, $recordId, $format, $language];
            if ($this->printFails !== null) {
                throw $this->printFails;
            }
            $envelope = json_decode(
                (string) file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Prints/invoice.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $envelope['language'] = $language ?? 'cs';
            return new PrintOutput(
                $format,
                PrintData::fromArray($envelope),
                $format === PrintFormat::Pdf ? self::PDF : null,
            );
        };

        return new RecordSendService(
            $registry,
            $runPrint,
            new PrintCatalogLoader($paths),
            new PrintEmailRenderer($paths, new PrintTwigFactory($paths)),
            $this->recipients,
            $this->senders,
            $this->attachments,
            $this->renderClient,
            $this->store,
            $this->transport,
            $this->db,
            ['docs_core_heads' => TableDefinition::fromArray([
                'tableId' => 401,
                'name'    => 'Document heads',
                'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
            ])],
            null,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-04 14:30:00'),
        );
    }

    private function request(array $overrides = []): SendRequest
    {
        return new SendRequest(...($overrides + ['printId' => self::PRINT_ID, 'recordId' => self::RECORD_ID, 'userId' => 3]));
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame([], $this->createdMessages, 'žádná zpráva');
        $this->assertSame([], $this->uploads, 'žádná příloha');
        $this->assertNotContains('enqueue', $this->log, 'nic ve frontě');
        $this->assertNotContains('attempt', $this->log, 'žádný pokus o odeslání');
    }

    /** @return ?string kód chyby odeslání */
    private function sendErrorCode(SendRequest $request): ?string
    {
        try {
            $this->service()->send($request);
        } catch (RecordSendException $e) {
            return $e->errorCode;
        }
        return null;
    }

    // ── prepare ─────────────────────────────────────────────────────────────

    public function testPrepareProposesRecipientsSenderTextsAndAttachments(): void
    {
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
            ['id' => 82, 'name' => 'interni.txt', 'mime_type' => 'text/plain', 'file_size' => 10, 'send_with_record' => 0],
        ];

        $draft = $this->service()->prepare($this->request())->toArray();

        $this->assertSame(['ucetni@odberatel.example', 'jana@odberatel.example'], array_column($draft['to'], 'email'));
        $this->assertSame('Kontakt Účtárna — Faktury', $draft['to'][0]['label']);
        $this->assertSame(
            ['email' => 'fakturace@firma.example', 'name' => 'Naše firma s.r.o.', 'source' => 'default'],
            $draft['from'],
        );
        $this->assertSame([['email' => 'fakturace@firma.example', 'source' => 'default']], $draft['allowedSenders']);
        $this->assertSame('Faktura – daňový doklad IT-PRINT-INV — Tiskárna Vzorová s.r.o.', $draft['subject']);
        $this->assertStringStartsWith("Dobrý den,\n", $draft['body']);
        $this->assertSame(['id' => 12, 'name' => 'Odběratel s.r.o.'], $draft['recipientPerson']);
        $this->assertSame('cs', $draft['language']);
        $this->assertTrue($draft['canSend']);

        $this->assertSame(
            [
                ['print', 'faktura-it-print-inv.pdf', true],
                ['record', 'dodaci-list.pdf', true],
                ['record', 'interni.txt', false],
            ],
            array_map(static fn (array $a): array => [$a['kind'], $a['name'], $a['selected']], $draft['attachments']),
        );
    }

    public function testPrepareHasNoSideEffectsAndNeedsNoPdf(): void
    {
        $this->service()->prepare($this->request());

        $this->assertNothingCreated();
        $this->assertSame([], $this->log, 'návrh neotevírá transakci');
        $this->assertSame([[self::PRINT_ID, self::RECORD_ID, PrintFormat::Json, null]], $this->printRuns);
    }

    public function testPrepareReportsMissingRecipientAndSenderAsMessages(): void
    {
        $recipients = $this->createMock(RecipientResolver::class);
        $recipients->method('resolve')->willReturn(new RecipientResolution([], [
            ['severity' => 'error', 'code' => 'NO_RECIPIENT', 'text' => 'Žádný příjemce.'],
        ]));
        $this->recipients = $recipients;

        $senders = $this->createMock(SenderResolver::class);
        $senders->method('allowed')->willReturn($this->createMock(AllowedSenders::class));
        $senders->method('resolve')->willReturn(
            new SenderResolution(null, null, null, SenderResolution::NO_SENDER, 'Chybí adresa odesílatele.'),
        );
        $this->senders = $senders;

        $draft = $this->service()->prepare($this->request())->toArray();

        // Návrh nepadá — dialog chyby ukáže a uživatel adresy doplní.
        $this->assertFalse($draft['canSend']);
        $this->assertNull($draft['from']);
        $this->assertSame(['NO_RECIPIENT', 'NO_SENDER'], array_column($draft['messages'], 'code'));
    }

    public function testPrintWithoutSendPurposeIsNotSendable(): void
    {
        try {
            $this->service()->prepare($this->request(['printId' => 'economy.accounting.docJournal']));
            $this->fail('Interní tisk nejde odeslat');
        } catch (RecordSendException $e) {
            $this->assertSame(RecordSendException::PRINT_NOT_SENDABLE, $e->errorCode);
        }
    }

    public function testRecordInStateWithoutPrintIsNotAvailable(): void
    {
        $this->record['docState'] = 10;

        $this->expectException(PrintNotAvailableException::class);

        $this->service()->prepare($this->request());
    }

    // ── send ────────────────────────────────────────────────────────────────

    public function testSendCreatesMessageAttachmentsAndQueueRowInOneTransaction(): void
    {
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
            ['id' => 82, 'name' => 'interni.txt', 'mime_type' => 'text/plain', 'file_size' => 10, 'send_with_record' => 0],
        ];

        $result = $this->service()->send($this->request());

        $this->assertSame(701, $result->sentMessageId);
        $this->assertSame(31, $result->outboxId);
        $this->assertSame('sent', $result->transportState);

        $message = $this->createdMessages[0];
        $this->assertSame('Faktura – daňový doklad IT-PRINT-INV — Tiskárna Vzorová s.r.o.', $message['subject']);
        $this->assertSame('ucetni@odberatel.example, jana@odberatel.example', $message['email_to']);
        $this->assertNull($message['email_cc']);
        $this->assertSame('fakturace@firma.example', $message['email_from']);
        $this->assertSame('Naše firma s.r.o.', $message['email_from_name']);
        $this->assertSame(12, $message['recipient_person']);
        $this->assertSame('docs_core_heads', $message['target_table_id']);
        $this->assertSame(self::RECORD_ID, $message['target_row']);
        $this->assertSame('Faktura – daňový doklad IT-PRINT-INV', $message['target_label']);
        $this->assertSame('invoices', $message['purpose']);
        $this->assertSame('cs', $message['language']);
        $this->assertSame(self::PRINT_ID, $message['print_id']);
        $this->assertSame('manual', $message['send_trigger']);
        $this->assertSame(3, $message['created_by']);

        // PDF tisku + přílohy s příznakem jdou do příloh ZPRÁVY; na záznamu nic.
        $this->assertSame(
            [[SentMessageStore::TABLE_ID, 701, 'faktura-it-print-inv.pdf', self::PDF]],
            $this->uploads,
        );
        $this->assertSame([[81, SentMessageStore::TABLE_ID, 701]], $this->copies);

        // Okamžitý pokus až po commitu — rollback nesmí přijít po odeslání.
        $this->assertSame(['begin', 'create', 'upload', 'copy', 'enqueue', 'commit', 'attempt'], $this->log);
        // Tisk běžel jednou, rovnou do PDF.
        $this->assertSame([[self::PRINT_ID, self::RECORD_ID, PrintFormat::Pdf, null]], $this->printRuns);
    }

    public function testEverySendCreatesNewMessage(): void
    {
        $service = $this->service();

        $service->send($this->request());
        $service->send($this->request());

        $this->assertCount(2, $this->createdMessages);
        $this->assertCount(2, $this->printRuns, 'PDF se vyrábí pokaždé znovu');
    }

    public function testRecipientsAreResolvedLiveOnEverySend(): void
    {
        // Partner nahlásil novou adresu: další odeslání jde na ni, první zpráva zůstává.
        $recipients = $this->createMock(RecipientResolver::class);
        $recipients->method('resolve')->willReturnOnConsecutiveCalls(
            new RecipientResolution([new Recipient('stara@odberatel.example', '', Recipient::SOURCE_PERSON, 'E-mail osoby')]),
            new RecipientResolution([new Recipient('nova@odberatel.example', '', Recipient::SOURCE_PERSON, 'E-mail osoby')]),
        );
        $this->recipients = $recipients;
        $service = $this->service();

        $service->send($this->request());
        $service->send($this->request());

        $this->assertSame(
            ['stara@odberatel.example', 'nova@odberatel.example'],
            array_column($this->createdMessages, 'email_to'),
        );
    }

    public function testRequestOverridesTakePrecedence(): void
    {
        $this->recipients->expects($this->never())->method('resolve');
        $this->senders = $this->createMock(SenderResolver::class);
        $this->senders->method('allowed')->willReturn($this->createMock(AllowedSenders::class));
        $this->senders->expects($this->once())->method('resolve')
            ->with('docs_core_heads', $this->record, 'ucet@firma.example')
            ->willReturn(new SenderResolution('ucet@firma.example', null, SenderResolution::SOURCE_CHOSEN));

        $this->service()->send($this->request([
            'to'       => ['Nekdo@Jinde.example', 'nekdo@jinde.example'],
            'cc'       => ['kopie@jinde.example'],
            'from'     => 'ucet@firma.example',
            'subject'  => 'Vlastní předmět',
            'body'     => 'Vlastní text.',
            'language' => 'en',
        ]));

        $message = $this->createdMessages[0];
        $this->assertSame('Nekdo@Jinde.example', $message['email_to'], 'duplicitní adresa jen jednou');
        $this->assertSame('kopie@jinde.example', $message['email_cc']);
        $this->assertSame('ucet@firma.example', $message['email_from']);
        $this->assertNull($message['email_from_name']);
        $this->assertSame('Vlastní předmět', $message['subject']);
        $this->assertSame('Vlastní text.', $message['body_text']);
        $this->assertSame('en', $message['language']);
        $this->assertSame('en', $this->printRuns[0][3], 'PDF vzniká ve zvoleném jazyce');
    }

    public function testCommandLineSendOnlyQueues(): void
    {
        $this->transport->expects($this->never())->method('attempt');

        $this->service()->send($this->request(['to' => ['test@prijemce.example'], 'trigger' => 'cli']));

        $this->assertSame('cli', $this->createdMessages[0]['send_trigger']);
        $this->assertSame(['begin', 'create', 'upload', 'enqueue', 'commit'], $this->log);
    }

    public function testPdfAttachmentsAreMergedIntoPrintWhenPersonAsksForIt(): void
    {
        $this->person['send_attachments_merged'] = 1;
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
            ['id' => 83, 'name' => 'foto.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 4096, 'send_with_record' => 1],
        ];
        $source = $this->storedFile('%PDF-1.4 dodaci list');
        file_put_contents($source['file_path'], '%PDF-1.4 dodaci list');
        $this->attachments->method('getAttachment')->willReturn($source);
        $this->renderClient->expects($this->once())->method('postProcess')
            ->with(self::PDF, [['step' => 'appendPdfs', 'params' => ['pdfs' => ['%PDF-1.4 dodaci list']]]])
            ->willReturn(RenderResult::success('%PDF-1.4 merged'));

        $this->service()->send($this->request());

        // PDF příloha je uvnitř PDF tisku, obrázek jde zvlášť.
        $this->assertSame('%PDF-1.4 merged', $this->uploads[0][3]);
        $this->assertSame([[83, SentMessageStore::TABLE_ID, 701]], $this->copies);
    }

    public function testExplicitAttachmentSelectionReplacesSendFlag(): void
    {
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
            ['id' => 82, 'name' => 'interni.txt', 'mime_type' => 'text/plain', 'file_size' => 10, 'send_with_record' => 0],
        ];

        $this->service()->send($this->request(['attachmentIds' => [82]]));

        $this->assertSame([[82, SentMessageStore::TABLE_ID, 701]], $this->copies);
    }

    public function testAttachmentOfAnotherRecordIsRejected(): void
    {
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
        ];

        $this->assertSame(
            RecordSendException::INVALID_ATTACHMENT,
            $this->sendErrorCode($this->request(['attachmentIds' => [999]])),
        );
        $this->assertNothingCreated();
    }

    public function testSendWithoutRecipientCreatesNothing(): void
    {
        $recipients = $this->createMock(RecipientResolver::class);
        $recipients->method('resolve')->willReturn(new RecipientResolution([], [
            ['severity' => 'error', 'code' => 'NO_RECIPIENT', 'text' => 'Žádný příjemce.'],
        ]));
        $this->recipients = $recipients;

        $this->assertSame(RecordSendException::NO_RECIPIENT, $this->sendErrorCode($this->request()));
        $this->assertNothingCreated();

        // Vymazané „Komu“ z dialogu je totéž.
        $this->assertSame(RecordSendException::NO_RECIPIENT, $this->sendErrorCode($this->request(['to' => []])));
        $this->assertNothingCreated();
    }

    public function testSendWithoutAllowedSenderCreatesNothing(): void
    {
        foreach ([SenderResolution::NO_SENDER, SenderResolution::SENDER_NOT_ALLOWED] as $code) {
            $senders = $this->createMock(SenderResolver::class);
            $senders->method('allowed')->willReturn($this->createMock(AllowedSenders::class));
            $senders->method('resolve')->willReturn(new SenderResolution(null, null, null, $code, 'Odesílatel.'));
            $this->senders = $senders;

            $this->assertSame($code, $this->sendErrorCode($this->request()));
        }
        $this->assertNothingCreated();
    }

    public function testInvalidManualAddressCreatesNothing(): void
    {
        $this->assertSame(
            RecordSendException::INVALID_EMAIL,
            $this->sendErrorCode($this->request(['to' => ['ok@prijemce.example', 'neni-adresa']])),
        );
        $this->assertSame(
            RecordSendException::INVALID_EMAIL,
            $this->sendErrorCode($this->request(['cc' => ['taky spatne']])),
        );
        $this->assertNothingCreated();
    }

    public function testEmptySubjectOrBodyCreatesNothing(): void
    {
        $this->assertSame(RecordSendException::EMPTY_MESSAGE, $this->sendErrorCode($this->request(['subject' => '  '])));
        $this->assertSame(RecordSendException::EMPTY_MESSAGE, $this->sendErrorCode($this->request(['body' => ''])));
        $this->assertNothingCreated();
    }

    public function testRenderFailureCreatesNothing(): void
    {
        $this->printFails = new PrintRenderException(RenderErrorKind::Unreachable, 'render service down');

        try {
            $this->service()->send($this->request());
            $this->fail('Bez PDF se neodesílá');
        } catch (PrintRenderException) {
            // očekáváno
        }
        $this->assertNothingCreated();
        $this->assertSame([], $this->log, 'transakce se ani neotevřela');
    }

    public function testFailureInsideTransactionRollsBackAndRemovesStoredFiles(): void
    {
        $this->recordAttachments = [
            ['id' => 81, 'name' => 'dodaci-list.pdf', 'mime_type' => 'application/pdf', 'file_size' => 2048, 'send_with_record' => 1],
        ];
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('upload')->willReturnCallback(
            fn (int $tableId, int $recordId, string $name): array => ['success' => true, 'data' => $this->storedFile($name)],
        );
        $attachments->method('copyTo')->willReturn(['success' => false, 'error' => 'disk je plný']);
        $attachments->method('getFilePath')->willReturnCallback(
            static fn (array $attachment): string => (string) $attachment['file_path'],
        );
        $this->attachments = $attachments;

        try {
            $this->service()->send($this->request());
            $this->fail('Selhání kopie přílohy má odeslání zrušit');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disk je plný', $e->getMessage());
        }

        $this->assertSame(['begin', 'create', 'rollback'], $this->log);
        // Řádky vrátí rollback, soubor PDF na disku musí uklidit služba.
        $this->assertCount(1, $this->files);
        $this->assertFileDoesNotExist($this->files[0]);
    }
}
