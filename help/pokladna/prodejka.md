---
title: Prodejka
summary: Jak zapsat prodej za hotové, kartou, přes bránu nebo na dobírku na pokladně bez faktury, jak udělat vratku a jak prodejku vytisknout.
keywords: [prodejka, prodej za hotové, prodej na pokladně, paragon, účtenka, platba kartou, prodej kartou, prodej na dobírku, dobírka, platební brána, tržba, vratka, vrácení zboží, storno prodeje, prodejky, plátce, tisk prodejky, vytisknout prodejku, PDF prodejky, prodejka daňový doklad, prodejka vratka]
related: [pokladna/pokladni-doklad.md, pokladna/platba-kartou-branou-dobirkou.md, faktury-vydane/tisk-faktury.md, polozky/zalozeni-polozky.md, uctarna/dph-zive-vystupy.md, co-dnes-nejde.md]
---

# Prodejka

Prodejka je doklad o prodeji za hotové nebo kartou na pokladně — to, co
zákazník dostane místo faktury. Najdeš ji v **Prodej → Prodejky**. Stejně jako
pokladní doklad patří vždy jedné pokladně a má její číselnou řadu.

## Kdy to potřebuješ

Prodáváš zboží nebo služby na místě, zákazník platí hned a fakturu nechce.
Když zákazník platí hotově *fakturu*, použij [Pokladní doklad](pokladni-doklad.md)
s pohybem *Úhrada pohledávky*, nebo na faktuře zvol platbu *Hotovost* rovnou.

## Postup

1. **Měj založenou pokladnu** v **Nastavení → Účetnictví → Pokladny** ve
   stavu **V pořádku** — řadu prodejek jí Shipard založí sám.

2. **Otevři Prodej → Prodejky**, vyber dole záložku pokladny (máš-li jich
   víc) a dej **Přidat**.

3. **Vyplň hlavičku.** **Způsob platby** *Hotovost*, *Kartou*, *Převodem*,
   *Dobírkou* nebo *Platební bránou*. U karty se doplní **Platební
   terminál / brána** pokladny, u brány ho vyber, u dobírky vyber **Způsob
   dopravy**; pole **Plátce** pak ukazuje, za kým pohledávka vznikne — viz
   [Platba kartou, přes bránu a dobírkou](platba-kartou-branou-dobirkou.md).
   **Partner** je nepovinný — vyplň ho, když zákazník chce mít prodej
   na své firmě (s DIČ pak prodej nad 10 000 Kč jde do kontrolního hlášení
   jednotlivě); u převodu je povinný. **Datum vystavení** a **Účetní
   datum** jsou povinné.
   **Měna dokladu** je měna pokladny. **Režim DPH**, **Místo plnění**,
   **Registrace DPH** a zaokrouhlení najdeš na záložce **Nastavení** úplně
   vpravo — běžně je nemusíš měnit, doplní se samy.

4. **Dej Uložit** a přidej řádky: **Pohyb** *Prodej zboží* nebo *Prodej
   služeb*, **Položka**, **Množství**, cena a **Kód DPH** — stejně jako na
   vydané faktuře.

5. **Dej Potvrdit a V pořádku.** Prodejka dostane číslo (například
   `14HP12600001`), zaúčtuje se — hotovost na pokladnu, karta, brána
   a dobírka jako pohledávka za plátcem s variabilním symbolem rovným
   číslu prodejky — a DPH z ní jde do přiznání za období podle DUZP.

## Tisk prodejky

Prodejku ve stavu **V pořádku** vytiskneš tlačítkem **Tisk** nahoře v jejím
detailu — vyber **Prodejka** (druhá položka, **Kontace**, je interní tisk
účetních zápisů, viz [Tisk kontace](../uctarna/tisk-kontace.md)). Otevře se
náhled PDF a **Stáhnout** ho uloží. Postup, náhled
i hlášení jsou stejné jako u faktury — viz
[Tisk faktury](../faktury-vydane/tisk-faktury.md). Vyjde doklad na celou
stránku A4, ne úzká účtenka.

- **Titulek**: u plátce DPH *Prodejka – daňový doklad*, u neplátce
  *Prodejka*. Vratka (prodejka se zápornou celkovou částkou) se jmenuje
  *Prodejka – vratka*; jinak vypadá stejně, jen má záporné částky.
- **Odběratel** je na dokladu jen tehdy, když má prodejka partnera. Bez
  něj zůstane jeho místo prázdné.
- **Pokladna**, **Datum vystavení** a u plátce datum zdanitelného plnění,
  řádky, rekapitulace DPH a částka **Celkem**.
- Prodejka placená **Převodem** má navíc **Datum splatnosti**, **Náš
  bankovní účet**, QR platbu a částku **K úhradě** — jako faktura.

Prodejka ve stavu **Storno** se vytiskne s šedým nápisem **STORNO** přes
každou stranu.

## Na co narazíš

**Vratka je záporný řádek.** Vrací-li zákazník zboží, založ novou prodejku
a dej na řádek záporné množství (nebo zápornou cenu). Zvláštní druh dokladu
ani směr prodejka nemá; celková částka pak vyjde záporná a zaúčtuje se
opačně.

**Prodejka kartou, dobírkou nebo bránou bez plátce nejde uložit.** Bez
terminálu, brány nebo dopravy s osobou pro saldokonto musíš vyplnit
partnera — pohledávka pak vznikne za ním.

**Prodejka bez partnera jde do kontrolního hlášení souhrnně.** Do řádků
A4 (nad 10 000 Kč jednotlivě) se dostane jen prodej, u kterého je vyplněný
partner s českým DIČ — viz [Živé výstupy DPH](../uctarna/dph-zive-vystupy.md).

**Účtenku z pokladní tiskárny z Shipardu nedostaneš.** Prodejka se tiskne
jen jako PDF na stránku A4 — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Pokladní doklad](pokladni-doklad.md) — příjem a výdej hotovosti, úhrady faktur
- [Platba kartou, přes bránu a dobírkou](platba-kartou-branou-dobirkou.md) —
  terminály, brány, dopravci a kde najdeš pohledávku za nimi
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na řádek
- [Živé výstupy DPH](../uctarna/dph-zive-vystupy.md) — kam prodejka spadne
  v přiznání a kontrolním hlášení
- [Tisk faktury](../faktury-vydane/tisk-faktury.md) — náhled, stažení
  a hlášení při tisku fungují stejně
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — účtenka z pokladní
  tiskárny, pokladní kniha
