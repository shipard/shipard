# Task: Automatické zaokrouhlení částky podle způsobu úhrady

**Stav:** naplánováno — zadání 2026-11-10 (D1–D8 zamčené v #73)

**Issue:** #73

**Cíl:** Prodejní doklad si způsob zaokrouhlení celkové částky určuje sám
podle způsobu úhrady, měny a země zdroje dat: hotovost a dobírka se
zaokrouhlí podle pravidel hotovosti (v Kč na koruny), ostatní úhrady na
haléře. Uživatel může automatiku na dokladu vypnout a mód nastavit ručně;
na neobvyklou kombinaci ho doklad upozorní.

Priorita: věcná správnost (`docs/roadmap.md` → Pravidlo prioritizace,
bod 1). Formulář dnes dává každému novému dokladu Zaokrouhlení částky
„Matematicky na 1“ bez ohledu na způsob úhrady, takže ručně pořízená
faktura hrazená převodem vyjde zaokrouhlená na koruny.

## Před implementací přečti

- `docs/vat-calculation.md` § 6 (zaokrouhlení celkové částky) a § 4
- `tasks/doc-rounding-modes.md` — kódy módů, `RoundingModes`, rozbor
  hotovostního zaokrouhlení v EU (#63), poznámka P5 (§ 36 odst. 5 ZDPH)
- `docs/exchange-format.md` — `totals`, odvození `total_rounding_mode`
- `docs/edit-forms.md` kap. 23 (polymorfní formuláře dokladů), recalculate
- `modules/docs/core/src/RoundingModes.php`
- `modules/docs/core/src/DocDocument.php` — `validate()` (~ř. 111,
  varování přes `addWarning` viz `validateDeclaredRecap()`),
  `computeAmounts()` (~ř. 531), `applyTotalRounding()` (~ř. 1511)
- `modules/docs/core/src/DocTypes.php` — vzor čtení příznaku typu dokladu
  (`isTaxDocument()`)
- `modules/docs/core/src/DocsHeadsFormBase.php` —
  `applyNewRecordDefaults()` (~ř. 220), `applyClientDefaults()` (~ř. 579,
  dnešní výchozí mód 1), sekce se zaokrouhlením (~ř. 738), `recalculate()`
  (~ř. 1256), vzor zaškrtávátka `partner_balance_manual` (~ř. 1408)
- `modules/docs/core/src/IssuedInvoiceFormBase.php` — `buildSettingsTab()`
  (~ř. 166), `ReceivedInvoiceFormBase.php` (~ř. 154),
  `modules/docs/cashRegister/src/CashRegisterForm.php` (~ř. 151),
  `modules/docs/cashDocs/src/CashDocForm.php` (~ř. 179),
  `CashDeskFormBase.php`
- `modules/docs/core/config/paymentMethods.jsonc`, `docTypes.jsonc`
- `modules/core/exchange/src/Document/DocumentApplier.php` — `transform()`
  (~ř. 1713), `deriveTotalRoundingMode()` (~ř. 3613);
  `Export/DocumentExporter.php` (~ř. 356);
  `schemas/shpd.docs.document.v1.jsonc` (~ř. 197)
- `modules/economy/workOrders/src/Invoicing/InvoiceBuilder.php` —
  generovaná faktura neposílá `totals` (zůstává tak)

## Kontext

**Starý Shipard** měl na hlavičce `automaticRound` (Ne / Podle způsobu
úhrady) pro faktury vydané, zálohové faktury vydané, prodejky a pokladní
doklady. Při zapnutí nastavil zaokrouhlení na koruny pro hotovost,
dobírku, pokladní lístek, šek a poštovní poukázku, jinak na haléře, a pole
se způsobem zaokrouhlení bylo jen ke čtení. Měnu neřešil. Používali ho
prakticky všichni.

**Nový Shipard** má jen samotné módy (`RoundingModes`, včetně
„Matematicky na 0,05“) a odvození módu z deklarované částky v applieru
(import, pošta, ISDOC). Pravidlo „způsob úhrady + měna + země → mód“
neexistuje. Původní chyba z #73 (haléře na 211 u importovaných faktur
hrazených hotově) je vyřešená na straně importu a tento task se jí
netýká.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1** — Sloupec `total_rounding_auto` na `docs_core_heads`. Výchozí
  hodnota sloupce 0, existující doklady se nemění.
- ✓ **D2** — Automatika platí pro `invno`, `invpo`, `cashreg` a příjmový
  `cash`. Nový doklad těchto typů ji má zapnutou; nastavení zdroje dat se
  nezavádí.
- ✓ **D3** — Ostatní doklady automatiku nemají a nový doklad z formuláře
  má výchozí mód Na haléře.
- ✓ **D4** — Pravidlo běží v dokumentu, ne ve formuláři. Formulář má mód
  při zapnuté automatice jen ke čtení a přepočítá ho při změně způsobu
  úhrady nebo měny.
- ✓ **D5** — Hotovostní způsoby úhrady určuje příznak v
  `docs.core.paymentMethods`: Hotovost a Dobírka.
- ✓ **D6** — Mód hotovosti podle země zdroje dat a měny dokladu
  (konfigurace ve `world`): CZ + CZK matematicky na 1; SK, FI, NL, BE, IE,
  IT, EE, LT + EUR matematicky na 0,05 (rozbor v #63); jinak na haléře.
  Nehotovostní úhrada vždy na haléře.
- ✓ **D7** — Varování při vypnuté automatice na prodejním dokladu:
  hotovostní úhrada s jiným módem než D6; nehotovostní úhrada se
  zaokrouhlením (§ 36 odst. 5 ZDPH).
- ✓ **D8** — Výměnný formát: doklad s deklarovanou celkovou částkou má
  automatiku vypnutou a mód odvozený z částky jako dnes; bez deklarované
  částky ji má zapnutou.

**Upřesnění při psaní tasku** (implementační, v duchu D1–D8):

- **U1 — Sloupec.** `total_rounding_auto`, `boolean`, `default: 0`,
  `nullable: false`, skupina `rounding`, název „Automatické zaokrouhlení“
  / „Automatic rounding“, v definici před `total_rounding_mode`.
- **U2 — Konfigurace hotovosti.** cfgItem `world.base.cashRounding`
  (`modules/world/base/config/cashRounding.jsonc`), klíč = měna malými
  písmeny, hodnota krok a země, kde platí:

  ```jsonc
  {
      "czk": { "step": 1,    "countries": ["cz"] },
      "eur": { "step": 0.05, "countries": ["sk", "fi", "nl", "be", "ie", "it", "ee", "lt"] }
  }
  ```

  `world` nezná kódy módů `docs.core`, proto nese krok. Krok na mód
  převádí `docs.core` (1 → `MATH_UNIT`, 0,05 → `MATH_FIVE_CENT`); test
  hlídá, že každý krok z konfigurace má mód v `RoundingModes::TABLE`.
- **U3 — Příznaky v číselnících.** `docs.core.paymentMethods`:
  `"cashRounding": true` u 0 (Hotovost) a 3 (Dobírka). `docTypes.jsonc`:
  `"total_rounding_auto": true` u `invno`, `invpo`, `cashreg`, `cash`.
  Čtení jedním místem v `DocTypes` (vzor `isTaxDocument()`), žádné
  `=== 'invno'` v kódu. U `cash` platí automatika jen pro příjem
  (`cash_dir` 1).
- **U4 — Čistá logika.** Nová třída v `docs.core` (pracovně
  `TotalRoundingAuto`) bez databáze:
  - `applies(config, docType, cashDir): bool` — typ má automatiku (U3);
  - `modeFor(config, paymentMethod, currency, country): ?int` — hotovostní
    způsob úhrady a pravidlo pro (měna, země) → mód z U2; hotovostní
    způsob bez pravidla → `ON_CENT`; nehotovostní → `ON_CENT`; bez
    konfigurace → `null` (nic se nepřepisuje).
- **U5 — Dokument.** V `DocDocument::computeAmounts()` před
  `applyTotalRounding()` (výpočet sdílí uložení i náhled applieru):
  je-li `total_rounding_auto` zapnuté a typ automatiku má, přepíše se
  `total_rounding_mode` výsledkem `modeFor()` (měna = `doc_currency`,
  země = `DataSourceConfig::getCountry()`); `null` mód nemění. U typu bez
  automatiky se `total_rounding_auto` vynuluje.
- **U6 — Výchozí hodnota.** Zapnutou automatiku novému dokladu nastavuje
  formulář (U7) a applier (U9). `DocDocument` chybějící klíč nedoplňuje —
  platí výchozí hodnota sloupce 0, takže jiné cesty vzniku a existující
  testy se nemění.
- **U7 — Formulář.** Zaškrtávátko `total_rounding_auto`
  (`triggers: 'reload'`) před selectem `total_rounding_mode` ve všech
  formulářích typů z D2; select je při zapnuté automatice `readOnly`.
  `recalculate()` po změně `payment_method`, `doc_currency`,
  `total_rounding_auto` (u pokladního dokladu i směru) dopočítá mód
  stejnou třídou (U4), aby uživatel viděl výsledek před uložením. Po
  vypnutí automatiky zůstává poslední mód a jde upravit. Formuláře bez
  automatiky (přijaté doklady, výdajový pokladní doklad, účetní doklad)
  pole neukazují. Výchozí hodnoty nového dokladu: typy z D2 automatika 1
  a mód podle U4, ostatní automatika 0 a mód 0 (dnes 1).
- **U8 — Varování (D7).** V `DocDocument::validate()` přes `addWarning`
  na sloupec `total_rounding_mode`, jen typy z D2, jen při vypnuté
  automatice, mimo import mód:
  - `cash_rounding_mismatch` — hotovostní způsob úhrady a mód jiný, než
    dává `modeFor()`; text jmenuje očekávaný mód názvem z číselníku;
  - `non_cash_rounding` — nehotovostní způsob úhrady, mód jiný než
    `ON_CENT` a doklad s DPH (`vat_mode` ≠ 0); text: zaokrouhlení mimo
    základ daně je možné jen u hotovostní platby.
- **U9 — Výměnný formát.** Nové volitelné pole
  `totals.totalRoundingAuto` (`true` / `false` / `null`). Explicitní
  hodnota vyhrává; bez ní platí D8: typ z D2 bez `totals.totalAmount` →
  zapnuto, jinak vypnuto a mód z `deriveTotalRoundingMode()` jako dnes.
  Při zapnuté automatice applier `total_rounding_mode` neposílá (určí ho
  dokument). `DocumentExporter` pole vyváží, aby datová sada po
  dump → seed zachovala stav. Import ze starého Shipardu pole neposílá.

## Co je potřeba udělat

### Commit 1 — konfigurace a čistá logika (D5, D6, U2–U4)

1. `world.base`: `config/cashRounding.jsonc` + registrace cfgItem
   v `module.jsonc`.
2. `docs.core`: příznak `cashRounding` v `paymentMethods.jsonc`, příznak
   `total_rounding_auto` v `docTypes.jsonc`, čtení v `DocTypes`.
3. Třída z U4 + unit testy: CZ/CZK hotově → 1; CZ/CZK dobírka → 1;
   CZ/CZK převodem, kartou, zápočtem, bránou → 0; SK/EUR hotově → 5;
   CZ/EUR hotově → 0; DE/EUR hotově → 0; neznámá měna → 0; bez
   konfigurace → `null`; `applies()` pro všechny typy dokladů a oba směry
   pokladního dokladu.
4. Test shody kroků z konfigurace s `RoundingModes::TABLE` (vzor
   `RoundingModesTest`).

### Commit 2 — sloupec, dokument, varování (D1, D4, D7, U1, U5, U6, U8)

1. Sloupec podle U1 v `docs_core_heads.jsonc`.
2. `DocDocument::computeAmounts()` podle U5.
3. `DocDocument::validate()` podle U8.
4. Unit testy (`DocDocumentTotalsTest` nebo nový soubor): zapnutá
   automatika přepíše ručně poslaný mód; změna způsobu úhrady mění mód
   i `total_amount` / `total_rounding`; vypnutá automatika mód nechává;
   typ bez automatiky příznak vynuluje; bez konfigurace se mód nemění;
   obě varování včetně případů, kdy padnout nemají (zapnutá automatika,
   import mód, doklad bez DPH u `non_cash_rounding`).
5. Integrační test přes `TableGateway` (ukázkový zdroj): faktura vydaná
   hotově s automatikou uložená gateway má mód 1 a zaokrouhlenou částku —
   ověřuje, že produkční cesta má konfiguraci i zemi.

### Commit 3 — formuláře (D2, D3, D4, U7)

1. Zaškrtávátko a `readOnly` select ve formulářích faktury vydané
   a zálohové faktury vydané (`IssuedInvoiceFormBase`), prodejky,
   pokladního dokladu (jen příjem) a v obecném `DocsHeadsFormBase`.
2. Výchozí hodnoty nového dokladu podle U7 na **obou** místech
   (`applyNewRecordDefaults()` i `applyClientDefaults()` — viz komentář
   u `applyClientDefaults()`).
3. `recalculate()` podle U7.
4. Testy: `DocsHeadsFormNewRecordDefaultsTest` (výchozí hodnoty per typ),
   `DocsHeadsFormTest` (přepočet po změně způsobu úhrady, měny a příznaku;
   `readOnly`; skryté pole u typů bez automatiky).

### Commit 4 — výměnný formát (D8, U9)

1. Schéma `shpd.docs.document.v1.jsonc`: `totals.totalRoundingAuto`.
2. `DocumentApplier::transform()` podle U9; náhled ukazuje mód
   a zaokrouhlení, které skončí na dokladu.
3. `DocumentExporter`: export pole.
4. Testy applieru: faktura ze zakázky (bez `totals`) → automatika zapnutá
   a mód podle způsobu úhrady; doklad s deklarovanou částkou → vypnutá
   a mód odvozený jako dnes; explicitní `true` s deklarovanou částkou;
   přijatý doklad → vždy vypnutá. `DocumentExporterTest`: pole ve výstupu.
   Dump → seed datové sady zachová příznak.
5. `docs/exchange-format.md`: pole a pravidlo D8.

### Commit 5 — dokumentace, nápověda, uzavření

1. `docs/vat-calculation.md` § 6: automatika, konfigurace hotovosti,
   varování. `docs/edit-forms.md` a `docs/docs-mvp.md`: zmínky
   o `total_rounding_mode` uvést do souladu.
2. `help/`: stránky faktur vydaných, `pokladna/prodejka.md`
   a `pokladna/pokladni-doklad.md` — jak automatika funguje a jak ji
   vypnout. Názvy polí ověřit ve zdroji, pak `python3 scripts/help-index.py`.
3. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj)

- Po `ds-upgrade` nemá žádný existující doklad zapnutou automatiku
  a žádnému se nezměnil mód ani částka.
- Nová faktura vydaná: převodem → mód Na haléře, pole jen ke čtení;
  přepnutí na Hotovost → Matematicky na 1 a zaokrouhlená částka; přepnutí
  na Dobírku → totéž; zpět na Kartou → Na haléře.
- Faktura v EUR hrazená hotově na českém zdroji → Na haléře.
- Vypnutá automatika, hotovost, mód Na haléře → varování, doklad jde
  uložit i potvrdit.
- Prodejka hotově a kartou; příjmový pokladní doklad; výdajový pokladní
  doklad pole nemá.
- Nová faktura přijatá z formuláře má mód Na haléře.
- Koncept z periodické fakturace má automatiku zapnutou.

## Mimo rozsah

- Přenos `automaticRound` ze starého Shipardu — importované doklady mají
  automatiku vypnutou a mód odvozený z částky (D8).
- Zahrnutí zaokrouhlení do základu daně u bezhotovostní úhrady — jen
  varování (D7).
- Kroky hotovosti, pro které není mód (DK 0,50, HU 5 Ft) — přidají se
  s prvním zdrojem dat, který je potřebuje.
- Nastavení zdroje dat pro výchozí hodnotu automatiky (D2).

## Pasti

- **`DocDocument` běží i bez konfigurace** (unit testy). Proto `modeFor()`
  vrací `null` a mód se nemění — nikdy tiché Na haléře. Produkční cestu
  hlídá integrační test z commitu 2.
- **Dvě místa výchozích hodnot formuláře.** `applyClientDefaults()` běží
  i při recalculate a jeho výchozí hodnoty se do odpovědi nepropisují;
  výchozí mód jen tam by uživatel v poli neviděl.
- **Náhled a uložení sdílejí `computeAmounts()`** — pravidlo patří tam,
  ne do `beforeSave()`, jinak náhled návrhu ukáže jinou částku než doklad.
- **Měna dokladu, ne domácí.** Mód se řídí `doc_currency`; `total_amount`
  v domácí měně se dopočítá kurzem z už zaokrouhlené částky jako dnes.
- **Prodejka a pokladní doklad** povolují jen hotovost a kartu
  (`CashDeskDocumentBase`) — dobírka se u nich neřeší.
- **Existující testy formulářů** mohou spoléhat na výchozí mód 1 —
  opravit očekávání, ne chování.
- **Zálohová faktura vydaná** není daňový doklad: varování
  `non_cash_rounding` u ní padá jen s DPH, tedy prakticky nikdy.

## Hotovo když

- [ ] Nový prodejní doklad má automatiku zapnutou a mód podle způsobu
      úhrady, měny a země; při změně způsobu úhrady nebo měny se mód
      přepočítá ve formuláři i při uložení.
- [ ] Existující doklady mají po `ds-upgrade` automatiku vypnutou
      a nezměněné částky.
- [ ] Nový přijatý doklad z formuláře má mód Na haléře.
- [ ] Vypnutá automatika s neobvyklým módem dává varování, ne chybu.
- [ ] Generovaná faktura (periodická fakturace) má automatiku zapnutou;
      import a doklady z pošty se chovají jako dosud.
- [ ] Dump → seed datové sady zachová příznak.
- [ ] Dokumentace a nápověda popisují nové chování.
- [ ] Testy zelené (cíleně, pak celá sada), `npm run build` jen pokud se
      měnil frontend.
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
