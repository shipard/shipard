/**
 * Chat store (Svelte 5 runes) — shared state between the conversation list and
 * the active thread.
 *
 * A "new conversation" is a draft (activeId = null, empty thread); the backend
 * row is created lazily on the first `send()`, so the list never fills with
 * empty conversations. After a streamed turn completes (or errors) we refetch
 * the conversation so the thread shows the canonical persisted messages
 * (correct seq / ids / tool_results) rather than the optimistic copy.
 */

import {
  listConversations,
  createConversation,
  getConversation,
  renameConversation,
  deleteConversation,
  sendMessageStream,
} from '../api/chat.js';

let conversations = $state([]);
let activeId = $state(null);
let activeConversation = $state(null); // hlavička z GET detailu (nese section)
let messages = $state([]);
let loadingList = $state(false);
let loadingThread = $state(false);
let streaming = $state(false);
let streamingText = $state('');
let streamingTools = $state([]);
// Model právě přemýšlí (SSE `thinking`, tasks/chat-thinking-blocks.md D56):
// true od začátku thinking bloku do dalšího textu, volání nástroje nebo
// konce odpovědi. Text úvah nechodí — jen indikace.
let thinking = $state(false);
let error = $state(null); // { code, message } | null
// Scope draftu na sekci navigace (UI shells Fáze 5) — nastavuje se při
// newConversation(), odešle se s lazy create v send(); ✕ na chipu ho
// před první zprávou odebere. Po založení drží scope řádek konverzace.
let pendingSection = $state(null);

async function loadConversations() {
  loadingList = true;
  try {
    const res = await listConversations();
    conversations = res && res.success ? res.data : [];
  } finally {
    loadingList = false;
  }
}

async function openConversation(id) {
  activeId = id;
  error = null;
  loadingThread = true;
  try {
    const res = await getConversation(id);
    messages = res && res.success ? res.data.messages : [];
    activeConversation = res && res.success ? res.data.conversation : null;
  } finally {
    loadingThread = false;
  }
}

function newConversation(section = null) {
  activeId = null;
  activeConversation = null;
  messages = [];
  error = null;
  streamingText = '';
  streamingTools = [];
  thinking = false;
  pendingSection = section ?? null;
}

function clearPendingSection() {
  pendingSection = null;
}

async function send(text) {
  const trimmed = (text ?? '').trim();
  if (streaming || trimmed === '') return;

  error = null;
  streaming = true;
  streamingText = '';
  streamingTools = [];
  thinking = false;

  // Optimistic user bubble.
  messages = [
    ...messages,
    { id: `tmp-${Date.now()}`, role: 'user', kind: 'user_text', content: [{ type: 'text', text: trimmed }] },
  ];

  // Create the conversation lazily on first message.
  let id = activeId;
  if (id == null) {
    const created = await createConversation(null, pendingSection);
    if (!created || !created.success) {
      streaming = false;
      error = { code: 'CREATE_FAILED', message: 'Could not create conversation' };
      return;
    }
    id = created.data.id;
    activeId = id;
    await loadConversations();
  }

  await sendMessageStream(id, trimmed, {
    onTextDelta: (delta) => { thinking = false; streamingText += delta; },
    onToolCall: (call) => { thinking = false; streamingTools = [...streamingTools, call]; },
    onThinking: () => { thinking = true; },
    onComplete: () => finalizeTurn(id),
    // openConversation() uvnitř finalizeTurn nuluje `error` — chybu nastavit
    // až PO refetchi, jinak zmizí dřív, než ji uživatel uvidí.
    onError: async (err) => { await finalizeTurn(id); error = err; },
  });
}

async function finalizeTurn(id) {
  // Refetch canonical state, then drop the transient streaming view.
  await openConversation(id);
  await loadConversations(); // reorder list by `modified`
  streamingText = '';
  streamingTools = [];
  thinking = false;
  streaming = false;
}

async function rename(id, title) {
  const res = await renameConversation(id, title);
  if (res && res.success) await loadConversations();
  return res;
}

async function remove(id) {
  const res = await deleteConversation(id);
  if (res && res.success) {
    if (activeId === id) newConversation();
    await loadConversations();
  }
  return res;
}

export const chatStore = {
  get conversations() { return conversations; },
  get activeId() { return activeId; },
  get messages() { return messages; },
  get loadingList() { return loadingList; },
  get loadingThread() { return loadingThread; },
  get streaming() { return streaming; },
  get streamingText() { return streamingText; },
  get streamingTools() { return streamingTools; },
  get thinking() { return thinking; },
  get error() { return error; },
  get pendingSection() { return pendingSection; },
  // Efektivní scope vlákna: draft → pendingSection; založená konverzace →
  // section z detailu (activeConversation), fallback řádek ze seznamu
  // (detail hned po lazy create ještě nemusí být načtený).
  get scopeSection() {
    if (activeId == null) return pendingSection;
    return activeConversation?.section
      ?? conversations.find((c) => c.id === activeId)?.section
      ?? null;
  },
  loadConversations,
  openConversation,
  newConversation,
  clearPendingSection,
  send,
  rename,
  remove,
};
