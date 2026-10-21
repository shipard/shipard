# Demo sada „IT servis“ — fáze 2b: zakázky

**Stav:** naplánováno — #131 D19, D29–D36

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

- [ ] druhy a řady zakázek v `setup/`, FK přes přirozený klíč, testy
- [ ] sekce `workOrders/` s evidencí období, dump → seed → dump shodný
- [ ] dimenze zakázky na dokladech přežije round-trip
- [ ] 13 periodických zakázek, faktury shodné s `InvoiceBuilder`, běh
      `--dry-run` ke kotvě nevystaví nic
- [ ] dva projekty Ukončeno s výnosy i náklady v Deníku, kladná marže
- [ ] souhrn ekonomiky splňuje D25, test cílů projde
- [ ] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [ ] ověření podle oddílu Ověření, výsledky v Odchylkách
- [ ] dokumentace aktualizovaná (D35 v `demo/datasets/README.md`)
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

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
