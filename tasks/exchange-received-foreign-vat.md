# Task: Přijatý doklad se zahraniční DPH a zvláštním místem plnění — doklad Bez DPH

**Stav:** částečně — commity 1/3–3/3 hotové 2026-11-11 (D1–D4, náhled, help, varianta korpusu 11); ověřeno na dev zdroji 2026-11-11 datovou sadou plátce (body 1, 5, 6; `dataset-verify` 6× `ok`), body 2–4 jen testy; zbývá ruční proklik náhledu (body 2–4); navazuje `mail-card-band-special-place.md` (D17, D18)

**Issue:** #89 (task A; task B je `vat-reverse-charge-subjects.md`)

**Cíl:** Návrh přijaté faktury od dodavatele mimo tuzemsko, který účtuje
vlastní (zahraniční) DPH nebo poskytuje službu se zvláštním místem plnění
(hotel, tankování, letenka, mýto v cizině), dnes končí chybou
`vat_code_unknown` a doklad se zakládá ručně. Nově vznikne celý
**Bez DPH**: řádky nesou ceny včetně daně dodavatele, kódy DPH ani
rekapitulaci doklad nemá. Stejný stav jde vynutit ručně volbou režimu
*Bez DPH* v náhledu.

Návaznost: roadmapa M2 (přijatá faktura z pošty bez ručního zakládání).
Navazuje na #86 (D7 — otevřené body), #87 (volby v náhledu) a #97 (převod
cen u neplátce). Na tasku B nezávisí.

## Před implementací přečti

