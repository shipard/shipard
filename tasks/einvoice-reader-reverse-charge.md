# Čtečka ISDOC: `vat.reverseCharge: false` jen u tuzemského plnění (#119)

**Stav:** naplánováno — oprava z ověření fáze 1 (2026-10-20)

> Task pro Claude Code (1 commit). Oprava kroku 3 z
> `tasks/einvoice-phase1.md`; ten task se nemění.

## Problém

`IsdocReader::fromXmlString()` určuje přenesení daňové povinnosti takto:

```php
$reverseCharge = $this->hasLocalReverseCharge($root) ? true : ($vatApplicable !== null ? false : null);
```

Každý ISDOC plátce DPH bez prvku tuzemského přenesení tak dostane
`vat.reverseCharge: false` — vracejí ho všechny stávající fixtury
v `tests/Fixtures/Exchange/isdoc/`.

ISDOC ale umí vyjádřit jen **tuzemské** přenesení (`LocalReverseCharge`
na řádku, `LocalReverseChargeFlag` v rekapitulaci). Přenesení u plnění
z jiného členského státu (čl. 196 směrnice) v něm prvek nemá, takže
chybějící prvek nedokazuje, že přenesení není.

Důsledek u došlé faktury od dodavatele z EU (typicky slovenského) za
služby v režimu přenesení:

1. Místo plnění odvodí applier z prefixu DIČ dodavatele jako EU
   (`VatPlaceDerivation`).
2. Kód DPH, který na řádek doplní historie (`RowHistoryEnricher`, např.
   `cz-217`), odmítne `VatCodeDerivation::conflict()`: „kód je
   samovyměření, doklad přenesení daňové povinnosti neuvádí“.
3. ISDOC nenese druh plnění, takže odvození jednoznačný kód nedá →
   `DocumentApplier` hlásí chybu `vat_code_unknown` a doklad nejde použít.
   Ručně zvolený kód dostane falešné varování `vat_code_pin_conflict`.

Před fází 1 byl signál `null`, kontrola se přeskočila a kód z historie
platil. Korpus chybu nechytí — ISDOC je v něm jen mezi českými stranami
(#119 D10).

## Co je potřeba udělat

- `false` jen tehdy, když je plnění podle stran **zjevně tuzemské**: země
  dodavatele = země odběratele.
  - Země dodavatele = prefix jeho DIČ (`PartyTaxScheme` s `TaxScheme`
    `VAT`), malými písmeny; bez DIČ země jeho adresy.
  - Země odběratele = země jeho adresy.
  - Chybí-li kterákoli země nebo prefix DIČ není písmenný → `null`.
  - Země se liší → `null`.
- `true` z `LocalReverseCharge` / `LocalReverseChargeFlag` beze změny.
- Prefix DIČ ber stejně jako `VatPlaceDerivation` (normalizace, první dva
  znaky, jen písmena). Nerovnost kvůli zvláštnímu prefixu (řecké `EL`)
  vede na `null`, což je bezpečné — nic dalšího neřeš.
- Upravit docblock čtečky a řádek `vat.reverseCharge` v
  `docs/einvoice.md` § 5.

## Testy

- `IsdocReaderTest`:
  - nová fixtura `tests/Fixtures/Exchange/isdoc/invoice_eu_supplier.isdoc`
    — fiktivní dodavatel ze Slovenska s DIČ `SK…`, odběratel z ČR, EUR,
    `VATApplicable true`, řádky 0 %, bez `LocalReverseCharge` → `vat`
    bez `reverseCharge`;
  - `invoice_full.isdoc` (obě strany CZ) dál `false`;
  - fixtury bez země odběratele (`invoice_min`, `invoice_eur`,
    `credit_note`) nově bez `reverseCharge` — vědomá změna, vyjmenovat
    v commit message.
- `DocumentApplierTest`: ISDOC z nové fixtury přes `IsdocReader`, na
  řádku kód `cz-217` (jako z historie) → `/preview` bez `vat_code_unknown`
  a `vat_code_derived`, efektivní kód `cz-217`. Vzor
  `testReceivedIsdocWithoutPlaceDerivesIntracomFromVatId` a
  `buildVatDerivingApplier()`.
- Round-trip korpusu i fixtur generátoru beze změny.

```bash
vendor/bin/phpunit --filter 'IsdocReaderTest|IsdocCorpusRoundTripTest|IsdocWriterTest|IsdocImportServiceTest|DocumentApplierTest'
```

## Hotovo když

- [ ] Čtečka vrací `false` jen u plnění se stejnou zemí dodavatele
      a odběratele, jinak `null`; `true` beze změny
- [ ] Fixtura dodavatele z EU, testy čtečky a test přes applier
- [ ] `IsdocCorpusRoundTripTest` beze změny výjimek
- [ ] Docblock čtečky a `docs/einvoice.md` § 5
- [ ] `**Stav:**` + `python3 scripts/tasks-index.py`
