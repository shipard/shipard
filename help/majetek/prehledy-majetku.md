---
title: Přehledy majetku
summary: Pět přehledů majetku — sestava odpisů, přírůstky a úbytky, daňové odpisy pro přiznání, soupis majetku a kontrola evidence proti účetnímu deníku — kdy který použít a co dělat, když kontrola hlásí nesoulad.
keywords: [přehledy majetku, reporty majetku, sestava odpisů, sestavu odpisů, přehled odpisů, oprávky, zůstatková cena, zůstatkové ceny, přírůstky a úbytky, přírůstky majetku, úbytky majetku, zařazený majetek, vyřazený majetek, daňové odpisy pro DPPO, podklad pro přiznání, přiznání k dani z příjmů, odpisové skupiny, rozdíl účetních a daňových odpisů, soupis majetku, seznam majetku, inventární seznam, stav majetku k datu, kontrola evidence, kontrola majetku proti deníku, evidence nesouhlasí s deníkem, majetek nesedí na účetnictví, nesoulad pořízení, pořízení nesouhlasí se zařazením, 042 nesedí, zápis bez karty, majetek do Excelu, export majetku, tisk karty majetku]
related: [majetek/odpisy-majetku.md, majetek/zauctovani-majetku.md, majetek/naklady-na-majetek.md, uctarna/export-reportu.md, co-dnes-nejde.md]
---

# Přehledy majetku

V sekci **Majetek** je skupina **Reporty** s pěti přehledy. Čtyři ukazují,
co je v evidenci majetku; pátý — **Kontrola evidence × deník** — hlídá, že
evidence souhlasí s účetním deníkem.

## Kdy to potřebuješ

| Potřebuješ | Přehled |
|---|---|
| Odpisy, oprávky a zůstatkové ceny karet za rok, daňově i účetně | **Sestava odpisů** |
| Co se v období zařadilo, zhodnotilo a vyřadilo | **Přírůstky a úbytky** |
| Podklad daňových odpisů k přiznání k dani z příjmů | **Daňové odpisy pro DPPO** |
| Seznam majetku, který firma k určitému dni má | **Soupis majetku** |
| Ověřit před uzávěrkou, že majetek sedí na účetnictví | **Kontrola evidence × deník** |

## Postup

1. V levém menu otevři **Majetek → Reporty** a vyber přehled.
2. Nahoře zvol **Období**. Sestava odpisů, daňové odpisy pro DPPO a kontrola
   jsou za celý účetní rok; přírůstky a úbytky i za měsíc, čtvrtletí nebo
   pololetí; soupis za rok nebo měsíc. Stav „k datu“ je vždy poslední den
   zvoleného období.
3. Vedle období nastav, co přehled nabízí (viz níže) — přehled se
   přepočítá hned.
4. Kliknutím na **název karty** otevřeš náhled karty. Tlačítkem **Export**
   stáhneš přehled jako Excel nebo CSV, viz
   [Export reportu do Excelu nebo CSV](../uctarna/export-reportu.md).

**Sestava odpisů**

Řádek za každou dlouhodobou kartu, která byla v roce v evidenci: vstupní
cena, oprávky na začátku roku, odpis roku, oprávky a zůstatková cena na
konci — zvlášť daňově a účetně — a rozdíl účetního a daňového odpisu.

- **Seskupit podle**: **Účetní skupina**, **Daňová skupina a metoda**,
  **Typ majetku**, nebo **Neseskupovat**. Skupina má nahoře součtový řádek.
- **Majetek**: **Všechen dlouhodobý**, jen **Odepisovaný**, nebo jen
  **Neodepisovaný**.
- Sloupec **Stav** říká **plán**, když odpis roku ještě není potvrzený —
  číslo je výpočet, ne hotový odpis. U karty vyřazené v roce je datum
  vyřazení.

**Přírůstky a úbytky**

Zařazení, technická zhodnocení, snížení hodnoty a vyřazení s datem
v období, po druzích pohybu s mezisoučtem; na konci **Přírůstky celkem**
a **Úbytky celkem**. U vyřazení je vedle vstupní ceny i **Oprávky při
vyřazení** a **Zůstatková cena při vyřazení**. Drobný majetek se řadí
podle data pořízení a vyřazení na kartě. Sloupec **Doklad zaúčtování**
ukazuje číslo účetního dokladu, kterým je pohyb zaúčtovaný — kliknutím ho
otevřeš. Volbou **Pohyby** omezíš přehled na **Jen přírůstky** nebo
**Jen úbytky**.

**Daňové odpisy pro DPPO**

Uplatněné daňové odpisy roku sečtené po odpisových skupinách (1–6),
zvlášť nehmotný majetek zařazený do roku 2020 a majetek odepisovaný
daňově podle účetních odpisů. Pod součtem jsou **Účetní odpisy celkem**
a **Rozdíl účetní − daňový odpis** — o ten se upravuje základ daně.
Mimořádné odpisy jsou v odpisové skupině svého majetku.

**Soupis majetku**

