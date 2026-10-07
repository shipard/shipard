---
title: Zálohová faktura přijatá
summary: Jak zadat výzvu dodavatele k platbě předem, proč není daňovým dokladem, co se stane po zaplacení a jak zálohu odečíst na konečné faktuře.
keywords: [zálohová faktura přijatá, zálohové faktury přijaté, zálohovou fakturu přijatou, přijatá proforma, proforma od dodavatele, proforma faktura přijatá, výzva k platbě, výzva k platbě od dodavatele, záloha dodavateli, zaplatit zálohu, platba předem dodavateli, poskytnutá záloha, poskytnuté zálohy, zálohová faktura od dodavatele, DUZP na zálohové faktuře přijaté, není daňový doklad, daňový doklad k poskytnuté záloze, odpočet poskytnuté zálohy, odpočet zálohy na faktuře přijaté, variabilní symbol výzvy, zaúčtování zálohové faktury přijaté, podrozvaha, úhrada zálohové faktury přijaté, zaplacená výzva, poskytnutá záloha v saldokontu, saldokonto zálohové faktury přijaté, částečná úhrada výzvy, uzavření výzvy k platbě, záloha v hotovosti dodavateli, storno výzvy k platbě]
related: [faktury-prijate/dokonceni-dokladu.md, faktury-prijate/oprava-dokladu.md, faktury-vydane/zalohova-faktura.md, osoby/zalozeni-osoby.md, polozky/zalozeni-polozky.md, co-dnes-nejde.md]
---

# Zálohová faktura přijatá

Zálohová faktura přijatá je výzva dodavatele k platbě předem: říká, kolik
a na jaký účet máš zaplatit, ještě než ti dodavatel dodá zboží nebo službu.
Najdeš ji v **Nákup → Zálohové faktury přijaté**. Je to zrcadlo
[Zálohové faktury](../faktury-vydane/zalohova-faktura.md), kterou vystavuješ
ty svým odběratelům.

**Není to daňový doklad.** DPH na ní vidíš jen orientačně — v sazbách na
řádcích, v rekapitulaci a v součtu s daní, tak jak ji dodavatel na výzvě
uvádí — ale doklad nemá DUZP ani DPPD a do přiznání DPH ani do kontrolního
hlášení nevstupuje. Odpočet daně uplatníš až z daňového dokladu, který ti
dodavatel pošle po zaplacení, nebo z konečné faktury.

## Kdy to potřebuješ

Dodavatel ti poslal proformu (výzvu k platbě) a chceš ji mít v evidenci:
aby platba z banky nevisela jako nespárovaná, aby bylo vidět, co ještě máš
zaplatit, a aby se poskytnutá záloha správně potkala s konečnou fakturou.

## Postup

1. **Otevři Nákup → Zálohové faktury přijaté a dej Přidat.**

2. **Vyplň hlavičku.** Je stejná jako u faktury přijaté, jen bez DUZP
   a DPPD:

   - **Partner** je dodavatel. Hledá se psaním; když ho v evidenci ještě
     nemáš, založíš ho přímo odsud — viz
     [Založení osoby](../osoby/zalozeni-osoby.md).
   - **Bankovní účet** nebo **IBAN** — účet dodavatele, na který máš
     zaplatit. Vyber ho z účtů dodavatele, nebo IBAN opiš z výzvy. Bez
     něj Shipard při **V pořádku** jen upozorní, doklad ale uložit nechá.
   - **Variabilní symbol** opiš z výzvy. Podle něj Shipard pozná tvou
     platbu z banky nebo z pokladny a výzvu jí uzavře.
   - **Ev. číslo dokladu** je číslo výzvy u dodavatele.
   - **Datum vystavení** a **Účetní datum** jsou předvyplněné dnešním
     dnem, **Datum splatnosti** se doplní podle splatnosti u partnera.
   - **Text dokladu** je krátký popis, pod kterým ji poznáš v seznamu.

3. **Dej Uložit a zadej řádky.** Tab **Řádky** → **Přidat**, stejně jako
   u faktury přijaté. Na výzvě jsou jen pohyby *Nákup zboží a materiálu*,
   *Nákup služeb* a *Ostatní nákup*; **DPH %** se ke zvolenému kódu
   doplní podle **Data vystavení**, protože DUZP výzva nemá. Na účtování
   řádky nemají vliv — zaúčtuje se jen celková částka.

