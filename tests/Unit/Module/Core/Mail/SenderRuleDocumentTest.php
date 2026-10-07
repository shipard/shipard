<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\SenderRuleDocument;

/**
 * Validace a beforeSave logika SenderRuleDocument. Unikátnost mezi živými
 * pravidly (docState 10/40/80) používá mock \Dibi\Connection.
 */
class SenderRuleDocumentTest extends TestCase
{
    private function doc(): SenderRuleDocument
    {
        return new SenderRuleDocument();
    }

    // --- validate -----------------------------------------------------------

    public function testValidateMissingPatternFails(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'email'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('pattern', $columns);
        $this->assertSame('required', $result->toArray()[0]['code']);
    }

    public function testValidateEmailKindRejectsInvalidAddress(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'email', 'pattern' => 'not-an-email'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_format', $result->toArray()[0]['code']);
    }

    public function testValidateEmailKindAcceptsAddress(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'email', 'pattern' => 'news@example.com'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateEmailKindIsCaseInsensitive(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'email', 'pattern' => '  News@Example.COM  '];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateDomainKindRejectsAtSign(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'domain', 'pattern' => 'news@example.com'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_format', $result->toArray()[0]['code']);
    }

    public function testValidateDomainKindRejectsMissingDot(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'domain', 'pattern' => 'localhost'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_format', $result->toArray()[0]['code']);
    }

    public function testValidateDomainKindAcceptsDomain(): void
    {
        $doc = $this->doc();
        $data = ['pattern_kind' => 'domain', 'pattern' => 'newsletter.example.com'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateLiveDuplicateFails(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row(['id' => 7]));

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['pattern_kind' => 'email', 'pattern' => 'news@example.com'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $first = $result->toArray()[0];
        $this->assertSame('pattern', $first['column']);
        $this->assertSame('duplicate_pattern', $first['code']);
    }

    public function testValidateTrashedDuplicateDoesNotBlockReuse(): void
    {
        // Dotaz filtruje docState IN (10,40,80) — koš vrací null.
        $captured = null;
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (...$args) use (&$captured) {
                $captured = $args;
                return null;
            },
        );

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['pattern_kind' => 'email', 'pattern' => 'news@example.com'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertContains([10, 40, 80], $captured);
    }

    public function testValidateDuplicateCheckExcludesOwnId(): void
    {
        $captured = null;
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (...$args) use (&$captured) {
                $captured = $args;
                return null;
            },
        );

        $doc = $this->doc();
        $doc->setDb($db);

        $data = ['id' => 12, 'pattern_kind' => 'email', 'pattern' => 'news@example.com'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertSame(12, end($captured));
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveNormalizesPattern(): void
    {
        $doc = $this->doc();
        $data = ['pattern' => '  News@Example.COM  '];

        $doc->beforeSave($data);

        $this->assertSame('news@example.com', $data['pattern']);
    }

    public function testBeforeSaveFillsDefaultsForNewRecord(): void
    {
        $doc = $this->doc();
        $data = ['pattern' => 'news@example.com'];

        $doc->beforeSave($data);

        $this->assertSame('email', $data['pattern_kind']);
        // D9: ručně zakládané pravidlo archivuje až po analýze.
        $this->assertSame('archiveIfOther', $data['disposition']);
        $this->assertSame('user', $data['origin']);
        $this->assertSame(0, $data['hit_count']);
        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
    }

    public function testBeforeSaveKeepsExplicitValues(): void
    {
        $doc = $this->doc();
        $data = [
            'pattern' => 'example.com',
            'pattern_kind' => 'domain',
            'origin' => 'suggested',
        ];

        $doc->beforeSave($data);

        $this->assertSame('domain', $data['pattern_kind']);
        $this->assertSame('suggested', $data['origin']);
    }

    public function testBeforeSaveOnExistingRecordOnlyUpdatesModified(): void
    {
        $doc = $this->doc();
        $existingCreated = '2025-01-01 00:00:00';
        $data = [
            'id' => 5,
            'pattern' => 'news@example.com',
            'created' => $existingCreated,
        ];

        $doc->beforeSave($data);

        $this->assertSame($existingCreated, $data['created']);
        $this->assertNotSame($existingCreated, $data['modified']);
    }

    // --- přechod docState (stateChanged → SenderRuleConfirmedHandler, D8) ---

    public function testBeforeSaveTracksConfirmationTransition(): void
    {
        $doc = $this->doc();
        $data = ['id' => 7, 'pattern' => 'news@example.com', 'docState' => 40];

        $doc->beforeSave($data, ['id' => 7, 'pattern' => 'news@example.com', 'docState' => 10]);

        $this->assertSame(['old' => 10, 'new' => 40], $doc->getStateTransition());
    }

    public function testBeforeSaveWithoutStateChangeHasNoTransition(): void
    {
        $doc = $this->doc();
        $data = ['id' => 7, 'pattern' => 'news@example.com', 'notice' => 'x'];

        $doc->beforeSave($data, ['id' => 7, 'pattern' => 'news@example.com', 'docState' => 40]);

        $this->assertNull($doc->getStateTransition());
    }

    public function testNewRecordOutsideDraftIsTransitionFromZero(): void
    {
        $doc = $this->doc();
        $data = ['pattern' => 'news@example.com', 'docState' => 40];

        $doc->beforeSave($data);

        $this->assertSame(['old' => 0, 'new' => 40], $doc->getStateTransition());
    }

    public function testNewDraftRecordHasNoTransition(): void
    {
        $doc = $this->doc();
        $data = ['pattern' => 'news@example.com'];

        $doc->beforeSave($data);

        $this->assertNull($doc->getStateTransition());
    }
}
