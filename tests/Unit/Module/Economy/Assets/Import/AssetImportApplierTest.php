<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\Assets\Import\AssetImportApplier;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetPlanService;

/** Applier nad pamětí: zápisy dokumentů se zaznamenávají, DB nahrazují pole. */
class RecordingAssetImportApplier extends AssetImportApplier
{
    /** @var array<string, array{id: int, docState: int, category: string}> inv. číslo → karta */
    public array $cards = [];
    /** @var list<int> karty s ručními / systémovými událostmi */
    public array $localEvents = [];
    /** @var array<int, array{account_depreciation: ?int, account_accumulated: ?int}> */
    public array $groups = [
        1 => ['account_depreciation' => 551, 'account_accumulated' => 82],
        2 => ['account_depreciation' => null, 'account_accumulated' => null],
    ];
    /** @var array<string, list<int>> tabulka → existující id */
    public array $references = [
        'economy_assets_types'             => [12],
        'economy_assets_accounting_groups' => [1, 2],
        'base_persons_persons'             => [7],
    ];
    /** @var list<array<string, mixed>> */
    public array $savedCards = [];
    /** @var list<array<string, mixed>> */
    public array $savedEvents = [];
    /** @var list<int> */
    public array $deletedEvents = [];
    /** @var list<int> */
    public array $resetDates = [];
    /** @var list<string> */
    public array $log = [];
    /** @var list<array{code: string, message: string}> */
    public array $planWarnings = [];
    /** @var \Closure(array<string, mixed>, int): ?ValidationResult|null odmítnutí n-té ukládané události */
    public ?\Closure $eventFailure = null;
    public ?ValidationResult $cardFailure = null;

    private int $nextId = 500;

    public function __construct()
    {
        $def = static fn(int $tableId, string $name): TableDefinition => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name'    => $name,
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true]],
        ]);
        parent::__construct(
            null,
            TestAssetPlanService::config(),
            null,
            new DocumentRegistry(),
            ['economy_assets_assets' => $def(450, 'Assets'), 'economy_assets_events' => $def(454, 'Events')],
            new SchemaValidator(SchemaLoader::default()),
        );
    }

    protected function saveCard(array $head): DocumentResult
    {
        $this->savedCards[] = $head;
        if ($this->cardFailure !== null) {
            return DocumentResult::validationFailed($this->cardFailure);
        }
        return DocumentResult::ok(['id' => $head['id'] ?? $this->nextId++]);
    }

    protected function saveEvent(array $event): DocumentResult
    {
        $this->savedEvents[] = $event;
        $failure = $this->eventFailure !== null ? ($this->eventFailure)($event, count($this->savedEvents)) : null;
        if ($failure !== null) {
            return DocumentResult::validationFailed($failure);
        }
        return DocumentResult::ok(['id' => $this->nextId++]);
    }

    protected function planWarnings(int $assetId): array
    {
        return $this->planWarnings;
    }

    protected function findCard(string $number): ?array
    {
        return $this->cards[$number] ?? null;
    }

    protected function hasLocalEvents(int $assetId): bool
    {
        return in_array($assetId, $this->localEvents, true);
    }

    protected function deleteImportedEvents(int $assetId): void
    {
        $this->deletedEvents[] = $assetId;
    }

    protected function resetCardDates(int $assetId): void
    {
        $this->resetDates[] = $assetId;
    }

    protected function referenceExists(string $table, int $id): bool
    {
        return in_array($id, $this->references[$table] ?? [], true);
    }

    protected function loadGroupAccounts(int $id): ?array
    {
        return $this->groups[$id] ?? null;
    }

    protected function begin(): void
    {
        $this->log[] = 'begin';
    }

    protected function commit(): void
    {
        $this->log[] = 'commit';
    }

    protected function rollback(): void
    {
        $this->log[] = 'rollback';
    }
}

/**
 * Import karty majetku `shpd.assets.asset.v1` (docs/assets.md D75, D79,
 * §5.7): založení, přepis, přeskočení karty s místními událostmi, koncept
 * bez úplné účetní skupiny, dry-run, chyby s cestou a `sourceRef`.
 * Pravidla dokumentů v importním módu kryjí testy dokumentů.
 */
