<script>
  // Command palette overlay (Ctrl/Cmd+K) — shell-nezávislá, renderuje ji
  // AppShell; shelly dodávají jen trigger (docs/ui-shells.md §4, §9).
  // Vlastní overlay, ne ui/Modal (R2): panel v horní třetině, bez
  // headeru/footeru, input jako první prvek. Lifecycle (Esc, klik mimo,
  // keydown registrovaný až po otevření) dle vzoru ThemePanel.
  import { paletteStore } from '../../stores/palette.svelte.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { resolveIcon, iconSearch, iconWarning, iconSpinner } from '../../icons.js';

  let inputEl = $state(null);
  let listEl = $state(null);

  // Skupiny s offsetem do plochého seznamu — flat index řídí aktivní
  // řádek a aria-activedescendant.
  const groups = $derived.by(() => {
    let offset = 0;
    return paletteStore.results.map((g) => {
      const withOffset = { ...g, offset };
      offset += g.items.length;
      return withOffset;
    });
  });

  const hasItems = $derived(paletteStore.flatItems.length > 0);
  const activeDescendant = $derived(
    hasItems ? `shpd-palette-item-${paletteStore.activeIndex}` : undefined);

  // Zvýraznění shod: label → segmenty dle ranges (indexy původního labelu).
  function segments(label, ranges) {
    if (!ranges?.length) return [{ text: label, hit: false }];
    const out = [];
    let pos = 0;
    for (const [start, end] of ranges) {
      if (start > pos) out.push({ text: label.slice(pos, start), hit: false });
      out.push({ text: label.slice(start, end), hit: true });
      pos = end;
    }
    if (pos < label.length) out.push({ text: label.slice(pos), hit: false });
    return out;
  }

  // Klávesy lokálně po dobu otevření (vzor ThemePanel) — globální je jen
  // otevírací zkratka v AppShellu. Autofocus inputu po otevření.
  $effect(() => {
    if (!paletteStore.open) return;
    inputEl?.focus();

    function onKeyDown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        paletteStore.closePalette();
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        paletteStore.moveActive(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        paletteStore.moveActive(-1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        paletteStore.confirmActive();
      }
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  });

  // Aktivní řádek držet ve viditelné části seznamu (šipky přes okraj).
  $effect(() => {
    const index = paletteStore.activeIndex;
    listEl?.querySelector(`#shpd-palette-item-${index}`)
      ?.scrollIntoView({ block: 'nearest' });
  });
</script>

{#if paletteStore.open}
  <div
    class="shpd-palette__backdrop"
    onclick={() => paletteStore.closePalette()}
    aria-hidden="true"
  ></div>

  <div class="shpd-palette" data-testid="palette" role="dialog" aria-label={t('palette.title')}>
    <div class="shpd-palette__input-row">
      <Icon icon={iconSearch} size="sm" />
      <input
        bind:this={inputEl}
        class="shpd-palette__input"
        data-testid="palette-input"
        type="text"
        value={paletteStore.query}
        oninput={(e) => paletteStore.setQuery(e.target.value)}
        placeholder={t('palette.placeholder')}
        aria-label={t('palette.title')}
        aria-activedescendant={activeDescendant}
        autocomplete="off"
        spellcheck="false"
      />
    </div>

    <div class="shpd-palette__results" bind:this={listEl} role="listbox">
      {#if paletteStore.loading}
        <div class="shpd-palette__status">
          <Icon icon={iconSpinner} spin size="sm" />
          <span>{t('common.loading')}</span>
        </div>
      {:else}
        {#each groups as group (group.key)}
          <div class="shpd-palette__group">
            <div class="shpd-palette__group-title">{t(group.groupKey)}</div>
            {#if group.error}
              <div class="shpd-palette__status shpd-palette__status--error">
                <Icon icon={iconWarning} size="sm" />
                <span>{t('palette.loadFailed')}</span>
              </div>
            {/if}
            {#each group.items as item, i (item.key)}
              {@const flatIndex = group.offset + i}
              <button
                id={'shpd-palette-item-' + flatIndex}
                class="shpd-palette__item"
                class:shpd-palette__item--active={flatIndex === paletteStore.activeIndex}
                role="option"
                aria-selected={flatIndex === paletteStore.activeIndex}
                onmousemove={() => paletteStore.setActive(flatIndex)}
                onclick={() => { paletteStore.setActive(flatIndex); paletteStore.confirmActive(); }}
              >
                <span class="shpd-palette__item-icon">
                  <Icon icon={resolveIcon(item.icon)} size="sm" />
                </span>
                <span class="shpd-palette__item-text">
                  <span class="shpd-palette__item-label">
                    {#each segments(item.label, item.ranges) as seg}
                      {#if seg.hit}<mark class="shpd-palette__hit">{seg.text}</mark>{:else}{seg.text}{/if}
                    {/each}
                  </span>
                  {#if item.groupLabel}
                    <span class="shpd-palette__item-group">{item.groupLabel}</span>
                  {/if}
                </span>
              </button>
            {/each}
          </div>
        {/each}
        {#if !hasItems && !groups.some((g) => g.error)}
          <div class="shpd-palette__status">
            {paletteStore.query.trim() === '' ? t('palette.hint') : t('palette.empty')}
          </div>
        {/if}
      {/if}
    </div>
  </div>
{/if}

<style>
  .shpd-palette__backdrop {
    position: fixed;
    inset: 0;
    background-color: var(--shpd-color-overlay);
    /* Nad ThemePanel (200) i mobilním drawerem (100), spolu s panelem
       na vrstvě modálů. */
    z-index: 1000;
  }

  .shpd-palette {
    position: fixed;
    top: 12vh;
    left: 50%;
    transform: translateX(-50%);
    width: min(600px, calc(100vw - 2 * var(--shpd-space-md)));
    max-height: 60vh;
    display: flex;
    flex-direction: column;
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-lg);
    z-index: 1001;
    overflow: hidden;
  }

  .shpd-palette__input-row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-palette__input {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
  }

  .shpd-palette__results {
    overflow-y: auto;
    padding: var(--shpd-space-sm) 0;
  }

  .shpd-palette__group-title {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-palette__item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    text-align: left;
    color: var(--shpd-color-text);
  }

  .shpd-palette__item--active {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-palette__item-icon {
    flex-shrink: 0;
    display: flex;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-palette__item-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .shpd-palette__item-label {
    font-size: var(--shpd-font-size-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-palette__item-group {
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .shpd-palette__hit {
    background: none;
    color: var(--shpd-color-accent);
    font-weight: 600;
  }

  .shpd-palette__status {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-palette__status--error {
    color: var(--shpd-color-danger, #ef4444);
  }
</style>
