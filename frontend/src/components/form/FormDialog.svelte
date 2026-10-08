<script lang="ts">
  import Modal from '../ui/Modal.svelte';
  import Icon from '../ui/Icon.svelte';
  import Button from '../ui/Button.svelte';
  import ConfirmDialog from '../ui/ConfirmDialog.svelte';
  import FormEditor from './FormEditor.svelte';
  import FormStateBadge from './FormStateBadge.svelte';
  import { resolveIcon, iconChevronLeft, iconChevronRight } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  type HeaderInfoItem = { label: string; value: string };
  type HeaderInfo = {
    title: string;
    info: HeaderInfoItem[];
    icon: string | null;
    summary: HeaderInfoItem[];
  };

  type Navigation = {
    /** 0-based index aktuálního záznamu v seznamu; -1 = nový záznam → šipky se nerenderují. */
    index: number;
    count: number;
    onPrev: () => void;
    onNext: () => void;
  };

  interface Props {
    table: string;
    recordId?: number | null;
    open: boolean;
    onClose: () => void;
    /** `wasNew` = záznam byl při uložení nový (dialog otevřený bez recordId). */
    onSaved?: (record: Record<string, unknown>, info?: { hasDocStates: boolean; wasNew: boolean }) => void;
    defaultData?: Record<string, unknown>;
    /** Nenápadná informační notice nad formulářem (neutrální, ne error). */
    notice?: string | null;
    /** Jen k prohlížení: pole vypnutá, bez Uložit a přechodů, zavření bez
     *  dotazu na neuložené změny. Používá FormSubTable pro řádky read-only
     *  rodiče (issue #53). */
    readOnly?: boolean;
    /** Listování sousedními záznamy seznamu (sub-tabulka): šipky ‹ › a
     *  pozice v hlavičce, Alt+←/→. Bez `navigation` vypadá dialog jako
     *  dnes (top-level). Navigaci vlastní volající — má seřazený seznam. */
    navigation?: Navigation | null;
    /** Nový sub-záznam: vedle Přidat i „Přidat a pokračovat" — po uložení se
     *  formulář resetuje na další nový záznam a zavolá se tento callback
     *  (nikoli onSaved, které by dialog zavřelo). */
    onSaveAndContinue?: (record: Record<string, unknown>) => void;
  }

  let {
    table,
    recordId = null,
    open,
    onClose,
    onSaved,
    defaultData = {},
    notice = null,
    readOnly = false,
    navigation = null,
    onSaveAndContinue,
  }: Props = $props();

  // Aktuální titulek a doc_states z formuláře — aktualizuje se přes onFormLoaded
  // callback z FormEditor po každém (re)loadu formuláře.
  let currentTitle = $state('');
  let currentDocStates = $state<Record<string, unknown> | null>(null);
  // Strukturovaná hlavička (title + info + icon + summary). Nastavuje se jen
  // při load/save, ne při recalculate — header odráží uložená data, ne živý formData.
  let savedHeaderInfo = $state<HeaderInfo | null>(null);

  // Dirty stav formuláře — propagován z FormEditor přes onDirtyChange callback.
  // Při pokusu o zavření (Esc, klik na overlay, křížek) se zobrazí potvrzovací dialog.
  let isDirty = $state(false);

  $effect(() => {
    if (open) {
      currentTitle = '';
      currentDocStates = null;
      savedHeaderInfo = null;
      isDirty = false;
    }
  });

  // Akce čekající na potvrzení „Neuložené změny" (zavření nebo Předchozí/
  // Další). Non-null = ConfirmDialog otevřený; Zahodit ji spustí, Zůstat zruší.
  let pendingAction = $state<(() => void) | null>(null);

  // Sdílená ochrana neuložených změn pro zavření i navigaci: bez dirty
  // rovnou `then()`, s dirty ConfirmDialog (Zahodit → then, Zůstat → nic).
  // Nahrazuje dřívější window.confirm.
  function guardDirty(then: () => void) {
    if (!isDirty) {
      then();
      return;
    }
    pendingAction = then;
  }

  function discardPending() {
    const run = pendingAction;
    pendingAction = null;
    isDirty = false;
    run?.();
  }

  function stayPending() {
    pendingAction = null;
  }

  function handleClose(opts?: { force?: boolean }) {
    // force=true — přeskočí dirty kontrolu. Používá se po úspěšném save+closeForm,
    // kde FormEditor ví, že data jsou uložená, a nesmí se zobrazit confirm.
    if (opts?.force) {
      isDirty = false;
      onClose();
      return;
    }
    guardDirty(() => {
      isDirty = false;
      onClose();
    });
  }

  function handleSaved(record: Record<string, unknown>) {
    // Druhý argument informuje konzumenta (např. FormSubTable), zda má
    // formulář doc states a zda byl záznam nový. Formuláře bez doc states
    // mají jedinou akci Uložit (žádné přechody s close_form), takže subtable
    // je po Uložit zavře; nový záznam se zavírá i s doc states (Přidat).
    // Existující záznam s doc states zůstane otevřený — zavření řeší
    // close_form / onClose, stejně jako u hlavních modalů. currentDocStates
    // je v okamžiku save spolehlivě naplněný (onFormLoaded proběhl při loadu).
    // `recordId` (prop) zůstává null po celou dobu dialogu nového záznamu.
    onSaved?.(record, { hasDocStates: currentDocStates != null, wasNew: recordId == null });
    // Nezavíráme formulář zde — o zavření rozhoduje FormEditor sám
    // na základě closeForm flagu nebo akce uživatele
  }

  // ── Navigace Předchozí / Další ──────────────────────────────────────────────

  const showNav = $derived(!!navigation && navigation.index >= 0 && navigation.count > 0);
  const canPrev = $derived(showNav && (navigation?.index ?? 0) > 0);
  const canNext = $derived(showNav && (navigation?.index ?? 0) < (navigation?.count ?? 0) - 1);

  function goPrev() {
    if (!canPrev) return;
    guardDirty(() => navigation?.onPrev());
  }

  function goNext() {
    if (!canNext) return;
    guardDirty(() => navigation?.onNext());
  }

  // Fokus v textovém poli: na macOS Alt+šipka skáče po slovech a dotaz na
  // neuložené změny uprostřed psaní by rušil — tam klávesy nechytáme.
  function isTextEditing(el: Element | null): boolean {
    if (!el) return false;
    if (el instanceof HTMLTextAreaElement) return true;
    if ((el as HTMLElement).isContentEditable) return true;
    if (el instanceof HTMLInputElement) {
      return !['checkbox', 'radio', 'button', 'submit', 'reset', 'range', 'color', 'file'].includes(el.type);
    }
    return false;
  }

  // Alt+← / Alt+→ = Předchozí / Další. Volá Modal jen na vrcholu stacku.
  // preventDefault brání tomu, aby Alt+← udělalo „zpět" v historii prohlížeče.
  function handleKeydown(e: KeyboardEvent) {
    if (!showNav || !e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    if (isTextEditing(document.activeElement)) return;
    e.preventDefault();
    if (e.key === 'ArrowLeft') goPrev();
    else goNext();
  }

  // Volá FormEditor po načtení / přepočtu formuláře. Aktualizuje header modalu.
  function handleFormLoaded(info: {
    title: string;
    docStates: Record<string, unknown> | null;
    headerInfo: HeaderInfo | null;
  }) {
    currentTitle = info.title;
    currentDocStates = info.docStates;
    savedHeaderInfo = info.headerInfo;
  }

  // Volá FormEditor při změně dirty stavu (po editaci polí nebo po načtení/uložení).
  function handleDirtyChange(dirty: boolean) {
    isDirty = dirty;
  }

  // Modal nesmí zůstat s prázdným titulem dokud se formDef nenačte. Pokud
  // server pošle strukturované header_info, jeho `title` má přednost před
  // generickým `currentTitle` (formDef.title — typicky „Osoba", „Faktura"…).
  const headerTitle = $derived(
    savedHeaderInfo?.title || currentTitle || t('common.loading')
  );

  // Spojený řádek info pro subtitle: „IČO 68253848 · Kód osoby TEST-0098".
  // Prázdný `label` znamená „jen hodnota bez prefixu" (např. „Přijatá faktura"
  // u dokladu — typ je samopopisný, žádný „Typ:" prefix tam nepatří).
  // Renderuje se jen pokud máme aspoň jednu položku info — jinak subtitle
  // snippet vrátí prázdno a Modal subtitle row je vidět jen kvůli badge (pokud je).
  const subtitleText = $derived(
    savedHeaderInfo && savedHeaderInfo.info.length > 0
      ? savedHeaderInfo.info.map(i => i.label ? `${i.label} ${i.value}` : i.value).join(' · ')
      : ''
  );

  // Subtitle row se renderuje, jen když máme strukturovanou hlavičku. Tím se
  // zachovává původní layout (badge vedle titulku, jednořádková hlavička) pro
  // formuláře bez `buildHeaderInfo()` override (typicky JSONC sub-formy).
  const hasHeaderInfo = $derived(savedHeaderInfo !== null);
  const hasSummary = $derived((savedHeaderInfo?.summary?.length ?? 0) > 0);
  const hasIcon = $derived(!!savedHeaderInfo?.icon);
</script>

{#snippet subtitleSnippet()}
  {#if subtitleText}
    {subtitleText}
  {/if}
{/snippet}

{#snippet iconSnippet()}
  {#if savedHeaderInfo?.icon}
    <Icon icon={resolveIcon(savedHeaderInfo.icon)} />
  {/if}
{/snippet}

{#snippet summarySnippet()}
  {#if savedHeaderInfo}
    {#each savedHeaderInfo.summary as item (item.label)}
      <span>{item.label}</span>
      <span>{item.value}</span>
    {/each}
  {/if}
{/snippet}

{#snippet headerExtraSnippet()}
  {#if currentDocStates}
    <FormStateBadge docStates={currentDocStates} />
  {/if}
{/snippet}

{#snippet headerNavSnippet()}
  {#if navigation}
    <Button
      icon={iconChevronLeft}
      iconOnly
      size="sm"
      variant="ghost"
      label={t('form.navPrev')}
      disabled={!canPrev}
      onclick={goPrev}
      testid="form-nav-prev"
    />
    <span class="shpd-form-dialog__nav-pos" data-testid="form-nav-pos">
      {t('form.navPosition', { index: navigation.index + 1, count: navigation.count })}
    </span>
    <Button
      icon={iconChevronRight}
      iconOnly
      size="sm"
      variant="ghost"
      label={t('form.navNext')}
      disabled={!canNext}
      onclick={goNext}
      testid="form-nav-next"
    />
  {/if}
{/snippet}

{#if open}
  <Modal
    title={headerTitle}
    open={true}
    onClose={handleClose}
    width="clamp(1200px, 80vw, 1700px)"
    height="clamp(720px, 88vh, 1100px)"
    subtitle={hasHeaderInfo ? subtitleSnippet : undefined}
    iconSlot={hasIcon ? iconSnippet : undefined}
    summary={hasSummary ? summarySnippet : undefined}
    headerExtra={headerExtraSnippet}
    headerNav={showNav ? headerNavSnippet : undefined}
    onKeydown={handleKeydown}
    testid="form-dialog"
    flush
  >
    {#if notice}
      <div class="shpd-form-dialog__notice" role="status">{notice}</div>
    {/if}
    <FormEditor
      {table}
      {recordId}
      onClose={handleClose}
      onSaved={handleSaved}
      onFormLoaded={handleFormLoaded}
      onDirtyChange={handleDirtyChange}
      {defaultData}
      {readOnly}
      {onSaveAndContinue}
    />
  </Modal>

  <ConfirmDialog
    open={pendingAction !== null}
    title={t('form.unsavedTitle')}
    message={t('form.unsavedChanges')}
    confirmLabel={t('form.unsavedDiscard')}
    cancelLabel={t('form.unsavedStay')}
    variant="danger"
    onConfirm={discardPending}
    onCancel={stayPending}
    testid="unsaved-dialog"
  />
{/if}

<style>
  .shpd-form-dialog__nav-pos {
    min-width: 3.5em;
    text-align: center;
  }

  /* Neutrální informační pruh nad formulářem — na rozdíl od červených
     validačních bannerů ve FormEditor nesignalizuje chybu. */
  .shpd-form-dialog__notice {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    background: var(--shpd-color-bg);
    border-bottom: 1px solid var(--shpd-color-border);
  }
</style>
