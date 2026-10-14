# ds-setup — Task 11: Rozšířená sada účetních položek se skupinami

**Stav:** hotovo

> PRD pro jednu Claude Code session. Navazuje na Task 10 (nabídka
> účetních položek, D18/D19). Rozhodnutí této revize: **D1–D9 níže**
> (potvrzeno v designové session 2026-08-14).

## Kontext

Task 10 dodal jednorázovou nabídku 7 účetních položek (UP-BANK…UP-ZAOV)
v sekci „Volitelné" panelu Nastavení zdroje dat. Sada je ryze finanční
(poplatky, úroky, kurzové rozdíly, zaokrouhlení) — pro běžné přijaté
faktury (telefon, internet, nájemné, energie…) nenabízí nic.

Starý Shipard měl v účetním rozvrhu příznak `accItem:1` (soubory
`modules/install/data/countries/cz/debs/debs-accounts-default-class*.json`
v repu old_shipard) — 50 účtů, u kterých se při instalaci osnovy zároveň
založila účetní položka. Analýza potvrdila, že **všech 50 čísel účtů
existuje 1:1 v `accountChartDefault.jsonc`** — mapování není potřeba,
jen kurátorský výběr. NPO větev starého Shipardu `accItem` neměla;
NPO sada se skládá nově z ploché NPO osnovy.

## Cíl

1. Rozšířit `accountingItemsDefault.jsonc` na **54 položek** v 8 skupinách.
2. Rozšířit `accountingItemsNpo.jsonc` na **31 položek** v 7 skupinách.
3. Nový účet **518206 Software a cloudové služby** v podnikatelské osnově.
4. Kódy položek = **čísla účtů** (s písmenným sufixem při víc položkách
   na jednom účtu); dosavadní kódy UP-* zanikají.
5. UI nabídky se skupinami: sbalitelné sekce, tri-state checkbox skupiny.

## Potvrzená designová rozhodnutí

1. **D1 — rozsah:** stará `accItem` sada minus 325100 (příliš generické)
   a 538100 (daň z převodu nemovitostí zrušena 2020), plus mzdová agenda
   (agenda v aplikaci není, ale účtuje se — ruší se „vědomé vynechání"
   z Tasku 10), plus 648100 pro symetrii k 548100, plus 518206 (nový účet).
2. **D2 — kódy = čísla účtů**, včetně přejmenování stávající sedmičky
   (nic není nasazeno na ostro, testovací DS se resetují — **migrace se
   nepíše**). Kolizní konvence: druhá a další položka na stejném účtu
   dostane sufix (Z = zaokrouhlení, B = bankovní poplatky).
3. **D3 — formát seedu** se mění z pole na objekt `{groups, items}`.
4. **D4 — API:** offer response navíc vrací `groups` a u kandidáta
   `group`; `POST /_setup/accounting-items` (kontrakt `codes`) beze změny.
5. **D5 — UI:** zachovat opt-out princip (vše neexistující předvybráno,
   jedno tlačítko), navrch skupinové sekce — default sbalené, v hlavičce
   tri-state checkbox + název + počty.
