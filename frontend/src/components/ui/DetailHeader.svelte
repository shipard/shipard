<script>
  // Hlavička detailu záznamu (#139 D48; docs/design-system.md §12 Hlavička):
  // ikona typu záznamu 40×40 vlevo, titulek (role title), štítky v řádku
  // titulku (`Badge`), podtitul (body, barvou textu, D56). Vytažená z
  // `ViewerDetail` beze změny vzhledu, aby ji task 2 #139 použil i pro
  // review dokladu (bez ikony, D49) — hlavička je sdílená část, ne kopie.
  //
  // `icon` je už rozřešený objekt ikony (hostitel volá `resolveIcon`;
  // bez ikony se box nekreslí), `badges` `[{label, style}]` se styly
  // `Badge`. Rozměry 40×40 px a mezery 0.75rem zůstávají jako dřív —
  // převod na tokeny je D46 (#138 task 4). Titulek je `<h2>` i v modalu
  // review: nadpis obsahu pod titulkem dialogu.
  import Icon from './Icon.svelte';
  import Badge from './Badge.svelte';

  let { title = '', subtitle = '', badges = [], icon = undefined } = $props();
</script>

<div class="shpd-detail-header">
  {#if icon}
    <div class="shpd-detail-header__icon">
      <Icon {icon} />
    </div>
  {/if}
  <div class="shpd-detail-header__main">
    <div class="shpd-detail-header__title-row">
      <h2 class="shpd-detail-header__title">{title}</h2>
      {#each badges ?? [] as badge}
        <Badge style={badge.style ?? 'neutral'} label={badge.label} />
      {/each}
    </div>
    {#if subtitle}
      <div class="shpd-detail-header__subtitle">{subtitle}</div>
    {/if}
  </div>
</div>

<style>
  /* Drobný panel nad taby. Písmo podle typografických rolí (#135,
     docs/design-system.md → Detail záznamu): titulek title, podtitul body
     barvou textu (D56 — v roli meta splýval se štítky), štítky meta
     normální tloušťkou (Badge). */
  .shpd-detail-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  /* Ikona vlevo v hlavičce (typ záznamu) — stejný vizuál jako
     shpd-modal__header-icon ve formulářovém modalu. */
  .shpd-detail-header__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 2.5rem;
    height: 2.5rem;
    font-size: 1.75em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-detail-header__main {
    flex: 1;
    /* min-width: 0 kvůli ellipsis uvnitř flex containeru */
    min-width: 0;
  }

  .shpd-detail-header__title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-detail-header__title {
    margin: 0;
    font-size: var(--shpd-font-size-title);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    line-height: 1.3;
  }

  .shpd-detail-header__subtitle {
    margin-top: 0.125rem;
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text);
  }
</style>
