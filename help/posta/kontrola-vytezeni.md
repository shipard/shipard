---
title: Kontrola vytěženého dokladu
summary: Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout.
keywords: [kontrola, vytěžení, analýza, náhled dokladu, jistota, použít, vystavit koncept, vystavit a uzavřít, zamítnout, AI přečetla špatně, sedí částka, review, vytvořit z registru, hledat v registru, nový dodavatel z faktury, projít frontu, přeskočit, fronta faktur, reverse charge, samovyměření, faktura z EU, místo plnění, DIČ dodavatele]
related: [slovnicek.md, co-dnes-nejde.md, osoby/zalozeni-osoby.md]
---

# Kontrola vytěženého dokladu

AI přečte přijatou fakturu a nabídne hotový návrh dokladu. **Návrh není
doklad** — doklad vznikne teprve tím, že návrh potvrdíš. Než to uděláš,
projdi ho podle postupu níž.

## Kdy to potřebuješ

Zpráva s fakturou dorazila a analýza skončila — v **Došlé poště** má
badge **Analyzováno** a stav **K řešení**, na **Dashboardu** se objevil
návrh s procentem **Jistoty**. Dashboard řadí návrhy do sekcí podle
naléhavosti: návrhy ke kontrole jsou karty v sekci **Ke kontrole**
s hlavním tlačítkem **Zkontrolovat**; jisté návrhy najdeš v sekci
**Připraveno**, sbalené do souhrnného pruhu s tlačítkem **Použít**
na každém řádku.

## Postup

1. **Otevři náhled.** Na **Dashboardu** u karty s fakturou klikni na
   **Zkontrolovat**.

2. **Zorientuj se v náhledu.** Vlevo je **PDF** faktury, jak přišla,
   vpravo data, která z ní AI přečetla. Kontrola je porovnávání levé
   strany s pravou. Na telefonu a v úzkém okně se místo dvou sloupců
   objeví taby **PDF** a **Náhled**.

3. **Zkontroluj v tomhle pořadí.** Nezačínej řádky; začni tím, co se
   nejhůř opravuje později:

   | Sekce náhledu | Co porovnat s originálem |
   |---|---|
   | **Součty** | **Celkem** především. Pak **Základ**, **DPH**, **Zaokrouhlení** |
   | **DPH rekapitulace** | Sedí rozpad po sazbách? Je tam sazba, která na faktuře není? |
   | **Dodavatel** | **IČO** a **DIČ**. Podle nich se dohledává partner |
   | **Datumy** | **DUZP** a **Datum splatnosti** — ovlivní přiznání i saldokonto |
   | **Platba** | **Variabilní symbol** a **Bankovní účet** — podle nich se pak páruje platba |
   | **Řádky** | Množství, **Cena/j**, sazba **DPH**. Namátkově, pokud sedí Součty |

4. **Projdi sekci Upozornění.** Sem Shipard píše, co mu na návrhu nesedí.
   Když je prázdná, nic to nezaručuje — jen tam nic nenašel.

5. **Rozhodni nejasné reference.** U dodavatele, položky nebo bankovního
   účtu může být místo hodnoty rozhodovací panel. Nabízí:

   - seznam **Kandidáti** s tlačítky **Použít #číslo**, když AI našla víc
     možných záznamů,
   - vyhledávací pole **Hledat…**, kde si záznam najdeš sám,
   - u dodavatele a odběratele **Vytvořit z registru: název** — nabídne
     se, když Shipard vytěžené IČO našel ve veřejném registru firem
     a osobu ještě nemáš v evidenci. Jeden klik ji založí i vybere.
     Stejné tlačítko je i přímo na kartě strany, panel nemusíš otevírat,
   - u dodavatele a odběratele **Hledat v registru…** — otevře hledání
     v registru s předvyplněným IČO z faktury (stejný dialog jako
     **Z registru** v Osobách); po **Uložit** se osoba rovnou vybere,
   - **Vytvořit novou osobu**, **Vytvořit novou položku** nebo
     **Vytvořit nový účet** — podle toho, čeho se rozhodnutí týká,
   - **Jen účet — bez položky** — jen u řádku faktury, který už nese
     účet (typicky doplněný obsahovou klasifikací). Řádek se pořídí
     s účtem a bez položky; to je v pořádku, položku zakládat nemusíš,
   - **Vynechat řádek**, ale jen u řádku faktury.

   Rozhodnutí se pak ukáže jako **Vybráno: …** a vezmeš ho zpět
   tlačítkem **Zrušit výběr**. Každé rozhodnutí se hned uloží — když
   náhled zavřeš a otevřeš znovu, máš ho předvyplněné. Dokud něco
   zůstává nerozhodnuté, obě tlačítka **Vystavit…** jsou zašedlá — bez
   vysvětlení, takže když nejde kliknout, hledej nedořešenou referenci.

