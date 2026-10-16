<script>
  // Jeden řádek o běhu (EvalRunSummary): varianta · model (štítky) ·
  // prompt · ×N · datum · commit · štítek „jiná verze sady“.
  import { formatDateTime, formatCommit } from '../format.js';
  let { summary, showFile = true } = $props();
</script>

<div class="line dev-small">
  {#if showFile}<code>{summary.file}</code>{/if}
  <span><strong>{summary.variant}</strong></span>
  <span class="dev-muted">model</span> <code>{summary.model ?? '—'}</code>
  {#if summary.tagsModel && summary.tagsModel !== summary.model}
    <span class="dev-muted">štítky</span> <code>{summary.tagsModel}</code>
  {/if}
  {#if summary.thinking || summary.effort}
    <span class="dev-muted">thinking</span> <span>{summary.thinking ?? '—'}</span>
    <span class="dev-muted">effort</span> <span>{summary.effort ?? '—'}</span>
  {/if}
  <span class="dev-muted">prompt</span> <span>{summary.promptVersion ?? '—'}</span>
  <span>× {summary.repeat}</span>
  <span>{formatDateTime(summary.startedAt)}</span>
  <span class="dev-muted" title={summary.dirty ? 'neuložené změny v checkoutu' : ''}>{formatCommit(summary.commit, summary.dirty)}</span>
  {#if summary.currentSet === false}
    <span class="dev-state dev-state--missing" title="Běh vznikl nad starší verzí sady — očekávání se od té doby mohlo změnit">jiná verze sady</span>
  {/if}
  {#if summary.only?.length || summary.limit}
    <span class="dev-state dev-state--neutral">výběr: {summary.only?.length ? `--only ${summary.only.join(',')}` : ''} {summary.limit ? `--limit ${summary.limit}` : ''}</span>
  {/if}
</div>

<style>
  .line { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; }
</style>
