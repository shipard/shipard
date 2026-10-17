<script>
  // Zdroje dat — port původní stránky `/_dev/`: tabulka, akce, automatické
  // obnovení (`?refresh=N` 5–3600 s, výchozí 60, parametr se z URL uklidí
  // jako dřív). Obnovení = nové načtení seznamu, ne reload. Akce (#103 D71):
  // Otevřít + kebab menu ⋮ (Logy, Upgrade, Nahrát datovou sadu — jen volný
  // zdroj dat se sekcí Testování a bez běžící úlohy, Kopírovat ID, Smazat… —
  // jen volný zdroj dat bez běžící úlohy); neaktivní položky se neukazují.
  // Smazat… (D70) otevře dialog s opsáním názvu a spustí úlohu `delete`.
  // Běžící úloha se ukazuje v řádku a seznam se při ní obnovuje po 5 s;
  // smazaný zdroj dat z něj po doběhnutí úlohy zmizí sám. Pojistku dělá server.
  // Bez zdrojů dat (a bez chyby) místo tabulky karta „Začni tady“ pro nováčka
  // (#129 D15); třída dat a AI jako štítky, cron slovy s ikonou u vypnutého.
  import { onMount } from 'svelte';
  import Button from '../../components/ui/Button.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconAdd, iconRefresh, iconDocument, iconUpgrade, iconUpload, iconCopy, iconDelete, iconOpenExternal, iconClock } from '../../icons.js';
  import { docsUrl } from '../links.js';
  import { getJson } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { devInfo } from '../info.svelte.js';
  import { jobTypeLabel, jobStatusLabel } from '../testing/jobs.js';
  import { formatDateTime, dataClassLabel, dataClassTitle, truncate } from '../format.js';
  import { t, label, currentLanguage } from '../i18n/index.js';
  import DevKebabMenu from '../components/DevKebabMenu.svelte';
  import LoadDatasetDialog from './LoadDatasetDialog.svelte';
  import DeleteDataSourceDialog from './DeleteDataSourceDialog.svelte';

  const JOB_REFRESH_SECONDS = 5;

  let items = $state([]);
  let loading = $state(true);
  let error = $state(null);
  let refreshSeconds = $state(60);
  let countdown = $state(60);
  let copiedId = $state(null);
  let loadFor = $state(null); // řádek zdroje dat pro dialog Nahrát datovou sadu
  let deleteFor = $state(null); // řádek zdroje dat pro dialog Smazat zdroj dat
  let notice = $state(null); // {kind: 'success'|'danger', text, jobId?}
  let sinceLoad = 0;

  const hasActiveJob = $derived(items.some((ds) => ds.job));

  function canLoadDataset(ds) {
    return Boolean(devInfo.value?.testing) && ds.dataClass === 'free' && ds.state === 'active';
  }

  function canDelete(ds) {
    return ds.dataClass === 'free';
  }

  /** Položky kebab menu řádku — neaktivní se vůbec nepředávají (D71). */
  function menuItems(ds) {
    const menu = [
      { key: 'logs', label: t('app.nav.logs'), icon: iconDocument, href: router.href('logs', {}, { ds: ds.id }) },
      { key: 'upgrade', label: t('ds.menu.upgrade'), icon: iconUpgrade, href: router.href('dsUpgrade', {}, { ds: ds.id }) },
    ];
    if (canLoadDataset(ds) && !ds.job) {
      menu.push({ key: 'dataset', label: t('ds.menu.loadDataset'), icon: iconUpload, onSelect: () => (loadFor = ds) });
    }
    menu.push({ key: 'copy', label: t('ds.menu.copyId'), icon: iconCopy, onSelect: () => copyId(ds.id) });
    if (canDelete(ds) && !ds.job) {
      menu.push({ key: 'delete', label: t('ds.menu.delete'), icon: iconDelete, danger: true, separator: true, onSelect: () => (deleteFor = ds) });
    }
    return menu;
  }

  function heldDataset(ds) {
    return ds.dataset?.name ?? ds.legacyDataset ?? null;
  }

  /** Štítek třídy dat: bez explicitní třídy jen rámeček (v main.json chybí → platí reálná kopie). */
  function dataClassBadge(ds) {
    if (!ds.dataClassExplicit) return 'outline';
    return ds.dataClass === 'free' ? 'free' : 'real';
  }

  function onJobStarted(job) {
    loadFor = null;
    deleteFor = null;
    notice = { kind: 'success', text: t('ds.jobStarted', { type: jobTypeLabel(job.type) }), jobId: job.id };
    load();
  }

  function readRefreshParam() {
    const raw = router.route.query.refresh;
    if (raw === undefined) return 60;
    router.replaceQuery({});
    const n = Number(raw);
    return Number.isInteger(n) && n >= 5 && n <= 3600 ? n : 60;
  }

  async function load() {
    try {
      items = await getJson('/data-sources');
      error = null;
    } catch (e) {
      error = t('ds.loadError', { message: e.message });
    } finally {
      loading = false;
      countdown = refreshSeconds;
      sinceLoad = 0;
    }
  }

  function refreshNow() {
    countdown = refreshSeconds;
    load();
  }

  async function copyId(id) {
    try {
      await navigator.clipboard.writeText(id);
      copiedId = id;
      setTimeout(() => {
        if (copiedId === id) copiedId = null;
      }, 1500);
    } catch {
      /* bez clipboard API (http bez localhost) — nic */
    }
  }

  onMount(() => {
    refreshSeconds = readRefreshParam();
    countdown = refreshSeconds;
    devInfo.load();
    load();
    const timer = setInterval(() => {
      countdown -= 1;
      sinceLoad += 1;
      if (countdown <= 0 || (hasActiveJob && sinceLoad >= JOB_REFRESH_SECONDS)) load();
    }, 1000);
    return () => clearInterval(timer);
  });
