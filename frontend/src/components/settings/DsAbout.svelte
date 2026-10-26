<script>
  // Panel „O zdroji dat" (nav item type 'panel', panelId dsAbout):
  // read-only agregace identity, charakteristiky a velikostí DS
  // z GET /_ui/ds-about (tasks/ds-about-panel.md, Issue #41).
  // Žádné akce ani nebezpečná tlačítka (D3); bloky seřazené podle
  // důležitosti (D5): identita → charakteristika → velikosti a počty.
  import { onMount } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Icon from '../ui/Icon.svelte';
  import Card from '../ui/Card.svelte';
  import { iconInfo } from '../../icons.js';
  import { fetchDsAbout } from '../../api/dsAbout.js';
  import { formatFileSize } from '../../api/attachments.js';

  let loading = $state(true);
  let error = $state(null);
  let data = $state(null);

  const DASH = '—';

  onMount(load);

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchDsAbout();
      if (result?.success) {
        data = result.data;
      } else {
        error = translateError(result?.error);
      }
    } catch (err) {
      error = t('dsAbout.error.loadFailed');
      console.error('DS about load failed:', err);
    } finally {
      loading = false;
    }
  }

  const identity = $derived(data?.identity ?? {});
  const profile = $derived(data?.profile ?? {});
  const storage = $derived(data?.storage ?? {});
  const counts = $derived(storage.counts ?? {});
  const attachments = $derived(storage.attachments ?? null);

  function locale() {
    return document.documentElement.lang || 'cs';
  }

  /** Text nebo pomlčka — chybějící údaj nikdy nevykreslí null/undefined. */
  function text(value) {
    return value === null || value === undefined || value === '' ? DASH : String(value);
  }

  function formatCount(value) {
    return new Intl.NumberFormat(locale()).format(Number(value ?? 0));
  }

  function toDate(iso) {
    const d = iso ? new Date(iso) : null;
    return d && !Number.isNaN(d.getTime()) ? d : null;
  }

  function formatDate(iso) {
    const d = toDate(iso);
    return d ? new Intl.DateTimeFormat(locale(), { dateStyle: 'medium' }).format(d) : DASH;
  }

  function formatDateTime(iso) {
    const d = toDate(iso);
    return d
      ? new Intl.DateTimeFormat(locale(), { dateStyle: 'medium', timeStyle: 'short' }).format(d)
      : DASH;
  }

  function vatLabel(p) {
    if (!p.vatPayer) return t('dsAbout.vat.nonPayer');
    return p.taxpayerKindLabel
      ? `${t('dsAbout.vat.payer')} (${p.taxpayerKindLabel})`
      : t('dsAbout.vat.payer');
  }

  // Sdílené klíče se Setup panelem — stejný slovník pro tutéž věc.
  function chartLabel(variant) {
    return variant === 'default' || variant === 'npo' || variant === 'none'
      ? t(`setup.chart.${variant}`)
      : t('setup.undecided');
  }
</script>

