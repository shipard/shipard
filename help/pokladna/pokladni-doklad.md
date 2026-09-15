---
title: Pokladní doklad
summary: Jak zapsat příjem nebo výdej hotovosti či platbu kartou na pokladně — včetně úhrady faktury — a co k tomu musí být nastavené.
keywords: [pokladní doklad, příjmový pokladní doklad, výdajový pokladní doklad, příjmový doklad, výdajový doklad, pokladna, hotovost, platba hotově, platba kartou, úhrada faktury hotově, zaplaceno hotově, pokladní lístek, paragon, směr pokladního dokladu, příjem, výdej, převod peněz, odvod hotovosti do banky, dotace pokladny, převod mezi pokladnami, peníze na cestě, záloha v hotovosti, přijatá záloha, poskytnutá záloha, odpočet zálohy, vrácení zálohy, archivovaná pokladna]
related: [pokladna/prodejka.md, pokladna/platba-kartou-branou-dobirkou.md, faktury-vydane/vystaveni-faktury.md, faktury-prijate/oprava-dokladu.md, uctarna/kdyz-se-doklad-nezauctuje.md, co-dnes-nejde.md]
---

# Pokladní doklad

Pokladní doklad zapisuje pohyb peněz na jedné pokladně: **příjem** (někdo ti
zaplatil hotově nebo kartou) nebo **výdej** (zaplatil jsi hotově ty). Najdeš
ho v **Účtárna → Pokladní doklady**. Každá pokladna má vlastní číselnou řadu,
takže se v seznamu přepínáš mezi pokladnami stejně jako mezi řadami faktur.

## Kdy to potřebuješ

- Prodal jsi službu nebo zboží za hotové a nechceš vystavovat fakturu.
- Zákazník zaplatil vydanou fakturu hotově nebo kartou na pokladně.
- Koupil jsi něco za hotové (materiál, drobný nákup) nebo hotově zaplatil
  přijatou fakturu.

Pro rychlý prodej zboží bez partnera se víc hodí [Prodejka](prodejka.md).

## Postup

1. **Založ pokladnu**, pokud ji ještě nemáš: **Nastavení → Účetnictví →
   Pokladny**. Vyplň kód, název a měnu a u pole **Účet pro pohyby** vyber
   účet pokladny (211…). Ulož ji do stavu **V pořádku** — v tu chvíli jí
   Shipard sám založí číselné řady pro pokladní doklady i prodejky.

2. **Otevři Účtárna → Pokladní doklady.** Máš-li pokladen víc, vyber dole
   pod seznamem záložku té správné. Dej **Přidat**.

3. **Vyber Směr pokladního dokladu** — *Příjem* nebo *Výdej*. Bez směru
   doklad neuložíš a podle směru se řídí, jaké pohyby řádků a jaké kódy DPH
   se ti nabídnou. Po zadání prvního řádku už směr měnit nejde.

4. **Vyplň zbytek hlavičky.** **Partner** je nepovinný — anonymní příjem
   či výdej se obejde bez něj. **Datum vystavení** a **Účetní datum** jsou
   povinné; **DUZP** se doplní podle vystavení. U příjmu můžeš zadat i
   **DPPD** (den přijetí hotovosti, když předchází DUZP — např. záloha);
   u výdeje se doplní podle DUZP. **Ev. číslo dokladu** je číslo dokladu
   od partnera (účtenka, paragon). **Místo plnění** funguje jako na
   fakturách. **Měna dokladu** je vždy měna pokladny a nedá se změnit;
   kterou pokladnu doklad patří, vidíš v sekci **Pokladna**.

   **Způsob platby** (*Hotovost* nebo *Kartou*, jiné se tu nenabízejí),
   registraci DPH a zaokrouhlení najdeš na tabu **Nastavení** úplně
   vpravo — předvyplní se a běžně na ně nesaháš. U příjmu kartou se tam
   doplní **Platební terminál / brána** pokladny a **Plátce** (protistrana
   terminálu) — viz [Platba kartou, přes bránu a dobírkou](platba-kartou-branou-dobirkou.md);
   bez terminálu musí mít doklad partnera.

