<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\CashDocs\CashDocForm;

/**
 * Formulář pokladního dokladu: pokladna z řady (žádný tichý výběr první
 * řady), způsob úhrady jen Hotovost / Kartou, směr po vzniku řádků jen pro
 * čtení, recalculate nemaže systémovou pokladnu, hlavička anonymního dokladu.
 */
class CashDocFormTest extends TestCase
{
    private const DESK = ['id' => 7, 'code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => 'czk'];

    /** @var list<string> */
    private array $sqlLog = [];

    private function db(int $rowsCount = 0, array $seriesRows = [], array $registrations = []): DataSourceConnection
    {
        $this->sqlLog = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($rowsCount): ?array {
                $this->sqlLog[] = $sql;
                if (str_contains($sql, 'COUNT(*)')) {
                    return ['cnt' => $rowsCount];
                }
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return self::DESK;
                }
                if (str_contains($sql, 'base_persons_persons')) {
                    return ['full_name' => 'Partner s.r.o.'];
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($seriesRows, $registrations): array {
                if (str_contains($sql, 'docs_core_number_series')) {
                    return $seriesRows;
                }
                if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                    return $registrations;
                }
                return [];
            },
        );
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $items = [
            'docs.core.paymentMethods' => [
                '0' => ['name' => 'Hotovost', 'cashRounding' => true], '1' => ['name' => 'Převodem'], '2' => ['name' => 'Kartou'],
                '3' => ['name' => 'Dobírkou', 'cashRounding' => true], '4' => ['name' => 'Zápočtem'],
            ],
            'docs.core.cashDirections' => [
                '0' => ['name' => '—'], '1' => ['name' => 'Příjem'], '2' => ['name' => 'Výdej'],
            ],
            'docs.core.docTypes' => [
                'cash' => [
                    'trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk',
                    'total_rounding_auto' => true,
                ],
            ],
            'world.base.cashRounding' => ['czk' => ['step' => 1, 'countries' => ['cz']]],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    private function form(int $rowsCount = 0, array $seriesRows = [], array $registrations = []): TestableCashDocForm
    {
        $form = new TestableCashDocForm('docs_core_heads');
        $form->setConfig($this->config());
        $form->setDb($this->db($rowsCount, $seriesRows, $registrations));
        return $form;
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

    /** @return list<string> obsah html elementů basic tabu */
    private function htmlBlocks(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    if ($el->type === 'html') {
                        $out[] = (string) $el->content;
                    }
                }
            }
        }
        return $out;
    }

    public function testNewRecordWithoutSeriesShowsSeriesSelectAndDoesNotAutoPick(): void
    {
        $series = [['id' => 12, 'name' => 'Pokladní doklad — HP1', 'doc_type' => 'cash']];
        $def = $this->form(seriesRows: $series)->buildFormDefinition(['doc_type' => 'cash'], true);

        $el = $this->findElement($def, 'number_series');
        $this->assertNotNull($el);
        $this->assertFalse($el->hidden, 'bez řady je select Pokladna viditelný');
        $this->assertSame('Pokladna', $el->label);
        $this->assertSame([12], array_column($el->options, 'value'));

        foreach ($this->sqlLog as $sql) {
            $this->assertStringNotContainsString(
                'ORDER BY `id` ASC LIMIT 1',
                $sql,
                'vázaný typ nesmí potichu vybrat první řadu',
            );
        }
    }

    public function testNewRecordWithSeriesHidesSelectAndTakesCurrencyFromDesk(): void
    {
        $form = $this->form();
        $def = $form->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);
        $this->assertTrue($this->findElement($def, 'number_series')->hidden);

