<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Docs;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Docs\Core\RoundingModes;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Automatické zaokrouhlení přes produkční cestu (#73, tasks/doc-total-rounding-auto.md
 * commit 2 bod 5): TableGateway na ukázkovém zdroji dat má konfiguraci
 * i zemi, takže faktura vydaná hrazená hotově dostane mód Matematicky na 1
 * a zaokrouhlenou částku; převodem na haléře; vypnutá automatika s hotovostí
 * na haléře dá varování, ale uložení projde.
 */
class TotalRoundingAutoGatewayTest extends IntegrationTestCase
{
    private ?TableGateway $gateway = null;
    private ?int $headId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        if (!is_array($configRuntime->cfgItem('world.base.cashRounding'))) {
            $this->markTestSkipped('Zdroj dat nemá zkompilovaný cfgItem world.base.cashRounding — spusť ds-upgrade');
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
        if ($this->headId !== null) {
            $dibi = $this->db->getDibiConnection();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $this->headId)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $this->headId)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $this->headId)->execute();
        }
    }

    public function testCashInvoiceRoundsToUnitsTransferToCents(): void
    {
        if ($this->dsConfig->getCountry() !== 'cz') {
            $this->markTestSkipped('Test počítá s českým zdrojem dat (CZK na koruny)');
        }
        $series = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s LIMIT 1', 'invno',
        );
        if ($series === null) {
            $this->markTestSkipped('Dev DS nemá řadu invno');
        }
        // S registrací DPH 1 × 100,33 při 21 % = 121,40 → hotově 121,00;
        // zdroj bez registrace (4l3j) jede bez DPH: 100,33 → 100,00.
        $vatReg = $this->db->fetchRow('SELECT id FROM economy_codebooks_vat_registrations LIMIT 1');
        $withVat = $vatReg !== null;
        $gross = $withVat ? 121.40 : 100.33;
        $units = $withVat ? 121.0 : 100.0;
        $row = ['row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Práce',
                'quantity' => 1, 'unit_price' => 100.33];
        if ($withVat) {
            $row += ['vat_code' => 'cz-120', 'vat_pct' => 21.0];
        }

        $create = $this->gateway->saveDocument([
            'doc_type'            => 'invno',
            'number_series'       => (int) $series['id'],
            'issue_date'          => '2026-06-10',
            'accounting_date'     => '2026-06-10',
            'vat_registration'    => $withVat ? (int) $vatReg['id'] : null,
            'vat_mode'            => $withVat ? 1 : 0,
            'doc_currency'        => 'czk',
            'payment_method'      => 0,
            'total_rounding_auto' => 1,
            'total_rounding_mode' => RoundingModes::ON_CENT,
            'doc_text'            => 'IT total rounding auto',
            'docState'            => 10,
            'docStateMain'        => 1,
            'rows'                => [$row],
        ]);
        $this->assertTrue($create->isSuccess(), 'Insert selhal: ' . ($create->getErrorMessage() ?? 'validace'));
        $this->headId = (int) $create->getData()['id'];

        $doc = $this->gateway->loadDocument($this->headId);
        $this->assertNotNull($doc);
        $this->assertSame(1, (int) $doc['total_rounding_auto']);
        $this->assertSame(RoundingModes::MATH_UNIT, (int) $doc['total_rounding_mode']);
        $this->assertSame($units, (float) $doc['total_amount']);
        $this->assertEqualsWithDelta($units - $gross, (float) $doc['total_rounding'], 0.001);

        // Převodem → na haléře, částka bez zaokrouhlení.
        $doc['payment_method'] = 1;
        $update = $this->gateway->saveDocument($doc);
        $this->assertTrue($update->isSuccess(), 'Update selhal: ' . ($update->getErrorMessage() ?? 'validace'));

        $doc = $this->gateway->loadDocument($this->headId);
        $this->assertSame(RoundingModes::ON_CENT, (int) $doc['total_rounding_mode']);
        $this->assertSame($gross, (float) $doc['total_amount']);
        $this->assertSame(0.0, (float) $doc['total_rounding']);

        // Vypnutá automatika, hotovost, na haléře → varování, uložení projde (D7).
        $doc['payment_method'] = 0;
        $doc['total_rounding_auto'] = 0;
        $manual = $this->gateway->saveDocument($doc);
        $this->assertTrue($manual->isSuccess(), 'Update selhal: ' . ($manual->getErrorMessage() ?? 'validace'));
        $codes = array_column(
            array_map(static fn ($w) => $w->toArray(), $manual->getValidation()?->getWarnings() ?? []),
            'code',
        );
        $this->assertContains('cash_rounding_mismatch', $codes);

        $doc = $this->gateway->loadDocument($this->headId);
        $this->assertSame(0, (int) $doc['total_rounding_auto']);
        $this->assertSame(RoundingModes::ON_CENT, (int) $doc['total_rounding_mode'], 'ruční mód zůstává');
        $this->assertSame($gross, (float) $doc['total_amount']);
    }
}
