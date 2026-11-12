<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Module\Economy\Vat\FilingDocument;

/**
 * Načtení vstupu generátoru XML z persistovaného snapshotu podání
 * (issue #55, X1). **Jediné místo, které kvůli XML sahá do databáze** —
 * writery i validace pak pracují s hodnotou.
 *
 * Čte se výhradně snapshot (`economy_vat_filings` a jeho řádkové tabulky):
 * doklady, koeficient ani registrace se znovu nevyhodnocují, takže
 * generování podaného podání dá stejný soubor i po jejich pozdější změně.
 * Jediná výjimka je rozsah instance tvrzení, ze kterého plyne zdaňovací
 * období — ten instance po podání zmrazuje `ReportPeriodDocument`.
 */
final class FilingXmlInputLoader
{
    public function __construct(private readonly \Dibi\Connection $db) {}

    public function load(int $filingId): FilingXmlInput
    {
        $filing = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        if ($filing === null) {
            throw new \DomainException("Podání #{$filingId} nenalezeno");
        }
        $filing = $filing->toArray();

        $period = $this->db->fetch(
            'SELECT [id], [report_type], [date_begin], [date_end], [vat_registration]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            (int) $filing['report_period'],
        );
        if ($period === null) {
            throw new \DomainException("Podání #{$filingId} míří na neexistující daňové tvrzení");
        }

        if (($filing['result'] ?? null) === null) {
            throw new \DomainException(
                "Podání #{$filingId} nemá sestavený snapshot — nejdřív ho přepočítejte.",
            );
        }

        $type   = (string) $filing['report_type'];
        $result = self::decodeJson($filing['result']);

        return new FilingXmlInput(
            reportType: $type,
            filingKind: (string) $filing['filing_kind'],
            previousKind: $this->previousKind($filing),
            header: StructuredFieldValues::decode($filing['header'] ?? null) ?? [],
            period: FilingPeriod::fromRange(
                self::isoDate($period['date_begin']),
                self::isoDate($period['date_end']),
            ),
            dateIssue: self::isoDate($filing['date_issue']),
            dateFiled: $filing['date_filed'] !== null ? self::isoDate($filing['date_filed']) : null,
            dateFound: $filing['date_found'] !== null ? self::isoDate($filing['date_found']) : null,
            returnRows: $type === 'return' ? $this->returnRows($filingId) : [],
            coefficients: $this->coefficients($result),
            controlRows: $type === 'cs' ? $this->controlRows($filingId) : [],
            controlReturnBase: $type === 'cs' ? self::floatMap($result['cs']['dp3Base'] ?? []) : [],
            recapRows: $type === 'rs' ? $this->recapRows($filingId) : [],
            note: $filing['note'] !== null && trim((string) $filing['note']) !== ''
                ? (string) $filing['note']
                : null,
        );
    }

    /**
     * Druh předchozího podání — rozlišuje opravné od opravného
     * dodatečného / následného (kód formy „E").
     *
     * @param array<string, mixed> $filing
     */
    private function previousKind(array $filing): ?string
    {
        $previousId = (int) ($filing['previous_filing'] ?? 0);
        if ($previousId <= 0) {
            return null;
        }
        $kind = $this->db->fetchSingle(
            'SELECT [filing_kind] FROM %n WHERE [id] = %i',
            FilingDocument::TABLE,
            $previousId,
        );
        return $kind !== null && $kind !== false ? (string) $kind : null;
    }

    /**
     * Podané hodnoty řádků přiznání (`_filed` sloupce — po řádkovém
     * zaokrouhlení, u dodatečného rozdíly).
     *
     * @return array<int, array{base: float, full: float, reduced: float}>
     */
    private function returnRows(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [row], [base_filed], [tax_full_filed], [tax_reduced_filed]'
            . ' FROM [economy_vat_filing_return_rows] WHERE [filing] = %i ORDER BY [row]',
            $filingId,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['row']] = [
                'base'    => (float) $row['base_filed'],
                'full'    => (float) $row['tax_full_filed'],
                'reduced' => (float) $row['tax_reduced_filed'],
            ];
        }
        return $out;
    }

    /**
     * Řádky kontrolního hlášení v pořadí, v jakém je zapsal composer
     * (detaily seřazené podle ev. čísla, agregáty na konci sekce).
     *
     * @return list<array<string, mixed>>
     */
    private function controlRows(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [section], [row_kind], [doc_head], [doc_number], [partner_vat_id], [vat_dppd],'
            . ' [reverse_charge_code], [base1], [tax1], [base2], [tax2], [base3], [tax3]'
            . ' FROM [economy_vat_filing_cs_rows] WHERE [filing] = %i ORDER BY [id]',
            $filingId,
        );

        $out = [];
        foreach ($rows as $row) {
            $data = $row->toArray();
            $data['vat_dppd'] = $data['vat_dppd'] !== null ? self::isoDate($data['vat_dppd']) : null;
            foreach (['base1', 'tax1', 'base2', 'tax2', 'base3', 'tax3'] as $band) {
                $data[$band] = (float) $data[$band];
            }
            $out[] = $data;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function recapRows(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [kod], [partner_vat_id], [count], [value], [value_filed]'
            . ' FROM [economy_vat_filing_rs_rows] WHERE [filing] = %i ORDER BY [kod], [partner_vat_id]',
            $filingId,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'kod'            => (int) $row['kod'],
                'partner_vat_id' => (string) $row['partner_vat_id'],
                'count'          => (int) $row['count'],
                'value'          => (float) $row['value'],
                'value_filed'    => (float) $row['value_filed'],
            ];
        }
        return $out;
    }

    /**
     * Koeficienty ř. 52 a 53 ze snapshotu — ne z živého resolveru, jinak
     * by se podané přiznání po změně koeficientu vygenerovalo jinak.
     *
     * @param array<string, mixed> $result
     * @return array<string, float>
     */
    private function coefficients(array $result): array
    {
        $out = [];
        foreach (['coefficient', 'settlementCoefficient'] as $key) {
            $value = $result['return'][$key] ?? null;
            if ($value !== null) {
                $out[$key] = (float) $value;
            }
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return array<string, float>
     */
    private static function floatMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = (float) $item;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
