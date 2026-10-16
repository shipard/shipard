<script>
  // Odznak stavu pole / volání (třídy dev-state--* v dev.css).
  import { STATE_LABELS } from '../testing/metrics.js';
  let { state, label = null, title = null } = $props();
  const cls = $derived(STATE_LABELS[state] ? state : 'neutral');
</script>

<span class="dev-state dev-state--{cls}" title={title ?? undefined}>{label ?? STATE_LABELS[state] ?? state}</span>
