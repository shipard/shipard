<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Docs\AccountingDocs\AccountingDocsForm;
use Shipard\Module\Docs\CashDocs\CashDocForm;
use Shipard\Module\Docs\CashRegister\CashRegisterForm;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\NumberSeriesForm;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Pole Vystavil (`author`) v tabu Nastavení všech formulářů dokladů a autor
 * automaticky vystavených dokladů na číselné řadě (#93 D3, D11, D12).
 */
class DocAuthorFormTest extends TestCase
{
    /** Karel (7) už ve firmě není — účet je neaktivní. */
    private const USER_ROWS = [
        ['id' => 4, 'full_name' => 'Jana Příkladová', 'login' => 'jana', 'is_active' => 1],
        ['id' => 7, 'full_name' => 'Karel Vzorný', 'login' => 'karel', 'is_active' => 0],
    ];

    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    /** @param list<mixed> $userQueryParams zachycené parametry dotazu na uživatele */
    private function db(?bool $currentUserIsSystem = false, array &$userQueryParams = []): DataSourceConnection
    {
        $dibi = $this->createMock(Connection::class);
        $dibi->method('fetch')->willReturnCallback(
            static fn (): ?Row => $currentUserIsSystem === null
                ? null
                : new Row(['is_system' => $currentUserIsSystem ? 1 : 0]),
        );

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getDibiConnection')->willReturn($dibi);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use (&$userQueryParams): array {
                if (!str_contains($sql, 'core_system_users')) {
                    return [];
                }
                $userQueryParams = $params;
                // Jako databáze: aktivní lidé + aktuální hodnota.
                return array_values(array_filter(
                    self::USER_ROWS,
                    static fn (array $row): bool => $row['is_active'] === 1 || $row['id'] === ($params[0] ?? 0),
                ));
            },
        );
        return $db;
    }

    /** @return list<FormElement> */
    private function elements(FormDefinition $def, string $column, ?string $tabId = null): array
    {
        $found = [];
        foreach ($def->tabs as $tab) {
            if ($tabId !== null && $tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            $found[] = $el;
                        }
                    }
                }
            }
        }
        return $found;
    }

    /** @return array<string, array{class-string<TableForm>, string}> */
    public static function documentForms(): array
    {
        return [
            'výchozí doklad'   => [DocsHeadsForm::class, ''],
            'faktura vydaná'   => [IssuedInvoiceForm::class, 'invno'],
            'zálohová faktura' => [ProformaOutForm::class, 'invpo'],
            'faktura přijatá'  => [ReceivedInvoiceForm::class, 'invni'],
            'pokladní doklad'  => [CashDocForm::class, 'cash'],
            'prodejka'         => [CashRegisterForm::class, 'cashreg'],
            'účetní doklad'    => [AccountingDocsForm::class, 'cmnbkp'],
        ];
    }

    /** @param class-string<TableForm> $class */
    #[DataProvider('documentForms')]
    public function testEveryDocumentFormHasAuthorInSettingsTab(string $class, string $docType): void
    {
        $form = new $class('docs_core_heads');
        $form->setDb($this->db());

        $def = $form->buildFormDefinition(['id' => 12, 'doc_type' => $docType, 'author' => 4], false);

        $this->assertCount(1, $this->elements($def, 'author'), 'pole Vystavil je ve formuláři právě jednou');
        $inSettings = $this->elements($def, 'author', 'settings');
        $this->assertCount(1, $inSettings, 'pole Vystavil patří do tabu Nastavení');

        $element = $inSettings[0];
        $this->assertSame('select', $element->type);
        $this->assertFalse($element->required);
        $this->assertFalse($element->readOnly);
        $this->assertSame('Bez autora', $element->placeholder);
        $this->assertSame([['value' => 4, 'label' => 'Jana Příkladová']], $element->options);
    }

    public function testInactiveCurrentAuthorStaysInOffer(): void
    {
        $params = [];
        $form = new IssuedInvoiceForm('docs_core_heads');
        $form->setDb($this->db(userQueryParams: $params));

        $def = $form->buildFormDefinition(['id' => 12, 'doc_type' => 'invno', 'author' => 7], false);

        $this->assertSame([7], $params);
        $this->assertSame(
            [['value' => 4, 'label' => 'Jana Příkladová'], ['value' => 7, 'label' => 'Karel Vzorný (neaktivní)']],
            $this->elements($def, 'author')[0]->options,
        );
    }

    public function testFormWithoutDatabaseStillRendersAuthorField(): void
    {
        $def = (new DocsHeadsForm('docs_core_heads'))->buildFormDefinition([], true);

        $this->assertSame([], $this->elements($def, 'author', 'settings')[0]->options);
    }

    // ── Výchozí autor nového dokladu ────────────────────────────────────────

    public function testNewDocumentIsPrefilledWithSignedInUser(): void
    {
        CurrentUser::set(4);
        $form = new IssuedInvoiceForm('docs_core_heads');
        $form->setDb($this->db());

        // FormController předvyplňuje sloupce ze schématu — klíč je, hodnota ne.
        $data = ['doc_type' => 'invno', 'author' => null];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(4, $data['author']);
    }

    public function testPrefilledAuthorIsNotOverwritten(): void
    {
        CurrentUser::set(4);
        $form = new IssuedInvoiceForm('docs_core_heads');
        $form->setDb($this->db());

        $data = ['doc_type' => 'invno', 'author' => 7];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(7, $data['author']);
    }

    public function testNoPrefillWithoutSignedInHuman(): void
    {
        $data = ['doc_type' => 'cmnbkp'];
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setDb($this->db());
        $form->applyNewRecordDefaults($data);
        $this->assertArrayNotHasKey('author', $data, 'nikdo přihlášený');

        CurrentUser::set(9);
        $data = ['doc_type' => 'cmnbkp'];
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setDb($this->db(currentUserIsSystem: true));
        $form->applyNewRecordDefaults($data);
        $this->assertArrayNotHasKey('author', $data, 'systémový uživatel není autor');
    }

    // ── Číselná řada ────────────────────────────────────────────────────────

    public function testNumberSeriesOffersAutomaticAuthor(): void
    {
        $form = new NumberSeriesForm('docs_core_number_series');
        $form->setDb($this->db());

        $def = $form->buildFormDefinition(['id' => 3, 'doc_type' => 'invno', 'auto_author' => 7], false);

        $section = null;
        foreach ($def->tabs[0]->sections as $candidate) {
            if ($candidate->title === 'Automaticky vystavené doklady') {
                $section = $candidate;
            }
        }
        $this->assertNotNull($section, 'sekce vedle Odesílání e-mailem');

        $element = $this->elements($def, 'auto_author')[0];
        $this->assertSame('select', $element->type);
        $this->assertSame('Podle nastavení', $element->placeholder);
        $this->assertStringContainsString('bez přihlášeného uživatele', (string) $element->hint);
        $this->assertSame([4, 7], array_column($element->options, 'value'));
    }
}
