<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingBuilder;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingService;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Zaúčtování podaného přiznání nad reálným dev DS (#55 D28–D31): založí
 * cmnbkp ve stavu Koncept s vyrovnanými řádky, naváže ho na podání
 * (`acc_document` + zpráva), druhé volání odmítne, po stornu dokladu projde
 * znovu; koncept podání a hlášení odmítne. Vlastní instance v izolovaném
 * rozsahu 03/2027 (fiskální rok existuje, instance tvrzení ne).
 */
class VatReturnAccountingServiceTest extends IntegrationTestCase
{
    private const BASE_PROFILE = ['typ_ds' => 'P', 'c_ufo' => '464', 'c_okec' => '620200'];

    /**
     * Syntetické DIČ registrace — variabilní symbol saldo řádku se bere
     * z něj, test proto nesmí stát na DIČ zdroje dat (#167).
     */
    private const VAT_ID = 'CZ00000019';

    private const DATE_BEGIN = '2027-03-01';
    private const DATE_END   = '2027-03-31';
    private const DUZP       = '2027-03-15';

    private ?ConfigRuntime $config = null;
    private ?DocumentRegistry $registry = null;
    private int $registrationId = 0;
    private int $taxOfficeId = 0;

    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdHeads = [];
    /** @var list<int> */
    private array $createdFilings = [];

    /** @var array{vat_id: mixed, filing_profile: mixed, tax_office_person: mixed}|null původní hodnoty registrace */
    private ?array $originalRegistration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        if (!isset($this->tables[FilingDocument::TABLE]) || !isset($this->tables['docs_core_heads'])) {
            $this->markTestSkipped('DS nemá tabulky podání / dokladů — spusťte ds-upgrade');
        }
        if (!is_array($this->config->cfgItem('economy.vat.reports.cz'))) {
            $this->markTestSkipped('DS nemá compiled mapování economy.vat.reports.cz');
        }
        $columns = $this->db->fetchAll('SHOW COLUMNS FROM economy_vat_filings');
        if (!in_array('acc_document', array_map(static fn ($c) => (string) $c['Field'], $columns), true)) {
            $this->markTestSkipped('DS nemá sloupec economy_vat_filings.acc_document — spusťte ds-upgrade');
        }

        $reg = $this->db->fetchRow(
            'SELECT id, vat_id, filing_profile, tax_office_person FROM economy_codebooks_vat_registrations'
            . ' WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];
        $this->originalRegistration = [
            'vat_id'            => $reg['vat_id'],
            'filing_profile'    => $reg['filing_profile'],
            'tax_office_person' => $reg['tax_office_person'],
        ];

        $collision = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_report_periods'
            . ' WHERE vat_registration = %i AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, self::DATE_END, self::DATE_BEGIN,
        );
        if ($collision > 0) {
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 03/2027');
        }

        $person = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE docState IN (10,40,80) ORDER BY id LIMIT 1',
        );
        if ($person === null) {
            $this->markTestSkipped('DS nemá živou osobu pro správce daně');
        }
        $this->taxOfficeId = (int) $person['id'];

        $profile = self::BASE_PROFILE + ['_schema' => 'economy.vat.filingProfileCz/2026'];
        $this->db->getDibiConnection()->update('economy_codebooks_vat_registrations', [
            'vat_id'            => self::VAT_ID,
            'filing_profile'    => json_encode($profile, JSON_UNESCAPED_UNICODE),
            'tax_office_person' => $this->taxOfficeId,
        ])->where('id = %i', $this->registrationId)->execute();
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdFilings as $id) {
            foreach (FilingDocument::SNAPSHOT_TABLES as $table) {
                $dibi->delete($table)->where('filing = %i', $id)->execute();
            }
            $dibi->delete(FilingDocument::TABLE)->where('id = %i', $id)->execute();
        }
        // Účetní doklady založené službou — dohledat přes podání už nejde
        // (smazaná), proto si je pamatujeme z výsledků.
        foreach ($this->createdHeads as $id) {
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        foreach ($this->createdPeriods as $id) {
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $id)->execute();
        }
        if ($this->originalRegistration !== null) {
            $dibi->update('economy_codebooks_vat_registrations', $this->originalRegistration)
                ->where('id = %i', $this->registrationId)->execute();
        }
    }

    // ── scénáře ─────────────────────────────────────────────────────────────

    public function testAccountsFiledReturnAsDraftDocument(): void
    {
        $filingId = $this->filedReturn();

        $result = $this->service()->account($filingId);
        $this->assertTrue($result->ok, $result->message . ' ' . implode(' | ', $result->messageTexts()));
        $docId = (int) $result->docId;
        $this->createdHeads[] = $docId;

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('cmnbkp', $head['doc_type']);
        $this->assertSame(10, (int) $head['docState'], 'doklad vzniká jako koncept (D31)');
        $this->assertSame(self::DATE_END, self::isoDate($head['accounting_date']));
        $this->assertSame('Přiznání DPH 03/2027 IT', $head['doc_text']);
        $this->assertSame($this->taxOfficeId, (int) $head['partner']);
        $this->assertSame(0, (int) $head['vat_mode']);
        $this->assertNull($head['vat_period'], 'doklad přiznání do instance nespadá — zámek instance ho nechytá');

        $rows = $this->rowsByAccount($docId);
        // Vydaná 210.06 (výstup → MD), přijatá 105.11 (vstup → DAL), odvod
        // 105 (podané ř. 64 = 210 − 105), zbytek −0.05 → náklad 548.
        $this->assertSame([VatReturnAccountingBuilder::SIDE_DEBIT, 210.06], $rows['343120']);
        $this->assertSame([VatReturnAccountingBuilder::SIDE_CREDIT, 105.11], $rows['343110']);
        $this->assertSame([VatReturnAccountingBuilder::SIDE_CREDIT, 105.00], $rows['343801']);
        $this->assertSame([VatReturnAccountingBuilder::SIDE_DEBIT, 0.05], $rows['548100']);
        $this->assertEqualsWithDelta($this->sumSide($docId, 0), $this->sumSide($docId, 1), 0.001, 'Σ MD = Σ DAL');

        $balance = $this->db->fetchRow(
            'SELECT r.* FROM docs_core_rows r JOIN economy_accounting_accounts a ON a.id = r.account'
            . ' WHERE r.doc_head = %i AND a.number = %s',
            $docId, '343801',
        );
        $this->assertSame($this->taxOfficeId, (int) $balance['partner']);
        $this->assertSame(
            preg_replace('/\D+/', '', self::VAT_ID),
            $balance['payment_reference'],
            'VS = číslice DIČ registrace',
        );
        $this->assertSame('705202703', $balance['specific_symbol']);
        $this->assertSame('1148', $balance['constant_symbol']);
        $this->assertSame('2027-04-25', self::isoDate($balance['due_date']));
        $this->assertSame('acc.record', $balance['operation']);
        $this->assertSame(1, (int) $balance['price_calc_mode']);

        $filing = $this->db->fetchRow('SELECT acc_document, messages FROM economy_vat_filings WHERE id = %i', $filingId);
        $this->assertSame($docId, (int) $filing['acc_document']);
        $codes = array_column(json_decode((string) $filing['messages'], true), 'code');
        $this->assertContains(VatReturnAccountingService::MSG_ACCOUNTED, $codes);
    }

    public function testSecondAccountingIsRefusedUntilDocumentIsCancelled(): void
    {
        $filingId = $this->filedReturn();
        $first = $this->service()->account($filingId);
        $this->assertTrue($first->ok, $first->message);
        $this->createdHeads[] = (int) $first->docId;

        $again = $this->service()->account($filingId);
        $this->assertFalse($again->ok);
        $this->assertSame('ALREADY_ACCOUNTED', $again->code);
        $this->assertSame((int) $first->docId, $again->context['existingDocId']);

        // Storno dokladu → nový doklad, FK se přepíše.
        $this->db->getDibiConnection()->update('docs_core_heads', ['docState' => 30, 'docStateMain' => 3])
            ->where('id = %i', (int) $first->docId)->execute();
        $third = $this->service()->account($filingId);
        $this->assertTrue($third->ok, $third->message . ' ' . implode(' | ', $third->messageTexts()));
        $this->createdHeads[] = (int) $third->docId;
        $this->assertNotSame((int) $first->docId, (int) $third->docId);
        $this->assertSame(
            (int) $third->docId,
            (int) $this->db->fetchSingle('SELECT acc_document FROM economy_vat_filings WHERE id = %i', $filingId),
        );
    }

    public function testDraftFilingIsRefusedButDryRunPlansIt(): void
    {
        $periodId = $this->insertPeriod('return', '03/2027 IT');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.30, 210.06, 'CZ12345678');
        $filingId = $this->createFiling($periodId, 'regular');

        $refused = $this->service()->account($filingId);
        $this->assertFalse($refused->ok);
        $this->assertSame('INVALID_DOC_STATE', $refused->code);

        $plan = $this->service()->plan($filingId, allowDraft: true);
        $this->assertTrue($plan->ok, $plan->message);
        $this->assertTrue((bool) $plan->context['draft']);
        $this->assertNotNull($plan->plan);
        $this->assertNotSame([], $plan->plan->rows);
        $this->assertNull($plan->docId);
        $this->assertNull(
            $this->db->fetchSingle('SELECT acc_document FROM economy_vat_filings WHERE id = %i', $filingId),
            'dry-run nic nezapisuje',
        );
    }

    public function testControlStatementFilingIsRefused(): void
    {
        $periodId = $this->insertPeriod('cs', 'KH 03/2027 IT');
        $filingId = $this->createFiling($periodId, 'regular');

        $result = $this->service()->plan($filingId, allowDraft: true);
        $this->assertFalse($result->ok);
        $this->assertSame('INVALID_REPORT_TYPE', $result->code);
    }

    public function testMissingTaxOfficeIsWarningAndBalanceRowHasNoPartner(): void
    {
        $this->db->getDibiConnection()->update('economy_codebooks_vat_registrations', ['tax_office_person' => null])
            ->where('id = %i', $this->registrationId)->execute();
        $filingId = $this->filedReturn();

        $result = $this->service()->account($filingId);
        $this->assertTrue($result->ok, $result->message);
        $this->createdHeads[] = (int) $result->docId;
        $this->assertSame(
            [VatReturnAccountingBuilder::MSG_TAX_OFFICE_MISSING],
            array_column($result->plan->warnings(), 'code'),
        );
        $balance = $this->db->fetchRow(
            'SELECT r.partner FROM docs_core_rows r JOIN economy_accounting_accounts a ON a.id = r.account'
            . ' WHERE r.doc_head = %i AND a.number = %s',
            (int) $result->docId, '343801',
        );
        $this->assertNull($balance['partner']);

        $codes = array_column(
            json_decode((string) $this->db->fetchSingle('SELECT messages FROM economy_vat_filings WHERE id = %i', $filingId), true),
            'code',
        );
        $this->assertContains(VatReturnAccountingBuilder::MSG_TAX_OFFICE_MISSING, $codes);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** Řádné přiznání 03/2027 s jednou vydanou a jednou přijatou fakturou, podané. */
    private function filedReturn(): int
    {
        $periodId = $this->insertPeriod('return', '03/2027 IT');
        $this->insertDoc('invno', $periodId, 'vat_period', 'cz-120', 1000.30, 210.06, 'CZ12345678');
        $this->insertDoc('invni', $periodId, 'vat_period', 'cz-110', 500.50, 105.11, 'CZ87654321');
        $filingId = $this->createFiling($periodId, 'regular');
        $this->fileFiling($filingId);
        return $filingId;
    }

    private function service(): VatReturnAccountingService
    {
        return new VatReturnAccountingService(
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
            'doc_number'         => 'IT-ACC-' . uniqid(),
            'partner_doc_number' => 'P-' . uniqid(),
            'issue_date'         => self::DUZP,
            'accounting_date'    => self::DUZP,
            'due_date'           => self::DUZP,
            'vat_duzp'           => self::DUZP,
            'vat_dppd'           => self::DUZP,
            'vat_mode'           => 1,
            'vat_registration'   => $this->registrationId,
            $periodColumn        => $periodId,
            'customer_snapshot'  => $isIssued ? $snapshot : null,
            'supplier_snapshot'  => $isIssued ? null : $snapshot,
            'doc_currency'       => 'czk',
            'home_currency'      => 'czk',
            'exchange_rate'      => 1.0,
            'doc_text'           => 'IT zaúčtování přiznání',
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

    private function createFiling(int $periodId, string $kind): int
    {
        $result = $this->filingGateway()->saveDocument([
            'report_period' => $periodId,
            'filing_kind'   => $kind,
            'docState'      => FilingDocument::DOC_STATE_COMPOSED,
            'docStateMain'  => 1,
        ]);
        $this->assertSaved($result, 'uložení podání');
        $id = (int) ($result->getData()['id'] ?? 0);
        $this->createdFilings[] = $id;
        return $id;
    }

    /** Přechod Sestaveno → Podáno přes Document (jako z formuláře). */
    private function fileFiling(int $filingId): void
    {
        $gateway  = $this->filingGateway();
        $existing = $gateway->loadDocument($filingId);
        $existing['docState']     = FilingDocument::DOC_STATE_FILED;
        $existing['docStateMain'] = 2;
        $this->assertSaved($gateway->saveDocument($existing), 'podání');
    }

    private function filingGateway(): TableGateway
    {
        $definition = $this->tables[FilingDocument::TABLE];
        return new TableGateway(
            FilingDocument::TABLE,
            $this->db->getDibiConnection(),
            $this->registry(),
            $definition->childTables,
            $this->config,
            $this->dsConfig,
            null,
            $definition->docStates,
        );
    }

    private function assertSaved(DocumentResult $result, string $what): void
    {
        $this->assertTrue(
            $result->isSuccess(),
            $what . ': ' . ($result->getErrorMessage() ?? json_encode($result->getValidation()?->toArray(), JSON_UNESCAPED_UNICODE)),
        );
    }

    /** @return array<string, array{0: int, 1: float}> číslo účtu → [strana, částka] */
    private function rowsByAccount(int $docId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.number, r.acc_side, r.total_price FROM docs_core_rows r'
            . ' JOIN economy_accounting_accounts a ON a.id = r.account WHERE r.doc_head = %i ORDER BY r.order_pos',
            $docId,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['number']] = [(int) $row['acc_side'], round((float) $row['total_price'], 2)];
        }
        return $out;
    }

    /** DataSourceConnection vrací datumy jako string, dibi jako DateTime. */
    private static function isoDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function sumSide(int $docId, int $side): float
    {
        return round((float) $this->db->fetchSingle(
            'SELECT COALESCE(SUM(total_price), 0) FROM docs_core_rows WHERE doc_head = %i AND acc_side = %i',
            $docId, $side,
        ), 2);
    }
}
