<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
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
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Module\Test\UiHost\RecordsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/** Testovací subclass — podstrčí fake gateway (Dibi\Connection je final). */
class ModuleTabsFormController extends FormController
{
    public ?ModuleTabsSaveGateway $gateway = null;

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
class ModuleTabsSaveGateway extends TableGateway
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    public static function create(): self
    {
        return (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function saveDocument(array $data): DocumentResult
    {
        $this->saved[] = $data;
        return DocumentResult::ok($data);
    }
}

/**
 * Záložka formuláře z modulu (`formTabs`, #172 D17, D19): je na konci
 * formuláře pro PHP třídu, JSONC i automatický formulář, přežije přepočet,
 * poskytovatel rozhoduje `null`, selhání a záložka mimo kontrakt se
 * přeskočí; sloupec z rozšíření tabulky se uloží běžným uložením
 * a v read-only stavu se odmítne jako ostatní pole.
 */
final class FormControllerModuleTabsTest extends TestCase
{
    use UiExtensionFixtures;

    private const MODULE_TAB = 'test.uiExtensions.sales';

    private ModuleTabsFormController $ctrl;

    /** @var array<string, mixed> řádek, který vrací SELECT záznamu */
    private array $row = ['id' => 7, 'name' => 'Záznam', 'kind' => 'main', 'docState' => 10, 'docStateMain' => 1, 'contact_payment' => 0];

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
        $this->ctrl = new ModuleTabsFormController();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    private function config(): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems([
            'core.system.docStatesArchive' => [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'readOnly' => 1, 'goto' => [10]],
            ],
        ]);
    }

