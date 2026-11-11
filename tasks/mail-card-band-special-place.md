# Task: Karta návrhu se zvláštním místem plnění jde do kontroly; varování v odpovědi MCP

**Stav:** naplánováno — rozhodnutí D17, D18 potvrzená 2026-11-11 (#89, doplněk tasku A)

**Issue:** #89 (drobnost z ověření tasku `exchange-received-foreign-vat.md`)

**Cíl:** Doklad od dodavatele mimo tuzemsko se zvláštním místem plnění
a bez vyčíslené daně vzniká z návrhu *Bez DPH* s varováním
`vat_special_place` (#89 D3). Varování je ale vidět jen v náhledu: karta
ve feedu smí být v pásmu *ready*, jednoklik doklad založí a odpověď MCP
nástroje `mail_draft_document` o varování neví. Rozhodnutí přitom stojí
na štítku dokladu, ne na údaji z faktury — má ho vidět člověk.

## Před implementací přečti

- `tasks/exchange-received-foreign-vat.md` — D3, tabulka „Kdy je doklad
  mimo české DPH“, upřesnění z implementace
- `docs/exchange-format.md` § 8.4 — *Přijatý doklad mimo české DPH*
- `docs/dashboard.md` — pásma karet; `tasks/value-rules.md` (strop pásma
  podle nálezů hodnot, #118 D15)

## Kontext — co je dnes

- Pásmo karty počítá `AnalysisConfidenceResolver::capBand()`
  (`modules/core/mail/src/`) jen z canonicalu: pokrytí řádků položkami
  a nálezy hodnot. Applier se pro kartu nevolá (feed sbírá desítky karet).
- O dokladu mimo české DPH rozhoduje `DocumentApplier::outOfScopeKind()`;
  štítek řádku čte přes `RowEnrichmentPipeline::rowContentTagOf()`
  a taxonomii `core.exchange.contentTags` (`crossBorderSupply: "special"`).
  Daň dodavatele pozná `SupplierVatPrices::carriesSupplierVat()` (čistá
  statická funkce).
- `MailDraftDocumentTool` (`modules/core/mail/src/Mcp/`) při úspěchu vrací
  jen `drafted: true` a odkaz na doklad.

## Rozhodnutí k designu (potvrzená)

Číslování navazuje na D1–D16 (#89).

- ✓ **D17 — strop pásma.** Karta návrhu, u kterého by applier ohlásil
  `vat_special_place`, nesmí být v pásmu *ready* — strop na *review*.
  Podmínka stropu smí být **širší** než rozhodnutí applieru (karta v kontrole
  navíc nevadí), nikdy užší:
  doklad nenese daň dodavatele **a** každý položkový řádek má štítek
  s `crossBorderSupply: "special"` **a** dodavatel není tuzemský podle
  kteréhokoli signálu canonicalu (`vat.place` je `intracom` /
  `thirdCountry`, nebo se prefix DIČ či země dodavatele liší od odběratele).
  Zahraniční DPH (`vat_foreign_tax`, D2) pásmo nemění — je to údaj
  z faktury, ne odhad.
- ✓ **D18 — MCP.** `mail_draft_document` při úspěchu vrací varování applieru
  (`warnings: [{path, message}]`, jen `severity: "warning"`), aby asistent
  mohl uživatele upozornit. Chování nástroje se jinak nemění.

## Co je potřeba udělat

Jeden commit — `feat(mail): karta návrhu se zvláštním místem plnění do kontroly; varování v mail_draft_document (#89 D17, D18)`:

1. **Jeden predikát „všechny položkové řádky mají štítek `special`“**
   v `core.exchange` (čistá funkce nad canonicalem a taxonomií štítků).
   `DocumentApplier::outOfScopeKind()` ho použije místo vlastní smyčky —
   žádná druhá kopie pravidla.
2. **`AnalysisConfidenceResolver::capBand()`** — třetí důvod stropu podle
   D17. Resolver dnes dostává jen databázi; taxonomii štítků mu předej
   (konfigurace je v místech volání k dispozici — feed, detail zprávy,
   náhled). Bez taxonomie strop nelze vyhodnotit — volající ji předat musí,
   žádný tichý fallback „bez stropu“.
3. **`MailDraftDocumentTool`** — `warnings` u úspěšného založení; ověř, že
   výsledek apply issues nese i při úspěchu, jinak je doplň do výsledku
   `MessageProposalApplier::apply()`.
4. **Testy:** `AnalysisConfidenceResolverTest` (strop platí; zahraniční DPH
   pásmo nemění; tuzemský dodavatel neplátce se štítkem `special` zůstává
   *ready*; jen část řádků `special` zůstává *ready*), test nástroje MCP,
   `DocumentApplierOutOfScopeTest` beze změny zelený.
5. **Dokumentace:** `docs/dashboard.md` (důvody stropu pásma),
   `docs/exchange-format.md` (odkaz u `vat_special_place`),
   `help/posta/kontrola-vytezeni.md` (faktura se zvláštním místem plnění jde
   vždy do kontroly); `python3 scripts/help-index.py`.
6. Z `tasks/exchange-received-foreign-vat.md` odstraň z upřesnění větu, že
   doklad se zvláštním místem plnění smí být v pásmu *ready*; `**Stav:**`
   tohoto tasku a `python3 scripts/tasks-index.py`.

## Pasti

- **Širší, ne užší.** Applier určuje místo plnění přes `VatPlaceDerivation`
  (registrace, členství v unii k datu). Strop tu logiku neopakuje — stačí
  mu hrubý signál „dodavatel není tuzemský“. Test proto ověř oběma směry:
  každý canonical, který v `DocumentApplierOutOfScopeTest` končí
  `specialPlace`, musí dostat strop.
- **Nájem od tuzemského neplátce** má štítek `special` a daň nenese — strop
  dostat nesmí, jinak půjdou do kontroly běžné měsíční faktury.
- **Volby uživatele** strop neřeší — kdo volil v náhledu, varování viděl.

## Hotovo když

- [ ] Karta návrhu podle D17 je v pásmu *review* (feed, detail zprávy,
      náhled); ostatní karty beze změny; testy
      `AnalysisConfidenceResolver|DocumentApplierOutOfScope` zelené.
- [ ] `mail_draft_document` vrací varování (D18); test nástroje zelený.
- [ ] Dokumentace a help aktualizované; `help-index.py` a `tasks-index.py`
      prošly.
- [ ] Ověření na dev zdroji: návrh se zvláštním místem plnění bez daně má
      kartu v kontrole, jednoklik není nabídnut.
