<script>
  // Trvalý pruh „Kopie reálných dat" (#126 D12): server v režimu
  // development u zdroje dat s třídou realCopy (i výchozí bez třídy).
  // Renderuje ContentArea ve společném sticky kontejneru s ReadOnlyBanner
  // a CronOffBanner — pruhy se skládají pod sebe (sloučení řeší #128).
  // Vidí ho všichni přihlášení; v produkci server realCopy neposílá.
  import { t } from '../../i18n/index.js';
</script>

<div class="shpd-realcopy-banner" role="status" data-testid="real-copy-banner">
  {t('dsState.realCopy.banner')}
</div>

<style>
  .shpd-realcopy-banner {
    padding: var(--shpd-space-xs) var(--shpd-space-lg);
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-bottom: 2px solid var(--shpd-color-alert-warning-bar);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    text-align: center;
  }
</style>
