# Demo sada „IT servis“ — ekonomika příběhu a převody EUR → CZK

**Stav:** naplánováno — zadání podle #131 D25–D28, čeká na implementaci

## Kontext / Cíl

Po fázi 2a (`tasks/demo-dataset-phase2a-bank.md`) se ukázalo, že ekonomika
příběhu „IT servis“ nesedí:

- **Chyba generátoru:** u faktur v EUR bere řádek z položky cenu z katalogu
  v CZK bez přepočtu (`expand.py`, `_item_row` → `item.price`). Monitor za
  7 490 Kč je na faktuře slovenskému odběrateli za 7 490 EUR, hodina práce
  německému odběrateli za 1 250 EUR. Zahraniční tržby jsou tím přes 60 %
  všech tržeb a na eurovém účtu se nastřádalo 52 100 EUR.
- **Skutečná ekonomika je ztrátová.** Po opravě přepočtu vychází výnosy
  za období zhruba 1,3 mil. proti nákladům 1,77 mil. (mzdy 1,13 mil.,
  dodavatelé 0,63 mil.). Výsledovka by ukázala ztrátu asi 0,5 mil.
  Chyba přepočtu to dnes zakrývá.
- **Korunový účet** platí všechny náklady, eura se nikdy nepřevedou —
  BU1 klesá a drží ho jen počáteční zůstatek 1,25 mil.

Sada je pro web a screenshoty, takže má ukazovat zdravou malou firmu.

## Návaznost

- **#131** — D1–D24 (komentáře v issue), tento task D25–D28
- `tasks/demo-dataset-phase1.md`, `tasks/demo-dataset-phase2a-bank.md` —
  generátor, příběh, banka, Odchylky
- `docs/bank.md` § 6.2 (`transfer.in` / `transfer.out`, kategorie
  `cash.transit`), § 6.3 (účet peněz na cestě)

## Před implementací přečti

- `demo/datasets/stories/it-servis/` (story, partners, items)
- `tools/invoice-generator/invoicegen/expand.py`, `bank.py`, `story.py`
- `docs/bank.md` § 6.2, § 6.3; `docs/accbal.md` (které skupiny sledují
  účet peněz na cestě — podle zjištění z fáze 2a žádná)

## Rozsah

**V rozsahu:**

- oprava přepočtu ceny položky na měnu faktury (D27)
- nové částky v příběhu podle cílů D25
- převody EUR → CZK mezi vlastními účty (D26)
- zahraniční dodavatel služby s fakturami v EUR placenými z BU2 (D28)
- souhrn ekonomiky z generátoru (kontrola cílů)
- přegenerovaná sada, testy, ověření

**Mimo rozsah:**

- zakázky (fáze 2b) — paušály zůstávají v `billing` příběhu; 2b je
  převezme i s novými částkami
- kurzové rozdíly (převod i platby jedou kurzem z příběhu)
- mzdy po zaměstnancích, DPH platby (D22)

## Návrh

### D27 — Přepočet ceny položky na měnu faktury

Cena položky v `items.jsonc` je v CZK. Na faktuře v cizí měně je jednotková
cena = cena v CZK ÷ kurz měsíce vystavení (`rates`), zaokrouhleno na celé
eurocenty (`ROUND_HALF_UP`). Platí pro paušály bez vlastní ceny,
jednorázové řádky (hardware, hodiny) i cokoli dalšího, co bere cenu
z katalogu. Pravidlo s vlastní `price` u odběratele v cizí měně je cena
v měně faktury (jako dnes) — v komentáři příběhu to výslovně napsat.

Loader / validace: faktura v cizí měně, jejíž řádek bere cenu z katalogu
bez kurzu, je chyba (žádný tichý fallback na kurz 1).

### D25 — Cíle ekonomiky příběhu

Konkrétní částky navrhne implementace; příběh musí splnit:

- **Výsledek za období kladný, zisk 8–15 % výnosů** (podle Výsledovky,
  sloupec Od počátku roku k měsíci kotvy).
