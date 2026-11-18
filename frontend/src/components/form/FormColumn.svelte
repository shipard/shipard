<script>
  import FormElement from './FormElement.svelte';
  import { flowFormComponents } from './formComponents.js';

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
  // Výjimka: komponenty s výškou podle obsahu (`flowFormComponents` —
  // rekapitulace DPH, strany dokladu) sloupec neroztahují.
  const fillOnly = $derived(
    column.elements.length > 0
      && column.elements.every((e) => e.type === 'component' && !flowFormComponents.has(e.component_name)),
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
   * Dvě stopy: sloupec popisků je široký jako nejdelší popisek, nejvýš ale
   * 40 % sloupce (#157 D118) — delší popisek se zalomí; pole dostane zbytek.
   * <FormFieldRow> vkládá do mřížky dva sourozence (<label> a
   * <div class="shpd-form-field-row__input">), takže popisky sloupce sdílejí
   * jednu stopu. Popisek i pole jsou zarovnané nahoru: první řádek popisku
   * sedí na svislém středu pole (odsazení popisku ve FormFieldRow), víceřádkové
   * pole ani zalomený popisek druhou stranu nenatahují.
   */
  .shpd-form-column {
    display: grid;
    grid-template-columns: fit-content(40%) minmax(0, 1fr);
    column-gap: var(--shpd-space-md);
    row-gap: var(--shpd-space-sm);
    align-items: start;
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

  /* Úzká sekce (práh 30rem jako skládání sloupců, #157 D117): jedna stopa,
     popisek nad polem. Mezi dvojicemi `md`; pole se ke svému popisku
     přitáhne na `xs` záporným okrajem (FormFieldRow, FormInline) — bez
     pravidla pro první popisek ve sloupci a bez mezery po skrytém prvku. */
  @container form-section (max-width: 30rem) {
    .shpd-form-column {
      grid-template-columns: minmax(0, 1fr);
      row-gap: var(--shpd-space-md);
    }
  }
</style>
