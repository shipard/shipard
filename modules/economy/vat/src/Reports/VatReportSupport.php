<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Reports;

use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Module\Economy\Vat\DeductionCoefficientResolver;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\ReportPeriodDocument;
use Shipard\Module\Economy\Vat\VatDocumentSelection;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Sdílené kusy tří živých DPH builderů — kompozice, žádná dědičnost
 * (vzor JournalReportSupport): načtení mapovací konfigurace, číselníku
 * kódů, výběru dokladů dle VatPeriodRange a degradace při chybějícím
 * kompilovaném configu.
 */
final class VatReportSupport
{
    public function mapping(ReportRequest $request): ?VatOutputsMapping
    {
        return VatOutputsMapping::fromConfig($request->config);
    }

    /** @return array<string, array<string, mixed>> Kódy vč. skrytých (párové). */
    public function vatCodes(ReportRequest $request): array
    {
        if ($request->config === null) {
            return [];
        }
        return (new VatRateResolver($request->config))->getVatCodes('cz', null, null, true);
    }

    /** @return list<array<string, mixed>> Doklady přiřazené instanci z VatPeriodRange (D11). */
    public function docs(ReportRequest $request): array
    {
        $range = $request->vatRange;
        if ($range === null) {
            throw new \RuntimeException(
                "Report '{$request->reportId}': missing VatPeriodRange (declare periodSource 'vatPeriod')",
            );
        }
        $column = ReportPeriodDocument::HEAD_COLUMN_BY_TYPE[$range->reportType] ?? null;
        if ($column === null) {
            throw new \RuntimeException("Report '{$request->reportId}': unknown vatReportType '{$range->reportType}'");
        }
        return (new VatDocumentSelection($request->db, $request->config))->load($range->periodId, $column);
    }

    /**
     * Zálohový koeficient odpočtu pro registraci a kalendářní rok začátku
     * instance (#59 D13) — vypořádací období je vždy kalendářní rok.
     *
     * @return array{value: float, source: string, year: int}
     */
    public function deductionCoefficient(ReportRequest $request): array
    {
        $range = $request->vatRange;
        if ($range === null) {
            throw new \RuntimeException(
                "Report '{$request->reportId}': missing VatPeriodRange (declare periodSource 'vatPeriod')",
            );
        }
        $year = (int) substr($range->dateBegin, 0, 4);
        $resolved = (new DeductionCoefficientResolver($request->db))->provisional($range->registrationId, $year);
        return $resolved + ['year' => $year];
    }

    /**
     * Poslední podání instance jako informační zpráva hlavičky (#55 D20) —
     * z živého reportu má být vidět, že a co už bylo za období podáno.
     * Report je pořád živý výpočet: nic z podání do řádků nevstupuje.
     *
     * Reportový systém se kvůli tomu nerozšiřuje — akce „Sestavit podání"
     * žije ve vieweru Daňová tvrzení, kam zpráva odkazuje.
     */
    public function lastFilingMessage(ReportRequest $request, bool $cs): ?ReportMessage
    {
        $range = $request->vatRange;
        if ($range === null) {
            return null;
        }
        // DS upgradovaný jen na Fázi 1 tabulku podání nemá — report tam
        // musí fungovat dál.
        if (!in_array('economy_vat_filings', $request->db->getAllTableNames(), true)) {
            return null;
        }

        $filing = $request->db->fetchRow(
            'SELECT [filing_kind], [date_filed], [result], [docState] FROM [economy_vat_filings]'
            . ' WHERE [report_period] = %i AND [docState] != %i'
            . ' ORDER BY [sequence] DESC, [id] DESC LIMIT 1',
            $range->periodId, FilingDocument::DOC_STATE_CANCELLED,
        );
        if ($filing === null) {
            return new ReportMessage(
                ReportMessageSeverity::Info,
                'vatFiling.none',
                $cs
                    ? 'Za toto období ještě nebylo nic podáno — podání sestavíte v Daňových tvrzeních.'
                    : 'Nothing has been filed for this period yet — compose a filing under VAT report periods.',
            );
        }

        $kindLabel = $this->filingKindLabel($request, (string) $filing['filing_kind']);
        $isDraft   = (int) $filing['docState'] === FilingDocument::DOC_STATE_COMPOSED;
        $filedOn   = $filing['date_filed'] instanceof \DateTimeInterface
            ? $filing['date_filed']->format('Y-m-d')
            : substr((string) ($filing['date_filed'] ?? ''), 0, 10);

        $text = $isDraft
            ? sprintf(
                $cs ? 'Sestavené podání (%s) čeká na podání.' : 'A composed filing (%s) is waiting to be filed.',
                $kindLabel,
            )
            : sprintf(
                $cs ? 'Poslední podání: %s, podáno %s.' : 'Last filing: %s, filed on %s.',
                $kindLabel,
                $filedOn !== '' ? $filedOn : '—',
            );

        $position = $this->filingPositionText($filing['result'] ?? null, $cs);
        if ($position !== null) {
            $text .= ' ' . $position;
        }

        return new ReportMessage(ReportMessageSeverity::Info, 'vatFiling.last', $text);
    }

