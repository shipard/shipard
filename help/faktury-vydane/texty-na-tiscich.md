---
title: Texty na tiscích
summary: Jak na faktury a další doklady přidat vlastní text — oznámení na pár dní nebo trvalý text na konci dokladu — a jak si přepsat předmět a text e-mailu, kterým doklad odchází.
keywords: [texty na tiscích, text na faktuře, vlastní text na dokladu, přidat text na fakturu, text v patičce faktury, text na konci faktury, poznámka na všech fakturách, obchodní podmínky na faktuře, poděkování na faktuře, oznámení na faktuře, dovolená na faktuře, text pod řádky, text nad řádky, vlastní text e-mailu, změnit text e-mailu, vlastní předmět e-mailu, šablona e-mailu, text průvodního e-mailu, proměnné v textu, číslo dokladu v textu, datum splatnosti v textu, tučný text na faktuře, tučné písmo, Markdown, platnost textu, text jen na týden, text jen pro jeden typ dokladu, text jen pro jednu řadu, text jen anglicky, text se nevytiskl, text se netiskne, text nejde uložit, text nejde použít]
related: [faktury-vydane/tisk-faktury.md, faktury-vydane/odeslani-faktury.md, faktury-vydane/vzhled-tisku.md, co-dnes-nejde.md]
---

# Texty na tiscích

Na tištěné doklady si můžeš přidat vlastní text — krátké oznámení, které
platí pár dní, nebo trvalý text na konci každé faktury. Stejně si přepíšeš
předmět a text e-mailu, kterým doklad odchází odběrateli.

## Kdy to potřebuješ

- Chceš odběratelům oznámit něco na omezenou dobu — „mezi svátky máme
  zavřeno“.
- Chceš mít na každém dokladu stejný text: poděkování, kontakt na
  fakturaci, upozornění na změnu účtu.
- Nevyhovuje ti výchozí text e-mailu s dokladem.

## Postup

1. **Otevři Nastavení aplikace → Aplikace → Texty na tiscích** a dej
   **Přidat**.

2. **Vyplň Název.** Je jen pro tebe, abys text v seznamu poznal — na
   doklad se netiskne.

3. **Vyber Umístění** — kam se text vloží:

   | Umístění | Kde na dokladu |
   |---|---|
   | **Začátek dokumentu** | nahoře na první straně, pod hlavičkou |
   | **Před řádky** | nad tabulkou řádků dokladu |
   | **Za řádky** | pod tabulkou řádků, nad součty |
   | **Konec dokumentu** | na konci dokladu, pod poznámkami |
   | **Předmět e-mailu** | nahradí výchozí předmět e-mailu s dokladem |
   | **Text e-mailu** | nahradí výchozí text e-mailu s dokladem |

4. **Urči, kde se text použije.** Když nic nevyplníš, platí pro všechny
   doklady. Omezit ho můžeš polem **Tisky** (jen faktura, jen pokladní
   doklad…), **Typy dokladů**, **Číselné řady** a **Jazyk** — text
   s jazykem se použije jen na dokladu tištěném v tom jazyce.

5. **Nastav platnost,** když má text platit jen nějakou dobu: **Platí od**
   a **Platí do**. Prázdné pole znamená bez omezení. Rozhoduje den, kdy
   doklad tiskneš nebo odesíláš — ne datum na dokladu.

6. **Napiš Text.** Údaj z dokladu (číslo, splatnost, částku) do něj vložíš
   kliknutím v nabídce **Proměnné** pod polem — na místě kurzoru se objeví
   značka ve složených závorkách a při tisku ji nahradí hodnota z dokladu.

7. **Dej V pořádku.** Tiskne se jen text v tomto stavu; **Koncept** si
   můžeš nechat rozpracovaný.

V textu na doklad jde použít jednoduché formátování:

| Napíšeš | Vytiskne se |
|---|---|
| `**důležité**` | **důležité** (tučně) |
| `*poznámka*` | *poznámka* (kurzívou) |
| řádky začínající pomlčkou a mezerou | seznam s odrážkami |
| prázdný řádek | nový odstavec |

