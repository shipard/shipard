---
title: Střediska
summary: Jak založit číselník středisek, zapnout středisko na dokladech, dát ho na hlavičku nebo jen na řádek a najít zápisy střediska v účetním deníku a na Kontaci.
keywords: [středisko, střediska, středisku, střediskem, nákladové středisko, hospodářské středisko, číselník středisek, založit středisko, zapnout střediska, středisko na dokladech, středisko na faktuře, středisko na řádku, středisko z hlavičky, z hlavičky, dimenze, dimenze na dokladech, analytika, analytické členění, náklady podle středisek, výnosy podle středisek, deník podle střediska, filtr středisko, kontace středisko, cost center, pobočka, oddělení, provoz]
related: [uctarna/tisk-kontace.md, uctarna/kdyz-se-doklad-nezauctuje.md, majetek/naklady-na-majetek.md, co-dnes-nejde.md]
---

# Střediska

Středisko je analytické členění účetních zápisů: pobočka, oddělení, provoz.
Vybíráš ho na dokladu — na hlavičce pro celý doklad, nebo jen na řádku —
a po zaúčtování ho nesou zápisy v účetním deníku.

## Kdy to potřebuješ

Chceš vědět, kolik stojí provoz jednotlivých poboček nebo oddělení, nebo
máš střediska zavedená z dřívějšího účetnictví a potřebuješ je dál
sledovat. Dokud střediska nezapneš, doklady se chovají jako dřív — pole
na nich není.

## Postup

**Číselník středisek**

1. Otevři **Nastavení aplikace → Účetnictví → Střediska** a dej **Přidat**.
2. Vyplň **Kód** (krátký, třeba `S01`) a **Název**. **Platnost od**,
   **Platnost do** a **Pořadí** jsou nepovinné; pořadí řídí, jak se
   střediska řadí v nabídce na dokladu.
3. Ulož. Středisko se hned nabízí na dokladech.

**Zapnutí střediska na dokladech**

1. Otevři **Nastavení aplikace → Účetnictví → Dimenze na dokladech**.
2. **Středisko na dokladech** nastav na *Ano* a dej **Uložit**.
3. Na **Faktuře přijaté**, **Faktuře vydané**, **Zálohové faktuře vydané**,
   **Pokladním dokladu** a **Účetním dokladu** se objeví pole
   **Středisko** — na hlavičce i na každém řádku.

**Středisko na dokladu**

1. Týká-li se celý doklad jednoho střediska, vyber ho na hlavičce. Řádky
   bez vlastního střediska ho převezmou; pole řádku to ukazuje nápisem
   *Z hlavičky: …*.
2. Týká-li se střediska jen část dokladu, vyber ho jen na tom řádku.
   Středisko na řádku má přednost před střediskem z hlavičky.
3. Dej **V pořádku**. Každý zápis v deníku teď nese středisko — řádky
   dokladu podle svého výběru, zápisy za DPH a za závazek nebo pohledávku
   podle hlavičky.

**Zápisy střediska**

1. V **Účetním deníku** napiš do filtru **Středisko** kód nebo část názvu.
   Sloupec **Středisko** je u každého zápisu.
2. Na záložce **Zaúčtování** v detailu dokladu a na vytištěné
   [Kontaci](tisk-kontace.md) je sloupec **Středisko**, když ho některý
   zápis nese.

## Na co narazíš

**Vypnutím nastavení se nic neztratí.** Pole **Středisko** z dokladů
zmizí, vybraná střediska ale zůstanou a do deníku se propisují dál.
Zapnutím se zase objeví i s původními hodnotami.

**Středisko, které už nepoužíváš, nemaž.** Zápisy v deníku a staré doklady
ho nesou dál a filtr ho najde; stačí ho na nových dokladech nevybírat.

**Řádek pořízení majetku** přebírá středisko z hlavičky stejně jako
ostatní řádky — z hlavičky se nedědí jen karta majetku, viz
[Majetek na dokladech](../majetek/naklady-na-majetek.md).

**Zálohová faktura vydaná** středisko má (periodicky vystavované zálohy ho
nesou), pole **Majetek** na ní není. Bankovní výpis středisko nenese.

**Přehledy po střediscích zatím nejsou** — dnes jen filtr a sloupec
v účetním deníku. Viz [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Tisk kontace](tisk-kontace.md)
- [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md)
- [Majetek na dokladech](../majetek/naklady-na-majetek.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
