<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\AccountingStatusContent;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Stav zaúčtování jako bloky detailu (#137 D30): štítek per stav s názvem
 * z konfigurace, hláška s hlášeními jen u stavu ≥ 1 (danger u chyby,
 * warning u varování zaúčtovaného dokladu), přípona řádku z katalogu
 * popisků, dekódování sloupce `accounting_messages`.
 */
class AccountingStatusContentTest extends TestCase
{
    private const STATES = [
        '0' => ['name' => 'Neúčtováno'],
        '1' => ['name' => 'Zaúčtováno'],
        '2' => ['name' => 'Chyba účtování'],
    ];

    private function config(bool $withRowReference = true): ConfigRuntime
    {
        $labels = ['tabs' => ['accounting' => ['name' => 'Zaúčtování']]];
        if ($withRowReference) {
            $labels['texts'] = ['rowReference' => ['name' => '(řádek {n})']];
        }
        return ConfigRuntimeFactory::fromItems([
            AccountingStatusContent::STATES_CFG_ITEM => self::STATES,
            AccountingStatusContent::LABELS_CFG_ITEM => $labels,
        ]);
    }

    public function testBadgeStyleAndNamePerState(): void
    {
        $expected = [
            0 => ['Neúčtováno', 'warning'],
            1 => ['Zaúčtováno', 'success'],
            2 => ['Chyba účtování', 'danger'],
        ];
        foreach ($expected as $state => [$label, $style]) {
            $this->assertSame(
                [['type' => 'badges', 'items' => [['label' => $label, 'style' => $style]]]],
                AccountingStatusContent::blocks($this->config(), $state, null),
                "stav {$state}",
            );
        }
    }

    public function testWithoutConfigUsesEnglishFallbacks(): void
    {
        $blocks = AccountingStatusContent::blocks(
            null,
            2,
            json_encode([['code' => 'account_not_found', 'message' => 'Account not found', 'rowId' => 3]]),
        );

        $this->assertSame('Accounting error', $blocks[0]['items'][0]['label']);
        $this->assertSame(['Account not found (row 3)'], $blocks[1]['items']);
    }

    public function testMessagesIgnoredWhenNotAccounted(): void
    {
        $blocks = AccountingStatusContent::blocks(
            $this->config(),
            0,
            json_encode([['code' => 'stale', 'message' => 'Z minula', 'rowId' => null]]),
        );

        $this->assertSame(['badges'], array_column($blocks, 'type'));
    }

    public function testErrorStateRendersDangerNoticeWithRowReference(): void
    {
        $messages = [
            ['code' => 'account_not_found', 'message' => 'Účet 504??? nenalezen', 'rowId' => 12],
            ['code' => 'unbalanced', 'message' => 'MD <> DAL', 'rowId' => null],
        ];
        $blocks = AccountingStatusContent::blocks($this->config(), 2, json_encode($messages));

        $this->assertSame(['badges', 'notice'], array_column($blocks, 'type'));
        $this->assertSame('danger', $blocks[1]['tone']);
        // Čistý text — bez escapování; bez rowId bez přípony.
        $this->assertSame(['Účet 504??? nenalezen (řádek 12)', 'MD <> DAL'], $blocks[1]['items']);
    }

    public function testWarningsOnAccountedRecordRenderWarningNotice(): void
    {
        $blocks = AccountingStatusContent::blocks($this->config(), 1, [
            ['code' => 'contributor_failed', 'message' => 'Selhal contributor', 'rowId' => null, 'level' => 'warning'],
        ]);

        $this->assertSame('warning', $blocks[1]['tone']);
        $this->assertSame(['Selhal contributor'], $blocks[1]['items']);
    }

    public function testRowReferenceFallsBackToEnglishWithoutCatalogText(): void
    {
        $blocks = AccountingStatusContent::blocks(
            $this->config(withRowReference: false),
            2,
            [['code' => 'x', 'message' => 'Chyba', 'rowId' => 7]],
        );

        $this->assertSame(['Chyba (row 7)'], $blocks[1]['items']);
    }

    public function testDecodeMessagesAcceptsJsonOrArrayAndDropsGarbage(): void
    {
        $list = [['code' => 'a', 'message' => 'A', 'rowId' => null]];

        $this->assertSame($list, AccountingStatusContent::decodeMessages(json_encode($list)));
        $this->assertSame($list, AccountingStatusContent::decodeMessages($list));
        $this->assertSame([], AccountingStatusContent::decodeMessages(null));
        $this->assertSame([], AccountingStatusContent::decodeMessages('   '));
        $this->assertSame([], AccountingStatusContent::decodeMessages('{not json'));
        $this->assertSame([], AccountingStatusContent::decodeMessages(json_encode(['x', 1])));
        $this->assertSame([['message' => 'B']], AccountingStatusContent::decodeMessages(['skip', ['message' => 'B']]));
    }
}
