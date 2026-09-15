<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\TransportDocument;

class TransportDocumentTest extends TestCase
{
    /** @return list<array{column: string, message: string, code: string}> */
    private function errorsFor(array $errors, string $column): array
    {
        return array_values(array_filter($errors, static fn(array $e): bool => $e['column'] === $column));
    }

    public function testValidWithAndWithoutPartner(): void
    {
        $doc = new TransportDocument();
        $own = ['code' => 'OSOB', 'name' => 'Osobní odběr', 'partner' => null];
        $carrier = ['code' => 'PPL', 'name' => 'PPL dobírka', 'partner' => 55];
        $this->assertTrue($doc->validate($own)->isValid());
        $this->assertTrue($doc->validate($carrier)->isValid());
    }

    public function testCodeAndNameRequired(): void
    {
        $data = ['partner' => 55];
        $errors = (new TransportDocument())->validate($data)->toArray();

        $this->assertSame('required', $this->errorsFor($errors, 'code')[0]['code']);
        $this->assertSame('required', $this->errorsFor($errors, 'name')[0]['code']);
    }

    public function testInvalidValidityRangeFails(): void
    {
        $data = ['code' => 'PPL', 'name' => 'PPL', 'valid_from' => '2026-12-31', 'valid_to' => '2026-01-01'];
        $errors = (new TransportDocument())->validate($data)->toArray();

        $this->assertSame('invalid_range', $this->errorsFor($errors, 'valid_to')[0]['code']);
    }

    public function testBeforeSaveTrimsTextFields(): void
    {
        $doc = new TransportDocument();
        $data = ['code' => '  PPL ', 'name' => " PPL dobírka\n", 'notice' => ' x '];
        $doc->beforeSave($data);

        $this->assertSame('PPL', $data['code']);
        $this->assertSame('PPL dobírka', $data['name']);
        $this->assertSame('x', $data['notice']);
    }
}
