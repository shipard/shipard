---
title: Přehled firmy
summary: Jak na tom firma je v číslech — peníze, pohledávky, závazky, DPH a výsledek hospodaření na jedné obrazovce, s datem, ke kterému každé číslo platí.
keywords: [přehled firmy, stav firmy, jak na tom jsme, jak na tom firma je, kolik máme na účtu, zůstatek na účtu, zůstatek v pokladně, kolik nám dluží, kolik dlužíme, pohledávky po splatnosti, závazky po splatnosti, co máme zaplatit, splatné do týdne, kolik budeme platit DPH, termín podání DPH, kdy podat přiznání, nadměrný odpočet, výsledek hospodaření, zisk, ztráta, výnosy, náklady, od začátku roku, poslední výpis, starý výpis, neaktuální výpis, počáteční stavy nejsou převedené, nespárované platby, přehled, souhrn, čísla firmy]
related: [uctarna/dph-zive-vystupy.md, uctarna/dph-podani.md, uctarna/uzamceni-obdobi.md, co-shipard-umi.md, co-dnes-nejde.md]
---

# Přehled firmy

Jedna obrazovka, která odpovídá na otázku „jak na tom firma je“: peníze,
pohledávky, závazky, DPH a výsledek hospodaření. Každé číslo je spočítané
z toho, co už v Shipardu máš — z bankovních výpisů, zaúčtovaných dokladů,
saldokonta a živého přiznání k DPH — a u každého vidíš, k jakému datu
platí. Je to stav, ne seznam práce: co je potřeba udělat, ti dál říká
**Dashboard**.

## Kdy to potřebuješ

- Chceš rychle vědět, kolik peněz je na účtech a v pokladnách a kolik
  firmě kdo dluží.
- Plánuješ platby: co je po splatnosti a co bude splatné během týdne.
- Zajímá tě, kolik budeš za období platit DPH (nebo kolik dostaneš zpět)
  a do kdy je třeba podat přiznání.
- Potřebuješ výsledek hospodaření od začátku účetního období bez
  otevírání výsledovky.

## Postup

1. V levém menu pod **Dashboardem** otevři **Přehled firmy**. Položku
   vidí jen správce zdroje dat.
2. Obrazovka má pět karet:
   - **Peníze** — každý bankovní účet s koncovým zůstatkem posledního
     výpisu a datem výpisu (*Podle výpisu č. …*), pod ním pokladny se
     zůstatkem *Podle zaúčtovaných dokladů* a datem posledního zápisu.
   - **Pohledávky** — **Pohledávky celkem** a **Pohledávky po
     splatnosti**, v každé měně zvlášť, s počtem případů.
   - **Závazky** — **Závazky po splatnosti** a **Závazky splatné do
     7 dní**, v každé měně zvlášť.
   - **DPH** — **Přiznání k DPH** za předchozí období, dokud není podané,
     s termínem podání, a za běžné období *(průběžně)*. U částky stojí,
     jestli jde o **Vlastní daňovou povinnost**, nebo **Nadměrný odpočet**.
   - **Výsledek hospodaření** — výsledek, **Výnosy** a **Náklady** od
     začátku účetního období.
3. Klepni na název řádku a otevře se zdroj čísla: bankovní výpis,
   **Účetní deník** s filtrem na účet pokladny, **Saldokonto** s filtry
   (měna, typ, po splatnosti, splatné do dní) nastavenými tak, aby patička
   ukázala totéž číslo, nebo report **Výsledovka** či **Přiznání k DPH —
   živě** za stejné období.
4. Tlačítkem **Obnovit** vpravo nahoře přehled přepočítáš. Vlevo od něj
   je datum, ke kterému se počítalo.

## Na co narazíš

- **Měny se nesčítají.** Účet v eurech nebo pohledávky v eurech jsou
  samostatné řádky; přepočet kurzem zatím není. Domácí měna je vždy první.
- Zůstatek banky je **z výpisu, ne z deníku** — ukazuje tedy stav
  k poslednímu nahranému výpisu. Když je výpis starší než 35 dní, řádek
  dostane štítek **neaktuální**; účet bez výpisu štítek **chybí** a odkaz
  vede do **Bankovních výpisů**.
- Zůstatek pokladny je **z deníku za běžné účetní období**: doklad, který
  ještě není ve stavu **V pořádku**, se v něm neukáže.
- **Počáteční stavy nejsou převedené.** Když nové účetní období ještě
  nemá otevírací zápisy a to předchozí zápisy má, karty **Peníze**
  (pokladny), **Pohledávky** a **Závazky** místo čísel řeknou, že
  počáteční stavy chybí. Přehled nehádá — převeď počáteční stavy a čísla
  se objeví.
- U **Pohledávek** a **Závazků** je poznámka o **nespárovaných
  platbách** — počet a součet plateb, ke kterým zatím není předpis;
  odkaz vede do saldokonta skupiny **Nespárované platby**.
- **DPH** ukazuje jen plátce. Předchozí období zmizí z přehledu, jakmile
  u něj existuje podání ve stavu **Podáno** — sestavené, ale nepodané
  podání ho neskryje. Termín je 25. den po konci období posunutý na
  nejbližší pracovní den; řádek se zvýrazní týden před termínem a po něm.
- **Výsledek hospodaření** je od začátku účetního období po běžný měsíc,
  tak jak ho počítá **Výsledovka**; chybové masky účtů z reportu se
  v kartě objeví jako chyba.
- Když se jedna karta nespočítá (chyba výpočtu), ostatní karty fungují
  dál — chybná karta to ohlásí sama.

## Souvisí

- [Živé výstupy DPH](dph-zive-vystupy.md) — odkud se bere číslo v kartě DPH
- [Podání DPH](dph-podani.md) — kdy předchozí období z přehledu zmizí
- [Uzamčení období](uzamceni-obdobi.md) — účetní období a jeho měsíce
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — co v přehledu zatím není
