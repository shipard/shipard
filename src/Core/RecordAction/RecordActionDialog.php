<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Dialog akce záznamu (#172 D12, D13): titulek, nepovinný úvodní text,
 * popisek tlačítka odeslání (bez něj obecné „Provést“) a pole. Dialog bez
 * polí je jen potvrzení s textem.
 */
final class RecordActionDialog
{
    /** @var list<RecordActionField> */
    public readonly array $fields;

    /** @param list<RecordActionField> $fields */
    public function __construct(
        public readonly string $title,
        array $fields = [],
        public readonly ?string $intro = null,
        public readonly ?string $submitLabel = null,
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('Record action dialog needs a title');
        }
        if ($intro !== null && trim($intro) === '') {
            throw new \InvalidArgumentException('Record action dialog: intro must not be empty');
        }
        if ($submitLabel !== null && trim($submitLabel) === '') {
            throw new \InvalidArgumentException('Record action dialog: submitLabel must not be empty');
        }
        if (!array_is_list($fields)) {
            throw new \InvalidArgumentException('Record action dialog: fields must be a list');
        }
        $seen = [];
        foreach ($fields as $idx => $field) {
            if (!$field instanceof RecordActionField) {
                throw new \InvalidArgumentException("Record action dialog: fields[{$idx}] must be a RecordActionField");
            }
            if (isset($seen[$field->id])) {
                throw new \InvalidArgumentException("Record action dialog: field id '{$field->id}' is used twice");
            }
            $seen[$field->id] = true;
        }
        $this->fields = $fields;
    }

    /**
     * @return array{title: string, intro: ?string, submitLabel: ?string, fields: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'intro' => $this->intro,
            'submitLabel' => $this->submitLabel,
            'fields' => array_map(static fn (RecordActionField $field): array => $field->toArray(), $this->fields),
        ];
    }
}
