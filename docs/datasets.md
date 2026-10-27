# Datové sady — `dataset-dump` / `dataset-seed`

Přenosný obraz obsahu datového zdroje: složka (nebo zip) souborů ve
výměnných formátech, kterou lze naimportovat do jiného (nebo téhož,
zresetovaného) DS. Zadání a rozhodnutí: `tasks/dataset-phase1.md`, issue
shipard/shipard#40 (D1–D5). Pilotní konzument: demonstrační sada pro web.

Fáze 1 pokrývá `dateMode: fixed`, celý DS bez filtrů a *snapshot mode*
došlé pošty (analýza se přenáší jako data — žádné volání AI).

## 1. Struktura sady

```
sada/
  manifest.jsonc                      # shpd.dataset.v1
  setup/<tabulka>.jsonc               # shpd.dataset.setup.v1 — číselníky + DS settings
  exchangeRates/NNNN-<slug(listType-validFrom)>.jsonc  # shpd.exchangeRates.list.v1 — kurzovní lístky (#121 D10),
                                      #   např. 0001-cz-cnb-daily-2026-08-19.jsonc (tečky jdou přes Slug na pomlčky)
  persons/NNNN-<slug>.jsonc           # shpd.persons.person.v1
  items/NNNN-<slug>.jsonc             # shpd.items.item.v1
  workOrders/NNNN-<číslo>.jsonc       # shpd.dataset.workOrder.v1 (obálka zakázky, #131 fáze 2b)
  assets/NNNN-<inventární číslo>.jsonc # shpd.dataset.asset.v1 (obálka karty majetku, #131 fáze 2c)
  docs/NNNN-<slug>.jsonc              # shpd.docs.document.v1
  bank/NNNN-<kód>-<RRRR-MM>.jsonc     # shpd.dataset.bankStatement.v1 (obálka výpisu, #131 fáze 2a)
  registry/NNNN-<slug>.jsonc          # shpd.dataset.registryDocument.v1 (obálka)
  registry/NNNN-<slug>.files/<soubor> # přílohy dokumentu spisovny
  mail/NNNN-<slug>.jsonc              # shpd.mail.incomingMessage.v1
  mail/NNNN-<slug>.files/<soubor>     # přílohy zprávy (vč. raw .eml)
  expected/…                          # volitelné: očekávání pro dataset-verify (#103 F3,
                                      # docs/test-corpus.md § 17) — seed ji ignoruje, do counts nejde
```

- **Názvy souborů** `NNNN-<slug>.jsonc` (čtyřmístné pořadí + ASCII slug
  z přirozeného klíče: název osoby, kód položky, číslo dokladu, titulek,
  `message_id`). Pořadí dává exporter podle přirozených klíčů, ne podle
  interních id — dump → seed → dump na jiném DS dává stejná jména.
- **Přílohy** leží v sidecar složce záznamu; `attachments[].file` v datech
  je jméno souboru v ní (unikátní v rámci záznamu, kolize → `-2`, `-3`).
- **JSON** deterministicky: pretty print, UTF-8 bez escapování, `\n` na
  konci, klíče v pořadí schématu, `null` a prázdná pole vynechaná.
  Výjimka: `analysisJson` / `canonicalJson` v analýzách zpráv se přenášejí
  verbatim (i s `null` a `{}`). Čísla v těchto blobech ukládá seed přes
  `json_encode` bez `PRESERVE_ZERO_FRACTION` — `1.0` skončí v DB i v dumpu
  jako `1`; sada psaná mimo PHP, která má být s dumpem byte-shodná, je
  zapisuje rovnou tak (`docs/test-corpus.md` § 16).
- **Žádná interní id.** Partner jako identifikátory (IČO/DIČ/VAT ID),
  položky přes `ourCode`, účty číslem, jednotky `system_code`, řada přes
  `numberSeriesCode`, vlastní bankovní účet přes `code` číselníku, mailbox a
  AI profil kódem, vazby zpráva ↔ doklad přes číslo dokladu (spisovna:
  titulek + `created`), zakázka číslem (dimenze dokladů, řada zakázek
  kódem, druh zakázky názvem), karta majetku inventárním číslem (dimenze
  dokladů; typ majetku názvem, účetní skupina kódem).

### `manifest.jsonc`

```jsonc
{
  "format": "shpd.dataset.v1",
  "name": "web-demo",                 // [a-z0-9][a-z0-9._-]*
  "title": "Demo webu — den účetní",
  "description": "…",                 // volitelné
  "dateMode": "fixed",                // fáze 1: jen fixed; jiná hodnota = „not implemented"
  "created": "2026-08-26T10:00:00Z",
  "country": "cz",                    // volitelné — země vlastní firmy (#103 D24)
  "profile": "vat-payer",             // volitelné, informativní (profil korpusu)
  "provision": { "accountingItems": "all" },   // volitelné — jednorázové akce seedu (#103 D22)
  "counts": { "setup": 5, "persons": 12, "items": 8, "docs": 15, "registry": 4, "mail": 6 }
}
```

`counts` jsou informativní — počty **souborů** per sekce; nesoulad při seedu
je varování, ne chyba. Třídy: `DatasetManifest`, `DatasetReader` (složka i
zip, guard proti `..`), `DatasetWriter` (`modules/core/exchange/src/Dataset/`).

Volitelná pole z #103 F2 (`tasks/testing-phase2.md`):

