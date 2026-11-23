# Task: Idempotence pokynu — `source.ref` vrátí existující doklad

**Stav:** naplánováno — zadání 2026-11-23 (#166 etapa 4, část 2; D7, D46); až po `tasks/doc-defaults-shared.md`

**Issue:** #166 (etapa 4)

**Cíl:** Skript nebo import, který pošle tentýž doklad podruhé (opakovaný
běh, výpadek spojení po odeslání), nezaloží duplicitu: doklad nese
identifikátor ze zdrojového systému a opakovaný pokyn se stejným
identifikátorem vrátí existující doklad beze změny.

## Před implementací přečti

- issue #166 — D7, D46
- `docs/exchange-format.md` — § 5 (blok `source`), § 10 („Přegenerovat
  v místě“, „Apply uvnitř cizí transakce“), § 11, § 12 Source lineage
- `DocumentApplier`: `checkIdempotent()` (idempotence podle zprávy
  pošty), `apply()`, `preview()`, `writeLineageTargets()`, větev
  `replaceConcept`
- `modules/docs/core/tables/docs_core_heads.jsonc` (skupina `lineage`),
  `modules/docs/core/config/sourceKinds.jsonc`;
  `modules/economy/items/tables/economy_items.jsonc` (`source_ref` — vzor)
- `modules/core/exchange/src/Export/DocumentExporter.php`

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D7** — doklady se přes API neaktualizují; `replaceConcept` zůstává.
- ✓ **D46 — `source.ref`.** Identifikátor dokladu ve zdrojovém systému
  vedle `source.kind`; sloupec `source_ref` na hlavičce. Opakovaný pokyn
  se stejnou dvojicí vrátí existující doklad beze změny, s příznakem
  v odpovědi. Obsah se neporovnává. Platí pro živé doklady — doklad
  v koši nový pokyn neblokuje.

## Upřesnění pro implementaci

- **U1 — Formát a sloupec.** `source.ref`: text do 60 znaků.
  `docs_core_heads.source_ref` (`varchar(60)`, nullable, skupina
  `lineage`, systémový) a index přes (`source_kind`, `source_ref`).
  `source.ref` bez `source.kind` je chyba validace. Do `sourceKinds`
  přibude druh `api` (skript nebo jiný systém přes REST).
- **U2 — Dohledání.** Před uložením (v `apply()` na místě dnešní
  `checkIdempotent()`): existuje doklad se stejným `source_kind`
  a `source_ref` v jiném stavu než koš → vrať ho. Odpověď má stejný tvar
  jako u idempotence podle zprávy pošty (`savedDocId`,
  `_resolve.summary.status = "alreadyApplied"`), HTTP 200. Typ dokladu se
  neporovnává s payloadem jinak než nálezem: existující doklad jiného
  typu než `docType` je chyba `source_ref_conflict` (422) — stejný
  identifikátor pro jiný doklad je chyba volajícího.
- **U3 — Souběh.** Dva současné pokyny se stejnou dvojicí nesmí založit
  dva doklady — zámek podle klíče uvnitř transakce uložení (způsob zvol
  a popiš v poznámkách; unikátní index nejde, koš neblokuje).
- **U4 — `/preview`.** Existující doklad hlásí info nálezem
  `already_applied` s jeho id a číslem; zbytek náhledu běží jako obvykle.
- **U5 — Ostatní cesty.** `replaceConcept`: cílový koncept se z dohledání
  vylučuje a `source_ref` přebírá z payloadu. Importní mód: beze změny —
  opakovaný běh importu vrátí existující doklad. Návrh: idempotence podle
  zprávy pošty zůstává; `source.ref` funguje v obou režimech stejně.
- **U6 — Export.** `DocumentExporter` píše `source.ref`, je-li vyplněný
  (sady nesou původ). Matice round-tripu sloupce původu neporovnává —
  ověř, že případy matice `source_ref` nenesou (jinak by apply vrátil
  původní doklad).
- **U7 — Testy.** Unit: dohledání, koš neblokuje, jiný typ = chyba,
  `ref` bez `kind`. Integrační (payloady): dvakrát týž pokyn = jeden
  doklad a `alreadyApplied`; druhý pokyn s jiným obsahem doklad nezmění;
  doklad v koši → vznikne nový; `replaceConcept` se stejným `ref`;
  import dvakrát.

## Co je potřeba udělat

1. Sloupec, index, `ds-upgrade`; schéma (tři kopie), validátor.
2. Applier (U2–U5) + unit testy.
3. Export, integrační testy (U6, U7).
4. Dokumentace: `docs/exchange-format.md` § 5, § 10, § 11 („Doklad
   skriptem“ — bezpečné opakování přes `source.ref`), § 12;
   `docs/rest-api.md`, pokud apply dokladu popisuje; stav tasku,
   `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

Payloady z U7; matice beze změny; `dataset-verify` veřejných sad beze
změny; dvojí odeslání minimálního payloadu z návodu přes REST `/apply`.

## Dopad na import ze starého Shipardu

Runner pošle identifikátor zdrojového dokladu jako `source.ref` — opakovaný
běh pak doklady nezdvojí. Druh zdroje pro import určí etapa 6.

## Mimo rozsah

- Aktualizace existujícího dokladu pokynem (D7).
- Porovnání obsahu opakovaného pokynu s uloženým dokladem.
- `source.ref` u ostatních formátů (osoby a položky ho mají po svém).

## Hotovo když

- [ ] `source.ref` ve schématu, sloupec `source_ref` na hlavičce
- [ ] opakovaný pokyn vrátí existující doklad; koš neblokuje; souběh
      nezaloží duplicitu
- [ ] `/preview` existující doklad hlásí
- [ ] testy podle U7; cílené testy, pak celá unit sada
- [ ] dokumentace, stav tasku
