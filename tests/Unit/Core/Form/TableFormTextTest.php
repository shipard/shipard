<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * `TableForm::formText()` (#150 D87): text ze sekce `texts` katalogu
 * `<modul>.formTexts`, náhrada parametrů, anglický fallback bez konfigurace
 * i bez klíče.
 */
class TableFormTextTest extends TestCase
{
    private function form(bool $withConfig = true): TableForm
    {
        $form = new class('test_table') extends TableForm {
            public function buildFormDefinition(array $data, bool $isNew): FormDefinition
            {
                return new FormDefinition(table: $this->table, title: 'T', titleNew: 'T', tabs: []);
            }

            public function text(string $key, string $fallback, array $params = []): string
            {
                return $this->formText('test.formTexts', $key, $fallback, $params);
            }
        };
        if ($withConfig) {
            $form->setConfig(ConfigRuntimeFactory::fromItems([
                'test.formTexts' => [
                    'texts' => [
                        'hint'  => ['name' => 'Nápověda formuláře.'],
                        'count' => ['name' => 'Řádků: {count}'],
                    ],
                    // Klíč mimo sekci `texts` se nečte.
                    'outside' => ['name' => 'Mimo sekci'],
                ],
            ]));
        }
        return $form;
    }

    public function testReadsTextsSection(): void
    {
        $this->assertSame('Nápověda formuláře.', $this->form()->text('hint', 'Form hint.'));
    }

    public function testReplacesParams(): void
    {
        $this->assertSame('Řádků: 3', $this->form()->text('count', 'Rows: {count}', ['count' => 3]));
    }

    public function testMissingKeyFallsBackToEnglish(): void
    {
        $this->assertSame('Rows: 3', $this->form()->text('missing', 'Rows: {count}', ['count' => 3]));
        $this->assertSame('Outside', $this->form()->text('outside', 'Outside'));
    }

    public function testWithoutConfigFallsBackToEnglish(): void
    {
        $this->assertSame('Form hint.', $this->form(withConfig: false)->text('hint', 'Form hint.'));
    }
}
