<script>
  // Seznam variant evaluace (F6b D62): tests/eval/variants/*.jsonc, včetně
  // rozbitých souborů (k opravě v editoru), odkaz na editor a novou variantu.
  import Button from '../../../components/ui/Button.svelte';
  import { iconAdd } from '../../../icons.js';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import Crumbs from '../../components/Crumbs.svelte';
  import { t } from '../../i18n/index.js';

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

<Crumbs items={[{ label: t('app.nav.testing'), href: router.href('testingDatasets') }, { label: t('app.title.variants') }]} />
<div class="dev-page__head">
  <h1>{t('testing.variants.title')}</h1>
  <span class="dev-small dev-muted"><code>{data?.dir ?? 'tests/eval/variants'}</code> {t('testing.variants.subtitle')}</span>
  <span class="dev-page__spacer"></span>
  <Button label={t('testing.variants.new')} icon={iconAdd} size="sm" onclick={() => router.go('testingVariant', { name: '_new' })} />
</div>

{#if error}
  <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
{:else if !data}
  <div class="dev-empty">{t('common.loading')}</div>
{:else}
  <div class="dev-table-wrap">
    <table class="dev-table">
      <thead><tr><th>{t('testing.col.variant')}</th><th>{t('testing.variants.col.description')}</th><th>{t('testing.col.profile')}</th><th>{t('testing.variants.col.extractionModel')}</th><th>{t('testing.variants.col.tagsModel')}</th><th>Prompt</th><th>{t('testing.col.state')}</th></tr></thead>
      <tbody>
        {#each data.variants as v (v.name)}
          <tr class:is-dim={!v.valid}>
            <td><a href={router.href('testingVariant', { name: v.name })} onclick={onLinkClick}><code>{v.name}</code></a>{#if v.valid && v.declaredName !== v.name} <span class="dev-small dev-muted">(name: {v.declaredName})</span>{/if}</td>
            <td>{v.description ?? '—'}</td>
            <td>{v.profile ?? '—'}</td>
            <td><code>{v.call?.model ?? t('testing.variants.perDataSource')}</code>{#if v.call?.connection} <span class="dev-small dev-muted">@{v.call.connection}</span>{/if}</td>
            <td><code>{v.contentTags?.model ?? t('testing.variants.perDataSource')}</code></td>
            <td>{v.promptTemplateFile ?? '—'}</td>
            <td>{#if v.valid}<span class="dev-state dev-state--match">{t('testing.variants.valid')}</span>{:else}<span class="dev-state dev-state--wrong" title={v.error}>{t('testing.variants.error')}</span>{/if}</td>
          </tr>
        {:else}
          <tr><td colspan="7" class="dev-empty">{t('testing.variants.empty')}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}
