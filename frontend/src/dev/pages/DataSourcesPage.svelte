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
  import { DOCS_URL } from '../links.js';
  import { getJson } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { devInfo } from '../info.svelte.js';
  import { JOB_TYPE_LABELS, JOB_STATUS_LABELS } from '../testing/jobs.js';
  import { formatDateTime, dataClassLabel, dataClassTitle, truncate } from '../format.js';
  import DevKebabMenu from '../components/DevKebabMenu.svelte';
  import LoadDatasetDialog from './LoadDatasetDialog.svelte';
  import DeleteDataSourceDialog from './DeleteDataSourceDialog.svelte';

  const STATE_LABELS = {
    read_only: 'jen ke čtení',
    suspended: 'pozastaven',
    pending_deletion: 'ke smazání',
    corrupted: 'poškozený stav',
  };
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
      { key: 'logs', label: 'Logy', icon: iconDocument, href: router.href('logs', {}, { ds: ds.id }) },
      { key: 'upgrade', label: 'Upgrade', icon: iconUpgrade, href: router.href('dsUpgrade', {}, { ds: ds.id }) },
    ];
    if (canLoadDataset(ds) && !ds.job) {
      menu.push({ key: 'dataset', label: 'Nahrát datovou sadu', icon: iconUpload, onSelect: () => (loadFor = ds) });
    }
    menu.push({ key: 'copy', label: 'Kopírovat ID', icon: iconCopy, onSelect: () => copyId(ds.id) });
    if (canDelete(ds) && !ds.job) {
      menu.push({ key: 'delete', label: 'Smazat…', icon: iconDelete, danger: true, separator: true, onSelect: () => (deleteFor = ds) });
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
    notice = { kind: 'success', text: `Úloha ${JOB_TYPE_LABELS[job.type] ?? job.type} spuštěna.`, jobId: job.id };
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
      error = `Zdroje dat se nepodařilo načíst (${e.message})`;
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

{#if notice}
  <div class="dev-alert dev-alert--{notice.kind}">
    <span class="dev-alert__text">
      {notice.text}
      {#if notice.jobId}
        <a href={router.href('testingJob', { jobId: notice.jobId })} onclick={onLinkClick}>Průběh úlohy {notice.jobId}</a>
      {/if}
    </span>
    <Button label="Zavřít" variant="secondary" size="sm" onclick={() => (notice = null)} />
  </div>
{/if}

{#if !loading && items.length === 0 && !error}
  <div class="dev-start">
    <div class="dev-card dev-start__card">
      <span class="dev-badge dev-badge--free">Začni tady</span>
      <h2 class="dev-start__title">Na tomhle stroji zatím nejsou žádné zdroje dat</h2>
      <p>Zdroj dat je jedna firma s vlastní databází. Nejrychleji začneš zdrojem dat s ukázkovou datovou sadou — dostaneš firmu s doklady, poštou a uživatelem, pod kterým se přihlásíš.</p>
      <ol class="dev-start__steps">
        <li>Založ zdroj dat a v poli Data vyber datovou sadu.</li>
        <li>Počkej, až průběh doběhne.</li>
        <li>Klikni na Otevřít a přihlas se zvoleným uživatelem.</li>
      </ol>
      <div class="dev-btnrow">
        <a href={router.href('dsCreate')} onclick={onLinkClick} class="dev-btn-link">
          <Button label="Nový zdroj dat s ukázkovými daty" icon={iconAdd} />
        </a>
        <a class="dev-btn-outline dev-btn-outline--md" href={DOCS_URL} target="_blank" rel="noopener">Návod pro začátečníky</a>
      </div>
    </div>
    <aside class="dev-start__aside">
      <h3 class="dev-start__aside-title">Co tu ještě najdeš</h3>
      <dl class="dev-start__list">
        <dt><a href={router.href('logs')} onclick={onLinkClick}>Logy</a></dt>
        <dd>Chyby a varování aplikace, filtrované podle zdroje dat.</dd>
        <dt><a href={router.href('doctor')} onclick={onLinkClick}>Doctor</a></dt>
        <dd>Kontrola stroje — práva, služby, konfigurace.</dd>
        <dt><a href={router.href('upgradeAll')} onclick={onLinkClick}>Upgrade všech</a></dt>
        <dd>Po stažení nových změn převede všechny zdroje dat na nové tabulky a nastavení.</dd>
      </dl>
    </aside>
  </div>
{:else}
<div class="dev-table-wrap">
  <table class="dev-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Název</th>
        <th>Třída</th>
        <th>Účel</th>
        <th>Vytvořeno</th>
        <th>Cron</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {#if loading}
        <tr><td colspan="7" class="dev-empty">Načítám…</td></tr>
      {:else}
        {#each items as ds (ds.id)}
          <tr>
            <td class="dev-nowrap">
              <code>{ds.id}</code>
              {#if copiedId === ds.id}
                <span class="dev-small dev-muted copied" role="status">zkopírováno</span>
              {/if}
            </td>
            <td>
              {ds.name}
              {#if ds.job}
                <div class="dev-small">
                  <a href={router.href('testingJob', { jobId: ds.job.id })} onclick={onLinkClick} title="Běžící úloha na zdroji dat">
                    <Icon icon={iconRefresh} size="sm" /> {JOB_TYPE_LABELS[ds.job.type] ?? ds.job.type} · {JOB_STATUS_LABELS[ds.job.status] ?? ds.job.status}
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
                <span class="dev-badge dev-badge--outline" title="Používá serverové AI připojení (dev-ai-key)">AI</span>
              {/if}
            </td>
            <td class="dev-muted purpose" title={ds.purpose ?? ''}>
              {truncate(ds.purpose)}
              {#if heldDataset(ds)}
                <div class="dev-small" title={ds.dataset ? `seedováno ${formatDateTime(ds.dataset.seededAt)}` : 'sada jen z původu (před F6b) — záznam doplní nové nahrání'}>
                  sada: <code>{heldDataset(ds)}</code>{#if !ds.dataset} <span class="dev-muted">(z původu)</span>{/if}
                </div>
              {/if}
            </td>
            <td class="dev-nowrap dev-muted">{ds.created ? formatDateTime(ds.created) : '—'}</td>
            <td class="dev-nowrap">
              {#if ds.cron}
                <span>zapnutý</span>
              {:else}
                <span class="cron-off" title="Periodické úlohy jsou pro tento zdroj dat vypnuté — zapnutí: shpd-ds ds-state cron --on"><Icon icon={iconClock} size="sm" /> vypnutý</span>
              {/if}
              {#if ds.state && ds.state !== 'active'}
                <span class="dev-muted"> · {STATE_LABELS[ds.state] ?? ds.state}</span>
              {/if}
            </td>
            <td class="dev-actions">
              <span class="dev-actions__inner">
                <a class="dev-btn-outline" href={`/${ds.id}/app/`} target="_blank" rel="noopener">Otevřít <Icon icon={iconOpenExternal} size="sm" /></a>
                <DevKebabMenu label={`Další akce: ${ds.name}`} items={menuItems(ds)} />
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
  .dev-btn-link { text-decoration: none; }

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
