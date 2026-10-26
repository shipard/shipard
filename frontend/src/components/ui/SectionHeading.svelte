<script>
  // Nadpis sekce v detailu záznamu (#137 D28): role heading, barvou textu,
  // bez linky a bez horního odsazení — mezeru mezi sekcemi dává rodič
  // (obal bloků, mřížka skupin), nadpis má jen malou mezeru pod sebou.
  // Jeden nadpis pro skupinu vlastností, blok `heading`, přílohy i Odeslanou
  // poštu; obecný detail i detail dokladu kreslí tentýž prvek.
  //
  // `level` 2–4 (výchozí 3; 2 = karta pod nadpisem stránky, Přehled firmy
  // přes `Card`) — base.css dává h2–h4 vlastní velikosti, role se proto
  // nastavuje explicitně. Obsah je snippet `children` (nadpis
  // přílohy nese odkaz a datum); volitelný snippet `aside` sedí vpravo ve
  // stejném řádku (přepínač náhledů příloh) — mezeru pod sebou pak nese
  // řádek, ne nadpis. Pravidla: docs/design-system.md §12.
  let { level = 3, children, aside = undefined } = $props();

  let tag = $derived([2, 3, 4].includes(level) ? `h${level}` : 'h3');
</script>

{#if aside}
  <div class="shpd-section-heading__row">
    <svelte:element this={tag} class="shpd-section-heading shpd-section-heading--in-row">
      {@render children?.()}
    </svelte:element>
    {@render aside()}
  </div>
{:else}
  <svelte:element this={tag} class="shpd-section-heading">
    {@render children?.()}
  </svelte:element>
{/if}

<style>
  .shpd-section-heading {
    margin: 0 0 var(--shpd-space-xs);
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
    line-height: 1.4;
    color: var(--shpd-color-text);
  }

  /* Řádek nadpis + aside: nadpis vlevo, aside vpravo na účaří. */
  .shpd-section-heading__row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--shpd-space-sm);
    margin: 0 0 var(--shpd-space-xs);
  }

  .shpd-section-heading--in-row {
    margin: 0;
  }
</style>
