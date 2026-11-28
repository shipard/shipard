<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\Module\ModuleProvisionContext;
use Shipard\Core\Module\ModuleProvisioner;

/**
 * Idempotentní seed druhů smluv (#174 D19, D28): jeden druh na typ se
 * seedovým kódem (`seedCode` typu — KA, BY, OP, SK, PA, VK, OS), se stejným
 * názvem jako typ, vzorcem `%C-%Y-%4` a ročním restartem čítače.
 *
 * Druh se pozná podle `system_code` (= klíč typu). Existuje-li řádek se
 * stejným kódem v jakémkoli stavu — i v archivu nebo v koši — nic se
 * nedělá: uživatel si druh mohl upravit nebo vyřadit a seed mu ho nevrací
 * ani nepřepisuje. Jinak INSERT rovnou ve stavu V pořádku.
 *
 * Deklarovaný v `provisioners` modulu, takže ho `ds-upgrade` volá vždy,
 * i pod skipProvisioning a po `ds-reset`. Typy bere z kompilátu v jazyce
 * zdroje dat; bez nich končí chybou — druhy se nezakládají odhadem
 * a zdroj dat bez druhů by nešel používat.
 */
class ContractKindsProvisioner implements ModuleProvisioner
{
    public const LABEL = 'contract kinds';
    public const DEFAULT_PATTERN = '%C-%Y-%4';

    public function provision(ModuleProvisionContext $context): array
    {
        $types = new ContractTypes($context->config);
        if ($types->all() === []) {
            throw new \RuntimeException(
                'contract types unavailable (cfgItem ' . ContractTypes::CFG_ITEM . ') — contract kinds not seeded',
            );
        }

        $created = 0;
        $existing = 0;
        foreach (array_keys($types->all()) as $typeId) {
            $code = $types->seedCode($typeId);
            if ($code === null) {
                continue;
            }

            $row = $context->db->fetchRow(
                'SELECT id FROM ' . ContractKindDocument::TABLE . ' WHERE system_code = %s',
                $typeId,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $context->db->insertRow(ContractKindDocument::TABLE, [
                'name'           => $types->label($typeId),
                'type'           => $typeId,
                'notice'         => null,
                'number_code'    => $code,
                'number_pattern' => self::DEFAULT_PATTERN,
                'reset_scope'    => ContractKindDocument::RESET_YEARLY,
                'system_code'    => $typeId,
                'docState'       => ContractKindDocument::STATE_CONFIRMED,
                'docStateMain'   => 3,
            ]);
            $created++;
        }

        return [self::LABEL => ['created' => $created, 'existing' => $existing]];
    }
}
