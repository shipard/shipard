# Tabulka: docs_core_rows

Řádky dokladu navázané na `docs_core_heads.id` přes `doc_head`.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `doc_head` | int → `docs_core_heads` | Hlavička, ke které řádek patří |
| `row_kind` | enumInt → `docs.core.rowKinds` | 0 textový (jen popis), 1 běžný (s množstvím a cenou) |
| `order_pos` | smallint default 0 | Pořadí řádku v dokladu |

### Identifikace

| Sloupec | Typ | Popis |
|---|---|---|
| `item` | int → `economy_items`, nullable | Položka z katalogu |
| `description` | varchar(500), nullable | Popis řádku (pro textový řádek povinné v aplikační vrstvě) |

### Množství a cena

| Sloupec | Typ | Popis |
|---|---|---|
| `unit` | int → `core_units`, nullable | Měrná jednotka |
| `quantity` | numeric(15,4) | Množství |
| `unit_price` | numeric(15,4) | Cena za jednotku |
| `total_price` | numeric(15,2) | Cena celkem (vypočtená) |
| `price_calc_mode` | enumInt → `docs.core.priceCalcModes` | 0 z ceny za jednotku, 1 z celkové ceny |

### Sleva

`discount_pct` × `discount_amount` jsou alternativy — uživatel zadá jeden
nebo druhý, druhý se dopočte v Fázi 2 výpočtu řádku.

### DPH

| Sloupec | Typ | Popis |
|---|---|---|
| `vat_code` | varchar(20), nullable | Kód DPH (např. `cz-110`). Bez fixního cfgItem — odvozuje se z `vat_registration` na hlavičce dynamicky. Pole se chová jako volný string s enforcementem na úrovni aplikace |
| `vat_pct` | numeric(5,2) | Resolvované procento (z `world.vat.{country}.vatPercents` podle DUZP) |

`vat_code` je v Phase 1 schválně `varchar` místo `enumString` — framework
vyžaduje fixní cfgItem u enumString, kdežto zde se cfgItem volí podle státu
DPH registrace na hlavičce. Funkčně se chová jako enumString a v pozdější
fázi (až framework bude umět dynamický cfgItem) může přejít na `enumString(20)`.

### Calculated (system)

| Sloupec | Typ | Popis |
|---|---|---|
| `vat_base` | numeric(15,2), system | Základ DPH řádku |
| `vat_amount` | numeric(15,2), system | DPH řádku |
| `vat_total` | numeric(15,2), system | Celkem s DPH |

Plněné v `Document::beforeSave` ve Fázi 2.

### Dimenze deníku (#110 D20)

Hodnota řádku jde do řádku deníku (`journalDimensions` v `economy.accounting`,
`docs/accounting.md` § Dimenze deníku); prázdnou doplní engine z hlavičky.
Pole na formuláři zapíná nastavení Dimenze na dokladech.

| Sloupec | Typ | Popis |
|---|---|---|
| `cost_center` | int → `economy_codebooks_cost_centers`, nullable | Středisko řádku (#110 D23); prázdné = středisko hlavičky, i u pořízení majetku |
| `asset` | int → `economy_assets_assets`, nullable | Karta majetku, které se řádek týká (`docs/assets.md` D15, D47). Povinná u operací s vlajkou `rowAsset: 1`, nepovinná u pořízení (`purchase.asset`) — tyhle řádky kartu z hlavičky nedědí |

## Indexy

- `idx_doc_head` — `(doc_head, order_pos)`
- `idx_item` — `(item)`
- `idx_vat_code` — `(vat_code)`
- `idx_cost_center` — `(cost_center)`
- `idx_asset` — `(asset)`

## Související

- [docs_core_heads](docs_core_heads.md) — parent
- `docs/docs-mvp.md` sekce 7 — výpočet ceny a DPH na řádku
