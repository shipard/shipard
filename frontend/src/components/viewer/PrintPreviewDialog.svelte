<script>
  /**
   * Náhled tisku záznamu (docs/prints.md, #90 D19) — otevírá ho akce `print`
   * v detailu záznamu. PDF se stáhne jako Blob (Bearer auth) a ukáže
   * v <iframe> z object URL; Stáhnout ho uloží pod názvem, který určil
   * server. Měkká hlášení builderu (QR platba nevznikla) jsou nad náhledem.
   *
   * Jazyk (#90 D33): první načtení jde bez jazyka — volí ho server podle
   * partnera dokladu a vrátí v `Content-Language`; přepínač v patičce pak
   * načte PDF znovu ve zvoleném jazyce. Výběr se nikam neukládá.
   *
   * Prohlížeč bez vestavěného prohlížeče PDF (typicky mobilní) náhled
   * nedostane — jen Stáhnout. Object URL se uvolní při zavření.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import Select from '../ui/Select.svelte';
  import Notice from '../ui/Notice.svelte';
  import { fetchPrintPdf } from '../../api/prints.js';
  import { saveBlob } from '../../utils/download.js';
  import { iconDownload } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    printId = '',
    recordId = null,
    /** Jazyky tisku z akce Tisk (`target.languages`): [{id, label}]. */
    languages = [],
    onClose = () => {},
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let pdfUrl = $state(null);
  let fileName = $state('');
  let messages = $state([]);
  // Jazyk zobrazeného (nebo právě načítaného) tisku; null = ještě ho neznáme.
  let language = $state(null);

  const languageOptions = $derived(languages.map((l) => ({ value: l.id, label: l.label })));

  // Blob a jeho object URL mimo reaktivitu — effect je jen zapisuje a uvolňuje.
  let blob = null;
  let objectUrl = null;
  // Guard proti out-of-order odpovědím: zavření nebo jiný tisk během
  // renderu starou odpověď zahodí.
  let fetchToken = 0;

  const canPreview = typeof navigator === 'undefined' || navigator.pdfViewerEnabled !== false;

  $effect(() => {
    if (open && printId && recordId !== null && recordId !== undefined) {
      void load(printId, recordId, null);
    } else {
      reset();
    }
    return reset;
  });

  /** Zahodí zobrazené PDF — Stáhnout nesmí nabízet soubor v jiném jazyce, než je vybraný. */
  function clearPdf() {
    if (objectUrl !== null) {
      URL.revokeObjectURL(objectUrl);
      objectUrl = null;
    }
    blob = null;
    pdfUrl = null;
    fileName = '';
    messages = [];
  }

  function reset() {
    fetchToken++;
    clearPdf();
    language = null;
    error = null;
    loading = false;
  }

  /** @param {?string} requested Vyžádaný jazyk; null = podle partnera dokladu. */
  async function load(id, record, requested) {
    const token = ++fetchToken;
    clearPdf();
    loading = true;
    error = null;

    const result = await fetchPrintPdf(id, record, requested);
    if (token !== fetchToken) return;

    loading = false;
    if (result === null) return; // 401 — klient už přesměrovává na přihlášení
    if (!result.success) {
      error = translateError(result.error);
      return;
    }

    blob = result.blob;
    objectUrl = URL.createObjectURL(result.blob);
    pdfUrl = objectUrl;
    fileName = result.fileName;
    messages = result.messages;
    language = result.language ?? requested;
  }

  function changeLanguage(event) {
    const value = event.currentTarget.value;
    if (!value || value === language) return;
    // Výběr drží i po chybě — uživatel vidí, který jazyk selhal, a zvolí jiný.
    language = value;
    void load(printId, recordId, value);
  }

  function download() {
    if (blob !== null) saveBlob(blob, fileName);
  }
</script>

