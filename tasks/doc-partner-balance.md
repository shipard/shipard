# Osoba pro saldokonto — platební terminály a brány, způsoby dopravy, 311 místo 261400

**Stav:** hotovo — implementace, testy a dokumentace 2026-09-15 (4 commity);
ds-upgrade na dev DS 4l3j (tabulky, sloupce, 315 v Pohledávkách), unit
i integrační sada zelené. Zbývá ruční proklik UI, nasazení na alfu
(`ds-upgrade` + `accbal-regenerate --all` na DS s doklady kartou) a
navazující import v `old_shipard`.
**Issue:** #72 (rozhodnutí D1–D6 v komentáři 2026-09-15); souvisí s #59 (karty
přes 261400 — tímto taskem nahrazeno) a #69 (saldokonto — klíč partner + VS).
**Milník:** M2 (bez toho se DS `btpg-p` neporovná se starým systémem).
**Návaznost:** po nasazení navazuje import v `old_shipard` (samostatný task —
`cashreg`, číselníky terminálů a dopravy, `personBalance` z hlaviček). Tvorba
„Vyúčtování úhrad" v novém Shipardu je mimo scope (samostatná issue, viz D6).

## Cíl

Pohledávka z prodejního dokladu placeného kartou, přes platební bránu nebo na
dobírku vzniká na 311 za **osobou pro saldokonto** (protistrana terminálu,
brány, dopravce) s VS = číslo dokladu — ne za zákazníkem z hlavičky a ne na
tranzitním 261400. Terminál/brána/dopravce je v saldokontu obyčejný dlužník;
model z #69 (případ = klíč partner + VS + …) se nemění.

Starý Shipard: hlavička `personBalance` + `askPersonBalance`, odvození v
`heads.php::checkBeforeSave` (karta → `pb` terminálu pokladny, dobírka → `pb`
dopravce, jinak `= person`), v `debs.php` dostane saldokontní řádek
`person = personBalance`. Nový Shipard to přebírá s jedním číselníkem
platebních prostředníků.

Před implementací **přečti**:

