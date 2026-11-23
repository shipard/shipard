<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\AccountingDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\AccountingDocs\AccountingDocsForm;

/**
 * Účetní doklad (cmnbkp) je bez DPH a bez bankovního účtu: říkají to
 * atributy typu `vat: false` a `own_bank_account: false` (#166 U1), ne
 * podtřída formuláře — registrace DPH se neuplatní a bankovní účet se
 * nedotazuje (issue #60).
 */
class AccountingDocsFormNewRecordDefaultsTest extends TestCase
{
    /** Skutečné `docs.core.docTypes` — atributy typu jsou to, co se testuje. */
    private function config(): ConfigRuntime
    {
        $docTypes = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/docs/core/config/docTypes.jsonc',
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

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
        $form->setConfig($this->config());
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
        // Důvod atributu `own_bank_account: false`: hlavička účetního
        // dokladu žádné DPH ani bankovní pole nemá, default by šel do ničeho.
        $form = new AccountingDocsForm('docs_core_heads');
        $form->setConfig($this->config());
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
