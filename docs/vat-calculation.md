# Jak se počítá DPH na dokladech

Autoritativní pravidla DPH na dokladu pro `docs.core` — kde se kód liší, platí
tento dokument a rozdíl je chyba (#75). Implementace:
`DocDocument::beforeSave` → `computeAmounts` = `calculateRowPrice` /
`calculateRowVat` → `buildVatRecapitulation` (nebo `takeOverVatRecapitulation`
u převzaté) → `sumTotals` / `applyTotalRoundingAuto` / `applyTotalRounding` →
`reconcileRowsToRecap` → `applyDomesticAmounts`.

## 1. Tři úrovně, jedna autorita

| úroveň | tabulka | role |
|---|---|---|
| řádek | `docs_core_rows` | cena, množství, sazba, DPH kód; `vat_base` / `vat_amount` / `vat_total` jsou **informativní** (tisk řádku) |
| rekapitulace | `docs_core_vat_recap` | **autoritativní** DPH dokladu per (kód, sazba, předmět plnění): `base`, `tax`, `total` + `_dom` |
| hlavička | `docs_core_heads` | součty z rekapitulace (`total_base`, `total_vat`, `total_amount`, `total_rounding`) |

Z rekapitulace čte účtování (343), saldo, DPH výstupy (DP3/KH/SH) a podání.
Řádkové hodnoty DPH do žádného výkazu nevstupují.

### 1.1 Tři stavy řádku dokladu s DPH (#164 D1–D3)

Položkový řádek dokladu s DPH (`vat_mode` ≠ 0) je vždy v jednom ze tří
stavů. Jediné místo pravidla je `VatLineScope` (docs.core):

| stav | kód DPH | v rekapitulaci | v částce dokladu |
|---|---|---|---|
| **plnění** | kód, který není „Mimo DPH“ — i osvobozený nebo s 0 % | ano (i s nulovou daní) | ano, přes rekapitulaci |
| **Mimo DPH** | `cz-000` (kategorie `outOfScope`) | ne | ano, vždy — i u převzaté rekapitulace (§ 5.1) |
| **nevyplněno** | žádný | ne | jen u přepočítané rekapitulace |

- **„Mimo DPH“** je řádek, který není plněním: odpočet nezdaněné zálohy,
  kauce, místní poplatek, průběžná položka. Nemá místo plnění ani směr,
  nabízí se na každém dokladu s DPH jako poslední, nemá řádek ve výkazech.
  Počítá se jako řádek bez daně (`vat_pct` 0, `vat_amount` 0, `vat_total` =
  `vat_base`) v obou režimech cen. Plnění s místem plnění mimo tuzemsko sem
  nepatří — mají vlastní kódy.
- **Řádek bez kódu znamená „nevyplněno“**, ne „mimo DPH“: nález
  `vat_code_missing` na `rows.N.vat_code` je varování v rozpracovaném
  dokladu a při uložení řádku ze sub-formuláře, chyba při potvrzení dokladu
  (`DocDocument::validateVatCodesFilled()`) — **i v importním módu**, který
  výjimku z kontroly nemá (#166 D22: zdroj posílá validní kód, u řádku,
  který není plněním, „Mimo DPH“). Výměnný formát v režimu pokyn kód
  neodvozuje. Chybějící kód ale doplní **výchozí hodnotou jako formulář**
  (první kód nabídky, #166 D43) — mimo importní mód; řádek s výslovným
  `"vat": {"code": null}` a řádek importu jdou na doklad bez kódu
  a rozhodne tahle kontrola (`docs/exchange-format.md` § 3). Netýká se
  kontačních řádků (operace s vlajkou `rowSide` — formulář jim pole Kód DPH
  nenabízí) a dokladu, u kterého se nedohledala země registrace.
- **Doklad Bez DPH (`vat_mode` 0) kódy nenese** — řádky bez kódu jsou na
  něm správně a kontrola se jich netýká.

Jediný znak „mimo rekapitulaci“ je kategorie kódu `outOfScope`
(`VatRateResolver::isOutOfScopeCode()`); žádný druhý příznak na kódu není.
`empty($row['vat_code'])` má v `DocDocument` dva významy — „řádek nemá kód“
(kontroly sazby a předmětu plnění, odvození procenta) zůstává dotazem na
sloupec, „řádek nepatří do rekapitulace“ jde vždy přes
`VatLineScope::inRecap()`.

## 2. Režim cen — `vat_mode`

Vlastnost dokladu (`docs.core.vatModes`), určuje, co znamená cena na řádku:

- `0 Bez DPH` — nedaňový doklad; základ = cena, daň 0. U neplátce DPH
  nese cena řádku i daň dodavatele (je součástí ceny pořízení) — přijaté
  doklady z pošty tak převádí `DocumentApplier`
  (`docs/exchange-format.md` § 8.4).
- `1 Ze základu` — ceny na řádcích jsou bez DPH.
- `2 Z ceny celkem` — ceny na řádcích jsou s DPH.

## 3. Metoda výpočtu — `vat_calc_source`

Platí jen pro **přepočítanou** rekapitulaci (§ 5). Default vždy `0`.

**`0 Z hlavičky`** (norma, § 37 odst. 1 ZDPH): řádky se seskupí per (DPH kód, sazba),
sečtou se **řádkové ceny** a daň se počítá jednou, ze součtu:

| `vat_mode` | výpočet skupiny |
|---|---|
| 1 ze základu | `base = Σ cena`; `tax = round(base × pct / 100)`; `total = base + tax` |
| 2 z ceny celkem | `total = Σ cena`; `base = round(total / (1 + pct / 100))`; `tax = total − base` |

Výsledek: celková částka dokladu je vždy přesně Σ řádkových cen; daň je spočtená
jednou, z celého plnění v dané sazbě. Řádkové `vat_base`/`vat_amount` se
z rekapitulace odchylují o haléře — to je v pořádku, jsou informativní.

Příklad (`mode 2`, 2 řádky à 55,00 s DPH 21 %): `total = 110,00`,
`base = round(110 / 1,21) = 90,91`, `tax = 19,09`. Součet řádkových rozpočtů by dal
2 × (45,45 + 9,55) = 90,90 + 19,10 — jiné rozdělení, stejná částka.

**`1 Z řádků`**: rekapitulace = součet **řádkových** `vat_base`/`vat_amount`
(daň tedy sečtená z per-řádek zaokrouhlených hodnot, v obou režimech cen).
Historický režim (řádek = samostatná účtenka v jednom pokladním lístku). Ponechán
pro doklady, kde protistrana počítala po řádcích a my doklad počítáme sami; u
vydaných faktur a prodejek nedává smysl a časem se z nich odstraní.

`round()` = `vat_rounding_mode` dokladu (cfgItem `docs.core.vatRoundingModes`
— jen na haléře (0, default) nebo matematicky na 1; sémantiku kódů drží
`RoundingModes`), aplikuje se na dokladové úrovni: při `vat_mode` 1 na daň,
při `vat_mode` 2 na základ.

Koeficientová metoda (do 2019, `k = round(pct / (100 + pct), 4)`) se **neimplementuje**;
historické doklady kryje převzatá rekapitulace.

## 4. Speciální kódy

- **Reverse charge (samovyměření):** primární řádek nese spočtenou daň jako nárok na
  odpočet (DP3 ř. 43/44), párový řádek `is_reverse_pair` oddanění; do `total`
  vstupuje jen daň placená dodavateli (`noPayTax` → jen základ).
- **`noPayTax` / 0 % / osvobozené:** daň 0 nebo informativní, `total = base`.
  Osvobozené plnění je v rekapitulaci i na vstupu (`cz-114` tuzemsko,
  `cz-214` EU, `cz-414` třetí země) — kvůli shodě s rekapitulací dodavatele;
  do výkazů nejde.
- **„Mimo DPH“ (`cz-000`):** řádek není plnění — do rekapitulace nevstupuje
  vůbec, do součtů hlavičky vždy (§ 1.1). Doklad jen s takovými řádky
  rekapitulaci nemá a do období DPH se nepočítá.
- **Tuzemské přenesení daňové povinnosti — předmět plnění:** viz § 4.1.
- **`sum_base` / `sum_tax` / `sum_total`** z definice kódu říkají, co z řádku
  rekapitulace vstupuje do součtů hlavičky.

### 4.1 Předmět plnění u tuzemského přenesení (#89 D7)

Kód DPH s příznakem `reverseCharge` (vstup `cz-115` / `cz-116` / `cz-340`,
výstup `cz-150` / `cz-151` / `cz-350`) nese jen místo, směr a sazbu. **Co** se
plní — kód předmětu plnění pro kontrolní hlášení A.1 / B.1 (`4` stavební
práce, `4a`, `5`, `14` mobilní telefony …) — je samostatný údaj
`reverse_charge_code` na řádku dokladu i na řádku rekapitulace, vybíraný
z číselníku `world.vat.<země>.reverseChargeSubjects` s platností v čase
(čte ho jen `VatRateResolver`).

- **Klíč skupiny rekapitulace = (kód DPH, sazba, předmět)** — jediné místo
  `DocDocument::vatGroupKeyOf()`. Dva řádky se stejným kódem a sazbou, ale
  různým předmětem jsou dvě skupiny a u vstupu dva oddaňovací páry; pár nese
  předmět své skupiny. Prázdný předmět dává klíč beze změny — doklady bez
  přenesení se seskupují jako dřív.
- **Jiný kód předmět nenese:** při uložení se vynuluje bez hlášky
  (`ReverseChargeSubjects::normalized()` — řádky z payloadu v
  `computeAmounts()`, řádek ze sub-formuláře v `DocRowsDocument`, řádek
  převzaté rekapitulace ve `VatRecapDocument` a `takeOverVatRecapitulation()`).
- **Validace** (`DocDocument::validateReverseChargeSubjects()`): předmět mimo
  číselník nebo neplatný k datu sazby (§ 5.2) je chyba **vždy**
  (`rows.N.reverse_charge_code`, u převzaté rekapitulace pole `recap`);
  chybějící předmět u kódu přenesení je chyba při **potvrzení** dokladu
  (stav V pořádku). Koncept ani V opravě se bez něj uložit smí — starý doklad
  bez předmětu musí jít otevřít k opravě. Platí i v importním módu.
- **Jedna autorita pro výstupy:** kontrolní hlášení čte předmět jen
  z rekapitulace, nikdy z řádků ani z definice kódu.

Pravidla sloupce drží `modules/docs/core/src/ReverseChargeSubjects.php`.

## 5. Autorita rekapitulace — `vat_recap_source`

Vlastnost **celého dokladu**:

- `0 Přepočítaná` — rekapitulace vzniká z řádků podle § 2–4 při každém uložení.
  Doklady, které vystavuje Nový Shipard (FVB, prodejky, pokladní doklady, opravné
  doklady).
- `1 Převzatá` — rekapitulace je **vstup a fakt**: to, co je na existujícím dokladu.
  `beforeSave` ji nepřepočítá; spočítá z ní `_dom`, součty hlavičky a `sum_*`.
  Použití: import ze starého Shipardu, přijaté doklady (rekapitulace dodavatele je
  závazná i když je haléřově „špatně" — nárok na odpočet je částka z faktury),
  ruční oprava účetní.

Výchozí hodnota podle původu: vystavený doklad → přepočítaná; import → převzatá
(exchange `vat.recapSource: "declared"`, `vatRecap` z dat zdroje); přijatý doklad
z AI extrakce → převzatá, když `vatRecap` je vnitřně konzistentní (`base + tax =
total`) a žádný řádek nemá kód se samovyměřením (`reverseVatCode`); jinak
přepočítaná. Rekapitulace dodavatele s přenesením daňové povinnosti je z jeho
pohledu (0 %, daň 0) a nepřebírá se — naše nese nárok na odpočet a oddaňovací pár
(`docs/exchange-format.md` § 5, D3).

Editace: při `převzatá` je rekapitulace editovatelná. Změna částek řádků ji
**nepřepíše** — uložení vydá warning `rows_recap_mismatch`. Oprava: upravit
rekapitulaci, nebo přepnout na `přepočítaná` (přegeneruje se z řádků) a případně
zpět. Změny textů a jiných neobnosových polí rekapitulaci nedotknou.

Procento řádku převzaté rekapitulace **vstupem není** — odvodí se z kódu
a data sazby (§ 5.2); vstupem jsou kód, základ, daň a celkem.

Kontroly (warning, neblokují): `vat_recap_inconsistent` (`tax ≠ base × pct`
v toleranci `max(0,05; |base| × 0,001)`, `base + tax ≠ total` ± 0,02) — u převzaté
zviditelní nesrovnalost dodavatele, i jeho jinou sazbu; `rows_recap_mismatch` (Σ řádků ≠ rekapitulace
dle režimu) — signál neúplných nebo špatně zadaných řádků. Řádky „Mimo DPH“
do žádné skupiny nepatří a kontroly je přeskakují.

Řádek převzaté rekapitulace s kódem „Mimo DPH“ je **chyba vždy**
(`vat_recap_out_of_scope`, pole `recap`; i v importním módu) — není to
plnění a základ by se do součtů dostal podruhé vedle řádků dokladu. Formulář
řádku rekapitulace kód nenabízí.

### 5.1 Implementace

- **Přechody zdroje** rozhoduje `DocDocument::useDeclaredRecap()` porovnáním
  `vat_recap_source` v payloadu se stavem v DB: přepnutí kterýmkoli směrem
  přegeneruje rekapitulaci z řádků (u `přepočítaná → převzatá` je to ta
  „kopie startovní převzaté"), uložení už převzatého dokladu ji nechá být.
  Bez `originalData` (applier, interní přepočet z dětské tabulky) rozhoduje
  payload: přišla-li rekapitulace, převezme se; u uloženého dokladu se
  převezme ta v DB.
- **Normalizace vstupu** (`takeOverVatRecapitulation`): flagy `sum_*` vždy
  z definice kódu (autorita definice, ne vstupu), `total` se dopočítá jen
  když ho vstup nenese, `id` řádku se zachovává (child sync `TableGateway`
  aktualizuje na místě, sub-tabulka ve formuláři neztratí identitu).
  Neznámý DPH kód je `DomainException` — stejně jako u přepočítané.
  Z výměnného formátu se k ní ale nedostane: applier každý kód rekapitulace
  ověří `VatCodeResolver`em (stejná kaskáda země jako u řádků) a
  nedohledatelný kód znamená přepočítanou + `recap_source_computed_fallback`
  s důvodem (I7), ne 500.
- **Součty hlavičky** se u převzaté berou **z rekapitulace a z řádků „Mimo
  DPH“** (`sumRowsOutsideRecap()`, #164 D7): odpočet nezdaněné zálohy na
  importovaném dokladu by jinak z částky dokladu vypadl. Řádek s běžným
  kódem, který v rekapitulaci není (import s jiným mapováním kódů), a řádek
  bez kódu se nepřičítají — započítaly by se podruhé; kontrola na chybějící
  skupinu rekapitulace neexistuje, hlídá se jen součet skupin, které
  v rekapitulaci jsou (`rows_recap_mismatch`). Prázdná rekapitulace =
  fallback na řádky jako dřív. U přepočítané rekapitulace se přičítají
  všechny řádky mimo ni. Typ dokladu s vlastními součty (účetní doklad)
  přičítání vypíná celé (`headTotalsIncludeRowsOutsideRecap()` = false).
- **Rekapitulace se neváže na řádky** — `docs_core_vat_recap` nemá FK na
  řádky, párování při `rows_recap_mismatch` je jen per (kód, sazba, předmět
  plnění — § 4.1).
- **Editace** ve formuláři: přepnutí na převzatou se projeví **po uložení**
  (tehdy vznikne kopie přepočítané rekapitulace, kterou jde editovat); tab
  se řídí uloženou hodnotou, ne přepínačem. Tab „Rekapitulace DPH" je u převzaté sub-tabulka
  nad `docs_core_vat_recap` (`VatRecapForm`, `VatRecapDocument`), u
  přepočítané zůstává přehled ke čtení. Po změně řádku rekapitulace
  přepočítá hlavičku `DocHeadRecomputer` — u převzaté aktualizuje řádky
  **na místě** podle `id`, nikdy nemění částky. Účtování jde na přechodu
  stavu, takže po ruční opravě zaúčtovaného dokladu je potřeba Přeúčtovat.
- **Jeden výpočetní blok pro uložení i náhled**: řádky, rekapitulace,
  součty, zaokrouhlení, dorovnání a domácí měna žijí v
  `DocDocument::computeAmounts()` (veřejná, bez zápisu do DB, číslování a
  přechodů stavů); `beforeSave()` ji volá, náhled návrhu výměnného formátu
  (`DocumentApplier::preview` → `_resolve.computed`) ji volá přímo nad
  instancí z `TableGateway::createDocument()`, takže platí i přetížení
  podtříd (`AccountingDocument`: `vat_mode` 0, součty Σ MD). Co ukáže
  náhled, to skončí na dokladu — `tasks/exchange-preview-vat-recompute.md`.
- **Výchozí hodnota z výměnného formátu**: `vat.recapSource` (`computed` /
  `declared`), `vat.calcSource` (`header` / `rows`). Chybějící `recapSource`
  applier odvodí — u dokladu, který přijímáme, `declared` při neprázdné,
  aritmeticky konzistentní rekapitulaci s dohledatelnými kódy, jinak
  `computed` + info issue `recap_source_computed_fallback`. Explicitní
  `declared` se aritmetikou nepodmiňuje: u přenesení daňové povinnosti
  `base + tax ≠ total` platí. Rekapitulaci bez kódů (ISDOC) applier doplní
  z řádků, když je pro sazbu jednoznačný kód. Detaily
  `docs/exchange-format.md`.

### 5.2 Datum sazby DPH (#159, #92 D4)

**Procento DPH se nezadává.** Na řádku dokladu i na řádku rekapitulace se
vždy odvodí z kódu DPH a **data sazby** (`VatRateResolver::resolveVatPct`);
`vat_pct` zůstává jako uložená hodnota. Odchylka od běžné sazby má na dokladu
viditelnou stopu — datum, ne přepsané číslo. Pravidla drží na jednom místě
`VatRateDates` (`modules/docs/core/src/`).

Datum sazby určuje přepínač hlavičky **Sazby DPH podle**
(`docs_core_heads.vat_rate_source`, cfgItem `docs.core.vatRateSources`):

| Zdroj | Datum sazby |
|---|---|
| `0` Automaticky (DPPD) | **opravný daňový doklad** s vyplněným `corrected_doc_date` → datum plnění původního dokladu (oprava používá sazbu původního plnění, #92 D4); jinak **DPPD**, bez něj DUZP, bez něj datum vystavení (nedaňový doklad DUZP ani DPPD nemá, #79 D1) |
| `1` Vlastní datum dokladu | `vat_rate_date` hlavičky pro všechny řádky i řádky převzaté rekapitulace; nevyplněné = do potvrzení automatika |
| `2` Vlastní datum na řádcích | `vat_rate_date` řádku dokladu a řádku rekapitulace; prázdné = automatika |

Proč DPPD před DUZP: sazba se řídí dnem vzniku povinnosti přiznat daň.
U přijatého dokladu je to DUZP z dokladu dodavatele (v KH B.2 se uvádí jako
DPPD); DUZP přijatého dokladu u nás řídí období přiznání (datum odpočtu),
datem sazby proto není. Vlastní datum na řádcích pokrývá vyúčtování záloh
zdaněných různými sazbami — jeden doklad nese víc sazeb (informace GFŘ
k § 37a, příklad 3).

- **Jen daňový doklad s DPH.** Nedaňový doklad a doklad s `vat_mode` 0 má
  vždy zdroj 0 (`VatRateDates::source()`).
- **Data mimo svůj režim se při uložení mažou** — `vat_rate_date` hlavičky
  mimo zdroj 1, `vat_rate_date` řádků mimo zdroj 2.
- **Kde se procento odvozuje:** `DocDocument::computeAmounts()` (řádky před
  výpočtem daně), `takeOverVatRecapitulation()` (řádky převzaté
  rekapitulace), `DocRowsDocument` / `VatRecapDocument` při uložení ze
  sub-formuláře. `persistRowComputedColumns()` zapisuje i `vat_pct`
  a `vat_rate_date`, takže uložení hlavičky bez řádků v payloadu (změna DPPD
  nebo přepínače) propíše procento a datum do řádků v DB;
  `DocHeadRecomputer` u převzaté rekapitulace mění na místě jen procento,
  datum a odvozené sloupce, nikdy základ, daň a celkem.
- **Bez země registrace nebo konfigurace** (doklad bez registrace DPH, unit
  testy) se procento neodvozuje a zůstává hodnota ze vstupu.
- **Rekapitulace při datech na řádcích:** přepočítaná rekapitulace nese
  `vat_rate_date` prvního řádku skupiny; kopie při přepnutí na převzatou ho
  převezme. Klíč skupiny se nemění (kód, sazba, předmět plnění) — řádky
  s různým datem a stejným procentem jsou jedna skupina, řádky téhož kódu
  s různým procentem dvě. Při zdroji 0 a 1 je datum řádků rekapitulace NULL.
- **Oddaňovací pár** (`is_reverse_pair`) procento i datum dědí z primární
  skupiny — u převzaté rekapitulace z nejbližšího předchozího řádku, jehož
  kód na něj ukazuje (`reverseVatCode`); vlastní kontrolu sazby nemá.
- **Předmět plnění** (§ 4.1) se ověřuje k datu sazby svého řádku.
- **Převzatá rekapitulace** (§ 5): procento už není vstup, částky ano.
  Rekapitulaci dodavatele v jiné sazbě ukáže `vat_recap_inconsistent` (daň
  neodpovídá sazbě ze základu) — odstraní ji datum sazby, ne přepsané procento.

Kontroly (`DocDocument::validateVatRates()`, nálezy `VatRateDates`):

| Kód | Kdy | Závažnost |
|---|---|---|
| `vat_rate_missing` | kód DPH řádku (`rows.N.vat_code`) nebo řádku převzaté rekapitulace (`recap`) nemá k datu sazby sazbu | chyba při potvrzení dokladu, v importním módu a při uložení řádku ze sub-formuláře; v rozpracovaném dokladu (Koncept, V opravě) varování a procento řádku zůstává uložené |
| `vat_rate_date_required` | zdroj 1 bez vyplněného data | chyba při potvrzení a v importním módu |
| `vat_rate_date_late` | vlastní datum (dokladu nebo řádku) pozdější než automatické | varování, neblokuje |

Varování místo chyby v rozpracovaném dokladu je záměr: jinak by změna DPPD
na hlavičce nešla uložit, dokud nejsou opravené řádky, a řádky (nabídka kódů)
se řídí uloženou hlavičkou.

**DPPD u dokladu směru prodej následuje změnu DUZP** (U13,
`VatRateDates::followObligationDate()`): formulář faktury vydané, prodejky
ani příjmového pokladního dokladu DPPD neukazuje a `applyDateDefaults` ho
doplňuje jen prázdné. Následuje se jen DPPD, které DUZP v uloženém dokladu
sledovalo a které payload sám nemění; odlišné DPPD z importu zůstává.

Formuláře: přepínač v tabu Nastavení (`triggers: 'reload'`), při zdroji 1
pole data na hlavním tabu, při zdroji 2 pole data ve formuláři řádku a řádku
rekapitulace. Nabídka kódů DPH (`DocHeadVatContext::vatCodeOptions()`) ukazuje
jen kódy se sazbou k datu sazby řádku, s procentem v popisku; uložený kód
bez sazby zůstává vidět s označením. Kontext hlavičky dává datum sazby dokladu
jako `effective_rate_date`. Sub-tabulka řádků a rekapitulace má při zdroji 2
sloupec Datum sazby, detail dokladu stopu u údajů dokladu a u řádků.

**Zařazení do období DPH, kontrolního hlášení a zámky období datum sazby
nečtou** — řídí se dál `vat_duzp` / `vat_dppd` (`economy.vat`).

**Výměnný formát a applier** (#159 task B, D11): canonical nese přepínač
a vlastní data — `vat.rateSource` (`auto` | `document` | `rows`),
`dates.vatRateDate`, `rows[].vat.rateDate`, `vatRecap[].rateDate`. Applier
datum sazby nepočítá vlastním kódem: složí hlavičku ve tvaru, který čte
`VatRateDates` (`DocumentApplier::vatRateHead()`), a kód DPH (odvození,
kontrola souladu, volba uživatele), sazbu i platnost předmětu plnění hledá
**k datu sazby řádku**, u převzaté rekapitulace k datu řádku rekapitulace.
Místo plnění, registrace k datu, neplátce a období čtou dál DUZP. Hodnoty
na doklad jen předá; mazání podle režimu dělá dokument. Přijatá faktura,
jejíž DPPD a DUZP leží na různých stranách změny sazby, tak projde se sazbou
dodavatele (dřív `vat_code_unknown`, resp. chyba při potvrzení). Export
dokladu (datové sady) přepínač a data nese, takže dump → seed je zachová.
Viz `docs/exchange-format.md` § 5 „Datum sazby DPH“ a § 8.4.

## 6. Zaokrouhlení celkové částky — `total_rounding_mode`, `total_rounding_auto`

Až po rekapitulaci: `total_amount` se zaokrouhlí (matematicky na celé jednotky,
nahoru, dolů, **matematicky na 0,05** — hotovost SK a část eurozóny, nebo na
haléře = bez efektu), rozdíl jde do `total_rounding`; rekapitulace zůstává.
Kódy módů a výpočet `kód → (krok, směr)` drží `RoundingModes`
(`modules/docs/core/src/`), jsonc `docs.core.roundingModes` nese jen názvy;
historický kód 2 (matematicky na 0,01) byl totožný s 0 a `ds-upgrade` ho slévá
(#63). `vat_rounding_mode` má užší nabídku `docs.core.vatRoundingModes` (§ 3).

**Ve výměnném formátu** (#166 D25, D26) jsou oba módy výslovná pole:
`totals.totalRoundingMode` (`cent` | `unit` | `unitUp` | `unitDown` |
`fiveCent`) a `vat.roundingMode` (`cent` | `unit`); názvy ↔ kódy drží
`RoundingModeNames` (core.exchange) a test hlídá úplnost proti
`RoundingModes`. Pokyn mód bere z payloadu a neodhaduje ho (chybí = na
haléře), návrh ho bez uvedené hodnoty odhadne z částek; export vypisuje jen
nevýchozí hodnoty. Spočtené `total_base`, `total_vat`, `total_amount`
a `total_rounding` jsou v pokynu protistranou kontrolního součtu `totals`
— `docs/exchange-format.md` § 5.

**Automatika podle způsobu úhrady** (#73 D1–D8, `tasks/doc-total-rounding-auto.md`).
Prodejní doklad (`docTypes[].total_rounding_auto`: `invno`, `invpo`,
`cashreg`, příjmový `cash` — směr přes `DocDocument::resolveTradeDir()`) si
při zapnutém `total_rounding_auto` mód určí sám: `computeAmounts` před
`applyTotalRounding` zavolá `TotalRoundingAuto::modeFor()` se způsobem
úhrady, měnou dokladu (`doc_currency`; náhled applieru běží před
`applyHomeCurrency`, proto fallback na domácí) a zemí zdroje dat
(`DataSourceConfig::getCountry()`):

- hotovostní způsob úhrady (`paymentMethods[].cashRounding`: Hotovost,
  Dobírkou) → krok z `world.base.cashRounding` pro měnu, platí-li pravidlo
  pro zemi (CZ + CZK → 1 matematicky na 1; SK, FI, NL, BE, IE, IT, EE, LT
  + EUR → 5 matematicky na 0,05, rozbor v #63); krok na mód překládá
  `RoundingModes::forStep()`. Měna bez pravidla nebo země mimo seznam
  → na haléře;
- nehotovostní úhrada → na haléře;
- bez konfigurace nebo bez země → `null`, mód se nemění (unit testy, zdroj
  dat před `ds-upgrade`) — nikdy tiché Na haléře.

Kde automatika neplatí (typ bez příznaku, výdajový pokladní doklad), se
zapnutý příznak při výpočtu vynuluje; chybějící klíč `DocDocument`
nedoplňuje — platí výchozí hodnota sloupce 0, existující doklady se nemění.
Zapnutou automatiku dává novému dokladu formulář
(`DocsHeadsFormBase::applyNewRecordDefaults`; select módu je při zapnuté
automatice jen ke čtení a `recalculate` ho drží podle úhrady a měny,
`docs/edit-forms.md` kap. 23) a applier (`totals.totalRoundingAuto`, D8,
`docs/exchange-format.md`; pokyn ji bez pole zapne všude, kde platí,
a uvedený jiný mód ohlásí varováním `total_rounding_mode_ignored`).
Ostatní typy mají z formuláře mód Na haléře.

Při **vypnuté** automatice `DocDocument::validate()` varuje (`addWarning` na
`total_rounding_mode`, jen prodejní typy, mimo import mód; uložení
neblokuje): hotovostní úhrada s jiným módem, než dává pravidlo →
`cash_rounding_mismatch` (text jmenuje očekávaný mód z číselníku);
nehotovostní úhrada se zaokrouhlením u dokladu s DPH → `non_cash_rounding`.
Zaokrouhlovací rozdíl je ze základu daně vyjmut jen při hotovostní platbě
(§ 36 odst. 5 ZDPH); u bezhotovostní úhrady se do základu dál nezahrnuje,
doklad jen varuje (D7).

## 7. Dorovnání řádků na rekapitulaci — obě měny

Rekapitulace je autorita (§ 1); řádkové `vat_base` / `vat_amount` jsou z ní odvozené
a **dorovnávají se na ni top-down v obou měnách nezávisle** — v měně dokladu i v
domácí. Rozdíl per skupina (kód, sazba) absorbuje poslední řádek skupiny s nenulovou
hodnotou; cena řádku (`total_price`) se nikdy nemění, jen odvozený rozpad. V mode 1
se dorovnává jen `vat_amount` (základ = cena, sedí konstrukčně); v mode 2 `vat_base`
i `vat_amount` (`vat_total` = cena, sedí konstrukčně).

Proč obě měny: deník účtuje výnos/náklad z řádků a 311/321 z hlavičky v obou
měnách — bez dorovnání cur by byl sloupec měny dokladu per doklad rozjetý o haléře
(u tuzemského dokladu s kurzem 1 dokonce dva sloupce téhož řádku různě). A tisk
řádkových DPH hodnot musí dávat rekapitulaci. Starý Shipard dorovnával jen Hc
(`taxBaseHcCorr`, jen tuzemské doklady); tuzemsky to vyšlo nastejno, cizoměnově ne.

Domácí měna: `base_dom`/`tax_dom` rekapitulace = `round(cur × kurz)`; hlavička se
sčítá z rekapitulace; `total_rounding_dom` absorbuje kurzový zbytek hlavičky.
**Výjimka — samovyměření v cizí měně** (#103 D80, § 4 odst. 8 ZDPH): daň
v domácí měně se počítá ze základu v domácí měně (`tax_dom =
applyRounding(base_dom × sazba)`), ne přepočtem daně spočtené v měně dokladu
— ta se liší o haléře (432,70 EUR × 24,325 = 10 525,43 Kč, 21 % = 2 210,34;
přepočet 90,87 EUR by dal 2 210,41). Oddaňovací pár nese `base_dom` a `tax_dom`
primární skupiny, `total_dom` = jejich součet. Platí i pro deklarovanou
rekapitulaci (`takeOverVatRecapitulation`); při kurzu 1 zůstává `tax_dom`
kopií `tax`. **Přepočet jde v desítkové aritmetice** (`DocDocument::toHome`,
BCMath `bcmul` + `bcround`, #103 nález I): `round(x × kurz, 2)` nad floatem
zaokrouhlí součin na přesné půlce haléře dolů (45 × 24,345 = 1 095,525 je
binárně 1 095,5249…), správně je 1 095,53; daň samovyměření ze základu
v domácí měně (`homeTax`) totéž.

Implementace: `DocDocument::reconcileRowsToRecap($rows, $recap, $suffix, $tolerance)`
— jedna metoda, volaná dvakrát (`''` pro měnu dokladu z `beforeSave`, `'_dom'`
z `applyDomesticAmounts` nad už dorovnanými cur hodnotami, takže při kurzu 1 jsou
obě měny shodné). Cíl dorovnání se v obou průchodech vybírá podle cur hodnot —
řádek s nulou v měně dokladu má nulu i v domácí, takže haléř základu i daně
padne na týž řádek a `vat_total` zůstane konzistentní. `vat_total` po dorovnání
drží politiku `DocRowCalculator::computeVat`: součet částí jen tam, kde je daň
součástí placené ceny (mode 1 s běžným kódem), jinak je autoritou cena řádku.

Invarianty (per skupina kód+sazba a per doklad, v cur i dom):

```
Σ rows.vat_base      == recap.base        Σ rows.vat_base_dom   == recap.base_dom
Σ rows.vat_amount    == recap.tax         Σ rows.vat_amount_dom == recap.tax_dom
Σ recap.base (sum_base) == total_base     Σ recap.base_dom == total_base_dom
Σ recap.tax  (sum_tax)  == total_vat      Σ recap.tax_dom  == total_vat_dom
total_base + total_vat + total_rounding == total_amount   (obdobně _dom)
```

**Tolerance u převzaté rekapitulace (§ 5):** dorovnání se provede jen do meze
haléřového zaokrouhlení — `max(0,02; 0,01 × počet řádků skupiny)` na základ i daň.
Větší rozdíl znamená chybějící nebo špatně zadané řádky: řádky zůstanou, jak jsou,
invariant pro tu skupinu neplatí a uložení vydá `rows_recap_mismatch`. U přepočítané
je rozdíl konstrukčně vždy v mezi.

## 8. Co se tiskne

Rekapitulace (základ / daň / celkem per sazba) a součty hlavičky. Řádkové DPH
hodnoty jsou volitelný komfort tiskové šablony, nikdy zdroj pro součet. Otevřené:
u dokladů v cenách s DPH řádkové DPH raději netisknout.

## 9. Odkazy

`docs/docs-mvp.md` §7–8 (historie návrhu; kde se liší, platí tento dokument),
`docs/exchange-format.md` (`vat`, `vatRecap`, `totals`), `docs/accounting.md`
(343 z rekapitulace), `modules/economy/vat/docs/README.md` (výstupy z recapu),
issue #75, zákon č. 235/2004 Sb. § 37.
