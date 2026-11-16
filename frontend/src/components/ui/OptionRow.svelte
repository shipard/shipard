<script>
  // Volitelný řádek (#149 D76, D80): jedna volba ze seznamu — dialog Přidat
  // firmu z registru, převod bankovních účtů, kód DPH, předmět přenesení,
  // kandidáti partnera.
  //
  // `size` podle místa:
  //   `large`   volba s popisem, potvrzuje se tlačítkem dialogu — odsazení
  //             md, zaoblení md, ukazatel volby vlevo, název body strong,
  //             popis body barvou textu (D80 — nese běžné údaje: adresu,
  //             identifikátory, účet; šedou meta si skutečný doplněk dává
  //             sám), `aside` vpravo — jen když ho rodič předá, prázdný
  //             obal se nekreslí;
  //   `compact` jednořádková volba aplikovaná kliknutím (popover) —
  //             odsazení xs × sm, zaoblení sm, bez ukazatele.
  // Vybraný: rámeček `primary` + pozadí `primary-soft`; pod kurzorem
  // `bg-hover`; zakázaný průhlednost 0,6.
  //
  // `indicator` `radio` | `checkbox` | `none` (výchozí `radio` u `large`,
  // `none` u `compact`):
  //   s ukazatelem je řádek `<label>` s nativním `<input>` (klávesnice přes
  //   input; radia jedné skupiny sdílí `name` a rodič jim dá obal
  //   `role="radiogroup"`). Label je roztažený přes celý řádek (`::after`),
  //   takže klik kamkoli volí. `aside` leží mimo label — smí nést vlastní
  //   ovládací prvky (radio „výchozí“ u bankovních účtů); klik na jeho
  //   neinteraktivní obsah (štítky) propadne na řádek.
  //   bez ukazatele je řádek `<button>` s `role` podle rodiče: `radio`
  //   (výchozí, `aria-checked`) | `option` (`aria-selected`, rodič je
  //   `listbox`).
  //
  // `onselect(checked)` — radio a tlačítko volají s `true`, checkbox se
  // stavem po přepnutí. Řádek je řízený: `selected` drží rodič. Ukazatel
  // se kreslí tady stejnými tokeny jako `Checkbox` — ten je sám `<label>`
  // s vlastním okrajem a do řádku vložit nejde. Zbylé atributy
  // (`data-testid`, `aria-*`) jdou na kořen / na tlačítko.
  // Pravidla: docs/design-system.md §8 Volitelné řádky.
  let {
    size = 'large',
    selected = false,
    disabled = false,
    indicator = undefined,
    name = undefined,
    value = undefined,
    role = 'radio',
    onselect = undefined,
    children,
    description = undefined,
    aside = undefined,
    class: extraClass = '',
    ...rest
  } = $props();

  let safeSize = $derived(size === 'compact' ? 'compact' : 'large');
  let safeIndicator = $derived(
    ['radio', 'checkbox', 'none'].includes(indicator)
      ? indicator
      : (safeSize === 'large' ? 'radio' : 'none'),
  );
  let rootClass = $derived(`shpd-option-row shpd-option-row--${safeSize} ${extraClass}`);

  function handleChange(event) {
    onselect?.(safeIndicator === 'checkbox' ? event.currentTarget.checked : true);
  }
</script>

