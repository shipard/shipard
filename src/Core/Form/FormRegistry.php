<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;

class FormRegistry
{
    /** @var array<string, array<string, mixed>> tableId → merged registration */
    private array $registrations = [];

    /** Předává se každému vytvořenému formuláři (`setDsConfig`). */
    private ?DataSourceConfig $dsConfig = null;

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    /** @var array<string, string> tableId → form id (per-table, ne per-type) */
    private array $formIds = [];

    /**
     * @param list<array<string, mixed>> $registrations
     *     Pre-merged form registrations (output of FormLoader::mergeForms).
     */
    public function __construct(array $registrations = [])
    {
        foreach ($registrations as $reg) {
            $table = $reg['table'] ?? null;
            if (!is_string($table) || $table === '') {
                continue;
            }
            $this->registrations[$table] = $reg;
            if (isset($reg['id']) && is_string($reg['id'])) {
                $this->formIds[$table] = $reg['id'];
            }
        }
    }

    public function getFormId(string $table): ?string
    {
        return $this->formIds[$table] ?? null;
    }

    /**
     * Resolve form class for given $table and $data.
     *
     * For typeColumn-based registrations: dispatch via $data[$typeColumn]
     * through `classes` map, fallback to `defaultClass`. For simple
     * `{table, class}` registrations: return `class` regardless of $data.
     *
     * @param array<string, mixed> $data Row data — typically from DB SELECT
     *     for existing records, or newRecordDefaults for new ones.
     */
    public function createForm(
        string $table,
        array $data = [],
        ?DataSourceConnection $db = null,
        ?ConfigRuntime $config = null,
    ): ?TableForm {
        $reg = $this->registrations[$table] ?? null;
        if ($reg === null) {
            return null;
        }

        $class = null;
        if (isset($reg['typeColumn'])) {
            $typeValue = $data[$reg['typeColumn']] ?? '';
            $class = $reg['classes'][$typeValue] ?? $reg['defaultClass'] ?? null;
        } elseif (isset($reg['class'])) {
            $class = $reg['class'];
        }

        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return null;
        }

        $form = new $class($table);
        if ($db !== null) {
            $form->setDb($db);
        }
        if ($config !== null) {
            $form->setConfig($config);
        }
        if ($this->dsConfig !== null) {
            $form->setDsConfig($this->dsConfig);
        }
        return $form;
    }
}
