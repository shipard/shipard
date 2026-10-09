# Zakázky — výměnný formát a import ze starého Shipardu

**Stav:** hotovo — 2026-10-09; #110 D9, D25, D26; I1–I4 potvrzené 2026-10-09

> PRD pro jednu Claude Code session (3 commity). Design:
> `docs/work-orders.md` §4 (D9, D17, D25, D26), §6; issue #110. Vzor:
> formát karty majetku `shpd.assets.asset.v1` (`docs/exchange-format.md`
> § Majetek, `modules/economy/assets/src/Import/`). Runner ve starém
> Shipardu je oblast importu — tenhle task připraví jen stranu nového
> Shipardu.

## Kontext

Zakázky a periodická fakturace jsou hotové (fáze 1, 2), ale do nového
Shipardu se zatím nedají dostat ze starého. Testuje se opakovaným plným
reimportem (D26), takže stačí jeden formát zakázky, kterým runner zakázku
založí — se všemi poli fáze 1 a 2, s řádky předpisu a s *fakturovat od*.
Zakázky jdou **před** doklady; doklady pak nesou zakázku v
`dimensions.workOrder` (číslo zakázky), což už umí.

## Cíl

1. Formát `shpd.workOrders.workOrder.v1` (schéma, applier, verifier)
   a endpointy `validate` / `apply`.
2. Zakázka vznikne v jednom požadavku včetně řádků a v cílovém stavu;
   importované číslo se převezme a čítač řady se srovná.
3. Dokumentace kontraktu pro runner (`docs/exchange-format.md`,
   `docs/work-orders.md` §6).

## Před implementací přečti

- `docs/work-orders.md` §4 (D9, D17, D25, D26), §5.2–5.5, §6
- `docs/exchange-format.md` § Majetek — `shpd.assets.asset.v1`
  (tvar odpovědi, chybové kódy, oprávnění) a § Apply uvnitř cizí
  transakce
- `modules/economy/assets/src/Import/AssetImportApplier.php`,
  `AssetImportVerifier.php`, `AssetImportResult.php`; routing
  `/_exchange/assets/` v `src/Api/Router.php` a `ExchangeController`
- `modules/economy/workOrders/src/` — `WorkOrderDocument` (stavy,
  číslování přes `SequenceCounter`, validace podle typu),
  `WorkOrderRowLockProvider`, `Invoicing/` (efektivní hodnoty předpisu)
- `src/Core/Numbering/SequenceCounter.php` (`syncImported`) a import čísla
  dokladu v `DocDocument` (`_importNumber`) — vzor převzetí čísla

## Scope

**Uvnitř:** vše v Cíli.

**Mimo:** runner ve starém Shipardu (párování smluv a zakázek `689089`,
pravidlo *fakturovat od* — `docs/work-orders.md` §6, oblast importu);
druhy a číselné řady zakázek ve formátu (I3); zakázky v datových sadách
(`dataset-dump/seed`); historie vyfakturovaných období (evidence období
po importu začíná prázdná — *fakturovat od* zajistí, že se nic nevystaví
zpětně); doplnění zakázky na už importované doklady (D26).

## 1. Formát `shpd.workOrders.workOrder.v1`

```jsonc
{
  "format": "shpd.workOrders.workOrder.v1",
  "workOrder": {
    "number": "S260001",            // klíč párování; chybí → číslo přidělí řada při potvrzení
    "sequenceNumber": 1,            // nepovinné; s number srovná čítač řady
    "numberSeries": 3,              // řada zakázek (id) — určuje druh a typ
    "title": "…",
    "state": "confirmed",           // draft | confirmed | finished | cancelled
    "dateStart": "2026-01-01", "dateEnd": null,
    "costCenter": 2, "internalNote": null,
    "customer": 41, "currency": "czk", "paymentReference": null,   // externí typy
    "parent": null,                 // číslo nadřazené zakázky (jednorázové typy)
    "invoicing": {                  // jen periodický typ
      "periodicity": "month", "invoiceFrom": "2026-11-01", "docText": null,
      // přepisy z druhu — chybějící / null = z druhu
      "docType": null, "numberSeries": null, "dueDays": null, "timing": null,
      "vatMode": null, "paymentMethod": null, "bankAccount": null
    }
  },
  "rows": [
    { "item": 15, "description": "…", "quantity": 1, "unit": "pcs",
      "unitPrice": 4500, "vatCode": "cz-110", "operation": null,
      "validFrom": null, "validTo": null }
  ]
}
```

