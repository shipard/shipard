# Tabulka: docs_core_vat_recap

Rekapitulace DPH per (vat_code, vat_pct, reverse_charge_code) na hlavičce. **Persistovaná**
tabulka — sestavovaná v `Document::beforeSave` hlavičky (Fáze 2). Stará
rekapitulace se smaže, nová se vloží přes child-tables sync v
`TableGateway`.

## Účel

- Reverse charge páry vyžadují generovaný protizáznam (oddanění) — flag
  `is_reverse_pair = 1`. Sčítací flagy na něm jsou 0, takže do
  hlavičkových součtů nepřispívá.
- Sčítací flagy (`sum_base`, `sum_tax`, `sum_total`) řídí, zda daný
  řádek rekapitulace přispívá do hlavičkových `total_*`. Hodnoty pochází
  z `vatCode` definice v cfgItem `world.vat.{country}.vatCodes`.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_head` | int → `docs_core_heads` | Hlavička |
| `vat_code` | varchar(20), nullable=false | Kód DPH (jako u `docs_core_rows.vat_code`, viz tam pro důvod proč varchar a ne enumString) |
| `reverse_charge_code` | varchar(3), nullable | Předmět plnění u tuzemského přenesení daňové povinnosti — třetí složka klíče skupiny; nese ho řádek s kódem přenesení i jeho oddaňovací pár. Odtud ho čte kontrolní hlášení (A.1 / B.1) |
| `vat_pct` | numeric(5,2) | Procento DPH platné pro DUZP |
| `base`, `tax`, `total` | numeric(15,2) | Částky v měně dokladu |
| `base_dom`, `tax_dom`, `total_dom` | numeric(15,2) | Částky v domácí měně (přes `exchange_rate`) |
| `sum_base`, `sum_tax`, `sum_total` | boolean default 1 | Flagy z `vatCode` definice |
| `is_reverse_pair` | boolean default 0 | 1 = generovaný oddaňující protizáznam |
| `order_pos` | smallint default 0 | Pořadí v rekapitulaci |

## Bez doc-state

Tabulka **nemá** `docState` / `docStateMain` — je čistě technickým
dumpem výpočtu. Životní cyklus drží hlavička přes child-tables sync.

## Indexy

- `idx_doc_head` — `(doc_head, order_pos)`
- `idx_vat_code` — `(vat_code)` — připravený lookup pro budoucí Přiznání DPH

## Související

- [docs_core_heads](docs_core_heads.md) — parent
- [docs_core_rows](docs_core_rows.md) — vstup do agregace
- `docs/docs-mvp.md` sekce 8 — algoritmus sestavení rekapitulace
