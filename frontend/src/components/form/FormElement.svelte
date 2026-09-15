<script>
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import MultiselectInput from '../ui/MultiselectInput.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import LookupInput from '../ui/LookupInput.svelte';
  import FormFieldRow from './FormFieldRow.svelte';
  import FormInline from './FormInline.svelte';
  import { formComponents } from './formComponents.js';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    /** Sloupce, které zůstávají aktivní i když je formulář read-only
     *  (doc_states.editable_columns) — `disabled` se na ně nevztahuje. */
    unlockedColumns = [],
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();

  const error = $derived(element.column ? (fieldErrors[element.column] ?? null) : null);
  const elDisabled = $derived(
    element.read_only === true
      || (disabled && !(element.column && unlockedColumns.includes(element.column))),
  );
  const idSuffix = Math.random().toString(36).slice(2, 6);
  const inputId = $derived(`shpd-${element.column ?? element.type}-${idSuffix}`);

  function handleChange() {
    if (element.triggers === 'reload' && element.column) {
      onTrigger?.(element.column);
    }
  }
</script>

{#if element.type === 'separator'}
  <div class="shpd-form-separator" class:shpd-form-separator--hidden={element.hidden}>
    {#if element.label}<span class="shpd-form-separator__text">{element.label}</span>{/if}
  </div>

{:else if element.type === 'inline'}
  <FormInline {element} bind:formData {fieldErrors} disabled={elDisabled} {onTrigger} id={inputId} />

{:else if element.type === 'html'}
  <div class="shpd-form-html" class:shpd-form-html--hidden={element.hidden}>
    {@html element.content}
  </div>

{:else if element.type === 'component'}
  {@const ComponentImpl = formComponents[element.component_name]}
  <div class="shpd-form-component" class:shpd-form-component--hidden={element.hidden}>
    {#if ComponentImpl}
      <ComponentImpl params={element.params ?? {}} {parentId} />
    {:else}
      <span class="shpd-form-component__placeholder">[{element.component_name}]</span>
    {/if}
  </div>

{:else if element.type === 'input' && element.input_type === 'checkbox'}
  <div class="shpd-form-checkbox-row" class:shpd-form-checkbox-row--hidden={element.hidden}>
    <Checkbox label={element.label} bind:checked={formData[element.column]} disabled={elDisabled} onchange={handleChange} />
  </div>

{:else}
  <FormFieldRow {element} id={inputId}>
    {#if element.type === 'select'}
      <Select id={inputId} bind:value={formData[element.column]} options={element.options ?? []} required={element.required ?? false} disabled={elDisabled} placeholder={element.placeholder} {error} onchange={handleChange} />

    {:else if element.type === 'multiselect'}
      <MultiselectInput
        id={inputId}
        bind:value={formData[element.column]}
        options={element.options ?? []}
        required={element.required ?? false}
        disabled={elDisabled}
        placeholder={element.placeholder}
        {error}
        onchange={handleChange}
      />

    {:else if element.type === 'lookup'}
      <LookupInput
        id={inputId}
        bind:value={formData[element.column]}
        resolved={dataResolved?.[element.column] ?? null}
        lookup={element.lookup}
        required={element.required ?? false}
        disabled={elDisabled}
        placeholder={element.placeholder}
        {error}
        onchange={handleChange}
        onResolveChange={(item) => onResolveChange?.(element.column, item)}
      />

    {:else if element.input_type === 'textarea'}
      <TextArea id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'date'}
      <DateInput id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} onchange={handleChange} />

    {:else if element.input_type === 'datetime'}
      <Input id={inputId} type="datetime-local" bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'time'}
      <Input id={inputId} type="time" bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} />

    {:else if element.input_type === 'number'}
      <NumberInput id={inputId} bind:value={formData[element.column]} required={element.required ?? false} disabled={elDisabled} {error} onchange={handleChange} />

    {:else}
      <Input id={inputId} type={element.input_type ?? 'text'} bind:value={formData[element.column]} placeholder={element.placeholder} required={element.required ?? false} disabled={elDisabled} {error} />
    {/if}
  </FormFieldRow>
{/if}

<style>
  /*
   * separator / inline / html / component / checkbox-row span both grid tracks
   * of the parent FormColumn (label + input). FormInline is the exception —
   * its outer label IS the first track, so it does NOT span.
   */
  .shpd-form-separator,
  .shpd-form-html,
  .shpd-form-component,
  .shpd-form-checkbox-row {
    grid-column: 1 / -1;
  }

  .shpd-form-separator {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) 0 0 0;
    margin-top: var(--shpd-space-sm);
  }
  .shpd-form-separator::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--shpd-color-border);
  }
  .shpd-form-separator__text {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-form-separator--hidden,
  .shpd-form-html--hidden,
  .shpd-form-component--hidden,
  .shpd-form-checkbox-row--hidden {
    display: none;
  }

  .shpd-form-html {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-form-component {
    /* In a --fill column the row is definite (1fr) and 100% fills it; in
       regular auto-sized rows the percentage resolves to auto (no effect). */
    height: 100%;
    min-height: 0;
  }

  .shpd-form-component__placeholder {
    font-style: italic;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-form-checkbox-row {
    padding: var(--shpd-space-xs) 0;
  }
</style>
