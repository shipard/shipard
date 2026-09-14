<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Import pokladních dokladů a prodejek přes exchange applier (#59 D12):
 * řada se dohledá podle (typ, pokladna dle kódu), cash_desk se denormalizuje
 * z řady, cash_dir z cashDirection, číslo z importNumber se zachová,
 * doklad ve stavu 40 se zaúčtuje. Úhrada payment.* nese partnera přes pin
 * `_resolve.rows[i].partner` a paymentReference. Neznámá pokladna = čistá
 * 422 (cash_desk_not_found), žádný doklad. Faktura placená hotově s kódem
 * pokladny dostane cash_desk.
 */
class CashDocumentImportTest extends IntegrationTestCase
{
    private const FIXTURE_PREFIX = 'IT-CASHIMP';
    private const ISSUE_DATE = '2026-06-10';

    private ConfigRuntime $configRuntime;
    private DocumentApplier $applier;

    /** @var list<int> */
    private array $createdDocIds = [];
    /** @var list<int> */
    private array $createdCashDesks = [];
    /** @var list<int> */
    private array $createdSeries = [];
    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;
    private int $partnerId = 0;
    private string $deskCode = '';
    private int $deskId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $person = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE docState IN (%i, %i, %i) ORDER BY id LIMIT 1',
            10, 40, 80,
        );
        if ($person === null) {
            $this->markTestSkipped('DS nemá žádnou osobu pro partnera úhrady.');
        }
        $this->partnerId = (int) $person['id'];

        $account211 = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts WHERE number LIKE %like~ AND account_level = 4 AND docState IN (10,40,80) ORDER BY number LIMIT 1',
            '211',
        );
        if ($account211 === null) {
            $this->markTestSkipped('DS nemá analytiku 211.');
        }

        $dispatcher = DocumentEventHandlerLoader::load(
            $this->dsConfig,
            $modulePathResolver,
            $this->db->getDibiConnection(),
            $this->configRuntime,
        );
        $this->applier = DocumentApplier::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
            $dispatcher,
        );

        $this->ensureOwnCompany();
        $this->createCashDesk((int) $account211['id']);
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM economy_accbal_ledger WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM economy_accounting_journal WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        foreach ($this->createdSeries as $id) {
            $dibi->query('DELETE FROM docs_core_number_counters WHERE number_series = %i', $id);
            $dibi->query('DELETE FROM docs_core_number_series WHERE id = %i', $id);
        }
        foreach ($this->createdCashDesks as $id) {
            $dibi->query('DELETE FROM economy_codebooks_cash_desks WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testCashReceiptImportKeepsNumberAndBindsToCashDesk(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;

        $result = $this->applier->apply($this->canonical('cashDocument', [
            'cashDirection' => 1,
            'payment'       => ['method' => 'cash'],
            'rows'          => [$this->serviceRow('sale.services', 1000.0)],
        ], $ourNumber, $seq));

        $this->assertApplied($result);
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('cash', (string) $head['doc_type']);
        $this->assertSame($this->deskId, (int) $head['cash_desk'], 'pokladna denormalizovaná z řady');
        $this->assertSame(1, (int) $head['cash_dir']);
        $this->assertSame(0, (int) $head['payment_method']);
        $this->assertSame($ourNumber, (string) $head['doc_number'], 'číslo z importu zachováno');
        $this->assertSame(40, (int) $head['docState']);
        $this->assertNull($head['partner'], 'anonymní příjem');
        $this->assertSame(self::ISSUE_DATE, $head['due_date'] instanceof \DateTimeInterface ? $head['due_date']->format('Y-m-d') : (string) $head['due_date']);

        $series = $this->db->fetchRow('SELECT doc_type, cash_desk FROM docs_core_number_series WHERE id = %i', (int) $head['number_series']);
        $this->assertSame('cash', (string) $series['doc_type']);
        $this->assertSame($this->deskId, (int) $series['cash_desk']);

        // zaúčtováno: 602 DAL / 343 DAL / 211 MD
        $journal = $this->db->fetchAll('SELECT account_number, money_dr, money_cr FROM economy_accounting_journal WHERE doc_head = %i', $docId);
        $this->assertNotEmpty($journal, 'PD ve stavu 40 se zaúčtoval');
        $this->assertEqualsWithDelta(1210.0, (float) $this->lineByPrefix($journal, '211')['money_dr'], 0.001);
    }

    public function testCashDisbursementWithPaymentOfPayableUsesRowPartnerPin(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;

        $canonical = $this->canonical('cashDocument', [
            'cashDirection' => 2,
            'payment'       => ['method' => 'cash'],
            'rows'          => [[
                'rowKind'          => 'item',
                'operation'        => 'payment.payable',
                'totalPrice'       => 605.0,
                'description'      => 'Úhrada FP hotově',
                'paymentReference' => '2026000011',
            ]],
            '_resolve' => ['rows' => [0 => ['partner' => ['userAction' => 'useExisting:' . $this->partnerId]]]],
        ], $ourNumber, $seq);

        $result = $this->applier->apply($canonical);
        $this->assertApplied($result);
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame(2, (int) $head['cash_dir']);
        $this->assertEqualsWithDelta(605.0, (float) $head['total_amount'], 0.001);

        $rows = $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i', $docId);
        $this->assertCount(1, $rows);
        $this->assertSame('payment.payable', (string) $rows[0]['operation']);
        $this->assertSame($this->partnerId, (int) $rows[0]['partner'], 'partner řádku z pinu');
        $this->assertSame('2026000011', (string) $rows[0]['payment_reference']);
        $this->assertEqualsWithDelta(605.0, (float) $rows[0]['total_price'], 0.001, 'kontační řádek nesmí přijít o částku');
        $this->assertNull($rows[0]['vat_code']);

        $journal = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i', $docId);
        $payable = $this->lineByPrefix($journal, '321');
        $this->assertEqualsWithDelta(605.0, (float) $payable['money_dr'], 0.001);
        $this->assertSame($this->partnerId, (int) $payable['partner']);
        $this->assertEqualsWithDelta(605.0, (float) $this->lineByPrefix($journal, '211')['money_cr'], 0.001);
    }

    public function testCashRegisterDocumentImport(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;

        $result = $this->applier->apply($this->canonical('cashRegisterDocument', [
            'payment' => ['method' => 'card'],
            'rows'    => [$this->serviceRow('sale.goods', 200.0)],
        ], $ourNumber, $seq));

        $this->assertApplied($result);
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('cashreg', (string) $head['doc_type']);
        $this->assertSame($this->deskId, (int) $head['cash_desk']);
        $this->assertSame(0, (int) $head['cash_dir']);
        $this->assertSame(2, (int) $head['payment_method']);
        $this->assertSame($ourNumber, (string) $head['doc_number']);

        $journal = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i', $docId);
        $this->assertEqualsWithDelta(242.0, (float) $this->lineByPrefix($journal, '261400')['money_dr'], 0.001, 'karta → platební karty na cestě');
    }

    /**
     * Pokladna založená mimo TableGateway (generický CRUD importu) nemá řady
     * — afterSave handler se nespustil. Applier je musí založit sám, jinak
     * padá celý import pokladny (#59, import msi 2026-09-06).
     */
    public function testCashDeskWithoutSeriesGetsProvisionedOnApply(): void
    {
        $dibi = $this->db->getDibiConnection();
        $code = 'NS' . strtoupper(substr(uniqid(), -4));
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => $code, 'name' => 'IT pokladna bez řad', 'currency' => 'czk',
            'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;
        $this->assertNull(
            $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId),
            'pre-condition: pokladna bez řad',
        );

        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;
        $canonical = $this->canonical('cashDocument', [
            'cashDesk'      => $code,
            'cashDirection' => 1,
            'payment'       => ['method' => 'cash'],
            'rows'          => [$this->serviceRow('sale.services', 100.0)],
        ], $ourNumber, $seq);

        $result = $this->applier->apply($canonical);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
        if ($result->savedId !== null) {
            $this->createdDocIds[] = $result->savedId;
        }

        $this->assertTrue($result->success, 'apply: ' . ($result->errorCode ?? '') . ' ' . ($result->errorMessage ?? ''));
        $this->assertNotEmpty($this->createdSeries, 'řady pokladny založeny při apply');
        $head = $this->db->fetchRow('SELECT doc_type, cash_desk FROM docs_core_heads WHERE id = %i', $result->savedId);
        $this->assertSame('cash', $head['doc_type']);
        $this->assertSame($deskId, (int) $head['cash_desk']);
    }

    /**
     * Archivovaná pokladna (#59 Task E, E2): historické doklady na ni import
     * přijme — řady vzniknou ve stavu 70 (provisioner z apply), hlavička nese
     * pokladnu. Živý apply (bez importNumber) na archivovanou pokladnu končí
     * čistou 422 cash_desk_not_found.
     */
    public function testArchivedCashDeskAcceptsImportOnlyAndGetsArchivedSeries(): void
    {
        $dibi = $this->db->getDibiConnection();
        $code = 'AR' . strtoupper(substr(uniqid(), -4));
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => $code, 'name' => 'IT archivovaná pokladna', 'currency' => 'czk',
            'docState' => 70, 'docStateMain' => 4,
        ])->execute();
        $deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $deskId;

        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;
        $canonical = $this->canonical('cashDocument', [
            'cashDesk'      => $code,
            'cashDirection' => 1,
            'payment'       => ['method' => 'cash'],
            'rows'          => [$this->serviceRow('sale.services', 100.0)],
        ], $ourNumber, $seq);

        // živý apply: bez importNumber archivovaná pokladna neprojde
        $live = $canonical;
        unset($live['applyOptions']['importNumber']);
        $liveResult = $this->applier->apply($live);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
            if (!in_array((int) $s['id'], $this->createdSeries, true)) {
                $this->createdSeries[] = (int) $s['id'];
            }
        }
        if ($liveResult->savedId !== null) {
            $this->createdDocIds[] = $liveResult->savedId;
        }
        $this->assertFalse($liveResult->success, 'živý doklad na archivovanou pokladnu nejde');
        $this->assertSame('cash_desk_not_found', $liveResult->errorCode);

        // import: projde, řady jsou v archivu
        $result = $this->applier->apply($canonical);
        if ($result->savedId !== null) {
            $this->createdDocIds[] = $result->savedId;
        }
        $this->assertApplied($result);

        $series = $this->db->fetchAll('SELECT id, docState, docStateMain FROM docs_core_number_series WHERE cash_desk = %i', $deskId);
        $this->assertNotEmpty($series, 'řady archivované pokladny založeny');
        foreach ($series as $s) {
            $this->assertSame(70, (int) $s['docState'], 'řada dědí archiv pokladny');
            $this->assertSame(4, (int) $s['docStateMain']);
        }
        $head = $this->db->fetchRow('SELECT doc_type, cash_desk, doc_number, docState FROM docs_core_heads WHERE id = %i', $result->savedId);
        $this->assertSame('cash', $head['doc_type']);
        $this->assertSame($deskId, (int) $head['cash_desk']);
        $this->assertSame($ourNumber, (string) $head['doc_number']);
        $this->assertSame(40, (int) $head['docState']);
    }

    public function testUnknownCashDeskFailsCleanly(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;
        $canonical = $this->canonical('cashDocument', [
            'cashDesk'      => 'NOPE' . substr(uniqid(), -4),
            'cashDirection' => 1,
            'payment'       => ['method' => 'cash'],
            'rows'          => [$this->serviceRow('sale.services', 100.0)],
        ], $ourNumber, $seq);

        $result = $this->applier->apply($canonical);

        $this->assertFalse($result->success);
        $this->assertSame('cash_desk_not_found', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $this->assertNull($result->savedId);
        $this->assertNull($this->db->fetchRow('SELECT id FROM docs_core_heads WHERE doc_number = %s', $ourNumber), 'žádný částečný zápis');
    }

    public function testMissingCashDeskAndDirectionAreValidationErrors(): void
    {
        $canonical = $this->canonical('cashDocument', [
            'payment' => ['method' => 'cash'],
            'rows'    => [$this->serviceRow('sale.services', 100.0)],
        ], self::FIXTURE_PREFIX . '-X', 1);
        unset($canonical['cashDesk']);

        $result = $this->applier->apply($canonical);

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $codesByPath = [];
        foreach ($result->canonical['_resolve']['issues'] ?? [] as $issue) {
            $codesByPath[$issue['path']] = $issue['code'];
        }
        $this->assertSame('required', $codesByPath['cashDesk'] ?? null);
        $this->assertSame('invalid_value', $codesByPath['cashDirection'] ?? null);
    }

    public function testIssuedInvoicePaidInCashGetsCashDesk(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-' . $seq;
        $invnoSeries = $this->db->fetchRow('SELECT doc_number_code FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1', 'invno');
        if ($invnoSeries === null) {
            $this->markTestSkipped('DS nemá řadu invno.');
        }
        $ownBank = $this->db->fetchRow('SELECT code FROM economy_codebooks_bank_accounts WHERE docState IN (10,40,80) ORDER BY id LIMIT 1');

        $canonical = $this->canonical('invoiceIssued', [
            'selfParty' => 'supplier',
            'customer'  => ['name' => self::FIXTURE_PREFIX . ' Odběratel', 'companyId' => '00000097'],
            'payment'   => ['method' => 'cash'],
            'rows'      => [$this->serviceRow('sale.services', 1000.0)],
            '_resolve'  => ['customer' => ['userAction' => 'useExisting:' . $this->partnerId]],
        ], $ourNumber, $seq);
        // faktura hotově: stav 10 stačí (bank_account by byl při 40 povinný)
        $canonical['applyOptions']['targetDocState'] = 10;
        if ($ownBank !== null) {
            $canonical['applyOptions']['importOwnBankAccount'] = (string) $ownBank['code'];
        }

        $result = $this->applier->apply($canonical);
        $this->assertApplied($result);
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT doc_type, cash_desk, payment_method FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('invno', (string) $head['doc_type']);
        $this->assertSame(0, (int) $head['payment_method']);
        $this->assertSame($this->deskId, (int) $head['cash_desk'], 'kód pokladny → cash_desk faktury');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function canonical(string $docType, array $overrides, string $ourNumber, int $seq): array
    {
        $base = [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => $docType,
            'cashDesk'      => $this->deskCode,
            'source'        => ['kind' => 'import'],
            'docText'       => 'IT import pokladna',
            'dates'         => ['issueDate' => self::ISSUE_DATE, 'accountingDate' => self::ISSUE_DATE, 'taxPointDate' => self::ISSUE_DATE],
            'currency'      => 'CZK',
            'vat'           => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'CZ'],
            'applyOptions'  => [
                'targetDocState' => 40,
                'importNumber'   => ['docNumber' => $ourNumber, 'sequenceNumber' => $seq],
            ],
        ];
        return array_replace($base, $overrides);
    }

    /** @return array<string, mixed> */
    private function serviceRow(string $operation, float $base): array
    {
        return [
            'rowKind'     => 'item',
            'operation'   => $operation,
            'description' => 'IT položka',
            'quantity'    => 1,
            'unitPrice'   => $base,
            'totalPrice'  => $base,
            'vat'         => ['code' => 'cz-120', 'pct' => 21],
        ];
    }

    private function assertApplied(mixed $result): void
    {
        $this->assertTrue(
            $result->success,
            'apply selhal: ' . $result->errorCode . ' — ' . $result->errorMessage
            . ' / ' . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE),
        );
    }

    /** @param array<int, mixed> $journal */
    private function lineByPrefix(array $journal, string $prefix): mixed
    {
        foreach ($journal as $line) {
            if (str_starts_with((string) $line['account_number'], $prefix)) {
                return $line;
            }
        }
        $this->fail("Deník nemá zápis na účtu s prefixem {$prefix}.");
    }

    private function createCashDesk(int $accountingAccount): void
    {
        $this->deskCode = 'IT' . strtoupper(substr(uniqid(), -4));
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_codebooks_cash_desks', [
            'code' => $this->deskCode, 'name' => 'IT import pokladna', 'currency' => 'czk',
            'accounting_account' => $accountingAccount, 'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        $this->deskId = (int) $dibi->getInsertId();
        $this->createdCashDesks[] = $this->deskId;
        (new BoundNumberSeriesProvisioner($this->db, $this->configRuntime))->provisionForCashDesk($this->deskId);
        foreach ($this->db->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $this->deskId) as $s) {
            $this->createdSeries[] = (int) $s['id'];
        }
    }

    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id'    => 'F-OWNCASH',
            'person_type'  => 2,
            'full_name'    => self::FIXTURE_PREFIX . ' Own',
            'last_name'    => self::FIXTURE_PREFIX . ' Own',
            'first_name'   => '',
            'company_id'   => '00000098',
            'is_own'       => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }
}
