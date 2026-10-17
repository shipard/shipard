// Vstup vývojářského dashboardu /_dev/ (druhý vstup Vite, vite.dev.config.js).
// Sdílí reset, tokeny a základní typografii s aplikací; theme.css přepisuje
// hodnoty tokenů na světlý vzhled shipard.dev jen v tomhle bundlu (#129 D1).
// Vlastní rozvržení a prvky dashboardu jsou v dev.css (globální třídy `dev-*`).
// Písmo IBM Plex (D3) z @fontsource — jen latinka a latin-ext jako web,
// z vlastního bundlu, žádné Google Fonts.
import { mount } from 'svelte';
import { currentLanguage } from './i18n/index.js';
import '@fontsource/ibm-plex-sans/latin-400.css';
import '@fontsource/ibm-plex-sans/latin-ext-400.css';
import '@fontsource/ibm-plex-sans/latin-500.css';
import '@fontsource/ibm-plex-sans/latin-ext-500.css';
import '@fontsource/ibm-plex-sans/latin-600.css';
import '@fontsource/ibm-plex-sans/latin-ext-600.css';
import '@fontsource/ibm-plex-sans/latin-700.css';
import '@fontsource/ibm-plex-sans/latin-ext-700.css';
import '@fontsource/ibm-plex-mono/latin-400.css';
import '@fontsource/ibm-plex-mono/latin-ext-400.css';
import '@fontsource/ibm-plex-mono/latin-500.css';
import '@fontsource/ibm-plex-mono/latin-ext-500.css';
import '../styles/reset.css';
import '../styles/variables.css';
import '../styles/base.css';
import './theme.css';
import './dev.css';
import DevApp from './DevApp.svelte';

// Jazyk dashboardu (#129 D12): localStorage `shpd_dev_language`, jinak podle
// prohlížeče; <html lang> před prvním renderem, přepínač v liště dělá reload.
document.documentElement.lang = currentLanguage();
mount(DevApp, { target: document.getElementById('app') });
