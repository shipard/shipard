<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Prints\PrintTextsForm;

/**
 * Formulář textu na tiscích: nabídky tisků, typů a řad se řídí umístěním
 * a vybranými tisky; přepočet z výběru vyhodí, co už v nabídce není.
 */
class PrintTextsFormTest extends TestCase
{
    use PrintTextConfigFixture;

    /** Řady v „databázi“: id → [název, typ dokladu]. */
    private const SERIES = [3 => ['Faktury tuzemsko', 'invno'], 7 => ['Faktury zahraničí', 'invno'], 9 => ['Pokladna', 'cash']];

    private function form(): PrintTextsForm
    {
        $db = $this->createStub(DataSourceConnection::class);
        // Dotaz na řady: 4. parametr = typy dokladů (IN %in).
        $db->method('fetchAll')->willReturnCallback(static function (mixed ...$args): array {
            $rows = [];
            foreach (self::SERIES as $id => [$name, $type]) {
                if (in_array($type, $args[3], true)) {
                    $rows[] = ['id' => $id, 'name' => $name];
                }
            }
            return $rows;
        });

        $form = new PrintTextsForm('core_prints_texts');
        $form->setDb($db);
        $form->setConfig($this->config());
        return $form;
    }

    /**
     * @param array<string, mixed> $definition `FormDefinition::toArray()`
     * @return array<string, array<string, mixed>> sloupec → element
     */
    private static function elements(array $definition): array
    {
        $elements = [];
        $walk = static function (array $node) use (&$walk, &$elements): void {
            if (isset($node['column'], $node['type'])) {
                $elements[$node['column']] = $node;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($definition);
        return $elements;
    }

    /** @return list<int|string> */
    private static function values(array $element): array
    {
        return array_column($element['options'] ?? [], 'value');
    }

    public function testNewTextOffersAllSlotsAndEveryPrintWithASlot(): void
    {
        $definition = $this->form()->buildFormDefinition([], true)->toArray();
        $elements   = self::elements($definition);

        $this->assertSame('Nový text na tiscích', $definition['title_new']);
        $this->assertSame(
            ['header', 'beforeRows', 'afterRows', 'footer', 'emailSubject', 'emailBody'],
            self::values($elements['slot']),
        );
        $this->assertSame('reload', $elements['slot']['triggers']);
        $this->assertSame(
            ['docs.invoicesOut.invoice', 'docs.cashDocs.cash', 'economy.assets.card'],
            self::values($elements['prints']),
        );
        $this->assertSame('reload', $elements['prints']['triggers']);
        $this->assertSame(['cs', 'en', 'sk', 'de'], self::values($elements['language']));
        $this->assertArrayNotHasKey('required', $elements['language']);
        $this->assertTrue($elements['text']['required']);
    }

    public function testSlotNarrowsPrintsAndExplainsWhereTheTextGoes(): void
    {
        $elements = self::elements($this->form()->buildFormDefinition(['slot' => 'emailBody'], true)->toArray());

        $this->assertSame(['docs.invoicesOut.invoice'], self::values($elements['prints']));
        $this->assertSame('Nahradí text.', $elements['slot']['hint']);
        // E-mail je prostý text — nápověda neslibuje Markdown.
        $this->assertStringContainsString('Prostý text', $elements['text']['hint']);
        $this->assertStringNotContainsString('Markdown', $elements['text']['hint']);

        $page = self::elements($this->form()->buildFormDefinition(['slot' => 'footer'], true)->toArray());
        $this->assertStringContainsString('Markdown', $page['text']['hint']);
    }

    public function testDocumentPrintsOfferTheirDocTypesAndSeries(): void
    {
        $elements = self::elements($this->form()->buildFormDefinition(
            ['slot' => 'footer', 'prints' => ['docs.invoicesOut.invoice']],
            false,
        )->toArray());

        $this->assertArrayNotHasKey('hidden', $elements['doc_types']);
        $this->assertSame(['invno'], self::values($elements['doc_types']));
        $this->assertSame([3, 7], self::values($elements['number_series']));
        $this->assertSame('Faktury tuzemsko', $elements['number_series']['options'][0]['label']);
    }

    public function testStoredJsonListsWorkLikeArrays(): void
    {
        // GET /meta/{id} bez dekódování JSON sloupců — formulář si poradí.
        $elements = self::elements($this->form()->buildFormDefinition(
            ['slot' => 'footer', 'prints' => '["docs.cashDocs.cash"]'],
            false,
        )->toArray());

        $this->assertSame(['cash'], self::values($elements['doc_types']));
        $this->assertSame([9], self::values($elements['number_series']));
    }

    public function testPrintOverAnotherTableHidesDocTypeAndSeries(): void
    {
        $elements = self::elements($this->form()->buildFormDefinition(
            ['slot' => 'footer', 'prints' => ['economy.assets.card']],
            false,
        )->toArray());

        $this->assertTrue($elements['doc_types']['hidden']);
        $this->assertTrue($elements['number_series']['hidden']);
        $this->assertSame([], self::values($elements['doc_types']));
    }

    public function testChangingSlotDropsPrintsThatDoNotSupportIt(): void
    {
        $result = $this->form()->recalculate('slot', [
            'slot'          => 'emailBody',
            'prints'        => ['docs.invoicesOut.invoice', 'docs.cashDocs.cash'],
            'doc_types'     => ['invno', 'cash'],
            'number_series' => [3, 9],
        ]);

        $this->assertSame(['docs.invoicesOut.invoice'], $result->data['prints']);
        // Pokladní doklad z výběru vypadl — s ním i jeho typ a řada.
        $this->assertSame(['invno'], $result->data['doc_types']);
        $this->assertSame([3], $result->data['number_series']);
    }

    public function testChangingPrintsToAnotherTableClearsDocTypeAndSeries(): void
    {
        $result = $this->form()->recalculate('prints', [
            'slot'          => 'footer',
            'prints'        => ['economy.assets.card'],
            'doc_types'     => ['invno'],
            'number_series' => [3],
        ]);

        $this->assertSame([], $result->data['doc_types']);
        $this->assertSame([], $result->data['number_series']);
        $this->assertTrue(self::elements($result->formDefinition->toArray())['doc_types']['hidden']);
    }

    public function testOtherChangesLeaveTargetingAlone(): void
    {
        $data = ['slot' => 'footer', 'prints' => ['docs.invoicesOut.invoice'], 'doc_types' => ['invno'], 'number_series' => [3]];

        $this->assertSame($data, $this->form()->recalculate('language', $data)->data);
    }
}
