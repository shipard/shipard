# Task: Partner účetního a pokladního dokladu ve formátu, konec `_resolve` v pokynu

**Stav:** hotovo — implementováno 2026-11-23 (#166 etapa 3, část C2; D30, D32)

**Issue:** #166 (etapa 3, část C)

**Cíl:** Dvě reference, které formát dokladu dosud neuměl říct jinak než
volbou v `_resolve`, dostanou vlastní pole. Pokyn pak `_resolve` nečte
vůbec (D32) a datové sady přestanou partnera účetního dokladu ztrácet.

## Před implementací přečti

- issue #166 — D30, D32
- `tasks/doc-format-references.md` včetně poznámek k implementaci
- `docs/exchange-format.md` — § 5, § 7, § 9; `docs/datasets.md` § 4
  („řádkový partner účetního dokladu a hlavičkový partner `cmnbkp` se
  nepřenáší“)
- `DocumentApplier`: `reconcile()` (`resolvedHeadPartner`,
  `resolvedRowPartners`, `resolvePin()`), `transform()` (partner podle
  typu dokladu), `transformRows()`
- `DocumentExporter` — hlavička, `rows()`, varování o nepřenášeném
  partnerovi
- `tests/Integration/Exchange/AccountingDocumentImportTest.php`,
  `CashDocumentImportTest.php`

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D30** — `partner` na hlavičce účetního a pokladního dokladu
  a `rows[].partner` jsou pole formátu s referencí.
- ✓ **D32** — pokyn nečte `_resolve`.

## Upřesnění pro implementaci

- **U1 — Pole.** `partner` (kořen dokladu) a `rows[].partner`: reference
  na osobu, stejná definice jako strana v
  `tasks/doc-format-references.md` (reference, nebo objekt s `ref`
  a daty). `partner` platí u typů, kde dnes hlavičkového partnera nese
  volba (`cmnbkp`, typy vázané na pokladnu); u faktur je partnerem strana
  — `partner` u nich je chyba validace.
- **U2 — Pokyn.** Obě pole přes `ReferenceResolver`; `_resolve` se
  v pokynu nečte vůbec — dočasná výjimka z části C1 mizí. Payload pokynu
  s `_resolve` projde, blok se ignoruje s jedním info nálezem
  `resolve_ignored`.
- **U3 — Návrh.** Volby `_resolve.partner` a `_resolve.rows[].partner`
  fungují dál; uvedené pole má přednost.
- **U4 — Export a sady.** `DocumentExporter` obě pole vyváží (objekt
  s `ref` a daty u hlavičky, reference u řádku); varování o nepřenášeném
  partnerovi zmizí. Generátor sad a demo sada podle toho.
- **U5 — Testy.** Matice: případ účetního dokladu s partnerem na
  hlavičce i řádku a pokladní doklad s partnerem — musí držet. Payloady:
  `partner` na faktuře → chyba; `_resolve` v pokynu → `resolve_ignored`.
  Importní integrační testy přejdou z voleb na pole.

## Co je potřeba udělat

1. Schéma (tři kopie), validátor, applier (U1–U3) + unit testy.
2. Export, generátor sad, demo sada (U4).
3. Matice, payloady, integrační testy (U5).
4. Dokumentace: `docs/exchange-format.md` § 5, § 7, § 9 (`_resolve` jen
   návrh), `docs/datasets.md` § 4 (limit zmizí), stav tasku,
   `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

Matice, payloady, `DatasetRoundTripTest`, `dataset-verify` veřejných sad
beze změny, demo sada seed → dump → seed → dump stabilní.

## Mimo rozsah

- Další sloupce, které export nenese (doprava a platební terminál,
  adresa partnera, autor).
- Runner starého Shipardu — partnera účetního dokladu bude posílat polem
  (etapa 6).

## Hotovo když

- [x] `partner` a `rows[].partner` ve schématu, applieru a exportu
- [x] pokyn nečte `_resolve`
- [x] sady partnera účetního dokladu přenášejí; limit v dokumentaci
      zrušen
- [x] matice a payloady podle U5; cílené testy, pak celá unit sada

## Poznámky k implementaci

Implementováno 2026-11-23 ve třech commitech kódu (`84b60060` schéma,
validátor a applier; `be7f69d1` export a generátor sad; `d8e3be1a` matice
a payloady) a commitu dokumentace.

### Upřesnění přijatá při plánování

- **R1 — hlavička pokladního dokladu.** Partnera hlavičky pokladního
  dokladu a prodejky nese dál **strana podle směru** (tak ho píše export:
  má bankovní spojení dodavatele a v importním módu z ní vzniká snapshot).
  Pole `partner` applier u těchto typů přijme (U1) a má přednost jako
  dřív volba; určí-li pole a strana různé osoby, pokyn doklad odmítne
  chybou `partner_conflict` (v návrhu pole tiše vyhrává). Polem `partner`
  export píše jen hlavičku účetního dokladu.
- **R2 — rozsah `resolve_ignored`.** Neprázdný blok `_resolve` se
  v pokynu zahodí celý hned po kontrole schématu
  (`CanonicalReferences::withoutResolve()`) a payload dostane jeden info
  nález na cestě `_resolve`. `vat_pin_ignored` tím v pokynu zaniká
  (v návrhu zůstává pro volby mimo větev derivace). Důsledek: klient,
  který pošle zpět odpověď z `/preview`, dostane info při každém `/apply`.
- **R3 — `partner` na faktuře a partner jen údaji.** V pokynu chyba
  (`partner_not_supported`, `reference_missing`); v návrhu jen varování
  a pole se nepoužije — kopie schématu v profilu pošty pole ukáže modelu
  a tvrdá chyba by zablokovala návrh z pošty. Pole se hledá **jen přes
  `ref`** v obou režimech: je nové, žádný uložený návrh ho nenese, podle
  údajů se proto nehledá ani v návrhu.

### Odchylky od zadání

- **Integrační testy importu** (`AccountingDocumentImportTest`,
  `CashDocumentImportTest`) přešly z voleb na pole už v commitu 1 —
  posílají pokyn s volbou partnera a po změně applieru by mezi commity
  padaly. Matice a případy payloadů jsou v commitu 3 podle zadání.
- **Souhrn `_resolve.summary`** počítá i bloky `partner`
  a `rows[].partner` (nalezená reference = `matchedCount`).
- **Demo sada se nezměnila.** Generátor pole umí (`RowRec.partner_key`,
  hlavička `cmnbkp` s `partner_key`), ale příběh `it-servis` partnera na
  účetním ani pokladním dokladu nemá; doklad s partnerem do příběhu
  nepřibyl — round-trip pokrývá matice.

### Ověření (volné zdroje dat)

- Unit sada 12 088 testů — jediné selhání je náhodný
  `DatabaseManagerTest::testGeneratePasswordContainsVariedChars` (heslo
  bez číslice), sám o sobě projde. Generátor sad 330 testů.
- Integrační sada nad zdrojem se sadou Plátce DPH: 601 testů bez selhání
  (21 přeskočených). Matice round-tripu 22 / 22 (dva nové případy),
  případy payloadů 37 (čtyři nové).
- `dataset-verify --json` tří veřejných sad po čerstvém seedu: výstup
  bajtově shodný s kódem před taskem (worktree), všude 6 × `ok`.
- Demo sada: seed → dump má `docs/` bajtově shodné se sadou v repu,
  druhé kolo seed → dump stabilní (liší se jen manifest).

### Otevřené body

- **Runner starého Shipardu** posílá partnera účetního dokladu volbou
  v pokynu. Od tohoto tasku se volba ignoruje (jen info
  `resolve_ignored`) a doklad vznikne **bez partnera**, dokud runner
  nepřejde na pole (etapa 6).
- **Výstupní schéma profilu pošty** nese nová pole; popis říká, že doklad
  z papíru je neplní. Verze promptu se nemění, existující zdroje dat si
  drží uložené starší schéma. Dopad na extrakci ověří evaluace (pouští
  člověk).