Karty v evidenci ke konci období včetně drobného a cizího majetku: druh,
typ, datum pořízení, vstupní cena a účetní zůstatková cena. **Seskupit
podle** typu, druhu nebo účetní skupiny; **Cizí majetek** zahrneš, vynecháš
(**Jen vlastní**), nebo ukážeš samotný (**Jen cizí**).

**Kontrola evidence × deník**

Přehled má dvě části.

- **Účty účetních skupin** — pro účet majetku, pořízení, oprávek, odpisů
  a zůstatkové ceny: hodnota podle evidence, hodnota v deníku a **Rozdíl**.
  Účty majetku, pořízení a oprávek se porovnávají konečným zůstatkem roku,
  účty odpisů a zůstatkové ceny obratem roku. Sloupce **Obrat roku
  s kartou** a **Obrat roku bez karty** ukazují, kolik zápisů na účtu nese
  kartu majetku. Kliknutím na název účtu otevřeš účetní deník s tímto
  účtem a rokem.
- **Nesoulady po kartách** — jen karty, u kterých něco nesedí:
  **zaúčtování ≠ deník**, **pořízení ≠ zařazení** a **nezaúčtovaná
  událost**. Kliknutím na název karty přejdeš na kartu, kliknutím na druh
  nesouladu do deníku s řádky té karty.

Když je všechno v pořádku, přehled nemá pod tabulkou žádnou zprávu.
Chyby jsou červeně, varování žlutě; každá zpráva pod tabulkou patří ke
zvýrazněnému řádku.

## Na co narazíš

**Rozdíl na účtu majetku nebo oprávek.** Nejčastěji ruční účetní zápis na
účet majetku bez karty — poznáš ho podle nenulového **Obratu roku bez
karty**. Zápis oprav tak, aby nesl kartu (pole **Majetek** na řádku
dokladu, viz [Majetek na dokladech](naklady-na-majetek.md)), nebo ho
nahraď událostí na kartě. Druhá příčina je karta s **počátečním stavem**,
ke které v účetnictví chybí počáteční zůstatek účtu.

**Rok nemá v deníku počáteční stavy.** Jen varování: rok ještě není
v účetnictví otevřený (otevírací období nemá žádný zápis na účtech
majetku), i když evidence k jeho začátku stav má. Účty majetku, pořízení
a oprávek mají ve sloupci **Deník** prázdno a **Rozdíl** nula — porovnají
se, až rok otevřeš; účty odpisů a zůstatkové ceny se porovnávají dál.

**Rozdíl na účtu pořízení.** Jen varování: evidence na účtu pořízení
počítá pouze pořízení s kartou, takže rozdíl znamená pořízení bez karty
(včetně počátečního zůstatku účtu) nebo zařazení, ke kterému v Shipardu
není navázané pořízení — typicky majetek převzatý ze starého systému.
Zápisy bez karty ukazuje sloupec **Obrat roku bez karty**.

**Pořízení ≠ zařazení.** Na účtu pořízení je s kartou jiná částka, než na
jakou je karta zařazená. Typicky doplatek nebo další faktura po zařazení:
zařaď ji tlačítkem **Technické zhodnocení**. Když je chybně částka
zařazení, oprav událost zařazení. Nezaúčtované zařazení samo nesoulad
není — porovnává se s potvrzenými událostmi.

**Pořízení čeká na zařazení.** Karta s pořízením na dokladu, která ještě
není zařazená, se v kontrole objeví až po 30 dnech. Do té doby ji hlásí
jen upozornění **Majetek čeká na zařazení**.

**Nezaúčtovaná událost.** Potvrzená událost z období, které už mělo být
zaúčtované. Spusť **Odpisy za období** → **Účetní odpisy a zaúčtování**
za to období, viz [Zaúčtování majetku](zauctovani-majetku.md). Události
běžného, ještě neuzavřeného období kontrola nehlásí.

**Zaúčtování ≠ deník.** Doklad, kterým Majetek účtoval, neodpovídá
událostem karty — někdo ho změnil mimo Majetek, nebo karta po zaúčtování
dostala jinou účetní skupinu. Zruš zaúčtování období a zaúčtuj ho znovu.

**Na nesoulad tě Shipard upozorní sám.** Na přehledu **Dashboard** se
objeví upozornění **Evidence majetku nesouhlasí s deníkem** (odkaz
**Otevřít kontrolu**) a **Pořízení majetku nesouhlasí se zařazením**, když
rozdíl trvá déle než 30 dní. Karta s nesouladem má v záložce **Přehled**
nahoře část **Kontrola proti deníku**.

**Plán není hotový odpis.** Sestava odpisů i daňové odpisy pro DPPO za
běžný rok počítají s odpisy, které ještě nejsou potvrzené — přehled to
hlásí pod tabulkou. Konečná čísla dostaneš po **Odpisech za období**.

**Dlouhodobá karta bez zařazení v přehledech není.** Do sestavy odpisů
a soupisu se dostane až zařazením nebo počátečním stavem.

## Souvisí

- [Odpisy majetku](odpisy-majetku.md)
- [Zaúčtování majetku](zauctovani-majetku.md)
- [Majetek na dokladech](naklady-na-majetek.md)
- [Export reportu do Excelu nebo CSV](../uctarna/export-reportu.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
