<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Base\Persons\PersonsForm;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Přepínač „Odeslat s dokladem“ v tabu Přílohy (#94 D6) nabízejí jen
 * formuláře dokladů, které se odesílají — faktura vydaná a zálohová.
 */
class AttachmentsSendFlagFormTest extends TestCase
{
    /** @return array<string, array{0: TableForm, 1: bool}> */
    public static function forms(): array
    {
        return [
            'faktura vydaná'   => [new IssuedInvoiceForm('docs_core_heads'), true],
            'zálohová faktura' => [new ProformaOutForm('docs_core_heads'), true],
            'faktura přijatá'  => [new ReceivedInvoiceForm('docs_core_heads'), false],
            'obecný doklad'    => [new DocsHeadsForm('docs_core_heads'), false],
            'osoba'            => [new PersonsForm('base_persons_persons'), false],
        ];
    }

    #[DataProvider('forms')]
    public function testAttachmentsTabOffersSendFlagOnlyForSentDocuments(TableForm $form, bool $expected): void
    {
        $definition = $form->buildFormDefinition(['person_type' => 2], true);

        $tabs = array_values(array_filter($definition->tabs, static fn ($tab): bool => $tab->type === 'attachments'));
        $this->assertCount(1, $tabs);
        $this->assertSame($expected, $tabs[0]->sendFlag);
        $this->assertSame($expected, $tabs[0]->toArray()['send_flag'] ?? false);
    }
}