- `country` — ISO 3166-1 alpha-2 malými. Seed porovná se zemí zdroje dat
  (`DataSourceConfig::getCountry()`; DS bez klíče v `main.json` se bere
  jako `cz`) **už v preflightu** — sada jiné země skončí chybou a nic se
  nezmění. Bez `country` se nic neověřuje.
- `profile` — klíč profilu, ze kterého sada vznikla; seed ho nečte.
- `provision` — akce, o které sada žádá nad rámec záznamů. Jediný známý
  klíč: `accountingItems`: `"all"` = všechny položky nabídky účetních
  položek podle osnovy DS, seznam řetězců = jen tyto kódy nabídky. Neznámý
  klíč nebo jiný tvar = chyba manifestu. Dřívější `serverAi` zaniklo
  (#126 D9): serverové AI připojení pro vývoj plyne z třídy dat, kterou
  `dataset-seed` po úspěšném resetu nastaví na `free` (`docs/cli.md`
  § `dataset-seed`). Nabídka zůstává jednorázová akce
  (`docs/ds-setup.md` D18) — `ds-upgrade` položky nevrací ani nedoplňuje.

### Sekce `setup/` (R1)

`ds-reset` + provisionery obnoví jednotky, druhy položek, účtový rozvrh,
fiskální roky, DPH období, číselné řady, default mailbox a účetní skupiny
majetku — ale jen když znají rozhodovací parametry DS, a neobnoví číselníky,
které jsou uživatelská data. Sada proto nese (`SetupExporter::TABLES`):

| Soubor | Tabulka | Klíč | Poznámka |
|--------|---------|------|----------|
| `settings.jsonc` | `core_system_settings` | `key` | jen `economy.*` + `app.name/shortName/theme/shell`; aplikuje se **před** resetem (tabulka je `keepOnReset`, provisionery ji čtou). `app.name` = název firmy v záhlaví aplikace: demo sada ho dostává od generátoru z `own.name` příběhu (#103 D135); protože tabulka přežije reset a `SetupSeeder` maže jen vyloučené klíče, přežije `app.name` i přeseedování jinou sadou, která ho nenese. **Bez** `economy.assets.accountingSeries` a `economy.vat.filingAccountingSeries` (`SetupExporter::SETTINGS_EXCLUDED`, #131 fáze 2c D42): jsou to id řad dokladů, která na jiném zdroji dat neplatí a která by po resetu zablokovala provisioner řady „Majetek“ — po resetu je nastaví `ds-upgrade`; seed je ve starší sadě přeskočí. Tytéž klíče maže i samotný `ds-reset` podle deklarací `clearSettingsOnReset` modulů ([docs/modules.md](modules.md)); konstanta exportéru je jejich kopie hlídaná testem |
| `accounts.jsonc` | `economy_accounting_accounts` | `number` | jen **nesystémové** účty (`is_system = 0`) — analytiky založené nad seedem, např. 221200 pro bankovní účet v cizí měně (#131 fáze 2a); systémové obnoví `AccountChartProvisioner`. Seeduje se abecedně před `bank_accounts.jsonc`, takže `accounting_account` číslem se dohledá |
| `bank_accounts.jsonc` | `economy_codebooks_bank_accounts` | `code` | vlastní účty (vydané faktury ve 40 ho vyžadují); `accounting_account` = číslo analytiky 221 (bez něj bankovní engine hlásí `bank_account_not_found`) |
| `cash_desks.jsonc` | `economy_codebooks_cash_desks` | `code` | pokladny; výchozí (`is_default`) dostane v apply doklad placený hotově bez `cashDesk` (#103 F3) |
| `cost_centers.jsonc` | `economy_codebooks_cost_centers` | `code` | střediska — doklady v sadě na ně odkazují kódem v objektu `dimensions` (#110 T2) |
| `vat_registrations.jsonc` | `economy_codebooks_vat_registrations` | `country`, `name` | `DocDocument` vyžaduje při `vat_mode != 0` |
| `binders.jsonc` | `base_registry_binders` | `name` | šanony spisovny |
| `mailboxes.jsonc` | `core_mail_mailboxes` | `mailbox_id` | `default` po resetu existuje, jen se aktualizuje |
| `work_order_kinds.jsonc` | `economy_work_orders_kinds` | `name` | druhy zakázek (#131 fáze 2b, D29): periodický druh nese řadu dokladů `{docType, code}` (D37) a účet kódem |
| `work_order_series.jsonc` | `economy_work_orders_number_series` | `number_code` | číselné řady zakázek — druh názvem; řada bez kódu se nepřenese (dump varuje); sekce `workOrders/` odkazuje řadu kódem |
| `asset_accounting_groups.jsonc` | `economy_assets_accounting_groups` | `code` | účetní skupiny majetku (#131 fáze 2c, D39): účty osnovy číslem; skupiny zakládá i `AccountingGroupsProvisioner` (jen ty s úplnými účty v osnově), upsert podle kódu je jen srovná — dump proto nese všechny, které na zdroji dat jsou |
| `asset_type_groups.jsonc` | `economy_assets_type_groups` | `name` | skupiny typů majetku |
| `asset_types.jsonc` | `economy_assets_types` | `name` | typy majetku: skupina typů názvem, výchozí účetní skupina kódem; sekce `assets/` odkazuje typ názvem a účetní skupinu kódem |

Řádky = sloupce tabulky bez `id`, `docStateMain` a auditních sloupců. Seed =
upsert přes `TableGateway` podle klíče. Soubory pro tabulky, které na
cílovém DS nejsou (jiná sada modulů), se přeskočí.

**Cizí klíče přes přirozený klíč** (`SetupReferences`, #131 fáze 2b D29,
D37) — jediné místo, které říká, kam FK sloupec sady ukáže a jak se hodnota
překládá tam (dump) a zpět (seed):

- tabulka ze `SetupExporter::TABLES` s jednosloupcovým klíčem → hodnota
  klíče (druh zakázky názvem, bankovní účet kódem, středisko kódem, typ
  majetku a skupina typů názvem, účetní skupina majetku kódem, …);
- `docs_core_number_series` → objekt `{"docType": "invno", "code": null}`,
  `null` se v souboru vynechá (`{"docType": "invno"}`). Výchozí řady
  z provisioneru kód nemají, samotný kód by je nedohledal (D37). Seed hledá
  jako `DocumentApplier::resolveNumberSeriesFor`, ale přísně: s kódem přesná
  shoda `(doc_type, doc_number_code)`, bez kódu řada typu s prázdným kódem;
  žádná nebo víc než jedna = chyba řádku;
- `economy_accounting_accounts` → číslo účtu jako dřív (chybějící
  analytika = `null`, ohlásí ji až engine);
- ostatní reference (osoby, uživatelé) se s varováním vynechají.

Nenalezený nebo nejednoznačný klíč je chyba řádku (soubor `failed`) — nikdy
tiché vynechání ani první nalezený. Záznam v koši se nepočítá, archivovaný
ano.

### Obálka zakázky `shpd.dataset.workOrder.v1` (#131 fáze 2b, D30)

Jeden soubor = jedna zakázka nad importním formátem
`shpd.workOrders.workOrder.v1` ([exchange-format.md](exchange-format.md)
§ Zakázky, `tasks/work-orders-import.md`): stejná struktura (`workOrder`,
`rows`, `periods`), místo id přirozené klíče. Schéma
`modules/core/exchange/schemas/`, třídy `modules/economy/workOrders/src/Dataset/`
(`WorkOrderExporter`, `WorkOrderSeeder`, `WorkOrderPeriodsSeeder`).

```jsonc
{
  "format": "shpd.dataset.workOrder.v1",
  "workOrder": {
    "number": "S0001", "sequenceNumber": 1,
    "numberSeries": "S",                 // kód řady zakázek (setup/work_order_series.jsonc)
    "title": "Správa IT — Autodoprava Kopretina s.r.o.", "state": "confirmed",
    "dateStart": "2024-04-01",           // dateEnd jen u ukončené
    "costCenter": null,                  // kód střediska
    "customer": { "name": "…", "country": "cz", "companyId": "…", "taxId": "…", "vatId": "…" },
    "currency": "czk", "paymentReference": null, "parent": null,   // parent = číslo nadřazené
    "invoicing": {                       // jen periodický typ; chybějící přepis = hodnota druhu
      "periodicity": "month", "invoiceFrom": "2026-01-01",
      "docText": "Správa IT — paušál {období}",
      "numberSeries": { "docType": "invno" },   // řada dokladů {docType, code} (D37)
      "bankAccount": "BU2"               // kód vlastního účtu
    }
  },
  "rows": [                              // předpis — jen periodický typ
    { "item": "SPRAVA-IT", "description": "Správa IT — měsíční paušál", "quantity": 1.0, "unit": "mnth",
      "unitPrice": 16000.0, "vatCode": "cz-120", "validTo": "2026-05-31" }
  ],
  "periods": [                           // jen vystavená období s dokladem
    { "from": "2026-01-01", "to": "2026-01-31", "state": "issued", "docNumber": "1260001" }
  ]
}
```

- **Zápis jen přes `WorkOrderImportApplier`** (hlavička v Konceptu → řádky
  → cílový stav přes V pořádku; existující číslo = `skipped`). Seeder
  přeloží klíče na id: řada zakázek kódem a středisko kódem
  (`SetupReferences`), zákazník jen podle identifikátorů (`PartyResolver`,
  nenalezený = chyba — osoba musí být v `persons/`), řada dokladů
  `{docType, code}` a účet kódem, položka `ourCode` (`ItemResolver`,
  nenalezená = chyba); jednotka kódem dohledá verifier importu. Hlavičku ani
  řádky seeder nepíše mimo applier.
- **Strana** je štíhlá identita (název, země, IČO, DIČ, VAT ID) — osoba sama
  je v `persons/`.
- **`periods[]`** nese jen vystavená období (`issued` s dokladem mimo koš,
  `docNumber` = číslo dokladu mezi vydanými doklady; koncept zástupným
  `!` + id). Naplánovaná, čekající a zastavená období dump vynechá s jedním
  varováním per zakázka; `result`, `message`, `waiting_since`
  a `content_hash` v souboru nejsou. `content_hash` spočítá seeder
  z uloženého dokladu (`ContentHash::ofStoredDocument`) stejně jako běh po
  apply — shoda otisku pak znamená „koncept nikdo ručně neupravil“.
- **Pořadí seedu:** zakázky za osobami a položkami, **před doklady** (doklady
  je odkazují číslem v `dimensions`); evidence období až **za doklady**
  (`WorkOrderPeriodsSeeder`, sekce `workOrderPeriods` bez souborů a `counts`
  jako `fiscalYears`). Periodický běh se při seedu nespouští (D19).
- **Pořadí souborů:** nadřazené před podřízenými (hloubka v řetězci), pak
  bez čísla, pak podle čísla; slug = číslo zakázky. Zakázka řady bez kódu se
  nepřenese (varování), stav V opravě se přenáší jako V pořádku.
- **Dimenze zakázky na dokladech** je přenosná jen s touto sekcí (dump ji
  `DocumentExporter` dostane jako `$sectionTables`): hodnota = číslo
  zakázky na hlavičce i řádcích. `DimensionResolver` najde i ukončenou
  zakázku (jen koš se vylučuje), deník dědí dimenzi hlavičky do řádků bez
  vlastní — faktury z periodické fakturace ji nesou jen na hlavičce, jako je
  skládá `InvoiceBuilder`.
- **Meze:** řada zakázek s ročním restartem potřebuje fiskální rok data
  zahájení (`FiscalYearsSeeder` data zakázek nečte — demo sada používá
  průběžné řady, D34); koncept zakázky bez čísla klíč nemá (dump ho vypíše
  se slugem `koncept`, opakovaný seed ho založí znovu); řádky přispěvatelů
  (`contributor`) formát nemá.

### Obálka karty majetku `shpd.dataset.asset.v1` (#131 fáze 2c, D38, D40)

Jeden soubor = jedna karta majetku nad importním formátem
`shpd.assets.asset.v1` ([exchange-format.md](exchange-format.md) § Majetek,
[assets.md](assets.md) §5.7): stejná struktura (`asset`, `events`), místo
id přirozené klíče a navíc původ události. Schéma
`modules/core/exchange/schemas/`, třídy `modules/economy/assets/src/Dataset/`
(`AssetExporter`, `AssetSeeder`, `AssetEventsSeeder`).

```jsonc
{
  "format": "shpd.dataset.asset.v1",
  "asset": {
    "assetNumber": "MA0001",             // klíč párování, převezme se beze změny
    "name": "Server 1U, 2× CPU, 64 GB RAM",
    "type": "Výpočetní technika",        // název typu (setup/asset_types.jsonc)
    "category": "tangible", "tracking": "single",
    "accountingGroup": "022",            // kód účetní skupiny (setup/asset_accounting_groups.jsonc)
    "taxMethod": "straight", "taxRule": "cz-1", "accMethod": "as_tax",
    "state": "confirmed"                 // draft | confirmed (i V opravě) | archived
  },
  "events": [                            // potvrzené události chronologicky
    { "kind": "activation", "scope": "both", "date": "2026-02-14", "amount": 150000.0, "origin": "manual" }
  ]
}
```

- **Evidence, ne zaúčtování (D38).** Sada nese kartu a potvrzené události
  s původem, jaký mají v databázi. `AssetSeeder` zakládá kartu přes
  `AssetDocument` a `AssetEventsSeeder` události přes `AssetEventDocument`
  stejně jako uživatel — **bez importního módu**: událost s `origin:
  manual` dostane původ `manual`; jen `origin: import` (zdroj dat po
  migraci) se ukládá s markerem `_import` a původ `import` zůstává.
  `AssetImportApplier` seed nepoužívá — jeho importní mód by dal
  zařazení původ `import` a kontrola evidence × deník by ho brala jako
  zaúčtované ve starém systému (D76).
- **Co dump nepřenáší:** systémové odpisy (původ `system` — odvozené,
  po seedu je založí znovu Odpisy za období; jedno varování per karta),
  nepotvrzené události (varování), vazbu události na účetní doklad
  (`doc_head`), kartu bez inventárního čísla (koncept bez identity,
  varování) a **doklady zaúčtování majetku** — doklad s řádky `asset.*`
  `DocumentExporter` vynechá s varováním per doklad (§2). Dlouhodobý
  majetek datum pořízení, vyřazení ani cenu nenese (nesou je události,
  D13); `acquiredDate`, `disposedDate` a `price` platí jen drobnému
  majetku. Booleany (`foreign`, `halfYear`, `claimUnrecorded`,
  `priceIncreased`) jsou v souboru jen jako `true`.
- **Překlad klíčů při seedu:** typ názvem a účetní skupina kódem přes
  `SetupReferences` (nenalezený = chyba), vlastník cizího majetku jen
  identifikátory (`PartyResolver`, nenalezený = chyba — osoba musí být
  v `persons/`). Dlouhodobá karta se ukládá ve stavu V pořádku; do archivu
  ji přesune až potvrzené vyřazení (D38), drobný majetek dostane cílový
  stav přímo. Karta se stejným číslem (libovolný stav) = `skipped`; karta,
  která už nějakou událost má (`--no-reset`), události nedostane
  (`skipped`). Všechny události jedné karty jdou v jedné transakci,
  odmítnutá událost = chyba záznamu s cestou do obálky (`events[2].amount`).
- **Pořadí seedu:** karty za zakázkami, **před doklady** — řádky pořízení
  (`purchase.asset`, účet 042xxx přímo na řádku) je odkazují inventárním
  číslem v `dimensions.asset`; události až **za doklady** (sekce
  `assetEvents` bez souborů a `counts`, jako evidence období zakázek).
  `FiscalYearsSeeder` bere i data událostí — ruční událost mimo založený
  účetní rok by dokument odmítl (`outsideFiscalYear`).
- **Pořadí souborů** podle inventárního čísla; slug = inventární číslo.
  Stav V opravě se přenáší jako V pořádku.
- **Dimenze majetku na dokladech** je přenosná jen s touto sekcí
  (`$sectionTables` exportéru dokladů): hodnota = inventární číslo na
  hlavičce i řádcích; řádek pořízení kartu z hlavičky nedědí (vlajka
  `rowAsset`, `docs/assets.md` §5.5).

### Obálka spisovny `shpd.dataset.registryDocument.v1` (R3)

`shpd.registry.document.v1` nenese stav, původ, šanon ani přílohy —
obálka je doplňuje: `{format, document: <canonical>, docState, sourceKind,
binder (název), refNumber, validFrom, validTo, notice, created,
attachments[]}`. Schéma `modules/base/registry/schemas/`. Seed staví data
přes `RegistryApplier::buildDocumentData()` a vkládá Document hooky
(chybějící šanon založí). Dokumenty spisovny přílohy **vlastní** (kopie
ze zprávy), doklady ne — ty je zobrazují ze zdrojové zprávy přes
`source_message`, proto stačí obnovit lineage.

### Obálka výpisu `shpd.dataset.bankStatement.v1` (#131 fáze 2a, D17)

Jeden soubor = jeden výpis jednoho našeho účtu (typicky kalendářní měsíc)
nad kanonickým `shpd.bank.statement.v1`: `{format, bankAccount (code
číselníku), statement {statementNumber, externalId, periodStart, periodEnd,
openingBalance, closingBalance, currency}, transactions[], docState}`.
Schéma `modules/core/exchange/schemas/`, třídy
`modules/economy/bank/src/Dataset/` (`BankStatementExporter`,
`BankStatementSeeder`). Pravidla:

- **Žádná interní id.** Účet kódem (`setup/bank_accounts.jsonc`), partner se
  nepřenáší vůbec — po seedu ho dohledá applier z protiúčtu
  (`StatementImportService::resolvePartner`, bankovní spojení osoby: číslo
  účtu i IBAN) stejně jako při importu souboru.
- **Transakce nese jen to, co dodá banka:** `externalId`, znaménkový
  `amount` (− = výdaj), data, protiúčet a název, VS/SS/KS, zpráva. Dopočtené
  `partner`, `amount_dom`, `accounting_state`, `fingerprint` v souboru
  nejsou. `operation` jen výslovně nastavená (poplatek `fee.out`, úrok,
  převod) — výchozí `payment.in` / `payment.out` dump vynechá a applier
  doplní podle směru. `exchangeRate` jen u účtu v cizí měně (jinak by
  applier hledal kurzovní lístky a výpis by se nezapsal); na účtu v domácí
  měně se kurz ignoruje.
- **`docState`** = cílový stav výpisu i transakcí (10 / 40); stav 40 přes
  `BankTransactionEventHandler` zaúčtuje a mikroengine dohledá otevřený
  předpis — proto se sekce seeduje **až za `docs/`**. Transakce v jiném
  stavu než výpis dá při dumpu varování a po seedu má stav výpisu.
- **Dedup** podle `externalId` per účet (unikátní index); bez něj fingerprint
  s pořadím v rámci dne. Sada nese `externalId` stabilní a deterministické.
- **Pořadí:** výpisy podle kódu účtu a `periodStart`, transakce podle
  `date_transaction` a `id` (DB pořadový sloupec nemá — pořadí = vložení);
  sada seřazená podle data tedy přežije seed beze změny. Slug souboru
  `<kód>-<RRRR-MM>`.

### `shpd.mail.incomingMessage.v1` (D2, R4)

Schéma `modules/core/mail/schemas/`. Hlavičky, tělo, stavy (`docState`,
`analysisState`), `target` (vazba na doklad / dokument), `attachments[]`
(`isRawSource` pro `.eml`), `analyses[]` — snapshot běhů s `analysisJson` a
`canonicalJson` verbatim. Odkazy `att:<id>` v canonicalu se při dumpu
přepisují na `att:<pořadí v attachments[]>` (1-based) a při seedu zpět na
nová id — jinak by apply návrhu ze seedované zprávy nesedělo a sada by
nebyla stabilní. `message_id` (`MSG-…`) se zachovává;
`IncomingMessageDocument` generuje jen prázdný.

Pozornost u zprávy bez dokladu (#105, #131 krok 0b): volitelná pole
`attention` (`action` / `info` / `promo`), `actionNote` a `actionDue`
(datum) za `aiTitle` — AI-vlastněné sloupce, které jinak plní jen živá
analýza (`AnalysisResultWriter`). Seed je zapíše jen když klíč v obálce je,
dump je vypisuje; zpráva `other` ve stavu Nová s `attention: action` tak
po seedu dá kartu „K vyřízení“ s lhůtou i bez volání AI.

## 2. `dataset-dump`

`shpd-ds dataset-dump <dir> [--zip[=cesta]] [--force] [--name] [--title] [--description]`
(reference `docs/cli.md`). Bere všechny záznamy s `docState != 90` (archiv
80 ano, koš ne). `DatasetDumper` řídí exportery podle aktivních tabulek DS:
`PersonExporter`, `ItemExporter`, `DocumentExporter`, `SetupExporter`
(`modules/core/exchange/src/Export/`), `WorkOrderExporter`
(`modules/economy/workOrders/src/Dataset/`, #131 fáze 2b — před doklady),
`AssetExporter` (`modules/economy/assets/src/Dataset/`, #131 fáze 2c —
před doklady), `BankStatementExporter`
(`modules/economy/bank/src/Dataset/`, #131 fáze 2a), `RegistryExporter`
(`modules/base/registry/src/`), `MailExporter`
(`modules/core/mail/src/Dataset/`), `ExchangeRatesExporter`
(`modules/economy/exchangeRates/src/Dataset/`). Každý má `exportAll()` a
`exportByIds()` (R7 — integrační test) a `getWarnings()`; varování příkaz
vypíše, exit code zůstává 0.

**Kurzovní lístky** (#121 D10, U7): sekce `exchangeRates/` nese právě ty
lístky, které `ExchangeRateResolver` použije pro účetní data dokladů
(`accounting_date`) a návrhů z pošty (`canonicalJson.dates.accountingDate`,
bez něj `issueDate`) v cizí měně — ne celou historii lístků zdroje dat.
Soubor = formát `shpd.exchangeRates.list.v1` ([exchange-rates.md](exchange-rates.md)
§2) bez zdroje a id, název `NNNN-<listType>-<validFrom>.jsonc`. Doklad
v cizí měně bez lístku k datu dá varování („kurz EUR k … není v lístcích“)
— po seedu kurz nedostane. Sada bez cizí měny sekci nemá.

**Účetní položky z nabídky osnovy** (#131 krok 0c): nezměněné položky
se `source_kind = setup.accountingItems` (kód v nabídce aktivní varianty,
název = lokalizovaný název nabídky v jazyce DS, účet a štítky podle
nabídky) `ItemExporter` do `items/` nepíše — manifest je nese jako
`provision.accountingItems` (`"all"`, když pokrývá celou nabídku s účty
v osnově, jinak seznam kódů) a seed je založí znovu z nabídky
(`ProvisionedItemsDetector`, `modules/economy/items/src/Dataset/`).
Přejmenovaná nebo přeúčtovaná položka se exportuje jako každá jiná.
Manifest dumpu nese i `country` zdroje dat (pojistka seedu, D24).

**Pokladní doklady** (#131 krok 0a): `DocumentExporter` vypisuje
`cashDocument` (`cash`) a `cashRegisterDocument` (`cashreg`) s `cashDesk`
(kód pokladny) a u pokladního dokladu `cashDirection`; faktura placená
hotově nese `cashDesk` také. Strany: prodejka jako vydaná faktura,
pokladní doklad podle směru (příjem jako vydaná, výdej jako přijatá vč.
bankovního spojení dodavatele).

Exportery jsou zrcadlem applierů (`DocumentApplier::transform()`,
`PersonApplier::transformHeaderForCreate`, …). Odvozené sloupce se
neexportují (fiskální rok, DPH období, domácí měna, snapshoty partnera,
účetní stav, rekapitulace je jen informativní) — při seedu je spočítá
`DocDocument`.

**Dimenze deníku na dokladech** (#110 T2): `DocumentExporter` vypisuje
objekt `dimensions` na hlavičce i řádcích s přirozeným klíčem
(`exchangeKey` dimenze), ale jen u dimenzí, jejichž cílovou tabulku sada
nese v `setup/` — středisko (`cost_centers.jsonc`) — nebo ve vlastní sekci
(`$sectionTables`: zakázka číslem se sekcí `workOrders/`, #131 fáze 2b;
karta majetku inventárním číslem se sekcí `assets/`, fáze 2c). Dimenzi
na záznamy mimo sadu (zdroj dat bez příslušné sekce) dump vynechá — seed
by doklad odmítl `dimension_not_found` — a ohlásí to jedním varováním per
dimenze s počtem dokladů.

**Zaúčtování majetku** (#131 fáze 2c, D38): doklad s řádky `asset.*`
(dávka Odpisy za období — zařazení, odpisy, vyřazení) `DocumentExporter`
vynechá s varováním per doklad. Je odvozený z evidence majetku, kterou
sada nese v `assets/`; po seedu ho založí znovu Odpisy za období
(applier by řádky systémových operací stejně odmítl).

## 3. `dataset-seed`

`shpd-ds dataset-seed <dir|zip> [--no-reset] [-y]`. Průběh:

1. **Preflight** (`DatasetPreflight`): manifest, země sady vs. země DS
   (`country`, #103 D24), každý soubor proti schématu (objektová forma —
   `{}` nesmí zdegenerovat na `[]`), sémantické validátory
   (`PersonValidator`, `ItemValidator`, `DocumentValidator`), přítomnost
   příloh, `counts`. Chyba = nic se nemění.
2. Potvrzení (bez `-y`).
3. Čerstvý DS po `ds-create` nemá schéma (`core_system_settings` chybí) →
   `ds-upgrade` ve stejném procesu, aby měl zápis nastavení kam (jen
   v reset režimu). `ds-create --country cz --no-cron` → `dataset-seed`
   tedy funguje bez ručního mezikroku.
4. `setup/settings.jsonc` → `SettingsStore` (jen v reset režimu).
5. `ds-reset --yes` delegací ve stejném procesu (respektuje `enableReset`).
   `--no-reset` krok přeskočí: osoby a položky jedou v `mergeAdd`, duplicitní
   číslo dokladu / kód zprávy je chyba záznamu.
6. `DatasetSeeder` → seedery v pořadí sekcí (`SectionSeeder`):
   `SetupSeeder`; **`FiscalYearsSeeder`** (#103 D23, `modules/economy/codebooks/src/Dataset/`):
   projde data sady — `docs/*.jsonc` `dates.{issueDate, accountingDate,
   taxPointDate, vatObligationDate}`, `mail/*.jsonc` `receivedAt` a tatáž
   pole v `analyses[].canonicalJson.dates` (splatnost a období plnění rok
   neurčují) — a pro každý dotčený fiskální rok zavolá
   `FiscalYearsProvisioner::ensureYearForDate()` (zajistí právě rok
   obsahující datum; `provision()` by při existujícím roce založil
   následující). Existující i založený rok = `ok`; nerozhodnutý první
   měsíc / domácí měna při datech v sadě = chyba (jinak by doklady vznikly
   s `fiscal_year = null`). **`ExchangeRatesSeeder`** (#121 D10,
   `modules/economy/exchangeRates/src/Dataset/`), jen když zdroj dat má
   tabulku lístků: každý soubor `exchangeRates/` přes
   `ExchangeRateListImporter` se zdrojem *datová sada* — `created`
   i `skipped` (shodný lístek už je) = `ok`, lístek s jiným obsahem
   (`conflict`) = chyba, seed nepřepisuje; běží před doklady a zprávami,
   aby applier doplnil kurz dokladů a návrhů v cizí měně. **`AccountingItemsSeeder`** (#103 D22,
   `modules/economy/items/src/Dataset/`), jen když manifest nese
   `provision.accountingItems`: tatáž orchestrace jako průvodce nastavením
   (`AccountingItemsGenerator` — brána osnovy, prerekvizity, položky přes
   `ItemDocument`); založená i existující položka = `ok`, účet mimo osnovu =
   `skipped` s varováním, neznámý kód nebo nedostupná nabídka = chyba.
   Oba kroky běží i s `--no-reset` (idempotentní). Pak `PersonSeeder`,
   `ItemSeeder`, **`WorkOrderSeeder`** (#131 fáze 2b,
   `modules/economy/workOrders/src/Dataset/`, jen s tabulkami zakázek:
   obálka → payload importu s id → `WorkOrderImportApplier`; před doklady,
   protože ty zakázku odkazují číslem), **`AssetSeeder`** (#131 fáze 2c,
   `modules/economy/assets/src/Dataset/`, jen s tabulkami majetku: karta
   přes `AssetDocument` bez importního módu; před doklady — řádky pořízení
   odkazují kartu inventárním číslem), `DocumentSeeder` (applieři;
   `DocumentApplier` s `DocumentEventDispatcher` → doklady ve 40 se
   zaúčtují), **`WorkOrderPeriodsSeeder`** (evidence vystavených období
   z `periods[]` — doklad číslem mezi vydanými doklady, `content_hash`
   z uloženého dokladu; sekce `workOrderPeriods` bez souborů),
   **`AssetEventsSeeder`** (události karet z `events[]` přes
   `AssetEventDocument`, potvrzené, chronologicky, jedna transakce per
   karta; sekce `assetEvents` bez souborů), **`BankStatementSeeder`** (#131 fáze 2a, `modules/economy/bank/src/Dataset/`,
   jen když zdroj dat má tabulku transakcí): kód účtu → id, výpis přes
   `BankStatementApplier` s dispatcherem — stav 40 zaúčtuje a dohledá
   předpisy z právě naseedovaných dokladů; `_result.txErrors` = chyba,
   přeskočené transakce (dedup) = varování. `FiscalYearsSeeder` bere
   i období výpisů.
   `RegistrySeeder`, `MailSeeder` (zpráva + přílohy přes
   `AttachmentService` + analýzy + lineage `target_table_id/target_row` ↔
   `source_message`; jedna zpráva = jedna transakce).
7. Souhrn ok / failed / skipped per sekce (sekce `fiscalYears`,
   `accountingItems`, `workOrderPeriods` a `assetEvents` nemají soubory
   ani `counts`),
   varování, chyby;
   nenulový exit code při jakékoli chybě záznamu.
8. Záznam sady v `config/main.json` (#103 F6b D59): po úspěšném seedu
   s resetem `dataset: {name, hash, seededAt}` (`hash` =
   `EvalRunStore::datasetHash()` nad `mail/` + `expected/` + `exchangeRates/`,
   #103 D81), smazaný už na
   začátku resetu a odebraný při `--no-reset`. Čte ho `shpd-server ds-list
   --json` a panel Zdroje dat vývojářského dashboardu (aktuální /
   zastaralá sada).

Applieři cílí jen stavy 10/40 — stav 70/80 osob a položek seed po uložení
dorovná přímým UPDATE. Varování applierů (např. `account_not_found`, když DS
nemá účtový rozvrh) se propouštějí do souhrnu.

## 4. Round-trip a co formát nenese

Ověřeno na jednorázovém DS (`ds-create` → `dataset-seed` sady z dev DS →
`dataset-dump`): dump → seed → dump je **byte-shodný modulo
`manifest.created`** (a `title`, který bez `--title` defaultuje na název DS).
Proti zdrojovému DS shodné počty osob/adres/účtů/položek/dodavatelských
kódů/dokladů/zpráv/příloh i deník (40 řádků, součty MD = D). Integrační
test: `tests/Integration/Dataset/DatasetRoundTripTest.php`.

Vědomé normalizace a limity fáze 1:

- `rows[].orderPos` applier přečísluje od 1 (zdroj s `order_pos = 0` se
  po prvním cyklu ustálí).
- `source.fetchedAt` osob a položek se neexportuje — applier razítkuje
  okamžik importu.
- Doklady importované s `importNumber` nestaví snapshoty partnera
  (`DocDocument::importMode`); detail dokladu bere partnera živě.
- Řádkový partner účetního dokladu a hlavičkový partner `cmnbkp` se
  nepřenáší (formát ho nemá) → warning při dumpu.
- Vlastní bankovní účet bez `code` v číselníku nelze přenést → warning.
- `app.icon` / `app.companyLogo` (soubory v `branding/`) se nepřenáší.
- Fiskální roky po resetu zakládá provisioner jen pro aktuální rok; roky
  dat sady doplní `FiscalYearsSeeder` (§3).
- Kurzovní lístky: dump nese jen lístky použité resolverem pro doklady
  a návrhy v cizí měně (§2, U7); `source` lístku se nepřenáší (po seedu
  *datová sada*), `rate` je číslo za `amount` jednotek a kurz za jednotku
  se počítá až při dohledání — dump → seed → dump je shodný, kurz
  dokladu po seedu je `round(rate / amount, 6)`.
- Koncept bez čísla dostane po seedu zástupné číslo `!` + id doplněné
  nulami (`DocDocument`); dump ho vypíše v `importNumber.docNumber`
  (`sequenceNumber: null`) a slug souboru je z něj — na čerstvém zdroji
  dat je id = pořadí vložení, takže sada psaná mimo PHP ho předpočítá.
- Kontační řádek bez množství (`acc.record`) vyjde z dumpu s `unitPrice:
  0.0` a `priceCalcMode: fromTotal`; celková částka účetního dokladu je
  obrat strany MD (`AccountingDocument`), kontrola součtů validátoru
  (`totals_mismatch`) to u `accountingDocument` respektuje.
- Řádek bez `description` dostane popis = název položky.
- Bankovní výpisy (#131 fáze 2a): partner transakce se nepřenáší — po seedu
  ho určí protiúčet, ručně přiřazený partner bez shody v bankovních
  spojeních se ztratí; výchozí pohyb `payment.*` a kurz na účtu v domácí
  měně dump vynechá; transakce ve stavu jiném než výpis dostane po seedu
  stav výpisu (dump varuje). Saldokonto je derivát deníku — sada ho
  nenese, po seedu vzniká z párování.
- Zakázky (#131 fáze 2b): období bez vystaveného dokladu (naplánované,
  čekající, zastavené) a výsledky běhů se nepřenáší, `content_hash` vzniká
  při seedu znovu; stav V opravě se vrátí jako V pořádku; zakázka řady bez
  kódu a koncept bez čísla identitu v sadě nemají.
- Majetek (#131 fáze 2c, D38): systémové odpisy (původ `system`),
  nepotvrzené události, vazba události na doklad (`doc_head`) a doklady
  zaúčtování majetku (řádky `asset.*`) se nepřenáší — po seedu je založí
  znovu Odpisy za období; karta bez inventárního čísla identitu v sadě
  nemá; stav V opravě se vrátí jako V pořádku; dlouhodobá vyřazená karta
  skončí v archivu jen přes potvrzené vyřazení.
- Mimo rozsah: `dateMode: relative`, filtry dumpu, scénářové akce a
  expected výsledky, live mode AI.

## 5. Demo sady

Sady pro ukázku aplikace a web (`demo/datasets/`, `tasks/demo-dataset-phase1.md`,
#131) vznikají **generátorem z ručně psaného příběhu firmy**
(`demo/datasets/stories/<název>/` → `demo/datasets/<název>/`, vstupní bod
`demo/datasets/build.py` nad balíkem `invoicegen`). Generátor vyrábí
soubory ve tvaru dumpu — sada je s `dataset-dump` naseedovaného zdroje dat
bajtově shodná kromě `manifest.created` (D10), což hlídá postup
v `demo/datasets/README.md`. Data příběhu jsou relativní ke kotvě
(`--anchor`), identity fiktivní s ověřením v ARES / VIES, PDF přes
Gotenberg jen při změně obsahu (cache `build-state.json`). První sada:
`it-servis` (plátce DPH, leden–říjen 2026). Fáze 2a (#131 D17, D18, D22,
`tasks/demo-dataset-phase2a-bank.md`) přidala sekci `bank/`: generátor
(`invoicegen/bank.py`) promítne chování plateb z příběhu do měsíčních
výpisů dvou účtů (CZK, EUR), mzdy a odvody bez partnera vyrovná interními
doklady (MD závazek / DAL 261300 — zrcadlové řádky skupiny Nespárované
platby je v saldokontu vynulují, `docs/accbal.md` § 4.4) a počáteční
zůstatek nese otevírací doklad (`fiscalPeriodType: opening`); analytiku
221 pro eurový účet zakládá `setup/accounts.jsonc`. Ekonomika příběhu
(#131 D25–D28, `tasks/demo-dataset-economy.md`): cena katalogové položky
na faktuře v cizí měně se přepočítává kurzem měsíce, přebytek eurového
účtu odchází převody `transfer.out` / `transfer.in` (261100 nulový),
dodavatel z EU fakturuje v EUR se samovyměřením (cz-217 / cz-207, PDF
anglicky, úhrada z eurového účtu) a `build.py` vypisuje souhrn ekonomiky,
jehož cíle (zisk 8–15 %, zahraničí 10–20 %, …) hlídá unit test generátoru.
Fáze 2b (`tasks/demo-dataset-phase2b-work-orders.md`) přidala sekci
`workOrders/` (paušály jako periodické zakázky, dva projekty), fáze 2c
(`tasks/demo-dataset-phase2c-assets.md`) sekci `assets/` — server
pořízený fakturou velkoobchodu s řádkem `purchase.asset`, zařazený, bez
zaúčtování (D38); `invoicegen/assets.py` portuje měsíční plán účetních
odpisů jen pro souhrn ekonomiky. Nastavení `app.name` (název firmy
v záhlaví aplikace, které jinak ukazuje název zdroje dat — i ve videích
nad sadou) doplňuje generátor z `own.name` příběhu (#103 D135);
v `setup/settings.jsonc` příběhu se nepíše a loader ho tam hlásí jako
chybu. Sada bez `app.name` ho při přeseedování nesmaže — viz § 1.
