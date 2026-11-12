<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Tasks\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Tasks\Core\TasksForm;

class TasksFormHeaderInfoTest extends TestCase
{
    /**
     * Vyrobí TasksForm s minimálním ConfigRuntime, který zná cfgItem
     * `tasks.core.priorities`. Bez configu se priorita silently přeskočí
     * (testy bez priority configu jsou níže).
     */
    private function createFormWithPriorities(): TasksForm
    {
        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(function (string $id) {
            if ($id !== 'tasks.core.priorities') {
                return null;
            }
            return [
                'low'      => ['name' => 'Nízká'],
                'medium'   => ['name' => 'Střední'],
                'high'     => ['name' => 'Vysoká'],
                'critical' => ['name' => 'Kritická'],
            ];
        });

        $form = new TasksForm('tasks_core_tasks');
        $form->setConfig($config);
        return $form;
    }

    private function createBareForm(): TasksForm
    {
        return new TasksForm('tasks_core_tasks');
    }

    public function testEmptyTitleReturnsNull(): void
    {
        $form = $this->createFormWithPriorities();

        $this->assertNull($form->buildHeaderInfo([
            'priority' => 'high',
            'due_date' => '2024-05-15',
        ]));
    }

    public function testWhitespaceOnlyTitleReturnsNull(): void
    {
        $form = $this->createFormWithPriorities();

        $this->assertNull($form->buildHeaderInfo([
            'title' => '   ',
        ]));
    }

    public function testMinimalRecordOnlyTitle(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title' => 'Zavolat účetní',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Zavolat účetní', $info->title);
        $this->assertSame([], $info->info);
        $this->assertSame('list-check', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testPriorityInInfo(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'priority' => 'high',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Priorita', 'value' => 'Vysoká']],
            $info->info,
        );
    }

    public function testDueDateInInfo(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'due_date' => '2024-05-15',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Termín', 'value' => '2024-05-15']],
            $info->info,
        );
    }

    public function testPriorityAndDueDateOrdering(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'priority' => 'critical',
            'due_date' => '2024-05-15',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [
                ['label' => 'Priorita', 'value' => 'Kritická'],
                ['label' => 'Termín',   'value' => '2024-05-15'],
            ],
            $info->info,
        );
    }

    public function testUnknownPriorityKeySkipped(): void
    {
        $form = $this->createFormWithPriorities();

        // Defenzivní: kdyby v DB byla starší / nezvalidovaná hodnota,
        // radši ji v hlavičce nezobrazit (surový enum klíč by mátl)
        // než ji ukázat jako „Priorita unknown".
        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'priority' => 'super-urgent',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testEmptyPrioritySkipped(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'priority' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testWithoutConfigPrioritySkipped(): void
    {
        $form = $this->createBareForm();

        // Bez configu nevíme, jak prioritu lokalizovat → vynecháme.
        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'priority' => 'high',
            'due_date' => '2024-05-15',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Termín', 'value' => '2024-05-15']],
            $info->info,
        );
    }

    public function testEmptyDueDateSkipped(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'due_date' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testNullDueDateSkipped(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'due_date' => null,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testMalformedDueDateKeepsValue(): void
    {
        $form = $this->createFormWithPriorities();

        $info = $form->buildHeaderInfo([
            'title'    => 'Zavolat účetní',
            'due_date' => 'not-a-date',
        ]);

        // Hodnota, která datem není, se vrací beze změny (DisplayDate, #133 D73).
        $this->assertNotNull($info);
        $this->assertSame([['label' => 'Termín', 'value' => 'not-a-date']], $info->info);
    }
}
