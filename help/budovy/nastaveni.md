---
title: Druhy smluv a číslování
summary: Kde upravíš druhy smluv doplňku Budovy, jak napsat vzorec čísla smlouvy, co znamená roční restart, jak druhu nastavit výchozí hodnoty fakturace a šablonu řádků pro nové smlouvy a co se u druhu po potvrzení už nedá změnit.
keywords: [druhy smluv, druh smlouvy, nový druh smlouvy, typ smlouvy, typ druhu, nastavení budov, nastavení smluv, číslování smluv, číslo smlouvy, vzorec čísla smlouvy, vzorec čísla, kód druhu, restart počítadla, ročně, průběžně, pořadové číslo smlouvy, "%C", "%y", "%Y", "%4", placeholder, ukázka čísla, nejde změnit typ druhu, nejde smazat druh smlouvy, druh používají smlouvy, ukončit platnost druhu, výchozí druhy smluv, kód už má jiný druh, výchozí hodnoty fakturace, typ dokladu druhu, řada dokladů druhu, splatnost, režim DPH, způsob platby, šablona řádků, řádky nové smlouvy, předvyplněné řádky smlouvy, kód DPH nájmu, DPH u bytu, nájem bytu osvobozený, nájem bez DPH, šablonu nejde upravit]
related: [budovy/smlouvy.md, zakazky/periodicka-fakturace.md, mista/mista-a-druhy-mist.md, co-shipard-umi.md]
---

# Druhy smluv a číslování

Druh smlouvy říká, o jaký pronájem jde, jak se smlouvy číslují a s jakým
nastavením a řádky se fakturují. Druhy najdeš v **Nastavení aplikace →
Budovy → Druhy smluv**. Sekce je vidět,
jen když je zapnutý doplněk Budovy.

## Kdy to potřebuješ

Chceš jiné číslování smluv, než jaké má Shipard předpřipravené. Nebo
potřebuješ vlastní druh — třeba zvlášť kanceláře ve dvou budovách.
Nebo některý druh nepoužíváš a nechceš ho mít v nabídce. Nebo chceš, aby
nové smlouvy druhu měly rovnou správný typ faktury, splatnost a řádky
s cenou a kódem DPH.

## Postup

**Předpřipravené druhy**

Po zapnutí doplňku je založený jeden druh pro každý typ smlouvy:

| Druh | Kód | Typ smlouvy |
|---|---|---|
| Kancelář | KA | Kancelář |
| Byt | BY | Byt |
| Obchodní prostor | OP | Obchodní prostor |
| Sklad | SK | Sklad |
| Parkování | PA | Parkování |
| Virtuální kancelář | VK | Virtuální kancelář |
| Ostatní | OS | Ostatní |

Všechny mají vzorec čísla `%C-%Y-%4` a restart **Ročně** — první smlouva
na kancelář se zahájením v roce 2027 dostane číslo *KA-2027-0001*.

**Nový druh**

1. V **Nastavení aplikace → Budovy → Druhy smluv** dej **Přidat**.
2. Vyplň **Název** a vyber **Typ smlouvy**. Typ určuje, jaká místa smí
   smlouva tohoto druhu mít (viz [Smlouvy](smlouvy.md)).
3. V části **Číslování** vyplň:
   - **Kód druhu (%C)** — krátký kód, který se dosadí do čísla. Dva druhy
     nesmí mít stejný.
   - **Vzorec čísla smlouvy** — viz tabulka níže.
   - **Restart počítadla** — **Ročně** počítá smlouvy každý rok zahájení
     znovu od jedné, **Průběžně** počítá pořád dál.
4. Dej **V pořádku**.

**Vzorec čísla**

| Znak | Dosadí se | Příklad |
|---|---|---|
| `%C` | kód druhu | KA |
| `%Y` | rok zahájení smlouvy, čtyři místa | 2027 |
| `%y` | rok zahájení smlouvy, dvě místa | 27 |
| `%3` až `%6` | pořadí doplněné nulami na 3 až 6 míst | 0001 |

