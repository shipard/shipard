<script>
  let { element, id, children } = $props();
</script>

{#if !element.hidden}
  <label class="shpd-form-field-row__label" for={id}>
    {element.label ?? ''}{#if element.required}<span class="shpd-form-field-row__required">*</span>{/if}
  </label>
  <div class="shpd-form-field-row__input">
    {@render children()}
    {#if element.hint}
      <p class="shpd-form-field-row__hint">{element.hint}</p>
    {/if}
  </div>
{/if}

<style>
  /*
   * <label> and <.input> are two SEPARATE siblings inside the parent FormColumn
   * grid (no wrapping element around them). That's how all labels in a column
   * collapse to one shared "max-content" track.
   *
   * The label/required rules are exposed via :global() because FormInline.svelte
   * also emits a <label class="shpd-form-field-row__label"> as its grid label
   * sibling. Without :global, Svelte's scoped styles would hash this rule to
   * the FormFieldRow component only, leaving inline labels at the default
   * text-align: left — inconsistent with non-inline labels in the same column.
   */
  :global(.shpd-form-field-row__label) {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-align: right;
    white-space: nowrap;
  }
  :global(.shpd-form-field-row__required) {
    color: var(--shpd-color-danger);
    margin-left: 0.125rem;
  }
  .shpd-form-field-row__input {
    min-width: 0;
  }
  .shpd-form-field-row__hint {
    margin: var(--shpd-space-xs) 0 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
