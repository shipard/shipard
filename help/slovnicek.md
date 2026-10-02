---
title: Slovníček
summary: Co která věc v Shipardu znamená a jak se jmenuje v rozhraní.
keywords: [slovníček, pojmy, názvy, co to znamená, jak se to jmenuje, terminologie, DUZP, jistota, přeúčtovat, stavy, majetek, inventární číslo, drobný majetek, dlouhodobý majetek]
related: [co-dnes-nejde.md]
---

# Slovníček

Shipard občas používá názvy, které nejsou samozřejmé — a ty občas používáš
jiné než on. Tady je oboje pohromadě: **první sloupec je to, co asi řekneš,
druhý to, jak se to jmenuje v aplikaci.**

Slovníček roste s dokumentací. Zatím pokrývá orientaci v aplikaci, došlou
poštu a přijaté faktury.

---

## Orientace v aplikaci

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| úvodní stránka, plocha, přehled | **Dashboard** | Domovská obrazovka po přihlášení. Ukazuje, co je právě potřeba udělat — nová pošta, upozornění, rozdělané doklady |
| seznam, tabulka, výpis | **Prohlížeč** (v rozhraní občas i *viewer*) | Seznamová obrazovka, třeba přehled faktur |
| moje firma, můj účet, prostor | **Datový zdroj** (DS) | Jedna firma nebo organizace se svými daty. Tvoje data jsou oddělená od ostatních |
| menu vlevo | **Sekce** *Základní*, *Nákup*, *Prodej*, *Majetek*, *Účtárna*, *Systém* | Nad nimi stojí samostatné položky *Dashboard*, *Došlá pošta*, *Úkoly* a *Chat* |
| dokumenty, smlouvy, přílohy k ničemu | **Spisovna** | Evidence dokumentů, které nejsou doklady — smlouvy, výpisy, úřední pošta |
| asistent, AI, chatbot | **Chat** | Vestavěný AI asistent. Umí se dívat do tvých dat a odpovídat na otázky. Nic za tebe nezaloží ani nezmění |
| hláška, varování, červená věc | **Upozornění** | Kontrola, která našla nesrovnalost. Objeví se jako karta na Dashboardu |

---

## Došlá pošta a vytěžení

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| e-maily, co přišlo | **Došlá pošta** | Zprávy, které do systému přišly na tvou adresu pro příjem pošty |
| AI to přečetla, načtení faktury | **Analýza** / **vytěžení** | Co AI udělá s přiloženou fakturou: přečte ji a nabídne hotový doklad |
| co z toho vypadlo, rozečtená faktura, vytěžený doklad | **Návrh** (záložka **Návrh** u zprávy) | Výsledek analýzy — ještě není doklad, je to návrh ke kontrole. Z jednoho e-mailu vznikne nejvýše jeden; co AI našla navíc, je na kartě jen poznámka |
| jak si je tím AI jistá, spolehlivost | **Jistota** (v procentech) | Odhad modelu, jak dobře fakturu přečetl. Nízká jistota = kontroluj pečlivě. Vysoká jistota **není** záruka správnosti |
| ještě jsem to neřešil | stav **Nová** | Zpráva přišla a nikdo se jí ještě nezabýval |
| čeká to na mě | stav **K řešení** | Je z čeho udělat doklad, nebo zpráva potřebuje tvoje rozhodnutí. Shipard sem zprávu přepne sám, jakmile z ní AI něco vytáhla |
| hotovo, už jsem to vyřídil | stav **Hotovo** | Vyřízeno — doklad vznikl, nebo jsi návrh zamítl. Zpráva se dál needituje |
| co s tím dělá AI | badge **Ve frontě** · **Analyzuje se** · **Analyzováno** · **Analýza selhala** | Jak daleko je strojové čtení. Je to **jiná věc** než stav zprávy: badge říká, jak daleko je AI, stav říká, jak daleko jsi ty |
| uklidit z cesty | stav **Archiv** | Zpráva, se kterou už nic nebude — reklama, nevyžádaná pošta |
| elektronická faktura, XML faktura | **ISDOC** | Strojově čitelný formát faktury. Když ho dodavatel přiloží, Shipard ho použije přímo a AI analýzu vůbec nepotřebuje — je to přesnější |
| obrazovka, kde se to kontroluje | **Náhled dokladu** | Vlevo PDF faktury, jak přišla, vpravo data, která z ní AI přečetla. Na telefonu se z toho stanou taby **PDF** a **Náhled** |
| co je vyplněné podle mých starších faktur | **Doplněno z historie** | Poznámka u pole: hodnota nepřišla z faktury, ale z tvých dřívějších dokladů od téhož dodavatele |
| položka nebo dodavatel, které mám potvrdit | **Reference** | Odkaz na záznam v tvé evidenci. Když si AI není jistá, který to je, nabídne kandidáty, vyhledávání a **Vytvořit novou osobu** nebo **položku** — a dokud nerozhodneš, tlačítka **Vystavit…** jsou zašedlá |
| přečíst to znovu | **Znovu analyzovat** | Spustí novou analýzu už doručené zprávy. Nový návrh nahradí ten dosavadní; starší běhy zůstávají na záložce **Analýzy**. Zprávu s už použitým návrhem znovu analyzovat nejde |
| ať už mi tohle nechodí | **Pravidlo odesílatele** | Po třech tvých ručních odklizeních pošty od stejné adresy Shipard navrhne pravidlo. Potvrzené pravidlo pak poštu od té adresy archivuje samo |
| účtenka, paragon | **Zjednodušený daňový doklad** | Doklad z prodejny bez tvých údajů. Shipard je zpracovává také |

