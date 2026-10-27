# Design system — breakpointy shellu a obsah na container queries

**Stav:** naplánováno — #138 task 1 (D42, D43, D47)

## Kontext / Cíl

První task #138 (zastřešující #133). Aplikace má dnes 14 media queries se
čtyřmi hodnotami (768 / 720 / 640 / 600 px) a vývojářský dashboard dvě
s 1100 px. Hodnota shellu 768 px žije dvakrát — v CSS a v JS konstantě
`MOBILE_BREAKPOINT` — a hlídá ji jen komentář. Container queries má jen
pilot (`DocumentDetail`, `PropertyGroups`, 30 rem). Obsah, který bývá
v panelu, draweru i modalu, se tak často řídí šířkou okna, která o jeho
šířce nic neříká (D3).

**Cíl:**

1. Media query jen pro shell aplikace s jedinou hodnotou `48rem`
   (dnes 768 px), JS konstanta z téže hodnoty (D42).
2. Obsahové media queries → container queries s prahem `30rem` (D43).
3. Pre-commit kontrola hodnot v `@media` a `@container` a shody JS
   konstanty s CSS (D47, bez rozměrové části).
4. Pravidla responzivity v `docs/design-system.md`.

Vizuálně se nic nemá změnit při 16 px písmu prohlížeče a šířkách, kde
dnešní media query a nový container query dávají stejný výsledek (telefon,
široké okno). Rozdíly mezi tím jsou záměr (obsah reaguje na svou šířku) —
viz Ověření.

**Mimo rozsah:**

- tabulky — obal s posunem do strany vždy, složené řádky (D44, D45) —
  #139 a task 3 #138; `FormSubTable` zatím jen přepne hodnotu;
- pevné šířky, výšky a layout tokeny v px, prop `width` u `Modal` (D46) —
  task 4 #138;
- `.snapshots-container` — HTML se vzhledem z backendu ve formuláři dokladu
  (`DocsHeadsFormBase`), dluh zapsaný v #138; tady jen přepne hodnotu;
- JS logika `isMobile` (kdo ji čte, co dělá) — mění se jen hodnota;
- review modal — přestavba stran a řádků je #139; tady jen převod
  dnešní media query stran na container query.

## Návaznost

- **#133** — D3, D11, D12, D42, D43, D47
- **#135** — pilot: container query 30 rem v `DocumentDetail`
- **#137** task 1 — `PropertyGroups` (vzor kontejneru na obalu mřížky)
- **#139** — použije práh `36rem` pro složené řádky (kontrola ho už povolí)

## Před implementací přečti

- `docs/design-system.md` §8 Layout konvence (Mobilní drawer, Modální
  dialogy), §10 Jednotky a mezery (Jak psát hodnoty, Kontrola
  v pre-commitu), §12 Úzká šířka
- `docs/frontend.md` → Aplikační shell, Mobilní režim (breakpoint na dvou
  místech)
- `frontend/src/stores/layout.svelte.js`
- `scripts/check-css-units.py`, `.githooks/pre-commit`
- `frontend/src/components/ui/PropertyGroups.svelte` — kontejner na obalu
  mřížky, komentář proč

## Rozhodnutí (potvrzená v #133)

- ✓ **D42** Media query jen pro shell aplikace a jedna hodnota `48rem`:
  drawer, modal přes celou obrazovku, přepínání seznam / detail, prvky
  vázané na výšku okna (`vh`). `rem` v `@media` = výchozí písmo
  prohlížeče — při 16 px beze změny, při větším písmu přijde mobilní
  rozložení dřív. Vývojářský dashboard má vlastní shell a hodnotu
  `68.75rem` (dnes 1100 px).
