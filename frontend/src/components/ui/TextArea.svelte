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
  /* Víceřádkové pole má výšku ovládacího prvku jako minimum (#156 D106):
     svislé odsazení doplňuje jeden řádek do této výšky, takže jednořádkové
     pole je stejně vysoké jako vstup vedle něj. Písmo a rámeček z tokenů
     (D108, D109). */
  .shpd-textarea__field {
    width: 100%;
    min-height: var(--shpd-control-height);
    padding: max(0rem, calc((var(--shpd-control-height) - 1lh) / 2 - 1px)) var(--shpd-space-sm); /* px-ok: rámeček pole */
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-field);
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
    border-color: var(--shpd-color-border);
  }

  .shpd-textarea__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger-text);
    margin-top: var(--shpd-space-xs);
  }
</style>
