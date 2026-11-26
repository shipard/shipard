<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Queue\SaleQueue;
use Shipard\Module\Economy\SalesRegistration\Safety\SalesRegistrationServerConfig;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Evidence tržeb × doklady (#171 D6, D7, U12) — handler na `docs_core_heads`.
 *
 *  - `stateChanged`: přechod do V pořádku (40) nebo Storno (30) spustí
 *    rozdílový výpočet ({@see SaleSync}). Počítá i se starým stavem 0
 *    (doklad založený rovnou V pořádku — „Vystavit a uzavřít“, pokyn přes
 *    API); importovaný doklad vyřadí vyhodnocení podle trvalého příznaku.
 *    Výjimky loguje — uložení dokladu kvůli evidenci selhat nesmí
 *    a chybějící tržbu dorovná záchranná síť. Založení tržby a její
 *    okamžité odeslání mají každé svůj `try`: „not created“ je jen selhání
 *    založení, selhání odeslání u existující tržby je „left to the queue“.
 *  - `beforeSave`: doklad s tržbou nejde přesunout do koše (stav Smazáno).
 *    Doklad se v aplikaci nemaže řádkem, ale přechodem stavu, a `beforeDelete`
 *    se pro hlavičku z UI nevolá — proto tady. `\DomainException` dá
 *    formuláři 422 s hláškou.
 *  - `beforeDelete`: pojistka pro tvrdé smazání řádku.
 */
class DocsHeadsSalesHandler extends AbstractDocumentEventHandler
{
    public const string TABLE = 'docs_core_heads';

    public const int STATE_CANCELLED = 30;
    public const int STATE_CONFIRMED = 40;
    public const int STATE_TRASH = 90;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($tableId !== self::TABLE || !in_array($newState, [self::STATE_CONFIRMED, self::STATE_CANCELLED], true)) {
            return;
        }
        $docId = (int) ($data['id'] ?? 0);
        if ($docId <= 0 || $this->db === null) {
            return;
        }

        try {
            $result = $this->sync()->sync($docId);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'sales registration: sale of document ' . $docId . ' not created');
            return;
        }
        if ($result->status === SyncResult::NONE) {
            return;
        }
        ErrorLogger::info('sales registration: document ' . $result->status, [
            'doc'          => $docId,
            'oldState'     => $oldState,
            'newState'     => $newState,
            'sale'         => $result->saleId,
            'registration' => $result->registration,
            'reason'       => $result->reason,
            'serialNumber' => $result->sale['serial_number'] ?? null,
        ]);
        if (!$result->isCreated()) {
            return;
        }

        // Okamžité odeslání má vlastní `try`: tržba už existuje a selhání
        // odeslání (i sestavení fronty) není „not created“ — pošle ji cron.
        try {
            $this->afterCreated($result);
        } catch (\Throwable $e) {
            ErrorLogger::warn(
                'sales registration: sale ' . $result->saleId . ' of document ' . $docId
                . ' not sent right away — left to the queue',
                [
                    'sale'         => $result->saleId,
                    'doc'          => $docId,
                    'registration' => $result->registration,
                    'exception'    => get_class($e),
                    'error'        => $e->getMessage(),
                ],
            );
        }
    }

    public function onBeforeSave(string $tableId, array &$data, ?array $originalData): void
    {
        if ($tableId !== self::TABLE || $originalData === null || !array_key_exists('docState', $data)) {
            return;
        }
        if ((int) $data['docState'] !== self::STATE_TRASH || (int) ($originalData['docState'] ?? 0) === self::STATE_TRASH) {
            return;
        }
        $docId = (int) ($data['id'] ?? $originalData['id'] ?? 0);
        if ($docId > 0 && $this->hasSales($docId)) {
            throw new \DomainException(Texts::of($this->config)->t(
                'doc.trashWithSale',
                'The document has a sale registered with the tax authority and cannot be deleted. Cancel it instead — the cancellation registers a negative sale.',
            ));
        }
    }

    public function onBeforeDelete(string $tableId, array $data): void
    {
        if ($tableId !== self::TABLE) {
            return;
        }
        $docId = (int) ($data['id'] ?? 0);
        if ($docId > 0 && $this->hasSales($docId)) {
            throw new \DomainException(Texts::of($this->config)->t(
                'doc.deleteWithSale',
                'The document has a sale registered with the tax authority and cannot be deleted.',
            ));
        }
    }

    /**
     * Čerstvě založenou tržbu hned zkusí odeslat (D7) — stejnou cestou jako
     * cron a jako akce Kontaktní platba ({@see ImmediateSend}). Dvě výjimky,
     * kdy ji nechá cronu (do minuty):
     *
     *  - **otevřená transakce**: handler z applieru běží před jeho commitem
     *    a doklad se ještě může vrátit (kontrolní součet) — zpráva by šla
     *    správci daně za doklad, který nevznikl;
     *  - **jistič** (U6): poslední pokus registrace v posledních 60 s skončil
     *    výpadkem — každé uložení prodejky by jinak čekalo na timeout.
     */
    protected function afterCreated(SyncResult $result): void
    {
        if ($result->saleId === null || $this->inTransaction()) {
            return;
        }
        $queue = $this->queue();
        if ($queue === null) {
            return;
        }
        (new ImmediateSend($queue))->attempt($result);
    }

    /** Běží handler uvnitř cizí transakce (applier)? Přepsatelné v testech. */
    protected function inTransaction(): bool
    {
        return $this->db !== null && NestedTransaction::isActive($this->db);
    }

    /** Pojistku serveru handler nedostává — čte si ji sám, fail-closed (#178 bod 2). */
    protected function queue(): ?SaleQueue
    {
        if ($this->db === null || $this->dsConfig === null) {
            return null;
        }
        return SaleQueue::create($this->db, $this->config, $this->dsConfig, SalesRegistrationServerConfig::forServer());
    }

    protected function sync(): SaleSync
    {
        return SaleSync::create($this->db, $this->config, $this->dsConfig);
    }

    protected function hasSales(int $docId): bool
    {
        if ($this->db === null) {
            return false;
        }
        return $this->db->fetchSingle('SELECT 1 FROM %n WHERE [doc_head] = %i LIMIT 1', SaleSync::TABLE, $docId) !== null;
    }
}
