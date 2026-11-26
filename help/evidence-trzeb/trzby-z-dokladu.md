---
title: Evidence tržeb — tržby z dokladů
summary: Které doklady se evidují jako tržba, kdy a jak se tržba odesílá správci daně, co znamenají stavy tržby a upozornění, jak nastavit evidenční jednotku na číselné řadě a co udělá storno nebo oprava dokladu.
keywords: [evidence tržeb, evidenci tržeb, EET, EET 2.0, tržba, tržby, tržbu, tržeb, odeslání tržby, odeslat tržbu, tržba se neodeslala, přerušený pokus, přerušeno, nepotvrzená tržba, potvrzení tržby, kód potvrzení, potvrzovací kód, POK, pořadové číslo tržby, odeslat znovu, tržba selhala, tržba čeká na odeslání, zahozeno, pojistka serveru, kontaktní platba, platba hotově, platba kartou, platební brána, platební bránou, prodejka, prodejky, pokladní doklad, faktura hotově, faktura kartou, storno tržby, stornovat doklad, oprava dokladu, záporná tržba, evidenční jednotka na řadě, číselná řada, kód řady, označení pokladny, pokladní zařízení, doklad nejde zaevidovat, doklad nejde smazat, 48 hodin, lhůta evidence]
related: [evidence-trzeb/registrace-a-certifikat.md, pokladna/prodejka.md, pokladna/pokladni-doklad.md, pokladna/platba-kartou-branou-dobirkou.md, co-dnes-nejde.md]
modules: [economy.salesRegistration]
---

# Evidence tržeb — tržby z dokladů

Když doklad s platbou na místě dostane stav **V pořádku**, Shipard z něj
udělá **tržbu** a pošle ji správci daně. Nemusíš pro to nic dělat — tahle
stránka říká, které doklady se evidují, co se děje, když odeslání nevyjde,
a co uvidíš v agendě **Evidence tržeb**.

## Kdy to potřebuješ

Máš zapnutý doplněk Evidence tržeb a registraci **V pořádku**
(viz [Registrace a certifikát](registrace-a-certifikat.md)) a chceš vědět,
co se s tvými prodejkami, pokladními doklady a fakturami děje — nebo řešíš
upozornění, že tržba není potvrzená.

## Postup

**Které doklady se evidují**

| Doklad | Kdy je to tržba |
|---|---|
| **Prodejka** | **Způsob platby** **Hotovost** nebo **Kartou** |
| **Faktura vydaná** | **Způsob platby** **Hotovost** nebo **Kartou** |
| **Pokladní doklad** — **Příjem** | jen řádky s pohybem prodeje (**Prodej služeb**, **Prodej zboží**, **Odpočet přijaté zálohy**, **Zdanění přijaté zálohy**), **Úhrada pohledávky** a **Přijatá záloha** |

Neevidují se doklady placené **Převodem**, **Dobírkou**, **Zápočtem**
a **Platební bránou**, pokladní doklad **Výdej** a u pokladního příjmu
řádky **Příjem z převodu peněz** a **Účetní položka**.

**Kartou** znamená terminál na místě. Platbu kartou přes internet (e-shop,
platební odkaz) zapiš způsobem platby **Platební bránou** — to není
kontaktní platba a do evidence nepatří. Víc v
[Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md).

**Co se stane po potvrzení dokladu**

1. Dáš dokladu **V pořádku**.
2. Shipard založí tržbu: částka je celková částka dokladu v korunách
   (u dokladu v cizí měně přepočtená kurzem dokladu, hotovost zaokrouhlená
   stejně jako na dokladu), čas tržby je okamžik potvrzení.
3. Tržbu hned odešle. Když to napoprvé nevyjde (služba neodpovídá, chybí
   certifikát), zkouší to sám znovu — po minutě, pěti, patnácti minutách
   a pak každou hodinu, dokud se to nepovede.

Tržby najdeš v sekci **Prodej → Evidence tržeb**. V detailu tržby je
záložka **Pokusy** se vším, co se s ní dělo, a tlačítko **Otevřít doklad**.

**Stavy tržby**

| Stav | Co znamená |
|---|---|
| **Čeká na odeslání** | Tržba je ve frontě — buď se ještě neposlala, nebo se čeká na další pokus |
| **Odesílá se** | Právě se posílá |
| **Potvrzeno** | Správce daně tržbu přijal; v detailu je **Kód potvrzení** |
| **Ověřeno** | Registrace je v režimu **Ověřovací** — zpráva prošla, ale nic se nezaevidovalo |
| **Zahozeno** | Server má zapnutou pojistku a nic neposílá (zkušební nebo vývojový server) |
| **Selhalo** | Správce daně tržbu odmítl. Sama se už neposílá |

