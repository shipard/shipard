<script>
  /**
   * Odeslání záznamu e-mailem (docs/prints.md, #90 D38) — otevírá ho akce
   * `send` v detailu záznamu. Dialog načte návrh ze serveru (příjemci
   * s důvodem, proč tam adresa je, odesílatel, texty v jazyce dokumentu,
   * přílohy) a nechá ho upravit; odeslání vždy vytvoří novou zprávu
   * v Odeslané poště.
   *
   * Změna jazyka načte návrh znovu: předmět a tělo se přepíšou (ručně
   * upravený text až po potvrzení), příjemci, odesílatel a výběr příloh
   * zůstávají, jak je uživatel nastavil.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Select from '../ui/Select.svelte';
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Icon from '../ui/Icon.svelte';
  import SpanBadge from './SpanBadge.svelte';
  import PrintPreviewDialog from './PrintPreviewDialog.svelte';
  import { fetchSendDraft, sendPrint } from '../../api/prints.js';
  import { iconSend, iconClose, iconPreview } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    printId = '',
    recordId = null,
    onClose = () => {},
    /** Voláno po úspěšném vytvoření zprávy — hostitel obnoví detail záznamu. */
    onSent = () => {},
  } = $props();

  const EMAIL = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/;

  let loading = $state(false);
  let loadError = $state(null);
  let draft = $state(null);

  // Upravitelná část návrhu.
  let from = $state(null);
  let to = $state([]);          // [{email, label, source}]
  let cc = $state([]);          // [email]
  let language = $state(null);
  let subject = $state('');
  let body = $state('');
  let selected = $state({});    // id přílohy záznamu → bool
  let newTo = $state('');
  let newCc = $state('');
  let addressError = $state(null);

  // Texty ze serveru — podle nich se pozná ruční úprava.
  let serverSubject = '';
  let serverBody = '';

  let sending = $state(false);
  let sendError = $state(null);
  let result = $state(null);    // {transportState, messages}
  let previewOpen = $state(false);

  // Guard proti out-of-order odpovědím (zavření, jiný záznam, rychlá změna jazyka).
  let fetchToken = 0;

  const senderOptions = $derived((draft?.allowedSenders ?? []).map((s) => ({ value: s.email, label: s.email })));
  const languageOptions = $derived((draft?.languages ?? []).map((l) => ({ value: l.id, label: l.label })));
  const recordAttachments = $derived((draft?.attachments ?? []).filter((a) => a.kind === 'record'));
  const printAttachment = $derived((draft?.attachments ?? []).find((a) => a.kind === 'print') ?? null);

  // Hlášení návrhu; chyby, které uživatel v dialogu už vyřešil, se schovají.
  const messages = $derived((draft?.messages ?? []).filter((m) => {
    if (m.code === 'NO_RECIPIENT') return to.length === 0;
    if (m.code === 'NO_SENDER' || m.code === 'SENDER_NOT_ALLOWED') return !from;
    return true;
  }));

  const canSend = $derived(
    draft !== null && !sending && !!from && to.length > 0 && subject.trim() !== '' && body.trim() !== '',
  );

  $effect(() => {
    if (open && printId && recordId !== null && recordId !== undefined) {
      void load(null, true);
    } else {
      reset();
    }
    return reset;
  });

  function reset() {
    fetchToken++;
    draft = null;
    loadError = null;
    loading = false;
    sending = false;
    sendError = null;
    result = null;
    previewOpen = false;
    newTo = '';
    newCc = '';
    addressError = null;
  }

  /**
   * @param {?string} lang Jazyk návrhu; null = podle partnera.
   * @param {boolean} initial První načtení plní všechno; další (změna
   *   jazyka) jen texty a přílohu tisku.
   */
  async function load(lang, initial) {
    const token = ++fetchToken;
    loading = true;
    loadError = null;

    const res = await fetchSendDraft(printId, recordId, lang);
    if (token !== fetchToken) return;
    loading = false;

    if (res === null) return; // 401 — přesměrování řeší API klient
    if (!res.success) {
      loadError = translateError(res.error);
      return;
    }

    const data = res.data;
    draft = data;
    language = data.language;
    subject = data.subject;
    body = data.body;
    serverSubject = data.subject;
    serverBody = data.body;

    if (initial) {
      from = data.from?.email ?? null;
      to = (data.to ?? []).map((r) => ({ email: r.email, label: r.label ?? '', source: r.source }));
      cc = [...(data.cc ?? [])];
      selected = Object.fromEntries(
        (data.attachments ?? []).filter((a) => a.kind === 'record').map((a) => [a.id, a.selected]),
      );
    }
  }

  /** @param {Event} event `change` nativního <select> */
  function changeLanguage(event) {
    const value = event.currentTarget?.value ?? null;
    if (!value || value === draft?.language) return;
    const edited = subject !== serverSubject || body !== serverBody;
    if (edited && !window.confirm(t('send.replaceTextsConfirm'))) {
      language = draft?.language ?? null;
      return;
    }
    void load(value, false);
  }

  function addAddress(kind) {
    const value = (kind === 'to' ? newTo : newCc).trim();
    if (value === '') return;
    if (!EMAIL.test(value)) {
      addressError = t('send.invalidAddress', { email: value });
      return;
    }
    addressError = null;
    const known = (list) => list.some((e) => e.toLowerCase() === value.toLowerCase());
    if (kind === 'to') {
      if (!known(to.map((r) => r.email))) to = [...to, { email: value, label: t('send.addedManually'), source: 'manual' }];
      newTo = '';
    } else {
      if (!known(cc)) cc = [...cc, value];
      newCc = '';
    }
  }

  function addOnEnter(event, kind) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    addAddress(kind);
  }

  async function send() {
    if (!canSend) return;
    sending = true;
    sendError = null;

    const res = await sendPrint(printId, recordId, {
      from,
      to: to.map((r) => r.email),
      cc,
      subject,
      body,
      language,
      attachmentIds: recordAttachments.filter((a) => selected[a.id]).map((a) => a.id),
    });
    sending = false;

    if (res === null) return;
    if (!res.success) {
      sendError = translateError(res.error);
      return;
    }
    result = res.data;
    onSent();
  }

  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  }
