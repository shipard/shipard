<script>
  // Trvalý pruh „Periodické úlohy jsou vypnuté" (#115 D11). Renderuje
  // ContentArea ve společném sticky kontejneru s ReadOnlyBanner — oba
  // pruhy se skládají pod sebe, ne přes sebe. Vidí ho všichni přihlášení.
  import { t } from '../../i18n/index.js';
</script>

<div class="shpd-cronoff-banner" role="status" data-testid="cron-off-banner">
  {t('dsState.cronOff.banner')}
</div>

<style>
  .shpd-cronoff-banner {
    padding: var(--shpd-space-xs) var(--shpd-space-lg);
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-bottom: 2px solid var(--shpd-color-alert-warning-bar);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    text-align: center;
  }
</style>
