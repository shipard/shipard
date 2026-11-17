<script>
  // Pilulka pro span s `badge: <style>` (docs/viewer-grid.md §3.4, D5).
  // Sdílí ji ViewerRow (list) i ViewerGrid — proto samostatná komponenta,
  // ne kopie CSS v obou. Paleta = badge systém (design-system.md §5),
  // stejné tokeny jako ui/Badge.svelte (.shpd-badge--*), ale lokální třídy
  // (žádné :global — anti-pattern §6). `badge` má přednost před `class`.
  // Klidný tón `success` není pilulka (#153 D99): tečka `success-mark`
  // + text barvou a písmem okolí, stejně jako span `success` v řádku —
  // klidný stav nevyčnívá. Tečku na vybraném řádku (plocha tónu s obrysem)
  // přebarvuje rodič (`ViewerRow`, `ViewerGrid`).
  let { style = 'neutral', text } = $props();
</script>

<span class="shpd-span-badge shpd-span-badge--{style}">{text}</span>

<style>
  .shpd-span-badge {
    display: inline-block;
    padding: 0.0625rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-full);
    font-size: var(--shpd-font-size-xs);
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
  }

  /* Typové varianty (mimo doc-state) — stejná paleta jako
   * .shpd-badge--* v ui/Badge.svelte. */
  .shpd-span-badge--neutral   { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-span-badge--primary   { background: var(--shpd-color-primary-soft); color: var(--shpd-color-primary-text); }
  .shpd-span-badge--warning   { background: var(--shpd-color-state-concept-bg); color: var(--shpd-color-state-concept-text); }
  .shpd-span-badge--danger    { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  /* Klidný tón (D99): bez podkladu a odsazení, písmo a barva z okolí,
     před textem tečka (velikost jako tečky nadpisů feedu). */
  .shpd-span-badge--success {
    padding: 0;
    border-radius: 0;
    font-size: inherit;
    font-weight: inherit;
    line-height: inherit;
    color: inherit;
  }

  .shpd-span-badge--success::before {
    content: '';
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    margin-right: var(--shpd-space-xs);
    border-radius: 50%;
    vertical-align: middle;
    background: var(--shpd-color-success-mark);
  }

  /* Doc-state varianty — sdílené tokeny, stejný stav vypadá všude stejně. */
  .shpd-span-badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }
  .shpd-span-badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-span-badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-span-badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-span-badge--trash     { background: var(--shpd-color-state-trash-bg);     color: var(--shpd-color-state-trash-text); }
  .shpd-span-badge--cancelled { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }
  .shpd-span-badge--done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-span-badge--error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }
</style>