class AssetImportApplierTest extends TestCase
{
    private RecordingAssetImportApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new RecordingAssetImportApplier();
    }

    /**
     * @param array<string, mixed> $asset
     * @param list<array<string, mixed>>|null $events
     * @return array<string, mixed>
     */
    private function payload(array $asset = [], ?array $events = null): array
    {
        return [
            'format' => 'shpd.assets.asset.v1',
            'asset'  => $asset + [
                'assetNumber' => 'MA0007', 'name' => 'Soustruh', 'shortName' => null, 'note' => null,
                'type' => 12, 'category' => 'tangible', 'tracking' => 'single', 'accountingGroup' => 1,
                'foreign' => false, 'owner' => null, 'acquiredDate' => null, 'disposedDate' => null, 'price' => null,
                'taxMethod' => 'straight', 'taxRule' => 'cz-2', 'accMethod' => 'as_tax', 'accMonths' => null,
                'state' => 'confirmed',
            ],
            'events' => $events ?? [
                // Záměrně mimo pořadí — applier řadí chronologicky.
                ['kind' => 'depreciation', 'scope' => 'tax', 'date' => '2022-12-31', 'periodBegin' => '2022-01-01', 'periodEnd' => '2022-12-31',
                    'amount' => 11000.4, 'claimUnrecorded' => true, 'halfYear' => false, 'sourceRef' => 'deps:1'],
                ['kind' => 'activation', 'scope' => 'both', 'date' => '2022-03-15', 'amount' => 100000, 'sourceRef' => 'row:1'],
                ['kind' => 'depreciation', 'scope' => 'acc', 'date' => '2022-12-31', 'periodBegin' => '2022-01-01', 'periodEnd' => '2022-12-31',
                    'amount' => 11000, 'sourceRef' => 'deps:2'],
            ],
        ];
    }

    public function testCreatesCardAndEventsChronologically(): void
    {
        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->statusCode);
        $this->assertSame(['status' => 'created', 'assetId' => 500, 'warnings' => []], $result->toArray());
        $this->assertSame(['begin', 'commit'], $this->applier->log);

        $head = $this->applier->savedCards[0];
        $this->assertSame('MA0007', $head['asset_number']);
        $this->assertSame(12, $head['asset_type']);
        $this->assertSame(1, $head['accounting_group']);
        $this->assertSame(0, $head['is_foreign']);
        $this->assertSame(40, $head['docState']);
        $this->assertTrue($head['_import']);
        $this->assertArrayNotHasKey('id', $head);
        $this->assertArrayNotHasKey('sourceRef', $head);

        $events = $this->applier->savedEvents;
        $this->assertSame(['activation', 'depreciation', 'depreciation'], array_column($events, 'event_kind'));
        $this->assertSame(['both', 'tax', 'acc'], array_column($events, 'scope'));
        $this->assertSame([500, 500, 500], array_column($events, 'asset'));
        $this->assertSame([40, 40, 40], array_column($events, 'docState'));
        $this->assertSame([0, 1, 0], array_column($events, 'claim_unrecorded'));
        $this->assertSame('2022-01-01', $events[1]['period_begin']);
        $this->assertTrue($events[0]['_import']);
        $this->assertArrayNotHasKey('sourceRef', $events[0]);
        $this->assertArrayNotHasKey('origin', $events[0], 'původ nastaví dokument z markeru');
        $this->assertSame([], $this->applier->deletedEvents);
    }

    public function testUpdatesExistingCardAndReplacesImportedEvents(): void
    {
        $this->applier->cards['MA0007'] = ['id' => 31, 'docState' => 70, 'category' => 'tangible'];

        $result = $this->applier->apply($this->payload());

        $this->assertSame(200, $result->statusCode);
        $this->assertSame('updated', $result->status);
        $this->assertSame(31, $result->assetId);
        $this->assertSame([31], $this->applier->deletedEvents);
        $this->assertSame([31], $this->applier->resetDates, 'data dlouhodobé karty dosadí znovu události');
        $this->assertSame(31, $this->applier->savedCards[0]['id']);
        $this->assertSame(40, $this->applier->savedCards[0]['docState']);
        $this->assertSame([31, 31, 31], array_column($this->applier->savedEvents, 'asset'));
        $this->assertSame(['begin', 'commit'], $this->applier->log);
    }

    public function testSkipsCardWithLocalEvents(): void
    {
        $this->applier->cards['MA0007'] = ['id' => 31, 'docState' => 40, 'category' => 'tangible'];
        $this->applier->localEvents = [31];

        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame('skipped', $result->status);
        $this->assertSame(31, $result->assetId);
        $this->assertSame('asset_has_local_events', $result->warnings[0]['code']);
        $this->assertSame([], $this->applier->savedCards);
        $this->assertSame([], $this->applier->deletedEvents);
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testLongTermWithoutCompleteGroupIsStoredAsDraft(): void
    {
        // Bez skupiny.
        $result = $this->applier->apply($this->payload(['accountingGroup' => null]));
        $this->assertTrue($result->success);
        $this->assertSame(10, $this->applier->savedCards[0]['docState']);
        $this->assertSame('accounting_group_incomplete', $result->warnings[0]['code']);
        $this->assertSame('asset.accountingGroup', $result->warnings[0]['path']);
        $this->assertSame([40, 40, 40], array_column($this->applier->savedEvents, 'docState'), 'události jsou potvrzené i na konceptu');

        // Skupina bez účtu odpisů a oprávek (D57).
        $this->applier = new RecordingAssetImportApplier();
        $result = $this->applier->apply($this->payload(['accountingGroup' => 2]));
        $this->assertSame(10, $this->applier->savedCards[0]['docState']);
        $this->assertSame('accounting_group_incomplete', $result->warnings[0]['code']);

        // Neodepisovaný dlouhodobý majetek skupinu bez účtu odpisů mít smí.
        $this->applier = new RecordingAssetImportApplier();
        $result = $this->applier->apply($this->payload(
            ['accountingGroup' => 2, 'category' => 'nondepreciable', 'taxMethod' => null, 'taxRule' => null, 'accMethod' => null],
            [['kind' => 'activation', 'scope' => 'both', 'date' => '2022-03-15', 'amount' => 100000]],
        ));
        $this->assertSame(40, $this->applier->savedCards[0]['docState']);
        $this->assertSame([], $result->warnings);
    }

    public function testValidateRunsEverythingAndRollsBack(): void
    {
        $result = $this->applier->validate($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame('created', $result->status);
        $this->assertCount(1, $this->applier->savedCards);
        $this->assertCount(3, $this->applier->savedEvents);
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testEventValidationFailureRollsBackWithPathAndSourceRef(): void
    {
        $this->applier->eventFailure = static function (array $event, int $n): ?ValidationResult {
            if ($event['scope'] !== 'tax') {
                return null;
            }
            return (new ValidationResult())
                ->addError('amount', 'Odpis nesmí být větší než zůstatková cena okruhu.', 'aboveResidual')
                ->addError('_form', 'Majetek není v tomto okruhu zařazen.', 'notActivated');
        };

        $result = $this->applier->apply($this->payload());

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(
            [
                ['severity' => 'error', 'path' => 'events.0.amount', 'code' => 'aboveResidual',
                    'message' => 'Odpis nesmí být větší než zůstatková cena okruhu.', 'sourceRef' => 'deps:1'],
                ['severity' => 'error', 'path' => 'events.0', 'code' => 'notActivated',
                    'message' => 'Majetek není v tomto okruhu zařazen.', 'sourceRef' => 'deps:1'],
            ],
            $result->issues,
        );
        // Daňový odpis je v pořadí druhý — zařazení prošlo, účetní odpis se už neukládal.
        $this->assertCount(2, $this->applier->savedEvents);
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testCardValidationFailureMapsColumnsToPayloadKeys(): void
    {
        $this->applier->cardFailure = (new ValidationResult())
            ->addError('tax_rule', 'Pravidlo neplatí k datu zařazení.', 'ruleNotValid')
            ->addError('_form', 'Majetek je vyřazen.', 'disposedAssetActive');

        $result = $this->applier->apply($this->payload());

        $this->assertSame(['asset.taxRule', 'asset'], array_column($result->issues, 'path'));
        $this->assertArrayNotHasKey('sourceRef', $result->issues[0]);
        $this->assertSame([], $this->applier->savedEvents);
    }

    public function testSchemaViolationIs400WithSourceRef(): void
    {
        // Validátor hlásí první nález; chyba v události nese sourceRef.
        $payload = $this->payload();
        $payload['events'][0]['kind'] = 'teleport';

        $result = $this->applier->apply($payload);

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame('events.0.kind', $result->issues[0]['path']);
        $this->assertSame('deps:1', $result->issues[0]['sourceRef']);
        $this->assertSame([], $this->applier->log, 'bez transakce');

        $payload = $this->payload();
        unset($payload['asset']['state']);
        $result = $this->applier->apply($payload);
        $this->assertSame('asset', $result->issues[0]['path']);
        $this->assertArrayNotHasKey('sourceRef', $result->issues[0]);
    }

    public function testUnknownReferencesAre422(): void
    {
        $result = $this->applier->apply($this->payload(['type' => 99, 'accountingGroup' => 5, 'owner' => 8, 'foreign' => true]));

        $this->assertSame(422, $result->statusCode);
        $this->assertSame(
            ['asset.type' => 'type_not_found', 'asset.accountingGroup' => 'accounting_group_not_found', 'asset.owner' => 'owner_not_found'],
            array_column($result->issues, 'code', 'path'),
        );
        $this->assertSame([], $this->applier->log);
    }

    public function testArchivedState(): void
    {
        // Dlouhodobá karta: do archivu ji přesune vyřazení; bez něj varování.
        $result = $this->applier->apply($this->payload(['state' => 'archived']));
        $this->assertSame(40, $this->applier->savedCards[0]['docState']);
        $this->assertSame('archived_without_disposal', $result->warnings[0]['code']);

        $this->applier = new RecordingAssetImportApplier();
        $events = $this->payload()['events'];
        $events[] = ['kind' => 'disposal', 'scope' => 'both', 'date' => '2023-06-30', 'amount' => 0, 'halfYear' => true];
        $result = $this->applier->apply($this->payload(['state' => 'archived'], $events));
        $this->assertSame([], $result->warnings);
        $this->assertSame('disposal', end($this->applier->savedEvents)['event_kind']);
        $this->assertSame(1, end($this->applier->savedEvents)['half_year']);

        // Drobný majetek: stav i data přímo na kartě.
        $this->applier = new RecordingAssetImportApplier();
        $this->applier->apply($this->payload(
            ['category' => 'small', 'state' => 'archived', 'accountingGroup' => null, 'acquiredDate' => '2019-05-01',
                'disposedDate' => '2024-02-29', 'price' => 4990, 'taxMethod' => null, 'taxRule' => null, 'accMethod' => null],
            [],
        ));
        $head = $this->applier->savedCards[0];
        $this->assertSame(70, $head['docState']);
        $this->assertSame('2024-02-29', $head['disposed_date']);
        $this->assertSame(4990, $head['price']);
    }

    public function testLongTermHeadDropsDatesAndPrice(): void
    {
        $this->applier->apply($this->payload(['acquiredDate' => '2019-05-01', 'disposedDate' => '2024-02-29', 'price' => 1]));

        $head = $this->applier->savedCards[0];
        $this->assertNull($head['acquired_date']);
        $this->assertNull($head['disposed_date']);
        $this->assertNull($head['price']);
    }

    public function testPlanWarningsArePassedThrough(): void
    {
        $this->applier->planWarnings = [['code' => 'plan_error', 'message' => 'Plán daňových odpisů: chybí období 2023.']];

        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame('plan_error', $result->warnings[0]['code']);
        $this->assertSame(['begin', 'commit'], $this->applier->log);
    }
}
