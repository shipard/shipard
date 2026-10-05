<script>
  /**
   * Stav transportu odeslané zprávy ve formuláři (element `component`,
   * component_name `sentMessageTransport`, #90 D43–D45): jak dopadl poslední
   * průchod frontou, kolikrát zpráva odešla, historie pokusů a akce
   * Odeslat znovu — stejná zpráva, stejní příjemci, stejné přílohy.
   *
   * `params.transport` staví backend (`SentMessageTransportInfo::describe`);
   * odpověď Odeslat znovu nese tentýž tvar, takže se blok jen přepíše.
   */
  import Button from '../ui/Button.svelte';
  import SpanBadge from '../viewer/SpanBadge.svelte';
  import { resendSentMessage } from '../../api/mail.js';
  import { iconSend } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let { params = {}, parentId = null } = $props();

  // Po Odeslat znovu platí stav z odpovědi; nový záznam ve formuláři
  // (jiné params) ho zase přebije.
  let override = $state(null);
  let sending = $state(false);
  let error = $state(null);

  $effect(() => {
    void params;
    override = null;
    error = null;
  });

  const transport = $derived(override ?? params?.transport ?? null);
  const attempts = $derived(transport?.attempts ?? []);

  async function resend() {
    if (sending || parentId == null) return;
    sending = true;
    error = null;
    const result = await resendSentMessage(parentId);
    if (result?.success) {
      override = result.data?.transport ?? null;
    } else {
      error = translateError(result?.error);
    }
    sending = false;
  }
</script>

{#if transport}
  <div class="shpd-sent-transport" data-testid="sent-message-transport">
    <div class="shpd-sent-transport__head">
      <SpanBadge style={transport.stateStyle} text={transport.stateLabel} />
      {#if transport.safety}
        <SpanBadge style={transport.safety.style} text={transport.safety.label} />
      {/if}
      {#if transport.sentAt}
        <span class="shpd-sent-transport__meta">{t('sentMessage.sentAt', { at: transport.sentAt })}</span>
      {/if}
      {#if transport.sendCount > 1}
        <span class="shpd-sent-transport__meta">{t('sentMessage.sendCount', { count: transport.sendCount })}</span>
      {/if}
      <span class="shpd-sent-transport__spacer"></span>
      <Button
        label={sending ? t('sentMessage.resending') : t('sentMessage.resend')}
        variant="secondary"
        size="sm"
        icon={iconSend}
        disabled={!transport.canResend || sending}
        onclick={resend}
        testid="sent-message-resend"
      />
    </div>

    {#if transport.state === 'queued'}
      <p class="shpd-sent-transport__note">{t('sentMessage.queuedNote')}</p>
    {/if}
    {#if transport.lastError}
      <p class="shpd-sent-transport__error">{transport.lastError}</p>
    {/if}
    {#if error}
      <p class="shpd-sent-transport__error" data-testid="sent-message-transport-error">{error}</p>
    {/if}

    {#if attempts.length > 0}
      <details class="shpd-sent-transport__attempts">
        <summary>{t('sentMessage.attempts', { count: attempts.length })}</summary>
        <ul>
          {#each attempts as attempt}
            <li class:shpd-sent-transport__attempt--fail={!attempt.ok}>
              <span>{attempt.at ?? ''}</span>
              <span>{attempt.ok ? t('sentMessage.attemptOk') : t('sentMessage.attemptFail')}</span>
              <span class="shpd-sent-transport__meta">{attempt.transport}</span>
              {#if attempt.response}
                <span class="shpd-sent-transport__response">{attempt.response}</span>
              {/if}
            </li>
          {/each}
        </ul>
      </details>
    {/if}
  </div>
{/if}

<style>
  .shpd-sent-transport {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-sent-transport__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-sent-transport__spacer { flex: 1; }

  .shpd-sent-transport__meta { color: var(--shpd-color-text-secondary); }

  .shpd-sent-transport__note {
    margin: 0;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-sent-transport__error {
    margin: 0;
    color: var(--shpd-color-danger);
    overflow-wrap: anywhere;
  }

  .shpd-sent-transport__attempts summary {
    cursor: pointer;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-sent-transport__attempts ul {
    margin: var(--shpd-space-xs) 0 0;
    padding: 0;
    list-style: none;
  }

  .shpd-sent-transport__attempts li {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    padding: 2px 0;
  }

  .shpd-sent-transport__attempt--fail { color: var(--shpd-color-danger); }

  .shpd-sent-transport__response {
    flex-basis: 100%;
    color: var(--shpd-color-text-secondary);
    overflow-wrap: anywhere;
  }
</style>
