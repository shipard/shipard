<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí tasku „Import dokladů: výběr číselné řady + cílové
 * stavy 40/30" proti živému DS (`SHIPARD_INTEGRATION_DS_PATH`).
 *
 * Ověřuje:
 *   1. výběr řady referencí `numberSeries` (#166 D30), ne první aktivní,
 *   2. import na stav 40 + importNumber → číslo/sekvence verbatim, counter
 *      = GREATEST, a že se přes zapojený DocumentEventDispatcher spustilo
 *      účtování (accounting_state ≠ 0; deník existuje právě když state 40),
 *   3. import na stav 30 (Storno) → číslo+counter ano, deník ne,
 *   4. neznámá řada → nález reference_not_found a 422 validation_failed,
 *      žádný doklad,
 *   5. importNumber se sequenceNumber = null (duplicitní klíče migrace) →
 *      doc_number verbatim, sequence_number = NULL (dva NULL v unq_series_seq
 *      nekolidují), čítač řady se nehne.
 *
 * Doklady jdou applierem jako pokyn: partnera si test zakládá sám
 * a odkazuje ho referencí id, položku bere existující (pokyn nic nezakládá).
 *
 * Test po sobě uklízí: snapshot/restore number_counters pro dotčené řady +
 * smazání vytvořených dokladů/řádků/rekapitulace/deníku a vlastního partnera;
 * existující položky ani osoby nemaže.
 * tearDown běží i při selhání assertions, takže DS zůstává čistý.
 */
class DocumentImportSeriesStatesTest extends IntegrationTestCase
{
    private const FIXTURE_PREFIX = 'IT-SER';

    private ConfigRuntime $configRuntime;
    private DocumentApplier $applier;

    private int $seriesCode1Id = 0; // Faktury přijaté (default = nejnižší id)
    private int $seriesCode5Id = 0; // Ostatní závazky

    /** @var list<int> */
    private array $createdDocIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];

    /** @var list<array<string, mixed>> */
    private array $counterSnapshot = [];

    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        // Dvě invni řady s kódy 1 a 5 — bez nich nemá co testovat.
        $this->seriesCode1Id = $this->resolveSeriesId('invni', '1');
        $this->seriesCode5Id = $this->resolveSeriesId('invni', '5');
        if ($this->seriesCode1Id === 0 || $this->seriesCode5Id === 0) {
            $this->markTestSkipped('DS nemá invni řady s kódy 1 a 5 — viz task scénář.');
        }

        // Crux tasku (Nález B): applier musí dostat reálný event dispatcher,
        // jinak se import na stav 40 nezaúčtuje.
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

        // Snapshot counterů dotčených řad — restore v tearDown vrátí DS do
        // původního stavu (import bumpne counter přes GREATEST).
        $this->counterSnapshot = array_map(
            fn($r) => $r instanceof \Dibi\Row ? $r->toArray() : (array) $r,
            $this->db->fetchAll(
                'SELECT * FROM docs_core_number_counters WHERE number_series IN (%i, %i)',
                $this->seriesCode1Id, $this->seriesCode5Id,
            ),
        );

        $this->ensureOwnCompany();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();

        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM economy_accounting_journal WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }

        // Restore counters: smazat vše pro dotčené řady a vrátit snapshot.
        if ($this->seriesCode1Id !== 0 || $this->seriesCode5Id !== 0) {
            $dibi->query(
                'DELETE FROM docs_core_number_counters WHERE number_series IN (%i, %i)',
                $this->seriesCode1Id, $this->seriesCode5Id,
            );
            foreach ($this->counterSnapshot as $row) {
                $dibi->insert('docs_core_number_counters', $row)->execute();
            }
        }
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testApplyAtState40ByCodeAccountsAndSyncsCounter(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-40-' . $seq;
        $canonical = $this->buildCanonical($this->seriesCode5Id, 40, $ourNumber, $seq);

        $result = $this->applier->apply($canonical);
        $this->assertTrue(
            $result->success,
            'apply selhal: ' . $result->errorCode . ' — ' . $result->errorMessage . ' / ' . json_encode($result->canonical['_resolve']['issues'] ?? []),
        );
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->trackPartner($head);

        // 1. Řada vybrána kódem 5 (Ostatní závazky), NE výchozí první aktivní.
        $this->assertSame($this->seriesCode5Id, (int) $head['number_series']);
        $this->assertNotSame(
            $this->seriesCode1Id, (int) $head['number_series'],
            'kód 5 nesmí spadnout na výchozí řadu (kód 1)',
        );

        // 2. Stav + číslo/sekvence verbatim z importNumber.
        $this->assertSame(40, (int) $head['docState']);
        $this->assertSame($ourNumber, (string) $head['doc_number']);
        $this->assertSame($seq, (int) $head['sequence_number']);

        // 3. Counter (number_series, fiscal_year) = GREATEST → náš seq.
        $counter = $this->db->fetchRow(
            'SELECT last_assigned FROM docs_core_number_counters
             WHERE number_series = %i AND fiscal_year <=> %iN',
            $this->seriesCode5Id, $head['fiscal_year'] ?? null,
        );
        $this->assertNotNull($counter);
        $this->assertSame($seq, (int) $counter['last_assigned']);

        // 4. Účtování proběhlo (dispatcher zapojen). accounting_state ≠ 0;
        //    invariant „deník právě tehdy, když state 40" — engine buď uspěl
        //    (state 1 + řádky deníku), nebo error-tolerantně selhal (state 2,
        //    bez deníku, s message). Obojí dokazuje, že se dispatch spustil.
        $accState = (int) $head['accounting_state'];
        $this->assertContains($accState, [1, 2], 'účtování se na stavu 40 nespustilo (chybí dispatcher?)');
        $journalCount = (int) $this->db->getDibiConnection()
            ->query('SELECT COUNT(*) FROM economy_accounting_journal WHERE doc_head = %i', $docId)
            ->fetchSingle();
        if ($accState === 1) {
            $this->assertGreaterThan(0, $journalCount, 'state=40/Zaúčtováno musí mít řádky deníku');
        } else {
            $this->assertSame(0, $journalCount, 'chyba účtování → žádný deník');
            $this->assertNotNull($head['accounting_messages']);
        }
    }

    public function testApplyAtState30StornoKeepsNumberWithoutJournal(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-30-' . $seq;
        $canonical = $this->buildCanonical($this->seriesCode5Id, 30, $ourNumber, $seq);

        $result = $this->applier->apply($canonical);
        $this->assertTrue(
            $result->success,
            'apply selhal: ' . $result->errorCode . ' — ' . $result->errorMessage . ' / ' . json_encode($result->canonical['_resolve']['issues'] ?? []),
        );
        $docId = (int) $result->savedId;
        $this->createdDocIds[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->trackPartner($head);

        // Stav 30 (Storno): číslo + counter ano …
        $this->assertSame(30, (int) $head['docState']);
        $this->assertSame($ourNumber, (string) $head['doc_number']);
        $this->assertSame($seq, (int) $head['sequence_number']);
        $counter = $this->db->fetchRow(
            'SELECT last_assigned FROM docs_core_number_counters
             WHERE number_series = %i AND fiscal_year <=> %iN',
            $this->seriesCode5Id, $head['fiscal_year'] ?? null,
        );
        $this->assertSame($seq, (int) $counter['last_assigned']);

        // … deník NE (engine se pro 30 nespouští) a accounting_state zůstává 0.
        $journalCount = (int) $this->db->getDibiConnection()
            ->query('SELECT COUNT(*) FROM economy_accounting_journal WHERE doc_head = %i', $docId)
            ->fetchSingle();
        $this->assertSame(0, $journalCount, 'storno (30) nesmí mít deník');
        $this->assertSame(0, (int) $head['accounting_state']);
    }

    public function testNullSequenceImportsDuplicateKeysWithoutCounterBump(): void
    {
        // D14-B: pravé duplicity klíče (řada, rok, sekvence) ve zdrojových
        // datech — první doklad drží sekvenci, další jdou se sufixovaným
        // číslem a sequence_number = NULL, mimo formuli řady i čítač.
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-NULL-' . $seq;

        // 1. Držitel sekvence: normální import, čítač → N.
        $result1 = $this->applier->apply($this->buildCanonical($this->seriesCode5Id, 40, $ourNumber, $seq));
        $this->assertTrue(
            $result1->success,
            'apply 1 selhal: ' . $result1->errorCode . ' — ' . $result1->errorMessage . ' / ' . json_encode($result1->canonical['_resolve']['issues'] ?? []),
        );
        $this->createdDocIds[] = $doc1Id = (int) $result1->savedId;
        $head1 = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $doc1Id);
        $this->trackPartner($head1);
        $this->assertSame($seq, (int) $head1['sequence_number']);

        // 2. Duplicita: sufixované číslo, sequence null → uloží se bez
        //    unq_series_seq kolize, čítač zůstává na N.
        $result2 = $this->applier->apply($this->buildCanonical($this->seriesCode5Id, 40, $ourNumber . '-2', null));
        $this->assertTrue(
            $result2->success,
            'apply 2 (null sekvence) selhal: ' . $result2->errorCode . ' — ' . $result2->errorMessage . ' / ' . json_encode($result2->canonical['_resolve']['issues'] ?? []),
        );
        $this->createdDocIds[] = $doc2Id = (int) $result2->savedId;
        $head2 = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $doc2Id);
        $this->trackPartner($head2);

        $this->assertSame($ourNumber . '-2', (string) $head2['doc_number']);
        $this->assertNull($head2['sequence_number']);
        // Stejný klíč (řada, rok) jako držitel sekvence — jinak by NULL
        // v unikátu nic nedokazoval.
        $this->assertSame((int) $head1['number_series'], (int) $head2['number_series']);
        $this->assertSame((int) $head1['fiscal_year'], (int) $head2['fiscal_year']);

        // 3. Druhý NULL v témže klíči (UNIQUE bere NULL jako distinct).
        $result3 = $this->applier->apply($this->buildCanonical($this->seriesCode5Id, 40, $ourNumber . '-3', null));
        $this->assertTrue(
            $result3->success,
            'apply 3 (druhý NULL) selhal: ' . $result3->errorCode . ' — ' . $result3->errorMessage . ' / ' . json_encode($result3->canonical['_resolve']['issues'] ?? []),
        );
        $this->createdDocIds[] = $doc3Id = (int) $result3->savedId;
        $head3 = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $doc3Id);
        $this->trackPartner($head3);
        $this->assertNull($head3['sequence_number']);

        // Čítač se od dokladu 1 nehnul — číslo mimo formuli ho nesmí syncnout.
        $counter = $this->db->fetchRow(
            'SELECT last_assigned FROM docs_core_number_counters
             WHERE number_series = %i AND fiscal_year <=> %iN',
            $this->seriesCode5Id, $head1['fiscal_year'] ?? null,
        );
        $this->assertNotNull($counter);
        $this->assertSame($seq, (int) $counter['last_assigned']);
    }

    public function testApplyWithUnknownSeriesCodeFailsCleanly(): void
    {
        $seq = random_int(900_000_000, 999_999_999);
        $ourNumber = self::FIXTURE_PREFIX . '-ERR-' . $seq;
        $canonical = $this->buildCanonical('@neznama-rada-9999', 40, $ourNumber, $seq);

        $result = $this->applier->apply($canonical);

        $this->assertFalse($result->success);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $findings = array_column($result->canonical['_resolve']['issues'] ?? [], 'code', 'path');
        $this->assertSame('reference_not_found', $findings['numberSeries'] ?? null);
        $this->assertNull($result->savedId);

        // Žádný doklad nevznikl.
        $orphan = $this->db->fetchRow('SELECT id FROM docs_core_heads WHERE doc_number = %s', $ourNumber);
        $this->assertNull($orphan);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function resolveSeriesId(string $docType, string $code): int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series
             WHERE doc_type = %s AND doc_number_code = %s AND docState IN (%i, %i, %i)
             ORDER BY id LIMIT 1',
            $docType, $code, 10, 40, 80,
        );
        return $row !== null ? (int) $row['id'] : 0;
    }

    /**
     * Partner dokladu je fixtura testu ({@see fixturePartner()}) — eviduje
     * se při založení; doklad na něj jen odkazuje.
     *
     * @param array<string, mixed>|\Dibi\Row $head
     */
    private function trackPartner($head): void
    {
        $this->assertContains((int) ($head['partner'] ?? 0), $this->createdPersonIds, 'doklad nese partnera z reference');
    }

    /** Vlastní partner testu (IČO s prefixem 88) — pokyn osoby nezakládá. */
    private function fixturePartner(string $uniq): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('base_persons_persons', [
            'person_id'    => 'S' . $uniq,
            'person_type'  => 2,
            'full_name'    => self::FIXTURE_PREFIX . ' Vendor ' . $uniq,
            'last_name'    => self::FIXTURE_PREFIX . ' Vendor ' . $uniq,
            'first_name'   => '',
            'company_id'   => '88' . $uniq,
            'vat_id'       => 'CZ88' . $uniq,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdPersonIds[] = $id;
        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanonical(int|string $numberSeries, int $targetState, string $ourNumber, ?int $sequence): array
    {
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );

        // Bez mailové návaznosti — žádné zápisy do extracted_documents.
        $payload['source'] = ['kind' => 'import'];

        // Pohyb řádku (rowOperations) — povinný pro item-řádky na stavu 40.
        // „Konzultace" = nákup služeb (platný pohyb pro invni). Položka
        // referencí id na existující položku s účtem; bez ní řádek bez položky.
        $item = $this->db->fetchRow(
            'SELECT id FROM economy_items WHERE docState = 40 AND accounting_account IS NOT NULL ORDER BY id LIMIT 1',
        );
        foreach ($payload['rows'] as &$r) {
            $r['operation'] = 'purchase.services';
            $r['description'] = (string) ($r['item']['name'] ?? 'Konzultace');
            $r['unit'] = 'hr';
            if ($item !== null) {
                $r['item'] = (int) $item['id'];
            } else {
                unset($r['item']);
            }
        }
        unset($r);

        // Dodavatel = vlastní partner testu, referencí id s dobovými daty.
        $uniq = substr((string) (microtime(true) * 10000), -6);
        $payload['supplier'] = ['ref' => $this->fixturePartner($uniq)] + $payload['supplier'];
        $payload['supplier']['name'] = self::FIXTURE_PREFIX . ' Vendor ' . $uniq;
        $payload['supplier']['companyId'] = '88' . $uniq;
        $payload['supplier']['vatId'] = 'CZ88' . $uniq;
        $payload['supplier']['taxId'] = 'CZ88' . $uniq;
        $payload['docNumber'] = self::FIXTURE_PREFIX . '-PARTNER-' . $uniq; // partner_doc_number
        // Signál návrhu (#166 D39) — pokyn bere výchozí registraci zdroje dat.
        unset($payload['vat']['registrationCountry']);

        // Součty fixtury jsou z papíru dodavatele (10 × 1 033,06 ≠ 10 330,58) —
        // v pokynu by byly kontrolní součet (#166 D27); test je neposílá.
        unset($payload['totals'], $payload['vatRecap']);

        $payload['numberSeries'] = $numberSeries;
        $payload['applyOptions'] = [
            'targetDocState' => $targetState,
            'importNumber'   => ['docNumber' => $ourNumber, 'sequenceNumber' => $sequence],
        ];

        return $payload;
    }

    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id'    => 'F-OWN-SER',
            'person_type'  => 2,
            'full_name'    => self::FIXTURE_PREFIX . ' Own',
            'last_name'    => self::FIXTURE_PREFIX . ' Own',
            'first_name'   => '',
            'company_id'   => '00000099',
            'is_own'       => 1,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }
}
