# Shipard — Reporty (doména `report`)

**Designový dokument.** Reporty = datové výstupy: komplexní čtení z dat,
agregace a prezentace (typicky tabulka, výhledově grafy a infografika).
Zakládá celou doménu reportů; první tři reporty jsou **hlavní kniha,
výsledovka a rozvaha** (interní podoba) — kontrolní protějšek DPH výstupů
z milníku M1 a validace importu ze starého Shipardu (M3).

> **Stav:** Fáze 1 hotova (2026-08-21, `tasks/reports-phase1.md`) — jádro
> `src/Core/Reports/`, klíč `reports` v module.jsonc, hlavní kniha
> (`GeneralLedgerBuilder`), REST `GET /_reports[/{reportId}]`. Rozhodnutí
> D1–D16 potvrzena (GitHub issue #42 + komentář). Fáze 2 hotova
> (2026-08-21, `tasks/reports-phase2.md`) — výsledovka
> (`ProfitLossBuilder`) a rozvaha (`BalanceSheetBuilder`, invarianty D15)
> nad sdíleným `JournalReportSupport`. Fáze 3 hotova (2026-08-21,
> `tasks/reports-phase3.md`) — skupina Reporty v navigaci, `ReportsPage`
> + `PeriodPicker` + `ReportView`, deep-link přes query string. Fáze 4
> hotova (2026-08-21, `tasks/reports-phase4.md`) — MCP tooly `report_list`
> + `report_run`, `ReportDiff` + CLI `report-run`/`report-diff`; plán
> domény z issue #42 tím je uzavřen (validace importu čeká na exportér
> v `old_shipard`, kontrakt §7.4). Upřesnění tvaru dle implementace:
> §10, §11, §12, §13. Export do XLSX a CSV hotov (2026-10-01,
> `tasks/reports-export.md`, #83 D72) — §15. Obecné parametry, seskupení
> podle klíče, identita řádku a drill-down (2026-10-01,
> `tasks/assets-phase5.md`) — §16.

---

## 1. Terminologie a vymezení domény

Nový Shipard rozlišuje tři domény výstupů (D1, D2):

| Doména | CZ | Co to je | Klíčová vlastnost |
|---|---|---|---|
| `print` | tisk | tisková podoba dokladu (faktura, dodací list) | 1 záznam → PDF ven z firmy |
| `report` | report | datový výstup (hlavní kniha, výsledovka, …) | data → strukturovaný výsledek → obrazovka/API |
| `filing` | podání / výkaz | úřední výstup (přiznání DPH, výkazy dle vyhlášky) | data → formát úřadu, má lifecycle (sestavit → podat → zamknout) |

Slovo **„sestava" se nepoužívá** — bylo zdrojem chaosu starého Shipardu.

**Print a report jsou oddělené subsystémy.** Žádná sdílená hierarchie tříd,
žádné dědění mezi nimi (poučení ze starého Shipardu, kde provázané třídy
vytvářely zmatek). Sdílet mohou nanejvýš infrastrukturu úplně dole
(PDF rendering služba #34 — pro reporty až budoucí rozšíření, §8).

`filing` je v tomto dokumentu jen vymezen; „státní" výkaz zisku a ztráty
či rozvaha dle vyhlášky 500/2002 **nejsou pohledy reportu**, ale budoucí
samostatná doména s vlastními položkami v navigaci. První reálný `filing`
žije v modulu `economy.vat` (podání DPH — snapshot s lifecyclem, druhy
podání, zaokrouhlení podaných hodnot; issue #55 Fáze 2, viz
[modules/economy/vat/docs/README.md](../modules/economy/vat/docs/README.md)).
Ukazuje i hranici obou domén: živý report je pořád přesný a přepočtený,
podání drží zaokrouhlené hodnoty z okamžiku sestavení.

---

## 2. Princip: jeden výpočet, N prezentací

Klíčové architektonické rozhodnutí (D4): report engine produkuje
**výhradně strukturovaný `ReportResult`** (JSON). Všechno ostatní jsou
renderery nad ním:

```
ReportBuilder ──► ReportResult (JSON)
                     │
                     ├── Svelte ReportViewer (UI)
                     ├── REST endpoint (vrátí JSON tak jak je)
                     ├── MCP tool (AI asistent)
                     ├── diff dvou výsledků (kontrola importu, M3)
                     ├── export do XLSX / CSV (§15)
                     └── (budoucí) tisk: HTML šablona → PDF služba #34
```

Důsledky:

- **Mezisoučty počítá engine, nikdy UI.** API klient a UI nesmí mít šanci
  ukázat různá čísla.
- Prezentační volby (znaménka, zaokrouhlení na tisíce v zobrazení) jsou věc
  rendereru; data nesou vždy plnou přesnost a strany účtu (§3.2).
- Stejný vzor jako one-LLM-call princip v AI analýze: spočítej jednou,
  předej strukturu, prezentace se odvozuje.

---

## 3. `ReportResult`

### 3.1 Tvar (D5)

Samonosný JSON: metadata + definice sloupců + **plochý** seznam řádků.
Žádný strom — plochý seznam s `level` se triviálně renderuje, diffuje
i čte AI asistentem.

```json
{
  "reportId": "economy.reports.profitLoss",
  "params": {"period": {"fiscalYear": 2026, "monthFrom": 5, "monthTo": 5}, "detail": "analytic"},
  "generatedAt": "2026-08-21T12:52:46+02:00",
  "dataSource": "dtje-3qu7-3iof-5imh",
  "status": "ok",
  "messages": [],
  "columns": [
    {"id": "month",   "type": "money", "label": "Měsíc"},
    {"id": "year",    "type": "money", "label": "Rok"}
  ],
  "rows": [
    {"kind": "detail",   "level": 3, "account": "501001", "label": "Spotřeba materiálu",
     "values": {"month": {"md": 59281.34, "d": 0, "balance": 59281.34},
                "year":  {"md": 419454.36, "d": 0, "balance": 419454.36}}},
    {"kind": "subtotal", "level": 2, "account": "501", "label": "Spotřeba materiálu", "values": {"…": "…"}},
    {"kind": "subtotal", "level": 1, "account": "50",  "label": "Spotřebované nákupy", "values": {"…": "…"}},
    {"kind": "computed", "level": 1, "account": null,  "label": "Výsledek hospodaření běžného období", "values": {"…": "…"}},
    {"kind": "total",    "level": 0, "account": null,  "label": "Celkem", "values": {"…": "…"}}
  ]
}
```

- `kind`: `detail | subtotal | total | computed`.
- `level`: hloubka řádku (analytika → syntetika → skupina → třída → celkem);
  renderer z něj odvozuje odsazení a zvýraznění.
- Přesný seznam polí řádku doladí PRD (např. `accountId` vedle
  `account_number` pro drill-down do deníku).

### 3.2 Hodnoty per strany účtu (D6)

Každá buňka nese `md`, `d` a `balance` (zůstatek dle strany účtu).
**Znaménková prezentace je věc rendereru** — starý Shipard zobrazoval
náklady mínusem (aby se výsledek „sečetl sám"), což polovina uživatelů
nechápala; data nového systému žádnou takovou konvenci nezakódovávají.

### 3.3 Stav a zprávy (D15)

Ve starém Shipardu byly chyby prezentační záležitost: červené řádky
+ `addMessage(...)` štosující chyby a warningy „pod čarou" reportu.
To stačí pro oči, ne pro stroje. `ReportResult` proto nese **tvrdý aparát**:

- `status`: `ok | warnings | errors` — odvozený z nejvyšší severity zpráv,
- `messages`: seznam `{severity: error|warning|info, code, text, rowRef?}` —
  `code` je strojově čitelný (např. `journal.accountNotFound`,
  `balanceSheet.notBalanced`, `balanceSheet.balanceOnWrongSide`),
  `text` lidský, `rowRef` volitelná vazba na řádek.

Závazná pravidla pro konzumenty:

- **Renderer**: červené řádky a zprávy pod čarou — prezentace nad daty,
  jako dřív.
- **MCP / AI asistent**: výsledek se `status: errors` se nesmí mlčky
  použít jako spolehlivá čísla — asistent chybu ohlásí (a případné
  odpovědi z takových dat explicitně označí). Tool `report_run` tuto
  sémantiku deklaruje v popisu.
- **Diff** (§7.4): porovnání výsledku s `errors` odmítne nebo výsledek
  zřetelně označí — diff „skoro správných" čísel je horší než žádný.

### 3.4 Rozvaha a dopočtené řádky (D13)

Rozvaha potřebuje výsledek hospodaření běžného období jako řádek v pasivech,
který v deníku neexistuje (dopočet z tříd 5/6). Je to regulérní součást
datového modelu — `kind: computed` — ne hack v rendereru. Kontrolní
invarianty rozvahy: **aktiva = pasiva** a **zisk z rozvahy = zisk
z výsledovky**; jejich porušení okamžitě signalizuje nevyrovnané deníky.

---

## 4. Deklarativní definice reportu (D7)

Report deklaruje: `id`, název, **schéma parametrů** a podporované
granularity období. Z jediné deklarace se odvodí:

- toolbar parametrů v UI,
- validace parametrů na REST API,
- `inputSchema` MCP toolu.

Výpočet = PHP třída `ReportBuilder` per report; dostane validované parametry,
vrátí `ReportResult`.

**Forma deklarace: JSONC cfgItem** (D16) — konzistentní s modulovým
systémem (i18n přes `name:cs`, kompilace konfigurace, `ds-upgrade` po
změně). Deklarace odkazuje na `ReportBuilder` třídu. Zůstává jediným
místem, ze kterého se odvozuje toolbar, validace i MCP schéma.

### 4.1 Parametry v1 (D9)

Všechny parametry žijí v horní liště; v1 **nemá** pravý pruh „pohledů":

- **období** (§5) — povinné u všech tří reportů,
- **úroveň detailu**: analyticky / synteticky (mění sadu řádků výsledku),
- **formát**: přesně / v tisících (u výsledovky a rozvahy).

„Operativní vs. státní" přepínač starého Shipardu se nepřenáší — státní
podoba je doména `filing` (§1).

---

## 5. Období (D8)

Zdroj pravdy: fiskální období (`economy_codebooks_fiscal_years` /
`_fiscal_months`); každý řádek deníku nese `fiscal_year` + `fiscal_month`
(viz `accounting.md` §6).

- **Picker**: roky × měsíc / čtvrtletí / pololetí / rok — převzatý vzor ze
  starého Shipardu. Report deklaruje, které granularity podporuje; picker
  nenabízí nesmysly.
- **Interně** se výběr vždy přeloží na **interval fiskálních měsíců od–do**.
  Engine zná jen tento jeden tvar.
- Počáteční stavy jdou z účetních dokladů (otevírací doklad, účet 701) —
  jsou v deníku jako každé jiné účtování, engine je **nedopočítává** (D3).

---

## 6. Zdroj dat a výpočet

- Jediný zdroj: `economy_accounting_journal` (D3). Reporty nesahají na
  doklady ani transakce — stejný princip jako saldokonto (`accbal.md` §1.2):
  každý budoucí zdroj účtování nakrmí reporty bez změny jejich kódu.
- **Výjimka: reporty nad vlastní evidencí** (#83 D65). Přehledy majetku
  (`economy.assets.*`) čtou karty, události a plány odpisů, ne deník —
  daňové hodnoty v deníku nejsou a plán odpisů v něm být nemůže. Deník
  u nich slouží **kontrole** (report `economy.assets.journalCheck`
  porovnává evidenci s deníkem). Pravidlo D3 dál platí pro účetní reporty;
  report nad jinou evidencí musí tuto odchylku uvést v deklaraci
  a v dokumentaci modulu (`docs/assets.md` §5.6).
- **v1 počítá vždy živě** (D12) — agregační dotaz nad deníkem s indexem
  (`fiscal_year`, `fiscal_month`) je levný a odpadá invalidace.
- Řádky s `is_error = 1` (nedohledaný účet): report je nesmí tiše
  zahodit — vstoupí do výsledku jako samostatný řádek a vygenerují
  zprávu se severity `error` (§3.3, D15) — failing loudly.

---

## 7. Prezentace

### 7.1 UI (D10)

- Navigace: podsekce **Reporty** v sekci Účtárna.
- Jedna generická stránka `ReportViewer` parametrizovaná `reportId`.
- **Parametry v URL** — deep-link „výsledovka 2026/5 v tisících" jde poslat
  kolegovi.
- Drill-down z řádku do deníku (filtr účet + období) — přirozený krok,
  rozsah určí PRD. Obecný mechanismus odkazů řádku: §16.5.

### 7.2 REST API

Endpoint (návrh, doladí PRD): `GET /_reports/{reportId}?…parametry…`
→ `ReportResult` beze změn. Validace parametrů z deklarace (§4).

### 7.3 MCP (D11)

Dva **generické** tooly — počet toolů neroste s počtem reportů:

- `report_list` — katalog: id, název, popis, schéma parametrů,
- `report_run(reportId, params)` — vrátí `ReportResult`.

AI asistent tak dostane konzistentní tvar napříč reporty; „porovnej
výsledovku Q1 a Q2 a najdi největší rozdíly" funguje pro každý report
zadarmo. Sémantika chyb: viz §3.3 (D15) — `status: errors` znamená,
že čísla nejsou spolehlivá a asistent to musí ohlásit.

### 7.4 Kontrolní diff (D14)

Strojové porovnání dvou `ReportResult` (případně CSV export per účet
a období) — vedlejší produkt domény, primární využití: kontrola importu
ze starého Shipardu (stejný report za stejné období musí sedět). Využije M3.
Pozn.: starý Shipard strukturovaná data reportů dnes negeneruje; půjde
snadno dodělat, znaménkovou konvenci srovná diff vrstva.

Implementace (Fáze 4): `Shipard\Core\Reports\ReportDiff` — čistá třída
bez DB nad dvěma dekódovanými `ReportResult` array. Porovnávají se pouze
řádky `kind: detail` (klíč = `account`) a jako kontrolní součet řádky
`kind: total` (klíč = `label`, jen labely přítomné na obou stranách);
subtotaly a computed se ignorují — derivují z detailů, stará strana je
mít nemusí. Sloupce se porovnávají v průniku dle `id` (jednostranné →
`columnsOnlyInA/B`, warning), takže strany nemusejí mít shodné `reportId`
ani stejnou sadu sloupců. Tolerance 0,005. Výstup: `{identical,
differences: [{account, column, field (md|d|balance), a, b, delta}],
onlyInA, onlyInB, columnsOnlyInA/B, statusA, statusB}`.

**Kontrakt exportu staré strany** (navazující task v `old_shipard`):
exportér agregovaného deníku produkuje minimální `ReportResult`-kompatibilní
JSON — diff nic víc nepotřebuje:

```json
{
  "reportId": "external.oldShipard.generalLedger",
  "params": {},
  "status": "ok",
  "messages": [],
  "columns": [{"id": "opening"}, {"id": "turnover"}, {"id": "closing"}],
  "rows": [
    {"kind": "detail", "level": 4, "account": "311001",
     "label": "Odběratelé", "values": {"closing": {"md": 0, "d": 0, "balance": 0}}}
  ]
}
```

`params` jsou volné, subtotaly žádné, `total` volitelný. Přesnou sadu
sloupců určí task v `old_shipard` podle možností staré DB — minimálně
`closing` k datu; id sloupců musí odpovídat nové straně
(`opening`/`turnover`/`closing` hlavní knihy), jinak se hodnoty
neporovnají (skončí v `columnsOnlyIn*`).

---

## 8. Budoucí rozšíření (mimo rozsah v1)

- **Materializace výsledků pro uzamčená období** — vypočítat a persistovat
  při uzamčení období (M1); invalidace odpadá, protože zamčené období se
  nemění. Definováno jako rozšíření **vázané na zámek období, ne jako cache
  s invalidací** — otevřená období se nikdy nematerializují (D12).
- **Doména `filing`** — přiznání DPH, kontrolní hlášení, výkazy dle
  vyhlášky 500/2002 (plný/zkrácený rozsah, sloupec minulého období).
- **Tisk reportů** — `ReportResult` → HTML šablona → PDF služba (#34).
- **Grafy a infografika** — další renderery nad `ReportResult`.
- Další reporty: pokladní kniha, cash flow, přehledy výnosů/nákladů, …

---

## 9. Vztah k milníkům

- **M1**: hlavní kniha je kontrolní protějšek přiznání k DPH — čísla na
  sebe musí sedět. Rozvaha agregovaně odhalí nevyrovnané deníky (známý
  backlog ~1 200+ dokladů s imbalancí nad 100 Kč).
- **M3**: kontrolní diff (§7.4) dává rychlou validaci importu.

Reference: GitHub issue #42, `accounting.md` (deník), `accbal.md` §1.2
(vzor „jen deník"), `tasks/pdf-rendering-service.md` (#34).

---

## 10. Upřesnění z implementace Fáze 1

Body, kde implementace zpřesnila návrh (tvar §3.1 platí beze změn):

- **Parametry období na API**: `fiscalYear` = **název** fiskálního roku
  (unikátní `name`, např. `2026`); `monthFrom`/`monthTo` = **pořadí běžného
  fiskálního měsíce v roce** (1-based dle `date_begin`; u kalendářního roku
  shodné s kalendářním měsícem). Interval musí odpovídat některé deklarované
  granularitě (zarovnané čtvrtletí/pololetí/měsíc/rok) — API drží stejnou
  hranici jako budoucí picker (D8).
- **`FiscalRange`** vzniká výhradně v `ReportParamValidator` a nese už
  přeložené FK id fiskálních měsíců (`monthIdsBefore` vč. otevíracího
  období `period_type = 0`, `monthIdsInRange`) — buildery na číselníky
  znovu nesahají. Fiskální období čte `FiscalPeriodProvider`
  (DB implementace + in-memory fake pro unit testy).
- **Řádky výsledku**: `level` — total 0, třída 1, skupina 2, syntetika 3,
  analytika 4 (v syntetickém režimu detail = 3). `rowRef` zpráv má tvar
  `rows.{index}` do plochého seznamu řádků.
- **Chybové masky** (`is_error = 1`, např. `504???`): samostatný detail
  řádek s maskou jako label, v syntetickém režimu se **neagregují** na
  prefix; do mezisoučtů a totalu ale vstupují (total = suma deníku, sedí
  na SQL agregaci).
- **REST**: odpověď jede ve standardní API obálce `{success, data}`,
  `data` = `ReportResult::toArray()` beze změn. Chybové kódy:
  `REPORT_NOT_FOUND` (404), `BAD_REQUEST` (400, text z validatoru).
  Katalog `GET /_reports` vrací `{items: [{id, name, periodGranularities,
  params}]}`.
- **Umístění builderu**: `modules/economy/accounting/src/Reports/`
  (namespace `Shipard\Module\Economy\Accounting\Reports`), deklarace
  `config/reports.jsonc` + klíč `reports` v module.jsonc
  (`[{"file": "config/reports.jsonc"}]`).

## 11. Upřesnění z implementace Fáze 2

- **Sdílené helpery**: `JournalReportSupport` (agregace deníku per fiskální
  měsíce s volitelným filtrem tříd — seznam povolených prvních znaků čísla
  účtu, platí i pro chybové masky —, názvy z rozvrhu, chybové zprávy).
  Buildery ho skládají, žádná dědičnost mezi nimi (duch D1).
- **Výsledovka** (`economy.accounting.profitLoss`): třídy 5/6, sloupce
  `period` (obraty intervalu) a `ytd` (od začátku fiskálního roku do konce
  intervalu) — uzavírá otevřený bod Fáze 1, odpovídá sloupcům „Měsíc / Rok"
  starého Shipardu. Místo generického totalu `computed` řádek „Výsledek
  hospodaření za období" (level 0, `balance` = výnosy − náklady, kladné =
  zisk; `md`/`d` = 0 — výsledek není obrat stran).
- **Rozvaha** (`economy.accounting.balanceSheet`): třídy 0–4, sloupce
  `opening`/`closing`. Zařazení do sekcí Aktiva/Pasiva **per analytický
  účet**: `account_kind` 0/1 přímo, kind 5 (aktivně pasivní), NULL či jiný
  dle znaménka closing balance (≥ 0 Aktiva) — zjednodušení v1, opening
  strana se může lišit. Syntetické slučování až uvnitř sekce (analytiky
  téhož syntetického účtu mohou skončit v opačných sekcích). Na skupinových
  řádcích rozvrhu je `account_kind` nespolehlivý — kind se čte jen
  z analytik.
- **Sémantika znaménka v pasivní sekci**: builder otáčí `balance` všech
  řádků pasiv (detail, subtotal, total), `md`/`d` zůstávají syrové. Není to
  porušení D6 — builder definuje sémantiku sloupce své sekce, renderer nic
  nedopočítává. `computed` řádek „Výsledek hospodaření běžného období"
  (vzorec ytd výsledovky; `opening` k začátku intervalu) je poslední
  položkou pasiv a vstupuje do „PASIVA CELKEM".
- **Invarianty D15 v rozvaze**: `AKTIVA CELKEM == PASIVA CELKEM`
  a vyrovnanost deníku (Σ syrových balance tříd 0–4 + tříd 5–6 == 0),
  obojí per sloupec s tolerancí 0,005. Porušení → `ReportMessage` error
  `balanceSheet.notBalanced` / `balanceSheet.journalImbalance` (v textu
  sloupec a rozdíl), report se vrátí i tak (`status: errors`, HTTP 200).

## 12. Upřesnění z implementace Fáze 3

- **Katalog `GET /_reports`** nese vedle `items` i `periods:
  {fiscalYears: [{name, months}]}` — data pro picker období, jednou pro
  celou odpověď. `name` je **string** (shoda s parametrem `fiscalYear`
  a sloupcem `name` číselníku; PRD ukázka měla number), `months` = počet
  běžných měsíců (`period_type` 1). Zdroj `FiscalPeriodProvider::
  regularYears()`.
- **`ReportColumn.display`**: zobrazovací hint `'balance'` (default; jedna
  hodnota Zůstatek) | `'sides'` (trojice MD / D / Zůstatek), v `toArray()`
  vždy přítomen. Hlavní kniha: `turnover` → `sides`; ostatní sloupce všech
  reportů `balance`. Data nesou vždy všechny tři hodnoty (D6) — hint řídí
  jen renderer.
- **Navigace z deklarace (duch D7)**: report deklarace nese volitelné
  `navSection` + `navOrder`; `NavigationController::collectReportItems()`
  z nich staví skupinu `{id: 'reports', label: Reporty/Reports, children}`
  v cílové sekci. Child = `{id: 'report:<reportId>', type: 'panel',
  panelId: 'reports', panelParams: {reportId}, icon: 'chart'}` — jedna
  generická komponenta `ReportsPage` parametrizovaná přes `panelParams`.
  Panel `reports` v module.jsonc je bez `navSection` (do navigace vstupují
  jen per-report children). Selhání loaderu deklarací navigaci neshodí.
- **Frontend** (`frontend/src/components/reports/`): `ReportsPage`
  (stav parametrů per report v session mapě, default = poslední celý
  měsíc), `PeriodPicker` (mřížka roky × měsíc | čtvrtletí | pololetí |
  rok dle granularit deklarace), `ReportView` (čistý renderer
  `ReportResult`; červené řádky dle `rowRef`, messages pod čarou,
  přepínač „V tisících" dělí 1000 jen při renderu — D6).
- **Deep-link (D10)**: `?report=<id>&fy=<rok>&mf=<od>&mt=<do>&detail=<d>`
  (parametry deklarace obecně pod svým id, §16.1)
  — `history.replaceState` při každé změně parametrů, žádný router.
  Při startu aplikace se query parsuje v `main.js` (URL se nečistí,
  na rozdíl od auth větví) a po loadu navigace se aktivuje leaf
  `report:<id>`; neznámé id = normální start. „V tisících" do URL
  nepatří (čistě vizuální volba). Odchod ze stránky reportu query uklidí.

---

## 13. Upřesnění z implementace Fáze 4

- **MCP tooly** (`modules/economy/accounting/src/Mcp/`): `report_list`
  (katalog + `fiscalYears: [{name, months}]` per položka, jednou spočtené)
  a `report_run` (`{summary, report: ReportResult::toArray()}`; `summary`
  nese název, období a status — model ho nepřehlédne bez čtení JSONu).
  Registrace v `buildMcpRegistry` podmíněná tabulkou
  `economy_accounting_journal`. `McpInvocationContext` nenese
  `DataSourceConfig` ani jazyk — sdílený `ReportToolSupport` je dostává
  při registraci a `ReportRegistry` staví lazily až při prvním volání.
- **Default detailu se liší per prezentace**: MCP `synthetic` (menší
  výstup pro LLM), UI a CLI `analytic` (plný detail — u CLI kvůli diffu).
  `detail` je per-report parametr — default se podsouvá jen reportům,
  které ho deklarují.
- **`fiscalYear` v MCP schema je integer** (pro model přirozené), před
  validací se kastuje na string `name` fiskálního roku — validátor
  i číselník pracují se stringem (§12).
- **CLI** (`shpd-ds`): `report-run <reportId> --fiscal-year --month-from
  --month-to [--detail] [--pretty]` — čistý JSON na stdout, poznámka
  o `status: errors|warnings` na stderr, exit 0 (D15: legitimní výsledek).
  `report-diff <fileA> <fileB>` (`-` = stdin pro jednu stranu)
  `[--strict] [--json]` — exit 0 shoda, 1 rozdíly, 2 chyba vstupu /
  strict violation (`--strict` odmítne stranu se `status: errors`;
  bez něj se statusy jen zřetelně tisknou).
- Limit velikosti výstupu `report_run` (analytická hlavní kniha za rok
  na velkém DS) se zatím řeší doporučením `synthetic` v popisu toolu;
  tvrdý limit / stránkování až podle chování na alfě.

## 14. Zdroj období `vatPeriod` + text/date sloupce (M1 — DPH, #55)

Rozšíření jádra pro živé DPH výstupy modulu `economy.vat`
(`tasks/taxes-phase01.md`):

- **`periodSource` v deklaraci reportu**: `'fiscal'` (default, beze změny)
  nebo `'vatPeriod'`. VatPeriod report nesmí deklarovat
  `periodGranularities` a **musí** deklarovat `vatReportType`
  (`return` | `cs` | `rs`) — typ instance daňového tvrzení
  (`economy_vat_report_periods`, `modules/economy/vat/docs/README.md`).
  Jediný parametr období je `period` = id instance; instance musí být
  živá a typu `vatReportType`, jinak 400 (revize #55 D11 — původní
  interval `vatRegistration` + `dateFrom`/`dateTo` s přesným pokrytím
  období zanikl spolu s původní tabulkou období DPH v economy.codebooks).
- **`VatPeriodRange`** (`periodId`, `reportType`, `periodName`,
  `registrationId`, `registrationName`, `dateBegin`, `dateEnd`, `locked`)
  je druhý tvar období vedle `FiscalRange`; `ReportRequest.range` je
  nullable a `ReportRequest.vatRange` je vyplněné právě u vatPeriod.
  `params.period` výsledku: `{period, reportType, name, vatRegistration,
  dateFrom, dateTo}`. Zdroj dat: `ReportPeriodProvider` /
  `DbReportPeriodProvider` (runner si ho self-wiruje; konstrukce bez dotazu).
  Výběr dokladů: `WHERE h.<vat|cs|rs>_period = instance` — pravidlo clamped
  DPPD žije v přiřazení při uložení dokladu, ne v SQL reportu (D8).
- **REST katalog**: položka nese `periodSource` a `vatReportType`;
  `periods.vatRegistrations: [{id, name, vatId, periods: [{id, type, name,
  dateBegin, dateEnd, locked, docState}]}]` (instance všech typů) se emituje
  **jen když** je registrovaný nějaký vatPeriod report. Query klíč runu:
  `period`.
- **CLI** `report-run`: `--period <id>`; povinnost voleb se větví dle
  deklarace, neznámý report propadá na výpis dostupných reportů. **MCP**:
  `report_run` má required jen `reportId` (`period` se vynucuje větví dle
  `periodSource`), `report_list` vrací `periodSource` + `vatReportType`
  per položku, `fiscalYears` jen fiskálním reportům a top-level
  `vatRegistrations` s instancemi (gate: vatPeriod deklarace + existence
  `economy_vat_report_periods`).
- **Frontend**: `ReportsPage` větví picker dle `periodSource` —
  `VatPeriodPicker.svelte` nabízí instance typu reportu zvolené registrace
  filtrované rokem (roletka, default rok zvolené instance); koncepty
  kurzívou, `locked` jen vizuální hint; žádné slučování kvartálů (KH
  čtvrtletního plátce má měsíční instance `cs`). Deep-link klíč `p`;
  default = poslední uzavřená instance typu. Gate „žádná instance tohoto
  tvrzení" je per typ (`reports.noVatRegistrations`).
- **Sloupce `text` a `date`** (`ReportColumn`): buňka je prostý string
  (datum ISO `YYYY-MM-DD`), `display: 'sides'` je pro ně zakázaný.
  `ReportView` je vykresluje vlevo (datum lokalizovaně), suffix
  „(v tis.)" dostávají jen money sloupce; `SubtotalAggregator` string
  buňky ignoruje. **`ReportDiff`** porovnává text/date sloupce striktně
  jako string (`field: 'value'`, `a`/`b` string, `delta: null`); chybějící
  `type` ve starém JSON exportu = money — kontrakt §7.4 drží. První
  konzument: detailní řádky kontrolního hlášení (ev. číslo, DIČ, kód PDP,
  DPPD).
- **Zámek období (`locked`)** se v reportech nevynucuje — živé výstupy
  jsou čtení; vynucení v lifecyclu dokladu je Fáze 4 dle #55.

---

## 15. Export do XLSX a CSV (#83 D72)

Export je další renderer nad `ReportResult` (§2) — obecný pro všechny
reporty, žádný builder o něm neví. PDF a tisk sem nepatří (tisková doména,
§8); export mimo reporty (viewery) také ne.

### 15.1 Stavba (`src/Core/Reports/Export/`)

```
ReportResult ──► ReportTabularizer ──► ReportTable ──┬─► ReportXlsxWriter (OpenSpout)
                 (+ ReportExportContext)             └─► ReportCsvWriter (fputcsv)
```

- **`ReportTabularizer`** je jediné místo s pravidly převodu — čistá
  funkce bez DB. `ReportTable` nese název, úvodní blok, hlavičky, řádky
  s typovanými buňkami (`string` text, `float` číslo, `DateTimeImmutable`
  datum, `null` prázdná) + `kind` a `level`, a zprávy s indexem řádku.
  Writery jen zapisují, pravidla se mezi formáty neliší.
- **`ReportExportContext`** dodává, co výsledek o sobě neví: lidský název
  reportu (z deklarace), **název firmy** (`app.name` → `name` z main.json;
  do exportu nikdy ID zdroje), jazyk, popisky a počet měsíců fiskálního
  roku. Staví ho `ReportExportContextFactory` — sdílená REST i CLI.
- **`ReportExporter`** je jediný vstupní bod: `export(result, format,
  context)` → `ReportExportFile {body, contentType, fileName}`.
- **Popisky** (názvy listů, úvod, hlavičky Účet / Název / MD / D /
  Zůstatek, list Zprávy, názvy parametrů) jdou z cfgItem
  `core.system.reportExportLabels` (`ReportExportLabels::fromConfig()`),
  bez zkompilovaného cfgItem anglický fallback. Parametr reportu bez
  záznamu v `params` se v úvodu vypíše jako `id: hodnota` — nový
  parametr s lidským názvem = doplnit ho do cfgItem.

### 15.2 Pravidla převodu

- **Sloupce:** [`Účet`, je-li u některého řádku `account`] + `Název` +
  sloupce reportu. Money `display: balance` = jedna číselná buňka
  (zůstatek); `display: sides` = tři sloupce „{label} — MD“, „— D“,
  „— Zůstatek“. `text` = text, `date` = datum (nevalidní ISO zůstává
  textem).
- **Řádky:** všechny řádky výsledku v pořadí.
- **Čísla vždy přesně.** Přepínač „V tisících“ je věc zobrazení v UI
  a do exportu se nepromítá (úvod XLSX to poznamenává). **Nula je číslo**
  (`0`), ne prázdná buňka jako v UI — sloupec zůstává číselný; prázdná
  buňka jen tam, kde hodnota v řádku chybí.
- **Název souboru:** `{slug názvu reportu}-{období}.{přípona}` —
  `hlavni-kniha-2026-05.xlsx` (měsíc), `…-2026-04-06` (interval měsíců),
  `…-2026` (celý fiskální rok), u reportů DPH slug názvu instance
  tvrzení (`kontrolni-hlaseni-zive-06-2026.csv`).

**XLSX** (list „Report“, při `status != ok` druhý list „Zprávy“):

- Úvod: název reportu, období (stejný tvar jako picker — `2026 / 5`,
  `2026 / 2Q`, `2026`; u DPH název instance + rozsah dat), parametry,
  datum vygenerování **s časovou zónou** (server běží v UTC), zdroj dat,
  poznámka o přesných částkách, při chybách / varováních stavový řádek.
  Prázdný řádek, pak tabulka.
- Čísla jako čísla, uložený formát **`#,##0.00`** — tabulkový procesor ho
  zobrazí dle svého locale (česky `1 234,50`). Literál `# ##0.00`
  z původního zadání by u milionů vložil jedinou mezeru. Datum jako
  datum.
- **Odsazení dle `level`:** OpenSpout neumí indent stylu buňky, proto
  textový formát `"  "@` (2 mezery × `level`) na buňce popisku. Mezery
  jsou jen zobrazení — hodnota buňky zůstává čistá pro filtry a hledání.
- `subtotal` / `total` / `computed` tučně, stejně hlavička tabulky.
- List „Zprávy“: závažnost, kód, text a **číslo řádku na listu Report**
  (z `rowRef`), ne index do výsledku.

**CSV:** UTF-8 s BOM, oddělovač `;`, desetinná čárka bez oddělovače
tisíců, datum `YYYY-MM-DD`, konce řádků CRLF, první řádek hlavička; jen
tabulka — bez úvodu, odsazení a zpráv. Text začínající znakem vzorce
(`=`, `+`, `-`, `@`) dostává apostrof: názvy účtů a čísla dokladů jsou
uživatelská data a tabulkový procesor by je při otevření CSV vyhodnotil
(v XLSX jsou buňky typované, tam to netřeba).

### 15.3 REST

`GET /_reports/{reportId}?…&format=json|xlsx|csv` — `json` (default) je
beze změny. `xlsx` / `csv` vrací soubor **mimo JSON obálku**
(`Response::binary`) s hlavičkami `Content-Type`, `Content-Disposition:
attachment; filename="…"` a **`X-Report-Status`** (`ok | warnings |
errors`, u obou formátů — CSV zprávy jinak nenese). Neplatný `format`
→ 400 `BAD_REQUEST`; chyby validace parametrů a neznámý report zůstávají
JSON (400 / 404). `format` není parametr reportu — controller ho odebírá
před `ReportRunner` (validátor neznámé parametry odmítá). `ReadOnlyPolicy`
beze změny (čtení); MCP `report_run` export nenabízí.

### 15.4 UI a CLI

- **UI:** tlačítko **Export** (nabídka Excel (XLSX) / CSV) v liště
  parametrů `ReportsPage`, aktivní jen s načteným výsledkem; stahuje se
  stejnými parametry jako zobrazený report. Auth jde Bearer hlavičkou,
  proto fetch → Blob → object URL (`api/client.js` `getBlob()`,
  `utils/download.js`), název souboru z `Content-Disposition`.
- **CLI:** `report-run … --format=json|xlsx|csv [--output=<soubor>]` —
  `xlsx` vyžaduje `--output`, `csv` bez něj na stdout; viz
  [cli.md](cli.md).

---

## 16. Obecné parametry, seskupení a identita řádku

Rozšíření jádra pro reporty, jejichž řádky nejsou účty — první konzument
jsou přehledy majetku (`economy.assets.*`, `docs/assets.md` §5.6).

### 16.1 Parametry deklarace ve všech prezentacích

Do té doby uměly UI, CLI i MCP jen parametr `detail`. Deklarace je teď
jediný zdroj i pro popisky (duch D7):

```jsonc
{
    "id": "groupBy", "type": "enum",
    "name": "Group by", "name:cs": "Seskupit podle",
    "options": ["accountingGroup", "type", "none"],
    "optionNames": {
        "accountingGroup": "Accounting group", "accountingGroup:cs": "Účetní skupina",
        "type": "Type", "type:cs": "Typ",
        "none": "No grouping", "none:cs": "Neseskupovat"
    },
    "default": "accountingGroup"
}
```

- `name` a `optionNames` jsou nepovinné a lokalizují se jako zbytek
  deklarace (`ConfigLocalizer`); katalog `GET /_reports` je posílá v
  `params[]`. Bez popisku padá prezentace na id.
- Id parametru nesmí kolidovat s klíči období, exportu a deep-linku
  (`ReportDefinition::RESERVED_PARAM_IDS`: `fiscalYear`, `monthFrom`,
  `monthTo`, `period`, `format`, `report`, `fy`, `mf`, `mt`, `p`).
- **UI:** `ReportsPage` staví toolbar ze všech parametrů deklarace
  (`enum` = roletka, `bool` = zaškrtávátko); popisek vedle roletky
  dostávají až dva a víc parametrů. Stav stránky je plochý objekt
  období + parametry, čisté funkce v `utils/reportParams.js`. Deep-link
  nese parametry pod jejich id (`…&groupBy=type`), neplatná hodnota padá
  na default. Výchozí období respektuje granularity — report jen s roční
  granularitou dostane celý rok, ne poslední měsíc.
- **CLI:** opakovatelné `--param id=hodnota`; `--detail` zůstává
  (`--param detail=…` má přednost).
- **MCP:** `report_run` bere objekt `params` (`{id: hodnota}`), nabídku
  ukazuje `report_list`; top-level `detail` zůstává kvůli výchozí hodnotě
  `synthetic`.
- **Export:** názvy parametrů a hodnot v úvodním bloku bere z deklarace
  (`ReportExportLabels::withDefinitionParams()`), cfgItem
  `core.system.reportExportLabels.params` je už jen fallback.

### 16.2 Země zdroje dat

`ReportRequest::$country` (`DataSourceConfig::getCountry()`) — builder,
který počítá podle pravidel země (daňové odpisy), nemá jinou cestu
k `DataSourceConfig`. Plní `ReportRunner` (parametr `country`) na všech
třech místech vzniku: REST (`dispatchReports`), CLI `report-run`, MCP
`ReportToolSupport`.

### 16.3 Seskupení podle klíče

`SubtotalAggregator::rollup()` seskupuje podle prefixu čísla účtu.
`SubtotalAggregator::groupBy($rows, $groupOf, $labelResolver,
$totalLabel, $subtotalFirst = true)` seskupuje podle libovolného klíče
(účetní skupina, typ, druh události): skupiny v pořadí prvního výskytu,
řádky uvnitř v pořadí vstupu (řadí builder), řádek skupiny je `subtotal`
level 1 s `key = group:{klíč}` — ve výchozím stavu **před** svými řádky
jako nadpis se součty (vzor kontrolního hlášení). `$groupOf = null` dá
jen total, `$totalLabel = null` jen skupiny.

### 16.4 Identita řádku (`key`)

`ReportRow::$key` — volitelná stabilní identita řádku, který není účet
(`asset:68`, `event:412`, `group:…`); v JSON jen když je vyplněná.
`account` zůstává číslem účtu: renderer ho tiskne před názvem a export
z něj dělá sloupec „Účet“, takže inventární číslo patří do textového
sloupce, ne do `account`. `ReportDiff` páruje detail řádky podle
`key ?? account` — reporty mimo deník tak jdou porovnat `report-diff`
a dva řádky téhož účtu se nepřepíšou.

### 16.5 Drill-down z řádku (`link`, `cellLinks`)

Naplnění §7.1 („drill-down z řádku“) obecně pro všechny reporty:

- `ReportRow::$link` — akce na **názvu** řádku; `ReportRow::$cellLinks` —
  akce na **textové buňce** (id sloupce → akce). Tvar je shodný s akcí
  detailu vieweru: `{kind, target}`. V JSON jen když jsou vyplněné.
- Podporované `kind`: `open_detail` (`{viewerId, recordId, tabId?}` —
  read-only náhled v modalu nad reportem), `open_viewer` (`{viewerId,
  recordId?, viewGroup?, filters?}` — přechod do vieweru s předvybraným
  záznamem a filtry) a `open_report` (`{reportId, params?}`).
- **Renderer:** `ReportView` vykreslí název / buňku jako odkaz a akci jen
  předá rodiči (`onAction`); `ReportsPage` ji obslouží (`ViewerDetailModal`,
  `navigationStore.navigateToViewer` / `navigateToReport`). Bez `onAction`
  zůstává report prostý text. Prázdná buňka odkaz nedostane.
- Export, `ReportDiff` ani MCP odkazy nepotřebují — export je ignoruje,
  v JSON pro MCP zůstávají jako strojově čitelná vazba (`recordId`).
- Odchod přes `open_viewer` nic neztrácí: parametry reportu drží session
  mapa stránky.

První uživatelé jsou reporty majetku: řádek karty → náhled karty, číslo
dokladu zaúčtování → doklad, účet v kontrole → deník s filtrem účtu a roku,
nesoulad karty → karta ve vieweru a deník s filtrem účtu a karty.

