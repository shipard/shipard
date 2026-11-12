<script>
  // Náhled registry extrakce (`shpd.registry.document.v1`) pro pravý panel
  // DocumentExchangePreviewModal — target `registry` nemá žádný resolve
  // panel (design §7.8). Od #139 (D49, D54) na stavebních prvcích detailu:
  // hlavička `DetailHeader` z `registryHeader` (titulek název dokumentu,
  // podtitul „protistrana · IČO“, štítek „Spisovna · druh“ bez stavu),
  // shrnutí jako prostý text přes celou šířku (blok `text` detailu)
  // a pole druhu s návrhem šanonu jako skupina `PropertyGroups`
  // (lokalizované popisky, prázdná pole vynechaná jako dřív). Akce
  // (Zařadit / Zamítnout) vlastní footer modalu.

  import { t } from '../../i18n/index.js';
  import DetailHeader from '../ui/DetailHeader.svelte';
  import PropertyGroups from '../ui/PropertyGroups.svelte';
  import { registryHeader } from './reviewParts.js';
  import { formatDate } from '../../utils/formatDate.js';

  let { canonical = null } = $props();

  let header = $derived(registryHeader(canonical, t));

  // Lokalizovaný label pole; neznámý klíč (budoucí druh) padá na raw název.
  function fieldLabel(key) {
    const i18nKey = `registry.field.${key}`;
    const out = t(i18nKey);
    return out === i18nKey ? key : out;
  }

  function formatValue(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') {
      return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
    }
    // ISO datum → datum v jazyce rozhraní, ostatní stringy beze změny.
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return formatDate(value);
    }
    return String(value);
  }

  // Jedna skupina „Údaje dokumentu“: pole druhu v pořadí z canonicalu
  // a návrh šanonu jako poslední položka (D31: jediná položka vedle
  // dlouhé skupiny by byla nevyvážená dvojice). Prázdné položky vynechá
  // PropertyGroups (výchozí chování detailu).
  let groups = $derived([
    {
      title: t('registry.preview.fields'),
      items: [
        ...Object.entries(canonical?.kindFields ?? {}).map(([key, value]) => ({
          label: fieldLabel(key),
          value: formatValue(value),
        })),
        { label: t('registry.preview.binderSuggestion'), value: formatValue(canonical?.binderSuggestion) },
      ],
    },
  ]);
</script>

{#if canonical}
  <div class="shpd-registry-preview">
    <DetailHeader title={header.title} subtitle={header.subtitle} badges={header.badges} />

    {#if canonical.summary}
      <!-- Shrnutí z analýzy — prostý text jako blok `text` detailu (§12). -->
      <p class="shpd-registry-preview__summary">{canonical.summary}</p>
    {/if}

    <PropertyGroups {groups} headingLevel={3} />
  </div>
{/if}

<style>
  /* Obal jako .shpd-exchange: flex sloupec, odsazení a mezery md, body. */
  .shpd-registry-preview {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text);
  }

  .shpd-registry-preview__summary {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.5;
  }
</style>
