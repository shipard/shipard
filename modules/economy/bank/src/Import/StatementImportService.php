<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Bank\BankStatementDocument;
use Shipard\Module\World\Validations\BankAccountKey;
use Shipard\Module\World\Validations\DomesticAccount;

/**
 * Orchestrace importu bankovního výpisu: detekce + parse → match našeho účtu →
 * najít/založit výpis → per transakce dedup (external_id / fingerprint) +
 * vznik ve stavu `targetState` → dohledání partnera → zůstatkový můstek →
 * uložení zdrojového souboru jako příloha.
 *
 * Apply jádro (`applyParsedStatement`) je sdílené souborovým importem (Fáze 2,
 * `targetState = 10`) i migrací přes výměnný formát (Fáze 4 `BankStatementApplier`,
 * `targetState = 40` → účtování). Transakce vznikají přes dokumentovou vrstvu
 * (`TableGateway`), takže přechod do stavu 40 spustí `BankTransactionEventHandler`
 * (účtování). Vznik přes core `TableGateway` (ne exchange variantu) drží směr
 * závislostí (economy.bank nezná core.exchange); `applyParsedStatement` vlastní
 * vnější transakci, vnořené begin/commit gatewaye i účetního enginu jsou
 * savepointy (dibi).
 *
 * Idempotentní: opětovný import/apply téhož vstupu → vše skipped. Per-výpis
 * transakce (jeden vadný výpis neshodí ostatní).
 */
class StatementImportService
{
    private const TABLE_TX = 'economy_bank_transactions';
    private const TABLE_STMT = 'economy_bank_statements';
    private const STATEMENT_TABLE_ID = 415;

