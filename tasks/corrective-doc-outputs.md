# Task: Opravný daňový doklad — task C: tisk a ISDOC

**Stav:** hotovo — 2026-11-11 (3 commity; unit sada zelená, integrační testy tisku na ukázkovém zdroji plátce, tisk a ISDOC ověřené nad potvrzeným opravným dokladem na DEMO zdroji; zbývá revize formulací `sk` / `de`, nálezy z ověření níže a uzavření #92)

**Issue:** #92

**Cíl:** Opravný doklad se tiskne s titulkem „Opravný daňový doklad“
(u neplátce „Opravný doklad“), s číslem původního dokladu a důvodem
opravy, a jako ISDOC vychází s typem dobropis nebo vrubopis a odkazem na
původní doklad.

Navazuje na `tasks/corrective-doc-core.md` (sloupce) a
`tasks/corrective-doc-exchange.md` (blok `correction` v canonical — ISDOC
se generuje z canonical).

## Před implementací přečti

- `docs/prints.md` — § 4 kontrakt dat tisku, tabulka variant titulku
  (~ř. 338–356, varianty `correctiveVatPayer` / `correctiveNonVatPayer`
  jsou rezervované), katalogy textů a jazyky tisku, testy tisků
- `docs/einvoice.md` — § 1 rozsah V1 (typy 1 a 4, jen Kč), třídy,
  validace vstupu, testy proti XSD
- `modules/docs/core/src/Prints/TitleVariantResolver.php`,
  `DocTitleContext.php`, `DocPrintContext.php` (`titleContext()`, ~ř. 255)
- `modules/docs/core/src/Prints/Blocks/DocDocumentBlock.php` — blok
  `document`
- `modules/docs/invoicesOut/prints/invoice/` — `page.html.twig`,
  `messages.jsonc`; společné texty `modules/docs/core/prints/_layout/messages.jsonc`
- `modules/core/exchange/src/Isdoc/IsdocWriter.php` — `DOC_TYPE_MAP`
  (~ř. 41), `check()` a `checkRows()` / `checkTotals()` (validace vstupu),
  `writeHeader()` (~ř. 409)
- `modules/core/exchange/src/Isdoc/IsdocReader.php` — čtení typu 2
  (znaménka částek)
- `modules/core/exchange/schemas/isdoc/isdoc-invoice-6.0.1.xsd` —
  `OriginalDocumentReferences`, `OriginalDocumentReferenceType` (~ř. 536),
  pořadí prvků hlavičky

## Rozhodnutí k designu (potvrzená)

- ✓ **D8** — Tisk: titulek „Opravný daňový doklad“ (plátce) a „Opravný
  doklad“ (neplátce), řádek s číslem původního dokladu a důvodem opravy.
- ✓ **D9** — ISDOC: typ 2 (dobropis) nebo 3 (vrubopis) podle znaménka,
  odkaz na původní doklad. Platí omezení generátoru z první verze.

Ostatní rozhodnutí viz tasky A a B.

**Upřesnění při psaní tasku** (implementační, v duchu D8 a D9):

- **U1 — Varianta titulku.** `DocTitleContext` dostane příznak opravného
  dokladu; `invno` s příznakem → `correctiveVatPayer` (plátce) nebo
  `correctiveNonVatPayer` (neplátce). Rozhoduje příznak, ne znaménko —
  záporná faktura bez příznaku se dál tiskne jako faktura.
- **U2 — Texty.** Katalog tisku faktury vydané (návrh, cizí jazyky ověřit
  proti slovníku v `docs/prints.md`):

  | klíč | cs | en | sk | de |
  |---|---|---|---|---|
  | `title.correctiveVatPayer` | Opravný daňový doklad | Corrective tax document | Opravná faktúra | Rechnungskorrektur |
  | `title.correctiveNonVatPayer` | Opravný doklad | Corrective document | Opravný doklad | Rechnungskorrektur |
  | `fileName.correctiveVatPayer`, `fileName.correctiveNonVatPayer` | opravny-doklad | corrective-document | opravny-doklad | rechnungskorrektur |

  Společné popisky (`_layout`): `label.correctedDoc` („Původní doklad“ /
  „Original document“ / „Pôvodný doklad“ / „Ursprünglicher Beleg“)
  a `label.correctionReason` („Důvod opravy“ / „Reason for correction“ /
  „Dôvod opravy“ / „Korrekturgrund“).
- **U3 — Data tisku.** Blok `document` nese `correction`: `null` u běžného
  dokladu, jinak `{originalNumber, reason}` (obě hodnoty text nebo
  `null`). Šablona faktury tiskne pod identifikací dokladu řádek
  „Původní doklad: …“ (jen s číslem) a „Důvod opravy: …“ (jen s textem).
  Datum plnění původního dokladu se netiskne.
- **U4 — ISDOC typ.** Canonical s blokem `correction` a typem
  `invoiceIssued`: celková částka < 0 → `DocumentType` 2, jinak 3. Bez
  bloku platí dnešní mapa.
- **U5 — ISDOC odkaz a důvod.** `OriginalDocumentReferences` s jedním
  `OriginalDocumentReference` (`ID` = číslo původního dokladu); bez čísla
  (souhrnný doklad) se kolekce nepíše. Důvod opravy jde na začátek `Note`
  („Důvod opravy: …“), stávající poznámka za něj.
- **U6 — ISDOC znaménka.** Částky se píší se znaménkem dokladu. Validace
  vstupu (`checkRows`, `checkTotals`, `checkRecap`) musí záporné hodnoty
  u opravného dokladu propustit. Zrcadlí se čtení: co writer zapíše,
  `IsdocReader` přečte se stejnými částkami.

## Co je potřeba udělat

### Commit 1 — tisk (D8, U1–U3)

1. `DocTitleContext`, `DocPrintContext::titleContext()`,
   `TitleVariantResolver` podle U1.
2. Texty podle U2, blok `document` a šablona podle U3.
3. Testy: `TitleVariantResolver` (plátce, neplátce, záporná faktura bez
   příznaku, zálohová faktura s příznakem v datech → `proforma`); blok
   `document` (s číslem i důvodem, jen důvod, běžný doklad `null`);
   kontraktní a snapshot testy tisku faktury podle zvyklostí
   `docs/prints.md` (nová fixtura opravného dokladu ve všech jazycích
   tisku).
4. `docs/prints.md`: tabulka variant (dva nové řádky, poznámka
   o rezervaci pryč), kontrakt dat (`document.correction`), poznámka
   u vratky na prodejce — titulek vratky plátce #92 neřeší (D11).

### Commit 2 — ISDOC (D9, U4–U6)

1. `IsdocWriter`: typ podle U4, odkaz a poznámka podle U5, validace
   podle U6. Pořadí prvků podle XSD.
2. Testy: dobropis (typ 2, záporné částky, odkaz), vrubopis (typ 3),
   souhrnný doklad bez čísla (bez kolekce odkazů, důvod v `Note`), běžná
   faktura beze změny (snapshot); validace proti XSD 6.0.1 a 6.0.2;
   round-trip writer → `IsdocReader` zachová částky a typ.
3. `docs/einvoice.md`: § 1 (typy 2 a 3 podporované pro fakturu vydanou
   s blokem `correction`), mapování, omezení (jen Kč zůstává).

### Commit 3 — nápověda a uzavření

1. `help/faktury-vydane/opravny-doklad.md`: jak vypadá tisk a e-faktura
   opravného dokladu; `co-dnes-nejde.md` uvést do souladu;
   `python3 scripts/help-index.py`.
2. `CLAUDE.md` → sekce tisků a e-faktury: jedna věta o opravném dokladu.
3. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj)

