# Korpus testovacích dokladů s očekávaným výsledkem

Generovaný korpus fiktivních přijatých dokladů v `tests/corpus/`: pro
každý doklad a každý profil vlastní firmy PDF a očekávaný výsledek
v kanonickém formátu `shpd.docs.document.v1`. Zadání a rozhodnutí:
`tasks/testing-phase1.md`, issue #103 (D1–D18).

## 1. Účel a vazby

Issue #103 dělí testování na dvě nezávislé poloviny spojené jedním
formátem: **AI polovina** porovnává výstup analýzy dokladu s očekávaným
výsledkem, **účetní polovina** bere očekávaný výsledek jako „ideální
analýzu“ a ověřuje zaúčtování, přiznání DPH a kontrolní hlášení. Obě
potřebují korpus dokladů, u kterých přesně víme, co je na papíře.

Zdrojem pravdy je ručně psaný **datový soubor dokladu**; PDF i očekávaný
výsledek z něj vyrobí generátor (D13). Částky počítá generátor sám,
v `Decimal`, nezávisle na výpočtu Shipardu (D5) — chyba výpočtu
v Shipardu tak nemůže souhlasit sama se sebou.

Fáze F1 (tento dokument) je jen obsah. Sestavení datových sad (profil ×
korpus, seed s ideální analýzou) je F2, ověření zaúčtování F3,
porovnávač a evaluace AI F4 — obě čtou `expected/` a `projection.jsonc`.
Soubory ISDOC u podoby `pdfIsdoc` dodá generátor ISDOC (#119 fáze 1);
normalizaci hodnot v produkční cestě převezme #118.

## 2. Struktura

```
tools/invoice-generator/            # sdílený generátor (balík invoicegen, jen stdlib)
  invoicegen/{jsonc,checks,compute,render,expected,corpus,registries}.py
  tests/                            # python3 -m unittest
demo/invoices/                      # demo kolekce — jen importuje generátor, výstup beze změny
tests/corpus/
  projection.jsonc                  # papírová projekce (sdílí generátor, PHPUnit strážce, F4)
  cz/                               # země vlastní firmy
    build.py                        # vstupní bod
    identities.jsonc                # profily + dodavatelé, datum ověření
    templates/*.html                # šablony korpusu (vlastní kopie, demo se nemění)
    data/NNNN-<slug>.jsonc          # zdroj dokladu — píše se ručně
    out/<profil>/NNNN-<slug>.pdf    # generované, commitované
    expected/<profil>/NNNN-<slug>.json  # generované, commitované, ručně se NEEDITUJÍ
    build-state.json                # sha256 renderu per výstup
```

Moduly generátoru: `tools/invoice-generator/README.md`. Vstupní body si
přidávají `tools/invoice-generator` na `sys.path`, nic se neinstaluje.

**Profily** vlastní firmy (klíč = název adresáře v `out/` a `expected/`):

| Klíč | Vlastní firma | Na dokladech jako odběratel |
|------|---------------|-----------------------------|
| `vat-payer` | s.r.o., plátce DPH | název, adresa, IČO, DIČ |
| `non-payer` | s.r.o., neplátce DPH | název, adresa, IČO |
| `association` | spolek (z. s.), neplátce DPH | název, adresa, IČO |

## 3. Identity (`identities.jsonc`)

Všechny subjekty jsou fiktivní a odlišné od demo identit
(`demo/invoices/suppliers.jsonc` — build disjunkci kontroluje):

- CZ IČO s validním kontrolním součtem, v ARES neexistující, názvy s nulou
  zásahů;
- DIČ zemí EU s platným kontrolním číslem (DE: ISO 7064 MOD 11,10)
  a ve VIES neplatné;
- domény jen v TLD `.example` (RFC 2606);
- česká čísla účtů s mod 11, IBAN odpovídá účtu, reálné kódy bank bez
  názvů bank; zahraniční IBAN s platnými kontrolními číslicemi.

Hodnoty jsou v **kanonickém tvaru** (§ 11); šablony je na PDF tisknou jako
skutečné doklady (PSČ s mezerou, IBAN po čtveřicích, telefon s mezerami).
Záznam dodavatele obsahuje přesně to, co dodavatel tiskne (`null` =
netiskne; účtenka tak nemá bankovní účet, americký dodavatel placený
kartou žádné DIČ ani banku). Profil je vlastní firma jako odběratel —
kontakt a banku nenese. `vatPayer: false` = na dokladu „Nejsem plátce
DPH“, bez DIČ. Klíče jen pro generátor (`accent` šablony, `print.state`
pro tisk americké adresy) do očekávání nejdou.

Kontrolní součty ověřuje každý build (`checks.validate_identities`);
ARES a VIES jen `build.py --verify-identities` (síť, § 12) — datum
posledního ověření nese `verifiedAt`. Při kolizi (IČO přiděleno, DIČ
platné) identitu vyměnit a korpus přegenerovat.

## 4. Datový soubor dokladu (`data/NNNN-<slug>.jsonc`)

Píše se ručně, stručně; všechno odvoditelné dopočítá generátor. Název:
`NNNN` = dvojčíslí varianty + podoba (`01` = `pdf`, `02` = `pdfIsdoc`),
slug = varianta (`0502-eu-services`).

```jsonc
{
	"variant": "eu-services",
	"form": "pdfIsdoc",                  // "pdf" | "pdfIsdoc"
	"supplier": "<klíč dodavatele>",
	"docNumber": "INV-2026-0388",
	"paymentReference": null,            // VS (jen číslice, max 10); null = na dokladu není
	"dates": { "issue": "…", "tax": "…", "due": "…", "periodFrom": "…", "periodTo": "…" },
	"currency": "EUR",
	"vatTreatment": "reverseCharge",     // § 5
	"payment": { "method": "bankTransfer", "specificSymbol": null, "constantSymbol": null },
	"contentTag": "it.hosting",
	"rows": [ … ],
	"rounding": "none",                  // "none" | "czk" (jen CZK)
	"template": "en",
	"notes": { "onDocument": "…" },      // volná poznámka; null = bez poznámky
	"skipProfiles": { "non-payer": "identifikovaná osoba — druhá vlna" },
	"overrides": { "non-payer": { … }, "association": "non-payer" },
}
```

Pole řádku: `text`, `description` (volitelné), `supplierCode`, `ean`
(volitelné, 8 nebo 13 číslic), `qty` (nejvýš 3 desetinná místa), `unit`
(tiskne se doslova), `unitPrice` (nejvýš 2 desetinná místa), `vatPct`
(`null` u `noVat`), `discountPct` (volitelné, sleva v %), `supplyKind`
(`goods` | `services`, u dodavatele mimo ČR povinné, u tuzemského
zakázané), `reverseChargeCode` (jen tuzemské přenesení, pak povinné),
`tag` (výjimka štítku řádku, musí se lišit od štítku dokladu),
`priceCalcMode: "fromTotal"` + `totalPrice` (autoritativní cena řádku,
od `qty × unitPrice` se smí lišit o méně než 1,00; bez slevy).

Pravidla hlavičky: `dates.tax` povinné mimo `noVat`; `dates.due` povinné
mimo účtenku, účtenka ho nemá; `periodFrom` a `periodTo` jen spolu;
účtenka bez variabilního symbolu, platba `card` nebo `cash`; `payment.method`
bez uvedení = `bankTransfer`. Účtenka = `template: "receipt"`.

**`overrides`** = částečný datový soubor per profil: objekty se slučují do
hloubky, pole a skaláry nahrazují, `null` nastavuje `null` (řádky se tedy
v přepisu uvádějí celé). Hodnota smí být i jméno jiného profilu — alias
přebírá jeho přepis (`"association": "non-payer"`). **`skipProfiles`** =
vynechaná buňka matice, hodnota je důvod (vypisuje ho build). Profil nesmí
být v obou.

Sloučený doklad každého profilu se validuje zvlášť: šablona, dodavatel,
`vatTreatment` × dodavatel (`noVat` jen bez DIČ, `standard` /
`pricesWithVat` / `reverseCharge` jen s DIČ, `exemptIntraCommunity` jen
z jiného státu EU), sazby podle země dodavatele (`cz`: 21, 12, 0; `de`:
19, 7, 0), štítky z taxonomie `modules/core/exchange/config/contentTags.jsonc`.
Obě podoby varianty jsou dva doklady téhož dodavatele.

## 5. `vatTreatment`

Jak dokument pojednává DPH; odvodí se z něj `vat.mode`, `vat.reverseCharge`,
rekapitulace a text na dokladu:

| Hodnota | Na dokladu | `vat.mode` | `reverseCharge` | Rekapitulace |
|---------|-----------|-----------|-----------------|--------------|
| `standard` | sazby, ceny bez DPH | `fromBase` | `false` | ano |
| `pricesWithVat` | sazby, ceny s DPH (účtenka) | `fromTotal` | `false` | ano |
| `reverseCharge` | 0 % + „daň odvede zákazník“ / čl. 196 | `fromBase` | `true` | ano (0 %) |
| `exemptIntraCommunity` | 0 % + osvobozené dodání do EU (čl. 138) | `fromBase` | `false` | ano (0 %) |
| `noVat` | DPH vůbec (neplátce, třetí země) | `none` | `false` | ne (`vatRecap: []`) |

Tuzemský neplátce má na dokladu „Nejsem plátce DPH.“; dodavatel ze třetí
země o DPH nemluví vůbec.

## 6. Varianty a matice

Deset variant první vlny (D16), každá ve dvou podobách (`pdf`, `pdfIsdoc`):

| V | Slug | Dodavatel | Měna | Šablona | `vatTreatment` | Štítek | Prověří |
|---|------|-----------|------|---------|----------------|--------|---------|
| 1 | `domestic-goods` | CZ | CZK | `cs-a` | `standard` 21 % | `goods.stock` | řádky, jednotky m2 / ks / bm, sleva na řádku |
| 2 | `domestic-two-rates` | CZ | CZK | `cs-b` | `standard` 21 % + 12 % | `office.supplies`, řádky 12 % `admin.memberships` | rekapitulace po sazbách, `rowExceptions` |
| 3 | `domestic-rounding` | CZ | CZK | `cs-c` | `standard`, zaokrouhlení na Kč | `premises.electricity` | `totalRounding` (dolů i nahoru), období plnění |
| 4 | `domestic-reverse-charge` | CZ | CZK | `cs-a` | `reverseCharge` | `premises.maintenance` | `reverseCharge`, `reverseChargeCode: "4"` |
| 5 | `eu-services` | DE | EUR | `en` | `reverseCharge` (čl. 196) | `it.hosting` | `intracom`, `supplyKind: services` |
| 6 | `eu-goods` | DE | EUR | `en` | `exemptIntraCommunity` (čl. 138) | `it.hardware` | `intracom`, `supplyKind: goods`, `reverseCharge: false`, EAN |
| 7 | `domestic-non-payer-supplier` | CZ neplátce | CZK | `cs-b` | `noVat` | `services.training` | `vat.mode: none`, bez rekapitulace |
| 8 | `third-country-services` | US | USD | `en` | `noVat` | `it.software` | `thirdCountry`, `supplyKind` bez zmínky o DPH, úhrada kartou |
| 9 | `receipt` | CZ | CZK | `receipt` | `pricesWithVat` 21 % (+ 12 %) | `vehicle.fuel` | účtenka bez odběratele a splatnosti, `fromTotal` u PHM |
| 10 | `long-document` | CZ | CZK | `cs-c` | `standard` 21 % + 12 % | `goods.stock`, potraviny `people.catering` | 44–48 řádků přes dvě strany, úplnost řádků |

Matice variant × profil (D17). Identifikovaná osoba se v první vlně neřeší:

| V | `vat-payer` | `non-payer` | `association` |
|---|-------------|-------------|---------------|
| 1–3, 7, 9, 10 | ✓ | ✓ | ✓ |
| 4 | ✓ | přepis: běžná faktura 21 %, bez přenesení | přepis jako `non-payer` |
| 5 | ✓ | vynecháno: identifikovaná osoba — druhá vlna | vynecháno: identifikovaná osoba — druhá vlna |
| 6 | ✓ | přepis: DPH dodavatele 19 %, bez DIČ odběratele, bez textu čl. 138 | přepis jako `non-payer` |
| 8 | ✓ | vynecháno: identifikovaná osoba — druhá vlna | vynecháno: identifikovaná osoba — druhá vlna |

Celkem 20 + 16 + 16 = **52 buněk** (PDF + očekávaný výsledek), 8 vynechaných.

## 7. Výpočet (D5)

`invoicegen/compute.py`, jen `Decimal`, `ROUND_HALF_UP` na setiny, žádný
`float` (vstup s `float` je chyba):

- řádek: `qty × unitPrice × (1 − discountPct/100)`, jedno zaokrouhlení;
  s `priceCalcMode: "fromTotal"` je `totalPrice` autoritou (build ověří
  rozdíl < 1,00);
- rekapitulace po sazbách z hlavičky: ceny bez DPH — základ = Σ řádků
  sazby, daň = základ × sazba; ceny s DPH — celkem = Σ řádků sazby, daň =
  celkem × sazba / (100 + sazba), základ = celkem − daň;
- `noVat`: bez rekapitulace, částka = Σ řádků, `totalBase` a `totalVat`
  nejsou;
- `rounding: "czk"`: částka k úhradě na celé Kč, `totalRounding` se
  znaménkem, build assertuje pásmo 0,01–1,00 (jinak derivace režimu
  zaokrouhlení v Shipardu nezabere);
- integritní asserty: Σ řádků = Σ rekapitulace = součty;
- cizí měna: vše v měně dokladu; kurz na dokladech korpusu není.

Demo kolekce je referenční výpočet — `tools/invoice-generator/tests/`
hlídá její součty i bajtově shodné HTML.

## 8. Očekávaný výsledek — papírová projekce (D14)

`expected/<profil>/NNNN-<slug>.json` je **úplná papírová projekce**
canonicalu:

- platný `shpd.docs.document.v1` (projde `SchemaValidator`);
- obsahuje **vždy všechna pole projekce**; `null` = na dokladu není;
  pole mimo projekci chybí (chybějící klíč = neporovnávat, u generovaného
  korpusu vždy chyba — hlídá PHPUnit);
- klíče v pořadí projekce, JSON s odsazením 2 mezery, UTF-8 bez
  escapování, `\n` na konci; čísla jako JSON number (částky na setiny,
  množství bez koncových nul), zapisovač nikdy nejde přes `float`;
- liší se od konvence datových sad (`docs/datasets.md` — tam se `null`
  vynechává): očekávání **není** soubor sady.

### Projekce (`tests/corpus/projection.jsonc`)

Jediný zdroj pravdy pro generátor (`invoicegen/expected.py`), PHPUnit
strážce i porovnávač F4. Čtení je v PHP i Pythonu stejné, dispatch jen
podle typu JSON, pořadí klíčů je významné:

| Uzel | Význam |
|------|--------|
| objekt bez `$ref` | strom klíčů — očekávání má přesně tyto klíče, v tomto pořadí |
| `{ "$ref": "Party" }` | odkaz do `$defs` (jediný klíč objektu) |
| `[ uzel ]` | pole; každý prvek proti uzlu |
| `"jméno"` | list; tvar hodnoty ze `shapes`; `null` = na dokladu není (tvar se nekontroluje, výjimka `const`) |
| `null` | list bez tvaru (platí jen JSON schéma formátu) |

Tvar = objekt s právě jedním klíčem: `regex` (společná podmnožina PCRE
a Python `re`: `^…$`, třídy znaků, `(?:…)`, `{m,n}`, `|`, `\s`, `\S`,
`[0-9]`; ne `\d`, `\p{…}`, `(?>…)`, `++`; bez `~`), `const` (přesná
hodnota, platí i pro `null`), `check` (`number` | `amount` — nejvýš dvě
desetinná místa | `index` — celé číslo ≥ 0 | `tag` — klíč v taxonomii),
`byCountry` (tvar podle klíče `country` v témž objektu, `"*"` povinné —
PSČ cz / sk pět číslic, IČO cz / sk osm číslic, `houseNumber` u `us`
`null`).

Top-level `nullableNodes` = jediné uzly, které smí být `null` jako celek
(`customer` u účtenky). Ostatní objekty projekce jsou vždy přítomné
s `null` v listech; pole bez prvků (`vatRecap` u dokladu bez DPH,
`rowExceptions`) je prázdné pole — schéma formátu `null` u polí nedovolí.

Pole projekce: top-level `format`, `formatVersion`, `docType`, `docNumber`,
`selfParty`, `supplier`, `customer`, `dates` {`issueDate`, `dueDate`,
`taxPointDate`, `periodFrom`, `periodTo`}, `currency`, `exchangeRate`,
`vat` {`mode`, `place`, `reverseCharge`}, `payment` {`method`,
`paymentReference`, `specificSymbol`, `constantSymbol`}, `notes`
{`onDocument`}, `rows[]`, `vatRecap[]`, `totals` {`totalBase`,
`totalVat`, `totalAmount`, `totalRounding`}, `_resolve` {`contentTag`
{`tag`, `rowExceptions[]` {`rowIndex`, `tag`}}}; Party `name`, `country`,
`companyId`, `taxId`, `vatId`, `courtRegistration`, `address` {`street`,
`houseNumber`, `city`, `zip`, `country`}, `contact` {`name`, `email`,
`phone`, `web`}, `bankAccount` {`accountNumber`, `iban`, `bic`}; Row
`rowKind`, `item` {`supplierCode`, `ean`, `name`, `description`}, `unit`,
`quantity`, `unitPrice`, `totalPrice`, `priceCalcMode`, `discountPct`,
`discountAmount`, `vat` {`pct`, `supplyKind`, `reverseChargeCode`};
VatRecapEntry `vatPct`, `base`, `tax`, `total`. Pořadí odpovídá schématu
(strážce to ověřuje). Systémová pole a metadata AI (`source`, `docText`,
`accountingDate`, `vatObligationDate`, `registrationCountry`,
`recapSource`, `calcSource`, `rows[].vat.code`, `vatRecap[].vatCode`,
`orderPos`, `computed`, `ourCode`, `paymentTermDays`, `cityPart`,
`displayLine`, `balanceParty`, `dimensions`, `attachments`,
`notes.internal`, z `_resolve` vše kromě štítku a výjimek) mimo projekci.

## 9. Odvozená pole

Pravidla kóduje `invoicegen/expected.py` a sledují prompt analýzy pošty
`modules/core/mail/profiles/czech_general.jsonc` (konstanta
`PROMPT_VERSION`, dnes v4.7.1). Když se prompt změní, změní se modul
a korpus se přegeneruje — jinak evaluace měří proti zastaralé pravdě.

- `docType`: `invoiceReceived` (i účtenka); `selfParty`: `customer`.
- `supplier`: záznam dodavatele bez klíčů generátoru; `customer`: identita
  profilu (kontakt, banka a rejstřík `null`); u účtenky `customer: null`.
- `vat.mode` a `vat.reverseCharge` podle `vatTreatment` (§ 5). Text čl. 138
  (osvobozené dodání) `reverseCharge` nezapíná.
- `vat.place`: podle DIČ dodavatele (prefix `CZ` → `domestic`, prefix státu
  EU vč. `EL` → `intracom`); bez DIČ podle sídla (`cz` → `domestic`, EU →
  `intracom`, jinak `thirdCountry`).
- `dates`: `issueDate`, `dueDate` (účtenka `null`), `taxPointDate`
  (`dates.tax`, u `noVat` `null`), `periodFrom` / `periodTo`.
- `payment.method` z dat (účtenka `card` / `cash`), `paymentReference` = VS.
- `notes.onDocument` = jen volná poznámka z dat; zákonná věta k DPH
  (přenesení, čl. 196 / 138, „Nejsem plátce DPH“) je samostatný blok
  šablony a do očekávání nejde — AI ji nejspíš do poznámky opíše,
  porovnávač F4 proto musí text poznámky brát volně.
- `rows[]`: `rowKind: item`, `item.name` = text řádku, `unit` doslova,
  `quantity` bez koncových nul, `unitPrice` a `totalPrice` na setiny,
  `priceCalcMode` (`fromUnitPrice`, u PHM `fromTotal`), `discountPct` z dat,
  `discountAmount` vždy `null` (šablony tisknou slevu v procentech;
  canonical je „pct nebo amount“), `vat.pct` sazba z dokladu (i 0; u `noVat`
  `null`), `vat.supplyKind` u dodavatele mimo ČR na každém řádku, jinak
  `null`, `vat.reverseChargeCode` jen u tuzemského přenesení.
- `vatRecap[]` po sazbách sestupně; u `noVat` `[]`, `totals.totalBase`
  a `totalVat` `null`, `totalAmount` = částka k úhradě, `totalRounding` `0`.
- `_resolve.contentTag.rowExceptions`: `[{rowIndex (od 0), tag}]` z `tag`
  řádků, `[]` bez výjimek; štítky v taxonomii.
- `exchangeRate`: `null` (kurz na dokladech korpusu není).

## 10. Rovnocenné tvary

Implementuje porovnávač F4, na obě strany:

1. na straně výstupu `null` ≡ chybějící klíč;
2. `totalRounding` `0` ≡ `null`;
3. prázdné pole (`rowExceptions`, `vatRecap`) ≡ `null`;
4. objekt se všemi listy `null` (`contact`, `bankAccount`, `address`,
   `customer`) ≡ `null` — korpus píše u přítomné strany vždy objekt,
   u účtenky `customer: null`.

## 11. Tvary hodnot (D15)

Kanonický tvar hodnot v očekávaném výsledku. Vychází z toho, co už dělá
produkce (země malými v `IsdocReader`, `CanonicalParty` a promptu; ulice
a číslo zvlášť). Je to zároveň vstup pro #118 — do jeho dokončení použije
porovnávač stejná pravidla na obě strany. Sloupec *Tvar v projekci* je
jméno ze `shapes`.

| Hodnota | Tvar | Příklad | Tvar v projekci |
|---------|------|---------|-----------------|
| země (strana i adresa) | ISO 3166-1 alpha-2 malými | `cz`, `de`, `us` | `country` |
| měna | ISO 4217 velkými | `CZK`, `EUR` | `currency` |
| PSČ | `cz`, `sk`: 5 číslic bez mezery; jinde jak na dokladu, bez okrajových mezer | `76001`, `10115`, `94105` | `zip` (byCountry) |
| ulice a číslo | kde číslo následuje ulici (`cz`, `de`): `street` bez čísla, `houseNumber` jak na dokladu včetně lomítka; kde číslo ulici předchází (`us`): celý řádek v `street`, `houseNumber` `null` | `Pilařská` + `1204/7`; `500 Market Street, Suite 200` + `null` | `text`, `houseNumber` (byCountry) |
| obec | bez PSČ a bez zkratky státu (`us`) | `Zlín`, `San Francisco` | `text` |
| IČO | `cz`, `sk`: 8 číslic včetně vedoucích nul; jinde jak na dokladu | `05123456` | `companyId` (byCountry) |
| DIČ / VAT ID | velkými, s prefixem země, bez mezer; u EU `taxId` = `vatId` | `CZ52654150`, `DE123456789` | `vatId` |
| číslo účtu (`cz`) | `[předčíslí-]číslo/kód banky`, bez vedoucích nul, bez mezer | `19-2000145399/0800` | `accountNumber` |
| IBAN | velkými, bez mezer | `CZ6508000000192000145399` | `iban` |
| BIC | velkými | `GIBACZPX` | `bic` |
| e-mail | malými | `fakturace@dodavatel.example` | `email` |
| telefon | `+` a číslice, bez mezer | `+420577123456` | `phone` |
| web | malými, bez schématu a koncového lomítka | `www.dodavatel.example` | `web` |
| datum | ISO 8601 | `2026-08-25` | `date` |
| částka | číslo zaokrouhlené na setiny | `10330.58` | `amount` |
| množství, procenta | číslo bez koncových nul | `0.643`, `12.5`, `5` | `number` |
| EAN | 8 nebo 13 číslic | `4260999100013` | `ean` |
| symboly (VS, KS, SS) | jen číslice, nejvýš 10 | `20261180`, `0308` | `symbol` |
| jednotka, názvy, texty | jak na dokladu, bez okrajových mezer | `m2`, `ks` | `text` |

Na PDF se hodnoty tisknou tak, jak je tisknou skutečné doklady (PSČ
s mezerou, IBAN po čtveřicích, telefon s mezerami, americká adresa se
zkratkou státu) — rozdíl mezi tiskem a kanonickým tvarem je přesně to,
co má zachytit normalizace (#118).

## 12. Build

```bash
python3 tests/corpus/cz/build.py [--only NNNN]... [--force] [--verify-identities]
```

1. Validace identit (součty, disjunkce s demem), datových souborů
   (§ 4) a párů podob.
2. Pro každou buňku matice: výpočet, očekávaný výsledek (zapisuje se vždy —
   je deterministický), HTML a jeho hash (sha256 HTML + voleb papíru).
3. PDF se renderuje jen tehdy, když chybí, hash se liší od
   `build-state.json`, nebo s `--force` — PDF z Chromia není bajtově
   deterministické a přegenerování všeho by dělalo šum v diffech. Klíč
   stavu je `<profil>/NNNN-<slug>`; plný běh zastaralé klíče prořeže.
4. Výpis per buňka: součty, „PDF beze změny / přegenerováno“, u podoby
   `pdfIsdoc` řádek „ISDOC: čeká na generátor (#119)“, vynechané buňky
   s důvodem.

Gotenberg jako demo (`GOTENBERG_URL`, `docs/render.md`); volby papíru per
šablona v hlavičce `<!-- pdf: paperWidth=80mm … -->` (účtenka). Šablona
`cs-c` je vícestránková: okraje přes `@page`, opakovaná hlavička tabulky,
číslo strany v okrajovém boxu `@bottom-right` (Chromium ≥ 131).

`--verify-identities` (síť, po změně identit): ARES — IČO neexistuje
(404) a název má nula zásahů; VIES — každé DIČ (i CZ) neplatné. Vše OK →
přepíše řádek `verifiedAt` dnešním datem a pokračuje buildem; selhání →
exit 1 a identity vyměnit; nedostupný registr (429, 5xx, síť) → exit 2,
identity se neoznačí jako ověřené. Bez přepínače build na síť nesahá.

Šablony korpusu v `templates/` skládá `build.py` (`build_values`):
odběratel bez DIČ u neplátce, sloupec slevy jen když ji některý řádek má,
měna podle dokladu, texty k DPH podle `vatTreatment`, období plnění,
tiskový tvar hodnot. Demo šablony a data se nemění.

## 13. Strážce korpusu (PHPUnit)

`tests/Unit/Corpus/CorpusExpectedTest.php`, bez sítě a bez Gotenbergu:

- projekce je dobře utvořená (tvary, `$ref`, `byCountry`) a je podmnožinou
  schématu formátu ve stejném pořadí;
- matice `data/*.jsonc` × profily bez `skipProfiles` je úplná: ke každé
  buňce existuje očekávání i PDF, žádné sirotky v `expected/` a `out/`,
  profily v `identities.jsonc` odpovídají;
- každý `expected/**/*.json` (data provider per soubor): projde
  `SchemaValidator` proti `shpd.docs.document.v1`; má přesně klíče
  projekce v jejím pořadí a tvary hodnot (regexy, `byCountry`, štítky
  z taxonomie); formát souboru (2 mezery, bez escapování, `\n`);
  aritmetika — řádek `qty × unitPrice × sleva` (±0,01; `fromTotal` < 1,00),
  `base + tax = total`, `tax = base × pct` (±0,01), Σ řádků proti
  rekapitulaci podle `vat.mode`, Σ rekapitulace = součty, `totalBase +
  totalVat + totalRounding = totalAmount`, `rowExceptions` v rozsahu
  a bez duplicit; shoda s datovým souborem po sloučení přepisu profilu
  (číslo, měna, počet řádků, štítek, účtenka, podoba a varianta z názvu).

Jeden doklad: `vendor/bin/phpunit --filter 'CorpusExpectedTest.*0101-domestic-goods'`.
Problémy se sbírají do jednoho assertu s tečkovanými cestami
(`rows[3].vat.pct`).

## 14. Jak přidat doklad

1. Zvol variantu a podobu → `NNNN-<slug>`; nová varianta = záznam
   v `invoicegen/corpus.py` (`VARIANTS`) a řádek v tabulce § 6.
2. Nový dodavatel → záznam v `identities.jsonc` (kanonický tvar, `.example`),
   kandidáty IČO / IBAN / DIČ vyrob pomocníky z `invoicegen/checks.py`,
   pak `build.py --verify-identities`.
3. Napiš `data/NNNN-<slug>.jsonc` (§ 4); vynechané profily do
   `skipProfiles` s důvodem, přepisy do `overrides`.
4. `python3 tests/corpus/cz/build.py --only NNNN`, prohlédni PDF.
5. `vendor/bin/phpunit --filter CorpusExpectedTest`,
   `cd tools/invoice-generator && python3 -m unittest`.
6. Doplň matici v § 6 a commitni `data/`, `out/`, `expected/`,
   `build-state.json` (a identity) v jednom commitu.

Nová šablona = soubor v `templates/` + jazyk v `TEMPLATE_LOCALE`
(`build.py`); účtenkové šablony v `RECEIPT_TEMPLATES` (`corpus.py`).

## 15. Pasti a mimo rozsah

- **Očekávaný výsledek se nikdy needituje ručně.** Změna = úprava
  datového souboru nebo generátoru a nový build.
- **Demo se nemění.** Šablony a data dema jsou vázané na video; korpus má
  vlastní kopie šablon. Identity se nesmí překrývat (build kontroluje).
- **Pole projekce nechybí** — chybějící klíč by znamenal „neporovnávat“.
- **Pravidla odvozených polí sledují prompt** (§ 9).
- **`exemptIntraCommunity` ≠ `reverseCharge`**: čl. 138 `reverseCharge`
  nezapíná; samovyměření u zboží z EU zapne místo plnění
  (`docs/exchange-format.md` § 8.4).
- **Účtenka v podobě `pdfIsdoc`** je v pořádku — ISDOC zná zjednodušený
  daňový doklad jako samostatný typ.
- **Žádný `float` ve výpočtu ani v zápisu JSON.**
- **DS ID** (`xxxx-xxxx-xxxx-xxxx`) nesmí v datech vzniknout —
  `scripts/check-sensitive.py`.

Mimo rozsah F1: soubory ISDOC (#119), ISDOC vložený do PDF, nastavení
profilů ve zdroji dat a sestavení sad (F2), ověření zaúčtování (F3),
porovnávač a evaluace (F4), normalizace v produkci (#118), druhá vlna
variant (dobropis, zálohová faktura s odpočtem zálohy, slovenské profily,
identifikovaná osoba), soukromé sady s reálnými doklady.