6. **D6 — názvy** opravené a modernizované (viz tabulky; „Spořeba PHM" →
   „Spotřeba PHM", „dohoda o provedení činnosti" → „dohoda o pracovní
   činnosti"); všude doplněné `name:en`.
7. **D7 — NPO sada** z ploché NPO osnovy, ~31 položek (viz tabulka).
8. **D8 — daň z příjmů** zůstává jako závazkový účet 341100 (platba daně),
   nákladový 591x se nepřidává.
9. **D9 — 518206** jen do podnikatelské osnovy; NPO drží jednu analytiku
   služeb (518100), software tam jde na ni. *Nahrazeno `ds-setup-12` D32
   (2026-10-14): nezisková osnova i nabídka mají stejné nákladové
   analytiky jako podnikatelská.*

## Před implementací přečti

- `tasks/ds-setup-10-accounting-items-offer.md` — původní task, D18/D19
- `src/Api/Controller/SetupController.php` ř. ~350–630 —
  `accountingItemsOffer`, `generateAccountingItems`,
  `loadAccountingItemsSeed`, `seedName`, `existingItemCodes`
- `modules/economy/items/config/accountingItemsDefault.jsonc` + `…Npo.jsonc`
- `modules/economy/accounting/config/accountChartDefault.jsonc` ř. ~475
  (tvar záznamů 518xxx)
- `modules/economy/accounting/src/AccountChartProvisioner.php` — upsert
  podle čísla: existující přeskočí, chybějící vloží (`is_system = 1`,
  `docState = 40`) → 518206 se na už naseedované DS dostane při dalším
  provision běhu, nic dalšího není potřeba
- `frontend/src/components/settings/AccountingItemsOffer.svelte`
- `frontend/src/components/ui/Checkbox.svelte` — ověř podporu
  `indeterminate`; pokud chybí, doplň (prop + nastavení na DOM elementu)
- `tests/Unit/Api/Controller/SetupControllerTest.php` ř. ~860+

## Scope

### 1. Osnova: nový účet 518206

`modules/economy/accounting/config/accountChartDefault.jsonc` — vložit
za 518205:

```jsonc
{"number":"518206","name":"Software a cloudové služby","short_name":"Software a cloud","account_kind":2,"costs_type":1,"results_type":1},
```

NPO osnova beze změny (D9).

### 2. Seed: `accountingItemsDefault.jsonc` — nový formát a obsah

Formát:

```jsonc
{
    "groups": [
        {"id": "finance",    "name:cs": "Finanční operace",   "name:en": "Financial operations", "order": 10},
        {"id": "services",   "name:cs": "Služby",             "name:en": "Services",             "order": 20},
        {"id": "materials",  "name:cs": "Materiál a energie", "name:en": "Materials & energy",   "order": 30},
        {"id": "operations", "name:cs": "Provoz a opravy",    "name:en": "Operations & repairs", "order": 40},
        {"id": "insurance",  "name:cs": "Pojištění a sankce", "name:en": "Insurance & penalties","order": 50},
        {"id": "payroll",    "name:cs": "Mzdová agenda",      "name:en": "Payroll",              "order": 60},
        {"id": "taxes",      "name:cs": "Daně",               "name:en": "Taxes",                "order": 70},
        {"id": "assets",     "name:cs": "Majetek",            "name:en": "Assets",               "order": 80}
    ],
    "items": [
        {"code": "568201", "group": "finance", "name:cs": "Bankovní poplatky", "name:en": "Bank fees", "account": "568201"},
        // …
    ]
}
```

Kompletní obsah `items` (54 položek; `code` = `account`, pokud tabulka
neříká jinak):

| code | group | name:cs | name:en | account |
|---|---|---|---|---|
| 568201 | finance | Bankovní poplatky | Bank fees | 568201 |
| 562100 | finance | Úroky placené | Interest paid | 562100 |
| 662100 | finance | Úroky přijaté | Interest received | 662100 |
| 563100 | finance | Kurzová ztráta | FX loss | 563100 |
| 663100 | finance | Kurzový zisk | FX gain | 663100 |
| 548100Z | finance | Zaokrouhlení (náklad) | Rounding (expense) | 548100 |
| 648100Z | finance | Zaokrouhlení (výnos) | Rounding (income) | 648100 |
| 518100 | services | Ostatní služby | Other services | 518100 |
| 518201 | services | Telefonní poplatky | Telephone charges | 518201 |
| 518202 | services | Internetové služby | Internet services | 518202 |
| 518203 | services | Poštovné | Postage | 518203 |
| 518204 | services | Reklama | Advertising | 518204 |
| 518205 | services | Nájemné | Rent | 518205 |
| 518206 | services | Software a cloudové služby | Software & cloud services | 518206 |
| 518211 | services | Účetní a daňové služby | Accounting & tax services | 518211 |
| 518212 | services | Právní a notářské služby | Legal & notary services | 518212 |
| 518301 | services | Finanční leasing vozidel | Vehicle finance lease | 518301 |
| 518302 | services | Finanční leasing strojů | Machinery finance lease | 518302 |
| 501100 | materials | Spotřeba materiálu | Materials consumed | 501100 |
| 502100 | materials | Spotřeba energie (elektřina) | Energy — electricity | 502100 |
| 502200 | materials | Spotřeba energie (vodné a stočné) | Energy — water & sewage | 502200 |
| 502300 | materials | Spotřeba energie (plyn) | Energy — gas | 502300 |
| 503100 | materials | Spotřeba PHM | Fuel consumed | 503100 |
| 511100 | operations | Opravy a udržování | Repairs & maintenance | 511100 |
| 511201 | operations | Opravy a údržba nemovitého majetku | Real-estate repairs | 511201 |
| 511202 | operations | Servis vozidel | Vehicle servicing | 511202 |
| 512100 | operations | Cestovné | Travel expenses | 512100 |
| 513900 | operations | Náklady na reprezentaci | Entertainment expenses | 513900 |
| 548100 | operations | Ostatní provozní náklady | Other operating expenses | 548100 |
| 648100 | operations | Ostatní provozní výnosy | Other operating income | 648100 |
| 548201 | insurance | Pojištění majetku | Property insurance | 548201 |
| 548202 | insurance | Pojištění vozidel | Vehicle insurance | 548202 |
| 544100 | insurance | Smluvní pokuty a úroky z prodlení | Contractual penalties & default interest | 544100 |
| 545900 | insurance | Ostatní pokuty a penále | Other fines & penalties | 545900 |
| 521100 | payroll | Mzdové náklady (pracovní smlouva) | Wages — employment contract | 521100 |
| 521200 | payroll | Mzdové náklady (dohoda o provedení práce) | Wages — work agreement (DPP) | 521200 |
| 521300 | payroll | Mzdové náklady (dohoda o pracovní činnosti) | Wages — work activity agreement (DPČ) | 521300 |
| 523100 | payroll | Odměny členům orgánů společnosti a družstva | Remuneration of company body members | 523100 |
| 524100 | payroll | Sociální zabezpečení | Social security | 524100 |
| 524200 | payroll | Zdravotní pojištění | Health insurance | 524200 |
| 548301 | payroll | Zákonné úrazové pojištění pracovníků | Statutory employee accident insurance | 548301 |
| 331100 | payroll | Hrubá mzda | Gross wages | 331100 |
| 331200 | payroll | Čistá mzda | Net wages | 331200 |
| 336100 | payroll | Zúčtování s institucí sociálního zabezpečení | Social security institution settlement | 336100 |
| 336200 | payroll | Zúčtování s institucemi zdravotního pojištění | Health insurance institutions settlement | 336200 |
| 342100 | payroll | Daň z příjmu ze závislé činnosti | Employment income tax | 342100 |
| 341100 | taxes | Daň z příjmů | Income tax | 341100 |
| 531100 | taxes | Daň silniční | Road tax | 531100 |
| 532100 | taxes | Daň z nemovitostí | Real-estate tax | 532100 |
| 042100 | assets | Pořízení dlouhodobého hmotného majetku | Acquisition of tangible fixed assets | 042100 |
| 501201 | assets | Evidovaný hmotný majetek | Registered tangible assets | 501201 |
| 501202 | assets | Evidovaný nehmotný majetek | Registered intangible assets | 501202 |
| 648201 | assets | Prodej evidovaného hmotného majetku | Sale of registered tangible assets | 648201 |
| 648202 | assets | Prodej evidovaného nehmotného majetku | Sale of registered intangible assets | 648202 |

Hlavičkový komentář souboru přepiš: popis formátu `{groups, items}`,
konvence kódů (číslo účtu + sufix Z/B při kolizi), zmínka že mzdová
agenda je záměrně UVNITŘ (revize rozhodnutí z Tasku 10), a stávající
varování o nesdílení čísel s NPO sadou zůstává.

### 3. Seed: `accountingItemsNpo.jsonc` — nový formát a obsah

Skupiny: `finance` (10), `materials` (20), `operations` — name:cs
„Služby a provoz" / name:en "Services & operations" (30), `payroll` (40),
`taxes` (50), `sanctions` — „Sankce a dary" / "Penalties & gifts" (60),
`assets` (70).

| code | group | name:cs | name:en | account |
|---|---|---|---|---|
| 549100B | finance | Bankovní poplatky | Bank fees | 549100 |
| 544100 | finance | Úroky placené | Interest paid | 544100 |
| 644100 | finance | Úroky přijaté | Interest received | 644100 |
| 545100 | finance | Kurzová ztráta | FX loss | 545100 |
| 645100 | finance | Kurzový zisk | FX gain | 645100 |
| 549100Z | finance | Zaokrouhlení (náklad) | Rounding (expense) | 549100 |
| 649100Z | finance | Zaokrouhlení (výnos) | Rounding (income) | 649100 |
| 501100 | materials | Spotřeba materiálu | Materials consumed | 501100 |
| 502100 | materials | Spotřeba energie | Energy consumed | 502100 |
| 503100 | materials | Spotřeba ostatních neskladovatelných dodávek | Other non-storable supplies | 503100 |
| 518100 | operations | Ostatní služby | Other services | 518100 |
| 511100 | operations | Opravy a udržování | Repairs & maintenance | 511100 |
| 512100 | operations | Cestovné | Travel expenses | 512100 |
| 513100 | operations | Náklady na reprezentaci | Entertainment expenses | 513100 |
| 549100 | operations | Jiné ostatní náklady | Other miscellaneous expenses | 549100 |
| 521100 | payroll | Mzdové náklady | Wages | 521100 |
| 524100 | payroll | Zákonné sociální pojištění | Statutory social insurance | 524100 |
| 527100 | payroll | Zákonné sociální náklady | Statutory social costs | 527100 |
| 331100 | payroll | Hrubá mzda | Gross wages | 331100 |
| 331200 | payroll | Čistá mzda | Net wages | 331200 |
| 336100 | payroll | Zúčtování s institucí sociálního zabezpečení | Social security institution settlement | 336100 |
| 336200 | payroll | Zúčtování s institucemi zdravotního pojištění | Health insurance institutions settlement | 336200 |
| 342100 | payroll | Ostatní přímé daně | Other direct taxes | 342100 |
| 341100 | taxes | Daň z příjmů | Income tax | 341100 |
| 531100 | taxes | Daň silniční | Road tax | 531100 |
| 532100 | taxes | Daň z nemovitostí | Real-estate tax | 532100 |
| 538100 | taxes | Ostatní daně a poplatky | Other taxes & fees | 538100 |
| 541100 | sanctions | Smluvní pokuty a úroky z prodlení | Contractual penalties & default interest | 541100 |
| 542100 | sanctions | Ostatní pokuty a penále | Other fines & penalties | 542100 |
| 546100 | sanctions | Poskytnuté dary | Gifts provided | 546100 |
| 042100 | assets | Pořízení dlouhodobého hmotného majetku | Acquisition of tangible fixed assets | 042100 |

Pozn.: 538100 zde znamená „Ostatní daně a poplatky" (NPO osnova), nemá
nic společného se zrušenou daní z převodu nemovitostí — do hlavičkového
komentáře.

### 4. Backend: `SetupController`

- `loadAccountingItemsSeed()` — parsuje nový tvar. Návratový typ změnit
  na `array{groups: list<array>, items: array<string, array>}|null`
  (items klíčované kódem jako dnes). Skupiny bez položek nevadí (offer
  je stejně nepošle — viz níže), položka s neznámým `group` → hlasitě
  `ErrorLogger::error` + položku zařadit (offer ji vrátí s `group` tak,
  jak je; klient neznámou skupinu zobrazí na konci). Starý tvar (pole)
  se NEpodporuje — jediný konzument je tento kontroler.
- `accountingItemsOffer()` — response nově:
  ```
  {available, chartVariant, unavailableReason,
   groups: [{id, name, order}],   // name lokalizované stejně jako seedName
   candidates: [{code, name, accountNumber, group, exists}]}
  ```
  `groups` posílej jen ty, které mají aspoň jednoho kandidáta, seřazené
  podle `order`. Lokalizaci názvu skupiny řeš stejným mechanismem jako
  `seedName()` (vytkni sdílený helper, např. `localizedField(array $entry,
  string $base, string $fallback)`).
- `generateAccountingItems()` — logika beze změny, jen čte `items`
  z nového návratu parseru. Kontrakt `codes` nezměněn.
- Konstanta `ITEMS_SOURCE_KIND` a plnění `source_ref = code` beze změny —
  kódy jsou nové, ale mechanismus stejný.

### 5. Frontend: `AccountingItemsOffer.svelte`

- Render podle `offer.groups` (řazení dle `order`); kandidáti bez známé
  skupiny do syntetické sekce na konci (bez názvu ze serveru → fallback
  i18n `setup.offer.items.group.other`).
- Sekce skupiny: hlavička = tri-state checkbox (vybrané/žádné/část
  neexistujících položek skupiny) + název + počet `vybráno/dostupné`
  (existující se nepočítají do dostupných) + šipka sbalení. **Default
  sbaleno.** Kliknutí na checkbox hlavičky přepne všechny neexistující
  položky skupiny; kliknutí na zbytek hlavičky sbalí/rozbalí.
- Předvýběr všech neexistujících položek při načtení zůstává (opt-out),
  tlačítko „Vygenerovat N položek" a souhrn created/skipped beze změny.
- `Checkbox.svelte`: pokud nepodporuje `indeterminate`, doplnit prop
  (nastavuje se přes DOM property, ne atribut).
- Řádek položky beze změny (checkbox, název, mono číslo účtu, badge
  „Už existuje").

### 6. i18n (`cs.js`, `en.js`)

- `setup.offer.items.description` — přepsat: sada pokrývá běžné náklady
  (služby, energie, mzdy, daně…) i finanční operace; položky se účtují
  přímo z řádku dokladu; účty odpovídají zvolené osnově.
- Nové klíče: `setup.offer.items.group.other` („Ostatní" / "Other"),
  `setup.offer.items.group.count` (`{selected}/{total}`) — případně
  formátuj přímo v komponentě, pak klíč netřeba.
- Ostatní klíče (generate/summary/skipped/unavailable) beze změny.

### 7. Testy: `SetupControllerTest`

- Fixture seed přepnout na nový formát; upravit asserty kódů
  (UP-ZAON → 548100Z, UP-BANK → 568201, NPO 549100B…).
- Nové asserty: offer vrací `groups` seřazené podle `order`, jen
  neprázdné; kandidát nese `group`.
- Zachovat/ověřit stávající scénáře: exists-detekce podle kódu,
  generate skipped `already_exists` / `unknown_code` /
  `account_not_found`, obě varianty osnovy (dvojí význam čísel —
  548100 vs 549100 dvakrát v NPO s různými kódy).
- Nový scénář: dvě položky na stejném účtu (548100 + 548100Z) se obě
  vygenerují — různé kódy, stejný `accounting_account`.

### 8. Dokumentace

- `docs/ds-setup.md` — v sekci nabídky účetních položek (D18) doplnit
  revizi: skupiny, kódy = čísla účtů, mzdová agenda zařazena, rozšířená
  NPO sada, účet 518206. Označit např. „D18-rev (Task 11)".

## Mimo scope

- Migrace UP-* kódů na běžících DS (D2 — testovací DS se resetují;
  na DS s vygenerovanou starou sadou by nová nabídka vytvořila
  duplicitní položky, to je akceptováno a řeší se resetem).
- Přidávání účtů do NPO osnovy (D9).
- Nákladový účet daně z příjmů 591x (D8).
- Jakékoli změny `POST /_setup/accounting-items` kontraktu.

## Commit strategie

1. `feat(economy.items): extended accounting items seeds with groups`
   — osnova 518206, oba seed soubory, `SetupController`, testy.
2. `feat(frontend): grouped accounting items offer`
   — `AccountingItemsOffer.svelte`, `Checkbox.svelte`, i18n.

## Hotovo když

- [ ] `accountChartDefault.jsonc` obsahuje 518206; provision na DS
      s už naseedovanou osnovou účet doplní (created +1, existing beze změny)
- [ ] Oba seedy v novém formátu: default 54 položek / 8 skupin,
      NPO 31 položek / 7 skupin; každý `account` existuje v příslušné
      osnově (ověřit skriptem/testem proti chart JSONC)
- [ ] Offer vrací `groups` + `group` u kandidátů; generate beze změny
      kontraktu
- [ ] UI: sbalené skupiny s tri-state checkboxem, počty v hlavičce,
      default vše neexistující předvybráno, generování funguje napříč
      skupinami jedním tlačítkem
- [ ] Dvě položky na jednom účtu (548100/548100Z, NPO 549100/549100B/549100Z)
      se vygenerují bez konfliktu
- [ ] PHPUnit: `vendor/bin/phpunit --filter "SetupControllerTest"` zelený
- [ ] `docs/ds-setup.md` aktualizován (D18-rev)