5. **Dej Uložit** a zadej řádky na tabu **Řádky**. **Pohyb** vybírej podle
   toho, co se stalo:

   - *Prodej služeb* / *Prodej zboží* — příjem za prodej; řádek má
     množství, cenu a **Kód DPH** jako na faktuře.
   - *Nákup zboží a materiálu* / *Nákup služeb* / *Ostatní nákup* — výdej
     za nákup, také s DPH.
   - *Úhrada pohledávky* (u příjmu) / *Úhrada závazku* (u výdeje) — zákazník
     platí vydanou fakturu, nebo ty platíš přijatou. Zadáváš jen **Částku**,
     **Partnera** a **Variabilní symbol** hrazené faktury; DPH se tu neřeší,
     tu už nese faktura. Partner a variabilní symbol jsou povinné — bez nich
     by se úhrada nespárovala.
   - *Příjem z převodu peněz* (u příjmu) / *Výdej pro převod peněz* (u
     výdeje) — peníze se jen přesouvají mezi pokladnou a bankou nebo mezi
     dvěma pokladnami: odvod hotovosti do banky, dotace pokladny z účtu,
     přesun do druhé pokladny. Zadáváš jen **Částku**; partner se nevybírá
     a DPH se neřeší. Do **Variabilního symbolu** si můžeš poznamenat, s čím
     převod souvisí (číslo bankovní transakce, doklad druhé pokladny) — není
     to povinné. Druhou stranu převodu zapíšeš na bankovní transakci
     (Pohyb *Příjem z převodu peněz* / *Výdej pro převod peněz*) nebo
     pokladním dokladem opačného směru na druhé pokladně; dokud tam není,
     zůstávají peníze na účtu *Peníze na cestě*.
   - *Přijatá záloha* (u příjmu) / *Poskytnutá záloha* (u výdeje) — zákazník
     ti složil zálohu v hotovosti, nebo ty jsi zálohu zaplatil. Zadáváš
     **Částku** a **Partnera** (ten je povinný), do **Variabilního symbolu**
     můžeš dát číslo zálohové faktury nebo smlouvy. DPH se tu neřeší —
     zdanění zálohy je samostatný daňový doklad. Vrácení zálohy zapíšeš
     stejným pohybem se zápornou částkou.
   - *Odpočet přijaté zálohy* (u příjmu) / *Odpočet poskytnuté zálohy* (u
     výdeje) — při konečném vyúčtování se dřív zaplacená záloha odečte.
     Funguje stejně jako na faktuře: řádek se **zápornou** částkou, s
     množstvím, cenou a **Kódem DPH** jako u zdaněné zálohy, do
     **Variabilního symbolu** číslo zálohového dokladu.
   - *Účetní položka* — cokoli jiného, účet dá vybraná položka.

6. **Dej Potvrdit a pak V pořádku.** Doklad dostane číslo z řady pokladny
   (například `31HP12600001`: typ, kód pokladny, rok, pořadí), zaúčtuje se
   a úhrada faktury se v saldokontu spáruje s fakturou.

## Na co narazíš

**Bez pokladny se doklad založit nedá.** Seznam Pokladní doklady je prázdný
a **Přidat** ukáže prázdný výběr pokladny. Nejdřív založ pokladnu v
**Nastavení → Účetnictví → Pokladny** a ulož ji do stavu **V pořádku**.

**Směr se nedá změnit.** Jakmile má doklad řádky, je pole **Směr pokladního
dokladu** jen pro čtení — změna směru by zneplatnila pohyby řádků. Špatný
směr vyřeš smazáním řádků, nebo založ nový doklad.

**Pohyb, který hledáš, v nabídce není.** Nabídka se řídí směrem: u příjmu
jsou jen prodejní pohyby, úhrada pohledávky, příjem z převodu peněz,
přijatá záloha a její odpočet; u výdeje jen nákupní pohyby, úhrada závazku,
výdej pro převod peněz, poskytnutá záloha a její odpočet. *Účetní položka*
je v obou.

**Pokladna je v archivu.** Na pokladnu ve stavu **V archívu** nový doklad
nezaložíš — v seznamu ani při přidávání se nenabízí. Historické doklady na
ní zůstávají čitelné (a přenesou se i převodem dat ze starého systému).

**Odvod do banky nebo dotace pokladny.** Nezakládej k tomu nákup ani
prodej — použij pohyb *Výdej pro převod peněz* (peníze odcházejí
z pokladny) nebo *Příjem z převodu peněz* (přicházejí do ní) a na bankovní
transakci zvol tentýž pohyb v opačném směru. Převod mezi dvěma pokladnami
= výdajový doklad na jedné a příjmový na druhé.

**Částka je vždy kladná.** Zda jde o příjem nebo výdej, říká směr, ne
znaménko. Vratka zaplacené částky je u pokladního dokladu opačný směr,
u prodejky záporný řádek.

**Doklad se nezaúčtoval a svítí upozornění.** Nejčastěji pokladna nemá
vyplněný **Účet pro pohyby**. Doplň účet a v detailu dokladu dej
**Přeúčtovat** — viz
[Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md).

**Příjem kartou je pohledávka za terminálem**, ne peníze v pokladně:
zaúčtuje se za plátce (provozovatele terminálu) s variabilním symbolem
rovným číslu dokladu a uzavře ji až jeho vyúčtování. U úhrady faktury
kartou tak vzniknou dva zápisy: faktura zákazníka se uhradí a pohledávka
se přesune na terminál.

**Faktura zaplacená hotově přímo při vystavení.** Nemusíš k ní dělat
pokladní doklad: na faktuře zvol **Způsob platby** *Hotovost* a vyber
**Pokladnu**. Faktura se pak rovnou zaúčtuje proti pokladně a v saldokontu
se jako nezaplacená neobjeví.

**Pokladní knihu ani tisk dokladu tu nenajdeš** — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Prodejka](prodejka.md) — rychlý prodej za hotové bez partnera
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md) — faktura
  placená hotově se dá zaúčtovat proti pokladně rovnou
- [Oprava dokladu](../faktury-prijate/oprava-dokladu.md) — přechody stavů
  platí i pro pokladní doklady
- [Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — pokladní kniha, tisk
