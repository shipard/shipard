---
title: Evidence tržeb — kontaktní platba
summary: Jak zaevidovat jako tržbu fakturu vystavenou Převodem, kterou zákazník zaplatil na místě QR kódem nebo kartou — akce Kontaktní platba, zaškrtávátko ve formuláři, zrušení a kde na dokladu tržbu uvidíš.
keywords: [kontaktní platba, kontaktní platbu, kontaktní platby, platba na místě, zaplatil na místě, zaplaceno na místě, QR platba, QR kód, platba QR kódem, QR platbu, karta na terminálu, platba kartou na místě, faktura převodem zaplacená na místě, faktura převodem, zaevidovat tržbu, zaevidovat fakturu, čas platby, zrušit kontaktní platbu, zrušení kontaktní platby, záporná tržba, zálohová faktura, zálohové faktury, zálohovou fakturu, záloha zaplacená na místě, tržba na dokladu, tržbu na dokladu, štítek EET, EET test, záložka Evidence tržeb, evidence tržeb, evidenci tržeb, EET, EET 2.0, platební brána, platební bránou, dvojí tržba, uhrazeno pokladním dokladem]
related: [evidence-trzeb/trzby-z-dokladu.md, evidence-trzeb/registrace-a-certifikat.md, faktury-vydane/vystaveni-faktury.md, faktury-vydane/zalohova-faktura.md, pokladna/platba-kartou-branou-dobirkou.md, co-dnes-nejde.md]
modules: [economy.salesRegistration]
---

# Evidence tržeb — kontaktní platba

Faktura se způsobem platby **Převodem** se jako tržba neeviduje — peníze
přijdou na účet. Když ji ale zákazník zaplatí **na místě** (naskenuje QR kód
z faktury, zaplatí kartou na terminálu), tržba to je. Řekneš to Shipardu
příznakem **Kontaktní platba** a ten z faktury udělá tržbu na celou její
částku.

## Kdy to potřebuješ

Máš zapnutý doplněk Evidence tržeb a registraci **V pořádku** (viz
[Registrace a certifikát](registrace-a-certifikat.md)) a zákazník ti na místě
zaplatil:

- **fakturu vydanou** se způsobem platby **Převodem**,
- **zálohovou fakturu vydanou** se způsobem platby **Převodem** nebo **Kartou**.

Kontaktní platbu **nepoužívej** pro platbu přes internet (e-shop, platební
odkaz) — tu zapiš způsobem platby **Platební bránou**, do evidence nepatří.
Fakturu placenou **Hotovost** nebo **Kartou** a prodejku označovat nemusíš,
evidují se samy — viz [Tržby z dokladů](trzby-z-dokladu.md).

## Postup

**Faktura už je V pořádku — akce Kontaktní platba**

Tohle je běžný případ: fakturu jsi vystavil dřív a zákazník přišel zaplatit.

1. Otevři fakturu v **Prodej → Faktury vydané** (zálohovou v **Prodej →
   Zálohové faktury vydané**).
2. V detailu dej **Kontaktní platba**.
3. V okně zkontroluj **Čas platby**. Je předvyplněný na teď; přepiš ho,
   když zákazník zaplatil dřív. V budoucnu být nesmí.
4. Potvrď **Zaevidovat tržbu**.

Faktura zůstává **V pořádku** — nemusíš ji otevírat k opravě a nevadí ani,
že je období DPH už podané. Shipard založí tržbu na celou částku faktury
a hned ji odešle; výsledek ti napíše (potvrzeno, čeká ve frontě, nebo proč
tržbu založit nešlo). Způsob platby ani zaúčtování faktury se nemění.

U faktury s odpočtem zálohy je tržbou částka k úhradě, ne hodnota celé
zakázky.

**Faktura je rozpracovaná — zaškrtávátko ve formuláři**

Víš-li už při vystavování, že zákazník platí na místě:

1. Ve formuláři faktury (stav **Koncept** nebo **V opravě**) otevři záložku
   **Evidence tržeb**.
2. Zaškrtni **Kontaktní platba**.
3. Vyplň **Čas platby**, nebo ho nech prázdný — pak platí okamžik, kdy
   faktuře dáš **V pořádku**.
4. Ulož a dej **V pořádku**. Tržba vznikne v tu chvíli.

**Zrušení**

Označil jsi fakturu omylem? V detailu dej **Zrušit kontaktní platbu**
a potvrď. Shipard pošle druhou tržbu se zápornou částkou a časem zrušení —
součet tržeb faktury je pak nula. U rozpracované faktury stačí zaškrtávátko
odškrtnout.

**Kde tržbu na dokladu uvidíš**

- V detailu dokladu je záložka **Evidence tržeb**: jestli doklad do evidence
  spadá a jakou částkou (nebo proč ne), a jeho tržby — čas, částka, stav
  a konec kódu potvrzení. Klikem na pořadové číslo otevřeš tržbu.
- Nahoře v detailu je štítek se stavem, třeba **EET: Potvrzeno**.
- V seznamu dokladů mají doklady s tržbou štítek **EET** — zelený
  u potvrzené tržby, oranžový, když se čeká na odeslání, červený, když ji
  správce daně odmítl. **EET test** znamená potvrzení ze zkušebního
  prostředí.

## Na co narazíš

**Akce Kontaktní platba u faktury chybí.** Nabízí se jen u dokladu **V
pořádku**, u faktury vydané **Převodem** a u zálohové faktury vydané
**Převodem** nebo **Kartou**, a jen když máš registraci **V pořádku**.
U pokladního dokladu není — příjem se eviduje sám podle řádků.

**Záložka Evidence tržeb ve formuláři chybí.** Je jen u faktury vydané
a zálohové faktury vydané a jen s registrací **V pořádku**.

**Při ukládání „Kontaktní platbu u tohoto dokladu a způsobu platby nastavit
nejde“.** Faktura má zaškrtnutou kontaktní platbu a způsob platby, ke kterému
nepatří (**Hotovost**, **Kartou**, **Platební bránou**, **Zápočtem**,
**Dobírkou**). Odškrtni ji, nebo vrať způsob platby na **Převodem**.

**V okně je „Pozor: doklad už hradí pokladní doklad…“.** Fakturu jsi už
zaplatil pokladním dokladem **Příjem** s řádkem **Úhrada pohledávky** a ten
se jako tržba eviduje. Kontaktní platba by tutéž tržbu poslala podruhé —
okno zavři.

**„Doklad by do evidence tržeb nespadl“.** Čas platby je mimo platnost
registrace (třeba před jejím začátkem). Zkontroluj čas. Faktura smí být
vystavená i před začátkem registrace — rozhoduje, kdy zákazník zaplatil.

**Tržba „čeká ve frontě“.** Služba správce daně teď neodpovídá; Shipard
tržbu pošle sám, jakmile to půjde. Sleduj ji v **Prodej → Evidence tržeb**.

**Opravíš fakturu s kontaktní platbou.** Vrátíš-li ji do **V pořádku**
s jinou částkou, pošle se rozdíl; **Stornovat** pošle zápornou tržbu —
stejně jako u ostatních dokladů.

**Čas platby zadávej tak, jak ukazují hodiny v provozovně.**

## Souvisí

- [Evidence tržeb — tržby z dokladů](trzby-z-dokladu.md) — které doklady se
  evidují samy, stavy tržby, upozornění
- [Evidence tržeb — registrace a certifikát](registrace-a-certifikat.md)
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md)
- [Zálohová faktura](../faktury-vydane/zalohova-faktura.md)
- [Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — co z evidence tržeb zatím chybí
