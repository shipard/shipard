---
title: Co Shipard dnes neumí
summary: Poctivý seznam chybějících funkcí a míst, kde ještě nemusí souhlasit čísla.
keywords: [neumí, nejde, chybí, omezení, alfa, přiznání k DPH, kontrolní hlášení, záloha, nefunguje]
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

## Vydané faktury: nic pro odběratele

**Vydanou fakturu nevytiskneš, neuložíš do PDF ani neodešleš e-mailem.**
Vystavit a zaúčtovat ji jde celou — viz
[Vystavení faktury](faktury-vydane/vystaveni-faktury.md) — ale doklad, který
drží v ruce odběratel, musíš zatím vyrobit jinde. U vydaných faktur je tak
Shipard dneska evidence, ne fakturační nástroj.

**Zálohovou fakturu (proformu) taky nevytiskneš** — platí pro ni totéž co pro
fakturu. **Fakturu z proformy nevystavíš jedním klikem**: až zálohu dostaneš,
vystav vydanou fakturu ručně a zálohu na ní odečti řádkem *Odpočet přijaté
zálohy* s variabilním symbolem proformy. **Daňový doklad k přijaté záloze**
Shipard zatím neumí — daň z přijaté zálohy tak přiznáš až fakturou.
**Potvrzená proforma se zatím nezaúčtuje** a její úhradu z banky k ní
saldokonto nespáruje; v přehledu účtování ji uvidíš s chybou, že chybí
účtovací předpis. Viz [Zálohová faktura](faktury-vydane/zalohova-faktura.md).

---

## Pokladna: bez knihy a bez účtenky

**Pokladní knihu Shipard zatím nevede.** Pokladní doklady a prodejky se
zaúčtují na účet pokladny, ale přehled zůstatku a pohybů pokladny za období,
počáteční stav ani inventura pokladny nejsou. Zůstatek dnes zjistíš jen
z účetního deníku nebo hlavní knihy na účtu pokladny.

**Pokladní doklad ani prodejku nevytiskneš.** Stejně jako u vydaných faktur
— zákazníkovi účtenku musíš dát z jiného zařízení.

**Vyúčtování od platební brány, terminálu nebo dopravce Shipard sám
nevytvoří.** Prodej kartou, přes bránu nebo na dobírku založí pohledávku
za plátcem (viz [Platba kartou, přes bránu a dobírkou](pokladna/platba-kartou-branou-dobirkou.md)),
ale souhrnné vyúčtování s poplatky, které tyhle pohledávky uzavře a
připraví připsání od brány k párování, zatím zadáváš ručně účetním
dokladem. Připsání od brány z bankovního výpisu se k dávce nepřiřadí samo.

---

## Kde ještě nemusí souhlasit čísla

Tohle je pro nás priorita číslo jedna a pracuje se na tom. Do té doby
u těchto případů **porovnej celkovou částku dokladu s originálem faktury**:

- **Faktury s jednotkovými cenami včetně DPH** (typicky drobný prodej,
  občerstvení). Daň se může spočítat dvakrát a celková částka pak vyjde
  vyšší než na faktuře.
- **Zaokrouhlení celkové částky** — dodělané, ale ještě neověřené na širší
  sadě faktur.
- **Reverse charge (samovyměření) v rekapitulaci DPH** — rozpis daně
  u těchto dokladů se opravuje.
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

Když dodavatel přiloží fakturu ve formátu **ISDOC**, AI se nepoužije vůbec
a data se převezmou přímo — je to přesnější. Vyplatí se o ISDOC dodavatele
požádat.

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
