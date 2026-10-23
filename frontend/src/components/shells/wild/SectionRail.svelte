<script>
  // Svislý rail sekcí wild shellu (D1): nahoře ikona aplikace (tooltip =
  // název), domeček (`_top`) + sekce z navSections jako ikony s popiskem
  // pod sebou (Issue #51; dříve jen tooltip), badge z Fáze 3 (sekce pod svým id, domeček pod
  // `_top` — první místo, kde se `_top` badge kreslí). Dole trigger
  // palety + UserMenu (compact); bez chrome tlačítka chatu — AI je
  // v záložkách (D1).
  //
  // Klik na rail NEnaviguje — jen mění prohlíženou sekci (D5); navigaci
  // (přistání dle R6) řeší WildShell přes onSelectSection. Aktivní
  // zvýraznění = prohlížená sekce, ne activeSection.
  import Icon from '../../ui/Icon.svelte';
  import UserMenu from '../../chrome/UserMenu.svelte';
  import { appInfoStore } from '../../../stores/appInfo.svelte.js';
  import { brandingUrl } from '../../../api/app.js';
  import { navigationStore } from '../../../stores/navigation.svelte.js';
  import { paletteStore } from '../../../stores/palette.svelte.js';
  import { sectionBadgesStore } from '../../../stores/sectionBadges.svelte.js';
  import { t, tn } from '../../../i18n/index.js';
  import { resolveIcon, iconHome, iconSearch } from '../../../icons.js';

  let { browsingSection = null, onSelectSection, onOpenThemePanel } = $props();

  const tree = $derived(navigationStore.appNavTree ?? []);
  // Sekce = root uzly se children (leafy `_top`/dashboard/chat mají type).
  const sections = $derived(tree.filter((n) => !n?.type && n?.children?.length));

  const appTitle = $derived(appInfoStore.name ?? appInfoStore.shortName ?? 'Shipard');

  function badgeCount(count) {
    return count > 99 ? '99+' : String(count);
  }

  // Trigger palety — tooltip se zkratkou dle platformy (vzor TopMenuBar).
  const isMac = /Mac|iPhone|iPad/.test(navigator.platform ?? '');
  const searchTitle = t('palette.trigger') + ' · ' + (isMac ? '⌘K' : 'Ctrl+K');
</script>

