<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Utils\JsoncParser;
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

    private static ?array $czRules = null;

    private function doc(): TestableAssetDocument
    {
        self::$czRules ??= JsoncParser::parseFile(__DIR__ . '/../../../../../modules/world/assets/config/assets-cz.jsonc');
        $doc = new TestableAssetDocument();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.assets.categories', self::CATEGORIES],
            ['world.assets.cz', self::$czRules],
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

    /** @return array<string, mixed> odepisovaná karta s platným nastavením */
    private function tangibleAsset(): array
    {
        return [
            'name'             => 'Soustruh',
            'category'         => 'tangible',
            'tracking'         => 'single',
            'accounting_group' => 1,
            'is_foreign'       => 0,
            'tax_method'       => 'straight',
            'tax_rule'         => 'cz-2',
            'acc_method'       => 'as_tax',
            'acc_months'       => null,
            'docState'         => 10,
        ];
    }

    /** @return list<string> column:code */
    private function codes(array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $this->doc()->validate($data)->toArray(),
        );
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

    public function testImportMayStoreLongTermDraftWithoutGroup(): void
    {
        // D79: import ukládá dlouhodobou kartu bez skupiny jako koncept.
        $data = $this->tangibleAsset();
        $data['accounting_group'] = null;
        $data['_import'] = true;
        $this->assertSame([], $this->errorsFor($data, 'accounting_group'));
        $this->assertTrue($this->doc()->isLockExempt($data));

        $data['docState'] = 40;
        $this->assertSame('required', $this->errorsFor($data, 'accounting_group')[0]['code'] ?? null, 'potvrzená karta skupinu potřebuje i v importu');

        $data['docState'] = 10;
        $this->doc()->beforeSave($data, null);
        $this->assertArrayNotHasKey('_import', $data, 'marker nesmí dojít do SQL');
    }

    public function testLongTermWithGroupAndNoPriceIsValid(): void
    {
        $data = $this->smallAsset();
        $data['category'] = 'nondepreciable';
        $data['accounting_group'] = 3;
        $data['price'] = null;

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testDepreciableCardNeedsCompleteAccountingGroupToConfirm(): void
    {
        // D57: skupina bez účtu odpisů nebo oprávek kartu nepustí do V pořádku.
        $codes = static fn(TestableAssetDocument $doc, array $data): array => array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
        $confirmed = ['docState' => 40] + $this->tangibleAsset();

        foreach ([
            ['account_depreciation' => null, 'account_accumulated' => 82],
            ['account_depreciation' => 551, 'account_accumulated' => null],
        ] as $group) {
            $doc = $this->doc();
            $doc->accountingGroup = $group;
            $this->assertSame(['accounting_group:accountingGroupIncomplete'], $codes($doc, $confirmed));
        }

        // Koncept se uložit smí — skupinu jde doplnit před potvrzením.
        $doc = $this->doc();
        $doc->accountingGroup = ['account_depreciation' => null, 'account_accumulated' => null];
        $this->assertSame([], $codes($doc, $this->tangibleAsset()));
        $this->assertSame([], $doc->groupQueries);

        // Úplná skupina projde.
        $doc = $this->doc();
        $this->assertSame([], $codes($doc, $confirmed));
        $this->assertSame([1], $doc->groupQueries);
    }

    public function testNonDepreciableCardDoesNotNeedDepreciationAccounts(): void
    {
        // Pozemek: skupina účet odpisů ani oprávek nemá a mít nemusí.
        $doc = $this->doc();
        $doc->accountingGroup = ['account_depreciation' => null, 'account_accumulated' => null];
        $data = ['category' => 'nondepreciable', 'accounting_group' => 3, 'price' => null, 'docState' => 40]
            + $this->smallAsset();
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame([], $doc->groupQueries);
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

    // --- odpisové nastavení (D30) --------------------------------------------

    public function testDepreciableCardNeedsMethods(): void
    {
        $this->assertSame([], $this->codes($this->tangibleAsset()));
        $this->assertSame(
            ['tax_method:required', 'acc_method:required'],
            $this->codes(['tax_method' => null, 'acc_method' => null] + $this->tangibleAsset()),
        );
    }

    public function testDepreciationSettingsCombinations(): void
    {
        $card = fn(array $o): array => $o + $this->tangibleAsset();

        $this->assertSame(['tax_rule:required'], $this->codes($card(['tax_rule' => null])));
        $this->assertSame(['tax_rule:ruleNotValid'], $this->codes($card(['tax_rule' => 'cz-nim-software'])));
        $this->assertSame(['tax_method:invalid'], $this->codes($card(['tax_method' => 'units'])));
        $this->assertSame(['acc_method:invalid'], $this->codes($card(['acc_method' => 'av'])));
        $this->assertSame(['acc_months:accMonthsMissing'], $this->codes($card(['acc_method' => 'time'])));
        $this->assertSame([], $this->codes($card(['acc_method' => 'time', 'acc_months' => 60])));
        $this->assertSame(
            ['acc_method:asTaxWithoutFormula'],
            $this->codes($card(['tax_method' => 'none', 'tax_rule' => null])),
        );
        $this->assertSame(
            ['acc_method:accountingWithoutAccMethod'],
            $this->codes($card(['tax_method' => 'accounting', 'tax_rule' => null])),
        );
        $this->assertSame([], $this->codes($card(['tax_method' => 'accounting', 'tax_rule' => null, 'acc_method' => 'time', 'acc_months' => 36])));
    }

    public function testRuleValidityFollowsAcquisitionDate(): void
    {
        // Mimořádné odpisy sk. 2 (2020–2023): před zařazením se platnost
        // k datu neřeší, po zařazení v roce 2025 pravidlo neplatí.
        $card = ['tax_method' => 'extraordinary', 'tax_rule' => 'cz-30a-2'] + $this->tangibleAsset();
        $this->assertSame([], $this->codes($card));

        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'category' => 'tangible', 'acquired_date' => '2025-03-15'] + $card;
        $data = ['id' => 3] + $card;
        $this->assertSame(['tax_rule'], array_column($doc->validate($data)->toArray(), 'column'));
    }

    public function testTaxMethodLockedAfterFirstTaxDepreciation(): void
    {
        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'acquired_date' => '2022-03-15'] + $this->tangibleAsset();
        $doc->eventKinds = [['event_kind' => 'activation', 'scope' => 'both'], ['event_kind' => 'depreciation', 'scope' => 'tax']];

        $data = ['id' => 3, 'tax_method' => 'accelerated', 'acc_method' => 'time', 'acc_months' => 60] + $this->tangibleAsset();
        $codes = array_map(static fn(array $e): string => $e['column'] . ':' . $e['code'], $doc->validate($data)->toArray());
        $this->assertSame(['tax_method:taxMethodLocked'], $codes);

        // Bez daňového odpisu (jen účetní) změna metody projde.
        $doc->eventKinds = [['event_kind' => 'depreciation', 'scope' => 'acc']];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // --- data z událostí (D38) ------------------------------------------------

    public function testLongTermDatesComeFromStoredCardNotPayload(): void
    {
        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'acquired_date' => '2022-03-15', 'disposed_date' => null] + $this->tangibleAsset();

        $data = ['id' => 3, 'acquired_date' => '2020-01-01', 'disposed_date' => '2021-01-01'] + $this->tangibleAsset();
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame('2022-03-15', $data['acquired_date']);
        $this->assertNull($data['disposed_date']);

        $doc->beforeSave($data, $doc->cardRow);
        $this->assertSame('2022-03-15', $data['acquired_date']);
        $this->assertNull($data['disposed_date']);

        // Nová dlouhodobá karta: bez událostí bez dat.
        $new = ['acquired_date' => '2020-01-01'] + $this->tangibleAsset();
        $this->assertTrue($this->doc()->validate($new)->isValid());
        $this->assertNull($new['acquired_date']);
    }

    public function testSmallAssetKeepsManualDates(): void
    {
        $data = ['acquired_date' => '2020-01-01'] + $this->smallAsset();
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertSame('2020-01-01', $data['acquired_date']);
    }

    public function testLongTermArchiveNeedsConfirmedDisposal(): void
    {
        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'acquired_date' => '2022-03-15', 'disposed_date' => null] + $this->tangibleAsset();
        $data = ['id' => 3, 'docState' => 70] + $this->tangibleAsset();
        $this->assertSame(['_form:disposedDateRequired'], array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        ));

        $doc->cardRow['disposed_date'] = '2024-05-10';
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testDisposedLongTermCannotReturnToConfirmed(): void
    {
        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'acquired_date' => '2022-03-15', 'disposed_date' => '2024-05-10', 'docState' => 80] + $this->tangibleAsset();
        $data = ['id' => 3, 'docState' => 40] + $this->tangibleAsset();

        $this->assertSame(['_form'], array_column($doc->validate($data)->toArray(), 'column'));
        $this->assertSame('disposedAssetActive', $doc->validate($data)->toArray()[0]['code']);
    }

    public function testConfirmedEventsLockCategoryAndDeletion(): void
    {
        $doc = $this->doc();
        $doc->cardRow = ['id' => 3, 'acquired_date' => '2022-03-15'] + $this->tangibleAsset();
        $doc->eventKinds = [['event_kind' => 'activation', 'scope' => 'both']];

        $deleted = ['id' => 3, 'docState' => 90] + $this->tangibleAsset();
        $this->assertSame(['hasConfirmedEvents'], array_column($doc->validate($deleted)->toArray(), 'code'));

        $changed = ['id' => 3, 'category' => 'small', 'accounting_group' => null] + $this->tangibleAsset();
        $this->assertContains('categoryLockedByEvents', array_column($doc->validate($changed)->toArray(), 'code'));
    }

    // --- beforeSave: nastavení ------------------------------------------------

    public function testNonDepreciableCategoryClearsDepreciationSettings(): void
    {
        $data = ['category' => 'nondepreciable'] + $this->tangibleAsset();
        $this->doc()->beforeSave($data, null);

        foreach (['tax_method', 'tax_rule', 'acc_method', 'acc_months'] as $col) {
            $this->assertNull($data[$col], $col);
        }
    }

    public function testRuleAndMonthsAreKeptOnlyWhereTheyApply(): void
    {
        $data = ['tax_method' => 'accounting', 'tax_rule' => 'cz-2', 'acc_method' => 'time', 'acc_months' => '36'] + $this->tangibleAsset();
        $this->doc()->beforeSave($data, null);
        $this->assertNull($data['tax_rule']);
        $this->assertSame(36, $data['acc_months']);

        $data = ['acc_months' => 36] + $this->tangibleAsset();
        $this->doc()->beforeSave($data, null);
        $this->assertSame('cz-2', $data['tax_rule']);
        $this->assertNull($data['acc_months']);
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

    /** Uložený řádek karty (validate s id). */
    public ?array $cardRow = null;
    /** Účty účetní skupiny karty; null = skupina neexistuje. */
    public ?array $accountingGroup = ['account_depreciation' => 551, 'account_accumulated' => 82];
    /** @var list<int> dotazy na účetní skupinu */
    public array $groupQueries = [];
    /** @var list<array{event_kind: string, scope: string}> */
    public array $eventKinds = [];

    protected function loadCardRow(int $id): ?array
    {
        return $this->cardRow;
    }

    protected function loadConfirmedEventKinds(int $assetId): array
    {
        return $this->eventKinds;
    }

    protected function loadAccountingGroup(int $id): ?array
    {
        $this->groupQueries[] = $id;
        return $this->accountingGroup;
    }

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
