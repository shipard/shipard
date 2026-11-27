# Modul: Periodická fakturace (economy.periodicInvoicing)

Engine, který za záznamy **zdrojů fakturace** vystavuje koncepty dokladů za
splatná období (#175 D1–D9, [docs/work-orders.md](../../../docs/work-orders.md)
§5.5). Modul jádra v `install.base`. Sám žádný zdroj nemá — první zdroj jsou
Zakázky (`economy.workOrders`), druhý smlouvy doplňku (#174); další přidá
modul, který zdroj zaregistruje. Modul žádný zdroj nejmenuje: zdroj se
napojuje jen registrací, deklarací akcí záznamu a zapojením společné
záložky, formulář a dokument řádku předpisu dědí ze společného základu.

## Závislosti

`core.system`, `core.alerts`, `core.units`, `base.persons`,
`economy.codebooks`, `docs.core`, `core.exchange`. Na modulu závisí každý
modul, který registruje zdroj fakturace (`economy.workOrders`).

## Tabulky

Žádné — evidence období je per zdroj a tabulka období patří modulu zdroje
(zakázky `economy_work_orders_periods`).

## Co modul dělá

- **Běh** (`InvoicingRunService`): projde zdroje, pro každý záznam mezi
  kandidáty založí chybějící splatná období a vystaví je jako koncepty
  dokladů přes výměnný formát (`InvoiceBuilder` → `DocumentApplier`). Zámek
  řádku období, apply a zápis dokladu do období jsou jedna transakce
  (`NestedTransaction`). Víc než `MAX_CATCHUP` (3) dlužných období jednoho
  záznamu běh nevystaví bez `force` (pojistka dohánění).
- **Evidence období per zdroj** (`PeriodRepository`): každý zdroj má vlastní
  tabulku období stejného tvaru; repozitář dostává jméno tabulky a sloupec
  záznamu. Stavy `planned` → `waiting` → `issued`; „zastaveno“ je odvozené
  (vystavené období, jehož doklad je v koši nebo chybí). **Id období je
  jednoznačné jen v tabulce zdroje** — `regenerate()` / `restore()` i akce
  proto vždy dostávají zdroj.
- **Kalendář a popisek období** (`PeriodCalendar`, `PeriodLabel`), efektivní
  předpis (`InvoicingSettingsResolver`: záznam → druh → výchozí hodnota),
  nabídky polí předpisu pro formuláře (`InvoicingFormOptions`).
- **Společný základ řádku předpisu** (`Rows\*`, #174 D31) — formulář,
  dokument, zámek a sub-tabulka řádků pro všechny zdroje i pro šablonu
  řádků na druhu (viz [Společný základ řádku](#společný-základ-řádku)).
- **Přispěvatelé obsahu** (`Contributor\InvoiceContributor`) — společní pro
  všechny zdroje; kontext nese id zdroje a záznam.
- **Záložka Fakturace** (`Viewer\InvoicingDetailTab`) a **akce záznamu**
  (`Action\*`) společné všem zdrojům.
- **Upozornění** `economy.periodicinvoicing.*` — jednou pro všechny zdroje.

Doklady všech zdrojů nesou druh zdroje dokladu `workOrder` („Periodická
fakturace“ v `docs.core.sourceKinds`, D7).

## Rozhraní zdroje

`Source\PeriodicInvoicingSource` (typicky přes
`Source\AbstractPeriodicInvoicingSource` se settery `db` / `config` /
`dsConfig`; třída vzniká bez argumentů konstruktoru):

| Metoda | Co vrací |
|--------|----------|
| `id()` | id z registrace (CLI `--source`, klíč nálezu upozornění) |
| `table()`, `tableId()` | tabulka záznamů a její stabilní `tableId` (předmět nálezu upozornění) |
| `periodsTable()`, `periodsRecordColumn()` | tabulka evidence období a sloupec záznamu v ní |
| `viewerId()`, `navSection()` | viewer, který záznam otevírá, a sekce navigace pro badge upozornění |
| `candidates(?int $recordId)` | záznamy k fakturaci podle id; s `$recordId` jen ten jeden (mimo kandidáty prázdné) |
| `record(int $id)`, `recordIdByNumber(string $number)` | normalizovaný záznam, id záznamu podle čísla (CLI `--record`) |
| `kind(array $record)` | řádek druhu se sloupci `inv_*`, nebo `null` |
| `rows(array $record)` | řádky předpisu, `order_pos` vzestupně |
| `dimensions(array $record)` | dimenze dokladu `id dimenze → id záznamu` |
| `recordLabel(array $record)` | číslo a název záznamu (výpis běhu, upozornění) |
| `recordNoun()`, `openActionLabel()` | pojmenování záznamu („Zakázka“) a popisek akce upozornění („Otevřít zakázku“) v jazyce konfigurace |

### Normalizovaný záznam

Pole s klíči, které engine čte: `id`, `number`, `title`, `customer`,
`currency`, `payment_reference`, `date_end`, `inv_periodicity`, `inv_from`,
`inv_doc_text` a přepisy předpisu `inv_doc_type`, `inv_number_series`,
`inv_due_days`, `inv_timing`, `inv_vat_mode`, `inv_payment_method`,
`inv_bank_account`. Řádek druhu nese tytéž sloupce `inv_*` (prázdná hodnota
záznamu = hodnota druhu). Zakázky mají záznam 1 : 1 jako řádek své tabulky.

### Řádek předpisu

`id`, `order_pos`, `item`, `description`, `quantity`, `unit` (id jednotky),
`unit_price`, `vat_code`, `operation`, `valid_from`, `valid_to`,
`contributor`. Do dokladu jdou řádky platné ke dni fakturace
(`valid_from ≤ den ≤ valid_to`, prázdné = neomezeno); řádek s `contributor`
vzniká s množstvím 0 a doplní ho přispěvatel.

### Sloupce předpisu

`InvoicingSettings::COLUMNS` jsou sloupce společné druhu a záznamu (sedm
`inv_*` — prázdná hodnota záznamu = hodnota druhu),
`InvoicingSettings::RECORD_COLUMNS` sloupce jen na záznamu
(`inv_periodicity`, `inv_from`, `inv_doc_text`). `InvoicingSettings::source()`
říká per sloupec, odkud efektivní hodnota je: `SOURCE_RECORD` / `SOURCE_KIND`
/ `SOURCE_DEFAULT` — hodnoty neopouštějí proces (neukládají se, klient je
nedostává). Do #174 D35 se konstanty jmenovaly `WORK_ORDER_COLUMNS`
a `SOURCE_WORK_ORDER`.

### Společný základ řádku

Namespace `Rows\` (#174 D31) — zdroj z něj dědí a dodá jen rodiče:

| Třída | Co dává | Co dodá potomek |
|-------|---------|-----------------|
| `AbstractPrescriptionRowsForm` | pole řádku, nabídka kódů DPH a pohybů podle efektivního typu dokladu, předvyplnění z položky, pole přispěvatele | `parentColumn()`, `loadParentHeadAndKind()`, `rowTitle()`, `rowTitleNew()` |
| `AbstractPrescriptionRowDocument` | tvarová validace, pořadí nového řádku | `rowsTable()`, `parentColumn()`, `parentRequiredMessage()`; volitelně `validateAgainstParent()` |
| `AbstractPrescriptionRowLockProvider` | zámek řádků podle stavů rodiče s `readOnly` | tabulka a sloupec rodiče, cfgItem jeho stavů, předmět a texty důvodu |
| `PrescriptionRowsSubtable` | `render()` pro `renderSubtable()` formuláře rodiče, `unitShortcuts()` | popisky sloupců (`subtableLabel()`) |

**Šablona řádků na druhu** se vejde do téhož základu: `hasValidity()` vrací
`false` (formulář nestaví pole platnosti, dokument platnost nehlídá,
sub-tabulka je bez sloupců platnosti) a `loadParentHeadAndKind()` vrací
`head` null — typ dokladu jde jen z druhu. Kopii šablony do záznamu dělá
dokument záznamu, engine ji neřeší.

Nápovědy formuláře jdou z katalogu `economy.periodicInvoicing.formTexts`
a záznam zdroje nejmenují; titulky dodává potomek.

### Tabulka období zdroje

Stejný tvar jako `economy_work_orders_periods`: sloupec záznamu, `period_from`,
`period_to`, `state` (cfgItem `economy.periodicInvoicing.periodStates`), `doc`,
`content_hash`, `result`, `message`, `waiting_since`, `created_at`,
`updated_at` a unikátní index záznam × `period_from`.

## Registrace

```jsonc
// module.jsonc modulu, který zdroj dodává
"dependencies": ["economy.periodicInvoicing"],
"periodicInvoicingSources": [
    {"id": "workOrders", "class": "Shipard\\Module\\Economy\\WorkOrders\\WorkOrderInvoicingSource"}
],
"periodicInvoicingContributors": [
    {"id": "energy.consumption", "class": "…\\ConsumptionContributor", "name": "Energy consumption", "name:cs": "Spotřeba energií"}
]
```

`ConfigCompiler` skládá registrace aktivních modulů do cfgItemů
`economy.periodicInvoicing.sources` a `economy.periodicInvoicing.contributors`
(duplicitní id mezi moduly i cfgItem téhož id z `config[]` je chyba
kompilace). Instance staví `Source\InvoicingSources::fromConfig()`
(`get(id)`, `all()`, `forTable(tabulka)`) a
`Contributor\InvoiceContributorRegistry::fromConfig()`.

Číselníky modulu: `economy.periodicInvoicing.periodicities`,
`.invoiceTimings`, `.periodStates`, `.periodTexts` (tvar `{období}` v jazycích
dokumentů) a katalogy textů `.viewerLabels` (záložka, akce) a `.formTexts`
(formulář řádku předpisu).

## Záložka a akce záznamu

Záložku zapojí viewer zdroje:

```php
(new InvoicingDetailTab($source, PeriodRepository::forSource($dibi, $source), $config, $language))->build($record);
```

Akce jsou akce záznamu (`recordActions`, `docs/modules.md` kap. 5) se
společnými třídami; zdroj je deklaruje nad svou tabulkou a akce si zdroj
najde podle tabulky:

| Třída | Dostupná | Dialog | Výsledek |
|-------|----------|--------|----------|
| `Action\IssueDueAction` | záznam je mezi kandidáty zdroje | — | „Vystaveno období: N, selhalo: M“; varování, když M > 0 |
| `Action\RegenerateAction` | záznam má období s dokladem v Konceptu | výběr období + upozornění, že ruční úpravy konceptu se ztratí | koncept nahrazen v místě (`replaceConcept`) |
| `Action\RestoreAction` | záznam má zastavené období | výběr zastaveného období | nový koncept; jiný výsledek běhu = varování se zprávou |

Stav, který běh odmítne (`\DomainException`), je chyba pole období (422).
Běh akce skládá z kontextu (`RecordActionContext::$documents`, `$tables`,
`$dispatcher`).

## CLI a cron

```bash
shpd-ds periodic-invoicing-run [--date=YYYY-MM-DD] [--source=<id>] [--record=<číslo>] [--dry-run]
```

Úloha cronu `daily` (stav zdroje dat `active`). `--record` vyžaduje
`--source` a běží bez pojistky dohánění. `work-orders-invoice-run` je alias
`--source=workOrders` na jedno vydání. Viz
[docs/cli.md](../../../docs/cli.md).

## Upozornění

| Id | Co hlásí |
|----|----------|
| `economy.periodicinvoicing.invoices_to_review` | jeden souhrnný nález: koncepty z periodické fakturace ke kontrole |
| `economy.periodicinvoicing.period_failed` | per záznam: období, která se nepodařilo vystavit |
| `economy.periodicinvoicing.catchup_blocked` | per záznam: dlužná období zablokovaná pojistkou dohánění |
| `economy.periodicinvoicing.period_waiting` | per záznam: období čeká na podklady přispěvatele déle než lhůta, nebo má podklady a ručně upravený koncept |

Nález per záznam má klíč `zdroj:id`, předmět = záznam zdroje, akci, která
otevře viewer zdroje, a v `context.navSection` sekci vieweru zdroje — check
sám `navSection` nemá, badge sekce bere feed z nálezu
([docs/alerts.md](../../../docs/alerts.md)). **Známé omezení se dvěma zdroji:**
nad tři aktivní nálezy jednoho checku je ve feedu jedna skupinová karta
a ta sekci nese, jen když se v ní shodnou všechny nálezy checku. Mají-li
nálezy zároveň dva zdroje s různou sekcí, odznak se nerozsvítí u žádné
z nich (ověřeno 2026-11-27 nad zakázkami a smlouvami; samotný jeden zdroj
odznak dává). Náprava je v `core.alerts` (skupina per check a sekce).
Lhůtu čekání na podklady
(výchozí 10 dní) nastavuje stránka *Nastavení → Účetnictví → Periodická
fakturace* (`economy.periodicInvoicing.contributorWaitDays`).

## Jak přidat zdroj

1. Tabulky zdroje: záznam (a druh) se sloupci předpisu `inv_*`, řádky
   předpisu a tabulka období tvaru výše; enumy odkazují na cfgItemy tohoto
   modulu. Formulář, dokument a zámek řádku jako potomci `Rows\*`.
2. Třída zdroje (`AbstractPeriodicInvoicingSource`) a registrace
   `periodicInvoicingSources`; modul závisí na `economy.periodicInvoicing`.
3. `recordActions` nad tabulkou záznamů se třídami `Action\*`.
4. Zapojení `InvoicingDetailTab` do vieweru zdroje.
5. Test zdroje: shoda `tableId()`, `navSection()` a sloupce období
   s definicemi modulu (vzor `WorkOrderInvoicingSourceTest`); zdroj mimo
   jádro přidá i běh se dvěma skutečnými zdroji (vzor doplňku Budovy —
   test patří k doplňku, engine ho nejmenuje).

Běh, CLI, cron, upozornění a nastavení pak zdroj dostane bez dalšího kódu.
