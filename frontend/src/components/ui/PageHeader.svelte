<script>
  // Nadpis stránky (#149 D78): `<h1>` stránky, která není prohlížeč ani
  // detail — Dashboard, Přehled firmy, Štítky obsahu, Portál, reporty.
  //
  // `variant`:
  //   `flow` (výchozí) nadpis v toku stránky — typografická role `page`
  //          (xl, strong), bez pozadí a vnějšího okraje; mezeru pod
  //          nadpisem dává stránka;
  //   `bar`  lišta nad obsahem ve tvaru hlavičky detailu (`DetailHeader`):
  //          role title, odsazení `--shpd-bar-padding-y` × md, pozadí
  //          plochy, linka dole — reporty.
  //
  // `icon` = ikona z položky navigace, odkud stránka vznikla (stejná jako
  // v menu — obdoba D38): už rozřešený objekt ikony; stránka bez ikony
  // v navigaci nadpis bez ikony, žádný fallback. Kreslí se jako
  // v `SectionHeading`: `Icon size="md"`, šedá, mezera sm, dekorace
  // (`aria-hidden`). Snippet `aside` (akce, datum „k“, výběr období) sedí
  // vpravo a při nedostatku místa se zalomí pod nadpis. Zbylé atributy
  // (`data-testid`, `class` pro rozložení — okraj, `flex-shrink`) jdou na
  // kořen. Pravidla: docs/design-system.md §8 Nadpis stránky.
  import Icon from './Icon.svelte';

  let {
    title = '',
    icon = undefined,
    variant = 'flow',
    aside = undefined,
    class: extraClass = '',
    ...rest
  } = $props();

  let safeVariant = $derived(variant === 'bar' ? 'bar' : 'flow');
</script>

<header class="shpd-page-header shpd-page-header--{safeVariant} {extraClass}" {...rest}>
  <h1 class="shpd-page-header__title">
    {#if icon}
      <span class="shpd-page-header__icon"><Icon {icon} size="md" /></span>
    {/if}
    <span>{title}</span>
  </h1>
  {#if aside}
    <div class="shpd-page-header__aside">{@render aside()}</div>
  {/if}
</header>

<style>
  /* Řádek nadpis + aside: když se vedle sebe nevejdou, aside se zalomí
     pod nadpis místo stlačení nadpisu do víc řádků. */
  .shpd-page-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    min-width: 0;
  }

  /* `<h1>` má výchozí tloušťku 700 — vždy strong (§11). */
  .shpd-page-header__title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    min-width: 0;
    margin: 0;
    font-size: var(--shpd-font-size-page);
    font-weight: var(--shpd-font-weight-strong);
    line-height: 1.3;
    color: var(--shpd-color-text);
  }

  .shpd-page-header__icon {
    display: inline-flex;
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-page-header__aside {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    min-width: 0;
  }

  /* Lišta: tvar hlavičky detailu (ui/DetailHeader). */
  .shpd-page-header--bar {
    flex-shrink: 0;
    padding: var(--shpd-bar-padding-y) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
  }

  .shpd-page-header--bar .shpd-page-header__title {
    font-size: var(--shpd-font-size-title);
  }
</style>
