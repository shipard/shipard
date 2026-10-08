---
title: Zakázky
summary: Jak založit zakázku, co znamenají čtyři typy zakázek, jak zakázka dostane číslo, jak funguje nadřazená zakázka a podzakázky a jak zakázku ukončit nebo zrušit.
keywords: [zakázka, zakázky, zakázku, zakázce, nová zakázka, založit zakázku, přidat zakázku, číslo zakázky, číslování zakázek, typ zakázky, typy zakázek, periodická zakázka, externí jednorázová, interní průběžná, interní jednorázová, projekt, režijní zakázka, režie, nadřazená zakázka, podzakázka, podzakázky, strom zakázek, zákazník zakázky, zákazník z nadřazené, ukončit zakázku, zrušit zakázku, ukončená zakázka, zrušená zakázka, archiv zakázek, datum zahájení, datum ukončení, zakázka v opravě, smazat zakázku, přílohy k zakázce, smlouva, nájemní smlouva, work order]
related: [zakazky/nastaveni-zakazek.md, zakazky/zakazka-na-dokladech.md, uctarna/strediska.md, co-shipard-umi.md, co-dnes-nejde.md]
---

# Zakázky

Zakázka je evidence toho, pro koho a na čem pracuješ: projekt pro
zákazníka, nájemní smlouva, interní režie nebo interní úkol. Najdeš je
v **Zakázky → Zakázky**. Zakázku přiřadíš dokladům a v účetním deníku
pak vidíš její náklady a výnosy (viz
[Zakázka na dokladech](zakazka-na-dokladech.md)).

## Kdy to potřebuješ

Chceš vědět, kolik tě stojí a kolik vynáší jednotlivý projekt nebo
smlouva. Nebo máš větší projekt rozdělený na dílčí práce a chceš je držet
pod jednou zakázkou. Nebo připravuješ periodickou fakturaci — ta bude
stát na zakázkách (viz [Co Shipard dnes neumí](../co-dnes-nejde.md)).

## Postup

**Před první zakázkou**

Zakázky se zakládají do **číselné řady** a řada patří jednomu **druhu
zakázky**. Druh nese **Typ zakázky**, který určuje, co zakázka má:

| Typ | K čemu je | Má zákazníka | Smí mít nadřazenou |
|---|---|---|---|
| **Periodická** | smlouva, ze které se bude periodicky fakturovat | ano | ne |
| **Externí jednorázová** | projekt nebo zakázka pro zákazníka | ano | ano |
| **Interní průběžná** | režie, provoz, vlastní dlouhodobé činnosti | ne | ne |
| **Interní jednorázová** | vnitřní úkol, dílčí práce pod jinou zakázkou | z nadřazené | ano |

Druh a řadu založíš podle [Nastavení zakázek](nastaveni-zakazek.md).
Dokud žádná řada není, tlačítko **Přidat** zakázku nezaloží.

**Založení zakázky**

1. V **Zakázky → Zakázky** vyber dole záložku s číselnou řadou a dej
   **Přidat**. Řada určí druh a typ zakázky; změnit ji jde jen u konceptu.
2. Vyplň **Název**. U externích typů vyber **Zákazníka** (povinný před
   potvrzením), **Měnu** (předvyplní se domácí) a případně **Variabilní
   symbol** — pevný VS, který ponesou všechny faktury zakázky.
3. U jednorázových typů můžeš vybrat **Nadřazenou zakázku**. Nabízí se
   zakázky V pořádku, V opravě i koncepty; periodická zakázka nadřazenou
   být nesmí a zakázka nesmí být nadřazená sama sobě ani přes řetězec
   podzakázek.
4. Vyplň **Zahájení** (povinné před potvrzením) a **Středisko**, když ho
   používáš. **Interní poznámka** zůstává jen v Shipardu.
5. Přílohy (smlouva, objednávka) dej na záložku **Přílohy**.
6. Dej **V pořádku**. Zakázka dostane **Číslo** z řady a od té chvíle ji
   jde vybírat na dokladech.

**Oprava a ukončení**

1. Potvrzenou zakázku upravíš přes **Opravit** a vrátíš do **V pořádku**.
   Číslo zůstává — zakázka se do konceptu nevrací.
2. Hotovou zakázku **Ukonči**, nerealizovanou **Zruš**. Obě skončí v
   záložce **Archív**; **Ukončení** se doplní dnešním datem, když ho
   nevyplníš sám. U periodické zakázky je **Ukončení** datum, do kdy
   zakázka platí — smí být v budoucnu.
3. Z archivu se zakázka dostane zpět přes **Opravit**. Smazat jde jen
   koncept.

**Detail zakázky**

- **Přehled** ukazuje hlavičku, u interní jednorázové zakázky zákazníka
  z nejbližší nadřazené externí zakázky (řádek **Zákazník z**), a tabulku
  **Nadřazená zakázka a podzakázky** s odkazy do jejich detailu.
- **Deník** ukazuje obraty po účetních letech a zápisy účetního deníku
  s touto zakázkou — jen její vlastní, podzakázky se nesčítají. Tlačítko
  **Otevřít v deníku** otevře účetní deník s filtrem na zakázku.

## Na co narazíš

**Bez účetního roku zakázku nepotvrdíš.** Řada s restartem po roce bere
rok z data **Zahájení**; chybí-li pro něj účetní rok, potvrzení skončí
chybou u zahájení — založ rok v **Nastavení aplikace → Účetnictví →
Fiskální období**.

**Pole, která typ nemá, na formuláři nejsou.** Interní zakázka nemá
zákazníka, měnu ani VS; průběžná nemá nadřazenou. Když změníš řadu
konceptu na jiný typ, tahle pole se vyprázdní.

**Zakázka se v deníku objeví až po zaúčtování dokladu, který ji nese.**
Pole **Zakázka** na dokladech zapíná nastavení, viz
[Zakázka na dokladech](zakazka-na-dokladech.md).

**Cena, předmět dodávky, termíny a vyhodnocení projektu zatím nejsou**,
stejně jako periodická fakturace ze zakázky. Viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Nastavení zakázek](nastaveni-zakazek.md)
- [Zakázka na dokladech](zakazka-na-dokladech.md)
- [Střediska](../uctarna/strediska.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