- Tisk opravného dokladu plátce a neplátce (zdroj bez registrace DPH):
  titulek, číslo původního dokladu, důvod opravy, záporné částky
  v řádcích, rekapitulaci i součtu; název souboru PDF.
- Tisk v angličtině (partner s jazykem dokumentů `en`).
- Souhrnný opravný doklad bez čísla původního dokladu: jen důvod.
- ISDOC z detailu: typ 2, odkaz; soubor projde validací a přijetím
  zpět do pošty jako přijatý doklad se stejnými částkami.
- Běžná faktura a zálohová faktura se tisknou a exportují beze změny.

## Mimo rozsah

- Přijaté opravné doklady a jejich čtení z ISDOC / AI (D11).
- Titulek vratky na prodejce u plátce (D11).
- Cizí měna v ISDOC (omezení V1 trvá).
- Text „K úhradě“ u záporné částky a QR platba na dobropisu — pokud se
  při ověření ukáže, že QR kód nebo popisek u záporné částky nedává
  smysl, zapiš nález do tasku a neřeš ho tady.

## Pasti

- **Titulek řídí příznak, ne znaménko.** Importované záporné faktury bez
  příznaku se nesmí začít tisknout jako opravné doklady.
- **Katalog tisku se překládá do čtyř jazyků** — chybějící klíč v jednom
  jazyce shodí kontraktní test katalogu.
- **Pořadí prvků ISDOC** je dané XSD (`xs:sequence`) — kolekce odkazů
  nepatří na konec hlavičky; ověř v XSD, test proti XSD to chytí.
- **Validace vstupu writeru** dnes může záporné množství nebo částku
  odmítat — uvolnit jen pro canonical s blokem `correction`, ať se běžné
  faktury validují jako dosud.
- **Snapshot testy tisku** — nové fixtury přidat, existující nepřepisovat
  (běžná faktura se nesmí změnit ani o bajt).

