<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\PayloadContext;

/**
 * Hodnoty, které papírová podoba dokladu nenese a návrh je doplňuje sám
 * (#166 D51, D52): pohyb řádku podle typu položky a způsob úhrady. Pokyn
 * má výchozí hodnoty společné s formulářem (`InstructionDefaults`) a tyhle
 * větve nevolá.
 */
final class ProposalDefaults
{
    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly PayloadContext $payload,
    ) {
    }

    /**
     * Způsob úhrady dokladu, který ho neuvádí — totéž pravidlo jako
     * formulář: doklad pokladny Hotově, ostatní Převodem.
     */
    public function paymentMethod(string $docType): int
    {
        return $this->payload->headDefaults()->paymentMethod($docType);
    }

    /**
     * Způsob úhrady, se kterým doklad z papíru vznikne: uvedený
     * `payment.method`, jinak {@see paymentMethod()}.
     *
     * @param array<string, mixed> $canonical
     */
    public function paymentMethodFor(array $canonical, string $docType): int
    {
        return DocumentApplier::PAYMENT_METHOD_MAP[(string) ($canonical['payment']['method'] ?? '')]
            ?? $this->paymentMethod($docType);
    }

    /**
     * Item řádek, kterému se při apply doplňuje pohyb: rowKind item,
     * bez explicitní operation (AI ji správně nevrací — interní účetní
     * koncept, na předloze není) a bez kontace accSide. Textové/sekční
     * a kontační řádky se nedoplňují; explicitní operation = passthrough
     * (operace s vlajkou rowSide sem nespadnou — nesou operation).
     */
    private function rowNeedsOperation(mixed $row): bool
    {
        return is_array($row)
            && (DocumentApplier::ROW_KIND_MAP[(string) ($row['rowKind'] ?? 'item')] ?? 1) === 1
            && !isset($row['accSide'])
            && (string) ($row['operation'] ?? '') === '';
    }

    /**
     * Doplní pohyb item řádkům bez operation podle cfgItem
     * `docs.core.applyRowOperations` — primárně mapou `byItemType`
     * z item_type položky řádku, jinak výchozím pohybem `default`
     * docTypu. Bez doplnění koncept z AI analýzy neprojde na docState
     * 40 („Pohyb je povinný", DocRowOperationRules::validateRow).
     * Volá se po runSideCreates() — finální ID položek (matched
     * i právě side-created) už jsou v DB, item_type se čte jednotně
     * přes ID. docType bez záznamu v cfg → beze změny (null).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param array<int, ?int> $pendingItemTypes index řádku → typ položky, která se
     *        teprve založí ({@see PendingRecords::plannedItemType()}) — interpretace
     *        určuje pohyb před založením; při uložení jsou id v `$sideIds`
     * @return array<int, string> index řádku → doplněný kód operace
     */
    public function rowOperations(array $canonical, array $plan, array $sideIds, array &$issues, array $pendingItemTypes = []): array
    {
        $docType = $this->payload->mapDocType($canonical);
        $cfgApply = $this->config->cfgItem('docs.core.applyRowOperations');
        if (!is_array($cfgApply[$docType] ?? null)) {
            return [];
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $rowItemIds = [];
        foreach ($rows as $i => $row) {
            if (!$this->rowNeedsOperation($row) || in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $rowItemIds[$i] = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
        }
        if ($rowItemIds === []) {
            return [];
        }

        $types = $this->fetchItemTypes(array_filter($rowItemIds, static fn($id) => $id !== null));
        $rowItemTypes = [];
        foreach ($rowItemIds as $i => $id) {
            $rowItemTypes[$i] = $id !== null ? ($types[$id] ?? null) : ($pendingItemTypes[$i] ?? null);
        }
        return $this->resolveRowOperationDefaults($docType, $rowItemTypes, $issues);
    }

    /**
     * Přeloží item_type řádků na kódy pohybů dle
     * `docs.core.applyRowOperations` pro daný docType. Doplnění je
     * TICHÉ — žádné info issue: AI pohyb nikdy nevrací, doplňuje se
     * tedy na každém item řádku každého apply a hláška, která svítí
     * vždy, by učila uživatele sekci Upozornění přeskakovat.
     * Transparentnost dává sám výsledek (sloupec Pohyb konceptu) —
     * na rozdíl od `vat_mode_derived`, kde výsledek odchylku od
     * výstupu AI nevysvětlí. Kód, který v `docs.core.rowOperations`
     * neexistuje nebo není pro docType povolený, se NEdoplní (chová se
     * jako docType bez záznamu) a přidá warning
     * `row_operation_config_invalid` — ten hlásí skutečný problém
     * a vystřelí jen při rozbité mapě.
     *
     * @param array<int, ?int> $rowItemTypes index řádku → item_type (null = bez položky / neznámý)
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<int, string> index řádku → kód operace
     */
    private function resolveRowOperationDefaults(string $docType, array $rowItemTypes, array &$issues): array
    {
        $cfgApply = $this->config->cfgItem('docs.core.applyRowOperations');
        $cfg = is_array($cfgApply[$docType] ?? null) ? $cfgApply[$docType] : null;
        if ($cfg === null) {
            return [];
        }
        $cfgOps = $this->config->cfgItem('docs.core.rowOperations');
        $cfgOps = is_array($cfgOps) ? $cfgOps : [];
        $byItemType = is_array($cfg['byItemType'] ?? null) ? $cfg['byItemType'] : [];

        $out = [];
        foreach ($rowItemTypes as $i => $itemType) {
            $code = ($itemType !== null ? ($byItemType[(string) $itemType] ?? null) : null)
                ?? ($cfg['default'] ?? null);
            if (!is_string($code) || $code === '') {
                continue;
            }
            if (!isset($cfgOps[$code]['docTypes'][$docType])) {
                $issues[] = [
                    'severity' => 'warning',
                    'path'     => "rows.{$i}.operation",
                    'code'     => 'row_operation_config_invalid',
                    'message'  => "Pohyb „{$code}“ z docs.core.applyRowOperations neexistuje nebo není povolen pro doklad „{$docType}“; pohyb nedoplněn.",
                ];
                continue;
            }
            $out[$i] = $code;
        }
        return $out;
    }

    /**
     * @param array<int, int> $itemIds
     * @return array<int, int> ID položky → item_type
     */
    private function fetchItemTypes(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [item_type] FROM [economy_items] WHERE [id] IN %in',
            $itemIds,
        ) as $row) {
            $out[(int) $row['id']] = (int) $row['item_type'];
        }
        return $out;
    }
}
