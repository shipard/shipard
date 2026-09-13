<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\Import\FilingImportException;
use Shipard\Module\Economy\Vat\Import\FilingImportRequest;
use Shipard\Module\Economy\Vat\Import\FilingImportResult;
use Shipard\Module\Economy\Vat\Import\FilingImportService;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Import starých podání nad reálným dev DS (#55 D21, D32–D39). Test si
 * zakládá vlastní instance tvrzení v izolovaném rozsahu 01/2029 a vlastní
 * doklady jako `FilingComposerTest`; XML „podaného" podání si skládá sám
 * s hodnotami, které se od composeru záměrně liší.
 */
class FilingImportServiceTest extends IntegrationTestCase
{
    private const BASE_PROFILE = ['typ_ds' => 'P', 'c_ufo' => '464', 'c_okec' => '620200'];

    private const DATE_BEGIN = '2029-01-01';
    private const DATE_END   = '2029-01-31';
    private const DUZP       = '2029-01-15';

    private ?ConfigRuntime $config = null;
    private ?DocumentRegistry $registry = null;
    private int $registrationId = 0;

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdFilings = [];

    private mixed $originalProfile = null;
    private bool $profileRestored = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables[FilingDocument::TABLE])) {
            $this->markTestSkipped('DS nemá tabulku economy_vat_filings — spusťte ds-upgrade');
        }
        if ($this->db->fetchRow('SHOW COLUMNS FROM economy_vat_filings LIKE %s', 'origin') === null) {
            $this->markTestSkipped('DS nemá sloupec economy_vat_filings.origin — spusťte ds-upgrade');
        }
        foreach (['economy.vat.reports.cz', 'economy.vat.xml.cz', 'economy.vat.filingOrigins'] as $cfgItem) {
            if (!is_array($this->config->cfgItem($cfgItem))) {
                $this->markTestSkipped("DS nemá compiled cfgItem {$cfgItem} — spusťte ds-upgrade");
            }
        }

        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations'
            . ' WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];

        $collision = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_report_periods'
            . ' WHERE vat_registration = %i AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, self::DATE_END, self::DATE_BEGIN,
        );
        if ($collision > 0) {
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 01/2029');
        }

        $this->setRegistrationProfile(self::BASE_PROFILE);
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdFilings as $id) {
            $dibi->delete('core_attachments_files')
                ->where('table_id = %i AND record_id = %i', FilingFilesService::TABLE_ID, $id)->execute();
            foreach (FilingDocument::SNAPSHOT_TABLES as $table) {
                $dibi->delete($table)->where('filing = %i', $id)->execute();
            }
            $dibi->delete(FilingDocument::TABLE)->where('id = %i', $id)->execute();
        }
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPeriods as $id) {
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $id)->execute();
        }
        if ($this->profileRestored) {
            $dibi->update('economy_codebooks_vat_registrations', ['filing_profile' => $this->originalProfile])
                ->where('id = %i', $this->registrationId)->execute();
        }
    }

    // ── Přiznání s XML ──────────────────────────────────────────────────────

    public function testRegularReturnWithXmlOverridesFiledValues(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT import');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.30, 210.06, 'CZ12345678');
        $this->insertDoc('invni', $periodId, 'vat_period', 'cz-110', 500.50, 105.11, 'CZ87654321');

        // Composer: ř. 1 = 1000/210, ř. 40 = 501/105, ř. 46 = 105, ř. 62 = 210,
        // ř. 63 = 105, ř. 64 = 105. Podané XML má odpočet o korunu vyšší
        // (jiné zaokrouhlení starého systému) a řádek, který dnes neexistuje.
        $xml = $this->dp3Xml(
            'dapdph_forma="B" typ_platce="P" c_okec="620200" trans="N" d_poddp="20.02.2029"',
            'typ_ds="P" dic="12345678" zkrobchjm="Import s.r.o." c_ufo="464" stat="NĚMECKO" email="import@example.com"',
            '<Veta1 obrat23="1000" dan23="210" novy_radek="7"/>'
            . '<Veta4 pln23="501" odp_tuz23_nar="106" odp_sum_nar="106" odp_sum_kr="0"/>'
            . '<Veta6 dan_zocelk="210" odp_zocelk="106" dano_da="104"/>',
        );

        $result = $this->import([
            'reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'regular',
            'name' => 'Přiznání DPH 2029/1', 'dateIssue' => '2029-02-20', 'dateFiled' => '2029-02-25',
            'xml' => $xml, 'legacy' => ['filingNdx' => 812, 'reportNdx' => 140],
        ]);
        $filingId = $result->filingId;

        $this->assertSame(1, $result->sequence);
        $this->assertSame(4, $result->mismatchRows, 'ř. 40, 46, 63, 64');
        $this->assertSame(0, $result->lineMismatches);
        $this->assertContains(FilingImportService::MSG_LEGACY, $result->flags);
        $this->assertContains(FilingImportService::MSG_ROW_UNMAPPED, $result->flags);
        $this->assertNotContains(FilingImportService::MSG_WITHOUT_XML, $result->flags);
        $this->assertNotContains(FilingImportService::MSG_ORDER_IRREGULAR, $result->flags);
        $this->assertNotContains(FilingImportService::MSG_KIND_MISMATCH, $result->flags);
        $this->assertSame([], $result->warnings);

        $filing = $this->filing($filingId);
        $this->assertSame(FilingDocument::ORIGIN_IMPORTED, $filing['origin']);
        $this->assertSame(FilingDocument::DOC_STATE_COMPOSED, (int) $filing['docState']);
        $this->assertSame('Přiznání DPH 2029/1', $filing['name'], 'název ze starého systému');
        $this->assertSame('2029-02-25', $this->iso($filing['date_filed']));
        $this->assertSame('2029-02-20', $this->iso($filing['date_issue']));

        $rows = $this->returnRows($filingId);
        $this->assertSame(1000.0, $rows[1]['base_filed']);
        $this->assertSame(210.0, $rows[1]['tax_full_filed']);
        $this->assertSame(1000.30, $rows[1]['base'], 'přesné hodnoty zůstávají z composeru');
        $this->assertSame(106.0, $rows[40]['tax_full_filed'], 'podaná hodnota z XML');
        $this->assertSame(106.0, $rows[46]['tax_full_filed']);
        $this->assertSame(106.0, $rows[63]['tax_full_filed']);
        $this->assertSame(104.0, $rows[64]['tax_full_filed']);
        $this->assertSame(210.0, $rows[62]['tax_full_filed']);

        $decoded = json_decode((string) $filing['result'], true);
        $this->assertEqualsWithDelta(104.0, $decoded['return']['row64'], 0.001, 'souhrn přepočtený z podaných hodnot');
        $this->assertEqualsWithDelta(106.0, $decoded['return']['row63'], 0.001);

        $header = json_decode((string) $filing['header'], true);
        $this->assertFalse($header['trans'], 'věta D z XML');
        $this->assertSame('Import s.r.o.', $header['zkrobchjm'], 'věta P z XML');
        $this->assertSame('de', $header['stat'], 'název státu → ISO kód');
        $this->assertSame('import@example.com', $header['email']);
        $this->assertSame('12345678', $header['dic']);

        $messages = json_decode((string) $filing['messages'], true);
        $codes    = array_column($messages, 'code');
        $this->assertContains(FilingImportService::MSG_ROW_MISMATCH, $codes);
        $mismatch = $this->message($messages, FilingImportService::MSG_ROW_MISMATCH, 'row', 64);
        $this->assertSame(105.0, (float) $mismatch['composed']['full']);
        $this->assertSame(104.0, (float) $mismatch['filed']['full']);
        $legacy = $this->message($messages, FilingImportService::MSG_LEGACY);
        $this->assertSame(812, $legacy['filingNdx']);
        $unmapped = $this->message($messages, FilingImportService::MSG_ROW_UNMAPPED);
        $this->assertSame('novy_radek', $unmapped['attribute']);

        // finish: 40 bez souborů, bez generování; podruhé no-op.
        $finish = $this->service()->finish($filingId);
        $this->assertSame(['filingId' => $filingId, 'docState' => 40, 'files' => 0], $finish);
        $filing = $this->filing($filingId);
        $this->assertSame(FilingDocument::DOC_STATE_FILED, (int) $filing['docState']);
        $this->assertSame(2, (int) $filing['docStateMain']);
        $this->assertSame('2029-02-25', $this->iso($filing['date_filed']), 'datum podání z importu, ne dnešek');
        $this->assertSame(0, $this->attachmentCount($filingId), 'import soubory negeneruje');
        $this->assertSame($finish, $this->service()->finish($filingId));
        $messages = json_decode((string) $this->filing($filingId)['messages'], true);
        $this->assertCount(1, array_filter($messages, static fn (array $m): bool => $m['code'] === FilingImportService::MSG_FILES));

        // Generátor souborů importované podání odmítne (původní přílohy jsou pravda).
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('importované');
        (new FilingFilesService($this->db->getDibiConnection(), $this->config))->generate($filingId, xmlOnly: true);
    }

    public function testWithoutXmlKeepsRoundedValues(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT bez XML');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.30, 210.06, 'CZ12345678');

        $result = $this->import([
            'reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'regular',
            'dateIssue' => '2029-02-20', 'dateFiled' => '2029-02-25',
        ]);

        $this->assertContains(FilingImportService::MSG_WITHOUT_XML, $result->flags);
        $this->assertSame(0, $result->mismatchRows);
        $rows = $this->returnRows($result->filingId);
        $this->assertSame(1000.0, $rows[1]['base_filed'], 'D17 zaokrouhlení');
        $this->assertSame(210.0, $rows[64]['tax_full_filed']);
        $this->assertStringContainsString('01/2029 IT bez XML', $this->filing($result->filingId)['name'], 'bez dodaného názvu se skládá');
    }

    public function testSupplementaryAfterImportedRegularDiffsAgainstXmlValues(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT dodatečné');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $this->insertDoc('invni', $periodId, 'vat_period', 'cz-110', 500.0, 105.0, 'CZ87654321');

        // Řádné podané se starou daní 200 (dnes doklady říkají 210).
        $regular = $this->import([
            'reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'regular',
            'dateIssue' => '2029-02-20', 'dateFiled' => '2029-02-25',
            'xml' => $this->dp3Xml(
                'dapdph_forma="B" typ_platce="P" trans="A"',
                'typ_ds="P" dic="12345678" zkrobchjm="Import s.r.o."',
                '<Veta1 obrat23="1000" dan23="200"/><Veta4 pln23="500" odp_tuz23_nar="105" odp_sum_nar="105"/>'
                . '<Veta6 dan_zocelk="200" odp_zocelk="105" dano_da="95"/>',
            ),
        ]);
        $this->assertSame(3, $regular->mismatchRows, 'ř. 1, 62 a 64 — daň o 10 nižší než dnes');
        $this->service()->finish($regular->filingId);

        // Dodatečné: rozdíl proti podaným hodnotám z XML (200), ne proti D17 (210).
        $supplementary = $this->import([
            'reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'supplementary',
            'dateIssue' => '2029-03-05', 'dateFiled' => '2029-03-06',
            'xml' => $this->dp3Xml(
                'dapdph_forma="D" d_zjist="01.03.2029" typ_platce="P"',
                'typ_ds="P" dic="12345678" zkrobchjm="Import s.r.o."',
                '<Veta1 dan23="10"/><Veta6 dan_zocelk="10" dano="10"/>',
            ),
        ]);

        $this->assertSame(0, $supplementary->mismatchRows, 'composer diffuje proti importovaným podaným hodnotám');
        $this->assertNotContains(FilingImportService::MSG_KIND_MISMATCH, $supplementary->flags);
        $this->assertNotContains(FilingImportService::MSG_ORDER_IRREGULAR, $supplementary->flags);
        $filing = $this->filing($supplementary->filingId);
        $this->assertSame($regular->filingId, (int) $filing['previous_filing']);
        $this->assertSame(2, (int) $filing['sequence']);
        $this->assertSame('2029-03-01', $this->iso($filing['date_found']), 'd_zjist z věty D');
        $rows = $this->returnRows($supplementary->filingId);
        $this->assertSame(10.0, $rows[1]['tax_full_filed']);
        $this->assertSame(0.0, $rows[1]['base_filed']);
        $this->assertSame(10.0, $rows[66]['tax_full_filed']);
    }

    // ── Kontrolní hlášení ───────────────────────────────────────────────────

    public function testControlStatementIsOnlyCompared(): void
    {
        $periodId = $this->insertPeriod('cs', '01/2029 IT KH import');
        $head      = $this->insertDoc('invno', $periodId, 'cs_period', 'cz-120', 20000.0, 4200.0, 'CZ12345678');
        $docNumber = (string) $this->db->fetchSingle('SELECT doc_number FROM docs_core_heads WHERE id = %i', $head);

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="old" verzeSW="1"><DPHKH1 verzePis="01.02">'
            . '<VetaD dokument="KH1" k_uladis="DPH" khdph_forma="B" rok="2029" mesic="1"/>'
            . '<VetaP typ_ds="P" dic="12345678" zkrobchjm="Import s.r.o."/>'
            . "<VetaA4 dic_odb=\"12345678\" c_evid_dd=\"{$docNumber}\" dppd=\"15.01.2029\" zakl_dane1=\"20000.00\""
            . ' dan1="4300.00" kod_rezim_pl="0" zdph_44="N"/>'
            . '<VetaC obrat23="20000.00"/>'
            . '</DPHKH1></Pisemnost>';

        $result = $this->import([
            'reportPeriodId' => $periodId, 'reportType' => 'cs', 'filingKind' => 'regular',
            'dateIssue' => '2029-02-20', 'dateFiled' => '2029-02-25', 'xml' => $xml,
        ]);

        $this->assertSame(1, $result->lineMismatches);
        $this->assertNotContains(FilingImportService::MSG_LINE_COMPARE_FAILED, $result->flags);
        $messages = json_decode((string) $this->filing($result->filingId)['messages'], true);
        $line = $this->message($messages, FilingImportService::MSG_LINE_MISMATCH);
        $this->assertSame('A4', $line['section']);
        $this->assertSame("12345678|{$docNumber}", $line['key']);
        $this->assertSame('dan1', $line['field']);
        $this->assertSame('4200', $line['composed']);
        $this->assertSame('4300', $line['filed']);

        $tax = (float) $this->db->fetchSingle(
            'SELECT tax1 FROM economy_vat_filing_cs_rows WHERE filing = %i AND section = %s',
            $result->filingId, 'A4',
        );
        $this->assertSame(4200.0, $tax, 'řádky hlášení se nepřepisují');

        $this->service()->finish($result->filingId);
        $this->assertSame(FilingDocument::DOC_STATE_FILED, (int) $this->filing($result->filingId)['docState']);
    }

    // ── Odmítnutí a varování ────────────────────────────────────────────────

    public function testPreChecksRejectBadInput(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT guardy');
        $base = ['reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'regular', 'dateFiled' => '2029-02-25'];

        $this->assertRejected(['reportPeriodId' => 999999999] + $base, 'PERIOD_NOT_FOUND', 404);
        $this->assertRejected(['reportType' => 'cs'] + $base, 'PERIOD_NOT_FOUND', 404);
        $this->assertRejected(['filingKind' => 'subsequent'] + $base, 'INVALID_KIND');
        $this->assertRejected(['filingKind' => 'supplementary'] + $base, 'DATE_FOUND_REQUIRED');
        $this->assertRejected(['filingKind' => 'supplementary', 'dateFound' => '2029-03-01'] + $base, 'PREVIOUS_FILING_MISSING');
        $this->assertRejected(['xml' => 'tohle není XML'] + $base, 'XML_UNREADABLE');
        $this->assertRejected(['xml' => (string) file_get_contents(__DIR__ . '/../../Fixtures/vat-xml/synthetic/kh1-regular.xml')] + $base, 'XML_TYPE_MISMATCH');
        $this->assertSame([], $this->createdFilings, 'předběžné kontroly nic nezakládají');

        // Živý koncept v instanci — import se odmítne s id konceptu.
        $draftId = $this->createDraft($periodId);
        try {
            $this->import($base);
            $this->fail('očekáváno DRAFT_EXISTS');
        } catch (FilingImportException $e) {
            $this->assertSame('DRAFT_EXISTS', $e->errorCode);
            $this->assertSame($draftId, $e->details['filingId']);
            $this->assertSame(FilingDocument::ORIGIN_COMPOSED, $e->details['origin']);
        }
        $this->assertSame('NOT_IMPORTED', $this->finishError($draftId), 'sestavený koncept finish nepodává');
    }

    public function testAccDocumentIsLinkedOrWarned(): void
    {
        $periodId = $this->insertPeriod('return', '01/2029 IT účto');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.0, 210.0, 'CZ12345678');
        $base = ['reportPeriodId' => $periodId, 'reportType' => 'return', 'filingKind' => 'regular', 'dateFiled' => '2029-02-25'];

        $dry = $this->service()->import(FilingImportRequest::fromArray(['accDocumentId' => 999999999] + $base), dryRun: true);
        $this->assertTrue($dry->dryRun);
        $this->assertCount(1, $dry->warnings);
        $this->assertContains(FilingImportService::MSG_ACC_DOCUMENT_MISSING, $dry->flags);
        $this->assertNull($this->filing($dry->filingId), 'dry-run nic nezapsal');

        $headId = $this->insertAccountingHead();
        $result = $this->import(['accDocumentId' => $headId] + $base);
        $this->assertSame([], $result->warnings);
        $this->assertSame($headId, (int) $this->filing($result->filingId)['acc_document'], 'FK už na konceptu (D37)');
        $this->service()->finish($result->filingId);
        $this->assertSame($headId, (int) $this->filing($result->filingId)['acc_document']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function import(array $body): FilingImportResult
    {
        $result = $this->service()->import(FilingImportRequest::fromArray($body));
        $this->createdFilings[] = $result->filingId;
        return $result;
    }

    /** @param array<string, mixed> $body */
    private function assertRejected(array $body, string $code, int $status = 422): void
    {
        try {
            $result = $this->service()->import(FilingImportRequest::fromArray($body));
            $this->createdFilings[] = $result->filingId;
            $this->fail("očekáváno {$code}");
        } catch (FilingImportException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            $this->assertSame($status, $e->status);
        }
    }

    private function finishError(int $filingId): ?string
    {
        try {
            $this->service()->finish($filingId);
            return null;
        } catch (FilingImportException $e) {
            return $e->errorCode;
        }
    }

    private function service(): FilingImportService
    {
        return new FilingImportService(
            $this->db->getDibiConnection(),
            $this->config,
            $this->dsConfig,
            $this->registry(),
            $this->tables,
        );
    }

    private function registry(): DocumentRegistry
    {
        return $this->registry ??= DocumentLoader::load(
            $this->dsConfig,
            new ModulePathResolver([dirname(__DIR__, 3) . '/modules']),
        );
    }

    /** Sestavený koncept přes gateway (jako z formuláře). */
    private function createDraft(int $periodId): int
    {
        $definition = $this->tables[FilingDocument::TABLE];
        $gateway    = new TableGateway(
            FilingDocument::TABLE, $this->db->getDibiConnection(), $this->registry(),
            $definition->childTables, $this->config, $this->dsConfig, null, $definition->docStates, $definition,
        );
        $result = $gateway->saveDocument([
            'report_period' => $periodId, 'filing_kind' => 'regular',
            'docState' => FilingDocument::DOC_STATE_COMPOSED, 'docStateMain' => 1,
        ]);
        $this->assertTrue($result->isSuccess(), (string) $result->getErrorMessage());
        $id = (int) ($result->getData()['id'] ?? 0);
        $this->createdFilings[] = $id;
        return $id;
    }

    private function dp3Xml(string $vetaD, string $vetaP, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="old" verzeSW="1"><DPHDP3 verzePis="01.02">'
            . "<VetaD dokument=\"DP3\" k_uladis=\"DPH\" {$vetaD} rok=\"2029\" mesic=\"1\"/><VetaP {$vetaP}/>"
            . $body . '</DPHDP3></Pisemnost>';
    }

    private function insertPeriod(string $type, string $name): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => $type,
            'name'             => mb_substr($name, 0, 20),
            'date_begin'       => self::DATE_BEGIN,
            'date_end'         => self::DATE_END,
            'locked'           => 0,
            'docState'         => 40,
            'docStateMain'     => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdPeriods[] = $id;
        return $id;
    }

    private function insertDoc(
        string $docType,
        int $periodId,
        string $periodColumn,
        string $vatCode,
        float $base,
        float $tax,
        string $partnerVatId,
    ): int {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            $docType,
        );
        if ($series === null) {
            $this->markTestSkipped("DS nemá aktivní řadu {$docType}");
        }
        $total    = round($base + $tax, 2);
        $snapshot = json_encode(['vat_id' => $partnerVatId], JSON_UNESCAPED_UNICODE);
        $isIssued = $docType === 'invno';

        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'           => $docType,
            'number_series'      => (int) $series['id'],
            'doc_number'         => 'IT-IMPORT-' . uniqid(),
            'partner_doc_number' => 'P-' . uniqid(),
            'issue_date'         => self::DUZP,
            'accounting_date'    => self::DUZP,
            'due_date'           => self::DUZP,
            'vat_duzp'           => self::DUZP,
            'vat_dppd'           => self::DUZP,
            'vat_mode'           => 1,
            'cs_mode'            => 0,
            'vat_registration'   => $this->registrationId,
            $periodColumn        => $periodId,
            'customer_snapshot'  => $isIssued ? $snapshot : null,
            'supplier_snapshot'  => $isIssued ? null : $snapshot,
            'doc_currency'       => 'czk',
            'home_currency'      => 'czk',
            'exchange_rate'      => 1.0,
            'doc_text'           => 'IT import podání DPH',
            'total_base'         => $base,
            'total_vat'          => $tax,
            'total_amount'       => $total,
            'total_base_dom'     => $base,
            'total_vat_dom'      => $tax,
            'total_amount_dom'   => $total,
            'docState'           => 40,
            'docStateMain'       => 2,
        ])->execute();
        $headId = (int) $dibi->getInsertId();
        $this->createdHeads[] = $headId;

        $dibi->insert('docs_core_vat_recap', [
            'doc_head' => $headId, 'vat_code' => $vatCode, 'vat_pct' => 21.0,
            'base' => $base, 'tax' => $tax, 'total' => $total,
            'base_dom' => $base, 'tax_dom' => $tax, 'total_dom' => $total,
            'sum_base' => 1, 'sum_tax' => 1, 'sum_total' => 1,
            'is_reverse_pair' => 0, 'order_pos' => 0,
        ])->execute();
        return $headId;
    }

    /** Účetní doklad (cmnbkp) bez vazby na instanci — kandidát na `acc_document`. */
    private function insertAccountingHead(): int
    {
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState = 40 ORDER BY id LIMIT 1',
            'cmnbkp',
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá aktivní řadu cmnbkp');
        }
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('docs_core_heads', [
            'doc_type'         => 'cmnbkp',
            'number_series'    => (int) $series['id'],
            'doc_number'       => 'IT-IMPORT-ACC-' . uniqid(),
            'issue_date'       => self::DATE_END,
            'accounting_date'  => self::DATE_END,
            'due_date'         => self::DATE_END,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'exchange_rate'    => 1.0,
            'doc_text'         => 'IT účetní doklad přiznání',
            'total_base'       => 0.0, 'total_vat' => 0.0, 'total_amount' => 0.0,
            'total_base_dom'   => 0.0, 'total_vat_dom' => 0.0, 'total_amount_dom' => 0.0,
            'docState'         => 10,
            'docStateMain'     => 1,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdHeads[] = $id;
        return $id;
    }

    /** @return ?array<string, mixed> */
    private function filing(int $filingId): ?array
    {
        $row = $this->db->fetchRow('SELECT * FROM economy_vat_filings WHERE id = %i', $filingId);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    /** @return array<int, array<string, float|int|string|null>> číslo řádku → řádek přiznání */
    private function returnRows(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM economy_vat_filing_return_rows WHERE filing = %i ORDER BY `row`',
            $filingId,
        );
        $out = [];
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : $row->toArray();
            foreach (['base', 'tax_full', 'tax_reduced', 'base_filed', 'tax_full_filed', 'tax_reduced_filed'] as $col) {
                $data[$col] = (float) $data[$col];
            }
            $out[(int) $data['row']] = $data;
        }
        return $out;
    }

    private function attachmentCount(int $filingId): int
    {
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_attachments_files WHERE table_id = %i AND record_id = %i AND is_deleted = 0',
            FilingFilesService::TABLE_ID, $filingId,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function message(array $messages, string $code, ?string $key = null, mixed $value = null): array
    {
        foreach ($messages as $message) {
            if (($message['code'] ?? null) === $code && ($key === null || ($message[$key] ?? null) === $value)) {
                return $message;
            }
        }
        $this->fail("zpráva {$code} nenalezena");
    }

    private function iso(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /** @param array<string, mixed> $profile */
    private function setRegistrationProfile(array $profile): void
    {
        if (!$this->profileRestored) {
            $this->originalProfile = $this->db->fetchSingle(
                'SELECT filing_profile FROM economy_codebooks_vat_registrations WHERE id = %i',
                $this->registrationId,
            );
            $this->profileRestored = true;
        }
        $profile['_schema'] = 'economy.vat.filingProfileCz/2026';
        $this->db->getDibiConnection()
            ->update('economy_codebooks_vat_registrations', [
                'filing_profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
            ])
            ->where('id = %i', $this->registrationId)->execute();
    }
}
