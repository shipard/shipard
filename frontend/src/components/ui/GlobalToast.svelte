<script>
  // Globální toast nad noticeStore — jedna zpráva, tlačítko zavřít.
  // Vizuál shodný s lokálním toastem Dashboardu (tmavý pruh dole).
  // Zpráva s úrovní (`success` / `info` / `warning` — výsledek akce
  // záznamu, #172 D14) má před textem značku v barvě tónu.
  import { noticeStore } from '../../stores/notice.svelte.js';
  import { t } from '../../i18n/index.js';

  const LEVELS = ['success', 'info', 'warning'];
  let level = $derived(LEVELS.includes(noticeStore.current?.level) ? noticeStore.current.level : null);
</script>

{#if noticeStore.current}
  <div class="shpd-global-toast" role="status" data-testid="global-toast" data-level={level ?? undefined}>
    {#if level}
      <span class="shpd-global-toast__mark shpd-global-toast__mark--{level}" aria-hidden="true"></span>
    {/if}
    <span class="shpd-global-toast__msg">{noticeStore.current.message}</span>
    <button
      type="button"
      class="shpd-global-toast__close"
      onclick={noticeStore.dismiss}
      aria-label={t('common.close')}
    >×</button>
  </div>
{/if}

<style>
  .shpd-global-toast {
    position: fixed;
    left: 50%;
    bottom: var(--shpd-space-lg);
    transform: translateX(-50%);
    z-index: 1100;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    max-width: min(90vw, 35rem);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-text);
    color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.25));
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-global-toast__msg {
    flex: 1;
    min-width: 0;
  }

  /* Značka tónu bez textu — tečka v barvě `--shpd-color-{tón}-mark` (D74). */
  .shpd-global-toast__mark {
    flex-shrink: 0;
    width: var(--shpd-space-sm);
    height: var(--shpd-space-sm);
    border-radius: 50%;
  }

  .shpd-global-toast__mark--success { background: var(--shpd-color-success-mark); }
  .shpd-global-toast__mark--info { background: var(--shpd-color-info-mark); }
  .shpd-global-toast__mark--warning { background: var(--shpd-color-warning-mark); }

  .shpd-global-toast__close {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
    opacity: 0.7;
  }

  .shpd-global-toast__close:hover {
    opacity: 1;
  }
</style>
