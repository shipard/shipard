<script>
  import Icon from '../ui/Icon.svelte';
  import SpanBadge from './SpanBadge.svelte';
  import { resolveIcon } from '../../icons.js';
  import { normalizeSpans } from './viewerSpans.js';

  let { row, index, selected = false, onclick } = $props();

  // Compute row CSS class including optional docState style
  let rowClass = $derived(
    'shpd-viewer-row' +
    (selected ? ' shpd-viewer-row--selected' : '') +
    (row.stateStyle ? ` docState_${row.stateStyle}` : '')
  );
</script>

<!-- Render spanů jednoho pole (t1/i1/t2/i2/t3). Span s `badge` se kreslí
     jako pilulka (SpanBadge, má přednost před `class`); sep=true vkládá
     mezi spany oddělovač ` · ` (jen t2). -->
{#snippet spanList(value, sep = false)}
  {#each normalizeSpans(value) ?? [] as span, i}
    {#if sep && i > 0}<span class="shpd-viewer-row__sep"> · </span>{/if}
    {#if span.badge}
      <SpanBadge style={span.badge} text={span.text} />
    {:else}
      <span class={span.class ? `shpd-viewer-row__span--${span.class}` : ''}>{span.text}</span>
    {/if}
  {/each}
{/snippet}

<button
  class={rowClass}
  data-testid="viewer-row"
  {onclick}
  type="button"
>
  {#if row.avatar}
    <span class="shpd-viewer-row__avatar">{row.avatar}</span>
  {:else}
    <span class="shpd-viewer-row__lead">
      <span class="shpd-viewer-row__icon">
        <Icon icon={resolveIcon(row.icon)} size="xl" />
      </span>
    </span>
  {/if}

  <div class="shpd-viewer-row__body">
    <!-- Line 1 -->
    <div class="shpd-viewer-row__line">
      {#if index != null}
        <span class="shpd-viewer-row__index">{index}</span>
      {/if}
      <span class="shpd-viewer-row__t1">
        {@render spanList(row.t1)}
      </span>
      {#if row.i1 != null}
        <span class="shpd-viewer-row__i1">
          {@render spanList(row.i1)}
        </span>
      {/if}
    </div>

    <!-- Line 2 -->
    {#if row.t2 != null || row.i2 != null}
      <div class="shpd-viewer-row__line shpd-viewer-row__line--secondary">
        <span class="shpd-viewer-row__t2">
          {@render spanList(row.t2, true)}
        </span>
        {#if row.i2 != null}
          <span class="shpd-viewer-row__i2">
            {@render spanList(row.i2)}
          </span>
        {/if}
      </div>
    {/if}

    <!-- Line 3 -->
    {#if row.t3 != null}
      <div class="shpd-viewer-row__line shpd-viewer-row__line--tertiary">
        <span class="shpd-viewer-row__t3">
          {@render spanList(row.t3)}
        </span>
      </div>
    {/if}
  </div>
</button>

<style>
  .shpd-viewer-row {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    transition: background-color 0.1s;
  }

  /* Levý stavový / accent proužek (4px, plný v celé výšce řádku).
   *
   * Barva se řídí CSS proměnnou --shpd-row-bar, kterou nastavují
   * docState_* třídy níže. Pokud proměnná není nastavená
   * (= confirmed nebo neznámý stav), proužek je průhledný — nic
   * se nezobrazí. Tím dostáváme „confirmed = klid“ chování.
   *
   * Výběr řádku proměnnou záměrně NEpřepisuje:
   * stavový proužek zůstává i u vybraného záznamu, výběr nese jen
   * pozadí --shpd-color-bg-selected. Oranžový accent „kde jsem"
   * patří výhradně navigaci (sidebar, shelly). */
  .shpd-viewer-row::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    background-color: var(--shpd-row-bar, transparent);
  }

  .shpd-viewer-row:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-viewer-row--selected {
    background-color: var(--shpd-color-bg-selected);
  }

  .shpd-viewer-row--selected:hover {
    background-color: var(--shpd-color-bg-selected-hover);
  }

  /* Doc-state proužky řeší globální .docState_* třídy v styles/base.css
   */

  /* Levý sloupec — drží jen ikonu typu záznamu, vertikálně
   * vystředěnou v celé výšce řádku.
   * Tlumená barva, aby ikona nekonkurovala stavovému proužku
   * ani hlavnímu textu v t1.
   *
   * align-self: stretch natáhne sloupec na výšku řádku;
   * align-items + justify-content: center vystředí ikonu
   * (jediný child) v obou osách. */
  .shpd-viewer-row__lead {
    display: flex;
    align-items: center;
    justify-content: center;
    align-self: stretch;
    width: 32px;
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-viewer-row__icon {
    /* Velikost SVG přes `size="xl"` v <Icon>; výška 1.5em. */
    display: inline-flex;
  }

  /* Pořadové číslo — sedí v prvním řádku těla před názvem (t1).
   * Tabular-nums = monospace číslice, aby šířka Č=99 a Č=10 byla stejná. */
  .shpd-viewer-row__index {
    flex-shrink: 0;
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
    font-variant-numeric: tabular-nums;
  }

  /* Avatar (kruhový, s iniciálami).
   * Stejná sloupcová šířka jako __icon, aby řádky bez avataru
   * a s avatarem byly horizontálně zarovnané. Neutrální barva
   * — nemá v seznamu konkurovat stavovému pruhu ani výběru. */
  .shpd-viewer-row__avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    border-radius: 50%;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.02em;
    color: var(--shpd-color-primary);
    background-color: var(--shpd-color-primary-soft);
    text-transform: uppercase;
  }

  .shpd-viewer-row__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-viewer-row__line {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--shpd-space-sm);
  }

  .shpd-viewer-row__t1 {
    flex: 1;
    min-width: 0;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-viewer-row__i1 {
    flex-shrink: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-viewer-row__line--secondary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-viewer-row__line--tertiary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    opacity: 0.8;
  }

  .shpd-viewer-row__t2,
  .shpd-viewer-row__t3 {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .shpd-viewer-row__i2 { flex-shrink: 0; }

  .shpd-viewer-row__sep { color: var(--shpd-color-border); }

  /* Styled span variants */
  .shpd-viewer-row__span--amount   { font-variant-numeric: tabular-nums; font-weight: 600; }
  .shpd-viewer-row__span--muted    { opacity: 0.6; }
  .shpd-viewer-row__span--bold     { font-weight: 600; }
  .shpd-viewer-row__span--primary  { color: var(--shpd-color-primary); }
  .shpd-viewer-row__span--success  { color: var(--shpd-color-success); }
  .shpd-viewer-row__span--warning  { color: var(--shpd-color-warning); }
  .shpd-viewer-row__span--danger   { color: var(--shpd-color-danger); }
</style>
