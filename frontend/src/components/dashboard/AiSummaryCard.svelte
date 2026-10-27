<script>
  import { t } from '../../i18n/index.js';
  import { streamDashboardSummary } from '../../api/dashboard.js';
  import { iconRobot } from '../../icons.js';
  import Icon from '../ui/Icon.svelte';
  import Card from '../ui/Card.svelte';

  let { summary } = $props();

  // Generované shrnutí (fáze 2b) dotéká přes SSE do `aiText`. Dokud není
  // (prázdný feed, degradace, stream teprve běží), zobrazuje se statický
  // text složený z počtů karet dle kind (fáze 2a).
  let aiText = $state('');
  let streaming = $state(false);

  const staticText = $derived.by(() => {
    const counts = summary.counts ?? {};
    const parts = [];
    if (counts.urgent > 0) {
      parts.push(t('dashboard.aiSummary.counts.urgent', { count: counts.urgent }));
    }
    if (counts.review > 0) {
      parts.push(t('dashboard.aiSummary.counts.review', { count: counts.review }));
    }
    if (counts.ready > 0) {
      parts.push(t('dashboard.aiSummary.counts.ready', { count: counts.ready }));
    }
    if (parts.length === 0) {
      return t('dashboard.aiSummary.empty');
    }
    return t('dashboard.aiSummary.intro') + ' ' + parts.join(', ') + '.';
  });

  const displayText = $derived(aiText !== '' ? aiText : staticText);

  // Každá změna `summary` (mount i refresh dashboardu — load() vymění data)
  // otevře stream znovu; hit/miss rozhodne server. Cleanup zavře předchozí
  // stream, chyba/prázdné shrnutí = tichá degradace na statický text.
  $effect(() => {
    void summary;
    aiText = '';
    streaming = true;
    const handle = streamDashboardSummary({
      onDelta: (delta) => {
        aiText += delta;
      },
      onDone: (text) => {
        streaming = false;
        aiText = text ?? '';
      },
      onError: (message) => {
        streaming = false;
        aiText = '';
        console.warn('AI summary failed:', message);
      },
    });
    return () => handle.close();
  });
</script>

<!-- Sdílená karta s tónovaným pozadím (#137 D33, tón info); řádek
     ikona + tělo je obsah karty. -->
<Card as="div" tone="info">
  <div class="shpd-ai-summary__layout">
    <span class="shpd-ai-summary__icon">
      <Icon icon={iconRobot} size="lg" />
    </span>
    <div class="shpd-ai-summary__body">
      <div class="shpd-ai-summary__title">{t('dashboard.aiSummary.title')}</div>
      <div class="shpd-ai-summary__text">{displayText}</div>
      {#if streaming}
        <div class="shpd-ai-summary__hint">{t('dashboard.aiSummary.generating')}</div>
      {/if}
    </div>
  </div>
</Card>

<style>
  .shpd-ai-summary__layout {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: flex-start;
  }

  .shpd-ai-summary__icon {
    color: var(--shpd-color-primary);
    flex-shrink: 0;
    margin-top: 0.125rem;
  }

  .shpd-ai-summary__body { flex: 1; min-width: 0; }

  .shpd-ai-summary__title {
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-primary);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-ai-summary__text {
    color: var(--shpd-color-text);
    line-height: 1.5;
  }

  .shpd-ai-summary__hint {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xs);
    font-style: italic;
  }
</style>
