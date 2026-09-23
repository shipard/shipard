/**
 * Centrální registr ikon pro celou aplikaci.
 *
 * Všechny ikony importujeme a re-exportujeme z jednoho místa.
 * Díky tomu:
 *   - tree-shaking zahrne jen použité ikony
 *   - stejný význam = stejná ikona všude (konzistence)
 *   - přejmenování/výměna ikony na jednom místě
 *
 * Pojmenování: icon{Význam} — ne podle vzhledu, ale podle účelu.
 */

import {
  faPlus,
  faPen,
  faTrash,
  faArrowUp,
  faArrowDown,
  faCopy,
  faFloppyDisk,
  faXmark,
  faCheck,
  faMagnifyingGlass,
  faFilter,
  faArrowsRotate,
  faChevronLeft,
  faLanguage,
  faChevronRight,
  faChevronDown,
  faChevronUp,
  faEllipsisVertical,
  faEllipsis,
  faArrowRightFromBracket,
  faGear,
  faGears,
  faCalculator,
  faChartColumn,
  faUser,
  faUsers,
  faBuilding,
  faFileInvoiceDollar,
  faFileArrowDown,
  faFileArrowUp,
  faFileContract,
  faFileLines,
  faBoxesStacked,
  faTag,
  faTags,
  faFolderOpen,
  faTable,
  faTableColumns,
  faList,
  faBars,
  faAnglesLeft,
  faAnglesRight,
  faCircleNotch,
  faTriangleExclamation,
  faCircleInfo,
  faCircleCheck,
  faCircleQuestion,
  faPaperclip,
  faDownload,
  faCloudArrowDown,
  faUpload,
  faFile,
  faFilePdf,
  faFileImage,
  faImage,
  faFileWord,
  faFileExcel,
  faFileZipper,
  faEnvelope,
  faRulerCombined,
  faCube,
  faCalendarDays,
  faBook,
  faListCheck,
  faPercent,
  faWallet,
  faBuildingColumns,
  faHashtag,
  faSun,
  faMoon,
  faPalette,
  faBell,
  faGauge,
  faRobot,
  faComments,
  faLock,
  faArrowUpRightFromSquare,
  faServer,
  faDatabase,
  faEye,
  faHouse,
  faBagShopping,
  faShapes,
  faShuffle,
  faTableList,
  faScaleBalanced,
  faRightLeft,
  faCircleArrowUp,
  faCircleArrowDown,
  faMoneyBillTransfer,
  faCashRegister,
  faCreditCard,
  faTruck,
  faChartPie,
  faPaperPlane,
  faStamp,
  faInbox,
  faAddressBook,
  faWandMagicSparkles,
  faMicrochip,
} from '@fortawesome/free-solid-svg-icons';

// ── Akce (toolbary, tlačítka) ──────────────────────────────────
export const iconAdd = faPlus;
export const iconEdit = faPen;
export const iconDelete = faTrash;
export const iconSave = faFloppyDisk;
export const iconCancel = faXmark;
export const iconConfirm = faCheck;
export const iconSearch = faMagnifyingGlass;
export const iconFilter = faFilter;
export const iconRefresh = faArrowsRotate;
export const iconCopy = faCopy;
export const iconPreview = faEye; // 👁 — otevření read-only náhledu / review modalu
export const iconMoveUp = faArrowUp;     // ▲ — přesun řádku výš (sub-tabulka); pohyb, ne rozbalení (to je chevron)
export const iconMoveDown = faArrowDown; // ▼ — přesun řádku níž

