<script>
  // Trvalý pruh „Periodické úlohy jsou vypnuté" (#115 D11). Renderuje
  // ContentArea ve společném sticky kontejneru s ReadOnlyBanner — oba
  // pruhy se skládají pod sebe, ne přes sebe. Vidí ho všichni přihlášení.
  // Vzhled dává sdílený `PageBanner` (#137 D41).
  import PageBanner from './PageBanner.svelte';
  import { t } from '../../i18n/index.js';
</script>

<PageBanner data-testid="cron-off-banner">{t('dsState.cronOff.banner')}</PageBanner>
