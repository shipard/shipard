<script>
  // Canonical document visualization with optional interactive resolve.
  //
  // Phase 3a: read-only render. Phase 3b adds interactive status badges:
  // when `onUserActionsChange` callback is provided, non-matched badges
  // become <button>s that open a Popover with a ResolveDecisionPanel
  // (Create / Pick existing / Skip). Decisions are accumulated in
  // `userActions` (prop, parent-owned) and reported back via callback.
  //
  // For `aiFailed=true` (status=70 from /result with ai_failed wrapper) the
  // component switches to an error view that surfaces _validationError +
  // _validationIssues + raw output (collapsible).
  //
  // Props:
  //   canonical            shpd.docs.document.v1 payload (with _resolve)
  //   aiFailed             boolean
  //   wrapper              AI failed wrapper
  //   userActions          flat map {path: action} of accumulated decisions
  //   onUserActionsChange  (next) => void; null = read-only mode (3a)

  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import Popover from '../ui/Popover.svelte';
  import ResolveDecisionPanel from './ResolveDecisionPanel.svelte';
  import RegistryImportWizard from '../registry/RegistryImportWizard.svelte';
  import { enrichedRowCount, matchKindKey, suggestedFieldKeys } from './enrichBadge.js';
  import {
    findRegistryQuickHit,
    fetchRegistryPerson,
    applyRegistryPerson,
  } from '../../api/personsRegistry.js';

  let {
    canonical = null,
    aiFailed = false,
    wrapper = null,
    userActions = {},
    onUserActionsChange = null,
  } = $props();

  // Derived helpers
  let docType = $derived(canonical?.docType ?? null);
  let docTypeLabel = $derived(
    docType ? t(`exchange.preview.docType.${docType}`) : '',
  );
  let resolve = $derived(canonical?._resolve ?? null);
  let summary = $derived(resolve?.summary ?? null);
  let issues = $derived(resolve?.issues ?? []);
  let enrichedCount = $derived(enrichedRowCount(resolve?.rows));
  // Sloupec Účet se ukazuje, jen když ho aspoň jeden řádek nese —
  // faktury bez kontace/enrichmentu zůstávají beze změny (D23).
  let hasAccountColumn = $derived(
    (canonical?.rows ?? []).some((r) => r?.account != null && String(r.account) !== ''),
  );

  // ── DPH & platba: lokalizace canonical enum klíčů ──────────────────────
  // Canonical nese stringové klíče (fromBase, domestic, bankTransfer, …);
  // schema je nevaliduje (volný string), neznámý klíč proto zobrazíme
  // surově. Chybějící vat.mode / vat.place ukazujeme jako applierův
  // default (fromBase / domestic) se ztlumeným „(výchozí)“ — uživatel
  // vidí, co se při uložení reálně použije (DocumentApplier::VAT_*_MAP).
  const VAT_MODE_KEYS = ['none', 'fromBase', 'fromTotal'];
  const VAT_PLACE_KEYS = ['domestic', 'intracom', 'thirdCountry'];
  const PAYMENT_METHOD_KEYS = ['cash', 'bankTransfer', 'card', 'cashOnDelivery', 'setOff'];

  function enumLabel(prefix, keys, value) {
    if (value === null || value === undefined) return null;
    return keys.includes(value) ? t(`exchange.preview.${prefix}.${value}`) : String(value);
  }

  function enumWithDefault(prefix, keys, value, fallback) {
    const isDefault = value === null || value === undefined;
    return {
      text: enumLabel(prefix, keys, isDefault ? fallback : value),
      isDefault,
    };
  }

  let vatModeDisplay = $derived(
    enumWithDefault('vatMode', VAT_MODE_KEYS, canonical?.vat?.mode ?? null, 'fromBase'),
  );
  let vatPlaceDisplay = $derived(
    enumWithDefault('vatPlace', VAT_PLACE_KEYS, canonical?.vat?.place ?? null, 'domestic'),
  );
  let paymentMethodLabel = $derived(
    enumLabel('paymentMethod', PAYMENT_METHOD_KEYS, canonical?.payment?.method ?? null),
  );

  // Tooltip enrichment badge: „Doplněno z historie — doklad X (přesná
  // shoda)" + řádek „Položka: kód — jméno“ (enrichment.itemName) +
  // řádek s výčtem skutečně doplněných polí. U dominance
  // nese kind i podíl položky na řádcích historie (enrichment.dominance).
  // Obsahov\u00e1 klasifikace (matchedBy 'contentTag') m\u00e1 vlastn\u00ed header \u2014
  // blok nenese sourceDocId a matchKindKey by lhal \u201ep\u0159esn\u00e1 shoda".
  function enrichTitle(e) {
    let header;
    if (e.matchedBy === 'contentTag') {
      header = t('exchange.preview.enrich.contentTag', {
        tag: e.tagLabel ?? e.tag ?? '\u2014',
        source: t(`exchange.preview.enrich.tagSource.${e.tagSource === 'rule' ? 'rule' : 'llm'}`),
      });
    } else {
      const docNumber = e.sourceDocNumber ?? `#${e.sourceDocId}`;
      const kindKey = matchKindKey(e.matchedBy);
      const kind =
        kindKey === 'dominant' && e.dominance?.share != null
          ? t('exchange.preview.enrich.kind.dominantShare', {
              share: Math.round(e.dominance.share * 100),
            })
          : t(`exchange.preview.enrich.kind.${kindKey}`);
      header = t('exchange.preview.enrich.tooltip', { docNumber, kind });
    }
    const fields = suggestedFieldKeys(e.suggested)
      .map((key) => t(`exchange.preview.enrich.field.${key}`))
      .join(', ');
    const lines = [header];
    if (e.suggested?.ourCode) {
      lines.push(
        t('exchange.preview.enrich.item', {
          code: e.suggested.ourCode,
          name: e.itemName ?? '\u2014',
        }),
      );
    }
    if (fields) lines.push(t('exchange.preview.enrich.filled', { fields }));
    if (e.vatHint === 'nonDeductible') {
      lines.push(t('exchange.preview.enrich.vatHint.nonDeductible'));
    }
    return lines.join('\n');
  }

  function statusKey(status) {
    if (status === 'matched') return 'matched';
    if (status === 'canCreate') return 'canCreate';
    if (status === 'ambiguous') return 'ambiguous';
    if (status === 'notFound') return 'notFound';
    return null;
  }

  function statusLabel(resolveBlock) {
    if (!resolveBlock) return null;
    const s = resolveBlock.status;
    if (s === 'matched') {
      const id = resolveBlock.matchedId;
      const by = resolveBlock.matchedBy;
      if (by === 'created') {
        return t('exchange.preview.status.matchedCreated', { id });
      }
      return t('exchange.preview.status.matched', { id });
    }
    if (s === 'canCreate') return t('exchange.preview.status.canCreate');
    if (s === 'ambiguous') {
      return t('exchange.preview.status.ambiguous', {
        count: (resolveBlock.candidates ?? []).length,
      });
    }
    if (s === 'notFound') return t('exchange.preview.status.notFound');
    return null;
  }

  function statusGlyph(s) {
    if (s === 'matched') return '✓';
    if (s === 'canCreate') return '+';
    if (s === 'ambiguous') return '?';
    if (s === 'notFound') return '✗';
    if (s === 'matchedDecided') return '✓';
    if (s === 'canCreateDecided') return '+';
    if (s === 'skipped') return '⊘';
    if (s === 'noItemDecided') return '✓';
    return null;
  }

  // „Jen účet — bez položky" (D24) se nabízí jen na řádku, který účet
  // reálně nese (z extrakce nebo propsaný enrichmentem do canonical).
  function rowHasAccount(i) {
    const account = canonical?.rows?.[i]?.account;
    return account != null && String(account) !== '';
  }

  function rowHasAccountForPath(path) {
    const m = /^rows\[(\d+)\]\.item$/.exec(path ?? '');
    return m !== null && rowHasAccount(Number(m[1]));
  }

  // ── Phase 3b: interactive decisions ────────────────────────────────────

  // decisionOpen tracks the popover state. When non-null:
  //   { anchor: HTMLElement, path: string, resolveBlock, kind, table,
  //     parentMatchedId }
  let decisionOpen = $state(null);

  // ── Hromadné naplnění položky (Issue #29) ──────────────────────────────
  // U dokladů s mnoha řádky se často používá jedna položka. Badge `+`
  // v hlavičce sloupce „Položka“ otevře stejný ResolveDecisionPanel a
  // rozhodnutí zapíše do všech ne-matched řádků naráz (včetně přepisu
  // dřívějších per-row rozhodnutí). Matched řádky zůstávají nedotčené —
  // konzistentně s tím, že jejich řádkový badge není interaktivní.
  let bulkItemIndices = $derived.by(() => {
    const rows = resolve?.rows ?? [];
    const out = [];
    for (let i = 0; i < rows.length; i++) {
      const st = rows[i]?.item?.status;
      if (st && st !== 'matched') out.push(i);
    }
    return out;
  });
  let bulkItemVisible = $derived(
    onUserActionsChange !== null && bulkItemIndices.length >= 2,
  );

  function openBulkItemDecision(event) {
    if (onUserActionsChange === null) return;
    event.preventDefault();
    event.stopPropagation();
    decisionOpen = {
      anchor: event.currentTarget,
      path: null,
      resolveBlock: null,
      kind: 'item',
      parentMatchedId: null,
      bulkPaths: bulkItemIndices.map((i) => `rows[${i}].item`),
      // Bulk noItem jen když účet nesou všechny cílové řádky — částečný
      // zápis by na zbytku vyrobil apply chybu no_item_requires_account.
      allowNoItem: bulkItemIndices.every((i) => rowHasAccount(i)),
      ...entityConfigForKind('item'),
    };
  }

  function entityConfigForKind(kind) {
    if (kind === 'party')       return { table: 'base_persons_persons' };
    if (kind === 'item')        return { table: 'economy_items' };
    if (kind === 'bankAccount') return { table: 'base_persons_bank_accounts' };
    return null;
  }

  // For a bank-account decision we need the resolved supplier person id, so
  // the panel can filter the lookup search and pre-fill the create form.
  // `path` for supplier bank is e.g. `supplier.bankAccount` — derive the
  // owning party path by stripping the last segment and read its matched id.
  function parentMatchedIdForPath(path, kind) {
    if (kind !== 'bankAccount' || !path) return null;
    const parentPath = path.replace(/\.[^.]+$/, '');
    if (parentPath === path) return null;
    // Walk _resolve via the parent path. Only the supplier/customer slots
    // currently carry bankAccount, so a 2-level lookup is enough.
    const parentBlock = parentPath
      .split('.')
      .reduce((acc, seg) => (acc && typeof acc === 'object' ? acc[seg] : undefined), resolve);
    if (!parentBlock) return null;
    if (parentBlock.status === 'matched' && parentBlock.matchedId != null) {
      return parentBlock.matchedId;
    }
    // Also accept a user-side decision to use an existing party.
    const parentAction = userActions[parentPath] ?? null;
    if (typeof parentAction === 'string' && parentAction.startsWith('useExisting:')) {
      const id = Number(parentAction.slice('useExisting:'.length));
      return Number.isFinite(id) ? id : null;
    }
    return null;
  }

  function openDecision(event, path, resolveBlock, kind) {
    if (onUserActionsChange === null) return; // read-only mode
    event.preventDefault();
    event.stopPropagation();
    const cfg = entityConfigForKind(kind);
    if (!cfg) return;
    decisionOpen = {
      anchor: event.currentTarget,
      path,
      resolveBlock,
      kind,
      parentMatchedId: parentMatchedIdForPath(path, kind),
      allowNoItem: kind === 'item' && rowHasAccountForPath(path),
      ...cfg,
    };
  }

  function closeDecision() {
    decisionOpen = null;
  }

  function decideForPath(path, action) {
    const next = { ...userActions };
    if (action === null || action === undefined) {
      delete next[path];
    } else {
      next[path] = action;
    }
    onUserActionsChange?.(next);
  }

  function handleDecide(action) {
    if (!decisionOpen) return;
    if (decisionOpen.bulkPaths) {
      // Bulk: všechny cesty v jednom novém objektu — jediné volání
      // onUserActionsChange, žádné N dílčích callbacků.
      const next = { ...userActions };
      for (const path of decisionOpen.bulkPaths) {
        if (action === null || action === undefined) {
          delete next[path];
        } else {
          next[path] = action;
        }
      }
      onUserActionsChange?.(next);
    } else {
      decideForPath(decisionOpen.path, action);
    }
    decisionOpen = null;
  }

  // ── Quick-add z registru (Issue #28) ────────────────────────────────────
  // Předkontrola: nespárovaná strana s vytěženým IČO se na pozadí ověří
  // proti ARES/RPO registru; při jednoznačném nálezu se na kartě strany
  // i v resolve popoveru nabídne jednoklikové vytvoření osoby.

  // path ('supplier' | 'customer') → SearchResultRow z registru, nebo null.
  let registryHits = $state({});
  // path → true během fetch+apply (loading na tlačítku, single-flight).
  let quickAddBusy = $state({});
  // path → lokalizovaná chybová hláška posledního pokusu, nebo null.
  let quickAddError = $state({});
  // Wizard fallback: null | { path, initialQuery }
  let registrySearchOpen = $state(null);
  // Token běžící předkontroly — přepnutí zprávy zahodí staré odpovědi.
  let registryCheckToken = 0;

  // IČO čteme z canonical party bloku, ne z createPayload — ambiguous
  // createPayload nenese a hodnoty jsou identické (payload se z něj staví).
  function partyCompanyId(path) {
    const raw = canonical?.[path]?.companyId;
    return typeof raw === 'string' ? raw.trim() : '';
  }

  $effect(() => {
    const currentResolve = resolve;
    // Reset při každé změně preview dat (přepnutí zprávy v modalu).
    registryHits = {};
    quickAddBusy = {};
    quickAddError = {};
    registrySearchOpen = null;
    const token = ++registryCheckToken;
    if (onUserActionsChange === null || aiFailed || !currentResolve) return;
    for (const path of ['supplier', 'customer']) {
      const block = currentResolve[path];
      if (!block || block.status === 'matched') continue;
      const companyId = partyCompanyId(path);
      if (companyId === '') continue;
      void findRegistryQuickHit(companyId).then((hit) => {
        if (token !== registryCheckToken || hit === null) return;
        registryHits = { ...registryHits, [path]: hit };
      });
    }
  });

  // Jediné místo quick-add logiky — sdílí ho karta strany i popover panel.
  // Vrací true při úspěchu; zavření popoveru řeší volající (karta nezavírá).
  async function handleRegistryQuickAdd(path) {
    const hit = registryHits[path];
    if (!hit || quickAddBusy[path]) return false;
    quickAddBusy = { ...quickAddBusy, [path]: true };
    quickAddError = { ...quickAddError, [path]: null };
    try {
      const fetched = await fetchRegistryPerson(hit.country, hit.companyId);
      if (!fetched?.success) {
        quickAddError = { ...quickAddError, [path]: translateError(fetched?.error) };
        return false;
      }
      const applied = await applyRegistryPerson({
        ...fetched.data,
        applyOptions: { mergeStrategy: 'createOnly', targetDocState: 40 },
      });
      if (applied?.success) {
        decideForPath(path, `useExisting:${applied.data?.savedPersonId}`);
        return true;
      }
      // Souběh: osobu mezitím někdo vytvořil → applier vrací person_exists
      // (409) s čerstvě resolvnutým matchedId v error.details.canonical.
      const matchedId = applied?.error?.code === 'person_exists'
        ? applied?.error?.details?.canonical?._resolve?.header?.matchedId ?? null
        : null;
      if (matchedId != null) {
        decideForPath(path, `useExisting:${matchedId}`);
        return true;
      }
      quickAddError = { ...quickAddError, [path]: translateError(applied?.error) };
      return false;
    } finally {
      quickAddBusy = { ...quickAddBusy, [path]: false };
    }
  }

  function openRegistrySearch(path) {
    const initialQuery = partyCompanyId(path) || (canonical?.[path]?.name ?? '');
    registrySearchOpen = { path, initialQuery };
    closeDecision();
  }

  function effectiveStatusKey(path, resolveBlock) {
    const original = statusKey(resolveBlock?.status);
    if (path === null || onUserActionsChange === null) return original;
    if (resolveBlock?.status === 'matched') return 'matched';
    const ua = userActions[path] ?? null;
    if (ua === null) return original;
    if (typeof ua === 'string' && ua.startsWith('useExisting:')) return 'matchedDecided';
    if (ua === 'create') return 'canCreateDecided';
    if (ua === 'skip') return 'skipped';
    if (ua === 'noItem') return 'noItemDecided';
    return original;
  }

  function effectiveStatusLabel(path, resolveBlock) {
    if (path === null || onUserActionsChange === null) return statusLabel(resolveBlock);
    const ua = userActions[path] ?? null;
    if (ua === null) return statusLabel(resolveBlock);
    if (typeof ua === 'string' && ua.startsWith('useExisting:')) {
      return t('exchange.preview.status.decided.useExisting', { id: ua.slice('useExisting:'.length) });
    }
    if (ua === 'create') return t('exchange.preview.status.decided.create');
    if (ua === 'skip') return t('exchange.preview.status.decided.skip');
    if (ua === 'noItem') return t('exchange.preview.status.decided.noItem');
    return statusLabel(resolveBlock);
  }

  function formatMoney(value, currency) {
    if (value === null || value === undefined) return '—';
    try {
      return new Intl.NumberFormat('cs-CZ', {
        style: 'currency',
        currency: currency ?? 'CZK',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value);
    } catch {
      return String(value);
    }
  }

  function formatDate(iso) {
    if (!iso) return '—';
    try {
      return new Intl.DateTimeFormat('cs-CZ').format(new Date(iso));
    } catch {
      return String(iso);
    }
  }

  function formatAddress(addr) {
    if (!addr) return '—';
    if (addr.displayLine) return addr.displayLine;
    const street = [addr.street, addr.houseNumber].filter(Boolean).join(' ');
    const city = [addr.zip, addr.city].filter(Boolean).join(' ');
    return [street, city].filter(Boolean).join(', ') || '—';
  }

  function issueGlyph(severity) {
    if (severity === 'error') return '✗';
    if (severity === 'warning') return '⚠';
    return 'ℹ';
  }
</script>

{#snippet statusBadge(resolveBlock, path = null, kind = null)}
  {#if resolveBlock && statusKey(resolveBlock.status)}
    {@const modifier = effectiveStatusKey(path, resolveBlock)}
    {@const label = effectiveStatusLabel(path, resolveBlock)}
    {@const interactive
      = onUserActionsChange !== null
      && resolveBlock.status !== 'matched'
      && path !== null
      && kind !== null}
    {#if interactive}
      <button
        type="button"
        class="shpd-exchange__status shpd-exchange__status--{modifier} shpd-exchange__status--interactive"
        title={label}
        onclick={(e) => openDecision(e, path, resolveBlock, kind)}
      >
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </button>
    {:else}
      <span
        class="shpd-exchange__status shpd-exchange__status--{modifier}"
        title={label}
      >
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </span>
    {/if}
  {/if}
{/snippet}

{#snippet enrichBadge(e)}
  {#if e?.matchedBy}
    <span
      class="shpd-exchange__enrich shpd-exchange__enrich--{e.confidence}"
      title={enrichTitle(e)}
    >⟲</span>
  {/if}
{/snippet}

{#snippet partyCard(label, party, partyResolve, path)}
  <div class="shpd-exchange__party">
    <div class="shpd-exchange__party-header">
      <h3 class="shpd-exchange__party-label">{label}</h3>
      {@render statusBadge(partyResolve, path, 'party')}
    </div>
    {#if party}
      <div class="shpd-exchange__party-body">
        {#if party.name}
          <div class="shpd-exchange__party-name">{party.name}</div>
        {/if}
        {#if party.companyId || party.taxId || party.vatId}
          <div class="shpd-exchange__party-ids">
            {#if party.companyId}
              <span>{t('exchange.preview.field.companyId')}: <strong>{party.companyId}</strong></span>
            {/if}
            {#if party.taxId}
              <span>{t('exchange.preview.field.taxId')}: <strong>{party.taxId}</strong></span>
            {/if}
            {#if party.vatId && party.vatId !== party.taxId}
              <span>{t('exchange.preview.field.vatId')}: <strong>{party.vatId}</strong></span>
            {/if}
          </div>
        {/if}
        {#if party.address}
          <div class="shpd-exchange__party-address">{formatAddress(party.address)}</div>
        {/if}
        {#if party.contact?.email || party.contact?.phone}
          <div class="shpd-exchange__party-contact">
            {#if party.contact?.email}<span>{party.contact.email}</span>{/if}
            {#if party.contact?.phone}<span>{party.contact.phone}</span>{/if}
          </div>
        {/if}
        {#if party.bankAccount?.iban || party.bankAccount?.accountNumber}
          <div class="shpd-exchange__party-bank">
            {t('exchange.preview.field.bankAccount')}:
            <strong>{party.bankAccount.iban ?? party.bankAccount.accountNumber}</strong>
          </div>
        {/if}
      </div>
    {:else}
      <div class="shpd-exchange__party-empty">—</div>
    {/if}
    {#if onUserActionsChange !== null
         && registryHits[path]
         && (userActions[path] ?? null) === null
         && partyResolve?.status !== 'matched'}
      <div class="shpd-exchange__party-registry">
        <button
          type="button"
          class="shpd-exchange__party-registry-btn"
          onclick={() => handleRegistryQuickAdd(path)}
          disabled={quickAddBusy[path]}
        >
          + {quickAddBusy[path]
            ? t('exchange.preview.registry.creating')
            : t('exchange.preview.registry.quickAdd', { name: registryHits[path].fullName })}
        </button>
        {#if quickAddError[path]}
          <span class="shpd-exchange__party-registry-error">{quickAddError[path]}</span>
        {/if}
      </div>
    {/if}
  </div>
{/snippet}

{#snippet field(label, value, hint = null)}
  <div class="shpd-exchange__field">
    <span class="shpd-exchange__field-label">{label}</span>
    <span class="shpd-exchange__field-value">
      {value ?? '—'}{#if hint}
        <span class="shpd-exchange__field-hint">{hint}</span>
      {/if}
    </span>
  </div>
{/snippet}

{#if aiFailed && wrapper}
  <div class="shpd-exchange shpd-exchange--ai-failed">
    <div class="shpd-exchange__ai-failed-header">
      <span class="shpd-exchange__ai-failed-icon" aria-hidden="true">⚠</span>
      <h2>{t('exchange.preview.aiFailed.title')}</h2>
    </div>
    <p class="shpd-exchange__ai-failed-message">
      {t('exchange.preview.aiFailed.message')}
    </p>
    {#if wrapper._validationIssues && wrapper._validationIssues.length > 0}
      <h3>{t('exchange.preview.aiFailed.issues')}</h3>
      <ul class="shpd-exchange__ai-failed-issues">
        {#each wrapper._validationIssues as issue}
          <li>
            <code>{issue.path}</code> — {issue.message}
          </li>
        {/each}
      </ul>
    {/if}
    <details class="shpd-exchange__ai-failed-raw">
      <summary>{t('exchange.preview.aiFailed.rawOutput')}</summary>
      <pre>{JSON.stringify(wrapper._rawOutput, null, 2)}</pre>
    </details>
  </div>
{:else if canonical}
  <div class="shpd-exchange">
    <header class="shpd-exchange__header">
      <div class="shpd-exchange__header-main">
        <span class="shpd-exchange__type-badge">{docTypeLabel}</span>
        <h2 class="shpd-exchange__title">{canonical.docNumber ?? '—'}</h2>
        {#if canonical.docText}
          <p class="shpd-exchange__subtitle">{canonical.docText}</p>
        {/if}
      </div>
      {#if summary}
        <span
          class="shpd-exchange__summary-badge shpd-exchange__summary-badge--{summary.status}"
        >
          {summary.status === 'ok'
            ? t('exchange.preview.status.summary.ok')
            : t('exchange.preview.status.summary.needsAttention')}
        </span>
      {/if}
    </header>

    <section class="shpd-exchange__parties">
      {@render partyCard(
        t('exchange.preview.section.supplier'),
        canonical.supplier,
        resolve?.supplier,
        'supplier',
      )}
      {@render partyCard(
        t('exchange.preview.section.customer'),
        canonical.customer,
        resolve?.customer,
        'customer',
      )}
    </section>

    <section class="shpd-exchange__meta-grid">
      {@render field(t('exchange.preview.field.issueDate'), formatDate(canonical.dates?.issueDate))}
      {@render field(t('exchange.preview.field.dueDate'), formatDate(canonical.dates?.dueDate))}
      {@render field(t('exchange.preview.field.accountingDate'), formatDate(canonical.dates?.accountingDate))}
      {@render field(t('exchange.preview.field.taxPointDate'), formatDate(canonical.dates?.taxPointDate))}
      {@render field(t('exchange.preview.field.vatObligationDate'), formatDate(canonical.dates?.vatObligationDate))}
      {@render field(t('exchange.preview.field.currency'), canonical.currency)}
      {@render field(t('exchange.preview.field.paymentMethod'), paymentMethodLabel)}
      {@render field(t('exchange.preview.field.paymentReference'), canonical.payment?.paymentReference)}
      {@render field(
        t('exchange.preview.field.vatMode'),
        vatModeDisplay.text,
        vatModeDisplay.isDefault ? t('exchange.preview.defaultHint') : null,
      )}
      {@render field(
        t('exchange.preview.field.vatPlace'),
        vatPlaceDisplay.text,
        vatPlaceDisplay.isDefault ? t('exchange.preview.defaultHint') : null,
      )}
    </section>

    {#if (canonical.rows ?? []).length > 0}
      <section class="shpd-exchange__section">
        <h3 class="shpd-exchange__section-heading shpd-exchange__section-heading--split">
          <span>{t('exchange.preview.section.rows')}</span>
          {#if enrichedCount > 0}
            <span class="shpd-exchange__enrich-summary">
              {t('exchange.preview.enrich.summary', { count: enrichedCount })}
            </span>
          {/if}
        </h3>
        <table class="shpd-exchange__rows">
          <thead>
            <tr>
              <th class="shpd-exchange__rows-pos">{t('exchange.preview.row.position')}</th>
              <th>
                {t('exchange.preview.row.item')}
                {#if bulkItemVisible}
                  <button
                    type="button"
                    class="shpd-exchange__status shpd-exchange__status--canCreate shpd-exchange__status--interactive"
                    title={t('exchange.preview.bulk.itemTitle', { count: bulkItemIndices.length })}
                    onclick={openBulkItemDecision}
                  >
                    <span class="shpd-exchange__status-glyph">+</span>
                  </button>
                {/if}
              </th>
              {#if hasAccountColumn}
                <th>{t('exchange.preview.row.account')}</th>
              {/if}
              <th class="num">{t('exchange.preview.row.quantity')}</th>
              <th>{t('exchange.preview.row.unit')}</th>
              <th class="num">{t('exchange.preview.row.unitPrice')}</th>
              <th>{t('exchange.preview.row.vat')}</th>
              <th class="num">{t('exchange.preview.row.total')}</th>
            </tr>
          </thead>
          <tbody>
            {#each canonical.rows as row, i}
              <tr>
                <td>{row.orderPos ?? i + 1}</td>
                <td>
                  <!-- Row text composed server-side (_resolve.rows[i].rowText, #84) — the same
                       text the applier writes to the document. item.name fallback only for
                       a canonical without _resolve (previewMessage without applier). -->
                  <span class="shpd-exchange__row-name">{resolve?.rows?.[i]?.rowText ?? row.item?.name ?? '—'}</span>
                  {#if row.item?.supplierCode}
                    <span class="shpd-exchange__row-code">{row.item.supplierCode}</span>
                  {/if}
                  {@render statusBadge(resolve?.rows?.[i]?.item, `rows[${i}].item`, 'item')}
                  {@render enrichBadge(resolve?.rows?.[i]?.enrichment)}
                </td>
                {#if hasAccountColumn}
                  <td>
                    {#if row.account}
                      <span class="shpd-exchange__row-code">{row.account}</span>
                      {@render statusBadge(resolve?.rows?.[i]?.account)}
                    {:else}
                      —
                    {/if}
                  </td>
                {/if}
                <td class="num">{row.quantity ?? '—'}</td>
                <td>
                  {row.unit ?? '—'}
                  {@render statusBadge(resolve?.rows?.[i]?.unit)}
                </td>
                <td class="num">{formatMoney(row.unitPrice, canonical.currency)}</td>
                <td>
                  {row.vat?.pct ?? '—'}%
                  {@render statusBadge(resolve?.rows?.[i]?.vatCode)}
                </td>
                <td class="num">{formatMoney(row.totalPrice, canonical.currency)}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      </section>
    {/if}

    {#if (canonical.vatRecap ?? []).length > 0 || canonical.totals}
      <section class="shpd-exchange__section shpd-exchange__totals-section">
        {#if (canonical.vatRecap ?? []).length > 0}
          <table class="shpd-exchange__vat-recap">
            <thead>
              <tr>
                <th>{t('exchange.preview.row.vat')}</th>
                <th class="num">{t('exchange.preview.totals.base')}</th>
                <th class="num">{t('exchange.preview.totals.vat')}</th>
                <th class="num">{t('exchange.preview.totals.total')}</th>
              </tr>
            </thead>
            <tbody>
              {#each canonical.vatRecap as r}
                <tr>
                  <td>{r.vatPct}%</td>
                  <td class="num">{formatMoney(r.base, canonical.currency)}</td>
                  <td class="num">{formatMoney(r.tax, canonical.currency)}</td>
                  <td class="num">{formatMoney(r.total, canonical.currency)}</td>
                </tr>
              {/each}
            </tbody>
          </table>
        {/if}
        {#if canonical.totals}
          <div class="shpd-exchange__totals-summary">
            <div>
              {t('exchange.preview.totals.base')}:
              <strong>{formatMoney(canonical.totals.totalBase, canonical.currency)}</strong>
            </div>
            <div>
              {t('exchange.preview.totals.vat')}:
              <strong>{formatMoney(canonical.totals.totalVat, canonical.currency)}</strong>
            </div>
            <div class="shpd-exchange__total" data-testid="review-total">
              {t('exchange.preview.totals.total')}:
              <strong>{formatMoney(canonical.totals.totalAmount, canonical.currency)}</strong>
            </div>
            {#if canonical.totals.totalRounding}
              <div>
                {t('exchange.preview.totals.rounding')}:
                <strong>{formatMoney(canonical.totals.totalRounding, canonical.currency)}</strong>
              </div>
            {/if}
          </div>
        {/if}
      </section>
    {/if}

    {#if issues.length > 0}
      <section class="shpd-exchange__section shpd-exchange__issues">
        <h3 class="shpd-exchange__section-heading">
          {t('exchange.preview.section.issues')}
        </h3>
        <ul>
          {#each issues as issue}
            <li class="shpd-exchange__issue shpd-exchange__issue--{issue.severity}">
              <span class="shpd-exchange__issue-glyph" aria-hidden="true">
                {issueGlyph(issue.severity)}
              </span>
              <code>{issue.path}</code>
              <span>—</span>
              <span>{issue.message}</span>
            </li>
          {/each}
        </ul>
      </section>
    {/if}
  </div>
{/if}

{#if decisionOpen !== null}
  <Popover
    open={true}
    anchor={decisionOpen.anchor}
    placement="bottom"
    width="400px"
    onClose={closeDecision}
  >
    <ResolveDecisionPanel
      resolveBlock={decisionOpen.resolveBlock}
      referenceKind={decisionOpen.kind}
      entityTable={decisionOpen.table}
      createPayload={decisionOpen.resolveBlock?.createPayload ?? null}
      parentMatchedId={decisionOpen.parentMatchedId}
      currentUserAction={decisionOpen.path !== null ? userActions[decisionOpen.path] ?? null : null}
      allowNoItem={decisionOpen.allowNoItem ?? false}
      bulkCount={decisionOpen.bulkPaths?.length ?? 0}
      bulkDecidedCount={decisionOpen.bulkPaths
        ? decisionOpen.bulkPaths.filter((p) => (userActions[p] ?? null) !== null).length
        : 0}
      onDecide={handleDecide}
      registryHit={decisionOpen.kind === 'party' ? registryHits[decisionOpen.path] ?? null : null}
      registryBusy={quickAddBusy[decisionOpen.path] ?? false}
      registryError={decisionOpen.kind === 'party' ? quickAddError[decisionOpen.path] ?? null : null}
      onRegistryQuickAdd={decisionOpen.kind === 'party'
        ? () => {
            const path = decisionOpen.path;
            void handleRegistryQuickAdd(path).then((ok) => {
              if (ok) closeDecision();
            });
          }
        : null}
      onOpenRegistrySearch={decisionOpen.kind === 'party'
        ? () => openRegistrySearch(decisionOpen.path)
        : null}
    />
  </Popover>
{/if}

<RegistryImportWizard
  open={registrySearchOpen !== null}
  initialQuery={registrySearchOpen?.initialQuery ?? ''}
  onClose={() => (registrySearchOpen = null)}
  onSaved={(personId) => {
    if (registrySearchOpen && personId != null) {
      decideForPath(registrySearchOpen.path, `useExisting:${personId}`);
    }
    registrySearchOpen = null;
  }}
/>

<style>
  .shpd-exchange {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-md);
    font-size: 0.875rem;
  }

  .shpd-exchange__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    padding-bottom: var(--shpd-space-md);
  }

  .shpd-exchange__type-badge {
    display: inline-block;
    padding: 2px 8px;
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
    border-radius: 4px;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
  }

  .shpd-exchange__subtitle {
    margin: var(--shpd-space-xs) 0 0;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__summary-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    white-space: nowrap;
  }

  .shpd-exchange__summary-badge--ok {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
  }

  .shpd-exchange__summary-badge--needsAttention {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  /* ── Parties ─────────────────────────────────────────────────────────── */

  .shpd-exchange__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  @media (max-width: 600px) {
    .shpd-exchange__parties {
      grid-template-columns: 1fr;
    }
  }

  .shpd-exchange__party {
    border: 1px solid var(--shpd-color-border);
    border-radius: 6px;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-surface);
  }

  .shpd-exchange__party-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-label {
    margin: 0;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__party-name {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-ids,
  .shpd-exchange__party-contact {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-md);
    font-size: 0.8125rem;
    color: var(--shpd-color-text-muted);
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-address,
  .shpd-exchange__party-bank {
    font-size: 0.8125rem;
    margin-bottom: var(--shpd-space-xs);
  }

  .shpd-exchange__party-empty {
    color: var(--shpd-color-text-muted);
    font-style: italic;
  }

  /* Quick-add z registru (Issue #28) — vizuálně zrcadlí .shpd-resolve__create
     v ResolveDecisionPanel, aby obě místa nabízela stejně vypadající akci. */
  .shpd-exchange__party-registry {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-xs);
  }

  .shpd-exchange__party-registry-btn {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-primary);
    font-family: inherit;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    text-align: left;
  }

  .shpd-exchange__party-registry-btn:hover:not(:disabled) {
    background-color: var(--shpd-color-primary-soft);
  }

  .shpd-exchange__party-registry-btn:disabled {
    cursor: default;
    opacity: 0.7;
  }

  .shpd-exchange__party-registry-error {
    font-size: 0.75rem;
    color: var(--shpd-color-danger);
  }

  /* ── Meta grid ───────────────────────────────────────────────────────── */

  .shpd-exchange__meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-exchange__field {
    display: flex;
    flex-direction: column;
  }

  .shpd-exchange__field-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    letter-spacing: 0.5px;
  }

  .shpd-exchange__field-value {
    font-size: 0.875rem;
  }

  .shpd-exchange__field-hint {
    margin-left: 4px;
    font-size: 0.75rem;
    color: var(--shpd-color-text-muted);
  }

  /* ── Status badges ───────────────────────────────────────────────────── */

  .shpd-exchange__status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    margin-left: var(--shpd-space-xs);
    font-size: 0.625rem;
    font-weight: 700;
    vertical-align: middle;
  }

  .shpd-exchange__status-glyph {
    line-height: 1;
  }

  .shpd-exchange__status--matched {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
  }

  .shpd-exchange__status--canCreate {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  .shpd-exchange__status--ambiguous {
    background: var(--shpd-color-accent-soft);
    color: var(--shpd-color-accent);
  }

  .shpd-exchange__status--notFound {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  /* Phase 3b: clickable badge (`<button>` element variant). Removes the
     default button chrome — visual stays identical to the read-only span. */
  .shpd-exchange__status--interactive {
    cursor: pointer;
    padding: 0;
    border: 0;
    font-family: inherit;
  }

  .shpd-exchange__status--interactive:hover {
    filter: brightness(1.1);
  }

  .shpd-exchange__status--interactive:focus-visible {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: 1px;
  }

  /* Decided modifiers — same color palette as the original status plus a
     2px outline to signal "user made a choice". Outline is more legible at
     18px than a combined "✓+" glyph and works across all bg colors. */
  .shpd-exchange__status--matchedDecided {
    background: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
    outline: 2px solid var(--shpd-color-state-done-text);
    outline-offset: 1px;
  }

  .shpd-exchange__status--canCreateDecided {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
    outline: 2px solid var(--shpd-color-state-concept-text);
    outline-offset: 1px;
  }

  .shpd-exchange__status--skipped {
    background: var(--shpd-color-text-muted, #888);
    color: var(--shpd-color-surface, white);
  }

  /* noItem — řádek půjde jen s účtem, bez položky. Pozitivní rozhodnutí
     (řádek se pořídí), proto ✓ + outline; neutrální paleta odlišuje od
     matched (zelená) i canCreate (žlutá). */
  .shpd-exchange__status--noItemDecided {
    background: var(--shpd-color-state-confirmed-bg);
    color: var(--shpd-color-state-confirmed-text);
    outline: 2px solid var(--shpd-color-state-confirmed-text);
    outline-offset: 1px;
  }

  /* ── Enrichment badge (Row History Enrichment) ───────────────────────────
     Neinteraktivní, záměrně výrazně tišší než resolve badge: menší, bez
     pozadí, jen tónovaný glyph. Tooltip nese detail (zdrojový doklad,
     stupeň shody, doplněná pole vč. účtu). */
  .shpd-exchange__enrich {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    margin-left: 2px;
    font-size: 0.6875rem;
    line-height: 1;
    vertical-align: middle;
    cursor: default;
    opacity: 0.8;
  }

  .shpd-exchange__enrich--high {
    color: var(--shpd-color-state-done-text);
    background: var(--shpd-color-state-done-bg);
  }

  .shpd-exchange__enrich--medium {
    color: var(--shpd-color-state-concept-text);
    background: var(--shpd-color-state-concept-bg);
  }

  /* Dominance (historyDominantItem) — nejslabší signál, neutrální šeď. */
  .shpd-exchange__enrich--low {
    color: var(--shpd-color-state-confirmed-text);
    background: var(--shpd-color-state-confirmed-bg);
  }

  .shpd-exchange__enrich-summary {
    text-transform: none;
    letter-spacing: normal;
    font-size: 0.75rem;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__section-heading--split {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--shpd-space-sm);
  }

  /* ── Rows table ──────────────────────────────────────────────────────── */

  .shpd-exchange__section-heading {
    margin: 0 0 var(--shpd-space-xs);
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--shpd-color-text-muted);
  }

  .shpd-exchange__rows,
  .shpd-exchange__vat-recap {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
  }

  .shpd-exchange__rows th,
  .shpd-exchange__rows td,
  .shpd-exchange__vat-recap th,
  .shpd-exchange__vat-recap td {
    padding: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  .shpd-exchange__rows th,
  .shpd-exchange__vat-recap th {
    font-size: 0.6875rem;
    text-transform: uppercase;
    color: var(--shpd-color-text-muted);
    font-weight: 500;
    letter-spacing: 0.5px;
  }

  .shpd-exchange__rows .num,
  .shpd-exchange__vat-recap .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .shpd-exchange__row-name {
    font-weight: 500;
  }

  .shpd-exchange__row-code {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 0 4px;
    background: var(--shpd-color-surface-alt, #f5f5f5);
    border-radius: 3px;
    font-family: var(--shpd-font-mono, monospace);
    font-size: 0.6875rem;
    color: var(--shpd-color-text-muted);
  }

  /* ── Totals ──────────────────────────────────────────────────────────── */

  .shpd-exchange__totals-section {
    display: flex;
    justify-content: space-between;
    gap: var(--shpd-space-md);
    align-items: flex-end;
    flex-wrap: wrap;
  }

  .shpd-exchange__totals-summary {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    text-align: right;
    min-width: 220px;
    font-variant-numeric: tabular-nums;
  }

  .shpd-exchange__total {
    border-top: 1px solid var(--shpd-color-border);
    padding-top: var(--shpd-space-xs);
    font-size: 1rem;
  }

  /* ── Issues ──────────────────────────────────────────────────────────── */

  .shpd-exchange__issues ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  .shpd-exchange__issue {
    display: flex;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs);
    border-radius: 4px;
    font-size: 0.8125rem;
  }

  .shpd-exchange__issue--error {
    background: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-exchange__issue--warning {
    background: var(--shpd-color-state-concept-bg);
    color: var(--shpd-color-state-concept-text);
  }

  .shpd-exchange__issue--info {
    background: var(--shpd-color-primary-soft);
    color: var(--shpd-color-primary);
  }

  .shpd-exchange__issue-glyph {
    font-weight: 700;
  }

  /* ── ai_failed ───────────────────────────────────────────────────────── */

  .shpd-exchange--ai-failed {
    color: var(--shpd-color-state-cancelled-text);
  }

  .shpd-exchange__ai-failed-header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  .shpd-exchange__ai-failed-icon {
    font-size: 1.5rem;
  }

  .shpd-exchange__ai-failed-issues {
    margin: var(--shpd-space-xs) 0;
    padding-left: var(--shpd-space-md);
  }

  .shpd-exchange__ai-failed-raw pre {
    background: var(--shpd-color-surface-alt, #f5f5f5);
    padding: var(--shpd-space-sm);
    border-radius: 4px;
    overflow: auto;
    font-size: 0.75rem;
    max-height: 320px;
  }
</style>