- ✓ **D43** Obsah reaguje na šířku kontejneru. Prahy kontejneru `30rem`
  (úzký — sloupce pod sebe, popisky nad pole) a `36rem` (složené řádky
  dokladu, #139). Obsahové 768 / 720 / 640 / 600 px přejdou na `30rem`.
- ✓ **D47** Kontrola v pre-commitu: `@media` přijme jen hodnoty shellu,
  `@container` jen prahy D43, JS konstanta musí odpovídat CSS. (Rozměry
  v px až task 4.)

## 1. Shell — `48rem`

- `stores/layout.svelte.js`: `MOBILE_BREAKPOINT = '48rem'` (řetězec
  s jednotkou), `matchMedia(\`(max-width: ${MOBILE_BREAKPOINT})\`)`.
  Komentář: hodnotu hlídá kontrola (§3), ne komentáře v komponentách.
- `@media (max-width: 768px)` → `@media (max-width: 48rem)`:
  - `ui/Modal.svelte` — modal přes celou obrazovku;
  - `viewer/Viewer.svelte` — přepínání seznam / detail;
  - `exchange/DocumentExchangePreviewModal.svelte` — taby PDF / údaje
    místo splitu;
  - `viewer/AttachmentGrid.svelte` — výška náhledu PDF ve `vh`;
  - `form/FormSubTable.svelte` — dočasně, obal tabulky vyřeší task 3
    (D44);
  - `styles/base.css` `.snapshots-container` (dnes 720 px) — dočasně,
    dluh v #138.
- Komentáře „Breakpoint 768px ladí s MOBILE_BREAKPOINT“ nahradit odkazem na
  D42 (hodnotu hlídá kontrola).
- Vývojářský dashboard: `dev/dev.css` a
  `dev/pages/testing/RunDetailPage.svelte` 1100 px → `68.75rem`.
- `@media (hover: none)` v `chat/TableBlock.svelte` beze změny — netýká se
  šířky.

## 2. Obsah — container queries `30rem`

Každý blok dostane kontejner na svém kořeni (obal na celou šířku,
`container-type: inline-size`) a dnešní media query se změní na
`@container (max-width: 30rem)`:

| Soubor | Dnes | Kontejner |
|---|---|---|
| `settings/SettingsPage.svelte` | 640 px | `.shpd-settings-page` |
| `account/ChangePasswordPanel.svelte` | 640 px | `.shpd-security-panel` |
| `settings/DsAbout.svelte` | 600 px | `.shpd-ds-about` |
| `viewer/SendDialog.svelte` | 768 px | `.shpd-send` |
| `viewer/PrintPreviewDialog.svelte` | 768 px | `.shpd-print` |
| `exchange/DocumentExchangePreview.svelte` (strany) | 600 px | `.shpd-exchange` |
| `form/FormSection.svelte` | 768 px | `.shpd-form-section`, pojmenovaný `form-section` |
| `form/FormAttachmentsView.svelte` | 768 px | týž `form-section` |

- `FormAttachmentsView` je prvek uvnitř sloupce `FormSection` a musí se
  přepnout ve stejný okamžik jako sloupce — proto pojmenovaný kontejner
  (`container-name: form-section`, dotaz `@container form-section
  (max-width: 30rem)`), ne nejbližší předek.
- U každého kořene ověř, že je blok na celou šířku rodiče (ne flex/inline
  prvek smrštěný na obsah); kde je ve flex řádku, dej mu `width: 100%`
  nebo `flex: 1` a `min-width: 0`.
- Pokud kořen komponenty nemá šířku obsahu (např. obsah je až uvnitř
  `Modal` slotu), dej kontejner na nejbližší obal na celou šířku a uveď to
  v komentáři.

## 3. Kontrola (D47)

Rozšířit `scripts/check-css-units.py` (stejný průchod `frontend/src`,
stejné parsování `<style>` a `.css`), ne kopii parseru:

- **`@media`** s rysem šířky (`width`, `min-width`, `max-width`, i zápis
  rozsahu `width <= …`) přijme jen:
  - `48rem` mimo `frontend/src/dev/`,
  - `68.75rem` ve `frontend/src/dev/`.
  Rysy bez šířky (`hover`, `pointer`, `prefers-*`, `print`) projdou.
- **`@container`** přijme jen `30rem` a `36rem` (celý `frontend/src`).
- **JS konstanta:** `MOBILE_BREAKPOINT` v `stores/layout.svelte.js` musí
  být řetězec rovný hodnotě shellu; jiná hodnota nebo chybějící konstanta
  = chyba.
- Povolené hodnoty v jednom místě skriptu (konstanty nahoře s odkazem na
  D42 / D43), výpis chyby `soubor:řádek: @media … — povolené: …`.
- Výjimka značkou jako dnes (`/* px-ok: … */`) pro breakpointy **neplatí**
  — jiná hodnota znamená nové rozhodnutí v #133.
- Docstring skriptu a `.githooks/pre-commit` (text nápovědy) doplnit.
- Test, pokud skript testy má; jinak ruční ověření: dočasně vložená
  `@media (max-width: 700px)` a `@container (max-width: 25rem)` → exit 1,
  pak vrátit.

## 4. Dokumentace

- `docs/design-system.md`:
  - §8 nová podsekce **Responzivita** (D42, D43, D47): media query jen
    shell, tabulka hodnot (shell `48rem`, dev dashboard `68.75rem`,
    kontejner `30rem` / `36rem` a k čemu), proč `rem` v `@media`
    (výchozí písmo prohlížeče, větší písmo = mobilní rozložení dřív), jak
    převést obsah (kontejner na obal na celou šířku, pojmenovaný kontejner,
    když se dva prvky musí přepnout spolu), nová hodnota = nové rozhodnutí;
  - §8 Mobilní drawer a Modální dialogy: „≤ 768px“ → „≤ 48rem (768 px)“;
  - §10: odstavec o D11 — breakpointy už řeší §8; pevné šířky a výšky
    a layout tokeny zůstávají v px do #138 task 4; v seznamu „V px
    zůstává“ odebrat `@media`; Kontrola v pre-commitu doplnit o breakpointy;
  - §12 Úzká šířka: prahy odkazem na §8, `36rem` pro řádky dokladu (#139).
- `docs/frontend.md` → Mobilní režim: breakpoint je `48rem` na jednom
  místě v CSS i JS, shodu hlídá kontrola; ostatní „≤ 768px“ v souboru
  přepsat na `48rem` (grep `768` v `docs/frontend.md`, `docs/edit-forms.md`,
  `docs/design-system.md`).
- `tasks/README.md` — řádek v sekci Design system.

## Commity

1. `feat(frontend): breakpoint shellu 48rem, obsah na container queries (#138, D42, D43)`
   — §1 a §2
2. `build: kontrola breakpointů v pre-commitu (#138, D47)` — §3
3. `docs: responzivita — breakpointy a prahy kontejneru (#138, D42, D43, D47)`
   — §4, `**Stav:**` tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
grep -rnoE --include=*.svelte --include=*.css "@(media|container)[^{]*" frontend/src
```

Poslední příkaz: v aplikaci jen `48rem`, v dev dashboardu `68.75rem`,
kontejnery `30rem`, k tomu `(hover: none)`.

V prohlížeči, šířky okna **390, 700, 769, 800 a 1280 px**:

- shell se přepíná přesně na 768 / 769 px: drawer, modal přes celou
  obrazovku, seznam / detail, taby v review modalu; JS (`isMobile`)
  a CSS se přepnou současně — žádný stav, kdy je drawer a vedle něj
  desktopové rozložení;
- **větší písmo prohlížeče (20 px):** mobilní rozložení přijde při 960 px
  okna, JS a CSS opět současně;
- Nastavení (stránka nastavení, O zdroji dat, Zabezpečení účtu): na 390
  popisky nad poli; na 800 se sidebarem dva sloupce;
- formulář dokladu s přílohami (`FormSection` + náhled příloh): na 390
  sloupce pod sebou a náhled příloh v pevném okně; na 700 a výš dva
  sloupce — dnes je 700 pod sebou, teď vedle sebe; **posoudit
  čitelnost** a výsledek napsat do #138;
- Odeslat e-mailem a Náhled tisku: na 390 popisky nad poli, patička
  zalomená; na 1280 beze změny;
- review modal: strany na 390 pod sebou, na 1280 vedle sebe;
- vývojářský dashboard: split na 1100 / 1101 px;
- rozbalovací prvky uvnitř nových kontejnerů (lookup, select, `Popover`
  ve formuláři, v Nastavení a v Odeslat) se neořezávají a nejsou
  posunuté.

## Hotovo když

- [ ] v aplikaci jen `@media … 48rem`, ve dev dashboardu `68.75rem`
- [ ] `MOBILE_BREAKPOINT = '48rem'`, `matchMedia` s rem
- [ ] obsahové media queries jako `@container … 30rem` (tabulka v §2)
- [ ] kontrola breakpointů a JS konstanty v pre-commitu
- [ ] `docs/design-system.md` §8, §10, §12, `docs/frontend.md`
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **`rem` v `@media` není rem stránky.** Bere výchozí písmo prohlížeče,
  ne `font-size` kořene. Aplikace kořenové písmo nenastavuje (§10), takže
  CSS i `matchMedia` v JS počítají stejně — kořenové písmo nenastavovat.
- **`@container` platí pro potomky.** Dotaz kořene sám na sebe nefunguje;
  měněný prvek (mřížka) musí být uvnitř kontejneru. Kontejner na prvku,
  který se smršťuje podle obsahu, zkolabuje na nulu.
- **Nejbližší kontejner vyhrává.** Nepojmenovaný `@container` se ptá
  nejbližšího předka s `container-type` — uvnitř formuláře nebo detailu
  může být jiný kontejner, než čekáš. Kde na tom záleží, pojmenovat.
- **Containment a rozbalovací prvky.** Kontejner zavádí containment;
  ověř, že absolutně a fixně polohované vrstvy uvnitř (lookup, select,
  `Popover`) se nechovají jinak než dřív.
- **Bez nových hodnot.** Žádná další hodnota v `@media` / `@container`
  bez rozhodnutí v #133 — kontrola ji odmítne a `px-ok` ji nepustí.
- **Šířky nechat být.** `width`, `min-*`, `max-*`, layout tokeny a prop
  `width` u `Modal` zůstávají v px (task 4).
