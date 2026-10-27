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
  import Card from '../ui/Card.svelte';
  import { iconWarning, iconInfo } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { failure, variant = 'error' } = $props();

  const VARIANTS = ['error', 'warning', 'info'];
  const safeVariant = $derived(VARIANTS.includes(variant) ? variant : 'error');

  // Tón sdílené karty (#137 D33): tón = barva rámečku, levý pruh zanikl.
  const TONE = { error: 'danger', warning: 'warning', info: 'info' };

  const hasTechnical = $derived(
    Boolean(failure?.technical || failure?.analyzedAt || failure?.promptVersion || failure?.finishedAt),
  );
</script>

{#if failure}
  <!-- Sdílená Card s tónem podle varianty; vnější okraj karta nemá, mezeru
       dává rodič (.shpd-extracted má gap, v kartě návrhu obal __failure). -->
  <Card
    as="div"
    tone={TONE[safeVariant]}
    data-kind={failure.kind}
    data-variant={safeVariant}
    role="status"
  >
    <div class="shpd-failure-card__header shpd-failure-card__header--{safeVariant}">
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
  </Card>
{/if}

<style>
  /* Rámeček, zaoblení, pozadí a odsazení dává sdílená Card; tón karty
   * (danger / warning / info) je barva rámečku, u info tónované pozadí
   * (#137 D33). Barva hlavičky per varianta: error = tokeny stavu error
   * (stejný stav jako shpd-extracted__badge--error), warning = varovná
   * barva, info = neutrální confirmed tokeny (samostatný info token není).
   * Tělo karty zůstává v běžném textu. */
  .shpd-failure-card__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-failure-card__header--warning {
    color: var(--shpd-color-warning);
  }

  .shpd-failure-card__header--info {
    color: var(--shpd-color-state-confirmed-text);
  }

  .shpd-failure-card__title {
    font-weight: var(--shpd-font-weight-strong);
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
    font-size: var(--shpd-font-size-xs);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-failure-card__tech summary {
    cursor: pointer;
    user-select: none;
  }

  .shpd-failure-card__dl {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 0.125rem var(--shpd-space-md);
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
    font-size: 0.6875rem;
  }
</style>
