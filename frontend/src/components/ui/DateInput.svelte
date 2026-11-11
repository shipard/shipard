<script lang="ts">
  interface Props {
    id?: string;
    value?: string;
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    /** Zavolá se po opuštění pole, když se hodnota liší od té, s jakou uživatel do pole vstoupil. */
    onchange?: () => void;
  }

  let {
    id,
    value = $bindable(''),
    required = false,
    disabled = false,
    error = null,
    onchange,
  }: Props = $props();

  // Chrome střílí nativní `change` po každém úhozu, který dá validní datum:
  // při psaní roku „2026“ je po první číslici hodnota 0002-MM-DD. Trigger
  // (recalculate, #24 B) proto běží až na blur a jen při skutečné změně —
  // jinak by server doplnil Účetní datum z rozepsaného roku a další úhozy
  // by ho už nepřepsaly (recalculate doplňuje jen prázdná pole). Výchozí
  // hodnota se bere při focusu, aby změna zvenku (recalculate, reload)
  // neproběhla jako uživatelova.
  let valueOnFocus = '';

  function handleFocus() {
    valueOnFocus = value;
  }

  function handleBlur() {
    if (value === valueOnFocus) return;
    onchange?.();
  }
</script>

<input
  {id}
  class="shpd-input__field"
  class:shpd-input__field--error={!!error}
  type="date"
  bind:value
  {required}
  {disabled}
  onfocus={handleFocus}
  onblur={handleBlur}
/>
{#if error}
  <span class="shpd-input__error">{error}</span>
{/if}

<style>
  .shpd-input__field {
    width: 100%;
    /* Umožní zmenšení pod intrinsic šířku nativního date inputu (dd.mm.rrrr
       + ikona kalendáře) v grid/flex kontejneru — bez toho pole přetéká
       doprava na úzkých obrazovkách (mobil, inline rozpad). */
    min-width: 0;
    padding: var(--shpd-input-padding-y) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    transition: border-color 0.15s ease;
  }

  .shpd-input__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-input__field--error {
    border-color: var(--shpd-color-danger-text);
  }

  .shpd-input__field--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-input__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-input__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
    margin-top: var(--shpd-space-xs);
  }
</style>
