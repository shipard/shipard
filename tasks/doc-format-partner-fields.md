# Task: Partner účetního a pokladního dokladu ve formátu, konec `_resolve` v pokynu

**Stav:** naplánováno — zadání 2026-11-22 (#166 etapa 3, část C2; D30, D32); až po `tasks/doc-format-references.md`

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

- [ ] `partner` a `rows[].partner` ve schématu, applieru a exportu
- [ ] pokyn nečte `_resolve`
- [ ] sady partnera účetního dokladu přenášejí; limit v dokumentaci
      zrušen
- [ ] matice a payloady podle U5; cílené testy, pak celá unit sada
