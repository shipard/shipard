<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\AccountingDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\AccountingDocs\AccountingDocsForm;

/**
 * Účetní doklad (cmnbkp) je bez DPH a bez bankovního účtu: hook nastaví
 * vat_mode = 0 před base hookem, takže registrace DPH se neuplatní, a
 * bankovní účet se nedotazuje (issue #60).
 */
class AccountingDocsFormNewRecordDefaultsTest extends TestCase
{
    public function testHookForcesNoVatAndSkipsRegistrationAndBankAccount(): void
    {
        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use (&$queries): array {
                $queries[] = $sql;
                return [['id' => 4, 'country' => 'cz', 'vat_id' => 'CZ1']];
            },
        );
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use (&$queries): ?array {
                $queries[] = $sql;
                return ['id' => 9];
            },
        );
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setDb($db);

        // schéma defaulty, jak je vloží FormController
        $data = ['doc_type' => 'cmnbkp', 'vat_mode' => 1, 'payment_method' => 1, 'doc_currency' => 'czk'];
        $form->applyNewRecordDefaults($data);

        $this->assertSame(0, $data['vat_mode']);
        $this->assertArrayNotHasKey('vat_registration', $data);
        $this->assertArrayNotHasKey('bank_account', $data);
        $this->assertSame(date('Y-m-d'), $data['issue_date'], 'datum vystavení z base hooku zůstává');
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('vat_registrations', $sql);
            $this->assertStringNotContainsString('bank_accounts', $sql);
        }
    }

    public function testFormRendersNoVatNorBankAccountFields(): void
    {
        // Důvod pro newRecordUsesBankAccount() = false: hlavička účetního
        // dokladu žádné DPH ani bankovní pole nemá, default by šel do ničeho.
        $form = new AccountingDocsForm('docs_core_heads');
        $def = $form->buildFormDefinition(['doc_type' => 'cmnbkp', 'vat_mode' => 1], true);

        $columns = [];
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column !== null) {
                            $columns[] = $el->column;
                        }
                    }
                }
            }
        }
        $this->assertCount(5, $def->tabs, 'Hlavička, Řádky, Poznámky, Přílohy, Nastavení');
        $this->assertContains('issue_date', $columns);
        $this->assertNotContains('vat_mode', $columns);
        $this->assertNotContains('vat_registration', $columns);
        $this->assertNotContains('bank_account', $columns);
    }
}
