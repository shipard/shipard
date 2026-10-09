# Shipard — Zakázky a periodická fakturace (`economy.workOrders`)

> **Designový dokument.** **Stav:** D1–D24 rozhodnuto 2026-10-08;
> fáze 1 (evidence) a fáze 2 (periodická fakturace, úroveň Koncept)
> hotové 2026-10-08, tasky v §7.
> **Datum:** 2026-10-08 · **Milník:** M4 (blokátor migrace) ·
> **Issue:** #110

Jedna evidence zakázek pro **automatickou periodickou fakturaci**
(nájemné, služby) i pro **sledování nákladů a výnosů** (zakázky výroby
a služeb, režie). Nahrazuje dvě cesty starého Shipardu — prodejní
smlouvy a fakturaci zakázek přes šablony dokladů — jednou. Zakázka
a středisko se stávají standardními dimenzemi účetního deníku vedle
majetku.

---

## 1. Cíl a hranice

**Cíl:** firma v novém Shipardu vede zakázky všech typů (§5.1), periodické
zakázky se fakturují samy — od konceptu ke kontrole až po plnou
automatizaci s odesláním — a každý doklad lze přiřadit zakázce
a středisku, takže je z deníku vidět, co zakázka stojí a co vynáší.

**V rozsahu M4 (D19):**

- model všech čtyř typů zakázek, druhy, číselné řady zakázek, nadřazená
  zakázka;
- standardní dimenze deníku středisko, zakázka a majetek v jádru
  účetnictví (D20, D21);
- celá periodická fakturace (D2–D7, D10–D12, D24).

**Později:** projektové funkce externí jednorázové zakázky — faktura ze
zakázky (dílčí i konečná), cena a předmět dodávky, termíny, přehled
stavu se sčítáním podzakázek, uzavření zakázky.

**Mimo:** výkazy práce, plánování výroby, zádržné, místa a osoby
zakázky, návazné zakázky (ve starých datech nepoužité); dimenze
„projekt“ (D21); zásoby — samostatné téma, pro zdroje s výrobními
zakázkami však podmínka importu (D19).

---

## 2. Rozbor starého Shipardu

Starý Shipard má periodickou fakturaci dvakrát a k tomu zakázky jako
analytiku.

### 2.1 Prodejní smlouvy (`e10doc.contracts`)

Hlavička (komu, jak, kdy) a řádky (co se fakturuje), druhy smluv.
Druh nese výchozí hodnoty ve 14 párech „hodnota + smí se měnit na
smlouvě“. Generátor běží v ranním cronu, prochází posledních 10 dní
a pro každé období vytvoří doklad; existenci faktury hledá dotazem do
dokladů (partner + smlouva + období). Cílový doklad: faktura vydaná nebo
zálohová faktura, stav podle druhu (výchozí koncept), smlouva může nést
zakázku a středisko, které se propíší na fakturu.

### 2.2 Šablony dokladů (`e10doc.templates`)

Tři typy šablon: obecná šablona (bez generátoru), **generování ze
zakázek** (jedna šablona na druh zakázky, jedna faktura na zakázku podle
její periodicity a řádků) a kopie existujícího dokladu. Duplicitu hlídá
textovým klíčem v dokladu.

### 2.3 Zakázky (`e10mnf`)

Druh zakázky má přes 60 přepínačů „používat X“. Dvě osy: typ (běžná /
režijní) × frekvence (jednorázová / průběžná / periodická). Číselné řady
zakázek s kódem a pevnou vazbou na druh, nadřazená zakázka, termíny,
řádky zakázky, osoby, místa, zádržné. Zakázka je sloupec na hlavičce
i řádcích dokladů a v deníku; rozbor zakázky čte deník a do nadřazené
zakázky sčítá podzakázky.

### 2.4 Slabiny, které nepřebíráme

- Výpadek cronu delší než 10 dní období **tiše vynechá**; stejně tak
  smlouva, která je přes přelom období déle než 10 dní V opravě.
- Generátor ze zakázek nefiltruje stav ani platnost zakázky (jen druh,
  limit 500 záznamů).
- Duplicitu faktur hlídá dotaz do dokladů nebo textový klíč, ne
  evidence vyfakturovaných období.
- Páry „hodnota + smí se měnit“ a desítky přepínačů na druhu místo
  jednoduché dědičnosti.

---

## 3. Data — co se reálně používá

Zdroje dat migrované ze starého Shipardu, agregovaně.

### 3.1 Periodická fakturace

| zdroj | platné smlouvy | faktur ze smluv (2025) | zakázky | doklady se zakázkou (celkem) |
|---|---|---|---|---|
| `689089` | 438 (+909 ukončených) | ≈1 290 — ≈1 050 měsíčních FV, ≈230 ročních zálohových | ≈620 | 0 |
| `732084` | 39 | ≈210 | 50 | ≈1 400 (FV + FP) |
| `205976` | 4 | ≈76 | 17 | ≈620 FV |
| `332718`, `426748` | 6 / 6 | ≈72 / ≈72 | 0 | 0 |

