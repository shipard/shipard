---
title: Smlouvy
summary: Jak v doplňku Budovy založit nájemní smlouvu, přidat jí místa, potvrdit ji a dostat číslo, co znamená varování o překryvu míst a jak smlouvu ukončit nebo zrušit.
keywords: [smlouvy, smlouva, nájemní smlouva, nájemní smlouvy, pronájem, pronájmy, nájem, nájemce, nájemci, budovy, správa budovy, kancelář, byt, obchodní prostor, sklad, parkování, parkovací stání, virtuální kancelář, sídlo firmy, místa smlouvy, přidat místo ke smlouvě, víc míst na smlouvě, povolené typy míst, místo nejde přidat, číslo smlouvy, kdy dostane smlouva číslo, zahájení smlouvy, ukončení smlouvy, doba neurčitá, ukončit smlouvu, zrušit smlouvu, smazat smlouvu, opravit smlouvu, překryv míst, místo je i ve smlouvě, dvě smlouvy na jedno místo, sekce budovy není vidět, doplněk budovy]
related: [budovy/nastaveni.md, mista/mista-a-druhy-mist.md, co-dnes-nejde.md, co-shipard-umi.md]
---

# Smlouvy

Smlouvy jsou evidence pronájmů — kanceláří, bytů, obchodních prostor,
skladů, parkování a virtuálních kanceláří. Každá smlouva má nájemce, dobu
platnosti a místa z číselníku míst. Najdeš je v sekci **Budovy → Smlouvy**.

Budovy jsou **doplněk**: sekci vidíš, jen když ho ve tvém Shipardu správce
systému zapnul.

## Kdy to potřebuješ

Pronajímáš prostory a chceš mít na jednom místě, kdo má co pronajaté a od
kdy do kdy. Nebo zakládáš novou smlouvu a potřebuješ zjistit, jestli
kancelář už někdo nemá. Nebo nájemce končí a smlouvu je potřeba ukončit.

## Postup

**Než začneš**

- Místa, která pronajímáš, musí být v **Nastavení aplikace → Místa**
  (viz [Místa a druhy míst](../mista/mista-a-druhy-mist.md)).
- Nájemce musí být mezi osobami — nebo ho založíš rovnou z formuláře
  smlouvy.
- Druhy smluv jsou předpřipravené, jeden pro každý typ smlouvy; upravíš
  je v **Nastavení aplikace → Budovy** (viz
  [Druhy smluv a číslování](nastaveni.md)).

**Nová smlouva**

1. V sekci **Budovy → Smlouvy** dej **Přidat**.
2. Vyber **Druh**. Druh určuje typ smlouvy a tím i to, jaká místa smlouva
   smí mít:

   | Typ smlouvy | Místa | Povolené typy míst |
   |---|---|---|
   | **Kancelář** | povinná | Místnost, Část budovy |
   | **Byt** | povinná | Část budovy, Místnost |
   | **Obchodní prostor** | povinná | Část budovy, Místnost, Venkovní plocha |
   | **Sklad** | povinná | Místnost, Část budovy, Venkovní plocha |
   | **Parkování** | povinná | Místo, Venkovní plocha |
   | **Virtuální kancelář** | povinná | Budova, Areál |
   | **Ostatní** | nepovinná | libovolný |

3. Vyplň **Název** — podle něj smlouvu poznáš v seznamu.
4. Vyber **Nájemce**. Když mezi osobami není, založíš ho volbou
   **Vytvořit nový záznam** v nabídce.
5. Vyplň **Zahájení**. **Ukončení** nech prázdné u smlouvy na dobu
   neurčitou.
6. Do pole **Místa** piš název místa a vyber ho z nabídky; takhle přidej
   všechna místa smlouvy. Nabídka ukazuje jen místa povolených typů
   a místo, které už smlouva má, nenabízí. Místo odebereš křížkem u jeho
   štítku.
7. **Interní poznámka** je jen pro vás.
8. Dej **V pořádku**. Smlouva dostane **Číslo** podle vzorce svého druhu
   — například *KA-2027-0001* — a přesune se mezi potvrzené.

Chceš smlouvu jen rozpracovat? Dej **Uložit**. Koncept potřebuje jen druh
a název a číslo ještě nemá.

**Oprava smlouvy**

1. Otevři smlouvu a dej **Opravit**. Smlouva přejde do stavu **V opravě**.
2. Uprav, co potřebuješ, a dej **V pořádku**. Číslo zůstává stejné.

**Ukončení a zrušení**

- **Ukončit** — smlouva doběhla. Když je **Ukončení** prázdné, doplní se
  dnešní datum; jiné datum vyplň před ukončením.
- **Zrušit** — smlouva se neuskutečnila. Prázdné **Ukončení** se doplní
  stejně.

Ukončené a zrušené smlouvy najdeš v seznamu na záložce archivu. Vrátit je
jde tlačítkem **Opravit**.

**Hledání a filtry**

- Hledání prochází číslo, název, nájemce i názvy míst — smlouvu najdeš
  i podle čísla kanceláře.
- V pravém panelu jsou filtry **Druh** a **Typ smlouvy**.
- Detail smlouvy ukazuje pod přehledem tabulku **Místa** se sloupcem
  **Umístění** (areál, budova, křídlo); kliknutím na místo otevřeš jeho
  detail.

## Na co narazíš

**„… je i ve smlouvě …“.** Po **Uložit** se nad formulářem objeví
**Uloženo, ale zkontroluj:** a věta se jménem místa a číslem druhé
smlouvy. Je to varování, ne chyba — smlouva se uložila a potvrdit jde.
Shipard jím upozorňuje, že stejné místo má v překrývající se době ještě
jiná platná smlouva. Někdy je to záměr (sdílená kancelář, nový nájemce
nastupuje dřív, než starý skončí), jindy překlep v místě nebo datu.
Počítají se smlouvy **V pořádku** a **V opravě**; koncepty, ukončené
a zrušené ne. Smlouva bez **Ukončení** platí pořád.

**Varování po V pořádku neuvidíš.** Tlačítko **V pořádku** formulář
rovnou zavře, takže varování o překryvu zmizí s ním. Když si obsazeností
míst nejsi jistý, dej nejdřív **Uložit**, přečti si případné varování
a potvrď až potom.

**Místo nejde přidat nebo ho nabídka neukazuje.** Typ smlouvy ho
nepovoluje — do smlouvy na kancelář nedáš podlaží ani parkovací stání.
Zkontroluj **Druh** smlouvy a druh místa v číselníku míst. Smlouva typu
**Ostatní** bere místa všech typů.

**Druh nejde změnit.** Druh volíš jen u konceptu. Po potvrzení je od něj
odvozené číslo smlouvy, takže se už nemění — smlouvu se špatným druhem
zruš a založ znovu.

**Po změně druhu svítí u pole Místa chyba.** Místa, která novému typu
nevyhovují, Shipard sám neodebere — vypíše je při uložení a odebrat je
musíš ty.

**Smlouva nejde potvrdit.** **V pořádku** vyžaduje **Nájemce**,
**Zahájení** a — kromě typu **Ostatní** — aspoň jedno místo.

**Rok v čísle je rok zahájení.** Smlouva se zahájením 1. 1. 2028
potvrzená v prosinci 2027 dostane číslo z řady 2028.

**Smazat jde jen koncept.** Potvrzenou smlouvu **Ukonči** nebo **Zruš**.

**Sekce Budovy v menu není.** Doplněk není zapnutý — požádej správce
systému.

## Souvisí

- [Druhy smluv a číslování](nastaveni.md)
- [Místa a druhy míst](../mista/mista-a-druhy-mist.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
