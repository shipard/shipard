---
title: Vystavení faktury
summary: Jak vystavit fakturu odběrateli — od Přidat po V pořádku — a proč ji z Shipardu zatím nedostaneš na papír.
keywords: [vystavit fakturu, vystavení faktury, vystavuji fakturu, vydaná faktura, vydané faktury, faktura odběrateli, faktura zákazníkovi, fakturovat, nová vydaná faktura, prodej služeb, prodej zboží, poslat fakturu odběrateli, odeslat fakturu e-mailem, vytisknout fakturu, tisk faktury, PDF faktury, náš bankovní účet, variabilní symbol na faktuře, způsob výpočtu, z ceny celkem, sleva na řádku, DPH na řádku, základ daně řádku]
related: [osoby/zalozeni-osoby.md, polozky/zalozeni-polozky.md, pokladna/platba-kartou-branou-dobirkou.md, faktury-prijate/oprava-dokladu.md, co-dnes-nejde.md]
---

# Vystavení faktury

Faktura vydaná je doklad pro odběratele. Zakládá se vždy ručně — z došlé pošty,
na rozdíl od přijatých faktur, nevzniká. Najdeš ji v **Prodej → Faktury
vydané**.

**Hotovou fakturu z Shipardu zatím nedostaneš.** Tisk, PDF ani odeslání
odběrateli e-mailem neexistují — viz [Co Shipard dnes neumí](../co-dnes-nejde.md).
Vystavit, zaúčtovat a hlídat ji v saldokontu jde celou; doklad, který drží
v ruce odběratel, musíš zatím vyrobit jinde.

## Kdy to potřebuješ

Fakturuješ odběrateli a chceš mít fakturu v evidenci, v účetnictví a v přehledu
toho, kdo ti kolik dluží. Nebo zkoušíš, co Shipard u vydané faktury umí a co ne.

## Postup

1. **Otevři Prodej → Faktury vydané a dej Přidat.**

2. **Vyplň hlavičku.** Vlevo je partner, způsob platby a datumy, vpravo
   DPH, měna, platební symboly a období:

   - **Partner** je odběratel. Hledá se psaním; když ho v evidenci ještě nemáš,
     založíš ho přímo odsud — viz [Založení osoby](../osoby/zalozeni-osoby.md).
   - **Adresa partnera** se dá vybrat teprve po zvolení partnera. Bankovní
     účet odběratele se na vydané faktuře nezadává — platí on tobě.
   - **Způsob platby** — když zvolíš *Hotovost*, objeví se pod ním ještě
     **Pokladna**. U *Kartou* a *Platební bránou* se objeví **Platební
     terminál / brána**, u *Dobírkou* vyber **Způsob dopravy**; pole
     **Plátce** pak říká, za kým pohledávka vznikne (jinak za odběratelem)
     — viz [Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md).
   - **Datum vystavení**, **Účetní datum** a **DUZP** jsou předvyplněné
     dnešním dnem. Dokud faktura není uložená, jdou Účetní datum a DUZP
     za Datem vystavení: přepiš ho, opusť pole a obě se přepíšou podle
     něj. Potřebuješ-li je jiné, nastav je až po Datu vystavení.
   - **Datum splatnosti** nechat prázdné můžeš. Doplní se po výběru
     partnera podle splatnosti u něj sjednané (bez ní 14 dní od vystavení),
     nejpozději po **Uložit**. Přepsat ho jde kdykoli. Datum povinnosti
     přiznat daň (DPPD) na vydané faktuře nevyplňuješ, Shipard ho bere z DUZP.
   - **Text dokladu** je krátký popis, pod kterým fakturu poznáš v seznamu.

   Dvě povinné věci jsou na záložce **Nastavení** — úplně vpravo, za
   **Přílohami** — protože se u tebe většinou nemění. Obě jsou u nové
   faktury předvybrané; zkontroluj je, jen když máš registrací nebo účtů víc:

   - **Registrace DPH** je u faktury s daní povinná; předvybraná je první
     z tvých registrací. Bez ní nepůjde na řádku zvolit **Kód DPH** a doklad
     nepotvrdíš.
   - **Náš bankovní účet** je u vydané faktury povinný — je to účet, na který
     má odběratel zaplatit. Předvybraný je účet označený v Nastavení jako
     **Výchozí**; bez výchozího účtu ho vyber ručně, jinak doklad nepotvrdíš.

3. **Dej Uložit.** Řádky se dají zadávat až u uloženého dokladu; do té doby
   na tabu **Řádky** stojí, že je potřeba záznam nejprve uložit.

