---
title: Podání DPH
summary: Jak z živého výpočtu udělat podání, vyrobit soubor pro daňový portál a mít trvalý záznam toho, co jsi za období odevzdal.
keywords: [zaúčtovat přiznání, zaúčtování DPH, účetní doklad přiznání, odvod DPH, nadměrný odpočet, správce daně, závazek vůči finančnímu úřadu, účet 343, vypořádání DPH, podací údaje, profil podatele, finanční úřad, územní pracoviště, kdo podává přiznání, sestavil, podepisující osoba, zástupce, datová schránka, podání DPH, podat přiznání, odevzdat přiznání, řádné podání, opravné podání, dodatečné přiznání, následné hlášení, dodatečné podání, změna daňové povinnosti, řádek 66, zaokrouhlení na koruny, sestavit podání, přepočítat podání, co jsem podal, historie podání, datum podání, datum zjištění důvodů, rozdíly proti předchozímu podání, podané hodnoty, XML pro daňový portál, soubor pro EPO, vytvořit soubory, opis přiznání, obsah podání, hlavička podání, načíst hlavičku z profilu, elektronické podání, importované podání, import ze starého Shipardu, podání ze starého systému, čip Import, rozdíly importu, původ podání]
related: [uctarna/dph-zive-vystupy.md, uctarna/uzamceni-obdobi.md, co-dnes-nejde.md]
---

# Podání DPH

Živé výstupy DPH se počítají vždy znovu, takže se mění pokaždé, když
opravíš doklad. **Podání** je opak: uloží obsah období tak, jak vypadal
v okamžiku sestavení, a už se nemění. Díky tomu máš i po roce jasno, co
jsi finančnímu úřadu skutečně odevzdal — a když se doklady později změní,
Shipard ti ukáže přesně které.

Seznam podání je v sekci **Účtárna** → **Podání DPH**.

## Kdy to potřebuješ

- Odevzdal jsi přiznání nebo hlášení a chceš mít v Shipardu záznam, co
  v něm bylo.
- Po odevzdání se něco změnilo a potřebuješ vědět, jaký je rozdíl proti
  tomu, co už je podané.
- Podáváš opravné, dodatečné nebo následné podání a potřebuješ do něj
  správná čísla.

## Postup

1. V **Účtárna → Daňová tvrzení** vyber tvrzení, za které podáváš
   (období přiznání, kontrolního nebo souhrnného hlášení).
2. V pravém panelu klikni na **Sestavit podání**.
3. Zkontroluj **Druh podání** — nabízejí se jen druhy, které pro daný
   výstup existují (viz níže). U prvního podání za období je to **Řádné**.
4. Ulož. Shipard rovnou sestaví obsah podání z dokladů ve stavu
   **V pořádku** a spočítá **podané hodnoty** (zaokrouhlené).
5. Zkontroluj podání v **Účtárna → Podání DPH**: záložka **Výstupní
   řádky** ukazuje vedle sebe podané a přesné hodnoty.
6. Až výstup skutečně odevzdáš, otevři podání a zvol **Podat**. Doplní se
   **datum podání** (dnešní, můžeš ho před podáním přepsat).

Podání ve stavu **Sestaveno** je koncept: pořád ho můžeš přepsat, změnit
druh nebo zrušit. Když se mezitím opravily doklady, akce **Přepočítat**
v detailu podání sestaví obsah znovu z aktuálních dat.

## Druhy podání

Které druhy jde použít, plyne ze zákona a liší se podle výstupu:

| Výstup | Druhy podání |
|---|---|
| Přiznání k DPH | Řádné · Opravné · **Dodatečné** |
| Kontrolní hlášení | Řádné · Opravné · **Následné** |
| Souhrnné hlášení | Řádné · **Následné** |

