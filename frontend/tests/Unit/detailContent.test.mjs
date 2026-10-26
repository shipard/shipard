import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contentHasAttachments } from '../../src/components/viewer/detailContent.js';

test('tab Přílohy builderu i prázdný obsah', () => {
  assert.equal(contentHasAttachments({ type: 'attachments' }), true);
  assert.equal(contentHasAttachments(null), false);
  assert.equal(contentHasAttachments(undefined), false);
  assert.equal(contentHasAttachments({ type: 'properties', groups: [] }), false);
});

test('attachment-grid uvnitř obsahu není tab Přílohy záznamu', () => {
  assert.equal(contentHasAttachments({ type: 'attachment-grid', attachments: [] }), false);
});

test('composite hledá rekurzivně, i bez blocks', () => {
  assert.equal(contentHasAttachments({ type: 'composite' }), false);
  assert.equal(contentHasAttachments({ type: 'composite', blocks: [{ type: 'html', html: '' }] }), false);
  assert.equal(
    contentHasAttachments({ type: 'composite', blocks: [{ type: 'html', html: '' }, { type: 'composite', blocks: [{ type: 'attachments' }] }] }),
    true,
  );
});
