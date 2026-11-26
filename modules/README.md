# Moduly Shipardu

Přehled všech modulů v hlavním repozitáři. Jak modulový systém funguje —
struktura modulu, `module.jsonc`, závislosti, kompilace konfigurace — popisuje
[docs/modules.md](../docs/modules.md).

## Druhy modulů

| Druh | Kde leží | Jak se dostane do zdroje dat |
|------|----------|------------------------------|
| **jádro** | `modules/` | závislost instalačního modulu (`install.base`) |
| **doplněk** | `modules/`, v `module.jsonc` má `"kind": "addon"` | zapne se ve zdroji dat, případně ho přinese vlastní instalační modul dedikovaného zdroje dat |
| **privátní** | vlastní repozitář, `extraModulesPath` v `server.json` | zapne se ve zdroji dat |

O místě rozhoduje, komu kód patří: obecné a veřejné je v tomto repozitáři
(jádro nebo doplněk), logika jedné firmy je privátní modul mimo něj
([docs/modules.md](../docs/modules.md) §1 a §10). **Instalační modul**
(skupina `install`) žádnou agendu nenese — určuje, z čeho se zdroj dat skládá.

Doplněk leží mezi ostatními moduly, ve skupině podle své domény, a od jádra
se liší jen polem `kind`. Jádro o doplňku neví — doplněk se napojuje přes body
rozšíření.

## Zapnutí doplňku

V adresáři zdroje dat:

```bash
shpd-ds ds-module list                 # co je aktivní a co jde zapnout
shpd-ds ds-module enable <id>          # zapíše modul do config/main.json a spustí ds-upgrade
shpd-ds ds-module disable <id>         # tabulky modulu v databázi zůstávají
```

Podrobnosti: [docs/cli.md](../docs/cli.md) § `shpd-ds` a
[docs/modules.md](../docs/modules.md) §9.

## Kde je dokumentace modulu

- **`README.md` modulu** — co modul dělá, jeho tabulky, třídy a konfigurace;
  pravidla v [docs/documentation.md](../docs/documentation.md).
- **`docs/` modulu** (`modules/<skupina>/<modul>/docs/`) — specifikace, které
  se týkají jen toho modulu. Doplněk tu drží všechno, co by jinak bylo
  v kořenovém `docs/`.
- **Uživatelská dokumentace** je v [help/](../help/README.md), zadání
  v [tasks/](../tasks/README.md).

Pozor na záměnu: `modules/docs/` je **skupina modulů dokladů** (faktury,
pokladna, účetní doklady), ne dokumentace.

## Přehled modulů

Odkaz u ID vede na `README.md` modulu; modul bez něj odkazuje na svůj
`module.jsonc`.

<!-- MODULY:BEGIN — generováno scripts/modules-index.php, needituj ručně -->

## Instalační moduly

| Modul | Název | Popis |
|-------|-------|-------|
| [`install.base`](install/base/module.jsonc) | Základní instalace | Základní sada modulů pro provoz systému |
| [`install.hosting`](install/hosting/module.jsonc) | Instalace hostingu | Dedikovaný hosting zdroj dat — servery, zdroje dat, portál (D11) |

## Jádro

### `base`

| Modul | Název | Popis |
|-------|-------|-------|
| [`base.persons`](base/persons/README.md) | Osoby | Dodavatelé, odběratelé, zaměstnanci a další osoby |
| [`base.places`](base/places/module.jsonc) | Místa | Místa: hierarchický číselník areálů, budov, podlaží, místností a míst s druhy míst |
| [`base.registry`](base/registry/README.md) | Spisovna | Evidence trvalých dokumentů firmy (smlouvy, pojistky, revize...) |

### `core`

| Modul | Název | Popis |
|-------|-------|-------|
| [`core.ai`](core/ai/README.md) | AI | Sdílená AI infrastruktura: AI připojení (platforma, zašifrovaný API klíč), katalog modelů, mapa úrovní a nastavení AI funkcí sdílené analýzou pošty, chatem a dalšími AI funkcemi |
| [`core.alerts`](core/alerts/README.md) | Upozornění | Upozornění pro uživatele — chybějící nastavení, dlouho otevřené stavy, nespárovaná data, vybitá IoT baterie atp. |
| [`core.attachments`](core/attachments/README.md) | Přílohy | Přílohy dokumentů — nahrávání souborů, náhledy, úložiště |
| [`core.chat`](core/chat/module.jsonc) | Chat | Vestavěný AI asistent: perzistované konverzace a zprávy |
| [`core.exchange`](core/exchange/README.md) | Výměnné formáty | Kanonické výměnné formáty pro doklady a další entity |
| [`core.help`](core/help/module.jsonc) | Uživatelská dokumentace | Zpřístupňuje uživatelskou dokumentaci z help/ vnitřnímu asistentovi přes čtecí MCP nástroje (help_search, help_get_page). Žádné tabulky ani obrazovky — zdrojem pravdy jsou markdown soubory v help/. |
| [`core.mail`](core/mail/README.md) | Pošta | Došlá a odchozí pošta |
| [`core.prints`](core/prints/README.md) | Tisky | Nastavení společné všem tiskům — vzhled a vlastní texty na tiscích |
| [`core.system`](core/system/module.jsonc) | Systém | Základní systémové tabulky — uživatelé, relace, nastavení |
| [`core.units`](core/units/README.md) | Měrné jednotky | Číselník měrných jednotek (hmotnost, objem, délka, čas, …) s koeficienty pro převody v rámci jedné veličiny |

### `docs`

