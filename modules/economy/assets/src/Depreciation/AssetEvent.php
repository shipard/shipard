<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Hodnotová událost majetku (D3, D28) — vstup enginu. Odpovídá řádku
 * `economy_assets_events`, engine ale tabulku nezná.
 *
 * `amount`: vstupní cena (zařazení, počáteční stav), změna vstupní ceny
 * (technické zhodnocení, snížení) nebo odpis. Počáteční stav (D16) nese
 * navíc oprávky, `unitsDone` (roky u ročních daňových metod, měsíce
 * u časových, mimořádných a účetních; u zrychleného odpisu se zvýšenou
 * cenou roky odpisované ze zvýšené zůstatkové ceny), příznak zvýšené ceny
 * a datum původního zařazení.
 */
final readonly class AssetEvent
{
    public const KIND_OPENING = 'opening';
    public const KIND_ACTIVATION = 'activation';
    public const KIND_IMPROVEMENT = 'improvement';
    public const KIND_REDUCTION = 'reduction';
    public const KIND_DEPRECIATION = 'depreciation';
    public const KIND_INTERRUPTION = 'interruption';
    public const KIND_DISPOSAL = 'disposal';

    public const SCOPE_BOTH = 'both';
    public const SCOPE_TAX = 'tax';
    public const SCOPE_ACC = 'acc';

    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_SYSTEM = 'system';
    public const ORIGIN_IMPORT = 'import';

    /** Pořadí událostí téhož dne. */
    private const KIND_ORDER = [
        self::KIND_OPENING => 0,
        self::KIND_ACTIVATION => 0,
        self::KIND_IMPROVEMENT => 1,
        self::KIND_REDUCTION => 1,
        self::KIND_INTERRUPTION => 2,
        self::KIND_DEPRECIATION => 3,
        self::KIND_DISPOSAL => 4,
    ];

    public function __construct(
        public string $kind,
        public string $scope,
        public string $date,
        public float $amount = 0.0,
        public ?string $periodBegin = null,
        public ?string $periodEnd = null,
        public bool $confirmed = true,
        public string $origin = self::ORIGIN_MANUAL,
        public ?float $accumulated = null,
        public ?int $unitsDone = null,
        public bool $priceIncreased = false,
        public ?string $originalDate = null,
        /** D11: uplatněná částka neevidována (jen import). */
        public bool $claimUnrecorded = false,
        /** Vyřazení: uplatnit polovinu; odpis: odpis roku vyřazení (D35). */
        public bool $halfYear = false,
        public ?int $id = null,
    ) {
        if (!isset(self::KIND_ORDER[$kind])) {
            throw new \InvalidArgumentException("Neznámý druh události '{$kind}'");
        }
        if (!in_array($scope, [self::SCOPE_BOTH, self::SCOPE_TAX, self::SCOPE_ACC], true)) {
            throw new \InvalidArgumentException("Neznámý okruh události '{$scope}'");
        }
        foreach ([$date, $periodBegin, $periodEnd, $originalDate] as $value) {
            if ($value !== null && !Period::isDate($value)) {
                throw new \InvalidArgumentException("Neplatné datum události '{$value}'");
            }
        }
        if ($amount < 0) {
            throw new \InvalidArgumentException('Částka události nesmí být záporná');
        }
    }

    /**
     * Klíče = sloupce `economy_assets_events` (`event_kind`, `scope`,
     * `event_date`, `period_begin`, `period_end`, `amount`, `accumulated`,
     * `units_done`, `price_increased`, `original_date`, `half_year`,
     * `claim_unrecorded`, `origin`, `id`) + `confirmed` (výchozí true).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $date = static fn(string $key): ?string
            => ($data[$key] ?? '') === '' ? null : substr((string) $data[$key], 0, 10);

        return new self(
            kind: (string) ($data['event_kind'] ?? ''),
            scope: (string) ($data['scope'] ?? self::SCOPE_BOTH),
            date: $date('event_date') ?? '',
            amount: (float) ($data['amount'] ?? 0),
            periodBegin: $date('period_begin'),
            periodEnd: $date('period_end'),
            confirmed: (bool) ($data['confirmed'] ?? true),
            origin: (string) ($data['origin'] ?? self::ORIGIN_MANUAL),
            accumulated: isset($data['accumulated']) ? (float) $data['accumulated'] : null,
            unitsDone: isset($data['units_done']) ? (int) $data['units_done'] : null,
            priceIncreased: (bool) ($data['price_increased'] ?? false),
            originalDate: $date('original_date'),
            claimUnrecorded: (bool) ($data['claim_unrecorded'] ?? false),
            halfYear: (bool) ($data['half_year'] ?? false),
            id: isset($data['id']) ? (int) $data['id'] : null,
        );
    }

    /** Patří událost do okruhu (`tax` / `acc`)? `both` patří do obou. */
    public function inCircuit(string $circuit): bool
    {
        return $this->scope === self::SCOPE_BOTH || $this->scope === $circuit;
    }

    public function isStart(): bool
    {
        return $this->kind === self::KIND_ACTIVATION || $this->kind === self::KIND_OPENING;
    }

    public function sortOrder(): int
    {
        return self::KIND_ORDER[$this->kind];
    }

    /** Pořadí druhu mezi událostmi téhož dne; neznámý druh až za všemi. */
    public static function kindOrder(string $kind): int
    {
        return self::KIND_ORDER[$kind] ?? PHP_INT_MAX;
    }

    public static function isKind(string $kind): bool
    {
        return isset(self::KIND_ORDER[$kind]);
    }
}
