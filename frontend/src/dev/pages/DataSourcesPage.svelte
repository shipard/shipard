<script>
  // Zdroje dat — port původní stránky `/_dev/`: tabulka, akce, kopírování
  // ID, automatické obnovení (`?refresh=N` 5–3600 s, výchozí 60, parametr
  // se z URL uklidí jako dřív). Obnovení = nové načtení seznamu, ne reload.
  import { onMount } from 'svelte';
  import Button from '../../components/ui/Button.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconAdd, iconRefresh, iconCopy, iconConfirm } from '../../icons.js';
  import { getJson } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { formatDateTime } from '../format.js';

  const STATE_LABELS = {
    read_only: 'jen ke čtení',
    suspended: 'pozastaven',
    pending_deletion: 'ke smazání',
    corrupted: 'poškozený stav',
  };

  let items = $state([]);
  let loading = $state(true);
  let error = $state(null);
  let refreshSeconds = $state(60);
  let countdown = $state(60);
  let copiedId = $state(null);

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
      error = `Zdroje dat se nepodařilo načíst (${e.message})`;
    } finally {
      loading = false;
      countdown = refreshSeconds;
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
      }, 1000);
    } catch {
      /* bez clipboard API (http bez localhost) — nic */
    }
  }

  onMount(() => {
    refreshSeconds = readRefreshParam();
    countdown = refreshSeconds;
    load();
    const timer = setInterval(() => {
      countdown -= 1;
      if (countdown <= 0) load();
    }, 1000);
    return () => clearInterval(timer);
  });
</script>

<div class="dev-page__head">
  <h1>Zdroje dat</h1>
  <span class="dev-muted dev-small">obnovení za {countdown} s</span>
  <span class="dev-page__spacer"></span>
  <Button label="Obnovit" icon={iconRefresh} variant="secondary" size="sm" onclick={refreshNow} />
  <a href={router.href('dsCreate')} onclick={onLinkClick} class="dev-btn-link">
    <Button label="Nový zdroj dat" icon={iconAdd} size="sm" />
  </a>
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{/if}

<div class="dev-table-wrap">
  <table class="dev-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Název</th>
        <th>Vytvořeno</th>
        <th>Databáze</th>
        <th>Cron</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {#if loading}
        <tr><td colspan="6" class="dev-empty">Načítám…</td></tr>
      {:else if items.length === 0 && !error}
        <tr>
          <td colspan="6" class="dev-empty">
            Zatím žádné zdroje dat. Založ první přes
            <a href={router.href('dsCreate')} onclick={onLinkClick}>Nový zdroj dat</a>
            — zaškrtni „Naseedovat testovací data“, dostaneš ukázkové osoby a poštu.
          </td>
        </tr>
      {:else}
        {#each items as ds (ds.id)}
          <tr>
            <td class="dev-nowrap">
              <code>{ds.id}</code>
              <button
                class="copy-btn"
                type="button"
                title="Kopírovat ID"
                aria-label="Kopírovat ID"
                onclick={() => copyId(ds.id)}
              >
                <Icon icon={copiedId === ds.id ? iconConfirm : iconCopy} size="sm" />
              </button>
            </td>
            <td>{ds.name}</td>
            <td class="dev-nowrap dev-muted">{ds.created ? formatDateTime(ds.created) : '—'}</td>
            <td><code>{ds.database_name ?? '—'}</code></td>
            <td class="dev-nowrap">
              {#if ds.cron}
                <span>zap</span>
              {:else}
                <span class="cron-off" title="Periodické úlohy jsou pro tento zdroj dat vypnuté — zapnutí: shpd-ds ds-state cron --on">vyp</span>
              {/if}
              {#if ds.state && ds.state !== 'active'}
                <span class="dev-muted"> · {STATE_LABELS[ds.state] ?? ds.state}</span>
              {/if}
            </td>
            <td class="dev-actions">
              <a class="action" href={`/${ds.id}/app/`} target="_blank" rel="noopener">Otevřít</a>
              <a class="action" href={router.href('logs', {}, { ds: ds.id })} onclick={onLinkClick}>Logy</a>
              <a class="action" href={router.href('dsUpgrade', {}, { ds: ds.id })} onclick={onLinkClick}>Upgrade</a>
            </td>
          </tr>
        {/each}
      {/if}
    </tbody>
  </table>
</div>

<style>
  .dev-btn-link { text-decoration: none; }

  .copy-btn {
    background: none;
    border: 0;
    padding: 2px 4px;
    margin-left: 4px;
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
    vertical-align: middle;
  }

  .copy-btn:hover { color: var(--shpd-color-primary); }

  .cron-off {
    color: var(--shpd-color-danger);
    font-weight: 600;
    cursor: help;
  }

  .action {
    display: inline-block;
    padding: 2px 8px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    text-decoration: none;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    background: var(--shpd-color-bg);
  }

  .action:hover {
    border-color: var(--shpd-color-primary);
    color: var(--shpd-color-primary);
  }
</style>
