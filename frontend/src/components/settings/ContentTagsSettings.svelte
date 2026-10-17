<script>
  // Nastavení → Položky → Obsahové štítky (tasks/content-tag-ui.md D27):
  // (a) přehled taxonomie se stavem mapování (otagované položky / default
  //     účet z nabídky / bez mapování) + akce Založit (D26 endpoint),
  // (b) reverzní otagování — neotagované položky s jednoznačným štítkem
  //     dle účtu v nabídce, checkboxy + hromadné potvrzení (D15); kolizní
  //     účty poctivě bez návrhu,
  // (c) odkaz na setup panel nabídky (hromadné založení výchozích položek).
  //
  // Ručně psaný panel (vzor DsSetup/AccountingItemsOffer) — server-driven
  // settings page tabulku s akcemi neunese.
  import { onMount } from 'svelte';
  import Button from '../ui/Button.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import {
    fetchContentTagsOverview,
    materializeContentTag,
    tagContentItems,
  } from '../../api/contentTags.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';

  let loading = $state(true);
  let loadError = $state(null);
  let overview = $state(null);

  // Právě materializovaný štítek (disabluje jeho tlačítko) + poslední chyba.
  let materializingTag = $state(null);
  let materializeError = $state(null);

  // Reverzní otagování: id → bool (jen položky s jednoznačným návrhem).
  let checked = $state({});
  let tagging = $state(false);
  let taggingError = $state(null);
  let taggingSummary = $state(null); // {updated, failed}

  async function load() {
    loading = true;
    loadError = null;
    try {
      const result = await fetchContentTagsOverview();
      if (result?.success) {
        overview = result.data;
        const preselect = {};
        for (const item of overview?.untagged ?? []) {
          if (item.suggestedTag) preselect[item.id] = true;
        }
        checked = preselect;
      } else {
        loadError = translateError(result?.error);
      }
    } catch (e) {
      loadError = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  const suggestible = $derived((overview?.untagged ?? []).filter((i) => i.suggestedTag));
  const collisions = $derived((overview?.untagged ?? []).filter((i) => !i.suggestedTag));
  const selected = $derived(suggestible.filter((i) => checked[i.id]));

  function stateLabel(tag) {
    if (tag.state === 'mapped') return t('settings.contentTags.state.mapped');
    if (tag.state === 'defaultAccount') {
      return t('settings.contentTags.state.defaultAccount', { account: tag.defaultAccount });
    }
    return t('settings.contentTags.state.unmapped');
  }

  async function handleMaterialize(tag) {
    if (materializingTag !== null) return;
    materializingTag = tag.tag;
    materializeError = null;
    try {
      const result = await materializeContentTag(tag.tag);
      if (result?.success) {
        await load();
      } else {
        materializeError = translateError(result?.error);
      }
    } catch (e) {
      materializeError = e instanceof Error ? e.message : String(e);
    } finally {
      materializingTag = null;
    }
  }

  async function handleTagSelected() {
    if (selected.length === 0 || tagging) return;
    tagging = true;
    taggingError = null;
    taggingSummary = null;
    try {
      const result = await tagContentItems(
        selected.map((i) => ({ id: i.id, tags: [i.suggestedTag] })),
      );
      if (result?.success) {
        taggingSummary = {
          updated: (result.data?.updated ?? []).length,
          failed: (result.data?.failed ?? []).length,
        };
        await load();
      } else {
        taggingError = translateError(result?.error);
      }
    } catch (e) {
      taggingError = e instanceof Error ? e.message : String(e);
    } finally {
      tagging = false;
    }
  }

  function openSetupPanel() {
    navigationStore.navigateToPanel('dsSetup', null);
  }

  onMount(load);
</script>

<div class="shpd-content-tags">
  <h2 class="shpd-content-tags__title">{t('settings.contentTags.title')}</h2>
  <p class="shpd-content-tags__desc">{t('settings.contentTags.description')}</p>

  {#if loading}
    <p class="shpd-content-tags__state">{t('common.loading')}</p>
  {:else if loadError}
    <p class="shpd-content-tags__state shpd-content-tags__state--error">{loadError}</p>
  {:else if overview}
    {#if !overview.available}
      <p class="shpd-content-tags__state shpd-content-tags__state--muted">
        {t('settings.contentTags.unavailable')}
      </p>
    {/if}

    <!-- (a) Přehled taxonomie -->
    <section class="shpd-content-tags__section">
      <table class="shpd-content-tags__table">
        <thead>
          <tr>
            <th>{t('settings.contentTags.col.tag')}</th>
            <th>{t('settings.contentTags.col.state')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {#each overview.tags as tag (tag.tag)}
            <tr>
              <td>
                <span class="shpd-content-tags__label">{tag.label}</span>
                <span class="shpd-content-tags__key">{tag.tag}</span>
              </td>
              <td>
                <span
                  class="shpd-content-tags__chip shpd-content-tags__chip--{tag.state}"
                >{stateLabel(tag)}</span>
                {#if tag.items.length > 0}
                  <span class="shpd-content-tags__items">
                    {tag.items.map((i) => `${i.code} — ${i.name}`).join(', ')}
                  </span>
                {/if}
              </td>
              <td class="shpd-content-tags__action-cell">
                {#if tag.state === 'defaultAccount' && overview.available}
                  <Button
                    label={t('settings.contentTags.action.create')}
                    size="sm"
                    variant="secondary"
                    disabled={materializingTag !== null}
                    loading={materializingTag === tag.tag}
                    onclick={() => handleMaterialize(tag)}
                  />
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
      {#if materializeError}
        <p class="shpd-content-tags__state shpd-content-tags__state--error">{materializeError}</p>
      {/if}
    </section>

    <!-- (b) Reverzní otagování -->
    {#if overview.available}
      <section class="shpd-content-tags__section">
        <h3 class="shpd-content-tags__subtitle">{t('settings.contentTags.untagged.title')}</h3>
        <p class="shpd-content-tags__desc">{t('settings.contentTags.untagged.description')}</p>

        {#if taggingSummary}
          <p class="shpd-content-tags__state" role="status">
            {t('settings.contentTags.untagged.summary', taggingSummary)}
          </p>
        {/if}

        {#if suggestible.length === 0 && collisions.length === 0}
          <p class="shpd-content-tags__state shpd-content-tags__state--muted">
            {t('settings.contentTags.untagged.empty')}
          </p>
        {:else}
          <ul class="shpd-content-tags__list">
            {#each suggestible as item (item.id)}
              <li class="shpd-content-tags__row">
                <Checkbox bind:checked={checked[item.id]} disabled={tagging} />
                <span class="shpd-content-tags__name">{item.code} — {item.name}</span>
                <span class="shpd-content-tags__account">{item.account}</span>
                <span class="shpd-content-tags__suggestion">→ {item.suggestedTagLabel}</span>
              </li>
            {/each}
            {#each collisions as item (item.id)}
              <li class="shpd-content-tags__row shpd-content-tags__row--collision">
                <Checkbox checked={false} disabled={true} />
                <span class="shpd-content-tags__name">{item.code} — {item.name}</span>
                <span class="shpd-content-tags__account">{item.account}</span>
                <span class="shpd-content-tags__suggestion shpd-content-tags__suggestion--muted">
                  {t('settings.contentTags.untagged.collision')}
                </span>
              </li>
            {/each}
          </ul>

          {#if taggingError}
            <p class="shpd-content-tags__state shpd-content-tags__state--error">{taggingError}</p>
          {/if}

          <div class="shpd-content-tags__actions">
            <Button
              label={t('settings.contentTags.untagged.tagSelected', { count: selected.length })}
              size="sm"
              disabled={selected.length === 0 || tagging}
              loading={tagging}
              onclick={handleTagSelected}
            />
          </div>
        {/if}
      </section>
    {/if}

    <!-- (c) Odkaz na setup panel nabídky -->
    <section class="shpd-content-tags__section">
      <button type="button" class="shpd-content-tags__link" onclick={openSetupPanel}>
        {t('settings.contentTags.offerLink')}
      </button>
    </section>
  {/if}
</div>

<style>
  .shpd-content-tags {
    padding: var(--shpd-space-lg);
    max-width: 900px;
  }

  .shpd-content-tags__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
  }

  .shpd-content-tags__subtitle {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
  }

  .shpd-content-tags__desc {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content-tags__section {
    margin-top: var(--shpd-space-lg);
  }

  .shpd-content-tags__state {
    margin-top: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-content-tags__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-content-tags__state--muted {
    font-style: italic;
  }

  .shpd-content-tags__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-content-tags__table th,
  .shpd-content-tags__table td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
    vertical-align: top;
  }

  .shpd-content-tags__table th {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-secondary);
    font-weight: 500;
  }

  .shpd-content-tags__label {
    font-weight: 500;
  }

  .shpd-content-tags__key {
    display: block;
    font-family: var(--shpd-font-mono, monospace);
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content-tags__chip {
    display: inline-block;
    padding: 1px var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs);
    white-space: nowrap;
  }

  .shpd-content-tags__chip--mapped {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
  }

  .shpd-content-tags__chip--defaultAccount {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  .shpd-content-tags__chip--unmapped {
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content-tags__items {
    display: block;
    margin-top: 2px;
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content-tags__action-cell {
    text-align: right;
    white-space: nowrap;
  }

  .shpd-content-tags__list {
    list-style: none;
    margin: var(--shpd-space-sm) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-content-tags__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-content-tags__row--collision {
    opacity: 0.6;
  }

  .shpd-content-tags__name {
    color: var(--shpd-color-text);
  }

  .shpd-content-tags__account {
    font-family: var(--shpd-font-mono, monospace);
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-content-tags__suggestion {
    color: var(--shpd-color-primary);
  }

  .shpd-content-tags__suggestion--muted {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }

  .shpd-content-tags__actions {
    margin-top: var(--shpd-space-md);
  }

  .shpd-content-tags__link {
    background: transparent;
    border: 0;
    padding: 0;
    color: var(--shpd-color-primary);
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    text-decoration: underline;
    cursor: pointer;
  }
</style>
