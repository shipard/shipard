<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Náhled: efektivní položka a účet řádku v `_resolve.rows[*]`
 * (tasks/exchange-preview-matched-item.md, #111 D3, D7b):
 * `item.display` {id, code, name, pinned} a `effectiveAccount`
 * {id, number, name, source} | null.
 */
class DocumentApplierRowDisplayTest extends TestCase
{
    /** Položky pro dotaz displeje: id → řádek (tvar výsledku JOINu). */
    private const ITEMS = [
        18 => ['id' => 18, 'code' => 'SPOTR-TON', 'name' => 'Tonery a náplně', 'item_type' => 2,
               'account_id' => 412, 'account_number' => '501300', 'account_name' => 'Spotřeba materiálu'],
        77 => ['id' => 77, 'code' => 'SLUZ-IT', 'name' => 'IT služby', 'item_type' => 2,
               'account_id' => 518, 'account_number' => '518100', 'account_name' => 'Ostatní služby'],
        // Služba bez vlastního účtu — řádek s ní se účtuje maskou kategorie.
        30 => ['id' => 30, 'code' => 'KONZ', 'name' => 'Konzultace', 'item_type' => 0,
               'account_id' => null, 'account_number' => null, 'account_name' => null],
    ];

    private const ACCOUNTS = [
        55 => ['id' => 55, 'number' => '503100', 'name' => 'Spotřeba energie'],
    ];

    /** SQL všech fetchAll volání posledního náhledu. @var list<string> */
    private array $fetchAllSql = [];

    private function buildApplier(
        ResolveResult $itemResult,
        ?int $accountId,
        bool $itemsHaveAccountingAccount = true,
    ): DocumentApplier {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));

        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));

        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn($itemResult);

        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturn(new ResolveResult(
            ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: ['code' => 'cz-110', 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false],
        ));

        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $account = $this->createMock(AccountResolver::class);
        $account->method('resolve')->willReturn($accountId);

        $this->fetchAllSql = [];
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($itemsHaveAccountingAccount): array {
            $sql = (string) ($args[0] ?? '');
            $this->fetchAllSql[] = $sql;
            if (str_contains($sql, '[economy_items]')) {
                $ids = is_array($args[1] ?? null) ? $args[1] : [];
                $rows = [];
                foreach ($ids as $id) {
                    if (!isset(self::ITEMS[$id])) {
                        continue;
                    }
                    $row = self::ITEMS[$id];
                    if (!$itemsHaveAccountingAccount) {
                        unset($row['account_id'], $row['account_number'], $row['account_name']);
                    }
                    $rows[] = $row;
                }
                return $rows;
            }
            if (str_contains($sql, '[economy_accounting_accounts]')) {
                $ids = is_array($args[1] ?? null) ? $args[1] : [];
                return array_values(array_intersect_key(self::ACCOUNTS, array_flip($ids)));
            }
            return [];
        });

        return new TestableDocumentApplier(
            db: $db,
            config: $this->createMock(ConfigRuntime::class),
            headsGateway: $this->createMock(TransactionlessTableGateway::class),
            personsGateway: $this->createMock(TransactionlessTableGateway::class),
            itemsGateway: $this->createMock(TransactionlessTableGateway::class),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: $vat,
            bankAccountResolver: $bank,
            accountResolver: $account,
            vatCodeDerivation: new VatCodeDerivation(new VatRateResolver($this->createMock(ConfigRuntime::class))),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($this->createMock(ConfigRuntime::class))),
            itemsHaveAccountingAccount: $itemsHaveAccountingAccount,
        );
    }

    /**
     * Happy fixture; `$rowAccount` propíše účet na rows[0] (jako enrichment),
     * `$itemAction` je uložená volba `rows[0].item`.
     *
     * @return array<string, mixed>
     */
    private function payload(?string $rowAccount = null, ?string $itemAction = null, int $rowCount = 1): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $row = $payload['rows'][0];
        if ($rowAccount !== null) {
            $row['account'] = $rowAccount;
        }
        $payload['rows'] = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $r = $row;
            $r['orderPos'] = $i + 1;
            $r['item']['supplierCode'] = 'KONZ-00' . ($i + 1);
            $payload['rows'][] = $r;
        }
        if ($itemAction !== null) {
            $payload['_resolve'] = ['rows' => [0 => ['item' => ['userAction' => $itemAction]]]];
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    private function previewRow(DocumentApplier $applier, array $payload, int $pos = 0): array
    {
        $result = $applier->preview($payload);
        $this->assertTrue($result->success);
        $row = $result->canonical['_resolve']['rows'][$pos] ?? null;
        $this->assertIsArray($row);
        $this->assertArrayHasKey('effectiveAccount', $row, 'effectiveAccount je na každém řádku');
        return $row;
    }

    public function testMatchedRowShowsItemAndItsAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), null);

        $row = $this->previewRow($applier, $this->payload());

        $this->assertSame(
            ['id' => 18, 'code' => 'SPOTR-TON', 'name' => 'Tonery a náplně', 'pinned' => false],
            $row['item']['display'],
        );
        $this->assertSame(
            ['id' => 412, 'number' => '501300', 'name' => 'Spotřeba materiálu', 'source' => 'item'],
            $row['effectiveAccount'],
        );
    }

    public function testUseExistingOverMatchedShowsChosenItemPinned(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $row = $this->previewRow($applier, $this->payload('503100', 'useExisting:77'));

        $this->assertSame(77, $row['item']['display']['id']);
        $this->assertTrue($row['item']['display']['pinned']);
        $this->assertSame('SLUZ-IT', $row['item']['display']['code']);
        // Účet jde s položkou — ne účet řádku z historie (D7b).
        $this->assertSame('518100', $row['effectiveAccount']['number']);
        $this->assertSame('item', $row['effectiveAccount']['source']);
        // Fresh status zůstává matched na původní položku.
        $this->assertSame(18, $row['item']['matchedId']);
    }

    public function testServiceItemWithoutAccountHidesRowAccountFromHistory(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(30, 'supplierCode'), 55);

        $row = $this->previewRow($applier, $this->payload('503100'));

        $this->assertSame(30, $row['item']['display']['id']);
        $this->assertNull($row['effectiveAccount'], 'služba bez účtu → „—“, i když canonical nese účet');
        // Resolve účtu řádku je dál k dispozici (badge, noItem).
        $this->assertSame('matched', $row['account']['status']);
    }

    public function testNoItemShowsRowAccountWithoutDisplay(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $row = $this->previewRow($applier, $this->payload('503100', 'noItem'));

        $this->assertArrayNotHasKey('display', $row['item']);
        $this->assertSame(
            ['id' => 55, 'number' => '503100', 'name' => 'Spotřeba energie', 'source' => 'row'],
            $row['effectiveAccount'],
        );
    }

    public function testSkipHasNeitherDisplayNorAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $row = $this->previewRow($applier, $this->payload('503100', 'skip'));

        $this->assertArrayNotHasKey('display', $row['item']);
        $this->assertNull($row['effectiveAccount']);
    }

    public function testUndecidedCanCreateProposesRowAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::canCreate(['name' => 'Konzultace']), 55);

        $row = $this->previewRow($applier, $this->payload('503100'));

        $this->assertArrayNotHasKey('display', $row['item']);
        $this->assertSame('row', $row['effectiveAccount']['source']);
        $this->assertSame('503100', $row['effectiveAccount']['number']);
    }

    public function testUndecidedCanCreateWithoutAccountIsNull(): void
    {
        $applier = $this->buildApplier(ResolveResult::canCreate(['name' => 'Konzultace']), null);

        $row = $this->previewRow($applier, $this->payload());

        $this->assertArrayNotHasKey('display', $row['item']);
        $this->assertNull($row['effectiveAccount']);
    }

    public function testPinOnMissingItemHasNoDisplayAndFallsBackToRowAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $row = $this->previewRow($applier, $this->payload('503100', 'useExisting:999'));

        $this->assertArrayNotHasKey('display', $row['item']);
        $this->assertSame('row', $row['effectiveAccount']['source']);
    }

    public function testQueryCountDoesNotDependOnRowCount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $result = $applier->preview($this->payload('503100', null, 5));
        $this->assertTrue($result->success);

        $displayQueries = array_values(array_filter(
            $this->fetchAllSql,
            static fn (string $sql): bool => str_contains($sql, '[economy_items]')
                || str_contains($sql, '[economy_accounting_accounts]'),
        ));
        $this->assertCount(2, $displayQueries, 'položky + účty řádků, žádný dotaz per řádek');
        $this->assertStringContainsString('LEFT JOIN [economy_accounting_accounts]', $displayQueries[0]);
        foreach ($result->canonical['_resolve']['rows'] as $row) {
            $this->assertSame(18, $row['item']['display']['id']);
            $this->assertSame('item', $row['effectiveAccount']['source']);
        }
    }

    public function testWithoutAccountingExtensionItemAccountIsNullAndNoJoin(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), null, itemsHaveAccountingAccount: false);

        $row = $this->previewRow($applier, $this->payload());

        $this->assertSame(18, $row['item']['display']['id']);
        $this->assertNull($row['effectiveAccount']);
        foreach ($this->fetchAllSql as $sql) {
            $this->assertStringNotContainsString('accounting_account', $sql);
        }
    }
}
