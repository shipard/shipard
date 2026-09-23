# Shipard — Účtování dokladů

Automatické generování záznamů účetního deníku z dokladů. Uživatel nikde nezadává
čísla účtů — zaúčtování se odvozuje z obsahu dokladu (pohyby řádků, DPH
rekapitulace, hlavička) podle deklarativního účtovacího předpisu.

Inspirováno `AccountingDocEngine` ze starého Shipardu; základní princip
(pohyb → předpis → kategorie → maska účtu → účtový rozvrh) zůstává, výrazně
se ale zjednodušuje a modernizuje. Co se ze starého systému **nepřebírá**, je
shrnuto v sekci [Mimo scope](#10-mimo-scope).

---

## 1. Přehled

```
┌──────────────────────────────────────────────────────────────────┐
│  Doklad (docs_core_heads + rows + vat_recap)                     │
│  - řádek má pohyb (operation) — co řádek "dělá"                  │
│  - přechod do docState 40 (V pořádku)                            │
├──────────────────────────────────────────────────────────────────┤
│  AccountingEngine (economy.accounting)                           │
│  - načte účtovací předpis pro zemi (rules.cz)                    │
│  - projde kroky předpisu pro docType                             │
│  - dohledá účty v účtovém rozvrhu                                │
│  - vygeneruje a seskupí řádky deníku, zkontroluje MD = DAL       │
├──────────────────────────────────────────────────────────────────┤
│  Účetní deník (economy_accounting_journal)                       │
│  - jednostranné řádky: účet + MD nebo DAL částka                 │
│  - částky vždy v domácí měně I měně dokladu                      │
└──────────────────────────────────────────────────────────────────┘
```

Klíčové vlastnosti:

- **Deklarativní předpis** — postup účtování je v JSONC konfiguraci per země,
  ne v kódu. Engine je obecný interpret předpisu.
- **Tolerance chyb** — nedohledaný účet nebo nevyrovnané strany **neblokují**
  přechod dokladu do stavu 40. Doklad dostane příznak chyby účtování
  (`accounting_state = 2`) a systém alertů uživatele upomíná, dokud to nedá
  do pořádku.
- **Deník je derivát** — řádky deníku se kdykoliv dají smazat a vygenerovat
  znovu z dokladu. Žádná ruční editace deníku, žádné docStates na deníku.
- **Invariant**: doklad má řádky v deníku právě tehdy, když je ve stavu 40.

---

## 2. Pohyby řádků (rowOperations)

Pohyb říká, co řádek dokladu *znamená* (prodej služeb, nákup zboží, …).
Je to **business** sémantika — na rozdíl od `row_kind`, který zůstává čistě
strukturální (0 = textový řádek, 1 = běžný řádek).

Pohyby jsou koncept dokladového systému (časem na ně naváže i sklad), proto
žijí v `docs.core`, účetnictví se na ně jen odkazuje.

### Konfigurace

`modules/docs/core/config/rowOperations.jsonc` → cfgItem `docs.core.rowOperations`

```jsonc
{
    // docs.core.rowOperations
    //
    // Pohyby řádků dokladů. Klíč = stabilní stringový identifikátor.
    // docTypes: pro které typy dokladů je pohyb povolený; order určuje
    // pořadí v nabídce UI (vzestupně, první = default pro nový řádek).

    "sale.services": {
        "name": "Sale of services",
        "name:cs": "Prodej služeb",
        "name:en": "Sale of services",
        "docTypes": {
            "invno": {"order": 100}
        }
    },
    "sale.goods": {
        "name": "Sale of goods",
        "name:cs": "Prodej zboží",
        "name:en": "Sale of goods",
        "docTypes": {
            "invno": {"order": 200}
        }
    },
    "purchase.goods": {
        "name": "Purchase of goods",
        "name:cs": "Nákup zboží a materiálu",
        "name:en": "Purchase of goods",
        "docTypes": {
            "invni": {"order": 100}
        }
    },
    "purchase.services": {
        "name": "Purchase of services",
        "name:cs": "Nákup služeb",
        "name:en": "Purchase of services",
        "docTypes": {
            "invni": {"order": 200}
        }
    },
    "purchase.other": {
        "name": "Other purchase",
        "name:cs": "Ostatní nákup",
        "name:en": "Other purchase",
        "docTypes": {
            "invni": {"order": 300}
        }
    },
    "acc.entry": {
        "name": "Accounting entry",
        "name:cs": "Účetní položka",
        "name:en": "Accounting entry",
        "docTypes": {
            "invno": {"order": 900},
            "invni": {"order": 900}
        }
    }
}
```

Oproti starému Shipardu (`e10.docs.operations.json`):

- čitelné stringové klíče místo magických čísel (`sale.services` místo `1010001`)
- žádné `docDir`, `itemType`, `paymentSymbols`, `paymentBalance`, `costOff`,
  `currencyMode` — atributy navázané na saldo, sklad a směr dokladu přijdou
  až s příslušnými úkoly

### Sloupec `operation` v `docs_core_rows`

```jsonc
{
    "id": "operation",
    "name": "Operation",
    "name:cs": "Pohyb",
    "name:en": "Operation",
    "type": "enumString",
    "length": 40,
    "cfgItem": "docs.core.rowOperations",
    "nullable": true
}
```

Pravidla (validace v `DocDocument::validate`, tvrdá — blokuje uložení):

- `row_kind = 1` (běžný řádek): `operation` je **povinný** a musí být povolený
  pro `doc_type` hlavičky
- `row_kind = 0` (textový řádek): `operation` je prázdný

Default pro nový řádek: pohyb s nejnižším `order` pro daný `doc_type`
(doplňuje formulář / `DocRowsForm`).

### Pohyb `acc.entry` — účetní položka

Řádek s pohybem `acc.entry` se účtuje **přímo na účet uvedený na položce**
(`economy_items.accounting_account`, viz sekce 3). Slouží pro řádky typu
bankovní poplatek, úrok, pojistné apod., kde účet určuje předem připravená
položka, ne předpis.

- Tvrdá validace (při uložení dokladu): řádek `acc.entry` musí mít vyplněný
  `item`.
- Měkká kontrola (při účtování): položka musí být `item_type = 2` (Účetní
  položka) a mít vyplněný `accounting_account`. Pokud ne → chybový řádek
  deníku (viz sekce 7.4). Měkce proto, že konfigurace položky se může změnit
  nezávisle na dokladu.

Startovní sadu účetních položek (bankovní poplatky, úroky, kurzové rozdíly,
zaokrouhlení) lze na čerstvém DS **vygenerovat z panelu Nastavení zdroje
dat**, sekce „Volitelné" — dvě seed sady per varianta osnovy, viz
`docs/ds-setup.md` §10 a `modules/economy/items/README.md`.

### Vlajky operací (vlna C/D)

Operace může deklarovat atributy, které řídí formulář řádku (`DocRowsForm`)
a razítkování identity v enginu (`resolveRowIdentity`):

| vlajka | význam |
|---|---|
| `rowPartner: 1` | řádek nese vlastního partnera — engine ho razítkuje z řádku místo z hlavičky, formulář ukáže lookup |
| `rowPaymentId: 1` | řádek nese vlastní platební identitu (`payment_reference` / `specific_symbol` / `constant_symbol` / `due_date`) |
| `rowAccount: "direct"` | účet se zadává přímo na řádku (majetek — analytika per druh) |
| `rowAccount: "item"` | účet přijde z položky typu 2 |
| `rowSide: 1` | strana MD/DAL se zadává na řádku (`acc_side`; protějšek `sideSrc: "row"` předpisu). Přepíná formulář do kontačního layoutu bez položkového bloku |
| `rowSide: 0` | kontační layout, ale **bez volby strany**: stranu nesou fixní kroky předpisu, částky řádků kladné — směr = volba operace (kurzové rozdíly, saldokontní úhrady `payment.*`). Řádek je bez DPH bloku: `vat_code` prázdný → mimo rekapitulaci, do součtu dokladu se přičte |
| `identityRequired: 1` | partner řádku a `payment_reference` jsou **tvrdě** povinné (`DocRowOperationRules`, kódy `partner_required` / `payment_reference_required`) — saldokontní úhrady, bez nich accbal nemá co párovat. Vyžaduje `rowPartner` + `rowPaymentId` |
| `partnerRequired: 1` | jen partner řádku je tvrdě povinný (`partner_required`), VS ne — zálohy v hotovosti (`advance.*`). `identityRequired` ho implikuje |
| `docTypes.{typ}.cashDir: 1 \| 2` | jen u typu se směrem per doklad (`cash`): pohyb je povolený jen při daném `cash_dir` hlavičky (1 příjem, 2 výdej). Chybí = oba směry. Filtruje nabídku formuláře i tvrdou validaci; default nového řádku = nejnižší `order` pro daný směr |

Vlajka `rowSide` chybí = položkový layout (faktury) — i s
`rowAccount`/`rowPartner`/`rowPaymentId` (zálohy, majetek); stranu určuje
krok předpisu. Saldokontní operace bez `rowAccount` mají účet implicitní
z kategorie předpisu — formulář vstup účtu/položky nestaví.

### Pokladní doklady a prodejky (#59 D7)

Pohyby per typ a směr (`docs.core.docTypes`: `cash` má `trade_dir: 0` +
`trade_dir_column: cash_dir`, `cashreg` pevně výstup):

| pohyb | `cash` příjem (`cashDir: 1`) | `cash` výdej (`cashDir: 2`) | `cashreg` |
|---|---|---|---|
| `sale.services`, `sale.goods` | ✓ | — | ✓ |
| `payment.receivable` (Úhrada pohledávky) | ✓ | — | — |
| `purchase.goods`, `purchase.services`, `purchase.other` | — | ✓ | — |
| `payment.payable` (Úhrada závazku) | — | ✓ | — |
| `transfer.in` (Příjem z převodu peněz) | ✓ | — | — |
| `transfer.out` (Výdej pro převod peněz) | — | ✓ | — |
| `sale.advanceDeduction` (Odpočet přijaté zálohy) | ✓ | — | — |
| `advance.received` (Přijatá záloha) | ✓ | — | — |
| `purchase.advanceDeduction` (Odpočet poskytnuté zálohy) | — | ✓ | — |
| `advance.given` (Poskytnutá záloha) | — | ✓ | — |
| `acc.entry` | ✓ | ✓ | ✓ |

Zálohy na pokladním dokladu (#59 Task E): **odpočet zálohy**
(`sale.advanceDeduction` / `purchase.advanceDeduction`) se chová **stejně
jako na faktuře** — položkový záporný řádek s DPH (odpočet zdaněné zálohy
nese daň), `payment_reference` = číslo zálohového dokladu; tytéž vlajky, jen
přibyl `docTypes.cash` se směrem. **Hotovostní záloha** (`advance.received`
příjem / `advance.given` výdej) je kontační bez DPH (`rowSide: 0`,
zdanění zálohy = samostatný daňový doklad, mimo scope), `rowPartner` +
`partnerRequired` (partner povinný — saldo záloh bez dlužníka/věřitele
nedává smysl), `rowPaymentId` bez `identityRequired` (VS nepovinný, staré
doklady ho často nemají). Záporná částka = vrácení zálohy (konvence D9).
Zálohové faktury v novém systému nejsou; úhrada zálohové faktury hotově =
`advance.received`. Párování záloh je accbal fáze 4+.

`transfer.in` / `transfer.out` (#59 Task D) jsou převody peněz — odvod
hotovosti do banky, dotace pokladny z banky, převod mezi pokladnami. Obě
strany převodu jdou přes **261100 Peníze na cestě** (kategorie
`cash.transit`); druhou stranu nese bankovní transakce s operací
`transfer.in/out` (`economy.bank.txOperations`) nebo pokladní doklad druhé
pokladny. Vlajky `rowSide: 0` + `rowPaymentId` **bez** `rowPartner` a bez
`identityRequired`: řádek je bez DPH, bez partnera, `payment_reference`
nepovinný (identifikace protistrany převodu — číslo bankovní transakce,
doklad druhé pokladny — pro budoucí párování 261). Na `cmnbkp` úmyslně
nejsou (ruční opravy pokryje `acc.record` na 261). Saldokontní skupinu
převody nemají — po zaúčtování obou stran má 261100 z převodů nulový
zůstatek, což je zároveň kontrola.

`payment.receivable` / `payment.payable` jsou protějšek bankovních úhrad
routovaných na účet předpisu (`OpenItemLookup`, #69 D3): `rowSide: 0`,
`rowPartner`, `rowPaymentId`, `identityRequired` — partner řádku =
dlužník/věřitel, `payment_reference` = VS / číslo hrazené faktury. Deník pak
nese identitu řádku a `LedgerGenerator` z něj udělá úhradu v saldokontu
(`receivables`/`payables`, bal_side 1) se stejným klíčem jako předpis —
pokladní úhrada clearingem 261200/261300 neprochází, žádné dohledání
nepotřebuje (viz `docs/accbal.md`). Zálohy (`*.advance*`) na pokladní
doklady zatím nepatří.

**Operace a strana saldokonta (#69 D17).** Generátor salda
(`docs/accbal.md` §4.2) dává operaci řádku přednost před pravidly nastavení
saldokont:

| operace | saldo |
|---|---|
| `acc.balanceReceivable`, `acc.fxLossReceivable`, `acc.fxGainReceivable` | skupina s předpisem na MD (Pohledávky); strana řádku proti předpisu = předpis / úhrada, znaménko částky zachováno |
| `acc.balancePayable`, `acc.fxLossPayable`, `acc.fxGainPayable` | skupina s předpisem na DAL (Závazky); dtto |
| `payment.receivable`, `payment.payable`, bankovní `payment.in` / `payment.out` | skupina účtu řádku (první řádek nastavení bez `modify_sign`); strana řádku proti předpisové straně skupiny = předpis / úhrada, znaménko zachováno (D23: bankovní záloha na 324 DAL / 314 MD je předpis, vratka přeplatku na 311 MD předpis +) |
| ostatní (`sale.*`, `purchase.*`, `advance.*`, `transfer.*`, `acc.entry`, `acc.record`, `acc.item`, NULL) | řádky nastavení saldokont vč. sign-pravidel dobropisů |

Mapa je `OperationSides::MAP` (`modules/economy/accbal/src/`); nová
operace bez zařazení = selhání `OperationSidesTest`.

### Kurzové rozdíly saldokonta (vlna D, D12)

Čtyři operace na `cmnbkp`, všechny `rowSide: 0`, `rowPartner: 1`,
`rowPaymentId: 1`, bez `rowAccount`:

| operace | MD | DAL |
|---|---|---|
| `acc.fxLossReceivable` (Kurzová ztráta — pohledávka) | `fx.loss` (563) | `receivables` (311) |
| `acc.fxGainReceivable` (Kurzový zisk — pohledávka) | `receivables` (311) | `fx.gain` (663) |
| `acc.fxLossPayable` (Kurzová ztráta — závazek) | `fx.loss` (563) | `payables` (321) |
| `acc.fxGainPayable` (Kurzový zisk — závazek) | `payables` (321) | `fx.gain` (663) |

Jeden řádek generuje **dva zápisy** deníku — v předpisu jsou na operaci dva
kroky s fixní `side`, oba `src: rows` filtrované `operation`. Kategorie
`fx.loss`/`fx.gain` dohledávají analytiku maskou 563/663 per DS. Identita
řádku (partner, `payment_reference` = číslo párované faktury, staré
`symbol1`) se razítkuje na oba zápisy — saldo strana je párovatelná accbal
FX fází.

---

## 3. Změny ve stávajících tabulkách

### 3.1 `docs_core_rows` — domácí měna

Nové systémové sloupce (plní přepočet v `beforeSave`, viz sekce 8):

| sloupec | typ | popis |
|---|---|---|
| `vat_base_dom` | numeric 15,2 | Základ DPH v domácí měně |
| `vat_amount_dom` | numeric 15,2 | DPH v domácí měně |
| `vat_total_dom` | numeric 15,2 | Celkem s DPH v domácí měně |

### 3.2 `docs_core_heads` — zaokrouhlení v domácí měně

Nový systémový sloupec `total_rounding_dom` (numeric 15,2) — doplňuje řadu
`total_*_dom` (důsledná politika "každá částka v obou měnách").

**Typ období dokladu** — `fiscal_period_type` (enumString 10, nullable,
system, cfgItem `docs.core.fiscalPeriodTypes`, #69 D20): NULL = běžný
doklad, `fiscal_month` = běžný měsíc podle účetního data
(`FiscalMonthLookup::monthIdForDate`); `opening` / `closing` = doklad
otevíracího / uzávěrkového období, `fiscal_month` = jednodenní měsíc
Otevření / Uzavření roku účetního data (`monthIdForYearAndType`, rokem +
typem — datum by trefilo i běžný měsíc). Plní jen import (exchange
`fiscalPeriodType` v import módu), formulář pole nemá. Deník typ období
nenese — saldokonto ho čte z měsíce řádku a uzávěrkové řádky nederivuje
(`docs/accbal.md` §4.2). Zámek měsíce (#55 D27) se otevíracího ani
uzávěrkového měsíce netýká (zamknout lze jen běžný).

### 3.3 Extension `economy.accounting` → `docs_core_heads`

Stav účtování vlastní účetnictví, ne dokladový systém — proto extension
(`modules/economy/accounting/extensions/docs_core_heads.jsonc` — soubory
extensions se jmenují podle cílové tabulky),
stejný princip jako `payment_term_days` z `docs.core` na osobách.

| sloupec | typ | popis |
|---|---|---|
| `accounting_state` | enumInt, default 0, system, cfgItem `economy.accounting.accountingStates` | 0 = neúčtováno, 1 = zaúčtováno, 2 = chyba účtování |
| `accounting_messages` | json, nullable, system | seznam chyb z enginu: `[{"code": "...", "message": "...", "rowId": 123}]` |

Config `modules/economy/accounting/config/accountingStates.jsonc`:

```jsonc
{
    // economy.accounting.accountingStates
    "0": {"name": "Not accounted", "name:cs": "Neúčtováno",      "name:en": "Not accounted"},
    "1": {"name": "Accounted",     "name:cs": "Zaúčtováno",      "name:en": "Accounted"},
    "2": {"name": "Error",         "name:cs": "Chyba účtování",  "name:en": "Accounting error"}
}
```

### 3.4 Extension `economy.accounting` → `economy_items`

`modules/economy/accounting/extensions/economy_items.jsonc`:

```jsonc
{
    "table": "economy_items",
    "columns": [
        {
            "id": "accounting_account",
            "name": "Accounting account",
            "name:cs": "Účet",
            "name:en": "Accounting account",
            "type": "int",
            "nullable": true,
            "reference": "economy_accounting_accounts",
            "group": "classification"
        }
    ]
}
```

Závislost směřuje správně: `economy.accounting` zná items i doklady, ale ani
`economy.items`, ani `docs.core` nezávisí na účetnictví.

Formulář položky: pole viditelné pro `item_type = 2`; picker omezený na
analytické účty (`account_level = 4`) a aktivní záznamy.

---

## 4. Účtovací předpis

Per-country JSONC konfigurace v modulu `economy.accounting`:

`modules/economy/accounting/config/accountingRules.cz.jsonc`
→ cfgItem `economy.accounting.rules.cz`

Výběr předpisu: podle země vlastní firmy (`OwnCompanyResolver`), fallback `cz`.

### Struktura

Tři sekce (zachováno ze starého `acc-default.json`, ale kategorie jsou
sémantické stringy místo čísel účtů):

```
documents   per docType seznam kroků — odkud vzít částku, na kterou stranu,
            jaká kategorie
accounts    kategorie → maska účtu (případně s podmínkou query)
categories  jen názvy kategorií pro dokumentaci/UI
```

### Krok předpisu (step)

| pole | význam |
|---|---|
| `cat` | kategorie → dohledání masky v `accounts`. Nepovinné, pokud je `accountSrc` |
| `accountSrc` | alternativní zdroj účtu mimo kategorie: `"item"` = účet z položky řádku (`acc.entry`, `acc.item`), `"row"` = přímý účet řádku (`acc.record`, `purchase.asset`), `"cashDesk"` (jen `src: head`) = účet pokladny hlavičky (`head.cash_desk` → `economy_codebooks_cash_desks.accounting_account`, 211xxx) |
| `headQuery` | filtr `{sloupec: hodnota}` nad **hlavičkou** pro libovolný `src` — `query` se u `rows`/`vat` kroků hodnotí nad řádkem / rekapitulací, takže bez `headQuery` nejde v jednom bloku rozlišit strany podle `cash_dir`. U `src: head` je ekvivalentní `query` |
| `src` | `"rows"` (řádky dokladu) \| `"vat"` (DPH rekapitulace) \| `"head"` (hlavička) |
| `col` | pro `head`: `"total"` (default) \| `"rounding"`. Pro `rows`/`vat` se nepoužívá (MVP) |
| `operation` / `operations` | filtr pohybu řádku (jen `src: rows`) |
| `side` | 0 = MD (Má dáti), 1 = DAL |
| `sideSrc` | `"row"` = strana z `acc_side` řádku (kontační operace s `rowSide: 1`); jinak platí fixní `side` kroku |
| `partnerSrc` | jen `src: head`: `"balance"` = partner řádku je **osoba pro saldokonto** (`head.partner_balance`, fallback `head.partner`); VS/SS/KS/splatnost zůstávají z hlavičky. Používají saldokontní kroky (`receivables`/`payables`) prodejních typů a `invni` (#72 D3, viz „Osoba pro saldokonto"). Jiná hodnota = chyba předpisu (`LogicException`) |
| `sign` | `"+"` / `"-"` — krok platí jen pro kladnou / zápornou částku |
| `reverseSign` | 1 = otočit znaménko částky (typicky se `sign: "-"`) |
| `query` | obecný filtr `{sloupec: hodnota}` nad zdrojovým záznamem (head/row/recap), volné porovnání. Hodnota-pole je operátorový objekt: `{"$ne": v}` (nerovnost), `{"$in": [v, …]}`; neznámý operátor je chyba předpisu (`LogicException`). Totéž platí pro `query` záznamů `accounts` |
| `text` | text řádku deníku; pokud chybí, použije se default podle `src` |

Zdroje částek (vždy pár domácí měna + měna dokladu):

| `src` | domácí měna | měna dokladu |
|---|---|---|
| `rows` | `vat_base_dom` | `vat_base` |
| `vat` (per řádek rekapitulace) | `tax_dom` | `tax` |
| `head`, `col: total` | `total_amount_dom` | `total_amount` |
| `head`, `col: rounding` | `total_rounding_dom` | `total_rounding` |

Default texty: `rows` → `description` řádku; `vat` → `"DPH {vat_code} {vat_pct}%"`;
`head` → `doc_text`.

Specifika `src: vat`:

- **Reverse charge**: řádek rekapitulace s `is_reverse_pair = 1` (oddanění)
  se účtuje na **opačnou** stranu, než určuje krok (`side` 0 ↔ 1). Primární
  řádek samovyměření nese odpočet a jde na stranu kroku — deník je vyrovnaný,
  obě strany dostanou analytiku svého kódu. Flip je nezávislý na `reverseSign`
  (ten otáčí znaménko částky, typicky u zaokrouhlení).
- **`vat_code_country`**: engine při matchování doplní do kopie záznamu
  rekapitulace odvozené pole — lowercase část `vat_code` před první pomlčkou
  (`cz-110` → `cz`). Nepersistuje se; je dostupné v `query` kroků i záznamů
  `accounts` (využívá ho fallback masky 343).

### Předpis CZ pro MVP (invno, invni)

```jsonc
{
    // economy.accounting.rules.cz
    //
    // Účtovací předpis — Česká republika, podvojné účetnictví.

    "categories": {
        "receivables":      {"name:cs": "Pohledávky"},
        "payables":         {"name:cs": "Závazky"},
        "vat":              {"name:cs": "DPH"},
        "revenue":          {"name:cs": "Výnosy"},
        "costs":            {"name:cs": "Náklady"},
        "rounding.cost":    {"name:cs": "Zaokrouhlení — náklad"},
        "rounding.revenue": {"name:cs": "Zaokrouhlení — výnos"}
    },

    "accounts": [
        {"cat": "revenue", "accountMask": "602", "query": {"operation": "sale.services"}},
        {"cat": "revenue", "accountMask": "604", "query": {"operation": "sale.goods"}},

        {"cat": "costs", "accountMask": "504", "query": {"operation": "purchase.goods"}},
        {"cat": "costs", "accountMask": "518", "query": {"operation": "purchase.services"}},
        {"cat": "costs", "accountMask": "548", "query": {"operation": "purchase.other"}},

        {"cat": "receivables",      "accountMask": "311"},
        {"cat": "payables",         "accountMask": "321"},

        // DPH analytiky per kód — 55 mapovacích řádků, konvence 343{NNN}
        // (generováno z vat-cz.jsonc, úplnost hlídá VatAnalyticsCompletenessTest)
        {"cat": "vat", "accountMask": "343110", "query": {"vat_code": "cz-110"}},
        {"cat": "vat", "accountMask": "343111", "query": {"vat_code": "cz-111"}},
        // ... všechny kódy s možnou nenulovou daní ...

        // fallback jen pro tuzemsko — zahraniční kód bez mapování selže hlasitě
        {"cat": "vat", "accountMask": "343", "query": {"vat_code_country": "cz"}},

        {"cat": "rounding.cost",    "accountMask": "548"},
        {"cat": "rounding.revenue", "accountMask": "648"}
    ],

    "documents": [
        {"docType": "invno",
            "accounting": [
                {"cat": "revenue", "src": "rows", "side": 1,
                    "operations": ["sale.services", "sale.goods"]},
                {"accountSrc": "item", "src": "rows", "side": 1,
                    "operation": "acc.entry"},
                {"cat": "vat", "src": "vat", "side": 1},
                {"cat": "rounding.revenue", "src": "head", "col": "rounding",
                    "side": 1, "sign": "+", "text": "Zaokrouhlení dokladu"},
                {"cat": "rounding.cost", "src": "head", "col": "rounding",
                    "side": 0, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
                {"cat": "receivables", "src": "head", "col": "total", "side": 0}
            ]
        },
        {"docType": "invni",
            "accounting": [
                {"cat": "costs", "src": "rows", "side": 0,
                    "operations": ["purchase.goods", "purchase.services", "purchase.other"]},
                {"accountSrc": "item", "src": "rows", "side": 0,
                    "operation": "acc.entry"},
                {"cat": "vat", "src": "vat", "side": 0},
                {"cat": "rounding.cost", "src": "head", "col": "rounding",
                    "side": 0, "sign": "+", "text": "Zaokrouhlení dokladu"},
                {"cat": "rounding.revenue", "src": "head", "col": "rounding",
                    "side": 1, "reverseSign": 1, "sign": "-", "text": "Zaokrouhlení dokladu"},
                {"cat": "payables", "src": "head", "col": "total", "side": 1}
            ]
        }
    ]
}
```

Kontrolní příklad — faktura vydaná 1 000 Kč služby + 21 % DPH (cz-120),
zaokrouhleno na 1 210 Kč (rounding 0):

```
602xxx  DAL  1 000,00   (rows, sale.services)
343120  DAL    210,00   (vat — analytika dle vat_code)
311xxx  MD   1 210,00   (head, total)
                         MD 1 210 = DAL 1 210 ✓
```

Kontrolní příklad — faktura přijatá, EU pořízení služeb 1 000 Kč
(cz-217, 21 %, samovyměření):

```
518xxx  MD   1 000,00   (rows, purchase.services)
343217  MD     210,00   (vat — odpočet, primární řádek rekapitulace)
343207  DAL    210,00   (vat — oddanění, pár na opačné straně)
321xxx  DAL  1 000,00   (head, total — jen základ, daň se neplatí)
                         MD 1 210 = DAL 1 210 ✓
```

### Předpis pokladny — `cash`, `cashreg`, hotově placené faktury (#59 D8)

Protistrana hotovostních dokladů se řídí `payment_method` hlavičky:
**0 Hotovost** → účet pokladny (`accountSrc: "cashDesk"`, 211xxx per
pokladna); **2 Kartou**, **3 Dobírkou**, **5 Platební bránou** (a u
prodejky i **1 Převodem**) → kategorie `receivables` (311) **za osobou pro
saldokonto** (`partnerSrc: "balance"`, #72 D1/D3): protistrana terminálu,
brány nebo dopravce, u převodu partner hlavičky. VS = `payment_reference`
hlavičky (= číslo dokladu). Výdajový PD kartou = `payables` (321) za
plátcem. Karty tranzit **nemají**: kategorie `card.transit` a maska
`261400` z předpisu zmizely, účet v osnově zůstává (nic ho neúčtuje).
Pokladní doklad převodem nemá; prodejka bez plátce u karty / dobírky /
brány neprojde (`partner_balance_required`, `CashDeskDocumentBase`).
Kategorie `cash` neexistuje — `accountSrc: cashDesk` `accounts[]` obchází.

**Tranzitní účty jsou infrastruktura** (#59 Task E): `261` (syntetika)
a `261100` zajišťuje `TransitAccountsProvisioner`
(`modules/economy/accounting/src/`) z `ds-upgrade` **bezpodmínečně**, i pod
`skipProvisioning` — zrcadlo `ClearingInfrastructureProvisioner` pro
261200/261300. Migrovaný rozvrh (staré `261001/261002`) by jinak dal
každému převodu chybový řádek `261???`. Idempotence per `number`,
existující (i přejmenovaný) účet se nepřepisuje; seedy 261100 (i 261400
pro historii) zůstávají pro nové DS, drift proti provisioneru hlídá
`CashAccountingRulesTest`.

Zálohy (Task E): hotovostní záloha `advance.received` → DAL `advances.received`
(řádek bez DPH → `vat_amount 0` → maska `324`, ne `3249`), `advance.given` →
MD `advances.given` (`314`); odpočet `sale/purchase.advanceDeduction` má v
bloku `cash` tytéž kroky jako na faktuře (`reverseSign`, záporný řádek s DPH
→ `3249`/`3149`, daň z rekapitulace). Záporná hotovostní záloha (vrácení)
zůstává na stranách kroku se zápornou částkou (D9) — saldo účtu odpovídá
otočenému zápisu.

Převody peněz (Task D) mají v bloku `cash` per směr jeden řádkový krok
kategorie `cash.transit` (maska `261100`): příjem `transfer.in` DAL 261100
(MD pokladna z head kroku), výdej `transfer.out` MD 261100 (DAL pokladna).
Řádek má nulovou DPH, kroky `src: vat` ho nezasáhnou. Druhou stranu
převodu účtuje bankovní mikroengine z operace `transfer.in/out`
(kategorie `cash.transit`, tedy tatáž maska) nebo pokladní doklad druhé
pokladny — viz `docs/bank.md` §6.2.

**Proč už ne 261400.** Převody (`261100`) mají po zaúčtování obou stran
nulový zůstatek — stejná kontrolní logika jako clearing `261200/261300`.
Karty tranzit původně měly (`card.transit` 261400, nenulový zůstatek do
připsání bankou), ale ten model se neuměl spárovat s bankou ani se
starým systémem: tam je tržba kartou **pohledávka 311 za terminálem**
s VS = číslo dokladu, kterou uzavře „Vyúčtování úhrad" a banka pak platí
315 (#72 D1, zrušeno 2026-09-15). Úplnost seedů vůči maskám `261xxx`
hlídá `CashAccountingRulesTest::testEvery261MaskOfRulesHasAccountInBothSeedCharts`.

Blok `cash` je jeden (engine bere první blok per docType): příjmová část
(`headQuery: {cash_dir: 1}`, jako vydaná faktura, strany DAL/MD) a výdajová
(`headQuery: {cash_dir: 2}`, jako přijatá faktura). Head kroky protistrany
mají `query: {cash_dir, payment_method}`. Blok `cashreg` = příjmová část bez
`headQuery` a bez `payment.*`. U `invno`/`invni` dostal saldo krok
`query: {payment_method: {"$ne": 0}}` a přibyl krok
`{accountSrc: "cashDesk", src: "head", col: "total", query: {payment_method: 0}}`
— hotově placená faktura účtuje celkem na pokladnu místo 311/321
(nevzniká otevřená položka salda, staré `totalCash`). Faktura s Hotovostí
bez `cash_desk` → chybový řádek `211???` + alert, uživatel doplní pokladnu
a přeúčtuje. Konzistenci předpisu s `rowOperations` a seed rozvrhy hlídá
`CashAccountingRulesTest`.

Kontrolní příklady (`tests/Integration/Accounting/CashDocsAccountingTest`):

```
Příjmový PD, hotově, prodej služby 1 000 + 21 % (cz-120):
    602xxx DAL 1 000   343120 DAL 210   211xxx MD 1 210
Příjmový PD, kartou, úhrada FVB 1 210 (payment.receivable, VS = číslo FVB):
    311xxx DAL 1 210 (zákazník + VS FVB z řádku)
    311xxx MD  1 210 (protistrana terminálu, VS = číslo PD — partnerSrc balance)
Prodejka kartou 1 000 + 21 % (terminál pokladny s protistranou):
    604xxx DAL 1 000   343120 DAL 210   311xxx MD 1 210 (protistrana terminálu, VS = číslo prodejky)
Prodejka na dobírku (doprava s dopravcem):  dtto, 311 MD za dopravcem
Prodejka bránou (5):                         dtto, 311 MD za protistranou brány
PD kartou na DS bez terminálů:               311 MD za partnerem hlavičky (fallback)
Výdajový PD, hotově, nákup materiálu 500 + 21 %:
    504xxx MD 500   343120 MD 105   211xxx DAL 605
Prodejka hotově, zboží 1 000 + 21 %:
    604xxx DAL 1 000   343120 DAL 210   211xxx MD 1 210
Prodejka — vratka (záporné řádky, D9): tytéž účty, záporné částky na obou
    stranách, deník vyrovnaný
FVB s Hotovostí 1 210:  602/343 DAL   211xxx MD 1 210   (žádný 311)
```

Zálohy na pokladně (`tests/Integration/Accounting/CashAdvancesAccountingTest`):

```
Příjmový PD: prodej 10 000 + 21 % a odpočet přijaté zálohy −4 000 + 21 % (sale.advanceDeduction):
    602xxx DAL 10 000   343120 DAL 1 260   3249xx MD 4 000   211xxx MD 7 260
Příjmový PD: přijatá záloha 5 000 (advance.received, partner, VS):
    324xxx DAL 5 000 (partner + payment_reference z řádku)   211xxx MD 5 000
Příjmový PD: přijatá záloha −5 000 (vrácení, D9):
    324xxx DAL −5 000   211xxx MD −5 000   (saldo = 324 MD 5 000 / 211 DAL 5 000)
Výdajový PD: poskytnutá záloha 3 000 (advance.given):
    314xxx MD 3 000   211xxx DAL 3 000
Výdajový PD: nákup 2 000 + 21 % a odpočet poskytnuté zálohy −1 000 + 21 %:
    504xxx MD 2 000   343120 MD 210   3149xx DAL 1 000   211xxx DAL 1 210
```

Převody peněz (`tests/Integration/Accounting/CashTransferAccountingTest`):

```
Odvod hotovosti do banky 20 000:
  výdajový PD, transfer.out:      261100 MD 20 000 / 211xxx DAL 20 000
  bankovní transakce transfer.in: 221xxx MD 20 000 / 261100 DAL 20 000
  → 261100: 0
Dotace pokladny z banky 5 000:
  bankovní transakce transfer.out: 261100 MD 5 000 / 221xxx DAL 5 000
  příjmový PD, transfer.in:        211xxx MD 5 000 / 261100 DAL 5 000
  → 261100: 0
Převod mezi pokladnami A → B 3 000:
  výdajový PD na A (transfer.out) + příjmový PD na B (transfer.in) → 261100: 0
Prodejka kartou 1 000 + 21 %:  604/343 DAL   311 MD 1 210 za terminálem   (261100 i 261400 bez řádku)
```

### Osoba pro saldokonto — plátce (#72)

Pohledávka z prodejního dokladu placeného kartou, bránou nebo na dobírku
vzniká za **plátcem** (`docs_core_heads.partner_balance`, formulář
„Plátce"), ne za zákazníkem z hlavičky a ne na tranzitu: terminál / brána
/ dopravce je v saldokontu obyčejný dlužník s VS = číslo dokladu, klíč
případu z #69 (partner + VS) se nemění. Model starého Shipardu
(`personBalance`); bez něj se DS `btpg-p` neporovná (M2).

**Odvození** (`modules/docs/core/src/PartnerBalanceResolver.php`, volá
`DocDocument::validate()` i `beforeSave()` po denormalizaci z řady —
validate běží dřív a musí vidět odvozenou hodnotu; formulář ho volá pro
živý náhled v `recalculate`) — jen pro **prodejní směr** (`invno`,
`cashreg`, `cash` příjem), pořadí:

1. `payment_method` 2 Kartou / 5 Bránou → terminál / brána hlavičky
   (`payment_terminal`, číselník `economy_codebooks_payment_terminals`);
   neodpovídá-li (jiný druh, u karty s pokladnou jiná pokladna) nebo
   chybí, doplní se default (karta: default terminál pokladny hlavičky, na
   faktuře bez pokladny default mezi všemi; brána: default brána) →
   `partner_balance = terminal.partner`;
2. 3 Dobírkou + `transport` (číselník `economy_codebooks_transports`)
   s protistranou → dopravce;
3. jinak, není-li `partner_balance_manual` → `= partner`.

Kroky 1–2 přepisují i ruční hodnotu. `invni` a výdej mají jen krok 3
(ruční plátce). Import (`_importNumber`) s explicitním ručním plátcem
(`balanceParty` kanonického formátu → `partner_balance_manual = 1`) se
respektuje — terminály se mapují až v navazujícím importním tasku.
Validace: brána bez vybrané brány = `payment_terminal_required`; prodejka
/ příjmový PD kartou, dobírkou, bránou bez plátce = `partner_balance_required`
(DS bez terminálů projde, jakmile má doklad partnera).

**Účtování**: atribut kroku `partnerSrc: "balance"` (tabulka kroků výše)
jen na saldokontním hlavičkovém kroku — výnosy, DPH i zaokrouhlení
zůstávají za partnerem. Engine vezme `partner_balance ?? partner`, ostatní
identita (VS, SS, KS, splatnost) z hlavičky. U PD kartou s úhradou FVB tak
vzniknou dva řádky 311 s různou identitou (DAL zákazník + VS FVB, MD
terminál + VS PD) — grouping key deníku partnera obsahuje, nesloučí se.
Vyúčtování úhrad od brány / terminálu (311 DAL per doklad → 315 MD per
dávka) a jeho párování s bankou (315 v Pohledávkách, #72 D6) viz
`docs/accbal.md`; tvorba vyúčtování v novém Shipardu je mimo scope.

### Zálohová faktura vydaná — podrozvaha (#79 D2)

`invpo` není daňový doklad a nevstupuje do rozvahy ani výsledovky:
potvrzená proforma se účtuje **jen na podrozvahu celkovou částkou
hlavičky** — `756100 MD / 799100 DAL` (kategorie `proformas.out` s maskou
`756`, `offbalance.contra` s maskou `799`). Řádky, rekapitulace DPH,
zaokrouhlení ani pohledávka 311 se neúčtují; deník je vyrovnaný
z principu (obě strany tatáž částka). Identita obou řádků je z hlavičky
(partner, VS, SS, KS, splatnost) — z 756 MD vzniká předpis v saldokontní
skupině **Zálohové faktury vydané** (`docs/accbal.md` §3.1, §5.1).

```jsonc
{"docType": "invpo",
    "accounting": [
        {"cat": "proformas.out",     "src": "head", "col": "total", "side": 0, "text": "Zálohová faktura vydaná"},
        {"cat": "offbalance.contra", "src": "head", "col": "total", "side": 1, "text": "Zálohová faktura vydaná"}
    ]
}
```

Účty třídy 7 mají povahu **6 Podrozvaha** (skupiny 75–79, v NPO osnově
i 97–99); `799100` je společný evidenční protiúčet pro budoucí
podrozvahové evidence. Oba účty i syntetiky 75/756/79/799 zajišťuje
`OffBalanceAccountsProvisioner` z `ds-upgrade` **bezpodmínečně** (i pod
`skipProvisioning` — migrovaný rozvrh má jen syntetiky 75 a 79) a zároveň
jednorázově opraví povahu 0 → 6 na účtech 75–79 (jiné hodnoty nechá).
Rozvaha čte třídy 0–4, výsledovka 5–6, takže zaúčtovaná proforma žádný
report nezmění (`ProformaAccountingTest`).

Úhrada proformy se na 756 **nikdy neúčtuje**: bankovní engine ji přes
`OpenItem::paymentCategory` položí na přijatou zálohu 324 (§7.1,
`docs/bank.md` §6.1), pokladní doklad s `advance.received` účtuje 324
z předpisu. Uzavírací pár `799100 MD / 756100 DAL` do téhož deníku
doplní contributor deníku `CaseClosureContributor` modulu saldokonta
(#79 D3b/c, `journalContributors` v §7.1, `docs/accbal.md` §5.8) —
do výše zbytku proformy, v cizí měně kurzem proformy.

---

## 5. Dohledávání účtů

Pořadí:

1. **`accountSrc: "item"`** — účet přímo z `economy_items.accounting_account`
   položky řádku (FK). Maska se nepoužívá.
1b. **`accountSrc: "cashDesk"`** (head kroky) — `head.cash_desk` →
   `economy_codebooks_cash_desks.accounting_account` → účet rozvrhu
   (`docState IN LINKABLE_STATES`, archivní 70 se dohledá). Chybějící
   pokladna nebo účet → chybový řádek `211???`, kód `cash_desk_account_missing`
   (dvě hlášky: „doklad nemá pokladnu" vs. „pokladna nemá účet").
2. **`cat`** — v sekci `accounts` se najde **první** záznam se shodnou `cat`
   a vyhovující `query` (porovnání rovností nad zdrojovým záznamem — u
   `src: rows` nad řádkem, u `src: head` nad hlavičkou). Výsledkem je
   `accountMask`.
3. **Maska → účtový rozvrh** (`economy_accounting_accounts`):

```sql
SELECT id, number FROM economy_accounting_accounts
WHERE number LIKE '{mask}%'
  AND account_level = 4                         -- jen analytické účty
  AND docState IN (10, 40, 70, 80)              -- linkable (vyloučen jen smazaný 90)
  AND (valid_from IS NULL OR valid_from <= :accounting_date)
  AND (valid_to   IS NULL OR valid_to   >= :accounting_date)
ORDER BY docState = 70, number                  -- aktivní před archivem
LIMIT 1
```

Deterministické: první účet podle čísla vzestupně. Praktický důsledek shodný
se starým systémem: `602` najde `602000` dřív než `602100`.

Archivní účet (70) je **jen fallback**: jakýkoliv aktivní účet vyhovující
masce vyhrává (i číselně vyšší), archiv se dohledá až když aktivní neexistuje
— historické doklady na zrušené účty (např. úvěrové 221xxx) se tak zaúčtují,
ale běžnému účtování archiv výsledek masky nikdy nezmění. Stejná konvence
LINKABLE_STATES platí pro přímé účty řádků/položek (`acc.record`, `acc.item`
— lookup podle id, smazaný 90 → chybový řádek) i pro import dokladů
(`AccountResolver` v core.exchange, lookup podle čísla). UI výběr účtů
(`AccountsLookup`) zůstává aktivní-only.

### Nenalezený účet

Řádek deníku se přesto zapíše:

- `account = NULL`
- `account_number` = maska doplněná `?` na 6 znaků (např. `504???`)
- `is_error = 1`
- do `accounting_messages` hlavičky přibude `{code: "account_not_found",
  message: "Účet nenalezen pro masku 504", rowId: ...}`
- `accounting_state = 2`

Pokud se nenajde ani maska (žádný záznam `accounts` dané kategorie
nevyhovuje query — typicky zahraniční vat kód bez mapování), zapíše se
chybový řádek se syntetikou kategorie doplněnou `?` (`343???`) — hint je
display-only, bere se z poslední masky dané kategorie.

Reporty (obratová předvaha apod.) chybové řádky snadno vyloučí/zvýrazní
podle `is_error`.

### Konvence DPH analytik a OSS

- **Tuzemsko**: `343{NNN}`, kde `NNN` = číselná část vat kódu
  (`cz-110` → `343110`). Mapování žije v sekci `accounts` předpisu (query
  na `vat_code`), **ne** jako atribut kódu ve `world.vat` — legislativní
  vrstva zůstává bez účetních konvencí. Řádek má každý kód, který může
  vyprodukovat nenulovou daň (nenulové `vatPercents` vč. historické 3xx
  řady kvůli zpětně datovaným a migrovaným dokladům + cíle
  `reverseVatCode`); úplnost vůči číselníku i seed rozvrhům hlídá
  `VatAnalyticsCompletenessTest`.
- **Zahraničí (budoucí OSS)**: `343{CC}{NNN}` (`de-120` → `343DE120`).
  Zahraniční účty se **neprovisionují** do každého DS — vzniknou on-demand
  s budoucím OSS úkolem (enablement per stát = doprovisionování účtů +
  per-datasource vrstva mapování; pořadí resolution pak: per-DS mapování →
  předpis → fallback). Alfanumerická čísla účtů projdou celým stackem:
  `AccountDocument::validate` povoluje analytiku `3 číslice + 1–9 číslic
  či písmen`, `deriveStructure` je délková (level 4, g3 `343`), sloupce
  `number` / `account_number` jsou varchar(12).
- **Fallback** `{"cat": "vat", "accountMask": "343"}` je omezen query na
  `vat_code_country = cz`: neznámý tuzemský kód spadne na syntetiku 343,
  zahraniční kód bez mapování skončí hlasitě (`account_not_found`, řádek
  `343???`, `is_error`, alert) — žádné tiché smíchání cizí DPH s tuzemskou.
  Hodnota `cz` je správně, dokud existuje jen `rules.cz`; každý budoucí
  country předpis dostane vlastní fallback se svou zemí.
- **Krácené kódy** (118/119/341/342, koeficient odpočtu): doklad účtuje
  plnou daň na vlastní analytiku; neuplatnitelnou část (krácený sloupec
  ř. 46 − odpočet ř. 52) přeúčtuje na náklad `vat.nondeductible` (548) až
  účetní doklad přiznání.
- **Uzavření analytik 343 přiznáním** (#55 F4b): podané přiznání dostane
  účetní doklad `cmnbkp` (`economy_vat_filings.acc_document`), který per
  kód DPH vynuluje analytiku proti saldu — kategorie `vat.payable` (343801,
  odvod, DAL) / `vat.receivable` (343802, nadměrný odpočet, MD), partner =
  správce daně registrace, VS/SS/KS + splatnost pro párování platby FÚ.
  Opravné a dodatečné podání účtují jen rozdíl proti kumulativnímu
  podanému stavu; zbytek ze zaokrouhlení na Kč jde na `rounding.cost` /
  `rounding.revenue`. Řádky dokladu staví `Economy\Vat\Accounting
  \VatReturnAccountingBuilder` ze snapshotu podání (ne z deníku), deník
  vznikne standardně uzavřením dokladu. Součet deníku na `343*` (mimo
  801/802) přes doklady instance a doklady jejích podání je pak nula —
  `ClosedPeriodBalanceService`. Detaily: README modulu `economy.vat` →
  Zaúčtování přiznání.

---

## 6. Účetní deník — `economy_accounting_journal`

`modules/economy/accounting/tables/economy_accounting_journal.jsonc`,
**tableId 413**, `hideFromNavigation` ne (deník má vlastní viewer), ale
**bez docStates** — řádky se nikdy needitují, jen mažou a generují celé.

| sloupec | typ | popis |
|---|---|---|
| `id` | int, PK, autoincrement | řazení deníku = řazení podle `id` |
| `doc_head` | int, FK `docs_core_heads`, not null | zdrojový doklad |
| `doc_type` | enumString 20, denorm | typ dokladu |
| `doc_number` | varchar 40, denorm | číslo dokladu |
| `accounting_date` | date, not null | účetní datum (z hlavičky) |
| `fiscal_year` | int, FK `economy_codebooks_fiscal_years` | denorm z hlavičky |
| `fiscal_month` | int, FK `economy_codebooks_fiscal_months` | denorm z hlavičky |
| `account` | int, FK `economy_accounting_accounts`, **nullable** | NULL = nedohledaný účet |
| `account_number` | varchar 12, not null | denorm číslo účtu / chybová maska |
| `is_error` | boolean, default 0 | řádek s nedohledaným účtem |
| `operation` | enumString 40, nullable, cfgItem `docs.core.rowOperations` | pohyb zdrojového řádku (head/vat kroky: NULL) |
| `money_dr` | numeric 15,2, default 0 | MD — **domácí měna** |
| `money_cr` | numeric 15,2, default 0 | DAL — domácí měna |
| `currency` | enumString 3, cfgItem `world.base.currencies` | měna dokladu |
| `money_dr_cur` | numeric 15,2, default 0 | MD — měna dokladu |
| `money_cr_cur` | numeric 15,2, default 0 | DAL — měna dokladu |
| `partner` | int, FK `base_persons_persons`, nullable | partner z hlavičky |
| `text` | varchar 200 | text řádku |
| `payment_reference` | varchar 35, nullable | variabilní symbol (ze zdroje) |
| `specific_symbol` | varchar 20, nullable | specifický symbol (ze zdroje) |
| `constant_symbol` | varchar 10, nullable | konstantní symbol (ze zdroje) |
| `due_date` | date, nullable | splatnost z hlavičky dokladu (bankovní transakce: NULL) |

Indexy: (`doc_head`), (`source_kind`), (`account_number`, `accounting_date`),
(`fiscal_year`, `fiscal_month`), (`partner`), (`payment_reference`).

Poznámky:

- Jednostranné řádky: vyplněno je vždy právě `money_dr` XOR `money_cr`
  (a párový `_cur` sloupec).
- U dokladu v domácí měně jsou `*_cur` shodné s domácími — **plní se vždy**,
  reporty pak nemusí rozlišovat.
- **Platební identita** (`payment_reference` / `specific_symbol` /
  `constant_symbol` / `due_date`): orazítkovaná ze zdroje (hlavička dokladu /
  bankovní transakce), aby saldo (accbal) četlo výhradně deník bez joinu na
  zdroj a šel filtr deníku za VS. Symboly v konvenci dokladů (varchar 35 pro
  RF/EndToEndId). Tím se **částečně obrací rozhodnutí #10** (viz Log rozhodnutí).

---

## 7. Engine a lifecycle

### 7.1 Obecný mechanismus — documentEventHandlers

Účetnictví se potřebuje zaháknout na změny stavu dokladu, ale `docs.core`
nesmí záviset na `economy.accounting`. Zavádí se obecný mechanismus
(použitelný později i pro sklad, saldo, …):

`module.jsonc` (libovolného modulu):

```jsonc
"documentEventHandlers": [
    {
        "table": "docs_core_heads",
        "class": "Shipard\\Module\\Economy\\Accounting\\DocsHeadsEventHandler",
        "events": ["stateChanged", "beforeDelete"]
    }
]
```

Interface `Shipard\Core\Document\DocumentEventHandler`:

```php
interface DocumentEventHandler
{
    /** Po commitu uložení, pokud se změnil docState. */
    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void;

    /** Před smazáním dokumentu (uvnitř transakce, před child delete). */
    public function onBeforeDelete(string $tableId, array $data): void;

    /** V save transakci před zápisem hlavičky, smí mutovat $data (economy.vat). */
    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void;

    /** Po commitu každého uložení. */
    public function onAfterSave(string $tableId, array $data, ?array $originalData): void;
}
```

Dispatch zajišťuje `TableGateway`: `beforeSave` po `Document::beforeSave`
uvnitř transakce, `afterSave` a `stateChanged` po `Document::afterSave`,
`beforeDelete` po `Document::beforeDelete`. Úplná sémantika: `docs/modules.md`
→ Pole `documentEventHandlers`. Registrace se kompiluje z
`module.jsonc` do cfg (analogie `documentClasses`).

#### Hooky účtování pro cizí moduly — `journalEventHandlers`, `openItemLookup`

Tentýž princip (deklarace v core, implementace v modulu, registrace v
`module.jsonc`, účtování na modulu nezávisí) mají dva další body:

- **`journalEventHandlers: [{class, events}]`** — rozhraní
  `Shipard\Core\Document\JournalEventHandler`, událost `journalWritten
  (sourceKind, sourceId)`. Vysílají ji **oba enginy** (`AccountingEngine`,
  `BankTransactionAccountingEngine`) po commitu každého (pře)zápisu i
  vymazání deníku zdroje; dispatcher `JournalEventDispatcher` volá handlery
  v pořadí registrace, výjimku zaloguje a spolkne, a injektuje handlerům
  sám sebe (handler smí sám účtovat, re-entrantní dispatch je bezpečný).
  Konzument: `economy.accbal` (`JournalLedgerHandler` re-derivuje saldo
  pohyby, `ClearingRerouteHandler` přeúčtuje clearingové úhrady po vzniku
  předpisu — `docs/accbal.md` §4.1, rozhodnutí #19).
- **`openItemLookup: "FQCN"`** (jeden poskytovatel per DS) — rozhraní
  `Shipard\Core\Accounting\OpenItemLookup::findOpenRequest(partner, VS,
  SS, měna, směr, období, [vyloučený zdroj]) → ?OpenItem{balance,
  accountNumber, residual}` — klíč případu vč. účetního období (#69 D11,
  `null` = bez klíče → miss). Bankovní engine podle něj rozhoduje účet úhrady (účet předpisu
  vs. clearing, `docs/bank.md` §6.1); DS bez poskytovatele má
  `NullOpenItemLookup`. Loader `OpenItemLookupLoader`; do handlerů ho vkládá
  `DocumentEventDispatcher` (`AbstractDocumentEventHandler::setOpenItems`),
  controllerům `public/index.php`. Implementace `LedgerOpenItemLookup`
  (`economy.accbal`) nad `economy_accbal_ledger`. `OpenItem::paymentCategory`
  (#79 D3a): skupina saldokonta s `payment_category` (Zálohové faktury
  vydané → `advances.received`) říká enginu, ať úhradu položí na masku
  kategorie předpisu (324), ne na `accountNumber` (756 je podrozvaha).
- **`journalContributors: ["FQCN", …]`** (#79 D3b) — rozhraní
  `Shipard\Core\Accounting\JournalContributor::contribute(JournalSourceContext,
  list<JournalLineView>) → list<JournalLineRequest>`. **Oba enginy** po
  sestavení vlastních řádků (dokladový po seskupení, bankovní po dvou
  řádcích) předají contributorům kontext zdroje (`sourceKind`, `sourceId`,
  účetní datum, fiskální rok, měna) a pohledy na řádky bez chyby (strana,
  účet, operace, partner, VS, SS, částky; bankovní engine identitu doplní
  z transakce) a jejich požadavky doplní jako další řádky: účet buď
  `category` (první maska kategorie v sekci `accounts`, bez `query`) nebo
  přesné `accountNumber` (ověřené v rozvrhu k datu), identita
  z požadavku, `operation` NULL, text z požadavku; pak teprve kontrola
  vyrovnanosti a zápis v jedné transakci. Nedohledaný účet je chybový
  řádek jako u masky (`account_not_found`); nevyrovnané požadavky skončí
  jako `unbalanced`; **výjimka contributoru** se zaloguje a spolkne — deník
  se zapíše bez příspěvku a přibude zpráva `contributor_failed` úrovně
  `warning` (stav účtování zůstává 1, viewer ji vypíše v tónu
  „k pozornosti“). Bankovní engine požadavek s jinou identitou, než má
  transakce, odmítne `LogicException` (chyba kontraktu, ne dat). Víc
  modulů smí přispívat, pořadí = pořadí resolvovaných modulů × pořadí
  pole; prázdná sada = chování beze změny. Sdílená logika obou enginů:
  `AccountingRules` (předpis + první maska kategorie) a
  `JournalContributions` (volání sady, převod požadavku na účet)
  v `economy.accounting`. Loader `JournalContributorLoader` →
  `JournalContributorSet`; sadu injektují oba dispatchery
  (`AbstractDocumentEventHandler::setJournalContributors`,
  `AbstractJournalEventHandler::setJournalContributors`), loadery si ji
  při nepředání sestaví z modulů, controllery a CLI (`doc-reaccount`,
  `accbal-match`) ji předávají enginu explicitně. První konzument:
  `economy.accbal` → `CaseClosureContributor` (uzavření zálohové faktury
  úhradou, `docs/accbal.md` §5.8).

### 7.2 Lifecycle účtování

Handler `DocsHeadsEventHandler` (economy.accounting):

| událost | akce |
|---|---|
| přechod **do** 40 (V pořádku) | smazat případné staré řádky deníku dokladu → spustit `AccountingEngine` → zapsat deník + `accounting_state`/`accounting_messages` |
| přechod **ze** 40 (→ 80 V opravě, → 30 Storno, → 90 Smazáno) | smazat řádky deníku, `accounting_state = 0`, `accounting_messages = NULL` |
| `beforeDelete` | smazat řádky deníku (jinak FK blokuje delete) |

Storno (30) = doklad účetně neexistuje. Generování je idempotentní
(delete + insert), takže opakovaný průchod 40 → 80 → 40 je bezpečný.

### 7.3 Algoritmus AccountingEngine

`Shipard\Module\Economy\Accounting\AccountingEngine`
(`modules/economy/accounting/src/AccountingEngine.php`):

```
1. Najdi předpis: rules.{country} → documents[docType]. Nenalezen → chyba (state 2).
2. Ověř fiscal_year / fiscal_month na hlavičce. Chybí → chyba (state 2).
3. Pro každý krok předpisu:
   a. src=rows: iteruj řádky dokladu (row_kind=1), aplikuj filtry
      (operation/operations, query, sign) → částka z vat_base_dom / vat_base
   b. src=vat:  iteruj vat_recap → tax_dom / tax; záznam se obohatí o
      vat_code_country; pár is_reverse_pair=1 jde na opačnou stranu kroku
   c. src=head: jedna částka podle col
   d. dohledej účet (sekce 5), sestav řádek deníku
   e. money == 0 → řádek se přeskakuje
4. Seskupení: klíč (side, account_number, partner, operation + platební
   identita) — shodné řádky se sčítají (domácí i cur částky), text
   z prvního řádku skupiny. Prázdný výsledek → chyba "empty_journal".
5. Contributoři deníku (§7.1, #79 D3b): kontext zdroje + pohledy na
   seskupené řádky bez chyby → požadavky → řádky (účet dle kategorie /
   přesného čísla, identita z požadavku, operation NULL) → seskupení znovu.
   Prázdná sada = krok se přeskočí; výjimka contributoru = varování
   contributor_failed, deník bez příspěvku.
6. Kontroly:
   - round(Σ money_dr, 2) == round(Σ money_cr, 2), jinak chyba "unbalanced"
   - existují is_error řádky → state 2
7. Zápis: DELETE + INSERT řádků deníku, update accounting_state (1 ok / 2 chyba)
   a accounting_messages na hlavičce. Vše v transakci.
```

Chybové kódy (`accounting_messages[].code`): `rules_not_found`,
`fiscal_period_missing`, `account_not_found`, `item_account_missing`,
`row_account_missing`, `cash_desk_account_missing` (hotovostní doklad bez
pokladny nebo pokladna bez účtu 211xxx — `accountSrc: cashDesk`),
`unbalanced`, `empty_journal`; jediná zpráva úrovně `warning`
(`level: "warning"`, stav účtování zůstává 1) je `contributor_failed`
(selhání contributoru deníku, §7.1).

### 7.4 Chyby a alerty

Filozofie: účtování **nikdy neblokuje** přechod do stavu 40 (jedna
nedohledatelná položka nesmí zablokovat celý doklad — relevantní hlavně pro
budoucí bankovní výpisy). Místo toho:

- doklad ve stavu 40 s `accounting_state = 2` je "dluh" uživatele
- alert check (inline v `module.jsonc` modulu `economy.accounting`, vzor
  `core.alerts`): per-record alert na doklady
  `docState = 40 AND accounting_state = 2`
- po opravě (rozvrh, položka, …) uživatel spustí přeúčtování — detail akce
  **Přeúčtovat** (`detail.actions` pattern) → endpoint znovu spustí engine
  pro doklad ve stavu 40; úspěch alert rozpustí

### 7.5 Tvrdá vs. měkká validace — shrnutí

| kontrola | kdy | typ |
|---|---|---|
| `operation` povinný a povolený pro docType | uložení dokladu | tvrdá (`validate`) |
| `operation` povolený pro `cash_dir` hlavičky (`cashDir`) | uložení dokladu | tvrdá |
| `payment.*` řádek má partnera a `payment_reference` (`identityRequired`) | uložení dokladu | tvrdá |
| `acc.entry` řádek má `item` | uložení dokladu | tvrdá |
| položka `acc.entry` je typ 2 + má účet | účtování | měkká (chybový řádek) |
| pokladna dokladu má účet 211xxx (hotovostní doklad, hotově placená faktura) | účtování | měkká (`cash_desk_account_missing`) |
| účet dle masky existuje v rozvrhu | účtování | měkká |
| MD = DAL, neprázdný deník | účtování | měkká |

### 7.6 Přegenerování deníku vs. zámek období (#55 D27)

Deník je **derivát dokladu** — bez změny dokladu se přegenerováním nesmí
změnit. Zamčený doklad (`documentLockProviders`: zamčená instance tvrzení
DPH nebo zamčený fiskální měsíc, `docs/document-system.md` §16) proto
`POST /_accounting/reaccount` odmítne 422 `DOCUMENT_LOCKED` s výčtem
důvodů. Účtování při přechodu do 40 (`DocsHeadsEventHandler`) guard nemá —
uložení zamčeného dokladu odmítne už gateway, a import mód (zámek obchází)
musí účtovat dál.

Vědomé obejití má jen CLI: `shpd-ds doc-reaccount <docId> --force`
přeúčtuje i zamčený doklad a použití zaloguje (`warn`,
`document lock bypassed by force (doc-reaccount)`). Slouží k opravě rozvrhu
nebo předpisu nad uzavřeným obdobím — výsledek je stále jen derivát téhož
dokladu.

---

## 8. Měny a přepočty

Zásada: **každá částka existuje v měně dokladu i v domácí měně**, přepočet se
provádí jednou (při uložení dokladu) a deník už jen čte hotové hodnoty.
(Ve starém Shipardu byl deník jen v domácí měně a působilo to problémy.)

### Přepočet v `beforeSave` dokladu

1. `doc_currency == home_currency` → `_dom` = kopie, `exchange_rate = 1`.
2. Jinak `_dom = round(cur × exchange_rate, 2)` + **haléřové dorovnání**, aby
   platily invarianty:

```
Σ rows.vat_base_dom   (per vat_code) == vat_recap.base_dom   (daného kódu)
Σ rows.vat_amount_dom (per vat_code) == vat_recap.tax_dom
Σ vat_recap.base_dom  (sum_base=1)   == heads.total_base_dom
Σ vat_recap.tax_dom   (sum_tax=1)    == heads.total_vat_dom
total_base_dom + total_vat_dom + total_rounding_dom == total_amount_dom
```

Pozn. k reverse charge: primární řádek samovyměření nese spočtenou daň
(odpočet), ale `sum_tax = 0` — do `total_vat` nevstupuje a `total` řádku
je jen základ (daň se dodavateli neplatí).

Dorovnání: rozdíl ze zaokrouhlení se přičte k poslednímu nenulovému řádku
příslušné skupiny (vat_code). Závazné jsou head totals (top-down: head →
recap → rows).

Díky invariantům deník automaticky bilancuje i u cizoměnových dokladů —
strana pohledávky/závazku (head total) přesně odpovídá součtu základů + DPH
+ zaokrouhlení.

---

## 9. Zobrazení (Fáze 3) ✓

### Detail dokladu — tab Zaúčtování

`DocsHeadsViewer::buildAccountingTab()` přidává za Přehled podmíněný tab
Zaúčtování (label z cfgItem `economy.accounting.viewerDetailLabels`).
Zobrazí se, když `accounting_state != 0` nebo existují řádky deníku.

- **Mezimodulová vazba** docs.core × economy.accounting: přímý dotaz
  (precedent: přílohy z core.attachments). Guard bez `tableExists` —
  extension sloupec `accounting_state` je v `SELECT h.*` jen
  s nainstalovaným modulem; bez něj se na tabulku deníku vůbec nesahá.
  Čistý extension point (`viewerDetailExtensions` v module.jsonc, obdoba
  `documentEventHandlers`) zůstává dluh.
- **Obsah** (composite): stavový badge (názvy z `accountingStates`,
  inline styly přes globální CSS proměnné — scoped styly se na `{@html}`
  nevztahují); při stavu 2 banner s `accounting_messages` (`rowId` →
  „řádek N", hodnoty escapované). Tabulka řádků deníku Účet / Text /
  MD / DAL se Σ řádkem; u cizoměnového dokladu navíc MD/DAL v měně
  dokladu (kód měny v labelu sloupce). Chybové řádky `_class: error`,
  součtový řádek `_class: total`, částky `align: right` (viz
  `docs/frontend.md` §7). Nulová strana zápisu se nechává prázdná.
  Prázdný deník při chybě (např. chybějící fiskální období) → jen
  banner bez tabulky.

### Akce Přeúčtovat

Detail dokladu vrací `actions: [{id: "reaccount"}]` jen pro doklad ve
stavu 40 — libovolný `accounting_state` (přeúčtovat lze i bezchybně
zaúčtovaný doklad), bez confirm (operace je idempotentní). Frontend
(`Viewer.svelte::handleDetailAction` + `api/accounting.js`) volá
`POST /_accounting/reaccount` `{docId}` a refreshne detail i seznam;
endpoint vrací success i při výsledku „zaúčtováno s chybami" — refresh
rovnou ukáže banner. Chybové odpovědi jdou přes `translateError`
(klíč `error.INVALID_DOC_STATE`).

### Viewer deníku — `economy.accounting.journal`

`JournalViewer` („Účetní deník", ikona `book`) — **read-only**: žádné
new/edit/delete (`getToolbarActions()` prázdné), bez docState tabů
(`$docStatesCfgItem = null`), bez formu. Tabulka deníku už nemá
`hideFromNavigation` — do navigace jde přes viewer.

- **Seznam**: text + číslo účtu, datum / číslo dokladu / partner,
  částka se stranou zápisu (MD/DAL) v domácí měně, u cizoměnového
  řádku navíc částka v měně dokladu s kódem. Chybové řádky: červený
  proužek (`stateStyle: error`) + ⚠. Řazení `accounting_date` desc,
  `id` desc.
- **Filtry** (generický filtr bar `ViewerFilters.svelte`, viz
  `docs/frontend.md` §7): fiskální rok, fiskální měsíc (závislý select
  přes `parentFilter`), účet (prefix match), partner (contains na
  jméno), Jen chyby. **Fulltext**: text, doc_number, account_number.
- **Detail**: properties (Zápis / Částky vč. obou měn / Doklad) + akce
  Otevřít doklad (`kind: open_viewer` → `docs.core.heads`, existující
  cross-viewer navigace).

Obratová předvaha, hlavní kniha a další reporty = samostatný pozdější
úkol.

---

## 10. Mimo scope

Vědomě se teď neřeší (a předpis/schéma na to nic nepředpřipravuje):

- **Saldokonto** — párování úhrad, párování a zdanění záloh (samotné
  pohyby záloh na fakturách i pokladních dokladech už existují — vlna C,
  Task E), symboly a balance v deníku, kurzové rozdíly, zápočty. Bude
  samostatný velký úkol, navržený od nuly a jinak než ve starém Shipardu.
  Ze starého enginu tím odpadá: `balanceRows`, `balancePayment`/
  `balanceRequest`, `paymentSymbols`, dohledávání účtu z deníku.
- **Metody účtování** (`accMethod`, daňová evidence, `stockA`/`stockB`) a
  **účetní skupiny** (`e10doc.debs.groups`).
- **Sklad** — skladové pohyby, `invPriceAcc`, ocenění. Pohyby `sale.goods` /
  `purchase.goods` zatím účtují jen výnos/náklad bez vazby na sklad.
- **Majetek** (property, odpisy), **accRing** (účetní okruhy), `accExts`,
  `cashBookId`.
- **Doklady bank / purchase / sklad** — přijdou s dalšími typy dokladů;
  mechanismy `query` (vč. operátorů) a `headQuery` v krocích předpisu
  pokryjí budoucí potřeby. Pokladní doklady, prodejky a hotově placené
  faktury jsou hotové (#59, sekce 4 a 5).
- **Pokladní kniha** — report nad deníkem (211 analytika pokladny, běžící
  zůstatek), otevírací doklady (`cmnbkp` 211/701), inventura 211 proti
  668/568 a importní kontrola `initBalance` — fáze 2 (#59 D10). Platební
  terminály per 261 analytika a tisk pokladních dokladů také později.
- **OSS** (prodej neplátcům v EU se zahraničními sazbami) — `vat-de.jsonc`
  a další státy, zahraniční kódy v UI dokladu, OSS přiznání, per-datasource
  vrstva mapování, enablement per stát. Základ je položený: konvence
  `343{CC}{NNN}`, fallback omezený na tuzemsko, alfanumerické účty projdou
  stackem (viz sekce 5, Konvence DPH analytik a OSS).
- **Krácení odpočtu** u krácených kódů (118/119/341/342) — dopočet na 548
  je téma budoucího DPH přiznání; zatím se účtuje plná daň na vlastní
  analytiku.
- **Automatické zakládání DPH účtů za běhu** (`checkAccountVAT`) — účty
  zakládá provisioner / uživatel, engine jen dohledává.

Hotovo (dřív tady jako „později"): **analytiky bankovních účtů a pokladen**
žijí jako atribut číselníku `accounting_account` — na bankovním spojení
extension `economy.bank/extensions/economy_codebooks_bank_accounts.jsonc`
(221xxx, používá bankovní engine), na pokladně extension
`economy.accounting/extensions/economy_codebooks_cash_desks.jsonc` (211xxx;
používá `accountSrc: cashDesk` — pokladní doklady, prodejky i hotově
placené faktury, #59 D8). Formuláře obou číselníků nabízejí lookup omezený
na analytiky dané řady, Document tvrdě validuje.

---

## 11. Fáze implementace

**Stav: Fáze 1–3 hotové** (commity `87e53b1`/`e6442c4`/`2bd1619` —
Fáze 1; mechanismus eventů, deník, předpis, engine, endpoint, alert —
Fáze 2; tab Zaúčtování, akce Přeúčtovat, JournalViewer — Fáze 3).
Dál: reporty (obratová předvaha, hlavní kniha), saldo, další docTypes.
Drobnosti zjištěné implementací:

- cfgItem předpisu: `economy.accounting.rules.cz` (tečkovaný suffix funguje)
- extension soubory dle cílové tabulky (`extensions/docs_core_heads.jsonc`)
- reverse charge se účtuje oboustranně: primární řádek recapu nese odpočet
  (strana kroku), pár `is_reverse_pair = 1` oddanění (opačná strana) —
  viz sekce 4, Specifika `src: vat`
- integrační testy: `tests/Integration/Accounting/`

### Fáze 1 — pohyby a sloupce ✓

- `rowOperations.jsonc` + sloupec `operation` v `docs_core_rows` + validace
  v `DocDocument` + select v řádkovém formuláři (filtrovaný dle docType,
  default dle `order`)
- `_dom` sloupce v `docs_core_rows`, `total_rounding_dom` v heads, přepočet
  s dorovnáním + invarianty (testy!)
- extension `accounting_account` na `economy_items` + pole ve formuláři
  položky

### Fáze 2 — deník a engine ✓

- mechanismus `documentEventHandlers` (core: interface, registry, dispatch
  v `TableGateway`)
- tabulka `economy_accounting_journal` (413)
- extension `accounting_state` / `accounting_messages` na heads + config
  `accountingStates.jsonc`
- `accountingRules.cz.jsonc` + `AccountingEngine` + `DocsHeadsEventHandler`
- endpoint Přeúčtovat + alert check na chybové doklady
- testy: kontrolní příklady invno/invni (CZK, cizí měna, zaokrouhlení,
  acc.entry, chybové stavy)

### Fáze 3 — UI ✓

- tab Zaúčtování v detailu dokladu (podmíněný, composite; guard přes
  extension sloupec v `SELECT h.*`) + banner chyb + akce Přeúčtovat
- `JournalViewer` s filtry; vznikly obecné frontend patterny: generický
  filtr bar `ViewerFilters.svelte` (`TableViewer::getFilters()` →
  `filter[id]=value`) a rozšíření `table` content typu o
  `columns[].align` / `row._class` — viz `docs/frontend.md` §7
- task: `tasks/accounting-phase3.md` (vč. zapsaných rozhodnutí a dluhu
  `viewerDetailExtensions`)

---

## 12. Log rozhodnutí

1. Pohyb = nový sloupec `operation` (enumString) v `docs_core_rows`;
   `row_kind` zůstává strukturální.
2. Pohyby definuje `docs.core` (`rowOperations.jsonc`), čitelné stringové klíče.
3. Deník: jednostranné řádky `money_dr`/`money_cr`, FK `account` +
   denormalizované `account_number`; částky vždy v obou měnách; řazení podle
   `id`; bez docStates.
4. Účtování neblokuje přechod do stavu 40 — `accounting_state` +
   `accounting_messages` (extension z economy.accounting) + alert.
5. Nedohledaný účet: `account = NULL`, `account_number` = maska + `?`
   (`504???`), `is_error = 1`.
6. `_dom` sloupce v řádcích dokladu, haléřové dorovnání top-down
   (head → recap → rows).
7. Předpis per-country (`accountingRules.cz.jsonc`) v `economy.accounting`;
   výběr dle země vlastní firmy.
8. DPH analytiky per kód: mapování `vat_code → 343{NNN}` žije v sekci
   `accounts` účtovacího předpisu (query na `vat_code`), **ne** jako
   atribut kódu ve `world.vat` (legislativní vrstva bez účetních konvencí)
   a **ne** v DB číselníku (ten přijde až jako per-DS override s OSS).
   Ruší dřívější záměr „analytika jako atribut vatCode".
9. Účetní položky: extension `accounting_account` na items (FK na rozvrh),
   pohyb `acc.entry`, účet přímo z položky (`accountSrc: "item"`).
10. Saldokonto kompletně mimo scope, bez předpřípravy ve schématu.
    **Částečně obráceno (accbal Fáze 0, tasks/accbal-phase0-payment-identity.md):**
    deník dostal `payment_reference` / `specific_symbol` / `constant_symbol` /
    `due_date`, aby saldo četlo výhradně deník (bez joinu na zdroj) a šel filtr
    deníku za VS. Samotná saldo tabulka/logika zůstává mimo scope.
11. Kategorie předpisu jsou sémantické stringy (`receivables`, `revenue`, …),
    ne čísla účtů.
12. Hook přes obecný mechanismus `documentEventHandlers`
    (`stateChanged`, `beforeDelete`) — použitelný i pro budoucí sklad/saldo.
13. Konvence čísel DPH analytik: `343{NNN}` tuzemsko, `343{CC}{NNN}`
    zahraničí (`de-120` → `343DE120`); `NNN` = číselná část vat kódu.
    Zahraniční analytiky se neprovisionují plošně — on-demand s OSS.
14. Reverse charge: primární řádek rekapitulace (kód s `reverseVatCode`)
    nese spočtenou daň — odpočet pro účetnictví i budoucí DPH přiznání;
    `total` řádku je jen základ a `sum_tax = 0` drží head totals beze změny.
    Pár (`is_reverse_pair = 1`) se účtuje na opačnou stranu než krok;
    analytika podle vlastního kódu páru.
15. Fallback `vat` masky 343 omezen na `vat_code_country = cz` — zahraniční
    kód bez mapování selže hlasitě (chybový řádek `343???` + alert), nikdy
    tiše na 343. Až přibudou další country předpisy, každý má svůj fallback
    se svou zemí.
16. Historické kódy (3xx řada 2015–2023) mají mapování i seed účty — kvůli
    zpětně datovaným a migrovaným dokladům. Trvale nulové kódy (112, 122,
    123, 201, 202, 401) mapování nemají, nulová daň řádek negeneruje.
17. PDP výstup (cz-150/151/152/350): `noPayTax + sumTax: 0` — faktura je
    jen základ, daň odvádí zákazník, bez oddaňovacího páru (oprava W4).
18. Převody peněz (#59 Task D, `tasks/cash-transfers.md`): **T1** pohyby
    `transfer.in/out` jen na `cash` a bankovních transakcích, na `cmnbkp` ne
    (ruční opravy = `acc.record` na 261). **T2** převody na `261100`
    (`cash.transit`), karty přesunuty na `261400` (`card.transit`) — u
    převodů čekáme nulu, u karet ne. **T3** řádek převodu bez DPH, bez
    partnera, `payment_reference` nepovinný (cesta k budoucímu párování
    261); saldokontní skupina 4100 ze starého systému se nezavádí.
    Migrované DS (`skipProvisioning`) dostanou 261400 rozvrhem, ne
    provisionerem — stejně jako 261100. **Obráceno v Task E (č. 19).**
19. Opravy po reimportu 689089 (#59 Task E, `tasks/cash-import-fixes.md`):
    **E1** 261/261100/261400 jsou infrastruktura — `TransitAccountsProvisioner`
    bezpodmínečně i pod `skipProvisioning` (migrovaný rozvrh je nemá).
    **E2** archivovaná pokladna (70) dostane řady ve stavu 70; import je
    přijme, UI ne. **E3** zálohy na `cash`: odpočet `*.advanceDeduction`
    jako na faktuře (položkový s DPH — upřesnění proti původnímu zadání
    „bez DPH“), nové `advance.received/given` kontační bez DPH s povinným
    partnerem (`partnerRequired`), VS nepovinný, záporná = vrácení (D9).
20. Osoba pro saldokonto (#72 D1–D6, 2026-09-15, `tasks/doc-partner-balance.md`):
    karta / brána / dobírka = pohledávka 311 za plátcem (`partner_balance`,
    `partnerSrc: "balance"`), tranzit `card.transit` 261400 z předpisu pryč
    (účet v osnově zůstává, provisioner ho už nezakládá — obrací E1 pro
    261400, 261100 beze změny). Jeden číselník terminálů a bran
    (`kind`), způsoby dopravy, způsob úhrady 5 Platební bránou; 315 do
    skupiny Pohledávky. Prodejka a příjmový PD bez plátce u karty / dobírky
    / brány neprojdou (rozhodnutí nad rámec zadání: 311 bez dlužníka by se
    nedalo spárovat). Vyúčtování úhrad v novém Shipardu mimo scope.
21. Zálohová faktura vydaná na podrozvaze (#79 D2/D3a, 2026-09-23,
    `tasks/accbal-proformas-out.md`): `invpo` účtuje jen `756100 MD /
    799100 DAL` celkovou částkou hlavičky (kategorie `proformas.out`,
    `offbalance.contra`); třída 7 má povahu 6 Podrozvaha, účty zajišťuje
    `OffBalanceAccountsProvisioner` bezpodmínečně vč. opravy povahy
    75–79. Úhradu proformy engine přes `OpenItem::paymentCategory`
    účtuje na 324 (§4 „Zálohová faktura vydaná“); uzavření případu
    proformy (799 MD / 756 DAL) dělá contributor deníku (#22).
22. Contributoři deníku (#79 D3b/c, 2026-09-23,
    `tasks/accbal-proforma-closure.md`): core rozhraní
    `JournalContributor` volané **oběma** enginy po sestavení řádků a
    před kontrolou vyrovnanosti, registrace `journalContributors` v
    module.jsonc (víc modulů), injekce oběma dispatchery i controllery.
    Proč ne v bankovním enginu: stejnou logiku potřebuje pokladna
    i budoucí kanály; proč ne z handleru `journalWritten`: reaccount by
    řádky smazal a handler by je psal do cizího deníku. Výjimka
    contributoru = varování `contributor_failed` (zprávy dostaly volitelné
    `level`, stav zůstává 1); jinou identitu než transakce bankovní
    engine odmítne. Sdílené `AccountingRules` + `JournalContributions`
    místo třetí kopie dohledání předpisu.
