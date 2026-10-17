<script>
  // Nový zdroj dat — port stránky `/_dev/ds-create/`: formulář, klientská
  // validace zrcadlí DevDashboardController::dsCreate, průběh
  // `ds-create → ds-upgrade → user-create → dataset-seed` čtený po kouscích
  // (api.js streamLines), na konci odkaz do nového zdroje dat. Pole Data
  // (#103 D64) nabízí sady z tests/datasets — jen když server sekci
  // Testování nabízí (`/_dev/api/info` → testing); se sadou se země zamkne
  // na zemi sady a prázdný účel se předvyplní. Heslo admina jde na server
  // v těle požadavku a dál na stdin `user-create` (D65), nikdy do argv.
  import { onMount } from 'svelte';
  import Button from '../../components/ui/Button.svelte';
  import { getJson, streamLines, ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import { devInfo } from '../info.svelte.js';
  import { testingApi } from '../testing/api.js';
  import StreamOutput from '../components/StreamOutput.svelte';
  import { t } from '../i18n/index.js';

  const DEFAULT_MODULE = 'install.base';
  const COUNTRIES = [
    { code: 'cz', label: t('dsCreate.country.cz') },
    { code: 'sk', label: t('dsCreate.country.sk') },
  ];

  let form = $state({
    name: router.route.query.name ?? '',
    language: 'cs',
    country: 'cz',
    module: DEFAULT_MODULE,
    login: 'admin',
    password: 'admin',
    dataset: '',
    purpose: '',
  });
  let showPassword = $state(false);
  let fieldErrors = $state({});
  let formError = $state(null);

  let modules = $state([]);
  let modulesState = $state('loading'); // loading | ok | empty | error

  let datasets = $state([]);
  let datasetsState = $state('hidden'); // hidden | loading | ok | empty | error
  /** Účel doplněný automaticky podle sady — přepisuje se jen, dokud ho uživatel nezměnil. */
  let autoPurpose = $state('');

  let busy = $state(false);
  let lines = $state([]);
  let result = $state(null); // {kind: 'done', url, id, dataset} | {kind: 'error', text} | {kind: 'finished'}

  const moduleHint = $derived(modules.find((m) => m.id === form.module)?.description ?? '');
  const selectedDataset = $derived(datasets.find((d) => d.name === form.dataset) ?? null);
  /** Země zamčená podle sady (sada bez země zemi nechává volnou). */
  const countryLocked = $derived(Boolean(selectedDataset?.country));
  const countries = $derived(
    selectedDataset?.country && !COUNTRIES.some((c) => c.code === selectedDataset.country)
      ? [...COUNTRIES, { code: selectedDataset.country, label: selectedDataset.country }]
      : COUNTRIES,
  );

  onMount(async () => {
    try {
      modules = await getJson('/install-modules');
      modulesState = modules.length === 0 ? 'empty' : 'ok';
      if (!modules.some((m) => m.id === form.module) && modules.length > 0) {
        form.module = modules[0].id;
      }
    } catch {
      modulesState = 'error';
    }
    const info = await devInfo.load();
    if (info?.testing) {
      datasetsState = 'loading';
      try {
        datasets = await testingApi.datasets();
        datasetsState = datasets.length === 0 ? 'empty' : 'ok';
      } catch {
        datasetsState = 'error';
      }
    }
  });

  function datasetLabel(d) {
    return `${d.title} — ${d.name} · ${d.country ?? t('dsLoad.noCountry')} · ${d.profile ?? t('dsLoad.noProfile')}`;
  }

  /** Výběr sady: země podle sady, prázdný (nebo dřív automatický) účel = „datová sada <název>“. */
  function onDatasetChange() {
    const d = selectedDataset;
    if (d?.country) form.country = d.country;
    const purposeUntouched = form.purpose.trim() === '' || form.purpose === autoPurpose;
    if (d && purposeUntouched) {
      autoPurpose = t('dsCreate.autoPurpose', { name: d.name });
      form.purpose = autoPurpose;
    } else if (!d && purposeUntouched) {
      form.purpose = '';
      autoPurpose = '';
    }
  }

  function validate() {
    const errors = {};
    const name = form.name.trim();
    if (name === '') errors.name = t('dsCreate.err.nameRequired');
    else if (name.length > 200) errors.name = t('dsCreate.err.nameTooLong');

    const login = form.login.trim();
    if (login === '') errors.login = t('dsCreate.err.loginRequired');
    else if (!/^[a-zA-Z0-9_]+$/.test(login)) errors.login = t('dsCreate.err.loginChars');
    else if (login.length > 64) errors.login = t('dsCreate.err.loginTooLong');

    if (form.password === '') errors.password = t('dsCreate.err.passwordRequired');
    else if (form.password.length < 4) errors.password = t('dsCreate.err.passwordShort');

    if (form.module.trim() === '') errors.module = t('dsCreate.err.moduleRequired');
    else if (!/^install\.[a-z][a-zA-Z0-9]*$/.test(form.module)) errors.module = t('dsCreate.err.moduleInvalid');

    if (!['cs', 'en'].includes(form.language)) errors.language = t('dsCreate.err.languageRequired');
    if (!/^[a-z]{2}$/.test(form.country)) errors.country = t('dsCreate.err.countryRequired');

    // Sada jen ze seznamu; země musí sedět se zemí sady (server kontroluje znovu, dataset-seed potřetí).
    if (form.dataset !== '') {
      if (!selectedDataset) errors.dataset = t('dsCreate.err.datasetInvalid');
      else if (selectedDataset.country && selectedDataset.country !== form.country) {
        errors.country = t('dsCreate.err.datasetCountry', { name: selectedDataset.name, country: selectedDataset.country });
      }
    }

    // Účel (#126 D6) — zrcadlí MainConfigPatcher::normalizePurpose: jeden řádek, max. 200 znaků.
    const purpose = form.purpose.trim();
    if (/[\p{Cc}]/u.test(purpose)) errors.purpose = t('dsCreate.err.purposeOneLine');
    else if (purpose.length > 200) errors.purpose = t('dsCreate.err.purposeTooLong');

    fieldErrors = errors;
    return Object.keys(errors).length === 0;
  }

  async function submit(event) {
    event.preventDefault();
    formError = null;
    result = null;
    if (!validate()) return;

    busy = true;
    lines = [];
    try {
      const summary = await streamLines('/ds-create', {
        body: {
          name: form.name.trim(),
          login: form.login.trim(),
          password: form.password,
          dataset: form.dataset || null,
          module: form.module,
          language: form.language,
          country: form.country,
          purpose: form.purpose.trim(),
        },
        onLine: (line) => {
          if (line.kind !== 'done') lines = [...lines, line];
        },
      });
      if (summary.error) {
        result = { kind: 'error', text: summary.error.text };
      } else if (summary.done) {
        result = {
          kind: 'done',
          url: summary.done.payload?.url ?? null,
          id: summary.done.payload?.id ?? null,
          dataset: summary.done.payload?.dataset ?? null,
        };
      } else {
        result = { kind: 'finished' };
      }
    } catch (e) {
      if (e instanceof ApiError) {
        formError = e.message;
      } else {
        result = { kind: 'error', text: t('common.networkError', { message: e.message }) };
      }
    } finally {
      busy = false;
    }
  }

  function reset() {
    lines = [];
    result = null;
    formError = null;
    fieldErrors = {};
  }
</script>

<div class="dev-breadcrumb">
  <a href={router.href('dataSources')} onclick={onLinkClick}>{t('app.nav.dataSources')}</a> ›
  <span>{t('ds.new')}</span>
</div>
<div class="dev-page__head">
  <h1>{t('ds.new')}</h1>
</div>

<div class="dev-card">
  <form class="dev-form" onsubmit={submit} novalidate>
    <div class="dev-field" class:has-error={fieldErrors.name}>
      <label for="ds-name">{t('dsCreate.name')}</label>
      <input id="ds-name" type="text" bind:value={form.name} maxlength="200" disabled={busy} autocomplete="off" />
      {#if fieldErrors.name}<div class="dev-field__error">{fieldErrors.name}</div>{/if}
    </div>

    <div class="dev-field" class:has-error={fieldErrors.purpose}>
      <label for="ds-purpose">{t('dsCreate.purpose')} <span class="dev-muted">{t('common.optional')}</span></label>
      <input id="ds-purpose" type="text" bind:value={form.purpose} maxlength="200" disabled={busy} autocomplete="off"
        placeholder={t('dsCreate.purposePlaceholder')} />
      {#if fieldErrors.purpose}
        <div class="dev-field__error">{fieldErrors.purpose}</div>
      {:else}
        <div class="dev-field__hint">{t('dsCreate.purposeHint')}</div>
      {/if}
    </div>

    <div class="dev-form__row">
      <div class="dev-field" class:has-error={fieldErrors.language}>
        <label for="ds-language">{t('dsCreate.language')}</label>
        <select id="ds-language" bind:value={form.language} disabled={busy}>
          <option value="cs">{t('dsCreate.lang.cs')}</option>
          <option value="en">{t('dsCreate.lang.en')}</option>
        </select>
        {#if fieldErrors.language}<div class="dev-field__error">{fieldErrors.language}</div>{/if}
      </div>
      <div class="dev-field" class:has-error={fieldErrors.country}>
        <label for="ds-country">{t('dsCreate.countryLabel')}</label>
        <select id="ds-country" bind:value={form.country} disabled={busy || countryLocked}>
          {#each countries as c (c.code)}
            <option value={c.code}>{c.label}</option>
          {/each}
        </select>
        {#if fieldErrors.country}
          <div class="dev-field__error">{fieldErrors.country}</div>
        {:else if countryLocked}
          <div class="dev-field__hint">{t('dsCreate.countryFromDataset', { name: selectedDataset.name })}</div>
        {/if}
      </div>
    </div>

    {#if datasetsState !== 'hidden'}
      <div class="dev-field" class:has-error={fieldErrors.dataset}>
        <label for="ds-dataset">{t('dsCreate.data')}</label>
        <select id="ds-dataset" bind:value={form.dataset} onchange={onDatasetChange} disabled={busy || datasetsState !== 'ok'}>
          <option value="">{t('dsCreate.noDataset')}</option>
          {#if datasetsState === 'loading'}
            <option value="__loading" disabled>{t('dsLoad.loading')}</option>
          {:else if datasetsState === 'error'}
            <option value="__error" disabled>{t('dsCreate.datasetsError')}</option>
          {:else}
            {#each datasets as d (d.name)}
              <option value={d.name}>{datasetLabel(d)}</option>
            {/each}
          {/if}
        </select>
        {#if fieldErrors.dataset}
          <div class="dev-field__error">{fieldErrors.dataset}</div>
        {:else}
          <div class="dev-field__hint">{t('dsCreate.datasetHint')}</div>
        {/if}
      </div>
    {/if}

    <div class="dev-field" class:has-error={fieldErrors.module}>
      <label for="ds-module">{t('dsCreate.module')}</label>
      <select id="ds-module" bind:value={form.module} disabled={busy || modulesState !== 'ok'}>
        {#if modulesState === 'loading'}
          <option value={form.module}>{t('common.loading')}</option>
        {:else if modulesState === 'empty'}
          <option value={form.module}>{t('dsCreate.noModules')}</option>
        {:else if modulesState === 'error'}
          <option value={form.module}>{t('dsCreate.modulesError')}</option>
        {:else}
          {#each modules as m (m.id)}
            <option value={m.id}>{m.name} ({m.id})</option>
          {/each}
        {/if}
      </select>
      {#if fieldErrors.module}
        <div class="dev-field__error">{fieldErrors.module}</div>
      {:else if moduleHint}
        <div class="dev-field__hint">{moduleHint}</div>
      {/if}
    </div>

    <div class="dev-form__row">
      <div class="dev-field" class:has-error={fieldErrors.login}>
        <label for="ds-login">{t('dsCreate.login')}</label>
        <input id="ds-login" type="text" bind:value={form.login} maxlength="64" disabled={busy} autocomplete="off" />
        {#if fieldErrors.login}<div class="dev-field__error">{fieldErrors.login}</div>{/if}
      </div>
      <div class="dev-field" class:has-error={fieldErrors.password}>
        <label for="ds-password">{t('dsCreate.password')}</label>
        <div class="dev-input-group">
          <input
            id="ds-password"
            type={showPassword ? 'text' : 'password'}
            bind:value={form.password}
            disabled={busy}
            autocomplete="new-password"
          />
          <Button
            label={showPassword ? t('dsCreate.hide') : t('dsCreate.show')}
            variant="secondary"
            size="sm"
            disabled={busy}
            onclick={() => (showPassword = !showPassword)}
          />
        </div>
        {#if fieldErrors.password}<div class="dev-field__error">{fieldErrors.password}</div>{/if}
      </div>
    </div>

    {#if formError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{formError}</span></div>
    {/if}

    <div>
      <Button type="submit" label={busy ? t('dsCreate.creating') : t('dsCreate.create')} loading={busy} disabled={busy} />
    </div>
  </form>
</div>

{#if lines.length > 0 || busy}
  <div class="dev-card">
    <div class="dev-card__title">{t('common.progress')}</div>
    <StreamOutput {lines} />
  </div>
{/if}

{#if result?.kind === 'done'}
  <div class="dev-alert dev-alert--success">
    <span class="dev-alert__text">
      {result.dataset ? t('dsCreate.doneWithDataset', { id: result.id, dataset: result.dataset }) : t('dsCreate.done', { id: result.id })}
    </span>
    {#if result.url}
      <a href={result.url} target="_blank" rel="noopener">{t('dsCreate.openDataSource')}</a>
    {/if}
    <Button label={t('dsCreate.createAnother')} variant="secondary" size="sm" onclick={reset} />
    <a href={router.href('dataSources')} onclick={onLinkClick}>{t('common.backToDashboard')}</a>
  </div>
{:else if result?.kind === 'error'}
  <div class="dev-alert dev-alert--danger">
    <span class="dev-alert__text">{result.text}</span>
    <Button label={t('common.retry')} variant="secondary" size="sm" onclick={reset} />
  </div>
{:else if result?.kind === 'finished'}
  <div class="dev-alert dev-alert--warning">
    <span class="dev-alert__text">{t('dsCreate.finishedWithoutMarker')}</span>
    <a href={router.href('dataSources')} onclick={onLinkClick}>{t('common.backToDashboard')}</a>
  </div>
{/if}
