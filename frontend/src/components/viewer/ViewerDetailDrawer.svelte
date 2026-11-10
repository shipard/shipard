<script>
  // Non-modální slide-over detail — docs/viewer-grid.md §6 (D4). Slouží
  // grid layoutu a úzkému vieweru se seznamem (#133 D58: viewer užší než
  // seznam + nejužší rozumný detail). Bez overlay: seznam pod drawerem
  // zůstává interaktivní, klik na jiný řádek přepíná detail. Obsahem je
  // stávající ViewerDetail (props kontrakt beze změny). Na mobilu (pod
  // breakpointem shellu) se drawer nepoužívá — tam platí přepínání
  // seznam / detail přes celou šířku (D42).
  import ViewerDetail from './ViewerDetail.svelte';
  import Button from '../ui/Button.svelte';
  import { isModalOpen } from '../ui/Modal.svelte';
  import { t } from '../../i18n/index.js';

  let {
    detail = null,
    loading = false,
    actions = [],
    /** Změřená šířka vieweru v px (Viewer.svelte měří .shpd-viewer__body);
     *  0 / null = neznámá. Horní mez šířky draweru — viz max-width níže. */
    viewerWidth = 0,
    /** Akce hlavičky (detailToolbar) — kontrakt handleToolbarAction(id),
     *  stejně jako desktop ViewerToolbar / mobile top bar. */
    onToolbarAction,
    /** Akce uvnitř ViewerDetail (detail.actions) — kontrakt
     *  handleDetailAction(id, action, value). */
    onAction,
    onRefresh,
    onClose,
  } = $props();

  // `create` (Přidat) patří jen seznamu — stejný filtr jako mobile top bar
  // ve Viewer.svelte.
  let headerActions = $derived(
    (actions ?? []).filter(a => a.id !== 'create' && a.id !== 'add' && a.id !== 'new')
  );

  // Esc zavírá drawer jen když není otevřený žádný Modal — jinak patří
  // event modálu na vrcholu stacku (FormDialog z detail akce). Modal se
  // v témže keydownu zavře sám; drawer stack ještě vidí neprázdný.
  function handleKeydown(e) {
    if (e.key === 'Escape' && !isModalOpen()) {
      onClose?.();
    }
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div
  class="shpd-drawer"
  role="complementary"
  aria-label={t('viewer.selectRecord')}
  style:--shpd-viewer-width={viewerWidth > 0 ? `${viewerWidth}px` : null}
>
  <div class="shpd-drawer__header">
    <div class="shpd-drawer__actions">
      {#each headerActions as action (action.id)}
        <Button
          label={action.label}
          icon={action.icon}
          variant={action.variant ?? 'secondary'}
          size="sm"
          onclick={() => onToolbarAction?.(action.id)}
        />
      {/each}
    </div>
    <button class="shpd-drawer__close" onclick={() => onClose?.()} aria-label={t('viewer.drawer.close')}>×</button>
  </div>
  <div class="shpd-drawer__body">
    <ViewerDetail {detail} {loading} {onRefresh} {onAction} />
  </div>
</div>

<style>
  .shpd-drawer {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 35rem;
    /* Horní mez je šířka VIEWERU, ne okna: drawer je position: fixed
       u pravého okraje okna a viewer sahá k témuž okraji, takže drawer
       širší než viewer by přesáhl přes navigaci vlevo (sidebar shell,
       okno 800 px: viewer 540 px, drawer 560 px — uříznuté šipky sekcí;
       90vw tu nepomůže). --shpd-viewer-width nastavuje Viewer.svelte ze
       změřené šířky .shpd-viewer__body (px z měření vykresleného prvku,
       D46); bez ní platí jen strop podle okna. */
    max-width: min(90vw, var(--shpd-viewer-width, 90vw));
    display: flex;
    flex-direction: column;
    background-color: var(--shpd-color-bg);
    border-left: 1px solid var(--shpd-color-border);
    box-shadow: var(--shpd-shadow-lg);
    /* Pod modálem (.shpd-modal má z-index 1000) — FormDialog z detail
       akce se otevírá NAD drawerem. */
    z-index: 900;
    animation: shpd-drawer-slide-in 0.18s ease;
  }

  @keyframes shpd-drawer-slide-in {
    from { transform: translateX(100%); }
    to   { transform: translateX(0); }
  }

  .shpd-drawer__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-drawer__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    min-width: 0;
  }

  .shpd-drawer__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    padding: 0;
    background: none;
    border: none;
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xl);
    line-height: 1;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: background-color 0.15s ease, color 0.15s ease;
    flex-shrink: 0;
  }

  .shpd-drawer__close:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-drawer__body {
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }
</style>