- **Reference id** (I1): `numberSeries`, `costCenter`, `customer`,
  `item`, `invoicing.numberSeries`, `invoicing.bankAccount` jsou id
  záznamů nového Shipardu — runner je zná z mapy id (vzor
  `shpd.assets.asset.v1`: `type`, `owner`). Kódy tam, kde jsou přirozené:
  měna, `vatCode`, `operation`, jednotka (`system_code`), periodicita,
  `timing`, `vatMode`, `paymentMethod`.
- **Validace** stejná jako ve formuláři: tvar (JSON Schema), existence
  referencí, pravidla typu z `WorkOrderTypes` (zákazník jen u externích,
  nadřazená jen u jednorázových a ne periodická, `invoicing` jen
  u periodického typu, řádky jen u periodického typu), řada platná,
  `invoicing.numberSeries` typu odpovídajícího `docType`. Cesta chyby do
  payloadu (`rows.2.vatCode`).

## 2. Apply

- **Založení** (I2): v jedné transakci hlavička v Konceptu → řádky →
  přechod do cílového stavu přes `WorkOrderDocument` (stejné validace
  a zámky jako formulář; řádky se zapíší, dokud je zakázka v Konceptu —
  zámek řádků potvrzené zakázky platí dál). `finished` / `cancelled`
  projdou přes V pořádku a nastaví `date_end`, jak to dělá přechod.
- **Číslo** (I4): `number` vyplněné → převezme se beze změny (jako
  `_importNumber` dokladu), `sequenceNumber` + fiskální rok `dateStart`
  srovnají čítač řady (`SequenceCounter::syncImported`); bez
  `sequenceNumber` jen varování `counter_not_synced`. `number` chybí
  a stav není `draft` → číslo přidělí řada jako při potvrzení ve
  formuláři. Duplicitní číslo v DS → `validation_failed`.
- **Existující číslo** (I2): zakázka se stejným číslem →
  `status: "skipped"` s varováním `work_order_exists` (reimport jde do
  resetovaného DS; přepis existující zakázky se nedělá).
- **Nadřazená** se hledá podle čísla; neexistuje → chyba
  `parent_not_found` (runner posílá nadřazené dřív).
- Odpověď `{ "status": "created" | "skipped", "workOrderId": 31,
  "number": "S260001", "warnings": [{code, message, path?}] }` — 201 při
  založení. Runner si `number` uloží pro `dimensions.workOrder` dokladů.
- **Endpointy** (oprávnění jako u majetku — admin nebo API klíč, vždy
  POST): `POST /api/v1/_exchange/workOrders/workOrder/validate` (celý
  průběh s rollbackem) a `…/apply`. Chyby ve společném tvaru
  (`schema_invalid` 400, `validation_failed` 422, `details.issues[]`).

## 3. Dokumentace

- `docs/exchange-format.md` — sekce „Zakázky — `shpd.workOrders.workOrder.v1`“
  vedle majetku (příklad, pole, odpověď, kódy, pořadí importu, *fakturovat
  od* a proč).
- `docs/work-orders.md` §6 — odkaz na formát, stav, §7 řádek 6.
- `CLAUDE.md` — řádek `docs/work-orders.md` (import zakázek).

## Testy

- Verifier: schéma, reference, pravidla typů, řada dokladů vs. `docType`,
  cesty chyb.
- Applier: periodická zakázka s řádky v cílovém stavu V pořádku
  (řádky zapsané, číslo převzaté, čítač srovnaný — další zakázka ve
  formuláři pokračuje za importovaným číslem); `finished` / `cancelled`
  s `date_end`; bez čísla → přidělí řada; existující číslo → `skipped`;
  nadřazená podle čísla; `validate` nic nezapíše.
- Endpointy: oprávnění, routing, tvar chyb.
- `vendor/bin/phpunit --filter 'WorkOrder(Import|Applier|Verifier)|ExchangeController|Router'`,
  pak celá sada.

## Task breakdown

1. **Formát a verifier** — schéma `.jsonc` + `.json`, verifier, testy.
2. **Applier a endpointy** — apply / validate, číslo a čítač, routing,
   testy.
3. **Dokumentace a stav** — `docs/exchange-format.md`,
   `docs/work-orders.md` §6/§7, `CLAUDE.md`, Stav tasku +
   `python3 scripts/tasks-index.py`.

## Hotovo když

