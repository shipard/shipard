<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
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
use Shipard\Tests\Fixtures\Module\Economy\ExchangeRates\FakeExchangeRateResolver;

/**
 * Kurz z lístku v applieru (#121 D6, U5; tasks/exchange-rates-core.md §6.2):
 * null → doplněno + info, kurz z canonicalu vyhrává, import mód nedohledává,
 * nenalezeno → warning a doklad se uloží bez kurzu, domácí měna bez dotazu.
 */
class DocumentApplierExchangeRateTest extends TestCase
{
    private const RATES = ['eur|2026-10-16' => 24.325, 'eur|2026-10-20' => 24.5];

    private static ?array $vatCz = null;
    private static ?array $tradeUnions = null;

    /** @var array<string, mixed>|null data hlavičky, jak šla do gateway */
    private ?array $saved = null;

    private function applier(?FakeExchangeRateResolver $resolver): DocumentApplier
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $vat = $this->createMock(VatCodeResolver::class);
        $vat->method('resolve')->willReturnCallback(
            static fn (?string $code): ResolveResult => str_starts_with((string) $code, 'cz-')
                ? new ResolveResult(ResolveStatus::Matched, matchedId: 0, matchedBy: 'cfgItem', createPayload: ['code' => $code, 'pct' => 21.0, 'reverseVatCode' => null, 'noPayTax' => false])
                : ResolveResult::notFound(),
        );
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));

        $this->saved = null;
        $saved = &$this->saved;
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('saveDocument')->willReturnCallback(static function (array $data) use (&$saved): DocumentResult {
            $saved = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            return str_contains($sql, 'economy_codebooks_vat_registrations') ? new Row(['id' => 5, 'country' => 'cz']) : null;
        });
        $db->method('getInsertId')->willReturn(0);

        $docTypes = ['invno' => ['trade_dir' => 1], 'invni' => ['trade_dir' => 2], 'cmnbkp' => ['trade_dir' => 0]];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null);

        self::$vatCz ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/vat/config/vat-cz.jsonc');
        $vatConfig = $this->createMock(ConfigRuntime::class);
        $vatConfig->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null);
        self::$tradeUnions ??= JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/world/trade/config/tradeUnions.jsonc');
        $tradeConfig = $this->createMock(ConfigRuntime::class);
        $tradeConfig->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null);

        return new class(
            db: $db,
            config: $config,
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
            accountResolver: $this->createMock(AccountResolver::class),
            vatCodeDerivation: new VatCodeDerivation(new VatRateResolver($vatConfig)),
            vatPlaceDerivation: new VatPlaceDerivation(new TradeUnionResolver($tradeConfig)),
            exchangeRateResolver: $resolver,
        ) extends DocumentApplier {
            protected function executeSql(mixed ...$args): void
            {
            }
        };
    }

    /** Přijatá faktura z fixture, v EUR bez kurzu, účetní datum 16. 10. 2026. */
    private static function payload(array $override = []): array
    {
        $payload = json_decode((string) file_get_contents(dirname(__DIR__, 5) . '/Fixtures/Exchange/invoiceReceived_happy.json'), true);
        $payload['currency'] = 'EUR';
        $payload['exchangeRate'] = null;
        $payload['dates']['accountingDate'] = '2026-10-16';
        return array_replace($payload, $override);
    }

    private static function describe(\Shipard\Module\Core\Exchange\Common\ApplyResult $result): string
    {
        return json_encode([$result->errorCode, $result->canonical['_resolve']['issues'] ?? null], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, array{severity: string, path: string, code: string, message: string}> */
    private static function issuesByCode(array $canonical): array
    {
        return array_column($canonical['_resolve']['issues'] ?? [], null, 'code');
    }

    public function testPreviewAddsInfoWithRateAndList(): void
    {
        $resolver = new FakeExchangeRateResolver(self::RATES);
        $result = $this->applier($resolver)->preview(self::payload());

        $this->assertTrue($result->success, self::describe($result));
        $issues = self::issuesByCode($result->canonical);
        $this->assertArrayHasKey('exchange_rate_from_list', $issues);
        $this->assertSame('info', $issues['exchange_rate_from_list']['severity']);
        $this->assertSame('exchangeRate', $issues['exchange_rate_from_list']['path']);
        $this->assertSame('Kurz 24,325 z lístku ČNB — kurzy devizového trhu platného od 16. 10. 2026.', $issues['exchange_rate_from_list']['message']);
        $this->assertArrayNotHasKey('exchange_rate_missing', $issues);
        $this->assertSame([['eur', '2026-10-16']], array_unique($resolver->lookups, SORT_REGULAR));
    }

    public function testApplySavesRateFromListAndReportsInfo(): void
    {
        $result = $this->applier(new FakeExchangeRateResolver(self::RATES))->apply(self::payload());

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(24.325, $this->saved['exchange_rate'] ?? null);
        $this->assertSame('eur', $this->saved['doc_currency'] ?? null);
        $this->assertArrayHasKey('exchange_rate_from_list', self::issuesByCode($result->canonical));
    }

    public function testRateOnTheDocumentWins(): void
    {
        $resolver = new FakeExchangeRateResolver(self::RATES);
        $result = $this->applier($resolver)->apply(self::payload(['exchangeRate' => 25.1]));

        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame(25.1, $this->saved['exchange_rate']);
        $this->assertSame([], $resolver->lookups, 'kurz z dokladu — bez dotazu');
        $this->assertArrayNotHasKey('exchange_rate_from_list', self::issuesByCode($result->canonical));
    }

    public function testImportModeNeverLooksUp(): void
    {
        $resolver = new FakeExchangeRateResolver(self::RATES);
        $result = $this->applier($resolver)->apply(self::payload([
            'applyOptions' => ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]],
        ]));

        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('exchange_rate', $this->saved, 'chybějící kurz zůstane chybějící');
        $this->assertSame([], $resolver->lookups);
        $issues = self::issuesByCode($result->canonical);
        $this->assertArrayNotHasKey('exchange_rate_from_list', $issues);
        $this->assertArrayNotHasKey('exchange_rate_missing', $issues);
    }

    public function testMissingRateWarnsAndStillApplies(): void
    {
        $applier = $this->applier(new FakeExchangeRateResolver([]));

        $preview = $applier->preview(self::payload());
        $issues = self::issuesByCode($preview->canonical);
        $this->assertSame('warning', $issues['exchange_rate_missing']['severity'] ?? null);
        $this->assertSame('Kurz EUR k 16. 10. 2026 není v kurzovním lístku — doklad nepůjde potvrdit bez ručního kurzu.', $issues['exchange_rate_missing']['message']);

        $result = $applier->apply(self::payload());
        $this->assertTrue($result->success, self::describe($result));
        $this->assertArrayNotHasKey('exchange_rate', $this->saved);
        $this->assertArrayHasKey('exchange_rate_missing', self::issuesByCode($result->canonical));
    }

    public function testHomeCurrencyAndMissingResolverDoNothing(): void
    {
        $resolver = new FakeExchangeRateResolver(self::RATES);
        $result = $this->applier($resolver)->apply(self::payload(['currency' => 'CZK']));
        $this->assertTrue($result->success, self::describe($result));
        $this->assertSame([], $resolver->lookups);
        $this->assertArrayNotHasKey('exchange_rate', $this->saved);

        $result = $this->applier(null)->preview(self::payload());
        $this->assertTrue($result->success, self::describe($result));
        $issues = self::issuesByCode($result->canonical);
        $this->assertArrayNotHasKey('exchange_rate_from_list', $issues);
        $this->assertArrayNotHasKey('exchange_rate_missing', $issues);
    }

    public function testWithoutAccountingDateIssueDateIsUsed(): void
    {
        $resolver = new FakeExchangeRateResolver(self::RATES);
        $payload = self::payload();
        unset($payload['dates']['accountingDate']);
        $payload['dates']['issueDate'] = '2026-10-20';

        $this->applier($resolver)->apply($payload);

        $this->assertSame(24.5, $this->saved['exchange_rate']);
        $this->assertSame([['eur', '2026-10-20']], array_unique($resolver->lookups, SORT_REGULAR));
    }
}
