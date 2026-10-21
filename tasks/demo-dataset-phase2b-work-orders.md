# Demo sada „IT servis“ — fáze 2b: zakázky

**Stav:** hotovo — 2026-10-21, #131 D19, D29–D37

## Kontext / Cíl

Paušály v příběhu „IT servis“ dnes vznikají jako samostatné vydané faktury.
Fáze 2b z nich udělá **periodické zakázky** s evidencí vyfakturovaných období
a faktury, které vypadají jako z periodické fakturace. Přibudou **dva
projekty** (jednorázové zakázky) s výnosy i náklady. Formát sady se k tomu
rozšíří o druhy a řady zakázek v `setup/`, sekci `workOrders/` a přenosnou
dimenzi zakázky na dokladech.

Po seedu je v aplikaci vidět: agenda Zakázky (13 periodických, 2 projekty),
tab Fakturace s evidencí období, Deník zakázky, filtr Zdroj ve vydaných
fakturách a koncepty z periodické fakturace ke kontrole v měsíci kotvy.

## Návaznost

- **#131** — D1–D28 (komentáře v issue), **D19** (zakázky ve fázi 2),
  tento task **D29–D36**
- `tasks/demo-dataset-phase1.md`, `tasks/demo-dataset-phase2a-bank.md`,
  `tasks/demo-dataset-economy.md` — generátor, příběh, banka, ekonomika,
  Odchylky
- `docs/work-orders.md` §5.2–5.6, §6; `tasks/work-orders-import.md`
  (`WorkOrderImportApplier`)

## Před implementací přečti

- `docs/datasets.md` §1 (sekce `setup/`, obálka výpisu jako vzor), §3, §4
- `docs/work-orders.md` §5.2–5.6
- `docs/exchange-format.md` § Zakázky (I1–I4, pořadí importu D26)
- `modules/economy/bank/src/Dataset/` — vzor exportéru a seederu sekce
- `modules/core/exchange/src/Export/SetupExporter.php`,
  `modules/core/exchange/src/Dataset/Seed/SetupSeeder.php`,
  `modules/core/exchange/src/Export/DocumentExporter.php` (přenosné dimenze)
- `modules/economy/workOrders/src/Import/`, `src/Invoicing/`
  (`InvoiceBuilder`, `PeriodCalendar`, `PeriodLabel`, `DocTextTemplate`,
  `ContentHash`, `PeriodRepository`)
- `src/Command/DataSource/DatasetSeedCommand.php`, `DatasetDumpCommand.php`
- `demo/datasets/stories/it-servis/`, `demo/datasets/README.md`,
  `tools/invoice-generator/invoicegen/expand.py`, `story.py`, `economy.py`

## Rozsah

**V rozsahu:**

- druhy a řady zakázek v `setup/`, cizí klíč přes přirozený klíč (D29)
- sekce `workOrders/`: obálka, exportér, seeder, evidence období (D30, D31)
- přenosná dimenze zakázky v exportéru dokladů (D31)
- příběh: paušály → periodické zakázky (D32–D34), dva projekty (D36)
- generátor, souhrn ekonomiky, přegenerovaná sada, testy, dokumentace (D35)

**Mimo rozsah:**

- paušál zahájený nebo ukončený uprostřed okna (stavy periodických
  zakázek) — odloženo v #131
- majetek (fáze 2c), přispěvatelé obsahu faktury, úroveň V pořádku
  a automatické odeslání v periodické fakturaci
- změny periodické fakturace v PHP — kdyby byly potřeba, zastav se,
  zapiš do Odchylek a zeptej se

## Návrh

### D29 — Druhy a řady zakázek v `setup/`

| Soubor | Tabulka | Klíč |
|--------|---------|------|
| `work_order_kinds.jsonc` | `economy_work_orders_kinds` | `name` |
| `work_order_series.jsonc` | `economy_work_orders_number_series` | `number_code` |