</script>

<div class="dev-page__head">
  <h1>{t('app.nav.dataSources')}</h1>
  <span class="dev-muted dev-small">{t('ds.refreshIn', { s: countdown })}</span>
  <span class="dev-page__spacer"></span>
  <Button label={t('common.refresh')} icon={iconRefresh} variant="secondary" size="sm" onclick={refreshNow} />
  <a class="dev-btn-primary" href={router.href('dsCreate')} onclick={onLinkClick}><Icon icon={iconAdd} size="sm" />{t('ds.new')}</a>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{/if}

{#if notice}
  <div class="dev-alert dev-alert--{notice.kind}">
    <span class="dev-alert__text">
      {notice.text}
      {#if notice.jobId}
        <a href={router.href('testingJob', { jobId: notice.jobId })} onclick={onLinkClick}>{t('ds.jobProgress', { id: notice.jobId })}</a>
      {/if}
    </span>
    <Button label={t('common.close')} variant="secondary" size="sm" onclick={() => (notice = null)} />
  </div>
{/if}

{#if !loading && items.length === 0 && !error}
  <div class="dev-start">
    <div class="dev-card dev-start__card">
      <span class="dev-badge dev-badge--free">{t('ds.start.badge')}</span>
      <h2 class="dev-start__title">{t('ds.start.title')}</h2>
      <p>{t('ds.start.text')}</p>
      <ol class="dev-start__steps">
        <li>{t('ds.start.step1')}</li>
        <li>{t('ds.start.step2')}</li>
        <li>{t('ds.start.step3')}</li>
      </ol>
      <div class="dev-btnrow">
        <a class="dev-btn-primary dev-btn-primary--md" href={router.href('dsCreate')} onclick={onLinkClick}><Icon icon={iconAdd} />{t('ds.start.create')}</a>
        <a class="dev-btn-outline dev-btn-outline--md" href={docsUrl(currentLanguage())} target="_blank" rel="noopener">{t('ds.start.guide')}</a>
      </div>
    </div>
    <aside class="dev-start__aside">
      <h3 class="dev-start__aside-title">{t('ds.start.alsoTitle')}</h3>
      <dl class="dev-start__list">
        <dt><a href={router.href('logs')} onclick={onLinkClick}>{t('app.nav.logs')}</a></dt>
        <dd>{t('ds.start.logs')}</dd>
        <dt><a href={router.href('doctor')} onclick={onLinkClick}>{t('app.nav.doctor')}</a></dt>
        <dd>{t('ds.start.doctor')}</dd>
        <dt><a href={router.href('upgradeAll')} onclick={onLinkClick}>{t('app.nav.upgradeAll')}</a></dt>
        <dd>{t('ds.start.upgradeAll')}</dd>
      </dl>
    </aside>
  </div>
{:else}
<div class="dev-table-wrap">
  <table class="dev-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>{t('ds.col.name')}</th>
        <th>{t('ds.col.class')}</th>
        <th>{t('ds.col.purpose')}</th>
        <th>{t('ds.col.created')}</th>
        <th>Cron</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {#if loading}
        <tr><td colspan="7" class="dev-empty">{t('common.loading')}</td></tr>
      {:else}
        {#each items as ds (ds.id)}
          <tr>
            <td class="dev-nowrap">
              <code>{ds.id}</code>
              {#if copiedId === ds.id}
                <span class="dev-small dev-muted copied" role="status">{t('ds.copied')}</span>
              {/if}
            </td>
            <td>
              {ds.name}
              {#if ds.job}
                <div class="dev-small">
                  <a href={router.href('testingJob', { jobId: ds.job.id })} onclick={onLinkClick} title={t('ds.runningJobTitle')}>
                    <Icon icon={iconRefresh} size="sm" /> {jobTypeLabel(ds.job.type)} · {jobStatusLabel(ds.job.status)}
                  </a>
                </div>
              {/if}
            </td>
            <!-- Třída dat (#126 D10) jako štítek: volný modře, reálná kopie žlutě, bez explicitní
                 třídy jen rámeček (v main.json chybí → reálná kopie); značka AI = serverové AI připojení. -->
            <td class="dev-nowrap">
              <span class="dev-badge dev-badge--{dataClassBadge(ds)}" title={dataClassTitle(ds)}>
                {dataClassLabel(ds.dataClass)}
              </span>
              {#if ds.serverAi}
                <span class="dev-badge dev-badge--outline" title={t('ds.aiTitle')}>AI</span>
              {/if}
            </td>
            <td class="dev-muted purpose" title={ds.purpose ?? ''}>
              {truncate(ds.purpose)}
              {#if heldDataset(ds)}
                <div class="dev-small" title={ds.dataset ? t('ds.seededAt', { when: formatDateTime(ds.dataset.seededAt) }) : t('ds.datasetLegacyTitle')}>
                  {t('ds.datasetLabel')} <code>{heldDataset(ds)}</code>{#if !ds.dataset} <span class="dev-muted">{t('ds.datasetFromOrigin')}</span>{/if}
                </div>
              {/if}
            </td>
            <td class="dev-nowrap dev-muted">{ds.created ? formatDateTime(ds.created) : '—'}</td>
            <td class="dev-nowrap">
              {#if ds.cron}
                <span>{t('ds.cron.on')}</span>
              {:else}
                <span class="cron-off" title={t('ds.cron.offTitle')}><Icon icon={iconClock} size="sm" /> {t('ds.cron.off')}</span>
              {/if}
              {#if ds.state && ds.state !== 'active'}
                <span class="dev-muted"> · {label('ds.state', ds.state)}</span>
              {/if}
            </td>
            <td class="dev-actions">
              <span class="dev-actions__inner">
                <a class="dev-btn-outline" href={`/${ds.id}/app/`} target="_blank" rel="noopener">{t('common.open')} <Icon icon={iconOpenExternal} size="sm" /></a>
                <DevKebabMenu label={t('common.moreActionsFor', { name: ds.name })} items={menuItems(ds)} />
              </span>
            </td>
          </tr>
        {/each}
      {/if}
    </tbody>
  </table>
</div>
{/if}

{#if loadFor}
  <LoadDatasetDialog open={true} ds={loadFor} onClose={() => (loadFor = null)} onStarted={onJobStarted} />
{/if}

{#if deleteFor}
  <DeleteDataSourceDialog open={true} ds={deleteFor} onClose={() => (deleteFor = null)} onStarted={onJobStarted} />
{/if}

<style>
  .copied { margin-left: 6px; }

  .cron-off {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--shpd-color-danger);
    font-weight: 600;
    cursor: help;
  }

  .purpose { max-width: 18rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
