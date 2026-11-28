---
title: Smlouvy
summary: Jak v doplňku Budovy založit nájemní smlouvu, přidat jí místa, potvrdit ji a dostat číslo, jak jí nastavit fakturaci (periodicita, řádky, přepisy hodnot druhu) a pracovat se záložkou Fakturace, co znamená varování o překryvu míst a jak smlouvu ukončit nebo zrušit.
keywords: [smlouvy, smlouva, nájemní smlouva, nájemní smlouvy, pronájem, pronájmy, nájem, nájemce, nájemci, budovy, správa budovy, kancelář, byt, obchodní prostor, sklad, parkování, parkovací stání, virtuální kancelář, sídlo firmy, místa smlouvy, přidat místo ke smlouvě, víc míst na smlouvě, povolené typy míst, místo nejde přidat, číslo smlouvy, kdy dostane smlouva číslo, zahájení smlouvy, ukončení smlouvy, doba neurčitá, ukončit smlouvu, zrušit smlouvu, smazat smlouvu, opravit smlouvu, překryv míst, místo je i ve smlouvě, dvě smlouvy na jedno místo, sekce budovy není vidět, doplněk budovy, fakturace smlouvy, fakturace nájmu, fakturovat nájem, nájemné, faktura za nájem, periodicita smlouvy, fakturovat od, text dokladu, řádky smlouvy, řádek předpisu, platnost řádku, cena nájmu, změna ceny nájmu, zvýšení nájmu, šablona řádků, pevný variabilní symbol, středisko smlouvy, měna smlouvy, přepisy výchozích hodnot druhu, z druhu, záložka fakturace, vystavit dlužná období, přegenerovat, obnovit, smlouva se nefakturuje, smlouva nejde potvrdit, řádky nejdou upravit]
related: [budovy/nastaveni.md, zakazky/periodicka-fakturace.md, mista/mista-a-druhy-mist.md, co-dnes-nejde.md, co-shipard-umi.md]
---

# Smlouvy

Smlouvy jsou evidence pronájmů — kanceláří, bytů, obchodních prostor,
skladů, parkování a virtuálních kanceláří. Každá smlouva má nájemce, dobu
platnosti a místa z číselníku míst. Smlouvě můžeš nastavit i fakturaci:
Shipard pak za každé období sám vystaví koncept faktury. Smlouvy najdeš
v sekci **Budovy → Smlouvy**.

Budovy jsou **doplněk**: sekci vidíš, jen když ho ve tvém Shipardu správce
systému zapnul.

## Kdy to potřebuješ

