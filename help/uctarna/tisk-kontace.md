---
title: Tisk kontace
summary: Jak k dokladu vytisknout Kontaci — PDF s účetními zápisy, kterými je doklad zaúčtovaný — a co na ní je.
keywords: [kontace, tisk kontace, vytisknout kontaci, košilka, košilka dokladu, účetní zápisy dokladu, předkontace, zaúčtování dokladu vytisknout, PDF zaúčtování, MD Dal dokladu, účtovací předpis dokladu, kontace faktury, kontace pokladního dokladu, kontace účetního dokladu, zaúčtoval]
related: [uctarna/kdyz-se-doklad-nezauctuje.md, faktury-vydane/tisk-faktury.md, pokladna/pokladni-doklad.md, co-dnes-nejde.md]
---

# Tisk kontace

Kontace je PDF s účetními zápisy jednoho dokladu: na které účty a v jakých
částkách je doklad zaúčtovaný. Je to interní výstup — přikládá se k dokladu
do šanonu nebo pro kontrolu účetní, ven z firmy nejde.

## Kdy to potřebuješ

Doklad je ve stavu **V pořádku** a potřebuješ k němu na papír to, co vidíš
na jeho záložce **Zaúčtování**. Kontace jde vytisknout k jakémukoli dokladu:
faktuře vydané i přijaté, zálohové faktuře, pokladnímu dokladu, prodejce
i účetnímu dokladu.

## Postup

1. **Otevři doklad** v jeho seznamu (například **Faktury přijaté** nebo
   **Účetní doklady**). Vpravo se ukáže detail.

2. **Dej Tisk.** U dokladu, který má i vlastní tisk (faktura vydaná,
   zálohová faktura, pokladní doklad, prodejka), se tlačítko rozbalí —
   vyber **Kontace**. U ostatních dokladů je Kontace jediný tisk a otevře
   se rovnou.

3. **Zkontroluj náhled** a dej **Stáhnout**. Soubor se jmenuje
   `kontace-` a číslo dokladu.

Kontace je interní tisk, proto vychází v jazyce země tvé firmy bez ohledu
na partnera dokladu. Jiný jazyk zvolíš výběrem **Jazyk** v okně **Tisk** —
viz [Tisk faktury](../faktury-vydane/tisk-faktury.md).

## Co na kontaci je

- **Titulek** *Kontace* s číslem dokladu, pod ním text dokladu.
- **Účetní jednotka** a **Partner** — tak, jak byly na dokladu zmrazené
  při potvrzení. Doklad bez partnera má místo partnera prázdné.
- **Typ dokladu**, **Datum vystavení**, **Účetní datum** a u daňového
  dokladu datum zdanitelného plnění.
- **Stav účtování** a **Měna**; u dokladu v cizí měně i kurz.
- **Tabulka zápisů** ve stejném pořadí jako na záložce **Zaúčtování**:
  účet, název účtu, text, strana **MD** a **Dal** a pod nimi součet obou
  stran. U dokladu v cizí měně přibudou sloupce MD a Dal v měně dokladu.
  Když zápisy nesou další údaj — třeba **Majetek** —, má vlastní sloupec.
- Dole prázdná linka **Zaúčtoval** na podpis. Jméno Shipard nedoplňuje.
- V zápatí vpravo **Vystavil** se jménem toho, kdo doklad vystavil — viz
  [Kdo doklad vystavil](../faktury-vydane/vystavil-na-dokladu.md). Doklad
  bez autora řádek nemá.

Názvy účtů jsou z dnešního účtového rozvrhu: když účet později
přejmenuješ, Kontace starého dokladu ukáže nový název.

## Na co narazíš

**V nabídce Tisk Kontace není.** Tiskne se jen u dokladu ve stavu
**V pořádku** — koncept, doklad **V opravě** ani **Storno** zápisy v deníku
nemají. Stornovanou fakturu vytiskneš jen jako fakturu s nápisem STORNO.

**Nad náhledem je upozornění, že doklad nemá účetní zápisy.** Doklad se
nezaúčtoval — Kontace vyjde s prázdnou tabulkou. Podívej se na záložku
**Zaúčtování**, proč; viz
[Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md).

**Upozornění říká, že doklad je ve stavu Chyba účtování.** Zápisy na
Kontaci nemusí být úplné ani správné; chybový zápis je v tabulce
zvýrazněný tučně na šedém podkladu. Sprav příčinu, dej **Přeúčtovat**
a Kontaci vytiskni znovu.

**Kontace bankovního výpisu.** Tiskne se jen k dokladům; bankovní
transakce Kontaci nemají.

## Souvisí

- [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md) — co dělat
  s chybou účtování
- [Tisk faktury](../faktury-vydane/tisk-faktury.md) — náhled, stažení
  a hlášení při tisku fungují stejně
- [Pokladní doklad](../pokladna/pokladni-doklad.md) — tisk pokladního
  dokladu pro druhou stranu
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — jména u podpisů, tisk
  reportů
