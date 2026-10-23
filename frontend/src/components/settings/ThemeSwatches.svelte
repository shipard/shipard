<script>
  // Sdílený editor custom vzhledu sidebaru: báze (světlý/tmavý), stránkovaný
  // grid presetů (plné barvy / přechody), slider intenzity a nativní color
  // picker. Controlled — dostane `custom` config a callbacky, sám nedrží
  // model (kromě presetPage = která stránka gridu je vidět).
  //
  // Používá ThemePanel (user, vázaný na themeStore) i DsThemeField (DS default,
  // controlled přes savePage). Žádný import themeStore — komponenta je nezávislá.
  import { untrack } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { THEME_PRESETS, THEME_GRADIENT_PRESETS } from '../layout/themePresets.js';
  import Icon from '../ui/Icon.svelte';
  import { iconChevronLeft, iconChevronRight } from '../../icons.js';

  let { custom, onSelectBase, onSelectColor, onSelectGradient, onSelectOpacity } = $props();

  // 0 = plné barvy, 1 = přechody. Init podle aktuálního výběru — jen
  // počáteční hodnota (untrack), dál je presetPage řízena uživatelem.
  let presetPage = $state(untrack(() => (custom?.sidebar?.type === 'gradient' ? 1 : 0)));
  const PAGE_COUNT = 2;

  function isSolidSelected(preset) {
    const sb = custom?.sidebar;
    return sb?.type === 'solid'
      && sb.color?.toLowerCase() === preset.color.toLowerCase();
  }

  function isGradientSelected(preset) {
    const sb = custom?.sidebar;
    return sb?.type === 'gradient'
      && sb.stops?.length === 2
      && sb.stops[0].toLowerCase() === preset.stops[0].toLowerCase()
      && sb.stops[1].toLowerCase() === preset.stops[1].toLowerCase();
  }

  // Custom color input je solid-only (vlastní gradient = budoucí fáze).
  // Při aktivním gradientu zobrazuje první stop; interakce přepne na solid.
  const colorInputValue = $derived(
    custom?.sidebar?.type === 'gradient'
      ? custom.sidebar.stops[0]
      : custom?.sidebar?.color ?? '#00345C',
  );
</script>