- Šablony dokladů nejsou nainstalované v žádném migrovaném zdroji.
- Kde smlouva vede na zakázku (`732084`, `205976`), je to vždy 1:1 —
  smlouva je fakticky fakturační předpis zakázky.
- Zakázky v `689089` jsou rozpracovaný přechod ze smluv na fakturaci
  zakázek; nic na ně nevede (D9).
- Generované faktury se před potvrzením upravují: ≈3 % (`689089`)
  a ≈13 % (`732084`) má jiný počet řádků než smlouva. Část faktur
  `689089` se po začátku období doplňuje o přefakturovanou spotřebu podle
  podkladů dodavatele (D7, D10).
- Nepoužívá se posun data vystavení, automatické odeslání ani poznámka
  na fakturu. Používá se platnost řádků od/do, ceny s DPH, cizí měna
  a zálohová faktura jako cílový doklad. Opakované platby stejné výše
  odběratelé platí trvalým příkazem — VS se nesmí měnit (D11).

### 3.2 Zakázky ve výrobě (`272304`)

- ≈48 tis. zakázek od roku 2003, ≈2 800 nových ročně. Druhy: obchodní
  zakázky (≈35 tis.), výrobní příkazy (≈10 tis.), reklamace (≈2 250),
  režijní (≈210). Měna ≈2/3 EUR, ≈1/3 CZK. ≈270 tis. řádků zakázek.
- 12 číselných řad zakázek (produktové řady, výrobní příkazy, výroba na
  sklad, reklamace, režie); každá řada pevně určuje druh.
- Nadřazená zakázka: ≈2 700 výrobních příkazů pod obchodní zakázkou,
  ≈600 reklamací pod obchodní zakázkou nebo výrobním příkazem, ≈135
  obchodních zakázek pod jinou obchodní — strom aspoň tří úrovní.
- Zakázka je **vždy na řádku dokladu, nikdy na hlavičce**. Podíl řádků
  se zakázkou (2024): FV 99 %, objednávky vydané ≈100 %, výdejky 87 %,
  FP 85 %, příjemky 79 %, účetní doklady 71 %.
- Každá FV patří právě jedné zakázce; ≈14 % zakázek fakturovaných
  v roce 2024 má dvě a víc faktur (dílčí fakturace).
- Termíny: požadovaný téměř u všech, potvrzený u výrobních příkazů,
  datum ukončení u ≈70 % obchodních zakázek. Nepoužívá se zádržné,
  místo, návazná zakázka, osoby zakázky ani výkazy práce.

### 3.3 Dimenze ve starém deníku

Deník starého Shipardu má **čtyři pevné dimenze** přímo v tabulce:
středisko, projekt, zakázku a majetek. Za 20 let nepřibyla žádná další.
Středisko používá pět migrovaných zdrojů (v `689089` je na ≈99 %
vydaných a ≈95 % přijatých faktur a ≈70 % řádků deníku), majetek pět,
zakázku čtyři, projekt čtyři — ale okrajově.

### 3.4 Co nový Shipard už má

- `period_from` / `period_to` na hlavičce dokladu;
- dimenze deníku — jednotný mechanismus `journalDimensions`
  (`docs/accounting.md` § Dimenze deníku), zatím s jedinou dimenzí
  `asset`;
- číselník středisek `economy_codebooks_cost_centers` (bez vazby na
  doklady a deník);
