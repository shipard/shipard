# Saldokonto — operace má přednost, dobropis na druhou stranu jako výchozí, uzávěrkové období mimo ledger (#69 D17–D20)

**Stav:** částečně — kód, testy a docs hotové 2026-09-22 (5 commitů); ověřeno na `btpg-p` po resetu + reimportu 2026-09-22 (rok 2026: 104 dluhů, přeplatky 0, uzávěrkové řádky 0, nastavení `legacy`; zbývajících 8 párů proti starému = proformy → #69 D21); zbývá `ds-upgrade` + reset/reimport a srovnání `e8w1-i`

## Kontext

Rozbor `btpg-p` proti starému modulu `balance` (#69 komentář 2026-09-21)
ukázal, že zbývající rozdíly nejsou v datech, ale v odvození pohybu:

- **D15** — generátor určuje skupinu jen z účtu + strany + znaménka. Opravy
  salda a zápočty ze starého systému mají 311 DAL **záporně** (439 řádků na
  `btpg-p`) a padají podle sign-pravidla do Závazků; v Pohledávkách zbyde
  přeplatek, v Závazcích fantom. Přitom všechny tyto řádky mají operaci
  `acc.balanceReceivable` / `acc.balancePayable` už z importu. Rok 2026:
  starý 112 dluhů, nový 104 + 8 přeplatků — všech 8 je tento vzor.
- **D14** — lookup (`LedgerOpenItemLookup`) hledá jen v skupině podle směru
  platby a jen na předpisových účtech bez `modify_sign`. Vratka přeplatku
  (výdaj proti pohledávce) a vratka dobropisu (výdaj proti dobropisu
  v Závazcích) se nikdy nenajdou; na `btpg-p` ~425 clearingových výdajů
  s klíčem přeplatkového případu.
- **Uzávěrkové doklady** — 311 DAL jednou částkou bez partnera per rok
  (13 řádků, ~7,6 mil.) vstupují jako úhrady a tvoří agregát bez klíče
  (−8,2 mil.). Starý systém je má v uzávěrkovém období a do salda je nebere.
  Nová strana otevírací/uzávěrkové období má (`period_type` 0/2), ale
  `DocDocument::resolveFiscalMonthId()` resolvuje vždy běžný měsíc podle data.
- **Nastavení** — `accbalSettings.json` v importu je dump testovacího
  Saldo2, které se v ostrém provozu nikdy nepoužívalo. Na `btpg-p` dává 321
  a 343802 do Pohledávek (76 případů). Starý modul `balance` sign-pravidla
  neměl (dobropis zůstával záporně ve své skupině).

Rozhodnutí (#69, zamčená):

- **D17** Skupinu a druh pohybu určuje (1) operace řádku, pokud určuje
  stranu; (2) jinak účet + strana + znaménko podle řádku nastavení platného
  k účetnímu datu pohybu.
- **D18** Dobropis se pozná jen ze záporné částky. Seed nového Shipardu
  nechává sign-pravidla (311 záporně → Závazky ×−1, zrcadlově 321).
  Importovaný DS dostane seed **bez** sign-pravidel; přepnutí je ruční přes
  platnost řádků nastavení na přelomu fiskálního roku, průvodce nevzniká.
- **D19** Lookup podle směru platby, ne podle účtu (ruší část T1).
- **D20** Import nastavení ze starého systému se ruší; řádky uzávěrkového
  období do ledgeru nejdou; haléřové rozdíly zůstávají viditelné.

## Před implementací přečti

- `docs/accbal.md` §3.2 (nastavení, sign-pravidla), §4.2 (generátor),
  §5.1 (lookup, „lookup je užší než případ"), §12 (import) — všechny
  se přepisují.
- `modules/economy/accbal/src/LedgerGenerator.php` — `buildDesired()`,
  `passesAmountsSign()`, `validAt()` (platnost k datu už funguje — D17 (2)
  jen potvrdit testem).
- `modules/economy/accbal/src/LedgerOpenItemLookup.php` —
  `targetsForDirection()`, `loadKeyRows()` s prefixy, `residualOf()`.
- `modules/economy/accbal/src/BalancesProvisioner.php` +
  `modules/economy/accbal/config/balancesDefault.cz.jsonc` — seed a
  `addMissingAccounts()`.
- `modules/docs/core/src/DocDocument.php` — `resolveFiscalMonthId()`,
  `Shipard\Module\Economy\Codebooks\FiscalMonthLookup`.
- `docs/accounting.md` — operace řádku (`acc.balanceReceivable`,
  `acc.balancePayable`, `acc.fxLossPayable`, `payment.*`, `acc.item`,
  `acc.record`), `fiscal_month` denorm.
- `docs/exchange-format.md` — hlavička dokladu (přidává se pole).
- `modules/economy/codebooks/src/FiscalMonthDocument.php` — `period_type`.

## 1. Generátor: operace má přednost (D17, D15)

`LedgerGenerator::buildDesired()` dostane před smyčkou přes účty nastavení
krok „operace“:

1. Řádek s operací určující stranu:
   - `acc.*Receivable` → skupina, jejíž nastavení obsahuje účet řádku
     v roli **předpisu** pro Pohledávky (na seedu `receivables`);
     `acc.*Payable` → totéž pro Závazky. Hledá se skupina, ne řádek
     nastavení: účet řádku musí být v té skupině na některém řádku
     (prefixově), sign-pravidla a `amounts_sign` se **nepoužijí**.
   - `bal_side` = podle strany účtu proti `acc_side` předpisového řádku
     skupiny (311 MD → předpis, 311 DAL → úhrada), **znaménko částky se
     zachová** (záporná úhrada = záporný pohyb ve stejné skupině, žádné
     ×−1).
   - `payment.*` → vždy úhrada (`bal_side` 1) ve skupině účtu, znaménko
     podle strany: strana, kterou skupina sleduje jako úhradu, dává +,
     opačná strana dává − (vratka = úhrada se záporným směrem).
   - Není-li účet řádku v žádné skupině, řádek se přeskočí jako dosud.
2. Řádek bez takové operace (`acc.item`, `acc.record`, NULL, ostatní) →
   stávající průchod přes řádky nastavení včetně sign-pravidel a
   `modify_sign`, platnost k účetnímu datu (`validAt`).

Seznam operací určujících stranu drž v jedné konstantě/mapě (`*Receivable`
→ 0, `*Payable` → 1) s testem, který ji porovná s cfg operací v
`docs/accounting.md`; nová operace bez zařazení = selhání testu.

## 2. Uzávěrkové období mimo ledger (D20)

- `DocDocument::resolveFiscalMonthId()`: doklad, jehož hlavička nese
  `fiscal_period_type` ∈ {`opening`, `closing`} (nový sloupec
  `docs_core_heads.fiscal_period_type`, enumString nullable, default NULL =
  běžné), se zařadí do otevíracího/uzávěrkového měsíce fiskálního roku
  podle účetního data, ne do běžného měsíce. Kanonický formát: pole
  `fiscalPeriodType` v hlavičce (`docs/exchange-format.md`). Zámek měsíce
  (#55 D27) se otevíracího/uzávěrkového období netýká — beze změny.
- `loadJournalRows()` (nebo filtr v `buildDesired()`): řádky, jejichž
  `fiscal_month` má `period_type = 2`, se do ledgeru nederivují. Řádky
  otevíracího období (0) zůstávají předpisem nového roku (D11).
- `accbal-regenerate` po `ds-upgrade` uzávěrkové řádky odstraní (diff
  desired × existing je maže).

## 3. Lookup podle směru (D19, D14)

`LedgerOpenItemLookup`:

- Cíle nejsou „skupina se směrem“, ale **obě hlavní skupiny** (ty, které
  mají v nastavení řádek předpisu pro 311 resp. 321 — na seedu
  `receivables` + `payables`) v pořadí podle nastavení; zálohy a úvěry za
  nimi jako dosud.
- `loadKeyRows()` načte **všechny** řádky klíče ve skupině (bez filtru na
  prefixy předpisových účtů, bez vyloučení `modify_sign`) — reziduum =
  Σ předpisy − Σ úhrady celé skupiny, shodně s `CaseQuery` (§3.4 „lookup
  je užší“ přestává platit).
- Otevřený případ pro směr: příjem → reziduum > 0 v Pohledávkách **nebo**
  reziduum < 0 v Závazcích (přeplacený závazek / dobropis přijaté faktury
  vracený dodavatelem); výdaj → reziduum > 0 v Závazcích **nebo**
  reziduum < 0 v Pohledávkách (přeplatek zákazníka, dobropis vydané
  faktury). `OpenItem` nese znaménko rezidua; bankovní engine z něj
  odvodí účet a stranu (viz `docs/banking.md` § párování) — kladné
  reziduum se platí jako dosud, záporné znamená vratku: stejný účet,
  opačná strana proti běžné úhradě.
- První zásah vyhrává v pořadí Pohledávky → Závazky pro příjem, Závazky →
  Pohledávky pro výdaj.

## 4. Seed a provisioning (D18, D20)

- `balancesDefault.cz.jsonc`: sign-pravidla zůstávají (výchozí chování
  nového Shipardu); řádky dostanou příznak `"creditNoteRule": true`
  (název upřesni podle konvence seedu).
- `BalancesProvisioner::provision()` dostane volbu `legacy` (bool):
  s `legacy = true` se řádky `creditNoteRule` **nezakládají**.
  `ds-upgrade` volá provisioning jako dosud; import volá s `legacy = true`
  (aplikační rozhraní pro import: `shpd-ds accbal-provision --legacy`,
  nebo parametr existujícího vstupu, kterým import provisioning spouští —
  zjisti v `docs/cli.md` / `docs/import.md`).
- Přepnutí na nové chování je ruční: účetní v nastavení ukončí platnost
  řádků / založí `creditNoteRule` řádky s `valid_from` = 1. den nového
  fiskálního roku. Do `docs/accbal.md` §3.2 přidej postup (bez UI změn;
  formulář řádku už `valid_from`/`valid_to` má — ověř, jinak doplň do
  formuláře).
- Řádky nastavení, které na DS založil zrušený import (321 a 343802
  v Pohledávkách na `btpg-p`), se **nemažou kódem** — nastavení uživatele
  je jeho. Zmizí resetem + reimportem dev DS po task 40 (ops, David); pro
  DS bez resetu je odstraní účetní ručně (zapiš do §12).

## 5. Dokumentace

- `docs/accbal.md`: §3.2 (sign-pravidla = výchozí, `legacy`, ruční
  přepnutí, proč ne uprostřed roku), §4.2 (krok „operace“, mapa operací,
  vyloučení uzávěrkového období), §5.1 (lookup obě skupiny, znaménko
  rezidua), §12 (import bez nastavení, srovnání per fiskální rok proti
  starému `e10doc_balance_journal`, nikdy proti Saldo2; „938“ vysvětleno).
- `docs/accounting.md`: `fiscal_period_type`, které operace určují stranu
  salda.
- `docs/exchange-format.md`: `fiscalPeriodType`.
- `docs/banking.md`: záporné reziduum = vratka.

## Testy (PHPUnit, úzký `--filter`)

- Unit `LedgerGenerator`: (a) 311 DAL −100 s `acc.balanceReceivable` →
  úhrada −100 v Pohledávkách (ne Závazky); (b) 311 MD −100 bez operace →
  podle sign-pravidla Závazky předpis +100 (seed) / Pohledávky předpis −100
  (`legacy`); (c) `payment.receivable` na 311 MD +100 → úhrada −100
  (vratka); (d) řádek nastavení s `valid_to` před účetním datem se
  nepoužije; (e) řádek v měsíci `period_type = 2` se nederivuje, v 0 ano.
- Unit `LedgerOpenItemLookup`: výdaj najde přeplatkový případ
  v Pohledávkách (reziduum −), příjem najde přeplacený závazek; dobropisové
  řádky vstupují do rezidua.
- Unit `DocDocument`: `fiscal_period_type = closing` → uzávěrkový měsíc
  roku podle účetního data; NULL → běžný měsíc (stávající).
- Unit `BalancesProvisioner`: `legacy` nezakládá `creditNoteRule` řádky;
  `addMissingAccounts()` je do existující skupiny nedoplňuje, pokud DS
  byl provisionován jako `legacy` (ulož příznak do skupiny — sloupec
  `provisioning_variant` na `economy_accbal_balances`, nebo `note`; zvol
  a zdokumentuj).
- Test mapy operací proti cfg.

## Hotovo když

- Testy výše zelené; `git diff` po každém patchi.
- Dev DS `btpg-p` po resetu + reimportu (task 40) + `ds-upgrade` +
  `accbal-regenerate --all`: rok 2026 = **112 dluhů** (SELECT z #69
  komentáře 2026-09-21, per fiskální rok), agregát bez partnera 0 řádků,
  321/343802 v Pohledávkách 0, přeplatky ≤ 6 haléřových (ty zůstávají,
  zapiš je do #69 k dalšímu rozboru).
- `e8w1-i`: nové srovnání per fiskální rok proti `e10doc_balance_journal`
  starého DS (ne Saldo2) — výsledek do #69, rozdíly rozepsané po vzorech.
- Alfa: nic — nasazení až po ověření na dev (#69, David).

## Poznámky k implementaci (2026-09-22)

Odchylky od zadání, odsouhlasené při plánování:

- **Legacy seed spouští `ds-upgrade` pod `skipProvisioning`** (= importovaný
  DS), ne nový endpoint ani CLI — import nemá co volat, jen zruší fázi
  `accbal-settings`. Skupina nese `provisioning_variant = legacy`.
- **Legacy = bez `creditNoteRule` řádků a s částkami Všechny.** Seed má
  běžné řádky jen na kladné částky (záporné nechává sign-pravidlům); bez
  nich by dobropis nevyhověl ničemu a ze salda zmizel. Test (b) legacy
  proto předpokládá řádky „Všechny“.
- **Cíle lookupu genericky**: každá skupina s předpisem, přirozená pro
  směr (reziduum > 0) před opačnou (reziduum < 0), bez 311/321 v kódu
  (D3). Reziduum přes účty skupiny bez `modify_sign` — sign-ruled řádky
  výchozího seedu zůstávají lookupu neviditelné (engine účtuje stranu ze
  směru, vratku takového dobropisu by saldo nezařadilo); na legacy seedu
  je to celá skupina.
- **Seed dostal zrcadlové 321 řádky** v Pohledávkách (D18 je předpokládá,
  seed je neměl).
- **Bankovní engine beze změny logiky** — strana ze směru transakce je
  proti běžné úhradě právě „opačná strana“; `OpenItem::residual` nese
  znaménko jen informativně.
- `fiscal_period_type` je `enumString` s cfgItem `docs.core.fiscalPeriodTypes`
  (`opening` / `closing`); applier ho bere jen v import módu.
