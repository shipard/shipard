---
title: Když se doklad nezaúčtuje
summary: Co znamenají hlášky u chyby účtování, kde se která spravuje a proč doklad nemusíš rozebírat.
keywords: [chyba účtování, nezaúčtovalo se, nezaúčtovaný doklad, neúčtováno, účtování selhalo, přeúčtovat, účet nenalezen pro masku, deník není vyrovnaný, fiskální rok, fiskální období, chybí analytika, účet 343, nemá vyplněný účet, upozornění chyba účtování]
related: [faktury-prijate/dokonceni-dokladu.md, faktury-prijate/oprava-dokladu.md, uctarna/tisk-kontace.md, slovnicek.md]
---

# Když se doklad nezaúčtuje

Doklad ve stavu **V pořádku** se má zaúčtovat sám. Když to nevyjde, nic se
neztratilo: doklad zůstává uložený, jen k němu není zápis v **Účetním
deníku**. Shipard u něj napíše, co mu chybělo — a tahle stránka říká, kde
se to která hláška spravuje.

## Kdy to potřebuješ

Poznáš to dvěma způsoby:

- na dokladu na záložce **Zaúčtování** je místo odznaku **Zaúčtováno**
  odznak **Chyba účtování** a pod ním důvod,
- na **Dashboardu** je karta *„Doklad … má chybu účtování"* s akcí
  **Otevřít doklad**.

## Postup

1. **Otevři doklad a přepni na záložku Zaúčtování.** Přečti si text
   hlášky — je to jediné, co potřebuješ k rozhodnutí, co dělat.

2. **Najdi hlášku v tabulce níž a sprav příčinu.** Skoro vždy je mimo
   doklad — v nastavení období nebo v účtovém rozvrhu.

3. **Vrať se na doklad a dej Přeúčtovat.** Doklad zůstane ve **V pořádku**
   a účetní zápis se vytvoří znovu z aktuálních dat. **Nepřeváděj ho do
   opravy** — nebylo by to potřeba a rozpojilo by to párování s platbou.

## Co která hláška znamená

| Co Shipard napsal | Co s tím |
|---|---|
| *Doklad nemá přiřazený fiskální rok/měsíc — zkontroluj účetní datum a číselník období* | **Účetní datum** dokladu míří mimo založená období. Buď je datum špatné a opravíš ho na dokladu, nebo období chybí a založí se v **Nastavení → Účetnictví → Fiskální období** |
| *Účet nenalezen pro masku …* | V **Účtárna → Účtový rozvrh** není analytický účet, na který by se zápis vešel. Doplň ho. U masky začínající **343** jde o analytiku k jednomu kódu DPH |
| *Položka řádku není typu Účetní položka nebo nemá vyplněný účet* | Řádek má **Pohyb** *Účetní položka*, takže účet se bere z položky. Buď u řádku změň Pohyb na běžný nákup či prodej, nebo položce doplň **Účet** (jde to jen u položek typu *Účetní položka*). Startovní sadu takových položek — bankovní poplatky, úroky, kurzové rozdíly, zaokrouhlení — vygeneruješ jedním tlačítkem v **Nastavení zdroje dat**, sekce **Volitelné** |
| *Účet uvedený na položce řádku v rozvrhu neexistuje* · *Účet uvedený na řádku v rozvrhu neexistuje* | Účet, na který se odkazuje, byl z rozvrhu vyřazen. Vyber jiný |
| *Řádek účetního zápisu nemá vyplněný účet* | Ruční **Účetní doklad** — u řádku kontace chybí účet |
| *Deník není vyrovnaný: MD … ≠ DAL …* | Ruční **Účetní doklad** — chybí protistrana nebo nesedí částka. Doklad musí mít součet MD stejný jako součet DAL |
| *Z dokladu nevznikl žádný řádek deníku* · *Účtovací předpis pro typ dokladu … nenalezen* | Tohle si nespravíš. Nahlas to — je to chyba na naší straně, ne v tvých datech |

## Na co narazíš

**Odznak se změní hned, karta na Dashboardu ne.** Po **Přeúčtovat** vidíš
na dokladu **Zaúčtováno** okamžitě, ale kontrola upozornění běží po
hodině — karta *„má chybu účtování"* proto může na Dashboardu ještě chvíli
zůstat, i když je doklad v pořádku. Není to další chyba a nemusíš s tím
nic dělat.

**Přeúčtovat nic nerozbije.** Operace je bezpečná i u dokladu, který je
zaúčtovaný správně — vytvoří zápis znovu z týchž dat. Dvojí zápisy
nevzniknou.

**„Neúčtováno" u rozdělaného dokladu není chyba.** Účtuje se jen doklad
ve stavu **V pořádku**. V **Konceptu** a v **Potvrzeno** je odznak
**Neúčtováno** správný stav věci.

**Nejčastější příčina je datum, ne položka.** Když hláška mluví
o fiskálním roku nebo měsíci, začni **Účetním datem** na dokladu — bývá
o rok vedle častěji než cokoli jiného.

**Bankovní transakce mají chyby účtování zvlášť.** Vypadají podobně, ale
jsou to jiné hlášky a jiná místa opravy; tahle stránka o nich není.

## Souvisí

- [Dokončení dokladu](../faktury-prijate/dokonceni-dokladu.md) — jak se
  doklad dostane do stavu, ve kterém se účtuje
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — kdy doklad
  naopak do opravy převést musíš
- [Tisk kontace](tisk-kontace.md) — účetní zápisy dokladu jako PDF
- [Slovníček](../slovnicek.md) — co znamenají odznaky stavu účtování
