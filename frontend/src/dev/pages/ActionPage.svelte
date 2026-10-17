<script>
  // Akční stránka — port `renderActionPage`: Doctor, Upgrade všech,
  // Upgrade jednoho zdroje dat (`?ds=`). Nic nespouští samo, až tlačítkem;
  // výstup streamovaný řádek po řádku, po skončení banner (chyba má
  // přednost, pak zpráva z [DONE], jinak „Dokončeno“).
  import Button from '../../components/ui/Button.svelte';
  import { streamLines, ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { devInfo } from '../info.svelte.js';
  import StreamOutput from '../components/StreamOutput.svelte';
  import { t } from '../i18n/index.js';

  const DS_ID_REGEX = /^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/;

  const route = $derived(router.route);
  const dsId = $derived(route.name === 'dsUpgrade' ? (route.query.ds ?? '') : null);
  const dsIdValid = $derived(dsId === null || DS_ID_REGEX.test(dsId));

  const config = $derived.by(() => {
    if (route.name === 'doctor') {
      return {
        title: t('action.doctor.title'),
        command: 'shpd-server doctor',
        description: t('action.doctor.description'),
        endpoint: '/doctor',
        button: t('action.doctor.button'),
      };
    }
    if (route.name === 'upgradeAll') {
      return {
        title: t('action.upgradeAll.title'),
        command: 'shpd-server ds-upgrade-all',
        description: t('action.upgradeAll.description'),
        endpoint: '/upgrade-all',
        button: t('action.upgradeAll.button'),
      };
    }
    const dir = devInfo.value?.dataSourcesDir ?? '/opt/shipard/data-sources';
    return {
      title: t('action.dsUpgrade.title', { id: dsId || '?' }),
      command: 'shpd-ds ds-upgrade',
      description: t('action.dsUpgrade.description', { dir: `${dir}/${dsId || '<id>'}` }),
      endpoint: `/ds-upgrade?ds=${encodeURIComponent(dsId ?? '')}`,
      button: t('action.dsUpgrade.button'),
    };
  });

  let lines = $state([]);
  let busy = $state(false);
  let result = $state(null); // {kind: 'success'|'error'|'finished', text}

  async function run() {
    lines = [];
    result = null;
    busy = true;
    try {
      const summary = await streamLines(config.endpoint, {
        onLine: (line) => {
          lines = [...lines, line];
        },
      });
      if (summary.error) result = { kind: 'error', text: summary.error.text };
      else if (summary.done) result = { kind: 'success', text: summary.done.payload?.message ?? t('action.done') };
      else result = { kind: 'finished', text: t('action.done') };
    } catch (e) {
      result = { kind: 'error', text: e instanceof ApiError ? e.message : t('common.networkError', { message: e.message }) };
    } finally {
      busy = false;
    }
  }

  // Změna routy (jiná akce) = čistá stránka.
  $effect(() => {
    void route.name;
    void dsId;
    lines = [];
    result = null;
  });
</script>

<div class="dev-breadcrumb">
  <a href={router.href('dataSources')} onclick={onLinkClick}>{t('app.nav.dataSources')}</a> ›
  <span>{config.title}</span>
</div>
<div class="dev-page__head">
  <h1>{config.title}</h1>
</div>

{#if !dsIdValid}
  <div class="dev-alert dev-alert--danger">
    <span class="dev-alert__text">{t('action.invalidParam')}</span>
    <a href={router.href('dataSources')} onclick={onLinkClick}>{t('common.backToDataSources')}</a>
  </div>
{:else}
  <div class="dev-card">
    <p class="desc">{t('action.runs')} <code>{config.command}</code>. {config.description}</p>
    <div class="actions">
      <Button label={busy ? t('action.running') : (result ? t('action.runAgain') : config.button)} variant="primary" loading={busy} disabled={busy} onclick={run} />
      <a href={router.href('dataSources')} onclick={onLinkClick}>{t('common.backToDashboard')}</a>
    </div>
  </div>

  {#if lines.length > 0 || busy}
    <div class="dev-card">
      <div class="dev-card__title">{t('common.output')}</div>
      <StreamOutput {lines} />
    </div>
  {/if}

  {#if result?.kind === 'error'}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{result.text}</span></div>
  {:else if result}
    <div class="dev-alert dev-alert--success"><span class="dev-alert__text">{result.text}</span></div>
  {/if}
{/if}

<style>
  .desc { margin-bottom: var(--shpd-space-md); }
  .actions { display: flex; align-items: center; gap: var(--shpd-space-lg); }
</style>
