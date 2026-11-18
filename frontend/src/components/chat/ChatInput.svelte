<script>
  /**
   * Message composer. Enter sends, Shift+Enter inserts a newline. Disabled
   * while a turn is streaming.
   *
   * Text je `value` (bindable) — vlákno ho drží v chatStore, aby rozepsaná
   * zpráva přežila zavření bočního panelu. Esc pole neřeší: zavírá panel
   * přes zásobník vrstev.
   */
  import Button from '../ui/Button.svelte';
  import { iconChat } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { disabled = false, value = $bindable(''), onsend } = $props();

  function submit() {
    const text = value.trim();
    if (disabled || text === '') return;
    value = '';
    onsend?.(text);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      submit();
    }
  }
</script>

<div class="shpd-chat-input">
  <textarea
    class="shpd-chat-input__field"
    bind:value
    {disabled}
    rows="2"
    placeholder={t('chat.input.placeholder')}
    onkeydown={handleKeydown}
  ></textarea>
  <Button
    icon={iconChat}
    label={t('chat.input.send')}
    variant="primary"
    disabled={disabled || value.trim() === ''}
    onclick={submit}
  />
</div>

<style>
  .shpd-chat-input {
    display: flex;
    gap: var(--shpd-space-sm);
    align-items: flex-end;
    padding: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
  }
  /* Víceřádkové pole: výška ovládacího prvku jako minimum, písmo a rámeček
     z tokenů (#156 D106, D108, D109). */
  .shpd-chat-input__field {
    flex: 1;
    min-height: var(--shpd-control-height);
    padding: var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border-control);
    border-radius: var(--shpd-radius-md);
    font-size: var(--shpd-font-size-field);
    font-family: var(--shpd-font-family);
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg);
    box-sizing: border-box;
    resize: none;
  }
  .shpd-chat-input__field:focus {
    outline: none;
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }
  .shpd-chat-input__field:disabled {
    opacity: 0.6;
    background-color: var(--shpd-color-bg-secondary);
    border-color: var(--shpd-color-border);
  }
</style>
