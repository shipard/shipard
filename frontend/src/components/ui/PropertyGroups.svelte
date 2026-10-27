<script>
  // Skupiny vlastností vedle sebe (#137 D29): dva sloupce po řádcích
  // v pořadí od backendu (frontend skupiny nepřehazuje, CSS `columns` by
  // změnilo pořadí čtení), skupina `wide: true` přes celou šířku (poznámky,
  // popisy, zprávy — stejně jako tabulky a ostatní bloky), pod 30 rem šířky
  // kontejneru jeden sloupec. Výšku řádku určuje vyšší skupina. Skupina bez
  // vykreslitelné položky se nevykreslí (`visibleGroups`); bez skupin se
  // nevykreslí nic, ani obal. Skupina, která by v řádku zůstala sama
  // (poslední lichá, nebo před `wide`), jde přes celou šířku také — příznak
  // `full` počítá `visibleGroups` (D32), komponenta ho jen kreslí.
  //
  // Obal je kontejner pro @container (`container-type: inline-size`) — na
  // celou šířku, mřížka až uvnitř: dotaz platí pro potomky kontejneru, ne pro
  // něj sám. Práh 30 rem sdílí s DocumentDetail (D3). Hostitel dává mezeru
  // mezi sekcemi, obal sám žádný okraj nemá (D28).
  import SectionHeading from './SectionHeading.svelte';
  import PropertyList from './PropertyList.svelte';
  import { visibleGroups } from './propertyGroups.js';

  let { groups = [], headingLevel = 3 } = $props();

  let visible = $derived(visibleGroups(groups));
</script>

{#if visible.length > 0}
  <div class="shpd-property-groups">
    <div class="shpd-property-groups__grid">
      {#each visible as group}
        <div class="shpd-property-groups__group" class:shpd-property-groups__group--full={group.full}>
          {#if group.title !== ''}
            <SectionHeading level={headingLevel}>{group.title}</SectionHeading>
          {/if}
          <PropertyList items={group.items} />
        </div>
      {/each}
    </div>
  </div>
{/if}

<style>
  .shpd-property-groups {
    container-type: inline-size;
  }

  .shpd-property-groups__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--shpd-space-md) var(--shpd-space-lg);
    align-items: start;
  }

  .shpd-property-groups__group {
    min-width: 0;
  }

  /* Přes celou šířku: wide, nebo skupina sama v řádku (D32). */
  .shpd-property-groups__group--full {
    grid-column: 1 / -1;
  }

  /* Úzký kontejner (drawer, zúžené okno) — skupiny pod sebe. */
  @container (max-width: 30rem) {
    .shpd-property-groups__grid {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
