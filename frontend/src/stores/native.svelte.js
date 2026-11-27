// Stav mostu nativní aplikace (docs/native-bridge.md, #177 D21): je na
// stránce most, co o sobě říká `info` a které capabilities nabízí.
//
// Načítá se při startu v main.js (`nativeStore.load()` bez await) — start
// aplikace na mostu nestojí. Obrazovky se řídí seznamem capabilities, ne
// platformou ani verzí aplikace (§2.4). Po `device.sync` volat
// `load({refresh: true})`.

import { isNativeAvailable, isNativeMock, nativeInfo } from '../native/bridge.js';

let available = $state(false);
let mock = $state(false);
let info = $state(null);
let error = $state(null);

async function load({ refresh = false } = {}) {
  available = isNativeAvailable();
  mock = isNativeMock();
  if (!available) {
    info = null;
    error = null;
    return null;
  }
  try {
    info = await nativeInfo({ refresh });
    error = null;
  } catch (e) {
    // Most je, ale `info` selhalo — capabilities zůstávají prázdné.
    error = e;
  }
  return info;
}

export const nativeStore = {
  /** Je na stránce most (skutečný, nebo simulace)? */
  get available() { return available; },
  /** Je most simulace pro vývoj (#177 D29)? */
  get mock() { return mock; },
  /** Poslední `info`, `null` bez mostu a před načtením. */
  get info() { return info; },
  get error() { return error; },
  get capabilities() { return info?.capabilities ?? []; },
  has(capability) { return (info?.capabilities ?? []).includes(capability); },
  load,
};
