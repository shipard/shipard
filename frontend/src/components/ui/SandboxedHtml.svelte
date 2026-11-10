<script>
  // Sandboxovaný rendering HTML z nedůvěryhodného zdroje (tělo e-mailu).
  // Obsah běží v <iframe srcdoc> — samostatný dokument izoluje CSS oběma
  // směry a sandbox bez allow-scripts zaručuje, že se nespustí žádný skript.
  //
  // Props:
  //   html   string — HTML tak, jak přišlo z API (fragment i celý dokument;
  //          srcdoc atribut Svelte escapuje sám)
  //   title  string — accessibility popisek iframu

  let { html, title } = $props();

  let frame = $state(null);

  // BEZPEČNOST: nikdy nepřidávat allow-scripts. V kombinaci
  // s allow-same-origin by srcdoc dokument sdílel origin aplikace a skript
  // z e-mailu by přečetl Bearer token z localStorage. Bez allow-scripts
  // uvnitř nic neběží, takže allow-same-origin je bezpečné a dává parentu
  // přístup na contentDocument (fixace odkazů, auto-height).
  // Viz tasks/mail-html-sandbox.md, rozhodnutí D3.
  const SANDBOX_FLAGS = 'allow-same-origin allow-popups allow-popups-to-escape-sandbox';

  const LINK_PROTOCOLS = ['http:', 'https:', 'mailto:', 'tel:'];

  // Nulová specificita (:where) — vlastní styly e-mailu vždy vyhrají.
  // Záměrně vždy světlé pozadí: e-maily jsou designované na bílou,
  // tmavý režim aplikace se dovnitř nepropaguje.
  const BASE_STYLES = `
    :where(html) { background: #fff; }
    :where(body) {
      margin: 8px;
      font-family: system-ui, -apple-system, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: #111;
      overflow-wrap: break-word;
    }
    :where(img) { max-width: 100%; height: auto; }
  `;

  function measure() {
    const doc = frame?.contentDocument;
    if (!doc) return;
    // Reset před měřením — scrollHeight dokumentu nikdy neklesne pod výšku
    // viewportu iframu, bez resetu by se výška při kratším obsahu (přepnutí
    // na jinou zprávu) nezmenšila.
    frame.style.height = '';
    const height = Math.max(
      doc.documentElement?.scrollHeight ?? 0,
      doc.body?.scrollHeight ?? 0,
    );
    if (height > 0) {
      frame.style.height = `${height}px`;
    }
  }

  // Po každém load (spouští se i při změně srcdoc) upraví dokument zprávy.
  function prepareDocument() {
    const doc = frame?.contentDocument;
    if (!doc?.head) return;

    // <base target="_blank"> jako první element <head> — default cíl odkazů.
    if (!doc.head.querySelector('base')) {
      const base = doc.createElement('base');
      base.target = '_blank';
      doc.head.insertBefore(base, doc.head.firstChild);
    }

    const style = doc.createElement('style');
    style.textContent = BASE_STYLES;
    doc.head.appendChild(style);

    // Auto-navigace pryč ze srcdoc obsahu.
    for (const meta of doc.querySelectorAll('meta[http-equiv="refresh" i]')) {
      meta.remove();
    }

    for (const link of doc.querySelectorAll('a[href]')) {
      // Kontrola přes protocol property je robustní — prohlížeč už
      // normalizoval whitespace/entity triky v raw atributu.
      if (!LINK_PROTOCOLS.includes(link.protocol)) {
        link.removeAttribute('href'); // javascript:, data:, vbscript:, …
      } else if (link.getAttribute('href')?.startsWith('#')) {
        // In-page kotva — jinak by ji <base> poslal do nového tabu.
        link.setAttribute('target', '_self');
      } else {
        link.setAttribute('rel', 'noopener noreferrer');
      }
    }

    measure();

    // Obrázky doskakují asynchronně — po každém přeměřit.
    for (const img of doc.images) {
      if (img.complete) continue;
      img.addEventListener('load', measure);
      img.addEventListener('error', measure);
    }
  }

  // Změna šířky panelu → reflow obsahu → nová výška.
  $effect(() => {
    if (!frame) return;
    const observer = new ResizeObserver(measure);
    observer.observe(frame);
    return () => observer.disconnect();
  });
</script>

<!-- BEZPEČNOST: sandbox nikdy nerozšiřovat o allow-scripts — viz komentář
     u SANDBOX_FLAGS ve script bloku (invarianta D3, tasks/mail-html-sandbox.md). -->
<iframe
  bind:this={frame}
  {title}
  class="shpd-sandboxed-html"
  sandbox={SANDBOX_FLAGS}
  referrerpolicy="no-referrer"
  srcdoc={html}
  onload={prepareDocument}
></iframe>

<style>
  .shpd-sandboxed-html {
    display: block;
    width: 100%;
    min-height: 3.75rem; /* placeholder než doběhne load */
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    /* Bílá i před loadem — žádný flash; v tmavém režimu působí blok
       jako záměrná „karta zprávy". */
    background: #fff;
  }
</style>
