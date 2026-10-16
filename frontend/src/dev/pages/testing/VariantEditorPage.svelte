<script>
  // Editor varianty evaluace (F6b D62): název (nová), popis, profil pošty,
  // šablona promptu ze souboru, přepis volání mail.extraction (připojení,
  // model z katalogu platformy serverového připojení, thinking, effort,
  // strop tokenů) a přepis funkce štítků. Nabídky bere z vybraného volného
  // zdroje dat; uloží `tests/eval/variants/<název>.jsonc` přes stejný
  // načítač jako ai-eval, přepsání existující jen s potvrzením.
  import { onMount } from 'svelte';
  import Button from '../../../components/ui/Button.svelte';
  import DevConfirm from '../../components/DevConfirm.svelte';
  import { router, onLinkClick } from '../../router.svelte.js';
  import { testingApi } from '../../testing/api.js';
  import { getJson, ApiError } from '../../api.js';
  import Crumbs from '../../components/Crumbs.svelte';

  const NEW = '_new';
  const route = $derived(router.route);
  const routeName = $derived(route.params.name);
  const isNew = $derived(routeName === NEW);

  let form = $state({
    name: '',
    description: '',
    profile: 'czech_general',
    promptTemplateFile: '',
    call: { connection: '', model: '', thinking: '', effort: '', maxTokens: '' },
    contentTags: { model: '', thinking: '', effort: '' },
  });
  let promptTemplates = $state([]);
  let dataSources = $state([]);
  let optionsDs = $state('');
  let options = $state(null);
  let optionsError = $state(null);
  let loading = $state(true);
  let error = $state(null);
  let saved = $state(null);
  let busy = $state(false);
  let confirmOverwrite = $state(false);

  function fill(v) {
    form = {
      name: v.name,
      description: v.description ?? '',
      profile: v.profile ?? '',
      promptTemplateFile: v.promptTemplateFile ?? '',
      call: {
        connection: v.call?.connection ?? '',
        model: v.call?.model ?? '',
        thinking: v.call?.thinking ?? '',
        effort: v.call?.effort ?? '',
        maxTokens: v.call?.maxTokens ?? '',
      },
      contentTags: { model: v.contentTags?.model ?? '', thinking: v.contentTags?.thinking ?? '', effort: v.contentTags?.effort ?? '' },
    };
  }

  $effect(() => {
    const name = routeName;
    loading = true;
    error = null;
    saved = null;
    let cancelled = false;
    (async () => {
      try {
        if (name === NEW) {
          const list = await testingApi.variants();
          if (!cancelled) {
            promptTemplates = list.promptTemplates;
            fill({ name: '', profile: 'czech_general', call: {}, contentTags: {} });
          }
        } else {
          const d = await testingApi.variant(name);
          if (!cancelled) {
            promptTemplates = d.promptTemplates;
            fill(d.variant);
          }
        }
      } catch (e) {
        if (!cancelled) error = e.message;
      } finally {
        if (!cancelled) loading = false;
      }
    })();
    return () => { cancelled = true; };
  });

  onMount(async () => {
    try {
      const all = await getJson('/data-sources');
      dataSources = all.filter((d) => d.dataClass === 'free');
      const preferred = dataSources.find((d) => d.serverAi) ?? dataSources[0];
      if (preferred) {
        optionsDs = preferred.id;
        await loadOptions();
      }
    } catch (e) {
      optionsError = e.message;
    }
  });

  async function loadOptions() {
    options = null;
    optionsError = null;
    if (!optionsDs) return;
    try {
      options = await testingApi.variantOptions(optionsDs);
    } catch (e) {
      optionsError = e instanceof ApiError ? `${e.message} (${e.code})` : e.message;
    }
  }

  const nameOk = $derived(/^[a-z0-9][a-z0-9-]{0,63}$/.test(form.name));

  function nullable(v) {
    const s = String(v ?? '').trim();
    return s === '' ? null : s;
  }

  function payload(overwrite) {
    const maxTokens = String(form.call.maxTokens ?? '').trim();
    return {
      description: nullable(form.description),
      profile: form.profile.trim(),
      promptTemplateFile: nullable(form.promptTemplateFile),
      call: {
        connection: nullable(form.call.connection),
        model: nullable(form.call.model),
        thinking: nullable(form.call.thinking),
        effort: nullable(form.call.effort),
        maxTokens: maxTokens === '' ? null : Number(maxTokens),
      },
      contentTags: { model: nullable(form.contentTags.model), thinking: nullable(form.contentTags.thinking), effort: nullable(form.contentTags.effort) },
      overwrite,
    };
  }

  async function save(overwrite = false) {
    busy = true;
    error = null;
    saved = null;
    try {
      const d = await testingApi.saveVariant(form.name, payload(overwrite));
      saved = d.variant;
      confirmOverwrite = false;
      if (isNew) router.go('testingVariant', { name: d.variant.name });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'VARIANT_EXISTS') {
        confirmOverwrite = true;
      } else {
        error = e instanceof ApiError ? `${e.message} (${e.code})` : `Chyba sítě: ${e.message}`;
      }
    } finally {
      busy = false;
    }
  }
