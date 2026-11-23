<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Base\Persons\Values\PersonValues;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;

/**
 * Založení záznamů, které doklad z papíru potřebuje a adresář je nemá
 * (#166 D51, D53): osoby, bankovní účet dodavatele a položky podle plánu
 * z {@see RecordMatching::reconcile()}. **Zapisuje** — proto stojí mimo
 * samotnou interpretaci; volá se uvnitř transakce uložení dokladu, aby
 * neúspěšné uložení vrátilo i založené záznamy. Pokyn nic nezakládá (D31).
 */
final class PendingRecords
{
    private const ACTIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly Connection $db,
        private readonly TransactionlessTableGateway $personsGateway,
        private readonly TransactionlessTableGateway $itemsGateway,
    ) {
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $resolved
     * @return array{supplier: ?int, customer: ?int, balanceParty: ?int, supplierBank: ?int, rowItems: array<int, int>}
     */
    public function create(array $plan): array
    {
        $ids = ['supplier' => null, 'customer' => null, 'balanceParty' => null, 'supplierBank' => null, 'rowItems' => []];

        foreach (['supplier', 'customer', 'balanceParty'] as $partyKey) {
            $payload = $plan['partyCreates'][$partyKey] ?? null;
            if (!is_array($payload) || $payload === []) {
                continue;
            }
            // Adresy (`addresses`, #103 F3 nález C) nejsou child tabulka osoby —
            // zůstávají v payloadu a zapíše je PersonDocument::afterPersist
            // uvnitř téže transakce (jeden zápis adres pro formulář i applier,
            // #103 D99); stav řádku adresy = stav osoby.
            $payload['docState'] = 40;
            $payload['docStateMain'] = 3;
            if (!isset($payload['person_type'])) {
                $payload['person_type'] = PersonType::Company->value;
            }
            $result = $this->personsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create person ({$partyKey}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids[$partyKey] = (int) $result->getData()['id'];
        }

        if (is_array($plan['bankCreate'] ?? null)) {
            $bankPayload = $plan['bankCreate'];
            // Re-link bank to the supplier: freshly created one, or the one
            // the client decided (`useExisting:<id>` → resolvedSupplier,
            // #103 D98) — otherwise `create` on the account would be silently
            // skipped. `person` in the payload (matched supplier) wins.
            if (empty($bankPayload['person'])) {
                $bankPayload['person'] = $ids['supplier'] ?? $plan['resolvedSupplier'] ?? null;
            }
            if (!empty($bankPayload['person'])) {
                // Přímý insert (stav řádku z výchozí hodnoty DB); tvar čísla účtu,
                // IBAN a BIC sjednocuje táž funkce jako formulář a applier osob
                // (#118 D18, D20).
                $bankPayload = PersonValues::normalizeBankAccount($bankPayload);
                $this->db->insert('base_persons_bank_accounts', $bankPayload)->execute();
                $ids['supplierBank'] = (int) $this->db->getInsertId();
            }
        }

        foreach ($plan['rowItemCreates'] ?? [] as $rowIdx => $payload) {
            $payload = $this->prepareItemCreatePayload($payload, $plan['resolvedRowUnits'][$rowIdx] ?? null);
            $result = $this->itemsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Side-create item (row {$rowIdx}) failed: " . ($result->getErrorMessage() ?? ''));
            }
            $ids['rowItems'][$rowIdx] = (int) $result->getData()['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareItemCreatePayload(array $payload, ?int $unitId): array
    {
        $payload['docState'] = 40;
        $payload['docStateMain'] = 3;
        if ($unitId !== null) {
            $payload['unit'] = $unitId;
        }
        // item_kind is required by ItemDocument::validate. Pick the first
        // active kind as a generic default — better than failing the save.
        if (empty($payload['item_kind'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [economy_items_kinds]
                 WHERE [docState] IN (%i, %i, %i) ORDER BY [id] LIMIT 1',
                self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            );
            if ($row !== null) {
                $payload['item_kind'] = (int) $row['id'];
            }
        }
        // Default unit fallback if not resolved on the row.
        if (empty($payload['unit'])) {
            $row = $this->db->fetch(
                'SELECT [id] FROM [core_units] WHERE [system_code] = %s LIMIT 1',
                'pcs',
            );
            if ($row !== null) {
                $payload['unit'] = (int) $row['id'];
            }
        }
        return $payload;
    }
}
