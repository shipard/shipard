---
title: Oprava dokladu
summary: Jak opravit nebo zrušit přijatou fakturu, která už je ve stavu V pořádku, a čemu se přitom vyhnout.
keywords: [oprava, opravit doklad, oprava přijaté faktury, v opravě, storno, stornovat, zrušit fakturu, smazat doklad, přeúčtovat, špatná částka na faktuře]
related: [faktury-prijate/dokonceni-dokladu.md, uctarna/uzamceni-obdobi.md, slovnicek.md, co-dnes-nejde.md]
---

# Oprava dokladu

Doklad ve stavu **V pořádku** je uzamčený — nedá se do něj psát. Není to
překážka, kterou je potřeba obejít: oprava má vlastní stav, ve kterém se
doklad odúčtuje, opraví a zaúčtuje znovu.

Stránka je psaná pro **přijaté faktury**. Stavy a přechody jsou u všech
dokladů stejné, takže postup platí i jinde — co u dalších typů dokladů
platí metodicky jinak, tady popsané není.

## Kdy to potřebuješ

V hotovém dokladu je chyba — špatná částka, sazba, datum nebo dodavatel.
Nebo faktura neplatí celá a potřebuješ ji zrušit.

## Postup

1. **Otevři doklad.** **Nákup → Faktury přijaté**, klikni na doklad.

2. **Rozhodni, o jaký případ jde.** Cesty jsou tři a nejsou zaměnitelné:

   | Situace | Co udělat |
   |---|---|
   | Špatná data na dokladu | **Opravit** (dál podle kroku 3) |
   | Data jsou správně, jen se doklad nezaúčtoval | **Přeúčtovat** |
   | Faktura neplatí celá | **Stornovat** |

3. **Dej Opravit.** Doklad přejde do stavu **V opravě**, je znovu
   editovatelný a **číslo si nechává** — opravou se nemění. Zároveň se
   odúčtuje: zápis v Účetním deníku i pohyby v saldokontu zmizí.

4. **Oprav a dej V pořádku.** Účetní zápis se vytvoří znovu z aktuálních
   dat, takže nevzniknou dvojí zápisy.

5. **Zkontroluj párování s platbou.** Pokud už byla faktura spárovaná
   s platbou z banky, podívej se po opravě do saldokonta — párování se
   nemusí obnovit samo a platba by pak visela zvlášť.

## Na co narazíš

**Když je chyba jen v účtování, doklad do opravy netahej.** Sprav to, na co
si Shipard stěžuje — hlášky vysvětluje
[Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md) —
a dej u dokladu **Přeúčtovat**.
Doklad zůstane ve **V pořádku** a — na rozdíl od opravy — se párování
s platbou nerozpojí.

**Do Konceptu se z V opravy vrátí jen poslední doklad v řadě.** Tlačítko
**Uložit jako koncept** doklad vrátí do Konceptu a uvolní jeho číslo;
u staršího dokladu se nenabízí, aby v číslování nezůstala díra. Jinak
vedou z V opravy cesty zpět na **V pořádku**, do **Storna** nebo do koše.

**Storno doklad nemaže.** Zůstává v evidenci, ale neplatí a je odúčtovaný.
Ze Storna se dá jít jedině přes **Opravit**, takže ani storno není konečné.

**Doklad s číslem nemaž.** Smazat sice jde, ale v číselné řadě zůstane díra
a nebude dohledatelné, co se s dokladem stalo. Správná cesta je **Storno**.
Smazaný doklad se dá vrátit tlačítkem **Opravit**.

**Doklad v uzamčeném období neopravíš.** Po podání přiznání k DPH období
uzamkni — zamčený doklad pak nejde opravit, stornovat ani smazat a podklady,
které už jsi odevzdal, se zpětně nezmění. Zámek se nenastaví sám; jak na
něj a jak ho pro dodatečné přiznání zase sundat, je v
[Uzamčení období](../uctarna/uzamceni-obdobi.md).

## Souvisí

- [Dokončení dokladu](dokonceni-dokladu.md) — jak doklad vzniká a co dělá
  přechod na V pořádku
- [Slovníček](../slovnicek.md) — přehled stavů dokladu
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
