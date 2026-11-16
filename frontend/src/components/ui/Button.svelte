<script lang="ts">
  import Icon from './Icon.svelte';

  interface Props {
    label?: string;
    icon?: object;
    iconOnly?: boolean;
    variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'ghost';
    size?: 'sm' | 'md';
    disabled?: boolean;
    loading?: boolean;
    type?: 'button' | 'submit' | 'reset';
    onclick?: () => void;
    /** Volitelný `data-testid` pro automatizaci (video-runner, smoke E2E). */
    testid?: string;
    /**
     * Dlouhý popisek se smí zalomit (výchozí `false` = jeden řádek). Jen pro
     * tlačítko s proměnným textem v úzkém místě (rychlé založení z registru
     * s názvem firmy): text vlevo, výška podle obsahu, ikona u prvního
     * řádku, nejvýš šířka rodiče. Jednořádkové je stejně vysoké jako bez
     * `wrap`.
     */
    wrap?: boolean;
  }

  let {
    label,
    icon = undefined,
    iconOnly = false,
    variant = 'primary',
    size = 'md',
    disabled = false,
    loading = false,
    type = 'button',
    onclick,
    testid,
    wrap = false,
  }: Props = $props();

  const isDisabled = $derived(disabled || loading);
</script>

<button
  class="shpd-btn shpd-btn--{variant} shpd-btn--{size}"
  class:shpd-btn--loading={loading}
  class:shpd-btn--icon-only={iconOnly}
  class:shpd-btn--wrap={wrap}
  {type}
  data-testid={testid}
  disabled={isDisabled}
  title={iconOnly ? label : undefined}
  {onclick}
>
  {#if loading}
    <span class="shpd-btn__spinner" aria-hidden="true"></span>
  {:else if icon}
    <Icon {icon} size={size === 'sm' ? 'sm' : 'md'} />
  {/if}
  {#if label && !iconOnly}
    <span class="shpd-btn__label">{label}</span>
  {/if}
</button>

<style>
  .shpd-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
    white-space: nowrap;
    line-height: 1;
  }

  .shpd-btn--sm {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-btn--icon-only {
    padding: var(--shpd-space-sm);
    aspect-ratio: 1;
  }

  .shpd-btn--icon-only.shpd-btn--sm {
    padding: var(--shpd-space-xs);
  }

  .shpd-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  /* Primary */
  .shpd-btn--primary {
    background-color: var(--shpd-color-primary);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-primary);
  }

  .shpd-btn--primary:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-hover);
    border-color: var(--shpd-color-primary-hover);
  }

  /* Secondary. Text a rámeček mají volitelné tokeny s fallbackem na dnešní
   * hodnoty: aplikace je nedefinuje (vzhled beze změny), vývojářský dashboard
   * je nastavuje v src/dev/theme.css — bílé tlačítko s modrým textem (#129 D1, D4). */
  .shpd-btn--secondary {
    background-color: var(--shpd-color-bg);
    color: var(--shpd-btn-secondary-text, var(--shpd-color-text));
    border-color: var(--shpd-btn-secondary-border, var(--shpd-color-border));
  }

  .shpd-btn--secondary:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
  }

  /* Danger */
  .shpd-btn--danger {
    background-color: var(--shpd-color-danger);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-danger);
  }

  .shpd-btn--danger:hover:not(:disabled) {
    background-color: var(--shpd-color-danger-hover);
    border-color: var(--shpd-color-danger-hover);
  }

  /* Success — použito pro "Přidat / Použít / Potvrdit" akce, kde se chce
   * od uživatele odlišit pozitivní očekávaný výsledek od neutrálního.
   * Záměrně málo častý — většina akcí jsou primary nebo secondary. */
  .shpd-btn--success {
    background-color: var(--shpd-color-success);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-success);
  }

  .shpd-btn--success:hover:not(:disabled) {
    background-color: var(--shpd-color-success-hover);
    border-color: var(--shpd-color-success-hover);
  }

  /* Ghost — bez pozadí, jen ikona/text */
  .shpd-btn--ghost {
    background-color: transparent;
    color: var(--shpd-color-text-secondary);
    border-color: transparent;
    padding: var(--shpd-space-xs);
  }

  .shpd-btn--ghost:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }

  /* Zalamovaný popisek (prop `wrap`, opt-in — výchozí tlačítko zůstává na
   * jednom řádku). Řádkování jako běžný text, text vlevo, nejvýš šířka
   * rodiče. Svislé odsazení je menší o půl prokladu řádku, takže
   * jednořádkové tlačítko je stejně vysoké jako bez `wrap` a mezera mezi
   * textem a rámečkem se nemění. Ikona a spinner sedí na účaří prvního
   * řádku a jsou posunuté jako ikona v textu. */
  .shpd-btn--wrap {
    align-items: baseline;
    justify-content: flex-start;
    min-width: 0;
    max-width: 100%;
    padding-block: max(0rem, calc(var(--shpd-space-sm) - (1lh - 1em) / 2));
    white-space: normal;
    line-height: normal;
    text-align: left;
  }

  .shpd-btn--wrap.shpd-btn--sm {
    padding-block: max(0rem, calc(var(--shpd-space-xs) - (1lh - 1em) / 2));
  }

  .shpd-btn--wrap .shpd-btn__label {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .shpd-btn--wrap :global(.shpd-icon),
  .shpd-btn--wrap .shpd-btn__spinner {
    position: relative;
    top: 0.125em;
  }

  /* Spinner */
  .shpd-btn__spinner {
    display: inline-block;
    width: 0.875rem;
    height: 0.875rem;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: shpd-spin 0.6s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-spin {
    to { transform: rotate(360deg); }
  }
</style>