### Stavy návrhu

Odznak u návrhu na záložce **Návrh**. První tři se odvozují z **Jistoty**
a čekají na tebe, zbytek je už vyřízený:

| Odznak | Co znamená |
|---|---|
| **K použití** | Jistota 90 % a víc. Zkontroluj Součty a Datumy, zbytek namátkou. Na Dashboardu má karta rovnou tlačítko **Použít** |
| **Čeká na review** | Jistota 60–90 %. Projdi všechny sekce náhledu |
| **Nízká jistota** | Pod 60 %. Čti řádek po řádku, nebo zamítni a zadej ručně |
| **Použito** | Z návrhu už vznikl doklad |
| **Zamítnuto** | Zamítl jsi ho s důvodem; důvod zůstává u zprávy |
| **Chyba extrakce** | AI sice odpověděla, ale návrh neprošel kontrolou formátu dokladu a nedá se použít. Není to chyba ve tvé příloze. Karta pod odznakem vysvětlí, co se stalo, a řekne, jestli má smysl **Znovu analyzovat** |

---

## Doklady a faktury

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| faktura, paragon, cokoli k zaúčtování | **Doklad** | Souhrnný název pro fakturu, pokladní doklad a podobné |
| faktura, co mi přišla | **Faktura přijatá** (sekce *Nákup*) | Doklad od dodavatele |
| faktura, co jsem poslal | **Faktura vydaná** (sekce *Prodej*) | Doklad pro odběratele |
| rozdělaný, ještě to nechci | stav **Koncept** | Doklad se dá libovolně měnit, nemá vliv na nic dalšího |
| mám číslo, ale ještě to ladím | stav **Potvrzeno** | Doklad má přidělené číslo z číselné řady, ale pořád se dá upravovat |
| hotovo, platí to | stav **V pořádku** | Doklad je uzavřený a nedá se přímo měnit. Tenhle stav se účtuje |
| chci to opravit | stav **V opravě** | Do tohohle stavu doklad převedeš, když potřebuješ změnit hotový doklad |
| zrušit fakturu, škrtnout | stav **Storno** | Doklad zůstává v evidenci, ale neplatí. Nemazat — storno je správná cesta |
| číslování faktur | **Číselná řada** | Předpis, podle kterého Shipard přiděluje čísla dokladů. Číslo se přiděluje při přechodu z Konceptu |
| řádek faktury, co se fakturuje | **Položka** | Jeden řádek dokladu — množství, jednotková cena, sazba DPH |
| co se na řádku vlastně děje, druh řádku | **Pohyb** | Volba na řádku dokladu — *Prodej služeb*, *Nákup zboží a materiálu*, *Účetní položka* a další. Podle ní se řádek zaúčtuje — účet tedy neurčuje položka, ale pohyb |
| kam mi mají zaplatit, na jaký účet | **Náš bankovní účet** | Volba na dokladu, u faktur na záložce **Nastavení**. U vydané faktury povinná — je to účet, na který má odběratel zaplatit. Účty se zakládají v Nastavení → Účetnictví → Bankovní spojení |
| datum, odkud se počítá DPH | **DUZP** | Datum uskutečnění zdanitelného plnění. Určuje, do jakého období DPH doklad spadne |
| datum, kdy to patří do účetí | **Účetní datum** | Datum účetního případu. Vedle něj jsou na dokladu ještě **Datum vystavení** a **Datum splatnosti** |
| kód pro kontrolní hlášení | **Kód DPH** | Označení režimu plnění u řádku. Není to sazba — sazba je procento, kód říká, jak se plnění vykazuje |
| součet daní dole na faktuře | **Rekapitulace DPH** | Rozpis základu a daně po jednotlivých sazbách |
| daň platí odběratel, přenesená daň | **Reverse charge** / **samovyměření** | Režim, kdy daň neodvádí dodavatel, ale odběratel. Typicky u zboží z EU nebo u stavebních prací |
| dodavatel, odběratel, partner | **Osoba** (sekce *Základní*) | Jedna evidence pro firmy i fyzické osoby |
| natáhnout českou firmu podle IČO | **Z registru** | Tlačítko v Osobách; otevře dialog **Přidat firmu z registru**, kde stačí IČO nebo název. Existující osobu nepřepíše |

