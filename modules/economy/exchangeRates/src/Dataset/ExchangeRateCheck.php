<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Dataset;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Kontrola 5 — kurzy dokladů v cizí měně (#103 D79, tasks/testing-rates-isdoc.md):
 * per použitý doklad v cizí měně uložené účetní datum a kurz ≟
 * `expected/exchange-rates.json` (datum kurzu a kurz spočtené generátorem
 * nezávisle na Shipardu: DUZP, bez něj datum vystavení, lístek podle
 * pravidla platnosti) a kurz, který k témuž datu dohledá
 * {@see ExchangeRateResolver} nad lístky zdroje dat, ≟ totéž včetně lístku
 * (`listType`, `validFrom`). Očekávání se neupravuje podle Shipardu —
 * rozdíl je nález. Sada bez `exchange-rates.json` (před D79) = přeskočeno.
 */
final class ExchangeRateCheck implements VerifyCheck
{
    public const ID = 'exchangeRates';
    /** Kurz za jednotku má 6 desetinných míst. */
    public const TOLERANCE = 0.0000005;

    public function __construct(private readonly ExchangeRateResolver $resolver) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Kurzy dokladů v cizí měně (doklad ≟ očekávání ≟ lístek)';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $records = $ctx->expectations->exchangeRatesByMessage();
        if ($records === null) {
            return CheckResult::skipped(self::ID, $this->label(), 'sada nemá expected/exchange-rates.json');
        }
        $home = strtoupper(trim($ctx->homeCurrency));
        $docs = array_values(array_filter(
            $ctx->appliedDocuments(),
            static fn (SetDocument $d): bool => $d->currency() !== null && $d->currency() !== $home,
        ));
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad v cizí měně');
        }

        $differences = [];
        $compared = 0;
        foreach ($docs as $doc) {
            $label = "{$doc->messageId} ({$doc->docNumber})";
            $record = $records[$doc->messageId] ?? null;
            if ($record === null) {
                $differences[] = "{$label}: bez záznamu v exchange-rates.json";
                continue;
            }
            $head = self::head($ctx->wiring->db, (int) $doc->docId);
            if ($head === null) {
                $differences[] = "{$label}: doklad #{$doc->docId} nenalezen";
                continue;
            }
            $currency = strtolower((string) $doc->currency());
            $expectedDate = (string) ($record['rateDate'] ?? '');
            $expectedRate = (float) ($record['rate'] ?? 0);
            $compared++;

            if ($head['accounting_date'] !== $expectedDate) {
                $differences[] = sprintf('%s: účetní datum očekáváno %s, doklad %s', $label, $expectedDate, $head['accounting_date'] ?? '—');
            }
            if ($head['exchange_rate'] === null) {
                $differences[] = "{$label}: doklad nemá kurz";
            } elseif (abs($head['exchange_rate'] - $expectedRate) > self::TOLERANCE) {
                $differences[] = sprintf('%s: kurz očekáváno %s, doklad %s', $label, self::fmt($expectedRate), self::fmt($head['exchange_rate']));
            }

            $lookup = $this->resolver->lookup($currency, $expectedDate);
            if ($lookup->rate === null) {
                $reasons = array_map(static fn (array $r): string => ($r['listType'] ?? '—') . ': ' . ($r['reason'] ?? '?'), $lookup->reasons);
                $differences[] = sprintf('%s: resolver kurz %s k %s nenašel (%s)', $label, strtoupper($currency), $expectedDate, implode(', ', $reasons));
                continue;
            }
            $listMatches = $lookup->rate->listType === (string) ($record['listType'] ?? '')
                && $lookup->rate->validFrom === (string) ($record['validFrom'] ?? '');
            if (abs($lookup->rate->rate - $expectedRate) > self::TOLERANCE || !$listMatches) {
                $differences[] = sprintf(
                    '%s: resolver dává kurz %s z lístku %s od %s, očekáváno %s z lístku %s od %s',
                    $label,
                    self::fmt($lookup->rate->rate),
                    $lookup->rate->listType,
                    $lookup->rate->validFrom,
                    self::fmt($expectedRate),
                    (string) ($record['listType'] ?? '—'),
                    (string) ($record['validFrom'] ?? '—'),
                );
            }
        }
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, ["{$compared} dokladů porovnáno"]);
    }

    /** @return array{accounting_date: ?string, exchange_rate: ?float}|null */
    private static function head(Connection $db, int $docId): ?array
    {
        $row = $db->fetch('SELECT [accounting_date], [exchange_rate] FROM [docs_core_heads] WHERE [id] = %i', $docId);
        if ($row === null) {
            return null;
        }
        $rate = $row['exchange_rate'];
        return [
            'accounting_date' => ExchangeRateResolver::dateString($row['accounting_date']),
            'exchange_rate'   => $rate === null || $rate === '' ? null : (float) $rate,
        ];
    }

    private static function fmt(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
    }
}