<aside class="shpd-rail">
  <div class="shpd-rail__brand" title={appTitle}>
    {#if appInfoStore.icon}
      <img
        class="shpd-rail__app-icon"
        src={brandingUrl('icon', appInfoStore.icon.hash)}
        alt=""
      />
    {:else}
      <span class="shpd-rail__app-fallback">{(appInfoStore.shortName ?? 'S').slice(0, 1)}</span>
    {/if}
  </div>

  <nav class="shpd-rail__nav" aria-label={t('shell.wild.sections')}>
    <button
      class="shpd-rail__item"
      class:shpd-rail__item--active={browsingSection === null}
      onclick={() => onSelectSection?.(null)}
      title={t('shell.wild.home')}
      aria-label={t('shell.wild.home')}
    >
      <Icon icon={iconHome} size="lg" />
      <span class="shpd-rail__label">{t('shell.wild.home')}</span>
      {#if sectionBadgesStore.badges._top?.count > 0}
        <span
          class="shpd-rail__badge"
          class:shpd-rail__badge--danger={sectionBadgesStore.badges._top.severity === 'danger'}
          aria-label={tn('sidebar.sectionBadge', sectionBadgesStore.badges._top.count)}
        >{badgeCount(sectionBadgesStore.badges._top.count)}</span>
      {/if}
    </button>

    {#each sections as section (section.id)}
      <button
        class="shpd-rail__item"
        class:shpd-rail__item--active={browsingSection === section.id}
        onclick={() => onSelectSection?.(section.id)}
        title={section.label}
        aria-label={section.label}
      >
        <Icon icon={resolveIcon(section.icon)} size="lg" />
        <span class="shpd-rail__label">{section.label}</span>
        {#if sectionBadgesStore.badges[section.id]?.count > 0}
          <span
            class="shpd-rail__badge"
            class:shpd-rail__badge--danger={sectionBadgesStore.badges[section.id].severity === 'danger'}
            aria-label={tn('sidebar.sectionBadge', sectionBadgesStore.badges[section.id].count)}
          >{badgeCount(sectionBadgesStore.badges[section.id].count)}</span>
        {/if}
      </button>
    {/each}
  </nav>

  <div class="shpd-rail__footer">
    <button
      class="shpd-rail__item"
      onclick={() => paletteStore.openPalette()}
      title={searchTitle}
      aria-label={searchTitle}
    >
      <Icon icon={iconSearch} size="lg" />
    </button>
    <UserMenu compact {onOpenThemePanel} />
  </div>
</aside>

<style>
  /* Rail sdílí barevný systém sidebaru (vč. custom theme gradientu);
     šířka = collapsed sidebar token (R8 — žádný nový token). */
  .shpd-rail {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: var(--shpd-rail-width);
    flex-shrink: 0;
    padding: var(--shpd-space-sm) 0;
    background: var(--shpd-sidebar-bg-image, var(--shpd-color-bg-sidebar));
    /* Bez overflow: hidden — side-overlay dropdown UserMenu (compact)
       vyjíždí doprava MIMO rail a ořez by ho skryl; scroll řeší __nav. */
  }

  .shpd-rail__brand {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    margin-bottom: var(--shpd-space-sm);
    flex-shrink: 0;
  }

  .shpd-rail__app-icon {
    width: 24px;
    height: 24px;
    object-fit: contain;
    border-radius: var(--shpd-radius-sm);
  }

  .shpd-rail__app-fallback {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    color: var(--shpd-color-text-sidebar);
  }

  .shpd-rail__nav {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    width: 100%;
    gap: 0.125rem;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    /* Badge přesahuje tlačítko — bez rezervy by ho overflow ořízl. */
    padding: 0.125rem var(--shpd-space-xs);
  }

  /* Ikona s popiskem pod sebou (Issue #51, jazyk NavFlyoutStrip) —
     aktivní signál = levý accent proužek. */
  .shpd-rail__item {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    padding: var(--shpd-space-sm) 0.125rem;
    color: var(--shpd-color-text-sidebar);
    border-radius: var(--shpd-radius-sm);
    transition: background-color 0.15s, opacity 0.15s;
    opacity: 0.85;
    flex-shrink: 0;
  }

  .shpd-rail__label {
    max-width: 100%;
    font-size: 0.7rem;
    line-height: 1.15;
    text-align: center;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .shpd-rail__item:hover {
    background-color: var(--shpd-color-bg-sidebar-hover);
    opacity: 1;
  }

  .shpd-rail__item--active {
    background-color: var(--shpd-color-sidebar-active-bg);
    opacity: 1;
  }

  .shpd-rail__item--active:hover {
    background-color: var(--shpd-color-sidebar-active-bg-hover);
  }

  .shpd-rail__item--active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 4px;
    bottom: 4px;
    width: 3px;
    border-radius: 0 0.125rem 0.125rem 0;
    background-color: var(--shpd-color-accent);
  }

  /* Badge — počet v pilulce vpravo nahoře (ikonový rail nemá místo
     na tečku + počet vedle textu jako NavTree/TopMenuBar). */
  .shpd-rail__badge {
    position: absolute;
    top: 0;
    right: 12px;
    min-width: 14px;
    height: 14px;
    padding: 0 0.1875rem;
    border-radius: 0.4375rem;
    font-size: 0.6rem;
    font-weight: 700;
    line-height: 14px;
    text-align: center;
    color: #fff;
    background-color: var(--shpd-color-warning, #d97706);
  }

  .shpd-rail__badge--danger {
    background-color: var(--shpd-color-danger, #ef4444);
  }

  .shpd-rail__footer {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    padding: 0 var(--shpd-space-xs);
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    margin-top: var(--shpd-space-sm);
  }
</style>
