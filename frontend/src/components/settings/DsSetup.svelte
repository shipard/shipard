<script>
  // Panel „Nastavení zdroje dat" (nav item type 'panel', panelId dsSetup):
  // živý checklist chybějícího nastavení (GET /_setup/checklist, D12)
  // + ovládání parametrů vrstvy C přímo v řádcích (POST /_setup/parameters).
  //
  // Ručně psaný panel místo generické settings stránky (D14): parametry
  // potřebují vysvětlující texty a vatAgenda je tříhodnotová. Žádný
  // progress se neukládá (D3) — prázdný checklist = hotovo.
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Icon from '../ui/Icon.svelte';
  import Select from '../ui/Select.svelte';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import RegistryImportWizard from '../registry/RegistryImportWizard.svelte';
  import VatRegistrationPrefillDialog from './VatRegistrationPrefillDialog.svelte';
  import BankAccountBridgeDialog from './BankAccountBridgeDialog.svelte';
  import AccountingItemsOffer from './AccountingItemsOffer.svelte';
  import { iconListCheck, iconSuccess, iconWarning } from '../../icons.js';
  import { fetchSetupChecklist, saveSetupParameters } from '../../api/setup.js';

  let loading = $state(true);
  let error = $state(null);
  let items = $state([]);
  let parameters = $state({});
  let currencyOptions = $state([]);
  let warnings = $state([]);
  let savingKey = $state(null);
  let fieldErrors = $state({});

  // Draft hodnoty selectů — bind cíl; po POSTu (i po revertu) se srovná
  // se serverovým stavem. Žádná validace hodnot tady není (jediné místo
  // pravdy je LayerCParameters na serveru), jen omezená nabídka.
  let draft = $state({});

  // Form modal pro open_form akce checků — stejný vzor jako feed karta
  // (Dashboard.svelte): FormDialog + wasSaved → reload po zavření.
  let formModal = $state({ open: false, table: '', recordId: null, defaultData: {}, wasSaved: false });

  // Registry wizard v režimu asOwn — akce registry_import_own u položky
  // „Chybí vlastní Osoba". Stejný wasSaved → reload vzor jako formModal.
  let registryModal = $state({ open: false, wasSaved: false });

  // Dialogy Task 09: prefill Registrace DPH a můstek bankovních účtů.
  let vatModal = $state({ open: false, wasSaved: false });
  let bankModal = $state({ open: false, wasSaved: false });

  const CHART_KEY = 'economy.accountChart';
  const VAT_KEY = 'economy.vatAgenda';
  const MONTH_KEY = 'economy.fiscalYearStartMonth';
  const CURRENCY_KEY = 'economy.homeCurrency';

  const chartOptions = $derived([
    { value: 'default', label: t('setup.chart.default') },
    { value: 'npo', label: t('setup.chart.npo') },
    { value: 'none', label: t('setup.chart.none') },
  ]);

  const monthOptions = (() => {
    const lang = document.documentElement.lang || 'cs';
    const fmt = new Intl.DateTimeFormat(lang, { month: 'long' });
    return Array.from({ length: 12 }, (_, i) => ({
      value: i + 1,
      label: fmt.format(new Date(2000, i, 1)),
    }));
  })();

  const vatStates = $derived([
    { value: null, label: t('setup.undecided') },
    { value: true, label: t('setup.vat.payer') },
    { value: false, label: t('setup.vat.nonPayer') },
  ]);

  // Rozhodnuté parametry do sbalené sekce — jen ty, které nejsou v items
  // (nerozhodnuté řeší checklist) a mají hodnotu.
  const decidedKeys = $derived(
    Object.keys(parameters).filter(
      (key) => parameters[key] !== null && !items.some((it) => it.parameter === key),
    ),
  );

  function applyState(data) {
    items = data.items ?? [];
    parameters = data.parameters ?? {};
    currencyOptions = data.currencyOptions ?? currencyOptions;
    draft = { ...parameters };
    // Serverový návrh (suggestion) předvybere hodnotu jen v draftu —
    // na server se nic nepíše, dokud uživatel volbu nepotvrdí kliknutím
    // (D2: absence klíče = nerozhodnuto). Položka v checklistu zůstává.
    for (const item of items) {
      if (item.parameter && item.suggestion && (parameters[item.parameter] ?? null) === null) {
        draft[item.parameter] = item.suggestion.value;
      }
    }
  }

  // Důvod návrhu k zobrazení u ovládání parametru.
  const suggestionByKey = $derived(
    Object.fromEntries(
      items.filter((it) => it.parameter && it.suggestion).map((it) => [it.parameter, it.suggestion]),
    ),
  );

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchSetupChecklist();
      if (result?.success) {
        applyState(result.data);
      } else {
        error = translateError(result?.error);
      }
    } catch (err) {
      error = t('setup.error.loadFailed');
      console.error('Setup checklist load failed:', err);
    } finally {
      loading = false;
    }
  }

  async function setParameter(key, value) {
    if (savingKey !== null) return;
    savingKey = key;
    fieldErrors = { ...fieldErrors, [key]: null };
    const prev = parameters[key];
    try {
      const result = await saveSetupParameters({ [key]: value });
      if (result?.success) {
        warnings = result.data.warnings ?? [];
        applyState(result.data);
      } else {
        draft = { ...draft, [key]: prev };
        fieldErrors = { ...fieldErrors, [key]: fieldErrorMessage(result?.error, key) };
      }
    } catch (err) {
      draft = { ...draft, [key]: prev };
      fieldErrors = { ...fieldErrors, [key]: t('setup.error.saveFailed') };
      console.error('Setup parameter save failed:', err);
    } finally {
      savingKey = null;
    }
  }

  function fieldErrorMessage(errorPayload, key) {
    const detail = Array.isArray(errorPayload?.details)
      ? errorPayload.details.find((d) => d?.field === key)
      : null;
    return detail?.message ?? translateError(errorPayload);
  }

  function runAction(action) {
    const target = action?.target ?? {};
    if (action?.kind === 'open_form' && target.table) {
      formModal = {
        open: true,
        table: target.table,
        recordId: target.mode === 'edit' ? (target.id ?? null) : null,
        defaultData: target.preset ?? {},
        wasSaved: false,
      };
      return;
    }
    if (action?.kind === 'registry_import_own') {
      registryModal = { open: true, wasSaved: false };
      return;
    }
    if (action?.kind === 'prefill_vat_registration') {
      vatModal = { open: true, wasSaved: false };
      return;
    }
    if (action?.kind === 'bridge_bank_accounts') {
      bankModal = { open: true, wasSaved: false };
      return;
    }
    console.warn('Unknown setup item action kind:', action?.kind);
  }

  function handleFormSaved() {
    formModal.wasSaved = true;
  }

  function handleFormClose() {
    const shouldRefetch = formModal.wasSaved;
    formModal = { open: false, table: '', recordId: null, defaultData: {}, wasSaved: false };
    if (shouldRefetch) load();
  }

  function handleRegistrySaved() {
    registryModal.wasSaved = true;
  }

  function handleRegistryClose() {
    const shouldRefetch = registryModal.wasSaved;
    registryModal = { open: false, wasSaved: false };
    if (shouldRefetch) load();
  }

  function handleVatSaved() {
    vatModal.wasSaved = true;
  }

  function handleVatClose() {
    const shouldRefetch = vatModal.wasSaved;
    vatModal = { open: false, wasSaved: false };
    if (shouldRefetch) load();
  }

  function handleBankSaved() {
    bankModal.wasSaved = true;
  }

  function handleBankClose() {
    const shouldRefetch = bankModal.wasSaved;
    bankModal = { open: false, wasSaved: false };
    if (shouldRefetch) load();
  }

  function itemActions(item) {
    return Array.isArray(item.actions) ? item.actions : [];
  }

  onMount(load);
