<script>
  // Segmentový přepínač (#149 D77): jedna volba z několika málo — vzhled,
  // rozvržení, jazyk, plátce DPH v průvodci, formát čísel reportu, způsob
  // nahrání pošty. Oddělená tlačítka s mezerou xs (zalamují se),
  // `radiogroup` / `radio`. Vybraná volba jako volitelný řádek (D76):
  // rámeček `primary` + pozadí `primary-soft`, tloušťka normal — barvou
  // výběru je v celé aplikaci `primary`, `accent` zůstává barvou značky.
  // Stav nese barva a `aria-checked`, fajfka u vybrané volby není.
  //
  // `options` `[{value, label, icon?, disabled?}]` — `value` libovolného
  // typu (i `null` / boolean), porovnává se `===`; `icon` jen tam, kde je
  // součástí popisku. `value` je bindable; `onchange(value)` po změně.
  // Řízené použití bez `bind:` — rodič po `onchange` hodnotu změní (jinak
  // přepínač drží klikem zvolenou). `label` = `aria-label` skupiny,
  // `disabled` zakáže celou skupinu. Zbylé atributy (`data-testid`) jdou na
  // kořen.
  //
  // Klávesnice (roving tabindex): Tab vstoupí na vybranou volbu, šipky
  // vlevo / vpravo (nahoru / dolů) přesouvají výběr přes zakázané volby
  // dokola, Home / End na první / poslední.
  // Pravidla: docs/design-system.md §8 Segmentový přepínač.
  import Icon from './Icon.svelte';

  let {
    options = [],
    value = $bindable(),
    label = undefined,
    disabled = false,
    size = 'md',
    onchange = undefined,
    class: extraClass = '',
    ...rest
  } = $props();

  let buttons = $state([]);
  // Jediná velikost; prop je připravený na další bez změny volajících.
  let safeSize = $derived(['md'].includes(size) ? size : 'md');

  let selectedIndex = $derived(options.findIndex((opt) => opt.value === value));
  // Bez vybrané volby dostane Tab první povolenou.
  let tabIndex = $derived(
    selectedIndex >= 0 && !options[selectedIndex].disabled
      ? selectedIndex
      : options.findIndex((opt) => !opt.disabled),
  );

  function select(index) {
    const opt = options[index];
    if (!opt || disabled || opt.disabled || opt.value === value) return;
    value = opt.value;
    onchange?.(opt.value);
  }

  function step(from, direction) {
    const count = options.length;
    for (let i = 1; i <= count; i++) {
      const index = (from + direction * i + count * i) % count;
      if (!options[index].disabled) return index;
    }
    return -1;
  }

  function handleKeydown(event, index) {
    if (disabled) return;
    let target = -1;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') target = step(index, 1);
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') target = step(index, -1);
    else if (event.key === 'Home') target = step(-1, 1);
    else if (event.key === 'End') target = step(options.length, -1);
    else return;
    event.preventDefault();
    if (target < 0 || target === index) return;
    buttons[target]?.focus();
    select(target);
  }
</script>

<div
  class="shpd-segmented shpd-segmented--{safeSize} {extraClass}"
  role="radiogroup"
  aria-label={label || undefined}
  aria-disabled={disabled || undefined}
  {...rest}
>
  {#each options as opt, index (index)}
    <button
      bind:this={buttons[index]}
      type="button"
      class="shpd-segmented__option"
      class:shpd-segmented__option--selected={index === selectedIndex}
      role="radio"
      aria-checked={index === selectedIndex}
      tabindex={index === tabIndex ? 0 : -1}
      disabled={disabled || opt.disabled}
      onclick={() => select(index)}
      onkeydown={(event) => handleKeydown(event, index)}
    >
      {#if opt.icon}
        <Icon icon={opt.icon} size="sm" />
      {/if}
      <span>{opt.label}</span>
    </button>
  {/each}
</div>

<style>
  .shpd-segmented {
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    min-width: 0;
  }

  .shpd-segmented__option {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font: inherit;
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-normal);
    cursor: pointer;
    transition: background-color 0.12s, border-color 0.12s;
  }

  .shpd-segmented--md .shpd-segmented__option {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
  }

  /* Pod kurzorem `bg-hover` (§9), ne `bg-secondary`. */
  .shpd-segmented__option:hover:not(:disabled) {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-segmented__option--selected,
  .shpd-segmented__option--selected:hover:not(:disabled) {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary-soft);
  }

  .shpd-segmented__option:focus-visible {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: 2px;
  }

  .shpd-segmented__option:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
</style>
