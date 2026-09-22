<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Accounting\NullOpenItemLookup;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;

/**
 * Generátor účetního deníku z bankovní transakce — bankovní obdoba
 * AccountingEngine (doklady). Vstup: id transakce; výstup: dva vyrovnané
 * řádky deníku (banka 221xxx + protistrana dle operation) zapsané
 * idempotentně (DELETE + INSERT) + aktualizovaný accounting_state /
 * accounting_messages na transakci. Vše v jedné transakci.
 *
 * Bankovní strana: účet z bank_account.accounting_account (221xxx).
 * Protistrana: operation → cat (cfgItem economy.bank.txOperations) → maska
 * (sekce `accounts` téhož účtovacího předpisu jako doklady) → účet rozvrhu
 * (sdílený AccountMaskResolver). Princip „účet se nikde nezadává" zachován.
 *
 * Úhrady (`payment.in` / `payment.out`, kategorie `bank.unmatched.*`) mají
 * před maskou přednostní krok (#69 D3): má-li transakce partnera, engine
 * dohledá otevřený předpis pro klíč (partner, VS, SS, měna) přes
 * {@see OpenItemLookup} a položí úhradu přesně na účet předpisu (311xxx,
 * 321xxx, ale i 336xxx… — účty z nastavení saldokont). Miss → clearing dle
 * masky (261200/261300). Spárovanost tedy
 * nenese `operation` ani žádný stav na transakci; reaccount je idempotentní
 * a bez paměti (vlastní úhrada se z rezidua vylučuje).
 *
 * Dva řádky stejné částky → deník je z principu vyrovnaný; žádná penny
 * reconciliation. Filozofie chyb stejná jako u dokladů: účtování nikdy
 * neblokuje transakci. Nedohledaný účet → chybový řádek (account NULL,
 * maska s '?', is_error) + message; chybějící fiskální období → prázdný
 * deník. Výsledek vždy accounting_state 1 (OK) / 2 (cokoliv v messages).
 *
 * Smysl §6 docs/bank.md.
 */
final class BankTransactionAccountingEngine
{
    /**
     * Stavy, ve kterých je účet rozvrhu odkazovatelný — re-účtování
     * historických transakcí na dnes archivní (70) účet je legitimní,
     * vyloučen jen smazaný (90). Konvence LINKABLE_STATES viz
     * StatementImportService; shodné s doklady (AccountingEngine).
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /** Smazáno — lookup fiskálního roku vynechává jen tento stav (archivní roky zůstávají dohledatelné). */
    private const DOC_STATE_DELETED = 90;

    /** Délka chybové masky účtu ('221' → '221???'). */
    private const ACCOUNT_NUMBER_LENGTH = 6;

    private const DIRECTION_IN = 1;

    /** Per-run dohledávač účtů dle masky. */
    private AccountMaskResolver $maskResolver;

    /** @var list<array{code: string, message: string, rowId: int|null}> */
    private array $messages = [];