    /** Podaná daňová povinnost ze souhrnu podání (jen u přiznání). */
    private function filingPositionText(mixed $result, bool $cs): ?string
    {
        $decoded = is_string($result) && $result !== '' ? json_decode($result, true) : null;
        if (!is_array($decoded) || !isset($decoded['return'])) {
            return null;
        }
        $row66 = (float) ($decoded['return']['row66'] ?? 0.0);
        if (abs($row66) >= 0.005) {
            return ($cs ? 'Podaná změna daňové povinnosti (ř. 66): ' : 'Filed change of tax liability (row 66): ')
                . number_format($row66, 2, ',', ' ') . '.';
        }
        $row64 = (float) ($decoded['return']['row64'] ?? 0.0);
        if ($row64 >= 0.005) {
            return ($cs ? 'Podaná vlastní daň (ř. 64): ' : 'Filed tax liability (row 64): ')
                . number_format($row64, 2, ',', ' ') . '.';
        }
        $row65 = (float) ($decoded['return']['row65'] ?? 0.0);
        if ($row65 >= 0.005) {
            return ($cs ? 'Podaný nadměrný odpočet (ř. 65): ' : 'Filed excess deduction (row 65): ')
                . number_format($row65, 2, ',', ' ') . '.';
        }
        return null;
    }

    private function filingKindLabel(ReportRequest $request, string $kind): string
    {
        $cfg   = $request->config?->cfgItem('economy.vat.filingKinds');
        $label = is_array($cfg) ? (string) ($cfg[$kind]['name'] ?? '') : '';
        return $label !== '' ? $label : $kind;
    }

    /**
     * Chybějící kompilovaný config = error výsledek, ne crash (vzor
     * degradace server-driven textů).
     *
     * @param list<\Shipard\Core\Reports\ReportColumn> $columns
     */
    public function missingConfigResult(ReportRequest $request, array $columns, bool $cs): ReportResult
    {
        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: [new ReportMessage(
                ReportMessageSeverity::Error,
                'vatReports.missingConfig',
                $cs
                    ? 'Chybí kompilovaná konfigurace mapování DPH výstupů (economy.vat.reports.cz) — spusťte ds-upgrade.'
                    : 'Missing compiled VAT outputs mapping config (economy.vat.reports.cz) — run ds-upgrade.',
            )],
            columns: $columns,
            rows: [],
        );
    }

    /** Money buňka jednohodnotového sloupce. @return array{md: float, d: float, balance: float} */
    public function money(float $value): array
    {
        return ['md' => 0.0, 'd' => 0.0, 'balance' => round($value, 2)];
    }
}
