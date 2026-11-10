<script lang="ts">
  interface Props {
    checked?: boolean;
    label?: string;
    disabled?: boolean;
    indeterminate?: boolean;
    onchange?: (e: Event) => void;
  }

  let {
    checked = $bindable(false),
    label,
    disabled = false,
    indeterminate = false,
    onchange,
  }: Props = $props();
</script>

<label class="shpd-checkbox" class:shpd-checkbox--disabled={disabled}>
  <input
    class="shpd-checkbox__native"
    type="checkbox"
    bind:checked
    {indeterminate}
    {disabled}
    {onchange}
  />
  <span class="shpd-checkbox__box" aria-hidden="true"></span>
  {#if label}
    <span class="shpd-checkbox__label">{label}</span>
  {/if}
</label>

<style>
  .shpd-checkbox {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    cursor: pointer;
    margin-bottom: var(--shpd-space-md);
    user-select: none;
  }

  .shpd-checkbox--disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-checkbox__native {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
  }

  .shpd-checkbox__box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.125rem;
    height: 1.125rem;
    flex-shrink: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  /* Checked state via sibling selector */
  .shpd-checkbox__native:checked + .shpd-checkbox__box {
    background-color: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
  }

  .shpd-checkbox__native:checked + .shpd-checkbox__box::after {
    content: '';
    display: block;
    width: 0.3125rem;
    height: 0.5625rem;
    border: 2px solid white;
    border-top: none;
    border-left: none;
    transform: rotate(45deg) translateY(-1px);
  }

  /* Indeterminate (tri-state) — vodorovná čárka, vizuál jako checked. */
  .shpd-checkbox__native:indeterminate + .shpd-checkbox__box {
    background-color: var(--shpd-color-primary);
    border-color: var(--shpd-color-primary);
  }

  .shpd-checkbox__native:indeterminate + .shpd-checkbox__box::after {
    content: '';
    display: block;
    width: 0.5625rem;
    height: 0;
    border: none;
    border-top: 2px solid white;
    transform: none;
  }

  .shpd-checkbox__native:focus-visible + .shpd-checkbox__box {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-checkbox__label {
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
  }
</style>
