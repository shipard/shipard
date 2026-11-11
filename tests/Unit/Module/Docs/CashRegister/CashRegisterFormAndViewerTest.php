<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashRegister;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\CashRegister\CashRegisterForm;
use Shipard\Module\Docs\CashRegister\CashRegisterViewer;

/**
 * Formulář a viewer prodejky: úhrada Hotovost / Převodem / Kartou, měna
 * pokladny jen pro čtení, žádný směr; tab Nastavení s DPH a zaokrouhlením
 * (#68); hlavička s odběratelem ze snapshotu; řádek vieweru s datem a
 * způsobem úhrady.
 */
class CashRegisterFormAndViewerTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.paymentMethods' => [
                '0' => ['name' => 'Hotovost', 'cashRounding' => true], '1' => ['name' => 'Převodem'], '2' => ['name' => 'Kartou'],
            ],
            'world.base.cashRounding' => ['czk' => ['step' => 1, 'countries' => ['cz']]],
            'docs.core.docTypes' => [
                'cashreg' => ['name' => 'Prodejka', 'trade_dir' => 1, 'series_binding' => 'cash_desk', 'total_rounding_auto' => true],
            ],
            'docs.core.docStates' => [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3],
            ],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    private function db(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn (string $sql, mixed ...$params): ?array =>
                str_contains($sql, 'economy_codebooks_cash_desks')
                    ? ['id' => 7, 'code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => 'eur']
                    : null,
        );
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    /** @param string|null $tabId null = hledat ve všech tabech */
    private function findElement(FormDefinition $def, string $column, ?string $tabId = 'basic'): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tabId !== null && $tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    public function testHeaderHasNoDirectionAndRestrictedPaymentMethods(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db());

        $def = $form->buildFormDefinition(['doc_type' => 'cashreg', 'number_series' => 12], true);

        $this->assertSame('Nová prodejka', $def->titleNew);
        $this->assertNull($this->findElement($def, 'cash_dir'), 'prodejka směr nemá');
        $this->assertNull($this->findElement($def, 'cash_desk'));
        $this->assertSame([0, 1, 2], array_column($this->findElement($def, 'payment_method')->options, 'value'), 'hotově, převodem (úhrada = pohledávka), kartou');
        $this->assertTrue($this->findElement($def, 'doc_currency')->readOnly);
        $this->assertNotNull($this->findElement($def, 'partner'));
        $this->assertFalse($this->findElement($def, 'partner')->required);
    }

    /** Issue #68: tab Nastavení za Přílohami s režimem DPH, místem plnění, registrací a zaokrouhlením. */
    public function testSettingsTabIsLastAndHoldsVatAndRounding(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db());

        $def = $form->buildFormDefinition(['doc_type' => 'cashreg', 'number_series' => 12, 'vat_mode' => 1], true);

        $tabIds = array_map(static fn ($t) => $t->id, $def->tabs);
        $this->assertSame('settings', end($tabIds), 'Nastavení je poslední tab');
        $this->assertContains('attachments', $tabIds);
        $this->assertLessThan(array_search('settings', $tabIds, true), array_search('attachments', $tabIds, true));

        foreach (['vat_mode', 'vat_place', 'vat_registration', 'total_rounding_mode', 'vat_rounding_mode'] as $col) {
            $this->assertNull($this->findElement($def, $col, 'basic'), "$col není v hlavičce");
            $this->assertNotNull($this->findElement($def, $col, 'settings'), "$col je v Nastavení");
        }
        $this->assertNotNull($this->findElement($def, 'payment_method'), 'způsob úhrady zůstává v hlavičce');
        $this->assertSame('reload', $this->findElement($def, 'vat_mode', 'settings')->triggers);
        $this->assertSame('reload', $this->findElement($def, 'vat_place', 'settings')->triggers);
        $this->assertTrue($this->findElement($def, 'vat_registration', 'settings')->required, 's DPH je registrace povinná');
        $this->assertFalse($this->findElement($def, 'vat_place', 'settings')->hidden);

        // Bez DPH: režim DPH zůstává viditelný (jinak by se nedal zapnout), podřízená pole skrytá.
        $def = $form->buildFormDefinition(['doc_type' => 'cashreg', 'number_series' => 12, 'vat_mode' => 0], true);
        $this->assertFalse($this->findElement($def, 'vat_mode', 'settings')->hidden);
        foreach (['vat_place', 'vat_registration', 'vat_rounding_mode'] as $col) {
            $this->assertTrue($this->findElement($def, $col, 'settings')->hidden, "$col bez DPH skryté");
        }
        $this->assertFalse($this->findElement($def, 'total_rounding_mode', 'settings')->hidden);
        $this->assertTrue($this->findElement($def, 'vat_duzp')->hidden, 'DUZP v hlavičce bez DPH skryté');
    }

    /**
     * HTTP cesta nové prodejky: schéma default Převodem → Hotovost, explicitní
     * prefill povolené úhrady prodejky (Kartou, Dobírkou, Bránou) přežije,
     * nepovolená (Zápočtem) → Hotovost. Dřív rozhodovala konstanta pokladního
     * dokladu a Dobírkou i Bránou padaly na Hotovost.
     */
    public function testNewRecordHookKeepsPrefillOfPaymentMethodsAllowedOnRetailSale(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db());

        $expected = [1 => 0, 2 => 2, 3 => 3, 5 => 5, 4 => 0];
        foreach ($expected as $prefill => $result) {
            $data = ['doc_type' => 'cashreg', 'number_series' => 12, 'payment_method' => $prefill, 'vat_mode' => 1];
            $form->applyNewRecordDefaults($data);
            $this->assertSame($result, $data['payment_method'], "prefill {$prefill}");
        }

        $data = ['doc_type' => 'cashreg', 'number_series' => 12, 'vat_mode' => 1];
        $form->applyNewRecordDefaults($data);
        $this->assertSame(0, $data['payment_method'], 'bez hodnoty → Hotovost');
    }

    /** #73 D2, D4: prodejka má automatiku — při zapnuté je mód jen ke čtení a recalculate ho drží podle úhrady. */
    public function testAutomaticRoundingFollowsPaymentMethod(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db());
        $ds = $this->createMock(DataSourceConfig::class);
        $ds->method('getCountry')->willReturn('cz');
        $form->setDsConfig($ds);
        $base = [
            'doc_type' => 'cashreg', 'number_series' => 12, 'vat_mode' => 1, 'payment_method' => 0,
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'total_rounding_auto' => 1, 'total_rounding_mode' => 0,
        ];

        $def = $form->buildFormDefinition($base, true);
        $checkbox = $this->findElement($def, 'total_rounding_auto', 'settings');
        $this->assertNotNull($checkbox, 'zaškrtávátko v Nastavení');
        $this->assertFalse($checkbox->hidden);
        $this->assertSame('reload', $checkbox->triggers);
        $this->assertTrue($this->findElement($def, 'total_rounding_mode', 'settings')->readOnly);
        $this->assertNull($this->findElement($def, 'total_rounding_auto'), 'v hlavičce není');

        $result = $form->recalculate('payment_method', $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'hotově → na koruny');
        $result = $form->recalculate('payment_method', ['payment_method' => 2, 'total_rounding_mode' => 1] + $base);
        $this->assertSame(0, $result->data['total_rounding_mode'], 'kartou → na haléře');
        $result = $form->recalculate('payment_method', ['total_rounding_auto' => 0, 'total_rounding_mode' => 1] + $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'vypnutá automatika mód nechává');
        $this->assertFalse($this->findElement($result->formDefinition, 'total_rounding_mode', 'settings')->readOnly);
    }

    public function testHeaderInfoUsesCustomerSnapshot(): void
    {
        $form = new CashRegisterForm('docs_core_heads');
        $form->setConfig($this->config());

        $info = $form->buildHeaderInfo([
            'doc_type'          => 'cashreg',
            'customer_snapshot' => json_encode(['name' => 'Odběratel a.s.']),
            'supplier_snapshot' => json_encode(['name' => 'My s.r.o.']),
        ]);

        $this->assertSame('Odběratel a.s.', $info->title);
        $this->assertSame('Prodejka', $info->info[0]['value']);
        $this->assertSame('cash-register', $info->icon);
    }

    public function testViewerRowShowsDateAndPaymentMethodOnly(): void
    {
        $viewer = new CashRegisterViewer($this->db(), 'docs_core_heads');
        $viewer->setConfig($this->config());

        $row = $viewer->renderRow([
            'id' => 4, 'doc_type' => 'cashreg', 'doc_number' => '14HP12600001',
            'doc_text' => 'Prodej', 'docState' => 40, 'docStateMain' => 3,
            'issue_date' => '2026-06-10', 'due_date' => '2026-06-10',
            'total_amount' => '-1210.00', 'doc_currency' => 'czk',
            'cash_dir' => 0, 'payment_method' => 2, 'partner_name' => null,
        ]);

        $this->assertSame([['text' => '10. 6. 2026'], ['text' => 'Kartou', 'class' => 'muted']], $row['t2']);
        $this->assertSame('-1 210,00 CZK', $row['i2']['text'], 'vratka je záporná');
    }
}