<div class="shpd-theme-swatches">
  <div class="shpd-theme-swatches__section">
    <div class="shpd-theme-swatches__label">{t('theme.panel.base')}</div>
    <div class="shpd-theme-swatches__base-toggle" role="radiogroup" aria-label={t('theme.panel.base')}>
      <button
        type="button"
        class="shpd-theme-swatches__base-button"
        class:shpd-theme-swatches__base-button--active={custom?.base === 'light'}
        onclick={() => onSelectBase?.('light')}
        role="radio"
        aria-checked={custom?.base === 'light'}
      >{t('theme.base.light')}</button>
      <button
        type="button"
        class="shpd-theme-swatches__base-button"
        class:shpd-theme-swatches__base-button--active={custom?.base === 'dark'}
        onclick={() => onSelectBase?.('dark')}
        role="radio"
        aria-checked={custom?.base === 'dark'}
      >{t('theme.base.dark')}</button>
    </div>
  </div>

  <div class="shpd-theme-swatches__section">
    <div class="shpd-theme-swatches__presets-row">
      <button
        type="button"
        class="shpd-theme-swatches__page-arrow"
        onclick={() => { presetPage = Math.max(presetPage - 1, 0); }}
        disabled={presetPage === 0}
        aria-label={t('theme.panel.prevPage')}
      >
        <Icon icon={iconChevronLeft} size="sm" />
      </button>

      {#if presetPage === 0}
        <div class="shpd-theme-swatches__presets" role="listbox" aria-label={t('theme.panel.pageSolid')}>
          {#each THEME_PRESETS as preset (preset.id)}
            <button
              type="button"
              class="shpd-theme-swatches__swatch"
              class:shpd-theme-swatches__swatch--selected={isSolidSelected(preset)}
              style="background: {preset.color}"
              onclick={() => onSelectColor?.(preset.color)}
              title={t(preset.nameKey)}
              aria-label={t(preset.nameKey)}
              role="option"
              aria-selected={isSolidSelected(preset)}
            ></button>
          {/each}
        </div>
      {:else}
        <div class="shpd-theme-swatches__presets" role="listbox" aria-label={t('theme.panel.pageGradient')}>
          {#each THEME_GRADIENT_PRESETS as preset (preset.id)}
            <button
              type="button"
              class="shpd-theme-swatches__swatch"
              class:shpd-theme-swatches__swatch--selected={isGradientSelected(preset)}
              style="background: linear-gradient(180deg, {preset.stops[0]}, {preset.stops[1]})"
              onclick={() => onSelectGradient?.(preset.stops)}
              title={t(preset.nameKey)}
              aria-label={t(preset.nameKey)}
              role="option"
              aria-selected={isGradientSelected(preset)}
            ></button>
          {/each}
        </div>
      {/if}

      <button
        type="button"
        class="shpd-theme-swatches__page-arrow"
        onclick={() => { presetPage = Math.min(presetPage + 1, PAGE_COUNT - 1); }}
        disabled={presetPage === PAGE_COUNT - 1}
        aria-label={t('theme.panel.nextPage')}
      >
        <Icon icon={iconChevronRight} size="sm" />
      </button>
    </div>

    <div class="shpd-theme-swatches__dots">
      <button
        type="button"
        class="shpd-theme-swatches__dot"
        class:shpd-theme-swatches__dot--active={presetPage === 0}
        onclick={() => { presetPage = 0; }}
        aria-label={t('theme.panel.pageSolid')}
      ></button>
      <button
        type="button"
        class="shpd-theme-swatches__dot"
        class:shpd-theme-swatches__dot--active={presetPage === 1}
        onclick={() => { presetPage = 1; }}
        aria-label={t('theme.panel.pageGradient')}
      ></button>
    </div>
  </div>

  <div class="shpd-theme-swatches__section">
    <label class="shpd-theme-swatches__label" for="shpd-theme-opacity">
      {t('theme.panel.opacity')}
      <span class="shpd-theme-swatches__opacity-value">{custom?.opacity ?? 100} %</span>
    </label>
    <input
      id="shpd-theme-opacity"
      class="shpd-theme-swatches__opacity-slider"
      type="range" min="0" max="100" step="5"
      value={custom?.opacity ?? 100}
      oninput={(e) => onSelectOpacity?.(e.target.value)}
    />
  </div>

  <div class="shpd-theme-swatches__section">
    <label class="shpd-theme-swatches__label" for="shpd-theme-custom-color">
      {t('theme.panel.customColor')}
    </label>
    <!-- oninput (ne jen onchange) — live preview už při tažení v pickeru -->
    <input
      id="shpd-theme-custom-color"
      class="shpd-theme-swatches__color-input"
      type="color"
      value={colorInputValue}
      oninput={(e) => onSelectColor?.(e.target.value)}
    />
  </div>
</div>

<style>
  .shpd-theme-swatches__section {
    margin-bottom: var(--shpd-space-md);
  }

  .shpd-theme-swatches__section:last-child {
    margin-bottom: 0;
  }

  .shpd-theme-swatches__label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
  }

  /* Segmented control — dvě toggle tlačítka vedle sebe. */
  .shpd-theme-swatches__base-toggle {
    display: flex;
    gap: var(--shpd-space-xs);
  }

  .shpd-theme-swatches__base-button {
    flex: 1;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s, color 0.15s, border-color 0.15s;
  }

  .shpd-theme-swatches__base-button--active {
    color: var(--shpd-color-bg);
    background: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
    font-weight: 500;
  }

  /* Stránkovaný grid: [‹] [grid] [›], tečky pod gridem. */
  .shpd-theme-swatches__presets-row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
  }

  .shpd-theme-swatches__page-arrow {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    align-self: stretch;
    padding: 0;
    flex-shrink: 0;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: background-color 0.15s, color 0.15s;
  }

  .shpd-theme-swatches__page-arrow:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  .shpd-theme-swatches__page-arrow:disabled {
    color: var(--shpd-color-border-strong);
    cursor: default;
  }

  .shpd-theme-swatches__presets {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--shpd-space-sm);
    justify-items: center;
    flex: 1;
  }

  .shpd-theme-swatches__dots {
    display: flex;
    justify-content: center;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-sm);
  }

  .shpd-theme-swatches__dot {
    width: 8px;
    height: 8px;
    padding: 0;
    border-radius: 50%;
    background-color: var(--shpd-color-border-strong);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  .shpd-theme-swatches__dot--active {
    background-color: var(--shpd-color-text);
  }

  .shpd-theme-swatches__swatch {
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid color-mix(in srgb, var(--shpd-color-text) 25%, transparent);
    border-radius: 50%;
    cursor: pointer;
    transition: border-color 0.15s;
  }

  .shpd-theme-swatches__swatch:hover {
    border-color: var(--shpd-color-text-secondary);
  }

  .shpd-theme-swatches__swatch--selected {
    outline: 2px solid var(--shpd-color-accent);
    outline-offset: 2px;
  }

  .shpd-theme-swatches__opacity-slider {
    width: 100%;
    accent-color: var(--shpd-color-primary);
  }

  .shpd-theme-swatches__opacity-value {
    font-weight: 400;
    color: var(--shpd-color-text);
    font-variant-numeric: tabular-nums;
  }

  .shpd-theme-swatches__color-input {
    width: 100%;
    height: 36px;
    padding: 0.125rem;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
  }
</style>
