---
title: Evidence majetku
summary: Jak založit kartu majetku, kdy je věc drobný a kdy dlouhodobý majetek, co je cizí majetek, jak karta dostane inventární číslo a jak majetek vyřadit.
keywords: [majetek, karta majetku, karty majetku, evidence majetku, inventář, inventární číslo, inventárního čísla, evidenční číslo, drobný majetek, drobného majetku, evidovaný majetek, dlouhodobý majetek, dlouhodobého majetku, DHM, DNM, hmotný majetek, nehmotný majetek, neodepisovaný majetek, pozemek, cizí majetek, pronajatý majetek, půjčený majetek, leasing, vlastník majetku, vyřazení majetku, vyřadit majetek, datum vyřazení, datum pořízení, pořizovací cena, cena majetku, typ majetku, účetní skupina, přílohy k majetku, soubor movitých věcí, množstevní karta, nový majetek, založit majetek, přidat majetek]
related: [majetek/nastaveni-majetku.md, co-shipard-umi.md, co-dnes-nejde.md, slovnicek.md]
---

# Evidence majetku

**Majetek** je evidence věcí, které firma vlastní nebo používá — od
vrtačky po budovu. Každá věc má **kartu majetku**. Najdeš je v
**Majetek → Majetek**. Zatím jde o evidenci: odpisy a zaúčtování majetku
Shipard ještě neumí (viz [Co Shipard dnes neumí](../co-dnes-nejde.md)).

## Kdy to potřebuješ

Koupil jsi věc, kterou chceš mít v inventáři — ať už drobnou, která šla
rovnou do nákladů, nebo dlouhodobý majetek, který se bude odepisovat.
Nebo používáš věc, která ti nepatří (pronájem, leasing, zápůjčka), a
chceš ji mít v seznamu i s tím, čí je. Nebo věc prodáváš či likviduješ
a potřebuješ ji z evidence vyřadit.

## Postup

**Založení karty**

1. V **Majetek → Majetek** dej **Přidat**. Když předtím dole vybereš
   záložku druhu (třeba **Dlouhodobý hmotný**) nebo **Cizí**, nová karta
   ho má rovnou předvyplněný.
2. Vyplň **Název** (povinný) a v sekci *Zařazení* vyber **Druh**:
   - **Drobný majetek** — věc, kterou jen eviduješ; nákup šel do nákladů
     a nic se neodepisuje. Jediný druh, u kterého karta nese **Cenu**.
   - **Dlouhodobý hmotný** a **Dlouhodobý nehmotný** — majetek, který se
     bude odepisovat. Karta musí mít **Účetní skupinu**; pole **Cena**
     zmizí, protože cena dlouhodobého majetku vznikne až ze zařazení.
   - **Neodepisovaný dlouhodobý** — pozemky, umělecká díla. Také chce
     **Účetní skupinu**, cenu na kartě nemá.
3. Když máš připravené typy (viz [Nastavení majetku](nastaveni-majetku.md)),
   vyber **Typ** — karta si z něj vezme druh a účetní skupinu, pokud jsi
   je ještě nezměnil. Typ jde založit i rovnou z výběru přes
   **Vytvořit nový záznam**.
4. Věc, která ti nepatří, označ v sekci *Vlastnictví* jako **Cizí
   majetek** a vyber **Vlastníka** z Osob. Bez vlastníka kartu neuložíš.
5. V sekci *Pořízení a vyřazení* doplň **Datum pořízení** a u drobného
   majetku **Cenu**. **Způsob sledování** nech na *Jednotlivá věc*, pokud
   nejde o soubor věcí.
6. Ulož: **Uložit jako koncept**, nebo rovnou **V pořádku**.

**Inventární číslo**

Pole **Inventární číslo** nech prázdné. Při přechodu karty do stavu
**V pořádku** ho Shipard přidělí sám: prefix podle druhu (výchozí *MA*)
a pořadové číslo, třeba *MA0001*, *MA0002*. Číslo navazuje na nejvyšší
už použité se stejným prefixem. Když máš vlastní číslování, napiš číslo
ručně — ručně zadané se nikdy nepřepisuje, jen musí být jedinečné.

**Přílohy**

Na záložce **Přílohy** dej **Nahrát přílohu** — faktura, záruční list,
fotka. Přílohy jdou přidat i ke kartě ve stavu **V pořádku**.

**Vyřazení**

1. Otevři kartu a dej **Opravit**.
2. Vyplň **Datum vyřazení** a dej **Uložit**.
3. Dej **Ukončit platnost**. Karta přejde do stavu **V archívu** a v
   seznamu ji najdeš pod záložkou **Archív**. Bez data vyřazení Shipard
   ukončení platnosti odmítne.

## Na co narazíš

**Dlouhodobý majetek bez účetní skupiny neuložíš.** Skupiny podle
standardní účtové osnovy (Stavby, Samostatné movité věci, Software, …)
jsou v čerstvém zdroji dat připravené; jinak si je založ v
[Nastavení majetku](nastaveni-majetku.md).

**Cena je jen u drobného majetku.** Přepnutím druhu na dlouhodobý se pole
**Cena** schová a vymaže — cena dlouhodobého majetku bude vznikat ze
zařazení a technického zhodnocení, až Shipard umí odpisy.

**Záložky dole seznam filtrují podle druhu**, záložka **Cizí** ukáže jen
cizí majetek. V panelu filtrů zúžíš seznam podle **Typu** a **Účetní
skupiny**; hledání jde přes inventární číslo, název i zkrácený název.

**Smazat** kartu jde jen do **Koše**, inventární číslo přitom zůstává
obsazené — smazaná karta ho drží, aby se číslo nikdy nepoužilo dvakrát.

**Nic se neodepisuje ani neúčtuje.** Karta zatím nevidí doklady, kterými
jsi věc pořídil, a účetní doklady k majetku zadáváš ručně.

## Souvisí

- [Nastavení majetku](nastaveni-majetku.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
- [Slovníček](../slovnicek.md)
