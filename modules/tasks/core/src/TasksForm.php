<?php

declare(strict_types=1);

namespace Shipard\Module\Tasks\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\TableForm;

class TasksForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->input('title', required: true)
                    ->textarea('description')
                    ->select('priority', options: $this->resolvePriorityOptions())
                    ->date('due_date')
            ->build();

        return new FormDefinition(
            table:    $this->table,
            title:    'Úkol',
            titleNew: 'Nový úkol',
            tabs:     [$basic],
        );
    }

    /**
     * Strukturovaná hlavička úkolu.
     *
     *   ┌──┐ Zavolat účetní o uzávěrce
     *   │☑ │ [Nový] Priorita Vysoká · Termín 15.05.2024
     *   └──┘
     *
     *   - title  = title úkolu, fallback na formDef.title („Úkol")
     *   - info[] = priorita (pokud je vyplalněná a známá v cfgItem) +
     *              termín (pokud je vypělněný)
     *   - icon   = stejná jako u vieweru úkolů
     *   - summary = prázdný (úkoly nemají totals)
     *
     * @param array<string, mixed> $data
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $info = [];

        $priority = $this->resolvePriorityLabel((string) ($data['priority'] ?? ''));
        if ($priority !== '') {
            $info[] = ['label' => 'Priorita', 'value' => $priority];
        }

        $dueDate = $this->formatDate($data['due_date'] ?? null) ?? '';
        if ($dueDate !== '') {
            $info[] = ['label' => 'Termín', 'value' => $dueDate];
        }

        return new FormHeaderInfo(
            title: $title,
            info: $info,
            icon: 'list-check',
        );
    }

    /**
     * Resolvuje lokalizovaný název priority z cfgItem. Neznámý klíč nebo
     * chybějící config → prázdný string (volající pak položku v info vynechá
     * — radši nic než ukazovat surový enum klíč „high").
     */
    protected function resolvePriorityLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('tasks.core.priorities');
        if (is_array($cfg) && isset($cfg[$key]['name'])) {
            return (string) $cfg[$key]['name'];
        }
        return '';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolvePriorityOptions(): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem('tasks.core.priorities');
        if (!is_array($cfgData)) {
            return [];
        }

        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $entries[] = [
                'key'   => (string) $key,
                'name'  => (string) $entry['name'],
                'order' => (int) ($entry['order'] ?? 999),
            ];
        }
        usort($entries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn(array $e) => ['value' => $e['key'], 'label' => $e['name']],
            $entries,
        );
    }
}
