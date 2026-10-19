<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Jediná cesta zápisu kurzovních lístků (#121 §3): CLI exchange-rates-import,
 * seeder datové sady, v tasku B služba. Vstup = dekódovaný lístek ve formátu
 * shpd.exchangeRates.list.v1 + zdroj. Schéma → sémantika (známý typ pro zemi
 * zdroje dat, domácí měna lístku = měna typu = domácí měna zdroje dat, měny
 * z world.base.currencies, žádná měna dvakrát, validTo ≥ validFrom, typ bez
 * maxAgeDays vyžaduje validTo) → zápis hlavičky a hodnot v jedné transakci
 * přes TableGateway (childTables `values`).
 *
 * Výsledek per lístek: nový (list_type, valid_from) → created; existující
 * se shodným obsahem → skipped; s jiným obsahem → conflict, s volbou replace
 * se hodnoty nahradí → replaced. DB přístup přes chráněné metody (testy).
 */
class ExchangeRateListImporter
{
    public const int SOURCE_FILE = 1;
    public const int SOURCE_DATASET = 2;
    public const int SOURCE_SERVICE = 3;

    public const string FORMAT = 'shpd.exchangeRates.list.v1';
    public const string FORMAT_ID = 'shpd.exchangeRates.list';
    public const string FORMAT_VERSION = '1';

    public const string TABLE_LISTS = ExchangeRateResolver::TABLE_LISTS;
    public const string TABLE_VALUES = ExchangeRateResolver::TABLE_VALUES;
    public const string VALUES_KEY = 'values';

    private const array CHILD_TABLES = [
        ['table' => self::TABLE_VALUES, 'foreignKey' => 'list', 'dataKey' => self::VALUES_KEY],
    ];

    /**
     * @param array<string, true> $currencies známé měny malými; prázdné = katalog chybí, měny se nehlídají
     */
    public function __construct(
        private readonly Connection $db,
        private readonly TableGateway $gateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly ExchangeRateListTypes $types,
        private readonly array $currencies,
        private readonly string $homeCurrency,
    ) {
    }

    /** @param array<string, \Shipard\Core\Database\TableDefinition> $tables */
    public static function create(
        Connection $db,
        ?ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
    ): self {
        $def = $tables[self::TABLE_LISTS] ?? null;
        $gateway = new TableGateway(
            self::TABLE_LISTS,
            $db,
            $registry,
            $def?->childTables ?: self::CHILD_TABLES,
            $config,
            $dsConfig,
            null,
            null,
            $def,
        );
        $currencies = [];
        $catalog = $config?->cfgItem('world.base.currencies');
        if (is_array($catalog)) {
            foreach (array_keys($catalog) as $code) {
                $currencies[strtolower((string) $code)] = true;
            }
        }
        return new self(
            $db,
            $gateway,
            new SchemaValidator(SchemaLoader::default()),
            ExchangeRateListTypes::fromConfig($config, $dsConfig->getCountry()),
            $currencies,
            ExchangeRateResolver::homeCurrencyOf(new SettingsStore(new DataSourceConnection($db))),
        );
    }

    public function types(): ExchangeRateListTypes
    {
        return $this->types;
    }

    /** @param array<string, mixed> $list dekódovaný lístek */
    public function import(array $list, int $source, bool $replace = false): ExchangeRateListImportResult
    {
        $listType = is_string($list['listType'] ?? null) ? $list['listType'] : '';
        $validFrom = is_string($list['validFrom'] ?? null) ? $list['validFrom'] : '';

        $issues = $this->schemaValidator->validate($list, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($issues !== []) {
            $errors = [];
            foreach ($issues as $issue) {
                $path = (string) ($issue['path'] ?? '');
                $errors[] = ($path !== '' ? $path . ': ' : '') . (string) ($issue['message'] ?? $issue['code'] ?? 'schema');
            }
            return new ExchangeRateListImportResult(ExchangeRateListImportResult::INVALID, $listType, $validFrom, null, $errors);
        }

        $errors = $this->semanticErrors($list);
        if ($errors !== []) {
            return new ExchangeRateListImportResult(ExchangeRateListImportResult::INVALID, $listType, $validFrom, null, $errors);
        }

        $head = $this->normalize($list, $source);
        $existingId = $this->findListId($head['list_type'], $head['valid_from']);

        if ($existingId === null) {
            $result = $this->save($head);
            if (!$result->isSuccess()) {
                return $this->failed($head, null, $result);
            }
            return new ExchangeRateListImportResult(ExchangeRateListImportResult::CREATED, $head['list_type'], $head['valid_from'], (int) ($result->getData()['id'] ?? 0));
        }

        $existing = $this->loadList($existingId);
        if ($existing !== null && self::fingerprint($existing) === self::fingerprint($head)) {
            return new ExchangeRateListImportResult(ExchangeRateListImportResult::SKIPPED, $head['list_type'], $head['valid_from'], $existingId);
        }
        if (!$replace) {
            return new ExchangeRateListImportResult(
                ExchangeRateListImportResult::CONFLICT,
                $head['list_type'],
                $head['valid_from'],
                $existingId,
                ['Lístek už existuje s jiným obsahem — nahradí ho jen volba replace.'],
            );
        }

        // Nahrazení: hodnoty téže měny dostanou id existujícího řádku (UPDATE),
        // nové měny bez id (INSERT), chybějící měny gateway smaže — bez
        // kolize unikátního klíče (list, currency).
        $idsByCurrency = [];
        foreach ($existing[self::VALUES_KEY] ?? [] as $row) {
            if (is_array($row) && isset($row['id'], $row['currency'])) {
                $idsByCurrency[strtolower((string) $row['currency'])] = (int) $row['id'];
            }
        }
        foreach ($head[self::VALUES_KEY] as &$value) {
            if (isset($idsByCurrency[$value['currency']])) {
                $value['id'] = $idsByCurrency[$value['currency']];
            }
        }
        unset($value);
        $head['id'] = $existingId;

        $result = $this->save($head);
        if (!$result->isSuccess()) {
            return $this->failed($head, $existingId, $result);
        }
        return new ExchangeRateListImportResult(ExchangeRateListImportResult::REPLACED, $head['list_type'], $head['valid_from'], $existingId);
    }

    /** @return list<string> */
    private function semanticErrors(array $list): array
    {
        $errors = [];
        $listType = (string) $list['listType'];
        $type = $this->types->get($listType);
        if ($type === null) {
            $known = array_map(static fn(ExchangeRateListType $t): string => $t->id, $this->types->all());
            $errors[] = "Neznámý typ lístku „{$listType}“"
                . ($known !== [] ? ' — typy zdroje dat: ' . implode(', ', $known) : ' — zdroj dat nemá žádné typy lístků');
            return $errors;
        }

        $home = strtolower((string) $list['homeCurrency']);
        if ($home !== $type->homeCurrency) {
            $errors[] = 'Měna lístku ' . strtoupper($home) . ' neodpovídá měně typu ' . strtoupper($type->homeCurrency) . '.';
        }
        if ($home !== $this->homeCurrency) {
            $errors[] = 'Měna lístku ' . strtoupper($home) . ' neodpovídá domácí měně zdroje dat ' . strtoupper($this->homeCurrency) . '.';
        }

        $validFrom = (string) $list['validFrom'];
        if (ExchangeRateResolver::normalizeDate($validFrom) === null) {
            $errors[] = "Neplatné datum validFrom „{$validFrom}“.";
        }
        $validTo = $list['validTo'] ?? null;
        if ($validTo === null || $validTo === '') {
            if ($type->requiresValidTo()) {
                $errors[] = "Typ {$type->id} nemá maxAgeDays — lístek musí nést validTo.";
            }
        } else {
            $validTo = (string) $validTo;
            if (ExchangeRateResolver::normalizeDate($validTo) === null) {
                $errors[] = "Neplatné datum validTo „{$validTo}“.";
            } elseif ($validTo < $validFrom) {
                $errors[] = "validTo {$validTo} je před validFrom {$validFrom}.";
            }
        }

        $seen = [];
        foreach ($list['rates'] as $rate) {
            $currency = strtolower((string) $rate['currency']);
            if ($this->currencies !== [] && !isset($this->currencies[$currency])) {
                $errors[] = 'Neznámá měna ' . strtoupper($currency) . '.';
            }
            if (isset($seen[$currency])) {
                $errors[] = 'Měna ' . strtoupper($currency) . ' je v lístku dvakrát.';
            }
            $seen[$currency] = true;
        }
        return $errors;
    }

    /**
     * Lístek v tvaru pro gateway: měny malými, hodnoty pod klíčem `values`.
     *
     * @return array<string, mixed>
     */
    private function normalize(array $list, int $source): array
    {
        $values = [];
        foreach ($list['rates'] as $rate) {
            $values[] = [
                'currency' => strtolower((string) $rate['currency']),
                'amount'   => (int) $rate['amount'],
                'rate'     => round((float) $rate['rate'], 6),
            ];
        }
        $listNumber = $list['listNumber'] ?? null;
        $validTo = $list['validTo'] ?? null;
        return [
            'list_type'     => (string) $list['listType'],
            'home_currency' => strtolower((string) $list['homeCurrency']),
            'valid_from'    => (string) $list['validFrom'],
            'valid_to'      => $validTo !== null && $validTo !== '' ? (string) $validTo : null,
            'list_number'   => $listNumber !== null && $listNumber !== '' ? (string) $listNumber : null,
            'source'        => $source,
            self::VALUES_KEY => $values,
        ];
    }

    /** Otisk obsahu pro porovnání existujícího lístku s importovaným (bez zdroje a id). */
    public static function fingerprint(array $list): string
    {
        $values = [];
        foreach ($list[self::VALUES_KEY] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $currency = strtolower((string) ($row['currency'] ?? ''));
            $values[$currency] = [$currency, (int) ($row['amount'] ?? 1), number_format((float) ($row['rate'] ?? 0), 6, '.', '')];
        }
        ksort($values);
        $listNumber = $list['list_number'] ?? null;
        $validTo = ExchangeRateResolver::dateString($list['valid_to'] ?? null);
        return json_encode([
            strtolower((string) ($list['home_currency'] ?? '')),
            $validTo,
            $listNumber !== null && $listNumber !== '' ? (string) $listNumber : null,
            array_values($values),
        ], JSON_THROW_ON_ERROR);
    }

    private function failed(array $head, ?int $id, DocumentResult $result): ExchangeRateListImportResult
    {
        $message = $result->getErrorMessage();
        if ($message === null && $result->getValidation() !== null) {
            $message = json_encode($result->getValidation()->toArray(), JSON_UNESCAPED_UNICODE);
        }
        return new ExchangeRateListImportResult(
            ExchangeRateListImportResult::FAILED,
            $head['list_type'],
            $head['valid_from'],
            $id,
            ['Zápis selhal: ' . ($message ?? 'neznámá chyba')],
        );
    }

    protected function findListId(string $listType, string $validFrom): ?int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM %n WHERE [list_type] = %s AND [valid_from] = %d',
            self::TABLE_LISTS,
            $listType,
            new \DateTimeImmutable($validFrom),
        );
        return $id !== null && $id !== false ? (int) $id : null;
    }

    /** Hlavička + hodnoty (`values`) existujícího lístku. */
    protected function loadList(int $id): ?array
    {
        return $this->gateway->loadDocument($id);
    }

    protected function save(array $data): DocumentResult
    {
        return $this->gateway->saveDocument($data);
    }
}
