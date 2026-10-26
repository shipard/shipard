<script>
  /**
   * Přehled firmy (#120) — obrazovka „jak na tom firma je": sekce jako
   * karty (Peníze, Pohledávky, Závazky, DPH, Výsledek hospodaření), každé
   * číslo s datem, ke kterému platí, a proklikem do zdroje. Je to stav, ne
   * fronta práce — akce a upozornění zůstávají na Dashboardu (#127).
   *
   * Texty sekcí, položek a zpráv jdou ze serveru (katalogy modulů); tady
   * jen texty rozhraní. Prokliky stejné jako v reportech (ReportsPage):
   * open_detail → ViewerDetailModal, open_viewer / open_report → navigace.
   */
  import { onMount } from 'svelte';
  import { t, language } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import { iconOverview, iconRefresh } from '../../icons.js';
  import { fetchOverview } from '../../api/overview.js';
  import { formatMoney } from '../../utils/formatNumber.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import ViewerDetailModal from '../viewer/ViewerDetailModal.svelte';

  let loading = $state(true);
  let error = $state(null);
  let data = $state(null);
  let detailModal = $state(null); // {viewerId, recordId, tabId}

  const DASH = '—';
  const DUE_SOON_DAYS = 7;

  onMount(load);

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchOverview();
      if (result?.success) {
        data = result.data;
      } else {
        error = translateError(result?.error) || t('overview.error');
      }
    } catch (err) {
      error = t('overview.error');
      console.error('Company overview load failed:', err);
    } finally {
      loading = false;
    }
  }

  const sections = $derived(data?.sections ?? []);

  /** ISO `YYYY-MM-DD` → lokální datum bez posunu časové zóny. */
  function parseIso(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
  }

  function formatDate(iso) {
    const d = parseIso(iso);
    if (!d) return DASH;
    const locale = language.current === 'en' ? 'en-US' : 'cs-CZ';
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(d);
  }

  /** Dny do termínu vůči datu sběru (`asOf`); záporné = po termínu. */
  function daysUntil(iso) {
    const due = parseIso(iso);
    const today = parseIso(data?.asOf);
    if (!due || !today) return null;
    return Math.round((due.getTime() - today.getTime()) / 86400000);
  }

  function dueClass(iso) {
    const days = daysUntil(iso);
    if (days === null) return '';
    if (days < 0) return 'shpd-overview__due--overdue';
    if (days <= DUE_SOON_DAYS) return 'shpd-overview__due--soon';
    return '';
  }

  function handleAction(action) {
    const target = action?.target ?? {};
    if (action?.kind === 'open_detail') {
      if (!target.viewerId || target.recordId == null) return;
      detailModal = { viewerId: target.viewerId, recordId: target.recordId, tabId: target.tabId ?? null };
    } else if (action?.kind === 'open_viewer') {
      if (!target.viewerId) return;
      navigationStore.navigateToViewer(
        target.viewerId,
        target.recordId ?? null,
        target.viewGroup ?? null,
        target.filters ?? null,
      );
    } else if (action?.kind === 'open_report') {
      if (!target.reportId) return;
      navigationStore.navigateToReport(target.reportId, target.params ?? null);
    }
  }
</script>

