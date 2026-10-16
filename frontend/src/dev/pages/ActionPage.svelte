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

  const DS_ID_REGEX = /^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/;

  const route = $derived(router.route);
  const dsId = $derived(route.name === 'dsUpgrade' ? (route.query.ds ?? '') : null);
  const dsIdValid = $derived(dsId === null || DS_ID_REGEX.test(dsId));

  const config = $derived.by(() => {
    if (route.name === 'doctor') {
      return {
        title: 'Doctor serveru',
        command: 'shpd-server doctor',
        description: 'Zkontroluje konfiguraci serveru, práva v souborovém systému, socket PHP-FPM, routování nginx a připojení k databázím. Jen čte — nic nemění.',
        endpoint: '/doctor',
        button: 'Spustit Doctor',
      };
    }
    if (route.name === 'upgradeAll') {
      return {
        title: 'Upgrade všech zdrojů dat',
        command: 'shpd-server ds-upgrade-all',
        description: 'Povýší schéma a konfiguraci na všech zdrojích dat. Idempotentní — zdroj dat, který je aktuální, projde beze změny.',
        endpoint: '/upgrade-all',
        button: 'Spustit upgrade všech',
      };
    }
    const dir = devInfo.value?.dataSourcesDir ?? '/opt/shipard/data-sources';
    return {
      title: `Upgrade zdroje dat — ${dsId || '?'}`,
      command: 'shpd-ds ds-upgrade',
      description: `Spustí upgrade v adresáři ${dir}/${dsId || '<id>'}. Idempotentní — bez změny, pokud je zdroj dat aktuální.`,
      endpoint: `/ds-upgrade?ds=${encodeURIComponent(dsId ?? '')}`,
      button: 'Spustit upgrade',
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
      else if (summary.done) result = { kind: 'success', text: summary.done.payload?.message ?? 'Dokončeno.' };
      else result = { kind: 'finished', text: 'Dokončeno.' };
    } catch (e) {
      result = { kind: 'error', text: e instanceof ApiError ? e.message : `Chyba sítě: ${e.message}` };
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
  <a href={router.href('dataSources')} onclick={onLinkClick}>Zdroje dat</a> ›
  <span>{config.title}</span>
</div>
<div class="dev-page__head">
  <h1>{config.title}</h1>
</div>

{#if !dsIdValid}
  <div class="dev-alert dev-alert--danger">
    <span class="dev-alert__text">Chybí nebo je neplatný parametr <code>?ds=&lt;id&gt;</code> (tvar <code>xxxx-xxxx-xxxx-xxxx</code>).</span>
    <a href={router.href('dataSources')} onclick={onLinkClick}>Zpět na zdroje dat</a>
  </div>
{:else}
  <div class="dev-card">
    <p class="desc">Spustí <code>{config.command}</code>. {config.description}</p>
    <div class="actions">
      <Button label={busy ? 'Běží…' : (result ? 'Spustit znovu' : config.button)} loading={busy} disabled={busy} onclick={run} />
      <a href={router.href('dataSources')} onclick={onLinkClick}>Zpět na dashboard</a>
    </div>
  </div>

  {#if lines.length > 0 || busy}
    <div class="dev-card">
      <div class="dev-card__title">Výstup</div>
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