- `docs/exchange-format.md` — § 8.4: *Přijatý doklad neplátce DPH*
  (převod cen D4 / D5 z #97), *Místo plnění přijatého dokladu*, *Odvození
  kódu DPH u přijatých dokladů*, *Druh plnění ze štítku řádku*
- `tasks/exchange-received-non-vat-payer.md` — převod cen, tabulka situací,
  Pasti
- `tasks/exchange-received-supply-kind.md` — D3 / D4: dovoz zboží a veto
  štítku `special`
- `tasks/exchange-preview-vat-choices.md` — piny v náhledu, D11 (ruční
  *Bez DPH* se samovyměřením)
- `docs/vat-calculation.md` § 2 — režim *Bez DPH*

## Kontext — co je dnes

- **Zahraniční DPH odmítá derivace.** `VatCodeDerivation::derive()`: místo
  mimo tuzemsko, `reverseCharge` není `true` a řádek má kladnou sazbu →
  `null` s důvodem „dodavatel mimo tuzemsko účtuje DPH (zahraniční DPH)“.
  Applier z toho dělá error `vat_code_unknown` a apply končí
  `validation_failed`.
- **Zvláštní místo plnění je veto.** Štítek řádku s `crossBorderSupply:
  "special"` (`core.exchange.contentTags` — ubytování, jízdné a letenky,
  stravování, nájem a služby k nemovitosti, mýto, parkování) vrací z derivace
  `null` i s druhem plnění z AI a i s `reverseCharge: true`. Štítek řádku
  drží `DocumentApplier::effectiveSupplyKinds()` (~ř. 2245, klíč `tagSupply`).
- **V náhledu se to vyřešit nedá.** Nabídka kódů (`_resolve.vatCodeOptions`)
  pro místo mimo tuzemsko obsahuje jen kódy samovyměření. Ruční režim
  *Bez DPH* se u řádků se samovyměřením tiše přepne zpět na *Ze základu*
  (`effectiveVatMode()`, ~ř. 3571; D11 z #87).
- **Převod cen existuje, ale jen pro neplátce.**
  `DocumentApplier::nonPayerGrossTotals()` (~ř. 3665) a predikát
  `carriesSupplierVat()` (~ř. 3608); `vatContext()` (~ř. 1966) nastavuje
  `nonPayer`, `effectiveVatMode()` pak vrací `none` se zdrojem `nonPayer`,
  `resolveRowVatCode()` (~ř. 2540) kódy neresolvuje, `resolveRecapSource()`
  (~ř. 2841) rekapitulaci nepřebírá, `transform()` (~ř. 1595) a
  `transformRows()` (~ř. 4053) ukládají ceny s daní a `price_calc_mode` 1.
- **Ručně to jde.** Doklad s **Režimem DPH** *Bez DPH* má řádky bez kódu
  DPH a účtování zaúčtuje plnou částku řádku — žádná změna v `docs.core`
  ani v účtování není potřeba.
- **Data:** na kopiích reálných dat se řádky s kódem a bez kódu na jednom
  dokladu prakticky nevyskytují (jednotky dokladů z desítek tisíc) — řádek
  bez kódu znamená doklad Bez DPH. Proto se nezavádí kód „nedaňový řádek“.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — bez `cz-000`.** Nedaňový řádek je `vat_code = null`. Doklad mimo
  české DPH vzniká celý Bez DPH (`vat_mode` 0).
- ✓ **D2 — zahraniční DPH.** Přijatý doklad (`selfParty: "customer"`) na
  zdroji s registrací DPH platnou k datu, mimo import, efektivní místo
  plnění mimo tuzemsko, `vat.reverseCharge` není `true` a doklad nese daň
  dodavatele (`carriesSupplierVat()`) → doklad je **mimo české DPH**:
  Bez DPH, řádky v cenách s daní dodavatele (převod D4 / D5 z #97), bez
  kódů DPH, bez rekapitulace. Info `vat_foreign_tax`.
- ✓ **D3 — zvláštní místo plnění.** Stejný doklad, který daň dodavatele
  nenese, ale **každý** položkový řádek má štítek s `crossBorderSupply:
  "special"` → také mimo české DPH (ceny beze změny), warning
  `vat_special_place`. Nahrazuje dnešní chybu. Volba kódu řádku nebo režimu
  v náhledu má přednost (viz tabulka).
- ✓ **D4 — ruční Bez DPH mimo tuzemsko platí.** Volba režimu `none`
  v náhledu (`_resolve.vat.mode.userAction = "useValue:none"`) u dokladu
  s efektivním místem mimo tuzemsko = doklad mimo české DPH, warning
  `vat_out_of_scope_user`. Mění D11 z #87 jen v tomto případě; v tuzemsku
  a u `none` deklarovaného AI (bez volby) zůstává tiché přepnutí na
  *Ze základu*.
- ✓ **D5 — mimo rozsah.** Smíšený doklad (`vat.reverseCharge: true` a zároveň
  řádky se zahraniční DPH; část řádků `special`, část ne) — chování jako
  dnes, řeší se ručně nebo volbou D4. Vrácení daně z jiného členského státu.
  Import (`applyOptions.importNumber`) beze změny — runner posílá řádky
  `EUCZ000` bez kódu už dnes.

### Kdy je doklad „mimo české DPH“

Vyhodnocuje se jednou na doklad ve `vatContext()` (až po konečném místě
plnění, tedy po pinu místa) a nese se v kontextu, např.
`outOfScope: null | "foreignVat" | "specialPlace" | "user"`:

| Situace (větev derivace, místo ≠ tuzemsko) | Výsledek |
|---|---|
| pin režimu `none` | `user` (D4) |
| pin režimu `fromBase` / `fromTotal`, nebo pin kódu (`useCode`) na kterémkoli řádku | není mimo DPH — chování jako dnes |
| bez pinů, `reverseCharge` ≠ `true`, doklad nese daň dodavatele | `foreignVat` (D2) |
| bez pinů, doklad daň nenese, všechny položkové řádky `special` | `specialPlace` (D3) |
| cokoli jiného | není mimo DPH — chování jako dnes |

Tuzemské místo plnění, neplátce, vystavený doklad a import se nemění.

Upřesnění při implementaci (2026-11-11):

- **Ponechaný kód ruší režim mimo DPH.** Řádek s platným kódem z canonicalu
  nebo z historie řádků, který je v souladu se signály, dnes projde se
  samovyměřením (veto `special` se kódu z historie netýká) — takový doklad
  zůstává, jak byl. Režim mimo DPH nahrazuje jen dřívější chybu.
- **`specialPlace` platí i s `vat.reverseCharge: true`** (tabulka výše
  podmínku nemá; veto štítku jde před příznak už dnes).
- **Blok `vatCode` řádku** u dokladu mimo DPH: `{status: "matched",
  matchedBy: "outOfScope"}` bez kódu — náhled na něm drží volbu kódu.
- **`vat_registration`** doklad mimo DPH nese (naše registrace) — formulář
  ji při přepnutí na *Bez DPH* jen skrývá, nemaže.
- **Volba Bez DPH má přednost i před volbou kódu řádku** (D4) — kód se
  pak ohlásí info `vat_pin_ignored`. „Automaticky“ v náhledu dál ukazuje,
  co by vyšlo bez volby (`autoOutOfScope` / `autoReverseCharge` v kontextu).
- **Korpus (3/3):** varianta 11 `eu-foreign-vat` (hotel v Německu, EUR,
  7 % + 19 %) je ve všech třech profilech bez přepisu — u plátce cestou
  dokladu mimo české DPH, u neplátce a spolku cestou #97; deník je u všech
  stejný (512100 / 321100). Očekávání plátce: `vat-reporting.jsonc` režim
  `none`, náklad z řádků s daní (`reporting.out_of_czech_vat`), výjimky
  věrnosti `ApplyFidelityCheck::EXCEPTIONS_OUT_OF_SCOPE` čtené z návrhu.
  Sady mají 22 / 18 / 18 zpráv; dřívější běhy evaluací neodpovídají
  aktuální sadě.
- **Snímky deníku** s variantou 11 (přírůstek jen na 512100 a 321100
  o součet obou dokladů) schválené člověkem 2026-11-11.
- **Jednoklik a MCP:** varování apply neblokuje, doklad vznikne jako
  koncept. Karta návrhu se zvláštním místem plnění má strop pásma na
  *review* a `mail_draft_document` varování vrací v odpovědi — doplněk
  `mail-card-band-special-place.md` (D17, D18).

### Co skončí na dokladu

Jako u ručně založeného dokladu Bez DPH: hlavička `vat_mode` 0, bez
rekapitulace; řádky `total_price` = cena s daní dodavatele (u `specialPlace`
a u `user` bez daně na dokladu cena beze změny), `price_calc_mode` 1, bez
slev, bez `vat_code` / `vat_pct`. `vat_place` hlavičky zůstává efektivní
místo plnění. `_resolve.vat.mode` vrací `value: "none"` se zdrojem
`outOfScope` a důvodem; `_resolve.computed` počítá výsledek stejnou cestou
jako u neplátce. Nabídka `_resolve.vatCodeOptions` zůstává — volbou kódu se
uživatel z režimu mimo DPH vrátí k samovyměření.

U dokladu mimo české DPH se nehlásí `vat_code_unknown`, `vat_code_derived`,
`vat_mode_derived`, `vat_mode_suspect` ani `recap_source_computed_fallback`.

## Kroky a commity

### Commit 1 — `feat(exchange): přijatý doklad se zahraniční DPH nebo zvláštním místem plnění vznikne Bez DPH (#89 D1–D3, 1/3)`

- Převod cen vytáhni z `DocumentApplier` do samostatné třídy v
  `modules/core/exchange/src/Document/` (čistá funkce nad canonicalem —
  cena řádku s daní D4, dorovnání na rekapitulaci D5). Neplátce i doklad
  mimo DPH volají totéž; chování neplátce se nesmí změnit (stávající testy
  neplátce beze změny zelené).
- `VatCodeDerivation::derive()`: zahraniční DPH a veto `special` dál vracejí
  `null`, ale s rozlišitelným druhem důvodu (např. klíč `kind` vedle
  `reason`), aby applier nemusel porovnávat texty. Derivace sama o dokladu
  nerozhoduje.
- `vatContext()`: vyhodnocení `outOfScope` podle tabulky (v tomto commitu
  bez pinu `none` — ten je commit 2). Při `outOfScope` se kódy řádků
  nerozhodují (prázdné `rows`), stejně jako u neplátce.
- `effectiveVatMode()`, `resolveRowVatCode()`, `resolveRecapSource()`,
  `transform()` / `transformRows()`, `appendVatModeIssue()` a výpočet
  náhledu: větev `outOfScope` vedle `nonPayer`. Kde to jde, sjednoť obě
  větve jednou podmínkou („doklad vznikne Bez DPH s převodem cen“), ne
  kopií kódu.
- Issues: info `vat_foreign_tax` („Dodavatel mimo tuzemsko účtuje vlastní
  DPH — doklad vznikne bez DPH a daň dodavatele je součástí cen řádků.“),
  warning `vat_special_place` („Služba se zvláštním místem plnění
  (ubytování, doprava osob, stravování, nemovitost, mýto, parkování) —
  doklad vznikne bez DPH. Pokud daň v ČR přiznat máš, vyber kód DPH
  u řádku.“). Cesta `vat.mode`.
- Testy: `VatCodeDerivationTest` (druh důvodu), nový
  `DocumentApplierOutOfScopeTest` (D2 s cenami bez daně i s daní, dorovnání
  na rekapitulaci, D3, tabulka situací včetně `reverseCharge: true` + řádky
  s daní = beze změny, import beze změny), `DocumentApplierPreviewComputedTest`.
- `docs/exchange-format.md`: nová podsekce *Přijatý doklad mimo české DPH*
  u § 8.4, nové kódy issues v tabulce issues, úprava textu derivace
  (zahraniční DPH a `special` už nejsou „mimo rozsah“).

### Commit 2 — `feat(mail): ruční Bez DPH u dokladu mimo tuzemsko platí; náhled (#89 D4, 2/3)`

- `vatContext()` / `effectiveVatMode()`: pin režimu `none` mimo tuzemsko →
  `outOfScope: "user"`; tiché přepnutí na *Ze základu* zůstává pro tuzemsko
  a pro `none` bez volby. Warning `vat_out_of_scope_user` („Doklad od
  dodavatele mimo tuzemsko vznikne bez DPH — daň se v ČR nepřizná.“).
- `DocumentApplierVatPinsTest`: úprava testů D11 (tuzemsko beze změny, mimo
  tuzemsko nové chování), pin kódu ruší `foreignVat` i `specialPlace`.
- Náhled (`frontend/src/components/exchange/DocumentExchangePreview.svelte`,
  `VatCodeDecisionPanel.svelte`): u dokladu mimo DPH řádky bez odznaku kódu
  (odznak „bez DPH“), hlavička ukazuje *Bez DPH* se zdrojem; výběr kódu
  u řádku zůstává dostupný. Texty přes `cs.js` / `en.js`, `npm run check:i18n`.
- Help: `help/co-dnes-nejde.md` (odstavec o mezích samovyměření — zahraniční
  DPH a zvláštní místo plnění už návrh zpracuje), `help/posta/kdyz-ai-cte-spatne.md`,
  `help/posta/kontrola-vytezeni.md` (co znamená *Bez DPH* u faktury ze
  zahraničí a jak se vrátit k samovyměření). Názvy ověř ve zdroji popisků;
  `python3 scripts/help-index.py`.

### Commit 3 — `test(corpus): přijatý doklad se zahraniční DPH; task hotový (#89 D16, 3/3)`

- Nová varianta korpusu podle `docs/test-corpus.md` § 14: dodavatel z EU,
  cizí měna, `vatTreatment: "standard"` se zahraničními sazbami, štítek
  `travel.accommodation` (dvě sazby dodavatele). Očekávání: doklad Bez DPH,
  řádky v cenách s daní, součet = částka k úhradě. Matice § 6, přegenerované
  `tests/datasets/`.
- Snímky deníku datových sad schvaluje člověk (`docs/test-corpus.md` § 17).
  Když build korpusu na stroji nejde (chybí nástroje), commit nedělej,
  zapiš to do hlavičky tasku a nech stav *částečně*.
- `**Stav:**` tasku, `python3 scripts/tasks-index.py`.

## Ověření na dev zdroji

Na volném zdroji dat (třída `free`) s registrací DPH a s analyzovanou poštou:

1. Faktura za ubytování od dodavatele z EU s vyčíslenou zahraniční DPH:
   náhled bez chyby, režim *Bez DPH*, řádky v cenách s daní, součet =
   částka k úhradě na dokladu; jednoklik projde.
2. Faktura se zvláštním místem plnění bez vyčíslené daně: *Bez DPH*
   s varováním; volba kódu u řádku vrátí samovyměření a přepočítanou
   rekapitulaci.
3. Služba z EU bez štítku `special`, odvozená jako samovyměření: ruční
   *Bez DPH* v hlavičce platí, doklad vznikne bez kódů; volba *Automaticky*
   vrátí samovyměření.
4. Tuzemský doklad s přenesením daňové povinnosti: ruční *Bez DPH* se dál
   přepne zpět.
5. Služba z EU se samovyměřením a doklad neplátce: beze změny.
6. Vzniklý doklad Bez DPH: zaúčtování plné částky do nákladů, žádný řádek
   v přiznání ani v kontrolním hlášení.

## Mimo rozsah

- Smíšené doklady a vrácení zahraniční daně (D5).
- Řádkový formulář dokladu s DPH dál vyžaduje kód DPH — řádek bez kódu
  na dokladu s DPH jde jen z importu.
- Vystavené doklady.

## Pasti

- **Neplátce se nesmí pohnout.** Vytažení převodu cen je refaktoring —
  nejdřív přesun se zelenými testy neplátce, teprve pak nová větev.
- **`vat_registration` hlavičky.** U neplátce se neukládá. Ověř, co dělá
  formulář dokladu u plátce při ručním přepnutí na *Bez DPH*, a drž totéž —
  doklad z návrhu se nesmí lišit od ručně založeného.
- **Pořadí v kontextu.** `outOfScope` se vyhodnocuje až po pinu místa
  plnění; změna místa na tuzemsko režim mimo DPH ruší.
- **Jeden zdroj pravdy pro piny.** Volby čte jen `vatPins()`; nabídka kódů
  zůstává i u dokladu mimo DPH, jinak by uživatel neměl cestu zpět.
- **Varování a jednoklik.** `vat_special_place` je warning — ověř, že
  jednoklik (režim *safe*) i MCP `mail_draft_document` doklad vystaví
  a varování je vidět na kartě / v náhledu.
- **Cizí měna.** Převod cen běží v měně dokladu; kurz a domácí měnu dál
  počítá doklad.

## Hotovo když

- [x] Doklad se zahraniční DPH a doklad se zvláštním místem plnění vznikne
      z návrhu Bez DPH podle D2 / D3; testy
      `DocumentApplier|VatCodeDerivation` zelené, neplátce beze změny.
- [x] Ruční *Bez DPH* mimo tuzemsko platí (D4), tuzemsko beze změny; testy
      `DocumentApplierVatPins|MessageProposalApplier|AnalysisController`
      zelené; build frontendu a i18n parita.
- [x] `docs/exchange-format.md` a help aktualizované; `help-index.py`
      a `tasks-index.py` prošly.
- [x] Varianta korpusu se zahraniční DPH (nebo zapsaný důvod, proč ne).
- [ ] Ověření na dev zdroji (body 1–6) a výsledek do #89 — body 1, 5, 6
      ověřené 2026-11-11 datovou sadou plátce (dva doklady varianty 11:
      Bez DPH, řádky v cenách s daní = částka k úhradě, bez rekapitulace,
      512100 / 321100, nic na 343), výsledek v #89; zbývá ruční proklik
      náhledu (body 2–4).
