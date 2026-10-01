<?php

declare(strict_types=1);

namespace Shipard\Core\Form;

use Shipard\Core\Database\ColumnDefinition;

/**
 * Fluent builder for {@see FormTab} of type 'fields'.
 *
 * Scope hierarchy:
 *   tab → section() → col() → [inline()/endInline()] → elements
 *
 * Auto-close in build(): inline → col → section. The first section() and col()
 * are NOT created implicitly — callers must open them. Adding an element without
 * an open column throws LogicException.
 */
final class TabBuilder
{
    /** @var FormSection[] Completed sections. */
    private array $sections = [];

    /** @var FormColumn[] Completed columns in the currently-open section. */
    private array $currentColumns = [];

    /** @var FormElement[]|null Elements buffer for the currently-open column; null = no column open. */
    private ?array $currentElements = null;

    /** @var FormElement[]|null Elements buffer for the currently-open inline group; null = not in inline mode. */
    private ?array $inlineBuffer = null;

    /** Metadata for the currently-open section. */
    private ?string $sectionTitle = null;
    private bool $sectionHidden = false;
    private bool $sectionOpen = false;

    /** @var array<string, string> column => label map for auto-resolve. */
    private array $colLabels;

    /**
     * @var array<string, ColumnDefinition> column => definition map. Used to
     *      derive `required` for select() when the caller does not decide
     *      explicitly (issue #61). Empty = no inference, select() defaults to false.
     */
    private array $colDefs;

    /**
     * @param array<string, string>           $colLabels
     * @param array<string, ColumnDefinition> $colDefs
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        array $colLabels = [],
        private readonly ?string $icon = null,
        array $colDefs = [],
    ) {
        $this->colLabels = $colLabels;
        $this->colDefs   = $colDefs;
    }

    // -------- Section / column management --------

    public function section(?string $title = null, bool $hidden = false): static
    {
        $this->closeColumnIfOpen();
        $this->flushSectionIfOpen();

        $this->sectionTitle  = $title;
        $this->sectionHidden = $hidden;
        $this->sectionOpen   = true;
        $this->currentColumns = [];

        return $this;
    }

    public function col(): static
    {
        if (!$this->sectionOpen) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: col() called outside of a section. Call section() first.',
                $this->id,
            ));
        }
        $this->closeColumnIfOpen();
        $this->currentElements = [];
        return $this;
    }

    // -------- Inline group --------

    public function inline(): static
    {
        $this->requireOpenColumn('inline()');
        if ($this->inlineBuffer !== null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: inline() called inside another inline group',
                $this->id,
            ));
        }
        $this->inlineBuffer = [];
        return $this;
    }

    public function endInline(): static
    {
        if ($this->inlineBuffer === null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: endInline() called without matching inline()',
                $this->id,
            ));
        }
        $elements = $this->inlineBuffer;
        $this->inlineBuffer = null;

        if ($elements === []) {
            // Empty inline — silently drop, this is forgiving for conditional code paths.
            return $this;
        }

        $this->currentElements[] = new FormElement(type: 'inline', elements: $elements);
        return $this;
    }

    /**
     * Shortcut: inline group of plain text-like inputs. Each entry is a column name;
     * labels are auto-resolved. inputType is left null (text default), so this is meant for
     * homogeneous quick groups. For mixed types use inline() + input()/date()/... + endInline().
     */
    public function inlineFields(string ...$columns): static
    {
        $this->inline();
        foreach ($columns as $col) {
            $this->input($col);
        }
        return $this->endInline();
    }

    // -------- Elements --------

    public function input(
        string $column,
        ?string $label = null,
        bool $required = false,
        ?string $triggers = null,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $placeholder = null,
        ?string $hint = null,
        ?string $inputType = null,
    ): static {
        $this->pushElement(new FormElement(
            type: 'input',
            column: $column,
            label: $this->resolveLabel($column, $label),
            placeholder: $placeholder,
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            hint: $hint,
            inputType: $inputType,
        ));
        return $this;
    }

