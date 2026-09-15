<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\PartnerBalanceResolver;

/**
 * Odvození osoby pro saldokonto (#72 D2): pořadí kroků terminál → dopravce
 * → partner, ruční plátce, přepis u karty, default terminálu pokladny,
 * respekt importu.
 */
class PartnerBalanceResolverTest extends TestCase
{
    private const PARTNER = 5;

    /** Terminály: id 9 = terminál pokladny 7 (protistrana 33, default), id 10 = terminál pokladny 8 (protistrana 34), id 12 = brána (55, default). */
    private const TERMINALS = [
        9  => ['id' => 9,  'kind' => 0, 'cash_desk' => 7,    'partner' => 33],
        10 => ['id' => 10, 'kind' => 0, 'cash_desk' => 8,    'partner' => 34],
        12 => ['id' => 12, 'kind' => 1, 'cash_desk' => null, 'partner' => 55],
        13 => ['id' => 13, 'kind' => 0, 'cash_desk' => 7,    'partner' => null],
    ];

    /** @var list<string> SQL dotazů poslaných do mocku (kontrola default dotazu). */
    private array $sqlLog = [];

    /**
     * @param array<int, array<string, mixed>> $defaults default per (kind, cash_desk|null) → terminál
     */
    private function db(array $defaults = [], ?array $transport = null): Connection
    {
        $this->sqlLog = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($defaults, $transport): ?Row {
                $this->sqlLog[] = $sql;
                if (str_contains($sql, 'economy_codebooks_transports')) {
                    return $transport !== null && (int) $params[0] === $transport['id'] ? new Row($transport) : null;
                }
                if (str_contains($sql, 'economy_codebooks_payment_terminals')) {
                    if (str_contains($sql, '[is_default] = 1')) {
                        $key = (int) $params[0] . ':' . (isset($params[1]) ? (int) $params[1] : '');
                        return isset($defaults[$key]) ? new Row(self::TERMINALS[$defaults[$key]]) : null;
                    }
                    $id = (int) $params[0];
                    return isset(self::TERMINALS[$id]) ? new Row(self::TERMINALS[$id]) : null;
                }
                return null;
            },
        );
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invno'   => ['trade_dir' => 1],
            'invni'   => ['trade_dir' => 2],
            'cmnbkp'  => ['trade_dir' => 0],
            'cash'    => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            'cashreg' => ['trade_dir' => 1, 'series_binding' => 'cash_desk'],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    private function resolver(array $defaults = [], ?array $transport = null): PartnerBalanceResolver
    {
        return new PartnerBalanceResolver($this->db($defaults, $transport), $this->config());
    }

    // --- krok 3: partner / ruční -------------------------------------------

    public function testTransferFollowsPartnerUnlessManual(): void
    {
        $data = ['doc_type' => 'invno', 'payment_method' => 1, 'partner' => self::PARTNER];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver()->resolve($data));
        $this->assertSame(self::PARTNER, $data['partner_balance']);

        $data = ['doc_type' => 'invno', 'payment_method' => 1, 'partner' => self::PARTNER,
            'partner_balance' => 77, 'partner_balance_manual' => 1];
        $this->assertSame(PartnerBalanceResolver::SOURCE_MANUAL, $this->resolver()->resolve($data));
        $this->assertSame(77, $data['partner_balance'], 'ruční plátce zůstává');