4. **Zkontroluj tab Rekapitulace DPH.** Je to rozpis částky, kterou máš
   zaplatit, po sazbách a celkem s daní — má sedět s výzvou.

5. **Dej Potvrdit.** Doklad dostane **číslo** z vlastní číselné řady
   (Shipard ji zakládá sám).

6. **Dej V pořádku.** Doklad se uzamkne, formulář se zavře a výzva se
   zaúčtuje **na podrozvahu** celkovou částkou — do rozvahy, výsledovky
   ani DPH nevstupuje. V saldokontu ji od té chvíle vidíš ve skupině
   **Zálohové faktury přijaté** jako otevřenou položku, dokud ji
   nezaplatíš.

## Na co narazíš

**DUZP ani DPPD na výzvě nenajdeš.** Není to chyba: výzva není daňový
doklad a do žádného výkazu DPH nespadá. Sazby a rekapitulaci vidíš dál —
jsou informativní.

**Po zaplacení se výzva v saldokontu uzavře sama.** Když z banky odejde
platba s variabilním symbolem výzvy (nebo zaplatíš z pokladny výdajovým
dokladem s pohybem *Poskytnutá záloha* a symbolem výzvy), Shipard ji
zaúčtuje jako poskytnutou zálohu a zároveň výzvu ve skupině
**Zálohové faktury přijaté** uzavře — funguje to i tehdy, když jsi
zaplatil dřív, než jsi výzvu potvrdil. V saldokontu pak vidíš dvě věci:
výzva je vyrovnaná a ve skupině **Poskytnuté zálohy** leží pod jejím
symbolem otevřená záloha. Tu uzavře až konečná faktura přijatá.

**Stejný variabilní symbol na faktuře i na výzvě.** Pokud máš od téhož
dodavatele otevřenou fakturu přijatou se stejným symbolem, platba se
spáruje s fakturou, ne s výzvou — závazky mají přednost.

**Částečná platba nechá zbytek výzvy otevřený.** Zaplatíš-li míň, výzva
zůstává ve skupině **Zálohové faktury přijaté** s nedoplatkem a další
platba se stejným symbolem ho uzavře. Zaplatíš-li víc, výzva se uzavře
jen do své výše — přebytek zůstává jako poskytnutá záloha. Výzva v cizí
měně se uzavírá svým kurzem, takže kurzový rozdíl z platby zůstává jen na
záloze.

**Zálohu na konečné faktuře odečteš ručně.** Až přijde konečná faktura,
zadej ji jako běžnou fakturu přijatou a přidej řádek s pohybem *Odpočet
poskytnuté zálohy* se zápornou částkou zálohy a s variabilním (a případně
specifickým) symbolem výzvy. Tím se poskytnutá záloha v saldokontu
uzavře. Daňový doklad k zaplacené záloze zatím Shipard neumí.

**Výzvu, kterou platit nebudeš, stornuj — ale jen nezaplacenou.** Storno
nezaplacené výzvy ji odúčtuje a ze saldokonta zmizí. Výzvu, která už má
platbu, zatím Shipard před stornem nehlídá; stornem by se rozpojila od
své zálohy, proto ji nech být.

**Číselnou řadu ve formuláři nenajdeš.** Platí totéž co u faktur: doklad
jde do řady vybrané v liště pod seznamem; s jedinou řadou lišta není
vidět.

**Opravy a storno** fungují jako u ostatních dokladů — přechody popisuje
[Oprava dokladu](oprava-dokladu.md).

## Souvisí

- [Dokončení dokladu](dokonceni-dokladu.md) — stavy Koncept, Potvrzeno
  a V pořádku
- [Oprava dokladu](oprava-dokladu.md) — oprava a storno
- [Zálohová faktura](../faktury-vydane/zalohova-faktura.md) — totéž
  z druhé strany, pro odběratele
- [Založení osoby](../osoby/zalozeni-osoby.md) — jak dostat dodavatele do
  evidence
- [Založení položky](../polozky/zalozeni-polozky.md) — co se dá dát na
  řádek
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — výzva z pošty, odpočet
  zálohy, daňový doklad k záloze
