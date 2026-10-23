<script>
  let { docStates = null } = $props();
</script>

{#if docStates}
  <span class="shpd-form-state-badge docState_{docStates.stateStyle}">
    {docStates.stateName}
  </span>
{/if}

<style>
  .shpd-form-state-badge {
    display: inline-block;
    padding: 0.125rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    white-space: nowrap;
  }

  /* Stylové varianty.
   *
   * Používáme child selectory (.shpd-form-state-badge.docState_*)
   * místo :global(.docState_*) — :global by se chytlo i na .docState_*
   * třídy v jiných komponentách (např. ViewerRow), kde mají
   * úplně jiný význam (tam doc-state ovládá jen levý proužek,
   * pozadí je bílé). Sdílené třídní názvy + :global by tedy
   * v jednom turně kolidovaly.
   *
   * Paleta sjednocená s ostatními badge v aplikaci:
   *   concept   → žlutá
   *   done      → zelená ("hotovo")
   *   edit      → fialová (NE oranžová — ta je pro brand accent)
   *   confirmed → tichá šedá ("OK = klid")
   *   archive   → šedá
   *   trash     → šedá + line-through
   *   cancelled → červená
   *   error     → červená
   */
  .shpd-form-state-badge.docState_concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }
  .shpd-form-state-badge.docState_done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-form-state-badge.docState_edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-form-state-badge.docState_confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-form-state-badge.docState_archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-form-state-badge.docState_trash     { background: var(--shpd-color-state-trash-bg);     color: var(--shpd-color-state-trash-text); text-decoration: line-through; }
  .shpd-form-state-badge.docState_cancelled { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }
  .shpd-form-state-badge.docState_error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }
</style>
