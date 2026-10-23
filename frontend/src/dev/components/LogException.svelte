<script>
  // Strom výjimky záznamu logu: třída, zpráva, místo, trace (sbalený),
  // rekurzivně „Způsobeno:“ pro `previous`.
  import LogException from './LogException.svelte';
  import { t } from '../i18n/index.js';

  let { exception, depth = 0 } = $props();

  let showTrace = $state(false);
  const frames = $derived(Array.isArray(exception?.trace) ? exception.trace : []);
</script>

<div class="exc" style:margin-left={depth > 0 ? '0.75rem' : '0'}>
  {#if depth > 0}<div class="exc__caused">{t('logs.exception.causedBy')}</div>{/if}
  <div class="exc__head">
    <code>{exception.class}</code>
    <span class="exc__msg">{exception.message}</span>
  </div>
  {#if exception.at}<div class="exc__at dev-muted">{exception.at}</div>{/if}
  {#if frames.length > 0}
    <button type="button" class="exc__toggle" onclick={() => (showTrace = !showTrace)}>
      {showTrace ? t('logs.exception.hideTrace') : t('logs.exception.showFrames', { n: frames.length })}
    </button>
    {#if showTrace}
      <pre class="exc__trace">{frames.join('\n')}</pre>
    {/if}
  {/if}
  {#if exception.previous}
    <LogException exception={exception.previous} depth={depth + 1} />
  {/if}
</div>

<style>
  .exc { margin-top: var(--shpd-space-sm); font-size: var(--shpd-font-size-sm); }
  .exc__caused { font-weight: 600; color: var(--shpd-color-text-secondary); margin-bottom: 0.125rem; }
  .exc__head { display: flex; flex-wrap: wrap; gap: var(--shpd-space-sm); align-items: baseline; }
  .exc__msg { color: var(--shpd-color-danger); }
  .exc__at { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.8rem; margin-top: 0.125rem; }
  .exc__toggle {
    background: none;
    border: 0;
    padding: 0;
    margin-top: var(--shpd-space-xs);
    color: var(--shpd-color-primary);
    cursor: pointer;
    font: inherit;
    font-size: var(--shpd-font-size-sm);
  }
  .exc__trace {
    margin: var(--shpd-space-xs) 0 0;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-bg-secondary);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-xs);
    line-height: 1.5;
    overflow: auto;
    max-height: 320px;
    white-space: pre;
  }
</style>
