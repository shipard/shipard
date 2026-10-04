<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Attachments\AttachmentGuard;
use Shipard\Module\Core\Mail\Sent\SentMessageAttachmentGuard;
use Shipard\Module\Core\Mail\Sent\SentMessageDocument;
use Shipard\Core\Database\DataSourceConnection;

/** Pevný obsah odeslané zprávy (#90 D41): mění se jen stav. */
class SentMessageDocumentTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../../../../../modules/core/mail/';

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return [
            'id'              => 7,
            'subject'         => 'Faktura 2260011',
            'body_text'       => 'Dobrý den.',
            'email_from'      => 'fakturace@firma.example',
            'email_to'        => 'ucetni@odberatel.example',
            'email_cc'        => null,
            'target_table_id' => 'docs_core_heads',
            'target_row'      => 55,
            'created'         => new \DateTimeImmutable('2026-10-04 14:30:00'),
            'docState'        => 40,
            'docStateMain'    => 1,
        ];
    }

    private function document(): SentMessageDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row($this->stored()));

        $doc = new SentMessageDocument();
        $doc->setDb($db);
        return $doc;
    }

    /** @return list<string> */
    private function errorCodes(array $data): array
    {
        return array_map(
            static fn (ValidationError $error): string => $error->column . ':' . $error->code,
            $this->document()->validate($data)->getErrors(),
        );
    }

    public function testMessageCannotBeCreatedByHand(): void
    {
        $this->assertSame(['_form:system_managed'], $this->errorCodes(['subject' => 'Ručně', 'email_to' => 'x@y.example']));
    }

    public function testStateChangeAlonePasses(): void
    {
        $this->assertSame([], $this->errorCodes(['id' => 7, 'docState' => 70]));
    }

    public function testFullPayloadWithUnchangedContentPasses(): void
    {
        $data = ['created' => '2026-10-04 14:30:00', 'docState' => 90] + $this->stored();

        $this->assertSame([], $this->errorCodes($data));
    }

    public function testContentChangeIsRejected(): void
    {
        foreach ([
            ['subject' => 'Jiný předmět'],
            ['body_text' => 'Jiný text'],
            ['email_to' => 'nekdo@jinde.example'],
            ['email_cc' => 'kopie@jinde.example'],
            ['email_from' => 'jiny@firma.example'],
            ['target_row' => 56],
        ] as $change) {
            $this->assertSame(['_form:immutable'], $this->errorCodes(['id' => 7] + $change), key($change));
        }
    }

    public function testMessageIsNeverDeletedPhysically(): void
    {
        $this->expectException(\LogicException::class);

        $this->document()->beforeDelete($this->stored());
    }

    public function testAttachmentsOfSentMessageAreFrozen(): void
    {
        $guard = new SentMessageAttachmentGuard($this->createMock(DataSourceConnection::class));

        foreach ([
            AttachmentGuard::OPERATION_UPLOAD,
            AttachmentGuard::OPERATION_DELETE,
            AttachmentGuard::OPERATION_RENAME,
            AttachmentGuard::OPERATION_REORDER,
            AttachmentGuard::OPERATION_SEND_FLAG,
        ] as $operation) {
            $this->assertNotNull($guard->refuse(['table_id' => 455, 'record_id' => 7], $operation), $operation);
        }
    }

    public function testStatesAreAllReadOnlyWithArchiveAndTrashTransitions(): void
    {
        $states = DocStateConfig::fromCfgItem(ConfigLocalizer::localize(
            JsoncParser::parseFile(self::MODULE_DIR . 'config/docStatesSent.jsonc'),
            'cs',
        ));

        foreach ([40, 70, 90] as $state) {
            $this->assertTrue($states->isReadOnly($state), "stav {$state} je jen pro čtení");
        }
        // Odeslaná → V archivu / Smazaná; zpět jen na Odeslanou.
        $this->assertTrue($states->isTransitionAllowed(40, 70));
        $this->assertTrue($states->isTransitionAllowed(40, 90));
        $this->assertTrue($states->isTransitionAllowed(70, 40));
        $this->assertTrue($states->isTransitionAllowed(90, 40));
        $this->assertFalse($states->isTransitionAllowed(70, 90));
        $this->assertFalse($states->isTransitionAllowed(90, 70));
        $this->assertSame([40], $states->getViewGroupStates('active'));
    }

    public function testTableIsSystemManagedAndStartsAsSent(): void
    {
        $def = TableDefinition::fromArray(
            JsoncParser::parseFile(self::MODULE_DIR . 'tables/core_mail_sent_messages.jsonc'),
        );

        $this->assertTrue($def->systemManaged);
        $this->assertSame(455, $def->tableId);
        $this->assertSame('core.mail.docStatesSent', $def->docStates->cfgItem);
    }
}
