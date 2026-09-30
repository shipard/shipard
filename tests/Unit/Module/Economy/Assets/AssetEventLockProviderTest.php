<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetEventLockProvider;

/**
 * Zámek potvrzené události (D29): append-from-end per okruh (`both` = oba),
 * zámek účetního měsíce jen pro potvrzenou nebo potvrzovanou událost.
 */
class AssetEventLockProviderTest extends TestCase
{
    private TestableAssetEventLockProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new TestableAssetEventLockProvider();
        $this->provider->setDb($this->createMock(\Dibi\Connection::class));
    }

    /** @param array<string, mixed> $o */
    private function event(string $kind, string $scope, string $date, array $o = []): array
    {
        return $o + [
            'id' => 1, 'asset' => 5, 'event_kind' => $kind, 'scope' => $scope,
            'event_date' => $date, 'docState' => 40,
        ];
    }

    private function depreciation(int $id, string $scope, string $date, string $periodEnd): array
    {
        return [
            'id' => $id, 'asset' => 5, 'event_kind' => 'depreciation', 'scope' => $scope,
            'event_date' => $date, 'period_end' => $periodEnd,
        ];
    }

    /** @return list<string> */
    private function sources(array $data, ?array $original): array
    {
        return array_map(
            static fn($r): string => $r->source,
            $this->provider->lockReasons('economy_assets_events', $data, $original),
        );
    }

    public function testConfirmedEventWithLaterDepreciationIsLocked(): void
    {
        $improvement = $this->event('improvement', 'both', '2023-05-10');
        $this->provider->depreciations = [$this->depreciation(9, 'tax', '2023-12-31', '2023-12-31')];

        $reasons = $this->provider->lockReasons('economy_assets_events', $improvement, $improvement);
        $this->assertCount(1, $reasons);
        $this->assertSame(AssetEventLockProvider::SOURCE_HISTORY, $reasons[0]->source);
        $this->assertSame(454, $reasons[0]->subjectTableId);
        $this->assertSame(9, $reasons[0]->subjectRowId);
        $this->assertSame('2023-12-31', $reasons[0]->params['periodEnd']);
        $this->assertSame([5, '2023-05-10', 1], $this->provider->lastQuery);
    }

    public function testDepreciationOfOtherCircuitDoesNotLock(): void
    {
        $taxDepreciation = $this->event('depreciation', 'tax', '2022-12-31');
        $this->provider->depreciations = [$this->depreciation(9, 'acc', '2023-12-31', '2023-12-31')];
        $this->assertSame([], $this->sources($taxDepreciation, $taxDepreciation));

        $this->provider->depreciations[] = $this->depreciation(10, 'tax', '2023-12-31', '2023-12-31');
        $this->assertSame([AssetEventLockProvider::SOURCE_HISTORY], $this->sources($taxDepreciation, $taxDepreciation));
    }

    public function testEventIsNotLockedByDepreciationBeforeItOnTheSameDay(): void
    {
        // Poslední odpisy vzniklé s vyřazením mají datum vyřazení, ale jsou
        // v historii před ním — vyřazení jde zrušit.
        $disposal = $this->event('disposal', 'both', '2024-05-10', ['id' => 50]);
        $this->provider->depreciations = [
            $this->depreciation(51, 'tax', '2024-05-10', '2024-12-31'),
            $this->depreciation(52, 'acc', '2024-05-10', '2024-05-31'),
        ];
        $this->assertSame([], $this->sources($disposal, $disposal));

        // Odpis téhož dne za TZ (pořadí druhu) ale TZ zamyká.
        $improvement = $this->event('improvement', 'both', '2024-05-10', ['id' => 40]);
        $this->assertSame([AssetEventLockProvider::SOURCE_HISTORY], $this->sources($improvement, $improvement));
    }

    public function testDraftAndDeletedEventsAreNotLockedByHistory(): void
    {
        $this->provider->depreciations = [$this->depreciation(9, 'tax', '2023-12-31', '2023-12-31')];
        foreach ([10, 80, 90] as $state) {
            $event = $this->event('improvement', 'both', '2023-05-10', ['docState' => $state]);
            $this->assertSame([], $this->sources($event, $event), "stav {$state}");
        }
        // Nová událost (bez originálu) — pořadí hlídá validace, ne zámek.
        $this->assertSame([], $this->sources($this->event('improvement', 'both', '2023-05-10', ['id' => null]), null));
    }

    public function testLockedMonthLocksConfirmedAndConfirmingEvents(): void
    {
        $this->provider->lockedMonths = ['2023-05'];

        $confirmed = $this->event('improvement', 'both', '2023-05-10');
        $reasons = $this->provider->lockReasons('economy_assets_events', $confirmed, $confirmed);
        $this->assertCount(1, $reasons);
        $this->assertSame(AssetEventLockProvider::SOURCE_MONTH, $reasons[0]->source);
        $this->assertSame(314, $reasons[0]->subjectTableId);
        $this->assertSame('2023/05', $reasons[0]->params['label']);

        // Koncept v zamčeném měsíci zamčený není; jeho potvrzení ano.
        $draft = $this->event('improvement', 'both', '2023-05-10', ['docState' => 10]);
        $this->assertSame([], $this->sources($draft, $draft));
        $this->assertSame([AssetEventLockProvider::SOURCE_MONTH], $this->sources(['docState' => 40] + $draft, $draft));
        $this->assertSame([AssetEventLockProvider::SOURCE_MONTH], $this->sources(['docState' => 40] + $draft, null));

        // Přesun potvrzené události ze zamčeného měsíce: zamčený je původní i nový měsíc.
        $this->provider->lockedMonths = ['2023-05', '2023-06'];
        $moved = ['event_date' => '2023-06-10'] + $confirmed;
        $this->assertSame(
            [AssetEventLockProvider::SOURCE_MONTH, AssetEventLockProvider::SOURCE_MONTH],
            $this->sources($moved, $confirmed),
        );
    }

    public function testWithoutDbNothingIsLocked(): void
    {
        $provider = new TestableAssetEventLockProvider();
        $provider->depreciations = [$this->depreciation(9, 'tax', '2023-12-31', '2023-12-31')];
        $event = $this->event('improvement', 'both', '2023-05-10');
        $this->assertSame([], $provider->lockReasons('economy_assets_events', $event, $event));
    }
}

class TestableAssetEventLockProvider extends AssetEventLockProvider
{
    /** @var list<array<string, mixed>> potvrzené odpisy karty */
    public array $depreciations = [];
    /** @var list<string> zamčené měsíce `Y-m` */
    public array $lockedMonths = [];
    /** @var array{int, string, int}|null */
    public ?array $lastQuery = null;

    protected function confirmedDepreciationsFrom(int $assetId, string $fromDate, int $excludeId): array
    {
        $this->lastQuery = [$assetId, $fromDate, $excludeId];
        return array_values(array_filter(
            $this->depreciations,
            static fn(array $d): bool => $d['asset'] === $assetId && $d['event_date'] >= $fromDate && $d['id'] !== $excludeId,
        ));
    }

    protected function lockedMonthForDate(string $date): ?array
    {
        $month = substr($date, 0, 7);
        if (!in_array($month, $this->lockedMonths, true)) {
            return null;
        }
        return ['id' => 300 + (int) substr($month, 5, 2), 'calendar_year' => (int) substr($month, 0, 4), 'calendar_month' => (int) substr($month, 5, 2)];
    }
}
