// Vstup vývojářského dashboardu /_dev/ (druhý vstup Vite, vite.dev.config.js).
// Sdílí reset, tokeny a základní typografii s aplikací; vlastní rozvržení
// a prvky dashboardu jsou v dev.css (globální třídy `dev-*`).
import { mount } from 'svelte';
import '../styles/reset.css';
import '../styles/variables.css';
import '../styles/base.css';
import './dev.css';
import DevApp from './DevApp.svelte';

mount(DevApp, { target: document.getElementById('app') });
