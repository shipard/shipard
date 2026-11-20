# Design system — prvek `content` místo `html` ve formulářích

**Stav:** hotovo — implementováno 2026-11-16 (D82–D87, #150); ručně ověřeno 2026-11-16 (body 1–3, 5–9 včetně telefonu a angličtiny `ok`; řádek Celkem v rekapitulaci a fakturační údaje v rozsahu karty strany schválené); bod 4 a řada bez pokladny jen podvrženými daty a testy

## Kontext / Cíl

Editační formuláře mají prvek `html` (`docs/edit-forms.md` §4.5,
`TabBuilder::html()`), který frontend vkládá přes `{@html}` ve
`FormElement.svelte`. Vzhled pak určuje backend — mimo tokeny,
typografické role a mimo kontrolu frontendu. Detail záznamu tentýž problém
vyřešil typovanými bloky (D30). Audit (komentář v #150) našel sedm výskytů
v pěti formulářích:

1. **Rekapitulace DPH** — tab formuláře dokladu (`DocsHeadsFormBase::buildRecapTab()`,
   `renderRecapHtml()`), tabulka `.vat-recap` z `base.css`.
2. **Fakturační údaje** — tab formuláře dokladu (`buildSnapshotsTab()`,
   `renderSnapshotsHtml()`, `renderPersonSnapshot()`), `.snapshots-container`
   a `.snapshot-block` z `base.css`.
3. **Souhrn AI funkce** — `modules/core/ai/src/AiFunctionSettingsForm.php`
   (`functionSummary()`).
4. **Pokladna** — `modules/docs/core/src/CashDeskFormBase.php`
   (`addCashDeskSection()`).
5. **Tři nápovědy** — `modules/economy/codebooks/src/VatRegistrationsForm.php`
   (2×), `modules/economy/vat/src/FilingsForm.php` (1×).

Texty v bodech 1 a 3–5 jsou česky natvrdo v PHP; formuláře nemají katalog
textů.

**Cíl:** formuláře neposílají HTML; jednoduchý obsah nese nový prvek
`content` s bloky detailu, rekapitulace a strany dokladu mají pojmenované
komponenty nad sdílenými prvky detailu; texty převáděných prvků jsou
z katalogu cs + en.

**Mimo rozsah:** názvy tabů, sekcí a oddělovačů formulářů („Rekapitulace
DPH“, „Fakturační údaje“, „Pokladna“, „Krácený nárok na odpočet“, …) —
#154; rekapitulace v detailu a review (beze změny, D86); vzhled tabulky
`DocTotals` v detailu (beze změny — jen se z ní vytáhne tabulka
rekapitulace).

## Návaznost

- **#150** — audit, D82–D87
- **#133** — D15, D16 (typografie), D30 (bloky obsahu detailu místo HTML),
  D40 (hláška), D44, D57 (tabulka v obalu `TableScroll`)
- **#139** — `DocTotals`, `PartyCard`, `documentParts.js` (D48, D50, D52, D55)
- **#149** — D75 (sekce formuláře na `Panel`)
- **#154** — vícejazyčnost backendových textů (navazuje na D87)

## Před implementací přečti

- `docs/edit-forms.md` §4 (typy prvků, hlavně §4.5 `html`, §4.6 `component`)
- `docs/design-system.md` §8 Hlášky a pruhy, §11 Typografie, §12 Stavební
  prvky obsahu (Karta strany, Popisek–hodnota, Tabulka, Součty, Hláška,
  Prázdný stav, Prostý text, Backend HTML se vzhledem neposílá)
- `docs/frontend.md` — kontrakt detailu (typy bloků)
- `src/Core/Form/FormElement.php`, `src/Core/Form/TabBuilder.php`,
  `src/Core/Form/TableForm.php`
- `src/Core/Viewer/Detail/DetailContent.php`, `src/Core/Viewer/TableViewer.php`
  (`labelText()`, `detailText()`, `catalogText()`)
- `frontend/src/components/form/FormElement.svelte`, `formComponents.js`
- `frontend/src/components/viewer/ViewerDetail.svelte` (snippet `renderContent`)
- `frontend/src/components/document/DocTotals.svelte`, `PartyCard.svelte`,
  `documentParts.js` (`detailParty`), `viewer/DocumentDetail.svelte`
  (rozložení stran)
- `modules/docs/core/src/DocsHeadsFormBase.php`, `DocsHeadsViewer.php`
  (`buildDetailParties()`, `buildDetailVatRecap()`)

## Rozhodnutí (potvrzená v #150)

- ✓ **D82** Prvek `html` z kontraktu formuláře zaniká (obdoba D30):
  `FormElement` ho nepřijme, `TabBuilder::html()` zaniká, frontend žádné
  `{@html}` ve formuláři. Z `base.css` zmizí `.vat-recap*`,
  `.snapshots-container`, `.snapshot-block`.
- ✓ **D83** Nový prvek formuláře `content` nese bloky obsahu detailu —
  stejný kontrakt (`DetailContent`) a stejné komponenty; vykreslování
  jednoduchých bloků se vytáhne z `ViewerDetail` do sdílené komponenty pro
  detail i formulář. Souhrn AI funkce = vlastnosti, pokladna = text, řada
  bez pokladny = hláška `warning`.
- ✓ **D84** Nový blok `hint` (nápověda): odstavec body šedě
  (`--shpd-color-text-secondary`), bez tučného zvýraznění.
- ✓ **D85** Fakturační údaje ve formuláři kreslí `PartyCard` jako detail —
  stejná data (zmrazené snapshoty) a stejný adaptér (`detailParty`); tab
  se ukazuje jako dnes, jen když snapshot existuje.
- ✓ **D86** Rekapitulace DPH ve formuláři je podrobnější než v detailu
  a review, ale kreslí se sdílenou tabulkou (rozšířená varianta tabulky
  z `DocTotals`): sloupce Sazba (název kódu DPH, u přenesení s předmětem
  plnění), %, Měna, Základ, DPH, Celkem; u cizí měny pod řádkem v měně
  dokladu řádek v domácí měně barvou textu, sazba a % sloučené přes oba;
  šedé jsou jen nesčítané hodnoty; pořadové číslo odpadá; prázdný stav
  šedým textem z katalogu. Detail a review beze změny.
- ✓ **D87** Texty formulářů z katalogu modulu `*.formTexts` (tvar jako
  texty viewerů), `TableForm::formText()` cs + en. Rozsah: texty
  převáděných prvků. Názvy tabů a sekcí zůstávají na #154.

## 1. Sdílené bloky obsahu a blok `hint` (D83, D84)

Frontend:

- vytáhni vykreslení jednoduchých bloků ze snippetu `renderContent`
  ve `ViewerDetail.svelte` do sdílené komponenty (návrh
  `components/content/ContentBlock.svelte` — jeden blok, `composite`
  rekurzivně): `properties`, `table`, `heading`, `composite`, `notice`,
  `badges`, `empty`, `text`, `code` a nový `hint`. Bloky vázané na viewer
  (`untrusted-html`, `attachment-grid`, `attachments`, `failure`,
  `proposal`, `document`) zůstávají ve `ViewerDetail`; `composite`
  s takovým blokem uvnitř musí dál fungovat (snippet / callback pro
  „ostatní“ typy, nebo `composite` zůstane ve `ViewerDetail` a sdílená
  komponenta dostane jen listy — zvol čistší a zapiš do Odchylek).
- detail se vizuálně nemění — žádný rozdíl na snímcích detailu.
- `hint` `{text}`: odstavec body, `--shpd-color-text-secondary`,
  `white-space: pre-wrap`, bez vnějšího okraje.

Backend: `hint` do `DetailContent::TYPES`; detail ho smí používat také.

## 2. Prvek formuláře `content` (D83)

- `FormElement`: typ `content` s polem `blocks` (seznam bloků podle
  `DetailContent`); povolené jen typy ze sdílené komponenty (krok 1) —
  jiný typ = výjimka v konstruktoru (vzor validace `html` dnes).
- `TabBuilder::content(array $blocks)` — mimo inline skupinu, jako
  `component()`; volitelně `hidden` jako ostatní prvky.
- frontend: větev `content` ve `FormElement.svelte` → sdílená komponenta,
  přes obě kolony jako dnes `html` / `component`; skrytí stejně.
- testy: `FormElementTest`, `TabBuilderTest` (platný `content`, neznámý
  typ bloku zamítnutý).

## 3. Katalog textů formulářů (D87)

- katalog modulu `config/formTexts.jsonc` → `<modul>.formTexts`, tvar
  `{"texts": {"<key>": {"name": "...", "name:cs": "...", "name:en": "..."}}}`
  — stejný jako sekce `texts` v `*.viewerDetailLabels`.
- `TableForm::formText(string $cfgItemId, string $key, string $englishFallback, array $params = [])`
  — jazyk formuláře (`FormRegistry::setLanguage()`), náhrada `{param}`,
  fallback jako `TableViewer::labelText()`. Sdílej implementaci
  (`catalogText()`) s viewerem, nekopíruj ji — pokud to znamená přesun do
  společné třídy, zapiš do Odchylek.
- katalogy vzniknou jen u modulů, které text potřebují (`docs.core`,
  `core.ai`, `economy.codebooks`, `economy.vat`); kompilace konfigurace
  po `ds-upgrade`.

## 4. Jednoduché převody

- **Nápovědy** (D84) — `VatRegistrationsForm` (Krácený nárok na odpočet,
  tab Podací údaje), `FilingsForm` (tab Hlavička): `content` s blokem
  `hint`, text z `formText()` bez `<strong>`.
- **Souhrn AI funkce** (D83) — `AiFunctionSettingsForm::functionSummary()`
  → blok `properties` s jednou skupinou bez nadpisu: Funkce (název),
  Popis, Úroveň, Model, Platforma (prázdné se vynechají jako v detailu);
  popisky z `formText()`.
- **Pokladna** (D83) — `CashDeskFormBase::addCashDeskSection()`: pokladna
  = blok `text` (`kód — název · měna`), řada bez pokladny = blok `notice`
  `warning` s textem z `formText()`.

## 5. Fakturační údaje (D85)

- formulářová komponenta `docParties` (`formComponents.js`): params
  `{supplier, customer}` — dekódované zmrazené snapshoty ve stejném tvaru,
  jaký detail posílá v bloku `document` (`supplier` / `customer`), bez
  `person_id` (formulář ukazuje zmrazené údaje, jméno není odkaz).
- frontend: dvě `PartyCard` přes adaptér `detailParty()` se stejnými
  nadpisy (Dodavatel / Odběratel) a rozložením jako strany v detailu
  (dva sloupce, pod sebou pod `30rem` — vzor `DocumentDetail`); strana bez
  snapshotu jako v detailu.
- `DocsHeadsFormBase::buildSnapshotsTab()` — `component('docParties', …)`;
  podmínka zobrazení tabu (`supplier_snapshot` nebo `customer_snapshot`)
  beze změny; `renderSnapshotsHtml()` a `renderPersonSnapshot()` zaniknou.

## 6. Rekapitulace DPH (D86)

Frontend:

- vytáhni tabulku rekapitulace z `DocTotals.svelte` do
  `components/document/VatRecapTable.svelte` (v `TableScroll`, styly
  tabulky §12); `DocTotals` ji používá v kompaktní podobě — **detail
  a review beze změny**, včetně snippetu `rate` z review.
- rozšířená podoba (prop `extended`): sloupce Sazba, %, Měna, Základ, DPH,
  Celkem; řádek s domácí měnou pod řádkem v měně dokladu, barvou textu,
  bez čáry mezi nimi; Sazba a % přes oba řádky (`rowspan`), zarovnané
  nahoru; nesčítaná hodnota `--shpd-color-text-secondary` — v tabulce jiná
  šedá není; čísla vpravo, `tabular-nums`, nezalomená; záhlaví z i18n
  frontendu (klíče jako `DocTotals`, nové jen pro %, Měna).
- formulářová komponenta `docVatRecap` (`formComponents.js`): params
  `{currency, homeCurrency, rows}`; řádek `{label, pct, base, tax, total,
  sum: {base, tax, total}, dom: {base, tax, total} | null}` — hodnoty
  hotové texty z backendu (`formatMoney`), `sum` booleany.

Backend (`DocsHeadsFormBase`):

- `renderRecapHtml()` → metoda, která z `resolveRecapForRender()` skládá
  params výše (štítek sazby `vatCodeLabelWithSubject()`, procento, příznaky
  `sum_base` / `sum_tax` / `sum_total`, domácí měna jen při cizí měně
  dokladu jako dnes).
- prázdná rekapitulace → místo komponenty `content` s blokem `empty`,
  text z `formText()`.

## 7. Zrušení `html` (D82)

- `FormElement::ALLOWED_TYPES` bez `html` (i validace `content` pro html),
  `TabBuilder::html()` pryč, větev `html` a CSS `.shpd-form-html*` ve
  `FormElement.svelte` pryč.
- `base.css`: `.vat-recap*`, `.snapshots-container` (včetně dočasného
  `@media` z D42), `.snapshot-block` pryč — ověř grepem, že je nic jiného
  nepoužívá.
- test: `FormElement` typ `html` odmítne; kontrola zdrojů (vzor
  `DetailContractTest`), že žádný formulář v `src/` a `modules/` nevolá
  `TabBuilder::html()` ani nestaví `FormElement` typu `html` (pozor:
  `MailComposer::html()` je tělo e-mailu, ne formulář — kontrola ho
  nesmí chytit).

## 8. Dokumentace

- `docs/edit-forms.md` §4.5 — místo `html` prvek `content` (kontrakt,
  povolené bloky, příklad), zmínka, že `html` zanikl (D82); §4.6 tabulka
  registrovaných komponent — `docParties`, `docVatRecap`; katalog textů
  formulářů a `formText()` (D87) tam, kde dokument popisuje texty a
  jazyk.
- `docs/design-system.md` §12 — blok `hint` (D84); Součty / Tabulka —
  rozšířená rekapitulace ve formuláři (D86); věta „Backend HTML se
  vzhledem neposílá“ rozšířit na formuláře (D82); §14 Soubory — nové
  komponenty.
- `docs/frontend.md` — kontrakt bloků: `hint`, sdílená komponenta.

## Commity

1. sdílená komponenta bloků + blok `hint` (detail beze změny)
2. prvek `content` + katalog `formTexts` a `formText()`
3. převod nápověd, souhrnu AI funkce a pokladny
4. `VatRecapTable`, `docVatRecap`, `docParties` a převod formuláře dokladu
5. zrušení `html` + `base.css` + testy
6. dokumentace + stav tasku + index

PHPUnit jen s úzkým `--filter` na dotčené testy; `npm run build`
a `npm test` ve `frontend/`; `ds-upgrade` volného dev zdroje dat kvůli
kompilaci katalogů.

## Ověření

V prohlížeči na volném dev zdroji dat, světlý i tmavý vzhled, šířka plná
i 360 px, čeština i angličtina rozhraní:

1. **Detail dokladu a review** — beze změny (snímky před / po), včetně
   rekapitulace a stran.
2. **Faktura v Kč** — formulář, tab Rekapitulace DPH: tabulka
   Sazba / % / Měna / Základ / DPH / Celkem bez řádků v domácí měně.
3. **Doklad v cizí měně** — pod každou sazbou řádek v Kč barvou textu,
   sazba a % sloučené; na 360 px se tabulka posune do strany v obalu.
4. **Doklad s přenesenou daňovou povinností** (je-li v datech, jinak
   podvržená data) — nesčítané hodnoty šedě, sazba s předmětem plnění.
5. **Nový doklad** — prázdná rekapitulace šedým textem z katalogu.
6. **Potvrzený doklad** — tab Fakturační údaje: dvě karty stran jako
   v detailu; na 360 px pod sebou; doklad bez snapshotu tab nemá.
7. **Registrace DPH** (Krácený nárok, tab Podací údaje) a **Podání DPH**
   (tab Hlavička) — nápověda šedě, bez tučného; anglicky v angličtině.
8. **Nastavení AI funkce** — souhrn jako seznam vlastností.
9. **Pokladní doklad** — pokladna jako text; řada bez pokladny (podvržená,
   nebo testem) hláška `warning`.
10. `grep` — žádné `->html(` ve formulářích, žádné `{@html}` ve
    `components/form/`, třídy `.vat-recap` / `.snapshot*` nikde.

## Hotovo když

- [x] sdílená komponenta bloků, blok `hint`, detail beze změny
- [x] prvek `content`, katalog `formTexts`, `formText()`
- [x] všech sedm výskytů převedených, `html` zrušený, `base.css` uklizený
- [x] testy (kontrakt formuláře, zdroje bez `html`) a ověření 1–10
      (automatizovaně v prohlížeči, viz Ověření provedené při implementaci;
      ruční průchod na dev serveru čeká)
- [x] dokumentace podle kroku 8
- [x] `**Stav:**` aktualizovaný, `scripts/tasks-index.py`

## Odchylky od zadání

- **`composite` kreslí sdílená komponenta** (krok 1): `ContentBlock.svelte`
  má `composite` u sebe (rekurze, mezery mezi bloky na jednom místě) a typy
  vázané na viewer předá snippetu `other` hostitele — i uvnitř `composite`.
  Formulář tak seznam `blocks` kreslí tímtéž kódem jako detail.
- **`catalogText()` ve společné třídě** (krok 3): čtení katalogu
  `{klíč: {name}}` je v `Core\I18n\CatalogText::resolve()`; privátní
  `TableViewer::catalogText()` na ni deleguje, `TableForm::formText()` ji
  volá také.
- **Řádek Celkem v rekapitulaci zůstal** (krok 6, rozhodnuto 2026-11-16):
  dnešní HTML končilo řádkem „Celkem (kurz)“ a u cizí měny součtem v domácí
  měně; audit ani D86 ho nezmiňovaly. Tabulka má patičku a params
  `docVatRecap` navíc `totals {base, tax, total, rate, dom}`. Kurz je
  s desetinnou čárkou jako částky („1 EUR = 24,47 CZK“, dřív s tečkou).
- **Tři nové i18n klíče záhlaví**, ne dva (krok 6): `document.recap.rate`
  (Sazba), `.pct`, `.currency` — Sazba klíč neměla a `document.meta.currency`
  patří údajům dokladu.
- **Sazba se nezaokrouhluje na celé procento** (krok 6): „12,5 %“ místo
  dřívějšího „13 %“ (`formatPercent()`).
- **Komponenty s výškou podle obsahu neroztahují sloupec** (kroky 5, 6):
  sloupec jen s `component` prvky je fill sloupec (roztáhne sekci na výšku
  tabu) — pro tabulku a karty to dávalo prázdný panel přes celý tab.
  `formComponents.js` má `flowFormComponents` (`docVatRecap`, `docParties`),
  `FormColumn` je z fill pravidla vyjímá.
- **Fakturační údaje ukazují totéž co detail** (krok 5, důsledek D85):
  zápis v rejstříku, BIC a „DIČ pro DPH“ registrace z dřívějšího HTML karta
  strany nekreslí; účet je číslo účtu, bez něj IBAN.
- **Text „předmět plnění“ z katalogu** (krok 6): `vatCodeLabelWithSubject()`
  bere vzor z `docs.core.formTexts` — projeví se i v buňce kódu DPH řádků
  dokladu (sub-tabulka), která text sdílí.
- **Test katalogů** navíc: `FormTextsCatalogTest` (tvar katalogů, shoda
  fallbacku s holým `name`, klíč bez čtenáře) — zadání ho nežádalo, hlídá
  pravidlo D87 stejně jako katalogy viewerů.
- **Třída tabulky rekapitulace** je `.shpd-recap-table`, ne
  `.shpd-vat-recap` — aby grep `vat-recap` z bodu 10 nic nenašel.

## Ověření provedené při implementaci

Playwright na volném zdroji dat (demo sada IT servis), uživatel `e2e`:

- **Bod 1** — 126 snímků obsahu detailu (faktury přijaté a vydané, pokladní
  doklady, majetek, došlá pošta, Spisovna, zakázky, osoby, podání DPH; taby
  detailu; 1500 a 360 px; světlý i tmavý vzhled) a 8 snímků součtů v review
  před / po: **pixelově shodné** po commitu 1, 4 i 5. Demo sada nemá
  upozornění (blok `code`) — CSS bloku se stěhovalo beze změny hodnot.
- **Body 2, 3, 5, 6, 7, 8, 9** — formuláře na skutečných záznamech, 1500
  a 360 px, světlý i tmavý vzhled, čeština i angličtina: žádný prvek
  `html`; rekapitulace v Kč bez řádků domácí měny, u dokladu v EUR řádky
  v Kč barvou textu, sloučená sazba a %, na 360 px posun tabulky v obalu
  (stránka neroluje do strany); nový doklad s prázdným stavem z katalogu;
  potvrzený doklad se dvěma kartami stran (na 360 px pod sebou), koncept
  tab Fakturační údaje nemá; nápovědy šedě bez tučného v obou jazycích;
  souhrn AI funkce jako vlastnosti; pokladna jako text.
- **Body 4 a 9 (řada bez pokladny)** — demo sada nemá tuzemské přenesení
  ani řadu bez pokladny: vzhled ověřen **podvrženou odpovědí**
  `/_ui/form/…/meta` (štítek s předmětem plnění, nesčítané hodnoty šedě,
  hláška `warning`), obsah params a bloků unit testy
  (`DocsHeadsFormTest`, `CashDocFormTest`).
- **Bod 10** — `->html(` v PHP zbývá jen `MailComposer` (tělo e-mailu),
  `{@html}` v `components/form/` není, `vat-recap` / `snapshot*` ve
  `frontend/src` nejsou.

Mimo rozsah, zaznamenáno při ověřování: formulář Nastavení AI funkce má na
360 px vstupy stlačené dlouhými popisky polí (stav před tímto taskem).

## Pasti

- **Detail se nesmí změnit** — vytažení bloků je refaktor; porovnej
  snímky detailu několika viewerů (doklad, majetek, upozornění s blokem
  `code`, Spisovna s `text`) před a po.
- **`composite` míchá sdílené a viewerové bloky** — rozhodnutí v kroku 1.
- **Kompilace konfigurace** — nový katalog se projeví až po `ds-upgrade`;
  bez něj `formText()` vrací anglický fallback.
- **`MailComposer::html()`** není formulářový prvek — kontrola zdrojů ho
  nesmí hlásit.
- **Hodnoty ve formuláři jsou hotové texty z backendu** jako v detailu —
  frontend částky neformátuje.
- **Žádný text česky natvrdo** v převedených místech — vše přes
  `formText()` nebo i18n frontendu.
- **px v CSS** zastaví pre-commit; nové `@container` jen s prahy §8.