- cron slot `daily`, zálohová faktura vydaná (`invpo`), odesílání
  záznamů `RecordSendService` (`docs/prints.md` §9; hromadné
  a automatické odesílání je #90 D11).

---

## 4. Rozhodnutí

Všechna rozhodnutí jsou z issue #110, schválená 2026-10-08. Kde pozdější
rozhodnutí mění dřívější, je to u obou vyznačené.

### D1–D9 — Základ periodické fakturace

- **D1 — Jedna cesta: zakázky.** Periodická fakturace stojí na
  zakázkách. Prodejní smlouvy jako samostatná evidence nevzniknou.
  Rozsah: evidence zakázek, fakturační předpis zakázky, periodické
  vystavování a zakázka jako dimenze deníku. Výkazy práce, plánování
  a zádržné zůstávají odložené. *Rozsah upraven D15 — nadřazená zakázka
  je v modelu od začátku.*
- **D2 — Jeden fakturační proud na zakázku.** Dva rytmy pro jednoho
  zákazníka = dvě zakázky. *Zúženo D16 — periodicita jen u periodického
  typu.*
- **D3 — Výchozí hodnoty na druhu zakázky.** Druh nese výchozí „jak“
  (typ cílového dokladu, číselná řada dokladů, splatnost, fakturace na
  počátku / konci období, ceny bez / s DPH, stav vzniklého dokladu).
  Zakázka hodnotu smí přepsat; prázdná hodnota = z druhu.
- **D4 — Stav vzniklého dokladu nastavitelný až po plnou automatizaci.**
  Úrovně: Koncept (výchozí, karta ve feedu ke kontrole) → V pořádku →
  V pořádku a automaticky odeslat. Implementace po fázích.
- **D5 — Evidence vyfakturovaných období.** Tabulka zakázka × období →
  doklad s unikátním klíčem je zdroj pravdy o tom, co je vystaveno.
  Běh dohání všechna splatná období od začátku fakturace zakázky.
  Smazaný koncept období **zastaví**; akce Přegenerovat sestaví obsah
  konceptu znovu ze zakázky (detail D24).
- **D6 — Datum vystavení je pevné.** Datum vystavení a DUZP určuje
  předpis zakázky (počátek / konec období), ne okamžik vzniku nebo
  potvrzení dokladu.
- **D7 — Rozšiřitelný obsah faktury.** Obecný bod rozšíření, přes který
  jiný modul doplní nebo upraví obsah faktury za období (první případ:
  přefakturace spotřeby podle podkladů dodavatele). Teď jen návrh
  rozhraní (D10), implementace přispěvatelů později.
- **D8 — Středisko jako dimenze deníku**, zároveň se zakázkou.
  *Upřesněno D20.*
- **D9 — Import (oblast importu ze starého Shipardu).** U `689089` se
  importují rozpracované zakázky, smlouvy ne; před importem se ověří
  shoda se smlouvami. Specifikum tohoto zdroje. *Upřesněno D25 — platí
  pro roční část; měsíční část převezme runner ze smluv.*

### D10–D13 — Přispěvatelé, VS, text, poznámka

- **D10 — Přispěvatelé obsahu faktury.** Evidence období má stavy
  *naplánováno → čeká na podklady → vystaveno → zastaveno*. Přispěvatele
  registruje modul v `module.jsonc` (vzor `documentLockProviders`); volá
  se pro zakázku × období nad rozpracovaným dokladem a vrací doplněné
  nebo upravené řádky, „čekám na podklady“, nebo chybu. Zapíná se
  řádkem zakázky označeným jako **proměnný**; zakázka bez proměnných
  řádků přispěvatele nevolá. Úroveň Koncept: doklad vznikne hned
  a přispěvatel ho doplní po příchodu podkladů. Vyšší úrovně: doklad
  vznikne až s podklady. Překročení nastavené lhůty = upozornění
  „období čeká na podklady“. Datum vystavení a DUZP zůstávají podle D6.
- **D11 — Pevný variabilní symbol na zakázce.** Nepovinný; vyplněný
  nesou všechny faktury zakázky (trvalé příkazy odběratelů).
- **D12 — Proměnná `období` v textu dokladu.** Jedna proměnná, formát
  podle periodicity zakázky a jazyka dokladu.
- **D13 — Jen interní poznámka** na zakázce; poznámka na fakturu nebude.

### D14–D19 — Typy, nadřazená zakázka, číselné řady, stavy, fáze

- **D14 — Typ zakázky na druhu.** Zakázka typ dědí z druhu. Čtyři typy
  (§5.1): periodická, externí jednorázová, interní průběžná, interní
  jednorázová. Externí průběžná zakázka (podpora, přefakturace podle
  skutečnosti) je externí jednorázová bez data ukončení.
- **D15 — Nadřazená zakázka je součást modelu od začátku** (mění rozsah
  D1). Nepovinná, u jednorázových typů, hloubka stromu neomezená. Stav
  nadřazené zakázky sčítá podzakázky. Povinnost lze časem nastavit na
  číselné řadě zakázek.
- **D16 — Periodicita jen u periodického typu** (zužuje D2).
- **D17 — Číselné řady zakázek.** Vlastní tabulka řad; společný engine
  čísel (vzorec, kód řady, restart po roce / průběžně, čítače) vytažený
  z číselných řad dokladů. Řada patří jednomu druhu, druh může mít víc
  řad (pobočky, organizační důvody). Číslo se přidělí při potvrzení;
  importovaná čísla zůstávají.
- **D18 — Dva koncové stavy:** **Ukončeno** (úspěšně dokončená zakázka,
  u periodické konec platnosti) a **Zrušeno** (nerealizovaná zakázka —
  obchodní důvody, změna metodiky).
- **D19 — Fáze.** Pro M4: model všech typů, druhy, číselné řady,
  nadřazená zakázka, dimenze zakázka a středisko, celá periodická
  fakturace. Později projektové funkce (§1). Import `272304` později;
  jeho podmínkou jsou i zásoby.

### D20–D24 — Dimenze, evidence, doklady, Přegenerovat

- **D20 — Standardní dimenze jsou součást jádra** (upřesňuje D8).
  Středisko, zakázka a majetek mají sloupce přímo v definicích
  `docs_core_heads`, `docs_core_rows` a `economy_accounting_journal`;
  deklaraci v `journalDimensions` nese `economy.accounting`. Evidence
  (číselník středisek, zakázky, karty majetku) a jejich chování zůstávají
  ve svých modulech. Engine, deník, formuláře a tisk Kontace zůstávají
  na jednotném seznamu dimenzí, otevřeném pro další dimenze. Modul
  zakázek je součástí `install.base`. Důvody: §3.3 (pevná sada dimenzí),
  `install.base` aktivuje ekonomické moduly v každém zdroji (volitelnost
  sloupců nic nepřináší), databáze nemá cizí klíče.
- **D21 — Nastavení „Dimenze na dokladech“.** Viditelnost každé dimenze
  na dokladech zapíná nastavení v jednom bloku; výchozí vypnuto, import
  zapne dimenze, které zdroj používá. Nahrazuje
  `economy.assets.trackExpenses` (bez ohledu na kompatibilitu testovacích
  zdrojů). Dimenze **projekt se nezavádí**.
- **D22 — Evidence zakázky (rozsah M4)** — §5.2, §5.3.
- **D23 — Pole na dokladech.** Zakázka a středisko na fakturách
  vydaných, zálohových vydaných, fakturách přijatých, pokladních
  a účetních dokladech; na hlavičce jako výchozí hodnota, na řádcích
  skutečná. Viditelnost podle D21. Bankovní výpis zatím ne.
- **D24 — Přegenerovat.** Jen u konceptu; nahradí hlavičku i řádky podle
  aktuální zakázky (ruční úpravy se ztratí — potvrzení); doklad si drží
  id (koncept nemá číslo). Zastavené období lze obnovit akcí na záložce
  *Fakturace* — vrátí se do „naplánováno“ a vystaví se hned.


### D25–D27 — Import zakázek (2026-10-09)

- **D25 — `689089`: roční část ze zakázek, měsíční ze smluv** (upřesňuje
  D9). Kontrola dat (§6.1) ukázala, že rozpracované zakázky pokrývají
  roční fakturaci (párování 1:1 se smlouvami), ale zakázky měsíčních
  druhů nemají řádky ani periodicitu. Runner proto u měsíční části
  převezme obsah platných smluv — do zakázky spárované podle zákazníka,
  nebo do nové zakázky; zakázky bez smlouvy se uklidí ručně ve zdroji.
  Drobné rozdíly roční části se doladí ručně ve zdrojovém DS.
- **D26 — Celý reimport, zakázky před doklady.** Testuje se opakovaným
  plným importem, takže doplnění zakázky na už importované doklady se
  neřeší: zakázky se importují **před** doklady a doklady nesou zakázku
  rovnou v `dimensions.workOrder`.
- **D27 — Ostatní zdroje: smlouva → zakázka** (uzavírá O6). Prodejní
  smlouva se importuje jako periodická zakázka:
  smlouva navázaná na zakázku (ve starých datech vždy 1:1) převezme svůj
  předpis do ní, smlouva bez zakázky založí novou. Stejný postup jako
  u měsíční části `689089` (D25); efektivní hodnoty smlouvy (druh vs.
  smlouva) runner počítá jako starý generátor.
---

## 5. Doménový model (návrh)

Modul **`economy.workOrders`** (součást `install.base`). Názvy tabulek
a sloupců jsou závazné; podrobnosti (stavové hodnoty, indexy, formuláře)
upřesní fázové tasky.

### 5.1 Typy zakázek (D14)

Typ je enum na druhu zakázky (`economy.workOrders.types`), po založení
druhu jen ke čtení. Určuje, co zakázka má:

| typ | zákazník | řádky | cena | fakturace | nadřazená | konec |
|---|---|---|---|---|---|---|
| `periodic` — periodická | ano | fakturační předpis | — | automatická (§5.5) | ne | ukončení platnosti |
| `project` — externí jednorázová | ano | předmět dodávky *(později)* | *(později)* | dílčí + konečná ze zakázky *(později)* | volitelně | uzavření |
| `overhead` — interní průběžná | ne | ne | ne | ne | ne | archiv |
| `internal` — interní jednorázová | z nadřazené | co vyrobit / zadat *(později)* | plán *(později)* | ne | volitelně | uzavření |

Pro všechny typy: zakázka je dimenze deníku (hlavička jako výchozí
hodnota, řádky skutečná) a stav nadřazené zakázky sčítá podzakázky
(D15; přehled stavu je projektová funkce, §1).

### 5.2 Druh zakázky a číselné řady (D3, D17, D22)

**`economy_work_orders_kinds`** (Nastavení, archivní stavy): název, typ,
výchozí hodnoty fakturace — jen u periodického typu: typ cílového dokladu
(`invno` / `invpo`), číselná řada dokladů, splatnost, fakturace na
počátku / konci období, ceny bez / s DPH, úroveň stavu vzniklého
dokladu (D4).

**`economy_work_orders_number_series`** (Nastavení, archivní stavy):
druh, název, kód řady, vzorec čísla, restart (průběžně / po roce),
platnost od/do. Řada patří jednomu druhu; druh může mít víc řad. Engine
čísel je společný s číselnými řadami dokladů (§5.7). Číslo se přidělí
při potvrzení (Koncept → V pořádku); importovaná čísla zůstávají.

### 5.3 Zakázka — hlavička a řádky (D11–D13, D15, D22)

**`economy_work_orders_heads`**

| skupina | pole | typy |
|---|---|---|
| identita | číselná řada, číslo, druh (denormalizovaný z řady, jako typ dokladu u dokladů), název, stav | všechny |
| analytika | středisko — propíše se na hlavičku vzniklého dokladu | všechny |
| platnost | zahájení, ukončení — u periodické „do kdy fakturovat“ (smí být v budoucnu), u ostatních skutečné datum ukončení (nastaví přechod do Ukončeno / Zrušeno) | všechny |
| poznámka | interní poznámka | všechny |
| strana | zákazník, měna, pevný VS | externí |
| strom | nadřazená zakázka | jednorázové |
| fakturace | periodicita (měsíc / čtvrtletí / pololetí / rok), fakturovat od, text dokladu s proměnnou `období`, přepisy výchozích hodnot z druhu (prázdné = z druhu) | periodická |

*Fakturovat od* = první fakturované období (výchozí = zahájení); od něj
počítá dohánění (D5). Import ho nastaví na první dosud nevyfakturované
období, takže se nic nevystaví zpětně.

**`economy_work_orders_rows`** — v M4 jen u periodické zakázky jako
fakturační předpis: položka, text, množství, jednotka, jednotková cena,
DPH kód, pohyb, platnost od/do, příznak **proměnný** (D10).

- Řádek se na fakturu použije, když jeho platnost pokrývá DUZP období
  (změna ceny k datu bez zásahu do starých řádků).
- DPH kód je na řádku — nová položka sazbu DPH nenese. Pohyb smí být
  prázdný: použije se výchozí pohyb cílového dokladu, jako u nového
  řádku faktury.
- Tabulka je navržená tak, aby se do ní vešel i předmět dodávky externí
  jednorázové zakázky (později).

**Detail zakázky v M4:** záložka *Fakturace* (evidence období a doklady,
jen periodická; akce Obnovit u zastaveného období) a záložka *Deník*
(vlastní řádky deníku se zakázkou; sčítání podzakázek až s přehledem
stavu).

**Hotovo ve fázi 1** (2026-10-08, `tasks/work-orders-phase1.md`): modul
`economy.workOrders` v `install.base` — `economy_work_orders_kinds`
(typ přes cfgItem `economy.workOrders.types` s příznaky `external` /
`oneOff` / `invoicing`, čte jen `WorkOrderTypes`; typ po opuštění Konceptu
jen ke čtení), `economy_work_orders_number_series` + `_number_counters`
(`WorkOrderSeriesDocument`: vzorec přes `NumberPattern::validate` bez
doménových placeholderů a navíc s povinným pořadím `%3`–`%6`, druh po
založení neměnný), `economy_work_orders_heads` s poli identita / strana /
strom / platnost / poznámka (fakturační pole a řádky přidá fáze 2).
`WorkOrderDocument`: druh a typ denormalizované z řady při každém uložení,
validace podle příznaků typu (neexterní typ zákazníka, měnu a VS vynuluje;
nadřazená jen u `oneOff` — jinak chyba `not_allowed`; nadřazená ne
periodická, ne smazaná, bez cyklu), zahájení povinné při potvrzení,
chybějící fiskální rok hlášený na `date_start` (`fiscalYearMissing`),
číslo při přechodu 0/10 → 40 přes `SequenceCounter` nad vlastní
`SequenceStorage` (rozsah = `FiscalYearLookup::yearIdForDate(date_start)`
u `fiscal_year`, jinak NULL), importované číslo zůstává, přechod do 70 /
30 doplní `date_end`, `beforeDelete` pustí jen koncept. `WorkOrdersForm`
(pole podle typu, řada jen u konceptu, `applyNewRecordDefaults` z tabu
řady dosadí druh, typ a domácí měnu, tab Přílohy), `WorkOrdersViewer`
(spodní taby = řady V pořádku jako u dokladů, filtry druh / typ, detail:
Přehled + tabulka nadřazené a podzakázek + zákazník interní jednorázové
z nejbližší externí v řetězci předků (`WorkOrderTreeService`), tab Deník
(`WorkOrderJournalService`, strop 200 řádků) a akce Otevřít v deníku
s filtrem `dim_workOrder=#id`), `WorkOrdersLookup` (V pořádku a V opravě;
filtr `role=parent` přidá koncepty pro pole Nadřazená). Sekce sidebaru
*Zakázky* (`navSections` order 33) a sekce Nastavení *Zakázky*
(`settingsSections` order 12). Helper `Document::trackStateChange` je
sdílený jádrem (dřív kopie v pěti dokumentech).

### 5.4 Stavy zakázky (D18)

Vlastní sada stavů (`economy.workOrders.docStates`): Koncept, V pořádku
(zakázka běží), V opravě, **Ukončeno**, **Zrušeno**, Smazáno. Ukončeno
i Zrušeno jsou koncové stavy v archivní skupině prohlížeče, rozlišené
pro přehledy. Fakturuje jen zakázka **V pořádku** v době platnosti;
zakázka V opravě se přeskočí a její období se díky dohánění (D5) vystaví
po návratu do V pořádku.

**Řádky předpisu se řídí stavem zakázky** jako řádky dokladu
(`tasks/work-orders-rows-readonly.md`, 2026-10-09 — ruší odchylku fáze 2):
Přidat, Smazat, přesun i úprava jen v Konceptu a V opravě, ve stavech
s `readOnly` jen ke čtení. Sub-tabulka Řádky je běžná (bez
`independentRows`), takže přebírá read-only rodiče; server to vynucuje
`WorkOrderRowLockProvider` (`documentLockProviders` nad
`economy_work_orders_rows`, `docs/document-system.md` §16) na uložení
z dialogu, mazání i generickém CRUD — stavy čte z cfgItem, ne natvrdo;
přesun řádků hlídá `/subtable/…/move` podle stavu rodiče. Změna ceny
k datu = V opravě → platnost starého řádku → nový řádek → V pořádku →
případně Přegenerovat koncept.

### 5.5 Periodická fakturace (D2–D7, D10–D12, D24)

**`economy_work_orders_periods`** — evidence období: zakázka, začátek
a konec období, stav (*naplánováno / čeká na podklady / vystaveno /
zastaveno*), doklad. Unikátní klíč zakázka × začátek období.

- **Období** jsou kalendářní podle periodicity (měsíc, čtvrtletí,
  pololetí, rok). Datum vystavení a DUZP = počátek nebo konec období
  podle předpisu (D6); `period_from` / `period_to` dokladu = období.
- **Běh** v cron slotu `daily` a ručně z CLI s náhledem bez zápisu:
  pro každou zakázku V pořádku v platnosti založí chybějící splatná
  období od *fakturovat od* a vystaví je. Nic se nehledá dotazem do
  dokladů — co je vystaveno, říká evidence období.
- **Vzniklý doklad** podle úrovně z druhu / zakázky (D4): koncept (karta
  ve feedu „faktury z periodické fakturace ke kontrole“), V pořádku,
  V pořádku a automaticky odeslat (`RecordSendService`, hromadné
  odesílání #90 D11).
- **Obsah:** hlavička z druhu a zakázky (strana, měna, řada dokladů,
  splatnost, středisko, zakázka jako výchozí dimenze, pevný VS, text
  s `období`), řádky z předpisu platné k DUZP.
- **Přispěvatelé** (D10): rozhraní a stav *čeká na podklady* v M4,
  žádný přispěvatel zatím.
- **Smazaný koncept** období zastaví; **Přegenerovat** a **Obnovit**
  podle D24.

**Hotovo ve fázi 2** (2026-10-08, `tasks/work-orders-phase2.md`, úroveň
Koncept — Q7): fakturační předpis — sloupce `inv_*` na druhu
(`economy_work_orders_kinds`) a zakázce (NULL = z druhu), periodicita,
`inv_from` (výchozí zahájení) a `inv_doc_text` s `{období}`; efektivní
hodnoty skládá jen `InvoicingSettingsResolver`; řádky
`economy_work_orders_rows` (`WorkOrderRowDocument` / `WorkOrderRowsForm`,
sub-tabulka řízená stavem zakázky — oprava
`tasks/work-orders-rows-readonly.md`, §5.4; pohyby a kódy DPH podle
efektivního typu dokladu, `contributor`); potvrzení vyžaduje periodicitu,
efektivní typ dokladu a řadu a aspoň jeden řádek. Evidence
`economy_work_orders_periods` (UNIQUE zakázka × začátek; `state` planned /
waiting / issued, zastaveno odvozené = issued s dokladem v koši nebo bez
něj; `result` + `message` = výsledek posledního běhu, `content_hash` =
otisk konceptu). `src/Invoicing/`: `PeriodCalendar` (kalendářní období,
den fakturace, splatná období k datu, ukončení bez krácení),
`PeriodLabel` (`{období}` přes ext-intl a cfgItem `periodTexts` cs / en /
sk / de), `InvoiceBuilder` → kanonický `shpd.docs.document.v1`
(`DocumentApplier`, Q1: `numberSeriesId`, `importOwnBankAccount`
efektivní nebo výchozí účet, pin zákazníka, dimenze zakázka a středisko,
`source.kind = workOrder`), `InvoicingRunService` (zámek řádku období +
apply + zápis dokladu v jedné transakci — applier ukládá v
`NestedTransaction`; chyba jednoho období zastaví jen je; pojistka
dohánění `MAX_CATCHUP = 3` s `force`; dry-run bez zápisu; Přegenerovat
přes `applyOptions.replaceConcept`, Obnovit = odvázání dokladu + běh),
CLI `work-orders-invoice-run` a cron `daily`. Detail: tab *Fakturace*
a akce Vystavit dlužná období / Přegenerovat / Obnovit
(`WorkOrdersInvoicingController`, `/_work-orders/…`). Přispěvatelé:
`InvoiceContributor` + registrace `workOrderInvoiceContributors` →
cfgItem `economy.workOrders.invoiceContributors`
(`InvoiceContributorRegistry`), Waiting = koncept s řádky přispěvatele
s množstvím 0, Ready v dalším běhu přegeneruje jen nezměněný koncept
(`ContentHash`), nastavení `economy.workOrders.contributorWaitDays`;
žádný přispěvatel zatím. Upozornění `economy.workorders.*`
(invoices_to_review souhrnně, period_failed, catchup_blocked,
period_waiting per zakázka). Viewer dokladů má filtr Zdroj
(`source_kind`). Odchylky od zadání: řada do applieru podle id
(`numberSeriesId` — kód řady je nepovinný a neunikátní), výchozí účet
dosazuje builder přes `DefaultBankAccountResolver` (applier pro ostatní
volající beze změny), `inv_vat_mode` nabízí i Bez DPH, id alertů malými
písmeny (`economy.workorders.*`).

### 5.6 Standardní dimenze (D20, D21, D23)

| dimenze | id | sloupec (hlavička, řádky, deník) | cílová tabulka | doklady |
|---|---|---|---|---|
| Středisko | `costCenter` | `cost_center` | `economy_codebooks_cost_centers` | `invno`, `invpo`, `invni`, `cash`, `cmnbkp` |
| Zakázka | `workOrder` | `work_order` | `economy_work_orders_heads` | `invno`, `invpo`, `invni`, `cash`, `cmnbkp` |
| Majetek | `asset` | `asset` | `economy_assets_assets` | `invni`, `invno`, `cash`, `cmnbkp` (+ řádky s vlajkou `rowAsset`) |

- Sloupce jsou v definicích tabulek jádra, deklarace v `module.jsonc`
  modulu `economy.accounting`; mechanismus `docs/accounting.md`
  § Dimenze deníku beze změny chování.
- Nastavení *Dimenze na dokladech* (Nastavení → Účetnictví): jedno pole
  na dimenzi, klíč `economy.accounting.dimension.<id>`, výchozí vypnuto.
  Nastavení řídí jen zobrazení pole; uložená hodnota se do deníku
  propisuje vždy.
- Výměnný formát dokladu nese dimenze v objektu `dimensions` na hlavičce
  a řádcích, klíčem je id dimenze a hodnotou přirozený klíč cílové
  tabulky (`exchangeKey` deklarace: středisko kód, majetek inventární
  číslo). Datová sada nese jen dimenze, jejichž cílovou tabulku má
  v `setup/` (středisko); majetek si ponechává vlastní postup doplnění
  karty na doklady (`docs/assets.md` §5.7) a do sady se nepřenáší.

### 5.7 Engine čísel (D17)

Vzorec čísla, kód řady, restart a čítače dnes žijí v číselných řadách
dokladů (`docs_core_number_series`). Vytáhnou se do společné služby jádra
beze změny chování dokladů; číselné řady zakázek ji použijí se svou
tabulkou řad a čítačů. Vazby specifické pro doklady (typ dokladu,
pokladna, sklad, odesílatel e-mailu, automatický autor) zůstávají
v řadách dokladů.

Hotovo (2026-10-08, `tasks/number-series-engine.md`): `src/Core/Numbering/`
— `NumberPattern` + `NumberContext` (vzorec), `SequenceStorage` +
`SequenceCounter` (čítač), id a popisek roku `FiscalYearLookup`
v `economy.codebooks`; `docs/architecture.md` §8. Řady zakázek
(`WorkOrderDocument::sequenceCounter`, fáze 1) používají vlastní
`SequenceStorage` nad `economy_work_orders_number_counters` +
`economy_work_orders_heads`, žádné doménové placeholdery a rozsah čítače
= fiskální rok data zahájení (`reset_scope = fiscal_year`), jinak NULL.

---

## 6. Import (kontrakt pro `old_shipard`)

Import je oblast importu ze starého Shipardu (D9, D25–D27). Nový Shipard
pro něj připraví:

- středisko a zakázku ve výměnném formátu dokladu (`dimensions`, §5.6) —
  hotovo;
- výměnný formát zakázky `shpd.workOrders.workOrder.v1` včetně
  fakturačního předpisu a *fakturovat od* (`tasks/work-orders-import.md`) —
  hotovo 2026-10-09, kontrakt v `docs/exchange-format.md` § Zakázky.

**Pořadí** (D26): druhy a číselné řady zakázek → osoby, položky,
střediska → **zakázky** (nadřazené před podřízenými) → doklady
s `dimensions.workOrder`. Řádky předpisu se zapisují, dokud je zakázka
v Konceptu (`WorkOrderRowLockProvider`, §5.4, bez výjimky pro import) —
formát zakázky to řeší sám: hlavička a řádky v jednom požadavku, potvrzení
až po řádcích.

**Fakturovat od** (pravidlo runneru): smlouva / zakázka s vystavenou
fakturou → den po konci posledního vyfakturovaného období; bez faktury →
první začátek období v den zahájení nebo po něm (starý generátor
nefakturoval zpětně za rozběhnuté období). Jinak by import vystavil
období, která starý Shipard nefakturoval.

**Hotovo na straně nového Shipardu** (2026-10-09,
`tasks/work-orders-import.md`, I1–I4): schéma
`modules/core/exchange/schemas/shpd.workOrders.workOrder.v1`, v modulu
`src/Import/` `WorkOrderImportVerifier` (kontrola payloadu před zápisem:
reference podle id, kódy, pravidla typu podle řady, řada dokladů vs.
efektivní typ dokladu, nadřazená podle čísla, číslo a čítač; nálezy
s cestou do payloadu, `WorkOrderImportCheck` nese dohledané id) a
`WorkOrderImportApplier` (jedna transakce: hlavička v Konceptu → řádky →
cílový stav přes V pořádku uložením přes `WorkOrderDocument`
a `WorkOrderRowDocument`; existující číslo = `skipped`; `validate` =
průběh s rollbackem). Převzaté číslo: `WorkOrderDocument` čte virtuální
pole `_importSequence` a srovná čítač řady (`SequenceCounter::syncImported`)
v rozsahu fiskálního roku data zahájení. Endpointy
`POST /_exchange/workOrders/workOrder/{validate|apply}` (admin nebo API
klíč, exchange dispatcher, `ReadOnlyPolicy` podle přípony akce). Runner ve
starém Shipardu (párování smluv a zakázek `689089`, pravidlo *fakturovat
od*) je oblast importu a tenhle task ho nepokrývá.

### 6.1 Kontrola dat `689089` (2026-10-09, agregovaně)

- **Roční část** (≈240 zálohových faktur ročně): 342 ze 343 platných
  smluv má podle zákazníka právě jednu aktivní zakázku a naopak. Řádek
  (vždy jeden) má shodnou položku u 341, shodnou částku u 337 dvojic;
  periodicita sedí u 336 (5 smluv pololetních proti roční zakázce,
  1 zakázka bez periodicity). 17 aktivních zakázek nemá platnou smlouvu
  (13 „nefakturuje se“).
- **Měsíční část** (≈1 050 faktur ročně, 95 platných smluv): zakázky
  měsíčních druhů (85 aktivních) nemají řádky a většinou ani periodicitu.
  Podle zákazníka má zakázku 64 smluv, 31 ne; 24 zakázek nemá smlouvu.
- **Středisko** dává faktuře druh smlouvy, ne smlouva (druhy 1–4 → jedno
  středisko, druh 5 → jiné); zakázky nesou středisko shodné s fakturami.
  Nová zakázka ze smlouvy bere středisko z druhu smlouvy.
- **Fakturovat od:** roční — 241 smluv vyfakturováno do konce 2026, 95 bez
  faktury (85 začíná 2027, 6 v 2028, 4 v 2026); měsíční — většina má další
  období jako první nevyfakturované, 6 je o 1–3 měsíce pozadu, 1 má
  nekalendářní měsíční období, 1 bez faktury — k ruční kontrole ve zdroji.
- Období starých faktur jsou kalendářní (měsíc od 1., pololetí od 1. 1.
  a 1. 7., rok od 1. 1.) — odpovídá Q3 fáze 2.
---

## 7. Fáze a tasky

| # | Task | Rozhodnutí | Stav |
|---|---|---|---|
| 1 | `tasks/dimensions-core.md` — majetek a středisko jako standardní dimenze jádra, nastavení *Dimenze na dokladech*, středisko ve výměnném formátu | D20, D21, D23 | hotovo (2026-10-08) |
| 2 | `tasks/number-series-engine.md` — společný engine čísel vytažený z číselných řad dokladů | D17 | hotovo (2026-10-08) |
| 3 | `tasks/work-orders-phase1.md` — modul, druhy, číselné řady, hlavička všech typů, stavy, nadřazená zakázka, dimenze zakázka, záložka Deník | D14–D18, D22, D23 | hotovo (2026-10-08) |
| 4 | `tasks/work-orders-phase2.md` — periodická fakturace: předpis, evidence období, běh, koncept s kartou ve feedu, Přegenerovat a Obnovit, VS, `období`, záložka Fakturace, rozhraní přispěvatelů | D2–D7, D10–D12, D24 | hotovo (2026-10-08) |
| 4a | `tasks/work-orders-rows-readonly.md` — oprava fáze 2: řádky předpisu se řídí stavem zakázky (bez `independentRows`, `WorkOrderRowLockProvider`) | D22, D24 | hotovo (2026-10-09) |
| 5 | `tasks/work-orders-phase3.md` — úrovně V pořádku a automatické odeslání | D4 | připravuje se; navazuje na #90 D11 |
| 6 | `tasks/work-orders-import.md` — výměnný formát zakázky `shpd.workOrders.workOrder.v1` včetně fakturačního předpisu a *fakturovat od* (strana nového Shipardu; runner v `old_shipard`) | D9, D25, D26 | hotovo (2026-10-09) |

---

## 8. Otevřené otázky

- ~~O6~~ → D27.
