# 09 — Rename v exchange payloadu: `variableSymbol` → `paymentReference`

## Kontext

V nov_shipard se přejmenovává sloupec `variable_symbol` na `payment_reference`
(obecný evropský koncept payment reference, jehož je VS česká instance) a s ním
i pole `payment.variableSymbol` → `payment.paymentReference` v exchange formátu
`shpd.docs.document.v1`. Formát zůstává na verzi v1, změna je in-place.

`specificSymbol` a `constantSymbol` zůstávají beze změny.

Hlavní PRD: nov_shipard `tasks/docs-payment-reference-rename.md`.

## Návaznost

- **Lockstep s nov_shipard** — nasadit až poté, co nov_shipard přijímá
  `paymentReference` (DocumentApplier + schéma). Payload se starým názvem pole
  by se po změně schématu tiše zahodil (`?? null`).
- Žádná datová migrace — testovací datasources v nov_shipard se resetují
  a import se spustí znovu od začátku.

## Před implementací přečti

- `modules/imports/newShipard/libs/runners/DocsRunner.php` (~ř. 433–435)

## Co implementovat

V `DocsRunner.php` v sestavení `payment` sekce:

```php
'paymentReference' => $this->emptyToNull($oldRow['symbol1'] ?? null),
'specificSymbol'   => $this->emptyToNull($oldRow['symbol2'] ?? null),
'constantSymbol'   => null,
```

Tedy jen rename klíče `variableSymbol` → `paymentReference`; zdrojové sloupce
starého Shipardu (`symbol1`, `symbol2`) i ostatní klíče beze změny.

Zkontrolovat, zda `variableSymbol` nefiguruje i jinde v importním modulu
(`grep -rn "variableSymbol" modules/imports/newShipard/`).

## Hotovo když

- `grep -rn "variableSymbol" modules/imports/newShipard/` nevrací nic.
- Testovací běh `DocsRunner` proti resetnutému nov_shipard datasource projde
  a importované doklady mají vyplněný `payment_reference`.

## Rozhodnutí ✓

- Rename pouze na výstupní straně (exchange klíč); interní `symbol1`/`symbol2`
  starého Shipardu se nemění.
- Exchange format zůstává v1.