<div class="shpd-ds-about">
  <!-- Karta stránky Nastavení = sdílená Card s odsazením lg (#137 D33, D35). -->
  <Card spacious>
    <h2 class="shpd-ds-about__title">
      <Icon icon={iconInfo} size="md" />
      <span>{t('dsAbout.title')}</span>
    </h2>
    <p class="shpd-ds-about__intro">{t('dsAbout.intro')}</p>

    {#if loading}
      <p class="shpd-ds-about__state">{t('common.loading')}</p>
    {:else if error}
      <p class="shpd-ds-about__state shpd-ds-about__state--error">{error}</p>
    {:else}
      <section class="shpd-ds-about__block">
        <h3 class="shpd-ds-about__block-title">{t('dsAbout.block.identity')}</h3>
        <dl class="shpd-ds-about__list">
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.dsName')}</dt>
            <dd>{text(identity.dsName)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.ownPerson')}</dt>
            {#if identity.ownPerson}
              <dd>{text(identity.ownPerson.fullName)}</dd>
            {:else}
              <dd class="shpd-ds-about__muted">{t('dsAbout.notSet')}</dd>
            {/if}
          </div>
          {#if identity.ownPerson}
            <div class="shpd-ds-about__row">
              <dt>{t('dsAbout.field.companyId')}</dt>
              <dd class="shpd-ds-about__mono">{text(identity.ownPerson.companyId)}</dd>
            </div>
            <div class="shpd-ds-about__row">
              <dt>{t('dsAbout.field.taxId')}</dt>
              <dd class="shpd-ds-about__mono">{text(identity.ownPerson.taxId)}</dd>
            </div>
          {/if}
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.mailAddress')}</dt>
            <dd>{text(identity.mailAddress)}</dd>
          </div>
        </dl>
      </section>

      <section class="shpd-ds-about__block">
        <h3 class="shpd-ds-about__block-title">{t('dsAbout.block.profile')}</h3>
        <dl class="shpd-ds-about__list">
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.vat')}</dt>
            <dd>{vatLabel(profile)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.accountChart')}</dt>
            <dd>{chartLabel(profile.accountChart)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.dsId')}</dt>
            <dd class="shpd-ds-about__mono">{text(profile.dsId)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.created')}</dt>
            <dd>{formatDate(profile.created)}</dd>
          </div>
        </dl>
      </section>

      <section class="shpd-ds-about__block">
        <h3 class="shpd-ds-about__block-title">{t('dsAbout.block.storage')}</h3>
        <dl class="shpd-ds-about__list">
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.databaseSize')}</dt>
            <dd>{formatFileSize(Number(storage.databaseBytes ?? 0))}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.attachmentsSize')}</dt>
            <dd>
              {formatFileSize(Number(attachments?.bytes ?? 0))}
              <span class="shpd-ds-about__muted">
                · {t('dsAbout.attachments.files', { count: Number(attachments?.files ?? 0) })}
                {#if attachments?.computedAt}
                  · {t('dsAbout.attachments.computedAt', { time: formatDateTime(attachments.computedAt) })}
                {/if}
              </span>
            </dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.documents')}</dt>
            <dd>{formatCount(counts.documents)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.incomingMail')}</dt>
            <dd>{formatCount(counts.incomingMail)}</dd>
          </div>
          <div class="shpd-ds-about__row">
            <dt>{t('dsAbout.field.attachmentFiles')}</dt>
            <dd>{formatCount(counts.attachmentFiles)}</dd>
          </div>
        </dl>
      </section>
    {/if}
  </Card>
</div>

<style>
  .shpd-ds-about {
    padding: var(--shpd-space-lg);
    max-width: 760px;
  }

  .shpd-ds-about__title {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-ds-about__intro {
    margin-bottom: var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-ds-about__state {
    color: var(--shpd-color-text-secondary);
  }

  .shpd-ds-about__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-ds-about__block + .shpd-ds-about__block {
    margin-top: var(--shpd-space-lg);
    padding-top: var(--shpd-space-lg);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-ds-about__block-title {
    margin-bottom: var(--shpd-space-sm);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  /* Definiční seznam jako dvousloupcová mřížka: popisek | hodnota. */
  .shpd-ds-about__list {
    display: grid;
    grid-template-columns: minmax(10rem, 14rem) 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
    margin: 0;
  }

  .shpd-ds-about__row {
    display: contents;
  }

  .shpd-ds-about__row dt {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    line-height: 1.5;
  }

  .shpd-ds-about__row dd {
    margin: 0;
    color: var(--shpd-color-text);
    line-height: 1.5;
    overflow-wrap: anywhere;
  }

  .shpd-ds-about__mono {
    font-family: var(--shpd-font-mono, monospace);
  }

  .shpd-ds-about__muted {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  @media (max-width: 600px) {
    .shpd-ds-about__list {
      grid-template-columns: 1fr;
    }

    .shpd-ds-about__row dd {
      margin-bottom: var(--shpd-space-sm);
    }
  }
</style>
