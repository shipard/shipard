<script>
  // Chrome primitiv: rekurzivní renderer navigačního stromu — sekce
  // s toggle chevronem, vnořené pod-skupiny, klikatelné leafy se
  // zvýrazněním aktivní položky. Stav rozbalení drží komponenta sama.
  import { untrack } from 'svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconChevronDown, iconChevronRight, resolveIcon } from '../../icons.js';
  import { tn } from '../../i18n/index.js';

  // `sectionBadges` — mapa `{ "<sectionId>": { count, severity } }` z GET
  // /_ui/section-badges (UI shells Fáze 3). Renderuje se jen na root
  // skupinách-sekcích; sentinel `_top` není uzlem stromu, ignoruje se
  // přirozeně. Komponenta je hloupá — data dodává Sidebar ze store.
  let { tree = [], activeId = null, onNavigate, sectionBadges = {} } = $props();

  function badgeCount(count) {
    return count > 99 ? '99+' : String(count);
  }

  // Ids všech skupin na cestě k leafu s daným id (rekurzivně) — na
  // rozbalení předků aktivní položky po loadu stromu.
  function collectAncestorGroupIds(nodes, leafId, path = []) {
    if (!Array.isArray(nodes) || leafId == null) return [];
    for (const node of nodes) {
      if (node?.id === leafId && node?.type) return path;
      if (node?.children) {
        const found = collectAncestorGroupIds(node.children, leafId, [...path, node.id]);
        if (found.length > 0) return found;
      }
    }
    return [];
  }

  // Track expanded state per group id
  let expanded = $state(new Set());

  // Inicializace při změně stromu: root skupiny rozbalené, vnořené
  // startují sbalené — jen předci aktivní položky se rozbalí, jinak by
  // deep-linknutý leaf nebyl vidět. `activeId` čteme untracked: auto-expand
  // se dělá jen po loadu/výměně stromu, ne při každé navigaci (1:1 chování
  // původního Sidebaru).
  $effect(() => {
    const next = new Set(tree.filter((g) => g.children).map((g) => g.id));
    const leafId = untrack(() => activeId);
    for (const groupId of collectAncestorGroupIds(tree, leafId)) {
      next.add(groupId);
    }
    expanded = next;
  });

  function toggleGroup(id) {
    if (expanded.has(id)) {
      expanded.delete(id);
    } else {
      expanded.add(id);
    }
    // Trigger reactivity — reassign
    expanded = new Set(expanded);
  }
</script>

{#each tree as group}
  {#if group.type}
    <!-- Root-level leaf (např. Dashboard) — žádná skupina, klikatelná
         položka přímo. Stejný layout jako leaf v podskupinách, aby
         vizuálně zapadl mezi ostatní položky. -->
    <div class="shpd-navtree__group shpd-navtree__group--leaf">
      <button
        class="shpd-navtree__item"
        class:shpd-navtree__item--active={activeId === group.id}
        data-testid={'nav-' + group.id}
        onclick={() => onNavigate?.(group)}
      >
        <Icon icon={resolveIcon(group.icon)} size="sm" />
        <span>{group.label}</span>
      </button>
    </div>
  {:else}
    <div class="shpd-navtree__group">
      <button
        class="shpd-navtree__group-header"
        data-testid={'navgroup-' + group.id}
        onclick={() => toggleGroup(group.id)}
      >
        <span class="shpd-navtree__group-label">{group.label}</span>
        {#if sectionBadges[group.id]?.count > 0}
          <span
            class="shpd-navtree__badge"
            class:shpd-navtree__badge--danger={sectionBadges[group.id].severity === 'danger'}
            aria-label={tn('sidebar.sectionBadge', sectionBadges[group.id].count)}
          >
            <span class="shpd-navtree__badge-dot" aria-hidden="true"></span>
            {badgeCount(sectionBadges[group.id].count)}
          </span>
        {/if}
        <span class="shpd-navtree__chevron">
          <Icon icon={expanded.has(group.id) ? iconChevronDown : iconChevronRight} size="xs" />
        </span>
      </button>

      {#if expanded.has(group.id)}
        <ul class="shpd-navtree__list">
          {#each group.children as child}
            {#if child.children}
              <!-- Nested sub-group -->
              <li class="shpd-navtree__subgroup">
                <button
                  class="shpd-navtree__subgroup-header"
                  data-testid={'navgroup-' + child.id}
                  onclick={() => toggleGroup(child.id)}
                >
                  <span>{child.label}</span>
                  <span class="shpd-navtree__chevron">
                    <Icon icon={expanded.has(child.id) ? iconChevronDown : iconChevronRight} size="xs" />
                  </span>
                </button>

                {#if expanded.has(child.id)}
                  <ul class="shpd-navtree__list shpd-navtree__list--nested">
                    {#each child.children as leaf}
                      <li>
                        <button
                          class="shpd-navtree__item"
                          class:shpd-navtree__item--active={activeId === leaf.id}
                          data-testid={'nav-' + leaf.id}
                          onclick={() => onNavigate?.(leaf)}
                        >
                          <Icon icon={resolveIcon(leaf.icon)} size="sm" />
                          <span>{leaf.label}</span>
                        </button>
                      </li>
                    {/each}
                  </ul>
                {/if}
              </li>
            {:else}
              <li>
                <button
                  class="shpd-navtree__item"
                  class:shpd-navtree__item--active={activeId === child.id}
                  data-testid={'nav-' + child.id}
                  onclick={() => onNavigate?.(child)}
                >
                  <Icon icon={resolveIcon(child.icon)} size="sm" />
                  <span>{child.label}</span>
                </button>
              </li>
            {/if}
          {/each}
        </ul>
      {/if}
    </div>
  {/if}
{/each}

<style>
  .shpd-navtree__group {
    padding-top: var(--shpd-space-sm);
  }

  .shpd-navtree__group-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-xs);
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--shpd-color-text-sidebar-muted);
    transition: color 0.15s;
  }

  .shpd-navtree__group-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-navtree__group-label {
    flex: 1;
    text-align: left;
  }

  .shpd-navtree__chevron {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }

  /* Badge stavu sekce — tečka v barvě severity + počet. Header má
     uppercase a 0.75rem, badge si drží vlastní neutrální podobu. */
  .shpd-navtree__badge {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    margin-right: var(--shpd-space-xs);
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-navtree__badge-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background-color: var(--shpd-color-warning, #d97706);
  }

  .shpd-navtree__badge--danger .shpd-navtree__badge-dot {
    background-color: var(--shpd-color-danger, #ef4444);
  }

  .shpd-navtree__list {
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-navtree__list--nested {
    padding-left: var(--shpd-space-md);
  }

  .shpd-navtree__subgroup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar-muted);
    transition: color 0.15s;
  }

  .shpd-navtree__subgroup-header:hover {
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-navtree__item {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-sidebar);
    text-align: left;
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
  }

  .shpd-navtree__item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-navtree__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
    font-weight: 500;
  }

  .shpd-navtree__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  /* Levý oranžový proužek u aktivní položky — zvýrazňuje pozici
     uživatele a propojuje navigaci s brand accent barvou. */
  .shpd-navtree__item--active::before {
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
