<script>
  // Canonical document visualization with optional interactive resolve.
  //
  // Od #139 (D48–D53) kreslí review tytéž sdílené části dokladu jako detail
  // (`DetailHeader`, `PartyCard`, `PropertyGroups`, `DocRows`, `DocTotals`
  // v `components/document/`) přes adaptér `reviewParts.js` (canonical →
  // hotové texty). Stav review — rozhodnutí, stavové značky, popovery,
  // výběr DPH, rychlé založení z registru — zůstává tady a do částí jde
  // snippety přes uzávěr; části jsou prezentační. Pasti: ve složeném
  // režimu řádků (pod 36 rem) jsou značky jednotky, kódu DPH a účtu v DOM
  // dvakrát (skryté široké buňky + řádek meta) — obě volají totéž
  // rozhodnutí (stejná `path`), popover se kotví ke kliknuté značce
  // (`event.currentTarget`); testy hledají jen viditelné prvky.
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
  import DetailHeader from '../ui/DetailHeader.svelte';
  import Notice from '../ui/Notice.svelte';
  import Button from '../ui/Button.svelte';
  import PropertyGroups from '../ui/PropertyGroups.svelte';
  import PartyCard from '../document/PartyCard.svelte';
  import DocRows from '../document/DocRows.svelte';
  import DocTotals from '../document/DocTotals.svelte';
  import {
    formatDate,
    formatMoney,
    issueNotice,
    reviewHeader,
    reviewParty,
    reviewRows,
    reviewTotals,
  } from './reviewParts.js';
  import ResolveDecisionPanel from './ResolveDecisionPanel.svelte';
  import VatCodeDecisionPanel from './VatCodeDecisionPanel.svelte';
  import RegistryImportWizard from '../registry/RegistryImportWizard.svelte';
  import { enrichedRowCount, matchKindKey, suggestedFieldKeys } from './enrichBadge.js';
  import { REFERENCE_TABLES, bankParentPath, hasControl } from './reviewGate.js';
  import {
    itemDecision,
    matchSourceKey,
    isWeakSource,
    isEnrichmentSource,
  } from './rowMatch.js';
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
  let resolve = $derived(canonical?._resolve ?? null);
  let summary = $derived(resolve?.summary ?? null);
  let issues = $derived(resolve?.issues ?? []);
  let enrichedCount = $derived(enrichedRowCount(resolve?.rows));
  // Rekapitulace DPH a součty, které skončí na dokladu (_resolve.computed,
  // tasks/exchange-preview-vat-recompute.md D5) — jen z /preview. Bez něj
  // (výpočet selhal, canonical bez _resolve) ukazujeme čísla z canonicalu
  // s poznámkou, že nejde o přepočet. Údaje z dokladu dodavatele se
  // zobrazí jen při rozdílu částky k úhradě (computed_total_mismatch).
  let computed = $derived(resolve?.computed ?? null);
  let recapRows = $derived(computed ? (computed.vatRecap ?? []) : (canonical?.vatRecap ?? []));
  let totals = $derived(computed ? computed.totals : (canonical?.totals ?? null));
  let totalMismatch = $derived(
    computed !== null && issues.some((issue) => issue?.code === 'computed_total_mismatch'),
  );
  let supplierTotal = $derived(canonical?.totals?.totalAmount ?? null);
  // Ceny řádků spočítané dokladem (_resolve.computed.rows, #97) podle indexu
  // canonicalu. U neplátce DPH jsou včetně daně dodavatele — canonical by
  // ukázal ceny bez daně vedle součtů s daní. Bez nich ceny z canonicalu.
  let computedRowPrices = $derived.by(() => {
    const map = new Map();
    for (const r of computed?.rows ?? []) {
      if (Number.isInteger(r?.index)) map.set(r.index, r);
    }
    return map;
  });

  // ── Volby DPH (tasks/exchange-preview-vat-choices.md D13–D16) ──────────
  // Nabídka kódů a efektivní hlavička přicházejí ze serveru. Bez nabídky
  // (vystavený doklad, přijatý doklad neplátce DPH, canonical bez _resolve)
  // se nic nevolí; hlavička ukazuje efektivní hodnotu se zdrojem
  // (u neplátce „Bez DPH“ / nonPayer), bez _resolve canonical jako dřív.
  // Doklad mimo české DPH (#89: zahraniční DPH, zvláštní místo plnění,
  // zvolené Bez DPH mimo tuzemsko) má režim „Bez DPH“ se zdrojem outOfScope
  // a důvodem; řádky nesou blok vatCode bez kódu (matchedBy outOfScope) —
  // odznak „bez DPH“ je klikací a volbou kódu se doklad vrátí k DPH.
  let vatCodeOptions = $derived(Array.isArray(resolve?.vatCodeOptions) ? resolve.vatCodeOptions : []);
  let vatCodeOptionCodes = $derived(new Set(vatCodeOptions.map((o) => o.code)));
  let effectiveVat = $derived(resolve?.vat ?? null);
  let vatChoiceEnabled = $derived(onUserActionsChange !== null && vatCodeOptions.length > 0);
  // Řádky s blokem vatCode — jen ty mají volbu; textové a kontační řádky ne.
  let vatCodeRowIndices = $derived.by(() => {
    const rows = resolve?.rows ?? [];
    const out = [];
    for (let i = 0; i < rows.length; i++) {
      if (rows[i]?.vatCode) out.push(i);
    }
    return out;
  });
  let bulkVatCodeVisible = $derived(vatChoiceEnabled && vatCodeRowIndices.length >= 2);
  // Sloupec Účet ukazuje účet, podle kterého se řádek zaúčtuje
  // (_resolve.rows[i].effectiveAccount, #111 D7b: účetní položka → její
  // účet, kontace / „jen účet“ / návrh → účet řádku, služba bez účtu → „—“).
  // Sloupec je vidět, jen když ho aspoň jeden řádek nese (D23); canonical
  // bez effectiveAccount (starší server, bez _resolve) → účet z canonicalu.
  let hasAccountColumn = $derived.by(() => {
    const rows = resolve?.rows;
    if (Array.isArray(rows) && rows.some((r) => r && typeof r === 'object' && 'effectiveAccount' in r)) {
      return rows.some((r) => r?.effectiveAccount != null);
    }
    return (canonical?.rows ?? []).some((r) => r?.account != null && String(r.account) !== '');
  });

  function rowEffectiveAccount(i) {
    const rowResolve = resolve?.rows?.[i] ?? null;
    if (rowResolve && typeof rowResolve === 'object' && 'effectiveAccount' in rowResolve) {
      return rowResolve.effectiveAccount ?? null;
    }
    const account = canonical?.rows?.[i]?.account;
    return account != null && String(account) !== ''
      ? { id: null, number: String(account), name: null, source: 'row' }
      : null;
  }

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
      // Položka: kód a název ze serveru místo „#id“ (#111 D3).
      if (resolveBlock.display && !resolveBlock.display.pinned) {
        return t('exchange.preview.status.matchedItem', { label: displayLabel(resolveBlock.display) });
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
    if (s === 'withoutVat') return '–';
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

  // ── Napárovaná položka v řádku (tasks/exchange-preview-matched-item.md) ──
  // Druhý řádek buňky Položka: efektivní položka (volba > napárování,
  // `_resolve.rows[i].item.display` ze serveru) se zdrojem napárování,
  // nebo rozhodnutí slovy („jen účet …“, „řádek se vynechá“). Po volbě drží
  // `pendingLabels` název z panelu, než nový náhled přinese `display`
  // se stejným id (D3).
  let pendingLabels = $state({});

  function rowIndexOfPath(path) {
    const m = /^rows\[(\d+)\]\.item$/.exec(path ?? '');
    return m !== null ? Number(m[1]) : null;
  }

  function isItemPath(path) {
    return rowIndexOfPath(path) !== null;
  }

  function displayLabel(display) {
    return [display?.code, display?.name]
      .filter((v) => v != null && String(v) !== '')
      .join(' ');
  }

  function matchSourceText(key, enrichment) {
    if (key === null || key === undefined) return '';
    if (key === 'contentTag') {
      return t('exchange.preview.match.source.contentTag', {
        tag: enrichment?.tagLabel ?? enrichment?.tag ?? '\u2014',
      });
    }
    return t(`exchange.preview.match.source.${key}`);
  }

  // Název zvolené položky: `display` ze serveru (po obnovení náhledu),
  // do té doby optimistický štítek z panelu, jinak #id.
  function itemChoiceLabel(i, decision, display) {
    if (display?.pinned && display.id === decision.id) return displayLabel(display);
    const pending = pendingLabels[`rows[${i}].item`] ?? null;
    if (pending && pending.action === `useExisting:${decision.id}` && pending.label) return pending.label;
    return `#${decision.id}`;
  }

  // null = řádek druhý řádek nemá (nenapárovaný bez rozhodnutí, bez _resolve).
  function rowMatchInfo(i) {
    const rowResolve = resolve?.rows?.[i] ?? null;
    if (!rowResolve) return null;
    const block = rowResolve.item ?? null;
    const enrichment = rowResolve.enrichment ?? null;
    const display = block?.display ?? null;
    const decision = block ? itemDecision(userActions, i) : null;
    if (decision?.kind === 'skip') {
      return { kind: 'skip', weak: false, title: t('exchange.preview.status.decided.skip') };
    }
    if (decision?.kind === 'noItem') {
      const acc = rowEffectiveAccount(i);
      const account = acc ? [acc.number, acc.name].filter(Boolean).join(' ') : '';
      return { kind: 'noItem', account, weak: false, title: t('exchange.preview.status.decided.noItem') };
    }
    if (decision?.kind === 'useExisting') {
      const sourceText = matchSourceText('user', null);
      return {
        kind: 'item',
        label: itemChoiceLabel(i, decision, display),
        sourceKey: 'user',
        sourceText,
        weak: false,
        title: sourceText,
      };
    }
    if (!display) return null;
    const sourceKey = matchSourceKey(block, enrichment, display);
    const sourceText = matchSourceText(sourceKey, enrichment);
    return {
      kind: 'item',
      label: displayLabel(display),
      sourceKey,
      sourceText,
      weak: isWeakSource(sourceKey),
      // Detail enrichmentu (zdrojový doklad, doplněná pole, odpočet DPH)
      // přešel z ikony ⟲ do tooltipu druhého řádku (D2, U1).
      title: isEnrichmentSource(sourceKey) && enrichment ? enrichTitle(enrichment) : sourceText,
    };
  }

  // „Napárováno automaticky: …“ v panelu (D5) — jen nad matched blokem.
  // Název i zdroj z `item.matchedDisplay` (automatické napárování nezávisle
  // na volbě, #111 D10); bez něj (smazaná položka) jen #id.
  function automaticMatchLabel(path) {
    const i = rowIndexOfPath(path);
    if (i === null) return null;
    const rowResolve = resolve?.rows?.[i] ?? null;
    const block = rowResolve?.item ?? null;
    if (block?.status !== 'matched') return null;
    const matched = block.matchedDisplay ?? null;
    const enrichment = rowResolve?.enrichment ?? null;
    return t('exchange.preview.match.automatic', {
      label: matched ? displayLabel(matched) : `#${block.matchedId}`,
      source: matchSourceText(matchSourceKey(block, enrichment, matched), enrichment),
    });
  }

  // „Vybráno: {název}“ v panelu (U4) — jen u volby položky.
  function currentChoiceLabel(path) {
    const i = rowIndexOfPath(path);
    if (i === null) return null;
    const decision = itemDecision(userActions, i);
    if (decision?.kind !== 'useExisting') return null;
    return itemChoiceLabel(i, decision, resolve?.rows?.[i]?.item?.display ?? null);
  }

  function rememberPendingLabels(paths, action, label) {
    const next = { ...pendingLabels };
    for (const p of paths) {
      if (typeof action === 'string' && action.startsWith('useExisting:') && label) {
        next[p] = { action, label };
      } else {
        delete next[p];
      }
    }
    pendingLabels = next;
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

  // Tabulka entity per druh reference — jeden zdroj s reviewGate.js (D98).
  function entityConfigForKind(kind) {
    const table = REFERENCE_TABLES[kind];
    return table ? { table } : null;
  }

  // For a bank-account decision we need the resolved supplier person id, so
  // the panel can filter the lookup search and pre-fill the create form.
  // Bank paths are `supplierBank` / `customerBank` (server `_resolve`), the
  // owning party is `supplier` / `customer` (reviewGate.bankParentPath).
  function parentMatchedIdForPath(path, kind) {
    if (kind !== 'bankAccount') return null;
    const parentPath = bankParentPath(path);
    if (parentPath === null) return null;
    const parentBlock = resolve?.[parentPath] ?? null;
    if (parentBlock?.status === 'matched' && parentBlock.matchedId != null) {
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

  // ── Volby DPH ───────────────────────────────────────────────────────────

  // Badge kódu DPH je klikací i u napárovaného kódu (D16) — uživatel smí
  // přebít i správně odvozený kód; vlastní panel, ne ResolveDecisionPanel.
  function openVatCodeDecision(event, path, block) {
    // I bez nabídky (prázdné vatCodeOptions) — blokující cesta musí mít
    // ovládání (D98); panel pak řekne, proč není z čeho vybrat.
    if (onUserActionsChange === null) return;
    event.preventDefault();
    event.stopPropagation();
    decisionOpen = { anchor: event.currentTarget, path, resolveBlock: block, kind: 'vatCode', parentMatchedId: null };
  }

  function openBulkVatCodeDecision(event) {
    if (!bulkVatCodeVisible) return;
    event.preventDefault();
    event.stopPropagation();
    decisionOpen = {
      anchor: event.currentTarget,
      path: null,
      resolveBlock: null,
      kind: 'vatCode',
      parentMatchedId: null,
      bulkPaths: vatCodeRowIndices.map((i) => `rows[${i}].vatCode`),
    };
  }

  // Volba místa plnění / režimu hlavičky; '' = automaticky (klíč smazat).
  // Změna místa smaže volby kódů řádků v témže novém objektu — kódy
  // jiného místa by byly neplatné (D15); jedno uložení, jeden refresh.
  function chooseVatHeader(kind, value) {
    const path = `vat.${kind}`;
    const next = { ...userActions };
    if (value === '' || value === null || value === undefined) {
      delete next[path];
    } else {
      next[path] = value;
    }
    if (kind === 'place') {
      for (const key of Object.keys(next)) {
        if (/^rows\[\d+\]\.vatCode$/.test(key)) delete next[key];
      }
    }
    onUserActionsChange?.(next);
  }

  function chosenVatCode(path) {
    const ua = onUserActionsChange !== null ? (userActions[path] ?? null) : null;
    return typeof ua === 'string' && ua.startsWith('useCode:') ? ua.slice('useCode:'.length) : null;
  }

  // Stav badge kódu DPH: volba platí (server vrátil matchedBy "user", nebo
  // je platná volba v mapě a nový náhled ještě nedorazil) → „zvoleno“;
  // jinak původní stav resolve (notFound po neplatné volbě zůstane chybou).
  function vatCodeStatusKey(path, block) {
    if (block?.matchedBy === 'user') return 'matchedDecided';
    const chosen = chosenVatCode(path);
    if (chosen !== null && vatCodeOptionCodes.has(chosen)) return 'matchedDecided';
    if (block?.matchedBy === 'outOfScope') return 'withoutVat';
    return statusKey(block?.status);
  }

  // Zdroj efektivního místa / režimu DPH v hlavičce; doklad mimo české DPH
  // má vlastní text podle důvodu (#89).
  function vatSourceLabel(eff) {
    return eff.source === 'outOfScope'
      ? t(`exchange.preview.vatChoice.source.outOfScope.${eff.reason ?? 'foreignVat'}`)
      : t(`exchange.preview.vatChoice.source.${eff.source}`);
  }

  // Platí volba uživatele? Bez DPH mimo tuzemsko se vrací se zdrojem
  // outOfScope a důvodem user (#89 D4).
  function vatChoiceHonored(eff) {
    return eff.source === 'user' || (eff.source === 'outOfScope' && eff.reason === 'user');
  }

  function vatCodeStatusLabel(path, block) {
    const code = block?.matchedBy === 'user' ? (block.createPayload?.code ?? null) : null;
    const chosen = code ?? chosenVatCode(path);
    if (chosen !== null && (code !== null || vatCodeOptionCodes.has(chosen))) {
      return t('exchange.preview.status.decided.useCode', { code: chosen });
    }
    if (block?.matchedBy === 'outOfScope') return t('exchange.preview.status.withoutVat');
    return statusLabel(block);
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

  function handleDecide(action, meta = null) {
    if (!decisionOpen) return;
    rememberPendingLabels(decisionOpen.bulkPaths ?? [decisionOpen.path], action, meta?.label ?? null);
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
    // Položka: volba má přednost i nad napárováním (#111 D4, D5); strany
    // a bankovní účet u matched zůstávají bez volby.
    if (resolveBlock?.status === 'matched' && !isItemPath(path)) return 'matched';
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
      if (isItemPath(path)) {
        const label = currentChoiceLabel(path) ?? `#${ua.slice('useExisting:'.length)}`;
        return t('exchange.preview.status.decided.useItem', { label });
      }
      return t('exchange.preview.status.decided.useExisting', { id: ua.slice('useExisting:'.length) });
    }
    if (ua === 'create') return t('exchange.preview.status.decided.create');
    if (ua === 'skip') return t('exchange.preview.status.decided.skip');
    if (ua === 'noItem') return t('exchange.preview.status.decided.noItem');
    return statusLabel(resolveBlock);
  }

  // ── Sdílené části dokladu (#139 D48–D53) ─────────────────────────────
  // Hlavička, strany, řádky, součty a hláška problémů jdou přes adaptér
  // do týchž komponent jako detail dokladu.
  let header = $derived(reviewHeader(canonical, summary, t));
  let supplierParty = $derived(reviewParty(canonical?.supplier ?? null, t));
  let customerParty = $derived(reviewParty(canonical?.customer ?? null, t));
  let rows = $derived(reviewRows(canonical, resolve, computedRowPrices, t));
  let totalsData = $derived(reviewTotals(recapRows, totals, canonical?.currency ?? null));
  let issueNoticeData = $derived(issueNotice(issues));
  let rowsAsideVisible = $derived(enrichedCount > 0 || bulkItemVisible || bulkVatCodeVisible);
  let aiFailedItems = $derived(
    (wrapper?._validationIssues ?? []).map((issue) => ({ text: issue?.message ?? '', meta: issue?.path ?? '' })),
  );

  // Skupiny údajů (D51): Data / Platba / DPH — prázdné pole „—“ (kontroluje
  // se vytěžení), režim a místo plnění jako položky se snippetem výběru;
  // snippet je hodnota až v šabloně, proto funkce volaná z `{@const}`.
  function metaGroups(vatChoice) {
    return [
      {
        title: t('document.section.dates'),
        items: [
          { label: t('document.meta.issueDate'), value: formatDate(canonical?.dates?.issueDate) },
          { label: t('document.meta.dueDate'), value: formatDate(canonical?.dates?.dueDate) },
          { label: t('document.meta.accountingDate'), value: formatDate(canonical?.dates?.accountingDate) },
          { label: t('document.meta.taxPointDate'), value: formatDate(canonical?.dates?.taxPointDate) },
          { label: t('exchange.preview.field.vatObligationDate'), value: formatDate(canonical?.dates?.vatObligationDate) },
        ],
      },
      {
        title: t('document.section.payment'),
        items: [
          { label: t('document.meta.currency'), value: canonical?.currency ?? null },
          { label: t('document.meta.paymentMethod'), value: paymentMethodLabel },
          { label: t('document.meta.paymentReference'), value: canonical?.payment?.paymentReference ?? null },
        ],
      },
      {
        title: t('exchange.preview.section.vat'),
        items: [
          { label: t('exchange.preview.field.vatMode'), kind: 'mode', snippet: vatChoice },
          { label: t('exchange.preview.field.vatPlace'), kind: 'place', snippet: vatChoice },
        ],
      },
    ];
  }

  // Patička karty strany — rychlé založení z registru (Issue #28): jen
  // v interaktivním režimu, s nálezem, bez rozhodnutí a u nenapárované strany.
  function registryOffered(path) {
    return onUserActionsChange !== null
      && Boolean(registryHits[path])
      && (userActions[path] ?? null) === null
      && resolve?.[path]?.status !== 'matched';
  }
</script>

{#snippet statusBadge(resolveBlock, path = null, kind = null)}
  {#if resolveBlock && statusKey(resolveBlock.status)}
    {@const modifier = effectiveStatusKey(path, resolveBlock)}
    {@const label = effectiveStatusLabel(path, resolveBlock)}
    <!-- Klikatelnost = pravidlo reviewGate.hasControl (D98): napárovaná
         položka ano (změna napárování, #111 D4), strany a bankovní účet
         u matched ne; read-only náhled nic. -->
    {@const interactive
      = path !== null
      && hasControl({ kind, block: resolveBlock }, { interactive: onUserActionsChange !== null })}
    {#if interactive}
      <!-- `review-ref:{path}` + `data-status` = stav reference pro proklikání
           sady (#103 F7a): test rozhoduje jen odznaky canCreate / ambiguous /
           notFound a čeká na jejich překlopení. -->
      <button
        type="button"
        class="shpd-exchange__status shpd-exchange__status--{modifier} shpd-exchange__status--interactive"
        title={label}
        data-testid="review-ref:{path}"
        data-status={modifier}
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

<!-- Druhý řádek buňky Položka (#111 D1, D2): napárovaná / zvolená položka
     a zdroj, nebo rozhodnutí slovy. V interaktivním režimu tlačítko vzhledu
     textu — tatáž akce jako ✓ (D4); bez callbacku prostý text. -->
{#snippet rowMatchLine(i, match)}
  {@const text = match.kind === 'skip'
    ? t('exchange.preview.match.skip')
    : match.kind === 'noItem'
      ? t('exchange.preview.match.noItem', { account: match.account })
      : `${match.label} \u00b7 ${match.sourceText}`}
  {#if onUserActionsChange !== null && resolve?.rows?.[i]?.item}
    <button
      type="button"
      class="shpd-exchange__row-match shpd-exchange__row-match--interactive"
      class:shpd-exchange__row-match--weak={match.weak}
      title={match.title}
      onclick={(e) => openDecision(e, `rows[${i}].item`, resolve?.rows?.[i]?.item, 'item')}
    ><span class="shpd-exchange__row-match-arrow" aria-hidden="true">{'\u21b3'}</span> {text}</button>
  {:else}
    <span
      class="shpd-exchange__row-match"
      class:shpd-exchange__row-match--weak={match.weak}
      title={match.title}
    ><span class="shpd-exchange__row-match-arrow" aria-hidden="true">{'\u21b3'}</span> {text}</span>
  {/if}
{/snippet}

<!-- Místo plnění / režim DPH jako položka skupiny DPH (D51): popisek
     kreslí PropertyList, snippet hodnotu — efektivní hodnota z _resolve.vat
     se zdrojem (D14); s nabídkou kódů i select „Automaticky (…)“ / pevná
     volba (D16). V závorce je `auto` = co by Shipard určil bez volby, ne
     aktuální efektivní hodnota — jinak by po volbě ukazovala to, co
     uživatel vybral. Bez _resolve.vat fallback na canonical jako dřív. -->
{#snippet vatChoiceField(item)}
  {@const kind = item.kind}
  {@const keys = kind === 'mode' ? VAT_MODE_KEYS : VAT_PLACE_KEYS}
  {@const prefix = kind === 'mode' ? 'vatMode' : 'vatPlace'}
  {@const display = kind === 'mode' ? vatModeDisplay : vatPlaceDisplay}
  {@const eff = effectiveVat?.[kind] ?? null}
  {@const path = `vat.${kind}`}
  {@const chosen = userActions[path] ?? null}
  {@const effLabel = eff ? enumLabel(prefix, keys, eff.value) : null}
  {@const autoLabel = eff ? enumLabel(prefix, keys, eff.auto ?? eff.value) : null}
  {#if vatChoiceEnabled && eff}
    <span class="shpd-exchange__vat-choice">
      <select
        class="shpd-exchange__vat-select"
        value={chosen ?? ''}
        onchange={(e) => chooseVatHeader(kind, e.currentTarget.value)}
      >
        <option value="">{t('exchange.preview.vatChoice.auto', { value: autoLabel })}</option>
        {#each keys as key (key)}
          <option value={`useValue:${key}`}>{t(`exchange.preview.${prefix}.${key}`)}</option>
        {/each}
      </select>
      <span class="shpd-exchange__field-hint">
        {chosen !== null && !vatChoiceHonored(eff)
          ? t('exchange.preview.vatChoice.overridden', { value: effLabel })
          : vatSourceLabel(eff)}
      </span>
    </span>
    {:else}
    <span>
      {eff ? effLabel : display.text}
      {#if eff}
        <span class="shpd-exchange__field-hint">{vatSourceLabel(eff)}</span>
      {:else if display.isDefault}
        <span class="shpd-exchange__field-hint">{t('exchange.preview.defaultHint')}</span>
      {/if}
    </span>
    {/if}
{/snippet}


<!-- Badge kódu DPH řádku — na rozdíl od statusBadge klikací i u matched
     (D16) a bez entity: popover s VatCodeDecisionPanel. Klikací i bez
     nabídky (D98 — blokující cesta má ovládání; panel řekne, že není z čeho). -->
{#snippet vatCodeBadge(i, block)}
  {#if block && statusKey(block.status)}
    {@const path = `rows[${i}].vatCode`}
    {@const modifier = vatCodeStatusKey(path, block)}
    {@const label = vatCodeStatusLabel(path, block)}
    {#if modifier === 'withoutVat' && onUserActionsChange === null}
      <!-- Read-only náhled: text „bez DPH“ ve sloupci stačí, odznak bez
           ovládání by nic neřekl. -->
    {:else if hasControl({ kind: 'vatCode', block }, { interactive: onUserActionsChange !== null })}
      <button
        type="button"
        class="shpd-exchange__status shpd-exchange__status--{modifier} shpd-exchange__status--interactive"
        title={label}
        data-testid="review-ref:{path}"
        data-status={modifier}
        onclick={(e) => openVatCodeDecision(e, path, block)}
      >
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </button>
    {:else}
      <span class="shpd-exchange__status shpd-exchange__status--{modifier}" title={label}>
        <span class="shpd-exchange__status-glyph">{statusGlyph(modifier)}</span>
      </span>
    {/if}
  {/if}
{/snippet}


<!-- Řádek nadpisu Řádky dokladu (D45): souhrn obohacení a hromadné akce
     jako textová tlačítka — položka do všech ne-matched řádků (Issue #29),
     kód DPH do všech řádků s volbou. -->
{#snippet rowsAside()}
  <span class="shpd-exchange__rows-aside">
    {#if enrichedCount > 0}
      <span class="shpd-exchange__enrich-summary">
        {t('exchange.preview.enrich.summary', { count: enrichedCount })}
      </span>
    {/if}
    {#if bulkItemVisible}
      <Button
        size="sm"
        variant="secondary"
        label={t('exchange.preview.bulk.itemButton', { count: bulkItemIndices.length })}
        testid="review-ref-bulk:item"
        onclick={openBulkItemDecision}
      />
    {/if}
    {#if bulkVatCodeVisible}
      <Button
        size="sm"
        variant="secondary"
        label={t('exchange.preview.vatCode.bulkButton', { count: vatCodeRowIndices.length })}
        onclick={openBulkVatCodeDecision}
      />
    {/if}
  </span>
{/snippet}

<!-- Popis řádku: text složený serverem (_resolve.rows[i].rowText, #84 —
     tentýž, co applier zapíše), kód dodavatele, značka položky a ⟲ jen
     u řádku s enrichmentem bez druhého řádku (U1). -->
{#snippet rowDescription(row, i)}
  {@const match = rowMatchInfo(i)}
  {row.description}
  {#if row.supplierCode}
    <span class="shpd-exchange__row-code">{row.supplierCode}</span>
  {/if}
  {@render statusBadge(resolve?.rows?.[i]?.item, `rows[${i}].item`, 'item')}
  {#if match === null}
    {@render enrichBadge(resolve?.rows?.[i]?.enrichment)}
  {/if}
{/snippet}

{#snippet rowBelow(row, i)}
  {@const match = rowMatchInfo(i)}
  {#if match !== null}
    {@render rowMatchLine(i, match)}
  {/if}
{/snippet}

<!-- Účet, podle kterého se řádek zaúčtuje (_resolve.rows[i].effectiveAccount,
     #111 D7b); značka jen u účtu z řádku. -->
{#snippet rowAccount(row, i)}
  {@const acc = rowEffectiveAccount(i)}
  {#if acc}
    <span class="shpd-exchange__row-code" title={acc.name ?? ''}>{acc.number}</span>
    {#if acc.source === 'row'}
      {@render statusBadge(resolve?.rows?.[i]?.account)}
    {/if}
  {:else}
    —
  {/if}
{/snippet}

{#snippet rowUnit(row, i)}
  {row.unit ?? '—'}
  {@render statusBadge(resolve?.rows?.[i]?.unit)}
{/snippet}

{#snippet rowVat(row, i)}
  {row.vat ?? '—'}
  {#if row.vatCode}
    <span class="shpd-exchange__row-code">{row.vatCode}</span>
  {/if}
  {@render vatCodeBadge(i, resolve?.rows?.[i]?.vatCode)}
{/snippet}

<!-- Řádek složeného režimu (D45): „množství jednotka × cena · sazba kód“
     a „· účet“ — stejné oddělovače jako rowMetaLine (documentParts.js), ale
     skládá se tu, aby značky jednotky, kódu DPH a účtu stály u svých částí.
     Tytéž značky jsou i ve skrytých širokých buňkách (viz hlavička souboru). -->
{#snippet rowMeta(row, i)}
  {@const amount = [row.quantity, row.unit].filter((part) => part).join(' ')}
  {@const vatBlock = resolve?.rows?.[i]?.vatCode ?? null}
  {@const acc = hasAccountColumn ? rowEffectiveAccount(i) : null}
  {#if amount}
    {amount}{@render statusBadge(resolve?.rows?.[i]?.unit)}
  {/if}
  {#if row.unitPrice}
    {amount ? ' \u00d7 ' : ''}{row.unitPrice}
  {/if}
  {#if row.vat || vatBlock}
    {amount || row.unitPrice ? ' \u00b7 ' : ''}{row.vat ?? '—'}
    {#if row.vatCode}
      <span class="shpd-exchange__row-code">{row.vatCode}</span>
    {/if}
    {@render vatCodeBadge(i, vatBlock)}
  {/if}
  {#if acc}
    {' \u00b7 '}<span class="shpd-exchange__row-code" title={acc.name ?? ''}>{acc.number}</span>
    {#if acc.source === 'row'}
      {@render statusBadge(resolve?.rows?.[i]?.account)}
    {/if}
  {/if}
{/snippet}

<!-- Zdroj čísel rekapitulace a součtů (tasks/exchange-preview-vat-recompute.md
     D5): převzatá z dokladu / přepočítaná (s důvodem) / bez přepočtu. -->
{#snippet totalsBefore()}
  <div class="shpd-exchange__recap-source">
    {#if computed}
      {t(computed.recapSource === 'declared'
        ? 'exchange.preview.recap.declared'
        : 'exchange.preview.recap.computed')}
      {#if computed.recapFallback}
        <span class="shpd-exchange__recap-reason">— {computed.recapFallback}</span>
      {/if}
    {:else}
      {t('exchange.preview.recap.notComputed')}
    {/if}
  </div>
{/snippet}

<!-- Sazba v rekapitulaci: kód DPH (meta) a „oddaňovací řádek“ u dvojice
     přenesené povinnosti (meta, ne zvláštní třída řádku). -->
{#snippet recapRate(r)}
  {r.rate ?? '—'}
  {#if r.vatCode}
    <span class="shpd-exchange__row-code">{r.vatCode}</span>
  {/if}
  {#if r.isReversePair}
    <span class="shpd-exchange__recap-pair">{t('exchange.preview.recap.reversePair')}</span>
  {/if}
{/snippet}

<!-- Nesoulad s částkou na dokladu dodavatele (computed_total_mismatch) —
     hláška pod souhrnem (D52). -->
{#snippet totalsAfter()}
  <Notice tone="warning">
    {t('exchange.preview.totals.supplierMismatch', { amount: formatMoney(supplierTotal, canonical?.currency ?? null) })}
  </Notice>
{/snippet}

{#if aiFailed && wrapper}
  <!-- Selhání AI (D53): hláška danger s problémy formátu, surový výstup
       rozbalovací pod ní jako blok `code` detailu. -->
  <div class="shpd-exchange">
    <Notice tone="danger" title={t('exchange.preview.aiFailed.title')} items={aiFailedItems}>
      <p class="shpd-exchange__notice-text">{t('exchange.preview.aiFailed.message')}</p>
    </Notice>
    <details class="shpd-exchange__raw">
      <summary>{t('exchange.preview.aiFailed.rawOutput')}</summary>
      <pre class="shpd-exchange__raw-pre">{JSON.stringify(wrapper._rawOutput, null, 2)}</pre>
    </details>
  </div>
{:else if canonical}
  {@const groups = metaGroups(vatChoiceField)}
  <div class="shpd-exchange">
    <!-- Hlavička (D49): titulek protistrana (bez ní číslo), podtitul
         „číslo · text“, štítky typ výsledku analýzy a souhrnný stav kontroly
         — review je místo kontroly výsledku analýzy, stav kontroly není
         stav záznamu (D20 se tu neuplatní). Bez ikony. -->
    <DetailHeader title={header.title} subtitle={header.subtitle} badges={header.badges} />

    <!-- Problémy z vytěžení (D53): hned pod hlavičkou, tón podle nejvyšší
         závažnosti, cesta v roli meta. -->
    {#if issueNoticeData.items.length > 0}
      <Notice tone={issueNoticeData.tone} title={t('exchange.preview.section.issues')} items={issueNoticeData.items} />
    {/if}

    <!-- Strany (D50): sdílené karty v mřížce dvou sloupců; stav strany
         v nadpisu karty, značka účtu za číslem (účet i bez čísla, když má
         vlastní rozhodnutí — `supplierBank` / `customerBank`, D98), rychlé
         založení z registru dole v kartě. -->
    <section class="shpd-exchange__parties">
      {#each [
        ['supplier', supplierParty, t('document.section.supplier')],
        ['customer', customerParty, t('document.section.customer')],
      ] as [path, party, title] (path)}
        {@const bankPath = `${path}Bank`}
        {@const bankResolve = resolve?.[bankPath] ?? null}
        {#snippet partyStatus()}
          {@render statusBadge(resolve?.[path], path, 'party')}
        {/snippet}
        {#snippet partyBankStatus()}
          {@render statusBadge(bankResolve, bankPath, 'bankAccount')}
        {/snippet}
        {#snippet partyFooter()}
          <div class="shpd-exchange__party-footer">
            <Button
              size="sm"
              variant="secondary"
              disabled={quickAddBusy[path] ?? false}
              label={quickAddBusy[path]
                ? t('exchange.preview.registry.creating')
                : `+ ${t('exchange.preview.registry.quickAdd', { name: registryHits[path].fullName })}`}
              onclick={() => handleRegistryQuickAdd(path)}
            />
            {#if quickAddError[path]}
              <Notice tone="danger">{quickAddError[path]}</Notice>
            {/if}
          </div>
        {/snippet}
        <PartyCard
          {title}
          {party}
          status={resolve?.[path] ? partyStatus : undefined}
          bankStatus={bankResolve ? partyBankStatus : undefined}
          footer={registryOffered(path) ? partyFooter : undefined}
        />
      {/each}
    </section>

    <!-- Údaje (D51): PropertyGroups jako detail, prázdné pole „—“ —
         kontroluje se vytěžení; výběr DPH je snippet položky. -->
    <PropertyGroups {groups} headingLevel={3} emptyValue={'\u2014'} />

    {#if rows.length > 0}
      <DocRows
        title={t('document.section.rows')}
        {rows}
        aside={rowsAsideVisible ? rowsAside : undefined}
        description={rowDescription}
        below={rowBelow}
        account={hasAccountColumn ? rowAccount : undefined}
        unit={rowUnit}
        vat={rowVat}
        meta={rowMeta}
      />
    {/if}

    {#if totalsData.recap.length > 0 || totalsData.summary}
      <!-- Hodnota Celkem nese data-testid review-total (proklikání sady #103). -->
      <DocTotals
        totals={totalsData}
        totalTestId="review-total"
        before={totalsBefore}
        rate={recapRate}
        after={totalMismatch && supplierTotal !== null ? totalsAfter : undefined}
      />
    {/if}
  </div>
{/if}

<!-- Props panelu čtou `decisionOpen?.…` (#103 D101): `handleDecide` ho
     vynuluje synchronně uvnitř callbacku potomka (FormEditor → FormDialog →
     panel → onDecide) a gettery props se ještě vyhodnotí, než Svelte blok
     strhne — bez `?.` padá TypeError „reading 'table'“. -->
{#if decisionOpen !== null}
  <Popover
    open={true}
    anchor={decisionOpen?.anchor ?? null}
    placement="bottom"
    width="25rem"
    testid="review-ref-dialog"
    onClose={closeDecision}
  >
    {#if decisionOpen?.kind === 'vatCode'}
      <VatCodeDecisionPanel
        options={vatCodeOptions}
        withoutVat={effectiveVat?.mode?.source === 'outOfScope'}
        currentUserAction={decisionOpen?.path != null ? userActions[decisionOpen.path] ?? null : null}
        bulkCount={decisionOpen?.bulkPaths?.length ?? 0}
        bulkDecidedCount={decisionOpen?.bulkPaths
          ? decisionOpen.bulkPaths.filter((p) => (userActions[p] ?? null) !== null).length
          : 0}
        onDecide={handleDecide}
      />
    {:else}
      <ResolveDecisionPanel
        path={decisionOpen?.bulkPaths ? null : decisionOpen?.path ?? null}
        resolveBlock={decisionOpen?.resolveBlock ?? null}
        referenceKind={decisionOpen?.kind ?? 'party'}
        entityTable={decisionOpen?.table ?? null}
        createPayload={decisionOpen?.resolveBlock?.createPayload ?? null}
        parentMatchedId={decisionOpen?.parentMatchedId ?? null}
        currentUserAction={decisionOpen?.path != null ? userActions[decisionOpen.path] ?? null : null}
        allowNoItem={decisionOpen?.allowNoItem ?? false}
        automaticLabel={decisionOpen?.kind === 'item' && decisionOpen?.path != null
          ? automaticMatchLabel(decisionOpen.path)
          : null}
        currentLabel={decisionOpen?.kind === 'item' && decisionOpen?.path != null
          ? currentChoiceLabel(decisionOpen.path)
          : null}
        bulkCount={decisionOpen?.bulkPaths?.length ?? 0}
        bulkDecidedCount={decisionOpen?.bulkPaths
          ? decisionOpen.bulkPaths.filter((p) => (userActions[p] ?? null) !== null).length
          : 0}
        onDecide={handleDecide}
        registryHit={decisionOpen?.kind === 'party' ? registryHits[decisionOpen.path] ?? null : null}
        registryBusy={decisionOpen?.kind === 'party' ? quickAddBusy[decisionOpen.path] ?? false : false}
        registryError={decisionOpen?.kind === 'party' ? quickAddError[decisionOpen.path] ?? null : null}
        onRegistryQuickAdd={decisionOpen?.kind === 'party'
          ? () => {
              const path = decisionOpen?.path ?? null;
              if (path === null) return;
              void handleRegistryQuickAdd(path).then((ok) => {
                if (ok) closeDecision();
              });
            }
          : null}
        onOpenRegistrySearch={decisionOpen?.kind === 'party'
          ? () => {
              if (decisionOpen?.path != null) openRegistrySearch(decisionOpen.path);
            }
          : null}
      />
    {/if}
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
    font-size: var(--shpd-font-size-body);
    /* Kontejner pro @container níže (#133 D43): obsah reaguje na svou šířku,
       ne na okno — bývá v panelu, draweru i modalu. Blok na celou šířku
       rodiče, mřížka až uvnitř (dotaz platí pro potomky kontejneru). */
    container-type: inline-size;
  }

  /* Hlavičku, karty stran, údaje, tabulky, součty a hlášky kreslí sdílené
     části (#139 D48): DetailHeader, PartyCard, PropertyGroups, DocRows,
     DocTotals, Notice — vlastní CSS tu mají jen mřížka stran, stavové
     značky, napárování, kódy, výběr DPH a drobné obaly snippetů. */

  /* ── Parties ─────────────────────────────────────────────────────────── */

  .shpd-exchange__parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
  }

  /* Úzký kontejner: strany pod sebe (týž práh jako detail dokladu). */
  @container (max-width: 30rem) {
    .shpd-exchange__parties {
      grid-template-columns: 1fr;
    }
  }

  /* Patička karty strany: rychlé založení z registru + chyba pod ním. */
  .shpd-exchange__party-footer {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }

  /* Řádek nadpisu Řádky dokladu: souhrn obohacení a hromadné akce. */
  .shpd-exchange__rows-aside {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  /* ── Údaje: výběr DPH ──────────────────────────────────────────────── */

  .shpd-exchange__field-hint {
    margin-left: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* Volba místa plnění / režimu DPH v hlavičce (D16). */
  .shpd-exchange__vat-choice {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-xs);
  }

  .shpd-exchange__vat-select {
    font: inherit;
    font-size: var(--shpd-font-size-body);
    padding: 0.125rem var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg);
    color: inherit;
    max-width: 100%;
  }

  /* ── Status badges ───────────────────────────────────────────────────── */

  /* Stavová značka (kolečko se znakem) zůstává ovládacím prvkem; znak
     v roli meta normální tloušťkou (D54), kolečko 18 px — D46 (#138 task 4). */

  .shpd-exchange__status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.125rem;
    height: 1.125rem;
    border-radius: 50%;
    margin-left: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-normal);
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
    background: var(--shpd-color-text-secondary);
    color: var(--shpd-color-bg);
  }

  /* Řádek dokladu mimo české DPH (#89) — bez kódu záměrně, ne chyba:
     neutrální paleta, žádná zelená „napárováno“ ani červená „nenalezeno“. */
  .shpd-exchange__status--withoutVat {
    background: var(--shpd-color-bg-secondary);
    color: var(--shpd-color-text-secondary);
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
    width: 0.875rem;
    height: 0.875rem;
    border-radius: 50%;
    margin-left: 0.125rem;
    font-size: var(--shpd-font-size-meta);
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
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* ── Řádky: napárování a kódy ──────────────────────────────────────── */

  /* Druhý řádek buňky Položka (#111 D1, D2): napárovaná / zvolená položka
     a zdroj — menší a šedě; slabý zdroj (podobný text, častá položka,
     kategorie, podle názvu) jantarově přes token varování (světlý i tmavý
     vzhled). Interaktivní varianta je <button> vzhledu textu. */
  .shpd-exchange__row-match {
    display: block;
    margin-top: 0.125rem;
    font-size: var(--shpd-font-size-meta);
    line-height: 1.3;
    color: var(--shpd-color-text-secondary);
    text-align: left;
  }

  .shpd-exchange__row-match--weak {
    color: var(--shpd-color-warning);
  }

  .shpd-exchange__row-match-arrow {
    opacity: 0.7;
  }

  .shpd-exchange__row-match--interactive {
    background: transparent;
    border: 0;
    padding: 0;
    font-family: inherit;
    cursor: pointer;
  }

  .shpd-exchange__row-match--interactive:hover {
    text-decoration: underline;
  }

  .shpd-exchange__row-match--interactive:focus-visible {
    outline: 2px solid var(--shpd-color-primary);
    outline-offset: 1px;
    border-radius: 0.125rem;
  }

  .shpd-exchange__row-code {
    display: inline-block;
    margin-left: var(--shpd-space-xs);
    padding: 0 var(--shpd-space-xs);
    background: var(--shpd-color-bg-secondary);
    border-radius: 0.1875rem;
    font-family: var(--shpd-font-family-mono);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* ── Součty: zdroj čísel, dvojice přenesené povinnosti ─────────────── */

  /* Zdroj čísel nad rekapitulací i součty — celá šířka sekce. */
  .shpd-exchange__recap-source {
    flex-basis: 100%;
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-exchange__recap-reason {
    font-style: italic;
  }

  .shpd-exchange__recap-pair {
    margin-left: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* ── Selhání AI ────────────────────────────────────────────────────── */

  .shpd-exchange__notice-text {
    margin: 0;
  }

  .shpd-exchange__raw summary {
    cursor: pointer;
  }

  /* Surový výstup jako blok `code` detailu (§12): mono, meta, pozadí
     bg-secondary, zaoblení md, dlouhý řádek roluje v bloku. */
  .shpd-exchange__raw-pre {
    margin: var(--shpd-space-xs) 0 0;
    padding: var(--shpd-space-sm);
    background: var(--shpd-color-bg-secondary);
    border-radius: var(--shpd-radius-md);
    font-family: var(--shpd-font-family-mono);
    font-size: var(--shpd-font-size-meta);
    overflow: auto;
    max-height: 20rem;
  }
</style>
