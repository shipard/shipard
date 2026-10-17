<script>
  // Panel „Zdroje dat“ sady (F6b D55, D59): které zdroje dat sadu drží
  // a v jakém jsou stavu (sada aktuální / zastaralá, čistý / použitý, třída,
  // AI, běžící úloha), akce Přeseedovat / Ověřit zaúčtování / Spustit
  // evaluaci, založení evaluačního zdroje dat a přeseedování jiného volného
  // zdroje dat. Pojistku (jen free, jen development) dělá server — tlačítka
  // u reálných kopií se jen nenabízejí.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import DevConfirm from '../../components/DevConfirm.svelte';
  import { iconRefresh, iconAdd } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { ApiError } from '../../api.js';
  import { jobTypeLabel, jobStatusLabel } from '../../testing/jobs.js';
  import { t } from '../../i18n/index.js';
  import { dataClassLabel, formatDateTime } from '../../format.js';
  import EvalRunDialog from './EvalRunDialog.svelte';

  let { set, datasetTitle = '' } = $props();

  let data = $state(null);
  let error = $state(null);
  let busy = $state(false);
  let notice = $state(null); // {kind: 'danger'|'info', text, jobId?}
  let confirm = $state(null); // {title, message, variant, run: () => Promise}
  let evalFor = $state(null); // řádek zdroje dat pro dialog evaluace
  let candidate = $state('');
  let verifyApply = $state(true);

  const hasActiveJob = $derived((data?.dataSources ?? []).some((r) => r.job));

  async function load() {
    try {
      data = await testingApi.dataSources(set);
      error = null;
    } catch (e) {
      error = e.message;
    }
  }

  onMount(() => {
    load();
    const timer = setInterval(() => {
      if (hasActiveJob) load();
    }, 5000);
    return () => clearInterval(timer);
  });

  function failed(e) {
    if (e instanceof ApiError) {
      notice = { kind: 'danger', text: `${e.message} (${e.code})`, jobId: e.details?.jobId ?? null };
    } else {
      notice = { kind: 'danger', text: t('common.networkError', { message: e.message }) };
    }
  }

  async function launch(action) {
    busy = true;
    notice = null;
    try {
      const { job } = await action();
      router.go('testingJob', { jobId: job.id });
    } catch (e) {
      failed(e);
    } finally {
      busy = false;
      confirm = null;
    }
  }

  function askReseed(row) {
    confirm = {
      title: t('testing.dsPanel.reseedTitle'),
      message: t('testing.dsPanel.reseedMessage', { name: row.name, id: row.id, set }),
      variant: 'danger',
      confirmLabel: t('testing.dsPanel.reseed'),
      run: () => launch(() => testingApi.reseed(set, row.id)),
    };
  }

  function askReseedCandidate() {
    const row = (data?.reseedCandidates ?? []).find((c) => c.id === candidate);
    if (!row) return;
    confirm = {
      title: t('testing.dsPanel.reseedOtherTitle'),
      message: t('testing.dsPanel.reseedOtherMessage', { name: row.name, id: row.id, purpose: row.purpose ? t('testing.dsPanel.purposePart', { purpose: row.purpose }) : '', set }),
      variant: 'danger',
      confirmLabel: t('testing.dsPanel.reseed'),
      run: () => launch(() => testingApi.reseed(set, row.id)),
    };
  }

  function askCreate() {
    confirm = {
      title: t('testing.dsPanel.createTitle'),
      message: t('testing.dsPanel.createMessage', { title: datasetTitle || set, set }),
      variant: 'primary',
      confirmLabel: t('testing.dsPanel.create'),
      run: () => launch(() => testingApi.create(set)),
    };
  }

  function askVerify(row) {
    confirm = {
      title: t('testing.dsPanel.verifyTitle'),
      message: t(verifyApply ? 'testing.dsPanel.verifyApplyMessage' : 'testing.dsPanel.verifyNoApplyMessage', { name: row.name }),
      variant: 'primary',
      confirmLabel: t('testing.dsPanel.verify'),
      run: () => launch(() => testingApi.verify(set, row.id, verifyApply)),
    };
  }

  function datasetCell(row) {
    if (!row.dataset) return { cls: 'missing', text: row.legacyOrigin ? t('testing.dsPanel.ds.legacy') : t('testing.dsPanel.ds.unrecorded') };
    return row.dataset.current ? { cls: 'match', text: t('testing.dsPanel.ds.current') } : { cls: 'wrong', text: t('testing.dsPanel.ds.stale') };
  }

  function usageCell(row) {
    if (!row.readable || row.dataClass !== 'free') return { cls: 'neutral', text: '—' };
    if (!row.usage) return { cls: 'failed', text: t('testing.dsPanel.usage.dbDown') };
    if (row.usage.clean) return { cls: 'match', text: t('testing.dsPanel.usage.clean') };
    const parts = [];
    if (row.usage.documents > 0) parts.push(t('testing.dsPanel.usage.documents', { n: row.usage.documents }));
    if (row.usage.tagRules > 0) parts.push(t('testing.dsPanel.usage.rules', { n: row.usage.tagRules }));
    if (row.usage.missing?.length) parts.push(t('testing.dsPanel.usage.missingMessages', { n: row.usage.missing.length }));
    if (row.usage.membership?.length) parts.push(t('testing.dsPanel.usage.foreign'));
    return { cls: 'wrong', text: parts.join(', ') || t('testing.dsPanel.usage.used') };
  }
