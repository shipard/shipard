<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Docs;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocBuilder;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocException;
use Shipard\Module\Docs\Core\Corrective\CorrectiveDocService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Vystavit opravný doklad produkční cestou (#92 D6, tasks/corrective-doc-exchange.md
 * commit 2 bod 5): k potvrzené faktuře vydané vznikne koncept s příznakem,
 * odkazem, číslem a datem plnění původního dokladu, stejným partnerem, řadou,
 * účtem a kurzem; základ i daň přesně opačné; bez důvodu opravy nejde
 * potvrdit, s důvodem ano. Odmítnutí: koncept, stornovaný doklad, přijatá
 * faktura, neexistující doklad.
 */
class CorrectiveDocServiceTest extends IntegrationTestCase
{
    private ?TableGateway $gateway = null;
    private ?CorrectiveDocService $service = null;

    /** @var list<int> hlavičky k úklidu (opravný doklad před původním) */
    private array $headIds = [];

    /** @var array<string, mixed> společná hlavička faktury vydané */
    private array $head = [];
    private bool $withVat = false;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        if ($this->db->fetchRow('SHOW COLUMNS FROM docs_core_heads LIKE %s', 'corrective') === null) {
            $this->markTestSkipped('Zdroj dat nemá sloupec corrective — spusť ds-upgrade');
        }
        $own = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1');
        if ($own === null) {
            $this->markTestSkipped('Dev DS nemá nastavenou vlastní firmu');
        }
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10, 40, 80) ORDER BY id LIMIT 1', 'invno');
        $partner = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 0 AND docState IN (10, 40, 80) ORDER BY id LIMIT 1');
        $bankAcc = $this->db->fetchRow('SELECT id FROM economy_codebooks_bank_accounts WHERE docState = 40 LIMIT 1');
        if ($series === null || $partner === null || $bankAcc === null) {
            $this->markTestSkipped('Dev DS nemá řadu invno / partnera / bankovní účet');
        }
        $vatReg = $this->db->fetchRow('SELECT id FROM economy_codebooks_vat_registrations WHERE docState IN (10, 40, 80) ORDER BY id LIMIT 1');
        $this->withVat = $vatReg !== null;
        $this->head = [
            'doc_type'         => 'invno',
            'number_series'    => (int) $series['id'],
            'partner'          => (int) $partner['id'],
            'bank_account'     => (int) $bankAcc['id'],
            'vat_registration' => $this->withVat ? (int) $vatReg['id'] : null,
            'vat_mode'         => $this->withVat ? 1 : 0,
            'doc_currency'     => 'czk',
            'payment_method'   => 1,
            'docStateMain'     => 1,
        ];

        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dibi = $this->db->getDibiConnection();
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $configRuntime);
        $this->gateway = new TableGateway(
            'docs_core_heads',
            $dibi,
            $registry,
            $this->tables['docs_core_heads']->childTables,
            $configRuntime,
            $this->dsConfig,
            $dispatcher,
        );
        $this->service = CorrectiveDocService::create($dibi, $configRuntime, $this->dsConfig, $registry, $this->tables, $dispatcher);
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach (array_reverse($this->headIds) as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
    }

    /** @return list<string> kódy chyb validace */
    private static function errorCodes(\Shipard\Core\Document\DocumentResult $result): array
    {
        return array_map(
            static fn ($e) => $e->toArray()['code'],
            $result->getValidation()?->getErrors() ?? [],
        );
    }

    /** @return array<string, mixed> řádek faktury: služba za jednotkovou cenu */
    private function row(float $qty, float $unitPrice = 1000.0): array
    {
        $r = ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Služba', 'quantity' => $qty, 'unit_price' => $unitPrice];
        return $this->withVat ? $r + ['vat_code' => 'cz-120', 'vat_pct' => 21.0] : $r;
    }

    /** Založí a potvrdí fakturu vydanou; vrací id. */
    private function confirmedInvoice(array $extra, array $rows): int
    {
        // $extra přepisuje společnou hlavičku (měna, kurz) — levý operand `+` vyhrává.
        $create = $this->gateway->saveDocument($extra + $this->head + ['docState' => 10, 'rows' => $rows]);
        $this->assertTrue($create->isSuccess(), 'Insert faktury selhal: ' . ($create->getErrorMessage() ?? implode(', ', self::errorCodes($create))));
        $id = (int) $create->getData()['id'];
        $this->headIds[] = $id;

        $doc = $this->gateway->loadDocument($id);
        $doc['docState'] = 40;
        $confirm = $this->gateway->saveDocument($doc);
        $this->assertTrue($confirm->isSuccess(), 'Potvrzení faktury selhalo: ' . ($confirm->getErrorMessage() ?? implode(', ', self::errorCodes($confirm))));
        return $id;
    }

    public function testCreatesDraftWithOppositeAmountsAndLinkToOriginal(): void
    {
        $originalId = $this->confirmedInvoice(
            ['issue_date' => '2026-03-10', 'accounting_date' => '2026-03-10', 'doc_text' => 'IT corrective service — original'],
            [$this->row(2.0), $this->row(1.0, 500.0)],
        );
        $original = $this->gateway->loadDocument($originalId);

        $draftId = $this->service->createFor($originalId, '2026-04-20');
        $this->headIds[] = $draftId;
        $draft = $this->gateway->loadDocument($draftId);

        $this->assertSame(10, (int) $draft['docState'], 'koncept');
        $this->assertSame(1, (int) $draft['corrective']);
        $this->assertSame($originalId, (int) $draft['corrected_doc']);
        $this->assertSame((string) $original['doc_number'], (string) $draft['corrected_doc_number']);
        $this->assertSame(substr((string) $original['vat_duzp'], 0, 10), substr((string) $draft['corrected_doc_date'], 0, 10), 'datum plnění původní faktury');
        $this->assertNull($draft['correction_reason'], 'důvod doplní uživatel');
        $this->assertSame((int) $original['partner'], (int) $draft['partner']);
        $this->assertSame((int) $original['number_series'], (int) $draft['number_series'], 'řada původního dokladu');
        $this->assertSame((int) $original['bank_account'], (int) $draft['bank_account']);
        $this->assertSame('czk', $draft['doc_currency']);
        $this->assertSame(CorrectiveDocBuilder::SOURCE_KIND, $draft['source_kind']);
        $this->assertSame('2026-04-20', substr((string) $draft['issue_date'], 0, 10));
        $this->assertSame('', (string) ($draft['doc_text'] ?? ''), 'text dokladu se nepřebírá');

        $this->assertSame(-(float) $original['total_base'], (float) $draft['total_base'], 'základ přesně opačný');
        $this->assertSame(-(float) $original['total_vat'], (float) $draft['total_vat'], 'daň přesně opačná');
        $this->assertSame(-(float) $original['total_amount'], (float) $draft['total_amount']);

        $rows = $draft['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(-2.0, (float) $rows[0]['quantity']);
        $this->assertSame(1000.0, (float) $rows[0]['unit_price'], 'jednotková cena zůstává kladná');
        $this->assertSame(-1.0, (float) $rows[1]['quantity']);
        $this->assertSame(500.0, (float) $rows[1]['unit_price']);
        if ($this->withVat) {
            $this->assertSame('cz-120', $rows[0]['vat_code'], 'kód DPH převzatý');
        }

        // Potvrzení bez důvodu opravy selže, s důvodem projde.
        $draft['docState'] = 40;
        $failed = $this->gateway->saveDocument($draft);
        $this->assertFalse($failed->isSuccess());
        $this->assertContains('correction_reason_required', self::errorCodes($failed));

        $draft['correction_reason'] = 'Vrácené zboží';
        $confirmed = $this->gateway->saveDocument($draft);
        $this->assertTrue($confirmed->isSuccess(), 'Potvrzení opravného dokladu selhalo: ' . ($confirmed->getErrorMessage() ?? implode(', ', self::errorCodes($confirmed))));
        $this->assertSame(40, (int) $this->gateway->loadDocument($draftId)['docState']);
    }

    public function testForeignCurrencyDraftKeepsOriginalRate(): void
    {
        $originalId = $this->confirmedInvoice(
            ['issue_date' => '2026-03-10', 'accounting_date' => '2026-03-10', 'doc_currency' => 'eur', 'exchange_rate' => 25.5,
             'doc_text' => 'IT corrective service — EUR'],
            [$this->row(1.0, 100.0)],
        );
        $original = $this->gateway->loadDocument($originalId);

        $draftId = $this->service->createFor($originalId, '2026-04-20');
        $this->headIds[] = $draftId;
        $draft = $this->gateway->loadDocument($draftId);

        $this->assertSame('eur', $draft['doc_currency']);
        $this->assertSame(25.5, (float) $draft['exchange_rate'], 'kurz původní faktury, ne z lístku');
        $this->assertSame(-(float) $original['total_base'], (float) $draft['total_base']);
        $this->assertSame(-(float) $original['total_base_dom'], (float) $draft['total_base_dom'], 'přepočet domácí měny týmž kurzem');
    }

    public function testRefusesDraftCancelledOtherTypeAndMissingDocument(): void
    {
        // Koncept faktury vydané.
        $create = $this->gateway->saveDocument($this->head + [
            'issue_date' => '2026-03-10', 'accounting_date' => '2026-03-10', 'doc_text' => 'IT corrective service — draft',
            'docState' => 10, 'rows' => [$this->row(1.0)],
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert konceptu selhal: ' . ($create->getErrorMessage() ?? implode(', ', self::errorCodes($create))));
        $draftId = (int) $create->getData()['id'];
        $this->headIds[] = $draftId;

        try {
            $this->service->createFor($draftId);
            $this->fail('koncept nejde opravit');
        } catch (CorrectiveDocException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame(CorrectiveDocException::SOURCE_INVALID, $e->errorCode);
        }

        // Stornovaný doklad (stav 30 přímo v DB — storno nemá produkční zkratku v testu).
        $this->db->getDibiConnection()->query('UPDATE docs_core_heads SET docState = 30 WHERE id = %i', $draftId);
        try {
            $this->service->createFor($draftId);
            $this->fail('stornovaný doklad nejde opravit');
        } catch (CorrectiveDocException $e) {
            $this->assertSame(CorrectiveDocException::SOURCE_INVALID, $e->errorCode);
        }

        // Přijatá faktura — typ bez příznaku, odmítnuto bez ohledu na stav.
        $invniSeries = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (10, 40, 80) ORDER BY id LIMIT 1', 'invni');
        if ($invniSeries !== null) {
            $received = $this->gateway->saveDocument([
                'doc_type' => 'invni', 'number_series' => (int) $invniSeries['id'], 'partner' => $this->head['partner'],
                'vat_mode' => 0, 'doc_currency' => 'czk', 'payment_method' => 1, 'docStateMain' => 1,
                'issue_date' => '2026-03-10', 'accounting_date' => '2026-03-10', 'doc_text' => 'IT corrective service — received',
                'docState' => 10, 'rows' => [['row_kind' => 1, 'operation' => 'purchase.services', 'description' => 'Služba', 'quantity' => 1.0, 'unit_price' => 100.0]],
            ]);
            $this->assertTrue($received->isSuccess(), 'Insert přijaté faktury selhal: ' . ($received->getErrorMessage() ?? implode(', ', self::errorCodes($received))));
            $receivedId = (int) $received->getData()['id'];
            $this->headIds[] = $receivedId;
            try {
                $this->service->createFor($receivedId);
                $this->fail('k přijaté faktuře opravný doklad nejde');
            } catch (CorrectiveDocException $e) {
                $this->assertSame(CorrectiveDocException::SOURCE_INVALID, $e->errorCode);
                $this->assertStringContainsString('typu', $e->getMessage());
            }
        }

        try {
            $this->service->createFor(2147483000);
            $this->fail('neexistující doklad');
        } catch (CorrectiveDocException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame(CorrectiveDocException::NOT_FOUND, $e->errorCode);
        }
    }
}
