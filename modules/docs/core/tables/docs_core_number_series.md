# Tabulka: docs_core_number_series

Číselná řada dokladů. Drží konfiguraci pro generování čísla dokladu
určitého typu. Jeden typ může mít víc řad (FVB tuzemsko / FVB EUR / …);
řada je **vázaná pevně na jeden typ dokladu**.

Stavy: standardní `core.system.docStatesArchive` (Koncept → V pořádku
→ V archívu / Smazáno) — žádný Potvrzeno / Storno.

## Sloupce

### `identity`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_type` | enumString(20) → `docs.core.docTypes` | Typ dokladu (`invno`, `invni`, …) |
| `cash_desk` | int, nullable → `economy_codebooks_cash_desks` | Pokladna — povinná pro typ s `series_binding: cash_desk`, jinak NULL |
| `warehouse` | int, nullable → `economy_codebooks_warehouses` | Sklad — povinný pro typ s `series_binding: warehouse`, jinak NULL |
| `name` | varchar(100) | Lidský název řady |
| `notice` | varchar(250), nullable | Volná poznámka |

### Řady vázané na entitu

Typ dokladu může v `docs.core.docTypes` deklarovat `series_binding`
(`cash_desk` | `warehouse`). Řada takového typu **musí** mít vyplněný
odpovídající FK a druhý NULL; řada nevázaného typu musí mít oba NULL.
Vazba se při uložení dokladu denormalizuje z řady do hlavičky
(`docs_core_heads.cash_desk`) a přepíše, co poslal klient — stejně jako
`doc_type`. Změna pokladny pod existujícími doklady nedává smysl, formulář
ji po založení řady nabízí jen ke čtení.

### `numbering`

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_number_code` | varchar(10), nullable | Vkládá se do `%C` placeholderu vzorce |
| `doc_number_pattern` | varchar(50) | Vzorec čísla dokladu — viz placeholdery níže |
| `reset_scope` | enumString(15) → `docs.core.resetScopes` default `fiscal_year` | `none` (průběžný counter) / `fiscal_year` (restart každý rok) |

### `validity`

`valid_from`, `valid_to` — volitelné období platnosti řady.

### `sending`

Odesílatel dokladů řady při odeslání e-mailem (#90 D39) — viz
`docs/mail/outbound.md` → Odesílatel záznamu.

| Sloupec | Typ | Popis |
|---|---|---|
| `email_from` | varchar(200), nullable | „Odesílat z“ — NULL = automaticky (výchozí adresa `mail.defaultFrom`), jinak jedna z povolených adres |
| `email_from_name` | varchar(200), nullable | Jméno v hlavičce From; NULL = název vlastní firmy |

Uložit jde jen adresa, ze které zdroj dat smí odesílat (výchozí adresa
nebo aktivní odesílatel pošty). Když odesílatel později přestane být
aktivní, řada si adresu nechá a odeslání dokladu skončí chybou
`SENDER_NOT_ALLOWED` — ne tichým přechodem na výchozí adresu.

### Systémové

`docState`, `docStateMain` (default 10/1).

## Vzorec čísla dokladu

| Placeholder | Zdroj | Příklad |
|---|---|---|
| `%D` | `doc_id_code` z cfgItem `docs.core.docTypes` | `1` (FVB), `2` (FPB) |
| `%C` | `doc_number_code` na řadě | `A`, `EUR` |
| `%y` | Rok 2-místně z `fiscal_years.doc_number_prefix` | `26` |
| `%Y` | Rok 4-místně | `2026` |
| `%3..%6` | `sequence_number` doplněný nulami | `0001`, `00001` |

Default vzorec pro nový typ je `%D%y%C%4` (přidělován provisionerem
z `docs.core.docTypes.{type}.doc_number_pattern_default`).

Resolver vzorce přijde ve Fázi 2 (`assignDocumentNumber`).

## Validace

Implementováno v `NumberSeriesDocument::validate`:

- `name`, `doc_type`, `doc_number_pattern` jsou povinné
- Pokud vzorec obsahuje `%C`, `doc_number_code` je povinný
- Vzorec smí obsahovat jen známé placeholdery (`D`, `C`, `y`, `Y`, `3`, `4`, `5`, `6`)
- `reset_scope` je `none` nebo `fiscal_year`
- `valid_from <= valid_to`

## Indexy

- `idx_doc_type` — `(doc_type)`, lookup per typ
- `idx_binding_cash_desk` — `(doc_type, cash_desk)`, „řady pokladny X typu Y"
  (provisioner, denormalizace, import)
- `idx_binding_warehouse` — `(doc_type, warehouse)`, totéž pro sklad
- `idx_doc_state` — `(docStateMain ASC, name ASC)`, viewer řazení

## Provisioner

`NumberSeriesProvisioner` (volaný z `ds-upgrade`) idempotentně zajistí, že
pro každý typ dokladu z cfgItem `docs.core.docTypes` existuje aspoň
jedna řada (kromě `Smazáno`, docState=90). Default je vytvořena rovnou
jako `V pořádku` (40/3) s patternem z `doc_number_pattern_default`.

Uživatel si může výchozí řadu zarchivovat a založit vlastní; provisioner
pak nezasáhne. Typy se `series_binding` tento provisioner **přeskakuje**.

### Vázané řady — `BoundNumberSeriesProvisioner`

Pro každý typ se `series_binding` a každou entitu ve stavu `V pořádku`
(pokladna / sklad, `docState = 40`) zajistí řadu (`doc_type`, FK) mimo
stav Smazáno. Nová řada: `name` = „{název typu} — {kód entity}",
`doc_number_code` = kód entity (do `%C`; žádný nový placeholder),
`doc_number_pattern` z `doc_number_pattern_default` typu, `reset_scope`
`fiscal_year`, stav 40/3. Idempotentní, vrací `{created, existing}`.

Spouští se:

- z `ds-upgrade` **i pod `skipProvisioning`** (řady jsou infrastruktura,
  kterou import dokladů potřebuje — dohledává řadu podle typu a kódu
  pokladny);
- z `CashDeskSeriesEventHandler` (`documentEventHandlers` docs.core na
  tabulce `economy_codebooks_cash_desks`, event `afterSave`) — pokladna
  uložená do stavu 40 dostane řady hned, bez `ds-upgrade`.

Přejmenování pokladny (změna `code`) se do `doc_number_code` existující
řady **nepropaguje** — čísla vydaných dokladů se nesmí měnit. Vazby jsou
obecné přes `NumberSeriesDocument::BINDINGS` (binding → tabulka); sklad
nevyžaduje žádný další kód, jen typ dokladu se `series_binding: warehouse`.

## Související

- [docs_core_heads](docs_core_heads.md) — odkazuje sem přes `number_series`
- [docs_core_number_counters](docs_core_number_counters.md) — atomický counter per řadu+rok
- [NumberSeriesDocument](../src/NumberSeriesDocument.php), [NumberSeriesForm](../src/NumberSeriesForm.php), [NumberSeriesViewer](../src/NumberSeriesViewer.php), [NumberSeriesProvisioner](../src/NumberSeriesProvisioner.php)
- `docs/docs-mvp.md` sekce 5 — kompletní design číselných řad
