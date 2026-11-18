<script>
  import Icon from '../ui/Icon.svelte';
  import KebabMenu from '../ui/KebabMenu.svelte';
  import {
    iconMenu, iconChevronLeft, iconAdd, iconEdit,
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

  // Položky sdíleného kebabu (`ui/KebabMenu`, #155 D114).
  let kebabItems = $derived(kebabActions.map((action) => ({
    key: action.id,
    label: action.label,
    run: () => action.onClick?.(),
  })));

  function runAction(action) {
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
      <KebabMenu
        items={kebabItems}
        placement="bottom"
        variant="plain"
        ariaLabel={t('viewer.more')}
      />
    {/if}
  </div>
</header>

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
    width: 2.5rem;
    height: 2.5rem;
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
    min-width: 2.5rem;
    justify-content: flex-end;
    /* Kebab (`ui/KebabMenu`, podoba `plain`) v rozměru a s hoverem
       ostatních tlačítek lišty; barvu textu dědí z lišty. */
    --shpd-kebab-size: 2.5rem;
    --shpd-kebab-hover-bg: var(--shpd-color-bg-sidebar-hover);
  }
</style>
