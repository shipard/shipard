<script>
  // Full-screen modal hosting the PDF + canonical preview split-view.
  //
  // Message-centric UX flow (tasks/mail-message-centric.md):
  //   - User clicks "Detail" / "Zkontrolovat" on the message's proposal
  //   - This modal opens, calls previewMessage(messageNdx)
  //   - Left: PDF / image attachments via PdfViewerPanel — all content
  //     attachments of the message (D10)
  //   - Right: canonical visualization via DocumentExchangePreview
  //   - Footer: Zavřít / Zamítnout / Vystavit koncept / Vystavit a uzavřít
  //     (registry target: single Zařadit); both applies disabled for
  //     ai_failed / undecided references.
  //
  // Apply / reject delegate to parent callbacks — parent still owns the
  // actual API call (Dashboard / ViewerDetail).
  //
  // Resolve decisions:
  //   - `userActions` state accumulates the resolve-decision choices from
  //     clickable badges in DocumentExchangePreview.
  //   - `canApply` is true only when all non-matched references have a
  //     decision (or are explicitly skipped). Unit badges don't gate apply
  //     (applier default). A row whose VAT code is `notFound` needs a
  //     `rows[i].vatCode` choice from `_resolve.vatCodeOptions` — the applier
  //     no longer falls back (tasks/exchange-preview-vat-choices.md D17).
  //   - Both apply buttons pass `userActions` and the doc target ('docs' /
  //     'registry') to onApply — parents branch post-apply UX on it.
  //     „Vystavit a uzavřít“ adds applyOptions {targetDocState: 40} as the
  //     4th argument (document goes directly to V pořádku, no FormDialog).
  //
  // Persistence rozhodnutí (tasks/mail-review-decisions-persist.md, #76):
  //   - Každá změna `userActions` (handleUserActionsChange, včetně „Zrušit
  //     výběr") se okamžitě uloží na server — POST /decisions s celou mapou,
  //     na pozadí, bez debounce (hromadné rozhodnutí = jeden callback).
  //   - previewMessage vrací `userActions` uložené k poslední analýze;
  //     loadPreview jimi inicializuje stav místo {}.
  //   - Modal se zavírá volně. ConfirmDialog „Neuložená rozhodnutí" se ukáže
  //     JEN když uložení právě běží nebo selhalo (pendingSave || saveError)
  //     — pro Esc, overlay, „×", Zavřít i Přeskočit. Zamítnout a apply se
  //     neguardují: apply posílá mapu v `_resolve` nezávisle na persistenci.
  //   - Jediné místo, které volá persist(), je handleUserActionsChange.
  //     Reset stavu při zavření / změně zprávy ukládat NESMÍ (poslal by {}
  //     a smazal rozhodnutí na serveru).
  //   - saveSeq (ne-reaktivní čítač) řeší závod odpovědí: starší pomalejší
  //     odpověď nesmí přepsat pendingSave / saveError.
  //
  // Volby DPH (tasks/exchange-preview-vat-choices.md D15, #87 B):
  //   - změna cesty `vat.*` / `rows[i].vatCode` → po ÚSPĚŠNÉM uložení nový
  //     náhled (refreshPreview): server čte uložená rozhodnutí, refresh
  //     před dokončením POST /decisions by ukázal stav bez volby; selhání
  //     uložení = žádný refresh (saveError zůstává).
  //   - refreshPreview nemění userActions ani loading (modal nebliká,
  //     rozhodnutí zůstávají); refreshSeq zahodí starší odpověď / jinou zprávu.
  //   - rozhodnutí o stranách a položkách nový náhled nespouštějí.
  //
  // Zdrojová zpráva v hlavičce (tasks/mail-source-message-link.md D1–D3):
  //   - subtitle „Došlá zpráva #YYMMDD-NNNN · datum · odesílatel" z bloku
  //     `message` preview endpointu (krátký kód i datum formátuje server,
  //     plný kód je v tooltipu — D8, D9).
  //   - s `onOpenMessage` je kód tlačítko: hostitel otevře read-only detail
  //     zprávy NAD tímto modalem (Dashboard → jeho ViewerDetailModal; stack
  //     v Modal.svelte pošle Esc jen hornímu). Bez callbacku (review
  //     z detailu téže zprávy v Došlé poště) je kód prostý text (D2).
  //   - snippet se předává po celou dobu `open`, obsah až s `data.message`
  //     — jinak by ve frontovém režimu badge počítadla skákal mezi titulkem
  //     a subtitle řádkem při načítání další zprávy.
  //
  // Mobile (<768px): single column with PDF/Preview tab switcher.

  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import ConfirmDialog from '../ui/ConfirmDialog.svelte';
  import DocumentExchangePreview from './DocumentExchangePreview.svelte';
  import RegistryExtractedPreview from './RegistryExtractedPreview.svelte';
  import PdfViewerPanel from './PdfViewerPanel.svelte';
  import { previewMessage, saveDecisions } from '../../api/exchange.js';
  import { t } from '../../i18n/index.js';

  let {
    open = false,
    messageNdx = null,
    // Batch mód „Projít frontu" (tasks/dashboard-queue-walkthrough.md):
    // {index, total} | null. Zapíná počítadlo v hlavičce a Přeskočit
    // v patičce; apply/reject delegace se nemění — posun na další zprávu
    // řídí rodič změnou messageNdx (reload zajistí $effect níž).
    queue = null,
    onClose = () => {},
    onApply = () => {},
    onReject = () => {},
    onSkip = () => {},
    // `(messageNdx) => void` | null — viz hlavička, Zdrojová zpráva.
    onOpenMessage = null,
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let data = $state(null);
  let mobileTab = $state('pdf'); // 'pdf' | 'preview'

  // Accumulated decisions from clickable status badges. Flat
  // {path: action} map — see api/exchange.js applyMessage.
  let userActions = $state({});

  // Persistence rozhodnutí (#76) — viz hlavička.
  let pendingSave = $state(false);
  let saveError = $state(false);
  // Akce čekající na potvrzení „Neuložená rozhodnutí" (vzor FormDialog).
  // Non-null = ConfirmDialog otevřený; Zahodit ji spustí, Zůstat zruší.
  let pendingAction = $state(null);
  // Sekvence POST /decisions — poslední odpověď vyhrává, starší se ignorují.
  // Ne-reaktivní: nic se na něj nevykresluje.
  let saveSeq = 0;
  // Sekvence refreshe náhledu po volbě DPH (D15) — totéž pravidlo.
  let refreshSeq = 0;

  const VAT_CHOICE_PATH_RE = /^(vat\.(place|mode)|rows\[\d+\]\.vatCode)$/;

  $effect(() => {
    if (open && messageNdx !== null && messageNdx !== undefined) {
      void loadPreview(messageNdx);
    } else {
      data = null;
      error = null;
      userActions = {};
      resetDecisionState();
    }
  });

  // Reset persistence při zavření i při změně zprávy. Batch mód: `open`
  // zůstává true a mění se jen messageNdx → větev else efektu neproběhne,
  // proto i na začátku loadPreview. saveSeq++ zneplatní dobíhající odpověď
  // předchozí zprávy. Nikdy neukládá.
  function resetDecisionState() {
    pendingSave = false;
    saveError = false;
    pendingAction = null;
    saveSeq++;
    refreshSeq++;
  }

  async function loadPreview(ndx) {
    loading = true;
    error = null;
    data = null;
    userActions = {};
    resetDecisionState();
    try {
      const result = await previewMessage(ndx);
      if (result?.success) {
        data = result.data;
        // Uložená rozhodnutí z poslední analýzy — předvyplní badge (D8).
        userActions = result.data.userActions ?? {};
      } else {
        error = result?.error?.message ?? 'Unknown error';
      }
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  function handleUserActionsChange(next) {
    const refresh = vatChoiceChanged(userActions, next);
    userActions = next;
    void persist(next).then((saved) => {
      if (saved && refresh) void refreshPreview();
    });
  }

  function vatChoiceChanged(prev, next) {
    for (const key of new Set([...Object.keys(prev), ...Object.keys(next)])) {
      if (VAT_CHOICE_PATH_RE.test(key) && (prev[key] ?? null) !== (next[key] ?? null)) return true;
    }
    return false;
  }

  // Autosave celé mapy na pozadí. Klient drží správnou mapu i při selhání —
  // apply ji pošle v `_resolve` nezávisle na persistenci; další změna
  // uložení zopakuje. Vrací true jen pro úspěšně uložený poslední stav
  // (překonané uložení = false, refresh udělá to novější).
  async function persist(map) {
    const seq = ++saveSeq;
    const ndx = messageNdx;
    pendingSave = true;
    let result;
    try {
      result = await saveDecisions(ndx, map);
    } catch {
      result = null;
    }
    if (seq !== saveSeq) return false; // překonáno novějším uložením nebo resetem
    pendingSave = false;
    saveError = !result?.success;
    return !saveError;
  }

  // Nový náhled po volbě DPH — bez resetu userActions a bez loading.
  async function refreshPreview() {
    const seq = ++refreshSeq;
    const ndx = messageNdx;
    let result;
    try {
      result = await previewMessage(ndx);
    } catch {
      result = null;
    }
    if (seq !== refreshSeq || ndx !== messageNdx) return;
    if (result?.success) {
      data = result.data;
    }
  }

  // Guard zavření / přeskočení — jen když uložení běží nebo selhalo.
  let unsafeToClose = $derived(pendingSave || saveError);

  function guardClose(then) {
    if (!unsafeToClose) {
      then();
      return;
    }
    pendingAction = then;
  }

  function discardPending() {
    const run = pendingAction;
    pendingAction = null;
    saveError = false;
    run?.();
  }

  function stayPending() {
    pendingAction = null;
  }

  // Modal volá onClose pro Esc, overlay i „×" — guard stačí na jednom místě.
  const handleClose = () => guardClose(onClose);
  const handleSkip = () => guardClose(onSkip);

  // Walk `_resolve` and verify every non-matched reference has a decision.
  // Unit badges are excluded (applier default). A row whose VAT code is
  // notFound needs a `useCode:` choice from vatCodeOptions (D17) — a stale
  // choice outside the offer would end in validation_failed on apply.
  function allDecided(resolve, ua) {
    if (!resolve) return true;
    const vatCodes = new Set((resolve.vatCodeOptions ?? []).map((o) => o.code));
    for (const key of ['supplier', 'customer', 'supplierBank', 'customerBank']) {
      const block = resolve[key];
      if (!block) continue;
      if (block.status === 'matched') continue;
      if (ua[key] !== undefined && ua[key] !== null) continue;
      return false;
    }
    const rows = resolve.rows ?? [];
    for (let i = 0; i < rows.length; i++) {
      const itemBlock = rows[i]?.item;
      if (!itemBlock) continue;
      if (itemBlock.status === 'matched') continue;
      const p = `rows[${i}].item`;
      if (ua[p] !== undefined && ua[p] !== null) continue;
      return false;
    }
    for (let i = 0; i < rows.length; i++) {
      if (rows[i]?.vatCode?.status !== 'notFound') continue;
      const chosen = ua[`rows[${i}].vatCode`];
      if (typeof chosen === 'string' && chosen.startsWith('useCode:')
          && vatCodes.has(chosen.slice('useCode:'.length))) continue;
      return false;
    }
    return true;
  }

  // Registry target (Spisovna) — server posílá `target: 'registry'`;
  // preview je kompaktní bez resolve panelu, apply se negatuje jen ai_failed
  // (registry canonical žádné `_resolve` nenese).
  let isRegistry = $derived(data?.target === 'registry');

  let canApply = $derived(
    data !== null
      && !data.aiFailed
      && allDecided(data.canonical?._resolve ?? null, userActions),
  );

  function handleApplyClick(applyOptions = null) {
    onApply(messageNdx, userActions, data?.target ?? 'docs', applyOptions);
  }
</script>

{#snippet queueBadge()}
  <span class="shpd-exchange-modal__queue-pos">
    {t('exchange.preview.queuePosition', { i: queue.index + 1, n: queue.total })}
  </span>
{/snippet}

{#snippet sourceMessage()}
  {#if data?.message}
    {@const msg = data.message}
    <span class="shpd-exchange-modal__source" data-testid="review-source-message">
      <span class="shpd-exchange-modal__source-seg">
        {t('exchange.preview.sourceMessage')}
        {#if msg.codeShort}
          {#if onOpenMessage}
            <button
              type="button"
              class="shpd-exchange-modal__source-link"
              title={msg.code}
              data-testid="review-source-message-link"
              onclick={() => onOpenMessage(msg.ndx)}
            >#{msg.codeShort}</button>
          {:else}
            <span class="shpd-exchange-modal__source-code" title={msg.code}>#{msg.codeShort}</span>
          {/if}
        {/if}
      </span>
      {#if msg.receivedAt}
        <span class="shpd-exchange-modal__source-seg">{msg.receivedAt}</span>
      {/if}
      {#if msg.sender}
        <span class="shpd-exchange-modal__source-seg">{msg.sender}</span>
      {/if}
    </span>
  {:else if loading}
    <!-- Drží výšku řádku během načítání, hlavička neposkakuje. -->
    &nbsp;
  {/if}
{/snippet}

<Modal
  title={t('exchange.preview.title')}
  {open}
  onClose={handleClose}
  width="full"
  testid="review-modal"
  headerExtra={queue ? queueBadge : undefined}
  subtitle={sourceMessage}
  flush
>
  {#if loading}
    <div class="shpd-exchange-modal__loading">
      {t('exchange.preview.loading')}
    </div>
  {:else if error}
    <div class="shpd-exchange-modal__error">{error}</div>
  {:else if data}
    <div class="shpd-exchange-modal__mobile-tabs" role="tablist">
      <button
        class="shpd-exchange-modal__mobile-tab"
        class:shpd-exchange-modal__mobile-tab--active={mobileTab === 'pdf'}
        onclick={() => (mobileTab = 'pdf')}
        role="tab"
        aria-selected={mobileTab === 'pdf'}
      >
        {t('exchange.preview.tabs.pdf')}
      </button>
      <button
        class="shpd-exchange-modal__mobile-tab"
        class:shpd-exchange-modal__mobile-tab--active={mobileTab === 'preview'}
        onclick={() => (mobileTab = 'preview')}
        role="tab"
        aria-selected={mobileTab === 'preview'}
      >
        {t('exchange.preview.tabs.preview')}
      </button>
    </div>

    <div
      class="shpd-exchange-modal__split"
      data-mobile-tab={mobileTab}
    >
      <div class="shpd-exchange-modal__pdf">
        <PdfViewerPanel attachments={data.attachments ?? []} />
      </div>
      <div class="shpd-exchange-modal__preview">
        {#if isRegistry && !data.aiFailed}
          <RegistryExtractedPreview canonical={data.canonical} />
        {:else}
          <DocumentExchangePreview
            canonical={data.canonical}
            aiFailed={data.aiFailed}
            wrapper={data.wrapper}
            {userActions}
            onUserActionsChange={handleUserActionsChange}
          />
        {/if}
      </div>
    </div>
  {/if}

  {#snippet footer()}
    {#if saveError}
      <!-- Záchranná síť (#76): autosave selhal — klient mapu drží, další
           změna uložení zopakuje; apply funguje nezávisle. -->
      <span class="shpd-exchange-modal__save-error" role="status" data-testid="review-save-error">
        {t('exchange.preview.decisions.saveError')}
      </span>
    {/if}
    <Button
      label={t('exchange.preview.actions.close')}
      variant="secondary"
      testid="review-close"
      onclick={handleClose}
    />
    {#if queue}
      <!-- Jen batch mód (D4) — posun na další zprávu bez verdiktu,
           karta zůstává ve feedu. Guard jako u zavření (#76). -->
      <Button
        label={t('exchange.preview.actions.skip')}
        variant="secondary"
        testid="review-skip"
        disabled={loading}
        onclick={handleSkip}
      />
    {/if}
    <Button
      label={t('exchange.preview.actions.reject')}
      variant="danger"
      testid="review-reject"
      disabled={data === null}
      onclick={() => onReject(messageNdx)}
    />
    {#if isRegistry}
      <Button
        label={t('exchange.preview.actions.applyRegistry')}
        variant="success"
        disabled={!canApply}
        onclick={() => handleApplyClick()}
      />
    {:else}
      <!-- Oba apply gatuje stejné canApply (D3) — náležitosti stavu 40
           hlídá backendová validace, chyba se ukáže alertem v hostiteli. -->
      <Button
        label={t('exchange.preview.actions.apply')}
        variant="secondary"
        testid="review-apply-draft"
        disabled={!canApply}
        onclick={() => handleApplyClick()}
      />
      <Button
        label={t('exchange.preview.actions.applyFinal')}
        variant="success"
        testid="review-apply-final"
        disabled={!canApply}
        onclick={() => handleApplyClick({ targetDocState: 40 })}
      />
    {/if}
  {/snippet}
</Modal>

<!-- Guard „Neuložená rozhodnutí" — jen při pendingSave || saveError (#76).
     Další Modal na stacku: Esc v něm zavře jen dialog (= Zůstat). -->
<ConfirmDialog
  open={pendingAction !== null}
  title={t('exchange.preview.decisions.unsavedTitle')}
  message={t('exchange.preview.decisions.unsavedMessage')}
  confirmLabel={t('exchange.preview.decisions.discard')}
  cancelLabel={t('exchange.preview.decisions.stay')}
  variant="danger"
  onConfirm={discardPending}
  onCancel={stayPending}
  testid="review-unsaved-dialog"
/>

<style>
  /* Zdrojová zpráva pod titulkem — inline segmenty oddělené tečkou přes
     ::before (oddělovač není v JS zdroji), prázdné segmenty se nevykreslí.
     Elipsování řeší .shpd-modal__subtitle rodiče. */
  .shpd-exchange-modal__source-seg + .shpd-exchange-modal__source-seg::before {
    content: '\00B7';
    margin: 0 0.4em;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange-modal__source-code {
    font-weight: 600;
    font-variant-numeric: tabular-nums;
  }

  .shpd-exchange-modal__source-link {
    border: 0;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-exchange-modal__source-link:hover {
    text-decoration: underline;
  }

  /* Počítadlo pozice ve frontě („3 / 8") — nenápadný badge v hlavičce. */
  .shpd-exchange-modal__queue-pos {
    padding: 2px var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: 999px;
    background: var(--shpd-color-surface);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  /* Autosave rozhodnutí selhal (#76) — nenápadný text vlevo v patičce;
     margin-right: auto drží tlačítka na místě. */
  .shpd-exchange-modal__save-error {
    margin-right: auto;
    align-self: center;
    color: var(--shpd-color-danger);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-exchange-modal__loading,
  .shpd-exchange-modal__error {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-muted);
    font-size: 0.875rem;
    min-height: 200px;
  }

  .shpd-exchange-modal__error {
    color: var(--shpd-color-danger);
  }

  /* Mobile tab bar — hidden on desktop. */
  .shpd-exchange-modal__mobile-tabs {
    display: none;
    border-bottom: 1px solid var(--shpd-color-border);
    padding: 0 var(--shpd-space-sm);
    gap: var(--shpd-space-xs);
    background: var(--shpd-color-surface);
    flex-shrink: 0;
  }

  .shpd-exchange-modal__mobile-tab {
    border: 0;
    background: transparent;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    cursor: pointer;
    color: var(--shpd-color-text-muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    font-size: 0.875rem;
    font-weight: 500;
  }

  .shpd-exchange-modal__mobile-tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
  }

  /* Desktop: 50/50 split. */
  .shpd-exchange-modal__split {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
    height: 100%;
    min-height: 0;
    overflow: hidden;
  }

  .shpd-exchange-modal__pdf,
  .shpd-exchange-modal__preview {
    overflow-y: auto;
    min-height: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    background: var(--shpd-color-surface);
  }

  /* Mobile: single column controlled by tab. */
  @media (max-width: 768px) {
    .shpd-exchange-modal__mobile-tabs {
      display: flex;
    }

    .shpd-exchange-modal__split {
      grid-template-columns: 1fr;
    }

    .shpd-exchange-modal__split[data-mobile-tab='pdf'] .shpd-exchange-modal__preview {
      display: none;
    }

    .shpd-exchange-modal__split[data-mobile-tab='preview'] .shpd-exchange-modal__pdf {
      display: none;
    }
  }
</style>
