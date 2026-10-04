<script lang="ts">
  interface Props {
    id?: string;
    value?: string;
    type?: string;
    placeholder?: string;
    required?: boolean;
    maxlength?: number;
    disabled?: boolean;
    error?: string | null;
    oninput?: (event: Event) => void;
    /** Klávesy v poli — např. Enter potvrdí hodnotu bez tlačítka. */
    onkeydown?: (event: KeyboardEvent) => void;
    /** Volitelný `data-testid` na inputu (video-runner, smoke E2E). */
    testid?: string;
  }

  let {
    id,
    value = $bindable(''),
    type = 'text',
    placeholder,
    required = false,
    maxlength,
    disabled = false,
    error = null,
    oninput,
    onkeydown,
    testid,
  }: Props = $props();
</script>

<input
  {id}
  class="shpd-input__field"
  class:shpd-input__field--error={!!error}
  {type}
  bind:value
  {placeholder}
  {required}
  {maxlength}
  {disabled}
  {oninput}
  {onkeydown}
  data-testid={testid}
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
    border-color: var(--shpd-color-danger);
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
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
    grid-column: 2;
  }
</style>
