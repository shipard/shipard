---
title: Kontrola vytěženého dokladu
summary: Jak porovnat návrh dokladu s originálem faktury, co kontrolovat první a kdy návrh zamítnout.
keywords: [kontrola, vytěžení, analýza, náhled dokladu, jistota, použít, vystavit koncept, vystavit a uzavřít, zamítnout, AI přečetla špatně, sedí částka, review, vytvořit z registru, hledat v registru, nový dodavatel z faktury, projít frontu, přeskočit, fronta faktur, reverse charge, samovyměření, faktura z EU, faktura z USA, software ze zahraničí, místo plnění, DIČ dodavatele, neplátce DPH, faktura od plátce, daň v ceně, bez DPH, napárování, napárovaná položka, špatná položka, změnit položku, položka řádku, zdroj napárování, kód dodavatele, sloupec Účet]
related: [slovnicek.md, co-dnes-nejde.md, osoby/zalozeni-osoby.md]
---

# Kontrola vytěženého dokladu

AI přečte přijatou fakturu a nabídne hotový návrh dokladu. **Návrh není
doklad** — doklad vznikne teprve tím, že návrh potvrdíš. Než to uděláš,
projdi ho podle postupu níž.

## Kdy to potřebuješ

Zpráva s fakturou dorazila a analýza skončila — v **Došlé poště** má
badge **Analyzováno** a stav **K řešení**, na **Dashboardu** se objevil
návrh s procentem **Jistoty**. Dashboard řadí karty do sekcí podle toku
práce: nahoře **Položky k založení**, pak **Připraveno** (jisté návrhy
sbalené do souhrnného pruhu s tlačítkem **Použít** na každém řádku),
**Ke kontrole** (návrhy s hlavním tlačítkem **Zkontrolovat**),
**K vyřízení** (pošta bez dokladu, která chce akci — viz
[Příjem pošty](prijem-posty.md)), **Nepodařilo se zpracovat**,
**Upozornění** a **Ostatní**. Každá sekce
ukazuje nejvýš 30 karet; když jich čeká víc, hlavička říká skutečný počet
a pod sekcí je odkaz **a N dalších**, který otevře **Došlou poštu**.

## Postup

1. **Otevři náhled.** Na **Dashboardu** u karty s fakturou klikni na
   **Zkontrolovat**.

2. **Zorientuj se v náhledu.** Vlevo je **PDF** faktury, jak přišla,
   vpravo data, která z ní AI přečetla. Kontrola je porovnávání levé
   strany s pravou. Na telefonu a v úzkém okně se místo dvou sloupců
   objeví taby **PDF** a **Náhled**. Pod titulkem je řádek **Došlá
   zpráva** s kódem, datem přijetí a odesílatelem zprávy, ze které návrh
   vznikl — hodí se, když od jednoho dodavatele přišlo víc pošty. Klik na
   kód ukáže celý e-mail i s přílohami; zavřením se vrátíš do náhledu
   a nic z toho, co jsi v něm rozhodl, se neztratí.

3. **Zkontroluj v tomhle pořadí.** Nezačínej řádky; začni tím, co se
   nejhůř opravuje později:

   | Sekce náhledu | Co porovnat s originálem |
   |---|---|
   | **Součty** | **Celkem** především. Pak **Základ**, **DPH**, **Zaokrouhlení**. Náhled ukazuje částky, které skončí na dokladu — Shipard je spočítá z řádků stejně jako při vystavení. Když se **Celkem** liší od částky na faktuře, náhled to hlásí upozorněním a pod **Celkem** uvidíš i částku z faktury (*Na dokladu dodavatele*) |
   | **DPH rekapitulace** | Sedí rozpad po sazbách? Je tam sazba, která na faktuře není? Nad tabulkou je uvedeno, zda je rekapitulace *převzatá z dokladu dodavatele*, nebo *přepočítaná podle řádků* — a proč (třeba když rekapitulace na faktuře aritmeticky nesedí) |
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
   - **Vynechat řádek**, ale jen u řádku faktury. Řádek se na doklad
     nezapíše a nezapočítá se do součtů v náhledu; **DPH rekapitulace**
     se pak spočítá z řádků, které zůstaly. Když kvůli tomu **Celkem**
     nesedí s fakturou, náhled to ukáže upozorněním a pod **Celkem**
     částkou *Na dokladu dodavatele*.

   Rozhodnutí se pak ukáže jako **Vybráno: …** a vezmeš ho zpět
   tlačítkem **Zrušit výběr**. Každé rozhodnutí se hned uloží — když
   náhled zavřeš a otevřeš znovu, máš ho předvyplněné. Dokud něco
   zůstává nerozhodnuté, obě tlačítka **Vystavit…** jsou zašedlá — bez
   vysvětlení, takže když nejde kliknout, hledej nedořešenou referenci.

