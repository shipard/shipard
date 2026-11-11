<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Jediný výstup report enginu (D4): samonosný strukturovaný výsledek —
 * metadata + definice sloupců + plochý seznam řádků. Všechny prezentace
 * (REST, budoucí viewer, MCP, diff) jsou renderery nad tímto tvarem.
 *
 * `status` se nezadává — odvozuje se z nejvyšší severity zpráv (D15).
 * Tvar `toArray()`: docs/reports.md §3.1.
 */
final class ReportResult
{
    public readonly ReportStatus $status;
    public readonly \DateTimeImmutable $generatedAt;

    /**
     * @param array<string, mixed> $params Normalizované parametry běhu
     *                                     (vč. klíče `period`).
     * @param string $dataSource Id zdroje dat (`xxxx-xxxx-xxxx-xxxx`).
     * @param ReportMessage[] $messages
     * @param ReportColumn[] $columns
     * @param ReportRow[] $rows
     * @param ?string $accountLabel Popisek sloupce s číslem řádku (pole
     *        `account`), když v něm report nenese číslo účtu (#133 D68).
     *        Builder ho nezadává — doplňuje ho `ReportRunner` z definice
     *        reportu přes `withAccountLabel()`. Null = výchozí „Účet“.
     */
    public function __construct(
        public readonly string $reportId,
        public readonly array $params,
        public readonly string $dataSource,
        public readonly array $messages,
        public readonly array $columns,
        public readonly array $rows,
        ?\DateTimeImmutable $generatedAt = null,
        public readonly ?string $accountLabel = null,
    ) {
        $this->status      = ReportStatus::fromMessages($messages);
        $this->generatedAt = $generatedAt ?? new \DateTimeImmutable();
    }

    /** Kopie výsledku s popiskem sloupce s číslem; čas vytvoření zůstává. */
    public function withAccountLabel(?string $accountLabel): self
    {
        return new self(
            reportId: $this->reportId,
            params: $this->params,
            dataSource: $this->dataSource,
            messages: $this->messages,
            columns: $this->columns,
            rows: $this->rows,
            generatedAt: $this->generatedAt,
            accountLabel: $accountLabel,
        );
    }

    /**
     * `accountLabel` je ve výstupu jen když je vyplněný — tvar účtových
     * reportů (REST, MCP, `report-diff`) se nemění.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'reportId'    => $this->reportId,
            'params'      => $this->params,
            'generatedAt' => $this->generatedAt->format(\DateTimeInterface::ATOM),
            'dataSource'  => $this->dataSource,
            'status'      => $this->status->value,
            'messages'    => array_map(static fn (ReportMessage $m): array => $m->toArray(), $this->messages),
            'columns'     => array_map(static fn (ReportColumn $c): array => $c->toArray(), $this->columns),
        ];
        if ($this->accountLabel !== null) {
            $out['accountLabel'] = $this->accountLabel;
        }
        $out['rows'] = array_map(static fn (ReportRow $r): array => $r->toArray(), $this->rows);

        return $out;
    }
}
