---
title: AI — připojení, funkce a modely
summary: Kde správce zadá klíč k AI, co je úroveň modelu, jak jedné AI funkci (třeba štítkům) nastavit jiný model, co znamenají Úpravy požadavku a Stav volání a jak si nastavení ověřit příkazem ai-test.
keywords: [nastavení AI, AI připojení, připojení k AI, klíč AI, API klíč, zadat klíč, kde vzít klíč, Anthropic, Claude, AI funkce, funkce AI, úroveň modelu, rychlý model, vyvážený model, silný model, model, jiný model, změnit model, nastavit model, přepnout model, model pro štítky, model pro vytěžení, model pro chat, model asistenta, modely, přehled modelů, katalog modelů, ceny modelů, cena za tokeny, model končí, model skončil, AI model brzy končí, tvarovat jako model, vlastní model, přemýšlení, úsilí, max. tokenů, úpravy požadavku, stav volání, chyba nastavení, teplota se neposílá, ověřit AI, otestovat AI, ai-test, test volání, AI nefunguje, nejde AI, AI neodpovídá]
related: [prvni-nastaveni.md, posta/prijem-posty.md, posta/kdyz-ai-cte-spatne.md, polozky/obsahove-stitky.md, co-dnes-nejde.md, slovnicek.md]
---

# AI — připojení, funkce a modely

Všechno, co v Shipardu dělá AI — vytěžení došlé pošty, štítky položek,
shrnutí na Dashboardu i asistent v Chatu — běží přes jedno místo
v **Nastavení aplikace → AI**. Má tři části: **Připojení** (kam a s jakým
klíčem se volá), **Funkce** (co se volá a na jakém modelu) a **Modely**
(přehled modelů, které Shipard zná).

## Kdy to potřebuješ

- Zakládáš zdroj dat a AI zatím nic nedělá — v Došlé poště se nic
  nevytěží, asistent hlásí, že není nastavené připojení.
- Chceš jedné funkci (třeba štítkům) nastavit levnější nebo silnější
  model než ostatním.
- Na Dashboardu svítí upozornění **AI model brzy končí** nebo **AI model
  skončil**.
- Něco s AI nefunguje a chceš zjistit, kde to vázne, dřív než selže
  další analýza.

## Postup

**Připojení a klíč**

1. Klíč k AI vydává poskytovatel modelu — pro Claude ho získáš v účtu
   u Anthropic (sekce API keys). Klíč je tajný: nikomu ho neposílej
   a do Shipardu ho nikdy nepiš do formuláře ani do chatu.
2. Zdroj dat má od založení jedno připojení s kódem **default**
   (**Nastavení aplikace → AI → Připojení**). Klíč do něj zadá správce
   z konzole serveru příkazem:

   ```
   shpd-ds ai-backend-set-key --backend default
   ```

   Příkaz se na klíč zeptá skrytým vstupem a připojení zapne. V detailu
   připojení pak uvidíš **API klíč: nastaven** — samotný klíč se nikde
   nezobrazuje. Když Shipard provozuje hosting, klíč za tebe nastaví
   provozovatel a tento krok odpadá.
3. Druhé připojení potřebuješ jen pro jiný klíč nebo účet (třeba
   oddělené vyúčtování). Založíš ho v **Připojení** tlačítkem
   **Přidat**, klíč zadáš stejným příkazem s jeho kódem
   (`--backend <kód>`).

**Funkce a úroveň modelu**

4. **Nastavení aplikace → AI → Funkce** ukazuje jednu řádku na každou
   AI funkci: **Vytěžení došlé pošty**, **Klasifikace obsahových
   štítků**, **Shrnutí dashboardu**, **Chat asistenta** a **Klasifikace
   historie účtování**. Každá má **úroveň modelu** — **Rychlý**,
   **Vyvážený** nebo **Silný**. Který model úroveň právě znamená, určuje
   Shipard sám a mění to s novými verzemi; ty nemusíš nic nastavovat.
5. Chceš-li funkci jiný model, otevři ji (**Otevřít**) a vyber **Model**
   ze seznamu — třeba štítkům levný Haiku. Prázdné pole = model podle
   úrovně. Stejně můžeš funkci přepnout na jiné **AI připojení**
   a upravit **Přemýšlení (thinking)**, **Úsilí (effort)** a **Max.
   tokenů (prázdné = podle funkce)**; **Podle funkce (default)** znamená
   výchozí hodnotu funkce, **Výchozí modelu (neposílat)** nechat
   rozhodnutí na modelu.
