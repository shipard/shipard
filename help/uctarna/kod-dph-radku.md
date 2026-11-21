---
title: Řádek mimo DPH a řádek bez kódu DPH
summary: Kdy dát řádku dokladu kód „Mimo DPH“, kdy „Osvobozeno“, co znamená upozornění, že řádek nemá kód DPH, a proč faktura od neplátce kódy nemá.
keywords: [mimo DPH, kód mimo DPH, řádek mimo DPH, řádek bez DPH, nedaňový řádek, není plnění, odpočet zálohy, odpočet nezdaněné zálohy, kauce, vratná záloha, místní poplatek, poplatek z pobytu, průběžná položka, bez daně, kód bez daně, osvobozeno, osvobozené plnění, pojištění, poštovné, řádek nemá kód DPH, chybí kód DPH, kód DPH není vyplněný, nevyplněný kód DPH, doklad nejde potvrdit, faktura od neplátce, dodavatel neplátce, třístranný obchod, řádek 26, řádek 30, řádek 31]
related: [uctarna/datum-sazby-dph.md, uctarna/dph-podani.md, faktury-vydane/vystaveni-faktury.md, faktury-prijate/dokonceni-dokladu.md, posta/kontrola-vytezeni.md, co-dnes-nejde.md]
---

# Řádek mimo DPH a řádek bez kódu DPH

Na dokladu s DPH má každý řádek s částkou svůj **Kód DPH**. Kód říká, jak
se řádek vykazuje — a jeden z nich, **Mimo DPH**, říká, že se nevykazuje
vůbec, protože to není plnění. Řádek bez kódu Shipard bere jako
nedokončený a doklad s ním nepotvrdíš.

## Kdy to potřebuješ

- Na faktuře máš řádek, který **není prodej ani nákup**: odpočet zálohy,
  ze které se daň neodváděla, kauci, místní poplatek nebo částku, kterou
  jen přeposíláš dál.
- Řádek je **osvobozené plnění** (pojištění, poštovní služby, finanční
  služby) a nevíš, jestli mu patří *Mimo DPH*, nebo *Osvobozeno*.
- Shipard ti při uložení píše **„Řádek nemá kód DPH“** nebo ti doklad
  kvůli tomu nepustí do stavu **V pořádku**.

## Postup

**Řádek, který není plněním — zvol „Mimo DPH“**

1. Otevři řádek dokladu a v poli **Kód DPH** vyber **Mimo DPH**. Je vždy
   na konci nabídky, u vydaných i přijatých dokladů a pro každé **Místo
   plnění**.
2. Ulož řádek. Do částky dokladu se počítá — záporný řádek (odpočet
   zálohy) ji sníží. V **Rekapitulaci DPH** není a do přiznání, kontrolního
   ani souhrnného hlášení nejde.

Nad formulářem řádku pak vidíš jen **Celkem** — základ a daň takový řádek
nemá.

**Osvobozené plnění — zvol „Osvobozeno“**

Osvobozené plnění plněním je, jen se z něj neodvádí daň. Patří mu kód
s osvobozením, ne *Mimo DPH*:

- přijatý doklad: *Tuzemsko/Vstup/Osvobozeno*, od dodavatele z EU
  *EU/Vstup/Osvobozeno*, ze třetí země *DOVOZ/Vstup/Osvobozeno*;
- vydaný doklad: *Tuzemsko/Výstup/Osvobozeno* (bez nároku na odpočet),
  nebo *Tuzemsko/Výstup/Osvobozeno s nárokem na odpočet*.

Řádek s osvobozením v **Rekapitulaci DPH** je — se základem a nulovou
daní —, takže rekapitulace souhlasí s tou na faktuře dodavatele.

**Řádek bez kódu — doplň ho**

1. Otevři řádek, u kterého Shipard hlásí **„Řádek nemá kód DPH. Vyberte
   kód, nebo „Mimo DPH“.“**
2. Vyber **Kód DPH** — sazbu, osvobození, nebo *Mimo DPH*, když řádek
   plněním není.
3. Ulož řádek a doklad dej **V pořádku**.

## Na co narazíš

**Rozpracovaný doklad se s řádkem bez kódu uloží, potvrdit nejde.**
Koncept i doklad v opravě se uloží se žlutým pruhem **Uloženo, ale
zkontroluj:**. Do stavu **V pořádku** ho Shipard nepustí a chybu ukáže
u řádku. Je to schválně: řádek bez kódu dřív potichu vypadl ze základu
daně i z přiznání.

**Nový řádek dostane kód sám.** Ve formuláři je **Kód DPH** předvybraný
na první kód nabídky (u vydané faktury základní sazba), nikdy ne na *Mimo
DPH*. Řádek bez kódu proto potkáš hlavně u dokladů, které vznikly jinak
než ručně.

**Mimo DPH není „plnění v zahraničí“.** Dodání do EU, vývoz nebo služba
pro zahraničního odběratele plněním jsou a mají vlastní kódy (*EU/Výstup/…*,
*VÝVOZ/Výstup/…*). Podle nich se dostanou do přiznání a souhrnného
hlášení; s *Mimo DPH* by z nich vypadly.

**Faktura od neplátce kódy nemá.** Když dodavatel není plátce DPH, celý
doklad má **Režim DPH** *Bez DPH* a pole **Kód DPH** se na řádcích vůbec
nenabízí. Nic nedoplňuješ a nic se nehlásí. Návrh z došlé pošty tak
vznikne sám — viz [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md).

**Na pokladním dokladu se úhrad, převodů a záloh netýká.** Řádky s pohybem
*Úhrada pohledávky*, *Úhrada závazku*, převody peněz a přijaté nebo
poskytnuté zálohy pole **Kód DPH** nemají a Shipard u nich kód nechce.

**Do převzaté rekapitulace „Mimo DPH“ nezadáš.** U dokladu s **Převzatou**
rekapitulací (opisuješ rekapitulaci z faktury dodavatele) nabídka kódu
u řádku rekapitulace *Mimo DPH* nemá — rekapitulace je jen o plněních.
Částka dokladu je pak rekapitulace plus řádky *Mimo DPH*.

**Doklad jen s řádky „Mimo DPH“** nemá rekapitulaci, nepatří do žádného
období DPH a zámek tvrzení se ho netýká.

**Kódy „Bez daně“ už v nabídce nejsou.** Dřív se pod ně schovávalo
všechno bez daně. Teď vyber podle toho, o co jde: *Mimo DPH* (není
plnění), *Osvobozeno* (osvobozené plnění), nebo u faktury od neplátce
**Režim DPH** *Bez DPH*.

**Třístranný obchod a ostatní plnění s nárokem na odpočet** mají vlastní
kódy: *EU/Vstup/Zboží — třístranný obchod* a *EU/Výstup/Zboží — třístranný
obchod* (prostřední osoba), *EU/Výstup/Ostatní s nárokem na odpočet*
a *VÝVOZ/Výstup/Služby a ostatní*. Kam se v přiznání počítají, je v
[Podání DPH](dph-podani.md).

## Souvisí

- [Datum sazby DPH](datum-sazby-dph.md)
- [Podání DPH](dph-podani.md)
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md)
- [Dokončení dokladu](../faktury-prijate/dokonceni-dokladu.md)
- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
