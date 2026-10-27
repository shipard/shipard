<script>
  // Štítek z hlavičky detailu (#137 task 3, docs/design-system.md §5):
  // pilulka v roli meta, normální tloušťka. `style` je obecná varianta
  // (`neutral` výchozí, `primary`, `accent`, `success`, `warning`, `danger`)
  // nebo doc-state styl (`concept`, `edit`, `confirmed`, `archive`, `trash`,
  // `cancelled`, `done`, `error`) — tytéž tokeny jako FormStateBadge
  // a štítek návrhu, aby stejný stav vypadal všude stejně; neznámý styl
  // = neutral. Kreslí hlavičku detailu (`detail.badges`) i blok `badges`
  // v obsahu (stav zaúčtování). `SpanBadge` (štítek v řádku seznamu) má
  // vlastní menší metriku a zůstává.
  let { style = 'neutral', label = '', ...rest } = $props();

  const STYLES = [
    'neutral', 'primary', 'accent', 'success', 'warning', 'danger',
    'concept', 'edit', 'confirmed', 'archive', 'trash', 'cancelled', 'done', 'error',
  ];
  let safeStyle = $derived(STYLES.includes(style) ? style : 'neutral');
</script>

<span class="shpd-badge shpd-badge--{safeStyle}" {...rest}>{label}</span>

<style>
  .shpd-badge {
    display: inline-block;
    padding: 0.125rem 0.625rem;
    border-radius: var(--shpd-radius-full);
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-normal);
    line-height: 1.4;
    white-space: nowrap;
  }

  /* Typové varianty (mimo doc-state) — sdílí paletu s doc-state tokeny
     tam, kde to barevně sedí. */
  .shpd-badge--neutral   { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-badge--primary   { background: var(--shpd-color-primary-soft); color: var(--shpd-color-primary); }
  .shpd-badge--accent    { background: var(--shpd-color-accent-soft); color: var(--shpd-color-accent-hover); }
  .shpd-badge--success   { background: var(--shpd-color-state-done-bg); color: var(--shpd-color-state-done-text); }
  .shpd-badge--warning   { background: var(--shpd-color-state-concept-bg); color: var(--shpd-color-state-concept-text); }
  .shpd-badge--danger    { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  /* Doc-state varianty — stejné tokeny jako FormStateBadge a extracted__badge. */
  .shpd-badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }
  .shpd-badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-badge--trash     { background: var(--shpd-color-state-trash-bg);     color: var(--shpd-color-state-trash-text); text-decoration: line-through; }
  .shpd-badge--cancelled { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }
  .shpd-badge--done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-badge--error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }
</style>
