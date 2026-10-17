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
  import { JOB_TYPE_LABELS, JOB_STATUS_LABELS } from '../../testing/jobs.js';
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
      notice = { kind: 'danger', text: `Chyba sítě: ${e.message}` };
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
      title: 'Přeseedovat zdroj dat',
      message: `Zdroj dat „${row.name}“ (${row.id}) se RESETUJE — všechna data zmizí — a naseeduje sadou ${set}.`,
      variant: 'danger',
      confirmLabel: 'Přeseedovat',
      run: () => launch(() => testingApi.reseed(set, row.id)),
    };
  }

  function askReseedCandidate() {
    const row = (data?.reseedCandidates ?? []).find((c) => c.id === candidate);
    if (!row) return;
    confirm = {
      title: 'Přeseedovat jiný volný zdroj dat',
      message: `Zdroj dat „${row.name}“ (${row.id}${row.purpose ? `, účel: ${row.purpose}` : ''}) zatím žádnou sadu nedrží. RESETUJE se — všechna data zmizí — a naseeduje sadou ${set}.`,
      variant: 'danger',
      confirmLabel: 'Přeseedovat',
      run: () => launch(() => testingApi.reseed(set, row.id)),
    };
  }

  function askCreate() {
    confirm = {
      title: 'Založit evaluační zdroj dat',
      message: `Založí se nový volný zdroj dat „Evaluace — ${datasetTitle || set}“ (ds-create --no-cron, bez uživatele) a naseeduje sadou ${set}. Trvá to několik minut; průběh uvidíš v úloze.`,
      variant: 'primary',
      confirmLabel: 'Založit',
      run: () => launch(() => testingApi.create(set)),
    };
  }

  function askVerify(row) {
    confirm = {
      title: 'Ověřit zaúčtování',
      message: verifyApply
        ? `Spustí se dataset-verify nad „${row.name}“: návrhy sady se APLIKUJÍ (zdroj dat pak bude použitý — před evaluací ho přeseeduj) a zkontroluje se účetnictví, DPH a náklady.`
        : `Spustí se dataset-verify --no-apply nad „${row.name}“: jen kontroly nad tím, co už ve zdroji dat je.`,
      variant: 'primary',
      confirmLabel: 'Ověřit',
      run: () => launch(() => testingApi.verify(set, row.id, verifyApply)),
    };
  }

  function datasetCell(row) {
    if (!row.dataset) return { cls: 'missing', text: row.legacyOrigin ? 'z původu — přeseedovat' : 'nezaznamenaná — přeseedovat' };
    return row.dataset.current ? { cls: 'match', text: 'aktuální' } : { cls: 'wrong', text: 'zastaralá' };
  }

  function usageCell(row) {
    if (!row.readable || row.dataClass !== 'free') return { cls: 'neutral', text: '—' };
    if (!row.usage) return { cls: 'failed', text: 'DB nedostupná' };
    if (row.usage.clean) return { cls: 'match', text: 'čistý' };
    const parts = [];
    if (row.usage.documents > 0) parts.push(`${row.usage.documents} dokladů`);
    if (row.usage.tagRules > 0) parts.push(`${row.usage.tagRules} pravidel`);
    if (row.usage.missing?.length) parts.push(`chybí ${row.usage.missing.length} zpráv`);
    if (row.usage.membership?.length) parts.push('cizí data');
    return { cls: 'wrong', text: parts.join(', ') || 'použitý' };
  }
</script>

<div class="dev-card">
  <div class="dev-page__head">
    <h2 class="dev-card__title">Zdroje dat se sadou</h2>
    <span class="dev-page__spacer"></span>
    <Button label="Obnovit" icon={iconRefresh} variant="secondary" size="sm" disabled={busy} onclick={load} />
    <Button label="Založit evaluační zdroj dat" icon={iconAdd} size="sm" disabled={busy} onclick={askCreate} />
  </div>

  {#if notice}
    <div class="dev-alert dev-alert--{notice.kind}">
      <span class="dev-alert__text">
        {notice.text}
        {#if notice.jobId}
          — <a href={router.href('testingJob', { jobId: notice.jobId })} onclick={onLinkClick}>běžící úloha {notice.jobId}</a>
        {/if}
      </span>
    </div>
  {/if}

  {#if error}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
  {:else if !data}
    <div class="dev-empty">Načítám…</div>
  {:else}
    <div class="dev-table-wrap">
      <table class="dev-table">
        <thead>
          <tr>
            <th>Zdroj dat</th><th>Třída</th><th>Sada</th><th>Použití</th><th>Stav</th><th>Úloha</th><th>Akce</th>
          </tr>
        </thead>
        <tbody>
          {#each data.dataSources as row (row.id)}
            {@const ds = datasetCell(row)}
            {@const usage = usageCell(row)}
            <tr>
              <td>
                <div>{row.name}</div>
                <div class="dev-small dev-muted"><code>{row.id}</code> · <a href="/{row.id}/app/" target="_blank" rel="noopener">Otevřít</a></div>
              </td>
              <td>
                <span class="dev-nowrap">{dataClassLabel(row.dataClass)}</span>
                {#if row.serverAi}<span class="dev-state dev-state--neutral" title="serverové AI připojení">AI</span>{/if}
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
                  <a href={router.href('testingJob', { jobId: row.job.id })} onclick={onLinkClick}>{JOB_TYPE_LABELS[row.job.type] ?? row.job.type} · {JOB_STATUS_LABELS[row.job.status] ?? row.job.status}</a>
                {:else}
                  <span class="dev-muted">—</span>
                {/if}
              </td>
              <td>
                <div class="dev-btnrow">
                  <Button label="Přeseedovat" variant="secondary" size="sm" disabled={busy || !row.canReseed} onclick={() => askReseed(row)} />
                  <Button label="Ověřit zaúčtování" variant="secondary" size="sm" disabled={busy || !row.canVerify} onclick={() => askVerify(row)} />
                  <span title={row.blockers.join('\n')}>
                    <Button label="Spustit evaluaci" size="sm" disabled={busy || !row.canEval} onclick={() => (evalFor = row)} />
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
            <tr><td colspan="7" class="dev-empty">Žádný zdroj dat tuhle sadu nedrží — založ evaluační zdroj dat, nebo přeseeduj jiný volný.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>

    <div class="dev-toolbar">
      <label class="dev-field dev-field--inline">
        <input type="checkbox" bind:checked={verifyApply} />
        <span>Ověření s aplikací návrhů (<code>apply</code>; po něm je zdroj dat použitý)</span>
      </label>
      <span class="dev-page__spacer"></span>
      {#if data.reseedCandidates.length}
        <select bind:value={candidate} disabled={busy}>
          <option value="">Přeseedovat jiný volný zdroj dat…</option>
          {#each data.reseedCandidates as c (c.id)}
            <option value={c.id}>{c.name} ({c.id}){c.purpose ? ` — ${c.purpose}` : ''}</option>
          {/each}
        </select>
        <Button label="Přeseedovat" variant="secondary" size="sm" disabled={busy || candidate === ''} onclick={askReseedCandidate} />
      {/if}
    </div>
  {/if}
</div>

<DevConfirm
  open={confirm !== null}
  title={confirm?.title ?? ''}
  message={confirm?.message ?? ''}
  variant={confirm?.variant ?? 'primary'}
  confirmLabel={confirm?.confirmLabel ?? 'Potvrdit'}
  cancelLabel="Zrušit"
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
