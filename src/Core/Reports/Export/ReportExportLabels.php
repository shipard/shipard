<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Lokalizované popisky exportu reportu z cfgItem
 * `core.system.reportExportLabels` (už přeložený do jazyka requestu).
 * Chybějící cfgItem / klíč padá na anglický default — lokalizace
 * degraduje, export nepadá.
 */
final class ReportExportLabels
{
    public const CFG_ITEM = 'core.system.reportExportLabels';

    private const DEFAULTS = [
        'sheetReport'     => 'Report',
        'sheetMessages'   => 'Messages',
        'period'          => 'Period',
        'generatedAt'     => 'Generated',
        'dataSource'      => 'Data source',
        'note'            => 'Note',
        'exactAmounts'    => 'Amounts are exported in full precision (never in thousands).',
        'status'          => 'Status',
        'statusErrors'    => 'The report contains errors — see the Messages sheet.',
        'statusWarnings'  => 'The report contains warnings — see the Messages sheet.',
        'account'         => 'Account',
        'label'           => 'Name',
        'sideMd'          => 'Dr',
        'sideD'           => 'Cr',
        'sideBalance'     => 'Balance',
        'severity'        => 'Severity',
        'code'            => 'Code',
        'text'            => 'Text',
        'row'             => 'Row',
        'severityError'   => 'Error',
        'severityWarning' => 'Warning',
        'severityInfo'    => 'Info',
        'yes'             => 'Yes',
        'no'              => 'No',
    ];

    /**
     * @param array<string, string> $labels Klíč → text (přebíjí defaulty).
     * @param array<string, array{name: string, options: array<string, string>}> $params
     *        Id parametru reportu → lidský název + názvy hodnot.
     */
    public function __construct(
        private readonly array $labels = [],
        private readonly array $params = [],
    ) {}

    public static function fromConfig(?ConfigRuntime $config): self
    {
        $item = $config?->cfgItem(self::CFG_ITEM);
        if (!is_array($item)) {
            return new self();
        }

        $labels = [];
        foreach (is_array($item['labels'] ?? null) ? $item['labels'] : [] as $key => $entry) {
            if (is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                $labels[(string) $key] = $entry['name'];
            }
        }

        $params = [];
        foreach (is_array($item['params'] ?? null) ? $item['params'] : [] as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $options = [];
            foreach (is_array($entry['options'] ?? null) ? $entry['options'] : [] as $value => $option) {
                if (is_string($option['name'] ?? null)) {
                    $options[(string) $value] = $option['name'];
                }
            }
            $params[(string) $id] = [
                'name'    => is_string($entry['name'] ?? null) ? $entry['name'] : (string) $id,
                'options' => $options,
            ];
        }

        return new self($labels, $params);
    }

    /**
     * Popisky parametrů z deklarace reportu (`name`, `optionNames`) mají
     * přednost před cfgItem — deklarace je jediné místo, kde report své
     * parametry popisuje (docs/reports.md D7).
     *
     * @param list<array{id: string, name?: ?string, optionNames?: array<string, string>}> $definitionParams
     */
    public function withDefinitionParams(array $definitionParams): self
    {
        $params = $this->params;
        foreach ($definitionParams as $param) {
            $id = $param['id'];
            $params[$id] = [
                'name'    => $param['name'] ?? $params[$id]['name'] ?? $id,
                'options' => ($param['optionNames'] ?? []) + ($params[$id]['options'] ?? []),
            ];
        }
        return new self($this->labels, $params);
    }

    public function get(string $key): string
    {
        return $this->labels[$key] ?? self::DEFAULTS[$key] ?? $key;
    }

    public function paramName(string $id): string
    {
        return $this->params[$id]['name'] ?? $id;
    }

    public function paramValue(string $id, mixed $value): string
    {
        if (is_bool($value)) {
            return $this->get($value ? 'yes' : 'no');
        }
        if (!is_scalar($value)) {
            return '';
        }
        return $this->params[$id]['options'][(string) $value] ?? (string) $value;
    }
}