</script>

<Crumbs items={[{ label: 'Testování', href: router.href('testingDatasets') }, { label: 'Varianty', href: router.href('testingVariants') }, { label: isNew ? 'Nová varianta' : routeName }]} />
<div class="dev-page__head">
  <h1>{isNew ? 'Nová varianta' : `Varianta ${routeName}`}</h1>
  <span class="dev-page__spacer"></span>
  {#if !isNew}<a href={router.href('testingVariant', { name: NEW })} onclick={onLinkClick} class="dev-small">založit další</a>{/if}
</div>

{#if loading}
  <div class="dev-empty">Načítám…</div>
{:else}
  {#if error}
    <div class="dev-alert dev-alert--danger"><span class="dev-alert__text">{error}</span></div>
  {/if}
  {#if saved}
    <div class="dev-alert dev-alert--success"><span class="dev-alert__text">Uloženo: <code>tests/eval/variants/{saved.file}</code> — použitelné v <code>ai-eval --variant</code> i v dialogu evaluace.</span></div>
  {/if}

  <div class="dev-split">
    <div class="dev-card">
      <form class="dev-form" onsubmit={(e) => { e.preventDefault(); save(false); }} novalidate>
        <div class="dev-field" class:has-error={isNew && form.name !== '' && !nameOk}>
          <label for="v-name">Název <span class="dev-muted">(soubor <code>{form.name || '<název>'}.jsonc</code>)</span></label>
          <input id="v-name" type="text" bind:value={form.name} disabled={!isNew || busy} placeholder="např. sonnet-5-5-effort-high" autocomplete="off" />
          {#if isNew && form.name !== '' && !nameOk}<div class="dev-field__error">Jen <code>a-z</code>, <code>0-9</code> a pomlčka, max. 64 znaků.</div>{/if}
        </div>
        <div class="dev-field">
          <label for="v-desc">Popis</label>
          <input id="v-desc" type="text" bind:value={form.description} disabled={busy} autocomplete="off" />
        </div>
        <div class="dev-form__row">
          <div class="dev-field">
            <label for="v-profile">Profil pošty (kód)</label>
            <input id="v-profile" type="text" list="v-profiles" bind:value={form.profile} disabled={busy} autocomplete="off" />
            <datalist id="v-profiles">{#each options?.profiles ?? [] as p (p.code)}<option value={p.code}>{p.name}</option>{/each}</datalist>
          </div>
          <div class="dev-field">
            <label for="v-prompt">Šablona promptu ze souboru <span class="dev-muted">(volitelné)</span></label>
            <select id="v-prompt" bind:value={form.promptTemplateFile} disabled={busy}>
              <option value="">— z profilu ve zdroji dat —</option>
              {#each promptTemplates as f (f)}<option value={f}>{f}</option>{/each}
            </select>
            <div class="dev-field__hint">Soubory mimo <code>.jsonc</code> v <code>tests/eval/variants/</code> a <code>variants/prompts/</code>.</div>
          </div>
        </div>

        <h3 class="dev-card__title">Přepis volání <code>mail.extraction</code></h3>
        <div class="dev-form__row">
          <div class="dev-field">
            <label for="v-conn">Připojení</label>
            <select id="v-conn" bind:value={form.call.connection} disabled={busy}>
              <option value="">— dle zdroje dat —</option>
              {#each options?.connections ?? [] as c (c.code)}<option value={c.code}>{c.name} ({c.code}, {c.platform})</option>{/each}
              {#if form.call.connection && !(options?.connections ?? []).some((c) => c.code === form.call.connection)}<option value={form.call.connection}>{form.call.connection}</option>{/if}
            </select>
          </div>
          <div class="dev-field">
            <label for="v-model">Model (ID z katalogu)</label>
            <input id="v-model" type="text" list="v-models" bind:value={form.call.model} disabled={busy} autocomplete="off" placeholder="dle zdroje dat" />
            <datalist id="v-models">{#each options?.models ?? [] as m (m.id)}<option value={m.id}>{m.name}{m.tier ? ` · ${m.tier}` : ''}{m.status && m.status !== 'active' ? ` · ${m.status}` : ''}</option>{/each}</datalist>
          </div>
        </div>
        <div class="dev-form__row">
          <div class="dev-field">
            <label for="v-think">Thinking</label>
            <input id="v-think" type="text" list="v-thinking" bind:value={form.call.thinking} disabled={busy} autocomplete="off" placeholder="dle deklarace funkce" />
            <datalist id="v-thinking">{#each options?.thinkingModes ?? [] as t (t)}<option value={t}></option>{/each}</datalist>
          </div>
          <div class="dev-field">
            <label for="v-effort">Effort</label>
            <select id="v-effort" bind:value={form.call.effort} disabled={busy}>
              <option value="">— dle deklarace funkce —</option>
              {#each options?.effortLevels ?? ['low', 'medium', 'high', 'xhigh', 'max'] as e (e)}<option value={e}>{e}</option>{/each}
            </select>
          </div>
          <div class="dev-field">
            <label for="v-max">Strop tokenů výstupu</label>
            <input id="v-max" type="number" min="1" step="1" bind:value={form.call.maxTokens} disabled={busy} placeholder="dle deklarace" />
          </div>
        </div>

        <h3 class="dev-card__title">Přepis funkce štítků <code>exchange.contentTags</code></h3>
        <div class="dev-form__row">
          <div class="dev-field">
            <label for="t-model">Model</label>
            <input id="t-model" type="text" list="v-models" bind:value={form.contentTags.model} disabled={busy} autocomplete="off" placeholder="dle zdroje dat" />
          </div>
          <div class="dev-field">
            <label for="t-think">Thinking</label>
            <input id="t-think" type="text" list="v-thinking" bind:value={form.contentTags.thinking} disabled={busy} autocomplete="off" />
          </div>
          <div class="dev-field">
            <label for="t-effort">Effort</label>
            <select id="t-effort" bind:value={form.contentTags.effort} disabled={busy}>
              <option value="">— dle deklarace —</option>
              {#each options?.effortLevels ?? ['low', 'medium', 'high', 'xhigh', 'max'] as e (e)}<option value={e}>{e}</option>{/each}
            </select>
          </div>
        </div>

        <div class="dev-actions">
          <Button label={isNew ? 'Uložit variantu' : 'Uložit změny'} type="submit" size="sm" loading={busy} disabled={busy || (isNew && !nameOk) || form.profile.trim() === ''} onclick={() => save(false)} />
          <span class="dev-small dev-muted">Validace stejná jako v <code>ai-eval</code>; existující soubor se přepíše jen s potvrzením.</span>
        </div>
      </form>
    </div>

    <div class="dev-card">
      <h2 class="dev-card__title">Nabídky</h2>
      <div class="dev-field">
        <label for="v-ds">Zdroj dat pro nabídky (připojení, modely, profily)</label>
        <select id="v-ds" bind:value={optionsDs} onchange={loadOptions} disabled={busy}>
          <option value="">— vyber volný zdroj dat —</option>
          {#each dataSources as d (d.id)}<option value={d.id}>{d.name} ({d.id}){d.serverAi ? ' · AI' : ''}</option>{/each}
        </select>
        {#if optionsError}<div class="dev-field__error">{optionsError}</div>{/if}
        {#if options}
          <div class="dev-field__hint">Platforma serverového připojení: <code>{options.platform ?? '—'}</code>; modelů {options.models.length}, připojení {options.connections.length}, profilů {options.profiles.length}.</div>
        {/if}
      </div>
      <p class="dev-small dev-muted">Model se zapisuje jako ID z katalogu (<code>claude-sonnet-5-5</code>); platformové ID doplní resolver. Prázdné pole = <code>null</code> = jak je nastaveno ve zdroji dat.</p>
    </div>
  </div>
{/if}

<DevConfirm
  open={confirmOverwrite}
  title="Přepsat variantu"
  message={`Varianta ${form.name} už existuje. Přepsat soubor tests/eval/variants/${form.name}.jsonc?`}
  variant="danger"
  confirmLabel="Přepsat"
  cancelLabel="Zpět"
  busy={busy}
  onConfirm={() => save(true)}
  onCancel={() => (confirmOverwrite = false)}
/>
