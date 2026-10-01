<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Deklarace reportu z JSONC (D7, D16) — jediné místo, ze kterého se odvozuje
 * toolbar parametrů v UI, validace na REST API i budoucí MCP `inputSchema`.
 *
 * Vstupní pole je už lokalizované (`ConfigLocalizer` vyřešil `name:cs`
 * varianty před voláním `fromArray()` — vzor `AlertCheckRegistry`).
 */
final class ReportDefinition
{
    public const GRANULARITIES = ['month', 'quarter', 'halfYear', 'year'];
    public const PERIOD_SOURCES = ['fiscal', 'vatPeriod'];
    public const VAT_REPORT_TYPES = ['return', 'cs', 'rs'];
    private const PARAM_TYPES  = ['enum', 'bool'];

    /**
     * Id, která parametr reportu nést nesmí: klíče období na API
     * a klíče deep-linku / exportu v query stringu.
     */
    public const RESERVED_PARAM_IDS = [
        'fiscalYear', 'monthFrom', 'monthTo', 'period', 'format',
        'report', 'fy', 'mf', 'mt', 'p',
    ];

    /**
     * @param list<string> $periodGranularities Podmnožina GRANULARITIES;
     *        u `periodSource: 'vatPeriod'` prázdné (období určuje registrace).
     * @param list<array{id: string, type: string, name: ?string, options: list<string>,
     *        optionNames: array<string, string>, default: mixed}> $params
     *        Schéma ne-periodových parametrů. `name` a `optionNames` (hodnota →
     *        lidský název) jsou lokalizované popisky pro toolbar v UI a úvodní
     *        blok exportu; chybí-li, prezentace padá na id.
     * @param ?string $navSection Sekce hlavní navigace; null = report do navigace nevstupuje.
     * @param string $periodSource Zdroj období: 'fiscal' (fiskální měsíce, default)
     *        nebo 'vatPeriod' (instance daňového tvrzení — parametr `period`).
     * @param ?string $vatReportType Typ instance tvrzení (`return`|`cs`|`rs`),
     *        povinný právě u `periodSource: 'vatPeriod'` (D11).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $builderClass,
        public readonly array $periodGranularities,
        public readonly array $params,
        public readonly string $moduleId,
        public readonly ?string $navSection = null,
        public readonly int $navOrder = 1000,
        public readonly string $periodSource = 'fiscal',
        public readonly ?string $vatReportType = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $moduleId): self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z][a-zA-Z0-9.]*$/', $id)) {
            throw new \InvalidArgumentException(
                "Module '{$moduleId}': report declaration missing or invalid 'id'",
            );
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException("Report '{$id}': missing 'name'");
        }

        $builder = $data['builder'] ?? null;
        if (!is_string($builder) || $builder === '') {
            throw new \InvalidArgumentException("Report '{$id}': missing 'builder' class");
        }

        $periodSource = $data['periodSource'] ?? 'fiscal';
        if (!is_string($periodSource) || !in_array($periodSource, self::PERIOD_SOURCES, true)) {
            throw new \InvalidArgumentException(
                "Report '{$id}': 'periodSource' must be one of " . implode('|', self::PERIOD_SOURCES),
            );
        }

        $vatReportType = $data['vatReportType'] ?? null;
        if ($periodSource === 'vatPeriod') {
            if (!is_string($vatReportType) || !in_array($vatReportType, self::VAT_REPORT_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Report '{$id}': periodSource 'vatPeriod' requires 'vatReportType' one of "
                    . implode('|', self::VAT_REPORT_TYPES),
                );
            }
        } elseif ($vatReportType !== null) {
            throw new \InvalidArgumentException(
                "Report '{$id}': 'vatReportType' is only allowed for periodSource 'vatPeriod'",
            );
        }

        $granularities = $data['periodGranularities'] ?? null;
        if ($periodSource === 'vatPeriod') {
            // Období určuje registrace DPH — granularity by byly mrtvá konfigurace.
            if ($granularities !== null) {
                throw new \InvalidArgumentException(
                    "Report '{$id}': 'periodGranularities' must not be declared for periodSource 'vatPeriod'",
                );
            }
            $granularities = [];
        } elseif (!is_array($granularities) || $granularities === []
            || array_diff($granularities, self::GRANULARITIES) !== []
        ) {
            throw new \InvalidArgumentException(
                "Report '{$id}': 'periodGranularities' must be a non-empty subset of "
                . implode('|', self::GRANULARITIES),
            );
        }

        $params = [];
        foreach ($data['params'] ?? [] as $idx => $param) {
            if (!is_array($param) || !isset($param['id']) || !is_string($param['id']) || $param['id'] === '') {
                throw new \InvalidArgumentException("Report '{$id}': params[{$idx}] missing 'id'");
            }
            if (in_array($param['id'], self::RESERVED_PARAM_IDS, true)) {
                throw new \InvalidArgumentException(
                    "Report '{$id}': params[{$idx}] id '{$param['id']}' is reserved",
                );
            }
            $type = $param['type'] ?? null;
            if (!in_array($type, self::PARAM_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Report '{$id}': params[{$idx}] type must be one of " . implode('|', self::PARAM_TYPES),
                );
            }
            if (!array_key_exists('default', $param)) {
                throw new \InvalidArgumentException("Report '{$id}': params[{$idx}] missing 'default'");
            }
            $options = [];
            if ($type === 'enum') {
                $options = $param['options'] ?? null;
                if (!is_array($options) || $options === []) {
                    throw new \InvalidArgumentException(
                        "Report '{$id}': params[{$idx}] enum requires non-empty 'options'",
                    );
                }
                if (!in_array($param['default'], $options, true)) {
                    throw new \InvalidArgumentException(
                        "Report '{$id}': params[{$idx}] default is not among options",
                    );
                }
            }
            $paramName = $param['name'] ?? null;
            if ($paramName !== null && (!is_string($paramName) || $paramName === '')) {
                throw new \InvalidArgumentException("Report '{$id}': params[{$idx}] 'name' must be a non-empty string");
            }
            $optionNames = [];
            foreach (is_array($param['optionNames'] ?? null) ? $param['optionNames'] : [] as $value => $optionName) {
                if (in_array((string) $value, $options, true) && is_string($optionName) && $optionName !== '') {
                    $optionNames[(string) $value] = $optionName;
                }
            }
            $params[] = [
                'id'          => $param['id'],
                'type'        => $type,
                'name'        => $paramName,
                'options'     => array_values($options),
                'optionNames' => $optionNames,
                'default'     => $type === 'bool' ? (bool) $param['default'] : $param['default'],
            ];
        }

        $navSection = $data['navSection'] ?? null;
        if ($navSection !== null && (!is_string($navSection) || $navSection === '')) {
            throw new \InvalidArgumentException("Report '{$id}': 'navSection' must be a non-empty string");
        }
        $navOrder = $data['navOrder'] ?? 1000;
        if (!is_int($navOrder)) {
            throw new \InvalidArgumentException("Report '{$id}': 'navOrder' must be an integer");
        }

        return new self(
            id: $id,
            name: $name,
            builderClass: $builder,
            periodGranularities: array_values($granularities),
            params: $params,
            moduleId: $moduleId,
            navSection: $navSection,
            navOrder: $navOrder,
            periodSource: $periodSource,
            vatReportType: $vatReportType,
        );
    }
}
