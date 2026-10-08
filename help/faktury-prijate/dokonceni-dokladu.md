---
title: Dokončení dokladu
summary: Co se děje po Vystavit koncept — od Konceptu k V pořádku a co se tím spustí.
keywords: [dokončení, dokončit doklad, potvrdit doklad, koncept, v pořádku, vrátit do konceptu, uložit jako koncept, vystavit koncept, číslo faktury, číselná řada, zaúčtování, saldokonto, uzamčení dokladu]
related: [posta/kontrola-vytezeni.md, faktury-prijate/oprava-dokladu.md, slovnicek.md]
---

# Dokončení dokladu

Doklad vystavený z došlé pošty přes **Vystavit koncept** vzniká jako
**Koncept** — rozpracovaný záznam, který zatím nikam nepočítá. Aby dostal
číslo, zaúčtoval se a objevil v saldokontu, dáš mu **V pořádku**. Tahle
stránka je o tom, co se při tom stane. (Doklad vystavený přes **Vystavit
a uzavřít** je hotový rovnou — nic z toho ho nečeká.)

## Kdy to potřebuješ

Vystavil jsi z vytěženého návrhu koncept, doklad se otevřel v editačním
formuláři a ty řešíš, co s ním dál. Nebo hledáš doklad, který jsi rozdělal
a nedokončil.

## Postup

Kroky 1–2 se dějí v **editačním formuláři** — tom, který se ti otevřel po
**Vystavit koncept**. Tlačítka dole ve formuláři jsou **Uložit**,
**V pořádku** a **Smazat**.

1. **Dokonči Koncept.** Tady je editovatelné všechno: řádky, částky, sazby,
   datumy, dodavatel. Zkontroluj především **Účetní datum**,
   **DUZP** a **Datum splatnosti** — od nich se odvíjí období a saldokonto.
   Doklad z pošty už má nastavenou **číselnou řadu**, takže na ni myslet
   nemusíš. Rozdělaný doklad ulož tlačítkem **Uložit** — číslo ani
   zaúčtování se tím nespustí.

2. **Dej V pořádku.** Tím se doklad stává hotovým a **formulář se
   zavře** — práce s dokladem tím pro tebe končí. Na pozadí se stane tohle:

   - doklad dostane **číslo** z číselné řady,
   - **uzamkne se** — v tomto stavu se needituje,
   - **zaúčtuje se** — vznikne zápis v **Účetním deníku**,
   - **objeví se v saldokontu** jako nezaplacená položka, kterou pak
     spáruješ s platbou z banky.

   Když na dokladu něco chybí (partner, registrace DPH, vlastní firma),
   Shipard ho do V pořádku nepustí, napíše proč a doklad zůstane
   v Konceptu.

3. **Když chceš zkontrolovat zaúčtování, jdi na doklad znovu.** Ve
   formuláři účetní zápis není. Otevři **Nákup → Faktury přijaté**, klikni
   na doklad a přepni na záložku **Zaúčtování**. Je tam odznak
   **Zaúčtováno**, **Neúčtováno** nebo **Chyba účtování**, pod ním
   samotný zápis a u chyby i její důvod — co která hláška znamená, je
   v [Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md).
   Doklad zůstává uložený, jen není zaúčtovaný,
   takže se nic neztratilo.

   Rutinně to dělat nemusíš — když se účtování nepovede, Shipard tě na to
   upozorní v Dashboardu.

## Na co narazíš

**Mezikrok „s číslem, ale nezaúčtovaný“ není.** Číslo doklad dostane až
s **V pořádku** a tím se zároveň zaúčtuje. Když na něco čekáš (chybějící
informaci, ověření dodávky, odpověď dodavatele), nech doklad v Konceptu.
Hotový doklad, který potřebuješ změnit, převedeš na **V opravě** — číslo
si nechá, viz [Oprava dokladu](oprava-dokladu.md).

**Do Konceptu se vrací jen poslední doklad v řadě.** Hotový doklad
nejdřív převeď na **V opravě**; tam je tlačítko **Uložit jako koncept**,
které ho vrátí do Konceptu a vezme mu číslo. Nabízí se jen u posledního
dokladu v číselné řadě — u staršího by v číslování zůstala díra. Dokladů
můžeš vrátit i víc, ale vždy postupně od nejnovějšího: jak číslo vrátí
poslední doklad, stane se posledním ten před ním. Počítá se to zvlášť pro
každou číselnou řadu a účetní rok. Starší doklad oprav ve **V opravě** —
číslo si nechá.

