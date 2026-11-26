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
| 1 | typ evidence, registrace a evidenční jednotky, certifikát, pojistka serveru, engine, Ověřit spojení, upozornění na certifikát | hotovo 2026-11-26 (`tasks/sales-registration-phase1.md`) |
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

## 5. Pojistka serveru a kam zpráva jde (D9, D10, U2)

### 5.1 Pojistka

Sekce `salesRegistration` v `/etc/shipard/server.json` (deklaruje doplněk
polem `serverConfig`, čte `SalesRegistrationServerConfig`), klíč
`safety.mode`:

| Režim | Chování |
|---|---|
| `off` | rozhoduje režim registrace |
| `playground` | všechno jde na neprodukční prostředí se sdílenou testovací identitou — certifikát zdroje dat ven nejde nikdy |
| `drop` | nic se neodešle |

Výchozí stavy po vzoru `mail.safety`: sekce chybí a server je `production`
→ `off`; sekce chybí a jiný režim serveru → `drop`; chybná hodnota → `drop`
s popisem problému (`source: invalid`). Třída **nikdy nevyhazuje** —
nečitelný `server.json` je taky `drop`. Pojistka je věc serveru, zdroj dat ji
nepřepíše. Smysl: kopie ostrých dat na dev nebo testovacím serveru nesmí
poslat tržbu správci daně pod certifikátem zákazníka.

