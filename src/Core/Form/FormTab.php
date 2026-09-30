<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class FormTab
{
    public const ALLOWED_TYPES = ['fields', 'subtable', 'attachments'];

    /**
     * @param FormSection[] $sections   Required for type='fields'.
     * @param array{table: string, foreignKey: string, formId: ?string, sort?: ?string, orderColumn?: ?string, independentRows?: bool}|null $subtable
     *                                  Required for type='subtable'. `orderColumn` = pořadový
     *                                  sloupec dětské tabulky (šipky přesunu, řazení
     *                                  `orderColumn ASC, id ASC`); nekombinuje se se `sort`.
     * @param int|null      $tableId    Required for type='attachments'.
     * @param string|null   $changeEndpoint Only for type='attachments': API path
     *                                  POSTed (fire-and-forget) after upload/delete;
     *                                  `{id}` placeholder = record id.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $sections = [],
        public readonly string $type = 'fields',
        public readonly ?array $subtable = null,
        public readonly ?int $tableId = null,
        public readonly ?string $icon = null,
        public readonly ?string $changeEndpoint = null,
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid tab type "%s". Allowed: %s',
                $type,
                implode(', ', self::ALLOWED_TYPES),
            ));
        }

        if ($changeEndpoint !== null && $type !== 'attachments') {
            throw new \InvalidArgumentException(
                sprintf('FormTab "%s": changeEndpoint is only allowed for type "attachments"', $id),
            );
        }

        if ($type === 'fields') {
            if ($sections === []) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "fields" must have at least one section', $id),
                );
            }
            foreach ($sections as $i => $section) {
                if (!$section instanceof FormSection) {
                    throw new \InvalidArgumentException(sprintf(
                        'FormTab "%s" sections[%d] must be FormSection, got %s',
                        $id, $i, get_debug_type($section),
                    ));
                }
            }
            if ($subtable !== null) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "fields" must not have subtable', $id),
                );
            }
            if ($tableId !== null) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "fields" must not have tableId', $id),
                );
            }
        }

        if ($type === 'subtable') {
            if ($subtable === null) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "subtable" requires subtable {table, foreignKey, formId}', $id),
                );
            }
            foreach (['table', 'foreignKey'] as $key) {
                if (!isset($subtable[$key]) || !is_string($subtable[$key]) || $subtable[$key] === '') {
                    throw new \InvalidArgumentException(sprintf(
                        'FormTab "%s" subtable must have non-empty string "%s"', $id, $key,
                    ));
                }
            }
            if ($sections !== []) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "subtable" must not have sections', $id),
                );
            }
            $orderColumn = $subtable['orderColumn'] ?? null;
            if ($orderColumn !== null) {
                if (!is_string($orderColumn) || $orderColumn === '') {
                    throw new \InvalidArgumentException(
                        sprintf('FormTab "%s" subtable "orderColumn" must be a non-empty string or null', $id),
                    );
                }
                // Řádky seřazené sub-tabulky se řadí VŽDY podle pořadového
                // sloupce (+ id) — endpoint výpisu i přesunu musí vidět totéž
                // pořadí, jinak by šipky přesouvaly něco jiného, než uživatel vidí.
                if (isset($subtable['sort']) && $subtable['sort'] !== null && $subtable['sort'] !== '') {
                    throw new \InvalidArgumentException(
                        sprintf('FormTab "%s": subtable "sort" cannot be combined with "orderColumn"', $id),
                    );
                }
            }
        }

        if ($type === 'attachments') {
            if ($tableId === null) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "attachments" requires tableId', $id),
                );
            }
            if ($sections !== []) {
                throw new \InvalidArgumentException(
                    sprintf('FormTab "%s" of type "attachments" must not have sections', $id),
                );
            }
        }
    }

    public function toArray(): array
    {
        $result = [
            'id'    => $this->id,
            'label' => $this->label,
            'type'  => $this->type,
        ];

        if ($this->icon !== null) {
            $result['icon'] = $this->icon;
        }

        if ($this->type === 'fields') {
            $result['sections'] = array_map(
                fn(FormSection $s) => $s->toArray(),
                $this->sections,
            );
        }

        if ($this->type === 'subtable' && $this->subtable !== null) {
            $sub = [
                'table'       => $this->subtable['table'],
                'foreign_key' => $this->subtable['foreignKey'],
            ];
            if (array_key_exists('formId', $this->subtable) && $this->subtable['formId'] !== null) {
                $sub['form_id'] = $this->subtable['formId'];
            }
            if (array_key_exists('sort', $this->subtable) && $this->subtable['sort'] !== null) {
                $sub['sort'] = $this->subtable['sort'];
            }
            if (!empty($this->subtable['orderColumn'])) {
                $sub['order_column'] = $this->subtable['orderColumn'];
            }
            if (!empty($this->subtable['independentRows'])) {
                $sub['independent_rows'] = true;
            }
            $result['subtable'] = $sub;
        }

        if ($this->type === 'attachments') {
            $result['table_id'] = $this->tableId;
            if ($this->changeEndpoint !== null) {
                $result['change_endpoint'] = $this->changeEndpoint;
            }
        }

        return $result;
    }
}