6. Model, který v seznamu není (nový nebo testovací), napiš jako vlastní
   ID a v poli **Tvarovat jako model** vyber nejpodobnější model ze
   seznamu — podle něj Shipard pozná, co model přijímá.

**Co se modelu skutečně pošle**

7. V detailu funkce najdeš skupinu **Volání** a **Ladění**: každá
   hodnota nese, odkud je — *nastaveno u funkce*, *podle úrovně
   modelu*, *podle deklarace funkce*, *výchozí připojení*.
8. **Úpravy požadavku** říkají, co Shipard před odesláním změní, protože
   to model neumí — třeba *Teplota 0 se neposílá — model nepřijímá jinou
   než výchozí teplotu* u modelů řady 5. Není to chyba; volání projde.
9. **Stav volání** říká, jak by volání dopadlo. **V pořádku** znamená,
   že připojení existuje a má uložený klíč. Jinak tu je věta s chybou
   a **Kde to opravit** — stejná věta, jakou ti ukáže asistent v Chatu.

**Ověření**

10. Zda nastavení skutečně funguje (klíč platí, model na platformě
    existuje, účet má volný limit), ověří správce z konzole:

    ```
    shpd-ds ai-test
    ```

    Pro každou funkci pošle modelu kratičký požadavek a vypíše
    připojení, model, čas, tokeny a cenu — nebo chybu. Jednu funkci
    otestuješ s `--function`, třeba `--function exchange.contentTags`.
    Příkaz najdeš i v detailu funkce pod **Ověření z konzole**.
    Stojí zlomek centu, proto se spouští jen ručně.

**Modely**

11. **Nastavení aplikace → AI → Modely** je přehled jen pro čtení:
    u každého modelu úroveň, cena za milion tokenů na vstupu a výstupu,
    stav (**Končí** s datem, **Skončil**) a **Výchozí pro** — které
    úrovně na něj dnes ukazují. V detailu jsou schopnosti modelu
    (teplota, režimy přemýšlení, úrovně úsilí, strop výstupu) a ID na
    platformách.

## Na co narazíš

- **Upozornění „AI model brzy končí“** se týká jen modelu, který máš
  u funkce vybraný ručně; modely podle úrovně Shipard vyměňuje sám.
  Vyber u funkce jiný model, nebo pole **Model** vyprázdni.
- **Asistent na modelech, které přemýšlejí** (řada 5 — Sonnet 5.5,
  Haiku 5.5, Opus 5.5): funguje; během úvahy ukáže **Přemýšlím…**, text
  úvah neuvidíš. Úvahy se počítají do spotřeby a asistent si je u každé
  otázky tvoří znovu — delší odpověď a víc tokenů než na Sonnetu 4.6.
- **Vytěžení pošty má navíc AI profily** (**Nastavení aplikace →
  Ostatní → Pošta → AI profily**) — profil umí pro některé zprávy
  přepsat model funkce **Vytěžení došlé pošty**. Jde o výjimku pro
  poštu; ostatní funkce profily nemají.
- **Klíč nejde zadat v prohlížeči** — jen příkazem v konzoli. Nejde ani
  zobrazit; když ho ztratíš, vygeneruj u poskytovatele nový a zadej ho
  znovu.
- **Cena** v přehledu modelů je ceník poskytovatele; kolik která funkce
  skutečně spotřebovala, Shipard zatím nesčítá.

## Souvisí

- [První nastavení zdroje dat](prvni-nastaveni.md) — ostatní kroky po
  založení zdroje dat
- [Příjem pošty](posta/prijem-posty.md) — co dělá AI s došlou fakturou
- [Když AI přečte fakturu špatně](posta/kdyz-ai-cte-spatne.md)
- [Obsahové štítky](polozky/obsahove-stitky.md) — funkce, které se
  typicky mění model
- [Co Shipard dnes neumí](co-dnes-nejde.md) — omezení asistenta
- [Slovníček](slovnicek.md) — připojení, funkce, úroveň modelu
