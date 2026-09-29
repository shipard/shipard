<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AccountingGroupDocument;

/** Účetní skupina majetku: povinná pole, unikátní kód, třídy účtů. */
class AccountingGroupDocumentTest extends TestCase
{
    private function doc(): TestableAccountingGroupDocument
    {
        $doc = new TestableAccountingGroupDocument();
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        $doc->accounts = [
            1 => '022100',
            2 => '042100',
            3 => '082100',
            4 => '551100',
            5 => '541100',
            6 => '501100',
        ];
        return $doc;
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'code'                 => '022',
            'name'                 => 'Samostatné movité věci',
            'account_asset'        => 1,
            'account_acquisition'  => 2,
            'account_accumulated'  => 3,
            'account_depreciation' => 4,
            'account_disposal'     => 5,
        ];
    }

    /** @return array<string, string> column → code */
    private function codes(array $data): array
    {
        $out = [];
        foreach ($this->doc()->validate($data)->toArray() as $e) {
            $out[$e['column']] = $e['code'];
        }
        return $out;
    }

    public function testValidGroupPasses(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testCodeNameAndAssetAccountAreRequired(): void
    {
        $codes = $this->codes(['note' => 'x']);
        $this->assertSame('required', $codes['code'] ?? null);
        $this->assertSame('required', $codes['name'] ?? null);
        $this->assertSame('required', $codes['account_asset'] ?? null);
    }

    public function testOptionalAccountsMayBeEmpty(): void
    {
        $data = ['code' => '031', 'name' => 'Pozemky', 'account_asset' => 1];
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testAccountInWrongClassFails(): void
    {
        $data = $this->validData();
        $data['account_asset'] = 6;        // 501 není majetek
        $data['account_depreciation'] = 2; // 042 nejsou odpisy

        $codes = $this->codes($data);
        $this->assertSame('invalid', $codes['account_asset'] ?? null);
        $this->assertSame('invalid', $codes['account_depreciation'] ?? null);
        $this->assertArrayNotHasKey('account_acquisition', $codes);
    }

    public function testUnknownOrInactiveAccountFails(): void
    {
        $data = $this->validData();
        $data['account_accumulated'] = 999;
        $this->assertSame('invalid', $this->codes($data)['account_accumulated'] ?? null);
    }

    public function testDuplicateCodeFails(): void
    {
        $doc = $this->doc();
        $doc->codeOwners = ['022' => 8];
        $data = $this->validData();

        $this->assertSame('duplicate', $doc->validate($data)->toArray()[0]['code']);

        $data['id'] = 8;
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testBeforeSaveTrims(): void
    {
        $doc = $this->doc();
        $data = ['code' => ' 022 ', 'name' => ' Stroje '];
        $doc->beforeSave($data, null);
        $this->assertSame('022', $data['code']);
        $this->assertSame('Stroje', $data['name']);
    }
}

class TestableAccountingGroupDocument extends AccountingGroupDocument
{
    /** @var array<int, string> id účtu → číslo (jen aktivní analytické) */
    public array $accounts = [];
    /** @var array<string, int> kód → id skupiny */
    public array $codeOwners = [];

    protected function findCodeOwner(string $code, ?int $excludeId): ?int
    {
        $owner = $this->codeOwners[$code] ?? null;
        return $owner === null || $owner === $excludeId ? null : $owner;
    }

    protected function findActiveAnalyticalAccountNumber(int $accountId): ?string
    {
        return $this->accounts[$accountId] ?? null;
    }
}