// ── Navigace ────────────────────────────────────────────────────
export const iconChevronLeft = faChevronLeft;
export const iconChevronRight = faChevronRight;
export const iconChevronDown = faChevronDown;
export const iconChevronUp = faChevronUp;
export const iconMenu = faBars;
export const iconClose = faXmark; // ✕ — zavření drawera / panelu (význam „zavřít", odlišný od iconCancel)
export const iconCollapse = faAnglesLeft;
export const iconExpand = faAnglesRight;
export const iconHome = faHouse; // 🏠 — domeček `_top` v horním menu classic shellu
export const iconMore = faEllipsisVertical;
export const iconDots = faEllipsis; // ⋯ — sekce „Ostatní" v Nastavení
export const iconOpenExternal = faArrowUpRightFromSquare; // ⧉ — otevřít v plném zobrazení (chat panel → sekce Chat)

// ── Auth / uživatelé ────────────────────────────────────────────
export const iconLogout = faArrowRightFromBracket;
export const iconSettings = faGear;
export const iconAppSettings = faGears;
export const iconLanguage = faLanguage; // jazyk — podmenu v UserMenu
export const iconLayout = faTableColumns; // rozložení — podmenu v UserMenu
export const iconCalculator = faCalculator;
export const iconUser = faUser;
export const iconUsers = faUsers;
export const iconLock = faLock;

// ── Číselníky / moduly (sidebar, navigace) ──────────────────────
export const iconChart = faChartColumn;
export const iconCompany = faBuilding;
export const iconInvoice = faFileInvoiceDollar;
export const iconInvoiceIn = faFileArrowDown; // ⬇ — přijaté faktury (směrová metafora)
export const iconInvoiceOut = faFileArrowUp; // ⬆ — vydané faktury
export const iconInvoiceProforma = faFileContract; // zálohové faktury vydané — výzva k platbě, ne daňový doklad
export const iconDocAccounting = faFileInvoiceDollar;
export const iconDocument = faFileLines;
export const iconWarehouse = faBoxesStacked;
export const iconItems = faBagShopping; // Položky
export const iconKinds = faShapes; // Druhy položek
export const iconTag = faTag;
export const iconTags = faTags;
export const iconShuffle = faShuffle; // Pravidla štítků
export const iconFolder = faFolderOpen;
export const iconTable = faTable;
export const iconTableList = faTableList; // Účtový rozvrh
export const iconList = faList;
export const iconMail = faEnvelope;
export const iconMailOut = faPaperPlane; // Odeslaná pošta
export const iconInbox = faInbox; // Schránky
export const iconAddressBook = faAddressBook; // Odesílatelé
export const iconMagic = faWandMagicSparkles; // Preprocess pravidla
export const iconRuler = faRulerCombined;
export const iconBox = faCube;
export const iconCalendar = faCalendarDays;
export const iconBook = faBook;
export const iconListCheck = faListCheck;
export const iconVat = faPercent;
export const iconFiling = faStamp; // Podání na úřad (DPH a dál) — má lifecycle, na rozdíl od reportu
export const iconWallet = faWallet;
export const iconCashRegister = faCashRegister; // Pokladny
export const iconCreditCard = faCreditCard; // Platební terminály a brány
export const iconTruck = faTruck; // Způsoby dopravy
export const iconBank = faBuildingColumns;
export const iconMoneyTransfer = faMoneyBillTransfer; // Bankovní pohyby
export const iconBalance = faScaleBalanced; // Saldokonta + fallback saldokontních nav položek
export const iconMovements = faRightLeft; // Saldo pohyby
export const iconReceivable = faCircleArrowUp; // nav položka saldokonta pohledávkového typu (MD)
export const iconPayable = faCircleArrowDown; // nav položka saldokonta závazkového typu (DAL)
export const iconChartPie = faChartPie; // Střediska
export const iconHash = faHashtag;
export const iconServer = faServer;
export const iconDatabase = faDatabase;

// ── Vzhled / theme ─────────────────────────────────────────────
export const iconThemeLight = faSun;
export const iconThemeDark = faMoon;
export const iconPalette = faPalette;

// ── Stav / feedback ─────────────────────────────────────────────
export const iconSpinner = faCircleNotch;
export const iconWarning = faTriangleExclamation;
export const iconInfo = faCircleInfo;
export const iconSuccess = faCircleCheck;
export const iconQuestion = faCircleQuestion;
export const iconAlert = faBell;