**Cizí klíč přes přirozený klíč.** Dnešní výjimku pro účty (číslo účtu)
zobecnit v `SetupExporter` / `SetupSeeder` na jeden mechanismus:

- reference na tabulku ze `SetupExporter::TABLES` s jednosloupcovým klíčem
  → hodnota klíče (řada → druh názvem, druh → bankovní účet kódem);
- reference na `docs_core_number_series` → `doc_number_code` řady (řady
  dokladů zakládá provisioner); nedohledatelný nebo neunikátní kód = chyba
  seedu, ne tiché vynechání ani první nalezená;
- účet osnovy číslem jako dnes;
- ostatní FK beze změny (vynechání s varováním).

Řada zakázek bez `number_code` se nepřenese (dump varuje). Seed jde
abecedně: `work_order_kinds` před `work_order_series`, obě za
`bank_accounts`.

**D37 — řada dokladů složeným klíčem typ + kód.** Výchozí řady dokladů
z provisioneru (`NumberSeriesProvisioner`) mají `doc_number_code = NULL`
(číslo 1260001 má prázdné `%C`), takže samotný kód řadu nedohledá. Reference
na `docs_core_number_series` (`inv_number_series` druhu a zakázky) se proto
přenáší jako objekt `{"docType": "invno", "code": null}` — `null` se
v souboru vynechá (`{"docType": "invno"}`). Seed hledá jako
`DocumentApplier::resolveNumberSeriesFor`, ale přísně: s kódem přesná shoda
`(doc_type, doc_number_code)`, bez kódu řada typu s prázdným kódem; žádná
nebo víc než jedna = chyba seedu. Provisioner ani existující sady se nemění.

### D30 — Sekce `workOrders/`, obálka `shpd.dataset.workOrder.v1`

Jeden soubor = jedna zakázka. Struktura je `shpd.workOrders.workOrder.v1`,
liší se jen reference: místo id přirozené klíče.

```jsonc
{
  "format": "shpd.dataset.workOrder.v1",
  "workOrder": {
    "number": "S0001", "sequenceNumber": 1,
    "numberSeries": "S",                 // number_code řady zakázek
    "title": "Správa IT", "state": "confirmed",
    "dateStart": "2023-04-01", "dateEnd": null,
    "costCenter": null,                  // kód střediska
    "customer": { "name": "…", "country": "cz", "companyId": "…", "vatId": "…" },
    "currency": "czk", "paymentReference": null, "parent": null,
    "invoicing": {
      "periodicity": "month", "invoiceFrom": "2026-01-01",
      "docText": "Správa IT — paušál {období}",
      "numberSeries": null,              // kód řady dokladů
      "bankAccount": null                // kód bankovního účtu
      // ostatní přepisy z druhu jako v kanonickém formátu
    }
  },
  "rows": [
    { "item": "SPRAVA-IT", "description": "…", "quantity": 1, "unit": "pcs",
      "unitPrice": 16000, "vatCode": "…", "validFrom": null, "validTo": null }
  ],
  "periods": [
    { "from": "2026-01-01", "to": "2026-01-31", "state": "issued", "docNumber": "…" }
  ]
}
```

- **Zápis jen přes `WorkOrderImportApplier`.** Seeder přeloží přirozené
  klíče na id a zavolá applier. Stranu dohledá stejně jako u dokladů,
  podle identifikátorů; nenalezená strana je chyba. Hlavičku ani řádky
  nezapisuje mimo applier.
- **`periods[]`** nese jen vystavená období (`issued` s dokladem).
  Období `planned` / `waiting` a období bez dokladu (smazaný, zastavené)
  dump vynechá s varováním. `result`, `message`, `waiting_since`
  a `content_hash` v souboru nejsou. `content_hash` spočítá seeder
  z uloženého dokladu přes `ContentHash`, stejně jako běh po apply.
