<script>
  // Karta selhani AI analyzy zpravy (tasks/mail-analysis-error-messages.md
  // D3a, D5). Kresli se v tabu Navrh: pri stavu "Analyza selhala" misto
  // prazdneho stavu (content.failure), u navrhu s nevalidnim vystupem
  // uvnitr karty navrhu (proposal.failure). Texty hlasky posila server
  // z katalogu core.mail.analysisErrorKinds (AnalysisErrorPresenter) uz
  // v jazyce requestu; tady jsou jen staticke popisky.
  //
  // `technical` je puvodni chybova hlaska analyzeru a muze nest hodnoty
  // z dokladu - proto jen sbalena v technickych podrobnostech.
  //
  // Props:
  //   failure  {kind, title, description, detail, hint,
  //             reanalysisRecommended, technical, analyzedAt, promptVersion}
  import Icon from '../ui/Icon.svelte';
  import { iconWarning } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { failure } = $props();

  const hasTechnical = $derived(
    Boolean(failure?.technical || failure?.analyzedAt || failure?.promptVersion),
  );
</script>

{#if failure}
  <div class="shpd-analysis-failure" data-kind={failure.kind} role="status">
    <div class="shpd-analysis-failure__header">
      <Icon icon={iconWarning} size="sm" class="shpd-analysis-failure__icon" />
      <span class="shpd-analysis-failure__title">{failure.title}</span>
    </div>

    <p class="shpd-analysis-failure__text">
      {failure.description}{#if failure.detail}{' '}{failure.detail}{/if}
    </p>

    {#if failure.hint}
      <p
        class="shpd-analysis-failure__hint"
        class:shpd-analysis-failure__hint--recommended={failure.reanalysisRecommended}
      >
        {failure.hint}
      </p>
    {/if}

    {#if hasTechnical}
      <details class="shpd-analysis-failure__tech">
        <summary>{t('viewer.failure.technical')}</summary>
        <dl class="shpd-analysis-failure__dl">
          {#if failure.analyzedAt}
            <dt>{t('viewer.failure.analyzedAt')}</dt>
            <dd>{failure.analyzedAt}</dd>
          {/if}
          {#if failure.promptVersion}
            <dt>{t('viewer.failure.prompt')}</dt>
            <dd>{failure.promptVersion}</dd>
          {/if}
          {#if failure.technical}
            <dt>{t('viewer.failure.message')}</dt>
            <dd><code class="shpd-analysis-failure__code">{failure.technical}</code></dd>
          {/if}
        </dl>
      </details>
    {/if}
  </div>
{/if}

<style>
  /* Stejne tokeny error stavu jako shpd-extracted__badge--error -
   * stejny stav, stejna barva; telo karty zustava v beznem textu. */
  .shpd-analysis-failure {
    border: 1px solid var(--shpd-color-state-error-bg);
    border-left: 4px solid var(--shpd-color-state-error-text);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-bg);
    padding: var(--shpd-space-md);
    margin-bottom: var(--shpd-space-md);
    text-align: left;
  }

  .shpd-analysis-failure__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-analysis-failure__title {
    font-weight: 600;
  }

  .shpd-analysis-failure__text {
    margin: 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  .shpd-analysis-failure__hint {
    margin: var(--shpd-space-sm) 0 0;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-analysis-failure__hint--recommended {
    color: var(--shpd-color-text);
    font-weight: 500;
  }

  .shpd-analysis-failure__tech {
    margin-top: var(--shpd-space-sm);
    font-size: 12px;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-analysis-failure__tech summary {
    cursor: pointer;
    user-select: none;
  }

  .shpd-analysis-failure__dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 2px var(--shpd-space-md);
    margin: var(--shpd-space-sm) 0 0;
  }

  .shpd-analysis-failure__dl dt {
    font-weight: 500;
  }

  .shpd-analysis-failure__dl dd {
    margin: 0;
    min-width: 0;
  }

  .shpd-analysis-failure__code {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font-size: 11px;
  }
</style>
