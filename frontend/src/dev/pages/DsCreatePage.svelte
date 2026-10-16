<script>
  // Nový zdroj dat — port stránky `/_dev/ds-create/`: formulář, klientská
  // validace zrcadlí DevDashboardController::validateDsCreateInput, průběh
  // `ds-create → ds-upgrade → user-create → seed` čtený po kouscích
  // (api.js streamLines), na konci odkaz do nového zdroje dat.
  import { onMount } from 'svelte';
  import Button from '../../components/ui/Button.svelte';
  import { getJson, streamLines, ApiError } from '../api.js';
  import { router, onLinkClick } from '../router.svelte.js';
  import StreamOutput from '../components/StreamOutput.svelte';

  const DEFAULT_MODULE = 'install.base';

  let form = $state({
    name: router.route.query.name ?? '',
    language: 'cs',
    country: 'cz',
    module: DEFAULT_MODULE,
    login: 'admin',
    password: 'admin',
    seed: true,
  });
  let showPassword = $state(false);
  let fieldErrors = $state({});
  let formError = $state(null);

  let modules = $state([]);
  let modulesState = $state('loading'); // loading | ok | empty | error

  let busy = $state(false);
  let lines = $state([]);
  let result = $state(null); // {kind: 'done', url} | {kind: 'error', text} | {kind: 'finished'}

  const moduleHint = $derived(modules.find((m) => m.id === form.module)?.description ?? '');

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
  });

  function validate() {
    const errors = {};
    const name = form.name.trim();
    if (name === '') errors.name = 'Název je povinný.';
    else if (name.length > 200) errors.name = 'Název je příliš dlouhý (max. 200 znaků).';

    const login = form.login.trim();
    if (login === '') errors.login = 'Login administrátora je povinný.';
    else if (!/^[a-zA-Z0-9_]+$/.test(login)) errors.login = 'Login smí obsahovat jen písmena, číslice a podtržítko.';
    else if (login.length > 64) errors.login = 'Login je příliš dlouhý (max. 64 znaků).';

    if (form.password === '') errors.password = 'Heslo administrátora je povinné.';
    else if (form.password.length < 4) errors.password = 'Heslo musí mít aspoň 4 znaky.';

    if (form.module.trim() === '') errors.module = 'Instalační modul je povinný.';
    else if (!/^install\.[a-z][a-zA-Z0-9]*$/.test(form.module)) errors.module = 'Neplatné ID instalačního modulu.';

    if (!['cs', 'en'].includes(form.language)) errors.language = 'Vyber jazyk.';
    if (!/^[a-z]{2}$/.test(form.country)) errors.country = 'Vyber zemi.';

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
          seed: form.seed,
          module: form.module,
          language: form.language,
          country: form.country,
        },
        onLine: (line) => {
          if (line.kind !== 'done') lines = [...lines, line];
        },
      });
      if (summary.error) {
        result = { kind: 'error', text: summary.error.text };
      } else if (summary.done) {
        result = { kind: 'done', url: summary.done.payload?.url ?? null, id: summary.done.payload?.id ?? null };
      } else {
        result = { kind: 'finished' };
      }
    } catch (e) {
      if (e instanceof ApiError) {
        formError = e.message;
      } else {
        result = { kind: 'error', text: `Chyba sítě: ${e.message}` };
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
  <a href={router.href('dataSources')} onclick={onLinkClick}>Zdroje dat</a> ›
  <span>Nový zdroj dat</span>
</div>
<div class="dev-page__head">
  <h1>Nový zdroj dat</h1>
</div>

<div class="dev-card">
  <form class="dev-form" onsubmit={submit} novalidate>
    <div class="dev-field" class:has-error={fieldErrors.name}>
      <label for="ds-name">Název</label>
      <input id="ds-name" type="text" bind:value={form.name} maxlength="200" disabled={busy} autocomplete="off" />
      {#if fieldErrors.name}<div class="dev-field__error">{fieldErrors.name}</div>{/if}
    </div>

    <div class="dev-form__row">
      <div class="dev-field" class:has-error={fieldErrors.language}>
        <label for="ds-language">Výchozí jazyk</label>
        <select id="ds-language" bind:value={form.language} disabled={busy}>
          <option value="cs">čeština (cs)</option>
          <option value="en">angličtina (en)</option>
        </select>
        {#if fieldErrors.language}<div class="dev-field__error">{fieldErrors.language}</div>{/if}
      </div>
      <div class="dev-field" class:has-error={fieldErrors.country}>
        <label for="ds-country">Země</label>
        <select id="ds-country" bind:value={form.country} disabled={busy}>
          <option value="cz">Česko (cz)</option>
          <option value="sk">Slovensko (sk)</option>
        </select>
        {#if fieldErrors.country}<div class="dev-field__error">{fieldErrors.country}</div>{/if}
      </div>
    </div>

    <div class="dev-field" class:has-error={fieldErrors.module}>
      <label for="ds-module">Instalační modul</label>
      <select id="ds-module" bind:value={form.module} disabled={busy || modulesState !== 'ok'}>
        {#if modulesState === 'loading'}
          <option value={form.module}>Načítám…</option>
        {:else if modulesState === 'empty'}
          <option value={form.module}>Žádný instalační modul nenalezen</option>
        {:else if modulesState === 'error'}
          <option value={form.module}>Moduly se nepodařilo načíst</option>
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
        <label for="ds-login">Login administrátora</label>
        <input id="ds-login" type="text" bind:value={form.login} maxlength="64" disabled={busy} autocomplete="off" />
        {#if fieldErrors.login}<div class="dev-field__error">{fieldErrors.login}</div>{/if}
      </div>
      <div class="dev-field" class:has-error={fieldErrors.password}>
        <label for="ds-password">Heslo administrátora</label>
        <div class="dev-input-group">
          <input
            id="ds-password"
            type={showPassword ? 'text' : 'password'}
            bind:value={form.password}
            disabled={busy}
            autocomplete="new-password"
          />
          <Button
            label={showPassword ? 'Skrýt' : 'Zobrazit'}
            variant="secondary"
            size="sm"
            disabled={busy}
            onclick={() => (showPassword = !showPassword)}
          />
        </div>
        {#if fieldErrors.password}<div class="dev-field__error">{fieldErrors.password}</div>{/if}
      </div>
    </div>

    <div class="dev-field dev-field--inline">
      <input id="ds-seed" type="checkbox" bind:checked={form.seed} disabled={busy} />
      <label for="ds-seed">Naseedovat testovací data (osoby a pošta)</label>
    </div>

    {#if formError}
      <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{formError}</span></div>
    {/if}

    <div>
      <Button type="submit" label={busy ? 'Vytvářím…' : 'Vytvořit zdroj dat'} loading={busy} disabled={busy} />
    </div>
  </form>
</div>

{#if lines.length > 0 || busy}
  <div class="dev-card">
    <div class="dev-card__title">Průběh</div>
    <StreamOutput {lines} />
  </div>
{/if}

{#if result?.kind === 'done'}
  <div class="dev-alert dev-alert--success">
    <span class="dev-alert__text">Zdroj dat <code>{result.id}</code> je založený.</span>
    {#if result.url}
      <a href={result.url} target="_blank" rel="noopener">Otevřít zdroj dat →</a>
    {/if}
    <Button label="Vytvořit další" variant="secondary" size="sm" onclick={reset} />
    <a href={router.href('dataSources')} onclick={onLinkClick}>Zpět na dashboard</a>
  </div>
{:else if result?.kind === 'error'}
  <div class="dev-alert dev-alert--danger">
    <span class="dev-alert__text">{result.text}</span>
    <Button label="Zkusit znovu" variant="secondary" size="sm" onclick={reset} />
  </div>
{:else if result?.kind === 'finished'}
  <div class="dev-alert dev-alert--warning">
    <span class="dev-alert__text">Průběh skončil bez závěrečné značky — zkontroluj výstup a seznam zdrojů dat.</span>
    <a href={router.href('dataSources')} onclick={onLinkClick}>Zpět na dashboard</a>
  </div>
{/if}
