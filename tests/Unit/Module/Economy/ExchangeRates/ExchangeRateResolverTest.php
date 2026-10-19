<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListTypes;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateLookup;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Pravidlo dohledání (#121 D4, D5, U1, U2; tasks/exchange-rates-core.md §11):
 * poslední lístek ≤ D, víkend, den před vyhlášením, lístek starší než
 * maxAgeDays, měsíční po valid_to, měna chybí v denním → měsíční typ,
 * domácí měna → 1, kurz za jednotku u amount 100 a 1000, memoizace.
 */
class ExchangeRateResolverTest extends TestCase
{
    /** Lístky: čtvrtek 15. 10., pátek 16. 10., pátek 23. 10. (denní), říjen (měsíční). */
    private const LISTS = [
        ['id' => 1, 'list_type' => 'cz.cnb.daily', 'valid_from' => '2026-10-15', 'list_number' => '200', 'values' => ['eur' => ['amount' => 1, 'rate' => 24.4], 'gbp' => ['amount' => 1, 'rate' => 28.1]]],
        ['id' => 2, 'list_type' => 'cz.cnb.daily', 'valid_from' => '2026-10-16', 'list_number' => '201', 'values' => ['eur' => ['amount' => 1, 'rate' => 24.325], 'jpy' => ['amount' => 100, 'rate' => 15.871]]],
        ['id' => 3, 'list_type' => 'cz.cnb.other', 'valid_from' => '2026-10-01', 'valid_to' => '2026-10-31', 'list_number' => '10', 'values' => ['thb' => ['amount' => 100, 'rate' => 69.5], 'idr' => ['amount' => 1000, 'rate' => 1.4]]],
        ['id' => 4, 'list_type' => 'cz.cnb.daily', 'valid_from' => '2026-10-23', 'list_number' => '206', 'values' => ['eur' => ['amount' => 1, 'rate' => 24.2]]],
    ];

    /** @return ExchangeRateResolver&object{listQueries: int} */
    private function resolver(array $lists = self::LISTS, string $home = 'czk', ?array $types = null): ExchangeRateResolver
    {
        $types ??= [
            new ExchangeRateListType('cz.cnb.daily', 'ČNB denní', 'czk', 7),
            new ExchangeRateListType('cz.cnb.other', 'ČNB ostatní', 'czk', null),
        ];
        return new class($this->createMock(Connection::class), new ExchangeRateListTypes($types), $home, $lists) extends ExchangeRateResolver {
            public int $listQueries = 0;

            public function __construct(Connection $db, ExchangeRateListTypes $types, string $home, private readonly array $lists)
            {
                parent::__construct($db, $types, $home);
            }

            protected function latestList(string $listType, string $date): ?array
            {
                $this->listQueries++;
                $best = null;
                foreach ($this->lists as $list) {
                    if ($list['list_type'] !== $listType || $list['valid_from'] > $date) {
                        continue;
                    }
                    if ($best === null || $list['valid_from'] > $best['valid_from']) {
                        $best = $list;
                    }
                }
                if ($best === null) {
                    return null;
                }
                return ['id' => $best['id'], 'valid_from' => $best['valid_from'], 'valid_to' => $best['valid_to'] ?? null, 'list_number' => $best['list_number'] ?? null];
            }

            protected function listValue(int $listId, string $currency): ?array
            {
                foreach ($this->lists as $list) {
                    if ($list['id'] === $listId) {
                        return $list['values'][$currency] ?? null;
                    }
                }
                return null;
            }
        };
    }

    public function testSaturdayUsesFridayList(): void
    {
        $rate = $this->resolver()->rateFor('EUR', '2026-10-17');

        $this->assertNotNull($rate);
        $this->assertSame(24.325, $rate->rate);
        $this->assertSame('cz.cnb.daily', $rate->listType);
        $this->assertSame('2026-10-16', $rate->validFrom);
        $this->assertSame('201', $rate->listNumber);
        $this->assertSame(2, $rate->listId);
        $this->assertFalse($rate->isHome());
    }

    public function testMondayWithoutMondayListStillUsesFriday(): void
    {
        // D4: dopoledne před vyhlášením platí poslední lístek — záměr, bez kalendáře.
        $this->assertSame(24.325, $this->resolver()->rateFor('eur', '2026-10-19')?->rate);
    }

    public function testDayBeforePublicationUsesPreviousList(): void
    {
        $this->assertSame(24.4, $this->resolver()->rateFor('eur', '2026-10-15')?->rate);
    }

    public function testCurrencyMissingInLatestListTriesNextTypeNeverOlderList(): void
    {
        // GBP je jen ve čtvrtečním lístku; páteční ho nemá → ne starší lístek, ale další typ (U2).
        $lookup = $this->resolver()->lookup('gbp', '2026-10-17');

        $this->assertFalse($lookup->found());
        $this->assertSame(
            [
                ['listType' => 'cz.cnb.daily', 'reason' => ExchangeRateLookup::REASON_CURRENCY_MISSING],
                ['listType' => 'cz.cnb.other', 'reason' => ExchangeRateLookup::REASON_CURRENCY_MISSING],
            ],
            $lookup->reasons,
        );
    }

    public function testCurrencyOnlyInMonthlyListFallsThroughToIt(): void
    {
        $rate = $this->resolver()->rateFor('THB', '2026-10-17');

        $this->assertSame(0.695, $rate?->rate, 'kurz za jednotku z amount 100');
        $this->assertSame('cz.cnb.other', $rate?->listType);
        $this->assertSame(3, $rate?->listId);
        $this->assertSame(0.0014, $this->resolver()->rateFor('idr', '2026-10-17')?->rate, 'amount 1000');
    }

    public function testDailyListIsUsedAtMostMaxAgeDays(): void
    {
        $this->assertSame(24.2, $this->resolver()->rateFor('eur', '2026-10-30')?->rate, 'přesně 7 dní');

        $lookup = $this->resolver()->lookup('eur', '2026-10-31');
        $this->assertFalse($lookup->found(), '8 dní — starý lístek se nepoužije (U1)');
        $this->assertSame(
            [
                ['listType' => 'cz.cnb.daily', 'reason' => ExchangeRateLookup::REASON_LIST_STALE],
                ['listType' => 'cz.cnb.other', 'reason' => ExchangeRateLookup::REASON_CURRENCY_MISSING],
            ],
            $lookup->reasons,
        );
    }

    public function testMonthlyListExpiresAfterValidTo(): void
    {
        $this->assertSame(0.695, $this->resolver()->rateFor('thb', '2026-10-31')?->rate, 'poslední den platnosti');

        $lookup = $this->resolver()->lookup('thb', '2026-11-08');
        $this->assertFalse($lookup->found());
        $this->assertSame(
            [
                ['listType' => 'cz.cnb.daily', 'reason' => ExchangeRateLookup::REASON_LIST_STALE],
                ['listType' => 'cz.cnb.other', 'reason' => ExchangeRateLookup::REASON_LIST_EXPIRED],
            ],
            $lookup->reasons,
        );
    }

    public function testNoListBeforeFirstPublication(): void
    {
        $lookup = $this->resolver()->lookup('eur', '2026-09-01');

        $this->assertFalse($lookup->found());
        $this->assertSame([ExchangeRateLookup::REASON_NO_LIST, ExchangeRateLookup::REASON_NO_LIST], array_column($lookup->reasons, 'reason'));
    }

    public function testHomeCurrencyIsOneWithoutList(): void
    {
        $resolver = $this->resolver();
        $rate = $resolver->rateFor('CZK', '2026-10-17');

        $this->assertSame(1.0, $rate?->rate);
        $this->assertTrue($rate->isHome());
        $this->assertNull($rate->listType);
        $this->assertSame(0, $resolver->listQueries, 'bez dotazu do lístků');
        $this->assertSame('czk', $resolver->homeCurrency());
    }

    public function testNoTypesForHomeCurrencyAndInvalidDate(): void
    {
        $lookup = $this->resolver(types: [new ExchangeRateListType('eu.ecb.daily', 'ECB', 'eur', 7)])->lookup('usd', '2026-10-17');
        $this->assertSame([['listType' => null, 'reason' => ExchangeRateLookup::REASON_NO_TYPES]], $lookup->reasons);

        $lookup = $this->resolver()->lookup('eur', '2026-13-01');
        $this->assertSame([['listType' => null, 'reason' => ExchangeRateLookup::REASON_INVALID_DATE]], $lookup->reasons);
        $this->assertSame('2026-13-01', $lookup->date);
    }

    public function testMemoizesPerCurrencyAndDateAndAcceptsDateTime(): void
    {
        $resolver = $this->resolver();
        $resolver->rateFor('eur', '2026-10-17');
        $resolver->rateFor('EUR ', new \DateTimeImmutable('2026-10-17 15:00'));
        $this->assertSame(1, $resolver->listQueries);

        $resolver->rateFor('eur', '2026-10-18');
        $this->assertSame(2, $resolver->listQueries);
    }

    public function testPerUnitRoundsToSixDecimals(): void
    {
        $this->assertSame(0.158713, ExchangeRate::perUnit('15.8713', 100));
        $this->assertSame(0.001235, ExchangeRate::perUnit(1.2345, 1000));
        $this->assertSame(24.325, ExchangeRate::perUnit(24.325, 0), 'amount 0 jako 1');
    }
}
