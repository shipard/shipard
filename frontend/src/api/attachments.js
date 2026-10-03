/**
 * API helpers for the attachment system.
 *
 * Uses the /_attachments/* endpoints.
 * Upload uses multipart/form-data (not JSON).
 */

import { API_BASE_URL } from './config.js';

const TOKEN_KEY = 'shpd_token';

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

/**
 * List attachments for a record.
 * @param {number} tableId - Numeric tableId
 * @param {number} recordId - Record primary key
 * @param {boolean} includeDeleted - Include soft-deleted attachments
 * @returns {Promise<object>}
 */
export async function listAttachments(tableId, recordId, includeDeleted = false) {
  const params = new URLSearchParams({
    table_id: String(tableId),
    record_id: String(recordId),
  });
  if (includeDeleted) params.set('include_deleted', '1');

  const res = await fetch(`${API_BASE_URL}/_attachments?${params}`, {
    headers: buildHeaders(),
  });
  return res.json();
}

/**
 * Upload an attachment.
 * @param {number} tableId
 * @param {number} recordId
 * @param {File} file
 * @returns {Promise<object>}
 */
export async function uploadAttachment(tableId, recordId, file) {
  const formData = new FormData();
  formData.append('table_id', String(tableId));
  formData.append('record_id', String(recordId));
  formData.append('file', file);

  const headers = {};
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(`${API_BASE_URL}/_attachments/upload`, {
    method: 'POST',
    headers,
    body: formData,
  });
  return res.json();
}

/**
 * Rename an attachment.
 * @param {number} id
 * @param {string} newName
 * @returns {Promise<object>}
 */
export async function renameAttachment(id, newName) {
  const res = await fetch(`${API_BASE_URL}/_attachments/${id}`, {
    method: 'PATCH',
    headers: buildHeaders(true),
    body: JSON.stringify({ name: newName }),
  });
  return res.json();
}

/**
 * Zapne / vypne odeslání přílohy se záznamem („Odeslat s dokladem").
 * @param {number} id
 * @param {boolean} value
 * @returns {Promise<object>}
 */
export async function setAttachmentSendFlag(id, value) {
  const res = await fetch(`${API_BASE_URL}/_attachments/${id}`, {
    method: 'PATCH',
    headers: buildHeaders(true),
    body: JSON.stringify({ send_with_record: value }),
  });
  return res.json();
}

/**
 * Soft-delete an attachment.
 * @param {number} id
 * @returns {Promise<boolean>}
 */
export async function deleteAttachment(id) {
  const res = await fetch(`${API_BASE_URL}/_attachments/${id}`, {
    method: 'DELETE',
    headers: buildHeaders(),
  });
  return res.status === 204;
}

/**
 * Restore a soft-deleted attachment.
 * @param {number} id
 * @returns {Promise<object>}
 */
export async function restoreAttachment(id) {
  const res = await fetch(`${API_BASE_URL}/_attachments/${id}/restore`, {
    method: 'POST',
    headers: buildHeaders(true),
  });
  return res.json();
}

/**
 * Build the thumbnail URL for an attachment.
 * @param {number} id
 * @param {number} width
 * @returns {string}
 */
export function thumbnailUrl(id, width = 300) {
  return `${API_BASE_URL}/_attachments/${id}/thumbnail?w=${width}`;
}

/**
 * Build the download URL for an attachment.
 * @param {number} id
 * @returns {string}
 */
export function downloadUrl(id) {
  return `${API_BASE_URL}/_attachments/${id}/download`;
}

/**
 * Build the inline-render URL for an attachment (PDF / image preview
 * in the browser). The server whitelists MIME types and falls back to
 * attachment disposition for anything else — see
 * AttachmentController::computeDisposition().
 * @param {number} id
 * @returns {string}
 */
export function inlineUrl(id) {
  return `${API_BASE_URL}/_attachments/${id}/download?inline=1`;
}

/**
 * Format file size for display.
 * @param {number} bytes
 * @returns {string}
 */
export function formatFileSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

// ── Internal helpers ─────────────────────────────────────────────────────────

function buildHeaders(json = false) {
  const headers = { 'Accept-Language': 'cs' };
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;
  if (json) headers['Content-Type'] = 'application/json';
  return headers;
}
