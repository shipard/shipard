# Tabulka: Kontakty (base_persons_contacts)

## Účel

Tabulka eviduje kontaktní osoby a kontaktní místa přiřazená k osobám
a firmám v tabulce `base_persons_persons`. Jeden záznam v tabulce Osoby
může mít libovolný počet kontaktů — jak u firem (oddělení, zaměstnanci),
tak u fyzických osob (asistentka, manželka).

Kontakt je „lehký" záznam — slouží pro rychlé dohledání e-mailu nebo
telefonu, ne pro plnohodnotnou evidenci osob.

## Struktura

Tabulka nemá skupiny sloupců — všechny sloupce jsou v jedné ploché
struktuře.

| Sloupec | Typ | Nullable | Popis |
|---|---|---|---|
| `id` | int, PK | — | Primární klíč |
| `person` | int | ne | Vazba na osobu/firmu (`base_persons_persons`) |
| `name` | varchar(200) | ne | Název kontaktu — jméno osoby („Jan Novák") nebo funkční označení („Účtárna", „Reklamační oddělení") |
| `role` | varchar(100) | ano | Funkce nebo role — volný text: „Obchodní ředitel", „IT správce", „Fakturantka" |
| `email` | varchar(200) | ano | E-mailová adresa |
| `send_purposes` | json | ano | Účely odesílání — pole id z cfgItemu `base.persons.sendPurposes` (`["invoices","reminders"]`); NULL = žádný účel |
| `phone` | varchar(30) | ano | Telefonní číslo |
| `note` | text | ano | Volná poznámka |
| `order_pos` | smallint | ne (default 0) | Pořadí zobrazení — nižší hodnota = vyšší priorita. Kontakt s `order_pos = 0` se považuje za primární |
| `valid_from` | date | ano | Datum začátku platnosti kontaktu |
| `valid_to` | date | ano | Datum konce platnosti — kontakt s vyplněným `valid_to` v minulosti se v UI přestane nabízet jako aktivní, ale zůstane v historii |
| `docState` | tinyint | ne (default 10) | Stav dokumentu — cfgItem `core.system.docStatesArchive` |
| `docStateMain` | tinyint | ne (default 1) | Stav pro řazení a filtraci viewerů |

### Stavy dokumentů

`valid_to` a `docState` jsou dvě ortogonální osy — viz
[exchange-format-persons.md §4a](../../../../docs/exchange-format-persons.md#4a-stavy-dokumentů-a-valid_to--dvě-ortogonální-osy).
Stručně: `valid_to` značí, že kontakt přestal platit (historie zůstává
korektní), `docState = 90` značí, že záznam je vadný.

Aktivní záznamy mají `docState IN (10, 40, 80)`. Resolvery výměnného
formátu (modul `core.exchange`) tímto filtrem párují payload se
záznamy v DB.

## Obchodní logika

### Platnost kontaktu

Sloupce `valid_from` a `valid_to` umožňují vést historii kontaktních
osob. Kontakt s vyplněným `valid_to` v minulosti:

- Se nezobrazuje v aktivních výběrových seznamech.
- Zůstává v historických vazbách (např. kontaktní osoba na starší faktuře).
- Je dohledatelný v detailu osoby přes filtr „zobrazit i neplatné".

### Pořadí a primární kontakt

Sloupec `order_pos` určuje pořadí kontaktů v UI. Kontakt s nejnižší
hodnotou se zobrazí jako první a může sloužit jako výchozí volba
při vystavování dokladů.

### Účely odesílání

Sloupec `send_purposes` říká, co se na e-mail kontaktu posílá (#90 D34):
faktury a daňové doklady, upomínky, nabídky a objednávky, přehledy
a výpisy. Účely deklarují moduly klíčem `sendPurposes` v `module.jsonc`
a kompilace je skládá do cfgItemu `base.persons.sendPurposes`
(`docs/modules.md`).

- Při odeslání záznamu jdou do „Komu“ **všechny** platné kontakty osoby
  s e-mailem a s účelem tisku (`RecipientResolver`, `docs/prints.md`).
- Kontakt **bez účelu se při odesílání nepoužije** — ani jako záloha;
  tou je e-mail osoby.
- Validaci (jen známá id, bez duplicit) a serializaci dělá
  `ContactDocument`; prázdný výběr se ukládá jako NULL. Tvar sloupce zná
  jen `Shipard\Module\Base\Persons\Send\SendPurposes`.

### Vztah name vs role

Sloupec `name` je povinný a nese hlavní identifikaci kontaktu. Sloupec
`role` je doplňkový — typicky se vyplňuje, pokud `name` obsahuje jméno
osoby a je užitečné vědět, jakou funkci ve firmě zastává. Pokud `name`
obsahuje funkční označení (např. „Účtárna"), `role` se obvykle nevyplňuje.

## Indexy

| Index | Typ | Sloupce | Účel |
|---|---|---|---|
| `idx_person` | index | `person`, `order_pos` ASC | Hlavní přístupová cesta — rychlé načtení kontaktů osoby seřazených podle priority. Kompozitní index obslouží dotaz i řazení bez dalšího třídění |
| `idx_name` | index | `name` | Vyhledávání kontaktu podle jména napříč osobami |
| `idx_doc_state` | index | `docStateMain` ASC | Filtrace aktivních záznamů ve výpisech a resolverech |

## Návaznosti

- **Rodičovská tabulka:** `base_persons_persons` — přes sloupec `person`
- **Rozšíření:** Tabulku mohou rozšiřovat moduly, které potřebují na kontakt
  navázat další informaci (např. modul komunikace by mohl přidat sloupec
  `preferred_channel`).
