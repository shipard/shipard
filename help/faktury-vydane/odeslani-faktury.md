---
title: Odeslání faktury e-mailem
summary: Jak fakturu, zálohovou fakturu, pokladní doklad nebo prodejku poslat odběrateli e-mailem přímo z Shipardu — komu odejde, co je v příloze a co dělat, když odběratel nahlásí jinou adresu.
keywords: [odeslat fakturu e-mailem, odeslání faktury, poslat fakturu odběrateli, poslat fakturu mailem, fakturu e-mailem, tlačítko Odeslat, odeslat doklad, odeslat zálohovou fakturu, odeslat proformu, odeslat pokladní doklad, odeslat prodejku, komu faktura odejde, příjemci faktury, kopie e-mailu, předmět e-mailu, text e-mailu, přílohy e-mailu, odeslat s dokladem, připojí se do PDF, faktura nepřišla, faktura nedorazila, poslat fakturu znovu, nová adresa odběratele, změna e-mailu odběratele, špatná adresa, žádný příjemce, chybí adresa odesílatele, ve frontě, neodesláno, jazyk e-mailu]
related: [faktury-vydane/tisk-faktury.md, faktury-vydane/texty-na-tiscich.md, faktury-vydane/odesilatel-faktur.md, osoby/kontakty-a-ucely.md, posta/odeslana-posta.md, faktury-vydane/vystaveni-faktury.md, co-dnes-nejde.md]
---

# Odeslání faktury e-mailem

Hotový doklad pošleš odběrateli přímo z Shipardu: v příloze e-mailu je PDF
dokladu a Shipard si pamatuje, co, komu a kdy odešlo. Stejně se posílá
faktura vydaná, zálohová faktura, pokladní doklad i prodejka.

## Kdy to potřebuješ

Doklad je ve stavu **V pořádku** a má ho dostat odběratel. Nebo ti
odběratel napsal, že mu faktura nepřišla, a chceš mu ji poslat znovu.

## Postup

1. **Otevři Prodej → Faktury vydané** a v seznamu klikni na doklad. Vpravo
   se ukáže jeho detail.

2. **Dej Odeslat.** Tlačítko je nahoře v detailu, vedle **Tisk**. Otevře se
   okno **Odeslat e-mailem** s připravenou zprávou.

3. **Zkontroluj, komu zpráva odejde.** V řádku **Komu** jsou adresy
   odběratele a pod každou je napsané, odkud se vzala — třeba *Kontakt
   Účtárna — Faktury a daňové doklady* nebo *E-mail osoby*. Adresu odebereš
   křížkem, další přidáš do pole **Přidat adresu** a tlačítkem **Přidat**.
   Stejně funguje řádek **Kopie**.

4. **Zkontroluj text.** **Předmět** a **Text** jsou předvyplněné v jazyce
   dokladu a můžeš je přepsat. Jiný jazyk zvolíš ve výběru **Jazyk** —
   změní se předmět, text i PDF v příloze. Když ti výchozí znění
   nevyhovuje trvale, nastav si vlastní — viz
   [Texty na tiscích](texty-na-tiscich.md).

5. **Zkontroluj přílohy.** První je vždy PDF dokladu — tlačítkem **Náhled**
   si ho prohlédneš. Pod ním jsou přílohy dokladu; zaškrtnuté jsou ty, které
   mají zapnuté **Odeslat s dokladem**. Zaškrtnutí můžeš pro tuhle zprávu
   změnit.

6. **Dej Odeslat.** Okno ukáže výsledek: **Odesláno**, nebo **Ve frontě**,
   když se zprávu nepodařilo doručit hned — Shipard to pak zkouší sám
   znovu.

Odeslaná zpráva je od té chvíle v detailu dokladu v sekci **Odeslaná
pošta** — s datem, příjemci, stavem a náhledem toho, co odešlo.

## Komu zpráva odejde

Shipard hledá adresy u odběratele v **Osobách**, vždy v tu chvíli, kdy
odesíláš:

1. **Kontakty osoby**, které mají e-mail a v poli **Účely odesílání**
   zaškrtnuté **Faktury a daňové doklady**. Dostanou to všechny takové
   kontakty.
2. Když žádný takový kontakt není, **E-mail** osoby.
3. Když není ani ten, okno ohlásí, že zpráva nemá příjemce — adresu doplň
   ručně do **Komu**, nebo ji nejdřív zapiš k osobě.

Jak kontakty nastavit, je v
[Kontakty a účely odesílání](../osoby/kontakty-a-ucely.md).

## Odběratel hlásí, že faktura nepřišla

**Adresa je správně, jen zpráva zapadla.** V detailu dokladu v sekci
**Odeslaná pošta** klikni na zprávu a ve formuláři dej **Odeslat znovu** —
odejde stejná zpráva na stejné adresy se stejnými přílohami.

**Odběratel má novou adresu.** Oprav ji v **Osobách** (e-mail osoby nebo
kontakt) a u dokladu dej znovu **Odeslat**. Vznikne nová zpráva na novou
adresu; ta původní zůstane v historii, takže je vidět, kam se posílalo
dřív.

## Na co narazíš

**Tlačítko Odeslat u dokladu není.** Odeslat jde jen doklad ve stavu
**V pořádku** nebo **Storno** — stejně jako tisk. Koncept odeslat nejde.

**Okno hlásí, že chybí adresa odesílatele.** Zdroj dat nemá nastavené,
odkud pošta odchází — viz
[Z jaké adresy faktury odcházejí](odesilatel-faktur.md).

**U přílohy je štítek „připojí se do PDF“.** Odběratel má v **Osobách** na
tabu **Nastavení** zapnuté **Přílohy dokladu připojit do PDF dokladu**: PDF
přílohy se pak přidají za doklad do jednoho souboru. Ostatní soubory
(obrázky, tabulky) jdou vždy jako samostatné přílohy.

**Výsledek je „Ve frontě“.** Zpráva je vytvořená, jen ji poštovní server
zatím nepřevzal. Shipard to zkouší opakovaně několik hodin; stav uvidíš
u dokladu v sekci **Odeslaná pošta**. Když skončí jako **Neodesláno**,
otevři zprávu — je u ní důvod — a po nápravě dej **Odeslat znovu**.

**Změna jazyka přepíše text.** Když jsi předmět nebo text upravil a pak
změníš **Jazyk**, Shipard se zeptá, jestli je má přepsat. Příjemci a výběr
příloh zůstanou.

**Každé Odeslat vytvoří novou zprávu** s nově vyrobeným PDF. Když doklad
mezitím opravíš, další odeslání už nese opravenou podobu; dřívější zprávy
se nemění.

## Souvisí

- [Tisk faktury](tisk-faktury.md) — PDF dokladu bez odeslání
- [Kontakty a účely odesílání](../osoby/kontakty-a-ucely.md) — komu faktury chodí
- [Z jaké adresy faktury odcházejí](odesilatel-faktur.md) — odesílatel a jeho jméno
- [Odeslaná pošta](../posta/odeslana-posta.md) — přehled všeho, co odešlo
- [Co Shipard dnes neumí](../co-dnes-nejde.md) — hromadné odesílání, vlastní texty
