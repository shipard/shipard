---
title: Příjem pošty
summary: Jak dostat fakturu do Shipardu, co se s ní pak děje a jak si poradit s poštou, ve které není doklad ani dokument.
keywords: [příjem pošty, přeposlat fakturu, adresa pro poštu, kam poslat fakturu, nahrát soubor, nahrání z dashboardu, přetáhnout soubor, nedorazilo, reklama, newsletter, hromadná pošta, ostatní pošta, sken obálky, neobsahuje doklad ani dokument, k vyřízení, expirace domény, výzva k platbě, upomínka, lhůta, vyřízeno, archivovat vše, vrátit, pravidlo odesílatele, archivovat bez analýzy, archivovat hned, smíšený odesílatel, ISDOC, sken, skener, předmět zprávy, titulek zprávy, hledání v poště, dodavatel u zprávy, analýza selhala, chyba analýzy, AI vrátila data v nečekaném tvaru, nepoužitelný návrh, znovu analyzovat, předzpracování, hotovo s chybami, faktura z odkazu, odkaz na dokument nefunguje, návrh vznikl bez výsledku předzpracování, pravidla předzpracování]
related: [posta/kontrola-vytezeni.md, slovnicek.md, co-dnes-nejde.md]
---

# Příjem pošty

Shipard nemá schránku, do které bys chodil číst e-maily. Faktury mu
přeposíláš na jeho adresu, nebo je nahraješ přímo z Dashboardu —
a on je sám přečte a připraví z nich doklady.

## Kdy to potřebuješ

Přišla ti faktura od dodavatele — e-mailem nebo jako PDF na disku — a nechce
se ti ji přepisovat do systému ručně.

## Postup

1. **Zjisti svou adresu pro příjem pošty.** Odvozuje se z adresy, na
   které běží tvoje aplikace: když Shipard otevíráš na
   `mojefirma.shpd.dev`, poštu mu posíláš na **`mojefirma@shpd.dev`**.

2. **Přepošli fakturu na tuhle adresu.** Buď přepošli celý e-mail od
   dodavatele, nebo napiš nový a přilož PDF. Předmět ani text psát nemusíš —
   Shipardu jde o přílohu. **Máš-li fakturu jako soubor na disku**, nemusíš
   nic posílat: na **Dashboardu** klikni na **Nahrát**, nebo soubory
   přetáhni myší kamkoli na plochu Dashboardu.

3. **Podívej se do Došlé pošty.** Zpráva se objeví se stavem **Nová**
   a u ní badge, který ukazuje, jak daleko je strojové čtení: **Ve frontě**
   → **Analyzuje se** → **Analyzováno**.

4. **Vyzvedni si výsledek na Dashboardu.** Když AI ve zprávě našla fakturu,
   zpráva se sama přepne na **K řešení** a na Dashboardu se objeví návrh —
   jistý v sekci **Připraveno** (sbalený souhrnný pruh, tlačítko
   **Použít** na řádku), ostatní jako karty v sekci **Ke kontrole**
   s tlačítkem **Zkontrolovat**. Sekce jdou na Dashboardu v pořadí
   práce: **Položky k založení**, **Připraveno**, **Ke kontrole**,
   **K vyřízení**, **Nepodařilo se zpracovat**, **Upozornění**
   a **Ostatní**. Každá ukazuje nejvýš 30 karet — skutečný počet vidíš
   v hlavičce sekce a zbytek otevřeš odkazem **a N dalších** pod ní.
   Odtud pokračuj podle [Kontrola vytěženého dokladu](kontrola-vytezeni.md).

## Na co narazíš