| Modul | Název | Popis |
|-------|-------|-------|
| [`docs.accountingDocs`](docs/accountingDocs/module.jsonc) | Účetní doklady | Účetní doklady (cmnbkp) — specifická Document třída (účtování + vyrovnanost) |
| [`docs.cashDocs`](docs/cashDocs/README.md) | Pokladní doklady | Pokladní doklady (cash) — příjmové a výdajové doklady per pokladna, Document třída, formulář a viewer |
| [`docs.cashRegister`](docs/cashRegister/README.md) | Prodejky | Prodejky (cashreg) — prodej za hotové / kartou na pokladně, Document třída, formulář a viewer |
| [`docs.core`](docs/core/README.md) | Doklady — jádro | Polymorfní jádro pro všechny typy dokladů (faktury, …) |
| [`docs.invoicesIn`](docs/invoicesIn/README.md) | Faktury přijaté | Faktury přijaté (invni) — specifická Document třída a viewer |
| [`docs.invoicesOut`](docs/invoicesOut/README.md) | Faktury vydané | Faktury vydané (invno) — specifická Document třída a viewer |
| [`docs.proformasIn`](docs/proformasIn/README.md) | Zálohové faktury přijaté | Zálohové faktury přijaté (invpi) — nedaňový doklad; specifická Document třída, formulář a viewer (#106) |
| [`docs.proformasOut`](docs/proformasOut/README.md) | Zálohové faktury vydané | Zálohové faktury vydané (invpo) — nedaňový doklad; specifická Document třída, formulář a viewer (#79) |

### `economy`

| Modul | Název | Popis |
|-------|-------|-------|
| [`economy.accbal`](economy/accbal/module.jsonc) | Saldokonto | Nastavení saldokont — skupiny a jejich účty |
| [`economy.accounting`](economy/accounting/module.jsonc) | Účetnictví | Podvojné účetnictví — účtový rozvrh |
| [`economy.assets`](economy/assets/module.jsonc) | Majetek | Evidence majetku: karty majetku, typy, skupiny typů a účetní skupiny |
| [`economy.bank`](economy/bank/module.jsonc) | Banka | Bankovní transakce a výpisy |
| [`economy.codebooks`](economy/codebooks/README.md) | Číselníky | Sklady, střediska, fiskální období, registrace DPH, pokladny, bankovní spojení a další číselníky |
| [`economy.exchangeRates`](economy/exchangeRates/README.md) | Kurzy cizích měn | Kurzovní lístky země zdroje dat a dohledání kurzu k datu pro doklady, applier a import bankovního výpisu |
| [`economy.items`](economy/items/README.md) | Položky | Katalog položek (zboží, služby, účetní položky, …) a jejich druhů |
| [`economy.periodicInvoicing`](economy/periodicInvoicing/module.jsonc) | Periodická fakturace | Engine periodické fakturace: zdroje fakturace, evidence období per zdroj a denní běh, který vystavuje koncepty dokladů |
| [`economy.vat`](economy/vat/module.jsonc) | DPH | Výstupy DPH: živé přiznání, kontrolní hlášení a souhrnné hlášení počítané on-demand z potvrzených dokladů (issue #55, M1) |
| [`economy.workOrders`](economy/workOrders/module.jsonc) | Zakázky | Zakázky: druhy s typem, číselné řady, evidence zakázek a dimenze deníku zakázka |

### `hosting`

| Modul | Název | Popis |
|-------|-------|-------|
| [`hosting.core`](hosting/core/module.jsonc) | Hosting | Centrální správa zdrojů dat Shipard — servery, zdroje dat, vazby uživatel↔DS, portál pro uživatele |

### `tasks`

| Modul | Název | Popis |
|-------|-------|-------|
| [`tasks.core`](tasks/core/README.md) | Úkoly | Osobní i sdílený seznam úkolů s prioritami, termíny a jednoduchým životním cyklem |

### `world`

| Modul | Název | Popis |
|-------|-------|-------|
| [`world.assets`](world/assets/README.md) | Pravidla odpisů majetku | Pravidla daňových odpisů majetku per stát (CZ, později další státy EU) |
| [`world.base`](world/base/README.md) | Svět | Země, měny a jazyky |
| [`world.cz`](world/cz/README.md) | Česko — číselníky | Statické české státní číselníky společné pro víc domén — finanční úřady a jejich územní pracoviště, země podle daňového portálu (podání), typy kurzovních lístků ČNB, další podle potřeby |
| [`world.divisions`](world/divisions/README.md) | Administrativní členění | Hierarchické administrativní členění zemí — regiony, kraje, okresy, obce |
| [`world.trade`](world/trade/README.md) | Obchodní unie | Obchodní unie, členství zemí a definice daňových prefixů (EU, GCC, …) |
| [`world.validations`](world/validations/README.md) | Validace a normalizace údajů per stát | Normalizace a validace identifikátorů a kontaktních údajů per stát — IČO, DIČ, PSČ, tuzemské číslo účtu, IBAN, BIC, telefon, e-mail, web (#118) |
| [`world.vat`](world/vat/README.md) | Pravidla DPH | Sazby DPH a kódy DPH per stát (CZ, později další státy EU) |

## Doplňky

| Modul | Název | Popis |
|-------|-------|-------|
| [`economy.salesRegistration`](economy/salesRegistration/README.md) | Evidence tržeb | Evidence tržeb u správce daně (česká EET 2.0): registrace, evidenční jednotky, pokladní certifikát, pojistka serveru a odesílání zpráv |

<!-- MODULY:END -->
