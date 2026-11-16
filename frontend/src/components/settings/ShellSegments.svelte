<script>
  // Přepínač volby shellu — tenký obal sdíleného SegmentedControl (#149
  // D77). Bezstavový: dostane aktuální hodnotu, seznam options
  // [{value, labelKey}] a callback. Sdílí ho ShellField (user, vázaný na
  // shellStore, se segmentem „Podle aplikace") i DsShellField (DS default,
  // controlled, jen jména shellů) — vzor ThemeModeSegments. `label` =
  // popisek skupiny pro čtečku (popisek pole ze stránky nastavení).
  import { t } from '../../i18n/index.js';
  import SegmentedControl from '../ui/SegmentedControl.svelte';

  let { value, options = [], onSelect, label = undefined } = $props();

  let segments = $derived(options.map((opt) => ({ value: opt.value, label: t(opt.labelKey) })));
</script>

<SegmentedControl options={segments} {value} {label} onchange={(next) => onSelect?.(next)} />
