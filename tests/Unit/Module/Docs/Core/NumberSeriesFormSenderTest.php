<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\NumberSeriesForm;

/** Sekce „Odesílání e-mailem“ na číselné řadě (#90 D39). */
class NumberSeriesFormSenderTest extends TestCase
{
    /** @param list<string> $senders Adresy aktivních odesílatelů pošty. */
    private function emailFromElement(array $data, ?string $defaultFrom = 'podatelna@firma.example', array $senders = []): FormElement
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn($defaultFrom === null ? null : json_encode($defaultFrom));
        $db->method('fetchAll')->willReturn(array_map(
            static fn (string $email): array => ['email_from' => $email],
            $senders,
        ));

        $form = new NumberSeriesForm('docs_core_number_series');
        $form->setDb($db);

        foreach ($form->buildFormDefinition($data, false)->tabs as $tab) {
            foreach ($tab->sections as $section) {
                if ($section->title !== 'Odesílání e-mailem') {
                    continue;
                }
                foreach ($section->columns as $column) {
                    foreach ($column->elements as $element) {
                        if ($element->column === 'email_from') {
                            return $element;
                        }
                    }
                }
            }
        }
        $this->fail('Sekce Odesílání e-mailem s polem email_from ve formuláři není');
    }

    public function testOffersAllowedAddressesAndAutomaticChoice(): void
    {
        $element = $this->emailFromElement(['id' => 3, 'email_from' => null], senders: ['fakturace@firma.example']);

        $this->assertSame('select', $element->type);
        $this->assertSame('Automaticky', $element->placeholder);
        $this->assertSame(
            ['podatelna@firma.example', 'fakturace@firma.example'],
            array_column($element->options, 'value'),
        );
    }

    public function testStoredAddressThatIsNoLongerAllowedStaysVisible(): void
    {
        $element = $this->emailFromElement(['id' => 3, 'email_from' => 'zruseny@firma.example']);

        $this->assertSame(
            [
                ['value' => 'podatelna@firma.example', 'label' => 'podatelna@firma.example'],
                ['value' => 'zruseny@firma.example', 'label' => 'zruseny@firma.example (nepovolená adresa)'],
            ],
            $element->options,
        );
    }
}