`doctor` ani `/_app/info` režim pojistky zatím neukazují — pro sekce modulů
tam není bod rozšíření (#171 D13, mimo fázi 1). Režim je vidět v detailu
každé registrace.

### 5.2 Kam zpráva jde

Jedna funkce, `MessageRouteResolver::resolve(pojistka, režim registrace,
zpráva žádá ověření)`:

| Pojistka | Režim registrace | Prostředí | Identita | Ověřovací mód |
|----------|------------------|-----------|----------|---------------|
| `off` | `production` | produkce | certifikát registrace | podle zprávy |
| `off` | `verification` | produkce | certifikát registrace | vždy |
| `off` | `playground` | playground | testovací | podle zprávy |
| `playground` | jakýkoli | playground | testovací | podle režimu |
| `drop` | jakýkoli | nic se neodešle | — | — |

Ověřovací mód má jedno pravidlo: zpráva ho žádá (Ověřit spojení), nebo je
registrace v ověřovacím režimu. Registrace s neznámým režimem se neposílá
(`dropped`). Výsledek `MessageRoute` nese u `dropped` důvod (`configured` /
`default` / `invalid` / `unknownMode`) a popis problému konfigurace.

`MessageRouteText::describe()` říká trasu slovy — hláška nad Přehledem
v detailu registrace (blok `notice`, u `drop` tón `warning`) a později
výsledek akcí. Viewer `ServerConfig` nedostává, pojistku si načte
`SalesRegistrationServerConfig::forServer()` sama.

### 5.3 Testovací identita

S testovací identitou jde ve zprávě **EIČ testovacího certifikátu**, ne
identifikátor registrace — jinak služba vrací varování 1 (EIČ zprávy ≠ EIČ
certifikátu). Klíč a certifikát `CZ00000019` jsou v
`resources/playground/` (`PlaygroundIdentity`); pocházejí z veřejného balíku
finanční správy pro vývojáře, proto smí být v repozitáři — zdroj, převod,
platnost (do 14. 5. 2027) a postup obnovy jsou v tamním README. Obsah
dokumentu „Přístupové a provozní informace — playground“ (TLP:AMBER) do
repozitáře nepatří.

`PlaygroundIdentityTest::testCertificateIsValidForAtLeast30Days` začne padat
30 dní před koncem platnosti certifikátu — signál k obnově. Tentýž test
hlídá, že v doplňku není žádný jiný soukromý klíč.

## 6. Engine a odeslání zprávy (D12)

### 6.1 Kontrakt engine

`SalesRegistrationEngine::send(SalesMessage, SenderIdentity,
EngineEnvironment): SendResult` — třída z mapy
`economy.salesRegistration.engines`, instance bez argumentů konstruktoru.

- Engine pošle **jednu zprávu** do daného prostředí pod danou identitou.
  **Nikdy nevyhazuje** — každé selhání je stav výsledku.
- Nerozhoduje, kam zpráva jde, ani pod jakou identitou; nic neukládá
  a neopakuje. Klíč čte ze souborů identity jen po dobu volání.
- Nepředpokládá online potvrzení každé tržby: `confirmationCode` je obecný
  kód potvrzení, pokud ho protokol vydává.

| Objekt | Obsah |
|---|---|
| `SalesMessage` | `uuid` (generuje volající — pokus jde zapsat před odesláním a potvrzení se váže na toto UUID), jednotka, pokladna, pořadové číslo, čas tržby, částka jako desetinný řetězec, měna, první zaslání, ověřovací mód, identifikátor poplatníka (doplní odesílač), `extras` typu |
| `SenderIdentity` | cesty k dočasným souborům klíče a certifikátu, identifikátor poplatníka, příznak testovací identity |
| `EngineEnvironment` | název prostředí a adresa služby z dat typu |
| `SendResult` | `status`, `sent` (odešlo něco po síti?), kód potvrzení, kód a text chyby, varování `[kód → text]`, `transactionId`, odeslané a přijaté tělo, doba v ms, příznak testu, `untrusted`, `problem` (místní důvod), trasa (prostředí, testovací identita, ověření) |

Stavy `SendStatus`: `confirmed` (jediný, který plní evidenční povinnost),
`verified`, `rejected`, `retryLater`, `unknown`, `dropped`.

### 6.2 `Eet2Engine` — typ `cz.eet2`

Tenká vrstva nad knihovnou `ondrejnov/eet` (pinovaný commit, viz README
modulu): `Certificate::fromPem()` z dočasných souborů, `Dispatcher`,
`CurlTransport` s limity knihovny 2,5 s na požadavek a 2 s na spojení
(HTTP/1.1, TLS ≥ 1.2, ověřený certifikát serveru).

| Knihovna | Stav |
|---|---|
| `send()` vrátí POK | `confirmed` |
| `check()` vrátí true (chyba s kódem 0) | `verified` |
| chyba služby −1 nebo 8 | `retryLater` |
| jiná chyba služby | `rejected` |
| `TransportException` (timeout, výpadek, SOAP fault, nečitelná odpověď) | `unknown` |
| `ResponseVerificationException` | `unknown` + `untrusted`, POK se nepřebírá |
| `ValidationException`, nečitelný certifikát, `extras` | `rejected`, nic neodešlo |

- `Dispatcher::check()` chybu služby polyká a vrací `false` — ověřovací mód
  se proto vyhodnocuje z `getLastResponse()`, ne z výjimky.
- Čas tržby jde v zóně `Europe/Prague`.
- `extras` (čerpání a zúčtování, pověřující poplatník — #171 D14) engine
  zatím neumí a zprávu s nimi **odmítne**, aby z daňové zprávy nic tiše
  nezmizelo.
- Chybové odpovědi služba nepodepisuje, ověřuje se jen potvrzení: podpis,
  řetěz k I.CA (`resources/ica-signing-ca.pem` knihovny), platnost
  certifikátu, `O = Generální finanční ředitelství` a shoda UUID se zprávou.
  CRL ani OCSP se nekontrolují.

### 6.3 Odesílač

`SalesRegistrationSender::send(řádek registrace, SalesMessage)`:

1. trasa — `MessageRouteResolver` (§5.2); `drop` = `dropped` bez sítě, klíč
   se ani nedešifruje;
2. typ registrace → engine; adresa prostředí z dat typu;
3. identita: certifikát registrace (klíč dešifrovaný `DsSecretCipher` až
   tady), nebo `PlaygroundIdentity` — s ní jde ve zprávě její identifikátor;
4. `KeyFiles::with()` — dočasné soubory jen po dobu volání enginu (§4.2);
5. `ErrorLogger::info('sales registration: message <stav>')` — registrace,
   typ, UUID, prostředí, stav, kódy, `transactionId`, doba. **Bez klíče,
   hesla a těl zpráv** (`SendResult::logContext()`).

Místní důvody, proč nic neodešlo (`SendResult::$problem`): `safety`, `type`,
`environment`, `noCertificate`, `key`, `noTestIdentity`, `message`,
`internal`. Odesílač nic neukládá a neopakuje — žurnál pokusů a opakování
staví fáze 2 nad vráceným výsledkem.

### 6.4 Ověřit spojení (U4)

Akce záznamu `verifyConnection` (`admin: true`), dostupná u živé registrace
s výchozí jednotkou a certifikátem — bez certifikátu jen když trasa vede na
testovací identitu. Po potvrzení pošle přes odesílač zkušební zprávu:
výchozí jednotka registrace, pokladna `SHIPARD-TEST`, pořadové číslo
`TEST-<RRRRMMDDhhmmss>`, částka 0,00, **vždy ověřovací mód** — nic se
neeviduje. Výsledek: „Spojení ověřeno — prostředí, identita“ (`success`),
jinak `warning` s kódem a textem chyby; varování služby (např. 1 = EIČ
zprávy ≠ EIČ certifikátu) úspěch mění na `warning`. Pod pojistkou `drop`
řekne, proč se nic neodešle.

### 6.5 Testy

- `Eet2EngineTest` — falešný transport nad oficiálními vzorovými zprávami
  a skutečnými odpověďmi playgroundu (`tests/Fixtures/…/eet/`, kopie fixtur
  knihovny); převod každého výsledku a výjimky.
- `SalesRegistrationSenderTest` — tabulka tras, klíč registrace nikdy na
  playground, úklid dočasného souboru i po výjimce, log bez tajemství.
- `Eet2PlaygroundTest` (suite Integration) — živý playground, jen
  s `SHIPARD_EET_PLAYGROUND=1`.

## 7. Upozornění (D13)

Tři kontroly `alertChecks` nad společným základem `CertificateCheck`
(id malými písmeny — pravidlo `AlertCheckDefinition`):

| Id | Závažnost | Kdy |
|---|---|---|
| `economy.salesregistration.cert_expiring` | warning | certifikát vyprší do 30 dnů (a ještě platí) |
| `economy.salesregistration.cert_expired` | error | certifikát vypršel |
| `economy.salesregistration.cert_missing` | warning | registrace nemá certifikát |

Hlídají se jen **platné registrace**: stav V pořádku a dnešek v rozmezí
`valid_from` – `valid_to`. Koncept, registrace v opravě, archiv a registrace
mimo platnost upozornění nenesou. Jeden nález na registraci
(`findingKey` = id, předmět = řádek registrace), nálezy „vyprší“ a „vypršel“
se nepřekrývají. Kontroly soukromý klíč nenačítají. Upozornění na
nepotvrzenou tržbu přijde s tržbami ve fázi 2.

## 8. Chybějící body rozšíření

Co fáze 1 obešla a co patří do obecné změny jádra s vlastní issue
(#170 D6), ne do úpravy na míru doplňku:

- **`doctor` a `/_app/info` neznají sekce `server.json` z modulů** — režim
  pojistky evidence tržeb ukazuje jen detail registrace (#171 D13).
- **Viewer ani `detailExtensions` nedostávají `ServerConfig`** — pojistku si
  doplněk načítá sám (`SalesRegistrationServerConfig::forServer()`).
- **Stránky `help/` nejsou vázané na aktivní moduly** — hlavička
  `modules:` na stránce doplňku zatím nic nedělá (#170 D7), stránka se
  nabízí i zdroji dat bez doplňku.
- **Podproces se vstupem na stdin** — `CapturedProcess` stdin neumí, záložní
  čtení `.p12` má vlastní `proc_open`.
- **Doplněk se nezapíná z aplikace** — jen `shpd-ds ds-module enable`
  (stránka Doplňky v Nastavení je cílový stav #170).

## 9. Co není ověřené

- **Produkce.** Knihovna i doplněk jsou ověřené jen proti playgroundu —
  k produkci zatím nejsou certifikáty. Adresa produkce a vydavatel
  podpisového certifikátu potvrzení (I.CA Public CA/RSA 06/2022, subjekt
  `O = Generální finanční ředitelství`) jsou z dokumentu „Přístupové
  a provozní informace — produkce“ (verze 1.2 z 11. 11. 2026) a odpovídají
  tomu, co engine ověřuje; první ostré potvrzení to musí potvrdit.
- **Šifra produkčních `.p12`** z CA EET — import má obě cesty (§4.1).
- **Fixtury stárnou**: testovací certifikát playgroundu do 14. 5. 2027,
  podpisový certifikát skutečného potvrzení do 8. 6. 2027; testy začnou
  padat 30 dní předem.
