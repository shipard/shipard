<script>
  import { router, onLinkClick } from '../router.svelte.js';
  import { t } from '../i18n/index.js';
</script>

<div class="dev-page__head">
  <h1>{t('notFound.title')}</h1>
</div>
<div class="dev-card">
  <p>{t('notFound.text')}</p>
  <p><a href={router.href('dataSources')} onclick={onLinkClick}>{t('common.backToDataSources')}</a></p>
</div>