- Třídy v `modules/economy/workOrders/src/Dataset/` (`WorkOrderExporter`,
  `WorkOrderSeeder`), schéma obálky vedle obálky výpisu. Slug souboru =
  číslo zakázky. Pořadí: nadřazené před podřízenými, jinak podle čísla.
  `manifest.counts.workOrders`.

### D31 — Pořadí seedu, dimenze na dokladech

setup → osoby → položky → **zakázky** (hlavička, řádky, cílový stav) →
doklady → **evidence období** → banka → spisovna → pošta.

- Evidence období jde až za doklady, protože odkazuje číslo dokladu: druhý
  krok seederu zakázek, nebo samostatný seeder bez vlastní sekce
  v manifestu. Doklad se hledá podle čísla mezi vydanými fakturami.
- `DocumentExporter` bere dimenzi `workOrder` za přenosnou, když je na
  zdroji dat sekce zakázek (dnes jen tabulky ze `setup/`). Hodnota = číslo
  zakázky, v hlavičce i na řádcích.
- Periodický běh se při seedu nespouští (D19).

### D32 — Paušály jako periodické zakázky

- **Druh** „Správa IT — paušál“, typ `periodic`: vydaná faktura, řada
  vydaných faktur, splatnost 14 dní, fakturace na začátku období, ceny bez
  DPH, převodem, účet BU1. **Řada** zakázek kód `S`, průběžná (D34).
- Každé pravidlo `billing` s `every: month | quarter` = jedna zakázka
  V pořádku (10 měsíčních, 3 čtvrtletní). Zálohová faktura (`once`) zůstává
  mimo zakázky.
- **Řádky předpisu:** položka, popis, množství (čtvrtletní 3), cena, kód
  DPH — stejně jako dnes na fakturách. Změna ceny (`priceFrom`) = dva řádky
  s `validTo` / `validFrom`.
- **Zahraniční odběratelé:** měna EUR, vlastní cena v EUR (D27 se netýká),
  přepis účtu na BU2, text dokladu a popis řádku v jazyce dokladu.
- **Faktury skládá generátor tak, jak by je složil `InvoiceBuilder`:**
  vystavení = DUZP = účetní datum = začátek období
  (`PeriodCalendar::billingDate`), splatnost +14 dní, `periodFrom` /
  `periodTo`, `docText` podle `DocTextTemplate` (text zakázky
  s `{období}` → `PeriodLabel` v jazyce dokladu), `source.kind = workOrder`,
  `dimensions.workOrder` = číslo zakázky, řádky v pořadí předpisu.
- **Koncepty v měsíci kotvy** (`conceptInAnchorMonth`) = vystavené období
  s dokladem v Konceptu.
- **Evidence úplná:** každé splatné období od „fakturovat od“ do kotvy má
  doklad. Kritérium: `work-orders-invoice-run --dry-run --date=<kotva>`
  nevystaví nic a nehlásí dohánění.
- Částky se nemění, cíle D25 platí dál (`test_economy.py`).

### D33 — Bez pevného VS

`paymentReference` zakázky prázdný, VS = číslo faktury jako dnes. Párování
plateb, bankovní výpisy a případy saldokonta se nemění.

### D34 — Zahájení před oknem, průběžné řady

Datum zahájení zakázky různě před oknem příběhu (smlouvy běží roky), údaj
v příběhu u zakázky. „Fakturovat od“ = začátek prvního období, které okno
obsahuje (u čtvrtletí začátek čtvrtletí). Řady `S` (paušály) a `P`
(projekty) bez ročního restartu (`reset_scope: none`); sada nese `number`
i `sequenceNumber`, aby se srovnal čítač.

### D35 — Cron po seedu

Nic se neimplementuje, jen se to zapíše do `demo/datasets/README.md`.
Zakázky jsou V pořádku, takže zdroj dat se zapnutým cronem po kotvě
vystavuje další období jako koncepty. Po třech zmeškaných obdobích zastaví
pojistka dohánění a ukáže upozornění. Pro web proto platí: čerstvý build
s posunutou kotvou (`build.py --anchor`).

