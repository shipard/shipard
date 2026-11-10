<script>
  // Stavová obrazovka zavřeného DS (#56 fáze 2, R4): SPA se načte vždy
  // (nginx ji servíruje staticky), boot GET /_app/info vrátí 503
  // DS_UNAVAILABLE a tohle nahradí login/aplikaci. DS config se nečte,
  // takže žádný název/logo — generický Shipard vizuál. Maintenance se
  // z 503 nerozlišuje (details nesou efektivní stav `suspended`), jeden
  // text pro všechny zavřené stavy.
  import { t } from '../../i18n/index.js';

  function retry() {
    window.location.reload();
  }
</script>

<div class="shpd-status" data-testid="status-screen">
  <div class="shpd-status__card">
    <h1 class="shpd-status__heading">{t('dsState.unavailable.title')}</h1>
    <p class="shpd-status__text">{t('dsState.unavailable.text')}</p>
    <button type="button" class="shpd-status__retry" onclick={retry} data-testid="status-retry">
      {t('dsState.unavailable.retry')}
    </button>
  </div>
</div>

<style>
  .shpd-status {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background-color: var(--shpd-color-bg-secondary);
  }

  .shpd-status__card {
    width: 100%;
    max-width: 25rem;
    padding: var(--shpd-space-xl);
    background-color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-lg);
    box-shadow: var(--shpd-shadow-md);
    text-align: center;
  }

  .shpd-status__heading {
    margin: 0 0 var(--shpd-space-md);
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  .shpd-status__text {
    margin: 0 0 var(--shpd-space-lg);
    color: var(--shpd-color-text-secondary);
    line-height: 1.5;
  }

  .shpd-status__retry {
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    border: none;
    border-radius: var(--shpd-radius-md);
    background: var(--shpd-color-primary);
    color: #fff;
    font: inherit;
    font-weight: 600;
    cursor: pointer;
  }

  .shpd-status__retry:hover {
    background: var(--shpd-color-primary-hover, var(--shpd-color-primary));
  }
</style>
