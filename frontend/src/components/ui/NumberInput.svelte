<script lang="ts">
  interface Props {
    id?: string;
    value?: number | null;
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    min?: number;
    max?: number;
    step?: number;
    /** Nativní `change` — u číselného inputu až po potvrzení (blur, Enter), ne při psaní. */
    onchange?: () => void;
  }

  let {
    id,
    value = $bindable(null),
    required = false,
    disabled = false,
    error = null,
    min,
    max,
    step,
    onchange,
  }: Props = $props();
</script>

<input
  {id}
  class="shpd-input__field"
  class:shpd-input__field--error={!!error}
  type="number"
  bind:value
  {required}
  {disabled}
  {min}
  {max}
  {step}
  {onchange}
/>
{#if error}
  <span class="shpd-input__error">{error}</span>
{/if}

<style>
  .shpd-input__field {
    width: 100%;
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
