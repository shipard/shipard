<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

/**
 * Co vyhodnocení potřebuje o dokladu vědět (#171 fáze 2) — hlavička, řádky,
 * číselná řada a země registrace DPH v jednom neměnném objektu. Skládá ho
 * {@see DocFactsLoader} z databáze; vyhodnocení samo databázi nečte.
 */
final readonly class DocFacts
{
    public const int STATE_CONFIRMED = 40;
    public const int STATE_CANCELLED = 30;

    /**
     * @param list<array{operation: ?string, cents: int}> $rows běžné řádky dokladu: pohyb a částka
     *        s DPH v domácí měně (haléře); textové řádky tu nejsou
     */
    public function __construct(
        public int $id,
        public string $docType,
        public int $docState,
        public string $docNumber,
        public string $issueDate,
        public int $paymentMethod,
        public int $cashDir,
        public int $totalCents,
        public string $homeCurrency,
        public bool $imported,
        public ?string $sourceKind,
        public string $country,
        public ?string $seriesCode,
        public ?int $seriesUnit,
        public ?string $stateChangedAt,
        public array $rows = [],
    ) {
    }

    /**
     * @param array<string, mixed>           $head    řádek docs_core_heads
     * @param list<array<string, mixed>>     $rows    řádky docs_core_rows (`operation`, `vat_total_dom`)
     * @param array<string, mixed>|null      $series  řádek číselné řady (`code`, `sales_registration_unit`)
     * @param string                         $country země registrace DPH dokladu, bez ní domácí země zdroje dat
     */
    public static function fromRows(array $head, array $rows, ?array $series, string $country): self
    {
        $facts = [];
        foreach ($rows as $row) {
            $operation = trim((string) ($row['operation'] ?? ''));
            $facts[] = [
                'operation' => $operation !== '' ? $operation : null,
                'cents'     => Money::cents($row['vat_total_dom'] ?? null),
            ];
        }
        $unit = (int) ($series['sales_registration_unit'] ?? 0);
        $code = trim((string) ($series['code'] ?? ''));
        $sourceKind = trim((string) ($head['source_kind'] ?? ''));

        return new self(
            id: (int) ($head['id'] ?? 0),
            docType: (string) ($head['doc_type'] ?? ''),
            docState: (int) ($head['docState'] ?? 0),
            docNumber: trim((string) ($head['doc_number'] ?? '')),
            issueDate: self::date($head['issue_date'] ?? null),
            paymentMethod: (int) ($head['payment_method'] ?? -1),
            cashDir: (int) ($head['cash_dir'] ?? 0),
            totalCents: Money::cents($head['total_amount_dom'] ?? null),
            homeCurrency: strtolower(trim((string) ($head['home_currency'] ?? ''))),
            imported: !empty($head['imported']),
            sourceKind: $sourceKind !== '' ? $sourceKind : null,
            country: strtolower(trim($country)),
            seriesCode: $code !== '' ? $code : null,
            seriesUnit: $unit > 0 ? $unit : null,
            stateChangedAt: self::dateTime($head['doc_state_changed_at'] ?? null),
            rows: $facts,
        );
    }

    /** Doklad převzatý importem (#171 D8e, U11): trvalý příznak, nebo druh zdroje `import.*`. */
    public function isImported(): bool
    {
        return $this->imported || ($this->sourceKind !== null && str_starts_with($this->sourceKind, 'import.'));
    }

    private static function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_scalar($value) ? substr(trim((string) $value), 0, 10) : '';
    }

    private static function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        return $text !== '' ? str_replace('T', ' ', substr($text, 0, 19)) : null;
    }
}
