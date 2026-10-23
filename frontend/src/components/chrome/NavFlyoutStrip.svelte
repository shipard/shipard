<script>
  // Chrome primitiv: vertikální pás uzlů úrovně 2 (classic shell, D8) —
  // leaf = ikona + popisek, klik naviguje; skupina = totéž + klik otevírá
  // Popover flyout s úrovní 3 (podskupiny oddělené separátorem
  // s popiskem). Max jeden otevřený flyout; zavření výběrem / Esc /
  // klikem mimo řeší Popover (capture listener + anchor výjimka, D9).
  // Pás při přetečení scrolluje — overflow „malé ikony dole" ze starého
  // Shipardu v1 neřešíme.
  //
  // `onNavigate(leaf)` je synchronní zápis do storu, takže zavření
  // flyoutu hned po volání je bezpečné (past async akcí z frontend.md §9
  // se tu neuplatní).
  import Icon from '../ui/Icon.svelte';
  import Popover from '../ui/Popover.svelte';
  import { resolveIcon } from '../../icons.js';
  import { findLeafById } from '../../utils/navTree.js';

  let { nodes = [], activeId = null, onNavigate } = $props();

  // Otevřený flyout — id skupiny; anchory tlačítek skupin per id kvůli
  // pozicování Popoveru.
  let openGroupId = $state(null);
  let groupButtons = $state({});

  const openGroup = $derived(
    openGroupId !== null ? nodes.find((n) => n.id === openGroupId) ?? null : null
  );

  // Skupina je aktivní, když aktivní leaf leží kdekoli pod ní.
  function isGroupActive(node) {
    return activeId !== null && !!findLeafById(node.children ?? [], activeId);
  }

  function toggleGroup(id) {
    openGroupId = openGroupId === id ? null : id;
  }

  function selectLeaf(leaf) {
    onNavigate?.(leaf);
    openGroupId = null;
  }

  // Při výměně nodes (přepnutí sekce) otevřený flyout nedává smysl.
  $effect(() => {
    void nodes;
    openGroupId = null;
  });
</script>

<ul class="shpd-flyoutstrip">
  {#each nodes as node (node.id)}
    <li>
      {#if node.type}
        <button
          class="shpd-flyoutstrip__item"
          class:shpd-flyoutstrip__item--active={activeId === node.id}
          onclick={() => selectLeaf(node)}
          title={node.label}
        >
          <Icon icon={resolveIcon(node.icon)} size="lg" />
          <span class="shpd-flyoutstrip__label">{node.label}</span>
        </button>
      {:else}
        <button
          class="shpd-flyoutstrip__item"
          class:shpd-flyoutstrip__item--active={isGroupActive(node)}
          class:shpd-flyoutstrip__item--open={openGroupId === node.id}
          onclick={() => toggleGroup(node.id)}
          title={node.label}
          aria-haspopup="menu"
          aria-expanded={openGroupId === node.id}
          bind:this={groupButtons[node.id]}
        >
          <Icon icon={resolveIcon(node.icon)} size="lg" />
          <span class="shpd-flyoutstrip__label">{node.label}</span>
        </button>
      {/if}
    </li>
  {/each}
</ul>

<Popover
  open={openGroup !== null}
  anchor={openGroupId !== null ? groupButtons[openGroupId] ?? null : null}
  placement="right"
  onClose={() => { openGroupId = null; }}
>
  {#if openGroup}
    <div class="shpd-flyoutstrip__flyout" role="menu" aria-label={openGroup.label}>
      {#each openGroup.children ?? [] as child (child.id)}
        {#if child.type}
          <button
            class="shpd-flyoutstrip__flyout-item"
            class:shpd-flyoutstrip__flyout-item--active={activeId === child.id}
            onclick={() => selectLeaf(child)}
            role="menuitem"
          >
            <Icon icon={resolveIcon(child.icon)} size="sm" />
            <span>{child.label}</span>
          </button>
        {:else}
          <!-- Podskupina (úroveň 3): oddělovač s popiskem + její leafy. -->
          <div class="shpd-flyoutstrip__flyout-divider" role="separator">
            <span>{child.label}</span>
          </div>
          {#each child.children ?? [] as leaf (leaf.id)}
            {#if leaf.type}
              <button
                class="shpd-flyoutstrip__flyout-item"
                class:shpd-flyoutstrip__flyout-item--active={activeId === leaf.id}
                onclick={() => selectLeaf(leaf)}
                role="menuitem"
              >
                <Icon icon={resolveIcon(leaf.icon)} size="sm" />
                <span>{leaf.label}</span>
              </button>
            {/if}
          {/each}
        {/if}
      {/each}
    </div>
  {/if}
</Popover>

<style>
  .shpd-flyoutstrip {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    padding: var(--shpd-space-sm) var(--shpd-space-xs);
    list-style: none;
    overflow-y: auto;
    min-height: 0;
  }

  /* Ikona nad popiskem, celá buňka klikatelná — vzhled pásu starého
     Shipardu. Barvy a aktivní signál (accent proužek vlevo) sdílí
     s NavTree/NavIconStrip. */
  .shpd-flyoutstrip__item {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    padding: var(--shpd-space-sm) 0.125rem;
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-flyoutstrip__label {
    max-width: 100%;
    font-size: 0.7rem;
    line-height: 1.15;
    text-align: center;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .shpd-flyoutstrip__item:hover,
  .shpd-flyoutstrip__item--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-flyoutstrip__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
  }

  .shpd-flyoutstrip__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  .shpd-flyoutstrip__item--active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    bottom: 4px;
    width: 3px;
    border-radius: 0 0.125rem 0.125rem 0;
    background-color: var(--shpd-color-accent);
  }

  /* --- Flyout obsah (renderuje se uvnitř Popoveru — surface barvy) --- */

  .shpd-flyoutstrip__flyout {
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
  }

  .shpd-flyoutstrip__flyout-item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    text-align: left;
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.12s;
  }

  .shpd-flyoutstrip__flyout-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-flyoutstrip__flyout-item--active {
    background-color: var(--shpd-color-bg-active, var(--shpd-color-bg-hover));
    font-weight: 500;
  }

  .shpd-flyoutstrip__flyout-divider {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-sm) var(--shpd-space-xs);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-flyoutstrip__flyout-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: var(--shpd-color-border);
  }
</style>