    /**
     * Stavy, ve kterých je entita odkazovatelná (vyloučen jen smazaný 90) —
     * import zpracovává historická data, archivní účet/osoba je legitimní
     * cíl reference. Stejná konvence jako `DocumentApplier::LINKABLE_STATES`.
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    private readonly PartnerResolver $partnerResolver;

    private ?string $homeCurrency = null;

    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ConfigRuntime $config,
        private readonly ?AttachmentService $attachments = null,
        private readonly ?TableGateway $txGateway = null,
        /** Kurz k datu transakce pro účet v cizí měně (#121 D7); null = DS bez modulu kurzů. */
        private readonly ?ExchangeRateResolver $exchangeRateResolver = null,
    ) {
        $this->partnerResolver = new PartnerResolver($db);
    }

    /**
     * Produkční factory — postaví core TableGateway pro transakce (s event
     * dispatcherem, aby vznik ve stavu 40 spustil účtování) a vrátí službu.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public static function create(
        \Dibi\Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?DocumentEventDispatcher $eventDispatcher = null,
        ?AttachmentService $attachments = null,
    ): self {
        $childTables = $tables[self::TABLE_TX]?->childTables ?? [];
        $txGateway = new TableGateway(
            self::TABLE_TX,
            $db,
            $registry,
            $childTables,
            $config,
            $dsConfig,
            $eventDispatcher,
            $tables[self::TABLE_TX]?->docStates,
            $tables[self::TABLE_TX] ?? null,
        );
        $exchangeRates = isset($tables[ExchangeRateResolver::TABLE_LISTS])
            ? ExchangeRateResolver::create($db, $config, $dsConfig)
            : null;
        return new self($db, $config, $attachments, $txGateway, $exchangeRates);
    }

    /**
     * @return array<string, mixed> souhrn importu
     * @throws ImportException nerozpoznaný formát / charset
     */
    public function import(
        string $rawContent,
        ?string $accountOverride = null,
        ?string $sourceFilePath = null,
        ?string $sourceFileName = null,
        ?int $userId = null,
    ): array {
        $detector = new StatementFormatDetector($this->config->cfgItem('economy.bank.statementFormats') ?? []);
        $detected = $detector->detect($rawContent);
        $text = $detector->decode($rawContent, $detected['srcCharset']);
        $parser = (new StatementParserRegistry())->parserFor($detected['formatId']);
        $statements = $parser->parse($text);

        $summary = [
            'format'           => $detected['formatId'],
            'statements'       => [],
            'created'          => 0,
            'skipped'          => 0,
            'unmatchedPartner' => 0,
        ];

        foreach ($statements as $parsed) {
            try {
                $account = $this->matchBankAccount($parsed->bankAccountRef, $accountOverride);
                $result = $this->applyParsedStatement($parsed, (int) $account['id'], 10);
            } catch (ImportException $e) {
                $summary['statements'][] = [
                    'bankAccountRef' => $parsed->bankAccountRef,
                    'error'          => $e->getMessage(),
                ];
                continue;
            }

            // Příloha (provenience) — po commitu, nekritické.
            if ($result['statementId'] > 0 && $this->attachments !== null && $sourceFilePath !== null) {
                try {
                    $this->attachments->upload(
                        self::STATEMENT_TABLE_ID,
                        $result['statementId'],
                        $sourceFileName ?? basename($sourceFilePath),
                        $sourceFilePath,
                        $userId,
                    );
                    $result['attached'] = true;
                } catch (\Throwable $e) {
                    $result['attached'] = false;
                    $result['attachError'] = $e->getMessage();
                }
            }

            $summary['statements'][] = $result;
            $summary['created'] += $result['created'];
            $summary['skipped'] += $result['skipped'];
            $summary['unmatchedPartner'] += $result['unmatchedPartner'];
        }

        return $summary;
    }

    /**
     * Sdílené apply jádro — vznik výpisu + transakcí z již naparsované
     * `ParsedStatement` na zadaný účet (`bankAccountId`), ve stavu
     * `targetState` (10 = Nová, 40 = Zaúčtováno → spustí účetní engine).
     * Vlastní transakce per výpis (atomická hlavička + transakce + můstek).
     *
     * Volá souborový import (`import()` s targetState 10) i migrace
     * (`BankStatementApplier` přes výměnný formát). `createMissingPartner` je
     * rezervovaný (auto-create partnera z protistrany je rozšíření, viz
     * docs/bank.md §7 — saldo/párování se nemigruje).
     *
     * @return array<string, mixed> souhrn (created/skipped/unmatched/reconciliation)
     * @throws ImportException účet nenalezen/neaktivní nebo neplatný výpis
     */
    public function applyParsedStatement(
        ParsedStatement $stmt,
        int $bankAccountId,
        int $targetState = 10,
        bool $createMissingPartner = false,
    ): array {
        if ($this->txGateway === null) {
            throw new \LogicException(
                'StatementImportService vyžaduje transakční gateway; použij StatementImportService::create().',
            );
        }

        $account = $this->loadBankAccount($bankAccountId);
        $accountCurrency = strtolower((string) ($account['currency'] ?? ''));

        $currency = $accountCurrency;
        $currencyWarning = null;
        $stmtCcy = $stmt->currency !== null ? strtolower($stmt->currency) : null;
        if ($stmtCcy !== null && $accountCurrency !== '' && $stmtCcy !== $accountCurrency) {
            $currencyWarning = "Měna výpisu ({$stmtCcy}) se liší od měny účtu ({$accountCurrency}); použita měna účtu.";
        } elseif ($stmtCcy !== null && $accountCurrency === '') {
            $currency = $stmtCcy;
        }

        $statesCfg = DocStateConfig::fromCfgItem($this->config->cfgItem('economy.bank.txStates'));
        $mainState = $statesCfg->getMainState($targetState);

        // Kurzy pro celý výpis PŘED transakcí (#121 D7): chybí-li lístek pro
        // kterékoli datum, výpis se nezapíše vůbec.
        $rates = $this->resolveExchangeRates($stmt, $currency);

        $created = 0;
        $skipped = 0;
        $unmatchedPartner = 0;
        $txErrors = [];
        $seqInDay = [];

        $this->db->begin();
        try {
            $statementId = $this->findOrCreateStatement($stmt, $bankAccountId, $currency, $targetState);

            foreach ($stmt->transactions as $i => $tx) {
                $direction = $tx->amount < 0 ? 2 : 1;
                $amount = abs($tx->amount);
                $rate = $rates[$i];
                $dateKey = $tx->dateTransaction->format('Y-m-d');
                $seqInDay[$dateKey] = ($seqInDay[$dateKey] ?? -1) + 1;

                $fingerprint = $this->fingerprint($bankAccountId, $tx, $direction, $amount, $dateKey, $seqInDay[$dateKey]);

                // Partner: přednost explicitního partnera z migrace (partnerId),
                // fallback na párování přes protiúčet. Spočítané PŘED dedup blokem,
                // aby šlo backfillnout i do dříve naimportovaných transakcí.
                $partnerId = $this->resolvePartner($tx);

                $existingId = $this->findExisting($bankAccountId, $tx->externalId, $fingerprint);
                if ($existingId !== null) {
                    $this->backfill($existingId, $statementId, $tx->externalId, $fingerprint, $partnerId);
                    $skipped++;
                    continue;
                }

                $row = [
                    'bank_account'         => $bankAccountId,
                    'statement'            => $statementId,
                    'direction'            => $direction,
                    'amount'               => round($amount, 2),
                    'currency'             => $currency,
                    'exchange_rate'        => $rate,
                    'amount_dom'           => round($amount * $rate, 2),
                    'date_transaction'     => $dateKey,
                    'date_value'           => $tx->dateValue?->format('Y-m-d'),
                    'counterparty_account' => $tx->counterpartyAccount,
                    'counterparty_name'    => $this->cap($tx->counterpartyName, 150),
                    'payment_reference'    => $this->cap($tx->paymentReference, 35),
                    'specific_symbol'      => $this->cap($tx->specificSymbol, 20),
                    'constant_symbol'      => $this->cap($tx->constantSymbol, 10),
                    'message'              => $this->cap($tx->message, 250),
                    'operation'            => $tx->operation ?? ($direction === 1 ? 'payment.in' : 'payment.out'),
                    'external_id'          => $tx->externalId,
                    'fingerprint'          => $fingerprint,
                    'partner'              => $partnerId,
                    'accounting_state'     => 0,
                    'docState'             => $targetState,
                    'docStateMain'         => $mainState,
                ];

                // Vznik přes dokumentovou vrstvu — přechod do 40 spustí
                // BankTransactionEventHandler (účtování). Selhání jedné
                // transakce (savepoint rollback) neshodí zbytek výpisu.
                $result = $this->txGateway->saveDocument($row);
                if (!$result->isSuccess()) {
                    $txErrors[] = [
                        'date'   => $dateKey,
                        'amount' => $amount,
                        'errors' => $this->resultErrors($result),
                    ];
                    continue;
                }

                if ($partnerId === null) {
                    $unmatchedPartner++;
                }
                $created++;
            }

            $reconciliation = $this->reconcile($statementId, $stmt->openingBalance, $stmt->closingBalance);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return [
            'bankAccountRef'   => $stmt->bankAccountRef,
            'statementId'      => $statementId,
            'created'          => $created,
            'skipped'          => $skipped,
            'unmatchedPartner' => $unmatchedPartner,
            'reconciliation'   => $reconciliation,
            'currencyWarning'  => $currencyWarning,
            'txErrors'         => $txErrors,
        ];
    }

    /**
     * Kurz per transakce v pořadí `$stmt->transactions` (#121 D7): účet
     * v domácí měně → 1 (kurz z výpisu se ignoruje); cizí měna → kurz
     * z výpisu (migrace, kurz za jednotku), bez něj kurz z lístku k datu
     * transakce. Nikdy tiché 1: chybí-li lístek, ImportException se
     * seznamem dat a nic se nezapíše.
     *
     * @return array<int, float>
     * @throws ImportException chybějící kurz
     */
    protected function resolveExchangeRates(ParsedStatement $stmt, string $currency): array
    {
        $currency = strtolower($currency);
        $home = $this->homeCurrency();
        $rates = [];
        $missing = [];
        foreach ($stmt->transactions as $i => $tx) {
            if ($currency === '' || $currency === $home) {
                $rates[$i] = 1.0;
                continue;
            }
            if ($tx->exchangeRate !== null && $tx->exchangeRate > 0) {
                $rates[$i] = (float) $tx->exchangeRate;
                continue;
            }
            $rate = $this->exchangeRateResolver?->rateFor($currency, $tx->dateTransaction);
            if ($rate === null) {
                $missing[$tx->dateTransaction->format('Y-m-d')] = true;
                $rates[$i] = 0.0;
                continue;
            }
            $rates[$i] = $rate->rate;
        }
        if ($missing !== []) {
            $dates = array_keys($missing);
            sort($dates);
            throw new ImportException(sprintf(
                'Kurz %s chybí pro data: %s — nahraj kurzovní lístky (shpd-ds exchange-rates-import).',
                strtoupper($currency),
                implode(', ', $dates),
            ));
        }
        return $rates;
    }

    /** Domácí měna zdroje dat (z resolveru, jinak z nastavení; nerozhodnuto = czk). */
    protected function homeCurrency(): string
    {
        return $this->homeCurrency ??= $this->exchangeRateResolver?->homeCurrency()
            ?? ExchangeRateResolver::homeCurrencyOf(new SettingsStore(new DataSourceConnection($this->db)));
    }

    /**
     * Načte vlastní bankovní účet dle id (exchange/migrace ho zná přímo).
     * Archivní účet (70) je odkazovatelný — výpis pro později archivovaný
     * účet je legitimní historické datum (poslední výpis chodí i po uzavření).
     *
     * @return array<string, mixed>
     * @throws ImportException nenalezen / smazán
     */
    protected function loadBankAccount(int $id): array
    {
        $row = $this->db->query(
            'SELECT [id], [currency], [code] FROM %n WHERE [id] = %i AND [docState] IN %in',
            self::tableBankAccounts(),
            $id,
            self::LINKABLE_STATES,
        )->fetch();
        if ($row === false || $row === null) {
            throw new ImportException("Bankovní účet #{$id} nenalezen nebo smazán.");
        }
        return (array) $row;
    }

    /**
     * Chybové hlášky z neúspěšného saveDocument (validace / doménová chyba).
     *
     * @return list<array<string, mixed>>
     */
    private function resultErrors(\Shipard\Core\Document\DocumentResult $result): array
    {
        $validation = $result->getValidation();
        if ($validation !== null && !$validation->isValid()) {
            return $validation->toArray();
        }
        return [['message' => $result->getErrorMessage() ?? 'Uložení transakce selhalo.']];
    }

    /**
     * Match našeho účtu (port konceptu `checkBankAccount`) přes account_number
     * / iban / ebanking_id (klíč účtu, #118 D23) a u odkazu bez kódu banky
     * přes předčíslí a číslo tuzemského čísla účtu (D25). Override
     * z CLI/endpointu má přednost. Matchuje i archivní účty (70) —
     * importovaný výpis k později archivovanému účtu je historické datum,
     * ne nová aktivita.
     *
     * @return array<string, mixed>
     * @throws ImportException nenalezen
     */
    private function matchBankAccount(string $ref, ?string $override): array
    {
        if ($override !== null && trim($override) !== '') {
            $row = ctype_digit($override)
                ? $this->db->query('SELECT [id], [currency], [code] FROM %n WHERE [id] = %i', self::tableBankAccounts(), (int) $override)->fetch()
                : $this->db->query('SELECT [id], [currency], [code] FROM %n WHERE [code] = %s', self::tableBankAccounts(), $override)->fetch();
            if ($row === false || $row === null) {
                throw new ImportException("Bankovní spojení '{$override}' (--account) nenalezeno.");
            }
            return (array) $row;
        }

        // Klíč účtu (#118 D23): IBAN i tuzemské číslo dávají tentýž klíč, takže
        // CAMT (IBAN) najde účet uložený číslem a naopak; ID v ebankingu je
        // libovolný řetězec — porovnává se jako `raw:` klíč.
        $refKey = BankAccountKey::of($ref);
        $accounts = $this->loadOwnAccounts();

        foreach ($accounts as $a) {
            foreach (['account_number', 'iban', 'ebanking_id'] as $column) {
                $value = $a[$column] ?? null;
                if ($refKey !== null && is_string($value) && BankAccountKey::of($value) === $refKey) {
                    return (array) $a;
                }
            }
        }

        // Odkaz bez kódu banky (#118 D25) — hlavička GPC nese jen předčíslí
        // a číslo: porovnat s tuzemským číslem vlastních účtů podle předčíslí
        // a čísla bez vedoucích nul a přijmout jen jedinou shodu (chování
        // starého `checkBankAccount`). IBAN ani ID v ebankingu se nehádají.
        $bare = self::bareDomesticAccount($ref);
        if ($bare !== null) {
            $hits = [];
            foreach ($accounts as $a) {
                $domestic = DomesticAccount::parse((string) ($a['account_number'] ?? ''));
                if ($domestic !== null && $domestic->prefix === $bare['prefix'] && $domestic->number === $bare['number']) {
                    $hits[] = $a;
                }
            }
            if (count($hits) === 1) {
                return (array) $hits[0];
            }
        }

        throw new ImportException(
            "Účet z výpisu '{$ref}' není v žádném vlastním bankovním spojení "
            . '(Číslo účtu / IBAN / ID v ebankingu). Výpis přeskočen.',
        );
    }

    /**
     * Vlastní účty v odkazovatelných stavech (i archivní 70) — šev pro testy.
     *
     * @return list<array<string, mixed>|\Dibi\Row>
     */
    protected function loadOwnAccounts(): array
    {
        return $this->db->query(
            'SELECT [id], [currency], [code], [account_number], [iban], [ebanking_id]'
            . ' FROM %n WHERE [docState] IN %in',
            self::tableBankAccounts(),
            self::LINKABLE_STATES,
        )->fetchAll();
    }

    /**
     * Holé tuzemské číslo bez kódu banky (`[předčíslí-]číslo`) bez mezer
     * a vedoucích nul; jiný tvar (IBAN, číslo s kódem, ID v ebankingu) `null`.
     *
     * @return array{prefix: string, number: string}|null
     */
    private static function bareDomesticAccount(string $ref): ?array
    {
        $s = (string) preg_replace('/\s+/u', '', trim($ref));
        if (preg_match('~^(?:([0-9]+)-)?([0-9]+)$~', $s, $m) !== 1) {
            return null;
        }
        $number = ltrim($m[2], '0');
        return ['prefix' => ltrim($m[1], '0'), 'number' => $number === '' ? '0' : $number];
    }

    /**
     * Identita výpisu — pořadí párování:
     *   1. `(bank_account, external_id)` — přesná identita (migrace, API),
     *   2. `(bank_account, statement_number, period_start, period_end)` —
     *      number-aware fallback; NULL číslo páruje jen s NULL. Nese-li
     *      payload external_id, matchují se jen řádky bez něj (jiné
     *      external_id = jiný výpis, byť se stejným číslem i periodou) a
     *      nalezený výpis ho backfillem dostane (vzor transakcí),
     *   3. jinak create.
     * Známé omezení: dva bezčíselné výpisy bez external_id se stejnou
     * periodou se slijí — reálné soubory číslo výpisu nesou.
     */
    private function findOrCreateStatement(ParsedStatement $parsed, int $accountId, string $currency, int $targetState = 10): int
    {
        $start = $parsed->periodStart->format('Y-m-d');
        $end = $parsed->periodEnd->format('Y-m-d');

        // Stav výpisu zrcadlí targetState transakcí: souborový import zakládá
        // koncept (10), migrace „hotového" výpisu rovnou „V pořádku" (40).
        // docStateMain z archivační sady stavů (economy_bank_statements →
        // core.system.docStatesArchive: 10→1, 40→3).
        $stmtStates = DocStateConfig::fromCfgItem($this->config->cfgItem('core.system.docStatesArchive'));

        $row = null;
        if ($parsed->externalId !== null) {
            $row = $this->db->query(
                'SELECT [id], [docState], [external_id] FROM %n WHERE [bank_account] = %i AND [external_id] = %s LIMIT 1',
                self::TABLE_STMT,
                $accountId,
                $parsed->externalId,
            )->fetch();
        }
        if ($row === false || $row === null) {
            $cond = [
                ['[bank_account] = %i', $accountId],
                $parsed->statementNumber === null
                    ? ['[statement_number] IS NULL']
                    : ['[statement_number] = %s', $parsed->statementNumber],
                ['[period_start] = %s', $start],
                ['[period_end] = %s', $end],
            ];
            if ($parsed->externalId !== null) {
                $cond[] = ['[external_id] IS NULL'];
            }
            $row = $this->db->query(
                'SELECT [id], [docState], [external_id] FROM %n WHERE %and LIMIT 1',
                self::TABLE_STMT,
                $cond,
            )->fetch();
        }
        if ($row !== false && $row !== null) {
            $existingId = (int) $row['id'];
            $set = [];
            // Backfill identity: výpis založený před zavedením external_id
            // (nebo souborem) ho při shodě čísla + periody dostane.
            if ($parsed->externalId !== null && ($row['external_id'] ?? null) === null) {
                $set['external_id'] = $parsed->externalId;
            }
            // Self-healing re-import: koncept (10) povýšit na cílový stav (migrace
            // „hotového" výpisu po opravě / re-importu). Vyšší nebo ručně nastavené
            // stavy (40/80/70/90) neměníme. Souborový import (targetState=10) nikdy
            // nepovyšuje.
            if ($targetState !== 10 && (int) ($row['docState'] ?? 10) === 10) {
                $set['docState'] = $targetState;
                $set['docStateMain'] = $stmtStates->getMainState($targetState);
            }
            if ($set !== []) {
                $this->db->update(self::TABLE_STMT, $set)->where('[id] = %i', $existingId)->execute();
            }
            return $existingId;
        }

        $stmtRow = [
            'bank_account'         => $accountId,
            'statement_number'     => $parsed->statementNumber,
            'external_id'          => $parsed->externalId,
            'period_start'         => $start,
            'period_end'           => $end,
            'opening_balance'      => round($parsed->openingBalance, 2),
            'closing_balance'      => round($parsed->closingBalance, 2),
            'currency'             => $currency,
            'reconciliation_state' => 0,
            'docState'             => $targetState,
            'docStateMain'         => $stmtStates->getMainState($targetState),
        ];

        $doc = new BankStatementDocument();
        $doc->setDb($this->db);
        $validation = $doc->validate($stmtRow);
        if (!$validation->isValid()) {
            $messages = array_map(static fn($e) => $e['message'], $validation->toArray());
            throw new ImportException('Neplatný výpis: ' . implode('; ', $messages));
        }
        $doc->beforeSave($stmtRow);
        $this->db->insert(self::TABLE_STMT, $stmtRow)->execute();

        return (int) $this->db->getInsertId();
    }

    private function findExisting(int $accountId, ?string $externalId, string $fingerprint): ?int
    {
        if ($externalId !== null) {
            $row = $this->db->query(
                'SELECT [id] FROM %n WHERE [bank_account] = %i AND [external_id] = %s LIMIT 1',
                self::TABLE_TX,
                $accountId,
                $externalId,
            )->fetch();
            return ($row !== false && $row !== null) ? (int) $row['id'] : null;
        }

        $row = $this->db->query(
            'SELECT [id] FROM %n WHERE [bank_account] = %i AND [fingerprint] = %s LIMIT 1',
            self::TABLE_TX,
            $accountId,
            $fingerprint,
        )->fetch();
        return ($row !== false && $row !== null) ? (int) $row['id'] : null;
    }

    private function backfill(int $txId, int $statementId, ?string $externalId, string $fingerprint, ?int $partnerId = null): void
    {
        $set = [];
        $existing = $this->db->query('SELECT [statement], [external_id], [fingerprint], [partner] FROM %n WHERE [id] = %i', self::TABLE_TX, $txId)->fetch();
        if ($existing === false || $existing === null) {
            return;
        }
        if (($existing['statement'] ?? null) === null) {
            $set['statement'] = $statementId;
        }
        if (($existing['external_id'] ?? null) === null && $externalId !== null) {
            $set['external_id'] = $externalId;
        }
        if (($existing['fingerprint'] ?? null) === null) {
            $set['fingerprint'] = $fingerprint;
        }
        // Doplnit partnera do dříve naimportovaných transakcí, kde chybí
        // (re-run migrace s nově dodaným partnerId ze starého řádku).
        if (($existing['partner'] ?? null) === null && $partnerId !== null) {
            $set['partner'] = $partnerId;
        }
        if ($set !== []) {
            $this->db->update(self::TABLE_TX, $set)->where('[id] = %i', $txId)->execute();
        }
    }

    /**
     * Partner transakce: přednost explicitního `partnerId` z migrace (přímý
     * odkaz ze starého řádku, je-li to linkovatelná osoba), jinak fallback na
     * párování přes protiúčet (souborový import partnerId neposílá).
     */
    private function resolvePartner(ParsedTransaction $tx): ?int
    {
        if ($tx->partnerId !== null && $this->personLinkable($tx->partnerId)) {
            return $tx->partnerId;
        }
        return $this->partnerResolver->resolve($tx->counterpartyAccount);
    }

    /**
     * Archivní osoba (70) je odkazovatelná — transakce odkazující později
     * archivovanou osobu si drží přímou vazbu `partner`.
     */
    private function personLinkable(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $row = $this->db->query(
            'SELECT [id] FROM [base_persons_persons] WHERE [id] = %i AND [docState] IN %in',
            $id,
            self::LINKABLE_STATES,
        )->fetch();
        return $row !== false && $row !== null;
    }

    /**
     * sha256 z normalizovaných polí + seqInDay (W4.2) + external_id (je-li).
     * External_id v otisku: obsahově identické opakované platby (paušály se
     * stejným datem, částkou i textem) napříč výpisy jinak kolidují na
     * `unq_fingerprint` — seqInDay se počítá od nuly per apply. Podmíněně,
     * ne `?? ''`: otisk souborových transakcí (bez id) musí zůstat bajtově
     * shodný s už uloženými, jinak se rozpadne dedup přes fingerprint.
     */
    private function fingerprint(int $accountId, ParsedTransaction $tx, int $direction, float $amount, string $dateKey, int $seqInDay): string
    {
        $parts = [
            $accountId,
            $dateKey,
            $direction,
            number_format($amount, 2, '.', ''),
            $tx->counterpartyAccount ?? '',
            $tx->paymentReference ?? '',
            $tx->specificSymbol ?? '',
            $tx->message ?? '',
            $seqInDay,
        ];
        if ($tx->externalId !== null) {
            $parts[] = $tx->externalId;
        }
        return hash('sha256', implode('|', $parts));
    }

    /** Zůstatkový můstek (W5.1). @return int reconciliation_state (0/1/2) */
    private function reconcile(int $statementId, float $opening, float $closing): int
    {
        $rows = $this->db->query(
            'SELECT [direction], [amount] FROM %n WHERE [statement] = %i AND [docState] != 90',
            self::TABLE_TX,
            $statementId,
        )->fetchAll();

        $delta = 0.0;
        foreach ($rows as $r) {
            $amt = (float) $r['amount'];
            $delta += ((int) $r['direction'] === 2) ? -$amt : $amt;
        }

        $state = abs(($opening + $delta) - $closing) <= 0.005 ? 1 : 2;
        $this->db->update(self::TABLE_STMT, ['reconciliation_state' => $state])
            ->where('[id] = %i', $statementId)
            ->execute();

        return $state;
    }

    private function cap(?string $v, int $len): ?string
    {
        if ($v === null) {
            return null;
        }
        return mb_substr($v, 0, $len, 'UTF-8');
    }

    private static function tableBankAccounts(): string
    {
        return 'economy_codebooks_bank_accounts';
    }
}