6. **Vystav doklad, nebo návrh zamítni.**
   - **Vystavit a uzavřít** — **Faktura přijatá** vznikne rovnou ve
     stavu **V pořádku**: dostane číslo, zaúčtuje se a nic dalšího po
     tobě nechce. Zpráva přejde na **Hotovo**. Volba pro návrhy, které
     po kontrole sedí. Když dokladu chybí náležitost (třeba registrace
     DPH nebo kurz), Shipard vystavení odmítne s hláškou — pak jdi
     cestou konceptu a chybějící doplň ve formuláři.
   - **Vystavit koncept** — doklad vznikne ve stavu **Koncept**
     a zpráva přejde na **Hotovo**. Volba pro chvíle, kdy chceš před
     uzavřením ještě něco upravit.
   - **Zamítnout** — když to faktura vůbec není (reklama, upomínka)
     nebo je vytěžení nepoužitelné. Důvod je povinný a uloží se k návrhu:
     *špatně rozpoznaný typ*, *není to faktura*.

7. **Dokonči koncept.** Po **Vystavit koncept** se ti doklad hned otevře
   v editačním formuláři a je plně editovatelný — co jsi v náhledu jen
   zaregistroval, oprav teď. Zaúčtuje se teprve přechodem
   na stav **V pořádku**. Po **Vystavit a uzavřít** tenhle krok odpadá —
   doklad je hotový a na Dashboardu ho z potvrzující lišty otevřeš
   odkazem **Otevřít**.

## Na co narazíš

**Jistota není správnost.** Procento říká, jak si byl model jistý sám
sebou. Badge u návrhu se z něj odvozuje:

| Badge | Jistota | Co to znamená pro tebe |
|---|---|---|
| **K použití** | 90 % a víc | Zkontroluj Součty a Datumy. Zbytek namátkou |
| **Čeká na review** | 60–90 % | Projdi všechny sekce z kroku 3 |
| **Nízká jistota** | pod 60 % | Čti řádek po řádku, nebo zamítni a zadej ručně |
| **Chyba extrakce** | — | Extrakce se nepovedla, typicky nečitelné PDF. Zkus **Znovu analyzovat** |

**Jistý návrh můžeš použít rovnou z Dashboardu.** Faktury s badge
**K použití** jsou v sekci **Připraveno** sbalené do jednoho pruhu —
vidíš na něm počet čekajících, součet částek po měnách a rozsah jistoty.
Tlačítko **Zobrazit** pruh rozbalí na seznam řádků; každý řádek nese
jistotu, dodavatele, datum, částku a tlačítko **Použít** — **Koncept**
vznikne na jeden klik, bez otevírání náhledu. Když v návrhu zbývá
nerozhodnutá reference (dodavatel, položka…), Shipard místo uložení
otevře náhled ke kontrole a rozhodneš ji tam. Náhled si i u jistého
návrhu můžeš otevřít sám ikonou oka u řádku. Vystavit a uzavřít na jeden
klik odtud nejde — rovnou uzavřít se dá jen z náhledu, po kontrole.
Jisté návrhy k zařazení do **Spisovny** mají v sekci vedle vlastní pruh
— stejné rozbalení a **Použít**, jen bez součtu částek.

**Víc faktur najednou projdeš frontou.** Když na Dashboardu čeká víc
návrhů přijatých faktur, na záložkách **Vše** a **Přijaté faktury** je
vedle filtru tlačítko **Projít frontu** s počtem čekajících. Otevře
náhled nejstarší zprávy a po každém rozhodnutí — **Vystavit a uzavřít**,
**Vystavit koncept**, **Zamítnout**, nebo **Přeskočit** (nechá návrh na
později, karta zůstane) — rovnou ukáže další. V hlavičce náhledu vidíš,
kolikátou zprávu z kolika právě řešíš. Po vystavení konceptu se ve frontě
formulář neotvírá — koncepty dokončíš po průchodu. Na konci (nebo když
frontu zavřeš křížkem dřív) se ukáže souhrn, kolik jsi uzavřel, kolik
vzniklo konceptů, kolik jsi zamítl a přeskočil. Pokud na Dashboardu čekají
i karty **Nová kategorie**, průchod začne dialogem, kde můžeš chybějící
kategorie nejdřív založit — návrhy, kterým chyběla jen položka, pak
projdou rovnou.

