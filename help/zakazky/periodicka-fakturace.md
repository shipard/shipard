---
title: Periodická fakturace
summary: Jak nastavit periodické zakázce fakturační předpis (druh, zakázka, řádky s platností), kdy a jak vznikají koncepty faktur, co s nimi udělat a jak období přegenerovat, obnovit nebo dovystavit.
keywords: [periodická fakturace, opakovaná fakturace, pravidelná fakturace, fakturace nájmu, nájemné, nájem, paušál, paušální faktura, měsíční faktura, čtvrtletní faktura, roční faktura, roční záloha, zálohová faktura periodicky, fakturační předpis, předpis fakturace, řádky zakázky, řádek předpisu, platnost řádku, změna ceny od data, nová cena od, periodicita, fakturovat od, text dokladu, období, {období}, proměnná období, pevný variabilní symbol, trvalý příkaz, kdy vznikne faktura, faktura na počátku období, faktura na konci období, koncept faktury ke kontrole, koncepty ke kontrole, periodická faktura nevznikla, smazaný koncept, zastavené období, obnovit období, přegenerovat koncept, přegenerovat fakturu, vystavit dlužná období, dlužná období, dohánění, čeká na podklady, přispěvatel obsahu, přefakturace spotřeby, záložka fakturace, výchozí hodnoty fakturace, typ dokladu na druhu, řada dokladů na druhu, splatnost na druhu]
related: [zakazky/zakazky.md, zakazky/nastaveni-zakazek.md, faktury-vydane/vystaveni-faktury.md, co-dnes-nejde.md]
---

# Periodická fakturace

Periodická zakázka je smlouva, ze které Shipard každé období sám vystaví
fakturu (nebo zálohovou fakturu). Ty nastavíš **co** a **jak často**, běh
jednou denně vystaví koncepty za splatná období a ty je zkontroluješ
a potvrdíš. Předpis a vystavená období najdeš na zakázce v **Zakázky →
Zakázky**, záložka **Fakturace** v detailu.

## Kdy to potřebuješ

Fakturuješ nájemné, paušální služby, servisní smlouvy nebo roční zálohy —
stejnému zákazníkovi, stejné řádky, každý měsíc, čtvrtletí, pololetí nebo
rok. Nechceš faktury zakládat ručně a zapomínat na ně.

## Postup

**Druh zakázky — výchozí hodnoty fakturace**

1. V **Nastavení aplikace → Zakázky → Druhy zakázek** otevři druh
   s typem **Periodická** (nebo založ nový, viz
   [Nastavení zakázek](nastaveni-zakazek.md)). Sekce **Výchozí hodnoty
   fakturace** platí pro všechny zakázky druhu.
2. Vyber **Typ dokladu** — **Faktura vydaná**, nebo **Zálohová faktura
   vydaná** (roční zálohy) — a **Řadu dokladů** stejného typu. Obojí je
   povinné pro **V pořádku**.
3. Doplň **Splatnost (dny)** (prázdné = 14), **Fakturace** — **Na počátku
   období**, nebo **Na konci období** — **Režim DPH** (ceny na řádcích
   **Ze základu**, nebo **Z ceny celkem**), **Způsob platby** a případně
   **Bankovní účet**. Prázdné pole = výchozí hodnota jako u ručně
   založeného dokladu.

**Zakázka — předpis**

1. Založ periodickou zakázku (viz [Zakázky](zakazky.md)): **Zákazník**,
   **Měna**, **Zahájení**, případně **Středisko** a **Variabilní symbol**
   — pevný VS ponesou všechny faktury zakázky, hodí se pro trvalé
   příkazy.
2. V sekci **Fakturace** vyber **Periodicitu** (**Měsíčně**,
   **Čtvrtletně**, **Pololetně**, **Ročně** — období jsou kalendářní)
   a **Fakturovat od**: první fakturované období je to, které obsahuje
   tohle datum (prázdné = zahájení). **Text dokladu** je text faktury;
   napiš do něj `{období}`, Shipard dosadí *říjen 2026*, *3. čtvrtletí
   2026*, *2. pololetí 2026* nebo *2026* v jazyce zákazníka. Prázdný text
   = název zakázky a období.
3. V sekci **Přepisy výchozích hodnot druhu** nech pole prázdná, když má
   zakázka platit hodnoty druhu — prázdné pole ukazuje, co z druhu
   platí (*Z druhu: …*). Vyplň jen to, co se u téhle zakázky liší.
4. Ulož zakázku a na záložce **Řádky** přidej řádky předpisu: **Popis**
   (nebo vyber **Položku**, která popis a cenu předvyplní), **Množství**,
   **Jednotku**, **Cenu/jednotku**, **Kód DPH** a případně **Pohyb**
   (prázdný = výchozí pohyb faktury). **Platnost od** a **Platnost do**
   nech prázdné u stálých řádků.
