# Shipard — Nastavení nového zdroje dat (`ds-setup`)

**Designový dokument.** Mechanismus, který dovede čerstvě založený zdroj dat do
stavu „lze v něm účtovat“ — bez toho, aby uživatel na chybějící nastavení přišel
až ve chvíli, kdy nejde potvrdit první faktura. Skládá se ze tří částí:
**instalačních parametrů** zadaných při zakládání, odvozeného **checklistu**
chybějícího nastavení a **průvodce**, který je jen UI nad tím checklistem.

Podoblast [`hosting.md`](hosting.md) — Hosting přispívá výhradně přenosem dvou
instalačních parametrů z admin formuláře do `ds-create` (D7). Zbytek mechanismu
žije v samotném zdroji dat a funguje shodně pro DS založené z konzole i z dev
dashboardu.

> **Stav:** **Fáze 0 (diskuse) hotová, rozhodnutí D1–D9 schválená
> (2026-08-11). Fáze 1 (vrstva A) hotová — Task 01 (`getCountry()`,
> `ds-create --language --country`, dev dashboard, `[WARN]` v `ds-upgrade`)
> + Task 02 (sloupce na hostingu, formulář, queue payload, agent).
> Z Fáze 2 hotový Task 03 — klíče `economy.accountChart`
> a `economy.fiscalYearStartMonth` (`LayerCParameters`), CLI `ds-setting`,
> odložený provisioning osnovy a fiskálních roků, `[TODO]` výpis
> v `ds-upgrade`, konec `getAccountChart()` — a `vat-payer-01` (§6 body
> 1–4: klíč `economy.vatAgenda`, default `vat_mode`, skrytí sekce DPH
> a navigace přes nový `NavItemVisibilityGate`, období DPH při uložení
> registrace). Task 04 (`economy.homeCurrency`, konec
> `getDefaultCurrency()`) hotový — vrstva C je parametricky kompletní
> a `main.json` je čistě vrstva A. Z Fáze 3 hotový Task 05 — sedm
> nových setup checků (§5.3), služba `SetupChecklist` (D12) a zákaz
> snooze/dismiss pro setup alerty (D13) — a Task 06 — panel `dsSetup`
> v Nastavení (D14): `GET /_setup/checklist`, `POST /_setup/parameters`
> s okamžitým během provisionerů, `DsSetup.svelte`. Task 07 (agregovaná
> karta feedu `alert-group:setup` + akce `open_panel`, D8) hotový —
> Fáze 3 je kompletní. Fáze 4 hotová: Task 08 (vlastní Osoba z registru,
> režim `asOwn`, návrh `vatAgenda` podle DIČ) + Task 09 (předvyplněná
> Registrace DPH, můstek bankovních účtů do číselníku, D17) — viz §5.4.
> Fáze 5 hotová: Task 10 (sekce „Volitelné“ v panelu + nabídka účetních
> položek per varianta osnovy, D18/D19) — **oblast ds-setup je kompletní**.**
> Fázování viz §8, uzavřené body §10.

---

## 0. Schválená rozhodnutí

