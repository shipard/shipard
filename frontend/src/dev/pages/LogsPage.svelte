<script>
  // Logy — port stránky `/_dev/logs/`: filtr úrovní, zdroje dat a fulltextu,
  // „tail -f“ = dotaz každých 5 s (pozastaví se při rozbaleném detailu),
  // rozbalitelný detail s kontextem a stromem výjimky, Načíst starší
  // (zdvojení limitu do 2000). Čisté funkce v logs.js.
  import { onMount } from 'svelte';
  import Button from '../../components/ui/Button.svelte';
  import { getJson } from '../api.js';
  import { router } from '../router.svelte.js';
  import { formatTime } from '../format.js';
  import { LEVELS, DEFAULT_LEVELS, DS_NONE, MAX_LIMIT, filterEntries, dsOptions, shortDs } from '../logs.js';
  import LogException from '../components/LogException.svelte';
  import { t } from '../i18n/index.js';

  const REFRESH_SECONDS = 5;
  const DOCS_LOGGING = 'https://github.com/shipard/shipard/blob/stable/docs/logging.md';

  let entries = $state([]);
  let logFile = $state(null);
  let available = $state(true);
  let loadError = $state(null);
  let limit = $state(200);
  let levels = $state({ ...DEFAULT_LEVELS });
  let ds = $state(router.route.query.ds ?? '');
  let search = $state('');
  let paused = $state(false);
  let countdown = $state(REFRESH_SECONDS);
  let expanded = $state(new Set());

  const filtered = $derived(filterEntries(entries, { levels, ds, search }));
  const options = $derived(dsOptions(entries, ds));
  const atMax = $derived(limit >= MAX_LIMIT);

  async function load() {
    try {
      const data = await getJson(`/logs?limit=${limit}`);
      entries = data.entries ?? [];
      logFile = data.logFile ?? null;
      available = data.available !== false;
      loadError = null;
      expanded = new Set();
    } catch (e) {
      loadError = e.message;
    } finally {
      countdown = REFRESH_SECONDS;
    }
  }

  function toggleLevel(level) {
    levels = { ...levels, [level]: !levels[level] };
  }

  function soloLevel(level) {
    const next = {};
    for (const l of LEVELS) next[l] = l === level;
    levels = next;
  }

  function toggleExpanded(index) {
    const next = new Set(expanded);
    if (next.has(index)) next.delete(index);
    else next.add(index);
    expanded = next;
    if (next.size > 0) paused = true;
    else if (paused && !userPaused) paused = false;
  }

  let userPaused = $state(false);

  function togglePaused() {
    if (paused) {
      // Pokračování při rozbalených řádcích je zavře — detail by uskočil.
      expanded = new Set();
      userPaused = false;
      paused = false;
      load();
    } else {
      userPaused = true;
      paused = true;
    }
  }

  function loadOlder() {
    limit = Math.min(MAX_LIMIT, limit * 2);
    load();
  }

  onMount(() => {
    load();
    const timer = setInterval(() => {
      if (paused) return;
      countdown -= 1;
      if (countdown <= 0) load();
    }, 1000);
    return () => clearInterval(timer);
  });
</script>

<div class="dev-page__head">
  <h1>{t('app.nav.logs')}</h1>
  <span class="dev-page__spacer"></span>
  <Button
    label={paused ? t('logs.resume') : t('logs.pause', { s: countdown })}
    variant="secondary"
    size="sm"
    onclick={togglePaused}
  />
  <span class="dot" class:dot--paused={paused} title={paused ? t('logs.pausedTitle') : t('logs.refreshingTitle')}></span>
</div>

