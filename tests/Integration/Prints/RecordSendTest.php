<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Prints;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Api\RecordSenderProviderLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Mail\MailServiceFactory;
use Shipard\Core\Mail\SenderResolver;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintEmailRenderer;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintOutput;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\PostProcess\PostProcessStepInterface;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Base\Persons\Send\RecipientResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\SendRequest;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Module\Core\Mail\Sent\SentMessageTransport;
use Shipard\Tests\Integration\IntegrationTestCase;

/** Render klient, který „spojuje“ PDF prostým zřetězením — bez pdfunite. */
final class ConcatenatingRenderClient extends RenderClient
{
    protected function createStep(string $name): ?PostProcessStepInterface
    {
        return new class implements PostProcessStepInterface {
            public function apply(string $pdf, array $params): string
            {
                return $pdf . "\n" . implode("\n", $params['pdfs']);
            }
        };
    }
}

/**
 * Odeslání faktury e-mailem nad skutečnou databází (#90 D35, D42, D44):
 * příjemci z kontaktů a osoby, přílohy zvlášť / spojené do PDF, zpráva
 * v Odeslané poště a řádek fronty.
 *
 * **Nic neodchází:** služba běží s `trigger: cli` (jen zařazení do fronty,
 * žádný pokus o odeslání) a celý test je v transakci, kterou `onTearDown`
 * vrátí — řádek fronty nikdy neuvidí worker `mail-outbox-run`. Adresy jsou
 * v rezervované doméně `.example`. PDF tisku je falešné (data a jazyk jdou
 * ze skutečného builderu), takže test nepotřebuje render službu.
 */
class RecordSendTest extends IntegrationTestCase
{
    use PrintFixtureDocuments;

    private const PRINT_ID = 'docs.invoicesOut.invoice';

    private const TEXTS_TABLE = 'core_prints_texts';

