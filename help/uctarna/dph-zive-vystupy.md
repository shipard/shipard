---
title: Živé výstupy DPH
summary: Jak si přečíst živé přiznání k DPH, kontrolní hlášení a souhrnné hlášení za zvolené období a co znamenají upozornění pod tabulkou.
keywords: [DPH, přiznání k DPH, kontrolní hlášení, souhrnné hlášení, DPHDP3, DPHKH1, DPHSHV, daňová povinnost, vlastní daň, nadměrný odpočet, období DPH, daňové tvrzení, daňová tvrzení, registrace DPH, kolik zaplatím DPH, sekce A4, sekce B2, kód plnění, souhlasí s deníkem, přesunout doklad do jiného měsíce, koncept tvrzení, koeficient, koeficient odpočtu, krácený odpočet, krácený nárok, zálohový koeficient, vypořádací koeficient, řádek 52, osvobozená plnění, koeficient 1,00, do kontrolního hlášení, ručně do kontrolního hlášení, vždy jednotlivě, vždy souhrnně, nevykazovat v kontrolním hlášení, opakovaná plnění, dílčí plnění, limit 10 000]
related: [uctarna/dph-podani.md, uctarna/export-reportu.md, uctarna/kdyz-se-doklad-nezauctuje.md, faktury-prijate/dokonceni-dokladu.md, co-dnes-nejde.md]
---

# Živé výstupy DPH

Ve skupině **Reporty** jsou tři živé výstupy DPH: **Přiznání k DPH — živě**,
**Kontrolní hlášení — živě** a **Souhrnné hlášení — živě**. Počítají se vždy
znovu z dokladů ve stavu **V pořádku** — nic se nikam neukládá, takže ukazují
aktuální stav i rozpracovaného období.

## Kdy to potřebuješ

- Chceš vědět, kolik DPH za období zaplatíš nebo dostaneš zpět — ještě
  před koncem období.
- Před podáním si chceš zkontrolovat, co do přiznání, kontrolního hlášení
  a souhrnného hlášení vstoupí.
- Kontroluješ, jestli čísla DPH souhlasí s účetním deníkem.

## Postup

1. V levém menu otevři skupinu **Reporty** a vyber jeden z výstupů —
   **Přiznání k DPH — živě**, **Kontrolní hlášení — živě** nebo
   **Souhrnné hlášení — živě**.
2. Nahoře zvol **Období** — nabízejí se **daňová tvrzení** tvé registrace
   pro daný výstup: u přiznání období přiznání (u čtvrtletního plátce
   čtvrtletí), u kontrolního a souhrnného hlášení jejich vlastní období
   (u právnické osoby měsíce). Roletkou **rok** se přepneš na starší
   tvrzení. Máš-li registrací víc, nejdřív vyber **Registraci DPH**.
3. Přečti si výsledek:
   - **Přiznání** ukazuje řádky formuláře se základem a daní; dole jsou
     dopočtené řádky včetně **Vlastní daň** (kolik zaplatíš) nebo
     **Nadměrný odpočet** (kolik dostaneš zpět) — stejná čísla shrnuje
     i řádek pod tabulkou.
   - **Kontrolní hlášení** je rozdělené do sekcí (A1–B3). Doklady nad
     10 000 Kč včetně daně jsou vypsané jednotlivě s ev. číslem a DIČ,
     menší doklady jsou sečtené v souhrnných sekcích A5 a B3.
   - **Souhrnné hlášení** sčítá dodání zboží a služeb do EU po odběratelích
     (podle DIČ) s počtem plnění a hodnotou.

## Odkud se berou období

Období výstupů jsou záznamy v seznamu **Daňová tvrzení** v sekci
**Účtárna** — pro každou registraci a každý výstup (přiznání, kontrolní
hlášení, souhrnné hlášení) zvlášť, s vlastním rozsahem dat. Shipard je
zakládá sám pro běžný měsíc; když uložíš doklad s datem, pro které tvrzení
ještě neexistuje, založí ho jako **Koncept** a upozorní na to na
Dashboardu — zkontroluj rozsah a potvrď ho (**V pořádku**).

Doklad se do tvrzení zařadí při uložení: do přiznání podle data
uskutečnění plnění, do kontrolního a souhrnného hlášení podle data
povinnosti přiznat daň (oříznutého do období přiznání). Kam doklad spadl,
vidíš na jeho hlavičce v sekci **DPH** (pole **Přiznání k DPH**,
**Kontrolní hlášení**, **Souhrnné hlášení**) a můžeš ho tam ručně přesunout
— třeba do jiného měsíce kontrolního hlášení. Při dalším uložení dokladu
se ale zařazení znovu spočítá podle dat.

## Krácený odpočet a koeficient

Když firma vedle zdanitelných plnění uskutečňuje i **plnění osvobozená bez
nároku na odpočet** (třeba pronájem bytů nebo finanční služby), má u části
přijatých dokladů nárok na odpočet jen **krácený** — na dokladu je to kód
DPH s kráceným odpočtem. V přiznání se tyhle částky ukazují ve sloupci
**Krácený odpočet** řádků 40–45 a do celkového nároku vstupují přes řádek
**Krácený odpočet (ř. 40–45 × koeficient)** vynásobené **koeficientem
odpočtu**.

