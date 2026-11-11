<script>
  // Hláška v obsahu (#137 D40): jedna sdílená komponenta pro výsledek akce
  // a stav formuláře, dialogu nebo panelu — kde všude stojí a kdy je to
  // místo ní chyba u prvku, říká docs/design-system.md §8 Hlášky a pruhy
  // (D60, D61). Tóny `info` / `success` / `warning` /
  // `danger` berou barvy jen z rodiny `--shpd-color-alert-*` (pozadí, text,
  // rámeček 1 px v barvě tónu; `success` jsou aliasy na zelené, `danger`
  // mapuje na `alert-error-*`; rodinu smí použít jen tahle komponenta
  // a `PageBanner`), zaoblení md, odsazení sm / md, písmo body.
  // Bez levého pruhu a bez vnějšího okraje — mezeru dává rodič (obal,
  // nebo mezera flex sloupce). Není to karta (§8 D35) ani pruh nad
  // obsahem (`PageBanner`, D41).
  //
  // `title` (strong, velikost body) a `icon` (vlevo, dekorace) jsou
  // nepovinné. `items` kreslí seznam — položka je text, nebo
  // `{label, text, meta}` (popisek strong, za ním „: “ a text: validace
  // formuláře, zámek dokladu; `meta` za textem v roli meta šedě — cesta
  // problému z vytěžení v review, #139 D53); ostatní obsah dává snippet
  // `children`.
  // `role`: `alert` u `danger` (čte se hned — proto jen chyba), jinak
  // `status`; volající ji smí přepsat. Zbylé atributy (`data-testid`,
  // `aria-*`) jdou na kořen; `class` je jen pro stavové třídy, nikdy pro
  // mezery. Pravidla: docs/design-system.md §8 Hlášky a pruhy.
  import Icon from './Icon.svelte';

  let {
    tone = 'info',
    title = '',
    icon = undefined,
    items = undefined,
    role = undefined,
    class: extraClass = '',
    children,
    ...rest
  } = $props();

  const TONES = ['info', 'success', 'warning', 'danger'];
  let safeTone = $derived(TONES.includes(tone) ? tone : 'info');
  let safeRole = $derived(role ?? (safeTone === 'danger' ? 'alert' : 'status'));
  let list = $derived(
    (Array.isArray(items) ? items : [])
      .map((item) => (item !== null && typeof item === 'object'
        ? { label: String(item.label ?? ''), text: String(item.text ?? ''), meta: String(item.meta ?? '') }
        : { label: '', text: String(item ?? ''), meta: '' }))
      .filter((item) => item.label !== '' || item.text !== ''),
  );
</script>

<div class="shpd-notice shpd-notice--{safeTone} {extraClass}" role={safeRole} {...rest}>
  {#if icon}
    <div class="shpd-notice__icon"><Icon {icon} size="sm" /></div>
  {/if}
  <div class="shpd-notice__body">
    {#if title}
      <div class="shpd-notice__title">{title}</div>
    {/if}
    {@render children?.()}
    {#if list.length > 0}
      <ul class="shpd-notice__list">
        {#each list as item}
          <li>
            {#if item.label}<strong>{item.label}</strong>{': '}{/if}{item.text}
            {#if item.meta}<span class="shpd-notice__meta">{item.meta}</span>{/if}
          </li>
        {/each}
      </ul>
    {/if}
  </div>
</div>

<style>
  .shpd-notice {
    display: flex;
    gap: var(--shpd-space-sm);
    box-sizing: border-box;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid transparent;
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-body);
    line-height: 1.5;
  }

  /* Tóny: jen rodina alert-* — tmavý vzhled je přebarví sám. */
  .shpd-notice--info {
    background: var(--shpd-color-alert-info-bg);
    color: var(--shpd-color-alert-info-text);
    border-color: var(--shpd-color-alert-info-bar);
  }

  .shpd-notice--success {
    background: var(--shpd-color-alert-success-bg);
    color: var(--shpd-color-alert-success-text);
    border-color: var(--shpd-color-alert-success-bar);
  }

  .shpd-notice--warning {
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-color: var(--shpd-color-alert-warning-bar);
  }

  .shpd-notice--danger {
    background: var(--shpd-color-alert-error-bg);
    color: var(--shpd-color-alert-error-text);
    border-color: var(--shpd-color-alert-error-bar);
  }

  .shpd-notice__icon {
    flex-shrink: 0;
    padding-top: 0.125rem; /* na první řádek textu */
  }

  .shpd-notice__body {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .shpd-notice__title {
    font-weight: var(--shpd-font-weight-strong);
  }

  .shpd-notice__title:not(:last-child) {
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-notice__list {
    margin: 0;
    padding-left: var(--shpd-space-md);
    list-style: disc;
  }

  .shpd-notice__list li + li {
    margin-top: var(--shpd-space-xs);
  }

  /* Doplněk položky (cesta problému) — meta šedě za textem. */
  .shpd-notice__meta {
    margin-left: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }
</style>
