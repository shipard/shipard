<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Formulář číselné řady zakázek (D17): druh (po založení jen ke čtení),
 * název, kód řady, vzorec, restart a platnost. Nová řada začíná s výchozím
 * vzorcem `%C%y%4` a ročním restartem (P3); výběr druhu předvyplní prázdný
 * název názvem druhu.
 */
class WorkOrderSeriesForm extends TableForm
{
    public const RESET_SCOPES_CFG_ITEM = 'docs.core.resetScopes';

    public function applyNewRecordDefaults(array &$data): void
    {
        if (empty($data['number_pattern'])) {
            $data['number_pattern'] = WorkOrderSeriesDocument::DEFAULT_PATTERN;
        }
        if (empty($data['reset_scope'])) {
            $data['reset_scope'] = WorkOrderSeriesDocument::DEFAULT_RESET_SCOPE;
        }
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew) {
            $this->applyNewRecordDefaults($data);
        }

        $tab = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->select(
                        'kind',
                        options: $this->kindOptions($data['kind'] ?? null),
                        required: true,
                        readOnly: !$isNew,
                        triggers: 'reload',
                        hint: $isNew ? 'Řada patří jednomu druhu; po založení řady se druh nemění.' : null,
                    )
                    ->input('name', required: true)
                    ->input('number_code', hint: 'Dosadí se za %C ve vzorci.')
                    ->input(
                        'number_pattern',
                        required: true,
                        hint: '%C kód řady, %y / %Y rok (2 / 4 místa), %3 až %6 pořadí doplněné nulami. Pořadí je povinné.',
                    )
                    ->select('reset_scope', options: $this->resetScopeOptions(), required: true)
                    ->separator('Platnost')
                    ->date('valid_from')
                    ->date('valid_to')
                    ->separator('Poznámka')
                    ->textarea('notice')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Číselná řada zakázek',
            titleNew: 'Nová číselná řada zakázek',
            tabs: [$tab],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'kind' && !empty($data['kind']) && empty($data['name']) && $this->db !== null) {
            $row = $this->db->fetchRow(
                'SELECT `name` FROM `' . KindDocument::TABLE . '` WHERE `id` = %i',
                (int) $data['kind'],
            );
            if ($row !== null && !empty($row['name'])) {
                $data['name'] = (string) $row['name'];
            }
        }
        return new RecalculateResult($this->buildFormDefinition($data, empty($data['id'])), $data);
    }

    /**
     * Platné druhy (ne archiv, ne koš) jako `název (typ)`; uložený druh,
     * který už platný není, v nabídce zůstává — jinak by ho select tiše
     * vyprázdnil a dokument odmítl změnu druhu.
     *
     * @return list<array{value: int, label: string}>
     */
    private function kindOptions(mixed $current): array
    {
        if ($this->db === null) {
            return [];
        }
        $types = new WorkOrderTypes($this->config);
        $currentId = (int) ($current ?? 0);
        $options = [];
        $found = $currentId <= 0;
        foreach ($this->db->fetchAll(
            'SELECT `id`, `name`, `type`, `docState` FROM `' . KindDocument::TABLE . '`'
            . ' WHERE `docState` IN (10, 40, 80) OR `id` = %i'
            . ' ORDER BY `name` ASC',
            $currentId,
        ) as $row) {
            $id = (int) $row['id'];
            $label = (string) $row['name'] . ' (' . $types->label((string) $row['type']) . ')';
            if (!in_array((int) ($row['docState'] ?? 10), [10, 40, 80], true)) {
                $label .= ' — neplatný druh';
            }
            $options[] = ['value' => $id, 'label' => $label];
            $found = $found || $id === $currentId;
        }
        if (!$found) {
            $options[] = ['value' => $currentId, 'label' => '#' . $currentId];
        }
        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private function resetScopeOptions(): array
    {
        $cfg = $this->config?->cfgItem(self::RESET_SCOPES_CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', self::RESET_SCOPES_CFG_ITEM) : [];
    }
}
