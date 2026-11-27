/**
 * Odběr čteček (#177 D6, U15): která obrazovka dostane událost
 * `shipard:scan`, rozhoduje webová aplikace. Odběratelé tvoří zásobník —
 * událost dostane poslední přihlášený (obrazovka navrchu), po jeho
 * odhlášení zase předchozí; bez odběratele se událost zahodí.
 *
 * Jeden posluchač `shipard:scan` pro celou aplikaci, přihlášený jen
 * dokud existuje aspoň jeden odběratel.
 */
import { onNativeEvent } from './bridge.js';

const stack = [];
const countListeners = new Set();
let stopListening = null;

function dispatch(detail) {
  const top = stack[stack.length - 1];
  if (top) top.handler(detail);
}

function notifyCount() {
  for (const listener of countListeners) listener(stack.length);
}

/**
 * Přihlásí odběratele navrch zásobníku.
 *
 * @param {(scan: {peripheralId: string, kind: string, data: string, symbology: string|null, at: string}) => void} handler
 * @returns {() => void} odhlášení (opakované volání nic neudělá)
 */
export function onScan(handler) {
  const entry = { handler };
  stack.push(entry);
  if (!stopListening) stopListening = onNativeEvent('scan', dispatch);
  notifyCount();
  return () => {
    const index = stack.indexOf(entry);
    if (index === -1) return;
    stack.splice(index, 1);
    if (stack.length === 0 && stopListening) {
      stopListening();
      stopListening = null;
    }
    notifyCount();
  };
}

/** Počet přihlášených odběratelů (panel simulace, testy). */
export function subscriberCount() {
  return stack.length;
}

/**
 * Hlášení změn počtu odběratelů — panel simulace ho ukazuje vedle čtečky.
 *
 * @param {(count: number) => void} listener
 * @returns {() => void} odhlášení
 */
export function onSubscriberCountChange(listener) {
  countListeners.add(listener);
  return () => countListeners.delete(listener);
}
