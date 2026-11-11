# Modul: docs.core

Polymorfní jádro dokladového systému — společné tabulky, číselné řady,
stavový model, konfigurační cfgItem soubory pro všechny typy dokladů.

## Účel

Drží **5 univerzálních tabulek** sdílených všemi typy dokladů (faktura
vydaná, faktura přijatá, …):

- `docs_core_heads` — hlavička dokladu (~40 sloupců, podle typu se používají
  různé)
- `docs_core_rows` — řádky dokladu
- `docs_core_vat_recap` — rekapitulace DPH (sestavovaná v `beforeSave` hlavičky)
- `docs_core_number_series` — číselné řady dokladů
- `docs_core_number_counters` — atomické countery pro generování čísla
  dokladu

Polymorfismus: hlavička má `doc_type` (enumString), který přes polymorfní
Document dispatch (registrace v `module.jsonc` přes `typeColumn = doc_type`)
resolvuje konkrétní Document subclass.

## Stav

MVP je hotové. Modul obsahuje:

- **5 tabulek** (heads, rows, vat_recap, number_series, number_counters)
  + cfgItem soubory (výčet v module.jsonc)
- **`DocDocument` (abstract)** — orchestrační `beforeSave` pipeline
  (denormalizace doc_type, defaulty datumů, home currency, fiscal periods,
  výpočty na řádcích, DPH rekapitulace vč. reverse charge párů, sumarizace,
  zaokrouhlení, exchange rate, přidělení/uvolnění čísla, snapshoty,
  variabilní symbol)
- **`DocsHeadsDocument`** — thin concrete subclass, polymorfní dispatch
  cesta v `module.jsonc` přes `typeColumn = doc_type`
- **`DocRowsDocument`** — `afterSave` / `afterDelete` hook, který po
  změně řádku spustí recompute hlavičky
- **`DocsHeadsForm`** + **`DocRowsForm`** — UI s tabovaným rozhraním
  (Hlavička / Řádky / Rekapitulace / Fakturační údaje / Poznámky / Přílohy)
- **`DocsHeadsViewer`** — generický viewer „Doklady“ (všechny typy);
  per-typ viewery (`Faktury vydané` / `Faktury přijaté`) jsou v navazujících
  modulech
- **Číselné řady** — kompletní CRUD + atomické přidělení čísla
  (`assignDocumentNumber`) při Koncept → Potvrzeno, uvolnění
  (`releaseDocumentNumber`) při návratu Potvrzeno → Koncept jen pokud je
  poslední v sekvenci
- **Snapshoty** — `supplier_snapshot` a `customer_snapshot` se ukládají jako
  JSON string při Koncept → Potvrzeno (a refresh-ují pokud se změnil partner
  v editovatelných potvrzených stavech)

Konkrétní typy dokladů žijí v navazujících modulech:

- **`docs.invoicesOut`** — faktura vydaná (`IssuedInvoiceDocument` +
  `IssuedInvoicesViewer`, validace `bank_account` při Potvrzení)
- **`docs.invoicesIn`** — faktura přijatá (`ReceivedInvoiceDocument` +
  `ReceivedInvoicesViewer`, validace bankovního spojení partnera)

## Klíčové patterny

### Recompute hlavičky při změně řádku

Řádky dokladů se ukládají přes vlastní sub-form endpoint, který nepošle
hlavičku zpět na server. Hlavička by tak po přidání/změně/smazání řádku
měla zastaralé totals a rekapitulaci DPH. Řešení je `DocRowsDocument`:

```php
class DocRowsDocument extends Document
{
    public function afterSave(array $data): void  { $this->recomputeHeader($data); }
    public function afterDelete(array $data): void { $this->recomputeHeader($data); }

    private function recomputeHeader(array $rowData): void
    {
        // Načti hlavičku, spusť DocsHeadsDocument::beforeSave
        // (ten připočítá totals + recap z aktuálního stavu řádků v DB),
        // pak UPDATE heads výpočetních sloupců a DELETE+INSERT vat_recap.
    }
}
```

Viz `DocRowsDocument.php` pro detail. Tento pattern — `afterSave` na child
entitě spouští recompute parenta — je užitečný všude, kde má hlavička
odvozené hodnoty od child setů. Detaily k save semantice gatewayů viz
`docs/document-system.md` sekce 6.

