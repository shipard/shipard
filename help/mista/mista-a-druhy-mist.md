---
title: Místa a druhy míst
summary: Jak založit strom míst od areálu po pracovní místo, jak se ve stromu pohybovat a přidat podřízené místo, co smí být pod čím, jak místo přesunout a kdy má místo adresu.
keywords: [místa, místo, číselník míst, strom míst, hierarchie míst, rozbalit strom, sbalit strom, rozbalit vše, sbalit vše, přidat podřízené, podřízené místo, hledat místo, seznam míst, zobrazit jako seznam, zobrazit jako strom, areál, budova, budovy, podlaží, patro, místnost, místnosti, kancelář, zasedačka, sklad, parkoviště, pracovní místo, pobočka, pobočky, druh místa, druhy míst, typ místa, nadřazené místo, podřízená místa, přesun místa, přesunout místo, kód místa, číslo místnosti, pořadí míst, adresa budovy, adresa areálu, nejde smazat místo, místo má podřízená místa, nejde změnit typ druhu, nejde pod místnost]
related: [co-dnes-nejde.md, slovnicek.md]
---

# Místa a druhy míst

Místa jsou číselník toho, kde se co nachází — od areálu přes budovu
a podlaží po místnost a jednotlivé pracovní místo. Tvoří strom: každé
místo může mít nadřazené místo. Najdeš je v **Nastavení aplikace → Místa**.

## Kdy to potřebuješ

Chceš mít v Shipardu popsané, jak vypadají tvoje prostory — budovy,
patra, kanceláře, sklady, parkoviště. Nebo zakládáš další místnost
a nevíš, pod co patří. Nebo se kancelář přestěhovala do jiné budovy.

## Postup

**Druhy míst**

Každé místo má **Druh**. Shipard jich má osm předpřipravených, jeden pro
každý typ místa; vlastní druhy si přidáš podle toho, jak prostory dělíš
(*Kancelář*, *Zasedací místnost*, *Sklad*).

1. V **Nastavení aplikace → Místa → Druhy míst** dej **Přidat**.
2. Vyplň **Název** a vyber **Typ místa**. Typ určuje, kam místa druhu
   patří ve stromu a jestli mají adresu:

   | Typ místa | Příklad | Má adresu |
   |---|---|---|
   | **Areál** | areál firmy, pozemek | ano |
   | **Budova** | Budova A | ano |
   | **Část budovy** | křídlo, vchod | ne |
   | **Podlaží** | 1. NP, suterén | ne |
   | **Místnost** | kancelář 110, zasedačka | ne |
   | **Venkovní plocha** | parkoviště, dvůr | ano |
   | **Místo** | pracovní místo, parkovací stání, regál | ne |
   | **Ostatní** | cokoli mimo strom | ne |

3. **Ikona** je nepovinná. Volba **Podle typu** nechá ikonu typu.
4. Dej **V pořádku**.

**Strom míst**

Zakládej shora dolů — nejdřív areál nebo budovu, pak to, co je uvnitř.

1. V **Nastavení aplikace → Místa → Místa** dej **Přidat**.
2. Vyplň **Název** a vyber **Druh**.
3. **Nadřazené místo** nech prázdné u místa nejvyšší úrovně. U ostatních
   vyber, pod co místo patří — nabídka ukazuje jen místa, pod která druh
   smí.
4. Nepovinně doplň **Zkrácený název** (ukazuje se v cestě místo názvu,
   třeba *A* pro *Budova A*) a **Kód** (číslo místnosti, označení budovy).
5. U areálu, budovy a venkovní plochy vyplň sekci **Adresa**.
6. Dej **V pořádku**.

**Přidání podřízeného místa**

Když už nadřazené místo máš, je rychlejší přidat další přímo pod něj:

1. Ve stromu najeď na řádek místa a klikni na **+** na konci řádku
   (**Přidat podřízené**). Stejné tlačítko je i v detailu místa.
2. Formulář má **Nadřazené místo** už vyplněné a navrhne i **Druh** —
   stejný, jaký má poslední místo pod ním; pod prázdným místem druh
   o úroveň níž (pod budovou podlaží, pod podlažím místnost). Druh můžeš
   změnit.
