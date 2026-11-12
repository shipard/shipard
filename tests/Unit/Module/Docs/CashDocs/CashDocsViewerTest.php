<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\CashDocs\CashDocsViewer;

/**
 * Řádek vieweru Pokladní doklady: směr (výdej tlumeně), datum, způsob
 * úhrady; bez splatnosti a bez názvu typu; částka kladná.
 */
class CashDocsViewerTest extends TestCase
{
    private function viewer(): CashDocsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docStates' => [
                    '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1],
                    '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3],
                    '90' => ['stateName' => 'Smazaný', 'stateStyle' => 'trash', 'mainState' => 9],
                ],
                'docs.core.docTypes'       => ['cash' => ['name' => 'Pokladní doklad', 'trade_dir' => 0]],
                'docs.core.cashDirections' => ['0' => ['name' => '—'], '1' => ['name' => 'Příjem'], '2' => ['name' => 'Výdej']],
                'docs.core.paymentMethods' => ['0' => ['name' => 'Hotovost'], '2' => ['name' => 'Kartou']],
                default                    => null,
            },
        );
        $viewer = new CashDocsViewer($db, 'docs_core_heads');
        $viewer->setConfig($config);
        return $viewer;
    }

    /** @return array<string, mixed> */
    private function rowData(array $overrides = []): array
    {
        return array_merge([
            'id'             => 3,
            'doc_type'       => 'cash',
            'doc_number'     => '31HP12600001',
            'doc_text'       => 'Prodej za hotové',
            'docState'       => 40,
            'docStateMain'   => 3,
            'issue_date'     => '2026-06-10',
            'due_date'       => '2026-06-10',
            'total_amount'   => '1210.00',
            'doc_currency'   => 'czk',
            'cash_dir'       => 1,
            'payment_method' => 0,
            'partner_name'   => null,
        ], $overrides);
    }

    public function testReceiptRowShowsDirectionDateAndPaymentMethod(): void
    {
        $row = $this->viewer()->renderRow($this->rowData());

        $this->assertSame('Prodej za hotové', $row['t1']);
        $this->assertSame('31HP12600001', $row['i1']);
        $this->assertSame([
            ['text' => 'Příjem'],
            ['text' => '2026-06-10'],
            ['text' => 'Hotovost', 'class' => 'muted'],
        ], $row['t2']);
        $this->assertSame(['text' => '1 210,00 CZK', 'class' => 'amount'], $row['i2']);
    }

    public function testDisbursementDirectionIsMuted(): void
    {
        $row = $this->viewer()->renderRow($this->rowData(['cash_dir' => 2, 'payment_method' => 2]));

        $this->assertSame(['text' => 'Výdej', 'class' => 'muted'], $row['t2'][0]);
        $this->assertSame(['text' => 'Kartou', 'class' => 'muted'], $row['t2'][2]);
        $this->assertSame('1 210,00 CZK', $row['i2']['text'], 'částka výdeje zůstává kladná');
    }

    public function testNonStandardStateAppendsStateTag(): void
    {
        $row = $this->viewer()->renderRow($this->rowData(['docState' => 90]));

        $this->assertSame(['text' => 'Smazaný', 'class' => 'muted'], end($row['t2']));
    }
}
