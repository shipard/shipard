---
title: Opravný daňový doklad (dobropis)
summary: Jak vystavit dobropis nebo vrubopis k vydané faktuře — tlačítko Vystavit opravný doklad v detailu faktury, ruční pořízení souhrnného dokladu, důvod opravy, sazby DPH a kurz podle původního plnění, vazba v detailu, jak vypadá vytištěný opravný doklad.
keywords: [opravný daňový doklad, opravný doklad, dobropis, dobropisy, vystavit dobropis, vystavení dobropisu, vystavit opravný doklad, tlačítko vystavit opravný doklad, dobropis z detailu faktury, vrubopis, vrubopisy, oprava faktury vydané, oprava základu daně, snížení ceny, vrácené zboží, vratka faktury, částečný dobropis, storno faktury vs dobropis, souhrnný opravný doklad, bonus za období, sleva za období, původní doklad, číslo původního dokladu, datum plnění původního dokladu, důvod opravy, sazba DPH na dobropisu, kurz na dobropisu, záporná faktura, faktura se zápornou částkou, odkaz na původní fakturu, tisk dobropisu, vytisknout dobropis, PDF dobropisu, tisk opravného dokladu, titulek opravný daňový doklad, původní doklad na tisku]
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

### Z detailu původní faktury

1. **Otevři Prodej → Faktury vydané, vyber fakturu ve stavu V pořádku
   a v jejím detailu dej Vystavit opravný doklad.** Tlačítko je jen
   u potvrzené faktury vydané — i u takové, která je sama opravným
   dokladem (opravný doklad k opravnému dokladu vystavit jde).

2. **Otevře se koncept opravného dokladu.** Má řádky původní faktury se
   **záporným množstvím** (cena za kus zůstává kladná), zaškrtnutou volbu
   **Opravný doklad**, vyplněný **Původní doklad** s jeho číslem a datem
   plnění, stejného odběratele, řadu, bankovní účet, měnu i **Kurz**
   původní faktury; datum vystavení je dnešní. **Důvod opravy** je prázdný.

3. **Uprav řádky na skutečnou opravu.** Vracíš-li jen část, smaž řádky,
   které se neopravují, nebo zmenši množství; při slevě uprav cenu. Vrubopis
   dostaneš obrácením množství na kladné. Sazba DPH u kódu zůstává podle
   **Data plnění původního dokladu** — když se mezitím sazba změnila,
   dostaneš tu původní, správnou. Celková částka dobropisu vyjde záporná.

4. **Na záložce Nastavení napiš Důvod opravy** — třeba „Vrácené zboží“.
   Bez důvodu doklad do stavu **V pořádku** nepřejde.

5. **Ulož a převeď na V pořádku.** Doklad dostane číslo z řady vydaných
   faktur, zaúčtuje se, jde do saldokonta a do přiznání k DPH i kontrolního
   hlášení stejně jako každá vydaná faktura — se zápornou částkou.

6. **Zkontroluj vazbu v detailu.** Detail opravného dokladu má skupinu
   **Oprava** s původním dokladem (klikem otevřeš jeho náhled), datem jeho
   plnění a důvodem opravy. Detail původní faktury má dole sekci **Opravné
   doklady** se všemi svými dobropisy a vrubopisy — i s koncepty, bez
   stornovaných.

### Ručně — souhrnný opravný doklad k více fakturám

Když opravuješ víc faktur najednou (bonus, sleva za období), tlačítko
nepoužiješ; původní faktury vymezíš v důvodu opravy:

1. **Otevři Prodej → Faktury vydané a dej Přidat.** Hlavičku vyplň jako
   u běžné faktury (viz [Vystavení faktury](vystaveni-faktury.md)).

2. **Na záložce Nastavení zaškrtni v sekci Oprava volbu Opravný doklad.**
   Objeví se pole **Původní doklad**, **Číslo původního dokladu**, **Datum
   plnění původního dokladu** a **Důvod opravy**.

3. **Původní doklad nevybírej** — pole nech prázdné a původní faktury vymez
   v **Důvodu opravy**, třeba „Sleva za odběr v 3. čtvrtletí 2026, faktury
   126A0007–126A0012“. Číslo a datum plnění můžeš zapsat ručně, nebo je
   nechat prázdné; po uložení dostaneš upozornění, že u souhrnné opravy má
   být vymezení původních faktur v důvodu opravy — uložení to neblokuje.

   Opravuješ-li ručně jedinou fakturu, **Původní doklad** naopak vyber:
   hledá se podle čísla faktury, variabilního symbolu nebo názvu odběratele,
   nabízejí se jen faktury ve stavu **V pořádku**. Po výběru se číslo
   a datum plnění doplní z faktury a zamknou, prázdný partner se doplní z ní
   a u faktury v cizí měně se převezme i její **Kurz**.

4. **Přidej řádky.** Dobropis má **záporné množství** (−2 ks à 500 Kč), cena
   za kus zůstává kladná; vrubopis má množství kladné. Sazba DPH se
   předvyplní podle **Data plnění původního dokladu**, je-li vyplněné.

5. **Napiš Důvod opravy, ulož a převeď na V pořádku** — stejně jako
   v bodech 4 až 6 výše.

## Jak vypadá vytištěný opravný doklad

Opravný doklad vytiskneš stejně jako fakturu — tlačítkem **Tisk** v detailu
(viz [Tisk faktury](tisk-faktury.md)). Od faktury se liší:

- **Titulek** je *Opravný daňový doklad*, u neplátce DPH *Opravný doklad*.
- Pod textem dokladu je řádek **Původní doklad** s číslem opravované
  faktury a řádek **Důvod opravy**. Souhrnný opravný doklad bez čísla
  původního dokladu má jen důvod opravy.
- **Částky jsou se znaménkem** — dobropis má záporné řádky, rekapitulaci
  DPH i částku k úhradě. **QR platba** na něm není, je jen u kladné částky.
- Soubor se jmenuje `opravny-doklad-<číslo>.pdf`.

V jiném jazyce než českém se titulek i popisky tisknou v jazyce dokladu
jako na faktuře.

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

**Tlačítko Vystavit opravný doklad založí vždy celý dobropis** — všechny
řádky původní faktury s opačným znaménkem, text ani poznámky faktury
nepřebírá. Částečnou opravu z něj uděláš úpravou řádků v konceptu. Dokud
se koncept zakládá, je tlačítko neaktivní; každé další kliknutí by jinak
založilo další koncept. Když původní faktura není ve stavu **V pořádku**,
tlačítko v detailu není.

**Titulek opravného dokladu řídí volba Opravný doklad, ne znaménko.**
Faktura se zápornou částkou bez zaškrtnuté volby se vytiskne jako běžná
faktura — bez původního dokladu a bez důvodu opravy.

**Datum plnění původního dokladu se netiskne.** Na dokladu je jen jeho
číslo a důvod opravy; souhrnný doklad proto vymez v **Důvodu opravy**.

**Jako elektronickou fakturu (ISDOC) opravný doklad neodešleš** — Shipard
ISDOC k vydaným dokladům zatím nevystavuje, viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Vystavení faktury](vystaveni-faktury.md) — hlavička a řádky, které
  má opravný doklad společné s fakturou
- [Tisk faktury](tisk-faktury.md) — náhled, stažení a co na PDF je
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — kdy fakturu
  opravit nebo stornovat místo dobropisu
- [Uzamčení období](../uctarna/uzamceni-obdobi.md) — do kterého období
  opravný doklad patří
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — přijaté dobropisy,
  elektronická faktura
