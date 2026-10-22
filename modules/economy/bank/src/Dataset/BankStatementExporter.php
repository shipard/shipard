<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Dataset;

use Dibi\Connection;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Sekce `bank/` při dumpu (#131 fáze 2a, D17): jeden soubor per výpis
 * (`shpd.dataset.bankStatement.v1`), náš účet kódem, transakce jen s tím,
 * co dodá banka. Dopočtené hodnoty (partner, amount_dom, accounting_state,
 * fingerprint) se nepřenáší; výchozí pohyb `payment.in` / `payment.out`
 * se nevypisuje (applier ho doplní podle směru), kurz jen u účtu v cizí
 * měně. Pořadí: výpisy podle kódu účtu a období, transakce podle data a id
 * (pořadí vložení — DB jiné nemá), název `NNNN-<kód>-<RRRR-MM>.jsonc`.
 */
class BankStatementExporter implements RecordExporter
{
    public const SECTION = BankStatementSeeder::SECTION;
    public const FORMAT = 'shpd.dataset.bankStatement.v1';

    /** Pohyby, které applier doplní sám podle směru — soubor je nenese. */
    public const DEFAULT_OPERATIONS = ['payment.in', 'payment.out'];

    /** @var list<string> */
    private array $warnings = [];

    private ?string $homeCurrency;

    /**
     * @param string|null $homeCurrency domácí měna DS (malými); null = dohledat
     *        z nastavení stejně jako StatementImportService
     */
    public function __construct(private readonly Connection $db, ?string $homeCurrency = null)
    {
        $this->homeCurrency = $homeCurrency === null ? null : strtolower(trim($homeCurrency));
    }

    public function section(): string
    {
        return self::SECTION;
    }

    public function exportAll(): array
    {
        return $this->export(null);
    }

    /** @param list<int> $ids */
    public function exportByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids === [] ? [] : $this->export($ids);
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<int>|null $ids null = všechny nearchivované výpisy
     * @return list<ExportedRecord>
     */
    private function export(?array $ids): array
    {
        $sql = 'SELECT [s.id], [s.statement_number], [s.external_id], [s.period_start], [s.period_end],'
            . ' [s.opening_balance], [s.closing_balance], [s.currency], [s.docState],'
            . ' [a.code] AS [account_code], [a.currency] AS [account_currency]'
            . ' FROM [economy_bank_statements] AS [s]'
            . ' JOIN [economy_codebooks_bank_accounts] AS [a] ON [a.id] = [s.bank_account]'
            . ' WHERE [s.docState] <> 90'
            . ($ids !== null ? ' AND [s.id] IN %in' : '')
            . ' ORDER BY [a.code], [s.period_start], [s.statement_number], [s.id]';
        $statements = $ids !== null ? $this->db->fetchAll($sql, $ids) : $this->db->fetchAll($sql);

        $out = [];
        foreach ($statements as $s) {
            $s = is_array($s) ? $s : $s->toArray();
            $id = (int) $s['id'];
            $code = trim((string) ($s['account_code'] ?? ''));
            $periodStart = (string) V::date($s['period_start'] ?? null);
            if ($code === '') {
                $this->warnings[] = "bank: výpis #{$id} ({$periodStart}): bankovní účet bez kódu — nelze přenést";
                continue;
            }
            $docState = (int) ($s['docState'] ?? 0);
            $accountCurrency = strtolower(trim((string) ($s['account_currency'] ?? '')));
            $foreign = $accountCurrency !== '' && $accountCurrency !== $this->homeCurrency();

            $transactions = [];
            foreach ($this->db->fetchAll(
                'SELECT * FROM [economy_bank_transactions] WHERE [statement] = %i AND [docState] <> 90'
                . ' ORDER BY [date_transaction], [id]',
                $id,
            ) as $t) {
                $t = is_array($t) ? $t : $t->toArray();
                if ((int) ($t['docState'] ?? 0) !== $docState) {
                    $this->warnings[] = "bank: výpis {$code} {$periodStart}: transakce #{$t['id']} je ve stavu "
                        . (int) $t['docState'] . ", výpis ve {$docState} — po seedu dostane stav výpisu";
                }
                $amount = (float) ($t['amount'] ?? 0);
                $transactions[] = V::prune([
                    'externalId'          => V::str($t['external_id'] ?? null),
                    'amount'              => (int) ($t['direction'] ?? 1) === 2 ? -$amount : $amount,
                    'dateTransaction'     => V::date($t['date_transaction'] ?? null),
                    'dateValue'           => V::date($t['date_value'] ?? null),
                    'counterpartyAccount' => V::str($t['counterparty_account'] ?? null),
                    'counterpartyName'    => V::str($t['counterparty_name'] ?? null),
                    'paymentReference'    => V::str($t['payment_reference'] ?? null),
                    'specificSymbol'      => V::str($t['specific_symbol'] ?? null),
                    'constantSymbol'      => V::str($t['constant_symbol'] ?? null),
                    'message'             => V::str($t['message'] ?? null),
                    'operation'           => self::explicitOperation($t['operation'] ?? null),
                    'exchangeRate'        => $foreign ? V::float($t['exchange_rate'] ?? null) : null,
                ]);
            }

            // Top-level klíče pevně (prázdný výpis má `transactions: []`), prune jen uvnitř.
            $data = [
                'format'      => self::FORMAT,
                'bankAccount' => $code,
                'statement'   => V::prune([
                    'statementNumber' => V::str($s['statement_number'] ?? null),
                    'externalId'      => V::str($s['external_id'] ?? null),
                    'periodStart'     => $periodStart,
                    'periodEnd'       => V::date($s['period_end'] ?? null),
                    'openingBalance'  => V::float($s['opening_balance'] ?? null),
                    'closingBalance'  => V::float($s['closing_balance'] ?? null),
                    'currency'        => V::currencyUpper($s['currency'] ?? ($accountCurrency !== '' ? $accountCurrency : null)),
                ]),
                'transactions' => $transactions,
                'docState'     => $docState,
            ];
            $out[] = new ExportedRecord($id, $code . '-' . substr($periodStart, 0, 7), $data);
        }
        return $out;
    }

    /** Jen výslovně nastavený pohyb (poplatek, úrok, převod); výchozí podle směru → null. */
    public static function explicitOperation(mixed $operation): ?string
    {
        $op = V::str($operation);
        return $op === null || in_array($op, self::DEFAULT_OPERATIONS, true) ? null : $op;
    }

    private function homeCurrency(): string
    {
        return $this->homeCurrency ??= ExchangeRateResolver::homeCurrencyOf(
            new SettingsStore(new DataSourceConnection($this->db)),
        );
    }
}
