<script>
  // Read-only detail záznamu v modalu — třetí hostitel ViewerDetail (vedle
  // inline panelu ve Viewer.svelte a ViewerDetailDrawer). Otevírá ho dashboard
  // akce kind `open_detail`; uživatel zůstává na dashboardu (D1).
  //
  // Fetchuje GET /_ui/viewer/{viewerId}/detail/{recordId}. `toolbar`
  // z odpovědi se ignoruje celý a `onAction`/`onRefresh` se do ViewerDetail
  // nepředávají — modal je čistě čtecí (D3). Volitelný `tabId` ořeže detail
  // na jediný tab (D2); hlavička (title/subtitle/badges/icon) zůstává vždy.

  import Modal from '../ui/Modal.svelte';
  import ViewerDetail from './ViewerDetail.svelte';
  import { contentHasAttachments } from './detailContent.js';
  import { get } from '../../api/client.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    viewerId = '',
    recordId = null,
    tabId = null,
    onClose = () => {},
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let detail = $state(null);

  // Guard proti out-of-order odpovědím při přepnutí recordId bez zavření —
  // get() nemá AbortSignal, stale odpověď se zahazuje porovnáním tokenu.
  let fetchToken = 0;

  $effect(() => {
    if (open && viewerId && recordId !== null && recordId !== undefined) {
      void loadDetail(viewerId, recordId, tabId);
    } else {
      fetchToken++;
      detail = null;
      error = null;
      loading = false;
    }
  });

  async function loadDetail(vId, rId, tId) {
    const token = ++fetchToken;
    loading = true;
    error = null;
    detail = null;

    const result = await get(`/_ui/viewer/${vId}/detail/${rId}`);
    if (token !== fetchToken) return;

    if (result?.success) {
      // Akce detailu (Tisk, Přeúčtovat…) do čtecího modalu nepatří — bez
      // `onAction` by to byla mrtvá tlačítka.
      const { actions: _actions, ...d } = result.data?.detail ?? {};
      detail = tId ? restrictToTab(d, tId) : d;
    } else {
      error = t('viewer.detailModal.loadFailed', { msg: translateError(result?.error) });
    }
    loading = false;
  }

  // Ořez na jediný tab (D2). Sdílená sekce příloh a Odeslaná pošta patří
  // jen pod Přehled (první tab, #136 D21–D22) — ořezaný detail by je
  // ViewerDetail nakreslil pod cizím tabem, protože ten je teď první.
  // `attachments` zůstávají, když je ořezaný tab sám tabem Přílohy
  // (obsah typu `attachments`), jinak by neměl co ukázat.
  function restrictToTab(d, tId) {
    const allTabs = d.tabs ?? [];
    const tabs = allTabs.filter(tab => tab.id === tId);
    if (tId === allTabs[0]?.id) return { ...d, tabs };
    const { attachments, sentMessages: _sent, ...rest } = d;
    return tabs.some(tab => contentHasAttachments(tab.content))
      ? { ...rest, attachments, tabs }
      : { ...rest, tabs };
  }
</script>

<!-- Rozměry shodné s FormDialog — obsahové modaly mají v aplikaci
     jednotnou velikost (řešeno opakovaně, nezmenšovat). -->
<Modal
  title={t('viewer.detailModal.title')}
  {open}
  {onClose}
  width="clamp(1200px, 80vw, 1700px)"
  height="clamp(720px, 88vh, 1100px)"
  flush
>
  {#if error}
    <div class="shpd-detail-modal__error">{error}</div>
  {:else}
    <div class="shpd-detail-modal__body">
      <ViewerDetail {detail} {loading} hideSingleTabBar />
    </div>
  {/if}
</Modal>

<style>
  /* Stejný výškový kontrakt jako .shpd-drawer__body — ViewerDetail má
     height: 100% a scroll si řeší uvnitř (.shpd-detail__content). */
  .shpd-detail-modal__body {
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .shpd-detail-modal__error {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
    min-height: 200px;
  }
</style>
