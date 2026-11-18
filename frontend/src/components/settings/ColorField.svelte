<script>
  // Pole barvy pro settings page (field typ `color`) — nativní výběr barvy
  // + zápis `#rrggbb` + návrat k výchozí. Hodnota je řetězec nebo null
  // (klíč se smaže, čtenář použije svou výchozí barvu); ukládá se tlačítkem
  // Uložit stránky a tvar hlídá server.
  import { t } from '../../i18n/index.js';
  import Input from '../ui/Input.svelte';
  import Button from '../ui/Button.svelte';

  let {
    id,
    value = null,
    // Barva, kterou čtenář použije bez nastavené hodnoty — ukáže ji výběr
    // barvy, když je pole prázdné (nativní input prázdnou hodnotu nezná).
    defaultColor = '#000000',
    error = null,
    disabled = false,
    onchange,
  } = $props();

  const HEX = /^#[0-9a-f]{6}$/i;

  const pickerValue = $derived(HEX.test(value ?? '') ? value.toLowerCase() : defaultColor);

  function setFromText(event) {
    const text = event.currentTarget.value.trim();
    onchange?.(text === '' ? null : text);
  }
</script>

<div class="shpd-color-field">
  <input
    class="shpd-color-field__picker"
    type="color"
    value={pickerValue}
    {disabled}
    aria-label={t('settingsPage.color.pick')}
    oninput={(event) => onchange?.(event.currentTarget.value)}
  />
  <div class="shpd-color-field__text">
    <Input
      {id}
      value={value ?? ''}
      placeholder="#rrggbb"
      maxlength={7}
      {error}
      {disabled}
      oninput={setFromText}
    />
  </div>
  <Button
    label={t('settingsPage.color.reset')}
    variant="ghost"
    disabled={disabled || value === null}
    onclick={() => onchange?.(null)}
  />
</div>

<style>
  .shpd-color-field {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
  }

  /* Výška ovládacího prvku jako textové pole vedle (#156 D106); rámeček
     ovládacího prvku (D109). */
  .shpd-color-field__picker {
    flex: none;
    width: 2.75rem;
    height: var(--shpd-control-height);
    padding: 0.125rem;
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    cursor: pointer;
  }

  .shpd-color-field__picker:disabled {
    cursor: default;
    opacity: 0.6;
    border-color: var(--shpd-color-border);
  }

  .shpd-color-field__text {
    width: 9rem;
  }
</style>