<div class="shpd-overview">
  <header class="shpd-overview__header">
    <h1 class="shpd-overview__title">
      <Icon icon={iconOverview} size="md" />
      <span>{t('overview.title')}</span>
    </h1>
    <div class="shpd-overview__header-right">
      {#if data?.asOf}
        <span class="shpd-overview__asof">{t('overview.asOf', { date: formatDate(data.asOf) })}</span>
      {/if}
      <Button variant="ghost" size="sm" icon={iconRefresh} onclick={load} disabled={loading}>
        {t('overview.refresh')}
      </Button>
    </div>
  </header>

  {#if loading && !data}
    <p class="shpd-overview__state">{t('overview.loading')}</p>
  {:else if error && !data}
    <div class="shpd-overview__state shpd-overview__state--error">
      <p>{error}</p>
      <Button variant="secondary" size="sm" onclick={load}>{t('overview.retry')}</Button>
    </div>
  {:else if sections.length === 0}
    <p class="shpd-overview__state">{t('overview.empty')}</p>
  {:else}
    <div class="shpd-overview__grid">
      {#each sections as section (section.id)}
        <section class="shpd-overview__card shpd-overview__card--{section.state}">
          <h2 class="shpd-overview__card-title">
            <span>{section.title}</span>
            {#if section.state === 'unavailable'}
              <span class="shpd-overview__badge shpd-overview__badge--warning">{t('overview.section.unavailable')}</span>
            {:else if section.state === 'error'}
              <span class="shpd-overview__badge shpd-overview__badge--danger">{t('overview.section.error')}</span>
            {/if}
          </h2>

          {#if section.items.length > 0}
            <ul class="shpd-overview__items">
              {#each section.items as item (item.id)}
                <li class="shpd-overview__item shpd-overview__item--{item.state}">
                  <div class="shpd-overview__item-main">
                    {#if item.link}
                      <button
                        type="button"
                        class="shpd-overview__label shpd-overview__label--link"
                        title={t('overview.item.open')}
                        onclick={() => handleAction(item.link)}
                      >
                        {item.label}
                      </button>
                    {:else}
                      <span class="shpd-overview__label">{item.label}</span>
                    {/if}
                    <span class="shpd-overview__amount">
                      {item.amount ? formatMoney(item.amount.value, item.amount.currency) : DASH}
                    </span>
                  </div>
                  <div class="shpd-overview__item-meta">
                    {#if item.asOf}
                      <span>{t('overview.item.asOf', { date: formatDate(item.asOf) })}</span>
                    {/if}
                    {#if item.dueDate}
                      <span class="shpd-overview__due {dueClass(item.dueDate)}">
                        {t('overview.item.dueDate', { date: formatDate(item.dueDate) })}
                      </span>
                    {/if}
                    {#if item.state === 'stale'}
                      <span class="shpd-overview__badge shpd-overview__badge--warning">{t('overview.state.stale')}</span>
                    {:else if item.state === 'missing'}
                      <span class="shpd-overview__badge shpd-overview__badge--danger">{t('overview.state.missing')}</span>
                    {/if}
                    {#if item.note}
                      <span class="shpd-overview__note">{item.note}</span>
                    {/if}
                  </div>
                </li>
              {/each}
            </ul>
          {/if}

          {#if section.messages.length > 0}
            <ul class="shpd-overview__messages">
              {#each section.messages as message, i (message.code + ':' + i)}
                <li class="shpd-overview__message shpd-overview__message--{message.severity}">
                  <span>{message.text}</span>
                  {#if message.link}
                    <button
                      type="button"
                      class="shpd-overview__message-link"
                      onclick={() => handleAction(message.link)}
                    >
                      {t('overview.item.open')}
                    </button>
                  {/if}
                </li>
              {/each}
            </ul>
          {/if}
        </section>
      {/each}
    </div>
  {/if}
</div>

<ViewerDetailModal
  open={detailModal !== null}
  viewerId={detailModal?.viewerId ?? ''}
  recordId={detailModal?.recordId ?? null}
  tabId={detailModal?.tabId ?? null}
  onClose={() => {
    detailModal = null;
  }}
/>

<style>
  .shpd-overview {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    min-height: 100%;
  }

  .shpd-overview__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    flex-wrap: wrap;
  }

  .shpd-overview__title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin: 0;
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  .shpd-overview__header-right {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
  }

  .shpd-overview__asof {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-overview__state {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-overview__state--error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--shpd-space-md);
    color: var(--shpd-color-danger);
  }

  .shpd-overview__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: var(--shpd-space-md);
    align-items: start;
  }

  .shpd-overview__card {
    padding: var(--shpd-space-md);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  .shpd-overview__card--unavailable {
    border-color: var(--shpd-color-warning);
  }

  .shpd-overview__card--error {
    border-color: var(--shpd-color-danger);
  }

  .shpd-overview__card-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    margin: 0 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
  }

  .shpd-overview__items,
  .shpd-overview__messages {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .shpd-overview__item {
    padding: var(--shpd-space-sm) 0;
    border-top: 1px solid var(--shpd-color-border-subtle, var(--shpd-color-border));
  }

  .shpd-overview__item-main {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
  }

  .shpd-overview__label {
    font-size: var(--shpd-font-size-base);
    color: var(--shpd-color-text);
    text-align: left;
  }

  .shpd-overview__label--link {
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    cursor: pointer;
    color: var(--shpd-color-primary, var(--shpd-color-text));
    text-decoration: underline;
    text-decoration-color: transparent;
    transition: text-decoration-color 0.15s;
  }

  .shpd-overview__label--link:hover,
  .shpd-overview__label--link:focus-visible {
    text-decoration-color: currentColor;
  }

  .shpd-overview__amount {
    font-size: var(--shpd-font-size-lg);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  .shpd-overview__item--missing .shpd-overview__amount {
    color: var(--shpd-color-text-secondary);
    font-weight: 400;
  }

  .shpd-overview__item-meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-sm);
    margin-top: 0.125rem;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-overview__due--soon {
    color: var(--shpd-color-warning);
    font-weight: 600;
  }

  .shpd-overview__due--overdue {
    color: var(--shpd-color-danger);
    font-weight: 600;
  }

  .shpd-overview__badge {
    display: inline-block;
    padding: 0 var(--shpd-space-xs);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    line-height: 1.5;
  }

  .shpd-overview__badge--warning {
    color: var(--shpd-color-warning);
    background: color-mix(in srgb, var(--shpd-color-warning) 14%, transparent);
  }

  .shpd-overview__badge--danger {
    color: var(--shpd-color-danger);
    background: color-mix(in srgb, var(--shpd-color-danger) 14%, transparent);
  }

  .shpd-overview__messages {
    margin-top: var(--shpd-space-sm);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-overview__message {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-left: 3px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    background: var(--shpd-color-bg-secondary);
  }

  .shpd-overview__message--info {
    border-left-color: var(--shpd-color-alert-info-bar, var(--shpd-color-border-strong));
  }

  .shpd-overview__message--warning {
    border-left-color: var(--shpd-color-warning);
  }

  .shpd-overview__message--error {
    border-left-color: var(--shpd-color-danger);
  }

  .shpd-overview__message-link {
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    font-size: var(--shpd-font-size-sm);
    cursor: pointer;
    color: var(--shpd-color-primary, var(--shpd-color-text));
    text-decoration: underline;
    white-space: nowrap;
  }
</style>
