---
title: Nastavení majetku
summary: Kde nastavíš typy majetku, skupiny typů, účetní skupiny majetku a prefixy inventárních čísel — a co z toho karta majetku přebírá.
keywords: [nastavení majetku, typy majetku, typ majetku, nový typ majetku, skupiny typů, skupina typů majetku, účetní skupiny majetku, účetní skupina majetku, účty majetku, účet majetku, účet oprávek, účet odpisů, účet pořízení, účet vyřazení, zůstatková cena, prefix inventárního čísla, číslování majetku, inventární čísla, MA0001, výchozí druh, výchozí účetní skupina, stavby, samostatné movité věci, software, pozemky]
related: [majetek/evidence-majetku.md, uctarna/kdyz-se-doklad-nezauctuje.md, co-shipard-umi.md, slovnicek.md]
---

# Nastavení majetku

Číselníky majetku najdeš v **Nastavení aplikace → Majetek**: **Typy
majetku**, **Skupiny typů majetku**, **Účetní skupiny majetku** a stránku
**Inventární čísla**. Nic z toho není nutné k založení první karty
drobného majetku — dlouhodobý majetek ale účetní skupinu potřebuje.

## Kdy to potřebuješ

Zakládáš dlouhodobý majetek a karta chce **Účetní skupinu**. Nebo máš
desítky karet stejného druhu (notebooky, auta) a chceš, aby nová karta
dostala druh a účetní skupinu jedním výběrem **Typu**. Nebo ti nevyhovuje
výchozí prefix inventárních čísel *MA*.

## Postup

**Účetní skupina majetku**

Účetní skupina říká, na které účty patří majetek, jeho pořízení,
oprávky, odpisy a zůstatková cena při vyřazení. Karta na ni odkazuje;
účtovat podle ní bude Shipard, až umí odpisy.

1. V **Nastavení aplikace → Majetek → Účetní skupiny majetku** dej
   **Přidat**.
2. Vyplň **Kód** (krátký, jedinečný — třeba číslo syntetického účtu
   *022*), **Název** a **Účet majetku** (povinný, účet skupiny 01–03).
3. Doplň **Účet pořízení** (04x), **Účet oprávek** (07x nebo 08x), **Účet
   odpisů** (551) a **Účet zůstatkové ceny při vyřazení** (541). U
   neodepisovaného majetku (pozemky) oprávky ani odpisy nevyplňuj.
4. Ulož **V pořádku**.

**Typ majetku**

1. V **Nastavení aplikace → Majetek → Typy majetku** dej **Přidat**.
2. Vyplň **Název**, případně **Zkrácený název**, **Skupinu typů** (jen
   pro přehlednost, založíš ji přes **Vytvořit nový záznam**) a **Pořadí**.
3. V sekci *Výchozí hodnoty* vyber **Výchozí druh** a **Výchozí účetní
   skupinu**. Právě ty karta převezme po výběru typu.
4. Ulož **V pořádku**.

**Prefixy inventárních čísel**

1. Otevři **Nastavení aplikace → Majetek → Inventární čísla**.
2. Pro každý druh vyplň prefix (**Prefix — drobný majetek**, **Prefix —
   dlouhodobý hmotný**, …). Prázdné pole znamená *MA*.
3. Dej **Uložit**. Nová čísla vypadají jako prefix a čtyřmístné pořadové
   číslo (*DM0001*); pořadí navazuje na nejvyšší číslo se stejným
   prefixem. Už přidělená čísla se nemění.

## Na co narazíš

**Sedm účetních skupin je připravených.** Čerstvý zdroj dat se
standardní účtovou osnovou dostane skupiny *021 Stavby*, *022 Samostatné
movité věci*, *013 Software*, *014 Ocenitelná práva*, *019 Ostatní
dlouhodobý nehmotný majetek*, *031 Pozemky* a *032 Umělecká díla a
sbírky*. Když některý účet v osnově chybí (třeba po vlastních úpravách),
skupina se nezaloží — přidej ji ručně.

**Účet musí být ve správné skupině osnovy.** Do **Účtu majetku** nejde
uložit účet nákladů ani pořízení; Shipard hlídá skupiny 01–03 pro
majetek, 04 pro pořízení, 07–08 pro oprávky, 55 pro odpisy a 54–55 pro
vyřazení. Výběr nabízí analytické účty, syntetický účet nevybereš.

**Typ kartě nic nevnucuje.** Výchozí druh se z typu převezme jen u nové
karty, dokud jsi druh sám nezměnil; účetní skupina jen do prázdného
pole. Změna typu u uložené karty druh nepřepíše.

**Skupiny typů jsou jen pořádek v seznamu.** Nic dalšího se podle nich
neřídí.

## Souvisí

- [Evidence majetku](evidence-majetku.md)
- [Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md)
- [Slovníček](../slovnicek.md)
