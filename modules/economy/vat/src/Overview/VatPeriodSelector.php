<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Overview;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\IsoDate;
use Shipard\Module\Economy\Vat\FilingDocument;

/**
 * Výběr období přiznání k DPH pro Přehled firmy (#120 D7, D16): aktivní
 * registrace k dnešku (neplátce = žádná registrace, docs/ds-setup.md D5),
 * běžné období (instance typu přiznání obsahující dnešek) a předchozí
 * období, dokud k němu není podání ve stavu Podáno — sestavené, nepodané
 * podání termín neruší.
 */
final class VatPeriodSelector
{
    public const REPORT_TYPE_RETURN = 'return';

    /** Registrace platná pro doklady: Koncept i V pořádku (vzor DsAboutController). */
    private const ACTIVE_REGISTRATION_STATES = [10, 40];
    private const DOC_STATE_DELETED = 90;

    /** @param bool $hasFilings DS má tabulku podání (`economy_vat_filings`) */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly bool $hasFilings = true,
    ) {}

    /** @return ?array{id: int, name: string} */
    public function activeRegistration(string $today): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT [id], [name] FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [docState] IN %in AND [valid_from] <= %s AND ([valid_to] IS NULL OR [valid_to] >= %s)'
            . ' ORDER BY [valid_from] DESC, [id] DESC LIMIT 1',
            self::ACTIVE_REGISTRATION_STATES, $today, $today,
        );
        return $row !== null ? ['id' => (int) $row['id'], 'name' => (string) ($row['name'] ?? '')] : null;
    }

    /**
     * @return array{current: ?array{id: int, name: string, dateBegin: string, dateEnd: string},
     *         previous: ?array{id: int, name: string, dateBegin: string, dateEnd: string}}
     */
    public function periods(int $registrationId, string $today): array
    {
        $current = $this->shape($this->db->fetchRow(
            'SELECT [id], [name], [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_begin] <= %s AND [date_end] >= %s'
            . ' ORDER BY [date_begin] DESC, [id] DESC LIMIT 1',
            $registrationId, self::REPORT_TYPE_RETURN, self::DOC_STATE_DELETED, $today, $today,
        ));

        $before   = $current !== null ? $current['dateBegin'] : $today;
        $previous = $this->shape($this->db->fetchRow(
            'SELECT [id], [name], [date_begin], [date_end] FROM [economy_vat_report_periods]'
            . ' WHERE [vat_registration] = %i AND [report_type] = %s AND [docState] != %i'
            . ' AND [date_end] < %s'
            . ' ORDER BY [date_end] DESC, [id] DESC LIMIT 1',
            $registrationId, self::REPORT_TYPE_RETURN, self::DOC_STATE_DELETED, $before,
        ));
        if ($previous !== null && $this->isFiled($previous['id'])) {
            $previous = null;
        }

        return ['current' => $current, 'previous' => $previous];
    }

    private function isFiled(int $periodId): bool
    {
        if (!$this->hasFilings) {
            return false;
        }
        return $this->db->fetchSingle(
            'SELECT 1 FROM [economy_vat_filings] WHERE [report_period] = %i AND [docState] = %i LIMIT 1',
            $periodId, FilingDocument::DOC_STATE_FILED,
        ) !== null;
    }

    /**
     * @param ?array<string, mixed> $row
     * @return ?array{id: int, name: string, dateBegin: string, dateEnd: string}
     */
    private function shape(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'id'        => (int) $row['id'],
            'name'      => (string) ($row['name'] ?? ''),
            'dateBegin' => (string) IsoDate::fromDb($row['date_begin'] ?? null),
            'dateEnd'   => (string) IsoDate::fromDb($row['date_end'] ?? null),
        ];
    }
}
