<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentResult;
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
 * userAction `noItem` („jen účet, bez položky") je zrušená (#36 D1, D2 —
 * tasks/exchange-no-item-removal.md): applier ji nezná a odmítne ji jako
 * každou jinou neznámou userAction, i když řádek nese platný účet.
 */
class DocumentApplierNoItemTest extends TestCase
{
    use InterpreterFixture;
    use ReferenceFixture;
    /** Zachycený payload posledního heads saveDocument. */
    private ?array $savedHeadsData = null;

    private function buildApplier(
        ResolveResult $itemResult,
        ?int $accountId,
        ?TransactionlessTableGateway $items = null,
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

        $bank = $this->bankAccountResolverMock();
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
            config: $this->createMock(ConfigRuntime::class),
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
            references: $this->anyIdReferenceResolver(),
        );
    }

    /**
     * Happy fixture s pinem noItem na rows[0]; volitelně s účtem na řádku.
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

    public function testNoItemIsRejectedAsUnknownUserAction(): void
    {
        $items = $this->createMock(TransactionlessTableGateway::class);
        $items->expects($this->never())->method('saveDocument');

        $applier = $this->buildApplier(
            itemResult: ResolveResult::canCreate(['name' => 'Konzultace']),
            accountId: 55,
            items: $items,
        );

        $result = self::proposalOf($applier)->apply($this->payloadWithNoItemPin('503100'));

        $this->assertFalse($result->success);
        $this->assertSame('conflict', $result->errorCode);
        $this->assertStringContainsString('Neznámá userAction „noItem"', (string) $result->errorMessage);
        $this->assertStringContainsString('rows.0.item', (string) $result->errorMessage);
        $this->assertNull($this->savedHeadsData, 'Save nesmí proběhnout');
    }
}
