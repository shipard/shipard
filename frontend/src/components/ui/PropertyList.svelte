<script>
  // Seznam popisek–hodnota jedné skupiny vlastností (#137 D29): mřížka
  // `max-content 1fr`, popisek i hodnota body barvou textu — liší se jen
  // umístěním (D15), dlouhá hodnota (IBAN, e-mail) se zalamuje. Položka
  // s prázdnou hodnotou se nevykreslí; položka s prázdným popiskem (text
  // poznámky) má hodnotu přes oba sloupce. Výběr položek dělá čistá funkce
  // `visibleItems` (propertyGroups.js), aby se samostatné použití chovalo
  // stejně jako uvnitř PropertyGroups. Pravidla: docs/design-system.md §12.
  import { visibleItems } from './propertyGroups.js';

  let { items = [] } = $props();

  let visible = $derived(visibleItems(items));
</script>

{#if visible.length > 0}
  <dl class="shpd-property-list">
    {#each visible as item}
      {#if item.label === ''}
        <dd class="shpd-property-list__value shpd-property-list__value--full">{item.value}</dd>
      {:else}
        <dt class="shpd-property-list__label">{item.label}</dt>
        <dd class="shpd-property-list__value">{item.value}</dd>
      {/if}
    {/each}
  </dl>
{/if}

<style>
  .shpd-property-list {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
    margin: 0;
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text);
  }

  .shpd-property-list__label,
  .shpd-property-list__value {
    margin: 0;
  }

  .shpd-property-list__value {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  /* Bez popisku — hodnota přes oba sloupce (poznámka, popis, zpráva). */
  .shpd-property-list__value--full {
    grid-column: 1 / -1;
  }
</style>