Koeficient platí pro **kalendářní rok** a registraci DPH; zadáš ho v
**Nastavení → Účetnictví → Koeficienty odpočtu DPH** jako desetinné číslo
0,00–1,00 na celá procenta (0,80 = 80 %):

- **Zálohový koeficient** používáš během roku — obvykle je to vypořádací
  koeficient minulého roku, v prvním roce odhad dohodnutý se správcem
  daně.
- **Vypořádací koeficient** doplníš po skončení roku; Shipard ho pak sám
  bere jako zálohový pro rok následující, dokud mu nezadáš jiný.

Záznam platí, až když je ve stavu **V pořádku**. Bez záznamu Shipard
počítá s koeficientem **1,00** — plný nárok, což je správně pro firmu bez
osvobozených plnění. Pokud v období nějaký krácený odpočet je, přiznání
pod tabulkou vždy řekne, jaký koeficient použilo a odkud ho vzalo; bez
záznamu ti navíc doporučí koeficient nastavit.

## Ruční zařazení do kontrolního hlášení

Kontrolní hlášení dělí doklady podle částky: nad **10 000 Kč včetně daně**
jde doklad jednotlivě do sekce A4 nebo B2, pod limit se sčítá v souhrnných
sekcích A5 a B3. Někdy to nestačí — třeba u **opakovaných nebo dílčích
plnění** (měsíční fakturace, splátky), kde je každý doklad pod limitem, ale
plnění jako celek nad ním, a patří proto do detailu. Nebo doklad do hlášení
nepatří vůbec.

Na faktuře vydané i přijaté je proto v záložce **Nastavení** pole
**Do kontrolního hlášení**:

- **Automaticky** — podle limitu, tak je nastavený každý nový doklad.
- **Vždy jednotlivě (A4/B2)** — do detailu i pod limitem. U vydané faktury
  musí mít odběratel české DIČ; bez něj hlášení varuje a soubor pro portál
  nevznikne.
- **Vždy souhrnně (A5/B3)** — do souhrnu i nad limitem.
- **Nevykazovat** — doklad v kontrolním hlášení není vůbec, v přiznání
  k DPH ale zůstává. Použij jen tam, kde to zákon dovoluje; živé hlášení
  ti pod tabulkou řekne, kolik dokladů jsi takhle vyřadil.

Ruční zařazení se v živém hlášení pozná ve sloupci **Zařazení** u řádku
dokladu. Doklady převedené ze starého Shipardu si své zařazení přinesly
s sebou.

## Na co narazíš

- **Tvrzení nejde smazat**, dokud na něj míří nějaký doklad nebo je
  uzamčené. Nejdřív založ správné tvrzení a doklady se do něj přeřadí
  (při změně rozsahu tvrzení se přeřadí samy).
- **Dvě tvrzení stejného typu se nesmí překrývat**; mezera mezi nimi je
  jen upozornění — doklady s datem v mezeře ale do žádného výstupu
  nespadnou.
- **„Součty daně souhlasí s účetním deníkem"** pod přiznáním znamená, že
  DPH z dokladů sedí na účetnictví. Když místo toho vidíš upozornění
  o rozdílu na účtu, bývá příčinou doklad s chybou účtování — viz
  [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md).
- **Chybějící DIČ nebo číslo dokladu dodavatele** hlásí kontrolní hlášení
  jako upozornění a řádek podbarví. Doplň údaj na dokladu (u přijaté
  faktury je číslo dodavatele v poli **Číslo dokladu partnera**).
- Do výstupů vstupují **jen doklady ve stavu V pořádku** — koncept ani
  doklad v opravě v číslech nejsou. Tvrzení ve stavu Koncept je ale
  čitelné normálně.
- **Roční vypořádání koeficientu** (řádek 53 přiznání) Shipard zatím
  nespočítá — vypořádací koeficient zadáváš ručně a vypořádací řádek do
  posledního přiznání roku doplní účetní. Viz
  [Co Shipard dnes neumí](../co-dnes-nejde.md).
- Report je vždy živý výpočet. Trvalý záznam toho, co jsi za období
  odevzdal, vzniká jako **podání** — sestavíš ho v Daňových tvrzeních
  a najdeš v **Účtárna → Podání DPH**, viz
  [Podání DPH](dph-podani.md). Hlavička reportu ti připomene poslední
  podání a jeho podanou daňovou povinnost; ta se od živého výpočtu může
  lišit o jednotky korun (zaokrouhlení po řádcích).
- **Výstup si můžeš stáhnout** tlačítkem **Export** jako sešit pro Excel
  nebo CSV — viz [Export reportu do Excelu nebo CSV](export-reportu.md).
- **Uzamčení období z Shipardu zatím neuděláš** — viz
  [Co Shipard dnes neumí](../co-dnes-nejde.md). Soubor pro daňový portál
  vyrobíš z podání, viz [Podání DPH](dph-podani.md).

## Souvisí

- [Podání DPH](dph-podani.md)
- [Export reportu do Excelu nebo CSV](export-reportu.md)
- [Když se doklad nezaúčtuje](kdyz-se-doklad-nezauctuje.md)
- [Dokončení dokladu](../faktury-prijate/dokonceni-dokladu.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