6. **Zkontroluj položky řádků.** U každého řádku, který Shipard
   napároval na položku z tvého číselníku, je pod textem řádku druhý,
   menší řádek: kód a název položky a za tečkou zdroj napárování — *kód
   dodavatele*, *EAN*, *SKU*, *náš kód*, *z historie*, *podobný text*,
   *častá položka dodavatele*, *kategorie …*, *podle názvu*. Šedý zdroj
   je spolehlivý; **jantarový ověř** — *podobný text*, *častá položka
   dodavatele*, *kategorie* a *podle názvu* jsou odhady. Když je položka
   špatně, klikni na zelený odznak ✓ nebo rovnou na ten druhý řádek:
   otevře se stejný panel jako u nenapárované položky, nahoře s řádkem
   *Napárováno automaticky: …*, pod ním **Hledat…**, **Vytvořit novou
   položku** (předvyplněnou textem řádku), **Jen účet — bez položky**
   (jen u řádku, který nese účet) a **Vynechat řádek**. Po volbě se pod
   řádkem hned objeví název zvolené položky se zdrojem *zvoleno ručně*,
   odznak ✓ dostane obrys a náhled se obnoví — sloupec **Účet** pak
   ukazuje účet nové položky. **Zrušit výběr** vrátí automatické
   napárování. Rozhodnutí *jen účet* a *vynechat řádek* vidíš pod řádkem
   slovy (*jen účet 518100 …*, *řádek se vynechá*). Napárované řádky
   měníš po jednom; hromadné **+** v hlavičce sloupce **Položka** plní
   jen řádky bez položky.

7. **Vystav doklad, nebo návrh zamítni.**
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

8. **Dokonči koncept.** Po **Vystavit koncept** se ti doklad hned otevře
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
Počet i součty platí pro všechny připravené faktury, i když se do
rozbaleného seznamu vejde jen prvních 30.
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
i karty v sekci **Položky k založení**, průchod začne dialogem **Nejdřív
založte položky**, kde můžeš chybějící položky založit — návrhy, kterým
chyběla jen položka, pak projdou rovnou.

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

**Doplněno z historie.** Položka, sazba DPH nebo účet řádku nemusely
přijít z faktury, ale z tvých starších dokladů od stejného dodavatele.
Poznáš to podle zdroje za názvem položky pod řádkem — *z historie*
(přesná shoda textu), *podobný text* nebo *častá položka dodavatele*.
Po najetí myší na ten řádek uvidíš, ze kterého dokladu to je a co všechno
se z něj doplnilo. První zdroj bývá spolehlivý; **podobný text a častou
položku ověřuj vždy** — jsou jantarově a znamenají jen „tohle u tohohle
dodavatele býváš zvyklý", ne že to je na téhle faktuře.

**Obsahová klasifikace.** Když historie mlčí, AI doklad zařadí podle
obsahu. Když kategorie určí položku, je pod řádkem se zdrojem *kategorie
Pohonné hmoty* (jantarově — ověř) a po najetí myší vidíš, zda ji dala
*pravidlo dodavatele*, nebo *AI*. Když kategorie najde jen účet, ne
položku, je u řádku ikona ⟲ se stejnou poznámkou. U kategorií jako
občerstvení poznámka navíc upozorní na DPH typicky bez nároku na odpočet.
Detaily a správa kategorií:
[Obsahové štítky](../polozky/obsahove-stitky.md).

