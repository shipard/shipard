<script>
  // Řízený widget vzhledu pro user-scope settings page (account.theme).
  // Vázaný na themeStore.
  //
  // Přepínač „Vlastní vzhled" (follow) nahoře:
  //   vypnuto (follow) → skryje výběr, jen poznámka „Řídí se nastavením
  //                      aplikace" + mini náhled DS defaultu
  //   zapnuto (override) → odemkne segmented control Shipard/Tmavý/Vlastní;
  //                        u „Vlastní" tlačítko otevře ThemePanel
  //                        (přes onOpenThemePanel callback).
  //
  // `showFollow` (default true) zapíná přepínač. DsThemeField (DS default)
  // tuto komponentu nepoužívá — má vlastní controlled flow.
  //
  // Čte store reaktivně, takže zůstává v synchronu s panelem — jedna pravda
  // ve storu.
  import { themeStore } from '../../stores/theme.svelte.js';
  import { appInfoStore } from '../../stores/appInfo.svelte.js';
  import { t } from '../../i18n/index.js';
  import Icon from '../ui/Icon.svelte';
  import { iconPalette } from '../../icons.js';
  import ThemeModeSegments from './ThemeModeSegments.svelte';

  let { onOpenThemePanel, showFollow = true } = $props();

  // Otevření panelu deferujeme za aktuální klik (setTimeout 0). ThemePanel
  // si na desktopu po otevření přidá document click listener; bez deferu by
  // tentýž klik probublal na document a panel hned zavřel. Mobil (Modal)
  // listener nemá, ale defer nevadí. Stejný vzor jako Sidebar dropdown.
  function openPanel() {
    setTimeout(() => { onOpenThemePanel?.(); }, 0);
  }

  function select(value) {
    themeStore.setMode(value);
    if (value === 'custom') {
      openPanel();
    }
  }

  // Mini náhled DS defaultu při follow — solid barva, gradient nebo built-in.
  const dsSwatchStyle = $derived.by(() => {
    const ds = appInfoStore.theme;
    if (!ds || ds.mode === 'light') return 'background:#00345C';
    if (ds.mode === 'dark') return 'background:#232730';
    const sb = ds.custom?.sidebar;
    if (sb?.type === 'gradient' && sb.stops?.length === 2) {
      return `background:linear-gradient(180deg, ${sb.stops[0]}, ${sb.stops[1]})`;
    }
    return `background:${sb?.color ?? '#00345C'}`;
  });

  // „Vlastní vzhled" zaškrtnuté = override (!follow). Pojmenováno tak, aby
  // bylo jasné, že zaškrtnuto znamená „mám vlastní volbu".
  const customChecked = $derived(showFollow ? !themeStore.follow : true);
</script>

<div class="shpd-theme-field">
  {#if showFollow}
    <label class="shpd-theme-field__follow">
      <input
        type="checkbox"
        checked={customChecked}
        onchange={(e) => themeStore.setFollow(!e.currentTarget.checked)}
      />
      <span>{t('theme.customAppearance')}</span>
    </label>
  {/if}

  {#if !showFollow || !themeStore.follow}
    <ThemeModeSegments mode={themeStore.mode} onSelect={select} />

    {#if themeStore.mode === 'custom'}
      <button type="button" class="shpd-theme-field__edit" onclick={openPanel}>
        <Icon icon={iconPalette} size="sm" />
        <span>{t('account.theme.editColor')}</span>
      </button>
    {/if}
  {:else}
    <div class="shpd-theme-field__follow-note">
      <span class="shpd-theme-field__ds-swatch" style={dsSwatchStyle}></span>
      <span>{t('theme.followsApp')}</span>
    </div>
  {/if}
</div>

<style>
  .shpd-theme-field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-theme-field__follow {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
    align-self: flex-start;
  }

  .shpd-theme-field__follow input {
    cursor: pointer;
  }

  .shpd-theme-field__follow-note {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-theme-field__ds-swatch {
    display: inline-block;
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 50%;
    border: 1px solid color-mix(in srgb, var(--shpd-color-text) 25%, transparent);
    flex-shrink: 0;
  }

  .shpd-theme-field__edit {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    align-self: flex-start;
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background: transparent;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    transition: background-color 0.12s;
  }

  .shpd-theme-field__edit:hover {
    background-color: var(--shpd-color-bg-secondary);
  }
</style>