5. Dej **V pořádku**. Potvrdit jde jen zakázka s periodicitou, typem
   dokladu, řadou (ze zakázky nebo z druhu) a aspoň jedním řádkem.

**Změna ceny od data**

Řádky zakázky **V pořádku** jsou jen ke čtení — stejně jako celá
zakázka. Úprava jde přes **V opravě**:

1. Dej zakázku **V opravě** (tlačítko **Opravit**). Zakázku V opravě
   noční běh přeskočí; po návratu do V pořádku dožene, co bylo mezitím
   splatné.
2. Starému řádku vyplň **Platnost do** (poslední den staré ceny).
3. Přidej nový řádek s novou cenou a **Platnost od** (první den nové
   ceny).
4. Dej zakázku zpět **V pořádku**.
5. Faktura za období použije řádky platné k datu vystavení (DUZP) — za
   říjen novou cenu, za září ještě starou. Koncept, který už vznikl se
   starou cenou, na záložce **Fakturace** **Přegeneruj**.

**Kdy a jak faktura vznikne**

- Běh jede každou noc (03:17) a vystaví **koncept** za každé splatné
  období: při fakturaci **Na počátku období** první den období, **Na
  konci období** poslední den. Datum vystavení i DUZP je tento den,
  splatnost = plus dny z předpisu, na faktuře je **Období**, text
  s obdobím, VS, zakázka a středisko.
- Koncepty najdeš ve **Faktury vydané** (filtr **Zdroj** = **Periodická
  fakturace**) a na Dashboardu jako jednu kartu *N konceptů z periodické
  fakturace ke kontrole*. Zkontroluj je a dej **V pořádku** — pak je
  pošli jako každou jinou fakturu.
- Zakázka **V opravě** se přeskočí; po návratu do **V pořádku** běh
  dožene všechna splatná období. Období začínající po **Ukončení** se
  nevystaví, období, do kterého ukončení spadá, se vystaví celé.

**Záložka Fakturace na zakázce**

- Nahoře **Příští splatné období** a předpis, pod tím období se stavem
  (**Naplánováno**, **Čeká na podklady**, **Vystaveno**, **Zastaveno**),
  dokladem (odkaz otevře detail), částkou a zprávou, proč se období
  nevystavilo.
- **Vystavit dlužná období** vystaví hned všechna splatná období zakázky
  — i když jich je víc než tři (viz níže).
- **Přegenerovat** (nabídka období s konceptem) sestaví koncept znovu
  podle aktuální zakázky — stejný doklad, nové řádky. Ruční úpravy
  konceptu se ztratí, proto se Shipard ptá.
- **Obnovit** (nabídka zastavených období) vrátí období do plánu a hned
  vystaví nový koncept.

## Na co narazíš

**Smazaný koncept období zastaví.** Když koncept nechceš, smaž ho —
období je v záložce **Fakturace** jako **Zastaveno** a běh ho znovu
nevystaví. Rozmyslíš-li si to, dej **Obnovit**, nebo koncept vrať z koše.

**Víc než tři dlužná období najednou běh nevystaví.** Zakázka
s **Fakturovat od** daleko v minulosti (nebo dlouho **V opravě**) by
vystavila hromadu faktur naráz. Běh se zastaví, na Dashboardu se objeví
upozornění; zkontroluj **Fakturovat od** a dej **Vystavit dlužná období**.

**Období se nepodařilo vystavit.** Chybí řada dokladů, k datu vystavení
neplatí žádný řádek, zákazník nemá adresu… Období zůstane **Naplánováno**
se zprávou, Dashboard ukáže upozornění na zakázku a další běh to zkusí
znovu. Oprav předpis a buď počkej na noc, nebo dej **Vystavit dlužná
období**.

**Koncept má datum vystavení v minulosti.** To je záměr — datum vystavení
a DUZP určuje předpis (počátek nebo konec období), ne den, kdy koncept
potvrdíš.

**Čeká na podklady.** Řádek předpisu s **Přispěvatelem obsahu** (např.
přefakturace spotřeby) doplní obsah jiný modul; dokud podklady nemá,
koncept má takový řádek s nulovým množstvím a období **Čeká na
podklady**. Jakmile podklady přijdou, běh koncept sám přegeneruje — ale
jen když jsi ho ručně neupravil; jinak tě upozornění vyzve dát
**Přegenerovat**. Lhůtu, po které se čekání hlásí, nastavíš v **Nastavení
aplikace → Zakázky → Periodická fakturace** (**Čekání na podklady**,
prázdné = 10 dní). Zatím není žádný přispěvatel k dispozici.

**Koncept vzniká vždy jako koncept.** Automatické potvrzení ani odeslání
faktury zatím není, viz [Co Shipard dnes neumí](../co-dnes-nejde.md).

## Souvisí

- [Zakázky](zakazky.md)
- [Nastavení zakázek](nastaveni-zakazek.md)
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
