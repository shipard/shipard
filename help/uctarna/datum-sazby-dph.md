---
title: Datum sazby DPH
summary: Jak dostat na doklad jinou sazbu DPH, než platí k jeho datu — DPPD u přijaté faktury přes změnu sazby, vlastní datum dokladu a data na řádcích u závěrečné faktury s odpočtem záloh.
keywords: [datum sazby, datum sazby dph, sazby dph podle, sazba dph, sazbu dph, stará sazba, starou sazbu, jiná sazba, jinou sazbu, procento dph, dph procento, přepsat procento, změnit procento dph, změna sazby dph, 20 procent, 21 procent, 10 procent, druhá snížená, snížená sazba, dppd, datum povinnosti přiznat daň, vlastní datum dokladu, vlastní datum na řádcích, odpočet zálohy, odpočet záloh, závěrečná faktura, vyúčtování záloh, zálohy v různých sazbách, kód dph chybí v nabídce, kód dph nemá sazbu, k datu bez sazby, přelom roku]
related: [faktury-prijate/dokonceni-dokladu.md, faktury-vydane/vystaveni-faktury.md, faktury-vydane/opravny-doklad.md, pokladna/pokladni-doklad.md, co-dnes-nejde.md]
---

# Datum sazby DPH

Procento DPH na dokladu nezadáváš. Shipard ho vždy vezme z **Kódu DPH**
a z **data sazby** — a ty rozhoduješ jen o tom datu. Když má doklad nést
jinou sazbu než tu běžnou, zůstane na něm vidět proč: je u něj datum, ne
přepsané číslo.

## Kdy to potřebuješ

- Zadáváš **přijatou fakturu přes změnu sazby** — dodavatel plnil
  v prosinci starou sazbou, ty máš doklad s lednovým DUZP.
- Vystavuješ **závěrečnou fakturu s odpočtem záloh**, které byly zdaněné
  jinou sazbou než zbytek faktury.
- V nabídce **Kód DPH** chybí kód, který hledáš, nebo ti Shipard při
  uložení píše, že kód nemá k datu sazbu.

Na běžném dokladu neděláš nic: sazby se berou samy.

## Postup

**Přijatá faktura přes změnu sazby — vyplň DPPD**

1. Na hlavičce faktury přijaté vyplň **DPPD** dnem, kdy dodavatel plnil
   (na jeho faktuře je to DUZP). Tvoje **DUZP** nech podle toho, do kterého
   období si odpočet dáváš.
2. Ulož. Řádky i rekapitulace dostanou sazbu platnou k **DPPD**; nabídka
   **Kód DPH** na řádku ukazuje u každého kódu procento, takže to hned
   vidíš (třeba *Tuzemsko/Vstup/Základní — 20 %*).

Tohle stačí ve většině případů a nic dalšího nenastavuješ.

**Celý doklad k jinému datu — vlastní datum dokladu**

Použij, jen když DPPD nestačí — má třeba zůstat jiné kvůli kontrolnímu
hlášení.

1. Na záložce **Nastavení** přepni **Sazby DPH podle** na
   *Vlastní datum dokladu*.
2. Na hlavním tabu se u DUZP objeví pole **Datum sazby DPH**. Vyplň ho.
3. Ulož. Všechny řádky i řádky rekapitulace berou sazbu k tomuto datu.

**Jen některé řádky — vlastní datum na řádcích**

Typicky závěrečná faktura: běžné řádky v dnešní sazbě, odpočet zálohy
v sazbě, kterou byla záloha zdaněná.

1. Na záložce **Nastavení** přepni **Sazby DPH podle** na
   *Vlastní datum na řádcích* a ulož.
2. Na tabu **Řádky** otevři řádek odpočtu zálohy. V sekci *DPH* je nové
   pole **Datum sazby** — vyplň den, kdy byla záloha zdaněná. Nabídka
   **Kód DPH** se přepne na kódy a procenta platná k tomu dni.
3. Ostatní řádky nech s prázdným datem — berou sazbu jako doklad sám.
4. V seznamu řádků přibude sloupec **Datum sazby**, v rekapitulaci budou
   dvě skupiny téhož kódu, každá se svým procentem.

**Kde je to potom vidět**

V detailu dokladu je u údajů řádek **Sazby DPH** (jen když sazby nejdou
automaticky) a u řádku s vlastním datem je datum hned za sazbou.

## Na co narazíš

**Procento nikde nepřepíšeš.** Pole **DPH %** ve formuláři řádku ani
řádku rekapitulace není. Sazbu vidíš v popisku kódu a v pruhu součtů nad
formulářem řádku.

**Kód DPH v nabídce chybí.** Nabídka ukazuje jen kódy, které mají k datu
sazby sazbu — *První snížená* a *Druhá snížená* platily jen v letech
2015–2023, *Snížená* v nich naopak ne. Potřebuješ-li takový kód, patří
k řádku jiné datum sazby.

**„Kód DPH … nemá k datu sazby … sazbu.“** Změnil jsi DPPD nebo datum
sazby a některý řádek má kód, který k novému datu neplatí. Rozpracovaný
doklad se s tím uloží (žlutý pruh **Uloženo, ale zkontroluj:**), ale do
stavu **V pořádku** ho Shipard nepustí. Otevři řádek — kód je v nabídce
označený *k datu bez sazby* — a vyber jiný, nebo oprav datum.

**Vlastní datum je pozdější než to automatické.** Shipard na to upozorní,
ale uloží i potvrdí. Sazba „z budoucnosti“ bývá překlep v roce.

**Přepnutí zpět na Automaticky data smaže.** Vlastní datum dokladu
i data na řádcích se při uložení vymažou a procenta se vrátí k běžné
sazbě.

**Rekapitulace dodavatele v jiné sazbě.** U **Převzaté** rekapitulace
opisuješ základ, daň a celkem; procento Shipard doplní sám. Když daň
neodpovídá sazbě k datu sazby, napíše to při uložení — a ty víš, že má
doklad dostat jiné DPPD nebo vlastní datum sazby.

**Opravný doklad** bere sazby sám k **Datu plnění původního dokladu**,
nic nepřepínáš.

**Zálohové faktury** přepínač nemají — nejsou to daňové doklady a DPH
na nich je jen orientační, podle **Data vystavení**.

**Do kterého období doklad patří, se nemění.** Datum sazby určuje jen
procento. Přiznání, kontrolní hlášení i zámky období se dál řídí DUZP
a DPPD.

## Souvisí

- [Dokončení dokladu](../faktury-prijate/dokonceni-dokladu.md)
- [Vystavení faktury](../faktury-vydane/vystaveni-faktury.md)
- [Opravný daňový doklad (dobropis)](../faktury-vydane/opravny-doklad.md)
- [Pokladní doklad](../pokladna/pokladni-doklad.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