3. Doplň **Název** a dej **V pořádku**. Nové místo je ve stromu hned vidět.

U místa typu **Místo** tlačítko není — pod ním už nic být nemůže. Není
ani u místa s ukončenou platností.

**Strom**

Místa se ukazují jako strom se sloupci **Název**, **Kód** a **Druh**.

- Šipka před názvem místo rozbalí nebo sbalí. U sbaleného místa je šedě
  vidět, kolik míst má přímo pod sebou.
- **Rozbalit vše** a **Sbalit vše** jsou vedle hledání.
- Co máš rozbalené, si Shipard v tomto prohlížeči pamatuje — po návratu
  najdeš strom tak, jak jsi ho nechal. Napoprvé jsou rozbalená místa
  nejvyšší úrovně.
- Klik na řádek otevře detail místa v panelu vpravo; klik na jiný řádek
  ho přepne, **Esc** zavře. Detail ukazuje **Podřízená místa**; kliknutím
  na řádek otevřeš podřízené místo.

**Hledání ve stromu**

Hledá se v názvu, zkráceném názvu a kódu. Strom ukáže nalezená místa
i s cestou k nim — místa nad nimi jsou šedě, aby bylo vidět, kam nalezené
místo patří. Při hledání je strom rozbalený celý; po smazání hledání se
vrátí tak, jak jsi ho měl. Stejně se chovají záložky **Archív** a **Koš**:
vyřazené místo najdeš pod jeho nadřazenými místy.

**Seznam místo stromu**

Tlačítkem **Zobrazit jako seznam** vedle hledání přepneš na plochý seznam
— hodí se, když chceš vidět třeba všechny kanceláře napříč budovami.
V seznamu má každé místo pod názvem cestu a druh, třeba
*Areál › A › 1. NP · Kancelář*. Zpátky tě vrátí **Zobrazit jako strom**.

**Přesun místa**

1. Otevři místo a dej **Opravit**.
2. V poli **Nadřazené místo** vyber nové. Všechno, co je pod místem,
   se přesune s ním.
3. Dej **V pořádku**.

## Na co narazíš

**Co smí být pod čím.** Nadřazené místo musí být ve stromu výš: areál →
budova → část budovy → podlaží → místnost nebo venkovní plocha → místo.
Úrovně jde přeskočit — přízemní budova může mít místnosti rovnou pod
sebou a strom nemusí začínat areálem. Místnost pod místnost ani pod
venkovní plochu nejde. Druh typu **Ostatní** pravidlo nemá, smí být kdekoli.

**Kód je jedinečný jen v rámci nadřazeného místa.** Místnost *101* může
být v každé budově, dvě místnosti *101* na jednom podlaží ne.

**Pořadí.** Místa se pod nadřazeným místem řadí podle pole **Pořadí**,
pak podle kódu a názvu. Nové a přesunuté místo se zařadí na konec;
pořadí změníš přepsáním čísla. Kliknutím na záhlaví sloupce se strom
neřadí.

**Strom se neukáže, když je míst příliš mnoho.** Nad pět tisíc míst
Shipard místo stromu vyzve, ať místa zúžíš hledáním nebo přepneš na
seznam.

**Místo s podřízenými místy nejde smazat ani mu ukončit platnost.**
Podřízená místa nejdřív přesuň jinam, nebo je vyřaď.

**Změna druhu místa.** Jde, pokud místo i potom zapadne mezi své
nadřazené a podřízená místa. Budovu s podlažími na místnost nezměníš.
Když místu dáš druh bez adresy, adresa se smaže.

**Typ druhu po potvrzení nezměníš** — a nezměníš ho ani u druhu
v **Konceptu**, jakmile ho má nějaké místo. Pro jiný typ založ nový druh.
Druh, který nějaké místo používá, nejde smazat — dej mu **Ukončit
platnost**; místa si ho ponechají, jen se přestane nabízet novým.

## Souvisí

- [Co Shipard dnes neumí](../co-dnes-nejde.md)
- [Slovníček](../slovnicek.md)
