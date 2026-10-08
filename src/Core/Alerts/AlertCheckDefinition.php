<?php

declare(strict_types=1);

namespace Shipard\Core\Alerts;

/**
 * Parsovaný záznam ze `alertChecks` bloku v `module.jsonc`.
 *
 * Vzniká v `AlertCheckRegistry` z surového `ModuleDefinition->alertChecks`
 * pole. Surový seznam je v ModuleDefinition záměrně neparsovaný (jen array
 * passthrough) — definice závisí na lokalizaci, kterou aplikuje až
 * `ConfigLocalizer` ve fázi loaderu.
 */
final readonly class AlertCheckDefinition
{
    public const SEVERITIES = ['info', 'warning', 'error'];

    /**
     * @param string $id           Globálně unikátní v rámci všech modulů.
     *                              Formát [a-z][a-z0-9_.]*.
     * @param string $name         Lokalizované jméno (jazyk DS).
     * @param string $description  Lokalizovaný popis (jazyk DS), může být prázdný.
     * @param string $class        FQCN PHP třídy, která dědí z AlertCheck.
     * @param string $severity     Default severity findings tohoto checku
     *                              (override per-finding možný).
     * @param string $interval     Raw forma z jsonc, např. "1h".
     * @param int    $intervalSeconds Parsovaná forma — pro reconciler.
     * @param bool   $enabled      Pokud false, registry ho zahrne do getAll
     *                              ale getEnabled ho přeskočí.
     * @param string[] $tags       Volné značky pro filtrování.
     * @param string $moduleId     Modul, ze kterého check pochází (pro chyby
     *                              a debug výpisy).
     * @param ?string $navSection  Atribuce karet checku pro badge stavů sekcí
     *                              (UI shells Fáze 3) — id sekce navigace nebo
     *                              sentinel `_top`. Null = karty se do badge
     *                              nepočítají (opt-in, D1).
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $class,
        public string $severity,
        public string $interval,
        public int $intervalSeconds,
        public bool $enabled,
        public array $tags,
        public string $moduleId,
        public ?string $navSection = null,
    ) {}

    /**
     * Factory z raw pole. Volá se v `AlertCheckRegistry` po lokalizaci.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data, string $moduleId): self
    {
        $id = self::requireString($data, 'id', $moduleId);

        if (!preg_match('/^[a-z][a-z0-9_.]*$/', $id)) {
            throw new \InvalidArgumentException(
                "alertChecks: invalid id format '{$id}' in module '{$moduleId}'."
                . " Expected [a-z][a-z0-9_.]*",
            );
        }

        $name        = self::requireString($data, 'name', $moduleId, $id);
        $class       = self::requireString($data, 'class', $moduleId, $id);
        $interval    = self::requireString($data, 'interval', $moduleId, $id);

        $description = isset($data['description']) && is_string($data['description'])
            ? $data['description']
            : '';

        $severity = isset($data['severity']) && is_string($data['severity'])
            ? $data['severity']
            : 'warning';
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException(
                "alertChecks['{$id}']: severity must be one of " . implode('|', self::SEVERITIES)
                . " (in module '{$moduleId}'), got '{$severity}'",
            );
        }

        $intervalSeconds = IntervalParser::parse($interval);

        $enabled = !isset($data['enabled']) || (bool) $data['enabled'];

        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        $navSection = null;
        if (isset($data['navSection'])) {
            // Id sekce sidebaru z navSections.jsonc — včetně camelCase
            // (`workOrders`); sentinel `_top`.
            if (!is_string($data['navSection']) || !preg_match('/^[a-z_][a-zA-Z0-9_]*$/', $data['navSection'])) {
                $got = is_string($data['navSection']) ? $data['navSection'] : gettype($data['navSection']);
                throw new \InvalidArgumentException(
                    "alertChecks['{$id}']: invalid navSection '{$got}' (in module '{$moduleId}')."
                    . ' Expected [a-z_][a-zA-Z0-9_]*',
                );
            }
            $navSection = $data['navSection'];
        }

        return new self(
            id: $id,
            name: $name,
            description: $description,
            class: $class,
            severity: $severity,
            interval: $interval,
            intervalSeconds: $intervalSeconds,
            enabled: $enabled,
            tags: $tags,
            moduleId: $moduleId,
            navSection: $navSection,
        );
    }

    private static function requireString(array $data, string $field, string $moduleId, ?string $checkId = null): string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
            $where = $checkId !== null
                ? "alertChecks['{$checkId}']"
                : "alertChecks entry";
            throw new \InvalidArgumentException(
                "{$where}: missing required field '{$field}' (in module '{$moduleId}')",
            );
        }
        return $data[$field];
    }
}