        $data = ['doc_type' => 'cash', 'number_series' => 12];
        $form->applyClientDefaultsPub($data, true);
        $this->assertSame(0, $data['payment_method'], 'default Hotovost');
        $this->assertSame('czk', $data['doc_currency']);
    }

    // ── applyNewRecordDefaults (#60) — HTTP cesta nového dokladu ─────────────

    public function testNewRecordHookSetsCashPaymentDeskCurrencyAndRegistrationWithoutBankAccount(): void
    {
        $form = $this->form(registrations: [['id' => 4, 'country' => 'cz', 'vat_id' => 'CZ123']]);
        // schéma defaulty (payment_method 1 Převodem, doc_currency czk) + prefill řady z vieweru;
        // měna schválně jiná než pokladny, aby bylo vidět, že vyhrává pokladna
        $data = ['doc_type' => 'cash', 'number_series' => 12, 'payment_method' => 1, 'doc_currency' => 'eur', 'vat_mode' => 1];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(0, $data['payment_method'], 'Převodem ze schématu → Hotovost');
        $this->assertSame('czk', $data['doc_currency'], 'měna pokladny z řady');
        $this->assertSame(4, $data['vat_registration'], 'pokladní doklad DPH má');
        $this->assertArrayNotHasKey('bank_account', $data, 'formulář pole nemá');
        $this->assertSame(date('Y-m-d'), $data['issue_date']);
        foreach ($this->sqlLog as $sql) {
            $this->assertStringNotContainsString('bank_accounts', $sql);
        }
    }

    public function testNewRecordHookKeepsCardPrefill(): void
    {
        $data = ['doc_type' => 'cash', 'number_series' => 12, 'payment_method' => 2];
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(2, $data['payment_method'], 'Kartou je povolené — explicitní prefill vyhrává');

        $data = ['doc_type' => 'cash', 'number_series' => 12, 'payment_method' => 3];
        $this->form()->applyNewRecordDefaults($data);
        $this->assertSame(0, $data['payment_method'], 'Dobírkou pokladní doklad nepovoluje → Hotovost');
    }

    public function testNewRecordHookWithoutSeriesKeepsSchemaCurrency(): void
    {
        $data = ['doc_type' => 'cash', 'payment_method' => 1, 'doc_currency' => 'czk'];
        $this->form()->applyNewRecordDefaults($data);

        $this->assertSame(0, $data['payment_method']);
        $this->assertSame('czk', $data['doc_currency'], 'bez řady není pokladna — měna beze změny');
    }

    public function testPaymentMethodOptionsAreCashAndCardOnly(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);

        $el = $this->findElement($def, 'payment_method', 'settings');
        $this->assertSame([0, 2], array_column($el->options, 'value'));
        $this->assertSame('reload', $el->triggers);
    }

    /** #73 D2: automatika jen u příjmu — výdej zaškrtávátko nemá; recalculate směru i úhrady dopočítá mód. */
    public function testAutomaticRoundingOnlyForReceipts(): void
    {
        $form = $this->form();
        $ds = $this->createMock(DataSourceConfig::class);
        $ds->method('getCountry')->willReturn('cz');
        $form->setDsConfig($ds);
        $base = [
            'doc_type' => 'cash', 'number_series' => 12, 'vat_mode' => 1, 'payment_method' => 0,
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'total_rounding_auto' => 1, 'total_rounding_mode' => 0,
        ];

        $def = $form->buildFormDefinition(['cash_dir' => 2] + $base, true);
        $this->assertTrue($this->findElement($def, 'total_rounding_auto', 'settings')->hidden, 'výdej automatiku nemá');
        $this->assertFalse($this->findElement($def, 'total_rounding_mode', 'settings')->readOnly);

        $def = $form->buildFormDefinition(['cash_dir' => 1] + $base, true);
        $checkbox = $this->findElement($def, 'total_rounding_auto', 'settings');
        $this->assertFalse($checkbox->hidden, 'příjem automatiku má');
        $this->assertSame('reload', $checkbox->triggers);
        $this->assertTrue($this->findElement($def, 'total_rounding_mode', 'settings')->readOnly);

        $def = $form->buildFormDefinition(['cash_dir' => 1, 'total_rounding_auto' => 0] + $base, true);
        $this->assertFalse($this->findElement($def, 'total_rounding_mode', 'settings')->readOnly, 'vypnutá → jde upravit');

        // Směr a úhrada jdou přes CashDeskFormBase::recalculate — mód se dopočítá i tam.
        $result = $form->recalculate('cash_dir', ['cash_dir' => 1] + $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'příjem hotově → na koruny');
        $result = $form->recalculate('payment_method', ['cash_dir' => 1, 'payment_method' => 2] + $base);
        $this->assertSame(0, $result->data['total_rounding_mode'], 'kartou → na haléře');
        $result = $form->recalculate('cash_dir', ['cash_dir' => 2, 'total_rounding_mode' => 1] + $base);
        $this->assertSame(1, $result->data['total_rounding_mode'], 'výdej mód nemění');
    }

    /** Issue #67: tab Nastavení za Přílohami, přesunutá pole, ev. číslo a místo plnění v hlavičce. */
    public function testSettingsTabIsLastAndHoldsRarelyChangedFields(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12, 'vat_mode' => 1], true);

        $tabIds = array_map(static fn ($t) => $t->id, $def->tabs);
        $this->assertSame('settings', end($tabIds), 'Nastavení je poslední tab');
        $this->assertContains('attachments', $tabIds);
        $this->assertLessThan(array_search('settings', $tabIds, true), array_search('attachments', $tabIds, true));

        foreach (['payment_method', 'vat_registration', 'total_rounding_mode', 'vat_rounding_mode'] as $col) {
            $this->assertNull($this->findElement($def, $col, 'basic'), "$col už není v hlavičce");
            $this->assertNotNull($this->findElement($def, $col, 'settings'), "$col je v Nastavení");
        }

        // #75 C2: metoda výpočtu DPH nemá u pokladního dokladu smysl —
        // platí norma (daň ze součtu řádků v sazbě), pole je skryté.
        $this->assertNull($this->findElement($def, 'vat_calc_source'), 'vat_calc_source není ve formuláři');

        $this->assertNotNull($this->findElement($def, 'partner_doc_number'), 'ev. číslo dokladu v hlavičce');
        $place = $this->findElement($def, 'vat_place');
        $this->assertNotNull($place, 'místo plnění v hlavičce');
        $this->assertSame('reload', $place->triggers);
        $this->assertFalse($place->hidden);
    }

    /** Issue #67: DPPD jen na příjmu; na výdeji a bez DPH skryté. */
    public function testDppdOnlyOnReceipt(): void
    {
        $base = ['doc_type' => 'cash', 'number_series' => 12, 'vat_mode' => 1];

        $def = $this->form()->buildFormDefinition($base + ['cash_dir' => 1], true);
        $this->assertFalse($this->findElement($def, 'vat_dppd')->hidden, 'příjem → DPPD viditelné');
        $this->assertFalse($this->findElement($def, 'vat_duzp')->hidden);

        $def = $this->form()->buildFormDefinition($base + ['cash_dir' => 2], true);
        $this->assertTrue($this->findElement($def, 'vat_dppd')->hidden, 'výdej → DPPD skryté');
        $this->assertFalse($this->findElement($def, 'vat_duzp')->hidden, 'DUZP zůstává');

        $def = $this->form()->buildFormDefinition($base, true);
        $this->assertTrue($this->findElement($def, 'vat_dppd')->hidden, 'bez směru → DPPD skryté');

        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12, 'vat_mode' => 0, 'cash_dir' => 1], true);
        $this->assertTrue($this->findElement($def, 'vat_dppd')->hidden, 'bez DPH → DPPD skryté');
        $this->assertTrue($this->findElement($def, 'vat_place')->hidden, 'bez DPH → místo plnění skryté');
    }

    public function testCashDirIsRequiredReloadAndLockedOnceRowsExist(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);
        $el = $this->findElement($def, 'cash_dir');
        $this->assertSame([1, 2], array_column($el->options, 'value'), 'směr 0 se nenabízí');
        $this->assertTrue($el->required);
        $this->assertSame('reload', $el->triggers);
        $this->assertFalse($el->readOnly);

        $def = $this->form(rowsCount: 2)->buildFormDefinition(
            ['id' => 5, 'doc_type' => 'cash', 'cash_desk' => 7, 'cash_dir' => 1],
            false,
        );
        $el = $this->findElement($def, 'cash_dir');
        $this->assertTrue($el->readOnly);
        $this->assertNotNull($el->hint);
    }

    public function testDocCurrencyIsReadOnlyAndCashDeskSectionRendered(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'cash', 'number_series' => 12], true);

        $this->assertTrue($this->findElement($def, 'doc_currency')->readOnly);
        $this->assertNull($this->findElement($def, 'cash_desk'), 'pokladna se nezadává');
        $this->assertStringContainsString('HP1 — Hlavní pokladna · CZK', implode("\n", $this->htmlBlocks($def)));
    }

    public function testRecalculatePaymentMethodKeepsCashDesk(): void
    {
        $data = [
            'id' => 5, 'doc_type' => 'cash', 'cash_desk' => 7, 'cash_dir' => 1,
            'payment_method' => 2, 'doc_currency' => 'czk',
        ];
        $result = $this->form()->recalculate('payment_method', $data);

        $this->assertSame(7, $result->data['cash_desk'], 'base by pokladnu u karty vynulovala');
    }

    public function testHeaderInfoForAnonymousDocumentUsesDirection(): void
    {
        $form = $this->form();

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'doc_number' => '31HP12600001']);
        $this->assertNotNull($info);
        $this->assertSame('Příjmový pokladní doklad', $info->title);
        $this->assertSame('Příjmový pokladní doklad', $info->info[0]['value']);
        $this->assertSame('31HP12600001', $info->info[1]['value']);
        $this->assertSame('wallet', $info->icon);

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 2]);
        $this->assertSame('Výdajový pokladní doklad', $info->title);
    }

    public function testHeaderInfoPartnerFromSnapshotByDirection(): void
    {
        $form = $this->form();
        $snapshots = [
            'customer_snapshot' => json_encode(['name' => 'Odběratel a.s.']),
            'supplier_snapshot' => json_encode(['name' => 'Dodavatel s.r.o.']),
        ];

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'partner' => 5] + $snapshots);
        $this->assertSame('Odběratel a.s.', $info->title, 'příjem → partner je odběratel');

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 2, 'partner' => 5] + $snapshots);
        $this->assertSame('Dodavatel s.r.o.', $info->title, 'výdej → partner je dodavatel');

        $info = $form->buildHeaderInfo(['doc_type' => 'cash', 'cash_dir' => 1, 'partner' => 5]);
        $this->assertSame('Partner s.r.o.', $info->title, 'bez snapshotu živé jméno');
    }
}

class TestableCashDocForm extends CashDocForm
{
    public function applyClientDefaultsPub(array &$data, bool $isNew): void
    {
        $this->applyClientDefaults($data, $isNew);
    }
}
