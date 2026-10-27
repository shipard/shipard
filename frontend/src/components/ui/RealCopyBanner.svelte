<script>
  // Trvalý pruh „Kopie reálných dat" (#126 D12): server v režimu
  // development u zdroje dat s třídou realCopy (i výchozí bez třídy).
  // Renderuje ContentArea ve společném sticky kontejneru s ReadOnlyBanner
  // a CronOffBanner — pruhy se skládají pod sebe (sloučení řeší #128).
  // Vidí ho všichni přihlášení; v produkci server realCopy neposílá.
  // Vzhled dává sdílený `PageBanner` (#137 D41).
  import PageBanner from './PageBanner.svelte';
  import { t } from '../../i18n/index.js';
</script>

<PageBanner data-testid="real-copy-banner">{t('dsState.realCopy.banner')}</PageBanner>