// ── Dashboard ──────────────────────────────────────────────────
export const iconDashboard = faGauge;
export const iconRobot = faRobot;
export const iconChip = faMicrochip; // AI backendy
export const iconChat = faComments;

// ── Přílohy / soubory ─────────────────────────────────────────────
export const iconAttachment = faPaperclip;
export const iconDownload = faDownload;
export const iconCloudDownload = faCloudArrowDown;
export const iconUpload = faUpload;
export const iconFile = faFile;
export const iconFilePdf = faFilePdf;
export const iconFileImage = faFileImage;
export const iconImage = faImage; // obrázkový placeholder (branding sloty)
export const iconFileWord = faFileWord;
export const iconFileExcel = faFileExcel;
export const iconFileZip = faFileZipper;

/**
 * Mapování názvů ikon z API (string) → ikonový objekt.
 * Server může v navigaci poslat "icon": "users" a klient ho přeloží.
 * Fallback: iconTable.
 */
export const iconMap = {
  'add': iconAdd,
  'home': iconHome,
  'edit': iconEdit,
  'delete': iconDelete,
  'save': iconSave,
  'search': iconSearch,
  'filter': iconFilter,
  'refresh': iconRefresh,
  'settings': iconSettings,
  'user': iconUser,
  'users': iconUsers,
  'company': iconCompany,
  'invoice': iconInvoice,
  'invoice-in': iconInvoiceIn,
  'invoice-out': iconInvoiceOut,
  'invoice-proforma': iconInvoiceProforma,
  'doc-accounting': iconDocAccounting,
  'document': iconDocument,
  'warehouse': iconWarehouse,
  'items': iconItems,
  'kinds': iconKinds,
  'tag': iconTag,
  'tags': iconTags,
  'shuffle': iconShuffle,
  'folder': iconFolder,
  'table': iconTable,
  'table-list': iconTableList,
  'list': iconList,
  'mail': iconMail,
  'mail-out': iconMailOut,
  'inbox': iconInbox,
  'address-book': iconAddressBook,
  'magic': iconMagic,
  'ruler': iconRuler,
  'box': iconBox,
  'calendar': iconCalendar,
  'book': iconBook,
  'list-check': iconListCheck,
  'vat': iconVat,
  'filing': iconFiling,
  'wallet': iconWallet,
  'cash-register': iconCashRegister,
  'credit-card': iconCreditCard,
  'truck': iconTruck,
  'bank': iconBank,
  'money-transfer': iconMoneyTransfer,
  'balance': iconBalance,
  'movements': iconMovements,
  'receivable': iconReceivable,
  'payable': iconPayable,
  'chart-pie': iconChartPie,
  'hash': iconHash,
  'server': iconServer,
  'database': iconDatabase,
  'logout': iconLogout,
  'lock': iconLock,
  'calculator': iconCalculator,
  'chart': iconChart,
  'app-settings': iconAppSettings,
  'alert': iconAlert,
  'dashboard': iconDashboard,
  'robot': iconRobot,
  'chip': iconChip,
  'chat': iconChat,
  'cloud-download': iconCloudDownload,
  'dots': iconDots,
  // Feed karty (kind → stavová ikona)
  'check': iconSuccess,
  'question': iconQuestion,
  'warning': iconWarning,
  'info': iconInfo,
};

/**
 * Resolve ikony podle stringu z API.
 * @param {string|undefined} name — název ikony
 * @param {object} fallback — výchozí ikona (default: iconTable)
 * @returns {object} FontAwesome icon definition
 */
export function resolveIcon(name, fallback = iconTable) {
  if (!name) return fallback;
  if (import.meta.env.DEV && !(name in iconMap)) {
    console.warn(`[icons] neznámý klíč ikony "${name}" — fallback na iconTable`);
  }
  return iconMap[name] ?? fallback;
}