<Modal title={t('print.preview.title')} {open} {onClose} width="960px" height="90vh" testid="print-preview">
  <div class="shpd-print">
    {#if loading}
      <div class="shpd-print__state">
        <span class="shpd-print__spinner"></span>
        <span>{t('print.preview.loading')}</span>
      </div>
    {:else if error}
      <div class="shpd-print__state shpd-print__state--error" role="alert">{error}</div>
    {:else if pdfUrl}
      {#if messages.length > 0}
        <Notice tone="warning" items={messages.map((message) => message.text)} />
      {/if}
      {#if canPreview}
        <iframe class="shpd-print__frame" src={pdfUrl} title={fileName}></iframe>
      {:else}
        <div class="shpd-print__state">{t('print.preview.unsupported')}</div>
      {/if}
    {/if}
  </div>

  {#snippet footer()}
    <!-- Jeden obal: patička modalu na mobilu roztahuje své děti do jedné
         řady, výběr jazyka by se v ní neuvešel — tady se láme nad tlačítka.
         Snippet se kreslí v patičce Modalu mimo `.shpd-print`, proto má
         vlastní kontejner na celou šířku patičky (#133 D43). -->
    <div class="shpd-print__footer-box">
    <div class="shpd-print__footer">
      {#if languageOptions.length > 1}
        <div class="shpd-print__language" data-testid="print-language">
          <label for="shpd-print-language">{t('print.preview.language')}</label>
          <!-- Než server vrátí jazyk tisku, výběr ukazuje „Automaticky". -->
          <Select
            id="shpd-print-language"
            value={language}
            options={languageOptions}
            required
            placeholder={language === null ? t('print.preview.languageAuto') : undefined}
            disabled={loading}
            onchange={changeLanguage}
          />
        </div>
      {/if}
      <Button label={t('common.close')} variant="secondary" size="sm" onclick={onClose} />
      <Button
        label={t('print.download')}
        icon={iconDownload}
        variant="primary"
        size="sm"
        disabled={pdfUrl === null}
        onclick={download}
      />
    </div>
    </div>
  {/snippet}
</Modal>

<style>
  .shpd-print {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    height: 100%;
    min-height: 0;
    /* Kontejner pro @container níže (#133 D43): obsah reaguje na svou šířku,
       ne na okno — bývá v panelu, draweru i modalu. Blok na celou šířku
       rodiče, mřížka až uvnitř (dotaz platí pro potomky kontejneru). */
    container-type: inline-size;
  }

  .shpd-print__frame {
    flex: 1;
    width: 100%;
    min-height: 0;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background: var(--shpd-color-bg-secondary);
  }

  /* Kontejner pro @container patičky: položka patičky Modalu na celou
     šířku (flex: 1, min-width: 0 — inline-size containment by ji jinak
     smrštil na nulu), vlastní řádek až uvnitř. */
  .shpd-print__footer-box {
    flex: 1;
    min-width: 0;
    container-type: inline-size;
  }

  .shpd-print__footer {
    display: flex;
    /* Výchozí stretch — tlačítka dorostou na výšku výběru jazyka. */
    justify-content: flex-end;
    gap: var(--shpd-space-sm);
  }

  .shpd-print__language {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    /* Výběr vlevo, tlačítka vpravo. */
    margin-right: auto;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-print__language :global(.shpd-select__wrapper) {
    width: 11rem;
  }

  /* Úzký kontejner: výběr jazyka na vlastní řádek, tlačítka pod ním přes
     celou šířku. */
  @container (max-width: 30rem) {
    .shpd-print__footer {
      flex-wrap: wrap;
    }

    .shpd-print__language {
      flex: 1 0 100%;
      margin-right: 0;
    }

    .shpd-print__language :global(.shpd-select__wrapper) {
      width: auto;
      flex: 1;
    }

    .shpd-print__footer > :global(.shpd-btn) {
      flex: 1;
    }
  }

  .shpd-print__state {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-print__state--error {
    color: var(--shpd-color-danger);
  }

  .shpd-print__spinner {
    width: 16px;
    height: 16px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-print-spin 0.8s linear infinite;
  }

  @keyframes shpd-print-spin {
    to { transform: rotate(360deg); }
  }
</style>
