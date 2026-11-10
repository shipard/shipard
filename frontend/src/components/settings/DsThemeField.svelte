<script>
  // Controlled editor DS-wide výchozího vzhledu (app.theme, scope ds).
  // Na rozdíl od ThemeField NEpíše do themeStore (to je user override) —
  // edituje předanou `value` přes `onchange` a ukládá se přes tlačítko
  // Uložit na stránce (jako text pole). Žádné živé vysílání mezistavů
  // všem uživatelům.
  //
  // value = { mode, custom } | null. Segmented control + (u „Vlastní")
  // inline ThemeSwatches.
  import ThemeModeSegments from './ThemeModeSegments.svelte';
  import ThemeSwatches from './ThemeSwatches.svelte';

  let { value, onchange } = $props();

  // Sdílené s theme.svelte.js DEFAULT_CUSTOM (Shipard modrá, světlá báze).
  const DEFAULT_CUSTOM = {
    version: 1,
    base: 'light',
    opacity: 100,
    sidebar: { type: 'solid', color: '#00345C' },
  };

  const VALID_MODES = ['light', 'dark', 'custom'];

  // Normalizovaná pracovní kopie z value (nebo defaulty).
  const current = $derived.by(() => {
    const mode = VALID_MODES.includes(value?.mode) ? value.mode : 'light';
    const c = value?.custom;
    const custom = (c && (c.sidebar?.color || c.sidebar?.stops))
      ? { ...c, opacity: typeof c.opacity === 'number' ? c.opacity : 100 }
      : DEFAULT_CUSTOM;
    return { mode, custom };
  });

  function selectMode(mode) {
    // Custom config si neseme i u light/dark (poslední známá volba).
    onchange?.({ mode, custom: current.custom });
  }

  function patchCustom(patch) {
    onchange?.({ mode: 'custom', custom: { ...current.custom, ...patch } });
  }
</script>

<div class="shpd-ds-theme-field">
  <ThemeModeSegments mode={current.mode} onSelect={selectMode} />

  {#if current.mode === 'custom'}
    <div class="shpd-ds-theme-field__swatches">
      <ThemeSwatches
        custom={current.custom}
        onSelectBase={(b) => patchCustom({ base: b })}
        onSelectColor={(c) => patchCustom({ sidebar: { type: 'solid', color: c } })}
        onSelectGradient={(s) => patchCustom({ sidebar: { type: 'gradient', stops: s } })}
        onSelectOpacity={(o) => patchCustom({ opacity: Number(o) })}
      />
    </div>
  {/if}
</div>

<style>
  .shpd-ds-theme-field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  /* Inline editor pod segmented controlem — omezená šířka, ať grid presetů
     nevytéká přes celou stránku nastavení. */
  .shpd-ds-theme-field__swatches {
    max-width: 20rem;
    padding: var(--shpd-space-md);
    background: var(--shpd-color-bg-secondary);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
  }
</style>