## Hotovo když

- [x] Opravný doklad se tiskne se správným titulkem, číslem původního
      dokladu a důvodem opravy ve všech jazycích tisku.
- [x] ISDOC opravného dokladu má typ 2 nebo 3, odkaz na původní doklad
      a projde validací proti XSD.
- [x] Běžná faktura, zálohová faktura a záporná faktura bez příznaku
      beze změny.
- [x] Dokumentace a nápověda aktuální.
- [x] Testy zelené (cíleně, pak celá sada).
- [x] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
- [ ] Po ověření zavřít #92 a přesunout D11 do samostatného issue, pokud
      ho chceme sledovat (rozhoduje člověk).

## Odchylky od zadání (2026-11-11)

- **Stávající fixtury tisku dostaly klíč `correction: null`** (osm
  fixtur dokladů, jeden řádek) — U3 chce klíč i u běžného dokladu
  a kontraktní test porovnává `data` striktně. „Ani o bajt“ se hlídalo na
  výstupu: HTML všech stávajících fixtur ve čtyřech jazycích a ISDOC všech
  stávajících fixtur je před změnou a po ní bajtově shodné. Kontace klíč
  nenese (`describe()` se neměnil).
- **Řádky opravy tiskne společný layout** (`doc-base`, partial
  `_partials/correction.html.twig`), ne šablona faktury — ta nic
  nepřepisuje a popisky jsou podle U2 ve společném katalogu.
- **Čtečka ISDOC se neměnila.** Dobropis čte jako `creditNote` se stejnými
  částkami (round-trip test); vrubopis (typ 3) odmítá, odkaz na původní
  doklad a zápornou slevu nečte — přijaté opravné doklady jsou D11.
  Vrubopis se proto ověřuje jen proti XSD.
- **Kontrola vstupu writeru záporné hodnoty neodmítala** — nebylo co
  uvolňovat. Jediná úprava: sleva částkou se u opravného dokladu bere i se
  záporným znaménkem (znaménko řádku).
- **Nápověda k tisku šla do commitu 1**, ne 3 — stránka má být ve stejném
  commitu jako funkce, kterou popisuje. E-fakturu nápověda nepopisuje:
  ISDOC k vydaným dokladům z aplikace vyrobit nejde (fáze 2 #119),
  `co-dnes-nejde.md` to říká dál.

## Nálezy z ověření (neřešeno tady)

1. **Odběratel bez adresy na tisku dokladu bez `partner_address`.**
   Opravný doklad založený akcí *Vystavit opravný doklad* a potvrzený má
   ve snapshotu odběratele jen název, IČO, DIČ a kontakt — adresa chybí
   a na PDF není. Příčina je obecná: applier `partner_address` neplní
   a `PersonSnapshotBuilder` adresu bez id nedohledá (vlastní strana
   náhradu sídlem má, partner ne). Týká se každého dokladu potvrzeného bez
   vybrané adresy partnera, tedy i faktur z periodické fakturace. Daňový
   doklad má sídlo odběratele nést — chce vlastní rozhodnutí (náhrada
   sídlem partnera ve snapshotu, nebo doplnění adresy v applieru).
2. **„K úhradě“ u záporné částky.** Dobropis tiskne „K úhradě −12 100,00
   CZK“ a splatnost; QR platba na něm správně není. Popisek u záporné
   částky nedává smysl (správně „K vrácení“ / „Dobropisováno“) — podle
   oddílu Mimo rozsah jen zapsáno.
3. **ISDOC nad skutečným dokladem jde jen ručně.** Canonical z exportu
   nenese vlastní stranu ani číslo dokladu; pro ověření se doplnily ze
   snapshotu tisku. Adaptér `PrintData → canonical` a vystavení z aplikace
   jsou fáze 2 #119 — krok „ISDOC z detailu“ a „přijetí zpět do pošty“
   z ověření proto neexistuje (čtení zpět ověřeno přímo `IsdocReader`).
4. **Neplátce** je ověřený integračním testem (doklad bez registrace DPH
   na zdroji plátce) a testy šablony; ukázkový zdroj bez registrace DPH
   s vydanými fakturami na dev serveru není.

## Formulace k revizi (`sk`, `de`)

Titulky mají právní váhu — k revizi kolegy stejně jako
`tasks/prints-languages.md` → Formulace k revizi:

| klíč | cs | sk | de |
|---|---|---|---|
| `title.correctiveVatPayer` | Opravný daňový doklad | Opravná faktúra | Rechnungskorrektur |
| `title.correctiveNonVatPayer` | Opravný doklad | Opravný doklad | Rechnungskorrektur |
| `label.correctedDoc` | Původní doklad | Pôvodný doklad | Ursprünglicher Beleg |
| `label.correctionReason` | Důvod opravy | Dôvod opravy | Korrekturgrund |