    private function db(): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(fn (): array => $this->row);
        return $db;
    }

    private function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', null, true);
    }

    /**
     * Registr formulářů zdroje dat s doplňkem.
     *
     * @param 'php'|'jsonc'|'auto' $path
     */
    private function registry(string $path, ?ModuleUiExtensions $extensions = null): FormRegistry
    {
        $registry = new FormRegistry(
            $path === 'php' ? [['table' => self::UI_TABLE, 'class' => RecordsForm::class]] : [],
        );
        $registry->setLanguage('en');
        $registry->setUiExtensions($extensions ?? $this->uiExtensionsOf());
        return $registry;
    }

    /** @param 'php'|'jsonc'|'auto' $path */
    private function resolver(string $path): ModulePathResolver
    {
        // JSONC formulář leží ve fixturovém modulu; bez kořene modulů
        // zbývá automatický formulář.
        return $path === 'auto' ? new ModulePathResolver([]) : self::uiFixtureResolver();
    }

    /** @param list<array{0: string, 1?: int}> $classes krátké jméno fixturové třídy + volitelné pořadí */
    private function formTabs(array $classes): ModuleUiExtensions
    {
        $registrations = [];
        foreach ($classes as $entry) {
            $reg = ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . $entry[0]];
            if (isset($entry[1])) {
                $reg['order'] = $entry[1];
            }
            $registrations[] = $reg;
        }
        return ModuleUiExtensions::fromModules([ModuleDefinition::fromArray([
            'id' => 'test.uiExtensions',
            'name' => 'addon',
            'formTabs' => $registrations,
        ])]);
    }

    /**
     * @param 'php'|'jsonc'|'auto' $path
     * @return array<string, mixed>
     */
    private function meta(string $path, ?int $id = 7, ?ModuleUiExtensions $extensions = null): array
    {
        $response = $this->ctrl->meta(
            self::UI_TABLE, $id, $this->uiTables(), $this->db(), $this->registry($path, $extensions), $this->config(),
            new LookupRegistry(), $this->resolver($path), 'en', [], $this->admin(),
        );
        $this->assertSame(200, $this->httpStatus($response), json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /**
     * @param 'php'|'jsonc'|'auto' $path
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function recalculate(string $path, array $data): array
    {
        $request = Request::fromArray(
            'POST', '/_ui/form/' . self::UI_TABLE . '/recalculate', [],
            json_encode(['changedColumn' => 'name', 'data' => $data]), ['Content-Type' => 'application/json'],
        );
        $response = $this->ctrl->recalculate(
            self::UI_TABLE, $request, $this->uiTables(), $this->db(), $this->registry($path), $this->config(),
            new LookupRegistry(), $this->resolver($path), 'en', $this->admin(),
        );
        $this->assertSame(200, $this->httpStatus($response), json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /** @param array<string, mixed> $body */
    private function save(array $body): Response
    {
        $request = Request::fromArray(
            'PUT', '/_ui/form/' . self::UI_TABLE . '/save/7', [], json_encode($body), ['Content-Type' => 'application/json'],
        );
        return $this->ctrl->save(
            self::UI_TABLE, 7, $request, $this->uiTables(), $this->db(), $this->config(), $this->registry('php'),
            self::uiFixtureResolver(), new LookupRegistry(), 'en', null, null, $this->admin(), null,
        );
    }

    private function httpStatus(Response $response): int
    {
        return $response->getStatus();
    }

    /**
     * @param array<string, mixed> $payload odpověď meta / recalculate
     * @return list<string>
     */
    private function tabIds(array $payload): array
    {
        return array_column($payload['formDefinition']['tabs'], 'id');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function formPaths(): array
    {
        return [
            'PHP třída'            => ['php', ['basic', 'settings']],
            'JSONC formulář'       => ['jsonc', ['basic']],
            'automatický formulář' => ['auto', ['general']],
        ];
    }

    /**
     * @param 'php'|'jsonc'|'auto' $path
     * @param list<string> $ownTabs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formPaths')]
    public function testModuleTabIsAppendedToEveryKindOfForm(string $path, array $ownTabs): void
    {
        $payload = $this->meta($path);

        $this->assertSame([...$ownTabs, self::MODULE_TAB], $this->tabIds($payload), 'záložka modulu je poslední a nese prefix modulu');

        $tab = $payload['formDefinition']['tabs'][count($ownTabs)];
        $this->assertSame('Fixture sales', $tab['label']);
        $this->assertSame('fields', $tab['type']);

        $elements = $tab['sections'][0]['columns'][0]['elements'];
        $this->assertSame('contact_payment', $elements[0]['column']);
        $this->assertSame('Contact payment', $elements[0]['label'], 'popisek ze sloupce rozšíření tabulky');
        $this->assertSame('checkbox', $elements[0]['input_type']);
        $this->assertSame('separator', $elements[1]['type']);

        $this->assertArrayHasKey('contact_payment', $payload['data'], 'hodnota sloupce z rozšíření jde s daty formuláře');
        $this->assertSame([], $this->uiExtensionFailures());
    }

    /**
     * @param 'php'|'jsonc'|'auto' $path
     * @param list<string> $ownTabs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formPaths')]
    public function testModuleTabIsAppendedToNewRecordForm(string $path, array $ownTabs): void
    {
        $payload = $this->meta($path, null);

        $this->assertSame([...$ownTabs, self::MODULE_TAB], $this->tabIds($payload));
        $this->assertSame(0, $payload['data']['contact_payment'], 'výchozí hodnota sloupce z rozšíření');
    }

    /**
     * @param 'php'|'jsonc'|'auto' $path
     * @param list<string> $ownTabs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('formPaths')]
    public function testModuleTabSurvivesRecalculate(string $path, array $ownTabs): void
    {
        $payload = $this->recalculate($path, ['contact_payment' => 1] + $this->row);

        $this->assertSame([...$ownTabs, self::MODULE_TAB], $this->tabIds($payload));
        $this->assertSame(1, $payload['data']['contact_payment'], 'přepočet hodnotu záložky modulu nemění');
    }

    public function testProviderDecidesPerRecordAlsoOnRecalculate(): void
    {
        $this->row['kind'] = 'skip';

        $this->assertSame(['basic', 'settings'], $this->tabIds($this->meta('php')));
        $this->assertSame(['basic', 'settings'], $this->tabIds($this->recalculate('php', $this->row)));
        // Změna druhu ve formuláři záložku při přepočtu vrátí.
        $this->assertSame(
            ['basic', 'settings', self::MODULE_TAB],
            $this->tabIds($this->recalculate('php', ['kind' => 'main'] + $this->row)),
        );
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testFormWithoutActiveAddonHasNoModuleTab(): void
    {
        $payload = $this->meta('php', 7, $this->uiExtensionsOf(['test.uiHost']));

        $this->assertSame(['basic', 'settings'], $this->tabIds($payload));
    }

    public function testModuleTabsAreOrderedByOrder(): void
    {
        $payload = $this->meta('php', 7, $this->formTabs([['FixtureFormTab'], ['SecondFormTab', 100]]));

        $this->assertSame(
            ['basic', 'settings', 'test.uiExtensions.second', self::MODULE_TAB],
            $this->tabIds($payload),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function failingProviders(): array
    {
        return [
            'výjimka poskytovatele'  => ['ThrowingFormTab', 'form tab provider broke'],
            'lookup v záložce'       => ['LookupFormTab', "contains element 'lookup'"],
            'multilookup v záložce'  => ['MultiLookupFormTab', "contains element 'multilookup'"],
            'záložka typu subtable'  => ['SubtableFormTab', "must be of type 'fields'"],
            'systémový sloupec'      => ['SystemColumnFormTab', "edits 'docState', which is not a writable column"],
            'sloupec mimo tabulku'   => ['UnknownColumnFormTab', "edits 'no_such_column', which is not a writable column"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingProviders')]
    public function testFailingProviderIsLoggedAndFormIsRenderedWithoutIt(string $class, string $message): void
    {
        $payload = $this->meta('php', 7, $this->formTabs([[$class, 100], ['FixtureFormTab']]));

        $this->assertSame(['basic', 'settings', self::MODULE_TAB], $this->tabIds($payload));

        $failures = $this->uiExtensionFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('test.uiExtensions', $failures[0]['module']);
        $this->assertSame(self::UI_NAMESPACE . $class, $failures[0]['class']);
        $this->assertSame(self::UI_TABLE, $failures[0]['table']);
        $this->assertSame(ModuleUiExtensions::FORM_TABS, $failures[0]['kind']);
        $this->assertStringContainsString($message, $failures[0]['message']);
    }

    public function testExtensionColumnFromModuleTabIsSavedByRegularSave(): void
    {
        $this->ctrl->gateway = ModuleTabsSaveGateway::create();

        $response = $this->save(['name' => 'Záznam', 'contact_payment' => 1]);

        $this->assertSame(200, $this->httpStatus($response), json_encode($response->getPayload()));
        $this->assertCount(1, $this->ctrl->gateway->saved);
        $this->assertSame(1, $this->ctrl->gateway->saved[0]['contact_payment']);
        $this->assertSame(7, $this->ctrl->gateway->saved[0]['id']);
    }

    public function testExtensionColumnIsRejectedInReadOnlyStateLikeOtherFields(): void
    {
        $this->ctrl->gateway = ModuleTabsSaveGateway::create();
        $this->row['docState'] = 40;

        foreach ([['contact_payment' => 1], ['name' => 'Jiný název']] as $body) {
            $response = $this->save($body);

            $this->assertSame(422, $this->httpStatus($response));
            $this->assertSame('DOCUMENT_READONLY', $response->getPayload()['error']['code']);
        }
        $this->assertSame([], $this->ctrl->gateway->saved, 'nic se neuložilo');

        // Formulář v read-only stavu hlásí read_only celý — včetně záložky modulu.
        $payload = $this->meta('php');
        $this->assertTrue($payload['formDefinition']['doc_states']['read_only']);
        $this->assertArrayNotHasKey('editable_columns', $payload['formDefinition']['doc_states']);
        $this->assertContains(self::MODULE_TAB, $this->tabIds($payload));
    }
}