4. **Zadej řádky.** Tab **Řádky** → **Přidat**. Na řádku vyplň:

   - **Pohyb** — *Prodej služeb* (předvolený) nebo *Prodej zboží*. Určuje,
     jak se řádek zaúčtuje.
   - **Položka** z katalogu — vytáhne popis, jednotku a prodejní cenu.
     Chybějící položku založíš přímo z řádku, viz
     [Založení položky](../polozky/zalozeni-polozky.md).
   - **Množství** a **Cena/jednotka**; **Cena celkem** se dopočítá sama
     a nejde přepsat. Znáš-li naopak jen celkovou částku, přepni **Způsob
     výpočtu** na *Z ceny celkem* — pak zadáváš **Cena celkem** a dopočítá
     se **Cena/jednotka**. Slevu zadej v sekci *Sleva* procentem, nebo
     částkou — ne obojím; cena na řádku se slevou nemění, sleva se promítne
     až do základu daně.
   - Nad polem řádku vidíš průběžně **Základ**, **DPH** a **Celkem**.
     Přepočítají se, jakmile opustíš upravené pole — nemusíš řádek ukládat,
     abys viděl, kolik dělá s daní.
   - **Kód DPH** je předvybraný na základní sazbu (*Tuzemsko/Výstup/Základní*)
     a **DPH %** je doplněné podle DUZP. Potřebuje-li řádek jinou sazbu, změň
     kód — procento se přepočítá.

   Potřebuješ-li na faktuře mezititulek nebo komentář, přepni **Typ řádku**
   na *Textový řádek*. Do součtů nevstupuje.

   Nový řádek uložíš tlačítkem **Přidat**. Když zadáváš víc řádků za
   sebou, použij **Přidat a pokračovat**: řádek se uloží a hned máš před
   sebou prázdný formulář pro další.

   Zadané řádky vidíš na tabu **Řádky** v tabulce s popisem, množstvím,
   jednotkou, cenou a DPH. Řádek otevřeš k úpravě tlačítkem **Upravit**
   (tužka) nebo dvojklikem, **Smazat** (koš) se před smazáním zeptá.
   V otevřeném řádku listuješ na sousední šipkami v záhlaví okna.
   Pořadí řádků změníš šipkami **Posunout výš** a **Posunout níž** přímo
   v tabulce; nový řádek se zařadí na konec sám. Součty dokladu se po
   každé změně řádku přepočítají.

5. **Zkontroluj tab Rekapitulace DPH.** Je to rozpis základu a daně po sazbách
   a celkový součet. Nesouhlasí-li s tím, co čekáš, oprav řádky teď — ne až po
   potvrzení.

   Daň se počítá **jednou ze součtu řádků v každé sazbě**, ne po řádcích —
   tak to předepisuje zákon. U dokladu v cenách s DPH proto může být základ
   na řádku o haléř jinak, než kolik by vyšlo z ceny toho řádku samotného;
   součet za sazbu i celková částka jsou vždy správně.

6. **Dej Potvrdit.** Doklad dostane **číslo** z číselné řady, jako
   **Variabilní symbol** se předplní jeho pořadové číslo a do dokladu se
   zmrazí **Fakturační údaje** — tvoje i odběratelovy údaje v podobě, v jaké
   platí teď. Formulář zůstává otevřený a doklad se dál dá upravovat.

7. **Dej V pořádku.** Doklad se uzamkne, **zaúčtuje** a **objeví se
   v saldokontu** jako nezaplacená pohledávka, kterou pak spáruješ s platbou
   z banky. Formulář se zavře. Řádky uzamčeného dokladu si dál můžeš
   prohlédnout: na tabu **Řádky** je u každého tlačítko **Zobrazit** (oko),
   měnit je ale nejde.

## Na co narazíš

**Než vystavíš první fakturu, potřebuješ nastavené tři věci.** Vlastní firmu
v **Osobách** — bez ní Shipard při Potvrzení napíše, že vlastní firma není
nastavená a doklad nepotvrdí. Dál **Registraci DPH** a **Bankovní spojení**.
Nejrychlejší cesta je karta **Dokončit nastavení** na Dashboardu — otevře
panel **Nastavení zdroje dat**, který registraci předvyplní z údajů vlastní
firmy (doplníš jen datum a frekvence) a bankovní účty **převezme** z jejích
bankovních spojení. Ručně obojí najdeš v **Nastavení → Účetnictví**.
Číselnou řadu pro vydané faktury Shipard zakládá sám, o tu se starat
nemusíš.

**Nabídka Kód DPH je prázdná.** Řádek si bere údaje o DPH z *uloženého*
dokladu, takže musí být vybraná **Registrace DPH** (záložka **Nastavení**)
a hlavička uložená. Když jsi registraci právě doplnil, dej **Uložit**
a řádek otevři znovu.

**Číselnou řadu ve formuláři nenajdeš.** U vydaných faktur se na ni Shipard
neptá — doklad se založí do řady, která je právě vybraná v seznamu. Máš-li řad
víc, přepínají se v liště dole pod seznamem faktur a seznam přitom ukazuje jen
doklady vybrané řady. S jedinou řadou, což je běžný stav, lišta není vidět
vůbec.

**Variabilní symbol není číslo faktury.** Předplní se pořadovým číslem v řadě,
ne celým číslem dokladu. Když potřebuješ jiný, přepiš ho — přepis se
nepřepisuje zpátky.

**Do Konceptu se vrací jen poslední faktura v řadě.** Jinak by v číslování
zůstala díra. Když potřebuješ opravit starší fakturu, použij **Storno** a
vystav novou.

**Opravy a storno fungují stejně jako u přijatých faktur.** Stavy jsou
společné všem dokladům: hotovou fakturu převedeš na **V opravě**, opravíš
a vrátíš na **V pořádku**; neplatnou fakturu **Stornuješ**, nemažeš. Přechody
a jejich pravidla popisuje [Oprava dokladu](../faktury-prijate/oprava-dokladu.md)
— je psaná pro přijaté faktury, ale přechody platí i tady. Co u vydaných
faktur platí metodicky jinak, popsané zatím není.

**Že fakturu nikdo neuvidí, není chyba nastavení.** Chybějící tisk, PDF
a odeslání e-mailem je známé omezení alfy, ne něco, co by se dalo někde
zapnout.

## Souvisí

- [Založení osoby](../osoby/zalozeni-osoby.md) — jak dostat odběratele do
  evidence
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na řádek
- [Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md)
  — kdo je plátcem faktury placené kartou, bránou nebo na dobírku
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — přechody stavů
  (psáno pro přijaté faktury)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — chybějící výstupy
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy v rozhraní
