---
title: Tisk faktury
summary: Jak z hotové faktury nebo zálohové faktury dostat PDF — náhled, stažení, co na dokladu je, tisk stornovaného dokladu a proč se koncept netiskne.
keywords: [tisk faktury, vytisknout fakturu, tisknout fakturu, PDF faktury, fakturu do PDF, uložit fakturu jako PDF, stáhnout fakturu, stažení faktury, náhled faktury, tisk zálohové faktury, vytisknout zálohovou fakturu, vytisknout proformu, PDF zálohové faktury, tlačítko Tisk, tisk dokladu, QR platba, QR kód na faktuře, chybí QR kód, logo na faktuře, logo firmy na dokladu, faktura anglicky, jazyk faktury, faktura v angličtině, jazyk podle odběratele, faktura slovensky, faktura německy, přepnout jazyk tisku, změnit jazyk faktury, výběr jazyka, daňový doklad, tisková služba není dostupná, koncept nejde vytisknout, tisk stornované faktury, storno na faktuře, nápis STORNO, vodoznak storno]
related: [faktury-vydane/vystaveni-faktury.md, faktury-vydane/odeslani-faktury.md, faktury-vydane/zalohova-faktura.md, osoby/zalozeni-osoby.md, pokladna/pokladni-doklad.md, pokladna/prodejka.md, uctarna/tisk-kontace.md, co-dnes-nejde.md]
---

# Tisk faktury

Hotovou fakturu vydanou i zálohovou fakturu dostaneš z Shipardu jako PDF:
prohlédneš si ji v náhledu a stáhneš do počítače. Když ji chceš odběrateli
rovnou poslat, použij **Odeslat** — viz
[Odeslání faktury e-mailem](odeslani-faktury.md).

## Kdy to potřebuješ

Vystavil jsi fakturu nebo zálohovou fakturu, je ve stavu **V pořádku**
a potřebuješ doklad, který dostane odběratel.

## Postup

1. **Otevři Prodej → Faktury vydané** (nebo **Zálohové faktury vydané**)
   a v seznamu klikni na doklad. Vpravo se ukáže jeho detail.

2. **Dej Tisk** a vyber **Faktura** (u zálohové faktury **Zálohová
   faktura**). Tlačítko je nahoře v detailu dokladu, vedle **Přeúčtovat**;
   druhá položka nabídky, **Kontace**, je interní tisk účetních zápisů — viz
   [Tisk kontace](../uctarna/tisk-kontace.md). Otevře se okno **Tisk**
   s náhledem PDF — chvíli to trvá, doklad se teprve vyrábí.

3. **Zkontroluj náhled** a dej **Stáhnout**. Soubor se uloží pod názvem
   s číslem dokladu. Okno zavřeš tlačítkem **Zavřít**.

Doklad potřebuješ v jiném jazyce? Vlevo dole v okně **Tisk** je výběr
**Jazyk** — čeština, angličtina, slovenština, němčina. Náhled se po změně
načte znovu a **Stáhnout** uloží doklad ve zvoleném jazyce. Volba platí jen
pro tento tisk; příště se okno otevře zase v jazyce podle odběratele.

Tisk nic neukládá — PDF vzniká pokaždé znovu z dokladu, takže ho můžeš
stáhnout, kolikrát chceš.

## Stornovaný doklad

Vytisknout jde i doklad ve stavu **Storno** (v nabídce **Tisk** je pak jen
faktura, Kontace ne). PDF je stejné jako před
stornem, jen má přes každou stranu šedý nápis **STORNO** — hodí se, když
odběrateli potřebuješ doložit, že doklad neplatí. Název souboru zůstává
stejný.

## Co na dokladu je

- **Titulek** podle toho, co doklad je: u plátce DPH *Faktura – daňový
  doklad*, u neplátce *Faktura*, u zálohové faktury *Zálohová faktura*
  s větou *Nejedná se o daňový doklad.*
- **Dodavatel a odběratel** z **Fakturačních údajů** zmrazených při
  **Potvrdit** — tedy tak, jak platily při vystavení. Když odběrateli
  později změníš adresu v **Osobách**, vystavená faktura se nezmění.
- **Platební údaje**: způsob úhrady, variabilní, specifický a konstantní
  symbol. **Náš bankovní účet** se tiskne jen u dokladu placeného
  **Převodem** — hotově ani kartou se na něj neplatí.
