# Design system — typy obsahu místo HTML a sdílená hláška

**Stav:** hotovo — #137 task 3 (D30, D40, D41), 2026-10-27

## Kontext / Cíl

Třetí task #137 (zastřešující #133). Audit (v #137):

- Backend posílá do detailu na 12 místech hotové HTML se vzhledem: stav
  zaúčtování (doklady i bankovní pohyb, tentýž kód 2× — štítek s inline
  styly a px, seznam hlášení s vlastním tónem a odsazením 28 px), prázdný
  stav 7× (natvrdo česky i v angličtině), prostý text (tělo pošty, AI
  shrnutí Spisovny), výpis JSON (upozornění).
- Hlášky ve frontendu mají asi 12 kopií CSS ve třech barevných rodinách
  (`*-soft`, `alert-*`, `state-*`), s odsazením sm až xl, zaoblením sm / md
  a někde s levým pruhem.
- Pruhy nad obsahem (pouze ke čtení, reálná kopie, cron vypnutý,
  bezpečnost pošty) vypadají stejně, ale jsou to čtyři kopie CSS.

**Cíl:**

1. `Notice` (hláška), `Badge` (štítek z hlavičky detailu) a `PageBanner`
   (pruh nad obsahem) v `frontend/src/components/ui/`.
2. Typy obsahu detailu `notice`, `badges`, `empty`, `text`, `code`; typ
   `html` z kontraktu i frontendu pryč (D30).
3. Všech 12 HTML bloků ve viewerech na nových typech; texty prázdného
   stavu z katalogů popisků.
4. Hlášky ve frontendu na `Notice` (D40), pruhy na `PageBanner` (D41).
5. Kontrakt detailu hlídá, že `html` neposílá žádný viewer.