### D36 — Dva projekty

- **Druh** „Projekty“, typ `project`; **řada** kód `P`, průběžná.
- **Výměna sítě** u odběratele `galvanovna`: dnešní ad hoc faktury
  v měsíci −7 (práce) a −6 (router, switche, práce) ponesou dimenzi
  zakázky. Nákup od velkoobchodu `cipdeska` v měsíci −6 dostane dimenzi
  zakázky na řádcích se switchi a routerem. Router na faktuře velkoobchodu
  dnes není, doplní se. Množství nákupu = prodané množství.
- **Wi-Fi** u odběratele `jelen`: faktury v měsíci −5 (práce, průzkum)
  a −4 (přístupové body, práce); nákup přístupových bodů v měsíci −4
  s dimenzí. Nákup musí předcházet dodávce. Dnes je dodávka ke dni 3
  a nákup ke dni 15, takže se dodávka (i její úhrada) posune za nákup.
- Oba projekty **Ukončeno**: zahájení k první faktuře projektu (nebo
  o pár dní dřív), ukončení k datu dodávky.
- Tvar v příběhu navrhne implementace (např. `projects` a odkaz `project`
  u ad hoc faktury a u řádku nákupu).
- Souhrn ekonomiky z `build.py` vypíše výnosy, náklady a marži projektů.
  Projekty mají kladnou marži, cíle D25 platí dál.

## Kroky (commity)

1. **Setup (D29)** — druhy a řady zakázek, cizí klíč přes přirozený klíč;
   PHPUnit: dump a seed FK (druh, účet, řada dokladů), nedohledatelný
   a neunikátní kód řady dokladů = chyba.
2. **Sekce `workOrders/` (D30, D31)** — schéma obálky, exportér, seeder
   zakázek a evidence období, pořadí v seed a dump příkazu, přenosná
   dimenze v `DocumentExporter`; PHPUnit: round-trip zakázky s řádky
   s platností a evidencí, `content_hash`, dimenze na dokladu.
3. **Generátor: periodické zakázky (D32–D34)** — zakázky, faktury
   z předpisu, evidence období; unit testy: štítek období cs / sk / de,
   čtvrtletí, změna ceny, úplnost evidence nad výchozí i posunutou kotvou.
4. **Projekty (D36)** — příběh, dimenze na fakturách a nákupu, souhrn
   ekonomiky; unit testy.
