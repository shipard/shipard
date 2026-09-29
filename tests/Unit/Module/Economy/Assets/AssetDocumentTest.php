<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\AssetDocument;

/**
 * Validace karty majetku per druh, cizí majetek, vyřazení bez data,
 * přidělení inventárního čísla při přechodu do 40 (spy subclass — Dibi
 * query() je final, DB volání jsou proto v protected metodách).
 */
class AssetDocumentTest extends TestCase
{
    private const CATEGORIES = [
        'small'          => ['name' => 'Drobný majetek', 'longTerm' => false, 'depreciable' => false, 'numberPrefix' => 'MA'],
        'tangible'       => ['name' => 'Dlouhodobý hmotný', 'longTerm' => true, 'depreciable' => true, 'numberPrefix' => 'MA'],
        'nondepreciable' => ['name' => 'Neodepisovaný', 'longTerm' => true, 'depreciable' => false, 'numberPrefix' => 'NM'],
    ];

    private function doc(): TestableAssetDocument
    {
        $doc = new TestableAssetDocument();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
        ]);
        $doc->setConfig($config);
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        return $doc;
    }

    /** @return array<string, mixed> */
    private function smallAsset(): array
    {
        return [
            'name'       => 'Vrtačka',
            'category'   => 'small',
            'tracking'   => 'single',
            'is_foreign' => 0,
            'price'      => '4990.00',
            'docState'   => 10,
        ];
    }

    /** @return list<array{column: string, code: string}> */
    private function errorsFor(array $data, string $column): array
    {
        $result = $this->doc()->validate($data);
        return array_values(array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === $column,
        ));
    }

    // --- validate: druhy -----------------------------------------------------

    public function testSmallAssetWithPriceIsValid(): void
    {
        $data = $this->smallAsset();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testNameIsRequired(): void
    {
        $data = $this->smallAsset();
        $data['name'] = '  ';
        $errors = $this->errorsFor($data, 'name');
        $this->assertSame('required', $errors[0]['code'] ?? null);
    }

    public function testUnknownCategoryFails(): void
    {
        $data = $this->smallAsset();
        $data['category'] = 'leasing';
        $this->assertSame('invalid', $this->errorsFor($data, 'category')[0]['code'] ?? null);
    }

    public function testLongTermRequiresAccountingGroupAndForbidsPrice(): void
    {
        $data = $this->smallAsset();
        $data['category'] = 'tangible';

        $this->assertSame('required', $this->errorsFor($data, 'accounting_group')[0]['code'] ?? null);
        $this->assertSame('not_allowed', $this->errorsFor($data, 'price')[0]['code'] ?? null);
    }

    public function testLongTermWithGroupAndNoPriceIsValid(): void
    {
        $data = $this->smallAsset();
        $data['category'] = 'nondepreciable';
        $data['accounting_group'] = 3;
        $data['price'] = null;

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testSmallAssetAccountingGroupIsOptional(): void
    {
        $data = $this->smallAsset();
        $data['price'] = null;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // --- validate: cizí majetek, data, vyřazení ------------------------------

    public function testForeignAssetRequiresOwner(): void
    {
        $data = $this->smallAsset();
        $data['is_foreign'] = 1;
        $this->assertSame('required', $this->errorsFor($data, 'owner')[0]['code'] ?? null);

        $data['owner'] = 12;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testDisposedBeforeAcquiredFails(): void
    {
        $data = $this->smallAsset();
        $data['acquired_date'] = '2026-03-01';
        $data['disposed_date'] = '2026-02-28';
        $this->assertSame('invalid_range', $this->errorsFor($data, 'disposed_date')[0]['code'] ?? null);
    }

    public function testArchivingWithoutDisposedDateIsFormLevelError(): void
    {
        $data = $this->smallAsset();
        $data['docState'] = 70;

        $errors = $this->errorsFor($data, ValidationError::FIELD_FORM);
        $this->assertSame('disposedDateRequired', $errors[0]['code'] ?? null);

        $data['disposed_date'] = '2026-09-01';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testDuplicateAssetNumberFails(): void
    {
        $doc = $this->doc();
        $doc->numberOwners = ['MA0007' => 99];

        $data = $this->smallAsset();
        $data['asset_number'] = 'MA0007';
        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('duplicate', $result->toArray()[0]['code']);
    }

    public function testOwnNumberIsNotDuplicate(): void
    {
        $doc = $this->doc();
        $doc->numberOwners = ['MA0007' => 5];

        $data = $this->smallAsset();
        $data['id'] = 5;
        $data['asset_number'] = 'MA0007';

        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame(5, $doc->lastExcludeId);
    }

    // --- beforeSave ----------------------------------------------------------

    public function testBeforeSaveClearsOwnerWhenNotForeignAndNormalizesStrings(): void
    {
        $doc = $this->doc();
        $data = [
            'asset_number' => '  ',
            'name'         => ' Vrtačka ',
            'is_foreign'   => 0,
            'owner'        => 12,
            'price'        => '',
        ];
        $doc->beforeSave($data, null);

        $this->assertNull($data['asset_number']);
        $this->assertSame('Vrtačka', $data['name']);
        $this->assertNull($data['owner']);
        $this->assertNull($data['price']);
    }

    public function testBeforeSaveKeepsOwnerForForeignAsset(): void
    {
        $doc = $this->doc();
        $data = ['name' => 'Auto', 'is_foreign' => 1, 'owner' => 12];
        $doc->beforeSave($data, null);

        $this->assertSame(12, $data['owner']);
    }

    // --- přidělení čísla (afterPersist) --------------------------------------

    public function testConfirmingConceptAssignsNextNumber(): void
    {
        $doc = $this->doc();
        $doc->existingNumbers = ['MA0041', 'MA0003'];

        $data = ['id' => 7, 'category' => 'small', 'asset_number' => null, 'docState' => 40];
        $doc->beforeSave($data, ['id' => 7, 'docState' => 10]);
        $doc->afterPersist($data);

        $this->assertSame(['MA' => 1], $doc->lockedPrefixes);
        $this->assertSame([7 => 'MA0042'], $doc->written);
    }

    public function testPrefixFromSettingsWinsOverCfgItem(): void
    {
        $doc = $this->doc();
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnMap([
            ['economy.assets.numberPrefix.small', 'DM'],
        ]);
        $doc->setSettings($settings);

        $data = ['id' => 1, 'category' => 'small', 'docState' => 40];
        $doc->beforeSave($data, ['id' => 1, 'docState' => 10]);
        $doc->afterPersist($data);

        $this->assertSame([1 => 'DM0001'], $doc->written);
    }

    public function testPrefixFallsBackToCategoryDefault(): void
    {
        $doc = $this->doc();
        $data = ['id' => 2, 'category' => 'nondepreciable', 'accounting_group' => 1, 'docState' => 40];
        $doc->beforeSave($data, ['id' => 2, 'docState' => 80]);
        $doc->afterPersist($data);

        $this->assertSame([2 => 'NM0001'], $doc->written);
    }

    public function testManualNumberIsNotOverwritten(): void
    {
        $doc = $this->doc();
        $data = ['id' => 3, 'category' => 'small', 'asset_number' => 'INV-77', 'docState' => 40];
        $doc->beforeSave($data, ['id' => 3, 'docState' => 10]);
        $doc->afterPersist($data);

        $this->assertSame([], $doc->written);
        $this->assertSame([], $doc->lockedPrefixes);
    }

    public function testSaveWithoutStateChangeDoesNotAssign(): void
    {
        $doc = $this->doc();
        $data = ['id' => 4, 'category' => 'small', 'asset_number' => null, 'docState' => 10];
        $doc->beforeSave($data, ['id' => 4, 'docState' => 10]);
        $doc->afterPersist($data);

        $this->assertSame([], $doc->written);
    }

    public function testDirectInsertInConfirmedStateAssigns(): void
    {
        $doc = $this->doc();
        $data = ['category' => 'small', 'docState' => 40];
        $doc->beforeSave($data, null);
        $data['id'] = 11;
        $doc->afterPersist($data);

        $this->assertSame(['old' => 0, 'new' => 40], $doc->getStateTransition());
        $this->assertSame([11 => 'MA0001'], $doc->written);
    }
}

/** Spy subclass — DB volání nahrazená pamětí. */
class TestableAssetDocument extends AssetDocument
{
    /** @var array<string, int> číslo → id karty */
    public array $numberOwners = [];
    public ?int $lastExcludeId = null;

    /** @var list<string> */
    public array $existingNumbers = [];
    /** @var array<string, int> prefix → počet zámků */
    public array $lockedPrefixes = [];
    /** @var array<int, string> id → zapsané číslo */
    public array $written = [];

    protected function findAssetNumberOwner(string $number, ?int $excludeId): ?int
    {
        $this->lastExcludeId = $excludeId;
        $owner = $this->numberOwners[$number] ?? null;
        return $owner === null || $owner === $excludeId ? null : $owner;
    }

    protected function lockNumbersWithPrefix(string $prefix): array
    {
        $this->lockedPrefixes[$prefix] = ($this->lockedPrefixes[$prefix] ?? 0) + 1;
        return $this->existingNumbers;
    }

    protected function writeAssetNumber(int $id, string $number): void
    {
        $this->written[$id] = $number;
    }
}
