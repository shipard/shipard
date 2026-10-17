<script>
  // Kebab menu (⋮) řádku v dev dashboardu (tasks/ds-delete.md D71): tlačítko
  // + sdílený Popover (netahá t() ani api/client.js, docs/frontend.md →
  // Vývojářský dashboard). Položky jsou odkazy routeru (`href`) nebo akce
  // (`onSelect`); neaktivní položky volající vůbec nepředá — menu nic nešedí.
  // Klávesnice: klik / Enter / mezerník otevře a dá fokus na první položku
  // (↓ na zavřeném tlačítku totéž), ↑/↓ cyklicky, Home/End, Tab menu opustí,
  // Esc nebo klik mimo zavře (Popover → onClose) a fokus se vrátí na
  // tlačítko. Styl podle kebabu formuláře (`.shpd-form-state-bar__kebab-*`).
  import { tick } from 'svelte';
  import Popover from '../../components/ui/Popover.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconMore } from '../../icons.js';
  import { onLinkClick } from '../router.svelte.js';

  /**
   * items: {key, label, href?, onSelect?, danger?, icon?, separator?}[] —
   * `href` = odkaz routeru (naviguje `onLinkClick`), jinak tlačítko
   * s `onSelect`; `icon` z icons.js před textem (#129 D5), `separator`
   * vykreslí oddělovač nad položkou (Smazat…).
   */
  let { items = [], label = 'Další akce' } = $props();

  let open = $state(false);
  let buttonEl = $state(null);
  let menuEl = $state(null);
  const menuId = `dev-kebab-${Math.random().toString(36).slice(2, 8)}`;

  function itemElements() {
    return menuEl ? Array.from(menuEl.querySelectorAll('[role="menuitem"]')) : [];
  }

  async function openMenu() {
    if (open || items.length === 0) return;
    open = true;
    await tick();
    itemElements()[0]?.focus();
  }

  function closeMenu(restoreFocus = true) {
    if (!open) return;
    open = false;
    if (restoreFocus) buttonEl?.focus();
  }

  function toggle() {
    if (open) closeMenu();
    else openMenu();
  }

  function onButtonKeydown(e) {
    if (e.key === 'ArrowDown' && !open) {
      e.preventDefault();
      openMenu();
    }
  }

  function onMenuKeydown(e) {
    const els = itemElements();
    if (els.length === 0) return;
    const idx = els.indexOf(document.activeElement);
    let next = null;
    switch (e.key) {
      case 'ArrowDown':
        next = els[(idx + 1) % els.length];
        break;
      case 'ArrowUp':
        next = els[(idx - 1 + els.length) % els.length];
        break;
      case 'Home':
        next = els[0];
        break;
      case 'End':
        next = els[els.length - 1];
        break;
      case 'Tab':
        // Tab jde dál v dokumentu — menu jen zavřít, fokus nevracet.
        closeMenu(false);
        return;
      default:
        return;
    }
    e.preventDefault();
    next?.focus();
  }

  function select(item, event) {
    if (item.href) {
      onLinkClick(event);
      // Po navigaci může řádek i tlačítko zmizet — fokus nevracet.
      closeMenu(false);
      return;
    }
    closeMenu();
    item.onSelect?.();
  }
</script>

<button
  type="button"
  class="dev-kebab__btn"
  bind:this={buttonEl}
  aria-label={label}
  aria-haspopup="menu"
  aria-expanded={open}
  aria-controls={open ? menuId : undefined}
  disabled={items.length === 0}
  onclick={toggle}
  onkeydown={onButtonKeydown}
>
  <Icon icon={iconMore} size="sm" />
</button>

{#if open}
  <Popover open={true} anchor={buttonEl} placement="bottom" onClose={() => closeMenu()}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <div class="dev-kebab__menu" role="menu" id={menuId} tabindex="-1" bind:this={menuEl} onkeydown={onMenuKeydown}>
      {#each items as item (item.key)}
        {#if item.separator}
          <div class="dev-kebab__sep" role="separator"></div>
        {/if}
        {#if item.href}
          <a
            class="dev-kebab__item"
            class:dev-kebab__item--danger={item.danger}
            role="menuitem"
            href={item.href}
            onclick={(e) => select(item, e)}
          >{#if item.icon}<Icon icon={item.icon} size="sm" />{/if}<span>{item.label}</span></a>
        {:else}
          <button
            type="button"
            class="dev-kebab__item"
            class:dev-kebab__item--danger={item.danger}
            role="menuitem"
            onclick={(e) => select(item, e)}
          >{#if item.icon}<Icon icon={item.icon} size="sm" />{/if}<span>{item.label}</span></button>
        {/if}
      {/each}
    </div>
  </Popover>
{/if}

<style>
  .dev-kebab__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    vertical-align: middle;
    color: var(--shpd-color-text);
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s;
  }
  .dev-kebab__btn:hover,
  .dev-kebab__btn[aria-expanded='true'] {
    border-color: var(--shpd-color-primary);
    color: var(--shpd-color-primary);
  }
  .dev-kebab__btn[aria-expanded='true'] {
    background: var(--shpd-color-primary-soft);
  }
  .dev-kebab__btn:disabled {
    opacity: 0.5;
    cursor: default;
  }

  /* Položky v Popoveru — stejný vzor jako kebab formuláře a vieweru. */
  .dev-kebab__menu {
    display: flex;
    flex-direction: column;
    min-width: 180px;
    padding: 4px 0;
    outline: none;
  }
  .dev-kebab__item {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    text-align: left;
    padding: 8px 14px;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    text-decoration: none;
    cursor: pointer;
  }
  .dev-kebab__item:hover,
  .dev-kebab__item:focus-visible {
    background-color: var(--shpd-color-bg-hover);
    outline: none;
  }
  .dev-kebab__sep {
    height: 1px;
    margin: 4px 0;
    background: var(--shpd-color-border);
  }
  /* Destruktivní akce (Smazat…) červeně včetně ikony, jako ve formuláři. */
  .dev-kebab__item--danger {
    color: var(--shpd-color-danger);
  }
</style>