</script>

<Modal
  title={t('send.title')}
  subtitle={draft?.targetLabel ? recordLabel : undefined}
  {open}
  {onClose}
  width="760px"
  testid="send-dialog"
>
  {#snippet recordLabel()}{draft?.targetLabel ?? ''}{/snippet}

  <div class="shpd-send">
    {#if loading && draft === null}
      <div class="shpd-send__state">{t('send.loading')}</div>
    {:else if loadError}
      <div class="shpd-send__state shpd-send__state--error" role="alert">{loadError}</div>
    {:else if result}
      <div class="shpd-send__result" data-testid="send-result">
        <SpanBadge
          style={result.transportState === 'sent' ? 'success' : 'warning'}
          text={result.transportState === 'sent' ? t('send.resultSent') : t('send.resultQueued')}
        />
        <p>
          {result.transportState === 'sent' ? t('send.resultSentNote') : t('send.resultQueuedNote')}
        </p>
      </div>
    {:else if draft}
      {#if messages.length > 0}
        <ul class="shpd-send__messages" data-testid="send-messages">
          {#each messages as message (message.code + message.text)}
            <li class:shpd-send__message--error={message.severity === 'error'}>{message.text}</li>
          {/each}
        </ul>
      {/if}

      <div class="shpd-send__grid">
        <label for="shpd-send-from">{t('send.from')}</label>
        <div>
          <Select
            id="shpd-send-from"
            bind:value={() => from, (v) => { from = v || null; }}
            options={senderOptions}
            required
            placeholder={from === null ? t('send.fromNone') : undefined}
          />
        </div>

        <span class="shpd-send__label">{t('send.to')}</span>
        <div data-testid="send-to">
          <ul class="shpd-send__chips">
            {#each to as recipient (recipient.email)}
              <li class="shpd-send__chip" title={recipient.label}>
                <span class="shpd-send__chip-main">
                  <span>{recipient.email}</span>
                  {#if recipient.label}
                    <span class="shpd-send__chip-reason">{recipient.label}</span>
                  {/if}
                </span>
                <button
                  type="button"
                  class="shpd-send__chip-remove"
                  aria-label={t('send.removeAddress', { email: recipient.email })}
                  onclick={() => { to = to.filter((r) => r.email !== recipient.email); }}
                >
                  <Icon icon={iconClose} />
                </button>
              </li>
            {/each}
          </ul>
          <div class="shpd-send__add">
            <Input
              bind:value={newTo}
              type="email"
              placeholder={t('send.addAddress')}
              oninput={() => { addressError = null; }}
              onkeydown={(e) => addOnEnter(e, 'to')}
              testid="send-to-input"
            />
            <Button label={t('common.add')} variant="secondary" size="sm" onclick={() => addAddress('to')} />
          </div>
        </div>

        <span class="shpd-send__label">{t('send.cc')}</span>
        <div data-testid="send-cc">
          {#if cc.length > 0}
            <ul class="shpd-send__chips">
              {#each cc as address (address)}
                <li class="shpd-send__chip">
                  <span class="shpd-send__chip-main"><span>{address}</span></span>
                  <button
                    type="button"
                    class="shpd-send__chip-remove"
                    aria-label={t('send.removeAddress', { email: address })}
                    onclick={() => { cc = cc.filter((a) => a !== address); }}
                  >
                    <Icon icon={iconClose} />
                  </button>
                </li>
              {/each}
            </ul>
          {/if}
          <div class="shpd-send__add">
            <Input
              bind:value={newCc}
              type="email"
              placeholder={t('send.addAddress')}
              oninput={() => { addressError = null; }}
              onkeydown={(e) => addOnEnter(e, 'cc')}
            />
            <Button label={t('common.add')} variant="secondary" size="sm" onclick={() => addAddress('cc')} />
          </div>
        </div>

        {#if addressError}
          <span></span>
          <div class="shpd-send__address-error" role="alert">{addressError}</div>
        {/if}

        {#if languageOptions.length > 1}
          <label for="shpd-send-language">{t('send.language')}</label>
          <div>
            <Select
              id="shpd-send-language"
              bind:value={() => language, (v) => { language = v; }}
              options={languageOptions}
              required
              disabled={loading}
              onchange={changeLanguage}
            />
          </div>
        {/if}

        <label for="shpd-send-subject">{t('send.subject')}</label>
        <div><Input id="shpd-send-subject" bind:value={subject} testid="send-subject" /></div>

        <label for="shpd-send-body">{t('send.body')}</label>
        <div><TextArea id="shpd-send-body" bind:value={body} rows={9} /></div>

        <span class="shpd-send__label">{t('send.attachments')}</span>
        <ul class="shpd-send__attachments" data-testid="send-attachments">
          {#if printAttachment}
            <li>
              <Checkbox checked={true} disabled label={printAttachment.name} />
              <Button
                label={t('send.preview')}
                icon={iconPreview}
                variant="ghost"
                size="sm"
                onclick={() => { previewOpen = true; }}
              />
            </li>
          {/if}
          {#each recordAttachments as attachment (attachment.id)}
            <li>
              <Checkbox
                bind:checked={() => selected[attachment.id] ?? false, (v) => { selected = { ...selected, [attachment.id]: v }; }}
                label={attachment.name}
              />
              <span class="shpd-send__attachment-size">{formatSize(attachment.fileSize)}</span>
              {#if attachment.merged && selected[attachment.id]}
                <SpanBadge style="neutral" text={t('send.mergedIntoPdf')} />
              {/if}
            </li>
          {/each}
        </ul>
      </div>

      {#if sendError}
        <div class="shpd-send__state shpd-send__state--error" role="alert" data-testid="send-error">{sendError}</div>
      {/if}
    {/if}
  </div>

  {#snippet footer()}
    {#if result}
      <Button label={t('common.close')} variant="primary" size="sm" onclick={onClose} />
    {:else}
      <Button label={t('common.cancel')} variant="secondary" size="sm" onclick={onClose} />
      <Button
        label={sending ? t('send.sending') : t('send.submit')}
        icon={iconSend}
        variant="primary"
        size="sm"
        disabled={!canSend}
        onclick={send}
        testid="send-submit"
      />
    {/if}
  {/snippet}
</Modal>

<PrintPreviewDialog
  open={previewOpen}
  {printId}
  {recordId}
  languages={draft?.languages ?? []}
  onClose={() => { previewOpen = false; }}
/>

<style>
  .shpd-send {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-send__grid {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: var(--shpd-space-sm) var(--shpd-space-md);
    align-items: start;
  }

  .shpd-send__grid > label,
  .shpd-send__label {
    padding-top: 6px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    text-align: right;
  }

  .shpd-send__chips,
  .shpd-send__attachments,
  .shpd-send__messages {
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .shpd-send__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-send__chip {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 2px 4px 2px 8px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-hover);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-send__chip-main {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }

  .shpd-send__chip-reason {
    font-size: 0.75rem;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-send__chip-remove {
    border: 0;
    background: none;
    padding: 2px 4px;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
  }

  .shpd-send__chip-remove:hover { color: var(--shpd-color-danger); }

  .shpd-send__add {
    display: flex;
    gap: var(--shpd-space-xs);
    align-items: stretch;
  }

  .shpd-send__add > :global(:first-child) { flex: 1; }

  .shpd-send__address-error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }

  .shpd-send__attachments li {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: 2px 0;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-send__attachment-size { color: var(--shpd-color-text-secondary); }

  .shpd-send__messages {
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-warning);
  }

  .shpd-send__message--error { color: var(--shpd-color-danger); }

  .shpd-send__state {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-send__state--error {
    color: var(--shpd-color-danger);
    text-align: left;
    padding: var(--shpd-space-sm);
  }

  .shpd-send__result {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
  }

  .shpd-send__result p { margin: 0; }

  /* Na mobilu popisky nad poli. */
  @media (max-width: 768px) {
    .shpd-send__grid { grid-template-columns: minmax(0, 1fr); }
    .shpd-send__grid > label,
    .shpd-send__label { text-align: left; padding-top: 0; }
  }
</style>
