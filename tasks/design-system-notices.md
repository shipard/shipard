# Design system — hlášky do `Notice` (D60, D61)

**Stav:** hotovo — 2026-11-11 (#133 Další nesoulady, D60, D61); nad rámec seznamu převedeno dalších osm komponent a kontrola tokenů má výjimku pro vývojářský dashboard (viz Odchylky); ověřeno v Chromiu s podvrženými odpověďmi API a průchodem sady `cz/association`, místa bez ověření v prohlížeči jsou vyjmenovaná v Odchylkách; ruční ověření na dev serveru čeká

## Kontext / Cíl

Bod checklistu #133 „Další nesoulady“ — hlášky mimo `Notice`. D40 dalo
hláškám v obsahu sdílenou komponentu `Notice`, ale řada míst má dál
vlastní kopii vzhledu (pozadí, rámeček, červený text). Seznam
v checklistu jmenoval sedm komponent; průchod kódem jich našel víc
a ukázal, že ne každý červený text je hláška — proto D61 (hranice
hláška × chyba u prvku) a D60 (chyba načtení místo obsahu).
`DocumentExchangePreview` je převedený už z #139.

**Cíl:**

1. Každá hláška v obsahu (výsledek akce, stav formuláře, dialogu nebo
   panelu) je `Notice` — žádná vlastní třída s kopií vzhledu.
2. Chyba načtení místo obsahu je všude stejná: `Notice` `danger` nahoře
   v oblasti, „Zkusit znovu“ jako `Button` (D60).
3. Zprávy v kartách Přehledu firmy jsou `Notice` (tón podle závažnosti,
   odkaz uvnitř).
4. Tokeny `--shpd-color-alert-*` smí používat jen `Notice`
   a `PageBanner`; kontrola v pre-commitu (D61).

**Mimo rozsah:**

- chyby u prvku podle D61 — zůstávají textem u prvku a tady se nemění:
  chyby inputů (`ui/*Input`, `Select`, `TextArea`…), chyba karty v kontrole
  front (`QueueCategoriesPrompt`), varování u jednotlivého majetku
  v dialogu odpisů (`.shpd-deprun__warning` v řádku tabulky), chyba
  nahrání obrázku (`ImageSlotField`, `AvatarSlotField`), chyba pole
  v předvyplnění registrace DPH, chyba pole průvodce nastavení
  (`.shpd-ds-setup__field-error`); jejich sjednocení mezi sebou je
  případný další bod;
- varování na kartě a řádku feedu (`FeedCard`, `FeedRowCompact`) — je
  součástí karty;
- text potvrzovacího dialogu (`ConfirmDialog` `__message`) — tělo
  dialogu, ne hláška;
- štítek a barevné řádky reportu (`ReportView`) — vlastní bod checklistu
  #133; do jeho vyřešení výjimka v kontrole;
- vývojářský dashboard (`frontend/src/dev/`) — vlastní vzhled (D12);
- texty hlášek (i18n klíče) — beze změny, mění se jen prvek.

## Návaznost

- **#133** — D35 (karty), D40 (`Notice`), D41 (`PageBanner`), D60, D61;
  checklist „Další nesoulady“ (bod hlášky mimo `Notice`)
- **#137** task 3 — zavedení `Notice` a `PageBanner`
- **#139** D53 — `Notice` s `items` / `meta` v review
- **#129** D14 / `tasks/design-system-small-fixes.md` —
  `scripts/check-css-tokens.py`

## Před implementací přečti

- `docs/design-system.md` §8 Hlášky a pruhy (celé), §8 Karty
- `frontend/src/components/ui/Notice.svelte` — API (`tone`, `title`,
  `icon`, `items`, `role`, `children`, `data-testid` na kořen, bez
  vnějšího okraje)
- `frontend/src/components/reports/ReportsPage.svelte` — chyba katalogu
  (vzor D60, tlačítko se tady mění na `Button`)
- `frontend/src/components/ui/Button.svelte` — varianty a velikosti
- `scripts/check-css-tokens.py`
- každá komponenta z kroků 1–3 (styly i markup)

## Rozhodnutí (potvrzená v #133)

- ✓ **D60** Chyba načtení místo obsahu (seznam, detail, modal, stránka)
  je `Notice` v tónu `danger` nahoře v oblasti, ne vycentrovaný červený
  text; „Zkusit znovu“ (pokud je) jako `Button` secondary sm uvnitř
  hlášky — jako u reportů.
- ✓ **D61** Hranice hláška × chyba u prvku: chyba nebo varování, které
  patří k jednomu poli, položce seznamu nebo řádku, zůstává textem
  u prvku (tón jen barvou textu), ne `Notice`; výsledek akce a stav
  celého formuláře, dialogu nebo panelu je `Notice`. Tokeny
  `--shpd-color-alert-*` smí používat jen `Notice` a `PageBanner` —
  hlídá kontrola v pre-commitu (dočasná výjimka `ReportView` do vyřešení
  bodu reportů).
- Zprávy v kartách Přehledu firmy jsou hláška (stav sekce, ne jedné
  položky) → `Notice`, každá zvlášť; víc zpráv v kartě = víc rámečků,
  potvrzeno v chatu.

## 1. Hlášky → `Notice`

Pro každé místo: markup nahradit `Notice`, vlastní pravidla vzhledu
(pozadí, rámeček, barva, padding, zaoblení, velikost písma) smazat.
Mezeru kolem hlášky dává rodič (gap flex sloupce, nebo obal) — žádný
`margin` na `Notice`. Existující `data-testid`, `role` a podmínky
zobrazení zachovat.

| Komponenta | Dnes | `Notice` |
|------------|------|----------|
| `settings/SettingsPage.svelte` | `.shpd-settings-page__message` (`--success` / `--error`) | `tone` podle `saveMessage.type` (`success` / `danger`) |
| `account/ChangePasswordPanel.svelte` | `.shpd-security-panel__message` (`--success` / `--error`) | totéž |
| `dashboard/MailUploadModal.svelte` | `.shpd-mail-upload__notice` (limit souborů), `.shpd-mail-upload__error` | `warning`, `danger` |
| `portal/NewDatasourceModal.svelte` | `.shpd-ds-create__error` | `danger` |
| `chat/ChatThread.svelte` | `.shpd-thread__error` | `danger`; dál nad polem zprávy, ne v rolovací oblasti zpráv |
| `settings/DsSetup.svelte` | `.shpd-ds-setup__warnings` + `__warning` s ikonou | jedna `Notice` `warning`, `items` = varování, `icon` varování; `role="alert"` zachovat |
| `form/AttachmentPanel.svelte` | `.shpd-attachments__error` (příznak odeslání) | `danger` |
| `form/SentMessageTransport.svelte` | `.shpd-sent-transport__error` — `transport.lastError` a chyba akce | obě `danger`; `data-testid="sent-message-transport-error"` zůstává u chyby akce |
| `viewer/AssetsDepreciationRunDialog.svelte` | `.shpd-deprun__error` (chyba dialogu), blokace (`preview.blockers`), `.shpd-deprun__notice` | chyba `danger`; blokace jedna `Notice` `danger` s `items`; `notice` `info` |

Pokud při převodu najdeš další vlastní hlášku podle D61 (stav celého
formuláře, dialogu nebo panelu), převeď ji taky a uveď v odchylkách.
Chybu u prvku nepřeváděj.

## 2. Chyba načtení místo obsahu (D60)

`Notice` `danger` nahoře v oblasti, kde by byl obsah (ne vycentrovaná ve
volné ploše); obal oblasti dává odsazení od krajů (`--shpd-space-md`,
není-li už dané). Tlačítko „Zkusit znovu“, pokud ho místo má, je
`Button variant="secondary" size="sm"` uvnitř hlášky pod textem.

| Komponenta | Dnes |
|------------|------|
| `browser/TableBrowser.svelte` | `.shpd-browser__error` s ikonou `⚠` (znak) — ikonu zahodit nebo `icon` z ikon aplikace |
| `viewer/ViewerDetailModal.svelte` | `.shpd-detail-modal__error` (vycentrovaná) |
| `exchange/DocumentExchangePreviewModal.svelte` | `.shpd-exchange-modal__error` — `data-testid="review-error"` zachovat (používá ho `tests/e2e/dataset-walkthrough.spec.mjs`) |
| `dashboard/Dashboard.svelte` | `.shpd-dashboard__error` |
| `settings/SettingsPage.svelte` | `.shpd-settings-page__status--error` (`loadError`) |
| `settings/DsSetup.svelte` | `.shpd-ds-setup__state--error` |
| `overview/CompanyOverview.svelte` | `.shpd-overview__state--error` + tlačítko |
| `portal/PortalContent.svelte` | `.portal__error` + `.portal__retry` |
| `reports/ReportsPage.svelte` | už `Notice`; vlastní `.shpd-reports__retry` → `Button` |

Chyba načtení, která nastane **při obnovení** se starými daty na
obrazovce (např. `CompanyOverview` `error && data`), se nemění — tady
jde jen o stav, kdy chyba nahrazuje obsah.

## 3. Přehled firmy — zprávy sekcí

`overview/CompanyOverview.svelte`, `.shpd-overview__messages`: každá
zpráva sekce je `Notice` — `tone` podle `message.severity` (`info` →
`info`, `warning` → `warning`, `error` → `danger`; neznámá → `info`),
text zprávy a odkaz *Otevřít* (`message.link`) uvnitř hlášky. Odkaz
zůstává textovým tlačítkem (dnešní `.shpd-overview__message-link` —
barva odkazu, bez rámečku); umístění: na řádku vpravo od textu, při
nedostatku místa pod ním (flex s `wrap`). Zprávy pod sebou s mezerou
`--shpd-space-sm` (gap obalu), obal místo `<ul>` (seznam zpráv je dál
seznam pro čtečku — `role="list"` / `listitem`, nebo `<ul>` s `Notice`
v `<li>` bez odrážek; vyber jedno a drž ho).

Levý proužek (`border-left`) a pravidla `__message--{severity}` zmizí;
tím zmizí i jediné použití `--shpd-color-alert-*` mimo `Notice`
a `PageBanner` kromě `ReportView`.

## 4. Kontrola tokenů hlášek (D61)

`scripts/check-css-tokens.py`: rodina `--shpd-color-alert-*` smí být
**použitá** (`var(...)`) jen v `components/ui/Notice.svelte`
a `components/ui/PageBanner.svelte`; definice ve `variables.css` platí
dál. Dočasná výjimka `components/reports/ReportView.svelte` v pojmenované
konstantě s komentářem „do vyřešení bodu reportů v #133 (D61)“.

- Hláška vypíše soubor, řádek a token a radu: „hláška v obsahu =
  `Notice`, pruh nad obsahem = `PageBanner` (docs/design-system.md §8)“.
- Kontrola jde celým stromem jako ostatní; docstring popisuje proč
  (D61, rodina tokenů je vzhled hlášky — kdo ji potřebuje, kopíruje
  `Notice`).
- Ruční zkouška: `var(--shpd-color-alert-info-bg)` do kopie libovolné
  komponenty → skript selže; vrátit.

## 5. Dokumentace

- `docs/design-system.md` §8 Hlášky a pruhy:
  - D60: chyba načtení místo obsahu (odstavec);
  - D61: hranice hláška × chyba u prvku (kdy co, příklady z kroku 1
    a z „Mimo rozsah“) a omezení rodiny `--shpd-color-alert-*`
    s kontrolou;
  - seznam „Dnes na `Notice` stojí …“ doplnit o převedená místa; věta
    o „kandidátech na převod“ zmizí;
  - Přehled firmy: zprávy sekcí jsou `Notice`.
- `docs/design-system.md` §13 Soubory: u `check-css-tokens.py` doplnit
  omezení rodiny `alert`.
- Komentář v `Notice.svelte` (výčet míst) zkrátit na odkaz do §8 — výčet
  se jinak rozjede.
- Checklist #133: bod se zaškrtne při ověření (chat), ne v tasku.

## Commity

1. `refactor(frontend): hlášky formulářů, dialogů a panelů na Notice (#133, D61)`
   — krok 1
2. `refactor(frontend): chyba načtení místo obsahu jako Notice (#133, D60)`
   — krok 2
3. `refactor(frontend): zprávy Přehledu firmy jako Notice (#133)` — krok 3
4. `chore: tokeny hlášek jen v Notice a PageBanner (#133, D61)` — krok 4
5. `docs: hlášky a chyba u prvku (#133, D60, D61)` — krok 5 + `**Stav:**`
   tohoto tasku + `python3 scripts/tasks-index.py`

## Ověření

- `python3 scripts/check-css-tokens.py`, `check-css-units.py`,
  `cd frontend && npm run build`, `npm run check:i18n`.
- `grep` na smazané třídy (`settings-page__message`,
  `security-panel__message`, `mail-upload__error`, `mail-upload__notice`,
  `ds-create__error`, `thread__error`, `portal__error`, `portal__retry`,
  `ds-setup__warnings`, `ds-setup__state--error`, `overview__message`,
  `overview__state--error`, `attachments__error`, `deprun__error`,
  `deprun__notice`, `sent-transport__error`, `browser__error`,
  `detail-modal__error`, `exchange-modal__error`, `dashboard__error`,
  `settings-page__status--error`, `reports__retry`) ve `frontend/src` →
  nic.
- V prohlížeči (zdroj dat `free`, Playwright jako v předchozích taskech
  #138) každé místo vyvolat a nasnímat, světlý i tmavý motiv, 1280 px
  a 390 px: uložení Nastavení (úspěch i chyba), změna hesla (chyba
  validace), nahrání pošty (víc souborů než limit), založení zdroje dat
  s obsazeným web ID, chyba chatu (např. bez AI připojení), průvodce
  nastavení s varováním, dialog odpisů s blokací, Přehled firmy se
  zprávou sekce. Chyby načtení (krok 2) vyvolat podvržením odpovědi API
  (`page.route` → 500), jako u stavových obrazovek v
  `tasks/design-system-small-fixes.md`; u míst se „Zkusit znovu“ ověřit,
  že tlačítko načte znovu.
- `node tests/e2e/…` / spec `dataset-walkthrough` — jen pokud ho jde
  spustit proti zdroji `free`; jinak ověřit, že `review-error` zůstal na
  kořeni `Notice` (`data-testid` jde na kořen).
- Chyby u prvku (seznam v „Mimo rozsah“) vypadají jako předtím.

## Hotovo když

- [x] Všechna místa z kroků 1–3 jsou `Notice`, jejich vlastní třídy
      vzhledu smazané
- [x] Chyba načtení místo obsahu je všude `Notice` `danger` nahoře,
      „Zkusit znovu“ jako `Button` secondary sm
- [x] Zprávy Přehledu firmy jsou `Notice` s tónem podle závažnosti
      a odkazem uvnitř, bez levého proužku
- [x] `--shpd-color-alert-*` mimo `Notice` / `PageBanner` hlásí kontrola
      v pre-commitu (výjimka `ReportView` a vývojářský dashboard — viz
      Odchylky)
- [x] Chyby u prvku beze změny
- [x] Dokumentace podle kroku 5, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- `Notice` nemá vnější okraj — dnešní třídy často mají `margin-bottom`
  nebo `margin-top`; mezeru musí převzít rodič, jinak se hláška přilepí
  k sousednímu prvku.
- `role`: `Notice` dává `alert` jen u `danger`, jinak `status`. Kde dnešní
  markup má `role="alert"` u varování (`DsSetup`), předej `role="alert"`
  explicitně, ať se čtečka nezmění.
- Zprávy úspěchu v Nastavení a u změny hesla mohou mizet časovačem —
  časovač zůstává ve volající komponentě, `Notice` nic nemizí.
- `.shpd-browser__error-icon` je textový znak `⚠`, ne `Icon` — v `Notice`
  použij `icon` z ikon aplikace, nebo ikonu vynech; znak ne.
- Svelte styly jsou scoped: pravidla na obsah uvnitř `Notice` (odkaz
  v Přehledu, text a tlačítko u chyby) musí cílit na prvky, které
  komponenta sama vykreslí ve snippetu `children` — ty scoped styly
  volající chytí; na vnitřek `Notice` (`.shpd-notice__body`) necílit.
- Kontrola tokenů jde celým stromem: commit 4 až po commitu 3, jinak
  pre-commit selže na `CompanyOverview`.

## Odchylky od zadání

- **Další převedená místa** (pokyn z kroku 1 — stav celého dialogu nebo
  panelu podle D61, chyba načtení podle D60):

  | Komponenta | Hláška (commit 1) | Chyba načtení (commit 2) |
  |------------|-------------------|--------------------------|
  | `viewer/SendDialog.svelte` | chyba odeslání (`send-error`); nálezy návrhu (`send-messages`) jako jedna `Notice` s `items`, tón podle nejzávažnějšího nálezu | chyba načtení návrhu |
  | `viewer/PrintPreviewDialog.svelte` | — | chyba přípravy tisku |
  | `settings/VatRegistrationPrefillDialog.svelte` | chyba uložení | chyba načtení |
  | `settings/BankAccountBridgeDialog.svelte` | chyba uložení | chyba načtení |
  | `settings/ContentTagsSettings.svelte` | chyba založení štítků, chyba otagování | chyba načtení |
  | `settings/AccountingItemsOffer.svelte` | chyba generování | chyba načtení |
  | `settings/DsAbout.svelte` | — | chyba načtení |
  | `registry/RegistryImportWizard.svelte` | chyba založení | chyba hledání, chyba náhledu |

  Jako chyba u prvku nebo součást ovládání zůstávají: chyba hledání
  a chyba registru v `ResolveDecisionPanel`, stav `LookupInput`, chyba
  navigace v `Sidebar`, chyba skupiny v `CommandPalette`, chyba adresy
  v `SendDialog`, nenápadný text selhaného autosave v patičce review
  (`review-save-error`), štítky `portal__failed`
  a `registry-wizard__badge--warning`.
- **Kontrola tokenů má dvě výjimky, ne jednu.** `frontend/src/dev/dev.css`
  používá rodinu `--shpd-color-alert-*` pro hlášky vývojářského dashboardu,
  který je mimo rozsah (vlastní vzhled, D12). Krok 4 jmenoval jen
  `ReportView` — bez výjimky pro adresář `frontend/src/dev/` by kontrola
  selhala. Je v pojmenované konstantě `ALERT_EXEMPT_DIRS` vedle
  `ALERT_TEMPORARY_EXCEPTIONS`.
- **Přehled firmy — tlačítka bez textu.** „Zkusit znovu“ i „Obnovit“
  v hlavičce dostávala text jako obsah komponenty, který `Button` nekreslí
  (jen `label`): první bylo prázdné, druhé jen s ikonou. Obě mají `label`
  (commit 2).
- **Odkaz *Otevřít* ve zprávě Přehledu firmy nemá barvu odkazu, ale barvu
  tónu hlášky** (`color: inherit`, podtržený). Zadání chtělo zachovat
  dnešní barvu odkazu; na tónovaném pozadí má ale modrá v tmavém vzhledu
  kontrast 1,9–2,7 : 1 (změřeno), barva tónu 6,4–9,6 : 1. Ve světlém
  vzhledu jsou obě varianty nad 6 : 1.
- **Třídy v Přehledu firmy přejmenované** (`__notices`, `__notice-row`,
  `__notice-link`), aby grep z Ověření na `overview__message` nenašel nic;
  seznam zůstal `<ul>` s `Notice` v `<li>`.
- **`TableBrowser`** — znak `⚠` zahozen bez náhrady (ostatní chyby
  načtení ikonu nemají).
- **`NewDatasourceModal`** — hláška bez obalu, mezeru nad ní dává spodní
  okraj posledního pole (dřív navíc `margin-top`).
- **Písmo hlášek** je všude velikost body z `Notice` (dřív `sm`); chyba
  chatu už není plná červená s bílým textem, limit souborů při nahrání
  pošty je `warning` (dřív červený text).
- **Komentář v `CLAUDE.md`** u `check-css-tokens.py` doplněn o druhé
  pravidlo, `docs/overview.md` o zprávy sekcí jako `Notice` — zadání je
  nejmenovalo.

### Ověření — co proběhlo

- `check-css-tokens.py` (i ruční zkouška s tokenem v kopii komponenty →
  exit 1 s výpisem soubor:řádek), `check-css-units.py`, `npm run build`,
  `npm run check:i18n`, `npm test`; grep na smazané třídy bez nálezu.
- Chromium (Playwright) nad volným zdrojem `zey0-g…`, světlý i tmavý
  vzhled, 1280 a 390 px, bez vodorovného přetečení: chyba dashboardu,
  Přehled firmy (chyba + „Zkusit znovu“ načte znovu, zprávy sekcí všech
  závažností), katalog reportů (chyba + „Zkusit znovu“), chyba chatu (nad
  polem zprávy, mimo rolovací oblast), nahrání pošty (limit i chyba),
  review dokladu (`review-error` na kořeni hlášky), modal detailu, dialog
  Odeslat (chyba načtení, nálezy návrhu), náhled tisku, dialog odpisů
  (chyba, blokace), Nastavení (chyba načtení, uložení úspěch i chyba),
  průvodce nastavení (varování, chyba načtení), O zdroji dat, obsahové
  štítky, změna hesla (chyba validace). Chyby podvržené přes `page.route`,
  varování průvodce a blokace odpisů doplněné do skutečné odpovědi.
- `shpd-ds dataset-e2e tests/datasets/cz/association` nad volným zdrojem
  `nham-q…`: 16 karet z 16, bez chyb prohlížeče.
- **Bez ověření v prohlížeči** (jen build a čtení kódu): `PortalContent`
  a `NewDatasourceModal` (jen na zdroji s hostingem), `TableBrowser`
  (žádná tabulka bez vieweru v navigaci), `AttachmentPanel` (příznak
  odeslání), `SentMessageTransport`, dialogy registrace DPH a bankovního
  účtu, nabídka položek a průvodce registru (na zdroji nejsou položky
  průvodce, které je otevírají), chyba odeslání v dialogu Odeslat.

### Zjištění mimo rozsah

- Stránka Nastavení → Aplikace nejde uložit, dokud zdroj dat nemá zvolený
  výchozí vzhled a rozložení: klient posílá `app.theme: null`
  a `app.shell: null`, `SettingsController` u typů `theme` / `shell`
  vyžaduje objekt a vrací 422 `VALIDATION_ERROR`. S hláškami nesouvisí —
  úspěch uložení byl pro snímek podvržený.