---

## Účtárna

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| kam se to zaúčtovalo | **Účetní deník** | Účetní záznamy vzniklé z dokladů. Účty nezadáváš, skládají se automaticky |
| kdo mi kolik dluží | **Saldokonto** | Nezaplacené faktury proti přijatým platbám, po partnerech |
| výpis z banky, platby | **Bankovní transakce** | Pohyby na bankovních účtech, ze kterých se párují úhrady |
| přiřadit platbu k faktuře | **Párování** | Spojení bankovní platby s fakturou, kterou uhrazuje |
| platba, která nikam nesedí | **Clearing účet** | Přechodné místo pro platbu, ke které se zatím nenašla faktura |
| kde uvidím, jak je doklad zaúčtovaný | záložka **Zaúčtování** | V detailu dokladu. Je v ní odznak stavu účtování a samotný účetní zápis |
| zaúčtovalo se to? | **Zaúčtováno** · **Neúčtováno** · **Chyba účtování** | Stav účtování dokladu — jiná věc než stav dokladu. Účtuje se doklad ve stavu **V pořádku** |
| zaúčtovat to znovu | **Přeúčtovat** | Tlačítko u dokladu. Vytvoří účetní zápis znovu, aniž by se doklad musel vyjímat ze **V pořádku** — proto se tím nerozpojí párování s platbou |

---

## Majetek

| Když řekneš | V Shipardu | Co to je |
|---|---|---|
| inventář, inventární seznam, majetek firmy | **Majetek** (sekce i seznam) | Karty majetku — drobného i dlouhodobého. Najdeš v **Majetek → Majetek** |
| inventární číslo, evidenční číslo | **Inventární číslo** | Jedinečné označení karty. Prázdné dostane při potvrzení karty: prefix podle druhu (výchozí *MA*) a pořadové číslo, třeba *MA0001* |
| drobný majetek, evidovaný majetek, věc do nákladů | **Drobný majetek** (druh) | Věc, kterou jen eviduješ — nákup šel do nákladů, neodepisuje se. Jediný druh, u kterého karta nese **Cenu** |
| DHM, DNM, odepisovaný majetek | **Dlouhodobý hmotný**, **Dlouhodobý nehmotný** (druhy) | Majetek, který se bude odepisovat. Karta musí mít **Účetní skupinu**; cena vznikne až ze zařazení (odpisy zatím nejsou) |
| pozemek, umělecké dílo | **Neodepisovaný dlouhodobý** (druh) | Dlouhodobý majetek, který se neodepisuje |
| pronajaté, na leasing, půjčené, cizí | **Cizí majetek** + **Vlastník** | Věc, kterou používáš, ale nevlastníš. Karta nese, komu patří |
| vyřazený majetek, prodaný, zlikvidovaný | stav **V archívu** (akce **Ukončit platnost**) | Vyřazená karta s **Datem vyřazení**; v seznamu pod záložkou **Archív** |
| účty pro majetek, kam se to účtuje | **Účetní skupina majetku** | Sada účtů (majetek, pořízení, oprávky, odpisy, vyřazení). Nastavíš v **Nastavení aplikace → Majetek** |

## Souvisí

- [Co Shipard dnes neumí](co-dnes-nejde.md)
- [Pro testery](../TESTERS.md) — jak si říct o přístup a jak hlásit chyby
