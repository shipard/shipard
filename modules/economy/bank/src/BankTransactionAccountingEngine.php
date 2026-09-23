<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalLineView;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Core\Accounting\NullOpenItemLookup;
use Shipard\Core\Accounting\OpenItemLookup;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\JournalEventDispatcher;
use Shipard\Module\Economy\Accounting\AccountingRules;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;
use Shipard\Module\Economy\Accounting\JournalContributions;

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
 * 321xxx, ale i 336xxx… — účty z nastavení saldokont). Skupina s kategorií
 * úhrady (`OpenItem::paymentCategory`, #79 D3a — zálohové faktury vydané)
 * místo účtu předpisu dá masku kategorie (`advances.received` → 324): peníze
 * na podrozvahu nepatří. Miss → clearing dle
 * masky (261200/261300). Spárovanost tedy
 * nenese `operation` ani žádný stav na transakci; reaccount je idempotentní
 * a bez paměti (vlastní úhrada se z rezidua vylučuje).
 *
 * Dva řádky stejné částky → deník je z principu vyrovnaný; žádná penny
 * reconciliation. Filozofie chyb stejná jako u dokladů: účtování nikdy
 * neblokuje transakci. Nedohledaný účet → chybový řádek (account NULL,
 * maska s '?', is_error) + message; chybějící fiskální období → prázdný
 * deník. Výsledek vždy accounting_state 1 (OK) / 2 (chybová zpráva
 * v messages; zpráva úrovně `warning` stav nemění).
 *
 * Contributoři deníku (#79 D3b, `journalContributors` v module.jsonc):
 * po sestavení obou řádků engine předá kontext a pohledy na řádky
 * (identita z transakce) registrovaným contributorům a jejich požadavky
 * doplní jako další řádky (účet dle kategorie / přesného čísla, text
 * z požadavku, operace NULL) — pak teprve kontrola vyrovnanosti a zápis.
 * Identitu řádků píše writeResult z transakce, takže požadavek s jinou
 * identitou je chyba kontraktu (LogicException), ne datová chyba.
 * Prázdná sada = chování beze změny; výjimka contributoru = varování
 * `contributor_failed`, deník bez příspěvku.
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

    /** @var list<array{code: string, message: string, rowId: int|null, level?: string}> */
    private array $messages = [];

    private readonly OpenItemLookup $openItems;

    private readonly JournalContributorSet $contributors;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?JournalEventDispatcher $journalEvents = null,
        ?OpenItemLookup $openItems = null,
        ?JournalContributorSet $contributors = null,
    ) {
        // DS bez saldokonta (nebo engine postavený bez lookupu) → vše na clearing.
        $this->openItems = $openItems ?? new NullOpenItemLookup();
        // DS bez přispívajícího modulu (nebo engine postavený bez sady) → beze změny.
        $this->contributors = $contributors ?? JournalContributorSet::empty();
    }

    /**
     * Přeúčtuje transakci: smaže starý deník, vygeneruje nový, uloží stav.
     *
     * @return array{state: int, messages: list<array{code: string, message: string, rowId: int|null, level?: string}>}
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

        $lines = $this->applyContributions($txId, $tx, $lines, $accountingDate, $fiscalYear);

        // Pojistka: obě strany nesou stejnou částku, deník má být vyrovnaný
        // — i s příspěvky contributorů (nevyrovnaný požadavek se tu projeví).
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
     * Zásah ve skupině s kategorií úhrady (#79 D3a, `paymentCategory`):
     * účet = maska kategorie předpisu (`advances.received` → 324) přes
     * maskResolver, ne účet předpisu (756 je podrozvaha). Strana zápisu
     * dál plyne ze směru (příjem → 324 DAL), saldo z 324 DAL udělá předpis
     * přijaté zálohy pod klíčem proformy (D23). Chybějící maska nebo účet
     * = `account_not_found` jako u masky kategorie.
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

        if ($item->paymentCategory !== null) {
            $cat = $item->paymentCategory;
            $mask = $this->maskForCategory($cat);
            if ($mask === '') {
                $this->addMessage('account_not_found', "Předpis nemá masku pro kategorii '{$cat}' (úhrada předpisu {$item->accountNumber})");
                return ['number' => str_repeat('?', self::ACCOUNT_NUMBER_LENGTH), 'is_error' => true];
            }
            $account = $this->maskResolver->resolve($mask, $accountingDate);
            if ($account === null) {
                $this->addMessage('account_not_found', "Účet nenalezen pro masku {$mask} (kategorie '{$cat}', úhrada předpisu {$item->accountNumber})");
                return ['number' => str_pad($mask, self::ACCOUNT_NUMBER_LENGTH, '?'), 'is_error' => true];
            }
            return $account;
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

    /**
     * První maska v sekci `accounts` předpisu se shodnou kategorií
     * ({@see AccountingRules::firstMaskForCategory}) — transakce nemá
     * řádek, nad kterým by se hodnotilo `query`.
     */
    private function maskForCategory(string $cat): string
    {
        return AccountingRules::firstMaskForCategory($this->resolveRules(), $cat);
    }

    /**
     * Účtovací předpis dle země vlastní firmy, fallback cz — stejný zdroj
     * jako doklady ({@see AccountingRules::resolve}).
     *
     * @return array<string, mixed>
     */
    private function resolveRules(): array
    {
        return AccountingRules::resolve($this->config, $this->db) ?? [];
    }

    // ── Contributoři deníku (#79 D3b) ───────────────────────────────────────

    /**
     * Krok contributorů: kontext transakce + pohledy na oba řádky (identita
     * z transakce, chybové řádky vynechány) → požadavky → řádky deníku
     * (účet dle kategorie / přesného čísla, text z požadavku, operace NULL).
     * Identitu řádků píše writeResult z transakce — požadavek s jinou
     * identitou je chyba contributoru, ne dat (LogicException).
     *
     * @param array<string, mixed> $tx
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function applyContributions(int $txId, array $tx, array $lines, string $accountingDate, int $fiscalYear): array
    {
        if ($this->contributors->isEmpty()) {
            return $lines;
        }

        $partner = isset($tx['partner']) && $tx['partner'] !== null ? (int) $tx['partner'] : null;
        $paymentReference = self::symbol($tx['payment_reference'] ?? null);
        $specificSymbol   = self::symbol($tx['specific_symbol'] ?? null);

        $context = new JournalSourceContext(
            'bankTransaction',
            $txId,
            $accountingDate,
            $fiscalYear,
            strtolower(trim((string) ($tx['currency'] ?? ''))),
        );

        $views = [];
        foreach ($lines as $line) {
            if ($line['is_error']) {
                continue;
            }
            $side = (int) $line['side'];
            $views[] = new JournalLineView(
                $side,
                (string) $line['account_number'],
                $line['operation'],
                $partner,
                $paymentReference,
                $specificSymbol,
                (float) ($side === 0 ? $line['money_dr'] : $line['money_cr']),
                (float) ($side === 0 ? $line['money_dr_cur'] : $line['money_cr_cur']),
            );
        }

        $requests = JournalContributions::collect(
            $this->contributors,
            $context,
            $views,
            fn(string $code, string $message) => $this->addMessage($code, $message, null, 'warning'),
        );
        if ($requests === []) {
            return $lines;
        }

        $rules = $this->resolveRules();
        foreach ($requests as $request) {
            if ($request->partner !== $partner
                || self::symbol($request->paymentReference) !== $paymentReference
                || self::symbol($request->specificSymbol) !== $specificSymbol
            ) {
                throw new \LogicException(sprintf(
                    'JournalLineRequest identity (partner %s, VS %s, SS %s) differs from bank transaction #%d (partner %s, VS %s, SS %s)',
                    (string) ($request->partner ?? 'null'), (string) ($request->paymentReference ?? 'null'), (string) ($request->specificSymbol ?? 'null'),
                    $txId,
                    (string) ($partner ?? 'null'), (string) ($paymentReference ?? 'null'), (string) ($specificSymbol ?? 'null'),
                ));
            }
            $account = JournalContributions::resolveAccount(
                $request,
                $rules,
                $this->maskResolver,
                $accountingDate,
                fn(string $code, string $message) => $this->addMessage($code, $message),
            );
            $lines[] = $this->makeLine($tx, $request->side, $account, $request->moneyDom, $request->moneyCur, null, $request->text);
        }
        return $lines;
    }

    /** Normalizace symbolu pro porovnání identity: TRIM, prázdné → null. */
    private static function symbol(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    // ── Skladba řádku ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $tx
     * @param array{id?: int, number: string, is_error?: bool} $account
     * @return array<string, mixed>
     */
    private function makeLine(array $tx, int $side, array $account, float $dom, float $cur, ?string $operation, ?string $text = null): array
    {
        return [
            'side'           => $side,
            'account'        => $account['id'] ?? null,
            'account_number' => $account['number'],
            'is_error'       => !empty($account['is_error']),
            'operation'      => $operation,
            'partner'        => isset($tx['partner']) && $tx['partner'] !== null ? (int) $tx['partner'] : null,
            'text'           => mb_substr($text ?? $this->buildText($tx), 0, 200),
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
        $state = $this->hasErrors() ? 2 : 1;
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

    /**
     * Zpráva účtování; `level` 'error' (default, stav 2) nebo 'warning'
     * (jen informace, stav zůstává 1 — selhání contributoru, #79 D3b).
     * Pole `level` se zapisuje jen u varování.
     */
    private function addMessage(string $code, string $message, ?int $rowId = null, string $level = 'error'): void
    {
        $entry = ['code' => $code, 'message' => $message, 'rowId' => $rowId];
        if ($level !== 'error') {
            $entry['level'] = $level;
        }
        $this->messages[] = $entry;
    }

    /** Aspoň jedna zpráva bez úrovně warning → stav účtování 2. */
    private function hasErrors(): bool
    {
        foreach ($this->messages as $message) {
            if (($message['level'] ?? 'error') === 'error') {
                return true;
            }
        }
        return false;
    }
}