{#if safeIndicator === 'none'}
  <button
    type="button"
    class="{rootClass} shpd-option-row--button"
    class:shpd-option-row--selected={selected}
    class:shpd-option-row--disabled={disabled}
    {role}
    aria-checked={role === 'radio' ? selected : undefined}
    aria-selected={role === 'option' ? selected : undefined}
    {disabled}
    onclick={() => onselect?.(true)}
    {...rest}
  >
    {#if safeSize === 'large'}
      <span class="shpd-option-row__body">
        <span class="shpd-option-row__title">{@render children?.()}</span>
        {#if description}
          <span class="shpd-option-row__description">{@render description()}</span>
        {/if}
      </span>
    {:else}
      {@render children?.()}
      {#if description}
        <span class="shpd-option-row__description">{@render description()}</span>
      {/if}
    {/if}
    {#if aside}
      <span class="shpd-option-row__aside">{@render aside()}</span>
    {/if}
  </button>
{:else}
  <div
    class={rootClass}
    class:shpd-option-row--selected={selected}
    class:shpd-option-row--disabled={disabled}
    {...rest}
  >
    <label class="shpd-option-row__main">
      <input
        class="shpd-option-row__native"
        type={safeIndicator}
        {name}
        {value}
        checked={selected}
        {disabled}
        onchange={handleChange}
      />
      <span
        class="shpd-option-row__indicator shpd-option-row__indicator--{safeIndicator}"
        aria-hidden="true"
      ></span>
      <span class="shpd-option-row__body">
        <span class="shpd-option-row__title">{@render children?.()}</span>
        {#if description}
          <span class="shpd-option-row__description">{@render description()}</span>
        {/if}
      </span>
    </label>
    {#if aside}
      <div class="shpd-option-row__aside shpd-option-row__aside--over">{@render aside()}</div>
    {/if}
  </div>
{/if}

<style>
  .shpd-option-row {
    position: relative;
    box-sizing: border-box;
    display: flex;
    width: 100%;
    min-width: 0;
    margin: 0;
    border: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    font: inherit;
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-normal);
    text-align: left;
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .shpd-option-row--large {
    align-items: flex-start;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
    border-radius: var(--shpd-radius-md);
  }

  .shpd-option-row--compact {
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
  }

  /* Pod kurzorem `bg-hover` (v tmavém vzhledu světlejší než plocha, §9),
     ne `bg-secondary`. */
  .shpd-option-row:hover {
    background: var(--shpd-color-bg-hover);
  }

  .shpd-option-row--selected,
  .shpd-option-row--selected:hover {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary-soft);
  }

  .shpd-option-row--disabled,
  .shpd-option-row--disabled:hover {
    background: var(--shpd-color-bg);
    opacity: 0.6;
    cursor: not-allowed;
  }

  .shpd-option-row--button:focus-visible,
  .shpd-option-row:has(.shpd-option-row__native:focus-visible) {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: 2px;
  }

  /* Kompaktní řádek bývá v rolovacím seznamu popoveru — obrys dovnitř, ať
     ho okraj seznamu neořízne. */
  .shpd-option-row--compact.shpd-option-row--button:focus-visible {
    outline-offset: -2px;
  }

  /* Label s ukazatelem: roztažený přes celý řádek, klik kamkoli volí. */
  .shpd-option-row__main {
    display: flex;
    flex: 1;
    align-items: flex-start;
    gap: var(--shpd-space-md);
    min-width: 0;
    cursor: inherit;
  }

  .shpd-option-row__main::after {
    content: '';
    position: absolute;
    inset: 0;
  }

  .shpd-option-row__native {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
  }

  /* Ukazatel volby — rozměr a barvy jako ui/Checkbox. */
  .shpd-option-row__indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1.125rem;
    height: 1.125rem;
    margin-top: 0.0625rem;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .shpd-option-row__indicator--radio {
    border-radius: 50%;
  }

  .shpd-option-row__native:checked + .shpd-option-row__indicator {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary);
  }

  .shpd-option-row__native:checked + .shpd-option-row__indicator--checkbox::after {
    content: '';
    display: block;
    width: 0.3125rem;
    height: 0.5625rem;
    border: 2px solid var(--shpd-color-text-on-fill);
    border-top: none;
    border-left: none;
    transform: rotate(45deg) translateY(-1px);
  }

  .shpd-option-row__native:checked + .shpd-option-row__indicator--radio::after {
    content: '';
    display: block;
    width: 0.375rem;
    height: 0.375rem;
    border-radius: 50%;
    background: var(--shpd-color-text-on-fill);
  }

  .shpd-option-row__body {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.125rem;
    min-width: 0;
  }

  .shpd-option-row__title {
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
  }

  /* Popis nese běžné údaje (adresa, identifikátory, účet) — body barvou
     textu, od názvu se liší jen tloušťkou (D80, §11). */
  .shpd-option-row__description {
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-normal);
    color: var(--shpd-color-text);
  }

  .shpd-option-row__aside {
    flex-shrink: 0;
  }

  /* `aside` vedle labelu leží nad jeho roztaženou plochou: neinteraktivní
     obsah klik propustí na řádek, ovládací prvky uvnitř fungují samy. */
  .shpd-option-row__aside--over {
    position: relative;
    z-index: 1;
    pointer-events: none;
  }

  .shpd-option-row__aside--over :global(:is(a, button, input, select, textarea, label)) {
    pointer-events: auto;
  }
</style>
