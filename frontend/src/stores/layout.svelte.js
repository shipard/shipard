// Layout store — řídí mobilní vs. desktopový režim a stav drawera.
//
// `isMobile` se odvozuje z window.matchMedia nad breakpointem shellu
// (#133 D42): jediná hodnota `48rem`, stejná jako v `@media` shellových
// komponent (Modal, Viewer, review modal, AttachmentGrid). `rem` v media
// query i v matchMedia bere výchozí písmo prohlížeče, ne font-size kořene
// — aplikace kořenové písmo nenastavuje, takže CSS i JS se přepnou
// současně (při větším písmu přijde mobilní rozložení dřív). Shodu s CSS
// hlídá `scripts/check-css-units.py` v pre-commitu, ne komentáře
// v komponentách.
//
// `drawerOpen` je stav mobilního drawer sidebaru. Na desktopu se
// nepoužívá (drawer neexistuje, sidebar je pevný sloupec).

const MOBILE_BREAKPOINT = '48rem'; // breakpoint shellu (#133 D42), hlídá check-css-units.py

let isMobile   = $state(false);
let drawerOpen = $state(false);

// --- Screen surface (publikovaný aktuální obrazovkou, čte shell) ---
//
// Obecný kanál: aktivní obrazovka (typicky Viewer) publikuje svůj povrch —
// kontext (list/detail), akce (pole {id, label, icon, variant, onClick}),
// titul override a back handler. Shell rozhoduje, kde a jak ho vykreslí;
// dnešním konzumentem je MobileTopBar (mobilní top bar), budoucí shelly
// ho konzumují po svém. Viz docs/ui-shells.md §6. (Dříve `topBar*` kanál.)
//
// `null` kontext = obrazovka nic nepublikuje → konzument fallback
// na hamburger + titul z navigace + prázdný slot (dashboard apod.).

let surfaceContext = $state(null);  // 'list' | 'detail' | null
let surfaceActions = $state([]);    // [{ id, label, icon, variant, onClick }]
let surfaceTitle   = $state(null);  // string | null (override; null = z navigace)
let surfaceBack    = $state(null);  // (() => void) | null

function setScreenSurface({ context = null, actions = [], title = null, back = null }) {
  surfaceContext = context;
  surfaceActions = actions;
  surfaceTitle   = title;
  surfaceBack    = back;
}

function clearScreenSurface() {
  surfaceContext = null;
  surfaceActions = [];
  surfaceTitle   = null;
  surfaceBack    = null;
}

// Inicializace matchMedia listeneru. Voláno jednou z main.js po mountu.
// Defenzivní — matchMedia nemusí existovat v SSR/test prostředí.
function initLayout() {
  if (typeof window === 'undefined' || !window.matchMedia) return;

  const mq = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT})`);

  // Počáteční hodnota
  isMobile = mq.matches;

  // Reaktivní update při změně viewportu (resize, rotace zařízení)
  const onChange = (e) => {
    isMobile = e.matches;
    // Když se přepne na desktop, drawer zavři — na desktopu nemá smysl
    // a zůstal by viset jako stav.
    if (!e.matches) drawerOpen = false;
  };

  // addEventListener('change') je moderní API; addListener je legacy
  // fallback pro starší Safari. Cílíme moderní prohlížeče, ale levné.
  if (mq.addEventListener) {
    mq.addEventListener('change', onChange);
  } else if (mq.addListener) {
    mq.addListener(onChange);
  }
}

function openDrawer()   { drawerOpen = true; }
function closeDrawer()  { drawerOpen = false; }
function toggleDrawer() { drawerOpen = !drawerOpen; }

export const layoutStore = {
  get isMobile()   { return isMobile; },
  get drawerOpen() { return drawerOpen; },
  get surfaceContext() { return surfaceContext; },
  get surfaceActions() { return surfaceActions; },
  get surfaceTitle()   { return surfaceTitle; },
  get surfaceBack()    { return surfaceBack; },
  initLayout,
  openDrawer,
  closeDrawer,
  toggleDrawer,
  setScreenSurface,
  clearScreenSurface,
};
