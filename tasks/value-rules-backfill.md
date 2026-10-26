# Normalizace existujících dat adresáře — `values-normalize`

**Stav:** naplánováno — rozhodnutí schválená 2026-10-26 (#118 D22), neimplementováno; pustit až po `tasks/value-rules-phase2.md`


## Kontext / Cíl

Po `tasks/value-rules-phase2.md` ukládají všechny zápisy do adresáře
kanonický tvar (D18, D20), existující záznamy ale zůstávají, jak přišly
(ze starého Shipardu, ruční zadání, import). Agregovaně v kopiích
reálných dat: telefony osob skoro všechny v národním tvaru bez předvolby,
IBAN partnerů ve sloupci čísla účtu u 9–37 % účtů, PSČ s mezerou kolem
1 %, několik IČO bez vedoucích nul.

**Cíl:** jednorázový, opakovatelný příkaz, který existující záznamy
převede stejnými pravidly jako uložení a vypíše, co změnil a co zůstalo
nevalidní.

## Návaznost

- **#118** D22 (komentář 2026-10-26); D1, D18, D20 (pravidla)
- `tasks/value-rules-phase2.md` — normalizace při uložení (třída nad
  sloupci adresáře, pravidlo země osoby, D20) — **předpoklad**

## Před implementací přečti

- `tasks/value-rules-phase2.md` (oddíl 2) a kód, který z něj vznikl
- `docs/cli.md` → `shpd-ds` (vzor `accbal-regenerate … [--dry-run]`,
  `work-orders-invoice-run … [--dry-run]`)
- `docs/exchange-format.md` → Normalizace a validace hodnot
- `modules/world/validations/README.md`

## Rozhodnutí

- **D22** — `shpd-ds values-normalize [--dry-run] [--json]` nad osobami,
  adresami, kontakty, účty osob a vlastními účty; idempotentní; výpis
  změn po druzích hodnot a ID záznamů s nevalidní hodnotou. Doklady,
  snapshoty a tiskový tvar adres nemění. Na kopiích reálných dat ho
  pouští člověk po `--dry-run`.

## Příkaz

| Tabulka | Sloupce |
|---------|---------|
| `base_persons_persons` | `company_id`, `vat_id`, `tax_id`, `phone`, `email`, `web` |
| `base_persons_addresses` | `zip`, `country` |
| `base_persons_contacts` | `email`, `phone` |
| `base_persons_bank_accounts` | `account_number`, `iban`, `bic` (včetně D20) |
| `economy_codebooks_bank_accounts` | `account_number`, `iban`, `bic` (včetně D20) |

- **Stejná pravidla jako uložení** — volá třídu z fáze 2, žádná vlastní
  logika. Země osoby podle D18 (sídlo → první adresa → země zdroje dat).
- **Zápis:** jen změněné řádky, jen dotčené sloupce, bez změny `docState`
  a bez událostí dokumentů; celý běh v jedné transakci (při chybě nic).
- **Idempotence:** druhý běh nic nezmění a hlásí 0 změn.
- **Výpis** (text i `--json`): počet záznamů a změn po tabulce a sloupci,
  zvlášť přesuny IBAN ze sloupce čísla účtu (D20); seznam **nevalidních
  hodnot po opravě tvaru** jako `tabulka · id · sloupec · kód` (kódy D13)
  k ruční opravě. Žádné hodnoty, jména ani adresy — výstup se může dostat
  do issue.
- **`--dry-run`** spočítá a vypíše totéž, nezapíše nic.
- Modul `economy.codebooks` neaktivní → tabulka vlastních účtů se
  přeskočí (vypsat).

**Nemění:** doklady a jejich snapshoty stran, `display_line`
a `display_block` adres, pravidla obsahových štítků, bankovní výpisy,
historii.

## Kroky

1. Příkaz, testy → commit
   `feat(persons): shpd-ds values-normalize — normalizace existujících údajů adresáře (#118 D22)`
2. `docs/cli.md`, zmínka v `docs/exchange-format-persons.md`,
   `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs: values-normalize (#118 D22)`

## Hotovo když

- [ ] Testy: každá tabulka a sloupec, D20 všechny stavy, osoba bez adresy
      (země zdroje dat), `--dry-run` nic nezapíše, druhý běh 0 změn,
      výpis bez hodnot.
- [ ] Na volných zdrojích dat (sady korpusu, demo sada): běh, druhý běh
      0 změn; `dataset-verify` sad korpusu `ok`, snímek deníku beze změny.
- [ ] Na kopiích reálných dat **jen `--dry-run`**; počty změn a nevalidních
      hodnot (bez ID) do závěrečné zprávy.
- [ ] `vendor/bin/phpunit --filter` na dotčené testy; `docs/cli.md`,
      `**Stav:**`, index tasků.

## Pasti

- **Ostrý běh na kopiích reálných dat pouští člověk.** Claude Code tam
  smí jen `--dry-run`.
- **Nevalidní hodnota se neopravuje** — jen se vypíše; mění se tvar
  (a přesun D20), ne obsah.
- **Výstup bez osobních údajů** — jen počty, ID záznamů a kódy.
- Párování (`PartyResolver`, `BankAccountResolver`, import výpisů) musí
  po běhu fungovat stejně — páruje přes normalizované tvary na obou
  stranách, staré i nové záznamy.
