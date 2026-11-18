<script>
  // Sdílený kebab (#155 D114): tlačítko ⋮ a bublina s akcemi. Jediná
  // implementace v aplikaci — stavová lišta formuláře, patička náhledu
  // dokladu a horní lišta na telefonu; co do kebabu patří, rozhoduje
  // volající (docs/design-system.md → Akce na telefonu).
  //
  // Props:
  //   items      [{key, label, danger?, disabled?, testid?, run}] — bez
  //              položek se nevykreslí nic. Neaktivní položka zůstává
  //              vidět; skrytí je věc volajícího (položku nepředá).
  //   disabled   neaktivní celé tlačítko ⋮
  //   placement  'top' | 'bottom' — strana bubliny (výchozí 'top': kebab
  //              bývá v liště dole)
  //   variant    'control' = čtverec výšky ovládacího prvku s rámečkem
  //              (lišta, patička dialogu); 'plain' = bez rámečku, barvu
  //              dědí z lišty (horní lišta na barvě sidebaru)
  //   ariaLabel  popisek tlačítka (výchozí „Další akce“)
  //   testid     `data-testid` tlačítka ⋮; položky nesou `item.testid`
  import Icon from './Icon.svelte';
  import Popover from './Popover.svelte';
  import { iconMore } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let {
    items = [],
    disabled = false,
    placement = 'top',
    variant = 'control',
    ariaLabel = t('form.moreActions'),
    testid = undefined,
  } = $props();

  let open = $state(false);
  let anchor = $state(null);

  // Klik mimo řeší Popover a kotvu vynechává — druhé ťuknutí na ⋮ proto
  // zavírá tady.
  function toggle() { open = !open; }
  function close() { open = false; }

  // Nejdřív zavřít, pak spustit: akce bývá asynchronní nebo otevírá dialog
  // a na její dokončení se nečeká. Popover hlídá klik mimo ve fázi capture,
  // kdy je položka ještě v DOM, takže zavření před akcí klik nezahodí
  // (docs/frontend.md → Past: zavírání menu z handleru položky).
  function runItem(item) {
    close();
    item.run?.();
  }
</script>

{#if items.length > 0}
  <button
    type="button"
    class="shpd-kebab shpd-kebab--{variant}"
    bind:this={anchor}
    onclick={toggle}
    {disabled}
    aria-label={ariaLabel}
    aria-haspopup="menu"
    aria-expanded={open}
    data-testid={testid}
  >
    <Icon icon={iconMore} size="md" />
  </button>

  {#if open}
    <Popover open={true} {anchor} {placement} onClose={close}>
      <div class="shpd-kebab__menu" role="menu">
        {#each items as item (item.key)}
          <button
            type="button"
            role="menuitem"
            class="shpd-kebab__item"
            class:shpd-kebab__item--danger={item.danger}
            disabled={item.disabled}
            data-testid={item.testid}
            onclick={() => runItem(item)}
          >
            {item.label}
          </button>
        {/each}
      </div>
    </Popover>
  {/if}
{/if}

<style>
  /* Čtverec, který se v řadě nemačká ani neroztahuje. Selektor s prvkem
     kvůli specificitě: patička `Modal`u dává na telefonu svým dětem
     `flex: 1` pravidlem stejné váhy jako jedna třída, a pořadí CSS
     v bundlu nesmí rozhodovat. */
  button.shpd-kebab {
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: var(--shpd-control-height);
    height: var(--shpd-control-height);
    flex: none;
    padding: 0;
    color: var(--shpd-color-text);
    background: transparent;
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
    transition: background-color 0.15s;
  }

  button.shpd-kebab:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
  }

  button.shpd-kebab:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Bez rámečku, barva textu lišty. Rozměr a podklad při hoveru určuje
     lišta přes vlastnosti `--shpd-kebab-size` / `--shpd-kebab-hover-bg`. */
  button.shpd-kebab--plain {
    width: var(--shpd-kebab-size, var(--shpd-control-height));
    height: var(--shpd-kebab-size, var(--shpd-control-height));
    color: inherit;
    border-color: transparent;
    border-radius: var(--shpd-radius-sm);
  }

  button.shpd-kebab--plain:hover:not(:disabled) {
    background-color: var(--shpd-kebab-hover-bg, var(--shpd-color-bg-hover));
  }

  /* Položky v bublině (světlé pozadí Popoveru — i u kebabu v barevné
     liště). */
  .shpd-kebab__menu {
    display: flex;
    flex-direction: column;
    min-width: 11.25rem;
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-kebab__item {
    text-align: left;
    padding: 0.625rem 0.875rem;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }

  .shpd-kebab__item:hover:not(:disabled) {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-kebab__item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Destruktivní akce červeně — závažnost je vidět i bez velkého tlačítka. */
  .shpd-kebab__item--danger {
    color: var(--shpd-color-danger-text);
  }
</style>