- `docs/accounting.md` — §„Předpis pokladny — `cash`, `cashreg`, hotově
  placené faktury (#59 D8)" a „Proč dvě analytiky 261" (obojí tento task mění)
- `docs/accbal.md` — klíč případu, saldokontní skupiny, `LedgerGenerator`
- `docs/table-definitions.md`, `docs/document-system.md` (§ hooky `beforeSave`,
  `validate`), `docs/edit-forms.md` §22 (lookup pole), `docs/edit-forms-cookbook.md`
- `docs/documentation.md` (README modulu, `.md` k tabulce), `docs/help-authoring.md`
- `modules/economy/codebooks/tables/economy_codebooks_cash_desks.jsonc` +
  `forms/…cash_desks.jsonc` + `src/CashDeskDocument.php` (vzor číselníku vč.
  výlučného `is_default`) a `src/CashDesksLookup.php` (vzor lookupu)
- `modules/docs/core/src/DocDocument.php::beforeSave`,
  `DocsHeadsFormBase.php` (separator „Platba", `applyClientDefaults`),
  `CashDeskDocumentBase.php`, `modules/docs/cashRegister/src/CashRegisterDocument.php`
- `modules/economy/accounting/src/AccountingEngine.php` (`makeLine`,
  `headIdentity`, řádková identita), `config/accountingRules.cz.jsonc`,
  `tests/Integration/Accounting/CashDocsAccountingTest.php`,
  `CashAccountingRulesTest.php`
- `modules/economy/accbal/config/balancesDefault.cz.jsonc`,
  `src/BalancesProvisioner.php` (idempotence dle `code` skupiny)

## Rozhodnutí (z #72, zamčená)

- **D1** Model osoby pro saldokonto; 311 místo 261400 pro karty. `card.transit`
  z předpisu pryč, účet 261400 v osnově zůstává (nic ho neúčtuje), 261100 beze změny.
- **D2** Hlavička `partner_balance` + `partner_balance_manual`; odvození jen pro
  prodejní směr (`invno`, `cashreg`, `cash` s `cash_dir = 1`); u `invni` jen ručně.
- **D3** `partner_balance` jen na saldokontním řádku (`partnerSrc: "balance"`).
- **D4** Jeden číselník `economy_codebooks_payment_terminals` (terminál / brána);
  nový způsob úhrady **5 Platební bránou**.
- **D5** `economy_codebooks_transports`: název, kód, protistrana. Nic víc.
- **D6** 315 do saldokontní skupiny `receivables`.

## Scope

### 1. Číselník platebních terminálů a bran — `economy.codebooks`

Tabulka `economy_codebooks_payment_terminals` („Platební terminály a brány"),
`tableId` = další volné (ověř `grep -rh tableId modules/ --include=*.jsonc`).
Sloupce podle vzoru pokladny (`code`, `name`, `notice`, `is_default`, `sort_order`,
`valid_from/to`, docState) + :

| sloupec | typ | poznámka |
|---|---|---|
| `kind` | enumInt, cfg `economy.codebooks.paymentTerminalKinds` | 0 Platební terminál, 1 Platební brána |
| `cash_desk` | int, nullable, ref `economy_codebooks_cash_desks` | jen `kind = 0`; terminál patří jedné pokladně (1:N, bez `docLinks`) |
| `partner` | int, nullable, ref `base_persons_persons` | **Osoba pro saldokonto** — protistrana, za kterou vzniká pohledávka |

Validace v `PaymentTerminalDocument`: `partner` povinný ve stavu 40 (číselník bez
protistrany nedává smysl — `partner_required`); `cash_desk` povinná pro
terminál, prázdná pro bránu. `is_default` výlučné **v rámci pokladny** (terminál)
resp. **mezi bránami** (brána) — stejný mechanismus jako `CashDeskDocument`.
Formulář JSONC, viewer, lookup (`PaymentTerminalsLookup`, filtr `kind` a
`cash_desk`), navigace vedle Pokladen. Dokumentace: `tables/….md`, README modulu.

### 2. Číselník způsobů dopravy — `economy.codebooks`

Tabulka `economy_codebooks_transports` („Způsoby dopravy"): `code`, `name`,
`notice`, `partner` (ref `base_persons_persons`, nullable — vlastní doprava
protistranu nemá), `sort_order`, `valid_from/to`, docState. Bez řidiče, RZ,
hmotnosti (D5). Formulář, viewer, lookup, navigace, dokumentace.

### 3. Hlavička dokladu — `docs_core_heads`

Nové sloupce ve skupině `payment`:

| sloupec | typ | poznámka |
|---|---|---|
| `payment_terminal` | int, nullable, ref `economy_codebooks_payment_terminals` | terminál/brána použitá k platbě |
| `transport` | int, nullable, ref `economy_codebooks_transports` | způsob dopravy |
| `partner_balance` | int, nullable, ref `base_persons_persons` | Osoba pro saldokonto (label „Plátce") |
| `partner_balance_manual` | boolean, default 0 | ruční zadání — odvození ho nepřepisuje |

Indexy: `idx_partner_balance`, `idx_payment_terminal`. Popis sloupců do
`docs_core_heads.md`.

`docs.core.paymentMethods`: přidat `"5": Platební bránou` (`name:cs`,
`name:en: Payment gateway`). Aktualizovat výčet v `docs/docs-mvp.md`
(hledej „4 zápočtem").

### 4. Odvození `partner_balance` — `DocDocument::beforeSave`

Nová metoda `resolvePartnerBalance(array &$data)` volaná po
`denormalizeFromSeries` a před výpočtem řádků. Platí pro **prodejní směr**:
`invno`, `cashreg`, `cash` s `cash_dir = 1`. Pořadí:

1. `payment_method = 2` (Kartou) nebo `5` (Platební bránou):
   - je-li `payment_terminal` prázdný nebo neodpovídá (u karty: terminál jiné
     pokladny než `cash_desk` hlavičky; u brány: `kind ≠ 1`) → doplnit default
     (u karty default terminál pokladny hlavičky, u brány default brána);
   - `partner_balance = terminal.partner`.
2. `payment_method = 3` (Dobírkou) a `transport` s neprázdným `partner` →
   `partner_balance = transport.partner`.
3. Jinak, pokud `partner_balance_manual = 0` → `partner_balance = partner`.

Kroky 1–2 **přepisují i ruční hodnotu** (jako starý Shipard: terminál/dopravce
má přednost). Pro `invni` a ostatní typy: jen krok 3 (ruční ponechat, jinak
`= partner`). Pro pokladní **výdej** (`cash_dir = 2`) krok 3.

Pozor na `cash_desk` u `cashreg`/`cash`: denormalizuje se z řady
(`series_binding = cash_desk`) — proto se odvození volá až po
`denormalizeFromSeries`. Karta na FV (`invno`) pokladnu nemá: bez `cash_desk`
se vezme terminál z `payment_terminal` hlavičky, je-li zadaný, jinak default
mezi **všemi** terminály (`is_default` bez ohledu na pokladnu; není-li žádný →
`partner_balance` zůstane `= partner` a validace nic nevynucuje — DS bez
terminálů funguje jako dnes).

Validace: `payment_method = 5` bez `payment_terminal` (po odvození) → tvrdá
chyba `payment_terminal_required` (brána musí být vybraná). Ostatní případy
bez chyby.

Import mód (`_importNumber`): odvození běží stejně, ale importem poslané
`partner_balance` s `partner_balance_manual = 1` se respektuje (import ze
starého Shipardu pošle `personBalance` explicitně — terminály se mapují až v
navazujícím tasku, takže se nesmí přepsat prázdným defaultem). Doplň do
`docs/document-system.md` k virtuálním polím importu, pokud se tam něco mění.

### 5. Formulář hlavičky — `DocsHeadsFormBase`

V separatoru „Platba" (a `CashDeskFormBase` / `CashRegisterForm`, kde jsou
přepisy):

- `payment_terminal` — lookup, zobrazený jen při `payment_method ∈ {2, 5}`;
  u karty filtr `cash_desk` = pokladna hlavičky (u FV bez filtru), u brány
  filtr `kind = 1`. `payment_method` už má `triggers: 'reload'`.
- `transport` — lookup, zobrazený u prodejního směru vždy (doprava se zadává i
  bez dobírky); u `invni` a pokladního výdeje ne.
- `partner_balance` — lookup Osoby s labelem „Plátce", `readOnly` pokud
  `partner_balance_manual = 0`; checkbox `partner_balance_manual` („Zadat
  plátce ručně"). Při `payment_method ∈ {2, 3, 5}` s odvozenou osobou je
  checkbox skrytý (odvození má přednost, D2).

`HeaderInfo` / živý pruh: pokud `partner_balance ≠ partner`, ukázat vedle
partnera „Plátce: …" (krátce, jen když se liší).

### 6. Účtování — `AccountingEngine` + `accountingRules.cz.jsonc`

- Nový atribut kroku `partnerSrc: "balance"` → `headIdentity` (resp. identita
  head/vat zdroje) vezme `partner = head.partner_balance ?? head.partner`.
  Bez atributu chování beze změny. Zdokumentovat v tabulce atributů kroků v
  `docs/accounting.md`.
- `invno`: krok `receivables` (`payment_method ≠ 0`) dostane `partnerSrc: "balance"`.
- `invni`: krok `payables` dostane `partnerSrc: "balance"` (ruční plátce).
- `cash` příjem: krok `card.transit` (`cash_dir 1, payment_method 2`) → nahradit
  `{"cat": "receivables", "partnerSrc": "balance", …, "query": {"cash_dir": 1,
  "payment_method": {"$in": [2, 3, 5]}}}` (ověř, zda query engine `$in` umí;
  jinak tři kroky). Pokladní příjem dobírkou/bránou je okrajový, ale konzistentní.
- `cash` výdej: krok `card.transit` (`payment_method 2`) → `payables` s
  `partnerSrc: "balance"` (výdej kartou = závazek za terminálem; okrajové).
- `cashreg`: `card.transit` krok → `receivables` + `partnerSrc: "balance"`,
  query `payment_method ∈ {1, 2, 3, 5}` (dnes je 1 zvlášť — sloučit, identita
  je stejná: hlavička s plátcem).
- `CashRegisterDocument`: partner povinný u převodu zůstává; **u karty, brány a
  dobírky je povinný `partner_balance`** (po odvození) — jinak by pohledávka
  vznikla bez partnera. Chyba `partner_balance_required` s hláškou „Doklad
  nemá plátce — nastav terminál/bránu/dopravce s protistranou".
- Kategorie `card.transit` a maska `261400` z `accounts[]` a `categories`
  odstranit. `TransitAccountsProvisioner`: 261400 už neprovisionovat (261100
  zůstává); seedy osnov účet 261400 ponechat. Upravit
  `CashAccountingRulesTest::testEvery261MaskOfRulesHasAccountInBothSeedCharts`
  a související testy.

### 7. Saldokonto — 315

`balancesDefault.cz.jsonc`, skupina `receivables`: přidat `315` MD kladné
`bal_side 0` / DAL kladné `bal_side 1` (jako 311). Provisioner skupinu
přeskakuje, pokud existuje — pro **existující DS** doplnit do
`BalancesProvisioner` idempotentní doplnění chybějícího účtu do existující
seedované skupiny (per `code` skupiny + `account_number`), nebo to zdokumentuj
jako ruční krok v `docs/accbal.md`. Preferuj provisioner (DS `btpg-p` a alfa
skupinu už mají).

Ověř, že `LedgerOpenItemLookup` a `ClearingRouter` vezmou 315 automaticky (účty
skupiny, ne prefix — #69 oprava).

### 8. Dokumentace a help

- `docs/accounting.md`: přepsat §„Předpis pokladny" (karta → 311 za plátcem),
  §„Proč dvě analytiky 261" (261400 zrušeno z předpisu, důvod v #72 D1),
  kontrolní příklady (PD kartou, prodejka kartou → `311 MD partner_balance`),
  nová sekce „Osoba pro saldokonto" (odvození, `partnerSrc`).
- `docs/accbal.md`: 315 ve skupině pohledávek; poznámka, že terminál/brána/
  dopravce je běžný dlužník a vyúčtování je mimo (odkaz #72 D6).
- `modules/economy/codebooks/README.md`: dvě nové tabulky.
- `help/`: stránka „Platba kartou, přes bránu a dobírkou" (nastavení
  terminálu/brány/dopravy s protistranou, co se stane s pohledávkou, kde ji
  najdu v saldokontu) + odkaz z existujících stránek k pokladně/prodejce;
  `help/co-dnes-nejde.md`: vyúčtování úhrad od brány se zatím nedělá.
  Názvy polí ověř ve zdroji (`name:cs`).

### 9. Testy

- `tests/Integration/Accounting/CashDocsAccountingTest`: PD kartou s terminálem
  → `311 MD partner = protistrana terminálu, payment_reference = číslo dokladu`,
  žádný řádek 261400; prodejka kartou dtto; prodejka dobírkou s dopravcem;
  prodejka bránou; PD kartou bez terminálu v DS → 311 za partnerem hlavičky
  (fallback), prodejka bez partnera i plátce → validace.
- Test odvození `partner_balance` (unit nebo integrační na `DocDocument`):
  pořadí kroků, ruční hodnota, přepis u karty, default terminálu pokladny.
- `CashAccountingRulesTest`: konzistence po odstranění `card.transit`.
- `tests/Integration/Accbal/…`: prodejka kartou + `cmnbkp` řádek
  `acc.balanceReceivable` DAL 311 za terminálem s týmž VS → případ uzavřený.
- Číselníky: výlučnost `is_default`, validace `partner_required`.

## Commit strategie

1. Číselníky (§1, §2) + dokumentace modulu.
2. Hlavička + způsob úhrady 5 + odvození + formulář (§3–§5) + testy odvození.
3. Účtování + zrušení `card.transit` + 315 v saldokontu (§6, §7) + testy.
4. `docs/accounting.md`, `docs/accbal.md`, `help/` (§8), `**Stav:**`,
   `python3 scripts/tasks-index.py`, `python3 scripts/help-index.py`.

## Hotovo když

- [x] Prodejka / PD / FVB kartou, bránou nebo dobírkou zaúčtuje pohledávku 311
      za protistranou terminálu / brány / dopravce s VS = číslo dokladu;
      261400 se neúčtuje.
- [x] Ruční plátce na FV/FP funguje a odvození ho nepřepíše.
- [x] 315 je součástí saldokonta Pohledávky i na existujících DS
      (`BalancesProvisioner` doplňuje chybějící účty, i pod `skipProvisioning`).
- [x] Testy z §9 zelené (`vendor/bin/phpunit --filter …`), `ds-upgrade` na
      dev DS projde, `CashAccountingRulesTest` zelený.
- [x] Dokumentace a help aktualizované, `tasks/README.md` + `help/` index
      přegenerované.

## Stav implementace (2026-09-15)

Odchylky a doplňky proti zadání:

- **Povinný plátce i na příjmovém PD** kartou / dobírkou / bránou
  (`CashDeskDocumentBase::validate`, `partner_balance_required`), ne jen na
  prodejce — 311 bez dlužníka by se nedalo spárovat; DS bez terminálů
  projde, jakmile má doklad partnera (rozhodnuto při implementaci).
- **`PartnerBalanceResolver`** (`modules/docs/core/src/`) je sdílená autorita
  odvození: volá ho `validate()` i `beforeSave()` (validate běží dřív) a
  formulář pro živý náhled plátce v `recalculate`.
- **Kanonický formát** dostal `balanceParty` (ruční plátce importu,
  `partner_balance_manual = 1`) a `payment.method` `paymentGateway`; kopie
  schématu v mail profilu aktualizována. `terminalCode` / `transportCode`
  až s navazujícím importním taskem.
- **Checkbox spouští reload** (`FormElement.svelte`) — do té doby
  `triggers` u checkboxu nefungoval.
- Pokladní doklad dál povoluje jen hotově / kartou; předpis `cash` má
  `$in [2, 3, 5]` pro budoucí rozšíření.
- `BalancesProvisioner` doplňuje chybějící účty do existující skupiny a pod
  `skipProvisioning` běží v režimu „jen doplnění" (nové skupiny nezakládá).
- Detail dokladu ve vieweru ukazuje řádek **Plátce**, liší-li se od partnera.

Nasazení na DS s doklady kartou: `ds-upgrade`, pak přeúčtovat doklady
kartou (`doc-reaccount` / reimport) a `accbal-regenerate --all`.

## Mimo scope (navazuje)

- Import v `old_shipard`: `cashreg`, terminály/brány (vč. DS-vlastních způsobů
  úhrady s `personForBalance` → brána + metoda 5), způsoby dopravy,
  `personBalance`/`payTerminal`/`transport` z hlaviček, partner saldo-řádku
  `cmnbkp` = osoba řádku. Mapování starých způsobů úhrady (0 převod → 1,
  1 hotově → 0, 2 karta → 2, 3 dobírka → 3, 12 brána → 5).
- Vyúčtování úhrad od brány / terminálu v novém Shipardu (311 → 315, poplatky).
- Směrování bankovního připsání od brány na 315 podle dávky.
