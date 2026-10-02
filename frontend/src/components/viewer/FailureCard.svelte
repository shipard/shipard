<script>
  // Karta selhani - sdilena komponenta (tasks/mail-analysis-error-messages.md
  // D3a, D5; tasks/mail-preprocess-error-messages.md D6). Varianty:
  //   error   (vychozi) - selhana AI analyza: tab Navrh misto prazdneho stavu
  //             (content.failure), u navrhu s nevalidnim vystupem uvnitr
  //             karty navrhu (proposal.failure)
  //   warning - selhane predzpracovani (stav "Hotovo s chybami"): tab Obsah
  //             nad technickym blokem Predzpracovani; tab Navrh jako
  //             upozorneni nad navrhem (jen title + description)
  //   info    - jen informace (selhany import ISDOC): tab Obsah
  // Texty hlasky posila server z katalogu (core.mail.analysisErrorKinds /
  // core.mail.preprocessErrorKinds) uz v jazyce requestu; tady jsou jen
  // staticke popisky.
  //
  // `technical` je puvodni technicka hlaska - u analyzy muze nest hodnoty
  // z dokladu, u predzpracovani URL s tokeny - proto jen sbalena
  // v technickych podrobnostech.
  //
  // Props:
  //   failure  {kind, title, description, detail?, hint?, technical?,
  //             reanalysisRecommended?, analyzedAt?, promptVersion?, finishedAt?}
  //   variant  'error' | 'warning' | 'info' (default 'error')
  import Icon from '../ui/Icon.svelte';
  import { iconWarning, iconInfo } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { failure, variant = 'error' } = $props();

  const VARIANTS = ['error', 'warning', 'info'];
  const safeVariant = $derived(VARIANTS.includes(variant) ? variant : 'error');

  const hasTechnical = $derived(
    Boolean(failure?.technical || failure?.analyzedAt || failure?.promptVersion || failure?.finishedAt),
  );
</script>

{#if failure}
  <div
    class="shpd-failure-card shpd-failure-card--{safeVariant}"
    data-kind={failure.kind}
    data-variant={safeVariant}
    role="status"
  >
    <div class="shpd-failure-card__header">
      <Icon icon={safeVariant === 'info' ? iconInfo : iconWarning} size="sm" class="shpd-failure-card__icon" />
      <span class="shpd-failure-card__title">{failure.title}</span>
    </div>

    <p class="shpd-failure-card__text">
      {failure.description}{#if failure.detail}{' '}{failure.detail}{/if}
    </p>

    {#if failure.hint}
      <p
        class="shpd-failure-card__hint"
        class:shpd-failure-card__hint--recommended={failure.reanalysisRecommended}
      >
        {failure.hint}
      </p>
    {/if}

    {#if hasTechnical}
      <details class="shpd-failure-card__tech">
        <summary>{t('viewer.failure.technical')}</summary>
        <dl class="shpd-failure-card__dl">
          {#if failure.analyzedAt}
            <dt>{t('viewer.failure.analyzedAt')}</dt>
            <dd>{failure.analyzedAt}</dd>
          {/if}
          {#if failure.finishedAt}
            <dt>{t('viewer.failure.finishedAt')}</dt>
            <dd>{failure.finishedAt}</dd>
          {/if}
          {#if failure.promptVersion}
            <dt>{t('viewer.failure.prompt')}</dt>
            <dd>{failure.promptVersion}</dd>
          {/if}
          {#if failure.technical}
            <dt>{t('viewer.failure.message')}</dt>
            <dd><code class="shpd-failure-card__code">{failure.technical}</code></dd>
          {/if}
        </dl>
      </details>
    {/if}
  </div>
{/if}

<style>
  /* Barvy pres CSS promenne stavu per varianta: error = stejne tokeny jako
   * shpd-extracted__badge--error (stejny stav, stejna barva); warning =
   * varovna barva + jemny amber ram (concept tokeny, warning-soft je na
   * svetlem pozadi neviditelny); info = neutralni confirmed tokeny
   * (samostatny info token neni). Telo karty zustava v beznem textu. */
  .shpd-failure-card {
    --shpd-failure-accent: var(--shpd-color-state-error-text);
    --shpd-failure-border: var(--shpd-color-state-error-bg);

    border: 1px solid var(--shpd-failure-border);
    border-left: 4px solid var(--shpd-failure-accent);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg);
    padding: var(--shpd-space-md);
    margin-bottom: var(--shpd-space-md);
    text-align: left;
  }

  .shpd-failure-card--warning {
    --shpd-failure-accent: var(--shpd-color-warning);
    --shpd-failure-border: var(--shpd-color-state-concept-bg);
  }

  .shpd-failure-card--info {
    --shpd-failure-accent: var(--shpd-color-state-confirmed-text);
    --shpd-failure-border: var(--shpd-color-state-confirmed-bg);
  }

  .shpd-failure-card__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    color: var(--shpd-failure-accent);
  }

  .shpd-failure-card__title {
    font-weight: 600;
  }

  .shpd-failure-card__text {
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-failure-card__hint {
    margin: var(--shpd-space-sm) 0 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-failure-card__hint--recommended {
    color: var(--shpd-color-text);
    font-weight: 500;
  }

  .shpd-failure-card__tech {
    margin-top: var(--shpd-space-sm);
    font-size: 12px;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-failure-card__tech summary {
    cursor: pointer;
    user-select: none;
  }

  .shpd-failure-card__dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 2px var(--shpd-space-md);
    margin: var(--shpd-space-sm) 0 0;
  }

  .shpd-failure-card__dl dt {
    font-weight: 500;
  }

  .shpd-failure-card__dl dd {
    margin: 0;
    min-width: 0;
  }

  .shpd-failure-card__code {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 11px;
  }
</style>
