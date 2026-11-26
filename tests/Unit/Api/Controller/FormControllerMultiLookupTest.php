<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Controller\LookupController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Module\Test\MultiLookup\ParentsForm;
use Shipard\Module\Test\MultiLookup\TargetsLookup;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/** Testovací subclass — podstrčí fake gateway (Dibi\Connection je final). */
class MultiLookupFormController extends FormController
{
    public ?MultiLookupSaveGateway $gateway = null;

    protected function buildGateway(
        string $table,
        TableDefinition $def,
        DataSourceConnection $db,
        DocumentRegistry $documentRegistry,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        ?DocumentEventDispatcher $eventDispatcher,
    ): TableGateway {
        return $this->gateway ?? throw new \LogicException('gateway not set');
    }
}

/** Fake gateway — zaznamená ukládaná data. */
class MultiLookupSaveGateway extends TableGateway
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    /** Volá se v okamžiku uložení — test si jím zachytí, co se stalo před ním. */
    public ?\Closure $onSave = null;

    public static function create(): self
    {
        return (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function saveDocument(array $data): DocumentResult
    {
        $this->saved[] = $data;
        ($this->onSave)?->__invoke();
        return DocumentResult::ok($data + ['id' => 7]);
    }
}

/**
 * Prvek `multilookup` ve `FormController` (#176 D1–D5, D9) nad fixturovým
 * modulem `test.multiLookup`: meta a odpověď uložení nesou seznam id
 * a `dataResolved` jako seznam položek v pořadí hodnoty, přepočet seznam
 * předá formuláři, uložení ho převede na řádky child tabulky a klíč pustí
 * jen u formuláře s prvkem.
 */
final class FormControllerMultiLookupTest extends TestCase
{
    use UiExtensionFixtures;

    private const TABLE = 'test_multilookup_parents';
    private const LINKS = 'test_multilookup_links';
    private const MODULES = ['test.multiLookup'];

    private MultiLookupFormController $ctrl;

    /** @var array<string, mixed> řádek rodiče, který vrací SELECT záznamu */
    private array $parent = ['id' => 7, 'name' => 'Parent', 'kind' => null];

    /** @var list<array<string, mixed>> řádky child tabulky rodiče 7 */
    private array $links = [
        ['id' => 100, 'parent' => 7, 'target' => 1, 'order_pos' => 2, 'note' => 'first'],
        ['id' => 101, 'parent' => 7, 'target' => 2, 'order_pos' => 3, 'note' => 'second'],
        ['id' => 102, 'parent' => 7, 'target' => 3, 'order_pos' => 1, 'note' => 'third'],
    ];

    /** @var array<int, array{name: string, kind: ?string}> cílové záznamy */
    private array $targets = [
        1 => ['name' => 'Alpha', 'kind' => 'a'],
        2 => ['name' => 'Beta', 'kind' => 'b'],
        3 => ['name' => 'Gamma', 'kind' => null],
        9 => ['name' => 'Omega', 'kind' => 'a'],
    ];

    /** @var list<list<mixed>> dotazy na řádky child tabulky a lookup */
    private array $queries = [];

    /** PHP třída formuláře (`ParentsForm`), nebo JSONC formulář fixturového modulu. */
    private bool $phpForm = true;

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
        $this->ctrl = new MultiLookupFormController();
        $this->ctrl->gateway = MultiLookupSaveGateway::create();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    /** @return array<string, TableDefinition> */
    private function tables(): array
    {
        return $this->uiTables(self::MODULES);
    }

    private function db(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(fn (): array => $this->parent);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args): array {
            $this->queries[] = $args;
            $sql = (string) $args[0];
            if (str_contains($sql, TargetsLookup::TABLE)) {
                // TargetsLookup::resolve — sestupně podle id, neznámé id chybí.
                $ids = array_filter($args, 'is_array')[1] ?? [];
                rsort($ids);
                $rows = [];
                foreach ($ids as $id) {
                    if (isset($this->targets[$id])) {
                        $rows[] = ['id' => $id] + $this->targets[$id];
                    }
                }
                return $rows;
            }
            // Řádky child tabulky: SELECT %n FROM %n WHERE %n = %i
            [, $columns, $table, $foreignKey, $parentId] = $args;
            $this->assertSame(self::LINKS, $table);
            $this->assertSame('parent', $foreignKey);
            $this->assertSame(['id', 'target', 'order_pos'], $columns);
            return array_values(array_filter(
                $this->links,
                static fn (array $row): bool => $row['parent'] === $parentId,
            ));
        });
        return $db;
    }

    private function config(): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems([
            'core.system.formDefaults' => [
                'multiLookupInvalid' => ['name' => 'Hodnota musí být seznam záznamů.'],
                'multiLookupDuplicate' => ['name' => 'Záznam {name} je vybraný víckrát.'],
            ],
        ]);
    }

    private function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', null, true);
    }

    private function forms(): FormRegistry
    {
        $registry = new FormRegistry($this->phpForm ? [['table' => self::TABLE, 'class' => ParentsForm::class]] : []);
        $registry->setLanguage('en');
        return $registry;
    }

    private function lookups(): LookupRegistry
    {
        $registry = new LookupRegistry();
        $registry->register(TargetsLookup::TABLE, TargetsLookup::class);
        return $registry;
    }

    /** @return array<string, mixed> */
    private function meta(?int $id = 7): array
    {
        $response = $this->ctrl->meta(
            self::TABLE, $id, $this->tables(), $this->db(), $this->forms(), $this->config(),
            $this->lookups(), self::uiFixtureResolver(), 'en', [], $this->admin(),
        );
        $this->assertSame(200, $response->getStatus(), (string) json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function recalculate(array $data): array
    {
        $request = Request::fromArray(
            'POST', '/_ui/form/' . self::TABLE . '/recalculate', [],
            (string) json_encode(['changedColumn' => 'kind', 'data' => $data]), ['Content-Type' => 'application/json'],
        );
        $response = $this->ctrl->recalculate(
            self::TABLE, $request, $this->tables(), $this->db(), $this->forms(), $this->config(),
            $this->lookups(), self::uiFixtureResolver(), 'en', $this->admin(),
        );
        $this->assertSame(200, $response->getStatus(), (string) json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /** @param array<string, mixed> $body */
    private function save(array $body, ?int $id = 7): Response
    {
        $request = Request::fromArray(
            $id === null ? 'POST' : 'PUT', '/_ui/form/' . self::TABLE . '/save', [],
            (string) json_encode($body), ['Content-Type' => 'application/json'],
        );
        return $this->ctrl->save(
            self::TABLE, $id, $request, $this->tables(), $this->db(), $this->config(), $this->forms(),
            self::uiFixtureResolver(), $this->lookups(), 'en', null, null, $this->admin(), null,
        );
    }

    /** @return array<string, mixed> prvek `targets` z definice formuláře v odpovědi */
    private function targetsElement(array $payload): ?array
    {
        foreach ($payload['formDefinition']['tabs'][0]['sections'][0]['columns'][0]['elements'] as $element) {
            if (($element['column'] ?? null) === 'targets') {
                return $element;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function savedData(): array
    {
        $this->assertCount(1, $this->ctrl->gateway->saved, 'gateway dostala právě jedno uložení');
        return $this->ctrl->gateway->saved[0];
    }

    // ── meta ────────────────────────────────────────────────────────────────

    public function testMetaCarriesIdsInOrderAndResolvedList(): void
    {
        $payload = $this->meta();

        $this->assertSame([3, 1, 2], $payload['data']['targets'], 'pořadí podle order_pos, ne podle id řádku');
        $this->assertSame(
            [
                ['id' => 3, 'primary' => 'Gamma', 'secondary' => null],
                ['id' => 1, 'primary' => 'Alpha', 'secondary' => 'a'],
                ['id' => 2, 'primary' => 'Beta', 'secondary' => 'b'],
            ],
            $payload['dataResolved']['targets'],
            'dataResolved je seznam v pořadí hodnoty — lookup vrací sestupně podle id',
        );

        $element = $this->targetsElement($payload);
        $this->assertSame('multilookup', $element['type']);
        $this->assertSame(['table' => TargetsLookup::TABLE, 'filter' => null], $element['lookup']);
    }

    public function testMetaOmitsUnresolvedTargetFromResolvedList(): void
    {
        $this->links[] = ['id' => 103, 'parent' => 7, 'target' => 55, 'order_pos' => 4, 'note' => 'gone'];

        $payload = $this->meta();

        $this->assertSame([3, 1, 2, 55], $payload['data']['targets']);
        $this->assertSame([3, 1, 2], array_column($payload['dataResolved']['targets'], 'id'));
    }

    public function testMetaOfNewRecordHasEmptyList(): void
    {
        $payload = $this->meta(null);

        $this->assertSame([], $payload['data']['targets']);
        $this->assertSame([], $payload['dataResolved']['targets']);
        $this->assertSame([], $this->queries, 'nový záznam se na child tabulku ani lookup neptá');
    }

    public function testMetaOfFormWithoutElementDoesNotCarryTheKey(): void
    {
        $this->parent['kind'] = ParentsForm::KIND_PLAIN;

        $payload = $this->meta();

        $this->assertNull($this->targetsElement($payload));
        $this->assertArrayNotHasKey('targets', $payload['data']);
        $this->assertArrayNotHasKey('targets', $payload['dataResolved']);
        $this->assertSame([], $this->queries);
    }

    // ── přepočet ────────────────────────────────────────────────────────────

    public function testRecalculatePassesListToFormAndResolvesIt(): void
    {
        $payload = $this->recalculate(['id' => 7, 'name' => 'Parent', 'kind' => 'a', 'targets' => [9, 1]]);

        $element = $this->targetsElement($payload);
        $this->assertSame('Selected: 2', $element['hint'], 'formulář při přepočtu seznam dostal');
        $this->assertSame(['table' => TargetsLookup::TABLE, 'filter' => ['kind' => 'a']], $element['lookup']);

        $this->assertSame([9, 1], $payload['data']['targets']);
        $this->assertSame(['Omega', 'Alpha'], array_column($payload['dataResolved']['targets'], 'primary'));
    }

    public function testRecalculateWithEmptyListResolvesToEmptyList(): void
    {
        $payload = $this->recalculate(['id' => 7, 'name' => 'Parent', 'kind' => null, 'targets' => []]);

        $this->assertSame([], $payload['dataResolved']['targets'], 'seznam přichází vždy celý, i prázdný');
    }

    // ── uložení ─────────────────────────────────────────────────────────────

    public function testSaveConvertsListToChildRows(): void
    {
        $response = $this->save(['name' => 'Parent', 'kind' => null, 'targets' => [2, 9, 3]]);

        $this->assertSame(200, $response->getStatus(), (string) json_encode($response->getPayload()));
        $this->assertSame(
            [
                ['id' => 101, 'target' => 2, 'order_pos' => 1],
                ['target' => 9, 'order_pos' => 2],
                ['id' => 102, 'target' => 3, 'order_pos' => 3],
            ],
            $this->savedData()['targets'],
            'stejný odkaz si nechá id řádku, nový je bez id, chybějící (1) vypadne; sloupec navíc se neposílá',
        );
        $this->assertSame(7, $this->savedData()['id']);
    }

    public function testSaveResponseCarriesListAndResolvedList(): void
    {
        $data = $this->save(['name' => 'Parent', 'targets' => [3, 1, 2]])->getPayload()['data'];

        $this->assertSame([3, 1, 2], $data['data']['targets']);
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($data['dataResolved']['targets'], 'primary'));
    }

    public function testSaveEmptyListRemovesAllRows(): void
    {
        $this->save(['name' => 'Parent', 'targets' => []]);

        $saved = $this->savedData();
        $this->assertArrayHasKey('targets', $saved);
        $this->assertSame([], $saved['targets'], 'prázdný seznam = smazat vše');
    }

    public function testSaveWithoutKeyLeavesChildSetUntouched(): void
    {
        $queriesBeforeSave = null;
        $this->ctrl->gateway->onSave = function () use (&$queriesBeforeSave): void {
            $queriesBeforeSave = $this->queries;
        };

        $this->save(['name' => 'Renamed']);

        $this->assertArrayNotHasKey('targets', $this->savedData(), 'chybějící klíč ≠ prázdný seznam');
        $this->assertSame([], $queriesBeforeSave, 'bez klíče v těle se před uložením child tabulka nečte');
    }

    public function testSaveOfNewRecordInsertsRowsWithoutIds(): void
    {
        $response = $this->save(['name' => 'New', 'targets' => ['9', 1]], null);

        $this->assertSame(201, $response->getStatus(), (string) json_encode($response->getPayload()));
        $saved = $this->savedData();
        $this->assertSame([['target' => 9, 'order_pos' => 1], ['target' => 1, 'order_pos' => 2]], $saved['targets']);
        $this->assertArrayNotHasKey('id', $saved);
    }

    public function testSaveRejectsDuplicateAndNamesTheRecord(): void
    {
        $response = $this->save(['name' => 'Parent', 'targets' => [3, 1, 3]]);

        $this->assertSame(422, $response->getStatus());
        $error = $response->getPayload()['error'];
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame(
            [['field' => 'targets', 'code' => 'duplicate', 'message' => 'Záznam Gamma je vybraný víckrát.']],
            $error['details'],
        );
        $this->assertSame([], $this->ctrl->gateway->saved, 'gateway se nevolá');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidValues(): array
    {
        return [
            'null' => [null],
            'text' => ['1,2'],
            'číslo' => [5],
            'objekt' => [['a' => 1]],
            'nula' => [[0]],
            'záporné id' => [[-1]],
            'řádky místo id' => [[['target' => 1]]],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testSaveRejectsValueThatIsNotListOfIds(mixed $value): void
    {
        $response = $this->save(['name' => 'Parent', 'targets' => $value]);

        $this->assertSame(422, $response->getStatus());
        $this->assertSame(
            [['field' => 'targets', 'code' => 'invalid_list', 'message' => 'Hodnota musí být seznam záznamů.']],
            $response->getPayload()['error']['details'],
        );
        $this->assertSame([], $this->ctrl->gateway->saved);
    }

    public function testSaveDropsKeyOfReadOnlyElement(): void
    {
        $this->parent['kind'] = ParentsForm::KIND_LOCKED;

        $response = $this->save(['name' => 'Parent', 'kind' => ParentsForm::KIND_LOCKED, 'targets' => [9]]);

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayNotHasKey('targets', $this->savedData(), 'prvek jen ke čtení klíč nepropustí');
    }

    public function testSaveDropsKeyWhenFormHasNoElement(): void
    {
        $this->parent['kind'] = ParentsForm::KIND_PLAIN;

        $response = $this->save(['name' => 'Parent', 'targets' => []]);

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayNotHasKey('targets', $this->savedData(), 'formulář bez prvku vazby nesmaže');
    }

    /** Definice se při uložení staví nad uloženým řádkem překrytým tělem — rozhoduje stav, který vidí klient. */
    public function testSaveUsesFormDefinitionOfSubmittedState(): void
    {
        $this->parent['kind'] = ParentsForm::KIND_LOCKED;

        $this->save(['name' => 'Parent', 'kind' => 'a', 'targets' => [9]]);

        $this->assertSame([['target' => 9, 'order_pos' => 1]], $this->savedData()['targets']);
    }

    public function testEnglishFallbackWithoutConfiguration(): void
    {
        $request = Request::fromArray(
            'PUT', '/_ui/form/' . self::TABLE . '/save', [],
            (string) json_encode(['name' => 'Parent', 'targets' => [1, 1]]), ['Content-Type' => 'application/json'],
        );
        $response = $this->ctrl->save(
            self::TABLE, 7, $request, $this->tables(), $this->db(), null, $this->forms(),
            self::uiFixtureResolver(), $this->lookups(), 'en', null, null, $this->admin(), null,
        );

        $this->assertSame('Alpha is selected more than once.', $response->getPayload()['error']['details'][0]['message']);
    }

    // ── JSONC formulář ──────────────────────────────────────────────────────

    public function testJsoncFormLoadsRecalculatesAndSavesList(): void
    {
        $this->phpForm = false;

        $meta = $this->meta();
        $element = $this->targetsElement($meta);
        $this->assertSame('multilookup', $element['type']);
        $this->assertSame('Targets', $element['label']);
        $this->assertSame(['table' => TargetsLookup::TABLE, 'filter' => null], $element['lookup'], 'refColumn se klientovi neposílá');
        $this->assertSame([3, 1, 2], $meta['data']['targets']);
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($meta['dataResolved']['targets'], 'primary'));

        $recalculated = $this->recalculate(['id' => 7, 'name' => 'Parent', 'targets' => [2]]);
        $this->assertSame(['Beta'], array_column($recalculated['dataResolved']['targets'], 'primary'));

        $this->save(['name' => 'Parent', 'targets' => [1, 9]]);
        $this->assertSame(
            [['id' => 100, 'target' => 1, 'order_pos' => 1], ['target' => 9, 'order_pos' => 2]],
            $this->savedData()['targets'],
        );
    }

    // ── filtr lookupu ───────────────────────────────────────────────────────

    /** Filtr prvku jde stejným endpointem jako u `lookup`: projde jen klíč z whitelistu lookupu. */
    public function testLookupSearchKeepsOnlyWhitelistedFilterKeys(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$captured): array {
            $captured = $args;
            return [['id' => 9, 'name' => 'Omega', 'kind' => 'a']];
        });
        $request = Request::fromArray(
            'GET', '/_ui/lookup/' . TargetsLookup::TABLE . '/search',
            ['q' => '', 'filter' => ['kind' => 'a', 'name' => 'Alpha', 'id' => '1']], '', [],
        );

        $response = (new LookupController())->search(
            TargetsLookup::TABLE, $request, $this->admin(), $this->tables(), $db, $this->lookups(), null,
        );

        $this->assertSame(200, $response->getStatus(), (string) json_encode($response->getPayload()));
        $this->assertSame([9], array_column($response->getPayload()['data']['items'], 'id'));
        $this->assertStringContainsString('`kind` = %s', (string) $captured[0]);
        $this->assertContains('a', $captured);
        $this->assertNotContains('Alpha', $captured, 'klíč mimo whitelist se do hledání nedostane');
    }
}