- **Jednotlivé měsíce smí být ve ztrátě** (měsíc s odměnami, nákup
  hardwaru), nejvýš 2–3 měsíce z deseti.
- **Zahraniční odběratelé 10–20 % tržeb.** Paušál německého odběratele
  snížit z 1 250 EUR na úroveň srovnatelnou s největšími tuzemskými
  klienty (řádově 600–700 EUR měsíčně).
- **Tuzemské paušály** realistické pro správu IT malých firem; přírůstek
  tržeb hlavně odsud (zvýšení paušálů, případně víc jednorázové práce).
- **Hardware se prodává s maržou:** prodej zboží za období ≥ nákup od
  velkoobchodu × 1,15 (dnes se nakupuje víc, než prodá).
- **Mzdy** beze změny, pokud cíle jde splnit tržbami; jinak navrhnout
  v Odchylkách.
- **Počáteční zůstatek BU1** nejvýš 300 tisíc; zůstatek BU1 nesmí klesnout
  pod nulu (validace z 2a zůstává).
- Saldokonto má dál co ukázat: chování plateb odběratelů (pozdě, částečně,
  neuhrazeno, nespárované) se nemění.

Komentář v `story.jsonc` „příběh je na bance ztrátový“ nahradit popisem
ekonomiky (výnosy, náklady, zisk v %, podíl zahraničí).

### D26 — Převody EUR → CZK

Pravidelný převod mezi vlastními účty: z BU2 odejde částka v EUR
(`operation: transfer.out`), na BU1 přijde v CZK (`transfer.in`) téhož dne.

- Pravidlo v příběhu (`bank.transfers`): čtvrtletně, nebo když zůstatek
  BU2 přesáhne hranici — navrhne implementace; na BU2 má zůstat provozní
  rezerva, ne nula.
- Částka v CZK = částka v EUR × kurz transakce na BU2 (kurz měsíce
  z `rates`), aby účet peněz na cestě vyšel nulový. Kurzový rozdíl banky
  se nemodeluje.
- Protiúčet = náš druhý účet; operace výslovně v sadě (exportér ji
  vypíše, výchozí `payment.*` ne — jako `fee.out`).
- Ověř, že protiúčet vlastního účtu nedohledá partnera (nebo že partner
  nevadí) a že obě strany zaúčtuje mikroengine bez chyby.

### D28 — Zahraniční dodavatel v EUR

Nový dodavatel služby ze země EU (např. cloudová záloha nebo monitoring
jako předplatné), měsíční faktura řádově 150–250 EUR:

- přijatá faktura v EUR s přenesením daňové povinnosti (daň přiznává
  příjemce — `reverseCharge`, pár řádků podle `docs/exchange-format.md`
  § 8.4); kurz měsíce z `rates`;
- jako ostatní přijaté faktury: zpracovaná zpráva s PDF + navázaný doklad
  (D9), PDF ze šablon `demo/invoices/` — text faktury v angličtině nebo
  němčině podle země dodavatele;
- úhrada **z BU2**, kurz transakce = kurz hrazené faktury (jako U1),
  VS = číslo faktury dodavatele, protiúčet = IBAN dodavatele;
- identita: vymyšlený název, VAT ID ověřené ve VIES jako neplatné, doména
  `.example`, telefon s předčíslím `000` (pravidla z `partners.jsonc`).

Platby dodavateli z eurového účtu sníží potřebu převodů (D26), ale
nenahradí je — eurové tržby zůstanou vyšší než eurové náklady.

### Souhrn ekonomiky

`build.py` po sestavení vypíše krátký souhrn: výnosy, náklady, výsledek
a zisk v %, měsíce ve ztrátě, podíl zahraničních tržeb, nákup a prodej
zboží, nejnižší zůstatek BU1 a BU2. Výpočet ze stejných dat, ze kterých
vznikají soubory (ne čtením výstupu). Cíle D25 hlídá unit test nad
výchozí kotvou.

## Kroky (commity)

