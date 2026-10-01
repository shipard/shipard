<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

final class FormElement
{
    public const ALLOWED_TYPES = [
        'input',
        'select',
        'multiselect',
        'lookup',
        'separator',
        'inline',
        'component',
        'html',
    ];

    public const ALLOWED_INPUT_TYPES = [
        null,
        'text',
        'email',
        'tel',
        'url',
        'password',
        'number',
        'checkbox',
        'date',
        'datetime',
        'time',
        'textarea',
    ];

    /** Input types that may appear inside an inline group. */
    private const INLINE_INNER_ALLOWED_TYPES = ['input', 'select'];

    /**
     * @param FormElement[]|null $elements Used by inline groups.
     * @param array<int, array{value: mixed, label: string}>|null $options
     * @param array{table: string, filter: array<string, scalar>|null}|null $lookup
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $column = null,
        public readonly ?string $label = null,
        public readonly ?string $placeholder = null,
        public readonly bool $required = false,
        public readonly bool $readOnly = false,
        public readonly bool $hidden = false,
        public readonly ?string $triggers = null,
        public readonly ?string $hint = null,
        public readonly ?array $options = null,
        public readonly ?array $elements = null,
        public readonly ?string $content = null,
        public readonly ?string $componentName = null,
        public readonly ?string $inputType = null,
        public readonly ?array $lookup = null,
        public readonly ?array $params = null,
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid element type "%s". Allowed: %s',
                $type,
                implode(', ', self::ALLOWED_TYPES),
            ));
        }

        if ($type === 'input' && !in_array($inputType, self::ALLOWED_INPUT_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid inputType "%s". Allowed: %s',
                $inputType,
                implode(', ', array_map(fn($t) => $t ?? 'null', self::ALLOWED_INPUT_TYPES)),
            ));
        }

        if ($type === 'inline') {
            if ($elements === null || $elements === []) {
                throw new \InvalidArgumentException('inline element requires non-empty elements[]');
            }
            foreach ($elements as $i => $inner) {
                if (!$inner instanceof FormElement) {
                    throw new \InvalidArgumentException(
                        sprintf('inline.elements[%d] must be FormElement, got %s', $i, get_debug_type($inner)),
                    );
                }
                if (!in_array($inner->type, self::INLINE_INNER_ALLOWED_TYPES, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'inline.elements[%d] has type "%s"; only %s are allowed inside inline groups',
                        $i,
                        $inner->type,
                        implode(', ', self::INLINE_INNER_ALLOWED_TYPES),
                    ));
                }
            }
        }

        if ($type === 'multiselect' && ($column === null || $column === '')) {
            throw new \InvalidArgumentException('multiselect element requires column');
        }

        if ($type === 'component' && ($componentName === null || $componentName === '')) {
            throw new \InvalidArgumentException('component element requires componentName');
        }

        if ($type === 'html' && $content === null) {
            throw new \InvalidArgumentException('html element requires content');
        }

        if ($type === 'lookup') {
            if ($column === null || $column === '') {
                throw new \InvalidArgumentException('lookup element requires column');
            }
            if ($lookup === null) {
                throw new \InvalidArgumentException('lookup element requires lookup config {table, filter?}');
            }
            $table = $lookup['table'] ?? null;
            if (!is_string($table) || $table === '') {
                throw new \InvalidArgumentException('lookup.table must be a non-empty string');
            }
            if (array_key_exists('filter', $lookup) && $lookup['filter'] !== null && !is_array($lookup['filter'])) {
                throw new \InvalidArgumentException('lookup.filter must be array|null');
            }
            if (array_key_exists('edit_form', $lookup) && !is_bool($lookup['edit_form'])) {
                throw new \InvalidArgumentException('lookup.edit_form must be bool');
            }
            if (array_key_exists('create_form', $lookup) && !is_bool($lookup['create_form'])) {
                throw new \InvalidArgumentException('lookup.create_form must be bool');
            }
            if (array_key_exists('edit_triggers', $lookup) && !is_bool($lookup['edit_triggers'])) {
                throw new \InvalidArgumentException('lookup.edit_triggers must be bool');
            }
            if (array_key_exists('create_defaults', $lookup) && !is_bool($lookup['create_defaults'])) {
                throw new \InvalidArgumentException('lookup.create_defaults must be bool');
            }
        }
    }

    public function toArray(): array
    {
        $result = ['type' => $this->type];

        if ($this->column !== null) {
            $result['column'] = $this->column;
        }
        if ($this->label !== null) {
            $result['label'] = $this->label;
        }
        if ($this->placeholder !== null) {
            $result['placeholder'] = $this->placeholder;
        }
        if ($this->required) {
            $result['required'] = true;
        }
        if ($this->readOnly) {
            $result['read_only'] = true;
        }
        if ($this->hidden) {
            $result['hidden'] = true;
        }
        if ($this->triggers !== null) {
            $result['triggers'] = $this->triggers;
        }
        if ($this->hint !== null) {
            $result['hint'] = $this->hint;
        }
        if ($this->options !== null) {
            $result['options'] = $this->options;
        }
        if ($this->elements !== null) {
            $result['elements'] = array_map(
                fn(FormElement $el) => $el->toArray(),
                $this->elements,
            );
        }
        if ($this->content !== null) {
            $result['content'] = $this->content;
        }
        if ($this->componentName !== null) {
            $result['component_name'] = $this->componentName;
        }
        if ($this->params !== null) {
            $result['params'] = $this->params;
        }
        if ($this->inputType !== null) {
            $result['input_type'] = $this->inputType;
        }
        if ($this->lookup !== null) {
            $filter = $this->lookup['filter'] ?? null;
            $lookupOut = [
                'table'  => $this->lookup['table'],
                'filter' => is_array($filter) && $filter !== [] ? $filter : null,
            ];
            if (!empty($this->lookup['edit_form'])) {
                $lookupOut['edit_form'] = true;
            }
            if (!empty($this->lookup['create_form'])) {
                $lookupOut['create_form'] = true;
            }
            if (!empty($this->lookup['edit_triggers'])) {
                $lookupOut['edit_triggers'] = true;
            }
            if (!empty($this->lookup['create_defaults'])) {
                $lookupOut['create_defaults'] = true;
            }
            $result['lookup'] = $lookupOut;
        }

        return $result;
    }
}
