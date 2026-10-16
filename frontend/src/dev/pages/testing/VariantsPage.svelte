<script>
  // Seznam variant evaluace (F6b D62): tests/eval/variants/*.jsonc, včetně
  // rozbitých souborů (k opravě v editoru), odkaz na editor a novou variantu.
  import Button from '../../../components/ui/Button.svelte';
  import { iconAdd } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import Crumbs from '../../components/Crumbs.svelte';

  let data = $state(null);
  let error = $state(null);

  $effect(() => {
    let cancelled = false;
    testingApi.variants()
      .then((d) => { if (!cancelled) data = d; })
      .catch((e) => { if (!cancelled) error = e.message; });
    return () => { cancelled = true; };
  });
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: 'Varianty' }]} />
<div class="dev-page__head">
  <h1>Varianty evaluace</h1>
  <span class="dev-small dev-muted"><code>{data?.dir ?? 'tests/eval/variants'}</code> v pracovním stromu — commit dělá člověk</span>
  <span class="dev-page__spacer"></span>
  <Button label="Nová varianta" icon={iconAdd} size="sm" onclick={() => router.go('testingVariant', { name: '_new' })} />
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">Načítám…</div>
{:else}
  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead><tr><th>Varianta</th><th>Popis</th><th>Profil</th><th>Model extrakce</th><th>Model štítků</th><th>Prompt</th><th>Stav</th></tr></thead>
      <tbody>
        {#each data.variants as v (v.name)}
          <tr class:is-dim={!v.valid}>
            <td><a href={router.href('testingVariant', { name: v.name })} onclick={onLinkClick}><code>{v.name}</code></a>{#if v.valid && v.declaredName !== v.name} <span class="dev-small dev-muted">(name: {v.declaredName})</span>{/if}</td>
            <td>{v.description ?? '—'}</td>
            <td>{v.profile ?? '—'}</td>
            <td><code>{v.call?.model ?? 'dle zdroje dat'}</code>{#if v.call?.connection} <span class="dev-small dev-muted">@{v.call.connection}</span>{/if}</td>
            <td><code>{v.contentTags?.model ?? 'dle zdroje dat'}</code></td>
            <td>{v.promptTemplateFile ?? '—'}</td>
            <td>{#if v.valid}<span class="dev-state dev-state--match">platná</span>{:else}<span class="dev-state dev-state--wrong" title={v.error}>chyba</span>{/if}</td>
          </tr>
        {:else}
          <tr><td colspan="7" class="dev-empty">Žádná varianta.</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
