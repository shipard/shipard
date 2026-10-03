<script>
  import FormSection from './FormSection.svelte';
  import FormSubTable from './FormSubTable.svelte';
  import AttachmentPanel from './AttachmentPanel.svelte';

  let {
    tab,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    /** Rodič je jen pro čtení (prop FormEditor nebo doc state) — sub-tabulka
     *  přepne na režim Zobrazit. `disabled` samotné = jen dočasně vypnuté akce. */
    readOnly = false,
    /** Sloupce editovatelné i v read-only stavu (doc_states.editable_columns). */
    unlockedColumns = [],
    onTrigger,
    onResolveChange,
    parentId = null,
    /** Tabulka rodiče — sub-tabulka z ní skládá cestu endpointu /subtable. */
    parentTable = null,
    /** Sub-tabulka změnila řádek → rodič si přenačte odvozené hodnoty. */
    onSubtableChanged,
  } = $props();
</script>

<div class="shpd-form-tab">
  {#if tab.type === 'subtable'}
    <FormSubTable
      element={tab.subtable}
      tabId={tab.id}
      {parentTable}
      {parentId}
      {disabled}
      {readOnly}
      onChanged={onSubtableChanged}
    />
  {:else if tab.type === 'attachments'}
    <AttachmentPanel
      tableId={tab.table_id}
      recordId={parentId}
      {disabled}
      changeEndpoint={tab.change_endpoint}
      sendFlag={tab.send_flag ?? false}
    />
  {:else}
    {#each tab.sections as section, i (section.title ?? `section-${i}`)}
      <FormSection
        {section}
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
  {/if}
</div>

<style>
  .shpd-form-tab {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-form-section-gap);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
  }

  /* Součást řetězu pro fill sloupce — viz FormEditor / FormSection. */
  .shpd-form-tab:has(:global(.shpd-form-column--fill)) {
    flex: 1;
    min-height: 0;
  }
</style>
