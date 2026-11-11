<script lang="ts">
  interface Props {
    id?: string;
    value?: string;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    rows?: number;
  }

  let {
    id,
    value = $bindable(''),
    placeholder,
    required = false,
    disabled = false,
    error = null,
    rows = 4,
  }: Props = $props();
</script>

<textarea
  {id}
  class="shpd-textarea__field"
  class:shpd-textarea__field--error={!!error}
  bind:value
  {placeholder}
  {required}
  {disabled}
  {rows}
></textarea>
{#if error}
  <span class="shpd-textarea__error">{error}</span>
{/if}

<style>
  .shpd-textarea__field {
    width: 100%;
    padding: var(--shpd-input-padding-y) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-base);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    resize: vertical;
    transition: border-color 0.15s ease;
  }

  .shpd-textarea__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-textarea__field--error {
    border-color: var(--shpd-color-danger-text);
  }

  .shpd-textarea__field--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-textarea__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-textarea__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
    margin-top: var(--shpd-space-xs);
  }
</style>
