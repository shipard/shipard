<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Vstup návrhu nad skutečnou databází (#166 D53, U1, U5): osoba, kterou
 * návrh zakládá, vzniká **uvnitř** transakce uložení — pokyn ji dostane
 * referencí a jeho chyba ji vrátí spolu s dokladem.
 *
 * Celý test běží ve vnější transakci, která se vrací zpět: vstup návrhu
 * i pokyn si uvnitř berou savepointy (`NestedTransaction`), po testu ve
 * zdroji dat nic nezůstane.
 */
final class DocumentProposalTransactionTest extends IntegrationTestCase
{
    private DocumentProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->dsConfig->isFree()) {
            $this->markTestSkipped('Test zakládá doklad a osobu — jen nad zdrojem dat s třídou `free`.');
        }
        if ($this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = %i LIMIT 1', 'invni', 40) === null) {
            $this->markTestSkipped('Zdroj dat nemá řadu přijatých faktur.');
        }
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $dibi = $this->db->getDibiConnection();
        $this->proposal = DocumentProposal::create(
            $dibi,
            $config,
            $this->dsConfig,
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables,
            DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $config),
        );
        $dibi->begin();
    }

    protected function onTearDown(): void
    {
        if (isset($this->proposal)) {
            $this->db->getDibiConnection()->rollback();
        }
    }

    /**
     * Papírová podoba přijaté faktury od dodavatele, kterého adresář nezná.
     *
     * @return array<string, mixed>
     */
    private function paper(string $companyId): array
    {
        return [
            'format'        => 'shpd.docs.document',
            'formatVersion' => '1.0',
            'docType'       => 'invoiceReceived',
            'selfParty'     => 'customer',
            'docNumber'     => 'IT-PROP-' . $companyId,
            'currency'      => 'CZK',
            'dates'         => ['issueDate' => '2026-03-10', 'taxPointDate' => '2026-03-10', 'dueDate' => '2026-03-24'],
            'supplier'      => ['name' => 'IT Proposal ' . $companyId . ' s.r.o.', 'companyId' => $companyId, 'country' => 'CZ'],
            'rows'          => [[
                'description' => 'Služba podle smlouvy',
                'quantity'    => 1,
                'unitPrice'   => 1000.0,
                'totalPrice'  => 1000.0,
                'vat'         => ['pct' => 21],
            ]],
            'applyOptions'  => ['autoCreateMode' => 'liberal', 'targetDocState' => 10],
        ];
    }

    private function personId(string $companyId): ?int
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE company_id = %s', $companyId);
        return $row !== null ? (int) $row['id'] : null;
    }

    private static function companyId(): string
    {
        return '88' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function testFailedInstructionRollsBackTheCreatedSupplier(): void
    {
        $companyId = self::companyId();
        $paper = $this->paper($companyId);
        // Řada, která neexistuje: interpretace referenci nechá být a pokyn
        // ji odmítne — až poté, co návrh založil dodavatele.
        $paper['numberSeries'] = '@it-proposal-missing-series';

        $result = $this->proposal->apply($paper);

        $this->assertFalse($result->success);
        $this->assertSame(['validation_failed', 422], [$result->errorCode, $result->statusCode]);
        $issues = array_column($result->canonical['_resolve']['issues'], 'path', 'code');
        $this->assertSame('numberSeries', $issues['reference_not_found'] ?? null);
        $this->assertNull($this->personId($companyId), 'založený dodavatel se vrátil s transakcí');
        $this->assertSame('canCreate', $result->canonical['_resolve']['supplier']['status']);
        $this->assertNull($this->db->fetchRow('SELECT id FROM docs_core_heads WHERE partner_doc_number = %s', $paper['docNumber']));
    }

    public function testCreatedSupplierIsReferencedByTheSavedDocument(): void
    {
        $companyId = self::companyId();
        $paper = $this->paper($companyId);

        $preview = $this->proposal->preview($paper);
        $this->assertTrue($preview->success);
        $this->assertSame('canCreate', $preview->canonical['_resolve']['supplier']['status']);
        $this->assertNull($this->personId($companyId), 'náhled nic nezakládá');
        $this->assertSame(1210.0, $preview->canonical['_resolve']['computed']['totals']['totalAmount']);

        $result = $this->proposal->apply($paper);

        $this->assertTrue($result->success, (string) $result->errorMessage . ' ' . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_UNESCAPED_UNICODE));
        $personId = $this->personId($companyId);
        $this->assertNotNull($personId);
        $doc = $this->db->fetchRow('SELECT partner, total_amount, docState FROM docs_core_heads WHERE id = %i', (int) $result->savedId);
        $this->assertSame($personId, (int) $doc['partner']);
        $this->assertSame(10, (int) $doc['docState']);
        $this->assertEqualsWithDelta(1210.0, (float) $doc['total_amount'], 0.001);
        // Odpověď: papírová podoba (bez reference), založený záznam ve zprávě.
        $this->assertArrayNotHasKey('ref', $result->canonical['supplier']);
        $this->assertSame($personId, $result->canonical['_resolve']['supplier']['matchedId']);
        $this->assertSame('created', $result->canonical['_resolve']['supplier']['matchedBy']);
        $this->assertSame($result->savedId, $result->canonical['savedDocId']);
    }
}
