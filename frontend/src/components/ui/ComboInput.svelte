<script lang="ts">
  // Select s volným textem (form element `select` + `allow_custom`,
  // tasks/ai-functions.md D44): nativní <input list> s <datalist> — nabídka
  // z options, ale napsat jde i hodnota mimo ni (vlastní ID modelu).
  // Prázdný text = null, stejně jako prázdná možnost selectu.
  interface Option {
    value: string | number;
    label: string;
  }

  interface Props {
    id?: string;
    value?: string | number | null;
    options?: Option[];
    required?: boolean;
    disabled?: boolean;
    error?: string | null;
    placeholder?: string;
    onchange?: () => void;
  }

  let {
    id,
    value = $bindable(null),
    options = [],
    required = false,
    disabled = false,
    error = null,
    placeholder,
    onchange,
  }: Props = $props();

  const listId = $derived(`${id ?? 'shpd-combo'}-list`);
</script>

<input
  {id}
  class="shpd-combo__field"
  class:shpd-combo__field--error={!!error}
  type="text"
  list={listId}
  autocomplete="off"
  bind:value={() => (value == null ? '' : String(value)), (v) => (value = v === '' ? null : v)}
  {placeholder}
  {required}
  {disabled}
  {onchange}
/>
<datalist id={listId}>
  {#each options as option}
    <option value={option.value}>{option.label}</option>
  {/each}
</datalist>
{#if error}
  <span class="shpd-combo__error">{error}</span>
{/if}

<style>
  .shpd-combo__field {
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

  .shpd-combo__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-combo__field--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-combo__field--error:focus {
    box-shadow: 0 0 0 2px var(--shpd-color-error-ring);
  }

  .shpd-combo__field:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-combo__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
    margin-top: var(--shpd-space-xs);
  }
</style>
