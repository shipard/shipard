<script>
  // Dialog „Převzít bankovní účty" pro panel dsSetup (ds-setup Task 09,
  // §5.4 bod 3, D17). Zaškrtávací seznam bankovních spojení vlastní Osoby
  // — účty se source = 2 (Registr DPH API) jsou předvybrané, protože jsou
  // oficiálně zveřejněné; už překlopené účty jsou zašedlé s poznámkou.
  //
  // Právě jeden vybraný účet je výchozí (radio ve vybraných řádcích);
  // u jediného vybraného to drží efekt automaticky. Server má stejnou
  // pojistku, takže se na pořadí klikání nedá rozbít.
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchBankAccountCandidates, bridgeBankAccounts } from '../../api/setup.js';

  let {
    open = false,
    onClose = () => {},
    onSaved = () => {},
  } = $props();

  let loading = $state(true);
  let loadError = $state(null);
  let candidates = $state([]);
  let checked = $state({});   // id → bool
  let defaultId = $state(null);

  let saving = $state(false);
  let saveError = $state(null);

  $effect(() => {
    if (open) {
      void resetAndLoad();
    }
  });

  async function resetAndLoad() {
    loading = true;
    loadError = null;
    candidates = [];
    checked = {};
    defaultId = null;
    saving = false;
    saveError = null;
    try {
      const result = await fetchBankAccountCandidates();
      if (result?.success) {
        candidates = result.data?.candidates ?? [];
        // Předvýběr oficiálně zveřejněných účtů (source = 2, D17).
        const preselect = {};
        for (const c of candidates) {
          preselect[c.id] = c.source === 2 && !c.existsInCodebook;
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

  const selectedIds = $derived(
    candidates.filter((c) => checked[c.id] && !c.existsInCodebook).map((c) => c.id),
  );

  // Default drží krok s výběrem: odškrtnutý účet nemůže zůstat výchozí,
  // první/jediný vybraný je výchozí automaticky. Effect místo handleru,
  // protože checkboxy jsou bind:checked (klávesnice i myš).
  $effect(() => {
    const ids = selectedIds;
    if (ids.length === 0) {
      if (defaultId !== null) defaultId = null;
      return;
    }
    if (defaultId === null || !ids.includes(defaultId)) {
      defaultId = ids[0];
    }
  });

  function accountLine(c) {
    return c.iban !== '' ? c.iban : c.accountNumber;
  }

  async function handleSave() {
    if (selectedIds.length === 0 || saving) return;
    saving = true;
    saveError = null;
    try {
      const result = await bridgeBankAccounts(selectedIds, defaultId);
      if (result?.success) {
        onSaved();
        onClose();
        return;
      }
      saveError = translateError(result?.error);
    } catch (e) {
      saveError = e instanceof Error ? e.message : String(e);
    } finally {
      saving = false;
    }
  }
</script>

<Modal title={t('setup.bankDialog.title')} {open} {onClose}>
  <div class="shpd-bank-bridge">
    <p class="shpd-bank-bridge__intro">{t('setup.bankDialog.intro')}</p>

    {#if loading}
      <p class="shpd-bank-bridge__state">{t('common.loading')}</p>
    {:else if loadError}
      <p class="shpd-bank-bridge__state shpd-bank-bridge__state--error">{loadError}</p>
    {:else if candidates.length === 0}
      <p class="shpd-bank-bridge__state">{t('setup.bankDialog.empty')}</p>
    {:else}
      <ul class="shpd-bank-bridge__list">
        {#each candidates as candidate (candidate.id)}
          {@const disabled = candidate.existsInCodebook}
          {@const isChecked = !disabled && !!checked[candidate.id]}
          <li
            class="shpd-bank-bridge__row"
            class:shpd-bank-bridge__row--disabled={disabled}
          >
            <div class="shpd-bank-bridge__check">
              <Checkbox bind:checked={checked[candidate.id]} disabled={disabled || saving} />
            </div>
            <div class="shpd-bank-bridge__body">
              <div class="shpd-bank-bridge__name">
                <span>{candidate.name || accountLine(candidate)}</span>
                {#if candidate.source === 2}
                  <span class="shpd-bank-bridge__badge">{t('setup.bankDialog.badge.published')}</span>
                {/if}
                {#if disabled}
                  <span class="shpd-bank-bridge__badge shpd-bank-bridge__badge--muted">
                    {t('setup.bankDialog.badge.exists')}
                  </span>
                {/if}
              </div>
              <div class="shpd-bank-bridge__detail">
                <span class="shpd-bank-bridge__mono">{accountLine(candidate)}</span>
                {#if candidate.currency}
                  <span class="shpd-bank-bridge__currency">{candidate.currency.toUpperCase()}</span>
                {/if}
              </div>
            </div>
            {#if isChecked}
              <label class="shpd-bank-bridge__default">
                <input
                  type="radio"
                  name="bank-bridge-default"
                  value={candidate.id}
                  checked={defaultId === candidate.id}
                  disabled={saving}
                  onchange={() => { defaultId = candidate.id; }}
                />
                <span>{t('setup.bankDialog.default')}</span>
              </label>
            {/if}
          </li>
        {/each}
      </ul>
    {/if}

    {#if saveError}
      <p class="shpd-bank-bridge__state shpd-bank-bridge__state--error">{saveError}</p>
    {/if}
  </div>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" disabled={saving} onclick={onClose} />
    <Button
      label={saving ? t('common.saving') : t('setup.bankDialog.save', { count: selectedIds.length })}
      variant="primary"
      disabled={selectedIds.length === 0 || saving}
      loading={saving}
      onclick={handleSave}
    />
  {/snippet}
</Modal>

<style>
  .shpd-bank-bridge {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-bank-bridge__intro {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-bank-bridge__state {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-bank-bridge__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-bank-bridge__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-bank-bridge__row {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-surface);
  }

  .shpd-bank-bridge__row--disabled {
    opacity: 0.6;
  }

  .shpd-bank-bridge__check {
    flex-shrink: 0;
  }

  .shpd-bank-bridge__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-bank-bridge__name {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    flex-wrap: wrap;
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-bank-bridge__badge {
    padding: 1px var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs, 0.75rem);
    font-weight: 500;
    background: var(--shpd-color-primary-soft, rgba(34, 102, 238, 0.1));
    color: var(--shpd-color-primary);
    border: 1px solid var(--shpd-color-primary);
  }

  .shpd-bank-bridge__badge--muted {
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text-muted);
    border-color: var(--shpd-color-border);
  }

  .shpd-bank-bridge__detail {
    display: flex;
    gap: var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-bank-bridge__mono {
    font-family: var(--shpd-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  }

  .shpd-bank-bridge__default {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }
</style>
