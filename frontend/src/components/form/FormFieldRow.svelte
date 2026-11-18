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
   * <label> a <.input> jsou dva SAMOSTATNÍ sourozenci v mřížce FormColumn
   * (bez obalu) — proto popisky sloupce sdílejí jednu stopu.
   *
   * Pravidla popisku a hvězdičky jsou `:global()`, protože FormInline vkládá
   * do téže mřížky vlastní <label class="shpd-form-field-row__label">; bez
   * `:global` by je Svelte svázal jen s touto komponentou.
   *
   * Popisek (#157 D118): první řádek na svislém středu pole vedle — stejný
   * výraz jako stránka Nastavení, výška pole je token. Smí se zalomit
   * (strop stopy 40 % ve FormColumn); `overflow-wrap: anywhere` nedovolí
   * jednomu dlouhému slovu strop přetlačit.
   */
  :global(.shpd-form-field-row__label) {
    padding-top: calc((var(--shpd-control-height) - 1lh) / 2);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    text-align: right;
    overflow-wrap: anywhere;
  }
  :global(.shpd-form-field-row__required) {
    color: var(--shpd-color-danger-text);
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

  /* Úzká sekce (#157 D117): popisek vlevo nad polem, mezera `xs` — mřížka
     sloupce má mezi řádky `md`, pole se přitáhne záporným okrajem. */
  @container form-section (max-width: 30rem) {
    :global(.shpd-form-field-row__label) {
      padding-top: 0;
      text-align: left;
    }
    .shpd-form-field-row__input {
      margin-top: calc(var(--shpd-space-xs) - var(--shpd-space-md));
    }
  }
</style>
