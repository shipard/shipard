<script>
  // Přepínač Shipard / Tmavý / Vlastní (mode light/dark/custom) — tenký
  // obal sdíleného SegmentedControl (#149 D77) s konkrétními volbami.
  // Bezstavový: dostane aktuální `mode` a callback `onSelect`. Sdílí ho
  // ThemeField (user, vázaný na themeStore) i DsThemeField (DS default,
  // controlled), aby vypadaly identicky. `label` = popisek skupiny pro
  // čtečku (popisek pole ze stránky nastavení).
  import { t } from '../../i18n/index.js';
  import SegmentedControl from '../ui/SegmentedControl.svelte';
  import { iconThemeLight, iconThemeDark, iconPalette } from '../../icons.js';

  let { mode, onSelect, label = undefined } = $props();

  const options = [
    { value: 'light',  label: t('sidebar.appearance.light'),  icon: iconThemeLight },
    { value: 'dark',   label: t('sidebar.appearance.dark'),   icon: iconThemeDark },
    { value: 'custom', label: t('sidebar.appearance.custom'), icon: iconPalette },
  ];
</script>

<SegmentedControl {options} value={mode} {label} onchange={(value) => onSelect?.(value)} />
