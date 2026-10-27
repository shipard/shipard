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
  //
  // `role` (#137 D36): `heading` (výchozí, nadpis sekce a mezinadpis
  // v kartě) | `title` (nadpis karty jako samostatné části stránky —
  // Přehled firmy, karty Nastavení: písmo titulku detailu). `icon` (D38):
  // ikona tématu před textem, velikost podle písma nadpisu, šedá; je to
  // dekorace (`Icon` bez `label` = aria-hidden), nadpis čte jen text. Jen
  // s ikonou je nadpis flex řádek — bez ní zůstává vše jako dřív.
  // `spacing` = mezera pod nadpisem (`xs` výchozí; `md` / `lg` dává `Card`
  // pod nadpisem v roli title rovnou svému odsazení); s `aside` ji nese
  // řádek. Mezinadpisy ikonu nemají.
  import Icon from './Icon.svelte';

  let {
    level = 3,
    role = 'heading',
    icon = undefined,
    spacing = 'xs',
    children,
    aside = undefined,
  } = $props();

  let tag = $derived([2, 3, 4].includes(level) ? `h${level}` : 'h3');
  let safeRole = $derived(role === 'title' ? 'title' : 'heading');
  let safeSpacing = $derived(['xs', 'md', 'lg'].includes(spacing) ? spacing : 'xs');
  let headingClass = $derived(
    `shpd-section-heading shpd-section-heading--${safeRole}${icon ? ' shpd-section-heading--with-icon' : ''}`,
  );
</script>

{#snippet content()}
  {#if icon}
    <span class="shpd-section-heading__icon"><Icon {icon} size="md" /></span>
  {/if}
  {@render children?.()}
{/snippet}

{#if aside}
  <div class="shpd-section-heading__row shpd-section-heading__row--spacing-{safeSpacing}">
    <svelte:element this={tag} class="{headingClass} shpd-section-heading--in-row">
      {@render content()}
    </svelte:element>
    {@render aside()}
  </div>
{:else}
  <svelte:element this={tag} class="{headingClass} shpd-section-heading--spacing-{safeSpacing}">
    {@render content()}
  </svelte:element>
{/if}

<style>
  .shpd-section-heading {
    margin: 0;
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
    line-height: 1.4;
    color: var(--shpd-color-text);
  }

  /* Role title (D36): písmo titulku detailu (ViewerDetail .shpd-detail__title). */
  .shpd-section-heading--title {
    font-size: var(--shpd-font-size-title);
    line-height: 1.3;
  }

  /* Ikona tématu (D38): řádek ikona + text na střed; ikona v em roste
     s písmem nadpisu sama, šedá jako doplněk. */
  .shpd-section-heading--with-icon {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-section-heading__icon {
    display: inline-flex;
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  /* Mezera pod nadpisem — na nadpisu, nebo na řádku s aside. */
  .shpd-section-heading--spacing-xs,
  .shpd-section-heading__row--spacing-xs {
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-section-heading--spacing-md,
  .shpd-section-heading__row--spacing-md {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-section-heading--spacing-lg,
  .shpd-section-heading__row--spacing-lg {
    margin-bottom: var(--shpd-space-lg);
  }

  /* Řádek nadpis + aside: nadpis vlevo, aside vpravo na účaří. */
  .shpd-section-heading__row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--shpd-space-sm);
    margin: 0;
  }

  .shpd-section-heading--in-row {
    margin: 0;
  }
</style>
