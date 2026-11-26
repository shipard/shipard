# Evidence tržeb — specifikace doplňku

Doplněk `economy.salesRegistration` (#171). Tento dokument popisuje, **jak je
to udělané**; návod pro uživatele je v `help/`, zadání v
`tasks/sales-registration-phase1.md`.

## 1. Rozsah

Evidence tržeb podle zákona účinného od 1. 1. 2027 (EET 2.0, datové rozhraní
v4.1). Zatím Česko; model je stavěný tak, aby unesl další státy a víc
registrací různých typů v jednom zdroji dat (#171 D16).

| Fáze | Obsah | Stav |
|---|---|---|
| 1 | typ evidence, registrace a evidenční jednotky, certifikát, pojistka serveru, engine, Ověřit spojení, upozornění na certifikát | rozpracováno |
| 2 | tržby a pokusy, napojení na doklady, opakování cronem | — |
| 3 | Kontaktní platba, záložka a štítky na dokladu | — |

Mimo rozsah (#171 D14): `urceno_cerp_zuct` / `cerp_zuct`, pověřující
poplatník, automatická obnova certifikátu, potvrzovací kód na tisku, další
země.

## 2. Typ evidence (D2, D17)

Typ evidence má dvě poloviny, každou u jiného modulu:

- **data typu** deklaruje modul země — cfgItem
  `world.<země>.salesRegistrationTypes`, klíč = id typu (`cz.eet2`): název,
  `country`, `validFrom`, `currency`, `environments` (`production`,
  `playground` → `url`), masky `taxpayerId` (s popiskem — v Česku „EIČ“),
  `unitId`, `cashRegisterId`, `serialNumber`;
- **třídu engine** přiřazuje doplněk — cfgItem
  `economy.salesRegistration.engines`, `{"<id typu>": "<FQCN>"}`.

Modul jádra tak nejmenuje třídu doplňku (#170 D6) a doplněk na modulu země
nezávisí.

**Registr** `SalesRegistrationTypes::fromConfig()` je jediné čtení obou
cfgItemů. Skládá se od mapy enginů: země je první část id typu (`cz.eet2` →
`world.cz.salesRegistrationTypes`) — `ConfigRuntime` cfgItemy vyjmenovat
neumí a ani to není potřeba. Důsledky:

- typ bez enginu se nenabízí;
- záznam mapy, jehož modul země není ve zdroji dat aktivní, se ignoruje;
- vadná položka (žádné prostředí přes HTTPS, maska, která není regulární
  výraz, chybějící měna) se přeskočí — nic nespadne, registrace takového typu
  jen neprojde validací.

Masky jsou regulární výrazy bez oddělovačů, jak je píše XSD; kontroluje se
celá hodnota (`SalesRegistrationType::matches()`).

Rozhraní engine nepředpokládá online potvrzení každé tržby — výsledek je
obecný stav a kód potvrzení, data specifická pro typ jsou v doplňcích zprávy.

## 3. Registrace a evidenční jednotky (D3, D10, D16, U3)

### 3.1 Registrace — `economy_sales_registration_registrations`

| Sloupec | Popis |
|---|---|
| `type` | id typu evidence z registru (`cz.eet2`). `varchar`, ne `enumString` — nabídka se skládá ze dvou cfgItemů; hodnotu hlídá `RegistrationDocument` |
| `name` | název registrace |
| `taxpayer_id` | obecný identifikátor poplatníka, význam podle typu (EET 2.0: EIČ). **Systémový** — plní ho jen nahrání certifikátu (CN certifikátu) |
| `mode` | režim `production` / `verification` / `playground` (cfgItem `economy.salesRegistration.modes`), **výchozí `verification`** — nová registrace nic neeviduje |
| `valid_from`, `valid_to` | platnost registrace |
| `default_unit` | výchozí evidenční jednotka — musí patřit této registraci |
| `cert_pem` | certifikát v PEM (veřejná část), systémový |
| `cert_key` | soukromý klíč v PEM, `encrypted_text` — šifrovaný klíčem zdroje dat (`DsSecretCipher`), systémový a citlivý; heslo k `.p12` se neukládá |
| `cert_valid_from`, `cert_valid_to`, `cert_serial`, `cert_uploaded_at`, `cert_uploaded_by` | údaje certifikátu a nahrání, systémové |
| `notice` | poznámka |

Stavy: `core.system.docStatesArchive`, nová registrace je **Koncept**.
Registrace V pořádku je jen ke čtení (úprava přes V opravě), jednotky se
s ní zamykají taky.

**Bez `exchangeKey`.** Přirozený klíč je dvojice (typ, poplatník), kterou
jedním sloupcem vyjádřit nejde, identifikátor je do nahrání certifikátu
prázdný a registrace s certifikátem se mezi zdroji dat nepřenáší.

**Pravidla `RegistrationDocument`** (uložení přichází i jako částečná data,
pravidla se počítají nad uloženým řádkem doplněným o příchozí hodnoty):

- typ je z registru; typ, který z registru zmizel (vypnutý modul země),
  úpravu ostatních polí neblokuje, nově ho zvolit nejde;
- po nahrání certifikátu typ nejde změnit — certifikát patří typu;
- identifikátor poplatníka odpovídá masce typu;
- výchozí jednotka patří této registraci;
- **mezi živými registracemi** (Koncept, V pořádku, V opravě) je dvojice
  (typ, poplatník) jen jednou. Archiv dvojici nést smí — proto to hlídá
  dokument, ne unikátní index. Registrace různých typů (česká EET a později
  evidence jiné země) žijí vedle sebe (D16).

### 3.2 Evidenční jednotky — `economy_sales_registration_units`

`registration`, `unit_id` (text — tvar říká maska typu, u EET 2.0 číslo
z DIS+ 1 až 999999999), `name`. Unikátní index (`registration`, `unit_id`).
Bez stavů; spravují se jen ze sub-tabulky ve formuláři registrace
(`hideFromNavigation`). `UnitDocument` hlídá masku podle typu nadřízené
registrace a duplicitu srozumitelnou hláškou. Smazání jednotky, která byla
výchozí jednotkou, registraci výchozí jednotku odebere.

### 3.3 UI

Vlastní sekce Nastavení **Evidence tržeb** (`settingsSections`) s položkou
Registrace. Formulář `RegistrationsForm`: typ, název, režim, platnost,
výchozí jednotka (až u uložené registrace — nabízí její jednotky), poznámka;
sekce Certifikát ukazuje údaje jen ke čtení (blok `properties`). Soukromý
klíč formulář ani viewer nenačítají — viewer čte vyjmenované sloupce,
`getEditableSensitiveColumns()` je prázdné.

## 4. Certifikát (D11, U1)

### 4.1 Nahrání

Akce záznamu **Nahrát certifikát** (`recordActions` → `uploadCertificate`,
`admin: true`) nad registrací v živém stavu. Dialog: soubor (`.p12` / `.pfx`,
nejvýš 64 KiB — `accept` hlídá jen prohlížeč, obsah ověřuje import), heslo
a upozornění, co se stane. Postup `UploadCertificateAction`:

1. `Pkcs12Importer::import()` — `openssl_pkcs12_read`. Selže-li jinak než na
   hesle (typicky zastaralá šifra RC2-40, kterou mají `.p12` playgroundu
   a kterou OpenSSL 3 bez legacy provideru odmítá), zkusí se
   `LegacyPkcs12Reader`: `openssl pkcs12 -legacy -nodes -clcerts -in <soubor>
   -passin stdin` přes `proc_open`. **Heslo jde jen na stdin** — nikdy do
   argumentů, proměnných prostředí ani logu; prostředí podprocesu je pevné,
   binárka se hledá přes `UpdateEnvironment::findTool()`.
2. Kontroly: klíč patří k certifikátu, CN odpovídá masce identifikátoru typu,
   certifikát právě platí.
3. Registrace s jiným vyplněným identifikátorem certifikát odmítne.
4. Zápis přes `TableGateway` (platí pravidla `RegistrationDocument`):
   `cert_pem`, `cert_key` = `DsSecretCipher::forConfig()->encrypt(klíč v PEM)`,
   `taxpayer_id` = CN, platnost, sériové číslo, kdy a kdo nahrál. Nový
   certifikát přepíše starý (obnova po roce).

Heslo se neukládá ani neloguje. Nešifrovaný klíč žije jen v paměti mezi
převodem a zašifrováním. Do logu jde jen registrace, typ, identifikátor
a konec platnosti.

Chyby rozlišuje `CertificateImportException::$kind` a akce je vrací jako
chybu pole (422): špatné heslo (pole hesla), soubor není certifikát, klíč
nepatří k certifikátu, nejde o certifikát daného typu (CN mimo masku),
vypršel / ještě neplatí, jiný poplatník; chybějící `openssl` u zastaralé
šifry je chyba formuláře.

Šifra produkčních `.p12` z CA EET zatím není ověřená — obě cesty fungují,
takže na ní nezáleží.

### 4.2 Klíč při odeslání

Knihovna EET čte klíč jen ze souboru. `KeyFiles::with()` proto dešifrovaný
klíč a certifikát zapíše do privátního adresáře zdroje dat `tmp/` (adresář
`0700`, soubory `0600` vytvořené pod umask `0077` — nikdy systémový `/tmp`),
zavolá předanou funkci s cestami a soubory smaže ve `finally`, i po výjimce.
Soubor žije jen po dobu načtení klíče. Po tvrdém pádu procesu by soubor
zůstal, proto každé použití nejdřív uklidí vlastní soubory starší 10 minut.

Adresář `tmp/` je v kontraktu práv (`PermissionSpec::getDataSourceEntries()`,
`docs/operations/permissions.md`); při založení pod rootem dostane vlastníka
zdroje dat. Adresář přístupný skupině nebo ostatním se nepoužije.
