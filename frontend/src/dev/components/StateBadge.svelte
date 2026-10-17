<script>
  // Odznak stavu pole / volání (třídy dev-state--* v dev.css).
  import { stateLabel, isKnownState } from '../testing/metrics.js';
  let { state, label = null, title = null } = $props();
  const cls = $derived(isKnownState(state) ? state : 'neutral');
</script>

<span class="dev-state dev-state--{cls}" title={title ?? undefined}>{label ?? stateLabel(state)}</span>
