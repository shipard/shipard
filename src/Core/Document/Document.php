<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Settings\SettingsStore;

abstract class Document
{
    protected array $data = [];
    protected array $originalData = [];
    protected ?\Dibi\Connection $db = null;
    protected ?ConfigRuntime $config = null;
    protected ?DataSourceConfig $dsConfig = null;
    protected ?SettingsStore $settings = null;

    /**
     * Přechod docState detekovaný v beforeSave (`trackStateChange`).
     * Null = stav se neměnil. Pro nový záznam vzniklý rovnou mimo Koncept
     * (import) je old = 0. Čte TableGateway po commitu pro dispatch
     * documentEventHandlers — gateway sám nic nedopočítává.
     *
     * @var array{old: int, new: int}|null
     */
    protected ?array $stateTransition = null;

    /**
     * True = save běží uvnitř transakce vlastněné volajícím (exchange
     * Applier přes TransactionlessTableGateway). Dokumentové hooky pak
     * NESMÍ otevírat vlastní transakci — MariaDB nemá nested START
     * TRANSACTION, druhý begin() by vnější transakci implicitně commitnul
     * (viz doc-comment TransactionlessTableGateway). Nastavuje gateway
     * v injectDocServices().
     */
    protected bool $externalTransaction = false;

    public function setDb(\Dibi\Connection $db): void
    {
        $this->db = $db;
    }

    public function setExternalTransaction(bool $external): void
    {
        $this->externalTransaction = $external;
    }

    public function setConfig(ConfigRuntime $config): void
    {
        $this->config = $config;
    }

    public function setDsConfig(DataSourceConfig $dsConfig): void
    {
        $this->dsConfig = $dsConfig;
    }

    /**
     * Sdílená instance per gateway/dávka (cache SettingsStore je per instance)
     * — dokument si NIKDY nekonstruuje vlastní store, při dávkovém zpracování
     * by to byl jeden dotaz na doklad.
     */
    public function setSettings(SettingsStore $settings): void
    {
        $this->settings = $settings;
    }

    public function validate(array &$data): ValidationResult
    {
        return new ValidationResult();
    }

    /**
     * Zápis, který zámek záznamu (`documentLockProviders`, #55 D26) záměrně
     * obchází — import mód zrcadlí cizí systém, needituje ho. TableGateway
     * se ptá po `validate()` a před `beforeSave()`, takže virtuální markery
     * payloadu (`_importNumber`) jsou v `$data` ještě přítomné. Default: nic
     * neobchází.
     *
     * @param array<string, mixed> $data
     */
    public function isLockExempt(array $data): bool
    {
        return false;
    }

    /**
     * Schéma strukturovaného pole (#74) pro **zápis a editaci** tohoto
     * záznamu — hook I2. `null` = použij statický atribut `schema` z definice
     * sloupce (to je případ všech dnešních konzumentů, např. profil podatele).
     *
     * Override má smysl tam, kde se schéma vybírá podle záznamu: hlavička
     * podání DPH nese jinou sadu polí pro přiznání, kontrolní a souhrnné
     * hlášení, a jednou uložený snapshot si musí verzi **připnout**, aby
     * zůstal doslovně interpretovatelný (`$data['_schema']` hodnoty, ne
     * aktuální soubor). Čtení a zobrazení jde vždycky podle `_schema`
     * v datech, hook do něj nemluví — viz `StructuredFieldResolver`.
     *
     * Vrací cfgItem klíč se schématem (bez verze).
     *
     * @param array<string, mixed> $data zapisovaná data (už s dekódovanými
     *        strukturovanými sloupci, virtuální sloupce z formuláře jsou slité)
     */
    public function structuredSchemaFor(string $column, array $data): ?string
    {
        return null;
    }

    /**
     * Per-dokumentové filtrování nabídky stavových přechodů pro UI
     * (doc-state-options, form load/recalculate). Vrací podmnožinu
     * $transitions; slouží jen ke skrytí slepých cest v nabídce —
     * server-side bariéry (validace, výjimky v beforeSave) zůstávají
     * jediným vynucením. Default: pass-through.
     *
     * @param array<int, array<string, mixed>> $transitions položky z
     *        DocStateConfig::getAvailableTransitions (klíč 'state')
     * @param array<string, mixed> $row aktuální řádek záznamu
     * @return array<int, array<string, mixed>>
     */
    public function filterStateTransitions(array $transitions, array $row): array
    {
        return $transitions;
    }

    /**
     * Pre-save hook. Receives the original DB row as $originalData on update;
     * null on insert. Subclasses use originalData to detect what changed
     * (e.g. partner change → rebuild snapshot, docState transition → assign
     * number).
     */
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
    }

    /**
     * Detekce přechodu docState pro `$stateTransition` — volá se z `beforeSave`
     * jako první (vzor DocDocument, AssetDocument, BankTransactionDocument).
     * Nový záznam vzniklý rovnou mimo Koncept (import, „Vystavit a uzavřít“)
     * je přechod s old = 0; uložení bez změny stavu přechod nenastaví.
     * `$data` je referencí kvůli potomkům, kteří k přechodu dopisují sloupce
     * (DocDocument: `doc_state_changed_at`).
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $originalData
     */
    protected function trackStateChange(array &$data, ?array $originalData): void
    {
        $this->stateTransition = null;

        if ($originalData === null) {
            $newState = (int) ($data['docState'] ?? 10);
            if ($newState !== 10) {
                $this->stateTransition = ['old' => 0, 'new' => $newState];
            }
            return;
        }

        $newState = (int) ($data['docState'] ?? $originalData['docState'] ?? 10);
        $oldState = (int) ($originalData['docState'] ?? 10);
        if ($newState !== $oldState) {
            $this->stateTransition = ['old' => $oldState, 'new' => $newState];
        }
    }

    /**
     * Hook běžící uvnitř save transakce, PO INSERT/UPDATE hlavičky i child rows,
     * ale PŘED commitem. Použít, když má vedlejší efekt (např. UPDATE jiné
     * tabulky odvozené z nově uloženého stavu) zůstat atomický s persistem —
     * pokud zde dojde k výjimce, TableGateway transakci roluje zpět.
     *
     * Stav DB v tomto okamžiku obsahuje právě uložené řádky, takže lze
     * spolehlivě dotazovat sourozence vč. tohoto.
     */
    public function afterPersist(array $data): void
    {
    }

    /**
     * Hook běžící PO commitu. Vhodné pro idempotentní vedlejší efekty, které
     * nemají závislost na atomicitě (logování, posílání notifikací, atd.).
     */
    public function afterSave(array $data): void
    {
    }

    public function beforeDelete(array $data): void
    {
    }

    public function afterDelete(array $data): void
    {
    }

    public function onLoad(array &$data): void
    {
    }

    /**
     * @return array{old: int, new: int}|null
     */
    public function getStateTransition(): ?array
    {
        return $this->stateTransition;
    }
}