### Opravný daňový doklad (#92, `tasks/corrective-doc-core.md`)

Faktura vydaná s příznakem `corrective` na hlavičce, ne nový typ dokladu;
které typy ho mají, říká `docTypes[].corrective` (`DocTypes::isCorrective`).
Skupina sloupců `correction` (`tables/docs_core_heads.md`): odkaz na původní
doklad (nepovinný — souhrnný opravný doklad), jeho číslo, datum plnění
a důvod opravy. Pravidla drží `DocDocument`:

- `applyCorrectiveColumns()` (beforeSave): typ bez příznaku a vypnutý
  příznak sloupce opravy nulují; s odkazem se číslo a datum plnění přepíší
  z původního dokladu (`vat_duzp`, bez něj `issue_date`);
- `validateCorrective()`: odkaz vede na existující doklad typu s příznakem
  a ne na doklad sám (vždy), při potvrzení původní doklad V pořádku se
  stejným partnerem; mimo import mód je důvod opravy povinný a prázdný odkaz
  i číslo dají varování;
- sazba DPH k datu plnění původního dokladu
  (`DocHeadVatContext::vatRateDate`, `docs/vat-calculation.md` § 5.2); kurz
  se z lístku nepřepisuje (`docs/exchange-rates.md` § 5.1).

Formulář: sekce Oprava v tabu Nastavení faktury vydané
(`IssuedInvoiceFormBase`), lookup původního dokladu `DocsHeadsLookup`
(jen V pořádku, filtry `doc_type` a `partner`). Detail (`DocsHeadsViewer`):
štítek Opravný doklad, skupina Oprava s odkazem na původní doklad, na
původní faktuře sekce Opravné doklady; texty z `docs.core.viewerLabels`.
Saldokonto, účtování, přiznání k DPH ani KH se podle příznaku nemění
(dobropis zůstává zápornou fakturou). Tisk a ISDOC přijdou s taskem C.

**Výměnný formát a akce Vystavit opravný doklad** (#92 task B,
`tasks/corrective-doc-exchange.md`): canonical nese blok `correction`
(`originalDocNumber`, `originalTaxPointDate`, `reason`) a odkaz pinem
`_resolve.correction` — applier ho bere jen u typu s příznakem, původní
doklad dohledá pinem nebo podle čísla mezi doklady téhož typu V pořádku,
sloupce opravy zapíše už v `transform()`, sazby DPH hledá k datu původního
plnění a kurz přebírá z původního dokladu (`docs/exchange-format.md` § 5
„Opravný daňový doklad“). `src/Corrective/`: `CorrectiveDocBuilder` (čistá
funkce hlavička + řádky původního dokladu → canonical: partner, řada, účet,
měna a kurz, režim a místo DPH převzaté; řádky se záporným množstvím, sleva
částkou s opačným znaménkem; bez `totals` / `vatRecap`, zdroj
`correctiveDoc`), `CorrectiveDocService` (jen typ s příznakem ve stavu
V pořádku, uložení přes `DocumentApplier`, odmítnutí
`CorrectiveDocException` 404 / 422) a `CorrectiveDocController`
(`POST /_docs/corrective {docId}` → `{docId}`; read-only režim 403
z `ReadOnlyPolicy`). Akce detailu `createCorrectiveDoc` v `DocsHeadsViewer`
(popisek `action.createCorrectiveDoc` z `viewerLabels`), obsluha
ve `Viewer.svelte` otevře koncept ve formuláři.

### Server-driven UI s formuláři

`DocsHeadsForm` výpočty rekapitulace nepošle do form definition jako
samostatné elementy — místo toho v `buildRecapTab` zavolá
`renderRecapHtml(...)` a výsledný HTML výstup předá do `addHtml(...)`.
Frontend ho jen zobrazí. Stejný pattern používá i tab Fakturační údaje.

Výhody: rekapitulace má server-side editovaný render (rozlišení reverse
charge párů, dvouřádkové zobrazení pro cizí měnu, atd.) bez nutnosti
frontend custom komponent. Cena: nelze stylování editovat z frontu;
změna prezentační vrstvy vyžaduje deploy backendu.

