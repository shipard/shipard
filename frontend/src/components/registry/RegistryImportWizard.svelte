<script>
  // Wizard "Přidat firmu z registru".
  //
  // Two screens hosted in a single Modal:
  //   1. search — debounced typeahead against /persons/registry
  //   2. preview — static summary of the canonical, with Save committing
  //                via /_exchange/persons/person/apply
  //
  // The preview screen intentionally renders a hand-built summary instead
  // of the full exchange preview pipeline — registry data is authoritative
  // and the user just needs a quick "yes, that's the company" check before
  // commit. Heavier _resolve rendering would be over-engineering here.
  //
  // existsInDb gates selection on the search screen so we never reach
  // apply with a duplicate (the applier would reject anyway, but the
  // upfront gate makes the UX explicit).
  //
  // asOwn: režim „vlastní firma" pro panel dsSetup (docs/ds-setup.md §5.4).
  // Jediné odchylky: status.isOwn = true v payloadu a jiný titulek + intro.
  // Merge politika i existsInDb gate platí beze změny — vlastní Osoba se
  // importuje jen když v DB žádná není.

  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconCompany, iconUser, iconSpinner } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import {
    searchRegistry,
    fetchRegistryPerson,
    previewRegistryPerson,
    applyRegistryPerson,
  } from '../../api/personsRegistry.js';

  let {
    open = false,
    asOwn = false,
    initialQuery = '',
    onClose = () => {},
    onSaved = (_personId) => {},
  } = $props();

  // Jediné místo, kde se ke kanonickému payloadu přidává merge politika
  // (a v asOwn režimu příznak vlastní osoby). Preview i apply ji skládají
  // odtud — dvě rozjetá místa by byla přesně ta chyba, na kterou upozorňuje
  // tasks/ds-setup-08-registry-import.md.
  function withApplyPolicy(c) {
    const out = {
      ...c,
      applyOptions: { mergeStrategy: 'createOnly', targetDocState: 40 },
    };
    if (asOwn) {
      out.status = { ...(out.status ?? {}), isOwn: true };
    }
    return out;
  }

  // ── Screen + transient state ──────────────────────────────────────────
  let screen = $state('search'); // 'search' | 'preview'

  // Search
  let query = $state('');
  let searchResults = $state([]);
  let searchLoading = $state(false);
  let searchError = $state(null);
  let selectedKey = $state(null); // `${country}:${companyId}` for stable highlight
  let inputEl = $state(null);

  // Preview
  let canonical = $state(null);
  let previewLoading = $state(false);
  let previewError = $state(null);
  let applying = $state(false);
  let applyError = $state(null);

  // Debounce + request token (discard out-of-order responses).
  let searchTimer = null;
  let requestToken = 0;

  // ── Lifecycle ─────────────────────────────────────────────────────────
  $effect(() => {
    if (open) {
      resetAll();
      // Prefill z review modalu (fallback quick-addu, Issue #28): hledání
      // spustit rovnou, debounce se týká jen psaní. Až po resetAll() —
      // ten query maže. runSearch si token inkrementuje sám, searchLoading
      // shodí ve finally.
      const prefill = initialQuery.trim();
      if (prefill !== '') {
        query = initialQuery;
        searchLoading = true;
        void runSearch(prefill);
      }
      // Autofocus the input. The Modal is mounted synchronously, but the
      // input bind happens on the same tick — defer with microtask.
      queueMicrotask(() => inputEl?.focus());
    } else {
      // Cancel any pending debounce when the modal closes.
      if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
      }
    }
  });

  function resetAll() {
    screen = 'search';
    query = '';
    searchResults = [];
    searchLoading = false;
    searchError = null;
    selectedKey = null;
    canonical = null;
    previewLoading = false;
    previewError = null;
    applying = false;
    applyError = null;
  }

  // ── Search (debounced) ────────────────────────────────────────────────
  function handleInput(event) {
    query = event.target.value;
    selectedKey = null;
    searchError = null;

    if (searchTimer) clearTimeout(searchTimer);
    const trimmed = query.trim();
    if (trimmed === '') {
      searchResults = [];
      searchLoading = false;
      return;
    }
    searchLoading = true;
    searchTimer = setTimeout(() => {
      void runSearch(trimmed);
    }, 300);
  }

  async function runSearch(q) {
    const token = ++requestToken;
    try {
      const result = await searchRegistry(q);
      if (token !== requestToken) return; // superseded
      if (result?.success) {
        searchResults = result.data?.results ?? [];
        searchError = null;
      } else {
        searchResults = [];
        searchError = t('registry.wizard.search.error', { msg: translateError(result?.error) });
      }
    } catch (e) {
      if (token !== requestToken) return;
      searchResults = [];
      searchError = t('registry.wizard.search.error', { msg: e instanceof Error ? e.message : String(e) });
    } finally {
      if (token === requestToken) {
        searchLoading = false;
      }
    }
  }

  function rowKey(row) {
    return `${row.country}:${row.companyId}`;
  }

  function handleRowClick(row) {
    if (row.existsInDb) return;
    selectedKey = rowKey(row);
  }

  let selectedRow = $derived(
    selectedKey === null
      ? null
      : searchResults.find(r => rowKey(r) === selectedKey) ?? null,
  );

  let canContinue = $derived(
    selectedRow !== null && !selectedRow.existsInDb && !previewLoading,
  );

  // ── Continue → preview ────────────────────────────────────────────────
  async function handleContinue() {
    if (!canContinue || selectedRow === null) return;
    previewLoading = true;
    previewError = null;
    canonical = null;

    const row = selectedRow;
    try {
      const fetched = await fetchRegistryPerson(row.country, row.companyId);
      if (!fetched?.success) {
        previewError = t('registry.wizard.preview.error', { msg: translateError(fetched?.error) });
        return;
      }
      // Inject merge policy (and asOwn flag) before preview so apply sees
      // the same payload shape.
      const c = withApplyPolicy(fetched.data);

      const previewed = await previewRegistryPerson(c);
      if (!previewed?.success) {
        previewError = t('registry.wizard.preview.error', { msg: translateError(previewed?.error) });
        return;
      }
      // Preview returns the enriched canonical (with _resolve). Carry the
      // applyOptions we set above — preview may strip them depending on
      // applier behaviour, but the apply call below reattaches them.
      canonical = previewed.data?.canonical ?? c;
      screen = 'preview';
    } catch (e) {
      previewError = t('registry.wizard.preview.error', {
        msg: e instanceof Error ? e.message : String(e),
      });
    } finally {
      previewLoading = false;
    }
  }

  // ── Preview screen helpers ────────────────────────────────────────────
  function primaryAddress(c) {
    const list = c?.addresses;
    if (!Array.isArray(list) || list.length === 0) return null;
    // Sídlo (1) preferred; otherwise first entry.
    return list.find(a => a?.addressType === 1) ?? list[0];
  }

  function addressLine(addr) {
    if (!addr) return null;
    if (addr.displayLine && addr.displayLine.trim() !== '') return addr.displayLine;
    const parts = [];
    const street = [addr.street, [addr.houseNumber, addr.orientationNumber].filter(Boolean).join('/')]
      .filter(Boolean)
      .join(' ');
    if (street !== '') parts.push(street);
    const cityZip = [addr.zip, addr.city].filter(Boolean).join(' ');
    if (cityZip !== '') parts.push(cityZip);
    return parts.length > 0 ? parts.join(', ') : null;
  }

  function backToSearch() {
    screen = 'search';
    canonical = null;
    previewError = null;
    applyError = null;
  }

  // ── Apply ─────────────────────────────────────────────────────────────
  async function handleSave() {
    if (!canonical || applying) return;
    applying = true;
    applyError = null;
    try {
      const result = await applyRegistryPerson(withApplyPolicy(canonical));
      if (!result?.success) {
        applyError = t('registry.wizard.preview.applyError', { msg: translateError(result?.error) });
        return;
      }
      const personId = result.data?.savedPersonId ?? null;
      onSaved(personId);
      onClose();
    } catch (e) {
      applyError = t('registry.wizard.preview.applyError', {
        msg: e instanceof Error ? e.message : String(e),
      });
    } finally {
      applying = false;
    }
  }

  // ── Counts for preview footer pills ──────────────────────────────────
  let addressCount = $derived(Array.isArray(canonical?.addresses) ? canonical.addresses.length : 0);
  let bankCount = $derived(Array.isArray(canonical?.bankAccounts) ? canonical.bankAccounts.length : 0);
  let contactCount = $derived(Array.isArray(canonical?.contacts) ? canonical.contacts.length : 0);

  let displayName = $derived(canonical?.name?.fullName ?? selectedRow?.fullName ?? '');
