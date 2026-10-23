<script>
  import Icon from '../ui/Icon.svelte';
  import Popover from '../ui/Popover.svelte';
  import {
    iconMenu, iconChevronLeft, iconMore, iconAdd, iconEdit,
    iconDelete, iconRefresh, iconFilter, resolveIcon,
  } from '../../icons.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { t } from '../../i18n/index.js';

  // Titul: override ze screen surface, jinak z navigace.
  let title = $derived(
    layoutStore.surfaceTitle ?? navigationStore.activeItem?.label ?? ''
  );

  let context = $derived(layoutStore.surfaceContext);
  let actions = $derived(layoutStore.surfaceActions ?? []);

  // V detailu: první akce = hlavní (ikona). Kebab zatím obsahuje VŠECHNY
  // detail akce (včetně té hlavní) — dnes je tam reálně jen Otevřít, a než
  // přibudou další akce, nechceme prázdný kebab. Až jich bude víc, přepnout
  // na `actions.slice(1)`, ať se hlavní akce v kebabu neduplikuje.
  let mainAction   = $derived(context === 'detail' && actions.length > 0 ? actions[0] : null);
  let kebabActions = $derived(context === 'detail' ? actions : []);

  // V seznamu: všechny akce jako ikony (reálně 1–2).
  let listActions = $derived(context === 'list' ? actions : []);

  let kebabOpen = $state(false);
  let kebabAnchor = $state(null);

  function openKebab(e) {
    kebabAnchor = e.currentTarget;
    kebabOpen = true;
  }
  function closeKebab() { kebabOpen = false; }

  function runAction(action) {
    closeKebab();
    action.onClick?.();
  }

  function handleLeft() {
    if (context === 'detail' && layoutStore.surfaceBack) {
      layoutStore.surfaceBack();
    } else {
      layoutStore.toggleDrawer();
    }
  }

  // Mapování běžných action IDs na ikony — paralela k ViewerToolbar,
  // aby akce bez explicitní ikony (edit, create, …) měly v top baru
  // smysluplnou ikonu místo textového fallbacku.
  const defaultActionIcons = {
    'add': iconAdd,
    'create': iconAdd,
    'new': iconAdd,
    'edit': iconEdit,
    'open': iconEdit,
    'delete': iconDelete,
    'remove': iconDelete,
    'refresh': iconRefresh,
    'reanalyze': iconRefresh,
    'runDue': iconRefresh,
    'filter': iconFilter,
  };

  // Ikona akce — backend posílá string jméno, resolveIcon přeloží.
  // Vrací undefined, když se nic nenajde → template pak vyrenderuje
  // textový fallback (label) místo prázdné/rozbité <Icon> (icon.icon by
  // crashlo na undefined). Radši nic než iconTable na ikonovém tlačítku.
  function actionIcon(action) {
    if (typeof action.icon === 'string' && action.icon !== '') {
      const resolved = resolveIcon(action.icon, undefined);
      if (resolved) return resolved;
    } else if (action.icon) {
      return action.icon;
    }
    return defaultActionIcons[action.id] ?? undefined;
  }
</script>

<header class="shpd-topbar">
  <button
    class="shpd-topbar__menu-btn"
    onclick={handleLeft}
    aria-label={context === 'detail' ? t('viewer.back') : t('app.menu.open')}
  >
    <Icon icon={context === 'detail' ? iconChevronLeft : iconMenu} size="md" />
  </button>

  <span class="shpd-topbar__title">{title}</span>

  <div class="shpd-topbar__actions">
    {#if context === 'list'}
      {#each listActions as action (action.id)}
        {@const ico = actionIcon(action)}
        <button
          class="shpd-topbar__action-btn"
          class:shpd-topbar__action-btn--text={!ico}
          onclick={() => runAction(action)}
          aria-label={action.label}
          title={action.label}
        >
          {#if ico}
            <Icon icon={ico} size="md" />
          {:else}
            <span class="shpd-topbar__action-label">{action.label}</span>
          {/if}
        </button>
      {/each}
    {:else if context === 'detail'}
      {#if mainAction}
        {@const ico = actionIcon(mainAction)}
        <button
          class="shpd-topbar__action-btn"
          class:shpd-topbar__action-btn--text={!ico}
          onclick={() => runAction(mainAction)}
          aria-label={mainAction.label}
          title={mainAction.label}
        >
          {#if ico}
            <Icon icon={ico} size="md" />
          {:else}
            <span class="shpd-topbar__action-label">{mainAction.label}</span>
          {/if}
        </button>
      {/if}
      {#if kebabActions.length > 0}
        <button
          class="shpd-topbar__action-btn"
          onclick={openKebab}
          aria-label={t('viewer.more')}
        >
          <Icon icon={iconMore} size="md" />
        </button>
      {/if}
    {/if}
  </div>
</header>

{#if kebabOpen}
  <Popover open={true} anchor={kebabAnchor} placement="bottom" onClose={closeKebab}>
    <div class="shpd-topbar__kebab-menu">
      {#each kebabActions as action (action.id)}
        <button
          type="button"
          class="shpd-topbar__kebab-item"
          onclick={() => runAction(action)}
        >
          {action.label}
        </button>
      {/each}
    </div>
  </Popover>
{/if}

<style>
  .shpd-topbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    height: var(--shpd-header-height);
    padding: 0 var(--shpd-space-sm);
    flex-shrink: 0;
    background-color: var(--shpd-color-bg-sidebar);
    color: var(--shpd-color-text-sidebar);
    border-bottom: 1px solid var(--shpd-color-bg-sidebar-border);
  }

  .shpd-topbar__menu-btn,
  .shpd-topbar__action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    color: var(--shpd-color-text-sidebar);
    background: transparent;
    border: none;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  .shpd-topbar__menu-btn:hover,
  .shpd-topbar__action-btn:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
  }

  /* Textový fallback — akce bez ikony se zobrazí jako popisek.
     Tlačítko se roztáhne na šířku textu místo pevných 40px. */
  .shpd-topbar__action-btn--text {
    width: auto;
    padding: 0 var(--shpd-space-sm);
  }

  .shpd-topbar__action-label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    white-space: nowrap;
  }

  .shpd-topbar__title {
    flex: 1;
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-topbar__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    min-width: 40px;
    justify-content: flex-end;
  }

  /* Kebab menu — položky v Popoveru. Stejný vzor jako detail dropdown.
     Popover má světlé pozadí (--shpd-color-bg), proto text --shpd-color-text
     (ne sidebar text jako tlačítka v modrém top baru). */
  .shpd-topbar__kebab-menu {
    display: flex;
    flex-direction: column;
    min-width: 160px;
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-topbar__kebab-item {
    text-align: left;
    padding: var(--shpd-space-sm) 0.875rem;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }

  .shpd-topbar__kebab-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }
</style>
