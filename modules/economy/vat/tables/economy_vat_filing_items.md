# Tabulka: economy_vat_filing_items

Podání DPH — **dokladová úroveň** snapshotu (issue #55, rozhodnutí D15/1).
Jedna řada per (podání, doklad, řádek rekapitulace DPH) s úplným obsahem
instance tvrzení v okamžiku sestavení, společná pro všechny tři typy
tvrzení.

Dvě věci, které tabulka umožňuje a bez kterých by podání bylo jen číslo:

- **rozdíly mezi podáními po dokladech** — dodatečné podání ukáže, který
  doklad se změnil, přidal nebo vypadl (podle `doc_head` + `vat_code` + `vat_pct`);
- **výklad obsahu i po změně konfigurace** — výsledek mapování je
  materializovaný (`dp3_row`, `dp3_col`, `kh_group`, `kh_section`,
  `kh_reverse_charge_code`, `sh_kod`), takže XML jde regenerovat i po pozdější
  úpravě `vat-reports-cz.jsonc`.

Systémová tabulka bez docStates — vlastníkem je podání, zapisuje výhradně
`FilingComposer` (DELETE + INSERT celého podání).

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `filing` | int, reference `economy_vat_filings` | Podání |
| `doc_head` | int | Id hlavičky dokladu **bez reference** — snapshot musí přežít zrušení i smazání dokladu; je to historický údaj, ne živá vazba |
| `doc_type` | enumString(20), cfgItem `docs.core.docTypes` | Typ dokladu |
| `doc_number` | varchar(40) | Naše číslo dokladu |
| `partner_doc_number` | varchar(40) | Ev. číslo dokladu od partnera |
| `total_amount_dom` | numeric(15,2) | Celkem vč. daně v domácí měně — rozhoduje o limitu 10 000 Kč pro rozpad A4/A5 a B2/B3 |
| `vat_duzp` | date, nullable | DUZP |
| `vat_dppd` | date, nullable | DPPD |
| `partner_vat_id` | varchar(20) | DIČ protistrany dobově, ze snapshotu partnera na dokladu |
| `vat_code` | varchar(20) | Kód DPH řádku rekapitulace |
| `vat_pct` | numeric(5,2) | Sazba |
| `base_dom` | numeric(15,2) | Základ v domácí měně |
| `tax_dom` | numeric(15,2) | Daň v domácí měně |
| `is_reverse_pair` | boolean default 0 | Párový řádek samovyměření (opačná strana téhož plnění) |

### Materializované mapování

| Sloupec | Typ | Popis |
|---|---|---|
| `dp3_row` | smallint, nullable | Řádek DPHDP3 |
| `dp3_col` | varchar(10), nullable | `full` / `reduced` — sloupec odpočtu u ř. 40/41/43/44 |
| `kh_group` | varchar(10), nullable | Skupina z configu (`A1`/`A2`/`A4A5`/`B1`/`B2B3`) — před rozpadem |
| `kh_section` | varchar(5), nullable | Sekce po rozpadu enginem (`A1`…`B3`) — to, co reálně šlo do hlášení |
| `kh_reverse_charge_code` | varchar(3), nullable | Kód předmětu plnění z rekapitulace dokladu (`4`, `4a`, `14` …). Nahradil tinyint `kh_kod_pred_pl` (#89 D13), který v databázi zůstává nepoužitý |
| `sh_kod` | tinyint, nullable | Kód plnění DPHSHV (0 zboží, 3 služby) |

## Indexy

- `idx_filing_doc` na `filing, doc_head`

## Pravidla

- **Kód DPH bez mapování je tvrdá chyba sestavení.** U živého reportu se
  nenamapovaný kód jen hlásí, u podání ne — nesmí tiše vypadnout něco, co
  jsme úřadu poslali.
- `partner_vat_id` je to DIČ, které by u řádku použilo kontrolní hlášení:
  u přijatých plnění (A2/B1/B2) dodavatel, u našich plnění odběratel. Bez
  sekce KH rozhoduje směr kódu DPH (`world.vat` → `direction`).
- Řádky jsou **úplné** i u dodatečného přiznání, které v řádcích DPHDP3
  vykazuje jen rozdíly (D15) — jinak by nebylo proti čemu diffovat.

## Související

- [economy_vat_filings](economy_vat_filings.md) — hlavička podání
- [docs_core_vat_recap](../../../docs/core/tables/docs_core_vat_recap.md) — zdroj řádků
