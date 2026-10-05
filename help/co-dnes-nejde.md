---
title: Co Shipard dnes neumí
summary: Poctivý seznam chybějících funkcí a míst, kde ještě nemusí souhlasit čísla.
keywords: [neumí, nejde, chybí, omezení, alfa, hromadné odeslání faktur, automatické odesílání, odeslat upomínku, vlastní text e-mailu, doručenka, vzhled faktury, dobropis, opravný daňový doklad, ISDOC, přiznání k DPH, kontrolní hlášení, záloha, nefunguje, odpisy, majetek, odepisování, samovyměření, reverse charge, přenesení daňové povinnosti, dovoz zboží, celní doklad, identifikovaná osoba, neplátce DPH]
related: [slovnicek.md]
---

# Co Shipard dnes neumí

Shipard je ve stavu **alfa**. Tahle stránka je záměrně na jednom místě, ať
nemusíš hledat funkci, která ještě neexistuje — a ať ti ji nikdo neslibuje.

Seznam se mění. Když něco nenajdeš ani tady, ani v dokumentaci, napiš na
**podpora@shipard.cz**.

---

## Výstupy pro daně

**Podání Shipard nikam neodešle.** Soubor pro daňový portál (XML)
i tiskový opis vyrobí a uloží k podání, ale na portál nebo do datové
schránky ho odesíláš ty — viz [Podání DPH](uctarna/dph-podani.md).

**Následné souhrnné hlášení Shipard podává celé znovu**, ne přes storno
řádky. Úřad počítá s oběma způsoby; jestli ti vyhovuje jen ten druhý,
ozvi se.