**Sloupec Účet.** Ukazuje, kam se řádek zaúčtuje: u řádku s účetní
položkou účet té položky, u řádku *jen účet* a u účetního dokladu účet
řádku. Služba nebo zásoba bez vlastního účtu mají **—** — účtují se
podle druhu položky, i když historie nebo kategorie nějaký účet
navrhly. Sloupec se ukazuje, jen když má účet aspoň jeden řádek. Když
u řádku vybereš položku ručně, účet navržený historií nebo kategorií se
na doklad nezapíše; kód DPH řádku se změnou položky nemění.

**Oprava napárování se zapamatuje.** Když u řádku s kódem dodavatele
vybereš jinou položku, než Shipard napároval, uloží si pro ten kód
dodavatele tu tvou — příští faktura od téhož dodavatele řádek napáruje
správně. Platí to jen pro položku vybranou v náhledu; oprava až
v Konceptu dosavadní zapamatování nepřepíše.

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
u místa plnění. Zkontroluj tři věci, všechny už v náhledu: kód DPH a
sazbu u řádků (u služeb z EU *Základní - služby EU*, 21 %, i když faktura
uvádí 0 %), v **DPH rekapitulaci** řádek daně a k němu oddaňovací řádek
(označený, ztlumený), a že **Celkem** je rovno základu — tedy tomu, co máš
skutečně zaplatit. Doklad po **Vystavit koncept** má stejná čísla jako
náhled. Když Shipard určí kód špatně, klikni na odznak u sazby řádku a
vyber jiný z nabídky; v hlavičce jde stejně přepnout **Místo plnění**
a **Režim DPH**. Rekapitulace i součty se přepočítají a volba platí
i pro **Použít** na kartě. Faktura ze zahraničí, která DPH
vůbec nezmiňuje (typicky software nebo předplatné z USA), je také
samovyměření — Shipard ho dovodí sám. Když AI u řádku neurčila, zda jde
o zboží, nebo službu, doplní to Shipard podle kategorie dokladu a v náhledu
to ukáže upozorněním u řádku; zkontroluj, že kategorie sedí. Kurz cizí
měny návrh nedoplní, zadáš ho v dokladu dřív, než mu dáš **V pořádku**.
Co u samovyměření návrh zatím neumí, je v
[Co Shipard dnes neumí](../co-dnes-nejde.md).

**Neplátce DPH.** Když k datu faktury nejsi plátcem DPH, daň dodavatele
si odečíst nemůžeš — je součástí ceny. Návrh faktury od plátce proto
vznikne s **Režimem DPH** *Bez DPH* (náhled u něj píše *neplátce DPH —
daň v ceně*): řádky ukazují ceny **včetně daně dodavatele**, **DPH
rekapitulace** chybí a **Celkem** se rovná částce k úhradě na faktuře.
Do nákladů i do závazku vůči dodavateli tak jde celá zaplacená částka.
Jestli jsi plátce, pozná Shipard podle **Registrace DPH** platné k datu
zdanitelného plnění (pole **Platí od** a **Platí do**; když datum plnění
na faktuře není, rozhoduje datum vystavení). Když jsi byl plátcem jen
část období, zpracují se proto starší a novější faktury různě. Dostaneš-li
jako plátce návrh *Bez DPH*, zkontroluj u registrace **Platí od** —
faktura má datum plnění před ním. U faktury s přenesením daňové
povinnosti náhled upozorní, že se daň nevyměří; víc v
[Co Shipard dnes neumí](../co-dnes-nejde.md).

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
u vytěžené faktury, takže návrh může skončit **Ke kontrole** kvůli
chybějící položce — její založení nabídne karta v sekci **Položky
k založení**, viz [Obsahové štítky](../polozky/obsahove-stitky.md).

## Souvisí

- [Když AI přečte fakturu špatně](kdyz-ai-cte-spatne.md) — kde se která
  chyba opravuje
- [Obsahové štítky](../polozky/obsahove-stitky.md) — položky k založení
  a správa kategorií nákladů
- [Založení osoby](../osoby/zalozeni-osoby.md) — natažení firmy
  z registru mimo poštu a ruční založení
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy sekcí
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — kde ještě nemusí
  souhlasit čísla
- [Pro testery](../../TESTERS.md) — jak nahlásit rozdíl, který jsi našel