Pronajímáš prostory a chceš mít na jednom místě, kdo má co pronajaté a od
kdy do kdy. Nebo zakládáš novou smlouvu a potřebuješ zjistit, jestli
kancelář už někdo nemá. Nebo nájemce končí a smlouvu je potřeba ukončit.
Nebo chceš, aby se nájemné fakturovalo samo — každý měsíc, čtvrtletí,
pololetí nebo rok.

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
7. Má se smlouva fakturovat? Vyplň sekci **Fakturace** (viz
   [Fakturace smlouvy](#fakturace-smlouvy) níže). Smlouvu bez
   **Periodicity** Shipard nefakturuje.
8. **Interní poznámka** je jen pro vás.
9. Dej **V pořádku**. Smlouva dostane **Číslo** podle vzorce svého druhu
   — například *KA-2027-0001* — a přesune se mezi potvrzené.

Chceš smlouvu jen rozpracovat? Dej **Uložit**. Koncept potřebuje jen druh
a název a číslo ještě nemá.

### Fakturace smlouvy

Fakturace smlouvy funguje stejně jako periodická fakturace zakázek: ty
nastavíš **co** a **jak často**, běh jednou denně vystaví **koncepty**
faktur za splatná období a ty je zkontroluješ a potvrdíš. Jak běh pracuje,
kde koncepty najdeš a co s nimi, popisuje
[Periodická fakturace](../zakazky/periodicka-fakturace.md) — tady je to,
co se nastavuje na smlouvě.

**Sekce Fakturace**

- **Periodicita** — **Měsíčně**, **Čtvrtletně**, **Pololetně** nebo
  **Ročně**; období jsou kalendářní. Prázdná = smlouva se nefakturuje.
- **Fakturovat od** — první fakturované období je to, které obsahuje
  tohle datum. Prázdné pole se při uložení doplní **Zahájením**; dřív než
  zahájení být nesmí.
- **Text dokladu** — text faktury. Napiš do něj `{období}`, Shipard
  dosadí *říjen 2026*, *3. čtvrtletí 2026* nebo *2026* v jazyce nájemce.
  Prázdný text = název smlouvy a období.
- **Měna** — prázdná se při uložení doplní domácí měnou.
- **Variabilní symbol** — pevný VS, který ponesou všechny faktury
  smlouvy; hodí se pro trvalé příkazy.
- **Středisko** — propíše se na každou fakturu smlouvy.

**Sekce Přepisy výchozích hodnot druhu**

**Typ dokladu**, **Řada dokladů**, **Splatnost (dny)**, **Fakturace**
(na počátku, nebo na konci období), **Režim DPH**, **Způsob platby**
a **Bankovní účet** bere smlouva ze svého druhu (viz
[Druhy smluv a číslování](nastaveni.md)). Prázdné pole ukazuje, co z druhu
platí (*Z druhu: …*); vyplň jen to, co se u téhle smlouvy liší.

**Záložka Řádky**

Řádky říkají, co se fakturuje. Záložka **Řádky** se zpřístupní po prvním
uložení smlouvy.

- Nová smlouva dostane při prvním uložení řádky ze **šablony řádků** svého
  druhu — stačí je zkontrolovat a upravit ceny.
- Řádek přidáš tlačítkem **Přidat**: **Popis** (nebo vyber **Položku**,
  která popis a cenu předvyplní), **Množství**, **Jednotku**,
  **Cenu/jednotku**, **Kód DPH** a případně **Pohyb** (prázdný = výchozí
  pohyb faktury).
- **Platnost od** a **Platnost do** nech prázdné u stálých řádků. Vyplněná
  platnost musí ležet uvnitř platnosti smlouvy.
- Řádky smlouvy **V pořádku** jsou jen ke čtení. Změna ceny jde přes
  **Opravit**: starému řádku vyplň **Platnost do**, přidej nový řádek
  s novou cenou a **Platnost od** a dej smlouvu zpět **V pořádku**.

**Záložka Fakturace v detailu**

Smlouva s periodicitou má v detailu záložku **Fakturace**: nahoře
**Příští splatné období** a předpis, pod tím období se stavem
(**Naplánováno**, **Čeká na podklady**, **Vystaveno**, **Zastaveno**),
dokladem, částkou a zprávou. Nad detailem jsou tlačítka:

- **Vystavit dlužná období** — vystaví hned všechna splatná období
  smlouvy.
- **Přegenerovat** — sestaví vybraný koncept znovu podle aktuální smlouvy;
  ruční úpravy konceptu se ztratí. Je vidět, jen když má smlouva období
  s dokladem v konceptu.
- **Obnovit** — vrátí zastavené období do plánu a hned vystaví nový
  koncept. Je vidět, jen když má smlouva zastavené období.

Faktury vznikají smlouvě **V pořádku**. Smlouvu **V opravě** běh
přeskočí a po návratu do **V pořádku** dožene, co bylo mezitím splatné.
Období, které začíná po **Ukončení**, se už nevystaví; ukončená a zrušená
smlouva se nefakturuje.

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
**Zahájení** a — kromě typu **Ostatní** — aspoň jedno místo. Smlouva
s **Periodicitou** navíc potřebuje **Typ dokladu** a **Řadu dokladů**
(na smlouvě, nebo na druhu), aspoň jeden řádek na záložce **Řádky**
a řádky s platností uvnitř platnosti smlouvy — chyba řekne, který řádek
vadí.

**Smlouva se nefakturuje.** Fakturuje se jen smlouva **V pořádku**
s vyplněnou **Periodicitou**. Zkontroluj taky **Fakturovat od** a záložku
**Fakturace** v detailu — u období, které se nepodařilo vystavit, je
zpráva proč.

**Řádky nejdou upravit ani smazat.** Smlouva je **V pořádku** — dej
**Opravit**, uprav řádky a vrať ji **V pořádku**.

**Nová smlouva už má řádky.** Dostala je ze šablony řádků svého druhu.
Šablona se zkopíruje jen při prvním uložení a při změně druhu u konceptu
bez řádků — když řádky smažeš a smlouvu uložíš, znovu se nevrátí. Pozdější
změna šablony na druhu existující smlouvy nemění.

**Fakturovat od nejde nastavit před zahájení.** První faktura nemůže být
za období, které celé předchází smlouvě. Potřebuješ fakturovat až od
pozdějšího data? Nastav pozdější **Fakturovat od**.

**Víc než tři dlužná období běh nevystaví.** Smlouva s **Fakturovat od**
daleko v minulosti by vystavila hromadu faktur naráz. Běh se zastaví
a ukáže upozornění; zkontroluj datum a dej **Vystavit dlužná období**.

**Rok v čísle je rok zahájení.** Smlouva se zahájením 1. 1. 2028
potvrzená v prosinci 2027 dostane číslo z řady 2028.

**Smazat jde jen koncept.** Potvrzenou smlouvu **Ukonči** nebo **Zruš**.

**Sekce Budovy v menu není.** Doplněk není zapnutý — požádej správce
systému.

## Souvisí

- [Druhy smluv a číslování](nastaveni.md)
- [Periodická fakturace](../zakazky/periodicka-fakturace.md)
- [Místa a druhy míst](../mista/mista-a-druhy-mist.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