## Konfigurace

Modul registruje cfgItem soubory — viz `config/` (výčet v `module.jsonc`,
zde ty podstatné):

- `docs.core.docTypes` — typy dokladů a jejich příznaky (`tax_document`,
  `total_rounding_auto`, `corrective`; čte jen `src/DocTypes.php`)
- `docs.core.docStates` — stavový automat (Koncept → Potvrzeno → V pořádku
  → V opravě → Storno → Smazáno)
- `docs.core.vatModes` — režim DPH na hlavičce
- `docs.core.vatCalcSources` — odkud počítat DPH
- `docs.core.vatPlaces` — místo plnění
- `docs.core.priceCalcModes` — způsob výpočtu ceny na řádku
- `docs.core.rowKinds` — typ řádku (text / běžný)
- `docs.core.roundingModes` — zaokrouhlení celkové částky (názvy pro UI;
  sémantiku kódů drží `src/RoundingModes.php`)
- `docs.core.vatRoundingModes` — zaokrouhlení DPH, podmnožina {0, 1}
- `docs.core.paymentMethods` — způsoby platby (`cashRounding` = hotovostní
  úhrada pro automatické zaokrouhlení částky, `src/TotalRoundingAuto.php`)
- `docs.core.resetScopes` — kdy se restartuje counter řady

## Závislosti

```
docs.core
├── core.system
├── core.units
├── core.attachments
├── base.persons (vyžaduje is_own + court_registration)
├── world.base
├── world.vat (vyžaduje vat-cz.jsonc)
├── economy.codebooks (fiscal_years, vat_registrations, …)
└── economy.items (katalog položek)
```

## Číselné řady

Číselná řada je samostatný číselník (`docs_core_number_series`) editovatelný
přes UI v sekci Settings → Účtování. Každý typ dokladu má aspoň jednu řadu;
`NumberSeriesProvisioner` volaný z `ds-upgrade` automaticky založí default
řadu pro každý typ z cfgItem `docs.core.docTypes`.

Vzorec čísla dokladu používá `%X` placeholdery — viz cfgItem `docs.core.docTypes`
pro default vzorce a `docs/docs-mvp.md` sekce 5.4 pro popis placeholderů.

## Stavový model

Doklady mají vlastní rozšířenou sadu stavů (cfgItem `docs.core.docStates`),
**nikoli** standardní `core.system.docStatesArchive`. Klíčové rozdíly:

- **+ 20 Potvrzeno** — doklad má přidělené číslo, ale je stále editovatelný
- **+ 30 Storno** — náhrada za smazání po Potvrzení; zachovává číslo dokladu
  v sekvenci, sdílí `mainState=4` s V pořádku
- **− 70 V archívu** — u dokladů nadbytečné

Detaily v `docs/docs-mvp.md` sekce 3.

## Pro vývojáře

`OwnCompanyResolver` najde záznam vlastní firmy v `base_persons_persons`
(`is_own = 1`). Bez vlastní firmy nelze vystavovat doklady — kontroluje
se při Potvrzení.

`DocDocument` (abstract) je orchestrační třída — většina logiky žije v
ní. Per-typ subclasses (`DocsHeadsDocument`, `IssuedInvoiceDocument`,
`ReceivedInvoiceDocument`) ji rozšiřují o typově specifické validace.

Pokud přidáváš nový typ dokladu (např. zálohová faktura `prfmin`),
vytvoř nový modul (`docs.proforma`) s vlastním Document subclassem +
viewerem a zaregistruj typový dispatch v `documentClasses` přes
`typeColumn = doc_type`. Nepotřebuješ žádné nové tabulky — `docs_core_*`
je sdílí všechny typy.

## Alerts

Modul registruje jeden alert check (viz `docs/alerts.md`):

- **`docs.core.stale_in_repair`** (per-row, interval 1h, severity warning) —
  detekuje doklady visící ve stavu 80 V opravě déle než 24 h. Vstupem je sloupec
  `docs_core_heads.doc_state_changed_at`, udržovaný `DocDocument::trackStateChange`
  (první krok `beforeSave`). Existující řádky před přidáním sloupce backfillne
  jednorázový idempotentní `UPDATE` v `DsUpgradeCommand`.
