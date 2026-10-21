# Demo datové sady

Sady s „žitými“ daty pro ukázku aplikace (přehledy, saldokonto, výsledovka,
Dashboard) a pro screenshoty a videa webu (`shpd-web/docs/demo-scenare.md`).
Formát sady je týž jako u `dataset-dump` / `dataset-seed`
([`docs/datasets.md`](../../docs/datasets.md)); testovací sady z korpusu
dokladů (bez vazby na video) leží v [`tests/datasets/`](../../tests/datasets/).

Sada vzniká **generátorem z ručně psaného příběhu firmy** (#131 D3–D7):
příběh jsou pravidla, generátor je deterministicky rozvine na doklady,
zprávy a dokumenty. Výstupní složka sady se **needituje ručně** — změna
patří do příběhu a sada se přegeneruje. Vše je fiktivní od začátku: žádný
dump ani anonymizace ostrého zdroje dat, video i tento adresář jsou veřejné.

```
demo/datasets/
  build.py                 # vstupní bod generátoru
  templates/               # HTML šablony dokumentů spisovny (smlouva, pojistka, nájem)
  stories/<název>/         # příběh firmy — ručně psané soubory (viz níže)
    build-state.json       # hash HTML → PDF; generátor renderuje jen změněné
  <název>/                 # vygenerovaná sada (manifest, setup/, persons/, items/, workOrders/, assets/, docs/, bank/, registry/, mail/)
```

## Sady

| Sada | Příběh | Obsah |
|---|---|---|
| `it-servis/` | `stories/it-servis/` | Zelený Terminál s.r.o., malá IT firma, měsíční plátce DPH, leden–říjen 2026: 14 odběratelů (2 SK, 1 DE v EUR s přenesením daňové povinnosti), 136 vydaných faktur a zálohová faktura (4 koncepty v měsíci kotvy, 3 placené hotově; paušály, servisní práce každý měsíc, prodej hardware s maržou), 59 přijatých faktur od 8 dodavatelů jako zpracované zprávy s PDF (10 od nizozemského dodavatele cloudové zálohy v EUR se samovyměřením a anglickým PDF — #131 D28; jedna samostatná faktura velkoobchodu za server), 39 prodejek, 20 výdajových pokladních dokladů, 9 mzdových dokladů, 6 dokumentů spisovny (dvě smlouvy končí do 30 dnů), 3 otevřené návrhy faktur a e-mail s lhůtou pro kartu K vyřízení; **banka** (fáze 2a + D26): 20 výpisů dvou účtů (BU1 v CZK, BU2 v EUR pro slovenské a německého odběratele a úhrady dodavatele v EUR) s úhradami faktur podle chování odběratelů a dodavatelů (včas / pozdě / částečně s doplatkem / vůbec), 3 záměrně nespárovanými platbami (bez VS, překlep VS), výplatou mezd a odvody s vyrovnávacími interními doklady, poplatky, 6 převody z eurového účtu na korunový a otevíracím dokladem počátečního zůstatku 300 tis. — po seedu je většina pohledávek uzavřená, pár po splatnosti, saldokonto a přehled nespárovaných plateb mají co ukázat; **ekonomika** (D25): výnosy ≈ 2,09 mil., zisk ≈ 12 % výnosů, ve ztrátě jen červen (odměny) a srpen, zahraničí 17 % tržeb; **majetek** (fáze 2c, D41): karta serveru MA0001 pořízená fakturou velkoobchodu v únoru (150 000 Kč bez DPH, řádek pořízení s kartou), zařazená 14. 2. 2026, rovnoměrně v 1. odpisové skupině, účetně jako daňově po měsících — nic nezaúčtované, Odpisy za období nabídnou zaúčtování od února a hosting serveru je v Nákladech a výnosech karty |

## Generování

```bash
python3 demo/datasets/build.py                                   # stories/it-servis → it-servis/
python3 demo/datasets/build.py --anchor 2027-01-20 --out /tmp/it-servis-posun
python3 demo/datasets/build.py --verify-identities               # ARES / VIES (síť)
```

- Po sestavení `build.py` vypíše **souhrn ekonomiky příběhu** (#131 D25,
  `invoicegen/economy.py`): výnosy, náklady (mzdy, dodavatelé, pokladna,
  poplatky), výsledek a zisk v % výnosů, měsíce ve ztrátě, podíl zahraničních
  odběratelů, prodej a nákup zboží, počáteční / nejnižší / konečný zůstatek
  účtů, výnosy a náklady projektů a kartu majetku (vstupní cena, zařazení,
  plán účetních odpisů v okně a výsledek po jejich zaúčtování — fáze 2c)
  — ze stejných záznamů, ze kterých vznikají soubory. Cíle (zisk 8–15 %
  i po zaúčtování odpisů, ztrátové nejvýš 3 měsíce, zahraničí 10–20 %,
  zboží ≥ 1,15× nákup, BU1 začíná nejvýš na 300 tis. a nikdy pod nulou)
  hlídá `tools/invoice-generator/tests/test_economy.py` nad výchozí kotvou;
  změna částek v příběhu musí projít tímto testem.
- Druhý běh beze změny příběhu nic nezapíše (`git status` čistý): JSON se
  zapisuje jen při změně bajtů, PDF se renderuje přes Gotenberg
  (`GOTENBERG_URL`, `docs/render.md`) jen když se změní HTML nebo soubor
  chybí — hash drží `stories/<název>/build-state.json`. Chromium nedává
  bajtově stejné PDF, proto se PDF commitují a cache je součást repa.
- `--anchor` posune celou sadu v čase (D7): data příběhu jsou relativní
  ke kotvě (měsíc vůči měsíci kotvy, den v měsíci, splatnost ve dnech),
  `manifest.created` = kotva o půlnoci UTC, registrace DPH od prvního dne
  dat. Posunutá sada patří do dočasné složky (`--out`), do repa jen sada
  s výchozí kotvou. Posun o jiný počet měsíců než násobek tří mění počet
  čtvrtletních dokladů (čtvrtletí jsou kalendářní).
- `--verify-identities` ověří fiktivní identity: IČO v ARES neexistují,
  názvy mají 0 zásahů, DIČ ve VIES neplatná; při úspěchu zapíše
  `verifiedAt` do `partners.jsonc`. VIES pro CZ bývá přetížené
  (`MS_MAX_CONCURRENT_REQ`) — pak příkaz skončí kódem 2 a nic nezapíše.
  CZ DIČ s volným IČO platné být nemůže; rozhodující je ARES a VIES pro
  zahraniční DIČ.

Generátor žije v balíku `invoicegen` (`tools/invoice-generator/`,
`README.md` tamtéž): `story.py` (loader a validace), `expand.py`
(rozvinutí, model plateb, zakázky, majetek), `workorders.py` (porty `PeriodLabel`,
`DocTextTemplate` a `PeriodCalendar` periodické fakturace), `assets.py`
(port měsíčního plánu účetních odpisů nad rovnoměrnou metodou), `bank.py`
(výpisy, otevírací a vyrovnávací doklady), `doccalc.py` (výpočet jako
`DocDocument`), `dumpshape.py` (tvar dumpu), `demo.py` (sestavení). Testy: `cd tools/invoice-generator &&
python3 -m unittest`.

## Formát příběhu (`stories/<název>/`)

Čísla jen celá nebo s desetinnou tečkou (loader je čte jako `Decimal`).
Data relativně: `month` = posun od měsíce kotvy (0 = měsíc kotvy,
záporné = minulost), `day` = den v měsíci (delší než měsíc se ořízne),
`dueDays`, `afterAnchorDays`, `daysBeforeAnchor`.

| Soubor | Obsah |
|---|---|
| `story.jsonc` | `name`, `title`, `description`, `anchor`, `monthsBefore`; `own` — identita vlastní firmy (kanonický tvar jako `tests/corpus/cz/identities.jsonc`) + `language`, `bankAccounts` (měna → kód účtu ze `setup/`, CZK povinná; faktura v EUR nese eurový účet a platby za ni chodí na něj — U1), `cashDeskCode`; `rates` — kurz cizí měny per měsíc (D13); `invoicing` — den vystavení, splatnost a `workOrders` (fáze 2b, D32–D34: `kind` a `series` paušálů ze `setup/`, `title` zakázky s `{customer}`, `docText` s `{období}` a `rowText` předpisu per jazyk odběratelů); `billing[]` — opakovaná fakturace per odběratel (`every` month / quarter / once, `item`, `price`, `qty`, `priceFrom`, `docType: proformaIssued`, `conceptInAnchorMonth`, `since` = měsíc zahájení zakázky před oknem — `month` a `quarter` jsou periodické zakázky, `once` zůstává mimo); `projects` (D36) — `kind`, `series` a `items[]` projektů (`key`, `customer`, `title`, `startDaysBefore`, `purchases` {supplier, month, rows [{item zboží, text, unitPrice, offerItem}]}); `assets` (fáze 2c, D41) — `typeGroup` a `type` ze `setup/` (typ dlouhodobý hmotný s úplnou účetní skupinou) a `items[]` karet (`key`, `number` = inventární číslo, `name`, `taxMethod: straight`, `taxRule` = odpisová skupina ze `world.assets`, `accMethod: as_tax`, `purchase` {supplier s pravidlem faktur, month mimo jeho měsíce, day, unitPrice, qty?, text?, rows[] běžného nákupu s `text`, `unitPrice`, `offerItem`?} = samostatná faktura s řádkem pořízení, `activation` {daysAfterDelivery} = zařazení ve výši základu řádku, `costs` {supplier} = faktury dodavatele od první po zařazení s kartou na řádcích); `adhoc[]` — jednorázové faktury (řádky s `qty` nebo `hours: [lo, hi]`, volitelný `text`, `project` = klíč projektu, `payment: cash`, `state: concept` jen v měsíci 0); `cashRegister` — dny prodejek, zboží, rozsah kusů; `cashExpenses[]` — výdajové pokladní doklady na položky z nabídky osnovy; `payroll` — den, částky (`figures`, přepisy per měsíc), kontační řádky (loader hlídá MD = D); `bank` (fáze 2a) — `accounts` per kód účtu (`openingBalance` — účet v cizí měně jen 0; `fee` {amount, text} = poplatek poslední den dokončeného měsíce), `openingAccount` (701) a `clearingAccount` (261300), `settlementText`, `payroll[]` = platby mezd a odvodů (`amount` = částka z `payroll.figures` s řádkem na straně D, `day` v měsíci následujícím po mzdách, `text`, `counterparty` {account, name} — protiúčet nesmí patřit nikomu v sadě, D22), `transfers[]` = převody mezi vlastními účty (D26: `from` účet v cizí měně, `to` účet v CZK, `day` v každém měsíci, převede se zůstatek nad `reserve` zaokrouhlený dolů na `step`, jen když dělá aspoň `minAmount`; `text` s `{period}`; CZK strana = částka × kurz měsíce, pohyby `transfer.out` / `transfer.in`). Cena položky z `items.jsonc` (CZK) se na faktuře v cizí měně přepočítá kurzem měsíce vystavení (D27); vlastní `price` v `billing[]` je cena v měně faktury |
| `partners.jsonc` | `verifiedAt`; `customers` (identita + `language`, `currency`, `vatTreatment` standard / reverseCharge, kontakt s `sendPurposes`, `payment` = chování plateb D8/D18: `onTime` 2 dny před splatností, `late` + `days`, `partial` + `pct` (+ `remainderDays` = doplatek, jinak nikdy), `never`; `unmatched[]` {month, reference missing / typo} = platba faktury toho měsíce bez VS nebo s překlepem — záměrně nespárovaná se známým protiúčtem); `suppliers` (identita + `template` a `accent` šablony `demo/invoices/templates/` (`en` = anglická šablona korpusu), `docNumber` vzor čísla dodavatele, `invoices` pravidlo faktur: `every` / `months`, `day`, `dueDays`, `anchorMonth` processed / open / skip, `period: previousMonth`, `rounding: czk`, řádky s kódem nabídky účetních položek; `payment` = jak platíme my: `onTime` den před splatností, `late` + `days`, bez pole včas; zahraniční dodavatel (D28): `language` en, `currency` EUR (kurz z `story.jsonc`, úhrada z eurového účtu kurzem faktury), `vatTreatment` reverseCharge — řádky se `supplyKind: services` a `vatPct` 21 = sazba samovyměření, doklad dostane kód cz-217 s párem cz-207, papír tiskne 0 % a klauzuli reverse charge; bez polí = česky, CZK, standard) |
| `items.jsonc` | vlastní prodejní položky: kód, název, druh (`service` / `stock`), jednotka (`core.units`), cena, sazba, výnosový účet. Účetní položky pro přijaté faktury přidá seed z nabídky osnovy (`provision.accountingItems: "all"`) |
| `registry.jsonc` | dokumenty spisovny: druh, šablona z `templates/`, šanon, partner, `refNumber`, `created` {month, day, time}, `validFrom`, `validTo` ({afterAnchorDays} / {years} / {month, day}), `summary`, `kindFields` podle druhu (promote klíče doplní generátor) |
| `mail.jsonc` | zprávy k řešení bez dokladu (`kind: other`) s pozorností (`attention`, `actionNote`, `dueInDays` od kotvy); návrhy faktur vznikají z `anchorMonth: open` u dodavatelů |
| `setup/*.jsonc` | číselníky ve tvaru dumpu (všechny sloupce vč. `null`): nastavení (vč. `economy.accounting.dimension.workOrder: yes` a `economy.accounting.dimension.asset: yes` — pole Zakázka a Majetek na dokladech, `economy.assets.accPeriodicity: month` — měsíční účetní odpisy; **bez** id řad dokladů `economy.assets.accountingSeries` / `economy.vat.filingAccountingSeries` — ta po resetu nastaví provisionery, D42), nesystémové účty osnovy (`accounts` — analytika 221200 pro eurový účet, výchozí osnova má jen 221100), bankovní účty (BU1 CZK, BU2 EUR, každý s `accounting_account` = analytika 221), pokladna, registrace DPH, šanony, střediska (prázdné), schránka, druhy a řady zakázek (`work_order_kinds` — periodický druh s řadou dokladů `{docType, code}` D37 a účtem kódem, druh projektů; `work_order_series` — řady `S` a `P` průběžné, druh názvem), číselníky majetku (`asset_accounting_groups` — všech sedm skupin provisioneru s účty číslem, `asset_type_groups`, `asset_types` — typ „Výpočetní technika“ se skupinou typů názvem a výchozí účetní skupinou `022` kódem). `@period.start` v datu = první den dat |

Pravidla, která loader vynucuje: kontrolní součty IČO / účtů / IBAN / DE
DIČ, domény jen `.example` (D15), telefony s nepřidělitelným předčíslím 000 za kódem země, žádný překryv s identitami dema
(`demo/invoices/suppliers.jsonc`) ani korpusu (`tests/corpus/cz/identities.jsonc`),
odkazy partner ↔ položka ↔ nabídka osnovy, měsíce v období, vyvážené
mzdy, účet per měnu odběratelů i dodavatelů, kurz pro každý měsíc u každé
cizí měny v příběhu, protiúčet mezd nikomu nepatřící, převody jen z účtu
v cizí měně na účet v CZK, samovyměření jen u zahraničního dodavatele
s DIČ (řádky se `supplyKind: services`, šablona v jazyce dodavatele),
a **jednoznačné české řazení jmen** — o pořadí souborů osob
(`ORDER BY full_name` v `utf8mb4_czech_ci`) nesmí rozhodovat jen diakritika,
velikost písmen ani interpunkce; takové jméno autor přejmenuje. Rozvinutí
(`expand`) navíc hlídá, že zůstatek žádného účtu neklesne pod nulu (jinak
řekne, o kolik zvýšit `openingBalance`), že každá platba mezd má
vyrovnávací doklad, že překlep VS nekoliduje s číslem dokladu, že kurz
nese jen transakce na účtu v cizí měně a že obě strany převodu jsou téhož
dne s CZK částkou přesně EUR × kurz (peníze na cestě nulové); doklad
v cizí měně bez kurzu je chyba, ne tichý kurz 1. Zakázky: druh a řada
paušálů i projektů jsou v `setup/` (řada průběžná), `since` leží před
oknem, texty pokrývají jazyky odběratelů, každý projekt má fakturu
s `project`, nakupované položky se na nich prodávají a nákup předchází
dodávce. Majetek: typ a skupina typů v `setup/`, výchozí účetní skupina
typu úplná (účet majetku, pořízení, oprávek, odpisů), nastavení měsíčních
odpisů a pole Majetek, inventární číslo jedinečné, jen rovnoměrná metoda
v odpisové skupině ze `world.assets` a účetně jako daňově, nákup od
tuzemského dodavatele v CZK v měsíci, kdy podle pravidla nefakturuje,
zařazení nejpozději v den kotvy; rozvinutí hlídá, že faktura s pořízením
je V pořádku a zařazení není před dodáním.

## Jak sadu naseedovat a ověřit

Z vývojářského dashboardu: formulář **Nový zdroj dat** (pole **Data**,
skupina **Demo sady**) nebo akce **Nahrát datovou sadu** nad existujícím
volným zdrojem dat; sekce Testování demo sady nevypisuje. Z terminálu:

```bash
php bin/shpd-server ds-create --name "Demo IT servis" --language cs --country cz --no-cron
cd /opt/shipard/data-sources/<id>
php /cesta/k/repu/bin/shpd-ds dataset-seed /cesta/k/repu/demo/datasets/it-servis -y
php /cesta/k/repu/bin/shpd-ds dataset-dump /tmp/it-servis-dump --force --name it-servis \
    --title "Demo — IT servis" --description "<description z manifestu>"
diff -r /cesta/k/repu/demo/datasets/it-servis /tmp/it-servis-dump   # jen manifest.created
```

Sada je s dumpem naseedovaného zdroje dat **bajtově shodná** kromě
`manifest.created` (D10); každý další rozdíl je chyba generátoru, ne dumpu.
Očekávaná varování seedu: založení účetních položek z nabídky a chybějící
IČO německého odběratele. Dump varuje per EUR doklad, že kurz není
v lístcích — kurz nese doklad sám (D13), lístky sada nemá.

Co má po seedu sedět (fáze 2a + ekonomika D25–D28 + zakázky 2b + majetek
2c, ověřeno 2026-10-21): deník 1 364 řádků, MD = D bez chybových řádků,
všech 235 transakcí zaúčtovaných bez chyby, partner dohledaný u každé platby faktury (SK / DE
odběratelé a NL dodavatel podle IBAN), bez partnera jen mzdy, odvody,
poplatky a převody mezi vlastními účty; 261100 Peníze na cestě po 6
převodech nulový (obrat MD = D), 261300 nulový, 343217 MD = 343207 D
(samovyměření 10 faktur dodavatele z EU, DPH na vstupu i výstupu v CZK
kurzem faktury); saldokonto Pohledávky 129 případů (112 uzavřených, 17
otevřených — 16 po splatnosti vč. částečně uhrazených, 1 před splatností
v EUR), Závazky 58 uzavřených (vč. 10 v EUR bez rezidua v CZK) a 1 se
splatností za kotvou, Nespárované platby jen 3 záměrné příjmy (případ mezd
s prázdným klíčem uzavřený), Přijaté zálohy 1 otevřená (záloha čeká na
vyúčtování), zůstatek 221100 = konečný zůstatek posledního výpisu BU1
a 221200 v EUR = konečný zůstatek BU2; Výsledovka „Od počátku roku“ =
souhrn generátoru (výnosy 6xx a náklady 5xx se liší jen o haléřová
zaokrouhlení prodejek na 548 / 648; pořízení serveru je na 042100, ne
v nákladech). Zakázky: 15 (13 periodických
V pořádku, 2 projekty Ukončeno), 112 vystavených období s dokladem
a otiskem, 112 vydaných faktur se zdrojem *periodická fakturace*,
`work-orders-invoice-run --dry-run --date=<kotva>` nic nevystaví, deník
projektů nese výnosy i náklady, upozornění *Faktury z periodické
fakturace ke kontrole* ukazuje koncepty měsíce kotvy. Majetek: karta
MA0001 V pořádku se zařazením 14. 2. 2026 (původ *Ručně*, nezaúčtované),
sekce Pořízení = faktura velkoobchodu 150 000 Kč na 042100, upozornění
majetku 0, report Kontrola evidence × deník za 2026 jen s varováním
o nezaúčtovaném zařazení (042100 evidence = deník), dialog Odpisy za
období v účetním okruhu nabídne únor (zařazení MD 022100 / D 042100)
a březen (3 000 Kč), tab Náklady a výnosy = 8 faktur hostingu 39 200 Kč.
Zdroj dat vzniklý před fází 2a potřebuje `ds-upgrade` (zrcadlové řádky
skupiny Nespárované platby, `docs/accbal.md` § 4.4) a `accbal-regenerate
--all`.

Výchozí schránka: `ds-upgrade` ji zakládá s adresou podle ID zdroje dat,
sada ji přepíše pevnou `demo-it-servis@shipard.email`, aby dump vyšel
stejně na každém zdroji dat. Demo zdroj dat nemá přijímat skutečnou poštu;
kdo to potřebuje, opraví adresu v Nastavení.

## Banka (fáze 2a, převody D26) a její meze

Výpisy (`bank/`) nesou jen to, co dodá banka (#131 D17): partnera dohledá
aplikace z protiúčtu, párování s fakturou mikroengine podle partnera a VS.
Platby mezd a odvodů jdou bez partnera a VS na clearing 261300; generátor
ke každé vyrobí vyrovnávací interní doklad (MD závazek / DAL 261300), který
případ s prázdným klíčem vynuluje (D22). Poslední výpis končí dnem kotvy,
poplatek za nedokončený měsíc ještě není. Eurový účet začíná na nule,
přijímá platby odběratelů v EUR a platí dodavatele v EUR; kurz platby =
kurz hrazené faktury, takže v CZK nevzniká reziduum (kurzové rozdíly
saldokonto nemá). Přebytek nad rezervou odchází **převodem na korunový
účet** (`transfer.out` na BU2 s kurzem měsíce, `transfer.in` na BU1 v CZK
= EUR × kurz téhož dne): obě strany jdou na 261100 Peníze na cestě, který
po zaúčtování vyjde nulový; protiúčet je náš druhý účet, partnera nemá
(vlastní firma v sadě bankovní spojení nenese).

- **Ekonomika příběhu je zisková** (#131 D25): zisk ≈ 13 % výnosů se
  mzdami beze změny, ztrátový jen červen s odměnami a srpen; počáteční
  zůstatek BU1 300 tis. Souhrn vypisuje `build.py`, cíle hlídá
  `tests/test_economy.py`. Jiná ekonomika = jiný příběh, ne jiný generátor.
- **Samovyměření jen u služeb z EU** (kód cz-217 s párem cz-207); zboží
  z EU (cz-215), snížená sazba ani zahraniční dodavatel bez DIČ v sadě
  nejsou. Faktura dodavatele v EUR vzniká z anglické šablony korpusu
  (`demo/invoices/templates/en.html`, hodnoty `invoicegen/corpusprint.py`).
- **Posun kotvy přes hranici fiskálního roku** (např. `--anchor 2027-01-20`):
  generátor projde, ale platby za faktury minulého roku zůstanou po seedu
  v Nespárovaných — dohledání předpisu běží v účetním období transakce
  (`docs/bank.md` § 6.1) a otevírací doklady saldokonta sada nemá. Sada
  v repu leží celá v jednom roce.
- Aritmetika dnů (splatnost + N dní) může při posunu kotvy přesunout
  platbu přes hranici měsíce; obsah za celé období je stejný.
- DPH se do výpisu nedává (D22, otevřený bod v #131), úhrady řádkem
  pokladního dokladu sada nemá (řádkový partner se do formátu nepřenáší,
  D21, `docs/datasets.md` § 4).

## Zakázky (fáze 2b) a cron po seedu

Paušály jsou **periodické zakázky** (#131 D19, D32–D34,
`tasks/demo-dataset-phase2b-work-orders.md`): 13 zakázek V pořádku druhu
„Správa IT — paušál“ řady `S` (10 měsíčních, 3 čtvrtletní), zahájené
různě před oknem (`since`), „fakturovat od“ = první období v okně.
Faktury paušálů vypadají jako z periodické fakturace: vystavení = DUZP =
začátek období, splatnost 14 dní, `periodFrom` / `periodTo`, text dokladu
„Správa IT — paušál leden 2026“ (štítek období jako `PeriodLabel`
v jazyce odběratele — sk „január 2026“, de „Januar 2026“, čtvrtletí
„1. čtvrtletí 2026“), popis řádku z předpisu („Správa IT — měsíční
paušál“), zdroj *periodická fakturace* a dimenze zakázky na hlavičce.
Změna ceny hotelu = dva řádky předpisu s platností. Zahraniční zakázky
jsou v EUR s účtem BU2. Evidence období (`workOrders/*.jsonc` `periods[]`)
je úplná: každé období od „fakturovat od“ do kotvy má doklad, koncepty
v měsíci kotvy jsou vystavená období s dokladem v Konceptu, takže
`shpd-ds work-orders-invoice-run --dry-run --date=<kotva>` nic nevystaví.
Zálohová faktura (`once`) a jednorázové faktury zakázku nemají.

**Projekty** (D36): dvě zakázky Ukončeno druhu „Projekty“ řady `P` —
„Výměna sítě“ (galvanovna, faktury v březnu a dubnu, nákup routeru a switchů
u velkoobchodu v dubnu) a „Wi-Fi síť hotelu“ (jelen, faktury v květnu a
červnu, nákup přístupových bodů v červnu, dodávka až po nákupu). Faktury
projektu nesou dimenzi zakázky na hlavičce, nákupní řádky na faktuře
velkoobchodu na řádku; množství nákupu = prodané kusy. Deník zakázky tak
ukazuje výnosy i náklady, souhrn `build.py` vypisuje marži projektů (obě
kladné).

**Cron po seedu (D35).** Zakázky jsou V pořádku, takže zdroj dat se zapnutým
cronem (`shpd-ds ds-state cron --on`) vystavuje po kotvě další období jako
koncepty (denní běh periodické fakturace) a po třech zmeškaných obdobích
zastaví pojistka dohánění s upozorněním *Dohánění zastaveno*. Pro web proto
platí: čerstvý build s posunutou kotvou (`build.py --anchor <datum>`),
ne sada s kotvou v minulosti.

## Majetek (fáze 2c)

Jedna karta dlouhodobého hmotného majetku (#131 D20, D38–D42,
`tasks/demo-dataset-phase2c-assets.md`): **server** koupený samostatnou
fakturou velkoobchodu 10. 2. 2026 (150 000 Kč bez DPH, řádek s pohybem
*Pořízení majetku* a kartou na řádku, k tomu montážní sada jako běžný
nákup; faktura je zpracovaná zpráva s PDF jako ostatní, uhrazená podle
chování dodavatele), **zařazený** 14. 2. 2026 ve výši základu řádku
pořízení — karta V pořádku, 1. odpisová skupina rovnoměrně, účetně jako
daňově, měsíční účetní odpisy (nastavení). Hosting serveru nese od první
faktury po zařazení (březen) kartu na řádcích, takže tab Náklady a výnosy
na kartě ukazuje 8 faktur.

Sada nese **evidenci, ne zaúčtování** (D38): kartu a potvrzené zařazení
s původem *Ručně* (seed jde přes dokumenty bez importního módu, původ
*Import* by kontrola evidence × deník brala jako zaúčtované ve starém
systému), žádné odpisy ani účetní doklady majetku. Po seedu tak svítí
štítek *Nezaúčtováno*, kontrola evidence × deník za 2026 hlásí jen
nezaúčtované zařazení a dialog **Odpisy za období** v účetním okruhu
nabídne únor (zaúčtování zařazení MD 022100 / D 042100) a dál měsíční
odpisy 3 000 Kč (150 000 × 20 % / 10 měsíců březen–prosinec, od roku 2027
5 000 Kč); daňový odpis 2026 = 30 000 Kč. Souhrn `build.py` vypisuje
plán v okně (8 × 3 000 = 24 000 Kč) a výsledek po zaúčtování (≈ 11 %
výnosů). Dump zaúčtovaného zdroje doklady s řádky `asset.*` vynechá
s varováním — po seedu je zakládají Odpisy za období znovu.

**Id řad dokladů v nastavení (D42).** `economy.assets.accountingSeries`
a `economy.vat.filingAccountingSeries` sada nenese: hodnota z provozu
před resetem přežije reset (`core_system_settings` je `keepOnReset`),
`ds-upgrade` pak řadu „Majetek“ nezaloží a nastavení ukáže na pokladní
řadu vzniklou při seedu — zaúčtování majetku skončí *series_missing*.
`dataset-seed` obě nastavení před resetem smaže, provisionery je po
resetu nastaví znovu. Zdroj dat naseedovaný před touto opravou (sady
fáze 2a / 2b) to má rozbité: v Nastavení → Majetek → Odpisy chybí řada
„Majetek“ — buď `dataset-seed` znovu, nebo řadu `cmnbkp` s kódem `MA`
založit ručně a vybrat ji.
