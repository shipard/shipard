<script>
  // Chrome primitiv: plochý pás ikon všech leafů stromu, bez sekcí.
  // Dnes ho používá sbalený sidebar; velikostní varianty až Fáze 4.
  import Icon from '../ui/Icon.svelte';
  import { resolveIcon } from '../../icons.js';
  import { flattenLeaves } from '../../utils/navTree.js';

  let { tree = [], activeId = null, onNavigate } = $props();

  // Plochý seznam klikatelných leaves (skupiny se rozbalí do hloubky).
  let flatLeaves = $derived(flattenLeaves(tree));
</script>

<ul class="shpd-navstrip">
  {#each flatLeaves as leaf}
    <li>
      <button
        class="shpd-navstrip__item"
        class:shpd-navstrip__item--active={activeId === leaf.id}
        onclick={() => onNavigate?.(leaf)}
        title={leaf.label}
        aria-label={leaf.label}
      >
        <Icon icon={resolveIcon(leaf.icon)} size="sm" />
      </button>
    </li>
  {/each}
</ul>

<style>
  /* Bez sekcí, bez chevronů — jen ikony pod sebou, vystředěné. */
  .shpd-navstrip {
    padding: var(--shpd-space-sm) 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.125rem;
    list-style: none;
  }

  .shpd-navstrip > li {
    width: 100%;
    display: flex;
    justify-content: center;
  }

  /* Čtvercové tlačítko, jen ikona. Stejné barvy a aktivní stav (accent
   * proužek vlevo + primary pozadí) jako položky v NavTree — uživatel
   * pozná aktivní položku stejně. */
  .shpd-navstrip__item {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-navstrip__item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-navstrip__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
  }

  .shpd-navstrip__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  /* Levý oranžový proužek u aktivní položky — stejný signál jako v NavTree. */
  .shpd-navstrip__item--active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    bottom: 4px;
    width: 3px;
    border-radius: 0 0.125rem 0.125rem 0;
    background-color: var(--shpd-color-accent);
  }
</style>
