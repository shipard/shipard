<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí Exchange Applieru proti živé DB. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id> vendor/bin/phpunit
 * --testsuite Integration`.
 *
 * Test prefix `IT-EX-` v partner_doc_number / supplier name umožňuje rychlé
 * cleanup; tearDown maže všechno vyrobené.
 */
class DocumentExchangeEndpointTest extends IntegrationTestCase
{
    private const TEST_PARTNER_NAME_PREFIX = 'IT-EX Vendor';
    private const TEST_DOC_NUMBER_PREFIX = 'IT-EX-';

    private ConfigRuntime $config;
    /** Papírová podoba dokladu jde vstupem návrhu (#166 D53). */
    private DocumentProposal $applier;

    /** @var list<int> */
    private array $createdDocIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];
    /** @var list<int> */
    private array $createdItemIds = [];

    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        // Prerequisite: number_series for invni must exist (provisioned with DS).
        $invniSeries = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (%i,%i,%i) LIMIT 1',
            'invni', 10, 40, 80,
        );
        if ($invniSeries === null) {
            $this->markTestSkipped('DS is missing invni number_series — run bin/shpd-ds ds-upgrade with provisioners.');
        }

        $this->applier = DocumentProposal::create(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );

        $this->ensureOwnCompany();
    }

    /**
     * Applier's selfParty resolve requires an own-company row. Provision one
     * if missing; remember whether we created it so tearDown can clean up.
     */
    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id'    => 'F-OWN-IT',
            'person_type'  => 2,
            'full_name'    => 'IT-EX Own Company',
            'last_name'    => 'IT-EX Own Company',
            'first_name'   => '',
            'company_id'   => '00000001',
            'tax_id'       => '',
            'vat_id'       => '',
            'is_own'       => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        foreach ($this->createdItemIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $id);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }
    }

    public function testValidateAcceptsHappyFixture(): void
    {
        $payload = $this->loadFixture('invoiceReceived_happy.json');
        $result = $this->applier->validate($payload);
        $this->assertTrue(
            $result->success,
            'Issues: ' . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_PRETTY_PRINT),
        );
    }

    public function testValidateRejectsMissingDataFixture(): void
    {
        $payload = $this->loadFixture('invoiceReceived_missingData.json');
        $result = $this->applier->validate($payload);

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $codes = array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
        $this->assertContains('required', $codes);
    }

    public function testPreviewMarksUnknownSupplierAsCanCreate(): void
    {
        $payload = $this->loadFixture('invoiceReceived_happy.json');
        // Make supplier identity unique so it doesn't accidentally match an
        // existing person in the DS.
        $payload['supplier']['name'] = self::TEST_PARTNER_NAME_PREFIX . ' Preview ' . uniqid();
        $payload['supplier']['companyId'] = '99' . substr((string) (microtime(true) * 10000), -6);

        $result = $this->applier->preview($payload);
        $this->assertTrue($result->success);

        $supplier = $result->canonical['_resolve']['supplier'] ?? [];
        $this->assertSame('canCreate', $supplier['status']);
        $this->assertSame($payload['supplier']['name'], $supplier['createPayload']['full_name']);
        $this->assertSame($payload['supplier']['companyId'], $supplier['createPayload']['company_id']);
    }

    public function testApplyFailsWithoutUserActionOnCanCreate(): void
    {
        $payload = $this->loadFixture('invoiceReceived_happy.json');
        $payload['supplier']['name'] = self::TEST_PARTNER_NAME_PREFIX . ' NoAction ' . uniqid();
        $payload['supplier']['companyId'] = '88' . substr((string) (microtime(true) * 10000), -6);

        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame('unresolved_required', $result->errorCode);
    }

    public function testApplyHappyDraftPersistsHeadRowsAndCreatesPartner(): void
    {
        $payload = $this->loadFixture('invoiceReceived_happy.json');
        $uniqueSuffix = (string) uniqid();
        $partnerName = self::TEST_PARTNER_NAME_PREFIX . ' Apply ' . $uniqueSuffix;
        $companyId = '77' . substr((string) (microtime(true) * 10000), -6);

        $payload['supplier']['name'] = $partnerName;
        $payload['supplier']['companyId'] = $companyId;
        $payload['supplier']['vatId'] = 'CZ' . $companyId;
        $payload['supplier']['taxId'] = 'CZ' . $companyId;
        $payload['docNumber'] = self::TEST_DOC_NUMBER_PREFIX . $uniqueSuffix;
        $payload['_resolve'] = [
            'supplier'     => ['userAction' => 'create'],
            'supplierBank' => ['userAction' => 'create'],
            'rows'         => [
                ['item' => ['userAction' => 'create']],
            ],
        ];
        // Stay in Draft so we don't need own-company / number series assignment.
        $payload['applyOptions'] = ['targetDocState' => 10];

        $result = $this->applier->apply($payload);
        $this->assertTrue(
            $result->success,
            "Apply failed: code={$result->errorCode}, msg={$result->errorMessage}\n"
                . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_PRETTY_PRINT),
        );

        $savedId = $result->savedId;
        $this->assertNotNull($savedId);
        $this->createdDocIds[] = $savedId;

        // 1. Head row exists with right fields.
        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $savedId);
        $this->assertNotNull($head);
        $this->assertSame('invni', (string) $head['doc_type']);
        $this->assertSame(10, (int) $head['docState']);
        $this->assertSame(self::TEST_DOC_NUMBER_PREFIX . $uniqueSuffix, (string) $head['partner_doc_number']);
        $this->assertSame('czk', (string) $head['doc_currency']);
        $this->assertSame('aiExtraction', (string) $head['source_kind']);

        // 2. Partner created and linked.
        $partnerId = (int) $head['partner'];
        $this->assertGreaterThan(0, $partnerId);
        $this->createdPersonIds[] = $partnerId;

        $partner = $this->db->fetchRow('SELECT * FROM base_persons_persons WHERE id = %i', $partnerId);
        $this->assertNotNull($partner);
        $this->assertSame($partnerName, (string) $partner['full_name']);
        $this->assertSame($companyId, (string) $partner['company_id']);

        // 3. Bank linked.
        $bankId = $head['partner_bank'];
        if ($bankId !== null) {
            $bank = $this->db->fetchRow('SELECT * FROM base_persons_bank_accounts WHERE id = %i', (int) $bankId);
            $this->assertNotNull($bank);
            $this->assertSame($partnerId, (int) $bank['person']);
        }

        // 4. Row inserted with computed VAT (DocDocument::beforeSave).
        $rows = $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos', $savedId);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $newItemId = (int) $row['item'];
        $this->assertGreaterThan(0, $newItemId);
        $this->createdItemIds[] = $newItemId;
        $this->assertSame(10.0, (float) $row['quantity']);
        $this->assertSame('cz-110', (string) $row['vat_code']);

        // 5. VAT recap built by beforeSave — only if vat_registration was
        //    resolved. Plain dev DS without a CZ VAT registration legitimately
        //    produces empty recap (DocDocument::buildVatRecapitulation needs
        //    country to look up VAT codes).
        if (!empty($head['vat_registration'])) {
            $recap = $this->db->fetchAll('SELECT * FROM docs_core_vat_recap WHERE doc_head = %i', $savedId);
            $this->assertNotEmpty($recap);
        }

        // 6. Per-partner supplier-code mapping written (fixture's supplierCode = "KONZ-001").
        $mapping = $this->db->fetchRow(
            'SELECT * FROM economy_items_supplier_codes WHERE person = %i AND supplier_code = %s',
            $partnerId, 'KONZ-001',
        );
        $this->assertNotNull($mapping);
        $this->assertSame($newItemId, (int) $mapping['item']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/Exchange/' . $name;
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture: {$path}");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in fixture: {$path}");
        }
        return $decoded;
    }
}
