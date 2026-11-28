<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\Rows;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowDocument;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowLockProvider;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\AbstractPrescriptionRowsForm;
use Shipard\Module\Economy\PeriodicInvoicing\Rows\PrescriptionRowsSubtable;

/** Formulář řádku nad fake rodičem; `$template` = šablona na druhu (bez záznamu a platnosti). */
final class FakePrescriptionRowsForm extends AbstractPrescriptionRowsForm
{
    /** @var array{head: array<string, mixed>|null, kind: array<string, mixed>|null}|null */
    public ?array $parent = null;
    public bool $template = false;
    /** @var list<int> */
    public array $loaded = [];

    protected function parentColumn(): string
    {
        return 'parent';
    }

    protected function loadParentHeadAndKind(int $parentId): ?array
    {
        $this->loaded[] = $parentId;
        return $this->parent;
    }

    protected function rowTitle(): string
    {
        return 'Fake row';
    }

    protected function rowTitleNew(): string
    {
        return 'New fake row';
    }

    protected function hasValidity(): bool
    {
        return !$this->template;
    }

    protected function unitOptions(): array
    {
        return [];
    }
}

final class FakePrescriptionRowDocument extends AbstractPrescriptionRowDocument
{
    public bool $validity = true;
    /** @var list<array<string, mixed>> */
    public array $parentChecks = [];
    /** @var list<int> */
    public array $orderPosCalls = [];

    protected function rowsTable(): string
    {
        return 'fake_rows';
    }

    protected function parentColumn(): string
    {
        return 'parent';
    }

    protected function parentRequiredMessage(): string
    {
        return 'Row needs a parent.';
    }

    protected function hasValidity(): bool
    {
        return $this->validity;
    }

    protected function validateAgainstParent(array $data, ValidationResult $result): void
    {
        $this->parentChecks[] = $data;
        if (($data['description'] ?? null) === 'rejected') {
            $result->addError('description', 'Parent says no.', 'parent_rule');
        }
    }

    protected function nextOrderPos(int $parentId): int
    {
        $this->orderPosCalls[] = $parentId;
        return 7;
    }
}

final class FakePrescriptionRowLockProvider extends AbstractPrescriptionRowLockProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $parents = [];
    /** @var list<list<int>> */
    public array $queries = [];

    public function withDb(): self
    {
        $this->db = (new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor();
        return $this;
    }

    protected function parentTable(): string
    {
        return 'fake_kinds';
    }

    protected function parentColumn(): string
    {
        return 'parent';
    }

    protected function docStatesCfgItem(): string
    {
        return 'core.system.docStatesArchive';
    }

    protected function subjectTableId(): int
    {
        return 999;
    }

    protected function source(): string
    {
        return 'fake_parent_state';
    }

    protected function lockTitle(string $label): string
    {
        return "Parent {$label} is read-only.";
    }

    protected function lockMessage(string $stateName): string
    {
        return "State: {$stateName}.";
    }

    protected function parentLabel(array $parent, int $id): string
    {
        return trim((string) ($parent['name'] ?? '')) ?: parent::parentLabel($parent, $id);
    }

    protected function loadParents(array $ids): array
    {
        $this->queries[] = $ids;
        return array_intersect_key($this->parents, array_flip($ids));
    }
}

/**
 * Společný základ řádku předpisu (#174 D31) nad fake rodičem: šablona na
 * druhu nemá platnost a typ dokladu bere jen z druhu, dokument volá pravidla
 * rodiče jen u řádku s rodičem, zámek čte stavy z cfgItem rodiče
 * a sub-tabulka umí sadu sloupců s platností i bez.
 */
final class PrescriptionRowsBaseTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../..';

    private const OPERATIONS = [
        'sale.service' => ['name' => 'Prodej služby', 'docTypes' => ['invno' => ['order' => 2]]],
        'sale.goods'   => ['name' => 'Prodej zboží', 'docTypes' => ['invno' => ['order' => 1], 'invpo' => ['order' => 1]]],
        'advance'      => ['name' => 'Záloha', 'docTypes' => ['invpo' => ['order' => 2]]],
    ];

    /** Definice tabulky řádků zakázek s přejmenovaným rodičem; šablona bez sloupců platnosti. */
    private function rowsTable(bool $template): TableDefinition
    {
        $def = JsoncParser::parseFile(self::ROOT . '/modules/economy/workOrders/tables/economy_work_orders_rows.jsonc');
        $columns = [];
        foreach ($def['columns'] as $column) {
            if ($template && in_array($column['id'], ['valid_from', 'valid_to'], true)) {
                continue;
            }
            if ($column['id'] === 'work_order') {
                $column['id'] = 'parent';
                unset($column['reference']);
            }
            $columns[] = $column;
        }
        $def['columns'] = $columns;
        $def['indexes'] = [];
        return TableDefinition::fromArray($def);
    }

    private function form(bool $template, ?array $parent): FakePrescriptionRowsForm
    {
        $form = new FakePrescriptionRowsForm('fake_rows');
        $form->template = $template;
        $form->parent = $parent;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([['docs.core.rowOperations', self::OPERATIONS]]);
        $form->setConfig($config);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn('cz');
        $form->setDb($db);
        $form->setTableDef($this->rowsTable($template));
        return $form;
    }

    /** @return list<string> sloupce prvků formuláře */
    private function columns(FormDefinition $def): array
    {
        return array_values(array_filter(array_map(
            static fn($element): ?string => $element->column,
            $def->tabs[0]->sections[0]->columns[0]->elements,
        )));
    }

    private function options(FormDefinition $def, string $column): array
    {
        foreach ($def->tabs[0]->sections[0]->columns[0]->elements as $element) {
            if ($element->column === $column) {
                return array_column($element->options, 'value');
            }
        }
        $this->fail("Element {$column} not found");
    }

    public function testRecordRowHasValidityAndTitlesFromTheDescendant(): void
    {
        $form = $this->form(false, ['head' => ['inv_doc_type' => 'invno'], 'kind' => ['inv_doc_type' => 'invpo']]);
        $def = $form->buildFormDefinition(['parent' => 5], true);

        $this->assertSame('Fake row', $def->title);
        $this->assertSame('New fake row', $def->titleNew);
        $this->assertContains('valid_from', $this->columns($def));
        $this->assertContains('valid_to', $this->columns($def));
        $this->assertSame([5], $form->loaded);
        // Přepis na záznamu vyhrává nad druhem.
        $this->assertSame(['sale.goods', 'sale.service'], $this->options($def, 'operation'));
    }

    public function testTemplateRowHasNoValidityAndTakesDocTypeFromTheKind(): void
    {
        $form = $this->form(true, ['head' => null, 'kind' => ['inv_doc_type' => 'invpo']]);
        $def = $form->buildFormDefinition(['parent' => 3], true);

        $this->assertNotContains('valid_from', $this->columns($def));
        $this->assertNotContains('valid_to', $this->columns($def));
        $this->assertContains('contributor', $this->columns($def));
        $this->assertSame(['sale.goods', 'advance'], $this->options($def, 'operation'));
    }

    public function testWithoutParentOrDocTypeTheOffersAreEmpty(): void
    {
        $form = $this->form(true, ['head' => null, 'kind' => []]);
        $this->assertSame([], $this->options($form->buildFormDefinition(['parent' => 3], true), 'operation'));

        $form = $this->form(false, null);
        $def = $form->buildFormDefinition([], true);
        $this->assertSame([], $this->options($def, 'operation'));
        $this->assertSame([], $form->loaded);
    }

    /** @return list<string> column:code */
    private function codes(FakePrescriptionRowDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testDocumentValidatesShapeAndAsksTheParentOnlyWithParent(): void
    {
        $doc = new FakePrescriptionRowDocument();
        $this->assertSame(['parent:required'], $this->codes($doc, ['description' => 'rejected']));
        $this->assertSame([], $doc->parentChecks);
        $empty = [];
        $this->assertSame('Row needs a parent.', $doc->validate($empty)->toArray()[0]['message']);

        $this->assertSame(
            ['valid_to:invalid_range', 'description:parent_rule'],
            $this->codes($doc, ['parent' => 2, 'description' => 'rejected', 'valid_from' => '2026-10-01', 'valid_to' => '2026-09-30']),
        );
        $this->assertCount(1, $doc->parentChecks);
    }

    public function testTemplateDocumentIgnoresValidity(): void
    {
        $doc = new FakePrescriptionRowDocument();
        $doc->validity = false;
        $this->assertSame([], $this->codes($doc, ['parent' => 2, 'valid_from' => '2026-10-01', 'valid_to' => '2026-09-30']));
    }

    public function testNewRowIsOrderedWithinItsParent(): void
    {
        $doc = new FakePrescriptionRowDocument();
        $data = ['parent' => 4, 'description' => ' Nájem ', 'vat_code' => ''];
        $doc->beforeSave($data, null);
        $this->assertSame(7, $data['order_pos']);
        $this->assertSame([4], $doc->orderPosCalls);
        $this->assertSame('Nájem', $data['description']);
        $this->assertNull($data['vat_code']);

        $update = ['id' => 1, 'parent' => 4, 'order_pos' => 0];
        $doc->beforeSave($update, ['id' => 1]);
        $this->assertSame([4], $doc->orderPosCalls);
    }

    private function provider(bool $withStates = true): FakePrescriptionRowLockProvider
    {
        $provider = (new FakePrescriptionRowLockProvider())->withDb();
        $provider->parents = [
            1 => ['name' => 'Koncept druhu', 'docState' => 10],
            2 => ['name' => 'Druh v opravě', 'docState' => 80],
            3 => ['name' => 'Potvrzený druh', 'docState' => 40],
            4 => ['name' => '', 'docState' => 70],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $withStates && $id === 'core.system.docStatesArchive'
                ? JsoncParser::parseFile(self::ROOT . '/modules/core/system/config/docStatesArchive.jsonc')
                : null,
        );
        $provider->setConfig($config);
        return $provider;
    }

    public function testLockFollowsReadOnlyStatesOfTheParentCfgItem(): void
    {
        $provider = $this->provider();
        $this->assertSame([], $provider->lockReasons('fake_rows', ['parent' => 1], null));
        $this->assertSame([], $provider->lockReasons('fake_rows', ['parent' => 2], ['parent' => 2]));

        $reasons = $provider->lockReasons('fake_rows', ['parent' => 3], null);
        $this->assertCount(1, $reasons);
        $this->assertSame('fake_parent_state', $reasons[0]->source);
        $this->assertSame('Parent Potvrzený druh is read-only.', $reasons[0]->title);
        $this->assertSame(999, $reasons[0]->subjectTableId);
        $this->assertSame(3, $reasons[0]->subjectRowId);
        $this->assertSame(40, $reasons[0]->params['docState']);

        // Rodič bez popisku → `#id`; hlídá se původní i nový rodič.
        $moved = $provider->lockReasons('fake_rows', ['parent' => 1], ['parent' => 4]);
        $this->assertCount(1, $moved);
        $this->assertSame('Parent #4 is read-only.', $moved[0]->title);
    }

    public function testLockIsSilentWithoutStatesParentOrDb(): void
    {
        $this->assertSame([], $this->provider(false)->lockReasons('fake_rows', ['parent' => 3], null));

        $provider = $this->provider();
        $this->assertSame([], $provider->lockReasons('fake_rows', [], null));
        $this->assertSame([], $provider->queries);

        $this->assertSame([], (new FakePrescriptionRowLockProvider())->lockReasons('fake_rows', ['parent' => 3], null));
    }

    public function testSubtableRendersWithAndWithoutValidity(): void
    {
        $rows = [[
            'id' => 1, 'description' => 'Nájem', 'quantity' => '1.0000', 'unit' => 1, 'unit_price' => '12000.0000',
            'vat_code' => 'out21', 'valid_from' => '2026-08-01', 'valid_to' => null,
        ]];
        $label = static fn(string $column, string $fallback): string => strtoupper($column);

        $full = PrescriptionRowsSubtable::render($rows, $label, [1 => 'měs'], 'cs');
        $this->assertSame(
            ['description', 'quantity', 'unit', 'unit_price', 'vat_code', 'valid_from', 'valid_to'],
            array_column($full['columns'], 'id'),
        );
        $this->assertSame('UNIT_PRICE', $full['columns'][3]['label']);
        $this->assertSame('order_pos', $full['order_column']);
        $this->assertSame('01.08.2026', $full['rows'][0]['cells']['valid_from']);
        $this->assertSame('', $full['rows'][0]['cells']['valid_to']);
        $this->assertSame('měs', $full['rows'][0]['cells']['unit']);

        $template = PrescriptionRowsSubtable::render($rows, $label, [], 'cs', withValidity: false);
        $this->assertSame(
            ['description', 'quantity', 'unit', 'unit_price', 'vat_code'],
            array_column($template['columns'], 'id'),
        );
        $this->assertSame(['description', 'quantity', 'unit', 'unit_price', 'vat_code'], array_keys($template['rows'][0]['cells']));
        $this->assertSame('', $template['rows'][0]['cells']['unit']);
    }

    public function testUnitShortcutsFallBackToTheName(): void
    {
        $this->assertSame([], PrescriptionRowsSubtable::unitShortcuts(null));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'měsíc', 'shortcut' => 'měs'],
            ['id' => 2, 'name' => 'paušál', 'shortcut' => ''],
        ]);
        $this->assertSame([1 => 'měs', 2 => 'paušál'], PrescriptionRowsSubtable::unitShortcuts($db));
    }
}
