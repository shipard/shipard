/**
 * API helpers for incoming-mail noise handling (sender rules + auto-archive
 * digest, Fáze 3) and manual upload from the dashboard. Dashboard feed cards
 * call these from Dashboard.svelte.
 */

import { post } from './client.js';
import { API_BASE_URL } from './config.js';

/**
 * Confirm a suggested sender rule (Koncept 10 → 40). The rule starts
 * auto-archiving matching senders on ingest.
 *
 * @param {number} ruleId
 * @returns {Promise<{success: boolean, data?: {id: number, docState: number}, error?: object}>}
 */
export async function confirmSenderRule(ruleId) {
  return await post(`/_mail/sender-rules/${ruleId}/confirm`, {});
}

/**
 * Reject a suggested sender rule (Koncept 10 → 90).
 *
 * @param {number} ruleId
 */
export async function rejectSenderRule(ruleId) {
  return await post(`/_mail/sender-rules/${ruleId}/reject`, {});
}

/**
 * Undo the day's auto-archive: restores messages auto-disposed on `date`
 * (default today; backend allows today/yesterday only) back to Nová (10),
 * re-queues analysis and clears the audit columns.
 *
 * @param {string|null} [date]  YYYY-MM-DD
 * @returns {Promise<{success: boolean, data?: {restored: number}, error?: object}>}
 */
export async function undoAutoArchive(date = null) {
  return await post('/_mail/auto-archive/undo', date ? { date } : {});
}

/**
 * Manual upload from the dashboard (tasks/mail-dashboard-upload.md).
 * Creates one incoming message per file (`perFile`) or a single message
 * with all files attached (`single`); messages queue for AI analysis
 * automatically. Multipart — the shared JSON `post()` helper can't be used,
 * so this mirrors the FormData + Bearer pattern from attachments.js.
 *
 * @param {File[]} files  1..20 files
 * @param {'single'|'perFile'} mode
 * @returns {Promise<{success: boolean, data?: {mode: string, messages: Array<{ndx: number, message_id: string, subject: string}>}, error?: object}>}
 */
export async function uploadMailMessages(files, mode) {
  const formData = new FormData();
  formData.append('mode', mode);
  for (const file of files) {
    formData.append('attachments[]', file, file.name);
  }

  const headers = {};
  const token = localStorage.getItem('shpd_token');
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_mail/messages/upload`, {
    method: 'POST',
    headers,
    body: formData,
  });
  return res.json();
}

/**
 * Odeslat znovu odeslanou zprávu (#90 D44) — další průchod téže zprávy
 * transportem. Odpověď nese nový stav transportu ve tvaru, který zobrazuje
 * formulář zprávy.
 *
 * @param {number} sentMessageId
 * @returns {Promise<{success: boolean, data?: {transportState: string, transport: object}, error?: object}>}
 */
export async function resendSentMessage(sentMessageId) {
  return await post(`/_sent-messages/${sentMessageId}/resend`, {});
}
