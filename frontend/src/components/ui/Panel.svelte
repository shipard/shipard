<script>
  // Vložený panel (#149 D75): šedá plocha uvnitř obsahu — sekce formuláře,
  // nabídka položek, položka průvodce zdrojem dat, paleta vzhledu, souhrn
  // v dialogu, nápověda a „aktuální volba“ v rozhodovacích panelech importu.
  // Není to karta (D35): pozadí `bg-secondary`, slabý rámeček
  // `border-subtle`, zaoblení md, odsazení md, žádný stín ani vnější okraj
  // — mezery dává rodič. Panel v panelu se nepoužívá.
  //
  // `variant` `default` | `compact` (bez rámečku, odsazení sm, zaoblení sm
  // — drobná nápověda a „aktuální volba“ v popoveru). `tone` `neutral`
  // (výchozí) | `info` (`primary-soft` bez rámečku). `padding` `md`
  // (výchozí) | `form` (husté odsazení sekce formuláře
  // `--shpd-form-section-padding-y` × lg — jen `FormSection`). `as` volí
  // značku (`div` výchozí, `section`, `dl`, …). Zbylé atributy
  // (`data-testid`, `role`, `class` pro rozložení obsahu) jdou na kořen.
  //
  // Vnitřní rozložení dává obsah. Rodič kořen přes vlastní třídu nestyluje
  // vzhled (pozadí, rámeček, odsazení, zaoblení) — co panel potřebuje, je
  // prop; třída rodiče smí nést jen rozložení (mřížka, šířka, kontejner).
  // Pravidla: docs/design-system.md §8 Vložené panely.
  let {
    variant = 'default',
    tone = 'neutral',
    padding = 'md',
    as = 'div',
    class: extraClass = '',
    children,
    ...rest
  } = $props();

  let safeVariant = $derived(variant === 'compact' ? 'compact' : 'default');
  let safeTone = $derived(tone === 'info' ? 'info' : 'neutral');
  let safePadding = $derived(padding === 'form' ? 'form' : 'md');
</script>

<svelte:element
  this={as}
  class="shpd-panel shpd-panel--{safeVariant} shpd-panel--{safeTone} {extraClass}"
  class:shpd-panel--padding-form={safePadding === 'form'}
  {...rest}
>
  {@render children?.()}
</svelte:element>

<style>
  .shpd-panel {
    box-sizing: border-box;
    min-width: 0;
    margin: 0;
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border-subtle);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-panel--compact {
    padding: var(--shpd-space-sm);
    border: 0;
    border-radius: var(--shpd-radius-sm);
  }

  /* Tón info: tónovaná plocha bez rámečku. Jen tokeny — tmavý vzhled ji
     přebarví sám. */
  .shpd-panel--info {
    border: 0;
    background: var(--shpd-color-primary-soft);
  }

  /* Sekce formuláře: husté svislé odsazení, strany lg (FormSection). */
  .shpd-panel--padding-form {
    padding: var(--shpd-form-section-padding-y) var(--shpd-space-lg);
  }
</style>