- **Řádné** je první podání za období.
- **Opravné** podáváš ještě ve lhůtě — celý obsah nahradí to předchozí.
- **Dodatečné** (jen přiznání) podáváš po lhůtě a vykazuje se v něm
  **jen rozdíl** proti tomu, co už je podané. Vlastní daň ani nadměrný
  odpočet se v něm nevyplňují — místo nich je na řádku 66 **změna daňové
  povinnosti**. Shipard rozdíl spočítá sám.
- **Následné** (hlášení) podáváš po lhůtě a obsahuje celý obsah znovu.
  Vyžaduje **datum zjištění důvodů** — den, kdy jsi zjistil, že je potřeba
  podat znovu.

Řádné podání je za období jen jedno: dokud za tvrzení nic podaného není,
nabízí se řádné, potom už jen navazující druhy. Rozpracované podání může
být za jedno tvrzení nejvýš jedno — nejdřív ho podej, nebo zruš.

## Podací údaje na registraci k DPH

Aby z podání mohl jednou vzniknout soubor pro daňový portál, potřebuje
Shipard vědět **kdo podává, komu a kdo výstup sestavil**. Tyhle údaje se
nevyplňují u každého podání — zadáváš je jednou v **Nastavení → Účetnictví
→ Registrace DPH**, kde má registrace záložku **Podací údaje**:

- **Daňový subjekt** — právnická nebo fyzická osoba, kód hlavní činnosti
  (NACE).
- **Finanční úřad** a **územní pracoviště**, kam podání patří.
- **Adresa** a **kontakt** (telefon, e-mail, ID datové schránky).
- **Oprávněná osoba** — kdo za firmu jedná, a jaký k ní má vztah.
- **Sestavil** — kdo výstup připravil (jméno a telefon na dotazy úřadu).
- **Podepisující osoba** — jen když podání podepisuje zástupce
  (daňový poradce, zmocněnec); nechej prázdné, když podepisuješ sám.

Vyplnit je můžeš kdykoli — registrace k DPH se uloží i bez nich. Bez nich
ale **nevznikne soubor pro daňový portál**: Shipard řekne, co konkrétně
chybí, a ukáže to u příslušného pole. Hodnoty se dají opsat z posledního
přiznání, které jsi podal ve svém dnešním programu.

Údaje se ukládají k té registraci, ke které patří — firma s víc
registracemi (víc zemí) má každou zvlášť.

## Soubory pro daňový portál

Z podání Shipard vyrobí **soubor XML pro daňový portál** (EPO) a k němu
dvě PDF: **opis** (co v podání je, čitelně) a **obsah** (ze kterých
dokladů se čísla poskládala).

Vzniknou samy, když podání **podáš** — a kdykoli předtím si je můžeš
vyrobit akcí **Vytvořit soubory** v detailu podání, třeba ke kontrole.
Najdeš je v záložce **Přílohy** u podání, odkud se stahují.

Než soubor vznikne, Shipard zkontroluje, že v něm bude všechno, co úřad
vyžaduje. Když něco chybí, **nevytvoří nic** a napíše, které pole
doplnit — třeba obchodní jméno, finanční úřad nebo kód činnosti. Tatáž
kontrola běží i při podání, takže se to nedozvíš až u portálu.

Pořadí je tedy: sestavit → zkontrolovat čísla → (Vytvořit soubory) →
Podat → odevzdat soubor na portálu nebo datovou schránkou. **Samotné
odeslání dělá člověk** — Shipard soubor nikam neposílá.

### Hlavička podání

Údaje, které jdou do hlavičky souboru, má každé podání vlastní: záložka
**Hlavička** ve formuláři podání. Předvyplní se z **Podacích údajů**
registrace a z tvojí firmy, takže obvykle není co upravovat — hodí se to,
když se pro jedno konkrétní podání něco liší (jiný kód činnosti,
podepisující osoba, kód zdaňovacího období následujícího roku).

Sada polí odpovídá formuláři: přiznání jich má víc než hlášení. Změny
platí **jen pro to jedno podání**; trvale se údaje mění na registraci.