<div class="dev-toolbar">
  {#each LEVELS as level (level)}
    <button type="button" class="dev-chip level-{level}" class:is-on={levels[level]} onclick={() => toggleLevel(level)}>
      {level}
    </button>
  {/each}
  <span class="dev-field" style="min-width: 220px">
    <select bind:value={ds} aria-label={t('common.dataSource')}>
      {#each options as o (o.value)}
        <option value={o.value}>{o.label}</option>
      {/each}
    </select>
  </span>
  <span class="dev-field" style="flex: 1; min-width: 200px">
    <input type="search" placeholder={t('logs.searchPlaceholder')} bind:value={search} />
  </span>
</div>

<div class="status dev-small dev-muted">
  {#if loadError}
    <span class="status-error">{loadError}</span>
  {:else if !available}
    {t('logs.file')} <code>{logFile}</code> • {t('logs.unavailable')}
  {:else}
    {t('logs.file')} <code>{logFile ?? '—'}</code> • {t('logs.shown', { shown: filtered.length, total: entries.length })}
  {/if}
</div>

<div class="dev-table-wrap">
  {#if !available && !loadError}
    <div class="dev-empty">
      {t('logs.noFile')}
      <a href={DOCS_LOGGING} target="_blank" rel="noopener">docs/logging.md</a>.
    </div>
  {:else if entries.length === 0 && !loadError}
    <div class="dev-empty">{t('logs.noEntries')}</div>
  {:else if filtered.length === 0}
    <div class="dev-empty">{t('logs.noMatch')}</div>
  {:else}
    <div class="rows">
      {#each filtered as e, i (i)}
        <div class="row" class:is-open={expanded.has(i)}>
          <button type="button" class="row__main" onclick={() => toggleExpanded(i)}>
            <span class="row__time">{formatTime(e.ts)}</span>
            <span
              class="badge badge--{e.level}"
              role="button"
              tabindex="-1"
              title={t('logs.onlyLevel')}
              onclick={(ev) => { ev.stopPropagation(); soloLevel(e.level); }}
              onkeydown={(ev) => { if (ev.key === 'Enter') { ev.stopPropagation(); soloLevel(e.level); } }}
            >{e.level}</span>
            {#if e.ds}
              <span
                class="ds-chip"
                role="button"
                tabindex="-1"
                title={e.ds}
                onclick={(ev) => { ev.stopPropagation(); ds = e.ds; }}
                onkeydown={(ev) => { if (ev.key === 'Enter') { ev.stopPropagation(); ds = e.ds; } }}
              >{shortDs(e.ds)}</span>
            {:else}
              <span class="ds-chip ds-chip--none">—</span>
            {/if}
            <span class="row__request dev-muted">{e.request ?? ''}</span>
            <span class="row__msg">
              {e.msg}
              {#if e.exception}<span class="row__exc"> {e.exception.class}: {e.exception.message}</span>{/if}
            </span>
          </button>
          {#if expanded.has(i)}
            <div class="row__detail">
              <dl>
                <dt>{t('logs.detail.time')}</dt><dd>{e.ts}</dd>
                <dt>{t('logs.detail.message')}</dt><dd>{e.msg}</dd>
                {#if e.request}<dt>{t('logs.detail.request')}</dt><dd><code>{e.request}</code></dd>{/if}
                <dt>{t('common.dataSource')}</dt><dd>{e.ds ? e.ds : '—'}</dd>
                {#if e.ctx && Object.keys(e.ctx).length > 0}
                  <dt>{t('logs.detail.context')}</dt><dd><pre class="ctx">{JSON.stringify(e.ctx, null, 2)}</pre></dd>
                {/if}
              </dl>
              {#if e.exception}
                <LogException exception={e.exception} />
              {/if}
            </div>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>

<div class="older">
  <Button
    label={atMax ? t('logs.maxShown', { max: MAX_LIMIT }) : t('logs.loadOlder', { limit, next: Math.min(MAX_LIMIT, limit * 2) })}
    variant="secondary"
    size="sm"
    disabled={atMax}
    onclick={loadOlder}
  />
</div>

<style>
  .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--shpd-color-success);
    display: inline-block;
  }
  .dot--paused { background: var(--shpd-color-danger); }

  .status { margin-bottom: var(--shpd-space-sm); }
  .status-error { color: var(--shpd-color-danger); }

  .dev-chip.level-error.is-on { background: var(--shpd-color-state-cancelled-bg); border-color: var(--shpd-color-danger); color: var(--shpd-color-state-cancelled-text); }
  .dev-chip.level-warn.is-on { background: var(--shpd-color-state-concept-bg); border-color: var(--shpd-color-warning); color: var(--shpd-color-state-concept-text); }

  .rows { font-size: var(--shpd-font-size-sm); }

  .row { border-bottom: 1px solid var(--shpd-color-border-subtle); }
  .row:last-child { border-bottom: 0; }
  .row.is-open { background: var(--shpd-color-bg-secondary); }

  .row__main {
    display: grid;
    grid-template-columns: 70px 56px 56px minmax(80px, 180px) 1fr;
    gap: var(--shpd-space-sm);
    align-items: baseline;
    width: 100%;
    padding: 5px 12px;
    background: none;
    border: 0;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
  }
  .row__main:hover { background: var(--shpd-color-bg-hover); }

  .row__time { font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--shpd-color-text-secondary); }
  .row__request { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.75rem; }
  .row__msg { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .row.is-open .row__msg { white-space: normal; }
  .row__exc { color: var(--shpd-color-danger); }

  .badge {
    display: inline-block;
    padding: 0 6px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    text-align: center;
    cursor: pointer;
  }
  .badge--debug { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .badge--info { background: var(--shpd-color-primary-soft); color: var(--shpd-color-primary); }
  .badge--warn { background: var(--shpd-color-state-concept-bg); color: var(--shpd-color-state-concept-text); }
  .badge--error { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  .ds-chip {
    display: inline-block;
    padding: 0 6px;
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 0.75rem;
    cursor: pointer;
  }
  .ds-chip--none { color: var(--shpd-color-text-secondary); cursor: default; }

  .row__detail { padding: var(--shpd-space-sm) 12px var(--shpd-space-md) 82px; }
  .row__detail dl { display: grid; grid-template-columns: 90px 1fr; gap: 2px var(--shpd-space-md); }
  .row__detail dt { color: var(--shpd-color-text-secondary); }
  .row__detail dd { margin: 0; word-break: break-word; }
  .ctx {
    margin: 0;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border-subtle);
    border-radius: var(--shpd-radius-sm);
    font-size: 0.75rem;
    max-height: 320px;
    overflow: auto;
  }

  .older { margin-top: var(--shpd-space-md); }
</style>