</script>

<Modal title={t(asOwn ? 'registry.wizard.titleOwn' : 'registry.wizard.title')} {open} {onClose} width="full" flush>
  {#if screen === 'search'}
    {#if asOwn}
      <p class="shpd-registry-wizard__own-intro">{t('registry.wizard.ownIntro')}</p>
    {/if}
    <div class="shpd-registry-wizard__search-input-wrap">
      <input
        bind:this={inputEl}
        class="shpd-registry-wizard__search-input"
        type="text"
        value={query}
        oninput={handleInput}
        placeholder={t('registry.wizard.search.placeholder')}
        autocomplete="off"
        spellcheck="false"
      />
    </div>

    <div class="shpd-registry-wizard__results">
      {#if searchError}
        <div class="shpd-registry-wizard__status shpd-registry-wizard__status--error">
          {searchError}
        </div>
      {:else if searchLoading && searchResults.length === 0}
        <div class="shpd-registry-wizard__status">
          <Icon icon={iconSpinner} spin />
          <span>{t('registry.wizard.search.loading')}</span>
        </div>
      {:else if query.trim() === ''}
        <div class="shpd-registry-wizard__status shpd-registry-wizard__status--muted">
          {t('registry.wizard.search.empty')}
        </div>
      {:else if searchResults.length === 0}
        <div class="shpd-registry-wizard__status shpd-registry-wizard__status--muted">
          {t('registry.wizard.search.noResults')}
        </div>
      {:else}
        <ul class="shpd-registry-wizard__list">
          {#each searchResults as row, i (rowKey(row))}
            {@const key = rowKey(row)}
            {@const isSelected = selectedKey === key}
            {@const isDisabled = row.existsInDb}
            <li class="shpd-registry-wizard__list-item">
              <button
                type="button"
                class="shpd-registry-wizard__row"
                class:shpd-registry-wizard__row--selected={isSelected}
                class:shpd-registry-wizard__row--disabled={isDisabled}
                onclick={() => handleRowClick(row)}
                disabled={isDisabled}
                aria-pressed={isSelected}
              >
                <span class="shpd-registry-wizard__row-index">{i + 1}.</span>
                <div class="shpd-registry-wizard__row-body">
                  <div class="shpd-registry-wizard__row-name">{row.fullName}</div>
                  {#if row.primaryAddressText}
                    <div class="shpd-registry-wizard__row-address">
                      <Icon icon={iconCompany} size="sm" />
                      <span>{row.primaryAddressText}</span>
                    </div>
                  {/if}
                  <div class="shpd-registry-wizard__row-ids">
                    <span class="shpd-registry-wizard__mono">IČO {row.companyId}</span>
                    {#if row.vatId}
                      <span class="shpd-registry-wizard__mono">DIČ {row.vatId}</span>
                    {/if}
                  </div>
                </div>
                <div class="shpd-registry-wizard__row-badges">
                  {#if row.existsInDb}
                    <span class="shpd-registry-wizard__badge shpd-registry-wizard__badge--warning">
                      {t('registry.wizard.badge.existsInDb')}
                    </span>
                  {/if}
                  {#if !row.isValid && row.validTo}
                    <span class="shpd-registry-wizard__badge shpd-registry-wizard__badge--muted">
                      {t('registry.wizard.badge.terminated', { date: row.validTo })}
                    </span>
                  {/if}
                </div>
              </button>
            </li>
          {/each}
        </ul>
      {/if}
    </div>
  {:else if screen === 'preview'}
    {#if previewError}
      <div class="shpd-registry-wizard__status shpd-registry-wizard__status--error">
        {previewError}
      </div>
    {:else if canonical}
      {@const addr = primaryAddress(canonical)}
      {@const line = addressLine(addr)}
      <div class="shpd-registry-wizard__preview">
        <section class="shpd-registry-wizard__card">
          <h3 class="shpd-registry-wizard__card-title">
            <Icon icon={canonical.personType === 'person' ? iconUser : iconCompany} />
            <span>{displayName || '—'}</span>
          </h3>
          <dl class="shpd-registry-wizard__props">
            <div class="shpd-registry-wizard__prop">
              <dt>{t('registry.wizard.preview.field.companyId')}</dt>
              <dd class="shpd-registry-wizard__mono">{canonical.companyId ?? '—'}</dd>
            </div>
            {#if canonical.taxId}
              <div class="shpd-registry-wizard__prop">
                <dt>{t('registry.wizard.preview.field.taxId')}</dt>
                <dd class="shpd-registry-wizard__mono">{canonical.taxId}</dd>
              </div>
            {/if}
            {#if canonical.vatId}
              <div class="shpd-registry-wizard__prop">
                <dt>{t('registry.wizard.preview.field.vatId')}</dt>
                <dd class="shpd-registry-wizard__mono">{canonical.vatId}</dd>
              </div>
            {/if}
          </dl>
        </section>

        {#if addr || line}
          <section class="shpd-registry-wizard__card">
            <h3 class="shpd-registry-wizard__card-title">
              {t('registry.wizard.preview.section.address')}
            </h3>
            <div class="shpd-registry-wizard__address-line">{line ?? '—'}</div>
          </section>
        {/if}

        <section class="shpd-registry-wizard__card">
          <h3 class="shpd-registry-wizard__card-title">
            {t('registry.wizard.preview.section.collections')}
          </h3>
          <div class="shpd-registry-wizard__pills">
            <span class="shpd-registry-wizard__pill">
              {t('registry.wizard.preview.counts.addresses', { count: addressCount })}
            </span>
            <span class="shpd-registry-wizard__pill">
              {t('registry.wizard.preview.counts.bankAccounts', { count: bankCount })}
            </span>
            <span class="shpd-registry-wizard__pill">
              {t('registry.wizard.preview.counts.contacts', { count: contactCount })}
            </span>
          </div>
        </section>

        {#if applyError}
          <div class="shpd-registry-wizard__status shpd-registry-wizard__status--error">
            {applyError}
          </div>
        {/if}
      </div>
    {/if}
  {/if}

  {#snippet footer()}
    {#if screen === 'search'}
      <Button
        label={t('registry.wizard.actions.cancel')}
        variant="secondary"
        onclick={onClose}
      />
      <Button
        label={previewLoading ? t('registry.wizard.preview.loading') : t('registry.wizard.actions.next')}
        variant="primary"
        disabled={!canContinue}
        loading={previewLoading}
        onclick={handleContinue}
      />
    {:else}
      <Button
        label={t('registry.wizard.actions.back')}
        variant="secondary"
        disabled={applying}
        onclick={backToSearch}
      />
      <Button
        label={t('registry.wizard.actions.save')}
        variant="primary"
        disabled={!canonical || applying}
        loading={applying}
        onclick={handleSave}
      />
    {/if}
  {/snippet}
</Modal>

<style>
  .shpd-registry-wizard__own-intro {
    margin: 0;
    padding: var(--shpd-space-md) var(--shpd-space-lg) 0;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    flex-shrink: 0;
  }

  .shpd-registry-wizard__search-input-wrap {
    padding: var(--shpd-space-md) var(--shpd-space-lg);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-registry-wizard__search-input {
    width: 100%;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-lg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-surface);
    color: var(--shpd-color-text);
    font-family: var(--shpd-font-family);
  }

  .shpd-registry-wizard__search-input:focus {
    outline: none;
    border-color: var(--shpd-color-primary);
    box-shadow: 0 0 0 3px var(--shpd-color-primary-soft, rgba(34, 102, 238, 0.15));
  }

  .shpd-registry-wizard__results {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md) var(--shpd-space-lg);
  }

  .shpd-registry-wizard__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-muted);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-registry-wizard__status--muted {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-registry-wizard__status--error {
    color: var(--shpd-color-danger);
  }

  .shpd-registry-wizard__list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-registry-wizard__list-item {
    margin: 0;
  }

  .shpd-registry-wizard__row {
    width: 100%;
    text-align: left;
    font: inherit;
    color: inherit;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--shpd-space-md);
    align-items: flex-start;
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-surface);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }

  .shpd-registry-wizard__row:hover:not(:disabled) {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-bg-hover);
  }

  .shpd-registry-wizard__row:focus-visible {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: 2px;
  }

  .shpd-registry-wizard__row--selected {
    border-color: var(--shpd-color-primary);
    background: var(--shpd-color-primary-soft, rgba(34, 102, 238, 0.08));
  }

  .shpd-registry-wizard__row--disabled,
  .shpd-registry-wizard__row:disabled {
    cursor: not-allowed;
    opacity: 0.7;
  }

  .shpd-registry-wizard__row-index {
    color: var(--shpd-color-text-muted);
    font-variant-numeric: tabular-nums;
    min-width: 2ch;
    text-align: right;
  }

  .shpd-registry-wizard__row-body {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-registry-wizard__row-name {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-registry-wizard__row-address {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-registry-wizard__row-ids {
    display: flex;
    gap: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: 2px;
  }

  .shpd-registry-wizard__mono {
    font-family: var(--shpd-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  }

  .shpd-registry-wizard__row-badges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
  }

  .shpd-registry-wizard__badge {
    display: inline-block;
    padding: 2px var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs, 0.75rem);
    font-weight: 500;
    white-space: nowrap;
  }

  .shpd-registry-wizard__badge--warning {
    background: var(--shpd-color-warning-soft, rgba(220, 130, 0, 0.12));
    color: var(--shpd-color-warning, #b45309);
    border: 1px solid var(--shpd-color-warning, #b45309);
  }

  .shpd-registry-wizard__badge--muted {
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text-muted);
    border: 1px solid var(--shpd-color-border);
  }

  /* ── Preview screen ─────────────────────────────────────────────── */

  .shpd-registry-wizard__preview {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    max-width: 720px;
    width: 100%;
    margin: 0 auto;
  }

  .shpd-registry-wizard__card {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-surface);
    padding: var(--shpd-space-md) var(--shpd-space-lg);
  }

  .shpd-registry-wizard__card-title {
    margin: 0 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-registry-wizard__props {
    margin: 0;
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-lg);
  }

  .shpd-registry-wizard__prop {
    display: contents;
  }

  .shpd-registry-wizard__prop dt {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-registry-wizard__prop dd {
    margin: 0;
    color: var(--shpd-color-text);
  }

  .shpd-registry-wizard__address-line {
    color: var(--shpd-color-text);
  }

  .shpd-registry-wizard__pills {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-registry-wizard__pill {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border-radius: var(--shpd-radius-pill, 9999px);
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
  }
</style>