Když Podací údaje na registraci opravíš až po sestavení, koncept si je
sám nevezme — **Přepočítat** hlavičku nechává být, aby nepřepsal, co jsi
v ní změnil. Použij akci **Načíst hlavičku z profilu** v detailu podání:
naplní celou hlavičku znovu z Podacích údajů a z tvojí firmy, včetně polí,
která jsi upravil ručně. Funguje jen u podání ve stavu **Sestaveno**.

Po podání se hlavička, stejně jako celý obsah, už nemění — a soubory
podaného tvrzení nejde smazat ani přejmenovat. Přílohy k němu **přidat
můžeš**: potvrzení o přijetí z portálu tam patří.

## Podané a přesné hodnoty

Přiznání se odevzdává v celých korunách, a to po řádcích: **každý řádek
se zaokrouhlí zvlášť** a dopočtené řádky (odpočet celkem, daň na výstupu,
vlastní daň) se pak počítají už ze zaokrouhlených čísel. Proto se podaná
vlastní daň může od živého výpočtu lišit o jednotky korun — je to správně,
takhle to čeká i finanční úřad.

Podání si drží obě čísla: **podané** (co šlo na úřad) i **přesné** (na
haléře). Kontrolní hlášení se podává na haléře, souhrnné hlášení
zaokrouhluje hodnoty nahoru na celé koruny.

## Rozdíly proti předchozímu podání

U opravného, dodatečného i následného podání má detail záložku
**Rozdíly**: vypíše doklady, které se proti předchozímu podání
**přidaly, odebraly nebo změnily** — s rozdílem základu a daně. Když se
nezměnilo nic, řekne to. Tohle je nejrychlejší způsob, jak zjistit, proč
dodatečné přiznání vychází právě takhle.

## Zaúčtování přiznání

Podané přiznání k DPH je potřeba také **zaúčtovat**: daň na vstupu a na
výstupu, která se během období nasčítala na účtech DPH, se převede na
jeden závazek vůči finančnímu úřadu (odvod) nebo pohledávku (nadměrný
odpočet). Shipard to udělá za tebe:

1. V **Účtárna → Podání DPH** otevři podání ve stavu **Podáno**.
2. V pravém panelu klikni na **Zaúčtovat**.
3. Otevře se nový **účetní doklad** ve stavu **Koncept**: řádky s daní za
   jednotlivé druhy plnění, řádek odvodu nebo nadměrného odpočtu vůči
   správci daně (s variabilním a specifickým symbolem a splatností) a
   případné zaokrouhlení. Zkontroluj ho a přepni do stavu **V pořádku** —
   teprve tím se zaúčtuje.

V detailu podání pak vidíš řádek **Zaúčtování** s číslem dokladu a jeho
stavem a akci, která doklad otevře. Dodatečné nebo opravné podání zaúčtuje
**jen rozdíl** proti tomu, co už je podané a zaúčtované — nic se
nepřepisuje, doklady za období dávají dohromady poslední podaný stav.

Kdo je **správce daně**, si Shipard bere z registrace k DPH: v **Nastavení
→ Účetnictví → Registrace DPH** vyber v sekci **Správce daně** osobu
finančního úřadu z adresáře (pokud tam ještě není, založ ji v Osobách).
Jde to doplnit i u potvrzené registrace, bez opravy. Bez správce daně
doklad vznikne také, jen řádek odvodu nemá partnera — Shipard na to
upozorní a úhradu úřadu pak nepůjde spárovat.

Co když se něco nepovede:

- **Podání už má účetní doklad** — Shipard nezaloží druhý. Když je doklad
  špatně, stornuj ho a klikni na **Zaúčtovat znovu**.
- **Chybí účet** (odvod DPH, nadměrný odpočet, DPH bez nároku na odpočet,
  zaokrouhlení) — doklad nevznikne a Shipard řekne který. Doplň ho do
  účtového rozvrhu a akci zopakuj.
