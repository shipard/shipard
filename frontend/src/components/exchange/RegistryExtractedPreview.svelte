<script>
  // Kompaktní preview registry extrakce (`shpd.registry.document.v1`) pro
  // pravý panel DocumentExchangePreviewModal — target `registry` nemá žádný
  // resolve panel (design §7.8): jen title, summary, protistrana, tabulka
  // kindFields s lokalizovanými labely a návrh šanonu. Akce (Zařadit /
  // Zamítnout) vlastní footer modalu.

  import { t } from '../../i18n/index.js';

  let { canonical = null } = $props();

  const docKindLabel = $derived.by(() => {
    const docType = canonical?.docType ?? '';
    const key = `registry.docKind.${docType}`;
    const out = t(key);
    return out === key ? docType : out;
  });

  // Lokalizovaný label pole; neznámý klíč (budoucí druh) padá na raw název.
  function fieldLabel(key) {
    const i18nKey = `registry.field.${key}`;
    const out = t(i18nKey);
    return out === i18nKey ? key : out;
  }

  function formatValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'number') {
      return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    // ISO datum → lokalizované datum, ostatní stringy beze změny.
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      const d = new Date(value + 'T00:00:00');
      return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString();
    }
    return String(value);
  }

  const kindFields = $derived(
    Object.entries(canonical?.kindFields ?? {}).filter(
      ([, v]) => v !== null && v !== undefined && v !== '',
    ),
  );
</script>

{#if canonical}
  <div class="shpd-registry-preview">
    <header class="shpd-registry-preview__header">
      <span class="shpd-registry-preview__kind-badge">{docKindLabel}</span>
      <h2 class="shpd-registry-preview__title">{canonical.title ?? '—'}</h2>
      {#if canonical.party?.name}
        <p class="shpd-registry-preview__party">
          {t('registry.preview.party')}: {canonical.party.name}
          {#if canonical.party.companyId}
            · {t('registry.preview.companyId')} {canonical.party.companyId}
          {/if}
        </p>
      {/if}
    </header>

    {#if canonical.summary}
      <section class="shpd-registry-preview__summary">
        {canonical.summary}
      </section>
    {/if}

    {#if kindFields.length > 0}
      <section class="shpd-registry-preview__section">
        <h3 class="shpd-registry-preview__section-heading">
          {t('registry.preview.fields')}
        </h3>
        <table class="shpd-registry-preview__fields">
          <tbody>
            {#each kindFields as [key, value] (key)}
              <tr>
                <th scope="row">{fieldLabel(key)}</th>
                <td>{formatValue(value)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </section>
    {/if}

    {#if canonical.binderSuggestion}
      <section class="shpd-registry-preview__binder">
        <span class="shpd-registry-preview__binder-label">
          {t('registry.preview.binderSuggestion')}:
        </span>
        {canonical.binderSuggestion}
      </section>
    {/if}
  </div>
{/if}

<style>
  .shpd-registry-preview {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
  }

  .shpd-registry-preview__header {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    align-items: flex-start;
  }

  .shpd-registry-preview__kind-badge {
    display: inline-block;
    padding: 0.125rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-primary-soft, rgba(59, 130, 246, 0.12));
    color: var(--shpd-color-primary);
    font-size: var(--shpd-font-size-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .shpd-registry-preview__title {
    margin: 0;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
  }

  .shpd-registry-preview__party {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-registry-preview__summary {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-left: 3px solid var(--shpd-color-primary);
    background: var(--shpd-color-bg);
    border-radius: 0 var(--shpd-radius-md) var(--shpd-radius-md) 0;
    font-size: var(--shpd-font-size-sm);
    line-height: 1.5;
  }

  .shpd-registry-preview__section-heading {
    margin: 0 0 var(--shpd-space-xs);
    font-size: var(--shpd-font-size-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-registry-preview__fields {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-registry-preview__fields tr {
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-registry-preview__fields tr:last-child {
    border-bottom: 0;
  }

  .shpd-registry-preview__fields th {
    text-align: left;
    font-weight: 500;
    color: var(--shpd-color-text-secondary);
    padding: var(--shpd-space-xs) var(--shpd-space-sm) var(--shpd-space-xs) 0;
    white-space: nowrap;
    vertical-align: top;
    width: 40%;
  }

  .shpd-registry-preview__fields td {
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-registry-preview__binder {
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-registry-preview__binder-label {
    color: var(--shpd-color-text-secondary);
  }
</style>