5. **Sada a dokumentace** — přegenerovaná sada, ověření,
   `docs/datasets.md` (setup, sekce `workOrders/`, round-trip),
   `docs/work-orders.md` (odkaz na sadu), `demo/datasets/README.md`
   (zakázky, D35), `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

Čerstvý volný zdroj dat, `dataset-seed`, `dataset-dump`, `diff -r` jako
v 2a (jen `manifest.created`), a dál:

- seed bez nových varování;
- `work-orders-invoice-run --dry-run --date=<kotva>`: nic k vystavení;
- pro každé vystavené období: `InvoiceBuilder` nad zakázkou a obdobím dá
  stejný text dokladu, data, období, měnu, dimenzi a řádky (popis,
  množství, cena, kód DPH) jako naseedovaný doklad — skriptem nebo
  integračním testem, výsledek do Odchylek;
- v aplikaci: tab Fakturace (evidence, koncepty v měsíci kotvy), Deník
  periodické zakázky a obou projektů (výnosy i náklady), filtr Zdroj ve
  vydaných fakturách, upozornění na koncepty ke kontrole;
- saldokonto a banka: stejné kategorie případů jako po D25–D28, počty do
  Odchylek;
- Výsledovka odpovídá souhrnu, D25 splněno.

Ověřovací zdroj dat po kontrole smaž, nebo ho nech Anně a zapiš prefix.

## Pasti

- **Štítek období z ICU.** `PeriodLabel` formátuje měsíc přes
  `IntlDateFormatter` vzorem `LLLL yyyy` (samostatný tvar měsíce).
  Generátor v Pythonu musí trefit přesně stejný text v cs, sk i de —
  ověřit proti PHP, ne odhadem. Jinak se rozejde až další období vystavené
  během.
- **Jazyk dokladu** určuje `DocumentLanguageResolver` podle osoby a země;
  štítek i text dokladu se počítají v jazyce dokladu.
- **Ukončená zakázka a dimenze.** Projekty jsou Ukončeno a doklady se
  seedují až po zakázkách. Ověř, že applier dohledá dimenzi i u ukončené
  zakázky; pokud ne, seeder nastaví cílový stav až po dokladech (do
  Odchylek).
- **Zámek řádků předpisu** u potvrzené zakázky — applier importu zakládá
  řádky v Konceptu; seeder řádky mimo applier nepíše.
- **Zástupné číslo konceptu.** Evidence období u konceptu odkazuje na
  `!` + id; generátor ho předpočítá jako u ostatních konceptů
  (`docs/datasets.md` §4). Změna pořadí dokladů ho posune.
- **Posun kotvy** nesmí rozbít úplnost evidence ani „fakturovat od“
  u čtvrtletních zakázek (posun o jiný počet měsíců než násobek tří).
- **Přijaté faktury:** dimenze je jen v zaúčtovaném dokladu, ne v datech
  zprávy. Doplnění routeru na fakturu velkoobchodu přegeneruje její PDF
  a zprávu, to je očekávané.
- **Fiktivní data** platí dál (žádné reálné subjekty, telefony `000`).

## Hotovo když

- [x] druhy a řady zakázek v `setup/`, FK přes přirozený klíč, testy
- [x] sekce `workOrders/` s evidencí období, dump → seed → dump shodný
- [x] dimenze zakázky na dokladech přežije round-trip
- [x] 13 periodických zakázek, faktury shodné s `InvoiceBuilder`, běh
      `--dry-run` ke kotvě nevystaví nic
- [x] dva projekty Ukončeno s výnosy i náklady v Deníku, kladná marže
- [x] souhrn ekonomiky splňuje D25, test cílů projde
- [x] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [x] ověření podle oddílu Ověření, výsledky v Odchylkách
- [x] dokumentace aktualizovaná (D35 v `demo/datasets/README.md`)
- [x] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Odchylky a zjištění při implementaci (2026-10-21)

- **D37 — řada dokladů složeným klíčem.** Výchozí řady z provisioneru mají
  `doc_number_code = NULL`, kódem by je setup nedohledal; `inv_number_series`
  druhu a zakázky se přenáší jako `{docType, code}` (viz D29). Překlad všech
  FK v `setup/` i v sekci `workOrders/` dělá jedna třída
  `SetupReferences` (`modules/core/exchange/src/Dataset/`): tabulka ze
  `SetupExporter::TABLES` s jednosloupcovým klíčem → hodnota klíče, řada
  dokladů → objekt, účet osnovy číslem jako dřív (chybějící = `null`,
  beze změny chování), ostatní FK se dál vynechávají; nenalezený nebo
  nejednoznačný klíč = chyba řádku.
- **Verifier importu zakázek omezoval kódy DPH na místo plnění
  `domestic`** (`WorkOrderImportVerifier::vatCodes`, `getVatCodes(…,
  'domestic')`), takže předpis zahraničního odběratele s kódem EU služeb
  `cz-202` — který formulář řádků podle zákazníka i applier dokladů
  přijímají — odmítl (`vat_code_unknown`); první seed tak zahodil tři
  zakázky a 24 jejich faktur (`dimension_not_found`). Opraveno na kódy
  země registrace pro směr dokladu bez ohledu na místo plnění (import
  nenabízí, ověřuje); zapsáno v `docs/exchange-format.md` § Zakázky. Je to
  chyba importu, ne změna periodické fakturace.
- **Evidence období = samostatný seeder** `WorkOrderPeriodsSeeder` (sekce
  `workOrderPeriods` bez souborů a `counts`, jako `fiscalYears`) za
  `docs/`; `content_hash` počítá nová `ContentHash::ofStoredDocument`
  (přesun těla `InvoicingRunService::contentHashOf`, seam zůstal).
- **Dimenze zakázky na dokladech:** `DocumentExporter` dostal parametr
  `$sectionTables` (dump předá `economy_work_orders_heads`, když DS tabulku
  má) — přenosné jsou dimenze s cílovou tabulkou v `setup/` nebo ve vlastní
  sekci. `DimensionResolver` najde i ukončený projekt (vylučuje jen koš),
  takže past „ukončená zakázka a dimenze“ nenastala; deník dědí dimenzi
  hlavičky do řádků bez vlastní, proto faktury (paušály i projekty) nesou
  dimenzi jen na hlavičce — přesně jako `InvoiceBuilder` — a nákup jen na
  řádcích (switch, router, přístupové body).
- **Strana zakázky v obálce** je štíhlá identita (název, země, IČO, DIČ,
  VAT ID) — osoba je v `persons/`; seed ji dohledá jen identifikátory.
- **Texty paušálů:** popis řádku předpisu je statický v jazyce dokladu
  (cs „Správa IT — měsíční paušál“, sk, de z příběhu
  `invoicing.workOrders.rowText`), období nese `docText` z šablony
  (`docText` s `{období}`, štítek jako `PeriodLabel`). Tím se změnil popis
  řádku a přibyl `docText`, `periodFrom` / `periodTo`, `source` a
  `dimensions` na všech 112 paušálových fakturách sady; částky beze změny.
  Štítky měsíců pro cs / sk / de / en ověřené proti PHP 8.5 / ICU 74.2 (test
  `test_workorders.py` je nese jako pevné řetězce).
- **Zahájení zakázky** v příběhu jako posun měsíce (`since: -30`), ne
  absolutní datum — posun kotvy ho posune s sebou; u čtvrtletní zakázky se
  zarovná na začátek čtvrtletí. Řady `S` a `P` průběžné (`reset_scope
  none`), takže zahájení v letech 2022–2025 nepotřebuje fiskální roky
  (`FiscalYearsSeeder` beze změny).
- **Projekty (D36)** v příběhu jako blok `projects {kind, series, items[]}`
  s odkazem `project` u ad hoc faktur a `purchases {supplier, month,
  rows[]}`: množství nákupu = kusy prodané na fakturách projektu, běžný
  řádek dodavatele se stejným textem se v tom měsíci nahradí (switch 1 → 2,
  AP → 4), router přibyl (4 490 Kč); `row_index` ostatních řádků zůstává,
  takže determinismus množství (`spread_decimal`) se nezměnil. Dodávka
  Wi-Fi posunuta z 3. na 18. 6. (za nákup 15. 6.) — tím se přečíslovaly
  červnové vydané faktury a posunuly jejich úhrady; řádky hodin projektů
  dostaly vlastní text (`text` u řádku `hours`). Zakázky Ukončeno:
  „Výměna sítě“ 2026-03-17 → 2026-04-22, „Wi-Fi síť hotelu“ 2026-05-17 →
  2026-06-18.
- **Nastavení** `economy.accounting.dimension.workOrder: yes` v `setup/`,
  aby pole Zakázka bylo na formulářích dokladů vidět.
- **Ekonomika (D25 dál platí):** výnosy 2 089 121, náklady 1 835 345
  (dodavatelé +16 070 za router, switch a dva přístupové body), zisk
  +253 776 = **12,1 %**, zahraničí 17,0 %, zboží prodej 317 801 / nákup
  233 808 (1,36×); projekty: Výměna sítě výnosy 38 040 / náklady 17 290
  (54,5 %), Wi-Fi 35 660 / 10 360 (70,9 %). BU1 300 000 → minimum
  275 316 → 580 868.
- **Ověření** (čerstvý volný DS `joqf-v…`, ponechán Anně k ručnímu
  průchodu v aplikaci): seed bez nových varování (jen účetní položky
  z nabídky a dvě chybějící IČO zahraničních firem), `diff -r` jen
  `manifest.created`; `work-orders-invoice-run --dry-run --date=2026-10-20`
  = 0 k vystavení, 0 zablokováno pojistkou; skript nad `InvoiceBuilder`
  pro 13 zakázek × 112 vystavených období: text dokladu, data, období,
  měna, dimenze, zdroj i řádky (popis, množství, cena, kód DPH, položka)
  shodné, 0 neshod; 15 zakázek (13 V pořádku periodických, 2 projekty
  Ukončeno), čítače S = 13, P = 2, 112 období `issued` s dokladem i otiskem
  (2 koncepty měsíce kotvy), 112 dokladů se zdrojem *periodická
  fakturace*, 116 s dimenzí na hlavičce + 3 řádky nákupu; deník 1 358
  řádků, MD = D, 0 chybových, 322 řádků se zakázkou (15 zakázek), deník
  projektů: P0001 výnosy 6xx 38 040 / náklady 5xx 17 290, P0002 35 660 /
  10 360; 234 transakcí zaúčtovaných bez chyby; 261100, 261300, 331 / 336 /
  342 na nule, 343217 MD = 343207 D (9 797,37); 221100 = 580 868,11 =
  closing BU1, 221200 = 2 867,25 EUR = closing BU2; saldokonto Pohledávky
  129 případů — 112 uzavřených, 17 otevřených (16 po splatnosti, 1 před
  splatností v EUR), Závazky 58 uzavřených + 1 se splatností za kotvou,
  Přijaté zálohy 1 otevřená, Nespárované platby 3 záměrné + případ
  s prázdným klíčem uzavřený; Výsledovka Od počátku roku: náklady
  1 835 346,56, výnosy 2 089 127,49 → +253 780,93 (haléřová zaokrouhlení
  prodejek jako dřív); upozornění `economy.workorders.invoices_to_review`
  1 nález (koncepty ke kontrole), `period_failed` / `catchup_blocked` /
  `period_waiting` 0. Round-trip integrační test (`DatasetRoundTripTest`)
  kryje setup druhu a řady, periodickou zakázku s řádky s platností
  a obdobím na vydanou fakturu s dimenzí a `content_hash`.
- Ruční průchod v aplikaci (Zakázky 13 + 2, tab Fakturace s koncepty
  měsíce kotvy, Deník paušálu i projektů, filtr Zdroj ve vydaných
  fakturách, upozornění na koncepty) — Anna na DS `joqf-v…`; po kontrole
  `shpd-server ds-delete`.

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D19** — sekce `workOrders/` s přirozenými klíči, řádky s platností,
  evidence období, dimenze zakázky na fakturách, bez periodického běhu
  při seedu
- ✓ **D29** — druhy a řady zakázek v `setup/`, cizí klíč přes přirozený
  klíč cílové tabulky
- ✓ **D30** — obálka `shpd.dataset.workOrder.v1`, zápis přes
  `WorkOrderImportApplier`, `content_hash` počítá seeder
- ✓ **D31** — pořadí zakázky → doklady → evidence období; dimenze zakázky
  přenosná
- ✓ **D32** — paušály = periodické zakázky, faktury jako z periodické
  fakturace, částky beze změny
- ✓ **D33** — bez pevného VS
- ✓ **D34** — zahájení před oknem, řady bez ročního restartu
- ✓ **D35** — cron po seedu jen dokumentovat
- ✓ **D36** — dva projekty (výměna sítě, Wi-Fi) s výnosy i náklady
- ✓ **D37** — řada dokladů v `setup/` složeným klíčem `{docType, code}`
  (výchozí řady z provisioneru jsou bez kódu); nejednoznačná nebo chybějící
  řada = chyba seedu