**Mimo rozsah** (kandidáti do #133 / #137):

- prvek `html` v editačních formulářích (`docs/edit-forms.md`) — jiný
  systém, stejný problém;
- barevné řádky a štítek v tabulce reportu (`ReportView.svelte`
  `.shpd-report__badge`, `__tr--error/--warning`) — součást tabulky
  reportu, ne hláška;
- chybová stránka portálu hostingu;
- toast (`GlobalToast`), potvrzovací dialog.

## Návaznost

- **#133** — D15, D20, D25, D33, D30, D40, D41
- **#137** task 1 — `SectionHeading`; task 2 — `Card` (tón jako vzor)
- **#79** — stav a hlášení zaúčtování (`accounting_messages`)

## Před implementací přečti

- `docs/design-system.md` §5 (štítky), §8 Karty, §11, §12 Stavební prvky
  obsahu
- `docs/frontend.md` → Formát detail panelu (tabulka typů obsahu, řádek
  `html`)
- `frontend/src/components/viewer/ViewerDetail.svelte` — `renderContent`,
  štítky hlavičky (`.shpd-detail__badge*`)
- `frontend/src/styles/variables.css` — `--shpd-color-alert-*` (světlý
  i tmavý blok), `--shpd-color-state-done-*`
- `DocsHeadsViewer::accountingStatusBlock()`,
  `BankTransactionsViewer::accountingStatusBlock()`
- `tests/Integration/Api/ViewerDetailContractTest.php`,
  `tests/Unit/Core/Viewer/DetailContractTest.php`

## Rozhodnutí (potvrzená v #133)

- ✓ **D30** Backend neposílá HTML se vzhledem: typ `html` z kontraktu
  detailu mizí, zůstává jen `untrusted-html` (tělo e-mailu v sandboxu).
  Nové typy `notice`, `badges`, `empty`, `text`, `code`. Kontrakt detailu
  ověří, že žádný viewer `html` nepošle. Texty prázdného stavu
  z katalogů popisků vieweru.
- ✓ **D40** `Notice` — jedna sdílená hláška v obsahu: tóny `info` /
  `success` / `warning` / `danger`, barvy jen z rodiny
  `--shpd-color-alert-*` (pozadí, text, rámeček 1 px v barvě tónu;
  `success` aliasy na existující zelené), zaoblení md, odsazení sm / md,
  písmo body; volitelně nadpis, ikona, seznam; bez levého pruhu; role
  `alert` u `danger`, jinak `status`.
- ✓ **D41** Pruh nad obsahem je jiný prvek než hláška: celá šířka, linka
  dole, bez zaoblení; jedna komponenta místo čtyř kopií, vzhled beze
  změny.

## 1. Tokeny

`variables.css`:

- `--shpd-color-alert-success-bar` / `-bg` / `-text` jako aliasy
  (`var(--shpd-color-success)`, `var(--shpd-color-state-done-bg)`,
  `var(--shpd-color-state-done-text)`) — žádná nová barva; ověřit, že
  tmavý vzhled dostane tmavé hodnoty (aliasy se vyhodnotí na `:root`
  s `data-theme`).
- `--shpd-font-family-mono` (systémový neproporcionální stack), pokud
  token neexistuje.
- `FormSubTable.svelte` dnes odkazuje na neexistující
  `--shpd-color-alert-danger-bg` — zanikne převodem na `Notice`.

## 2. Komponenty (`frontend/src/components/ui/`)

- **`Notice.svelte`** (D40): props `tone` (`info` výchozí | `success` |
  `warning` | `danger`; `danger` → tokeny `alert-error-*`), `title`
  (nepovinný, `font-weight-strong`, velikost body), `icon` (nepovinný
  objekt ikony), `items` (pole textů → `<ul>`; jinak `children`).
  Vzhled: pozadí `alert-<tón>-bg`, text `alert-<tón>-text`, rámeček 1 px
  `alert-<tón>-bar`, zaoblení md, odsazení `--shpd-space-sm`
  `--shpd-space-md`, `--shpd-font-size-body`. Bez levého pruhu, bez
  vnějšího okraje. `role="alert"` u `danger`, jinak `role="status"`.
- **`Badge.svelte`**: štítek z hlavičky detailu vytažený do komponenty —
  markup a CSS `.shpd-detail__badge` včetně všech variant (`neutral`,
  `primary`, `accent`, `success`, `warning`, `danger` a stavové varianty).
  Hlavička detailu ho použije; vzhled beze změny. `SpanBadge` (seznam)
  zůstává, jak je.
- **`PageBanner.svelte`** (D41): pruh nad obsahem — dnešní vzhled
  `ReadOnlyBanner` (odsazení xs / lg, pozadí a text `alert-warning-*`,
  linka dole 2 px `alert-warning-bar`, `font-size` jako dnes); prop
  `tone` (`warning` výchozí, `info`, `danger`) pro budoucí pruhy.
  `ReadOnlyBanner`, `RealCopyBanner`, `CronOffBanner` a varianta pruhu
  v `MailSafetyNotice` ho použijí (texty a logika zůstávají v nich).

## 3. Typy obsahu detailu (D30)

`ViewerDetail.svelte` (`renderContent`) a `docs/frontend.md`:

| Typ | Tvar | Kreslí |
|---|---|---|
| `notice` | `{tone, title?, text? \| items?}` | `Notice` |
| `badges` | `{items: [{label, style}]}` | řada `Badge` (styly jako štítky hlavičky) |
| `empty` | `{text}` | odstavec body, `--shpd-color-text-secondary` |
| `text` | `{text}` | odstavec body, `white-space: pre-wrap`, `overflow-wrap: anywhere` |
| `code` | `{text}` | `<pre>` s `--shpd-font-family-mono`, velikost meta, vlastní `overflow-x: auto`, pozadí `--shpd-color-bg-secondary`, zaoblení md, odsazení sm |

- Větev `html` z `renderContent` smazat. `untrusted-html` beze změny.
- Nový typ v `composite` dostává mezeru nad sebou jako ostatní bloky
  (task 1).

## 4. Převod ve viewerech

- **Stav zaúčtování** (`DocsHeadsViewer`, `BankTransactionsViewer`):
  společná logika do jednoho místa (trait nebo služba v
  `economy.accounting`, podle toho, kde je `decodeAccountingMessages`) —
  dnes je kód dvakrát. Výstup `composite`:
  - `badges` se stavem: 0 Nezaúčtováno → `warning`, 1 Zaúčtováno →
    `success`, 2 Chyba účtování → `danger`; název z
    `economy.accounting.accountingStates` jako dnes;
  - je-li hlášení: `notice` s `items` — stav 2 → `danger`, jinak
    `warning` (dnes fialový tón „V opravě“); u dokladů text řádku
    `(řádek N)` přes katalog popisků, ne `language === 'cs'`.
- **Prázdný stav** → `empty`: `PersonsViewer`, `RegistryDocumentsViewer`,
  `IncomingMessagesViewer` (4×, včetně „Originální .eml není k dispozici“
  — bez `<code>`). Texty do katalogů popisků vieweru daného modulu
  (`…viewerDetailLabels` nebo `viewerLabels`, cs + en), čtené přes
  `detailTabLabel()` / `text()`.
- **Prostý text** → `text`: tělo došlé pošty (fallback bez HTML), tělo
  odeslané pošty, AI shrnutí Spisovny.
- **Upozornění, tab Kontext** → `composite`: `heading` „Akce“ + `code`,
  `heading` „Kontext“ + `code` (JSON jako dnes); nadpisy z katalogu
  `core.alerts.viewerDetailLabels`.
- Testy viewerů, které dnes kontrolují HTML (`assertStringContainsString`
  na `html`), přepsat na nové typy.

## 5. Kontrakt

- `ViewerDetailContractTest` (integrační): rekurzivně projít obsah všech
  tabů (`composite.blocks`) detailů prvního záznamu každého vieweru —
  žádný blok typu `html`. Hláška při selhání: viewer a tab, ne obsah.
- `DetailContractTest` (unit): známé typy obsahu včetně nových; `html`
  mezi nimi není.

## 6. Hlášky ve frontendu (D40)

| Soubor | Dnes | Na |
|---|---|---|
| `auth/LoginScreen.svelte` | `__error`, `__success` | `Notice danger` / `success` |
| `auth/OpAuthScreen.svelte`, `auth/SetPasswordScreen.svelte` | `.shpd-login__error` | `Notice danger` |
| `account/SessionsPanel.svelte` | `__error` | `Notice danger` |
| `form/FormEditor.svelte` | `__error-banner`, `__validation-banner`, `__warning-banner` | `Notice danger` / `danger` / `warning` |
| `form/FormSubTable.svelte` | `__error` | `Notice danger` |
| `reports/ReportsPage.svelte` | `__error` | `Notice danger` |
| `viewer/PrintPreviewDialog.svelte` | `__messages` (seznam) | `Notice warning items` |
| `ui/DocumentLockBanner.svelte` | rámeček + levý pruh 4 px, ikona, nadpis | `Notice warning` s `icon` a `title` — pruh zaniká |
| `ui/MailSafetyNotice.svelte` | inline varianta s levým pruhem 3 px | `Notice warning` — pruh zaniká; varianta pruhu → `PageBanner` |

Zaniklé třídy smazat a ověřit grepem (`--include=*.svelte --include=*.js`);
`data-testid` zachovat.

## 7. Dokumentace

- `docs/design-system.md`:
  - nová kapitola nebo podkapitola **Hlášky a pruhy** (u §8 Karty): D40,
    D41, kdy hláška / pruh / karta s tónem / toast; pravidlo „nová hláška
    = `Notice`“;
  - §12 Stavební prvky obsahu: typy `notice`, `badges`, `empty`, `text`,
    `code`; D30 — backend HTML se vzhledem neposílá, hlídá kontrakt;
  - §5 štítky: `Badge` jako komponenta;
  - §13 Soubory: `Notice`, `Badge`, `PageBanner`.
- `docs/frontend.md` → Formát detail panelu: tabulka typů (řádek `html`
  pryč, nové typy).

## Commity

1. `feat(frontend): sdílená hláška, štítek a pruh nad obsahem (#137, D40, D41)`
   — tokeny, `Notice`, `Badge`, `PageBanner`, hlavička detailu na `Badge`,
   pruhy na `PageBanner`
2. `feat(frontend): typy obsahu detailu místo HTML (#137, D30)` —
   `renderContent`, větev `html` pryč
3. `refactor: viewery bez HTML se vzhledem (#137, D30)` — převod §4,
   katalogy textů, testy viewerů, kontrakt §5
4. `refactor(frontend): hlášky na sdílené Notice (#137, D40)` — §6
5. `docs: typy obsahu a hlášky (#137, D30, D40, D41)` — §7, `**Stav:**`,
   `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
vendor/bin/phpunit --filter 'DetailContractTest|DocsHeadsViewer|BankTransactionsViewer|PersonsViewer|RegistryDocumentsViewer|IncomingMessagesViewer|SentMessagesViewer|AlertsViewer'
SHIPARD_INTEGRATION_DS_PATH=<dev DS> vendor/bin/phpunit --testsuite Integration --filter ViewerDetailContractTest
grep -rn --include=*.php "'type' => 'html'" modules src   # prázdné
```

V prohlížeči, světlý i tmavý vzhled:

- tab Zaúčtování u dokladu nezaúčtovaného, zaúčtovaného, s chybou
  a zaúčtovaného s varováním; totéž u bankovního pohybu;
- prázdné stavy (osoba bez dalších údajů, dokument Spisovny bez obsahu,
  došlá pošta bez těla / bez AI analýzy / bez originálu), přepnout jazyk
  na angličtinu;
- tělo došlé pošty bez HTML, odeslaná pošta, AI shrnutí Spisovny;
- upozornění s tabem Kontext (dlouhý řádek JSON roluje v bloku);
- přihlášení se špatným heslem, nastavení hesla, relace, formulář
  s chybou validace a varováním, zamčený doklad, náhled tisku s hlášením,
  chyba reportu, upozornění na bezpečnost pošty v dialogu Odeslat;
- pruhy nad obsahem (pouze ke čtení, reálná kopie) beze změny.

## Hotovo když

- [x] `Notice`, `Badge`, `PageBanner`, tokeny
- [x] typy `notice`, `badges`, `empty`, `text`, `code`; `html` pryč
- [x] 12 bloků ve viewerech převedených, stav zaúčtování na jednom místě,
  texty prázdného stavu v katalozích (cs + en)
- [x] kontrakt hlídá `html`
- [x] hlášky ve frontendu na `Notice`, pruhy na `PageBanner`
- [x] dokumentace, `**Stav:**`, `tasks-index.py`

## Odchylky od zadání

- Sdílený stav zaúčtování je `Core\Accounting\AccountingStatusContent`
  v `src/Core/Accounting/`, ne v `economy.accounting`: `docs.core` na
  `economy.accounting` záviset nesmí a `decodeAccountingMessages()` v modulu
  nebyl — byl dvakrát ve viewerech. Bankovní pohyb dostává příponu
  `(řádek N)` stejnou cestou; jeho engine `rowId` nedává, výstup se nemění.
- Texty z katalogů čte nový `TableViewer::detailText()` ze sekce `texts`
  v `*.viewerDetailLabels` (persons, registry, mail, alerts, accounting);
  tři kopie `text()` nad `viewerLabels` (AI, majetek, zakázky) zůstávají.
- Seznam typů obsahu je `Core\Viewer\Detail\DetailContent::TYPES`
  s rekurzivním `types()` pro oba kontrakty; unit kontrakt neprochází třídy
  reflexí, ale grepem všech PHP zdrojů (`modules/*/*/src`, `src/`) na
  `'type' => 'html'` — chytí i pomocné třídy mimo hierarchii viewerů.
  Nový `ViewerDetailLabelsCatalogTest` hlídá cs + en všech sekcí katalogů.
- `Notice.items` přijímá i `{label, text}` („popisek: text“) — validace
  a varování formuláře a zámek dokladu nepotřebují vlastní `<ul>`; u zámku
  se „zdroj — zpráva“ mění na „zdroj: zpráva“. `role` a `class` jsou
  explicitní propy (přepis role, stavová třída), ostatní atributy spread.
- `DocumentLockBanner` zůstává komponentou (lokalizace důvodů) s obalem,
  který nese vnější mezeru — `ViewerDetail` ani `FormEditor` se neměnily.
- Integrační kontrakt odhalil, že Přehled vieweru Období DPH
  (`economy.vat.reportPeriods`) posílal seznam bloků bez `composite`
  (frontend ho nekreslil) a opakoval název v titulku — opraveno (#140 D26).
- Neznámý `style` štítku kreslí `Badge` jako `neutral` (dřív pilulka bez
  barvy).
- Kandidáti mimo rozsah navíc k zadání: vlastní hlášky v `SettingsPage`,
  `ChangePasswordPanel`, `MailUploadModal`, `NewDatasourceModal`, `DsSetup`,
  `CompanyOverview`, `DocumentExchangePreview`; nedefinovaný token
  `--shpd-font-mono` s fallbackem v devíti komponentách (nový token je
  `--shpd-font-family-mono`).

## Pasti

- **Escapování.** S typem `html` zmizí i `htmlspecialchars` ve viewerech —
  nové typy nesou čistý text a frontend ho vkládá jako text, nikdy
  `{@html}`.
- **Tmavý vzhled** — rodina `alert-*` má v tmavém bloku jen `-bg` a
  `-text`; `-bar` se dědí ze světlého. Ověřit kontrast rámečku.
- **Role hlášky** — `role="alert"` se čte okamžitě; nedávat ji hláškám,
  které jsou na stránce od načtení a nejsou chyba (proto jen `danger`).
- **Testovací selektory** a texty, na které se odkazují testy
  (`data-testid`, `role`), zachovat.
- **px v CSS** zastaví pre-commit; odsazení 28 px seznamu hlášení
  zaniká s převodem na `Notice`.
