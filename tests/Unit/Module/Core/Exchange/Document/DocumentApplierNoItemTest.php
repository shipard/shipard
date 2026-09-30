<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Utils\JsoncParser;
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
 * userAction `noItem` — „jen účet, bez položky" (tasks/content-tag-ui.md D24):
 * řádek se pořídí bez item FK, účet řádku je povinný, pin přebíjí fresh
 * resolve i enrichment návrh.
 */
class DocumentApplierNoItemTest extends TestCase
{
    /** Zachycený payload posledního heads saveDocument. */
    private ?array $savedHeadsData = null;

    private function buildApplier(
        ResolveResult $itemResult,
        ?int $accountId,
        ?TransactionlessTableGateway $items = null,
        ?ConfigRuntime $config = null,
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

        $heads = $this->createMock(TransactionlessTableGateway::class);
        $this->savedHeadsData = null;
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->savedHeadsData = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        // Idempotency / number series / vat registration lookupy → null.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        return new TestableDocumentApplier(
            db: $db,
            config: $config ?? $this->createMock(ConfigRuntime::class),
            headsGateway: $heads,
            personsGateway: $this->createMock(TransactionlessTableGateway::class),
            itemsGateway: $items ?? $this->createMock(TransactionlessTableGateway::class),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: $vat,
            bankAccountResolver: $bank,
            accountResolver: $account,
            // DB mock nevrací registraci DPH → derivace kódu se neuplatní.
            vatCodeDerivation: new VatCodeDerivation(new VatRateResolver($this->createMock(ConfigRuntime::class))),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($this->createMock(ConfigRuntime::class))),
        );
    }

    /**
     * Happy fixture s pinem noItem na rows[0]; volitelně s účtem na řádku
     * (enrichment ho na fresh cestě propisuje do canonical.rows[i].account).
     *
     * @return array<string, mixed>
     */
    private function payloadWithNoItemPin(?string $rowAccount): array
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        if ($rowAccount !== null) {
            $payload['rows'][0]['account'] = $rowAccount;
        }
        $payload['_resolve'] = [
            'rows' => [0 => ['item' => ['userAction' => 'noItem']]],
        ];
        return $payload;
    }

    private function rowOperationConfig(): ConfigRuntime
    {
        $root = dirname(__DIR__, 6);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.rowOperations',
             JsoncParser::parseFile($root . '/modules/docs/core/config/rowOperations.jsonc')],
            ['docs.core.applyRowOperations',
             JsoncParser::parseFile($root . '/modules/docs/core/config/applyRowOperations.jsonc')],
        ]);
        return $config;
    }

    public function testNoItemWithAccountAppliesRowWithoutItem(): void
    {
        $applier = $this->buildApplier(
            itemResult: ResolveResult::canCreate(['name' => 'Konzultace']),
            accountId: 55,
            config: $this->rowOperationConfig(),
        );

        $result = $applier->apply($this->payloadWithNoItemPin('503100'));

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
        $this->assertSame(1234, $result->savedId);

        $row = $this->savedHeadsData['rows'][0] ?? null;
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('item', $row);
        $this->assertSame(55, $row['account']);
        // Pohyb spadl na default docTypu (invni bez item_type → acc.entry).
        $this->assertSame('acc.entry', $row['operation']);

        // Response označí rozhodnutý řádek statusem noItem (jen _resolve).
        $this->assertSame('noItem', $result->canonical['_resolve']['rows'][0]['item']['status']);
    }

    public function testNoItemWithoutAccountFailsWithCleanError(): void
    {
        $applier = $this->buildApplier(
            itemResult: ResolveResult::canCreate(['name' => 'Konzultace']),
            accountId: null,
        );

        $result = $applier->apply($this->payloadWithNoItemPin(null));

        $this->assertFalse($result->success);
        $this->assertSame('no_item_requires_account', $result->errorCode);
        $this->assertSame(422, $result->statusCode);
        $codes = array_column($result->canonical['_resolve']['issues'] ?? [], 'code');
        $this->assertContains('no_item_requires_account', $codes);
        $this->assertNull($this->savedHeadsData, 'Save nesmí proběhnout');
    }

    public function testNoItemOverridesMatchedItemAndSkipsSideCreate(): void
    {
        // Fresh resolve položku najde (matched) — pin noItem ji přesto
        // přebíjí (D3) a žádný item side-create neproběhne.
        $items = $this->createMock(TransactionlessTableGateway::class);
        $items->expects($this->never())->method('saveDocument');

        $applier = $this->buildApplier(
            itemResult: ResolveResult::matched(18, 'ourCode'),
            accountId: 55,
            items: $items,
        );

        $result = $applier->apply($this->payloadWithNoItemPin('503100'));

        $this->assertTrue(
            $result->success,
            "Expected success; errorCode={$result->errorCode} msg={$result->errorMessage}",
        );
        $row = $this->savedHeadsData['rows'][0] ?? null;
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('item', $row);
        $this->assertSame(55, $row['account']);
        $this->assertSame('noItem', $result->canonical['_resolve']['rows'][0]['item']['status']);
    }
}
