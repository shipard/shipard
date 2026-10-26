<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Module\Base\Persons\ContactDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

class ContactDocumentTest extends TestCase
{
    private function document(): ContactDocument
    {
        $doc = new ContactDocument();
        $doc->setConfig(ConfigRuntimeFactory::fromItems([
            ConfigCompiler::SEND_PURPOSES_ITEM => [
                'invoices'  => ['name' => 'Faktury a daňové doklady'],
                'reminders' => ['name' => 'Upomínky'],
            ],
        ]));
        return $doc;
    }

    /** @return list<string> */
    private function errorCodes(array $data): array
    {
        return array_map(
            static fn ($error): string => $error->column . ':' . $error->code,
            $this->document()->validate($data)->getErrors(),
        );
    }

    public function testKnownPurposesPass(): void
    {
        $this->assertSame([], $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => ['invoices', 'reminders']]));
        $this->assertSame([], $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => '["invoices"]']));
        $this->assertSame([], $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => null]));
        $this->assertSame([], $this->errorCodes(['name' => 'Účtárna']), 'payload bez sloupce se nekontroluje');
    }

    public function testUnknownPurposeIsRejected(): void
    {
        $this->assertSame(
            ['send_purposes:invalid'],
            $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => ['invoices', 'newsletter']]),
        );
    }

    public function testDuplicatePurposeIsRejected(): void
    {
        $this->assertSame(
            ['send_purposes:invalid'],
            $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => ['invoices', 'invoices']]),
        );
    }

    public function testValueThatIsNotListOfIdsIsRejected(): void
    {
        $this->assertSame(
            ['send_purposes:invalid'],
            $this->errorCodes(['name' => 'Účtárna', 'send_purposes' => 'invoices']),
        );
    }

    public function testBeforeSaveNormalizesEmailAndPhoneByPersonCountry(): void
    {
        $doc = new class extends ContactDocument {
            protected function personContext(): PersonContext
            {
                return new class (null, 'cz') extends PersonContext {
                    protected function storedCountry(int $personId): ?string
                    {
                        return $personId === 7 ? 'sk' : null;
                    }
                };
            }
        };
        $data = ['person' => 7, 'name' => 'Jana', 'email' => ' Jana@Acme.Example ', 'phone' => '0905 123 456'];
        $doc->beforeSave($data);
        $this->assertSame('jana@acme.example', $data['email']);
        $this->assertSame('+421905123456', $data['phone'], 'předvolba podle země osoby');

        $plain = ['person' => 8, 'phone' => '577 123 456'];
        $doc->beforeSave($plain);
        $this->assertSame('+420577123456', $plain['phone'], 'osoba bez adresy: země zdroje dat');
    }

    public function testBeforeSaveSerializesListAndEmptySelection(): void
    {
        $data = ['name' => 'Účtárna', 'send_purposes' => ['invoices', 'reminders']];
        $this->document()->beforeSave($data);
        $this->assertSame('["invoices","reminders"]', $data['send_purposes']);

        $data = ['name' => 'Účtárna', 'send_purposes' => []];
        $this->document()->beforeSave($data);
        $this->assertNull($data['send_purposes']);

        $data = ['name' => 'Účtárna'];
        $this->document()->beforeSave($data);
        $this->assertArrayNotHasKey('send_purposes', $data, 'částečný payload sloupec nepřidá');
    }
}
