<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration\ContactPayment;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Form\ModuleFormTabs;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentFormTab;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentPolicy;
use Shipard\Module\Economy\SalesRegistration\Evaluation\RegistrationFacts;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Tests\Unit\Module\Economy\SalesRegistration\SalesRegistrationConfig;

/**
 * Záložka Evidence tržeb ve formuláři dokladu (#171 D8b, U16): zaškrtávátko
 * Kontaktní platba a čas platby jen u typů dokladů z dat typu evidence
 * (faktura vydaná, zálohová faktura vydaná) a jen s registrací V pořádku.
 */
class ContactPaymentFormTabTest extends TestCase
{
    use SalesRegistrationConfig;

    private const ROOT = __DIR__ . '/../../../../../../modules';

    private function headsDefinition(): TableDefinition
    {
        $table = TableDefinition::fromArray(JsoncParser::parseFile(self::ROOT . '/docs/core/tables/docs_core_heads.jsonc'));
        $extension = ExtensionDefinition::fromArray(
            JsoncParser::parseFile(self::ROOT . '/economy/salesRegistration/extensions/docs_core_heads.jsonc'),
        );
        return TableMerger::merge($table, $extension);
    }

    private function tab(bool $withRegistration = true): ContactPaymentFormTab
    {
        $registrations = [];
        if ($withRegistration) {
            $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
            $registrations[] = new RegistrationFacts(1, $type, '2027-01-01', null, 2, [2 => '181']);
        }
        return new class(new ContactPaymentPolicy($registrations)) extends ContactPaymentFormTab {
            public int $policyReads = 0;

            public function __construct(private readonly ContactPaymentPolicy $testPolicy)
            {
            }

            protected function policy(ModuleExtensionContext $context): ContactPaymentPolicy
            {
                $this->policyReads++;
                return $this->testPolicy;
            }
        };
    }

    private function context(): ModuleExtensionContext
    {
        return new ModuleExtensionContext(
            'docs_core_heads',
            $this->createMock(DataSourceConnection::class),
            $this->salesRegistrationConfig(),
            null,
            'cs',
            $this->headsDefinition(),
        );
    }

    public function testExtensionAddsEditableFlagAndTimeAndASystemChangeStamp(): void
    {
        $columns = [];
        foreach ($this->headsDefinition()->columns as $column) {
            $columns[$column->id] = $column;
        }

        $this->assertSame('boolean', $columns['contact_payment']->type);
        $this->assertFalse($columns['contact_payment']->system);
        $this->assertSame('datetime', $columns['contact_payment_at']->type);
        $this->assertTrue($columns['contact_payment_at']->nullable);
        $this->assertFalse($columns['contact_payment_at']->system);
        $this->assertTrue($columns['contact_payment_changed_at']->system, 'čas změny plní doplněk, ne uživatel');
    }

    public function testInvoiceFormGetsTheTabWithFlagTimeAndHint(): void
    {
        $built = $this->tab()->build(['doc_type' => 'invno', 'payment_method' => 1], true, $this->context());

        $this->assertNotNull($built);
        $this->assertSame('Evidence tržeb', $built->label);
        $this->assertSame('Kontaktní platba', $built->sections[0]->title);
        [$hint, $flag, $time] = $built->sections[0]->columns[0]->elements;

        $this->assertSame('content', $hint->type);
        $this->assertSame('hint', $hint->blocks[0]['type']);
        $this->assertStringContainsString('zaplatil na místě', $hint->blocks[0]['text']);
        $this->assertStringContainsString('Platební bránou', $hint->blocks[0]['text']);
        $this->assertStringContainsString('nemá vliv', $hint->blocks[0]['text']);

        $this->assertSame('contact_payment', $flag->column);
        $this->assertSame('checkbox', $flag->inputType);
        $this->assertSame('contact_payment_at', $time->column);
        $this->assertSame('datetime', $time->inputType);
        $this->assertStringContainsString('Prázdné = čas přechodu', (string) $time->hint);
    }

    public function testTabIsOfferedByDocumentTypeNotByPaymentMethod(): void
    {
        $tab = $this->tab();
        foreach (['invno', 'invpo'] as $docType) {
            // Způsob úhrady uživatel ve formuláři teprve volí — hlídá ho až uložení.
            $this->assertNotNull($tab->build(['doc_type' => $docType, 'payment_method' => 5], false, $this->context()), $docType);
        }
        foreach (['cash', 'cashreg', 'invni', 'invpi', 'cmnbkp'] as $docType) {
            $this->assertNull($tab->build(['doc_type' => $docType], false, $this->context()), $docType);
        }
    }

    public function testWithoutAConfirmedRegistrationThereIsNoTab(): void
    {
        $this->assertNull($this->tab(withRegistration: false)->build(['doc_type' => 'invno'], true, $this->context()));
    }

    public function testFormWithoutDocumentTypeDoesNotReadRegistrations(): void
    {
        $tab = $this->tab();

        $this->assertNull($tab->build([], true, $this->context()));
        $this->assertSame(0, $tab->policyReads);
    }

    public function testTabPassesTheRulesOfModuleFormTabs(): void
    {
        $context = $this->context();
        $built = $this->tab()->build(['doc_type' => 'invno'], true, $context);
        $this->assertNotNull($built);

        // Kontrola, kterou záložce z modulu dělá sběr — výjimka = záložka by
        // se ve formuláři tiše neukázala.
        $check = new \ReflectionMethod(ModuleFormTabs::class, 'check');
        $check->invoke(null, $built, $context);
        $this->addToAssertionCount(1);
    }
}
