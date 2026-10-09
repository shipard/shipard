<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
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
 * Ruční volba položky v review (`rows[i].item = useExisting:<id>`,
 * tasks/exchange-preview-matched-item.md #111 D7b, D8) a vynechání řádku:
 * účet navržený historií se nezapíše, mapování kódu dodavatele se přepíše,
 * item-level `skip` řádek z dokladu vynechá.
 */
class DocumentApplierItemPinTest extends TestCase
{
    private ?array $savedHeadsData = null;

    private function buildApplier(ResolveResult $itemResult, ?int $accountId): TestableDocumentApplier
    {
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

        $heads = $this->createMock(TransactionlessTableGateway::class);
        $this->savedHeadsData = null;
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->savedHeadsData = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        // entityLinkable('economy_items', 77) → existuje; registrace DPH
        // (plátce → kód DPH řádku se zapíše); ostatní lookupy null.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            if (($args[1] ?? null) === 'economy_items' && ($args[2] ?? null) === 77) {
                return new Row(['id' => 77]);
            }
            if (str_contains((string) ($args[0] ?? ''), 'economy_codebooks_vat_registrations')) {
                return new Row(['id' => 5, 'country' => 'cz']);
            }
            return null;
        });
        $db->method('fetchAll')->willReturn([]);

        return new TestableDocumentApplier(
            db: $db,
            config: $this->createMock(ConfigRuntime::class),
            headsGateway: $heads,
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
        );
    }

    /**
     * Happy fixture s účtem na rows[0] (jak ho propíše enrichment), blokem
     * `enrichment` (klíč `index`, ne pozice) a volbou položky.
     *
     * @return array<string, mixed>
     */
    private function payload(
        ?string $rowAccount,
        ?string $suggestedAccount,
        ?string $itemAction,
        int $rowCount = 1,
        ?string $rowAction = null,
    ): array {
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
        $resolveRow = ['index' => 0];
        if ($suggestedAccount !== null) {
            $resolveRow['enrichment'] = [
                'matchedBy'  => 'historyExactRaw',
                'confidence' => 'high',
                'suggested'  => ['ourCode' => 'KONZ', 'vatCode' => 'cz-110', 'account' => $suggestedAccount],
            ];
        }
        if ($itemAction !== null) {
            $resolveRow['item'] = ['userAction' => $itemAction];
        }
        if ($rowAction !== null) {
            $resolveRow['userAction'] = $rowAction;
        }
        $payload['_resolve'] = ['rows' => [0 => $resolveRow]];
        return $payload;
    }

    /** @return list<string> SQL zápisů mapování kódu dodavatele. */
    private function supplierCodeSql(TestableDocumentApplier $applier): array
    {
        $out = [];
        foreach ($applier->sqlCalls as $call) {
            $sql = (string) ($call[0] ?? '');
            if (str_contains($sql, 'economy_items_supplier_codes')) {
                $out[] = $sql;
            }
        }
        return $out;
    }

    public function testPinDropsAccountSuggestedByHistoryAndKeepsVatCode(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $result = $applier->apply($this->payload('518100', '518100', 'useExisting:77'));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $row = $this->savedHeadsData['rows'][0] ?? null;
        $this->assertIsArray($row);
        $this->assertSame(77, $row['item']);
        $this->assertArrayNotHasKey('account', $row, 'účet z historie se s ruční položkou nezapíše (D7b)');
        $this->assertSame('cz-110', $row['vat_code'], 'kód DPH je z analýzy, položka ho nemění (D7a)');
    }

    public function testPinKeepsAccountThatDiffersFromSuggestion(): void
    {
        // Účet řádku 518100 od AI / uživatele; historie navrhovala 501300.
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $result = $applier->apply($this->payload('518100', '501300', 'useExisting:77'));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $this->assertSame(55, $this->savedHeadsData['rows'][0]['account']);
    }

    public function testMatchedWithoutPinKeepsSuggestedAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), 55);

        $result = $applier->apply($this->payload('518100', '518100', null));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $this->assertSame(18, $this->savedHeadsData['rows'][0]['item']);
        $this->assertSame(55, $this->savedHeadsData['rows'][0]['account']);
    }

    public function testPinUpsertsSupplierCodeMapping(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'supplierCode'), null);

        $result = $applier->apply($this->payload(null, null, 'useExisting:77'));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $sql = $this->supplierCodeSql($applier);
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql[0]);
        $this->assertStringNotContainsString('INSERT IGNORE', $sql[0]);
        $this->assertSame(77, $applier->sqlCalls[0][2] ?? null, 'mapování míří na zvolenou položku');
    }

    public function testAutomaticMatchStillInsertsIgnore(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'supplierCode'), null);

        $result = $applier->apply($this->payload(null, null, null));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $sql = $this->supplierCodeSql($applier);
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('INSERT IGNORE', $sql[0]);
    }

    public function testNoItemAndSkipWriteNoMapping(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'supplierCode'), 55);
        $result = $applier->apply($this->payload('518100', null, 'noItem'));
        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $this->assertSame([], $this->supplierCodeSql($applier));

        $applier = $this->buildApplier(ResolveResult::matched(18, 'supplierCode'), 55);
        $result = $applier->apply($this->payload('518100', null, 'skip'));
        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $this->assertSame([], $this->supplierCodeSql($applier));
    }

    public function testItemLevelSkipLeavesRowOutOfDocument(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), null);

        $result = $applier->apply($this->payload(null, null, 'skip', rowCount: 2));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $rows = $this->savedHeadsData['rows'] ?? [];
        $this->assertCount(1, $rows, 'vynechaný řádek 0 na dokladu není');
        $this->assertSame(1, $rows[0]['order_pos']);
        $this->assertSame(18, $rows[0]['item']);
    }

    /** Řádková volba `rows[i].userAction = skip` vynechává stejně jako položková ({@see DocumentApplier::skippedRowIndices}). */
    public function testRowLevelSkipLeavesRowOutOfDocument(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), null);

        $result = $applier->apply($this->payload(null, null, null, rowCount: 2, rowAction: 'skip'));

        $this->assertTrue($result->success, "errorCode={$result->errorCode} msg={$result->errorMessage}");
        $rows = $this->savedHeadsData['rows'] ?? [];
        $this->assertCount(1, $rows, 'vynechaný řádek 0 na dokladu není');
        $this->assertSame(1, $rows[0]['order_pos']);
        $this->assertSame(18, $rows[0]['item']);
    }

    public function testNoItemStillRequiresAccount(): void
    {
        $applier = $this->buildApplier(ResolveResult::matched(18, 'ourCode'), null);

        $result = $applier->apply($this->payload(null, null, 'noItem'));

        $this->assertFalse($result->success);
        $this->assertSame('no_item_requires_account', $result->errorCode);
    }
}
