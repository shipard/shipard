<script>
  // Panel custom vzhledu — otevírá se z dropdownu v patce sidebaru
  // (volba Vlastní). Každá interakce volá themeStore.setCustom(),
  // tokeny se aplikují okamžitě (live preview); persistence je vedlejší
  // efekt — žádné tlačítko Uložit/Použít.
  //
  // Desktop: fixed panel přilehlý k sidebaru (pozice reaguje na
  // collapsed stav). Mobil: strukturní přepnutí — obsah se renderuje
  // uvnitř <Modal> (fullscreen automaticky). Stejný vzor jako
  // FormInline / FormStateBar.
  //
  // Preset grid se stránkuje šipkami po stranách (stránka 0 = plné
  // barvy, 1 = přechody), tečky pod gridem indikují stránku.
  import { themeStore } from '../../stores/theme.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';
  import Modal from '../ui/Modal.svelte';
  import Icon from '../ui/Icon.svelte';
  import ThemeSwatches from '../settings/ThemeSwatches.svelte';
  import { iconClose } from '../../icons.js';

  // `leftOffset` — CSS délka levého okraje panelu na desktopu; hlásí ji
  // aktivní shell přes AppShell (sidebar dle collapsed stavu, classic
  // konstantou šířky pásu). CSS string místo čísla, ať zůstane calc()
  // nad tokeny.
  let {
    open,
    onClose,
    leftOffset = 'calc(var(--shpd-sidebar-width) + var(--shpd-space-sm))',
  } = $props();

  let panelRoot = $state(null);

  function selectBase(base) {
    themeStore.setCustom({ base });
  }

  function selectColor(color) {
    themeStore.setCustom({ sidebar: { type: 'solid', color } });
  }

  function selectGradient(stops) {
    // opacity je top-level, takže výměna sidebar objektu ji zachová
    themeStore.setCustom({ sidebar: { type: 'gradient', stops } });
  }

  function selectOpacity(value) {
    themeStore.setCustom({ opacity: Number(value) });
  }

  // Zavírání na desktopu: Esc + klik mimo panel. Listener se registruje
  // až po otevření ($effect po renderu) — otevírací klik z dropdownu už
  // dobublal, takže panel hned nezavře (viz past v docs/frontend.md
  // sekce Konvence → Dropdown / popover komponenty).
  $effect(() => {
    if (!open || layoutStore.isMobile) return;

    function onDocClick(e) {
      if (panelRoot && !panelRoot.contains(e.target)) {
        onClose();
      }
    }
    function onKeyDown(e) {
      if (e.key === 'Escape') onClose();
    }

    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onKeyDown);
    };
  });
</script>

{#snippet panelContent()}
  <ThemeSwatches
    custom={themeStore.custom}
    onSelectBase={selectBase}
    onSelectColor={selectColor}
    onSelectGradient={selectGradient}
    onSelectOpacity={selectOpacity}
  />
{/snippet}

{#if layoutStore.isMobile}
  <Modal title={t('theme.panel.title')} {open} {onClose}>
    {@render panelContent()}
  </Modal>
{:else if open}
  <div
    class="shpd-theme-panel"
    style:left={leftOffset}
    bind:this={panelRoot}
    role="dialog"
    aria-label={t('theme.panel.title')}
  >
    <div class="shpd-theme-panel__header">
      <span class="shpd-theme-panel__title">{t('theme.panel.title')}</span>
      <button class="shpd-theme-panel__close" onclick={onClose} aria-label={t('common.close')}>
        <Icon icon={iconClose} size="sm" />
      </button>
    </div>
    {@render panelContent()}
  </div>
{/if}

<style>
  .shpd-theme-panel {
    position: fixed;
    top: 64px;
    /* left dodává inline style:left (prop leftOffset). */
    width: 340px;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-lg);
    /* Stejná vrstva jako user menu dropdown v sidebaru — nad obsahem,
       pod modály (1000). */
    z-index: 200;
    padding: var(--shpd-space-md);
  }

  .shpd-theme-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-theme-panel__title {
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-theme-panel__close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    transition: background-color 0.15s, color 0.15s;
  }

  .shpd-theme-panel__close:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }
</style>