</script>

<div class="dev-card">
  <div class="dev-page__head">
    <h2 class="dev-card__title">{t('testing.dsPanel.title')}</h2>
    <span class="dev-page__spacer"></span>
    <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" disabled={busy} onclick={load} />
    <Button label={t('testing.dsPanel.createButton')} icon={iconAdd} size="sm" disabled={busy} onclick={askCreate} />
  </div>

  {#if notice}
    <div class="dev-alert dev-alert--{notice.kind}">
      <span class="dev-alert__text">
        {notice.text}
        {#if notice.jobId}
          — <a href={router.href('testingJob', { jobId: notice.jobId })} onclick={onLinkClick}>{t('common.runningJob', { id: notice.jobId })}</a>
        {/if}
      </span>
    </div>
  {/if}

  {#if error}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
  {:else if !data}
    <div class="dev-empty">{t('common.loading')}</div>
  {:else}
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead>
          <tr>
            <th>{t('common.dataSource')}</th><th>{t('ds.col.class')}</th><th>{t('common.dataset')}</th><th>{t('testing.dsPanel.col.usage')}</th><th>{t('testing.col.state')}</th><th>{t('testing.dsPanel.col.job')}</th><th>{t('testing.dsPanel.col.actions')}</th>
          </tr>
        </thead>
        <tbody>
          {#each data.dataSources as row (row.id)}
            {@const ds = datasetCell(row)}
            {@const usage = usageCell(row)}
            <tr>
              <td>
                <div>{row.name}</div>
                <div class="dev-small dev-muted"><code>{row.id}</code> · <a href="/{row.id}/app/" target="_blank" rel="noopener">{t('common.open')}</a></div>
              </td>
              <td>
                <span class="dev-nowrap">{dataClassLabel(row.dataClass)}</span>
                {#if row.serverAi}<span class="dev-state dev-state--neutral" title={t('testing.dsPanel.aiTitle')}>AI</span>{/if}
              </td>
              <td>
                <span class="dev-state dev-state--{ds.cls}">{ds.text}</span>
                {#if row.dataset?.seededAt}<div class="dev-small dev-muted">{formatDateTime(row.dataset.seededAt)}</div>{/if}
              </td>
              <td>
                <span class="dev-state dev-state--{usage.cls}" title={row.usageError ?? ''}>{usage.text}</span>
              </td>
              <td>{row.state}</td>
              <td>
                {#if row.job}
                  <a href={router.href('testingJob', { jobId: row.job.id })} onclick={onLinkClick}>{jobTypeLabel(row.job.type)} · {jobStatusLabel(row.job.status)}</a>
                {:else}
                  <span class="dev-muted">—</span>
                {/if}
              </td>
              <td>
                <div class="dev-btnrow">
                  <Button label={t('testing.dsPanel.reseed')} variant="secondary" size="sm" disabled={busy || !row.canReseed} onclick={() => askReseed(row)} />
                  <Button label={t('testing.dsPanel.verifyButton')} variant="secondary" size="sm" disabled={busy || !row.canVerify} onclick={() => askVerify(row)} />
                  <span title={row.blockers.join('\n')}>
                    <Button label={t('testing.dsPanel.runEval')} variant="secondary" size="sm" disabled={busy || !row.canEval} onclick={() => (evalFor = row)} />
                  </span>
                </div>
                {#if row.blockers.length}
                  <ul class="dev-blockers">
                    {#each row.blockers as b (b)}<li>{b}</li>{/each}
                  </ul>
                {/if}
              </td>
            </tr>
          {:else}
            <tr><td colspan="7" class="dev-empty">{t('testing.dsPanel.empty')}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>

    <div class="dev-toolbar">
      <label class="dev-field dev-field--inline">
        <input type="checkbox" bind:checked={verifyApply} />
        <span>{t('testing.dsPanel.verifyApplyLabel')}</span>
      </label>
      <span class="dev-page__spacer"></span>
      {#if data.reseedCandidates.length}
        <select bind:value={candidate} disabled={busy}>
          <option value="">{t('testing.dsPanel.reseedOtherOption')}</option>
          {#each data.reseedCandidates as c (c.id)}
            <option value={c.id}>{c.name} ({c.id}){c.purpose ? ` — ${c.purpose}` : ''}</option>
          {/each}
        </select>
        <Button label={t('testing.dsPanel.reseed')} variant="secondary" size="sm" disabled={busy || candidate === ''} onclick={askReseedCandidate} />
      {/if}
    </div>
  {/if}
</div>

<DevConfirm
  open={confirm !== null}
  title={confirm?.title ?? ''}
  message={confirm?.message ?? ''}
  variant={confirm?.variant ?? 'primary'}
  confirmLabel={confirm?.confirmLabel ?? t('common.confirm')}
  cancelLabel={t('common.cancel')}
  busy={busy}
  onConfirm={() => confirm?.run()}
  onCancel={() => (confirm = null)}
/>

{#if evalFor}
  <EvalRunDialog
    open={true}
    {set}
    dataSource={evalFor}
    onClose={() => (evalFor = null)}
    onStarted={(job) => { evalFor = null; router.go('testingJob', { jobId: job.id }); }}
  />
{/if}

<style>
  .dev-toolbar { margin-top: var(--shpd-space-sm); }
  .dev-toolbar select { max-width: 420px; }
</style>
