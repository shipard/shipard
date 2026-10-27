<script>
  // Trvalý pruh „Zdroj dat je jen pro čtení" (#56 fáze 2). Renderuje
  // ContentArea nad obsahem — sdílí ho všechny shelly, banner je tak na
  // jednom místě. Server zápisy odmítá 403, pruh dává srozumitelnost.
  // Vzhled dává sdílený `PageBanner` (#137 D41); sticky pozici drží
  // kontejner `.shpd-content__banners` v ContentArea (společný
  // s RealCopyBanner a CronOffBanner), ne pruh sám.
  import PageBanner from './PageBanner.svelte';
  import { t } from '../../i18n/index.js';
</script>

<PageBanner data-testid="readonly-banner">{t('dsState.readOnly.banner')}</PageBanner>