- **QR platba** — kód, který odběratel načte v bankovní aplikaci. Je jen
  u dokladu placeného **Převodem** s kladnou částkou k úhradě.
- **Řádky** včetně textových; u plátce se sazbou, základem a daní. **Odpočet
  přijaté zálohy** je vytištěný kurzívou a pod řádky je zvlášť částka před
  odpočtem, zálohy a **K úhradě**.
- **Rekapitulace DPH**; u dokladu v cizí měně i základ a daň v domácí měně
  a kurz.
- **Poznámka na doklad**. **Interní poznámka** se netiskne.
- **Logo** v záhlaví, pokud ho máš nahrané v **Nastavení → Aplikace** jako
  **Logo firmy**. Záhlaví s titulkem a číslem dokladu a zápatí s číslem
  strany jsou na každé straně.

## Na co narazíš

**Tlačítko Tisk u dokladu není.** Tiskne se jen doklad ve stavu
**V pořádku** nebo **Storno**. Koncept ani doklad **V opravě** vytisknout
nejde — odběratel by dostal doklad, který se ještě může změnit.

**Nad náhledem je žluté upozornění, že chybí QR platba.** Doklad nemá
bankovní účet, ze kterého by šel kód sestavit — **Náš bankovní účet** na
záložce **Nastavení** dokladu potřebuje IBAN, nebo české číslo účtu s kódem
banky. Druhé upozornění říká, že v kódu chybí variabilní symbol: QR platba
unese jen číslice, nejvýš deset. Symbol s písmeny nebo lomítkem se na
doklad vytiskne, ale do kódu se nedostane. PDF je v obou případech hotové
a použitelné.

**Okno hlásí, že tisková služba není dostupná.** Výroba PDF běží na serveru
jako samostatná služba. Zkus to za chvíli znovu; když to trvá, napiš na
**podpora@shipard.cz**.

**Náhled se neukáže, jen tlačítko Stáhnout.** Některé prohlížeče, hlavně
v telefonu, neumějí PDF zobrazit uvnitř stránky. Soubor stáhni a otevři
v prohlížeči PDF.

**Doklad je v jiném jazyce, než čekáš.** Jazyk se řídí zemí odběratele
z **Fakturačních údajů** dokladu: odběrateli z Česka se tiskne česky, ze
Slovenska slovensky, z Německa a Rakouska německy a odběratelům z ostatních
zemí anglicky. Doklad bez odběratele se tiskne v jazyce země tvé firmy. Když
to u některého odběratele nesedí trvale, nastav mu **Jazyk dokumentů** na
tabu **Nastavení** v **Osobách** — viz
[Založení osoby](../osoby/zalozeni-osoby.md); doklad není potřeba měnit,
příští tisk už je v novém jazyce. Pro jeden tisk stačí přepnout **Jazyk**
přímo v okně **Tisk**.

**Co se s jazykem mění a co ne.** V jazyce dokladu jsou titulek, popisky,
názvy sazeb DPH, způsob úhrady, podoba čísel a dat a zkratky běžných
jednotek (*ks* se anglicky tiskne jako *pcs*, německy *Stk*). Nepřekládá se
to, co jsi napsal sám: popis řádků, poznámka na doklad, název pokladny
a jednotky, které sis do **Měrných jednotek** přidal.

**Co zatím nejde:** změnit vzhled dokladu nebo na něj přidat vlastní text
— viz [Co Shipard dnes neumí](../co-dnes-nejde.md). Stejným tlačítkem **Tisk**
vytiskneš i [pokladní doklad](../pokladna/pokladni-doklad.md)
a [prodejku](../pokladna/prodejka.md).

## Souvisí

- [Vystavení faktury](vystaveni-faktury.md) — jak fakturu dostat do stavu
  V pořádku
- [Zálohová faktura](zalohova-faktura.md) — kdy vystavit proformu a proč
  není daňovým dokladem
- [Pokladní doklad](../pokladna/pokladni-doklad.md) — tisk příjmového
  a výdajového dokladu
- [Prodejka](../pokladna/prodejka.md) — tisk prodejky a vratky
- [Tisk kontace](../uctarna/tisk-kontace.md) — účetní zápisy dokladu na papír
- [Odeslání faktury e-mailem](odeslani-faktury.md) — doklad odběrateli
  přímo z Shipardu
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — vzhled dokladu
