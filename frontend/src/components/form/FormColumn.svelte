<script>
  import FormElement from './FormElement.svelte';

  let {
    column,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    unlockedColumns = [],
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();

  // Sloupec složený jen z `component` elementů (např. náhledy příloh)
  // nediktuje výšku řádku sekce — přizpůsobí se sousednímu sloupci.
  const fillOnly = $derived(
    column.elements.length > 0 && column.elements.every((e) => e.type === 'component'),
  );

  // Víc komponent pod sebou (stav transportu nad náhledy příloh): výšku
  // sousedního sloupce vyplní jen poslední, ostatní mají výšku podle obsahu.
  const fillRows = $derived(
    fillOnly && column.elements.length > 1
      ? `repeat(${column.elements.length - 1}, auto) 1fr`
      : null,
  );
</script>

<div class="shpd-form-column" class:shpd-form-column--fill={fillOnly} style:grid-template-rows={fillRows}>
  {#each column.elements as element, i (element.column ?? `${element.type}-${i}`)}
    <FormElement
      {element}
      bind:formData
      {fieldErrors}
      {dataResolved}
      {disabled}
      {unlockedColumns}
      {onTrigger}
      {onResolveChange}
      {parentId}
    />
  {/each}
</div>

<style>
  /*
   * Two-track grid: label column shrinks to the widest label, input column fills
   * the remainder. Every <FormFieldRow> emits two siblings (a <label> and an
   * <div class="shpd-form-field-row__input">) directly into this grid so labels
   * align across the column.
   */
  .shpd-form-column {
    display: grid;
    grid-template-columns: max-content 1fr;
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-sm);
    align-items: baseline;
    /* When a sibling column (e.g. attachment previews) is taller, the section
       grid stretches this container; without this the rows would spread out
       to fill it. Keep fields packed at the top instead. */
    align-content: start;
  }

  /* Component-only column: stretch the single row so the component can fill
     the full height given by the sibling column (section grid stretches the
     container; the component itself must not drive the row height). */
  .shpd-form-column--fill {
    grid-template-rows: 1fr;
    align-content: stretch;
    align-items: stretch;
    min-height: 0;
  }
</style>
