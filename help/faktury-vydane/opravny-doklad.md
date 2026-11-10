---
title: Opravný daňový doklad (dobropis)
summary: Jak vystavit dobropis nebo vrubopis k vydané faktuře — příznak Opravný doklad, výběr původní faktury, důvod opravy, sazby DPH a kurz podle původního plnění, vazba v detailu.
keywords: [opravný daňový doklad, opravný doklad, dobropis, dobropisy, vystavit dobropis, vystavení dobropisu, vrubopis, vrubopisy, oprava faktury vydané, oprava základu daně, snížení ceny, vrácené zboží, vratka faktury, storno faktury vs dobropis, souhrnný opravný doklad, bonus za období, sleva za období, původní doklad, číslo původního dokladu, datum plnění původního dokladu, důvod opravy, sazba DPH na dobropisu, kurz na dobropisu, záporná faktura, faktura se zápornou částkou, odkaz na původní fakturu]
related: [faktury-vydane/vystaveni-faktury.md, faktury-vydane/tisk-faktury.md, faktury-prijate/oprava-dokladu.md, uctarna/uzamceni-obdobi.md, co-dnes-nejde.md]
---

# Opravný daňový doklad (dobropis)

Když se po vystavení faktury změní cena, množství nebo se zboží vrátí,
původní fakturu neopravuješ ani nestornuješ — vystavíš k ní **opravný
daňový doklad**. Dobropis částku snižuje (záporné řádky), vrubopis zvyšuje.
V Shipardu je opravný doklad **faktura vydaná** s příznakem a s údaji
o původním dokladu; najdeš ho tedy v **Prodej → Faktury vydané** mezi
ostatními fakturami, v seznamu i v detailu nese štítek **Opravný doklad**.

## Kdy to potřebuješ

Odběratel vrátil část zboží, dohodli jste se na slevě, na faktuře byla
špatná cena nebo sazba, nebo dáváš bonus za období k několika fakturám
najednou. Zákon chce, aby opravný doklad nesl číslo původního dokladu,
datum jeho plnění a důvod opravy a aby daň počítal sazbou platnou
k původnímu plnění — to všechno Shipard na dokladu hlídá.

## Postup

1. **Otevři Prodej → Faktury vydané a dej Přidat.** Hlavičku vyplň jako
   u běžné faktury (viz [Vystavení faktury](vystaveni-faktury.md)) —
   nebo ji nech na výběr původní faktury, ten doplní partnera.

2. **Na záložce Nastavení zaškrtni v sekci Oprava volbu Opravný doklad.**
   Objeví se pole **Původní doklad**, **Číslo původního dokladu**, **Datum
   plnění původního dokladu** a **Důvod opravy**.

3. **Vyber Původní doklad.** Hledá se podle čísla faktury, variabilního
   symbolu nebo názvu odběratele; nabízejí se jen faktury ve stavu
   **V pořádku**, a když už máš vyplněného partnera, jen jeho. Po výběru se
   **Číslo původního dokladu** a **Datum plnění původního dokladu** doplní
   z faktury a zamknou; prázdný partner se doplní z ní a u faktury v cizí
   měně se převezme i její **Kurz**.

   Opravuješ-li víc faktur najednou (bonus, sleva za období), **Původní
   doklad nevybírej** — pole nech prázdné a původní faktury vymez
   v **Důvodu opravy**. Číslo a datum plnění pak můžeš zapsat ručně, nebo je
   nechat prázdné.

4. **Napiš Důvod opravy** — třeba „Vrácené zboží“ nebo „Sleva za odběr
   v 3. čtvrtletí 2026, faktury 126A0007–126A0012“. Bez důvodu doklad
   do stavu **V pořádku** nepřejde.

5. **Přidej řádky.** Dobropis má **záporné množství** (−2 ks à 500 Kč), cena
   za kus zůstává kladná; vrubopis má množství kladné. Sazba DPH u kódu se
   předvyplní podle **Data plnění původního dokladu** — když se mezitím
   sazba změnila, dostaneš tu původní, správnou. Celková částka dobropisu
   vyjde záporná.

6. **Ulož a převeď na V pořádku.** Doklad dostane číslo z řady vydaných
   faktur, zaúčtuje se, jde do saldokonta a do přiznání k DPH i kontrolního
   hlášení stejně jako každá vydaná faktura — se zápornou částkou. Pokud jsi
   nevyplnil ani původní doklad, ani jeho číslo, dostaneš po uložení
   upozornění, že u souhrnné opravy má být vymezení původních faktur
   v důvodu opravy; uložení to neblokuje.

7. **Zkontroluj vazbu v detailu.** Detail opravného dokladu má skupinu
   **Oprava** s původním dokladem (klikem otevřeš jeho náhled), datem jeho
   plnění a důvodem opravy. Detail původní faktury má dole sekci **Opravné
   doklady** se všemi svými dobropisy a vrubopisy — i s koncepty, bez
   stornovaných.

## Na co narazíš

**Původní doklad musí být faktura vydaná ve stavu V pořádku a na stejného
odběratele.** Jiný typ dokladu, koncept, stornovaná faktura ani faktura
jiného partnera jako původní doklad neprojdou. Opravný doklad k opravnému
dokladu vystavit jde.

**Zálohová faktura opravný doklad nemá.** Není to daňový doklad — sekce
**Oprava** je jen na faktuře vydané. Přijaté dobropisy od dodavatelů se
zatím pořizují jako přijatá faktura se zápornými řádky, bez příznaku
a bez odkazu na původní doklad.

**Opravný doklad patří do období podle svého DUZP, ne podle původního
plnění.** Datum plnění původního dokladu určuje jen sazbu DPH. DUZP
opravného dokladu je předvyplněné datem vystavení a jde přepsat —
zařazení do přiznání a kontrolního hlášení i zámek období se řídí jím.

**Kurz se u opravného dokladu v cizí měně nepřepisuje z kurzovního
lístku** — má být kurz původní faktury. S vybraným původním dokladem se
převezme sám (pokud mají oba doklady stejnou měnu), u souhrnného dokladu
bez původního dokladu ho zadáš ručně; bez kurzu doklad do **V pořádku**
nepřejde.

**Zrušíš-li výběr původního dokladu, číslo a datum plnění zůstanou
a odemknou se.** Vypnutím volby **Opravný doklad** se při uložení všechny
údaje opravy smažou.

**Vytiskne se zatím jako faktura.** Na PDF ještě nejsou titulek „Opravný
daňový doklad“, číslo původního dokladu ani důvod opravy — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md). Také tlačítko, které by
dobropis založilo rovnou z detailu faktury s převrácenými řádky, teprve
přijde; dnes se opravný doklad pořizuje ručně podle postupu výše.

## Souvisí

- [Vystavení faktury](vystaveni-faktury.md) — hlavička a řádky, které
  má opravný doklad společné s fakturou
- [Tisk faktury](tisk-faktury.md) — PDF dokladu
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — kdy fakturu
  opravit nebo stornovat místo dobropisu
- [Uzamčení období](../uctarna/uzamceni-obdobi.md) — do kterého období
  opravný doklad patří
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — tisk opravného dokladu,
  přijaté dobropisy
