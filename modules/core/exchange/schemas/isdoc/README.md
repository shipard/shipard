# XSD schémata ISDOC

Oficiální XML schémata českého standardu elektronické fakturace ISDOC.
Slouží jako validátor výstupu generátoru `IsdocWriter` (testy, CLI
`shpd-server einvoice-render`, ve fázi 2 i za běhu) — proto leží
v repozitáři a nic se nestahuje ze sítě (`LIBXML_NONET`). Viz
`docs/einvoice.md`.

| Soubor | Verze | Zdroj |
|---|---|---|
| `isdoc-invoice-6.0.1.xsd` | 6.0.1 (26. 5. 2014) | `https://isdoc.cz/6.0.1/xsd/isdoc-invoice-6.0.1.xsd`, zrcadlo `https://archiv.mv.gov.cz/isdoc/soubor/isdoc-invoice-6-0-1-xsd.aspx` |
| `isdoc-invoice-6.0.2.xsd` | 6.0.2 | `https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd` |

- **Staženo:** 2026-10-20, beze změny obsahu (žádné ruční úpravy; licenční
  hlavička musí zůstat)
- **Kontrolní součty (MD5):**
  `68cc18d2723b94dccb4e7dd08148ff7d` isdoc-invoice-6.0.1.xsd ·
  `ca5c92bbb41358368f162f326fd2682e` isdoc-invoice-6.0.2.xsd
- **Licence** (z hlavičky souborů): © 2009 SPIS, © 2010–2014 ICT UNIE,
  © 2022 MVČR. Schéma i dokumentaci lze bez poplatku používat, kopírovat,
  upravovat a šířit k libovolnému účelu, pokud zůstane zachována
  licenční poznámka; jména SPIS, ICT UNIE a ISDOC se nesmí bez písemného
  svolení použít k propagaci odvozených produktů; bez záruky.
- Varianty `-dsig` (s vloženým elektronickým podpisem) nepotřebujeme —
  podpis je mimo rozsah #119.
- `SchemaLoader` modulu čte z `schemas/` jen `shpd.*.v1.json`; této
  podsložky si nevšímá.

## Rozdíl 6.0.1 → 6.0.2

Jen licenční hlavička, atribut `version` a `xs:key` na
`InvoiceLines/InvoiceLine/ID` (ID řádků musí být unikátní). Generátor
píše `version="6.0.1"` kvůli kompatibilitě příjemců a výstup musí projít
oběma schématy (#119 D6).

## Aktualizace

1. stáhnout soubor z URL výše (beze změn) a přepsat ten v repozitáři,
2. aktualizovat datum a MD5 v této tabulce,
3. spustit `vendor/bin/phpunit --filter 'Isdoc'` — generátor i korpus
   (`IsdocCorpusRoundTripTest`, `CorpusExpectedTest`) se validují proti
   oběma schématům.
