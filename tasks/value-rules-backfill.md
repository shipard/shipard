# Normalizace existujících dat adresáře — `values-normalize`

**Stav:** hotovo — implementováno 2026-10-26 ve 3 commitech (D24 + D25, příkaz `values-normalize`, dokumentace); navazuje na `tasks/value-rules-phase2.md`; odchylky a ověření níže


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

- **D24 — Adresa bez země** dostane pro PSČ zemi osoby (řetěz D18: sídlo →
  první adresa se zemí → země zdroje dat). Do `country` se nic nezapisuje
  — země slouží jen pravidlům PSČ (normalizace i kontrola D19). V kopiích
  reálných dat je adres bez země 0–4 %; bez toho by zůstaly v tiskovém
  tvaru i po backfillu.
- **D25 — Vlastní účet z hlavičky výpisu bez kódu banky** (GPC nese jen
  předčíslí a číslo): když reference nemá kód banky a klíčem se nenapáruje,
  `StatementImportService` ji porovná s tuzemským `account_number` vlastních
  účtů podle předčíslí a čísla (bez vedoucích nul) a přijme ji jen při
  **jediné** shodě. Vrátí chování starého `checkBankAccount` (regrese
  fáze 2: žádný vlastní účet v kopiích reálných dat nemá `ebanking_id`).
  Párování partnerů beze změny — řádky GPC kód banky nesou.
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

0. D24 v `PersonValues` (normalizace i kontrola PSČ, všechny cesty, které
   zemi osoby už znají — `PersonContext`, `_country`), D25
   v `StatementImportService` + testy (GPC s vlastním účtem bez
   `ebanking_id`, dva vlastní účty se stejným číslem a jiným kódem banky →
   bez shody; integrační `StatementImportTest` vrátit vlastní účet bez
   `ebanking_id`) → commit
   `fix(persons, bank): PSČ adresy bez země podle země osoby, vlastní účet z hlavičky GPC (#118 D24, D25)`
1. Příkaz, testy → commit
   `feat(persons): shpd-ds values-normalize — normalizace existujících údajů adresáře (#118 D22)`
2. `docs/cli.md`, zmínka v `docs/exchange-format-persons.md`,
   `**Stav:**`, `python3 scripts/tasks-index.py` → commit
   `docs: values-normalize (#118 D22)`

## Hotovo když

- [x] D24: adresa bez země u české osoby i u osoby bez adresy se sídlem
      (země zdroje dat) má PSČ bez mezery a kontrolu D19; `country` zůstává
      prázdná.
- [x] D25: GPC výpis najde vlastní účet podle čísla bez `ebanking_id`;
      nejednoznačná shoda → dnešní chyba „Účet z výpisu … není“.
- [x] Testy: každá tabulka a sloupec, D20 všechny stavy, osoba bez adresy
      (země zdroje dat), `--dry-run` nic nezapíše, druhý běh 0 změn,
      výpis bez hodnot.
- [x] Na volných zdrojích dat (sady korpusu, demo sada): běh, druhý běh
      0 změn; `dataset-verify` sad korpusu `ok`, snímek deníku beze změny.
- [x] Na kopiích reálných dat **jen `--dry-run`**; počty změn a nevalidních
      hodnot (bez ID) do závěrečné zprávy.
- [x] `vendor/bin/phpunit --filter` na dotčené testy; `docs/cli.md`,
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

## Odchylky od zadání (2026-10-26)

- **D24 i při kontrole částečného updatu.** Gateway validuje jen poslaná
  pole, takže `AddressDocument::validate()` u updatu bez klíče `country`
  bere zemi uloženého řádku (`PersonContext::addressCountry()`) — PSČ
  britské adresy se nekontroluje českými pravidly. Zahraniční adresa bez
  země pod českou osobou po D24 přijde o mezery v PSČ a dostane nález —
  záměr rozhodnutí (adresa má mít zemi); dřívější test upraven.
- **D25 jen nad `account_number`** — IBAN vlastního účtu se na holé číslo
  nehádá. Integrační fixtura: vlastní účet bez `ebanking_id`
  s `account_number` shodným s IBAN a hlavička `statement.gpc` s platným
  číslem (dřív dekódovala na nesmysl `192322-`).
- **Runner `Values\DirectoryValuesNormalizer` v `base.persons`** zpracuje
  i tabulku vlastních účtů (touž funkcí jako `BankAccountDocument`);
  aktivitu `economy.codebooks` zjišťuje příkaz přes
  `Core\Server\DataSourceModules`. Země osob jedním dotazem
  (`PersonContext::countryFromAddresses()` nad uloženými adresami mimo
  koš); zpracovávají se řádky ve všech stavech včetně koše (idempotence,
  párování). Dávky po 1000 řádcích (keyset `id`).
- **Exit 0 i s nevalidními hodnotami** — jsou k ruční opravě, ne chyba
  příkazu; nenulový jen chyba volání nebo infrastruktury.

## Ověření (2026-10-26)

- `vendor/bin/phpunit`: 10 838 testů; jediné selhání
  `DatabaseManagerTest::testGeneratePasswordContainsVariedChars` je
  náhodné (heslo z 32 znaků bez číslice, zhruba 1 % běhů), s taskem
  nesouvisí a při opakování prochází; varování a skip z dřívějška.
  Integrační na 4l3j: `StatementImportTest` 10/10 (GPC bez `ebanking_id`
  přes D25), `Persons\ValuesNormalizeCommandTest` 2/2.
- Volné zdroje dat (sady korpusu ob9b / r1lf / wp66, demo 8yft, 4l3j):
  `--dry-run`, ostrý běh i druhý běh 0 změn — data seedovaná po fázi 2
  jsou už kanonická; `dataset-verify --no-apply` tří sad korpusu: všech
  šest kontrol `ok`, rozdíl 0, snímek deníku beze změny. Nevalidní
  hodnoty jen z dřívějších testovacích dat (4l3j 4, demo 2 IBAN).
- Kopie reálných dat **jen `--dry-run`** (agregovaně, bez ID):

  | zdroj | záznamů | změn | telefony | IBAN z čísla účtu | PSČ | nevalidních (po kódech) |
  |---|---|---|---|---|---|---|
  | btpg-p | 7 555 | 1 447 | 1 300 | 106 | 35 | 29 (12 číslo účtu, 8 PSČ, 5 IBAN, 3 IČO, 1 vlastní účet) |
  | e8w1-i | 1 308 | 189 | 62 | 118 | 1 | 9 (5 číslo účtu, 2 IČO, 1 DIČ, 1 PSČ) |
  | 4dnh-5 | 704 | 51 | 9 | 16 | 14 | 11 (9 číslo účtu, 1 DIČ, 1 PSČ) |
  | ur4l-0 | 176 | 14 | 12 | 0 | 1 | 1 (DIČ) |
  | nelh-0 | 92 | 22 | 5 | 17 | 0 | 1 (číslo účtu) |
  | p4zq-s | 296 | 29 | 22 | 6 | 0 | 0 |

  Telefony s víc čísly v jednom poli nebo s písmeny (normalizace je slije
  stejně jako uložení): btpg-p 2, e8w1-i 3, jinde 0 (read-only SQL).
  Ostrý běh na kopiích pouští člověk.