    public function textarea(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'textarea');
    }

    public function date(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'date');
    }

    public function datetime(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'datetime');
    }

    public function time(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'time');
    }

    public function number(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'number');
    }

    public function checkbox(
        string $column,
        ?string $label = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $triggers = null,
    ): static {
        return $this->pushWidget($column, $label, $required, $readOnly, $hidden, $hint, $triggers, 'checkbox');
    }

    /**
     * Single-value select over a fixed option list.
     *
     * `$required === null` (default) derives the flag from the column
     * definition: NOT NULL column → required (the empty option would send
     * NULL, which the column cannot hold), nullable → optional. Without a
     * column definition the default is false. Explicit true/false always wins.
     * The rule is intentionally `!nullable` without regard to the column
     * default — the empty option of a select always means NULL, a default
     * does not help there (unlike input(), see JsoncFormLoader/AutoFormBuilder).
     *
     * `$placeholder` = text of the empty option; the frontend falls back to
     * the global "not selected" label when null.
     */
    public function select(
        string $column,
        ?string $label = null,
        ?array $options = null,
        ?string $triggers = null,
        ?bool $required = null,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $placeholder = null,
    ): static {
        $this->pushElement(new FormElement(
            type: 'select',
            column: $column,
            label: $this->resolveLabel($column, $label),
            placeholder: $placeholder,
            required: $required ?? $this->inferSelectRequired($column),
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            options: $options,
            hint: $hint,
        ));
        return $this;
    }

    /**
     * Multi-select over a fixed option list (cfgItem-based). Value is a list of option values.
     */
    public function multiselect(
        string $column,
        ?string $label = null,
        ?array $options = null,
        ?string $triggers = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $hint = null,
        ?string $placeholder = null,
    ): static {
        $this->pushElement(new FormElement(
            type: 'multiselect',
            column: $column,
            label: $this->resolveLabel($column, $label),
            placeholder: $placeholder,
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            options: $options,
            hint: $hint,
        ));
        return $this;
    }

    /**
     * @param array<string, scalar>|null $filter
     */
    public function lookup(
        string $column,
        string $table,
        ?array $filter = null,
        ?string $label = null,
        ?string $placeholder = null,
        bool $required = false,
        bool $readOnly = false,
        bool $hidden = false,
        ?string $triggers = null,
        ?string $hint = null,
        bool $editForm = false,
        bool $createForm = false,
        bool $editTriggers = false,
        bool $createDefaults = false,
    ): static {
        $lookupCfg = ['table' => $table, 'filter' => $filter];
        if ($editForm) {
            $lookupCfg['edit_form'] = true;
        }
        if ($createForm) {
            $lookupCfg['create_form'] = true;
        }
        if ($editTriggers) {
            $lookupCfg['edit_triggers'] = true;
        }
        if ($createDefaults) {
            $lookupCfg['create_defaults'] = true;
        }
        $this->pushElement(new FormElement(
            type: 'lookup',
            column: $column,
            label: $this->resolveLabel($column, $label),
            placeholder: $placeholder,
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            hint: $hint,
            lookup: $lookupCfg,
        ));
        return $this;
    }

    /**
     * Vloží hotové elementy do otevřeného sloupce — pro generátory, které
     * elementy staví samy (`TableForm::structuredFieldElements()`, #74).
     * Prázdný seznam nic nedělá, takže podmíněné cesty nemusí nic řešit.
     *
     * @param list<FormElement> $elements
     */
    public function addElements(array $elements): static
    {
        foreach ($elements as $i => $element) {
            if (!$element instanceof FormElement) {
                throw new \InvalidArgumentException(sprintf(
                    'TabBuilder["%s"]: addElements[%d] must be FormElement, got %s',
                    $this->id,
                    $i,
                    get_debug_type($element),
                ));
            }
            if ($element->type === 'separator') {
                // Separátor má vlastní pravidla (nesmí do inline, auto-hide).
                $this->separator($element->label, $element->hidden);
                continue;
            }
            $this->pushElement($element);
        }
        return $this;
    }

    public function separator(?string $label = null, bool $hidden = false): static
    {
        $this->requireOpenColumn('separator()');
        if ($this->inlineBuffer !== null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: separator cannot appear inside inline group',
                $this->id,
            ));
        }
        $this->currentElements[] = new FormElement(
            type: 'separator',
            label: $label,
            hidden: $hidden,
        );
        return $this;
    }

    public function html(string $content): static
    {
        $this->requireOpenColumn('html()');
        if ($this->inlineBuffer !== null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: html cannot appear inside inline group',
                $this->id,
            ));
        }
        $this->currentElements[] = new FormElement(type: 'html', content: $content);
        return $this;
    }

    public function component(string $name, ?array $params = null): static
    {
        $this->requireOpenColumn('component()');
        if ($this->inlineBuffer !== null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: component cannot appear inside inline group',
                $this->id,
            ));
        }
        $this->currentElements[] = new FormElement(type: 'component', componentName: $name, params: $params);
        return $this;
    }

    // -------- Build --------

    public function build(): FormTab
    {
        // Auto-close any open scopes.
        if ($this->inlineBuffer !== null) {
            $this->endInline();
        }
        $this->closeColumnIfOpen();
        $this->flushSectionIfOpen();

        return new FormTab(
            id: $this->id,
            label: $this->label,
            sections: $this->sections,
            type: 'fields',
            icon: $this->icon,
        );
    }

    // -------- Internals --------

    private function pushWidget(
        string $column,
        ?string $label,
        bool $required,
        bool $readOnly,
        bool $hidden,
        ?string $hint,
        ?string $triggers,
        string $inputType,
    ): static {
        $this->pushElement(new FormElement(
            type: 'input',
            column: $column,
            label: $this->resolveLabel($column, $label),
            required: $required,
            readOnly: $readOnly,
            hidden: $hidden,
            triggers: $triggers,
            hint: $hint,
            inputType: $inputType,
        ));
        return $this;
    }

    private function pushElement(FormElement $el): void
    {
        if ($this->inlineBuffer !== null) {
            // Validation: inline allows only input/select; FormElement constructor will enforce
            // when the inline element is finally built, but we can fail earlier with a clearer error.
            if (!in_array($el->type, ['input', 'select'], true)) {
                throw new \LogicException(sprintf(
                    'TabBuilder["%s"]: element type "%s" not allowed inside inline group',
                    $this->id, $el->type,
                ));
            }
            $this->inlineBuffer[] = $el;
            return;
        }

        $this->requireOpenColumn(sprintf('%s element', $el->type));
        $this->currentElements[] = $el;
    }

    private function requireOpenColumn(string $what): void
    {
        if ($this->currentElements === null) {
            throw new \LogicException(sprintf(
                'TabBuilder["%s"]: %s called outside of a column. Call section()->col() first.',
                $this->id, $what,
            ));
        }
    }

    private function closeColumnIfOpen(): void
    {
        if ($this->currentElements === null) {
            return;
        }
        if ($this->inlineBuffer !== null) {
            // Auto-close inline first.
            $this->endInline();
        }
        $elements = $this->autoHideSeparators($this->currentElements);
        $this->currentColumns[] = new FormColumn($elements);
        $this->currentElements = null;
    }

    private function flushSectionIfOpen(): void
    {
        if (!$this->sectionOpen) {
            return;
        }
        if ($this->currentColumns === []) {
            // Empty section — silently skip. Allows conditional code to open a section
            // and add nothing without breaking the build.
            $this->sectionOpen = false;
            $this->sectionTitle = null;
            $this->sectionHidden = false;
            return;
        }

        $this->sections[] = new FormSection(
            columns: $this->currentColumns,
            title: $this->sectionTitle,
            hidden: $this->sectionHidden,
        );
        $this->currentColumns = [];
        $this->sectionTitle  = null;
        $this->sectionHidden = false;
        $this->sectionOpen   = false;
    }

    /**
     * Hide a separator if every element following it in the same column (until the next
     * separator or end) is hidden. Operates per-column.
     *
     * @param  FormElement[] $elements
     * @return FormElement[]
     */
    private function autoHideSeparators(array $elements): array
    {
        $count = count($elements);
        for ($i = 0; $i < $count; $i++) {
            if ($elements[$i]->type !== 'separator' || $elements[$i]->hidden) {
                continue;
            }
            $allHidden = true;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($elements[$j]->type === 'separator') {
                    break;
                }
                if (!$elements[$j]->hidden) {
                    $allHidden = false;
                    break;
                }
            }
            if ($allHidden) {
                $sep = $elements[$i];
                $elements[$i] = new FormElement(
                    type: 'separator',
                    label: $sep->label,
                    hidden: true,
                );
            }
        }
        return $elements;
    }

    private function resolveLabel(string $column, ?string $label): ?string
    {
        return $label ?? $this->colLabels[$column] ?? null;
    }

    /**
     * Default `required` for select(): NOT NULL column → true, nullable or
     * unknown column → false. See select() docblock.
     */
    private function inferSelectRequired(string $column): bool
    {
        $col = $this->colDefs[$column] ?? null;
        return $col !== null && !$col->nullable;
    }
}