**Vrácením do Konceptu se číslo uvolní — a příště to nemusí být to samé.**
Uvolněné číslo připadne tomu dokladu, který dáš do **V pořádku** nejdřív.
Vrátíš-li do Konceptu dva doklady a dokončíš je v jiném pořadí, vymění si
čísla.
U dokladu, který už jsi někam nahlásil nebo poslal, proto číslo neměň.

**Chybu účtování spravíš bez rozebírání dokladu.** Když doplníš, co
podle hlášky chybělo (viz
[Když se doklad nezaúčtuje](../uctarna/kdyz-se-doklad-nezauctuje.md)),
vrať se na doklad a dej **Přeúčtovat**.
Doklad zůstane ve **V pořádku** a účetní zápis se vytvoří znovu; jakmile
projde, upozornění na Dashboardu zmizí samo.

**Dokončit to nemusíš hned.** Když formulář zavřeš v Konceptu, doklad
nezmizí — najdeš ho v **Nákup → Faktury přijaté**
a tlačítkem **Otevřít** se vrátíš do stejného formuláře včetně tlačítek
pro přechody.

**Doklad ve V pořádku se needituje — a je to tak správně.** Když je v něm
chyba, nepřepisuje se: převedeš ho na **V opravě**, opravíš a vrátíš zpět.
Postup je v [Opravě dokladu](oprava-dokladu.md).

**Odchodem ze stavu V pořádku se doklad odúčtuje.** Zápis v Účetním deníku
i pohyby v saldokontu zmizí a po návratu na V pořádku se vytvoří znovu
z aktuálních dat. Nezůstávají tedy dvojí zápisy.

**Zaúčtování nezávisí na tom, jestli je faktura zaplacená.** Zaúčtuje se
podle stavu dokladu, platba se páruje samostatně přes bankovní transakce.
Nezaplacená faktura ve V pořádku je normální stav.

**Číslo dokladu není číslo od dodavatele.** Řada přiděluje tvoje interní
číslo; číslo, které faktuře dal dodavatel, je na dokladu vedené zvlášť
a podle něj se dohledávají duplicity.

**Rekapitulace DPH z faktury dodavatele se nepřepočítává.** V sekci **DPH**
je pole **Rekapitulace DPH** se dvěma volbami. **Přepočítaná** znamená, že
si ji Shipard spočítá z řádků při každém uložení — tak vznikají doklady,
které vystavuješ ty. **Převzatá** znamená, že platí to, co je na faktuře:
uložení do ní nesáhne a záložka **Rekapitulace DPH** se dá editovat
(**Přidat**, **Upravit**, **Smazat** jako u řádků). Nárok na odpočet je
částka z faktury, i když je dodavatel zaokrouhlil o haléř jinak než my.
Doklad vytěžený z pošty dostane **Převzatou** sám, když je rekapitulace na
faktuře úplná a sedí si; jinak **Přepočítanou**.

**Když rekapitulace nesedí na řádky, Shipard to napíše — ale uloží.** Nad
formulářem se objeví žlutý pruh **Uloženo, ale zkontroluj:** s tím, která
sazba nesedí. Není to chyba k odmítnutí: nejčastěji chybí řádek, nebo je
u něj špatná částka. Oprav řádky (rekapitulace zůstane), nebo — když je
špatně opsaná rekapitulace — uprav rovnou ji. Přepnutím na
**Přepočítanou** se rekapitulace vytvoří z řádků znovu a přepnutím zpět na
**Převzatou** ji můžeš dál upravovat.

**Ruční změna rekapitulace u zaúčtovaného dokladu chce Přeúčtovat.** Účetní
zápis se sám aktualizuje jen při změně stavu dokladu, ne při úpravě řádku
nebo rekapitulace. Po opravě dej **Přeúčtovat** — stejně jako u chyby
účtování.

## Souvisí

- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md) — co dělat
  před vznikem dokladu
- [Oprava dokladu](oprava-dokladu.md) — když je chyba v dokladu, který už
  je V pořádku
- [Slovníček](../slovnicek.md) — přehled stavů dokladu