Štítek **test** u tržby znamená potvrzení ze zkušebního prostředí —
povinnost se jím neplní.

**Storno a oprava dokladu**

- **Stornovat** doklad s tržbou pošle druhou tržbu se zápornou částkou.
  Má vlastní pořadové číslo — číslo dokladu s příponou `-2`.
- **Opravit** doklad a vrátit ho do **V pořádku** s jinou částkou pošle jen
  rozdíl (zase s příponou `-2`, `-3`, …). Oprava, která částku nezmění,
  neposílá nic.
- Změníš-li při opravě způsob platby na takový, který se neeviduje, pošle
  se záporná tržba na celou dosud evidovanou částku.

**Odeslat znovu**

U tržby ve stavu **Selhalo** nebo **Čeká na odeslání** je v detailu akce
**Odeslat znovu**. Použij ji, až odstraníš příčinu (v záložce **Pokusy**
je napsaná). Tržba se pošle se stejnými daty.

**Evidenční jednotka na číselné řadě**

Tržba se eviduje pod **výchozí evidenční jednotkou** registrace. Máš-li
víc provozoven, nastav jednotku na číselné řadě dokladů:

1. V **Nastavení aplikace → Účetnictví → Číselné řady dokladů** otevři řadu.
2. Na záložce **Evidence tržeb** vyber **Evidenční jednotka** a ulož.

Doklady té řady se pak evidují pod zvolenou jednotkou. **Kód řady** se
posílá jako označení pokladního zařízení: smí mít nejvýš 20 znaků a jen
písmena bez diakritiky, číslice, mezeru a znaky `. , : ; / # - _`.

## Na co narazíš

**Upozornění „Tržby čekají na potvrzení“.** Tržba není potvrzená déle než
hodinu. Shipard ji posílá dál sám; důvod najdeš v záložce **Pokusy**.
Nejčastěji chybí nebo vypršel certifikát registrace, nebo služba správce
daně neodpovídá.

**Upozornění „Tržby nezaevidované do 48 hodin“.** Tržby se mají evidovat
do 48 hodin. Odesílání nepřestává ani potom — povinnost trvá. Vyřeš
příčinu co nejdřív.

**Upozornění „Tržby odmítnuté službou“.** Tržba je ve stavu **Selhalo**.
Podívej se do záložky **Pokusy**, proč ji služba odmítla, a pak dej
**Odeslat znovu**.

**Pokus s výsledkem „Přerušeno“.** Odesílání se přerušilo dřív, než se
zapsal výsledek — zpráva odejít mohla i nemusela. Nic dělat nemusíš:
Shipard tržbu pošle znovu sám a výsledek najdeš v dalším pokusu.

**Upozornění „Doklad nejde zaevidovat jako tržbu“.** Doklad do evidence
patří, ale chybí nastavení: registrace nemá výchozí evidenční jednotku,
kód číselné řady nejde použít jako označení pokladny, nebo ve stejné době
platí dvě registrace. Oprav nastavení — tržba se pak založí sama a ponese
původní čas potvrzení dokladu.

**Doklad s tržbou nejde smazat.** Akce **Smazat** u něj skončí hláškou.
Doklad **Stornuj** — storno zaeviduje zápornou tržbu.

**Při ukládání řady „Kód řady … nejde použít jako označení pokladního
zařízení“.** Řada s evidenční jednotkou musí mít kód, který projde jako
označení pokladny. Změň **Kód řady**, nebo jednotku z řady odeber.

**Tržba je „Zahozeno“.** Pracuješ na zkušebním nebo vývojovém serveru,
kde pojistka nedovolí nic poslat. Tak to má být — na ostrém serveru se
tržby posílají.

**Doklad vystavený před začátkem registrace tržbu nedostane** — ani když
ho později opravíš nebo stornuješ. Totéž platí pro doklady převzaté
importem ze starého systému.

**Záložka Evidence tržeb u řady chybí.** Žádná registrace **V pořádku**
nemá evidenční jednotku.

**Doklad založený přes API tržbu odešle o chvilku později** (do minuty),
ne hned při uložení.

## Souvisí

- [Evidence tržeb — registrace a certifikát](registrace-a-certifikat.md)
- [Prodejka](../pokladna/prodejka.md)
- [Pokladní doklad](../pokladna/pokladni-doklad.md)
- [Platba kartou, přes bránu a dobírkou](../pokladna/platba-kartou-branou-dobirkou.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — co z evidence tržeb zatím chybí
