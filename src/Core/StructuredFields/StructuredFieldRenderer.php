<?php

declare(strict_types=1);

namespace Shipard\Core\StructuredFields;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\SubtableCellFormatter;

/**
 * Obsah strukturovaného pole pro detail vieweru (I6 v #74) — bloky
 * `{title, items: [{label, value}]}` pro typ obsahu `properties`
 * (docs/viewer-grid.md, `TableViewer::renderDetail`).
 *
 * Formátuje stejně jako sub-tabulky (`SubtableCellFormatter`): datum
 * `d.m.Y`, čísla s čárkou, boolean „Ano" / „Ne" z cfgItem
 * `core.system.formDefaults`, enum přes `name` položky číselníku.
 *
 * Prázdné položky se vynechávají. **Klíče, které schéma nezná** (pole
 * zrušené novější verzí), se ukazují pod syrovým klíčem — data uložená
 * podle starší verze se nezahazují, jen se nemají čím pojmenovat.
 */
final class StructuredFieldRenderer
{
    /** @param string $language Jazyk požadavku — zápis data (`DisplayDate`, #133 D73). */
    public function __construct(
        private readonly ?ConfigRuntime $config = null,
        private readonly string $language = 'en',
    ) {}

    /**
     * @param mixed $value hodnota sloupce (JSON string z DB, pole, null)
     * @return list<array{title: ?string, items: list<array{label: string, value: string}>}>
     */
    public function properties(StructuredSchema $schema, mixed $value): array
    {
        $decoded = StructuredFieldValues::decode($value);
        if ($decoded === null) {
            return [];
        }

        $blocks = [];
        $fallbackIndex = null;

        foreach ($schema->fieldsByGroup() as $block) {
            $items = [];
            foreach ($block['fields'] as $field) {
                $text = $this->formatValue($field, $decoded[$field->id] ?? null);
                if ($text !== null && $text !== '') {
                    $items[] = ['label' => $field->name, 'value' => $text];
                }
            }
            if ($items === []) {
                continue;
            }
            if ($block['title'] === null) {
                $fallbackIndex = count($blocks);
            }
            $blocks[] = ['title' => $block['title'] ?? $this->fallbackTitle(), 'items' => $items];
        }

        $unknown = $this->unknownItems($schema, $decoded);
        if ($unknown !== []) {
            if ($fallbackIndex !== null) {
                $blocks[$fallbackIndex]['items'] = [...$blocks[$fallbackIndex]['items'], ...$unknown];
            } else {
                $blocks[] = ['title' => $this->fallbackTitle(), 'items' => $unknown];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array{label: string, value: string}>
     */
    private function unknownItems(StructuredSchema $schema, array $decoded): array
    {
        $items = [];
        foreach ($decoded as $key => $raw) {
            if ($key === StructuredSchema::SCHEMA_KEY || isset($schema->fields[$key])) {
                continue;
            }
            if ($raw === null || $raw === '' || is_array($raw)) {
                continue;
            }
            $items[] = [
                'label' => (string) $key,
                'value' => is_bool($raw) ? $this->boolean($raw) : (string) $raw,
            ];
        }
        return $items;
    }

    private function formatValue(StructuredField $field, mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || is_array($raw)) {
            return null;
        }

        if ($field->isEnum()) {
            return $this->enumLabel($field, $raw);
        }

        return match ($field->type) {
            'boolean' => $this->boolean($raw),
            'date'    => SubtableCellFormatter::date($raw, $this->language),
            'numeric' => SubtableCellFormatter::number($raw, $field->scale ?? 2),
            default   => is_scalar($raw) ? (string) $raw : null,
        };
    }

    private function enumLabel(StructuredField $field, mixed $raw): string
    {
        $cfgData = $field->cfgItem !== null ? $this->config?->cfgItem($field->cfgItem) : null;
        $entry = is_array($cfgData) && is_scalar($raw) ? ($cfgData[(string) $raw] ?? null) : null;
        if (is_array($entry) && isset($entry['name'])) {
            return (string) $entry['name'];
        }
        return is_scalar($raw) ? (string) $raw : '';
    }

    private function boolean(mixed $raw): string
    {
        $defaults = $this->config?->cfgItem('core.system.formDefaults');
        return SubtableCellFormatter::boolean(
            $raw,
            (string) ($defaults['booleanYes']['name'] ?? 'Yes'),
            (string) ($defaults['booleanNo']['name'] ?? 'No'),
        );
    }

    /**
     * Titulek bloku pro pole bez skupiny a pro klíče mimo schéma — stejný
     * lokalizovaný zdroj jako generický tab „Obecné" (`AutoFormBuilder`).
     */
    private function fallbackTitle(): string
    {
        $defaults = $this->config?->cfgItem('core.system.formDefaults');
        return (string) ($defaults['generalTabLabel']['name'] ?? 'General');
    }
}
