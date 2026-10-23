<script>
  // Nabídka účetních položek — karta v sekci „Volitelné" panelu dsSetup
  // (ds-setup Task 10, D18/D19). Jednorázová akce: vygeneruje položky pro
  // účtování bankovních poplatků, kurzových rozdílů a zaokrouhlení přímo
  // z řádku dokladu (acc.entry). Není to checklist ani alert — nesplněná
  // nabídka nikdy nic nerozsvítí.
  //
  // Komponenta si drží vlastní stav (fetch/generate) a s checklistem
  // v DsSetup nijak neinteraguje.
  import { onMount } from 'svelte';
  import Button from '../ui/Button.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconChevronDown, iconChevronRight } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchAccountingItemsOffer, generateAccountingItems } from '../../api/setup.js';

  let loading = $state(true);
  let loadError = $state(null);
  let offer = $state(null);
  let checked = $state({});   // code → bool
  let expanded = $state({});  // group id → bool; default sbaleno

  let generating = $state(false);
  let generateError = $state(null);
  let summary = $state(null); // {createdCount, skipped: [...]}

  async function load() {
    loading = true;
    loadError = null;
    try {
      const result = await fetchAccountingItemsOffer();
      if (result?.success) {
        offer = result.data;
        // Předvybrat vše, co ještě neexistuje — nabídka je krátká a míněná
        // jako celek; odškrtnutí je opt-out.
        const preselect = {};
        for (const c of offer?.candidates ?? []) {
          preselect[c.code] = !c.exists;
        }
        checked = preselect;
        expanded = {};   // po (re)loadu vše sbalené
      } else {
        loadError = translateError(result?.error);
      }
    } catch (e) {
      loadError = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  const selectedCodes = $derived(
    (offer?.candidates ?? []).filter((c) => !c.exists && checked[c.code]).map((c) => c.code),
  );

  // Sekce = skupiny ze serveru (přicházejí seřazené dle order) + syntetická
  // „Ostatní" na konci pro kandidáty s neznámou skupinou (rozbitý seed
  // nabídku nerozbije, jen odsune položky na konec).
  const sections = $derived.by(() => {
    const byId = new Map((offer?.groups ?? []).map((g) => [g.id, { ...g, candidates: [] }]));
    const other = { id: '_other', name: t('setup.offer.items.group.other'), candidates: [] };
    for (const c of offer?.candidates ?? []) {
      (byId.get(c.group) ?? other).candidates.push(c);
    }
    const list = [...byId.values()].filter((s) => s.candidates.length > 0);
    if (other.candidates.length > 0) list.push(other);
    return list;
  });

  // Tri-state hlavičky: existující položky se do dostupných nepočítají.
  function sectionStats(section) {
    const available = section.candidates.filter((c) => !c.exists);
    const selected = available.filter((c) => checked[c.code]).length;
    return { available: available.length, selected };
  }

  function toggleSection(section) {
    const { available, selected } = sectionStats(section);
    if (available === 0) return;
    const value = selected < available;   // část/nic → vybrat vše, vše → odebrat
    for (const c of section.candidates) {
      if (!c.exists) checked[c.code] = value;
    }
  }

  function toggleExpand(id) {
    expanded[id] = !expanded[id];
  }

  const unavailableText = $derived.by(() => {
    const reason = offer?.unavailableReason;
    if (!reason) return null;
    const key = `setup.offer.items.unavailable.${reason}`;
    return t(key);
  });

  async function handleGenerate() {
    if (selectedCodes.length === 0 || generating) return;
    generating = true;
    generateError = null;
    summary = null;
    try {
      const result = await generateAccountingItems(selectedCodes);
      if (result?.success) {
        // Souhrn zůstává viditelný nad překreslenou nabídkou — přeskočené
        // položky má uživatel vidět, nic se nezavírá.
        summary = {
          createdCount: (result.data?.created ?? []).length,
          skipped: result.data?.skipped ?? [],
        };
        await load();
      } else {
        generateError = translateError(result?.error);
      }
    } catch (e) {
      generateError = e instanceof Error ? e.message : String(e);
    } finally {
      generating = false;
    }
  }

  function skippedLine(entry) {
    const reasonKey = `setup.offer.items.skipped.${entry.reason}`;
    const reason = entry.accountNumber
      ? t(reasonKey, { account: entry.accountNumber })
      : t(reasonKey);
    return `${entry.code} — ${reason}`;
  }

  onMount(load);
</script>

<div class="shpd-items-offer">
  <div class="shpd-items-offer__head">
    <span class="shpd-items-offer__title">{t('setup.offer.items.title')}</span>
  </div>
  <p class="shpd-items-offer__desc">{t('setup.offer.items.description')}</p>

  {#if loading}
    <p class="shpd-items-offer__state">{t('common.loading')}</p>
  {:else if loadError}
    <p class="shpd-items-offer__state shpd-items-offer__state--error">{loadError}</p>
  {:else if offer && !offer.available}
    <!-- Nedostupná nabídka se NEschovává — uživatel má vědět, že existuje
         a proč teď nejde (chart_undecided / chart_none / accounting_inactive). -->
    <p class="shpd-items-offer__state shpd-items-offer__state--muted">{unavailableText}</p>
  {:else if offer}
    {#if summary}
      <div class="shpd-items-offer__summary" role="status">
        <p>{t('setup.offer.items.summary.created', { count: summary.createdCount })}</p>
        {#if summary.skipped.length > 0}
          <p>{t('setup.offer.items.summary.skipped', { count: summary.skipped.length })}</p>
          <ul class="shpd-items-offer__skipped">
            {#each summary.skipped as entry (entry.code)}
              <li>{skippedLine(entry)}</li>
            {/each}
          </ul>
        {/if}
      </div>
    {/if}

    <div class="shpd-items-offer__groups">
      {#each sections as section (section.id)}
        {@const stats = sectionStats(section)}
        <section class="shpd-items-offer__group">
          <!-- Checkbox přepíná výběr skupiny, zbytek hlavičky je tlačítko
               sbalit/rozbalit — dva samostatné ovládací prvky, oba
               dosažitelné z klávesnice. -->
          <div class="shpd-items-offer__group-head">
            <span class="shpd-items-offer__group-check">
              <Checkbox
                checked={stats.available > 0 && stats.selected === stats.available}
                indeterminate={stats.selected > 0 && stats.selected < stats.available}
                disabled={stats.available === 0 || generating}
                onchange={() => toggleSection(section)}
              />
            </span>
            <button
              type="button"
              class="shpd-items-offer__group-toggle"
              aria-expanded={!!expanded[section.id]}
              onclick={() => toggleExpand(section.id)}
            >
              <span class="shpd-items-offer__group-name">{section.name}</span>
              <span class="shpd-items-offer__group-count">{stats.selected}/{stats.available}</span>
              <Icon icon={expanded[section.id] ? iconChevronDown : iconChevronRight} size="sm" />
            </button>
          </div>

          {#if expanded[section.id]}
            <ul class="shpd-items-offer__list">
              {#each section.candidates as candidate (candidate.code)}
                <li class="shpd-items-offer__row" class:shpd-items-offer__row--exists={candidate.exists}>
                  <Checkbox
                    bind:checked={checked[candidate.code]}
                    disabled={candidate.exists || generating}
                  />
                  <span class="shpd-items-offer__name">{candidate.name}</span>
                  <span class="shpd-items-offer__account">{candidate.accountNumber}</span>
                  {#if candidate.exists}
                    <span class="shpd-items-offer__badge">{t('setup.offer.items.exists')}</span>
                  {/if}
                </li>
              {/each}
            </ul>
          {/if}
        </section>
      {/each}
    </div>

    {#if generateError}
      <p class="shpd-items-offer__state shpd-items-offer__state--error">{generateError}</p>
    {/if}

    <div class="shpd-items-offer__actions">
      <Button
        label={generating ? t('common.saving') : t('setup.offer.items.generate', { count: selectedCodes.length })}
        size="sm"
        disabled={selectedCodes.length === 0 || generating}
        loading={generating}
        onclick={handleGenerate}
      />
    </div>
  {/if}
</div>

<style>
  .shpd-items-offer {
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-items-offer__head {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-items-offer__title {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__desc {
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__state {
    margin-top: var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-items-offer__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-items-offer__state--muted {
    font-style: italic;
  }

  .shpd-items-offer__summary {
    margin-top: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__skipped {
    margin: var(--shpd-space-xs) 0 0;
    padding-left: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__groups {
    margin-top: var(--shpd-space-sm);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-items-offer__group {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg);
  }

  .shpd-items-offer__group-head {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__group-toggle {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    flex: 1;
    min-width: 0;
    padding: 0;
    border: none;
    background: none;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    user-select: none;
  }

  .shpd-items-offer__group-check :global(.shpd-checkbox) {
    margin-bottom: 0;
  }

  .shpd-items-offer__group-name {
    flex: 1;
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__group-count {
    font-size: var(--shpd-font-size-sm);
    font-variant-numeric: tabular-nums;
  }

  .shpd-items-offer__list {
    list-style: none;
    /* Odsazení pod název skupiny: checkbox hlavičky (18px) + gap. */
    margin: 0;
    padding: 0 var(--shpd-space-sm) var(--shpd-space-sm) calc(1.125rem + 2 * var(--shpd-space-sm));
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-items-offer__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-items-offer__row--exists {
    opacity: 0.6;
  }

  .shpd-items-offer__name {
    color: var(--shpd-color-text);
  }

  .shpd-items-offer__account {
    font-family: var(--shpd-font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-items-offer__badge {
    padding: 0.0625rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs);
    background: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text-secondary);
    border: 1px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-items-offer__actions {
    margin-top: var(--shpd-space-md);
  }
</style>
