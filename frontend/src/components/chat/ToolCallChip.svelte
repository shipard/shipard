<script>
  /**
   * Renders a tool-call as a compact human-friendly chip
   * ("🔍 Hledám osoby…"), never raw JSON. Unknown tools fall back to a
   * generic label with the raw name so nothing breaks as the catalog grows.
   */
  import Icon from '../ui/Icon.svelte';
  import { iconSearch } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { toolLabelKey } from './toolLabels.js';

  let { name = '', state = 'done' } = $props();
  // state: 'running' | 'done' | 'error'

  const key = $derived(toolLabelKey(name));
  const label = $derived(key ? t(key) : t('chat.tool.generic', { name }));
</script>

<span class="shpd-toolchip shpd-toolchip--{state}" title={name}>
  <Icon icon={iconSearch} size="xs" />
  <span class="shpd-toolchip__label">{label}</span>
</span>

<style>
  .shpd-toolchip {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 0.125rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-full);
    font-size: var(--shpd-font-size-sm);
    background-color: var(--shpd-color-accent-soft);
    color: var(--shpd-color-accent);
    white-space: nowrap;
  }
  .shpd-toolchip--error {
    background-color: var(--shpd-color-danger);
    color: #fff;
  }
  .shpd-toolchip__label {
    overflow: hidden;
    text-overflow: ellipsis;
  }
</style>
