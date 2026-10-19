<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange\Bank;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Economy\Accounting\AccountDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Banka Fáze 4 W5.2–W5.5 — apply výměnného formátu shpd.bank.statement.v1
 * přes BankStatementApplier nad reálným DS. targetState 40 vytvoří výpis +
 * transakce a zaúčtuje je (přes sdílené apply jádro + event handler);
 * dedup idempotence, targetState 10, reconciliace.
 */
class BankStatementApplyTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    private ?BankStatementApplier $applier = null;

    private int $bankAccountId = 0;
    /** @var list<int> další účty založené testem (cizí měna) */
    private array $extraBankAccounts = [];
    /** @var list<int> */
    private array $createdAccounts = [];
    /** @var list<int> */
    private array $createdPersons = [];

    protected function setUp(): void
    {
        parent::setUp();

        $fm = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_months
             WHERE date_begin <= %s AND date_end >= %s AND period_type = 1 LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fm === null) {
            $this->markTestSkipped('DS nemá fiskální období pro ' . self::ACC_DATE);
        }

        $resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $this->db->getDibiConnection(), $config);

        $this->applier = BankStatementApplier::create(
            $this->db->getDibiConnection(),
            $config,
            $this->dsConfig,
            $registry,
            $this->tables,
            $dispatcher,
        );

        // Účet pro pohyby (221) + clearing + bankovní spojení s vazbou na 221.
        $bankAccount = $this->ensureAccountByMask('221');
        $this->ensureAccountByNumber('261200');
        $this->ensureAccountByNumber('261300');

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'ITX' . substr(uniqid(), -7),
            'name'               => 'IT F4 účet',
            'currency'           => 'czk',
            'accounting_account' => $bankAccount,
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $this->bankAccountId = (int) $dibi->getInsertId();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->extraBankAccounts as $extraId) {
            $txIds = $dibi->query('SELECT [id] FROM [economy_bank_transactions] WHERE [bank_account] = %i', $extraId)->fetchPairs(null, 'id');
            foreach ($txIds as $tid) {
                $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', (int) $tid)->execute();
            }
            $dibi->delete('economy_bank_transactions')->where('bank_account = %i', $extraId)->execute();
            $dibi->delete('economy_bank_statements')->where('bank_account = %i', $extraId)->execute();
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $extraId)->execute();
        }
        if ($this->bankAccountId > 0) {
            $stmtIds = $dibi->query(
                'SELECT [id] FROM [economy_bank_statements] WHERE [bank_account] = %i',
                $this->bankAccountId,
            )->fetchPairs(null, 'id');
            $txIds = $dibi->query(
                'SELECT [id] FROM [economy_bank_transactions] WHERE [bank_account] = %i',
                $this->bankAccountId,
            )->fetchPairs(null, 'id');
            foreach ($txIds as $tid) {
                $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', (int) $tid)->execute();
            }
            $dibi->delete('economy_bank_transactions')->where('bank_account = %i', $this->bankAccountId)->execute();
            $dibi->delete('economy_bank_statements')->where('bank_account = %i', $this->bankAccountId)->execute();
            $dibi->delete('economy_codebooks_bank_accounts')->where('id = %i', $this->bankAccountId)->execute();
            foreach ($this->createdAccounts as $id) {
                $dibi->delete('economy_accounting_accounts')->where('id = %i', $id)->execute();
            }
            foreach ($this->createdPersons as $id) {
                $dibi->delete('base_persons_persons')->where('id = %i', $id)->execute();
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'format'        => 'shpd.bank.statement',
            'formatVersion' => '1.0',
            'source'        => ['kind' => 'import.oldShipard', 'raw' => ['oldNdx' => 7]],
            'bankAccountId' => $this->bankAccountId,
            'statement'     => [
                'statementNumber' => 'IT-F4/2026-06',
                'periodStart'     => '2026-06-01',
                'periodEnd'       => '2026-06-30',
                'openingBalance'  => 0.0,
                'closingBalance'  => 710.0, // 1210 − 500
                'currency'        => 'CZK',
            ],
            'transactions'  => [
                [
                    'externalId'       => 'oldtx-in-1',
                    'amount'           => 1210.00,
                    'dateTransaction'  => self::ACC_DATE,
                    'counterpartyName' => 'IT příjemce',
                ],
                [
                    'externalId'       => 'oldtx-out-1',
                    'amount'           => -500.00,
                    'dateTransaction'  => self::ACC_DATE,
                    'counterpartyName' => 'IT plátce',
                ],
            ],
            'applyOptions'  => ['targetState' => 40],
        ], $overrides);
    }

    // ── W5.2 Apply ve stavu 40 → zaúčtováno ──────────────────────────────────

    public function testApplyState40CreatesAndAccounts(): void
    {
        $result = $this->applier->apply($this->payload());
        $this->assertTrue($result->success, 'apply selhal: ' . ($result->errorMessage ?? ''));
        $this->assertGreaterThan(0, $result->savedId, 'savedStatementId');

        // Výpis (hlavička) zrcadlí targetState: „hotový" → V pořádku (40), ne koncept.
        $stmt = $this->db->getDibiConnection()->fetch(
            'SELECT docState, docStateMain FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(40, (int) $stmt['docState'], 'výpis ve stavu V pořádku (40)');
        $this->assertSame(3, (int) $stmt['docStateMain'], 'docStateMain pro stav 40');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(40, (int) $r['docState'], 'transakce ve stavu 40');
            $this->assertSame(1, (int) $r['accounting_state'], 'zaúčtováno bez chyby');
        }

        // Deník: 2 řádky/transakce, source_kind bankTransaction, banka + clearing
        $journal = $this->db->getDibiConnection()->fetchAll(
            'SELECT j.* FROM economy_accounting_journal j
             JOIN economy_bank_transactions t ON t.id = j.bank_transaction
             WHERE t.bank_account = %i ORDER BY j.id',
            $this->bankAccountId,
        );
        $this->assertCount(4, $journal, '2 transakce × 2 řádky');
        foreach ($journal as $j) {
            $this->assertSame('bankTransaction', (string) $j['source_kind']);
            $this->assertNull($j['doc_head']);
        }
        $accounts = array_map(static fn($j) => substr((string) $j['account_number'], 0, 6), $journal);
        $this->assertContains('261200', $accounts, 'clearing příjmu');
        $this->assertContains('261300', $accounts, 'clearing výdaje');
    }

    // ── W5.3 Dedup idempotence ───────────────────────────────────────────────

    public function testSecondApplyIsIdempotent(): void
    {
        $this->applier->apply($this->payload());
        $second = $this->applier->apply($this->payload());

        $this->assertTrue($second->success);
        $this->assertSame(0, $second->canonical['_result']['created'], 'opakovaný apply nezakládá');
        $this->assertSame(2, $second->canonical['_result']['skipped']);
        $this->assertCount(2, $this->txRows(), 'žádné zdvojení');
    }

    // ── W5.4 Apply ve stavu 10 → bez deníku ──────────────────────────────────

    public function testApplyState10LeavesUnaccounted(): void
    {
        $result = $this->applier->apply($this->payload(['applyOptions' => ['targetState' => 10]]));
        $this->assertTrue($result->success);

        // Výpis zůstává koncept (10) — souborový import i konceptová migrace.
        $stmt = $this->db->getDibiConnection()->fetch(
            'SELECT docState FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(10, (int) $stmt['docState'], 'výpis zůstává koncept (10)');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(10, (int) $r['docState'], 'transakce Nová');
            $this->assertSame(0, (int) $r['accounting_state']);
        }
        $journalCount = (int) $this->db->getDibiConnection()->fetchSingle(
            'SELECT COUNT(*) FROM economy_accounting_journal j
             JOIN economy_bank_transactions t ON t.id = j.bank_transaction
             WHERE t.bank_account = %i',
            $this->bankAccountId,
        );
        $this->assertSame(0, $journalCount, 'stav 10 negeneruje deník');
    }

    // ── W5.5 Reconciliace ────────────────────────────────────────────────────

    public function testReconciliationMatchesOnBalancedStatement(): void
    {
        $result = $this->applier->apply($this->payload());
        $this->assertTrue($result->success);

        $state = (int) $this->db->getDibiConnection()->fetchSingle(
            'SELECT reconciliation_state FROM economy_bank_statements WHERE id = %i',
            $result->savedId,
        );
        $this->assertSame(1, $state, 'sedící zůstatky → reconciliation_state 1');
        $this->assertSame(1, $result->canonical['_result']['reconciliation']);
    }

    // ── partnerId z migrace (explicitní partner) ─────────────────────────────

    public function testExplicitPartnerIdSetsPartner(): void
    {
        $personId = $this->anyActivePersonId();

        // Protiúčet bez vazby na osobu → párování přes účet by selhalo;
        // partner musí přijít z explicitního partnerId.
        $result = $this->applier->apply($this->payload([
            'transactions' => [[
                'externalId'          => 'ptx-explicit-1',
                'amount'              => 100.00,
                'dateTransaction'     => self::ACC_DATE,
                'counterpartyAccount' => '9359200456/2700',
                'counterpartyName'    => 'GRAFE Polymer Solutions GmbH',
                'partnerId'           => $personId,
            ]],
        ]));
        $this->assertTrue($result->success, 'apply selhal: ' . ($result->errorMessage ?? ''));

        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertSame($personId, (int) $rows[0]['partner'], 'partner z explicitního partnerId');
        $this->assertSame(0, $result->canonical['_result']['unmatchedPartner']);
    }

    public function testInvalidPartnerIdFallsBackWithoutFailing(): void
    {
        // Neexistující osoba → fallback na párování přes účet (tady bez vazby →
        // partner zůstane null), transakce NESMÍ spadnout.
        $result = $this->applier->apply($this->payload([
            'transactions' => [[
                'externalId'          => 'ptx-invalid-1',
                'amount'              => 100.00,
                'dateTransaction'     => self::ACC_DATE,
                'counterpartyAccount' => '9359200456/2700',
                'partnerId'           => 2147483600, // neexistující id
            ]],
        ]));
        $this->assertTrue($result->success, 'neplatné partnerId nesmí shodit apply');

        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['partner'], 'neplatné partnerId → fallback, partner null');
        $this->assertSame(1, $result->canonical['_result']['unmatchedPartner']);
    }

    public function testReRunBackfillsPartnerIntoExistingNullPartner(): void
    {
        $personId = $this->anyActivePersonId();

        $base = [
            'externalId'          => 'ptx-backfill-1',
            'amount'              => 100.00,
            'dateTransaction'     => self::ACC_DATE,
            'counterpartyAccount' => '9359200456/2700',
        ];

        // 1) Import bez partnerId → partner null.
        $first = $this->applier->apply($this->payload(['transactions' => [$base]]));
        $this->assertTrue($first->success);
        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['partner'], 'první běh bez partnera');

        // 2) Re-run téže transakce s partnerId → dedup skip + backfill partnera.
        $second = $this->applier->apply($this->payload([
            'transactions' => [array_merge($base, ['partnerId' => $personId])],
        ]));
        $this->assertTrue($second->success);
        $this->assertSame(0, $second->canonical['_result']['created'], 're-run nezakládá');
        $this->assertSame(1, $second->canonical['_result']['skipped']);

        $rows = $this->txRows();
        $this->assertCount(1, $rows, 'žádné zdvojení');
        $this->assertSame($personId, (int) $rows[0]['partner'], 'partner doplněn backfillem');
    }

    // ── Fingerprint: obsahově identické transakce napříč výpisy ─────────────

    public function testContentIdenticalTransactionsAcrossStatementsCreateBoth(): void
    {
        // Měsíční paušál: stejný den, částka i text ve dvou výpisech → legacy
        // otisk (bez external_id) by kolidoval na unq_fingerprint a druhá
        // transakce by tiše skončila v txErrors.
        $fee = static fn(string $extId): array => [
            'externalId'      => $extId,
            'amount'          => -164.00,
            'dateTransaction' => self::ACC_DATE,
            'message'         => 'Cena za vedení účtu',
        ];
        $statement = static fn(string $number, string $end): array => [
            'statementNumber' => $number,
            'periodStart'     => '2026-06-01',
            'periodEnd'       => $end,
            'openingBalance'  => 0.0,
            'closingBalance'  => -164.0,
            'currency'        => 'CZK',
        ];

        $first = $this->applier->apply($this->payload([
            'statement'    => $statement('FEE/2026-06a', '2026-06-15'),
            'transactions' => [$fee('fee-old-1')],
            'applyOptions' => ['targetState' => 10],
        ]));
        $this->assertTrue($first->success, 'první apply selhal: ' . ($first->errorMessage ?? ''));
        $this->assertSame(1, $first->canonical['_result']['created']);

        $secondPayload = $this->payload([
            'statement'    => $statement('FEE/2026-06b', '2026-06-30'),
            'transactions' => [$fee('fee-old-2')],
            'applyOptions' => ['targetState' => 10],
        ]);
        $second = $this->applier->apply($secondPayload);
        $this->assertTrue($second->success, 'druhý apply selhal: ' . ($second->errorMessage ?? ''));
        $this->assertSame(1, $second->canonical['_result']['created'], 'identická transakce druhého výpisu musí vzniknout');
        $this->assertSame([], $second->canonical['_result']['txErrors'], 'žádná tichá kolize otisku');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        $this->assertNotSame((int) $rows[0]['statement'], (int) $rows[1]['statement'], 'každá transakce ve svém výpisu');

        // Idempotence s novým formátem otisku: re-apply → external_id match → skip.
        $third = $this->applier->apply($secondPayload);
        $this->assertTrue($third->success);
        $this->assertSame(0, $third->canonical['_result']['created'], 're-apply nezakládá');
        $this->assertSame(1, $third->canonical['_result']['skipped']);
        $this->assertCount(2, $this->txRows(), 'žádné zdvojení');
    }

    // ── Identita výpisu: external_id + number-aware fallback ────────────────

    /** @param array<string, mixed> $stmtOverrides */
    private function dayStatementPayload(array $stmtOverrides, array $transactions): array
    {
        return $this->payload([
            'statement'    => array_merge([
                'statementNumber' => null,
                'periodStart'     => self::ACC_DATE,
                'periodEnd'       => self::ACC_DATE,
                'openingBalance'  => 0.0,
                'closingBalance'  => 0.0,
                'currency'        => 'CZK',
            ], $stmtOverrides),
            'transactions' => $transactions,
            'applyOptions' => ['targetState' => 10],
        ]);
    }

    private function statementCount(): int
    {
        return (int) $this->db->getDibiConnection()->fetchSingle(
            'SELECT COUNT(*) FROM economy_bank_statements WHERE bank_account = %i',
            $this->bankAccountId,
        );
    }

    public function testSameDayStatementsWithDifferentNumbersStayApart(): void
    {
        // Dva výpisy téhož účtu z jednoho dne (DS B 425/523) — dřív se slily
        // do jednoho přes klíč (bank_account, period_start, period_end).
        $first = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '16', 'externalId' => 'old:1001', 'closingBalance' => 100.0],
            [['externalId' => 'day-tx-1', 'amount' => 100.00, 'dateTransaction' => self::ACC_DATE]],
        ));
        $second = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '21', 'externalId' => 'old:1002', 'closingBalance' => -50.0],
            [['externalId' => 'day-tx-2', 'amount' => -50.00, 'dateTransaction' => self::ACC_DATE]],
        ));

        $this->assertTrue($first->success, 'první apply selhal: ' . ($first->errorMessage ?? ''));
        $this->assertTrue($second->success, 'druhý apply selhal: ' . ($second->errorMessage ?? ''));
        $this->assertNotSame($first->savedId, $second->savedId, 'dva výpisy, žádné slití');
        $this->assertSame(2, $this->statementCount());
        $this->assertSame(1, $first->canonical['_result']['reconciliation'], 'první výpis reconciluje');
        $this->assertSame(1, $second->canonical['_result']['reconciliation'], 'druhý výpis reconciluje');

        $rows = $this->txRows();
        $this->assertCount(2, $rows);
        $byExt = array_column($rows, 'statement', 'external_id');
        $this->assertSame($first->savedId, (int) $byExt['day-tx-1'], 'transakce v prvním výpisu');
        $this->assertSame($second->savedId, (int) $byExt['day-tx-2'], 'transakce ve druhém výpisu');
    }

    public function testStatementExternalIdReapplyIsIdempotentWithSelfHealing(): void
    {
        $build = fn(int $targetState): array => $this->payload([
            'statement'    => [
                'statementNumber' => '31',
                'externalId'      => 'old:2001',
                'periodStart'     => self::ACC_DATE,
                'periodEnd'       => self::ACC_DATE,
                'openingBalance'  => 0.0,
                'closingBalance'  => 100.0,
                'currency'        => 'CZK',
            ],
            'transactions' => [['externalId' => 'heal-tx-1', 'amount' => 100.00, 'dateTransaction' => self::ACC_DATE]],
            'applyOptions' => ['targetState' => $targetState],
        ]);

        $first = $this->applier->apply($build(10));
        $this->assertTrue($first->success);

        // Re-apply „hotového" výpisu: match přes external_id, koncept se povýší.
        $second = $this->applier->apply($build(40));
        $this->assertTrue($second->success);
        $this->assertSame($first->savedId, $second->savedId, 'stejná identita → stejný výpis');
        $this->assertSame(0, $second->canonical['_result']['created'], 're-apply nezakládá');
        $this->assertSame(1, $second->canonical['_result']['skipped']);
        $this->assertSame(1, $this->statementCount(), 'žádný duplikát výpisu');

        $stmt = $this->db->getDibiConnection()->fetch(
            'SELECT docState, docStateMain FROM economy_bank_statements WHERE id = %i',
            $first->savedId,
        );
        $this->assertSame(40, (int) $stmt['docState'], 'self-healing povýšení konceptu');
        $this->assertSame(3, (int) $stmt['docStateMain']);
    }

    public function testStatementExternalIdBackfilledOnNumberPeriodMatch(): void
    {
        // Výpis založený bez identity (pre-fix data / soubor) ji při shodě
        // čísla + periody dostane backfillem.
        $first = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '77'],
            [['externalId' => 'bf-tx-1', 'amount' => 10.00, 'dateTransaction' => self::ACC_DATE]],
        ));
        $this->assertTrue($first->success);
        $stored = $this->db->getDibiConnection()->fetchSingle(
            'SELECT external_id FROM economy_bank_statements WHERE id = %i',
            $first->savedId,
        );
        $this->assertNull($stored, 'bez externalId v payloadu zůstává NULL');

        $second = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '77', 'externalId' => 'old:777'],
            [['externalId' => 'bf-tx-1', 'amount' => 10.00, 'dateTransaction' => self::ACC_DATE]],
        ));
        $this->assertTrue($second->success);
        $this->assertSame($first->savedId, $second->savedId, 'match přes číslo + periodu');
        $this->assertSame(1, $this->statementCount(), 'žádný nový výpis');
        $stored = $this->db->getDibiConnection()->fetchSingle(
            'SELECT external_id FROM economy_bank_statements WHERE id = %i',
            $first->savedId,
        );
        $this->assertSame('old:777', $stored, 'external_id doplněn backfillem');
    }

    public function testSameNumberSamePeriodDifferentExternalIdCreatesSecond(): void
    {
        // Guard kroku 2: výpis s JINÝM external_id nesmí unést match přes
        // shodné číslo + periodu — jiná identita = jiný výpis.
        $first = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '16', 'externalId' => 'old:3001'],
            [['externalId' => 'guard-tx-1', 'amount' => 10.00, 'dateTransaction' => self::ACC_DATE]],
        ));
        $second = $this->applier->apply($this->dayStatementPayload(
            ['statementNumber' => '16', 'externalId' => 'old:3002'],
            [['externalId' => 'guard-tx-2', 'amount' => 20.00, 'dateTransaction' => self::ACC_DATE]],
        ));

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertNotSame($first->savedId, $second->savedId, 'jiné external_id → jiný výpis');
        $this->assertSame(2, $this->statementCount());
    }

    // ── Linkable states: archiv (70) odkazovatelný, smazáno (90) ne ─────────

    public function testPreviewReportsArchivedAccountAsExisting(): void
    {
        // Preview musí být konzistentní s apply — archivní účet je odkazovatelný.
        $this->db->getDibiConnection()->update('economy_codebooks_bank_accounts', [
            'docState'     => 70,
            'docStateMain' => 4,
        ])->where('id = %i', $this->bankAccountId)->execute();

        $result = $this->applier->preview($this->payload());

        $this->assertTrue($result->success);
        $this->assertTrue($result->canonical['_preview']['bankAccountExists'], 'archivní účet existuje pro preview');
        $codes = array_map(static fn($i) => $i['code'], $result->canonical['_resolve']['issues'] ?? []);
        $this->assertNotContains('bank_account_not_found', $codes);
    }


    public function testApplyToArchivedAccountSucceeds(): void
    {
        // Výpis pro později archivovaný účet je legitimní historické datum
        // (poslední výpis banky chodí po uzavření účtu).
        $this->db->getDibiConnection()->update('economy_codebooks_bank_accounts', [
            'docState'     => 70,
            'docStateMain' => 4,
        ])->where('id = %i', $this->bankAccountId)->execute();

        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success, 'apply na archivní účet selhal: ' . ($result->errorMessage ?? ''));
        $this->assertGreaterThan(0, $result->savedId);
        $this->assertSame(2, $result->canonical['_result']['created']);
        $this->assertCount(2, $this->txRows(), 'výpis + transakce vzniknou i pro archivní účet');
    }

    public function testApplyToDeletedAccountFails(): void
    {
        $this->db->getDibiConnection()->update('economy_codebooks_bank_accounts', [
            'docState'     => 90,
            'docStateMain' => 5,
        ])->where('id = %i', $this->bankAccountId)->execute();

        $result = $this->applier->apply($this->payload());

        $this->assertFalse($result->success, 'smazaný účet nesmí projít');
        $this->assertSame('apply_failed', $result->errorCode);
        $this->assertStringContainsString('smazán', (string) $result->errorMessage);
        $this->assertCount(0, $this->txRows(), 'žádné transakce pro smazaný účet');
    }

    public function testArchivedPartnerIdKeepsLink(): void
    {
        // Transakce odkazující archivovanou osobu si drží přímou vazbu partner
        // (fallback přes protiúčet by tady selhal — účet bez vazby na osobu).
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_persons_persons', [
            'person_id'    => 'ITF4A' . substr(uniqid(), -5),
            'person_type'  => 2,
            'full_name'    => 'IT F4 archivní partner',
            'last_name'    => 'IT F4 archivní partner',
            'first_name'   => '',
            'docState'     => 70,
            'docStateMain' => 4,
        ])->execute();
        $personId = (int) $dibi->getInsertId();
        $this->createdPersons[] = $personId;

        $result = $this->applier->apply($this->payload([
            'transactions' => [[
                'externalId'          => 'ptx-archived-1',
                'amount'              => 100.00,
                'dateTransaction'     => self::ACC_DATE,
                'counterpartyAccount' => '9359200456/2700',
                'partnerId'           => $personId,
            ]],
        ]));
        $this->assertTrue($result->success, 'apply selhal: ' . ($result->errorMessage ?? ''));

        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertSame($personId, (int) $rows[0]['partner'], 'archivní partner si drží vazbu');
        $this->assertSame(0, $result->canonical['_result']['unmatchedPartner']);
    }

    // ── validate / preview bez zápisu ────────────────────────────────────────

    public function testValidateRejectsMissingBankAccount(): void
    {
        $payload = $this->payload();
        unset($payload['bankAccountId']);
        $result = $this->applier->validate($payload);

        $this->assertFalse($result->success);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertCount(0, $this->txRows(), 'validate nezapisuje');
    }

    public function testPreviewReportsCountsWithoutWriting(): void
    {
        $result = $this->applier->preview($this->payload());

        $this->assertTrue($result->success);
        $this->assertTrue($result->canonical['_preview']['bankAccountExists']);
        $this->assertSame(2, $result->canonical['_preview']['toCreate']);
        $this->assertSame(0, $result->canonical['_preview']['toSkip']);
        $this->assertCount(0, $this->txRows(), 'preview nezapisuje');
    }

    public function testApplyCarriesExchangeRateIntoAmountDomOnForeignAccount(): void
    {
        // Kurz z výpisu (migrace, kurz za jednotku) na účtu v EUR projde
        // řetězcem schéma → DTO → service → DB (amount_dom = amount × rate).
        // Na účtu v domácí měně je kurz vždy 1 (#121 D7).
        $eurAccount = $this->insertBankAccount('eur');
        $result = $this->applier->apply($this->payload([
            'bankAccountId' => $eurAccount,
            'statement'     => [
                'statementNumber' => 'FX-1',
                'periodStart'     => '2026-06-01',
                'periodEnd'       => '2026-06-30',
                'openingBalance'  => 0.0,
                'closingBalance'  => 100.0,
                'currency'        => 'EUR',
            ],
            'transactions'  => [[
                'externalId'      => 'fx-in-1',
                'amount'          => 100.00,
                'dateTransaction' => self::ACC_DATE,
                'exchangeRate'    => 25.30,
            ]],
            'applyOptions'  => ['targetState' => 10],
        ]));
        $this->assertTrue($result->success, (string) $result->errorMessage);

        $rows = $this->txRows($eurAccount);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(25.30, (float) $rows[0]['exchange_rate'], 0.0001);
        $this->assertEqualsWithDelta(100.00, (float) $rows[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(2530.00, (float) $rows[0]['amount_dom'], 0.001);
    }

    public function testApplyOnHomeAccountIgnoresExchangeRate(): void
    {
        $this->applier->apply($this->payload([
            'statement'    => [
                'statementNumber' => 'FX-2',
                'periodStart'     => '2026-06-01',
                'periodEnd'       => '2026-06-30',
                'openingBalance'  => 0.0,
                'closingBalance'  => 100.0,
                'currency'        => 'CZK',
            ],
            'transactions' => [[
                'externalId'      => 'fx-in-2',
                'amount'          => 100.00,
                'dateTransaction' => self::ACC_DATE,
                'exchangeRate'    => 25.30,
            ]],
            'applyOptions' => ['targetState' => 10],
        ]));

        $rows = $this->txRows();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]['exchange_rate'], 0.0001);
        $this->assertEqualsWithDelta(100.00, (float) $rows[0]['amount_dom'], 0.001);
    }

    public function testMissingExchangeRateListStopsTheWholeStatement(): void
    {
        // Účet v EUR, transakce bez kurzu k datu, pro které lístek jistě není
        // (rok 2001) → ImportException se seznamem dat, nic se nezapíše (#121 D7).
        $eurAccount = $this->insertBankAccount('eur');
        $result = $this->applier->apply($this->payload([
            'bankAccountId' => $eurAccount,
            'statement'     => [
                'statementNumber' => 'FX-3',
                'periodStart'     => '2001-01-01',
                'periodEnd'       => '2001-01-31',
                'openingBalance'  => 0.0,
                'closingBalance'  => 300.0,
                'currency'        => 'EUR',
            ],
            'transactions'  => [
                ['externalId' => 'fx-nr-1', 'amount' => 100.00, 'dateTransaction' => '2001-01-05'],
                ['externalId' => 'fx-nr-2', 'amount' => 200.00, 'dateTransaction' => '2001-01-03'],
            ],
            'applyOptions'  => ['targetState' => 10],
        ]));

        $this->assertFalse($result->success);
        $this->assertSame('apply_failed', $result->errorCode);
        $this->assertStringContainsString('Kurz EUR chybí pro data: 2001-01-03, 2001-01-05', (string) $result->errorMessage);
        $this->assertCount(0, $this->txRows($eurAccount));
        $this->assertSame(
            0,
            (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_bank_statements WHERE bank_account = %i', $eurAccount),
            'ani výpis',
        );
    }

    private function insertBankAccount(string $currency): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_bank_accounts', [
            'code'               => 'ITY' . substr(uniqid(), -7),
            'name'               => 'IT F4 účet ' . strtoupper($currency),
            'currency'           => $currency,
            'accounting_account' => $this->ensureAccountByMask('221'),
            'docState'           => 40,
            'docStateMain'       => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->extraBankAccounts[] = $id;
        return $id;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function txRows(?int $bankAccountId = null): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_bank_transactions WHERE bank_account = %i ORDER BY date_transaction, id',
            $bankAccountId ?? $this->bankAccountId,
        );
        return array_map(static fn($r) => $r->toArray(), $rows);
    }

    private function anyActivePersonId(): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE docState IN (10,40,80) ORDER BY id LIMIT 1',
        );
        if ($row === null) {
            $this->markTestSkipped('Dev DS nemá žádnou aktivní osobu');
        }
        return (int) $row['id'];
    }

    private function ensureAccountByMask(string $mask): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts
             WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80)
             ORDER BY number LIMIT 1',
            $mask,
        );
        if ($row === null) {
            $this->markTestSkipped("DS nemá analytický účet pro masku {$mask}");
        }
        return (int) $row['id'];
    }

    private function ensureAccountByNumber(string $number): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND docState IN (10,40,80) LIMIT 1',
            $number,
        );
        if ($row !== null) {
            return (int) $row['id'];
        }

        $dibi = $this->db->getDibiConnection();
        $structure = AccountDocument::deriveStructure($number);
        $dibi->insert('economy_accounting_accounts', array_merge($structure, [
            'number'       => $number,
            'name'         => 'IT clearing ' . $number,
            'short_name'   => 'IT ' . $number,
            'account_kind' => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ]))->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdAccounts[] = $id;
        return $id;
    }
}
