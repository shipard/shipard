<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Dataset;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Economy\ExchangeRates\ExchangeRate;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Sekce `exchangeRates/` při dumpu (#121 D10, U7): právě ty lístky, které
 * resolver použije pro účetní data dokladů a návrhů (canonical v analýzách
 * zpráv) v cizí měně — ne celou historii. Bez dokladů v cizí měně je
 * sekce prázdná. Soubor = formát shpd.exchangeRates.list.v1 (měny velkými,
 * kurz za `amount` jednotek, bez zdroje a id), název
 * `NNNN-<listType>-<validFrom>.jsonc`. Dvojice (měna, datum) bez lístku
 * dá varování — takový doklad po seedu kurz nedostane.
 *
 * Účetní datum návrhu určuje `$accountingDateOf` (canonical → datum) —
 * `DatasetDumpCommand` předává `AccountingDateRule::fromCanonical()`
 * z docs.core (#103 D78: přijatá faktura DUZP, jinak datum vystavení);
 * bez closure platí `accountingDate ?? issueDate`.
 */
class ExchangeRatesExporter implements RecordExporter
{
    public const SECTION = ExchangeRatesSeeder::SECTION;

    /** @var list<string> */
    private array $warnings = [];

    /** @param (\Closure(array<string, mixed>): ?string)|null $accountingDateOf */
    public function __construct(
        private readonly Connection $db,
        private readonly ExchangeRateResolver $resolver,
        private readonly bool $hasDocuments = true,
        private readonly bool $hasMail = true,
        private readonly ?\Closure $accountingDateOf = null,
    ) {
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function exportAll(): array
    {
        return $this->exportByIds($this->listIdsUsedByRecords());
    }

    /** @param list<int> $ids */
    public function exportByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $lists = $this->db->fetchAll(
            'SELECT [id], [list_type], [home_currency], [valid_from], [valid_to], [list_number] FROM %n'
            . ' WHERE [id] IN %in ORDER BY [valid_from], [list_type], [id]',
            ExchangeRateResolver::TABLE_LISTS,
            $ids,
        );
        $out = [];
        foreach ($lists as $list) {
            $list = is_array($list) ? $list : $list->toArray();
            $id = (int) $list['id'];
            $values = $this->db->fetchAll(
                'SELECT [currency], [amount], [rate] FROM %n WHERE [list] = %i ORDER BY [currency]',
                ExchangeRateResolver::TABLE_VALUES,
                $id,
            );
            $rates = [];
            foreach ($values as $value) {
                $value = is_array($value) ? $value : $value->toArray();
                $rates[] = [
                    'currency' => V::currencyUpper($value['currency'] ?? null),
                    'amount'   => (int) ($value['amount'] ?? 1),
                    'rate'     => round((float) ($value['rate'] ?? 0), 6),
                ];
            }
            $validFrom = (string) V::date($list['valid_from'] ?? null);
            $data = V::prune([
                'format'       => ExchangeRateListImporter::FORMAT,
                'listType'     => (string) $list['list_type'],
                'homeCurrency' => V::currencyUpper($list['home_currency'] ?? null),
                'validFrom'    => $validFrom,
                'validTo'      => V::date($list['valid_to'] ?? null),
                'listNumber'   => V::str($list['list_number'] ?? null),
                'rates'        => $rates,
            ]);
            $out[] = new ExportedRecord($id, $list['list_type'] . '-' . $validFrom, $data);
        }
        return $out;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Id lístků, které resolver použije pro doklady a návrhy v cizí měně.
     *
     * @return list<int>
     */
    private function listIdsUsedByRecords(): array
    {
        $home = $this->resolver->homeCurrency();
        $pairs = [];
        if ($this->hasDocuments) {
            $rows = $this->db->fetchAll(
                'SELECT DISTINCT [doc_currency], [accounting_date] FROM [docs_core_heads]'
                . ' WHERE [docState] <> 90 AND [doc_currency] IS NOT NULL AND [doc_currency] <> %s AND [doc_currency] <> %s'
                . ' AND [accounting_date] IS NOT NULL',
                '',
                $home,
            );
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : $row->toArray();
                self::addPair($pairs, (string) $row['doc_currency'], V::date($row['accounting_date'] ?? null));
            }
        }
        if ($this->hasMail) {
            $rows = $this->db->fetchAll(
                'SELECT [a.canonical_json] FROM [core_mail_message_analyses] AS [a]'
                . ' JOIN [core_mail_incoming_messages] AS [m] ON [m.id] = [a.message]'
                . ' WHERE [m.docState] <> 90 AND [a.canonical_json] IS NOT NULL',
            );
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : $row->toArray();
                $canonical = is_string($row['canonical_json'] ?? null) ? json_decode($row['canonical_json'], true) : null;
                if (!is_array($canonical)) {
                    continue;
                }
                $currency = strtolower((string) ($canonical['currency'] ?? ''));
                $date = $this->accountingDateOf !== null
                    ? ($this->accountingDateOf)($canonical)
                    : ($canonical['dates']['accountingDate'] ?? $canonical['dates']['issueDate'] ?? null);
                if ($currency !== '' && $currency !== $home && is_string($date)) {
                    self::addPair($pairs, $currency, $date);
                }
            }
        }
        ksort($pairs);

        $ids = [];
        foreach ($pairs as [$currency, $date]) {
            $rate = $this->resolver->rateFor($currency, $date);
            if ($rate === null || $rate->listId === null) {
                $this->warnings[] = sprintf('exchangeRates: kurz %s k %s není v lístcích — doklad po seedu kurz nedostane', strtoupper($currency), $date);
                continue;
            }
            $ids[$rate->listId] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /** @param array<string, array{0: string, 1: string}> $pairs */
    private static function addPair(array &$pairs, string $currency, ?string $date): void
    {
        $currency = strtolower(trim($currency));
        if ($currency === '' || $date === null || ExchangeRateResolver::normalizeDate($date) === null) {
            return;
        }
        $pairs[$currency . '|' . $date] = [$currency, $date];
    }
}
