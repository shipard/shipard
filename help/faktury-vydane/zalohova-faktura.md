---
title: Zálohová faktura
summary: Kdy vystavit zálohovou fakturu (proformu) místo faktury, proč není daňovým dokladem a jak ji vystavit — od Přidat po V pořádku.
keywords: [zálohová faktura, zálohové faktury, zálohovou fakturu, proforma, proforma faktura, proformu, výzva k platbě, záloha, zálohu, platba předem, vystavit zálohovou fakturu, vystavení zálohové faktury, zálohová faktura vydaná, zálohové faktury vydané, DUZP na zálohové faktuře, není daňový doklad, daňový doklad k záloze, odpočet zálohy, odpočet přijaté zálohy, faktura z proformy, variabilní symbol zálohy]
related: [faktury-vydane/vystaveni-faktury.md, osoby/zalozeni-osoby.md, polozky/zalozeni-polozky.md, faktury-prijate/oprava-dokladu.md, co-dnes-nejde.md]
---

# Zálohová faktura

Zálohová faktura (proforma) je výzva k platbě předem: odběrateli říká, kolik
a na jaký účet má zaplatit, ještě než mu dodáš zboží nebo službu. Najdeš ji
v **Prodej → Zálohové faktury vydané**.

**Není to daňový doklad.** DPH na ní vidíš jen orientačně — v sazbách na
řádcích, v rekapitulaci a v součtu s daní, tak jak ho odběratel čeká — ale
doklad nemá DUZP a nevstupuje do přiznání DPH ani do kontrolního a souhrnného
hlášení. Daň přiznáš až z faktury.

**Vytisknout ji zatím nejde** — stejně jako fakturu, viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Kdy to potřebuješ

Chceš od zákazníka peníze předem — zálohu na zakázku, platbu za zboží před
odesláním, předplatné — a potřebuješ mu poslat doklad s částkou, účtem
a variabilním symbolem, aniž bys vystavil fakturu a přiznal daň z něčeho, co
ještě neproběhlo.

## Postup

1. **Otevři Prodej → Zálohové faktury vydané a dej Přidat.**

2. **Vyplň hlavičku.** Je stejná jako u vydané faktury, jen bez DUZP:

   - **Partner** je odběratel. Hledá se psaním; když ho v evidenci ještě nemáš,
     založíš ho přímo odsud — viz [Založení osoby](../osoby/zalozeni-osoby.md).
   - **Datum vystavení** a **Účetní datum** jsou předvyplněné dnešním dnem.
     **Datum splatnosti** se doplní podle splatnosti sjednané u partnera
     (bez ní 14 dní od vystavení), přepsat ho jde kdykoli.
   - **Způsob platby** nech *Převodem* — proforma je především výzva
     k platbě na účet.
   - **Text dokladu** je krátký popis, pod kterým ji poznáš v seznamu.

   Na záložce **Nastavení** — úplně vpravo, za **Přílohami** — zkontroluj
   dvě předvybrané věci: **Náš bankovní účet** je povinný, je to účet, na
   který má odběratel zaplatit; **Registrace DPH** je potřeba, aby šel na
   řádku vybrat **Kód DPH**.

3. **Dej Uložit a zadej řádky.** Tab **Řádky** → **Přidat**, stejně jako
   u faktury — postup řádku najdeš ve
   [Vystavení faktury](vystaveni-faktury.md). Na proformě jsou jen pohyby
   *Prodej služeb* a *Prodej zboží*. **DPH %** se ke zvolenému kódu doplní
   podle **Data vystavení**, protože DUZP proforma nemá.

4. **Zkontroluj tab Rekapitulace DPH.** Je to rozpis částky, kterou má
   odběratel zaplatit, po sazbách a celkem s daní.

5. **Dej Potvrdit.** Proforma dostane **číslo** z vlastní číselné řady
   (Shipard ji zakládá sám) a jako **Variabilní symbol** se předplní pořadové
   číslo v řadě. Tenhle symbol pak odběratel uvede na platbě.

6. **Dej V pořádku.** Doklad se uzamkne a formulář se zavře. Zaúčtování
   proformy a spárování její úhrady v saldokontu Shipard zatím nedělá — viz
   [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Na co narazíš

**DUZP na zálohové faktuře nenajdeš.** Není to chyba: proforma není daňový
doklad, DUZP ani datum povinnosti přiznat daň nemá a do žádného výkazu DPH
nespadá. Sazby a rekapitulaci vidíš dál — jsou informativní.

**Fakturu z proformy zatím nevystavíš jedním klikem.** Až zálohu dostaneš
a budeš fakturovat, vystav vydanou fakturu ručně a zálohu na ní odečti řádkem
s pohybem *Odpočet přijaté zálohy* a variabilním symbolem proformy. Daňový
doklad k přijaté platbě zatím Shipard neumí.

**Číselnou řadu ve formuláři nenajdeš.** Platí totéž co u faktur: doklad jde
do řady vybrané v liště pod seznamem; s jedinou řadou lišta není vidět.

**Opravy a storno** fungují jako u ostatních dokladů — přechody popisuje
[Oprava dokladu](../faktury-prijate/oprava-dokladu.md).

## Souvisí

- [Vystavení faktury](vystaveni-faktury.md) — řádky, rekapitulace a stavy
  jsou stejné
- [Založení osoby](../osoby/zalozeni-osoby.md) — jak dostat odběratele do
  evidence
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na řádek
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — tisk, faktura z proformy,
  daňový doklad k záloze