Ostatní znaky se opíší tak, jak jsou. Seznam druhů ukazuje u každého
druhu ukázku čísla pro letošní rok, detail druhu řádek **Ukázka čísla**.

**Výchozí hodnoty fakturace**

Sekce **Výchozí hodnoty fakturace** platí pro všechny smlouvy druhu;
smlouva je smí přepsat (viz [Smlouvy](smlouvy.md)).

1. Vyber **Typ dokladu** — **Faktura vydaná**, nebo **Zálohová faktura
   vydaná** — a **Řadu dokladů** stejného typu. Smlouva s periodicitou
   je potřebuje k potvrzení: z druhu, nebo vlastní.
2. Doplň **Splatnost (dny)** (prázdné = 14), **Fakturace** — **Na počátku
   období**, nebo **Na konci období** — **Režim DPH** (ceny na řádcích
   **Ze základu**, nebo **Z ceny celkem**), **Způsob platby** a případně
   **Bankovní účet**. Prázdné pole = výchozí hodnota jako u ručně
   založeného dokladu.

**Šablona řádků**

Na záložce **Šablona řádků** připravíš řádky, které dostane každá nová
smlouva druhu: **Popis** (nebo **Položku**), **Množství**, **Jednotku**,
**Cenu/jednotku**, **Kód DPH** a případně **Pohyb**. Platnost šablona
nemá — tu si řádek nese až na smlouvě.

- Šablona se do smlouvy zkopíruje při jejím prvním uložení; na smlouvě
  pak řádky upravíš, jak potřebuješ.
- **Kód DPH** nájmu patří sem: druhu **Byt** dej řádek s osvobozeným
  kódem, kanceláři se základní sazbou. Typ smlouvy sám DPH neurčuje.
- Nabídka kódů DPH a pohybů se řídí **Typem dokladu** druhu — vyber ho
  dřív, než začneš šablonu plnit.
- Šablonu potvrzeného druhu upravíš přes **Opravit**, stejně jako ostatní
  pole druhu.

**Druh, který nepoužíváš**

Otevři ho a dej **Ukončit platnost**. Zmizí z nabídky nových smluv,
existující smlouvy ho mají dál.

## Na co narazíš

**Typ smlouvy nejde změnit.** Typ volíš jen u konceptu druhu, který ještě
nemá žádnou smlouvu. Potvrzený druh ho má jen ke čtení — smlouvy druhu si
podle typu hlídají povolená místa. Potřebuješ jiný typ? Založ nový druh.

**Vzorec nejde uložit.** Vzorec musí obsahovat pořadí (`%3` až `%6`).
Když obsahuje `%C`, musí mít druh vyplněný kód. A při restartu **Ročně**
musí obsahovat rok (`%y` nebo `%Y`) — jinak by se čísla po roce opakovala
a číslo smlouvy musí být jedinečné.

**Druh nejde smazat.** Druh, který má aspoň jednu smlouvu mimo koš, do
koše nejde. Dej mu **Ukončit platnost**.

**Změna vzorce nemění stará čísla.** Vzorec i kód platí jen pro smlouvy
potvrzené po změně; už přidělená čísla zůstávají.

**Počítadlo patří druhu.** Každý druh čísluje sám za sebe — *KA-2027-0001*
a *PA-2027-0001* můžou existovat vedle sebe.

**Šablona řádků nejde upravit.** Druh je **V pořádku** — dej **Opravit**,
uprav šablonu a vrať ho **V pořádku**.

**Změna šablony nezmění existující smlouvy.** Šablona se kopíruje jen do
nové smlouvy (a do konceptu bez řádků při změně jeho druhu). Smlouvám,
které už řádky mají, uprav řádky ručně.

**Řada dokladů se nenabízí.** Nejdřív vyber **Typ dokladu** — nabídka řad
se řídí jím. Po změně typu dokladu se řada vybírá znovu.

## Souvisí

- [Smlouvy](smlouvy.md)
- [Periodická fakturace](../zakazky/periodicka-fakturace.md)
- [Místa a druhy míst](../mista/mista-a-druhy-mist.md)
