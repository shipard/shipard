# Tabulka: economy_vat_filing_cs_rows

Podání DPH — **výstupní řádky kontrolního hlášení DPHKH1** (issue #55,
rozhodnutí D15/2). Kontrolní hlášení se podává vždy celé (řádné / opravné
/ následné), nikdy rozdílově.

Zaokrouhlení je na haléře (`roundingUnit: 0.01`), takže podaná hodnota je
totožná s přesnou — sloupce `_filed` se u KH proto **vůbec nezakládají**
(D17).

Systémová tabulka bez docStates — zapisuje výhradně `FilingComposer`.

## Sloupce

| Sloupec | Typ | Popis |
|---|---|---|
| `filing` | int, reference `economy_vat_filings` | Podání |
| `section` | varchar(5) | `A1` / `A2` / `A4` / `A5` / `B1` / `B2` / `B3` |
| `row_kind` | varchar(10) | `detail` = řádek per doklad, `aggregate` = součtový řádek A5/B3 |
| `doc_head` | int, nullable | Id hlavičky dokladu; NULL u agregátů, bez reference (snapshot přežije doklad) |
| `doc_number` | varchar(40) | Evidenční číslo daňového dokladu, jak šlo do hlášení: A1/A4 naše číslo, A2/B1/B2 číslo dodavatele |
| `partner_vat_id` | varchar(20) | DIČ protistrany |
| `vat_dppd` | date, nullable | DPPD (fallback DUZP) |
| `reverse_charge_code` | varchar(3), nullable | Kód předmětu plnění u sekcí A1/B1 (`4`, `4a`, `14` …) z rekapitulace dokladu; v XML atribut `kod_pred_pl`. Nahradil tinyint `kod_pred_pl` (#89 D13), který v databázi zůstává nepoužitý |
| `base1`, `tax1` | numeric(15,2) | Základní sazba |
| `base2`, `tax2` | numeric(15,2) | První snížená sazba |
| `base3`, `tax3` | numeric(15,2) | Druhá snížená sazba |

## Indexy

- `idx_filing_section` na `filing, section`

## Pravidla

- Rozpad A4/A5 a B2/B3 (limit 10 000 Kč vč. daně, u A4 navíc CZ DIČ
  odběratele) a sazbová pásma řeší `ControlStatementCalculator` — týž
  engine jako u živého reportu, žádná druhá výpočetní větev.
- Prázdné kontrolní hlášení se podává také; `economy_vat_filings.result`
  pak nese `isEmpty`.

## Související

- [economy_vat_filings](economy_vat_filings.md) — hlavička podání
- [ControlStatementCalculator](../src/ControlStatementCalculator.php) — rozpad sekcí