## Příklady

**Oznámení na pár dní.** Umístění **Za řádky**, **Platí od** 18. 12.,
**Platí do** 31. 12., text:

```
Od 23. prosince do 1. ledna máme **zavřeno**. Objednávky vyřídíme od 2. ledna.
```

Text se objeví na každém dokladu vytištěném v těch dnech a pak sám zmizí.

**Trvalý text na konci dokladu.** Umístění **Konec dokumentu**, platnost
prázdná, text třeba:

```
Děkujeme, že nakupujete u nás. S dotazy k faktuře pište na fakturace@example.com.
```

**Vlastní e-mail s dokladem.** Založ dva texty. První s umístěním **Předmět
e-mailu**:

```
Faktura {{ data.document.number }}
```

Druhý s umístěním **Text e-mailu**:

```
Dobrý den,

v příloze posíláme fakturu {{ data.document.number }} na částku
{{ data.payment.amountToPay|money(data.payment.currency) }}
se splatností {{ data.dates.due|date }}.

S pozdravem
fakturační oddělení
```

Značky nemusíš psát ručně — vloží je nabídka **Proměnné**. V okně
**Odeslat e-mailem** pak budou **Předmět** a **Text** předvyplněné podle
tebe a pořád je můžeš před odesláním upravit.

## Na co narazíš

**Text se na doklad nevytiskl.** V seznamu **Texty na tiscích** má text,
který se dnes tiskne, štítek **Platí dnes**. Když ho nemá, není ve stavu
**V pořádku**, nebo je dnešek mimo jeho platnost. Když ho má a na dokladu
přesto chybí, podívej se na omezení: **Tisky**, **Typy dokladů**,
**Číselné řady** a **Jazyk** musí odpovídat dokladu, který tiskneš.

**Nad náhledem tisku je žluté upozornění, že se text nevytiskl.** V textu
je značka pro údaj, který doklad nemá — nejčastěji překlep ve značce
napsané ručně. Doklad je v pořádku a jde použít, jen je bez toho textu.
Text otevři, dej **Opravit**, značku vlož znovu z nabídky **Proměnné**
a dej **V pořádku**.

**Uložení hlásí „Text nejde použít“.** Složené závorky `{{ }}` a `{% %}`
jsou vyhrazené pro značky. Zkontroluj, že je každá značka uzavřená. Kromě
vložení údaje jde použít jen podmínka, například
`{% if data.payment.amountToPay > 0 %}Prosíme o úhradu.{% endif %}` —
cykly ani nic dalšího ne.

**V e-mailu jsou hvězdičky místo tučného písma.** E-mail je prostý text,
formátování v něm nefunguje. Hvězdičky z textu smaž.

**Odkaz se vytiskl jako text s adresou v závorce.** Na papír se nedá
kliknout, proto se adresa odkazu vypíše. Obrázky ani HTML značky do textu
vložit nejdou — obrázek se nahradí svým popiskem, značky se vytisknou tak,
jak jsou napsané.

**Na jednom místě se mi sešlo víc textů.** Vytisknou se všechny, které
platí, pod sebou. Pořadí určuje pole **Pořadí** — nižší číslo je výš.

**Pole Typy dokladů a Číselné řady nevidím.** Nabízejí se jen pro tisky
dokladů; když v poli **Tisky** vybereš tisk, který typ a řadu nemá, pole
zmizí.

**Vlastní text e-mailu už nechci.** Text otevři a dej **Ukončit platnost**
(nebo **Smazat**). E-mail se vrátí k výchozímu předmětu a textu.

**Text je i na starších dokladech.** Platnost se počítá ke dni tisku. Když
dnes vytiskneš fakturu z loňska, bude na ní text, který platí dnes.

**Kontace vlastní texty nemá.** Je to interní tisk.

## Souvisí

- [Tisk faktury](tisk-faktury.md)
- [Odeslání faktury e-mailem](odeslani-faktury.md)
- [Vzhled tištěných dokladů](vzhled-tisku.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