**Nahrání z Dashboardu.** Tlačítko **Nahrát** (vedle **Obnovit**) otevře
okno, kam soubory přetáhneš nebo je vybereš tlačítkem **Vybrat soubory**;
totéž okno se otevře, když soubory přetáhneš rovnou na plochu Dashboardu.
U více souborů si vybereš, jestli vznikne **Jedna zpráva** se všemi
soubory, nebo **Každý soubor zvlášť** (výchozí — každá faktura je pak
samostatná zpráva s vlastní analýzou). Najednou lze nahrát nejvýše
20 souborů. Nahraná zpráva se tváří jako běžná pošta: najdeš ji v **Došlé
poště** (jako odesílatel jsi uveden ty), AI ji přečte a výsledek si
vyzvedneš na Dashboardu úplně stejně.

**Skeny a nahrané soubory v seznamu.** Zpráva ze skeneru má v předmětu
jen něco jako „Message from …" a nahraný soubor název souboru — podle toho
bys nic nenašel. Proto u takových zpráv Shipard v **Došlé poště** ukáže
místo předmětu titulek, který AI odvodila z obsahu (například „Faktura
2026-0042 — Dodavatel s.r.o., 13 105 Kč"), a pod ním dodavatele; technický
odesílatel (skener, kolega) je až ve třetím řádku za názvem schránky.
U běžných e-mailů zůstává předmět tak, jak ho znáš ze své pošty. Hledat
můžeš podle předmětu, titulku, dodavatele i kódu zprávy — stačí krátký
tvar, jak ho vidíš v náhledu dokladu nebo u dokladu (třeba `260905-0012`).
Původní předmět a plný kód najdeš v detailu zprávy v **Technických
údajích**. Dodavatele u zprávy můžeš
změnit ručně ve formuláři (**Upravit** → sekce **Partner**) — ruční volbu
už analýza nepřepíše; po **Použít** se dodavatel převezme z vytvořeného
dokladu.

**Co posílat.** Ověřené je **PDF**. Nejlepší je **ISDOC** — strojově
čitelnou fakturu Shipard převezme přímo, bez čtení AI, takže nemá co
přečíst špatně (AI jen zařadí řádky do kategorií, když k nim nemáš
položku); když ho tvůj dodavatel umí, popros ho o něj. Fotku nebo sken
zkusit můžeš, ale nespoléhej na výsledek a zkontroluj ho o to pečlivěji.

**Víc dokumentů v jedné zprávě.** Z jednoho e-mailu vznikne **nejvýše
jeden návrh** — AI vybere hlavní dokument zprávy (typicky fakturu).
Když ve zprávě najde ještě něco dalšího (smlouvu vedle faktury, druhou
fakturu), ukáže to na kartě jako poznámku; dokument z toho automaticky
nevznikne a založíš ho ručně — viz
[Co Shipard dnes neumí](../co-dnes-nejde.md). Když posíláš víc faktur,
pošli každou samostatným e-mailem.

**Dokument do Spisovny.** Když AI pozná smlouvu, pojistku, nabídku,
revizi nebo úřední písemnost, nabídne na Dashboardu zařazení do
**Spisovny** místo dokladu; jisté návrhy mají v sekci **Připraveno**
vlastní pruh, oddělený od faktur. Vzniklý záznam dostane **všechny
přílohy zprávy** — jedno doručení = jeden záznam, jako v podacím deníku.

**Když zpráva neobsahuje doklad ani dokument, ale chce něco po tobě.**
Přehled expirujících domén, výzva k platbě, upomínka nebo žádost — AI
pozná, že z toho sice nevznikne doklad, ale že musíš něco udělat nebo
rozhodnout, a dá zprávu na Dashboard do sekce **K vyřízení**. Karta má
v titulku, o co jde (třeba „Expirace 3 domén — Registrátor a.s.“), pod
ním větu, co máš udělat, a když zpráva uvádí lhůtu, štítek **do 15. 10.
2026**; po lhůtě nebo do tří dnů před ní je červený (**po lhůtě …**).
Karty se řadí podle lhůty, bez lhůty jsou na konci. Jakmile věc vyřídíš,
klikni na **Vyřízeno** — zpráva jde do Archivu. **Otevřít e-mail** ukáže
původní zprávu, **Do koše** ji zahodí. Dodavatel u takové zprávy je ten,
od koho opravdu je (registrátor, úřad), ne kolega, který ji přeposlal.
Výzvy k platbě zatím chodí sem; zálohovou fakturu přijatou z nich
Shipard sám nezaloží — zadáš ji ručně, viz
[Zálohová faktura přijatá](../faktury-prijate/zalohova-faktura-prijata.md)
a [Co Shipard dnes neumí](../co-dnes-nejde.md).

**Když zpráva jen informuje.** Potvrzení platby, změnu stavu objednávky,
notifikaci z banky, doručenku, sken obálky i reklamu a newsletter AI
pozná a místo návrhu se na Dashboardu objeví nenápadný řádek v sekci
**Ostatní** s akcemi **Do koše** a **Archivovat**. Tučně je na něm krátký
popis toho, co ve zprávě je — třeba „Newsletter — novinky dodavatele“
nebo „Sken obálky“ —, vedle odesílatel a předmět e-mailu. Koš a Archiv
se liší jen tím, kam zpráva zmizí; obojí ji odklidí z cesty a přílohy
zůstanou. Nemusíš je odklízet po jedné: v hlavičce sekce je tlačítko
**Archivovat vše (N)**, které jedním klikem archivuje všechny
informativní zprávy — i ty, které se do třiceti zobrazených řádků
nevešly. Hned nato se dole ukáže hlášení *„Archivováno N zpráv“*
s tlačítkem **Vrátit**; máš na něj pár sekund, pak zprávy najdeš
v **Došlé poště** v Archivu a vrátíš je odtud. U starších zpráv, ke
kterým AI popis nenapsala (je u nich **Neobsahuje doklad ani dokument**),
Shipard neví, jestli něco chtějí, a tak je **Archivovat vše** nechá na
místě — buď je odklidíš po jedné, nebo v detailu zprávy dáš **Znovu
analyzovat** a AI je roztřídí.

**Hromadnou poštu Shipard pozná, ale sám ji neodklidí.** Newslettery se
dají rozpoznat z hlaviček e-mailu (odhlašovací odkaz a podobné). Je to pro
Shipard jen příznak — nikdy podle něj nic automaticky nearchivuje.

**Pravidlo odesílatele si založíš sám.** V **Nastavení → Ostatní →
Pošta → Pravidla odesílatelů** přidáš pravidlo na adresu nebo na celou
doménu. Shipard ho sám nenavrhne, ani když poštu od stejné adresy
opakovaně odklízíš ručně. Pravidlo má jednu ze dvou akcí:

- **Archivovat, když neobsahuje doklad ani nic k vyřízení** — zpráva
  projde analýzou jako každá jiná, a když v ní AI nenajde fakturu ani
  dokument pro Spisovnu a zpráva nic nechce (není to expirace, výzva
  k platbě ani žádost), odklidí ji do Archivu. Faktury od téhož
  odesílatele chodí dál normálně a zprávy **K vyřízení** také — pravidlo
  je nikdy neodklidí. U nového pravidla je tahle akce výchozí.
- **Archivovat hned, bez analýzy** — zpráva jde rovnou do Archivu a AI ji
  vůbec nečte. Šetří to analýzu, ale spolkne i fakturu, kdyby od té adresy
  nějaká přišla. Hodí se jen pro odesílatele, kteří doklady nikdy
  neposílají.

Pravidlo platí, až ho uložíš jako **V pořádku**. Shipard pak rovnou
odklidí i zprávy od té adresy, které už čekají v sekci **Ostatní**.

- Když má adresa vlastní pravidlo a její doména jiné, platí to na adresu.
- Když si AI není dost jistá, že ve zprávě opravdu nic není, pravidlo ji
  nechá v sekci **Ostatní** a rozhodneš ty. Totéž platí pro zprávy
  analyzované starší verzí analýzy, která míru jistoty ještě neuváděla.
- Zprávu, kterou jsi z Archivu vrátil nebo nechal **Znovu analyzovat**,
  už pravidlo podruhé neodklidí.
- Auto-archivované zprávy nezmizí bez zprávy: Dashboard ukáže denní kartu
  *„N zpráv automaticky archivováno"* s tlačítky **Zobrazit** a **Vrátit
  vše**. Vrácení platí pro celý den z té karty. Zprávy, které AI už
  přečetla, se vrátí rovnou do sekce **Ostatní** bez nové analýzy; zprávy
  archivované hned při doručení jdou nejdřív na analýzu.

**Analýza selhala.** U zprávy svítí badge **Analýza selhala** a na
Dashboardu je karta v sekci **Nepodařilo se zpracovat**. Její titulek
říká, co se stalo — nejčastěji
**AI vrátila data v nečekaném tvaru**: to není chyba ve tvé zprávě ani
v příloze, ale v nastavení analýzy na naší straně. Pod **Zobrazit detail**
na kartě najdeš, co se stalo a co dělat; totéž ukazuje záložka **Návrh**
u zprávy (technické podrobnosti jsou sbalené). **Znovu analyzovat** má
smysl, jen když se analýza od té doby aktualizovala — v tom případě ti to
karta řekne a tlačítko je hlavní akcí. Jinak opakování dopadne stejně:
doklad zadej ručně a dej nám vědět, o jakou zprávu šlo. Když AI
odpověděla, ale návrh neprošel kontrolou formátu, uvidíš na záložce
**Návrh** odznak **Chyba extrakce** a pod ním stejnou kartu s vysvětlením
(**AI vrátila nepoužitelný návrh**).

**Předzpracování skončilo s chybami.** U některých odesílatelů faktura
nepřijde jako příloha, ale jen jako odkaz ke stažení nebo přímo jako text
e-mailu. Shipard ji podle pravidla stáhne nebo převede do PDF ještě před
analýzou — tomu říká předzpracování. Když se to nepovede, u zprávy svítí
badge **Hotovo s chybami** a AI pracovala jen s tím, co ve zprávě zůstalo,
takže návrh nebo zařazení nemusí sedět. Poznáš to na třech místech: karta
zprávy na Dashboardu má řádek **Předzpracování: …** (v kompaktních řádcích
jen ikonu varování, text uvidíš po najetí), záložka **Návrh** má nahoře
upozornění **Návrh vznikl bez výsledku předzpracování** a záložka
**Obsah** kartu s vysvětlením — co přesně se nepovedlo a co udělat.
Nejčastěji stačí dokument z e-mailu stáhnout ručně a nahrát ho na
Dashboard; když hláška ukazuje na pravidlo, zkontroluj ho v **Nastavení →
Pošta → Pravidla předzpracování**. Znovu spustit předzpracování
z aplikace zatím nejde (viz [Co dnes nejde](../co-dnes-nejde.md)). Když se
nepodařilo načíst přílohu ISDOC, uvidíš v **Obsahu** jen informační
kartu — doklad pak AI vyčte z ostatních příloh a návrh zkontroluješ
obvyklým způsobem.

**Nic nedorazilo.** Zkontroluj v tomhle pořadí: sedí adresa, na kterou jsi
posílal? Byla faktura opravdu jako příloha, ne jen odkaz ke stažení? Neuvízl
e-mail u tvého poskytovatele?

**Doručenou zprávu Shipard nemaže.** Ani po vytvoření dokladu, ani po
zamítnutí návrhu. Zpráva i s přílohami zůstává v **Došlé poště** jako důkaz,
odkud doklad vznikl.

## Souvisí

- [Kontrola vytěženého dokladu](kontrola-vytezeni.md) — co dělat s návrhem,
  který AI připravila
- [Slovníček](../slovnicek.md) — stavy zpráv a co znamená badge analýzy
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