        $data = ['doc_type' => 'invno', 'payment_method' => 1, 'partner' => null];
        $this->resolver()->resolve($data);
        $this->assertNull($data['partner_balance'], 'bez partnera bez plátce');
    }

    public function testPurchaseInvoiceIgnoresTerminalAndTransport(): void
    {
        // FP kartou: náš terminál nehraje roli — jen krok 3.
        $data = ['doc_type' => 'invni', 'payment_method' => 2, 'partner' => self::PARTNER, 'payment_terminal' => 9];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver(['0:' => 9])->resolve($data));
        $this->assertSame(self::PARTNER, $data['partner_balance']);
        $this->assertSame(9, $data['payment_terminal'], 'terminál se u nákupu nemění');
        $this->assertSame([], $this->sqlLog, 'nákupní směr do DB nesahá');

        $data = ['doc_type' => 'invni', 'payment_method' => 3, 'partner' => self::PARTNER, 'transport' => 4];
        $this->resolver([], ['id' => 4, 'partner' => 44])->resolve($data);
        $this->assertSame(self::PARTNER, $data['partner_balance']);
    }

    public function testCashDisbursementFollowsPartner(): void
    {
        $data = ['doc_type' => 'cash', 'cash_dir' => 2, 'payment_method' => 2, 'partner' => self::PARTNER, 'cash_desk' => 7];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver(['0:7' => 9])->resolve($data));
        $this->assertSame(self::PARTNER, $data['partner_balance']);
    }

    // --- krok 1: karta / brána -----------------------------------------------

    public function testCardOnCashDeskDocumentUsesDefaultTerminalOfDesk(): void
    {
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'partner' => self::PARTNER, 'cash_desk' => 7];
        $this->assertSame(PartnerBalanceResolver::SOURCE_TERMINAL, $this->resolver(['0:7' => 9])->resolve($data));
        $this->assertSame(33, $data['partner_balance']);
        $this->assertSame(9, $data['payment_terminal']);
    }

    public function testCardOverridesManualPayer(): void
    {
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'partner' => self::PARTNER, 'cash_desk' => 7,
            'partner_balance' => 77, 'partner_balance_manual' => 1];
        $this->resolver(['0:7' => 9])->resolve($data);
        $this->assertSame(33, $data['partner_balance'], 'terminál má přednost před ruční hodnotou');
        $this->assertSame(1, $data['partner_balance_manual'], 'flag se nemění');
    }

    public function testCardKeepsMatchingExplicitTerminal(): void
    {
        $data = ['doc_type' => 'cash', 'cash_dir' => 1, 'payment_method' => 2, 'cash_desk' => 7, 'payment_terminal' => 13];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver(['0:7' => 9])->resolve($data));
        $this->assertSame(13, $data['payment_terminal'], 'terminál pokladny odpovídá — default se nehledá');
        $this->assertNull($data['partner_balance'], 'terminál bez protistrany → krok 3 (bez partnera nic)');
    }

    public function testCardReplacesTerminalOfOtherDeskByDefault(): void
    {
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'partner' => self::PARTNER, 'cash_desk' => 7, 'payment_terminal' => 10];
        $this->resolver(['0:7' => 9])->resolve($data);
        $this->assertSame(9, $data['payment_terminal'], 'terminál cizí pokladny → default pokladny hlavičky');
        $this->assertSame(33, $data['partner_balance']);

        // Bez defaultu: cizí terminál se vyprázdní, plátce = partner.
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'partner' => self::PARTNER, 'cash_desk' => 7, 'payment_terminal' => 10];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver()->resolve($data));
        $this->assertNull($data['payment_terminal']);
        $this->assertSame(self::PARTNER, $data['partner_balance']);
    }

    public function testCardOnInvoiceWithoutCashDeskAcceptsAnyTerminal(): void
    {
        // FV kartou: pokladna není → explicitní terminál kterékoli pokladny platí.
        $data = ['doc_type' => 'invno', 'payment_method' => 2, 'partner' => self::PARTNER, 'payment_terminal' => 10];
        $this->assertSame(PartnerBalanceResolver::SOURCE_TERMINAL, $this->resolver()->resolve($data));
        $this->assertSame(34, $data['partner_balance']);

        // Bez terminálu: default mezi všemi terminály (dotaz bez cash_desk).
        $data = ['doc_type' => 'invno', 'payment_method' => 2, 'partner' => self::PARTNER];
        $this->resolver(['0:' => 9])->resolve($data);
        $this->assertSame(9, $data['payment_terminal']);
        $this->assertSame(33, $data['partner_balance']);
        $defaultSql = array_values(array_filter($this->sqlLog, fn(string $q) => str_contains($q, '[is_default] = 1')));
        $this->assertStringNotContainsString('[cash_desk] = %i', $defaultSql[0]);

        // DS bez terminálů: plátce = partner, terminál prázdný.
        $data = ['doc_type' => 'invno', 'payment_method' => 2, 'partner' => self::PARTNER];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver()->resolve($data));
        $this->assertNull($data['payment_terminal']);
        $this->assertSame(self::PARTNER, $data['partner_balance']);
    }

    public function testGatewayUsesDefaultGatewayAndRejectsTerminalKind(): void
    {
        $data = ['doc_type' => 'invno', 'payment_method' => 5, 'partner' => self::PARTNER];
        $this->assertSame(PartnerBalanceResolver::SOURCE_TERMINAL, $this->resolver(['1:' => 12])->resolve($data));
        $this->assertSame(12, $data['payment_terminal']);
        $this->assertSame(55, $data['partner_balance']);

        // Explicitní brána platí i s pokladnou hlavičky (brána pokladnu nemá).
        $data = ['doc_type' => 'cashreg', 'payment_method' => 5, 'cash_desk' => 7, 'payment_terminal' => 12];
        $this->resolver()->resolve($data);
        $this->assertSame(12, $data['payment_terminal']);
        $this->assertSame(55, $data['partner_balance']);

        // Terminál (kind 0) místo brány neodpovídá → default brána; bez ní prázdno.
        $data = ['doc_type' => 'invno', 'payment_method' => 5, 'partner' => self::PARTNER, 'payment_terminal' => 9];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver()->resolve($data));
        $this->assertNull($data['payment_terminal']);
        $this->assertSame(self::PARTNER, $data['partner_balance']);
    }

    // --- krok 2: dobírka ------------------------------------------------------

    public function testCodUsesCarrierOnlyWithCounterparty(): void
    {
        $data = ['doc_type' => 'invno', 'payment_method' => 3, 'partner' => self::PARTNER, 'transport' => 4];
        $this->assertSame(PartnerBalanceResolver::SOURCE_TRANSPORT, $this->resolver([], ['id' => 4, 'partner' => 44])->resolve($data));
        $this->assertSame(44, $data['partner_balance']);

        // Vlastní doprava (bez protistrany) → partner.
        $data = ['doc_type' => 'invno', 'payment_method' => 3, 'partner' => self::PARTNER, 'transport' => 4];
        $this->assertSame(PartnerBalanceResolver::SOURCE_PARTNER, $this->resolver([], ['id' => 4, 'partner' => null])->resolve($data));
        $this->assertSame(self::PARTNER, $data['partner_balance']);

        // Dobírka bez dopravy, ruční plátce zůstává.
        $data = ['doc_type' => 'invno', 'payment_method' => 3, 'partner' => self::PARTNER,
            'partner_balance' => 77, 'partner_balance_manual' => 1];
        $this->assertSame(PartnerBalanceResolver::SOURCE_MANUAL, $this->resolver()->resolve($data));
        $this->assertSame(77, $data['partner_balance']);
    }

    // --- import ----------------------------------------------------------------

    public function testImportRespectsExplicitManualPayer(): void
    {
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'cash_desk' => 7, 'partner' => self::PARTNER,
            'partner_balance' => 77, 'partner_balance_manual' => 1];
        $this->assertSame(PartnerBalanceResolver::SOURCE_IMPORT, $this->resolver(['0:7' => 9])->resolve($data, importMode: true));
        $this->assertSame(77, $data['partner_balance'], 'import: terminál ruční hodnotu nepřepíše');
        $this->assertArrayNotHasKey('payment_terminal', $data);

        // Import bez ručního plátce se odvozuje jako běžný doklad.
        $data = ['doc_type' => 'cashreg', 'payment_method' => 2, 'cash_desk' => 7, 'partner' => self::PARTNER];
        $this->resolver(['0:7' => 9])->resolve($data, importMode: true);
        $this->assertSame(33, $data['partner_balance']);
    }

    // --- helpers ---------------------------------------------------------------

    public function testStaticPredicates(): void
    {
        $config = $this->config();
        $this->assertTrue(PartnerBalanceResolver::isSalesDirection(['doc_type' => 'invno'], $config));
        $this->assertTrue(PartnerBalanceResolver::isSalesDirection(['doc_type' => 'cash', 'cash_dir' => 1], $config));
        $this->assertFalse(PartnerBalanceResolver::isSalesDirection(['doc_type' => 'cash', 'cash_dir' => 2], $config));
        $this->assertFalse(PartnerBalanceResolver::isSalesDirection(['doc_type' => 'invni'], $config));
        $this->assertFalse(PartnerBalanceResolver::isSalesDirection([], $config));

        $this->assertTrue(PartnerBalanceResolver::usesTerminal(['doc_type' => 'invno', 'payment_method' => 2], $config));
        $this->assertTrue(PartnerBalanceResolver::usesTerminal(['doc_type' => 'invno', 'payment_method' => '5'], $config));
        $this->assertFalse(PartnerBalanceResolver::usesTerminal(['doc_type' => 'invno', 'payment_method' => 3], $config));
        $this->assertFalse(PartnerBalanceResolver::usesTerminal(['doc_type' => 'invni', 'payment_method' => 2], $config));

        $this->assertTrue(PartnerBalanceResolver::isDerived(PartnerBalanceResolver::SOURCE_TERMINAL));
        $this->assertTrue(PartnerBalanceResolver::isDerived(PartnerBalanceResolver::SOURCE_TRANSPORT));
        $this->assertFalse(PartnerBalanceResolver::isDerived(PartnerBalanceResolver::SOURCE_MANUAL));
    }
}
