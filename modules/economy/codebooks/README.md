# Modul: Číselníky (economy.codebooks)

Modul sdružuje ekonomické číselníky používané dokladovým a skladovým
systémem. Klíčovou náplní jsou **fiskální období** (roky a měsíce, na
které se mapují účetní data dokladů) a **registrace DPH s obdobími**
(přiznání DPH, kontrolní hlášení). Modul dále spravuje **pokladny**
a **vlastní bankovní spojení**, které budou referencovány z hlaviček
dokladů (pokladní lístky, bankovní výpisy, faktury s předkontací na
bankovní účet), a **platební prostředníky** — terminály, brány a způsoby
dopravy s osobou pro saldokonto (#72).

Tabulky `economy_codebooks_warehouses` a `economy_codebooks_cost_centers`
jsou v této fázi placeholdery (schémata existují, UI a Document logika
přijde s dokladovým systémem).

## Závislosti

- `core.system`
- `world.base` — cfgItem zemí (`world.base.countries`) pro registrace DPH
  + budoucí currency picker pro fiskální roky
- `world.trade` — cfgItem obchodních unií (`world.trade.unions`) pro
  pole `region` u registrací DPH
- `base.persons` — osoba pro saldokonto u terminálů, bran a dopravy

## Tabulky

| Tabulka | Popis |
|---|---|
| [economy_codebooks_warehouses](tables/economy_codebooks_warehouses.jsonc) | Sklady (placeholder, fáze 1 neřeší) |
| [economy_codebooks_cost_centers](tables/economy_codebooks_cost_centers.jsonc) | Střediska (placeholder, fáze 1 neřeší) |
| [economy_codebooks_fiscal_years](tables/economy_codebooks_fiscal_years.md) | Fiskální (účetní) roky |
| [economy_codebooks_fiscal_months](tables/economy_codebooks_fiscal_months.md) | Fiskální měsíce navázané na rok |
| [economy_codebooks_vat_registrations](tables/economy_codebooks_vat_registrations.md) | Registrace k DPH (různé země, OSS, diskontinuity) |
| [economy_codebooks_cash_desks](tables/economy_codebooks_cash_desks.md) | Pokladny pro hotovostní operace |
| [economy_codebooks_bank_accounts](tables/economy_codebooks_bank_accounts.md) | Vlastní bankovní účty (firma) |
| [economy_codebooks_payment_terminals](tables/economy_codebooks_payment_terminals.md) | Platební terminály a brány — protistrana pohledávky z karty / brány |
| [economy_codebooks_transports](tables/economy_codebooks_transports.md) | Způsoby dopravy — protistrana pohledávky z dobírky |

**Číselník bankovních účtů vs. bankovní spojení Osob.** Vedle
`economy_codebooks_bank_accounts` existuje `base_persons_bank_accounts`
(modul `base.persons`) — bankovní spojení libovolné Osoby včetně
partnerských. Jsou to **dvě různé tabulky se dvěma rolemi**: na vydanou
fakturu jde výhradně účet z **číselníku**, spojení Osoby je evidence.
Číselník se dá naplnit překlopem bankovních spojení vlastní Osoby přes
panel Nastavení zdroje dat (`POST /_setup/bank-accounts`, ds-setup Task
09) — překlop je **kopie bez FK vazby**, žádná synchronizace se nekoná
a je to zamýšlené; `SetupController` řádky ukládá přes `BankAccountDocument`,
takže platí stejná validace i per-currency unikátnost `is_default` jako
při ručním pořízení.

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [FiscalYearDocument.php](src/FiscalYearDocument.php) | Validace fiskálního roku (povinná pole, rozsah dat, regex měny) |
| [FiscalMonthDocument.php](src/FiscalMonthDocument.php) | Validace měsíce + denormalizace `calendar_year`/`calendar_month` |
| [FiscalYearsForm.php](src/FiscalYearsForm.php) | Formulář roku se sub-tabulkou Měsíce |
| [FiscalYearsViewer.php](src/FiscalYearsViewer.php) | Viewer roků s tabem seznamu měsíců |
| [FiscalYearsProvisioner.php](src/FiscalYearsProvisioner.php) | Idempotentní seed aktuálního a následujícího roku |
| [VatRegistrationDocument.php](src/VatRegistrationDocument.php) | Validace registrace DPH (povinná pole, range platnosti, enum kontroly) |
| [VatRegistrationsForm.php](src/VatRegistrationsForm.php) | Formulář registrace (identifikace, periodicity = defaulty generátoru instancí, platnost) |
| [VatRegistrationsViewer.php](src/VatRegistrationsViewer.php) | Viewer registrací |
| [CashDeskDocument.php](src/CashDeskDocument.php) | Validace pokladny (povinná pole, formát měny) + default-per-currency uniqueness v `afterPersist` |
| [BankAccountDocument.php](src/BankAccountDocument.php) | Validace bankovního účtu (account_number nebo iban povinný, regex IBAN/BIC) + default-per-currency uniqueness v `afterPersist` |
| [PaymentTerminalDocument.php](src/PaymentTerminalDocument.php) | Terminál / brána: pokladna jen u terminálu, protistrana povinná ve 40, výlučný default per pokladna / mezi bránami |
| [PaymentTerminalsLookup.php](src/PaymentTerminalsLookup.php) | Lookup s cascade filtry `kind` a `cash_desk` (hlavička dokladu) |
| [TransportDocument.php](src/TransportDocument.php) | Validace způsobu dopravy |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.codebooks.fiscalPeriodTypes` | [config/fiscalPeriodTypes.jsonc](config/fiscalPeriodTypes.jsonc) | Typ měsíce — Otevření (0) / Běžné (1) / Uzavření (2) |
| `economy.codebooks.fiscalConfig` | [config/fiscalConfig.jsonc](config/fiscalConfig.jsonc) | `yearStartMonth` — výchozí 1 (leden); per-DS override zatím není |
| `economy.codebooks.vatTaxpayerKinds` | [config/vatTaxpayerKinds.jsonc](config/vatTaxpayerKinds.jsonc) | Druh plátce — Klasický (0) / OSS (1) |
| `economy.codebooks.vatPeriodKinds` | [config/vatPeriodKinds.jsonc](config/vatPeriodKinds.jsonc) | Frekvence DPH přiznání i kontrolního hlášení — Měsíční (1) / Čtvrtletní (2) |
| `economy.codebooks.paymentTerminalKinds` | [config/paymentTerminalKinds.jsonc](config/paymentTerminalKinds.jsonc) | Druh prostředníka — Platební terminál (0) / Platební brána (1) |

## Auto-generování fiskálních období

Při každém běhu `bin/shpd-ds ds-upgrade` se spustí
`FiscalYearsProvisioner::provision()` s touto logikou:

1. Načte `yearStartMonth` z cfgItem `economy.codebooks.fiscalConfig`
   (default 1).
2. Spočte rozsah aktuálního fiskálního roku podle dnešního data.
3. **Existuje-li v DB rok pokrývající dnešek?**
   - **Ne** → vygeneruje aktuální rok + 14 měsíců. Hotovo.
   - **Ano** → spočte rozsah následujícího roku; pokud neexistuje,
     vygeneruje ho.

Idempotence: lookup před insertem podle vypočítaného `date_begin`.
Druhý běh `ds-upgrade` na DS s aktuálním rokem typicky vygeneruje
rok následující; třetí běh je no-op (`existing: 2`).

Generovaný rok dostává `docState=40, docStateMain=3` (V pořádku).
Manuálně přes UI vznikající rok je `Koncept` (10) — uživatel ho
přepne tlačítkem.

Pro názvy roků a prefixy:

- `yearStartMonth=1`: `name = "YYYY"`, `doc_number_prefix` = poslední
  dvě číslice roku (např. `"26"`)
- jinak: `name = "YYYY-YYYY"` (rok začátku—rok konce, např.
  `"2026-2027"`), prefix = poslední dvě číslice **konce** (`"27"`)

Per-DS override `yearStartMonth` zatím není implementovaný — když
bude potřeba, doplní se mechanismus per-DS cfgItem override.

## Typy fiskálních měsíců

Každý fiskální rok obsahuje právě **14 měsíců**:

| period_type | Význam | Rozsah |
|---|---|---|
| 0 | Otevření | jednodenní = `date_begin == date_end == year.date_begin` |
| 1 | Běžné období | každý kalendářní měsíc roku (12×) |
| 2 | Uzavření | jednodenní = `date_begin == date_end == year.date_end` |

Otevření a Uzavření slouží počátečním a závěrkovým účetním operacím
(počáteční stavy, závěrkové opravy) — fakticky se chovají jako
samostatné jednodenní účetní období na hraně roku, kam doklady
„počátku" a „konce" patří mimo běžné měsíční rytmy.

`calendar_year` a `calendar_month` jsou denormalizované sloupce, do
kterých se v `FiscalMonthDocument::beforeSave` automaticky vyplňuje
rok a měsíc z `date_begin`. Ve formu jsou readOnly.

## Dělení dokladů

Každý doklad podle účetního data „spadne" do konkrétního fiskálního roku
a měsíce (`docs_core_heads.fiscal_year` / `fiscal_month`, dopočítává
`DocDocument::resolveAccountingPeriods`). Měsíc hledá sdílený
`FiscalMonthLookup::monthIdForDate()` — jen běžné měsíce (`period_type`
1); tentýž dotaz používá zámek měsíce pro nový stav dokladu, aby se
pravidlo nerozjelo.

## Zámek fiskálního měsíce (#55 D27)

`economy_codebooks_fiscal_months.locked` (+ `locked_at`, `locked_by`, stejný
tvar jako instance tvrzení DPH) přepíná uživatel ve formuláři měsíce
(sub-tabulka fiskálního roku, checkbox **Uzamčeno**); detail roku ukazuje
sloupec **Zámek**. Zamknout lze jen běžný měsíc. `FiscalMonthLockProvider`
(registrace `documentLockProviders` pro `docs_core_heads`) blokuje
**každý** doklad, jehož původní `fiscal_month` nebo nový měsíc
z `accounting_date` míří na zamčený měsíc — bez ohledu na obsah a stav,
koncepty i bezdaňové převody včetně. Vynucení a UI kontrakt jsou věcí jádra
(`docs/document-system.md` §16). Zamčený měsíc nemění rozsah, typ ani rok.

Při zamykání `FiscalMonthDocument` **varuje** (neblokuje), když kontrola
zůstatků 343 (`economy.vat`, `ClosedPeriodBalanceService`) najde za podané
přiznání končící v měsíci nevypořádanou DPH — vazba je měkká přes
přítomnost tabulky instancí, codebooks na vat nezávisí.

Roční `economy_codebooks_fiscal_years.locked` se **nevynucuje** — sémantika
uzavřeného roku přijde s uzávěrkou.

## Platební prostředníci a osoba pro saldokonto (#72)

Terminál (`kind` 0, patří pokladně) a brána (`kind` 1) sdílejí jeden
číselník; způsob dopravy je samostatný. Všechny tři nesou `partner` —
**osobu pro saldokonto**: pohledávka z prodejního dokladu placeného
kartou, bránou nebo dobírkou vzniká na 311 za touto osobou s VS = číslo
dokladu, ne za zákazníkem z hlavičky. Odvození `partner_balance` dělá
`docs.core` (`PartnerBalanceResolver`), účtování `economy.accounting`
(`partnerSrc: "balance"`) — viz `docs/accounting.md` § Osoba pro
saldokonto. Vyúčtování úhrad od brány / terminálu (311 → 315, poplatky)
je mimo scope (#72 D6).

## Registrace DPH a období DPH

Modul modeluje registrace k DPH a navazující období přiznání pro firmy,
které jsou plátci DPH ve více zemích nebo v různých režimech (klasický
plátce, OSS pro EU služby). Firma může mít 0, 1, nebo více aktivních
registrací; modeluje se i diskontinuita (uplynulé období plátcovství).

**Vztah:** každé období patří jedné registraci. Hlavička dokladu (přijde
později) bude obsahovat referenci na konkrétní registraci, a podle data
uskutečnění zdanitelného plnění „spadne" do jejího příslušného období.
Přiznání DPH se sestavují per registrace.

**Správce daně** (`tax_office_person`, #55 D30): osoba finančního úřadu
z adresáře, ručně vybraná v sekci **Správce daně** formuláře registrace.
`economy.vat` ji dává jako partnera saldo řádku účetního dokladu přiznání
(343801/343802), aby šla úhrada FÚ párovat. Je to jediný sloupec, který
jde doplnit i k registraci ve stavu **V pořádku** bez „Opravit"
(`VatRegistrationsForm::getReadOnlyEditableColumns`, Document zvládá
částečné uložení); import ji nastavuje přes `POST /_vat/registration-tax-office`
po naimportování osob. Číselník FÚ (`world.cz` `taxOffices`) slouží jen
hlavičce podání — osoba správce daně z něj neplyne.

### Instance daňových tvrzení

Období DPH už codebooks negeneruje. Instance tvrzení (přiznání, kontrolní
hlášení, souhrnné hlášení) s rozsahem s denní přesností žijí v tabulce
`economy_vat_report_periods` modulu `economy.vat`; periodicity na
registraci (`tax_period_kind`, `cs_period_kind`, `rs_period_kind`) jsou jen
defaulty jejího generátoru. Seed po uložení registrace dělá
`economy.vat` přes `afterSave` documentEventHandler. Viz
`modules/economy/vat/docs/README.md`.

### Manuální správa

V editačním formuláři registrace v záložce **Období DPH** lze přes
sub-formulář přidat / upravit / smazat jednotlivá období. To pokrývá
speciální případy:

- Mimořádná období (např. „Likvidace 2027" s vlastním rozsahem)
- Změna frekvence (`tax_period_kind`) u existující registrace —
  uživatel manuálně smaže nesedící stará období, další `ds-upgrade`
  doplní chybějící podle aktuální frekvence; provisioner do existujících
  záznamů nesahá. **Úklid musí být úplný**: kvůli překryvovému lookupu
  provisioner nevytvoří kandidáty, kteří se překrývají se zbylými
  (nesmazanými) starými obdobími — zapomenuté čtvrtletí zablokuje
  všechny tři měsíce v něm

Editace `valid_from`/`valid_to` po vygenerování období se neprojeví
zpětně — provisioner nikdy neupravuje existující záznamy. Uživatel si
přebytečné/chybějící období řeší ručně.