Na ukázkovém zdroji (`4l3j-z0bz-kz39-echj`, režim volný):

- `validate` periodické zakázky s řádky vrátí výsledek a nic nezapíše.
- `apply` založí zakázku V pořádku s řádky a převzatým číslem; detail ji
  ukáže, další nová zakázka v téže řadě dostane číslo za importovaným.
- Faktura přijatá / vydaná importovaná s `dimensions.workOrder` = číslo
  zakázky ji nese a Deník zakázky ji ukáže.
- `work-orders-invoice-run --dry-run` po importu nabídne až období od
  *fakturovat od*, nic dřív.
- Opakované `apply` téhož čísla → `skipped`.
- Celá sada PHPUnit zelená.

## Hotovo (2026-10-09)

Tři commity podle breakdownu: schéma + `WorkOrderImportVerifier`
(`WorkOrderImportCheck`), `WorkOrderImportApplier` + `WorkOrderImportResult`
+ endpointy, dokumentace. Kontrakt: `docs/exchange-format.md` § Zakázky,
`docs/work-orders.md` §6.

Odchylky a upřesnění proti zadání:

- **Verifier je kontrola payloadu před zápisem** (reference, kódy, pravidla
  typu, řada dokladů vs. typ dokladu, nadřazená podle čísla, číslo a čítač),
  ne kontrola dat po importu jako `AssetImportVerifier`. Vrací
  `WorkOrderImportCheck` s dohledanými id (jednotky, nadřazená, fiskální
  rok, existující zakázka), aby applier dotazy neopakoval. Nálezy mají
  `severity` error / warning (`counter_not_synced` je varování).
- **Čítač**: `WorkOrderDocument` dostal virtuální pole `_importSequence`
  (vzor `_importNumber` dokladu) — doplní `sequence_number`, `fiscal_year`
  a zavolá `syncImported`; applier ho posílá jen s prvním uložením.
  `sequenceNumber` bez `number` je chyba `number_required`.
- **Stavy**: `finished` / `cancelled` jdou dvěma uloženími (10 → 40 → 70 /
  30), ne přímým zápisem stavu — číslo i `date_end` vznikají stejnou cestou
  jako ve formuláři.
- **Kódy DPH** se bez registrace k DPH na zdroji neověřují (stejná
  degradace jako nabídka ve formuláři); verifier bere i skryté kódy.
- `rows` je ve schématu nepovinné; u neperiodického typu musí být prázdné.
- `DocumentApplier` dostal `vatModeFromCanonical` / `paymentMethodFromCanonical`
  (opak existujících `canonical*`), aby mapy kódů zůstaly na jednom místě.
- **Smoke na 4l3j** proběhl in-process skriptem (endpointy chtějí admina)
  a záznamy se po něm smazaly: validate bez zápisu, apply s převzatým
  číslem (čítač řady 1 → 2), opakovaný apply `skipped`, bez čísla S260003,
  `finished` přes V pořádku s dneškem v ukončení, podzakázka s nadřazenou
  podle čísla, chybové cesty, `dimensions.workOrder` v `preview` dokladu
  (validate dokladu rezoluci nedělá), `work-orders-invoice-run --dry-run`
  nabídl jen období od *fakturovat od*. Deník zakázky s importovanou
  fakturou se neověřoval (žádný doklad se na 4l3j nezakládal) — pokrývá ho
  mechanismus dimenzí z `tasks/dimensions-core.md`.

## Rozhodnutí k designu (potvrzená)

- ✓ **D9, D25, D26** (#110) — import rozpracovaných zakázek a měsíčních
  smluv `689089`, celý reimport, zakázky před doklady.
- ✓ **I1 — Reference přes id** záznamů nového Shipardu (runner je zná
  z mapy id), kódy jen tam, kde jsou přirozené — jako formát majetku.
  Datové sady by potřebovaly přirozené klíče; zakázky v nich zatím
  nejsou.
- ✓ **I2 — Jen založení:** hlavička + řádky + cílový stav v jednom
  požadavku; existující číslo = `skipped`, žádný přepis (reimport jde
  do resetovaného DS).
- ✓ **I3 — Druhy a číselné řady zakázek** formát nenese — runner je založí
  předem (generickým REST nebo ručně; je jich málo) a v zakázce posílá id
  řady.
- ✓ **I4 — Číslo:** převzaté beze změny + srovnání čítače podle
  `sequenceNumber`; bez čísla přidělí řada.