| # | Rozhodnutí |
|---|---|
| D1 | Vrstva **A** (`main.json`, jednorázové, nezměnitelné) = **jen jazyk a země**. Přepínače `ds-create --language --country`, nový getter `country` v `DataSourceConfig`. |
| D2 | Všechny ostatní parametry (účtová osnova, domácí měna, první měsíc fiskálního roku, plátcovství DPH) žijí v `core_system_settings` přes `SettingsStore`. **Absence klíče = nerozhodnuto.** |
| D3 | Stav nastavení se **nikde nepamatuje** — dopočítává se. Checklist má dva druhy položek: chybějící business řádek (`COUNT = 0`) a nerozhodnutý parametr (chybí klíč). Žádný příznak „průvodce dokončen“. |
| D4 | Průvodce je **UI nad checklistem**, ne samostatná evidence. Nový setup check = nový krok průvodce. Průvodce je přeskočitelný a neblokující. |
| D5 | Plátcovství DPH ([Issue #17](https://github.com/shipard/shipard/issues/17)) = **varianta 1**: absence Registrace DPH pro dané datum znamená neplátce. Příznak se jmenuje `economy.vatAgenda` („vede agendu DPH“) — záměrně ve tvaru předvolby, ne faktu, aby ho nikdo později nepoužil jako zdroj pravdy o plátcovství. |
| D10 | **Příznak řídí budoucnost a navigaci; renderování existujících dat řídí doklad sám** (`vat_mode`). Tím je přechod plátce ⇄ neplátce vyřešený bez zvláštní práce a bývalý plátce nepřestane vidět svá stará data o DPH. |
| D11 | Navigace agendy DPH (Registrace, Období) se skrývá jen tehdy, když je příznak neplátce **a zároveň nikdy neexistovala žádná registrace** (`COUNT(*) = 0` včetně ukončených). Samotný příznak jako podmínka nestačí. |
| D12 | **Hybrid dvou čtecích cest nad jednou implementací checku.** Karta ve feedu čerpá z tabulky alertů (naplní cron, slot `five-minutes`), **panel checklistu spouští checky naživo** přes službu `SetupChecklist`. Důvod: checky mají vlastní `interval` a runner přeskakuje ty, kde `next_run_at > NOW`, takže panel by uživateli hlásil chybějící nastavení ještě dlouho poté, co ho doplní. |
| D13 | **`snooze` a `dismiss` jsou pro alerty s `tags: ["setup"]` zakázané** (409, stejný vzor jako existující state guardy v `AlertsController`). Bez toho by si uživatel mohl položku checklistu odklikat, což je proti D3. |
| D14 | **Parametry vrstvy C se ovládají v panelu `dsSetup`, ne na generické settings stránce.** Panel je ručně psaná Svelte komponenta (vzor `accountSecurity`), takže si ovládání vyrenderuje sám a field typy `select`/`checkbox` nejsou potřeba — vypadávají z oblasti. Důvody: parametry potřebují vysvětlující UI, které deklarativní pole neunese (osnovu po naseedování nepřepneš, měna platí jen pro nové záznamy); `vatAgenda` je tříhodnotový (`null`/`true`/`false`) a checkbox nerozhodnuto neunese vůbec; panel je v Nastavení, takže požadavek §5.2 na editovatelnost je splněný bez druhé UI plochy. |
| D15 | **Fáze 4 rozšiřuje panel `dsSetup`, nestaví samostatný krokový průvodce.** Pořadí `SetupChecklist::ORDER` uživateli říká, co je další na řadě, a položky mizí, jak se plní — krokování by přidávalo dojem, ne informaci, a znamenalo by ovládání parametrů podruhé. Vedený režim se dá dodělat nad hotovým panelem, kdyby se ukázal jako potřeba. |
| D16 | **Registrový import vlastní Osoby funguje jen tehdy, když žádná není** — žádný merge do existující. Gate je implicitní: položka `missing_own_person` svítí právě tehdy, když vlastní Osoba neexistuje. |
| D17 | **Můstek bankovních účtů je zaškrtávací seznam**, ne návrh jednoho účtu k potvrzení — uživatel překlopí víc účtů naráz. Účty se `source = 2` (Registr DPH API) jsou předvybrané, protože jsou oficiálně zveřejněné. |
| D18 | **Nabídky jsou jednorázové akce z panelu, ne provisionery.** Provisionery jsou idempotentní a dorovnávají — smazané položky by `ds-upgrade` uživateli vracel. |
| D19 | **Nabídky nejsou alerty.** Nesplněná nabídka není problém a nemá nic rozsvítit — žádný check, žádná položka v `SetupChecklist::ORDER`, žádná karta ve feedu. V panelu žijí jako samostatná sekce „Volitelné“. |
| D6 | Provisioning fiskálních roků se **odkládá** za rozhodnutí o prvním měsíci **a o domácí měně** (Task 04 — měna je součástí zakládaného záznamu, seedovat s odhadnutou by obcházelo D2). Čerstvý DS chvíli nemá fiskální roky — nevadí, doklad se stejně nepotvrdí bez vlastní Osoby. |
| D7 | Hosting přispívá **výhradně vrstvou A** — dva sloupce na `hosting_core_data_sources`, pole ve formuláři, položky v queue payloadu, předání v `HostingSyncRunner`. Žádný ARES v provisioning agentovi, žádné business řádky. |
| D8 | Setup alerty se ve feedu dashboardu agregují **podle tagu** (ne podle `check_id`) do jedné karty; její primární akce otevírá panel průvodce — nový druh akce `open_panel`. |
| D9 | **Bez backfillu existujících DS.** Zdroje dat se přeimportují ze starého Shipardu a import si parametry vrstvy C zapisuje sám (§7.2). |

### Vědomě mimo scope

- **Profily instalace** (firma plátce / firma neplátce / OSVČ / nezisková
  organizace) jako pojmenované sady předvoleb — odloženo. Rozhodnutí padne až po
  zkušenosti s jednotlivými parametry; datový model to nesmí zablokovat, ale
  nestaví se.
- **Vzorce číselných řad** — `NumberSeriesProvisioner` generuje výchozí sadu;
  ladění prefixů a restartů je samostatné téma.
- **Pokladny** (`economy_codebooks_cash_desks`), **sklady**, **nákladová
  centra** — přidají se jako podmíněné checky, až bude hotová příslušná agenda.
- **Kdo smí nastavení měnit** — dnes kdokoli s přístupem do Nastavení, stejně
  jako u `app.theme`. Jemnější RBAC mimo scope.
- **Více vlastních Osob v jednom DS** (více firem pod jednou databází) —
  `PersonDocument::validate` dnes připouští právě jednu a to zůstává.
- **Migrace jako cesta k nastavení** — import ze starého Shipardu parametry
  zapisuje sám a checklist na importovaném DS má být rovnou prázdný (§7.2).

---

## 1. Motivace

Po `ds-create` + `ds-upgrade` je zdroj dat schématem hotový a referenční data
naseedovaná, ale **nedá se v něm potvrdit jediný doklad**. Chybějící nastavení
se dnes projeví až jako validační chyba v okamžiku, kdy uživatel poprvé něco
vystavuje — tedy nejdřív, kdy o něm nechce slyšet.

Tvrdé blokace v kódu:

| Blokace | Kde | Kdy se projeví |
|---|---|---|
| `no_own_company` | `DocDocument::validate` přes `OwnCompanyResolver` | potvrzení **jakéhokoli** dokladu (stavy 20/40/80) |
| `vat_registration` je povinná | `DocDocument::validate`, pokud `vat_mode !== 0` | potvrzení dokladu s DPH |
| `bank_account` je povinný | `IssuedInvoiceDocument::validate` | potvrzení **vydané** faktury |

K tomu měkké mezery, které nic nehlásí: vlastní Osoba bez adresy sídla
(snapshot dodavatele na dokladu vyjde neúplný), chybějící Položky, nevyplněný
název a logo aplikace (tisknou se na doklady).

Existující dílek: `MissingOwnPersonCheck` (`base.persons.missing_own_person`,
`severity: warning`, `interval: 1h`, `tags: ["setup"]`) s akcí `open_form`
a `preset: {is_own: true, person_type: 2}`. Tenhle check je **prototyp celé
mašinerie** — zbytek dokumentu ho jen zobecňuje.

---

## 2. Tři vrstvy nastavení

Bolest vzniká z toho, že se do jednoho pytle míchají tři věci s různým
životním cyklem. Rozdělení podle vrstvy je hlavní myšlenka tohoto dokumentu:

| Vrstva | Co to je | Kde žije | Kdy se rozhoduje | Změnitelné? |
|---|---|---|---|---|
| **A** | Instalační parametry — jazyk a země | `config/main.json` (read-only instalační config) | při zakládání DS | ne |
| **B** | Referenční data — jednotky, druhy položek, osnova, saldo skupiny, instance daňových tvrzení, číselné řady, mail router, AI analyzer | tabulky, generuje `ds-upgrade` | automaticky, bez vstupu uživatele | idempotentně dorovnává |
| **C** | Firemní identita a parametry účetnictví | `core_system_settings` (parametry) + business tabulky (řádky) | při prvním otevření DS, průvodcem | ano |

**Vrstva B je už hotová** — `DsUpgradeCommand` spouští provisionery jednotek,
druhů položek, účtové osnovy, saldo skupin, fiskálních roků, instancí
daňových tvrzení a číselných řad; clearing infrastruktura, tranzitní a podrozvahové účty
(261100, 756100/799100) a AI analyzer se zajišťují bezpodmínečně
(i pod `skipProvisioning`). Vrstva B je dobrý precedent hlavně tím, že je
**idempotentní a spouští se opakovaně**, ne jednorázově při zakládání.

**Proč jazyk a země patří do A.** Steerují to, co ostatní vrstvy vůbec smí
nabídnout: který registr se dotazuje (ARES / RPO / Handelsregister — viz
`base.persons.sourceKinds`), které varianty osnovy existují, jaké jsou sazby
DPH (`world.vat`), jaký je formát adres a bankovních spojení. Firma navíc
nemění jurisdikci; jazyk lze per uživatel přebít (`account.language`), takže
hodnota v `main.json` je jen fallback pro požadavky bez `Accept-Language`.

**Proč zbytek patří do C.** Účtová osnova, domácí měna, fiskální rok
i plátcovství DPH jsou obchodní rozhodnutí, na která zakladatel DS (typicky
provozovatel hostingu nebo účetní kancelář) často nezná odpověď. Zároveň to
jsou parametry, které steerují provisionery — proto nesmí skončit v business
tabulce, ale v key-value nastavení, odkud je čte HTTP i CLI.

---

## 3. Inventura nastavení

Kompletní seznam toho, co musí být nastaveno, než je DS použitelný. Sloupec
*Detekce* je zároveň specifikací setup checku.

| Nastavení | Vrstva | Úložiště | Detekce | Zdroj hodnoty |
|---|---|---|---|---|
| Jazyk | A | `main.json` → `defaultLanguage` | — (povinný parametr `ds-create`) | zakladatel |
| Země | A | `main.json` → `country` (nové) | — (povinný parametr `ds-create`) | zakladatel |
| Vlastní Osoba | C | `base_persons_persons.is_own = 1` | `COUNT(*) = 0` nad aktivními | registr (ARES/RPO) |
| Sídlo vlastní Osoby | C | `base_persons_addresses`, `address_type = 1` | vlastní Osoba bez adresy typu sídlo | registr |
| Plátcovství DPH | C | settings `economy.vatAgenda` | chybí klíč | uživatel |
| Registrace DPH | C | `economy_codebooks_vat_registrations` | `vatAgenda = true` ∧ `COUNT(*) = 0` | registr plátců + doptání na frekvenci |
| Vlastní bankovní účet | C | `economy_codebooks_bank_accounts` | `COUNT(*) = 0` nad aktivními | překlop z bank. spojení vlastní Osoby |
| Účtová osnova | C | settings `economy.accountChart` | chybí klíč | uživatel |
| První měsíc fisk. roku | C | settings `economy.fiscalYearStartMonth` | chybí klíč | uživatel |
| Domácí měna | C | settings `economy.homeCurrency` | chybí klíč | uživatel |
| Základní Položky | C | `economy_items` | **není check** — nabídka (§10) | generátor, opt-in |
| Název a logo aplikace | C | settings `app.name`, `app.companyLogo` | **není check** — nabídka (§10) | uživatel |

Osm položek se skutečnými checky (jazyk a země se nekontrolují — bez nich DS
nevznikne; nabídky checky vědomě nemají).

---

## 4. Architektura

```
   ┌──────────────────────────────────────────────────────────────┐
   │  ZAKLÁDÁNÍ DS                                                │
   │                                                              │
   │  hosting admin form ──┐                                      │
   │  dev dashboard    ────┼──► ds-create --language --country     │
   │  ruční CLI        ────┘         │                            │
   │                                 ▼                            │
   │                          config/main.json   (vrstva A)       │
   └──────────────────────────────────┬───────────────────────────┘
                                      │
                                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  ds-upgrade  (vrstva B, idempotentní, opakovaně)             │
   │  jednotky · druhy položek · saldo skupiny · daňová tvrzení · │
   │  číselné řady · mail router · AI analyzer                    │
   │  osnova a fiskální roky JEN když je rozhodnuto (D2/D6)       │
   └──────────────────────────────────┬───────────────────────────┘
                                      │
                                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  ZDROJ DAT — vrstva C                                        │
   │                                                              │
   │   setup checky (tags: ["setup"])                             │
   │      │  dopočítávají stav z dat a settings                   │
   │      ├──► panel „Nastavení zdroje dat“  (settingsItems)      │
   │      ├──► jedna agregovaná karta feedu  (open_panel)         │
   │      └──► viewer alertů (snooze/dismiss jako dnes)           │
   │                                                              │
   │   průvodce zapisuje ──► core_system_settings  (parametry)    │
   │                    └──► business tabulky      (řádky)        │
   │                    └──► spouští provisionery osnovy a roků   │
   └──────────────────────────────────────────────────────────────┘
```

Klíčové: **checky jsou jediný zdroj pravdy o stavu nastavení.** Panel, karta
i viewer alertů jsou tři pohledy na tutéž kolekci. Přidání nastavení = přidání
checku; všechna tři místa se aktualizují sama.

---

## 5. Kontrakty

### 5.1 Vrstva A — `main.json` a `ds-create`

`DataSourceConfig` dostane getter `getCountry(): string` (ISO 3166-1 alpha-2
lower-case) a `hasCountry(): bool`. `getDefaultLanguage()` zůstává, jak je.

**Přechodný fallback.** Žádný existující DS `country` v `main.json` nemá, a reimport ze starého Shipardu (D9) je teprve před námi — `getCountry()` proto
vrací přechodně `'cz'`, když klíč chybí, a `ds-upgrade` v tom případě
vypíše `[WARN]`. `hasCountry()` je tu proto, aby ten warning šel vystavit bez
hládání v surovém poli. Po reimportu se fallback odstraní a chybějící hodnota
se stane chybou konfigurace — do té doby je `'cz'` vědomá lokalíní nepřesnost,
která je tichá jen u DS založených před tímto taskem.

V `ds-create` naopak fallback **není** — oba přepínače jsou povinné (§ níže).
Nový DS tedy nikdy nevznikne s odhadnutou zemí; fallback slouží výhradně
starým DS.

`ds-create` dostane dva přepínače:

```
--language   ISO 639-1 (cs|en)          povinný; main.json → defaultLanguage
--country    ISO 3166-1 alpha-2 (cz|sk) povinný; main.json → country
```

Validace jen tvarem (`^[a-z]{2}$` + whitelist jazyků) — cfgItem
`world.base.countries` v okamžiku `ds-create` ještě není zkompilovaný,
sémantická validace tedy patří výš (formulář hostingu, dev dashboard).

Tři volající `ds-create` je předají všichni: `HostingSyncRunner` (z queue
payloadu), `DevDashboardController` (z formuláře dev dashboardu) a ruční CLI.

**Gettery `getAccountChart()` a `getDefaultCurrency()` jsou z repa venku**
(Task 03 a Task 04) a klíče `accountChart`/`defaultCurrency` v `main.json`
jsou mrtvé (D9: žádný backfill, žádný fallback na ně). Od Tasku 04 je
`main.json` čistě vrstva A — identita, DB credentials, moduly, jazyk a země.

### 5.2 Vrstva C — settings klíče a jejich čtenáři

Klíče namespacované podle modulu (pravidlo z [`app-settings.md`](app-settings.md)
§6), scope `ds`, uložené přes `SettingsStore`. Absence klíče = nerozhodnuto (D2);
`SettingsStore::set($key, null)` klíč maže, takže „vrátit do nerozhodnutého
stavu“ je součást existujícího API.

| Klíč | Typ | Obsah | Čtenáři |
|---|---|---|---|
| `economy.accountChart` | `default` \| `npo` \| `none` | varianta účtové osnovy | `AccountChartProvisioner` |
| `economy.homeCurrency` | string, ISO 4217 lower | domácí měna dokladů | `DocsHeadsFormBase`, `DocDocument`, `JournalLedgerHandler` → `LedgerGenerator`, `FiscalYearsForm`, `DsUpgradeCommand` (gate fiskálních roků) |
| `economy.fiscalYearStartMonth` | int 1–12 | první měsíc fiskálního roku | `FiscalYearsProvisioner` |
| `economy.vatAgenda` | bool | plátcovství DPH | formuláře dokladů (výchozí `vat_mode`), viditelnost DPH v UI |
| `economy.vat.filingAccountingSeries` | int, id řady `cmnbkp` | řada účetního dokladu přiznání DPH (#55 F4b); **volitelný** — mimo průvodce i `[TODO]` výpis, bez něj se použije jediná aktivní řada typu, při více řadách zaúčtování skončí s pokynem klíč nastavit | `VatReturnAccountingService` |

`SettingsStore` bere v konstruktoru jen `DataSourceConnection` a je podle
`app-settings.md` výslovně volatelný z HTTP i CLI — tentýž klíč tedy čte
průvodce ve frontendu i provisioner v `ds-upgrade`. Žádná nová infrastruktura.

**CLI přístup ke klíčům.** `SettingsStore` dnes není použitý z žádného
commandu. Do doby, než existuje průvodce (Fáze 4), by tedy nebylo jak
parametr rozhodnout — a každý DS založený v tom okně by zůstal bez
osnovy. Proto `ds-setting get|set|list` (Fáze 2) a výpis nerozhodnutých
parametrů na konci `ds-upgrade` včetně příkazů k jejich nastavení —
`ds-upgrade` tak slouží jako provizorní checklist a zároveň si na něm
ověříme obsah budoucích setup checků.

**Call sites (přepojeno v Tasku 04):** `economy.homeCurrency` čtou
`DocsHeadsFormBase::applyDefaults()` (default `home_currency` nového
dokladu, `doc_currency` se odvozuje z `home_currency`),
`DocDocument::applyHomeCurrency()` (přes `SettingsStore` injektovaný
`TableGateway`em — sdílená instance per gateway, žádný dotaz per doklad),
`JournalLedgerHandler` (předává měnu `LedgerGenerator`u jako string),
`FiscalYearsForm` (default měny nového fiskálního roku) a
`DsUpgradeCommand::provisionFiscalYears()` (gate + předání
`FiscalYearsProvisioner`u). Defenzivní fallbacky `?? 'czk'` při čtení
`$data` ve formulářích zůstaly — hodnotu vždy plní `applyDefaults()`.

**Editovatelnost mimo průvodce.** Parametry jsou vidět a měnitelné v panelu
`dsSetup`, který je sám položkou Nastavení (D14). Generická settings stránka
se pro ně nestaví a field typy `select`/`checkbox` tato oblast nepotřebuje —
panel je ručně psaná komponenta a ovládání si vyrenderuje sám.

### 5.3 Setup checky

Konvence `id` = `<group>.<module>.<slug>` (viz [`alerts.md`](alerts.md) §1).
Všechny nesou `tags: ["setup"]` — ten tag je **kontraktem** pro agregaci
ve feedu (D8) i pro sběr kroků průvodce (D4). Singleton checky, tedy
`findingKey = ""`.

| Check | Modul | Detekce | Primární akce |
|---|---|---|---|
| `base.persons.missing_own_person` | `base.persons` | *existuje dnes* | `open_form` (preset `is_own`, `person_type`) |
| `base.persons.missing_own_headquarters` | `base.persons` | vlastní Osoba bez adresy `address_type = 1` | `open_form` |
| `economy.codebooks.undecided_vat_agenda` | `economy.codebooks` | chybí `economy.vatAgenda` | `open_panel` |
| `economy.codebooks.missing_vat_registration` | `economy.codebooks` | `vatAgenda = true` ∧ `COUNT(*) = 0` | `open_form` |
| `economy.codebooks.missing_own_bank_account` | `economy.codebooks` | `COUNT(*) = 0` aktivních | `open_form` |
| `economy.codebooks.undecided_fiscal_year_start` | `economy.codebooks` | chybí `economy.fiscalYearStartMonth` | `open_panel` |
| `economy.codebooks.undecided_home_currency` | `economy.codebooks` | chybí `economy.homeCurrency` | `open_panel` |
| `economy.accounting.undecided_account_chart` | `economy.accounting` | chybí `economy.accountChart` | `open_panel` |

**Podmíněnost se řeší sama.** Check vrátí prázdné pole, když položka není
relevantní: `missing_vat_registration` mlčí u neplátce, checky nad osnovou
mlčí při rozhodnutí `none`. Žádný nový mechanismus — check si sám přečte
settings.

**Akce `open_panel` dodá až Task 06/07** (panel zatím neexistuje).
Checky nad nerozhodnutým parametrem proto od Tasku 05 vracejí **prázdné
`actions`** — karta/řádek se zobrazí bez tlačítka. Checky nad chybějícím
řádkem (`open_form`) akce nesou od začátku. Sloupec „Primární akce"
v tabulce popisuje cílový stav.

**Severity `warning`**, ne `error`. Nejde o poruchu, jde o nedokončené
nastavení; `error` je vyhrazený pro věci, které se rozbily.

### 5.4 Doplnění identity — rozšíření panelu

Fáze 4 **nestaví druhou plochu** (D15). Panel `dsSetup` z Fáze 3 už je ta
plocha: vyjmenuje, co chybí, a čtyři parametry vrstvy C umí rozhodnout sám.
Zbývá doplnit to, co panel dnes jen odkazuje na prázdný formulář — a to jsou
tři věci, ne osm.

**1. Vlastní Osoba z registru** (Task 08, hotové). Reuse existující cesty:
`RegistryImportWizard.svelte` → `personsRegistry.js` → `PersonsRegistryClient`
→ kanonický `shpd.persons.person.v1` → `_exchange/persons/person/preview` →
`apply`. Wizard má prop `asOwn` — jediná funkce `withApplyPolicy()` skládá
merge politiku (`createOnly`, `targetDocState: 40`) a v tomto režimu doplní
`status.isOwn = true` do payloadu pro preview i apply; `PersonApplier` ten
příznak zapisuje (ř. 422), takže **jedním applyem vznikne Osoba, sídlo,
bankovní spojení i DIČ**. Panel ho otevírá primární akcí
`registry_import_own` („Načíst z registru") u položky `missing_own_person`;
akce vzniká **až při serializaci v `SetupController::panelActions()`**, ne
v checku — finding checku putuje cronem do `core_alerts_alerts` a feed ani
viewer alertů tenhle kind neumí. Akce z checku (`open_form`) v panelu
zůstává jako sekundární „Zadat ručně" pro subjekty, které v registru nejsou.
Import funguje jen tehdy, když žádná vlastní Osoba není (D16) — gate je
implicitní, protože právě tehdy položka checklistu svítí; `existsInDb` gate
wizardu platí beze změny.

**2. Registrace DPH z DIČ** (Task 09, hotové). Položka
`missing_vat_registration` má primární akci `prefill_vat_registration`
(jen s aktivní vlastní Osobou — bez ní není z čeho předvyplňovat a zbývá
ruční cesta). Dialog čte `GET /_setup/vat-registration-prefill`: `vat_id`
a `name` z vlastní Osoby, `country` z vrstvy A
(`DataSourceConfig::getCountry()`), `region` default `eu`,
`taxpayer_kind = 0`. **Registr nevrací datum registrace ani příznak
plátce** — kanonický formát má jen `vatId`, žádné `vatRegistration` ani
`vatPayer`. `valid_from`, `tax_period_kind` a `cs_period_kind` proto
přijdou **null** a dialog se na ně zeptá (nápověda u data varuje před
„dnes"; frekvence jsou select z cfgItem `vatPeriodKinds`, rezervovaná
hodnota `0` v něm není). `rs_period_kind` přijde s návrhem `1` (souhrnné
hlášení je ze zákona měsíční), dialog ho nabídne ke změně. Uložení jde přes generický
`POST /_ui/form/economy_codebooks_vat_registrations/save`, tedy přes
`VatRegistrationDocument` — hook `afterSave` hned dogeneruje období DPH.
Přítomnost `vatId` se používá jako **návrh** hodnoty `economy.vatAgenda`
— to dodal už Task 08: položka `undecided_vat_agenda` v odpovědi
`GET /_setup/checklist` nese nepovinné pole `suggestion: {value, reason}`
(zdroj: `vat_id` aktivní vlastní Osoby, DIČ je v lokalizovaném `reason`
vidět; prázdné DIČ nebo žádná Osoba → pole chybí). Panel z něj jen
předvybere draft — **předvolba v UI, ne uložená hodnota**; rozhodnutí
zůstává na uživateli (D2, D5) a položka v checklistu svítí, dokud volbu
nepotvrdí. Pole je obecné, ale vyplňuje se zatím jen u tohohle checku.

**3. Můstek do číselníku bankovních účtů** (Task 09, hotové). Překlop z
`base_persons_bank_accounts` vlastní Osoby do
`economy_codebooks_bank_accounts` — dvě různé tabulky, přičemž na vydanou
fakturu jde ta číselníková; překlop je **kopie, žádné FK**, a je to
zamýšlené. Položka `missing_own_bank_account` má primární akci
`bridge_bank_accounts` (jen když má vlastní Osoba aspoň jedno spojení).
Dialog čte `GET /_setup/bank-account-candidates` (příznak
`existsInCodebook` podle IBAN, bez něj podle čísla účtu — už překlopené
účty jsou zašedlé) a ukládá `POST /_setup/bank-accounts`
`{personBankAccountIds, defaultId}`. Uživatel vybírá **zaškrtávacím
seznamem** (D17), takže může překlopit víc účtů naráz; `source = 2`
(Registr DPH API) označuje oficiálně zveřejněné účty a ty se nabízejí
předvybrané. Mapování je téměř 1:1 (`account_number`, `iban`, `bic`,
`currency`, `valid_from/to`); `code` se generuje sekvenčně (`BU1`, `BU2`,
… s posunem přes existující kódy), `name` má fallback z posledního
čtyřčíslí účtu, `bank_name` zůstává `null` (není z čeho, číselník bank
neexistuje) a `sort_order` navazuje na maximum v číselníku. Každý řádek
jde přes `BankAccountDocument` (TableGateway) — validace, normalizace měny
na malá písmena i per-currency unikátnost `is_default` (`afterPersist`) se
přebírají z dokumentu. Server odmítne (`422`, all-or-nothing) cizí id
i účet, který už v číselníku je.

Vrstva A (jazyk, země) se v panelu nezobrazuje — změnit se nedá a pro
rozhodování nic nepřináší.

Nabídky (Fáze 5, hotová) žijí v sekci „Volitelné“ panelu — viz §10;
název a logo řeší existující settings stránka Aplikace.

### 5.5 Agregovaná karta feedu

Dnešní agregace v `AlertsSource` je `GROUP BY check_id` s prahem
`GROUP_THRESHOLD = 3` (tj. 4+). Osm singleton setup alertů by se tedy
**nesbalilo** — každý check má jeden alert, tedy pod prahem.

Proto **nová osa agregace podle tagu** (D8), jako rozšíření `AlertsSource`,
ne jako nový feed zdroj:

- Alerty, jejichž check nese `'setup'` v `tags` (checky mohou mít i další
  tagy), se sbalí do **jedné karty** bez ohledu na počet — bez prahu,
  od jedné položky — a plně nahradí individuální karty daných checků.
- `id = "alert-group:setup"`, titulek *„Dokončit nastavení“*, `kind` podle
  nejvyšší severity ve skupině (agregace nesnižuje viditelnost — stejné
  pravidlo jako u agregace per check), `timestamp = MAX(last_seen_at)`,
  `context = {tag: 'setup', count, severity, group: true}`.
- **Podtitulek podle počtu**: jedna nesplněná položka → její `title`
  (u posledního zbývajícího kroku říká konkrétně, co chybí), dvě a víc →
  počet se správným skloňováním (2–4 položky / 5+ položek).
- Sběr zůstává dvoufázový; tagová skupina se vyhodnocuje **před** skupinami
  per check (fáze 0), aby setup alerty nespadly do obou.
- Lookup do `AlertCheckRegistry` (kvůli tagům a lokalizovaným názvům) tam už
  pro titulky skupinových karet je. Bez registry (`null`) se tagová
  agregace **přeskočí** — fail-open, alerty projdou individuálně.

**Primární akce = `open_panel`** — nový druh akce vedle `open_form`
a `open_viewer`. Payload `{panelId}`. Ve frontendu je to malé: mapa
`panelComponents` i protékání `panelId` přes `Sidebar.handleItemClick`
a `navigationStore.navigate()` už existují.

### 5.6 Hosting

Rozsah je vědomě minimální (D7):

- **Tabulka** `hosting_core_data_sources` — dva sloupce ve skupině `identity`:
  `language` (enumString, cfgItem jazyků) a `country` (enumString, cfgItem
  `world.base.countries`).
- **Formulář** `DataSourcesForm` — dvě pole.
- **Queue payload** — `HostingServerController::buildQueueItem()` přidá
  `language` a `country`. Varianta `peek` je informativní, může zůstat.
- **Agent** — `HostingSyncRunner` je předá do `ds-create` jako `--language`
  a `--country`.

Hosting se vrstvy C nedotýká. Žádné volání registru z agenta, žádné business
řádky, žádná změna v `confirm`.

---

## 6. Plátcovství DPH (Issue #17)

Rozhodnutí D5 je menší změna, než se zdálo: **`vat_mode = 0` („Bez DPH“) už
existuje** (`docs.core.vatModes`) a `DocDocument::validate` při něm Registraci
DPH nevyžaduje. Chybí tedy jen:

1. Globální příznak `economy.vatAgenda` (§5.2).
2. Výchozí `vat_mode` na novém dokladu odvozený z příznaku. Sloupec má
   `"default": 1` na úrovni definice tabulky — odvozený default patří do
   formuláře, ne do schématu.
3. Viditelnost agendy DPH v **navigaci** podle D11 a skrytí sekce „DPH“
   v hlavičce dokladu, když se s DPH nepracuje.

   Skrývání **jednotlivých polí** už implementované je: `DocsHeadsFormBase`
   počítá `$hasVat = $vatMode !== 0` a věší `hidden: !$hasVat` na
   `vat_calc_source`, `vat_place`, `vat_registration`, `vat_duzp`, `vat_dppd`;
   `DocRowsForm` dělá totéž. Vede to z `vat_mode` dokladu, tedy přesně
   podle D10 — nesáhat na to.

   **Mimo rozsah, protože není co skrývat:** viewery dokladů DPH sloupce
   nemají, tiskové šablony faktur v repu neexistují a DPH výstupy
   (přiznání, kontrolní hlášení) jsou neimplementovaný milník M1.

   **Účtování žádnou změnu nepotřebuje:** `AccountingEngine::buildVatLines()`
   staví řádky z `docs_core_vat_recap` a `buildRowLines()` bere
   `vat_base_dom`, takže doklad s `vat_mode = 0` zaúčtuje plnou částku
   a na 343xxx nesáhne.
4. Provisioning instancí tvrzení **v okamžiku vzniku registrace** (hotové,
   revize #55 D9). Uložení registrace přes `VatRegistrationDocument` vyvolá
   `afterSave` documentEventHandler modulu `economy.vat`
   (`VatRegistrationSeedHandler` → `ReportPeriodsProvisioner`), který založí
   instance přiznání / KH / SH pokrývající dnešek a zítřek; denní cron
   `vat-periods-ensure` je dorovnává dál. Kalendářní mřížka období DPH
   zanikla — změna periodicity se projeví u příští generované instance,
   historii dodá import reálných rozsahů.

**Proč varianta 1 a ne `neplátce` jako `taxpayer_kind`:** registrace je
časově omezený fakt (`valid_from`/`valid_to`), takže **absence v intervalu je
přirozené kódování „tehdy jsem nebyl plátce“**. Varianta 2 by nutila každého
neplátce držet fiktivní registraci a k ní existující období DPH, a přechod
plátce → neplátce → plátce by se kódoval překrývajícími se záznamy o něčem,
co neexistovalo. Globální příznak pak řídí jen defaulty a viditelnost, nikoli
pravdu.

Issue #17 je v milníku **M0 — Věcná správnost výpočtů**, tedy může mít vyšší
prioritu než zbytek této oblasti. Body 1–4 jsou implementovatelné samostatně,
jen s tím, že bod 1 předpokládá Fázi 2 (§8).

---

## 7. Provozní poznámky

### 7.1 `ds-reset`

`core_system_settings` je v `keepOnReset` modulu `core.system` — **parametry
vrstvy C reset přežijí**, business řádky ne. To je žádoucí chování: po resetu
zůstává „osnova = npo“ a provisioner ji jen znovu naseeduje, zatímco vlastní
Osoba a Registrace DPH se objeví v checklistu.

Právě tohle je důvod, proč stav průvodce **nesmí** být příznak v settings
(D3): takový příznak by po resetu tvrdil „hotovo“, i když data zmizela.
Dopočítávaný stav se nemůže rozejít s realitou.

### 7.2 Import ze starého Shipardu

Bez backfillu (D9) jsou settings klíče jediným zdrojem pravdy, takže **import
musí parametry zapsat sám** — jinak přeimportovaný zdroj přijde s kompletními
daty a současně s plným checklistem, protože „nerozhodnuto“ se pozná právě po
absenci klíče.

Import zapisuje: `economy.accountChart`, `economy.homeCurrency`,
`economy.fiscalYearStartMonth`, `economy.vatAgenda`. Import už dnes běží pod
`skipProvisioning: true`, takže se ta odpovědnost k němu logicky pojí.
Zápisovou cestou je `POST /_setup/parameters` — jediná validovaná vzdálená
cesta ke čtyřem klíčům. `runProvisioners` na DS se `skipProvisioning`
provisionery přeskakuje: parametry se uloží, seed dorovná `ds-upgrade` až
po zapnutí provisioningu.

Vrstvu A (`language`, `country`) zapisuje `ds-create`, tedy krok před importem.

---

## 8. Fázování

| Fáze | Obsah | Hotovo když |
|---|---|---|
| **1 — Vrstva A** | `country` v `DataSourceConfig`, přepínače `ds-create --language --country`, hosting (dva sloupce, formulář, queue payload, agent) | Nový DS vznikne z hostingu i z konzole s vyplněným jazykem a zemí v `main.json` |
| **2 — Parametry do settings** | Čtyři klíče, provisionery a formuláře je čtou, odložený provisioning osnovy a fiskálních roků (D6), konec `getAccountChart()`/`getDefaultCurrency()` | `ds-upgrade` na čerstvém DS osnovu ani roky nenaseeduje; naseeduje je, jakmile klíč existuje |
| **3 — Setup checky a panel** | Sedm nových checků + služba `SetupChecklist` (D12) + zákaz snooze/dismiss (D13); panel `dsSetup` „Co ještě chybí nastavit“ s ovládáním parametrů (D14); tagová agregace ve feedu (D8) a akce `open_panel` | Čerstvý DS ukazuje jednu kartu „Dokončit nastavení“, panel vyjmenuje chybějící položky a uživatel v něm rozhodne všechny čtyři parametry vrstvy C bez konzole |
| **4 — Průvodce** | Rozšíření panelu `dsSetup` (D15, §5.4): vlastní Osoba z registru + návrh `vatAgenda` (Task 08, hotové), registrace DPH a můstek do číselníku bankovních účtů (Task 09, hotové) | Uživatel projde od čerstvého DS k potvrditelné vydané faktuře bez opuštění panelu |
| **5 — Nabídky** | Nabídka účetních položek (`item_type = 2`) ve dvou sadách podle variantu osnovy, jako jednorázová akce v sekci „Volitelné“ (D18, D19) — Task 10, hotové | Uživatel umí zaúčtovat bankovní poplatek, kurzový rozdíl a zaokrouhlení přímo z řádku dokladu, aniž by na něj cokoli tlačilo |

Fáze 2 a 3 jsou navzájem nezávislé (checky nad chybějícími řádky nepotřebují
settings) a dají se prohodit nebo dělat paralelně; Fáze 4 potřebuje obě.

**Plátcovství DPH jde přednostně.** Issue #17 je v milníku **M0 — Věcná
správnost výpočtů**, takže body 1–4 z §6 se implementují hned poté, co Fáze 2
dodá mechanismus settings klíčů — tedy před zbytkem Fáze 2 i před Fází 3.
Pořadí tasků: vrstva A → hosting → klíče osnovy a fiskálního roku →
plátcovství DPH → domácí měna → setup checky → tagová agregace → field typy
→ průvodce → nabídky.

---

## 9. Dotčené moduly

| Modul / projekt | Dopad |
|---|---|
| `core.system` | `SettingsStore` beze změny (jen nový konzument); panel `dsSetup` v `panels` + `settingsItems` |
| `core.alerts` | Bez změny jádra — nové checky jsou data. Nový druh akce `open_panel` je kontrakt mezi `AlertFinding` a frontendem |
| `base.persons` | Zobecnění `MissingOwnPersonCheck`, nový check na sídlo |
| `economy.codebooks` | Pět checků, čtení `economy.*` klíčů ve `FiscalYearsProvisioner` |
| `economy.accounting` | Check nad osnovou, čtení `economy.accountChart` v `AccountChartProvisioner` |
| `economy.accbal` | `LedgerGenerator` — `home_currency` ze settings místo `main.json` |
| `docs.core` / `docs.invoicesOut` / `docs.invoicesIn` | Odvozený default `vat_mode`, skrytí DPH u neplátce, `home_currency` ze settings |
| `hosting.core` | Dva sloupce, formulář, queue payload (§5.6) |
| jádro (`src/`) | `DataSourceConfig::getCountry()`, `ds-create` přepínače, `HostingSyncRunner`, `DevDashboardController`, `AlertsSource` (tagová agregace) |
| frontend | Panel `dsSetup` (checklist + ovládání parametrů) a panel průvodce, `open_panel` v akcích karet |
| import ze starého Shipardu | Zápis čtyř settings klíčů (§7.2) |

---

## 10. Uzavřené otevřené body

Všechny body, které §0 nechávala otevřené, jsou rozhodnuté:

- **Nabídky vs. checklist** → D19. Nabídky nejsou alerty a žijí jako samostatná
  sekce „Volitelné" v panelu, mimo `SetupChecklist::ORDER` i mimo feed.
  Zvažovaný `kind: offer` v alertech se nedělá — je to víc mašinerie za málo.
- **Jak se panel otevírá poprvé** → neotevírá se sám. Zpřístupňuje ho
  agregovaná karta feedu (D8) a položka v Nastavení. Automatické otevření je
  půl kroku k blokujícímu wizardu, což D4 vylučuje.
- **Rozsah nabízených Položek** → jen **účetní položky** (`item_type = 2`).
  Fakturační položky typu Služba („Práce", „Doprava") se nedělají:
  `docs_core_rows.item` je nullable a účet běžného řádku se bere z masek podle
  operace, takže bez nich nic nespadne. Účetní položky naopak funkční jsou —
  `acc.entry` má `accountSrc: item` a bez nich vzniká `item_account_missing`.
  Sady jsou **dvě**, jedna per varianta osnovy, protože obě osnovy používají
  stejná čísla pro jiné účty (`548100` = Ostatní provozní náklady versus
  Manka a škody). Implementace (Task 10, hotové):
  `modules/economy/items/config/accountingItems{Default,Npo}.jsonc`;
  zaokrouhlovací účty jsou konvence, dedikovaný účet žádná osnova nemá.
  Generování je `POST /_setup/accounting-items` přes `ItemDocument`
  (druh `accounting`, jednotka `pcs`,
  `source_kind = 'setup.accountingItems'`, docState rovnou V pořádku —
  záznam je kurátorský a kompletní, Koncept by jen čekal na ruční
  potvrzení); generátor jedné položky je od content-tag-ui (D26) sdílená
  služba `AccountingItemMaterializer` (`modules/economy/items/src/`) —
  používá ji i `POST /_exchange/content-tags/materialize` (dashboard
  karta „Nová kategorie", settings stránka Obsahové štítky), chování
  setup endpointu beze změny;
  nabídku servíruje `GET /_setup/accounting-items-offer` s gaty
  `chart_undecided` / `chart_none` / `accounting_inactive` a příznakem
  `exists` per kód — opakované generování je bezpečné.
  **D18-rev (Task 11):** sada rozšířena z původních sedmi finančních
  položek na 54 (podnikatelská) / 31 (NPO) ve skupinách — seed má tvar
  `{groups, items}`, offer navíc vrací `groups` a `group` u kandidáta,
  UI je člení do sbalitelných sekcí s tri-state checkboxem. Kódy položek =
  čísla účtů (víc položek na jednom účtu → písmenný sufix, `548100Z`
  zaokrouhlení, NPO `549100B` bankovní poplatky); původní kódy `UP-*`
  zanikly bez migrace. Mzdová agenda je nově zařazena (revize „vědomého
  vynechání" z Tasku 10 — agenda v aplikaci není, ale účtuje se);
  daň z příjmů zůstává jen jako závazkový účet 341100. Podnikatelská
  osnova dostala nový účet `518206` Software a cloudové služby
  (NPO drží jednu analytiku služeb 518100).
- **Pořadí kroků průvodce** → bezpředmětné. Krokový průvodce se nestaví (D15),
  pořadí drží `SetupChecklist::ORDER` a řídí se závislostmi.

### Co zůstává mimo oblast

Zaznamenané v [`tasks/TODO.md`](../tasks/TODO.md), ne v této oblasti:

- dvojí zdroj země vlastního subjektu (`AccountingEngine::resolveOwnCompanyCountry()`
  versus `DataSourceConfig::getCountry()`)
- `dismiss` alertu není trvalý — `AlertReconciler` ho při dalším běhu obnoví
- settings stránky neumí field typy `select` a `checkbox` (D14 je pro tuto
  oblast nepotřebuje)


---

[← docs/README.md](README.md) · [hosting.md](hosting.md) · [alerts.md](alerts.md) · [app-settings.md](app-settings.md)