    private readonly OpenItemLookup $openItems;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?JournalEventDispatcher $journalEvents = null,
        ?OpenItemLookup $openItems = null,
    ) {
        // DS bez saldokonta (nebo engine postavený bez lookupu) → vše na clearing.
        $this->openItems = $openItems ?? new NullOpenItemLookup();
    }

    /**
     * Přeúčtuje transakci: smaže starý deník, vygeneruje nový, uloží stav.
     *
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null}>}
     */
    public function accountTransaction(int $txId): array
    {
        $this->messages = [];
        $this->maskResolver = new AccountMaskResolver($this->db);

        $txRow = $this->db->fetch(
            'SELECT t.*, ba.[accounting_account], ba.[currency] AS account_currency
             FROM [economy_bank_transactions] t
             JOIN [economy_codebooks_bank_accounts] ba ON ba.[id] = t.[bank_account]
             WHERE t.[id] = %i',
            $txId,
        );
        if ($txRow === null) {
            throw new \DomainException("Bankovní transakce #{$txId} nenalezena");
        }
        $tx = $txRow->toArray();

        $accountingDate = (string) ($tx['date_transaction'] instanceof \DateTimeInterface
            ? $tx['date_transaction']->format('Y-m-d')
            : ($tx['date_transaction'] ?? ''));

        $fiscalYear  = $this->resolveFiscalYearId($accountingDate);
        $fiscalMonth = $this->resolveFiscalMonthId($accountingDate);
        if ($fiscalYear === null || $fiscalMonth === null) {
            $this->addMessage(
                'fiscal_period_missing',
                'Transakce nemá přiřazený fiskální rok/měsíc — zkontroluj datum transakce a číselník období',
            );
            return $this->writeResult($txId, [], $tx, $accountingDate, $fiscalYear, $fiscalMonth);
        }

        $direction = (int) ($tx['direction'] ?? 0);
        $bankSide  = $direction === self::DIRECTION_IN ? 0 : 1;
        $cpSide    = $direction === self::DIRECTION_IN ? 1 : 0;

        $dom = (float) ($tx['amount_dom'] ?? 0);
        $cur = (float) ($tx['amount'] ?? 0);

        $bankAccount = $this->resolveBankAccount($tx);
        $cpAccount   = $this->resolveCounterpartyAccount($tx, $accountingDate, $fiscalYear);

        $lines = [
            $this->makeLine($tx, $bankSide, $bankAccount, $dom, $cur, null),
            $this->makeLine($tx, $cpSide, $cpAccount, $dom, $cur, $this->operationOf($tx)),
        ];

        // Pojistka: obě strany nesou stejnou částku, deník má být vyrovnaný.
        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $line) {
            $sumDr += $line['money_dr'];
            $sumCr += $line['money_cr'];
        }
        if (round($sumDr, 2) !== round($sumCr, 2)) {
            $this->addMessage(
                'unbalanced',
                sprintf('Deník není vyrovnaný: MD %.2f ≠ DAL %.2f', $sumDr, $sumCr),
            );
        }

        return $this->writeResult($txId, $lines, $tx, $accountingDate, $fiscalYear, $fiscalMonth);
    }

    /**
     * Smaže deník transakce a vynuluje stav účtování — výstup ze stavu 40
     * i beforeDelete cleanup.
     */
    public function clearTransaction(int $txId): void
    {
        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('bank_transaction = %i', $txId)
                ->execute();
            $this->db->update('economy_bank_transactions', [
                'accounting_state'    => 0,
                'accounting_messages' => null,
            ])->where('id = %i', $txId)->execute();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Deník vymazán → saldo musí pohyby transakce odebrat.
        $this->journalEvents?->dispatchJournalWritten('bankTransaction', $txId);
    }

    // ── Dohledávání účtů ────────────────────────────────────────────────────

    /**
     * Bankovní strana: účet z bank_account.accounting_account (221xxx).
     * Prázdné / neexistuje / smazaný → chybový řádek; archivní se dohledá
     * (historické transakce, viz LINKABLE_STATES).
     *
     * @param array<string, mixed> $tx
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveBankAccount(array $tx): array
    {
        $accountId = (int) ($tx['accounting_account'] ?? 0);
        $account = $accountId > 0
            ? $this->db->fetch(
                'SELECT [id], [number] FROM [economy_accounting_accounts]
                 WHERE [id] = %i AND [docState] IN %in',
                $accountId,
                self::LINKABLE_STATES,
            )
            : null;

        if ($account === null) {
            $this->addMessage(
                'bank_account_not_found',
                'Bankovní účet nemá vyplněný nebo platný účet pro pohyby (221xxx)',
            );
            return ['number' => str_pad('221', self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
        }

        return ['id' => (int) $account['id'], 'number' => (string) $account['number']];
    }

    /**
     * Protistrana: operation → cat (txOperations) → [úhrada: otevřený předpis]
     * → maska (accounts předpisu) → účet rozvrhu. Nedohledáno → chybový řádek
     * s maskou doplněnou '?'.
     *
     * @param array<string, mixed> $tx
     * @return array{id?: int, number: string, is_error?: bool}
     */
    private function resolveCounterpartyAccount(array $tx, string $accountingDate, int $fiscalYear): array
    {
        $operation = $this->operationOf($tx);
        $cat = $this->categoryOf($operation);
        if ($cat === '') {
            $this->addMessage(
                'account_not_found',
                "Pohyb '{$operation}' nemá přiřazenou účetní kategorii",
            );
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        // Úhrada: účet otevřeného předpisu má přednost před maskou — clearing
        // je jen výstup při miss (#69 D3, docs/bank.md §6.1).
        if (str_starts_with($cat, 'bank.unmatched.')) {
            $routed = $this->resolveOpenItemAccount($tx, $accountingDate, $fiscalYear);
            if ($routed !== null) {
                return $routed;
            }
        }

        $mask = $this->maskForCategory($cat);
        if ($mask === '') {
            $this->addMessage('account_not_found', "Předpis nemá masku pro kategorii '{$cat}'");
            return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
        }

        $account = $this->maskResolver->resolve($mask, $accountingDate);
        if ($account === null) {
            $this->addMessage('account_not_found', "Účet nenalezen pro masku {$mask}");
            return ['number' => str_pad($mask, self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
        }

        return $account;
    }

    /**
     * Úhrada s partnerem: otevřený případ pro klíč (partner, VS, SS, měna)
     * v účetním období transakce (#69 D11) a směr → protistrana = účet
     * předpisu přesně vč. analytiky (ne maska). Reziduum případu nese
     * znaménko (D19): kladné = dluh (příjem na 311 DAL / výdaj na 321 MD),
     * záporné = vratka přeplatku či dobropisu — strana zápisu plyne ze
     * směru transakce stejně jako u dluhu (výdaj → MD), což je předpisová
     * strana skupiny; saldo z ní udělá předpis + (`accbal.md` §4.2, D23).
     * Engine tedy znaménko rezidua nečte, jen účet.
     * Předpis z jiného období
     * je miss — zůstatky mezi obdobími přenáší otevírací doklad, po jehož
     * zaúčtování úhradu přeúčtuje trigger (D4).
     * Vlastní transakce se z rezidua vylučuje, aby reaccount už routované
     * úhrady neviděl nulu a nevrátil ji na clearing. Bez partnera / miss →
     * null (volající spadne na clearing dle masky). Účet předpisu, který
     * v rozvrhu není (deaktivovaný), je chyba jako u masky.
     *
     * @param array<string, mixed> $tx
     * @return array{id?: int, number: string, is_error?: bool}|null
     */
    private function resolveOpenItemAccount(array $tx, string $accountingDate, int $fiscalYear): ?array
    {
        $partner = (int) ($tx['partner'] ?? 0);
        if ($partner <= 0) {
            return null;
        }

        $item = $this->openItems->findOpenRequest(
            $partner,
            trim((string) ($tx['payment_reference'] ?? '')),
            trim((string) ($tx['specific_symbol'] ?? '')),
            strtolower(trim((string) ($tx['currency'] ?? ''))),
            (int) ($tx['direction'] ?? 0),
            $fiscalYear,
            'bankTransaction',
            (int) ($tx['id'] ?? 0),
        );
        if ($item === null) {
            return null;
        }

        $account = $this->maskResolver->resolve($item->accountNumber, $accountingDate);
        if ($account === null) {
            $this->addMessage('account_not_found', "Účet předpisu {$item->accountNumber} nenalezen v rozvrhu");
            return ['number' => str_pad($item->accountNumber, self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
        }
        return $account;
    }

    /** Operation transakce; prázdné → default dle směru. */
    private function operationOf(array $tx): string
    {
        $operation = trim((string) ($tx['operation'] ?? ''));
        if ($operation !== '') {
            return $operation;
        }
        return (int) ($tx['direction'] ?? 0) === self::DIRECTION_IN ? 'payment.in' : 'payment.out';
    }

    /** Účetní kategorie pohybu z cfgItem economy.bank.txOperations. */
    private function categoryOf(string $operation): string
    {
        $ops = $this->config?->cfgItem('economy.bank.txOperations');
        if (!is_array($ops) || !is_array($ops[$operation] ?? null)) {
            return '';
        }
        return (string) ($ops[$operation]['cat'] ?? '');
    }

    /** První maska v sekci `accounts` předpisu se shodnou kategorií. */
    private function maskForCategory(string $cat): string
    {
        $rules = $this->resolveRules();
        foreach ($rules['accounts'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['cat'] ?? null) === $cat) {
                return (string) ($entry['accountMask'] ?? '');
            }
        }
        return '';
    }

    /**
     * Účtovací předpis dle země vlastní firmy, fallback cz — stejný zdroj
     * jako doklady (cfgItem economy.accounting.rules.{country}).
     *
     * @return array<string, mixed>
     */
    private function resolveRules(): array
    {
        if ($this->config === null) {
            return [];
        }
        $address = (new OwnCompanyResolver($this->db))->getOwnHeadquartersAddress();
        $country = is_array($address) ? strtolower(trim((string) ($address['country'] ?? ''))) : '';
        $country = $country !== '' ? $country : 'cz';

        $rules = $this->config->cfgItem("economy.accounting.rules.{$country}");
        if (!is_array($rules)) {
            $rules = $this->config->cfgItem('economy.accounting.rules.cz');
        }
        return is_array($rules) ? $rules : [];
    }

    // ── Skladba řádku ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $tx
     * @param array{id?: int, number: string, is_error?: bool} $account
     * @return array<string, mixed>
     */
    private function makeLine(array $tx, int $side, array $account, float $dom, float $cur, ?string $operation): array
    {
        return [
            'side'           => $side,
            'account'        => $account['id'] ?? null,
            'account_number' => $account['number'],
            'is_error'       => !empty($account['is_error']),
            'operation'      => $operation,
            'partner'        => isset($tx['partner']) && $tx['partner'] !== null ? (int) $tx['partner'] : null,
            'text'           => mb_substr($this->buildText($tx), 0, 200),
            'money_dr'       => $side === 0 ? round($dom, 2) : 0.0,
            'money_cr'       => $side === 1 ? round($dom, 2) : 0.0,
            'money_dr_cur'   => $side === 0 ? round($cur, 2) : 0.0,
            'money_cr_cur'   => $side === 1 ? round($cur, 2) : 0.0,
        ];
    }

    /** Text řádku deníku: popis pohybu + protistrana + variabilní symbol. */
    private function buildText(array $tx): string
    {
        $parts = [];
        $opLabel = $this->operationLabel($this->operationOf($tx));
        if ($opLabel !== '') {
            $parts[] = $opLabel;
        }
        $counterparty = trim((string) ($tx['counterparty_name'] ?? ''));
        if ($counterparty !== '') {
            $parts[] = $counterparty;
        }
        $vs = trim((string) ($tx['payment_reference'] ?? ''));
        if ($vs !== '') {
            $parts[] = 'VS ' . $vs;
        }
        return implode(' — ', $parts);
    }

    private function operationLabel(string $operation): string
    {
        $ops = $this->config?->cfgItem('economy.bank.txOperations');
        if (is_array($ops) && is_array($ops[$operation] ?? null)) {
            return (string) ($ops[$operation]['name'] ?? $operation);
        }
        return $operation;
    }

    // ── Fiskální období (zrcadlí DocDocument) ────────────────────────────────

    private function resolveFiscalYearId(string $accountingDate): ?int
    {
        if ($accountingDate === '') {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_years]
             WHERE [date_begin] <= %d AND [date_end] >= %d
               AND [docState] != %i
             ORDER BY [date_begin] DESC
             LIMIT 1',
            $accountingDate, $accountingDate,
            self::DOC_STATE_DELETED,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    private function resolveFiscalMonthId(string $accountingDate): ?int
    {
        if ($accountingDate === '') {
            return null;
        }
        // Běžné měsíce (period_type = 1), bez Počátečního (0) / Závěrkového (2).
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_codebooks_fiscal_months]
             WHERE [date_begin] <= %d AND [date_end] >= %d AND [period_type] = 1
             LIMIT 1',
            $accountingDate, $accountingDate,
        );
        return $row !== null ? (int) $row['id'] : null;
    }

    // ── Zápis ─────────────────────────────────────────────────────────────

    /**
     * DELETE starého deníku + INSERT nových řádků + update transakce, vše
     * v jedné transakci. $lines může být prázdné (chybové stavy bez deníku).
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $tx
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null}>}
     */
    private function writeResult(
        int $txId,
        array $lines,
        array $tx,
        string $accountingDate,
        ?int $fiscalYear,
        ?int $fiscalMonth,
    ): array {
        $state = $this->messages === [] ? 1 : 2;
        $currency = (string) ($tx['currency'] ?? '');
        $statementNumber = $this->statementNumber($tx);

        $this->db->begin();
        try {
            $this->db->delete('economy_accounting_journal')
                ->where('bank_transaction = %i', $txId)
                ->execute();

            foreach ($lines as $line) {
                $this->db->insert('economy_accounting_journal', [
                    'source_kind'      => 'bankTransaction',
                    'doc_head'         => null,
                    'bank_transaction' => $txId,
                    'doc_type'         => null,
                    'doc_number'       => $statementNumber,
                    'accounting_date'  => $accountingDate !== '' ? $accountingDate : null,
                    'fiscal_year'      => $fiscalYear,
                    'fiscal_month'     => $fiscalMonth,
                    'account'          => $line['account'],
                    'account_number'   => $line['account_number'],
                    'is_error'         => $line['is_error'] ? 1 : 0,
                    'operation'        => $line['operation'],
                    'money_dr'         => $line['money_dr'],
                    'money_cr'         => $line['money_cr'],
                    'currency'         => $currency !== '' ? $currency : null,
                    'money_dr_cur'     => $line['money_dr_cur'],
                    'money_cr_cur'     => $line['money_cr_cur'],
                    'partner'          => $line['partner'],
                    'text'             => $line['text'],
                    // Platební identita z transakce; splatnost transakce nemá.
                    'payment_reference' => $tx['payment_reference'] ?? null,
                    'specific_symbol'   => $tx['specific_symbol'] ?? null,
                    'constant_symbol'   => $tx['constant_symbol'] ?? null,
                    'due_date'          => null,
                ])->execute();
            }

            $this->db->update('economy_bank_transactions', [
                'accounting_state'    => $state,
                'accounting_messages' => $this->messages === []
                    ? null
                    : json_encode($this->messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->where('id = %i', $txId)->execute();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        // Po commitu (deník zapsán): saldo si pohyby transakce (re)derivuje.
        $this->journalEvents?->dispatchJournalWritten('bankTransaction', $txId);

        return ['state' => $state, 'messages' => $this->messages];
    }

    /** Číslo navázaného výpisu (traceabilita), NULL když transakce není ve výpisu. */
    private function statementNumber(array $tx): ?string
    {
        $statementId = (int) ($tx['statement'] ?? 0);
        if ($statementId <= 0) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [statement_number] FROM [economy_bank_statements] WHERE [id] = %i',
            $statementId,
        );
        $number = $row !== null ? trim((string) ($row['statement_number'] ?? '')) : '';
        return $number !== '' ? $number : null;
    }

    private function addMessage(string $code, string $message, ?int $rowId = null): void
    {
        $this->messages[] = ['code' => $code, 'message' => $message, 'rowId' => $rowId];
    }
}