Pruh přijatých faktur v sekci **Připraveno** má navíc vlastní tlačítko
**Projít** — stejný průchod, ale jen přes jisté faktury z tohoto pruhu.
**Projít frontu** u filtru bere jisté návrhy i ty ke kontrole. Pruh
Spisovny průchod zatím nemá — návrhy z něj použiješ po jednom.

**Vystavit a uzavřít se nevrací jedním klikem.** Doklad je po něm
uzamčený ve stavu **V pořádku** jako každý jiný hotový doklad — když v něm
dodatečně najdeš chybu, řeší se převodem na **V opravě**, případně
**Stornem** (viz [Oprava dokladu](../faktury-prijate/oprava-dokladu.md)).
Když si nejsi jistý, vystav koncept.

**Z jednoho e-mailu vznikne nejvýše jeden návrh.** AI vytěží hlavní
dokument zprávy (typicky fakturu). Když ve zprávě najde ještě něco dalšího
— třeba smlouvu v příloze vedle faktury — ukáže to na kartě jen jako
poznámku; dokument z toho nevznikne a založíš ho ručně. Viz
[Co Shipard dnes neumí](../co-dnes-nejde.md).

**Doplněno z historie.** U některých polí najdeš poznámku, že hodnota
nepřišla z faktury, ale z tvých starších dokladů od stejného dodavatele —
*přesná shoda*, *podobný text* nebo *častá položka dodavatele*. První dvě
bývají spolehlivé; **častou položku ověřuj vždy** — znamená jen „tohle
u tohohle dodavatele býváš zvyklý", ne že to je na téhle faktuře.

**Obsahová klasifikace.** Když historie mlčí, AI doklad zařadí podle
obsahu — poznámka u řádku pak říká *Obsahová klasifikace — Pohonné hmoty
(pravidlo dodavatele)* nebo *(AI)*. Návrh doplní položku, nebo aspoň
účet — ten vidíš ve sloupci **Účet** tabulky řádků (sloupec se ukazuje,
jen když ho aspoň jeden řádek má). U kategorií jako občerstvení poznámka
navíc upozorní na DPH typicky bez nároku na odpočet. Detaily a správa
kategorií: [Obsahové štítky](../polozky/obsahove-stitky.md).

**Faktury s cenami včetně DPH.** U dokladů, kde jsou jednotkové ceny
uvedené s daní (typicky drobný prodej, občerstvení), se daň může spočítat
dvakrát a **Celkem** pak vyjde vyšší než na faktuře. Je to známá chyba —
viz [Co Shipard dnes neumí](../co-dnes-nejde.md). U takových faktur
kontroluj celkovou částku vždy.

**Reverse charge (samovyměření).** U faktury za zboží nebo služby z EU,
za služby ze třetí země nebo s tuzemským přenesením daňové povinnosti
určí kód DPH řádků Shipard sám podle údajů na dokladu; dodavatelovu
rekapitulaci s nulovou daní nepřebírá. Jestli jde o plnění z EU
(**Místo plnění** *Intrakomunitární plnění*) nebo ze zahraničí
(*Zahraničí*), určí podle **DIČ dodavatele**, ne podle jeho adresy —
firma se sídlem mimo EU, která fakturuje pod DIČ některého státu EU, je
plnění z EU (u služeb *Základní - služby EU*). Když se tím místo plnění
proti tomu, co přečetla AI, změnilo, náhled to ukáže jako upozornění
u místa plnění. Zkontroluj tři věci: kód DPH
u řádků (u služeb z EU *Základní - služby EU*), v **DPH rekapitulaci**
řádek daně a k němu oddaňovací řádek, a že **Celkem** je rovno základu —
tedy tomu, co máš skutečně zaplatit. Kurz cizí měny návrh nedoplní,
zadáš ho v dokladu před potvrzením. Co u samovyměření návrh zatím neumí,
je v [Co Shipard dnes neumí](../co-dnes-nejde.md).

