<script>
  import FormColumn from './FormColumn.svelte';

  let {
    section,
    formData = $bindable({}),
    fieldErrors = {},
    dataResolved = {},
    disabled = false,
    unlockedColumns = [],
    onTrigger,
    onResolveChange,
    parentId = null,
  } = $props();
</script>

{#if !section.hidden}
  <section class="shpd-form-section">
    {#if section.title}
      <h3 class="shpd-form-section__title">{section.title}</h3>
    {/if}
    <div
      class="shpd-form-section__columns"
      style:--shpd-form-section-cols={section.columns.length}
    >
      {#each section.columns as column, i (i)}
        <FormColumn
          {column}
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
  </section>
{/if}

<style>
  .shpd-form-section {
    background: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border-subtle);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-form-section-padding-y) var(--shpd-space-lg);
    /* Pojmenovaný kontejner pro @container (#133 D43): sekce reaguje na
       svou šířku (formulář bývá v modalu i v panelu), ne na okno. Jméno
       `form-section` sdílí FormAttachmentsView — náhled příloh uvnitř
       sloupce se musí přepnout ve stejný okamžik jako sloupce samotné,
       proto se ptá tohoto kontejneru, ne nejbližšího předka. */
    container-type: inline-size;
    container-name: form-section;
  }

  .shpd-form-section__title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text-secondary);
    margin: 0 0 var(--shpd-space-sm) 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .shpd-form-section__columns {
    display: grid;
    grid-template-columns: repeat(var(--shpd-form-section-cols), 1fr);
    gap: var(--shpd-space-xl);
  }

  /* Sekce s fill sloupcem (např. náhledy příloh) roste na celou výšku
     tabu; grid se sloupci vyplní zbytek karty a jeho řádek se natáhne
     (align-content default stretch) — levý sloupec drží pole nahoře
     (align-content: start), fill sloupec dostane celou výšku. */
  .shpd-form-section:has(:global(.shpd-form-column--fill)) {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }

  .shpd-form-section:has(:global(.shpd-form-column--fill)) .shpd-form-section__columns {
    flex: 1;
    min-height: 0;
  }

  /* Úzký kontejner (práh 30rem, #133 D43): sloupce se skládají pod sebe;
     gap přepneme z xl (vodorovná mezera mezi sloupci vedle sebe) na sm,
     aby svislá mezera mezi naskládanými sloupci navazovala na row-gap
     polí uvnitř sloupce (FormColumn). */
  @container form-section (max-width: 30rem) {
    .shpd-form-section__columns {
      grid-template-columns: 1fr;
      gap: var(--shpd-space-sm);
    }
  }
</style>
