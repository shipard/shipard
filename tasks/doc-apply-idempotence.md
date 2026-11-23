# Task: Idempotence pokynu — `source.ref` vrátí existující doklad

**Stav:** hotovo — 2026-11-23 (#166 etapa 4, část 2; D7, D46)

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

- [x] `source.ref` ve schématu, sloupec `source_ref` na hlavičce
- [x] opakovaný pokyn vrátí existující doklad; koš neblokuje; souběh
      nezaloží duplicitu
- [x] `/preview` existující doklad hlásí
- [x] testy podle U7; cílené testy, pak celá unit sada
- [x] dokumentace, stav tasku

## Poznámky k implementaci (2026-11-23)

- **Klíč** čte jediná třída `Document\SourceRef` (obě hodnoty neprázdné
  řetězce). Validátor hlásí `ref` bez `kind` kódem `source_kind_missing`.
- **Dohledání (U2)** běží hned za idempotencí podle zprávy, **před
  schématem a validací obsahu** — opakování po výpadku projde, i když se
  mezitím ve zdroji dat něco změnilo. Odpověď nese navíc info nález
  `already_applied` s `docId` a `docNumber` (koncept má jen zástupné
  číslo → `null`). Víc živých dokladů se stejnou dvojicí (obnova z koše)
  = nejnovější.
- **Přesná shoda** (nad rámec zadání): collation databáze nerozlišuje
  velikost písmen, `INV-1` a `inv-1` by byly tentýž doklad a druhý by
  tiše nevznikl. Index dává kandidáty, shodu rozhoduje `SourceRef::matches()`
  v PHP; schéma zakazuje mezery na krajích. Přetížení collation na sloupci
  nepoužito — `SqlGenerator` z pole `collation` skládal `CHARACTER SET
  <hodnota>`, pro název collation tedy neplatné SQL. Generátor je týž den
  opravený (`COLLATE <název>`, kontrola v `ColumnDefinition`), sloupec
  `source_ref` ale zůstává u shody v PHP: na zdrojích, kde už existuje,
  by `ds-upgrade` změnu collation nepoznal.
- **Souběh (U3)**: pojmenovaný zámek MariaDB `GET_LOCK(CONCAT(DATABASE(),
  ':docsrc:<sha1 dvojice>'), 10)` vzatý před `NestedTransaction::run`
  a uvolněný ve `finally` po commitu; první dotaz transakce doklad dohledá
  znovu (čerstvý snímek). Vypršení = 409 `source_ref_busy`. Zamykací čtení
  `FOR UPDATE` zavrženo — na neexistujícím klíči drží zámek mezery indexu
  a souběžné importy sousedních identifikátorů by se blokovaly navzájem;
  zamykací tabulka by byla druhý zdroj pravdy. **Mez**: uvnitř cizí
  transakce se zámek uvolní před commitem volajícího — souběh hlídá
  volající (dnes žádný takový `ref` neposílá).
- **Pošta** (nad rámec zadání): schéma má kopii v `output_schema` profilu,
  pole tedy vidí i model. `MessageProposalApplier::apply()` a náhled
  zprávy v `AnalysisController` `source.ref` zahazují; REST
  s `interpret: true` ho čte (U5).
- **`replaceConcept` (U5)** doslovně: cílový koncept se vylučuje, jiný
  živý doklad se stejnou dvojicí se vrátí jako `alreadyApplied` a koncept
  zůstane nepřegenerovaný. `REPLACE_RESET_HEAD` nuluje i `source_ref`.
- **Sady (U6)**: veřejné sady ani demo sada `source.ref` nenesou; matice
  round-tripu to u každého případu ověřuje. `dataset-seed --no-reset`
  hlásí existující číslo dokladu dřív, než dojde na klíč (beze změny).
- **Ověření**: unit sada 12 170 testů; `Integration\\Exchange` nad `blkm`
  140 testů (4 přeskočené jako dřív); přes REST na `8yft` dvojí odeslání
  minimálního pokynu = jeden doklad a `alreadyApplied`, čtyři kola
  8–16 souběžných `/apply` se stejným `ref` = pokaždé jeden doklad;
  testovací kolo `test-round --only=datasets` nad třemi veřejnými sadami
  (seed, `dataset-verify`, proklikání) 9 kroků `ok`.