- **Měsíc konce období je uzamčený** — doklad má datum účtování na konci
  období, do zamčeného měsíce ho nejde založit. Nejdřív zaúčtuj, pak
  zamykej měsíc; jinak měsíc dočasně odemkni.
- **Víc řad účetních dokladů** — Shipard nevybere první; správce systému
  určí, do které řady přiznání patří.

## Importovaná podání

Podání, která jsi odevzdal ještě ze starého Shipardu (nebo z jiného
systému), se do Shipardu přenášejí importem — v seznamu mají čip
**Import** a v přehledu řádek **Původ: Importováno ze starého Shipardu**.
Chovají se jako každé podané podání: dodatečné za stejné období se
sestaví jako rozdíl proti nim, zůstatky DPH se počítají i s jejich
účetním dokladem a období jde uzamknout.

Importované podání ale vzniklo jinak než sestavené:

- **Obsah se sestavil z dnešních dokladů, podané hodnoty jsou z původního
  souboru.** Když se obojí liší (starý systém zaokrouhlil jinak, doklad se
  od té doby změnil), ukáže to záložka **Rozdíly importu** — řádek, co
  Shipard sestavil dnes a co bylo skutečně podáno. Je to historický
  záznam, ne chyba k opravě.
- **Soubory jsou původní.** V záložce Přílohy je XML a opisy, které
  odešly na úřad; Shipard k nim nový soubor negeneruje a po podání je
  chrání stejně jako svoje.
- **Přepočítat, Načíst hlavičku z profilu ani Vytvořit soubory u něj
  nenajdeš** — nebylo by co přepočítat proti ničemu. **Zaúčtovat** funguje,
  když k podání nepřišel účetní doklad ze starého systému.

Import spouští správce z konzole nebo migrační nástroj; ty v aplikaci
nic nastavovat nemusíš.

## Na co si dát pozor

- **Podané podání už nezměníš ani nesmažeš.** Doplnit k němu můžeš jen
  poznámku. Oprava se nedělá editací, ale novým podáním jiného druhu —
  přesně jako u úřadu.
- **Tvrzení s podáním nejde zrušit** a tvrzení s už **podaným** podáním
  nejde ani **změnit rozsah období**: podaný obsah odpovídá období, za
  které se sestavil. Když je rozsah špatně, řeší se to novým podáním, ne
  přepsáním období.
- **Prázdné podání je legitimní.** Kontrolní hlášení se podává i za měsíc
  bez dokladů; Shipard takové podání označí jako prázdné.
- **Podání sestavíš jen z dokladů ve stavu V pořádku.** Koncept ani doklad
  v opravě v něm nebudou.
- **Když má doklad kód DPH, který Shipard neumí zařadit do výstupu**,
  sestavení se zastaví s chybou. Je to schválně: z podání nesmí nic tiše
  vypadnout. Oprav kód na dokladu a zkus to znovu.
- **Období se podáním nezamkne samo.** Po podání klikni v detailu podání
  na **Zaúčtovat** a pak na **Uzamknout tvrzení** — od té chvíle doklady
  s DPH za to období nejde měnit ani do něj dopisovat. Když na zámek
  zapomeneš, Shipard ti to za tři dny připomene. Podrobně
  [Uzamčení období](uzamceni-obdobi.md).
- **Přiznání se nezaúčtuje samo.** Dokud účetní doklad přiznání
  neuzavřeš, hlásí Shipard u tvrzení **Zůstatky DPH** — účty DPH za
  období nejsou vypořádané.
- **Soubor Shipard nikam neodešle.** Vyrobí ho a uloží k podání; na
  daňový portál nebo do datové schránky ho odesíláš ty.
- **Soubor je snímek okamžiku sestavení.** Když se doklady později změní,
  vygenerovaný soubor se nemění — proto z podaného tvrzení vyjde vždycky
  totéž, co odešlo na úřad.

## Souvisí

- [Živé výstupy DPH](dph-zive-vystupy.md)
- [Uzamčení období](uzamceni-obdobi.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