**Kontrolní hlášení neumí odpověď na výzvu** správce daně (rychlá
odpověď „nemám povinnost / potvrzuji"), ani sekci A.3 (investiční zlato).

**Zvláštní režimy Shipard nevykazuje.** Cestovní služba (§ 89), použité
zboží (§ 90), oprava u nedobytné pohledávky (§ 46) a poměrný nárok na
odpočet (§ 75) se v kontrolním hlášení vyplní jako „běžné plnění" bez
příznaku. Když je používáš, soubor před odevzdáním zkontroluj.

**Zámek období je ruční a po měsících.** Podané tvrzení ani fiskální měsíc
se nezamknou samy — klikneš **Uzamknout tvrzení** u podání, nebo zaškrtneš
**Uzamčeno** u měsíce (viz [Uzamčení období](uctarna/uzamceni-obdobi.md)).
Zaškrtnutí **Uzamčeno** u celého fiskálního roku doklady zatím neblokuje,
přehled zámků přes celý rok (rok × měsíc) neexistuje a ze zamčeného dokladu
se k odemknutí musíš proklikat sám. Přeúčtovat zamčený doklad umí jen
správce z konzole.

**Úhradu finančnímu úřadu Shipard zatím nespáruje.** Účetní doklad
přiznání sice založí závazek (nebo pohledávku) vůči správci daně s
variabilním a specifickým symbolem a splatností, ale účty odvodu
a nadměrného odpočtu DPH nejsou v saldokontu — platba z výpisu proti nim
nesedne sama, přiřadíš ji ručně. Účetní doklad přiznání také vzniká vždy
jako **koncept**: zaúčtuje se, až ho uzavřeš.

**Roční vypořádání koeficientu odpočtu (řádek 53) a úpravu odpočtu
(řádek 60) živé přiznání nespočítá.** Krácený odpočet přes zálohový
koeficient (řádek 52) funguje — koeficient per rok zadáš v Nastavení, viz
[Živé výstupy DPH](uctarna/dph-zive-vystupy.md). Vypořádací koeficient
sice můžeš uložit, ale vypořádací řádek posledního přiznání roku zatím
doplní účetní — a do účetního dokladu přiznání se rozdíl zálohového
a vypořádacího koeficientu nepromítne.

---

## Vydané faktury: odeslání po jedné, vzhled pevný

**Doklady odesíláš po jednom, ručně.** Fakturu, zálohovou fakturu, pokladní
doklad i prodejku pošleš odběrateli tlačítkem **Odeslat** — viz
[Odeslání faktury e-mailem](faktury-vydane/odeslani-faktury.md). Hromadné
odeslání víc dokladů najednou ani automatické odeslání po vystavení zatím
není.

**Posílají se jen doklady.** Upomínky, nabídky, objednávky ani přehledy
z Shipardu e-mailem neodejdou — účely kontaktů pro ně jsou připravené, ale
nic je zatím nepoužívá. Napsat samostatný e-mail bez dokladu nejde.

**Text e-mailu je předepsaný.** Předmět a text si pro jednu zprávu přepíšeš
v okně **Odeslat e-mailem**, vlastní výchozí znění ale nastavit nejde.
Zpráva je prostý text, bez formátování a bez podpisu s logem.

**Shipard neví, jestli zpráva došla.** Stav **Odesláno** znamená, že ji
převzal poštovní server. Nedoručitelnost ani přečtení se nesledují.

**Elektronická faktura (ISDOC) se k e-mailu nepřikládá.**

**Vzhled tištěného dokladu změníš jen v hlavičce.** Nastavíš barvu
hlavičky a stranu s logem; rozvržení dokladu je jedno. Jiné písmo, barvy
v těle dokladu ani vlastní šablona zatím nejsou.

**Podpis a razítko na doklad nedostaneš.** V zápatí je jméno toho, kdo
doklad vystavil — viz
[Kdo doklad vystavil](faktury-vydane/vystavil-na-dokladu.md) —, obrázek
podpisu ani razítka ale vložit nejde. Pokladní doklad a Kontace mají jen
prázdné linky na ruční podpis.

**Kontakty tvé firmy v zápatí dokladu nejsou.** Zápatí nese název firmy,
**Vystavil** a číslo strany; telefon, e-mail ani web se do něj zatím
netisknou. Na doklad je dostaneš vlastním textem s umístěním **Konec
dokumentu** — viz [Texty na tiscích](faktury-vydane/texty-na-tiscich.md).

**Vlastní text na doklad nejde omezit na jednoho odběratele ani na jednu
pokladnu.** Cílit jde na tisk, typ dokladu, číselnou řadu a jazyk. Text
si před uložením nevyzkoušíš na konkrétním dokladu — uvidíš ho až v náhledu
tisku. Obrázky a tabulky do textu vložit nejdou.

**E-mail s dokladem je jen prostý text.** Vlastní předmět a text si
nastavíš, formátování ani obrázky v e-mailu ne.

**Doklad vytiskneš jen česky, anglicky, slovensky a německy.** Jiné jazyky
nejsou — odběrateli z jiné země se tiskne anglicky.

**QR platba je jen česká.** Kód na dokladu čtou bankovní aplikace českých
bank; pro odběratele v zahraničí jiný standard zatím není.

**Fakturu pro strojové zpracování (ISDOC) Shipard nevystaví.** PDF je jen
obrázek dokladu pro člověka.

**Opravný daňový doklad nemá vlastní tisk.** Dobropis je v Shipardu faktura
se zápornou částkou a vytiskne se s titulkem faktury — bez odkazu na
opravovaný doklad a bez důvodu opravy.

**Fakturu z proformy nevystavíš jedním klikem**: až zálohu dostaneš,
vystav vydanou fakturu ručně a zálohu na ní odečti řádkem *Odpočet přijaté
zálohy* s variabilním symbolem proformy. **Daňový doklad k přijaté záloze**
Shipard zatím neumí — daň z přijaté zálohy tak přiznáš až fakturou. Viz
[Zálohová faktura](faktury-vydane/zalohova-faktura.md).

---

## Pokladna: bez knihy a bez účtenky z pokladní tiskárny

**Pokladní knihu Shipard zatím nevede.** Pokladní doklady a prodejky se
zaúčtují na účet pokladny, ale přehled zůstatku a pohybů pokladny za období,
počáteční stav ani inventura pokladny nejsou. Zůstatek dnes zjistíš jen
z účetního deníku nebo hlavní knihy na účtu pokladny.

**Účtenku na pokladní tiskárně nevytiskneš.**
[Prodejka](pokladna/prodejka.md) i [pokladní doklad](pokladna/pokladni-doklad.md)
se tisknou jen jako PDF na stránku A4. Úzkou účtenku pro zákazníka u pultu
musíš dát z jiného zařízení.

**Vyúčtování od platební brány, terminálu nebo dopravce Shipard sám
nevytvoří.** Prodej kartou, přes bránu nebo na dobírku založí pohledávku
za plátcem (viz [Platba kartou, přes bránu a dobírkou](pokladna/platba-kartou-branou-dobirkou.md)),
ale souhrnné vyúčtování s poplatky, které tyhle pohledávky uzavře a
připraví připsání od brány k párování, zatím zadáváš ručně účetním
dokladem. Připsání od brány z bankovního výpisu se k dávce nepřiřadí samo.

---

## Majetek

**Majetek se účtuje jen dávkou za období.** Zařazení, odpisy i vyřazení
zaúčtuje **Odpisy za období** jedním dokladem (viz
[Zaúčtování majetku](majetek/zauctovani-majetku.md)); zaúčtovat zařazení
hned v den zařazení zatím nejde. Odložená daň a účetní metody výkonové
a zrychlené (AV / AM) zatím nejsou.

**Prodej majetku se s vyřazením nepropojí.** Fakturu vydanou za prodaný
majetek ke kartě přiřadíš (viz
[Majetek na dokladech](majetek/naklady-na-majetek.md)), ale vyřazení
z evidence uděláš sám tlačítkem **Vyřadit**. Kartu jde založit jen z řádku
pořízení na přijaté faktuře — AI při vytěžení faktury kartu nenavrhne.

**Karta nevidí pohyb věci.** Předání do užívání (kdo věc má), umístění,
příslušenství, vlastnosti podle typu a inventurní seznamy zatím nejsou —
soupis majetku proto neukazuje osobu ani místo.

**Kartu majetku ani přehledy nevytiskneš.** Tisk karty a přehledů do PDF
zatím není; přehledy stáhneš jako Excel nebo CSV a vytiskneš z tabulkového
procesoru (viz [Přehledy majetku](majetek/prehledy-majetku.md)).
Daňové odpisy pro DPPO jsou podklad po odpisových skupinách — do řádků
tiskopisu přiznání je Shipard nepřenáší.

**Majetek ze starého Shipardu se zatím nepřenáší.** Import karet, historie
odpisů a vazeb na doklady přijde později; do té doby karty zakládáš ručně,
viz [Evidence majetku](majetek/evidence-majetku.md).

**Soubor a množstevní karta jsou zatím jen popisky.** **Způsob sledování**
na kartě vybereš, ale Shipard se podle něj ještě nechová — žádné množství,
žádné části souboru.

## Kde ještě nemusí souhlasit čísla

Tohle je pro nás priorita číslo jedna a pracuje se na tom. Do té doby
u těchto případů **porovnej celkovou částku dokladu s originálem faktury**:

- **Faktury s jednotkovými cenami včetně DPH** (typicky drobný prodej,
  občerstvení). Daň se může spočítat dvakrát a celková částka pak vyjde
  vyšší než na faktuře.
- **Zaokrouhlení celkové částky** — dodělané, ale ještě neověřené na širší
  sadě faktur.
- **Vratka dobropisu z bankovního výpisu** — dobropis vydané faktury vede
  Shipard v saldokontu jako závazek a přijatý dobropis jako pohledávku.
  Když ho pak zákazník nebo dodavatel skutečně vrátí z účtu, platba
  skončí v **Nespárované platby** a případ zůstane otevřený, dokud ji
  nepřiřadíš ručně. Vratku přeplatku (zaplaceno víc, než bylo
  fakturováno) spáruje sám.

Když najdeš rozdíl, chceme ho vědět i kdyby byl o korunu. Jak ho nahlásit
je v [TESTERS.md](../TESTERS.md).

---

## AI vytěžení faktur

- **Vytěžení není zaručeně správné.** Je to návrh ke kontrole, ne hotový
  doklad. Vysoká **Jistota** znamená, že model neměl pochybnost — ne že má
  pravdu.
- **Z jednoho e-mailu vznikne nejvýše jeden návrh dokumentu.** Když zpráva
  nese víc dokumentů (dvě faktury, faktura + smlouva), AI vytěží jen ten
  hlavní; ostatní nálezy uvidíš na kartě jako poznámku a založíš je ručně.
  Je to vědomé omezení, ne chyba čtení — víc faktur pošli každou
  samostatným e-mailem.
- **Nedá se to nastavit „na dodavatele".** Zvyklosti se sice zohledňují
  z tvé dosavadní historie, ale nemáš žádnou obrazovku, kde bys pravidla
  pro konkrétního dodavatele zadal ručně.
- **Analýzu nespustíš, kdy se ti zachce.** První běží automaticky po
  doručení zprávy; ručně jde jen **Znovu analyzovat** u zprávy, která už
  je analyzovaná nebo u které analýza selhala. Zprávu v Archivu nebo
  v koši znovu analyzovat nelze.
- **Předzpracování znovu nespustíš.** Když se nepovedlo stáhnout fakturu
  z odkazu nebo převést text e-mailu do PDF (badge **Hotovo s chybami**),
  v aplikaci není tlačítko, které by to zkusilo znovu. Dokument stáhni
  ručně a nahraj ho na Dashboard; **Znovu analyzovat** předzpracování
  neopakuje.
- **Text řádku v náhledu návrhu neupravíš.** Řádek dostane text tak, jak
  ho AI přečetla — název položky a za pomlčkou případný doplňující popis
  (fakturované období, číslo služby). Přesně tento text vidíš v náhledu
  a přesně ten skončí na dokladu; opravit ho jde až v Konceptu po
  **Vystavit koncept**.
- **Kontaktní osoba z faktury zůstává jen v náhledu.** Jméno uvedené
  u dodavatele nebo odběratele („Vyřizuje“, „Attn“, jméno nad názvem
  firmy) AI přečte a náhled návrhu ho ukáže jako **Kontakt**. Na doklad
  ani k dodavateli do **Osob** se nepřenáší; potřebuješ-li ho evidovat,
  doplň ho u dodavatele ručně.
- **Samovyměření (reverse charge) má v návrhu své meze.** Fakturu za
  služby nebo zboží z EU, služby ze třetí země a tuzemské přenesení
  daňové povinnosti (stavební práce, odpad a šrot) návrh zpracuje sám:
  kód DPH řádků určí podle údajů na dokladu a **DPH rekapitulaci**
  přepočítá s oddaňovacím řádkem. Sníženou sazbu u samovyměření sám
  nepozná (dá základní) — vyber ji v náhledu kliknutím na odznak u sazby
  řádku. Co zatím neumí: fakturu se zahraniční DPH (hotel nebo tankování
  v cizině), přenesení daňové povinnosti mimo stavební práce a přílohu 5
  (mobilní telefony, povolenky…), **dovoz zboží ze třetí země** (DPH se
  řeší z celního dokladu, ne z faktury dodavatele) a služby ze zahraničí
  se zvláštním místem plnění (ubytování, jízdenky a letenky, stravování,
  nájem a služby k nemovitosti, mýto, parkování). Pro ty v nabídce kódů
  v náhledu nic není, návrh skončí chybou kódu DPH a doklad založíš ručně.
- **Samovyměření u neplátce — identifikované osoby.** Když k datu faktury
  nemáš platnou **Registraci DPH**, návrh vznikne *Bez DPH* a daň se na
  něm nevyměří, ani když jde o fakturu s přenesením daňové povinnosti
  (služba z EU, zboží z EU). Náhled na to upozorní. Jsi-li identifikovaná
  osoba, daň z takové faktury musíš přiznat mimo tento doklad.

Když dodavatel přiloží fakturu ve formátu **ISDOC**, data se převezmou
přímo bez čtení AI — je to přesnější. AI se u něj použije jen na zařazení
řádků do kategorií, když k nim nemáš položku z historie. Vyplatí se
o ISDOC dodavatele požádat.

---

## Vnitřní AI asistent (Chat)

- **Asistent umí jen čtení.** Nezaloží ti doklad, nezmění záznam, nic
  neodešle. Poradí, najde, spočítá — provést to musíš ty.
- **Neví o všem.** Když se ptáš na postup a asistent odpoví, že to neví,
  je to správná odpověď — lepší než vymyšlený návod.

---

## Správa dat a provoz

- **Zálohu a obnovu svého datového zdroje si sám neuděláš.** Data
  zálohujeme my; obnova ze zálohy se dnes řeší přes podporu.
- **Datový zdroj se nedá smazat z rozhraní.** Napiš na podporu.
- **Převod dat ze starého Shipardu neděláš sám.** Import existuje, ale
  spouštíme ho my a s tebou pak porovnáme kontrolní součty.
- **Uživatele a přístupy zakládáme ručně.** Viz [TESTERS.md](../TESTERS.md).

---

## Rozhraní

- **Mobilní aplikace v App Storu ani Google Play není a nechystá se.**
  Rozhraní je responzivní a Shipard si přidáš na plochu telefonu nebo
  nainstaluješ do počítače přímo z prohlížeče — viz
  [Instalace aplikace](instalace-aplikace.md). Nainstalovaná aplikace ale
  **nefunguje bez připojení** a **neposílá notifikace do telefonu**.
- **Report nevytiskneš ani neuložíš do PDF přímo ze Shipardu.** Stáhneš ho
  jako sešit pro Excel nebo jako CSV a vytiskneš z tabulkového procesoru —
  viz [Export reportu do Excelu nebo CSV](uctarna/export-reportu.md).
  **Seznamy** (faktury, osoby, účetní deník…) do souboru stáhnout nejde.
- **Anglické rozhraní není úplné.** Čeština je hlavní jazyk; v angličtině
  můžeš narazit na nepřeložené popisky.
- **Narazíš na nedodělané obrazovky.** Nic tím nerozbiješ tak, abychom to
  nespravili.

---

## Souvisí

- [Slovníček](slovnicek.md)
- [Pro testery](../TESTERS.md) — jak nahlásit chybu
- [Kam projekt směřuje](../docs/roadmap.md) — v jakém pořadí se chybějící
  věci dodělávají

---

*Poznámka pro AI asistenta: když se dotaz uživatele týká něčeho z téhle
stránky, řekni, že to Shipard zatím neumí, a nabídni náhradní postup, pokud
existuje. Nevymýšlej návod k funkci, která není.*
