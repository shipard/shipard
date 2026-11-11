<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Dibi\Connection;
use Dibi\Row;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Logging\ErrorLogger;
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
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\DocsHeadsDocument;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Harness a payloady přijatého dokladu **mimo české DPH** (#89): applier se
 * skutečným `vat-cz.jsonc`, skutečnou taxonomií štítků a skutečným
 * `DocsHeadsDocument`; hotel v Německu se zahraniční DPH a tentýž doklad
 * bez daně se štítkem zvláštního místa plnění.
 *
 * Sdílí ho {@see DocumentApplierOutOfScopeTest} a test stropu pásma karty
 * návrhu (`SpecialPlaceCapTest`, #89 D17) — strop se ověřuje nad týmiž
 * canonicaly, o kterých rozhoduje applier.
 */
trait OutOfScopeHarness
{
    private const ROOT = __DIR__ . '/../../../../../..';

    private string $tmpDir;

    /** Payload posledního `saveDocument()` po `beforeSave()`. */
    private ?array $savedHeadsData = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_out_of_scope_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode([
                '_meta' => ['language' => 'cs'],
                'items' => [
                    'world.vat.cz'       => JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc'),
                    'docs.core.docTypes' => $this->docTypes(),
                ],
            ]),
        );
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->tmpDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    // ── Harness ─────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function docTypes(): array
    {
        return ['invno' => ['trade_dir' => 1], 'invni' => ['trade_dir' => 2]];
    }

    private static ?array $vatCz = null;
    private static ?array $vatPlaces = null;
    private static ?array $tradeUnions = null;

    private function vatCzRateResolver(): VatRateResolver
    {
        self::$vatCz ??= JsoncParser::parseFile(self::ROOT . '/modules/world/vat/config/vat-cz.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.vat.cz' ? self::$vatCz : null,
        );
        return new VatRateResolver($config);
    }

    private function tradeUnionResolver(): TradeUnionResolver
    {
        self::$tradeUnions ??= JsoncParser::parseFile(self::ROOT . '/modules/world/trade/config/tradeUnions.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'world.trade.unions' ? self::$tradeUnions : null,
        );
        return new TradeUnionResolver($config);
    }

    private function applierConfig(): ConfigRuntime
    {
        self::$vatPlaces ??= JsoncParser::parseFile(self::ROOT . '/modules/docs/core/config/vatPlaces.jsonc');
        $docTypes = $this->docTypes();
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'docs.core.docTypes'  => $docTypes,
                'docs.core.vatPlaces' => self::$vatPlaces,
                default               => null,
            },
        );
        return $config;
    }

    /**
     * Položky se štítky na zdroji (#89 D19) — tvary ze sady plátce: cestovné
     * (jen `special`), mýto se správními poplatky (štítek bez určení se
     * nepočítá), sběrné ostatní služby (`special` i `services`), software.
     */
    private const TAGGED_ITEMS = [
        'CEST'  => ['travel.accommodation', 'travel.fares'],
        'MYTO'  => ['vehicle.toll', 'admin.fees'],
        'OSTAT' => ['vehicle.parking', 'premises.security', 'services.shipping', 'services.postage'],
        'SOFT'  => ['it.software'],
    ];

    /** Počet dotazů na štítky položek ({@see db()}) — jeden na doklad. */
    private int $itemTagQueries = 0;

    /**
     * DB zdroje: jedna aktivní registrace DPH (cz, id 5), štítky položek
     * podle kódu (`ItemContentTags`); ostatní dotazy prázdné.
     *
     * @param array<string, list<string>> $taggedItems kód položky → štítky
     */
    private function db(array $taggedItems = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(static function (mixed ...$args): ?Row {
            $sql = (string) ($args[0] ?? '');
            return str_contains($sql, 'economy_codebooks_vat_registrations')
                ? new Row(['id' => 5, 'country' => 'cz'])
                : null;
        });
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($taggedItems): array {
            if (!str_contains((string) ($args[0] ?? ''), '[content_tags] FROM [economy_items]')) {
                return [];
            }
            $this->itemTagQueries++;
            $wanted = array_map('mb_strtolower', (array) ($args[1] ?? []));
            $rows = [];
            foreach ($taggedItems as $code => $tags) {
                if (in_array(mb_strtolower($code), $wanted, true)) {
                    $rows[] = new Row(['code' => $code, 'content_tags' => json_encode($tags)]);
                }
            }
            return $rows;
        });
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    private function headDocument(): Document
    {
        $doc = new DocsHeadsDocument();
        $doc->setDb($this->db());
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /** Zdroj bez registrace DPH — přijatý doklad neplátce. */
    private function dbWithoutVatRegistration(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        $db->method('getInsertId')->willReturn(0);
        return $db;
    }

    private static ?array $contentTaxonomy = null;

    /**
     * Skutečný cfgItem core.exchange.contentTags ve tvaru compiled configu
     * (`crossBorderSupply: "special"` u ubytování, jízdného, mýta, …).
     *
     * @return array<string, array<string, mixed>>
     */
    private function contentTaxonomy(): array
    {
        self::$contentTaxonomy ??= array_map(
            static fn (array $entry): array => ['name' => $entry['name:cs'] ?? $entry['name']] + $entry,
            JsoncParser::parseFile(self::ROOT . '/modules/core/exchange/config/contentTags.jsonc'),
        );
        return self::$contentTaxonomy;
    }

    /** @param Connection|null $db DB applieru; výchozí zdroj s registrací DPH ({@see db()}). */
    private function buildApplier(?Connection $db = null): DocumentApplier
    {
        $heads = $this->createMock(TransactionlessTableGateway::class);
        $heads->method('createDocument')->willReturnCallback(fn (array $data): Document => $this->headDocument());
        $this->savedHeadsData = null;
        $heads->method('saveDocument')->willReturnCallback(function (array $data): DocumentResult {
            $this->headDocument()->beforeSave($data);
            $this->savedHeadsData = $data;
            return DocumentResult::ok(['id' => 1234]);
        });

        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        $party->method('resolveSelfParty')->willReturn(ResolveResult::matched(1, 'self'));
        $unit = $this->createMock(UnitResolver::class);
        $unit->method('resolve')->willReturn(ResolveResult::matched(3, 'systemCode'));
        $item = $this->createMock(ItemResolver::class);
        $item->method('resolve')->willReturn(ResolveResult::matched(18, 'ourCode'));
        $bank = $this->createMock(BankAccountResolver::class);
        $bank->method('resolvePartnerBank')->willReturn(ResolveResult::matched(7, 'iban'));
        $account = $this->createMock(AccountResolver::class);
        $account->method('resolve')->willReturn(null);

        return new TestableDocumentApplier(
            db: $db ?? $this->db(),
            config: $this->applierConfig(),
            headsGateway: $heads,
            personsGateway: $this->createMock(TransactionlessTableGateway::class),
            itemsGateway: $this->createMock(TransactionlessTableGateway::class),
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            documentValidator: new DocumentValidator(),
            partyResolver: $party,
            itemResolver: $item,
            unitResolver: $unit,
            vatCodeResolver: new VatCodeResolver($this->vatCzRateResolver()),
            bankAccountResolver: $bank,
            accountResolver: $account,
            vatCodeDerivation: new VatCodeDerivation($this->vatCzRateResolver()),
            vatPlaceDerivation: new VatPlaceDerivation($this->tradeUnionResolver()),
            contentTags: $this->contentTaxonomy(),
        );
    }

    // ── Payloady ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function happyPayload(): array
    {
        return json_decode(
            (string) file_get_contents(self::ROOT . '/tests/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * Hotel v Německu: ubytování 7 %, snídaně 19 %, ceny bez daně, daň
     * dodavatele vyčíslená v rekapitulaci. Místo plnění plyne z DIČ
     * dodavatele (EU); přenesení daňové povinnosti doklad neuvádí.
     *
     * @return array<string, mixed>
     */
    private function hotelPayload(): array
    {
        $payload = $this->happyPayload();
        $payload['docText'] = 'Ubytování Berlín';
        $payload['supplier']['name'] = 'Hotel Beispiel GmbH';
        $payload['supplier']['country'] = 'DE';
        $payload['supplier']['vatId'] = 'DE123456789';
        $payload['supplier']['address']['country'] = 'DE';
        unset($payload['supplier']['companyId'], $payload['supplier']['taxId']);
        $payload['vat'] = ['mode' => 'fromBase', 'place' => 'intracom', 'reverseCharge' => false];
        $row = $payload['rows'][0];
        $row['vat'] = ['code' => null, 'pct' => 7, 'supplyKind' => 'services'];
        $row['item'] = ['name' => 'Ubytování'];
        $row['unit'] = 'ks';
        $row['quantity'] = 2;
        $row['unitPrice'] = 100.00;
        $row['totalPrice'] = 200.00;
        unset($row['computed']);
        $breakfast = $row;
        $breakfast['orderPos'] = 2;
        $breakfast['item'] = ['name' => 'Snídaně'];
        $breakfast['vat']['pct'] = 19;
        $breakfast['unitPrice'] = 15.00;
        $breakfast['totalPrice'] = 30.00;
        $payload['rows'] = [$row, $breakfast];
        $payload['vatRecap'] = [
            ['vatCode' => null, 'vatPct' => 7, 'base' => 200.00, 'tax' => 14.00, 'total' => 214.00],
            ['vatCode' => null, 'vatPct' => 19, 'base' => 30.00, 'tax' => 5.70, 'total' => 35.70],
        ];
        $payload['totals'] = ['totalBase' => 230.00, 'totalVat' => 19.70, 'totalAmount' => 249.70, 'totalRounding' => 0];
        return $payload;
    }

    /**
     * Tentýž hotel, ale doklad daň nevyčísluje (0 %, bez rekapitulace daně).
     * Štítek dokumentu `travel.accommodation` má zvláštní místo plnění.
     *
     * @param list<array{rowIndex: int, tag: string}> $rowExceptions
     * @return array<string, mixed>
     */
    private function specialPlacePayload(?string $tag = 'travel.accommodation', array $rowExceptions = []): array
    {
        $payload = $this->hotelPayload();
        unset($payload['vat']['reverseCharge']);
        foreach ($payload['rows'] as &$row) {
            $row['vat']['pct'] = 0;
        }
        unset($row);
        $payload['vatRecap'] = [
            ['vatCode' => null, 'vatPct' => 0, 'base' => 230.00, 'tax' => 0, 'total' => 230.00],
        ];
        $payload['totals'] = ['totalBase' => 230.00, 'totalVat' => 0, 'totalAmount' => 230.00, 'totalRounding' => 0];
        if ($tag !== null) {
            $payload['_resolve']['contentTag'] = [
                'tag'           => $tag,
                'tagSource'     => 'llm',
                'tagConfidence' => 0.9,
                'promptVersion' => 'tag-v1',
                'rowExceptions' => $rowExceptions,
            ];
        }
        return $payload;
    }

    /**
     * Hotel bez vyčíslené daně u **opakovaného dodavatele**: řádky pokryté
     * položkami z historie, žádný blok `_resolve.contentTag` (#89 D19).
     *
     * @param list<string> $itemCodes kód položky per řádek ({@see TAGGED_ITEMS})
     * @return array<string, mixed>
     */
    private function coveredPayload(array $itemCodes = ['CEST', 'CEST']): array
    {
        $payload = $this->specialPlacePayload(tag: null);
        foreach ($itemCodes as $idx => $code) {
            $payload['rows'][$idx]['item']['ourCode'] = $code;
        }
        return $payload;
    }

    /** @param array<string, string> $flat plochá mapa {cesta: akce} jako z MessageProposalApplier */
    private function withPins(array $payload, array $flat): array
    {
        foreach ($flat as $path => $action) {
            if (preg_match('/^rows\[(\d+)\]\.vatCode$/', $path, $m) === 1) {
                $payload['_resolve']['rows'][(int) $m[1]]['vatCode']['userAction'] = $action;
            } elseif (preg_match('/^vat\.(place|mode)$/', $path, $m) === 1) {
                $payload['_resolve']['vat'][$m[1]]['userAction'] = $action;
            } else {
                throw new \InvalidArgumentException($path);
            }
        }
        return $payload;
    }

    /** @return list<string> */
    private function issueCodes(array $canonical): array
    {
        return array_values(array_map(
            static fn (array $i): string => (string) $i['code'],
            $canonical['_resolve']['issues'] ?? [],
        ));
    }

    private function issue(array $canonical, string $code): ?array
    {
        foreach ($canonical['_resolve']['issues'] ?? [] as $issue) {
            if (($issue['code'] ?? null) === $code) {
                return $issue;
            }
        }
        return null;
    }
}