</script>

<div class="shpd-ds-setup">
  <div class="shpd-ds-setup__card">
    <h2 class="shpd-ds-setup__title">
      <Icon icon={iconListCheck} size="md" />
      <span>{t('setup.title')}</span>
    </h2>
    <p class="shpd-ds-setup__intro">{t('setup.intro')}</p>

    {#if loading}
      <p class="shpd-ds-setup__state">{t('common.loading')}</p>
    {:else if error}
      <p class="shpd-ds-setup__state shpd-ds-setup__state--error">{error}</p>
    {:else}
      {#if warnings.length > 0}
        <div class="shpd-ds-setup__warnings" role="alert">
          {#each warnings as warning}
            <p class="shpd-ds-setup__warning">
              <Icon icon={iconWarning} size="sm" />
              <span>{warning}</span>
            </p>
          {/each}
        </div>
      {/if}

      {#if items.length === 0}
        <p class="shpd-ds-setup__empty">
          <Icon icon={iconSuccess} size="sm" />
          <span>{t('setup.empty')}</span>
        </p>
      {:else}
        <ul class="shpd-ds-setup__list">
          {#each items as item (item.checkId)}
            <li class="shpd-ds-setup__item">
              <div class="shpd-ds-setup__item-head">
                <Icon icon={iconWarning} size="sm" />
                <span class="shpd-ds-setup__item-title">{item.title ?? item.name ?? item.checkId}</span>
              </div>
              {#if item.message}
                <p class="shpd-ds-setup__item-message">{item.message}</p>
              {/if}

              {#if item.parameter}
                {@render parameterControl(item.parameter)}
              {:else if itemActions(item).length > 0}
                <div class="shpd-ds-setup__item-actions">
                  {#each itemActions(item) as action (action.id)}
                    <Button
                      label={action.label ?? t('setup.openForm')}
                      size="sm"
                      variant={action.primary ? 'primary' : 'secondary'}
                      onclick={() => runAction(action)}
                    />
                  {/each}
                </div>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}

      {#if decidedKeys.length > 0}
        <details class="shpd-ds-setup__decided">
          <summary class="shpd-ds-setup__decided-summary">{t('setup.decided')}</summary>
          <ul class="shpd-ds-setup__list">
            {#each decidedKeys as key (key)}
              <li class="shpd-ds-setup__item">
                <div class="shpd-ds-setup__item-head">
                  <span class="shpd-ds-setup__item-title">{t(`setup.param.${key}`)}</span>
                </div>
                {@render parameterControl(key)}
              </li>
            {/each}
          </ul>
        </details>
      {/if}

      <!-- Nabídky (D18/D19): samostatná sekce mimo checklist — nesplněná
           nabídka není problém a nikdy nic nerozsvítí. -->
      <div class="shpd-ds-setup__optional">
        <h3 class="shpd-ds-setup__optional-title">{t('setup.optional.title')}</h3>
        <p class="shpd-ds-setup__optional-intro">{t('setup.optional.intro')}</p>
        <AccountingItemsOffer />
      </div>
    {/if}
  </div>
</div>

{#snippet parameterControl(key)}
  <div class="shpd-ds-setup__control">
    {#if key === VAT_KEY}
      <!-- Tři explicitní stavy — nerozhodnuto je legitimní hodnota a nesmí
           vypadat jako neplátce. -->
      <div class="shpd-ds-setup__segments" role="radiogroup">
        {#each vatStates as state (String(state.value))}
          <button
            type="button"
            class="shpd-ds-setup__segment"
            class:shpd-ds-setup__segment--active={draft[key] === state.value}
            role="radio"
            aria-checked={draft[key] === state.value}
            disabled={savingKey !== null}
            onclick={() => setParameter(key, state.value)}
          >
            {state.label}
          </button>
        {/each}
      </div>
    {:else}
      <div class="shpd-ds-setup__select">
        <Select
          bind:value={draft[key]}
          options={key === CHART_KEY ? chartOptions : key === MONTH_KEY ? monthOptions : currencyOptions}
          placeholder={t('setup.undecided')}
          disabled={savingKey !== null}
          error={fieldErrors[key] ?? null}
          onchange={() => setParameter(key, draft[key])}
        />
      </div>
    {/if}
    {#if key === VAT_KEY && fieldErrors[key]}
      <!-- u selectů chybu renderuje Select sám -->
      <p class="shpd-ds-setup__field-error">{fieldErrors[key]}</p>
    {/if}
    {#if suggestionByKey[key]}
      <!-- Předvybraný návrh ze serveru — uživatel má vidět, z čeho vychází. -->
      <p class="shpd-ds-setup__suggestion">{suggestionByKey[key].reason}</p>
    {/if}
    <p class="shpd-ds-setup__hint">{t(`setup.hint.${key}`)}</p>
  </div>
{/snippet}

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    defaultData={formModal.defaultData}
    open={formModal.open}
    onSaved={handleFormSaved}
    onClose={handleFormClose}
  />
{/if}

{#if registryModal.open}
  <RegistryImportWizard
    open={registryModal.open}
    asOwn={true}
    onSaved={handleRegistrySaved}
    onClose={handleRegistryClose}
  />
{/if}

{#if vatModal.open}
  <VatRegistrationPrefillDialog
    open={vatModal.open}
    onSaved={handleVatSaved}
    onClose={handleVatClose}
  />
{/if}

{#if bankModal.open}
  <BankAccountBridgeDialog
    open={bankModal.open}
    onSaved={handleBankSaved}
    onClose={handleBankClose}
  />
{/if}

<style>
  .shpd-ds-setup {
    padding: var(--shpd-space-lg);
    max-width: 760px;
  }

  .shpd-ds-setup__card {
    padding: var(--shpd-space-lg);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-lg);
  }

  .shpd-ds-setup__title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-ds-setup__intro {
    margin-bottom: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-ds-setup__state {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-setup__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-ds-setup__warnings {
    margin-bottom: var(--shpd-space-md);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-warning, #b98900);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-ds-setup__warning {
    display: flex;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-ds-setup__empty {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-setup__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-ds-setup__item {
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-ds-setup__item-head {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-ds-setup__item-message {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-setup__item-actions {
    margin-top: var(--shpd-space-sm);
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-ds-setup__control {
    margin-top: var(--shpd-space-sm);
  }

  .shpd-ds-setup__select {
    max-width: 320px;
  }

  .shpd-ds-setup__segments {
    display: inline-flex;
    gap: var(--shpd-space-xs);
    flex-wrap: wrap;
  }

  .shpd-ds-setup__segment {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
  }

  .shpd-ds-setup__segment--active {
    border-color: var(--shpd-color-primary);
    background-color: var(--shpd-color-primary-bg, var(--shpd-color-bg-secondary));
    font-weight: 600;
  }

  .shpd-ds-setup__segment:disabled {
    opacity: 0.6;
    cursor: default;
  }

  .shpd-ds-setup__hint {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-setup__suggestion {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-ds-setup__field-error {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }

  .shpd-ds-setup__decided {
    margin-top: var(--shpd-space-lg);
  }

  .shpd-ds-setup__decided-summary {
    cursor: pointer;
    font-weight: 600;
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-ds-setup__optional {
    margin-top: var(--shpd-space-xl, 2rem);
    padding-top: var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-ds-setup__optional-title {
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-ds-setup__optional-intro {
    margin: var(--shpd-space-xs) 0 var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