1. **Přepočet ceny (D27)** v generátoru + validace + unit testy (EUR
   faktura s katalogovou položkou, zaokrouhlení, chybějící kurz = chyba).
2. **Souhrn ekonomiky** v `build.py` + test cílů D25 (zatím padá).
3. **Převody (D26) a dodavatel v EUR (D28)** v generátoru + unit testy
   (párování transfer.out / transfer.in, součty výpisů, peníze na cestě
   nulové, úhrada EUR faktury z BU2 bez rezidua v CZK).
4. **Nové částky v příběhu** — test cílů projde; sada přegenerovaná;
   ověření; `demo/datasets/README.md`, `**Stav:**`,
   `python3 scripts/tasks-index.py`.

## Ověření

Čerstvý volný zdroj dat, `dataset-seed`, `dataset-dump`, `diff -r` jako
v 2a (jen `manifest.created`), a dál:

- Výsledovka, Od počátku roku k měsíci kotvy: zisk v pásmu D25 — čísla do
  Odchylek; Přehled firmy ukazuje totéž;
- žádná transakce s chybou účtování; převody zaúčtované na peníze
  na cestě, účet peněz na cestě nulový;
- přijaté faktury zahraničního dodavatele: přenesení daňové povinnosti
  zaúčtované (daň na vstupu i výstupu), úhrady z BU2 uzavřely případy
  na Závazcích bez rezidua v CZK; v náhledu Přiznání DPH za měsíc kotvy
  se plnění objeví na řádcích pro přijetí služby z EU (zapsat, co bylo
  vidět — přiznání se nepodává);
- zůstatky 221100 / 221200 = konečné zůstatky posledních výpisů;
- saldokonto: stejné kategorie případů jako v 2a (uzavřené, částečně,
  po splatnosti, záměrně nespárované) — počty do Odchylek.

Ověřovací zdroj dat po kontrole smaž, nebo ho nech Anně a zapiš prefix.

## Pasti

- **Kurz převodu.** CZK strana převodu musí být přesně EUR × kurz BU2
  transakce (zaokrouhlení na haléře ve stejném směru jako mikroengine),
  jinak zůstane haléřový zůstatek na penězích na cestě.
- **Posun kotvy** nesmí rozbít cíle: test cílů běží nad výchozí kotvou,
  pro posunutou kotvu stačí validace zůstatků.
- **Změna řady souborů.** Nové částky změní skoro všechny doklady i PDF
  přijatých faktur (pokud se mění dodavatelé); to je očekávané. PDF cache
  přerenderuje jen změněné.
- **Fiktivní data** platí dál (žádné reálné subjekty, telefony `000`).

## Hotovo když

- [ ] faktury v EUR mají ceny katalogových položek přepočtené kurzem
- [ ] souhrn ekonomiky z `build.py` splňuje D25, test cílů projde
- [ ] převody EUR → CZK ve výpisech obou účtů, peníze na cestě nulové
- [ ] faktury zahraničního dodavatele v EUR s přenesením daňové povinnosti,
      uhrazené z BU2, saldo bez rezidua
- [ ] počáteční zůstatek BU1 ≤ 300 tisíc, zůstatky nikdy pod nulou
- [ ] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [ ] ověření podle oddílu Ověření, výsledky v Odchylkách
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D25** — ekonomika příběhu: zisk 8–15 % výnosů za období, ztrátové
  nejvýš 2–3 měsíce, zahraničí 10–20 % tržeb (německý paušál řádově
  600–700 EUR), hardware s maržou, počáteční zůstatek BU1 ≤ 300 tisíc
- ✓ **D26** — pravidelné převody EUR → CZK mezi vlastními účty
  (`transfer.out` / `transfer.in`), kurzem z příběhu
- ✓ **D27** — oprava generátoru: cena katalogové položky na faktuře v cizí
  měně = CZK ÷ kurz měsíce vystavení
- ✓ **D28** — zahraniční dodavatel služby (EU), měsíční faktury v EUR
  s přenesením daňové povinnosti, hrazené z BU2 kurzem faktury
