<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Sales;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Evidence tržeb × doklady (#171 D6, D7, U12) — handler na `docs_core_heads`.
 *
 *  - `stateChanged`: přechod do V pořádku (40) nebo Storno (30) spustí
 *    rozdílový výpočet ({@see SaleSync}). Počítá i se starým stavem 0
 *    (doklad založený rovnou V pořádku — „Vystavit a uzavřít“, pokyn přes
 *    API); importovaný doklad vyřadí vyhodnocení podle trvalého příznaku.
 *    Výjimky loguje — uložení dokladu kvůli evidenci selhat nesmí
 *    a chybějící tržbu dorovná záchranná síť.
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
            if ($result->isCreated()) {
                $this->afterCreated($result);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'sales registration: sale of document ' . $docId . ' not created');
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

    /** Co se má stát s čerstvě založenou tržbou — odeslání přidává fronta (přepsatelné v testech). */
    protected function afterCreated(SyncResult $result): void
    {
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
