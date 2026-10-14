<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ContentTagsController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentResult;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Endpointy obsahových štítků (tasks/content-tag-ui.md D26/D27):
 * materialize (409 ALREADY_MAPPED, 422 ACCOUNT_REQUIRED/UNKNOWN_TAG),
 * overview (stav mapování + reverzní návrhy) a tag-items (bulk merge).
 */
class ContentTagsControllerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $savedItems = [];

    private static function auth(bool $authenticated = true): AuthContext
    {
        return new AuthContext(isAuthenticated: $authenticated, userId: $authenticated ? 1 : null);
    }

    private static function postRequest(array $body): Request
    {
        return Request::fromArray('POST', '/_exchange/content-tags/materialize', [], json_encode($body), []);
    }

    private function getStatus(Response $response): int
    {
        $ref  = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    private static function itemsTableDef(bool $withAccountingColumn = true): TableDefinition
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 25, 'nullable' => false],
        ];
        if ($withAccountingColumn) {
            $columns[] = ['id' => 'accounting_account', 'name' => 'Account', 'type' => 'int', 'nullable' => true];
        }
        return TableDefinition::fromArray(['tableId' => 320, 'name' => 'Items', 'columns' => $columns]);
    }

    /** @return array<string, mixed> malá taxonomie pro testy */
    private static function taxonomy(): array
    {
        return [
            'vehicle.fuel'     => ['name' => 'Pohonné hmoty', 'order' => 10],
            'services.banking' => ['name' => 'Bankovní poplatky', 'order' => 200],
            'goods.stock'      => ['name' => 'Zboží / materiál na sklad', 'order' => 310],
            'admin.other'      => ['name' => 'Ostatní (bez zařazení)', 'order' => 400],
            // Štítek mimo nabídku i mimo její prefixy — jediná cesta ke stavu unmapped.
            'misc.unknown'     => ['name' => 'Neznámý', 'order' => 500],
        ];
    }

    /**
     * @param list<array<string, mixed>> $taggedItems živé otagované položky
     *        (id, code, name, content_tags json, account_number)
     * @param list<array<string, mixed>> $untaggedItems živé položky s účtem
     *        bez štítků (id, code, name, account_number)
     * @param array<string, int> $accountIdsByNumber
     */
    private function makeDb(
        array $taggedItems = [],
        array $untaggedItems = [],
        array $accountIdsByNumber = [],
    ): MockObject&DataSourceConnection {
        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($accountIdsByNumber): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_system_settings')) {
                    return json_encode('default');
                }
                if (str_contains($sql, 'economy_items_kinds')) {
                    return 5;
                }
                if (str_contains($sql, 'core_units')) {
                    return 9;
                }
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    return $accountIdsByNumber[(string) ($args[1] ?? '')] ?? null;
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($taggedItems, $untaggedItems): array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'content_tags IS NOT NULL')) {
                    return $taggedItems;
                }
                if (str_contains($sql, 'content_tags IS NULL')) {
                    return $untaggedItems;
                }
                if (str_contains($sql, 'FROM economy_items')) {
                    return []; // existující kódy (materializer) — žádné kolize
                }
                return [];
            },
        );

        // ContentTagResolver (mapped check) běží nad dibi spojením.
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturnCallback(
            static fn (mixed ...$args): array => array_map(
                static fn (array $r) => new \Dibi\Row($r),
                $taggedItems,
            ),
        );
        $db->method('getDibiConnection')->willReturn($dibi);

        return $db;
    }

    private function makeConfig(): MockObject&ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', self::taxonomy()],
        ]);
        return $config;
    }

    /**
     * Controller se seam materializerem (zápis do $savedItems) a in-memory
     * řádky položek pro tag-items.
     *
     * @param array<int, array<string, mixed>> $itemRows id → celý řádek
     */
    private function makeController(
        MockObject&DataSourceConnection $db,
        array $itemRows = [],
        bool $withAccountingColumn = true,
    ): ContentTagsController {
        $config = $this->makeConfig();
        $tables = ['economy_items' => self::itemsTableDef($withAccountingColumn)];

        $offer = $this->getMockBuilder(AccountingItemsOffer::class)
            ->setConstructorArgs([$db, new ModulePathResolver([dirname(__DIR__, 4) . '/modules'])])
            ->onlyMethods(['variant'])
            ->getMock();
        $offer->method('variant')->willReturn('default');

        $test = $this;
        $materializer = new AccountingItemMaterializer(
            db: $db,
            offer: $offer,
            language: 'cs',
            config: $config,
            saveItem: function (array $payload) use ($test): DocumentResult {
                $test->savedItems[] = $payload;
                return DocumentResult::ok(['id' => 300 + count($test->savedItems)] + $payload);
            },
        );

        $this->savedItems = [];
        return new class($db, $config, $tables, $materializer, $itemRows, $test) extends ContentTagsController {
            /**
             * @param array<string, TableDefinition> $tables
             * @param array<int, array<string, mixed>> $itemRows
             */
            public function __construct(
                DataSourceConnection $db,
                ConfigRuntime $config,
                array $tables,
                private readonly AccountingItemMaterializer $testMaterializer,
                private readonly array $itemRows,
                private readonly ContentTagsControllerTest $test,
            ) {
                parent::__construct($db, $config, 'cs', null, $tables);
            }

            protected function materializer(): AccountingItemMaterializer
            {
                return $this->testMaterializer;
            }

            protected function fetchItemRow(int $id): ?array
            {
                return $this->itemRows[$id] ?? null;
            }

            protected function saveItemRow(array $payload): DocumentResult
            {
                $this->test->recordSave($payload);
                return DocumentResult::ok(['id' => (int) $payload['id']] + $payload);
            }
        };
    }

    /** @param array<string, mixed> $payload */
    public function recordSave(array $payload): void
    {
        $this->savedItems[] = $payload;
    }

    // ── materialize ────────────────────────────────────────────────────────

    public function testMaterializeRequiresAuth(): void
    {
        $ctrl = $this->makeController($this->makeDb());
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'vehicle.fuel']), self::auth(false));

        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testMaterializeRejectsUnknownTag(): void
    {
        $ctrl = $this->makeController($this->makeDb());
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'nonsense.tag']), self::auth());

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('UNKNOWN_TAG', $resp->getPayload()['error']['code']);
    }

    public function testMaterializeConflictsWhenTagAlreadyMapped(): void
    {
        $db = $this->makeDb(taggedItems: [[
            'id' => 7, 'code' => 'FUEL', 'name' => 'PHM',
            'content_tags' => json_encode(['vehicle.fuel']), 'account_number' => '503100',
        ]]);
        $ctrl = $this->makeController($db);
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'vehicle.fuel']), self::auth());

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('ALREADY_MAPPED', $resp->getPayload()['error']['code']);
        $this->assertSame([], $this->savedItems);
    }

    public function testMaterializeCreatesItemFromOffer(): void
    {
        $ctrl = $this->makeController($this->makeDb(accountIdsByNumber: ['503100' => 42]));
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'vehicle.fuel']), self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame('503100', $data['code']);
        $this->assertGreaterThan(0, $data['itemId']);
        $this->assertCount(1, $this->savedItems);
        $this->assertContains('vehicle.fuel', $this->savedItems[0]['content_tags']);
    }

    public function testMaterializeTagWithoutOfferEntryRequiresAccount(): void
    {
        // admin.other je jediný štítek bez položky v nabídce (ds-setup Task 12).
        $ctrl = $this->makeController($this->makeDb());
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'admin.other']), self::auth());

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('ACCOUNT_REQUIRED', $resp->getPayload()['error']['code']);
    }

    public function testMaterializeTagWithoutOfferEntryWithAccount(): void
    {
        $ctrl = $this->makeController($this->makeDb(accountIdsByNumber: ['548100' => 88]));
        $resp = $ctrl->materialize(
            self::postRequest(['tag' => 'admin.other', 'account' => '548100']),
            self::auth(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('548100', $resp->getPayload()['data']['code']);
        $this->assertSame(['admin.other'], $this->savedItems[0]['content_tags']);
    }

    public function testMaterializeUnavailableWithoutAccounting(): void
    {
        $ctrl = $this->makeController($this->makeDb(), withAccountingColumn: false);
        $resp = $ctrl->materialize(self::postRequest(['tag' => 'vehicle.fuel']), self::auth());

        $this->assertSame(409, $this->getStatus($resp));
        $this->assertSame('OFFER_UNAVAILABLE', $resp->getPayload()['error']['code']);
    }

    // ── overview ───────────────────────────────────────────────────────────

    public function testOverviewReportsMappingStates(): void
    {
        $db = $this->makeDb(
            taggedItems: [[
                'id' => 7, 'code' => 'FUEL', 'name' => 'PHM',
                'content_tags' => json_encode(['vehicle.fuel']), 'account_number' => '503100',
            ]],
            untaggedItems: [
                ['id' => 11, 'code' => 'BANK', 'name' => 'Poplatky', 'account_number' => '568201'],
                ['id' => 12, 'code' => 'SLUZBY', 'name' => 'Služby', 'account_number' => '518100'],
                ['id' => 13, 'code' => 'MIMO', 'name' => 'Mimo nabídku', 'account_number' => '999999'],
            ],
        );
        $resp = $this->makeController($db)->overview(self::auth());

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertTrue($data['available']);
        $this->assertSame('default', $data['chartVariant']);

        $byTag = array_column($data['tags'], null, 'tag');
        // Otagovaná položka → mapped s výčtem.
        $this->assertSame('mapped', $byTag['vehicle.fuel']['state']);
        $this->assertSame('FUEL', $byTag['vehicle.fuel']['items'][0]['code']);
        // Bez položky, ale v nabídce → defaultAccount.
        $this->assertSame('defaultAccount', $byTag['services.banking']['state']);
        $this->assertSame('568201', $byTag['services.banking']['defaultAccount']);
        // goods.stock míří na Spotřebu materiálu (#103 D31, ds-setup Task 12).
        $this->assertSame('defaultAccount', $byTag['goods.stock']['state']);
        $this->assertSame('501100', $byTag['goods.stock']['defaultAccount']);
        // Štítek bez položky i bez prefixového protějšku → unmapped.
        $this->assertSame('unmapped', $byTag['misc.unknown']['state']);
        $this->assertNull($byTag['misc.unknown']['defaultAccount']);

        // Reverzní návrhy: 568201 nese jediný štítek → návrh; 518100 nese
        // v nabídce víc štítků → kolize bez návrhu; účet mimo nabídku chybí.
        $untagged = array_column($data['untagged'], null, 'id');
        $this->assertSame('services.banking', $untagged[11]['suggestedTag']);
        $this->assertNull($untagged[12]['suggestedTag']);
        $this->assertNotEmpty($untagged[12]['candidateTags']);
        $this->assertArrayNotHasKey(13, $untagged);
    }

    // ── tag-items ──────────────────────────────────────────────────────────

    public function testTagItemsMergesAndSaves(): void
    {
        $itemRows = [
            11 => ['id' => 11, 'code' => 'BANK', 'name' => 'Poplatky',
                   'item_kind' => 5, 'unit' => 9, 'content_tags' => null],
        ];
        $ctrl = $this->makeController($this->makeDb(), itemRows: $itemRows);
        $resp = $ctrl->tagItems(
            self::postRequest(['items' => [['id' => 11, 'tags' => ['services.banking']]]]),
            self::auth(),
        );

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame([['id' => 11, 'tags' => ['services.banking']]], $data['updated']);
        $this->assertSame([], $data['failed']);
        // Merge-save: celý řádek + content_tags + id (gateway validuje as-is).
        $saved = $this->savedItems[0];
        $this->assertSame(11, $saved['id']);
        $this->assertSame('BANK', $saved['code']);
        $this->assertSame(['services.banking'], $saved['content_tags']);
    }

    public function testTagItemsKeepsExistingTags(): void
    {
        $itemRows = [
            11 => ['id' => 11, 'code' => 'BANK', 'name' => 'Poplatky',
                   'content_tags' => json_encode(['vehicle.fuel'])],
        ];
        $ctrl = $this->makeController($this->makeDb(), itemRows: $itemRows);
        $resp = $ctrl->tagItems(
            self::postRequest(['items' => [['id' => 11, 'tags' => ['services.banking']]]]),
            self::auth(),
        );

        $this->assertSame(
            ['vehicle.fuel', 'services.banking'],
            $resp->getPayload()['data']['updated'][0]['tags'],
        );
    }

    public function testTagItemsRejectsUnknownTagAndMissingItem(): void
    {
        $ctrl = $this->makeController($this->makeDb(), itemRows: []);
        $resp = $ctrl->tagItems(
            self::postRequest(['items' => [
                ['id' => 11, 'tags' => ['nonsense.tag']],
                ['id' => 99, 'tags' => ['services.banking']],
            ]]),
            self::auth(),
        );

        $data = $resp->getPayload()['data'];
        $this->assertSame([], $data['updated']);
        $this->assertSame(
            [
                ['id' => 11, 'reason' => 'unknown_tag'],
                ['id' => 99, 'reason' => 'not_found'],
            ],
            $data['failed'],
        );
        $this->assertSame([], $this->savedItems);
    }
}
