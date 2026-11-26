<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Jediné čtení databáze pro vyhodnocení dokladu (#171 fáze 2): fakta
 * o dokladu ({@see DocFacts}) a registrace ve stavu V pořádku
 * ({@see RegistrationFacts}).
 *
 * Země dokladu je země jeho registrace DPH (`vat_registration` →
 * `economy_codebooks_vat_registrations.country`), bez ní domácí země zdroje
 * dat (D22). Řádky se čtou jen běžné (`row_kind` 1) — textové částku nemají.
 */
class DocFactsLoader
{
    public const string HEADS = 'docs_core_heads';
    public const string ROWS = 'docs_core_rows';
    public const string SERIES = 'docs_core_number_series';
    public const string VAT_REGISTRATIONS = 'economy_codebooks_vat_registrations';

    /** Sloupce hlavičky, které vyhodnocení čte. */
    public const array HEAD_COLUMNS = [
        'id', 'doc_type', 'docState', 'doc_number', 'issue_date', 'payment_method', 'cash_dir',
        'total_amount_dom', 'home_currency', 'imported', 'source_kind', 'vat_registration',
        'number_series', 'doc_state_changed_at',
    ];

    /** @var list<RegistrationFacts>|null */
    private ?array $registrations = null;

    /** @var array<int, string> id registrace DPH → země */
    private array $vatCountries = [];

    /** @var array<int, array<string, mixed>|null> id řady → řádek */
    private array $series = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?DataSourceConfig $dsConfig,
    ) {
    }

    public function load(int $docId): ?DocFacts
    {
        $head = $this->db->fetch('SELECT %n FROM %n WHERE [id] = %i', self::HEAD_COLUMNS, self::HEADS, $docId);
        return $head === null ? null : $this->fromHead(iterator_to_array($head));
    }

    /** @param array<string, mixed> $head řádek hlavičky se sloupci {@see self::HEAD_COLUMNS} */
    public function fromHead(array $head): DocFacts
    {
        $rows = $this->db->fetchAll(
            'SELECT [operation], [vat_total_dom] FROM %n WHERE [doc_head] = %i AND [row_kind] = 1 ORDER BY [order_pos], [id]',
            self::ROWS,
            (int) $head['id'],
        );
        return DocFacts::fromRows(
            $head,
            array_map(static fn($row): array => iterator_to_array($row), $rows),
            $this->seriesRow((int) ($head['number_series'] ?? 0)),
            $this->countryOf((int) ($head['vat_registration'] ?? 0)),
        );
    }

    /**
     * Registrace ve stavu V pořádku se známým typem a jejich jednotky.
     *
     * @return list<RegistrationFacts>
     */
    public function registrations(): array
    {
        if ($this->registrations !== null) {
            return $this->registrations;
        }
        $types = SalesRegistrationTypes::fromConfig($this->config);
        $units = [];
        foreach ($this->db->fetchAll('SELECT [id], [registration], [unit_id] FROM %n', UnitDocument::TABLE) as $unit) {
            $units[(int) $unit['registration']][(int) $unit['id']] = (string) $unit['unit_id'];
        }

        $registrations = [];
        $rows = $this->db->fetchAll(
            'SELECT [id], [type], [valid_from], [valid_to], [default_unit] FROM %n WHERE [docState] = %i ORDER BY [id]',
            RegistrationDocument::TABLE,
            RegistrationDocument::STATE_CONFIRMED,
        );
        foreach ($rows as $row) {
            $type = $types->get((string) $row['type']);
            if ($type === null) {
                continue;
            }
            $validFrom = RegistrationDocument::dateOf($row['valid_from']);
            $validTo = RegistrationDocument::dateOf($row['valid_to']);
            $defaultUnit = (int) ($row['default_unit'] ?? 0);
            $registrations[] = new RegistrationFacts(
                id: (int) $row['id'],
                type: $type,
                validFrom: $validFrom !== '' ? $validFrom : null,
                validTo: $validTo !== '' ? $validTo : null,
                defaultUnit: $defaultUnit > 0 ? $defaultUnit : null,
                units: $units[(int) $row['id']] ?? [],
            );
        }
        return $this->registrations = $registrations;
    }

    /** @return array<string, mixed>|null */
    private function seriesRow(int $seriesId): ?array
    {
        if ($seriesId <= 0) {
            return null;
        }
        if (!array_key_exists($seriesId, $this->series)) {
            $row = $this->db->fetch(
                'SELECT [code], [sales_registration_unit] FROM %n WHERE [id] = %i',
                self::SERIES,
                $seriesId,
            );
            $this->series[$seriesId] = $row === null ? null : iterator_to_array($row);
        }
        return $this->series[$seriesId];
    }

    private function countryOf(int $vatRegistration): string
    {
        $home = $this->dsConfig?->getCountry() ?? 'cz';
        if ($vatRegistration <= 0) {
            return $home;
        }
        if (!isset($this->vatCountries[$vatRegistration])) {
            $country = $this->db->fetchSingle(
                'SELECT [country] FROM %n WHERE [id] = %i',
                self::VAT_REGISTRATIONS,
                $vatRegistration,
            );
            $this->vatCountries[$vatRegistration] = is_string($country) && $country !== '' ? $country : $home;
        }
        return $this->vatCountries[$vatRegistration];
    }
}
