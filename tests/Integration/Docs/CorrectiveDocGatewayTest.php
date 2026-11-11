<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Docs;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Opravný daňový doklad přes produkční cestu (#92, tasks/corrective-doc-core.md
 * commit 1 bod 5): faktura vydaná V pořádku, k ní opravný doklad s odkazem —
 * po uložení nese číslo a datum plnění původního dokladu, potvrzení bez
 * důvodu opravy selže, s důvodem projde (vstup do 40 zaúčtuje jako dnešní
 * záporná faktura).
 */
class CorrectiveDocGatewayTest extends IntegrationTestCase
{
    private ?TableGateway $gateway = null;

    /** @var list<int> hlavičky k úklidu (opravný doklad před původním) */
    private array $headIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $defined = array_filter(
            $this->tables['docs_core_heads']->columns,
            static fn ($column) => $column->id === 'corrective',
        );
        if ($defined === []) {
            $this->markTestSkipped('Definice docs_core_heads nemá sloupec corrective');
        }
        if ($this->db->fetchRow('SHOW COLUMNS FROM docs_core_heads LIKE %s', 'corrective') === null) {
            $this->markTestSkipped('Zdroj dat nemá sloupec corrective — spusť ds-upgrade');
        }
        $own = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1',
        );
        if ($own === null) {
            $this->markTestSkipped('Dev DS nemá nastavenou vlastní firmu');
        }

        $this->gateway = new TableGateway(
            'docs_core_heads',
            $this->db->getDibiConnection(),
            DocumentLoader::load($this->dsConfig, $resolver),
            $this->tables['docs_core_heads']->childTables,
            $configRuntime,
            $this->dsConfig,
            DocumentEventHandlerLoader::load(
                $this->dsConfig,
                $resolver,
                $this->db->getDibiConnection(),
                $configRuntime,
            ),
        );
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

    public function testCorrectiveInvoiceTakesOriginalNumberAndDateAndRequiresReason(): void
    {
        $series = $this->db->fetchRow('SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invno');
        $partner = $this->db->fetchRow(
            'SELECT id FROM base_persons_persons WHERE is_own = 0 AND docState = 40 ORDER BY id LIMIT 1',
        );
        $bankAcc = $this->db->fetchRow('SELECT id FROM economy_codebooks_bank_accounts WHERE docState = 40 LIMIT 1');
        if ($series === null || $partner === null || $bankAcc === null) {
            $this->markTestSkipped('Dev DS nemá řadu invno / partnera / bankovní účet');
        }
        // Zdroj bez registrace DPH (4l3j) jede bez DPH; s registrací 21 %.
        $vatReg = $this->db->fetchRow('SELECT id FROM economy_codebooks_vat_registrations LIMIT 1');
        $withVat = $vatReg !== null;
        $row = static function (float $qty) use ($withVat): array {
            $r = ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Služba',
                  'quantity' => $qty, 'unit_price' => 1000.0];
            return $withVat ? $r + ['vat_code' => 'cz-120', 'vat_pct' => 21.0] : $r;
        };
        $head = [
            'doc_type'         => 'invno',
            'number_series'    => (int) $series['id'],
            'partner'          => (int) $partner['id'],
            'bank_account'     => (int) $bankAcc['id'],
            'vat_registration' => $withVat ? (int) $vatReg['id'] : null,
            'vat_mode'         => $withVat ? 1 : 0,
            'doc_currency'     => 'czk',
            'payment_method'   => 1,
            'docStateMain'     => 1,
        ];

        // 1. Původní faktura: Koncept → V pořádku (číslo z řady, DUZP = vystavení).
        $create = $this->gateway->saveDocument($head + [
            'issue_date'      => '2026-03-10',
            'accounting_date' => '2026-03-10',
            'doc_text'        => 'IT corrective — original',
            'docState'        => 10,
            'rows'            => [$row(1.0)],
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert původní faktury selhal: ' . ($create->getErrorMessage() ?? 'validace'));
        $originalId = (int) $create->getData()['id'];
        $this->headIds[] = $originalId;

        $original = $this->gateway->loadDocument($originalId);
        $original['docState'] = 40;
        $confirm = $this->gateway->saveDocument($original);
        $this->assertTrue($confirm->isSuccess(), 'Potvrzení původní faktury selhalo: ' . ($confirm->getErrorMessage() ?? implode(', ', self::errorCodes($confirm))));
        $original = $this->gateway->loadDocument($originalId);
        $this->assertSame(0, (int) $original['corrective'], 'běžná faktura příznak nemá');
        $this->assertNull($original['corrected_doc']);
        $this->assertNotSame('', (string) $original['doc_number']);

        // 2. Opravný doklad s odkazem: číslo a datum plnění přepíše dokument.
        $create = $this->gateway->saveDocument($head + [
            'issue_date'           => '2026-04-15',
            'accounting_date'      => '2026-04-15',
            'doc_text'             => 'IT corrective — credit note',
            'corrective'           => 1,
            'corrected_doc'        => $originalId,
            'corrected_doc_number' => 'ručně',
            'corrected_doc_date'   => '2020-01-01',
            'docState'             => 10,
            'rows'                 => [$row(-1.0)],
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert opravného dokladu selhal: ' . ($create->getErrorMessage() ?? implode(', ', self::errorCodes($create))));
        $correctiveId = (int) $create->getData()['id'];
        $this->headIds[] = $correctiveId;

        $corrective = $this->gateway->loadDocument($correctiveId);
        $this->assertSame(1, (int) $corrective['corrective']);
        $this->assertSame($originalId, (int) $corrective['corrected_doc']);
        $this->assertSame((string) $original['doc_number'], (string) $corrective['corrected_doc_number']);
        $this->assertSame('2026-03-10', substr((string) $corrective['corrected_doc_date'], 0, 10), 'DUZP původní faktury');
        $this->assertNull($corrective['correction_reason']);
        $this->assertSame(-1000.0, (float) $corrective['total_base'], 'záporný řádek');

        // 3. Potvrzení bez důvodu opravy selže na correction_reason_required.
        $corrective['docState'] = 40;
        $failed = $this->gateway->saveDocument($corrective);
        $this->assertFalse($failed->isSuccess());
        $this->assertContains('correction_reason_required', self::errorCodes($failed));

        // 4. S důvodem projde — vstup do 40 jako u dnešní záporné faktury.
        $corrective['correction_reason'] = 'Vrácené zboží';
        $confirmed = $this->gateway->saveDocument($corrective);
        $this->assertTrue($confirmed->isSuccess(), 'Potvrzení opravného dokladu selhalo: ' . ($confirmed->getErrorMessage() ?? implode(', ', self::errorCodes($confirmed))));

        $corrective = $this->gateway->loadDocument($correctiveId);
        $this->assertSame(40, (int) $corrective['docState']);
        $this->assertSame('Vrácené zboží', $corrective['correction_reason']);
        $this->assertSame((string) $original['doc_number'], (string) $corrective['corrected_doc_number']);
    }
}