    private RecordSendService $service;
    private AttachmentService $attachments;
    private SentMessageStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[SentMessageStore::TABLE])) {
            $this->markTestSkipped('DS nemá modul core.mail s Odeslanou poštou.');
        }

        // Vše od této chvíle vrátí rollback v onTearDown.
        $this->db->begin();
        // Texty na tiscích, které na zdroji dat jsou, by přepsaly výchozí
        // předmět a tělo — na dobu testu se vypnou.
        if (isset($this->tables[self::TEXTS_TABLE])) {
            $this->db->execute('UPDATE %n SET [docState] = 10', self::TEXTS_TABLE);
        }
        $this->prepareFixtureDocuments();
        (new SettingsStore($this->db))->set('mail.defaultFrom', 'fakturace@firma.example');

        $modules  = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = PrintDefinitionLoader::load($this->dsConfig, $modules, 'cs');
        $runner   = PrintRunnerFactory::create($registry, $this->dsConfig, $this->db, $modules);
        $paths    = new PrintTemplatePaths($modules);
        $config   = ConfigRuntime::load($this->realDsPath, 'cs');

        // Data a jazyk ze skutečného builderu, PDF falešné — nese jazyk a titulek.
        $runPrint = static function (string $printId, int $recordId, PrintFormat $format, ?string $language) use ($runner): PrintOutput {
            $data = $runner->run($printId, $recordId, PrintFormat::Json, $language)->printData;
            return new PrintOutput(
                $format,
                $data,
                $format === PrintFormat::Pdf ? "%PDF-1.4 print [{$data->language}] {$data->title}" : null,
            );
        };

        $this->attachments = new AttachmentService($this->db, $this->dsPath, $this->tables);
        $this->store       = new SentMessageStore($this->db);
        $this->service     = new RecordSendService(
            $registry,
            $runPrint,
            new PrintCatalogLoader($paths),
            new PrintEmailRenderer($paths, new PrintTwigFactory($paths)),
            new RecipientResolver($this->db, $config),
            SenderResolver::forDataSource($this->db, RecordSenderProviderLoader::load($this->dsConfig, $modules), $config),
            $this->attachments,
            new ConcatenatingRenderClient(null),
            $this->store,
            new SentMessageTransport($this->store, MailServiceFactory::create($this->dsConfig, $this->db)),
            $this->db,
            $this->tables,
            $config,
        );
    }

    protected function onTearDown(): void
    {
        $this->db->rollback();
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function insertPerson(array $overrides = []): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_persons_persons', $overrides + [
            'person_id'    => 'IT' . bin2hex(random_bytes(3)),
            'person_type'  => 2,
            'full_name'    => 'IT-SEND Odběratel s.r.o.',
            'last_name'    => 'IT-SEND Odběratel s.r.o.',
            'first_name'   => '',
            'company_id'   => '',
            'tax_id'       => '',
            'vat_id'       => '',
            'email'        => 'info@it-send-odberatel.example',
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    /** @param array<string, mixed> $overrides */
    private function insertContact(int $personId, string $name, string $email, ?array $purposes, array $overrides = []): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_persons_contacts', $overrides + [
            'person'        => $personId,
            'name'          => $name,
            'email'         => $email,
            'send_purposes' => $purposes === null ? null : json_encode($purposes),
            'order_pos'     => 0,
            'docState'      => 40,
            'docStateMain'  => 3,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    private function invoiceFor(int $personId): int
    {
        return $this->insertInvoice($this->expected('invoice'), $this->anyUnit()[0], ['partner' => $personId]);
    }

    private function attach(int $headId, string $name, string $content, bool $sendWithRecord): int
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'shpd_it_send_');
        file_put_contents($tmp, $content);
        $result = $this->attachments->upload(
            $this->tables['docs_core_heads']->tableId,
            $headId,
            $name,
            $tmp,
            null,
            $sendWithRecord,
        );
        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        return (int) $result['data']['id'];
    }

    private function request(int $headId, array $overrides = []): SendRequest
    {
        // `cli` = jen fronta, žádný pokus o odeslání.
        return new SendRequest(...($overrides + [
            'printId'  => self::PRINT_ID,
            'recordId' => $headId,
            'trigger'  => SendRequest::TRIGGER_CLI,
        ]));
    }

    /** @return list<array<string, mixed>> přílohy zprávy v pořadí vzniku */
    private function messageAttachments(int $messageId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM [core_attachments_files] WHERE [table_id] = %i AND [record_id] = %i ORDER BY [id]',
            SentMessageStore::TABLE_ID,
            $messageId,
        );
    }

    // ── příjemci ────────────────────────────────────────────────────────────

    public function testContactsWithPurposeAreRecipientsInOrder(): void
    {
        $personId = $this->insertPerson();
        $this->insertContact($personId, 'Jana', 'jana@it-send-odberatel.example', ['reminders', 'invoices'], ['order_pos' => 2]);
        $this->insertContact($personId, 'Účtárna', 'ucetni@it-send-odberatel.example', ['invoices'], ['order_pos' => 1]);
        // Tihle faktury nedostanou: bez účelu, jiný účel, archivovaný, s prošlou platností.
        $this->insertContact($personId, 'Recepce', 'recepce@it-send-odberatel.example', null);
        $this->insertContact($personId, 'Upomínky', 'upominky@it-send-odberatel.example', ['reminders']);
        $this->insertContact($personId, 'Bývalá účetní', 'byvala@it-send-odberatel.example', ['invoices'], ['docState' => 70, 'docStateMain' => 4]);
        $this->insertContact($personId, 'Zástup', 'zastup@it-send-odberatel.example', ['invoices'], ['valid_to' => '2020-12-31']);

        $draft = $this->service->prepare($this->request($this->invoiceFor($personId)))->toArray();

        $this->assertSame(
            ['ucetni@it-send-odberatel.example', 'jana@it-send-odberatel.example'],
            array_column($draft['to'], 'email'),
        );
        $this->assertSame('Kontakt Účtárna — Faktury a daňové doklady', $draft['to'][0]['label']);
        $this->assertSame('fakturace@firma.example', $draft['from']['email']);
        $this->assertTrue($draft['canSend']);
    }

    public function testPersonEmailIsUsedWhenNoContactHasThePurpose(): void
    {
        $personId = $this->insertPerson();
        $this->insertContact($personId, 'Recepce', 'recepce@it-send-odberatel.example', null);

        $draft = $this->service->prepare($this->request($this->invoiceFor($personId)))->toArray();

        $this->assertSame(['info@it-send-odberatel.example'], array_column($draft['to'], 'email'));
        $this->assertSame('person', $draft['to'][0]['source']);
        $this->assertSame('E-mail osoby', $draft['to'][0]['label']);
    }

    public function testPersonWithoutAnyEmailHasNoRecipient(): void
    {
        $headId = $this->invoiceFor($this->insertPerson(['email' => '']));

        $draft = $this->service->prepare($this->request($headId))->toArray();

        $this->assertSame([], $draft['to']);
        $this->assertFalse($draft['canSend']);
        $this->assertSame(['NO_RECIPIENT'], array_column($draft['messages'], 'code'));

        try {
            $this->service->send($this->request($headId));
            $this->fail('Bez příjemce se neodesílá');
        } catch (RecordSendException $e) {
            $this->assertSame(RecordSendException::NO_RECIPIENT, $e->errorCode);
        }
        $this->assertSame([], $this->store->forTarget('docs_core_heads', $headId));
    }

    // ── zpráva, přílohy, fronta ─────────────────────────────────────────────

    public function testSendCreatesQueuedMessageWithAttachmentsAndLeavesRecordAlone(): void
    {
        $personId = $this->insertPerson();
        $headId   = $this->invoiceFor($personId);
        $pdfId    = $this->attach($headId, 'dodaci-list.pdf', '%PDF-1.4 dodaci list', true);
        $txtId    = $this->attach($headId, 'poznamka.txt', 'poznámka k dokladu', true);
        $this->attach($headId, 'interni.txt', 'interní poznámka', false);

        // Fixture faktura má slovenského odběratele — jazyk tady určuje požadavek.
        $result = $this->service->send($this->request($headId, ['language' => 'cs']));

        $this->assertSame(SentMessageStore::TRANSPORT_QUEUED, $result->transportState);
        $message = $this->store->get($result->sentMessageId);
        $this->assertSame(SentMessageStore::DOC_STATE_SENT, (int) $message['docState']);
        $this->assertSame('queued', $message['transport_state']);
        $this->assertSame('info@it-send-odberatel.example', $message['email_to']);
        $this->assertSame('fakturace@firma.example', $message['email_from']);
        $this->assertSame($personId, (int) $message['recipient_person']);
        $this->assertSame('docs_core_heads', $message['target_table_id']);
        $this->assertSame($headId, (int) $message['target_row']);
        $this->assertSame('Faktura – daňový doklad IT-PRINT-INV', $message['target_label']);
        $this->assertSame('invoices', $message['purpose']);
        $this->assertSame(self::PRINT_ID, $message['print_id']);
        $this->assertSame('cli', $message['send_trigger']);
        $this->assertSame(0, (int) $message['send_count']);

        // Přílohy zprávy: PDF tisku a kopie příloh s příznakem — bez spojování.
        $attachments = $this->messageAttachments($result->sentMessageId);
        $this->assertSame(
            ['faktura-it-print-inv.pdf', 'dodaci-list.pdf', 'poznamka.txt'],
            array_column($attachments, 'name'),
        );
        $this->assertNotContains($pdfId, array_map('intval', array_column($attachments, 'id')), 'kopie, ne přepojená příloha');

        // Fronta: řádek čeká, nic neodešlo; nese přílohy zprávy.
        $outbox = $this->db->fetchRow('SELECT * FROM [core_mail_outbox] WHERE [id] = %i', $result->outboxId);
        $this->assertSame('pending', $outbox['state']);
        $this->assertSame(0, (int) $outbox['attempt_count']);
        $this->assertSame('sentMessage:' . $result->sentMessageId, $outbox['source_ref']);
        $this->assertSame(
            array_map('intval', array_column($attachments, 'id')),
            json_decode((string) $outbox['attachments'], true),
        );
        $this->assertSame($result->outboxId, (int) $message['last_outbox_id']);

        // Záznam beze změny: tři přílohy, žádná zmrazená kopie.
        $onRecord = $this->db->fetchAll(
            'SELECT [id] FROM [core_attachments_files] WHERE [table_id] = %i AND [record_id] = %i ORDER BY [id]',
            $this->tables['docs_core_heads']->tableId,
            $headId,
        );
        $this->assertCount(3, $onRecord);
        $this->assertContains($txtId, array_map('intval', array_column($onRecord, 'id')));
    }

    public function testPdfAttachmentsAreMergedIntoPrintWhenPersonAsks(): void
    {
        if (!isset($this->db->getTableColumns('base_persons_persons')['send_attachments_merged'])) {
            $this->markTestSkipped('DS nemá docs.core (sloupec send_attachments_merged).');
        }
        $headId = $this->invoiceFor($this->insertPerson(['send_attachments_merged' => 1]));
        $this->attach($headId, 'dodaci-list.pdf', '%PDF-1.4 dodaci list', true);
        $this->attach($headId, 'poznamka.txt', 'poznámka k dokladu', true);

        $draft = $this->service->prepare($this->request($headId))->toArray();
        $this->assertTrue($draft['mergeAttachments']);
        $this->assertSame([false, true, false], array_column($draft['attachments'], 'merged'));

        $result      = $this->service->send($this->request($headId, ['language' => 'cs']));
        $attachments = $this->messageAttachments($result->sentMessageId);

        // PDF příloha je uvnitř PDF tisku, textový soubor jde zvlášť.
        $this->assertSame(['faktura-it-print-inv.pdf', 'poznamka.txt'], array_column($attachments, 'name'));
        $print = (string) file_get_contents($this->attachments->getFilePath($attachments[0]));
        $this->assertStringContainsString('Faktura – daňový doklad IT-PRINT-INV', $print);
        $this->assertStringContainsString('%PDF-1.4 dodaci list', $print);
    }

    // ── jazyk a živé adresy ─────────────────────────────────────────────────

    public function testSlovakPartnerGetsSlovakSubjectBodyAndPdf(): void
    {
        // Jazyk dokumentu: výslovně na osobě, jinak podle země odběratele
        // (fixture faktura má slovenskou adresu) — tady obojí.
        $headId = $this->invoiceFor($this->insertPerson(['language' => 'sk']));

        $result  = $this->service->send($this->request($headId));
        $message = $this->store->get($result->sentMessageId);

        $this->assertSame('sk', $message['language']);
        $this->assertStringStartsWith('Faktúra – daňový doklad IT-PRINT-INV', (string) $message['subject']);
        $this->assertStringStartsWith('Dobrý deň,', (string) $message['body_text']);

        $print = $this->messageAttachments($result->sentMessageId)[0];
        $this->assertStringContainsString(
            '[sk] Faktúra',
            (string) file_get_contents($this->attachments->getFilePath($print)),
        );
    }

    public function testUserEmailTextsOverrideDefaultSubjectAndBodyOfDraft(): void
    {
        if (!isset($this->tables[self::TEXTS_TABLE])) {
            $this->markTestSkipped('DS nemá modul core.prints s texty na tiscích.');
        }
        $headId = $this->invoiceFor($this->insertPerson());

        $default = $this->service->prepare($this->request($headId))->toArray();
        // Výchozí šablona: „<titulek> <číslo> — <vlastní firma>“ v jazyce partnera.
        $this->assertStringContainsString('IT-PRINT-INV — ', $default['subject']);

        $dibi = $this->db->getDibiConnection();
        foreach ([
            'emailSubject' => 'Vaše faktura {{ data.document.number }}',
            'emailBody'    => "Dobrý den,\n\nfaktura {{ data.document.number }} je splatná {{ data.dates.due|date }}.",
        ] as $slot => $text) {
            $dibi->insert(self::TEXTS_TABLE, [
                'name' => 'IT e-mail', 'slot' => $slot, 'text' => $text,
                'prints' => json_encode([self::PRINT_ID]), 'order_pos' => 0,
                'docState' => 40, 'docStateMain' => 2,
            ])->execute();
        }

        // Návrh v dialogu Odeslat i `print-send --dry-run` jdou přes prepare().
        $draft = $this->service->prepare($this->request($headId))->toArray();

        $this->assertSame('Vaše faktura IT-PRINT-INV', $draft['subject']);
        $this->assertStringStartsWith("Dobrý den,\n\nfaktura IT-PRINT-INV je splatná ", $draft['body']);
        $this->assertStringNotContainsString('{{', $draft['body']);
    }

    public function testChangedPersonEmailAppliesToNextSendAndKeepsHistory(): void
    {
        $personId = $this->insertPerson(['email' => 'stara@it-send-odberatel.example']);
        $headId   = $this->invoiceFor($personId);

        $first = $this->service->send($this->request($headId));

        // „Faktura mi nepřišla“ — oprava adresy na osobě a nové odeslání.
        $this->db->updateWhere('base_persons_persons', ['email' => 'nova@it-send-odberatel.example'], 'id = %i', $personId);
        $second = $this->service->send($this->request($headId));

        $this->assertNotSame($first->sentMessageId, $second->sentMessageId);
        $this->assertSame('stara@it-send-odberatel.example', $this->store->get($first->sentMessageId)['email_to']);
        $this->assertSame('nova@it-send-odberatel.example', $this->store->get($second->sentMessageId)['email_to']);

        // Záznam ví, co odešlo, přes zprávy, které na něj ukazují — nejnovější první.
        $this->assertSame(
            [$second->sentMessageId, $first->sentMessageId],
            array_map(static fn (array $m): int => (int) $m['id'], $this->store->forTarget('docs_core_heads', $headId)),
        );
    }
}
