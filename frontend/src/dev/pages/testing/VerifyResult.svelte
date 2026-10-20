<script>
  // Výsledek `dataset-verify --json` (F6b D63): souhrn, apply (aplikované,
  // selhané, přeskočené s důvodem) a tabulka kontrol s rozdíly.
  import { t } from '../../i18n/index.js';

  let { report } = $props();
  let expanded = $state({});

  const STATUS_CLS = { ok: 'match', diff: 'wrong', skipped: 'neutral' };
  const checks = $derived(report?.checks ?? []);
  const apply = $derived(report?.apply ?? null);
</script>

{#if !report}
  <div class="dev-empty">{t('testing.verify.noResult')}</div>
{:else}
  <dl class="dev-kv dev-small">
    <dt>{t('testing.verify.summary')}</dt>
    <dd>
      <span class="dev-lvl dev-lvl--ok">{t('testing.verify.ok', { n: report.summary?.ok ?? 0 })}</span> ·
      <span class="dev-lvl" class:dev-lvl--bad={(report.summary?.diff ?? 0) > 0}>{t('testing.verify.diffs', { n: report.summary?.diff ?? 0 })}</span> ·
      <span class="dev-muted">{t('testing.verify.skipped', { n: report.summary?.skipped ?? 0 })}</span>
      {#if report.exitCode !== undefined}<span class="dev-muted"> · exit {report.exitCode}</span>{/if}
    </dd>
    {#if apply}
      <dt>Apply</dt>
      <dd>
        {#if apply.ran}
          {t('testing.verify.applyLine', { applied: apply.applied?.length ?? 0, failed: apply.failed?.length ?? 0, before: apply.itemsBefore, after: apply.itemsAfter })}
          {#if apply.failed?.length}
            <ul class="dev-blockers">
              {#each apply.failed as f (f.messageId)}<li><code>{f.messageId}</code>: {f.code} — {f.message}</li>{/each}
            </ul>
          {/if}
        {:else}
          <span class="dev-muted">{t('testing.verify.noApply')}</span>
        {/if}
      </dd>
    {/if}
  </dl>

  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead><tr><th>{t('testing.verify.col.check')}</th><th>{t('testing.col.state')}</th><th>{t('testing.verify.col.reason')}</th><th class="dev-num">{t('testing.verify.col.differences')}</th></tr></thead>
      <tbody>
        {#each checks as c (c.id)}
          <tr>
            <td>{c.label ?? c.id} <span class="dev-small dev-muted"><code>{c.id}</code></span></td>
            <td><span class="dev-state dev-state--{STATUS_CLS[c.status] ?? 'neutral'}">{c.status}</span></td>
            <td>
              {#if c.reason}<div>{c.reason}</div>{/if}
              {#each c.notes ?? [] as n, i (i)}<div class="dev-small dev-muted">{n}</div>{/each}
            </td>
            <td class="dev-num">
              {#if c.differences?.length}
                <button type="button" class="dev-chip" class:is-on={expanded[c.id]} onclick={() => (expanded = { ...expanded, [c.id]: !expanded[c.id] })}>{c.differences.length}</button>
              {:else}
                <span class="dev-muted">0</span>
              {/if}
            </td>
          </tr>
          {#if expanded[c.id] && c.differences?.length}
            <tr><td colspan="4"><pre class="dev-output">{c.differences.map((d) => (typeof d === 'string' ? d : JSON.stringify(d))).join('\n')}</pre></td></tr>
          {/if}
        {:else}
          <tr><td colspan="4" class="dev-empty">{t('testing.verify.empty')}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
