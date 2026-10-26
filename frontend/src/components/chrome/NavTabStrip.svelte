<script>
  // Chrome primitiv: horizontální pruh uzlů úrovně 2 jako ikonové
  // záložky (wild shell, D2/R5) — leaf = ikona s tooltipem, klik
  // naviguje; skupina = totéž + klik otevírá Popover dropdown s úrovní 3
  // (podskupiny oddělené separátorem s popiskem). Chování 1:1
  // s NavFlyoutStrip, jen orientace (dropdown směrem dolů). Max jeden
  // otevřený dropdown; zavření výběrem / Esc / klikem mimo řeší Popover.
  // Pruh při přetečení scrolluje horizontálně.
  //
  // Aktivní záložka (leaf i skupina s aktivním leafem) zobrazuje svůj
  // popisek (Issue #51) — ostatní zůstávají ikonové s tooltipem.
  //
  // O AI záložce wild shellu primitiv nic neví (R1) — WildShell ji
  // renderuje vedle jako sourozence ve stejném vizuálním pruhu.
  //
  // `onNavigate(leaf)` je synchronní zápis do storu, takže zavření
  // dropdownu hned po volání je bezpečné.
  import Icon from '../ui/Icon.svelte';
  import Popover from '../ui/Popover.svelte';
  import { resolveIcon } from '../../icons.js';
  import { findLeafById } from '../../utils/navTree.js';

  let { nodes = [], activeId = null, onNavigate } = $props();

  // Otevřený dropdown — id skupiny; anchory tlačítek skupin per id kvůli
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

  // Při výměně nodes (přepnutí sekce) otevřený dropdown nedává smysl.
  $effect(() => {
    void nodes;
    openGroupId = null;
  });
</script>

<ul class="shpd-tabstrip">
  {#each nodes as node (node.id)}
    <li>
      {#if node.type}
        <button
          class="shpd-tabstrip__item"
          class:shpd-tabstrip__item--active={activeId === node.id}
          onclick={() => selectLeaf(node)}
          title={node.label}
          aria-label={node.label}
        >
          <Icon icon={resolveIcon(node.icon)} size="md" />
          {#if activeId === node.id}
            <span class="shpd-tabstrip__label">{node.label}</span>
          {/if}
        </button>
      {:else}
        <button
          class="shpd-tabstrip__item"
          class:shpd-tabstrip__item--active={isGroupActive(node)}
          class:shpd-tabstrip__item--open={openGroupId === node.id}
          onclick={() => toggleGroup(node.id)}
          title={node.label}
          aria-label={node.label}
          aria-haspopup="menu"
          aria-expanded={openGroupId === node.id}
          bind:this={groupButtons[node.id]}
        >
          <Icon icon={resolveIcon(node.icon)} size="md" />
          {#if isGroupActive(node)}
            <span class="shpd-tabstrip__label">{node.label}</span>
          {/if}
        </button>
      {/if}
    </li>
  {/each}
</ul>

<Popover
  open={openGroup !== null}
  anchor={openGroupId !== null ? groupButtons[openGroupId] ?? null : null}
  placement="bottom"
  onClose={() => { openGroupId = null; }}
>
  {#if openGroup}
    <div class="shpd-tabstrip__dropdown" role="menu" aria-label={openGroup.label}>
      {#each openGroup.children ?? [] as child (child.id)}
        {#if child.type}
          <button
            class="shpd-tabstrip__dropdown-item"
            class:shpd-tabstrip__dropdown-item--active={activeId === child.id}
            onclick={() => selectLeaf(child)}
            role="menuitem"
          >
            <Icon icon={resolveIcon(child.icon)} size="sm" />
            <span>{child.label}</span>
          </button>
        {:else}
          <!-- Podskupina (úroveň 3): oddělovač s popiskem + její leafy. -->
          <div class="shpd-tabstrip__dropdown-divider" role="separator">
            <span>{child.label}</span>
          </div>
          {#each child.children ?? [] as leaf (leaf.id)}
            {#if leaf.type}
              <button
                class="shpd-tabstrip__dropdown-item"
                class:shpd-tabstrip__dropdown-item--active={activeId === leaf.id}
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
  .shpd-tabstrip {
    display: flex;
    align-items: center;
    gap: 0.125rem;
    list-style: none;
    overflow-x: auto;
    min-width: 0;
  }

  /* Ikonová záložka — barvy a aktivní signál sdílí s TopMenuBarem
     (spodní accent proužek = horizontální obdoba levého v NavTree). */
  .shpd-tabstrip__item {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    min-width: 36px;
    height: 36px;
    padding: 0 var(--shpd-space-sm);
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
    flex-shrink: 0;
  }

  .shpd-tabstrip__label {
    max-width: 180px;
    font-size: var(--shpd-font-size-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-tabstrip__item:hover,
  .shpd-tabstrip__item--open {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-tabstrip__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
  }

  .shpd-tabstrip__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  .shpd-tabstrip__item--active::after {
    content: '';
    position: absolute;
    left: 4px;
    right: 4px;
    bottom: 0;
    height: 3px;
    border-radius: 0.125rem 0.125rem 0 0;
    background-color: var(--shpd-color-accent);
  }

  /* --- Dropdown obsah (renderuje se uvnitř Popoveru — surface barvy) --- */

  .shpd-tabstrip__dropdown {
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
  }

  .shpd-tabstrip__dropdown-item {
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

  .shpd-tabstrip__dropdown-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-tabstrip__dropdown-item--active {
    background-color: var(--shpd-color-bg-active, var(--shpd-color-bg-hover));
    font-weight: 500;
  }

  .shpd-tabstrip__dropdown-divider {
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

  .shpd-tabstrip__dropdown-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background-color: var(--shpd-color-border);
  }
</style>