**Vytvořit z registru se nenabízí vždy.** Tlačítko se objeví, jen když se
z faktury vytěžilo IČO, subjekt pod ním v registru existuje a v evidenci
ho ještě nemáš. Když osobu se stejným IČO už máš, tlačítko se nenabízí —
najdeš ji vyhledávacím polem **Hledat…**. A když je registr zrovna
nedostupný, tlačítko prostě chybí a nic dalšího se neděje; **Hledat
v registru…** v panelu je k dispozici vždy.

**U bankovního účtu musí být nejdřív rozhodnutý dodavatel.** Dokud není,
panel u účtu místo vyhledávání napíše *Nejdřív vyber nebo vytvoř
dodavatele.* a nabídne jedině vytvoření nového účtu — účet se totiž
zakládá k někomu.

**Znovu analyzovat nic neztratí.** Opakovaná analýza vytvoří nový návrh,
který ten dosavadní nahradí; starší běhy zůstávají u zprávy vidět na
záložce **Analýzy**. Zprávu s návrhem, který jsi už **použil**, znovu
analyzovat nejde — nejdřív by ses musel dokladu zbavit přes podporu.

**Rozhodnutí v náhledu se ukládají průběžně.** Náhled můžeš kdykoli
zavřít, rozhodnutí u dodavatele, položek nebo účtů zůstanou a při dalším
otevření jsou předvyplněná. Jen **Znovu analyzovat** je smaže — nový
návrh začíná bez rozhodnutí. Dotaz **Neuložená rozhodnutí** při zavírání
uvidíš pouze tehdy, když se poslední rozhodnutí ještě neuložilo nebo
uložení selhalo (v patičce pak svítí *Rozhodnutí se nepodařilo uložit*):
**Zůstat** nechá náhled otevřený a další změna uložení zopakuje,
**Zahodit** náhled zavře.

**Co udělá Zamítnout.** Návrh dostane stav **Zamítnuto**, důvod se uloží k němu a v **Došlé poště** ho u zprávy pak vidíš jako
*Důvod zamítnutí*. Karta z Dashboardu zmizí. Zpráva a přílohy zůstávají —
zamítá se návrh dokladu, ne e-mail. Zpráva přejde na **Hotovo**.

**Zamítnutí se z rozhraní nevrací.** Když jsi zamítl omylem, spusť
**Znovu analyzovat** — dostaneš nový návrh; ten zamítnutý zůstane
i s důvodem v historii na záložce **Analýzy**. Důvod zamítnutí nikam
neodchází, zůstává jen v tvojí agendě — když AI čte něco opakovaně
špatně, nahlas to zvlášť.

**Ke stejnému náhledu se dostaneš i z Došlé pošty.** Otevři zprávu,
přepni na záložku **Návrh** a klikni na **Zobrazit detail**. Hodí se,
když karta na Dashboardu už není — třeba když se vracíš k něčemu
staršímu. Na záložce jsou i tlačítka **Použít** a **Zamítnout**.

**Vytěžení nespustíš na přání.** Analýza běží automaticky po doručení
zprávy. Ruční cesta je jen **Znovu analyzovat** u už doručené zprávy.

**Když dodavatel umí ISDOC, popros ho o něj.** Přiloženou fakturu ve
formátu ISDOC Shipard převezme přímo, bez čtení AI — a je to přesnější než
cokoli popsané na téhle stránce. Položky k řádkům se hledají stejně jako
u vytěžené faktury, takže návrh může skončit **Ke kontrole** kvůli nové
kategorii — viz [Obsahové štítky](../polozky/obsahove-stitky.md).

## Souvisí

- [Když AI přečte fakturu špatně](kdyz-ai-cte-spatne.md) — kde se která
  chyba opravuje
- [Obsahové štítky](../polozky/obsahove-stitky.md) — karta Nová kategorie
  a správa kategorií nákladů
- [Založení osoby](../osoby/zalozeni-osoby.md) — natažení firmy
  z registru mimo poštu a ruční založení
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy sekcí
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — kde ještě nemusí
  souhlasit čísla
- [Pro testery](../../TESTERS.md) — jak nahlásit rozdíl, který jsi našel
